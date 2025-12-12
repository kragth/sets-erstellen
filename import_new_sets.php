<?php 
/*
* Projekt: Set-Artikel Generator
* Datei: import_new_sets.php
* Stand: 2025-11-11
*
* ---------------------------------------------------------------------------
* ZUSAMMENFASSUNG
* ---------------------------------------------------------------------------
* Dieses Script importiert neue Set-Zuordnungen aus einer CSV-Datei, aktualisiert
* entsprechende Jobs in der Datenbank und erzeugt anschließend zwei Exporte:
*   1) Komponenten-Export (Set-Jobs inkl. Komponenten-Varianten in Reihenfolge)
*   2) Weitere-Daten-Export (zusammengeführte Bilder, Beschreibung, Setpreis
*      sowie PropertyIds/PropertyValues nach Komponentenvergleich)
* Danach startet es per Synesty-API einen Flow
*
* Kernlogik:
* - CSV "import/neue_Sets.csv" einlesen (erwartete Spalten: ItemID, MainVariantID, FreeText17)
* - Nur Jobs mit Status "Warte auf Set-Komponenten" werden auf "Komponenten hinzugefügt" gesetzt
*   und erhalten new_item_id / new_variant_id aus der CSV.
* - Exporte erstellen:
*     • Komponenten-CSV: je Komponente eine Zeile
*     • Weitere-Daten-CSV: je Set genau eine Zeile
*         - ImageUrls aus Items gesammelt (per "," zusammengeführt, Synesty erwartet Komma)
*         - Beschreibung aus Items_Beschreibung (HTML-entities decodiert, segmentweise verknüpft)
*         - Brutto-Setpreis:
*             -> Wenn mindestens eine Komponente keinen BruttoMindestpreisKH24 hat → Setpreis = 0
*             -> sonst: Summe( (Komponenten-Brutto / 1.19) - Versand(komponente) ) + Versand(set)  → * 1.19
*         - Properties:
*             -> Pro Komponente ItemPropertyIDs und VariationPropertyIDs zusammenführen,
*                innerhalb der Komponente nach ID deduplizieren.
*             -> setweit je ID die Anzahl der Komponenten zählen, in denen sie vorkommt:
*                   · kommt ID nur in 1 Komponente vor und ist nicht konfliktbehaftet → Wert aus dieser Komponente übernehmen
*                   · kommt ID in ≥2 Komponenten vor ODER ist innerhalb einer Komponente mehrfach vergeben → Wert leer lassen (ERP entscheidet später)
*             -> Ausgabe in zwei Spalten: PropertyIds und PropertyValues (jeweils ";"-getrennt)
* - Importdatei nach erfolgreicher Verarbeitung mit Timestamp ins Archiv verschieben.
* - Synesty-Flow "Sets-erstellen-Komponenten" starten
*
* Ein-/Ausgänge & Nebenwirkungen:
* - INPUT: CSV im Ordner /import (Semikolon-getrennt, Quote ", Escape \)
* - OUTPUT: zwei CSVs im Ordner /export (mit Timestamp im Dateinamen)
* - DB: Tabellenzugriffe auf set_job, set_job_items, Items, Items_Beschreibung, Items_Preise
*
* Nichtfunktionale Aspekte:
* - Fehlertoleranz: CSV-Validierung, harte Abbrüche bei kritischen Fehlern (fehlende Datei/Spalten),
* - Sicherheit: IDs für IN-Klausel werden auf int gecastet (array_map('intval')).
* - Nachvollziehbarkeit: HTML-Ausgaben als Prozessreport; Warnhinweis ins error_log, wenn Setpreis = 0.
* - Konfiguration: Steuersatz (19 %) sowie Versandstaffeln sind im Code fest hinterlegt.
*/

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');
require_once 'db.php';

/**
 * ---------------------------------------------------------------------------
 * KONFIGURATION
 * ---------------------------------------------------------------------------
 */

// Properties (Eigenschafts-IDs), die komplett ignoriert werden sollen.
// Diese IDs werden weder gezählt noch in PropertyIds/PropertyValues exportiert.
$ignorePropIds = array_map('strval', [
    162, 166, 167, 168, 169, 170, 171,
    175, 176, 177, 178, 179, 180,
    182, 183, 184, 187, 188, 189,
    192, 194, 195, 196, 198, 200,
    267, 268
]);

/**
 * Versandkostenberechnung anhand Gesamtgewicht (in kg).
 * Staffelpreise:
 *   < 5      →  3.6
 *   <= 15    →  4.2
 *   <= 20    →  6.2
 *   <= 29    → 22.0
 *   <= 99    → 63.0
 *   >  99    → 63.0
 */
function berechneVersandkosten(float $gewicht_kg): float {
    if ($gewicht_kg < 5) return 3.6;
    elseif ($gewicht_kg <= 15) return 4.2;
    elseif ($gewicht_kg <= 20) return 6.2;
    elseif ($gewicht_kg <= 29) return 22.0;
    elseif ($gewicht_kg <= 99) return 63.0;
    else return 63.0;
}

// Pfade für Import/Archiv/Export
$importFile = __DIR__ . '/import/neue_Sets.csv';
$archiveDir = __DIR__ . '/import/archive/';
$exportDir  = __DIR__ . '/export/';

// Verfügbarkeit der Importdatei sicherstellen
if (!file_exists($importFile)) {
    die("Fehler: Importdatei '$importFile' wurde nicht gefunden.\n");
}

// CSV öffnen (Lesemodus)
$handle = fopen($importFile, 'r');
if (!$handle) {
    die("Fehler: Datei konnte nicht geöffnet werden.\n");
}

$updatedJobs = [];   // IDs der erfolgreich aktualisierten set_job-Datensätze
$updated = 0;       // Zähler für Updates
$skipped = 0;       // Zähler für übersprungene Zeilen (Validierung/Status)

// Kopfzeile lesen und validieren (Semikolon, Quote ", Escape \)
$header = fgetcsv($handle, 2000, ';', '"', "\\");
if (!$header) {
    die("Fehler: Kopfzeile konnte nicht gelesen werden oder ist leer.\n");
}

// Erwartete Spalten prüfen (harte Bedingung)
$expectedCols = ['ItemID', 'MainVariantID', 'FreeText17'];
foreach ($expectedCols as $col) {
    if (!in_array($col, $header)) {
        die("Fehler: Erwartete Spalte '$col' fehlt in der CSV.\n");
    }
}
$colIndex = array_flip($header);

// Datenzeilen verarbeiten
while (($row = fgetcsv($handle, 2000, ';', '"', "\\")) !== false) {
    $ItemID        = trim($row[$colIndex['ItemID']] ?? '');
    $MainVariantID = trim($row[$colIndex['MainVariantID']] ?? '');
    $FreeText17    = trim($row[$colIndex['FreeText17']] ?? ''); // entspricht set_job.id

    // Mindestvalidierung: alle drei Pflichtfelder müssen gefüllt sein
    if ($FreeText17 === '' || $ItemID === '' || $MainVariantID === '') {
        $skipped++;
        continue;
    }

    // Nur Jobs im Status "Warte auf Set-Komponenten" werden fortgeführt
    $stmtCheck = $pdo->prepare("SELECT status FROM set_job WHERE id = ?");
    $stmtCheck->execute([$FreeText17]);
    $status = $stmtCheck->fetchColumn();

    if ($status !== 'Warte auf Set-Komponenten') {
        $skipped++;
        continue;
    }

    // Job aktualisieren: neue Item/Variant IDs + Statuswechsel
    $stmt = $pdo->prepare("
        UPDATE set_job
        SET new_item_id = ?, new_variant_id = ?, status = 'Komponenten hinzugefügt'
        WHERE id = ?
    ");
    $stmt->execute([$ItemID, $MainVariantID, $FreeText17]);

    if ($stmt->rowCount() > 0) {
        $updated++;
        $updatedJobs[] = $FreeText17;
    } else {
        $skipped++;
    }
}
fclose($handle);

// Ergebnis-Report (HTML)
echo "<h2>Import abgeschlossen</h2>";
echo "<p>Aktualisierte Jobs: <strong>$updated</strong></p>";
echo "<p>Übersprungene Zeilen: <strong>$skipped</strong></p>";

// Importdatei ins Archiv verschieben (Ordner bei Bedarf anlegen)
if (!is_dir($archiveDir)) mkdir($archiveDir, 0775, true);
$newName = 'neue_Sets_' . date('Y-m-d_His') . '.csv';
rename($importFile, $archiveDir . $newName);
echo "<p>Datei wurde archiviert als <strong>$newName</strong>.</p>";

// Komponenten-Export und Weitere-Daten-Export erzeugen (nur wenn mindestens ein Job aktualisiert wurde)
if (count($updatedJobs) > 0) {
    if (!is_dir($exportDir)) mkdir($exportDir, 0775, true);

    $timestamp = date('Y-m-d_His');
    $csvFileKomponenten = $exportDir . 'komponenten_' . $timestamp . '.csv';
    $csvFileWeitere     = $exportDir . 'set_weitere_daten_' . $timestamp . '.csv';

    // --- CSV 1: Komponenten-Export (je Komponente eine Zeile) ---
    $csvHeaderKomponenten = [
        'SetJobID', 'SetType', 'SetItemID', 'SetVariantID',
        'ComponentVariantID', 'SortIndex', 'RequestedBy', 'RequestedAt'
    ];
    $csvHandleKomponenten = fopen($csvFileKomponenten, 'w');
    fputcsv($csvHandleKomponenten, $csvHeaderKomponenten, ';', '"', "\\");

    // --- CSV 2: Weitere-Daten-Export (je Set genau eine Zeile) ---
    //   Neu: PropertyIds, PropertyValues, zusätzliche Set-Preise
    $csvHeaderWeitere = [
        'SetItemID', 'SetVariantID', 'SetType',
        'ImageUrls', 'BeschreibungHTML', 'BruttoMindestpreisSet',
        'RequestedByID', 'PropertyIds', 'PropertyValues',
        'SetUVP', 'SetShopPreisKH24', 'SetEbayPreisKH24', 'SetAmazonPreisKH24',
        'SetPreisManuelleEingabe', 'SetRealPreisKH24', 'SetRealTiefstpreisKH24', 'SetB2B'
    ];
    $csvHandleWeitere = fopen($csvFileWeitere, 'w');
    fputcsv($csvHandleWeitere, $csvHeaderWeitere, ';', '"', "\\");

    // Betroffene Jobs laden (IDs integer-saniert)
    $jobStmt = $pdo->prepare("
        SELECT id, set_type, new_item_id, new_variant_id, requested_by, requested_at
        FROM set_job
        WHERE id IN (" . implode(',', array_map('intval', $updatedJobs)) . ")
    ");
    $jobStmt->execute();
    $jobs = $jobStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($jobs as $job) {
        // Komponentenvarianten zum Job (in definierter Reihenfolge)
        $itemStmt = $pdo->prepare("
            SELECT variant_id, sort_index
            FROM set_job_items
            WHERE set_job_id = ?
            ORDER BY sort_index ASC
        ");
        $itemStmt->execute([$job['id']]);
        $components = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        // === CSV 1: Komponenten schreiben ===
        foreach ($components as $comp) {
            fputcsv($csvHandleKomponenten, [
                $job['id'], $job['set_type'], $job['new_item_id'], $job['new_variant_id'],
                $comp['variant_id'], $comp['sort_index'], $job['requested_by'],
                date('d.m.Y H:i', strtotime($job['requested_at']))
            ], ';', '"', "\\");
        }

        // === CSV 2: Weitere Daten aggregieren & schreiben ===
        $variantIDs = array_column($components, 'variant_id');
        if (empty($variantIDs)) continue; // Ohne Komponenten keine weitere-Daten-Zeile

        $in = str_repeat('?,', count($variantIDs) - 1) . '?';

        // Stammdaten je Variante (inkl. Property-Spalten)
        $itemDataStmt = $pdo->prepare("
            SELECT VariantID, ImageUrls, ItemProducerName, Model, WeightG,
                   ItemPropertyIDs, VariationPropertyIDs
            FROM Items WHERE VariantID IN ($in)
        ");
        $itemDataStmt->execute($variantIDs);
        $itemData = $itemDataStmt->fetchAll(PDO::FETCH_ASSOC);
        $itemMap = [];
        foreach ($itemData as $r) $itemMap[$r['VariantID']] = $r;

        // Beschreibungen je Variante (HTML-Inhalte)
        $descStmt = $pdo->prepare("SELECT VariantID, Beschreibung FROM Items_Beschreibung WHERE VariantID IN ($in)");
        $descStmt->execute($variantIDs);
        $descRows = $descStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Preise je Variante (alle benötigten Preisspalten)
        $preisStmt = $pdo->prepare("
            SELECT VariantID, BruttoMindestpreisKH24, UVP, ShopPreisKH24, EbayPreisKH24,
                   AmazonPreisKH24, PreisManuelleEingabe, RealPreisKH24, RealTiefstpreisKH24, B2B
            FROM Items_Preise WHERE VariantID IN ($in)
        ");
        $preisStmt->execute($variantIDs);
        $preisRowsFull = $preisStmt->fetchAll(PDO::FETCH_ASSOC);
        // Mapping VariantID => Preisdaten
        $preisMap = [];
        foreach ($preisRowsFull as $pr) {
            $preisMap[$pr['VariantID']] = $pr;
        }

        // Aggregationscontainer
        $ImageUrls = [];
        $BeschreibungHTML = '';
        $gesamtNettoOhneVersand = 0.0;
        $gesamtGewicht = 0.0;
        $fehlendePreise = [];

        // Zusätzliche Set-Preise (Summe der Komponenten, nur wenn > 0)
        $setPreise = [
            'UVP' => 0.0,
            'ShopPreisKH24' => 0.0,
            'EbayPreisKH24' => 0.0,
            'AmazonPreisKH24' => 0.0,
            'PreisManuelleEingabe' => 0.0,
            'RealPreisKH24' => 0.0,
            'RealTiefstpreisKH24' => 0.0,
            'B2B' => 0.0
        ];

        // Properties: setweite Erfassung
        $propOccur     = []; // id => Anzahl Komponenten, in denen die ID vorkommt
        $propValues    = []; // id => Liste mit je einem (ersten) Wert pro Komponente
        $propConflict  = []; // id => true, wenn innerhalb einer Komponente mehrfach vergeben (Item/Variation-Konflikt o.Ä.)

        foreach ($components as $comp) {
            $vid = $comp['variant_id'];

            // Gewicht in kg addieren (WeightG kann fehlen → Fallback 0)
            $weight = floatval($itemMap[$vid]['WeightG'] ?? 0) / 1000;
            $gesamtGewicht += $weight;

            // Preislogik: wenn Komponente keinen BruttoMindestpreis hat → später Setpreis = 0
            $kompPreise = $preisMap[$vid] ?? [];
            $bruttoPreis = floatval($kompPreise['BruttoMindestpreisKH24'] ?? 0);
            if ($bruttoPreis <= 0) {
                $fehlendePreise[] = $vid;
            } else {
                // Netto = Brutto / 1.19, dann Versandkostenanteil auf Komponentennebene abziehen
                $netto = $bruttoPreis / 1.19; // 19 %
                $versand = berechneVersandkosten($weight);
                $nettoOhneVersand = max(0, $netto - $versand);
                $gesamtNettoOhneVersand += $nettoOhneVersand;
            }

            // Zusätzliche Preise summieren (nur wenn > 0)
            foreach ($setPreise as $preisKey => $dummy) {
                $p = floatval($kompPreise[$preisKey] ?? 0);
                if ($p > 0) {
                    $setPreise[$preisKey] += $p;
                }
            }

            // Bild-URLs sammeln (falls vorhanden)
            // WICHTIG: ImageUrls in DB sind KOMMA-getrennt, einzeln ins Array aufnehmen
            if (!empty($itemMap[$vid]['ImageUrls'])) {
                $urlParts = explode(',', $itemMap[$vid]['ImageUrls']);
                foreach ($urlParts as $url) {
                    $url = trim($url);
                    if ($url !== '') {
                        $ImageUrls[] = $url;
                    }
                }
            }

            // Beschreibung anhängen (HTML-Entity-Decoding, Segmenttrennung via <br><br>)
            $beschreibung = html_entity_decode($descRows[$vid] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($beschreibung) {
                $BeschreibungHTML .= $beschreibung . "\n<br><br>\n";
            }

            // --- Properties je Komponente (ItemPropertyIDs + VariationPropertyIDs) ---
            // Format (gemäß Items): "6=A;7=Einbaugerät;42=Weiß;..."
            $propRawItem = (string)($itemMap[$vid]['ItemPropertyIDs'] ?? '');
            $propRawVar  = (string)($itemMap[$vid]['VariationPropertyIDs'] ?? '');
            $propMerged  = trim($propRawItem) !== '' && trim($propRawVar) !== ''
                         ? $propRawItem . ';' . $propRawVar
                         : ($propRawItem ?: $propRawVar);

            if ($propMerged !== '') {
                // 1) innerhalb der Komponente nach ID deduplizieren
                $pairs = array_filter(array_map('trim', explode(';', $propMerged)));
                $compProps = []; // id => value (pro Komponente eindeutig)
                foreach ($pairs as $pair) {
                    $eqPos = strpos($pair, '=');
                    if ($eqPos === false) continue;
                    $id  = trim(substr($pair, 0, $eqPos));
                    $val = substr($pair, $eqPos + 1); // Wert kann '=' enthalten
                    if ($id === '') continue;

                    // IDs aus Ignore-Liste vollständig überspringen
                    if (in_array((string)$id, $ignorePropIds, true)) {
                        continue;
                    }

                    if (!array_key_exists($id, $compProps)) {
                        // Erstes Auftreten dieser ID in dieser Komponente
                        $compProps[$id] = $val;
                    } else {
                        // ID kommt innerhalb dieser Komponente mehrfach vor (Item + Variation o.ä.)
                        // → als konfliktbehaftet markieren, Wert soll später im Set leer bleiben
                        $propConflict[$id] = true;
                        // ursprünglicher Wert in $compProps bleibt erhalten (für Nachvollziehbarkeit),
                        // hat aber wegen $propConflict später keinen Effekt auf den Exportwert.
                    }
                }
                // 2) setweit registrieren (pro Komponente zählt jede ID max. 1x)
                foreach ($compProps as $id => $val) {
                    $propOccur[$id] = ($propOccur[$id] ?? 0) + 1;
                    if (!array_key_exists($id, $propValues)) {
                        $propValues[$id] = [];
                    }
                    $propValues[$id][] = $val;
                }
            }
        }

        // Versandkosten fürs Gesamtset (einmalig)
        $versandSet = berechneVersandkosten($gesamtGewicht);

        // Brutto-Setpreis bestimmen:
        // - Bei fehlenden Komponentenpreisen → 0 und Warnhinweis ins error_log
        // - Sonst Netto-Summe (bereits ohne Komponenten-Versand) + Set-Versand, danach 19 % aufschlagen
        if (!empty($fehlendePreise)) {
            error_log("⚠️ Kein Set-Preis berechnet (fehlende BruttoMindestpreisKH24) für Varianten: " . implode(', ', $fehlendePreise));
            $bruttoSet = 0;
        } else {
            $bruttoSet = ($gesamtNettoOhneVersand + $versandSet) * 1.19;
            $bruttoSet = number_format($bruttoSet, 2, '.', '');
        }

        // Zusätzliche Set-Preise: 10% Aufschlag auf Summe, formatieren (0 wenn Summe = 0)
        foreach ($setPreise as $preisKey => $summe) {
            if ($summe > 0) {
                $setPreise[$preisKey] = number_format($summe * 1.10, 2, '.', '');
            } else {
                $setPreise[$preisKey] = '';  // Leer lassen wenn keine Komponente den Preis hatte
            }
        }

        // --- Set-weite Property-Ausgabe ---
        // Regel:
        // - ID immer ausgeben
        // - Wert nur, wenn:
        //      · ID in GENAU 1 Komponente vorkam (propOccur[id] === 1)
        //      · UND nicht konfliktbehaftet ist (innerhalb einer Komponente nur einmal vergeben)
        // - sonst Wert leer (ERP-Entscheidung im Nachgang)
        $allPropIds = array_keys($propOccur);
        usort($allPropIds, static function($a, $b) {
            if (ctype_digit($a) && ctype_digit($b)) return (int)$a <=> (int)$b;
            return strcmp($a, $b);
        });
        $outIds  = [];
        $outVals = [];
        foreach ($allPropIds as $pid) {
            $outIds[] = $pid;
            if ($propOccur[$pid] === 1 && empty($propConflict[$pid])) {
                $outVals[] = (string)($propValues[$pid][0] ?? '');
            } else {
                $outVals[] = '';
            }
        }

        // Eine Zeile pro Set in Weitere-Daten-CSV
        fputcsv($csvHandleWeitere, [
            $job['new_item_id'],
            $job['new_variant_id'],
            $job['set_type'],
            implode(',', $ImageUrls),  // KOMMA-getrennt für Synesty (siehe API-Doku)
            $BeschreibungHTML,
            $bruttoSet,
            $job['requested_by'], // numerische ID
            implode(';', $outIds),
            implode(';', $outVals),
            // Zusätzliche Set-Preise (Summe + 10%)
            $setPreise['UVP'],
            $setPreise['ShopPreisKH24'],
            $setPreise['EbayPreisKH24'],
            $setPreise['AmazonPreisKH24'],
            $setPreise['PreisManuelleEingabe'],
            $setPreise['RealPreisKH24'],
            $setPreise['RealTiefstpreisKH24'],
            $setPreise['B2B']
        ], ';', '"', "\\");
    }

    // Handles schließen & Pfade berichten
    fclose($csvHandleKomponenten);
    fclose($csvHandleWeitere);
    echo "<p><strong>komponenten.csv</strong> erfolgreich erstellt: {$csvFileKomponenten}</p>";
    echo "<p><strong>set_weitere_daten.csv</strong> erfolgreich erstellt: {$csvFileWeitere}</p>";
} else {
    echo "<p>Keine aktualisierten Jobs → keine Komponenten exportiert.</p>";
}

// === Synesty Flow starten ===
echo "Flow starten mit RunID ";
$url = "https://apps.synesty.com/studio/api/flow/v1?id=Sets-erstellen-Komponenten&t=".$token;
$json = file_get_contents($url);
$json = json_decode($json);
echo $json->{'runId'}."<br /><br />";

$url = "https://apps.synesty.com/studio/api/flow/v1?id=Sets-erstellen-Komponenten&t=".$token."&action=status&runId=".$json->{'runId'};
$json = file_get_contents($url);
$json = json_decode($json);

switch ($json->{'status'}) {
    case "SCHEDULED":  $Status = "SCHEDULED - Flow ist Geplant"; break;
    case "QUEUED":     $Status = "QUEUED - Flow ist in Warteschlange"; break;
    case "NEW":        $Status = "NEW - Flow läuft"; break;
    case "SKIPPED":    $Status = "SKIPPED - Flow Übersprungen"; break;
    case "SUCCESS":    $Status = "SUCCESS - Flow Erfolgreich"; break;
    case "ERROR":      $Status = "ERROR - Flow mit FEHLER abgebrochen"; break;
    case "WARNING":    $Status = "WARNING - Flow mit Warnungen durchgeführt"; break;
    case "ERROR_SKIP": $Status = "ERROR_SKIP - Flow nicht durchgeführt. Limits überschritten"; break;
}
echo "Rückmeldung von Synesty: ".$Status;
?>

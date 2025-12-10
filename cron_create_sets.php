<?php 
/*
 * Projekt: Set-Artikel Generator
 * Datei: cron_create_sets.php
 * Zweck: Erstellt CSV-Dateien mit zusammengeführten Set-Artikeldaten
 * Datum: Stand 2025-11-14
 *
 * Funktionen:
 *  - Liest offene Set-Jobs aus der Datenbank
 *  - Führt Einzelartikel zu einem Set zusammen
 *  - Berechnet Preis, Gewicht, Versandprofile
 *  - Erstellt CSV-Exportdatei (UTF-8)
 *  - Setzt Status auf „Warte auf Set-Komponenten“ bei Erfolg
 *  - Setzt Status auf „Fehler“ bei Problemen
 *  - Sendet Mailbenachrichtigung bei Fehlern
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');
require_once 'db.php';

// ------------------------------------------------------------
// Konfiguration
// ------------------------------------------------------------

$testmode = (isset($_GET['mode']) && $_GET['mode'] === 'test'); // Testmodus
$csvFile = __DIR__ . '/export/sets_' . date('Y-m-d_His') . '.csv';

// CSV-Kopfzeile
$csvHeader = [
    'SetJobID',
    'SetItemTextsName1',
    'SetItemTextsName2',
    'SetItemTextsName3',
    'SetItemTextsShortDescription',
    'SetExternalItemID',
    'SetModel',
    'SetVariantName',
    'SetPurchasePrice',
    'SetWeightG',
    'SetWidthMM',
    'SetLengthMM',
    'SetHeightMM',
    'SetItemProducerName',
    'ItemShippingProfiles',
    'SourceVariantIDs',
    'SetType',
    'SetBarcode',
    'Bearbeiter',
    'ErstelltAm'
];

$errorMessages = [];

try {
    $jobs = $pdo->query("SELECT * FROM set_job WHERE status IS NULL OR status = 'Offen'")->fetchAll();
    $csvRows = [];
	
	// ------------------------------------------------------------
	// Prüfung: Gibt es überhaupt offene Jobs?
	// ------------------------------------------------------------
	if (empty($jobs)) {
		echo "<p style='color:#e2732b;font-family:Arial,sans-serif;'>Keine offenen Set-Jobs gefunden – keine Aktion erforderlich.</p>";		
		exit;
	}

    foreach ($jobs as $job) {
        try {
            $jobId = $job['id'];
            $SetType = $job['set_type'];
            $itemStmt = $pdo->prepare("SELECT variant_id FROM set_job_items WHERE set_job_id = ? ORDER BY sort_index ASC");
            $itemStmt->execute([$jobId]);
            $variantIds = $itemStmt->fetchAll(PDO::FETCH_COLUMN);

            // Fehlerfall: Zu wenige Varianten
            if (count($variantIds) < 2) {
                $errorMessages[] = "Job $jobId enthält zu wenige Varianten.";
                if (!$testmode) {
                    $pdo->prepare("UPDATE set_job SET status = 'Fehler' WHERE id = ?")->execute([$jobId]);
                    mail($mailEmpfaenger, "Fehler beim Set-Export", "Job $jobId enthält zu wenige Varianten.");
                }
                continue;
            }

            // Artikel holen (in Reihenfolge von set_job_items.sort_index)
            $in = str_repeat('?,', count($variantIds) - 1) . '?';
            $sql = "SELECT * FROM Items 
                    WHERE VariantID IN ($in)
                    ORDER BY FIELD(VariantID, " . implode(',', array_map('intval', $variantIds)) . ")";
            $articleData = $pdo->prepare($sql);
            $articleData->execute($variantIds);
            $articles = $articleData->fetchAll(PDO::FETCH_ASSOC);

            // Fehlerfall: Artikel fehlen
            if (count($articles) != count($variantIds)) {
                $errorMessages[] = "Job $jobId: Einige Varianten wurden nicht gefunden.";
                if (!$testmode) {
                    $pdo->prepare("UPDATE set_job SET status = 'Fehler' WHERE id = ?")->execute([$jobId]);
                    mail($mailEmpfaenger, "Fehler beim Set-Export", "Job $jobId: Einige Varianten wurden nicht gefunden.");
                }
                continue;
            }

            // Set-Bezeichnung
            if ($SetType == "423;476") {
                $Set = 'Backofen-Set';
            } elseif ($SetType == "423;428") {
                $Set = 'Herdset';
            } elseif ($SetType == "430;432") {
                $Set = 'Spülenset';
            } elseif ($SetType == "mikrowellenset") {
                $Set = 'Mikrowellenset';
            } else {
                $Set = $SetType;
            }

            // Texte zusammenführen
            $SetItemTextsName1 = implode(' + ', array_column($articles, 'ItemTextsName1'));
            $SetItemTextsName2 = implode(' + ', array_column($articles, 'ItemTextsName2'));
            $SetItemTextsName3 = implode(' + ', array_column($articles, 'ItemTextsName3'));
            $SetItemTextsShortDesc = implode("\n---\n", array_column($articles, 'ItemTextsShortDescription'));
            $SetExternalItemID = implode(' + ', array_column($articles, 'ExternalItemID'));
            $SetModel = implode(' + ', array_column($articles, 'Model'));
            $SetVariantName = implode(' + ', array_column($articles, 'VariantName'));

            $SetItemTextsName1 = $Set . ' ' . $SetItemTextsName1;
            $SetItemTextsName2 = $Set . ' ' . $SetItemTextsName2;
            $SetItemTextsName3 = $Set . ' ' . $SetItemTextsName3;

            // Preise & Gewicht
            $SetPurchasePrice = 0;
            $SetWeightG = 0;
            foreach ($articles as $a) {
                $SetPurchasePrice += floatval($a['PurchasePrice']);
                $SetWeightG += intval($a['WeightG']);
            }

            // Maße vom ersten Artikel
            $SetItemProducerName = $articles[0]['ItemProducerName'];
            $SetWidthMM = $articles[0]['WidthMM'];
            $SetLengthMM = $articles[0]['LengthMM'];
            $SetHeightMM = $articles[0]['HeightMM'];

            // Versandprofil anhand Gewicht
            if ($SetWeightG < 1000) {
                $ItemShippingProfiles = '21;12';
            } elseif ($SetWeightG >= 1000 && $SetWeightG < 30000) {
                $ItemShippingProfiles = '12';
            } else {
                $ItemShippingProfiles = '8';
            }

            // Barcode abrufen
            $barcodeRow = $pdo->query("SELECT barcode FROM set_barcode WHERE verwendet = 0 ORDER BY id ASC LIMIT 1")->fetch();
            if (!$barcodeRow) {
                $errorMessages[] = "Job $jobId: Kein verfügbarer Barcode vorhanden.";
                if (!$testmode) {
                    $pdo->prepare("UPDATE set_job SET status = 'Fehler' WHERE id = ?")->execute([$jobId]);
                    mail($mailEmpfaenger, "Fehler beim Set-Export", "Job $jobId: Kein verfügbarer Barcode vorhanden.");
                }
                continue;
            }
            $SetBarcode = $barcodeRow['barcode'];

            if (!$testmode) {
                $pdo->prepare("UPDATE set_barcode SET verwendet = 1 WHERE barcode = ?")->execute([$SetBarcode]);
            }

            $SourceVariantIDs = implode(', ', $variantIds);

            $row = [
                $jobId,
                $SetItemTextsName1,
                $SetItemTextsName2,
                $SetItemTextsName3,
                $SetItemTextsShortDesc,
                $SetExternalItemID,
                $SetModel,
                $SetVariantName,
                number_format($SetPurchasePrice, 2, ',', ''),
                $SetWeightG,
                $SetWidthMM,
                $SetLengthMM,
                $SetHeightMM,
                $SetItemProducerName,
                $ItemShippingProfiles,
                $SourceVariantIDs,
                $SetType,
                $SetBarcode,
                $job['requested_by'],
                date('d.m.Y H:i', strtotime($job['requested_at']))
            ];

            $csvRows[] = $row;

            // Status setzen bei Erfolg
            if (!$testmode) {
                $pdo->prepare("UPDATE set_job SET status = 'Warte auf Set-Komponenten' WHERE id = ?")->execute([$jobId]);
            }

        } catch (Throwable $e) {
            $errorMessages[] = "Job $jobId: " . $e->getMessage();
            if (!$testmode) {
                $pdo->prepare("UPDATE set_job SET status = 'Fehler' WHERE id = ?")->execute([$jobId]);
                mail($mailEmpfaenger, "Fehler beim Set-Export Job $jobId", $e->getMessage());
            }
            continue;
        }
    }

    // ------------------------------------------------------------
    // Testmodus: Tabelle anzeigen
    // ------------------------------------------------------------
    if ($testmode) {
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Set-Export Testmodus</title>';
        echo '<style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:15px;background:#f7f8fb;}
            table{border-collapse:collapse;margin-top:1em;}
            td,th{border:1px solid #888;padding:4px 8px;}
            th{background:#e2e5ee;}
            tr:nth-child(even){background:#f1f3fa;}
            .okay{color:#009933;}
            .warning{color:#e2732b;}
        </style></head><body>';
        echo "<h2>Testmodus: So würde die Sets-CSV aussehen</h2>";

        if (count($csvRows)) {
            echo '<table><tr>';
            foreach ($csvHeader as $h) echo "<th>$h</th>";
            echo '</tr>';
            foreach ($csvRows as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td>' . nl2br(htmlspecialchars($cell)) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
            echo '<p class="okay">Testmodus: Keine Datei geschrieben, keine Jobs verändert!</p>';
        } else {
            echo '<p class="warning">Keine passenden Jobs gefunden oder alle fehlerhaft!</p>';
        }
        echo '</body></html>';
        exit;
    }

    // ------------------------------------------------------------
    // CSV schreiben
    // ------------------------------------------------------------
    if (!is_dir(dirname($csvFile))) {
        mkdir(dirname($csvFile), 0775, true);
    }

    $csvHandle = fopen($csvFile, 'w');
    if ($csvHandle === false) {
        throw new Exception("Fehler: CSV-Datei konnte nicht geschrieben werden!");
    }

    fputcsv($csvHandle, $csvHeader, ';', '"', "\\");
    foreach ($csvRows as $row) {
        $row = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8'), $row);
        fputcsv($csvHandle, $row, ';', '"', "\\");
    }
    fclose($csvHandle);

    echo "Set-CSV erfolgreich generiert: " . $csvFile . "\n";

} catch (Throwable $globalError) {
    $errorText = "Unerwarteter Fehler im Set-Export: " . $globalError->getMessage();
    if (!$testmode) {
        mail($mailEmpfaenger, "Kritischer Fehler im Set-Export", $errorText);
    }
    echo "<p style='color:red;'>$errorText</p>";
}
// === Synesty Flow starten ===
echo "Flow starten mit RunID ";
$url = "https://apps.synesty.com/studio/api/flow/v1?id=BundleErstellen&t=".$token;
$json = file_get_contents($url);
$json = json_decode($json);
echo $json->{'runId'}."<br /><br />";

$url = "https://apps.synesty.com/studio/api/flow/v1?id=BundleErstellen&t=".$token."&action=status&runId=".$json->{'runId'};
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
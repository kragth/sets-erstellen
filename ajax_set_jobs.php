<?php 
/**
 * Projekt: Set-Artikel-Generator
 * Datei: ajax_set_jobs.php
 *
 * Zweck:
 *  - Verarbeitet AJAX-Anfragen aus index.php:
 *      • action=list     → alle Set-Jobs abrufen
 *      • action=add      → neuen Set-Job anlegen
 *      • action=edit     → bestehenden Set-Job ändern
 *      • action=delete   → Set-Job löschen (inkl. Items)
 *  - Bearbeiter wird als ID gespeichert.
 *  - Beim Abrufen (list) wird die Bearbeiter-ID in den Namen übersetzt
 *    und das Mapping an das Frontend übergeben.
 * 
 *  Erweiterung:
 *  - Verhindert das doppelte Anlegen von Sets mit identischer Kombination
 *    aus VariantIDs (reihenfolge-unabhängig, Multiset-genau).
 */

header('Content-Type: application/json; charset=UTF-8');
require_once 'db.php';

// ---------------------------------------------------------------------------
// Bearbeiter-Mapping (ID → Name)
// ---------------------------------------------------------------------------
$bearbeiterMap = [
    8  => 'Andreas',
    47 => 'Emily',
    2  => 'Kristin',
    60 => 'Katrin',
    9  => 'Tino'
];

// ---------------------------------------------------------------------------
// Sicherheitsfunktion für Eingaben
// ---------------------------------------------------------------------------
function clean_input($value) {
    return trim(htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'));
}

// ---------------------------------------------------------------------------
// Hilfsfunktionen zur Duplikatprüfung
// ---------------------------------------------------------------------------

/**
 * Normalisiert Variant-IDs für den Vergleich (Multiset, reihenfolge-unabhängig).
 * - Behält Duplikate (kein Deduplizieren)
 * - Filtert Werte ≤ 0
 * - Sortiert numerisch nur für die Vergleichssignatur
 * 
 * Diese Funktion beeinflusst nicht die gespeicherte Reihenfolge.
 */
function normalize_variant_ids(array $ids): array {
    $out = [];
    foreach ($ids as $v) {
        $iv = (int)$v;
        if ($iv > 0) { 
            $out[] = $iv; 
        }
    }
    sort($out, SORT_NUMERIC);
    return $out;
}

/**
 * Findet einen bestehenden Set-Job mit identischer VariantID-Kombination
 * (reihenfolge-unabhängig, Multiset-genau).
 * Optional kann $excludeId gesetzt werden, um den eigenen Datensatz beim Editieren
 * auszuschließen.
 *
 * Vorgehen:
 * - Aus den übergebenen VariantIDs wird eine sortierte CSV-Signatur erzeugt (z. B. "123,456,789").
 * - In der DB wird je Set eine Signatur via GROUP_CONCAT(ORDER BY variant_id) gebildet.
 * - Nur Kandidaten mit gleicher Stückzahl (HAVING COUNT(*) = :cnt) werden geprüft.
 * - Bei identischer Signatur gilt das Set als Duplikat.
 */
function findDuplicateSet(PDO $pdo, array $variantIds, ?int $excludeId = null): ?array {
    $norm = normalize_variant_ids($variantIds);
    if (count($norm) < 2) {
        return null;
    }

    $needle = implode(',', $norm);
    $params = [ ':cnt' => count($norm) ];
    $excludeSql = '';

    if (!empty($excludeId)) {
        $excludeSql = 'AND s.id <> :excludeId';
        $params[':excludeId'] = $excludeId;
    }

    $sql = "
        SELECT 
            s.id,
            s.new_variant_id,
            GROUP_CONCAT(i.variant_id ORDER BY i.variant_id SEPARATOR ',') AS sig
        FROM set_job s
        JOIN set_job_items i ON i.set_job_id = s.id
        WHERE 1=1
        $excludeSql
        GROUP BY s.id
        HAVING COUNT(*) = :cnt
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($row['sig']) && $row['sig'] === $needle) {
            return $row;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Action-Dispatcher
// ---------------------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // -----------------------------------------------------------------------
    // LIST: alle Set-Jobs abrufen
    // -----------------------------------------------------------------------
    case 'list':
        $stmt = $pdo->query("SELECT * FROM set_job ORDER BY id DESC");
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($jobs as &$job) {
            // VariantIDs in Eingabereihenfolge abrufen
            $stmtItems = $pdo->prepare("SELECT variant_id FROM set_job_items WHERE set_job_id = ? ORDER BY sort_index ASC");
            $stmtItems->execute([$job['id']]);
            $job['variant_ids'] = $stmtItems->fetchAll(PDO::FETCH_COLUMN);

            // Bearbeitername ergänzen
            $id = (int)$job['requested_by'];
            $job['requested_by_id'] = $id;
            $job['requested_by'] = $bearbeiterMap[(string)$id] ?? $bearbeiterMap[(int)$id] ?? 'Unbekannt';
        }

        echo json_encode([
            'success' => true,
            'jobs' => $jobs,
            'bearbeiterMap' => $bearbeiterMap
        ]);
        break;

    // -----------------------------------------------------------------------
    // ADD: neuen Set-Job anlegen
    // -----------------------------------------------------------------------
    case 'add':
        $requested_by = (int)($_POST['requested_by'] ?? 0);
        $set_type     = clean_input($_POST['set_type'] ?? '');
        $variant_ids  = $_POST['variant_ids'] ?? [];

        // Grundprüfung
        if ($requested_by <= 0 || empty($variant_ids) || count($variant_ids) < 2) {
            echo json_encode(['success' => false, 'message' => 'Bitte Bearbeiter und mindestens zwei VariantIDs angeben.']);
            exit;
        }

        // Duplikatprüfung vor Anlage
        if ($dup = findDuplicateSet($pdo, $variant_ids, null)) {
            $norm = normalize_variant_ids($variant_ids);
            $msg  = "Dieses Set existiert bereits (VariantIDs: " . implode(', ', $norm) . "). Kein neuer Datensatz angelegt.";
            if (!empty($dup['new_variant_id'])) {
                $msg .= " Set VariantID: " . (int)$dup['new_variant_id'];
            }
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        // Set-Kopf anlegen
        $stmt = $pdo->prepare("
            INSERT INTO set_job (requested_by, set_type, requested_at, status)
            VALUES (?, ?, NOW(), 'Offen')
        ");
        $stmt->execute([$requested_by, $set_type]);
        $jobId = (int)$pdo->lastInsertId();

        // Varianten speichern (mit Priorität über sort_index)
        $sortIndex = 0;
        $stmtItem = $pdo->prepare("INSERT INTO set_job_items (set_job_id, variant_id, sort_index) VALUES (?, ?, ?)");
        foreach ($variant_ids as $vid) {
            $vid = (int)$vid;
            if ($vid > 0) {
                $stmtItem->execute([$jobId, $vid, $sortIndex]);
                $sortIndex++;
            }
        }

        echo json_encode(['success' => true]);
        break;

    // -----------------------------------------------------------------------
    // EDIT: bestehenden Set-Job aktualisieren
    // -----------------------------------------------------------------------
    case 'edit':
        $id           = (int)($_POST['id'] ?? 0);
        $requested_by = (int)($_POST['requested_by'] ?? 0);
        $set_type     = clean_input($_POST['set_type'] ?? '');
        $variant_ids  = $_POST['variant_ids'] ?? [];

        if ($id <= 0 || $requested_by <= 0 || empty($variant_ids)) {
            echo json_encode(['success' => false, 'message' => 'Fehlende Angaben.']);
            exit;
        }

        // Duplikatprüfung beim Bearbeiten (aktueller Datensatz wird ignoriert)
        if ($dup = findDuplicateSet($pdo, $variant_ids, $id)) {
            $norm = normalize_variant_ids($variant_ids);
            $msg  = "Dieses Set existiert bereits (VariantIDs: " . implode(', ', $norm) . "). Kein neuer Datensatz angelegt.";
            if (!empty($dup['new_variant_id'])) {
                $msg .= " Set VariantID: " . (int)$dup['new_variant_id'];
            }
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        // Kopf aktualisieren
        $stmt = $pdo->prepare("UPDATE set_job SET requested_by = ?, set_type = ? WHERE id = ?");
        $stmt->execute([$requested_by, $set_type, $id]);

        // Varianten neu anlegen (alte löschen)
        $pdo->prepare("DELETE FROM set_job_items WHERE set_job_id = ?")->execute([$id]);
        $sortIndex = 0;
        $stmtItem = $pdo->prepare("INSERT INTO set_job_items (set_job_id, variant_id, sort_index) VALUES (?, ?, ?)");
        foreach ($variant_ids as $vid) {
            $vid = (int)$vid;
            if ($vid > 0) {
                $stmtItem->execute([$id, $vid, $sortIndex]);
                $sortIndex++;
            }
        }

        echo json_encode(['success' => true]);
        break;

    // -----------------------------------------------------------------------
    // DELETE: Set-Job entfernen
    // -----------------------------------------------------------------------
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM set_job_items WHERE set_job_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM set_job WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ungültige ID.']);
        }
        break;

    // -----------------------------------------------------------------------
    // Ungültige Aktion
    // -----------------------------------------------------------------------
    default:
        echo json_encode(['success' => false, 'message' => 'Ungültige Aktion.']);
        break;
}
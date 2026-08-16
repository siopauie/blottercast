<?php
// ============================================================
// blotter_import.php — imports blotter records from a file using
// the exact "Blotter Record" column layout produced by
// api/exports.php?action=blotter_record, so the export/import
// round-trips cleanly. Accepts .xlsx (via xlsx_reader.php) or .csv.
//
// Columns (in order): CASE NO. | CASE TITLE | COMPLAINT TITLE |
// NATURE OF CASE | DATE FILED | DATE OF INITIAL CONFRONTATION |
// ACTION TAKEN | DATE OF SETTLEMENT OR AWARD | DATE OF EXECUTION
// OF SETTLEMENT OR AWARD | MAIN POINT OF AGREEMENT | STATUS OF
// COMPLIANCE ON THE SETTLEMENT OR AWARD
//
// Each row always creates a blotter_records entry. If any of the
// settlement-related columns (confrontation date, action taken,
// settlement date, execution date, main point, or status) have
// data, a linked settlements row is created too — matching how
// the two modules are connected everywhere else in this app.
//
//   POST (multipart/form-data, field "file") → import rows, returns a summary
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';
require_permission('import_data');
require __DIR__ . '/xlsx_reader.php';

$mysqli = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST required', 405);
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_error('No file uploaded, or the upload failed.');
}

$tmpPath = $_FILES['file']['tmp_name'];
$originalName = $_FILES['file']['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($_FILES['file']['size'] > 5 * 1024 * 1024) json_error('File must be smaller than 5MB.');

/** Parse a variety of common date formats into Y-m-d, or null if unparseable. */
function parseFlexibleDate(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    // Excel sometimes stores dates as a raw serial number even in an
    // otherwise-text cell if the source workbook formatted it that way.
    if (ctype_digit($s) && (int)$s > 25000 && (int)$s < 60000) {
        $unixTs = ((int)$s - 25569) * 86400;
        return gmdate('Y-m-d', $unixTs);
    }
    $formats = ['Y-m-d', 'm/d/Y', 'n/j/Y', 'd/m/Y', 'Y/m/d', 'M j, Y', 'F j, Y'];
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d && $d->format($fmt) === $s) return $d->format('Y-m-d');
    }
    $ts = strtotime($s);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/** Split "Complainant vs. Respondent" (also tolerates "Complainant VS Respondent" etc). */
function splitCaseTitle(string $title): array {
    $parts = preg_split('/\s+vs\.?\s+/iu', trim($title), 2);
    if (count($parts) === 2) return [trim($parts[0]), trim($parts[1])];
    return [trim($title), ''];
}

function logAudit(mysqli $mysqli, string $action, string $module, string $details): void {
    $user = $_SESSION['username'] ?? 'system';
    $stmt = $mysqli->prepare('INSERT INTO audit_logs (username, action, module, details) VALUES (?,?,?,?)');
    $stmt->bind_param('ssss', $user, $action, $module, $details);
    $stmt->execute();
}

try {
    if ($ext === 'xlsx') {
        $rows = SimpleXlsxReader::read($tmpPath);
    } elseif ($ext === 'csv') {
        $rows = [];
        if (($fh = fopen($tmpPath, 'r')) !== false) {
            while (($row = fgetcsv($fh)) !== false) $rows[] = $row;
            fclose($fh);
        }
    } else {
        json_error('Please upload a .xlsx or .csv file.');
    }
} catch (Throwable $e) {
    json_error('Could not read that file: ' . $e->getMessage());
}

if (count($rows) < 2) json_error('No data rows found in that file.');

// Locate the real header row — the export has a merged title bar in row 1,
// so search the first few rows for the one that actually looks like the
// Blotter Record header (matches on the first column's label).
$headerRowIndex = null;
foreach (array_slice($rows, 0, 5, true) as $i => $row) {
    $first = strtoupper(trim($row[0] ?? ''));
    if ($first === 'CASE NO.') { $headerRowIndex = $i; break; }
}
if ($headerRowIndex === null) {
    json_error('This doesn\'t look like a Blotter Record file — expected a "CASE NO." column header. Export a template from this page first if you need the exact format.');
}

$dataRows = array_slice($rows, $headerRowIndex + 1);

$insertBlt = $mysqli->prepare(
    'INSERT INTO blotter_records (docket_no, date_filed, complainant, complainant_id, complainant_addr, respondent, respondent_id, respondent_addr, nature, case_type, status, zone_id)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
);
$insertStl = $mysqli->prepare(
    'INSERT INTO settlements (blotter_id, case_no, case_title, complaint_title, nature, date_filed, date_confrontation, action_taken, date_settlement, date_execution, main_point, status, remarks)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
);

require __DIR__ . '/nextseq.php';

$imported = 0; $settlementsCreated = 0; $skipped = 0; $errors = [];
$rowNum = $headerRowIndex + 1;
foreach ($dataRows as $row) {
    $rowNum++;
    $caseNo = trim($row[0] ?? '');
    $caseTitle = trim($row[1] ?? '');
    $complaintTitle = trim($row[2] ?? '');
    $natureOfCase = trim($row[3] ?? '');
    $dateFiledRaw = trim($row[4] ?? '');
    $dateConfrontationRaw = trim($row[5] ?? '');
    $actionTaken = trim($row[6] ?? '');
    $dateSettlementRaw = trim($row[7] ?? '');
    $dateExecutionRaw = trim($row[8] ?? '');
    $mainPoint = trim($row[9] ?? '');
    $statusRaw = trim($row[10] ?? '');

    if ($caseTitle === '' && $complaintTitle === '') { $skipped++; continue; } // blank row

    [$complainant, $respondent] = splitCaseTitle($caseTitle);
    if ($complainant === '') { $errors[] = "Row $rowNum: could not read a Case Title."; $skipped++; continue; }

    $complainantId = findCensusResidentIdByName($mysqli, $complainant);
    $respondentId = findCensusResidentIdByName($mysqli, $respondent);
    $complainantIsResident = $complainantId !== null || isNameACensusResident($mysqli, $complainant);
    $respondentIsResident = $respondentId !== null || isNameACensusResident($mysqli, $respondent);
    if (!$complainantIsResident && !$respondentIsResident) {
        $errors[] = "Row $rowNum: skipped — neither \"$complainant\" nor \"$respondent\" matches a resident in Census.";
        $skipped++;
        continue;
    }
    $sameCensusPerson = $complainantId !== null && $respondentId !== null && $complainantId === $respondentId;
    $sameNameTyped = strtolower(trim($complainant)) === strtolower(trim($respondent));
    if ($sameCensusPerson || $sameNameTyped) {
        $errors[] = "Row $rowNum: skipped — complainant and respondent (\"$complainant\") are the same person.";
        $skipped++;
        continue;
    }

    $dateFiled = parseFlexibleDate($dateFiledRaw) ?? date('Y-m-d');
    // NATURE OF CASE here is "Criminal — description" or "Civil — description"
    // (as produced by the export) or just a plain description on a hand-made
    // import — accept either.
    $caseType = 'CIVIL';
    $natureDesc = $natureOfCase;
    if (preg_match('/^(criminal|civil)\s*[—-]\s*(.+)$/iu', $natureOfCase, $m)) {
        $caseType = strtoupper($m[1]) === 'CRIMINAL' ? 'CRIM' : 'CIVIL';
        $natureDesc = trim($m[2]);
    } elseif ($complaintTitle !== '') {
        $natureDesc = $complaintTitle;
    }

    $docketNo = nextSeqNo($mysqli, 'blotter_records', 'docket_no', 'BLT');
    $zoneId = 'Zone 1'; // not part of this column layout — defaults to Zone 1, editable afterward
    $status = 'Ongoing';
    $emptyAddr = '';

    $insertBlt->bind_param('sssississsss', $docketNo, $dateFiled, $complainant, $complainantId, $emptyAddr, $respondent, $respondentId, $emptyAddr, $natureDesc, $caseType, $status, $zoneId);
    if (!$insertBlt->execute()) {
        $errors[] = "Row $rowNum: could not save this blotter entry.";
        $skipped++;
        continue;
    }
    $blotterId = $mysqli->insert_id;
    $imported++;

    // If any settlement-related column has data, create the linked
    // settlement record too — this is the "if the settlement part has
    // data, create a new record in Settlement Monitor" behavior.
    $hasSettlementData = $dateConfrontationRaw !== '' || $actionTaken !== '' || $dateSettlementRaw !== ''
        || $dateExecutionRaw !== '' || $mainPoint !== '' || $statusRaw !== '';
    if ($hasSettlementData) {
        $stlCaseNo = $caseNo !== '' ? $caseNo : nextSeqNo($mysqli, 'settlements', 'case_no', 'STL');
        $stlNature = $caseType === 'CRIM' ? 'Criminal' : 'Civil';
        $dateConfrontation = parseFlexibleDate($dateConfrontationRaw);
        $dateSettlement = parseFlexibleDate($dateSettlementRaw);
        $dateExecution = parseFlexibleDate($dateExecutionRaw);
        $stlStatus = 'Pending';
        $statusUpper = strtoupper($statusRaw);
        if (str_contains($statusUpper, 'NOT COMPLIED')) $stlStatus = 'Not Complied';
        elseif (str_contains($statusUpper, 'COMPLIED')) $stlStatus = 'Complied';
        $remarks = '';
        $blotterIdStr = (string)$blotterId;

        $insertStl->bind_param('sssssssssssss', $blotterIdStr, $stlCaseNo, $caseTitle, $complaintTitle, $stlNature,
            $dateFiled, $dateConfrontation, $actionTaken, $dateSettlement, $dateExecution, $mainPoint, $stlStatus, $remarks);
        if ($insertStl->execute()) {
            $settlementsCreated++;
        } else {
            $errors[] = "Row $rowNum: blotter entry saved, but its linked settlement could not be created.";
        }
    }
}

logAudit($mysqli, 'Imported', 'Blotter', "Imported $imported blotter record(s) ($settlementsCreated linked settlement(s)) from $originalName");

json_response([
    'ok' => true,
    'imported' => $imported,
    'settlementsCreated' => $settlementsCreated,
    'skipped' => $skipped,
    'errors' => array_slice($errors, 0, 10), // cap so a bad file doesn't return a huge payload
]);

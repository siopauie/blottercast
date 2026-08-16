<?php
// ============================================================
// exports.php — generates .xlsx files matching three official
// barangay forms exactly (column names, order, and grouped
// headers), using the dependency-free writer in xlsx_writer.php.
//
//   GET ?action=settlement_monitoring   → "Monitoring of Compliance
//        to Settlement or Award" (from Settlement Monitor page)
//   GET ?action=blotter_record          → "Blotter Record" wide form
//        with linked settlement columns (from Blotter Records page)
//   GET ?action=blotter_entry_2025      → "Blotter Entry Record 2025"
//        docket-style form (from Blotter Records page)
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';
require_permission('generate_reports');
require __DIR__ . '/xlsx_writer.php';

$mysqli = db();
$action = $_GET['action'] ?? '';

/**
 * Parse ?year=2026&month=7 (month optional) into a [from, to, label] date
 * range for filtering, or [null, null, ''] if no year was given (full history).
 */
function parseDateFilter(): array {
    $year = $_GET['year'] ?? '';
    $month = $_GET['month'] ?? '';
    if (!$year || !ctype_digit($year) || strlen($year) !== 4) {
        return [null, null, 'All Records'];
    }
    if ($month && ctype_digit($month) && (int)$month >= 1 && (int)$month <= 12) {
        $m = (int)$month;
        $from = sprintf('%04d-%02d-01', $year, $m);
        $to = date('Y-m-t', strtotime($from)); // last day of that month
        $monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
        return [$from, $to, $monthNames[$m] . ' ' . $year];
    }
    return ["$year-01-01", "$year-12-31", (string)$year];
}

[$filterFrom, $filterTo, $filterLabel] = parseDateFilter();

function filenameSuffix(): string {
    global $filterFrom;
    $year = $_GET['year'] ?? ''; $month = $_GET['month'] ?? '';
    if (!$filterFrom) return date('Ymd'); // no filter: stamp with today's date
    if ($month && ctype_digit($month)) return $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
    return $year;
}

function streamXlsx(SimpleXlsxWriter $w, string $sheetName, string $filename): void {
    $tmpPath = sys_get_temp_dir() . '/' . uniqid('bc_export_', true) . '.xlsx';
    $w->save($tmpPath, $sheetName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpPath));
    readfile($tmpPath);
    unlink($tmpPath);
    exit;
}

const HEADER_BG = '1E3A5F';   // dark blue header fill
const HEADER_FG = 'FFFFFF';   // white header text
const TITLE_BG  = 'FFFF00';   // yellow title bar (matches the supplied form)
const ALT_BG    = 'F2F6FA';   // light zebra-stripe for data rows

function headerCell(string $text): array {
    return ['value' => $text, 'bold' => true, 'bg' => HEADER_BG, 'color' => HEADER_FG, 'border' => true, 'align' => 'center', 'wrap' => true, 'size' => 10];
}
function dataCell($value, bool $alt = false, string $align = 'left'): array {
    return ['value' => (string)($value ?? ''), 'border' => true, 'bg' => $alt ? ALT_BG : null, 'align' => $align, 'size' => 10];
}

// ---------------------------------------------------------------
// 1. Monitoring of Compliance to Settlement or Award
//    Columns: CASE NO. | CASE TITLE (COMPLAINANT VS. RESPONDENT) |
//    COMPLAINT TITLE | ACTION TAKEN (M,C, C w EP, and C46+) |
//    SETTLEMENT OR AWARD [merged] -> DATE AGREED | DATE OF EXECUTION |
//    MAIN POINT OF AGREEMENT | STATUS OF COMPLIANCE (COMPLIED OR NOT
//    COMPLIED) | REMARKS
// ---------------------------------------------------------------
if ($action === 'settlement_monitoring') {
    $where = ''; $params = []; $types = '';
    if ($filterFrom) { $where = ' WHERE s.date_filed BETWEEN ? AND ?'; $params = [$filterFrom, $filterTo]; $types = 'ss'; }
    $stmt = $mysqli->prepare(
        'SELECT s.*, b.docket_no, b.complainant, b.respondent
         FROM settlements s
         JOIN blotter_records b ON b.id = s.blotter_id' . $where . '
         ORDER BY s.date_filed DESC, s.id DESC'
    );
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $w = new SimpleXlsxWriter();
    foreach ([1=>14, 2=>32, 3=>22, 4=>16, 5=>14, 6=>14, 7=>32, 8=>16, 9=>24] as $col => $width) {
        $w->setColumnWidth($col, $width);
    }

    // Title bar
    $w->addRow([['value' => 'MONITORING OF COMPLIANCE TO SETTLEMENT or AWARD (' . $filterLabel . ')', 'bold' => true, 'bg' => TITLE_BG, 'align' => 'center', 'size' => 12]], 22);
    $w->mergeCells(1, 1, 1, 9);

    // Header row 1 (with SETTLEMENT OR AWARD spanning two sub-columns)
    $w->addRow([
        headerCell('CASE NO.'),
        headerCell('CASE TITLE (COMPLAINANT VS. RESPONDENT)'),
        headerCell('COMPLAINT TITLE'),
        headerCell("ACTION TAKEN\n(M,C, C w EP, A, and C46+)"),
        headerCell('SETTLEMENT OR AWARD'),
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true], // merged-away cell
        headerCell('MAIN POINT OF AGREEMENT'),
        headerCell("STATUS OF COMPLIANCE\n(COMPLIED OR NOT COMPLIED)"),
        headerCell('REMARKS'),
    ], 30);
    $headerRow1 = 2;
    $w->mergeCells($headerRow1, 5, $headerRow1, 6); // SETTLEMENT OR AWARD spans cols E:F
    // Vertically merge every other single-row header down through row 3 (sub-header row)
    foreach ([1,2,3,4,7,8,9] as $col) $w->mergeCells($headerRow1, $col, $headerRow1 + 1, $col);

    // Header row 2 (sub-columns under SETTLEMENT OR AWARD)
    $w->addRow([
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true],
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true],
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true],
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true],
        headerCell('DATE AGREED'),
        headerCell('DATE OF EXECUTION'),
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true],
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true],
        ['value' => '', 'bold' => true, 'bg' => HEADER_BG, 'border' => true],
    ], 20);

    $i = 0;
    foreach ($rows as $r) {
        $alt = ($i % 2) === 1;
        $caseTitle = $r['complainant'] . ' vs. ' . $r['respondent'];
        $status = strtoupper($r['status'] === 'Not Complied' ? 'NOT COMPLIED' : ($r['status'] === 'Complied' ? 'COMPLIED' : 'PENDING'));
        $w->addRow([
            dataCell($r['case_no'], $alt, 'center'),
            dataCell($caseTitle, $alt),
            dataCell($r['complaint_title'], $alt),
            dataCell($r['action_taken'], $alt, 'center'),
            dataCell($r['date_settlement'], $alt, 'center'),
            dataCell($r['date_execution'], $alt, 'center'),
            dataCell($r['main_point'], $alt),
            dataCell($status, $alt, 'center'),
            dataCell($r['remarks'], $alt),
        ]);
        $i++;
    }

    streamXlsx($w, 'Settlement Monitoring', 'monitoring-compliance-settlement-award-' . filenameSuffix() . '.xlsx');
}

// ---------------------------------------------------------------
// 2. Blotter Record (wide form with linked settlement columns)
//    Columns: CASE NO. | CASE TITLE | COMPLAINT TITLE | NATURE OF
//    CASE | DATE FILED | DATE OF INITIAL CONFRONTATION | ACTION
//    TAKEN | DATE OF SETTLEMENT OR AWARD | DATE OF EXECUTION OF
//    SETTLEMENT OR AWARD | MAIN POINT OF AGREEMENT | STATUS OF
//    COMPLIANCE ON THE SETTLEMENT OR AWARD
// ---------------------------------------------------------------
if ($action === 'blotter_record') {
    // Every blotter case, left-joined to its settlement (if one exists yet).
    $where = ''; $params = []; $types = '';
    if ($filterFrom) { $where = ' WHERE b.date_filed BETWEEN ? AND ?'; $params = [$filterFrom, $filterTo]; $types = 'ss'; }
    $stmt = $mysqli->prepare(
        'SELECT b.docket_no, b.complainant, b.respondent, b.nature, b.date_filed, b.case_type,
                s.date_confrontation, s.action_taken, s.date_settlement, s.date_execution, s.main_point, s.status
         FROM blotter_records b
         LEFT JOIN settlements s ON s.blotter_id = b.id' . $where . '
         ORDER BY b.date_filed DESC, b.id DESC'
    );
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $w = new SimpleXlsxWriter();
    foreach ([1=>14, 2=>30, 3=>20, 4=>16, 5=>14, 6=>16, 7=>14, 8=>16, 9=>18, 10=>32, 11=>20] as $col => $width) {
        $w->setColumnWidth($col, $width);
    }

    $w->addRow([['value' => 'BLOTTER RECORD (' . $filterLabel . ')', 'bold' => true, 'bg' => HEADER_BG, 'color' => HEADER_FG, 'align' => 'center', 'size' => 12]], 24);
    $w->mergeCells(1, 1, 1, 11);

    $w->addRow([
        headerCell('CASE NO.'), headerCell('CASE TITLE'), headerCell('COMPLAINT TITLE'),
        headerCell('NATURE OF CASE'), headerCell('DATE FILED'), headerCell('DATE OF INITIAL CONFRONTATION'),
        headerCell('ACTION TAKEN'), headerCell('DATE OF SETTLEMENT OR AWARD'),
        headerCell('DATE OF EXECUTION OF SETTLEMENT OR AWARD'), headerCell('MAIN POINT OF AGREEMENT'),
        headerCell('STATUS OF COMPLIANCE ON THE SETTLEMENT OR AWARD'),
    ], 34);

    $i = 0;
    foreach ($rows as $r) {
        $alt = ($i % 2) === 1;
        $caseTitle = $r['complainant'] . ' vs. ' . $r['respondent'];
        $nature = ($r['case_type'] === 'CRIM' ? 'Criminal' : 'Civil') . ' — ' . $r['nature'];
        $status = $r['status'] ? strtoupper($r['status'] === 'Not Complied' ? 'NOT COMPLIED' : ($r['status'] === 'Complied' ? 'COMPLIED' : 'PENDING')) : '';
        $w->addRow([
            dataCell($r['docket_no'], $alt, 'center'),
            dataCell($caseTitle, $alt),
            dataCell($r['nature'], $alt),
            dataCell($nature, $alt),
            dataCell($r['date_filed'], $alt, 'center'),
            dataCell($r['date_confrontation'], $alt, 'center'),
            dataCell($r['action_taken'], $alt, 'center'),
            dataCell($r['date_settlement'], $alt, 'center'),
            dataCell($r['date_execution'], $alt, 'center'),
            dataCell($r['main_point'], $alt),
            dataCell($status, $alt, 'center'),
        ]);
        $i++;
    }

    streamXlsx($w, 'Blotter Record', 'blotter-record-' . filenameSuffix() . '.xlsx');
}

// ---------------------------------------------------------------
// 3. Blotter Entry Record 2025 (docket-style form)
//    Columns: DOCKET NO. | DATE FILED | NAME OF COMPLAINANT |
//    ADDRESS | NAME OF RESPONDENT | ADDRESS | NATURE OF CASE |
//    CRIMINAL | CIVIL   (two separate marker columns)
// ---------------------------------------------------------------
if ($action === 'blotter_entry_2025') {
    $where = ''; $params = []; $types = '';
    if ($filterFrom) { $where = ' WHERE date_filed BETWEEN ? AND ?'; $params = [$filterFrom, $filterTo]; $types = 'ss'; }
    $stmt = $mysqli->prepare('SELECT * FROM blotter_records' . $where . ' ORDER BY date_filed ASC, id ASC');
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Title year: use the requested filter year if one was given, otherwise
    // fall back to the year of the earliest matching record, otherwise today.
    if ($_GET['year'] ?? '') {
        $titleYear = $_GET['year'];
    } elseif (!empty($rows)) {
        $titleYear = substr($rows[0]['date_filed'], 0, 4);
    } else {
        $titleYear = date('Y');
    }

    $w = new SimpleXlsxWriter();
    foreach ([1=>9, 2=>13, 3=>26, 4=>28, 5=>26, 6=>28, 7=>20, 8=>11, 9=>9] as $col => $width) {
        $w->setColumnWidth($col, $width);
    }

    $titleSuffix = ($filterFrom && ($_GET['month'] ?? '')) ? ' — ' . $filterLabel : '';
    $w->addRow([['value' => 'BLOTTER ENTRY RECORD ' . $titleYear . $titleSuffix, 'bold' => true, 'align' => 'center', 'size' => 18]], 30);
    $w->mergeCells(1, 1, 1, 9);

    $w->addRow([
        headerCell('DOCKET NO.'), headerCell('DATE FILED'), headerCell('NAME OF COMPLAINANT'),
        headerCell('ADDRESS'), headerCell('NAME OF RESPONDENT'), headerCell('ADDRESS'),
        headerCell('NATURE OF CASE'), headerCell('CRIMINAL'), headerCell('CIVIL'),
    ], 24);

    $i = 1;
    foreach ($rows as $r) {
        $alt = ($i % 2) === 0;
        $w->addRow([
            dataCell($i, $alt, 'center'),
            dataCell($r['date_filed'], $alt, 'center'),
            dataCell($r['complainant'], $alt),
            dataCell($r['complainant_addr'], $alt),
            dataCell($r['respondent'], $alt),
            dataCell($r['respondent_addr'], $alt),
            dataCell($r['nature'], $alt),
            dataCell($r['case_type'] === 'CRIM' ? '/' : '', $alt, 'center'),
            dataCell($r['case_type'] === 'CIVIL' ? '/' : '', $alt, 'center'),
        ]);
        $i++;
    }

    streamXlsx($w, 'Blotter Entry Record', 'blotter-entry-record-' . filenameSuffix() . '.xlsx');
}

json_error('Unknown export type', 404);

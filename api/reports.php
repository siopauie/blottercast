<?php
// ============================================================
// reports.php — generates real downloadable reports (PDF/CSV)
// from live MySQL data, using TCPDF (vendor/tcpdf) for PDFs.
//
//   GET  ?action=list                       → recent generated reports
//   POST ?action=generate  (JSON body)       → build + log a report, returns file URL
//   GET  ?action=download&file=xxx.pdf       → stream a previously generated file
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';
require_permission('generate_reports');
require __DIR__ . '/../vendor/tcpdf/tcpdf.php';

$mysqli = db();
$action = $_GET['action'] ?? '';
$REPORTS_DIR = __DIR__ . '/../generated_reports';
if (!@is_dir($REPORTS_DIR)) @mkdir($REPORTS_DIR, 0775, true);
if (!@is_writable($REPORTS_DIR)) {
    $REPORTS_DIR = sys_get_temp_dir() . '/generated_reports';
    if (!@is_dir($REPORTS_DIR)) @mkdir($REPORTS_DIR, 0775, true);
}

// ---------------------------------------------------------------
// Shared TCPDF setup: letterhead, footer, brand color, base layout.
// ---------------------------------------------------------------
define('BC_GREEN', [30, 126, 30]);   // #1e7e1e
define('BC_GREEN_DARK', [15, 66, 15]); // #0f420f
define('BC_GREEN_PALE', [240, 250, 240]); // #f0faf0

class BlotterCastPdf extends TCPDF {
    public string $subtitle = '';

    public function Header() {
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(...BC_GREEN_DARK);
        $this->SetXY(15, 12);
        $this->Cell(0, 8, 'BlotterCast', 0, 1, 'L');
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(90, 110, 90);
        $this->SetXY(15, 20);
        $this->Cell(0, 5, 'Barangay Mapulang Lupa, Pandi, Bulacan', 0, 1, 'L');
        if ($this->subtitle) {
            $this->SetXY(15, 25);
            $this->Cell(0, 5, $this->subtitle, 0, 1, 'L');
        }
        $this->SetDrawColor(...BC_GREEN);
        $this->SetLineWidth(0.6);
        $this->Line(15, 31, 195, 31);
        $this->SetY(36);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 10, 'Generated ' . date('F j, Y g:i A') . '  |  Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
    }

    public function sectionHeading(string $text) {
        $this->Ln(2);
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(...BC_GREEN_DARK);
        $this->Cell(0, 8, $text, 0, 1, 'L');
        $this->SetDrawColor(216, 243, 216);
        $this->SetLineWidth(0.3);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 180, $this->GetY());
        $this->Ln(3);
    }

    public function kv(string $label, string $value) {
        $this->SetFont('helvetica', 'B', 9.5);
        $this->SetTextColor(60, 90, 60);
        $this->Cell(55, 6, $label, 0, 0, 'L');
        $this->SetFont('helvetica', '', 9.5);
        $this->SetTextColor(20, 20, 20);
        $this->Cell(0, 6, $value, 0, 1, 'L');
    }

    /** @param string[] $headers @param array<int,string[]> $rows @param int[] $widths */
    public function table(array $headers, array $rows, array $widths) {
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetFillColor(...BC_GREEN);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(200, 200, 200);
        foreach ($headers as $i => $h) $this->Cell($widths[$i], 7, $h, 1, 0, 'L', true);
        $this->Ln();

        $this->SetFont('helvetica', '', 8.5);
        $fill = false;
        foreach ($rows as $row) {
            if ($this->GetY() > 265) { $this->AddPage(); $this->SetFont('helvetica', '', 8.5); }
            $this->SetFillColor(...BC_GREEN_PALE);
            $this->SetTextColor(30, 30, 30);
            foreach ($row as $i => $cell) {
                $this->Cell($widths[$i], 6, (string)$cell, 1, 0, 'L', $fill);
            }
            $this->Ln();
            $fill = !$fill;
        }
    }
}

function newReportPdf(string $subtitle): BlotterCastPdf {
    $pdf = new BlotterCastPdf('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->subtitle = $subtitle;
    $pdf->SetCreator('BlotterCast');
    $pdf->SetAuthor($_SESSION['full_name'] ?? 'System');
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15, 38, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    return $pdf;
}

// ---------------------------------------------------------------
// Report builders — each returns raw PDF bytes.
// ---------------------------------------------------------------
function buildIncidentSummaryPdf($mysqli, string $from, string $to, ?string $zone): string {
    $where = ['incident_date BETWEEN ? AND ?'];
    $params = [$from, $to]; $types = 'ss';
    if ($zone) { $where[] = 'zone_id = ?'; $params[] = $zone; $types .= 's'; }
    $sql = 'SELECT * FROM incidents WHERE ' . implode(' AND ', $where) . ' ORDER BY incident_date';
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $byCategory = []; $byStatus = [];
    foreach ($rows as $r) {
        $byCategory[$r['category']] = ($byCategory[$r['category']] ?? 0) + 1;
        $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
    }
    arsort($byCategory);

    $pdf = newReportPdf('Incident Summary Report');
    $pdf->kv('Period:', $from . '  to  ' . $to . ($zone ? "   ·   Zone: $zone" : '   ·   All Zones'));
    $pdf->kv('Total Incidents:', (string)count($rows));
    $pdf->Ln(3);

    $pdf->sectionHeading('Status Breakdown');
    foreach ($byStatus as $status => $c) $pdf->kv($status . ':', (string)$c);
    $pdf->Ln(3);

    $pdf->sectionHeading('Category Breakdown');
    foreach ($byCategory as $cat => $c) $pdf->kv($cat . ':', (string)$c);
    $pdf->Ln(3);

    $pdf->sectionHeading('Incident Log');
    $tableRows = array_map(fn($r) => [$r['report_no'], $r['incident_date'], $r['zone_id'], $r['category'], $r['priority'], $r['status']], $rows);
    $pdf->table(['Report No.', 'Date', 'Zone', 'Category', 'Priority', 'Status'], $tableRows, [30, 24, 18, 45, 22, 41]);

    return $pdf->Output('', 'S');
}

function buildSettlementCompliancePdf($mysqli): string {
    $rows = $mysqli->query('SELECT * FROM settlements ORDER BY date_filed DESC')->fetch_all(MYSQLI_ASSOC);
    $byStatus = [];
    foreach ($rows as $r) $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;

    $pdf = newReportPdf('Settlement Compliance Report');
    $pdf->kv('Total Settlement Cases:', (string)count($rows));
    $pdf->Ln(3);
    $pdf->sectionHeading('Status Breakdown');
    foreach ($byStatus as $s => $c) $pdf->kv($s . ':', (string)$c);
    $pdf->Ln(3);

    $pdf->sectionHeading('Case Log');
    $tableRows = array_map(fn($r) => [$r['case_no'], $r['case_title'] ?? '', $r['nature'], $r['date_filed'] ?? '', $r['status']], $rows);
    $pdf->table(['Case No.', 'Case Title', 'Nature', 'Date Filed', 'Status'], $tableRows, [26, 55, 30, 30, 39]);

    return $pdf->Output('', 'S');
}

function buildTrendAnalysisPdf($mysqli, string $year): string {
    $stmt = $mysqli->prepare('SELECT MONTH(incident_date) m, COUNT(*) c FROM incidents WHERE YEAR(incident_date)=? GROUP BY m ORDER BY m');
    $stmt->bind_param('s', $year); $stmt->execute();
    $monthly = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt2 = $mysqli->prepare('SELECT category, COUNT(*) c FROM incidents WHERE YEAR(incident_date)=? GROUP BY category ORDER BY c DESC');
    $stmt2->bind_param('s', $year); $stmt2->execute();
    $cats = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $pdf = newReportPdf('Trend Analysis Report - ' . $year);

    $pdf->sectionHeading('Monthly Incident Count');
    $tableRows = array_map(fn($r) => [$months[$r['m']-1], (string)$r['c']], $monthly);
    $pdf->table(['Month', 'Incidents'], $tableRows, [90, 90]);
    $pdf->Ln(4);

    $pdf->sectionHeading('Category Breakdown');
    $tableRows2 = array_map(fn($r) => [$r['category'], (string)$r['c']], $cats);
    $pdf->table(['Category', 'Incidents'], $tableRows2, [90, 90]);

    return $pdf->Output('', 'S');
}

function buildPredictiveRiskPdf($mysqli): string {
    $row = $mysqli->query('SELECT * FROM ml_runs ORDER BY id DESC LIMIT 1')->fetch_assoc();
    $pdf = newReportPdf('Predictive Risk Assessment');

    if (!$row) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, 'No trained model run found yet. Visit the Predictions page to train a model, then regenerate this report.', 0, 'L');
        return $pdf->Output('', 'S');
    }

    $hotspots = json_decode($row['hotspots_json'], true);
    $metrics = json_decode($row['metrics_json'], true);
    $active = $row['active_model'];

    $pdf->kv('Active Model:', str_replace('_', ' ', ucwords($active, '_')));
    if (isset($metrics[$active])) {
        $m = $metrics[$active];
        $pdf->kv('Accuracy / AUC / F1:', round($m['accuracy']*100,1) . '%  /  ' . $m['auc'] . '  /  ' . round($m['f1']*100,1) . '%');
    }
    $pdf->kv('Trained On:', $row['record_count'] . ' records at ' . $row['trained_at']);
    $pdf->Ln(3);

    $pdf->sectionHeading('Zone Risk Ranking');
    $tableRows = array_map(fn($h) => [
        $h['zone'], round($h['meanDailyProb']*100,1).'%', (string)$h['expectedCount7d'], $h['topCategory'], $h['peakWindow'],
    ], $hotspots);
    $pdf->table(['Zone', 'Daily Prob.', 'Expected/7d', 'Top Category', 'Peak Window'], $tableRows, [25, 25, 25, 55, 50]);

    return $pdf->Output('', 'S');
}

function logReport($mysqli, string $type, string $from, string $to, string $format, string $filePath): void {
    $user = $_SESSION['full_name'] ?? 'System';
    $stmt = $mysqli->prepare('INSERT INTO generated_reports (report_type, generated_by, period_from, period_to, format, file_path) VALUES (?,?,?,?,?,?)');
    $fromNull = $from ?: null; $toNull = $to ?: null;
    $stmt->bind_param('ssssss', $type, $user, $fromNull, $toNull, $format, $filePath);
    $stmt->execute();
}

// ---------------- Routes ----------------
if ($action === 'list') {
    $rows = $mysqli->query('SELECT * FROM generated_reports ORDER BY id DESC LIMIT 20')->fetch_all(MYSQLI_ASSOC);
    json_response($rows);
}

if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = body();
    $type = $d['type'] ?? 'Incident Summary Report';
    $from = $d['from'] ?? date('Y-m-01');
    $to = $d['to'] ?? date('Y-m-d');
    $zone = $d['zone'] ?? null;
    $format = $d['format'] ?? 'pdf';
    $year = substr($from, 0, 4);

    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($type));
    $filename = $slug . '-' . date('Ymd-His') . '.' . ($format === 'excel' ? 'csv' : 'pdf');
    $filePath = $REPORTS_DIR . '/' . $filename;

    if ($format === 'excel') {
        // Excel-compatible CSV export driven by the same query as the PDF
        $fh = fopen($filePath, 'w');
        fputcsv($fh, [$type, 'Barangay Mapulang Lupa, Pandi, Bulacan', 'Generated ' . date('Y-m-d H:i')]);
        fputcsv($fh, []);
        if ($type === 'Incident Summary Report' || $type === 'Predictive Risk Assessment' || $type === 'Patrol Deployment Plan') {
            $where = ['incident_date BETWEEN ? AND ?']; $params = [$from, $to]; $types = 'ss';
            if ($zone) { $where[] = 'zone_id = ?'; $params[] = $zone; $types .= 's'; }
            $stmt = $mysqli->prepare('SELECT report_no, incident_date, zone_id, category, priority, status FROM incidents WHERE ' . implode(' AND ', $where) . ' ORDER BY incident_date');
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            fputcsv($fh, ['Report No.', 'Date', 'Zone', 'Category', 'Priority', 'Status']);
            foreach ($stmt->get_result() as $row) fputcsv($fh, array_values($row));
        } elseif ($type === 'Settlement Compliance Report') {
            fputcsv($fh, ['Case No.', 'Case Title', 'Nature', 'Date Filed', 'Status']);
            $rows = $mysqli->query('SELECT case_no, case_title, nature, date_filed, status FROM settlements ORDER BY date_filed DESC');
            foreach ($rows as $row) fputcsv($fh, array_values($row));
        } elseif ($type === 'Trend Analysis Report' || $type === 'Comparative Period Report') {
            $stmt = $mysqli->prepare('SELECT MONTH(incident_date) m, COUNT(*) c FROM incidents WHERE YEAR(incident_date)=? GROUP BY m ORDER BY m');
            $stmt->bind_param('s', $year); $stmt->execute();
            fputcsv($fh, ['Month', 'Incident Count']);
            $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            foreach ($stmt->get_result() as $row) fputcsv($fh, [$months[$row['m']-1], $row['c']]);
        } else {
            fputcsv($fh, ['No data available for this report type yet.']);
        }
        fclose($fh);
    } else {
        $pdfBytes = match ($type) {
            'Settlement Compliance Report' => buildSettlementCompliancePdf($mysqli),
            'Trend Analysis Report', 'Comparative Period Report' => buildTrendAnalysisPdf($mysqli, $year),
            'Predictive Risk Assessment', 'Patrol Deployment Plan' => buildPredictiveRiskPdf($mysqli),
            default => buildIncidentSummaryPdf($mysqli, $from, $to, $zone ?: null),
        };
        file_put_contents($filePath, $pdfBytes);
    }

    logReport($mysqli, $type, $from, $to, strtoupper($format === 'excel' ? 'Excel' : 'PDF'), $filename);
    json_response(['ok' => true, 'file' => $filename, 'url' => 'api/reports.php?action=download&file=' . urlencode($filename)]);
}

if ($action === 'download') {
    $file = basename($_GET['file'] ?? ''); // basename() blocks path traversal
    $path = $REPORTS_DIR . '/' . $file;
    if (!$file || !is_file($path)) { http_response_code(404); echo 'Report not found.'; exit; }
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    header('Content-Type: ' . ($ext === 'csv' ? 'text/csv' : 'application/pdf'));
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

json_error('Unknown action', 404);

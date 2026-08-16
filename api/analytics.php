<?php
// ============================================================
// analytics.php — read-only aggregate endpoints for dashboard,
// heat map, and trends pages. All computed live from MySQL.
//   ?action=dashboard
//   ?action=heatmap&from=YYYY-MM-DD&to=YYYY-MM-DD&category=...
//   ?action=trends&year=2026
//   ?action=zones
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';

$mysqli = db();
$action = $_GET['action'] ?? '';

if ($action === 'zones') {
    $res = $mysqli->query('SELECT * FROM zones ORDER BY zone_id');
    json_response($res->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'dashboard') {
    $blotterCount = $mysqli->query('SELECT COUNT(*) c FROM blotter_records')->fetch_assoc()['c'];
    $incidentCount = $mysqli->query('SELECT COUNT(*) c FROM incidents')->fetch_assoc()['c'];
    $weekCount = $mysqli->query("SELECT COUNT(*) c FROM incidents WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
    $pendingStl = $mysqli->query("SELECT COUNT(*) c FROM settlements WHERE status='Pending'")->fetch_assoc()['c'];
    $resolved = $mysqli->query("SELECT COUNT(*) c FROM incidents WHERE status IN ('Resolved','Closed')")->fetch_assoc()['c'];
    $resRate = $incidentCount > 0 ? round($resolved / $incidentCount * 100) : 0;
    $recent = $mysqli->query('SELECT * FROM blotter_records ORDER BY date_filed DESC, id DESC LIMIT 8')->fetch_all(MYSQLI_ASSOC);

    json_response([
        'blotterCount' => (int)$blotterCount,
        'incidentCount' => (int)$incidentCount,
        'weekCount' => (int)$weekCount,
        'pendingSettlements' => (int)$pendingStl,
        'resolutionRate' => (int)$resRate,
        'recentBlotter' => $recent,
    ]);
}

if ($action === 'heatmap') {
    require_permission('view_analytics');
    $where = [];
    $params = []; $types = '';
    if (!empty($_GET['from'])) { $where[] = 'incident_date >= ?'; $params[] = $_GET['from']; $types .= 's'; }
    if (!empty($_GET['to']))   { $where[] = 'incident_date <= ?'; $params[] = $_GET['to'];   $types .= 's'; }
    if (!empty($_GET['category']) && $_GET['category'] !== 'all') { $where[] = 'category = ?'; $params[] = $_GET['category']; $types .= 's'; }
    $sql = 'SELECT id, incident_date, zone_id, lat, lng, category, priority, status, location FROM incidents' .
           ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY incident_date DESC';
    $stmt = $mysqli->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    json_response($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'trends') {
    require_permission('view_analytics');
    $years = $mysqli->query('SELECT DISTINCT YEAR(incident_date) y FROM incidents ORDER BY y DESC')->fetch_all(MYSQLI_ASSOC);
    $year = $_GET['year'] ?? ($years[0]['y'] ?? date('Y'));

    $monthly = $mysqli->prepare('SELECT MONTH(incident_date) m, COUNT(*) c FROM incidents WHERE YEAR(incident_date)=? GROUP BY m ORDER BY m');
    $monthly->bind_param('s', $year);
    $monthly->execute();
    $monthlyRows = $monthly->get_result()->fetch_all(MYSQLI_ASSOC);

    $dow = $mysqli->prepare('SELECT DAYOFWEEK(incident_date) d, COUNT(*) c FROM incidents WHERE YEAR(incident_date)=? GROUP BY d ORDER BY d');
    $dow->bind_param('s', $year);
    $dow->execute();
    $dowRows = $dow->get_result()->fetch_all(MYSQLI_ASSOC);

    $cat = $mysqli->prepare('SELECT category, COUNT(*) c FROM incidents WHERE YEAR(incident_date)=? GROUP BY category ORDER BY c DESC');
    $cat->bind_param('s', $year);
    $cat->execute();
    $catRows = $cat->get_result()->fetch_all(MYSQLI_ASSOC);

    $total = $mysqli->prepare('SELECT COUNT(*) c FROM incidents WHERE YEAR(incident_date)=?');
    $total->bind_param('s', $year);
    $total->execute();
    $totalCount = (int)$total->get_result()->fetch_assoc()['c'];

    $prevYear = (string)((int)$year - 1);
    $prevTotal = $mysqli->prepare('SELECT COUNT(*) c FROM incidents WHERE YEAR(incident_date)=?');
    $prevTotal->bind_param('s', $prevYear);
    $prevTotal->execute();
    $prevCount = (int)$prevTotal->get_result()->fetch_assoc()['c'];

    $resolved = $mysqli->prepare("SELECT COUNT(*) c FROM incidents WHERE YEAR(incident_date)=? AND status IN ('Resolved','Closed')");
    $resolved->bind_param('s', $year);
    $resolved->execute();
    $resolvedCount = (int)$resolved->get_result()->fetch_assoc()['c'];

    json_response([
        'years' => array_map(fn($r) => (int)$r['y'], $years),
        'year' => (int)$year,
        'monthly' => $monthlyRows,
        'dayOfWeek' => $dowRows,
        'categories' => $catRows,
        'total' => $totalCount,
        'prevYearTotal' => $prevCount,
        'resolvedCount' => $resolvedCount,
        'resolutionRate' => $totalCount > 0 ? round($resolvedCount / $totalCount * 100) : 0,
    ]);
}

json_error('Unknown action', 404);

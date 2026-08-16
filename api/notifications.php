<?php
// ============================================================
// notifications.php — dashboard notification bell, backed by
// real system conditions rather than fabricated content:
//   - a new High priority incident was logged
//   - a settlement has been Pending for more than 14 days
//   - the latest ML run flags a zone above the configured risk
//     threshold (Settings → risk_threshold, default 75%)
//
//   GET  ?action=list&limit=20   → recent notifications + this user's read state
//   GET  ?action=unread_count    → badge count for the bell icon
//   POST ?action=mark_read&id=5  → mark one notification read (this user)
//   POST ?action=mark_all_read   → mark everything read (this user)
// ============================================================
require __DIR__ . '/config.php';
require_login();

$mysqli = db();
$action = $_GET['action'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

/**
 * Scan for new alert-worthy conditions and insert any that don't already
 * exist, using (type, ref_table, ref_id) to avoid duplicating the same
 * alert on every page load. Runs on every list/unread_count call — cheap
 * queries, and it means alerts appear without needing a cron job.
 */
function generateNotifications(mysqli $mysqli): void {
    // 1) New High-priority incidents (last 3 days, not already alerted)
    $stmt = $mysqli->query(
        "SELECT id, report_no, location, zone_id FROM incidents
         WHERE priority = 'High' AND incident_date >= (CURDATE() - INTERVAL 3 DAY)
         AND id NOT IN (SELECT ref_id FROM notifications WHERE type='new_incident' AND ref_table='incidents' AND ref_id IS NOT NULL)"
    );
    foreach ($stmt->fetch_all(MYSQLI_ASSOC) as $r) {
        $title = 'High-priority incident reported';
        $body = "{$r['report_no']} at {$r['location']} ({$r['zone_id']})";
        $ins = $mysqli->prepare("INSERT INTO notifications (type, title, body, severity, link, ref_table, ref_id) VALUES ('new_incident', ?, ?, 'critical', 'incident.html', 'incidents', ?)");
        $ins->bind_param('ssi', $title, $body, $r['id']);
        $ins->execute();
    }

    // 2) Settlements still Pending after 14+ days since filing
    $stmt = $mysqli->query(
        "SELECT id, case_no, case_title FROM settlements
         WHERE status = 'Pending' AND date_filed <= (CURDATE() - INTERVAL 14 DAY)
         AND id NOT IN (SELECT ref_id FROM notifications WHERE type='settlement_overdue' AND ref_table='settlements' AND ref_id IS NOT NULL)"
    );
    foreach ($stmt->fetch_all(MYSQLI_ASSOC) as $r) {
        $title = 'Settlement follow-up overdue';
        $body = "{$r['case_no']}" . ($r['case_title'] ? " ({$r['case_title']})" : '') . ' has been pending for 14+ days';
        $ins = $mysqli->prepare("INSERT INTO notifications (type, title, body, severity, link, ref_table, ref_id) VALUES ('settlement_overdue', ?, ?, 'warning', 'settlement.html', 'settlements', ?)");
        $ins->bind_param('ssi', $title, $body, $r['id']);
        $ins->execute();
    }

    // 3) Latest ML run: any zone above the configured risk threshold
    $thresholdRow = $mysqli->query("SELECT setting_value FROM system_settings WHERE setting_key='risk_threshold'")->fetch_assoc();
    $threshold = ((float)($thresholdRow['setting_value'] ?? 75)) / 100;

    $run = $mysqli->query('SELECT id, hotspots_json FROM ml_runs ORDER BY id DESC LIMIT 1')->fetch_assoc();
    if ($run) {
        $hotspots = json_decode($run['hotspots_json'], true) ?: [];
        foreach ($hotspots as $h) {
            if (($h['meanDailyProb'] ?? 0) < $threshold) continue;
            // De-dupe on the ml_runs id (via ref_id) so a re-train that still
            // flags the same zone doesn't spam a second identical alert.
            $refId = (int)$run['id'];
            $zone = $h['zone'] ?? '?';
            $exists = $mysqli->prepare(
                "SELECT id FROM notifications WHERE type='high_risk_zone' AND ref_table='ml_runs' AND ref_id=? AND body LIKE ?"
            );
            $zoneLike = "%$zone%";
            $exists->bind_param('is', $refId, $zoneLike);
            $exists->execute();
            if ($exists->get_result()->fetch_assoc()) continue;

            $pct = round($h['meanDailyProb'] * 100);
            $title = 'Elevated incident risk forecast';
            $body = "Zone $zone is forecast at {$pct}% daily incident probability, above the configured threshold";
            $ins = $mysqli->prepare("INSERT INTO notifications (type, title, body, severity, link, ref_table, ref_id) VALUES ('high_risk_zone', ?, ?, 'warning', 'predictions.html', 'ml_runs', ?)");
            $ins->bind_param('ssi', $title, $body, $refId);
            $ins->execute();
        }
    }
}

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    generateNotifications($mysqli);
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $stmt = $mysqli->prepare(
        "SELECT n.*, (nr.notification_id IS NOT NULL) AS is_read
         FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
         ORDER BY n.created_at DESC LIMIT ?"
    );
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    json_response($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'unread_count' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    generateNotifications($mysqli);
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) c FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
         WHERE nr.notification_id IS NULL"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    json_response(['count' => (int)$stmt->get_result()->fetch_assoc()['c']]);
}

if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('id required');
    $stmt = $mysqli->prepare('INSERT IGNORE INTO notification_reads (user_id, notification_id) VALUES (?, ?)');
    $stmt->bind_param('ii', $userId, $id);
    $stmt->execute();
    json_response(['ok' => true]);
}

if ($action === 'mark_all_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $mysqli->prepare(
        'INSERT IGNORE INTO notification_reads (user_id, notification_id)
         SELECT ?, id FROM notifications'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    json_response(['ok' => true]);
}

json_error('Unknown action', 404);

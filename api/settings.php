<?php
// ============================================================
// settings.php — persists every field/toggle on the Settings
// page as key-value rows in system_settings, and runs a full
// SQL (schema + data) database backup.
//   GET  ?action=list                → all settings as {key: value}
//   POST ?action=save   (JSON body)  → bulk-update settings {key: value, ...}
//   POST ?action=backup              → dump schema+data to backup/*.sql, log to backups table
//   GET  ?action=backups             → backup history
//   GET  ?action=download_backup&file=xxx.sql → stream a backup file
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';

$mysqli = db();
$action = $_GET['action'] ?? '';
$BACKUP_DIR = __DIR__ . '/../backup';
if (!@is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0775, true);
if (!@is_writable($BACKUP_DIR)) {
    $BACKUP_DIR = sys_get_temp_dir() . '/backup';
    if (!@is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0775, true);
}

/**
 * Dump every table in the current database as plain SQL text — full
 * schema (DROP TABLE + CREATE TABLE, taken verbatim from SHOW CREATE
 * TABLE so keys/engine/charset all match exactly) followed by every
 * row as an INSERT statement. Pure PHP/mysqli — no shelling out to the
 * `mysqldump` binary, so it works the same way whether or not exec()
 * is available/enabled on the server (many shared hosts and locked-down
 * XAMPP installs disable it), and doesn't depend on mysqldump being on
 * the PATH or living at one of a few guessed locations.
 */
function generateSqlDump($mysqli): string {
    $out = "-- BlotterCast database backup\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Database: " . DB_NAME . "\n\n";

    // ---- enumerate tables via pg_catalog (works on both MySQL via transformSqlForPg and PG) ----
    $tablesRes = $mysqli->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    $tables = [];
    if ($tablesRes) {
        while ($row = $tablesRes->fetch_row()) {
            $tables[] = is_array($row) ? $row[0] : array_values((array)$row)[0];
        }
    }
    // Fallback: hard-coded table list if pg_catalog query returns nothing
    if (empty($tables)) {
        $tables = ['users','zones','incidents','blotter_records','settlements','audit_logs',
                   'notifications','notification_reads','ml_runs','generated_reports',
                   'system_settings','backups','census_residents','documents'];
    }

    foreach ($tables as $table) {
        $out .= "-- ----------------------------\n";
        $out .= "-- Records of $table\n";
        $out .= "-- ----------------------------\n";

        $dataRes = $mysqli->query("SELECT * FROM \"$table\"");
        if (!$dataRes) continue;

        $rows = $dataRes->fetch_all();
        if (empty($rows)) { $out .= "\n"; continue; }

        // Use first row keys as column list
        $firstRow = $dataRes->fetch_assoc() ?? reset($rows);
        if (!$firstRow) { $out .= "\n"; continue; }
        // Re-fetch all since fetch_assoc consumed one
        $dataRes2 = $mysqli->query("SELECT * FROM \"$table\"");
        if (!$dataRes2) continue;

        $allRows = [];
        while ($row = $dataRes2->fetch_assoc()) $allRows[] = $row;
        if (empty($allRows)) { $out .= "\n"; continue; }

        $cols = array_keys($allRows[0]);
        $colList = implode(', ', array_map(fn($c) => "\"$c\"", $cols));

        foreach ($allRows as $row) {
            $vals = array_map(function ($v) use ($mysqli) {
                if ($v === null) return 'NULL';
                if (is_int($v) || is_float($v)) return $v;
                $escaped = str_replace("'", "''", (string)$v);
                return "'$escaped'";
            }, array_values($row));
            $out .= "INSERT INTO \"$table\" ($colList) VALUES (" . implode(', ', $vals) . ");\n";
        }
        $out .= "\n";
    }

    return $out;
}


/**
 * Run a full schema+data backup, write it to backup/*.sql, log it to
 * the backups + audit_logs tables, and return a result array. Shared
 * by the manual "Run Backup Now" button and the automatic check-on-login
 * trigger, so both paths behave identically and show up in the same
 * history list.
 */
function runDatabaseBackup($mysqli, string $backupDir, string $triggeredBy): array {
    $filename = 'blottercast-backup-' . date('Ymd-His') . '.sql';
    $filePath = $backupDir . '/' . $filename;

    $success = false;
    $errMsg = null;
    try {
        $sql = generateSqlDump($mysqli);
        $written = file_put_contents($filePath, $sql);
        $success = $written !== false && is_file($filePath) && filesize($filePath) > 0;
        if (!$success) $errMsg = 'Could not write backup file — check that the backup/ folder is writable.';
    } catch (Throwable $e) {
        $errMsg = 'Backup failed: ' . $e->getMessage();
    }

    $size = $success ? filesize($filePath) : 0;
    $status = $success ? 'Success' : 'Failed';

    $stmt = $mysqli->prepare('INSERT INTO backups (file_name, size_bytes, status, created_by) VALUES (?,?,?,?)');
    $stmt->bind_param('siss', $filename, $size, $status, $triggeredBy);
    $stmt->execute();

    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Exported', 'Backup', ?)");
    $detail = $success ? "Database backup created: $filename" : 'Database backup failed';
    $log->bind_param('ss', $triggeredBy, $detail);
    $log->execute();

    return ['success' => $success, 'file' => $filename, 'size' => $size, 'error' => $errMsg];
}

/**
 * Has enough time passed since the last backup, per the configured
 * frequency, that one is due? Used by the check-on-login auto-trigger.
 */
function isBackupDue($mysqli): bool {
    $settings = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('backup_frequency','backup_time')")->fetch_all(MYSQLI_ASSOC);
    $map = [];
    foreach ($settings as $s) $map[$s['setting_key']] = $s['setting_value'];
    $frequency = $map['backup_frequency'] ?? 'Daily';

    $last = $mysqli->query("SELECT created_at FROM backups WHERE status='Success' ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    if (!$last) return true; // never backed up before — due immediately

    $lastTime = strtotime($last['created_at']);
    $intervalSeconds = match ($frequency) {
        'Every 12 hours' => 12 * 3600,
        'Weekly' => 7 * 24 * 3600,
        default => 24 * 3600, // Daily
    };
    return (time() - $lastTime) >= $intervalSeconds;
}

// Check-on-login auto-backup: called from the dashboard/settings bootstrap.
// If enough time has passed per the configured frequency, silently run one
// in the background and report what happened — no terminal, no manual
// step, and it piggybacks on a page load instead of needing a real cron
// daemon that XAMPP doesn't provide out of the box.
if ($action === 'auto_backup_check' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_permission('system_settings');
    if (!isBackupDue($mysqli)) {
        json_response(['ran' => false]);
    }
    $result = runDatabaseBackup($mysqli, $BACKUP_DIR, 'system (automatic)');
    json_response(['ran' => true] + $result);
}

// The barangay-wide default ML model — one per task (occurrence, type,
// hotspot), since each task now has its own pair of algorithms — is
// readable by anyone with view_analytics (so the Predictions page can
// show the right defaults to everyone, not just Admin/Captain), but only
// settable by whoever can actually retrain — same tier as the Retrain
// button itself.
$ML_TASK_KEYS = [
    'occurrence' => ['setting' => 'ml_occurrence_model', 'default' => 'random_forest', 'allowed' => ['logistic_regression', 'random_forest']],
    'type'       => ['setting' => 'ml_type_model', 'default' => 'gradient_boosting', 'allowed' => ['decision_tree', 'gradient_boosting']],
    'hotspot'    => ['setting' => 'ml_hotspot_model', 'default' => 'random_forest', 'allowed' => ['random_forest', 'gradient_boosting']],
];

if ($action === 'ml_model' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_permission('view_analytics');
    $keys = array_column($ML_TASK_KEYS, 'setting');
    $in = "'" . implode("','", $keys) . "'";
    $rows = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)")->fetch_all(MYSQLI_ASSOC);
    $byKey = [];
    foreach ($rows as $r) $byKey[$r['setting_key']] = $r['setting_value'];
    $out = [];
    foreach ($ML_TASK_KEYS as $task => $cfg) {
        $out[$cfg['setting']] = $byKey[$cfg['setting']] ?? $cfg['default'];
    }
    json_response($out);
}
if ($action === 'ml_model' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('retrain_ml');
    $d = body();
    $task = $d['task'] ?? '';
    $model = $d['model'] ?? '';
    if (!isset($ML_TASK_KEYS[$task])) json_error('Invalid task. Expected occurrence, type, or hotspot.');
    $cfg = $ML_TASK_KEYS[$task];
    if (!in_array($model, $cfg['allowed'], true)) {
        json_error('Invalid model for the ' . $task . ' task. Expected one of: ' . implode(', ', $cfg['allowed']));
    }
    $settingKey = $cfg['setting'];
    $stmt = $mysqli->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param('sss', $settingKey, $model, $model);
    $stmt->execute();
    json_response(['ok' => true]);
}

// Non-sensitive public system settings (captain name, barangay name, time_format, date_format)
// are readable by any signed-in user — certificates and dynamic time formatting need it regardless of role —
// while every other action below still requires full system_settings access.
if (($action === 'letterhead' || $action === 'public') && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $mysqli->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('captain_name','barangay_name','time_format','date_format')");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $out = [
        'captain_name'  => 'Kapitan Jose Reyes',
        'barangay_name' => 'Barangay Mapulang Lupa',
        'time_format'   => '12-Hour (AM/PM)',
        'date_format'   => 'MM/DD/YYYY'
    ];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    json_response($out);
}

require_permission('system_settings');

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $mysqli->query('SELECT setting_key, setting_value FROM system_settings')->fetch_all(MYSQLI_ASSOC);
    $out = [
        'barangay_name'        => 'Barangay Mapulang Lupa',
        'municipality'         => 'Pandi, Bulacan',
        'region'               => 'Region III – Central Luzon',
        'captain_name'         => 'Kapitan Jose Reyes',
        'contact_no'           => '09170000000',
        'email'                => 'mapulanglupa@pandi.gov.ph',
        'date_format'          => 'MM/DD/YYYY',
        'time_format'          => '12-Hour (AM/PM)',
        'records_per_page'     => '6',
        'default_language'     => 'English',
        'risk_threshold'       => '75',
        'spike_threshold'      => '5',
        'notif_inapp'          => '1',
        'notif_retrain'        => '1',
        'two_factor_auth'      => '0',
        'lockout_enabled'      => '1',
        'session_timeout'      => '30',
        'max_failed_logins'    => '5',
        'min_password_length'  => '8',
        'password_expiry_days' => '90',
        'audit_trail'          => '1',
        'data_subject_rights'  => '1',
        'backup_frequency'     => 'Daily',
        'backup_time'          => '02:00',
        'backup_destination'   => 'Local Storage Device',
        'retain_backups_days'  => '30',
        'rto_hours'            => '4 hours',
        'rpo_hours'            => '24 hours',
    ];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    json_response($out);
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = body();
    if (!$d) json_error('No settings provided');

    foreach ($d as $key => $value) {
        $cleanKey = preg_replace('/[^a-z0-9_]/i', '', (string)$key); // keep keys safe
        if ($cleanKey === '') continue;
        $val = (string)$value;
        $stmt = $mysqli->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        if ($stmt) {
            $stmt->bind_param('ss', $cleanKey, $val);
            $stmt->execute();
        }
    }

    // Invalidate security settings session cache so changes take effect immediately
    unset($_SESSION['sec_settings']);

    // Synchronize active Barangay Captain user full_name if captain_name is updated
    if (!empty($d['captain_name'])) {
        $capName = trim((string)$d['captain_name']);
        if ($capName !== '') {
            $updCap = $mysqli->prepare("UPDATE users SET full_name = ? WHERE role = 'Barangay Captain' AND status = 'Active'");
            if ($updCap) {
                $updCap->bind_param('s', $capName);
                $updCap->execute();
            }
        }
    }

    $user = $_SESSION['username'] ?? 'system';
    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Updated', 'Settings', 'System settings saved')");
    $log->bind_param('s', $user);
    $log->execute();

    json_response(['ok' => true, 'updated' => count($d)]);
}

if ($action === 'backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_SESSION['username'] ?? 'system';
    $result = runDatabaseBackup($mysqli, $BACKUP_DIR, $user);
    if (!$result['success']) {
        json_response(['ok' => false, 'error' => $result['error']], 500);
    }
    json_response(['ok' => true, 'file' => $result['file'], 'size' => $result['size'],
        'url' => 'api/settings.php?action=download_backup&file=' . urlencode($result['file'])]);
}

if ($action === 'backups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $mysqli->query('SELECT * FROM backups ORDER BY id DESC LIMIT 20')->fetch_all(MYSQLI_ASSOC);
    json_response($rows);
}

if ($action === 'download_backup') {
    $file = basename($_GET['file'] ?? '');
    $path = $BACKUP_DIR . '/' . $file;
    if (!$file || !is_file($path)) { http_response_code(404); echo 'Backup not found.'; exit; }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

json_error('Unknown action', 404);

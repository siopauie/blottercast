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
if (!is_dir($BACKUP_DIR)) mkdir($BACKUP_DIR, 0775, true);

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
function generateSqlDump(mysqli $mysqli): string {
    $out = "-- BlotterCast database backup\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Database: " . DB_NAME . "\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $out .= "SET NAMES utf8mb4;\n\n";

    $tables = [];
    $res = $mysqli->query('SHOW TABLES');
    while ($row = $res->fetch_row()) $tables[] = $row[0];

    foreach ($tables as $table) {
        // ---- schema ----
        $out .= "-- ----------------------------\n";
        $out .= "-- Table structure for `$table`\n";
        $out .= "-- ----------------------------\n";
        $out .= "DROP TABLE IF EXISTS `$table`;\n";
        $createRow = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $out .= $createRow[1] . ";\n\n";

        // ---- data ----
        $dataRes = $mysqli->query("SELECT * FROM `$table`");
        if ($dataRes->num_rows > 0) {
            $out .= "-- ----------------------------\n";
            $out .= "-- Records of `$table`\n";
            $out .= "-- ----------------------------\n";
            $fields = $dataRes->fetch_fields();
            $colList = implode(', ', array_map(fn($f) => "`{$f->name}`", $fields));
            while ($row = $dataRes->fetch_assoc()) {
                $vals = array_map(function ($v) use ($mysqli) {
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return $v;
                    return "'" . $mysqli->real_escape_string((string)$v) . "'";
                }, array_values($row));
                $out .= "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $out .= "\n";
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

/**
 * Run a full schema+data backup, write it to backup/*.sql, log it to
 * the backups + audit_logs tables, and return a result array. Shared
 * by the manual "Run Backup Now" button and the automatic check-on-login
 * trigger, so both paths behave identically and show up in the same
 * history list.
 */
function runDatabaseBackup(mysqli $mysqli, string $backupDir, string $triggeredBy): array {
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
function isBackupDue(mysqli $mysqli): bool {
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

// Non-sensitive letterhead info (captain name, barangay name) is readable
// by any signed-in user — certificates need it regardless of role — while
// every other action below still requires full system_settings access.
if ($action === 'letterhead' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $mysqli->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('captain_name','barangay_name')");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    json_response($out);
}

require_permission('system_settings');

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $mysqli->query('SELECT setting_key, setting_value FROM system_settings')->fetch_all(MYSQLI_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
    json_response($out);
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = body();
    if (!$d) json_error('No settings provided');

    $stmt = $mysqli->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($d as $key => $value) {
        $key = preg_replace('/[^a-z0-9_]/i', '', (string)$key); // keep keys safe
        $val = (string)$value;
        $stmt->bind_param('ss', $key, $val);
        $stmt->execute();
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

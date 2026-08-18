<?php
// ============================================================
// config.php — database connection for XAMPP (Apache + MySQL)
// Default XAMPP MySQL: host=localhost, user=root, no password.
// If you set a root password in XAMPP, put it below.
// ============================================================

// Every api/*.php endpoint returns JSON. If PHP prints a notice/warning
// as HTML before that JSON (e.g. an undefined array key), the browser's
// JSON.parse() fails with "Unexpected token '<'". Keep errors logged for
// debugging but never echoed into the response body.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Manila');

function getEnvVal(string $key): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return (string)$v;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return '';
}

function getDbCredentials(): array {
    $urlStr = getEnvVal('POSTGRES_URL') ?: (getEnvVal('POSTGRES_PRISMA_URL') ?: (getEnvVal('DATABASE_URL') ?: (getEnvVal('MYSQL_URL') ?: (getEnvVal('SUPABASE_DATABASE_URL') ?: ''))));
    if (!empty($urlStr)) {
        $p = parse_url($urlStr);
        if ($p && isset($p['host'])) {
            $scheme = strtolower($p['scheme'] ?? '');
            $isPgScheme = in_array($scheme, ['postgres', 'postgresql', 'pgsql']);
            $defaultPort = $isPgScheme ? 5432 : 3306;
            return [
                'host' => $p['host'],
                'user' => isset($p['user']) ? rawurldecode($p['user']) : 'root',
                'pass' => isset($p['pass']) ? rawurldecode($p['pass']) : '',
                'name' => ltrim(rawurldecode($p['path'] ?? '/blottercast'), '/'),
                'port' => (int)($p['port'] ?? $defaultPort),
                'ssl'  => true
            ];
        }
    }

    $host = getEnvVal('MYSQL_HOST') ?: (getEnvVal('POSTGRES_HOST') ?: (getEnvVal('DB_HOST') ?: (getEnvVal('BC_DB_HOST') ?: 'localhost')));
    $user = getEnvVal('MYSQL_USER') ?: (getEnvVal('POSTGRES_USER') ?: (getEnvVal('DB_USER') ?: (getEnvVal('BC_DB_USER') ?: 'root')));
    $pass = getEnvVal('MYSQL_PASSWORD') !== '' ? getEnvVal('MYSQL_PASSWORD') : (getEnvVal('POSTGRES_PASSWORD') !== '' ? getEnvVal('POSTGRES_PASSWORD') : (getEnvVal('DB_PASS') !== '' ? getEnvVal('DB_PASS') : (getEnvVal('BC_DB_PASS') !== '' ? getEnvVal('BC_DB_PASS') : '')));
    $name = getEnvVal('MYSQL_DATABASE') ?: (getEnvVal('POSTGRES_DATABASE') ?: (getEnvVal('DB_NAME') ?: (getEnvVal('BC_DB_NAME') ?: 'blottercast')));
    $port = (int)(getEnvVal('MYSQL_PORT') ?: (getEnvVal('POSTGRES_PORT') ?: (getEnvVal('DB_PORT') ?: (getEnvVal('BC_DB_PORT') ?: 3306))));
    $ssl  = getEnvVal('DB_SSL') === 'true' || getEnvVal('DB_SSL') === '1' || getEnvVal('MYSQL_SSL') === 'true' || strpos($host, 'vercel-storage') !== false || strpos($host, 'supabase') !== false || strpos($host, 'aivencloud') !== false || strpos($host, 'pooler') !== false;

    return [
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'name' => $name,
        'port' => $port,
        'ssl'  => $ssl
    ];
}

$cred = getDbCredentials();
define('DB_HOST', $cred['host']);
define('DB_NAME', $cred['name']);
define('DB_USER', $cred['user']);
define('DB_PASS', $cred['pass']);
define('DB_PORT', $cred['port']);

// Base URL of the Python ML microservice (see /ml/service.py)
define('ML_SERVICE_URL', getenv('ML_SERVICE_URL') ?: (getenv('BC_ML_SERVICE_URL') ?: 'http://localhost:5000'));

class BlotterPdoResult {
    private PDOStatement $stmt;
    public int $num_rows = 0;

    public function __construct(PDOStatement $stmt) {
        $this->stmt = $stmt;
        $this->num_rows = $stmt->rowCount();
    }

    public function fetch_assoc(): ?array {
        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function fetch_all(int $mode = 1): array {
        return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch_row(): ?array {
        $row = $this->stmt->fetch(PDO::FETCH_NUM);
        return $row !== false ? $row : null;
    }
}

class BlotterPdoStmtAdapter {
    private PDOStatement $stmt;
    private BlotterPdoAdapter $adapter;
    private array $boundParams = [];
    public int $num_rows = 0;
    public int $affected_rows = 0;
    public int $insert_id = 0;

    public function __construct(PDOStatement $stmt, BlotterPdoAdapter $adapter) {
        $this->stmt = $stmt;
        $this->adapter = $adapter;
    }

    public function bind_param(string $types, &...$args): bool {
        $this->boundParams = [];
        foreach ($args as $idx => &$argRef) {
            $this->boundParams[$idx] = &$argRef;
        }
        return true;
    }

    public function execute(?array $params = null): bool {
        try {
            if ($params !== null) {
                @$this->stmt->closeCursor();
                $res = $this->stmt->execute($params);
            } else {
                $execParams = [];
                foreach ($this->boundParams as $v) {
                    $execParams[] = $v;
                }
                @$this->stmt->closeCursor();
                $res = $this->stmt->execute($execParams);
            }
            $this->affected_rows = $this->stmt->rowCount();
            $this->num_rows = $this->stmt->rowCount();
            $this->adapter->updateInsertId();
            $this->insert_id = $this->adapter->insert_id;
            return $res;
        } catch (Exception $e) {
            error_log('PDO execute error: ' . $e->getMessage());
            json_error('Database statement execution error: ' . $e->getMessage(), 500);
            return false;
        }
    }

    public function get_result(): BlotterPdoResult {
        return new BlotterPdoResult($this->stmt);
    }

    public function close(): bool {
        return true;
    }
}

function transformSqlForPg(string $sql): string {
    $sql = str_replace('`', '"', $sql);

    // ---- skip MySQL-only session variables ----
    if (stripos($sql, 'FOREIGN_KEY_CHECKS') !== false) {
        return '';
    }
    if (preg_match('/^\s*SET\s+NAMES\s/i', $sql)) {
        return '';
    }

    // ---- TRUNCATE … CASCADE ----
    if (preg_match('/^TRUNCATE\s+TABLE\s+([a-z0-9_"]+)/i', trim($sql), $m)) {
        return "TRUNCATE TABLE {$m[1]} CASCADE";
    }

    // ---- SHOW TABLES → pg_catalog ----
    if (preg_match('/^\s*SHOW\s+TABLES/i', $sql)) {
        return "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'";
    }

    // ---- SHOW CREATE TABLE → empty (not supported on PG) ----
    if (preg_match('/^\s*SHOW\s+CREATE\s+TABLE/i', $sql)) {
        return "SELECT '' AS \"Table\", '' AS \"Create Table\"";
    }

    // ---- DATE_SUB(CURDATE(), INTERVAL n DAY/MONTH/YEAR) → CURRENT_DATE - INTERVAL 'n days' ----
    $sql = preg_replace_callback(
        '/DATE_SUB\s*\(\s*CURDATE\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|MONTH|YEAR)\s*\)/i',
        function ($m) {
            $unit = strtolower($m[2]) . 's';
            return "CURRENT_DATE - INTERVAL '{$m[1]} {$unit}'";
        },
        $sql
    );

    // ---- DATE_ADD(CURDATE(), INTERVAL n DAY/MONTH/YEAR) → CURRENT_DATE + INTERVAL 'n days' ----
    $sql = preg_replace_callback(
        '/DATE_ADD\s*\(\s*CURDATE\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|MONTH|YEAR)\s*\)/i',
        function ($m) {
            $unit = strtolower($m[2]) . 's';
            return "CURRENT_DATE + INTERVAL '{$m[1]} {$unit}'";
        },
        $sql
    );

    // ---- (CURDATE() - INTERVAL n DAY) → (CURRENT_DATE - INTERVAL 'n days') ----
    $sql = preg_replace_callback(
        '/CURDATE\s*\(\s*\)\s*-\s*INTERVAL\s+(\d+)\s+(DAY|MONTH|YEAR)/i',
        function ($m) {
            $unit = strtolower($m[2]) . 's';
            return "CURRENT_DATE - INTERVAL '{$m[1]} {$unit}'";
        },
        $sql
    );

    // ---- (CURDATE() + INTERVAL n DAY) → (CURRENT_DATE + INTERVAL 'n days') ----
    $sql = preg_replace_callback(
        '/CURDATE\s*\(\s*\)\s*\+\s*INTERVAL\s+(\d+)\s+(DAY|MONTH|YEAR)/i',
        function ($m) {
            $unit = strtolower($m[2]) . 's';
            return "CURRENT_DATE + INTERVAL '{$m[1]} {$unit}'";
        },
        $sql
    );

    // ---- standalone CURDATE() → CURRENT_DATE ----
    $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql);

    // ---- NOW() → NOW() (same in PG, but keep for completeness) ----
    // NOW() works natively in PostgreSQL — no change needed

    // ---- YEAR(col) → EXTRACT(YEAR FROM col) ----
    $sql = preg_replace_callback(
        '/\bYEAR\s*\(\s*([^)]+)\s*\)/i',
        function ($m) { return "EXTRACT(YEAR FROM {$m[1]})"; },
        $sql
    );

    // ---- MONTH(col) → EXTRACT(MONTH FROM col) ----
    $sql = preg_replace_callback(
        '/\bMONTH\s*\(\s*([^)]+)\s*\)/i',
        function ($m) { return "EXTRACT(MONTH FROM {$m[1]})"; },
        $sql
    );

    // ---- DAYOFWEEK(col) → EXTRACT(DOW FROM col) + 1 ----
    // MySQL DAYOFWEEK: 1=Sunday..7=Saturday; PG DOW: 0=Sunday..6=Saturday
    $sql = preg_replace_callback(
        '/\bDAYOFWEEK\s*\(\s*([^)]+)\s*\)/i',
        function ($m) { return "(EXTRACT(DOW FROM {$m[1]}) + 1)"; },
        $sql
    );

    // ---- TIMESTAMPDIFF(YEAR, col, CURRENT_DATE) → EXTRACT(YEAR FROM AGE(CURRENT_DATE, col)) ----
    $sql = preg_replace_callback(
        '/\bTIMESTAMPDIFF\s*\(\s*YEAR\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i',
        function ($m) {
            $from = trim($m[1]);
            $to = trim($m[2]);
            return "EXTRACT(YEAR FROM AGE({$to}, {$from}))";
        },
        $sql
    );

    // ---- ON DUPLICATE KEY UPDATE → ON CONFLICT ... DO UPDATE SET ----
    if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
        // Rewrite VALUES(col) → EXCLUDED.col
        $sql = preg_replace_callback(
            '/VALUES\s*\(\s*([a-z0-9_"]+)\s*\)/i',
            function ($m) { return 'EXCLUDED.' . trim($m[1], '"'); },
            $sql
        );
        if (stripos($sql, 'INTO users') !== false) {
            $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (username) DO UPDATE SET', $sql);
        } else if (stripos($sql, 'INTO zones') !== false) {
            $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (zone_id) DO UPDATE SET', $sql);
        } else if (stripos($sql, 'INTO system_settings') !== false) {
            $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (setting_key) DO UPDATE SET', $sql);
        } else if (stripos($sql, 'INTO notification_reads') !== false) {
            $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (user_id, notification_id) DO UPDATE SET', $sql);
        } else {
            $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (id) DO UPDATE SET', $sql);
        }
    }

    // ---- REGEXP → ~ (PostgreSQL regex match operator) ----
    $sql = preg_replace('/\bREGEXP\b/i', '~', $sql);

    // ---- AS UNSIGNED / AS SIGNED → AS INTEGER ----
    $sql = preg_replace('/\bAS\s+UNSIGNED\b/i', 'AS INTEGER', $sql);
    $sql = preg_replace('/\bAS\s+SIGNED\b/i',   'AS INTEGER', $sql);

    return $sql;
}

class BlotterPdoAdapter {
    public PDO $pdo;
    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function updateInsertId(): void {
        try {
            $id = $this->pdo->lastInsertId();
            if ($id !== false && $id !== '' && $id !== null) {
                $this->insert_id = (int)$id;
            }
        } catch (Throwable $t) {
            // Silent catch if query did not generate a sequence value
        }
    }

    public function query(string $sql) {
        try {
            $pgSql = transformSqlForPg($sql);
            if ($pgSql === '') return true;
            $stmt = $this->pdo->query($pgSql);
            if (!$stmt) return false;
            $this->affected_rows = $stmt->rowCount();
            $this->updateInsertId();
            return new BlotterPdoResult($stmt);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            json_error('Database query error: ' . $e->getMessage(), 500);
        }
    }

    public function prepare(string $sql) {
        try {
            $pgSql = transformSqlForPg($sql);
            if ($pgSql === '') return new BlotterPdoStmtAdapter($this->pdo->prepare('SELECT 1'), $this);
            $stmt = $this->pdo->prepare($pgSql);
            return new BlotterPdoStmtAdapter($stmt, $this);
        } catch (Exception $e) {
            json_error('Database prepare error: ' . $e->getMessage(), 500);
        }
    }

    public function set_charset(string $charset): bool {
        return true;
    }

    public function real_escape_string(string $str): string {
        return addslashes($str);
    }

    public function escape_string(string $str): string {
        return addslashes($str);
    }
}

function db(bool $allowFailure = false) {
    static $conn = null;
    if ($conn === null) {
        $cred = getDbCredentials();
        $isPg = ($cred['port'] === 5432 || $cred['port'] === 6543 || strpos($cred['host'], 'supabase') !== false || strpos($cred['host'], 'postgres') !== false);
        
        if ($isPg && extension_loaded('pdo_pgsql')) {
            try {
                $dsn = "pgsql:host={$cred['host']};port={$cred['port']};dbname={$cred['name']};sslmode=require";
                $pdo = new PDO($dsn, $cred['user'], $cred['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => true,
                    PDO::ATTR_PERSISTENT => true
                ]);
                $pdo->exec("SET TIME ZONE 'Asia/Manila'");
                $conn = new BlotterPdoAdapter($pdo);
            } catch (PDOException $e) {
                if ($allowFailure) return null;
                json_error('Supabase / PostgreSQL connection error: ' . $e->getMessage(), 500);
            }
        } else {
            mysqli_report(MYSQLI_REPORT_OFF);
            $conn = mysqli_init();
            if ($cred['ssl']) {
                $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
            }
            $connected = @$conn->real_connect($cred['host'], $cred['user'], $cred['pass'], $cred['name'], $cred['port'], NULL, $cred['ssl'] ? MYSQLI_CLIENT_SSL : 0);
            if (!$connected || $conn->connect_error) {
                if ($allowFailure) {
                    $conn = null;
                    return null;
                }
                json_error('Database connection error: ' . ($conn->connect_error ?: 'Could not connect to host'), 500);
            }
            $conn->set_charset('utf8mb4');
            $conn->query("SET time_zone = '+08:00'");
        }
    }
    return $conn;
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

class BlotterDbSessionHandler implements SessionHandlerInterface {
    private string $lastData = '';

    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }

    public function read($id): string|false {
        try {
            $mysqli = db(true);
            if (!$mysqli) return '';
            $stmt = $mysqli->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $key = "sess_" . $id;
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $this->lastData = $row ? (string)$row['setting_value'] : '';
            return $this->lastData;
        } catch (Throwable $t) {
            return '';
        }
    }

    public function write($id, $data): bool {
        if ($data === $this->lastData && $data !== '') {
            return true; // Skip redundant DB write when session is unchanged
        }
        try {
            $mysqli = db(true);
            if (!$mysqli) return true;
            $key = "sess_" . $id;
            $stmt = $mysqli->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param("ss", $key, $data);
            $res = (bool)$stmt->execute();
            if ($res) $this->lastData = $data;
            return $res;
        } catch (Throwable $t) {
            return true;
        }
    }

    public function destroy($id): bool {
        try {
            $mysqli = db(true);
            if (!$mysqli) return true;
            $key = "sess_" . $id;
            $stmt = $mysqli->prepare("DELETE FROM system_settings WHERE setting_key = ?");
            $stmt->bind_param("s", $key);
            $this->lastData = '';
            return (bool)$stmt->execute();
        } catch (Throwable $t) {
            return true;
        }
    }

    public function gc($max_lifetime): int|false {
        return 0;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('session_set_save_handler')) {
        @session_set_save_handler(new BlotterDbSessionHandler(), true);
    }
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '86400');
    session_start();
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        json_error('Not authenticated', 401);
    }

    // Session Timeout (minutes): if the account has been idle longer than
    // the configured limit, force a fresh login instead of letting an
    // abandoned/unlocked browser tab keep acting as that user forever.
    $settings = getSecuritySettings();
    $timeoutSeconds = $settings['session_timeout'] * 60;
    if ($timeoutSeconds > 0 && !empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutSeconds) {
        $_SESSION = [];
        session_destroy();
        json_error('Your session has expired due to inactivity. Please log in again.', 401);
    }
    $_SESSION['last_activity'] = time();

    // Password Expiry (days): once flagged, every other endpoint refuses
    // to do anything until the password is actually changed — the modal
    // in app.js is the normal path, but this is what actually enforces it
    // if someone bypasses the UI (e.g. calling the API directly).
    // auth.php itself is exempt, since that's where change_password lives.
    if (!empty($_SESSION['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'auth.php') {
        json_error('Your password has expired. Please update it before continuing.', 403);
    }
}

/**
 * Read the Settings > Security tab values straight from system_settings,
 * with the same defaults shown on that page, so every enforcement point
 * (login lockout, session timeout, password rules) reads one source of
 * truth instead of each guessing its own fallback.
 */
function getSecuritySettings(bool $fresh = false): array {
    static $cached = null;
    if (!$fresh && $cached !== null) return $cached;
    if (!$fresh && !empty($_SESSION['sec_settings']) && is_array($_SESSION['sec_settings'])) {
        $cached = $_SESSION['sec_settings'];
        return $cached;
    }
    $defaults = [
        'two_factor_auth'      => false,
        'lockout_enabled'      => true,
        'session_timeout'      => 30,
        'max_failed_logins'    => 5,
        'min_password_length'  => 8,
        'password_expiry_days' => 90,
    ];
    try {
        $mysqli = db();
        $keys = "'" . implode("','", array_keys($defaults)) . "'";
        $rows = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($keys)")->fetch_all(MYSQLI_ASSOC);
        $out = $defaults;
        foreach ($rows as $r) {
            $key = $r['setting_key'];
            if ($key === 'lockout_enabled' || $key === 'two_factor_auth') {
                $out[$key] = ($r['setting_value'] === '1' || $r['setting_value'] === 'true');
            } else {
                $out[$key] = (int)$r['setting_value'];
            }
        }
        $cached = $out;
        $_SESSION['sec_settings'] = $out;
        return $out;
    } catch (Throwable $t) {
        return $defaults;
    }
}

if (!function_exists('logAudit')) {
    function logAudit($mysqli, string $action, string $module, string $details): void {
        try {
            $user = $_SESSION['username'] ?? 'system';
            $stmt = $mysqli->prepare('INSERT INTO audit_logs (username, action, module, details) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $user, $action, $module, $details);
            $stmt->execute();
        } catch (Throwable $t) {
            error_log('logAudit error: ' . $t->getMessage());
        }
    }
}

if (!function_exists('computeAge')) {
    function computeAge(?string $dob): ?int {
        if (!$dob || trim($dob) === '') return null;
        try {
            $d = new DateTime($dob);
            $now = new DateTime();
            if ($d > $now) return null;
            return (int)$d->diff($now)->y;
        } catch (Throwable $e) {
            return null;
        }
    }
}

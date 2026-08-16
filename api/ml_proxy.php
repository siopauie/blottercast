<?php
// ============================================================
// ml_proxy.php — enforces login + role permissions before
// forwarding requests to the Python ML microservice (port 5000).
// The Flask service itself has no concept of PHP sessions/roles,
// so every ML call from the browser must go through this proxy
// rather than hitting http://localhost:5000 directly.
//   GET  ?action=health
//   GET  ?action=latest
//   POST ?action=train   (JSON body: {activeModel})
// ============================================================
require __DIR__ . '/config.php';
require_login();
require __DIR__ . '/permissions.php';
require_permission('view_analytics');

// Auto-starting the Python service (cold start: up to ~20s) plus the actual
// model training afterward can together exceed PHP's default 30s execution
// limit — raise it just for this endpoint rather than globally.
set_time_limit(90);

$action = $_GET['action'] ?? '';
$ML_BASE = ML_SERVICE_URL; // defined in config.php, default http://localhost:5000

/** Is the Flask service already up? A cheap, fast check against /health. */
function mlIsRunning(string $base): bool {
    $ch = curl_init("$base/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 800);
    $res = curl_exec($ch);
    $ok = $res !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    return $ok;
}

/**
 * Launch ml/service.py in the background if it isn't already running, then
 * wait (briefly, in short polling steps) for it to come up. This is what
 * lets a barangay staff member just open the Predictions page — no
 * terminal, no "run python ml/service.py" step for them to remember.
 */
function mlEnsureRunning(string $base): bool {
    if (mlIsRunning($base)) return true;

    $mlDir = realpath(__DIR__ . '/../ml');
    $scriptPath = $mlDir . DIRECTORY_SEPARATOR . 'service.py';
    if (!$mlDir || !is_file($scriptPath)) return false; // nothing we can launch

    $logFile = $mlDir . DIRECTORY_SEPARATOR . 'service.log';
    $isWindows = stripos(PHP_OS, 'WIN') === 0;

    // Prefer a bundled venv's python if one exists (ml/venv on Linux/Mac,
    // ml/venv/Scripts on Windows); otherwise fall back to whatever "python"
    // resolves to on the PATH.
    $venvPython = $isWindows
        ? $mlDir . '\\venv\\Scripts\\python.exe'
        : $mlDir . '/venv/bin/python';
    $python = is_file($venvPython) ? $venvPython : ($isWindows ? 'python' : 'python3');

    // proc_open() takes the command as an array on PHP 7.4+, which passes
    // each argument straight to the process without going through a shell
    // at all — this sidesteps Windows-vs-Linux escapeshellarg() differences
    // entirely, rather than trying to hand-build a quoted command string.
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];
    $options = $isWindows ? ['bypass_shell' => true] : [];
    $process = @proc_open([$python, $scriptPath], $descriptors, $pipes, $mlDir, null, $options);
    if (is_resource($process)) {
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        // Deliberately do not wait on this process or call proc_close() —
        // doing so would block until it exits, but service.py is meant to
        // keep running indefinitely as a background server.
    }

    // Poll briefly for it to come up — model loading + first Flask bind is
    // fast on most machines, but a cold start (first run, or a slower PC
    // importing scikit-learn/pandas for the first time) can take longer,
    // so give it real time rather than failing right away.
    for ($i = 0; $i < 40; $i++) {
        usleep(500_000); // 0.5s — up to 20s total
        if (mlIsRunning($base)) return true;
    }
    return false;
}

function forward(string $url, string $method = 'GET', ?string $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        json_error('ML service unreachable: ' . $error, 502);
    }
    http_response_code($httpCode ?: 200);
    header('Content-Type: application/json');
    echo $response;
    exit;
}

// health stays a fast, pure status check (no auto-start) so the frontend can
// poll it while showing a "starting up…" state without blocking on a launch
// attempt every time.
if ($action === 'health' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (mlIsRunning($ML_BASE)) {
        forward("$ML_BASE/health");
    }
    json_response(['status' => 'down'], 200);
}

// latest and train are what the Predictions page actually needs data from,
// so auto-start the service here if it isn't already running — the person
// using the page never has to open a terminal themselves.
if (!mlEnsureRunning($ML_BASE)) {
    json_error(
        'The prediction service could not be started automatically. ' .
        'Make sure Python is installed and its packages are set up ' .
        '(see ml/requirements.txt), then check ml/service.log for details.',
        503
    );
}

if ($action === 'latest' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    forward("$ML_BASE/latest");
}

if ($action === 'train' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('retrain_ml'); // stricter than view_analytics: Desk Officer can view, not retrain
    $body = file_get_contents('php://input');
    forward("$ML_BASE/train", 'POST', $body);
}

json_error('Unknown action', 404);

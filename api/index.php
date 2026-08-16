<?php
// ============================================================
// index.php — Unified Vercel API Gateway Router
// Routes all /api/*.php calls through a single Serverless Function
// to comply with Vercel Hobby Plan (max 12 serverless functions).
// ============================================================

set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Server Exception: ' . $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine()
    ]);
    exit;
});

$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH);
$parts = array_values(array_filter(explode('/', $path)));

// Look for file requested after /api/
$file = 'auth.php';
if (!empty($parts)) {
    $last = end($parts);
    if (strpos($last, '.php') !== false && $last !== 'index.php') {
        $file = $last;
    } else if (isset($_GET['route']) && $_GET['route'] !== '') {
        $r = explode('&', explode('?', $_GET['route'])[0])[0];
        $r = basename($r);
        if (!empty($r) && $r !== 'index.php') {
            $file = strpos($r, '.php') !== false ? $r : $r . '.php';
        }
    }
}

$target = __DIR__ . '/' . $file;

if (file_exists($target) && is_file($target) && $file !== 'index.php') {
    $_SERVER['SCRIPT_NAME'] = '/api/' . $file;
    require $target;
} else {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Endpoint not found: ' . $file]);
}

<?php
// ============================================================
// users.php — user account management (System Admin / Barangay
// Captain / Desk Officer / Data Encoder) and the audit log feed.
//   GET    ?action=list                 → all users
//   POST   ?action=create                → create a user
//   PUT    ?action=update&id=5           → update a user
//   POST   ?action=toggle_status&id=5    → flip Active/Suspended
//   DELETE ?action=delete&id=5           → remove a user
//   GET    ?action=audit&limit=10        → recent audit log rows
// ============================================================
require __DIR__ . '/config.php';
require_login();

$mysqli = db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Non-sensitive: the current Barangay Captain's name + signature image path,
// readable by any signed-in user — certificates need it regardless of role,
// same reasoning as the letterhead endpoint in settings.php. Every other
// action below still requires full manage_users access.
if ($action === 'captain_signature' && $method === 'GET') {
    $row = $mysqli->query("SELECT full_name, signature_path FROM users WHERE role = 'Barangay Captain' AND status = 'Active' ORDER BY id LIMIT 1")->fetch_assoc();
    json_response([
        'fullName' => $row['full_name'] ?? null,
        'signaturePath' => $row['signature_path'] ?? null,
    ]);
}

require __DIR__ . '/permissions.php';
require_permission('manage_users');

function logAudit(mysqli $mysqli, string $action, string $module, string $details): void {
    $user = $_SESSION['username'] ?? 'system';
    $stmt = $mysqli->prepare('INSERT INTO audit_logs (username, action, module, details) VALUES (?,?,?,?)');
    $stmt->bind_param('ssss', $user, $action, $module, $details);
    $stmt->execute();
}

if ($action === 'list' && $method === 'GET') {
    $rows = $mysqli->query('SELECT id, username, full_name, email, contact_no, role, status, signature_path, last_login, created_at FROM users ORDER BY full_name')->fetch_all(MYSQLI_ASSOC);
    json_response($rows);
}

if ($action === 'create' && $method === 'POST') {
    $d = body();
    $username = trim($d['username'] ?? '');
    $fullName = trim($d['name'] ?? '');
    $password = $d['password'] ?? '';
    if ($username === '' || $fullName === '' || $password === '') json_error('Name, username, and password are required');

    $minLen = getSecuritySettings()['min_password_length'];
    if (strlen($password) < $minLen) json_error("Password must be at least $minLen characters long");

    $exists = $mysqli->prepare('SELECT id FROM users WHERE username = ?');
    $exists->bind_param('s', $username);
    $exists->execute();
    if ($exists->get_result()->fetch_assoc()) json_error('That username is already taken', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $email = $d['email'] ?? null; $contact = $d['contact'] ?? null;
    $role = $d['role'] ?? 'Desk Officer'; $status = $d['status'] ?? 'Active';

    $stmt = $mysqli->prepare('INSERT INTO users (username, password, full_name, email, contact_no, role, status, password_changed_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $stmt->bind_param('sssssss', $username, $hash, $fullName, $email, $contact, $role, $status);
    $stmt->execute();
    $newId = $mysqli->insert_id;

    logAudit($mysqli, 'Created', 'Users', "New account created: $username ($role)");
    json_response(['ok' => true, 'id' => $newId], 201);
}

if ($action === 'update' && $method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('id required');
    $d = body();
    $fullName = trim($d['name'] ?? ''); $email = $d['email'] ?? null; $contact = $d['contact'] ?? null;
    $role = $d['role'] ?? 'Desk Officer'; $status = $d['status'] ?? 'Active';
    if ($fullName === '') json_error('Name is required');

    if (!empty($d['password'])) {
        $minLen = getSecuritySettings()['min_password_length'];
        if (strlen($d['password']) < $minLen) json_error("Password must be at least $minLen characters long");
        $hash = password_hash($d['password'], PASSWORD_BCRYPT);
        $stmt = $mysqli->prepare('UPDATE users SET full_name=?, email=?, contact_no=?, role=?, status=?, password=?, password_changed_at=NOW(), failed_attempts=0, locked_until=NULL WHERE id=?');
        $stmt->bind_param('ssssssi', $fullName, $email, $contact, $role, $status, $hash, $id);
    } else {
        $stmt = $mysqli->prepare('UPDATE users SET full_name=?, email=?, contact_no=?, role=?, status=? WHERE id=?');
        $stmt->bind_param('sssssi', $fullName, $email, $contact, $role, $status, $id);
    }
    $stmt->execute();

    logAudit($mysqli, 'Updated', 'Users', "Account updated: $fullName");
    json_response(['ok' => true]);
}

if ($action === 'toggle_status' && $method === 'POST') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('id required');
    $row = $mysqli->prepare('SELECT username, status FROM users WHERE id = ?');
    $row->bind_param('i', $id); $row->execute();
    $user = $row->get_result()->fetch_assoc();
    if (!$user) json_error('User not found', 404);

    $newStatus = $user['status'] === 'Active' ? 'Suspended' : 'Active';
    $stmt = $mysqli->prepare('UPDATE users SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $newStatus, $id);
    $stmt->execute();

    logAudit($mysqli, 'Updated', 'Users', "{$user['username']} set to $newStatus");
    json_response(['ok' => true, 'status' => $newStatus]);
}

if ($action === 'delete' && $method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('id required');
    if ((int)($_SESSION['user_id'] ?? 0) === $id) json_error('You cannot delete your own account while logged in');

    $row = $mysqli->prepare('SELECT username FROM users WHERE id = ?');
    $row->bind_param('i', $id); $row->execute();
    $user = $row->get_result()->fetch_assoc();

    $stmt = $mysqli->prepare('DELETE FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    if ($user) logAudit($mysqli, 'Deleted', 'Users', "Account removed: {$user['username']}");
    json_response(['ok' => true]);
}

if ($action === 'upload_signature' && $method === 'POST') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('id required');
    if (empty($_FILES['signature']) || $_FILES['signature']['error'] !== UPLOAD_ERR_OK) {
        json_error('No signature file uploaded, or upload failed');
    }

    $row = $mysqli->prepare('SELECT username, role, signature_path FROM users WHERE id = ?');
    $row->bind_param('i', $id); $row->execute();
    $user = $row->get_result()->fetch_assoc();
    if (!$user) json_error('User not found', 404);

    $tmpPath = $_FILES['signature']['tmp_name'];
    $info = @getimagesize($tmpPath);
    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg'])) {
        json_error('Signature must be a PNG or JPEG image');
    }
    if ($_FILES['signature']['size'] > 2 * 1024 * 1024) {
        json_error('Signature image must be smaller than 2MB');
    }

    $SIG_DIR = __DIR__ . '/../assets/signatures';
    if (!is_dir($SIG_DIR)) mkdir($SIG_DIR, 0775, true);

    // Normalize to PNG so transparency is preserved consistently on certificates,
    // regardless of whether the captain uploaded a PNG or JPEG.
    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpeg';
    $filename = 'sig-' . $id . '-' . time() . '.' . $ext;
    $destPath = $SIG_DIR . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        json_error('Could not save the uploaded signature', 500);
    }

    // Remove the old signature file if one existed, now that the new one is saved.
    if (!empty($user['signature_path'])) {
        $oldFile = $SIG_DIR . '/' . basename($user['signature_path']);
        if (is_file($oldFile)) @unlink($oldFile);
    }

    $relativePath = 'assets/signatures/' . $filename;
    $stmt = $mysqli->prepare('UPDATE users SET signature_path = ? WHERE id = ?');
    $stmt->bind_param('si', $relativePath, $id);
    $stmt->execute();

    logAudit($mysqli, 'Updated', 'Users', "Signature uploaded for {$user['username']}");
    json_response(['ok' => true, 'signaturePath' => $relativePath]);
}

if ($action === 'remove_signature' && $method === 'POST') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('id required');

    $row = $mysqli->prepare('SELECT username, signature_path FROM users WHERE id = ?');
    $row->bind_param('i', $id); $row->execute();
    $user = $row->get_result()->fetch_assoc();
    if (!$user) json_error('User not found', 404);

    if (!empty($user['signature_path'])) {
        $file = __DIR__ . '/../' . $user['signature_path'];
        if (is_file($file)) @unlink($file);
    }

    $stmt = $mysqli->prepare('UPDATE users SET signature_path = NULL WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    logAudit($mysqli, 'Updated', 'Users', "Signature removed for {$user['username']}");
    json_response(['ok' => true]);
}

if ($action === 'audit' && $method === 'GET') {
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 10)));
    $stmt = $mysqli->prepare('SELECT username, action, module, details, created_at FROM audit_logs ORDER BY id DESC LIMIT ?');
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    json_response($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

json_error('Unknown action or method', 404);

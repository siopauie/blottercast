<?php
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$mysqli = db();

if ($action === 'public_config') {
    $url = getEnvVal('NEXT_PUBLIC_SUPABASE_URL') ?: (getEnvVal('SUPABASE_URL') ?: 'https://fzvepwddggfendczjecg.supabase.co');
    $anonKey = getEnvVal('NEXT_PUBLIC_SUPABASE_ANON_KEY') ?: (getEnvVal('SUPABASE_ANON_KEY') ?: (getEnvVal('SUPABASE_PUBLISHABLE_KEY') ?: ''));
    json_response([
        'supabase_url' => $url,
        'supabase_anon_key' => $anonKey
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $in = body();
    $username = trim($in['username'] ?? '');
    $password = $in['password'] ?? '';
    if ($username === '' || $password === '') json_error('Username and password required');

    $settings = getSecuritySettings();

    $stmt = $mysqli->prepare('SELECT id, username, password, full_name, role, status, failed_attempts, locked_until, password_changed_at FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    // Account Lockout After Failed Attempts: block the login attempt
    // entirely while locked_until is still in the future, without even
    // checking the password — that's the whole point of a lockout.
    if ($user && $settings['lockout_enabled'] && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $minutesLeft = max(1, ceil((strtotime($user['locked_until']) - time()) / 60));
        json_error("This account is locked due to too many failed login attempts. Try again in $minutesLeft minute(s), or contact an administrator.", 403);
    }

    if (!$user || !password_verify($password, $user['password'])) {
        // Only track failed attempts against a real account — an unknown
        // username shouldn't let an attacker learn whether it exists by
        // watching a lockout counter.
        if ($user && $settings['lockout_enabled']) {
            $attempts = (int)$user['failed_attempts'] + 1;
            if ($attempts >= $settings['max_failed_logins']) {
                $lockUntil = date('Y-m-d H:i:s', time() + 15 * 60); // 15-minute lockout window
                $upd = $mysqli->prepare('UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?');
                $upd->bind_param('si', $lockUntil, $user['id']);
                $upd->execute();
                $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Locked', 'System', 'Account locked after too many failed login attempts')");
                $log->bind_param('s', $user['username']);
                $log->execute();
                json_error('Too many failed login attempts. This account has been locked for 15 minutes.', 403);
            }
            $upd = $mysqli->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?');
            $upd->bind_param('ii', $attempts, $user['id']);
            $upd->execute();
        }
        json_error('Invalid username or password', 401);
    }
    if ($user['status'] !== 'Active') {
        json_error('This account is ' . strtolower($user['status']) . '. Contact an administrator.', 403);
    }

    // Correct password: clear any lockout state.
    $clear = $mysqli->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?');
    $clear->bind_param('i', $user['id']);
    $clear->execute();

    // Password Expiry (days): flag it so the frontend can force a change
    // before letting the user do anything else. Still let them into a
    // session (they proved they know the current password) rather than
    // locking them out with no self-service way back in.
    $mustChangePassword = false;
    if ($settings['password_expiry_days'] > 0 && !empty($user['password_changed_at'])) {
        $ageDays = (time() - strtotime($user['password_changed_at'])) / 86400;
        $mustChangePassword = $ageDays > $settings['password_expiry_days'];
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['must_change_password'] = $mustChangePassword;
    $_SESSION['last_activity'] = time();

    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Login', 'System', 'Successful login')");
    $log->bind_param('s', $user['username']);
    $log->execute();

    json_response(['ok' => true, 'user' => [
        'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'],
        'mustChangePassword' => $mustChangePassword,
    ]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'google_login') {
    $in = body();
    $email = trim($in['email'] ?? '');
    $fullName = trim($in['full_name'] ?? '');
    if ($email === '') json_error('Email is required from Google account');

    // Look up existing user by email
    $stmt = $mysqli->prepare('SELECT id, username, full_name, email, role, status FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    // Fallback: check if username matches email prefix (e.g. admin@... -> admin)
    if (!$user) {
        $prefix = explode('@', $email)[0];
        $stmt2 = $mysqli->prepare('SELECT id, username, full_name, email, role, status FROM users WHERE LOWER(username) = LOWER(?)');
        $stmt2->bind_param('s', $prefix);
        $stmt2->execute();
        $user = $stmt2->get_result()->fetch_assoc();
    }

    // No account found — only an admin can create accounts
    if (!$user) {
        json_error('No BlotterCast account is linked to this Google email (' . $email . '). Please ask your System Admin to create an account for you first.', 403);
    }

    if ($user['status'] !== 'Active') {
        json_error('This account is ' . strtolower($user['status']) . '. Contact an administrator.', 403);
    }

    // Clear any lockout state on successful Google login
    $clear = $mysqli->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?');
    $clear->bind_param('i', $user['id']);
    $clear->execute();

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['must_change_password'] = false;
    $_SESSION['last_activity'] = time();

    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Login', 'System', 'Google OAuth login successful')");
    $log->bind_param('s', $user['username']);
    $log->execute();

    json_response(['ok' => true, 'user' => [
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'mustChangePassword' => false
    ]]);
}

if ($action === 'logout') {
    if (!empty($_SESSION['username'])) {
        $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Logout', 'System', 'User logged out')");
        $log->bind_param('s', $_SESSION['username']);
        $log->execute();
    }
    $_SESSION = [];
    session_destroy();
    json_response(['ok' => true]);
}

if ($action === 'me') {
    if (empty($_SESSION['user_id'])) json_response(['authenticated' => false]);

    // Same idle-timeout check as require_login() in config.php — 'me' is
    // called on every page load via requireAuth(), so this is what
    // actually catches an abandoned tab and forces it back to login.html.
    $settings = getSecuritySettings();
    $timeoutSeconds = $settings['session_timeout'] * 60;
    if ($timeoutSeconds > 0 && !empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeoutSeconds) {
        $_SESSION = [];
        session_destroy();
        json_response(['authenticated' => false]);
    }
    $_SESSION['last_activity'] = time();

    json_response(['authenticated' => true, 'user' => [
        'full_name' => $_SESSION['full_name'], 'role' => $_SESSION['role'],
        'mustChangePassword' => !empty($_SESSION['must_change_password']),
    ]]);
}

// Self-service password change — used both for the voluntary "change my
// password" flow and the mandatory one triggered by Password Expiry
// (days). Requires the current password (proves it's really the account
// owner, not just an unattended open session) and enforces Minimum
// Password Length from the same Security settings the rest of auth.php
// reads from.
if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    $d = body();
    $currentPassword = $d['currentPassword'] ?? '';
    $newPassword = $d['newPassword'] ?? '';
    if ($currentPassword === '' || $newPassword === '') json_error('Current and new password are both required');

    $stmt = $mysqli->prepare('SELECT id, username, password FROM users WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || !password_verify($currentPassword, $user['password'])) {
        json_error('Current password is incorrect', 401);
    }

    $settings = getSecuritySettings();
    if (strlen($newPassword) < $settings['min_password_length']) {
        json_error("New password must be at least {$settings['min_password_length']} characters long");
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $upd = $mysqli->prepare('UPDATE users SET password = ?, password_changed_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?');
    $upd->bind_param('si', $hash, $user['id']);
    $upd->execute();

    $_SESSION['must_change_password'] = false;

    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Updated', 'System', 'Password changed')");
    $log->bind_param('s', $user['username']);
    $log->execute();

    json_response(['ok' => true]);
}

// ── Forgot Password: Step 1 - Lookup Email by Username/Email ──
if ($action === 'lookup_reset_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = body();
    $identity = trim($in['identity'] ?? '');
    if ($identity === '') json_error('Username or email is required');

    $stmt = $mysqli->prepare('SELECT id, username, email, full_name, status FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)');
    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || empty($user['email'])) {
        json_error('No account with a registered email found. Please contact your administrator.', 404);
    }
    if ($user['status'] !== 'Active') {
        json_error('This account is ' . strtolower($user['status']) . '. Contact an administrator.', 403);
    }

    $em = $user['email'];
    $parts = explode('@', $em);
    $namePart = $parts[0];
    $domainPart = $parts[1] ?? '';
    $maskedName = strlen($namePart) > 2 ? substr($namePart, 0, 1) . str_repeat('*', max(3, strlen($namePart) - 2)) . substr($namePart, -1) : substr($namePart, 0, 1) . '***';
    $maskedEmail = $maskedName . '@' . $domainPart;

    json_response([
        'ok' => true,
        'email' => $user['email'],
        'masked_email' => $maskedEmail,
        'full_name' => $user['full_name']
    ]);
}

// ── Forgot Password: Step 2 - Verify & Reset Password ──
if ($action === 'reset_password_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = body();
    $email = trim($in['email'] ?? '');
    $newPassword = $in['newPassword'] ?? '';
    if ($email === '' || $newPassword === '') json_error('Email and new password are required');

    $stmt = $mysqli->prepare('SELECT id, username, full_name, status FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        json_error('Account not found for email: ' . $email, 404);
    }
    if ($user['status'] !== 'Active') {
        json_error('This account is ' . strtolower($user['status']) . '. Contact an administrator.', 403);
    }

    $settings = getSecuritySettings();
    if (strlen($newPassword) < $settings['min_password_length']) {
        json_error("Password must be at least {$settings['min_password_length']} characters long");
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $upd = $mysqli->prepare('UPDATE users SET password = ?, password_changed_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?');
    $upd->bind_param('si', $hash, $user['id']);
    $upd->execute();

    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Reset', 'System', 'Password reset successfully via OTP')");
    $log->bind_param('s', $user['username']);
    $log->execute();

    json_response(['ok' => true, 'message' => 'Password reset successfully']);
}

json_error('Unknown action', 404);

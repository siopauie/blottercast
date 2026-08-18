<?php
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

if ($action === 'public_config') {
    $url = getEnvVal('NEXT_PUBLIC_SUPABASE_URL') ?: (getEnvVal('SUPABASE_URL') ?: 'https://fzvepwddggfendczjecg.supabase.co');
    $anonKey = getEnvVal('NEXT_PUBLIC_SUPABASE_ANON_KEY') ?: (getEnvVal('SUPABASE_ANON_KEY') ?: (getEnvVal('SUPABASE_PUBLISHABLE_KEY') ?: ''));
    json_response([
        'supabase_url' => $url,
        'supabase_anon_key' => $anonKey
    ]);
}

if ($action === 'public_stats' || $action === 'health' || $action === 'ping') {
    $mysqli = db(true);
    $systemStatus = $mysqli ? 'Online' : 'Offline';
    $blotterCount = 0;
    $incidentCount = 0;
    $zonesCount = 0;
    $accuracy = '—';
    $lastModelTrain = 'No training runs yet';
    $riskZone = 'Zone 1';
    $riskLevel = 'Low Risk';

    if ($mysqli) {
        // 1. Blotter Records Count (live from blotter_records table)
        try {
            $blotterRes = $mysqli->query("SELECT COUNT(*) AS total FROM blotter_records");
            if ($blotterRes && ($row = $blotterRes->fetch_assoc())) {
                $blotterCount = (int)($row['total'] ?? 0);
            }
        } catch (\Throwable $e) {}

        // 2. Incident Records Count
        try {
            $incRes = $mysqli->query("SELECT COUNT(*) AS total FROM incidents");
            if ($incRes && ($row = $incRes->fetch_assoc())) {
                $incidentCount = (int)($row['total'] ?? 0);
            }
        } catch (\Throwable $e) {}

        // 3. Monitored Zones Count (dynamic count of active monitored zones)
        try {
            $zonesRes = $mysqli->query("SELECT COUNT(*) AS total FROM zones");
            if ($zonesRes && ($row = $zonesRes->fetch_assoc())) {
                $cnt = (int)($row['total'] ?? 0);
                if ($cnt > 0) $zonesCount = $cnt;
            }
        } catch (\Throwable $e) {}

        if ($zonesCount === 0) {
            try {
                $dZoneRes = $mysqli->query("SELECT COUNT(DISTINCT zone_id) AS total FROM incidents WHERE zone_id IS NOT NULL AND TRIM(zone_id) != ''");
                if ($dZoneRes && ($row = $dZoneRes->fetch_assoc())) {
                    $cnt = (int)($row['total'] ?? 0);
                    if ($cnt > 0) $zonesCount = $cnt;
                }
            } catch (\Throwable $e) {}
        }

        // 4. ML Run Metrics (Test Accuracy, Timestamp, Hotspot Risk Alerts)
        $mlFound = false;
        try {
            $mlRes = $mysqli->query("SELECT * FROM ml_runs ORDER BY id DESC LIMIT 1");
            if ($mlRes && ($mlRow = $mlRes->fetch_assoc())) {
                $mlFound = true;
                
                // Real timestamp from latest ML training run
                $ts = $mlRow['trained_at'] ?? ($mlRow['created_at'] ?? null);
                if (!empty($ts)) {
                    $timeVal = strtotime($ts);
                    if ($timeVal !== false && $timeVal > 0) {
                        $lastModelTrain = date('M j, Y', $timeVal);
                    }
                }

                // Live test accuracy metric from latest active model artifact/metadata
                $activeOcc = $mlRow['active_occurrence_model'] ?? 'random_forest';
                $occMetrics = !empty($mlRow['occurrence_metrics_json']) ? json_decode($mlRow['occurrence_metrics_json'], true) : [];
                $accFound = null;

                if (is_array($occMetrics)) {
                    if (isset($occMetrics[$activeOcc]['accuracy'])) {
                        $accFound = $occMetrics[$activeOcc]['accuracy'];
                    } elseif (isset($occMetrics[strtolower($activeOcc)]['accuracy'])) {
                        $accFound = $occMetrics[strtolower($activeOcc)]['accuracy'];
                    } elseif (isset($occMetrics['random_forest']['accuracy'])) {
                        $accFound = $occMetrics['random_forest']['accuracy'];
                    } elseif (isset($occMetrics['RandomForest']['accuracy'])) {
                        $accFound = $occMetrics['RandomForest']['accuracy'];
                    } else {
                        foreach ($occMetrics as $mKey => $mData) {
                            if (is_array($mData) && isset($mData['accuracy'])) {
                                $accFound = $mData['accuracy'];
                                break;
                            }
                        }
                    }
                }

                if ($accFound === null && !empty($mlRow['hotspot_metrics_json'])) {
                    $hotMetrics = json_decode($mlRow['hotspot_metrics_json'], true);
                    if (is_array($hotMetrics)) {
                        foreach ($hotMetrics as $mData) {
                            if (is_array($mData) && isset($mData['accuracy'])) {
                                $accFound = $mData['accuracy'];
                                break;
                            }
                        }
                    }
                }

                if ($accFound !== null) {
                    $accNum = (float)$accFound;
                    $accuracy = round($accNum <= 1.0 ? $accNum * 100 : $accNum, 1) . '%';
                }

                // Risk Alert: Fetch the actual highest-risk zone and its calculated level
                if (!empty($mlRow['hotspots_json'])) {
                    $hotspots = json_decode($mlRow['hotspots_json'], true);
                    if (is_array($hotspots) && !empty($hotspots)) {
                        usort($hotspots, function($a, $b) {
                            return ($b['meanDailyProb'] ?? 0) <=> ($a['meanDailyProb'] ?? 0);
                        });
                        $top = $hotspots[0];
                        $zRaw = trim((string)($top['zone'] ?? '1'));
                        $riskZone = (is_numeric($zRaw) || stripos($zRaw, 'zone') === false) ? ('Zone ' . $zRaw) : $zRaw;
                        $p = (float)($top['meanDailyProb'] ?? 0);
                        $riskLevel = $p >= 0.20 ? 'High Risk' : ($p >= 0.13 ? 'Moderate Risk' : 'Low Risk');
                    }
                }
            }
        } catch (\Throwable $e) {}

        // Fallback for Risk Alert from incident density if ML run not yet performed
        if (!$mlFound) {
            try {
                $topZoneRes = $mysqli->query("SELECT zone_id, COUNT(*) AS cnt FROM incidents WHERE zone_id IS NOT NULL AND TRIM(zone_id) != '' GROUP BY zone_id ORDER BY cnt DESC LIMIT 1");
                if ($topZoneRes && ($tzRow = $topZoneRes->fetch_assoc())) {
                    $zRaw = trim((string)$tzRow['zone_id']);
                    $riskZone = (is_numeric($zRaw) || stripos($zRaw, 'zone') === false) ? ('Zone ' . $zRaw) : $zRaw;
                    $cnt = (int)$tzRow['cnt'];
                    $riskLevel = $cnt >= 10 ? 'High Risk' : ($cnt >= 5 ? 'Moderate Risk' : 'Low Risk');
                }
            } catch (\Throwable $e) {}
        }
    }

    json_response([
        'ok' => ($systemStatus === 'Online'),
        'system_status' => $systemStatus,
        'blotter_count' => $blotterCount,
        'incident_count' => $incidentCount,
        'total_records' => $blotterCount + $incidentCount,
        'ml_accuracy' => $accuracy,
        'zones_count' => $zonesCount,
        'risk_zone' => $riskZone,
        'risk_level' => $riskLevel,
        'last_model_train' => $lastModelTrain
    ]);
}

$mysqli = db();

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

    // Correct password: clear any lockout state and update last_login.
    $now = date('Y-m-d H:i:s');
    $clear = $mysqli->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = ? WHERE id = ?');
    $clear->bind_param('si', $now, $user['id']);
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

    // Clear any lockout state on successful Google login and record last_login
    $now = date('Y-m-d H:i:s');
    $clear = $mysqli->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = ? WHERE id = ?');
    $clear->bind_param('si', $now, $user['id']);
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
    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Updated', 'System', 'Password changed')");
    $log->bind_param('s', $user['username']);
    $log->execute();

    json_response(['ok' => true]);
}

/**
 * Send an email directly via Brevo (Sendinblue) REST API
 */
function sendBrevoEmail(string $toEmail, string $toName, string $subject, string $htmlContent): array {
    $apiKey = getEnvVal('BREVO_API_KEY') ?: '';
    if (empty($apiKey)) {
        return [
            'ok' => false,
            'error' => 'BREVO_API_KEY is not configured in Vercel environment variables. Please add your Brevo API key.'
        ];
    }

    $senderEmail = getEnvVal('BREVO_SENDER_EMAIL') ?: 'fhalynramos4@gmail.com';
    $senderName = getEnvVal('BREVO_SENDER_NAME') ?: 'BlotterCast Security';

    $payload = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail,
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => !empty($toName) ? $toName : 'Barangay Staff',
            ],
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'error' => 'Curl network error: ' . $curlErr];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'response' => json_decode($response, true)];
    }

    $errJson = json_decode($response, true);
    $msg = $errJson['message'] ?? "Brevo API responded with error code $httpCode";
    return ['ok' => false, 'error' => $msg];
}

// ── Forgot Password: Step 1 - Generate 6-digit OTP & Send via Brevo API ──
if ($action === 'send_reset_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = body();
    $identity = trim($in['identity'] ?? '');
    if ($identity === '') json_error('Username or email is required');

    $stmt = $mysqli->prepare('SELECT id, username, email, full_name, status FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)');
    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || empty($user['email'])) {
        json_error('No account with a registered email found for "' . htmlspecialchars($identity) . '". Please contact an administrator.', 404);
    }
    if ($user['status'] !== 'Active') {
        json_error('This account is ' . strtolower($user['status']) . '. Contact an administrator.', 403);
    }

    // Ensure password_resets table exists
    $mysqli->query("CREATE TABLE IF NOT EXISTS password_resets (
        id SERIAL PRIMARY KEY,
        email VARCHAR(150) NOT NULL,
        otp VARCHAR(10) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Generate 6-digit OTP
    $otp = sprintf('%06d', random_int(100000, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60); // 10 minutes expiry

    // Remove any previous OTPs for this email
    $del = $mysqli->prepare('DELETE FROM password_resets WHERE LOWER(email) = LOWER(?)');
    $del->bind_param('s', $user['email']);
    $del->execute();

    // Insert new OTP
    $ins = $mysqli->prepare('INSERT INTO password_resets (email, otp, expires_at) VALUES (?, ?, ?)');
    $ins->bind_param('sss', $user['email'], $otp, $expiresAt);
    $ins->execute();

    // Mask email for display (e.g. a***s@gmail.com)
    $em = $user['email'];
    $parts = explode('@', $em);
    $namePart = $parts[0];
    $domainPart = $parts[1] ?? '';
    $maskedName = strlen($namePart) > 2 ? substr($namePart, 0, 1) . str_repeat('*', max(3, strlen($namePart) - 2)) . substr($namePart, -1) : substr($namePart, 0, 1) . '***';
    $maskedEmail = $maskedName . '@' . $domainPart;

    // Send email via Brevo API
    $emailHtml = "
    <div style=\"font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 500px; margin: 0 auto; padding: 28px; border: 1px solid #e5e7eb; border-radius: 14px; background: #ffffff; color: #1f2937;\">
      <div style=\"text-align: center; margin-bottom: 20px;\">
        <h2 style=\"color: #1a5c31; margin: 0; font-size: 24px; font-weight: 700;\">BlotterCast</h2>
        <p style=\"color: #6b7280; font-size: 13px; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em;\">Pamahalaang Barangay ng Mapulang Lupa</p>
      </div>
      <hr style=\"border: none; border-top: 1px solid #f3f4f6; margin: 16px 0 20px;\">
      <p style=\"font-size: 15px; margin-bottom: 12px;\">Hello <strong>" . htmlspecialchars($user['full_name']) . "</strong>,</p>
      <p style=\"font-size: 14px; color: #4b5563; line-height: 1.6; margin-bottom: 20px;\">You requested to reset your BlotterCast password. Enter the 6-digit verification code below to set your new password:</p>
      
      <div style=\"text-align: center; margin: 24px 0;\">
        <div style=\"display: inline-block; background: #f0fdf4; border: 2px dashed #4fa868; border-radius: 12px; padding: 14px 32px; font-size: 34px; font-weight: 800; letter-spacing: 8px; color: #1a5c31; font-family: Consolas, monospace;\">
          {$otp}
        </div>
      </div>
      
      <p style=\"font-size: 13px; color: #6b7280; text-align: center; line-height: 1.5;\">
        This verification code expires in <strong>10 minutes</strong>.<br/>
        If you did not request a password reset, you can safely ignore this email.
      </p>
      <hr style=\"border: none; border-top: 1px solid #f3f4f6; margin: 24px 0 14px;\">
      <p style=\"font-size: 11px; color: #9ca3af; text-align: center; margin: 0;\">
        BlotterCast — Official Barangay Records & Intelligence System
      </p>
    </div>";

    $mailResult = sendBrevoEmail(
        $user['email'],
        $user['full_name'],
        'BlotterCast Password Reset Code: ' . $otp,
        $emailHtml
    );

    if (!$mailResult['ok']) {
        json_error($mailResult['error'], 500);
    }

    json_response([
        'ok' => true,
        'masked_email' => $maskedEmail,
        'message' => 'Verification code sent successfully!'
    ]);
}

// ── Forgot Password: Step 2 - Verify OTP Code Only ──
if ($action === 'verify_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = body();
    $identity = trim($in['identity'] ?? '');
    $otp = trim($in['otp'] ?? '');

    if ($identity === '' || $otp === '') {
        json_error('Username/email and 6-digit verification code are required');
    }

    // Lookup user
    $stmt = $mysqli->prepare('SELECT id, username, email, full_name, status FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)');
    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        json_error('Account not found', 404);
    }
    if ($user['status'] !== 'Active') {
        json_error('This account is ' . strtolower($user['status']) . '. Contact an administrator.', 403);
    }

    // Verify OTP against password_resets table
    $vStmt = $mysqli->prepare('SELECT id FROM password_resets WHERE LOWER(email) = LOWER(?) AND otp = ? AND expires_at > NOW()');
    $vStmt->bind_param('ss', $user['email'], $otp);
    $vStmt->execute();
    $resetRecord = $vStmt->get_result()->fetch_assoc();

    if (!$resetRecord) {
        json_error('Invalid or expired verification code. Please check your code or request a new one.', 400);
    }

    // Generate temporary reset token valid for 15 minutes
    $resetToken = bin2hex(random_bytes(24));
    $updToken = $mysqli->prepare('UPDATE password_resets SET otp = ?, expires_at = ? WHERE id = ?');
    $newExpiry = date('Y-m-d H:i:s', time() + 15 * 60);
    $updToken->bind_param('ssi', $resetToken, $newExpiry, $resetRecord['id']);
    $updToken->execute();

    json_response([
        'ok' => true,
        'reset_token' => $resetToken,
        'message' => 'Verification code confirmed!'
    ]);
}

// ── Forgot Password: Step 3 - Set New Password ──
if ($action === 'set_new_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = body();
    $identity = trim($in['identity'] ?? '');
    $resetToken = trim($in['reset_token'] ?? '');
    $newPassword = $in['newPassword'] ?? '';

    if ($identity === '' || $resetToken === '' || $newPassword === '') {
        json_error('Invalid request. Please complete the verification step first.');
    }

    // Lookup user
    $stmt = $mysqli->prepare('SELECT id, username, email, full_name, status FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)');
    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        json_error('Account not found', 404);
    }

    // Validate reset token
    $vStmt = $mysqli->prepare('SELECT id FROM password_resets WHERE LOWER(email) = LOWER(?) AND otp = ? AND expires_at > NOW()');
    $vStmt->bind_param('ss', $user['email'], $resetToken);
    $vStmt->execute();
    $resetRecord = $vStmt->get_result()->fetch_assoc();

    if (!$resetRecord) {
        json_error('Your verification session has expired. Please request a new code.', 400);
    }

    $settings = getSecuritySettings();
    if (strlen($newPassword) < $settings['min_password_length']) {
        json_error("New password must be at least {$settings['min_password_length']} characters long");
    }

    // Update password with bcrypt hash and unlock account
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $upd = $mysqli->prepare('UPDATE users SET password = ?, password_changed_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?');
    $upd->bind_param('si', $hash, $user['id']);
    $upd->execute();

    // Delete used reset record
    $del = $mysqli->prepare('DELETE FROM password_resets WHERE LOWER(email) = LOWER(?)');
    $del->bind_param('s', $user['email']);
    $del->execute();

    $log = $mysqli->prepare("INSERT INTO audit_logs (username, action, module, details) VALUES (?, 'Reset', 'System', 'Password reset successfully via Brevo OTP')");
    $log->bind_param('s', $user['username']);
    $log->execute();

    json_response(['ok' => true, 'message' => 'Password reset successfully!']);
}

json_error('Unknown action', 404);

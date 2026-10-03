<?php
/**
 * login.php — Centralized Login Page
 * 
 * Every page links here:  <a href="login.php">Login</a>
 * Optional redirect back:  <a href="login.php?redirect=houses.php">Login</a>
 * 
 * ✨ Device-based OTP verification for new devices
 * ✨ IP-based rate limiting (5 attempts per device/IP)
 */

session_start();
require_once 'database.php';

// Auth helpers
require_once 'includes/auth.php';

// ✅ Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Rate limiter
require_once 'includes/LoginRateLimiter.php';
$rateLimiter = new LoginRateLimiter($pdo);

// Terms gate
require_once 'includes/TermsGate.php';
$termsGate = new TermsGate($pdo);

// Email for device OTP
require_once 'config/mail_config.php';

// ── Helper: real client IP ──
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ── Password helpers ──
if (!function_exists('isPasswordHashed')) {
    function isPasswordHashed(string $pwd): bool {
        return (bool) preg_match('/^\$2[aby]\$/', $pwd) || strpos($pwd, '$argon') === 0;
    }
}
if (!function_exists('isPasswordCompliant')) {
    function isPasswordCompliant(string $pwd): bool {
        return strlen($pwd) >= 8 && preg_match('/[A-Z]/', $pwd) && preg_match('/[0-9]/', $pwd);
    }
}

// ── Helper: auto-detect cookie path for subfolder installs ──
if (!function_exists('detectCookiePath')) {
    function detectCookiePath(): string {
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $script_dir = str_replace('\\', '/', $script_dir);
        if ($script_dir === '' || $script_dir === '/' || $script_dir === '.') {
            return '/';
        }
        return rtrim($script_dir, '/') . '/';
    }
}

// ── Helper: check if device is trusted ──
if (!function_exists('isDeviceTrusted')) {
    function isDeviceTrusted(PDO $pdo, int $user_id): bool {
        $token = $_COOKIE['trusted_device'] ?? '';
        if (empty($token)) return false;

        try {
            $stmt = $pdo->prepare("
                SELECT id FROM trusted_devices 
                WHERE user_id = ? 
                  AND device_token = ? 
                  AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$user_id, $token]);
            $found = $stmt->fetch();

            if ($found) {
                $upd = $pdo->prepare("UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?");
                $upd->execute([$found['id']]);
                return true;
            }
        } catch (PDOException $e) {
            error_log("isDeviceTrusted error: " . $e->getMessage());
        }
        return false;
    }
}

// ── Helper: create trusted device ──
if (!function_exists('createTrustedDevice')) {
    function createTrustedDevice(PDO $pdo, int $user_id): void {
        try {
            $token = bin2hex(random_bytes(32));
            $ua    = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';
            $ip    = getClientIp();
            $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

            $stmt = $pdo->prepare("
                INSERT INTO trusted_devices 
                (user_id, device_token, device_name, user_agent, ip_address, last_used_at, expires_at)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$user_id, $token, $ua, $ua, $ip, $expires_at]);

            setcookie('trusted_device', $token, [
                'expires'  => time() + (30 * 24 * 60 * 60),
                'path'     => detectCookiePath(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } catch (PDOException $e) {
            error_log("createTrustedDevice error: " . $e->getMessage());
        }
    }
}

// ══════════════════════════════════════════════════════════
// DYNAMIC CONTENT (logo + hero image)
// ══════════════════════════════════════════════════════════
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

$logo_path = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
}
$logo_exists = !empty($logo_path) && file_exists($logo_path) && !is_dir($logo_path);

if (!$logo_exists && file_exists('uploads/logos/logo.png')) {
    $logo_exists = true;
    $logo_path = 'uploads/logos/logo.png';
}

$hero_path = 'uploads/hero/hero-bg.jpg';
if(isset($content['site_settings']['hero_image_path']) && !empty($content['site_settings']['hero_image_path'])) {
    $hero_path = $content['site_settings']['hero_image_path'];
}
$hero_exists = !empty($hero_path) && file_exists($hero_path);

$site_name    = $content['site_settings']['site_name']    ?? 'Transient House & Tours';
$site_tagline = $content['site_settings']['site_tagline'] ?? 'Your Home Away From Home';

// ── If already logged in, go to dashboard ──
redirectIfLoggedIn();

// ── Where to send the user after login? ──
$redirectTo = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_\-\/\.\?=&%]+$/', $redirectTo)) {
    $redirectTo = '';
}

// ── Handle POST ──
$login_error_type    = '';
$login_attempts_left = null;
$error               = '';
$username_posted     = '';
$lockout_remaining   = 0;

if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = getClientIp();

    // ✅ IP-based rate limiting — lockout applies to THIS device/IP only.
    // This lets the same account log in from other devices/IPs.
    $rateKey  = $ip;

    $username_posted = $username;

    $lockStatus = $rateLimiter->check($rateKey, $ip);

    if (!empty($lockStatus['locked'])) {
        $error             = $lockStatus['message'] ?? 'Too many failed attempts. Please try again later.';
        $lockout_remaining = $lockStatus['remaining'] ?? 0;
        $login_error_type  = 'lockout';

        // ✅ Normalize countdown to match the actual lockout duration (in seconds)
        if ($lockout_remaining <= 0) {
            // If rate limiter didn't give us a specific value, fall back to 5 minutes
            $lockout_remaining = 300;
        } else {
            // If it's given in minutes (heuristic: < 60), convert to seconds
            if ($lockout_remaining < 60) {
                $lockout_remaining = $lockout_remaining * 60;
            }
        }

        // ✅ Log lockout event
        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'login_failed',
                'auth',
                "Login blocked — device locked for IP '{$ip}' (username attempted: '{$username}')",
                null,
                'user',
                null,
                ['username' => $username, 'ip' => $ip, 'reason' => 'lockout', 'remaining' => $lockout_remaining],
                'failed'
            );
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $authOk = false;

        if ($user) {
            $stored = $user['password'];

            if (isPasswordHashed($stored)) {
                if (password_verify($password, $stored)) {
                    $authOk = true;
                    if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $upd->execute([$newHash, $user['id']]);
                    }
                }
            } else {
                if (hash_equals((string)$stored, (string)$password) && isPasswordCompliant($password)) {
                    $authOk = true;
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->execute([$newHash, $user['id']]);
                } elseif (hash_equals((string)$stored, (string)$password)) {
                    $error = "Your password is out of date. It must be at least 8 characters "
                           . "with 1 uppercase letter and 1 number. Please use Forgot Password.";
                    $login_error_type = 'wrong';

                    // ✅ Log outdated password
                    if (class_exists('SystemLogger')) {
                        SystemLogger::log(
                            $pdo,
                            'login_failed',
                            'auth',
                            "Login failed (outdated password) for username '{$username}'",
                            (int)$user['id'],
                            'user',
                            null,
                            ['username' => $username, 'ip' => $ip, 'reason' => 'outdated_password'],
                            'failed'
                        );
                    }
                }
            }
        }

        if ($authOk && $user) {
            $rateLimiter->clear($rateKey, $ip);

            $device_trusted = isDeviceTrusted($pdo, (int)$user['id']);

            if ($device_trusted) {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];

                if ($user['role'] === 'guest' && !$termsGate->hasAccepted((int)$user['id'])) {
                    $_SESSION['show_terms_modal'] = true;
                }

                // ✅ Log successful login (trusted device)
                if (class_exists('SystemLogger')) {
                    SystemLogger::log(
                        $pdo,
                        'login',
                        'auth',
                        "User '{$user['username']}' logged in (trusted device)",
                        (int)$user['id'],
                        'user',
                        null,
                        ['role' => $user['role'], 'ip' => $ip, 'device_trusted' => true]
                    );
                }

                if ($redirectTo !== '') {
                    header("Location: " . $redirectTo);
                } else {
                    header("Location: " . dashboardUrlForRole($user['role']));
                }
                exit();
            }

            $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
            $upd->execute([$otp_code, $expires, $user['id']]);

            try {
                $mail = MailConfig::getInstance()->getMailer();
                $mail->clearAddresses();
                $mail->addAddress($user['email'], $user['username']);
                $mail->Subject = '🔐 New Device Login — Transient House & Tours';

                $mail->Body = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; background: #f5f7fa; padding: 20px; }
                        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                        .header { text-align: center; padding-bottom: 20px; border-bottom: 2px solid #e8f0fe; }
                        .header h2 { color: #0B2447; margin: 0; }
                        .otp-box { background: #f0f7fb; padding: 25px; text-align: center; font-size: 36px; font-weight: bold; letter-spacing: 12px; color: #0B2447; border-radius: 10px; margin: 25px 0; border: 2px dashed #4DA6D9; }
                        .info { color: #64748b; font-size: 14px; line-height: 1.6; }
                        .warning { background: #fef3c7; padding: 12px 20px; border-radius: 8px; color: #92400e; font-size: 13px; margin: 20px 0; }
                        .danger { background: #fee2e2; padding: 12px 20px; border-radius: 8px; color: #991b1b; font-size: 13px; margin: 20px 0; }
                        .footer { text-align: center; padding-top: 20px; border-top: 1px solid #e8f0fe; color: #94a3b8; font-size: 12px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h2>🔐 New Device Login Detected</h2>
                        </div>
                        <p class="info">Dear <strong>' . htmlspecialchars($user['username']) . '</strong>,</p>
                        <p class="info">We detected a login from a new device. Use this OTP code to verify:</p>

                        <div class="otp-box">' . $otp_code . '</div>

                        <div class="warning">
                            <strong>⏰ This code will expire in 15 minutes.</strong>
                        </div>

                        <div class="danger">
                            <strong>⚠️ If this wasn\'t you,</strong><br>
                            Someone may have your password. Please change it immediately.
                        </div>

                        <p class="info">Best regards,<br><strong>Transient House & Tours Team</strong></p>

                        <div class="footer">
                            &copy; ' . date('Y') . ' Transient House & Tours. All rights reserved.
                        </div>
                    </div>
                </body>
                </html>
                ';

                $mail->AltBody = "New Device Login\n\nYour OTP code: $otp_code\nExpires in 15 minutes.\n\nIf this wasn't you, change your password immediately.";

                $mail->send();
            } catch (Exception $e) {
                error_log("Device OTP email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
                $error = "❌ We couldn't send the verification code to your email. Please try again.";
                $login_error_type = 'error';

                // ✅ Log email send failure
                if (class_exists('SystemLogger')) {
                    SystemLogger::log(
                        $pdo,
                        'login_failed',
                        'auth',
                        "Failed to send device OTP to '{$user['username']}'",
                        (int)$user['id'],
                        'user',
                        null,
                        ['email' => $user['email'], 'error' => $mail->ErrorInfo ?? $e->getMessage()],
                        'failed'
                    );
                }
            }

            if (empty($error)) {
                $_SESSION['pending_device_verify_user_id']  = (int)$user['id'];
                $_SESSION['pending_device_verify_redirect'] = $redirectTo;
                $_SESSION['pending_device_verify_username'] = $user['username'];
                $_SESSION['pending_device_verify_email']    = $user['email'];

                // ✅ Log new device OTP sent (pending verification)
                if (class_exists('SystemLogger')) {
                    SystemLogger::log(
                        $pdo,
                        'login',
                        'auth',
                        "User '{$user['username']}' logged in from NEW device — OTP sent",
                        (int)$user['id'],
                        'user',
                        null,
                        ['role' => $user['role'], 'ip' => $ip, 'device_trusted' => false, 'pending_otp' => true]
                    );
                }

                header("Location: verify-device.php");
                exit();
            }
        }

        if (!$authOk && $error === '') {
            $result = $rateLimiter->recordFailure($rateKey, $ip);
            $fresh  = $rateLimiter->check($rateKey, $ip);

            if (!empty($result['locked']) || !empty($fresh['locked'])) {
                $error             = $result['message'] ?? $fresh['message'] ?? 'Too many attempts.';
                $lockout_remaining = $result['remaining'] ?? $fresh['remaining'] ?? 0;
                $login_error_type  = 'lockout';

                // ✅ Normalize countdown to match the actual lockout duration
                if ($lockout_remaining <= 0) {
                    $lockout_remaining = 300; // fallback 5 minutes
                } elseif ($lockout_remaining < 60) {
                    // If rate limiter returned minutes, convert to seconds
                    $lockout_remaining = $lockout_remaining * 60;
                }

                // ✅ Log lockout triggered
                if (class_exists('SystemLogger')) {
                    SystemLogger::log(
                        $pdo,
                        'login_failed',
                        'auth',
                        "Login locked out after too many failed attempts for IP '{$ip}' (username attempted: '{$username}')",
                        $user ? (int)$user['id'] : null,
                        'user',
                        null,
                        ['username' => $username, 'ip' => $ip, 'reason' => 'lockout_triggered', 'remaining' => $lockout_remaining],
                        'failed'
                    );
                }
            } else {
                $attemptsLeft  = $fresh['attempts_left'] ?? $result['attempts_left'] ?? null;

                $error = "Incorrect username or password.";
                $login_attempts_left = $attemptsLeft;
                $login_error_type    = 'wrong';

                // ✅ Log failed login attempt
                if (class_exists('SystemLogger')) {
                    SystemLogger::log(
                        $pdo,
                        'login_failed',
                        'auth',
                        "Failed login attempt for username '{$username}' (IP: {$ip})",
                        $user ? (int)$user['id'] : null,
                        'user',
                        null,
                        [
                            'username'      => $username,
                            'ip'            => $ip,
                            'attempts_left' => $attemptsLeft,
                            'reason'        => $user ? 'wrong_password' : 'user_not_found'
                        ],
                        'failed'
                    );
                }
            }
        }
    }
}

$termsContent = $termsGate->getContent();
$termsVersion = $termsGate->getCurrentVersion();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- ✅ Mobile-optimized viewport -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#0B2447">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Login — <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html {
            -webkit-text-size-adjust: 100%;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            background-color: #0B2447;
            overscroll-behavior-y: contain;
            <?php if($hero_exists): ?>
            background-image:
                linear-gradient(135deg, rgba(11, 36, 71, 0.75), rgba(11, 61, 145, 0.65)),
                url('<?php echo htmlspecialchars($hero_path); ?>?<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            <?php else: ?>
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            <?php endif; ?>
        }

        @supports (padding: max(0px)) {
            body {
                padding-left: max(20px, env(safe-area-inset-left));
                padding-right: max(20px, env(safe-area-inset-right));
                padding-top: max(20px, env(safe-area-inset-top));
                padding-bottom: max(20px, env(safe-area-inset-bottom));
            }
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(ellipse at center, transparent 0%, rgba(0,0,0,0.35) 100%);
            pointer-events: none;
            z-index: 0;
        }

        .login-card {
            position: relative;
            z-index: 1;
            background: white;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }

        .login-header {
 background:
    linear-gradient(
        135deg,
        #06263D,
        #0B7CC1
    );            color: white;
    padding:40px 35px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(244, 180, 0, 0.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .login-header .logo-image {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.35);
            padding: 4px;
            background: white;
            margin: 0 auto 15px;
            display: block;
            position: relative;
            z-index: 1;
            box-shadow: 0 8px 25px rgba(0,0,0,0.25);
        }

        .login-header .logo-circle {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 32px;
            color: #F4B400;
            position: relative;
            z-index: 1;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 5px;
            position: relative;
            z-index: 1;
        }

        .login-header p {
            font-size: 13px;
            opacity: 0.85;
            margin: 0;
            position: relative;
            z-index: 1;
        }

        .login-body { padding: 30px; }

        .alert {
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5;
            border-left: 4px solid;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }

        .alert-lockout {
            background: #fef3c7;
            border-color: #f59e0b;
            color: #92400e;
        }

        .alert strong {
            display: block;
            margin-bottom: 3px;
            color: inherit;
            font-weight: 700;
        }

        .alert .countdown {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .form-group { margin-bottom: 15px; }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #0B2447;
        }

        .form-group label i { color: #4DA6D9; margin-right: 5px; }

        .password-wrapper { position: relative; }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            background: #fafafa;
            transition: all 0.2s;
            min-height: 48px;
            -webkit-appearance: none;
            appearance: none;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #4DA6D9;
            background: white;
            box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }

        .form-control::placeholder {
            color: #94a3b8;
            opacity: 1;
        }

        .password-wrapper .form-control {
            padding-right: 52px;
        }

        .toggle-password {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #94a8b8;
            cursor: pointer;
            padding: 10px;
            font-size: 18px;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.2s;
            -webkit-tap-highlight-color: transparent;
        }

        .toggle-password:hover,
        .toggle-password:active {
            color: #4DA6D9;
            background: rgba(77, 166, 217, 0.1);
        }

        .btn-login {
            width: 100%;
            min-height: 48px;
            padding: 14px;
    background:#F4B400;
    color:#0B2447;
            border: none;
    border-radius:10px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }

        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.4);
            background: #e6a800;
        }

        .btn-login:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-login:disabled {
            background: #cbd5e1;
            color: #64748b;
            cursor: not-allowed;
            box-shadow: none;
        }

        .login-links {
            text-align: center;
            margin-top: 18px;
            font-size: 14px;
            color: #64748b;
        }

        .login-links a {
            color: #4DA6D9;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            padding: 4px 2px;
            min-height: 28px;
        }

        .login-links a:hover { text-decoration: underline; }

        .login-links .forgot {
            display: block;
            margin-bottom: 8px;
            padding: 10px;
        }

        .login-links .divider {
            display: block;
            height: 1px;
            background: #e2e8f0;
            margin: 15px 0;
        }

        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            text-decoration: none;
            font-size: 14px;
            margin-top: 5px;
            min-height: 44px;
            padding: 8px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
        }

        .back-home:hover { color: #0B2447; }

        /* Device hint */
        .device-hint {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12.5px;
            color: #1e40af;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.5;
        }

        .device-hint i {
            color: #3b82f6;
            font-size: 15px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        @media (max-width: 480px) {
            body {
                padding: 12px;
                align-items: flex-start;
                padding-top: max(20px, env(safe-area-inset-top));
            }

            .login-card {
                border-radius: 20px;
                margin-top: 0;
            }

            .login-header {
                padding: 28px 20px;
            }

            .login-header h1 {
                font-size: 20px;
            }

            .login-header p {
                font-size: 12px;
            }

            .login-header .logo-image {
                width: 68px;
                height: 68px;
                border-radius: 16px;
            }

            .login-header .logo-circle {
                width: 60px;
                height: 60px;
                font-size: 26px;
                border-radius: 16px;
            }

            .login-body {
                padding: 24px 20px;
            }

            .form-group label {
                font-size: 13px;
            }

            .form-control {
                font-size: 16px;
                padding: 13px 14px;
            }

            .btn-login {
                font-size: 15px;
                padding: 13px;
            }

            .login-links {
                font-size: 13px;
            }

            .device-hint {
                font-size: 12px;
                padding: 10px 12px;
            }
        }

        @media (max-width: 360px) {
            .login-header h1 {
                font-size: 18px;
            }

            .login-body {
                padding: 20px 16px;
            }

            .form-control {
                padding: 12px 12px;
                font-size: 16px;
            }

            .btn-login {
                font-size: 14px;
            }
        }

        @media (max-height: 500px) and (orientation: landscape) {
            body {
                align-items: flex-start;
                padding-top: 15px;
            }

            .login-header {
                padding: 20px;
            }

            .login-header .logo-image,
            .login-header .logo-circle {
                width: 50px;
                height: 50px;
                margin-bottom: 10px;
            }

            .login-header h1 {
                font-size: 18px;
                margin-bottom: 3px;
            }

            .login-body {
                padding: 20px;
            }
        }

        @media (hover: none) and (pointer: coarse) {
            .btn-login {
                min-height: 52px;
            }

            .form-control {
                min-height: 50px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* =====================================
   LOGIN CARD SIZE ADJUSTMENT
   ===================================== */

.login-card {

    width:100% !important;

    max-width:390px !important;

    border-radius:24px !important;

}


/* Header */

.login-header {

    padding:30px 25px !important;

}


.login-header .logo-image {

    width:70px !important;
    height:70px !important;

}


.login-header h1 {

    font-size:23px !important;

}



/* Body */

.login-body {

    padding:25px !important;

}


/* Inputs */

.form-control {

    padding:12px 14px !important;

    min-height:46px !important;

}


/* Button */

.btn-login {

    min-height:48px !important;

    padding:12px !important;

}


/* Security notice */

.device-hint {

    padding:10px 12px !important;

    font-size:12px !important;

}

/* =====================================
   LOGIN FULL SCREEN NO SCROLL
   ===================================== */

html,
body {
    height:100%;
    overflow:hidden;
}


body {
    padding:10px !important;
}


.login-card {

    max-width:390px !important;

    max-height:calc(100vh - 20px);

    display:flex;

    flex-direction:column;

}


/* Header tighter */

.login-header {

    padding:22px 25px !important;

}


.login-header .logo-image {

    width:60px !important;
    height:60px !important;

    margin-bottom:8px !important;
}


.login-header h1 {

    font-size:21px !important;

    margin-bottom:3px !important;
}



/* Content */

.login-body {

    padding:18px 22px !important;

}



/* Reduce spacing */

.device-hint {

    margin-bottom:12px !important;

}


.form-group {

    margin-bottom:10px !important;

}


.form-control {

    min-height:42px !important;

    padding:10px 12px !important;

}


.btn-login {

    min-height:44px !important;

}


/* Links */

.login-links {

    margin-top:10px !important;

}


.login-links .forgot {

    padding:6px !important;

}
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <?php if($logo_exists): ?>
            <img src="<?php echo htmlspecialchars($logo_path); ?>?<?php echo time(); ?>"
                 alt="<?php echo htmlspecialchars($site_name); ?>"
                 class="logo-image">
        <?php else: ?>
            <div class="logo-circle">
                <i class="fas fa-umbrella-beach"></i>
            </div>
        <?php endif; ?>

        <h1>Welcome Back</h1>
        <p><?php echo htmlspecialchars($site_name); ?></p>
    </div>

    <div class="login-body">
        <?php if ($error !== ''): ?>
            <div class="alert alert-<?php echo $login_error_type === 'lockout' ? 'lockout' : 'error'; ?>">
                <?php if ($login_error_type === 'lockout'): ?>
                    <strong><i class="fas fa-lock"></i> Device temporarily locked</strong>
                    <?php echo htmlspecialchars($error); ?>
                    <?php if (!empty($lockout_remaining)): ?>
                        <br>Try again in <span class="countdown" id="lockoutCountdown" data-seconds="<?php echo (int)$lockout_remaining; ?>"><?php
                            $m = floor($lockout_remaining / 60);
                            $s = $lockout_remaining % 60;
                            echo ($m > 0 ? $m . 'm ' : '') . $s . 's';
                        ?></span>.
                    <?php endif; ?>
                <?php else: ?>
                    <strong><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></strong>
                    <?php if ($login_attempts_left !== null): ?>
                        <?php if ($login_attempts_left <= 2): ?>
                            <span style="color: #b91c1c; font-weight: 700;">
                                ⚠️ <?php echo $login_attempts_left; ?> attempt<?php echo $login_attempts_left !== 1 ? 's' : ''; ?> left before lockout.
                            </span>
                        <?php else: ?>
                            <strong><?php echo $login_attempts_left; ?></strong> attempt<?php echo $login_attempts_left !== 1 ? 's' : ''; ?> remaining.
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Device security notice -->
        <div class="device-hint">
            <i class="fas fa-shield-alt"></i>
            <span>New device? We'll send a one-time verification code to your email for security.</span>
        </div>

        <form method="POST" id="loginForm" autocomplete="on">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectTo); ?>">

            <div class="form-group">
                <label for="username"><i class="fas fa-user"></i> Username</label>
                <input type="text"
                       name="username"
                       id="username"
                       class="form-control"
                       placeholder="Enter your username"
                       required
                       autofocus
                       autocomplete="username"
                       autocorrect="off"
                       autocapitalize="off"
                       spellcheck="false"
                       value="<?php echo htmlspecialchars($username_posted); ?>">
            </div>

            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <div class="password-wrapper">
                    <input type="password"
                           name="password"
                           id="password"
                           class="form-control"
                           placeholder="Enter your password"
                           required
                           autocomplete="current-password">
                    <button type="button"
                            class="toggle-password"
                            onclick="togglePassword()"
                            id="togglePasswordBtn"
                            aria-label="Show password">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" name="login" class="btn-login" id="loginBtn">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        <div class="login-links">
            <a href="forgot-password.php" class="forgot">
                <i class="fas fa-key"></i> Forgot your password?
            </a>
            <span class="divider"></span>
            Don't have an account?
            <a href="register.php">Register here</a>
            <br><br>
            <a href="index.php" class="back-home">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
    </div>
</div>

<script>
// Password visibility toggle
function togglePassword() {
    var input = document.getElementById('password');
    var icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// ✅ Lockout countdown only (5 minutes)
(function() {
    function formatTime(totalSeconds) {
        var m = Math.floor(totalSeconds / 60);
        var s = totalSeconds % 60;
        return (m > 0 ? m + 'm ' : '') + s + 's';
    }

    var lockoutEl = document.getElementById('lockoutCountdown');
    if (lockoutEl) {
        var lockoutSeconds = parseInt(lockoutEl.dataset.seconds, 10) || 0;

        if (lockoutSeconds > 0) {
            var btn = document.getElementById('loginBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-lock"></i> Locked';
            }

            lockoutEl.textContent = formatTime(lockoutSeconds);

            var lockoutTick = setInterval(function() {
                lockoutSeconds--;

                if (lockoutSeconds <= 0) {
                    clearInterval(lockoutTick);
                    lockoutEl.textContent = '0s';

                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login';
                    }
                    return;
                }

                lockoutEl.textContent = formatTime(lockoutSeconds);
            }, 1000);
        }
    }
})();

// Submit on Enter
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        var form = document.getElementById('loginForm');
        if (form && document.activeElement.tagName === 'INPUT') {
            form.submit();
        }
    }
});
</script>

</body>
</html>
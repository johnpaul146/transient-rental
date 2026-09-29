<?php
// ============================================================
// verify-device.php — OTP Verification for New Devices
// ============================================================

ini_set('display_errors', 0);
error_reporting(E_ALL);

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

session_start();
require_once 'database.php';
require_once 'config/mail_config.php';
require_once 'includes/auth.php';

// ✅ NEW: Load SystemLogger (safe — won't break if file missing)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Must have pending device verification
if (empty($_SESSION['pending_device_verify_user_id'])) {
    header("Location: login.php");
    exit();
}

$error = '';
$success = '';

// ============================================================
// DYNAMIC CONTENT (logo + hero background)
// ============================================================
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {
    $content = [];
}

$logo_path = 'uploads/logos/logo.png';
$logo_exists = file_exists('uploads/logos/logo.png');
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
    $logo_exists = file_exists($logo_path);
}

$hero_path = 'uploads/hero/hero-bg.jpg';
$hero_exists = file_exists('uploads/hero/hero-bg.jpg');
if(isset($content['site_settings']['hero_image_path']) && !empty($content['site_settings']['hero_image_path'])) {
    $hero_path = $content['site_settings']['hero_image_path'];
    $hero_exists = file_exists($hero_path);
}

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

// Auto-cleanup expired devices
try {
    $pdo->exec("DELETE FROM trusted_devices WHERE expires_at < NOW()");
} catch (PDOException $e) {}

// ============================================================
// 🔐 COOKIE HELPER — computes correct Path & Secure flag
// ============================================================
if (!function_exists('sendTrustedDeviceCookie')) {
    function sendTrustedDeviceCookie(string $token, int $days = 30): void {
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (($_SERVER['SERVER_PORT'] ?? '') == 443)
                 || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        // Auto-detect base path for subfolder installs
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $script_dir = str_replace('\\', '/', $script_dir);
        $cookie_path = ($script_dir === '' || $script_dir === '/' || $script_dir === '.') ? '/' : rtrim($script_dir, '/') . '/';

        $max_age = $days * 24 * 60 * 60;
        $expires = gmdate('D, d M Y H:i:s T', time() + $max_age);

        $cookie_header = sprintf(
            'trusted_device=%s; Expires=%s; Max-Age=%d; Path=%s; %sHttpOnly; SameSite=Lax',
            urlencode($token),
            $expires,
            $max_age,
            $cookie_path,
            $is_https ? 'Secure; ' : ''
        );

        header('Set-Cookie: ' . $cookie_header, false);
        error_log("[verify-device] Set-Cookie sent: " . preg_replace('/trusted_device=[^;]+/', 'trusted_device=***', $cookie_header));
    }
}

// ============================================================
// AJAX: RESEND OTP
// ============================================================
if (isset($_POST['resend_otp'])) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    try {
        $user_id = (int)($_SESSION['pending_device_verify_user_id'] ?? 0);
        $email   = $_SESSION['pending_device_verify_email'] ?? '';

        if (empty($user_id) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit();
        }

        $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
            ->execute([$otp_code, $expires, $user['id']]);

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

        if ($mail->send()) {
            // ✅ NEW: Log OTP resend for device verification
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'resend_otp',
                    'auth',
                    "User '{$user['username']}' requested a NEW device-verification OTP (resend) — IP: " . getClientIp(),
                    (int)$user['id'],
                    'user',
                    null,
                    [
                        'email'    => $user['email'],
                        'ip'       => getClientIp(),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                        'page'     => 'verify-device.php',
                        'purpose'  => 'new_device_verification'
                    ]
                );
            }

            echo json_encode(['success' => true, 'message' => 'New OTP code sent to your email!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP. Please try again.']);
        }
        exit();

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Email error: ' . ($e->getMessage())
        ]);
        exit();
    }
}

// ============================================================
// AJAX: VERIFY OTP
// ============================================================
if (isset($_POST['ajax_verify_otp'])) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    try {
        $otp = trim($_POST['otp_code'] ?? '');
        $user_id = (int)($_SESSION['pending_device_verify_user_id'] ?? 0);

        // ---------- Validation ----------
        if (empty($otp)) {
            echo json_encode([
                'success' => false,
                'title'   => 'OTP Required',
                'message' => 'Please enter the 6-digit OTP code sent to your email.'
            ]);
            exit();
        }

        // ✅ Strip any non-digit characters just in case
        $otp = preg_replace('/\D/', '', $otp);

        if (!preg_match('/^\d{6}$/', $otp)) {
            // ✅ NEW: Log invalid format attempt
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'verify_device_failed',
                    'auth',
                    "New device verification FAILED — invalid OTP format (user ID: {$user_id}) — IP: " . getClientIp(),
                    $user_id ?: null,
                    'user',
                    null,
                    [
                        'reason'  => 'invalid_format',
                        'ip'      => getClientIp(),
                        'page'    => 'verify-device.php'
                    ],
                    'failed'
                );
            }

            echo json_encode([
                'success' => false,
                'title'   => 'Invalid Format',
                'message' => 'OTP must be exactly 6 digits (numbers only).'
            ]);
            exit();
        }

        // ---------- Check OTP in DB ----------
        $stmt = $pdo->prepare("
            SELECT id, username, role, email, reset_token, reset_token_expires 
            FROM users 
            WHERE id = ? AND reset_token = ?
        ");
        $stmt->execute([$user_id, $otp]);
        $user = $stmt->fetch();

        if (!$user) {
            // ✅ NEW: Log wrong OTP attempt
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'verify_device_failed',
                    'auth',
                    "New device verification FAILED — incorrect OTP (user ID: {$user_id}) — IP: " . getClientIp(),
                    $user_id ?: null,
                    'user',
                    null,
                    [
                        'reason'  => 'incorrect_otp',
                        'ip'      => getClientIp(),
                        'page'    => 'verify-device.php'
                    ],
                    'failed'
                );
            }

            echo json_encode([
                'success' => false,
                'title'   => 'Incorrect OTP',
                'message' => 'Invalid OTP code. Please check your email and try again.'
            ]);
            exit();
        }

        if (strtotime($user['reset_token_expires']) < time()) {
            // ✅ NEW: Log expired OTP attempt
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'verify_device_failed',
                    'auth',
                    "New device verification FAILED — OTP expired for '{$user['username']}' — IP: " . getClientIp(),
                    (int)$user['id'],
                    'user',
                    null,
                    [
                        'reason'   => 'expired_otp',
                        'expired_at' => $user['reset_token_expires'],
                        'ip'       => getClientIp(),
                        'page'     => 'verify-device.php'
                    ],
                    'failed'
                );
            }

            echo json_encode([
                'success' => false,
                'title'   => 'OTP Expired',
                'message' => 'Your OTP code has expired. Please click "Resend OTP" for a new code.'
            ]);
            exit();
        }

        // ---------- ✅ OTP verified — Create trusted device ----------
        $device_token = bin2hex(random_bytes(32));
        $device_name  = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';
        $ip_address   = getClientIp();
        $expires_at   = date('Y-m-d H:i:s', strtotime('+30 days'));

        $ins = $pdo->prepare("
            INSERT INTO trusted_devices 
            (user_id, device_token, device_name, user_agent, ip_address, last_used_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        $ins->execute([
            $user_id, $device_token, $device_name,
            $device_name, $ip_address, $expires_at
        ]);

        // ✅ Send trusted device cookie
        sendTrustedDeviceCookie($device_token, 30);

        // Clear OTP
        $pdo->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
            ->execute([$user_id]);

        // Prevent session fixation
        session_regenerate_id(true);

        // Set session
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];

        // Compute redirect target
        $redirect = $_SESSION['pending_device_verify_redirect'] ?? '';

        // Clear pending verification
        unset($_SESSION['pending_device_verify_user_id']);
        unset($_SESSION['pending_device_verify_redirect']);
        unset($_SESSION['pending_device_verify_username']);
        unset($_SESSION['pending_device_verify_email']);

        // Terms check for guests
        if ($user['role'] === 'guest') {
            require_once 'includes/TermsGate.php';
            $termsGate = new TermsGate($pdo);
            if (!$termsGate->hasAccepted((int)$user['id'])) {
                $_SESSION['show_terms_modal'] = true;
            }
        }

        $final_url = !empty($redirect) ? $redirect : dashboardUrlForRole($user['role']);

        // ✅ NEW: Log successful device verification
        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'verify_device',
                'auth',
                "User '{$user['username']}' successfully verified a NEW device and logged in — IP: {$ip_address}",
                (int)$user['id'],
                'user',
                null,
                [
                    'role'          => $user['role'],
                    'device_name'   => $device_name,
                    'ip'            => $ip_address,
                    'device_expires_at' => $expires_at,
                    'redirect_to'   => $final_url,
                    'page'          => 'verify-device.php'
                ]
            );
        }

        echo json_encode([
            'success'  => true,
            'redirect' => $final_url,
            'message'  => 'Verification successful! Redirecting...'
        ]);
        exit();

    } catch (Exception $e) {
        error_log("[verify-device] Error: " . $e->getMessage());

        // ✅ NEW: Log unexpected server error
        if (class_exists('SystemLogger')) {
            try {
                SystemLogger::log(
                    $pdo,
                    'verify_device_failed',
                    'auth',
                    "New device verification CRASHED — server error: " . $e->getMessage(),
                    $user_id ?: null,
                    'user',
                    null,
                    [
                        'reason'  => 'server_error',
                        'error'   => $e->getMessage(),
                        'ip'      => getClientIp(),
                        'page'    => 'verify-device.php'
                    ],
                    'failed'
                );
            } catch (Exception $logEx) {
                // Silently ignore logging failure so we don't mask the original error
            }
        }

        echo json_encode([
            'success' => false,
            'title'   => 'Server Error',
            'message' => 'Something went wrong: ' . $e->getMessage()
        ]);
        exit();
    }
}
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
    <title>Verify Device — <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html {
            -webkit-text-size-adjust: 100%;
            /* ✅ Prevent zoom on input focus (iOS) */
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            min-height: 100dvh; /* ✅ Dynamic viewport for mobile */
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            background-color: #0B2447;
            /* ✅ Prevent pull-to-refresh scroll bounce */
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

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(ellipse at center, transparent 0%, rgba(0,0,0,0.35) 100%);
            pointer-events: none;
            z-index: 0;
        }

        /* ✅ Safe area support for notched phones */
        @supports (padding: max(0px)) {
            body {
                padding-left: max(20px, env(safe-area-inset-left));
                padding-right: max(20px, env(safe-area-inset-right));
                padding-top: max(20px, env(safe-area-inset-top));
                padding-bottom: max(20px, env(safe-area-inset-bottom));
            }
        }

        .verify-card {
            position: relative;
            z-index: 1;
            background: white;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 460px;
            overflow: hidden;
        }

        .verify-header {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            color: white;
            padding: 35px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .verify-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(244, 180, 0, 0.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .verify-header .logo-image {
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

        .verify-header .logo-icon {
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

        .verify-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 5px;
            position: relative;
            z-index: 1;
        }

        .verify-header h1 i {
            color: #F4B400;
            margin-right: 6px;
        }

        .verify-header p {
            font-size: 13px;
            opacity: 0.85;
            margin: 0;
            position: relative;
            z-index: 1;
            color: #e0eeff;
        }

        .verify-body {
            padding: 30px;
        }

        .info-box {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-left: 4px solid #d97706;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 12.5px;
            color: #92400e;
            margin-bottom: 20px;
            line-height: 1.5;
            text-align: left;
        }

        .info-box i {
            color: #f59e0b;
            margin-right: 4px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5;
            border-left: 4px solid;
            text-align: left;
        }

        .alert-success {
            background: #d1fae5;
            border-color: #10b981;
            color: #065f46;
        }

        .otp-label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #0B2447;
            text-align: left;
        }

        .otp-label i {
            color: #4DA6D9;
            margin-right: 5px;
        }

        /* ============================================================
           ✅ OTP INPUT — mobile-friendly, numbers only
           ============================================================ */
        .otp-input {
            text-align: center;
            font-size: 28px;
            letter-spacing: 12px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            padding: 16px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            width: 100%;
            margin-bottom: 6px;
            background: #fafafa;
            transition: all 0.2s;
            color: #0B2447;
            /* ✅ CRITICAL: prevents iOS auto-zoom on focus */
            font-size: 28px;
            /* ✅ Remove spinners if browser treats it as number-ish */
            -moz-appearance: textfield;
            appearance: textfield;
            /* ✅ Prevent text selection artifacts */
            -webkit-user-select: text;
            user-select: text;
        }

        .otp-input::-webkit-outer-spin-button,
        .otp-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .otp-input:focus {
            outline: none;
            border-color: #4DA6D9;
            background: white;
            box-shadow: 0 0 0 4px rgba(77,166,217,0.1);
        }

        .otp-input::placeholder {
            color: #cbd5e1;
            letter-spacing: 8px;
        }

        .otp-input.shake {
            animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both;
            border-color: #ef4444 !important;
            background: #fee2e2 !important;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.15) !important;
        }

        @keyframes shake {
            10%, 90% { transform: translateX(-2px); }
            20%, 80% { transform: translateX(4px); }
            30%, 50%, 70% { transform: translateX(-6px); }
            40%, 60% { transform: translateX(6px); }
        }

        .otp-hint {
            font-size: 11.5px;
            color: #94a3b8;
            text-align: center;
            display: block;
            margin-bottom: 18px;
        }

        /* ============================================================
           ✅ BUTTONS — 48px minimum touch target
           ============================================================ */
        .btn-verify {
            width: 100%;
            min-height: 48px;
            padding: 14px;
            background: #F4B400;
            color: #0B2447;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(244,180,0,0.3);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }

        .btn-verify:hover:not(:disabled) {
            background: #e6a800;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244,180,0,0.45);
            color: #0B2447;
        }

        .btn-verify:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-verify:disabled {
            cursor: not-allowed;
            opacity: 0.8;
        }

        .btn-verify.ready {
            background: #10b981;
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-verify.ready:hover:not(:disabled) {
            background: #059669;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45);
            color: white;
        }

        .btn-resend {
            background: transparent;
            border: 2px solid #4DA6D9;
            color: #4DA6D9;
            min-height: 48px;
            padding: 11px 20px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }

        .btn-resend:hover:not(:disabled) {
            background: #4DA6D9;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(77, 166, 217, 0.3);
        }

        .btn-resend:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-resend:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .timer-text {
            font-size: 12.5px;
            color: #64748b;
            text-align: center;
            margin-top: 10px;
        }

        .timer-text span {
            font-weight: 700;
            color: #4DA6D9;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 20px;
            color: #64748b;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
            justify-content: center;
            width: 100%;
            min-height: 44px;
            padding: 8px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
        }

        .back-link:hover {
            color: #4DA6D9;
        }

        .resend-hint {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        /* ERROR POPUP */
        .error-popup-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 36, 71, 0.6);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.2s ease;
            overscroll-behavior: contain;
        }

        .error-popup-overlay.show { display: flex; }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .error-popup {
            background: white;
            border-radius: 24px;
            max-width: 420px;
            width: 100%;
            padding: 35px 30px;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            animation: popupSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            border-top: 6px solid #ef4444;
            max-height: 90vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        @keyframes popupSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.9); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .error-popup .popup-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
            color: #ef4444;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); }
        }

        .error-popup h2 {
            font-size: 22px;
            font-weight: 700;
            color: #991b1b;
            margin-bottom: 10px;
        }

        .error-popup p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 25px;
            word-wrap: break-word;
        }

        .error-popup .btn-close-error {
            width: 100%;
            min-height: 48px;
            padding: 14px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }

        .error-popup .btn-close-error:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45);
        }

        .error-popup .btn-close-error:active {
            transform: translateY(0);
        }

        .error-popup .error-hint {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        .error-popup .error-hint a {
            color: #4DA6D9;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .error-popup .error-hint a:hover {
            text-decoration: underline;
        }

        /* LOADING OVERLAY */
        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 36, 71, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 20px;
            animation: fadeIn 0.2s ease;
            overscroll-behavior: contain;
        }

        .loading-overlay.show { display: flex; }

        .loading-overlay .spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(255,255,255,0.2);
            border-top-color: #F4B400;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-overlay .loading-text {
            color: white;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: center;
            padding: 0 20px;
        }

        .loading-overlay .loading-text i {
            color: #10b981;
        }

        /* ============================================================
           ✅ RESPONSIVE — Mobile-first
           ============================================================ */
        @media (max-width: 480px) {
            body {
                padding: 12px;
                align-items: flex-start;
                padding-top: max(20px, env(safe-area-inset-top));
            }

            .verify-card {
                border-radius: 20px;
                margin-top: 0;
            }

            .verify-header {
                padding: 25px 20px;
            }

            .verify-header h1 {
                font-size: 19px;
            }

            .verify-header p {
                font-size: 12px;
            }

            .verify-header .logo-image {
                width: 65px;
                height: 65px;
                border-radius: 16px;
            }

            .verify-header .logo-icon {
                width: 58px;
                height: 58px;
                font-size: 26px;
                border-radius: 16px;
            }

            .verify-body {
                padding: 22px 18px;
            }

            .info-box {
                font-size: 12px;
                padding: 10px 14px;
            }

            /* ✅ Slightly smaller but still legible */
            .otp-input {
                font-size: 26px;
                letter-spacing: 10px;
                padding: 14px 12px;
            }

            .otp-input::placeholder {
                letter-spacing: 6px;
            }

            .btn-verify,
            .btn-resend {
                font-size: 14px;
                padding: 13px;
            }

            .error-popup {
                padding: 28px 22px;
                border-radius: 20px;
            }

            .error-popup .popup-icon {
                width: 65px;
                height: 65px;
                font-size: 32px;
            }

            .error-popup h2 {
                font-size: 19px;
            }

            .error-popup p {
                font-size: 13px;
            }

            .loading-overlay .loading-text {
                font-size: 14px;
            }
        }

        /* ✅ Extra small phones */
        @media (max-width: 360px) {
            .verify-header h1 {
                font-size: 17px;
            }

            .otp-input {
                font-size: 22px;
                letter-spacing: 8px;
                padding: 12px 10px;
            }

            .verify-body {
                padding: 18px 14px;
            }

            .info-box {
                font-size: 11px;
            }

            .btn-verify,
            .btn-resend {
                font-size: 13px;
                padding: 12px;
            }
        }

        /* ✅ Landscape phones */
        @media (max-height: 500px) and (orientation: landscape) {
            body {
                align-items: flex-start;
                padding-top: 15px;
            }

            .verify-header {
                padding: 20px;
            }

            .verify-header .logo-image,
            .verify-header .logo-icon {
                width: 50px;
                height: 50px;
                margin-bottom: 10px;
            }

            .verify-header h1 {
                font-size: 18px;
                margin-bottom: 3px;
            }

            .verify-body {
                padding: 20px;
            }

            .info-box {
                margin-bottom: 12px;
            }

            .otp-input {
                padding: 12px;
                font-size: 22px;
            }

            .error-popup {
                padding: 20px;
                max-height: 95vh;
            }
        }

        /* ✅ Larger tap targets for touch devices */
        @media (hover: none) and (pointer: coarse) {
            .btn-verify,
            .btn-resend,
            .error-popup .btn-close-error,
            .back-link {
                min-height: 48px;
            }

            .otp-input {
                min-height: 56px;
            }
        }

        /* ✅ Prefers-reduced-motion */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

<div class="verify-card">

    <div class="verify-header">
        <?php if($logo_exists && !is_dir($logo_path)): ?>
            <img src="<?php echo htmlspecialchars($logo_path); ?>?<?php echo time(); ?>"
                 alt="<?php echo htmlspecialchars($site_name); ?>"
                 class="logo-image">
        <?php else: ?>
            <div class="logo-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
        <?php endif; ?>

        <h1><i class="fas fa-shield-alt"></i> Verify New Device</h1>
        <p>We sent a 6-digit code to your email</p>
    </div>

    <div class="verify-body">

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <strong>New device detected!</strong> For your security, please enter the OTP sent to your email. This device will be remembered for 30 days.
        </div>

        <div id="resendMessage" class="alert alert-success" style="display: none;">
            <i class="fas fa-check-circle"></i> New OTP code sent to your email!
        </div>

        <form id="verifyForm" onsubmit="event.preventDefault(); submitOTP(); return false;">
            <label class="otp-label" for="otp_code">
                <i class="fas fa-key"></i> OTP Code
            </label>
            <!-- ✅ type="tel" gives numeric keypad on mobile, text allows leading zeros -->
            <input type="tel"
                   name="otp_code"
                   id="otp_code"
                   class="otp-input"
                   placeholder="000000"
                   maxlength="6"
                   pattern="[0-9]{6}"
                   inputmode="numeric"
                   autocomplete="one-time-code"
                   autocorrect="off"
                   autocapitalize="off"
                   spellcheck="false"
                   required
                   autofocus>

            <span class="otp-hint">
                <i class="fas fa-clock"></i> Code expires in 15 minutes
            </span>

            <button type="submit" id="verifyBtn" class="btn-verify">
                <i class="fas fa-check-circle"></i> Verify &amp; Login
            </button>
        </form>

        <button type="button" id="resendBtn" class="btn-resend" onclick="resendOTP()">
            <i class="fas fa-redo"></i> Resend OTP
        </button>
        <div id="timerDisplay" class="timer-text">
            <i class="fas fa-clock"></i> Resend available in <span id="countdown">30</span> seconds
        </div>

        <div class="resend-hint">
            Didn't receive the code? Check your spam folder.
        </div>

        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
    </div>
</div>

<!-- ERROR POPUP MODAL -->
<div class="error-popup-overlay" id="errorPopupOverlay">
    <div class="error-popup">
        <div class="popup-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <h2 id="errorPopupTitle">Verification Failed</h2>
        <p id="errorPopupMessage">
            Invalid OTP code. Please check your email and try again.
        </p>
        <button type="button" class="btn-close-error" onclick="closeErrorPopup()">
            <i class="fas fa-redo"></i> Try Again
        </button>
        <div class="error-hint">
            Didn't get a valid code? <a onclick="closeErrorPopup(); resendOTP();">Request a new OTP</a>
        </div>
    </div>
</div>

<!-- LOADING OVERLAY -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">
        <i class="fas fa-check-circle"></i> Verification successful! Redirecting...
    </div>
</div>

<script>
// ============================================================
// ELEMENT REFERENCES
// ============================================================
const otpInput = document.getElementById('otp_code');
const verifyBtn = document.getElementById('verifyBtn');

// ============================================================
// ✅ OTP INPUT — ONLY NUMBERS ALLOWED (mobile-friendly)
// ============================================================

// 1) Strip non-digits on input
otpInput.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    this.classList.remove('shake');

    if (this.value.length === 6) {
        verifyBtn.classList.add('ready');
        verifyBtn.innerHTML = '<i class="fas fa-check-circle"></i> Ready — Click to Verify';
    } else {
        verifyBtn.classList.remove('ready');
        verifyBtn.innerHTML = '<i class="fas fa-check-circle"></i> Verify &amp; Login';
    }
});

// 2) Block non-digit keypresses (desktop keyboard)
otpInput.addEventListener('keypress', function(e) {
    const char = String.fromCharCode(e.which);
    if (!/[0-9]/.test(char)) {
        e.preventDefault();
    }
});

// 3) Handle paste — strip non-digits
otpInput.addEventListener('paste', function(e) {
    e.preventDefault();
    const pasted = (e.clipboardData || window.clipboardData).getData('text');
    const cleaned = pasted.replace(/[^0-9]/g, '').slice(0, 6);
    this.value = cleaned;
    this.dispatchEvent(new Event('input'));
});

// 4) Block non-digit keydown as backup
otpInput.addEventListener('keydown', function(e) {
    const allowedKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                         'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                         'Home', 'End'];
    if (allowedKeys.includes(e.key)) return;
    if ((e.ctrlKey || e.metaKey) && ['a','c','v','x'].includes(e.key.toLowerCase())) return;
    if (!/^[0-9]$/.test(e.key)) {
        e.preventDefault();
    }
});

// ✅ 5) Handle Android autofill / OTP suggestion — sometimes inserts on a delay
otpInput.addEventListener('change', function() {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    this.dispatchEvent(new Event('input'));
});

// ✅ 6) Auto-submit when 6 digits are entered (mobile convenience)
otpInput.addEventListener('input', function() {
    if (this.value.length === 6) {
        // Small delay to let the UI update first
        setTimeout(() => {
            if (otpInput.value.length === 6 && !verifyBtn.disabled) {
                submitOTP();
            }
        }, 300);
    }
});

// ============================================================
// SUBMIT OTP VIA AJAX
// ============================================================
function submitOTP() {
    const otp = otpInput.value.trim();

    if (otp.length === 0) {
        showErrorPopup('OTP Required', 'Please enter the 6-digit OTP code sent to your email.');
        return;
    }

    if (!/^\d{6}$/.test(otp)) {
        showErrorPopup('Invalid Format', 'OTP must be exactly 6 digits (numbers only).');
        return;
    }

    verifyBtn.disabled = true;
    verifyBtn.classList.remove('ready');
    verifyBtn.style.background = '#F4B400';
    verifyBtn.style.color = '#0B2447';
    verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

    // ✅ Dismiss mobile keyboard
    otpInput.blur();

    const formData = new URLSearchParams();
    formData.append('ajax_verify_otp', '1');
    formData.append('otp_code', otp);

    fetch('verify-device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString(),
        credentials: 'same-origin'
    })
    .then(async response => {
        const rawText = await response.text();
        try {
            return JSON.parse(rawText);
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError);
            console.error('Server returned:', rawText);
            throw new Error('Server returned invalid response. Check console for details.');
        }
    })
    .then(data => {
        if (data.success) {
            document.getElementById('loadingOverlay').classList.add('show');
            setTimeout(function() {
                window.location.href = data.redirect;
            }, 800);
        } else {
            showErrorPopup(
                data.title || 'Verification Failed',
                data.message || 'Invalid OTP code. Please try again.'
            );
            resetVerifyButton();
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        showErrorPopup(
            'Connection Error',
            'Failed to verify OTP: ' + (error.message || 'Unknown error')
        );
        resetVerifyButton();
    });
}

function resetVerifyButton() {
    verifyBtn.disabled = false;
    verifyBtn.classList.remove('ready');
    verifyBtn.style.background = '#F4B400';
    verifyBtn.style.color = '#0B2447';
    verifyBtn.innerHTML = '<i class="fas fa-check-circle"></i> Verify &amp; Login';
}

// ============================================================
// ERROR POPUP
// ============================================================
function showErrorPopup(title, message) {
    document.getElementById('errorPopupTitle').textContent = title;
    document.getElementById('errorPopupMessage').textContent = message;
    document.getElementById('errorPopupOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';

    otpInput.classList.add('shake');
    setTimeout(() => otpInput.classList.remove('shake'), 600);
}

function closeErrorPopup() {
    document.getElementById('errorPopupOverlay').classList.remove('show');
    document.body.style.overflow = 'auto';
    otpInput.value = '';
    otpInput.focus();
    resetVerifyButton();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const overlay = document.getElementById('errorPopupOverlay');
        if (overlay.classList.contains('show')) {
            closeErrorPopup();
        }
    }
});

document.getElementById('errorPopupOverlay').addEventListener('click', function(e) {
    if (e.target === this) {
        closeErrorPopup();
    }
});

// ============================================================
// RESEND OTP
// ============================================================
let countdown = 30;
let timerInterval = null;
let isResendDisabled = true;

function startTimer() {
    const countdownEl = document.getElementById('countdown');
    const resendBtn = document.getElementById('resendBtn');

    if (!countdownEl || !resendBtn) return;
    if (timerInterval) clearInterval(timerInterval);

    timerInterval = setInterval(function() {
        countdown--;

        const el = document.getElementById('countdown');
        if (el) el.textContent = countdown;

        if (countdown <= 0) {
            clearInterval(timerInterval);
            isResendDisabled = false;
            resendBtn.disabled = false;
            document.getElementById('timerDisplay').innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> You can now resend the OTP code';
        }
    }, 1000);
}

startTimer();

function resendOTP() {
    if (isResendDisabled) return;

    const resendBtn = document.getElementById('resendBtn');
    resendBtn.disabled = true;
    resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    fetch('verify-device.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'resend_otp=1',
        credentials: 'same-origin'
    })
    .then(async response => {
        const rawText = await response.text();
        try {
            return JSON.parse(rawText);
        } catch (parseError) {
            console.error('Resend JSON Parse Error:', parseError);
            throw new Error('Server returned invalid response.');
        }
    })
    .then(data => {
        if (data.success) {
            const msgDiv = document.getElementById('resendMessage');
            msgDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
            msgDiv.style.display = 'block';

            countdown = 30;
            isResendDisabled = true;

            document.getElementById('timerDisplay').innerHTML = '<i class="fas fa-clock"></i> Resend available in <span id="countdown">30</span> seconds';

            resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
            resendBtn.disabled = true;

            otpInput.value = '';
            otpInput.focus();
            resetVerifyButton();

            startTimer();

            setTimeout(() => {
                msgDiv.style.display = 'none';
            }, 5000);
        } else {
            showErrorPopup('Resend Failed', data.message);
            resendBtn.disabled = false;
            resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
            isResendDisabled = false;
        }
    })
    .catch(error => {
        console.error('Resend fetch error:', error);
        showErrorPopup('Connection Error', 'Failed to resend OTP: ' + error.message);
        resendBtn.disabled = false;
        resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
        isResendDisabled = false;
    });
}

// ============================================================
// AUTO-FOCUS
// ============================================================
window.addEventListener('DOMContentLoaded', function() {
    if (otpInput) {
        // ✅ Slight delay — some mobile browsers focus before layout
        setTimeout(() => otpInput.focus(), 100);
    }
});
</script>

</body>
</html>
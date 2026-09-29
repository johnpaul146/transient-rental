<?php
// forgot-password.php
session_start();
require_once 'database.php';
require_once 'includes/EmailHelper.php';

// ✅ NEW: Load SystemLogger (safe — won't break if file missing)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// ============================================================
// GET DYNAMIC CONTENT (for logo + hero background)
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

// Get logo path
$logo_path = 'uploads/logos/logo.png';
$logo_exists = file_exists('uploads/logos/logo.png');
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
    $logo_exists = file_exists($logo_path);
}

// Get hero image path
$hero_path = 'uploads/hero/hero-bg.jpg';
$hero_exists = file_exists('uploads/hero/hero-bg.jpg');
if(isset($content['site_settings']['hero_image_path']) && !empty($content['site_settings']['hero_image_path'])) {
    $hero_path = $content['site_settings']['hero_image_path'];
    $hero_exists = file_exists($hero_path);
}

// Site name
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';
$site_tagline = $content['site_settings']['site_tagline'] ?? 'Your Home Away From Home';

// ============================================================
// ✅ NEW: Helper — real client IP (used for logging)
// ============================================================
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================================
// PASSWORD VALIDATION FUNCTION
// ============================================================
function validatePassword($password) {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter (A-Z)";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number (0-9)";
    }
    return $errors;
}

$error = '';
$success = '';
$step = 'email';

if (isset($_SESSION['reset_email']) && isset($_SESSION['reset_user_id']) && isset($_SESSION['otp_sent'])) {
    $step = 'verify';
}

function generateOTP($length = 6) {
    return str_pad(rand(0, 999999), $length, '0', STR_PAD_LEFT);
}

// ============================================================
// RESEND OTP - AJAX
// ============================================================
if (isset($_POST['resend_otp'])) {
    header('Content-Type: application/json');
    $email = $_SESSION['reset_email'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    
    if (empty($email) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit();
    }
    
    $otp_code = generateOTP(6);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
    $stmt->execute([$otp_code, $expires, $user_id]);
    
    $mail = MailConfig::getInstance()->getMailer();
    
    try {
        $mail->clearAddresses();
        $mail->addAddress($user['email'], $user['username']);
        $mail->Subject = '🔐 Password Reset OTP - Transient House & Tours';
        $mail->Body = '
        <html><head><style>
        body { font-family: Arial, sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .header { text-align: center; padding-bottom: 20px; border-bottom: 2px solid #e8f0fe; }
        .header h2 { color: #0B2447; margin: 0; }
        .otp-box { background: #f0f7fb; padding: 25px; text-align: center; font-size: 36px; font-weight: bold; letter-spacing: 12px; color: #0B2447; border-radius: 10px; margin: 25px 0; border: 2px dashed #4DA6D9; }
        .info { color: #64748b; font-size: 14px; line-height: 1.6; }
        .warning { background: #fef3c7; padding: 12px 20px; border-radius: 8px; color: #92400e; font-size: 13px; margin: 20px 0; }
        .footer { text-align: center; padding-top: 20px; border-top: 1px solid #e8f0fe; color: #94a3b8; font-size: 12px; }
        </style></head><body><div class="container">
        <div class="header"><h2>🔐 Password Reset Request</h2></div>
        <p class="info">Dear <strong>' . htmlspecialchars($user['username']) . '</strong>,</p>
        <p class="info">We received a request to reset your password. Use the OTP code below:</p>
        <div class="otp-box">' . $otp_code . '</div>
        <div class="warning"><strong>⏰ This code will expire in 15 minutes.</strong></div>
        <p class="info">If you didn\'t request this, please ignore this email.</p>
        <p class="info">Best regards,<br><strong>Transient House & Tours Team</strong></p>
        <div class="footer">&copy; ' . date('Y') . ' Transient House & Tours. All rights reserved.</div>
        </div></body></html>';
        
        $mail->AltBody = "Password Reset Request\n\nOTP Code: " . $otp_code . "\n\nExpires in 15 minutes.";
        
        if ($mail->send()) {
            $_SESSION['otp_sent'] = true;

            // ✅ NEW: Log OTP resend
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'resend_otp',
                    'auth',
                    "User '{$user['username']}' requested a NEW password-reset OTP (resend) — IP: " . getClientIp(),
                    (int)$user['id'],
                    'user',
                    null,
                    [
                        'email'   => $user['email'],
                        'ip'      => getClientIp(),
                        'purpose' => 'password_reset',
                        'page'    => 'forgot-password.php'
                    ]
                );
            }

            echo json_encode(['success' => true, 'message' => '✅ New OTP code sent to your email!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP. Please try again.']);
        }
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Email error: ' . $mail->ErrorInfo]);
        exit();
    }
}

// ============================================================
// STEP 1: SEND OTP
// ============================================================
if (isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([strtolower($email)]);
        $user = $stmt->fetch();
        
        if ($user) {
            $otp_code = generateOTP(6);
            $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
            $stmt->execute([$otp_code, $expires, $user['id']]);
            
            $mail = MailConfig::getInstance()->getMailer();
            
            try {
                $mail->clearAddresses();
                $mail->addAddress($user['email'], $user['username']);
                $mail->Subject = '🔐 Password Reset OTP - Transient House & Tours';
                $mail->Body = '
                <html><head><style>
                body { font-family: Arial, sans-serif; background: #f5f7fa; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                .header { text-align: center; padding-bottom: 20px; border-bottom: 2px solid #e8f0fe; }
                .header h2 { color: #0B2447; margin: 0; }
                .otp-box { background: #f0f7fb; padding: 25px; text-align: center; font-size: 36px; font-weight: bold; letter-spacing: 12px; color: #0B2447; border-radius: 10px; margin: 25px 0; border: 2px dashed #4DA6D9; }
                .info { color: #64748b; font-size: 14px; line-height: 1.6; }
                .warning { background: #fef3c7; padding: 12px 20px; border-radius: 8px; color: #92400e; font-size: 13px; margin: 20px 0; }
                .footer { text-align: center; padding-top: 20px; border-top: 1px solid #e8f0fe; color: #94a3b8; font-size: 12px; }
                </style></head><body><div class="container">
                <div class="header"><h2>🔐 Password Reset Request</h2></div>
                <p class="info">Dear <strong>' . htmlspecialchars($user['username']) . '</strong>,</p>
                <p class="info">We received a request to reset your password. Use the OTP code below:</p>
                <div class="otp-box">' . $otp_code . '</div>
                <div class="warning"><strong>⏰ This code will expire in 15 minutes.</strong></div>
                <p class="info">If you didn\'t request this, please ignore this email.</p>
                <p class="info">Best regards,<br><strong>Transient House & Tours Team</strong></p>
                <div class="footer">&copy; ' . date('Y') . ' Transient House & Tours. All rights reserved.</div>
                </div></body></html>';
                
                $mail->AltBody = "Password Reset Request\n\nOTP Code: " . $otp_code . "\n\nExpires in 15 minutes.";
                
                if ($mail->send()) {
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['otp_sent'] = true;
                    $step = 'verify';
                    $success = "✅ OTP code sent to your email. Please check your inbox.";

                    // ✅ NEW: Log OTP send (initial request)
                    if (class_exists('SystemLogger')) {
                        SystemLogger::log(
                            $pdo,
                            'password_reset_request',
                            'auth',
                            "User '{$user['username']}' requested a password reset OTP — IP: " . getClientIp(),
                            (int)$user['id'],
                            'user',
                            null,
                            [
                                'email'   => $user['email'],
                                'ip'      => getClientIp(),
                                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                                'page'    => 'forgot-password.php'
                            ]
                        );
                    }
                } else {
                    $error = "❌ Failed to send OTP code. Please try again.";
                }
            } catch (Exception $e) {
                $error = "❌ Email error: " . $mail->ErrorInfo;
            }
        } else {
            $error = "❌ Email address not found in our system.";

            // ✅ NEW: Log failed password reset request (email not found)
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'password_reset_request',
                    'auth',
                    "Password reset attempted for NON-EXISTENT email: '{$email}' — IP: " . getClientIp(),
                    null,
                    'user',
                    null,
                    [
                        'attempted_email' => $email,
                        'ip'              => getClientIp(),
                        'result'          => 'email_not_found',
                        'page'            => 'forgot-password.php'
                    ],
                    'failed'
                );
            }
        }
    }
}

// ============================================================
// STEP 2: VERIFY OTP
// ============================================================
if (isset($_POST['verify_otp'])) {
    $otp_code = trim($_POST['otp_code']);
    $email = $_SESSION['reset_email'] ?? '';
    
    if (empty($otp_code)) {
        $error = "Please enter the OTP code.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, reset_token, reset_token_expires FROM users WHERE LOWER(email) = LOWER(?) AND reset_token = ?");
        $stmt->execute([strtolower($email), $otp_code]);
        $user = $stmt->fetch();
        
        if ($user) {
            $expires = strtotime($user['reset_token_expires']);
            $now = time();
            
            if ($now > $expires) {
                $error = "❌ OTP code has expired. Please request a new one.";

                // ✅ NEW: Log expired OTP
                if (class_exists('SystemLogger')) {
                    SystemLogger::log(
                        $pdo,
                        'password_reset_failed',
                        'auth',
                        "Password reset OTP EXPIRED for '{$user['username']}' — IP: " . getClientIp(),
                        (int)$user['id'],
                        'user',
                        null,
                        [
                            'reason'     => 'expired_otp',
                            'expired_at' => $user['reset_token_expires'],
                            'ip'         => getClientIp(),
                            'page'       => 'forgot-password.php'
                        ],
                        'failed'
                    );
                }
            } else {
                $_SESSION['reset_verified'] = true;
                $step = 'reset';
                $success = "✅ OTP verified! You can now change your password.";

                // ✅ NEW: Log successful OTP verification
                if (class_exists('SystemLogger')) {
                    SystemLogger::log(
                        $pdo,
                        'password_reset_verify',
                        'auth',
                        "User '{$user['username']}' successfully verified password-reset OTP — IP: " . getClientIp(),
                        (int)$user['id'],
                        'user',
                        null,
                        [
                            'ip'   => getClientIp(),
                            'step' => 'otp_verified',
                            'page' => 'forgot-password.php'
                        ]
                    );
                }
            }
        } else {
            $error = "❌ Invalid OTP code. Please try again.";

            // ✅ NEW: Log wrong OTP attempt
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'password_reset_failed',
                    'auth',
                    "Password reset FAILED — incorrect OTP for email '{$email}' — IP: " . getClientIp(),
                    $_SESSION['reset_user_id'] ?? null,
                    'user',
                    null,
                    [
                        'reason' => 'incorrect_otp',
                        'email'  => $email,
                        'ip'     => getClientIp(),
                        'page'   => 'forgot-password.php'
                    ],
                    'failed'
                );
            }
        }
    }
}

// ============================================================
// STEP 3: RESET PASSWORD
// ============================================================
if (isset($_POST['reset_password'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } else {
        $passwordErrors = validatePassword($new_password);
        if (!empty($passwordErrors)) {
            $error = implode("<br>", $passwordErrors);

            // ✅ NEW: Log password policy violation
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'password_reset_failed',
                    'auth',
                    "Password reset FAILED — password policy violation (user ID: {$user_id}) — IP: " . getClientIp(),
                    $user_id ?: null,
                    'user',
                    null,
                    [
                        'reason'  => 'weak_password',
                        'errors'  => $passwordErrors,
                        'ip'      => getClientIp(),
                        'page'    => 'forgot-password.php'
                    ],
                    'failed'
                );
            }
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";

            // ✅ NEW: Log mismatch
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'password_reset_failed',
                    'auth',
                    "Password reset FAILED — passwords did not match (user ID: {$user_id}) — IP: " . getClientIp(),
                    $user_id ?: null,
                    'user',
                    null,
                    [
                        'reason' => 'password_mismatch',
                        'ip'     => getClientIp(),
                        'page'   => 'forgot-password.php'
                    ],
                    'failed'
                );
            }
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            // ✅ NEW: Fetch username for logging (before we clear session)
            $log_username = 'Unknown';
            try {
                $u_stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
                $u_stmt->execute([$user_id]);
                $u_row = $u_stmt->fetch();
                if ($u_row) {
                    $log_username = $u_row['username'];
                }
            } catch (PDOException $e) {}

            $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
            $stmt->execute([$hashed, $user_id]);
            
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_verified']);
            unset($_SESSION['otp_sent']);
            
            $step = 'done';
            $success = "✅ Password reset successfully! You can now login with your new password.";

            // ✅ NEW: Log successful password reset (critical security event)
            if (class_exists('SystemLogger')) {
                SystemLogger::log(
                    $pdo,
                    'password_reset_success',
                    'auth',
                    "User '{$log_username}' successfully RESET their password via OTP verification — IP: " . getClientIp(),
                    $user_id,
                    'user',
                    null,
                    [
                        'ip'         => getClientIp(),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                        'method'     => 'otp_email',
                        'page'       => 'forgot-password.php'
                    ]
                );
            }
        }
    }
}

// ============================================================
// CLEAR SESSION - "Start over"
// ============================================================
if (isset($_GET['clear_session'])) {
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_verified']);
    unset($_SESSION['otp_sent']);
    header("Location: forgot-password.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            background-color: #0B2447;
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

        .card {
            position: relative;
            z-index: 1;
            border-radius: 24px;
            padding: 0;
            overflow: hidden;
            max-width: 460px;
            width: 100%;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            border: none;
            background: white;
        }

        .card-header-gradient {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            color: white;
            padding: 35px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .card-header-gradient::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(244, 180, 0, 0.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .card-header-gradient .logo-image {
            width: 80px; height: 80px;
            border-radius: 20px;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.35);
            padding: 4px;
            background: white;
            margin: 0 auto 15px;
            display: block;
            position: relative; z-index: 1;
            box-shadow: 0 8px 25px rgba(0,0,0,0.25);
        }

        .card-header-gradient .logo-icon {
            width: 70px; height: 70px;
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 32px;
            color: #F4B400;
            position: relative; z-index: 1;
        }

        .card-header-gradient h2 {
            text-align: center;
            color: white;
            font-weight: 700;
            font-size: 22px;
            margin: 0;
            position: relative; z-index: 1;
        }

        .card-header-gradient p {
            text-align: center;
            color: #e0eeff;
            font-size: 13px;
            margin: 5px 0 0 0;
            opacity: 0.85;
            position: relative; z-index: 1;
        }

        .card-body-content { padding: 30px; }

        .form-label {
            font-weight: 600;
            color: #0B2447;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .form-control {
            padding: 12px 14px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            font-size: 14px;
            transition: all 0.2s;
            background: #fafafa;
        }

        .form-control:focus {
            border-color: #4DA6D9;
            background: white;
            box-shadow: 0 0 0 3px rgba(77,166,217,0.1);
        }

        /* ============================================================
           ✅ NEW: PASSWORD WRAPPER + TOGGLE EYE (inline SVG, always visible)
           ============================================================ */
        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper .form-control {
            padding-right: 48px;
            width: 100%;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            padding: 6px;
            cursor: pointer;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            transition: all 0.2s;
            z-index: 5;
            line-height: 1;
        }

        .toggle-password:hover {
            background: #f1f5f9;
            color: #4DA6D9;
        }

        .toggle-password:focus {
            outline: none;
            color: #4DA6D9;
        }

        .toggle-password svg {
            width: 20px;
            height: 20px;
            display: block;
            pointer-events: none;
        }

        /* Eye open + eye closed — only one shown at a time */
        .toggle-password .eye-open { display: block; }
        .toggle-password .eye-closed { display: none; }

        .toggle-password.is-visible .eye-open { display: none; }
        .toggle-password.is-visible .eye-closed { display: block; }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn-primary {
            background: #F4B400;
            color: #0B2447;
            border: none;
            padding: 13px;
            border-radius: 10px;
            font-weight: 700;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover {
            background: #e6a800;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.4);
            color: #0B2447;
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 13px;
            border-radius: 10px;
            font-weight: 700;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45);
            color: white;
        }

        .btn-resend {
            background: transparent;
            border: 2px solid #4DA6D9;
            color: #4DA6D9;
            padding: 11px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-resend:hover:not(:disabled) {
            background: #4DA6D9;
            color: white;
        }

        .btn-resend:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* ============================================================
           LINKS
           ============================================================ */
        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            color: #64748b;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.2s;
        }

        .back-link:hover { color: #4DA6D9; }

        /* ============================================================
           OTP INPUT
           ============================================================ */
        .otp-input {
            text-align: center;
            font-size: 24px;
            letter-spacing: 10px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }

        /* ============================================================
           ALERTS
           ============================================================ */
        .alert {
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5;
        }

        .alert-success {
            background: #e6f7e6;
            color: #10b981;
            border: 1px solid #b8e6b8;
        }

        .alert-danger {
            background: #fee2e2;
            color: #ef4444;
            border: 1px solid #fecaca;
        }

        .text-muted {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 5px;
            display: block;
        }

        .timer-text {
            font-size: 13px;
            color: #64748b;
            text-align: center;
            margin-top: 10px;
        }

        .timer-text span {
            font-weight: 700;
            color: #4DA6D9;
        }

        /* ============================================================
           PASSWORD REQUIREMENTS
           ============================================================ */
        .password-requirements {
            font-size: 11px;
            margin-top: 8px;
            padding: 10px 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .password-requirements .req {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 3px 0;
            color: #475569;
        }

        .password-requirements .req .check { color: #10b981; }
        .password-requirements .req .cross { color: #ef4444; }
        .password-requirements .req .pending { color: #94a3b8; }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 480px) {
            .card-header-gradient { padding: 25px 20px; }
            .card-header-gradient h2 { font-size: 19px; }
            .card-header-gradient .logo-image { width: 65px; height: 65px; }
            .card-header-gradient .logo-icon { width: 58px; height: 58px; font-size: 26px; }
            .card-body-content { padding: 22px; }
            .otp-input { font-size: 20px; letter-spacing: 8px; }
        }
    </style>
</head>
<body>

<div class="card">

    <div class="card-header-gradient">
        <?php if($logo_exists && !is_dir($logo_path)): ?>
            <img src="<?php echo htmlspecialchars($logo_path); ?>?<?php echo time(); ?>"
                 alt="<?php echo htmlspecialchars($site_name); ?>"
                 class="logo-image">
        <?php else: ?>
            <div class="logo-icon"><i class="fas fa-umbrella-beach"></i></div>
        <?php endif; ?>

        <?php if ($step == 'done'): ?>
            <h2>✅ Password Reset!</h2>
            <p>Your password has been changed successfully</p>
        <?php elseif ($step == 'reset'): ?>
            <h2>🔐 Reset Password</h2>
            <p>Enter your new password below</p>
        <?php elseif ($step == 'verify'): ?>
            <h2>📧 Enter OTP Code</h2>
            <p>We sent a 6-digit code to your email</p>
        <?php else: ?>
            <h2>🔐 Forgot Password?</h2>
            <p>Enter your email to receive an OTP code</p>
        <?php endif; ?>
    </div>

    <div class="card-body-content">

    <?php if ($step == 'done'): ?>
        <p style="text-align:center; color:#64748b; font-size:14px; margin-bottom:20px;">
            You can now login with your new password.
        </p>
        <a href="login.php" class="btn btn-primary" style="text-decoration: none;">
            <i class="fas fa-sign-in-alt"></i> Login Now
        </a>

    <?php elseif ($step == 'reset'): ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <div class="password-wrapper">
                    <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Enter new password" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('new_password', this)" aria-label="Show password" title="Show password">
                        <!-- Eye open (SVG) -->
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <!-- Eye closed (SVG) -->
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>

                <span class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Must be at least 8 characters, contain 1 uppercase letter and 1 number
                </span>

                <div class="password-requirements" id="passwordRequirements" style="display:none;">
                    <div class="req">
                        <span id="reqLength" class="pending"><i class="fas fa-circle"></i></span>
                        <span>At least 8 characters</span>
                    </div>
                    <div class="req">
                        <span id="reqUppercase" class="pending"><i class="fas fa-circle"></i></span>
                        <span>At least 1 uppercase letter (A-Z)</span>
                    </div>
                    <div class="req">
                        <span id="reqNumber" class="pending"><i class="fas fa-circle"></i></span>
                        <span>At least 1 number (0-9)</span>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm new password" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password', this)" aria-label="Show password" title="Show password">
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
                <div id="confirmFeedback" style="font-size: 12px; margin-top: 5px;"></div>
            </div>

            <button type="submit" name="reset_password" class="btn btn-success">
                <i class="fas fa-check-circle"></i> Reset Password
            </button>
        </form>
        <a href="forgot-password.php?clear_session=1" class="back-link">
            <i class="fas fa-arrow-left"></i> Start over
        </a>

    <?php elseif ($step == 'verify'): ?>
        <?php if ($success): ?>
            <div class="alert alert-success" id="successAlert"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div id="resendMessage" style="display: none;" class="alert alert-success">
            <i class="fas fa-check-circle"></i> New OTP code sent to your email!
        </div>

        <form method="POST" id="otpForm">
            <div class="mb-3">
                <label class="form-label">OTP Code</label>
                <input type="text" name="otp_code" id="otpCode" class="form-control otp-input" placeholder="000000" maxlength="6" required autofocus>
                <span class="text-muted">Enter the 6-digit code sent to your email</span>
            </div>
            <button type="submit" name="verify_otp" class="btn btn-primary">
                <i class="fas fa-check-circle"></i> Verify OTP
            </button>
        </form>

        <button id="resendBtn" class="btn-resend" onclick="resendOTP()">
            <i class="fas fa-redo"></i> Resend OTP
        </button>
        <div id="timerDisplay" class="timer-text">
            <i class="fas fa-clock"></i> Resend available in <span id="countdown">30</span> seconds
        </div>

        <a href="forgot-password.php?clear_session=1" class="back-link">
            <i class="fas fa-arrow-left"></i> Start over
        </a>

    <?php else: ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="your@email.com" required autofocus>
                <span class="text-muted">Enter the email address you used to register</span>
            </div>
            <button type="submit" name="send_otp" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send OTP Code
            </button>
        </form>

        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
    <?php endif; ?>

    </div>
</div>

<script>
// ============================================================
// ✅ PASSWORD VISIBILITY TOGGLE (works for both fields)
// ============================================================
function togglePassword(inputId, button) {
    var input = document.getElementById(inputId);
    if (!input) return;

    if (input.type === 'password') {
        input.type = 'text';
        button.classList.add('is-visible');
        button.setAttribute('aria-label', 'Hide password');
        button.setAttribute('title', 'Hide password');
    } else {
        input.type = 'password';
        button.classList.remove('is-visible');
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('title', 'Show password');
    }
}

// ============================================================
// PASSWORD VALIDATION - REAL-TIME (for reset step)
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const requirementsDiv = document.getElementById('passwordRequirements');
    const reqLength = document.getElementById('reqLength');
    const reqUppercase = document.getElementById('reqUppercase');
    const reqNumber = document.getElementById('reqNumber');
    const confirmFeedback = document.getElementById('confirmFeedback');

    if (passwordInput && requirementsDiv) {
        passwordInput.addEventListener('focus', function() {
            requirementsDiv.style.display = 'block';
        });

        passwordInput.addEventListener('blur', function() {
            if (this.value === '') {
                requirementsDiv.style.display = 'none';
            }
        });

        passwordInput.addEventListener('input', function() {
            const password = this.value;
            requirementsDiv.style.display = 'block';

            if (password.length >= 8) {
                reqLength.className = 'check';
                reqLength.innerHTML = '<i class="fas fa-check-circle"></i>';
            } else {
                reqLength.className = password.length > 0 ? 'cross' : 'pending';
                reqLength.innerHTML = password.length > 0 ? '<i class="fas fa-times-circle"></i>' : '<i class="fas fa-circle"></i>';
            }

            if (/[A-Z]/.test(password)) {
                reqUppercase.className = 'check';
                reqUppercase.innerHTML = '<i class="fas fa-check-circle"></i>';
            } else {
                reqUppercase.className = password.length > 0 ? 'cross' : 'pending';
                reqUppercase.innerHTML = password.length > 0 ? '<i class="fas fa-times-circle"></i>' : '<i class="fas fa-circle"></i>';
            }

            if (/[0-9]/.test(password)) {
                reqNumber.className = 'check';
                reqNumber.innerHTML = '<i class="fas fa-check-circle"></i>';
            } else {
                reqNumber.className = password.length > 0 ? 'cross' : 'pending';
                reqNumber.innerHTML = password.length > 0 ? '<i class="fas fa-times-circle"></i>' : '<i class="fas fa-circle"></i>';
            }

            checkPasswordMatch();
        });
    }

    if (confirmInput) {
        confirmInput.addEventListener('input', function() {
            checkPasswordMatch();
        });
    }

    function checkPasswordMatch() {
        if (!passwordInput || !confirmInput || !confirmFeedback) return;

        const password = passwordInput.value;
        const confirm = confirmInput.value;

        if (confirm === '') {
            confirmFeedback.innerHTML = '';
            confirmFeedback.style.color = '';
            confirmInput.classList.remove('error');
            return;
        }

        if (password === confirm) {
            confirmFeedback.innerHTML = '✅ Passwords match';
            confirmFeedback.style.color = '#10b981';
            confirmInput.classList.remove('error');
        } else {
            confirmFeedback.innerHTML = '❌ Passwords do not match';
            confirmFeedback.style.color = '#ef4444';
            confirmInput.classList.add('error');
        }
    }
});

<?php if ($step == 'verify'): ?>
// Timer functionality
let countdown = 30;
let timerInterval;
let isResendDisabled = true;

function startTimer() {
    const countdownEl = document.getElementById('countdown');
    const resendBtn = document.getElementById('resendBtn');

    timerInterval = setInterval(function() {
        countdown--;
        countdownEl.textContent = countdown;

        if (countdown <= 0) {
            clearInterval(timerInterval);
            isResendDisabled = false;
            resendBtn.disabled = false;
            countdownEl.textContent = '0';
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

    fetch('forgot-password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'resend_otp=1'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const msgDiv = document.getElementById('resendMessage');
            msgDiv.textContent = '✅ ' + data.message;
            msgDiv.style.display = 'block';

            countdown = 30;
            isResendDisabled = true;
            document.getElementById('timerDisplay').innerHTML = '<i class="fas fa-clock"></i> Resend available in <span id="countdown">30</span> seconds';
            resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
            resendBtn.disabled = true;

            clearInterval(timerInterval);
            startTimer();

            setTimeout(() => { msgDiv.style.display = 'none'; }, 5000);
        } else {
            alert('❌ ' + data.message);
            resendBtn.disabled = false;
            resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
        }
    })
    .catch(error => {
        alert('❌ Failed to resend OTP. Please try again.');
        resendBtn.disabled = false;
        resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
    });
}

<?php if ($success): ?>
setTimeout(function() {
    const alert = document.getElementById('successAlert');
    if (alert) {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.style.display = 'none', 500);
    }
}, 5000);
<?php endif; ?>

document.getElementById('otpCode').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('otpForm').submit();
    }
});
<?php endif; ?>
</script>

</body>
</html>
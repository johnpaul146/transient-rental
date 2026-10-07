<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once 'database.php';
require_once 'includes/sidebar-counts.php';

// Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Auth check — admin or staff only
require_once 'includes/auth.php';
requireAdminOrStaff();

$is_admin = ($_SESSION['role'] === 'admin');
$is_staff = ($_SESSION['role'] === 'staff');
$user_id  = (int)$_SESSION['user_id'];

// ============================================================
// ✅ AUTO-MIGRATE — Add profile_photo column if missing
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_photo'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER email");
    }
} catch (PDOException $e) {
    // Silent fail
}

// ============================================================
// Fetch current user
// ============================================================
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) { session_destroy(); header("Location: login.php"); exit(); }

// ============================================================
// Helpers
// ============================================================
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================================
// ✅ UPDATED: Avatar resolver — checks new + legacy paths
// ============================================================
if (!function_exists('getUserAvatar')) {
    function getUserAvatar($user) {
        if (empty($user['profile_photo'])) return null;
        $user_id = (int)($user['id'] ?? 0);
        if ($user_id <= 0) return null;

        $paths = [
            'uploads/profile/user_' . $user_id . '/' . $user['profile_photo'],
            'uploads/staff/' . $user['profile_photo'],
            'uploads/profile/' . $user['profile_photo'],
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && !is_dir($path)) return $path;
        }
        return null;
    }
}

// ============================================================
// ✅ NEW HELPER: Get the upload directory for a given user
// ============================================================
if (!function_exists('getStaffUploadDir')) {
    function getStaffUploadDir(int $user_id, bool $create = true): ?string {
        $dir = 'uploads/profile/user_' . $user_id . '/';
        if ($create && !is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) return null;
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
        }
        return $dir;
    }
}

// ============================================================
// ✅ NEW HELPER: Delete a photo across all known locations
// ============================================================
if (!function_exists('deleteStaffPhoto')) {
    function deleteStaffPhoto(int $user_id, string $filename): void {
        if (empty($filename)) return;
        $paths = [
            'uploads/profile/user_' . $user_id . '/' . $filename,
            'uploads/staff/' . $filename,
            'uploads/profile/' . $filename,
        ];
        foreach ($paths as $path) {
            if (file_exists($path) && !is_dir($path)) @unlink($path);
        }
    }
}

function validatePassword($password) {
    $errors = [];
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters long";
    if (!preg_match('/[A-Z]/', $password)) $errors[] = "Must contain at least one uppercase letter (A-Z)";
    if (!preg_match('/[0-9]/', $password)) $errors[] = "Must contain at least one number (0-9)";
    return $errors;
}

$success = '';
$error   = '';

// ============================================================
// ✅ HANDLE — Profile Photo Upload
// ============================================================
if (isset($_POST['upload_photo'])) {
    try {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception("Please select an image to upload.");
        }
        if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Upload error. Please try again.");
        }

        $file = $_FILES['profile_photo'];

        if ($file['size'] > 3 * 1024 * 1024) {
            throw new Exception("Image is too large. Maximum size is 3 MB.");
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            throw new Exception("Invalid file type. Allowed: JPG, PNG, GIF, WEBP.");
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!empty($mime) && !in_array($mime, $allowedMime)) {
            throw new Exception("File is not a valid image.");
        }

        $dir = getStaffUploadDir($user_id, true);
        if ($dir === null) {
            throw new Exception("Cannot create upload folder. Check permissions on 'uploads/profile/'.");
        }

        if (!empty($user['profile_photo'])) {
            deleteStaffPhoto($user_id, $user['profile_photo']);
        }

        $new_filename = 'profile_' . time() . '.' . $ext;
        $target = $dir . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new Exception("Failed to save the uploaded image.");
        }
        @chmod($target, 0644);

        $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")
            ->execute([$new_filename, $user_id]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'update', 'profile',
                "Uploaded new profile photo (saved to uploads/profile/user_{$user_id}/)",
                $user_id, 'user', null, [
                    'file' => $new_filename,
                    'dir'  => $dir
                ]);
        }

        $success = "Profile photo uploaded successfully!";
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================================
// ✅ HANDLE — Remove Profile Photo
// ============================================================
if (isset($_POST['remove_photo'])) {
    try {
        if (!empty($user['profile_photo'])) {
            deleteStaffPhoto($user_id, $user['profile_photo']);

            $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?")
                ->execute([$user_id]);

            if (class_exists('SystemLogger')) {
                SystemLogger::log($pdo, 'delete', 'profile',
                    "Removed own profile photo",
                    $user_id, 'user', null, null, 'warning');
            }

            $success = "Profile photo removed.";
        }
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================================
// HANDLE: Update Profile (full name + email)
// ============================================================
if (isset($_POST['update_profile'])) {
    try {
        $fullname = trim($_POST['fullname'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        // Staff may change name, photo and password only — the account email stays as set by an administrator.
        if (!$is_admin) $email = (string)$user['email'];

        if (empty($fullname)) throw new Exception("Full name is required.");
        if (empty($email))    throw new Exception("Email is required.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Please enter a valid email address.");

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $user_id]);
        if ($check->fetch()) throw new Exception("Email is already used by another account.");

        $old_values = ['fullname' => $user['fullname'], 'email' => $user['email']];
        $new_values = ['fullname' => $fullname, 'email' => $email];

        $stmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ? WHERE id = ?");
        $stmt->execute([$fullname, $email, $user_id]);

        $_SESSION['username'] = $user['username'];

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'update', 'profile',
                "Updated own profile (name & email)",
                $user_id, 'user', $old_values, $new_values);
        }

        $success = "Profile updated successfully!";
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================================
// HANDLE: Send OTP for password change
// ============================================================
if (isset($_POST['send_otp']) && isset($_POST['new_password'])) {
    try {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current)) throw new Exception("Current password is required.");
        if (empty($new))     throw new Exception("New password is required.");
        if ($new !== $confirm) throw new Exception("New passwords do not match.");

        if (!password_verify($current, $user['password'])) {
            throw new Exception("Current password is incorrect.");
        }

        $pwErrors = validatePassword($new);
        if (!empty($pwErrors)) throw new Exception(implode("<br>", $pwErrors));

        $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
            ->execute([$otp_code, $expires, $user_id]);

        $_SESSION['pending_password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        $_SESSION['pending_password_expires'] = time() + (15 * 60);

        require_once 'config/mail_config.php';
        $mail = MailConfig::getInstance()->getMailer();
        $mail->clearAddresses();
        $mail->addAddress($user['email'], $user['fullname'] ?: $user['username']);
        $mail->Subject = '🔐 Password Change OTP — Transient House & Tours';
        $mail->Body = '
        <html><head><style>
        body { font-family: Arial, sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 40px; }
        .header { text-align: center; padding-bottom: 20px; border-bottom: 2px solid #e8f0fe; }
        .header h2 { color: #0B2447; margin: 0; }
        .otp-box { background: #f0f7fb; padding: 25px; text-align: center; font-size: 36px; font-weight: bold; letter-spacing: 12px; color: #0B2447; border-radius: 10px; margin: 25px 0; border: 2px dashed #4DA6D9; }
        .info { color: #64748b; font-size: 14px; line-height: 1.6; }
        .warning { background: #fef3c7; padding: 12px 20px; border-radius: 8px; color: #92400e; font-size: 13px; margin: 20px 0; }
        .footer { text-align: center; padding-top: 20px; border-top: 1px solid #e8f0fe; color: #94a3b8; font-size: 12px; }
                .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }
    </style></head><body><div class="container">
        <div class="header"><h2>🔐 Password Change Request</h2></div>
        <p class="info">Hi <strong>' . htmlspecialchars($user['fullname'] ?: $user['username']) . '</strong>,</p>
        <p class="info">Use the OTP below to confirm your password change:</p>
        <div class="otp-box">' . $otp_code . '</div>
        <div class="warning"><strong>⏰ This code expires in 15 minutes.</strong></div>
        <p class="info">If you didn\'t request this, ignore this email.</p>
        <div class="footer">&copy; ' . date('Y') . ' Transient House & Tours</div>
        </div></body></html>';
        $mail->AltBody = "Password Change OTP: $otp_code\nExpires in 15 minutes.";
        $mail->send();

        $_SESSION['otp_sent_for_password'] = true;
        $success = "✅ OTP sent to your email: " . htmlspecialchars($user['email']);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================================
// HANDLE: Verify OTP + save new password
// ============================================================
if (isset($_POST['verify_otp'])) {
    try {
        $otp = trim($_POST['otp_code'] ?? '');

        if (empty($otp)) throw new Exception("Please enter the OTP code.");
        if (!preg_match('/^\d{6}$/', $otp)) throw new Exception("OTP must be 6 digits.");

        if (empty($_SESSION['pending_password_hash']) || time() > ($_SESSION['pending_password_expires'] ?? 0)) {
            throw new Exception("Session expired. Please request a new OTP.");
        }

        $stmt = $pdo->prepare("SELECT reset_token, reset_token_expires FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();

        if (!$row || $row['reset_token'] !== $otp) throw new Exception("Invalid OTP code.");
        if (strtotime($row['reset_token_expires']) < time()) throw new Exception("OTP has expired.");

        $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
            ->execute([$_SESSION['pending_password_hash'], $user_id]);

        unset($_SESSION['pending_password_hash']);
        unset($_SESSION['pending_password_expires']);
        unset($_SESSION['otp_sent_for_password']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'update', 'profile',
                "Changed own password (OTP verified)",
                $user_id, 'user');
        }

        $success = "✅ Password changed successfully!";

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================================
// ✅ SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================




// ============================================================
// Site content
// ============================================================
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while ($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch (PDOException $e) {}

$nav_logo = $content['site_settings']['logo_path'] ?? 'uploads/logos/logo.png';
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

// ✅ Compute avatar once
$avatar = getUserAvatar($user);
$initial = strtoupper(substr($user['fullname'] ?: $user['username'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>My Profile - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }
.app-container { display: flex; min-height: 100vh; }

/* SIDEBAR */
.sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77,166,217,0.15); z-index: 100; flex-shrink: 0; }
.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(77,166,217,0.3); border-radius: 10px; }
.sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
.sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
.sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg,#4DA6D9,#7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; overflow: hidden; }
.sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
.sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
.sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; }
.sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; }
.sidebar-close-btn:hover { background: #ef4444; color: white; transform: rotate(90deg); }
.sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
.sidebar-header .role-badge.admin { background: rgba(239,68,68,0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
.sidebar-header .role-badge.staff { background: rgba(251,191,36,0.2); color: #fbbf24; border: 1px solid rgba(251,191,36,0.2); }

.nav-menu { list-style: none; padding: 0; margin: 0; }
.nav-item { margin-bottom: 2px; }
.nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
.nav-link i { width: 22px; font-size: 16px; text-align: center; }
.nav-link:hover { background: rgba(77,166,217,0.15); color: white; border-left-color: #4DA6D9; }
.nav-link.active { background: rgba(77,166,217,0.2); color: white; border-left-color: #4DA6D9; }
.nav-link .nav-badge { margin-left: auto; background: rgba(239,68,68,0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
.nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; opacity: 0; transition: opacity 0.3s; }
.sidebar-overlay.active { display: block; opacity: 1; }

/* MENU TOGGLE */
.menu-toggle {
    display: none;
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 1001;
    background: #0B2447;
    color: white;
    border: none;
    border-radius: 12px;
    width: 48px;
    height: 48px;
    font-size: 22px;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(77,166,217,0.2);
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    transition: all 0.3s ease;
}
.menu-toggle:hover { background: rgba(77,166,217,0.2); transform: scale(1.05); }
.menu-toggle .fa-bars { transition: transform 0.3s ease; }
.menu-toggle.active .fa-bars { transform: rotate(90deg); }
body.sidebar-open-mobile .menu-toggle {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: scale(0.8);
}

@media (max-width: 480px) {
    .menu-toggle {
        width: 40px;
        height: 40px;
        font-size: 18px;
        top: 10px;
        left: 10px;
        border-radius: 10px;
    }
}
@media (max-width: 360px) {
    .menu-toggle {
        width: 36px;
        height: 36px;
        font-size: 16px;
        top: 8px;
        left: 8px;
        border-radius: 8px;
    }
}

@media (max-width: 1024px) {
    .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); transition: transform 0.3s; width: 280px; z-index: 1000; }
    .sidebar.open { transform: translateX(0); box-shadow: 4px 0 30px rgba(0,0,0,0.4); }
    .menu-toggle { display: flex; }
    .sidebar-overlay.active { display: block; }
    .main-content { padding: 70px 16px 20px !important; }
    .sidebar-close-btn { display: flex; }
}

/* COMPACT SIDEBAR ON MOBILE */
@media (max-width: 480px) {
    .sidebar { width: 85%; max-width: 300px; }
    .sidebar-header { padding: 0 16px 18px; margin-bottom: 14px; }
    .sidebar-header .logo .logo-text .main { font-size: 15px; }
    .sidebar-header .logo .logo-text .sub { font-size: 9px; }
    .sidebar-header .logo .logo-icon,
    .sidebar-header .logo .logo-icon img { width: 40px; height: 40px; font-size: 18px; border-radius: 12px; }
    .sidebar-header .role-badge { margin-top: 10px; padding: 3px 12px; font-size: 9px; }
    .sidebar-close-btn { width: 32px; height: 32px; font-size: 14px; border-radius: 8px; }
    .nav-link { padding: 10px 16px; font-size: 13px; gap: 12px; }
    .nav-link i { width: 20px; font-size: 14px; }
    .nav-link .nav-badge { font-size: 9px; padding: 1px 8px; }
    .nav-divider { margin: 12px 16px; }
}

/* MAIN */
.main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; }

/* TOP BAR */
.top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11,36,71,0.1); flex-wrap: wrap; gap: 10px; }
.page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
.page-title h1 i { color: #4DA6D9; }
.page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
.user-profile { display: flex; align-items: center; gap: 15px; }

.user-profile .avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg,#4DA6D9,#7bb8f0);
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: 700; font-size: 18px;
    overflow: hidden;
    border: 2px solid rgba(77, 166, 217, 0.2);
}
.user-profile .avatar img {
    width: 100%; height: 100%;
    object-fit: cover; border-radius: 50%;
}

.user-name { color: #0B2447; font-weight: 600; font-size: 14px; }
.user-role { color: #4a6a8c; font-size: 12px; }

/* MOBILE-ONLY ELEMENTS */
.mobile-role-badge,
.mobile-avatar {
    display: none !important;
}

.mobile-role-badge {
    font-size: 11px !important;
    font-weight: 700 !important;
    padding: 4px 12px !important;
    border-radius: 20px !important;
    align-items: center !important;
    gap: 5px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
}
.mobile-role-badge.admin {
    background: rgba(239, 68, 68, 0.12);
    color: #ef4444;
    border: 1.5px solid rgba(239, 68, 68, 0.25);
}
.mobile-role-badge.staff {
    background: rgba(251, 191, 36, 0.15);
    color: #d97706;
    border: 1.5px solid rgba(251, 191, 36, 0.3);
}

.mobile-avatar {
    width: 80px !important;
    height: 80px !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
    align-items: center !important;
    justify-content: center !important;
    color: white;
    font-weight: 700 !important;
    font-size: 32px !important;
    border: 4px solid #4DA6D9 !important;
    flex-shrink: 0;
    overflow: hidden;
    box-shadow:
        0 6px 20px rgba(77, 166, 217, 0.4),
        0 0 0 4px rgba(255, 255, 255, 1),
        0 0 0 7px rgba(77, 166, 217, 0.4);
}
.mobile-avatar img {
    width: 100%; height: 100%;
    object-fit: cover; border-radius: 50%;
}

@media (max-width: 768px) {
    .top-bar {
        position: relative;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        gap: 8px;
        padding-bottom: 15px;
        padding-left: 100px;
        padding-right: 100px;
        min-height: 130px;
    }
    .top-bar .page-title {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        width: 100%;
    }

    .top-bar .page-title h1 {
        font-size: 20px !important;
        line-height: 1.2 !important;
        font-weight: 700 !important;
        color: #0B2447 !important;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 8px;
        margin: 0;
    }
    .top-bar .page-title h1 > i {
        font-size: 18px !important;
    }

    .top-bar .page-title h1 .mobile-role-badge {
        display: inline-flex !important;
        font-size: 11px !important;
    }
    .top-bar .page-title h1 .mobile-avatar {
        display: inline-flex !important;
        position: absolute;
        top: 50%;
        right: 14px;
        transform: translateY(-50%);
        width: 80px !important;
        height: 80px !important;
        font-size: 32px !important;
    }

    .top-bar .page-title p {
        font-size: 12px !important;
        text-align: center;
        margin: 0;
    }
    .top-bar .user-profile { display: none !important; }
}

@media (max-width: 480px) {
    .top-bar {
        padding-left: 84px;
        padding-right: 84px;
        min-height: 110px;
    }
    .top-bar .page-title h1 {
        font-size: 17px !important;
        gap: 6px;
    }
    .top-bar .page-title h1 > i {
        font-size: 15px !important;
    }
    .top-bar .page-title p {
        font-size: 11px !important;
    }
    .top-bar .page-title h1 .mobile-avatar {
        width: 72px !important;
        height: 72px !important;
        font-size: 28px !important;
        right: 12px;
    }
    .top-bar .page-title h1 .mobile-role-badge {
        font-size: 10px !important;
        padding: 3px 10px !important;
    }
}

/* SECTION HEADER */
.section-header { display: flex; align-items: flex-start; gap: 14px; margin: 0 0 18px; padding: 14px 18px; background: #fff; border: 1px solid #dce8f3; border-left: 4px solid #4DA6D9; border-radius: 14px; box-shadow: 0 2px 8px rgba(11,36,71,0.05); }
.section-header .sh-icon { width: 40px; height: 40px; border-radius: 12px; background: #0B2447; color: #7bb8f0; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.section-header h2 { font-size: 18px; font-weight: 700; color: #0B2447; margin: 0; line-height: 1.25; }
.section-header p { font-size: 13px; color: #4a6a8c; margin: 2px 0 0; line-height: 1.4; }
@media (max-width: 480px) {
    .main-content { padding: 60px 12px 16px !important; }
}

/* CARDS */
.card { background: white; border-radius: 16px; padding: 22px; box-shadow: 0 2px 8px rgba(11,36,71,0.05); border: 1px solid #dce8f3; margin-bottom: 18px; min-width: 0; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
.card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
.card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }

@media (max-width: 768px) {
    .card { padding: 18px 15px; border-radius: 14px; }
    .card-header h2 { font-size: 15px; }
}
@media (max-width: 480px) {
    .card { padding: 12px 10px; }
    .card-header { flex-direction: column; align-items: stretch; }
}

/* ALERTS */
.alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; }
.alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
.alert-danger  { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }
.alert i { font-size: 18px; }

/* FORM */
.form-group { margin-bottom: 18px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #0B2447; font-size: 13px; }
.form-group label i { color: #4DA6D9; margin-right: 6px; }
.form-control { width: 100%; padding: 12px 14px; border: 1px solid #c9d9e8; border-radius: 10px; font-size: 14px; background: #fff; color: #0B2447; min-height: 44px; font-family: inherit; transition: all 0.2s; }
.form-control:focus { outline: 3px solid rgba(77,166,217,0.35); outline-offset: 0; border-color: #4DA6D9; background: white; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
@media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }

/* PASSWORD */
.password-wrapper { position: relative; }
.password-wrapper .form-control { padding-right: 48px; }
.toggle-password { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; padding: 8px; cursor: pointer; color: #94a3b8; font-size: 16px; }
.toggle-password:hover { color: #4DA6D9; }

.password-requirements { font-size: 12.5px; margin: 4px 0 16px; padding: 12px 14px; background: #f5f9fd; border-radius: 12px; border: 1px solid #dce8f3; }
.password-requirements .req-title { font-weight: 700; color: #0B2447; font-size: 12px; margin-bottom: 6px; }
.password-requirements .req-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 12px; }
.password-requirements .req { display: inline-flex; align-items: center; gap: 6px; color: #334e68; }
.password-requirements .req small { color: #64748b; }
@media (max-width: 380px) { .password-requirements .req-list { grid-template-columns: minmax(0, 1fr); } }
.password-requirements .check { color: #10b981; }
.password-requirements .cross { color: #ef4444; }
.password-requirements .pending { color: #94a3b8; }

/* BUTTONS */
.btn-primary { width: auto; min-width: 200px; min-height: 46px; padding: 12px 22px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(244,180,0,0.25); }
.btn-primary:hover { background: #e6a800; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244,180,0,0.4); }
.btn-success { width: auto; min-width: 200px; min-height: 46px; padding: 12px 22px; background: linear-gradient(135deg,#10b981,#059669); color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; }
.btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16,185,129,0.4); }

/* PROFILE PHOTO SECTION */
/* PROFILE HEADER CARD */
.profile-card { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; border-top: 4px solid #4DA6D9; }
.photo-preview-wrapper { position: relative; flex-shrink: 0; }
.photo-preview { width: 112px; height: 112px; border-radius: 50%; background: linear-gradient(135deg,#4DA6D9,#7bb8f0); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 44px; border: 4px solid #e8f0fe; overflow: hidden; position: relative; }
.photo-preview img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.photo-preview-wrapper .photo-badge { position: absolute; bottom: 4px; right: 4px; width: 28px; height: 28px; background: #4DA6D9; border: 3px solid #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; }
.photo-info { flex: 1; min-width: 220px; }
.photo-info h3 { font-size: 22px; font-weight: 700; color: #0B2447; margin: 0 0 2px; overflow-wrap: anywhere; }
.photo-info .handle { color: #4a6a8c; font-size: 14px; margin: 0 0 8px; overflow-wrap: anywhere; }
.profile-meta { display: flex; align-items: center; gap: 8px 14px; flex-wrap: wrap; margin-bottom: 4px; }
.profile-meta .since { font-size: 12.5px; color: #4a6a8c; }
.role-tag { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.role-admin { background: #fee2e2; color: #b91c1c; }
.role-staff { background: #fef3c7; color: #92400e; }
.photo-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.photo-actions form { display: inline-block; }
.btn-photo { min-height: 40px; padding: 8px 16px; border-radius: 10px; font-weight: 600; font-size: 13px; font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; border: 1px solid transparent; text-decoration: none; }
.btn-photo-upload { background: #2f8dc4; color: #fff; }
.btn-photo-upload:hover { background: #247aab; }
.btn-photo-remove { background: #fff; color: #b91c1c; border-color: #f3c0c0; }
.btn-photo-remove:hover { background: #fef2f2; }
.btn-photo:focus-visible, .btn-primary:focus-visible, .btn-success:focus-visible, .toggle-password:focus-visible { outline: 3px solid rgba(77,166,217,0.5); outline-offset: 2px; }
.photo-hint { font-size: 12px; color: #5b6b7e; margin-top: 10px; line-height: 1.5; }

/* ACCOUNT GRID */
.account-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 18px; align-items: start; }
.account-grid > .card { margin-bottom: 0; }
.card-sub { font-size: 13px; color: #4a6a8c; margin: -6px 0 16px; line-height: 1.5; }
.username-display { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #f5f9fd; border: 1px solid #dce8f3; border-radius: 10px; min-height: 44px; }
.username-display strong { color: #0B2447; font-size: 15px; overflow-wrap: anywhere; }
.field-note { font-size: 12px; color: #5b6b7e; margin-top: 5px; display: flex; gap: 6px; align-items: center; }
.field-note i { color: #4DA6D9; }
@media (max-width: 1100px) { .account-grid { grid-template-columns: minmax(0, 1fr); } .account-grid > .card { margin-bottom: 0; } }
@media (max-width: 600px) {
    .profile-card { flex-direction: column; text-align: center; gap: 14px; }
    .photo-info { min-width: 0; width: 100%; }
    .profile-meta, .photo-actions { justify-content: center; }
    .photo-actions form, .photo-actions .btn-photo { width: 100%; }
    .photo-actions .btn-photo { justify-content: center; }
    .btn-primary, .btn-success { width: 100%; }
    .photo-preview { width: 96px; height: 96px; font-size: 38px; }
}

/* OTP MODAL */
.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); z-index: 3000; align-items: center; justify-content: center; padding: 20px; }
.modal.show { display: flex; }
.modal-content { background: white; border-radius: 24px; max-width: 480px; width: 100%; max-height: 100%; overflow-y: auto; padding: 30px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
.modal-header h3 { font-size: 20px; font-weight: 700; color: #0B2447; margin: 0; display: flex; align-items: center; gap: 10px; }
.modal-header h3 i { color: #4DA6D9; }
.modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; background: none; border: none; padding: 0 10px; }
.modal-header .close:hover { color: #ef4444; }

.otp-input { text-align: center; font-size: 24px; letter-spacing: 10px; font-weight: 700; font-family: 'Courier New', monospace; padding: 14px; border: 2px solid #e8f0fe; border-radius: 10px; width: 100%; background: #fafafa; }
.otp-input:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77,166,217,0.1); }

/* FOOTER */
.footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77,166,217,0.15); }
.footer i { color: #4DA6D9; }
@media (max-width: 768px) { .footer { font-size: 11px; padding: 12px 10px; } }

/* LOGOUT MODAL */
.logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11,36,71,0.6); backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; }
.logout-modal-overlay.show { display: flex; }
.logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); border-top: 6px solid #ef4444; }
.logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg,#fee2e2,#fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; }
.logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
.logout-modal p { color: #64748b; font-size: 14px; margin-bottom: 25px; }
.logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
.btn-logout-cancel { background: #e2e8f0; color: #475569; }
.btn-logout-confirm { background: linear-gradient(135deg,#ef4444,#dc2626); color: white; }
@media (max-width: 480px) {
    .logout-modal { padding: 28px 22px 20px; }
    .logout-modal-actions { flex-direction: column-reverse; }
    .btn-logout-cancel, .btn-logout-confirm { width: 100%; }
}
</style>
    <link rel="stylesheet" href="assets/css/admin-responsive.css">
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="app-container">

    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-header-top">
                <a href="admin-dashboard.php" class="logo">
                    <div class="logo-icon">
                        <?php if($nav_logo_exists): ?>
                            <img src="<?php echo htmlspecialchars($nav_logo); ?>?<?php echo time(); ?>" alt="<?php echo htmlspecialchars($site_name); ?>">
                        <?php else: ?>
                            <i class="fas fa-umbrella-beach"></i>
                        <?php endif; ?>
                    </div>
                    <div class="logo-text">
                        <span class="main">Hundred Islands</span>
                        <span class="sub">Reservation System</span>
                    </div>
                </a>
                <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Close menu">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="role-badge <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                <?php echo $is_admin ? 'Administrator' : 'Staff'; ?>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item"><a href="admin-dashboard.php" class="nav-link"><i class="fas fa-th-large"></i><span>Dashboard</span></a></li>

            <?php if($is_admin): ?>
            <li class="nav-item"><a href="user-management.php" class="nav-link"><i class="fas fa-users"></i><span>User Management</span></a></li>
            <?php endif; ?>

            <li class="nav-item"><a href="house-dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>House Management</span></a></li>
            <li class="nav-item"><a href="tour-dashboard.php" class="nav-link"><i class="fas fa-umbrella-beach"></i><span>Tour Management</span></a></li>
            <li class="nav-item"><a href="activities-dashboard.php" class="nav-link"><i class="fas fa-water"></i><span>Activities Management</span></a></li>
<li class="nav-item">
                <a href="food-dashboard.php" class="nav-link">
                    <i class="fas fa-utensils"></i><span>Food Management</span></a>
            </li>

            <!-- ✅ BOOKING — badge = pending bookings -->
            <li class="nav-item">
                <a href="booking-management.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i><span>Booking Management</span>
                    <?php if($sidebar_pending_bookings > 0): ?>
                        <span class="nav-badge" style="background: rgba(245,158,11,0.2); color:#f59e0b;"><?php echo $sidebar_pending_bookings; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="blocked-dates.php" class="nav-link"><i class="fas fa-ban"></i><span>Blocked Dates</span></a></li>

            <!-- ✅ REVIEWS — badge = PENDING only -->
            <li class="nav-item">
                <a href="reviews-management.php" class="nav-link">
                    <i class="fas fa-star"></i><span>Reviews Management</span>
                    <?php if($sidebar_pending_reviews > 0): ?>
                        <span class="nav-badge" style="background: rgba(16,185,129,0.2); color:#10b981;"><?php echo $sidebar_pending_reviews; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <?php if(!empty($is_admin)): ?><li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li><?php endif; ?>

            <?php if($is_admin): ?>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>

            <!-- ✅ SYSTEM LOGS — badge = failed only -->
            <li class="nav-item">
                <a href="system-logs.php" class="nav-link">
                    <i class="fas fa-history"></i><span>System Logs</span>
                    <?php if($sidebar_failed_logs > 0): ?>
                        <span class="nav-badge"><?php echo $sidebar_failed_logs; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <div class="nav-divider"></div>
            <li class="nav-item"><a href="admin-profile.php" class="nav-link active"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link" onclick="openLogoutModal(event); return false;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">

        <!-- TOP BAR -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-user-circle"></i> My Profile

                    <span class="mobile-role-badge <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                        <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo $is_admin ? 'Admin' : 'Staff'; ?>
                    </span>
                    <span class="mobile-avatar" title="<?php echo htmlspecialchars($user['fullname'] ?: $user['username']); ?>">
                        <?php if($avatar): ?>
                            <img src="<?php echo htmlspecialchars($avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $initial; ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <p>Manage your account information and password</p>
            </div>
            <div class="user-profile">
                <div class="user-info" style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($user['fullname'] ?: $user['username']); ?></div>
                    <div class="user-role">
                        <?php if($is_admin): ?>
                            <i class="fas fa-crown" style="color: #fbbf24;"></i> Admin
                        <?php else: ?>
                            <i class="fas fa-user-tie" style="color: #fbbf24;"></i> Staff
                        <?php endif; ?>
                    </div>
                </div>
                <div class="avatar">
                    <?php if($avatar): ?>
                        <img src="<?php echo htmlspecialchars($avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo $initial; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- SECTION HEADER -->
        <div class="section-header">
            <div class="sh-icon"><i class="fas fa-user-cog"></i></div>
            <div>
                <h2>Account Center</h2>
                <p>Manage your profile details and keep your account secure. Your name appears in System Activity.</p>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <!-- PROFILE HEADER CARD -->
        <div class="card profile-card">
            <div class="photo-preview-wrapper">
                <div class="photo-preview">
                    <?php if($avatar): ?>
                        <img src="<?php echo htmlspecialchars($avatar); ?>?<?php echo time(); ?>" alt="Profile Photo">
                    <?php else: ?>
                        <?php echo $initial; ?>
                    <?php endif; ?>
                </div>
                <?php if($avatar): ?>
                    <div class="photo-badge"><i class="fas fa-check"></i></div>
                <?php endif; ?>
            </div>

            <div class="photo-info">
                <h3><?php echo htmlspecialchars($user['fullname'] ?: $user['username']); ?></h3>
                <p class="handle">@<?php echo htmlspecialchars($user['username']); ?></p>
                <div class="profile-meta">
                    <span class="role-tag <?php echo $is_admin ? 'role-admin' : 'role-staff'; ?>">
                        <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo $is_admin ? 'Administrator' : 'Staff Member'; ?>
                    </span>
                    <?php if(!empty($user['created_at']) && strtotime($user['created_at'])): ?>
                    <span class="since"><i class="fas fa-calendar-check"></i> Member since <?php echo date('F Y', strtotime($user['created_at'])); ?></span>
                    <?php endif; ?>
                </div>

                <div class="photo-actions">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="file"
                               name="profile_photo"
                               id="profile_photo_input"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               style="display: none;"
                               onchange="this.form.submit()">
                        <button type="button"
                                class="btn-photo btn-photo-upload"
                                onclick="document.getElementById('profile_photo_input').click()">
                            <i class="fas fa-camera"></i>
                            <?php echo $avatar ? 'Change Photo' : 'Upload Photo'; ?>
                        </button>
                        <input type="hidden" name="upload_photo" value="1">
                    </form>

                    <?php if($avatar): ?>
                    <form method="POST" onsubmit="return confirm('Remove your profile photo?');">
                        <button type="submit" name="remove_photo" class="btn-photo btn-photo-remove">
                            <i class="fas fa-trash"></i> Remove Photo
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="photo-hint">
                    Supported formats: JPG, PNG, WEBP<br>
                    Maximum size: 3 MB
                </div>
            </div>
        </div>

        <div class="account-grid">
            <!-- PERSONAL INFORMATION -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user-edit"></i> Personal Information</h2>
                </div>
                <form method="POST">
                    <div class="form-group">
                        <label for="fullname"><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" id="fullname" name="fullname" class="form-control" required
                               value="<?php echo htmlspecialchars($user['fullname'] ?? ''); ?>"
                               placeholder="e.g., Juan Dela Cruz">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-at"></i> Username</label>
                        <div class="username-display">
                            <strong>@<?php echo htmlspecialchars($user['username']); ?></strong>
                        </div>
                        <div class="field-note"><i class="fas fa-lock"></i> Username cannot be changed</div>
                    </div>

                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" required<?php echo $is_admin ? '' : ' readonly'; ?>
                               value="<?php echo htmlspecialchars($user['email']); ?>"
                               placeholder="your@email.com">
                        <div class="field-note"><i class="fas fa-info-circle"></i> Verification codes for password changes are sent to this address<?php if (!$is_admin): ?> · contact an administrator to change it<?php endif; ?></div>
                    </div>

                    <button type="submit" name="update_profile" class="btn-primary">
                        <i class="fas fa-save"></i> Save Profile Changes
                    </button>
                </form>
            </div>

            <!-- SECURITY CENTER -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-shield-alt"></i> Security Center</h2>
                </div>
                <p class="card-sub">Protect your account by updating your password. A verification code is sent to your email before the change takes effect.</p>

                <form method="POST" id="passwordForm">
                    <div class="form-group">
                        <label for="current_password"><i class="fas fa-key"></i> Current Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="current_password" id="current_password" class="form-control" required placeholder="Enter current password" autocomplete="current-password">
                            <button type="button" class="toggle-password" aria-label="Show or hide password" onclick="togglePw('current_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password"><i class="fas fa-lock"></i> New Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="new_password" id="new_password" class="form-control" required placeholder="Enter new password" autocomplete="new-password">
                            <button type="button" class="toggle-password" aria-label="Show or hide password" onclick="togglePw('new_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-lock"></i> Confirm New Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required placeholder="Re-enter new password" autocomplete="new-password">
                            <button type="button" class="toggle-password" aria-label="Show or hide password" onclick="togglePw('confirm_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="password-requirements" id="pwReq">
                        <div class="req-title">Password Requirements</div>
                        <div class="req-list">
                            <span class="req" id="reqLength"><span class="pending"><i class="fas fa-circle"></i></span> Minimum 8 characters</span>
                            <span class="req" id="reqUpper"><span class="pending"><i class="fas fa-circle"></i></span> Uppercase letter</span>
                            <span class="req" id="reqNum"><span class="pending"><i class="fas fa-circle"></i></span> Number</span>
                            <span class="req" id="reqSpecial"><span class="pending"><i class="fas fa-circle"></i></span> Special character <small>(recommended)</small></span>
                        </div>
                    </div>

                    <button type="submit" name="send_otp" class="btn-success">
                        <i class="fas fa-paper-plane"></i> Send Verification OTP
                    </button>
                </form>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            <p>
                <i class="fas fa-umbrella-beach"></i>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Hundred Islands Reservation System. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i> <?php echo $is_admin ? 'Administrator' : 'Staff'; ?> Access
                </span>
            </p>
        </div>
    </div>
</div>

<!-- OTP MODAL -->
<div class="modal <?php echo isset($_SESSION['otp_sent_for_password']) ? 'show' : ''; ?>" id="otpModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-shield-alt"></i> Enter OTP</h3>
            <button class="close" onclick="closeOtpModal()">&times;</button>
        </div>

        <p style="font-size:13px; color:#64748b; margin-bottom:15px; text-align:center;">
            We sent a 6-digit code to <strong><?php echo htmlspecialchars($user['email']); ?></strong>
        </p>

        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-key"></i> OTP Code</label>
                <input type="text" name="otp_code" class="otp-input" maxlength="6" inputmode="numeric"
                       placeholder="000000" required autofocus
                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                <small style="font-size:11px; color:#94a3b8; display:block; margin-top:6px; text-align:center;">
                    <i class="fas fa-clock"></i> Expires in 15 minutes
                </small>
            </div>

            <button type="submit" name="verify_otp" class="btn-success" style="margin-top:10px;">
                <i class="fas fa-check-circle"></i> Verify & Change Password
            </button>
        </form>

        <form method="POST" style="margin-top:12px;">
            <input type="hidden" name="current_password" value="">
            <p style="text-align:center; font-size:12px; color:#94a3b8; margin:0;">
                Didn't receive it? <a href="admin-profile.php" style="color:#4DA6D9; font-weight:600;">Cancel & try again</a>
            </p>
        </form>
    </div>
</div>

<!-- LOGOUT MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon"><i class="fas fa-sign-out-alt"></i></div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="logout.php" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
// SIDEBAR
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');
    const willOpen = !sidebar.classList.contains('open');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    toggleBtn.classList.toggle('active');
    if (willOpen && window.innerWidth <= 1024) document.body.classList.add('sidebar-open-mobile');
    else document.body.classList.remove('sidebar-open-mobile');
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const s = document.getElementById('sidebar');
        if (s.classList.contains('open')) toggleSidebar();
        const m = document.getElementById('logoutModal');
        if (m && m.classList.contains('show')) closeLogoutModal();
    }
});

// LOGOUT MODAL
function openLogoutModal(event) {
    if (event) event.preventDefault();
    const sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) toggleSidebar();
    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}
document.addEventListener('DOMContentLoaded', function() {
    const m = document.getElementById('logoutModal');
    if (m) m.addEventListener('click', function(e) { if (e.target === this) closeLogoutModal(); });
});

// PASSWORD TOGGLE
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
    }
}

// PASSWORD STRENGTH
const pwInput = document.getElementById('new_password');
if (pwInput) {
    pwInput.addEventListener('focus', () => document.getElementById('pwReq').classList.add('visible'));
    pwInput.addEventListener('input', function() {
        const p = this.value;
        const req = document.getElementById('pwReq');
        req.classList.add('visible');
        const set = (id, ok, label) => {
            document.getElementById(id).innerHTML = (ok
                ? '<span class="check"><i class="fas fa-check-circle"></i></span> '
                : '<span class="cross"><i class="fas fa-times-circle"></i></span> ') + label;
        };
        set('reqLength', p.length >= 8, 'Minimum 8 characters');
        set('reqUpper', /[A-Z]/.test(p), 'Uppercase letter');
        set('reqNum', /[0-9]/.test(p), 'Number');
        set('reqSpecial', /[^A-Za-z0-9]/.test(p), 'Special character <small>(recommended)</small>');
    });
}

// OTP MODAL
function closeOtpModal() {
    document.getElementById('otpModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}
<?php if(isset($_SESSION['otp_sent_for_password'])): ?>
    document.body.style.overflow = 'hidden';
<?php endif; ?>
</script>

</body>
</html>
<?php
session_start();
require_once 'database.php';

// ============================================================
// UPLOAD DEBUGGING — Enable temporarily if uploads keep failing
// ============================================================
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$is_staff = (isset($_SESSION['role']) && $_SESSION['role'] === 'staff');

if (!$is_admin && !$is_staff) {
    header("Location: index.php");
    exit();
}

// ✅ Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Get user info
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch (PDOException $e) {
    $user_info = [];
}

// ============================================================
// ✅ NEW: Resolve avatar (photo or initial) — same as admin-profile.php
// ============================================================
if (!function_exists('getAdminAvatar')) {
    function getAdminAvatar($user_info) {
        if (empty($user_info['profile_photo'])) return null;
        $user_id = (int)($user_info['id'] ?? 0);
        if ($user_id <= 0) return null;

        $paths = [
            'uploads/profile/user_' . $user_id . '/' . $user_info['profile_photo'],
            'uploads/staff/' . $user_info['profile_photo'],
            'uploads/profile/' . $user_info['profile_photo'],
        ];
        foreach ($paths as $path) {
            if (file_exists($path) && !is_dir($path)) return $path;
        }
        return null;
    }
}

$admin_avatar       = getAdminAvatar($user_info);
$admin_initial      = strtoupper(substr($user_info['fullname'] ?? $user_info['username'] ?? 'U', 0, 1));
$admin_display_name = $user_info['fullname'] ?? $user_info['username'] ?? 'User';

// ✅ NEW: Helper — build the "Admin 'username'" prefix for logs
if (!function_exists('logActor')) {
    function logActor(array $user_info): string {
        if (!empty($user_info['username'])) {
            return "Admin '{$user_info['username']}'";
        }
        return "User '" . ($_SESSION['username'] ?? 'Unknown') . "'";
    }
}

// ============================================================
// ✅ SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================

// ✅ System Logs — failed only
$log_stats = ['failed' => 0];
try {
    $log_stats['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM system_logs WHERE status = 'failed'")->fetchColumn();
} catch (PDOException $e) {}

// ✅ Booking Management — pending bookings (house + tour + food)
$pending_bookings = 0;
try {
    $hb_count = 0; $tb_count = 0; $fb_count = 0;

    $has_hb = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'booking_status'")->fetchAll();
    if (!empty($has_hb)) {
        $hb_count = (int)$pdo->query("SELECT COUNT(*) FROM house_bookings WHERE booking_status = 'pending'")->fetchColumn();
    }

    $has_tb = $pdo->query("SHOW COLUMNS FROM tour_bookings LIKE 'booking_status'")->fetchAll();
    if (!empty($has_tb)) {
        $tb_count = (int)$pdo->query("SELECT COUNT(*) FROM tour_bookings WHERE booking_status = 'pending'")->fetchColumn();
    }

    try {
        $has_fb = $pdo->query("SHOW COLUMNS FROM food_bookings LIKE 'booking_status'")->fetchAll();
        if (!empty($has_fb)) {
            $fb_count = (int)$pdo->query("SELECT COUNT(*) FROM food_bookings WHERE booking_status = 'pending'")->fetchColumn();
        }
    } catch(PDOException $e) {}

    $pending_bookings = $hb_count + $tb_count + $fb_count;
} catch(PDOException $e) {}

// ✅ Reviews — PENDING only (auto-detect column)
$pending_reviews = 0;
try {
    $has_status = $pdo->query("SHOW COLUMNS FROM overall_feedback LIKE 'status'")->fetchAll();
    $has_is_approved = $pdo->query("SHOW COLUMNS FROM overall_feedback LIKE 'is_approved'")->fetchAll();

    if (!empty($has_status)) {
        $pending_reviews = (int)$pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE status = 'pending'")->fetchColumn();
    } elseif (!empty($has_is_approved)) {
        $pending_reviews = (int)$pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE is_approved = 0")->fetchColumn();
    }
} catch(PDOException $e) {}

// ============================================================
// HOSTING-SAFE DIRECTORY CREATION
// ============================================================
$upload_dirs = [
    'uploads/gcash/',
    'uploads/logos/',
    'uploads/hero/',
    'uploads/hero/houses/',
    'uploads/hero/tours/',
    'uploads/hero/activities/',
    'uploads/hero/food/',
];

foreach ($upload_dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ============================================================
// HELPER: Reusable image uploader (hosting-safe)
// ============================================================
if (!function_exists('handleImageUpload')) {
    function handleImageUpload($fileKey, $targetDir, $targetBaseName, $allowedExtCsv = 'jpg,jpeg,png,gif,webp') {
        if (!isset($_FILES[$fileKey])) {
            return ['success' => false, 'message' => 'No file received.'];
        }
        $file = $_FILES[$fileKey];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'message' => 'Please select a file to upload.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => "File exceeds server's upload_max_filesize (" . ini_get('upload_max_filesize') . ").",
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server has no temporary folder for uploads. Contact your host.',
                UPLOAD_ERR_CANT_WRITE => 'Server could not write the file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload was stopped by a server extension.',
            ];
            return ['success' => false, 'message' => $errMap[$file['error']] ?? 'Unknown upload error.'];
        }
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        if (!is_dir($targetDir)) {
            return ['success' => false, 'message' => "Upload folder could not be created: $targetDir"];
        }
        if (!is_writable($targetDir)) {
            @chmod($targetDir, 0755);
            if (!is_writable($targetDir)) {
                return ['success' => false, 'message' => "Upload folder is not writable: $targetDir. Set permissions to 755 (or 775)."];
            }
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = array_map('trim', explode(',', $allowedExtCsv));
        if (!in_array($ext, $allowedExt, true)) {
            return ['success' => false, 'message' => "Invalid file extension (.$ext). Allowed: " . implode(', ', $allowedExt)];
        }
        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($file['tmp_name']);
        } else {
            $mime = $file['type'] ?? '';
        }
        if (!in_array($mime, $allowedMime, true)) {
            return ['success' => false, 'message' => "File is not a valid image (detected: $mime)."];
        }
        $targetFilename = $targetBaseName . '.' . $ext;
        $targetPath = rtrim($targetDir, '/') . '/' . $targetFilename;
        foreach (glob(rtrim($targetDir, '/') . '/' . $targetBaseName . '.*') as $oldFile) {
            @unlink($oldFile);
        }
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $lastErr = error_get_last();
            $detail = ($lastErr && !empty($lastErr['message'])) ? ' ' . $lastErr['message'] : '';
            return ['success' => false, 'message' => "Failed to save file.$detail Check that $targetDir is writable."];
        }
        @chmod($targetPath, 0644);
        return ['success' => true, 'message' => 'Uploaded successfully.', 'filename' => $targetFilename];
    }
}

// ============================================================
// HELPER: Update / insert a site_content row
// ============================================================
if (!function_exists('saveSiteContent')) {
    function saveSiteContent(PDO $pdo, string $section, string $key, string $value): void {
        $check = $pdo->prepare("SELECT id FROM site_content WHERE section_name = ? AND content_key = ?");
        $check->execute([$section, $key]);
        if ($check->fetch()) {
            $stmt = $pdo->prepare("UPDATE site_content SET content_value = ? WHERE section_name = ? AND content_key = ?");
            $stmt->execute([$value, $section, $key]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO site_content (section_name, content_key, content_value) VALUES (?, ?, ?)");
            $stmt->execute([$section, $key, $value]);
        }
    }
}

// ============================================================
// LOGO UPLOAD
// ============================================================
if (isset($_POST['upload_logo']) && $is_admin) {
    $result = handleImageUpload('logo_image', 'uploads/logos/', 'logo');
    if ($result['success']) {
        saveSiteContent($pdo, 'site_settings', 'logo_path', 'uploads/logos/' . $result['filename']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'content',
                logActor($user_info) . " uploaded a new site logo",
                null, 'site_content', null,
                ['file' => 'uploads/logos/' . $result['filename']]);
        }

        $_SESSION['flash_success'] = "Logo uploaded successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } else { $error = $result['message']; }
}

// HERO IMAGE UPLOADS
if (isset($_POST['upload_home_hero']) && $is_admin) {
    $result = handleImageUpload('home_hero_image', 'uploads/hero/', 'hero-bg');
    if ($result['success']) {
        saveSiteContent($pdo, 'site_settings', 'hero_image_path', 'uploads/hero/' . $result['filename']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'content',
                logActor($user_info) . " uploaded a new Homepage hero image",
                null, 'site_content', null,
                ['file' => 'uploads/hero/' . $result['filename']]);
        }

        $_SESSION['flash_success'] = "Homepage hero image uploaded successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } else { $error = $result['message']; }
}
if (isset($_POST['upload_houses_hero']) && $is_admin) {
    $result = handleImageUpload('houses_hero_image', 'uploads/hero/houses/', 'houses-hero');
    if ($result['success']) {
        saveSiteContent($pdo, 'site_settings', 'houses_hero_image', $result['filename']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'content',
                logActor($user_info) . " uploaded a new Houses page hero image",
                null, 'site_content', null,
                ['file' => $result['filename']]);
        }

        $_SESSION['flash_success'] = "Houses page hero image uploaded successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } else { $error = $result['message']; }
}
if (isset($_POST['upload_tours_hero']) && $is_admin) {
    $result = handleImageUpload('tours_hero_image', 'uploads/hero/tours/', 'tours-hero');
    if ($result['success']) {
        saveSiteContent($pdo, 'site_settings', 'tours_hero_image', $result['filename']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'content',
                logActor($user_info) . " uploaded a new Tours page hero image",
                null, 'site_content', null,
                ['file' => $result['filename']]);
        }

        $_SESSION['flash_success'] = "Tours page hero image uploaded successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } else { $error = $result['message']; }
}
if (isset($_POST['upload_activities_hero']) && $is_admin) {
    $result = handleImageUpload('activities_hero_image', 'uploads/hero/activities/', 'activities-hero');
    if ($result['success']) {
        saveSiteContent($pdo, 'site_settings', 'activities_hero_image', $result['filename']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'content',
                logActor($user_info) . " uploaded a new Activities page hero image",
                null, 'site_content', null,
                ['file' => $result['filename']]);
        }

        $_SESSION['flash_success'] = "Activities page hero image uploaded successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } else { $error = $result['message']; }
}
if (isset($_POST['upload_food_hero']) && $is_admin) {
    $result = handleImageUpload('food_hero_image', 'uploads/hero/food/', 'food-hero');
    if ($result['success']) {
        saveSiteContent($pdo, 'site_settings', 'food_hero_image', $result['filename']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'content',
                logActor($user_info) . " uploaded a new Food page hero image",
                null, 'site_content', null,
                ['file' => $result['filename']]);
        }

        $_SESSION['flash_success'] = "Food page hero image uploaded successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } else { $error = $result['message']; }
}

// GCASH QR CODE UPLOAD
if (isset($_POST['upload_gcash_qr']) && $is_admin) {
    $result = handleImageUpload('gcash_qr', 'uploads/gcash/', 'gcash_qr');
    if ($result['success']) {
        saveSiteContent($pdo, 'gcash', 'qr_code', $result['filename']);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'upload', 'content',
                logActor($user_info) . " uploaded a new GCash QR code",
                null, 'site_content', null,
                ['file' => 'uploads/gcash/' . $result['filename']]);
        }

        $_SESSION['flash_success'] = "GCash QR Code uploaded successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } else { $error = $result['message']; }
}

// ============================================================
// REMOVALS
// ============================================================
if (isset($_GET['remove_logo']) && $is_admin) {
    foreach (glob('uploads/logos/logo.*') as $f) @unlink($f);
    $pdo->prepare("DELETE FROM site_content WHERE section_name='site_settings' AND content_key='logo_path'")->execute();

    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'content',
            logActor($user_info) . " removed the site logo",
            null, 'site_content', null, null, 'warning');
    }

    $_SESSION['flash_success'] = "Logo removed successfully.";
    header("Location: edit-content.php"); exit();
}
if (isset($_GET['remove_home_hero']) && $is_admin) {
    foreach (glob('uploads/hero/hero-bg.*') as $f) @unlink($f);
    $pdo->prepare("DELETE FROM site_content WHERE section_name='site_settings' AND content_key='hero_image_path'")->execute();

    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'content',
            logActor($user_info) . " removed the Homepage hero image",
            null, 'site_content', null, null, 'warning');
    }

    $_SESSION['flash_success'] = "Homepage hero image removed successfully.";
    header("Location: edit-content.php"); exit();
}
if (isset($_GET['remove_houses_hero']) && $is_admin) {
    foreach (glob('uploads/hero/houses/houses-hero.*') as $f) @unlink($f);
    $pdo->prepare("DELETE FROM site_content WHERE section_name='site_settings' AND content_key='houses_hero_image'")->execute();

    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'content',
            logActor($user_info) . " removed the Houses page hero image",
            null, 'site_content', null, null, 'warning');
    }

    $_SESSION['flash_success'] = "Houses page hero image removed successfully.";
    header("Location: edit-content.php"); exit();
}
if (isset($_GET['remove_tours_hero']) && $is_admin) {
    foreach (glob('uploads/hero/tours/tours-hero.*') as $f) @unlink($f);
    $pdo->prepare("DELETE FROM site_content WHERE section_name='site_settings' AND content_key='tours_hero_image'")->execute();

    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'content',
            logActor($user_info) . " removed the Tours page hero image",
            null, 'site_content', null, null, 'warning');
    }

    $_SESSION['flash_success'] = "Tours page hero image removed successfully.";
    header("Location: edit-content.php"); exit();
}
if (isset($_GET['remove_activities_hero']) && $is_admin) {
    foreach (glob('uploads/hero/activities/activities-hero.*') as $f) @unlink($f);
    $pdo->prepare("DELETE FROM site_content WHERE section_name='site_settings' AND content_key='activities_hero_image'")->execute();

    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'content',
            logActor($user_info) . " removed the Activities page hero image",
            null, 'site_content', null, null, 'warning');
    }

    $_SESSION['flash_success'] = "Activities page hero image removed successfully.";
    header("Location: edit-content.php"); exit();
}
if (isset($_GET['remove_food_hero']) && $is_admin) {
    foreach (glob('uploads/hero/food/food-hero.*') as $f) @unlink($f);
    $pdo->prepare("DELETE FROM site_content WHERE section_name='site_settings' AND content_key='food_hero_image'")->execute();

    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'content',
            logActor($user_info) . " removed the Food page hero image",
            null, 'site_content', null, null, 'warning');
    }

    $_SESSION['flash_success'] = "Food page hero image removed successfully.";
    header("Location: edit-content.php"); exit();
}
if (isset($_GET['remove_gcash_qr']) && $is_admin) {
    foreach (glob('uploads/gcash/gcash_qr.*') as $f) @unlink($f);
    $pdo->prepare("DELETE FROM site_content WHERE section_name='gcash' AND content_key='qr_code'")->execute();

    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'content',
            logActor($user_info) . " removed the GCash QR code",
            null, 'site_content', null, null, 'warning');
    }

    $_SESSION['flash_success'] = "GCash QR code removed successfully.";
    header("Location: edit-content.php"); exit();
}

// FLASH MESSAGES
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// LOAD SITE CONTENT
$content = [];
try {
    $stmt = $pdo->query("SELECT * FROM site_content ORDER BY section_name, id");
    while ($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch (Exception $e) { $error = "Failed to load content: " . $e->getMessage(); }

// RESOLVE LOGO PATH (dynamic + fallback)
$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

// RESOLVE IMAGE PATHS WITH GLOB FALLBACK
$logo_path = $content['site_settings']['logo_path'] ?? '';
$logo_exists = false;
if (!empty($logo_path) && file_exists($logo_path) && !is_dir($logo_path)) {
    $logo_exists = true;
} else {
    foreach (glob('uploads/logos/logo.*') as $f) {
        $logo_path = $f; $logo_exists = true;
        saveSiteContent($pdo, 'site_settings', 'logo_path', $f); break;
    }
}

$home_hero_path = $content['site_settings']['hero_image_path'] ?? '';
$home_hero_exists = false;
if (!empty($home_hero_path) && file_exists($home_hero_path) && !is_dir($home_hero_path)) {
    $home_hero_exists = true;
} else {
    foreach (glob('uploads/hero/hero-bg.*') as $f) {
        $home_hero_path = $f; $home_hero_exists = true;
        saveSiteContent($pdo, 'site_settings', 'hero_image_path', $f); break;
    }
}

$houses_hero_filename = $content['site_settings']['houses_hero_image'] ?? '';
$houses_hero_path = 'uploads/hero/houses/' . $houses_hero_filename;
$houses_hero_exists = !empty($houses_hero_filename) && file_exists($houses_hero_path);
if (!$houses_hero_exists) {
    foreach (glob('uploads/hero/houses/houses-hero.*') as $f) {
        $houses_hero_path = $f; $houses_hero_exists = true;
        saveSiteContent($pdo, 'site_settings', 'houses_hero_image', basename($f)); break;
    }
}

$tours_hero_filename = $content['site_settings']['tours_hero_image'] ?? '';
$tours_hero_path = 'uploads/hero/tours/' . $tours_hero_filename;
$tours_hero_exists = !empty($tours_hero_filename) && file_exists($tours_hero_path);
if (!$tours_hero_exists) {
    foreach (glob('uploads/hero/tours/tours-hero.*') as $f) {
        $tours_hero_path = $f; $tours_hero_exists = true;
        saveSiteContent($pdo, 'site_settings', 'tours_hero_image', basename($f)); break;
    }
}

$activities_hero_filename = $content['site_settings']['activities_hero_image'] ?? '';
$activities_hero_path = 'uploads/hero/activities/' . $activities_hero_filename;
$activities_hero_exists = !empty($activities_hero_filename) && file_exists($activities_hero_path);
if (!$activities_hero_exists) {
    foreach (glob('uploads/hero/activities/activities-hero.*') as $f) {
        $activities_hero_path = $f; $activities_hero_exists = true;
        saveSiteContent($pdo, 'site_settings', 'activities_hero_image', basename($f)); break;
    }
}

$food_hero_filename = $content['site_settings']['food_hero_image'] ?? '';
$food_hero_path = 'uploads/hero/food/' . $food_hero_filename;
$food_hero_exists = !empty($food_hero_filename) && file_exists($food_hero_path);
if (!$food_hero_exists) {
    foreach (glob('uploads/hero/food/food-hero.*') as $f) {
        $food_hero_path = $f; $food_hero_exists = true;
        saveSiteContent($pdo, 'site_settings', 'food_hero_image', basename($f)); break;
    }
}

$gcash_qr_filename = $content['gcash']['qr_code'] ?? '';
$gcash_qr_path = 'uploads/gcash/' . $gcash_qr_filename;
$gcash_qr_exists = !empty($gcash_qr_filename) && file_exists($gcash_qr_path);
if (!$gcash_qr_exists) {
    foreach (glob('uploads/gcash/gcash_qr.*') as $f) {
        $gcash_qr_path = $f; $gcash_qr_exists = true;
        saveSiteContent($pdo, 'gcash', 'qr_code', basename($f)); break;
    }
}

$facebook_link = $content['social']['facebook'] ?? '#';
$location_address = $content['location']['address'] ?? '123 Transient Street, Alaminos City, Pangasinan';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';

// ============================================================
// HANDLE CONTENT UPDATE
// ============================================================
if (isset($_POST['update_content']) && $is_admin) {
    try {
        $old_terms_body   = $content['terms']['body']   ?? '';
        $old_privacy_body = $content['privacy']['body'] ?? '';
        $old_terms_title  = $content['terms']['title']  ?? '';
        $old_privacy_title= $content['privacy']['title']?? '';

        if (!empty($_POST['content']) && is_array($_POST['content'])) {
            foreach ($_POST['content'] as $section => $items) {
                foreach ($items as $key => $value) {
                    saveSiteContent($pdo, $section, $key, $value);
                }
            }
        }

        $new_terms_body   = $_POST['content']['terms']['body']   ?? $old_terms_body;
        $new_privacy_body = $_POST['content']['privacy']['body'] ?? $old_privacy_body;
        $new_terms_title  = $_POST['content']['terms']['title']  ?? $old_terms_title;
        $new_privacy_title= $_POST['content']['privacy']['title']?? $old_privacy_title;

        $terms_changed = ($old_terms_body !== $new_terms_body)
                      || ($old_privacy_body !== $new_privacy_body)
                      || ($old_terms_title !== $new_terms_title)
                      || ($old_privacy_title !== $new_privacy_title);

        if (class_exists('SystemLogger')) {
            $sections = array_keys($_POST['content'] ?? []);
            $field_count = 0;
            foreach ($_POST['content'] as $items) {
                if (is_array($items)) $field_count += count($items);
            }

            SystemLogger::log($pdo, 'update', 'content',
                logActor($user_info) . " updated site content ({$field_count} field(s) across " . count($sections) . " section(s): " . implode(', ', $sections) . ")",
                null, 'site_content', null,
                ['sections' => $sections, 'field_count' => $field_count]);

            if ($terms_changed) {
                $new_version = substr(md5(
                    $new_terms_title . $new_terms_body .
                    $new_privacy_title . $new_privacy_body
                ), 0, 8);

                SystemLogger::log($pdo, 'update', 'terms',
                    logActor($user_info) . " updated Terms & Privacy Policy (new version: {$new_version})",
                    null, 'site_content', null,
                    ['new_version' => $new_version]);
            }
        }

        $_SESSION['flash_success'] = "Content updated successfully!";
        header("Location: edit-content.php?fresh=" . time());
        exit();
    } catch (Exception $e) { $error = "Update failed: " . $e->getMessage(); }
}

if (isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out",
            (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

$cache_buster = 'v=' . time() . '_' . rand(1000, 9999);

$current_terms_version = substr(md5(
    ($content['terms']['title'] ?? '') .
    ($content['terms']['body'] ?? '') .
    ($content['privacy']['title'] ?? '') .
    ($content['privacy']['body'] ?? '')
), 0, 8);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Edit Content - <?php echo $is_admin ? 'Admin' : 'Staff'; ?> Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .app-container { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px;
            background: #0B2447;
            box-shadow: 4px 0 20px rgba(0,0,0,0.2);
            padding: 25px 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            border-right: 2px solid rgba(77,166,217,0.15);
            transition: transform 0.3s ease, width 0.3s ease;
            z-index: 100;
            flex-shrink: 0;
        }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77,166,217,0.3); border-radius: 10px; }
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .sidebar-header .logo {
            font-size: 22px; font-weight: 700; color: white;
            text-decoration: none; display: flex; align-items: center; gap: 12px;
            flex: 1; min-width: 0;
        }
        .sidebar-header .logo .logo-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: white; flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77,166,217,0.3);
            overflow: hidden;
        }
        .sidebar-header .logo .logo-icon img {
            width: 100%; height: 100%;
            object-fit: cover; border-radius: 14px;
            background: white;
        }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main {
            font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; letter-spacing: 0.3px; }
        .sidebar-close-btn {
            display: none;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            color: #e0eeff;
            width: 36px; height: 36px;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .sidebar-close-btn:hover {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
            transform: rotate(90deg);
        }
        .sidebar-header .role-badge {
            display: inline-block; margin-top: 12px;
            padding: 4px 14px; border-radius: 20px;
            font-size: 10px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .sidebar-header .role-badge.admin { background: rgba(239,68,68,0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251,191,36,0.2); color: #fbbf24; border: 1px solid rgba(251,191,36,0.2); }
        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 20px; color: #b3d9ff;
            text-decoration: none; transition: all 0.3s;
            border-left: 3px solid transparent;
            font-weight: 500; font-size: 14px;
        }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77,166,217,0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77,166,217,0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
        .nav-link .nav-badge {
            margin-left: auto; background: rgba(239,68,68,0.2);
            color: #ef4444; padding: 1px 10px;
            border-radius: 20px; font-size: 10px; font-weight: 600;
        }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 99;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; opacity: 1; }
        .menu-toggle {
            display: none;
            position: fixed;
            top: 12px; left: 12px;
            z-index: 1001;
            background: #0B2447; color: white;
            border: none; border-radius: 12px;
            width: 48px; height: 48px; font-size: 22px;
            cursor: pointer; transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            align-items: center; justify-content: center;
            border: 1px solid rgba(77,166,217,0.2);
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
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                transform: translateX(-100%);
                width: 280px;
                z-index: 1000;
                box-shadow: none;
                border-radius: 0;
            }
            .sidebar.open {
                transform: translateX(0);
                box-shadow: 4px 0 30px rgba(0,0,0,0.4);
            }
            .menu-toggle { display: flex; }
            .sidebar-overlay.active { display: block; }
            .main-content { padding: 70px 16px 20px !important; }
            .sidebar-close-btn { display: flex; }
        }
        @media (max-width: 480px) {
            .sidebar { width: 85%; max-width: 300px; }
            .menu-toggle {
                width: 42px; height: 42px; font-size: 18px;
                top: 10px; left: 10px; border-radius: 10px;
            }
            .main-content { padding: 60px 12px 16px !important; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
            .nav-link i { font-size: 14px; }
        }
        .main-content {
            flex: 1;
            padding: 20px 30px 30px;
            min-width: 0;
            width: 100%;
            transition: padding 0.3s ease;
        }

        /* TOP BAR — Desktop */
        .top-bar {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(11,36,71,0.1);
            flex-wrap: wrap; gap: 10px;
        }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }

        .top-bar .user-profile .avatar {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 26px;
            border: 3px solid rgba(77, 166, 217, 0.35);
            flex-shrink: 0; overflow: hidden;
            box-shadow: 0 6px 20px rgba(77, 166, 217, 0.35);
        }
        .top-bar .user-profile .avatar img {
            width: 100%; height: 100%;
            object-fit: cover; border-radius: 50%;
        }
        .top-bar .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 15px; }
        .top-bar .user-profile .user-role { color: #4a6a8c; font-size: 13px; }

        .mobile-role-badge,
        .mobile-avatar {
            display: none;
        }

        .mobile-avatar {
            display: none;
            width: 80px; height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 32px;
            border: 4px solid #4DA6D9;
            flex-shrink: 0; overflow: hidden;
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
                font-size: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                gap: 8px;
                margin: 0;
            }
            .top-bar .page-title h1 > i { font-size: 18px; }

            .top-bar .page-title h1 .mobile-role-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
            }
            .top-bar .page-title h1 .mobile-role-badge.admin {
                background: rgba(239, 68, 68, 0.12);
                color: #ef4444;
                border: 1.5px solid rgba(239, 68, 68, 0.25);
            }
            .top-bar .page-title h1 .mobile-role-badge.staff {
                background: rgba(251, 191, 36, 0.15);
                color: #d97706;
                border: 1.5px solid rgba(251, 191, 36, 0.3);
            }

            .top-bar .page-title h1 .mobile-avatar {
                display: inline-flex;
                position: absolute;
                top: 50%;
                right: 14px;
                transform: translateY(-50%);
            }

            .top-bar .page-title p { font-size: 12px; text-align: center; margin: 0; }
            .top-bar .user-profile { display: none !important; }
        }

        @media (max-width: 480px) {
            .top-bar {
                padding-left: 92px;
                padding-right: 92px;
                min-height: 120px;
            }
            .top-bar .page-title h1 { font-size: 17px; gap: 6px; }
            .top-bar .page-title h1 > i { font-size: 15px; }
            .top-bar .page-title p { font-size: 11px; }
            .top-bar .page-title h1 .mobile-avatar {
                width: 72px;
                height: 72px;
                font-size: 28px;
                right: 12px;
            }
            .top-bar .page-title h1 .mobile-role-badge { font-size: 10px; padding: 3px 10px; }
        }

        .page-title-banner {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            border-radius: 20px; padding: 30px 35px;
            margin-bottom: 30px; color: white;
            box-shadow: 0 10px 30px rgba(11,36,71,0.15);
            position: relative; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .page-title-banner::before {
            content: ''; position: absolute;
            top: -50%; right: -50%; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .page-title-banner .banner-content { position: relative; z-index: 1; }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner h1 i { margin-right: 10px; opacity: 0.9; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }
        .page-title-banner p .staff-notice {
            display: inline-block; background: rgba(251,191,36,0.2);
            color: #fbbf24; padding: 2px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 500;
            border: 1px solid rgba(251,191,36,0.2); margin-top: 5px;
        }
        .page-title-banner p .staff-notice i { margin-right: 5px; }
        @media (max-width: 768px) {
            .page-title-banner { padding: 20px; text-align: center; border-radius: 16px; }
            .page-title-banner .underline { margin: 8px auto 0; }
            .page-title-banner h1 { font-size: 22px; }
        }
        @media (max-width: 480px) {
            .page-title-banner { padding: 15px; border-radius: 12px; }
            .page-title-banner h1 { font-size: 18px; }
        }
        .content-section {
            background: white; border-radius: 20px;
            padding: 25px; margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            border: 1px solid #e8f0fe;
        }
        .content-section h2 {
            font-size: 20px; font-weight: 600; color: #4DA6D9;
            margin-bottom: 20px; padding-bottom: 10px;
            border-bottom: 2px solid #e8f0fe;
        }
        .content-section h2 i { margin-right: 10px; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; margin-bottom: 8px;
            font-weight: 600; color: #1e293b; font-size: 14px;
        }
        .form-group label i { color: #4DA6D9; margin-right: 5px; }
        .form-control {
            width: 100%; padding: 12px;
            border: 2px solid #e8f0fe; border-radius: 8px;
            font-size: 14px; transition: all 0.2s; background: #fafafa;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none; border-color: #4DA6D9;
            background: white; box-shadow: 0 0 0 3px rgba(77,166,217,0.1);
        }
        .form-control:disabled { background: #f1f5f9; cursor: not-allowed; opacity: 0.7; }
        textarea.form-control { min-height: 100px; resize: vertical; }
        .form-control-readonly { background: #f1f5f9 !important; cursor: not-allowed !important; opacity: 0.7 !important; }
        .preview-section {
            background: #f8fafc; border-radius: 12px;
            padding: 20px; margin-top: 15px;
            text-align: center;
            border: 2px dashed #e8f0fe;
        }
        .preview-section .image-display {
            display: inline-block; padding: 15px;
            background: white; border-radius: 12px;
            border: 2px solid #e8f0fe; margin-bottom: 15px;
            max-width: 100%;
        }
        .preview-section .image-display img {
            max-width: 100%; max-height: 200px;
            border-radius: 8px; display: block; object-fit: cover;
        }
        .preview-section .image-display .placeholder-icon {
            width: 100%; height: 200px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 60px;
        }
        .preview-section .status {
            display: inline-block; padding: 3px 12px;
            border-radius: 20px; font-size: 12px;
            font-weight: 600; margin-top: 8px;
        }
        .preview-section .status.active { background: #e6f7e6; color: #10b981; }
        .preview-section .status.inactive { background: #fef3c7; color: #f59e0b; }
        .preview-section .actions {
            display: flex; gap: 10px;
            justify-content: center; flex-wrap: wrap; margin-top: 10px;
        }
        .preview-section .actions .btn-upload {
            background: #4DA6D9; color: white; border: none;
            padding: 10px 25px; border-radius: 8px;
            font-weight: 600; cursor: pointer; transition: all 0.3s;
        }
        .preview-section .actions .btn-upload:hover {
            background: #3a8bbf; transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(77,166,217,0.3);
        }
        .preview-section .actions .btn-remove {
            background: #ef4444; color: white; border: none;
            padding: 10px 25px; border-radius: 8px;
            font-weight: 600; cursor: pointer; transition: all 0.3s;
            text-decoration: none; display: inline-flex;
            align-items: center; gap: 6px;
        }
        .preview-section .actions .btn-remove:hover {
            background: #dc2626; transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }
        .preview-section .file-input-wrapper {
            position: relative; overflow: hidden; display: inline-block;
        }
        .preview-section .file-input-wrapper input[type="file"] {
            position: absolute; left: 0; top: 0;
            opacity: 0; width: 100%; height: 100%; cursor: pointer;
        }
        .preview-section .file-input-wrapper .custom-file-label {
            display: inline-block; padding: 10px 25px;
            border: 2px dashed #e8f0fe; border-radius: 8px;
            color: #94a3b8; transition: all 0.3s;
            cursor: pointer; font-weight: 500;
        }
        .preview-section .file-input-wrapper .custom-file-label:hover {
            border-color: #4DA6D9; background: #f0f7fb;
        }
        .preview-section .file-input-wrapper .custom-file-label i { margin-right: 8px; color: #4DA6D9; }
        .preview-section .file-input-wrapper .custom-file-label.has-file {
            border-color: #10b981; background: #f0fdf4; color: #10b981;
        }
        .preview-section .file-name-display { font-size: 13px; color: #64748b; margin-top: 10px; }
        .preview-section .size-info { font-size: 12px; color: #94a3b8; margin-top: 5px; }
        .hero-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .btn-save {
            background: #10b981; color: white; border: none;
            padding: 12px 30px; border-radius: 8px;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: all 0.2s; display: inline-flex;
            align-items: center; gap: 8px;
        }
        .btn-save:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(16,185,129,0.3); }
        .btn-save:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; }
        .btn-save:disabled:hover { background: #cbd5e1; transform: none; box-shadow: none; }
        .btn-back {
            background: #64748b; color: white; border: none;
            padding: 12px 25px; border-radius: 8px;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: all 0.2s; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-back:hover { background: #475569; transform: translateY(-2px); color: white; }
        .action-buttons {
            display: flex; gap: 15px;
            justify-content: flex-end;
            margin-top: 30px; flex-wrap: wrap;
        }
        .alert {
            padding: 15px 20px; border-radius: 12px;
            margin-bottom: 20px; display: flex;
            align-items: center; gap: 10px;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }
        .alert i { font-size: 18px; }
        .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
        h4 { color: #1e293b; margin-bottom: 15px; font-size: 16px; font-weight: 600; }
        .text-muted { color: #64748b; font-size: 12px; margin-top: 5px; }
        .help-text { font-size: 11px; color: #94a3b8; margin-top: 4px; }
        .view-only-banner {
            background: #fef3c7; border: 1px solid #f59e0b;
            border-radius: 12px; padding: 12px 20px;
            margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px; color: #92400e;
        }
        .view-only-banner i { font-size: 20px; color: #f59e0b; }
        .form-section-title {
            font-size: 14px; font-weight: 600; color: #4DA6D9;
            margin: 20px 0 15px; border-bottom: 1px solid #e8f0fe;
            padding-bottom: 10px;
        }
        .footer {
            background: #0B2447; color: #b3d9ff;
            padding: 15px 0; text-align: center;
            margin-top: 30px; border-radius: 12px;
            font-size: 13px;
            border: 1px solid rgba(77,166,217,0.15);
        }
        .footer i { color: #4DA6D9; }
        @media (max-width: 992px) {
            .main-content { padding: 15px; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 12px; }
            .page-title-banner { padding: 20px; text-align: center; border-radius: 16px; }
            .page-title-banner .underline { margin: 8px auto 0; }
            .page-title-banner h1 { font-size: 22px; }
            .content-section { padding: 18px 15px; border-radius: 14px; margin-bottom: 20px; }
            .content-section h2 { font-size: 17px; }
            .row { grid-template-columns: 1fr; gap: 12px; }
            .hero-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column-reverse; }
            .action-buttons .btn-back,
            .action-buttons .btn-save { width: 100%; justify-content: center; }
            .preview-section .image-display img { max-height: 150px; }
            .preview-section .image-display .placeholder-icon { height: 150px; font-size: 48px; }
            .footer { font-size: 11px; padding: 12px 10px; border-radius: 10px; margin-top: 20px; }
        }
        @media (max-width: 480px) {
            .sidebar { width: 85%; max-width: 300px; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
            .nav-link i { font-size: 14px; }
            .top-bar .page-title h1 { font-size: 17px; }
            .page-title-banner { padding: 15px; border-radius: 12px; }
            .page-title-banner h1 { font-size: 18px; }
            .content-section { padding: 15px 12px; border-radius: 12px; }
            .content-section h2 { font-size: 15px; }
            .form-group label { font-size: 13px; }
            .form-control { padding: 10px; font-size: 13px; }
            .preview-section { padding: 15px 12px; }
            .preview-section .image-display { padding: 10px; }
            .preview-section .image-display img { max-height: 120px; }
            .preview-section .image-display .placeholder-icon { height: 120px; font-size: 36px; }
            .preview-section .actions .btn-upload,
            .preview-section .actions .btn-remove {
                padding: 8px 16px;
                font-size: 12px;
            }
            .preview-section .file-input-wrapper .custom-file-label {
                padding: 8px 16px;
                font-size: 12px;
            }
            .footer { font-size: 10px; padding: 10px 8px; border-radius: 8px; margin-top: 15px; }
        }
        .logout-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 36, 71, 0.6);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: logoutFadeIn 0.2s ease;
            overscroll-behavior: contain;
        }
        .logout-modal-overlay.show { display: flex; }
        @keyframes logoutFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .logout-modal {
            background: white;
            border-radius: 24px;
            max-width: 400px;
            width: 100%;
            padding: 35px 30px 25px;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            animation: logoutSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            border-top: 6px solid #ef4444;
            max-height: 90vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        @keyframes logoutSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.9); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .logout-modal-icon {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 18px;
            font-size: 36px; color: #ef4444;
            animation: logoutPulse 2s ease-in-out infinite;
        }
        @keyframes logoutPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); }
        }
        .logout-modal h3 {
            font-size: 22px; font-weight: 700;
            color: #991b1b; margin-bottom: 8px;
        }
        .logout-modal p {
            color: #64748b; font-size: 14px;
            line-height: 1.6; margin-bottom: 25px;
        }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel,
        .btn-logout-confirm {
            flex: 1;
            min-width: 130px;
            min-height: 48px;
            padding: 13px 18px;
            border: none; border-radius: 12px;
            font-weight: 700; font-size: 14px;
            cursor: pointer; transition: all 0.25s;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; text-decoration: none;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }
        .btn-logout-cancel { background: #e2e8f0; color: #475569; }
        .btn-logout-cancel:hover,
        .btn-logout-cancel:active { background: #cbd5e1; transform: translateY(-2px); }
        .btn-logout-confirm {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }
        .btn-logout-confirm:hover,
        .btn-logout-confirm:active {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45);
            color: white;
        }
        @media (max-width: 480px) {
            .logout-modal { padding: 28px 22px 20px; border-radius: 20px; }
            .logout-modal-icon { width: 65px; height: 65px; font-size: 28px; margin-bottom: 14px; }
            .logout-modal h3 { font-size: 19px; }
            .logout-modal p { font-size: 13px; margin-bottom: 20px; }
            .logout-modal-actions { flex-direction: column-reverse; }
            .btn-logout-cancel,
            .btn-logout-confirm { width: 100%; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- HAMBURGER MENU BUTTON -->
<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="app-container">
    <!-- SIDEBAR NAVIGATION -->
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

            <!-- FOOD — walang badge -->
            <li class="nav-item">
                <a href="food-dashboard.php" class="nav-link">
                    <i class="fas fa-utensils"></i><span>Food Management</span>
                </a>
            </li>

            <!-- ✅ BOOKING — badge = pending bookings -->
            <li class="nav-item">
                <a href="booking-management.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i><span>Booking Management</span>
                    <?php if($pending_bookings > 0): ?>
                        <span class="nav-badge" style="background: rgba(245,158,11,0.2); color:#f59e0b;"><?php echo $pending_bookings; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="blocked-dates.php" class="nav-link"><i class="fas fa-ban"></i><span>Blocked Dates</span></a></li>

            <!-- ✅ REVIEWS — badge = PENDING only -->
            <li class="nav-item">
                <a href="reviews-management.php" class="nav-link">
                    <i class="fas fa-star"></i><span>Reviews Management</span>
                    <?php if($pending_reviews > 0): ?>
                        <span class="nav-badge" style="background: rgba(16,185,129,0.2); color:#10b981;"><?php echo $pending_reviews; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Reports</span></a></li>

            <?php if($is_admin): ?>
            <li class="nav-item">
                <a href="edit-content.php" class="nav-link active">
                    <i class="fas fa-edit"></i><span>Edit Content</span>
                </a>
            </li>

            <!-- ✅ SYSTEM LOGS — badge = failed only -->
            <li class="nav-item">
                <a href="system-logs.php" class="nav-link">
                    <i class="fas fa-history"></i><span>System Logs</span>
                    <?php if($log_stats['failed'] > 0): ?>
                        <span class="nav-badge"><?php echo $log_stats['failed']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <div class="nav-divider"></div>
            <li class="nav-item">
                <a href="admin-profile.php" class="nav-link">
                    <i class="fas fa-user-circle"></i><span>My Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link" onclick="openLogoutModal(event); return false;">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">

        <!-- TOP BAR -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-edit"></i> Edit Content
                    <span class="mobile-role-badge <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                        <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo $is_admin ? 'Admin' : 'Staff'; ?>
                    </span>
                    <span class="mobile-avatar" title="<?php echo htmlspecialchars($admin_display_name); ?>">
                        <?php if($admin_avatar): ?>
                            <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $admin_initial; ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <p>Manage website content, logos, hero images, and GCash payment settings</p>
            </div>
            <div class="user-profile">
                <div class="user-info" style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                    <div class="user-role">
                        <?php if($is_admin): ?>
                            <i class="fas fa-crown" style="color: #fbbf24;"></i> Admin
                        <?php else: ?>
                            <i class="fas fa-user-tie" style="color: #fbbf24;"></i> Staff
                        <?php endif; ?>
                    </div>
                </div>
                <div class="avatar">
                    <?php if($admin_avatar): ?>
                        <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo $admin_initial; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if(isset($success)): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <!-- PAGE TITLE BANNER -->
        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-edit"></i> Edit Content</h1>
                <div class="underline"></div>
                <p>
                    Manage website content, logos, hero images, and GCash payment settings
                    <?php if($is_staff): ?>
                        <br><span class="staff-notice"><i class="fas fa-info-circle"></i> You are in View-Only mode. Contact Admin for changes.</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if($is_staff): ?>
        <div class="view-only-banner">
            <i class="fas fa-eye"></i>
            <div>
                <strong>View-Only Mode</strong> — You can view all content but cannot make changes.
                Please contact an Administrator to update content.
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <!-- LOGO SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-image"></i> Site Logo</h2>
                <div class="preview-section">
                    <h5 style="color: #1e293b; font-weight: 600; margin-bottom: 15px;">Current Logo</h5>
                    <div class="image-display">
                        <?php if($logo_exists): ?>
                            <img src="<?php echo htmlspecialchars($logo_path); ?>?<?php echo $cache_buster; ?>" alt="Site Logo" style="max-width: 150px; max-height: 150px;">
                            <div class="status active"><i class="fas fa-check-circle"></i> Logo is active</div>
                        <?php else: ?>
                            <div class="placeholder-icon"><i class="fas fa-home"></i></div>
                            <div class="status inactive"><i class="fas fa-info-circle"></i> No logo uploaded</div>
                        <?php endif; ?>
                    </div>
                    <?php if($is_admin): ?>
                        <div class="actions">
                            <div class="file-input-wrapper">
                                <input type="file" name="logo_image" id="logoInput" accept="image/*">
                                <div class="custom-file-label" id="fileLabel">
                                    <i class="fas fa-upload"></i>
                                    <span id="fileLabelText">Choose logo image</span>
                                </div>
                            </div>
                            <button type="submit" name="upload_logo" class="btn-upload">
                                <i class="fas fa-upload"></i> Upload Logo
                            </button>
                            <?php if($logo_exists): ?>
                                <a href="?remove_logo=1" class="btn-remove" onclick="return confirm('Remove the site logo?')">
                                    <i class="fas fa-trash"></i> Remove Logo
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="file-name-display" id="fileNameDisplay">No file selected</div>
                        <div class="size-info"><i class="fas fa-info-circle"></i> Recommended: Square image, minimum 200x200px. Supports JPG, PNG, GIF, WEBP</div>
                    <?php else: ?>
                        <div style="padding: 10px; background: #f1f5f9; border-radius: 8px; color: #94a3b8;">
                            <i class="fas fa-lock" style="color: #f59e0b;"></i> Only Admin can upload or change the logo
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- HERO IMAGES SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-image"></i> Hero Images</h2>
                <div class="hero-grid">
                    <?php
                    $hero_sections = [
                        ['label' => 'Homepage', 'exists' => $home_hero_exists, 'path' => $home_hero_path, 'input' => 'home_hero_image', 'btn' => 'upload_home_hero', 'remove' => 'remove_home_hero', 'icon' => 'fa-image', 'prefix' => 'homeHero'],
                        ['label' => 'Houses', 'exists' => $houses_hero_exists, 'path' => $houses_hero_path, 'input' => 'houses_hero_image', 'btn' => 'upload_houses_hero', 'remove' => 'remove_houses_hero', 'icon' => 'fa-home', 'prefix' => 'housesHero'],
                        ['label' => 'Tours', 'exists' => $tours_hero_exists, 'path' => $tours_hero_path, 'input' => 'tours_hero_image', 'btn' => 'upload_tours_hero', 'remove' => 'remove_tours_hero', 'icon' => 'fa-umbrella-beach', 'prefix' => 'toursHero'],
                        ['label' => 'Activities', 'exists' => $activities_hero_exists, 'path' => $activities_hero_path, 'input' => 'activities_hero_image', 'btn' => 'upload_activities_hero', 'remove' => 'remove_activities_hero', 'icon' => 'fa-water', 'prefix' => 'activitiesHero'],
                        ['label' => 'Food', 'exists' => $food_hero_exists, 'path' => $food_hero_path, 'input' => 'food_hero_image', 'btn' => 'upload_food_hero', 'remove' => 'remove_food_hero', 'icon' => 'fa-utensils', 'prefix' => 'foodHero'],
                    ];
                    foreach ($hero_sections as $h):
                    ?>
                    <div class="preview-section" style="margin-bottom: 0;">
                        <h5 style="color: #1e293b; font-weight: 600; margin-bottom: 15px; font-size: 14px;"><?php echo $h['label']; ?></h5>
                        <div class="image-display" style="padding: 10px;">
                            <?php if($h['exists']): ?>
                                <img src="<?php echo htmlspecialchars($h['path']); ?>?<?php echo $cache_buster; ?>" alt="<?php echo $h['label']; ?> Hero" style="max-height: 120px;">
                                <div class="status active" style="font-size: 10px;">Active</div>
                            <?php else: ?>
                                <div class="placeholder-icon" style="height: 120px;">
                                    <i class="fas <?php echo $h['icon']; ?>" style="font-size: 40px;"></i>
                                </div>
                                <div class="status inactive" style="font-size: 10px;">No image</div>
                            <?php endif; ?>
                        </div>
                        <?php if($is_admin): ?>
                            <div class="actions" style="gap: 5px;">
                                <div class="file-input-wrapper">
                                    <input type="file" name="<?php echo $h['input']; ?>" id="<?php echo $h['prefix']; ?>Input" accept="image/*">
                                    <div class="custom-file-label" id="<?php echo $h['prefix']; ?>FileLabel" style="padding: 5px 12px; font-size: 11px;">
                                        <i class="fas fa-upload"></i>
                                        <span id="<?php echo $h['prefix']; ?>FileLabelText">Choose</span>
                                    </div>
                                </div>
                                <button type="submit" name="<?php echo $h['btn']; ?>" class="btn-upload" style="padding: 5px 12px; font-size: 11px;">
                                    Upload
                                </button>
                                <?php if($h['exists']): ?>
                                    <a href="?<?php echo $h['remove']; ?>=1" class="btn-remove" style="padding: 5px 12px; font-size: 11px;" onclick="return confirm('Remove <?php echo strtolower($h['label']); ?> hero image?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="file-name-display" id="<?php echo $h['prefix']; ?>FileNameDisplay" style="font-size: 10px;">No file</div>
                        <?php else: ?>
                            <div style="padding: 5px; background: #f1f5f9; border-radius: 8px; color: #94a3b8; font-size: 11px;">
                                <i class="fas fa-lock" style="color: #f59e0b;"></i> Admin only
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- GCASH QR CODE SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-qrcode"></i> GCash QR Code</h2>
                <div class="preview-section">
                    <h5 style="color: #1e293b; font-weight: 600; margin-bottom: 15px;">Current QR Code</h5>
                    <div class="image-display">
                        <?php if($gcash_qr_exists): ?>
                            <img src="<?php echo htmlspecialchars($gcash_qr_path); ?>?<?php echo $cache_buster; ?>" alt="GCash QR Code" style="max-width: 200px; max-height: 200px;">
                            <div class="status active"><i class="fas fa-check-circle"></i> QR Code is active</div>
                        <?php else: ?>
                            <div class="placeholder-icon"><i class="fas fa-qrcode"></i></div>
                            <div class="status inactive"><i class="fas fa-info-circle"></i> No QR Code uploaded</div>
                        <?php endif; ?>
                    </div>
                    <?php if($is_admin): ?>
                        <div class="actions">
                            <div class="file-input-wrapper">
                                <input type="file" name="gcash_qr" id="gcashQrInput" accept="image/*">
                                <div class="custom-file-label" id="gcashQrFileLabel">
                                    <i class="fas fa-upload"></i>
                                    <span id="gcashQrFileLabelText">Choose QR image</span>
                                </div>
                            </div>
                            <button type="submit" name="upload_gcash_qr" class="btn-upload">
                                <i class="fas fa-upload"></i> Upload QR Code
                            </button>
                            <?php if($gcash_qr_exists): ?>
                                <a href="?remove_gcash_qr=1" class="btn-remove" onclick="return confirm('Remove the GCash QR code?')">
                                    <i class="fas fa-trash"></i> Remove QR Code
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="file-name-display" id="gcashQrFileNameDisplay">No file selected</div>
                        <div class="size-info"><i class="fas fa-info-circle"></i> Upload GCash QR code image (PNG, JPG, JPEG, GIF)</div>
                    <?php else: ?>
                        <div style="padding: 10px; background: #f1f5f9; border-radius: 8px; color: #94a3b8;">
                            <i class="fas fa-lock" style="color: #f59e0b;"></i> Only Admin can upload QR code
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- GCASH SETTINGS SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-mobile-alt"></i> GCash Payment Settings</h2>
                <div class="form-group">
                    <label><i class="fas fa-user"></i> GCash Account Name</label>
                    <input type="text" name="content[gcash][account_name]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['gcash']['account_name'] ?? 'Juan Dela Cruz'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>>
                    <div class="text-muted">Name registered in GCash account</div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-mobile-alt"></i> GCash Number</label>
                    <input type="text" name="content[gcash][number]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['gcash']['number'] ?? '09123456789'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>>
                    <div class="text-muted">GCash mobile number (e.g., 09123456789)</div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-info-circle"></i> Payment Instructions</label>
                    <textarea name="content[gcash][instructions]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                              rows="6" <?php echo $is_staff ? 'disabled' : ''; ?>><?php echo htmlspecialchars($content['gcash']['instructions'] ?? "1. Open GCash app\n2. Click 'Pay QR' or 'Scan QR'\n3. Scan the QR code above\n4. Enter the exact amount shown\n5. Complete the payment\n6. Take a screenshot of the transaction\n7. Upload screenshot as proof of payment"); ?></textarea>
                    <div class="text-muted">Instructions for guests on how to pay via GCash (one per line)</div>
                </div>
            </div>

            <!-- HERO SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-star"></i> Hero Section</h2>
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Main Title</label>
                    <input type="text" name="content[hero][title]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['hero']['title'] ?? 'Welcome to Transient House & Tours'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-paragraph"></i> Subtitle</label>
                    <textarea name="content[hero][subtitle]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                              <?php echo $is_staff ? 'disabled' : ''; ?>><?php echo htmlspecialchars($content['hero']['subtitle'] ?? 'Your home away from home and gateway to unforgettable island adventures.'); ?></textarea>
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>Houses Label</label>
                        <input type="text" name="content[hero][stats_houses_label]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['hero']['stats_houses_label'] ?? 'Total Houses'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Houses Available Label</label>
                        <input type="text" name="content[hero][stats_houses_available_label]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['hero']['stats_houses_available_label'] ?? 'Houses Available'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Tours Label</label>
                        <input type="text" name="content[hero][stats_tours_label]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['hero']['stats_tours_label'] ?? 'Total Tours'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Tours Available Label</label>
                        <input type="text" name="content[hero][stats_tours_available_label]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['hero']['stats_tours_available_label'] ?? 'Tours Available'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                </div>
            </div>

            <!-- FEATURES SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-th-large"></i> Features Section</h2>
                <div class="form-group">
                    <label>Section Title</label>
                    <input type="text" name="content[features][section_title]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['features']['section_title'] ?? 'Why Choose Us'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>>
                </div>
                <div class="row">
                    <?php for ($i = 1; $i <= 4; $i++):
                        $defaultTitles = [1 => 'Comfortable Houses', 2 => 'Island Tours', 3 => 'Exciting Activities', 4 => '24/7 Support'];
                        $defaultDescs  = [
                            1 => 'Experience true comfort in our well-appointed transient houses.',
                            2 => 'Explore the beautiful islands with our exciting tour packages.',
                            3 => 'Enjoy banana boat rides, jet skiing, snorkeling, and many more water activities!',
                            4 => "We're always here to help you with any questions or concerns."
                        ];
                    ?>
                    <div>
                        <h4>Feature <?php echo $i; ?></h4>
                        <div class="form-group">
                            <label>Title</label>
                            <input type="text" name="content[features][feature<?php echo $i; ?>_title]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                                   value="<?php echo htmlspecialchars($content['features']['feature'.$i.'_title'] ?? $defaultTitles[$i]); ?>"
                                   <?php echo $is_staff ? 'disabled' : ''; ?>>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="content[features][feature<?php echo $i; ?>_desc]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                                      <?php echo $is_staff ? 'disabled' : ''; ?>><?php echo htmlspecialchars($content['features']['feature'.$i.'_desc'] ?? $defaultDescs[$i]); ?></textarea>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- CTA SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-bullhorn"></i> Call to Action Section</h2>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="content[cta][title]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['cta']['title'] ?? 'Ready to Book Your Stay?'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group">
                    <label>Subtitle</label>
                    <textarea name="content[cta][subtitle]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                              <?php echo $is_staff ? 'disabled' : ''; ?>><?php echo htmlspecialchars($content['cta']['subtitle'] ?? 'Choose from our comfortable houses or exciting tour packages for your next adventure.'); ?></textarea>
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>Houses Button Text</label>
                        <input type="text" name="content[cta][button_houses_text]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['cta']['button_houses_text'] ?? 'Browse Houses'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Tours Button Text</label>
                        <input type="text" name="content[cta][button_tours_text]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['cta']['button_tours_text'] ?? 'Browse Tours'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                </div>
            </div>

            <!-- FOOTER SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-foot"></i> Footer Section</h2>
                <div class="form-group">
                    <label>Company Description</label>
                    <textarea name="content[footer][company_description]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                              <?php echo $is_staff ? 'disabled' : ''; ?>><?php echo htmlspecialchars($content['footer']['company_description'] ?? 'Your trusted partner for comfortable accommodations and exciting island adventures.'); ?></textarea>
                </div>

                <div class="form-section-title">
                    <i class="fas fa-share-alt"></i> Social Media Links
                </div>
                <div class="form-group">
                    <label><i class="fab fa-facebook-f" style="color: #1877f2;"></i> Facebook Page URL</label>
                    <input type="url" name="content[social][facebook]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['social']['facebook'] ?? '#'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>
                           placeholder="https://www.facebook.com/yourpage">
                    <div class="help-text"><i class="fas fa-info-circle"></i> Enter the full URL of your Facebook page. Leave as # if not set.</div>
                </div>

                <div class="form-section-title" style="color: #ef4444;">
                    <i class="fas fa-map-marker-alt"></i> Location Settings
                </div>
                <div class="form-group">
                    <label><i class="fas fa-map-pin"></i> Business Address</label>
                    <input type="text" name="content[location][address]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($location_address); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>
                           placeholder="123 Transient Street, Alaminos City, Pangasinan">
                    <div class="help-text"><i class="fas fa-info-circle"></i> This address will be used for Google Maps link in the footer.</div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-code"></i> Google Maps Embed URL</label>
                    <textarea name="content[location][google_maps_embed]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                              rows="4" <?php echo $is_staff ? 'disabled' : ''; ?>
                              placeholder="https://www.google.com/maps/embed?pb=..."><?php echo htmlspecialchars($google_maps_embed); ?></textarea>
                    <div class="help-text">
                        <i class="fas fa-info-circle"></i>
                        <strong>How to get Google Maps Embed URL:</strong><br>
                        1. Go to <a href="https://www.google.com/maps" target="_blank">Google Maps</a><br>
                        2. Search for your business location<br>
                        3. Click the <strong>"Share"</strong> button<br>
                        4. Click the <strong>"Embed a map"</strong> tab<br>
                        5. Copy the iframe <strong>src</strong> URL (starts with https://www.google.com/maps/embed?pb=...)<br>
                        6. Paste it above. Leave empty to hide the map.
                    </div>
                </div>

                <div style="margin-top: 15px; padding: 15px; background: #f8fafc; border-radius: 12px; border: 1px solid #e8f0fe;">
                    <label style="font-weight: 600; color: #1e293b; margin-bottom: 10px; display: block;">
                        <i class="fas fa-eye"></i> Map Preview
                    </label>
                    <?php if (!empty($google_maps_embed) && $google_maps_embed != '#'): ?>
                        <iframe src="<?php echo htmlspecialchars($google_maps_embed); ?>" width="100%" height="250"
                                style="border:0; border-radius: 10px;" allowfullscreen="" loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"></iframe>
                    <?php else: ?>
                        <div style="text-align: center; padding: 30px; background: #f1f5f9; border-radius: 10px; color: #94a3b8;">
                            <i class="fas fa-map" style="font-size: 40px; display: block; margin-bottom: 10px; color: #cbd5e1;"></i>
                            <p>No map embedded yet. Add the Google Maps Embed URL above.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row">
                    <div class="form-group">
                        <label>Address (Fallback)</label>
                        <input type="text" name="content[footer][address]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['footer']['address'] ?? '123 Transient Street, City'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                        <div class="help-text"><i class="fas fa-info-circle"></i> Fallback address if Location Settings address is empty.</div>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="content[footer][phone]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['footer']['phone'] ?? '+63 912 345 6789'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="content[footer][email]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['footer']['email'] ?? 'info@transientrental.com'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>Copyright Text</label>
                        <input type="text" name="content[footer][copyright]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Transient House & Tours. All rights reserved.'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Privacy Policy Text</label>
                        <input type="text" name="content[footer][privacy_policy]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['footer']['privacy_policy'] ?? 'Privacy Policy'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Terms of Service Text</label>
                        <input type="text" name="content[footer][terms_of_service]" class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                               value="<?php echo htmlspecialchars($content['footer']['terms_of_service'] ?? 'Terms of Service'); ?>"
                               <?php echo $is_staff ? 'disabled' : ''; ?>>
                    </div>
                </div>
            </div>

            <!-- TERMS & PRIVACY SECTION -->
            <div class="content-section">
                <h2><i class="fas fa-file-contract"></i> Terms & Privacy Policy</h2>

                <div class="form-section-title"><i class="fas fa-scroll"></i> Terms & Conditions</div>
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Terms Title</label>
                    <input type="text" name="content[terms][title]"
                           class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['terms']['title'] ?? 'Terms & Conditions'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-paragraph"></i> Terms Content</label>
                    <textarea name="content[terms][body]"
                              class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                              rows="14"
                              <?php echo $is_staff ? 'disabled' : ''; ?>><?php echo htmlspecialchars($content['terms']['body'] ?? 'Welcome to Transient House & Tours. By booking with us, you agree to the following terms:

1. BOOKING & RESERVATIONS
• All bookings are subject to availability and confirmation.
• A valid government-issued ID is required during check-in.
• The lead guest must be at least 18 years old.'); ?></textarea>
                    <div class="help-text"><i class="fas fa-info-circle"></i> Basic HTML is supported.</div>
                </div>

                <div class="form-section-title"><i class="fas fa-shield-alt"></i> Privacy Policy</div>
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Privacy Title</label>
                    <input type="text" name="content[privacy][title]"
                           class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                           value="<?php echo htmlspecialchars($content['privacy']['title'] ?? 'Privacy Policy'); ?>"
                           <?php echo $is_staff ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-paragraph"></i> Privacy Content</label>
                    <textarea name="content[privacy][body]"
                              class="form-control <?php echo $is_staff ? 'form-control-readonly' : ''; ?>"
                              rows="14"
                              <?php echo $is_staff ? 'disabled' : ''; ?>><?php echo htmlspecialchars($content['privacy']['body'] ?? 'Your privacy is important to us. This policy explains what information we collect and how we use it.'); ?></textarea>
                    <div class="help-text"><i class="fas fa-info-circle"></i> Basic HTML is supported.</div>
                </div>

                <div style="background: #f8fafc; border-radius: 10px; padding: 12px 15px; margin-top: 15px; border: 1px solid #e8f0fe;">
                    <div style="font-size: 12px; color: #64748b;">
                        <i class="fas fa-fingerprint" style="color: #4DA6D9;"></i>
                        <strong>Current Version:</strong>
                        <code style="background: #e8f0fe; padding: 2px 8px; border-radius: 4px; color: #0B2447;"><?php echo $current_terms_version; ?></code>
                        <span style="margin-left: 8px;">— When you change the text and save, this version changes, and all users will need to accept the new terms on next login.</span>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <a href="index.php" target="_blank" class="btn-back"><i class="fas fa-eye"></i> Preview Site</a>
                <?php if($is_admin): ?>
                    <button type="submit" name="update_content" class="btn-save"><i class="fas fa-save"></i> Save All Changes</button>
                <?php else: ?>
                    <button type="button" class="btn-save" disabled>
                        <i class="fas fa-lock"></i> Save Disabled (View-Only)
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <div class="footer">
            <p>
                <i class="fas fa-umbrella-beach"></i>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Huddled Islands Tour and Reservation. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i>
                    <?php echo $is_admin ? 'Administrator' : 'Staff'; ?> Access
                </span>
            </p>
        </div>
    </div>
</div>

<!-- LOGOUT CONFIRMATION MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="?logout=1" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
// FILE INPUT PREVIEW HANDLERS
function handleFileInput(inputId, labelId, labelTextId, displayId, defaultLabel) {
    var input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('change', function() {
        var file = this.files && this.files[0];
        var label = document.getElementById(labelId);
        var labelText = document.getElementById(labelTextId);
        var display = document.getElementById(displayId);

        if (file) {
            if (label) label.classList.add('has-file');
            if (labelText) labelText.textContent = file.name;
            if (display) {
                display.textContent = 'Selected: ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            }
        } else {
            if (label) label.classList.remove('has-file');
            if (labelText) labelText.textContent = defaultLabel;
            if (display) display.textContent = 'No file selected';
        }
    });
}

handleFileInput('logoInput', 'fileLabel', 'fileLabelText', 'fileNameDisplay', 'Choose logo image');
handleFileInput('homeHeroInput', 'homeHeroFileLabel', 'homeHeroFileLabelText', 'homeHeroFileNameDisplay', 'Choose');
handleFileInput('housesHeroInput', 'housesHeroFileLabel', 'housesHeroFileLabelText', 'housesHeroFileNameDisplay', 'Choose');
handleFileInput('toursHeroInput', 'toursHeroFileLabel', 'toursHeroFileLabelText', 'toursHeroFileNameDisplay', 'Choose');
handleFileInput('activitiesHeroInput', 'activitiesHeroFileLabel', 'activitiesHeroFileLabelText', 'activitiesHeroFileNameDisplay', 'Choose');
handleFileInput('foodHeroInput', 'foodHeroFileLabel', 'foodHeroFileLabelText', 'foodHeroFileNameDisplay', 'Choose');
handleFileInput('gcashQrInput', 'gcashQrFileLabel', 'gcashQrFileLabelText', 'gcashQrFileNameDisplay', 'Choose QR image');

// SIDEBAR TOGGLE
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');
    if (!sidebar) return;

    const willOpen = !sidebar.classList.contains('open');

    sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('active');
    if (toggleBtn) toggleBtn.classList.toggle('active');

    if (willOpen && window.innerWidth <= 1024) {
        document.body.classList.add('sidebar-open-mobile');
    } else {
        document.body.classList.remove('sidebar-open-mobile');
    }

    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('open')) toggleSidebar();
    }
});

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar && window.innerWidth > 1024 && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('menuToggle');
        if (overlay) overlay.classList.remove('active');
        if (toggleBtn) toggleBtn.classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
        document.body.style.overflow = 'auto';
    }
});

// LOGOUT CONFIRMATION MODAL
function openLogoutModal(event) {
    if (event) event.preventDefault();

    const sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('menuToggle');
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        if (toggleBtn) toggleBtn.classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
    }

    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';

    setTimeout(function() {
        const cancelBtn = document.querySelector('.btn-logout-cancel');
        if (cancelBtn) cancelBtn.focus();
    }, 100);
}

function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) {
            closeLogoutModal();
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeLogoutModal();
            }
        });
    }
});

// AUTO-HIDE ALERTS
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>

</body>
</html>
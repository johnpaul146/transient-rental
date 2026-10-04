<?php
session_start();

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: index.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

require_once 'database.php';
require_once 'includes/sidebar-counts.php';
if (file_exists('includes/SystemLogger.php')) require_once 'includes/SystemLogger.php';

// AUTO-ADD columns if they don't exist (duration + length)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM activities LIKE 'duration'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE activities ADD COLUMN duration VARCHAR(50) DEFAULT NULL AFTER price_note");
        $pdo->exec("ALTER TABLE activities ADD COLUMN duration_unit VARCHAR(20) DEFAULT NULL AFTER duration");
    }
} catch(PDOException $e) {}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM activities LIKE 'length_value'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE activities ADD COLUMN length_value VARCHAR(50) DEFAULT NULL AFTER duration_unit");
        $pdo->exec("ALTER TABLE activities ADD COLUMN length_unit VARCHAR(20) DEFAULT NULL AFTER length_value");
    }
} catch(PDOException $e) {}

// User info
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {}

// Resolve avatar
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

// SIDEBAR BADGE COUNTS



// Get all activities
$activities = [];
try {
    $activities = $pdo->query("SELECT * FROM activities ORDER BY category, name")->fetchAll();
} catch(PDOException $e) {}
$total_activities = count($activities);

if (!function_exists('getActivityMainImage')) {
    function getActivityMainImage($activity) {
        if (!empty($activity['image']) && $activity['image'] != 'default-activity.jpg') {
            if (file_exists('uploads/activities/' . $activity['image'])) {
                return 'uploads/activities/' . $activity['image'];
            }
        }
        return null;
    }
}

// HANDLE ADD
if(isset($_POST['add_activity']) && ($is_admin || $is_staff)) {
    try {
        if (!file_exists('uploads/activities/')) mkdir('uploads/activities/', 0777, true);
        $activity_image = 'default-activity.jpg';
        $image_uploaded = false;
        if(isset($_FILES['activity_image']) && $_FILES['activity_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES["activity_image"]["name"], PATHINFO_EXTENSION));
            $new_filename = time() . '_' . str_replace(' ', '_', $_POST['name']) . '.' . $ext;
            if(move_uploaded_file($_FILES["activity_image"]["tmp_name"], "uploads/activities/" . $new_filename)) {
                $activity_image = $new_filename;
                $image_uploaded = true;
            }
        }

        $hasDuration = !empty($pdo->query("SHOW COLUMNS FROM activities LIKE 'duration'")->fetchAll());
        $hasLength = !empty($pdo->query("SHOW COLUMNS FROM activities LIKE 'length_value'")->fetchAll());

        if ($hasDuration && $hasLength) {
            $stmt = $pdo->prepare("INSERT INTO activities (name, category, description, price, price_unit, price_note, duration, duration_unit, length_value, length_unit, icon, is_featured, status, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['description'], $_POST['price'] ?: 0, $_POST['price_unit'], $_POST['price_note'], $_POST['duration'] ?: null, $_POST['duration_unit'] ?: null, $_POST['length_value'] ?: null, $_POST['length_unit'] ?: null, $_POST['icon'] ?? 'fas fa-star', isset($_POST['is_featured']) ? 1 : 0, $_POST['status'], $activity_image]);
        } elseif ($hasDuration) {
            $stmt = $pdo->prepare("INSERT INTO activities (name, category, description, price, price_unit, price_note, duration, duration_unit, icon, is_featured, status, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['description'], $_POST['price'] ?: 0, $_POST['price_unit'], $_POST['price_note'], $_POST['duration'] ?: null, $_POST['duration_unit'] ?: null, $_POST['icon'] ?? 'fas fa-star', isset($_POST['is_featured']) ? 1 : 0, $_POST['status'], $activity_image]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO activities (name, category, description, price, price_unit, price_note, icon, is_featured, status, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['description'], $_POST['price'] ?: 0, $_POST['price_unit'], $_POST['price_note'], $_POST['icon'] ?? 'fas fa-star', isset($_POST['is_featured']) ? 1 : 0, $_POST['status'], $activity_image]);
        }
        $activity_id = $pdo->lastInsertId();

        if (class_exists('SystemLogger')) {
            $metrics = [];
            if (!empty($_POST['duration'])) $metrics[] = "Duration: {$_POST['duration']} " . ($_POST['duration_unit'] ?? 'minutes');
            if (!empty($_POST['length_value'])) $metrics[] = "Length: {$_POST['length_value']} " . ($_POST['length_unit'] ?? 'meters');
            $desc = "Added new activity: {$_POST['name']} (Category: {$_POST['category']}, Price: ₱" . number_format($_POST['price'] ?: 0, 2) . ")";
            if (!empty($metrics)) $desc .= " — " . implode(', ', $metrics);
            SystemLogger::log($pdo, 'create', 'activity', $desc, $activity_id, 'activity', null, ['name' => $_POST['name'], 'category' => $_POST['category'], 'price' => $_POST['price'] ?: 0, 'status' => $_POST['status'], 'is_featured' => isset($_POST['is_featured']) ? 1 : 0, 'has_image' => $image_uploaded]);
        }

        header("Location: activities-dashboard.php?added=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed to add activity: " . $e->getMessage();
    }
}

// HANDLE EDIT
if(isset($_POST['edit_activity']) && ($is_admin || $is_staff)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM activities WHERE id = ?");
        $stmt->execute([$_POST['activity_id']]);
        $current = $stmt->fetch();
        $activity_image = $current['image'] ?? 'default-activity.jpg';
        $image_action = 'none';

        if(isset($_FILES['activity_image']) && $_FILES['activity_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES["activity_image"]["name"], PATHINFO_EXTENSION));
            $new_filename = time() . '_' . str_replace(' ', '_', $_POST['name']) . '.' . $ext;
            if(move_uploaded_file($_FILES["activity_image"]["tmp_name"], "uploads/activities/" . $new_filename)) {
                if($activity_image && $activity_image != 'default-activity.jpg' && file_exists("uploads/activities/" . $activity_image)) {
                    unlink("uploads/activities/" . $activity_image);
                }
                $activity_image = $new_filename;
                $image_action = 'replaced';
            }
        }

        $hasDuration = !empty($pdo->query("SHOW COLUMNS FROM activities LIKE 'duration'")->fetchAll());
        $hasLength = !empty($pdo->query("SHOW COLUMNS FROM activities LIKE 'length_value'")->fetchAll());

        if ($hasDuration && $hasLength) {
            $stmt = $pdo->prepare("UPDATE activities SET name=?, category=?, description=?, price=?, price_unit=?, price_note=?, duration=?, duration_unit=?, length_value=?, length_unit=?, icon=?, is_featured=?, status=?, image=? WHERE id=?");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['description'], $_POST['price'] ?: 0, $_POST['price_unit'], $_POST['price_note'], $_POST['duration'] ?: null, $_POST['duration_unit'] ?: null, $_POST['length_value'] ?: null, $_POST['length_unit'] ?: null, $_POST['icon'], isset($_POST['is_featured']) ? 1 : 0, $_POST['status'], $activity_image, $_POST['activity_id']]);
        } elseif ($hasDuration) {
            $stmt = $pdo->prepare("UPDATE activities SET name=?, category=?, description=?, price=?, price_unit=?, price_note=?, duration=?, duration_unit=?, icon=?, is_featured=?, status=?, image=? WHERE id=?");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['description'], $_POST['price'] ?: 0, $_POST['price_unit'], $_POST['price_note'], $_POST['duration'] ?: null, $_POST['duration_unit'] ?: null, $_POST['icon'], isset($_POST['is_featured']) ? 1 : 0, $_POST['status'], $activity_image, $_POST['activity_id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE activities SET name=?, category=?, description=?, price=?, price_unit=?, price_note=?, icon=?, is_featured=?, status=?, image=? WHERE id=?");
            $stmt->execute([$_POST['name'], $_POST['category'], $_POST['description'], $_POST['price'] ?: 0, $_POST['price_unit'], $_POST['price_note'], $_POST['icon'], isset($_POST['is_featured']) ? 1 : 0, $_POST['status'], $activity_image, $_POST['activity_id']]);
        }

        if (class_exists('SystemLogger')) {
            $changed = [];
            if ($current && $current['name'] !== $_POST['name']) $changed[] = 'name';
            if ($current && $current['category'] !== $_POST['category']) $changed[] = 'category';
            if ($current && (float)$current['price'] != (float)($_POST['price'] ?: 0)) $changed[] = 'price';
            if ($current && $current['status'] !== $_POST['status']) $changed[] = 'status';
            if ($current && (int)$current['is_featured'] !== (isset($_POST['is_featured']) ? 1 : 0)) $changed[] = 'featured';
            if ($image_action !== 'none') $changed[] = 'image (' . $image_action . ')';
            $desc = "Updated activity: {$_POST['name']}";
            if (!empty($changed)) $desc .= " (changed: " . implode(', ', $changed) . ")";
            SystemLogger::log($pdo, 'update', 'activity', $desc, (int)$_POST['activity_id'], 'activity',
                $current ? ['name' => $current['name'], 'category' => $current['category'], 'price' => $current['price'], 'status' => $current['status']] : null,
                ['name' => $_POST['name'], 'category' => $_POST['category'], 'price' => $_POST['price'] ?: 0, 'status' => $_POST['status']]);
        }

        header("Location: activities-dashboard.php?updated=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed to update activity: " . $e->getMessage();
    }
}

// HANDLE DELETE
if(isset($_GET['delete_activity']) && ($is_admin || $is_staff)) {
    try {
        $stmt = $pdo->prepare("SELECT image, name, category, price FROM activities WHERE id = ?");
        $stmt->execute([$_GET['delete_activity']]);
        $activity = $stmt->fetch();
        if($activity && $activity['image'] && $activity['image'] != 'default-activity.jpg') {
            $image_path = "uploads/activities/" . $activity['image'];
            if(file_exists($image_path)) unlink($image_path);
        }
        $pdo->prepare("DELETE FROM activities WHERE id = ?")->execute([$_GET['delete_activity']]);
        if (class_exists('SystemLogger')) {
            $desc = "Deleted activity: " . ($activity['name'] ?? "ID {$_GET['delete_activity']}");
            if ($activity) $desc .= " (Category: {$activity['category']}, Price: ₱" . number_format($activity['price'], 2) . ")";
            SystemLogger::log($pdo, 'delete', 'activity', $desc, (int)$_GET['delete_activity'], 'activity', null, null, 'warning');
        }
        header("Location: activities-dashboard.php?deleted=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed to delete activity: " . $e->getMessage();
    }
}

// Logout
if(isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth', "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out", (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

// Site content
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) $content[$row['section_name']][$row['content_key']] = $row['content_value'];
} catch(PDOException $e) {}

$nav_logo = 'uploads/logos/logo.png';
if(!empty($content['site_settings']['logo_path'])) $nav_logo = $content['site_settings']['logo_path'];
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Activities Management - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
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
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; overflow: hidden; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .sidebar-header .role-badge.admin { background: rgba(239,68,68,0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251,191,36,0.2); color: #fbbf24; border: 1px solid rgba(251,191,36,0.2); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77,166,217,0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77,166,217,0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
        .nav-link .nav-badge { margin-left: auto; background: rgba(239,68,68,0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }
        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.3); border: 1px solid rgba(77,166,217,0.2); }
        .menu-toggle:hover { background: rgba(77,166,217,0.2); transform: scale(1.05); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8); }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); width: 280px; z-index: 1000; padding-top: 25px; }
            .sidebar.open { transform: translateX(0); box-shadow: 4px 0 30px rgba(0,0,0,0.4); }
            .menu-toggle { display: flex; }
            .sidebar-overlay.active { display: block; }
            .main-content { padding: 70px 16px 20px !important; }
            .sidebar-close-btn { display: flex; }
        }
        @media (max-width: 480px) {
            .sidebar { width: 85%; max-width: 300px; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .main-content { padding: 60px 12px 16px !important; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
        }

        /* MAIN */
        .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; }

        /* TOP BAR */
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11,36,71,0.1); flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
        .top-bar .user-profile .avatar { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg,#4DA6D9,#7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 26px; border: 3px solid rgba(77, 166, 217, 0.35); flex-shrink: 0; overflow: hidden; box-shadow: 0 6px 20px rgba(77, 166, 217, 0.35); }
        .top-bar .user-profile .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .top-bar .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 15px; }
        .top-bar .user-profile .user-role { color: #4a6a8c; font-size: 13px; }

        .mobile-role-badge, .mobile-avatar { display: none; }
        .mobile-avatar { display: none; width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 32px; border: 4px solid #4DA6D9; flex-shrink: 0; overflow: hidden; box-shadow: 0 6px 20px rgba(77, 166, 217, 0.4), 0 0 0 4px rgba(255, 255, 255, 1), 0 0 0 7px rgba(77, 166, 217, 0.4); }
        .mobile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

        @media (max-width: 768px) {
            .top-bar { position: relative; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px; padding-bottom: 15px; padding-left: 100px; padding-right: 100px; min-height: 130px; }
            .top-bar .page-title { display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%; }
            .top-bar .page-title h1 { font-size: 20px; display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 8px; margin: 0; }
            .top-bar .page-title h1 > i { font-size: 18px; }
            .top-bar .page-title h1 .mobile-role-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
            .top-bar .page-title h1 .mobile-role-badge.admin { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1.5px solid rgba(239, 68, 68, 0.25); }
            .top-bar .page-title h1 .mobile-role-badge.staff { background: rgba(251, 191, 36, 0.15); color: #d97706; border: 1.5px solid rgba(251, 191, 36, 0.3); }
            .top-bar .page-title h1 .mobile-avatar { display: inline-flex; position: absolute; top: 50%; right: 14px; transform: translateY(-50%); }
            .top-bar .page-title p { font-size: 12px; text-align: center; margin: 0; }
            .top-bar .user-profile { display: none !important; }
        }
        @media (max-width: 480px) {
            .top-bar { padding-left: 92px; padding-right: 92px; min-height: 120px; }
            .top-bar .page-title h1 { font-size: 17px; gap: 6px; }
            .top-bar .page-title h1 > i { font-size: 15px; }
            .top-bar .page-title p { font-size: 11px; }
            .top-bar .page-title h1 .mobile-avatar { width: 72px; height: 72px; font-size: 28px; right: 12px; }
            .top-bar .page-title h1 .mobile-role-badge { font-size: 10px; padding: 3px 10px; }
        }

        /* PAGE TITLE BANNER */
        .page-title-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11,36,71,0.15); position: relative; overflow: hidden; }
        .page-title-banner::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); animation: rotate 20s linear infinite; }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .page-title-banner .banner-content { position: relative; z-index: 1; }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }
        .page-title-banner p .staff-notice { display: inline-block; background: rgba(251,191,36,0.2); color: #fbbf24; padding: 2px 12px; border-radius: 20px; font-size: 12px; margin-top: 5px; }

        @media (max-width: 768px) {
            .page-title-banner { padding: 20px; text-align: center; border-radius: 16px; }
            .page-title-banner .underline { margin: 8px auto 0; }
            .page-title-banner h1 { font-size: 22px; }
        }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #4DA6D9; border-radius: 16px; padding: 22px 20px; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 10px 30px rgba(77,166,217,0.2); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(77,166,217,0.3); }
        .stat-icon { width: 44px; height: 44px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; }
        .stat-number { font-size: 26px; font-weight: 700; color: white; margin-top: 8px; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 12px; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; border-radius: 12px; }
            .stat-number { font-size: 20px; }
            .stat-icon { width: 36px; height: 36px; font-size: 14px; }
        }

        /* CARDS */
        .card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); margin-bottom: 30px; border: 1px solid #e8f0fe; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
        .card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
        .card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }
        .card-header .header-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        @media (max-width: 768px) {
            .card { padding: 18px 15px; border-radius: 14px; }
            .card-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .card-header h2 { font-size: 15px; }
        }

        /* BUTTONS */
        .btn { padding: 10px 20px; border: none; border-radius: 10px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
        .btn-primary { background: #4DA6D9; color: white; }
        .btn-primary:hover { background: #3a8bbf; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(77,166,217,0.4); }

        /* TABLES */
        .table-container { overflow-x: auto; border-radius: 12px; border: 1px solid #e8f0fe; }
        .activity-table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 1000px; }
        .activity-table thead { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
        .activity-table thead th { padding: 14px 16px; text-align: left; font-weight: 600; color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .activity-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
        .activity-table tbody tr:hover { background: #f8fafc; }
        .activity-table tbody td { padding: 12px 16px; vertical-align: middle; }
        .activity-table .image-cell { width: 80px; }
        .activity-table .image-cell img { width: 70px; height: 55px; object-fit: cover; border-radius: 8px; border: 1px solid #e8f0fe; }
        .activity-table .image-cell .no-image { width: 70px; height: 55px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 20px; border: 1px solid #e8f0fe; }
        .activity-table .name-cell { font-weight: 600; color: #0B2447; }
        .activity-table .desc-cell { font-size: 12px; color: #64748b; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .activity-table .category-cell { display: inline-block; padding: 3px 12px; background: #e8f0fe; border-radius: 20px; font-size: 11px; color: #4DA6D9; font-weight: 500; white-space: nowrap; }
        .activity-table .price-cell { font-weight: 700; color: #0B2447; white-space: nowrap; }
        .activity-table .price-cell small { font-weight: 400; color: #94a3b8; font-size: 11px; }

        .duration-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; background: #dbeafe; color: #1e40af; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .length-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; background: #fef3c7; color: #92400e; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .metric-group { display: flex; flex-wrap: wrap; gap: 4px; }

        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .status-available { background: #dcfce7; color: #16a34a; }
        .status-unavailable { background: #fee2e2; color: #dc2626; }

        /* MOBILE ACTIVITY CARDS */
        .activity-grid.mobile-only { display: none; grid-template-columns: 1fr; gap: 18px; }
        .activity-card { background: #4DA6D9; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.15); transition: all 0.3s ease; border: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; }
        .activity-card:hover { transform: translateY(-6px); box-shadow: 0 15px 40px rgba(77, 166, 217, 0.25); }
        .activity-card .activity-image-wrapper { position: relative; width: 100%; height: 200px; overflow: hidden; background: rgba(255,255,255,0.1); flex-shrink: 0; }
        .activity-card .activity-image { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
        .activity-card:hover .activity-image { transform: scale(1.03); }
        .activity-card .activity-image-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 60px; color: rgba(255,255,255,0.3); }
        .activity-card .featured-badge { position: absolute; top: 12px; left: 12px; background: #F4B400; color: #0B2447; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; z-index: 2; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .activity-card .status-badge-mobile { position: absolute; top: 12px; right: 12px; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; z-index: 2; color: white; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .activity-card .status-badge-mobile.status-available { background: rgba(16, 185, 129, 0.95); }
        .activity-card .status-badge-mobile.status-unavailable { background: rgba(239, 68, 68, 0.95); }

        .activity-card .activity-content { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        .activity-card .activity-content h4 { font-size: 18px; font-weight: 700; color: white; margin-bottom: 4px; line-height: 1.3; }
        .activity-card .activity-content h4 i { margin-right: 6px; opacity: 0.8; font-size: 16px; }
        .activity-card .activity-category { font-size: 13px; color: rgba(255,255,255,0.75); margin-bottom: 10px; display: block; }
        .activity-card .activity-desc { color: rgba(255,255,255,0.9); font-size: 13px; line-height: 1.5; margin-bottom: 12px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 39px; }
        .activity-card .price-tag { font-size: 22px; font-weight: 700; color: #F4B400; margin: 4px 0 8px; line-height: 1.2; }
        .activity-card .price-tag small { font-size: 13px; font-weight: 400; color: rgba(255,255,255,0.7); margin-left: 2px; }
        .activity-card .price-note { font-size: 12px; color: rgba(255,255,255,0.7); font-style: italic; margin-bottom: 8px; }
        .activity-card .metric-group-mobile { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
        .activity-card .metric-badge-mobile { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .activity-card .metric-badge-mobile.duration { background: rgba(255,255,255,0.9); color: #1e40af; }
        .activity-card .metric-badge-mobile.length { background: rgba(255,255,255,0.9); color: #92400e; }
        .activity-card .activity-actions { margin-top: auto; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.12); display: flex; gap: 8px; flex-wrap: wrap; }
        .activity-card .btn-card { flex: 1; min-width: 70px; min-height: 40px; padding: 10px 12px; border: none; border-radius: 10px; font-weight: 700; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; transition: all 0.2s; }
        .activity-card .btn-card:active { transform: scale(0.97); }
        .activity-card .btn-edit-card { background: #f59e0b; color: white; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }
        .activity-card .btn-delete-card { background: #ef4444; color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }

        @media (min-width: 769px) {
            .desktop-table { display: block !important; }
            .activity-grid.mobile-only { display: none !important; }
        }
        @media (max-width: 768px) {
            .desktop-table { display: none !important; }
            .activity-grid.mobile-only { display: grid !important; }
        }

        /* MODALS */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 750px; max-height: 90vh; overflow-y: auto; padding: 30px; animation: modalSlideIn 0.3s ease; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
        @keyframes modalSlideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; margin-bottom: 20px; }
        .modal-header h3 { font-size: 20px; font-weight: 700; color: #0B2447; margin: 0; }
        .modal-header h3 i { margin-right: 10px; color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-group label .required { color: #dc2626; margin-left: 3px; }
        .form-control, .form-select { width: 100%; padding: 10px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77,166,217,0.1); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .form-section-title { font-size: 14px; font-weight: 600; color: #4DA6D9; margin: 20px 0 15px; border-bottom: 1px solid #e8f0fe; padding-bottom: 10px; }
        .form-section-title i { margin-right: 8px; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #4DA6D9; }
        .checkbox-group label { margin-bottom: 0; cursor: pointer; font-weight: 500; font-size: 13px; }
        .help-text { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .metrics-section { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 2px dashed #cbd5e1; border-radius: 16px; padding: 20px; margin: 20px 0; }
        .metrics-section .metrics-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .metrics-section .metrics-title h4 { font-size: 15px; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 8px; margin: 0; }
        .metrics-section .metrics-title h4 i { background: white; padding: 6px; border-radius: 8px; font-size: 13px; border: 1px solid #cbd5e1; color: #64748b; }
        .metrics-section .metrics-title .badge-optional { font-size: 10px; font-weight: 600; background: rgba(100, 116, 139, 0.1); color: #64748b; padding: 2px 10px; border-radius: 20px; text-transform: uppercase; }
        .metrics-section .metrics-hint { font-size: 12px; color: #64748b; margin-bottom: 15px; padding: 8px 12px; background: rgba(255,255,255,0.8); border-radius: 8px; border-left: 3px solid #64748b; }
        .metrics-section .metrics-hint i { color: #64748b; margin-right: 5px; }
        .metric-block-time { background: #eff6ff; border: 2px solid #bfdbfe; border-radius: 12px; padding: 15px; margin-bottom: 12px; }
        .metric-block-time .metric-block-label { color: #1e40af; font-weight: 700; font-size: 13px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
        .metric-block-time .metric-block-label i { color: #3b82f6; }
        .metric-block-length { background: #fffbeb; border: 2px solid #fde68a; border-radius: 12px; padding: 15px; }
        .metric-block-length .metric-block-label { color: #92400e; font-weight: 700; font-size: 13px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
        .metric-block-length .metric-block-label i { color: #f59e0b; }

        .quick-suggestions { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
        .quick-suggestions button { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: 1px solid; }
        .quick-suggestions.blue button { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .quick-suggestions.blue button:hover { background: #3b82f6; color: white; border-color: #3b82f6; }
        .quick-suggestions.amber button { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .quick-suggestions.amber button:hover { background: #f59e0b; color: white; border-color: #f59e0b; }
        .quick-price-suggestions { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px; }
        .quick-price-suggestions button { padding: 3px 10px; background: #eef2ff; color: #4DA6D9; border: 1px solid #dbeafe; border-radius: 20px; font-size: 10px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .quick-price-suggestions button:hover { background: #4DA6D9; color: white; border-color: #4DA6D9; }

        @media (max-width: 768px) {
            .modal-content { padding: 20px; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .modal-content { padding: 15px; max-width: 95%; }
        }

        /* ALERTS */
        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }

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
        .btn-logout-cancel:hover { background: #cbd5e1; }
        .btn-logout-confirm { background: linear-gradient(135deg,#ef4444,#dc2626); color: white; }
        .btn-logout-confirm:hover { box-shadow: 0 8px 25px rgba(239,68,68,0.45); color: white; }

        @media (max-width: 480px) {
            .logout-modal { padding: 28px 22px 20px; }
            .logout-modal-actions { flex-direction: column-reverse; }
            .btn-logout-cancel, .btn-logout-confirm { width: 100%; }
        }

        /* ACTIVITY SEARCH */

.section-subtitle{
    margin-top:5px;
    color:#64748b;
    font-size:13px;
}


.activity-toolbar{
    display:flex;
    align-items:center;
    gap:12px;
}


.activity-search{
    height:45px;
    width:350px;

    display:flex;
    align-items:center;
    gap:10px;

    padding:0 15px;

    background:white;
    border:2px solid #e8f0fe;
    border-radius:12px;
}


.activity-search i{
    color:#4DA6D9;
}


.activity-search input{
    border:none;
    outline:none;
    width:100%;
    font-size:14px;
}



@media(max-width:768px){

    .activity-toolbar{
        flex-direction:column;
        align-items:stretch;
    }


    .activity-search{
        width:100%;
    }

}
            .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="app-container">
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

            <!-- ACTIVITIES — active page natin -->
            <li class="nav-item">
                <a href="activities-dashboard.php" class="nav-link active">
                    <i class="fas fa-water"></i><span>Activities Management</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="food-dashboard.php" class="nav-link">
                    <i class="fas fa-utensils"></i><span>Food Management</span></a>
            </li>

            <li class="nav-item">
                <a href="booking-management.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i><span>Booking Management</span>
                    <?php if($sidebar_pending_bookings > 0): ?>
                        <span class="nav-badge" style="background: rgba(245,158,11,0.2); color:#f59e0b;"><?php echo $sidebar_pending_bookings; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="blocked-dates.php" class="nav-link"><i class="fas fa-ban"></i><span>Blocked Dates</span></a></li>

            <li class="nav-item">
                <a href="reviews-management.php" class="nav-link">
                    <i class="fas fa-star"></i><span>Reviews Management</span>
                    <?php if($sidebar_pending_reviews > 0): ?>
                        <span class="nav-badge" style="background: rgba(16,185,129,0.2); color:#10b981;"><?php echo $sidebar_pending_reviews; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li>

            <?php if($is_admin): ?>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>

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
            <li class="nav-item"><a href="admin-profile.php" class="nav-link"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link" onclick="openLogoutModal(event); return false;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <div class="main-content">
        <!-- TOP BAR -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-water"></i>
                    Activities Management
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
                <p>Manage activities, prices, durations, and lengths</p>
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

        <?php if(isset($_GET['added']) || isset($_GET['updated']) || isset($_GET['deleted'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php
            if(isset($_GET['added'])) echo "Activity added successfully!";
            elseif(isset($_GET['updated'])) echo "Activity updated successfully!";
            else echo "Activity deleted successfully!";
            ?>
        </div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-water"></i> Activities Management</h1>
                <div class="underline"></div>
                <p>
                    Manage activities with prices, durations (minutes), and lengths (meters)
                    <?php if($is_staff): ?>
                        <br><span class="staff-notice"><i class="fas fa-user-tie"></i> Staff Access - Full Management</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>


        <div class="card">
           <div class="card-header">

    <div>
        <h2>
            <i class="fas fa-list"></i>
            Manage Activities
        </h2>

        <p class="section-subtitle">
            Manage activity packages, prices, availability, and details
        </p>
    </div>


    <div class="activity-toolbar">

        <div class="activity-search">
            <i class="fas fa-search"></i>

            <input 
                type="text"
                id="activitySearch"
                placeholder="Search activities..."
                autocomplete="off"
            >
        </div>


        <button class="btn btn-primary" onclick="showModal('addActivity')">
            <i class="fas fa-plus"></i>
            Add New Activity
        </button>

    </div>

</div>

            <?php if(empty($activities)): ?>
                <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                    <i class="fas fa-water" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                    No activities available. Click "Add New Activity" to get started!
                </div>
            <?php else: ?>

            <div class="table-container desktop-table">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>IMAGE</th><th>ACTIVITY NAME</th><th>CATEGORY</th><th>PRICE</th>
                            <th>DURATION / LENGTH</th><th>STATUS</th><th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($activities as $activity):
                            $main_image = getActivityMainImage($activity);
                            $has_duration = !empty($activity['duration']);
                            $has_length = !empty($activity['length_value']);
                        ?>
                        <tr class="activity-row"
    data-search="<?php echo strtolower(htmlspecialchars(
        ($activity['name'] ?? '') . ' ' .
        ($activity['description'] ?? '') . ' ' .
        ($activity['category'] ?? '') . ' ' .
        ($activity['status'] ?? '')
    )); ?>">
                            <td class="image-cell">
                                <?php if($main_image): ?>
                                    <img src="<?php echo htmlspecialchars($main_image); ?>?<?php echo time(); ?>" alt="<?php echo htmlspecialchars($activity['name']); ?>">
                                <?php else: ?>
                                    <div class="no-image"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="name-cell">
                                    <?php echo htmlspecialchars($activity['name']); ?>
                                    <?php if($activity['is_featured']): ?>
                                        <span style="background: #fef3c7; color: #f59e0b; font-size: 9px; padding: 1px 8px; border-radius: 20px; margin-left: 5px;">
                                            <i class="fas fa-star"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if($activity['description']): ?>
                                    <div class="desc-cell"><?php echo htmlspecialchars($activity['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="category-cell"><?php echo htmlspecialchars($activity['category'] ?? 'Other'); ?></span>
                            </td>
                            <td>
                                <div class="price-cell">
                                    ₱<?php echo number_format($activity['price']); ?>
                                    <small><?php echo htmlspecialchars($activity['price_unit'] ?? ''); ?></small>
                                </div>
                                <?php if($activity['price_note']): ?>
                                    <div style="font-size: 10px; color: #94a3b8; max-width: 200px;"><?php echo htmlspecialchars($activity['price_note']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="metric-group">
                                    <?php if($has_duration): ?>
                                        <span class="duration-badge">
                                            <i class="fas fa-clock"></i>
                                            <?php echo htmlspecialchars($activity['duration']); ?>
                                            <?php echo htmlspecialchars($activity['duration_unit'] ?? 'minutes'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if($has_length): ?>
                                        <span class="length-badge">
                                            <i class="fas fa-ruler-horizontal"></i>
                                            <?php echo htmlspecialchars($activity['length_value']); ?>
                                            <?php echo htmlspecialchars($activity['length_unit'] ?? 'meters'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if(!$has_duration && !$has_length): ?>
                                        <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $activity['status'] == 'available' ? 'available' : 'unavailable'; ?>">
                                    <?php echo ucfirst($activity['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                    <button class="btn" style="padding: 4px 12px; font-size: 11px; background: #f59e0b; color: white; font-weight: 600; border-radius: 6px;" onclick='editActivity(<?php echo json_encode($activity); ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="?delete_activity=<?php echo $activity['id']; ?>" class="btn" style="padding: 4px 12px; font-size: 11px; background: #ef4444; color: white; font-weight: 600; border-radius: 6px; text-decoration: none;" onclick="return confirm('Delete this activity?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="activity-grid mobile-only">
                <?php foreach($activities as $activity):
                    $main_image = getActivityMainImage($activity);
                    $has_duration = !empty($activity['duration']);
                    $has_length = !empty($activity['length_value']);
                    $status = $activity['status'] ?? 'available';
                ?>
                <div class="activity-card">
                    <div class="activity-image-wrapper">
                        <?php if($main_image): ?>
                            <img src="<?php echo htmlspecialchars($main_image); ?>?v=<?php echo time(); ?>"
                                 alt="<?php echo htmlspecialchars($activity['name']); ?>"
                                 class="activity-image"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="activity-image-placeholder" style="display:none;">
                                <i class="<?php echo htmlspecialchars($activity['icon'] ?? 'fas fa-star'); ?>"></i>
                            </div>
                        <?php else: ?>
                            <div class="activity-image-placeholder">
                                <i class="<?php echo htmlspecialchars($activity['icon'] ?? 'fas fa-star'); ?>"></i>
                            </div>
                        <?php endif; ?>

                        <?php if($activity['is_featured']): ?>
                        <span class="featured-badge"><i class="fas fa-star"></i> Featured</span>
                        <?php endif; ?>

                        <span class="status-badge-mobile status-<?php echo $status == 'available' ? 'available' : 'unavailable'; ?>">
                            <i class="fas fa-<?php echo $status == 'available' ? 'check-circle' : 'times-circle'; ?>"></i>
                            <?php echo ucfirst($status); ?>
                        </span>
                    </div>

                    <div class="activity-content">
                        <h4><i class="<?php echo htmlspecialchars($activity['icon'] ?? 'fas fa-star'); ?>"></i> <?php echo htmlspecialchars($activity['name']); ?></h4>
                        <span class="activity-category"><?php echo htmlspecialchars($activity['category'] ?? 'Other'); ?></span>

                        <?php if(!empty($activity['description'])): ?>
                        <div class="activity-desc">
                            <?php echo htmlspecialchars($activity['description']); ?>
                        </div>
                        <?php endif; ?>

                        <div class="price-tag">
                            ₱<?php echo number_format($activity['price']); ?>
                            <?php if(!empty($activity['price_unit'])): ?>
                                <small><?php echo htmlspecialchars($activity['price_unit']); ?></small>
                            <?php endif; ?>
                        </div>

                        <?php if(!empty($activity['price_note'])): ?>
                        <div class="price-note">
                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($activity['price_note']); ?>
                        </div>
                        <?php endif; ?>

                        <?php if($has_duration || $has_length): ?>
                        <div class="metric-group-mobile">
                            <?php if($has_duration): ?>
                                <span class="metric-badge-mobile duration">
                                    <i class="fas fa-clock"></i>
                                    <?php echo htmlspecialchars($activity['duration']); ?>
                                    <?php echo htmlspecialchars($activity['duration_unit'] ?? 'minutes'); ?>
                                </span>
                            <?php endif; ?>
                            <?php if($has_length): ?>
                                <span class="metric-badge-mobile length">
                                    <i class="fas fa-ruler-horizontal"></i>
                                    <?php echo htmlspecialchars($activity['length_value']); ?>
                                    <?php echo htmlspecialchars($activity['length_unit'] ?? 'meters'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="activity-actions">
                            <button type="button" class="btn-card btn-edit-card"
                                    onclick='editActivity(<?php echo json_encode($activity); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <a href="?delete_activity=<?php echo $activity['id']; ?>"
                               class="btn-card btn-delete-card"
                               onclick="return confirm('Delete this activity?')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

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

<!-- ADD ACTIVITY MODAL -->
<div class="modal" id="addActivityModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add New Activity</h3>
            <button class="close" onclick="hideModal('addActivity')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-section-title"><i class="fas fa-water"></i> Activity Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label>Activity Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g., Jet Ski, Zipline 345m" required>
                </div>
                <div class="form-group">
                    <label>Category <span class="required">*</span></label>
                    <select name="category" class="form-select" required>
                        <option value="Water Sports">Water Sports</option>
                        <option value="Water Activities">Water Activities</option>
                        <option value="Adventure">Adventure</option>
                        <option value="Entertainment">Entertainment</option>
                        <option value="Sports">Sports</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Price (₱) <span class="required">*</span></label>
                    <input type="number" name="price" class="form-control" step="0.01" required>
                    <div class="quick-price-suggestions">
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=100">₱100</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=200">₱200</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=250">₱250</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=270">₱270</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=350">₱350</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=400">₱400</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=500">₱500</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=1500">₱1,500</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=1800">₱1,800</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=3000">₱3,000</button>
                        <button type="button" onclick="document.querySelector('#addActivityModal input[name=price]').value=5000">₱5,000</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Price Unit</label>
                    <input type="text" name="price_unit" class="form-control" placeholder="per person, per set, per ride" list="price_unit_list">
                    <datalist id="price_unit_list">
                        <option value="per person"><option value="per set"><option value="per ride">
                        <option value="per boat"><option value="per group"><option value="per hour">
                    </datalist>
                </div>
            </div>

            <div class="form-group">
                <label>Price Note</label>
                <input type="text" name="price_note" class="form-control" placeholder="e.g., Includes life vest, Good for 3-6 people">
            </div>

            <div class="metrics-section">
                <div class="metrics-title">
                    <h4><i class="fas fa-sliders-h"></i> Activity Metrics</h4>
                    <span class="badge-optional">Optional — fill what applies</span>
                </div>
                <div class="metrics-hint">
                    <i class="fas fa-lightbulb"></i> Use <strong>Duration</strong> for time-based activities (Jet Ski, Kayaking). Use <strong>Length</strong> for distance-based activities (Zipline).
                </div>

                <div class="metric-block-time">
                    <div class="metric-block-label"><i class="fas fa-clock"></i> Duration (Time-Based)</div>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <input type="number" name="duration" class="form-control" step="1" placeholder="e.g., 15, 30, 60" style="background: white;">
                            <div class="quick-suggestions blue">
                                <button type="button" onclick="document.querySelector('#addActivityModal input[name=duration]').value=15">15</button>
                                <button type="button" onclick="document.querySelector('#addActivityModal input[name=duration]').value=30">30</button>
                                <button type="button" onclick="document.querySelector('#addActivityModal input[name=duration]').value=60">60</button>
                                <button type="button" onclick="document.querySelector('#addActivityModal input[name=duration]').value=120">120</button>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <select name="duration_unit" class="form-select" style="background: white;">
                                <option value="minutes">minutes</option>
                                <option value="hours">hours</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="metric-block-length">
                    <div class="metric-block-label"><i class="fas fa-ruler-horizontal"></i> Length (Distance-Based)</div>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <input type="number" name="length_value" class="form-control" step="1" placeholder="e.g., 120, 345, 546" style="background: white;">
                            <div class="quick-suggestions amber">
                                <button type="button" onclick="document.querySelector('#addActivityModal input[name=length_value]').value=120">120</button>
                                <button type="button" onclick="document.querySelector('#addActivityModal input[name=length_value]').value=345">345</button>
                                <button type="button" onclick="document.querySelector('#addActivityModal input[name=length_value]').value=546">546</button>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <select name="length_unit" class="form-select" style="background: white;">
                                <option value="meters">meters</option>
                                <option value="kilometers">kilometers</option>
                                <option value="feet">feet</option>
                                <option value="miles">miles</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label>Icon</label>
                    <input type="text" name="icon" class="form-control" value="fas fa-star">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-select">
                        <option value="available">Available</option>
                        <option value="unavailable">Unavailable</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: center; justify-content: center;">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_featured" value="1">
                        <label><i class="fas fa-star" style="color: #f59e0b;"></i> Featured</label>
                    </div>
                </div>
            </div>

            <div class="form-section-title"><i class="fas fa-image"></i> Activity Image</div>
            <div class="form-group">
                <label>Main Image</label>
                <input type="file" name="activity_image" class="form-control" accept="image/*">
            </div>

            <button type="submit" name="add_activity" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <i class="fas fa-save"></i> Add Activity
            </button>
        </form>
    </div>
</div>

<!-- EDIT ACTIVITY MODAL -->
<div class="modal" id="editActivityModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Activity</h3>
            <button class="close" onclick="hideModal('editActivity')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editForm">
            <input type="hidden" name="activity_id" id="edit_activity_id">

            <div class="form-section-title"><i class="fas fa-water"></i> Activity Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label>Activity Name <span class="required">*</span></label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category <span class="required">*</span></label>
                    <select name="category" id="edit_category" class="form-select" required>
                        <option value="Water Sports">Water Sports</option>
                        <option value="Water Activities">Water Activities</option>
                        <option value="Adventure">Adventure</option>
                        <option value="Entertainment">Entertainment</option>
                        <option value="Sports">Sports</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Price (₱) <span class="required">*</span></label>
                    <input type="number" name="price" id="edit_price" class="form-control" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Price Unit</label>
                    <input type="text" name="price_unit" id="edit_price_unit" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>Price Note</label>
                <input type="text" name="price_note" id="edit_price_note" class="form-control">
            </div>

            <div class="metrics-section">
                <div class="metrics-title">
                    <h4><i class="fas fa-sliders-h"></i> Activity Metrics</h4>
                    <span class="badge-optional">Optional</span>
                </div>

                <div class="metric-block-time">
                    <div class="metric-block-label"><i class="fas fa-clock"></i> Duration (Time-Based)</div>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <input type="number" name="duration" id="edit_duration" class="form-control" step="1" style="background: white;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <select name="duration_unit" id="edit_duration_unit" class="form-select" style="background: white;">
                                <option value="minutes">minutes</option>
                                <option value="hours">hours</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="metric-block-length">
                    <div class="metric-block-label"><i class="fas fa-ruler-horizontal"></i> Length (Distance-Based)</div>
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <input type="number" name="length_value" id="edit_length_value" class="form-control" step="1" style="background: white;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <select name="length_unit" id="edit_length_unit" class="form-select" style="background: white;">
                                <option value="meters">meters</option>
                                <option value="kilometers">kilometers</option>
                                <option value="feet">feet</option>
                                <option value="miles">miles</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label>Icon</label>
                    <input type="text" name="icon" id="edit_icon" class="form-control">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-select">
                        <option value="available">Available</option>
                        <option value="unavailable">Unavailable</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: center; justify-content: center;">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_featured" id="edit_is_featured" value="1">
                        <label for="edit_is_featured"><i class="fas fa-star" style="color: #f59e0b;"></i> Featured</label>
                    </div>
                </div>
            </div>

            <div class="form-section-title"><i class="fas fa-image"></i> Activity Image</div>
            <div class="form-group">
                <label>Main Image</label>
                <input type="file" name="activity_image" class="form-control" accept="image/*">
                <div class="help-text">Upload a new image to replace the current one</div>
            </div>

            <button type="submit" name="edit_activity" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <i class="fas fa-save"></i> Update Activity
            </button>
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
            <a href="?logout=1" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');
    const willOpen = !sidebar.classList.contains('open');

    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    toggleBtn.classList.toggle('active');

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
        if (sidebar.classList.contains('open')) toggleSidebar();
        const lm = document.getElementById('logoutModal');
        if (lm && lm.classList.contains('show')) closeLogoutModal();
    }
});

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 1024 && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.getElementById('menuToggle').classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
        document.body.style.overflow = 'auto';
    }
});

function showModal(type) {
    document.getElementById(type + 'Modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function hideModal(type) {
    document.getElementById(type + 'Modal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

window.onclick = function(event) {
    if(event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

function editActivity(activity) {
    document.getElementById('edit_activity_id').value = activity.id;
    document.getElementById('edit_name').value = activity.name;
    document.getElementById('edit_category').value = activity.category || 'Other';
    document.getElementById('edit_description').value = activity.description || '';
    document.getElementById('edit_price').value = activity.price;
    document.getElementById('edit_price_unit').value = activity.price_unit || '';
    document.getElementById('edit_price_note').value = activity.price_note || '';
    document.getElementById('edit_duration').value = activity.duration || '';
    document.getElementById('edit_duration_unit').value = activity.duration_unit || 'minutes';
    document.getElementById('edit_length_value').value = activity.length_value || '';
    document.getElementById('edit_length_unit').value = activity.length_unit || 'meters';
    document.getElementById('edit_icon').value = activity.icon || 'fas fa-star';
    document.getElementById('edit_status').value = activity.status || 'available';
    document.getElementById('edit_is_featured').checked = activity.is_featured == 1;
    showModal('editActivity');
}

function openLogoutModal(event) {
    if (event) event.preventDefault();
    const sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.getElementById('menuToggle').classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
    }
    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) modal.addEventListener('click', function(e) { if (e.target === this) closeLogoutModal(); });
});

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);

// LIVE ACTIVITY SEARCH

document.addEventListener("DOMContentLoaded", function(){

    const searchInput = document.getElementById("activitySearch");
    const rows = document.querySelectorAll(".activity-row");


    if(!searchInput) return;


    searchInput.addEventListener("input", function(){

        const keyword = this.value.toLowerCase().trim();


        rows.forEach(row => {

            const data = row.dataset.search;


            if(data.includes(keyword)){
                row.style.display = "";
            }
            else{
                row.style.display = "none";
            }

        });

    });

});
</script>

</body>
</html>
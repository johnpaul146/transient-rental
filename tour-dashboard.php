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

$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {}

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

// ============================================================
// ✅ SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================




$tours = [];
try {
    $tours = $pdo->query("SELECT * FROM tours ORDER BY id DESC")->fetchAll();
} catch(PDOException $e) {}
$total_tours = count($tours);

// ============================================================
// PARSE PLACES TO VISIT
// Format: Place Name|image_path (one per line)
// ============================================================
function parsePlacesToVisit($raw) {
    $places = [];
    if (empty($raw)) return $places;
    $lines = preg_split('/[\r\n]+/', $raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '|') !== false) {
            $parts = explode('|', $line, 2);
            $name = trim($parts[0]);
            $image = trim($parts[1]);
            if ($name !== '') $places[] = ['name' => $name, 'image' => $image];
        } else {
            $places[] = ['name' => $line, 'image' => ''];
        }
    }
    return $places;
}


function getTourMainImage($tour) {
    $image = $tour['image'] ?? '';
    $folder_name = !empty($tour['folder_name']) ? $tour['folder_name'] : str_replace(' ', '_', trim($tour['tour_name']));
    $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    if (empty($image) || $image == 'default-tour.jpg') {
        $gallery_path = 'uploads/tours/gallery/' . $folder_name . '/';
        if (file_exists($gallery_path)) {
            foreach (scandir($gallery_path) as $file) {
                if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $exts)) {
                    return 'uploads/tours/gallery/' . $folder_name . '/' . $file;
                }
            }
        }
        return null;
    }

    if (file_exists('uploads/tours/' . $image)) return 'uploads/tours/' . $image;
    if (file_exists('uploads/tours/gallery/' . $folder_name . '/' . $image))
        return 'uploads/tours/gallery/' . $folder_name . '/' . $image;

    $gallery_path = 'uploads/tours/gallery/' . $folder_name . '/';
    if (file_exists($gallery_path)) {
        foreach (scandir($gallery_path) as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $exts)) {
                return 'uploads/tours/gallery/' . $folder_name . '/' . $file;
            }
        }
    }
    return null;
}

function getBoatType($n) {
    if ($n <= 5) return 'Small Boat';
    if ($n <= 10) return 'Medium Boat';
    if ($n <= 15) return 'Large Boat';
    return 'Deluxe Boat';
}
function getBoatCapacityLabel($n) {
    if ($n <= 5) return '1-5 PAX';
    if ($n <= 10) return '6-10 PAX';
    if ($n <= 15) return '11-15 PAX';
    return '16-20 PAX';
}
function getBoatBadgeClass($n) {
    if ($n <= 5) return 'boat-badge-small';
    if ($n <= 10) return 'boat-badge-medium';
    if ($n <= 15) return 'boat-badge-large';
    return 'boat-badge-deluxe';
}
function createTourFolder($tour_name) {
    $folder_name = str_replace(' ', '_', trim($tour_name));
    $path = 'uploads/tours/gallery/' . $folder_name . '/';
    if (!file_exists('uploads/tours/gallery/')) mkdir('uploads/tours/gallery/', 0777, true);
    if (!file_exists($path)) mkdir($path, 0777, true);
    return $folder_name;
}


// ============================================================
// HANDLE ADD TOUR
// ============================================================
if(isset($_POST['add_tour']) && ($is_admin || $is_staff)) {
    try {
        if (!file_exists('uploads/tours/')) mkdir('uploads/tours/', 0777, true);

        $boat_name = trim($_POST['tour_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price_per_boat = (float)($_POST['price_per_boat'] ?? 0);
        $boat_capacity = (int)($_POST['boat_capacity'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        $allowed_statuses = ['available', 'fully_booked', 'seasonal'];
        if (!in_array($status, $allowed_statuses, true)) $status = 'available';

        if ($boat_name === '') throw new Exception('Boat name is required.');
        if ($price_per_boat < 0) throw new Exception('Boat price cannot be negative.');
        if (!in_array($boat_capacity, [5, 10, 15, 20], true)) throw new Exception('Please select a valid boat capacity.');

        $tour_image = 'default-tour.jpg';
        $folder_name = createTourFolder($boat_name);

        if(isset($_FILES['tour_image']) && $_FILES['tour_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['tour_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                $new_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $boat_name) . '.' . $ext;
                if(move_uploaded_file($_FILES['tour_image']['tmp_name'], 'uploads/tours/gallery/' . $folder_name . '/' . $new_filename)) {
                    $tour_image = $new_filename;
                }
            }
        }

        // Legacy places_to_visit data is intentionally not used by the new Boat Information UI.
        // Keep required legacy columns populated for compatibility with the existing schema.
        $stmt = $pdo->prepare("INSERT INTO tours (tour_name, tour_type, description, duration_hours, price_per_boat, max_guests, status, image, boat_capacity, folder_name) VALUES (?, '', ?, 0, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$boat_name, $description, $price_per_boat, $boat_capacity, $status, $tour_image, $boat_capacity, $folder_name]);
        $tour_id = $pdo->lastInsertId();

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'create', 'tour', "Added new boat: {$boat_name}", $tour_id, 'tour');
        }
        $_SESSION['flash_success'] = 'Boat added successfully!';
        header('Location: tour-dashboard.php');
        exit();
    } catch(Exception $e) {
        $error = 'Failed to add boat: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE EDIT TOUR
// ============================================================
if(isset($_POST['edit_tour']) && ($is_admin || $is_staff)) {
    try {
        $tour_id = (int)($_POST['tour_id'] ?? 0);
        $boat_name = trim($_POST['tour_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price_per_boat = (float)($_POST['price_per_boat'] ?? 0);
        $boat_capacity = (int)($_POST['boat_capacity'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        $allowed_statuses = ['available', 'fully_booked', 'seasonal'];
        if (!in_array($status, $allowed_statuses, true)) $status = 'available';

        if ($tour_id <= 0) throw new Exception('Invalid boat record.');
        if ($boat_name === '') throw new Exception('Boat name is required.');
        if ($price_per_boat < 0) throw new Exception('Boat price cannot be negative.');
        if (!in_array($boat_capacity, [5, 10, 15, 20], true)) throw new Exception('Please select a valid boat capacity.');

        $old_tour = $pdo->prepare('SELECT tour_name, folder_name, image FROM tours WHERE id = ?');
        $old_tour->execute([$tour_id]);
        $old_data = $old_tour->fetch();
        if (!$old_data) throw new Exception('Boat record not found.');

        $new_folder_name = str_replace(' ', '_', $boat_name);
        $tour_image = $old_data['image'] ?? 'default-tour.jpg';

        if (($old_data['folder_name'] ?? '') !== $new_folder_name) {
            $old_path = 'uploads/tours/gallery/' . ($old_data['folder_name'] ?? '') . '/';
            $new_path = 'uploads/tours/gallery/' . $new_folder_name . '/';
            if (is_dir($old_path) && $old_path !== $new_path) {
                if (!is_dir('uploads/tours/gallery/')) mkdir('uploads/tours/gallery/', 0777, true);
                if (!file_exists($new_path)) @rename($old_path, $new_path);
            }
        }
        if (!is_dir('uploads/tours/gallery/' . $new_folder_name . '/')) {
            @mkdir('uploads/tours/gallery/' . $new_folder_name . '/', 0777, true);
        }

        if(isset($_FILES['tour_image']) && $_FILES['tour_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['tour_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                $new_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $boat_name) . '.' . $ext;
                $target_folder = 'uploads/tours/gallery/' . $new_folder_name . '/';
                if(move_uploaded_file($_FILES['tour_image']['tmp_name'], $target_folder . $new_filename)) {
                    $tour_image = $new_filename;
                }
            }
        }

        // Do not overwrite places_to_visit: old island data remains preserved but is no longer exposed in the active UI.
        $stmt = $pdo->prepare('UPDATE tours SET tour_name=?, description=?, price_per_boat=?, max_guests=?, status=?, boat_capacity=?, folder_name=?, image=? WHERE id=?');
        $stmt->execute([$boat_name, $description, $price_per_boat, $boat_capacity, $status, $boat_capacity, $new_folder_name, $tour_image, $tour_id]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'update', 'tour', "Updated boat: {$boat_name}", $tour_id, 'tour');
        }
        $_SESSION['flash_success'] = 'Boat updated successfully!';
        header('Location: tour-dashboard.php');
        exit();
    } catch(Exception $e) {
        $error = 'Failed to update boat: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE DELETE TOUR
// ============================================================
if(isset($_GET['delete_tour']) && ($is_admin || $is_staff)) {
    try {
        $tour = $pdo->prepare("SELECT tour_name, folder_name, places_to_visit FROM tours WHERE id = ?");
        $tour->execute([$_GET['delete_tour']]);
        $folder = $tour->fetch();

        if ($folder && !empty($folder['places_to_visit'])) {
            $places = parsePlacesToVisit($folder['places_to_visit']);
            foreach ($places as $p) {
                if (!empty($p['image']) && file_exists($p['image'])) {
                    @unlink($p['image']);
                }
            }
        }

        if ($folder && $folder['folder_name']) {
            $folder_path = 'uploads/tours/gallery/' . $folder['folder_name'] . '/';
            if (file_exists($folder_path)) {
                foreach (scandir($folder_path) as $file) {
                    if ($file != '.' && $file != '..') unlink($folder_path . $file);
                }
                rmdir($folder_path);
            }
        }
        $pdo->prepare("DELETE FROM tours WHERE id = ?")->execute([$_GET['delete_tour']]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'delete', 'tour', "Deleted boat: " . ($folder['tour_name'] ?? "ID {$_GET['delete_tour']}"), (int)$_GET['delete_tour'], 'tour', null, null, 'warning');
        }
        $_SESSION['flash_success'] = "Boat deleted successfully!";
        header("Location: tour-dashboard.php");
        exit();
    } catch(Exception $e) {
        $error = "Failed to delete boat: " . $e->getMessage();
    }
}

// ============================================================
// HANDLE LOGOUT
// ============================================================
if(isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth', "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out", (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

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
    <title>Tour Management - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }
        .app-container { display: flex; min-height: 100vh; }

        .sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); transition: transform 0.3s ease; z-index: 100; flex-shrink: 0; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }

        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; overflow: hidden; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; }

        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; transition: all 0.2s; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }

        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
        .nav-link .nav-badge { margin-left: auto; background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 99; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.3); align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8); }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); width: 280px; z-index: 1000; box-shadow: none; padding-top: 25px; }
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
            .nav-link i { font-size: 14px; }
        }

        .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; transition: padding 0.3s ease; }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11, 36, 71, 0.1); flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }

        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
        .top-bar .user-profile .avatar { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 26px; border: 3px solid rgba(77, 166, 217, 0.35); flex-shrink: 0; overflow: hidden; box-shadow: 0 6px 20px rgba(77, 166, 217, 0.35); }
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
            .top-bar .page-title h1 .mobile-role-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
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

        .page-title-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15); position: relative; overflow: hidden; }
        .page-title-banner::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); animation: rotate 20s linear infinite; }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .page-title-banner .banner-content { position: relative; z-index: 1; }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }
        .page-title-banner p .staff-notice { display: inline-block; background: rgba(251, 191, 36, 0.2); color: #fbbf24; padding: 2px 12px; border-radius: 20px; font-size: 12px; margin-top: 5px; }

        @media (max-width: 768px) {
            .page-title-banner { padding: 20px; text-align: center; border-radius: 16px; }
            .page-title-banner .underline { margin: 8px auto 0; }
            .page-title-banner h1 { font-size: 22px; }
        }

        .stat-card { background: #4DA6D9; border-radius: 16px; padding: 22px 20px; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2); transition: transform 0.3s, box-shadow 0.3s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(77, 166, 217, 0.3); }
        .stat-icon { width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; }
        .stat-number { font-size: 28px; font-weight: 700; color: white; margin-top: 10px; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 13px; margin-top: 2px; }

        @media (max-width: 768px) {
            .stat-card { padding: 16px 14px; border-radius: 12px; }
            .stat-number { font-size: 22px; }
            .stat-icon { width: 40px; height: 40px; font-size: 16px; }
        }

        .card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); margin-bottom: 30px; border: 1px solid #e8f0fe; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
        .card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
        .card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }
        .card-header .header-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .card-header a { color: #4DA6D9; text-decoration: none; font-weight: 500; font-size: 13px; }

        @media (max-width: 768px) {
            .card { padding: 18px 15px; border-radius: 14px; }
            .card-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .card-header h2 { font-size: 15px; }
        }

        .btn { padding: 10px 20px; border: none; border-radius: 10px; font-weight: 500; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
        .btn-primary { background: #4DA6D9; color: white; }
        .btn-primary:hover { background: #3a8bbf; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(77, 166, 217, 0.4); }

        .action-buttons { display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; }
        .btn-action { padding: 6px 14px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; gap: 5px; text-decoration: none; white-space: nowrap; min-width: 60px; }
        .btn-action:hover { transform: translateY(-2px); }
        .btn-edit { background: #f59e0b; color: white; }
        .btn-edit:hover { background: #d97706; box-shadow: 0 3px 10px rgba(245, 158, 11, 0.3); }
        .btn-delete { background: #ef4444; color: white; }
        .btn-delete:hover { background: #dc2626; box-shadow: 0 3px 10px rgba(239, 68, 68, 0.3); }

        @media (max-width: 480px) {
            .btn-action { padding: 3px 8px; font-size: 10px; min-width: 40px; }
            .btn-action span { display: none; }
        }

        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th { text-align: left; padding: 10px 12px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-danger { background: #fee2e2; color: #ef4444; }
        .badge-info { background: #dbeafe; color: #3b82f6; }
        .boat-badge-small { background: #d1fae5; color: #065f46; }
        .boat-badge-medium { background: #dbeafe; color: #1e40af; }
        .boat-badge-large { background: #fef3c7; color: #92400e; }
        .boat-badge-deluxe { background: #fee2e2; color: #991b1b; }

        .tour-grid.mobile-only { display: none; grid-template-columns: 1fr; gap: 18px; }

        .tour-card { background: #4DA6D9; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.15); transition: all 0.3s ease; border: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; }
        .tour-card:hover { transform: translateY(-6px); box-shadow: 0 15px 40px rgba(77, 166, 217, 0.25); }
        .tour-card .tour-image-wrapper { position: relative; width: 100%; height: 200px; overflow: hidden; background: rgba(255,255,255,0.1); flex-shrink: 0; }
        .tour-card .tour-image { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease; }
        .tour-card:hover .tour-image { transform: scale(1.03); }
        .tour-card .tour-image-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 60px; color: rgba(255,255,255,0.3); }

        .tour-card .boat-badge { position: absolute; top: 12px; left: 12px; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .tour-card .status-badge { position: absolute; top: 12px; right: 12px; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; color: white; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .tour-card .status-badge.status-available { background: rgba(16, 185, 129, 0.95); }
        .tour-card .status-badge.status-fully_booked { background: rgba(239, 68, 68, 0.95); }
        .tour-card .status-badge.status-seasonal { background: rgba(245, 158, 11, 0.95); }

        .tour-card .tour-content { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        .tour-card .tour-content h4 { font-size: 18px; font-weight: 700; color: white; margin-bottom: 8px; line-height: 1.3; }
        .tour-card .tour-content h4 i { margin-right: 6px; opacity: 0.8; font-size: 16px; }
        .tour-card .tour-meta { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 10px; }
        .tour-card .tour-meta .meta-item { display: flex; align-items: center; gap: 5px; color: rgba(255,255,255,0.9); font-size: 13px; }
        .tour-card .tour-meta .meta-item i { color: white; font-size: 13px; opacity: 0.85; }

        .tour-card .tour-desc { color: rgba(255,255,255,0.9); font-size: 13px; line-height: 1.5; margin-bottom: 12px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 39px; }
        .tour-card .price-tag { font-size: 22px; font-weight: 700; color: #F4B400; margin: 4px 0 10px; line-height: 1.2; }
        .tour-card .price-tag small { font-size: 13px; font-weight: 400; color: rgba(255,255,255,0.7); margin-left: 2px; }

        .tour-card .tour-actions { margin-top: auto; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.12); display: flex; gap: 8px; flex-wrap: wrap; }
        .tour-card .btn-card { flex: 1; min-width: 70px; min-height: 40px; padding: 10px 12px; border: none; border-radius: 10px; font-weight: 700; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; transition: all 0.2s; }
        .tour-card .btn-card:active { transform: scale(0.97); }
        .tour-card .btn-edit-card { background: #f59e0b; color: white; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }
        .tour-card .btn-delete-card { background: #ef4444; color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }

        .booking-cards-mobile { display: none; flex-direction: column; gap: 12px; }
        .booking-card-mobile { background: white; border-radius: 14px; padding: 14px; border: 1px solid #e8f0fe; box-shadow: 0 4px 12px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 10px; }
        .booking-card-mobile .card-top-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .booking-card-mobile .card-ref { font-weight: 700; color: #0B2447; font-size: 13px; word-break: break-all; }
        .booking-card-mobile .card-badges { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0; }
        .booking-card-mobile .card-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #475569; }
        .booking-card-mobile .card-row i { width: 16px; color: #4DA6D9; flex-shrink: 0; text-align: center; }
        .booking-card-mobile .card-row .card-label { color: #94a3b8; font-size: 10px; font-weight: 700; min-width: 46px; text-transform: uppercase; }
        .booking-card-mobile .card-row .card-value { font-weight: 600; color: #0B2447; word-break: break-word; flex: 1; min-width: 0; }

        @media (min-width: 769px) {
            .desktop-table { display: block !important; }
            .tour-grid.mobile-only { display: none !important; }
            .booking-cards-mobile { display: none !important; }
        }
        @media (max-width: 768px) {
            .desktop-table { display: none !important; }
            .tour-grid.mobile-only { display: grid !important; }
            .booking-cards-mobile { display: flex !important; }
        }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; padding: 30px; animation: modalSlideIn 0.3s ease; }
        @keyframes modalSlideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-lg { max-width: 800px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; margin-bottom: 20px; }
        .modal-header h3 { font-size: 20px; font-weight: 700; color: #0B2447; margin: 0; }
        .modal-header h3 i { margin-right: 10px; color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-group label .required { color: #dc2626; }
        .form-control, .form-select { width: 100%; padding: 10px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-section-title { font-size: 14px; font-weight: 600; color: #4DA6D9; margin: 20px 0 15px; border-bottom: 1px solid #e8f0fe; padding-bottom: 10px; }

        .current-image-preview { margin-top: 10px; padding: 10px; background: #f8fafc; border-radius: 10px; border: 1px dashed #cbd5e1; text-align: center; }
        .current-image-preview img { max-width: 100%; max-height: 150px; border-radius: 8px; object-fit: cover; }
        .current-image-preview .label { font-size: 11px; color: #64748b; margin-bottom: 6px; font-weight: 600; text-transform: uppercase; }
        .current-image-preview .no-image { color: #94a3b8; font-size: 12px; padding: 20px; font-style: italic; }

        @media (max-width: 768px) {
            .modal-content { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
        }

        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }

        .footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15); }
        .footer i { color: #4DA6D9; }
        @media (max-width: 768px) { .footer { font-size: 11px; padding: 12px 10px; } }

        .tour-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; border: 2px solid #e8f0fe; background: #f1f5f9; }

        .logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; }
        .logout-modal-overlay.show { display: flex; }
        .logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); border-top: 6px solid #ef4444; }
        .logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; }
        .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .logout-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-logout-cancel { background: #e2e8f0; color: #475569; }
        .btn-logout-cancel:hover { background: #cbd5e1; }
        .btn-logout-confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .btn-logout-confirm:hover { box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45); color: white; }

        @media (max-width: 480px) {
            .logout-modal { padding: 28px 22px 20px; }
            .logout-modal-actions { flex-direction: column-reverse; }
            .btn-logout-cancel, .btn-logout-confirm { width: 100%; }
        }

        .tour-header{
    flex-direction:column;
    align-items:stretch;
}


.section-subtitle{
    margin-top:5px;
    color:#64748b;
    font-size:13px;
}


.tour-toolbar{
    display:flex;
    gap:12px;
    align-items:center;
    justify-content:space-between;
}


.tour-search{
    flex:1;
    max-width:450px;
    height:45px;
    background:white;
    border:2px solid #e8f0fe;
    border-radius:12px;
    display:flex;
    align-items:center;
    padding:0 15px;
    gap:10px;
}


.tour-search i{
    color:#4DA6D9;
}


.tour-search input{
    border:none;
    outline:none;
    width:100%;
    font-size:14px;
}


@media(max-width:768px){

    .tour-toolbar{
        flex-direction:column;
        align-items:stretch;
    }


    .tour-search{
        max-width:none;
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
            <li class="nav-item"><a href="tour-dashboard.php" class="nav-link active"><i class="fas fa-umbrella-beach"></i><span>Tour Management</span></a></li>
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

            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li>
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
            <li class="nav-item"><a href="admin-profile.php" class="nav-link"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link" onclick="openLogoutModal(event); return false;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <div class="main-content">

        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-umbrella-beach"></i>
                    Tour Management
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
                <p>Manage boat information, pricing, capacity, and availability</p>
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

        <?php if(isset($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
        <?php endif; ?>

        <?php if(isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-umbrella-beach"></i> Tour Management</h1>
                <div class="underline"></div>
                <p>
                    Manage boat information, pricing, capacity, and availability
                    <?php if($is_staff): ?>
                        <br><span class="staff-notice"><i class="fas fa-user-tie"></i> Staff Access - Full Management</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>


        <div class="card">
           <div class="card-header tour-header">

    <div>
        <h2>
            <i class="fas fa-list"></i>
            Manage Boats
        </h2>

        <p class="section-subtitle">
            Maintain boat name, description, capacity, price, destinations, and availability
        </p>
    </div>


    <div class="tour-toolbar">

        <div class="tour-search">
            <i class="fas fa-search"></i>
            <input 
                type="text" 
                id="tourSearch"
                placeholder="Search boats..."
            >
        </div>


        <button class="btn btn-primary" onclick="showModal('addTour')">
            <i class="fas fa-plus"></i>
            Add New Boat
        </button>

    </div>

</div>

            <?php if(count($tours) > 0): ?>

            <div class="table-responsive desktop-table">
                <table>
                    <thead>
                        <tr>
                            <th>Image</th><th>Boat Name</th><th>Boat Price</th>
                            <th>Boat Capacity</th><th>Destinations</th><th>Availability</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($tours as $tour):
                            $boat_type = getBoatType($tour['max_guests']);
                            $boat_label = getBoatCapacityLabel($tour['max_guests']);
                            $badge_class = getBoatBadgeClass($tour['max_guests']);
                            $main_image = getTourMainImage($tour);
                        ?>
                        <tr class="tour-row"
    data-search="<?php echo strtolower(htmlspecialchars(
        ($tour['tour_name'] ?? '') . ' ' .
        ($tour['description'] ?? '') . ' ' .
        ($tour['status'] ?? '')
    )); ?>">
                            <td>
                                <?php if($main_image): ?>
                                    <img src="<?php echo htmlspecialchars($main_image); ?>" class="tour-thumb"
                                         onerror="this.src='https://via.placeholder.com/60x60/4DA6D9/ffffff?text=Tour'">
                                <?php else: ?>
                                    <div style="width:60px;height:60px;background:#f1f5f9;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#94a3b8;">
                                        <i class="fas fa-umbrella-beach"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($tour['tour_name']); ?></strong></td>
                            <td>₱<?php echo number_format($tour['price_per_boat'] ?? $tour['price_per_person'] ?? 0); ?></td>
                            <td>
                                <span class="badge <?php echo $badge_class; ?>" style="font-size: 11px;">
                                    <i class="fas fa-ship"></i> <?php echo $boat_type; ?>
                                    <br><small><?php echo $boat_label; ?></small>
                                </span>
                            </td>
                            <td><span class="badge badge-info"><i class="fas fa-map-marked-alt"></i> 12–14 islands</span></td>
                            <td>
                                <span class="badge <?php echo $tour['status'] == 'available' ? 'badge-success' : ($tour['status'] == 'fully_booked' ? 'badge-danger' : 'badge-warning'); ?>">
                                    <i class="fas fa-<?php echo $tour['status'] == 'available' ? 'check-circle' : ($tour['status'] == 'fully_booked' ? 'times-circle' : 'calendar-alt'); ?>"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $tour['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action btn-edit" onclick='editTour(<?php echo htmlspecialchars(json_encode($tour), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-edit"></i> <span>Edit</span>
                                    </button>
                                    <a href="?delete_tour=<?php echo $tour['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this boat? Existing related records may also be affected.')">
                                        <i class="fas fa-trash"></i> <span>Delete</span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="tour-grid mobile-only">
                <?php foreach($tours as $tour):
                    $boat_type = getBoatType($tour['max_guests']);
                    $boat_label = getBoatCapacityLabel($tour['max_guests']);
                    $badge_class = getBoatBadgeClass($tour['max_guests']);
                    $main_image = getTourMainImage($tour);
                    $status = $tour['status'] ?? 'available';
                ?>
                <div class="tour-card">
                    <div class="tour-image-wrapper">
                        <?php if($main_image): ?>
                            <img src="<?php echo htmlspecialchars($main_image); ?>?v=<?php echo time(); ?>"
                                 alt="<?php echo htmlspecialchars($tour['tour_name']); ?>"
                                 class="tour-image"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="tour-image-placeholder" style="display:none;">
                                <i class="fas fa-umbrella-beach"></i>
                            </div>
                        <?php else: ?>
                            <div class="tour-image-placeholder">
                                <i class="fas fa-umbrella-beach"></i>
                            </div>
                        <?php endif; ?>

                        <span class="boat-badge <?php echo $badge_class; ?>">
                            <i class="fas fa-ship"></i> <?php echo $boat_type; ?>
                        </span>

                        <span class="status-badge status-<?php echo $status; ?>">
                            <i class="fas fa-<?php echo $status == 'available' ? 'check-circle' : ($status == 'fully_booked' ? 'times-circle' : 'calendar-alt'); ?>"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                        </span>

                    </div>

                    <div class="tour-content">
                        <h4><i class="fas fa-umbrella-beach"></i> <?php echo htmlspecialchars($tour['tour_name']); ?></h4>

                        <div class="tour-meta">
                            <div class="meta-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo $boat_label; ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-map-marked-alt"></i>
                                <span>12–14 islands</span>
                            </div>
                        </div>


                        <?php if(!empty($tour['description'])): ?>
                        <div class="tour-desc">
                            <?php echo htmlspecialchars(substr($tour['description'], 0, 120)) . (strlen($tour['description']) > 120 ? '...' : ''); ?>
                        </div>
                        <?php endif; ?>

                        <div class="price-tag">
                            ₱<?php echo number_format($tour['price_per_boat'] ?? $tour['price_per_person'] ?? 0); ?> <small>/boat</small>
                        </div>

                        <div class="tour-actions">
                            <button type="button" class="btn-card btn-edit-card"
                                    onclick='editTour(<?php echo htmlspecialchars(json_encode($tour), ENT_QUOTES, "UTF-8"); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <a href="?delete_tour=<?php echo $tour['id']; ?>"
                               class="btn-card btn-delete-card"
                               onclick="return confirm('Delete this boat? Existing related records may also be affected.')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>
            <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                <i class="fas fa-umbrella-beach" style="font-size: 48px; margin-bottom: 15px; color: #cbd5e1; display:block;"></i>
                <p>No boats found. Click "Add New Boat" to get started!</p>
            </div>
            <?php endif; ?>
        </div>

     

        <div class="footer">
            <p>
                <i class="fas fa-umbrella-beach"></i>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Hundred Islands Tour and Reservation. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i>
                    <?php echo $is_admin ? 'Administrator' : 'Staff'; ?> Access
                </span>
            </p>
        </div>
    </div>
</div>

<!-- ADD BOAT MODAL -->
<div class="modal" id="addTourModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add New Boat</h3>
            <button class="close" onclick="hideModal('addTour')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="addTourForm">
            <div class="form-section-title"><i class="fas fa-ship"></i> Boat Information</div>

            <div class="form-group">
                <label>Boat Name <span class="required">*</span></label>
                <input type="text" name="tour_name" class="form-control" required maxlength="200">
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="4" placeholder="Describe the boat and service."></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Boat Capacity <span class="required">*</span></label>
                    <select name="boat_capacity" class="form-select" required>
                        <option value="5">Small Boat (1–5 PAX)</option>
                        <option value="10">Medium Boat (6–10 PAX)</option>
                        <option value="15">Large Boat (11–15 PAX)</option>
                        <option value="20">Deluxe Boat (16–20 PAX)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Boat Price (₱) <span class="required">*</span></label>
                    <input type="number" name="price_per_boat" class="form-control" min="0" step="0.01" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Destinations</label>
                    <input type="text" class="form-control" value="12–14 islands" readonly>
                    <div style="font-size:11px;color:#64748b;margin-top:5px;"><i class="fas fa-lock"></i> Fixed destination coverage requested by the client.</div>
                </div>
                <div class="form-group">
                    <label>Availability</label>
                    <select name="status" class="form-select">
                        <option value="available">Available</option>
                        <option value="fully_booked">Fully Booked</option>
                        <option value="seasonal">Seasonal</option>
                    </select>
                </div>
            </div>

            <div class="form-section-title"><i class="fas fa-image"></i> Main Boat Image</div>
            <div class="form-group">
                <label>Boat Image</label>
                <input type="file" name="tour_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                <div style="font-size:11px;color:#64748b;margin-top:5px;"><i class="fas fa-info-circle"></i> This is the boat photo only. Individual island photos are no longer used.</div>
            </div>

            <button type="submit" name="add_tour" class="btn btn-primary" style="width:100%;">
                <i class="fas fa-save"></i> Add Boat
            </button>
        </form>
    </div>
</div>

<!-- EDIT BOAT MODAL -->
<div class="modal" id="editTourModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Boat</h3>
            <button class="close" onclick="hideModal('editTour')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editTourForm">
            <input type="hidden" name="tour_id" id="edit_tour_id">

            <div class="form-section-title"><i class="fas fa-ship"></i> Boat Information</div>

            <div class="form-group">
                <label>Boat Name <span class="required">*</span></label>
                <input type="text" name="tour_name" id="edit_tour_name" class="form-control" required maxlength="200">
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description" class="form-control" rows="4"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Boat Capacity <span class="required">*</span></label>
                    <select name="boat_capacity" id="edit_boat_capacity" class="form-select" required>
                        <option value="5">Small Boat (1–5 PAX)</option>
                        <option value="10">Medium Boat (6–10 PAX)</option>
                        <option value="15">Large Boat (11–15 PAX)</option>
                        <option value="20">Deluxe Boat (16–20 PAX)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Boat Price (₱) <span class="required">*</span></label>
                    <input type="number" name="price_per_boat" id="edit_price" class="form-control" min="0" step="0.01" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Destinations</label>
                    <input type="text" class="form-control" value="12–14 islands" readonly>
                    <div style="font-size:11px;color:#64748b;margin-top:5px;"><i class="fas fa-lock"></i> Individual island names and photos are intentionally hidden from the active workflow.</div>
                </div>
                <div class="form-group">
                    <label>Availability</label>
                    <select name="status" id="edit_status" class="form-select">
                        <option value="available">Available</option>
                        <option value="fully_booked">Fully Booked</option>
                        <option value="seasonal">Seasonal</option>
                    </select>
                </div>
            </div>

            <div class="form-section-title"><i class="fas fa-image"></i> Main Boat Image</div>
            <div class="form-group">
                <label>Current Main Image</label>
                <div class="current-image-preview" id="current_main_image_preview"></div>
            </div>
            <div class="form-group">
                <label>Replace Main Image</label>
                <input type="file" name="tour_image" id="edit_tour_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                <div style="font-size:11px;color:#94a3b8;margin-top:4px;"><i class="fas fa-info-circle"></i> Leave empty to keep the existing boat image.</div>
            </div>

            <button type="submit" name="edit_tour" class="btn btn-primary" style="width:100%;">
                <i class="fas fa-save"></i> Update Boat
            </button>
        </form>
    </div>
</div>

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
// SIDEBAR TOGGLE
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

// MODALS
function showModal(type) {
    document.getElementById(type + 'Modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function hideModal(type) {
    document.getElementById(type + 'Modal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// EDIT TOUR
function editTour(tour) {
    document.getElementById('edit_tour_id').value = tour.id;
    document.getElementById('edit_tour_name').value = tour.tour_name;
    document.getElementById('edit_price').value = tour.price_per_boat || tour.price_per_person || 0;
    document.getElementById('edit_boat_capacity').value = tour.max_guests;
    document.getElementById('edit_status').value = tour.status;
    document.getElementById('edit_description').value = tour.description || '';

    const previewContainer = document.getElementById('current_main_image_preview');
    const folder = tour.folder_name || tour.tour_name.replace(/ /g, '_');
    let imgSrc = 'uploads/tours/' + (tour.image || 'default-tour.jpg');
    if (tour.image && tour.image !== 'default-tour.jpg') {
        imgSrc = 'uploads/tours/gallery/' + folder + '/' + tour.image;
    }

    if (tour.image && tour.image !== 'default-tour.jpg') {
        previewContainer.innerHTML = `
            <div class="label"><i class="fas fa-image"></i> Current Main Image</div>
            <img src="${imgSrc}?t=${Date.now()}" alt="Current Main Image"
                 onerror="this.onerror=null; this.src='uploads/tours/${tour.image}';">
        `;
    } else {
        previewContainer.innerHTML = `
            <div class="label"><i class="fas fa-image"></i> Current Main Image</div>
            <div class="no-image">No main image uploaded</div>
        `;
    }
    document.getElementById('edit_tour_image').value = '';


    showModal('editTour');
}

window.onclick = function(event) {
    if(event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

// LOGOUT MODAL
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

// LIVE TOUR SEARCH

document.addEventListener("DOMContentLoaded", function(){

    const searchInput = document.getElementById("tourSearch");
    const rows = document.querySelectorAll(".tour-row");

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
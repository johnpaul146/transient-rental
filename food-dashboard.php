<?php
// Food category: required, stored as a simple slug (column is VARCHAR(50) after migration 2026_10)
if (!function_exists('normalizeFoodCategory')) {
    function normalizeFoodCategory($value) {
        $c = strtolower(trim((string)$value));
        $c = preg_replace('/[^a-z0-9]+/', '_', $c);
        $c = trim((string)$c, '_');
        if ($c === '') throw new Exception('Please choose a category.');
        return substr($c, 0, 50);
    }
}

session_start();
require_once 'database.php';
require_once 'includes/sidebar-counts.php';

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: index.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

// SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// User info
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) { $user_info = []; }

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

// ============================================================
// ✅ SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================




// ============================================================
// SIZE VARIATIONS
// ============================================================
function getSizeVariations($category) {
    if ($category == 'bilao') {
        return [
            ['size' => 'Small', 'pax' => '2-5 PAX', 'price' => 1500],
            ['size' => 'Medium', 'pax' => '5-8 PAX', 'price' => 2000],
            ['size' => 'Large', 'pax' => '8-12 PAX', 'price' => 3000],
            ['size' => 'XLarge', 'pax' => '12-15 PAX', 'price' => 3500]
        ];
    } elseif ($category == 'boodle_special') {
        return [
            ['size' => '6 PAX', 'pax' => '6 PAX', 'price' => 4000],
            ['size' => '10 PAX', 'pax' => '10 PAX', 'price' => 5500],
            ['size' => '15 PAX', 'pax' => '15 PAX', 'price' => 7500],
            ['size' => '20 PAX', 'pax' => '20 PAX', 'price' => 9000]
        ];
    }
    return [];
}

// ============================================================
// GALLERY HELPERS
// ============================================================
function saveGalleryImages($files, $food_id, $food_name) {
    $saved = [];
    $upload_dir = 'uploads/foods/gallery/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

    $food_folder = str_replace(' ', '_', trim($food_name)) . '_' . $food_id;
    $food_upload_dir = $upload_dir . $food_folder . '/';
    if (!file_exists($food_upload_dir)) mkdir($food_upload_dir, 0777, true);

    if (isset($files['gallery_images']) && is_array($files['gallery_images']['name'])) {
        $total_files = count($files['gallery_images']['name']);
        for ($i = 0; $i < $total_files; $i++) {
            if ($files['gallery_images']['error'][$i] == 0) {
                $ext = strtolower(pathinfo($files['gallery_images']['name'][$i], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $filename = time() . '_' . $i . '_' . str_replace(' ', '_', $food_name) . '.' . $ext;
                    if (move_uploaded_file($files['gallery_images']['tmp_name'][$i], $food_upload_dir . $filename)) {
                        $saved[] = $filename;
                    }
                }
            }
        }
    }
    return $saved;
}

function getGalleryImages($food_id, $food_name) {
    $images = [];
    $food_folder = str_replace(' ', '_', trim($food_name)) . '_' . $food_id;
    $gallery_path = 'uploads/foods/gallery/' . $food_folder . '/';
    if (file_exists($gallery_path)) {
        foreach (scandir($gallery_path) as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'])) {
                $images[] = $file;
            }
        }
    }
    return $images;
}

// ============================================================
// HANDLE ADD FOOD ITEM
// ============================================================
if(isset($_POST['add_food']) && ($is_admin || $is_staff)) {
    try {
        if (!file_exists('uploads/foods/')) mkdir('uploads/foods/', 0777, true);

        $food_image = 'default-food.jpg';
        if(isset($_FILES['food_image']) && $_FILES['food_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES["food_image"]["name"], PATHINFO_EXTENSION));
            $new_filename = time() . '_' . str_replace(' ', '_', $_POST['name']) . '.' . $ext;
            if(move_uploaded_file($_FILES["food_image"]["tmp_name"], "uploads/foods/" . $new_filename)) {
                $food_image = $new_filename;
            }
        }

        $category = normalizeFoodCategory(!empty($_POST['category_custom']) ? $_POST['category_custom'] : ($_POST['category'] ?? ''));

        $sizes = [];
        if (isset($_POST['sizes']) && is_array($_POST['sizes'])) {
            foreach ($_POST['sizes'] as $size_data) {
                if (!empty($size_data['size']) && isset($size_data['price']) && $size_data['price'] !== '') {
                    $sizes[] = ['size' => $size_data['size'], 'pax' => $size_data['pax'] ?? '', 'price' => floatval($size_data['price'])];
                }
            }
        }
        if (empty($sizes)) $sizes = getSizeVariations($category);

        $price = !empty($sizes) ? $sizes[0]['price'] : 0;
        $size_variations = !empty($sizes) ? json_encode($sizes) : null;

        $stmt = $pdo->prepare("INSERT INTO food_items (name, description, price, category, image, is_available, is_featured, inclusions, size_variations) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['name'], $_POST['description'], $price, $category, $food_image,
            isset($_POST['is_available']) ? 1 : 0,
            isset($_POST['is_featured']) ? 1 : 0,
            $_POST['inclusions'] ?? null, $size_variations
        ]);
        $food_id = $pdo->lastInsertId();

        $gallery_images = [];
        if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
            $gallery_images = saveGalleryImages($_FILES, $food_id, $_POST['name']);
            if (!empty($gallery_images)) {
                $pdo->prepare("UPDATE food_items SET gallery_images = ? WHERE id = ?")
                    ->execute([json_encode($gallery_images), $food_id]);
            }
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'create', 'food', "Added food item: {$_POST['name']}", $food_id, 'food');
        }

        $_SESSION['flash_success'] = "Food item added successfully!";
        header("Location: food-dashboard.php");
        exit();
    } catch(Exception $e) {
        $error = "Failed to add food: " . $e->getMessage();
    }
}

// ============================================================
// HANDLE EDIT FOOD
// ============================================================
if(isset($_POST['edit_food']) && ($is_admin || $is_staff)) {
    try {
        $category = normalizeFoodCategory(!empty($_POST['category_custom']) ? $_POST['category_custom'] : ($_POST['category'] ?? ''));

        $sizes = [];
        if (isset($_POST['sizes']) && is_array($_POST['sizes'])) {
            foreach ($_POST['sizes'] as $size_data) {
                if (!empty($size_data['size']) && isset($size_data['price']) && $size_data['price'] !== '') {
                    $sizes[] = ['size' => $size_data['size'], 'pax' => $size_data['pax'] ?? '', 'price' => floatval($size_data['price'])];
                }
            }
        }
        if (empty($sizes)) $sizes = getSizeVariations($category);

        $price = !empty($sizes) ? $sizes[0]['price'] : 0;
        $size_variations = !empty($sizes) ? json_encode($sizes) : null;

        $stmt = $pdo->prepare("UPDATE food_items SET name=?, description=?, price=?, category=?, is_available=?, is_featured=?, inclusions=?, size_variations=? WHERE id=?");
        $stmt->execute([
            $_POST['name'], $_POST['description'], $price, $category,
            isset($_POST['is_available']) ? 1 : 0,
            isset($_POST['is_featured']) ? 1 : 0,
            $_POST['inclusions'] ?? null, $size_variations, $_POST['food_id']
        ]);

        if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
            $new_gallery = saveGalleryImages($_FILES, $_POST['food_id'], $_POST['name']);
            if (!empty($new_gallery)) {
                $stmt = $pdo->prepare("SELECT gallery_images FROM food_items WHERE id = ?");
                $stmt->execute([$_POST['food_id']]);
                $existing = $stmt->fetch();
                $existing_gallery = !empty($existing['gallery_images']) ? json_decode($existing['gallery_images'], true) : [];
                $all_gallery = array_merge($existing_gallery, $new_gallery);
                $pdo->prepare("UPDATE food_items SET gallery_images = ? WHERE id = ?")
                    ->execute([json_encode($all_gallery), $_POST['food_id']]);
            }
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'update', 'food', "Updated food item: {$_POST['name']}", (int)$_POST['food_id'], 'food');
        }

        $_SESSION['flash_success'] = "Food item updated successfully!";
        header("Location: food-dashboard.php");
        exit();
    } catch(Exception $e) {
        $error = "Failed to update food: " . $e->getMessage();
    }
}

// ============================================================
// HANDLE DELETE GALLERY IMAGE
// ============================================================
if(isset($_GET['delete_gallery_image']) && ($is_admin || $is_staff)) {
    try {
        $food_id = $_GET['food_id'];
        $image = $_GET['delete_gallery_image'];

        $stmt = $pdo->prepare("SELECT name, gallery_images FROM food_items WHERE id = ?");
        $stmt->execute([$food_id]);
        $food = $stmt->fetch();

        if ($food) {
            $food_folder = str_replace(' ', '_', trim($food['name'])) . '_' . $food_id;
            $file_path = 'uploads/foods/gallery/' . $food_folder . '/' . $image;
            if (file_exists($file_path)) unlink($file_path);

            $gallery = !empty($food['gallery_images']) ? json_decode($food['gallery_images'], true) : [];
            $gallery = array_diff($gallery, [$image]);
            $pdo->prepare("UPDATE food_items SET gallery_images = ? WHERE id = ?")
                ->execute([json_encode(array_values($gallery)), $food_id]);

            if (class_exists('SystemLogger')) {
                SystemLogger::log($pdo, 'delete', 'food', "Deleted gallery image for food: {$food['name']}", $food_id, 'food', null, null, 'warning');
            }

            header("Location: food-dashboard.php?gallery_deleted=1");
            exit();
        }
    } catch(Exception $e) {
        $error = "Failed to delete gallery image: " . $e->getMessage();
    }
}

// ============================================================
// HANDLE DELETE FOOD ITEM
// ============================================================
if(isset($_GET['delete_food']) && ($is_admin || $is_staff)) {
    try {
        $stmt = $pdo->prepare("SELECT image, name, gallery_images FROM food_items WHERE id = ?");
        $stmt->execute([$_GET['delete_food']]);
        $food = $stmt->fetch();

        if($food) {
            if($food['image'] && $food['image'] != 'default-food.jpg') {
                $image_path = "uploads/foods/" . $food['image'];
                if(file_exists($image_path)) unlink($image_path);
            }

            $food_folder = str_replace(' ', '_', trim($food['name'])) . '_' . $_GET['delete_food'];
            $gallery_path = 'uploads/foods/gallery/' . $food_folder . '/';
            if(file_exists($gallery_path)) {
                foreach(scandir($gallery_path) as $file) {
                    if($file != '.' && $file != '..') unlink($gallery_path . $file);
                }
                rmdir($gallery_path);
            }
        }

        $pdo->prepare("DELETE FROM food_items WHERE id = ?")->execute([$_GET['delete_food']]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'delete', 'food', "Deleted food item: " . ($food['name'] ?? "ID {$_GET['delete_food']}"), (int)$_GET['delete_food'], 'food', null, null, 'warning');
        }

        $_SESSION['flash_success'] = "Food item deleted successfully!";
        header("Location: food-dashboard.php");
        exit();
    } catch(Exception $e) {
        $error = "Failed to delete food: " . $e->getMessage();
    }
}

// Flash success
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Get all food items
$food_items = $pdo->query("SELECT * FROM food_items ORDER BY category, name")->fetchAll();

// Stats
$total_food = count($food_items);
$available_food = $pdo->query("SELECT COUNT(*) FROM food_items WHERE is_available = 1")->fetchColumn();
$featured_food = $pdo->query("SELECT COUNT(*) FROM food_items WHERE is_featured = 1")->fetchColumn();

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

// Categories
$categories = $pdo->query("SELECT DISTINCT category FROM food_items ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$default_categories = ['boodle_regular', 'boodle_special', 'bilao', 'breakfast', 'lunch', 'dinner', 'snack', 'beverage'];
$all_categories = array_values(array_filter(array_unique(array_merge($default_categories, $categories)), function ($c) { return trim((string)$c) !== ''; }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Food Management - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
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

        /* TOP BAR — Desktop */
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11,36,71,0.1); flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }

        .top-bar .user-profile .avatar {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg,#4DA6D9,#7bb8f0);
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
        .food-total .stat-icon{
    background:linear-gradient(135deg,#38bdf8,#0284c7);
}


.food-available .stat-icon{
    background:linear-gradient(135deg,#34d399,#059669);
}


.food-featured .stat-icon{
    background:linear-gradient(135deg,#fbbf24,#f59e0b);
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

      /* FOOD DASHBOARD STATS */

.stats-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:24px;
    margin-bottom:30px;
}


.stat-card{

    background:white;
    border-radius:22px;
    padding:24px;

    border:1px solid #e8f0fe;

    box-shadow:
    0 12px 30px rgba(11,36,71,.08);

    display:flex;
    align-items:center;
    gap:18px;

    transition:.25s ease;

}


.stat-card:hover{

    transform:translateY(-5px);

    box-shadow:
    0 18px 40px rgba(11,36,71,.15);

}



.stat-icon{

    width:65px;
    height:65px;

    border-radius:18px;

    display:flex;
    align-items:center;
    justify-content:center;

    font-size:26px;

    color:white;

    background:
    linear-gradient(
        135deg,
        #4DA6D9,
        #0B3D91
    );

}



.stat-number{

    font-size:34px;

    font-weight:800;

    color:#0B2447;

    line-height:1;

}



.stat-label{

    margin-top:6px;

    color:#64748b;

    font-size:14px;

    font-weight:600;

}

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .stat-card { padding: 16px 14px; border-radius: 12px; min-width: 0; }
            .stat-label { overflow-wrap: anywhere; }
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
        .btn-sm { padding: 5px 12px; font-size: 11px; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
        .btn-sm:hover { transform: translateY(-2px); }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }

        /* BADGES */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; text-transform: uppercase; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-danger  { background: #fee2e2; color: #ef4444; }

        /* FOOD CARD */
        .food-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
        }

        .food-card {
            background: #4DA6D9;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(77,166,217,0.2);
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.15);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .food-card:hover { transform: translateY(-6px); box-shadow: 0 15px 40px rgba(77,166,217,0.3); }

        .food-card .food-image-wrapper {
            position: relative;
            width: 100%;
            height: 180px;
            overflow: hidden;
            background: rgba(255,255,255,0.1);
            flex-shrink: 0;
        }
        .food-card .food-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .food-card:hover .food-image-wrapper img { transform: scale(1.03); }

        .food-card .food-image-wrapper .food-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: rgba(255,255,255,0.3);
        }

        .food-card .food-image-wrapper .food-badges {
            position: absolute;
            top: 12px;
            left: 12px;
            right: 12px;
            display: flex;
            justify-content: space-between;
            gap: 6px;
            flex-wrap: wrap;
            z-index: 2;
        }
        .food-card .food-image-wrapper .food-badges .badge {
            padding: 4px 12px;
            font-size: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .food-card .food-content {
            padding: 18px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .food-card .food-name { font-weight: 700; color: white; font-size: 17px; margin-bottom: 4px; line-height: 1.3; }
        .food-card .food-category { font-size: 12px; color: rgba(255,255,255,0.75); margin-bottom: 10px; display: block; }

        .food-card .food-desc {
            color: rgba(255,255,255,0.9);
            font-size: 13px;
            margin-bottom: 12px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 39px;
        }

        .food-card .size-variations-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px; background: rgba(255,255,255,0.9);
            color: #1e40af; border-radius: 20px;
            font-size: 11px; font-weight: 600;
            margin-bottom: 8px; align-self: flex-start;
        }

        .size-variations-badge{
    cursor:pointer;
    user-select:none;
}


.toggle-icon{
    margin-left:auto;
    transition:.3s ease;
}


.size-variations-badge.active .toggle-icon{
    transform:rotate(180deg);
}


.food-card .variations-list{
    display:none;
}


.food-card .variations-list.show{
    display:block;
}

        .food-card .variations-list {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 10px 12px;
            margin: 6px 0 10px;
            font-size: 12px;
            color: white;
        }
        .food-card .variations-list .var-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            border-bottom: 1px dashed rgba(255,255,255,0.15);
        }
        .food-card .variations-list .var-item:last-child { border-bottom: none; }

        .food-card .food-pax {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 12px; background: rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.9); border-radius: 12px;
            font-size: 11px; margin-bottom: 8px; align-self: flex-start;
        }

        .food-card .food-price {
            font-size: 22px;
            font-weight: 700;
            color: #F4B400;
            margin: 4px 0 10px;
        }

        .food-price small{

    display:block;

    font-size:11px;

    font-weight:500;

    color:rgba(255,255,255,.75);

    margin-top:2px;

}

        .food-card .food-actions {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.12);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .food-card .btn-card {
            flex: 1;
            min-width: 70px;
            min-height: 40px;
            padding: 10px 12px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 12.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .food-card .btn-card:active { transform: scale(0.97); }

        .food-card .btn-gallery-card { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; }
        .food-card .btn-edit-card { background: #f59e0b; color: white; }
        .food-card .btn-delete-card { background: #ef4444; color: white; }

        @media (max-width: 768px) {
            .food-grid { grid-template-columns: 1fr; gap: 18px; }
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
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #4DA6D9; }
        .checkbox-group label { margin-bottom: 0; cursor: pointer; font-weight: 500; font-size: 13px; }
        .help-text { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .category-wrapper { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .category-wrapper .form-select { flex: 2; min-width: 150px; }
        .category-wrapper .category-custom-input { flex: 1; min-width: 120px; }
        .category-wrapper .category-custom-input input { width: 100%; padding: 10px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 14px; background: #fafafa; }

        .size-variations-container { background: #f8fafc; border-radius: 12px; padding: 15px; border: 1px solid #e8f0fe; }
        .size-variations-container .size-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 10px; align-items: center; margin-bottom: 8px; padding: 8px; background: white; border-radius: 8px; border: 1px solid #e8f0fe; }
        .size-variations-container .size-row:last-child { margin-bottom: 0; }
        .size-variations-container .size-row input, .size-variations-container .size-row select { min-width: 0; width: 100%; padding: 6px 10px; border: 1px solid #e8f0fe; border-radius: 6px; font-size: 13px; background: white; }
        .size-variations-container .size-row input:focus { outline: none; border-color: #4DA6D9; }
        .size-variations-container .size-row .btn-remove-size { background: #ef4444; color: white; border: none; border-radius: 6px; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .size-variations-container .size-row .btn-remove-size:hover { background: #dc2626; }
        .size-variations-container .btn-add-size { margin-top: 10px; padding: 8px 16px; background: #4DA6D9; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .size-variations-container .btn-add-size:hover { background: #3a8bbf; }
        .size-variations-container .default-sizes-hint { font-size: 11px; color: #94a3b8; margin-bottom: 10px; padding: 8px 12px; background: #fef3c7; border-radius: 6px; border-left: 3px solid #f59e0b; }

        @media (max-width: 768px) {
            .modal-content { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .category-wrapper { flex-direction: column; }
            .category-wrapper .form-select, .category-wrapper .category-custom-input { flex: none; width: 100%; }
            .size-variations-container .size-row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
            .size-variations-container .size-row .btn-remove-size { grid-column: span 2; width: 100%; }
        }
        @media (max-width: 480px) {
            .modal-content { padding: 15px; max-width: 95%; }
        }
        @media (max-width: 360px) {
            .size-variations-container .size-row { grid-template-columns: minmax(0, 1fr); }
            .size-variations-container .size-row .btn-remove-size { grid-column: auto; }
        }

        /* GALLERY MODAL */
        .gallery-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
        .gallery-modal.open { display: flex !important; }
        .gallery-modal-content { background: rgba(0,0,0,0.9); border-radius: 16px; max-width: 900px; width: 100%; max-height: 95vh; position: relative; display: flex; flex-direction: column; align-items: center; }
        .gallery-modal-close { position: absolute; top: 15px; right: 20px; font-size: 32px; cursor: pointer; color: white; z-index: 10; background: rgba(0,0,0,0.6); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; }
        .gallery-modal-close:hover { background: rgba(255,255,255,0.2); transform: rotate(90deg); }
        .gallery-slider-container { position: relative; width: 100%; height: 500px; background: #000; border-radius: 16px 16px 0 0; overflow: hidden; }
        .gallery-slider-container .slider-image { width: 100%; height: 100%; object-fit: contain; }
        .gallery-slider-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.6); color: white; border: none; width: 50px; height: 50px; border-radius: 50%; font-size: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 5; }
        .gallery-slider-nav:hover { background: rgba(255,255,255,0.2); }
        .gallery-slider-prev { left: 15px; }
        .gallery-slider-next { right: 15px; }
        .gallery-slider-counter { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.7); color: white; padding: 6px 18px; border-radius: 20px; font-size: 14px; z-index: 5; }
        .gallery-thumbnails { display: flex; gap: 8px; padding: 12px 20px; overflow-x: auto; background: rgba(0,0,0,0.8); width: 100%; border-radius: 0 0 16px 16px; }
        .gallery-thumbnail { width: 70px; height: 50px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 3px solid transparent; flex-shrink: 0; }
        .gallery-thumbnail.active { border-color: #F4B400; }

        @media (max-width: 768px) {
            .gallery-slider-container { height: 350px; }
            .gallery-slider-nav { width: 40px; height: 40px; font-size: 18px; }
            .gallery-thumbnail { width: 55px; height: 40px; }
        }
        @media (max-width: 480px) {
            .gallery-slider-container { height: 280px; }
            .gallery-slider-nav { width: 35px; height: 35px; font-size: 14px; }
            .gallery-thumbnail { width: 45px; height: 35px; }
            .gallery-modal-close { width: 35px; height: 35px; font-size: 24px; top: 10px; right: 12px; }
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

        /* FOOD SEARCH */

.food-header{
    flex-direction:column;
    align-items:stretch;
}


.section-subtitle{
    margin-top:5px;
    color:#64748b;
    font-size:13px;
}


.food-toolbar{
    display:flex;
    align-items:center;
    gap:12px;
}


.food-search{

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


.food-search i{
    color:#4DA6D9;
}


.food-search input{

    width:100%;
    border:none;
    outline:none;
    font-size:14px;

}


@media(max-width:768px){

    .food-toolbar{
        flex-direction:column;
        align-items:stretch;
    }

    .food-search{
        width:100%;
    }

}
            .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }
    </style>
    <link rel="stylesheet" href="assets/css/admin-responsive.css">
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
            <li class="nav-item"><a href="activities-dashboard.php" class="nav-link"><i class="fas fa-water"></i><span>Activities Management</span></a></li>

         <li class="nav-item">
    <a href="food-dashboard.php" class="nav-link active">
        <i class="fas fa-utensils"></i>
        <span>Food Management</span>
    </a>
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
        <!-- TOP BAR -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-utensils"></i>
                    Food Management
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
                <p>Manage food items, packages, and orders</p>
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
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-utensils"></i> Food Management</h1>
                <div class="underline"></div>
                <p>
                    Manage food items, packages, and orders
                    <?php if($is_staff): ?>
                        <br><span class="staff-notice"><i class="fas fa-user-tie"></i> Staff Access - Full Management</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="stats-grid">
           <div class="stat-card food-total">
                <div class="stat-icon"><i class="fas fa-utensils"></i></div>
                <div class="stat-number"><?php echo $total_food; ?></div>
                <div class="stat-label">Total Food Items</div>
            </div>
            <div class="stat-card food-available">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $available_food; ?></div>
                <div class="stat-label">Available</div>
            </div>
            <div class="stat-card food-featured">
                <div class="stat-icon"><i class="fas fa-star"></i></div>
                <div class="stat-number"><?php echo $featured_food; ?></div>
                <div class="stat-label">Featured Items</div>
            </div>
        </div>

        <div class="card">
          <div class="card-header food-header">

    <div>
        <h2>
            <i class="fas fa-pizza-slice"></i>
            Food Items
        </h2>

        <p class="section-subtitle">
            Manage food packages, prices, availability, and menu items
        </p>
    </div>


    <div class="food-toolbar">

        <div class="food-search">

            <i class="fas fa-search"></i>

            <input 
                type="text"
                id="foodSearch"
                placeholder="Search food items..."
                autocomplete="off"
            >

        </div>


        <button class="btn btn-primary" onclick="showModal('addFood')">

            <i class="fas fa-plus"></i>
            Add Food Item

        </button>

    </div>

</div>   

            <?php if(empty($food_items)): ?>
            <div style="text-align: center; padding: 40px; color: #94a3b8;">
                <i class="fas fa-utensils" style="font-size: 48px; margin-bottom: 15px; color: #cbd5e1; display:block;"></i>
                <p>No food items found. Click "Add Food Item" to get started!</p>
            </div>
            <?php else: ?>
            <div class="food-grid">
                <?php foreach($food_items as $food):
                    $size_variations = json_decode($food['size_variations'], true);
                    $has_variations = !empty($size_variations) && is_array($size_variations);
                    $gallery_images = !empty($food['gallery_images']) ? json_decode($food['gallery_images'], true) : [];
                    $has_gallery = !empty($gallery_images);
                ?>
                <div class="food-card food-item"
data-search="<?php echo strtolower(htmlspecialchars(
    ($food['name'] ?? '') . ' ' .
    ($food['description'] ?? '') . ' ' .
    ($food['category'] ?? '') . ' ' .
    ($food['is_available'] ? 'available' : 'unavailable')
)); ?>">
                    <div class="food-image-wrapper">
                        <img src="uploads/foods/<?php echo htmlspecialchars($food['image'] ?? 'default-food.jpg'); ?>?v=<?php echo time(); ?>" 
                             alt="<?php echo htmlspecialchars($food['name']); ?>"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="food-image-placeholder" style="display:none;">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <div class="food-badges">
                            <span class="badge <?php echo $food['is_available'] ? 'badge-success' : 'badge-danger'; ?>">
                                <?php echo $food['is_available'] ? 'Available' : 'Unavailable'; ?>
                            </span>
                            <?php if($food['is_featured']): ?>
                                <span class="badge badge-warning"><i class="fas fa-star"></i> Featured</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="food-content">
                        <div class="food-name"><?php echo htmlspecialchars($food['name']); ?></div>
                        <span class="food-category"><?php echo ucfirst(str_replace('_', ' ', $food['category'])); ?></span>

                        <?php if(!empty($food['description'])): ?>
                            <div class="food-desc"><?php echo htmlspecialchars($food['description']); ?></div>
                        <?php endif; ?>

                    

                 <?php if($has_variations): ?>

<div 
    class="size-variations-badge"
    onclick="toggleSizes(this)"
>
    <i class="fas fa-layer-group"></i>
    <?php echo count($size_variations); ?> sizes available

    <i class="fas fa-chevron-down toggle-icon"></i>
</div>


<div class="variations-list">

    <?php foreach($size_variations as $var): ?>

        <div class="var-item">

            <span>
                <?php echo htmlspecialchars($var['size']); ?>
            </span>

            <span>
                ₱<?php echo number_format($var['price']); ?>
            </span>

        </div>

    <?php endforeach; ?>

</div>


<div class="food-price">

    ₱<?php echo number_format($size_variations[0]['price']); ?>

    <small>
        starting price
    </small>

</div>


   


<?php else: ?>

                            <?php if(!empty($food['pax_range'])): ?>
                                <div class="food-pax"><i class="fas fa-users"></i> <?php echo htmlspecialchars($food['pax_range']); ?></div>
                            <?php endif; ?>
                            <div class="food-price">₱<?php echo number_format($food['price']); ?></div>
                        <?php endif; ?>

                        <div class="food-actions">
                            <?php if($has_gallery): ?>
                            <button type="button" class="btn-card btn-gallery-card"
                                    onclick="openGallery(<?php echo $food['id']; ?>, '<?php echo addslashes($food['name']); ?>', <?php echo htmlspecialchars(json_encode($gallery_images), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-images"></i> Gallery
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn-card btn-edit-card"
                                    onclick='editFood(<?php echo htmlspecialchars(json_encode($food), ENT_QUOTES, "UTF-8"); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <a href="?delete_food=<?php echo $food['id']; ?>"
                               class="btn-card btn-delete-card"
                               onclick="return confirm('Delete this food item?')">
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

<!-- GALLERY MODAL -->
<div id="galleryModal" class="gallery-modal" onclick="if(event.target === this) closeGallery()">
    <div class="gallery-modal-content">
        <button class="gallery-modal-close" onclick="closeGallery()">&times;</button>
        <div class="gallery-slider-container">
            <img id="sliderMainImage" class="slider-image" src="" alt="Food Photo">
            <button class="gallery-slider-nav gallery-slider-prev" onclick="changeSliderImage(-1)">❮</button>
            <button class="gallery-slider-nav gallery-slider-next" onclick="changeSliderImage(1)">❯</button>
            <div class="gallery-slider-counter" id="sliderCounter">1 / 1</div>
        </div>
        <div class="gallery-thumbnails" id="sliderThumbnails"></div>
    </div>
</div>

<!-- ADD FOOD MODAL -->
<div class="modal" id="addFoodModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add Food Item</h3>
            <button class="close" onclick="hideModal('addFood')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="addFoodForm">
            <div class="form-group">
                <label>Food Name <span class="required">*</span></label>
                <input type="text" name="name" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <div class="category-wrapper">
                    <select name="category" id="add_category" class="form-select" onchange="handleCategoryChange('add')">
                        <option value="">Select or type your own...</option>
                        <?php foreach($all_categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo ucfirst(str_replace('_', ' ', $cat)); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">+ Add Custom Category</option>
                    </select>
                    <div class="category-custom-input" id="add_custom_category_wrapper" style="display: none;">
                        <input type="text" name="category_custom" id="add_category_custom" class="form-control" placeholder="Enter custom category...">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-group">
                <label><i class="fas fa-arrows-alt-h"></i> Sizes &amp; Prices <span class="required">*</span></label>
                <div class="size-variations-container" id="add_size_container">
                    <div class="default-sizes-hint">
                        <i class="fas fa-info-circle"></i>
                        <span id="add_default_hint_text">Add one or more size rows — price comes from these rows.</span>
                    </div>
                    <div id="add_size_rows"></div>
                    <button type="button" class="btn-add-size" onclick="addSizeRow('add')">
                        <i class="fas fa-plus"></i> Add Size
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label>Inclusions</label>
                <textarea name="inclusions" class="form-control" rows="3" placeholder="List all inclusions (one per line)"></textarea>
            </div>

            <div class="form-group">
                <label>Main Image</label>
                <input type="file" name="food_image" class="form-control" accept="image/*">
            </div>

            <div class="form-group">
                <label>Gallery Images (Multiple)</label>
                <input type="file" name="gallery_images[]" class="form-control" accept="image/*" multiple>
            </div>

            <div class="form-row">
                <div class="checkbox-group">
                    <input type="checkbox" name="is_available" id="add_is_available" value="1" checked>
                    <label for="add_is_available">Available</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="is_featured" id="add_is_featured" value="1">
                    <label for="add_is_featured"><i class="fas fa-star" style="color: #f59e0b;"></i> Featured</label>
                </div>
            </div>

            <button type="submit" name="add_food" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <i class="fas fa-save"></i> Add Food
            </button>
        </form>
    </div>
</div>

<!-- EDIT FOOD MODAL -->
<div class="modal" id="editFoodModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Food Item</h3>
            <button class="close" onclick="hideModal('editFood')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editFoodForm">
            <input type="hidden" name="food_id" id="edit_food_id">

            <div class="form-group">
                <label>Food Name <span class="required">*</span></label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Category <span class="required">*</span></label>
                <div class="category-wrapper">
                    <select name="category" id="edit_category" class="form-select" onchange="handleCategoryChange('edit')">
                        <option value="">Select or type your own...</option>
                        <?php foreach($all_categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo ucfirst(str_replace('_', ' ', $cat)); ?></option>
                        <?php endforeach; ?>
                        <option value="__custom__">+ Add Custom Category</option>
                    </select>
                    <div class="category-custom-input" id="edit_custom_category_wrapper" style="display: none;">
                        <input type="text" name="category_custom" id="edit_category_custom" class="form-control" placeholder="Enter custom category...">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-group">
                <label><i class="fas fa-arrows-alt-h"></i> Sizes &amp; Prices <span class="required">*</span></label>
                <div class="size-variations-container" id="edit_size_container">
                    <div class="default-sizes-hint">
                        <i class="fas fa-info-circle"></i>
                        <span id="edit_default_hint_text">Current size variations. Modify as needed.</span>
                    </div>
                    <div id="edit_size_rows"></div>
                    <button type="button" class="btn-add-size" onclick="addSizeRow('edit')">
                        <i class="fas fa-plus"></i> Add Size
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label>Inclusions</label>
                <textarea name="inclusions" id="edit_inclusions" class="form-control" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label>Add More Gallery Images</label>
                <input type="file" name="gallery_images[]" class="form-control" accept="image/*" multiple>
            </div>

            <div class="form-row">
                <div class="checkbox-group">
                    <input type="checkbox" name="is_available" id="edit_is_available" value="1">
                    <label for="edit_is_available">Available</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="is_featured" id="edit_is_featured" value="1">
                    <label for="edit_is_featured"><i class="fas fa-star" style="color: #f59e0b;"></i> Featured</label>
                </div>
            </div>

            <button type="submit" name="edit_food" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <i class="fas fa-save"></i> Update Food
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
// ============================================================
// SIDEBAR TOGGLE
// ============================================================
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

// ============================================================
// ESCAPE HELPER
// ============================================================
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ============================================================
// CATEGORY HANDLING
// ============================================================
function handleCategoryChange(type) {
    const select = document.getElementById(type + '_category');
    const customWrapper = document.getElementById(type + '_custom_category_wrapper');
    const customInput = document.getElementById(type + '_category_custom');
    const hintText = document.getElementById(type + '_default_hint_text');
    const container = document.getElementById(type + '_size_rows');

    if (select.value === '__custom__') {
        customWrapper.style.display = 'block';
        customInput.required = true;
        customInput.focus();
        hintText.textContent = 'Enter your custom category, then add sizes below.';
        return;
    } else {
        customWrapper.style.display = 'none';
        customInput.required = false;
        customInput.value = '';
    }

    const defaultSizes = getSizeVariations(select.value);
    if (defaultSizes.length > 0) {
        hintText.textContent = 'Default sizes loaded for ' + select.value.replace('_', ' ') + '.';
        container.innerHTML = '';
        defaultSizes.forEach(function(size, index) { addSizeRowWithData(type, size, index); });
    } else {
        hintText.textContent = 'Add one or more size rows — price comes from these rows.';
        if (container.children.length === 0) addSizeRow(type);
    }
}

function getSizeVariations(category) {
    var sizes = {
        'bilao': [
            {size: 'Small', pax: '2-5 PAX', price: 1500},
            {size: 'Medium', pax: '5-8 PAX', price: 2000},
            {size: 'Large', pax: '8-12 PAX', price: 3000},
            {size: 'XLarge', pax: '12-15 PAX', price: 3500}
        ],
        'boodle_special': [
            {size: '6 PAX', pax: '6 PAX', price: 4000},
            {size: '10 PAX', pax: '10 PAX', price: 5500},
            {size: '15 PAX', pax: '15 PAX', price: 7500},
            {size: '20 PAX', pax: '20 PAX', price: 9000}
        ]
    };
    return sizes[category] || [];
}

// ============================================================
// SIZE ROWS
// ============================================================
function addSizeRow(type) {
    const container = document.getElementById(type + '_size_rows');
    const rowCount = container.children.length;
    const row = document.createElement('div');
    row.className = 'size-row';
    row.innerHTML = `
        <input type="text" name="sizes[${rowCount}][size]" placeholder="Size name (e.g., Small)" value="">
        <input type="text" name="sizes[${rowCount}][pax]" placeholder="PAX (e.g., 2-5 PAX)" value="">
        <input type="number" name="sizes[${rowCount}][price]" placeholder="Price (₱)" value="" step="0.01">
        <button type="button" class="btn-remove-size" onclick="removeSizeRow(this)" title="Remove">
            <i class="fas fa-times"></i>
        </button>
    `;
    container.appendChild(row);
}

function addSizeRowWithData(type, data, index) {
    const container = document.getElementById(type + '_size_rows');
    const row = document.createElement('div');
    row.className = 'size-row';
    const sizeVal = escapeHtml(data.size ?? '');
    const paxVal = escapeHtml(data.pax ?? '');
    const priceVal = (data.price === null || data.price === undefined || data.price === '') ? '' : parseFloat(data.price);
    row.innerHTML = `
        <input type="text" name="sizes[${index}][size]" placeholder="Size name" value="${sizeVal}">
        <input type="text" name="sizes[${index}][pax]" placeholder="PAX" value="${paxVal}">
        <input type="number" name="sizes[${index}][price]" placeholder="Price (₱)" value="${priceVal}" step="0.01">
        <button type="button" class="btn-remove-size" onclick="removeSizeRow(this)" title="Remove">
            <i class="fas fa-times"></i>
        </button>
    `;
    container.appendChild(row);
}

function removeSizeRow(button) {
    const row = button.closest('.size-row');
    const container = row.parentElement;
    if (container.children.length <= 1) { alert('You need at least one size variation.'); return; }
    if (confirm('Remove this size variation?')) {
        row.remove();
        renumberSizeRows(container);
    }
}

function renumberSizeRows(container) {
    const rows = container.querySelectorAll('.size-row');
    rows.forEach(function(row, index) {
        row.querySelectorAll('input').forEach(function(input) {
            if (input.name) input.name = input.name.replace(/sizes\[\d+\]/, 'sizes[' + index + ']');
        });
    });
}

// ============================================================
// GALLERY
// ============================================================
var galleryImages = [];
var galleryCurrentIndex = 0;
var currentFoodId = 0;
var currentFoodName = '';

function openGallery(foodId, foodName, images) {
    currentFoodId = foodId;
    currentFoodName = foodName;
    galleryImages = images;
    galleryCurrentIndex = 0;
    if (galleryImages.length === 0) { alert('No gallery images.'); return; }
    showGalleryImage(0);
    const thumbnails = document.getElementById('sliderThumbnails');
    thumbnails.innerHTML = '';
    galleryImages.forEach(function(img, index) {
        const thumb = document.createElement('img');
        thumb.src = 'uploads/foods/gallery/' + str_replace(' ', '_', foodName) + '_' + foodId + '/' + img;
        thumb.className = 'gallery-thumbnail' + (index === 0 ? ' active' : '');
        thumb.onclick = function() { showGalleryImage(index); };
        thumbnails.appendChild(thumb);
    });
    document.getElementById('galleryModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function showGalleryImage(index) {
    if (index < 0 || index >= galleryImages.length) return;
    galleryCurrentIndex = index;
    var foodFolder = str_replace(' ', '_', currentFoodName) + '_' + currentFoodId;
    document.getElementById('sliderMainImage').src = 'uploads/foods/gallery/' + foodFolder + '/' + galleryImages[index];
    document.getElementById('sliderCounter').textContent = (index + 1) + ' / ' + galleryImages.length;
    document.querySelectorAll('.gallery-thumbnail').forEach(function(thumb, i) {
        thumb.classList.toggle('active', i === index);
    });
}

function changeSliderImage(direction) {
    if (galleryImages.length === 0) return;
    galleryCurrentIndex += direction;
    if (galleryCurrentIndex < 0) galleryCurrentIndex = galleryImages.length - 1;
    if (galleryCurrentIndex >= galleryImages.length) galleryCurrentIndex = 0;
    showGalleryImage(galleryCurrentIndex);
}

function closeGallery() {
    document.getElementById('galleryModal').classList.remove('open');
    document.body.style.overflow = 'auto';
}

function str_replace(search, replace, subject) {
    return subject.split(search).join(replace);
}

// ============================================================
// MODALS
// ============================================================
function showModal(type) {
    document.getElementById(type + 'Modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function hideModal(type) {
    document.getElementById(type + 'Modal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// EDIT FOOD
// ============================================================
function editFood(food) {
    document.getElementById('edit_food_id').value = food.id;
    document.getElementById('edit_name').value = food.name || '';

    const categorySelect = document.getElementById('edit_category');
    const customWrapper = document.getElementById('edit_custom_category_wrapper');
    const customInput = document.getElementById('edit_category_custom');

    let found = false;
    for (let i = 0; i < categorySelect.options.length; i++) {
        if (categorySelect.options[i].value === food.category) {
            categorySelect.value = food.category;
            found = true;
            break;
        }
    }

    if (!found && food.category) {
        categorySelect.value = '__custom__';
        customWrapper.style.display = 'block';
        customInput.value = food.category;
        customInput.required = true;
    } else {
        customWrapper.style.display = 'none';
        customInput.value = '';
        customInput.required = false;
    }

    document.getElementById('edit_description').value = food.description || '';
    document.getElementById('edit_inclusions').value = food.inclusions || '';
    document.getElementById('edit_is_available').checked = food.is_available == 1;
    document.getElementById('edit_is_featured').checked = food.is_featured == 1;

    const container = document.getElementById('edit_size_rows');
    container.innerHTML = '';
    const hintText = document.getElementById('edit_default_hint_text');

    let sizes = [];
    try {
        if (food.size_variations) {
            const parsed = (typeof food.size_variations === 'string') ? JSON.parse(food.size_variations) : food.size_variations;
            if (Array.isArray(parsed) && parsed.length > 0) sizes = parsed;
        }
    } catch(e) { console.warn('Invalid size_variations', e); }

    if (sizes.length === 0) {
        const defaultSizes = getSizeVariations(food.category);
        if (defaultSizes.length > 0) {
            sizes = defaultSizes;
            hintText.textContent = 'Default sizes loaded for ' + String(food.category).replace('_', ' ') + '.';
        } else {
            hintText.textContent = 'No size variations saved. Add your own below.';
            addSizeRow('edit');
            showModal('editFood');
            return;
        }
    } else {
        hintText.textContent = 'Current size variations. Modify as needed.';
    }

    sizes.forEach(function(size, index) { addSizeRowWithData('edit', size, index); });
    showModal('editFood');
}

// ============================================================
// CLOSE MODALS
// ============================================================
window.onclick = function(event) {
    if(event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
    if(event.target.classList.contains('gallery-modal')) closeGallery();
}

// ============================================================
// FORM VALIDATION
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const addContainer = document.getElementById('add_size_rows');
    if (addContainer && addContainer.children.length === 0) addSizeRow('add');

    const addForm = document.getElementById('addFoodForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            const priceInputs = addForm.querySelectorAll('input[name*="[price]"]');
            let hasPrice = false;
            priceInputs.forEach(function(inp) { if (inp.value && parseFloat(inp.value) > 0) hasPrice = true; });
            if (!hasPrice) { e.preventDefault(); alert('Add at least one size row with a price.'); return false; }
            return true;
        });
    }

    const editForm = document.getElementById('editFoodForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            const priceInputs = editForm.querySelectorAll('input[name*="[price]"]');
            let hasPrice = false;
            priceInputs.forEach(function(inp) { if (inp.value && parseFloat(inp.value) > 0) hasPrice = true; });
            if (!hasPrice) { e.preventDefault(); alert('Keep at least one size row with a price.'); return false; }
            return true;
        });
    }
});

// ============================================================
// LOGOUT MODAL
// ============================================================
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

// ============================================================
// AUTO-HIDE ALERTS
// ============================================================
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);

// FOOD LIVE SEARCH

document.addEventListener("DOMContentLoaded", function(){

    const searchInput = document.getElementById("foodSearch");
    const foods = document.querySelectorAll(".food-item");


    if(!searchInput) return;


    searchInput.addEventListener("input", function(){

        const keyword = this.value.toLowerCase().trim();


        foods.forEach(food => {

            const data = food.dataset.search;


            if(data.includes(keyword)){
                food.style.display = "";
            }
            else{
                food.style.display = "none";
            }

        });

    });

});

function toggleSizes(button){

    const list = button.nextElementSibling;

    list.classList.toggle("show");

    button.classList.toggle("active");

}
</script>

</body>
</html>
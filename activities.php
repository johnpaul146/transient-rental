<?php
session_start();
require_once 'database.php';
require_once 'includes/TermsGate.php';
TermsGate::enforceGuest($pdo); // Terms & Privacy must be accepted before guest features

// ✅ NEW: Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// ✨ TERMS: Load the terms gate
require_once 'includes/TermsGate.php';
$termsGate = new TermsGate($pdo);

// 🔒 Helper: real client IP
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// Get dynamic content
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

$logo_path = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
}

$hero_path = 'uploads/hero/hero-bg.jpg';
$home_hero_path = $content['site_settings']['hero_image_path'] ?? $hero_path;
if (!empty($home_hero_path) && file_exists($home_hero_path) && !is_dir($home_hero_path)) {
    $hero_path = $home_hero_path;
}
$page_hero_filename = basename((string)($content['site_settings']['activities_hero_image'] ?? ''));
$page_hero_path = 'uploads/hero/activities/' . $page_hero_filename;
if ($page_hero_filename !== '' && file_exists($page_hero_path) && !is_dir($page_hero_path)) {
    $hero_path = $page_hero_path;
}
$hero_exists = !empty($hero_path) && file_exists($hero_path) && !is_dir($hero_path);

function getGoogleMapsUrl($address) {
    if (empty($address) || $address == '#') return '#';
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address);
}

$location_address = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';
$maps_url = getGoogleMapsUrl($location_address);

$default_map_url = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863.123456789!2d119.1234567!3d16.1234567!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTbCsDA3JzI0LjAiTiAxMTnCsDA3JzI0LjAiRQ!5e0!3m2!1sen!2sph!4v1234567890';
$map_embed = !empty($google_maps_embed) && $google_maps_embed != '#' ? $google_maps_embed : $default_map_url;
$facebook_link = $content['social']['facebook'] ?? '#';

// Get all activities
$activities = [];
$stmt = $pdo->query("SELECT * FROM activities ORDER BY category, id");
$activities = $stmt->fetchAll();

// Group activities by category
$grouped_activities = [];
foreach($activities as $activity) {
    $category = $activity['category'] ?? 'Other';
    if(!isset($grouped_activities[$category])) {
        $grouped_activities[$category] = [];
    }
    $grouped_activities[$category][] = $activity;
}

// Check if user has overall feedback
$user_has_feedback = false;
$user_feedback = null;
if(isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_feedback = $stmt->fetch();
    $user_has_feedback = ($user_feedback !== false);
}

// ============================================================
// ✅ HANDLE OVERALL FEEDBACK — STAYS ON activities.php
// ============================================================
if (isset($_POST['submit_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $rating       = (int)($_POST['rating'] ?? 0);
        $comment      = trim($_POST['comment'] ?? '');
        $user_id      = (int)$_SESSION['user_id'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        if ($rating < 1 || $rating > 5) {
            throw new Exception("Please select a rating between 1 and 5.");
        }

        $check = $pdo->prepare("SELECT id FROM overall_feedback WHERE user_id = ?");
        $check->execute([$user_id]);
        $is_update = (bool)$check->fetch();

        if ($is_update) {
            $stmt = $pdo->prepare("UPDATE overall_feedback 
                                   SET rating = ?, comment = ?, updated_at = NOW(), is_anonymous = ? 
                                   WHERE user_id = ?");
            $stmt->execute([$rating, $comment, $is_anonymous, $user_id]);
            $_SESSION['flash_feedback_success'] = "Thank you! Your feedback has been updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO overall_feedback (user_id, rating, comment, is_anonymous) 
                                   VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $rating, $comment, $is_anonymous]);
            $_SESSION['flash_feedback_success'] = "Thank you for your feedback! Your review has been submitted.";
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                $is_update ? 'update' : 'create',
                'review',
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' " . ($is_update ? "updated" : "submitted") . " overall review from Activities page — Rating: {$rating}/5" . ($is_anonymous ? " (Anonymous)" : ""),
                null,
                'overall_feedback',
                null,
                [
                    'rating' => $rating,
                    'anonymous' => $is_anonymous,
                    'has_comment' => !empty($comment),
                    'page' => 'activities.php'
                ]
            );
        }

        header("Location: activities.php?feedback_success=1");
        exit();

    } catch (Exception $e) {
        $error = "Failed to submit feedback: " . $e->getMessage();
    }
}

// Pull flashed feedback success
if (isset($_SESSION['flash_feedback_success'])) {
    $success = $_SESSION['flash_feedback_success'];
    unset($_SESSION['flash_feedback_success']);
}

// ============================================================
// ✨ TERMS: Handle acceptance submission
// ============================================================
if (isset($_POST['accept_terms']) && isset($_SESSION['user_id'])) {
    $termsGate->accept((int)$_SESSION['user_id'], getClientIp());

    if (class_exists('SystemLogger')) {
        SystemLogger::log(
            $pdo,
            'accept',
            'terms',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' accepted Terms & Privacy Policy (v" . $termsGate->getCurrentVersion() . ") from Activities page",
            (int)$_SESSION['user_id'],
            'user',
            null,
            ['version' => $termsGate->getCurrentVersion(), 'ip' => getClientIp(), 'page' => 'activities.php']
        );
    }

    unset($_SESSION['show_terms_modal']);
    $_SESSION['terms_accepted'] = true;
    header("Location: activities.php?terms_accepted=1");
    exit();
}

// Handle Logout
if(isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Function to get activity image path
function getActivityImage($image) {
    if (!empty($image) && file_exists('uploads/activities/' . $image) && $image != 'default-activity.jpg') {
        return 'uploads/activities/' . $image;
    }
    return null;
}

// Function to get icon for activity
function getActivityIcon($activity) {
    if (!empty($activity['icon'])) {
        return $activity['icon'];
    }
    $categoryIcons = [
        'Water Sports' => 'fas fa-ship',
        'Water Activities' => 'fas fa-swimmer',
        'Adventure' => 'fas fa-hiking',
        'Other' => 'fas fa-star'
    ];
    return $categoryIcons[$activity['category']] ?? 'fas fa-star';
}

// Function to format price display
function formatActivityPrice($activity) {
    if (empty($activity['price']) || $activity['price'] == 0) {
        return null;
    }
    $price = '₱' . number_format($activity['price'], 2);
    $unit = !empty($activity['price_unit']) ? ' ' . htmlspecialchars($activity['price_unit']) : '';
    return $price . $unit;
}

// ✨ TERMS: Compute mode for the modal
$force_must_accept = false;
if (isset($_SESSION['show_terms_modal']) && $_SESSION['show_terms_modal']) {
    $force_must_accept = true;
}
if (!$force_must_accept
    && isset($_SESSION['user_id'])
    && isset($_SESSION['role'])
    && $_SESSION['role'] === 'guest'
) {
    if (!$termsGate->hasAccepted((int)$_SESSION['user_id'])) {
        $force_must_accept = true;
    }
}

$termsContent = $termsGate->getContent();
$termsVersion = $termsGate->getCurrentVersion();

$sidebar_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $sidebar_logo = $content['site_settings']['logo_path'];
}
$sidebar_logo_exists = !empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo);

$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Activities - Huddled Islands</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* RESET & BASE */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
        }

        /* HEADER */
        .header {
            background: #0B2447;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            padding: 12px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 2px solid rgba(77, 166, 217, 0.2);
        }
        .header-content {
            max-width: 1300px; margin: 0 auto; padding: 0 20px;
            display: flex; justify-content: space-between; align-items: center;
            gap: 15px;
        }
        .logo-wrapper { display: flex; align-items: center; gap: 15px; text-decoration: none; flex-shrink: 0; min-width: 0; }
        .logo-wrapper .logo-image {
            height: 50px; width: 50px; border-radius: 12px;
            object-fit: cover; border: 2px solid #4DA6D9;
            padding: 2px; background: white;
            transition: transform 0.3s ease; flex-shrink: 0;
        }
        .logo-wrapper .logo-image:hover { transform: scale(1.05); }
        .logo-wrapper .logo-image-placeholder {
            height: 50px; width: 50px; border-radius: 12px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 24px; font-weight: 700;
            border: 2px solid #4DA6D9; flex-shrink: 0;
        }
        .brand-text { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
        .brand-text .brand-name { font-size: 20px; font-weight: 700; color: white; letter-spacing: -0.5px; white-space: nowrap; }
        .brand-text .brand-tagline { font-size: 11px; color: #7bb8f0; font-weight: 500; letter-spacing: 0.3px; white-space: nowrap; }

        /* DESKTOP NAV */
        .desktop-nav {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: nowrap;
            flex-shrink: 1;
            min-width: 0;
            max-width: 100%;
        }
        .desktop-nav a {
            padding: 8px 12px;
            border-radius: 8px;
            color: #b3d9ff;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            white-space: nowrap;
            flex-shrink: 0;
            line-height: 1.2;
        }
        .desktop-nav a i { font-size: 13px; }
        .desktop-nav a:hover { background: rgba(77, 166, 217, 0.2); color: white; }
        .desktop-nav a.active-nav { background: rgba(77, 166, 217, 0.25); color: white; }
        .desktop-nav .btn-logout {
            background: #ef4444;
            color: white;
            border-radius: 8px;
            padding: 8px 14px;
        }
        .desktop-nav .btn-logout:hover { background: #dc2626; }
        .desktop-nav .btn-rate {
            background: #F4B400;
            color: #0B2447;
            border-radius: 8px;
            padding: 8px 14px;
        }
        .desktop-nav .btn-rate:hover { background: #e6a800; color: #0B2447; }

        /* HAMBURGER */
        .menu-toggle {
            display: none; position: fixed; top: 12px; left: 12px;
            z-index: 1001; background: #0B2447; color: white;
            border: none; border-radius: 12px;
            width: 48px; height: 48px; font-size: 22px;
            cursor: pointer; transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            align-items: center; justify-content: center;
            border: 1px solid rgba(77, 166, 217, 0.2);
        }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        .menu-toggle .fa-bars { transition: transform 0.3s ease; }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle {
            opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8);
        }

        /* SIDEBAR */
        .sidebar {
            position: fixed; top: 0; left: -320px;
            width: 300px; height: 100vh;
            background: #0B2447; box-shadow: 4px 0 30px rgba(0,0,0,0.3);
            padding: 25px 0; transition: left 0.3s ease;
            z-index: 1000; overflow-y: auto;
            border-right: 2px solid rgba(77, 166, 217, 0.15);
        }
        .sidebar.open { left: 0; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }

        .sidebar-header {
            padding: 0 20px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 20px;
        }
        .sidebar-header-top {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .sidebar-header .logo {
            font-size: 22px; font-weight: 700; color: white;
            text-decoration: none; display: flex; align-items: center; gap: 12px;
            flex: 1; min-width: 0;
        }
        .sidebar-header .logo .logo-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 14px; display: flex;
            align-items: center; justify-content: center;
            font-size: 22px; color: white; flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
            overflow: hidden;
        }
        .sidebar-header .logo img {
            width: 48px; height: 48px;
            border-radius: 14px; object-fit: cover;
            border: 2px solid #4DA6D9; padding: 2px;
            background: white; flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
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
            border-radius: 10px; font-size: 16px;
            cursor: pointer; flex-shrink: 0;
            align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .sidebar-close-btn:hover {
            background: #ef4444; border-color: #ef4444;
            color: white; transform: rotate(90deg);
        }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 20px; color: #b3d9ff;
            text-decoration: none; transition: all 0.3s;
            border-left: 3px solid transparent;
            font-weight: 500; font-size: 14px; position: relative;
        }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav i { color: #7bb8f0; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999; opacity: 0; transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; opacity: 1; }

        @media (max-width: 1200px) {
            .desktop-nav .btn-logout .logout-text { display: none; }
            .desktop-nav .btn-logout { padding: 8px 12px; }
            .desktop-nav .btn-rate .rate-text { display: none; }
            .desktop-nav .btn-rate { padding: 8px 12px; }
        }

        @media (max-width: 1100px) {
            .desktop-nav { display: none !important; }
            .menu-toggle { display: flex; }
            .header-content { padding-left: 65px; }
            .sidebar-close-btn { display: flex; }
        }

        @media (max-width: 768px) {
            .header-content { padding-left: 60px; padding-right: 15px; gap: 10px; }
            .logo-wrapper { gap: 10px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder { height: 42px; width: 42px; }
            .brand-text .brand-name { font-size: 17px; }
            .brand-text .brand-tagline { font-size: 10px; }
        }

        @media (max-width: 480px) {
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .sidebar { width: 85%; max-width: 300px; }
            .header-content { padding-left: 58px; padding-right: 10px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder { height: 36px; width: 36px; border-radius: 10px; }
            .brand-text .brand-name { font-size: 15px; }
            .brand-text .brand-tagline { font-size: 9px; }
        }

        /* HERO */
        .hero {
            <?php if($hero_exists): ?>
            background: linear-gradient(rgba(11, 36, 71, 0.5), rgba(11, 36, 71, 0.6)), url('<?php echo $hero_path; ?>?<?php echo time(); ?>');
            background-size: cover; background-position: center;
            <?php else: ?>
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            <?php endif; ?>
            padding: 120px 20px 70px;
            color: white; text-align: center; position: relative;
        }
        .hero-content { max-width: 800px; margin: 0 auto; padding: 0 20px; position: relative; z-index: 1; }
        .hero h1 { font-size: 48px; font-weight: 700; margin-bottom: 20px; text-shadow: 0 2px 25px rgba(0,0,0,0.25); }
        .hero h1 i { color: #7bb8f0; }
        .hero p { font-size: 18px; margin-bottom: 30px; opacity: 0.95; text-shadow: 0 1px 15px rgba(0,0,0,0.15); }

        /* MAIN */
        .main-container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        .section-title { text-align: center; margin-bottom: 40px; }
        .section-title h2 { font-size: 32px; font-weight: 700; color: #0B2447; margin-bottom: 10px; }
        .section-title .underline {
            width: 80px; height: 4px;
            background: linear-gradient(90deg, #4DA6D9, #7bb8f0);
            border-radius: 2px; margin: 0 auto;
        }

        /* ACTIVITY GRID */
        .category-section { margin-bottom: 50px; }
        .category-title {
            font-size: 24px; font-weight: 700; color: #0B2447;
            margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px;
        }
        .category-title i { color: #4DA6D9; }
        .category-title .badge-count {
            font-size: 14px; font-weight: 500; color: #64748b;
            background: #f1f5f9; padding: 2px 12px; border-radius: 20px;
        }

        .activity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
        }
        .activity-card {
            background: #4DA6D9;
            border-radius: 16px; overflow: hidden;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.15);
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.15);
            position: relative;
            cursor: pointer;
        }
        .activity-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(77, 166, 217, 0.25);
        }
        .activity-card .activity-image-wrapper {
            position: relative; width: 100%; height: 200px;
            overflow: hidden; background: rgba(255,255,255,0.1);
        }
        .activity-card .activity-image {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 0.3s ease;
        }
        .activity-card:hover .activity-image { transform: scale(1.03); }
        .activity-card .activity-image-placeholder {
            width: 100%; height: 100%; display: flex;
            align-items: center; justify-content: center;
            font-size: 60px; color: rgba(255,255,255,0.3);
        }
        .activity-card .featured-badge {
            position: absolute; top: 12px; left: 12px;
            background: #F4B400; color: #0B2447;
            padding: 4px 14px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            z-index: 2; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .activity-card .featured-badge i { margin-right: 4px; }
        .activity-card .status-badge {
            position: absolute; top: 12px; right: 12px;
            padding: 4px 14px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            z-index: 2; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .activity-card .status-badge i { margin-right: 4px; }
        .status-available { background: rgba(16, 185, 129, 0.95); color: white; }
        .status-unavailable { background: rgba(239, 68, 68, 0.95); color: white; }
        .activity-card .activity-content { padding: 20px; }
        .activity-card .activity-content h4 {
            font-size: 18px; font-weight: 700; color: white;
            margin-bottom: 4px;
        }
        .activity-card .activity-content .activity-category {
            font-size: 13px; color: rgba(255,255,255,0.75);
            margin-bottom: 10px; display: block;
        }
        .activity-card .activity-content .activity-desc {
            color: rgba(255,255,255,0.9); font-size: 14px;
            line-height: 1.6; margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .activity-card .price-tag {
            font-size: 22px; font-weight: 700;
            color: #F4B400; margin: 8px 0 5px; line-height: 1.2;
        }
        .activity-card .price-tag small {
            font-size: 13px; font-weight: 400;
            color: rgba(255,255,255,0.7); margin-left: 4px;
        }
        .activity-card .price-note {
            font-size: 12px; color: rgba(255,255,255,0.7);
            margin-bottom: 8px; font-style: italic;
        }

        /* MODAL */
        .modal {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 3000;
            align-items: center; justify-content: center;
            backdrop-filter: blur(5px);
        }
        .modal.show { display: flex; }
        .modal-content {
            background: white; border-radius: 24px;
            width: 90%; max-width: 550px;
            max-height: 90vh; overflow-y: auto; padding: 30px;
        }
        .modal-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 20px;
            padding-bottom: 15px; border-bottom: 2px solid #e8f0fe;
        }
        .modal-header h3 {
            font-size: 22px; font-weight: 700; color: #0B2447;
            display: flex; align-items: center; gap: 10px;
        }
        .modal-header h3 i { color: #4DA6D9; }
        .modal-header .close {
            font-size: 28px; cursor: pointer; color: #94a3b8;
            transition: color 0.3s; background: none;
            border: none; padding: 0 10px; line-height: 1;
        }
        .modal-header .close:hover { color: #ef4444; }

        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block; margin-bottom: 5px;
            font-weight: 600; color: #1e293b; font-size: 13px;
        }
        .form-control {
            width: 100%; padding: 10px 14px;
            border: 2px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; transition: border-color 0.3s;
            background: #fafafa;
        }
        .form-control:focus {
            outline: none; border-color: #4DA6D9;
            background: white;
            box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }
        .btn-primary {
            width: 100%; padding: 12px;
            background: #F4B400; color: #0B2447;
            border: none; border-radius: 10px;
            font-weight: 600; cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3);
            background: #e6a800;
        }

        /* ALERTS */
        .alert-overlay { position: fixed; top: 20px; right: 20px; z-index: 3000; }
        .alert-box {
            background: white; border-radius: 12px;
            padding: 15px 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: flex; align-items: center; gap: 10px;
            min-width: 300px; animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .alert-box.success { border-left: 4px solid #10b981; }
        .alert-box.error { border-left: 4px solid #ef4444; }
        .alert-box i { font-size: 18px; }

        /* ✅ CENTERED SUCCESS POPUP */
        .alert-overlay.show {
            display: flex; align-items: center; justify-content: center;
            inset: 0; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }
        .alert-box.success-popup {
            background: white;
            border-radius: 20px;
            width: 90%; max-width: 400px;
            padding: 35px 30px 25px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            border-top: 6px solid #10b981;
            animation: alertPopIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            min-width: unset;
            display: block;
        }
        @keyframes alertPopIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.9); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .alert-box.success-popup .alert-icon {
            font-size: 60px; margin-bottom: 15px; line-height: 1;
            display: block; color: #10b981;
        }
        .alert-box.success-popup h3 {
            font-size: 24px; font-weight: 700; margin-bottom: 10px; color: #10b981;
        }
        .alert-box.success-popup p {
            color: #4a6a8c; margin-bottom: 22px; font-size: 15px;
            line-height: 1.5; word-wrap: break-word;
        }
        .alert-box.success-popup .btn-popup-ok {
            background: #F4B400; color: #0B2447; border: none;
            padding: 12px 40px; border-radius: 10px;
            font-weight: 700; font-size: 15px; cursor: pointer;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
            transition: all 0.25s; min-width: 130px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }
        .alert-box.success-popup .btn-popup-ok:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.45);
            background: #e6a800; color: #0B2447;
        }
        @media (max-width: 480px) {
            .alert-box.success-popup { padding: 28px 22px 20px; border-radius: 16px; }
            .alert-box.success-popup .alert-icon { font-size: 50px; margin-bottom: 12px; }
            .alert-box.success-popup h3 { font-size: 20px; }
            .alert-box.success-popup p { font-size: 14px; margin-bottom: 18px; }
            .alert-box.success-popup .btn-popup-ok { padding: 11px 32px; font-size: 14px; }
        }

        /* OVERALL FEEDBACK */
        .rating-input {
            display: flex; justify-content: center;
            gap: 10px; font-size: 32px; cursor: pointer;
        }
        .rating-input i { color: #d4dce4; transition: all 0.2s; }
        .rating-input i:hover,
        .rating-input i.active { color: #f59e0b; transform: scale(1.1); }

        .feedback-message {
            text-align: center; padding: 15px;
            border-radius: 12px; margin-bottom: 15px;
            background: #f8faff; border: 1px solid #e8f0fe;
        }
        .feedback-message p { color: #4a6a8c; margin-bottom: 5px; }
        .feedback-message .stars-display { font-size: 28px; margin: 5px 0; }

        /* TERMS MODAL */
        .terms-modal { z-index: 3000 !important; }
        .terms-modal-content {
            max-width: 720px !important; padding: 0 !important;
            overflow: hidden !important; display: flex !important;
            flex-direction: column; max-height: 92vh !important;
        }
        .terms-modal-header {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            color: white; padding: 25px 30px;
            text-align: center; position: relative;
            overflow: hidden; flex-shrink: 0;
        }
        .terms-modal-icon {
            font-size: 42px; margin-bottom: 8px;
            position: relative; z-index: 1; color: #F4B400;
        }
        .terms-modal-header h3 {
            font-size: 22px; font-weight: 700;
            margin: 0 0 4px 0; color: white;
            position: relative; z-index: 1;
        }
        .terms-modal-header p {
            font-size: 13px; opacity: 0.9; margin: 0;
            color: #e0eeff; position: relative; z-index: 1;
        }
        .terms-modal-close {
            position: absolute; top: 14px; right: 14px;
            background: rgba(255,255,255,0.15); color: white;
            border: 1px solid rgba(255,255,255,0.25);
            width: 38px; height: 38px; border-radius: 50%;
            cursor: pointer; font-size: 16px; display: flex;
            align-items: center; justify-content: center;
            transition: all 0.25s ease; z-index: 5;
        }
        .terms-modal-close:hover {
            background: #ef4444; border-color: #ef4444;
            transform: rotate(90deg);
        }
        .terms-scroll-container {
            flex: 1; overflow-y: auto;
            padding: 25px 30px; background: white;
            min-height: 0; scroll-behavior: smooth;
        }
        .terms-scroll-container::-webkit-scrollbar { width: 8px; }
        .terms-scroll-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .terms-scroll-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .terms-scroll-container::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .terms-section { margin-bottom: 30px; }
        .terms-section:last-child { margin-bottom: 0; }
        .terms-section-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 18px; font-weight: 700; color: #0B2447;
            padding-bottom: 12px; border-bottom: 2px solid #4DA6D9;
            margin-bottom: 15px;
        }
        .terms-section-title i { color: #4DA6D9; font-size: 20px; }
        .terms-section-body {
            font-size: 13.5px; line-height: 1.7;
            color: #334155; white-space: pre-wrap;
            word-wrap: break-word;
        }
        .terms-section-body h1,
        .terms-section-body h2,
        .terms-section-body h3 {
            color: #0B2447; margin: 16px 0 8px; font-weight: 700;
        }
        .terms-section-body h1 { font-size: 18px; }
        .terms-section-body h2 { font-size: 16px; }
        .terms-section-body h3 { font-size: 14px; }
        .terms-section-body p { margin: 0 0 12px; }
        .terms-section-body ul,
        .terms-section-body ol { padding-left: 22px; margin: 8px 0 12px; }
        .terms-section-body li { margin-bottom: 5px; }
        .terms-divider { text-align: center; margin: 25px 0; position: relative; }
        .terms-divider::before {
            content: ''; position: absolute;
            top: 50%; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
        }
        .terms-divider span {
            position: relative; background: white;
            padding: 0 15px; color: #94a3b8;
            font-size: 12px; font-weight: 600;
            letter-spacing: 1px;
        }
        .terms-scroll-hint {
            text-align: center; padding: 12px;
            background: #fef3c7; color: #92400e;
            font-size: 12.5px; font-weight: 600;
            border-top: 1px solid #fde68a;
            flex-shrink: 0; transition: all 0.3s;
        }
        .terms-scroll-hint.done {
            background: #d1fae5; color: #065f46;
            border-top-color: #a7f3d0;
        }
        #termsAcceptForm {
            padding: 15px 25px 20px;
            border-top: 2px solid #e2e8f0;
            background: #f8fafc; flex-shrink: 0;
            display: none; animation: slideUp 0.3s ease;
        }
        #termsAcceptForm.visible { display: block; }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .terms-checkbox-label {
            display: flex; align-items: flex-start;
            gap: 10px; cursor: pointer;
            padding: 12px 14px; background: white;
            border: 2px solid #e2e8f0; border-radius: 10px;
            margin-bottom: 12px; transition: all 0.2s;
            font-weight: 500; font-size: 13.5px;
            color: #1e293b; line-height: 1.5;
        }
        .terms-checkbox-label:hover {
            border-color: #4DA6D9; background: #f0f7fb;
        }
        .terms-checkbox-label input[type="checkbox"] {
            width: 20px; height: 20px; cursor: pointer;
            accent-color: #4DA6D9; margin-top: 1px; flex-shrink: 0;
        }
        .terms-checkbox-text { flex: 1; }
        .terms-accept-btn {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white; border: none; border-radius: 10px;
            font-weight: 700; font-size: 15px;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center;
            justify-content: center; gap: 8px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        .terms-accept-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45);
        }
        .terms-accept-btn:disabled {
            background: #cbd5e1; cursor: not-allowed;
            box-shadow: none; transform: none; color: #94a3b8;
        }
        .terms-modal-footer-note {
            text-align: center; padding: 10px 20px 15px;
            font-size: 11.5px; color: #94a3b8;
            background: #f8fafc; margin: 0; flex-shrink: 0;
        }

        /* LOGOUT MODAL */
        .logout-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(11, 36, 71, 0.6);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999; align-items: center; justify-content: center;
            padding: 20px; animation: logoutFadeIn 0.2s ease;
            overscroll-behavior: contain;
        }
        .logout-modal-overlay.show { display: flex; }
        @keyframes logoutFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .logout-modal {
            background: white; border-radius: 24px;
            max-width: 400px; width: 100%;
            padding: 35px 30px 25px; text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            animation: logoutSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            border-top: 6px solid #ef4444;
            max-height: 90vh; overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        @keyframes logoutSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.9); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .logout-modal-icon {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-radius: 50%; display: flex;
            align-items: center; justify-content: center;
            margin: 0 auto 18px; font-size: 36px;
            color: #ef4444;
            animation: logoutPulse 2s ease-in-out infinite;
        }
        @keyframes logoutPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); }
        }
        .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .logout-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel,
        .btn-logout-confirm {
            flex: 1; min-width: 130px; min-height: 48px;
            padding: 13px 18px; border: none; border-radius: 12px;
            font-weight: 700; font-size: 14px; cursor: pointer;
            transition: all 0.25s;
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
            color: white; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
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

        /* ============================================================
           SHOPEE-STYLE VIEW MODAL
           ============================================================ */
        .shopee-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 4000;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: shopeeFadeIn 0.25s ease;
            overscroll-behavior: contain;
        }
        .shopee-modal.show { display: flex; }
        @keyframes shopeeFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .shopee-modal-content {
            background: white;
            border-radius: 24px;
            width: 100%;
            max-width: 980px;
            max-height: 92vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 30px 80px rgba(0,0,0,0.35);
            animation: shopeeSlideUp 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }
        @keyframes shopeeSlideUp { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .shopee-close-btn {
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 20;
            background: rgba(0,0,0,0.45);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s;
        }
        .shopee-close-btn:hover { background: #ef4444; transform: rotate(90deg); }

        .shopee-modal-body {
            display: flex;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        .shopee-gallery {
            width: 45%;
            background: #f4f7fa;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            border-right: 1px solid #e8f0fe;
        }
        .shopee-main-image-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            min-height: 0;
            position: relative;
        }
        .shopee-main-image {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            border-radius: 16px;
            object-fit: contain;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            transition: opacity 0.3s;
        }
        .shopee-main-image.placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 80px;
            color: #cbd5e1;
            background: #eef2f7;
        }
        .shopee-thumbnails {
            display: flex;
            gap: 8px;
            padding: 12px 20px 20px;
            overflow-x: auto;
            flex-shrink: 0;
            justify-content: center;
            flex-wrap: wrap;
        }
        .shopee-thumb {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 3px solid transparent;
            cursor: pointer;
            transition: all 0.25s;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .shopee-thumb:hover { transform: translateY(-2px); }
        .shopee-thumb.active { border-color: #F4B400; box-shadow: 0 4px 12px rgba(244,180,0,0.3); }

        .shopee-details {
            flex: 1;
            padding: 30px 32px 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-width: 0;
            background: white;
        }
        .shopee-details::-webkit-scrollbar { width: 6px; }
        .shopee-details::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .shopee-detail-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .shopee-detail-badges .badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .shopee-detail-title {
            font-size: 24px;
            font-weight: 800;
            color: #0B2447;
            line-height: 1.3;
            margin-bottom: 6px;
        }
        .shopee-detail-category {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .shopee-price-section {
            background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 20px;
            border: 1px solid #d4e4f0;
        }
        .shopee-price-main {
            font-size: 34px;
            font-weight: 800;
            color: #4DA6D9;
            line-height: 1.1;
        }
        .shopee-price-main .unit {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            margin-left: 4px;
        }
        .shopee-price-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
            font-weight: 500;
        }
        .shopee-price-note {
            font-size: 12.5px;
            color: #92400e;
            margin-top: 8px;
            padding: 8px 12px;
            background: #fef3c7;
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 6px;
            line-height: 1.4;
        }
        .shopee-price-note i { color: #f59e0b; margin-top: 2px; flex-shrink: 0; }

        .shopee-detail-section {
            margin-bottom: 20px;
        }
        .shopee-detail-section h4 {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .shopee-detail-section h4 i { color: #4DA6D9; }
        .shopee-detail-section p {
            font-size: 13.5px;
            color: #475569;
            line-height: 1.7;
            margin: 0;
        }

        .shopee-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .shopee-status.available { background: #d1fae5; color: #065f46; }
        .shopee-status.unavailable { background: #fee2e2; color: #991b1b; }
        .shopee-status i { font-size: 13px; }

        .shopee-actions {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid #e8f0fe;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .shopee-actions .btn-secondary {
            flex: 1;
            padding: 15px 24px;
            background: #f1f5f9;
            color: #475569;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s;
            white-space: nowrap;
        }
        .shopee-actions .btn-secondary:hover { background: #e2e8f0; transform: translateY(-2px); }

        @media (max-width: 820px) {
            .shopee-modal-body { flex-direction: column; }
            .shopee-gallery {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #e8f0fe;
                max-height: 320px;
            }
            .shopee-main-image-wrap { padding: 16px; min-height: 200px; }
            .shopee-main-image { max-height: 240px; }
            .shopee-thumbnails { padding: 10px 14px 14px; }
            .shopee-thumb { width: 48px; height: 48px; }
            .shopee-details { padding: 20px 20px 20px; }
            .shopee-detail-title { font-size: 20px; }
            .shopee-price-main { font-size: 28px; }
            .shopee-actions { flex-direction: column; }
            .shopee-actions .btn-secondary { width: 100%; }
        }
        @media (max-width: 480px) {
            .shopee-modal { padding: 10px; }
            .shopee-modal-content { border-radius: 18px; max-height: 95vh; }
            .shopee-gallery { max-height: 240px; }
            .shopee-main-image-wrap { padding: 12px; min-height: 150px; }
            .shopee-main-image { max-height: 180px; }
            .shopee-thumb { width: 40px; height: 40px; }
            .shopee-details { padding: 16px 16px 16px; }
            .shopee-detail-title { font-size: 18px; }
            .shopee-price-main { font-size: 24px; }
            .shopee-price-section { padding: 14px 16px; }
            .shopee-close-btn { width: 34px; height: 34px; font-size: 15px; top: 10px; right: 10px; }
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .header-content { flex-direction: row; justify-content: space-between; gap: 12px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder { height: 40px; width: 40px; }
            .brand-text .brand-name { font-size: 17px; }
            .brand-text .brand-tagline { font-size: 10px; }
            .hero { padding: 100px 16px 50px; }
            .hero h1 { font-size: 32px; }
            .hero p { font-size: 16px; }
            .activity-grid { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; max-width: 95%; }
        }
        @media (max-width: 600px) {
            .terms-modal-content { max-width: 95% !important; max-height: 94vh !important; }
            .terms-modal-header { padding: 20px; }
            .terms-modal-header h3 { font-size: 18px; }
            .terms-modal-icon { font-size: 34px; }
            .terms-modal-close { width: 32px; height: 32px; font-size: 14px; top: 10px; right: 10px; }
            .terms-scroll-container { padding: 18px 20px; }
            .terms-section-title { font-size: 16px; }
            .terms-section-body { font-size: 13px; }
            .terms-scroll-hint { font-size: 11.5px; padding: 10px; }
            #termsAcceptForm { padding: 12px 18px 15px; }
            .terms-checkbox-label { font-size: 12.5px; padding: 10px 12px; }
            .terms-accept-btn { font-size: 14px; padding: 12px; }
        }
        @media (max-width: 480px) {
            .modal-content { padding: 15px; }
            .brand-text .brand-name { font-size: 15px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder { height: 35px; width: 35px; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .sidebar { width: 85%; max-width: 300px; left: -320px; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon,
            .sidebar-header .logo img { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
            .hero h1 { font-size: 28px; }
            .activity-card .activity-image-wrapper { height: 160px; }
            .activity-card .activity-image-placeholder { font-size: 40px; }
            .activity-card .price-tag { font-size: 20px; }
        }
    
/* ACTIVITY CARD THEME FIX */
.activity-card {
    background:#ffffff !important;
    border:1px solid #e8f0fe !important;
    border-radius:24px !important;
    box-shadow:0 15px 40px rgba(6,38,61,.08) !important;
}
.activity-card .activity-content h4 {
    color:#06263D !important;
    font-weight:800 !important;
}
.activity-card .activity-category {
    color:#64748B !important;
}
.activity-card .activity-desc {
    color:#475569 !important;
}
.activity-card .price-tag {
    color:#0B7CC1 !important;
    font-weight:800 !important;
}
.activity-card .price-tag small {
    color:#64748B !important;
}

/* ============================================================
   ACTIVITIES CARD LAYOUT FIX
   ============================================================ */

.activity-grid {
    display:grid;
    grid-template-columns:repeat(3, minmax(280px, 1fr));
    gap:30px;
    align-items:stretch;
}

.activity-card {
    display:flex;
    flex-direction:column;
    height:100%;
}

.activity-card .activity-image-wrapper {
    height:220px;
}

.activity-card .activity-content {
    display:flex;
    flex-direction:column;
    flex:1;
    padding:22px;
}

.activity-card .activity-desc {
    min-height:65px;
}

.activity-card .price-tag {
    margin-top:auto !important;
    padding-top:18px;
}

/* responsive */
@media(max-width:992px){
    .activity-grid {
        grid-template-columns:repeat(2,1fr);
    }
}

@media(max-width:600px){
    .activity-grid {
        grid-template-columns:1fr;
    }
}

</style>
</head>
<body>
<?php include 'components/navbar.php'; ?>

<!-- ALERTS -->
<?php if(isset($success) && !isset($_GET['feedback_success'])): ?>
<div class="alert-overlay">
    <div class="alert-box success">
        <i class="fas fa-check-circle" style="color: #10b981;"></i> <?php echo $success; ?>
    </div>
</div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert-overlay">
    <div class="alert-box error">
        <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i> <?php echo $error; ?>
    </div>
</div>
<?php endif; ?>

<!-- ✅ CENTERED SUCCESS POPUP — for overall feedback -->
<?php if(isset($success) && isset($_GET['feedback_success']) || isset($_GET['feedback_success'])): ?>
<div class="alert-overlay show" id="feedbackSuccessAlert">
    <div class="alert-box success-popup">
        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Thank You!</h3>
        <p><?php echo isset($success) ? htmlspecialchars($success) : 'Your feedback has been recorded.'; ?></p>
        <button class="btn-popup-ok" onclick="dismissAlert()">OK</button>
    </div>
</div>
<?php endif; ?>

<!-- HERO -->
<div class="hero">
    <div class="hero-content">
        <h1><i class="fas fa-water"></i> Activities</h1>
        <p>Discover the best activities at Hundred Islands</p>
    </div>
</div>

<!-- MAIN CONTAINER -->
<div class="main-container">  

    <div class="section-title">
        <h2>Activities at Hundred Islands 🏖️</h2>
        <div class="underline"></div>
    </div>
    
    <?php if(empty($activities)): ?>
        <div style="text-align: center; padding: 60px; background: white; border-radius: 20px;">
            <i class="fas fa-water" style="font-size: 60px; color: #cbd5e1; margin-bottom: 20px;"></i>
            <h3>No Activities Available</h3>
            <p>Please check back later for exciting activities!</p>
        </div>
    <?php else: ?>
        <?php foreach($grouped_activities as $category => $category_activities): ?>
        <div class="category-section">
            <div class="category-title">
                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($category); ?>
                <span class="badge-count"><?php echo count($category_activities); ?> activities</span>
            </div>
            
            <div class="activity-grid">
                <?php foreach($category_activities as $activity): ?>
                <div class="activity-card" onclick="openShopeeView(<?php echo $activity['id']; ?>)">
                    <div class="activity-image-wrapper">
                        <?php 
                        $image_path = getActivityImage($activity['image'] ?? '');
                        if($image_path): 
                        ?>
                            <img src="<?php echo htmlspecialchars($image_path); ?>?<?php echo time(); ?>" alt="<?php echo htmlspecialchars($activity['name']); ?>" class="activity-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="activity-image-placeholder" style="display: none;">
                                <i class="<?php echo getActivityIcon($activity); ?>"></i>
                            </div>
                        <?php else: ?>
                            <div class="activity-image-placeholder">
                                <i class="<?php echo getActivityIcon($activity); ?>"></i>
                            </div>
                        <?php endif; ?>
                        
                        <?php if($activity['is_featured']): ?>
                        <span class="featured-badge"><i class="fas fa-star"></i> Popular</span>
                        <?php endif; ?>
                        
                        <span class="status-badge status-<?php echo $activity['status'] == 'available' ? 'available' : 'unavailable'; ?>">
                            <i class="fas fa-<?php echo $activity['status'] == 'available' ? 'check-circle' : 'times-circle'; ?>"></i>
                            <?php echo ucfirst($activity['status']); ?>
                        </span>
                    </div>
                    
                    <div class="activity-content">
                        <h4><?php echo htmlspecialchars($activity['name']); ?></h4>
                        <span class="activity-category"><?php echo htmlspecialchars($activity['category']); ?></span>
                        
                        <div class="activity-desc"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></div>
                        
                        <?php 
                        $price_display = formatActivityPrice($activity);
                        if($price_display): 
                        ?>
                            <div class="price-tag"><?php echo $price_display; ?></div>
                        <?php endif; ?>
                        
                        <?php if(!empty($activity['price_note'])): ?>
                            <div class="price-note">
                                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($activity['price_note']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
</div>

<!-- ============================================================
     SHOPEE-STYLE VIEW MODAL
     ============================================================ -->
<div class="shopee-modal" id="shopeeViewModal" onclick="if(event.target === this) closeShopeeView()">
    <div class="shopee-modal-content">
        <button class="shopee-close-btn" onclick="closeShopeeView()" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="shopee-modal-body" id="shopeeModalBody">
            <!-- Content will be injected by JS -->
        </div>
    </div>
</div>

<!-- ============================================================
     ✅ OVERALL FEEDBACK MODAL
     ============================================================ -->
<div class="modal" id="overallFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-star" style="color: #f59e0b;"></i>
                <?php echo $user_has_feedback ? 'Edit Your Review' : 'Rate Your Experience'; ?>
            </h3>
            <span class="close" onclick="closeOverallFeedbackModal()">&times;</span>
        </div>

        <div id="overallFeedbackContent">
            <?php if(isset($_SESSION['user_id'])): ?>

                <?php if($user_has_feedback): ?>
                    <div class="feedback-message">
                        <p>You previously rated:</p>
                        <div class="stars-display">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star"
                                   style="color: <?php echo $i <= (int)$user_feedback['rating'] ? '#f59e0b' : '#d4dce4'; ?>;"></i>
                            <?php endfor; ?>
                        </div>
                        <p style="font-size: 13px; margin-top: 5px;">Update your rating and review below</p>
                    </div>
                <?php else: ?>
                    <p style="color: #4a6a8c; margin-bottom: 15px; text-align: center;">
                        How would you rate your overall experience with us?
                    </p>
                <?php endif; ?>

                <form method="POST" action="activities.php" id="overallFeedbackForm">
                    <input type="hidden" name="submit_feedback" value="1">

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; text-align: center; font-weight: 600; color: #0B2447; margin-bottom: 10px;">
                            Your Overall Rating
                        </label>
                        <div class="rating-input" id="overallRatingInput">
                            <i class="fas fa-star" data-rating="1" onclick="setOverallRating(1)"></i>
                            <i class="fas fa-star" data-rating="2" onclick="setOverallRating(2)"></i>
                            <i class="fas fa-star" data-rating="3" onclick="setOverallRating(3)"></i>
                            <i class="fas fa-star" data-rating="4" onclick="setOverallRating(4)"></i>
                            <i class="fas fa-star" data-rating="5" onclick="setOverallRating(5)"></i>
                        </div>
                        <input type="hidden" name="rating" id="overall_feedback_rating"
                               value="<?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>" required>
                        <p id="overallRatingText" style="text-align: center; color: #4a6a8c; margin-top: 5px;">
                            <?php
                            if($user_has_feedback && $user_feedback['rating'] > 0) {
                                $ratingTexts = [1=>'Very Poor', 2=>'Poor', 3=>'Average', 4=>'Good', 5=>'Excellent!'];
                                echo $ratingTexts[$user_feedback['rating']] ?? 'Select a rating';
                            } else {
                                echo 'Select a rating';
                            }
                            ?>
                        </p>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0B2447;">
                            Your Comment
                        </label>
                        <textarea name="comment" class="form-control" rows="3"
                                  placeholder="Share your overall experience..."
                                  style="resize: vertical; width: 100%; padding: 12px; border: 1px solid #d4e4f0; border-radius: 8px; background: #f8faff; color: #1a3a5c;"><?php echo $user_has_feedback ? htmlspecialchars($user_feedback['comment']) : ''; ?></textarea>
                    </div>

                    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; padding: 10px 15px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <input type="checkbox" name="is_anonymous" id="overall_is_anonymous" value="1"
                               <?php echo ($user_has_feedback && !empty($user_feedback['is_anonymous'])) ? 'checked' : ''; ?>>
                        <label for="overall_is_anonymous" style="margin-bottom: 0; cursor: pointer; font-weight: 500; color: #1e293b;">
                            <i class="fas fa-user-secret" style="color: #7c3aed;"></i> Post as Anonymous
                        </label>
                        <span style="font-size: 12px; color: #94a3b8; margin-left: auto;">
                            Your name will not be shown publicly
                        </span>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn"
                                onclick="closeOverallFeedbackModal()"
                                style="flex: 1; padding: 12px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit"
                                style="flex: 2; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);">
                            <i class="fas fa-paper-plane"></i>
                            <?php echo $user_has_feedback ? 'Update Review' : 'Submit Review'; ?>
                        </button>
                    </div>
                </form>

            <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #4a6a8c;">
                    <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 15px; color: #4DA6D9;"></i>
                    <p>Please <a href="login.php" style="color: #4DA6D9; font-weight: 600; text-decoration: none;">login</a> to leave a review.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     ✨ TERMS & PRIVACY MODAL
     ============================================================ -->
<div class="modal terms-modal" id="termsModal"
     data-must-accept="<?php echo $force_must_accept ? '1' : '0'; ?>">
    <div class="modal-content terms-modal-content">

        <div class="terms-modal-header">
            <div class="terms-modal-icon">
                <i class="fas fa-file-contract"></i>
            </div>
            <h3 id="termsModalTitle">Before You Continue</h3>
            <p id="termsModalSubtitle">Please review our Terms &amp; Privacy Policy</p>

            <button type="button"
                    class="terms-modal-close"
                    id="termsModalCloseBtn"
                    onclick="closeTermsReadOnly()"
                    aria-label="Close"
                    style="display: none;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="terms-scroll-container" id="termsScrollContainer">
            <div class="terms-section" id="termsSection">
                <div class="terms-section-title">
                    <i class="fas fa-scroll"></i>
                    <span id="termsTitleText"><?php echo htmlspecialchars($termsContent['terms_title']); ?></span>
                </div>
                <div class="terms-section-body" id="termsBody">
                    <?php
                    $termsBody = $termsContent['terms_body'];
                    if (preg_match('/<[^>]+>/', $termsBody)) {
                        echo $termsBody;
                    } else {
                        echo nl2br(htmlspecialchars($termsBody));
                    }
                    ?>
                </div>
            </div>

            <div class="terms-divider">
                <span>END OF TERMS &amp; CONDITIONS</span>
            </div>

            <div class="terms-section" id="privacySection">
                <div class="terms-section-title">
                    <i class="fas fa-shield-alt"></i>
                    <span id="privacyTitleText"><?php echo htmlspecialchars($termsContent['privacy_title']); ?></span>
                </div>
                <div class="terms-section-body" id="privacyBody">
                    <?php
                    $privacyBody = $termsContent['privacy_body'];
                    if (preg_match('/<[^>]+>/', $privacyBody)) {
                        echo $privacyBody;
                    } else {
                        echo nl2br(htmlspecialchars($privacyBody));
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="terms-scroll-hint" id="termsScrollHint">
            <i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox
        </div>

        <form method="POST" id="termsAcceptForm">
            <label class="terms-checkbox-label" for="terms_agree">
                <input type="checkbox" name="agree" id="terms_agree" value="1" disabled>
                <span class="terms-checkbox-text">
                    <i class="fas fa-check-circle" style="color: #10b981;"></i>
                    I understand and accept the <strong>Terms &amp; Conditions</strong> and <strong>Privacy Policy</strong>.
                </span>
            </label>
            <button type="submit" name="accept_terms" class="terms-accept-btn" id="termsAcceptBtn" disabled>
                <i class="fas fa-check"></i> Accept &amp; Continue
            </button>
        </form>

        <p class="terms-modal-footer-note" id="termsModalFooterNote">
            You can review these documents anytime from the footer links.
        </p>
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
            <a href="logout.php" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<!-- FOOTER -->
<?php include 'components/footer.php'; ?>

<!-- JAVASCRIPT -->
<script>
// ============================================================
// ✅ SHOPEE-STYLE VIEW MODAL — ACTIVITY DATA
// ============================================================
const activityData = <?php
    $ad = [];
    foreach($activities as $activity) {
        $image_path = getActivityImage($activity['image'] ?? '');
        $ad[$activity['id']] = [
            'id' => (int)$activity['id'],
            'name' => $activity['name'],
            'category' => $activity['category'] ?? 'Other',
            'description' => $activity['description'] ?? '',
            'price' => (float)($activity['price'] ?? 0),
            'price_unit' => $activity['price_unit'] ?? '',
            'price_note' => $activity['price_note'] ?? '',
            'status' => $activity['status'] ?? 'available',
            'is_featured' => !empty($activity['is_featured']),
            'image_url' => $image_path,
            'icon' => getActivityIcon($activity),
        ];
    }
    echo json_encode($ad);
?>;

function openShopeeView(activityId) {
    const activity = activityData[activityId];
    if (!activity) return;

    const isAvailable = (activity.status === 'available');
    const isFeatured = activity.is_featured;

    // Build badges
    let badgesHtml = '';
    if (isFeatured) {
        badgesHtml += '<span class="badge" style="background:#F4B400; color:#0B2447; padding:4px 14px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px;"><i class="fas fa-star"></i> Popular</span>';
    }
    badgesHtml += '<span class="badge" style="background:rgba(77,166,217,0.9); color:white; padding:4px 14px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px;"><i class="fas fa-tag"></i> ' + escapeHtml(activity.category) + '</span>';

    // Main image
    let mainImageHtml = '';
    if (activity.image_url) {
        mainImageHtml = '<img class="shopee-main-image" src="' + activity.image_url + '?<?php echo time(); ?>" alt="' + escapeHtml(activity.name) + '" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';"><div class="shopee-main-image placeholder" style="display:none;"><i class="' + activity.icon + '"></i></div>';
    } else {
        mainImageHtml = '<div class="shopee-main-image placeholder"><i class="' + activity.icon + '"></i></div>';
    }

    // Price section
    let priceHtml = '';
    if (activity.price > 0) {
        priceHtml = '<div class="shopee-price-section">';
        priceHtml += '<div class="shopee-price-main">₱' + formatNumber(activity.price);
        if (activity.price_unit) {
            priceHtml += '<span class="unit">' + escapeHtml(activity.price_unit) + '</span>';
        }
        priceHtml += '</div>';
        priceHtml += '<div class="shopee-price-label">Price</div>';
        if (activity.price_note) {
            priceHtml += '<div class="shopee-price-note"><i class="fas fa-info-circle"></i> <span>' + escapeHtml(activity.price_note) + '</span></div>';
        }
        priceHtml += '</div>';
    } else if (activity.price_note) {
        priceHtml = '<div class="shopee-price-section"><div class="shopee-price-note" style="margin-top:0;"><i class="fas fa-info-circle"></i> <span>' + escapeHtml(activity.price_note) + '</span></div></div>';
    }

    // Status badge
    let statusHtml = '';
    if (isAvailable) {
        statusHtml = '<div class="shopee-status available"><i class="fas fa-check-circle"></i> Available</div>';
    } else {
        statusHtml = '<div class="shopee-status unavailable"><i class="fas fa-times-circle"></i> Unavailable</div>';
    }

    // Description
    let descHtml = '';
    if (activity.description) {
        descHtml = '<div class="shopee-detail-section"><h4><i class="fas fa-align-left"></i> Description</h4><p>' + nl2br(escapeHtml(activity.description)) + '</p></div>';
    }

    const bodyHtml = `
        <div class="shopee-gallery">
            <div class="shopee-main-image-wrap">
                ${mainImageHtml}
            </div>
        </div>
        <div class="shopee-details">
            <div class="shopee-detail-badges">${badgesHtml}</div>
            <h2 class="shopee-detail-title">${escapeHtml(activity.name)}</h2>
            <div class="shopee-detail-category"><i class="fas fa-tag"></i> ${escapeHtml(activity.category)}</div>
            ${statusHtml}
            ${priceHtml}
            ${descHtml}
            <div class="shopee-actions">
                <button class="btn-secondary" onclick="closeShopeeView()"><i class="fas fa-arrow-left"></i> Back</button>
            </div>
        </div>
    `;

    document.getElementById('shopeeModalBody').innerHTML = bodyHtml;
    document.getElementById('shopeeViewModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeShopeeView() {
    document.getElementById('shopeeViewModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Helpers
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

function formatNumber(num) {
    return parseFloat(num).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function nl2br(str) {
    return str.replace(/\n/g, '<br>');
}

// ============================================================
// ✅ DISMISS CENTERED ALERT POPUP
// ============================================================
function dismissAlert() {
    document.querySelectorAll('.alert-overlay').forEach(function(alert) {
        alert.style.display = 'none';
        alert.classList.remove('show');
    });
    if (window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('feedback_success');
        window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
    }
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

window.onclick = function(event) {
    if (!event.target.classList.contains('modal')) return;

    if (event.target.id === 'termsModal') {
        var termsModal = document.getElementById('termsModal');
        if (termsModal.dataset.mustAccept === '1') return;
        termsModal.classList.remove('show');
        document.body.style.overflow = 'auto';
        return;
    }

    event.target.classList.remove('show');
    document.body.style.overflow = 'auto';
}

setTimeout(function() {
    document.querySelectorAll('.alert-overlay:not(.show)').forEach(function(alert) {
        alert.style.display = 'none';
    });
}, 5000);

// ============================================================
// OVERALL FEEDBACK
// ============================================================
var overallSelectedRating = <?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>;
var overallRatingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

function openOverallFeedbackModal(event) {
    if (event) event.preventDefault();
    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();
    document.getElementById('overallFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeOverallFeedbackModal() {
    document.getElementById('overallFeedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function setOverallRating(rating) {
    overallSelectedRating = rating;
    document.getElementById('overall_feedback_rating').value = rating;
    document.getElementById('overallRatingText').textContent = overallRatingTexts[rating] || 'Select a rating';

    document.querySelectorAll('#overallRatingInput i').forEach(function(star, index) {
        star.classList.toggle('active', index < rating);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if($user_has_feedback && $user_feedback['rating'] > 0): ?>
        var rating = <?php echo (int)$user_feedback['rating']; ?>;
        document.querySelectorAll('#overallRatingInput i').forEach(function(star, index) {
            star.classList.toggle('active', index < rating);
        });
        document.getElementById('overallRatingText').textContent = overallRatingTexts[rating] || 'Select a rating';
    <?php endif; ?>
});

// ============================================================
// LOGOUT CONFIRMATION MODAL
// ============================================================
function openLogoutModal(event) {
    if (event) event.preventDefault();

    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();

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
        if (modal && modal.classList.contains('show')) closeLogoutModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeLogoutModal();
        });
    }
});

// ============================================================
// TERMS MODAL LOGIC
// ============================================================
var __termsState = { modal: null, mustAccept: false, hasReachedBottom: false };

(function () {
    var modal = document.getElementById('termsModal');
    if (!modal) return;

    __termsState.modal = modal;
    __termsState.mustAccept = modal.dataset.mustAccept === '1';

    var closeBtn      = document.getElementById('termsModalCloseBtn');
    var acceptForm    = document.getElementById('termsAcceptForm');
    var scrollHint    = document.getElementById('termsScrollHint');
    var scrollBox     = document.getElementById('termsScrollContainer');
    var checkbox      = document.getElementById('terms_agree');
    var acceptBtn     = document.getElementById('termsAcceptBtn');
    var titleEl       = document.getElementById('termsModalTitle');
    var subtitleEl    = document.getElementById('termsModalSubtitle');

    function unlockAcceptForm() {
        if (!__termsState.mustAccept) return;
        if (__termsState.hasReachedBottom) return;

        __termsState.hasReachedBottom = true;

        acceptForm.classList.add('visible');
        checkbox.disabled = false;

        scrollHint.classList.add('done');
        scrollHint.innerHTML = '<i class="fas fa-check-circle"></i> You\'ve read everything. Please check the box below.';

        setTimeout(function() {
            scrollBox.scrollTo({ top: scrollBox.scrollHeight, behavior: 'smooth' });
        }, 100);
    }

    function wireScrollListener() {
        if (!scrollBox) return;
        if (scrollBox.dataset.scrollWired === '1') return;
        scrollBox.dataset.scrollWired = '1';

        var needsScroll = scrollBox.scrollHeight > scrollBox.clientHeight + 5;
        if (!needsScroll) {
            unlockAcceptForm();
        }

        scrollBox.addEventListener('scroll', function () {
            var atBottom = (scrollBox.scrollTop + scrollBox.clientHeight) >= (scrollBox.scrollHeight - 15);
            if (atBottom) {
                unlockAcceptForm();
            }
        });
    }

    window.openTermsMustAccept = function () {
        __termsState.mustAccept = true;
        __termsState.hasReachedBottom = false;
        modal.dataset.mustAccept = '1';

        titleEl.textContent = 'Before You Continue';
        subtitleEl.textContent = 'Please review our Terms & Privacy Policy';

        closeBtn.style.display = 'none';
        scrollHint.style.display = 'block';
        scrollHint.classList.remove('done');
        scrollHint.innerHTML = '<i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox';
        acceptForm.classList.remove('visible');
        checkbox.disabled = true;
        checkbox.checked = false;
        acceptBtn.disabled = true;

        scrollBox.scrollTop = 0;

        wireScrollListener();

        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    };

    window.openTermsReadOnly = function () {
        __termsState.mustAccept = false;
        modal.dataset.mustAccept = '0';

        titleEl.textContent = 'Terms & Privacy Policy';
        subtitleEl.textContent = 'Review our policies at any time';

        closeBtn.style.display = 'flex';
        scrollHint.style.display = 'none';
        acceptForm.classList.remove('visible');

        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    };

    window.closeTermsReadOnly = function () {
        if (__termsState.mustAccept) return;
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    };

    modal.addEventListener('click', function (e) {
        if (e.target === modal && __termsState.mustAccept) {
            e.stopPropagation();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape'
            && modal.classList.contains('show')
            && __termsState.mustAccept) {
            e.stopPropagation();
            e.preventDefault();
        }
    }, true);

    checkbox.addEventListener('change', function () {
        acceptBtn.disabled = !checkbox.checked;
    });

    if (__termsState.mustAccept) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        wireScrollListener();
    } else {
        wireScrollListener();
    }
})();

function reopenTermsModal(tabName) {
    var modal = document.getElementById('termsModal');
    if (!modal) return false;

    var mustAccept = modal.dataset.mustAccept === '1';

    if (mustAccept) {
        if (typeof window.openTermsMustAccept === 'function') {
            window.openTermsMustAccept();
        }
    } else {
        if (typeof window.openTermsReadOnly === 'function') {
            window.openTermsReadOnly();
        }
    }
    return false;
}
</script>

</body>
</html>
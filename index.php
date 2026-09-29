<?php
session_start();
require_once 'database.php';  // <-- CONNECTED TO DATABASE

// ✅ NEW: Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// ✨ TERMS: Load the terms gate (kailangan pa rin para sa post-login terms)
require_once 'includes/TermsGate.php';
$termsGate = new TermsGate($pdo);

// 🔒 Helper to get the real client IP (used by terms acceptance)
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
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

// Get logo path from database
$logo_path = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
}

// Get hero image path from database
$hero_path = 'uploads/hero/hero-bg.jpg';
if(isset($content['site_settings']['hero_image_path']) && !empty($content['site_settings']['hero_image_path'])) {
    $hero_path = $content['site_settings']['hero_image_path'];
}
$hero_exists = file_exists($hero_path);

// Get houses with ratings
$houses_count = $pdo->query("SELECT COUNT(*) FROM houses")->fetchColumn();
$tours_count = $pdo->query("SELECT COUNT(*) FROM tours")->fetchColumn();
$available_houses = $pdo->query("SELECT COUNT(*) FROM houses WHERE status = 'available'")->fetchColumn();
$available_tours = $pdo->query("SELECT COUNT(*) FROM tours WHERE status = 'available'")->fetchColumn();

// Get featured activities
$featured_activities = $pdo->query("SELECT * FROM activities WHERE is_featured = 1 AND status = 'available' ORDER BY id LIMIT 3")->fetchAll();
$activities_count = $pdo->query("SELECT COUNT(*) FROM activities")->fetchColumn();

// Get featured food items for homepage
$featured_food = $pdo->query("SELECT * FROM food_items WHERE is_featured = 1 AND is_available = 1 ORDER BY id LIMIT 3")->fetchAll();
$food_count = $pdo->query("SELECT COUNT(*) FROM food_items")->fetchColumn();

// ============================================================
// ✨ TERMS: Handle acceptance submission
// ============================================================
if (isset($_POST['accept_terms']) && isset($_SESSION['user_id'])) {
    $termsGate->accept((int)$_SESSION['user_id'], getClientIp());

    // ✅ NEW: Log terms acceptance
    if (class_exists('SystemLogger')) {
        SystemLogger::log(
            $pdo,
            'accept',
            'terms',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' accepted Terms & Privacy Policy (v" . $termsGate->getCurrentVersion() . ")",
            (int)$_SESSION['user_id'],
            'user',
            null,
            ['version' => $termsGate->getCurrentVersion(), 'ip' => getClientIp()]
        );
    }

    unset($_SESSION['show_terms_modal']);
    $_SESSION['terms_accepted'] = true;
    header("Location: index.php?terms_accepted=1");
    exit();
}

// Check for registration success
$show_registration_success = isset($_GET['registered']) && $_GET['registered'] == 1;

// ============================================================
// Handle Overall Experience Feedback
// ============================================================
if(isset($_POST['submit_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $rating = $_POST['rating'];
        $comment = $_POST['comment'];
        $user_id = $_SESSION['user_id'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        $check = $pdo->prepare("SELECT id FROM overall_feedback WHERE user_id = ?");
        $check->execute([$user_id]);
        $is_update = (bool)$check->fetch();

        if($is_update) {
            $stmt = $pdo->prepare("UPDATE overall_feedback SET rating = ?, comment = ?, updated_at = NOW(), is_anonymous = ? WHERE user_id = ?");
            $stmt->execute([$rating, $comment, $is_anonymous, $user_id]);
            $feedback_success = "Thank you! Your feedback has been updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO overall_feedback (user_id, rating, comment, is_anonymous) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $rating, $comment, $is_anonymous]);
            $feedback_success = "Thank you for your feedback! Your review has been submitted.";
        }

        // ✅ NEW: Log overall feedback
        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                $is_update ? 'update' : 'create',
                'review',
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' " . ($is_update ? "updated" : "submitted") . " overall review — Rating: {$rating}/5" . ($is_anonymous ? " (Anonymous)" : ""),
                null,
                'overall_feedback',
                null,
                ['rating' => $rating, 'anonymous' => $is_anonymous, 'has_comment' => !empty($comment)]
            );
        }

        header("Location: index.php?feedback_success=1");
        exit();

    } catch(Exception $e) {
        $feedback_error = "Failed to submit feedback: " . $e->getMessage();
    }
}

// ============================================================
// Handle Delete Feedback
// ============================================================
if(isset($_GET['delete_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $user_id = $_SESSION['user_id'];

        // ✅ NEW: Fetch feedback info before deletion for logging
        $log_stmt = $pdo->prepare("SELECT rating, comment FROM overall_feedback WHERE user_id = ?");
        $log_stmt->execute([$user_id]);
        $old_feedback = $log_stmt->fetch();

        $stmt = $pdo->prepare("DELETE FROM overall_feedback WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $feedback_success = "Your review has been deleted successfully.";

        // ✅ NEW: Log feedback deletion
        if (class_exists('SystemLogger') && $old_feedback) {
            SystemLogger::log(
                $pdo,
                'delete',
                'review',
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' deleted own overall review (was Rating: {$old_feedback['rating']}/5)",
                $user_id,
                'user',
                ['rating' => $old_feedback['rating'], 'comment' => $old_feedback['comment']],
                null,
                'warning'
            );
        }

        header("Location: index.php?feedback_success=1");
        exit();
    } catch(Exception $e) {
        $feedback_error = "Failed to delete review: " . $e->getMessage();
    }
}

// Get filter parameter for rating
$rating_filter = isset($_GET['rating_filter']) ? $_GET['rating_filter'] : 'all';

// Get overall rating stats
$overall_stats = $pdo->query("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM overall_feedback")->fetch();
$avg_rating = $overall_stats['avg_rating'] ? round($overall_stats['avg_rating'], 1) : 0;
$total_reviews = $overall_stats['total_reviews'] ?? 0;

// Get all feedback with user info for modal - with rating filter
if($rating_filter != 'all') {
    $stmt = $pdo->prepare("SELECT f.*, u.username 
                           FROM overall_feedback f 
                           JOIN users u ON f.user_id = u.id 
                           WHERE f.rating = ?
                           ORDER BY f.created_at DESC LIMIT 5");
    $stmt->execute([$rating_filter]);
    $all_feedback = $stmt->fetchAll();
} else {
    $all_feedback = $pdo->query("SELECT f.*, u.username 
                                 FROM overall_feedback f 
                                 JOIN users u ON f.user_id = u.id 
                                 ORDER BY f.created_at DESC LIMIT 5")->fetchAll();
}

// Get total count for view all
$total_feedback_count = $pdo->query("SELECT COUNT(*) FROM overall_feedback")->fetchColumn();

// Check if user already submitted feedback
$user_has_feedback = false;
$user_feedback = null;
if(isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_feedback = $stmt->fetch();
    $user_has_feedback = ($user_feedback !== false);
}

// Function to render stars
function renderStars($rating) {
    $html = '';
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5;

    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $fullStars) {
            $html .= '<i class="fas fa-star" style="color: #f59e0b; font-size: 14px;"></i>';
        } elseif ($i == $fullStars + 1 && $halfStar) {
            $html .= '<i class="fas fa-star-half-alt" style="color: #f59e0b; font-size: 14px;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color: #f59e0b; font-size: 14px;"></i>';
        }
    }
    return $html;
}

function renderTinyStars($rating) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star" style="color: #f59e0b; font-size: 11px;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color: #d1d5db; font-size: 11px;"></i>';
        }
    }
    return $html;
}

// Function to display username with *** for anonymous
function displayUsername($username, $is_anonymous = 0) {
    if($is_anonymous == 1) {
        return '***';
    }
    return htmlspecialchars($username);
}

// Get social media links from database
$facebook_link = $content['social']['facebook'] ?? '#';

// ============================================================
// Function to generate Google Maps URL
// ============================================================
function getGoogleMapsUrl($address) {
    if (empty($address) || $address == '#') {
        return '#';
    }
    $encoded_address = urlencode($address);
    return 'https://www.google.com/maps/search/?api=1&query=' . $encoded_address;
}

// Get location data from database
$location_address  = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';
$maps_url          = getGoogleMapsUrl($location_address);

// Default map if no embed URL is set
$default_map_url = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863.123456789!2d119.1234567!3d16.1234567!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTbCsDA3JzI0LjAiTiAxMTnCsDA3JzI0LjAiRQ!5e0!3m2!1sen!2sph!4v1234567890';
$map_embed       = !empty($google_maps_embed) && $google_maps_embed != '#' ? $google_maps_embed : $default_map_url;

// ============================================================
// ✨ TERMS: Compute mode for the modal
// ============================================================
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

// ============================================================
// SIDEBAR LOGO (computed once)
// ============================================================
$sidebar_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $sidebar_logo = $content['site_settings']['logo_path'];
}
$sidebar_logo_exists = !empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transient House & Tour Reservation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* RESET & BASE */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* INLINE SVG FALLBACK */
        .inline-svg-icon {
            display: inline-block;
            vertical-align: -0.125em;
            width: 1em;
            height: 1em;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .inline-svg-icon.filled {
            fill: currentColor;
            stroke: none;
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
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .logo-wrapper {
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            flex-shrink: 0;
            min-width: 0;
        }

        .logo-wrapper .logo-image {
            height: 50px;
            width: 50px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #4DA6D9;
            padding: 2px;
            background: white;
            transition: transform 0.3s ease;
            flex-shrink: 0;
        }

        .logo-wrapper .logo-image:hover {
            transform: scale(1.05);
        }

        .logo-wrapper .logo-image-placeholder {
            height: 50px;
            width: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid #4DA6D9;
            flex-shrink: 0;
        }

        .logo-wrapper .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
            min-width: 0;
        }

        .logo-wrapper .brand-text .brand-name {
            font-size: 20px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.5px;
            white-space: nowrap;
        }

        .logo-wrapper .brand-text .brand-tagline {
            font-size: 11px;
            color: #7bb8f0;
            font-weight: 500;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        /* ============================================================
           DESKTOP NAV — single line, auto-collapses to sidebar
           SIDEBAR TAKES OVER AT ≤1100px
           ============================================================ */
        .desktop-nav {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: nowrap;
            flex-shrink: 1;
            min-width: 0;
            max-width: 100%;
            /* ✅ NO overflow:hidden — logout button stays visible */
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

        /* HAMBURGER MENU */
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
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(77, 166, 217, 0.2);
        }

        .menu-toggle:hover {
            background: rgba(77, 166, 217, 0.2);
            transform: scale(1.05);
        }

        .menu-toggle .fa-bars {
            transition: transform 0.3s ease;
        }

        .menu-toggle.active .fa-bars {
            transform: rotate(90deg);
        }

        /* ✅ Hide hamburger when sidebar opens (mobile) */
        body.sidebar-open-mobile .menu-toggle {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: scale(0.8);
        }

        /* SIDEBAR */
        .sidebar {
            position: fixed;
            top: 0;
            left: -320px;
            width: 300px;
            height: 100vh;
            background: #0B2447;
            box-shadow: 4px 0 30px rgba(0,0,0,0.3);
            padding: 25px 0;
            transition: left 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            border-right: 2px solid rgba(77, 166, 217, 0.15);
        }

        .sidebar.open {
            left: 0;
        }

        .sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(77, 166, 217, 0.3);
            border-radius: 10px;
        }

        .sidebar-header {
            padding: 0 20px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 20px;
        }

        /* ✅ Sidebar header top row */
        .sidebar-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .sidebar-header .logo {
            font-size: 22px;
            font-weight: 700;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }

        .sidebar-header .logo .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
            overflow: hidden;
        }

        /* ✅ NEW: sidebar logo image */
        .sidebar-header .logo img {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            object-fit: cover;
            border: 2px solid #4DA6D9;
            padding: 2px;
            background: white;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
        }

        .sidebar-header .logo .logo-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .sidebar-header .logo .logo-text .main {
            font-size: 18px;
            font-weight: 700;
            color: white;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-header .logo .logo-text .sub {
            font-size: 10px;
            color: #7bb8f0;
            font-weight: 400;
            letter-spacing: 0.3px;
        }

        /* ✅ Inline close button */
        .sidebar-close-btn {
            display: none;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            color: #e0eeff;
            width: 36px;
            height: 36px;
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

        .nav-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .nav-item {
            margin-bottom: 2px;
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 20px;
            color: #b3d9ff;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-weight: 500;
            font-size: 14px;
            position: relative;
        }

        .nav-link i {
            width: 22px;
            font-size: 16px;
            text-align: center;
            flex-shrink: 0;
        }

        .nav-link:hover {
            background: rgba(77, 166, 217, 0.15);
            color: white;
            border-left-color: #4DA6D9;
        }

        .nav-link.active-nav {
            background: rgba(77, 166, 217, 0.2);
            color: white;
            border-left-color: #4DA6D9;
        }

        .nav-link.active-nav i {
            color: #7bb8f0;
        }

        .nav-divider {
            height: 1px;
            background: rgba(255,255,255,0.06);
            margin: 15px 20px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        /* ============================================================
           RESPONSIVE HEADER — sidebar takes over at ≤1100px
           ============================================================ */
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
            .logo-wrapper .brand-text .brand-name { font-size: 17px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 10px; }
        }

        @media (max-width: 480px) {
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .sidebar { width: 85%; max-width: 300px; }
            .header-content { padding-left: 58px; padding-right: 10px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder { height: 36px; width: 36px; border-radius: 10px; }
            .logo-wrapper .brand-text .brand-name { font-size: 15px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 9px; }
        }

        /* HERO */
        .hero {
            <?php if($hero_exists): ?>
            background: linear-gradient(rgba(11, 36, 71, 0.5), rgba(11, 36, 71, 0.6)), url('<?php echo $hero_path; ?>?<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            <?php else: ?>
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            <?php endif; ?>
            padding: 100px 0;
            color: white;
            text-align: center;
            position: relative;
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            z-index: 1;
        }

        .hero h1 {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 0 2px 25px rgba(0,0,0,0.25);
        }

        .hero h1 i { color: #7bb8f0; }

        .hero p {
            font-size: 18px;
            margin-bottom: 30px;
            opacity: 0.95;
            text-shadow: 0 1px 15px rgba(0,0,0,0.15);
        }

        /* MAIN */
        .main-container {
            max-width: 1300px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .section-title {
            text-align: center;
            margin-bottom: 40px;
        }

        .section-title h2 {
            font-size: 32px;
            font-weight: 700;
            color: #0B2447;
            margin-bottom: 10px;
        }

        .section-title h2 i { color: #4DA6D9; }

        .section-title .underline {
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #4DA6D9, #7bb8f0);
            border-radius: 2px;
            margin: 0 auto;
        }

        /* FEATURES */
        .features-section { margin-bottom: 60px; }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
        }

        .feature-card {
            background: #4DA6D9;
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2);
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(77, 166, 217, 0.3);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 28px;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .feature-card h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 10px;
            color: white;
        }

        .feature-card p {
            color: rgba(255,255,255,0.9);
            line-height: 1.6;
            font-size: 14px;
        }

        /* ACTIVITIES */
        .activities-section { margin-bottom: 60px; }

        .activities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
        }

        .activity-card {
            background: #4DA6D9;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2);
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid rgba(255,255,255,0.15);
            text-align: center;
        }

        .activity-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(77, 166, 217, 0.3);
        }

        .activity-card .activity-icon {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 28px;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .activity-card h4 {
            font-size: 18px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }

        .activity-card .activity-category {
            font-size: 13px;
            color: rgba(255,255,255,0.8);
            margin-bottom: 10px;
        }

        .activity-card .activity-price {
            font-size: 22px;
            font-weight: 700;
            color: #F4B400;
            margin-bottom: 15px;
        }

        .activity-card .activity-price small {
            font-size: 14px;
            font-weight: 400;
            color: rgba(255,255,255,0.7);
        }

        .activity-card p {
            color: rgba(255,255,255,0.85);
            font-size: 13px;
            margin-bottom: 15px;
        }

        /* FOOD */
        .food-section { margin-bottom: 60px; }

        .food-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
        }

        .food-card {
            background: #4DA6D9;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2);
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid rgba(255,255,255,0.15);
            text-align: center;
        }

        .food-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 50px rgba(77, 166, 217, 0.3);
        }

        .food-card .food-icon {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 28px;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .food-card h4 {
            font-size: 18px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }

        .food-card .food-category {
            font-size: 13px;
            color: rgba(255,255,255,0.8);
            margin-bottom: 10px;
        }

        .food-card .food-price {
            font-size: 22px;
            font-weight: 700;
            color: #F4B400;
            margin-bottom: 15px;
        }

        .food-card .food-price small {
            font-size: 14px;
            font-weight: 400;
            color: rgba(255,255,255,0.7);
        }

        .food-card p {
            color: rgba(255,255,255,0.85);
            font-size: 13px;
            margin-bottom: 15px;
        }

        /* BUTTONS */
        .btn-activity, .btn-food {
            padding: 10px 25px;
            background: #F4B400;
            color: #0B2447;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
        }

        .btn-activity:hover, .btn-food:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(244, 180, 0, 0.4);
            background: #e6a800;
            color: #0B2447;
        }

        /* CTA */
        .cta-section {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            border-radius: 30px;
            padding: 60px;
            margin: 60px 0;
            color: white;
            text-align: center;
            position: relative;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }

        .cta-section h2 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .cta-section h2 i { color: #F4B400; }

        .cta-section p {
            font-size: 18px;
            margin-bottom: 30px;
            opacity: 0.9;
        }

        .cta-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .cta-btn {
            padding: 15px 40px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.3s, box-shadow 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .cta-btn:hover { transform: translateY(-3px); }

        .btn-houses {
            background: #F4B400;
            color: #0B2447;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
        }

        .btn-houses:hover {
            background: #e6a800;
            box-shadow: 0 8px 30px rgba(244, 180, 0, 0.4);
            color: #0B2447;
        }

        .btn-tours {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .btn-tours:hover { background: rgba(255,255,255,0.25); }

        .btn-activities-cta {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .btn-activities-cta:hover { background: rgba(255,255,255,0.25); }

        .btn-food-cta {
            background: #F4B400;
            color: #0B2447;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
        }

        .btn-food-cta:hover {
            background: #e6a800;
            box-shadow: 0 8px 30px rgba(244, 180, 0, 0.4);
            color: #0B2447;
        }

        /* REVIEW */
        .review-section-wrapper {
            max-width: 1300px;
            margin: 40px auto 20px;
            padding: 0 20px;
        }

        .review-section-wrapper .section-title h2 {
            font-size: 28px;
            color: #0B2447;
        }

        .review-section-wrapper .section-title .underline {
            background: linear-gradient(90deg, #F4B400, #fbbf24);
            width: 60px;
        }

        .review-container {
            background: white;
            border-radius: 20px;
            padding: 25px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            border: 1px solid #e8f0fe;
        }

        .review-container .rating-summary {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .review-container .rating-summary .big-rating {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .review-container .rating-summary .big-rating .number {
            font-size: 32px;
            font-weight: 700;
            color: #0B2447;
        }

        .review-container .rating-summary .big-rating .stars i {
            font-size: 18px;
        }

        .review-container .rating-summary .total-reviews {
            color: #4a6a8c;
            font-size: 14px;
            font-weight: 500;
        }

        .review-container .btn-view-all-reviews {
            margin-left: auto;
            padding: 8px 20px;
            background: #F4B400;
            color: #0B2447;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);
        }

        .review-container .btn-view-all-reviews:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3);
            background: #e6a800;
        }

        @media (max-width: 768px) {
            .review-container .rating-summary {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .review-container .btn-view-all-reviews {
                margin-left: 0;
                width: 100%;
                justify-content: center;
            }
        }

        /* FOOTER MAP */
        .footer-map {
            width: 100%;
            max-width: 300px;
            height: 180px;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 14px;
            border: 1px solid rgba(255,255,255,0.15);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        .footer-map iframe {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
        }

        @media (max-width: 768px) {
            .footer-map { max-width: 100%; height: 160px; }
        }

        @media (max-width: 480px) {
            .footer-map { height: 140px; }
        }

        /* FOOTER */
        .footer {
            background: #0B2447;
            color: white;
            padding: 60px 0 20px;
            margin-top: 50px;
            border-top: 2px solid rgba(77, 166, 217, 0.15);
        }

        .footer-content {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-col h4 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-col h4 i { color: #4DA6D9; }

        .footer-col p {
            color: #b3d9ff;
            line-height: 1.7;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .footer-col ul { list-style: none; }

        .footer-col ul li {
            margin-bottom: 12px;
            color: #b3d9ff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-col ul li i { width: 20px; color: #4DA6D9; }

        .footer-col ul li a {
            color: #b3d9ff;
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-col ul li a:hover { color: white; }

        .social-links { display: flex; gap: 15px; }

        .social-links a {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .social-links a:hover {
            background: #4DA6D9;
            border-color: #7bb8f0;
            transform: translateY(-3px);
        }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.05);
            padding-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            color: #b3d9ff;
            font-size: 14px;
        }

        .footer-bottom-links { display: flex; gap: 20px; }

        .footer-bottom-links a {
            color: #b3d9ff;
            text-decoration: none;
            transition: color 0.2s;
            cursor: pointer;
        }

        .footer-bottom-links a:hover { color: white; }

        /* MODAL (GENERIC) */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal.show { display: flex; }

        .modal-content {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 30px;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e8f0fe;
        }

        .modal-header h3 {
            font-size: 24px;
            color: #0B2447;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i { color: #4DA6D9; }

        .modal-header .close {
            font-size: 28px;
            cursor: pointer;
            color: #94a8b8;
            transition: color 0.3s;
            background: none;
            border: none;
            padding: 0 10px;
            line-height: 1;
        }

        .modal-header .close:hover { color: #ef4444; }

        .form-group { margin-bottom: 15px; }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #0B2447;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .form-group label i { color: #4DA6D9; }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #d4e4f0;
            border-radius: 8px;
            font-size: 14px;
            background: #f8faff;
            color: #1a3a5c;
        }

        .form-control:focus {
            outline: none;
            border-color: #4DA6D9;
            background: white;
            box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }

        .form-control::placeholder { color: #94a8b8; }

        .btn {
            width: 100%;
            padding: 12px;
            background: #F4B400;
            color: #0B2447;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3);
            background: #e6a800;
        }

        /* ALERT OVERLAY */
        .alert-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(5px);
        }

        .alert-overlay.show { display: flex; }

        .alert-box {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 400px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .alert-box.success { border-top: 5px solid #10b981; }
        .alert-box.error { border-top: 5px solid #ef4444; }

        .alert-icon { font-size: 60px; margin-bottom: 20px; }
        .alert-icon.success { color: #10b981; }
        .alert-icon.error { color: #ef4444; }

        .alert-box h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #0B2447;
        }

        .alert-box.success h3 { color: #10b981; }
        .alert-box.error h3 { color: #ef4444; }

        .alert-box p { color: #4a6a8c; margin-bottom: 20px; }

        .alert-box .btn {
            background: #F4B400;
            color: #0B2447;
            border: none;
            padding: 10px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            width: auto;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);
        }

        .alert-box .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3);
            background: #e6a800;
        }

        /* FEEDBACK & REVIEWS */
        .rating-input {
            display: flex;
            justify-content: center;
            gap: 10px;
            font-size: 32px;
            cursor: pointer;
        }

        .rating-input i {
            color: #d4dce4;
            transition: all 0.2s;
        }

        .rating-input i:hover,
        .rating-input i.active {
            color: #f59e0b;
            transform: scale(1.1);
        }

        .feedback-message {
            background: #f8faff !important;
            border: 1px solid #e8f0fe;
        }

        .review-item {
            padding: 15px 0;
            border-bottom: 1px solid #e8f0fe;
        }

        .review-item:last-child { border-bottom: none; }

        .review-item .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .review-item .review-user {
            font-weight: 600;
            color: #0B2447;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .review-item .review-user .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 13px;
            font-weight: 600;
        }

        .review-item .review-date { font-size: 12px; color: #94a8b8; }

        .review-item .review-text {
            color: #4a6a8c;
            font-size: 14px;
            margin-top: 5px;
            padding-left: 42px;
        }

        .review-item .review-text.no-comment {
            font-style: italic;
            color: #94a8b8;
        }

        .review-item .review-stars { margin: 3px 0; }

        .no-reviews-modal {
            text-align: center;
            padding: 40px 20px;
            color: #94a8b8;
        }

        .no-reviews-modal i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #4DA6D9;
        }

        .no-reviews-modal p { font-size: 16px; }

        /* ============================================================
           ✨ TERMS MODAL — SINGLE SCROLL (Terms + Privacy combined)
           ============================================================ */
        .terms-modal { z-index: 3000 !important; }

        .terms-modal-content {
            max-width: 720px !important;
            padding: 0 !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column;
            max-height: 92vh !important;
        }

        .terms-modal-header {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            color: white;
            padding: 25px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .terms-modal-icon {
            font-size: 42px;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
            color: #F4B400;
        }

        .terms-modal-header h3 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 4px 0;
            color: white;
            position: relative;
            z-index: 1;
        }

        .terms-modal-header p {
            font-size: 13px;
            opacity: 0.9;
            margin: 0;
            color: #e0eeff;
            position: relative;
            z-index: 1;
        }

        .terms-modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
            width: 38px;
            height: 38px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
            z-index: 5;
        }

        .terms-modal-close:hover {
            background: #ef4444;
            border-color: #ef4444;
            transform: rotate(90deg);
        }

        /* ✅ SINGLE SCROLL CONTAINER */
        .terms-scroll-container {
            flex: 1;
            overflow-y: auto;
            padding: 25px 30px;
            background: white;
            min-height: 0;
            scroll-behavior: smooth;
        }

        .terms-scroll-container::-webkit-scrollbar { width: 8px; }
        .terms-scroll-container::-webkit-scrollbar-track {
            background: #f1f5f9; border-radius: 4px;
        }
        .terms-scroll-container::-webkit-scrollbar-thumb {
            background: #cbd5e1; border-radius: 4px;
        }
        .terms-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Section blocks inside the scroll */
        .terms-section {
            margin-bottom: 30px;
        }

        .terms-section:last-child {
            margin-bottom: 0;
        }

        .terms-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
            color: #0B2447;
            padding-bottom: 12px;
            border-bottom: 2px solid #4DA6D9;
            margin-bottom: 15px;
        }

        .terms-section-title i {
            color: #4DA6D9;
            font-size: 20px;
        }

        .terms-section-body {
            font-size: 13.5px;
            line-height: 1.7;
            color: #334155;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .terms-section-body h1,
        .terms-section-body h2,
        .terms-section-body h3 {
            color: #0B2447;
            margin: 16px 0 8px;
            font-weight: 700;
        }

        .terms-section-body h1 { font-size: 18px; }
        .terms-section-body h2 { font-size: 16px; }
        .terms-section-body h3 { font-size: 14px; }
        .terms-section-body p { margin: 0 0 12px; }
        .terms-section-body ul,
        .terms-section-body ol { padding-left: 22px; margin: 8px 0 12px; }
        .terms-section-body li { margin-bottom: 5px; }

        /* Divider between sections */
        .terms-divider {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }

        .terms-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
        }

        .terms-divider span {
            position: relative;
            background: white;
            padding: 0 15px;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        /* Bottom hint (while scrolling) */
        .terms-scroll-hint {
            text-align: center;
            padding: 12px;
            background: #fef3c7;
            color: #92400e;
            font-size: 12.5px;
            font-weight: 600;
            border-top: 1px solid #fde68a;
            flex-shrink: 0;
            transition: all 0.3s;
        }

        .terms-scroll-hint.done {
            background: #d1fae5;
            color: #065f46;
            border-top-color: #a7f3d0;
        }

        /* Accept form (hidden until scroll bottom) */
        #termsAcceptForm {
            padding: 15px 25px 20px;
            border-top: 2px solid #e2e8f0;
            background: #f8fafc;
            flex-shrink: 0;
            display: none;
            animation: slideUp 0.3s ease;
        }

        #termsAcceptForm.visible {
            display: block;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .terms-checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            padding: 12px 14px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 12px;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 13.5px;
            color: #1e293b;
            line-height: 1.5;
        }

        .terms-checkbox-label:hover {
            border-color: #4DA6D9;
            background: #f0f7fb;
        }

        .terms-checkbox-label input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #4DA6D9;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .terms-checkbox-text { flex: 1; }

        .terms-accept-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .terms-accept-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45);
        }

        .terms-accept-btn:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
            color: #94a3b8;
        }

        .terms-modal-footer-note {
            text-align: center;
            padding: 10px 20px 15px;
            font-size: 11.5px;
            color: #94a3b8;
            background: #f8fafc;
            margin: 0;
            flex-shrink: 0;
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

        /* ============================================================
           ✅ LOGOUT CONFIRMATION MODAL
           ============================================================ */
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

        @keyframes logoutFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

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
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 36px;
            color: #ef4444;
            animation: logoutPulse 2s ease-in-out infinite;
        }

        @keyframes logoutPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); }
        }

        .logout-modal h3 {
            font-size: 22px;
            font-weight: 700;
            color: #991b1b;
            margin-bottom: 8px;
        }

        .logout-modal p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .logout-modal-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-logout-cancel,
        .btn-logout-confirm {
            flex: 1;
            min-width: 130px;
            min-height: 48px;
            padding: 13px 18px;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }

        .btn-logout-cancel {
            background: #e2e8f0;
            color: #475569;
        }

        .btn-logout-cancel:hover,
        .btn-logout-cancel:active {
            background: #cbd5e1;
            transform: translateY(-2px);
        }

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
            .logout-modal {
                padding: 28px 22px 20px;
                border-radius: 20px;
            }

            .logout-modal-icon {
                width: 65px;
                height: 65px;
                font-size: 28px;
                margin-bottom: 14px;
            }

            .logout-modal h3 {
                font-size: 19px;
            }

            .logout-modal p {
                font-size: 13px;
                margin-bottom: 20px;
            }

            .logout-modal-actions {
                flex-direction: column-reverse;
            }

            .btn-logout-cancel,
            .btn-logout-confirm {
                width: 100%;
            }
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .hero { padding: 60px 0; }
            .hero h1 { font-size: 32px; }
            .hero p { font-size: 16px; }
            .cta-section { padding: 40px 20px; }
            .cta-section h2 { font-size: 28px; }
            .footer-bottom { flex-direction: column; text-align: center; }
            .modal-content { padding: 20px; max-width: 95%; }
            .activities-grid { grid-template-columns: 1fr; }
            .features-grid { grid-template-columns: 1fr; }
            .food-grid { grid-template-columns: 1fr; }
            .review-container .rating-summary .big-rating .number { font-size: 28px; }
        }

        @media (max-width: 480px) {
            .modal-content { padding: 15px; }
            .review-container { padding: 15px 18px; }
            .hero h1 { font-size: 28px; }
            .cta-section h2 { font-size: 24px; }
            .cta-btn { padding: 12px 25px; font-size: 14px; }
        }
    </style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <div class="sidebar-header-top">
            <a href="index.php" class="logo">
                <?php if($sidebar_logo_exists): ?>
                    <img src="<?php echo htmlspecialchars($sidebar_logo); ?>?<?php echo time(); ?>" alt="Logo">
                <?php else: ?>
                    <div class="logo-icon"><i class="fas fa-umbrella-beach"></i></div>
                <?php endif; ?>
                <div class="logo-text">
                    <span class="main">Transient House</span>
                    <span class="sub">& Tours</span>
                </div>
            </a>
            <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Close menu">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <ul class="nav-menu">
        <li class="nav-item">
            <a href="index.php" class="nav-link active-nav">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="houses.php" class="nav-link">
                <i class="fas fa-home"></i>
                <span>Houses</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="tours.php" class="nav-link">
                <i class="fas fa-umbrella-beach"></i>
                <span>Tours</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="activities.php" class="nav-link">
                <i class="fas fa-water"></i>
                <span>Activities</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="food.php" class="nav-link">
                <i class="fas fa-utensils"></i>
                <span>Food</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="packages.php" class="nav-link">
                <i class="fas fa-box-open"></i>
                <span>My Package</span>
            </a>
        </li>

        <?php if(isset($_SESSION['user_id'])): ?>
            <?php if(isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'staff')): ?>
                <li class="nav-item">
                    <a href="admin-dashboard.php" class="nav-link">
                        <i class="fas fa-cog"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            <?php else: ?>
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <i class="fas fa-user"></i>
                        <span>Profile</span>
                    </a>
                </li>
                <li class="nav-item">
                    <?php if($user_has_feedback): ?>
                        <a href="#" onclick="openFeedbackModal()" class="nav-link" style="background: #F4B400; color: #0B2447; border-radius: 8px; margin: 0 20px; justify-content: center;">
                            <i class="fas fa-star"></i>
                            <span>Edit Review</span>
                        </a>
                    <?php else: ?>
                        <a href="#" onclick="openFeedbackModal()" class="nav-link" style="background: #F4B400; color: #0B2447; border-radius: 8px; margin: 0 20px; justify-content: center;">
                            <i class="fas fa-star"></i>
                            <span>Rate Us</span>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
            <div class="nav-divider"></div>
            <li class="nav-item">
                <a href="#" class="nav-link" onclick="openLogoutModal(event); return false;" style="color: #ef4444;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        <?php else: ?>
            <div class="nav-divider"></div>
            <li class="nav-item">
                <a href="login.php" class="nav-link" style="background: #4DA6D9; color: white; border-radius: 8px; margin: 0 20px; justify-content: center;">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Login</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="register.php" class="nav-link" style="background: #F4B400; color: #0B2447; border-radius: 8px; margin: 0 20px; justify-content: center;">
                    <i class="fas fa-user-plus"></i>
                    <span>Register</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>
</div>

<!-- ══════════════ HEADER ══════════════ -->
<div class="header">
    <div class="header-content">
        <a href="index.php" class="logo-wrapper">
            <?php
            $logo_exists = false;
            $logo_actual_path = 'uploads/logos/logo.png';

            if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
                $logo_actual_path = $content['site_settings']['logo_path'];
            }

            if(!empty($logo_actual_path) && file_exists($logo_actual_path) && !is_dir($logo_actual_path)) {
                $logo_exists = true;
            }

            if(!$logo_exists && file_exists('uploads/logos/logo.png')) {
                $logo_exists = true;
                $logo_actual_path = 'uploads/logos/logo.png';
            }

            if($logo_exists):
            ?>
                <img src="<?php echo htmlspecialchars($logo_actual_path); ?>?<?php echo time(); ?>" alt="Logo" class="logo-image">
            <?php else: ?>
                <div class="logo-image-placeholder">
                    <i class="fas fa-home"></i>
                </div>
            <?php endif; ?>
            <div class="brand-text">
                <span class="brand-name">Transient House & Tours</span>
                <span class="brand-tagline">Your Home Away From Home</span>
            </div>
        </a>

        <div class="desktop-nav">
            <a href="index.php" class="active-nav"><i class="fas fa-home"></i> Home</a>
            <a href="houses.php"><i class="fas fa-home"></i> Houses</a>
            <a href="tours.php"><i class="fas fa-umbrella-beach"></i> Tours</a>
            <a href="activities.php"><i class="fas fa-water"></i> Activities</a>
            <a href="food.php"><i class="fas fa-utensils"></i> Food</a>
            <a href="packages.php"><i class="fas fa-box-open"></i> My Package</a>

            <?php if(isset($_SESSION['user_id'])): ?>
                <?php if(isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'staff')): ?>
                    <a href="admin-dashboard.php"><i class="fas fa-cog"></i> Dashboard</a>
                <?php else: ?>
                    <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                    <?php if($user_has_feedback): ?>
                        <a href="#" onclick="openFeedbackModal()" class="btn-rate">
                            <i class="fas fa-star"></i> <span class="rate-text">Edit</span>
                        </a>
                    <?php else: ?>
                        <a href="#" onclick="openFeedbackModal()" class="btn-rate">
                            <i class="fas fa-star"></i> <span class="rate-text">Rate Us</span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="#" onclick="openLogoutModal(event); return false;" class="btn-logout"><i class="fas fa-sign-out-alt"></i> <span class="logout-text">Logout</span></a>
            <?php else: ?>
                <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════ ALERTS ══════════════ -->
<?php if(isset($success)): ?>
<div class="alert-overlay show">
    <div class="alert-box success">
        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Success!</h3>
        <p><?php echo $success; ?></p>
        <button class="btn" onclick="this.parentElement.parentElement.style.display='none'">OK</button>
    </div>
</div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert-overlay show">
    <div class="alert-box error">
        <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
        <h3>Error!</h3>
        <p><?php echo htmlspecialchars($error); ?></p>
        <button class="btn" onclick="this.parentElement.parentElement.style.display='none'">OK</button>
    </div>
</div>
<?php endif; ?>

<?php if($show_registration_success): ?>
<div class="alert-overlay show">
    <div class="alert-box success">
        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Welcome!</h3>
        <p>Registration successful! You are now logged in.</p>
        <button class="btn" onclick="this.parentElement.parentElement.style.display='none'">OK</button>
    </div>
</div>
<?php endif; ?>

<?php if(isset($feedback_success) || isset($_GET['feedback_success'])): ?>
<div class="alert-overlay show">
    <div class="alert-box success">
        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Thank You!</h3>
        <p><?php echo $feedback_success ?? 'Your feedback has been recorded.'; ?></p>
        <button class="btn" onclick="this.parentElement.parentElement.style.display='none'">OK</button>
    </div>
</div>
<?php endif; ?>

<?php if(isset($feedback_error)): ?>
<div class="alert-overlay show">
    <div class="alert-box error">
        <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
        <h3>Error!</h3>
        <p><?php echo $feedback_error; ?></p>
        <button class="btn" onclick="this.parentElement.parentElement.style.display='none'">OK</button>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════ HERO ══════════════ -->
<div class="hero">
    <div class="hero-content">
        <h1><i class="fas fa-umbrella-beach"></i> <?php echo htmlspecialchars($content['hero']['title'] ?? 'Welcome to Transient House & Tours'); ?></h1>
        <p><?php echo htmlspecialchars($content['hero']['subtitle'] ?? 'Your home away from home and gateway to unforgettable island adventures.'); ?></p>
    </div>
</div>

<!-- ══════════════ MAIN ══════════════ -->
<div class="main-container">

    <div class="features-section">
        <div class="section-title">
            <h2><i class="fas fa-star"></i> <?php echo htmlspecialchars($content['features']['section_title'] ?? 'Why Choose Us'); ?></h2>
            <div class="underline"></div>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-home"></i></div>
                <h3><?php echo htmlspecialchars($content['features']['feature1_title'] ?? 'Comfortable Houses'); ?></h3>
                <p><?php echo htmlspecialchars($content['features']['feature1_desc'] ?? 'Experience true comfort in our well-appointed transient houses.'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-umbrella-beach"></i></div>
                <h3><?php echo htmlspecialchars($content['features']['feature2_title'] ?? 'Island Tours'); ?></h3>
                <p><?php echo htmlspecialchars($content['features']['feature2_desc'] ?? 'Explore the beautiful islands with our exciting tour packages.'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-water"></i></div>
                <h3><?php echo htmlspecialchars($content['features']['feature3_title'] ?? 'Exciting Activities'); ?></h3>
                <p><?php echo htmlspecialchars($content['features']['feature3_desc'] ?? 'Enjoy banana boat rides, jet skiing, snorkeling, and many more water activities!'); ?></p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-headset"></i></div>
                <h3><?php echo htmlspecialchars($content['features']['feature4_title'] ?? '24/7 Support'); ?></h3>
                <p><?php echo htmlspecialchars($content['features']['feature4_desc'] ?? 'We\'re always here to help you with any questions or concerns.'); ?></p>
            </div>
        </div>
    </div>

    <?php if(!empty($featured_activities)): ?>
    <div class="activities-section">
        <div class="section-title">
            <h2><i class="fas fa-water"></i> Featured Activities</h2>
            <div class="underline"></div>
        </div>

        <div class="activities-grid">
            <?php foreach($featured_activities as $activity): ?>
            <div class="activity-card">
                <div class="activity-icon">
                    <i class="<?php echo $activity['icon'] ?? 'fas fa-star'; ?>"></i>
                </div>
                <h4><?php echo htmlspecialchars($activity['name']); ?></h4>
                <div class="activity-category"><?php echo htmlspecialchars($activity['category']); ?></div>
                <div class="activity-price">
                    ₱<?php echo number_format($activity['price']); ?>
                    <small><?php echo htmlspecialchars($activity['price_unit'] ?? ''); ?></small>
                </div>
                <?php if($activity['description']): ?>
                    <p><?php echo htmlspecialchars(substr($activity['description'], 0, 80)) . '...'; ?></p>
                <?php endif; ?>
                <a href="activities.php" class="btn-activity">
                    <i class="fas fa-calendar-check"></i> Book Now
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($featured_food)): ?>
    <div class="food-section">
        <div class="section-title">
            <h2><i class="fas fa-utensils"></i> Featured Food</h2>
            <div class="underline"></div>
        </div>

        <div class="food-grid">
            <?php foreach($featured_food as $food): ?>
            <div class="food-card">
                <div class="food-icon"><i class="fas fa-utensils"></i></div>
                <h4><?php echo htmlspecialchars($food['name']); ?></h4>
                <div class="food-category"><?php echo ucfirst($food['category']); ?></div>
                <div class="food-price">
                    ₱<?php echo number_format($food['price']); ?>
                </div>
                <?php if($food['description']): ?>
                    <p><?php echo htmlspecialchars(substr($food['description'], 0, 80)) . '...'; ?></p>
                <?php endif; ?>
                <a href="food.php" class="btn-food">
                    <i class="fas fa-utensils"></i> View Menu
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="cta-section">
        <h2><i class="fas fa-rocket"></i> <?php echo htmlspecialchars($content['cta']['title'] ?? 'Ready to Book Your Stay?'); ?></h2>
        <p><?php echo htmlspecialchars($content['cta']['subtitle'] ?? 'Choose from our comfortable houses or exciting tour packages for your next adventure.'); ?></p>
        <div class="cta-buttons">
            <a href="houses.php" class="cta-btn btn-houses">
                <i class="fas fa-home"></i> <?php echo htmlspecialchars($content['cta']['button_houses_text'] ?? 'Browse Houses'); ?>
            </a>
            <a href="tours.php" class="cta-btn btn-tours">
                <i class="fas fa-umbrella-beach"></i> <?php echo htmlspecialchars($content['cta']['button_tours_text'] ?? 'Browse Tours'); ?>
            </a>
            <a href="activities.php" class="cta-btn btn-activities-cta">
                <i class="fas fa-water"></i> View Activities
            </a>
            <a href="food.php" class="cta-btn btn-food-cta">
                <i class="fas fa-utensils"></i> View Food Menu
            </a>
        </div>
    </div>

</div>

<!-- ══════════════ REVIEWS ══════════════ -->
<div class="review-section-wrapper">
    <div class="section-title">
        <h2><i class="fas fa-star" style="color: #f59e0b;"></i> Customer Reviews</h2>
        <div class="underline"></div>
    </div>

    <div class="review-container">
        <div class="rating-summary">
            <div class="big-rating">
                <span class="number"><?php echo $avg_rating; ?></span>
                <div>
                    <div class="stars">
                        <?php
                        for($i = 1; $i <= 5; $i++) {
                            if($i <= floor($avg_rating)) {
                                echo '<i class="fas fa-star" style="color: #f59e0b;"></i>';
                            } elseif($i == floor($avg_rating) + 1 && ($avg_rating - floor($avg_rating)) >= 0.5) {
                                echo '<i class="fas fa-star-half-alt" style="color: #f59e0b;"></i>';
                            } else {
                                echo '<i class="far fa-star" style="color: #d1d5db;"></i>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
            <span class="total-reviews"><?php echo $total_reviews; ?> reviews</span>
            <button onclick="location.href='reviews.php'" class="btn-view-all-reviews">
                <i class="fas fa-arrow-right"></i> View All
            </button>
        </div>
    </div>
</div>

<!-- ══════════════ FOOTER ══════════════ -->
<div class="footer">
    <div class="footer-content">
        <div class="footer-grid">
            <div class="footer-col">
                <h4><i class="fas fa-home"></i> Transient House & Tours</h4>
                <p><?php echo htmlspecialchars($content['footer']['company_description'] ?? 'Your trusted partner for comfortable accommodations and exciting island adventures.'); ?></p>
                <div class="social-links">
                    <a href="<?php echo htmlspecialchars($facebook_link); ?>" target="_blank" title="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                </div>
            </div>

            <div class="footer-col">
                <h4><i class="fas fa-link"></i> Quick Links</h4>
                <ul>
                    <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="houses.php"><i class="fas fa-chevron-right"></i> Houses</a></li>
                    <li><a href="tours.php"><i class="fas fa-chevron-right"></i> Tours</a></li>
                    <li><a href="activities.php"><i class="fas fa-chevron-right"></i> Activities</a></li>
                    <li><a href="food.php"><i class="fas fa-chevron-right"></i> Food</a></li>
                    <li><a href="packages.php"><i class="fas fa-chevron-right"></i> My Package</a></li>
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <li><a href="profile.php"><i class="fas fa-chevron-right"></i> My Profile</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="footer-col">
                <h4><i class="fas fa-info-circle"></i> Contact Info</h4>

                <div class="footer-map">
                    <iframe
                        src="<?php echo htmlspecialchars($map_embed); ?>"
                        width="100%"
                        height="100%"
                        style="border:0;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>

                <ul>
                    <li>
                        <i class="fas fa-map-marker-alt"></i>
                        <?php if ($maps_url != '#'): ?>
                            <a href="<?php echo $maps_url; ?>" target="_blank" rel="noopener noreferrer" style="color: #b3d9ff; text-decoration: none; transition: color 0.2s;">
                                <?php echo htmlspecialchars($location_address); ?>
                                <i class="fas fa-external-link-alt" style="font-size: 10px; margin-left: 4px; opacity: 0.6;"></i>
                            </a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($location_address); ?>
                        <?php endif; ?>
                    </li>
                    <li>
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $content['footer']['phone'] ?? '+639123456789'); ?>" style="color: #b3d9ff; text-decoration: none;">
                            <?php echo htmlspecialchars($content['footer']['phone'] ?? '+63 912 345 6789'); ?>
                        </a>
                    </li>
                    <li>
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?php echo htmlspecialchars($content['footer']['email'] ?? 'info@transientrental.com'); ?>" style="color: #b3d9ff; text-decoration: none;">
                            <?php echo htmlspecialchars($content['footer']['email'] ?? 'info@transientrental.com'); ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <div>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Transient House & Tours. All rights reserved.'); ?></div>
            <div class="footer-bottom-links">
                <a onclick="reopenTermsModal('privacy'); return false;"><?php echo htmlspecialchars($content['footer']['privacy_policy'] ?? 'Privacy Policy'); ?></a>
                <a onclick="reopenTermsModal('terms'); return false;"><?php echo htmlspecialchars($content['footer']['terms_of_service'] ?? 'Terms of Service'); ?></a>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════ FEEDBACK MODAL ══════════════ -->
<div class="modal" id="feedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star" style="color: #f59e0b;"></i> <?php echo $user_has_feedback ? 'Edit Your Review' : 'Rate Your Experience'; ?></h3>
            <span class="close" onclick="closeFeedbackModal()">&times;</span>
        </div>
        <div id="feedbackContent">
            <?php if(isset($_SESSION['user_id'])): ?>
                <?php if($user_has_feedback): ?>
                    <div class="feedback-message" style="text-align: center; padding: 15px; border-radius: 12px; margin-bottom: 15px;">
                        <p style="color: #4a6a8c; margin-bottom: 5px;">You previously rated:</p>
                        <div style="font-size: 28px; margin: 5px 0;">
                            <?php for($i=1; $i<=5; $i++): ?>
                                <i class="fas fa-star" style="color: <?php echo $i <= $user_feedback['rating'] ? '#f59e0b' : '#d4dce4'; ?>;"></i>
                            <?php endfor; ?>
                        </div>
                        <p style="color: #4a6a8c; font-size: 13px; margin-top: 5px;">Update your rating and review below</p>
                    </div>
                <?php else: ?>
                    <p style="color: #4a6a8c; margin-bottom: 15px;">
                        How would you rate your overall experience with us?
                    </p>
                <?php endif; ?>

                <form method="POST" id="feedbackForm">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; text-align: center; font-weight: 600; color: #0B2447; margin-bottom: 10px;">Your Overall Rating</label>
                        <div class="rating-input" id="ratingInput">
                            <i class="fas fa-star" data-rating="1" onclick="setRating(1)"></i>
                            <i class="fas fa-star" data-rating="2" onclick="setRating(2)"></i>
                            <i class="fas fa-star" data-rating="3" onclick="setRating(3)"></i>
                            <i class="fas fa-star" data-rating="4" onclick="setRating(4)"></i>
                            <i class="fas fa-star" data-rating="5" onclick="setRating(5)"></i>
                        </div>
                        <input type="hidden" name="rating" id="feedback_rating" value="<?php echo $user_has_feedback ? $user_feedback['rating'] : 0; ?>" required>
                        <p id="ratingText" style="text-align: center; color: #4a6a8c; margin-top: 5px;">
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
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0B2447;">Your Comment</label>
                        <textarea name="comment" class="form-control" rows="3" placeholder="Share your overall experience..." style="resize: vertical; width: 100%; padding: 12px; border: 1px solid #d4e4f0; border-radius: 8px; background: #f8faff; color: #1a3a5c;"><?php echo $user_has_feedback ? htmlspecialchars($user_feedback['comment']) : ''; ?></textarea>
                    </div>

                    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; padding: 10px 15px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <input type="checkbox" name="is_anonymous" id="feedback_is_anonymous" value="1" <?php echo ($user_has_feedback && isset($user_feedback['is_anonymous']) && $user_feedback['is_anonymous']) ? 'checked' : ''; ?>>
                        <label for="feedback_is_anonymous" style="margin-bottom: 0; cursor: pointer; font-weight: 500; color: #1e293b;">
                            <i class="fas fa-user-secret" style="color: #7c3aed;"></i> Post as Anonymous
                        </label>
                        <span style="font-size: 12px; color: #94a3b8; margin-left: auto;">
                            Your name will not be shown publicly
                        </span>
                    </div>

                    <button type="submit" name="submit_feedback" class="btn" style="width: 100%; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);">
                        <i class="fas fa-paper-plane"></i> <?php echo $user_has_feedback ? 'Update Review' : 'Submit Review'; ?>
                    </button>
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

<!-- ══════════════ REVIEWS MODAL ══════════════ -->
<div class="modal" id="reviewsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star" style="color: #f59e0b;"></i> All Reviews</h3>
            <span class="close" onclick="closeReviewsModal()">&times;</span>
        </div>
        <div id="reviewsContent">
            <?php 
            $all_reviews = $pdo->query("SELECT f.*, u.username 
                                        FROM overall_feedback f 
                                        JOIN users u ON f.user_id = u.id 
                                        ORDER BY f.created_at DESC")->fetchAll();
            if(count($all_reviews) > 0): ?>
                <?php foreach($all_reviews as $review): ?>
                    <div class="review-item">
                        <div class="review-header">
                            <div class="review-user">
                                <div class="user-avatar">
                                    <?php 
                                    if(isset($review['is_anonymous']) && $review['is_anonymous'] == 1) {
                                        echo '<i class="fas fa-user-secret" style="font-size: 12px;"></i>';
                                    } else {
                                        echo strtoupper(substr($review['username'], 0, 1));
                                    }
                                    ?>
                                </div>
                                <?php 
                                if(isset($review['is_anonymous']) && $review['is_anonymous'] == 1) {
                                    echo '***';
                                } else {
                                    echo htmlspecialchars($review['username']);
                                }
                                ?>
                                <?php if(isset($_SESSION['user_id']) && $review['user_id'] == $_SESSION['user_id']): ?>
                                    <span style="font-size: 10px; background: #4DA6D9; color: white; padding: 1px 10px; border-radius: 20px; margin-left: 5px;">Your Review</span>
                                <?php endif; ?>
                                <?php if(isset($review['is_anonymous']) && $review['is_anonymous'] == 1): ?>
                                    <span style="font-size: 9px; background: #f3e8ff; color: #7c3aed; padding: 1px 8px; border-radius: 12px; margin-left: 4px;">
                                        <i class="fas fa-user-secret"></i> Anonymous
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="review-date">
                                <i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                            </span>
                        </div>
                        <div class="review-stars">
                            <?php echo renderTinyStars($review['rating']); ?>
                        </div>
                        <?php if($review['comment']): ?>
                            <div class="review-text">"<?php echo htmlspecialchars($review['comment']); ?>"</div>
                        <?php else: ?>
                            <div class="review-text no-comment">No comment provided</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-reviews-modal">
                    <i class="fas fa-comment-slash"></i>
                    <p>No reviews yet. Be the first to share your experience!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     ✨ TERMS & PRIVACY MODAL — SINGLE SCROLL
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

        <!-- ✅ SINGLE SCROLL CONTAINER (Terms + Privacy combined) -->
        <div class="terms-scroll-container" id="termsScrollContainer">

            <!-- SECTION 1: TERMS & CONDITIONS -->
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

            <!-- DIVIDER -->
            <div class="terms-divider">
                <span>END OF TERMS &amp; CONDITIONS</span>
            </div>

            <!-- SECTION 2: PRIVACY POLICY -->
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

        </div><!-- /terms-scroll-container -->

        <!-- Scroll hint (while user hasn't reached bottom) -->
        <div class="terms-scroll-hint" id="termsScrollHint">
            <i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox
        </div>

        <!-- Accept form (hidden until scroll bottom) -->
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

<!-- ============================================================
     ✅ LOGOUT CONFIRMATION MODAL
     ============================================================ -->
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

<!-- ══════════════ SCRIPTS ══════════════ -->
<script>
// SIDEBAR
function toggleSidebar() {
    if (window.innerWidth > 1100) return;

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');

    const willOpen = !sidebar.classList.contains('open');

    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    toggleBtn.classList.toggle('active');

    // ✅ Hide hamburger when sidebar opens (mobile only)
    if (willOpen && window.innerWidth <= 1100) {
        document.body.classList.add('sidebar-open-mobile');
    } else {
        document.body.classList.remove('sidebar-open-mobile');
    }

    if (sidebar.classList.contains('open')) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = 'auto';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('open')) toggleSidebar();

        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) closeLogoutModal();
    }
});

document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('menuToggle');
    if (window.innerWidth <= 1100) return;
    if (sidebar.classList.contains('open')) {
        const isClickInside = sidebar.contains(e.target) || toggleBtn.contains(e.target);
        if (!isClickInside) toggleSidebar();
    }
});

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 1100 && sidebar.classList.contains('open')) toggleSidebar();
});

// FEEDBACK & REVIEWS MODALS
function openFeedbackModal() {
    document.getElementById('feedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeFeedbackModal() {
    document.getElementById('feedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function openReviewsModal() {
    document.getElementById('reviewsModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeReviewsModal() {
    document.getElementById('reviewsModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// RATING
var selectedRating = <?php echo $user_has_feedback ? $user_feedback['rating'] : 0; ?>;
var ratingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

document.addEventListener('DOMContentLoaded', function() {
    <?php if($user_has_feedback && $user_feedback['rating'] > 0): ?>
        var rating = <?php echo $user_feedback['rating']; ?>;
        document.querySelectorAll('#ratingInput i').forEach(function(star, index) {
            if (index < rating) {
                star.classList.add('active');
            } else {
                star.classList.remove('active');
            }
        });
        document.getElementById('ratingText').textContent = ratingTexts[rating] || 'Select a rating';
    <?php endif; ?>
});

function setRating(rating) {
    selectedRating = rating;
    document.getElementById('feedback_rating').value = rating;
    document.getElementById('ratingText').textContent = ratingTexts[rating] || 'Select a rating';

    document.querySelectorAll('#ratingInput i').forEach(function(star, index) {
        if (index < rating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

// FONT AWESOME FALLBACK
(function() {
    var ICON_MAP = {
        'fa-home':        '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7h-6v7H4a1 1 0 0 1-1-1V9.5z"/></svg>',
        'fa-umbrella-beach':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M12 2C7 2 3 6 3 11h18c0-5-4-9-9-9z"/><path d="M12 11v10"/><path d="M8 21l4-10 4 10"/></svg>',
        'fa-water':       '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M2 12c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/><path d="M2 18c2-2 4-2 6 0s4 2 6 0 4-2 6 0"/></svg>',
        'fa-utensils':    '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M4 3v7a3 3 0 0 0 6 0V3"/><path d="M7 10v11"/><path d="M17 3c-2 0-3 2-3 4s1 4 3 4v10"/></svg>',
        'fa-star':        '<svg class="inline-svg-icon filled" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.56 5.82 22 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
        'fa-star-half-alt':'<svg class="inline-svg-icon filled" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2v15.5l-6.18 3.5L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
        'fa-user':        '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'fa-user-plus':   '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>',
        'fa-user-secret': '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M12 2a5 5 0 0 0-5 5v2H5a2 2 0 0 0-2 2v1h18v-1a2 2 0 0 0-2-2h-2V7a5 5 0 0 0-5-5z"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/></svg>',
        'fa-sign-in-alt': '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
        'fa-sign-out-alt':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'fa-lock':        '<svg class="inline-svg-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'fa-key':         '<svg class="inline-svg-icon" viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3L22 7l-3-3"/></svg>',
        'fa-bars':        '<svg class="inline-svg-icon" viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
        'fa-times':       '<svg class="inline-svg-icon" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'fa-cog':         '<svg class="inline-svg-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'fa-link':        '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'fa-info-circle': '<svg class="inline-svg-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        'fa-map-marker-alt':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        'fa-phone':       '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        'fa-envelope':    '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'fa-chevron-right':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>',
        'fa-external-link-alt':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        'fa-check-circle':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'fa-exclamation-circle':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'fa-exclamation-triangle':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'fa-calendar-check':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/></svg>',
        'fa-rocket':      '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
        'fa-headset':     '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>',
        'fa-arrow-right': '<svg class="inline-svg-icon" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
        'fa-paper-plane': '<svg class="inline-svg-icon" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        'fa-comment-slash':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="3" y1="3" x2="21" y2="21"/></svg>',
        'fa-clock':       '<svg class="inline-svg-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'fa-eye':         '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        'fa-eye-slash':   '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>',
        'fa-facebook-f':  '<svg class="inline-svg-icon filled" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
        'fa-file-contract':'<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>',
        'fa-scroll':      '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M8 21h12a2 2 0 0 0 2-2v-2H10v2a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v3h4"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/></svg>',
        'fa-shield-alt':  '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'fa-arrow-down':  '<svg class="inline-svg-icon" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>',
        'fa-check':       '<svg class="inline-svg-icon" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
        'fa-box-open':    '<svg class="inline-svg-icon" viewBox="0 0 24 24"><path d="M2 3h20v5H2z"/><path d="M4 8v13h16V8"/><path d="M10 12h4"/></svg>'
    };

    function faLoaded() {
        try {
            var test = document.createElement('i');
            test.className = 'fas fa-home';
            test.style.position = 'absolute';
            test.style.left = '-9999px';
            test.style.fontSize = '24px';
            document.body.appendChild(test);
            var before = window.getComputedStyle(test, '::before').content;
            document.body.removeChild(test);
            return before && before !== 'none' && before !== '""' && before !== 'normal';
        } catch(e) {
            return false;
        }
    }

    function replaceIcons() {
        if (faLoaded()) return;

        var icons = document.querySelectorAll('i.fas, i.far, i.fab, i.fa, i.fa-solid, i.fa-regular, i.fa-brands');
        icons.forEach(function(el) {
            if (el.dataset.svgReplaced === '1') return;

            var classes = (el.className || '').split(/\s+/);
            var svg = null;
            for (var i = 0; i < classes.length; i++) {
                if (ICON_MAP[classes[i]]) {
                    svg = ICON_MAP[classes[i]];
                    break;
                }
            }
            if (!svg) {
                svg = '<svg class="inline-svg-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/></svg>';
            }

            var span = document.createElement('span');
            span.innerHTML = svg;
            span.style.display = 'inline-flex';
            span.style.alignItems = 'center';
            span.style.justifyContent = 'center';
            if (el.style.color) span.style.color = el.style.color;
            if (el.style.fontSize) {
                span.style.fontSize = el.style.fontSize;
                var s = span.querySelector('svg');
                if (s) { s.style.width = '1em'; s.style.height = '1em'; }
            }

            el.dataset.svgReplaced = '1';
            el.parentNode.replaceChild(span, el);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(replaceIcons, 400);
        });
    } else {
        setTimeout(replaceIcons, 400);
    }
})();

// OUTSIDE CLICK
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
    document.querySelectorAll('.alert-overlay.show').forEach(function(alert) {
        if (alert.querySelector('.alert-box.lockout')) return;
        alert.style.display = 'none';
    });
}, 5000);

// ============================================================
// ✅ LOGOUT CONFIRMATION MODAL
// ============================================================
function openLogoutModal(event) {
    if (event) event.preventDefault();

    // Close mobile sidebar first if open
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

/* ══════════════════════════════════════════════════════════════
   ✨ NEW TERMS MODAL LOGIC — SINGLE SCROLL
   ══════════════════════════════════════════════════════════════ */
var __termsState = {
    modal: null,
    mustAccept: false,
    hasReachedBottom: false
};

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

        // Show accept form
        acceptForm.classList.add('visible');

        // Enable checkbox
        checkbox.disabled = false;

        // Update scroll hint
        scrollHint.classList.add('done');
        scrollHint.innerHTML = '<i class="fas fa-check-circle"></i> You\'ve read everything. Please check the box below.';

        // Small delay then smooth scroll to bottom
        setTimeout(function() {
            scrollBox.scrollTo({ top: scrollBox.scrollHeight, behavior: 'smooth' });
        }, 100);
    }

    function wireScrollListener() {
        if (!scrollBox) return;
        if (scrollBox.dataset.scrollWired === '1') return;
        scrollBox.dataset.scrollWired = '1';

        // Check if content already fits without scroll
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

        // Reset scroll to top
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

    // Checkbox enables the accept button
    checkbox.addEventListener('change', function () {
        acceptBtn.disabled = !checkbox.checked;
    });

    // Auto-open if must-accept
    if (__termsState.mustAccept) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        wireScrollListener();
    } else {
        wireScrollListener();
    }
})();

// FOOTER TERMS LINK
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
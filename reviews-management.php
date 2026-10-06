<?php
session_start();
require_once 'database.php';
require_once 'includes/sidebar-counts.php';


// ✅ NEW: Load SystemLogger (for logout logging)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Check if user is logged in and is admin or staff
if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: index.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

// Get user info for header
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {
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

// ============================================================
// ✅ SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================



// Handle Delete Review
if(isset($_GET['delete_review']) && ($is_admin || $is_staff)) {
    try {
        $review_id = $_GET['delete_review'];

        // ✅ NEW: Fetch review info for logging before delete
        $log_stmt = $pdo->prepare("SELECT f.*, u.username FROM overall_feedback f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
        $log_stmt->execute([$review_id]);
        $review_data = $log_stmt->fetch();

        $stmt = $pdo->prepare("DELETE FROM overall_feedback WHERE id = ?");
        $stmt->execute([$review_id]);

        // ✅ NEW: Log the delete
        if (class_exists('SystemLogger') && $review_data) {
            SystemLogger::log($pdo, 'delete', 'review',
                "Deleted review from '{$review_data['username']}' (Rating: {$review_data['rating']}/5)",
                $review_id, 'review',
                ['rating' => $review_data['rating'], 'comment' => $review_data['comment']],
                null, 'warning');
        }

        $success = "Review deleted successfully!";
        header("Location: reviews-management.php?deleted=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed to delete review: " . $e->getMessage();
    }
}

// Get filter and search parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$rating_filter = isset($_GET['rating']) ? $_GET['rating'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Build query
$query = "SELECT f.*, u.username, u.email 
          FROM overall_feedback f 
          JOIN users u ON f.user_id = u.id 
          WHERE 1=1";

if($search) {
    $query .= " AND (u.username LIKE :search OR f.comment LIKE :search)";
}
if($rating_filter != 'all') {
    $query .= " AND f.rating = :rating";
}

if($sort == 'newest') {
    $query .= " ORDER BY f.created_at DESC";
} elseif($sort == 'oldest') {
    $query .= " ORDER BY f.created_at ASC";
} elseif($sort == 'highest') {
    $query .= " ORDER BY f.rating DESC";
} elseif($sort == 'lowest') {
    $query .= " ORDER BY f.rating ASC";
} else {
    $query .= " ORDER BY f.created_at DESC";
}

$stmt = $pdo->prepare($query);
if($search) {
    $stmt->bindValue(':search', "%$search%");
}
if($rating_filter != 'all') {
    $stmt->bindValue(':rating', $rating_filter);
}
$stmt->execute();
$reviews = $stmt->fetchAll();

// Get stats
$total_reviews = $pdo->query("SELECT COUNT(*) FROM overall_feedback")->fetchColumn();
$avg_rating = $pdo->query("SELECT AVG(rating) FROM overall_feedback")->fetchColumn();
$avg_rating = $avg_rating ? round($avg_rating, 1) : 0;

// ============================================================
// ✅ Reviews — PENDING only (for badge)
// ============================================================

// Get rating distribution
$rating_distribution = [];
for($i = 5; $i >= 1; $i--) {
    $count = $pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE rating = $i")->fetchColumn();
    $percentage = $total_reviews > 0 ? round(($count / $total_reviews) * 100) : 0;
    $rating_distribution[$i] = ['count' => $count, 'percentage' => $percentage];
}

// Get recent reviews count (last 7 days)
$recent_count = $pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// Handle Logout
if(isset($_GET['logout'])) {
    // ✅ NEW: Log the logout
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out",
            (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

// Get dynamic content for footer
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {
    $content = [];
}

// ============================================================
// RESOLVE LOGO PATH (dynamic + fallback)
// ============================================================
$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

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
            $html .= '<i class="far fa-star" style="color: #d1d5db; font-size: 14px;"></i>';
        }
    }
    return $html;
}

function renderSmallStars($rating) {
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

function getRatingText($rating) {
    $texts = [1 => 'Very Poor', 2 => 'Poor', 3 => 'Average', 4 => 'Good', 5 => 'Excellent!'];
    return $texts[$rating] ?? 'Unknown';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reviews Management - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           RESET & BASE
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .app-container { display: flex; min-height: 100vh; }

        /* ============================================================
           SIDEBAR
           ============================================================ */
        .sidebar {
            width: 280px;
            background: #0B2447;
            box-shadow: 4px 0 20px rgba(0,0,0,0.2);
            padding: 25px 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            border-right: 2px solid rgba(77, 166, 217, 0.15);
            transition: transform 0.3s ease, width 0.3s ease;
            z-index: 100;
            flex-shrink: 0;
        }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }

        .sidebar-header {
            padding: 0 20px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 20px;
        }

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
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: white; flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
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
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 20px; color: #b3d9ff;
            text-decoration: none; transition: all 0.3s;
            border-left: 3px solid transparent;
            font-weight: 500; font-size: 14px;
            position: relative;
        }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
        .nav-link .nav-badge {
            margin-left: auto; background: rgba(239, 68, 68, 0.2); color: #ef4444;
            padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600;
        }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        /* ============================================================
           SIDEBAR OVERLAY + HAMBURGER
           ============================================================ */
        .sidebar-overlay {
            display: none; position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99; opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; opacity: 1; }

        .menu-toggle {
            display: none;
            position: fixed;
            top: 12px; left: 12px;
            z-index: 1001;
            background: #0B2447;
            color: white;
            border: none; border-radius: 12px;
            width: 48px; height: 48px; font-size: 22px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            align-items: center; justify-content: center;
            border: 1px solid rgba(77, 166, 217, 0.2);
        }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
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
                top: 0; left: 0;
                height: 100vh;
                transform: translateX(-100%);
                width: 280px;
                z-index: 1000;
                box-shadow: none; border-radius: 0;
                padding-top: 25px;
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
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .main-content { padding: 60px 12px 16px !important; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
            .nav-link i { font-size: 14px; }
        }

        /* ============================================================
           MAIN CONTENT
           ============================================================ */
        .main-content {
            flex: 1;
            padding: 20px 30px 30px;
            min-width: 0; width: 100%;
            transition: padding 0.3s ease;
        }

        /* ============================================================
           ✅ TOP BAR — with mobile-only elements
           ============================================================ */
        .top-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px; padding-bottom: 15px;
            border-bottom: 2px solid rgba(11, 36, 71, 0.1);
            flex-wrap: wrap; gap: 10px;
        }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }

        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }

        /* ✅ Desktop avatar — 64×64, photo-friendly */
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

        /* ✅ Mobile-only elements (hidden on desktop) */
        .mobile-role-badge,
        .mobile-avatar {
            display: none;
        }

        /* ✅ Mobile avatar — BIG SIZE, double-ring, absolute right */
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

        /* ============================================================
           ✅ MOBILE: Top bar — avatar sa KANANG GILID (absolute)
           Title + badge nasa gitna (SYMMETRIC PADDING para perfect center)
           ============================================================ */
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

            /* ✅ Mobile avatar — ABSOLUTE sa KANANG GILID */
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

        /* ============================================================
           PAGE TITLE BANNER
           ============================================================ */
        .page-title-banner {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            border-radius: 20px; padding: 30px 35px;
            margin-bottom: 30px; color: white;
            box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15);
            position: relative; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .page-title-banner::before {
            content: ''; position: absolute;
            top: -50%; right: -50%;
            width: 200%; height: 200%;
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
            display: inline-block; background: rgba(251, 191, 36, 0.2); color: #fbbf24;
            padding: 2px 12px; border-radius: 20px; font-size: 12px; font-weight: 500;
            border: 1px solid rgba(251, 191, 36, 0.2); margin-top: 5px;
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

        /* ============================================================
           STATS GRID
           ============================================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .stat-card {
            background: #4DA6D9; border-radius: 16px; padding: 22px 20px;
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid rgba(255,255,255,0.15);
            box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2);
            text-decoration: none; color: white; display: block;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(77, 166, 217, 0.3); }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .stat-icon {
            width: 48px; height: 48px;
            background: rgba(255,255,255,0.2); border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 20px; flex-shrink: 0;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .stat-number { font-size: 28px; font-weight: 700; color: white; margin-top: 10px; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 13px; font-weight: 500; margin-top: 2px; }
        .stat-small { font-size: 11px; color: rgba(255,255,255,0.8); margin-top: 8px; display: flex; align-items: center; gap: 5px; }
        .stat-small.warning { color: #F4B400; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; border-radius: 12px; }
            .stat-number { font-size: 22px; }
            .stat-icon { width: 40px; height: 40px; font-size: 16px; }
            .stat-label { font-size: 11px; }
            .stat-small { font-size: 10px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 10px; border-radius: 10px; }
            .stat-number { font-size: 18px; margin-top: 6px; }
            .stat-icon { width: 32px; height: 32px; font-size: 14px; border-radius: 8px; }
            .stat-label { font-size: 10px; }
            .stat-small { font-size: 9px; margin-top: 4px; }
        }

        /* ============================================================
           CARDS
           ============================================================ */
        .card {
            background: white; border-radius: 20px; padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            margin-bottom: 30px; border: 1px solid #e8f0fe; overflow: hidden;
        }
        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px;
            border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px;
        }
        .card-header h2 {
            font-size: 17px; font-weight: 600; color: #0B2447;
            display: flex; align-items: center; gap: 10px; margin: 0;
        }
        .card-header h2 i {
            color: #4DA6D9; background: #eef2ff;
            padding: 8px; border-radius: 8px; font-size: 14px;
        }
        .card-header .header-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        @media (max-width: 768px) {
            .card { padding: 18px 15px; border-radius: 14px; }
            .card-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .card-header h2 { font-size: 15px; }
            .card-header h2 i { padding: 6px; font-size: 12px; }
        }
        @media (max-width: 480px) {
            .card { padding: 12px 10px; border-radius: 12px; }
            .card-header h2 { font-size: 14px; }
        }

        /* ============================================================
           FILTER BAR
           ============================================================ */
        .filter-bar { display: flex; gap: 15px; flex-wrap: wrap; align-items: center; }
        .filter-bar select,
        .filter-bar input {
            padding: 8px 15px; border: 2px solid #e8f0fe;
            border-radius: 10px; font-size: 13px;
            background: #fafafa; transition: border-color 0.3s;
        }
        .filter-bar select:focus,
        .filter-bar input:focus {
            outline: none; border-color: #4DA6D9;
            background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }
        .filter-bar .btn-filter {
            padding: 8px 20px; background: #4DA6D9; color: white;
            border: none; border-radius: 10px; cursor: pointer;
            font-weight: 500; transition: transform 0.2s;
        }
        .filter-bar .btn-filter:hover { transform: scale(1.02); box-shadow: 0 4px 12px rgba(77, 166, 217, 0.3); }
        .btn-clear {
            background: #64748b; color: white; padding: 8px 15px;
            text-decoration: none; border-radius: 10px;
            font-size: 13px; transition: background 0.3s; font-weight: 500;
        }
        .btn-clear:hover { background: #475569; color: white; }

        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select,
            .filter-bar input { width: 100%; }
        }

        /* ============================================================
           TABLES
           ============================================================ */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 300px; }
        th {
            text-align: left; padding: 12px 14px;
            background: #f8fafc; color: #0B2447;
            font-weight: 600; font-size: 11px;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        td {
            padding: 12px 14px; border-bottom: 1px solid #e8f0fe;
            color: #475569; font-size: 13px; vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f8fafc; }

        @media (max-width: 768px) {
            th { font-size: 10px; padding: 8px 8px; }
            td { font-size: 12px; padding: 8px 8px; }
        }
        @media (max-width: 480px) {
            th { font-size: 9px; padding: 6px 6px; }
            td { font-size: 11px; padding: 6px 6px; }
        }

        /* ============================================================
           ✅ MOBILE REVIEW CARDS
           ============================================================ */
        .reviews-cards-mobile {
            display: none;
            flex-direction: column;
            gap: 12px;
        }
        @media (min-width: 769px) {
            .reviews-cards-mobile { display: none !important; }
        }
        @media (max-width: 768px) {
            .table-responsive.desktop-table { display: none !important; }
            .reviews-cards-mobile { display: flex; }
        }

        .review-card-mobile {
            background: white;
            border-radius: 14px;
            padding: 14px;
            border: 1px solid #e8f0fe;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .review-card-mobile .card-top-row {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 10px;
        }
        .review-card-mobile .card-user {
            display: flex; align-items: center; gap: 10px;
            flex: 1; min-width: 0;
        }
        .review-card-mobile .user-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 15px;
            flex-shrink: 0;
        }
        .review-card-mobile .user-info {
            flex: 1; min-width: 0;
        }
        .review-card-mobile .user-name {
            font-weight: 700; color: #0B2447; font-size: 13px;
            word-break: break-word; line-height: 1.3;
        }
        .review-card-mobile .user-email {
            font-size: 10.5px; color: #94a3b8;
            word-break: break-all; line-height: 1.3;
        }
        .review-card-mobile .card-rating {
            flex-shrink: 0; text-align: right;
        }
        .review-card-mobile .card-rating .stars {
            display: inline-flex; gap: 2px; font-size: 13px;
            white-space: nowrap;
        }
        .review-card-mobile .card-rating .rating-label {
            font-size: 10px; color: #94a3b8;
            font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.3px; margin-top: 2px;
        }
        .review-card-mobile .card-review-text {
            background: #f8fafc;
            border-left: 3px solid #4DA6D9;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 12.5px;
            color: #475569;
            line-height: 1.5;
            word-wrap: break-word;
        }
        .review-card-mobile .card-review-text.no-comment {
            color: #94a3b8;
            font-style: italic;
            font-size: 12px;
        }
        .review-card-mobile .card-row {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; color: #475569;
        }
        .review-card-mobile .card-row i {
            width: 16px; color: #4DA6D9;
            flex-shrink: 0; text-align: center;
        }
        .review-card-mobile .card-row .card-label {
            color: #94a3b8; font-size: 10px; font-weight: 700;
            min-width: 46px; text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .review-card-mobile .card-row .card-value {
            font-weight: 600; color: #0B2447;
            word-break: break-word; flex: 1; min-width: 0;
        }
        .review-card-mobile .card-actions {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding-top: 8px; border-top: 1px solid #f1f5f9;
        }
        .review-card-mobile .btn-card-action {
            flex: 1; min-width: 100px; min-height: 42px;
            padding: 10px 14px; border: none; border-radius: 10px;
            font-weight: 700; font-size: 12.5px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 6px; text-decoration: none; transition: all 0.2s;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }
        .review-card-mobile .btn-card-action:active { transform: scale(0.97); }
        .review-card-mobile .btn-delete-mobile {
            background: #ef4444; color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }

        /* ============================================================
           BADGES
           ============================================================ */
        .badge {
            padding: 4px 12px; border-radius: 20px;
            font-size: 10px; font-weight: 600;
            display: inline-block; text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-danger { background: #fee2e2; color: #ef4444; }
        .badge-info { background: #dbeafe; color: #3b82f6; }

        .review-stars { white-space: nowrap; }
        .review-stars i { font-size: 14px; }
        .review-text { max-width: 300px; word-wrap: break-word; line-height: 1.5; }
        .review-text .no-comment { color: #94a3b8; font-style: italic; }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn-sm {
            padding: 5px 12px; font-size: 11px;
            border: none; border-radius: 6px;
            cursor: pointer; transition: all 0.2s;
            display: inline-block; text-decoration: none;
            margin: 2px; font-weight: 600;
        }
        .btn-sm:hover { transform: translateY(-2px); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
        .btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
        .btn-disabled:hover { transform: none; box-shadow: none; }

        @media (max-width: 480px) {
            .btn-sm { font-size: 9px; padding: 3px 8px; }
        }

        /* ============================================================
           ALERTS
           ============================================================ */
        .alert {
            padding: 15px 20px; border-radius: 12px;
            margin-bottom: 20px; display: flex;
            align-items: center; gap: 10px;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }
        .alert i { font-size: 18px; }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 15px; color: #cbd5e1; }
        .empty-state p { font-size: 15px; }

        /* ============================================================
           RATING DISTRIBUTION
           ============================================================ */
        .rating-distribution { display: flex; flex-direction: column; gap: 6px; }
        .rating-distribution .bar-item { display: flex; align-items: center; gap: 10px; }
        .rating-distribution .bar-item .bar-label { font-size: 12px; font-weight: 600; color: #64748b; min-width: 30px; }
        .rating-distribution .bar-item .bar-track {
            flex: 1; height: 8px; background: #e2e8f0;
            border-radius: 10px; overflow: hidden; min-width: 100px;
        }
        .rating-distribution .bar-item .bar-track .bar-fill {
            height: 100%; background: linear-gradient(90deg, #f59e0b, #fbbf24);
            border-radius: 10px; transition: width 0.5s;
        }
        .rating-distribution .bar-item .bar-count { font-size: 12px; color: #94a3b8; min-width: 30px; text-align: right; }

        /* ============================================================
           FOOTER
           ============================================================ */
        .footer {
            background: #0B2447; color: #b3d9ff;
            padding: 15px 0; text-align: center;
            margin-top: 30px; border-radius: 12px;
            font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15);
        }
        .footer i { color: #4DA6D9; }

        @media (max-width: 768px) {
            .footer { font-size: 11px; padding: 12px 10px; border-radius: 10px; margin-top: 20px; }
        }
        @media (max-width: 480px) {
            .footer { font-size: 10px; padding: 10px 8px; border-radius: 8px; margin-top: 15px; }
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
            .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }
   
            /* REVIEWS STAT CARD REDESIGN */

.reviews-stats .stat-card {

    background: white !important;
    color: #0B2447;

    border-radius: 20px;
    border: 1px solid #e8f0fe;

    box-shadow: 0 10px 30px rgba(0,0,0,0.06);

    padding: 22px 20px;
}


.reviews-stats .stat-icon {

    width: 48px;
    height: 48px;

    border-radius: 14px;

    display:flex;
    align-items:center;
    justify-content:center;

    font-size:20px;

}


.review-rating-card .stat-icon {

    background: rgba(245,158,11,.15);
    color:#f59e0b;

}


.review-total-card .stat-icon {

    background: rgba(77,166,217,.15);
    color:#4DA6D9;

}


.review-recent-card .stat-icon {

    background: rgba(139,92,246,.15);
    color:#8b5cf6;

}


.reviews-stats .stat-number {

    color:#0B2447;

    font-size:30px;
    font-weight:800;

    margin-top:15px;

}


.reviews-stats .stat-label {

    color:#0B2447;

    font-weight:700;
    font-size:14px;

}


.reviews-stats .stat-small {

    color:#64748b;

    margin-top:8px;

}


.rating-stars i {

    font-size:14px;

}
   </style>
    <link rel="stylesheet" href="assets/css/admin-responsive.css">
</head>
<body>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Hamburger Menu -->
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
            <li class="nav-item">
                <a href="admin-dashboard.php" class="nav-link">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <?php if($is_admin): ?>
            <li class="nav-item">
                <a href="user-management.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>User Management</span>
                </a>
            </li>
            <?php endif; ?>

            <li class="nav-item">
                <a href="house-dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>House Management</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="tour-dashboard.php" class="nav-link">
                    <i class="fas fa-umbrella-beach"></i>
                    <span>Tour Management</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="activities-dashboard.php" class="nav-link">
                    <i class="fas fa-water"></i>
                    <span>Activities Management</span>
                </a>
            </li>
<li class="nav-item">
                <a href="food-dashboard.php" class="nav-link">
                    <i class="fas fa-utensils"></i>
                    <span>Food Management</span></a>
            </li>

            <!-- ✅ BOOKING — badge = pending bookings -->
            <li class="nav-item">
                <a href="booking-management.php" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span>Booking Management</span>
                    <?php if($sidebar_pending_bookings > 0): ?>
                        <span class="nav-badge" style="background: rgba(245,158,11,0.2); color:#f59e0b;"><?php echo $sidebar_pending_bookings; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item">
                <a href="blocked-dates.php" class="nav-link">
                    <i class="fas fa-ban"></i>
                    <span>Blocked Dates</span></a>
            </li>

            <!-- ✅ REVIEWS — badge = PENDING only -->
            <li class="nav-item">
                <a href="reviews-management.php" class="nav-link active">
                    <i class="fas fa-star"></i>
                    <span>Reviews Management</span>
                    <?php if($sidebar_pending_reviews > 0): ?>
                        <span class="nav-badge" style="background: rgba(16,185,129,0.2); color:#10b981;"><?php echo $sidebar_pending_reviews; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-item">
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-file-alt"></i>
                    <span>Sales Report</span>
                </a>
            </li>

            <?php if($is_admin): ?>
            <li class="nav-item">
                <a href="edit-content.php" class="nav-link">
                    <i class="fas fa-edit"></i>
                    <span>Edit Content</span>
                </a>
            </li>

            <!-- ✅ SYSTEM LOGS — badge = failed only -->
            <li class="nav-item">
                <a href="system-logs.php" class="nav-link">
                    <i class="fas fa-history"></i>
                    <span>System Logs</span>
                    <?php if($sidebar_failed_logs > 0): ?>
                        <span class="nav-badge"><?php echo $sidebar_failed_logs; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <div class="nav-divider"></div>
            <li class="nav-item">
                <a href="admin-profile.php" class="nav-link">
                    <i class="fas fa-user-circle"></i>
                    <span>My Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link" onclick="openLogoutModal(event); return false;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">

        <!-- TOP BAR — with mobile-only badge + avatar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-star" style="color: #f59e0b;"></i> Reviews

                    <!-- ✅ Mobile-only: badge + avatar beside title -->
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
                <p>Manage customer reviews and feedback</p>
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

        <?php if(isset($success) || isset($_GET['deleted'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success ?? 'Review deleted successfully!'; ?>
        </div>
        <?php endif; ?>

        <?php if(isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <!-- Page Title Banner -->
        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-star" style="color: #f59e0b;"></i> Reviews Management</h1>
                <div class="underline"></div>
                <p>
                    View and manage all customer reviews and feedback
                    <?php if($is_staff): ?>
                        <br><span class="staff-notice"><i class="fas fa-user-tie"></i> Staff Access - Full Management</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

    <!-- Stats Grid -->
<div class="stats-grid reviews-stats">

    <!-- Average Rating -->
    <div class="stat-card review-rating-card">

        <div class="stat-icon">
            <i class="fas fa-star"></i>
        </div>

        <div class="stat-number">
            <?php echo $avg_rating; ?>
        </div>

        <div class="stat-label">
            Average Rating
        </div>

        <div class="stat-small rating-stars">
            <?php echo renderSmallStars($avg_rating); ?>
        </div>

    </div>


    <!-- Total Reviews -->
    <div class="stat-card review-total-card">

        <div class="stat-icon">
            <i class="fas fa-comments"></i>
        </div>

        <div class="stat-number">
            <?php echo $total_reviews; ?>
        </div>

        <div class="stat-label">
            Total Reviews
        </div>

        <div class="stat-small">
            <i class="fas fa-user"></i>
            Customer feedback
        </div>

    </div>


    <!-- Recent Reviews -->
    <div class="stat-card review-recent-card">

        <div class="stat-icon">
            <i class="fas fa-calendar-week"></i>
        </div>

        <div class="stat-number">
            <?php echo $recent_count; ?>
        </div>

        <div class="stat-label">
            Recent Reviews
        </div>

        <div class="stat-small">
            <i class="fas fa-clock"></i>
            Last 7 days
        </div>

    </div>

</div>

        <!-- Rating Distribution -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-star" style="color: #f59e0b;"></i> Rating Distribution</h2>
                <span style="font-size: 13px; color: #64748b;"><?php echo $total_reviews; ?> total reviews</span>
            </div>
            <div class="rating-distribution">
                <?php foreach($rating_distribution as $star => $data): ?>
                <div class="bar-item">
                    <span class="bar-label"><?php echo $star; ?> ★</span>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?php echo $data['percentage']; ?>%;"></div>
                    </div>
                    <span class="bar-count"><?php echo $data['count']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Reviews Table -->
        <div class="card">
            <div class="card-header">
<h2>
    <i class="fas fa-comments"></i>
    Customer Feedback
</h2>                <div class="header-actions">
                    <form method="GET" class="filter-bar">
<input 
    type="text" 
    id="reviewSearch"
    name="search"
    placeholder="🔍 Search reviews..."
    value="<?php echo htmlspecialchars($search); ?>"
>
                        <select name="rating" id="ratingFilter">
                            <option value="all" <?php echo $rating_filter == 'all' ? 'selected' : ''; ?>>All Ratings</option>
                            <option value="5" <?php echo $rating_filter == '5' ? 'selected' : ''; ?>>5 ★</option>
                            <option value="4" <?php echo $rating_filter == '4' ? 'selected' : ''; ?>>4 ★</option>
                            <option value="3" <?php echo $rating_filter == '3' ? 'selected' : ''; ?>>3 ★</option>
                            <option value="2" <?php echo $rating_filter == '2' ? 'selected' : ''; ?>>2 ★</option>
                            <option value="1" <?php echo $rating_filter == '1' ? 'selected' : ''; ?>>1 ★</option>
                        </select>

                        <select name="sort" id="sortFilter">
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="highest" <?php echo $sort == 'highest' ? 'selected' : ''; ?>>Highest Rating</option>
                            <option value="lowest" <?php echo $sort == 'lowest' ? 'selected' : ''; ?>>Lowest Rating</option>
                        </select>

<button type="submit" class="btn-filter">
    <i class="fas fa-filter"></i> Apply
</button>                        <?php if($search || $rating_filter != 'all' || $sort != 'newest'): ?>
                            <a href="reviews-management.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- DESKTOP TABLE -->
            <div class="table-responsive desktop-table">
                <?php if(count($reviews) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Rating</th>
                            <th>Review</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reviews as $review): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($review['username']); ?></strong>
                                <br>
                                <small style="color: #94a3b8; font-size: 11px;"><?php echo htmlspecialchars($review['email']); ?></small>
                            </td>
                            <td>
                                <div class="review-stars">
                                    <?php echo renderStars($review['rating']); ?>
                                </div>
                                <small style="color: #94a3b8; font-size: 11px;"><?php echo getRatingText($review['rating']); ?></small>
                            </td>
                            <td>
                                <?php if($review['comment']): ?>
                                    <div class="review-text"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></div>
                                <?php else: ?>
                                    <span class="no-comment" style="color: #94a3b8; font-style: italic;">No comment provided</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                <br>
                                <small style="color: #94a3b8; font-size: 10px;"><?php echo date('h:i A', strtotime($review['created_at'])); ?></small>
                            </td>
                            <td>
                                <a href="?delete_review=<?php echo $review['id']; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $rating_filter != 'all' ? '&rating='.$rating_filter : ''; ?><?php echo $sort != 'newest' ? '&sort='.$sort : ''; ?>"
                                   class="btn-sm btn-danger"
                                   onclick="return confirm('Delete this review from <?php echo addslashes($review['username']); ?>? This action cannot be undone.')">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-comment-slash"></i>
                    <p>No reviews found.</p>
                    <?php if($search || $rating_filter != 'all'): ?>
                        <p style="font-size: 14px; margin-top: 5px; color: #94a3b8;">Try adjusting your search or filter criteria.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- MOBILE CARDS -->
            <div class="reviews-cards-mobile">
                <?php if(count($reviews) > 0): ?>
                    <?php foreach($reviews as $review): ?>
                    <div class="review-card-mobile">
                        <div class="card-top-row">
                            <div class="card-user">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($review['username'], 0, 1)); ?>
                                </div>
                                <div class="user-info">
                                    <div class="user-name"><?php echo htmlspecialchars($review['username']); ?></div>
                                    <div class="user-email"><?php echo htmlspecialchars($review['email']); ?></div>
                                </div>
                            </div>
                            <div class="card-rating">
                                <div class="stars"><?php echo renderSmallStars($review['rating']); ?></div>
                                <div class="rating-label"><?php echo getRatingText($review['rating']); ?></div>
                            </div>
                        </div>

                        <?php if($review['comment']): ?>
                            <div class="card-review-text">
                                "<?php echo nl2br(htmlspecialchars($review['comment'])); ?>"
                            </div>
                        <?php else: ?>
                            <div class="card-review-text no-comment">No comment provided</div>
                        <?php endif; ?>

                        <div class="card-row">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="card-label">Date</span>
                            <span class="card-value">
                                <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                · <?php echo date('h:i A', strtotime($review['created_at'])); ?>
                            </span>
                        </div>

                        <div class="card-actions">
                            <a href="?delete_review=<?php echo $review['id']; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $rating_filter != 'all' ? '&rating='.$rating_filter : ''; ?><?php echo $sort != 'newest' ? '&sort='.$sort : ''; ?>"
                               class="btn-card-action btn-delete-mobile"
                               onclick="return confirm('Delete this review from <?php echo addslashes($review['username']); ?>? This action cannot be undone.')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8; background:white; border-radius:14px; border:1px solid #e8f0fe;">
                        <i class="fas fa-comment-slash" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p>No reviews found.</p>
                        <?php if($search || $rating_filter != 'all'): ?>
                            <p style="font-size: 13px; margin-top: 5px; color: #94a3b8;">Try adjusting your search or filter criteria.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
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

    if (sidebar.classList.contains('open')) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = 'auto';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('open')) {
            toggleSidebar();
        }
    }
});

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 1024 && sidebar.classList.contains('open')) {
        toggleSidebar();
    }
});

// ============================================================
// LOGOUT CONFIRMATION MODAL
// ============================================================
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


// LIVE REVIEW SEARCH

document.addEventListener("DOMContentLoaded", function(){

    const searchInput = document.getElementById("reviewSearch");

    if(!searchInput) return;


    searchInput.addEventListener("input", function(){

        const keyword = this.value.toLowerCase();


        const rows = document.querySelectorAll(
            ".desktop-table tbody tr"
        );


        rows.forEach(function(row){

            const text = row.innerText.toLowerCase();


            if(text.includes(keyword)){
                row.style.display = "";
            } else {
                row.style.display = "none";
            }

        });


        const cards = document.querySelectorAll(
            ".review-card-mobile"
        );


        cards.forEach(function(card){

            const text = card.innerText.toLowerCase();


            if(text.includes(keyword)){
                card.style.display = "";
            } else {
                card.style.display = "none";
            }

        });


    });


});

// AUTO APPLY REVIEW FILTERS

document.addEventListener("DOMContentLoaded", function(){

    const ratingFilter = document.getElementById("ratingFilter");
    const sortFilter = document.getElementById("sortFilter");


    function applyReviewFilter(){

        const form = ratingFilter.closest("form");

        if(form){
            form.submit();
        }

    }


    if(ratingFilter){

        ratingFilter.addEventListener(
            "change",
            applyReviewFilter
        );

    }


    if(sortFilter){

        sortFilter.addEventListener(
            "change",
            applyReviewFilter
        );

    }


});
</script>

</body>
</html>
<?php
session_start();

date_default_timezone_set('Asia/Manila');

require_once 'database.php';
require_once 'includes/sidebar-counts.php';


if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: index.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

// Fetch admin/staff info
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {
    $user_info = [];
}

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

$admin_avatar = getAdminAvatar($user_info);
$admin_initial = strtoupper(substr($user_info['fullname'] ?? $user_info['username'] ?? 'U', 0, 1));
$admin_display_name = $user_info['fullname'] ?? $user_info['username'] ?? 'User';

// ============================================================
// DASHBOARD STATS
// ============================================================
$total_houses = 0;
$available_houses = 0;
try {
    $total_houses = $pdo->query("SELECT COUNT(*) FROM houses")->fetchColumn();
    $available_houses = $pdo->query("SELECT COUNT(*) FROM houses WHERE status = 'available'")->fetchColumn();
} catch(PDOException $e) {}

$total_tours = 0;
$available_tours = 0;
try {
    $total_tours = $pdo->query("SELECT COUNT(*) FROM tours")->fetchColumn();
    $available_tours = $pdo->query("SELECT COUNT(*) FROM tours WHERE status = 'available'")->fetchColumn();
} catch(PDOException $e) {}

$total_users = 0;
try {
    $total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'guest'")->fetchColumn();
} catch(PDOException $e) {}

$total_house_bookings = 0;
$total_tour_bookings = 0;
try {
    $total_house_bookings = $pdo->query("SELECT COUNT(*) FROM house_bookings")->fetchColumn();
} catch(PDOException $e) {}

try {
    $total_tour_bookings = $pdo->query("SELECT COUNT(*) FROM tour_bookings")->fetchColumn();
} catch(PDOException $e) {}

$total_reviews = 0;
$avg_rating = 0;
try {
    $total_reviews = $pdo->query("SELECT COUNT(*) FROM overall_feedback")->fetchColumn();
    $avg_rating = $pdo->query("SELECT AVG(rating) FROM overall_feedback")->fetchColumn();
    $avg_rating = $avg_rating ? round($avg_rating, 1) : 0;
} catch(PDOException $e) {}

$total_food = 0;
$available_food = 0;
$featured_food = 0;
try {
    $total_food = $pdo->query("SELECT COUNT(*) FROM food_items")->fetchColumn();
    $available_food = $pdo->query("SELECT COUNT(*) FROM food_items WHERE is_available = 1")->fetchColumn();
    $featured_food = $pdo->query("SELECT COUNT(*) FROM food_items WHERE is_featured = 1")->fetchColumn();
} catch(PDOException $e) {}

$total_blocked = 0;
$today_blocked = 0;
$house_blocked = 0;
$tour_blocked = 0;
try {
    $total_blocked  = (int)$pdo->query("SELECT COUNT(*) FROM blocked_dates")->fetchColumn();
    $today_blocked  = (int)$pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE block_date >= CURDATE()")->fetchColumn();
    $house_blocked  = (int)$pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE item_type = 'house'")->fetchColumn();
    $tour_blocked   = (int)$pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE item_type = 'tour'")->fetchColumn();
} catch (PDOException $e) {}

// Recent bookings
$recent_house_bookings = [];
try {
    $stmt = $pdo->query("SELECT b.*, h.house_name, u.fullname as guest_name 
                         FROM house_bookings b 
                         LEFT JOIN houses h ON b.house_id = h.id 
                         LEFT JOIN guests g ON b.guest_id = g.id 
                         LEFT JOIN users u ON g.user_id = u.id 
                         ORDER BY b.created_at DESC LIMIT 5");
    $recent_house_bookings = $stmt->fetchAll();
} catch(PDOException $e) {}

$recent_tour_bookings = [];
try {
    $stmt = $pdo->query("SELECT b.*, t.tour_name, u.fullname as guest_name 
                        FROM tour_bookings b 
                        LEFT JOIN tours t ON b.tour_id = t.id 
                        LEFT JOIN guests g ON b.guest_id = g.id 
                        LEFT JOIN users u ON g.user_id = u.id 
                        ORDER BY b.created_at DESC LIMIT 5");
    $recent_tour_bookings = $stmt->fetchAll();
} catch(PDOException $e) {}

$recent_reviews = [];
try {
    $stmt = $pdo->query("SELECT f.*, u.username 
                         FROM overall_feedback f 
                         JOIN users u ON f.user_id = u.id 
                         ORDER BY f.created_at DESC LIMIT 5");
    $recent_reviews = $stmt->fetchAll();
} catch(PDOException $e) {}

$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {}

// Logout
if(isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out",
            (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

// Logo
$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Hundred Islands';

function renderSmallStars($rating) {
    $html = '';
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $fullStars) {
            $html .= '<i class="fas fa-star" style="color: #FFC107; font-size: 14px;"></i>';
        } elseif ($i == $fullStars + 1 && $halfStar) {
            $html .= '<i class="fas fa-star-half-alt" style="color: #FFC107; font-size: 14px;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color: #d1d5db; font-size: 14px;"></i>';
        }
    }
    return $html;
}

function logActionIcon($action) {
    $icons = [
        'create'          => ['fa-plus-circle',          '#10b981'],
        'update'          => ['fa-edit',                 '#3b82f6'],
        'delete'          => ['fa-trash',                '#ef4444'],
        'login'           => ['fa-sign-in-alt',          '#10b981'],
        'login_failed'    => ['fa-exclamation-triangle', '#ef4444'],
        'logout'          => ['fa-sign-out-alt',         '#64748b'],
        'register'        => ['fa-user-plus',            '#8b5cf6'],
        'verify_device'   => ['fa-shield-alt',           '#10b981'],
        'confirm_payment' => ['fa-check-circle',         '#10b981'],
        'reject_payment'  => ['fa-times-circle',         '#ef4444'],
        'confirm_rebook'  => ['fa-redo',                 '#10b981'],
        'rebook'          => ['fa-redo',                 '#f59e0b'],
        'cancel_rebook'   => ['fa-undo',                 '#f59e0b'],
        'upload_proof'    => ['fa-upload',               '#0ea5e9'],
        'export'          => ['fa-file-csv',             '#10b981'],
        'block_date'      => ['fa-ban',                  '#64748b'],
        'unblock_date'    => ['fa-unlock',               '#10b981'],
        'bulk_block'      => ['fa-layer-group',          '#64748b'],
        'unblock_all'     => ['fa-unlock-alt',           '#10b981'],
        'multi_block'     => ['fa-layer-group',          '#64748b'],
    ];
    return $icons[$action] ?? ['fa-circle', '#94a3b8'];
}

function getBookingStatusLabel($payment_status) {
    $status = trim((string)$payment_status);
    if ($status === '' || strtolower($status) === 'null') {
        return ['label' => 'Unpaid', 'class' => 'badge-danger'];
    }
    $status = strtolower($status);
    if ($status === 'paid') return ['label' => 'Paid', 'class' => 'badge-success'];
    if ($status === 'pending') return ['label' => 'Pending', 'class' => 'badge-warning'];
    if ($status === 'rejected' || $status === 'cancelled') return ['label' => ucfirst($status), 'class' => 'badge-danger'];
    return ['label' => ucfirst($status), 'class' => 'badge-warning'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo $is_admin ? 'Admin' : 'Staff'; ?> Dashboard - Hundred Islands</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }
        .app-container { display: flex; min-height: 100vh; }

        /* ==================== SIDEBAR ==================== */
        .sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); z-index: 100; flex-shrink: 0; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; overflow: hidden; }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
        .nav-link .nav-badge { margin-left: auto; background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 99; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.3); border: 1px solid rgba(77, 166, 217, 0.2); }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; }

        .sidebar-close-btn { display: flex; }

        @media (max-width: 480px) {
            .sidebar { width: 85%; max-width: 300px; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; }
            .main-content { padding: 60px 12px 16px !important; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
        }

        /* ==================== MAIN CONTENT ==================== */
        .main-content {
            flex: 1;
            padding: 25px 35px;
            min-width: 0;
            width: calc(100% - 280px);
            overflow: hidden;
        }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11, 36, 71, 0.1); flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
        .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
        .top-bar .user-profile .avatar { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 26px; border: 3px solid rgba(77, 166, 217, 0.35); flex-shrink: 0; overflow: hidden; }
        .top-bar .user-profile .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .top-bar .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 15px; }
        .top-bar .user-profile .user-role { color: #4a6a8c; font-size: 13px; }

        .mobile-role-badge, .mobile-avatar { display: none; }
        .mobile-avatar { display: none; width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 32px; border: 4px solid #4DA6D9; flex-shrink: 0; overflow: hidden; }
        .mobile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

        @media (max-width: 768px) {
            .top-bar { position: relative; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px; padding: 15px 100px; min-height: 130px; }
            .top-bar .page-title { display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%; }
            .top-bar .page-title h1 { font-size: 20px; display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 8px; margin: 0; }
            .top-bar .page-title h1 .mobile-role-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
            .top-bar .page-title h1 .mobile-role-badge.admin { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1.5px solid rgba(239, 68, 68, 0.25); }
            .top-bar .page-title h1 .mobile-role-badge.staff { background: rgba(251, 191, 36, 0.15); color: #d97706; border: 1.5px solid rgba(251, 191, 36, 0.3); }
            .top-bar .page-title h1 .mobile-avatar { display: inline-flex; position: absolute; top: 50%; right: 14px; transform: translateY(-50%); }
            .top-bar .page-title p { font-size: 12px; text-align: center; margin: 0; }
            .top-bar .user-profile { display: none !important; }
        }
        @media (max-width: 480px) {
            .top-bar { padding: 15px 92px; min-height: 120px; }
            .top-bar .page-title h1 { font-size: 17px; }
            .top-bar .page-title h1 .mobile-avatar { width: 72px; height: 72px; font-size: 28px; }
        }

        /* ==================== WELCOME BANNER ==================== */
        .welcome-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; position: relative; overflow: hidden; }
        .welcome-banner::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); animation: rotate 20s linear infinite; }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .welcome-text { position: relative; z-index: 1; }
        .welcome-text h1 { font-size: 26px; font-weight: 700; margin-bottom: 5px; }
        .welcome-text p { opacity: 0.85; font-size: 14px; margin: 0; }
        .date-badge { background: rgba(255,255,255,0.15); padding: 10px 22px; border-radius: 50px; font-weight: 500; position: relative; z-index: 1; border: 1px solid rgba(255,255,255,0.15); font-size: 14px; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

        @media (max-width: 768px) {
            .welcome-banner { padding: 20px; flex-direction: column; text-align: center; border-radius: 16px; }
            .welcome-text h1 { font-size: 22px; }
            .date-badge { font-size: 12px; padding: 8px 16px; width: 100%; justify-content: center; }
        }

        /* ==================== STATS GRID ==================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 35px;
        }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 900px)  { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px)  { .stats-grid { grid-template-columns: 1fr; } }

     .stat-card{

    background:white;

    border-radius:22px;

    padding:24px;

    text-decoration:none;

    color:#0B2447;

    min-height:180px;

    border:1px solid #e8f0fe;

    box-shadow:
    0 12px 30px rgba(6,38,61,.08);

    position:relative;

    overflow:hidden;

    transition:.25s ease;

}


.stat-card:hover{

    transform:translateY(-6px);

    box-shadow:
    0 20px 45px rgba(6,38,61,.15);

}



.stat-icon{

    width:55px;

    height:55px;

    border-radius:18px;

    display:flex;

    align-items:center;

    justify-content:center;

    font-size:24px;

    background:#e8f5ff;

    color:#4DA6D9;

}



.stat-number{

    margin-top:18px;

    font-size:36px;

    font-weight:800;

    color:#0B2447;

}



.stat-label{

    font-size:15px;

    font-weight:700;

    color:#334155;

}



.stat-small{

    margin-top:10px;

    font-size:12px;

    color:#64748b;

}
.stat-card:nth-child(1) .stat-icon{
    background:#dbeafe;
    color:#2563eb;
}


.stat-card:nth-child(2) .stat-icon{
    background:#cffafe;
    color:#0891b2;
}


.stat-card:nth-child(3) .stat-icon{
    background:#ede9fe;
    color:#7c3aed;
}


.stat-card:nth-child(4) .stat-icon{
    background:#dcfce7;
    color:#16a34a;
}


.stat-card:nth-child(5) .stat-icon{
    background:#fef3c7;
    color:#d97706;
}


.stat-card:nth-child(6) .stat-icon{
    background:#fef9c3;
    color:#ca8a04;
}


.stat-card:nth-child(7) .stat-icon{
    background:#ffedd5;
    color:#ea580c;
}


.stat-card:nth-child(8) .stat-icon{
    background:#e2e8f0;
    color:#475569;
}

/* FIX DASHBOARD STAT TEXT COLORS */

.stat-number{
    color:#0B2447 !important;
    font-size:36px;
    font-weight:800;
    margin-top:18px;
}


.stat-label{
    color:#334155 !important;
    font-size:15px;
    font-weight:700;
}


.stat-small{
    color:#64748b !important;
    font-size:12px;
}


.stat-small.warning{
    color:#f59e0b !important;
}

        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; }
        .stat-number { font-size: 28px; font-weight: 700; color: white; margin-top: 10px; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 13px; font-weight: 500; margin-top: 2px; }
        .stat-small { font-size: 11px; color: rgba(255,255,255,0.8); margin-top: 8px; display: flex; align-items: center; gap: 5px; }
        .stat-small.warning { color: #F4B400; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; min-height: 0; }
            .stat-number { font-size: 22px; }
            .stat-icon { width: 40px; height: 40px; font-size: 16px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 10px; min-height: 0; }
            .stat-number { font-size: 18px; }
            .stat-icon { width: 32px; height: 32px; font-size: 14px; }
        }

        /* ==================== DASHBOARD GRID (SINGLE SOURCE OF TRUTH) ==================== */
        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
            width: 100%;
        }

        .dashboard-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
            min-width: 0;
        }

        @media (max-width: 900px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ==================== CARDS ==================== */
        .card {
            background: #ffffff;
            border-radius: 24px;
            padding: 0;
            width: 100%;
            height: auto;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(6,38,61,.08);
            border: 1px solid #e8f0fe;
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        .card-header { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); color: #ffffff; display: flex; justify-content: space-between; align-items: center; padding: 14px 22px; flex-wrap: wrap; gap: 10px; position: relative; overflow: hidden; flex-shrink: 0; }
        .card-header h2 { font-size: 15px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 10px; margin: 0; position: relative; z-index: 1; }
        .card-header h2 i { color: #0B2447; background: #ffffff; padding: 6px; border-radius: 8px; font-size: 13px; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; }
        .card-header a { color: #F4B400; text-decoration: none; font-weight: 600; font-size: 12.5px; position: relative; z-index: 1; }
        .card-header a:hover { text-decoration: underline; color: #fff; }

        .card > .quick-actions,
        .card > .table-responsive,
        .card > .dashboard-cards-mobile,
        .card > div:not(.card-header) {
            padding-left: 22px;
            padding-right: 22px;
            padding-top: 18px;
            padding-bottom: 18px;
        }

        @media (max-width: 768px) {
            .card-header { padding: 12px 16px; }
            .card-header h2 { font-size: 13.5px; }
            .card > .quick-actions,
            .card > .table-responsive,
            .card > .dashboard-cards-mobile,
            .card > div:not(.card-header) {
                padding-left: 16px;
                padding-right: 16px;
            }
        }
        @media (max-width: 480px) {
            .card > .quick-actions,
            .card > .table-responsive,
            .card > .dashboard-cards-mobile,
            .card > div:not(.card-header) {
                padding-left: 12px;
                padding-right: 12px;
            }
        }

        /* ==================== QUICK ACTIONS ==================== */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .action-btn {
            background: #f8fafc;
            border: 1px solid #e5edf5;
            border-radius: 18px;
            padding: 20px;
            min-height: 130px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            text-decoration: none;
            color: #0B2447;
            transition: .25s ease;
        }
        .action-btn:hover {
            background: #4DA6D9;
            color: white;
            transform: translateY(-5px);
        }
        .action-btn i { font-size: 28px; color: #4DA6D9; margin-bottom: 8px; }
        .action-btn:hover i { color: white; }
        .action-btn span { font-weight: 600; font-size: 13px; }
        .action-btn small { color: #4a6a8c; font-size: 10px; }
        .action-btn:hover small { color: rgba(255,255,255,0.85); }
        .action-btn.blocked-action { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); border-color: #cbd5e1; }
        .action-btn.blocked-action i { color: #64748b; }
        .action-btn.blocked-action:hover { background: linear-gradient(135deg, #64748b, #475569); }
        .action-btn.blocked-action:hover i { color: #ffffff; }

        @media (max-width: 480px) {
            .action-btn { padding: 10px 8px; gap: 4px; min-height: 0; }
            .action-btn i { font-size: 16px; }
            .action-btn span { font-size: 10px; }
            .action-btn small { display: none; }
        }

        /* ==================== TABLES ==================== */
        .table-responsive { overflow-x: auto; margin: 0; }
        table { width: 100%; border-collapse: collapse; min-width: 300px; }
        th { text-align: left; padding: 10px 12px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 11px; text-transform: uppercase; white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; word-break: break-word; }
        tr:last-child td { border-bottom: none; }

        .dashboard-cards-mobile { display: none; flex-direction: column; gap: 10px; }
        @media (max-width: 768px) { .table-responsive.desktop-table { display: none !important; } .dashboard-cards-mobile { display: flex; } }

        .dashboard-card-mobile { background: #f8fafc; border-radius: 12px; padding: 12px; border: 1px solid #e8f0fe; display: flex; flex-direction: column; gap: 8px; color: #0B2447; }
        .dashboard-card-mobile .card-top-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .dashboard-card-mobile .card-title { font-weight: 700; color: #0B2447; font-size: 13px; word-break: break-word; flex: 1; }
        .dashboard-card-mobile .card-badges { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
        .dashboard-card-mobile .card-row { display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: #475569; }
        .dashboard-card-mobile .card-row i { width: 16px; color: #4DA6D9; flex-shrink: 0; font-size: 11px; }
        .dashboard-card-mobile .card-row .card-label { color: #94a3b8; font-size: 10px; font-weight: 700; min-width: 52px; text-transform: uppercase; }
        .dashboard-card-mobile .card-row .card-value { font-weight: 600; color: #0B2447; word-break: break-word; flex: 1; }
        .dashboard-card-mobile .card-value.muted { color: #94a3b8; font-style: italic; font-weight: 400; }
        .dashboard-card-mobile .card-stars { display: inline-flex; gap: 2px; font-size: 12px; }

        /* ==================== BADGES ==================== */
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; text-transform: uppercase; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-danger { background: #fee2e2; color: #ef4444; }

        .review-stars { white-space: nowrap; }
        .review-text { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* ==================== FOOTER ==================== */
        .footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15); }
        .footer i { color: #4DA6D9; }
        @media (max-width: 768px) { .footer { font-size: 11px; padding: 12px 10px; } }

        /* ==================== LOGOUT MODAL ==================== */
        .logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; }
        .logout-modal-overlay.show { display: flex; }
        .logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); border-top: 6px solid #ef4444; }
        .logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; }
        .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .logout-modal p { color: #64748b; font-size: 14px; margin-bottom: 25px; }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .btn-logout-cancel { background: #e2e8f0; color: #475569; }
        .btn-logout-cancel:hover { background: #cbd5e1; }
        .btn-logout-confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }
        .btn-logout-confirm:hover { box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45); color: white; }

        /* ==================== BUSINESS OVERVIEW SECTION ==================== */
        .dashboard-section {
            background: #fff;
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 30px;
            box-shadow: 0 15px 40px rgba(6,38,61,.08);
        }
        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .section-heading h2 { margin: 0; font-size: 22px; font-weight: 800; color: #06263d; }
        .section-heading i { color: #1685c8; }
        .section-heading span { color: #64748b; font-size: 14px; }

        @media (max-width: 480px) {
            .dashboard-section { padding: 18px; border-radius: 18px; }
            .section-heading h2 { font-size: 18px; }
            .section-heading span { font-size: 12px; }
        }


        /* FINAL ADMIN DASHBOARD LAYOUT OVERRIDE */
        .main-content .dashboard-grid {
            display:grid !important;
            grid-template-columns:minmax(0,1fr) minmax(0,1fr) !important;
            gap:24px !important;
            align-items:start !important;
            width:100% !important;
        }

        .main-content .dashboard-column {
            display:flex !important;
            flex-direction:column !important;
            gap:24px !important;
            min-width:0 !important;
        }

        .main-content .card {
            width:100% !important;
            max-width:100% !important;
            height:auto !important;
            min-width:0 !important;
        }

        .main-content .stats-grid {
            grid-template-columns:repeat(4,minmax(0,1fr)) !important;
            overflow:hidden !important;
        }

        @media(max-width:1100px){
            .main-content .stats-grid {
                grid-template-columns:repeat(2,minmax(0,1fr)) !important;
            }
        }

        @media(max-width:900px){
            .main-content .dashboard-grid {
                grid-template-columns:1fr !important;
            }
        }

/* FINAL DASHBOARD ALIGNMENT */

.main-content .dashboard-grid {
    display:grid !important;
    grid-template-columns: minmax(0,1fr) minmax(0,1fr) !important;
    gap:24px !important;
    align-items:start !important;
}

.main-content .dashboard-column {
    display:flex !important;
    flex-direction:column !important;
    gap:24px !important;
}

.main-content .dashboard-column .card {
    width:100% !important;
    height:auto !important;
}


/* FINAL CARD NATURAL HEIGHT */

.main-content .dashboard-column .card {
    height:auto !important;
    min-height:0 !important;
}

.main-content .table-responsive {
    min-height:0;
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
            <li class="nav-item">
                <a href="admin-dashboard.php" class="nav-link active">
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

            <li class="nav-item">
                <a href="reviews-management.php" class="nav-link">
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

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                    <?php echo $is_admin ? 'Admin' : 'Staff'; ?> Dashboard

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
                <p>Manage your transient house, tours, and activities</p>
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

        <div class="welcome-banner">
            <div class="welcome-text">
                <h1> Welcome back, <?php echo $is_admin ? 'Admin' : 'Staff'; ?>!</h1>
                <p>Here's what's happening with your Hundred Islands business today.</p>
            </div>
            <div class="date-badge">
                <i class="fas fa-calendar-alt"></i> <?php echo date('F j, Y'); ?>
                <span style="opacity: 0.5; margin: 0 5px;">|</span>
                <i class="fas fa-clock"></i> <?php echo date('h:i A'); ?>
            </div>
        </div>

        <!-- ============================================================
             BUSINESS OVERVIEW
             ============================================================ -->
        <div class="stats-panel">
            <section class="dashboard-section overview-section">
                <div class="section-heading">
                    <h2>
                        <i class="fas fa-chart-line"></i>
                        Business Overview
                    </h2>
                    <span>Today's summary</span>
                </div>

                <div class="stats-grid">
                    <a href="house-dashboard.php" class="stat-card">
                        <div class="stat-icon"><i class="fas fa-home"></i></div>
                        <div class="stat-number"><?php echo $total_houses; ?></div>
                        <div class="stat-label">Total Houses</div>
                        <div class="stat-small"><i class="fas fa-check-circle"></i> <?php echo $available_houses; ?> available</div>
                    </a>

                    <a href="tour-dashboard.php" class="stat-card">
                        <div class="stat-icon"><i class="fas fa-umbrella-beach"></i></div>
                        <div class="stat-number"><?php echo $total_tours; ?></div>
                        <div class="stat-label">Tour Packages</div>
                        <div class="stat-small"><i class="fas fa-check-circle"></i> <?php echo $available_tours; ?> available</div>
                    </a>

              

                    <a href="booking-management.php" class="stat-card">
                        <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="stat-number"><?php echo $total_house_bookings + $total_tour_bookings; ?></div>
                        <div class="stat-label">Total Bookings</div>
                        <div class="stat-small">
                            <i class="fas fa-home"></i> <?php echo $total_house_bookings; ?> houses
                            <span style="margin: 0 4px;">|</span>
                            <i class="fas fa-umbrella-beach"></i> <?php echo $total_tour_bookings; ?> tours
                        </div>
                    </a>

                     <a href="blocked-dates.php" class="stat-card" style="background: linear-gradient(135deg, #64748b, #475569);">
                        <div class="stat-icon"><i class="fas fa-ban"></i></div>
                        <div class="stat-number"><?php echo $total_blocked; ?></div>
                        <div class="stat-label">Blocked Dates</div>
                        <div class="stat-small">
                            <i class="fas fa-home"></i> <?php echo $house_blocked; ?> houses
                            <span style="margin: 0 4px;">|</span>
                            <i class="fas fa-umbrella-beach"></i> <?php echo $tour_blocked; ?> tours
                        </div>
                    </a>

                    <a href="booking-management.php?status=pending" class="stat-card">
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                        <div class="stat-number"><?php echo $sidebar_pending_bookings; ?></div>
                        <div class="stat-label">Pending Bookings</div>
                        <div class="stat-small warning"><i class="fas fa-exclamation-triangle"></i> Need attention</div>
                    </a>

                          <?php if($is_admin): ?>
                 <a href="booking-management.php?status=pending" class="stat-card">

    <div class="stat-icon">
        <i class="fas fa-clock"></i>
    </div>

    <div class="stat-number">
        <?php echo $sidebar_pending_bookings; ?>
    </div>

    <div class="stat-label">
        Pending Payment
    </div>

    <div class="stat-small warning">
        <i class="fas fa-exclamation-circle"></i>
        Need verification
    </div>

</a>
                    <?php endif; ?>

                    <a href="reviews-management.php" class="stat-card">
                        <div class="stat-icon"><i class="fas fa-star"></i></div>
                        <div class="stat-number"><?php echo $total_reviews; ?></div>
                        <div class="stat-label">Customer Reviews</div>
                        <div class="stat-small" style="color: #F4B400;"><i class="fas fa-star"></i> <?php echo $avg_rating; ?> / 5</div>
                    </a>

                    <a href="food-dashboard.php" class="stat-card">
                        <div class="stat-icon"><i class="fas fa-utensils"></i></div>
                        <div class="stat-number"><?php echo $total_food; ?></div>
                        <div class="stat-label">Food Items</div>
                        <div class="stat-small"><i class="fas fa-check-circle"></i> <?php echo $available_food; ?> available</div>
                    </a>

                   
                </div>
            </section>
        </div>

        <!-- ============================================================
             DASHBOARD GRID
             LEFT:  Quick Actions + Recent Tour Bookings
             RIGHT: Recent House Bookings + Recent Reviews
             ============================================================ -->
        <div class="dashboard-grid">

            <!-- ==================== LEFT COLUMN ==================== -->
            <div class="dashboard-column">

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    </div>

                    <div class="quick-actions">
                        <a href="house-dashboard.php" class="action-btn">
                            <i class="fas fa-plus"></i>
                            <span>Add House</span>
                            <small>Create new accommodation</small>
                        </a>

                        <a href="tour-dashboard.php" class="action-btn">
                            <i class="fas fa-ship"></i>
                            <span>Add Tour</span>
                            <small>Create tour package</small>
                        </a>

                        <a href="booking-management.php" class="action-btn">
                            <i class="fas fa-calendar-check"></i>
                            <span>Review Bookings</span>
                            <small>Verify payments</small>
                        </a>

                        <a href="blocked-dates.php" class="action-btn blocked-action">
                            <i class="fas fa-ban"></i>
                            <span>Block Date</span>
                            <small>Manage availability</small>
                        </a>
                    </div>
                </div>

                <!-- Recent Tour Bookings -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-umbrella-beach"></i> Recent Tour Bookings</h2>
                        <a href="booking-management.php">View All →</a>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr><th>Guest</th><th>Tour</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if(empty($recent_tour_bookings)): ?>
                                <tr><td colspan="3" style="text-align: center; color: #94a3b8; padding: 20px;">No tour bookings yet</td></tr>
                                <?php else: ?>
                                    <?php foreach($recent_tour_bookings as $booking):
                                        $bstat = getBookingStatusLabel($booking['payment_status'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($booking['guest_name'] ?? 'N/A'); ?></td>
                                        <td><strong><?php echo $booking['tour_name'] ?? 'N/A'; ?></strong></td>
                                        <td><span class="badge <?php echo $bstat['class']; ?>"><?php echo $bstat['label']; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="dashboard-cards-mobile">
                        <?php if(empty($recent_tour_bookings)): ?>
                            <div style="text-align: center; color: #94a3b8; padding: 20px;">No tour bookings yet</div>
                        <?php else: ?>
                            <?php foreach($recent_tour_bookings as $booking):
                                $bstat = getBookingStatusLabel($booking['payment_status'] ?? '');
                            ?>
                            <div class="dashboard-card-mobile">
                                <div class="card-top-row">
                                    <div class="card-title"><?php echo htmlspecialchars($booking['guest_name'] ?? 'N/A'); ?></div>
                                    <div class="card-badges"><span class="badge <?php echo $bstat['class']; ?>"><?php echo $bstat['label']; ?></span></div>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-umbrella-beach"></i>
                                    <span class="card-label">Tour</span>
                                    <span class="card-value"><?php echo $booking['tour_name'] ?? 'N/A'; ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- ==================== RIGHT COLUMN ==================== -->
            <div class="dashboard-column">

                <!-- Recent House Bookings -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-home"></i> Recent House Bookings</h2>
                        <a href="booking-management.php">View All →</a>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr><th>Guest</th><th>House</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if(empty($recent_house_bookings)): ?>
                                <tr><td colspan="3" style="text-align: center; color: #94a3b8; padding: 20px;">No house bookings yet</td></tr>
                                <?php else: ?>
                                    <?php foreach($recent_house_bookings as $booking):
                                        $bstat = getBookingStatusLabel($booking['payment_status'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($booking['guest_name'] ?? 'N/A'); ?></td>
                                        <td><strong><?php echo $booking['house_name'] ?? 'N/A'; ?></strong></td>
                                        <td><span class="badge <?php echo $bstat['class']; ?>"><?php echo $bstat['label']; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="dashboard-cards-mobile">
                        <?php if(empty($recent_house_bookings)): ?>
                            <div style="text-align: center; color: #94a3b8; padding: 20px;">No house bookings yet</div>
                        <?php else: ?>
                            <?php foreach($recent_house_bookings as $booking):
                                $bstat = getBookingStatusLabel($booking['payment_status'] ?? '');
                            ?>
                            <div class="dashboard-card-mobile">
                                <div class="card-top-row">
                                    <div class="card-title"><?php echo htmlspecialchars($booking['guest_name'] ?? 'N/A'); ?></div>
                                    <div class="card-badges"><span class="badge <?php echo $bstat['class']; ?>"><?php echo $bstat['label']; ?></span></div>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-home"></i>
                                    <span class="card-label">House</span>
                                    <span class="card-value"><?php echo $booking['house_name'] ?? 'N/A'; ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Reviews -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-star"></i> Recent Reviews</h2>
                        <a href="reviews-management.php">View All →</a>
                    </div>

                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Rating</th>
                                    <th>Review</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($recent_reviews)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px;">
                                        <i class="fas fa-comment-slash" style="display: block; font-size: 20px; margin-bottom: 5px;"></i>
                                        No reviews yet
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach($recent_reviews as $review): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($review['username']); ?></strong></td>
                                        <td><span class="review-stars"><?php echo renderSmallStars($review['rating']); ?></span></td>
                                        <td class="review-text"><?php echo $review['comment'] ? htmlspecialchars($review['comment']) : '<span style="color: #94a3b8; font-style: italic;">No comment</span>'; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($review['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="dashboard-cards-mobile">
                        <?php if(empty($recent_reviews)): ?>
                            <div style="text-align: center; color: #94a3b8; padding: 20px;">No reviews yet</div>
                        <?php else: ?>
                            <?php foreach($recent_reviews as $review): ?>
                            <div class="dashboard-card-mobile">
                                <div class="card-top-row">
                                    <div class="card-title"><?php echo htmlspecialchars($review['username']); ?></div>
                                    <div class="card-badges"><span class="card-stars"><?php echo renderSmallStars($review['rating']); ?></span></div>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-comment"></i>
                                    <span class="card-label">Review</span>
                                    <span class="card-value <?php echo empty($review['comment']) ? 'muted' : ''; ?>"><?php echo $review['comment'] ? htmlspecialchars($review['comment']) : 'No comment'; ?></span>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span class="card-label">Date</span>
                                    <span class="card-value"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

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
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) closeLogoutModal();
            });
        }
    });
</script>

</body>
</html>
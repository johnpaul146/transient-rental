<?php
// ============================================================
// reports.php — with mobile-friendly tabs + dynamic stats
// ============================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'database.php';
require_once 'includes/Exporter.php';

// ✅ NEW: Load SystemLogger (for logout logging)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: index.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

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

$logo_path = 'uploads/logos/logo.png';
$logo_exists = file_exists($logo_path);

// ============================================================
// HELPER: Build date range filter from GET/POST
// ============================================================
function buildReportFilters() {
    $filters = [];
    if(isset($_REQUEST['date_from'])) $filters['date_from'] = $_REQUEST['date_from'];
    if(isset($_REQUEST['date_to']))   $filters['date_to']   = $_REQUEST['date_to'];
    if(isset($_REQUEST['status']))    $filters['status']    = $_REQUEST['status'];
    if(isset($_REQUEST['payment']))   $filters['payment']   = $_REQUEST['payment'];
    if(isset($_REQUEST['rating']))    $filters['rating']    = $_REQUEST['rating'];
    if(isset($_REQUEST['role']))      $filters['role']      = $_REQUEST['role'];
    return $filters;
}

// ============================================================
// ✅ NEW: SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================

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

// ✅ System Logs — failed only
$log_stats = ['failed' => 0];
try {
    $log_stats['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM system_logs WHERE status = 'failed'")->fetchColumn();
} catch (PDOException $e) {}

// ============================================================
// AJAX: DYNAMIC STATS (based on date range)
// ============================================================
if(isset($_GET['get_stats']) && ($is_admin || $is_staff)) {
    header('Content-Type: application/json');
    try {
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to   = $_GET['date_to']   ?? date('Y-m-t');

        // Ensure date_to includes the full day
        $date_to_full = $date_to . ' 23:59:59';

        $stats = [
            'total_bookings' => 0,
            'total_revenue'  => 0,
            'total_users'    => 0,
            'total_reviews'  => 0,
        ];

        // ---- Total Bookings (within date range, using created_at) ----
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM house_bookings 
                                   WHERE booking_status != 'cancelled' 
                                   AND created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_bookings'] += (int)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings 
                                   WHERE booking_status != 'cancelled' 
                                   AND created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_bookings'] += (int)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM food_bookings 
                                   WHERE booking_status != 'cancelled' 
                                   AND created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_bookings'] += (int)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        // ---- Total Revenue (paid bookings, within date range) ----
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM house_bookings 
                                   WHERE payment_status = 'paid' 
                                   AND created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_revenue'] += (float)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM tour_bookings 
                                   WHERE payment_status = 'paid' 
                                   AND created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_revenue'] += (float)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM food_bookings 
                                   WHERE payment_status = 'paid' 
                                   AND created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_revenue'] += (float)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        // ---- Total Users (registered within date range) ----
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users 
                                   WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_users'] = (int)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        // ---- Total Reviews (within date range) ----
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM overall_feedback 
                                   WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to_full]);
            $stats['total_reviews'] = (int)$stmt->fetchColumn();
        } catch(PDOException $e) {}

        echo json_encode(['success' => true, 'stats' => $stats]);
        exit();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// ============================================================
// HANDLE REPORT PREVIEW (AJAX)
// ============================================================
if(isset($_GET['preview']) && ($is_admin || $is_staff)) {
    header('Content-Type: application/json');
    try {
        $report_type = $_GET['preview'];
        $filters = buildReportFilters();

        if (!class_exists('ReportExporter')) {
            throw new Exception("ReportExporter class not found. Please check includes/Exporter.php");
        }

        $exporter = new ReportExporter();
        $data = [];
        $headers = [];

        switch($report_type) {
            case 'bookings':
                $data = $exporter->getBookingsData($filters);
                $headers = ['Reference', 'Type', 'Item', 'Guest Name', 'Phone', 'Email', 'Start Date', 'End Date', 'Pax', 'Total Amount', 'Payment Status', 'Booking Status', 'Booking Date', 'Payment Date', 'Rating', 'Feedback', 'Guest Names'];
                break;
            case 'revenue':
                $data = $exporter->getRevenueData($filters);
                $headers = ['Date', 'House Revenue', 'Tour Revenue', 'Food Revenue', 'Total Revenue'];
                break;
            case 'users':
                $data = $exporter->getUsersData($filters);
                $headers = ['ID', 'Username', 'Full Name', 'Email', 'Phone', 'Address', 'Role', 'Registered Date', 'Total Bookings', 'Total Spent', 'ID Type', 'ID Number', 'Emergency Contact', 'Emergency Number'];
                break;
            case 'houses':
                $data = $exporter->getHousesData();
                $headers = ['ID', 'House Name', 'Description', 'Price Per Night', 'Capacity', 'Bedrooms', 'Amenities', 'Status', 'Total Bookings', 'Total Revenue', 'Created Date'];
                break;
            case 'tours':
                $data = $exporter->getToursData();
                $headers = ['ID', 'Tour Name', 'Description', 'Price Per Boat', 'Boat Capacity', 'Status', 'Total Bookings', 'Total Revenue', 'Created Date'];
                break;
            case 'food':
                $data = $exporter->getFoodData();
                $headers = ['ID', 'Name', 'Description', 'Price', 'Category', 'PAX Range', 'Available', 'Featured', 'Total Orders', 'Total Revenue', 'Created Date'];
                break;
            case 'feedback':
                $data = $exporter->getFeedbackData($filters);
                $headers = ['ID', 'Username', 'Full Name', 'Email', 'Rating', 'Comment', 'Created Date', 'Anonymous'];
                break;
            case 'activities':
                $data = $exporter->getActivitiesData();
                $headers = ['ID', 'Name', 'Category', 'Description', 'Price', 'Price Unit', 'Price Note', 'Status', 'Featured', 'Created Date'];
                break;
            default:
                echo json_encode(['error' => 'Invalid report type']);
                exit();
        }

        if (!is_array($data)) { $data = []; }

        echo json_encode(['headers' => $headers, 'data' => $data]);
        exit();

    } catch (Exception $e) {
        error_log("Preview error: " . $e->getMessage());
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// ============================================================
// Handle export requests
// ============================================================
if(isset($_GET['export']) && ($is_admin || $is_staff)) {
    $exporter = new ReportExporter();
    $export_type = $_GET['export'];
    $filters = buildReportFilters();

    switch($export_type) {
        case 'bookings': $exporter->exportBookings($filters); break;
        case 'revenue':  $exporter->exportRevenue($filters);  break;
        case 'users':    $exporter->exportUsers($filters);    break;
        case 'houses':   $exporter->exportHouses();           break;
        case 'tours':    $exporter->exportTours();            break;
        case 'food':     $exporter->exportFood();             break;
        case 'feedback': $exporter->exportFeedback($filters); break;
        case 'activities': $exporter->exportActivities();     break;
        default:
            $_SESSION['report_error'] = 'Invalid export type';
            header("Location: reports.php");
            exit();
    }
    exit();
}

// ============================================================
// Initial page load stats (server-side render)
// ============================================================
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-t');
$range_type = isset($_GET['range_type']) ? $_GET['range_type'] : 'month';
$selected_report = isset($_GET['report']) ? $_GET['report'] : 'bookings';

$date_to_full = $date_to . ' 23:59:59';

$total_bookings = 0; $total_revenue = 0; $total_users = 0; $total_feedback = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM house_bookings WHERE booking_status != 'cancelled' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$date_from, $date_to_full]);
    $total_bookings += (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings WHERE booking_status != 'cancelled' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$date_from, $date_to_full]);
    $total_bookings += (int)$stmt->fetchColumn();

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM food_bookings WHERE booking_status != 'cancelled' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$date_from, $date_to_full]);
        $total_bookings += (int)$stmt->fetchColumn();
    } catch(PDOException $e) {}
} catch(PDOException $e) {}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM house_bookings WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$date_from, $date_to_full]);
    $total_revenue += (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM tour_bookings WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$date_from, $date_to_full]);
    $total_revenue += (float)$stmt->fetchColumn();

    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM food_bookings WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$date_from, $date_to_full]);
        $total_revenue += (float)$stmt->fetchColumn();
    } catch(PDOException $e) {}
} catch(PDOException $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$date_from, $date_to_full]);
    $total_users = (int)$stmt->fetchColumn();
} catch(PDOException $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM overall_feedback WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$date_from, $date_to_full]);
    $total_feedback = (int)$stmt->fetchColumn();
} catch(PDOException $e) {}

// Logout fallback
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

// Get dynamic content
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) { $content = []; }

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';
$site_tagline = $content['site_settings']['site_tagline'] ?? 'Your Home Away From Home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reports - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }
        .app-container { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar {
            width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2);
            padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto;
            border-right: 2px solid rgba(77, 166, 217, 0.15);
            transition: transform 0.3s ease, width 0.3s ease;
            z-index: 100; flex-shrink: 0;
        }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 0; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); overflow: hidden; }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; letter-spacing: 0.3px; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; transition: all 0.2s; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }
        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; position: relative; }
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
        .menu-toggle .fa-bars { transition: transform 0.3s ease; }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8); }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); width: 280px; z-index: 1000; box-shadow: none; border-radius: 0; padding-top: 25px; }
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

        /* MAIN CONTENT */
        .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; transition: padding 0.3s ease; }

        /* TOP BAR */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(11, 36, 71, 0.1);
            flex-wrap: wrap;
            gap: 10px;
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

        /* PAGE TITLE BANNER */
        .page-title-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15); position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); }
        .page-title-banner::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); animation: rotate 20s linear infinite; }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .page-title-banner .banner-content { position: relative; z-index: 1; }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner h1 i { margin-right: 10px; opacity: 0.9; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }
        .page-title-banner p .staff-notice { display: inline-block; background: rgba(251, 191, 36, 0.2); color: #fbbf24; padding: 2px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; border: 1px solid rgba(251, 191, 36, 0.2); margin-top: 5px; }
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

        /* STATS GRID */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; transition: opacity 0.3s ease; }
        .stats-grid.loading { opacity: 0.5; pointer-events: none; }
        .stat-card { background: #4DA6D9; border-radius: 16px; padding: 22px 20px; transition: transform 0.3s, box-shadow 0.3s; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2); color: white; display: block; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(77, 166, 217, 0.3); }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .stat-icon { width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.1); }
        .stat-number { font-size: 28px; font-weight: 700; color: white; margin-top: 10px; transition: opacity 0.3s; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 13px; font-weight: 500; margin-top: 2px; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; border-radius: 12px; }
            .stat-number { font-size: 22px; }
            .stat-icon { width: 40px; height: 40px; font-size: 16px; }
            .stat-label { font-size: 11px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 12px 10px; border-radius: 10px; }
            .stat-number { font-size: 18px; margin-top: 6px; }
            .stat-icon { width: 32px; height: 32px; font-size: 14px; border-radius: 8px; }
            .stat-label { font-size: 10px; }
        }

        /* FILTER BAR */
        .filter-bar { background: white; border-radius: 16px; padding: 15px 20px; margin-bottom: 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); border: 1px solid #e8f0fe; }
        .filter-bar .filter-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .filter-bar label { font-weight: 600; color: #0B2447; font-size: 13px; }
        .filter-bar .range-select { padding: 8px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 13px; background: #fafafa; transition: border-color 0.3s; }
        .filter-bar .range-select:focus { outline: none; border-color: #4DA6D9; background: white; }
        .filter-bar input[type="date"] { padding: 8px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 13px; background: #fafafa; transition: border-color 0.3s; }
        .filter-bar input[type="date"]:focus { outline: none; border-color: #4DA6D9; background: white; }
        .filter-bar .btn-filter { padding: 8px 20px; background: #4DA6D9; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.3s; }
        .filter-bar .btn-filter:hover { background: #3a8bbf; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(77, 166, 217, 0.3); }
        .filter-bar .btn-clear { padding: 8px 15px; background: #64748b; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.3s; text-decoration: none; }
        .filter-bar .btn-clear:hover { background: #475569; transform: translateY(-2px); }
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; padding: 15px; }
            .filter-bar input[type="date"] { flex: 1; min-width: 120px; }
        }

        /* REPORT TABS — MOBILE COLLAPSIBLE */
        .report-tabs-wrapper { margin-bottom: 20px; }

        .report-tabs-toggle {
            display: none;
            width: 100%;
            background: white;
            border: 2px solid #e8f0fe;
            border-radius: 12px;
            padding: 14px 18px;
            font-weight: 600;
            font-size: 14px;
            color: #0B2447;
            cursor: pointer;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s;
            margin-bottom: 10px;
        }
        .report-tabs-toggle:hover {
            border-color: #4DA6D9;
            background: #f0f7fb;
        }
        .report-tabs-toggle .toggle-label {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .report-tabs-toggle .toggle-label i {
            color: #4DA6D9;
        }
        .report-tabs-toggle .toggle-arrow {
            transition: transform 0.3s ease;
            color: #94a3b8;
        }
        .report-tabs-toggle.open .toggle-arrow {
            transform: rotate(180deg);
        }

        .report-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .report-tab {
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s;
            background: white;
            border: 2px solid #e8f0fe;
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .report-tab:hover { background: #f0f7fb; border-color: #4DA6D9; color: #0B2447; }
        .report-tab.active { background: #4DA6D9; border-color: #4DA6D9; color: white; }

        @media (max-width: 768px) {
            .report-tabs-toggle {
                display: flex;
            }
            .report-tabs {
                display: none;
                flex-direction: column;
                gap: 6px;
                background: white;
                border-radius: 12px;
                padding: 10px;
                border: 1px solid #e8f0fe;
                box-shadow: 0 4px 12px rgba(0,0,0,0.05);
                max-height: 60vh;
                overflow-y: auto;
            }
            .report-tabs.open {
                display: flex;
            }
            .report-tab {
                width: 100%;
                justify-content: flex-start;
                padding: 12px 16px;
                font-size: 13px;
                border-radius: 8px;
            }
            .report-tab.active {
                background: #4DA6D9;
                color: white;
            }
        }

        /* PREVIEW TABLE */
        .preview-container { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); border: 1px solid #e8f0fe; margin-bottom: 20px; overflow: hidden; }
        .preview-container .preview-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 10px; }
        .preview-container .preview-header h3 { font-size: 18px; font-weight: 700; color: #0B2447; margin: 0; }
        .preview-container .preview-header h3 i { color: #4DA6D9; margin-right: 8px; }
        .preview-container .preview-header .preview-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .preview-container .preview-header .preview-actions .btn-preview-action { padding: 8px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .preview-container .preview-header .preview-actions .btn-preview-action:hover { transform: translateY(-2px); }
        .preview-container .preview-header .preview-actions .btn-export-preview { background: #10b981; color: white; }
        .preview-container .preview-header .preview-actions .btn-export-preview:hover { background: #059669; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .preview-container .preview-header .preview-actions .btn-print-preview { background: #6366f1; color: white; }
        .preview-container .preview-header .preview-actions .btn-print-preview:hover { background: #4f46e5; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); }
        .preview-container .preview-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 10px 15px; background: #f8fafc; border-radius: 8px; font-size: 13px; color: #64748b; flex-wrap: wrap; gap: 10px; }
        .preview-container .preview-info .record-count { font-weight: 600; color: #0B2447; }
        .preview-container .preview-info .record-count span { color: #4DA6D9; font-size: 18px; }
        .preview-table-wrapper { overflow-x: auto; max-height: 500px; overflow-y: auto; }
        .preview-table-wrapper table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 600px; }
        .preview-table-wrapper table thead { position: sticky; top: 0; z-index: 10; }
        .preview-table-wrapper table thead th { background: #0B2447; color: white; padding: 10px 12px; text-align: left; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .preview-table-wrapper table tbody td { padding: 8px 12px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .preview-table-wrapper table tbody tr:hover { background: #f8fafc; }
        .preview-table-wrapper table tbody tr:last-child td { border-bottom: none; }
        .preview-table-wrapper .loading-row td, .preview-table-wrapper .no-data td { text-align: center; padding: 40px; color: #94a3b8; }
        @media (max-width: 768px) {
            .preview-container .preview-header { flex-direction: column; align-items: stretch; }
            .preview-container .preview-header .preview-actions { justify-content: stretch; }
            .preview-container .preview-header .preview-actions .btn-preview-action { flex: 1; justify-content: center; }
            .preview-container .preview-info { flex-direction: column; text-align: center; }
            .preview-table-wrapper table { font-size: 11px; min-width: 500px; }
            .preview-table-wrapper table thead th { font-size: 10px; padding: 6px 8px; }
            .preview-table-wrapper table tbody td { font-size: 10px; padding: 6px 8px; }
        }
        @media (max-width: 480px) {
            .preview-table-wrapper table { font-size: 10px; min-width: 400px; }
            .preview-table-wrapper table thead th { font-size: 9px; padding: 4px 6px; }
            .preview-table-wrapper table tbody td { font-size: 9px; padding: 4px 6px; }
        }

        /* SPINNER */
        .spinner-container { text-align: center; padding: 40px; }
        .spinner-container .spinner { width: 40px; height: 40px; border: 4px solid #e8f0fe; border-top-color: #4DA6D9; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 10px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner-container p { color: #94a3b8; font-size: 14px; }

        /* ALERTS */
        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }
        .alert i { font-size: 18px; }

        /* FOOTER */
        .footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15); }
        .footer i { color: #4DA6D9; }
        @media (max-width: 768px) {
            .footer { font-size: 11px; padding: 12px 10px; border-radius: 10px; margin-top: 20px; }
        }
        @media (max-width: 480px) {
            .footer { font-size: 10px; padding: 10px 8px; border-radius: 8px; margin-top: 15px; }
        }

        /* LOGOUT MODAL */
        .logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; animation: logoutFadeIn 0.2s ease; overscroll-behavior: contain; }
        .logout-modal-overlay.show { display: flex; }
        @keyframes logoutFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); animation: logoutSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border-top: 6px solid #ef4444; max-height: 90vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        @keyframes logoutSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; animation: logoutPulse 2s ease-in-out infinite; }
        @keyframes logoutPulse { 0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); } 50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); } }
        .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .logout-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.25s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; -webkit-tap-highlight-color: rgba(0,0,0,0.1); touch-action: manipulation; }
        .btn-logout-cancel { background: #e2e8f0; color: #475569; }
        .btn-logout-cancel:hover, .btn-logout-cancel:active { background: #cbd5e1; transform: translateY(-2px); }
        .btn-logout-confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3); }
        .btn-logout-confirm:hover, .btn-logout-confirm:active { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45); color: white; }
        @media (max-width: 480px) {
            .logout-modal { padding: 28px 22px 20px; border-radius: 20px; }
            .logout-modal-icon { width: 65px; height: 65px; font-size: 28px; margin-bottom: 14px; }
            .logout-modal h3 { font-size: 19px; }
            .logout-modal p { font-size: 13px; margin-bottom: 20px; }
            .logout-modal-actions { flex-direction: column-reverse; }
            .btn-logout-cancel, .btn-logout-confirm { width: 100%; }
        }

        /* PRINT STYLES */
        @media print {
            @page { size: A4 portrait; margin: 8mm 6mm; }
            body * { visibility: hidden; }
            #printArea, #printArea * { visibility: visible; }
            #printArea { position: absolute; top: 0; left: 0; width: 100%; padding: 0; background: white; font-family: 'Inter', Arial, sans-serif; }
            .sidebar, .top-bar, .menu-toggle, .sidebar-overlay, .filter-bar, .report-tabs-wrapper, .stats-grid, .page-title-banner, .footer, .preview-container { display: none !important; }
            .main-content { padding: 0 !important; margin: 0 !important; }
            .print-header { display: flex !important; justify-content: space-between !important; align-items: flex-start !important; margin-bottom: 10px !important; padding-bottom: 8px !important; border-bottom: 2px solid #0B2447 !important; }
            .print-header .print-logo { display: flex !important; align-items: center !important; gap: 10px !important; }
            .print-header .print-logo img { max-height: 45px !important; }
            .print-header .print-logo h2 { font-size: 15px !important; margin: 0 !important; }
            .print-header .print-logo p { font-size: 10px !important; margin: 0 !important; }
            .print-header .print-title { text-align: right !important; }
            .print-header .print-title h2 { font-size: 16px !important; font-weight: 700 !important; color: #0B2447 !important; margin: 0 !important; }
            .print-header .print-title p { font-size: 9px !important; color: #64748b !important; margin: 1px 0 0 !important; }
            #printTableContent { width: 100% !important; overflow: visible !important; }
            #printTableContent table { width: 100% !important; border-collapse: collapse !important; font-size: 8pt !important; table-layout: fixed !important; page-break-inside: auto !important; }
            #printTableContent table thead { display: table-header-group !important; }
            #printTableContent table thead th { background: #0B2447 !important; color: white !important; padding: 4px 3px !important; text-align: left !important; font-weight: 600 !important; font-size: 6.5pt !important; text-transform: uppercase !important; letter-spacing: 0.1px !important; border: 1px solid #1e3a5f !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; white-space: normal !important; word-break: break-word !important; line-height: 1.1 !important; vertical-align: middle !important; }
            #printTableContent table tbody td { padding: 3px 3px !important; border: 1px solid #d1dbe5 !important; color: #1e293b !important; font-size: 6.5pt !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; line-height: 1.15 !important; vertical-align: top !important; }
            #printTableContent table tbody tr:nth-child(even) { background: #f8fafc !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            #printTableContent table tbody tr { page-break-inside: avoid !important; }
            .print-footer { display: block !important; margin-top: 10px !important; padding-top: 6px !important; border-top: 1px solid #cbd5e1 !important; text-align: center !important; font-size: 8pt !important; color: #94a3b8 !important; }
            .print-footer p { margin: 2px 0 !important; }
        }
        .print-header { display: none; }
        .print-footer { display: none; }
    </style>
</head>
<body>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Hamburger Menu -->
<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="app-container">

    <!-- Sidebar -->
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

            <!-- FOOD — walang badge (tulad ng activities) -->
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

            <li class="nav-item"><a href="reports.php" class="nav-link active"><i class="fas fa-file-alt"></i><span>Reports</span></a></li>

            <?php if($is_admin): ?>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>

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

    <!-- Main Content -->
    <div class="main-content" id="mainContent">

        <!-- TOP BAR -->
        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-file-alt"></i> Reports

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
                <p>Export data and generate reports for analysis</p>
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

        <!-- Page Title Banner -->
        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-file-alt"></i> Reports & Exports</h1>
                <div class="underline"></div>
                <p>
                    Export data as CSV files for analysis and record-keeping
                    <?php if($is_staff): ?>
                        <br><span class="staff-notice"><i class="fas fa-user-tie"></i> Staff Access - Full Export Access</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="stat-number" id="statTotalBookings"><?php echo number_format($total_bookings); ?></div>
                <div class="stat-label">Total Bookings</div>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                </div>
                <div class="stat-number" id="statTotalRevenue">₱<?php echo number_format($total_revenue, 2); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-number" id="statTotalUsers"><?php echo number_format($total_users); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                </div>
                <div class="stat-number" id="statTotalReviews"><?php echo number_format($total_feedback); ?></div>
                <div class="stat-label">Total Reviews</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar" id="filterForm">
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Range:</label>
                <select name="range_type" id="range_type" class="range-select" onchange="updateDateRange()">
                    <option value="today" <?php echo $range_type == 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="yesterday" <?php echo $range_type == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                    <option value="week" <?php echo $range_type == 'week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="month" <?php echo $range_type == 'month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="year" <?php echo $range_type == 'year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="custom" <?php echo $range_type == 'custom' ? 'selected' : ''; ?>>Custom</option>
                </select>
            </div>
            <div class="filter-group" id="dateRangeGroup">
                <label>From:</label>
                <input type="date" name="date_from" id="date_from" value="<?php echo $date_from; ?>">
                <label>To:</label>
                <input type="date" name="date_to" id="date_to" value="<?php echo $date_to; ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-sync"></i> Apply</button>
            <a href="reports.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
        </form>

        <!-- REPORT TABS — Mobile Collapsible -->
        <div class="report-tabs-wrapper">
            <button type="button" class="report-tabs-toggle" id="reportTabsToggle" onclick="toggleReportTabs()">
                <span class="toggle-label">
                    <i class="fas fa-list"></i>
                    Select Report Type
                </span>
                <span class="toggle-arrow"><i class="fas fa-chevron-down"></i></span>
            </button>

            <div class="report-tabs" id="reportTabs">
                <a href="#" data-report="bookings" class="report-tab <?php echo $selected_report == 'bookings' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i> Bookings
                </a>
                <a href="#" data-report="revenue" class="report-tab <?php echo $selected_report == 'revenue' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i> Revenue
                </a>
                <a href="#" data-report="users" class="report-tab <?php echo $selected_report == 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Users
                </a>
                <a href="#" data-report="houses" class="report-tab <?php echo $selected_report == 'houses' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Houses
                </a>
                <a href="#" data-report="tours" class="report-tab <?php echo $selected_report == 'tours' ? 'active' : ''; ?>">
                    <i class="fas fa-umbrella-beach"></i> Tours
                </a>
                <a href="#" data-report="food" class="report-tab <?php echo $selected_report == 'food' ? 'active' : ''; ?>">
                    <i class="fas fa-utensils"></i> Food
                </a>
                <a href="#" data-report="activities" class="report-tab <?php echo $selected_report == 'activities' ? 'active' : ''; ?>">
                    <i class="fas fa-water"></i> Activities
                </a>
                <a href="#" data-report="feedback" class="report-tab <?php echo $selected_report == 'feedback' ? 'active' : ''; ?>">
                    <i class="fas fa-star"></i> Feedback
                </a>
            </div>
        </div>

        <!-- Preview Container -->
        <div class="preview-container" id="previewContainer">
            <div class="preview-header">
                <h3><i class="fas fa-table"></i> <span id="reportTitle"><?php echo ucfirst($selected_report); ?> Report</span></h3>
                <div class="preview-actions">
                    <button class="btn-preview-action btn-export-preview" onclick="exportReport()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button class="btn-preview-action btn-print-preview" onclick="printReport()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            <div class="preview-info">
                <span><i class="fas fa-info-circle"></i> Showing <strong class="record-count"><span id="recordCount">0</span></strong> record(s)</span>
                <span><i class="fas fa-calendar-alt"></i> <span id="reportDateRange"><?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></span></span>
            </div>
            <div class="preview-table-wrapper" id="previewTableWrapper">
                <div class="spinner-container" id="loadingSpinner">
                    <div class="spinner"></div>
                    <p>Loading report data...</p>
                </div>
                <div id="previewTableContent" style="display: none;"></div>
            </div>
        </div>

        <!-- Print Area (Hidden) -->
        <div id="printArea" style="display: none;">
            <div class="print-header">
                <div class="print-logo">
                    <?php if($logo_exists): ?>
                        <img src="<?php echo $logo_path; ?>?<?php echo time(); ?>" alt="Logo">
                    <?php endif; ?>
                    <div>
                        <h2 style="margin:0;font-size:20px;font-weight:700;color:#0B2447;">Transient House & Tours</h2>
                        <p style="margin:0;font-size:12px;color:#64748b;">Hundred Islands Reservation System</p>
                    </div>
                </div>
                <div class="print-title">
                    <h2 id="printReportTitle">Report</h2>
                    <p>Date Generated: <?php echo date('F d, Y h:i A'); ?></p>
                    <p>Period: <span id="printDateRange"></span></p>
                </div>
            </div>
            <div id="printTableContent"></div>
            <div class="print-footer">
                <p>This is a computer-generated report. For inquiries, please contact the administrator.</p>
                <p>&copy; <?php echo date('Y'); ?> Transient House & Tours. All rights reserved.</p>
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

<!-- Logout Modal -->
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
// STATE
// ============================================================
let currentReportType = '<?php echo $selected_report; ?>';
let currentDateFrom = '<?php echo $date_from; ?>';
let currentDateTo   = '<?php echo $date_to; ?>';

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
    }
});

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 1024 && sidebar.classList.contains('open')) {
        toggleSidebar();
    }
});

// ============================================================
// LOGOUT MODAL
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
// MOBILE: TOGGLE REPORT TABS
// ============================================================
function toggleReportTabs() {
    const tabs = document.getElementById('reportTabs');
    const toggle = document.getElementById('reportTabsToggle');
    tabs.classList.toggle('open');
    toggle.classList.toggle('open');
}

// Auto-close tabs after selecting a report on mobile
function closeReportTabsOnMobile() {
    if (window.innerWidth <= 768) {
        const tabs = document.getElementById('reportTabs');
        const toggle = document.getElementById('reportTabsToggle');
        tabs.classList.remove('open');
        toggle.classList.remove('open');
    }
}

// ============================================================
// DATE RANGE
// ============================================================
function updateDateRange() {
    const rangeType = document.getElementById('range_type').value;
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');
    const today = new Date();

    let from = new Date();
    let to = new Date();

    switch(rangeType) {
        case 'today':
            from = new Date(today.getFullYear(), today.getMonth(), today.getDate());
            to = new Date(today.getFullYear(), today.getMonth(), today.getDate());
            break;
        case 'yesterday':
            from = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
            to = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
            break;
        case 'week':
            const dayOfWeek = today.getDay();
            const diff = today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1);
            from = new Date(today.getFullYear(), today.getMonth(), diff);
            to = new Date(today.getFullYear(), today.getMonth(), diff + 6);
            break;
        case 'month':
            from = new Date(today.getFullYear(), today.getMonth(), 1);
            to = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'year':
            from = new Date(today.getFullYear(), 0, 1);
            to = new Date(today.getFullYear(), 11, 31);
            break;
        case 'custom':
        default:
            return;
    }

    dateFrom.value = formatDate(from);
    dateTo.value = formatDate(to);
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function formatDateDisplay(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[parseInt(parts[1]) - 1] + ' ' + parseInt(parts[2]) + ', ' + parts[0];
}

// ============================================================
// DYNAMIC STATS — update when date range changes
// ============================================================
function loadStats() {
    const grid = document.getElementById('statsGrid');
    grid.classList.add('loading');

    const url = 'reports.php?get_stats=1'
              + '&date_from=' + encodeURIComponent(currentDateFrom)
              + '&date_to='   + encodeURIComponent(currentDateTo);

    fetch(url)
        .then(response => response.json())
        .then(data => {
            grid.classList.remove('loading');
            if (data.success) {
                document.getElementById('statTotalBookings').textContent =
                    Number(data.stats.total_bookings).toLocaleString();
                document.getElementById('statTotalRevenue').textContent =
                    '₱' + Number(data.stats.total_revenue).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('statTotalUsers').textContent =
                    Number(data.stats.total_users).toLocaleString();
                document.getElementById('statTotalReviews').textContent =
                    Number(data.stats.total_reviews).toLocaleString();
            }
        })
        .catch(err => {
            grid.classList.remove('loading');
            console.error('Stats load error:', err);
        });
}

// ============================================================
// LOAD REPORT PREVIEW (no page reload)
// ============================================================
function loadReportPreview() {
    const spinner = document.getElementById('loadingSpinner');
    const content = document.getElementById('previewTableContent');
    const recordCount = document.getElementById('recordCount');

    spinner.style.display = 'block';
    content.style.display = 'none';
    content.innerHTML = '';
    recordCount.textContent = 'Loading...';

    document.getElementById('reportTitle').textContent = capitalize(currentReportType) + ' Report';
    document.getElementById('reportDateRange').textContent = formatDateDisplay(currentDateFrom) + ' - ' + formatDateDisplay(currentDateTo);
    document.getElementById('printDateRange').textContent = formatDateDisplay(currentDateFrom) + ' - ' + formatDateDisplay(currentDateTo);

    let url = 'reports.php?preview=' + encodeURIComponent(currentReportType);
    if (currentDateFrom) url += '&date_from=' + encodeURIComponent(currentDateFrom);
    if (currentDateTo) url += '&date_to=' + encodeURIComponent(currentDateTo);

    const params = new URLSearchParams(window.location.search);
    if (params.get('status')) url += '&status=' + encodeURIComponent(params.get('status'));
    if (params.get('payment')) url += '&payment=' + encodeURIComponent(params.get('payment'));
    if (params.get('rating')) url += '&rating=' + encodeURIComponent(params.get('rating'));
    if (params.get('role')) url += '&role=' + encodeURIComponent(params.get('role'));

    fetch(url)
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error('Server returned: ' + text.substring(0, 200));
                });
            }
            return response.json();
        })
        .then(data => {
            spinner.style.display = 'none';

            if (data.error) {
                content.innerHTML = '<div class="no-data"><i class="fas fa-exclamation-circle" style="font-size: 30px; display: block; margin-bottom: 10px; color: #ef4444;"></i>Error: ' + escapeHtml(data.error) + '</div>';
                content.style.display = 'block';
                recordCount.textContent = '0';
                return;
            }

            if (!data.data || data.data.length === 0) {
                content.innerHTML = '<div class="no-data"><i class="fas fa-inbox" style="font-size: 30px; display: block; margin-bottom: 10px; color: #cbd5e1;"></i>No data found for the selected period.</div>';
                content.style.display = 'block';
                recordCount.textContent = '0';
                return;
            }

            let html = '<table><thead><tr>';
            data.headers.forEach(header => { html += '<th>' + escapeHtml(header) + '</th>'; });
            html += '</tr></thead><tbody>';

            data.data.forEach((row) => {
                html += '<tr>';
                if (Array.isArray(row)) {
                    row.forEach(cell => {
                        let displayValue = (cell === null || cell === undefined || cell === '') ? '-' : cell;
                        html += '<td>' + escapeHtml(String(displayValue)) + '</td>';
                    });
                } else {
                    data.headers.forEach(header => {
                        let value = row[header];
                        if (value === null || value === undefined || value === '') value = '-';
                        html += '<td>' + escapeHtml(String(value)) + '</td>';
                    });
                }
                html += '</tr>';
            });

            html += '</tbody></table>';
            content.innerHTML = html;
            content.style.display = 'block';
            recordCount.textContent = data.data.length;
        })
        .catch(error => {
            spinner.style.display = 'none';
            content.innerHTML = '<div class="no-data"><i class="fas fa-exclamation-circle" style="font-size: 30px; display: block; margin-bottom: 10px; color: #ef4444;"></i>Failed to load report data.<br><small style="color: #94a3b8;">' + escapeHtml(error.message) + '</small></div>';
            content.style.display = 'block';
            recordCount.textContent = '0';
            console.error('Error loading report:', error);
        });
}

function capitalize(s) {
    if (!s) return '';
    return s.charAt(0).toUpperCase() + s.slice(1);
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ============================================================
// REPORT TAB CLICKS (no scroll, no reload)
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.report-tab');

    tabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();

            const newReport = this.dataset.report;
            if (!newReport) return;

            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            currentReportType = newReport;

            const url = new URL(window.location.href);
            url.searchParams.set('report', newReport);
            url.searchParams.set('date_from', currentDateFrom);
            url.searchParams.set('date_to', currentDateTo);
            if (window.history.replaceState) {
                window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString());
            }

            closeReportTabsOnMobile();
            loadReportPreview();
        });
    });

    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            currentDateFrom = document.getElementById('date_from').value;
            currentDateTo = document.getElementById('date_to').value;

            const url = new URL(window.location.href);
            url.searchParams.set('date_from', currentDateFrom);
            url.searchParams.set('date_to', currentDateTo);
            url.searchParams.set('range_type', document.getElementById('range_type').value);
            if (window.history.replaceState) {
                window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString());
            }

            loadStats();
            loadReportPreview();
        });
    }
});

// ============================================================
// EXPORT REPORT
// ============================================================
function exportReport() {
    let url = 'reports.php?export=' + encodeURIComponent(currentReportType);
    if (currentDateFrom) url += '&date_from=' + encodeURIComponent(currentDateFrom);
    if (currentDateTo) url += '&date_to=' + encodeURIComponent(currentDateTo);
    window.location.href = url;
}

// ============================================================
// PRINT REPORT
// ============================================================
function printReport() {
    const printArea = document.getElementById('printArea');
    const tableContent = document.getElementById('previewTableContent');
    const printTableContent = document.getElementById('printTableContent');
    const reportTitle = document.getElementById('reportTitle').textContent;

    printTableContent.innerHTML = tableContent.innerHTML;
    document.getElementById('printReportTitle').textContent = reportTitle;
    document.getElementById('printDateRange').textContent = formatDateDisplay(currentDateFrom) + ' - ' + formatDateDisplay(currentDateTo);

    printArea.style.display = 'block';
    window.print();

    setTimeout(() => { printArea.style.display = 'none'; }, 1000);
}

// ============================================================
// INITIAL LOAD
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    loadReportPreview();
});

// Auto-hide alerts
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
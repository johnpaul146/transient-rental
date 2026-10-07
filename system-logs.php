<?php
session_start();
require_once 'database.php';
require_once 'includes/sidebar-counts.php';

require_once 'includes/auth.php';
requireAdmin();

$is_admin = true;
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch (PDOException $e) {}

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

// Filters
$search    = trim($_GET['search'] ?? '');
$module    = $_GET['module'] ?? 'all';
$action    = $_GET['action'] ?? 'all';
$status    = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 25;
$offset    = ($page - 1) * $per_page;

$where  = "WHERE DATE(created_at) BETWEEN ? AND ?";
$params = [$date_from, $date_to];
if ($module !== 'all') { $where .= " AND module = ?"; $params[] = $module; }
if ($action !== 'all') { $where .= " AND action = ?"; $params[] = $action; }
if ($status !== 'all') { $where .= " AND status = ?"; $params[] = $status; }
if ($search) {
    $where .= " AND (description LIKE ? OR username LIKE ? OR ip_address LIKE ? OR target_type LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
    $params[] = "%$search%"; $params[] = "%$search%";
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("SELECT * FROM system_logs $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'export', 'system', 'Exported system logs to CSV (' . count($rows) . ' records)');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="system_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID','Date/Time','User','Role','Action','Module','Description','Target','IP','Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['created_at'], $r['username'] ?? 'Guest',
            $r['role'] ?? '-', $r['action'], $r['module'], $r['description'],
            ($r['target_type'] ? $r['target_type'] . ' #' . $r['target_id'] : '-'),
            $r['ip_address'], $r['status']
        ]);
    }
    fclose($out);
    exit();
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs $where");
$countStmt->execute($params);
$total       = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

$stmt = $pdo->prepare("SELECT * FROM system_logs $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$stats = [
    'total'  => (int)$pdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn(),
    'today'  => (int)$pdo->query("SELECT COUNT(*) FROM system_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
    'failed' => (int)$pdo->query("SELECT COUNT(*) FROM system_logs WHERE status = 'failed'")->fetchColumn(),
    'week'   => (int)$pdo->query("SELECT COUNT(*) FROM system_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
];

$active_users_today = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM system_logs WHERE DATE(created_at) = CURDATE() AND user_id IS NOT NULL")->fetchColumn();

$top_modules = $pdo->query("
    SELECT module, COUNT(*) as cnt
    FROM system_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY module
    ORDER BY cnt DESC
    LIMIT 6
")->fetchAll();

$failed_logins_24h = (int)$pdo->query("SELECT COUNT(*) FROM system_logs WHERE action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$modules = $pdo->query("SELECT DISTINCT module FROM system_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = $pdo->query("SELECT DISTINCT action FROM system_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

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

// ============================================================
// ✅ SIDEBAR BADGE COUNTS — Booking, Reviews, System Logs
// ============================================================



function actionIcon($action) {
    $icons = [
        'create'          => ['fa-plus-circle', '#10b981'],
        'update'          => ['fa-edit',        '#3b82f6'],
        'delete'          => ['fa-trash',       '#ef4444'],
        'login'           => ['fa-sign-in-alt', '#10b981'],
        'login_failed'    => ['fa-exclamation-triangle', '#ef4444'],
        'logout'          => ['fa-sign-out-alt','#64748b'],
        'register'        => ['fa-user-plus',   '#8b5cf6'],
        'verify_device'   => ['fa-shield-alt',  '#10b981'],
        'confirm_payment' => ['fa-check-circle','#10b981'],
        'reject_payment'  => ['fa-times-circle','#ef4444'],
        'confirm_rebook'  => ['fa-redo',        '#10b981'],
        'rebook'          => ['fa-redo',        '#f59e0b'],
        'cancel_rebook'   => ['fa-undo',        '#f59e0b'],
        'upload_proof'    => ['fa-upload',      '#0ea5e9'],
        'export'          => ['fa-file-csv',    '#10b981'],
    ];
    return $icons[$action] ?? ['fa-circle', '#94a3b8'];
}
function statusBadge($status) {
    if ($status === 'success') return '<span class="badge badge-success"><i class="fas fa-check"></i> Success</span>';
    if ($status === 'failed')  return '<span class="badge badge-danger"><i class="fas fa-times"></i> Failed</span>';
    return '<span class="badge badge-warning"><i class="fas fa-exclamation"></i> Warning</span>';
}
function roleBadge($role) {
    if ($role === 'admin') return '<span class="badge badge-role-admin">Admin</span>';
    if ($role === 'staff') return '<span class="badge badge-role-staff">Staff</span>';
    if ($role === 'guest') return '<span class="badge badge-role-guest">Guest</span>';
    return '<span class="badge badge-info">System</span>';
}
function moduleColor($m) {
    $map = [
        'auth' => '#8b5cf6', 'house' => '#10b981', 'tour' => '#f59e0b',
        'activity' => '#06b6d4', 'food' => '#ef4444', 'booking' => '#4DA6D9',
        'review' => '#fbbf24', 'user' => '#ec4899', 'content' => '#64748b',
        'system' => '#0B2447',
    ];
    return $map[$m] ?? '#94a3b8';
}

// ---- Display-only helpers (business-friendly wording; no data is changed) ----
function logStripIp($t) {
    $t = preg_replace('/\s*[—–-]?\s*\(?\bIP:\s*[0-9a-fA-F:.]+\)?/u', '', (string)$t);
    return trim(preg_replace('/\s{2,}/', ' ', $t));
}
function friendlyAction($a) {
    $map = [
        'create' => 'Created', 'update' => 'Updated', 'delete' => 'Deleted', 'login' => 'Login',
        'login_failed' => 'Failed Login', 'logout' => 'Logout', 'register' => 'New Account',
        'verify_device' => 'Device Verification', 'confirm_payment' => 'Payment Confirmed',
        'reject_payment' => 'Payment Rejected', 'confirm_rebook' => 'Rebooking Approved',
        'rebook' => 'Rebooking Request', 'cancel_rebook' => 'Rebooking Cancelled',
        'upload_proof' => 'Payment Proof', 'export' => 'Export', 'view' => 'Page View', 'upload' => 'Upload',
        'unknown' => 'Other',
    ];
    return $map[$a] ?? ucwords(str_replace('_', ' ', (string)$a));
}
function friendlyArea($m) {
    $map = [
        'auth' => 'Login & Security', 'booking' => 'Bookings', 'house' => 'Houses', 'tour' => 'Tours',
        'activity' => 'Activities', 'food' => 'Food', 'review' => 'Reviews', 'user' => 'Accounts',
        'content' => 'Website Content', 'profile' => 'Profiles', 'system' => 'System',
        'blocked_dates' => 'Availability', 'package' => 'Packages',
    ];
    return $map[$m] ?? ucwords(str_replace('_', ' ', (string)$m));
}
function friendlyDescription($d, $action, $username) {
    $d = logStripIp($d);
    if (($username === null || $username === '') && preg_match('/^User \'([^\']+)\'/u', $d, $um)) $username = $um[1];
    $u = $username !== null && $username !== '' ? ucfirst($username) : 'The system';
    // Authentication wording
    if (preg_match('/logged in.*(trusted device)/iu', $d)) return "$u successfully logged in using a trusted device.";
    if (preg_match('/logged in from (a )?NEW device/iu', $d)) return "$u logged in from a new device. A verification code was sent.";
    if (preg_match('/verified a NEW device/iu', $d)) return "$u verified a new device and logged in.";
    if (preg_match('/new device verification.*(failed|incorrect)/iu', $d)) return 'New device verification failed because of an incorrect verification code.';
    if (preg_match('/^User \'([^\']*)\' logged in\b/iu', $d, $m)) return ucfirst($m[1]) . ' successfully logged in.';
    if (preg_match('/^User \'([^\']*)\' logged out\b/iu', $d, $m)) return ucfirst($m[1]) . ' logged out.';
    if (preg_match('/failed login attempt for username \'([^\']*)\'/iu', $d, $m)) return 'A login attempt for the account "' . $m[1] . '" failed because of incorrect login details.';
    if (preg_match('/^User \'([^\']*)\' (.+)$/u', $d, $m)) $d = ucfirst($m[1]) . ' ' . $m[2];
    $d = preg_replace('/^Admin \'([^\']*)\' /u', '$1 ', $d);
    $d = preg_replace('/\bOTP\b/u', 'verification code', $d);
    $d = preg_replace('/\s*\(payment carried forward, no new fee\)/u', '. The payment already made was carried forward.', $d);
    $d = preg_replace('/\bFAILED\b/u', 'failed', $d);
    $d = ucfirst($d);
    if (!preg_match('/[.!?"”)]$/u', $d)) $d .= '.';
    return $d;
}
function friendlyTarget($type, $id, $desc, $action) {
    $desc = (string)$desc;
    if (preg_match('/\b((?:HS|TOUR|FOOD|PKG|RE)-[A-Z0-9-]+)\b/', $desc, $m)) {
        $ref = $m[1];
        $label = ['HS' => 'House Booking', 'TOUR' => 'Tour Booking', 'FOOD' => 'Food Order', 'PKG' => 'Package Booking'][explode('-', $ref)[0]] ?? 'Booking';
        return "$label $ref";
    }
    switch ($type) {
        case 'user':
            if (in_array($action, ['login', 'login_failed', 'logout', 'verify_device'], true)) return $action === 'logout' ? 'Account Logout' : ($action === 'verify_device' ? 'Device Verification' : 'Account Login');
            if ($action === 'register') return 'New Account';
            return 'User Account';
        case 'house': return $id ? 'Unit ' . (int)$id : 'House';
        case 'blocked_dates':
            if (preg_match('/house #(\d+)/i', $desc, $m)) return 'Unit ' . $m[1] . ' availability';
            return 'Blocked dates';
        case 'booking': return 'Booking';
        case 'house_booking': return 'House Booking';
        case 'tour_booking': return 'Tour Booking';
        case 'food_booking': return 'Food Order';
        case 'package_booking': return 'Package Booking';
        case 'package': return 'Package cart';
        case 'activity': return preg_match('/activity:\s*([^(]+?)\s*(\(|$)/iu', $desc, $m) ? trim($m[1]) : 'Activity';
        case 'overall_feedback': return 'Guest review';
        case 'page': return 'Website page';
        case 'site_content': return 'Website content';
    }
    return $type ? ucwords(str_replace('_', ' ', $type)) : null;
}

$base_qs = $_GET;
unset($base_qs['page'], $base_qs['export']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>System Activity - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }
        .app-container { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77,166,217,0.15); flex-shrink: 0; z-index: 100; }
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
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; align-items: center; justify-content: center; flex-shrink: 0; }
        .sidebar-close-btn:hover { background: #ef4444; color: white; transform: rotate(90deg); }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; background: rgba(239,68,68,0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; }
        .nav-link:hover { background: rgba(77,166,217,0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77,166,217,0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active i { color: #7bb8f0; }
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
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); transition: transform 0.3s ease; width: 280px; z-index: 1000; }
            .sidebar.open { transform: translateX(0); box-shadow: 4px 0 30px rgba(0,0,0,0.4); }
            .menu-toggle { display: flex; }
            .sidebar-overlay.active { display: block; }
            .main-content { padding: 70px 16px 20px !important; }
            .sidebar-close-btn { display: flex; }
        }

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
                padding-left: 84px;
                padding-right: 84px;
                min-height: 110px;
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

        /* SECTION HEADER */
        .section-header { display: flex; align-items: flex-start; gap: 14px; margin: 0 0 18px; padding: 14px 18px; background: #fff; border: 1px solid #dce8f3; border-left: 4px solid #4DA6D9; border-radius: 14px; box-shadow: 0 2px 8px rgba(11,36,71,0.05); }
        .section-header .sh-icon { width: 40px; height: 40px; border-radius: 12px; background: #0B2447; color: #7bb8f0; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
        .section-header h2 { font-size: 18px; font-weight: 700; color: #0B2447; margin: 0; line-height: 1.25; }
        .section-header p { font-size: 13px; color: #4a6a8c; margin: 2px 0 0; line-height: 1.4; }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 18px; }
        .stat-card { position: relative; background: #fff; border: 1px solid #dce8f3; border-radius: 14px; padding: 18px 18px 14px; color: #0B2447; box-shadow: 0 2px 8px rgba(11,36,71,0.05); overflow: hidden; display: grid; grid-template-columns: auto minmax(0,1fr); column-gap: 14px; align-items: center; }
        .stat-card::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: #4DA6D9; }
        .stat-icon { grid-row: 1 / span 2; width: 46px; height: 46px; background: #eaf5fc; color: #2f8dc4; border: 1px solid #d5eaf7; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 19px; }
        .stat-number { color: #0B2447; font-size: 28px; line-height: 1.1; font-weight: 800; letter-spacing: -0.5px; grid-column: 2; }
        .stat-label { color: #163a63; font-size: 13px; font-weight: 600; grid-column: 2; }
        .stat-small { grid-column: 1 / -1; font-size: 11.5px; color: #5d7388; margin-top: 12px; padding-top: 10px; border-top: 1px solid #edf3f8; display: flex; align-items: center; gap: 6px; }
        .stat-small i { color: #4DA6D9; }
        .stat-card.danger::before { background: #ef4444; }
        .stat-card.danger .stat-icon { background: #fff1f2; color: #dc2626; border-color: #fecdd3; }
        .stat-card.danger .stat-small i { color: #ef4444; }
        @media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 768px) { .stats-grid { gap: 12px; } .stat-card { padding: 14px 14px 12px; column-gap: 12px; } .stat-icon { width: 40px; height: 40px; font-size: 16px; } .stat-number { font-size: 24px; } }
        @media (max-width: 520px) { .stats-grid { grid-template-columns: 1fr; } }

        /* DASHBOARD GRID */
        .dashboard-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 16px; margin-bottom: 18px; align-items: start; }
        .dashboard-grid > .card { margin-bottom: 0; }
        @media (max-width: 992px) { .dashboard-grid { grid-template-columns: minmax(0, 1fr); } }

        /* CARD */
        .card { background: #fff; border-radius: 14px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(11,36,71,0.05); border: 1px solid #dce8f3; margin-bottom: 18px; min-width: 0; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid #e8f0fe; flex-wrap: wrap; gap: 10px; }
        .card-header h2 { font-size: 16px; font-weight: 700; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
        .card-header h2 i { color: #2f8dc4; background: #eaf5fc; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 13px; }
        .card-header a { color: #1f78ad; text-decoration: none; font-weight: 600; font-size: 13px; }
        .card-header a:hover { text-decoration: underline; }
        @media (max-width: 768px) { .card { padding: 14px; } .card-header h2 { font-size: 15px; } }

        /* MODULE BREAKDOWN */
        .module-breakdown { display: flex; flex-direction: column; gap: 10px; }
        .module-row { display: grid; grid-template-columns: 10px minmax(70px, 110px) minmax(0, 1fr) 44px; align-items: center; gap: 10px; }
        .module-row .module-dot { width: 10px; height: 10px; border-radius: 50%; }
        .module-row .module-name { font-weight: 600; color: #1e293b; font-size: 13px; text-transform: capitalize; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .module-row .module-bar-wrap { height: 8px; background: #eef3f8; border-radius: 10px; overflow: hidden; }
        .module-row .module-bar { height: 100%; border-radius: 10px; }
        .module-row .module-count { font-weight: 700; color: #0B2447; font-size: 13px; text-align: right; font-variant-numeric: tabular-nums; }

        /* ALERT LIST */
        .alert-list { display: flex; flex-direction: column; gap: 8px; }
        .alert-item { display: grid; grid-template-columns: 30px minmax(0, 1fr) auto; align-items: center; gap: 10px; padding: 9px 12px; background: #f8fafc; border-radius: 10px; border-left: 3px solid; }
        .alert-item.warning { border-left-color: #f59e0b; background: #fffbeb; }
        .alert-item.danger { border-left-color: #ef4444; background: #fef2f2; }
        .alert-item .alert-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 13px; color: #fff; }
        .alert-item .alert-content { min-width: 0; }
        .alert-item .alert-title { font-weight: 600; color: #1e293b; font-size: 13px; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word; }
        .alert-item .alert-sub { font-size: 11.5px; color: #5b6b7e; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .alert-item .alert-sub i { font-size: 10px; opacity: .7; }
        .alert-item .alert-time { font-size: 11.5px; color: #5b6b7e; white-space: nowrap; text-align: right; font-variant-numeric: tabular-nums; }
        .no-alerts { text-align: center; padding: 22px 15px; color: #64748b; font-size: 13px; }
        .no-alerts i { font-size: 28px; color: #10b981; display: block; margin-bottom: 8px; }

        /* FILTER */
        .filter-bar { display: grid; grid-template-columns: minmax(0, 2fr) repeat(5, minmax(0, 1fr)); gap: 12px; align-items: end; margin-bottom: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .filter-group label { font-size: 11px; font-weight: 700; color: #4a6a8c; text-transform: uppercase; letter-spacing: 0.4px; }
        .filter-bar input, .filter-bar select { padding: 0 12px; height: 42px; border: 1px solid #c9d9e8; border-radius: 10px; font-size: 14px; color: #0B2447; background: #fff; width: 100%; min-width: 0; font-family: inherit; }
        .filter-bar input::placeholder { color: #7a8ca0; }
        .filter-bar input:hover, .filter-bar select:hover { border-color: #9fc3dd; }
        .filter-bar input:focus, .filter-bar select:focus { outline: 3px solid rgba(77,166,217,0.35); outline-offset: 0; border-color: #4DA6D9; }

        .filter-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #e8f0fe; align-items: center; }
        .btn-filter, .btn-clear, .btn-export { min-height: 42px; padding: 0 18px; border-radius: 10px; font-weight: 600; font-size: 14px; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; border: 1px solid transparent; transition: background .15s, border-color .15s; }
        .btn-filter { background: #2f8dc4; color: #fff; }
        .btn-filter:hover { background: #247aab; }
        .btn-clear { background: #fff; color: #334e68; border-color: #c9d9e8; }
        .btn-clear:hover { background: #f0f7fb; border-color: #9fc3dd; }
        .btn-export { background: #0f8a5f; color: #fff; margin-left: auto; }
        .btn-export:hover { background: #0b7350; color: #fff; }
        .btn-filter:focus-visible, .btn-clear:focus-visible, .btn-export:focus-visible, .pagination a:focus-visible { outline: 3px solid rgba(77,166,217,0.5); outline-offset: 2px; }

        @media (max-width: 1360px) { .filter-bar { grid-template-columns: repeat(3, minmax(0, 1fr)); } .filter-group:first-child { grid-column: 1 / -1; } }
        @media (max-width: 640px) { .filter-bar { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 480px) {
            .filter-bar { grid-template-columns: minmax(0, 1fr); }
            .filter-actions { display: grid; grid-template-columns: 1fr 1fr; }
            .btn-filter, .btn-export { grid-column: 1 / -1; }
            .btn-export { margin-left: 0; }
        }

        /* TABLE (desktop) */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #dce8f3; }
        table { width: 100%; border-collapse: collapse; }
        thead th { text-align: left; padding: 12px 10px; background: #0B2447; color: #e6f2fb; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        tbody td { padding: 12px 10px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; vertical-align: middle; }
        tbody tr:nth-child(even) td { background: #fafcfe; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #eef6fc; }
        tbody td strong { color: #0B2447; font-weight: 600; }
        .log-icon { display: inline-flex; width: 34px; height: 34px; border-radius: 10px; align-items: center; justify-content: center; color: #fff; font-size: 14px; flex-shrink: 0; }
        .log-desc { min-width: 180px; max-width: 340px; overflow-wrap: anywhere; line-height: 1.45; color: #0B2447; font-weight: 500; }
        .log-meta { font-size: 11.5px; color: #64748b; margin-top: 3px; text-transform: capitalize; }
        .log-date { font-weight: 600; color: #0B2447; font-size: 13px; white-space: nowrap; }
        .log-time { font-size: 12px; color: #5b6b7e; margin-top: 2px; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .log-ip { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; color: #64748b; background: #f3f6f9; padding: 2px 6px; border-radius: 6px; white-space: nowrap; }
        .no-target { color: #94a3b8; }

        /* MOBILE LOG CARDS (phones only; table is used on tablet and desktop) */
        .logs-cards-mobile { display: none; }
        @media (min-width: 769px) { .logs-cards-mobile { display: none !important; } }
        @media (max-width: 768px) {
            .table-responsive.desktop-table { display: none !important; }
            .logs-cards-mobile { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 330px), 1fr)); gap: 12px; }
        }
        /* Compact table for tablet / small laptop */
        @media (max-width: 1280px) {
            thead th { padding: 10px 8px; font-size: 10.5px; letter-spacing: .3px; }
            tbody td { padding: 10px 8px; font-size: 12.5px; }
            .act-cell { flex-direction: column; align-items: flex-start; gap: 4px; }
            .act-name { font-size: 11.5px; }
            .log-desc { min-width: 150px; max-width: 260px; }
            .badge { font-size: 9.5px; padding: 3px 8px; white-space: normal; }
            .log-icon { width: 30px; height: 30px; font-size: 12px; }
            .btn-details { width: 34px; height: 34px; }
        }
        @media (max-width: 1100px) { .table-responsive.desktop-table table { min-width: 700px; } }
        .log-card-mobile { background: #fff; border-radius: 12px; padding: 12px 14px; border: 1px solid #dce8f3; box-shadow: 0 1px 4px rgba(11,36,71,0.05); display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        .log-card-mobile .card-top-row { display: flex; gap: 10px; }
        .log-card-mobile .card-icon-wrap { display: flex; align-items: flex-start; gap: 10px; flex: 1; min-width: 0; }
        .log-card-mobile .card-icon-wrap .log-icon { width: 34px; height: 34px; font-size: 14px; }
        .log-card-mobile .card-action-label { font-size: 11px; font-weight: 700; color: #4a6a8c; text-transform: uppercase; letter-spacing: 0.4px; }
        .log-card-mobile .card-desc { font-weight: 600; color: #0B2447; font-size: 13px; line-height: 1.4; overflow-wrap: anywhere; }
        .log-card-mobile .card-badges { display: flex; gap: 6px; flex-wrap: wrap; }
        .log-card-mobile .card-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #475569; }
        .log-card-mobile .card-row i { width: 14px; color: #4DA6D9; flex-shrink: 0; text-align: center; font-size: 11px; }
        .log-card-mobile .card-row .card-label { color: #5b6b7e; font-size: 10.5px; font-weight: 700; min-width: 44px; text-transform: uppercase; letter-spacing: 0.3px; }
        .log-card-mobile .card-row .card-value { font-weight: 600; color: #0B2447; overflow-wrap: anywhere; flex: 1; min-width: 0; }
        .log-card-mobile .card-row .card-value.secondary { font-weight: 500; color: #5b6b7e; }

        /* BADGES */
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 10.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .badge-success { background: #dcfce7; color: #047857; }
        .badge-danger  { background: #fee2e2; color: #b91c1c; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info    { background: #e0efff; color: #1d4ed8; text-transform: none; letter-spacing: 0; }
        .badge-module  { background: #e6f3fb; color: #1f6f9f; }
        .badge-role-admin { background: #fee2e2; color: #b91c1c; }
        .badge-role-staff { background: #fef3c7; color: #92400e; }
        .badge-role-guest { background: #dcfce7; color: #047857; }

        /* EMPTY */
        .empty-state { text-align: center; padding: 50px 20px; color: #64748b; }
        .empty-state i { font-size: 40px; margin-bottom: 12px; color: #cbd5e1; display: block; }

        /* PAGINATION */
        .pagination-wrap { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid #e8f0fe; flex-wrap: wrap; gap: 10px 16px; }
        .pagination-info { font-size: 13px; color: #4a6a8c; }
        .pagination-info strong { color: #0B2447; }
        .pagination { display: flex; gap: 6px; flex-wrap: wrap; }
        .pagination a, .pagination span { min-width: 38px; min-height: 38px; padding: 0 12px; border-radius: 10px; text-decoration: none; font-size: 13px; font-weight: 600; border: 1px solid #c9d9e8; color: #334e68; background: #fff; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .pagination a:hover { background: #eaf5fc; border-color: #4DA6D9; color: #0B2447; }
        .pagination .active { background: #2f8dc4; color: #fff; border-color: #2f8dc4; }
        .pagination .disabled { opacity: 0.45; cursor: not-allowed; background: #f6f9fc; }
        @media (max-width: 640px) {
            .pagination-wrap { flex-direction: column; align-items: stretch; text-align: center; }
            .pagination { justify-content: center; }
            .pagination a, .pagination span { min-width: 36px; padding: 0 10px; }
        }
        @media (max-width: 360px) { .pagination a, .pagination span { min-width: 34px; padding: 0 8px; font-size: 12px; } .pagination { gap: 4px; } }


        /* DETAILS (friendly view) */
        .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }
        .act-cell { display: flex; align-items: center; gap: 8px; }
        .act-name { line-height: 1.25; }
        .act-name { font-weight: 600; color: #0B2447; font-size: 13px; }
        .item-text { color: #0B2447; font-weight: 500; overflow-wrap: anywhere; }
        .btn-details { width: 38px; height: 38px; flex-shrink: 0; border: 1px solid #c9d9e8; background: #fff; color: #2f8dc4; border-radius: 10px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; }
        .btn-details:hover { background: #eaf5fc; border-color: #4DA6D9; }
        .btn-details:focus-visible, .detail-close:focus-visible { outline: 3px solid rgba(77,166,217,0.5); outline-offset: 2px; }
        .detail-overlay { position: fixed; inset: 0; background: rgba(11,36,71,0.55); z-index: 99998; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .detail-overlay[hidden] { display: none; }
        .detail-modal { background: #fff; border-radius: 16px; width: 100%; max-width: 520px; max-height: calc(100vh - 32px); overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); border-top: 4px solid #4DA6D9; padding: 18px 20px; }
        .detail-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #e8f0fe; }
        .detail-head h3 { font-size: 16px; font-weight: 700; color: #0B2447; margin: 0; display: flex; align-items: center; gap: 8px; }
        .detail-head h3 i { color: #2f8dc4; }
        .detail-close { width: 38px; height: 38px; border: none; background: #f0f7fb; color: #334e68; border-radius: 10px; font-size: 22px; line-height: 1; cursor: pointer; }
        .detail-close:hover { background: #e0eef8; }
        .detail-list { display: grid; grid-template-columns: minmax(90px, 130px) minmax(0, 1fr); gap: 8px 14px; margin: 0; }
        .detail-list dt { font-size: 11px; font-weight: 700; color: #4a6a8c; text-transform: uppercase; letter-spacing: .4px; padding-top: 2px; }
        .detail-list dd { margin: 0; font-size: 13.5px; color: #0B2447; overflow-wrap: anywhere; }
        .detail-note { margin: 14px 0 0; font-size: 11.5px; color: #5b6b7e; }
        @media (max-width: 480px) { .detail-list { grid-template-columns: minmax(0, 1fr); gap: 2px; } .detail-list dd { margin-bottom: 8px; } }

        /* FOOTER */
        .footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77,166,217,0.15); }
        .footer i { color: #4DA6D9; }
        @media (max-width: 768px) { .footer { font-size: 11px; padding: 12px 10px; border-radius: 10px; margin-top: 20px; } }

        /* LOGOUT */
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
            <div class="role-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item"><a href="admin-dashboard.php" class="nav-link"><i class="fas fa-th-large"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="user-management.php" class="nav-link"><i class="fas fa-users"></i><span>User Management</span></a></li>
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

            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span> Sales Report</span></a></li>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>

            <!-- ✅ SYSTEM LOGS — badge = failed only -->
            <li class="nav-item">
                <a href="system-logs.php" class="nav-link active">
                    <i class="fas fa-history"></i><span>System Logs</span>
                    <?php if($sidebar_failed_logs > 0): ?>
                        <span class="nav-badge"><?php echo $sidebar_failed_logs; ?></span>
                    <?php endif; ?>
                </a>
            </li>

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

    <!-- MAIN -->
    <div class="main-content">

        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-history"></i> System Activity

                    <span class="mobile-role-badge admin">
                        <i class="fas fa-crown"></i> Admin
                    </span>

                    <span class="mobile-avatar" title="<?php echo htmlspecialchars($admin_display_name); ?>">
                        <?php if($admin_avatar): ?>
                            <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $admin_initial; ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <p>Monitor important actions, security events, and changes in the reservation system.</p>
            </div>

            <div class="user-profile">
                <div class="user-info" style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                    <div class="user-role"><i class="fas fa-crown" style="color: #fbbf24;"></i> Admin</div>
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

        <div class="section-header">
            <div class="sh-icon"><i class="fas fa-history"></i></div>
            <div>
                <h2>Activity Overview</h2>
                <p>Recent activity, alerts and the full activity log.</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-list"></i></div>
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Logs</div>
                <div class="stat-small"><i class="fas fa-database"></i> All-time records</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-number"><?php echo number_format($stats['today']); ?></div>
                <div class="stat-label">Today</div>
                <div class="stat-small"><i class="fas fa-users"></i> <?php echo $active_users_today; ?> active user(s)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
                <div class="stat-number"><?php echo number_format($stats['week']); ?></div>
                <div class="stat-label">Last 7 Days</div>
                <div class="stat-small"><i class="fas fa-chart-line"></i> Recent activity</div>
            </div>
            <div class="stat-card danger">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-number"><?php echo number_format($stats['failed']); ?></div>
    <div class="stat-label">Failed Actions</div>
    <div class="stat-small"><i class="fas fa-shield-alt"></i> <?php echo $failed_logins_24h; ?> failed logins (24h)</div>
</div>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-chart-pie"></i> Top Modules (Last 30 Days)</h2>
                </div>
                <?php if(count($top_modules) > 0):
                    $max_cnt = max(array_column($top_modules, 'cnt'));
                ?>
                <div class="module-breakdown">
                    <?php foreach($top_modules as $m):
                        $pct = $max_cnt > 0 ? round(($m['cnt'] / $max_cnt) * 100) : 0;
                        $color = moduleColor($m['module']);
                    ?>
                    <div class="module-row">
                        <span class="module-dot" style="background: <?php echo $color; ?>;"></span>
                        <span class="module-name"><?php echo htmlspecialchars($m['module']); ?></span>
                        <div class="module-bar-wrap">
                            <div class="module-bar" style="width: <?php echo $pct; ?>%; background: <?php echo $color; ?>;"></div>
                        </div>
                        <span class="module-count"><?php echo number_format($m['cnt']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="no-alerts"><i class="fas fa-inbox" style="color: #cbd5e1;"></i>No activity in the last 30 days.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-bell"></i> Recent Alerts</h2>
                    <a href="?status=failed">View All →</a>
                </div>
                <?php
                $recent_alerts = $pdo->query("
                    SELECT * FROM system_logs
                    WHERE status IN ('failed','warning') OR action IN ('login_failed','reject_payment','delete')
                    ORDER BY created_at DESC
                    LIMIT 5
                ")->fetchAll();
                ?>
                <?php if(count($recent_alerts) > 0): ?>
                <div class="alert-list">
                    <?php foreach($recent_alerts as $a):
                        $is_failed = $a['status'] === 'failed' || $a['action'] === 'login_failed';
                        $cls = $is_failed ? 'danger' : 'warning';
                        $ic  = $is_failed ? 'fa-exclamation-circle' : 'fa-exclamation-triangle';
                    ?>
                    <div class="alert-item <?php echo $cls; ?>">
                        <div class="alert-icon" style="background: <?php echo $is_failed ? '#ef4444' : '#f59e0b'; ?>;">
                            <i class="fas <?php echo $ic; ?>"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-title"><?php echo htmlspecialchars(mb_substr(friendlyDescription($a['description'], $a['action'], $a['username'] ?? null), 0, 70)) . (mb_strlen(friendlyDescription($a['description'], $a['action'], $a['username'] ?? null)) > 70 ? '...' : ''); ?></div>
                            <div class="alert-sub">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($a['username'] ?? 'System'); ?>
                                · <i class="fas fa-tag"></i> <?php echo htmlspecialchars(friendlyArea($a['module'])); ?>
                            </div>
                        </div>
                        <div class="alert-time">
                            <?php
                            $diff = time() - strtotime($a['created_at']);
                            if ($diff < 60) echo $diff . 's ago';
                            elseif ($diff < 3600) echo floor($diff / 60) . 'm ago';
                            elseif ($diff < 86400) echo floor($diff / 3600) . 'h ago';
                            else echo date('M d', strtotime($a['created_at']));
                            ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="no-alerts">
                    <i class="fas fa-check-circle"></i>
                    No recent alerts — everything looks good!
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Full Activity Log</h2>
            </div>

            <form method="GET" action="system-logs.php" id="filterForm">
                <div class="filter-bar">
                    <div class="filter-group">
                        <label for="filter_search">Search</label>
                        <input type="text" id="filter_search" name="search" placeholder="Search activity or person..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="filter_module">Area</label>
                        <select id="filter_module" name="module">
                            <option value="all">All Areas</option>
                            <?php foreach($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $module === $m ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(friendlyArea($m)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter_action">Activity</label>
                        <select id="filter_action" name="action">
                            <option value="all">All Activities</option>
                            <?php foreach($actions as $a): ?>
                                <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(friendlyAction($a)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter_status">Result</label>
                        <select id="filter_status" name="status">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="success" <?php echo $status === 'success' ? 'selected' : ''; ?>>Success</option>
                            <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="warning" <?php echo $status === 'warning' ? 'selected' : ''; ?>>Warning</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter_date_from">From</label>
                        <input type="date" id="filter_date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="filter_date_to">To</label>
                        <input type="date" id="filter_date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="system-logs.php" class="btn-clear">
                        <i class="fas fa-times"></i> Clear
                    </a>
                    <a class="btn-export"
                       href="?<?php
                            $export_qs = $base_qs;
                            $export_qs['export'] = 'csv';
                            echo htmlspecialchars(http_build_query($export_qs));
                       ?>">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </form>

            <div class="table-responsive desktop-table">
                <?php if(count($logs) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 130px;">Activity</th>
                            <th>What Happened</th>
                            <th>Performed By</th>
                            <th>Area</th>
                            <th>Affected Item</th>
                            <th>Result</th>
                            <th>When</th>
                            <th style="width: 44px;"><span class="sr-only">Details</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log):
                            [$icon, $color] = actionIcon($log['action']);
                            $f_desc = friendlyDescription($log['description'], $log['action'], $log['username'] ?? null);
                            $f_target = friendlyTarget($log['target_type'], $log['target_id'], $log['description'], $log['action']);
                            $f_detail = htmlspecialchars(json_encode([
                                'Activity' => friendlyAction($log['action']),
                                'Action code' => $log['action'],
                                'Area' => friendlyArea($log['module']) . ' (' . $log['module'] . ')',
                                'What happened' => $f_desc,
                                'Performed by' => ($log['username'] ?? 'System') . ($log['role'] ? ' (' . ucfirst($log['role']) . ')' : ''),
                                'Affected item' => $f_target ?? '—',
                                'Target ID' => ($log['target_type'] ? $log['target_type'] : '—') . ($log['target_id'] ? ' #' . (int)$log['target_id'] : ''),
                                'Result' => ucfirst($log['status']),
                                'Full timestamp' => date('l, F j, Y \a\t h:i:s A', strtotime($log['created_at'])),
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES);
                        ?>
                        <tr>
                            <td>
                                <div class="act-cell">
                                    <div class="log-icon" style="background: <?php echo $color; ?>;">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <span class="act-name"><?php echo htmlspecialchars(friendlyAction($log['action'])); ?></span>
                                </div>
                            </td>
                            <td><div class="log-desc"><?php echo htmlspecialchars($f_desc); ?></div></td>
                            <td>
                                <strong><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></strong>
                                <div class="log-meta"><?php echo roleBadge($log['role']); ?></div>
                            </td>
                            <td><span class="badge badge-module"><?php echo htmlspecialchars(friendlyArea($log['module'])); ?></span></td>
                            <td>
                                <?php if($f_target): ?>
                                    <span class="item-text"><?php echo htmlspecialchars($f_target); ?></span>
                                <?php else: ?>
                                    <span class="no-target">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo statusBadge($log['status']); ?></td>
                            <td>
                                <div class="log-date"><?php echo date('M d, Y', strtotime($log['created_at'])); ?></div>
                                <div class="log-time"><?php echo date('h:i A', strtotime($log['created_at'])); ?></div>
                            </td>
                            <td><button type="button" class="btn-details" data-detail="<?php echo $f_detail; ?>" aria-label="View details" title="View details"><i class="fas fa-eye"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No system logs found for the selected filters.</p>
                </div>
                <?php endif; ?>
            </div>

            <div class="logs-cards-mobile">
                <?php if(count($logs) > 0): ?>
                    <?php foreach($logs as $log):
                        [$icon, $color] = actionIcon($log['action']);
                    ?>
                    <?php
                        $f_desc = friendlyDescription($log['description'], $log['action'], $log['username'] ?? null);
                        $f_target = friendlyTarget($log['target_type'], $log['target_id'], $log['description'], $log['action']);
                        $f_detail = htmlspecialchars(json_encode([
                            'Activity' => friendlyAction($log['action']),
                            'Action code' => $log['action'],
                            'Area' => friendlyArea($log['module']) . ' (' . $log['module'] . ')',
                            'What happened' => $f_desc,
                            'Performed by' => ($log['username'] ?? 'System') . ($log['role'] ? ' (' . ucfirst($log['role']) . ')' : ''),
                            'Affected item' => $f_target ?? '—',
                            'Target ID' => ($log['target_type'] ? $log['target_type'] : '—') . ($log['target_id'] ? ' #' . (int)$log['target_id'] : ''),
                            'Result' => ucfirst($log['status']),
                            'Full timestamp' => date('l, F j, Y \a\t h:i:s A', strtotime($log['created_at'])),
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES);
                    ?>
                    <div class="log-card-mobile">
                        <div class="card-top-row">
                            <div class="card-icon-wrap">
                                <div class="log-icon" style="background: <?php echo $color; ?>;">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="card-action-label"><?php echo htmlspecialchars(friendlyAction($log['action'])); ?></div>
                                    <div class="card-desc"><?php echo htmlspecialchars($f_desc); ?></div>
                                </div>
                            </div>
                            <button type="button" class="btn-details" data-detail="<?php echo $f_detail; ?>" aria-label="View details" title="View details"><i class="fas fa-eye"></i></button>
                        </div>

                        <div class="card-badges">
                            <?php echo statusBadge($log['status']); ?>
                            <span class="badge badge-module"><?php echo htmlspecialchars(friendlyArea($log['module'])); ?></span>
                        </div>

                        <div class="card-row">
                            <i class="fas fa-user"></i>
                            <span class="card-label">By</span>
                            <span class="card-value">
                                <?php echo htmlspecialchars($log['username'] ?? 'System'); ?>
                                <?php echo roleBadge($log['role']); ?>
                            </span>
                        </div>

                        <?php if($f_target): ?>
                        <div class="card-row">
                            <i class="fas fa-crosshairs"></i>
                            <span class="card-label">Item</span>
                            <span class="card-value"><?php echo htmlspecialchars($f_target); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="card-row">
                            <i class="fas fa-clock"></i>
                            <span class="card-label">When</span>
                            <span class="card-value">
                                <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                                · <?php echo date('h:i A', strtotime($log['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8; background:white; border-radius:14px; border:1px solid #e8f0fe;">
                        <i class="fas fa-history" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p>No system logs found for the selected filters.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination-wrap">
                <div class="pagination-info">
                    Showing <strong><?php echo $offset + 1; ?></strong>–<strong><?php echo min($offset + $per_page, $total); ?></strong> of <strong><?php echo $total; ?></strong> logs
                </div>
                <div class="pagination">
                    <?php
                    $qs = $base_qs;
                    if($page > 1) {
                        $qs['page'] = $page - 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($qs)) . '"><i class="fas fa-chevron-left"></i> Prev</a>';
                    } else {
                        echo '<span class="disabled"><i class="fas fa-chevron-left"></i> Prev</span>';
                    }

                    $start = max(1, $page - 2);
                    $end   = min($total_pages, $page + 2);

                    if($start > 1) {
                        $qs['page'] = 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($qs)) . '">1</a>';
                        if($start > 2) echo '<span class="disabled">…</span>';
                    }
                    for($i = $start; $i <= $end; $i++) {
                        $qs['page'] = $i;
                        if($i === $page) echo '<span class="active">' . $i . '</span>';
                        else echo '<a href="?' . htmlspecialchars(http_build_query($qs)) . '">' . $i . '</a>';
                    }
                    if($end < $total_pages) {
                        if($end < $total_pages - 1) echo '<span class="disabled">…</span>';
                        $qs['page'] = $total_pages;
                        echo '<a href="?' . htmlspecialchars(http_build_query($qs)) . '">' . $total_pages . '</a>';
                    }

                    if($page < $total_pages) {
                        $qs['page'] = $page + 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($qs)) . '">Next <i class="fas fa-chevron-right"></i></a>';
                    } else {
                        echo '<span class="disabled">Next <i class="fas fa-chevron-right"></i></span>';
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>
                <i class="fas fa-umbrella-beach"></i>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Hundred Islands Reservation System. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i> Administrator Access
                </span>
            </p>
        </div>
    </div>
</div>

<div class="detail-overlay" id="detailModal" role="dialog" aria-modal="true" aria-labelledby="detailTitle" hidden>
    <div class="detail-modal">
        <div class="detail-head">
            <h3 id="detailTitle"><i class="fas fa-circle-info"></i> Activity Details</h3>
            <button type="button" class="detail-close" id="detailClose" aria-label="Close">&times;</button>
        </div>
        <dl class="detail-list" id="detailList"></dl>
        <p class="detail-note">Technical details are shown for administrators only.</p>
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
            <a href="logout.php" class="btn-logout-confirm">
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
    if (willOpen && window.innerWidth <= 1024) document.body.classList.add('sidebar-open-mobile');
    else document.body.classList.remove('sidebar-open-mobile');
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('open')) toggleSidebar();
        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) closeLogoutModal();
    }
});

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
    const modal = document.getElementById('logoutModal');
    if (modal) modal.addEventListener('click', function(e) { if (e.target === this) closeLogoutModal(); });
});

    (function () {
        var modal = document.getElementById('detailModal'), list = document.getElementById('detailList'), lastBtn = null;
        if (!modal) return;
        function closeDetail() { modal.hidden = true; if (lastBtn) lastBtn.focus(); }
        document.addEventListener('click', function (e) {
            var b = e.target.closest('.btn-details');
            if (b) {
                var d = {}; try { d = JSON.parse(b.getAttribute('data-detail')) || {}; } catch (x) {}
                list.innerHTML = '';
                Object.keys(d).forEach(function (k) {
                    var dt = document.createElement('dt'), dd = document.createElement('dd');
                    dt.textContent = k; dd.textContent = d[k]; list.appendChild(dt); list.appendChild(dd);
                });
                lastBtn = b; modal.hidden = false; document.getElementById('detailClose').focus(); return;
            }
            if (e.target === modal || e.target.closest('#detailClose')) closeDetail();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeDetail(); });
    })();
</script>

</body>
</html>
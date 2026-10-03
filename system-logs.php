<?php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

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

$base_qs = $_GET;
unset($base_qs['page'], $base_qs['export']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>System Logs - Admin</title>
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

        .page-title-banner { background: linear-gradient(135deg,#0B2447 0%,#0B3D91 50%,#4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11,36,71,0.15); position: relative; overflow: hidden; }
        .page-title-banner::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); animation: rotate 20s linear infinite; }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .page-title-banner .banner-content { position: relative; z-index: 1; }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner h1 i { margin-right: 10px; opacity: 0.9; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }

        @media (max-width: 768px) {
            .page-title-banner { padding: 20px; text-align: center; border-radius: 16px; }
            .page-title-banner .underline { margin: 8px auto 0; }
            .page-title-banner h1 { font-size: 22px; }
        }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #4DA6D9; border-radius: 16px; padding: 22px 20px; color: white; box-shadow: 0 10px 30px rgba(77,166,217,0.2); border: 1px solid rgba(255,255,255,0.15); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(77,166,217,0.3); }
        .stat-icon { width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: 700; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 13px; }
        .stat-small { font-size: 11px; color: rgba(255,255,255,0.85); margin-top: 6px; display: flex; align-items: center; gap: 4px; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; border-radius: 12px; }
            .stat-number { font-size: 22px; }
            .stat-icon { width: 40px; height: 40px; font-size: 16px; margin-bottom: 6px; }
            .stat-label { font-size: 11px; }
            .stat-small { font-size: 10px; }
        }

        /* DASHBOARD GRID */
        .dashboard-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 25px; margin-bottom: 30px; }
        @media (max-width: 992px) { .dashboard-grid { grid-template-columns: 1fr; } }

        /* CARD */
        .card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); border: 1px solid #e8f0fe; margin-bottom: 30px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
        .card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
        .card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }
        .card-header a { color: #4DA6D9; text-decoration: none; font-weight: 500; font-size: 13px; }
        .card-header a:hover { text-decoration: underline; }

        @media (max-width: 768px) {
            .card { padding: 18px 15px; border-radius: 14px; }
            .card-header { flex-direction: column; align-items: stretch; gap: 10px; }
            .card-header h2 { font-size: 15px; }
            .card-header h2 i { padding: 6px; font-size: 12px; }
        }

        /* MODULE BREAKDOWN */
        .module-breakdown { display: flex; flex-direction: column; gap: 12px; }
        .module-row { display: flex; align-items: center; gap: 12px; }
        .module-row .module-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
        .module-row .module-name { font-weight: 600; color: #1e293b; font-size: 13px; min-width: 90px; text-transform: capitalize; }
        .module-row .module-bar-wrap { flex: 1; height: 8px; background: #f1f5f9; border-radius: 10px; overflow: hidden; }
        .module-row .module-bar { height: 100%; border-radius: 10px; transition: width 0.5s; }
        .module-row .module-count { font-weight: 700; color: #0B2447; font-size: 13px; min-width: 40px; text-align: right; }

        /* ALERT LIST */
        .alert-list { display: flex; flex-direction: column; gap: 10px; }
        .alert-item { display: flex; align-items: center; gap: 12px; padding: 12px 15px; background: #f8fafc; border-radius: 10px; border-left: 3px solid; }
        .alert-item.warning { border-left-color: #f59e0b; background: #fffbeb; }
        .alert-item.danger { border-left-color: #ef4444; background: #fef2f2; }
        .alert-item .alert-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: white; flex-shrink: 0; }
        .alert-item .alert-content { flex: 1; min-width: 0; }
        .alert-item .alert-title { font-weight: 600; color: #1e293b; font-size: 13px; }
        .alert-item .alert-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
        .alert-item .alert-time { font-size: 11px; color: #94a3b8; flex-shrink: 0; }
        .no-alerts { text-align: center; padding: 30px 15px; color: #94a3b8; font-size: 13px; }
        .no-alerts i { font-size: 32px; color: #10b981; display: block; margin-bottom: 8px; }

        /* FILTER */
        .filter-bar {
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr 1fr;
            gap: 10px;
            align-items: end;
            margin-bottom: 15px;
        }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; }
        .filter-bar input, .filter-bar select {
            padding: 9px 12px;
            border: 2px solid #e8f0fe;
            border-radius: 8px;
            font-size: 13px;
            background: #fafafa;
            width: 100%;
            font-family: inherit;
        }
        .filter-bar input:focus, .filter-bar select:focus { outline: none; border-color: #4DA6D9; background: white; }

        .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
            align-items: center;
        }
        .btn-filter {
            padding: 10px 18px;
            background: #4DA6D9;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }
        .btn-filter:hover { background: #3a8bbf; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(77,166,217,0.3); }
        .btn-clear {
            padding: 10px 14px;
            background: #64748b;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-clear:hover { background: #475569; color: white; transform: translateY(-2px); }
        .btn-export {
            padding: 10px 18px;
            background: #10b981;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            margin-left: auto;
            transition: all 0.2s;
        }
        .btn-export:hover { background: #059669; color: white; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }

        @media (max-width: 992px) { .filter-bar { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 480px) {
            .filter-bar { grid-template-columns: 1fr; }
            .filter-actions { flex-direction: column; align-items: stretch; }
            .filter-actions .btn-filter,
            .filter-actions .btn-clear,
            .filter-actions .btn-export { justify-content: center; width: 100%; margin-left: 0; }
        }

        /* TABLE */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e8f0fe; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        thead th { text-align: left; padding: 12px 14px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; border-bottom: 2px solid #e8f0fe; }
        tbody td { padding: 12px 14px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f8fafc; }

        .log-icon { display: inline-flex; width: 34px; height: 34px; border-radius: 8px; align-items: center; justify-content: center; color: white; font-size: 14px; flex-shrink: 0; }
        .log-desc { max-width: 380px; word-wrap: break-word; line-height: 1.4; }
        .log-meta { font-size: 11px; color: #94a3b8; margin-top: 3px; }

        /* MOBILE LOG CARDS */
        .logs-cards-mobile { display: none; flex-direction: column; gap: 12px; }
        @media (min-width: 769px) { .logs-cards-mobile { display: none !important; } }
        @media (max-width: 768px) {
            .table-responsive.desktop-table { display: none !important; }
            .logs-cards-mobile { display: flex; }
        }

        .log-card-mobile { background: white; border-radius: 14px; padding: 14px; border: 1px solid #e8f0fe; box-shadow: 0 4px 12px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 10px; }
        .log-card-mobile .card-top-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .log-card-mobile .card-icon-wrap { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
        .log-card-mobile .card-icon-wrap .log-icon { width: 38px; height: 38px; font-size: 15px; }
        .log-card-mobile .card-action-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; }
        .log-card-mobile .card-desc { font-weight: 600; color: #0B2447; font-size: 12.5px; line-height: 1.4; word-break: break-word; }
        .log-card-mobile .card-badges { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0; }
        .log-card-mobile .card-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #475569; }
        .log-card-mobile .card-row i { width: 16px; color: #4DA6D9; flex-shrink: 0; text-align: center; font-size: 11px; }
        .log-card-mobile .card-row .card-label { color: #94a3b8; font-size: 10px; font-weight: 700; min-width: 52px; text-transform: uppercase; letter-spacing: 0.3px; }
        .log-card-mobile .card-row .card-value { font-weight: 600; color: #0B2447; word-break: break-word; flex: 1; min-width: 0; }

        /* BADGES */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-danger  { background: #fee2e2; color: #ef4444; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-info    { background: #dbeafe; color: #3b82f6; }
        .badge-module  { background: #eef2ff; color: #4DA6D9; }
        .badge-role-admin { background: #fee2e2; color: #ef4444; }
        .badge-role-staff { background: #fef3c7; color: #f59e0b; }
        .badge-role-guest { background: #e6f7e6; color: #10b981; }

        /* EMPTY */
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 15px; color: #cbd5e1; display: block; }

        /* PAGINATION */
        .pagination-wrap { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: 13px; color: #64748b; }
        .pagination { display: flex; gap: 5px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 6px 12px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600; border: 1px solid #e8f0fe; color: #475569; }
        .pagination a:hover { background: #4DA6D9; color: white; border-color: #4DA6D9; }
        .pagination .active { background: #4DA6D9; color: white; border-color: #4DA6D9; }
        .pagination .disabled { opacity: 0.4; cursor: not-allowed; }

        @media (max-width: 480px) {
            .pagination-info { width: 100%; text-align: center; }
            .pagination { justify-content: center; width: 100%; }
            .pagination a, .pagination span { padding: 5px 9px; font-size: 11px; }
        }

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
    </style>
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

            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span> Sales Report</span></a></li>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>

            <!-- ✅ SYSTEM LOGS — badge = failed only -->
            <li class="nav-item">
                <a href="system-logs.php" class="nav-link active">
                    <i class="fas fa-history"></i><span>System Logs</span>
                    <?php if($stats['failed'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['failed']; ?></span>
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
                    <i class="fas fa-history"></i> System Logs

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
                <p>Complete audit trail of all system activity</p>
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

        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-history"></i> System Logs Dashboard</h1>
                <div class="underline"></div>
                <p>Track every action performed across the system — who did what, when, and from where.</p>
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
            <div class="stat-card">
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
                            <div class="alert-title"><?php echo htmlspecialchars(mb_substr($a['description'], 0, 60)) . (mb_strlen($a['description']) > 60 ? '...' : ''); ?></div>
                            <div class="alert-sub">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($a['username'] ?? 'System'); ?>
                                · <i class="fas fa-tag"></i> <?php echo htmlspecialchars($a['module']); ?>
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
                        <input type="text" id="filter_search" name="search" placeholder="Description, user, IP..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="filter_module">Module</label>
                        <select id="filter_module" name="module">
                            <option value="all">All Modules</option>
                            <?php foreach($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $module === $m ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($m)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter_action">Action</label>
                        <select id="filter_action" name="action">
                            <option value="all">All Actions</option>
                            <?php foreach($actions as $a): ?>
                                <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($a))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter_status">Status</label>
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
                            <th style="width: 60px;">Type</th>
                            <th>Description</th>
                            <th>User</th>
                            <th>Module</th>
                            <th>Target</th>
                            <th>IP</th>
                            <th>Status</th>
                            <th>Date/Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log):
                            [$icon, $color] = actionIcon($log['action']);
                        ?>
                        <tr>
                            <td>
                                <div class="log-icon" style="background: <?php echo $color; ?>;">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                            </td>
                            <td>
                                <div class="log-desc"><?php echo htmlspecialchars($log['description']); ?></div>
                                <div class="log-meta"><i class="fas fa-tag"></i> <?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?></div>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></strong>
                                <div class="log-meta"><?php echo roleBadge($log['role']); ?></div>
                            </td>
                            <td><span class="badge badge-module"><?php echo htmlspecialchars($log['module']); ?></span></td>
                            <td>
                                <?php if($log['target_type']): ?>
                                    <span class="badge badge-info">
                                        <?php echo htmlspecialchars($log['target_type']); ?>
                                        <?php if($log['target_id']): ?> #<?php echo (int)$log['target_id']; ?><?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size: 11px;"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></code></td>
                            <td><?php echo statusBadge($log['status']); ?></td>
                            <td>
                                <div style="font-weight: 600; color: #0B2447; font-size: 12px;">
                                    <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                                </div>
                                <div class="log-meta"><?php echo date('h:i:s A', strtotime($log['created_at'])); ?></div>
                            </td>
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
                    <div class="log-card-mobile">
                        <div class="card-top-row">
                            <div class="card-icon-wrap">
                                <div class="log-icon" style="background: <?php echo $color; ?>;">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="card-action-label"><?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?></div>
                                    <div class="card-desc"><?php echo htmlspecialchars($log['description']); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="card-badges" style="justify-content: flex-start;">
                            <?php echo statusBadge($log['status']); ?>
                            <span class="badge badge-module"><?php echo htmlspecialchars($log['module']); ?></span>
                        </div>

                        <div class="card-row">
                            <i class="fas fa-user"></i>
                            <span class="card-label">User</span>
                            <span class="card-value">
                                <?php echo htmlspecialchars($log['username'] ?? 'System'); ?>
                                <?php echo roleBadge($log['role']); ?>
                            </span>
                        </div>

                        <?php if($log['target_type']): ?>
                        <div class="card-row">
                            <i class="fas fa-crosshairs"></i>
                            <span class="card-label">Target</span>
                            <span class="card-value">
                                <?php echo htmlspecialchars($log['target_type']); ?>
                                <?php if($log['target_id']): ?> #<?php echo (int)$log['target_id']; ?><?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($log['ip_address'])): ?>
                        <div class="card-row">
                            <i class="fas fa-network-wired"></i>
                            <span class="card-label">IP</span>
                            <span class="card-value"><code style="font-size: 11px;"><?php echo htmlspecialchars($log['ip_address']); ?></code></span>
                        </div>
                        <?php endif; ?>

                        <div class="card-row">
                            <i class="fas fa-clock"></i>
                            <span class="card-label">When</span>
                            <span class="card-value">
                                <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                                · <?php echo date('h:i:s A', strtotime($log['created_at'])); ?>
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
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Huddled Islands Tour and Reservation. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i> Administrator Access
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
</script>

</body>
</html>
<?php
// ============================================================
// reports.php — Sales Analytics & Reports (ADMIN ONLY, read-only)
// Every figure on this page comes from includes/Exporter.php so the KPI
// cards, charts, tables, CSV and print output share one calculation.
// ============================================================

// Production-safe error handling: log, never display (stray output would
// also corrupt the JSON / CSV responses below).
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');   // same as the other admin pages
ob_start();

session_start();
require_once 'database.php';
require_once 'includes/sidebar-counts.php';
require_once 'includes/Exporter.php';

// ✅ Load SystemLogger (for logout logging)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

require_once 'includes/auth.php';
requireAdmin();

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

// Sales Report is management information: ADMIN ONLY
if(!$is_admin) {
    header("Location: admin-dashboard.php");
    exit();
}

// Logout fallback
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

// Site settings (also used for the CSV / print header)
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {
    error_log('[reports] site_content load failed: ' . $e->getMessage());
    $content = [];
}
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

/** Send JSON and stop; discards any buffered output first. */
function reportJson(array $payload, $status = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

function reportLogError($where, Throwable $e) {
    error_log('[reports] ' . $where . ' failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ============================================================
// AJAX: ANALYTICS DASHBOARD (KPIs, charts, performance, customers, data quality)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'analytics') {
    try {
        $filters  = ReportExporter::normalizeFilters($_GET);
        $exporter = new ReportExporter($pdo);
        reportJson(['success' => true, 'analytics' => $exporter->getAnalytics($filters)]);
    } catch (ReportException $e) {
        reportJson(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        reportLogError('analytics', $e);
        reportJson(['success' => false, 'message' => 'Unable to load analytics right now. The error has been logged for the administrator.'], 500);
    }
}

// ============================================================
// AJAX: DETAILED REPORT TABLE
// ============================================================
if (isset($_GET['preview'])) {
    try {
        $type = (string)$_GET['preview'];
        if (!in_array($type, ReportExporter::REPORT_TYPES, true)) {
            throw new ReportException('Unknown report type.');
        }
        $filters  = ReportExporter::normalizeFilters($_GET);
        $exporter = new ReportExporter($pdo);
        $table    = $exporter->getReportTable($type, $filters);
        $rowCount = count($table['rows']);
        $truncated = $rowCount > ReportExporter::PREVIEW_ROW_LIMIT;
        if ($truncated) $table['rows'] = array_slice($table['rows'], 0, ReportExporter::PREVIEW_ROW_LIMIT);
        reportJson([
            'success'   => true,
            'filters'   => $exporter->publicFilters($filters),
            'table'     => $table,
            'row_count' => $rowCount,
            'truncated' => $truncated,
            'limit'     => ReportExporter::PREVIEW_ROW_LIMIT,
        ]);
    } catch (ReportException $e) {
        reportJson(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        reportLogError('preview', $e);
        reportJson(['success' => false, 'message' => 'Unable to load this report right now. The error has been logged for the administrator.'], 500);
    }
}

// ============================================================
// CSV EXPORT (same table builder as the preview → identical totals)
// ============================================================
if (isset($_GET['export'])) {
    $type = (string)$_GET['export'];
    $back = 'reports.php';
    try {
        if (!in_array($type, ReportExporter::REPORT_TYPES, true)) {
            throw new ReportException('Invalid export type.');
        }
        $filters = ReportExporter::normalizeFilters($_GET);
        $back = 'reports.php?' . http_build_query([
            'report' => $type, 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
            'service' => $filters['service'], 'payment' => $filters['payment'], 'status' => $filters['status'],
        ]);
        $exporter = new ReportExporter($pdo);
        $exporter->exportReport($type, $filters, $site_name);   // streams and exits
    } catch (ReportException $e) {
        $_SESSION['report_error'] = 'Export failed: ' . $e->getMessage();
    } catch (Throwable $e) {
        reportLogError('export', $e);
        $_SESSION['report_error'] = 'Export failed because of a server error. The error has been logged for the administrator.';
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: ' . $back);
    exit();
}

// ============================================================
// PAGE: initial filter state (validated; never echoed raw)
// ============================================================
$page_error = null;
if (!empty($_SESSION['report_error'])) {
    $page_error = (string)$_SESSION['report_error'];
    unset($_SESSION['report_error']);
}
try {
    $init_filters = ReportExporter::normalizeFilters($_GET);
} catch (ReportException $e) {
    $page_error = $page_error ?: ('Filters were reset: ' . $e->getMessage());
    $init_filters = ReportExporter::normalizeFilters([]);
}
$allowed_ranges = ['today', 'yesterday', 'this_week', 'last_7', 'this_month', 'last_30', 'this_quarter', 'this_year', 'custom'];
$init_range = isset($_GET['range_type']) && in_array($_GET['range_type'], $allowed_ranges, true)
    ? $_GET['range_type']
    : ((isset($_GET['date_from']) || isset($_GET['date_to'])) ? 'custom' : 'this_month');
$init_report = isset($_GET['report']) && in_array($_GET['report'], ReportExporter::REPORT_TYPES, true) ? $_GET['report'] : 'bookings';

$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch() ?: [];
} catch(PDOException $e) {
    error_log('[reports] user load failed: ' . $e->getMessage());
    $user_info = [];
}

// ============================================================
// Resolve avatar (photo or initial) — same as admin-profile.php
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

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_tagline = $content['site_settings']['site_tagline'] ?? 'Your Home Away From Home';

$report_init = [
    'filters' => [
        'date_from' => $init_filters['date_from'],
        'date_to'   => $init_filters['date_to'],
        'service'   => $init_filters['service'],
        'payment'   => $init_filters['payment'],
        'status'    => $init_filters['status'],
        'range'     => $init_range,
    ],
    'report'   => $init_report,
    'siteName' => $site_name,
    'maxDays'  => ReportExporter::MAX_RANGE_DAYS,
];
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Sales Analytics &amp; Reports - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
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

        /* ============================================================
           SALES ANALYTICS — components (same navy / blue / white identity)
           ============================================================ */
        :root {
            --navy: #0B2447; --navy-2: #0B3D91; --blue: #4DA6D9; --blue-2: #7bb8f0;
            --ink: #0B2447; --ink-2: #334155; --muted: #64748b; --faint: #94a3b8;
            --line: #e8f0fe; --bg-soft: #f8fafc;
            --c-house: #2563eb; --c-tour: #0891b2; --c-food: #d97706; --c-package: #7c3aed;
            --ok: #059669; --warn: #d97706; --bad: #dc2626;
        }
        .main-content > * { min-width: 0; }
        .card-shell { background: #fff; border: 1px solid var(--line); border-radius: 20px; box-shadow: 0 10px 30px rgba(6, 38, 61, 0.06); }
        .section-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin: 28px 0 14px; }
        .section-title h2 { font-size: 18px; font-weight: 700; color: var(--ink); margin: 0; display: flex; align-items: center; gap: 10px; }
        .section-title h2 i { color: var(--blue); }
        .section-title .section-sub { font-size: 12px; color: var(--muted); }

        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px; font-size: 14px; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert i { margin-top: 2px; }

        /* FILTERS */
        .filter-card { padding: 18px 20px; margin-bottom: 22px; }
        .filter-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)) auto; gap: 12px; align-items: end; }
        .fg { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .fg label { font-size: 12px; font-weight: 600; color: var(--ink); letter-spacing: 0.2px; }
        .fg select, .fg input[type="date"] {
            width: 100%; min-width: 0; height: 42px; padding: 8px 12px; border: 2px solid var(--line); border-radius: 10px;
            font-size: 14px; background: #fafafa; color: var(--ink); font-family: inherit; transition: border-color .2s, background .2s;
        }
        .fg select:focus, .fg input[type="date"]:focus { outline: none; border-color: var(--blue); background: #fff; }
        .fg input.is-invalid { border-color: #ef4444; background: #fff5f5; }
        .btn-clear { height: 42px; padding: 0 16px; background: #64748b; color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; transition: background .2s, transform .2s; }
        .btn-clear:hover { background: #475569; transform: translateY(-1px); }
        .filter-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-top: 12px; font-size: 12.5px; color: var(--muted); }
        .filter-meta .period-pill { background: #eef6fc; color: var(--navy); border-radius: 999px; padding: 4px 12px; font-weight: 600; }
        .filter-error { color: #b91c1c; font-weight: 600; display: inline-flex; gap: 6px; align-items: center; }
        .filter-error[hidden] { display: none; }
        @media (max-width: 1279px) { .filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } .fg-actions { justify-self: start; } }
        @media (max-width: 767px)  { .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .filter-card { padding: 14px; } }
        @media (max-width: 374px)  { .filter-grid { grid-template-columns: 1fr; } }

        /* KPI CARDS */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .kpi-card { position: relative; padding: 20px; min-width: 0; display: flex; flex-direction: column; gap: 4px; transition: transform .25s, box-shadow .25s; }
        .kpi-card:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(6, 38, 61, 0.12); }
        .kpi-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .kpi-label { font-size: 13px; font-weight: 700; color: var(--ink-2); }
        .kpi-icon { width: 42px; height: 42px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
        .kpi-value { margin-top: 10px; font-size: clamp(22px, 1.9vw, 30px); font-weight: 800; color: var(--ink); line-height: 1.1; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        .kpi-sub { font-size: 12px; color: var(--muted); line-height: 1.45; }
        .kpi-delta { font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; }
        .kpi-delta.up { color: var(--ok); } .kpi-delta.down { color: var(--bad); } .kpi-delta.flat, .kpi-delta.na { color: var(--faint); font-weight: 500; }
        .kpi-grid.loading .kpi-value, .kpi-strip.loading .strip-value { color: transparent; background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 50%, #eef2f7 75%); background-size: 200% 100%; animation: shimmer 1.2s infinite; border-radius: 8px; }
        @keyframes shimmer { to { background-position: -200% 0; } }
        .i-blue { background: #dbeafe; color: #2563eb; } .i-cyan { background: #cffafe; color: #0891b2; } .i-violet { background: #ede9fe; color: #7c3aed; }
        .i-amber { background: #fef3c7; color: #d97706; } .i-green { background: #d1fae5; color: #059669; } .i-red { background: #fee2e2; color: #dc2626; }
        .i-navy { background: #e0e7ff; color: #0B3D91; } .i-sky { background: #e0f2fe; color: #0284c7; }

        .kpi-strip { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }
        .strip-item { padding: 14px 18px; display: flex; align-items: center; gap: 14px; min-width: 0; border-radius: 16px; }
        .strip-item .kpi-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 12px; }
        .strip-body { min-width: 0; flex: 1; }
        .strip-label { font-size: 12px; font-weight: 700; color: var(--ink-2); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .strip-value { font-size: 18px; font-weight: 800; color: var(--ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .strip-sub { font-size: 11.5px; color: var(--muted); }
        .strip-item.attention { border-color: #fde68a; background: #fffdf5; }

        .info-tip { position: relative; display: inline-flex; color: var(--faint); cursor: help; outline: none; }
        .info-tip:hover, .info-tip:focus { color: var(--blue); }
        .info-tip .tip { display: none; position: absolute; z-index: 50; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%); width: min(280px, 70vw); background: var(--navy); color: #e0eeff; font-size: 12px; font-weight: 500; line-height: 1.5; padding: 10px 12px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,.25); text-align: left; white-space: normal; }
        .info-tip:hover .tip, .info-tip:focus .tip, .info-tip:focus-within .tip { display: block; }
        @media (max-width: 767px) { .info-tip .tip { left: auto; right: -10px; transform: none; } }

        @media (max-width: 1279px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 1279px) { .kpi-strip { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 1023px) { .kpi-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 479px)  { .kpi-strip { grid-template-columns: 1fr; } }
        @media (max-width: 479px)  { .kpi-grid { grid-template-columns: 1fr; gap: 12px; } .kpi-card { padding: 16px; } .kpi-value { font-size: 24px; } }

        /* PANELS */
        .panel-grid { display: grid; gap: 16px; margin-top: 16px; }
        .panel-grid.charts { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
        .panel-grid.halves { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .panel { padding: 18px 20px; min-width: 0; position: relative; }
        .panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .panel-head h3 { font-size: 15px; font-weight: 700; color: var(--ink); margin: 0; display: flex; align-items: center; gap: 8px; }
        .panel-head h3 i { color: var(--blue); }
        .panel-head .panel-note { font-size: 12px; color: var(--muted); }
        .chart-box { position: relative; width: 100%; height: 300px; }
        .chart-box.donut { height: 220px; }
        .chart-empty, .panel-state { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px; color: var(--faint); font-size: 13px; background: rgba(255,255,255,.92); border-radius: 12px; padding: 16px; }
        .chart-empty i, .panel-state i { font-size: 26px; color: #cbd5e1; }
        .panel-state.error i { color: #ef4444; }
        .legend-row { display: flex; flex-wrap: wrap; gap: 8px 16px; margin-top: 12px; font-size: 12px; color: var(--ink-2); }
        .legend-row .sw { width: 10px; height: 10px; border-radius: 3px; display: inline-block; margin-right: 6px; vertical-align: -1px; }
        .mix-list { list-style: none; padding: 0; margin: 14px 0 0; display: flex; flex-direction: column; gap: 8px; }
        .mix-list li { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: center; font-size: 13px; color: var(--ink-2); }
        .mix-list .sw { width: 10px; height: 10px; border-radius: 3px; }
        .mix-list .amt { font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; text-align: right; }
        .mix-list .pct { color: var(--muted); font-weight: 500; margin-left: 6px; }
        .mini-note { font-size: 11.5px; color: var(--muted); margin-top: 10px; line-height: 1.5; }
        .link-btn { background: none; border: none; color: var(--navy-2); font-weight: 600; font-size: 12px; cursor: pointer; padding: 0; text-decoration: underline; text-underline-offset: 2px; }

        .bar-list { display: flex; flex-direction: column; gap: 12px; }
        .bar-row { display: grid; grid-template-columns: 96px minmax(0, 1fr) auto; gap: 12px; align-items: center; font-size: 13px; }
        .bar-row .bar-label { color: var(--ink-2); font-weight: 600; }
        .bar-track { height: 10px; background: #eef2f7; border-radius: 999px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 999px; transition: width .4s ease; min-width: 0; }
        .bar-row .bar-val { font-variant-numeric: tabular-nums; color: var(--ink); font-weight: 700; white-space: nowrap; }
        .bar-row .bar-val small { color: var(--muted); font-weight: 500; }
        .b-pending { background: #f59e0b; } .b-confirmed { background: #2563eb; } .b-completed { background: #0d9488; } .b-cancelled { background: #ef4444; }
        .b-paid { background: #059669; } .b-unpaid { background: #f59e0b; } .b-reservation_paid { background: #0891b2; } .b-rebook_required { background: #7c3aed; }

        @media (max-width: 1279px) { .panel-grid.charts { grid-template-columns: 1fr; } }
        @media (max-width: 767px)  { .panel-grid.halves { grid-template-columns: 1fr; } .panel { padding: 16px; } .chart-box { height: 240px; } .bar-row { grid-template-columns: 80px minmax(0, 1fr) auto; gap: 8px; } }

        /* PERFORMANCE / CUSTOMERS */
        .perf-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        @media (max-width: 899px) { .perf-grid { grid-template-columns: 1fr; } }
        .mini-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .mini-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 480px; }
        .mini-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; color: var(--muted); font-weight: 600; padding: 6px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        .mini-table td { padding: 8px; border-bottom: 1px solid #f1f5f9; color: var(--ink-2); vertical-align: top; }
        .mini-table tr:last-child td { border-bottom: none; }
        .mini-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .mini-table .rank { color: var(--faint); width: 22px; }
        .mini-table .name { font-weight: 600; color: var(--ink); }
        .main-content .mini-table td.name { min-width: 150px; overflow-wrap: break-word; word-break: normal; }
        .main-content .mini-table td.name .sub { overflow-wrap: normal; }
        .mini-table .sub { display: block; font-size: 11px; color: var(--faint); font-weight: 500; }
        .empty-inline { padding: 22px 10px; text-align: center; color: var(--faint); font-size: 13px; }
        .cust-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .cust-stat { background: var(--bg-soft); border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; min-width: 0; }
        .cust-stat .v { font-size: 22px; font-weight: 800; color: var(--ink); font-variant-numeric: tabular-nums; }
        .cust-stat .l { font-size: 12px; color: var(--muted); font-weight: 600; }
        @media (max-width: 767px) { .cust-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .tag-ret { background: #e0f2fe; color: #0369a1; } .tag-new { background: #f1f5f9; color: #475569; }

        /* DATA QUALITY */
        .dq-list { display: flex; flex-direction: column; gap: 10px; }
        .dq-item { border: 1px solid var(--line); border-radius: 14px; background: #fff; overflow: hidden; }
        .dq-item summary { list-style: none; cursor: pointer; padding: 12px 16px; display: flex; gap: 12px; align-items: flex-start; }
        .dq-item summary::-webkit-details-marker { display: none; }
        .dq-item .lvl { flex-shrink: 0; width: 30px; height: 30px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .dq-item.warning .lvl { background: #fef3c7; color: #b45309; } .dq-item.info .lvl { background: #e0f2fe; color: #0369a1; } .dq-item.system .lvl { background: #ede9fe; color: #6d28d9; }
        .dq-item .dq-title { font-weight: 700; color: var(--ink); font-size: 14px; }
        .dq-item .dq-detail { font-size: 12.5px; color: var(--muted); line-height: 1.5; margin-top: 2px; }
        .dq-item .chev { margin-left: auto; color: var(--faint); transition: transform .2s; }
        .dq-item[open] .chev { transform: rotate(180deg); }
        .dq-item .dq-body { padding: 0 16px 14px; }
        .dq-ok { padding: 16px; display: flex; gap: 10px; align-items: center; color: var(--ok); font-weight: 600; font-size: 14px; }

        /* DETAILED REPORTS */
        .report-shell { padding: 18px 20px; }
        .report-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .report-tab { padding: 9px 16px; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all .2s; background: #fff; border: 2px solid var(--line); color: var(--muted); display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; font-family: inherit; }
        .report-tab:hover { background: #f0f7fb; border-color: var(--blue); color: var(--ink); }
        .report-tab.active { background: var(--blue); border-color: var(--blue); color: #fff; }
        .report-tabs-select { display: none; width: 100%; height: 44px; padding: 8px 12px; border: 2px solid var(--line); border-radius: 10px; font-weight: 600; color: var(--ink); background: #fff; margin-bottom: 12px; font-family: inherit; font-size: 14px; }
        @media (max-width: 767px) { .report-tabs { display: none; } .report-tabs-select { display: block; } .report-shell { padding: 14px; } }
        .report-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .report-toolbar h3 { font-size: 16px; font-weight: 700; color: var(--ink); margin: 0; display: flex; gap: 8px; align-items: center; }
        .report-toolbar h3 i { color: var(--blue); }
        .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .search-box { position: relative; }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--faint); font-size: 13px; }
        .search-box input { height: 40px; padding: 8px 12px 8px 34px; border: 2px solid var(--line); border-radius: 10px; font-size: 13px; width: 220px; max-width: 100%; font-family: inherit; }
        .search-box input:focus { outline: none; border-color: var(--blue); }
        .page-size { height: 40px; border: 2px solid var(--line); border-radius: 10px; padding: 0 8px; font-size: 13px; background: #fff; font-family: inherit; }
        .btn-action { height: 40px; padding: 0 16px; border: none; border-radius: 10px; font-weight: 600; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: #fff; transition: transform .2s, box-shadow .2s, opacity .2s; font-family: inherit; white-space: nowrap; }
        .btn-action:hover { transform: translateY(-1px); }
        .btn-action:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        .btn-csv { background: #10b981; } .btn-csv:hover { box-shadow: 0 4px 12px rgba(16,185,129,.3); }
        .btn-print { background: var(--navy-2); } .btn-print:hover { box-shadow: 0 4px 12px rgba(11,61,145,.3); }
        @media (max-width: 767px) {
            .report-toolbar { flex-direction: column; align-items: stretch; }
            .toolbar-actions { display: grid; grid-template-columns: 1fr 1fr; }
            .toolbar-actions .search-box { grid-column: 1 / -1; }
            .search-box input { width: 100%; }
            .btn-action { justify-content: center; }
        }
        .report-info { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px 14px; padding: 9px 14px; background: var(--bg-soft); border-radius: 10px; font-size: 12.5px; color: var(--muted); margin-bottom: 10px; }
        .report-info strong { color: var(--ink); }
        .report-notes { margin: 0 0 10px; padding-left: 18px; font-size: 12px; color: var(--muted); line-height: 1.55; }
        .table-wrap { position: relative; overflow: auto; max-height: 560px; border: 1px solid var(--line); border-radius: 12px; -webkit-overflow-scrolling: touch; min-height: 160px; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 720px; }
        .data-table thead th { position: sticky; top: 0; z-index: 2; background: var(--navy); color: #fff; padding: 10px 12px; text-align: left; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
        .data-table thead th.sortable { cursor: pointer; user-select: none; }
        .data-table thead th.sortable:hover { background: var(--navy-2); }
        .data-table thead th .s-ind { opacity: .5; margin-left: 4px; font-size: 10px; }
        .data-table thead th.sorted .s-ind { opacity: 1; color: var(--blue-2); }
        .data-table td { padding: 9px 12px; border-bottom: 1px solid var(--line); color: #475569; white-space: nowrap; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
        .data-table td.wrap { white-space: normal; min-width: 220px; }
        .data-table th.num, .data-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .data-table tbody tr:hover { background: var(--bg-soft); }
        .data-table tfoot td { position: sticky; bottom: 0; background: #eef6fc; color: var(--ink); font-weight: 700; border-top: 2px solid var(--blue); }
        .badge-s { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .s-paid { background: #d1fae5; color: #047857; } .s-pending { background: #fef3c7; color: #b45309; } .s-cancelled { background: #fee2e2; color: #b91c1c; }
        .s-reservation-fee-paid { background: #cffafe; color: #0e7490; } .s-fully-paid { background: #d1fae5; color: #047857; } .s-pending-payment { background: #fef3c7; color: #b45309; }
        .s-rebooking-required { background: #ede9fe; color: #6d28d9; } .s-cancelled-unpaid, .s-payment-rejected { background: #fee2e2; color: #b91c1c; } .s-confirmed { background: #dbeafe; color: #1d4ed8; } .s-completed { background: #ccfbf1; color: #0f766e; }
        .table-state { padding: 46px 16px; text-align: center; color: var(--faint); font-size: 13.5px; }
        .table-state i { font-size: 30px; display: block; margin-bottom: 10px; color: #cbd5e1; }
        .table-state.error i { color: #ef4444; }
        .spinner { width: 36px; height: 36px; border: 4px solid var(--line); border-top-color: var(--blue); border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 10px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .pager { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 12px; font-size: 12.5px; color: var(--muted); }
        .pager-btns { display: flex; gap: 6px; align-items: center; }
        .pager button { height: 34px; min-width: 34px; padding: 0 10px; border: 2px solid var(--line); background: #fff; border-radius: 8px; cursor: pointer; color: var(--ink); font-weight: 600; font-family: inherit; }
        .pager button:disabled { opacity: .4; cursor: not-allowed; }
        .pager button:not(:disabled):hover { border-color: var(--blue); }

        /* FOOTER */
        .footer { background: #0B2447; color: #b3d9ff; padding: 15px 10px; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15); }
        .footer i { color: #4DA6D9; }
        @media (max-width: 768px) { .footer { font-size: 11px; padding: 12px 10px; border-radius: 10px; margin-top: 20px; } }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }

        /* PRINT (A4) — only the generated report is printed */
        #printArea { display: none; }
        @media print {
            @page { size: A4 portrait; margin: 10mm 8mm; }
            html, body { background: #fff !important; }
            .app-container, .menu-toggle, .sidebar-overlay, .logout-modal-overlay { display: none !important; }
            #printArea { display: block !important; font-family: 'Inter', Arial, sans-serif; color: #0f172a; }
            .pr-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 2px solid #0B2447; padding-bottom: 8px; margin-bottom: 10px; }
            .pr-brand { display: flex; gap: 10px; align-items: center; }
            .pr-brand img { max-height: 42px; }
            .pr-brand h1 { font-size: 15pt; margin: 0; color: #0B2447; }
            .pr-brand p, .pr-meta p { font-size: 8.5pt; margin: 1px 0; color: #475569; }
            .pr-meta { text-align: right; }
            .pr-meta h2 { font-size: 12.5pt; margin: 0 0 2px; color: #0B2447; }
            .pr-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin: 8px 0 10px; }
            .pr-kpi { border: 1px solid #cbd5e1; border-radius: 6px; padding: 5px 7px; break-inside: avoid; }
            .pr-kpi .l { font-size: 7pt; color: #475569; text-transform: uppercase; letter-spacing: .2px; }
            .pr-kpi .v { font-size: 10.5pt; font-weight: 700; color: #0B2447; }
            .pr-kpi .s { font-size: 6.5pt; color: #64748b; }
            .pr-table { width: 100%; border-collapse: collapse; font-size: 7pt; table-layout: auto; }
            .pr-table thead { display: table-header-group; }
            .pr-table tfoot { display: table-row-group; }
            .pr-table th { background: #0B2447 !important; color: #fff !important; padding: 4px 4px; text-align: left; font-size: 6.5pt; text-transform: uppercase; border: 1px solid #1e3a5f; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .pr-table td { padding: 3px 4px; border: 1px solid #d1dbe5; vertical-align: top; word-break: break-word; }
            .pr-table .num { text-align: right; white-space: nowrap; }
            .pr-table tr { break-inside: avoid; }
            .pr-table tbody tr:nth-child(even) td { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .pr-table tfoot td { font-weight: 700; background: #eef6fc !important; border-top: 2px solid #0B2447; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .pr-notes { font-size: 7.5pt; color: #475569; margin: 8px 0 0; padding-left: 14px; }
            .pr-foot { margin-top: 10px; border-top: 1px solid #cbd5e1; padding-top: 5px; text-align: center; font-size: 7.5pt; color: #64748b; }
            .pr-section { font-size: 9pt; font-weight: 700; color: #0B2447; margin: 8px 0 4px; }
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
            .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }
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

            <li class="nav-item"><a href="reports.php" class="nav-link active"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li>

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
                    <i class="fas fa-chart-line"></i> Sales Analytics &amp; Reports

                    <span class="mobile-role-badge <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                        <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo $is_admin ? 'Admin' : 'Staff'; ?>
                    </span>
                    <span class="mobile-avatar" title="<?php echo htmlspecialchars($admin_display_name); ?>">
                        <?php if($admin_avatar): ?>
                            <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo htmlspecialchars($admin_initial); ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <p>Monitor revenue, bookings, payments, customer activity, and service performance.</p>
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
                        <?php echo htmlspecialchars($admin_initial); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($page_error): ?>
            <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($page_error); ?></span></div>
        <?php endif; ?>

        <!-- FILTERS -->
        <section class="filter-card card-shell" aria-label="Report filters">
            <form id="filterForm" novalidate>
                <div class="filter-grid">
                    <div class="fg">
                        <label for="range_type"><i class="fas fa-bolt"></i> Quick range</label>
                        <select id="range_type">
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="this_week">This Week</option>
                            <option value="last_7">Last 7 Days</option>
                            <option value="this_month">This Month</option>
                            <option value="last_30">Last 30 Days</option>
                            <option value="this_quarter">This Quarter</option>
                            <option value="this_year">This Year</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label for="date_from">From</label>
                        <input type="date" id="date_from" min="2000-01-01" max="2100-12-31" value="<?php echo htmlspecialchars($init_filters['date_from']); ?>">
                    </div>
                    <div class="fg">
                        <label for="date_to">To</label>
                        <input type="date" id="date_to" min="2000-01-01" max="2100-12-31" value="<?php echo htmlspecialchars($init_filters['date_to']); ?>">
                    </div>
                    <div class="fg">
                        <label for="f_service">Service</label>
                        <select id="f_service">
                            <option value="all">All</option>
                            <option value="house">House</option>
                            <option value="tour">Tour</option>
                            <option value="food">Food</option>
                            <option value="package">Package</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label for="f_payment">Payment status</label>
                        <select id="f_payment">
                            <option value="all">All</option>
                            <option value="unpaid">Awaiting reservation fee</option>
                            <option value="reservation_paid">Reservation fee paid</option>
                            <option value="paid">Fully paid</option>
                            <option value="rebook_required">Rebooking required (credit)</option>
                            <option value="cancelled">Cancelled / rejected (unpaid)</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label for="f_status">Booking status</label>
                        <select id="f_status">
                            <option value="all">All</option>
                            <option value="pending">Pending</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="fg fg-actions">
                        <button type="button" class="btn-clear" id="btnReset"><i class="fas fa-undo"></i> Reset</button>
                    </div>
                </div>
                <div class="filter-meta">
                    <span class="period-pill" id="periodLabel"><i class="fas fa-calendar-alt"></i> —</span>
                    <span id="compareLabel"></span>
                    <span class="filter-error" id="filterError" role="alert" hidden></span>
                </div>
            </form>
        </section>

        <!-- EXECUTIVE KPIs -->
        <div class="kpi-grid loading" id="kpiGrid" aria-live="polite">
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Cash Collected
                    <span class="info-tip" tabindex="0" aria-label="About Cash Collected"><i class="fas fa-info-circle"></i><span class="tip">Money actually received in the selected period, by the date it was received: reservation fees plus balance payments. Each package counts once. Unpaid booking totals are never counted as cash.</span></span>
                </span><span class="kpi-icon i-green"><i class="fas fa-peso-sign"></i></span></div>
                <div class="kpi-value" id="k_net">₱0.00</div>
                <div class="kpi-sub" id="s_net">By date received</div>
                <div class="kpi-delta na" id="d_net"></div>
            </div>
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Reservation Fees Collected
                    <span class="info-tip" tabindex="0" aria-label="About reservation fees"><i class="fas fa-info-circle"></i><span class="tip">₱1,000 per booking or package (or the full total if it is below ₱1,000), received in the selected period. Fees are non-refundable and are carried forward when a guest rebooks — never charged twice.</span></span>
                </span><span class="kpi-icon i-cyan"><i class="fas fa-receipt"></i></span></div>
                <div class="kpi-value" id="k_fees">₱0.00</div>
                <div class="kpi-sub" id="s_fees">0 fees received</div>
                <div class="kpi-delta na" id="d_fees"></div>
            </div>
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Confirmed Booking Value
                    <span class="info-tip" tabindex="0" aria-label="About booking value"><i class="fas fa-info-circle"></i><span class="tip">Full price of bookings created in the period whose reservation fee has been received and that are not cancelled. This is business secured, not cash in hand.</span></span>
                </span><span class="kpi-icon i-blue"><i class="fas fa-calendar-check"></i></span></div>
                <div class="kpi-value" id="k_value">₱0.00</div>
                <div class="kpi-sub" id="s_value">0 secured bookings</div>
                <div class="kpi-delta na" id="d_value"></div>
            </div>
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Remaining Balance
                    <span class="info-tip" tabindex="0" aria-label="About remaining balance"><i class="fas fa-info-circle"></i><span class="tip">Still to be collected on arrival for the secured bookings above (Booking Value minus what has been paid). See the Outstanding Balances report for every booking that still owes, across all dates.</span></span>
                </span><span class="kpi-icon i-amber"><i class="fas fa-hand-holding-usd"></i></span></div>
                <div class="kpi-value" id="k_bal">₱0.00</div>
                <div class="kpi-sub" id="s_bal">To collect on arrival</div>
                <div class="kpi-delta na" id="d_bal"></div>
            </div>
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Fully Paid Bookings</span><span class="kpi-icon i-violet"><i class="fas fa-check-double"></i></span></div>
                <div class="kpi-value" id="k_paid">0</div>
                <div class="kpi-sub" id="s_paid">Created in period, nothing left to pay</div>
                <div class="kpi-delta na" id="d_paid"></div>
            </div>
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Awaiting Reservation Fee</span><span class="kpi-icon i-red"><i class="fas fa-hourglass-half"></i></span></div>
                <div class="kpi-value" id="k_pend">₱0.00</div>
                <div class="kpi-sub" id="s_pend">0 unpaid reservations</div>
                <div class="kpi-delta na" id="d_pend"></div>
            </div>
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Reservations</span><span class="kpi-icon i-navy"><i class="fas fa-clipboard-list"></i></span></div>
                <div class="kpi-value" id="k_res">0</div>
                <div class="kpi-sub" id="s_res">Active reservations created in period</div>
                <div class="kpi-delta na" id="d_res"></div>
            </div>
            <div class="kpi-card card-shell">
                <div class="kpi-head"><span class="kpi-label">Cancellation Rate</span><span class="kpi-icon i-red"><i class="fas fa-ban"></i></span></div>
                <div class="kpi-value" id="k_cancel">—</div>
                <div class="kpi-sub" id="s_cancel">Of reservations created in period</div>
                <div class="kpi-delta na" id="d_cancel"></div>
            </div>
        </div>

        <div class="kpi-strip loading" id="kpiStrip">
            <div class="strip-item card-shell" id="stripCredit">
                <span class="kpi-icon i-violet"><i class="fas fa-redo"></i></span>
                <div class="strip-body">
                    <div class="strip-label">Rebooking Credits Held
                        <span class="info-tip" tabindex="0" aria-label="About rebooking credits"><i class="fas fa-info-circle"></i><span class="tip">As of today, all dates. Money paid on bookings that were later cancelled (payments are non-refundable and are kept for rebooking), plus any excess paid over a lower rebooked total. Not counted again when used.</span></span>
                    </div>
                    <div class="strip-value" id="k_credit">₱0.00</div>
                    <div class="strip-sub" id="s_credit">As of today</div>
                </div>
            </div>
            <div class="strip-item card-shell" id="stripOut">
                <span class="kpi-icon i-amber"><i class="fas fa-file-invoice-dollar"></i></span>
                <div class="strip-body">
                    <div class="strip-label">Outstanding Balances
                        <span class="info-tip" tabindex="0" aria-label="About outstanding balances"><i class="fas fa-info-circle"></i><span class="tip">As of today, all dates. Bookings whose reservation fee was received but whose balance is still unpaid — including completed stays where the balance was never recorded.</span></span>
                    </div>
                    <div class="strip-value" id="k_out">₱0.00</div>
                    <div class="strip-sub" id="s_out">As of today</div>
                </div>
            </div>
            <div class="strip-item card-shell">
                <span class="kpi-icon i-sky"><i class="fas fa-users"></i></span>
                <div class="strip-body">
                    <div class="strip-label">Guests / Pax Served
                        <span class="info-tip" tabindex="0" aria-label="About Guests served"><i class="fas fa-info-circle"></i><span class="tip">Guests on non-cancelled house, tour and package bookings whose service date falls in the period. A package's guests are counted once. Food orders are excluded (quantity, not guests).</span></span>
                    </div>
                    <div class="strip-value" id="k_pax">0</div>
                    <div class="strip-sub" id="s_pax">By service date</div>
                </div>
            </div>
            <div class="strip-item card-shell">
                <span class="kpi-icon i-navy"><i class="fas fa-user-plus"></i></span>
                <div class="strip-body">
                    <div class="strip-label">New Guest Accounts</div>
                    <div class="strip-value" id="k_new">0</div>
                    <div class="strip-sub" id="s_new">Role = guest (staff/admin excluded)</div>
                </div>
            </div>
            <div class="strip-item card-shell" id="stripMissing">
                <span class="kpi-icon i-red"><i class="fas fa-calendar-times"></i></span>
                <div class="strip-body">
                    <div class="strip-label">Payments Without a Date
                        <span class="info-tip" tabindex="0" aria-label="About undated payments"><i class="fas fa-info-circle"></i><span class="tip">Money recorded without a date received, so it cannot be placed in Cash Collected. No date is assumed. See Data Quality below.</span></span>
                    </div>
                    <div class="strip-value" id="k_miss">₱0.00</div>
                    <div class="strip-sub" id="s_miss">0 bookings</div>
                </div>
            </div>
        </div>

        <!-- REVENUE CHARTS -->
        <div class="panel-grid charts">
            <section class="panel card-shell" aria-labelledby="trendTitle">
                <div class="panel-head">
                    <div>
                        <h3 id="trendTitle"><i class="fas fa-chart-bar"></i> Cash Collected Trend</h3>
                        <div class="panel-note" id="trendNote">Money received, by date received</div>
                    </div>
                    <button type="button" class="link-btn" data-goto-report="revenue">View daily table</button>
                </div>
                <div class="chart-box"><canvas id="trendChart" role="img" aria-label="Cash collected trend chart by service"></canvas><div class="panel-state" id="trendState"><div class="spinner"></div>Loading…</div></div>
                <div class="legend-row" id="trendLegend"></div>
            </section>
            <section class="panel card-shell" aria-labelledby="mixTitle">
                <div class="panel-head"><div><h3 id="mixTitle"><i class="fas fa-chart-pie"></i> Cash Mix</h3><div class="panel-note">Share of Cash Collected by transaction type</div></div></div>
                <div class="chart-box donut"><canvas id="mixChart" role="img" aria-label="Cash mix by service"></canvas><div class="panel-state" id="mixState"><div class="spinner"></div>Loading…</div></div>
                <ul class="mix-list" id="mixList"></ul>
                <div class="mini-note" id="mixNote"></div>
            </section>
        </div>

        <!-- STATUS DISTRIBUTION -->
        <div class="panel-grid halves">
            <section class="panel card-shell">
                <div class="panel-head"><div><h3><i class="fas fa-clipboard-check"></i> Booking Status</h3><div class="panel-note" id="bsNote">Reservations created in period</div></div></div>
                <div class="bar-list" id="bookingStatus"><div class="empty-inline">Loading…</div></div>
            </section>
            <section class="panel card-shell">
                <div class="panel-head"><div><h3><i class="fas fa-credit-card"></i> Payment Status</h3><div class="panel-note" id="psNote">Reservations created in period</div></div></div>
                <div class="bar-list" id="paymentStatus"><div class="empty-inline">Loading…</div></div>
            </section>
        </div>

        <!-- SERVICE PERFORMANCE -->
        <div class="section-title"><h2><i class="fas fa-trophy"></i> Service Performance</h2><span class="section-sub">Top 5 by secured booking value (fee received) for bookings created in the period · House/Tour/Food include package parts (service view)</span></div>
        <div class="perf-grid">
            <section class="panel card-shell"><div class="panel-head"><h3><i class="fas fa-home"></i> Top Houses</h3></div><div class="mini-table-wrap" id="perfHouses"><div class="empty-inline">Loading…</div></div></section>
            <section class="panel card-shell"><div class="panel-head"><h3><i class="fas fa-umbrella-beach"></i> Top Tours</h3></div><div class="mini-table-wrap" id="perfTours"><div class="empty-inline">Loading…</div></div></section>
            <section class="panel card-shell"><div class="panel-head"><h3><i class="fas fa-utensils"></i> Top Food Products</h3></div><div class="mini-table-wrap" id="perfFood"><div class="empty-inline">Loading…</div></div></section>
            <section class="panel card-shell"><div class="panel-head"><h3><i class="fas fa-box-open"></i> Package Sales</h3></div><div class="mini-table-wrap" id="perfPackages"><div class="empty-inline">Loading…</div></div></section>
        </div>

        <!-- CUSTOMER ANALYTICS -->
        <div class="section-title"><h2><i class="fas fa-user-friends"></i> Customer Analytics</h2><span class="section-sub">Names and totals only — no contact, ID or health details</span></div>
        <section class="panel card-shell">
            <div class="cust-stats">
                <div class="cust-stat"><div class="v" id="c_new">0</div><div class="l">New guest registrations</div></div>
                <div class="cust-stat"><div class="v" id="c_unique">0</div><div class="l">Unique paying customers</div></div>
                <div class="cust-stat"><div class="v" id="c_ret">0</div><div class="l">Returning customers <span class="info-tip" tabindex="0" aria-label="About returning customers"><i class="fas fa-info-circle"></i><span class="tip">Customers who paid money in this period and had also paid before the period started.</span></span></div></div>
                <div class="cust-stat"><div class="v" id="c_rate">—</div><div class="l">Returning share</div></div>
            </div>
            <div class="panel-head"><h3><i class="fas fa-crown"></i> Top Customers by Cash Collected</h3><button type="button" class="link-btn" data-goto-report="users">View all customers</button></div>
            <div class="mini-table-wrap" id="topCustomers"><div class="empty-inline">Loading…</div></div>
        </section>

        <!-- DATA QUALITY -->
        <div class="section-title"><h2><i class="fas fa-shield-alt"></i> Data Quality &amp; System Notices</h2><span class="section-sub">Records the report could not classify with certainty</span></div>
        <section class="card-shell" style="padding: 14px;"><div class="dq-list" id="dqList"><div class="empty-inline">Loading…</div></div></section>

        <!-- DETAILED REPORTS -->
        <div class="section-title" id="detailedReports"><h2><i class="fas fa-table"></i> Detailed Reports</h2><span class="section-sub">Same filters as the dashboard · CSV and Print use exactly these figures</span></div>
        <section class="report-shell card-shell">
            <div class="report-tabs" id="reportTabs" role="tablist">
                <button type="button" class="report-tab" data-report="bookings" role="tab"><i class="fas fa-list"></i> Transactions</button>
                <button type="button" class="report-tab" data-report="revenue" role="tab"><i class="fas fa-money-bill-wave"></i> Cash</button>
                <button type="button" class="report-tab" data-report="balances" role="tab"><i class="fas fa-file-invoice-dollar"></i> Balances</button>
                <button type="button" class="report-tab" data-report="credits" role="tab"><i class="fas fa-redo"></i> Credits</button>
                <button type="button" class="report-tab" data-report="users" role="tab"><i class="fas fa-users"></i> Customers</button>
                <button type="button" class="report-tab" data-report="houses" role="tab"><i class="fas fa-home"></i> Houses</button>
                <button type="button" class="report-tab" data-report="tours" role="tab"><i class="fas fa-umbrella-beach"></i> Tours</button>
                <button type="button" class="report-tab" data-report="food" role="tab"><i class="fas fa-utensils"></i> Food</button>
                <button type="button" class="report-tab" data-report="packages" role="tab"><i class="fas fa-box-open"></i> Packages</button>
                <button type="button" class="report-tab" data-report="activities" role="tab"><i class="fas fa-water"></i> Activities</button>
                <button type="button" class="report-tab" data-report="feedback" role="tab"><i class="fas fa-star"></i> Feedback</button>
            </div>
            <label for="reportSelect" class="visually-hidden" style="position:absolute;left:-9999px;">Report type</label>
            <select class="report-tabs-select" id="reportSelect">
                <option value="bookings">Transactions</option>
                <option value="revenue">Cash Collected</option>
                <option value="balances">Outstanding Balances</option>
                <option value="credits">Rebooking Credits</option>
                <option value="users">Customers</option>
                <option value="houses">Houses</option>
                <option value="tours">Tours</option>
                <option value="food">Food</option>
                <option value="packages">Packages</option>
                <option value="activities">Activities</option>
                <option value="feedback">Feedback</option>
            </select>

            <div class="report-toolbar">
                <h3><i class="fas fa-table"></i> <span id="reportTitle">Report</span></h3>
                <div class="toolbar-actions">
                    <div class="search-box"><i class="fas fa-search"></i><input type="search" id="tableSearch" placeholder="Search this report…" maxlength="100" aria-label="Search this report"></div>
                    <select class="page-size" id="pageSize" aria-label="Rows per page">
                        <option value="25">25 / page</option>
                        <option value="50" selected>50 / page</option>
                        <option value="100">100 / page</option>
                        <option value="0">All rows</option>
                    </select>
                    <button type="button" class="btn-action btn-csv" id="btnExport"><i class="fas fa-file-csv"></i> Export CSV</button>
                    <button type="button" class="btn-action btn-print" id="btnPrint"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
            <div class="report-info"><span id="reportCount">—</span><span id="reportRange"></span></div>
            <ul class="report-notes" id="reportNotes"></ul>
            <div class="table-wrap" id="tableWrap"><div class="table-state"><div class="spinner"></div>Loading report…</div></div>
            <div class="pager" id="pager"></div>
        </section>

        <!-- Footer -->
        <div class="footer">
            <p style="margin:0;">
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

<!-- Print output (built from the loaded report data; hidden on screen) -->
<div id="printArea" aria-hidden="true">
    <div class="pr-head">
        <div class="pr-brand">
            <?php if($nav_logo_exists): ?><img src="<?php echo htmlspecialchars($nav_logo); ?>" alt=""><?php endif; ?>
            <div>
                <h1><?php echo htmlspecialchars($site_name); ?></h1>
                <p>Hundred Islands Reservation System</p>
            </div>
        </div>
        <div class="pr-meta">
            <h2 id="prTitle">Sales Report</h2>
            <p id="prPeriod"></p>
            <p id="prFilters"></p>
            <p id="prGenerated"></p>
        </div>
    </div>
    <div class="pr-section">Summary</div>
    <div class="pr-kpis" id="prKpis"></div>
    <div class="pr-section" id="prTableTitle"></div>
    <div id="prTable"></div>
    <ul class="pr-notes" id="prNotes"></ul>
    <div class="pr-foot">Computer-generated report · Cash Collected = money actually received in the period (reservation fees + balances). Booking Value is the full price of secured bookings and is not cash. Payments are non-refundable; money on cancelled bookings is held as rebooking credit.</div>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
<script>
window.REPORT_INIT = <?php echo json_encode($report_init, $json_flags); ?>;
</script>
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
</script>
<script>
// ============================================================
// SALES ANALYTICS — client
// All figures come from the server (reports.php → includes/Exporter.php).
// The client only formats, sorts, searches and paginates them.
// ============================================================
(function () {
    'use strict';

    var INIT = window.REPORT_INIT;
    var TZ = 'Asia/Manila';
    var COLORS = { house: '#2563eb', tour: '#0891b2', food: '#d97706', package: '#7c3aed' };
    var TYPE_LABEL = { house: 'House', tour: 'Tour', food: 'Food', package: 'Package' };
    var SERVICE_LABEL = { all: 'All services', house: 'House', tour: 'Tour', food: 'Food', package: 'Package' };
    var PAYMENT_LABEL = { all: 'All', unpaid: 'Awaiting reservation fee', reservation_paid: 'Reservation fee paid', paid: 'Fully paid', rebook_required: 'Rebooking required (credit)', cancelled: 'Cancelled / rejected (unpaid)' };
    var STATUS_LABEL = { all: 'All', pending: 'Pending', confirmed: 'Confirmed', completed: 'Completed', cancelled: 'Cancelled' };
    var RANGE_LABEL = { today: 'Today', yesterday: 'Yesterday', this_week: 'This Week', last_7: 'Last 7 Days', this_month: 'This Month', last_30: 'Last 30 Days', this_quarter: 'This Quarter', this_year: 'This Year', custom: 'Custom' };

    var state = {
        filters: Object.assign({}, INIT.filters),
        report: INIT.report,
        analytics: null, analyticsKey: null,
        table: null, tableKey: null, tableMeta: null,
        search: '', sortCol: null, sortDir: 1, page: 1, pageSize: 50
    };
    var charts = { trend: null, mix: null };
    var reqSeq = { analytics: 0, table: 0 };
    var controllers = { analytics: null, table: null };

    // ---------- helpers ----------
    var $ = function (id) { return document.getElementById(id); };
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    var pesoFmt = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var intFmt = new Intl.NumberFormat('en-PH');
    function peso(v) {
        if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
        var n = Number(v);
        return (n < 0 ? '−₱' : '₱') + pesoFmt.format(Math.abs(n));
    }
    function int(v) { return (v === null || v === undefined || v === '' || isNaN(v)) ? '—' : intFmt.format(Number(v)); }
    function pct(v) { return (v === null || v === undefined) ? '—' : (Number(v).toFixed(1) + '%'); }
    function toCents(v) { return Math.round(Number(v) * 100); }
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function fmtDate(s) {
        if (!s) return '—';
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
        if (!m) return s;
        return MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
    }
    function fmtDateTime(s) {
        if (!s) return '—';
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s);
        if (!m) return fmtDate(s);
        var h = parseInt(m[4], 10), ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return fmtDate(s) + ' ' + h + ':' + m[5] + ' ' + ap;
    }
    function fmtServiceDate(s) {
        if (!s) return '—';
        var parts = String(s).split(' → ');
        return parts.map(fmtDate).join(' → ');
    }
    function manilaToday() {
        var p = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
        var m = /(\d{4})-(\d{2})-(\d{2})/.exec(p);
        return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    }
    function manilaNowText() {
        return new Intl.DateTimeFormat('en-US', { timeZone: TZ, year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date()) + ' (Asia/Manila)';
    }
    function ymd(d) { return d.toISOString().slice(0, 10); }
    function addDays(d, n) { var x = new Date(d.getTime()); x.setUTCDate(x.getUTCDate() + n); return x; }
    function parseYmd(s) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(s || '')) return null;
        var p = s.split('-').map(Number);
        var d = new Date(Date.UTC(p[0], p[1] - 1, p[2]));
        return (d.getUTCFullYear() === p[0] && d.getUTCMonth() === p[1] - 1 && d.getUTCDate() === p[2]) ? d : null;
    }

    function rangeFor(key) {
        var t = manilaToday(), y = t.getUTCFullYear(), mo = t.getUTCMonth();
        switch (key) {
            case 'today': return [t, t];
            case 'yesterday': var yd = addDays(t, -1); return [yd, yd];
            case 'this_week': var dow = (t.getUTCDay() + 6) % 7; var mon = addDays(t, -dow); return [mon, addDays(mon, 6)];
            case 'last_7': return [addDays(t, -6), t];
            case 'this_month': return [new Date(Date.UTC(y, mo, 1)), new Date(Date.UTC(y, mo + 1, 0))];
            case 'last_30': return [addDays(t, -29), t];
            case 'this_quarter': var q = Math.floor(mo / 3) * 3; return [new Date(Date.UTC(y, q, 1)), new Date(Date.UTC(y, q + 3, 0))];
            case 'this_year': return [new Date(Date.UTC(y, 0, 1)), new Date(Date.UTC(y, 11, 31))];
        }
        return null;
    }

    function filterKey(f) { return [f.date_from, f.date_to, f.service, f.payment, f.status].join('|'); }
    function filterQuery(f) {
        return 'date_from=' + encodeURIComponent(f.date_from) + '&date_to=' + encodeURIComponent(f.date_to) +
               '&service=' + encodeURIComponent(f.service) + '&payment=' + encodeURIComponent(f.payment) + '&status=' + encodeURIComponent(f.status);
    }
    function filterSummary(f) { return 'Service: ' + SERVICE_LABEL[f.service] + ' · Payment: ' + PAYMENT_LABEL[f.payment] + ' · Booking status: ' + STATUS_LABEL[f.status]; }

    function fetchJson(url, kind) {
        if (controllers[kind]) { try { controllers[kind].abort(); } catch (e) {} }
        var ctrl = ('AbortController' in window) ? new AbortController() : null;
        controllers[kind] = ctrl;
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {
                        if (res.redirected || /<html/i.test(text)) throw new Error('Your session may have expired. Please reload the page and sign in again.');
                        throw new Error('The server returned an unexpected response.');
                    }
                    if (!res.ok || !data || data.success !== true) throw new Error((data && data.message) || ('Request failed (' + res.status + ').'));
                    return data;
                });
            });
    }

    // ---------- filters ----------
    function readFilters() {
        return {
            date_from: $('date_from').value, date_to: $('date_to').value,
            service: $('f_service').value, payment: $('f_payment').value, status: $('f_status').value,
            range: $('range_type').value
        };
    }
    function writeFilters(f) {
        $('date_from').value = f.date_from; $('date_to').value = f.date_to;
        $('f_service').value = f.service; $('f_payment').value = f.payment; $('f_status').value = f.status;
        $('range_type').value = f.range || 'custom';
    }
    function validate(f) {
        var a = parseYmd(f.date_from), b = parseYmd(f.date_to);
        $('date_from').classList.toggle('is-invalid', !a);
        $('date_to').classList.toggle('is-invalid', !b);
        if (!a) return 'Please enter a valid "From" date.';
        if (!b) return 'Please enter a valid "To" date.';
        if (a.getUTCFullYear() < 2000 || b.getUTCFullYear() > 2100) return 'Dates must be between the years 2000 and 2100.';
        if (a > b) { $('date_from').classList.add('is-invalid'); $('date_to').classList.add('is-invalid'); return 'The "From" date must be on or before the "To" date.'; }
        var days = Math.round((b - a) / 86400000) + 1;
        if (days > INIT.maxDays) return 'Please choose a range of 3 years or less.';
        return null;
    }
    function showFilterError(msg) {
        var el = $('filterError');
        if (msg) { el.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + esc(msg); el.hidden = false; }
        else { el.hidden = true; el.textContent = ''; }
    }
    function updatePeriodLabel(f) {
        var label = (f.range && f.range !== 'custom' ? RANGE_LABEL[f.range] + ': ' : '') + fmtDate(f.date_from) + ' – ' + fmtDate(f.date_to);
        $('periodLabel').innerHTML = '<i class="fas fa-calendar-alt"></i> ' + esc(label);
    }
    function syncUrl() {
        if (!window.history.replaceState) return;
        var f = state.filters;
        var url = 'reports.php?' + filterQuery(f) + '&range_type=' + encodeURIComponent(f.range || 'custom') + '&report=' + encodeURIComponent(state.report);
        window.history.replaceState({}, '', url);
    }

    function applyFilters() {
        var f = readFilters();
        var err = validate(f);
        if (err) { showFilterError(err); return; }
        showFilterError(null);
        state.filters = f;
        updatePeriodLabel(f);
        syncUrl();
        loadAnalytics();
        loadTable();
    }

    // ---------- analytics ----------
    function setPanelState(id, html, cls) {
        var el = $(id);
        if (!el) return;
        if (html === null) { el.style.display = 'none'; return; }
        el.className = 'panel-state' + (cls ? ' ' + cls : '');
        el.innerHTML = html;
        el.style.display = 'flex';
    }

    function loadAnalytics() {
        var f = state.filters, key = filterKey(f), seq = ++reqSeq.analytics;
        $('kpiGrid').classList.add('loading'); $('kpiStrip').classList.add('loading');
        setPanelState('trendState', '<div class="spinner"></div>Loading…');
        setPanelState('mixState', '<div class="spinner"></div>Loading…');
        updatePrintAvailability();
        fetchJson('reports.php?action=analytics&' + filterQuery(f), 'analytics')
            .then(function (data) {
                if (seq !== reqSeq.analytics) return;
                state.analytics = data.analytics; state.analyticsKey = key;
                renderAnalytics(data.analytics);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (seq !== reqSeq.analytics) return;
                state.analytics = null; state.analyticsKey = null;
                renderAnalyticsError(err.message || 'Unable to load analytics.');
            })
            .then(function () {
                if (seq !== reqSeq.analytics) return;
                $('kpiGrid').classList.remove('loading'); $('kpiStrip').classList.remove('loading');
                updatePrintAvailability();
            });
    }

    function renderAnalyticsError(msg) {
        var html = '<i class="fas fa-exclamation-circle"></i>' + esc(msg);
        setPanelState('trendState', html, 'error');
        setPanelState('mixState', html, 'error');
        ['k_net', 'k_fees', 'k_value', 'k_bal', 'k_paid', 'k_pend', 'k_res', 'k_cancel', 'k_credit', 'k_out', 'k_pax', 'k_new', 'k_miss'].forEach(function (id) { $(id).textContent = '—'; });
        ['bookingStatus', 'paymentStatus', 'perfHouses', 'perfTours', 'perfFood', 'perfPackages', 'topCustomers', 'dqList'].forEach(function (id) {
            $(id).innerHTML = '<div class="empty-inline" style="color:#b91c1c;"><i class="fas fa-exclamation-circle"></i> ' + esc(msg) + '</div>';
        });
        $('mixList').innerHTML = ''; $('mixNote').textContent = ''; $('trendLegend').innerHTML = '';
        ['d_net', 'd_fees', 'd_value', 'd_bal', 'd_paid', 'd_pend', 'd_res', 'd_cancel'].forEach(function (id) { $(id).textContent = ''; });
    }

    function setDelta(id, change, opts) {
        opts = opts || {};
        var el = $(id);
        if (change === null || change === undefined) {
            el.className = 'kpi-delta na';
            el.textContent = opts.noBase || 'No comparable data in previous period';
            return;
        }
        var good = opts.inverse ? change < 0 : change > 0;
        var cls = change === 0 ? 'flat' : (good ? 'up' : 'down');
        var arrow = change > 0 ? 'fa-arrow-up' : (change < 0 ? 'fa-arrow-down' : 'fa-minus');
        var val = (change > 0 ? '+' : '') + change.toFixed(1) + (opts.points ? ' pts' : '%');
        el.className = 'kpi-delta ' + cls;
        el.innerHTML = '<i class="fas ' + arrow + '"></i> ' + esc(val) + ' vs previous period';
    }

    function renderAnalytics(a) {
        var k = a.kpis, c = a.changes;
        var plural = function (n, word) { return int(n) + ' ' + word + (n === 1 ? '' : 's'); };
        $('k_net').textContent = peso(k.cash_collected);
        $('s_net').textContent = 'Fees ' + peso(k.fees_collected) + ' · Balances ' + peso(k.balances_collected);
        $('k_fees').textContent = peso(k.fees_collected);
        $('s_fees').textContent = plural(k.fee_payments, 'fee') + ' received';
        $('k_value').textContent = peso(k.booking_value);
        $('s_value').textContent = plural(k.secured_count, 'secured booking') + (k.avg_booking_value === null ? '' : ' · avg ' + peso(k.avg_booking_value));
        $('k_bal').textContent = peso(k.remaining_balance);
        $('s_bal').textContent = k.balance_count ? plural(k.balance_count, 'booking') + ' to collect on arrival' : 'Nothing left to collect';
        $('k_paid').textContent = int(k.fully_paid_count);
        $('s_paid').textContent = k.fully_paid_count ? peso(k.fully_paid_value) + ' · nothing left to pay' : 'Created in period, nothing left to pay';
        $('k_pend').textContent = peso(k.pending_amount);
        $('s_pend').textContent = plural(k.pending_count, 'unpaid reservation') + (k.pending_past_service ? ' · ' + int(k.pending_past_service) + ' past service date' : '');
        $('k_res').textContent = int(k.reservations);
        $('s_res').textContent = int(k.reservations_total) + ' created · ' + int(k.cancelled_count) + ' cancelled';
        $('k_cancel').textContent = k.cancellation_rate === null ? '—' : pct(k.cancellation_rate);
        $('s_cancel').textContent = k.reservations_total ? (int(k.cancelled_count) + ' of ' + int(k.reservations_total) + ' reservations created') : 'No reservations created in period';
        $('k_credit').textContent = peso(k.credits_amount);
        $('s_credit').textContent = k.credits_count ? plural(k.credits_count, 'booking') + ' · as of today' : 'None · as of today';
        $('k_out').textContent = peso(k.outstanding_amount);
        $('s_out').textContent = k.outstanding_count ? plural(k.outstanding_count, 'booking') + (k.outstanding_completed ? ' · ' + int(k.outstanding_completed) + ' already completed' : '') : 'None · as of today';
        $('k_pax').textContent = int(k.pax_served);
        $('k_new').textContent = int(k.new_guests);
        $('k_miss').textContent = peso(k.undated_amount);
        $('s_miss').textContent = plural(k.undated_count, 'booking') + ' · no date assumed';
        $('stripOut').classList.toggle('attention', k.outstanding_completed > 0);
        $('stripMissing').classList.toggle('attention', k.undated_count > 0);

        setDelta('d_net', c.cash_collected);
        setDelta('d_fees', c.fees_collected);
        setDelta('d_value', c.booking_value);
        setDelta('d_bal', c.remaining_balance, { inverse: true });
        setDelta('d_paid', c.fully_paid_count);
        setDelta('d_pend', c.pending_amount, { inverse: true });
        setDelta('d_res', c.reservations);
        setDelta('d_cancel', c.cancellation_rate_pts, { inverse: true, points: true });
        $('compareLabel').textContent = 'Compared with ' + fmtDate(a.previous.date_from) + ' – ' + fmtDate(a.previous.date_to);

        renderTrend(a.trend);
        renderMix(a.mix);
        renderBars('bookingStatus', a.status.booking, a.status.total, 'b-');
        renderBars('paymentStatus', a.status.payment.filter(function (p) { return p.key !== 'rebook_required' || p.count > 0; }), a.status.total, 'b-');
        $('bsNote').textContent = int(a.status.total) + ' reservation' + (a.status.total === 1 ? '' : 's') + ' created in period';
        $('psNote').textContent = 'Same ' + int(a.status.total) + ' reservation' + (a.status.total === 1 ? '' : 's') + ', by payment status';
        renderPerf('perfHouses', a.performance.houses, 'Guests');
        renderPerf('perfTours', a.performance.tours, 'Guests');
        renderPerf('perfFood', a.performance.food, 'Qty');
        renderPackagePerf(a.performance.packages);
        renderCustomers(a.customers);
        renderQuality(a.quality);
    }

    function chartsAvailable() { return typeof window.Chart !== 'undefined'; }

    function renderTrend(t) {
        var legend = '';
        ['house', 'tour', 'food', 'package'].forEach(function (s) { legend += '<span><span class="sw" style="background:' + COLORS[s] + '"></span>' + TYPE_LABEL[s] + '</span>'; });
        $('trendLegend').innerHTML = legend + '<span style="margin-left:auto;font-weight:700;color:#0B2447;">Period total: ' + esc(peso(t.sum)) + '</span>';
        $('trendNote').textContent = 'Money received, by date received · grouped by ' + t.granularity;
        if (charts.trend) { charts.trend.destroy(); charts.trend = null; }
        if (!(t.sum > 0)) { setPanelState('trendState', '<i class="fas fa-chart-bar"></i>No money received in this period.'); return; }
        if (!chartsAvailable()) { setPanelState('trendState', '<i class="fas fa-exclamation-triangle"></i>Chart library could not be loaded. See the Cash table below — totals are unaffected.', 'error'); return; }
        setPanelState('trendState', null);
        var ds = ['house', 'tour', 'food', 'package'].map(function (s) {
            return { label: TYPE_LABEL[s], data: t.series[s], backgroundColor: COLORS[s], borderColor: '#ffffff', borderWidth: { top: 2 }, borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 42, stack: 'rev' };
        });
        charts.trend = new Chart($('trendChart'), {
            type: 'bar',
            data: { labels: t.labels, datasets: ds },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 300 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0B2447', padding: 10, cornerRadius: 8,
                        filter: function (item) { return item.parsed.y !== 0; },
                        callbacks: {
                            label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + peso(ctx.parsed.y); },
                            footer: function (items) { return items.length ? 'Total: ' + peso(t.total[items[0].dataIndex]) : ''; }
                        }
                    }
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { color: '#64748b', maxRotation: 0, autoSkip: true, autoSkipPadding: 10, font: { size: 11 } } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#eef2f7' }, border: { display: false },
                         ticks: { color: '#64748b', font: { size: 11 }, callback: function (v) { return '₱' + intFmt.format(v); } } }
                }
            }
        });
    }

    function renderMix(m) {
        if (charts.mix) { charts.mix.destroy(); charts.mix = null; }
        var list = '';
        m.items.forEach(function (it) {
            list += '<li><span class="sw" style="background:' + COLORS[it.key] + '"></span><span>' + esc(it.label) + ' <small style="color:#94a3b8;">(' + int(it.count) + ')</small></span>' +
                    '<span class="amt">' + esc(peso(it.amount)) + '<span class="pct">' + esc(pct(it.pct)) + '</span></span></li>';
        });
        $('mixList').innerHTML = list;
        $('mixNote').textContent = m.items[3] && m.items[3].amount > 0 ? 'Each package is counted once, inside Package (one reservation fee per package).' : '';
        if (!(m.total > 0)) { setPanelState('mixState', '<i class="fas fa-chart-pie"></i>No money received in this period.'); return; }
        if (!chartsAvailable()) { setPanelState('mixState', '<i class="fas fa-exclamation-triangle"></i>Chart unavailable — amounts listed below.', 'error'); return; }
        setPanelState('mixState', null);
        charts.mix = new Chart($('mixChart'), {
            type: 'doughnut',
            data: { labels: m.items.map(function (i) { return i.label; }), datasets: [{ data: m.items.map(function (i) { return i.amount; }), backgroundColor: m.items.map(function (i) { return COLORS[i.key]; }), borderColor: '#ffffff', borderWidth: 2, hoverOffset: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '64%', animation: { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#0B2447', padding: 10, cornerRadius: 8,
                        callbacks: { label: function (ctx) { var it = m.items[ctx.dataIndex]; return ' ' + it.label + ': ' + peso(it.amount) + ' (' + pct(it.pct) + ')'; } } }
                }
            }
        });
    }

    function renderBars(id, items, total, prefix) {
        if (!total) { $(id).innerHTML = '<div class="empty-inline"><i class="fas fa-inbox"></i> No reservations created in this period.</div>'; return; }
        var h = '';
        items.forEach(function (it) {
            h += '<div class="bar-row"><span class="bar-label">' + esc(it.label) + '</span>' +
                 '<div class="bar-track" role="img" aria-label="' + esc(it.label + ': ' + it.count + ' (' + pct(it.pct) + ')') + '"><div class="bar-fill ' + prefix + esc(it.key) + '" style="width:' + Math.max(0, Math.min(100, it.pct)) + '%"></div></div>' +
                 '<span class="bar-val">' + int(it.count) + ' <small>· ' + esc(pct(it.pct)) + '</small></span></div>';
        });
        $(id).innerHTML = h;
    }

    function renderPerf(id, rows, paxLabel) {
        if (!rows || !rows.length) { $(id).innerHTML = '<div class="empty-inline"><i class="fas fa-inbox"></i> No bookings or payments in this period.</div>'; return; }
        var h = '<table class="mini-table"><thead><tr><th class="rank">#</th><th>Name</th><th class="num">Bookings</th><th class="num">Fee Paid</th><th class="num">Secured Value</th><th class="num">Avg.</th></tr></thead><tbody>';
        rows.forEach(function (r, i) {
            h += '<tr><td class="rank">' + (i + 1) + '</td><td class="name">' + esc(r.name) +
                 '<span class="sub">' + int(r.pax) + ' ' + esc(paxLabel.toLowerCase()) + (r.pkg_bookings ? ' · ' + int(r.pkg_bookings) + ' via package' : '') + '</span></td>' +
                 '<td class="num">' + int(r.bookings) + '</td><td class="num">' + int(r.paid) + '</td><td class="num">' + esc(peso(r.revenue)) + '</td><td class="num">' + esc(r.avg === null ? '—' : peso(r.avg)) + '</td></tr>';
        });
        $(id).innerHTML = h + '</tbody></table>';
    }

    function renderPackagePerf(rows) {
        if (!rows || !rows.length) { $('perfPackages').innerHTML = '<div class="empty-inline"><i class="fas fa-inbox"></i> No package bookings in this period.</div>'; return; }
        var h = '<table class="mini-table"><thead><tr><th class="rank">#</th><th>Composition</th><th class="num">Active</th><th class="num">Cancelled</th><th class="num">Fee Paid</th><th class="num">Value</th></tr></thead><tbody>';
        rows.forEach(function (r, i) {
            h += '<tr><td class="rank">' + (i + 1) + '</td><td class="name">' + esc(r.name) + '<span class="sub">Avg. ' + esc(r.avg === null ? '—' : peso(r.avg)) + '</span></td>' +
                 '<td class="num">' + int(r.bookings) + '</td><td class="num">' + int(r.cancelled) + '</td><td class="num">' + int(r.paid) + '</td><td class="num">' + esc(peso(r.revenue)) + '</td></tr>';
        });
        $('perfPackages').innerHTML = h + '</tbody></table>';
    }

    function renderCustomers(c) {
        $('c_new').textContent = int(c.new_guests);
        $('c_unique').textContent = int(c.unique_paying);
        $('c_ret').textContent = int(c.returning);
        $('c_rate').textContent = c.returning_rate === null ? '—' : pct(c.returning_rate);
        if (!c.top.length) { $('topCustomers').innerHTML = '<div class="empty-inline"><i class="fas fa-inbox"></i> No paying customers in this period.</div>'; return; }
        var h = '<table class="mini-table"><thead><tr><th class="rank">#</th><th>Customer</th><th class="num">Payments</th><th class="num">Cash</th><th>Type</th><th>Last Payment</th></tr></thead><tbody>';
        c.top.forEach(function (r, i) {
            h += '<tr><td class="rank">' + (i + 1) + '</td><td class="name">' + esc(r.name) + '</td><td class="num">' + int(r.transactions) + '</td><td class="num">' + esc(peso(r.revenue)) + '</td>' +
                 '<td>' + (r.returning ? '<span class="tag tag-ret">Returning</span>' : '<span class="tag tag-new">First-time</span>') + '</td><td>' + esc(fmtDateTime(r.last_paid)) + '</td></tr>';
        });
        $('topCustomers').innerHTML = h + '</tbody></table>';
    }

    function renderQuality(list) {
        if (!list || !list.length) { $('dqList').innerHTML = '<div class="dq-ok"><i class="fas fa-check-circle"></i> No data-quality issues detected for this period.</div>'; return; }
        var icon = { warning: 'fa-exclamation-triangle', info: 'fa-info-circle', system: 'fa-server' };
        var h = '';
        list.forEach(function (n) {
            h += '<details class="dq-item ' + esc(n.level) + '"><summary><span class="lvl"><i class="fas ' + (icon[n.level] || 'fa-info-circle') + '"></i></span>' +
                 '<span><span class="dq-title">' + esc(n.title) + '</span><span class="dq-detail" style="display:block;">' + esc(n.detail) + '</span></span>' +
                 (n.items && n.items.length ? '<i class="fas fa-chevron-down chev"></i>' : '') + '</summary>';
            if (n.items && n.items.length) {
                h += '<div class="dq-body"><div class="mini-table-wrap"><table class="mini-table"><thead><tr><th>Reference</th><th>Type</th><th class="num">Amount</th><th>Status</th><th>Explanation</th></tr></thead><tbody>';
                n.items.forEach(function (it) {
                    h += '<tr><td class="name">' + esc(it.reference) + '</td><td>' + esc(it.type) + '</td><td class="num">' + esc(peso(it.amount)) + '</td><td>' + esc(it.status) + '</td><td>' + esc(it.note) + '</td></tr>';
                });
                h += '</tbody></table></div></div>';
            }
            h += '</details>';
        });
        $('dqList').innerHTML = h;
    }

    // ---------- detailed report table ----------
    var NUMERIC = { money: true, int: true, pct: true };
    function cellText(v, type) {
        if (v === null || v === undefined || v === '') return '—';
        switch (type) {
            case 'money': return peso(v);
            case 'int': return isNaN(v) ? String(v) : int(v);
            case 'pct': return pct(v);
            case 'date': return fmtDate(v);
            case 'datetime': return fmtDateTime(v);
            default: return String(v);
        }
    }
    function cellHtml(v, type, header) {
        if (type === 'status' && v) {
            var k = String(v).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            return '<span class="badge-s s-' + esc(k) + '">' + esc(v) + '</span>';
        }
        if (header === 'Service Date') return esc(fmtServiceDate(v));
        return esc(cellText(v, type));
    }
    // Mirrors ReportExporter::applySearch (case-insensitive "any raw cell contains")
    function matchesSearch(row, needle) {
        for (var i = 0; i < row.length; i++) {
            var c = row[i];
            if (c !== null && c !== undefined && String(c).toLowerCase().indexOf(needle) !== -1) return true;
        }
        return false;
    }
    function visibleRows() {
        var t = state.table; if (!t) return [];
        var rows = t.rows;
        var q = state.search.trim().toLowerCase();
        if (q) rows = rows.filter(function (r) { return matchesSearch(r, q); });
        if (state.sortCol !== null) {
            var i = state.sortCol, type = t.types[i], dir = state.sortDir;
            rows = rows.slice().sort(function (a, b) {
                var x = a[i], y = b[i];
                var xn = (x === null || x === undefined || x === ''), yn = (y === null || y === undefined || y === '');
                if (xn && yn) return 0; if (xn) return 1; if (yn) return -1;
                if (NUMERIC[type] && !isNaN(x) && !isNaN(y)) return (Number(x) - Number(y)) * dir;
                return String(x).localeCompare(String(y), 'en', { numeric: true, sensitivity: 'base' }) * dir;
            });
        }
        return rows;
    }
    function totalsFor(rows) {
        var t = state.table;
        if (!t || !t.sum || !t.sum.length) return null;
        if (!state.search.trim()) return t.totals;                 // server totals (full dataset)
        var tot = t.headers.map(function () { return null; });     // same rule as PHP computeTotals
        tot[0] = 'SUBTOTAL (' + rows.length + ' matching row' + (rows.length === 1 ? '' : 's') + ')';
        t.sum.forEach(function (i) {
            var acc = 0;
            rows.forEach(function (r) { var v = r[i]; if (v === null || v === '' || v === undefined) return; acc += (t.types[i] === 'money') ? toCents(v) : parseInt(v, 10) || 0; });
            tot[i] = (t.types[i] === 'money') ? acc / 100 : acc;
        });
        return tot;
    }

    function setTableState(html, cls) { $('tableWrap').innerHTML = '<div class="table-state ' + (cls || '') + '">' + html + '</div>'; $('pager').innerHTML = ''; }

    function selectReport(type, fromUser) {
        state.report = type;
        document.querySelectorAll('.report-tab').forEach(function (b) {
            var on = b.getAttribute('data-report') === type;
            b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        $('reportSelect').value = type;
        state.sortCol = null; state.sortDir = 1; state.page = 1;
        if (fromUser) syncUrl();
        loadTable();
    }

    function loadTable() {
        var f = state.filters, type = state.report, key = type + '|' + filterKey(f), seq = ++reqSeq.table;
        state.table = null; state.tableKey = null;
        updatePrintAvailability();
        $('reportTitle').textContent = 'Loading…';
        $('reportCount').textContent = '—';
        $('reportRange').textContent = type === 'activities' ? 'Catalog — date range not applicable' : ((type === 'balances' || type === 'credits') ? 'As of today — all dates' : (fmtDate(f.date_from) + ' – ' + fmtDate(f.date_to)));
        $('reportNotes').innerHTML = '';
        setTableState('<div class="spinner"></div>Loading report…');
        fetchJson('reports.php?preview=' + encodeURIComponent(type) + '&' + filterQuery(f), 'table')
            .then(function (data) {
                if (seq !== reqSeq.table) return;
                state.table = data.table; state.tableKey = key;
                state.tableMeta = { row_count: data.row_count, truncated: data.truncated, limit: data.limit };
                $('reportTitle').textContent = data.table.title;
                var notes = (data.table.notes || []).slice();
                if (data.truncated) notes.unshift('Showing the first ' + intFmt.format(data.limit) + ' of ' + intFmt.format(data.row_count) + ' rows. Totals and CSV include all rows.');
                $('reportNotes').innerHTML = notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('');
                renderTable();
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (seq !== reqSeq.table) return;
                $('reportTitle').textContent = 'Report unavailable';
                setTableState('<i class="fas fa-exclamation-circle"></i>' + esc(err.message || 'Unable to load report.'), 'error');
            })
            .then(function () { if (seq === reqSeq.table) updatePrintAvailability(); });
    }

    function renderTable() {
        var t = state.table; if (!t) return;
        var rows = visibleRows();
        var total = rows.length;
        var size = state.pageSize > 0 ? state.pageSize : (total || 1);
        var pages = Math.max(1, Math.ceil(total / size));
        if (state.page > pages) state.page = pages;
        var start = (state.page - 1) * size;
        var pageRows = state.pageSize > 0 ? rows.slice(start, start + size) : rows;

        var countText = state.search.trim()
            ? intFmt.format(total) + ' of ' + intFmt.format(t.rows.length) + ' rows match "' + state.search.trim() + '"'
            : intFmt.format(state.tableMeta ? state.tableMeta.row_count : t.rows.length) + ' row' + (t.rows.length === 1 ? '' : 's');
        $('reportCount').innerHTML = '<i class="fas fa-info-circle"></i> <strong>' + esc(countText) + '</strong>';

        if (!t.rows.length) { setTableState('<i class="fas fa-inbox"></i>No records for the selected filters and period.'); return; }
        if (!total) { setTableState('<i class="fas fa-search"></i>No rows match your search.'); return; }

        var h = '<table class="data-table"><thead><tr>';
        t.headers.forEach(function (hd, i) {
            var num = NUMERIC[t.types[i]] ? ' num' : '';
            var sorted = state.sortCol === i;
            var ind = sorted ? (state.sortDir === 1 ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
            h += '<th class="sortable' + num + (sorted ? ' sorted' : '') + '" data-col="' + i + '" tabindex="0" aria-sort="' + (sorted ? (state.sortDir === 1 ? 'ascending' : 'descending') : 'none') + '">' + esc(hd) + '<i class="fas ' + ind + ' s-ind"></i></th>';
        });
        h += '</tr></thead><tbody>';
        pageRows.forEach(function (r) {
            h += '<tr>';
            r.forEach(function (v, i) {
                var type = t.types[i];
                var cls = (NUMERIC[type] ? 'num' : '') + ((t.headers[i] === 'Comment') ? ' wrap' : '');
                h += '<td' + (cls ? ' class="' + cls + '"' : '') + ' title="' + esc(cellText(v, type)) + '">' + cellHtml(v, type, t.headers[i]) + '</td>';
            });
            h += '</tr>';
        });
        h += '</tbody>';
        var tot = totalsFor(rows);
        if (tot) {
            h += '<tfoot><tr>';
            tot.forEach(function (v, i) { h += '<td' + (NUMERIC[t.types[i]] ? ' class="num"' : '') + '>' + (i === 0 ? esc(v) : (v === null ? '' : esc(cellText(v, t.types[i])))) + '</td>'; });
            h += '</tr></tfoot>';
        }
        h += '</table>';
        $('tableWrap').innerHTML = h;

        $('tableWrap').querySelectorAll('th.sortable').forEach(function (th) {
            var act = function () {
                var col = parseInt(th.getAttribute('data-col'), 10);
                if (state.sortCol === col) state.sortDir = -state.sortDir; else { state.sortCol = col; state.sortDir = NUMERIC[t.types[col]] ? -1 : 1; }
                state.page = 1; renderTable();
            };
            th.addEventListener('click', act);
            th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); } });
        });

        if (state.pageSize > 0 && pages > 1) {
            $('pager').innerHTML = '<span>Rows ' + intFmt.format(start + 1) + '–' + intFmt.format(Math.min(start + size, total)) + ' of ' + intFmt.format(total) + '</span>' +
                '<div class="pager-btns"><button type="button" data-p="first" aria-label="First page"' + (state.page === 1 ? ' disabled' : '') + '><i class="fas fa-angle-double-left"></i></button>' +
                '<button type="button" data-p="prev" aria-label="Previous page"' + (state.page === 1 ? ' disabled' : '') + '><i class="fas fa-angle-left"></i></button>' +
                '<span>Page ' + state.page + ' of ' + pages + '</span>' +
                '<button type="button" data-p="next" aria-label="Next page"' + (state.page === pages ? ' disabled' : '') + '><i class="fas fa-angle-right"></i></button>' +
                '<button type="button" data-p="last" aria-label="Last page"' + (state.page === pages ? ' disabled' : '') + '><i class="fas fa-angle-double-right"></i></button></div>';
            $('pager').querySelectorAll('button').forEach(function (b) {
                b.addEventListener('click', function () {
                    var p = b.getAttribute('data-p');
                    state.page = p === 'first' ? 1 : p === 'prev' ? state.page - 1 : p === 'next' ? state.page + 1 : pages;
                    renderTable();
                    $('tableWrap').scrollTop = 0;
                });
            });
        } else {
            $('pager').innerHTML = total ? '<span>' + intFmt.format(total) + ' row' + (total === 1 ? '' : 's') + ' shown</span>' : '';
        }
    }

    // ---------- CSV / Print ----------
    function exportCsv() {
        var f = state.filters;
        if (validate(f)) return;
        var url = 'reports.php?export=' + encodeURIComponent(state.report) + '&' + filterQuery(f);
        if (state.search.trim()) url += '&q=' + encodeURIComponent(state.search.trim());
        window.location.href = url;
    }

    function updatePrintAvailability() {
        var key = filterKey(state.filters);
        var ready = state.table && state.tableKey === state.report + '|' + key && state.analytics && state.analyticsKey === key;
        $('btnPrint').disabled = !ready;
        $('btnPrint').title = ready ? 'Print this report (A4)' : 'Available once the report has finished loading';
    }

    function printReport() {
        var t = state.table, a = state.analytics, f = state.filters;
        if (!t || !a) return;
        var k = a.kpis;
        $('prTitle').textContent = 'Sales Analytics — ' + t.title;
        $('prPeriod').textContent = state.report === 'activities' ? 'Period: not applicable (catalog)' : ((state.report === 'balances' || state.report === 'credits') ? 'As of ' + manilaNowText() + ' (all dates) · Summary period: ' + fmtDate(f.date_from) + ' – ' + fmtDate(f.date_to) : 'Period: ' + fmtDate(f.date_from) + ' – ' + fmtDate(f.date_to));
        $('prFilters').textContent = filterSummary(f) + (state.search.trim() ? ' · Search: "' + state.search.trim() + '"' : '');
        $('prGenerated').textContent = 'Generated: ' + manilaNowText();
        var kp = [
            ['Cash Collected', peso(k.cash_collected), 'Fees ' + peso(k.fees_collected) + ' + balances ' + peso(k.balances_collected)],
            ['Reservation Fees Collected', peso(k.fees_collected), int(k.fee_payments) + ' received'],
            ['Confirmed Booking Value', peso(k.booking_value), int(k.secured_count) + ' secured bookings'],
            ['Remaining Balance', peso(k.remaining_balance), 'To collect on arrival'],
            ['Fully Paid Bookings', int(k.fully_paid_count), peso(k.fully_paid_value)],
            ['Awaiting Reservation Fee', peso(k.pending_amount), int(k.pending_count) + ' unpaid'],
            ['Reservations', int(k.reservations), int(k.reservations_total) + ' created'],
            ['Cancellation Rate', k.cancellation_rate === null ? '—' : pct(k.cancellation_rate), int(k.cancelled_count) + ' cancelled'],
            ['Rebooking Credits Held', peso(k.credits_amount), int(k.credits_count) + ' · as of today'],
            ['Outstanding Balances', peso(k.outstanding_amount), int(k.outstanding_count) + ' · as of today'],
            ['Guests / Pax Served', int(k.pax_served), 'By service date'],
            ['Payments Without a Date', peso(k.undated_amount), int(k.undated_count) + ' excluded from cash']
        ];
        $('prKpis').innerHTML = kp.map(function (x) { return '<div class="pr-kpi"><div class="l">' + esc(x[0]) + '</div><div class="v">' + esc(x[1]) + '</div><div class="s">' + esc(x[2]) + '</div></div>'; }).join('');

        var rows = visibleRows();
        $('prTableTitle').textContent = t.title + ' (' + intFmt.format(rows.length) + ' rows)';
        var h = '<table class="pr-table"><thead><tr>' + t.headers.map(function (hd, i) { return '<th' + (NUMERIC[t.types[i]] ? ' class="num"' : '') + '>' + esc(hd) + '</th>'; }).join('') + '</tr></thead><tbody>';
        if (!rows.length) h += '<tr><td colspan="' + t.headers.length + '" style="text-align:center;padding:10px;">No records for the selected filters and period.</td></tr>';
        rows.forEach(function (r) {
            h += '<tr>' + r.map(function (v, i) { return '<td' + (NUMERIC[t.types[i]] ? ' class="num"' : '') + '>' + esc(t.headers[i] === 'Service Date' ? fmtServiceDate(v) : cellText(v, t.types[i])) + '</td>'; }).join('') + '</tr>';
        });
        h += '</tbody>';
        var tot = totalsFor(rows);
        if (tot) h += '<tfoot><tr>' + tot.map(function (v, i) { return '<td' + (NUMERIC[t.types[i]] ? ' class="num"' : '') + '>' + (i === 0 ? esc(v) : (v === null ? '' : esc(cellText(v, t.types[i])))) + '</td>'; }).join('') + '</tr></tfoot>';
        $('prTable').innerHTML = h + '</table>';
        var notes = (t.notes || []).slice();
        if (state.tableMeta && state.tableMeta.truncated) notes.unshift('Printed rows are limited to the first ' + intFmt.format(state.tableMeta.limit) + '; use CSV export for the complete list. Totals cover all rows.');
        $('prNotes').innerHTML = notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('');
        window.print();
    }

    // ---------- wiring ----------
    document.addEventListener('DOMContentLoaded', function () {
        writeFilters(state.filters);
        updatePeriodLabel(state.filters);

        $('range_type').addEventListener('change', function () {
            var r = rangeFor(this.value);
            if (r) { $('date_from').value = ymd(r[0]); $('date_to').value = ymd(r[1]); applyFilters(); }
            else { $('date_from').focus(); }
        });
        ['date_from', 'date_to'].forEach(function (id) {
            $(id).addEventListener('change', function () { $('range_type').value = 'custom'; applyFilters(); });
        });
        ['f_service', 'f_payment', 'f_status'].forEach(function (id) { $(id).addEventListener('change', applyFilters); });
        $('filterForm').addEventListener('submit', function (e) { e.preventDefault(); applyFilters(); });
        $('btnReset').addEventListener('click', function () {
            var r = rangeFor('this_month');
            writeFilters({ date_from: ymd(r[0]), date_to: ymd(r[1]), service: 'all', payment: 'all', status: 'all', range: 'this_month' });
            applyFilters();
        });

        document.querySelectorAll('.report-tab').forEach(function (b) {
            b.addEventListener('click', function () { selectReport(b.getAttribute('data-report'), true); });
        });
        $('reportSelect').addEventListener('change', function () { selectReport(this.value, true); });
        document.querySelectorAll('[data-goto-report]').forEach(function (b) {
            b.addEventListener('click', function () {
                selectReport(b.getAttribute('data-goto-report'), true);
                var target = $('detailedReports'); if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        var searchTimer = null;
        $('tableSearch').addEventListener('input', function () {
            var v = this.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { state.search = v.slice(0, 100); state.page = 1; renderTable(); }, 200);
        });
        $('pageSize').addEventListener('change', function () { state.pageSize = parseInt(this.value, 10) || 0; state.page = 1; renderTable(); });
        $('btnExport').addEventListener('click', exportCsv);
        $('btnPrint').addEventListener('click', printReport);

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { if (charts.trend) charts.trend.resize(); if (charts.mix) charts.mix.resize(); }, 150);
        });

        // Initial load: honour a named quick range so "This Month" etc. are relative to today
        var f0 = state.filters;
        if (f0.range && f0.range !== 'custom') {
            var r0 = rangeFor(f0.range);
            if (r0) { $('date_from').value = ymd(r0[0]); $('date_to').value = ymd(r0[1]); }
        }
        state.report = INIT.report;
        document.querySelectorAll('.report-tab').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-report') === state.report); });
        $('reportSelect').value = state.report;
        applyFilters();
    });
})();
</script>

</body>
</html>

<?php
session_start();
require_once 'database.php';

// ✅ Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'staff')) {
    header("Location: index.php");
    exit();
}

$is_admin = ($_SESSION['role'] == 'admin');
$is_staff = ($_SESSION['role'] == 'staff');

// ============================================================
// AUTO-CREATE blocked_dates TABLE — ✅ kasama na ang 'food'
// ============================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_dates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_type ENUM('house', 'tour', 'food') NOT NULL,
        item_id INT NOT NULL,
        block_date DATE NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        block_type ENUM('walk_in', 'maintenance', 'special_occasion', 'owner_use', 'other') DEFAULT 'walk_in',
        blocked_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_block (item_type, item_id, block_date),
        INDEX idx_item (item_type, item_id, block_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(PDOException $e) {}

// ✅ Alter existing table kung luma pa (walang 'food' sa ENUM)
try {
    $pdo->exec("ALTER TABLE blocked_dates MODIFY COLUMN item_type ENUM('house', 'tour', 'food') NOT NULL");
} catch(PDOException $e) {}

// ============================================================
// GET DYNAMIC CONTENT
// ============================================================
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {}
$admin_display_name = $user_info['fullname'] ?? $user_info['username'] ?? 'User';

// ============================================================
// ✅ HANDLE MULTI-BLOCK (Multiple selected dates from calendar)
// ============================================================
if(isset($_POST['multi_block_action']) && ($is_admin || $is_staff)) {
    try {
        $item_type = $_POST['multi_item_type'] ?? '';
        $item_id = (int)($_POST['multi_item_id'] ?? 0);
        $dates_json = $_POST['multi_dates_json'] ?? '[]';
        $reason = trim($_POST['multi_reason'] ?? '');
        $block_type = $_POST['multi_block_type'] ?? 'walk_in';

        $dates = json_decode($dates_json, true);
        if (!is_array($dates) || empty($dates)) {
            throw new Exception("No dates selected.");
        }

        if (!in_array($item_type, ['house', 'tour', 'food'])) throw new Exception("Invalid item type.");
        if ($item_id <= 0) throw new Exception("Please select an item.");

        $today = date('Y-m-d');
        $blocked_count = 0;
        $skipped_count = 0;

        $stmt = $pdo->prepare("INSERT IGNORE INTO blocked_dates (item_type, item_id, block_date, reason, block_type, blocked_by) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($dates as $date) {
            if ($date < $today) { $skipped_count++; continue; }

            $stmt->execute([$item_type, $item_id, $date, $reason ?: null, $block_type, $_SESSION['user_id']]);
            if ($stmt->rowCount() > 0) $blocked_count++;
            else $skipped_count++;
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'multi_block', 'blocked_dates',
                "Multi-blocked {$item_type} #{$item_id} — {$blocked_count} dates blocked, {$skipped_count} skipped",
                $item_id, 'blocked_dates');
        }

        $success = "✅ Blocked <strong>$blocked_count</strong> date(s) successfully!";
        if ($skipped_count > 0) {
            $success .= " <span style='color: #f59e0b;'>($skipped_count date(s) skipped — already blocked or past)</span>";
        }
    } catch(Exception $e) {
        $error = "Failed: " . $e->getMessage();
    }
}

// ============================================================
// ✅ HANDLE UNBLOCK DATE
// ============================================================
if(isset($_POST['unblock_date_action']) && ($is_admin || $is_staff)) {
    try {
        $id = (int)($_POST['block_id'] ?? 0);
        if ($id <= 0) throw new Exception("Invalid block ID.");

        $stmt = $pdo->prepare("SELECT * FROM blocked_dates WHERE id = ?");
        $stmt->execute([$id]);
        $block = $stmt->fetch();
        if (!$block) throw new Exception("Blocked date not found.");

        $pdo->prepare("DELETE FROM blocked_dates WHERE id = ?")->execute([$id]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'unblock_date', 'blocked_dates', "Unblocked {$block['item_type']} #{$block['item_id']} on {$block['block_date']}", $id, 'blocked_dates');
        }

        $success = "✅ Date " . date('M d, Y', strtotime($block['block_date'])) . " unblocked! Now available for online booking.";
    } catch(Exception $e) {
        $error = "Failed to unblock: " . $e->getMessage();
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

// ============================================================
// GET FILTERS
// ============================================================
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_month = $_GET['filter_month'] ?? '';
$search = trim($_GET['search'] ?? '');
$cal_type = $_GET['cal_type'] ?? '';
$cal_id = (int)($_GET['cal_id'] ?? 0);

// Get houses, tours, and food items
$houses = $pdo->query("SELECT id, house_name FROM houses ORDER BY house_name")->fetchAll();
$tours = $pdo->query("SELECT id, tour_name FROM tours ORDER BY tour_name")->fetchAll();

// ✅ Food items — adjust columns as needed
$foods = [];
try {
    $foods = $pdo->query("SELECT id, name FROM food_items ORDER BY name")->fetchAll();
} catch(PDOException $e) {
    $foods = [];
}

// ============================================================
// GET BLOCKED DATES (with filters)
// ============================================================
$where = [];
$params = [];
if ($filter_type !== 'all') { $where[] = "bd.item_type = ?"; $params[] = $filter_type; }
if ($filter_month) { $where[] = "DATE_FORMAT(bd.block_date, '%Y-%m') = ?"; $params[] = $filter_month; }
if ($search) { $where[] = "(bd.reason LIKE ? OR bd.block_date LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$stmt = $pdo->prepare("SELECT bd.*, 
    CASE 
        WHEN bd.item_type = 'house' THEN (SELECT house_name FROM houses WHERE id = bd.item_id)
        WHEN bd.item_type = 'tour' THEN (SELECT tour_name FROM tours WHERE id = bd.item_id)
        WHEN bd.item_type = 'food' THEN (SELECT name FROM food_items WHERE id = bd.item_id)
    END as item_name
    FROM blocked_dates bd
    $where_sql
    ORDER BY bd.block_date ASC, bd.item_type, bd.item_id");
$stmt->execute($params);
$blocked_dates = $stmt->fetchAll();

// Stats
$total_blocks = $pdo->query("SELECT COUNT(*) FROM blocked_dates")->fetchColumn();
$total_house_blocks = $pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE item_type = 'house'")->fetchColumn();
$total_tour_blocks = $pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE item_type = 'tour'")->fetchColumn();
$total_food_blocks = $pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE item_type = 'food'")->fetchColumn();
$today_blocks = $pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE block_date >= CURDATE()")->fetchColumn();

// ============================================================
// GET CALENDAR EVENTS FOR SELECTED ITEM
// ============================================================
$calendar_events = [];
$cal_item_name = '';

if ($cal_type && $cal_id > 0) {
    // ---------- HOUSE ----------
    if ($cal_type === 'house') {
        $stmt = $pdo->prepare("SELECT house_name FROM houses WHERE id = ?");
        $stmt->execute([$cal_id]);
        $cal_item_name = $stmt->fetchColumn() ?: 'Unknown House';

        $stmt = $pdo->prepare("SELECT hb.*, 
            COALESCE(u.fullname, u.username, 'Guest') as guest_name
            FROM house_bookings hb 
            LEFT JOIN guests g ON hb.guest_id = g.id 
            LEFT JOIN users u ON g.user_id = u.id 
            WHERE hb.house_id = ? 
              AND hb.booking_status NOT IN ('cancelled', 'completed')
            ORDER BY hb.check_in_date ASC");
        $stmt->execute([$cal_id]);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as $b) {
            $isPaid = $b['payment_status'] === 'paid';
            $check_out = new DateTime($b['check_out_date']);
            $check_out->modify('+1 day');

            $calendar_events[] = [
                'id' => 'booking_' . $b['id'],
                'title' => ($isPaid ? '💰 ' : '⏳ ') . $b['guest_name'] . ' (' . $b['reference_number'] . ')',
                'start' => $b['check_in_date'],
                'end' => $check_out->format('Y-m-d'),
                'color' => $isPaid ? '#10b981' : '#f59e0b',
                'textColor' => '#ffffff',
                'extendedProps' => [
                    'event_type' => 'booking',
                    'booking_type' => 'house',
                    'booking_id' => $b['id'],
                    'reference' => $b['reference_number'],
                    'guest_name' => $b['guest_name'],
                    'payment_status' => $b['payment_status'],
                    'check_in' => $b['check_in_date'],
                    'check_out' => $b['check_out_date'],
                    'guests' => $b['number_of_guests'],
                    'total' => $b['total_amount']
                ]
            ];
        }

    // ---------- TOUR ----------
    } elseif ($cal_type === 'tour') {
        $stmt = $pdo->prepare("SELECT tour_name FROM tours WHERE id = ?");
        $stmt->execute([$cal_id]);
        $cal_item_name = $stmt->fetchColumn() ?: 'Unknown Tour';

        $stmt = $pdo->prepare("SELECT tb.*, 
            COALESCE(u.fullname, u.username, tb.guest_name, 'Guest') as guest_name
            FROM tour_bookings tb 
            LEFT JOIN guests g ON tb.guest_id = g.id 
            LEFT JOIN users u ON g.user_id = u.id 
            WHERE tb.tour_id = ? 
              AND tb.booking_status NOT IN ('cancelled', 'completed')
            ORDER BY tb.booking_date ASC");
        $stmt->execute([$cal_id]);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as $b) {
            $isPaid = $b['payment_status'] === 'paid';
            $end = new DateTime($b['booking_date']);
            $end->modify('+1 day');

            $calendar_events[] = [
                'id' => 'booking_' . $b['id'],
                'title' => ($isPaid ? '💰 ' : '⏳ ') . $b['guest_name'] . ' (' . $b['reference_number'] . ')',
                'start' => $b['booking_date'],
                'end' => $end->format('Y-m-d'),
                'color' => $isPaid ? '#10b981' : '#f59e0b',
                'textColor' => '#ffffff',
                'extendedProps' => [
                    'event_type' => 'booking',
                    'booking_type' => 'tour',
                    'booking_id' => $b['id'],
                    'reference' => $b['reference_number'],
                    'guest_name' => $b['guest_name'],
                    'payment_status' => $b['payment_status'],
                    'check_in' => $b['booking_date'],
                    'check_out' => $b['booking_date'],
                    'guests' => $b['number_of_guests'],
                    'total' => $b['total_amount']
                ]
            ];
        }

    // ---------- FOOD ----------
    } elseif ($cal_type === 'food') {
        $stmt = $pdo->prepare("SELECT name FROM food_items WHERE id = ?");
        $stmt->execute([$cal_id]);
        $cal_item_name = $stmt->fetchColumn() ?: 'Unknown Food Item';

        // ✅ Food bookings — adjust table/column names kung iba sa'yo
        try {
            $stmt = $pdo->prepare("SELECT fb.*, 
                COALESCE(u.fullname, u.username, fb.guest_name, 'Guest') as guest_name
                FROM food_bookings fb 
                LEFT JOIN guests g ON fb.guest_id = g.id 
                LEFT JOIN users u ON g.user_id = u.id 
                WHERE fb.food_id = ? 
                  AND fb.booking_status NOT IN ('cancelled', 'completed')
                ORDER BY fb.preferred_date ASC");
            $stmt->execute([$cal_id]);
            $bookings = $stmt->fetchAll();

            foreach ($bookings as $b) {
                $isPaid = ($b['payment_status'] ?? '') === 'paid';
                $end = new DateTime($b['preferred_date']);
                $end->modify('+1 day');

                $calendar_events[] = [
                    'id' => 'booking_' . $b['id'],
                    'title' => ($isPaid ? '💰 ' : '⏳ ') . $b['guest_name'] . ' (' . ($b['reference_number'] ?? '') . ')',
                    'start' => $b['preferred_date'],
                    'end' => $end->format('Y-m-d'),
                    'color' => $isPaid ? '#10b981' : '#f59e0b',
                    'textColor' => '#ffffff',
                    'extendedProps' => [
                        'event_type' => 'booking',
                        'booking_type' => 'food',
                        'booking_id' => $b['id'],
                        'reference' => $b['reference_number'] ?? '',
                        'guest_name' => $b['guest_name'],
                        'payment_status' => $b['payment_status'] ?? 'pending',
                        'check_in' => $b['preferred_date'],
                        'check_out' => $b['preferred_date'],
                        'guests' => $b['quantity'] ?? 1,
                        'total' => $b['total_amount'] ?? 0
                    ]
                ];
            }
        } catch(PDOException $e) {
            // Food bookings table not found — skip bookings, still show blocks
        }
    }

    // ---------- BLOCKS (for all types) ----------
    if (in_array($cal_type, ['house', 'tour', 'food'])) {
        $stmt = $pdo->prepare("SELECT * FROM blocked_dates WHERE item_type = ? AND item_id = ? ORDER BY block_date ASC");
        $stmt->execute([$cal_type, $cal_id]);
        $blocks = $stmt->fetchAll();

        foreach ($blocks as $bd) {
            $blockTypeLabel = ucwords(str_replace('_', ' ', $bd['block_type']));
            $end = new DateTime($bd['block_date']);
            $end->modify('+1 day');

            $calendar_events[] = [
                'id' => 'blocked_' . $bd['id'],
                'title' => '🚫 BLOCKED - ' . $blockTypeLabel,
                'start' => $bd['block_date'],
                'end' => $end->format('Y-m-d'),
                'color' => '#64748b',
                'textColor' => '#ffffff',
                'extendedProps' => [
                    'event_type' => 'blocked',
                    'block_id' => $bd['id'],
                    'block_type' => $bd['block_type'],
                    'block_type_label' => $blockTypeLabel,
                    'reason' => $bd['reason'] ?? '',
                    'block_date' => $bd['block_date']
                ]
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blocked Dates - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; }

        .app-container { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); flex-shrink: 0; z-index: 100; transition: transform 0.3s ease; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); overflow: hidden; }
        .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; }
        .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 99; opacity: 0; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; }

        @media (max-width: 1024px) {
            .sidebar { position: fixed; transform: translateX(-100%); z-index: 1000; }
            .sidebar.open { transform: translateX(0); }
            .menu-toggle { display: flex; }
            .main-content { padding: 70px 16px 20px !important; }
            .sidebar-close-btn { display: flex; }
        }

        /* MAIN */
        .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11, 36, 71, 0.1); flex-wrap: wrap; gap: 10px; }
        .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .top-bar .page-title h1 i { color: #4DA6D9; }
        .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
        .user-profile { display: flex; align-items: center; gap: 15px; }
        .user-profile .avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 18px; border: 2px solid rgba(77, 166, 217, 0.2); }
        .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 14px; }
        .user-profile .user-role { color: #4a6a8c; font-size: 12px; text-align: right; }

        .page-title-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15); }
        .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .page-title-banner h1 i { margin-right: 10px; opacity: 0.9; }
        .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
        .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #4DA6D9; border-radius: 16px; padding: 22px 20px; color: white; box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2); border: 1px solid rgba(255,255,255,0.15); }
        .stat-icon { width: 44px; height: 44px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; border: 1px solid rgba(255,255,255,0.1); margin-bottom: 10px; }
        .stat-number { font-size: 26px; font-weight: 700; color: white; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 12px; font-weight: 500; margin-top: 2px; }

        /* ALERTS */
        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }

        /* CARDS */
        .card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); margin-bottom: 30px; border: 1px solid #e8f0fe; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
        .card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
        .card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }

        /* FORMS */
        .form-label { display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-control, .form-select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .form-grid .full-width { grid-column: 1 / -1; }

        .btn-primary { padding: 12px 24px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.4); background: #e6a800; }

        .btn-secondary { padding: 10px 20px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }

        .btn-danger-sm { padding: 5px 12px; background: #ef4444; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 11px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-danger-sm:hover { background: #dc2626; transform: translateY(-1px); }

        .btn-success-sm { padding: 5px 12px; background: #10b981; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 11px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-success-sm:hover { background: #059669; transform: translateY(-1px); }

        /* TABLE */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th { text-align: left; padding: 10px 12px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; }
        td { padding: 10px 12px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; vertical-align: middle; }
        tr:hover { background: #f8fafc; }

        /* BADGES */
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; white-space: nowrap; }
        .badge-walk_in { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        .badge-maintenance { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
        .badge-special_occasion { background: #f3e8ff; color: #6b21a8; border: 1px solid #c4b5fd; }
        .badge-owner_use { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .badge-other { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-house { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
        .badge-tour { background: #e6f7e6; color: #10b981; border: 1px solid #a7f3d0; }
        .badge-food { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }

        /* EMPTY STATE */
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 60px; color: #cbd5e1; display: block; margin-bottom: 20px; }
        .empty-state h3 { color: #1e293b; margin-bottom: 10px; }

        /* FILTER BAR */
        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .filter-bar select, .filter-bar input { padding: 8px 12px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 13px; background: white; }
        .filter-bar select:focus, .filter-bar input:focus { outline: none; border-color: #4DA6D9; }

        /* TAB SWITCHER */
        .tab-switcher { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn { padding: 10px 20px; border-radius: 10px; background: white; border: 2px solid #e8f0fe; cursor: pointer; font-weight: 600; font-size: 13px; color: #4a6a8c; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.2s; }
        .tab-btn:hover { background: #f0f7fb; border-color: #4DA6D9; }
        .tab-btn.active { background: #4DA6D9; color: white; border-color: #4DA6D9; }
        .tab-btn.calendar-tab.active { background: #8b5cf6; border-color: #8b5cf6; }

        /* CALENDAR ITEM SELECTOR */
        .calendar-item-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            padding: 20px;
            background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%);
            border-radius: 16px;
            border: 2px dashed #4DA6D9;
            margin-bottom: 25px;
        }
        .calendar-item-selector .selector-group { display: flex; flex-direction: column; gap: 8px; }
        .calendar-item-selector .selector-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #4a6a8c; letter-spacing: 0.5px; }
        .calendar-item-selector select { padding: 12px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: white; font-weight: 500; }
        .calendar-item-selector select:focus { outline: none; border-color: #4DA6D9; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }

        /* CALENDAR */
        #itemCalendar { min-height: 600px; padding: 10px; background: white; border-radius: 16px; }
        .fc { font-family: 'Inter', sans-serif !important; }

        .fc-daygrid-day { min-height: 90px !important; background: white; transition: background 0.15s; }
        .fc-daygrid-day-frame { min-height: 90px !important; }
        .fc-daygrid-day-top { display: flex !important; flex-direction: row !important; }

        .fc-daygrid-day-number {
            color: #0B2447 !important;
            font-weight: 700 !important;
            font-size: 14px !important;
            padding: 6px 8px !important;
            transition: all 0.15s;
            z-index: 5 !important;
            position: relative;
            text-decoration: none !important;
        }
        .fc-daygrid-day-number:hover { background: rgba(77, 166, 217, 0.15); border-radius: 6px; }

        .fc-day-today { background: #fff8e1 !important; }
        .fc-day-today .fc-daygrid-day-number {
            background: #F4B400 !important;
            color: #0B2447 !important;
            border-radius: 6px;
            font-weight: 800 !important;
        }

        .fc-day-other { background: #fafafa !important; }
        .fc-day-other .fc-daygrid-day-number { color: #cbd5e1 !important; }

        .fc-day-sat, .fc-day-sun { background: #fef9f3; }
        .fc-day-sat .fc-daygrid-day-number,
        .fc-day-sun .fc-daygrid-day-number { color: #b45309 !important; }

        .fc-daygrid-day-events { margin-top: 28px !important; }
        .fc-daygrid-day-bottom { padding: 2px 4px !important; }

        .fc-event {
            cursor: pointer;
            border-radius: 8px !important;
            padding: 4px 8px !important;
            font-size: 11px !important;
            border: none !important;
            box-shadow: 0 1px 4px rgba(0,0,0,0.15);
            font-weight: 600;
            line-height: 1.3 !important;
        }
        .fc-event-title { font-weight: 700 !important; }
        .fc-daygrid-event-dot { display: none; }
        .fc-event-time { font-weight: 600; margin-right: 4px; }

        .fc-button-primary { background: #4DA6D9 !important; border: none !important; font-weight: 600 !important; }
        .fc-button-primary:hover { background: #3a8bbf !important; }
        .fc-button-active { background: #0B2447 !important; }
        .fc-toolbar-title { font-size: 20px !important; font-weight: 700 !important; color: #0B2447 !important; }

        /* MULTI-SELECT DATE STYLING */
        .fc-daygrid-day.selected-date {
            background: #dbeafe !important;
            box-shadow: inset 0 0 0 3px #0ea5e9;
        }
        .fc-daygrid-day.selected-date .fc-daygrid-day-number {
            color: #0369a1 !important;
            font-weight: 800 !important;
            background: white !important;
            border-radius: 50% !important;
            width: 26px !important;
            height: 26px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 4px !important;
            padding: 0 !important;
            box-shadow: 0 2px 6px rgba(14, 165, 233, 0.4);
            border: 2px solid #0ea5e9 !important;
        }

        .fc-daygrid-day.fc-day-past { background: #f8fafc; cursor: not-allowed !important; }
        .fc-daygrid-day.fc-day-past .fc-daygrid-day-number { color: #94a3b8 !important; opacity: 0.7; }
        .fc-daygrid-day.fc-day-past:hover { background: #f1f5f9 !important; }

        .calendar-legend { display: flex; gap: 20px; flex-wrap: wrap; padding: 15px 0; justify-content: center; margin-top: 15px; border-top: 2px solid #e8f0fe; }
        .calendar-legend .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #475569; font-weight: 500; }
        .calendar-legend .legend-color { width: 20px; height: 20px; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .calendar-legend .legend-color.paid { background: #10b981; }
        .calendar-legend .legend-color.pending { background: #f59e0b; }
        .calendar-legend .legend-color.blocked { background: #64748b; }

        .calendar-hint { background: linear-gradient(135deg, #fef3c7, #fffbeb); border-left: 4px solid #f59e0b; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 13px; color: #92400e; display: flex; align-items: flex-start; gap: 12px; line-height: 1.6; }
        .calendar-hint i { color: #f59e0b; font-size: 18px; flex-shrink: 0; margin-top: 2px; }
        .calendar-hint strong { color: #78350f; }

        /* FLOATING ACTION BAR */
        .selection-toolbar {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(120px);
            background: #0B2447;
            color: white;
            padding: 14px 20px;
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(11, 36, 71, 0.5);
            display: flex;
            align-items: center;
            gap: 16px;
            z-index: 2500;
            transition: transform 0.3s ease;
            border: 1px solid rgba(77, 166, 217, 0.3);
            max-width: 95%;
            flex-wrap: wrap;
            justify-content: center;
        }
        .selection-toolbar.show { transform: translateX(-50%) translateY(0); }
        .selection-toolbar .selection-info { display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; }
        .selection-toolbar .selection-info .count-badge { background: #4DA6D9; color: white; padding: 4px 12px; border-radius: 20px; font-weight: 800; font-size: 14px; min-width: 32px; text-align: center; }
        .selection-toolbar .toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .selection-toolbar button { padding: 9px 16px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .selection-toolbar .btn-clear-selection { background: rgba(255,255,255,0.15); color: white; }
        .selection-toolbar .btn-clear-selection:hover { background: rgba(255,255,255,0.25); }
        .selection-toolbar .btn-block-selected { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4); }
        .selection-toolbar .btn-block-selected:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.6); }

        @media (max-width: 600px) {
            .selection-toolbar { padding: 12px 14px; gap: 10px; bottom: 16px; }
            .selection-toolbar .selection-info { font-size: 13px; }
            .selection-toolbar button { padding: 8px 12px; font-size: 12px; }
        }

        /* MODAL */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 3000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; padding: 28px; animation: modalSlideIn 0.3s ease; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
        @keyframes modalSlideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
        .modal-header h3 { font-size: 18px; font-weight: 700; color: #0B2447; display: flex; align-items: center; gap: 10px; }
        .modal-header h3 i { color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; transition: color 0.3s; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }

        .modal-info-box { background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; border-left: 4px solid #4DA6D9; }
        .modal-info-box .info-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
        .modal-info-box .info-row .info-label { color: #64748b; font-weight: 500; }
        .modal-info-box .info-row .info-value { color: #0B2447; font-weight: 700; }

        .block-type-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 15px; }
        .block-type-option { position: relative; cursor: pointer; }
        .block-type-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .block-type-option-label { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 12.5px; font-weight: 600; color: #475569; transition: all 0.2s; }
        .block-type-option-label i { color: #94a3b8; font-size: 14px; }
        .block-type-option input[type="radio"]:checked + .block-type-option-label { background: #e0f2fe; border-color: #0ea5e9; color: #0369a1; }
        .block-type-option input[type="radio"]:checked + .block-type-option-label i { color: #0ea5e9; }
        .block-type-option:hover .block-type-option-label { border-color: #0ea5e9; }

        /* SELECTED DATE CHIP */
        .selected-date-chip { display: inline-flex; align-items: center; gap: 6px; background: #dbeafe; color: #0369a1; padding: 5px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; border: 1px solid #93c5fd; transition: all 0.2s; }
        .selected-date-chip:hover { background: #bfdbfe; }
        .selected-date-chip .remove-chip { background: #0369a1; color: white; width: 16px; height: 16px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; cursor: pointer; border: none; line-height: 1; padding: 0; }
        .selected-date-chip .remove-chip:hover { background: #ef4444; }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: flex-start; }
            .page-title-banner { padding: 20px; }
            .page-title-banner h1 { font-size: 22px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; }
            .stat-number { font-size: 20px; }
            .card { padding: 18px 15px; }
            .form-grid { grid-template-columns: 1fr; }
            table { min-width: 600px; }
            th, td { font-size: 11px; padding: 8px; }
            .block-type-grid { grid-template-columns: 1fr; }

            #itemCalendar { min-height: 400px; }
            .fc-daygrid-day { min-height: 60px !important; }
            .fc-daygrid-day-frame { min-height: 60px !important; }
            .fc-daygrid-day-number { font-size: 12px !important; padding: 4px 5px !important; }
            .fc-daygrid-day.selected-date .fc-daygrid-day-number { width: 22px !important; height: 22px !important; margin: 2px !important; }
            .fc-daygrid-day-events { margin-top: 22px !important; }
            .fc-toolbar-title { font-size: 16px !important; }
        }
        @media (max-width: 480px) {
            .main-content { padding: 60px 12px 16px !important; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; }
            .sidebar { width: 85%; max-width: 300px; }
            .stats-grid { grid-template-columns: 1fr; }
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
                            <img src="<?php echo htmlspecialchars($nav_logo); ?>?<?php echo time(); ?>" alt="Logo">
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
            <li class="nav-item"><a href="food-dashboard.php" class="nav-link"><i class="fas fa-utensils"></i><span>Food Management</span></a></li>
            <li class="nav-item"><a href="booking-management.php" class="nav-link"><i class="fas fa-calendar-check"></i><span>Booking Management</span></a></li>
            <li class="nav-item"><a href="blocked-dates.php" class="nav-link active"><i class="fas fa-ban"></i><span>Blocked Dates</span></a></li>
            <li class="nav-item"><a href="reviews-management.php" class="nav-link"><i class="fas fa-star"></i><span>Reviews Management</span></a></li>
            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Reports</span></a></li>
            <?php if($is_admin): ?>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>
            <li class="nav-item"><a href="system-logs.php" class="nav-link"><i class="fas fa-history"></i><span>System Logs</span></a></li>
            <?php endif; ?>
            <div class="nav-divider"></div>
            <li class="nav-item"><a href="admin-profile.php" class="nav-link"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
            <li class="nav-item"><a href="?logout=1" class="nav-link" onclick="return confirm('Logout?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">

        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-ban"></i> Blocked Dates Management</h1>
                <p>Block or unblock dates for houses, tours, and food items</p>
            </div>
            <div class="user-profile">
                <div style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                    <div class="user-role">
                        <?php if($is_admin): ?><i class="fas fa-crown" style="color: #fbbf24;"></i> Admin
                        <?php else: ?><i class="fas fa-user-tie" style="color: #fbbf24;"></i> Staff<?php endif; ?>
                    </div>
                </div>
                <div class="avatar"><?php echo strtoupper(substr($admin_display_name, 0, 1)); ?></div>
            </div>
        </div>

        <?php if(isset($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="page-title-banner">
            <h1><i class="fas fa-ban"></i> Blocked Dates</h1>
            <div class="underline"></div>
            <p style="margin-top: 8px;">Manage blocked dates so online guests can't book these dates</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
                <div class="stat-number"><?php echo $total_blocks; ?></div>
                <div class="stat-label">Total Blocked</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <div class="stat-icon"><i class="fas fa-home"></i></div>
                <div class="stat-number"><?php echo $total_house_blocks; ?></div>
                <div class="stat-label">House Blocks</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #10b981, #059669);">
                <div class="stat-icon"><i class="fas fa-umbrella-beach"></i></div>
                <div class="stat-number"><?php echo $total_tour_blocks; ?></div>
                <div class="stat-label">Tour Blocks</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="stat-icon"><i class="fas fa-utensils"></i></div>
                <div class="stat-number"><?php echo $total_food_blocks; ?></div>
                <div class="stat-label">Food Blocks</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo $today_blocks; ?></div>
                <div class="stat-label">Upcoming</div>
            </div>
        </div>

        <!-- TAB SWITCHER -->
        <div class="tab-switcher">
            <a href="?tab=calendar" class="tab-btn calendar-tab <?php echo (!isset($_GET['tab']) || $_GET['tab'] == 'calendar') ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> 📅 Calendar View
            </a>
            <a href="?tab=list" class="tab-btn <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'list') ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Blocked Dates
            </a>
        </div>

        <?php 
        $current_tab = $_GET['tab'] ?? 'calendar';
        
        // ============================================================
        // TAB 0: CALENDAR VIEW (DEFAULT)
        // ============================================================
        if ($current_tab === 'calendar'): 
        ?>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-calendar-alt"></i> Calendar View — Select a House, Tour, or Food</h2>
            </div>

            <div class="calendar-hint">
                <i class="fas fa-lightbulb"></i>
                <div>
                    <strong>How to use:</strong><br>
                    1. Select an <strong>Item Type</strong> (House, Tour, or Food) below<br>
                    2. Select a <strong>specific item</strong><br>
                    3. The <strong>calendar</strong> will show existing bookings and blocked dates<br>
                    4. <strong>Click multiple dates</strong> to select them, then click <strong>"Block Selected Dates"</strong><br>
                    5. Click a <strong>gray blocked event</strong> to unblock it
                </div>
            </div>

            <form method="GET" id="calendarFilterForm">
                <input type="hidden" name="tab" value="calendar">
                <div class="calendar-item-selector">
                    <div class="selector-group">
                        <label class="selector-label"><i class="fas fa-tag"></i> Item Type</label>
                        <select name="cal_type" id="cal_type_select" onchange="updateCalendarItems()" required>
                            <option value="">-- Select Type --</option>
                            <option value="house" <?php echo $cal_type === 'house' ? 'selected' : ''; ?>>🏠 House</option>
                            <option value="tour" <?php echo $cal_type === 'tour' ? 'selected' : ''; ?>>🚤 Tour</option>
                            <option value="food" <?php echo $cal_type === 'food' ? 'selected' : ''; ?>>🍽️ Food</option>
                        </select>
                    </div>
                    <div class="selector-group">
                        <label class="selector-label"><i class="fas fa-cube"></i> Select Item</label>
                        <select name="cal_id" id="cal_id_select" onchange="document.getElementById('calendarFilterForm').submit()" required>
                            <option value="">-- Select Item --</option>
                        </select>
                    </div>
                </div>
            </form>

            <?php if ($cal_type && $cal_id > 0 && !empty($cal_item_name)): ?>
                <div style="display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: #f0f7fb; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #4DA6D9;">
                    <i class="fas fa-<?php echo $cal_type === 'house' ? 'home' : ($cal_type === 'tour' ? 'umbrella-beach' : 'utensils'); ?>" style="color: #4DA6D9; font-size: 22px;"></i>
                    <div>
                        <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Selected Item</div>
                        <div style="font-size: 17px; font-weight: 700; color: #0B2447;"><?php echo htmlspecialchars($cal_item_name); ?></div>
                    </div>
                </div>

                <div id="itemCalendar"></div>

                <div class="calendar-legend">
                    <div class="legend-item"><div class="legend-color paid"></div> Paid Booking</div>
                    <div class="legend-item"><div class="legend-color pending"></div> Pending Payment</div>
                    <div class="legend-item"><div class="legend-color blocked"></div> Blocked Date</div>
                    <div class="legend-item" style="color: #0ea5e9;">
                        <div class="legend-color" style="background: #dbeafe; border: 2px solid #0ea5e9;"></div>
                        Selected (to block)
                    </div>
                </div>

                <div style="margin-top: 20px; padding: 14px 18px; background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 10px; font-size: 13px; color: #92400e;">
                    <i class="fas fa-mouse-pointer"></i> <strong>Tip:</strong> Click multiple dates to <strong>select</strong> them (turns blue). Then click <strong>"Block Selected Dates"</strong> to block them all at once. Click a <strong>gray blocked event</strong> to unblock it.
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <h3>Select an Item to View the Calendar</h3>
                    <p>Choose an <strong>Item Type</strong> and a <strong>specific item</strong> above to view its calendar.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php 
        // ============================================================
        // TAB 2: ALL BLOCKED DATES
        // ============================================================
        else: 
        ?>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> All Blocked Dates (<?php echo count($blocked_dates); ?>)</h2>
                <div class="filter-bar">
                    <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <input type="hidden" name="tab" value="list">
                        <select name="filter_type" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="house" <?php echo $filter_type == 'house' ? 'selected' : ''; ?>>🏠 Houses Only</option>
                            <option value="tour" <?php echo $filter_type == 'tour' ? 'selected' : ''; ?>>🚤 Tours Only</option>
                            <option value="food" <?php echo $filter_type == 'food' ? 'selected' : ''; ?>>🍽️ Foods Only</option>
                        </select>
                        <input type="month" name="filter_month" value="<?php echo htmlspecialchars($filter_month); ?>" onchange="this.form.submit()">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search reason..." style="min-width: 150px;">
                        <button type="submit" class="btn-secondary" style="padding: 8px 14px; font-size: 13px;">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <?php if($filter_type != 'all' || $filter_month || $search): ?>
                            <a href="?tab=list" class="btn-secondary" style="padding: 8px 14px; font-size: 13px; text-decoration: none;">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php if(!empty($blocked_dates)): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Item</th>
                            <th>Block Type</th>
                            <th>Reason</th>
                            <th>Blocked On</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($blocked_dates as $bd): ?>
                        <tr>
                            <td><strong style="color: #0B2447;"><?php echo date('M d, Y (D)', strtotime($bd['block_date'])); ?></strong></td>
                            <td>
                                <?php if($bd['item_type'] === 'house'): ?>
                                    <span class="badge badge-house"><i class="fas fa-home"></i> House</span>
                                <?php elseif($bd['item_type'] === 'tour'): ?>
                                    <span class="badge badge-tour"><i class="fas fa-umbrella-beach"></i> Tour</span>
                                <?php else: ?>
                                    <span class="badge badge-food"><i class="fas fa-utensils"></i> Food</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($bd['item_name'] ?? 'Unknown'); ?></strong></td>
                            <td><span class="badge badge-<?php echo $bd['block_type']; ?>"><?php echo ucwords(str_replace('_', ' ', $bd['block_type'])); ?></span></td>
                            <td><?php echo $bd['reason'] ? htmlspecialchars($bd['reason']) : '<em style="color:#94a3b8;">No reason</em>'; ?></td>
                            <td style="font-size: 12px; color: #94a3b8;"><?php echo date('M d, Y h:i A', strtotime($bd['created_at'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Unblock this date?');">
                                    <input type="hidden" name="block_id" value="<?php echo $bd['id']; ?>">
                                    <button type="submit" name="unblock_date_action" class="btn-success-sm">
                                        <i class="fas fa-unlock"></i> Unblock
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-check"></i>
                    <h3>No Blocked Dates Found</h3>
                    <p>All dates are available for online booking.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>

    </div>
</div>

<!-- FLOATING SELECTION TOOLBAR -->
<div class="selection-toolbar" id="selectionToolbar">
    <div class="selection-info">
        <i class="fas fa-check-square" style="color: #4DA6D9;"></i>
        <span>Selected: <span class="count-badge" id="selectedCount">0</span> date(s)</span>
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn-clear-selection" onclick="clearDateSelection()">
            <i class="fas fa-times"></i> Clear
        </button>
        <button type="button" class="btn-block-selected" onclick="openMultiBlockModal()">
            <i class="fas fa-ban"></i> Block Selected Dates
        </button>
    </div>
</div>

<!-- MULTI-BLOCK CONFIRMATION MODAL -->
<div class="modal" id="multiBlockModal">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header" style="border-bottom: 2px solid #fee2e2;">
            <h3 style="color: #991b1b;">
                <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>
                Confirm Block — Multiple Dates
            </h3>
            <button class="close" onclick="closeMultiBlockModal()">&times;</button>
        </div>

        <form method="POST" id="multiBlockForm">
            <input type="hidden" name="multi_block_action" value="1">
            <input type="hidden" name="multi_item_type" id="multi_item_type">
            <input type="hidden" name="multi_item_id" id="multi_item_id">
            <input type="hidden" name="multi_dates_json" id="multi_dates_json">

            <div class="modal-info-box" style="border-left-color: #ef4444;">
                <div class="info-row">
                    <span class="info-label">Item Type:</span>
                    <span class="info-value" id="multi_item_type_display">—</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Item Name:</span>
                    <span class="info-value" id="multi_item_name_display">—</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Dates:</span>
                    <span class="info-value" id="multi_total_count_display">—</span>
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="form-label">
                    <i class="fas fa-calendar-check" style="color: #64748b;"></i>
                    Selected Dates
                    <span style="font-weight: 400; color: #94a3b8;">(click × to remove)</span>
                </label>
                <div id="multi_dates_preview" style="
                    display: flex;
                    flex-wrap: wrap;
                    gap: 6px;
                    max-height: 140px;
                    overflow-y: auto;
                    padding: 10px;
                    background: #f8fafc;
                    border: 2px solid #e2e8f0;
                    border-radius: 10px;
                "></div>
            </div>

            <div style="margin-bottom: 15px;">
                <label class="form-label">
                    <i class="fas fa-list" style="color: #64748b;"></i> Block Type *
                </label>
                <div class="block-type-grid">
                    <label class="block-type-option">
                        <input type="radio" name="multi_block_type" value="walk_in" checked>
                        <span class="block-type-option-label"><i class="fas fa-walking"></i> Walk-in</span>
                    </label>
                    <label class="block-type-option">
                        <input type="radio" name="multi_block_type" value="maintenance">
                        <span class="block-type-option-label"><i class="fas fa-tools"></i> Maintenance</span>
                    </label>
                    <label class="block-type-option">
                        <input type="radio" name="multi_block_type" value="special_occasion">
                        <span class="block-type-option-label"><i class="fas fa-gift"></i> Special Event</span>
                    </label>
                    <label class="block-type-option">
                        <input type="radio" name="multi_block_type" value="owner_use">
                        <span class="block-type-option-label"><i class="fas fa-crown"></i> Owner Use</span>
                    </label>
                    <label class="block-type-option">
                        <input type="radio" name="multi_block_type" value="other">
                        <span class="block-type-option-label"><i class="fas fa-ellipsis-h"></i> Other</span>
                    </label>
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label class="form-label">
                    <i class="fas fa-comment" style="color: #64748b;"></i>
                    Reason (Optional — applied to all dates)
                </label>
                <input type="text" name="multi_reason" class="form-control"
                       placeholder="e.g., Walk-in group booking at counter"
                       maxlength="255">
            </div>

            <div style="background: #fee2e2; padding: 12px 15px; border-radius: 10px;
                        text-align: left; font-size: 12.5px; color: #991b1b;
                        margin-bottom: 15px; border-left: 4px solid #ef4444;">
                <strong>⚠️ Please review before confirming:</strong><br>
                • All selected dates will be blocked and unavailable for online booking.<br>
                • Existing bookings on any of these dates will prevent blocking.
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn-secondary"
                        style="flex: 1; justify-content: center;"
                        onclick="closeMultiBlockModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-primary"
                        style="flex: 1; justify-content: center;
                               background: linear-gradient(135deg, #ef4444, #dc2626);
                               color: white;">
                    <i class="fas fa-ban"></i> Yes, Block Them
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script>
// ============================================================
// DATA FROM PHP
// ============================================================
var housesData = <?php echo json_encode($houses); ?>;
var toursData  = <?php echo json_encode($tours); ?>;
var foodsData  = <?php echo json_encode($foods); ?>;
var calendarEvents = <?php echo json_encode($calendar_events); ?>;
var calType = <?php echo json_encode($cal_type); ?>;
var calId = <?php echo json_encode($cal_id); ?>;
var calItemName = <?php echo json_encode($cal_item_name); ?>;

var selectedDates = new Set();

// ============================================================
// ITEM SELECT DROPDOWNS
// ============================================================
function updateCalendarItems() {
    var type = document.getElementById('cal_type_select').value;
    var selectEl = document.getElementById('cal_id_select');
    selectEl.innerHTML = '<option value="">-- Select Item --</option>';
    var list = [];
    var labelKey = '';

    if (type === 'house') { list = housesData; labelKey = 'house_name'; }
    else if (type === 'tour') { list = toursData; labelKey = 'tour_name'; }
    else if (type === 'food') { list = foodsData; labelKey = 'name'; }

    list.forEach(function(item) {
        var opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = item[labelKey];
        selectEl.appendChild(opt);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (calType) {
        updateCalendarItems();
        if (calId) {
            document.getElementById('cal_id_select').value = calId;
        }
    }
});

// ============================================================
// HELPER: Check if a date has a booking or block
// ============================================================
function getEventForDate(dateStr) {
    var clicked = new Date(dateStr + 'T00:00:00');
    return calendarEvents.find(function(ev) {
        var start = new Date(ev.start + 'T00:00:00');
        var end = ev.end ? new Date(ev.end + 'T00:00:00') : start;
        return clicked >= start && clicked < end;
    });
}

// ============================================================
// MULTI-SELECT LOGIC
// ============================================================
function updateSelectionToolbar() {
    var toolbar = document.getElementById('selectionToolbar');
    var countEl = document.getElementById('selectedCount');
    countEl.textContent = selectedDates.size;
    if (selectedDates.size > 0) {
        toolbar.classList.add('show');
    } else {
        toolbar.classList.remove('show');
    }
}

function clearDateSelection() {
    selectedDates.clear();
    document.querySelectorAll('.fc-daygrid-day.selected-date').forEach(function(el) {
        el.classList.remove('selected-date');
    });
    updateSelectionToolbar();
}

function toggleDateSelection(dateStr, dayEl) {
    if (selectedDates.has(dateStr)) {
        selectedDates.delete(dateStr);
        if (dayEl) dayEl.classList.remove('selected-date');
    } else {
        selectedDates.add(dateStr);
        if (dayEl) dayEl.classList.add('selected-date');
    }
    updateSelectionToolbar();
}

// ============================================================
// FULLCALENDAR
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('itemCalendar');
    if (!calendarEl) return;

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,dayGridWeek'
        },
        events: calendarEvents,
        height: 'auto',
        contentHeight: 'auto',
        aspectRatio: 1.6,
        nowIndicator: true,
        dayMaxEvents: 3,
        weekends: true,
        selectable: false,

        dateClick: function(info) {
            if (!calType || !calId) return;

            var clickedDate = info.dateStr;
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var clicked = new Date(clickedDate + 'T00:00:00');

            if (clicked < today) {
                alert('❌ You cannot select a past date.');
                return;
            }

            var existingEvent = getEventForDate(clickedDate);
            if (existingEvent) {
                if (existingEvent.extendedProps.event_type === 'booking') {
                    alert('⚠️ There is already a booking on this date. It cannot be blocked.');
                    return;
                }
                if (existingEvent.extendedProps.event_type === 'blocked') {
                    openUnblockFromCalendar(existingEvent.extendedProps);
                    return;
                }
            }

            var dayEl = info.dayEl;
            toggleDateSelection(clickedDate, dayEl);
        },

        eventClick: function(info) {
            var props = info.event.extendedProps;

            if (props.event_type === 'blocked') {
                openUnblockFromCalendar(props);
            } else if (props.event_type === 'booking') {
                var msg = '💰 BOOKING DETAILS\n\n' +
                    'Reference: ' + props.reference + '\n' +
                    'Guest: ' + props.guest_name + '\n' +
                    'Date: ' + props.check_in + '\n' +
                    'Guests: ' + props.guests + '\n' +
                    'Total: ₱' + parseFloat(props.total).toLocaleString() + '\n' +
                    'Payment: ' + props.payment_status.toUpperCase() + '\n\n' +
                    'For full details, please go to Booking Management.';
                alert(msg);
            }
        },

        datesSet: function() {
            setTimeout(function() {
                document.querySelectorAll('.fc-daygrid-day').forEach(function(dayEl) {
                    var dateAttr = dayEl.getAttribute('data-date');
                    if (dateAttr && selectedDates.has(dateAttr)) {
                        dayEl.classList.add('selected-date');
                    }
                });
            }, 50);
        }
    });

    calendar.render();
    window.__blockedDatesCalendar = calendar;
});

// ============================================================
// MULTI-BLOCK MODAL
// ============================================================
function openMultiBlockModal() {
    if (selectedDates.size === 0) {
        alert('Please select at least one date first.');
        return;
    }
    if (!calType || !calId) {
        alert('Please select an item first.');
        return;
    }

    document.getElementById('multi_item_type').value = calType;
    document.getElementById('multi_item_id').value = calId;

    var sortedDates = Array.from(selectedDates).sort();
    document.getElementById('multi_dates_json').value = JSON.stringify(sortedDates);

    var typeLabel = calType === 'house' ? '🏠 House' : (calType === 'tour' ? '🚤 Tour' : '🍽️ Food');
    document.getElementById('multi_item_type_display').textContent = typeLabel;
    document.getElementById('multi_item_name_display').textContent = calItemName;
    document.getElementById('multi_total_count_display').textContent = sortedDates.length + ' date(s)';

    var preview = document.getElementById('multi_dates_preview');
    preview.innerHTML = '';
    sortedDates.forEach(function(dateStr) {
        var parts = dateStr.split('-');
        var dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
        var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var label = monthNames[dateObj.getMonth()] + ' ' + dateObj.getDate() + ', ' + dateObj.getFullYear() + ' (' + dayNames[dateObj.getDay()] + ')';

        var chip = document.createElement('span');
        chip.className = 'selected-date-chip';
        chip.innerHTML = '<span>' + label + '</span><button type="button" class="remove-chip" data-date="' + dateStr + '">×</button>';
        preview.appendChild(chip);
    });

    preview.querySelectorAll('.remove-chip').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.getAttribute('data-date');
            selectedDates.delete(d);
            updateSelectionToolbar();
            var dayEl = document.querySelector('.fc-daygrid-day[data-date="' + d + '"]');
            if (dayEl) dayEl.classList.remove('selected-date');
            if (selectedDates.size === 0) {
                closeMultiBlockModal();
            } else {
                openMultiBlockModal();
            }
        });
    });

    document.getElementById('multiBlockModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeMultiBlockModal() {
    document.getElementById('multiBlockModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// UNBLOCK FROM CALENDAR
// ============================================================
function openUnblockFromCalendar(props) {
    var blockTypeLabel = props.block_type_label || 'Blocked';
    var reason = props.reason || 'No reason provided';

    var msg = '🚫 BLOCKED DATE\n\n' +
        'Date: ' + props.block_date + '\n' +
        'Type: ' + blockTypeLabel + '\n' +
        'Reason: ' + reason + '\n\n' +
        'Do you want to UNBLOCK this date?';

    if (confirm(msg)) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="unblock_date_action" value="1">' +
                        '<input type="hidden" name="block_id" value="' + props.block_id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

// ============================================================
// SIDEBAR
// ============================================================
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    document.body.classList.toggle('sidebar-open-mobile');
}

// Auto-dismiss alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(function() { alert.remove(); }, 500);
    });
}, 5000);
</script>

</body>
</html>
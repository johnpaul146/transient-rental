<?php
/**
 * House Rebook Dashboard (GUEST only — the guest who owns the booking).
 *
 * Same dashboard pattern as food-rebook.php / package-rebook.php: current booking, rebooking rules
 * and allowance, deadline, a calendar and a live preview. House keeps its own rules (all in RebookService):
 *   - the guest picks the NEW CHECK-IN date; check-out = check-in + the original number of nights (never editable);
 *   - check-in / check-out TIMES stay exactly as saved on the booking;
 *   - max 2 rebooks per booking, within 7 days of the booking date;
 *   - the payment already received is carried forward (no new fee, no refund, nothing written to the ledger);
 *   - this is a REQUEST: the booking becomes "pending" and the owner/staff confirm or reject it in Booking Management.
 * This page only displays the rules and calls RebookService::requestHouseRebook(), which re-checks everything under locks.
 * Admin/staff behaviour is unchanged: they have no guest profile here and are sent back to profile.php.
 */
session_start();
require_once 'database.php';
require_once 'includes/TermsGate.php';
TermsGate::enforceGuest($pdo); // Terms & Privacy must be accepted before guest features
require_once 'config/mail_config.php';
require_once 'includes/EmailNotifications.php';
require_once 'includes/PaymentService.php';
require_once 'includes/RebookService.php';
require_once 'includes/emergency-ui.php';   // Phase 7.7 Emergency Rebook

if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

$isAjax = isset($_GET['ajax']);

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) jsonOut(['error' => 'Please log in again.'], 401);
    header("Location: index.php");
    exit();
}

// Helper — real client IP
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================================
// AUTO-ADD rebook columns safely if missing
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebook_count'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebook_count INT NOT NULL DEFAULT 0");
    } else {
        $pdo->exec("UPDATE house_bookings SET rebook_count = 0 WHERE rebook_count IS NULL");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebooked_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebooked_at DATETIME DEFAULT NULL");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebook_confirmed_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebook_confirmed_at DATETIME DEFAULT NULL");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'previous_check_in_date'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings
            ADD COLUMN previous_check_in_date DATE DEFAULT NULL,
            ADD COLUMN previous_check_out_date DATE DEFAULT NULL,
            ADD COLUMN previous_number_of_guests INT DEFAULT NULL,
            ADD COLUMN previous_total_amount DECIMAL(10,2) DEFAULT NULL");
    }
} catch(PDOException $e) {}

// Get guest info (the booking must be THEIRS — any other id looks like "not found")
$stmt = $pdo->prepare("SELECT * FROM guests WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$guest = $stmt->fetch();

$booking_id = (int)($_GET['booking_id'] ?? 0);
if ($booking_id <= 0) {
    if ($isAjax) jsonOut(['error' => 'Booking not found.'], 404);
    header("Location: profile.php");
    exit();
}

$stmt = $pdo->prepare("SELECT b.*, h.house_name, h.image, h.capacity
                       FROM house_bookings b
                       JOIN houses h ON b.house_id = h.id
                       WHERE b.id = ? AND b.guest_id = ? AND (b.package_id IS NULL OR b.package_id = 0)");
$stmt->execute([$booking_id, $guest['id'] ?? 0]);
$booking = $stmt->fetch();

if (!$booking) {
    if ($isAjax) jsonOut(['error' => 'Booking not found.'], 404);
    header("Location: profile.php");
    exit();
}

if (empty($_SESSION['house_rebook_csrf'])) $_SESSION['house_rebook_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['house_rebook_csrf'];

$blockReason = RebookService::houseBlockReason($booking);
// ---- Phase 7.7 Emergency Rebook (this page is guest-only). Same calendar, same validation: ONLY the 2-rebook limit and the 7-day window are lifted.
$emPending = EmergencyRebookService::pendingRequest($pdo, 'house', $booking_id);
$emergency = (bool)$emPending || EmergencyRebookUI::wanted(false);
$emEl = EmergencyRebookService::eligibility('house', $booking);
$emOffer = $emEl['eligible'] && !$emPending && EmergencyRebookService::tableReady($pdo);   // show the Emergency link on the normal screen
$emContact = '';
if ($emergency) {
    $blockReason = !EmergencyRebookService::tableReady($pdo) ? 'Emergency Rebook is not available right now.' : (($emEl['eligible'] || $emPending) ? null : $emEl['message']);
    $emContact = EmergencyRebookUI::contactFor($pdo, (int)$booking['guest_id']);
}
$ORIGINAL_NIGHTS = RebookService::houseNights($booking);
$times = RebookService::houseTimes($booking);
$timeEditable = RebookService::houseTimeColumnsReady($pdo);   // Phase 7.9.3: the check-in time can be changed only once the previous_*_time columns exist (migration_7_9_3)
$savedTimeHM = substr($times['in'], 0, 5);

// ------------------------------------------------------------------ AJAX (read-only)
if ($isAjax) {
    if ($blockReason !== null) jsonOut(['error' => $blockReason], 409);
    $mode = (string)$_GET['ajax'];
    // Phase 7.9.3: the SELECTED check-in time decides availability (exact datetimes). Absent = keep the saved time; malformed = refused.
    $reqTime = null;
    if (array_key_exists('time', $_GET)) {
        $reqTime = is_string($_GET['time']) ? trim($_GET['time']) : '';
        try { RebookService::houseRequestTimes($booking, $reqTime); }
        catch (InvalidArgumentException $e) { jsonOut(['ok' => false, 'error' => $e->getMessage(), 'message' => $e->getMessage()], 400); }
    }
    if ($mode === 'month') {
        $ym = (string)($_GET['ym'] ?? '');
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) jsonOut(['error' => 'Invalid month.'], 400);
        $days = [];
        $n = (int)date('t', strtotime($ym . '-01'));
        for ($d = 1; $d <= $n; $d++) {
            $ds = sprintf('%s-%02d', $ym, $d);
            $r = RebookService::houseDateCheck($pdo, $booking, $ds, $reqTime);
            $days[$ds] = ['ok' => $r['ok'], 'message' => $r['message']] + (isset($r['kind']) ? ['kind' => $r['kind']] : []);   // Phase 7.9: kind = hard | start (calendar meaning only)
        }
        jsonOut(['days' => $days]);
    }
    if ($mode === 'preview') {
        jsonOut(RebookService::houseDateCheck($pdo, $booking, (string)($_GET['date'] ?? ''), $reqTime));
    }
    jsonOut(['error' => 'Unknown request.'], 400);
}

// Blocked rebook attempt (opening the page for a booking that cannot be rebooked) — logged, as before
if ($blockReason !== null && $_SERVER['REQUEST_METHOD'] !== 'POST' && class_exists('SystemLogger')) {
    SystemLogger::log(
        $pdo,
        'rebook_blocked',
        'booking',
        "Guest '" . ($_SESSION['username'] ?? 'Unknown') . "' attempted to rebook booking #{$booking_id} but was blocked — Reason: {$blockReason}",
        (int)$booking_id,
        'house_booking',
        null,
        [
            'reason'  => $blockReason,
            'ip'      => getClientIp(),
            'page'    => 'rebook.php'
        ],
        'failed'
    );
}

// ------------------------------------------------------------------ SUBMIT (a request: status becomes pending)
$error = '';
if ($emergency && isset($_POST['emergency_rebook'])) {
    // Emergency Rebook: a request in its own table; THIS booking is not touched until Admin/Staff approve.
    $error = EmergencyRebookUI::handlePost($pdo, 'house', $booking_id, (int)$booking['guest_id'], $csrf);   // redirects to the dashboard on success
    $emPending = EmergencyRebookService::pendingRequest($pdo, 'house', $booking_id);
}
if (isset($_POST['confirm_rebook'])) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Your session expired. Please refresh the page and try again.');
        // Only the new check-in date and the number of guests come from the browser. Check-out, nights, times, price
        // and payment fields are decided by RebookService from the saved booking; anything posted for them is ignored.
        // Phase 7.9.3: the check-in TIME is also chosen by the guest (HH:MM). Check-out time is never read from the browser — it follows the check-in time.
        $postTime = array_key_exists('check_in_time', $_POST) ? (is_string($_POST['check_in_time']) ? trim($_POST['check_in_time']) : '') : null;
        $res = RebookService::requestHouseRebook($pdo, $booking_id, (int)$booking['guest_id'], (string)($_POST['check_in'] ?? ''), (int)($_POST['guests'] ?? 0), $postTime);

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'rebook',
                'booking',
                "Guest '" . ($_SESSION['username'] ?? 'Unknown') . "' rebooked house booking '{$res['reference']}' ({$res['house_name']}) — new dates: {$res['new_in']} to {$res['new_out']} ({$res['nights']} nights, {$res['guests']} pax, ₱" . number_format($res['total'], 2) . ") — Rebook #{$res['rebook_count']}/2",
                (int)$booking_id,
                'house_booking',
                [
                    'old_check_in'  => $res['old_in'],
                    'old_check_out' => $res['old_out'],
                    'old_check_in_time'  => $res['old_in_time'] ?? null,
                    'old_check_out_time' => $res['old_out_time'] ?? null,
                    'old_guests'    => $res['old_guests'],
                    'old_total'     => $res['old_total']
                ],
                [
                    'new_check_in'  => $res['new_in'],
                    'new_check_out' => $res['new_out'],
                    'new_check_in_time'  => $res['new_in_time'] ?? null,
                    'new_check_out_time' => $res['new_out_time'] ?? null,
                    'new_guests'    => $res['guests'],
                    'new_total'     => $res['total'],
                    'rebook_count'  => $res['rebook_count'],
                    'ip'            => getClientIp()
                ]
            );
        }

        // Notify admin/staff that a guest has rebooked and needs confirmation
        try {
            EmailNotifications::sendRebookAlert($booking_id, $pdo);
        } catch (Throwable $mailEx) {
            error_log("Rebook alert email failed (booking $booking_id): " . $mailEx->getMessage());
        }

        header("Location: profile.php?rebooked=1");
        exit();
    } catch (AvailabilityConflictException $e) {
        $error = 'Those dates are not available — ' . $e->getMessage() . ' Please choose another check-in date.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    if ($error !== '' && class_exists('SystemLogger')) {
        SystemLogger::log(
            $pdo,
            'rebook_failed',
            'booking',
            "Guest '" . ($_SESSION['username'] ?? 'Unknown') . "' FAILED to rebook booking '{$booking['reference_number']}' — Error: " . $error,
            (int)$booking_id,
            'house_booking',
            null,
            [
                'error'    => $error,
                'attempted_check_in'  => $_POST['check_in'] ?? null,
                'attempted_check_in_time' => isset($_POST['check_in_time']) && is_string($_POST['check_in_time']) ? substr($_POST['check_in_time'], 0, 8) : null,
                'attempted_guests'    => $_POST['guests'] ?? null,
                'ip'       => getClientIp(),
                'page'     => 'rebook.php'
            ],
            'failed'
        );
    }
    // The failed attempt may have changed nothing, but show fresh numbers and the current block reason
    $stmt = $pdo->prepare("SELECT b.*, h.house_name, h.image, h.capacity FROM house_bookings b JOIN houses h ON b.house_id = h.id WHERE b.id = ? AND b.guest_id = ?");
    $stmt->execute([$booking_id, $booking['guest_id']]);
    if ($fresh = $stmt->fetch()) { $booking = $fresh; $blockReason = RebookService::houseBlockReason($booking); }
}

$remaining = RebookService::remaining($booking);
$daysLeft = RebookService::daysLeft($booking);
$deadline = RebookService::deadline($booking);
$maxRebooks = RebookService::MAX_REBOOKS;
$amounts = PaymentService::amounts($booking);
$paid = (float)$amounts['paid'];
$total = (float)$booking['total_amount'];
$capacity = max(1, (int)($booking['capacity'] ?? 0) ?: 10);

// Dynamic content for the logo
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {}

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Jerry Dino\'s Stay';

function pr_dt($d, $t = null) { return date('M j, Y', strtotime($d)) . ($t ? ' · ' . date('g:i A', strtotime($t)) : ''); }
$startDate = substr((string)$booking['check_in_date'], 0, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rebook Your Stay - <?php echo htmlspecialchars($site_name); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php echo EmergencyRebookUI::css(); ?>
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;color:#1e293b;line-height:1.5;overflow-x:hidden}
.pr-header{background:#0B2447;color:#fff;padding:12px 16px}
.pr-header-in{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.pr-brand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-weight:700;min-width:0}
.pr-brand img{width:40px;height:40px;border-radius:10px;object-fit:cover}
.pr-brand span{overflow:hidden;text-overflow:ellipsis}
.pr-back{color:#fff;text-decoration:none;font-weight:600;font-size:14px;padding:8px 14px;border:1px solid rgba(255,255,255,.35);border-radius:10px;white-space:nowrap}
.pr-wrap{max-width:1100px;margin:0 auto;padding:18px 16px 40px}
.pr-title{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.pr-title i{width:44px;height:44px;border-radius:12px;background:#4DA6D9;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;flex:0 0 auto}
.pr-title h1{font-size:22px;color:#0B2447}
.pr-title small{display:block;color:#64748b;font-size:13px;font-weight:500;overflow-wrap:anywhere}
.pr-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
.pr-card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 2px 10px rgba(15,23,42,.06);min-width:0}
.pr-card h2{font-size:15px;color:#0B2447;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.pr-item{padding:10px 0;border-bottom:1px solid #eef2f7;display:flex;gap:10px}
.pr-item:last-child{border-bottom:0}
.pr-item>i{color:#4DA6D9;width:22px;text-align:center;margin-top:3px}
.pr-item b{display:block;font-size:14px}
.pr-item span{display:block;font-size:13px;color:#475569;overflow-wrap:anywhere}
.pr-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.pr-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px;text-align:center}
.pr-stat b{display:block;font-size:20px;color:#0B2447}
.pr-stat small{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.03em}
.pr-stat.warn b{color:#b45309}
.pr-rules{list-style:none;font-size:13px;color:#475569}
.pr-rules li{padding:5px 0;display:flex;gap:8px}
.pr-rules i{color:#10b981;margin-top:3px}
.pr-rules i.pend{color:#f59e0b}
.pr-alert{border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:14px;display:flex;gap:10px}
.pr-alert.err{background:#fee2e2;color:#991b1b}
.pr-alert.info{background:#fef3c7;color:#92400e}
.cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.cal-head h3{font-size:16px;color:#0B2447}
.cal-nav button{width:40px;height:40px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:10px;cursor:pointer;font-size:22px;line-height:1;color:#0B2447}
.cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
.cal-grid .dn{text-align:center;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;padding:4px 0}
.cal-grid .day{text-align:center;padding:10px 0;border-radius:8px;font-size:14px;cursor:pointer;border:1px solid transparent;background:#ecfdf5;color:#065f46;font-weight:600;min-height:40px}
.cal-grid .day:hover:not(.off):not(.sel){border-color:#10b981}
.cal-grid .day.off{background:#f1f5f9;color:#94a3b8;text-decoration:line-through;cursor:not-allowed;font-weight:500}
.cal-grid .day.nostart{background:#fff;color:#92400e;border:1px dashed #f59e0b;cursor:not-allowed;font-weight:600;text-decoration:none}
.cal-grid .day.today{position:relative}
.cal-grid .day.today::after{content:'';position:absolute;left:50%;bottom:4px;width:5px;height:5px;margin-left:-2.5px;border-radius:50%;background:#0B3D91}
.cal-grid .day.sel.today::after{background:#fff}
.cal-grid .day.cur{box-shadow:inset 0 0 0 2px #F4B400}
.cal-grid .day.cur.off{text-decoration:none;color:#92400e}
.cal-grid .day.rng{background:#dbeafe;color:#1e40af;text-decoration:none}
.cal-grid .day.outd{background:#dbeafe;color:#1e40af;text-decoration:none;box-shadow:inset 0 0 0 2px #4DA6D9}
.cal-grid .day.sel{background:#4DA6D9;color:#fff;box-shadow:0 4px 12px rgba(77,166,217,.4);text-decoration:none}
.cal-grid .day.rng.nostart,.cal-grid .day.outd.nostart,.cal-grid .day.sel.nostart{border-style:solid;border-color:transparent}
.cal-grid .day.blank{background:transparent;cursor:default}
.cal-grid .day.loading{opacity:.5}.cal-grid .day.loading:not(.sel):not(.rng):not(.outd):not(.blank){background:#f1f5f9;color:#94a3b8}   /* not green until the server has confirmed the day */
.cal-legend{display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;font-size:12px;color:#64748b}
.cal-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-1px}
.cal-note{margin-top:10px;font-size:13px;color:#475569;min-height:20px}
.pr-field{margin-top:14px}
.pr-field label{display:block;font-size:13px;font-weight:600;color:#0B2447;margin-bottom:6px}
.pr-field select,.pr-field input[type=time]{width:100%;box-sizing:border-box;min-height:44px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;font-size:15px;font-family:inherit;color:#1e293b}
.pr-preview{margin-top:14px;border:2px solid #4DA6D9;border-radius:12px;padding:12px 14px;background:#f0f9ff;display:none}
.pr-preview h3{font-size:14px;color:#0B2447;margin-bottom:6px}
.pr-preview .row{font-size:13px;padding:3px 0;overflow-wrap:anywhere}
.pr-preview .row i{color:#4DA6D9;width:20px}
.pr-btn{width:100%;margin-top:14px;min-height:48px;padding:12px;border:0;border-radius:12px;background:#F4B400;color:#0B2447;font-weight:700;font-size:15px;cursor:pointer}
.pr-btn:disabled{background:#e2e8f0;color:#94a3b8;cursor:not-allowed}
.pr-hint{margin-top:8px;font-size:12px;color:#64748b;text-align:center}
@media(max-width:820px){.pr-grid{grid-template-columns:1fr}}
@media(max-width:480px){.pr-card{padding:14px}.pr-title h1{font-size:19px}.pr-stat b{font-size:17px}.cal-grid{gap:3px}.cal-grid .day{padding:8px 0;font-size:13px}}
</style>
</head>
<body>
<div class="pr-header"><div class="pr-header-in">
    <a href="profile.php" class="pr-brand">
        <?php if ($nav_logo_exists): ?><img src="<?php echo htmlspecialchars($nav_logo); ?>" alt=""><?php endif; ?>
        <span><?php echo htmlspecialchars($site_name); ?></span>
    </a>
    <a href="profile.php" class="pr-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</div></div>

<div class="pr-wrap">
    <div class="pr-title">
        <i class="fas fa-redo-alt"></i>
        <h1><?php echo $emergency ? 'Emergency Rebook — House' : 'Rebook Your Stay'; ?> <small>Ref: <?php echo htmlspecialchars($booking['reference_number']); ?></small></h1>
    </div>

    <?php if ($error !== ''): ?><div class="pr-alert err" role="alert"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
    <?php if ($blockReason !== null): ?><div class="pr-alert info"><i class="fas fa-info-circle"></i><span><?php echo htmlspecialchars($blockReason); ?></span></div><?php endif; ?>
    <?php if ($emOffer && !$emergency): ?><div style="margin-bottom:14px;"><?php echo EmergencyRebookUI::link('rebook.php', $booking_id); ?></div><?php endif; ?>
    <?php if ($emergency && $blockReason === null): echo EmergencyRebookUI::banner('house', $emPending ? $emPending['eligibility'] : $emEl['reason']); endif; ?>

    <div class="pr-grid">
        <div>
            <div class="pr-card" style="margin-bottom:16px;">
                <h2><i class="fas fa-home"></i> Current booking</h2>
                <div class="pr-item"><i class="fas fa-house-user"></i><div><b><?php echo htmlspecialchars($booking['house_name']); ?></b>
                    <span>Ref <?php echo htmlspecialchars($booking['reference_number']); ?></span></div></div>
                <div class="pr-item"><i class="fas fa-sign-in-alt"></i><div><b>Check-in</b>
                    <span><?php echo htmlspecialchars(pr_dt($booking['check_in_date'], $times['in'])); ?></span></div></div>
                <div class="pr-item"><i class="fas fa-sign-out-alt"></i><div><b>Check-out</b>
                    <span><?php echo htmlspecialchars(pr_dt($booking['check_out_date'], $times['out'])); ?></span></div></div>
                <div class="pr-item"><i class="fas fa-moon"></i><div><b>Stay</b>
                    <span><?php echo $ORIGINAL_NIGHTS; ?> night<?php echo $ORIGINAL_NIGHTS === 1 ? '' : 's'; ?> · <?php echo (int)$booking['number_of_guests']; ?> guest<?php echo (int)$booking['number_of_guests'] === 1 ? '' : 's'; ?></span></div></div>
                <div class="pr-item"><i class="fas fa-wallet"></i><div><b>Payment</b>
                    <span>₱<?php echo number_format($paid, 2); ?> paid of ₱<?php echo number_format($total, 2); ?> — stays on this booking<?php if ($total > $paid): ?> (balance ₱<?php echo number_format($total - $paid, 2); ?> on arrival)<?php endif; ?></span></div></div>
            </div>

            <div class="pr-card">
                <h2><i class="fas fa-gavel"></i> Rebooking rules</h2>
                <div class="pr-stats">
                    <div class="pr-stat <?php echo $remaining < 1 ? 'warn' : ''; ?>"><b><?php echo $remaining; ?> / <?php echo $maxRebooks; ?></b><small>Rebooks left</small></div>
                    <div class="pr-stat <?php echo $daysLeft <= 2 ? 'warn' : ''; ?>"><b><?php echo $daysLeft; ?></b><small>Day(s) left</small></div>
                    <div class="pr-stat"><b><?php echo $deadline ? htmlspecialchars(date('M j', strtotime($deadline))) : '—'; ?></b><small>Deadline</small></div>
                </div>
                <?php if ($emergency): ?>
                <ul class="pr-rules">
                    <li><i class="fas fa-exclamation-triangle" style="color:#ea580c"></i><span><strong>Emergency Rebook</strong> is only for bookings whose normal rebook is closed (limit reached or 7-day window passed).</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Same stay length: exactly <strong><?php echo $ORIGINAL_NIGHTS; ?> night<?php echo $ORIGINAL_NIGHTS === 1 ? '' : 's'; ?></strong>. <?php echo $timeEditable ? 'You can also change the check-in time; check-out uses the same time.' : 'Check-in and check-out times stay the same.'; ?></span></li>
                    <li><i class="fas fa-check-circle"></i><span>Your payment is <strong>carried forward</strong>: no new reservation fee and price unchanged.</span></li>
                    <li><i class="fas fa-hourglass-half pend"></i><span><strong>This is a request.</strong> It still requires Admin/Staff approval and availability; it does not guarantee rescheduling. Your current stay stays as it is until then, and you can cancel the request from the dashboard.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>The house must be free for the whole new stay. It is checked now and again at approval.</span></li>
                </ul>
                <?php else: ?>
                <ul class="pr-rules">
                    <li><i class="fas fa-check-circle"></i><span>Same stay length: exactly <strong><?php echo $ORIGINAL_NIGHTS; ?> night<?php echo $ORIGINAL_NIGHTS === 1 ? '' : 's'; ?></strong>. You pick the new check-in date and the check-out date follows automatically.</span></li>
                    <?php if ($timeEditable): ?>
                    <li><i class="fas fa-check-circle"></i><span>Your current check-in time is <strong><?php echo date('g:i A', strtotime($times['in'])); ?></strong>. You may keep it or choose another check-in time; the check-out time automatically uses the same time.</span></li>
                    <?php else: ?>
                    <li><i class="fas fa-check-circle"></i><span>Your check-in time (<strong><?php echo date('g:i A', strtotime($times['in'])); ?></strong>) and check-out time (<strong><?php echo date('g:i A', strtotime($times['out'])); ?></strong>) stay the same.</span></li>
                    <?php endif; ?>
                    <li><i class="fas fa-check-circle"></i><span>Your payment is <strong>carried forward</strong>: no new reservation fee, no refund, price unchanged.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Up to <?php echo $maxRebooks; ?> rebooks per booking, within <?php echo RebookService::WINDOW_DAYS; ?> days from the booking date. Confirmed bookings, or cancelled bookings with money received, can be rebooked — completed stays cannot.</span></li>
                    <li><i class="fas fa-hourglass-half pend"></i><span><strong>This is a request.</strong> Our team has to confirm it, so the booking shows as <strong>Pending Rebook</strong> until then. If it cannot be approved, your original stay is restored.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>The house must be free for the whole new stay. It is checked again when you submit and when our team confirms.</span></li>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="pr-card">
            <h2><i class="fas fa-calendar-alt"></i> Choose your new check-in date<?php echo $timeEditable ? ' and time' : ''; ?></h2>
            <?php if ($emPending && $emergency): ?>
            <?php echo EmergencyRebookUI::pendingBox('house', $emPending, 'profile.php'); ?>
            <?php elseif ($blockReason === null && $ORIGINAL_NIGHTS >= 1): ?>
            <div class="cal-head">
                <h3 id="calTitle"></h3>
                <div class="cal-nav"><button type="button" id="calPrev" aria-label="Previous month">&lsaquo;</button> <button type="button" id="calNext" aria-label="Next month">&rsaquo;</button></div>
            </div>
            <div class="cal-grid" id="calGrid"></div>
            <div class="cal-legend"><span><i style="background:#ecfdf5;border:1px solid #10b981"></i>Available check-in</span><span><i style="background:#f1f5f9;border:1px solid #cbd5e1"></i>Booked / blocked</span><span><i style="background:#fff;border:1px dashed #f59e0b"></i>Free night, but your <?php echo $ORIGINAL_NIGHTS; ?>-night stay can't start here</span><span><i style="background:#4DA6D9"></i>New check-in</span><span><i style="background:#dbeafe"></i>Your new stay</span><span><i style="box-shadow:inset 0 0 0 2px #F4B400"></i>Current check-in</span><span><i style="background:#fff;border:1px solid #cbd5e1;position:relative"><b style="position:absolute;left:3px;top:3px;width:5px;height:5px;border-radius:50%;background:#0B3D91"></b></i>Today</span></div>
            <div class="cal-note" id="calNote" aria-live="polite">Pick your new check-in date. Booked or blocked nights are greyed out; dashed dates are free but cannot be your check-in — tap any date to see why. Check-out is set <?php echo $ORIGINAL_NIGHTS; ?> night<?php echo $ORIGINAL_NIGHTS === 1 ? '' : 's'; ?> later.</div>
            <form method="POST" id="rebookForm">
                <div class="pr-field">
                    <label for="guests"><i class="fas fa-users"></i> Number of guests</label>
                    <select name="guests" id="guests" required>
                        <?php $curPax = min($capacity, max(1, (int)($booking['number_of_guests'] ?? 1))); for ($i = 1; $i <= $capacity; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i === $curPax ? 'selected' : ''; ?>><?php echo $i; ?> guest<?php echo $i === 1 ? '' : 's'; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php if ($timeEditable): ?>
                <div class="pr-field">
                    <label for="checkInTime"><i class="fas fa-clock"></i> Check-in time</label>
                    <input type="time" name="check_in_time" id="checkInTime" value="<?php echo htmlspecialchars($savedTimeHM); ?>" step="60" required>
                    <small class="pr-note" id="timeNote" style="display:block;margin-top:4px;color:#64748b;font-size:12.5px">Check-out time will automatically use the same time.</small>
                </div>
                <?php endif; ?>
                <div class="pr-preview" id="preview" aria-live="polite"></div>
                <?php if ($emergency): ?><input type="hidden" name="emergency_rebook" value="1"><?php else: ?><input type="hidden" name="confirm_rebook" value="1"><?php endif; ?>
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="check_in" id="newDate" value="">
                <?php if ($emergency) echo EmergencyRebookUI::fields($emContact); ?>
                <button type="submit" class="pr-btn<?php echo $emergency ? ' em-btn' : ''; ?>" id="submitBtn" disabled><i class="fas fa-paper-plane"></i> <?php echo $emergency ? 'Submit Emergency Request' : 'Submit Rebook Request'; ?></button>
                <p class="pr-hint"><?php if ($emergency): ?>This is an Emergency Rebook request and still requires Admin/Staff approval. Your current stay stays as it is until then.<?php else: ?>Your booking stays as <strong>Pending Rebook</strong> until our team confirms the new dates.<?php endif; ?></p>
            </form>
            <?php else: ?>
            <p style="font-size:14px;color:#64748b;">This booking cannot be rebooked right now.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($blockReason === null && $ORIGINAL_NIGHTS >= 1 && !($emPending && $emergency)): ?>
<?php $rbcEmergency = $emergency; require __DIR__ . '/includes/rebook-confirm.php'; ?>
<?php if ($emergency) echo EmergencyRebookUI::js(); ?>
<script>
(function () {
    var BOOKING_ID = <?php echo (int)$booking_id; ?>, EMQ = <?php echo $emergency ? "'&emergency=1'" : "''"; ?>;
    var CURRENT = <?php echo json_encode($startDate); ?>;
    var TODAY = <?php echo json_encode(date('Y-m-d')); ?>;
    var MAXD = <?php echo json_encode(date('Y-m-d', strtotime('+365 days'))); ?>;
    var NIGHTS = <?php echo (int)$ORIGINAL_NIGHTS; ?>;
    var TIME_EDIT = <?php echo $timeEditable ? 'true' : 'false'; ?>, SAVED_TIME = <?php echo json_encode($savedTimeHM); ?>;
    var CUR_OUT_TIME = <?php echo json_encode($times['out']); ?>;   // saved check-out time (Phase 7.9.3.1: the new check-out time always follows the new check-in time)
    var timeEl = document.getElementById('checkInTime');
    var CUR_IN = <?php echo json_encode(pr_dt($booking['check_in_date'], $times['in'])); ?>, CUR_OUT = <?php echo json_encode(pr_dt($booking['check_out_date'], $times['out'])); ?>;   // for the confirmation step only
    var grid = document.getElementById('calGrid'), title = document.getElementById('calTitle');
    var note = document.getElementById('calNote'), prev = document.getElementById('preview');
    var btn = document.getElementById('submitBtn'), hidden = document.getElementById('newDate'), pax = document.getElementById('guests');
    var start = CURRENT < TODAY ? TODAY : CURRENT;
    var parts = start.split('-'), y = +parts[0], m = +parts[1] - 1, selected = null, plan = null, token = 0;
    var curTime = TIME_EDIT && timeEl ? timeEl.value : '';   // '' = not sent (old behaviour); otherwise HH:MM, validated by the server on every call
    var cache = {}, MN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function tq() { return TIME_EDIT ? '&time=' + encodeURIComponent(curTime) : ''; }   // the selected time travels with every availability call
    function validTime(t) { return /^([01]\d|2[0-3]):[0-5]\d$/.test(t); }
    function pad(n) { return String(n).padStart(2, '0'); }
    function fd(d) { var p = d.split('-'); return MN[+p[1] - 1].slice(0, 3) + ' ' + (+p[2]) + ', ' + p[0]; }
    function ft(t) { if (!t) return ''; var p = t.split(':'), h = +p[0], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + p[1] + ' ' + ap; }

    function draw(info) {
        var first = new Date(y, m, 1).getDay(), n = new Date(y, m + 1, 0).getDate(), ym = y + '-' + pad(m + 1);
        title.textContent = MN[m] + ' ' + y;
        var h = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(function (d) { return '<div class="dn">' + d + '</div>'; }).join('');
        for (var i = 0; i < first; i++) h += '<div class="day blank"></div>';
        for (var d = 1; d <= n; d++) {
            var ds = ym + '-' + pad(d), cls = 'day', r = info ? info[ds] : null;
            // nostart = the night itself is free but this N-night stay cannot BEGIN here (it may still lie inside an earlier stay)
            var nostart = !!(r && !r.ok && r.kind === 'start' && ds >= TODAY && ds <= MAXD);
            var off = ds < TODAY || ds > MAXD || (r && !r.ok && !nostart);
            if (!info) cls += ' loading';
            if (off) cls += ' off';
            else if (nostart) cls += ' nostart';
            if (ds === TODAY) cls += ' today';
            if (ds === CURRENT) cls += ' cur';
            if (plan && selected) {
                if (ds === selected) cls += ' sel';
                else if (ds > selected && ds < plan.out) cls += ' rng';
                else if (ds === plan.out) cls += ' outd';
            } else if (ds === selected) cls += ' sel';
            h += '<div class="' + cls + '" data-d="' + ds + '" role="button" aria-disabled="' + (off || nostart ? 'true' : 'false') + '">' + d + '</div>';
        }
        grid.innerHTML = h;
    }
    function load() {
        var ym = y + '-' + pad(m + 1), my = ++token, ck = ym + '|' + curTime, ct = curTime;
        if (TIME_EDIT && !validTime(curTime)) { draw(null); return; }
        if (cache[ck]) { draw(cache[ck]); return; }
        draw(null);
        fetch('rebook.php?booking_id=' + BOOKING_ID + EMQ + '&ajax=month&ym=' + ym + tq(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.days) { cache[ck] = j.days; if (my === token && ct === curTime) draw(j.days); } else if (ct === curTime) { note.textContent = j.error || 'Could not load availability.'; } })
            .catch(function () { note.textContent = 'Could not load availability. Please refresh.'; });
    }
    function showPlan() {
        if (!plan) return;
        var g = +pax.value, h = '<h3><i class="fas fa-calendar-check"></i> New schedule</h3>';
        h += '<div class="row"><i class="fas fa-sign-in-alt"></i> New check-in: <strong>' + fd(plan.in) + (plan.in_time ? ', ' + ft(plan.in_time) : '') + '</strong></div>';
        h += '<div class="row"><i class="fas fa-sign-out-alt"></i> New check-out: <strong>' + fd(plan.out) + (plan.out_time ? ', ' + ft(plan.out_time) : '') + '</strong></div>';
        if (plan.out_time && CUR_OUT_TIME && CUR_OUT_TIME.slice(0, 5) !== SAVED_TIME && plan.out_time.slice(0, 5) !== CUR_OUT_TIME.slice(0, 5)) h += '<div class="row"><i class="fas fa-info-circle"></i> Check-out time changes from <strong>' + ft(CUR_OUT_TIME) + '</strong> to <strong>' + ft(plan.out_time) + '</strong> (it always follows the check-in time).</div>';
        h += '<div class="row"><i class="fas fa-moon"></i> Stay: ' + plan.nights + ' night' + (plan.nights === 1 ? '' : 's') + ' · ' + g + ' guest' + (g === 1 ? '' : 's') + '</div>';
        h += '<div class="row"><i class="fas fa-wallet"></i> Your payment carries over. No new fee.</div>';
        h += '<div class="row"><i class="fas fa-hourglass-half"></i> Status after you submit: Pending Rebook (our team confirms).</div>';
        prev.innerHTML = h; prev.style.display = 'block';
    }
    grid.addEventListener('click', function (e) {
        var el = e.target.closest('.day[data-d]'); if (!el) return;
        var ds = el.getAttribute('data-d'), ym = y + '-' + pad(m + 1), info = cache[ym + '|' + curTime] && cache[ym + '|' + curTime][ds];
        if (el.classList.contains('off') || el.classList.contains('nostart')) {
            note.textContent = ds === CURRENT ? (TIME_EDIT ? 'This is your current check-in date and time. Choose a different date, or change the check-in time.' : 'This is your current check-in date. Please choose a different check-in date.') : ds < TODAY ? 'That date has passed.' : (ds > MAXD ? 'Please choose a date within the next 12 months.' : ((info && info.message) || 'Not available.'));
            return;
        }
        selected = ds; plan = null; hidden.value = ds; btn.disabled = true; note.textContent = 'Checking ' + fd(ds) + '…'; prev.style.display = 'none';
        draw(cache[ym + '|' + curTime]);
        checkSelected(false);
    });
    // Server-validate the selected DATE + TIME (same call for a click and for a time change). Submit stays disabled until it passes.
    function checkSelected(clearOnFail) {
        var ds = selected, ct = curTime;
        if (!ds) return;
        if (TIME_EDIT && !validTime(ct)) { btn.disabled = true; return; }
        fetch('rebook.php?booking_id=' + BOOKING_ID + EMQ + '&ajax=preview&date=' + ds + tq(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (selected !== ds || curTime !== ct) return;      // a newer click / time change owns the screen
                var key = y + '-' + pad(m + 1) + '|' + curTime;
                if (j.ok) {
                    plan = j.plan; note.textContent = 'The house is free from ' + fd(plan.in) + (plan.in_time ? ', ' + ft(plan.in_time) : '') + ' to ' + fd(plan.out) + (plan.out_time ? ', ' + ft(plan.out_time) : '') + '.';
                    showPlan(); btn.disabled = false; draw(cache[key]);
                } else {
                    plan = null; btn.disabled = true; prev.style.display = 'none';
                    note.textContent = j.message || j.error || 'Not available.';
                    if (clearOnFail) { selected = null; hidden.value = ''; draw(cache[key]); }
                }
            })
            .catch(function () { if (selected === ds && curTime === ct) { plan = null; btn.disabled = true; note.textContent = 'Could not check that date. Please try again.'; } });
    }
    // The check-in time changed: the calendar and the preview must be re-evaluated for the NEW time.
    function onTime() {
        if (!TIME_EDIT || !timeEl) return;
        var t = timeEl.value;
        if (t === curTime) return;
        curTime = t; plan = null; btn.disabled = true; prev.style.display = 'none';
        if (!validTime(t)) { note.textContent = 'Please choose a valid check-in time.'; draw(null); return; }
        note.textContent = 'Checking availability for ' + ft(t + ':00') + '…';
        load();
        if (selected) checkSelected(true); else note.textContent = 'Pick your new check-in date. Availability shown is for a ' + ft(t + ':00') + ' check-in.';
    }
    if (timeEl) { timeEl.addEventListener('input', onTime); timeEl.addEventListener('change', onTime); }
    pax.addEventListener('change', showPlan);
    document.getElementById('calPrev').addEventListener('click', function () { m--; if (m < 0) { m = 11; y--; } load(); });
    document.getElementById('calNext').addEventListener('click', function () { m++; if (m > 11) { m = 0; y++; } load(); });
    document.getElementById('rebookForm').addEventListener('submit', function (e) {
        if (!hidden.value || btn.disabled || !plan) { e.preventDefault(); return; }
        if (TIME_EDIT && (!timeEl || !validTime(timeEl.value) || (plan.in_time || '').slice(0, 5) !== timeEl.value)) { e.preventDefault(); return; }   // the plan on screen must be the time in the field
        var em = null;
        if (window.EmergencyForm) { em = EmergencyForm.check(); if (!em.ok) { e.preventDefault(); return; } }
        if (!RebookConfirm.gate(e, [
            { label: 'Check-in', from: CUR_IN, to: fd(plan.in) + (plan.in_time ? ' · ' + ft(plan.in_time) : '') },
            { label: 'Check-out', from: CUR_OUT, to: fd(plan.out) + (plan.out_time ? ' · ' + ft(plan.out_time) : '') }
        ], em ? em.reason : '')) return;                                   // first click opens the confirmation; the confirmed submit continues below
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending request…';   // one click = one request
    });
    load();
})();
</script>
<?php endif; ?>
</body>
</html>

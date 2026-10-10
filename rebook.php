<?php
/**
 * House Rebook Dashboard (GUEST only — the guest who owns the booking).
 *
 * Same dashboard pattern as tour-rebook.php / food-rebook.php / package-rebook.php: current booking, rebooking rules
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
$ORIGINAL_NIGHTS = RebookService::houseNights($booking);
$times = RebookService::houseTimes($booking);

// ------------------------------------------------------------------ AJAX (read-only)
if ($isAjax) {
    if ($blockReason !== null) jsonOut(['error' => $blockReason], 409);
    $mode = (string)$_GET['ajax'];
    if ($mode === 'month') {
        $ym = (string)($_GET['ym'] ?? '');
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) jsonOut(['error' => 'Invalid month.'], 400);
        $days = [];
        $n = (int)date('t', strtotime($ym . '-01'));
        for ($d = 1; $d <= $n; $d++) {
            $ds = sprintf('%s-%02d', $ym, $d);
            $r = RebookService::houseDateCheck($pdo, $booking, $ds);
            $days[$ds] = ['ok' => $r['ok'], 'message' => $r['message']];
        }
        jsonOut(['days' => $days]);
    }
    if ($mode === 'preview') {
        jsonOut(RebookService::houseDateCheck($pdo, $booking, (string)($_GET['date'] ?? '')));
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
if (isset($_POST['confirm_rebook'])) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Your session expired. Please refresh the page and try again.');
        // Only the new check-in date and the number of guests come from the browser. Check-out, nights, times, price
        // and payment fields are decided by RebookService from the saved booking; anything posted for them is ignored.
        $res = RebookService::requestHouseRebook($pdo, $booking_id, (int)$booking['guest_id'], (string)($_POST['check_in'] ?? ''), (int)($_POST['guests'] ?? 0));

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
                    'old_guests'    => $res['old_guests'],
                    'old_total'     => $res['old_total']
                ],
                [
                    'new_check_in'  => $res['new_in'],
                    'new_check_out' => $res['new_out'],
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
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

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
.cal-grid .day.cur{box-shadow:inset 0 0 0 2px #F4B400}
.cal-grid .day.cur.off{text-decoration:none;color:#92400e}
.cal-grid .day.rng{background:#dbeafe;color:#1e40af;text-decoration:none}
.cal-grid .day.outd{background:#dbeafe;color:#1e40af;text-decoration:none;box-shadow:inset 0 0 0 2px #4DA6D9}
.cal-grid .day.sel{background:#4DA6D9;color:#fff;box-shadow:0 4px 12px rgba(77,166,217,.4);text-decoration:none}
.cal-grid .day.blank{background:transparent;cursor:default}
.cal-grid .day.loading{opacity:.5}
.cal-legend{display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;font-size:12px;color:#64748b}
.cal-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-1px}
.cal-note{margin-top:10px;font-size:13px;color:#475569;min-height:20px}
.pr-field{margin-top:14px}
.pr-field label{display:block;font-size:13px;font-weight:600;color:#0B2447;margin-bottom:6px}
.pr-field select{width:100%;min-height:44px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;font-size:15px;font-family:inherit;color:#1e293b}
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
        <h1>Rebook Your Stay <small>Ref: <?php echo htmlspecialchars($booking['reference_number']); ?></small></h1>
    </div>

    <?php if ($error !== ''): ?><div class="pr-alert err" role="alert"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
    <?php if ($blockReason !== null): ?><div class="pr-alert info"><i class="fas fa-info-circle"></i><span><?php echo htmlspecialchars($blockReason); ?></span></div><?php endif; ?>

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
                <ul class="pr-rules">
                    <li><i class="fas fa-check-circle"></i><span>Same stay length: exactly <strong><?php echo $ORIGINAL_NIGHTS; ?> night<?php echo $ORIGINAL_NIGHTS === 1 ? '' : 's'; ?></strong>. You pick the new check-in date and the check-out date follows automatically.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Your check-in time (<strong><?php echo date('g:i A', strtotime($times['in'])); ?></strong>) and check-out time (<strong><?php echo date('g:i A', strtotime($times['out'])); ?></strong>) stay the same.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Your payment is <strong>carried forward</strong>: no new reservation fee, no refund, price unchanged.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Up to <?php echo $maxRebooks; ?> rebooks per booking, within <?php echo RebookService::WINDOW_DAYS; ?> days from the booking date. Confirmed bookings, or cancelled bookings with money received, can be rebooked — completed stays cannot.</span></li>
                    <li><i class="fas fa-hourglass-half pend"></i><span><strong>This is a request.</strong> Our team has to confirm it, so the booking shows as <strong>Pending Rebook</strong> until then. If it cannot be approved, your original stay is restored.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>The house must be free for the whole new stay. It is checked again when you submit and when our team confirms.</span></li>
                </ul>
            </div>
        </div>

        <div class="pr-card">
            <h2><i class="fas fa-calendar-alt"></i> Choose your new check-in date</h2>
            <?php if ($blockReason === null && $ORIGINAL_NIGHTS >= 1): ?>
            <div class="cal-head">
                <h3 id="calTitle"></h3>
                <div class="cal-nav"><button type="button" id="calPrev" aria-label="Previous month">&lsaquo;</button> <button type="button" id="calNext" aria-label="Next month">&rsaquo;</button></div>
            </div>
            <div class="cal-grid" id="calGrid"></div>
            <div class="cal-legend"><span><i style="background:#ecfdf5;border:1px solid #10b981"></i>Available check-in</span><span><i style="background:#f1f5f9;border:1px solid #cbd5e1"></i>Unavailable</span><span><i style="background:#4DA6D9"></i>New check-in</span><span><i style="background:#dbeafe"></i>Your new stay</span><span><i style="box-shadow:inset 0 0 0 2px #F4B400"></i>Current check-in</span></div>
            <div class="cal-note" id="calNote" aria-live="polite">Pick your new check-in date. Unavailable dates are greyed out — tap one to see why. Check-out is set <?php echo $ORIGINAL_NIGHTS; ?> night<?php echo $ORIGINAL_NIGHTS === 1 ? '' : 's'; ?> later.</div>
            <form method="POST" id="rebookForm">
                <div class="pr-field">
                    <label for="guests"><i class="fas fa-users"></i> Number of guests</label>
                    <select name="guests" id="guests" required>
                        <?php $curPax = min($capacity, max(1, (int)($booking['number_of_guests'] ?? 1))); for ($i = 1; $i <= $capacity; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i === $curPax ? 'selected' : ''; ?>><?php echo $i; ?> guest<?php echo $i === 1 ? '' : 's'; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="pr-preview" id="preview" aria-live="polite"></div>
                <input type="hidden" name="confirm_rebook" value="1">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="check_in" id="newDate" value="">
                <button type="submit" class="pr-btn" id="submitBtn" disabled><i class="fas fa-paper-plane"></i> Submit Rebook Request</button>
                <p class="pr-hint">Your booking stays as <strong>Pending Rebook</strong> until our team confirms the new dates.</p>
            </form>
            <?php else: ?>
            <p style="font-size:14px;color:#64748b;">This booking cannot be rebooked right now.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($blockReason === null && $ORIGINAL_NIGHTS >= 1): ?>
<?php require __DIR__ . '/includes/rebook-confirm.php'; ?>
<script>
(function () {
    var BOOKING_ID = <?php echo (int)$booking_id; ?>;
    var CURRENT = <?php echo json_encode($startDate); ?>;
    var TODAY = <?php echo json_encode(date('Y-m-d')); ?>;
    var MAXD = <?php echo json_encode(date('Y-m-d', strtotime('+365 days'))); ?>;
    var NIGHTS = <?php echo (int)$ORIGINAL_NIGHTS; ?>;
    var CUR_IN = <?php echo json_encode(pr_dt($booking['check_in_date'], $times['in'])); ?>, CUR_OUT = <?php echo json_encode(pr_dt($booking['check_out_date'], $times['out'])); ?>;   // for the confirmation step only
    var grid = document.getElementById('calGrid'), title = document.getElementById('calTitle');
    var note = document.getElementById('calNote'), prev = document.getElementById('preview');
    var btn = document.getElementById('submitBtn'), hidden = document.getElementById('newDate'), pax = document.getElementById('guests');
    var start = CURRENT < TODAY ? TODAY : CURRENT;
    var parts = start.split('-'), y = +parts[0], m = +parts[1] - 1, selected = null, plan = null, token = 0;
    var cache = {}, MN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
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
            var off = ds < TODAY || ds > MAXD || (r && !r.ok);
            if (!info) cls += ' loading';
            if (off) cls += ' off';
            if (ds === CURRENT) cls += ' cur';
            if (plan && selected) {
                if (ds === selected) cls += ' sel';
                else if (ds > selected && ds < plan.out) cls += ' rng';
                else if (ds === plan.out) cls += ' outd';
            } else if (ds === selected) cls += ' sel';
            h += '<div class="' + cls + '" data-d="' + ds + '" role="button" aria-disabled="' + (off ? 'true' : 'false') + '">' + d + '</div>';
        }
        grid.innerHTML = h;
    }
    function load() {
        var ym = y + '-' + pad(m + 1), my = ++token;
        if (cache[ym]) { draw(cache[ym]); return; }
        draw(null);
        fetch('rebook.php?booking_id=' + BOOKING_ID + '&ajax=month&ym=' + ym, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.days) { cache[ym] = j.days; if (my === token) draw(j.days); } else { note.textContent = j.error || 'Could not load availability.'; } })
            .catch(function () { note.textContent = 'Could not load availability. Please refresh.'; });
    }
    function showPlan() {
        if (!plan) return;
        var g = +pax.value, h = '<h3><i class="fas fa-calendar-check"></i> New schedule</h3>';
        h += '<div class="row"><i class="fas fa-sign-in-alt"></i> New check-in: <strong>' + fd(plan.in) + (plan.in_time ? ', ' + ft(plan.in_time) : '') + '</strong></div>';
        h += '<div class="row"><i class="fas fa-sign-out-alt"></i> New check-out: <strong>' + fd(plan.out) + (plan.out_time ? ', ' + ft(plan.out_time) : '') + '</strong></div>';
        h += '<div class="row"><i class="fas fa-moon"></i> Stay: ' + plan.nights + ' night' + (plan.nights === 1 ? '' : 's') + ' · ' + g + ' guest' + (g === 1 ? '' : 's') + '</div>';
        h += '<div class="row"><i class="fas fa-wallet"></i> Your payment carries over. No new fee.</div>';
        h += '<div class="row"><i class="fas fa-hourglass-half"></i> Status after you submit: Pending Rebook (our team confirms).</div>';
        prev.innerHTML = h; prev.style.display = 'block';
    }
    grid.addEventListener('click', function (e) {
        var el = e.target.closest('.day[data-d]'); if (!el) return;
        var ds = el.getAttribute('data-d'), ym = y + '-' + pad(m + 1), info = cache[ym] && cache[ym][ds];
        if (el.classList.contains('off')) {
            note.textContent = ds === CURRENT ? 'This is your current check-in date. Please choose a different check-in date.' : ds < TODAY ? 'That date has passed.' : (ds > MAXD ? 'Please choose a date within the next 12 months.' : ((info && info.message) || 'Not available.'));
            return;
        }
        selected = ds; plan = null; hidden.value = ds; btn.disabled = true; note.textContent = 'Checking ' + fd(ds) + '…'; prev.style.display = 'none';
        draw(cache[ym]);
        fetch('rebook.php?booking_id=' + BOOKING_ID + '&ajax=preview&date=' + ds, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (selected !== ds) return;
                if (j.ok) {
                    plan = j.plan; note.textContent = 'The house is free from ' + fd(plan.in) + ' to ' + fd(plan.out) + '.';
                    showPlan(); btn.disabled = false; draw(cache[y + '-' + pad(m + 1)]);
                } else { note.textContent = j.message || j.error || 'Not available.'; btn.disabled = true; }
            })
            .catch(function () { note.textContent = 'Could not check that date. Please try again.'; });
    });
    pax.addEventListener('change', showPlan);
    document.getElementById('calPrev').addEventListener('click', function () { m--; if (m < 0) { m = 11; y--; } load(); });
    document.getElementById('calNext').addEventListener('click', function () { m++; if (m > 11) { m = 0; y++; } load(); });
    document.getElementById('rebookForm').addEventListener('submit', function (e) {
        if (!hidden.value || btn.disabled || !plan) { e.preventDefault(); return; }
        if (!RebookConfirm.gate(e, [
            { label: 'Check-in', from: CUR_IN, to: fd(plan.in) + (plan.in_time ? ' · ' + ft(plan.in_time) : '') },
            { label: 'Check-out', from: CUR_OUT, to: fd(plan.out) + (plan.out_time ? ' · ' + ft(plan.out_time) : '') }
        ])) return;                                   // first click opens the confirmation; the confirmed submit continues below
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending request…';   // one click = one request
    });
    load();
})();
</script>
<?php endif; ?>
</body>
</html>

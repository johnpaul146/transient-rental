<?php
/**
 * Tour Rebook Dashboard (guest, or ADMIN / STAFF from Booking Management).
 *
 * Shows the current tour, the rebooking rules and allowance, the deadline, a calendar and a time choice.
 * Phase 7.6: the guest (or staff assisting the guest) picks a new DATE and TIME and submits a REQUEST; the booking
 * stays exactly as it is until Admin/Staff confirm it in Booking Management (Rebooks tab). Same boat, same pax,
 * same price, same payment. All rules live in RebookService (plan / requestServiceRebook); this page only displays
 * them. Every other role gets 403. This page never touches money.
 */
session_start();
require_once 'database.php';
require_once 'includes/TermsGate.php';
TermsGate::enforceGuest($pdo);
require_once 'includes/PaymentService.php';
require_once 'includes/RebookService.php';
require_once 'includes/emergency-ui.php';   // Phase 7.7 Emergency Rebook
if (file_exists('includes/SystemLogger.php')) require_once 'includes/SystemLogger.php';

if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit(); }

// Who may open this page: the guest who owns the booking, or admin/staff assisting a guest. Nobody else.
$role = (string)($_SESSION['role'] ?? '');
$isAssist = in_array($role, ['admin', 'staff'], true);   // admin and staff help guests rebook
if (!$isAssist && $role !== 'guest') {
    http_response_code(403);
    if (isset($_GET['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'You do not have permission to rebook tours.']); exit(); }
    exit('You do not have permission to rebook tours.');
}
$backUrl = $isAssist ? 'booking-management.php?tab=tour' : 'profile.php';

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

$isAjax = isset($_GET['ajax']);
$tour_pk = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($isAssist) {
    // Admin/staff: any tour booking. The guest id passed on to RebookService is the booking's own guest (owner check still runs).
    $st = $pdo->prepare("SELECT * FROM tour_bookings WHERE id = ?");
    $st->execute([$tour_pk]);
    $guest_id = 0;
} else {
    $g = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $g->execute([$_SESSION['user_id']]);
    $guest_id = (int)$g->fetchColumn();
    if (!$guest_id) {
        if ($isAjax) jsonOut(['error' => 'Guest profile not found.'], 403);
        header('Location: profile.php'); exit();
    }
    // The booking must be THEIRS (any other id looks like "not found")
    $st = $pdo->prepare("SELECT * FROM tour_bookings WHERE id = ? AND guest_id = ?");
    $st->execute([$tour_pk, $guest_id]);
}
$tour = $st->fetch(PDO::FETCH_ASSOC);
if (!$tour) {
    if ($isAjax) jsonOut(['error' => 'Booking not found.'], 404);
    header('Location: ' . ($isAssist ? 'booking-management.php?tab=tour' : 'profile.php?error=' . urlencode('Tour booking not found.'))); exit();
}
if ($isAssist) $guest_id = (int)$tour['guest_id'];
$guestLabel = '';
if ($isAssist) {
    try {
        $gn = $pdo->prepare("SELECT u.* FROM guests g JOIN users u ON u.id = g.user_id WHERE g.id = ?");
        $gn->execute([$guest_id]);
        if ($u = $gn->fetch(PDO::FETCH_ASSOC)) $guestLabel = (string)($u['fullname'] ?? $u['full_name'] ?? $u['name'] ?? $u['username'] ?? '');
    } catch (PDOException $e) {}
}

if (empty($_SESSION['tour_rebook_csrf'])) $_SESSION['tour_rebook_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['tour_rebook_csrf'];

$blockReason = RebookService::serviceBlockReason('tour', $tour);
// Phase 7.6: one request at a time — while one waits for Admin/Staff, the page shows it instead of the calendar.
$pending = RebookService::pendingRequest($pdo, 'tour', $tour_pk);
// ---- Phase 7.7 Emergency Rebook (guest only). Same page, same calendar, same validation: ONLY the 2-rebook limit and the 7-day window are lifted.
$emPending = EmergencyRebookService::pendingRequest($pdo, 'tour', $tour_pk);
$emergency = !$isAssist && ($emPending || EmergencyRebookUI::wanted($isAssist));
$emEl = EmergencyRebookService::eligibility('tour', $tour);
$emOffer = !$isAssist && !$pending && !$emPending && $emEl['eligible'] && EmergencyRebookService::tableReady($pdo);   // show the Emergency link on the normal screen
$emContact = '';
if ($emergency) {
    if (!EmergencyRebookService::tableReady($pdo)) $blockReason = 'Emergency Rebook is not available right now.';
    elseif ($pending) $blockReason = RebookService::PENDING_MESSAGE;                  // a normal rebook request is waiting: never both
    else $blockReason = ($emEl['eligible'] || $emPending) ? null : $emEl['message'];
    $pending = $emPending;                                                              // show the Emergency request while it waits
    $emContact = EmergencyRebookUI::contactFor($pdo, $guest_id);
}
$curSchedule = RebookService::currentSchedule('tour', $tour);
$timeChoices = RebookService::timeChoices('tour', $curSchedule['time']);
$gs = function ($k) { return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; };
$view = RebookService::tourView($pdo, $tour);
$startDate = $tour['booking_date'];

// ------------------------------------------------------------------ AJAX (read-only)
if ($isAjax) {
    if ($blockReason !== null || !$startDate) jsonOut(['error' => $blockReason ?: 'This tour has no date to move.'], 409);
    if ($pending) jsonOut(['error' => RebookService::PENDING_MESSAGE], 409);
    $mode = $gs('ajax');
    if ($mode === 'month') {
        $ym = $gs('ym');
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) jsonOut(['error' => 'Invalid month.'], 400);
        $days = [];
        $n = (int)date('t', strtotime($ym . '-01'));
        for ($d = 1; $d <= $n; $d++) {
            $ds = sprintf('%s-%02d', $ym, $d);
            $days[$ds] = RebookService::dayCheck($pdo, 'tour', $tour, 'tour', $ds);
        }
        jsonOut(['days' => $days]);
    }
    if ($mode === 'preview') {
        // The same validation the request (and later the confirmation) runs; display only.
        $r = RebookService::plan($pdo, 'tour', $tour, ['date' => $gs('date'), 'time' => $gs('time')]);
        jsonOut(['ok' => $r['ok'], 'message' => $r['message'],
                 'current' => RebookService::scheduleLines('tour', $curSchedule),
                 'requested' => $r['schedule'] ? RebookService::scheduleLines('tour', $r['schedule']) : []]);
    }
    jsonOut(['error' => 'Unknown request.'], 400);
}

// ------------------------------------------------------------------ SUBMIT (a REQUEST: the booking does not change until Admin/Staff confirm)
$error = '';
if (isset($_POST['rebook_tour'])) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Your session expired. Please refresh the page and try again.');
        // Only the new date and time are read from the browser; everything else comes from the saved booking.
        $res = RebookService::requestServiceRebook($pdo, 'tour', $tour_pk, $guest_id, RebookService::inputFromRequest('tour', $_POST), (int)$_SESSION['user_id'], $role);
        if (class_exists('SystemLogger')) {
            $old = RebookService::scheduleLines('tour', $res['original']); $new = RebookService::scheduleLines('tour', $res['requested']);
            SystemLogger::log($pdo, 'rebook_request', 'booking',
                ($isAssist ? (($role === 'admin') ? 'Admin' : 'Staff') . ' requested' : 'Guest requested') . " a rebook for tour '{$res['reference']}': " . $old[0]['text'] . ' -> ' . $new[0]['text'] . ' (waiting for confirmation; payment unchanged)',
                $tour_pk, 'tour_booking', ['schedule' => $res['original']], ['schedule' => $res['requested'], 'request_id' => $res['id'], 'by' => $isAssist ? $role : 'guest']);
        }
        header('Location: ' . ($isAssist ? 'booking-management.php?tab=rebook&rebook_requested=' . urlencode($res['reference']) : 'profile.php?rebook_requested=tour'));
        exit();
    } catch (PDOException $e) {
        error_log('tour rebook request failed: ' . $e->getMessage());
        $error = 'Something went wrong and nothing was saved. Please try again.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $pending = RebookService::pendingRequest($pdo, 'tour', $tour_pk);
}

if ($emergency && isset($_POST['emergency_rebook']) && !$isAssist) {
    $error = EmergencyRebookUI::handlePost($pdo, 'tour', $tour_pk, $guest_id, $csrf);          // redirects to the dashboard on success
    $emPending = EmergencyRebookService::pendingRequest($pdo, 'tour', $tour_pk);
    if ($emPending) $pending = $emPending;
}

$remaining = RebookService::remaining($tour);
$daysLeft = RebookService::daysLeft($tour);
$deadline = RebookService::deadline($tour);
$prev = RebookService::previousStartDates($pdo, $tour_pk, 'tour_booking');
$maxRebooks = RebookService::MAX_REBOOKS;

$content = [];
try {
    $q = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while ($row = $q->fetch()) $content[$row['section_name']][$row['content_key']] = $row['content_value'];
} catch (PDOException $e) {}
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';
$nav_logo = $content['site_settings']['logo_path'] ?? 'uploads/logos/logo.png';
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);

function pr_dt($d, $t = null) { return date('M j, Y', strtotime($d)) . ($t ? ' · ' . date('g:i A', strtotime($t)) : ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rebook Tour - <?php echo htmlspecialchars($site_name); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php echo EmergencyRebookUI::css(); ?>
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;color:#1e293b;line-height:1.5}
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
.pr-title small{display:block;color:#64748b;font-size:13px;font-weight:500}
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
.pr-prev{font-size:13px;color:#475569;margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0}
.pr-alert{border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:14px;display:flex;gap:10px}
.pr-alert.err{background:#fee2e2;color:#991b1b}
.pr-alert.info{background:#fef3c7;color:#92400e}
.cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.cal-head h3{font-size:16px;color:#0B2447}
.cal-nav button{width:40px;height:40px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:10px;cursor:pointer;font-size:22px;line-height:1;color:#0B2447}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
.cal-grid .dn{text-align:center;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;padding:4px 0}
.cal-grid .day{text-align:center;padding:10px 0;border-radius:8px;font-size:14px;cursor:pointer;border:1px solid transparent;background:#ecfdf5;color:#065f46;font-weight:600;min-height:40px}
.cal-grid .day:hover:not(.off):not(.sel){border-color:#10b981}
.cal-grid .day.off{background:#f1f5f9;color:#94a3b8;text-decoration:line-through;cursor:not-allowed;font-weight:500}
.cal-grid .day.available{background:#dcfce7;color:#166534;box-shadow:inset 0 0 0 1px #86efac}
.cal-grid .day.available:hover{background:#bbf7d0}
.cal-grid .day.cur{box-shadow:inset 0 0 0 2px #F4B400}
.cal-grid .day.sel{background:#4DA6D9;color:#fff;box-shadow:0 4px 12px rgba(77,166,217,.4)}
.cal-grid .day.blank{background:transparent;cursor:default}
.cal-grid .day.loading{opacity:.5}
.cal-legend{display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;font-size:12px;color:#64748b}
.cal-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-1px}
.cal-note{margin-top:10px;font-size:13px;color:#475569;min-height:20px}
.pr-preview{margin-top:14px;border:2px solid #4DA6D9;border-radius:12px;padding:12px 14px;background:#f0f9ff;display:none}
.pr-preview h3{font-size:14px;color:#0B2447;margin-bottom:6px}
.pr-preview .row{font-size:13px;padding:3px 0;overflow-wrap:anywhere}
.pr-preview .row i{color:#4DA6D9;width:20px}
.pr-btn{width:100%;margin-top:14px;min-height:48px;padding:12px;border:0;border-radius:12px;background:#F4B400;color:#0B2447;font-weight:700;font-size:15px;cursor:pointer}
.pr-btn:disabled{background:#e2e8f0;color:#94a3b8;cursor:not-allowed}
.pr-field{margin-top:12px}.pr-field label{display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:#0B2447}.pr-field select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;background:#fff;color:#1e293b}
.pr-hint{font-size:12px;color:#64748b;margin-top:8px}
.pr-pending{border:2px solid #f59e0b;background:#fffbeb;border-radius:12px;padding:12px 14px;font-size:14px;color:#92400e}
.pr-pending b{display:block;color:#92400e;margin-bottom:4px}.pr-pending .row{padding:3px 0;overflow-wrap:anywhere}
@media(max-width:820px){.pr-grid{grid-template-columns:1fr}}
@media(max-width:480px){.pr-card{padding:14px}.pr-title h1{font-size:19px}.pr-stat b{font-size:17px}.cal-grid{gap:3px}.cal-grid .day{padding:8px 0;font-size:13px}}
</style>
</head>
<body>
<div class="pr-header"><div class="pr-header-in">
    <a href="<?php echo $backUrl; ?>" class="pr-brand">
        <?php if ($nav_logo_exists): ?><img src="<?php echo htmlspecialchars($nav_logo); ?>" alt=""><?php endif; ?>
        <span><?php echo htmlspecialchars($site_name); ?></span>
    </a>
    <a href="<?php echo $backUrl; ?>" class="pr-back"><i class="fas fa-arrow-left"></i> <?php echo $isAssist ? 'Back to Booking Management' : 'Back to Dashboard'; ?></a>
</div></div>

<div class="pr-wrap">
    <div class="pr-title">
        <i class="fas fa-redo-alt"></i>
        <h1><?php echo $emergency ? 'Emergency Rebook — Tour' : 'Rebook Tour'; ?> <small>Ref: <?php echo htmlspecialchars($tour['reference_number']); ?><?php if ($isAssist && $guestLabel !== ''): ?> · Guest: <?php echo htmlspecialchars($guestLabel); ?><?php endif; ?></small></h1>
    </div>

    <?php if ($error !== ''): ?><div class="pr-alert err"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
    <?php if ($blockReason !== null): ?><div class="pr-alert info"><i class="fas fa-info-circle"></i><span><?php echo htmlspecialchars($blockReason); ?></span></div><?php endif; ?>
    <?php if ($emOffer && !$emergency): ?><div style="margin-bottom:14px;"><?php echo EmergencyRebookUI::link('tour-rebook.php', $tour_pk); ?></div><?php endif; ?>
    <?php if ($emergency && $blockReason === null): echo EmergencyRebookUI::banner('tour', $emPending ? $emPending['eligibility'] : $emEl['reason']); endif; ?>

    <div class="pr-grid">
        <div>
            <div class="pr-card" style="margin-bottom:16px;">
                <h2><i class="fas fa-box-open"></i> Current booking</h2>
                <div class="pr-item"><i class="fas fa-ship"></i><div><b><?php echo htmlspecialchars($view['item_name'] !== '' ? $view['item_name'] : 'Tour'); ?></b>
                    <span><?php echo htmlspecialchars(pr_dt($tour['booking_date'], $tour['preferred_time'] ?: null)); ?></span></div></div>
                <div class="pr-item"><i class="fas fa-users"></i><div><b>Guests</b>
                    <span><?php echo (int)$tour['number_of_guests']; ?> pax · stays the same</span></div></div>
                <div class="pr-item"><i class="fas fa-wallet"></i><div><b>Payment</b>
                    <span>₱<?php echo number_format((float)$tour['amount_paid'], 2); ?> paid of ₱<?php echo number_format((float)$tour['total_amount'], 2); ?> — stays on this booking</span></div></div>
                <?php if ($prev): ?>
                <div class="pr-prev"><i class="fas fa-history"></i> Previous date(s):
                    <?php echo htmlspecialchars(implode(', ', array_map(function ($p) { return date('M j, Y', strtotime($p['date'])); }, $prev))); ?></div>
                <?php endif; ?>
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
                    <li><i class="fas fa-check-circle"></i><span>Choose a new <strong>date and time</strong>, give your <strong>reason</strong> and a contact number.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Your payment is <strong>carried forward</strong>: no new reservation fee and price unchanged.</span></li>
                    <li><i class="fas fa-hourglass-half" style="color:#ea580c"></i><span><strong>This is a request.</strong> It still requires Admin/Staff approval and availability; it does not guarantee rescheduling. Until then you keep your current schedule, and you can cancel it from the dashboard.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Availability is checked now and again at approval; if the new schedule was taken meanwhile, nothing changes.</span></li>
                </ul>
                <?php else: ?>
                <ul class="pr-rules">
                    <li><i class="fas fa-check-circle"></i><span>Choose a new <strong>date and time</strong>. The boat, number of guests and price stay the same.</span></li>
                    <li><i class="fas fa-check-circle"></i><span><?php echo $isAssist ? 'The guest\'s' : 'Your'; ?> payment is <strong>carried forward</strong>: no new reservation fee, no refund, price unchanged.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Up to <?php echo $maxRebooks; ?> rebooks per booking, within <?php echo RebookService::WINDOW_DAYS; ?> days from the booking date.</span></li>
                    <li><i class="fas fa-hourglass-half" style="color:#f59e0b"></i><span><strong>This is a request.</strong> Admin/Staff confirm it in Booking Management. Until then <?php echo $isAssist ? 'the guest keeps the' : 'you keep your'; ?> current schedule, and it can be cancelled from the dashboard.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Availability is checked now and again when the request is confirmed; if the new schedule was taken meanwhile, nothing changes.</span></li>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="pr-card">
            <h2><i class="fas fa-calendar-alt"></i> Choose the new date and time</h2>
            <?php if ($pending && $emergency): ?>
            <?php echo EmergencyRebookUI::pendingBox('tour', $pending, 'profile.php'); ?>
            <?php elseif ($pending): $pLines = RebookService::scheduleLines('tour', $pending['requested']); ?>
            <div class="pr-pending"><b><i class="fas fa-hourglass-half"></i> Pending rebook — waiting for admin confirmation</b>
                <?php foreach ($pLines as $pl): ?><div class="row">Requested <?php echo htmlspecialchars($pl['label']); ?>: <strong><?php echo htmlspecialchars($pl['text']); ?></strong></div><?php endforeach; ?>
                <div class="row" style="font-size:13px;">Requested on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($pending['requested_at']))); ?>. <?php echo $isAssist ? 'Confirm or reject it in the Rebooks tab.' : 'You can cancel it from your dashboard.'; ?></div>
            </div>
            <p class="pr-hint"><a href="<?php echo $isAssist ? 'booking-management.php?tab=rebook' : 'profile.php'; ?>"><?php echo $isAssist ? 'Open the Rebooks tab' : 'Back to my bookings'; ?></a></p>
            <?php elseif ($blockReason === null && $startDate): ?>
            <div class="cal-head">
                <h3 id="calTitle"></h3>
                <div class="cal-nav"><button type="button" id="calPrev" aria-label="Previous month">&lsaquo;</button> <button type="button" id="calNext" aria-label="Next month">&rsaquo;</button></div>
            </div>
            <div class="cal-grid" id="calGrid"></div>
            <div class="cal-legend"><span><i style="background:#ecfdf5;border:1px solid #10b981"></i>Available</span><span><i style="background:#f1f5f9;border:1px solid #cbd5e1"></i>Unavailable</span><span><i style="background:#4DA6D9"></i>Selected</span><span><i style="box-shadow:inset 0 0 0 2px #F4B400"></i>Current tour date</span></div>
            <div class="cal-note" id="calNote">Pick a date, then a time. Unavailable dates are greyed out — tap one to see why.</div>
            <div class="pr-preview" id="preview"></div>
            <form method="POST" id="rebookForm">
                <input type="hidden" name="<?php echo $emergency ? 'emergency_rebook' : 'rebook_tour'; ?>" value="1">
                <input type="hidden" name="id" value="<?php echo (int)$tour_pk; ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="new_date" id="newDate" value="">
                <div class="pr-field">
                    <label for="newTime"><i class="fas fa-clock"></i> Tour time</label>
                    <select name="new_time" id="newTime">
                        <?php foreach ($timeChoices as $tc): ?>
                        <option value="<?php echo substr($tc, 0, 5); ?>" <?php echo $tc === $curSchedule['time'] ? 'selected' : ''; ?>><?php echo date('g:i A', strtotime($tc)); ?><?php echo $tc === $curSchedule['time'] ? ' (current)' : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($emergency) echo EmergencyRebookUI::fields($emContact); ?>
                <button type="submit" class="pr-btn<?php echo $emergency ? ' em-btn' : ''; ?>" id="submitBtn" disabled><i class="fas fa-paper-plane"></i> <?php echo $emergency ? 'Submit Emergency Request' : 'Submit Rebook Request'; ?></button>
                <p class="pr-hint"><?php echo $emergency ? 'This is an Emergency Rebook request and still requires Admin/Staff approval. ' : 'The request waits for Admin/Staff confirmation. '; ?> <?php echo $isAssist ? 'The guest\'s' : 'Your'; ?> payment stays as it is.</p>
            </form>
            <?php else: ?>
            <p style="font-size:14px;color:#64748b;">This tour cannot be rebooked right now.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($blockReason === null && $startDate && !$pending): ?>
<?php $rbcEmergency = $emergency; require __DIR__ . '/includes/rebook-confirm.php'; ?>
<?php if ($emergency) echo EmergencyRebookUI::js(); ?>
<script>
(function () {
    var PAGE = 'tour-rebook.php', BID = <?php echo (int)$tour_pk; ?>, EMQ = <?php echo $emergency ? "'&emergency=1'" : "''"; ?>;
    var CURRENT = <?php echo json_encode($startDate); ?>;
    var TODAY = <?php echo json_encode(date('Y-m-d')); ?>;
    var MAXD = <?php echo json_encode(date('Y-m-d', strtotime('+365 days'))); ?>;
    var grid = document.getElementById('calGrid'), title = document.getElementById('calTitle');
    var note = document.getElementById('calNote'), prev = document.getElementById('preview');
    var btn = document.getElementById('submitBtn'), hidden = document.getElementById('newDate'), timeSel = document.getElementById('newTime');
    var start = CURRENT && CURRENT >= TODAY ? CURRENT : TODAY;
    var parts = start.split('-'), y = +parts[0], m = +parts[1] - 1, selected = null, token = 0, ptoken = 0, last = null;
    var cache = {}, MN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function pad(n) { return String(n).padStart(2, '0'); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
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
            else if (info) cls += ' available';
            if (ds === CURRENT) cls += ' cur';
            if (ds === selected) cls += ' sel';
            h += '<div class="' + cls + '" data-d="' + ds + '" role="button" aria-disabled="' + (off ? 'true' : 'false') + '">' + d + '</div>';
        }
        grid.innerHTML = h;
    }
    function load() {
        var ym = y + '-' + pad(m + 1), my = ++token;
        if (cache[ym]) { draw(cache[ym]); return; }
        draw(null);
        fetch(PAGE + '?id=' + BID + EMQ + '&ajax=month&ym=' + ym, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.days) { cache[ym] = j.days; if (my === token) draw(j.days); } else { note.textContent = j.error || 'Could not load availability.'; } })
            .catch(function () { note.textContent = 'Could not load availability. Please refresh.'; });
    }
    function lines(list) { return (list || []).map(function (l) { return '<div class="row"><i class="fas fa-calendar-check"></i> ' + esc(l.label) + ': <strong>' + esc(l.text) + '</strong></div>'; }).join(''); }
    // Live check: the SAME server validation the request runs (display only — the request and the confirmation re-check).
    function preview() {
        var ds = selected, tm = timeSel.value, my = ++ptoken;
        last = null; btn.disabled = true; prev.style.display = 'none';
        if (!ds) return;
        note.textContent = 'Checking ' + fd(ds) + (tm ? ' at ' + ft(tm) : '') + '…';
        fetch(PAGE + '?id=' + BID + EMQ + '&ajax=preview&date=' + encodeURIComponent(ds) + '&time=' + encodeURIComponent(tm), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (my !== ptoken) return;
                if (j.ok) {
                    last = j; note.textContent = 'Available — the boat is free then.';
                    prev.innerHTML = '<h3><i class="fas fa-calendar-check"></i> Requested schedule</h3>' + lines(j.requested)
                        + '<div class="row"><i class="fas fa-hourglass-half"></i> Waits for Admin/Staff confirmation. Payment unchanged.</div>';
                    prev.style.display = 'block'; btn.disabled = false;
                } else { note.textContent = j.message || j.error || 'Not available.'; }
            })
            .catch(function () { note.textContent = 'Could not check that schedule. Please try again.'; });
    }
    grid.addEventListener('click', function (e) {
        var el = e.target.closest('.day[data-d]'); if (!el) return;
        var ds = el.getAttribute('data-d'), ym = y + '-' + pad(m + 1), info = cache[ym] && cache[ym][ds];
        if (el.classList.contains('off')) {
            note.textContent = ds < TODAY ? 'That date has passed.' : (ds > MAXD ? 'Please choose a date within the next 12 months.' : ((info && info.message) || 'Not available.'));
            return;
        }
        selected = ds; hidden.value = ds;
        draw(cache[ym]);
        preview();
    });
    timeSel.addEventListener('change', preview);
    document.getElementById('calPrev').addEventListener('click', function () { m--; if (m < 0) { m = 11; y--; } load(); });
    document.getElementById('calNext').addEventListener('click', function () { m++; if (m > 11) { m = 0; y++; } load(); });
    document.getElementById('rebookForm').addEventListener('submit', function (e) {
        if (!hidden.value || !last || btn.disabled) { e.preventDefault(); return; }
        var rows = (last.requested || []).map(function (r, i) { return { label: r.label, from: (last.current[i] || {}).text || '', to: r.text }; });
        var em = null;
        if (window.EmergencyForm) { em = EmergencyForm.check(); if (!em.ok) { e.preventDefault(); return; } }
        if (!RebookConfirm.gate(e, rows, em ? em.reason : '')) return;          // first click opens the confirmation; the confirmed submit continues
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending request…';
    });
    load();
})();
</script>
<?php endif; ?>
</body>
</html>

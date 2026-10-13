<?php
/**
 * Package Rebook Dashboard (guest, or ADMIN / STAFF from Booking Management).
 * Every other role is refused (403). Admin and staff use exactly the same rules, checks and
 * RebookService calls as the guest; only WHO may open it differs. They get no payment, refund,
 * cancellation or user-management ability from this page (it never touches money).
 *
 * Phase 7.6 — independent schedules + approval:
 *  - every part of the package gets its OWN new schedule (no common day offset any more):
 *      HOUSE  same unit, same number of nights: new check-in date + check-in time + guests (1..capacity);
 *             check-out date = check-in + nights, check-out time follows the check-in time (current rule);
 *      TOUR   same boat: new date + time;      FOOD   same order: new date + time (delivery stays at the package unit);
 *  - with a house in the package, the tour and food must be inside the NEW house stay;
 *  - the guest submits ONE request for the whole package; nothing changes until Admin/Staff confirm it
 *    (all parts together in one transaction) in Booking Management. Reject / Cancel leave it untouched.
 * All rules live in RebookService (plan / dayCheck / requestServiceRebook). The paid amount, the single ₱1,000
 * reservation fee and the payment records are never touched.
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

// Who may open this page: the guest who owns the package, or admin/staff assisting a guest. Nobody else.
$role = (string)($_SESSION['role'] ?? '');
$isAssist = in_array($role, ['admin', 'staff'], true);   // admin and staff help guests rebook
if (!$isAssist && $role !== 'guest') {
    http_response_code(403);
    if (isset($_GET['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'You do not have permission to rebook packages.']); exit(); }
    exit('You do not have permission to rebook packages.');
}
$backUrl = $isAssist ? 'booking-management.php?tab=package' : 'profile.php';

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

$isAjax = isset($_GET['ajax']);
$pkg_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($isAssist) {
    // Admin/staff: any package. The guest id passed on to RebookService is the package's own guest (owner check still runs).
    $st = $pdo->prepare("SELECT * FROM package_bookings WHERE id = ?");
    $st->execute([$pkg_id]);
    $guest_id = 0;
} else {
    $g = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $g->execute([$_SESSION['user_id']]);
    $guest_id = (int)$g->fetchColumn();
    if (!$guest_id) {
        if ($isAjax) jsonOut(['error' => 'Guest profile not found.'], 403);
        header('Location: profile.php'); exit();
    }
    // The package must be THEIRS (any other id looks like "not found")
    $st = $pdo->prepare("SELECT * FROM package_bookings WHERE id = ? AND guest_id = ?");
    $st->execute([$pkg_id, $guest_id]);
}
$pkg = $st->fetch(PDO::FETCH_ASSOC);
if (!$pkg) {
    if ($isAjax) jsonOut(['error' => 'Booking not found.'], 404);
    header('Location: ' . ($isAssist ? 'booking-management.php?tab=package' : 'profile.php?error=' . urlencode('Package booking not found.'))); exit();
}
if ($isAssist) $guest_id = (int)$pkg['guest_id'];
$guestLabel = '';
if ($isAssist) {
    try {
        $gn = $pdo->prepare("SELECT u.* FROM guests g JOIN users u ON u.id = g.user_id WHERE g.id = ?");
        $gn->execute([$guest_id]);
        if ($u = $gn->fetch(PDO::FETCH_ASSOC)) $guestLabel = (string)($u['fullname'] ?? $u['full_name'] ?? $u['name'] ?? $u['username'] ?? '');
    } catch (PDOException $e) {}
}

if (empty($_SESSION['pkg_rebook_csrf'])) $_SESSION['pkg_rebook_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['pkg_rebook_csrf'];

$blockReason = RebookService::serviceBlockReason('package', $pkg);
$parts = RebookService::packageView($pdo, $pkg);
$startDate = RebookService::packageStartDate($parts);
$pending = RebookService::pendingRequest($pdo, 'package', $pkg_id);
// ---- Phase 7.7 Emergency Rebook (guest only). Same page, same calendars, same validation: ONLY the 2-rebook limit and the 7-day window are lifted.
$emPending = EmergencyRebookService::pendingRequest($pdo, 'package', $pkg_id);
$emergency = !$isAssist && ($emPending || EmergencyRebookUI::wanted($isAssist));
$emEl = EmergencyRebookService::eligibility('package', $pkg);
$emOffer = !$isAssist && !$pending && !$emPending && $emEl['eligible'] && EmergencyRebookService::tableReady($pdo);   // show the Emergency link on the normal screen
$emContact = '';
if ($emergency) {
    if (!EmergencyRebookService::tableReady($pdo)) $blockReason = 'Emergency Rebook is not available right now.';
    elseif ($pending) $blockReason = RebookService::PENDING_MESSAGE;                  // a normal rebook request is waiting: never both
    else $blockReason = ($emEl['eligible'] || $emPending) ? null : $emEl['message'];
    $pending = $emPending;                                                              // show the Emergency request while it waits
    $emContact = EmergencyRebookUI::contactFor($pdo, $guest_id);
}
$cur = RebookService::currentSchedule('package', $pkg, $parts);
$capacity = $parts['house'] ? (int)RebookService::houseView($pdo, $parts['house'])['capacity'] : 0;
$tourTimes = $cur['tour'] ? RebookService::timeChoices('tour', $cur['tour']['time']) : [];
$foodTimes = $cur['food'] ? RebookService::timeChoices('food', $cur['food']['time']) : [];
$gs = function ($k) { return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; };

// ------------------------------------------------------------------ AJAX (read-only; the request and Confirm re-check)
if ($isAjax) {
    if ($blockReason !== null || !$startDate) jsonOut(['error' => $blockReason ?: 'This package has no dated items to move.'], 409);
    if ($pending) jsonOut(['error' => RebookService::PENDING_MESSAGE], 409);
    $mode = $gs('ajax');
    if ($mode === 'month') {
        $part = $gs('part');
        if (!in_array($part, ['house', 'tour', 'food'], true) || empty($cur[$part])) jsonOut(['error' => 'Unknown item.'], 400);
        $ym = $gs('ym');
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) jsonOut(['error' => 'Invalid month.'], 400);
        $ctx = ['h_date' => $gs('h_date'), 'h_time' => $gs('h_time')];
        $days = [];
        $n = (int)date('t', strtotime($ym . '-01'));
        for ($d = 1; $d <= $n; $d++) {
            $ds = sprintf('%s-%02d', $ym, $d);
            $days[$ds] = RebookService::dayCheck($pdo, 'package', $pkg, $part, $ds, $ctx, $parts);
        }
        jsonOut(['days' => $days]);
    }
    if ($mode === 'check') {
        // The same validation the request (and later the confirmation) runs on the whole package; display only.
        $r = RebookService::plan($pdo, 'package', $pkg, RebookService::inputFromRequest('package', $_GET), $parts);
        jsonOut(['ok' => $r['ok'], 'message' => $r['message'], 'no_change' => $r['message'] === RebookService::NO_CHANGE_MESSAGE,
                 'current' => RebookService::scheduleLines('package', $cur),
                 'requested' => $r['schedule'] ? RebookService::scheduleLines('package', $r['schedule']) : []]);
    }
    jsonOut(['error' => 'Unknown request.'], 400);
}

// ------------------------------------------------------------------ SUBMIT (ONE request for the whole package; nothing changes until confirmed)
$error = '';
if (isset($_POST['rebook_package'])) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Your session expired. Please refresh the page and try again.');
        // Only dates, times and the house guest count are read from the browser; everything else comes from the saved package.
        $res = RebookService::requestServiceRebook($pdo, 'package', $pkg_id, $guest_id, RebookService::inputFromRequest('package', $_POST), (int)$_SESSION['user_id'], $role);
        if (class_exists('SystemLogger')) {
            $txt = function ($s) { return implode('; ', array_map(function ($l) { return $l['label'] . ' ' . $l['text']; }, RebookService::scheduleLines('package', $s))); };
            SystemLogger::log($pdo, 'rebook_request', 'booking',
                ($isAssist ? (($role === 'admin') ? 'Admin' : 'Staff') . ' requested' : 'Guest requested') . " a rebook for package '{$res['reference']}': " . $txt($res['original']) . ' -> ' . $txt($res['requested']) . ' (waiting for confirmation; payment unchanged)',
                $pkg_id, 'package_booking', ['schedule' => $res['original']], ['schedule' => $res['requested'], 'request_id' => $res['id'], 'by' => $isAssist ? $role : 'guest']);
        }
        header('Location: ' . ($isAssist ? 'booking-management.php?tab=rebook&rebook_requested=' . urlencode($res['reference']) : 'profile.php?rebook_requested=package'));
        exit();
    } catch (PDOException $e) {
        error_log('package rebook request failed: ' . $e->getMessage());
        $error = 'Something went wrong and nothing was saved. Please try again.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $pending = RebookService::pendingRequest($pdo, 'package', $pkg_id);
}
if ($emergency && isset($_POST['emergency_rebook']) && !$isAssist) {
    $error = EmergencyRebookUI::handlePost($pdo, 'package', $pkg_id, $guest_id, $csrf);      // redirects to the dashboard on success
    $emPending = EmergencyRebookService::pendingRequest($pdo, 'package', $pkg_id);
    if ($emPending) $pending = $emPending;
}
$canRebook = ($blockReason === null && $startDate && !$pending);

$remaining = RebookService::remaining($pkg);
$daysLeft = RebookService::daysLeft($pkg);
$deadline = RebookService::deadline($pkg);
$prev = RebookService::previousStartDates($pdo, $pkg_id);
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
$h = $parts['house']; $t = $parts['tour']; $f = $parts['food'];
$hm = function ($time) { return substr((string)$time, 0, 5); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rebook Package - <?php echo htmlspecialchars($site_name); ?></title>
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
.pr-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.25fr);gap:16px;align-items:start}
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
.pk-sec{border:1px solid #e2e8f0;border-radius:14px;padding:14px;margin-bottom:14px;background:#fcfdff;min-width:0}
.pk-sec h3{font-size:15px;color:#0B2447;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pk-sec h3 i{color:#4DA6D9}
.pk-sec h3 small{font-weight:500;color:#64748b;font-size:12px}
.pk-cur{font-size:12.5px;color:#64748b;margin:4px 0 10px;overflow-wrap:anywhere}
.cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:8px}
.cal-title{font-size:15px;color:#0B2447;font-weight:700}
.cal-nav{display:flex;gap:6px}
.cal-nav button{width:40px;height:40px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:10px;cursor:pointer;font-size:22px;line-height:1;color:#0B2447}
.cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
.cal-grid .dn{text-align:center;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;padding:4px 0}
.cal-grid .day{text-align:center;padding:9px 0;border-radius:8px;font-size:14px;cursor:pointer;border:1px solid transparent;background:#ecfdf5;color:#065f46;font-weight:600;min-height:38px}
.cal-grid .day:hover:not(.off):not(.sel){border-color:#10b981}
.cal-grid .day.off{background:#f1f5f9;color:#94a3b8;text-decoration:line-through;cursor:not-allowed;font-weight:500}
.cal-grid .day.nostart{background:#fff;color:#92400e;border:1px dashed #f59e0b;cursor:not-allowed;font-weight:600;text-decoration:none}
.cal-grid .day.today{position:relative}
.cal-grid .day.today::after{content:'';position:absolute;left:50%;bottom:3px;width:5px;height:5px;margin-left:-2.5px;border-radius:50%;background:#0B3D91}
.cal-grid .day.sel.today::after{background:#fff}
.cal-grid .day.cur{box-shadow:inset 0 0 0 2px #F4B400}
.cal-grid .day.sel{background:#4DA6D9;color:#fff;box-shadow:0 4px 12px rgba(77,166,217,.4);text-decoration:none}
.cal-grid .day.rng{background:#dbeafe;color:#1e3a8a;text-decoration:none}
.cal-grid .day.outd{background:#bfdbfe;color:#1e3a8a;text-decoration:none}
.cal-grid .day.blank{background:transparent;cursor:default}
.cal-grid .day.loading{opacity:.5}
.cal-legend{display:flex;flex-wrap:wrap;gap:10px 14px;margin-top:10px;font-size:12px;color:#64748b}
.cal-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-1px}
.pk-msg{margin-top:8px;font-size:12.5px;color:#b45309;min-height:0}
.pk-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px}
.pr-field{margin-top:12px;min-width:0}
.pr-field label{display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:#0B2447}
.pr-field select,.pr-field input{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;background:#fff;color:#1e293b;min-height:44px}
.pk-auto{margin-top:10px;font-size:12.5px;color:#1e40af;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:8px 10px;overflow-wrap:anywhere}
.cal-note{margin-top:4px;font-size:13px;color:#475569;min-height:20px}
.pr-preview{margin-top:10px;border:2px solid #4DA6D9;border-radius:12px;padding:12px 14px;background:#f0f9ff;display:none}
.pr-preview h3{font-size:14px;color:#0B2447;margin-bottom:6px}
.pr-preview .row{font-size:13px;padding:3px 0;overflow-wrap:anywhere}
.pr-preview .row i{color:#4DA6D9;width:20px}
.pr-btn{width:100%;margin-top:14px;min-height:48px;padding:12px;border:0;border-radius:12px;background:#F4B400;color:#0B2447;font-weight:700;font-size:15px;cursor:pointer}
.pr-btn:disabled{background:#e2e8f0;color:#94a3b8;cursor:not-allowed}
.pr-hint{font-size:12px;color:#64748b;margin-top:8px}
.pr-pending{border:2px solid #f59e0b;background:#fffbeb;border-radius:12px;padding:12px 14px;font-size:14px;color:#92400e}
.pr-pending b{display:block;margin-bottom:4px}.pr-pending .row{padding:3px 0;overflow-wrap:anywhere}
@media(max-width:860px){.pr-grid{grid-template-columns:minmax(0,1fr)}}
@media(max-width:480px){.pr-card{padding:14px}.pk-sec{padding:12px}.pr-title h1{font-size:19px}.pr-stat b{font-size:17px}.cal-grid{gap:3px}.cal-grid .day{padding:8px 0;font-size:13px}.pk-row{grid-template-columns:minmax(0,1fr)}}
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
        <h1><?php echo $emergency ? 'Emergency Rebook — Package' : 'Rebook Package'; ?> <small>Ref: <?php echo htmlspecialchars($pkg['reference_number']); ?><?php if ($isAssist && $guestLabel !== ''): ?> · Guest: <?php echo htmlspecialchars($guestLabel); ?><?php endif; ?></small></h1>
    </div>

    <?php if ($error !== ''): ?><div class="pr-alert err"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
    <?php if ($blockReason !== null): ?><div class="pr-alert info"><i class="fas fa-info-circle"></i><span><?php echo htmlspecialchars($blockReason); ?></span></div><?php endif; ?>
    <?php if ($emOffer && !$emergency): ?><div style="margin-bottom:14px;"><?php echo EmergencyRebookUI::link('package-rebook.php', $pkg_id); ?></div><?php endif; ?>
    <?php if ($emergency && $blockReason === null): echo EmergencyRebookUI::banner('package', $emPending ? $emPending['eligibility'] : $emEl['reason']); endif; ?>

    <div class="pr-grid">
        <div>
            <div class="pr-card" style="margin-bottom:16px;">
                <h2><i class="fas fa-box-open"></i> Current package</h2>
                <?php if ($h): ?>
                <div class="pr-item"><i class="fas fa-home"></i><div><b><?php echo htmlspecialchars($h['item_name'] ?: 'House'); ?></b>
                    <span><?php echo htmlspecialchars(pr_dt($h['check_in_date'], $cur['house']['in_time'])); ?><br>to <?php echo htmlspecialchars(pr_dt($h['check_out_date'], $cur['house']['out_time'])); ?> · <?php echo (int)$h['number_of_guests']; ?> guest<?php echo (int)$h['number_of_guests'] === 1 ? '' : 's'; ?></span></div></div>
                <?php endif; ?>
                <?php if ($t): ?>
                <div class="pr-item"><i class="fas fa-ship"></i><div><b><?php echo htmlspecialchars($t['item_name'] ?: 'Tour'); ?></b>
                    <span><?php echo htmlspecialchars(pr_dt($t['booking_date'], $t['preferred_time'] ?: null)); ?></span></div></div>
                <?php endif; ?>
                <?php if ($f): ?>
                <div class="pr-item"><i class="fas fa-utensils"></i><div><b><?php echo htmlspecialchars($f['item_name'] ?: 'Food'); ?><?php if ((int)($f['quantity'] ?? 1) > 1): ?> × <?php echo (int)$f['quantity']; ?><?php endif; ?></b>
                    <span><?php echo $f['preferred_date'] ? htmlspecialchars(pr_dt($f['preferred_date'], $f['preferred_time'] ?: null)) : 'No date'; ?> · <?php echo htmlspecialchars(ucfirst((string)$f['fulfillment_method'])); ?></span></div></div>
                <?php endif; ?>
                <div class="pr-item"><i class="fas fa-wallet"></i><div><b>Payment</b>
                    <span>₱<?php echo number_format((float)$pkg['amount_paid'], 2); ?> paid of ₱<?php echo number_format((float)$pkg['grand_total'], 2); ?> — stays on this booking</span></div></div>
                <?php if ($prev): ?>
                <div class="pr-prev"><i class="fas fa-history"></i> Previous first day(s):
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
                    <li><i class="fas fa-exclamation-triangle" style="color:#ea580c"></i><span><strong>Emergency Rebook</strong> is only for packages whose normal rebook is closed (limit reached or 7-day window passed).</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Each part gets <strong>its own new schedule</strong>, and it is <strong>one request for the whole package</strong>: all parts are approved together or none.</span></li>
                    <?php if ($h && ($t || $f)): ?><li><i class="fas fa-check-circle"></i><span>The <?php echo $t && $f ? 'tour and food' : ($t ? 'tour' : 'food'); ?> must still be <strong>during the new house stay</strong>.</span></li><?php endif; ?>
                    <li><i class="fas fa-check-circle"></i><span>Your payment is <strong>carried forward</strong>: one payment, no new reservation fee, price unchanged.</span></li>
                    <li><i class="fas fa-hourglass-half" style="color:#ea580c"></i><span><strong>This is a request.</strong> It still requires Admin/Staff approval and availability; it does not guarantee rescheduling. Until then you keep your current schedule, and you can cancel it from the dashboard.</span></li>
                </ul>
                <?php else: ?>
                <ul class="pr-rules">
                    <li><i class="fas fa-check-circle"></i><span>Each part gets <strong>its own new schedule</strong>. You can change one part and keep the others.</span></li>
                    <?php if ($h): ?><li><i class="fas fa-check-circle"></i><span><strong>House:</strong> same unit and the same <?php echo (int)$cur['house']['nights']; ?> night<?php echo (int)$cur['house']['nights'] === 1 ? '' : 's'; ?>. Check-out is automatic and its time follows the check-in time. Up to <?php echo (int)$capacity; ?> guests.</span></li><?php endif; ?>
                    <?php if ($h && ($t || $f)): ?><li><i class="fas fa-check-circle"></i><span>The <?php echo $t && $f ? 'tour and food' : ($t ? 'tour' : 'food'); ?> must be <strong>during the new house stay</strong>.</span></li><?php endif; ?>
                    <li><i class="fas fa-check-circle"></i><span><?php echo $isAssist ? 'The guest\'s' : 'Your'; ?> payment is <strong>carried forward</strong>: no new reservation fee, no refund, price unchanged.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Up to <?php echo $maxRebooks; ?> rebooks per booking, within <?php echo RebookService::WINDOW_DAYS; ?> days from the booking date.</span></li>
                    <li><i class="fas fa-hourglass-half" style="color:#f59e0b"></i><span><strong>This is a request.</strong> Admin/Staff confirm the whole package at once; if any part was taken meanwhile, nothing changes.</span></li>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="pr-card">
            <h2><i class="fas fa-calendar-alt"></i> Choose the new schedule</h2>
            <?php if ($pending && $emergency): ?>
            <?php echo EmergencyRebookUI::pendingBox('package', $pending, 'profile.php'); ?>
            <?php elseif ($pending): ?>
            <div class="pr-pending"><b><i class="fas fa-hourglass-half"></i> Pending rebook — waiting for admin confirmation</b>
                <?php foreach (RebookService::scheduleLines('package', $pending['requested']) as $pl): ?><div class="row">Requested <?php echo htmlspecialchars($pl['label']); ?>: <strong><?php echo htmlspecialchars($pl['text']); ?></strong></div><?php endforeach; ?>
                <div class="row" style="font-size:13px;">Requested on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($pending['requested_at']))); ?>. <?php echo $isAssist ? 'Confirm or reject it in the Rebooks tab.' : 'You can cancel it from your dashboard.'; ?></div>
            </div>
            <p class="pr-hint"><a href="<?php echo $isAssist ? 'booking-management.php?tab=rebook' : 'profile.php'; ?>"><?php echo $isAssist ? 'Open the Rebooks tab' : 'Back to my bookings'; ?></a></p>
            <?php elseif ($canRebook): ?>
            <form method="POST" id="rebookForm">
                <input type="hidden" name="<?php echo $emergency ? 'emergency_rebook' : 'rebook_package'; ?>" value="1">
                <input type="hidden" name="id" value="<?php echo (int)$pkg_id; ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <?php foreach (['house' => ['fa-home', 'House', 'Pick the new check-in date'], 'tour' => ['fa-ship', 'Tour / Boat', 'Pick the new tour date'], 'food' => ['fa-utensils', 'Food', 'Pick the new food date']] as $pk => $pm): if (!$cur[$pk]) continue;
                    $nm = $pk === 'house' ? $h['item_name'] : ($pk === 'tour' ? $t['item_name'] : $f['item_name']); ?>
                <section class="pk-sec" data-part="<?php echo $pk; ?>">
                    <h3><i class="fas <?php echo $pm[0]; ?>"></i> <?php echo $pm[1]; ?> <small><?php echo htmlspecialchars((string)$nm); ?></small></h3>
                    <div class="pk-cur">Current: <?php echo htmlspecialchars(RebookService::scheduleLines('package', [$pk => $cur[$pk]] + ['house' => null, 'tour' => null, 'food' => null])[0]['text'] ?? ''); ?></div>
                    <div class="cal-head"><span class="cal-title"></span>
                        <div class="cal-nav"><button type="button" class="cal-prev" aria-label="Previous month">&lsaquo;</button><button type="button" class="cal-next" aria-label="Next month">&rsaquo;</button></div></div>
                    <div class="cal-grid" role="grid" aria-label="<?php echo $pm[2]; ?>"></div>
                    <div class="pk-msg" aria-live="polite"></div>
                    <?php if ($pk === 'house'): ?>
                    <input type="hidden" name="h_date" id="h_date" value="<?php echo htmlspecialchars($cur['house']['in']); ?>">
                    <div class="pk-row">
                        <div class="pr-field"><label for="h_time"><i class="fas fa-clock"></i> Check-in time</label>
                            <input type="time" name="h_time" id="h_time" value="<?php echo htmlspecialchars($hm($cur['house']['in_time'])); ?>" required></div>
                        <div class="pr-field"><label for="h_guests"><i class="fas fa-users"></i> Guests (max <?php echo (int)$capacity; ?>)</label>
                            <select name="h_guests" id="h_guests">
                                <?php $cg = min(max(1, (int)$cur['house']['guests']), max(1, $capacity ?: (int)$cur['house']['guests'])); for ($i = 1; $i <= max(1, $capacity ?: (int)$cur['house']['guests']); $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i === $cg ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select></div>
                    </div>
                    <div class="pk-auto" id="h_auto"></div>
                    <?php else: $ch = $pk === 'tour' ? $tourTimes : $foodTimes; $pre = $pk[0]; ?>
                    <input type="hidden" name="<?php echo $pre; ?>_date" id="<?php echo $pre; ?>_date" value="<?php echo htmlspecialchars($cur[$pk]['date']); ?>">
                    <div class="pr-field"><label for="<?php echo $pre; ?>_time"><i class="fas fa-clock"></i> <?php echo $pk === 'tour' ? 'Tour' : 'Food'; ?> time</label>
                        <select name="<?php echo $pre; ?>_time" id="<?php echo $pre; ?>_time">
                            <?php foreach ($ch as $tc): ?>
                            <option value="<?php echo $hm($tc); ?>" <?php echo $tc === $cur[$pk]['time'] ? 'selected' : ''; ?>><?php echo date('g:i A', strtotime($tc)); ?><?php echo $tc === $cur[$pk]['time'] ? ' (current)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <?php if ($pk === 'food' && $cur['food']['method'] === 'delivery'): ?><p class="pr-hint"><i class="fas fa-truck"></i> Delivered to your booked unit<?php echo $cur['food']['address'] !== '' ? ' (' . htmlspecialchars($cur['food']['address']) . ')' : ''; ?>, during the house stay.</p><?php endif; ?>
                    <?php endif; ?>
                </section>
                <?php endforeach; ?>
                <div class="cal-legend"><span><i style="background:#ecfdf5;border:1px solid #10b981"></i>Available</span><span><i style="background:#f1f5f9;border:1px solid #cbd5e1"></i>Booked / blocked / unavailable</span><span><i style="background:#4DA6D9"></i>Selected</span><?php if ($h): ?><span><i style="background:#dbeafe"></i>House stay</span><span><i style="background:#fff;border:1px dashed #f59e0b"></i>Free night, but the house stay can't start here</span><?php endif; ?><span><i style="box-shadow:inset 0 0 0 2px #F4B400"></i>Current</span><span><i style="background:#fff;border:1px solid #cbd5e1;position:relative"><b style="position:absolute;left:3px;top:3px;width:5px;height:5px;border-radius:50%;background:#0B3D91"></b></i>Today</span></div>
                <div class="cal-note" id="calNote"></div>
                <div class="pr-preview" id="preview"></div>
                <?php if ($emergency) echo EmergencyRebookUI::fields($emContact); ?>
                <button type="submit" class="pr-btn<?php echo $emergency ? ' em-btn' : ''; ?>" id="submitBtn" disabled><i class="fas fa-paper-plane"></i> <?php echo $emergency ? 'Submit Emergency Request' : 'Submit Rebook Request'; ?></button>
                <p class="pr-hint"><?php echo $emergency ? 'This is an Emergency Rebook request and still requires Admin/Staff approval. ' : ''; ?>One request for the whole package. It waits for Admin/Staff confirmation; <?php echo $isAssist ? 'the guest\'s' : 'your'; ?> payment stays as it is.</p>
            </form>
            <?php else: ?>
            <p style="font-size:14px;color:#64748b;">This package cannot be rebooked right now.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canRebook): ?>
<?php $rbcEmergency = $emergency; require __DIR__ . '/includes/rebook-confirm.php'; ?>
<?php if ($emergency) echo EmergencyRebookUI::js(); ?>
<script>
(function () {
    var PKG_ID = <?php echo (int)$pkg_id; ?>, EMQ = <?php echo $emergency ? "'&emergency=1'" : "''"; ?>;
    var TODAY = <?php echo json_encode(date('Y-m-d')); ?>;
    var MAXD = <?php echo json_encode(date('Y-m-d', strtotime('+365 days'))); ?>;
    var NIGHTS = <?php echo $cur['house'] ? (int)$cur['house']['nights'] : 0; ?>;
    var CUR = <?php echo json_encode(['house' => $cur['house'] ? $cur['house']['in'] : null, 'tour' => $cur['tour'] ? $cur['tour']['date'] : null, 'food' => $cur['food'] ? $cur['food']['date'] : null]); ?>;
    var MN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var $ = function (id) { return document.getElementById(id); };
    var hDate = $('h_date'), hTime = $('h_time'), hGuests = $('h_guests'), tDate = $('t_date'), tTime = $('t_time'), fDate = $('f_date'), fTime = $('f_time');
    var note = $('calNote'), prev = $('preview'), btn = $('submitBtn'), last = null, ptoken = 0;
    function pad(n) { return String(n).padStart(2, '0'); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function fd(d) { var p = d.split('-'); return MN[+p[1] - 1].slice(0, 3) + ' ' + (+p[2]) + ', ' + p[0]; }
    function ft(t) { if (!t) return ''; var p = t.split(':'), h = +p[0], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + p[1] + ' ' + ap; }
    function addDays(ds, n) { var p = ds.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2] + n); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    // One small calendar per package part (house / tour / food). Availability comes from the server (dayCheck);
    // tour and food days outside the NEW house stay are unavailable, so they reload when the house changes.
    function makeCal(part, input) {
        var root = document.querySelector('.pk-sec[data-part="' + part + '"]'); if (!root || !input) return null;
        var grid = root.querySelector('.cal-grid'), title = root.querySelector('.cal-title'), msg = root.querySelector('.pk-msg');
        var start = input.value && input.value >= TODAY ? input.value : TODAY;
        var y = +start.split('-')[0], m = +start.split('-')[1] - 1, cache = {}, token = 0, info = null;
        function ctx() { return part === 'house' ? '&h_time=' + encodeURIComponent(hTime ? hTime.value : '') : (hDate ? '&h_date=' + encodeURIComponent(hDate.value) + '&h_time=' + encodeURIComponent(hTime.value) : ''); }
        function draw() {
            var first = new Date(y, m, 1).getDay(), n = new Date(y, m + 1, 0).getDate(), ym = y + '-' + pad(m + 1);
            var sel = input.value, out = part === 'house' && sel ? addDays(sel, NIGHTS) : '';
            title.textContent = MN[m] + ' ' + y;
            var h = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(function (d) { return '<div class="dn">' + d.slice(0, 2) + '</div>'; }).join('');
            for (var i = 0; i < first; i++) h += '<div class="day blank"></div>';
            for (var d = 1; d <= n; d++) {
                var ds = ym + '-' + pad(d), cls = 'day', r = info ? info[ds] : null;
                // House only: nostart = that night is free, but the package stay cannot BEGIN here (Phase 7.9: not shown as booked)
                var nostart = !!(part === 'house' && r && !r.ok && r.kind === 'start' && ds >= TODAY && ds <= MAXD);
                var off = ds < TODAY || ds > MAXD || (r && !r.ok);
                if (!info) cls += ' loading';
                if (ds === sel) cls += ' sel';
                else if (part === 'house' && sel && ds > sel && ds < out) cls += ' rng';
                else if (part === 'house' && ds === out) cls += ' outd';
                else if (nostart) cls += ' nostart';
                else if (off) cls += ' off';
                if (ds === CUR[part]) cls += ' cur';
                if (ds === TODAY) cls += ' today';
                h += '<div class="' + cls + '" data-d="' + ds + '" role="button" aria-disabled="' + (off ? 'true' : 'false') + '">' + d + '</div>';
            }
            grid.innerHTML = h;
        }
        function load() {
            var ym = y + '-' + pad(m + 1), key = ym + ctx(), my = ++token;
            if (cache[key]) { info = cache[key]; draw(); return; }
            info = null; draw();
            fetch('package-rebook.php?id=' + PKG_ID + EMQ + '&ajax=month&part=' + part + '&ym=' + ym + ctx(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (my !== token) return; if (j.days) { cache[key] = j.days; info = j.days; draw(); } else { msg.textContent = j.error || 'Could not load availability.'; } })
                .catch(function () { msg.textContent = 'Could not load availability. Please refresh.'; });
        }
        grid.addEventListener('click', function (e) {
            var el = e.target.closest('.day[data-d]'); if (!el) return;
            var ds = el.getAttribute('data-d'), r = info && info[ds];
            if (ds < TODAY || ds > MAXD || (r && !r.ok)) {
                msg.textContent = ds < TODAY ? 'That date has passed.' : (ds > MAXD ? 'Please choose a date within the next 12 months.' : ((r && r.message) || 'Not available.'));
                return;
            }
            msg.textContent = ''; input.value = ds; draw(); changed(part);
        });
        root.querySelector('.cal-prev').addEventListener('click', function () { m--; if (m < 0) { m = 11; y--; } load(); });
        root.querySelector('.cal-next').addEventListener('click', function () { m++; if (m > 11) { m = 0; y++; } load(); });
        return { load: load, draw: draw };
    }
    var cals = { house: makeCal('house', hDate), tour: makeCal('tour', tDate), food: makeCal('food', fDate) };
    function houseAuto() {
        if (!hDate || !$('h_auto')) return;
        var out = addDays(hDate.value, NIGHTS);
        $('h_auto').innerHTML = '<i class="fas fa-sign-out-alt"></i> Check-out (automatic): <strong>' + esc(fd(out) + ' · ' + ft(hTime.value)) + '</strong> — ' + NIGHTS + ' night' + (NIGHTS === 1 ? '' : 's') + ', same time as check-in.';
    }
    function changed(part) {
        if (part === 'house') { houseAuto(); if (cals.tour) cals.tour.load(); if (cals.food) cals.food.load(); }
        check();
    }
    function params() {
        var p = [];
        [['h_date', hDate], ['h_time', hTime], ['h_guests', hGuests], ['t_date', tDate], ['t_time', tTime], ['f_date', fDate], ['f_time', fTime]].forEach(function (x) {
            if (x[1]) p.push(x[0] + '=' + encodeURIComponent(x[1].value));
        });
        return p.join('&');
    }
    function lines(list) { return (list || []).map(function (l) { return '<div class="row"><i class="fas fa-calendar-check"></i> ' + esc(l.label) + ': <strong>' + esc(l.text) + '</strong></div>'; }).join(''); }
    // The whole package is checked by the server with the same rules the request and the confirmation use.
    function check() {
        var my = ++ptoken; last = null; btn.disabled = true; prev.style.display = 'none';
        note.textContent = 'Checking the new schedule…';
        fetch('package-rebook.php?id=' + PKG_ID + EMQ + '&ajax=check&' + params(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (my !== ptoken) return;
                if (j.ok) {
                    last = j; note.textContent = 'Everything is available.';
                    prev.innerHTML = '<h3><i class="fas fa-calendar-check"></i> Requested schedule</h3>' + lines(j.requested)
                        + '<div class="row"><i class="fas fa-hourglass-half"></i> One request for the whole package — waits for Admin/Staff confirmation. Payment unchanged.</div>';
                    prev.style.display = 'block'; btn.disabled = false;
                } else {
                    note.textContent = j.no_change ? 'Choose a new date or time for at least one part of the package.' : (j.message || j.error || 'Not available.');
                }
            })
            .catch(function () { note.textContent = 'Could not check the schedule. Please try again.'; });
    }
    if (hTime) { hTime.addEventListener('change', function () { if (cals.house) cals.house.load(); changed('house'); }); }
    if (hGuests) hGuests.addEventListener('change', check);
    if (tTime) tTime.addEventListener('change', check);
    if (fTime) fTime.addEventListener('change', check);
    $('rebookForm').addEventListener('submit', function (e) {
        if (!last || btn.disabled) { e.preventDefault(); return; }
        var cur = {}; (last.current || []).forEach(function (l) { cur[l.label] = l.text; });
        var rows = (last.requested || []).map(function (r) { return { label: r.label, from: cur[r.label] || '', to: r.text }; });
        var em = null;
        if (window.EmergencyForm) { em = EmergencyForm.check(); if (!em.ok) { e.preventDefault(); return; } }
        if (!RebookConfirm.gate(e, rows, em ? em.reason : '')) return;          // first click opens the confirmation; the confirmed submit continues
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending request…';
    });
    houseAuto();
    ['house', 'tour', 'food'].forEach(function (k) { if (cals[k]) cals[k].load(); });
    check();
})();
</script>
<?php endif; ?>
</body>
</html>

<?php
/**
 * Package Rebook Dashboard (guest, or ADMIN / STAFF from Booking Management).
 * Every other role is refused (403). Admin and staff use exactly the same rules, checks and
 * RebookService call as the guest; only WHO may open it differs. They get no payment, refund,
 * cancellation or user-management ability from this page (it never touches money).
 *
 * Shows the current package, the rebooking rules and allowance, the deadline and a calendar.
 * The guest picks the NEW FIRST DAY; house + tour + food move together by the same number of days
 * (house nights and all times unchanged). All rules live in RebookService — this page only displays
 * them and calls RebookService::rebookService(), which re-checks everything under locks.
 * The paid amount, the single ₱1,000 reservation fee and the payment records are never touched.
 */
session_start();
require_once 'database.php';
require_once 'includes/TermsGate.php';
TermsGate::enforceGuest($pdo);
require_once 'includes/PaymentService.php';
require_once 'includes/RebookService.php';
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

// ------------------------------------------------------------------ AJAX (read-only)
if ($isAjax) {
    if ($blockReason !== null || !$startDate) jsonOut(['error' => $blockReason ?: 'This package has no dated items to move.'], 409);
    $mode = (string)$_GET['ajax'];
    if ($mode === 'month') {
        $ym = (string)($_GET['ym'] ?? '');
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) jsonOut(['error' => 'Invalid month.'], 400);
        $days = [];
        $n = (int)date('t', strtotime($ym . '-01'));
        for ($d = 1; $d <= $n; $d++) {
            $ds = sprintf('%s-%02d', $ym, $d);
            $r = RebookService::packageDateCheck($pdo, $pkg, $parts, $ds);
            $days[$ds] = ['ok' => $r['ok'], 'message' => $r['message']];
        }
        jsonOut(['days' => $days]);
    }
    if ($mode === 'preview') {
        $r = RebookService::packageDateCheck($pdo, $pkg, $parts, (string)($_GET['date'] ?? ''));
        jsonOut($r);
    }
    jsonOut(['error' => 'Unknown request.'], 400);
}

// ------------------------------------------------------------------ SUBMIT
$error = '';
if (isset($_POST['rebook_package'])) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Your session expired. Please refresh the page and try again.');
        $res = RebookService::rebookService($pdo, 'package', $pkg_id, (string)($_POST['new_date'] ?? ''), $guest_id, (int)$_SESSION['user_id']);
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'rebook', 'booking',
                ($isAssist ? (($role === 'admin') ? 'Admin' : 'Staff') . ' rebooked' : 'Guest rebooked') . " package booking '{$res['reference']}' from {$res['old_date']} to {$res['new_date']} (payment carried forward, no new fee)",
                $pkg_id, 'package_booking', ['old_date' => $res['old_date']], ['new_date' => $res['new_date'], 'by' => $isAssist ? $role : 'guest']);
        }
        header('Location: ' . ($isAssist ? 'booking-management.php?tab=package&pkg_rebooked=' . urlencode($res['reference']) : 'profile.php?rebooked=service'));
        exit();
    } catch (AvailabilityConflictException $e) {
        $error = 'That date is not available — ' . $e->getMessage() . ' Please choose another date.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rebook Package - <?php echo htmlspecialchars($site_name); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        <h1>Rebook Package <small>Ref: <?php echo htmlspecialchars($pkg['reference_number']); ?><?php if ($isAssist && $guestLabel !== ''): ?> · Guest: <?php echo htmlspecialchars($guestLabel); ?><?php endif; ?></small></h1>
    </div>

    <?php if ($error !== ''): ?><div class="pr-alert err"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
    <?php if ($blockReason !== null): ?><div class="pr-alert info"><i class="fas fa-info-circle"></i><span><?php echo htmlspecialchars($blockReason); ?></span></div><?php endif; ?>

    <div class="pr-grid">
        <div>
            <div class="pr-card" style="margin-bottom:16px;">
                <h2><i class="fas fa-box-open"></i> Current booking</h2>
                <?php if ($h): ?>
                <div class="pr-item"><i class="fas fa-home"></i><div><b><?php echo htmlspecialchars($h['item_name'] ?? 'House'); ?></b>
                    <span><?php echo htmlspecialchars(pr_dt($h['check_in_date'], $h['check_in_time'] ?: '14:00:00')); ?><br>to <?php echo htmlspecialchars(pr_dt($h['check_out_date'], $h['check_out_time'] ?: '12:00:00')); ?></span></div></div>
                <?php endif; ?>
                <?php if ($t): ?>
                <div class="pr-item"><i class="fas fa-ship"></i><div><b><?php echo htmlspecialchars($t['item_name'] ?? 'Tour'); ?></b>
                    <span><?php echo htmlspecialchars(pr_dt($t['booking_date'], $t['preferred_time'] ?: null)); ?></span></div></div>
                <?php endif; ?>
                <?php if ($f): ?>
                <div class="pr-item"><i class="fas fa-utensils"></i><div><b><?php echo htmlspecialchars($f['item_name'] ?? 'Food'); ?><?php if ((int)($f['quantity'] ?? 1) > 1): ?> × <?php echo (int)$f['quantity']; ?><?php endif; ?></b>
                    <span><?php echo htmlspecialchars(pr_dt($f['preferred_date'], $f['preferred_time'] ?: null)); ?> · <?php echo htmlspecialchars(ucfirst((string)$f['fulfillment_method'])); ?></span></div></div>
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
                <ul class="pr-rules">
                    <li><i class="fas fa-check-circle"></i><span>House, tour and food move <strong>together</strong> by the same number of days. House nights and all times stay the same.</span></li>
                    <li><i class="fas fa-check-circle"></i><span><?php echo $isAssist ? 'The guest\'s' : 'Your'; ?> payment is <strong>carried forward</strong>: no new reservation fee, no refund, price unchanged.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Up to <?php echo $maxRebooks; ?> rebooks per booking, within <?php echo RebookService::WINDOW_DAYS; ?> days from the booking date.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Dates are checked again when you confirm. If one was taken, nothing changes.</span></li>
                </ul>
            </div>
        </div>

        <div class="pr-card">
            <h2><i class="fas fa-calendar-alt"></i> Choose the new first day</h2>
            <?php if ($blockReason === null && $startDate): ?>
            <div class="cal-head">
                <h3 id="calTitle"></h3>
                <div class="cal-nav"><button type="button" id="calPrev" aria-label="Previous month">&lsaquo;</button> <button type="button" id="calNext" aria-label="Next month">&rsaquo;</button></div>
            </div>
            <div class="cal-grid" id="calGrid"></div>
            <div class="cal-legend"><span><i style="background:#ecfdf5;border:1px solid #10b981"></i>Available</span><span><i style="background:#f1f5f9;border:1px solid #cbd5e1"></i>Unavailable</span><span><i style="background:#4DA6D9"></i>Selected</span><span><i style="box-shadow:inset 0 0 0 2px #F4B400"></i>Current first day</span></div>
            <div class="cal-note" id="calNote">Pick a date. Unavailable dates are greyed out — tap one to see why.</div>
            <div class="pr-preview" id="preview"></div>
            <form method="POST" id="rebookForm">
                <input type="hidden" name="rebook_package" value="1">
                <input type="hidden" name="id" value="<?php echo (int)$pkg_id; ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="new_date" id="newDate" value="">
                <button type="submit" class="pr-btn" id="submitBtn" disabled><i class="fas fa-check-circle"></i> Confirm Rebook</button>
            </form>
            <?php else: ?>
            <p style="font-size:14px;color:#64748b;">This package cannot be rebooked right now.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($blockReason === null && $startDate): ?>
<script>
(function () {
    var PKG_ID = <?php echo (int)$pkg_id; ?>;
    var CURRENT = <?php echo json_encode($startDate); ?>;
    var TODAY = <?php echo json_encode(date('Y-m-d')); ?>;
    var MAXD = <?php echo json_encode(date('Y-m-d', strtotime('+365 days'))); ?>;
    var grid = document.getElementById('calGrid'), title = document.getElementById('calTitle');
    var note = document.getElementById('calNote'), prev = document.getElementById('preview');
    var btn = document.getElementById('submitBtn'), hidden = document.getElementById('newDate');
    var parts = CURRENT.split('-'), y = +parts[0], m = +parts[1] - 1, selected = null, token = 0;
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
            if (ds === CURRENT) cls += ' cur';
            if (ds === selected) cls += ' sel';
            h += '<div class="' + cls + '" data-d="' + ds + '">' + d + '</div>';
        }
        grid.innerHTML = h;
    }
    function load() {
        var ym = y + '-' + pad(m + 1), my = ++token;
        if (cache[ym]) { draw(cache[ym]); return; }
        draw(null);
        fetch('package-rebook.php?id=' + PKG_ID + '&ajax=month&ym=' + ym, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.days) { cache[ym] = j.days; if (my === token) draw(j.days); } else { note.textContent = j.error || 'Could not load availability.'; } })
            .catch(function () { note.textContent = 'Could not load availability. Please refresh.'; });
    }
    function showPlan(plan) {
        var h = '<h3><i class="fas fa-calendar-check"></i> New schedule</h3>';
        if (plan.house) h += '<div class="row"><i class="fas fa-home"></i> House: ' + fd(plan.house.in) + ' ' + ft(plan.house.in_time) + ' to ' + fd(plan.house.out) + ' ' + ft(plan.house.out_time) + '</div>';
        if (plan.tour) h += '<div class="row"><i class="fas fa-ship"></i> Tour: ' + fd(plan.tour.date) + (plan.tour.time ? ' · ' + ft(plan.tour.time) : '') + '</div>';
        if (plan.food) h += '<div class="row"><i class="fas fa-utensils"></i> Food: ' + fd(plan.food.date) + (plan.food.time ? ' · ' + ft(plan.food.time) : '') + '</div>';
        prev.innerHTML = h; prev.style.display = 'block';
    }
    grid.addEventListener('click', function (e) {
        var el = e.target.closest('.day[data-d]'); if (!el) return;
        var ds = el.getAttribute('data-d'), ym = y + '-' + pad(m + 1), info = cache[ym] && cache[ym][ds];
        if (el.classList.contains('off')) {
            note.textContent = ds < TODAY ? 'That date has passed.' : (ds > MAXD ? 'Please choose a date within the next 12 months.' : ((info && info.message) || 'Not available.'));
            return;
        }
        selected = ds; hidden.value = ds; btn.disabled = true; note.textContent = 'Checking ' + fd(ds) + '…'; prev.style.display = 'none';
        draw(cache[ym]);
        fetch('package-rebook.php?id=' + PKG_ID + '&ajax=preview&date=' + ds, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (selected !== ds) return;
                if (j.ok) { note.textContent = 'Everything is available on ' + fd(ds) + '.'; showPlan(j.plan); btn.disabled = false; }
                else { note.textContent = j.message || j.error || 'Not available.'; if (j.plan) showPlan(j.plan); btn.disabled = true; }
            })
            .catch(function () { note.textContent = 'Could not check that date. Please try again.'; });
    });
    document.getElementById('calPrev').addEventListener('click', function () { m--; if (m < 0) { m = 11; y--; } load(); });
    document.getElementById('calNext').addEventListener('click', function () { m++; if (m > 11) { m = 0; y++; } load(); });
    document.getElementById('rebookForm').addEventListener('submit', function (e) { if (!hidden.value) e.preventDefault(); });
    load();
})();
</script>
<?php endif; ?>
</body>
</html>

<?php
/**
 * Food Rebook Dashboard (guest, or ADMIN / STAFF from Booking Management).
 *
 * Shows the current food order, the rebooking rules and allowance, the deadline and a calendar. Only the DATE moves:
 * quantity, size, time, pickup/delivery, the delivery unit, the price and every payment field stay exactly as they are.
 * A DELIVERY order can only move to a date inside the guest's confirmed stay at that unit (same rule as ordering).
 * All rules live in RebookService — this page only displays them and calls RebookService::rebookService(), which
 * re-checks everything under locks. Every other role gets 403. This page never touches money.
 */
session_start();
require_once 'database.php';
require_once 'includes/TermsGate.php';
TermsGate::enforceGuest($pdo);
require_once 'includes/PaymentService.php';
require_once 'includes/RebookService.php';
if (file_exists('includes/SystemLogger.php')) require_once 'includes/SystemLogger.php';

if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit(); }

// Who may open this page: the guest who owns the booking, or admin/staff assisting a guest. Nobody else.
$role = (string)($_SESSION['role'] ?? '');
$isAssist = in_array($role, ['admin', 'staff'], true);   // admin and staff help guests rebook
if (!$isAssist && $role !== 'guest') {
    http_response_code(403);
    if (isset($_GET['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'You do not have permission to rebook food orders.']); exit(); }
    exit('You do not have permission to rebook food orders.');
}
$backUrl = $isAssist ? 'booking-management.php?tab=food' : 'profile.php';

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

$isAjax = isset($_GET['ajax']);
$order_pk = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($isAssist) {
    // Admin/staff: any food order. The guest id passed on to RebookService is the booking's own guest (owner check still runs).
    $st = $pdo->prepare("SELECT * FROM food_bookings WHERE id = ?");
    $st->execute([$order_pk]);
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
    $st = $pdo->prepare("SELECT * FROM food_bookings WHERE id = ? AND guest_id = ?");
    $st->execute([$order_pk, $guest_id]);
}
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    if ($isAjax) jsonOut(['error' => 'Booking not found.'], 404);
    header('Location: ' . ($isAssist ? 'booking-management.php?tab=food' : 'profile.php?error=' . urlencode('Food order not found.'))); exit();
}
if ($isAssist) $guest_id = (int)$order['guest_id'];
$guestLabel = '';
if ($isAssist) {
    try {
        $gn = $pdo->prepare("SELECT u.* FROM guests g JOIN users u ON u.id = g.user_id WHERE g.id = ?");
        $gn->execute([$guest_id]);
        if ($u = $gn->fetch(PDO::FETCH_ASSOC)) $guestLabel = (string)($u['fullname'] ?? $u['full_name'] ?? $u['name'] ?? $u['username'] ?? '');
    } catch (PDOException $e) {}
}

if (empty($_SESSION['food_rebook_csrf'])) $_SESSION['food_rebook_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['food_rebook_csrf'];

$blockReason = RebookService::serviceBlockReason('food', $order);
$view = RebookService::foodView($pdo, $order);
$startDate = $order['preferred_date'];
$stayWindows = RebookService::foodStayWindows($pdo, $order);

// ------------------------------------------------------------------ AJAX (read-only)
if ($isAjax) {
    if ($blockReason !== null || !$startDate) jsonOut(['error' => $blockReason ?: 'This food order has no date to move.'], 409);
    $mode = (string)$_GET['ajax'];
    if ($mode === 'month') {
        $ym = (string)($_GET['ym'] ?? '');
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym)) jsonOut(['error' => 'Invalid month.'], 400);
        $days = [];
        $n = (int)date('t', strtotime($ym . '-01'));
        for ($d = 1; $d <= $n; $d++) {
            $ds = sprintf('%s-%02d', $ym, $d);
            $r = RebookService::foodDateCheck($pdo, $order, $ds);
            $days[$ds] = ['ok' => $r['ok'], 'message' => $r['message']];
        }
        jsonOut(['days' => $days]);
    }
    if ($mode === 'preview') {
        $r = RebookService::foodDateCheck($pdo, $order, (string)($_GET['date'] ?? ''));
        jsonOut($r);
    }
    jsonOut(['error' => 'Unknown request.'], 400);
}

// ------------------------------------------------------------------ SUBMIT
$error = '';
if (isset($_POST['rebook_food'])) {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new Exception('Your session expired. Please refresh the page and try again.');
        $res = RebookService::rebookService($pdo, 'food', $order_pk, (string)($_POST['new_date'] ?? ''), $guest_id, (int)$_SESSION['user_id']);
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'rebook', 'booking',
                ($isAssist ? (($role === 'admin') ? 'Admin' : 'Staff') . ' rebooked' : 'Guest rebooked') . " food order '{$res['reference']}' from {$res['old_date']} to {$res['new_date']} (payment carried forward, no new fee)",
                $order_pk, 'food_booking', ['old_date' => $res['old_date']], ['new_date' => $res['new_date'], 'by' => $isAssist ? $role : 'guest']);
        }
        header('Location: ' . ($isAssist ? 'booking-management.php?tab=food&food_rebooked=' . urlencode($res['reference']) : 'profile.php?rebooked=service'));
        exit();
    } catch (AvailabilityConflictException $e) {
        $error = 'That date is not available — ' . $e->getMessage() . ' Please choose another date.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$remaining = RebookService::remaining($order);
$daysLeft = RebookService::daysLeft($order);
$deadline = RebookService::deadline($order);
$prev = RebookService::previousStartDates($pdo, $order_pk, 'food_booking');
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
<title>Rebook Food - <?php echo htmlspecialchars($site_name); ?></title>
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
        <h1>Rebook Food <small>Ref: <?php echo htmlspecialchars($order['reference_number']); ?><?php if ($isAssist && $guestLabel !== ''): ?> · Guest: <?php echo htmlspecialchars($guestLabel); ?><?php endif; ?></small></h1>
    </div>

    <?php if ($error !== ''): ?><div class="pr-alert err"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>
    <?php if ($blockReason !== null): ?><div class="pr-alert info"><i class="fas fa-info-circle"></i><span><?php echo htmlspecialchars($blockReason); ?></span></div><?php endif; ?>

    <div class="pr-grid">
        <div>
            <div class="pr-card" style="margin-bottom:16px;">
                <h2><i class="fas fa-box-open"></i> Current booking</h2>
                <div class="pr-item"><i class="fas fa-utensils"></i><div><b><?php echo htmlspecialchars($view['item_name'] !== '' ? $view['item_name'] : 'Food'); ?><?php if ((int)$order['quantity'] > 1): ?> × <?php echo (int)$order['quantity']; ?><?php endif; ?></b>
                    <span><?php echo htmlspecialchars(pr_dt($order['preferred_date'], $order['preferred_time'] ?: null)); ?><?php if (!empty($order['size_variant'])): ?><br>Size: <?php echo htmlspecialchars($order['size_variant']); ?> · Qty <?php echo (int)$order['quantity']; ?> (stay the same)<?php endif; ?></span></div></div>
                <div class="pr-item"><i class="fas <?php echo ($order['fulfillment_method'] ?? '') === 'delivery' ? 'fa-truck' : 'fa-store'; ?>"></i><div><b><?php echo ($order['fulfillment_method'] ?? '') === 'delivery' ? 'Delivery' : 'Pickup'; ?></b>
                    <span><?php if (($order['fulfillment_method'] ?? '') === 'delivery'): ?>To <?php echo htmlspecialchars((string)$order['delivery_address']); ?><?php if ($stayWindows): ?><br>Stay: <?php echo htmlspecialchars(implode(' | ', $stayWindows)); ?><?php endif; ?><?php else: ?>No delivery stay needed<?php endif; ?></span></div></div>
                <div class="pr-item"><i class="fas fa-wallet"></i><div><b>Payment</b>
                    <span>₱<?php echo number_format((float)$order['amount_paid'], 2); ?> paid of ₱<?php echo number_format((float)$order['total_amount'], 2); ?> — stays on this booking</span></div></div>
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
                <ul class="pr-rules">
                    <li><i class="fas fa-check-circle"></i><span>Only the <strong>date</strong> moves. Item, size, quantity and your order time stay the same.</span></li>
                    <?php if (($order['fulfillment_method'] ?? '') === 'delivery'): ?><li><i class="fas fa-check-circle"></i><span><strong>Delivery</strong> must stay inside your confirmed house stay, at the same unit (<?php echo htmlspecialchars((string)$order['delivery_address']); ?>). Dates outside the stay are not available.</span></li><?php endif; ?>
                    <li><i class="fas fa-check-circle"></i><span><?php echo $isAssist ? 'The guest\'s' : 'Your'; ?> payment is <strong>carried forward</strong>: no new reservation fee, no refund, price unchanged.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>Up to <?php echo $maxRebooks; ?> rebooks per booking, within <?php echo RebookService::WINDOW_DAYS; ?> days from the booking date.</span></li>
                    <li><i class="fas fa-check-circle"></i><span>The date must be available. It is checked again when you confirm; if it was taken, nothing changes.</span></li>
                </ul>
            </div>
        </div>

        <div class="pr-card">
            <h2><i class="fas fa-calendar-alt"></i> Choose the new date</h2>
            <?php if ($blockReason === null && $startDate): ?>
            <div class="cal-head">
                <h3 id="calTitle"></h3>
                <div class="cal-nav"><button type="button" id="calPrev" aria-label="Previous month">&lsaquo;</button> <button type="button" id="calNext" aria-label="Next month">&rsaquo;</button></div>
            </div>
            <div class="cal-grid" id="calGrid"></div>
            <div class="cal-legend"><span><i style="background:#ecfdf5;border:1px solid #10b981"></i>Available</span><span><i style="background:#f1f5f9;border:1px solid #cbd5e1"></i>Unavailable</span><span><i style="background:#4DA6D9"></i>Selected</span><span><i style="box-shadow:inset 0 0 0 2px #F4B400"></i>Current date</span></div>
            <div class="cal-note" id="calNote">Pick a date. Unavailable dates are greyed out — tap one to see why.</div>
            <div class="pr-preview" id="preview"></div>
            <form method="POST" id="rebookForm">
                <input type="hidden" name="rebook_food" value="1">
                <input type="hidden" name="id" value="<?php echo (int)$order_pk; ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="new_date" id="newDate" value="">
                <button type="submit" class="pr-btn" id="submitBtn" disabled><i class="fas fa-check-circle"></i> Confirm Rebook</button>
            </form>
            <?php else: ?>
            <p style="font-size:14px;color:#64748b;">This food order cannot be rebooked right now.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($blockReason === null && $startDate): ?>
<?php require __DIR__ . '/includes/rebook-confirm.php'; ?>
<script>
(function () {
    var PKG_ID = <?php echo (int)$order_pk; ?>;
    var CURRENT = <?php echo json_encode($startDate); ?>;
    var CUR_LABEL = <?php echo json_encode(pr_dt($order['preferred_date'], $order['preferred_time'] ?: null)); ?>, lastPlan = null;   // confirmation step only
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
        fetch('food-rebook.php?id=' + PKG_ID + '&ajax=month&ym=' + ym, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.days) { cache[ym] = j.days; if (my === token) draw(j.days); } else { note.textContent = j.error || 'Could not load availability.'; } })
            .catch(function () { note.textContent = 'Could not load availability. Please refresh.'; });
    }
    function showPlan(plan) {
        lastPlan = plan;
        var h = '<h3><i class="fas fa-calendar-check"></i> New schedule</h3>';
        h += '<div class="row"><i class="fas fa-utensils"></i> ' + (plan.method === 'delivery' ? 'Delivery' : 'Pickup') + ': ' + fd(plan.date) + (plan.time ? ' · ' + ft(plan.time) : '') + '</div>';
        if (plan.method === 'delivery' && plan.address) h += '<div class="row"><i class="fas fa-truck"></i> To ' + esc(plan.address) + '</div>';
        h += '<div class="row"><i class="fas fa-check"></i> Item, size, quantity and time stay the same. Your payment carries over.</div>';
        prev.innerHTML = h; prev.style.display = 'block';
    }
    grid.addEventListener('click', function (e) {
        var el = e.target.closest('.day[data-d]'); if (!el) return;
        var ds = el.getAttribute('data-d'), ym = y + '-' + pad(m + 1), info = cache[ym] && cache[ym][ds];
        if (el.classList.contains('off')) {
            note.textContent = ds < TODAY ? 'That date has passed.' : (ds > MAXD ? 'Please choose a date within the next 12 months.' : ((info && info.message) || 'Not available.'));
            return;
        }
        selected = ds; lastPlan = null; hidden.value = ds; btn.disabled = true; note.textContent = 'Checking ' + fd(ds) + '…'; prev.style.display = 'none';
        draw(cache[ym]);
        fetch('food-rebook.php?id=' + PKG_ID + '&ajax=preview&date=' + ds, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (selected !== ds) return;
                if (j.ok) { note.textContent = 'Available on ' + fd(ds) + '.'; showPlan(j.plan); btn.disabled = false; }
                else { note.textContent = j.message || j.error || 'Not available.'; if (j.plan) showPlan(j.plan); btn.disabled = true; }
            })
            .catch(function () { note.textContent = 'Could not check that date. Please try again.'; });
    });
    document.getElementById('calPrev').addEventListener('click', function () { m--; if (m < 0) { m = 11; y--; } load(); });
    document.getElementById('calNext').addEventListener('click', function () { m++; if (m > 11) { m = 0; y++; } load(); });
    document.getElementById('rebookForm').addEventListener('submit', function (e) {
        if (!hidden.value || !lastPlan) { e.preventDefault(); return; }
        RebookConfirm.gate(e, [{ label: lastPlan.method === 'delivery' ? 'Food delivery' : 'Food pickup', from: CUR_LABEL, to: fd(lastPlan.date) + (lastPlan.time ? ' · ' + ft(lastPlan.time) : '') }]);
    });
    load();
})();
</script>
<?php endif; ?>
</body>
</html>

<?php
/**
 * EmergencyRebookUI — the shared pieces of the guest Emergency Rebook screens (Phase 7.7): rebook.php (House),
 * tour-rebook.php, food-rebook.php and package-rebook.php in `?emergency=1` mode.
 * Display + the POST glue only; every rule lives in EmergencyRebookService / RebookService.
 */
require_once __DIR__ . '/EmergencyRebookService.php';

class EmergencyRebookUI {

    const LABELS = ['house' => 'House', 'tour' => 'Tour', 'food' => 'Food', 'package' => 'Package'];

    /** ?emergency=1 (or an emergency POST) on a guest page. Admin/Staff never use the guest Emergency form. */
    public static function wanted($isAssist) {
        if ($isAssist) return false;
        return (isset($_GET['emergency']) && $_GET['emergency'] === '1') || isset($_POST['emergency_rebook']);
    }

    /** Prefilled contact for the form: the guest's saved number, shown without the +63 prefix. */
    public static function contactFor(PDO $pdo, $guestId) {
        try {
            $st = $pdo->prepare('SELECT contact_number FROM guests WHERE id = ?');
            $st->execute([(int)$guestId]);
            $d = preg_replace('/\D+/', '', (string)$st->fetchColumn());
            if (strlen($d) === 12 && strpos($d, '63') === 0) $d = substr($d, 2);
            elseif (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
            return $d;
        } catch (PDOException $e) { return ''; }
    }

    /** Why the normal rebook is closed, in guest words. */
    public static function whyText($reason) {
        if ($reason === 'both') return 'the 2-rebook limit has been reached and the 7-day rebook window has passed';
        if ($reason === 'limit_exhausted') return 'the 2-rebook limit has been reached';
        return 'the 7-day rebook window has passed';
    }

    /**
     * Handle the Emergency POST on a guest page. Returns an error message to show (never throws); on success it records the
     * audit log and redirects to the dashboard (exit). $csrf = the page's session token.
     */
    /**
     * Phase 7.9: Emergency e-mails, AFTER the change is committed. Best effort — never throws, never changes the request,
     * the booking or any payment, never shows mail errors (EmailNotifications::notifyEmergency logs them safely).
     */
    public static function notify(PDO $pdo, $event, $requestId) {
        try {
            if (!class_exists('EmailNotifications')) {
                if (!is_file(__DIR__ . '/EmailNotifications.php') || !is_file(__DIR__ . '/../config/mail_config.php')) return null;
                require_once __DIR__ . '/EmailNotifications.php';
            }
            return EmailNotifications::notifyEmergency($pdo, $event, (int)$requestId);
        } catch (Throwable $e) {
            error_log('Emergency notification (' . $event . ') skipped: ' . get_class($e));
            return null;
        }
    }

    public static function handlePost(PDO $pdo, $type, $bookingId, $guestId, $csrf) {
        try {
            if (!hash_equals((string)$csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Your session expired. Please refresh the page and try again.');
            // Only the new schedule, the reason and the contact number are read from the browser. Amount / payment / status fields are ignored.
            $res = EmergencyRebookService::request($pdo, $type, (int)$bookingId, (int)$guestId, EmergencyRebookService::inputFromPost($type, $_POST),
                                                   $_POST['reason'] ?? '', $_POST['contact'] ?? '', (int)($_SESSION['user_id'] ?? 0));
            EmergencyRebookService::log($pdo, 'request', ['id' => $res['id'], 'booking_type' => $type, 'booking_id' => (int)$bookingId, 'reference_number' => $res['reference']], 'guest',
                ['eligibility' => $res['eligibility'], 'original' => $res['original'], 'requested' => $res['requested']]);
            self::notify($pdo, 'request', $res['id']);   // Phase 7.9: guest acknowledgement + Admin/Staff alert (after commit)
            header('Location: profile.php?emergency_requested=' . urlencode($type));
            exit();
        } catch (PDOException $e) {
            error_log('emergency rebook request failed: ' . $e->getMessage());
            return 'Something went wrong and nothing was saved. Please try again.';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public static function css() {
        return <<<'CSS'
<style>
.em-banner{border:2px solid #fb923c;background:#fff7ed;border-radius:14px;padding:12px 14px;margin-bottom:14px;color:#9a3412;font-size:14px;display:flex;gap:10px;align-items:flex-start}
.em-banner>i{margin-top:3px;color:#ea580c}.em-banner b{display:block;color:#9a3412;margin-bottom:2px}.em-banner span{min-width:0;overflow-wrap:anywhere}
.em-link{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:9px 14px;border-radius:10px;background:#fff7ed;color:#c2410c;border:1px solid #fdba74;font-weight:700;font-size:14px;text-decoration:none}
.em-link:hover{background:#ffedd5}
.em-field{margin-top:12px}.em-field label{display:block;font-weight:600;font-size:13px;margin-bottom:6px;color:#0B2447}
.em-field textarea,.em-field input{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;background:#fff;color:#1e293b;font-family:inherit;resize:vertical}
.em-field textarea:focus,.em-field input:focus{outline:2px solid #fdba74;border-color:#ea580c}
.em-field.bad textarea,.em-field.bad input{border-color:#dc2626;background:#fef2f2}
.em-req{color:#dc2626}.em-count{font-size:11px;color:#94a3b8;text-align:right;margin-top:2px}
.em-err{display:none;font-size:12.5px;color:#b91c1c;margin-top:4px}.em-field.bad .em-err{display:block}
.pr-btn.em-btn{background:#ea580c;color:#fff}.pr-btn.em-btn:disabled{background:#e2e8f0;color:#94a3b8}
.em-pending{border:2px solid #fb923c;background:#fff7ed;border-radius:12px;padding:12px 14px;font-size:14px;color:#9a3412}
.em-pending b{display:block;margin-bottom:4px}.em-pending .row{padding:3px 0;overflow-wrap:anywhere}
@media(max-width:480px){.em-banner{padding:10px 12px;font-size:13px}.em-link{width:100%;justify-content:center}}
</style>
CSS;
    }

    /** Orange explanation at the top of the Emergency screen. */
    public static function banner($type, $reason) {
        $label = self::LABELS[$type] ?? 'Booking';
        return '<div class="em-banner"><i class="fas fa-exclamation-triangle"></i><span><b>Emergency Rebook request — ' . htmlspecialchars($label) . '</b>'
             . 'Your normal rebook is closed because ' . htmlspecialchars(self::whyText($reason)) . '. You can ask Admin/Staff for an Emergency Rebook. '
             . 'It still needs their approval and depends on availability, so it does not guarantee rescheduling. Your payment stays as it is.</span></div>';
    }

    /** Orange link shown on the normal Rebook screen when the booking qualifies. */
    public static function link($pageUrl, $id) {
        return '<a class="em-link" href="' . htmlspecialchars($pageUrl) . '?id=' . (int)$id . '&amp;emergency=1"><i class="fas fa-exclamation-triangle"></i> Request Emergency Rebook</a>';
    }

    /** Reason + contact fields (the schedule fields are the page's own). */
    public static function fields($contact) {
        return '<div class="em-field" id="emReasonBox"><label for="emReason">Reason for the Emergency Rebook <span class="em-req">*</span></label>'
             . '<textarea id="emReason" name="reason" rows="3" maxlength="' . EmergencyRebookService::REASON_MAX . '" placeholder="Briefly tell us why you need to reschedule" required></textarea>'
             . '<div class="em-count"><span id="emCount">0</span>/' . EmergencyRebookService::REASON_MAX . '</div><div class="em-err" id="emReasonErr" role="alert"></div></div>'
             . '<div class="em-field" id="emContactBox"><label for="emContact">Contact number <span class="em-req">*</span></label>'
             . '<input type="tel" id="emContact" name="contact" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="09123456789" value="' . htmlspecialchars($contact) . '" required>'
             . '<div class="em-err" id="emContactErr" role="alert"></div></div>';
    }

    /** Client-side checks (the server validates again). EmergencyForm.check() -> {ok, reason}. */
    public static function js() {
        return <<<'JS'
<script>
window.EmergencyForm = (function () {
    var r = document.getElementById('emReason'), c = document.getElementById('emContact'), n = document.getElementById('emCount');
    var rb = document.getElementById('emReasonBox'), cb = document.getElementById('emContactBox');
    function setErr(box, id, msg) { box.classList.toggle('bad', !!msg); document.getElementById(id).textContent = msg || ''; }
    if (r) r.addEventListener('input', function () { n.textContent = r.value.length; if (rb.classList.contains('bad')) check(); });
    if (c) c.addEventListener('input', function () { if (cb.classList.contains('bad')) check(); });
    // 'required' would otherwise show the browser's own bubble and skip our inline messages
    if (r) r.addEventListener('invalid', function (e) { e.preventDefault(); check(); });
    if (c) c.addEventListener('invalid', function (e) { e.preventDefault(); check(); });
    function check() {
        var rv = (r.value || '').trim(), d = (c.value || '').replace(/\D+/g, '');
        if (d.length === 12 && d.indexOf('63') === 0) d = d.slice(2); else if (d.length === 11 && d.charAt(0) === '0') d = d.slice(1);
        var e1 = rv.length === 0 ? 'Please tell us the reason for your request.' : (rv.length < 10 ? 'Please give a little more detail (at least 10 characters).' : (rv.length > 500 ? 'The reason is too long (maximum 500 characters).' : ''));
        var e2 = c.value.trim() === '' ? 'Please enter your contact number.' : (/^9\d{9}$/.test(d) ? '' : 'Please enter a valid PH mobile number (e.g., 9123456789).');
        setErr(rb, 'emReasonErr', e1); setErr(cb, 'emContactErr', e2);
        if (e1) r.focus(); else if (e2) c.focus();
        return { ok: !e1 && !e2, reason: rv };
    }
    return { check: check };
})();
</script>
JS;
    }

    /** The "waiting for Admin/Staff" box that replaces the form while a request is pending. */
    public static function pendingBox($type, array $pending, $backUrl) {
        $lines = RebookService::scheduleLines($type, $pending['requested']);
        $h = '<div class="em-pending"><b><i class="fas fa-hourglass-half"></i> Emergency Rebook Pending — waiting for admin/staff approval</b>';
        foreach ($lines as $l) $h .= '<div class="row">Requested ' . htmlspecialchars($l['label']) . ': <strong>' . htmlspecialchars($l['text']) . '</strong></div>';
        $h .= '<div class="row" style="font-size:13px;">Requested on ' . htmlspecialchars(date('M j, Y g:i A', strtotime($pending['requested_at']))) . '. Your current schedule and payment stay as they are until it is decided. You can cancel the request from your dashboard.</div></div>';
        return $h . '<p class="pr-hint"><a href="' . htmlspecialchars($backUrl) . '">Back to my bookings</a></p>';
    }

    /** The confirmation modal needs the page to mark it as Emergency before including rebook-confirm.php. */
    public static function confirmWhyJs() { return 'EmergencyForm.check()'; }
}

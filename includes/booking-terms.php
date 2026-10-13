<?php
/**
 * BookingTerms — "read and accept the Terms before you book" for the TOUR and FOOD booking forms (Phase 7.7).
 *
 * Same experience as the House / Package booking terms: Book → Terms modal → scroll to the end → tick the box →
 * "Accept & Confirm Booking" → only then does the booking POST go out. Cancel / close = nothing is created.
 *
 * CONTENT  The active Terms in site_content: `booking_terms` (title/body, the editable booking policy House reads)
 *          when the owner has created it, otherwise the active `terms` body. No second policy text lives in code.
 * SERVER   JavaScript alone is not trusted. The page puts a per-session token in the form; the modal adds
 *          terms_ack=1 only after the guest accepted. BookingTerms::acknowledged() must be true on the POST, otherwise
 *          the booking is refused. The token is random per session and cannot be guessed by another site, so it also
 *          stops a cross-site form post from creating a booking (these two forms had no token before).
 * USE      <form> ... <?php echo BookingTerms::field(); ?> ... </form>   (inside the form)
 *          <?php BookingTerms::render($pdo); ?>                            (once per page, anywhere in <body>)
 *          JS:  BookingTerms.gate(formElement, 'submit_button_name')       (after the form's own validation passed)
 *          POST handler:  if (!BookingTerms::acknowledged()) throw new Exception(BookingTerms::MISSING_MESSAGE);
 */
class BookingTerms {

    const MISSING_MESSAGE = 'Please read and accept the Terms & Conditions before booking.';

    /** Per-session token (created on first use). */
    public static function token() {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';
        if (empty($_SESSION['booking_terms_token'])) $_SESSION['booking_terms_token'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['booking_terms_token'];
    }

    /** Hidden field to put inside the booking form. */
    public static function field() {
        return '<input type="hidden" name="terms_ack_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    /** True only when this POST carries the session token AND the acceptance flag the modal sets. */
    public static function acknowledged() {
        $tok = $_SESSION['booking_terms_token'] ?? '';
        $got = $_POST['terms_ack_token'] ?? '';
        return is_string($tok) && $tok !== '' && is_string($got) && hash_equals($tok, $got)
            && isset($_POST['terms_ack']) && $_POST['terms_ack'] === '1';
    }

    /** ['title' => string, 'html' => string]: the booking terms the owner edited, else the active Terms. */
    public static function content(PDO $pdo) {
        $rows = [];
        try {
            $st = $pdo->query("SELECT section_name, content_key, content_value FROM site_content WHERE section_name IN ('booking_terms','terms')");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['section_name']][$r['content_key']] = (string)$r['content_value'];
        } catch (PDOException $e) { $rows = []; }

        $bt = trim($rows['booking_terms']['body'] ?? '');
        if ($bt !== '') {
            $title = trim($rows['booking_terms']['title'] ?? '') ?: 'Booking Terms & Conditions';
            $html = preg_match('/<[^>]+>/', $bt) ? $bt : self::plainToHtml($bt);   // editable content, same trust as the House page
            return ['title' => $title, 'html' => $html];
        }
        $body = trim($rows['terms']['body'] ?? '');
        $title = trim($rows['terms']['title'] ?? '') ?: 'Terms & Conditions';
        if ($body === '') $body = "A ₱1,000 reservation fee is required to secure every booking and forms part of the total.\nReservation fees and amounts paid are non-refundable; paid bookings may be rebooked instead.\nRebooking is subject to Admin/Staff approval and availability.";
        return ['title' => $title, 'html' => self::plainToHtml($body)];
    }

    /**
     * Plain-text Terms -> safe HTML: "1. HEADING" lines become headings, "•" lines become list items, the rest paragraphs.
     * Everything is escaped, so a Terms body containing markup can never inject script.
     */
    public static function plainToHtml($text) {
        $out = ''; $inList = false;
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '') { if ($inList) { $out .= '</ul>'; $inList = false; } continue; }
            if (preg_match('/^[•\-\*]\s*(.+)$/u', $line, $m)) {
                if (!$inList) { $out .= '<ul>'; $inList = true; }
                $out .= '<li>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</li>';
                continue;
            }
            if ($inList) { $out .= '</ul>'; $inList = false; }
            if (preg_match('/^\d+\.\s+\S/u', $line) && $line === mb_strtoupper($line, 'UTF-8')) $out .= '<h3>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</h3>';
            else $out .= '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if ($inList) $out .= '</ul>';
        return $out;
    }

    /** Modal markup + styles + script. Output once per page. */
    public static function render(PDO $pdo) {
        $c = self::content($pdo);
        ?>
<style>
.btm-overlay { position: fixed; inset: 0; z-index: 4000; display: none; align-items: center; justify-content: center; padding: 14px; background: rgba(15, 23, 42, 0.62); }
.btm-overlay.show { display: flex; }
.btm-box { position: relative; width: 100%; max-width: 640px; max-height: 94vh; max-height: 94dvh; display: flex; flex-direction: column; background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,0.35); }
.btm-head { position: relative; padding: 22px 56px 16px 22px; text-align: center; background: linear-gradient(135deg, #0B2447, #19376D); color: #fff !important; }
.btm-head .btm-icon { font-size: 34px; color: #4DA6D9; margin-bottom: 6px; }
/* design-system.css normalizes all h1-h6 and p colors with !important.
   These stronger booking-terms rules keep the dark modal header readable. */
.btm-head h3 { margin: 0; font-size: 19px; line-height: 1.25; color: #fff !important; }
.btm-head p { margin: 4px 0 0; font-size: 12.5px; color: rgba(255,255,255,0.92) !important; opacity: 1 !important; }
.btm-close { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border: 0; border-radius: 50%; background: rgba(255,255,255,0.15); color: #fff; font-size: 15px; cursor: pointer; }
.btm-close:hover { background: rgba(255,255,255,0.28); }
.btm-scroll { flex: 1 1 auto; min-height: 120px; overflow-y: auto; padding: 18px 22px; -webkit-overflow-scrolling: touch; }
.btm-body { font-size: 14px; line-height: 1.65; color: #334155; overflow-wrap: anywhere; }
.btm-body h3 { margin: 16px 0 6px; font-size: 14px; color: #0B2447; letter-spacing: .2px; }
.btm-body h3:first-child { margin-top: 0; }
.btm-body p { margin: 0 0 10px; }
.btm-body ul { margin: 6px 0 12px; padding-left: 22px; }
.btm-body li { margin-bottom: 5px; }
.btm-hint { flex: 0 0 auto; padding: 9px 14px; text-align: center; font-size: 12.5px; font-weight: 600; background: #fef3c7; color: #92400e; }
.btm-hint.done { background: #dcfce7; color: #166534; }
.btm-accept { flex: 0 0 auto; display: none; padding: 12px 22px 16px; border-top: 1px solid #e2e8f0; background: #f8fafc; }
.btm-accept.visible { display: block; }
.btm-check { display: flex; gap: 10px; align-items: flex-start; padding: 11px 13px; border: 2px solid #e2e8f0; border-radius: 12px; background: #fff; cursor: pointer; font-size: 13.5px; line-height: 1.45; color: #1e293b; }
.btm-check input { width: 20px; height: 20px; margin-top: 1px; flex: 0 0 auto; accent-color: #10b981; }
.btm-ok { display: block; width: 100%; margin-top: 12px; padding: 13px; border: 0; border-radius: 12px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
.btm-ok:disabled { background: #cbd5e1; cursor: not-allowed; }
.btm-note { flex: 0 0 auto; margin: 0; padding: 8px 14px 12px; text-align: center; font-size: 11.5px; color: #64748b; background: #f8fafc; }
@media (max-width: 480px) {
    .btm-overlay { padding: 8px; }
    .btm-box { border-radius: 14px; max-height: 96vh; max-height: 96dvh; }
    .btm-head { padding: 16px 50px 12px 14px; } .btm-head h3 { font-size: 16.5px; } .btm-head .btm-icon { font-size: 28px; }
    .btm-scroll { padding: 14px 15px; } .btm-body { font-size: 13px; }
    .btm-accept { padding: 10px 14px 14px; } .btm-check { font-size: 12.5px; padding: 10px 11px; } .btm-ok { font-size: 14px; padding: 12px; }
}
</style>

<div class="btm-overlay" id="btmOverlay" role="dialog" aria-modal="true" aria-labelledby="btmTitle">
    <div class="btm-box">
        <div class="btm-head">
            <div class="btm-icon"><i class="fas fa-file-contract"></i></div>
            <h3 id="btmTitle"><?php echo htmlspecialchars($c['title']); ?></h3>
            <p>Please read and accept our booking terms</p>
            <button type="button" class="btm-close" id="btmClose" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="btm-scroll" id="btmScroll" tabindex="0"><div class="btm-body"><?php echo $c['html']; ?></div></div>
        <div class="btm-hint" id="btmHint"><i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox</div>
        <div class="btm-accept" id="btmAccept">
            <label class="btm-check" for="btmAgree">
                <input type="checkbox" id="btmAgree" disabled>
                <span><i class="fas fa-check-circle" style="color:#10b981;"></i> I understand there is <strong>NO CANCELLATION</strong> and only <strong>REBOOKING</strong> is allowed. I accept these terms.</span>
            </label>
            <button type="button" class="btm-ok" id="btmOk" disabled><i class="fas fa-check"></i> Accept &amp; Confirm Booking</button>
        </div>
        <p class="btm-note">Closing this window does not create a booking.</p>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('btmOverlay'), scroller = document.getElementById('btmScroll'), hint = document.getElementById('btmHint'),
        accept = document.getElementById('btmAccept'), agree = document.getElementById('btmAgree'), ok = document.getElementById('btmOk'), closeBtn = document.getElementById('btmClose');
    var onAccept = null, unlocked = false, prevOverflow = '';
    function unlock() {
        if (unlocked) return; unlocked = true;
        accept.classList.add('visible'); agree.disabled = false;
        hint.classList.add('done'); hint.innerHTML = '<i class="fas fa-check-circle"></i> You\'ve read everything. Please tick the box below.';
    }
    function atEnd() { return scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 15; }
    function open(cb) {
        onAccept = cb; unlocked = false;
        accept.classList.remove('visible'); agree.checked = false; agree.disabled = true; ok.disabled = true;
        hint.classList.remove('done'); hint.innerHTML = '<i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox';
        scroller.scrollTop = 0;
        prevOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
        overlay.classList.add('show');
        setTimeout(function () { if (scroller.scrollHeight <= scroller.clientHeight + 5 || atEnd()) unlock(); closeBtn.focus(); }, 80);
    }
    function close() { overlay.classList.remove('show'); document.body.style.overflow = prevOverflow || ''; onAccept = null; }
    scroller.addEventListener('scroll', function () { if (atEnd()) unlock(); });
    agree.addEventListener('change', function () { ok.disabled = !agree.checked; });
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    // Escape closes only this window (the booking form behind it stays as it was).
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('show')) { e.stopPropagation(); e.preventDefault(); close(); }
    }, true);
    ok.addEventListener('click', function () {
        if (!agree.checked || ok.disabled) return;
        ok.disabled = true;                                   // one click = one submit
        var cb = onAccept; close();
        if (cb) cb();
    });
    function hidden(form, name, value) {
        var el = form.querySelector('input[type="hidden"][name="' + name + '"][data-bt="1"]');
        if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = name; el.setAttribute('data-bt', '1'); form.appendChild(el); }
        el.value = value;
    }
    window.BookingTerms = {
        // Called after the form's own checks passed. Opens the Terms; on Accept it marks the POST as acknowledged,
        // re-adds the submit button's name (a script submit does not send it) and submits once.
        gate: function (form, submitName) {
            open(function () { hidden(form, 'terms_ack', '1'); if (submitName) hidden(form, submitName, '1'); form.submit(); });
        }
    };
})();
</script>
        <?php
    }
}

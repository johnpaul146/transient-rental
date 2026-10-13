<?php
/**
 * includes/rebook-confirm.php
 *
 * Confirmation step shown before a rebook REQUEST is sent (house / tour / food / package). Every rebook is a request
 * that Admin/Staff confirm later (Phase 7.6), so the final button reads "Submit Rebook Request".
 * UX ONLY: it never validates, prices or changes anything. The form's own POST (same URL, same fields, same CSRF
 * token) is submitted unchanged once the guest presses "Submit Rebook Request"; all rules stay in RebookService and
 * the rebook pages' server-side code.
 *
 * Phase 7.7: a page sets $rbcEmergency = true BEFORE including this file for an Emergency Rebook request. The modal then says so
 * ("This is an Emergency Rebook request and still requires Admin/Staff approval.") in a warning style; nothing else differs.
 *
 * Include once, inside <body>, before the page's own <script>. A page calls, from its form's submit handler:
 *     if (!RebookConfirm.gate(event, [{label:'Check-in', from:'Oct 14, 2026 · 2:00 PM', to:'Oct 20, 2026 · 2:00 PM'}, ...])) return;
 * gate() returns false (and opens the modal) on the first submit, and true when the submit is the confirmed one.
 */
$rbcEmergency = !empty($rbcEmergency);
?>
<style>
.rbc-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:16px;z-index:2000}
.rbc-overlay.show{display:flex}
.rbc-box{background:#fff;border-radius:16px;width:100%;max-width:460px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(15,23,42,.3);overflow:hidden}
.rbc-head{padding:18px 20px 10px}
.rbc-head h3{font-size:18px;color:#0B2447;display:flex;align-items:center;gap:8px}
.rbc-head h3 i{color:#4DA6D9}
.rbc-body{padding:4px 20px 8px;overflow-y:auto}
.rbc-row{border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;margin-bottom:10px;background:#f8fafc}
.rbc-label{font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px}
.rbc-line{display:flex;gap:8px;font-size:14px;color:#334155;padding:2px 0;align-items:baseline}
.rbc-line small{flex:0 0 58px;font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8}
.rbc-line span{min-width:0;overflow-wrap:anywhere}
.rbc-line.new{color:#0B2447;font-weight:700}
.rbc-line.new small{color:#4DA6D9}
.rbc-note{margin:2px 20px 4px;padding:10px 12px;border-radius:10px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;font-weight:600;display:flex;gap:8px;align-items:center}
.rbc-sub{margin:6px 20px 0;font-size:12px;color:#64748b}
.rbc-note.emergency{background:#fff7ed;border-color:#fdba74;color:#9a3412}
.rbc-box.emergency .rbc-head h3 i{color:#ea580c}
.rbc-box.emergency .rbc-btn.primary{background:#ea580c}
.rbc-why{margin:0 20px 4px;font-size:13px;color:#334155;overflow-wrap:anywhere}
.rbc-why b{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px}
.rbc-actions{display:flex;gap:10px;padding:14px 20px 18px}
.rbc-btn{flex:1;min-height:46px;border:0;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;padding:0 10px}
.rbc-btn.ghost{background:#e2e8f0;color:#475569}
.rbc-btn.primary{background:#4DA6D9;color:#fff}
.rbc-btn:disabled{opacity:.6;cursor:not-allowed}
@media (max-width:480px){.rbc-overlay{align-items:flex-end;padding:0}.rbc-box{max-width:none;border-radius:16px 16px 0 0;max-height:92vh}}
</style>
<div class="rbc-overlay" id="rbcModal" role="dialog" aria-modal="true" aria-labelledby="rbcTitle">
    <div class="rbc-box<?php echo $rbcEmergency ? ' emergency' : ''; ?>">
        <?php if ($rbcEmergency): ?>
        <div class="rbc-head"><h3 id="rbcTitle"><i class="fas fa-exclamation-triangle"></i> Submit Emergency Rebook request?</h3></div>
        <div class="rbc-body" id="rbcRows"></div>
        <div class="rbc-why" id="rbcWhy"></div>
        <div class="rbc-note emergency"><i class="fas fa-exclamation-circle"></i><span>This is an Emergency Rebook request and still requires Admin/Staff approval.</span></div>
        <p class="rbc-sub">Your payment remains unchanged. Until it is approved, your current schedule stays.</p>
        <div class="rbc-actions">
            <button type="button" class="rbc-btn ghost" id="rbcCancel">Cancel</button>
            <button type="button" class="rbc-btn primary" id="rbcOk">Submit Emergency Request</button>
        </div>
        <?php else: ?>
        <div class="rbc-head"><h3 id="rbcTitle"><i class="fas fa-redo"></i> Submit rebook request?</h3></div>
        <div class="rbc-body" id="rbcRows"></div>
        <div class="rbc-note"><i class="fas fa-wallet"></i><span>Your payment remains unchanged.</span></div>
        <p class="rbc-sub">Admin/Staff confirm the request. Until then the current schedule stays.</p>
        <div class="rbc-actions">
            <button type="button" class="rbc-btn ghost" id="rbcCancel">Cancel</button>
            <button type="button" class="rbc-btn primary" id="rbcOk">Submit Rebook Request</button>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
window.RebookConfirm = (function () {
    var modal = document.getElementById('rbcModal'), rowsEl = document.getElementById('rbcRows'),
        ok = document.getElementById('rbcOk'), cancel = document.getElementById('rbcCancel');
    var pending = null, confirmed = false, opener = null;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function close() { modal.classList.remove('show'); document.body.style.overflow = ''; pending = null; ok.disabled = false; if (opener && opener.focus) opener.focus(); }
    var whyEl = document.getElementById('rbcWhy');
    function open(form, rows, why) {
        pending = form; opener = document.activeElement;
        if (whyEl) whyEl.innerHTML = why ? '<b>Reason</b>' + esc(why) : '';
        rowsEl.innerHTML = rows.map(function (r) {
            return '<div class="rbc-row"><div class="rbc-label">' + esc(r.label) + '</div>' +
                '<div class="rbc-line"><small>Current</small><span>' + esc(r.from) + '</span></div>' +
                '<div class="rbc-line new"><small>New</small><span>' + esc(r.to) + '</span></div></div>';
        }).join('');
        modal.classList.add('show'); document.body.style.overflow = 'hidden'; ok.disabled = false; ok.focus();
    }
    cancel.addEventListener('click', close);                                   // Cancel never submits
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('show')) close(); });
    ok.addEventListener('click', function () {
        if (!pending) return;
        var form = pending; ok.disabled = true; confirmed = true;
        modal.classList.remove('show'); document.body.style.overflow = ''; pending = null;
        // re-run the page's own submit path (same handler, same POST); falls back to a plain submit on old browsers
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
    return {
        gate: function (e, rows, why) {
            if (confirmed) { confirmed = false; return true; }
            e.preventDefault();
            open(e.target, rows || [], why);
            return false;
        }
    };
})();
</script>

<?php
/**
 * includes/rebook-confirm.php
 *
 * Confirmation step shown before a guest's rebook request is sent (house / tour / food / package).
 * UX ONLY: it never validates, prices or changes anything. The form's own POST (same URL, same fields, same CSRF
 * token) is submitted unchanged once the guest presses "Confirm Rebook"; all rules stay in RebookService and the
 * rebook pages' server-side code.
 *
 * Include once, inside <body>, before the page's own <script>. A page calls, from its form's submit handler:
 *     if (!RebookConfirm.gate(event, [{label:'Check-in', from:'Oct 14, 2026 · 2:00 PM', to:'Oct 20, 2026 · 2:00 PM'}, ...])) return;
 * gate() returns false (and opens the modal) on the first submit, and true when the submit is the confirmed one.
 */
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
.rbc-actions{display:flex;gap:10px;padding:14px 20px 18px}
.rbc-btn{flex:1;min-height:46px;border:0;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer}
.rbc-btn.ghost{background:#e2e8f0;color:#475569}
.rbc-btn.primary{background:#4DA6D9;color:#fff}
.rbc-btn:disabled{opacity:.6;cursor:not-allowed}
@media (max-width:480px){.rbc-overlay{align-items:flex-end;padding:0}.rbc-box{max-width:none;border-radius:16px 16px 0 0;max-height:92vh}}
</style>
<div class="rbc-overlay" id="rbcModal" role="dialog" aria-modal="true" aria-labelledby="rbcTitle">
    <div class="rbc-box">
        <div class="rbc-head"><h3 id="rbcTitle"><i class="fas fa-redo"></i> Confirm your rebook</h3></div>
        <div class="rbc-body" id="rbcRows"></div>
        <div class="rbc-note"><i class="fas fa-wallet"></i><span>Your payment remains unchanged.</span></div>
        <div class="rbc-actions">
            <button type="button" class="rbc-btn ghost" id="rbcCancel">Cancel</button>
            <button type="button" class="rbc-btn primary" id="rbcOk">Confirm Rebook</button>
        </div>
    </div>
</div>
<script>
window.RebookConfirm = (function () {
    var modal = document.getElementById('rbcModal'), rowsEl = document.getElementById('rbcRows'),
        ok = document.getElementById('rbcOk'), cancel = document.getElementById('rbcCancel');
    var pending = null, confirmed = false, opener = null;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function close() { modal.classList.remove('show'); document.body.style.overflow = ''; pending = null; ok.disabled = false; if (opener && opener.focus) opener.focus(); }
    function open(form, rows) {
        pending = form; opener = document.activeElement;
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
        gate: function (e, rows) {
            if (confirmed) { confirmed = false; return true; }
            e.preventDefault();
            open(e.target, rows || []);
            return false;
        }
    };
})();
</script>

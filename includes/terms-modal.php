<?php
/**
 * includes/terms-modal.php
 *
 * Terms & Conditions / Privacy Policy acceptance modal for the guest homepage.
 * Same markup, wording and behaviour as the modal in profile.php; it posts the
 * existing `accept_terms` form and the acceptance is stored by TermsGate
 * (table user_terms_acceptance) — there is no separate Terms system.
 *
 * Expects from the including page:
 *   $termsContent       (TermsGate::getContent())
 *   $force_must_accept  (true = guest has not accepted the current version)
 * Also provides reopenTermsModal() for the footer Terms / Privacy links.
 */
if (!isset($termsContent) || !is_array($termsContent)) return;
$force_must_accept = !empty($force_must_accept);
?>
<style>
#termsModal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); z-index: 30000; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; -webkit-overflow-scrolling: touch; }
#termsModal.show { display: flex; }
#termsModal .terms-modal-content { background: white; border-radius: 24px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); font-family: inherit; text-align: left; }
@media (max-width: 480px) { #termsModal { padding: 10px; } }
        .terms-modal { z-index: 30000 !important; }
        .terms-modal-content { max-width: 720px !important; padding: 0 !important; overflow: hidden !important; display: flex !important; flex-direction: column; max-height: 92vh !important; }
        .terms-modal-header { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); color: white; padding: 25px 30px; text-align: center; position: relative; overflow: hidden; flex-shrink: 0; }
        .terms-modal-icon { font-size: 42px; margin-bottom: 8px; position: relative; z-index: 1; color: #F4B400; }
        .terms-modal-header h3 { font-size: 22px; font-weight: 700; margin: 0 0 4px 0; color: white; position: relative; z-index: 1; }
        .terms-modal-header p { font-size: 13px; opacity: 0.9; margin: 0; color: #e0eeff; position: relative; z-index: 1; }
        .terms-modal-close { position: absolute; top: 14px; right: 14px; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.25); width: 38px; height: 38px; border-radius: 50%; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all 0.25s ease; z-index: 5; }
        .terms-modal-close:hover { background: #ef4444; border-color: #ef4444; transform: rotate(90deg); }
        .terms-scroll-container { flex: 1; overflow-y: auto; padding: 25px 30px; background: white; min-height: 0; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
        .terms-section { margin-bottom: 30px; }
        .terms-section:last-child { margin-bottom: 0; }
        .terms-section-title { display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 700; color: #0B2447; padding-bottom: 12px; border-bottom: 2px solid #4DA6D9; margin-bottom: 15px; }
        .terms-section-title i { color: #4DA6D9; font-size: 20px; flex-shrink: 0; }
        .terms-section-body { font-size: 13.5px; line-height: 1.7; color: #334155; white-space: pre-wrap; word-wrap: break-word; }
        .terms-divider { text-align: center; margin: 25px 0; position: relative; }
        .terms-divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, #cbd5e1, transparent); }
        .terms-divider span { position: relative; background: white; padding: 0 15px; color: #94a3b8; font-size: 12px; font-weight: 600; letter-spacing: 1px; }
        .terms-scroll-hint { text-align: center; padding: 12px; background: #fef3c7; color: #92400e; font-size: 12.5px; font-weight: 600; border-top: 1px solid #fde68a; flex-shrink: 0; transition: all 0.3s; }
        .terms-scroll-hint.done { background: #d1fae5; color: #065f46; border-top-color: #a7f3d0; }
        #termsAcceptForm { padding: 15px 25px 20px; border-top: 2px solid #e2e8f0; background: #f8fafc; flex-shrink: 0; display: none; animation: slideUp 0.3s ease; }
        #termsAcceptForm.visible { display: block; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .terms-checkbox-label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; padding: 12px 14px; background: white; border: 2px solid #e2e8f0; border-radius: 10px; margin-bottom: 12px; transition: all 0.2s; font-weight: 500; font-size: 13.5px; color: #1e293b; line-height: 1.5; }
        .terms-checkbox-label:hover { border-color: #4DA6D9; background: #f0f7fb; }
        .terms-checkbox-label input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: #4DA6D9; margin-top: 1px; flex-shrink: 0; }
        .terms-checkbox-text { flex: 1; }
        .terms-accept-btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
        .terms-accept-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45); }
        .terms-accept-btn:disabled { background: #cbd5e1; cursor: not-allowed; box-shadow: none; transform: none; color: #94a3b8; }
        .terms-modal-footer-note { text-align: center; padding: 10px 20px 15px; font-size: 11.5px; color: #94a3b8; background: #f8fafc; margin: 0; flex-shrink: 0; }

        @media (max-width: 600px) {
            .terms-modal-content { max-width: 96% !important; max-height: 94vh !important; }
            .terms-modal-header { padding: 20px 18px; }
            .terms-modal-header h3 { font-size: 17px; }
            .terms-modal-icon { font-size: 32px; }
            .terms-modal-close { width: 32px; height: 32px; font-size: 14px; top: 10px; right: 10px; }
            .terms-scroll-container { padding: 18px 18px; }
            .terms-section-title { font-size: 15px; }
            .terms-section-body { font-size: 12.5px; }
            .terms-scroll-hint { font-size: 11px; padding: 10px; }
            #termsAcceptForm { padding: 12px 16px 14px; }
            .terms-checkbox-label { font-size: 12px; padding: 10px 12px; }
            .terms-accept-btn { font-size: 13px; padding: 12px; }
        }

        @media (max-width: 480px) {
            .terms-modal-header { padding: 16px 14px; }
            .terms-modal-header h3 { font-size: 15px; }
            .terms-modal-icon { font-size: 28px; }
            .terms-modal-close { width: 30px; height: 30px; font-size: 12px; top: 8px; right: 8px; }
            .terms-scroll-container { padding: 15px 15px; }
            .terms-section-title { font-size: 14px; }
            .terms-section-body { font-size: 12px; line-height: 1.6; }
            .terms-scroll-hint { font-size: 10.5px; padding: 8px 10px; }
            #termsAcceptForm { padding: 10px 14px 12px; }
            .terms-checkbox-label { font-size: 11.5px; padding: 9px 11px; gap: 8px; }
            .terms-checkbox-label input[type="checkbox"] { width: 18px; height: 18px; }
            .terms-accept-btn { font-size: 12.5px; padding: 11px; }
            .terms-modal-footer-note { font-size: 10.5px; padding: 8px 14px 12px; }
        }
</style>

<!-- TERMS MODAL -->
<div class="terms-modal" id="termsModal"
     data-must-accept="<?php echo $force_must_accept ? '1' : '0'; ?>">
    <div class="terms-modal-content">

        <div class="terms-modal-header">
            <div class="terms-modal-icon">
                <i class="fas fa-file-contract"></i>
            </div>
            <h3 id="termsModalTitle">Before You Continue</h3>
            <p id="termsModalSubtitle">Please review our Terms &amp; Privacy Policy</p>

            <button type="button"
                    class="terms-modal-close"
                    id="termsModalCloseBtn"
                    onclick="closeTermsReadOnly()"
                    aria-label="Close"
                    style="display: none;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="terms-scroll-container" id="termsScrollContainer">
            <div class="terms-section" id="termsSection">
                <div class="terms-section-title">
                    <i class="fas fa-scroll"></i>
                    <span id="termsTitleText"><?php echo htmlspecialchars($termsContent['terms_title']); ?></span>
                </div>
                <div class="terms-section-body" id="termsBody">
                    <?php
                    $termsBody = $termsContent['terms_body'];
                    if (preg_match('/<[^>]+>/', $termsBody)) {
                        echo $termsBody;
                    } else {
                        echo nl2br(htmlspecialchars($termsBody));
                    }
                    ?>
                </div>
            </div>

            <div class="terms-divider">
                <span>END OF TERMS &amp; CONDITIONS</span>
            </div>

            <div class="terms-section" id="privacySection">
                <div class="terms-section-title">
                    <i class="fas fa-shield-alt"></i>
                    <span id="privacyTitleText"><?php echo htmlspecialchars($termsContent['privacy_title']); ?></span>
                </div>
                <div class="terms-section-body" id="privacyBody">
                    <?php
                    $privacyBody = $termsContent['privacy_body'];
                    if (preg_match('/<[^>]+>/', $privacyBody)) {
                        echo $privacyBody;
                    } else {
                        echo nl2br(htmlspecialchars($privacyBody));
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="terms-scroll-hint" id="termsScrollHint">
            <i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox
        </div>

        <form method="POST" id="termsAcceptForm">
            <label class="terms-checkbox-label" for="terms_agree">
                <input type="checkbox" name="agree" id="terms_agree" value="1" disabled>
                <span class="terms-checkbox-text">
                    <i class="fas fa-check-circle" style="color: #10b981;"></i>
                    I understand and accept the <strong>Terms &amp; Conditions</strong> and <strong>Privacy Policy</strong>.
                </span>
            </label>
            <button type="submit" name="accept_terms" class="terms-accept-btn" id="termsAcceptBtn" disabled>
                <i class="fas fa-check"></i> Accept &amp; Continue
            </button>
        </form>

        <p class="terms-modal-footer-note" id="termsModalFooterNote">
            You can review these documents anytime from the footer links.
        </p>
    </div>
</div>

<script>
// ============================================================
// TERMS MODAL LOGIC
// ============================================================
var __termsState = { modal: null, mustAccept: false, hasReachedBottom: false };

(function () {
    var modal = document.getElementById('termsModal');
    if (!modal) return;

    __termsState.modal = modal;
    __termsState.mustAccept = modal.dataset.mustAccept === '1';

    var closeBtn   = document.getElementById('termsModalCloseBtn');
    var acceptForm = document.getElementById('termsAcceptForm');
    var scrollHint = document.getElementById('termsScrollHint');
    var scrollBox  = document.getElementById('termsScrollContainer');
    var checkbox   = document.getElementById('terms_agree');
    var acceptBtn  = document.getElementById('termsAcceptBtn');
    var titleEl    = document.getElementById('termsModalTitle');
    var subtitleEl = document.getElementById('termsModalSubtitle');

    function unlockAcceptForm() {
        if (!__termsState.mustAccept) return;
        if (__termsState.hasReachedBottom) return;
        __termsState.hasReachedBottom = true;
        acceptForm.classList.add('visible');
        checkbox.disabled = false;
        scrollHint.classList.add('done');
        scrollHint.innerHTML = '<i class="fas fa-check-circle"></i> You\'ve read everything. Please check the box below.';
        setTimeout(function() {
            scrollBox.scrollTo({ top: scrollBox.scrollHeight, behavior: 'smooth' });
        }, 100);
    }

    function wireScrollListener() {
        if (!scrollBox) return;
        if (scrollBox.dataset.scrollWired === '1') return;
        scrollBox.dataset.scrollWired = '1';
        var needsScroll = scrollBox.scrollHeight > scrollBox.clientHeight + 5;
        if (!needsScroll) unlockAcceptForm();
        scrollBox.addEventListener('scroll', function () {
            var atBottom = (scrollBox.scrollTop + scrollBox.clientHeight) >= (scrollBox.scrollHeight - 15);
            if (atBottom) unlockAcceptForm();
        });
    }

    window.openTermsMustAccept = function () {
        __termsState.mustAccept = true;
        __termsState.hasReachedBottom = false;
        modal.dataset.mustAccept = '1';
        titleEl.textContent = 'Before You Continue';
        subtitleEl.textContent = 'Please review our Terms & Privacy Policy';
        closeBtn.style.display = 'none';
        scrollHint.style.display = 'block';
        scrollHint.classList.remove('done');
        scrollHint.innerHTML = '<i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox';
        acceptForm.classList.remove('visible');
        checkbox.disabled = true;
        checkbox.checked = false;
        acceptBtn.disabled = true;
        scrollBox.scrollTop = 0;
        wireScrollListener();
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    };

    window.openTermsReadOnly = function () {
        __termsState.mustAccept = false;
        modal.dataset.mustAccept = '0';
        titleEl.textContent = 'Terms & Privacy Policy';
        subtitleEl.textContent = 'Review our policies at any time';
        closeBtn.style.display = 'flex';
        scrollHint.style.display = 'none';
        acceptForm.classList.remove('visible');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    };

    window.closeTermsReadOnly = function () {
        if (__termsState.mustAccept) return;
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    };

    modal.addEventListener('click', function (e) {
        if (e.target === modal && __termsState.mustAccept) e.stopPropagation();
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('show') && __termsState.mustAccept) {
            e.stopPropagation(); e.preventDefault();
        }
    }, true);

    checkbox.addEventListener('change', function () {
        acceptBtn.disabled = !checkbox.checked;
    });

    if (__termsState.mustAccept) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        wireScrollListener();
    } else {
        wireScrollListener();
    }
})();

function reopenTermsModal(tabName) {
    var modal = document.getElementById('termsModal');
    if (!modal) return false;
    var mustAccept = modal.dataset.mustAccept === '1';
    if (mustAccept) {
        if (typeof window.openTermsMustAccept === 'function') window.openTermsMustAccept();
    } else {
        if (typeof window.openTermsReadOnly === 'function') window.openTermsReadOnly();
    }
    return false;
}
</script>

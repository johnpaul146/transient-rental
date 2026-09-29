<?php
/**
 * includes/logout-modal.php
 * 
 * Reusable logout confirmation modal.
 * Include this just before </body> on any page with a logout link.
 * 
 * Usage in a page:
 *   <a href="#" onclick="openLogoutModal(event); return false;">Logout</a>
 *   ...
 *   <?php include 'includes/logout-modal.php'; ?>
 */
if (!defined('LOGOUT_MODAL_LOADED')):
    define('LOGOUT_MODAL_LOADED', true);
?>
<!-- ============================================================
     LOGOUT CONFIRMATION MODAL
     ============================================================ -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="logout.php" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<style>
/* ============================================================
   LOGOUT CONFIRMATION MODAL — Styles
   ============================================================ */
.logout-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(11, 36, 71, 0.6);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: logoutFadeIn 0.2s ease;
    overscroll-behavior: contain;
}

.logout-modal-overlay.show {
    display: flex;
}

@keyframes logoutFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.logout-modal {
    background: white;
    border-radius: 24px;
    max-width: 400px;
    width: 100%;
    padding: 35px 30px 25px;
    text-align: center;
    box-shadow: 0 30px 80px rgba(0,0,0,0.4);
    animation: logoutSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    border-top: 6px solid #ef4444;
    max-height: 90vh;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

@keyframes logoutSlideIn {
    from { opacity: 0; transform: translateY(-30px) scale(0.9); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.logout-modal-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    font-size: 36px;
    color: #ef4444;
    animation: logoutPulse 2s ease-in-out infinite;
}

@keyframes logoutPulse {
    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); }
    50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); }
}

.logout-modal h3 {
    font-size: 22px;
    font-weight: 700;
    color: #991b1b;
    margin-bottom: 8px;
}

.logout-modal p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 25px;
}

.logout-modal-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-logout-cancel,
.btn-logout-confirm {
    flex: 1;
    min-width: 130px;
    min-height: 48px;
    padding: 13px 18px;
    border: none;
    border-radius: 12px;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.25s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
    -webkit-tap-highlight-color: rgba(0,0,0,0.1);
    touch-action: manipulation;
}

.btn-logout-cancel {
    background: #e2e8f0;
    color: #475569;
}

.btn-logout-cancel:hover,
.btn-logout-cancel:active {
    background: #cbd5e1;
    transform: translateY(-2px);
}

.btn-logout-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

.btn-logout-confirm:hover,
.btn-logout-confirm:active {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45);
    color: white;
}

@media (max-width: 480px) {
    .logout-modal {
        padding: 28px 22px 20px;
        border-radius: 20px;
    }

    .logout-modal-icon {
        width: 65px;
        height: 65px;
        font-size: 28px;
        margin-bottom: 14px;
    }

    .logout-modal h3 {
        font-size: 19px;
    }

    .logout-modal p {
        font-size: 13px;
        margin-bottom: 20px;
    }

    .logout-modal-actions {
        flex-direction: column-reverse;
    }

    .btn-logout-cancel,
    .btn-logout-confirm {
        width: 100%;
    }
}
</style>

<script>
/* ============================================================
   LOGOUT CONFIRMATION — Logic
   ============================================================ */
(function() {
    if (window.__logoutModalWired) return;
    window.__logoutModalWired = true;

    window.openLogoutModal = function(event) {
        if (event) event.preventDefault();

        // Close mobile sidebar if open
        var sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('open')) {
            var overlay = document.getElementById('sidebarOverlay');
            var toggleBtn = document.getElementById('menuToggle');
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
            if (toggleBtn) toggleBtn.classList.remove('active');
        }

        document.getElementById('logoutModal').classList.add('show');
        document.body.style.overflow = 'hidden';

        // Focus Cancel (safer default)
        setTimeout(function() {
            var cancelBtn = document.querySelector('.btn-logout-cancel');
            if (cancelBtn) cancelBtn.focus();
        }, 100);
    };

    window.closeLogoutModal = function() {
        document.getElementById('logoutModal').classList.remove('show');
        document.body.style.overflow = 'auto';
    };

    // Escape closes
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var modal = document.getElementById('logoutModal');
            if (modal && modal.classList.contains('show')) {
                window.closeLogoutModal();
            }
        }
    });

    // Backdrop click closes
    document.addEventListener('DOMContentLoaded', function() {
        var modal = document.getElementById('logoutModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    window.closeLogoutModal();
                }
            });
        }
    });
})();
</script>
<?php endif; ?>
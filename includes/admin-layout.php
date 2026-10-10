<?php
/**
 * ADMIN / STAFF SHARED LAYOUT  (Phase 8.5a)
 *
 * One source for the pieces every admin/staff page used to copy-paste:
 *   - sidebar overlay, mobile hamburger, sidebar (logo, role badge, navigation, badges)
 *   - logout confirmation modal
 *   - sidebar + logout JavaScript  (toggleSidebar, openLogoutModal, closeLogoutModal)
 *
 * USAGE (in a page, after the page has done its own auth gate and set $is_admin):
 *
 *     require_once 'includes/sidebar-counts.php';     // badge counts, BEFORE the sidebar
 *     require_once 'includes/admin-layout.php';
 *     ...
 *     <div class="app-container">
 *         <?php admin_layout_sidebar(['active' => 'tour']); ?>
 *         <div class="main-content"> ... </div>
 *     </div>
 *     ...
 *     <?php admin_layout_footer(); ?>                 // logout modal + shared JS, before page scripts
 *
 * RULES
 *   - This file does NOT authenticate and does NOT change permissions. The page keeps its own
 *     requireAdmin()/requireAdminOrStaff() gate and defines $is_admin. Admin-only menu items are
 *     shown only when the page's $is_admin is truthy; a missing/empty $is_admin means STAFF view
 *     (least privilege). Hiding a link never replaces the target page's own gate.
 *   - Badge numbers come from includes/sidebar-counts.php ($sidebar_* globals). Missing = no badge.
 *   - CSS is still supplied by the page (shared stylesheet extraction is a later phase).
 *     Element IDs / classes are unchanged: #sidebar #sidebarOverlay #menuToggle #logoutModal
 *     .sidebar .nav-link .nav-badge .role-badge .logout-modal-overlay ...
 *
 * OPTIONS for admin_layout_sidebar():
 *   active       key of the current page (see admin_layout_nav())  default ''
 *   logout_href  where "Yes, Logout" points                          default 'logout.php'
 *   is_admin     override of the page's $is_admin (normally omit)
 */

if (!function_exists('admin_layout_nav')) {

    /** Navigation definition, in display order. 'admin' = admin-only; 'badge' = $sidebar_* variable name. */
    function admin_layout_nav(): array
    {
        return [
            ['key' => 'dashboard',  'href' => 'admin-dashboard.php',      'icon' => 'th-large',       'label' => 'Dashboard'],
            ['key' => 'users',      'href' => 'user-management.php',      'icon' => 'users',          'label' => 'User Management',      'admin' => true],
            ['key' => 'house',      'href' => 'house-dashboard.php',      'icon' => 'home',           'label' => 'House Management'],
            ['key' => 'tour',       'href' => 'tour-dashboard.php',       'icon' => 'umbrella-beach', 'label' => 'Tour Management'],
            ['key' => 'activities', 'href' => 'activities-dashboard.php', 'icon' => 'water',          'label' => 'Activities Management'],
            ['key' => 'food',       'href' => 'food-dashboard.php',       'icon' => 'utensils',       'label' => 'Food Management'],
            ['key' => 'bookings',   'href' => 'booking-management.php',   'icon' => 'calendar-check', 'label' => 'Booking Management',
                'badge' => 'sidebar_pending_bookings', 'badge_style' => 'background: rgba(245,158,11,0.2); color:#f59e0b;'],
            ['key' => 'blocked',    'href' => 'blocked-dates.php',        'icon' => 'ban',            'label' => 'Blocked Dates'],
            ['key' => 'reviews',    'href' => 'reviews-management.php',   'icon' => 'star',           'label' => 'Reviews Management',
                'badge' => 'sidebar_pending_reviews', 'badge_style' => 'background: rgba(16,185,129,0.2); color:#10b981;'],
            ['key' => 'reports',    'href' => 'reports.php',              'icon' => 'file-alt',       'label' => 'Sales Report',         'admin' => true],
            ['key' => 'content',    'href' => 'edit-content.php',         'icon' => 'edit',           'label' => 'Edit Content',         'admin' => true],
            ['key' => 'logs',       'href' => 'system-logs.php',          'icon' => 'history',        'label' => 'System Logs',          'admin' => true,
                'badge' => 'sidebar_failed_logs'],
            ['divider' => true],
            ['key' => 'profile',    'href' => 'admin-profile.php',        'icon' => 'user-circle',    'label' => 'My Profile'],
        ];
    }

    /** Remembers the options of the sidebar call so the footer (logout modal) stays consistent. */
    function admin_layout_config(?array $set = null): array
    {
        static $cfg = ['active' => '', 'logout_href' => 'logout.php'];
        if ($set !== null) $cfg = array_merge($cfg, $set);
        return $cfg;
    }

    /** Page's $is_admin, defaulting to false (least privilege). */
    function admin_layout_is_admin(array $opts = []): bool
    {
        if (array_key_exists('is_admin', $opts)) return !empty($opts['is_admin']);
        return !empty($GLOBALS['is_admin']);
    }

    /** [logo path, logo exists?, site name] — reuses the page's $content / $nav_logo when present, else one query. */
    function admin_layout_brand(): array
    {
        $content = $GLOBALS['content'] ?? null;
        if (!is_array($content)) {
            $content = [];
            $pdo = $GLOBALS['pdo'] ?? null;
            if ($pdo instanceof PDO) {
                try {
                    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content WHERE section_name = 'site_settings'");
                    while ($row = $stmt->fetch()) $content[$row['section_name']][$row['content_key']] = $row['content_value'];
                } catch (Throwable $e) {}
            }
        }
        $logo = 'uploads/logos/logo.png';
        if (!empty($content['site_settings']['logo_path'])) $logo = $content['site_settings']['logo_path'];
        $exists = !empty($logo) && file_exists($logo) && !is_dir($logo);
        $name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';
        return [$logo, $exists, $name];
    }

    /** Overlay + hamburger + sidebar. Place inside the page's .app-container, before .main-content. */
    function admin_layout_sidebar(array $opts = []): void
    {
        $cfg      = admin_layout_config(array_intersect_key($opts, ['active' => 1, 'logout_href' => 1]));
        $is_admin = admin_layout_is_admin($opts);
        [$logo, $logo_exists, $site_name] = admin_layout_brand();
        $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        ?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-header-top">
                <a href="admin-dashboard.php" class="logo">
                    <div class="logo-icon">
                        <?php if ($logo_exists): ?>
                            <img src="<?php echo $e($logo); ?>?<?php echo time(); ?>" alt="<?php echo $e($site_name); ?>">
                        <?php else: ?>
                            <i class="fas fa-umbrella-beach"></i>
                        <?php endif; ?>
                    </div>
                    <div class="logo-text">
                        <span class="main">Hundred Islands</span>
                        <span class="sub">Reservation System</span>
                    </div>
                </a>
                <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Close menu">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="role-badge <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                <?php echo $is_admin ? 'Administrator' : 'Staff'; ?>
            </div>
        </div>

        <ul class="nav-menu">
<?php foreach (admin_layout_nav() as $item): ?>
<?php   if (!empty($item['divider'])): ?>
            <li class="nav-divider" role="separator"></li>
<?php       continue; endif; ?>
<?php   if (!empty($item['admin']) && !$is_admin) continue; ?>
<?php   $count = isset($item['badge']) ? (int)($GLOBALS[$item['badge']] ?? 0) : 0; ?>
            <li class="nav-item">
                <a href="<?php echo $e($item['href']); ?>" class="nav-link<?php echo $cfg['active'] === $item['key'] ? ' active' : ''; ?>">
                    <i class="fas fa-<?php echo $e($item['icon']); ?>"></i><span><?php echo $e($item['label']); ?></span>
<?php   if ($count > 0): ?>
                    <span class="nav-badge"<?php echo isset($item['badge_style']) ? ' style="' . $e($item['badge_style']) . '"' : ''; ?>><?php echo $count; ?></span>
<?php   endif; ?>
                </a>
            </li>
<?php endforeach; ?>
            <li class="nav-item"><a href="#" class="nav-link" onclick="openLogoutModal(event); return false;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </div>
<?php
    }

    /** Logout modal + shared sidebar/logout JavaScript. Output once, near the end of <body>, before page scripts. */
    function admin_layout_footer(array $opts = []): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $cfg = admin_layout_config(array_intersect_key($opts, ['logout_href' => 1]));
        ?>
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon"><i class="fas fa-sign-out-alt"></i></div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="<?php echo htmlspecialchars($cfg['logout_href'], ENT_QUOTES, 'UTF-8'); ?>" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script>
// ---- Shared admin layout (includes/admin-layout.php) ----
function closeSidebarState() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggleBtn = document.getElementById('menuToggle');
    if (sidebar) sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('active');
    if (toggleBtn) toggleBtn.classList.remove('active');
    document.body.classList.remove('sidebar-open-mobile');
}

function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggleBtn = document.getElementById('menuToggle');
    if (!sidebar) return;
    var willOpen = !sidebar.classList.contains('open');

    sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('active');
    if (toggleBtn) toggleBtn.classList.toggle('active');

    if (willOpen && window.innerWidth <= 1024) {
        document.body.classList.add('sidebar-open-mobile');
    } else {
        document.body.classList.remove('sidebar-open-mobile');
    }
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : 'auto';
}

function openLogoutModal(event) {
    if (event) event.preventDefault();
    var sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) closeSidebarState();
    var modal = document.getElementById('logoutModal');
    if (!modal) return;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    setTimeout(function () {
        var cancelBtn = modal.querySelector('.btn-logout-cancel');
        if (cancelBtn) cancelBtn.focus();
    }, 100);
}

function closeLogoutModal() {
    var modal = document.getElementById('logoutModal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) toggleSidebar();
    var lm = document.getElementById('logoutModal');
    if (lm && lm.classList.contains('show')) closeLogoutModal();
});

window.addEventListener('resize', function () {
    var sidebar = document.getElementById('sidebar');
    if (sidebar && window.innerWidth > 1024 && sidebar.classList.contains('open')) {
        closeSidebarState();
        document.body.style.overflow = 'auto';
    }
});

(function () {
    var modal = document.getElementById('logoutModal');
    if (modal) modal.addEventListener('click', function (e) { if (e.target === this) closeLogoutModal(); });
})();
</script>
<?php
    }
}

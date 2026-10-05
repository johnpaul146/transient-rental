<?php
/**
 * components/navbar.php
 * Floating white navbar + mobile drawer.
 *
 * Requires (from parent scope):
 *   $content, $logo_path/$sidebar_logo, $user_has_feedback,
 *   $is_logged_in, $_SESSION['user_id'], $_SESSION['role']
 *
 * Optional:
 *   $nav_active = 'home' | 'houses' | 'tours' | 'activities' | 'food' | 'packages'
 *   $nav_variant = 'floating' (default) | 'solid'
 */

if (!isset($nav_variant)) {
    $nav_variant = 'floating';
}

$currentPage = basename($_SERVER['PHP_SELF']);

$pageActiveMap = [
    'index.php'      => 'home',
    'houses.php'     => 'stay',
    'tours.php'      => 'tours',
    'activities.php' => 'activities',
    'food.php'       => 'food',
    'packages.php'   => 'packages'
];

if (!isset($nav_active)) {
    $nav_active = $pageActiveMap[$currentPage] ?? 'home';
}

$isLoggedIn   = isset($_SESSION['user_id']);
$userRole     = $_SESSION['role'] ?? 'guest';
$isAdminStaff = in_array($userRole, ['admin', 'staff'], true);
$hasFeedback  = isset($user_has_feedback) ? $user_has_feedback : false;

/* Logo resolution — prefer $sidebar_logo if set, else $logo_path */
$navLogoPath = '';
if (!empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo)) {
    $navLogoPath = $sidebar_logo;
} elseif (!empty($logo_path) && file_exists($logo_path) && !is_dir($logo_path)) {
    $navLogoPath = $logo_path;
} elseif (file_exists('uploads/logos/logo.png')) {
    $navLogoPath = 'uploads/logos/logo.png';
}
$navLogoExists = !empty($navLogoPath);

/* Nav items */
$navItems = [
    'home'       => ['label' => 'Home',       'icon' => 'fa-home',          'href' => 'index.php'],
    'stay'       => ['label' => 'Stay',       'icon' => 'fa-bed',           'href' => 'houses.php'],
    'tours'      => ['label' => 'Tours',      'icon' => 'fa-umbrella-beach','href' => 'tours.php'],
    'activities' => ['label' => 'Activities', 'icon' => 'fa-water',         'href' => 'activities.php'],
    'food'       => ['label' => 'Food',       'icon' => 'fa-utensils',      'href' => 'food.php'],
    'packages'   => ['label' => 'Packages',   'icon' => 'fa-box-open',      'href' => 'packages.php'],
];
?>
<!-- ============================================================
     MOBILE DRAWER OVERLAY
     ============================================================ -->
<div class="nav-overlay" id="navOverlay" aria-hidden="true"></div>

<!-- ============================================================
     MOBILE DRAWER
     ============================================================ -->
<aside class="nav-drawer" id="navDrawer" aria-label="Mobile navigation">
    <div class="nav-drawer__head">
        <a href="index.php" class="nav-drawer__brand">
            <?php if ($navLogoExists): ?>
                <img src="<?php echo htmlspecialchars($navLogoPath); ?>?v=<?php echo time(); ?>" alt="Logo">
            <?php else: ?>
                <span class="nav-drawer__brand-fallback"><i class="fas fa-umbrella-beach"></i></span>
            <?php endif; ?>
            <span class="nav-drawer__brand-text">
                <span class="nav-drawer__brand-name">Transient House</span>
                <span class="nav-drawer__brand-sub">&amp; Tours</span>
            </span>
        </a>
        <button type="button" class="nav-drawer__close" id="navDrawerClose" aria-label="Close menu">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <nav class="nav-drawer__menu">
        <?php foreach ($navItems as $key => $item): ?>
            <a href="<?php echo htmlspecialchars($item['href']); ?>"
               class="nav-drawer__link <?php echo $nav_active === $key ? 'is-active' : ''; ?>">
                <i class="fas <?php echo $item['icon']; ?>"></i>
                <span><?php echo htmlspecialchars($item['label']); ?></span>
            </a>
        <?php endforeach; ?>

        <?php if ($isLoggedIn): ?>
            <?php if ($isAdminStaff): ?>
                <a href="admin-dashboard.php" class="nav-drawer__link">
                    <i class="fas fa-cog"></i><span>Dashboard</span>
                </a>
            <?php else: ?>
                <a href="profile.php" class="nav-drawer__link">
                    <i class="fas fa-user"></i><span>My Profile</span>
                </a>
                <a href="#" onclick="openOverallFeedbackModal(event); return false;"
                   class="nav-drawer__link nav-drawer__link--gold">
                    <i class="fas fa-star"></i>
                    <span><?php echo $hasFeedback ? 'Edit Review' : 'Rate Us'; ?></span>
                </a>
            <?php endif; ?>
            <a href="#" onclick="handleGuestLogout(event); return false;"
               class="nav-drawer__link nav-drawer__link--danger">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        <?php else: ?>
            <a href="login.php" class="nav-drawer__link nav-drawer__link--ocean">
                <i class="fas fa-sign-in-alt"></i><span>Login</span>
            </a>
            <a href="register.php" class="nav-drawer__link nav-drawer__link--gold">
                <i class="fas fa-user-plus"></i><span>Create Account</span>
            </a>
        <?php endif; ?>
    </nav>
</aside>

<!-- ============================================================
     FLOATING NAVBAR
     ============================================================ -->
<header class="navbar navbar--<?php echo htmlspecialchars($nav_variant); ?>" id="siteNavbar">
    <div class="navbar__inner">

        <!-- Mobile hamburger -->
        <button type="button" class="navbar__hamburger" id="navToggle"
                aria-label="Open menu" aria-controls="navDrawer" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Brand -->
        <a href="index.php" class="navbar__brand">
            <?php if ($navLogoExists): ?>
                <img src="<?php echo htmlspecialchars($navLogoPath); ?>?v=<?php echo time(); ?>"
                     alt="Logo" class="navbar__brand-img">
            <?php else: ?>
                <span class="navbar__brand-fallback"><i class="fas fa-umbrella-beach"></i></span>
            <?php endif; ?>
            <span class="navbar__brand-text">
                <span class="navbar__brand-name">Transient House &amp; Tours</span>
                <span class="navbar__brand-loc">
                    <i class="fas fa-map-marker-alt"></i> Hundred Islands &bull; Alaminos
                </span>
            </span>
        </a>

        <!-- Desktop menu -->
        <nav class="navbar__menu" aria-label="Primary">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?php echo htmlspecialchars($item['href']); ?>"
                   class="navbar__link <?php echo $nav_active === $key ? 'is-active' : ''; ?>">
                    <?php echo htmlspecialchars($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Desktop actions -->
        <div class="navbar__actions">
            <?php if ($isLoggedIn): ?>
                <?php if ($isAdminStaff): ?>
                    <a href="admin-dashboard.php" class="btn btn-ghost btn-sm">
                        <i class="fas fa-cog"></i> Dashboard
                    </a>
                <?php else: ?>
                    <a href="profile.php" class="navbar__user" title="My Profile">
                        <span class="navbar__user-avatar">
                            <i class="fas fa-user"></i>
                        </span>
                        <span class="navbar__user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Profile'); ?></span>
                    </a>
                <?php endif; ?>
                <a href="#" onclick="handleGuestLogout(event); return false;"
                   class="btn btn-ghost btn-sm" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            <?php else: ?>
                <a href="login.php" class="btn btn-ghost btn-sm">
                    <i class="fas fa-sign-in-alt"></i> Login
                </a>
                <a href="register.php" class="btn btn-ocean btn-sm">
                    <i class="fas fa-user-plus"></i> Create Account
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<script>
(function () {
    if (window.__guestNavInitialized) return;
    window.__guestNavInitialized = true;

    var navbar = document.getElementById('siteNavbar');
    var drawer = document.getElementById('navDrawer');
    var overlay = document.getElementById('navOverlay');
    var toggle = document.getElementById('navToggle');
    var closeBtn = document.getElementById('navDrawerClose');

    function setScrolledState() {
        if (!navbar) return;
        navbar.classList.toggle('is-scrolled', window.scrollY > 20);
    }

    function openDrawer() {
        if (!drawer || !overlay) return;
        drawer.classList.add('is-open');
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('nav-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
        if (closeBtn) setTimeout(function () { closeBtn.focus(); }, 50);
    }

    function closeDrawer() {
        if (!drawer || !overlay) return;
        drawer.classList.remove('is-open');
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('nav-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function toggleDrawer() {
        if (!drawer) return;
        if (drawer.classList.contains('is-open')) closeDrawer();
        else openDrawer();
    }

    window.handleGuestLogout = function (event) {
        if (event) event.preventDefault();
        closeDrawer();
        if (typeof window.openLogoutModal === 'function') {
            window.openLogoutModal(event);
            return;
        }
        window.location.href = 'logout.php?redirect=index.php';
    };

    if (toggle) toggle.addEventListener('click', toggleDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if (overlay) overlay.addEventListener('click', closeDrawer);

    if (drawer) {
        drawer.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeDrawer);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeDrawer();
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 992) closeDrawer();
    });

    setScrolledState();
    window.addEventListener('scroll', setScrolledState, { passive: true });

    window.closeGuestNavDrawer = closeDrawer;
})();
</script>

<?php
/**
 * includes/auth.php
 * 
 * Centralized authentication helpers.
 * Include this at the top of every protected page.
 * 
 * Usage:
 *   require_once 'includes/auth.php';
 *   requireLogin();                    // forces login, redirects to login.php
 *   requireLogin('admin');             // forces admin role
 *   requireLogin('admin', 'staff');    // forces admin OR staff
 *   if (isLoggedIn()) { ... }          // check without redirecting
 *   if (hasRole('admin')) { ... }      // role check
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Is the user currently logged in?
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get the current user's role (or null if not logged in).
 */
function currentRole(): ?string {
    return $_SESSION['role'] ?? null;
}

/**
 * Does the current user have one of the given roles?
 * Usage: hasRole('admin') or hasRole('admin', 'staff')
 */
function hasRole(string ...$roles): bool {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

/**
 * Where should this user go after login?
 * Based on their role.
 */
function dashboardUrlForRole(string $role): string {
    if ($role === 'admin' || $role === 'staff') {
        return 'admin-dashboard.php';
    }
    return 'index.php';
}

/**
 * Force login. If not logged in, redirect to login.php
 * with a "redirect back" hint.
 * 
 * Optionally require one or more roles.
 */
function requireLogin(string ...$roles): void {
    if (!isLoggedIn()) {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
        $redirect = urlencode($currentUrl);
        header("Location: login.php?redirect={$redirect}");
        exit();
    }

    if (!empty($roles) && !hasRole(...$roles)) {
        // Logged in but wrong role — send them to their own dashboard
        header("Location: " . dashboardUrlForRole(currentRole()));
        exit();
    }
}

/**
 * Redirect a logged-in user away from a page they shouldn't see
 * (e.g. logged-in user visiting login.php).
 */
function redirectIfLoggedIn(): void {
    if (isLoggedIn()) {
        header("Location: " . dashboardUrlForRole(currentRole()));
        exit();
    }
}
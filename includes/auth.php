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

/**
 * Send a 403 "Access denied" page and stop. Used when a logged-in user
 * has the wrong role for a page (e.g. staff opening an admin-only URL).
 */
function denyAccess(string $message = 'You do not have permission to access this page.'): void {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $home = htmlspecialchars(dashboardUrlForRole(currentRole() ?? 'guest'), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Access denied</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#f1f5f9;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;padding:16px}'
       . '.c{max-width:420px;width:100%;background:#fff;border-radius:16px;padding:32px 24px;text-align:center;'
       . 'box-shadow:0 10px 30px rgba(15,23,42,.08)}h1{font-size:1.25rem;margin:0 0 8px}p{margin:0 0 20px;color:#475569;line-height:1.5}'
       . 'a{display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:10px 20px;border-radius:10px;font-weight:600}</style>'
       . '</head><body><div class="c"><h1>Access denied</h1><p>' . $msg . '</p>'
       . '<a href="' . $home . '">Back to dashboard</a></div></body></html>';
    exit();
}

/**
 * Require one of the given roles. Not logged in -> login.php;
 * wrong role -> 403 page (no redirect, so a manual URL is clearly refused).
 */
function requireRoleOrDeny(array $roles, string $message): void {
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header("Location: login.php?redirect={$redirect}");
        exit();
    }
    if (!hasRole(...$roles)) {
        denyAccess($message);
    }
}

/** Admin only (user management, content, system logs, sales reports ...). */
function requireAdmin(): void {
    requireRoleOrDeny(['admin'], 'This page is available to administrators only.');
}

/** Staff only. */
function requireStaff(): void {
    requireRoleOrDeny(['staff'], 'This page is available to staff only.');
}

/** Admin or staff (back-office pages). */
function requireAdminOrStaff(): void {
    requireRoleOrDeny(['admin', 'staff'], 'This page is available to administrators and staff only.');
}

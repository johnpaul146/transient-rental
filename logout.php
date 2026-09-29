<?php
/**
 * logout.php — Centralized Logout
 * 
 * Usage:  <a href="logout.php">Logout</a>
 * Or with a redirect:  logout.php?redirect=houses.php
 *
 * ✅ Logs the logout action to system_logs BEFORE destroying the session.
 */

session_start();

require_once 'database.php';

// ✅ NEW: Load SystemLogger (safe — won't break if file missing)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// ============================================================
// ✅ STEP 1: Capture user info BEFORE clearing the session
// (After session_destroy(), $_SESSION is empty — kaya dapat kunin na agad)
// ============================================================
$logged_user_id   = isset($_SESSION['user_id'])  ? (int)$_SESSION['user_id'] : 0;
$logged_username  = $_SESSION['username'] ?? 'Unknown';

// ============================================================
// ✅ STEP 2: Log the logout action (session still valid)
// ============================================================
if ($logged_user_id > 0 && class_exists('SystemLogger')) {
    try {
        SystemLogger::log(
            $pdo,
            'logout',                                              // action
            'auth',                                                // module
            "User '{$logged_username}' logged out",                // description
            $logged_user_id,                                       // target_id
            'user'                                                 // target_type
        );
    } catch (Exception $e) {
        // Silent fail — huwag i-block ang logout kung mag-fail ang logging
        error_log("[logout] SystemLogger failed: " . $e->getMessage());
    }
}

// ============================================================
// ✅ STEP 3: Clear session
// ============================================================
$_SESSION = [];

// ============================================================
// ✅ STEP 4: Destroy session cookie
// ============================================================
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// ============================================================
// ✅ STEP 5: Destroy session
// ============================================================
session_destroy();

// ============================================================
// ✅ STEP 6: Optional redirect
// ============================================================
$redirect = $_GET['redirect'] ?? 'index.php';
if (!preg_match('/^[a-zA-Z0-9_\-\/\.\?=&%]+$/', $redirect)) {
    $redirect = 'index.php';
}

header("Location: " . $redirect);
exit();
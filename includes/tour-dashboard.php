<?php
/**
 * tour-dashboard.php — RETIRED (Tour/Boat catalog management).
 * The Tours/Boat booking service was removed, so there is no tour catalog to manage any more. The `tours` table and
 * all historical tour records are kept untouched in the database. Activities are managed in activities-dashboard.php.
 */
session_start();
require_once 'includes/auth.php';
requireAdminOrStaff();
header('Location: admin-dashboard.php', true, 302);
exit();

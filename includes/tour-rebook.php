<?php
/**
 * tour-rebook.php — RETIRED.
 * Tour rebooking would create a NEW tour reservation, which the removed Tours/Boat service no longer allows.
 * No booking is read or changed here; the request is redirected before any rebooking code runs.
 * (RebookService::canRebookService() also refuses 'tour' server-side.)
 */
session_start();
$role = $_SESSION['role'] ?? '';
header('Location: ' . (in_array($role, ['admin', 'staff'], true) ? 'booking-management.php' : (isset($_SESSION['user_id']) ? 'profile.php' : 'index.php')), true, 302);
exit();

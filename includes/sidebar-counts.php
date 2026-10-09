<?php
// ============================================================
// CENTRALIZED SIDEBAR NOTIFICATION SOURCE  (single source of truth)
//
// This is the ONLY place sidebar badge counts are calculated.
// Pages must `require_once 'includes/sidebar-counts.php';` right after
// database.php and BEFORE any sidebar HTML, then only READ:
//
//   $sidebar_pending_bookings
//   $sidebar_pending_reviews
//   $sidebar_failed_logs
//   $sidebar_total_food        (Food Management item count badge)
//   $sidebar_blocked_dates     (upcoming blocked dates badge)
//
// Work happens inside a closure so no temp variables ($cols, $table,
// $e, ...) leak into the page scope and clobber page variables.
// ============================================================

[$sidebar_pending_bookings,
 $sidebar_pending_reviews,
 $sidebar_failed_logs,
 $sidebar_total_food,
 $sidebar_blocked_dates] = (function () {
    global $pdo;

    $bookings = 0; $reviews = 0; $failed = 0; $food = 0; $blocked = 0;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return [$bookings, $reviews, $failed, $food, $blocked];
    }

    // Pending bookings (house + tour + food + package) — same definition as the "Pending" list in
    // booking-management.php: a package counts once (its house/tour/food component rows are skipped),
    // and a house rebook still waiting for confirmation counts as pending.
    foreach (['house_bookings', 'tour_bookings', 'food_bookings', 'package_bookings'] as $table) {
        $own  = $table === 'package_bookings' ? '' : ' AND (package_id IS NULL OR package_id = 0)';
        $pend = $table === 'house_bookings'
            ? "(payment_status='pending' OR (booking_status='pending' AND (rebooked_at IS NOT NULL OR rebook_count > 0)))"
            : "payment_status='pending'";
        try {
            $bookings += (int)$pdo->query(
                "SELECT COUNT(*) FROM `$table` WHERE $pend AND booking_status NOT IN ('cancelled','completed')$own"
            )->fetchColumn();
        } catch (Throwable $e) {
            if ($table === 'house_bookings') {   // rebook columns missing on an old database: plain definition
                try {
                    $bookings += (int)$pdo->query(
                        "SELECT COUNT(*) FROM `house_bookings` WHERE payment_status='pending' AND booking_status NOT IN ('cancelled','completed')$own"
                    )->fetchColumn();
                } catch (Throwable $e2) {}
            }
        }
    }

    // Pending reviews (auto-detect column)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM overall_feedback")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('status', $cols)) {
            $reviews = (int)$pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE status='pending'")->fetchColumn();
        } elseif (in_array('is_approved', $cols)) {
            $reviews = (int)$pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE is_approved=0")->fetchColumn();
        }
    } catch (Throwable $e) {}

    // Failed system logs (last 7 days)
    try {
        $failed = (int)$pdo->query(
            "SELECT COUNT(*) FROM system_logs WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
    } catch (Throwable $e) {}

    // Food items + upcoming blocked dates (Booking Management sidebar badges)
    try { $food = (int)$pdo->query("SELECT COUNT(*) FROM food_items")->fetchColumn(); } catch (Throwable $e) {}
    try { $blocked = (int)$pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE block_date >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}

    return [$bookings, $reviews, $failed, $food, $blocked];
})();

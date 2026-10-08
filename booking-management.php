<?php
session_start();
require_once 'database.php';
require_once 'includes/sidebar-counts.php';

require_once 'config/mail_config.php';
require_once 'includes/EmailNotifications.php';
require_once 'includes/PaymentService.php';
require_once 'includes/WalkInBookingService.php';
require_once 'includes/RebookService.php';

// ✅ Load SystemLogger (for logout logging)
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Check if user is logged in and is admin or staff
require_once 'includes/auth.php';
requireAdminOrStaff();

// ============================================================
// ✅ ROLE-BASED PERMISSIONS (Booking Management)
//    STAFF = daily operations   |   ADMIN = management + oversight
// ============================================================
$is_admin = (($_SESSION['role'] ?? '') === 'admin');
$is_staff = (($_SESSION['role'] ?? '') === 'staff');

// Daily operations (Staff + Admin): view, approve/confirm/reject bookings,
// check/approve/reject payment, handle rebooks, update operational status.
$can_manage_booking   = ($is_admin || $is_staff);

// Management + oversight (Admin only)
$can_delete_booking   = $is_admin;   // delete bookings / permanent removal
$can_override_booking = $is_admin;   // override booking decisions
$can_view_reports     = $is_admin;   // reports
$can_view_logs        = $is_admin;   // system logs
$can_manage_users     = $is_admin;   // user management
$can_manage_system    = $is_admin;   // system configuration

if (!function_exists('walkInChip')) {
    /** Small "Walk-in" label next to the reference for bookings created at the counter. */
    function walkInChip(array $row): string {
        if (($row['booking_source'] ?? 'online') !== 'walk_in') return '';
        return ' <span title="Created by staff for a walk-in guest" style="display:inline-block;margin-left:4px;padding:1px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;letter-spacing:.03em;vertical-align:middle;white-space:nowrap;">WALK-IN</span>';
    }
}

/**
 * May the current user cancel this booking row from the list?
 * ADMIN ONLY. Pending and confirmed bookings (paid or not) can be cancelled; cancelled and
 * completed ones cannot. Staff never see the button (and the server refuses them too).
 */
if (!function_exists('bookingCancelAllowed')) {
    function bookingCancelAllowed(array $row): bool {
        if (($_SESSION['role'] ?? '') !== 'admin') return false;
        return in_array((string)($row['booking_status'] ?? ''), ['pending', 'confirmed'], true);
    }
}

/**
 * Stop the request with HTTP 403 (used for server-side permission enforcement).
 */
if (!function_exists('denyBookingPermission')) {
    function denyBookingPermission(string $message = 'You do not have permission to perform this action.'): void {
        http_response_code(403);
        exit(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    }
}

// Server-side guard: admin-only actions can never be run by staff, even if the
// request is crafted by hand (hiding a button is not security).
foreach (['delete_booking', 'permanent_delete_booking', 'override_booking', 'override_status'] as $adminOnlyAction) {
    if ((isset($_POST[$adminOnlyAction]) || isset($_GET[$adminOnlyAction]))
        && !($can_delete_booking && $can_override_booking)) {
        denyBookingPermission('This action is restricted to administrators.');
    }
}

// Server-side guard: every booking operation requires booking-management permission.
// Cancelling a booking is ADMIN ONLY (staff get 403 even with a hand-made request).
if (isset($_POST['admin_cancel_booking']) && !$is_admin) {
    if (class_exists('SystemLogger') && isset($pdo)) {
        SystemLogger::log($pdo, 'error', 'booking', 'Cancellation attempt denied: only an administrator can cancel bookings', null, null, null, null, 'warning');
    }
    denyBookingPermission('Only an administrator can cancel bookings.');
}

foreach (['confirm_rebook', 'reject_rebook', 'confirm_payment', 'reject_payment', 'mark_balance_paid', 'create_walkin_booking'] as $bookingAction) {
    if (isset($_POST[$bookingAction]) && !$can_manage_booking) {
        denyBookingPermission();
    }
}

// ============================================================
// ✅ DUPLICATE REQUEST GUARD
// ============================================================
if (!function_exists('isDuplicateBookingRequest')) {
    function isDuplicateBookingRequest(string $actionKey, $bookingId, string $bookingType = ''): bool {
        $key = '_last_booking_action_' . $actionKey;
        $token = $bookingId . '_' . $bookingType;
        $now = time();

        if (isset($_SESSION[$key]['token'], $_SESSION[$key]['time'])) {
            $isSameToken = ($_SESSION[$key]['token'] === $token);
            $isRecent    = (($now - $_SESSION[$key]['time']) < 5);
            if ($isSameToken && $isRecent) {
                return true;
            }
        }

        $_SESSION[$key] = ['token' => $token, 'time' => $now];
        return false;
    }
}

// Get dynamic content
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

// ============================================================
// RESOLVE LOGO PATH
// ============================================================
$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

// Get user info for header
$user_info = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_info = $stmt->fetch();
} catch(PDOException $e) {
    $user_info = [];
}

if (!function_exists('getAdminAvatar')) {
    function getAdminAvatar($user_info) {
        if (empty($user_info['profile_photo'])) return null;
        $user_id = (int)($user_info['id'] ?? 0);
        if ($user_id <= 0) return null;

        $paths = [
            'uploads/profile/user_' . $user_id . '/' . $user_info['profile_photo'],
            'uploads/staff/' . $user_info['profile_photo'],
            'uploads/profile/' . $user_info['profile_photo'],
        ];
        foreach ($paths as $path) {
            if (file_exists($path) && !is_dir($path)) return $path;
        }
        return null;
    }
}

$admin_avatar = getAdminAvatar($user_info);
$admin_initial = strtoupper(substr($user_info['fullname'] ?? $user_info['username'] ?? 'U', 0, 1));
$admin_display_name = $user_info['fullname'] ?? $user_info['username'] ?? 'User';

// Detect name column
$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$name_column = in_array('fullname', $columns) ? 'fullname' : (in_array('full_name', $columns) ? 'full_name' : 'username');

// ============================================================
// ✅ AUTO-CREATE blocked_dates TABLE
// ============================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_dates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_type ENUM('house', 'tour') NOT NULL,
        item_id INT NOT NULL,
        block_date DATE NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        block_type ENUM('walk_in', 'maintenance', 'special_occasion', 'owner_use', 'other') DEFAULT 'walk_in',
        blocked_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_block (item_type, item_id, block_date),
        INDEX idx_item (item_type, item_id, block_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(PDOException $e) {}

// ============================================================
// ✅ AUTO-BLOCK dates when booking is confirmed/paid
// ============================================================
if (!function_exists('autoBlockBookingDates')) {
    function autoBlockBookingDates($pdo, $booking_type, $item_id, $start_date, $end_date, $booking_id, $reference_number) {
        // One rule for the whole system (AvailabilityService): a house stay blocks
        // its nights only — the check-out day stays free for the next check-in.
        try {
            return AvailabilityService::blockForBooking($pdo, $booking_type, $item_id, $start_date,
                $booking_type === 'house' ? $end_date : null, $reference_number, $_SESSION['user_id'] ?? null);
        } catch (Exception $e) {
            error_log("Auto-block failed: " . $e->getMessage());
            return 0;
        }
    }
}

// ============================================================
// ✅ AUTO-ADD check_in_time / check_out_time columns
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'check_in_time'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN check_in_time TIME DEFAULT '14:00:00' AFTER check_in_date");
    }
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'check_out_time'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN check_out_time TIME DEFAULT '12:00:00' AFTER check_out_date");
    }
} catch(PDOException $e) {}

// ============================================================
// AUTO-ADD rebook columns
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebook_count'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebook_count INT NOT NULL DEFAULT 0");
    } else {
        $pdo->exec("UPDATE house_bookings SET rebook_count = 0 WHERE rebook_count IS NULL");
    }
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebooked_at'")->fetchAll();
    if (empty($cols)) $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebooked_at DATETIME DEFAULT NULL");
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebook_confirmed_at'")->fetchAll();
    if (empty($cols)) $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebook_confirmed_at DATETIME DEFAULT NULL");
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'previous_check_in_date'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings 
            ADD COLUMN previous_check_in_date DATE DEFAULT NULL,
            ADD COLUMN previous_check_out_date DATE DEFAULT NULL,
            ADD COLUMN previous_number_of_guests INT DEFAULT NULL,
            ADD COLUMN previous_total_amount DECIMAL(10,2) DEFAULT NULL");
    }
} catch(PDOException $e) {}

try {
    foreach (['house_bookings', 'tour_bookings', 'food_bookings'] as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'gcash_reference'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN gcash_reference VARCHAR(30) DEFAULT NULL");
        }
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'cancelled_at'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN cancelled_at DATETIME DEFAULT NULL");
        }
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'cancellation_reason'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN cancellation_reason TEXT DEFAULT NULL");
        }
    }
} catch(PDOException $e) {}

// ============================================================
// ✅ AUTO-ADD food_bookings delivery columns
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM food_bookings LIKE 'contact_number'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE food_bookings ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL");
    }
    $cols = $pdo->query("SHOW COLUMNS FROM food_bookings LIKE 'fulfillment_method'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE food_bookings ADD COLUMN fulfillment_method VARCHAR(20) DEFAULT 'pickup'");
    }
    $cols = $pdo->query("SHOW COLUMNS FROM food_bookings LIKE 'delivery_address'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE food_bookings ADD COLUMN delivery_address TEXT DEFAULT NULL");
    }
    $pdo->exec("UPDATE food_bookings SET fulfillment_method = 'pickup' WHERE fulfillment_method IS NULL OR fulfillment_method = ''");
} catch(PDOException $e) {}

// ============================================================
// ✅ AUTO-ADD package_bookings table + package_id columns
// ============================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS package_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reference_number VARCHAR(50) NOT NULL UNIQUE,
        guest_id INT NOT NULL,
        house_booking_id INT DEFAULT NULL,
        tour_booking_id INT DEFAULT NULL,
        food_booking_id INT DEFAULT NULL,
        house_amount DECIMAL(10,2) DEFAULT 0,
        tour_amount DECIMAL(10,2) DEFAULT 0,
        food_amount DECIMAL(10,2) DEFAULT 0,
        grand_total DECIMAL(10,2) DEFAULT 0,
        contact_number VARCHAR(30) DEFAULT NULL,
        special_requests TEXT DEFAULT NULL,
        payment_status VARCHAR(20) DEFAULT 'pending',
        booking_status VARCHAR(20) DEFAULT 'pending',
        payment_proof VARCHAR(255) DEFAULT NULL,
        gcash_reference VARCHAR(30) DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        cancellation_reason TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_guest (guest_id),
        INDEX idx_ref (reference_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (['house_bookings', 'tour_bookings', 'food_bookings'] as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'package_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN package_id INT DEFAULT NULL");
        }
    }
} catch(PDOException $e) {}

// ============================================================
// ✅ AUTO-COMPLETE PAST BOOKINGS
// ============================================================
try {
    // Service status only: payment status is never changed here, so a completed
    // stay with a balance due stays visible under Outstanding Balances.
    // Secured past bookings -> completed; never-paid past bookings (no proof) -> expired; packages and parts included.
    foreach (PaymentService::autoCompleteSql() as $autoSql) { $pdo->exec($autoSql); }
} catch (PDOException $e) {
    error_log("AUTO-COMPLETE ERROR: " . $e->getMessage());
}

// ============================================================
// Handle Confirm Rebook
// ============================================================
// ============================================================
// WALK-IN BOOKINGS (admin + staff) — logic lives in includes/WalkInBookingService.php
// ============================================================
if (isset($_GET['walkin_guest_search']) && $can_manage_booking) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(['results' => WalkInBookingService::searchGuests($pdo, (string)($_GET['q'] ?? ''))]);
    } catch (Throwable $e) {
        error_log('walkin guest search failed: ' . $e->getMessage());
        echo json_encode(['results' => [], 'error' => 'Search failed.']);
    }
    exit();
}

if (isset($_POST['create_walkin_booking']) && $can_manage_booking) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals((string)($_SESSION['wk_csrf'] ?? ''), (string)($_POST['wk_csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your session expired. Please refresh the page and try again.');
        }
        $nonce = (string)($_POST['wk_nonce'] ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $nonce)) throw new InvalidArgumentException('Invalid request. Please refresh the page and try again.');
        $used = $_SESSION['wk_nonces'] ?? [];
        if (isset($used[$nonce])) throw new InvalidArgumentException('This walk-in booking was already submitted. Check the booking list before creating it again.');
        $used[$nonce] = time();
        $_SESSION['wk_nonces'] = array_slice($used, -50, null, true);

        $res = WalkInBookingService::create($pdo, $_POST, (int)$_SESSION['user_id'], (string)$_SESSION['role']);
        echo json_encode(['ok' => true, 'reference' => $res['reference'], 'guest' => $res['guest_name'], 'total' => $res['total'],
                          'type' => $res['type'], 'payment_message' => $res['payment']['message'], 'payment_recorded' => $res['payment']['recorded']]);
    } catch (InvalidArgumentException | AvailabilityConflictException $e) {
        if (!empty($nonce) && isset($_SESSION['wk_nonces'][$nonce])) unset($_SESSION['wk_nonces'][$nonce]); // allow a corrected retry
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        if (!empty($nonce) && isset($_SESSION['wk_nonces'][$nonce])) unset($_SESSION['wk_nonces'][$nonce]);
        error_log('walk-in booking failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Something went wrong and nothing was saved. Please try again.']);
    }
    exit();
}

if(isset($_POST['confirm_rebook']) && $can_manage_booking) {
    try {
        $booking_id = (int)$_POST['booking_id'];
        if (isDuplicateBookingRequest('confirm_rebook', $booking_id)) {
            header("Location: booking-management.php?tab=rebook");
            exit();
        }
        
        // Re-check the NEW dates while the house is locked; confirm only if still free.
        // On conflict nothing changes: the request stays pending and the original
        // dates stay blocked, so the guest keeps the reservation they had.
        RebookService::confirmHouseRebook($pdo, $booking_id, (int)$_SESSION['user_id']);

        try { EmailNotifications::sendRebookConfirmation($booking_id, $pdo); } catch (Throwable $mailEx) {}
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'confirm_rebook', 'booking', "Confirmed rebook for house booking ID {$booking_id}", $booking_id, 'booking');
        }
        $success = "✅ Rebook confirmed! New stay dates are now locked in and an email confirmation was sent to the guest.";
    } catch(AvailabilityConflictException $e) {
        $error = "Cannot confirm this rebook — the new dates are no longer available. " . $e->getMessage()
               . " Nothing was changed: the guest keeps the original dates. You can reject the rebook request so the guest can pick other dates.";
    } catch(Exception $e) {
        $error = "Failed to confirm rebook: " . $e->getMessage();
    }
}

// ============================================================
// ✅ Handle REJECT REBOOK
// ============================================================
if(isset($_POST['reject_rebook']) && $can_manage_booking) {
    try {
        $booking_id    = (int)($_POST['booking_id'] ?? 0);
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        $reject_reason_other = trim($_POST['reject_reason_other'] ?? '');
        $reject_notes  = trim($_POST['reject_notes'] ?? '');

        if (isDuplicateBookingRequest('reject_rebook', $booking_id)) {
            header("Location: booking-management.php?tab=rebook");
            exit();
        }

        if ($booking_id <= 0) throw new Exception("Invalid booking ID.");
        if (empty($reject_reason)) throw new Exception("Please select a reason for rejecting the rebook.");
        if ($reject_reason === 'Other' && empty($reject_reason_other)) {
            throw new Exception("Please specify the reason when selecting 'Other'.");
        }

        $final_reason = ($reject_reason === 'Other') ? $reject_reason_other : $reject_reason;
        if (!empty($reject_notes)) $final_reason .= ' — Notes: ' . $reject_notes;

        $stmt = $pdo->prepare("SELECT reference_number, house_id, guest_id, previous_check_in_date, previous_check_out_date, previous_number_of_guests, previous_total_amount, previous_booking_status, check_in_date, check_out_date, number_of_guests, total_amount, rebook_count FROM house_bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        if (!$booking) throw new Exception("Booking not found.");

        $has_backup = !empty($booking['previous_check_in_date']) && !empty($booking['previous_check_out_date']);

        PaymentService::releaseBlockedDates($pdo, 'house', $booking['house_id'], $booking['reference_number']);

        // Status before the rebook request (a rebooking credit returns to 'cancelled')
        $restore_status = in_array($booking['previous_booking_status'] ?? '', ['confirmed', 'cancelled', 'completed', 'pending'], true)
            ? $booking['previous_booking_status'] : 'confirmed';
        if ($has_backup) {
            $pdo->prepare("UPDATE house_bookings SET check_in_date = previous_check_in_date, check_out_date = previous_check_out_date, number_of_guests = previous_number_of_guests, total_amount = previous_total_amount, previous_check_in_date = NULL, previous_check_out_date = NULL, previous_number_of_guests = NULL, previous_total_amount = NULL, booking_status = ?, previous_booking_status = NULL, rebook_count = GREATEST(0, rebook_count - 1), rebooked_at = NULL, rebook_confirmed_at = NULL WHERE id = ?")->execute([$restore_status, $booking_id]);
            
            if ($restore_status !== 'cancelled') {
                autoBlockBookingDates($pdo, 'house', $booking['house_id'], $booking['previous_check_in_date'], $booking['previous_check_out_date'], $booking_id, $booking['reference_number']);
            }
        } else {
            $pdo->prepare("UPDATE house_bookings SET booking_status = ?, previous_booking_status = NULL, rebook_count = GREATEST(0, rebook_count - 1), rebooked_at = NULL, rebook_confirmed_at = NULL WHERE id = ?")->execute([$restore_status, $booking_id]);
        }

        $rejected_by_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Administrator';
        try {
            if (method_exists('EmailNotifications', 'sendRebookRejected')) {
                EmailNotifications::sendRebookRejected($booking_id, $pdo, $final_reason, $rejected_by_name);
            }
        } catch (Throwable $mailEx) { error_log("Rebook reject email failed: " . $mailEx->getMessage()); }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'reject_rebook', 'booking', "Rejected rebook for house booking {$booking['reference_number']} — Reason: {$final_reason}", $booking_id, 'house_booking', ['reference' => $booking['reference_number'], 'reverted_check_in' => $booking['previous_check_in_date'] ?? null], ['reason' => $final_reason, 'rejected_by' => $rejected_by_name], 'warning');
        }
        $success = "✅ Rebook rejected. Original dates have been restored and the guest has been notified via email.";
    } catch(Exception $e) {
        $error = "Failed to reject rebook: " . $e->getMessage();
    }
}

// ============================================================
// ✅ Handle ADMIN CANCEL BOOKING
// ============================================================
if(isset($_POST['admin_cancel_booking']) && $is_admin) {
    try {
        $booking_type  = $_POST['booking_type'] ?? '';
        $booking_id    = (int)($_POST['booking_id'] ?? 0);
        $cancel_reason = trim($_POST['cancel_reason'] ?? '');
        $cancel_reason_other = trim($_POST['cancel_reason_other'] ?? '');
        $cancel_notes  = trim($_POST['cancel_notes'] ?? '');

        if (isDuplicateBookingRequest('admin_cancel', $booking_id, $booking_type)) {
            header("Location: booking-management.php");
            exit();
        }

        $allowed_tables = ['house' => 'house_bookings', 'tour' => 'tour_bookings', 'food' => 'food_bookings', 'package' => 'package_bookings'];
        if (!isset($allowed_tables[$booking_type])) throw new Exception("Invalid booking type.");
        if ($booking_id <= 0) throw new Exception("Invalid booking ID.");
        if (empty($cancel_reason)) throw new Exception("Please select a reason for cancellation.");
        if (!in_array($cancel_reason, PaymentService::ADMIN_CANCEL_REASONS, true)) throw new Exception("Please select a valid reason for cancellation.");
        if ($cancel_reason === 'Other' && empty($cancel_reason_other)) throw new Exception("Please specify the reason when selecting 'Other'.");

        $final_reason = ($cancel_reason === 'Other') ? $cancel_reason_other : $cancel_reason;
        if (!empty($cancel_notes)) $final_reason .= ' — Notes: ' . $cancel_notes;

        $table = $allowed_tables[$booking_type];
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        if (!$booking) throw new Exception("Booking not found.");
        if ($booking['booking_status'] === 'cancelled') throw new Exception("This booking is already cancelled.");

        // Unpaid -> normal cancellation. Paid -> no refund: payment kept as rebooking credit.
        $cancelResult = PaymentService::cancelBooking($pdo, $booking_type, $booking_id, $final_reason);

        // Release the dates (house/tour, or the components of a package)
        if ($booking_type === 'house') {
            PaymentService::releaseBlockedDates($pdo, 'house', $booking['house_id'], $booking['reference_number']);
        } elseif ($booking_type === 'tour') {
            PaymentService::releaseBlockedDates($pdo, 'tour', $booking['tour_id'], $booking['reference_number']);
        } elseif ($booking_type === 'package') {
            if (!empty($booking['house_booking_id'])) {
                $c = $pdo->prepare("SELECT house_id, reference_number FROM house_bookings WHERE id = ?"); $c->execute([$booking['house_booking_id']]);
                if ($cr = $c->fetch()) PaymentService::releaseBlockedDates($pdo, 'house', $cr['house_id'], $cr['reference_number']);
            }
            if (!empty($booking['tour_booking_id'])) {
                $c = $pdo->prepare("SELECT tour_id, reference_number FROM tour_bookings WHERE id = ?"); $c->execute([$booking['tour_booking_id']]);
                if ($cr = $c->fetch()) PaymentService::releaseBlockedDates($pdo, 'tour', $cr['tour_id'], $cr['reference_number']);
            }
        }
        $booking['total_amount'] = $booking['total_amount'] ?? ($booking['grand_total'] ?? 0);

        $cancelled_by_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Administrator';
        try {
            if (method_exists('EmailNotifications', 'sendBookingCancelled')) {
                EmailNotifications::sendBookingCancelled($booking_id, $booking_type, $pdo, $final_reason, $cancelled_by_name);
            }
        } catch (Throwable $mailEx) { error_log("Cancel booking email failed: " . $mailEx->getMessage()); }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'cancel_booking', 'booking', "Admin cancelled {$booking_type} booking {$booking['reference_number']} — Reason: {$final_reason}", $booking_id, "{$booking_type}_booking", ['reference' => $booking['reference_number'], 'amount' => $booking['total_amount'], 'payment_status' => $booking['payment_status']], ['reason' => $final_reason, 'cancelled_by' => $cancelled_by_name], 'warning');
        }
        $success = $cancelResult['credit'] > 0
            ? "✅ Booking {$booking['reference_number']} cancelled. The " . PaymentService::peso($cancelResult['credit']) . " already paid is non-refundable and is kept as a rebooking credit (status: Rebooking Required)."
            : "✅ Booking {$booking['reference_number']} has been cancelled. Guest has been notified via email.";
    } catch(Throwable $e) {
        $error = "Failed to cancel booking: " . $e->getMessage();
    }
}

// ============================================================
// Handle Confirm Reservation Fee — idempotent (see includes/PaymentService.php)
// Confirming the guest's proof records the ₱1,000 reservation fee (or the total
// if lower). It does NOT mark the whole booking as paid.
// ============================================================
if(isset($_POST['confirm_payment']) && $can_manage_booking) {
    try {
        $booking_type = (string)($_POST['booking_type'] ?? '');
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        if (!isset(PaymentService::TABLES[$booking_type]) || $booking_id <= 0) throw new Exception("Invalid booking.");

        $result = PaymentService::confirmReservation($pdo, $booking_type, $booking_id, (int)$_SESSION['user_id']);

        if ($result['status'] === 'already') {
            $success = "This reservation fee was already confirmed — nothing was recorded twice.";
        } else {
            // Dates were checked and blocked inside the confirmation transaction (PaymentService).
            try { EmailNotifications::sendPaymentConfirmation($booking_id, $booking_type, $pdo); } catch (Throwable $mailEx) { error_log("Payment confirmation email failed: " . $mailEx->getMessage()); }
            if (class_exists('SystemLogger')) {
                SystemLogger::log($pdo, 'confirm_payment', 'booking',
                    "Confirmed {$booking_type} reservation fee of " . PaymentService::peso($result['amount']) . " for booking ID {$booking_id}",
                    $booking_id, 'booking', null, ['amount' => $result['amount'], 'payment_status' => $result['payment_status']]);
            }
            $success = $result['payment_status'] === 'paid'
                ? "Payment of " . PaymentService::peso($result['amount']) . " confirmed — booking is fully paid."
                : "Reservation fee of " . PaymentService::peso($result['amount']) . " confirmed. The remaining balance is collected on arrival.";
        }
    } catch(AvailabilityConflictException $e) {
        $error = "Cannot confirm this reservation fee — the date is already taken. " . $e->getMessage()
               . " Nothing was recorded (no payment, no status change). Contact the guest to choose another date, or reject the payment.";
    } catch(Throwable $e) {
        error_log("Confirm reservation fee failed: " . $e->getMessage());
        $error = "Failed to confirm payment: " . $e->getMessage();
    }
}

// ============================================================
// Handle Mark Balance as Paid (owner/staff, on arrival) — idempotent
// ============================================================
if(isset($_POST['mark_balance_paid']) && $can_manage_booking) {
    try {
        $booking_type = (string)($_POST['booking_type'] ?? '');
        $booking_id   = (int)($_POST['booking_id'] ?? 0);
        if (!isset(PaymentService::TABLES[$booking_type]) || $booking_id <= 0) throw new Exception("Invalid booking.");
        $result = PaymentService::recordBalance($pdo, $booking_type, $booking_id, (int)$_SESSION['user_id'],
            (string)($_POST['payment_method'] ?? ''), trim((string)($_POST['received_at'] ?? '')), $_POST['payment_notes'] ?? null);
        if ($result['status'] === 'already') {
            $success = "The balance for this booking was already recorded — nothing was recorded twice.";
        } else {
            if (class_exists('SystemLogger')) {
                SystemLogger::log($pdo, 'balance_paid', 'booking',
                    "Recorded balance payment of " . PaymentService::peso($result['amount']) . " for {$booking_type} booking ID {$booking_id} (" . ($_POST['payment_method'] ?? '') . ")",
                    $booking_id, 'booking', null, ['amount' => $result['amount'], 'received_at' => $result['received_at'], 'method' => $_POST['payment_method'] ?? '']);
            }
            $success = "Balance of " . PaymentService::peso($result['amount']) . " recorded. The booking is now Fully Paid.";
        }
    } catch(Throwable $e) {
        error_log("Mark balance paid failed: " . $e->getMessage());
        $error = "Failed to record the balance: " . $e->getMessage();
    }
}

// ============================================================
// ✅ Handle Reject Payment (Staff + Admin)
//    Soft reject: the booking record, payment proof and audit trail are KEPT.
//    Permanent deletion is an admin-only management action (not done here).
// ============================================================
if(isset($_POST['reject_payment']) && $can_manage_booking) {
    try {
        $booking_type = $_POST['booking_type'] ?? '';
        $booking_id   = (int)($_POST['booking_id'] ?? 0);
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        $reject_reason_other = trim($_POST['reject_reason_other'] ?? '');
        $reject_notes = trim($_POST['reject_notes'] ?? '');

        if (isDuplicateBookingRequest('reject_payment', $booking_id, $booking_type)) {
            header("Location: booking-management.php");
            exit();
        }

        $allowed_tables = ['house' => 'house_bookings', 'tour' => 'tour_bookings', 'food' => 'food_bookings', 'package' => 'package_bookings'];
        if (!isset($allowed_tables[$booking_type])) throw new Exception("Invalid booking type.");
        if ($booking_id <= 0) throw new Exception("Invalid booking ID.");
        if (empty($reject_reason)) throw new Exception("Please select a reason for rejection.");
        if ($reject_reason === 'Other' && empty($reject_reason_other)) throw new Exception("Please specify the reason when selecting 'Other'.");

        $final_reason = ($reject_reason === 'Other') ? $reject_reason_other : $reject_reason;
        if (!empty($reject_notes)) $final_reason .= ' — Notes: ' . $reject_notes;

        $table = $allowed_tables[$booking_type];
        $stmt = $pdo->prepare("SELECT reference_number, payment_status, booking_status, payment_proof, " . ($booking_type === 'package' ? 'grand_total' : 'total_amount') . " AS amount FROM `$table` WHERE id = ?");
        $stmt->execute([$booking_id]);
        $row = $stmt->fetch();
        if (!$row) throw new Exception("Booking not found.");
        if ($row['booking_status'] === 'cancelled') throw new Exception("This booking is already cancelled/rejected.");
        if (in_array($row['payment_status'], ['reservation_paid', 'paid'], true)) {
            throw new Exception("This payment was already confirmed. Payments are non-refundable — use Cancel to keep it as a rebooking credit instead.");
        }
        if ($booking_type !== 'package') {
            $pkgChk = $pdo->prepare("SELECT package_id FROM `$table` WHERE id = ?");
            $pkgChk->execute([$booking_id]);
            if ((int)$pkgChk->fetchColumn() > 0) throw new Exception("This item is part of a package. Reject the payment on the package.");
        }

        $rejected_by_id   = (int)$_SESSION['user_id'];
        $rejected_by_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Staff';

        // Release any auto-blocked dates (same behaviour as cancelling a booking)
        if ($booking_type === 'house') {
            try {
                $hb = $pdo->prepare("SELECT house_id FROM house_bookings WHERE id = ?");
                $hb->execute([$booking_id]);
                $hrow = $hb->fetch();
                if ($hrow && $hrow['house_id']) {
                    PaymentService::releaseBlockedDates($pdo, 'house', $hrow['house_id'], $row['reference_number']);
                }
            } catch (PDOException $e) {}
        } elseif ($booking_type === 'tour') {
            try {
                $tb = $pdo->prepare("SELECT tour_id FROM tour_bookings WHERE id = ?");
                $tb->execute([$booking_id]);
                $trow = $tb->fetch();
                if ($trow && $trow['tour_id']) {
                    PaymentService::releaseBlockedDates($pdo, 'tour', $trow['tour_id'], $row['reference_number']);
                }
            } catch (PDOException $e) {}
        }

        if ($booking_type === 'package') {
            // package_bookings has no reject_* columns; record the rejection and cancel the
            // package together with its components (no money was received).
            $pdo->prepare("UPDATE `$table` SET payment_status = 'cancelled', booking_status = 'cancelled', cancelled_at = NOW(), cancellation_reason = ? WHERE id = ? AND payment_status = 'pending'")
                ->execute(['Payment rejected by ' . $rejected_by_name . ': ' . $final_reason, $booking_id]);
            PaymentService::syncPackageComponents($pdo, $booking_id);
            $pk = $pdo->prepare("SELECT house_booking_id, tour_booking_id FROM package_bookings WHERE id = ?");
            $pk->execute([$booking_id]);
            if ($pkr = $pk->fetch()) {
                if (!empty($pkr['house_booking_id'])) { $c = $pdo->prepare("SELECT house_id, reference_number FROM house_bookings WHERE id = ?"); $c->execute([$pkr['house_booking_id']]); if ($cr = $c->fetch()) PaymentService::releaseBlockedDates($pdo, 'house', $cr['house_id'], $cr['reference_number']); }
                if (!empty($pkr['tour_booking_id']))  { $c = $pdo->prepare("SELECT tour_id, reference_number FROM tour_bookings WHERE id = ?");  $c->execute([$pkr['tour_booking_id']]);  if ($cr = $c->fetch()) PaymentService::releaseBlockedDates($pdo, 'tour', $cr['tour_id'], $cr['reference_number']); }
            }
        } else {
            $pdo->prepare("UPDATE `$table`
                           SET payment_status = 'cancelled',
                               booking_status = 'cancelled',
                               cancelled_at = NOW(),
                               cancellation_reason = ?,
                               reject_reason = ?,
                               reject_notes = ?,
                               rejected_by = ?,
                               rejected_at = NOW()
                           WHERE id = ? AND payment_status = 'pending'")
                ->execute([
                    'Payment rejected: ' . $final_reason,
                    substr($reject_reason === 'Other' ? $reject_reason_other : $reject_reason, 0, 255),
                    ($reject_notes !== '' ? $reject_notes : null),
                    $rejected_by_id,
                    $booking_id
                ]);
        }

        try {
            EmailNotifications::sendPaymentRejected($booking_id, $booking_type, $pdo, $final_reason, $rejected_by_name);
        } catch (Throwable $mailEx) { error_log("Reject email failed: " . $mailEx->getMessage()); }

        // NOTE: the uploaded payment proof file is intentionally kept as audit evidence.
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'reject_payment', 'booking', "Rejected payment for {$booking_type} booking {$row['reference_number']} — Reason: {$final_reason}", $booking_id, 'booking', ['reference' => $row['reference_number'], 'amount' => $row['amount'], 'payment_status' => $row['payment_status'], 'booking_status' => $row['booking_status']], ['reason' => $final_reason, 'rejected_by' => $rejected_by_name, 'rejected_by_id' => $rejected_by_id, 'rejected_at' => date('Y-m-d H:i:s')], 'warning');
        }

        $success = "Payment rejected. Reason sent to guest via email. Booking #{$row['reference_number']} was marked as cancelled and kept in the records.";
    } catch(Exception $e) {
        $error = "Failed to reject payment: " . $e->getMessage();
    }
}

// Get filter and search parameters
$status_filter = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
if (!in_array($status_filter, ['all', 'pending', 'rebook', 'pending_rebook', 'paid', 'cancelled', 'history'], true)) $status_filter = 'all';
// 'paid' filter = money received: Reservation Fee Paid or Fully Paid
$paid_filter_sql = "IN ('reservation_paid','paid')";
$search = isset($_GET['search']) ? $_GET['search'] : '';
$view = (isset($_GET['view']) && $_GET['view'] === 'calendar') ? 'calendar' : 'list';

// ============================================================
// ✅ Get House Bookings
// ============================================================
$house_query = "SELECT b.*, b.guest_names, h.house_name, COALESCE(u.$name_column, 'Unknown Guest') as guest_name, COALESCE(u.email, '') as email FROM house_bookings b LEFT JOIN houses h ON b.house_id = h.id LEFT JOIN guests g ON b.guest_id = g.id LEFT JOIN users u ON g.user_id = u.id WHERE (b.package_id IS NULL OR b.package_id = 0)";

if($status_filter == 'pending') {
    $house_query .= " AND (b.payment_status = 'pending' OR (b.booking_status = 'pending' AND (b.rebooked_at IS NOT NULL OR b.rebook_count > 0)))";
} elseif($status_filter == 'rebook' || $status_filter == 'pending_rebook') {
    $house_query .= " AND (b.rebooked_at IS NOT NULL OR b.rebook_count > 0)";
} elseif($status_filter == 'cancelled') {
    $house_query .= " AND b.booking_status = 'cancelled'";
} elseif($status_filter == 'history') {
    $house_query .= " AND b.booking_status IN ('completed', 'cancelled')";
} elseif($status_filter != 'all') {
    $house_query .= ($status_filter === 'paid') ? " AND b.payment_status $paid_filter_sql" : " AND b.payment_status = :status";
}
if($search) {
    $house_query .= " AND (b.reference_number LIKE :search OR COALESCE(u.$name_column, '') LIKE :search OR h.house_name LIKE :search)";
}
$house_query .= " ORDER BY COALESCE(b.rebooked_at, b.created_at) DESC";

$house_stmt = $pdo->prepare($house_query);
if($status_filter != 'all' && $status_filter != 'pending' && $status_filter != 'rebook' && $status_filter != 'pending_rebook' && $status_filter != 'cancelled' && $status_filter != 'history' && $status_filter != 'paid') {
    $house_stmt->bindValue(':status', $status_filter);
}
if($search) $house_stmt->bindValue(':search', "%$search%");
$house_stmt->execute();
$house_bookings = $house_stmt->fetchAll();

// ============================================================
// ✅ Get Tour Bookings
// ============================================================
$tour_query = "SELECT b.*, t.tour_name, COALESCE(u.$name_column, 'Unknown Guest') as guest_name, COALESCE(u.email, '') as email FROM tour_bookings b LEFT JOIN tours t ON b.tour_id = t.id LEFT JOIN guests g ON b.guest_id = g.id LEFT JOIN users u ON g.user_id = u.id WHERE (b.package_id IS NULL OR b.package_id = 0)";
if($status_filter == 'rebook' || $status_filter == 'pending_rebook') {
    $tour_query .= " AND 1=0";
} elseif($status_filter == 'cancelled') {
    $tour_query .= " AND b.booking_status = 'cancelled'";
} elseif($status_filter == 'history') {
    $tour_query .= " AND b.booking_status IN ('completed', 'cancelled')";
} elseif($status_filter != 'all') {
    $tour_query .= ($status_filter === 'paid') ? " AND b.payment_status $paid_filter_sql" : " AND b.payment_status = :status";
}
if($search) $tour_query .= " AND (b.reference_number LIKE :search OR COALESCE(u.$name_column, '') LIKE :search OR t.tour_name LIKE :search)";
$tour_query .= " ORDER BY b.created_at DESC";

$tour_stmt = $pdo->prepare($tour_query);
if($status_filter != 'all' && $status_filter != 'rebook' && $status_filter != 'pending_rebook' && $status_filter != 'cancelled' && $status_filter != 'history' && $status_filter != 'paid') $tour_stmt->bindValue(':status', $status_filter);
if($search) $tour_stmt->bindValue(':search', "%$search%");
$tour_stmt->execute();
$tour_bookings = $tour_stmt->fetchAll();

// ============================================================
// ✅ GET FOOD BOOKINGS
// ============================================================
$food_query = "SELECT b.*, f.name as food_name, COALESCE(u.$name_column, 'Unknown Guest') as guest_name, COALESCE(u.email, '') as email FROM food_bookings b LEFT JOIN food_items f ON b.food_id = f.id LEFT JOIN guests g ON b.guest_id = g.id LEFT JOIN users u ON g.user_id = u.id WHERE (b.package_id IS NULL OR b.package_id = 0)";
if($status_filter == 'rebook' || $status_filter == 'pending_rebook') {
    $food_query .= " AND 1=0";
} elseif($status_filter == 'cancelled') {
    $food_query .= " AND b.booking_status = 'cancelled'";
} elseif($status_filter == 'history') {
    $food_query .= " AND b.booking_status IN ('completed', 'cancelled')";
} elseif($status_filter != 'all') {
    $food_query .= ($status_filter === 'paid') ? " AND b.payment_status $paid_filter_sql" : " AND b.payment_status = :status";
}
if($search) $food_query .= " AND (b.reference_number LIKE :search OR COALESCE(u.$name_column, '') LIKE :search OR f.name LIKE :search)";
$food_query .= " ORDER BY b.created_at DESC";

$food_stmt = $pdo->prepare($food_query);
if($status_filter != 'all' && $status_filter != 'rebook' && $status_filter != 'pending_rebook' && $status_filter != 'cancelled' && $status_filter != 'history' && $status_filter != 'paid') $food_stmt->bindValue(':status', $status_filter);
if($search) $food_stmt->bindValue(':search', "%$search%");
$food_stmt->execute();
$food_bookings = $food_stmt->fetchAll();

// ============================================================
// ✅✅✅ GET PACKAGE BOOKINGS
// ============================================================
$package_bookings = [];
try {
    $pkg_query = "SELECT p.*, 
                    COALESCE(u.$name_column, 'Unknown Guest') as guest_name, 
                    COALESCE(u.email, '') as email,
                    h.house_name, hb.reference_number as house_ref,
                    hb.check_in_date AS house_check_in,
                    hb.check_in_time AS house_check_in_time,
                    hb.check_out_date AS house_check_out,
                    hb.check_out_time AS house_check_out_time,
                    hb.number_of_guests AS house_guests,
                    hb.guest_names AS house_guest_names,
                    t.tour_name, tb.reference_number as tour_ref,
                    tb.booking_date AS tour_date,
                    tb.preferred_time AS tour_time,
                    tb.number_of_guests AS tour_guests,
                    tb.guest_name AS tour_guest_name,
                    tb.contact_number AS tour_contact,
                    tb.special_requests AS tour_special_requests,
                    f.name as food_name, fb.reference_number as food_ref,
                    fb.size_variant AS food_size,
                    fb.preferred_date AS food_date,
                    fb.preferred_time AS food_time,
                    fb.quantity AS food_qty,
                    fb.number_of_persons AS food_persons,
                    fb.contact_number AS food_contact,
                    fb.fulfillment_method AS food_fulfillment,
                    fb.delivery_address AS food_delivery_address,
                    fb.special_requests AS food_special_requests
                  FROM package_bookings p
                  LEFT JOIN guests g ON p.guest_id = g.id
                  LEFT JOIN users u ON g.user_id = u.id
                  LEFT JOIN house_bookings hb ON p.house_booking_id = hb.id
                  LEFT JOIN houses h ON hb.house_id = h.id
                  LEFT JOIN tour_bookings tb ON p.tour_booking_id = tb.id
                  LEFT JOIN tours t ON tb.tour_id = t.id
                  LEFT JOIN food_bookings fb ON p.food_booking_id = fb.id
                  LEFT JOIN food_items f ON fb.food_id = f.id
                  WHERE 1=1";

    if ($status_filter == 'cancelled') {
        $pkg_query .= " AND p.booking_status = 'cancelled'";
    } elseif ($status_filter == 'history') {
        $pkg_query .= " AND p.booking_status IN ('completed', 'cancelled')";
    } elseif ($status_filter != 'all' && $status_filter != 'rebook' && $status_filter != 'pending_rebook' && $status_filter != 'pending') {
        $pkg_query .= ($status_filter === 'paid') ? " AND p.payment_status $paid_filter_sql" : " AND p.payment_status = :status";
    } elseif ($status_filter == 'pending') {
        $pkg_query .= " AND p.payment_status = 'pending'";
    } else {
        $pkg_query .= " AND p.booking_status != 'cancelled'";
    }

    if ($search) {
        $pkg_query .= " AND (p.reference_number LIKE :search OR COALESCE(u.$name_column, '') LIKE :search)";
    }
    $pkg_query .= " ORDER BY p.created_at DESC";

    $pkg_stmt = $pdo->prepare($pkg_query);
    if ($status_filter != 'all' && $status_filter != 'rebook' && $status_filter != 'pending_rebook' && $status_filter != 'cancelled' && $status_filter != 'pending' && $status_filter != 'history' && $status_filter != 'paid') $pkg_stmt->bindValue(':status', $status_filter);
    if ($search) $pkg_stmt->bindValue(':search', "%$search%");
    $pkg_stmt->execute();
    $package_bookings = $pkg_stmt->fetchAll();
} catch (PDOException $e) {
    error_log("PACKAGE QUERY ERROR: " . $e->getMessage());
    $package_bookings = [];
}

// Who cancelled (from the audit log) — shown in the booking details. Automatic expiries have no actor.
$annotateCancelledBy = function (string $type, array $rows) use ($pdo): array {
    $ids = [];
    foreach ($rows as $r) { if (($r['booking_status'] ?? '') === 'cancelled') $ids[] = $r['id']; }
    if (!$ids) return $rows;
    $who = PaymentService::cancellationActors($pdo, $type, $ids);
    foreach ($rows as $k => $r) {
        if (($r['booking_status'] ?? '') !== 'cancelled') continue;
        $rows[$k]['cancelled_by'] = $who[(int)$r['id']]
            ?? (strpos((string)($r['cancellation_reason'] ?? ''), 'Expired:') === 0 ? 'System (automatic)' : '');
    }
    return $rows;
};
$house_bookings   = $annotateCancelledBy('house', $house_bookings);
$tour_bookings    = $annotateCancelledBy('tour', $tour_bookings);
$food_bookings    = $annotateCancelledBy('food', $food_bookings);
$package_bookings = $annotateCancelledBy('package', $package_bookings);

// ============================================================
// ✅ REBOOK FILTER — FIXED: base sa rebooked_at/rebook_confirmed_at
// ============================================================
$is_rebook = function($b) { return (!empty($b['rebooked_at']) || (!empty($b['rebook_count']) && (int)$b['rebook_count'] > 0)); };
$rebooked_house = array_values(array_filter($house_bookings, $is_rebook));

// ✅ FIX: Pending rebook = may rebooked_at PERO wala pang rebook_confirmed_at
// Kahit booking_status ay 'confirmed', kung may rebooked_at at walang rebook_confirmed_at, PENDING pa rin
$pending_rebooks = array_values(array_filter($rebooked_house, function($b) {
    if ($b['booking_status'] === 'cancelled') return false;
    if ($b['booking_status'] === 'completed') return false;
    // May rebook request pero hindi pa na-confirm
    if (!empty($b['rebooked_at']) && empty($b['rebook_confirmed_at'])) return true;
    // Booking status = pending (bago pa ma-confirm payment)
    if ($b['booking_status'] === 'pending' && (!empty($b['rebook_count']) && (int)$b['rebook_count'] > 0)) return true;
    return false;
}));

usort($pending_rebooks, function($a, $b) {
    $tA = !empty($a['rebooked_at']) ? strtotime($a['rebooked_at']) : strtotime($a['created_at']);
    $tB = !empty($b['rebooked_at']) ? strtotime($b['rebooked_at']) : strtotime($b['created_at']);
    return $tB - $tA;
});

// ✅ Confirmed rebooks = may rebook_confirmed_at
$confirmed_rebooks = array_values(array_filter($rebooked_house, function($b) { 
    return !empty($b['rebook_confirmed_at']) && $b['booking_status'] !== 'cancelled'; 
}));

$cancelled_rebooks = array_values(array_filter($rebooked_house, function($b) { return $b['booking_status'] == 'cancelled'; }));
$completed_rebooks = array_values(array_filter($rebooked_house, function($b) { return $b['booking_status'] == 'completed'; }));

// ✅ FIX: Pending rebooks lang talaga ang bilang
$rebook_pending_count = count($pending_rebooks);

// ============================================================
// ✅ ACTIVE = confirmed/pending lang (confirmed na walang pending rebook)
// ============================================================
$is_active = function($b) {
    return !in_array($b['booking_status'], ['cancelled', 'completed']);
};

$active_house   = array_values(array_filter($house_bookings,   $is_active));
$active_tour    = array_values(array_filter($tour_bookings,    $is_active));
$active_food    = array_values(array_filter($food_bookings,    $is_active));
$active_package = array_values(array_filter($package_bookings, $is_active));

// ============================================================
// ✅ HISTORY = completed + cancelled lang
// ============================================================
$is_history = function($b) {
    return in_array($b['booking_status'], ['cancelled', 'completed']);
};

$history_house   = array_values(array_filter($house_bookings,   $is_history));
$history_tour    = array_values(array_filter($tour_bookings,    $is_history));
$history_food    = array_values(array_filter($food_bookings,    $is_history));
$history_package = array_values(array_filter($package_bookings, $is_history));

$total_history = count($history_house) + count($history_tour) + count($history_food) + count($history_package);

// ============================================================
// ✅ GET BLOCKED DATES COUNT
// ============================================================
$total_blocked = 0;
try { $total_blocked = $pdo->query("SELECT COUNT(*) FROM blocked_dates WHERE block_date >= CURDATE()")->fetchColumn(); } catch(PDOException $e) {}

// Get stats
$pending_house = $pdo->query("SELECT COUNT(*) FROM house_bookings WHERE (payment_status = 'pending' OR (booking_status = 'pending' AND (rebooked_at IS NOT NULL OR rebook_count > 0))) AND booking_status NOT IN ('cancelled', 'completed') AND (package_id IS NULL OR package_id = 0)")->fetchColumn();
$pending_tour = $pdo->query("SELECT COUNT(*) FROM tour_bookings WHERE payment_status = 'pending' AND booking_status NOT IN ('cancelled', 'completed') AND (package_id IS NULL OR package_id = 0)")->fetchColumn();
$pending_food = $pdo->query("SELECT COUNT(*) FROM food_bookings WHERE payment_status = 'pending' AND booking_status NOT IN ('cancelled', 'completed') AND (package_id IS NULL OR package_id = 0)")->fetchColumn();
$pending_package = 0;
try { $pending_package = $pdo->query("SELECT COUNT(*) FROM package_bookings WHERE payment_status = 'pending' AND booking_status NOT IN ('cancelled', 'completed')")->fetchColumn(); } catch (PDOException $e) {}

$paid_house = $pdo->query("SELECT COUNT(*) FROM house_bookings WHERE payment_status IN ('reservation_paid','paid') AND booking_status NOT IN ('cancelled', 'completed') AND (package_id IS NULL OR package_id = 0)")->fetchColumn();
$paid_tour = $pdo->query("SELECT COUNT(*) FROM tour_bookings WHERE payment_status IN ('reservation_paid','paid') AND booking_status NOT IN ('cancelled', 'completed') AND (package_id IS NULL OR package_id = 0)")->fetchColumn();
$paid_food = $pdo->query("SELECT COUNT(*) FROM food_bookings WHERE payment_status IN ('reservation_paid','paid') AND booking_status NOT IN ('cancelled', 'completed') AND (package_id IS NULL OR package_id = 0)")->fetchColumn();
$paid_package = 0;
try { $paid_package = $pdo->query("SELECT COUNT(*) FROM package_bookings WHERE payment_status IN ('reservation_paid','paid') AND booking_status NOT IN ('cancelled', 'completed')")->fetchColumn(); } catch (PDOException $e) {}

$total_active = count($active_house) + count($active_tour) + count($active_food) + count($active_package);
$pending_count = $pending_house + $pending_tour + $pending_food + $pending_package;
$paid_count = $paid_house + $paid_tour + $paid_food + $paid_package;


// ============================================================
// Prepare calendar events (active only) — WITH TIMES
// ============================================================
$calendar_events = [];
foreach($active_house as $booking) {
    $color = PaymentService::isSecured($booking) ? '#10b981' : '#f59e0b';
    $calendar_events[] = [
        'title' => '🏠 ' . htmlspecialchars($booking['guest_name']),
        'start' => $booking['check_in_date'],
        'end' => date('Y-m-d', strtotime($booking['check_out_date'] . ' +1 day')),
        'color' => $color,
        'extendedProps' => [
            'reference' => $booking['reference_number'], 'type' => 'house', 'id' => $booking['id'],
            'guest_name' => $booking['guest_name'], 'item_name' => $booking['house_name'],
            'check_in' => $booking['check_in_date'], 
            'check_in_time' => $booking['check_in_time'] ?? '',
            'check_out' => $booking['check_out_date'],
            'check_out_time' => $booking['check_out_time'] ?? '',
            'guests' => $booking['number_of_guests'], 'total' => $booking['total_amount'],
            'status' => $booking['booking_status'], 'payment' => $booking['payment_status'],
            'email' => $booking['email'], 'proof' => $booking['payment_proof'],
            'gcash_reference' => $booking['gcash_reference'] ?? '', 'guest_names' => $booking['guest_names'] ?? ''
        ]
    ];
}
foreach($active_tour as $booking) {
    $color = PaymentService::isSecured($booking) ? '#10b981' : '#f59e0b';
    $calendar_events[] = [
        'title' => '🏖️ ' . htmlspecialchars($booking['guest_name']),
        'start' => $booking['booking_date'], 'end' => date('Y-m-d', strtotime($booking['booking_date'] . ' +1 day')),
        'color' => $color,
        'extendedProps' => [
            'reference' => $booking['reference_number'], 'type' => 'tour', 'id' => $booking['id'],
            'guest_name' => $booking['guest_name'], 'item_name' => $booking['tour_name'],
            'check_in' => $booking['booking_date'], 'check_out' => $booking['booking_date'],
            'guests' => $booking['number_of_guests'], 'total' => $booking['total_amount'],
            'status' => $booking['booking_status'], 'payment' => $booking['payment_status'],
            'email' => $booking['email'], 'proof' => $booking['payment_proof'],
            'gcash_reference' => $booking['gcash_reference'] ?? ''
        ]
    ];
}
foreach($active_food as $booking) {
    $color = PaymentService::isSecured($booking) ? '#10b981' : '#f59e0b';
    $food_date = !empty($booking['preferred_date']) ? $booking['preferred_date'] : date('Y-m-d', strtotime($booking['created_at']));
    $calendar_events[] = [
        'title' => '🍽️ ' . htmlspecialchars($booking['guest_name']),
        'start' => $food_date, 'end' => date('Y-m-d', strtotime($food_date . ' +1 day')),
        'color' => $color,
        'extendedProps' => [
            'reference' => $booking['reference_number'], 'type' => 'food', 'id' => $booking['id'],
            'guest_name' => $booking['guest_name'], 'item_name' => $booking['food_name'],
            'check_in' => $food_date, 'check_out' => $food_date,
            'guests' => $booking['number_of_persons'] ?? 1, 'total' => $booking['total_amount'],
            'status' => $booking['booking_status'], 'payment' => $booking['payment_status'],
            'email' => $booking['email'], 'proof' => $booking['payment_proof'],
            'gcash_reference' => $booking['gcash_reference'] ?? '',
            'fulfillment_method' => $booking['fulfillment_method'] ?? 'pickup',
            'delivery_address' => $booking['delivery_address'] ?? '',
            'contact_number' => $booking['contact_number'] ?? ''
        ]
    ];
}
foreach($active_package as $pkg) {
    $color = PaymentService::isSecured($pkg) ? '#10b981' : '#f59e0b';
    $pkg_date = date('Y-m-d', strtotime($pkg['created_at']));
    $items = [];
    if (!empty($pkg['house_name'])) $items[] = '🏠 ' . $pkg['house_name'];
    if (!empty($pkg['tour_name'])) $items[] = '🏖️ ' . $pkg['tour_name'];
    if (!empty($pkg['food_name'])) $items[] = '🍽️ ' . $pkg['food_name'];
    $calendar_events[] = [
        'title' => '📦 ' . htmlspecialchars($pkg['guest_name']) . ' (' . count($items) . ' items)',
        'start' => $pkg_date, 'end' => date('Y-m-d', strtotime($pkg_date . ' +1 day')),
        'color' => $color,
        'extendedProps' => [
            'reference' => $pkg['reference_number'], 'type' => 'package', 'id' => $pkg['id'],
            'guest_name' => $pkg['guest_name'], 'item_name' => implode(' • ', $items),
            'check_in' => $pkg_date, 'check_out' => $pkg_date, 'guests' => 1,
            'total' => $pkg['grand_total'], 'status' => $pkg['booking_status'],
            'payment' => $pkg['payment_status'], 'email' => $pkg['email'],
            'proof' => $pkg['payment_proof'], 'gcash_reference' => $pkg['gcash_reference'] ?? ''
        ]
    ];
}

// ============================================================
// ✅ ADD BLOCKED DATES AS CALENDAR EVENTS (gray)
// ============================================================
try {
    $stmt = $pdo->query("SELECT bd.*, 
        CASE 
            WHEN bd.item_type = 'house' THEN (SELECT house_name FROM houses WHERE id = bd.item_id)
            WHEN bd.item_type = 'tour' THEN (SELECT tour_name FROM tours WHERE id = bd.item_id)
        END as item_name
        FROM blocked_dates bd
        WHERE bd.block_date >= CURDATE()
        ORDER BY bd.block_date ASC");
    $blocked_for_calendar = $stmt->fetchAll();
    foreach($blocked_for_calendar as $bd) {
        $calendar_events[] = [
            'title' => '🚫 BLOCKED: ' . htmlspecialchars((string)($bd['item_name'] ?? '')),
            'start' => $bd['block_date'],
            'end' => date('Y-m-d', strtotime($bd['block_date'] . ' +1 day')),
            'color' => '#64748b',
            'extendedProps' => [
                'type' => 'blocked',
                'item_name' => $bd['item_name'],
                'block_type' => $bd['block_type'],
                'reason' => $bd['reason'] ?? '',
                'block_date' => $bd['block_date'],
                'item_type' => $bd['item_type'],
                'id' => $bd['id']
            ]
        ];
    }
} catch(PDOException $e) {}

// Handle Logout
if(isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth', "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out", (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

// Helper functions
function formatDateDisplay($dateStr) {
    if (!$dateStr) return 'N/A';
    try { return (new DateTime($dateStr))->format('M d, Y'); } catch(Exception $e) { return $dateStr; }
}
function formatDateTimeDisplay($dateStr) {
    if (!$dateStr) return 'N/A';
    try { return (new DateTime($dateStr))->format('M d, Y h:i A'); } catch(Exception $e) { return $dateStr; }
}
function formatTimeDisplay($timeStr) {
    if (!$timeStr) return 'N/A';
    try { return (new DateTime($timeStr))->format('h:i A'); } catch(Exception $e) { return $timeStr; }
}
function formatGuestNames($guest_names) {
    if (empty($guest_names)) return 'No guest names recorded';
    if (is_string($guest_names)) {
        if (($guest_names[0] == '[' || $guest_names[0] == '{')) {
            try {
                $decoded = json_decode($guest_names, true);
                if (is_array($decoded)) {
                    if (isset($decoded[0]) && is_string($decoded[0])) return implode(', ', $decoded);
                    $names = array_values(array_filter($decoded, function($v) { return is_string($v) && !empty($v); }));
                    if (!empty($names)) return implode(', ', $names);
                }
            } catch(Exception $e) {}
        }
        if (strpos($guest_names, "\n") !== false) return implode(', ', array_filter(array_map('trim', explode("\n", $guest_names))));
        if (strpos($guest_names, ',') !== false) return implode(', ', array_filter(array_map('trim', explode(',', $guest_names))));
        return $guest_names;
    }
    return 'No guest names recorded';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Booking Management - <?php echo $is_admin ? 'Admin' : 'Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }
    .app-container { display: flex; min-height: 100vh; }

    /* SIDEBAR */
    .sidebar { width: 280px; background: #0B2447; box-shadow: 4px 0 20px rgba(0,0,0,0.2); padding: 25px 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); transition: transform 0.3s ease, width 0.3s ease; z-index: 100; flex-shrink: 0; }
    .sidebar::-webkit-scrollbar { width: 5px; }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }
    .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
    .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
    .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); overflow: hidden; }
    .sidebar-header .logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; border-radius: 14px; background: white; }
    .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
    .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; }
    .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; transition: all 0.2s; }
    .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }
    .sidebar-header .role-badge { display: inline-block; margin-top: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .sidebar-header .role-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
    .sidebar-header .role-badge.staff { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.2); }

    .nav-menu { list-style: none; padding: 0; margin: 0; }
    .nav-item { margin-bottom: 2px; position: relative; }
    .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
    .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
    .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
    .nav-link.active { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
    .nav-link.active i { color: #7bb8f0; }
    .nav-link .nav-badge { margin-left: auto; background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 1px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
    .nav-link .nav-badge.blocked { background: rgba(100, 116, 139, 0.3); color: #cbd5e1; }
    .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

    .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 99; opacity: 0; transition: opacity 0.3s ease; }
    .sidebar-overlay.active { display: block; opacity: 1; }

    .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.3); align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
    .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
    body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8); }

    @media (max-width: 1024px) {
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); width: 280px; z-index: 1000; box-shadow: none; border-radius: 0; }
        .sidebar.open { transform: translateX(0); box-shadow: 4px 0 30px rgba(0,0,0,0.4); }
        .menu-toggle { display: flex; }
        .sidebar-overlay.active { display: block; }
        .main-content { padding: 70px 16px 20px !important; }
        .sidebar-close-btn { display: flex; }
    }
    @media (max-width: 480px) {
        .sidebar { width: 85%; max-width: 300px; }
        .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
        .main-content { padding: 60px 12px 16px !important; }
    }

    /* MAIN */
    .main-content { flex: 1; padding: 20px 30px 30px; min-width: 0; width: 100%; }
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(11, 36, 71, 0.1); flex-wrap: wrap; gap: 10px; }
    .top-bar .page-title h1 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
    .top-bar .page-title h1 i { color: #4DA6D9; }
    .top-bar .page-title p { color: #4a6a8c; font-size: 13px; margin: 2px 0 0 0; }
    .top-bar .user-profile { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
    .top-bar .user-profile .avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 18px; border: 2px solid rgba(77, 166, 217, 0.2); flex-shrink: 0; overflow: hidden; }
    .top-bar .user-profile .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .top-bar .user-profile .user-name { color: #0B2447; font-weight: 600; font-size: 14px; }
    .top-bar .user-profile .user-role { color: #4a6a8c; font-size: 12px; }

    .top-bar .page-title h1 .title-badge-mobile, .top-bar .page-title h1 .avatar-mobile { display: none; }
    .title-badge-mobile { font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 20px; display: inline-flex; align-items: center; gap: 5px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
    .title-badge-mobile.admin { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1.5px solid rgba(239, 68, 68, 0.25); }
    .title-badge-mobile.staff { background: rgba(251, 191, 36, 0.15); color: #d97706; border: 1.5px solid rgba(251, 191, 36, 0.3); }
    .avatar-mobile { display: inline-flex; width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 13px; border: 2px solid #4DA6D9; flex-shrink: 0; box-shadow: 0 3px 10px rgba(77, 166, 217, 0.25); overflow: hidden; }
    .avatar-mobile img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

    @media (max-width: 768px) {
        .top-bar { position: relative; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px; padding-bottom: 15px; padding-left: 100px; padding-right: 100px; min-height: 130px; }
        .top-bar .page-title { display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%; }
        .top-bar .page-title h1 { font-size: 20px; display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 8px; }
        .top-bar .page-title h1 > i { font-size: 18px; }
        .top-bar .page-title p { font-size: 12px; margin: 0; }
        .top-bar .page-title h1 .title-badge-mobile { display: inline-flex; }
        .top-bar .page-title h1 .avatar-mobile { display: inline-flex; position: absolute; top: 50%; right: 14px; transform: translateY(-50%); width: 80px; height: 80px; font-size: 32px; border: 4px solid #4DA6D9; box-shadow: 0 6px 20px rgba(77, 166, 217, 0.4), 0 0 0 4px rgba(255, 255, 255, 1), 0 0 0 7px rgba(77, 166, 217, 0.4); }
        .top-bar .user-profile { display: none !important; }
    }

    .page-title-banner { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); border-radius: 20px; padding: 30px 35px; margin-bottom: 30px; color: white; box-shadow: 0 10px 30px rgba(11, 36, 71, 0.15); position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.1); }
    .page-title-banner::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); animation: rotate 20s linear infinite; }
    @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .page-title-banner .banner-content { position: relative; z-index: 1; }
    .page-title-banner h1 { font-size: 28px; font-weight: 700; margin-bottom: 5px; }
    .page-title-banner h1 i { margin-right: 10px; opacity: 0.9; }
    .page-title-banner .underline { width: 60px; height: 3px; background: white; border-radius: 2px; margin-top: 8px; opacity: 0.5; }
    .page-title-banner p { opacity: 0.85; font-size: 14px; margin: 8px 0 0 0; }
    .page-title-banner p .staff-notice { display: inline-block; background: rgba(251, 191, 36, 0.2); color: #fbbf24; padding: 2px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; border: 1px solid rgba(251, 191, 36, 0.2); margin-top: 5px; }
    @media (max-width: 768px) { .page-title-banner { padding: 20px; text-align: center; border-radius: 16px; } .page-title-banner .underline { margin: 8px auto 0; } .page-title-banner h1 { font-size: 22px; } }

    /* STATS */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-bottom: 30px; }
.stat-card{

    background:white;

    border-radius:22px;

    padding:24px 20px;

    transition:.25s ease;

    border:1px solid #e8f0fe;

    box-shadow:
    0 12px 30px rgba(6,38,61,.08);

    text-decoration:none;

    color:#0B2447;

    display:block;

}


.stat-card:hover{

    transform:translateY(-6px);

    box-shadow:
    0 20px 45px rgba(6,38,61,.15);

}    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(77, 166, 217, 0.3); }
    .stat-icon { width: 44px; height: 44px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.1); }
.stat-number{
    font-size:36px;
    font-weight:800;
    color:#0B2447;
    margin-top:18px;
}


.stat-label{
    color:#334155;
    font-size:14px;
    font-weight:700;
    margin-top:5px;
}
    @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; } 
    .stat-card { padding: 16px 14px; border-radius: 12px; } .stat-number { font-size: 20px; } .stat-icon { width: 36px; height: 36px; font-size: 14px; } .stat-label { font-size: 11px; } }

    /* FILTERS */
    .view-toggle { background: white; border-radius: 16px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; box-shadow: 0 10px 30px rgba(0,0,0,0.06); border: 1px solid #e8f0fe; }
    .view-toggle .view-label { font-weight: 600; color: #0B2447; font-size: 14px; }
    .view-toggle .btn-view { padding: 8px 20px; border-radius: 10px; border: 2px solid #e8f0fe; background: white; cursor: pointer; font-weight: 600; transition: all 0.3s; text-decoration: none; color: #4a6a8c; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
    .view-toggle .btn-view:hover { background: #f0f7fb; border-color: #4DA6D9; transform: translateY(-2px); }
    .view-toggle .btn-view.active { background: #4DA6D9; color: white; border-color: #4DA6D9; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); }

    .filter-bar { background: white; border-radius: 16px; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center; justify-content: space-between; box-shadow: 0 10px 30px rgba(0,0,0,0.06); border: 1px solid #e8f0fe; }
    .filter-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
    .filter-btn { padding: 6px 16px; border-radius: 20px; border: 1px solid #e8f0fe; background: white; cursor: pointer; transition: all 0.2s; text-decoration: none; color: #0B2447; font-weight: 500; font-size: 12px; }
    .filter-btn.active { background: #4DA6D9; color: white; border-color: #4DA6D9; }
    .filter-btn:hover { background: #f0f7fb; }
    .search-box { display: flex; gap: 8px; flex: 1; min-width: 200px; max-width: 400px; }
    .search-box input { padding: 8px 14px; border: 2px solid #e8f0fe; border-radius: 10px; width: 100%; font-size: 13px; }
    .search-box input:focus { outline: none; border-color: #4DA6D9; }
    .search-box button { padding: 8px 16px; background: #4DA6D9; color: white; border: none; border-radius: 10px; cursor: pointer; font-weight: 500; white-space: nowrap; }
    .btn-clear { background: #64748b; color: white; padding: 8px 14px; text-decoration: none; border-radius: 10px; font-size: 12px; white-space: nowrap; }
    .btn-clear:hover { background: #475569; color: white; }
    @media (max-width: 768px) { .filter-bar { flex-direction: column; align-items: stretch; padding: 15px; } .search-box { max-width: 100%; } }

    /* CARDS */
    .card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); margin-bottom: 30px; border: 1px solid #e8f0fe; overflow: hidden; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; flex-wrap: wrap; gap: 15px; }
    .card-header h2 { font-size: 17px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; margin: 0; }
    .card-header h2 i { color: #4DA6D9; background: #eef2ff; padding: 8px; border-radius: 8px; font-size: 14px; }
    @media (max-width: 768px) { .card { padding: 18px 15px; border-radius: 14px; } .card-header { flex-direction: column; align-items: stretch; gap: 10px; } .card-header h2 { font-size: 15px; } }

    #calendar { min-height: 600px; padding: 10px; }
    .fc { font-family: 'Inter', sans-serif !important; }
    @media (max-width: 768px) { #calendar { padding: 4px; min-height: 480px; } .fc .fc-toolbar { flex-wrap: wrap; justify-content: center; gap: 8px; } .fc .fc-toolbar-title { font-size: 1.1rem; } .fc .fc-view-harness { overflow-x: auto; } }
    .fc-event { cursor: pointer; border-radius: 10px !important; padding: 6px 10px !important; font-size: 12px !important; border: none !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .fc-modern-event { line-height: 1.25; font-size: 11px; padding: 4px 6px; white-space: normal; }
    .fc-modern-event strong { font-size: 10px; letter-spacing: .4px; }
    .fc-modern-event.blocked { color: #334155; }

    .fc-daygrid-day { min-height: 80px !important; }
    .fc-button-primary { background: #4DA6D9 !important; border: none !important; }
    .calendar-legend { display: flex; gap: 15px; flex-wrap: wrap; padding: 10px 0; }
    .calendar-legend .legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; }
    .calendar-legend .legend-color { width: 16px; height: 16px; border-radius: 6px; }
    .calendar-legend .legend-color.paid { background: #10b981; }
    .calendar-legend .legend-color.pending { background: #f59e0b; }
    .calendar-legend .legend-color.blocked { background: #64748b; }

    /* BOOKING TABS */
    .booking-tabs { display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
    .booking-tab { padding: 10px 20px; border-radius: 12px; cursor: pointer; font-weight: 600; color: #4a6a8c; transition: all 0.3s; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border: 2px solid transparent; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; }
    .booking-tab:hover { background: #f0f7fb; color: #4DA6D9; }
    .booking-tab.active { background: #4DA6D9; color: white; border-color: #4DA6D9; }
    .booking-tab.package-tab.active { background: #0ea5e9; border-color: #0ea5e9; }
    .booking-tab.rebook-tab.active { background: #8b5cf6; border-color: #8b5cf6; }
    .booking-tab.history-tab.active { background: #64748b; border-color: #64748b; }
    .booking-tab .tab-count { background: rgba(255,255,255,0.25); padding: 1px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .booking-tab.active .tab-count { background: rgba(255,255,255,0.3); }
    .booking-tab.package-tab:not(.active) .tab-count { background: #e0f2fe; color: #0369a1; }
    .booking-tab.rebook-tab:not(.active) .tab-count { background: #fef3c7; color: #92400e; }
    .booking-tab.history-tab:not(.active) .tab-count { background: #f1f5f9; color: #475569; }
    .booking-tab.rebook-tab.has-pending .tab-count { background: #f59e0b !important; color: white !important; animation: pulseCount 2s infinite; }
    @keyframes pulseCount { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
    .rebook-stat-alert { box-shadow: 0 0 0 2px #f59e0b, 0 4px 15px rgba(245, 158, 11, 0.2); }
    .rebook-banner { background: linear-gradient(135deg, #fffbeb, #fef3c7); border: 1px solid #f59e0b; border-left: 5px solid #d97706; padding: 16px 20px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.15); }
    @media (max-width: 768px) { .booking-tabs { flex-direction: column; } .booking-tab { text-align: center; padding: 12px; justify-content: center; } }

    .booking-section { display: none; }
    .booking-section.active { display: block; }

    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 0 -5px; }
    table { width: 100%; border-collapse: collapse; min-width: 700px; }
    th { text-align: left; padding: 10px 12px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
    td { padding: 10px 12px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 12px; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover { background: #f8fafc; }
    @media (max-width: 768px) { th { font-size: 9px; padding: 8px; } td { font-size: 11px; padding: 8px; } table { min-width: 650px; } }

    .booking-time-row { font-size: 10.5px; color: #64748b; margin-top: 2px; display: flex; align-items: center; gap: 4px; }
    .booking-time-row i { color: #4DA6D9; font-size: 10px; }

    .booking-cards-mobile { display: none; flex-direction: column; gap: 12px; }
    @media (max-width: 768px) {
        .table-responsive.desktop-table { display: none !important; }
        .booking-cards-mobile { display: flex; }
    }
    .booking-card-mobile { background: white; border-radius: 14px; padding: 14px; border: 1px solid #e8f0fe; box-shadow: 0 4px 12px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 10px; }
    .booking-card-mobile .card-top-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
    .booking-card-mobile .card-ref { font-weight: 700; color: #0B2447; font-size: 13px; word-break: break-all; }
    .booking-card-mobile .card-badges { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
    .booking-card-mobile .card-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: #475569; }
    .booking-card-mobile .card-row i { width: 16px; color: #4DA6D9; flex-shrink: 0; text-align: center; }
    .booking-card-mobile .card-row .card-label { color: #94a3b8; font-size: 10px; font-weight: 700; min-width: 46px; text-transform: uppercase; }
    .booking-card-mobile .card-row .card-value { font-weight: 600; color: #0B2447; word-break: break-word; flex: 1; min-width: 0; }
    .booking-card-mobile .card-proof { display: flex; align-items: center; gap: 10px; padding-top: 8px; border-top: 1px solid #f1f5f9; }
    .booking-card-mobile .card-proof-thumb { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 1px solid #e2e8f0; background: #f8fafc; cursor: pointer; flex-shrink: 0; }
    .booking-card-mobile .card-proof-thumb.no-proof { display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 18px; }
    .booking-card-mobile .card-proof-info { flex: 1; min-width: 0; }
    .booking-card-mobile .card-proof-info .card-label { font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-bottom: 2px; }
    .booking-card-mobile .card-proof-info .card-gcash { font-family: 'Courier New', monospace; font-size: 12px; font-weight: 700; color: #065f46; word-break: break-all; }
    .booking-card-mobile .card-actions { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 8px; border-top: 1px solid #f1f5f9; }
    .booking-card-mobile .btn-card-action { flex: 1; min-width: 100px; min-height: 42px; padding: 10px 14px; border: none; border-radius: 10px; font-weight: 700; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; transition: all 0.2s; touch-action: manipulation; }
    .booking-card-mobile .btn-more { background: linear-gradient(135deg, #4DA6D9, #3a8bbf); color: white; }
    .booking-card-mobile .btn-reject-mobile { background: #ef4444; color: white; }
    .booking-card-mobile .btn-confirm-mobile { background: #10b981; color: white; }
    .booking-card-mobile .btn-rebook-confirm-mobile { background: linear-gradient(135deg, #8b5cf6, #a78bfa); color: white; }

    /* BADGES */
    .badge { padding: 3px 10px; border-radius: 20px; font-size: 9px; font-weight: 600; display: inline-block; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
    .badge-success { background: #e6f7e6; color: #10b981; }
    .badge-warning { background: #fef3c7; color: #f59e0b; }
    .badge-danger { background: #fee2e2; color: #ef4444; }
    .badge-info { background: #dbeafe; color: #3b82f6; }
    .badge-cancelled { background: #fee2e2; color: #ef4444; }
    .badge-purple { background: #f3e8ff; color: #8b5cf6; }
    .badge-package { background: #e0f2fe; color: #0369a1; }
    .badge-delivery { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
    .badge-pickup { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .contact-link { color: #0B2447; font-weight: 600; text-decoration: none; font-size: 12px; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; }
    .contact-link:hover { color: #4DA6D9; text-decoration: underline; }
    .gcash-ref-badge { display: inline-flex; align-items: center; gap: 4px; background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; letter-spacing: 0.5px; font-family: 'Courier New', monospace; white-space: nowrap; border: 1px solid #a7f3d0; }
    .rebook-badge { display: inline-flex; align-items: center; gap: 4px; background: linear-gradient(135deg, #8b5cf6, #a78bfa); color: white; padding: 2px 10px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; margin-left: 6px; }
    .package-badge { display: inline-flex; align-items: center; gap: 4px; background: linear-gradient(135deg, #0ea5e9, #38bdf8); color: white; padding: 2px 10px; border-radius: 20px; font-size: 9px; font-weight: 700; text-transform: uppercase; }

    /* BUTTONS */
    .btn-sm { padding: 4px 12px; font-size: 10px; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s; margin: 2px; text-decoration: none; display: inline-block; font-weight: 600; white-space: nowrap; }
    .btn-sm:hover { transform: translateY(-2px); }
    .btn-success { background: #10b981; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-info { background: #0ea5e9; color: white; }
    .btn-rebook-confirm { background: linear-gradient(135deg, #8b5cf6, #a78bfa); color: white; }
    .btn-rebook-reject { background: #ef4444; color: white; }
    .btn-cancel-booking { background: #ef4444; color: white; }
    .btn-view-booking { background: #4DA6D9; color: white; padding: 4px 14px; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; white-space: nowrap; }

    /* ALERTS */
    .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
    @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
    .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }

    .rebook-info-note { background: linear-gradient(135deg, #fef3c7, #fffbeb); border: 1px solid #fbbf24; border-radius: 10px; padding: 12px 16px; margin-top: 15px; font-size: 13px; color: #92400e; display: flex; align-items: center; gap: 10px; }
    .rebook-info-note i { color: #f59e0b; font-size: 18px; }

    /* MODALS */
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
    .modal.show { display: flex; }
    .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; padding: 25px; animation: modalSlideIn 0.3s ease; box-shadow: 0 30px 60px rgba(0,0,0,0.3); }
    @keyframes modalSlideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
    .modal-header h3 { font-size: 18px; font-weight: 700; color: #0B2447; }
    .modal-header h3 i { color: #4DA6D9; margin-right: 10px; }
    .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; transition: color 0.3s; background: none; border: none; padding: 0 10px; }
    .modal-header .close:hover { color: #ef4444; }
    .proof-image { max-width: 100%; border-radius: 10px; margin: 10px 0; border: 1px solid #e8f0fe; }

    .form-label { display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px; }
    .form-control, .form-select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
    .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }

    .footer { background: #0B2447; color: #b3d9ff; padding: 15px 0; text-align: center; margin-top: 30px; border-radius: 12px; font-size: 13px; border: 1px solid rgba(77, 166, 217, 0.15); }
    .footer i { color: #4DA6D9; }

    /* CONFIRM MODAL */
    .confirm-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; animation: confirmFadeIn 0.2s ease; }
    .confirm-modal-overlay.show { display: flex; }
    @keyframes confirmFadeIn { from { opacity: 0; } to { opacity: 1; } }
    .confirm-modal { background: white; border-radius: 24px; max-width: 420px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); animation: confirmSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border-top: 6px solid #10b981; max-height: 90vh; overflow-y: auto; }
    .confirm-modal.rebook-variant { border-top-color: #8b5cf6; }
    .confirm-modal.package-variant { border-top-color: #0ea5e9; }
    @keyframes confirmSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .confirm-modal-icon { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; animation: confirmPulse 2s ease-in-out infinite; }
    .confirm-modal.payment-variant .confirm-modal-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #10b981; }
    .confirm-modal.rebook-variant .confirm-modal-icon { background: linear-gradient(135deg, #f3e8ff, #ddd6fe); color: #8b5cf6; }
    .confirm-modal.package-variant .confirm-modal-icon { background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #0ea5e9; }
    @keyframes confirmPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
    .confirm-modal h3 { font-size: 22px; font-weight: 700; color: #0B2447; margin-bottom: 8px; }
    .confirm-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 20px; }
    .confirm-modal .confirm-ref-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; text-align: left; }
    .confirm-modal .confirm-ref-box .ref-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; padding: 3px 0; }
    .confirm-modal .confirm-ref-box .ref-row .ref-label { color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .confirm-modal .confirm-ref-box .ref-row .ref-value { font-weight: 700; color: #0B2447; font-family: 'Courier New', monospace; word-break: break-all; text-align: right; }
    .confirm-modal-warning { background: #fffbeb; border: 1px solid #fbbf24; border-left: 4px solid #d97706; border-radius: 10px; padding: 12px 14px; margin-bottom: 20px; font-size: 12.5px; color: #92400e; display: flex; align-items: flex-start; gap: 10px; text-align: left; line-height: 1.5; }
    .confirm-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-confirm-cancel, .btn-confirm-submit { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
    .btn-confirm-cancel { background: #e2e8f0; color: #475569; }
    .btn-confirm-submit { background: linear-gradient(135deg, #10b981, #059669); color: white; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
    .confirm-modal.rebook-variant .btn-confirm-submit { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
    .confirm-modal.package-variant .btn-confirm-submit { background: linear-gradient(135deg, #0ea5e9, #0284c7); }

    /* LOGOUT MODAL */
    .logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; }
    .logout-modal-overlay.show { display: flex; }
    .logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; border-top: 6px solid #ef4444; }
    .logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; }
    .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
    .logout-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
    .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
    .btn-logout-cancel { background: #e2e8f0; color: #475569; }
    .btn-logout-confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; }

    /* PACKAGE BOOKING MODAL */
    .pkg-booking-modal .modal-content { max-width: 750px; padding: 0; border-radius: 24px; overflow: hidden; }
    .pkg-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 22px 28px 18px; border-bottom: 2px solid #e8f0fe; background: #ffffff; }
    .pkg-modal-header .pkg-title { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 700; color: #0B2447; flex-wrap: wrap; }
    .pkg-modal-header .pkg-title i { color: #0ea5e9; background: #e0f2fe; padding: 8px; border-radius: 10px; font-size: 18px; }
    .pkg-modal-header .close { font-size: 32px; color: #94a3b8; cursor: pointer; transition: all 0.2s; line-height: 1; padding: 0 6px; border-radius: 8px; background: none; border: none; }
    .pkg-modal-header .close:hover { color: #ef4444; background: #fee2e2; transform: rotate(90deg); }
    .pkg-modal-body { padding: 20px 28px 28px; max-height: 80vh; overflow-y: auto; }

    .pkg-section { background: #f8fafc; border: 1px solid #e8f0fe; border-radius: 18px; padding: 18px 20px; margin-bottom: 18px; }
    .pkg-section-label { display: flex; align-items: center; gap: 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px dashed #e2e8f0; }
    .pkg-section-label i { color: #0ea5e9; font-size: 15px; background: #e0f2fe; padding: 6px; border-radius: 8px; }

    .pkg-gcash-card { display: flex; align-items: center; gap: 14px; background: linear-gradient(135deg, #ecfdf5, #d1fae5); border: 2px solid #10b981; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; }
    .pkg-gcash-icon { width: 46px; height: 46px; background: #10b981; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
    .pkg-gcash-details { flex: 1; min-width: 0; }
    .pkg-gcash-details .pkg-gcash-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #065f46; margin-bottom: 2px; }
    .pkg-gcash-details .pkg-gcash-number { font-size: 20px; font-weight: 800; color: #065f46; font-family: 'Courier New', monospace; letter-spacing: 1.5px; word-break: break-all; }
    .pkg-copy-btn { background: white; color: #10b981; border: 1.5px solid #10b981; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; }

    .pkg-proof-image-wrapper { text-align: center; position: relative; }
    .pkg-proof-image-wrapper img { max-width: 100%; max-height: 300px; border-radius: 12px; border: 1px solid #e2e8f0; background: white; cursor: pointer; }
    .pkg-proof-helper { font-size: 12px; color: #94a3b8; margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 6px; }

    .pkg-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 8px; }
    .pkg-row:last-child { border-bottom: none; }
    .pkg-row .pkg-label { display: flex; align-items: center; gap: 10px; font-weight: 600; color: #475569; font-size: 13px; flex-shrink: 0; }
    .pkg-row .pkg-label i { color: #0ea5e9; font-size: 14px; width: 18px; text-align: center; }
    .pkg-row .pkg-value { font-weight: 600; color: #0B2447; font-size: 14px; text-align: right; word-break: break-word; max-width: 60%; }

    .pkg-item-card { background: #f0f7fb; border: 1px solid #dbeafe; border-left: 4px solid #4DA6D9; border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
    .pkg-item-card:last-child { margin-bottom: 0; }
    .pkg-item-card.tour { border-left-color: #10b981; }
    .pkg-item-card.tour .pkg-item-title i, .pkg-item-card.tour .pkg-item-detail i { color: #10b981; }
    .pkg-item-card.food { border-left-color: #f59e0b; }
    .pkg-item-card.food .pkg-item-title i, .pkg-item-card.food .pkg-item-detail i { color: #f59e0b; }
    .pkg-item-card .pkg-item-title { font-weight: 700; color: #0B2447; font-size: 14px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .pkg-item-card .pkg-item-title i { color: #4DA6D9; }
    .pkg-item-card .pkg-item-detail { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: #475569; padding: 3px 0; line-height: 1.5; }
    .pkg-item-card .pkg-item-detail i { color: #4DA6D9; width: 16px; text-align: center; font-size: 12px; flex-shrink: 0; padding-top: 3px; }
    .pkg-item-card .pkg-item-subtotal { display: inline-block; background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; margin-top: 6px; }

    .pkg-section-header { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #f8fafc; border-radius: 12px; font-weight: 700; color: #0B2447; font-size: 14px; margin-bottom: 12px; border: 1px solid #e8f0fe; }
    .pkg-section-header i { color: #0ea5e9; font-size: 16px; }
    .pkg-section-header .pkg-chevron { margin-left: auto; color: #94a3b8; font-size: 12px; }

    .pkg-status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 30px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .pkg-status-pill.pending { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
    .pkg-status-pill.paid { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .pkg-status-pill.confirmed { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
    .pkg-status-pill.cancelled { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .pkg-total-amount { font-size: 18px; font-weight: 800; color: #10b981; }

    .guest-list-section { margin-top: 20px; padding-top: 20px; border-top: 2px solid #e8f0fe; }
    .guest-list-section .guest-list-title { font-size: 14px; font-weight: 700; color: #0B2447; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .guest-list-section .guest-item { display: flex; align-items: center; gap: 12px; padding: 8px 15px; border-bottom: 1px solid #f1f5f9; background: #f8fafc; border-radius: 8px; margin-bottom: 4px; }
    .guest-list-section .guest-item .guest-number { width: 28px; height: 28px; background: #4DA6D9; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0; }
    .guest-list-section .guest-item .guest-name { font-weight: 500; color: #1e293b; font-size: 14px; }
    .guest-list-section .guest-item .guest-detail { font-size: 12px; color: #94a3b8; margin-left: auto; }

    @media (max-width: 640px) {
        .pkg-booking-modal .modal-content { max-height: 94vh; border-radius: 18px; }
        .pkg-modal-header { padding: 16px 18px 14px; }
        .pkg-modal-body { padding: 14px 16px 20px; }
        .pkg-row { padding: 10px 12px; flex-direction: column; align-items: flex-start; gap: 4px; }
        .pkg-row .pkg-value { max-width: 100%; text-align: left; width: 100%; padding-left: 28px; }
        .pkg-gcash-card { flex-wrap: wrap; gap: 10px; }
        .pkg-copy-btn { width: 100%; justify-content: center; }
    }
    /* BOOKING STAT COLORS */

.active-card .stat-icon{
    background:#dcfce7;
    color:#16a34a;
}


.pending-card .stat-icon{
    background:#fef3c7;
    color:#d97706;
}


.rebook-card .stat-icon{
    background:#ede9fe;
    color:#7c3aed;
}


.paid-card .stat-icon{
    background:#dbeafe;
    color:#0284c7;
}


.blocked-card .stat-icon{
    background:#e2e8f0;
    color:#475569;
}


/* Better status emphasis */

.pending-card:hover{
    box-shadow:0 20px 40px rgba(245,158,11,.25);
}


.rebook-card:hover{
    box-shadow:0 20px 40px rgba(139,92,246,.25);
}


.active-card:hover{
    box-shadow:0 20px 40px rgba(16,185,129,.25);
}
    </style>
    <link rel="stylesheet" href="assets/css/admin-responsive.css">
<?php echo PaymentService::css(); ?>
<style>
.btn-sm.btn-balance{background:#10b981;color:#fff;border:none}
.btn-sm.btn-balance:hover{background:#059669}
.btn-card-action.btn-balance-mobile{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
    <i class="fas fa-bars"></i>
</button>

<div class="app-container">
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-header-top">
                <a href="admin-dashboard.php" class="logo">
                    <div class="logo-icon">
                        <?php if($nav_logo_exists): ?>
                            <img src="<?php echo htmlspecialchars($nav_logo); ?>?<?php echo time(); ?>" alt="Logo">
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
            <li class="nav-item"><a href="admin-dashboard.php" class="nav-link"><i class="fas fa-th-large"></i><span>Dashboard</span></a></li>
            <?php if($is_admin): ?>
            <li class="nav-item"><a href="user-management.php" class="nav-link"><i class="fas fa-users"></i><span>User Management</span></a></li>
            <?php endif; ?>
            <li class="nav-item"><a href="house-dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>House Management</span></a></li>
            <li class="nav-item"><a href="tour-dashboard.php" class="nav-link"><i class="fas fa-umbrella-beach"></i><span>Tour Management</span></a></li>
            <li class="nav-item"><a href="activities-dashboard.php" class="nav-link"><i class="fas fa-water"></i><span>Activities Management</span></a></li>
            <li class="nav-item"><a href="food-dashboard.php" class="nav-link"><i class="fas fa-utensils"></i><span>Food Management</span>
            </a></li>
            <li class="nav-item"><a href="booking-management.php" class="nav-link active"><i class="fas fa-calendar-check"></i><span>Booking Management</span>
                <?php if($sidebar_pending_bookings > 0): ?><span class="nav-badge" style="background: rgba(245,158,11,0.2); color:#f59e0b;"><?php echo $sidebar_pending_bookings; ?></span><?php endif; ?>
            </a></li>
            <li class="nav-item"><a href="blocked-dates.php" class="nav-link"><i class="fas fa-ban"></i><span>Blocked Dates</span>
            </a></li>
            <li class="nav-item"><a href="reviews-management.php" class="nav-link"><i class="fas fa-star"></i><span>Reviews Management</span>
                <?php if($sidebar_pending_reviews > 0): ?><span class="nav-badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981;"><?php echo $sidebar_pending_reviews; ?></span><?php endif; ?>
            </a></li>
            <?php if(!empty($is_admin)): ?><li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i><span>Sales Report</span></a></li><?php endif; ?>
            <?php if($is_admin): ?>
            <li class="nav-item"><a href="edit-content.php" class="nav-link"><i class="fas fa-edit"></i><span>Edit Content</span></a></li>
            <li class="nav-item"><a href="system-logs.php" class="nav-link"><i class="fas fa-history"></i><span>System Logs</span>
                <?php if($sidebar_failed_logs > 0): ?><span class="nav-badge"><?php echo $sidebar_failed_logs; ?></span><?php endif; ?>
            </a></li>
            <?php endif; ?>
            <div class="nav-divider"></div>
            <li class="nav-item"><a href="admin-profile.php" class="nav-link"><i class="fas fa-user-circle"></i><span>My Profile</span></a></li>
            <li class="nav-item"><a href="#" class="nav-link" onclick="openLogoutModal(event); return false;"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">

        <div class="top-bar">
            <div class="page-title">
                <h1>
                    <i class="fas fa-calendar-check"></i> Bookings
                    <span class="title-badge-mobile <?php echo $is_admin ? 'admin' : 'staff'; ?>">
                        <i class="fas fa-<?php echo $is_admin ? 'crown' : 'user-tie'; ?>"></i>
                        <?php echo $is_admin ? 'Admin' : 'Staff'; ?>
                    </span>
                    <span class="avatar-mobile">
                        <?php if($admin_avatar): ?>
                            <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $admin_initial; ?>
                        <?php endif; ?>
                    </span>
                </h1>
                <p>Manage all house, tour, food, and package bookings, verify payments, and update status</p>
            </div>

            <div class="user-profile">
                <div class="user-info" style="text-align: right;">
                    <div class="user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                    <div class="user-role">
                        <?php if($is_admin): ?><i class="fas fa-crown" style="color: #fbbf24;"></i> Admin
                        <?php else: ?><i class="fas fa-user-tie" style="color: #fbbf24;"></i> Staff<?php endif; ?>
                    </div>
                </div>
                <div class="avatar">
                    <?php if($admin_avatar): ?>
                        <img src="<?php echo htmlspecialchars($admin_avatar); ?>?<?php echo time(); ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo $admin_initial; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if(isset($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (!PaymentService::gcashConfig($pdo)['configured']): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i>
            <strong>Payment account configuration required:</strong> the GCash account name/number still look like placeholders, so guests are told to contact you before paying.
            <?php if ($is_admin): ?><a href="edit-content.php" style="font-weight:700;">Set the real GCash details in Edit Content</a>.<?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="page-title-banner">
            <div class="banner-content">
                <h1><i class="fas fa-calendar-check"></i> Booking Management</h1>
                <div class="underline"></div>
                <p>
                    Manage all house, tour, food, and package bookings, verify payments, and update status
                    <?php if($is_staff): ?><br><span class="staff-notice"><i class="fas fa-user-tie"></i> Staff Access - Daily Operations</span><?php endif; ?>
                </p>
                <?php if($can_manage_booking): ?>
                <button type="button" class="wk-open-btn" onclick="wkOpen()"><i class="fas fa-plus"></i> Create Walk-in Booking</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if($rebook_pending_count > 0): ?>
        <div class="rebook-banner">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 50%; background: #fef3c7; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #d97706; flex-shrink: 0;">
                    <i class="fas fa-redo"></i>
                </div>
                <div>
                    <div style="font-weight: 700; color: #92400e; font-size: 15px;">
                        🔔 <?php echo $rebook_pending_count; ?> Pending Rebook Request<?php echo $rebook_pending_count > 1 ? 's' : ''; ?> Awaiting Confirmation
                    </div>
                    <div style="color: #b45309; font-size: 13px; margin-top: 2px;">
                        Guests have updated stay dates on already-paid bookings. Please confirm to finalize their new dates.
                    </div>
                </div>
            </div>
            <button type="button" onclick="showBookingType('rebook')" class="btn-sm btn-rebook-confirm" style="padding: 9px 18px; font-size: 13px;">
                <i class="fas fa-check-circle"></i> Review & Confirm Rebooks (<?php echo $rebook_pending_count; ?>)
            </button>
        </div>
        <?php endif; ?>

        <div class="stats-grid">
<a href="?status=all&view=<?php echo $view; ?>" class="stat-card active-card">                <div class="stat-top"><div class="stat-icon"><i class="fas fa-calendar-alt"></i></div></div>
                <div class="stat-number"><?php echo $total_active; ?></div>
                <div class="stat-label">Active Bookings</div>
            </a>
<a href="?status=pending&view=<?php echo $view; ?>" class="stat-card pending-card">                <div class="stat-top"><div class="stat-icon"><i class="fas fa-clock"></i></div></div>
                <div class="stat-number"><?php echo $pending_count; ?></div>
                <div class="stat-label">Pending Payment</div>
            </a>
            <a href="javascript:void(0)" onclick="showBookingType('rebook')" class="stat-card rebook-card <?php echo $rebook_pending_count > 0 ? 'rebook-stat-alert' : ''; ?>">
                <div class="stat-top"><div class="stat-icon" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6;"><i class="fas fa-redo"></i></div></div>
                <div class="stat-number"><?php echo $rebook_pending_count; ?></div>
                <div class="stat-label">Pending Rebooks</div>
            </a>
<a href="?status=paid&view=<?php echo $view; ?>" class="stat-card paid-card">                <div class="stat-top"><div class="stat-icon"><i class="fas fa-check-circle"></i></div></div>
                <div class="stat-number"><?php echo $paid_count; ?></div>
                <div class="stat-label">Secured (Fee / Fully Paid)</div>
            </a>
            <a href="blocked-dates.php" class="stat-card blocked-card">
                <div class="stat-top"><div class="stat-icon" style="background: rgba(255,255,255,0.2);"><i class="fas fa-ban"></i></div></div>
                <div class="stat-number"><?php echo $total_blocked; ?></div>
                <div class="stat-label">Blocked Dates</div>
            </a>
        </div>

        <div class="view-toggle">
            <span class="view-label"><i class="fas fa-eye"></i> View:</span>
            <a href="?status=<?php echo $status_filter; ?>&view=list<?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="btn-view <?php echo $view == 'list' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> List View
            </a>
            <a href="?status=<?php echo $status_filter; ?>&view=calendar<?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="btn-view <?php echo $view == 'calendar' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Calendar View
            </a>
        </div>

        <div class="filter-bar">
            <div class="filter-buttons">
                <a href="?status=all&view=<?php echo $view; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="filter-btn <?php echo $status_filter == 'all' ? 'active' : ''; ?>">All</a>
                <a href="?status=pending&view=<?php echo $view; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="filter-btn <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">Pending</a>
                <a href="?status=rebook&view=<?php echo $view; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="filter-btn <?php echo ($status_filter == 'rebook' || $status_filter == 'pending_rebook') ? 'active' : ''; ?>">Rebooks</a>
                <a href="?status=paid&view=<?php echo $view; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="filter-btn <?php echo $status_filter == 'paid' ? 'active' : ''; ?>">Fee / Fully Paid</a>
                <a href="?status=cancelled&view=<?php echo $view; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="filter-btn <?php echo $status_filter == 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
                <a href="?status=history&view=<?php echo $view; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="filter-btn <?php echo $status_filter == 'history' ? 'active' : ''; ?>">History</a>
            </div>
<div class="search-box">                <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
                <input type="hidden" name="view" value="<?php echo $view; ?>">
<input 
    type="text" 
    id="bookingSearch"
    placeholder="Search by reference, guest, or item..."
    autocomplete="off"
>                <button type="button"><i class="fas fa-search"></i></button>
                <?php if($search): ?>
                    <a href="?status=<?php echo $status_filter; ?>&view=<?php echo $view; ?>" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
          </div>
        </div>

        <?php if($view == 'calendar'): ?>
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-calendar-alt"></i> Booking Calendar</h2>
                <div class="calendar-legend">
                    <span class="legend-item"><span class="legend-color paid"></span> Reservation Fee Paid / Fully Paid</span>
                    <span class="legend-item"><span class="legend-color pending"></span> Pending Payment</span>
                    <span class="legend-item"><span class="legend-color blocked"></span> Blocked</span>
                    <span class="legend-item">🏠 House</span>
                    <span class="legend-item">🏖 Tour</span>
                    <span class="legend-item">🍽 Food</span>
                    <span class="legend-item">📦 Package</span>
                </div>
            </div>
            <div id="calendar"></div>
        </div>
        <?php else: ?>

        <div class="booking-tabs">
            <div class="booking-tab active" onclick="showBookingType('house')"><i class="fas fa-home"></i> House (<?php echo count($active_house); ?>)</div>
            <div class="booking-tab" onclick="showBookingType('tour')"><i class="fas fa-umbrella-beach"></i> Tour (<?php echo count($active_tour); ?>)</div>
            <div class="booking-tab" onclick="showBookingType('food')"><i class="fas fa-utensils"></i> Food (<?php echo count($active_food); ?>)</div>
            <div class="booking-tab package-tab" onclick="showBookingType('package')"><i class="fas fa-box-open"></i> Package (<?php echo count($active_package); ?>)</div>
            <div class="booking-tab rebook-tab <?php echo $rebook_pending_count > 0 ? 'has-pending' : ''; ?>" onclick="showBookingType('rebook')">
                <i class="fas fa-redo"></i> Rebooks
                <?php if($rebook_pending_count > 0): ?>
                    <span class="tab-count"><?php echo $rebook_pending_count; ?> pending</span>
                <?php endif; ?>
            </div>
            <div class="booking-tab history-tab" onclick="showBookingType('history')">
                <i class="fas fa-history"></i> History
                <?php if($total_history > 0): ?>
                    <span class="tab-count"><?php echo $total_history; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- HOUSE BOOKINGS — Exclude pending rebooks (para sa Rebook tab lang sila) -->
        <div id="house-section" class="booking-section active">
            <div class="card">
                <?php 
                // ✅ FIX: I-filter ang pending rebooks papuntang Rebook tab
                $active_house_no_pending_rebook = array_values(array_filter($active_house, function($b) {
                    if (empty($b['rebooked_at'])) return true;
                    if (!empty($b['rebook_confirmed_at'])) return true;
                    if ($b['booking_status'] === 'cancelled') return true;
                    return false;
                }));
                ?>
                <div class="card-header">
                    <h2><i class="fas fa-home"></i> Active House Bookings (<?php echo count($active_house_no_pending_rebook); ?>)</h2>
                </div>

                <div class="table-responsive desktop-table">
                    <?php if(count($active_house_no_pending_rebook) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Ref #</th><th>Guest</th><th>House</th><th>Check In</th><th>Check Out</th>
                                <th>Guests</th><th>Total</th><th>Payment</th><th>Status</th><th>Proof</th>
                                <th>GCash Ref</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($active_house_no_pending_rebook as $booking): 
                                $is_row_rebook = (!empty($booking['rebooked_at']) || (!empty($booking['rebook_count']) && (int)$booking['rebook_count'] > 0));
                                $is_row_pending_rebook = $is_row_rebook && ((!empty($booking['rebooked_at']) && empty($booking['rebook_confirmed_at'])) || $booking['booking_status'] == 'pending');
                            ?>
<tr 
class="booking-row"
data-search="<?php echo strtolower(htmlspecialchars(
    $booking['reference_number'].' '.
    $booking['guest_name'].' '.
    $booking['house_name']
)); ?>"
style="<?php echo $is_row_pending_rebook ? 'background: #fffdf5;' : ''; ?>">                                <td>
                                    <strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong><?php echo walkInChip($booking); ?>
                                    <?php if($is_row_rebook): ?>
                                        <span class="rebook-badge"><i class="fas fa-redo"></i> #<?php echo $booking['rebook_count'] ?: 1; ?>/2</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                <td><?php echo htmlspecialchars($booking['house_name']); ?></td>
                                <td>
                                    <?php echo formatDateDisplay($booking['check_in_date']); ?>
                                    <?php if (!empty($booking['check_in_time'])): ?>
                                        <div class="booking-time-row">
                                            <i class="fas fa-clock"></i>
                                            <?php echo formatTimeDisplay($booking['check_in_time']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo formatDateDisplay($booking['check_out_date']); ?>
                                    <?php if (!empty($booking['check_out_time'])): ?>
                                        <div class="booking-time-row">
                                            <i class="fas fa-clock"></i>
                                            <?php echo formatTimeDisplay($booking['check_out_time']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $booking['number_of_guests']; ?></td>
                                <td><?php echo PaymentService::adminAmountCell($booking); ?></td>
                                <td><?php echo PaymentService::adminBadge($booking); ?></td>
                                <td>
                                    <?php if($is_row_pending_rebook): ?>
                                        <span class="badge badge-warning"><i class="fas fa-clock"></i> Rebook Pending</span>
                                    <?php elseif($is_row_rebook && $booking['booking_status'] == 'confirmed'): ?>
                                        <span class="badge badge-success"><i class="fas fa-check-circle"></i> Confirmed</span>
                                    <?php else: ?>
                                        <span class="badge badge-info"><?php echo ucfirst($booking['booking_status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($booking['payment_proof']): ?>
                                    <button class="btn-sm btn-info" onclick="viewProof('<?php echo htmlspecialchars($booking['payment_proof']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['gcash_reference'] ?? ''); ?>')">
                                        <i class="fas fa-image"></i> View
                                    </button>
                                    <?php else: ?><span class="badge badge-info">No proof</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($booking['gcash_reference'])): ?>
                                        <span class="gcash-ref-badge"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['gcash_reference']); ?></span>
                                    <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                        <button class="btn-view-booking" onclick='viewBooking("house", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if($is_row_pending_rebook): ?>
                                        <button type="button" class="btn-sm btn-rebook-confirm" onclick="openConfirmModal('rebook', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', '<?php echo htmlspecialchars($booking['house_name']); ?>')">
                                            <i class="fas fa-check-circle"></i> Confirm Rebook
                                        </button>
                                        <button type="button" class="btn-sm btn-rebook-reject" onclick="openRejectRebookModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <?php endif; ?>
                                        <?php if($booking['payment_status'] == 'pending' && $booking['payment_proof']): ?>
                                        <button type="button" class="btn-sm btn-success" onclick="openConfirmModal('payment', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', 'house', <?php echo PaymentService::feeFor($booking['total_amount']); ?>)">
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                        <button type="button" class="btn-sm btn-danger" onclick="openRejectModal(<?php echo $booking['id']; ?>, 'house', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <?php endif; ?>
                                        <?php if(!$is_row_pending_rebook): ?>
                                        <?php if (PaymentService::state($booking) === 'reservation_paid'): ?>
                                        <button type="button" class="btn-sm btn-balance" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'house', 'id' => (int)$booking['id'], 'ref' => $booking['reference_number'], 'guest' => $booking['guest_name'] ?? '', 'total' => PaymentService::amounts($booking)['total'], 'paid' => PaymentService::amounts($booking)['paid'], 'balance' => PaymentService::amounts($booking)['balance'], 'reserved_at' => $booking['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                            <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                        </button>
                                        <?php endif; ?>
                                        <?php if(bookingCancelAllowed($booking)): ?><button type="button" class="btn-sm btn-cancel-booking" onclick="openAdminCancelModal('house', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', <?php echo PaymentService::amounts($booking)['paid']; ?>)">
                                            <i class="fas fa-ban"></i> Cancel
                                        </button><?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <i class="fas fa-inbox" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p>No active house bookings.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="booking-cards-mobile">
                    <?php if(count($active_house_no_pending_rebook) > 0): ?>
                        <?php foreach($active_house_no_pending_rebook as $booking): 
                            $is_row_rebook = (!empty($booking['rebooked_at']) || (!empty($booking['rebook_count']) && (int)$booking['rebook_count'] > 0));
                            $is_row_pending_rebook = $is_row_rebook && ((!empty($booking['rebooked_at']) && empty($booking['rebook_confirmed_at'])) || $booking['booking_status'] == 'pending');
                        ?>
                        <div class="booking-card-mobile" style="<?php echo $is_row_pending_rebook ? 'background:#fffdf5; border-color:#f59e0b;' : ''; ?>">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-hashtag" style="color:#4DA6D9; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($booking['reference_number']); ?><?php echo walkInChip($booking); ?>
                                    <?php if($is_row_rebook): ?>
                                        <span class="rebook-badge"><i class="fas fa-redo"></i> #<?php echo $booking['rebook_count'] ?: 1; ?>/2</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-badges">
                                    <?php echo PaymentService::adminBadge($booking); ?>
                                </div>
                            </div>
                            <div class="card-row">
                                <i class="fas fa-user"></i>
                                <span class="card-label">Guest</span>
                                <span class="card-value"><?php echo htmlspecialchars($booking['guest_name']); ?></span>
                            </div>
                            <div class="card-row">
                                <i class="fas fa-home"></i>
                                <span class="card-label">House</span>
                                <span class="card-value"><?php echo htmlspecialchars($booking['house_name']); ?></span>
                            </div>
                            <div class="card-row">
                                <i class="fas fa-calendar-alt"></i>
                                <span class="card-label">Dates</span>
                                <span class="card-value">
                                    <?php echo formatDateDisplay($booking['check_in_date']); ?>
                                    <?php if (!empty($booking['check_in_time'])): ?>
                                        <small style="color:#64748b; font-weight:400;">(<?php echo formatTimeDisplay($booking['check_in_time']); ?>)</small>
                                    <?php endif; ?>
                                    &rarr;
                                    <?php echo formatDateDisplay($booking['check_out_date']); ?>
                                    <?php if (!empty($booking['check_out_time'])): ?>
                                        <small style="color:#64748b; font-weight:400;">(<?php echo formatTimeDisplay($booking['check_out_time']); ?>)</small>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="card-proof">
                                <?php if($booking['payment_proof']): ?>
                                    <img class="card-proof-thumb" src="uploads/payments/<?php echo htmlspecialchars($booking['payment_proof']); ?>?t=<?php echo time(); ?>" alt="Proof"
                                         onclick="viewProof('<?php echo htmlspecialchars($booking['payment_proof']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['gcash_reference'] ?? ''); ?>')"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="card-proof-thumb no-proof" style="display:none;"><i class="fas fa-image"></i></div>
                                <?php else: ?>
                                    <div class="card-proof-thumb no-proof"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                                <div class="card-proof-info">
                                    <div class="card-label">GCash Reference</div>
                                    <?php if(!empty($booking['gcash_reference'])): ?>
                                        <div class="card-gcash"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['gcash_reference']); ?></div>
                                    <?php else: ?>
                                        <div style="color:#94a3b8; font-size:12px; font-style:italic;">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-actions">
                                <button type="button" class="btn-card-action btn-more" onclick='viewBooking("house", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>
                                <?php if($is_row_pending_rebook): ?>
                                    <button type="button" class="btn-card-action btn-rebook-confirm-mobile" onclick="openConfirmModal('rebook', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', '<?php echo htmlspecialchars($booking['house_name']); ?>')">
                                        <i class="fas fa-check-circle"></i> Confirm
                                    </button>
                                    <button type="button" class="btn-card-action btn-reject-mobile" onclick="openRejectRebookModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php elseif($booking['payment_status'] == 'pending' && $booking['payment_proof']): ?>
                                    <button type="button" class="btn-card-action btn-confirm-mobile" onclick="openConfirmModal('payment', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', 'house', <?php echo PaymentService::feeFor($booking['total_amount']); ?>)">
                                        <i class="fas fa-check"></i> Confirm
                                    </button>
                                    <button type="button" class="btn-card-action btn-reject-mobile" onclick="openRejectModal(<?php echo $booking['id']; ?>, 'house', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php endif; ?>
                                <?php if(!$is_row_pending_rebook): ?>
                                    <?php if (PaymentService::state($booking) === 'reservation_paid'): ?>
                                    <button type="button" class="btn-card-action btn-balance-mobile" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'house', 'id' => (int)$booking['id'], 'ref' => $booking['reference_number'], 'guest' => $booking['guest_name'] ?? '', 'total' => PaymentService::amounts($booking)['total'], 'paid' => PaymentService::amounts($booking)['paid'], 'balance' => PaymentService::amounts($booking)['balance'], 'reserved_at' => $booking['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                        <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                    </button>
                                    <?php endif; ?>
                                    <?php if(bookingCancelAllowed($booking)): ?><button type="button" class="btn-card-action btn-reject-mobile" onclick="openAdminCancelModal('house', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', <?php echo PaymentService::amounts($booking)['paid']; ?>)">
                                        <i class="fas fa-ban"></i> Cancel
                                    </button><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #94a3b8; background:white; border-radius:14px; border:1px solid #e8f0fe;">
                            <i class="fas fa-inbox" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                            <p>No active house bookings.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TOUR BOOKINGS -->
        <div id="tour-section" class="booking-section">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-umbrella-beach"></i> Active Tour Bookings (<?php echo count($active_tour); ?>)</h2>
                </div>

                <div class="table-responsive desktop-table">
                    <?php if(count($active_tour) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Ref #</th><th>Guest</th><th>Tour</th><th>Date</th><th>Guests</th>
                                <th>Total</th><th>Payment</th><th>Status</th><th>Proof</th><th>GCash Ref</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($active_tour as $booking): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong><?php echo walkInChip($booking); ?></td>
                                <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                <td><?php echo htmlspecialchars($booking['tour_name']); ?></td>
                                <td><?php echo formatDateDisplay($booking['booking_date']); ?></td>
                                <td><?php echo $booking['number_of_guests']; ?></td>
                                <td><?php echo PaymentService::adminAmountCell($booking); ?></td>
                                <td><?php echo PaymentService::adminBadge($booking); ?></td>
                                <td><span class="badge badge-info"><?php echo ucfirst($booking['booking_status']); ?></span></td>
                                <td>
                                    <?php if($booking['payment_proof']): ?>
                                    <button class="btn-sm btn-info" onclick="viewProof('<?php echo htmlspecialchars($booking['payment_proof']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['gcash_reference'] ?? ''); ?>')">
                                        <i class="fas fa-image"></i> View
                                    </button>
                                    <?php else: ?><span class="badge badge-info">No proof</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($booking['gcash_reference'])): ?>
                                        <span class="gcash-ref-badge"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['gcash_reference']); ?></span>
                                    <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                        <button class="btn-view-booking" onclick='viewBooking("tour", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if($booking['payment_status'] == 'pending' && $booking['payment_proof']): ?>
                                        <button type="button" class="btn-sm btn-success" onclick="openConfirmModal('payment', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', 'tour', <?php echo PaymentService::feeFor($booking['total_amount']); ?>)">
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                        <button type="button" class="btn-sm btn-danger" onclick="openRejectModal(<?php echo $booking['id']; ?>, 'tour', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <?php endif; ?>
                                        <?php if (PaymentService::state($booking) === 'reservation_paid'): ?>
                                        <button type="button" class="btn-sm btn-balance" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'tour', 'id' => (int)$booking['id'], 'ref' => $booking['reference_number'], 'guest' => $booking['guest_name'] ?? '', 'total' => PaymentService::amounts($booking)['total'], 'paid' => PaymentService::amounts($booking)['paid'], 'balance' => PaymentService::amounts($booking)['balance'], 'reserved_at' => $booking['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                            <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                        </button>
                                        <?php endif; ?>
                                        <?php if(bookingCancelAllowed($booking)): ?><button type="button" class="btn-sm btn-cancel-booking" onclick="openAdminCancelModal('tour', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', <?php echo PaymentService::amounts($booking)['paid']; ?>)">
                                            <i class="fas fa-ban"></i> Cancel
                                        </button><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <i class="fas fa-inbox" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p>No active tour bookings.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="booking-cards-mobile">
                    <?php if(count($active_tour) > 0): ?>
                        <?php foreach($active_tour as $booking): ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-hashtag" style="color:#4DA6D9; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($booking['reference_number']); ?><?php echo walkInChip($booking); ?>
                                </div>
                                <div class="card-badges">
                                    <?php echo PaymentService::adminBadge($booking); ?>
                                </div>
                            </div>
                            <div class="card-row"><i class="fas fa-user"></i><span class="card-label">Guest</span><span class="card-value"><?php echo htmlspecialchars($booking['guest_name']); ?></span></div>
                            <div class="card-row"><i class="fas fa-umbrella-beach"></i><span class="card-label">Tour</span><span class="card-value"><?php echo htmlspecialchars($booking['tour_name']); ?></span></div>
                            <div class="card-row"><i class="fas fa-calendar-alt"></i><span class="card-label">Date</span><span class="card-value"><?php echo formatDateDisplay($booking['booking_date']); ?></span></div>
                            <div class="card-proof">
                                <?php if($booking['payment_proof']): ?>
                                    <img class="card-proof-thumb" src="uploads/payments/<?php echo htmlspecialchars($booking['payment_proof']); ?>?t=<?php echo time(); ?>" alt="Proof"
                                         onclick="viewProof('<?php echo htmlspecialchars($booking['payment_proof']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['gcash_reference'] ?? ''); ?>')"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="card-proof-thumb no-proof" style="display:none;"><i class="fas fa-image"></i></div>
                                <?php else: ?>
                                    <div class="card-proof-thumb no-proof"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                                <div class="card-proof-info">
                                    <div class="card-label">GCash Reference</div>
                                    <?php if(!empty($booking['gcash_reference'])): ?>
                                        <div class="card-gcash"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['gcash_reference']); ?></div>
                                    <?php else: ?>
                                        <div style="color:#94a3b8; font-size:12px; font-style:italic;">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-actions">
                                <button type="button" class="btn-card-action btn-more" onclick='viewBooking("tour", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>
                                <?php if($booking['payment_status'] == 'pending' && $booking['payment_proof']): ?>
                                    <button type="button" class="btn-card-action btn-confirm-mobile" onclick="openConfirmModal('payment', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', 'tour', <?php echo PaymentService::feeFor($booking['total_amount']); ?>)">
                                        <i class="fas fa-check"></i> Confirm
                                    </button>
                                    <button type="button" class="btn-card-action btn-reject-mobile" onclick="openRejectModal(<?php echo $booking['id']; ?>, 'tour', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php endif; ?>
                                <?php if (PaymentService::state($booking) === 'reservation_paid'): ?>
                                <button type="button" class="btn-card-action btn-balance-mobile" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'tour', 'id' => (int)$booking['id'], 'ref' => $booking['reference_number'], 'guest' => $booking['guest_name'] ?? '', 'total' => PaymentService::amounts($booking)['total'], 'paid' => PaymentService::amounts($booking)['paid'], 'balance' => PaymentService::amounts($booking)['balance'], 'reserved_at' => $booking['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                    <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                </button>
                                <?php endif; ?>
                                <?php if(bookingCancelAllowed($booking)): ?><button type="button" class="btn-card-action btn-reject-mobile" onclick="openAdminCancelModal('tour', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', <?php echo PaymentService::amounts($booking)['paid']; ?>)">
                                    <i class="fas fa-ban"></i> Cancel
                                </button><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #94a3b8; background:white; border-radius:14px; border:1px solid #e8f0fe;">
                            <i class="fas fa-inbox" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                            <p>No active tour bookings.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- FOOD BOOKINGS -->
        <div id="food-section" class="booking-section">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-utensils"></i> Active Food Bookings (<?php echo count($active_food); ?>)</h2>
                </div>

                <div class="table-responsive desktop-table">
                    <?php if(count($active_food) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Ref #</th><th>Guest</th><th>Food Item</th><th>Qty</th><th>Size</th>
                                <th>Fulfillment</th><th>Contact #</th><th>Total</th><th>Payment</th><th>Status</th>
                                <th>Proof</th><th>GCash Ref</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($active_food as $booking): 
                                $fulfillment = $booking['fulfillment_method'] ?? 'pickup';
                                $is_delivery = ($fulfillment === 'delivery');
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong><?php echo walkInChip($booking); ?></td>
                                <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                <td><?php echo htmlspecialchars($booking['food_name']); ?></td>
                                <td><?php echo $booking['quantity']; ?></td>
                                <td><?php echo htmlspecialchars($booking['size_variant'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if($is_delivery): ?>
                                        <span class="badge badge-delivery"><i class="fas fa-truck"></i> Delivery</span>
                                    <?php else: ?>
                                        <span class="badge badge-pickup"><i class="fas fa-store"></i> Pickup</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($booking['contact_number'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($booking['contact_number']); ?>" class="contact-link">
                                            <i class="fas fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($booking['contact_number']); ?>
                                        </a>
                                    <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                </td>
                                <td><?php echo PaymentService::adminAmountCell($booking); ?></td>
                                <td><?php echo PaymentService::adminBadge($booking); ?></td>
                                <td><span class="badge badge-info"><?php echo ucfirst($booking['booking_status']); ?></span></td>
                                <td>
                                    <?php if($booking['payment_proof']): ?>
                                    <button class="btn-sm btn-info" onclick="viewProof('<?php echo htmlspecialchars($booking['payment_proof']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['gcash_reference'] ?? ''); ?>')">
                                        <i class="fas fa-image"></i> View
                                    </button>
                                    <?php else: ?><span class="badge badge-info">No proof</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($booking['gcash_reference'])): ?>
                                        <span class="gcash-ref-badge"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['gcash_reference']); ?></span>
                                    <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                        <button class="btn-view-booking" onclick='viewBooking("food", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if($booking['payment_status'] == 'pending' && $booking['payment_proof']): ?>
                                        <button type="button" class="btn-sm btn-success" onclick="openConfirmModal('payment', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', 'food', <?php echo PaymentService::feeFor($booking['total_amount']); ?>)">
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                        <button type="button" class="btn-sm btn-danger" onclick="openRejectModal(<?php echo $booking['id']; ?>, 'food', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <?php endif; ?>
                                        <?php if (PaymentService::state($booking) === 'reservation_paid'): ?>
                                        <button type="button" class="btn-sm btn-balance" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'food', 'id' => (int)$booking['id'], 'ref' => $booking['reference_number'], 'guest' => $booking['guest_name'] ?? '', 'total' => PaymentService::amounts($booking)['total'], 'paid' => PaymentService::amounts($booking)['paid'], 'balance' => PaymentService::amounts($booking)['balance'], 'reserved_at' => $booking['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                            <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                        </button>
                                        <?php endif; ?>
                                        <?php if(bookingCancelAllowed($booking)): ?><button type="button" class="btn-sm btn-cancel-booking" onclick="openAdminCancelModal('food', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', <?php echo PaymentService::amounts($booking)['paid']; ?>)">
                                            <i class="fas fa-ban"></i> Cancel
                                        </button><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <i class="fas fa-utensils" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p>No active food bookings.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="booking-cards-mobile">
                    <?php if(count($active_food) > 0): ?>
                        <?php foreach($active_food as $booking): 
                            $fulfillment = $booking['fulfillment_method'] ?? 'pickup';
                            $is_delivery = ($fulfillment === 'delivery');
                        ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-hashtag" style="color:#4DA6D9; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($booking['reference_number']); ?><?php echo walkInChip($booking); ?>
                                </div>
                                <div class="card-badges">
                                    <?php echo PaymentService::adminBadge($booking); ?>
                                </div>
                            </div>
                            <div class="card-row"><i class="fas fa-user"></i><span class="card-label">Guest</span><span class="card-value"><?php echo htmlspecialchars($booking['guest_name']); ?></span></div>
                            <div class="card-row"><i class="fas fa-utensils"></i><span class="card-label">Food</span><span class="card-value"><?php echo htmlspecialchars($booking['food_name']); ?></span></div>
                            <div class="card-row">
                                <i class="fas <?php echo $is_delivery ? 'fa-truck' : 'fa-store'; ?>"></i>
                                <span class="card-label">Method</span>
                                <span class="card-value">
                                    <?php if($is_delivery): ?><span class="badge badge-delivery"><i class="fas fa-truck"></i> Delivery</span>
                                    <?php else: ?><span class="badge badge-pickup"><i class="fas fa-store"></i> Pickup</span><?php endif; ?>
                                </span>
                            </div>
                            <div class="card-proof">
                                <?php if($booking['payment_proof']): ?>
                                    <img class="card-proof-thumb" src="uploads/payments/<?php echo htmlspecialchars($booking['payment_proof']); ?>?t=<?php echo time(); ?>" alt="Proof"
                                         onclick="viewProof('<?php echo htmlspecialchars($booking['payment_proof']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['gcash_reference'] ?? ''); ?>')"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="card-proof-thumb no-proof" style="display:none;"><i class="fas fa-image"></i></div>
                                <?php else: ?>
                                    <div class="card-proof-thumb no-proof"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                                <div class="card-proof-info">
                                    <div class="card-label">GCash Reference</div>
                                    <?php if(!empty($booking['gcash_reference'])): ?>
                                        <div class="card-gcash"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['gcash_reference']); ?></div>
                                    <?php else: ?>
                                        <div style="color:#94a3b8; font-size:12px; font-style:italic;">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-actions">
                                <button type="button" class="btn-card-action btn-more" onclick='viewBooking("food", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>
                                <?php if($booking['payment_status'] == 'pending' && $booking['payment_proof']): ?>
                                    <button type="button" class="btn-card-action btn-confirm-mobile" onclick="openConfirmModal('payment', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', 'food', <?php echo PaymentService::feeFor($booking['total_amount']); ?>)">
                                        <i class="fas fa-check"></i> Confirm
                                    </button>
                                    <button type="button" class="btn-card-action btn-reject-mobile" onclick="openRejectModal(<?php echo $booking['id']; ?>, 'food', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php endif; ?>
                                <?php if (PaymentService::state($booking) === 'reservation_paid'): ?>
                                <button type="button" class="btn-card-action btn-balance-mobile" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'food', 'id' => (int)$booking['id'], 'ref' => $booking['reference_number'], 'guest' => $booking['guest_name'] ?? '', 'total' => PaymentService::amounts($booking)['total'], 'paid' => PaymentService::amounts($booking)['paid'], 'balance' => PaymentService::amounts($booking)['balance'], 'reserved_at' => $booking['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                    <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                </button>
                                <?php endif; ?>
                                <?php if(bookingCancelAllowed($booking)): ?><button type="button" class="btn-card-action btn-reject-mobile" onclick="openAdminCancelModal('food', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', <?php echo PaymentService::amounts($booking)['paid']; ?>)">
                                    <i class="fas fa-ban"></i> Cancel
                                </button><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #94a3b8; background:white; border-radius:14px; border:1px solid #e8f0fe;">
                            <i class="fas fa-utensils" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                            <p>No active food bookings.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PACKAGE BOOKINGS -->
        <div id="package-section" class="booking-section">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-box-open"></i> Active Package Bookings (<?php echo count($active_package); ?>)</h2>
                </div>

                <div class="table-responsive desktop-table">
                    <?php if(count($active_package) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Ref #</th><th>Guest</th><th>Items</th><th>Contact #</th><th>Total</th>
                                <th>Payment</th><th>Status</th><th>Proof</th><th>GCash Ref</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($active_package as $pkg): 
                                $itemCount = 0;
                                if (!empty($pkg['house_name'])) $itemCount++;
                                if (!empty($pkg['tour_name'])) $itemCount++;
                                if (!empty($pkg['food_name'])) $itemCount++;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($pkg['reference_number']); ?></strong><?php echo walkInChip($pkg); ?>
                                    <span class="package-badge"><i class="fas fa-box-open"></i> <?php echo $itemCount; ?> items</span>
                                </td>
                                <td><?php echo htmlspecialchars($pkg['guest_name']); ?></td>
                                <td>
                                    <div style="font-size: 11px; line-height: 1.6;">
                                        <?php if(!empty($pkg['house_name'])): ?><div><i class="fas fa-home" style="color:#4DA6D9;"></i> <?php echo htmlspecialchars($pkg['house_name']); ?></div><?php endif; ?>
                                        <?php if(!empty($pkg['tour_name'])): ?><div><i class="fas fa-umbrella-beach" style="color:#10b981;"></i> <?php echo htmlspecialchars($pkg['tour_name']); ?></div><?php endif; ?>
                                        <?php if(!empty($pkg['food_name'])): ?><div><i class="fas fa-utensils" style="color:#f59e0b;"></i> <?php echo htmlspecialchars($pkg['food_name']); ?></div><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if(!empty($pkg['contact_number'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($pkg['contact_number']); ?>" class="contact-link">
                                            <i class="fas fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($pkg['contact_number']); ?>
                                        </a>
                                    <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                </td>
                                <td><?php echo PaymentService::adminAmountCell($pkg); ?></td>
                                <td><?php echo PaymentService::adminBadge($pkg); ?></td>
                                <td><span class="badge badge-info"><?php echo ucfirst($pkg['booking_status']); ?></span></td>
                                <td>
                                    <?php if(!empty($pkg['payment_proof'])): ?>
                                    <button class="btn-sm btn-info" onclick="viewProof('<?php echo htmlspecialchars($pkg['payment_proof']); ?>', '<?php echo htmlspecialchars($pkg['reference_number']); ?>', '<?php echo htmlspecialchars($pkg['gcash_reference'] ?? ''); ?>')">
                                        <i class="fas fa-image"></i> View
                                    </button>
                                    <?php else: ?><span class="badge badge-info">No proof</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($pkg['gcash_reference'])): ?>
                                        <span class="gcash-ref-badge"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($pkg['gcash_reference']); ?></span>
                                    <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                        <button class="btn-view-booking" onclick='viewBooking("package", <?php echo htmlspecialchars(json_encode($pkg), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fas fa-eye"></i> View Package
                                        </button>
                                        <?php if($pkg['payment_status'] == 'pending' && !empty($pkg['payment_proof']) && $pkg['booking_status'] != 'cancelled'): ?>
                                        <button type="button" class="btn-sm btn-success" onclick="openConfirmModal('payment', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>', '<?php echo htmlspecialchars($pkg['guest_name']); ?>', 'package', <?php echo PaymentService::feeFor($pkg['grand_total']); ?>)">
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                        <button type="button" class="btn-sm btn-danger" onclick="openRejectModal(<?php echo $pkg['id']; ?>, 'package', '<?php echo htmlspecialchars($pkg['reference_number']); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                        <?php endif; ?>
                                        <?php if (PaymentService::state($pkg) === 'reservation_paid'): ?>
                                        <button type="button" class="btn-sm btn-balance" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'package', 'id' => (int)$pkg['id'], 'ref' => $pkg['reference_number'], 'guest' => $pkg['guest_name'] ?? '', 'total' => PaymentService::amounts($pkg)['total'], 'paid' => PaymentService::amounts($pkg)['paid'], 'balance' => PaymentService::amounts($pkg)['balance'], 'reserved_at' => $pkg['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                            <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                        </button>
                                        <?php endif; ?>
                                        <?php if(bookingCancelAllowed($pkg)): ?><button type="button" class="btn-sm btn-cancel-booking" onclick="openAdminCancelModal('package', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>', '<?php echo htmlspecialchars($pkg['guest_name']); ?>', <?php echo PaymentService::amounts($pkg)['paid']; ?>)">
                                            <i class="fas fa-ban"></i> Cancel
                                        </button><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <i class="fas fa-box-open" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                        <p>No active package bookings.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="booking-cards-mobile">
                    <?php if(count($active_package) > 0): ?>
                        <?php foreach($active_package as $pkg): 
                            $itemCount = 0;
                            if (!empty($pkg['house_name'])) $itemCount++;
                            if (!empty($pkg['tour_name'])) $itemCount++;
                            if (!empty($pkg['food_name'])) $itemCount++;
                        ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-hashtag" style="color:#0ea5e9; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($pkg['reference_number']); ?><?php echo walkInChip($pkg); ?>
                                    <span class="package-badge"><i class="fas fa-box-open"></i> <?php echo $itemCount; ?> items</span>
                                </div>
                                <div class="card-badges">
                                    <?php echo PaymentService::adminBadge($pkg); ?>
                                </div>
                            </div>
                            <div class="card-row"><i class="fas fa-user"></i><span class="card-label">Guest</span><span class="card-value"><?php echo htmlspecialchars($pkg['guest_name']); ?></span></div>
                            <?php if(!empty($pkg['house_name'])): ?>
                            <div class="card-row"><i class="fas fa-home"></i><span class="card-label">House</span><span class="card-value"><?php echo htmlspecialchars($pkg['house_name']); ?></span></div>
                            <?php endif; ?>
                            <?php if(!empty($pkg['tour_name'])): ?>
                            <div class="card-row"><i class="fas fa-umbrella-beach"></i><span class="card-label">Tour</span><span class="card-value"><?php echo htmlspecialchars($pkg['tour_name']); ?></span></div>
                            <?php endif; ?>
                            <?php if(!empty($pkg['food_name'])): ?>
                            <div class="card-row"><i class="fas fa-utensils"></i><span class="card-label">Food</span><span class="card-value"><?php echo htmlspecialchars($pkg['food_name']); ?></span></div>
                            <?php endif; ?>
                            <div class="card-row"><i class="fas fa-money-bill"></i><span class="card-label">Total</span><span class="card-value"><?php echo PaymentService::adminAmountCell($pkg); ?></span></div>
                            <div class="card-proof">
                                <?php if(!empty($pkg['payment_proof'])): ?>
                                    <img class="card-proof-thumb" src="uploads/payments/<?php echo htmlspecialchars($pkg['payment_proof']); ?>?t=<?php echo time(); ?>" alt="Proof"
                                         onclick="viewProof('<?php echo htmlspecialchars($pkg['payment_proof']); ?>', '<?php echo htmlspecialchars($pkg['reference_number']); ?>', '<?php echo htmlspecialchars($pkg['gcash_reference'] ?? ''); ?>')"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="card-proof-thumb no-proof" style="display:none;"><i class="fas fa-image"></i></div>
                                <?php else: ?>
                                    <div class="card-proof-thumb no-proof"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                                <div class="card-proof-info">
                                    <div class="card-label">GCash Reference</div>
                                    <?php if(!empty($pkg['gcash_reference'])): ?>
                                        <div class="card-gcash"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($pkg['gcash_reference']); ?></div>
                                    <?php else: ?>
                                        <div style="color:#94a3b8; font-size:12px; font-style:italic;">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-actions">
                                <button type="button" class="btn-card-action btn-more" onclick='viewBooking("package", <?php echo htmlspecialchars(json_encode($pkg), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>
                                <?php if($pkg['payment_status'] == 'pending' && !empty($pkg['payment_proof']) && $pkg['booking_status'] != 'cancelled'): ?>
                                    <button type="button" class="btn-card-action btn-confirm-mobile" onclick="openConfirmModal('payment', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>', '<?php echo htmlspecialchars($pkg['guest_name']); ?>', 'package', <?php echo PaymentService::feeFor($pkg['grand_total']); ?>)">
                                        <i class="fas fa-check"></i> Confirm
                                    </button>
                                    <button type="button" class="btn-card-action btn-reject-mobile" onclick="openRejectModal(<?php echo $pkg['id']; ?>, 'package', '<?php echo htmlspecialchars($pkg['reference_number']); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php endif; ?>
                                <?php if (PaymentService::state($pkg) === 'reservation_paid'): ?>
                                <button type="button" class="btn-card-action btn-balance-mobile" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => 'package', 'id' => (int)$pkg['id'], 'ref' => $pkg['reference_number'], 'guest' => $pkg['guest_name'] ?? '', 'total' => PaymentService::amounts($pkg)['total'], 'paid' => PaymentService::amounts($pkg)['paid'], 'balance' => PaymentService::amounts($pkg)['balance'], 'reserved_at' => $pkg['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                    <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                </button>
                                <?php endif; ?>
                                <?php if(bookingCancelAllowed($pkg)): ?><button type="button" class="btn-card-action btn-reject-mobile" onclick="openAdminCancelModal('package', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>', '<?php echo htmlspecialchars($pkg['guest_name']); ?>', <?php echo PaymentService::amounts($pkg)['paid']; ?>)">
                                    <i class="fas fa-ban"></i> Cancel
                                </button><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #94a3b8; background:white; border-radius:14px; border:1px solid #e8f0fe;">
                            <i class="fas fa-box-open" style="display: block; font-size: 30px; margin-bottom: 10px; color: #cbd5e1;"></i>
                            <p>No active package bookings.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- REBOOKS SECTION -->
        <div id="rebook-section" class="booking-section">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-clock" style="color:#f59e0b;"></i> Pending Rebooks (<?php echo $rebook_pending_count; ?>)</h2>
                </div>

                <?php if($rebook_pending_count > 0): ?>
                <div class="rebook-info-note">
                    <i class="fas fa-info-circle"></i>
                    <span>These bookings have been rebooked by guests with new dates. Payment is already <strong>PAID</strong>. Click <strong>"Confirm Rebook"</strong> to finalize, or <strong>"Reject Rebook"</strong> to revert to the original dates.</span>
                </div>

                <div class="table-responsive desktop-table" style="margin-top: 15px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Ref #</th><th>Guest</th><th>House</th><th>Rebooked On</th><th>New Check In</th>
                                <th>New Check Out</th><th>Guests</th><th>Total</th><th>Payment</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending_rebooks as $booking): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong><?php echo walkInChip($booking); ?>
                                    <span class="rebook-badge"><i class="fas fa-redo"></i> #<?php echo $booking['rebook_count']; ?>/2</span>
                                </td>
                                <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                <td><?php echo htmlspecialchars($booking['house_name']); ?></td>
                                <td><?php echo formatDateTimeDisplay($booking['rebooked_at'] ?? null); ?></td>
                                <td>
                                    <?php echo formatDateDisplay($booking['check_in_date']); ?>
                                    <?php if (!empty($booking['check_in_time'])): ?>
                                        <div class="booking-time-row">
                                            <i class="fas fa-clock"></i>
                                            <?php echo formatTimeDisplay($booking['check_in_time']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo formatDateDisplay($booking['check_out_date']); ?>
                                    <?php if (!empty($booking['check_out_time'])): ?>
                                        <div class="booking-time-row">
                                            <i class="fas fa-clock"></i>
                                            <?php echo formatTimeDisplay($booking['check_out_time']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $booking['number_of_guests']; ?></td>
                                <td><?php echo PaymentService::adminAmountCell($booking); ?></td>
                                <td><?php echo PaymentService::adminBadge($booking); ?></td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                        <button class="btn-view-booking" onclick='viewBooking("house", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button type="button" class="btn-sm btn-rebook-confirm" onclick="openConfirmModal('rebook', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['guest_name']); ?>', '<?php echo htmlspecialchars($booking['house_name']); ?>')">
                                            <i class="fas fa-check-circle"></i> Confirm
                                        </button>
                                        <button type="button" class="btn-sm btn-rebook-reject" onclick="openRejectRebookModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #94a3b8;">
                    <i class="fas fa-check-circle" style="display: block; font-size: 40px; margin-bottom: 10px; color: #10b981;"></i>
                    <p>No pending rebooks. 🎉</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ✅ HISTORY SECTION -->
        <div id="history-section" class="booking-section">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-history" style="color:#64748b;"></i> Booking History (<?php echo $total_history; ?>)</h2>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-sm btn-info" onclick="printHistory()" style="padding: 8px 16px; font-size: 12px;">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button type="button" class="btn-sm btn-success" onclick="exportHistoryCSV()" style="padding: 8px 16px; font-size: 12px;">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                    </div>
                </div>

                <?php if($total_history > 0): ?>
                <div class="rebook-info-note" style="background: linear-gradient(135deg, #f1f5f9, #f8fafc); border-color: #cbd5e1; color: #475569;">
                    <i class="fas fa-info-circle" style="color:#64748b;"></i>
                    <span>Nakalista dito ang lahat ng <strong>completed</strong> at <strong>cancelled</strong> bookings. Hindi na sila kasama sa active listings.</span>
                </div>

                <div class="table-responsive desktop-table" style="margin-top: 15px;">
                    <table id="historyTable">
                        <thead>
                            <tr>
                                <th>Ref #</th><th>Type</th><th>Guest</th><th>Item</th>
                                <th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $all_history = [];
                            foreach($history_house as $b) { $b['_type'] = 'house'; $b['_item'] = $b['house_name'] ?? ''; $all_history[] = $b; }
                            foreach($history_tour as $b)  { $b['_type'] = 'tour';  $b['_item'] = $b['tour_name'] ?? '';  $all_history[] = $b; }
                            foreach($history_food as $b)  { $b['_type'] = 'food';  $b['_item'] = $b['food_name'] ?? '';  $all_history[] = $b; }
                            foreach($history_package as $b) { $b['_type'] = 'package'; $b['_item'] = $b['reference_number']; $all_history[] = $b; }
                            usort($all_history, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
                            ?>
                            <?php foreach($all_history as $item): 
                                $type_badge = [
                                    'house'   => '<span class="badge badge-info"><i class="fas fa-home"></i> House</span>',
                                    'tour'    => '<span class="badge badge-info"><i class="fas fa-umbrella-beach"></i> Tour</span>',
                                    'food'    => '<span class="badge badge-info"><i class="fas fa-utensils"></i> Food</span>',
                                    'package' => '<span class="badge badge-package"><i class="fas fa-box-open"></i> Package</span>',
                                ][$item['_type']];
                                $status_class = $item['booking_status'] === 'completed' ? 'badge-success' : 'badge-danger';
                                $status_icon = $item['booking_status'] === 'completed' ? 'fa-check-double' : 'fa-times-circle';
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['reference_number']); ?></strong></td>
                                <td><?php echo $type_badge; ?></td>
                                <td><?php echo htmlspecialchars($item['guest_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($item['_item']); ?></td>
                                <td><?php echo formatDateDisplay($item['created_at']); ?></td>
                                <td><?php echo PaymentService::adminAmountCell($item); ?></td>
                                <td><?php echo PaymentService::adminBadge($item); ?></td>
                                <td><span class="badge <?php echo $status_class; ?>"><i class="fas <?php echo $status_icon; ?>"></i> <?php echo ucfirst($item['booking_status']); ?></span></td>
                                <td>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <button class="btn-view-booking" onclick='viewBooking("<?php echo $item['_type']; ?>", <?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if (PaymentService::state($item) === 'reservation_paid'): ?>
                                        <button type="button" class="btn-sm btn-balance" onclick='openBalanceModal(<?php echo htmlspecialchars(json_encode(['type' => $item['_type'], 'id' => (int)$item['id'], 'ref' => $item['reference_number'], 'guest' => $item['guest_name'] ?? '', 'total' => PaymentService::amounts($item)['total'], 'paid' => PaymentService::amounts($item)['paid'], 'balance' => PaymentService::amounts($item)['balance'], 'reserved_at' => $item['reservation_paid_at'] ?? null]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                            <i class="fas fa-hand-holding-usd"></i> Mark Balance Paid
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #94a3b8;">
                    <i class="fas fa-history" style="display: block; font-size: 40px; margin-bottom: 10px; color: #cbd5e1;"></i>
                    <p>Wala pang completed o cancelled bookings. 🎉</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>

        <div class="footer">
            <p>
                <i class="fas fa-umbrella-beach"></i>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Huddled Islands Tour and Reservation. All rights reserved.'); ?>
                <span style="opacity: 0.3; margin: 0 10px;">|</span>
                <span style="color: #7bb8f0; font-size: 11px;">
                    <i class="fas fa-user-shield"></i> <?php echo $is_admin ? 'Administrator' : 'Staff'; ?> Access
                </span>
            </p>
        </div>
    </div>
</div>

<!-- PROOF MODAL -->
<div class="modal" id="proofModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-image"></i> Payment Proof</h3>
            <button class="close" onclick="closeProofModal()">&times;</button>
        </div>
        <div id="proofGcashBox" style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); border: 2px solid #10b981; border-radius: 12px; padding: 15px 20px; margin-bottom: 15px; display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; background: #10b981; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                <i class="fas fa-hashtag"></i>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div style="font-size: 11px; color: #065f46; font-weight: 700; text-transform: uppercase;">GCash Reference Number</div>
                <div id="proofGcashRef" style="font-size: 20px; font-weight: 800; color: #065f46; letter-spacing: 2px; font-family: 'Courier New', monospace; word-break: break-all;">—</div>
            </div>
            <button type="button" onclick="copyGcashRef()" id="copyRefBtn" style="background: white; color: #10b981; border: 1px solid #10b981; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 12px; cursor: pointer; white-space: nowrap;">
                <i class="fas fa-copy"></i> Copy
            </button>
        </div>
        <div style="text-align: center;">
            <img id="proofImage" class="proof-image" src="" alt="Payment Proof">
            <div id="proofCaption" style="margin-top: 10px; color: #64748b; font-size: 14px;"></div>
        </div>
    </div>
</div>

<!-- CONFIRM MODAL -->
<div class="confirm-modal-overlay" id="confirmActionModal">
    <div class="confirm-modal payment-variant" id="confirmActionContent">
        <div class="confirm-modal-icon" id="confirmActionIcon">
            <i class="fas fa-check-circle" id="confirmActionIconInner"></i>
        </div>
        <h3 id="confirmActionTitle">Confirm Action</h3>
        <p id="confirmActionMessage">Are you sure you want to proceed?</p>
        <div class="confirm-ref-box">
            <div class="ref-row"><span class="ref-label">Reference</span><span class="ref-value" id="confirmActionRef">—</span></div>
            <div class="ref-row"><span class="ref-label">Guest</span><span class="ref-value" id="confirmActionGuest" style="font-family: inherit;">—</span></div>
            <div class="ref-row" id="confirmActionItemRow"><span class="ref-label" id="confirmActionItemLabel">Item</span><span class="ref-value" id="confirmActionItem" style="font-family: inherit;">—</span></div>
        </div>
        <div class="confirm-modal-warning">
            <i class="fas fa-info-circle"></i>
            <span id="confirmActionWarningText">This action cannot be undone.</span>
        </div>
        <form method="POST" id="confirmActionForm">
            <input type="hidden" name="booking_id" id="confirmActionBookingId">
            <input type="hidden" name="booking_type" id="confirmActionBookingType">
            <input type="hidden" name="confirm_action_type" id="confirmActionType">
            <div class="confirm-modal-actions">
                <button type="button" class="btn-confirm-cancel" onclick="closeConfirmModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn-confirm-submit" id="confirmActionSubmitBtn" name="confirm_payment">
                    <i class="fas fa-check-circle"></i> <span id="confirmActionSubmitText">Confirm</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MARK BALANCE PAID MODAL -->
<div class="modal" id="balanceModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header" style="border-bottom: 2px solid #d1fae5;">
            <h3 style="color: #065f46;"><i class="fas fa-hand-holding-usd" style="color: #10b981;"></i> Mark Balance as Paid</h3>
            <button class="close" type="button" onclick="closeBalanceModal()">&times;</button>
        </div>
        <form method="POST" id="balanceForm">
            <input type="hidden" name="mark_balance_paid" value="1">
            <input type="hidden" name="booking_id" id="balance_booking_id">
            <input type="hidden" name="booking_type" id="balance_booking_type">
            <div class="pay-summary">
                <div class="row-line"><span>Reference</span><strong id="balanceRef">—</strong></div>
                <div class="row-line"><span>Guest</span><span id="balanceGuest">—</span></div>
                <div class="row-line"><span>Total booking price</span><span id="balanceTotal">₱0.00</span></div>
                <div class="row-line"><span>Already paid</span><span id="balancePaid">₱0.00</span></div>
                <div class="row-line due"><span>Balance to receive now</span><strong id="balanceDue">₱0.00</strong></div>
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; color:#1e293b; font-size:13px;">How was it paid? <span style="color:#dc2626;">*</span></label>
                <select name="payment_method" required style="width:100%; padding:10px 12px; border:2px solid #e2e8f0; border-radius:8px; font-size:14px;">
                    <?php foreach (PaymentService::METHODS as $mKey => $mLabel): ?>
                        <option value="<?php echo $mKey; ?>"><?php echo htmlspecialchars($mLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; color:#1e293b; font-size:13px;">Date &amp; time received</label>
                <input type="datetime-local" name="received_at" id="balance_received_at" style="width:100%; padding:10px 12px; border:2px solid #e2e8f0; border-radius:8px; font-size:14px;">
                <div class="pay-note">Leave as is if the balance was received just now. For an earlier stay, enter the actual date it was received.</div>
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; color:#1e293b; font-size:13px;">Note <span style="color:#94a3b8;">(optional)</span></label>
                <input type="text" name="payment_notes" maxlength="255" placeholder="e.g. paid in cash at check-in" style="width:100%; padding:10px 12px; border:2px solid #e2e8f0; border-radius:8px; font-size:14px;">
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="button" onclick="closeBalanceModal()" style="flex:1; padding:12px; background:#e2e8f0; color:#475569; border:none; border-radius:10px; font-weight:700; cursor:pointer;"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" style="flex:1; padding:12px; background:linear-gradient(135deg,#10b981,#059669); color:white; border:none; border-radius:10px; font-weight:700; cursor:pointer;"><i class="fas fa-check"></i> Balance Received</button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT PAYMENT MODAL -->
<div class="modal" id="rejectReasonModal">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header" style="border-bottom: 2px solid #fee2e2;">
            <h3 style="color: #991b1b;"><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> <span id="rejectModalTitle">Reject Payment?</span></h3>
            <button class="close" onclick="closeRejectModal()">&times;</button>
        </div>
        <form method="POST" id="rejectReasonForm">
            <input type="hidden" name="booking_id" id="reject_booking_id">
            <input type="hidden" name="booking_type" id="reject_booking_type">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-comment-alt" style="color: #ef4444;"></i> Reason for Rejection <span style="color: #dc2626;">*</span>
                </label>
                <select name="reject_reason" id="reject_reason_select" required onchange="toggleRejectOther()" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                    <option value="">-- Select a reason --</option>
                    <option value="Invalid or unclear payment proof">Invalid or unclear payment proof</option>
                    <option value="GCash reference number does not match">GCash reference number does not match</option>
                    <option value="Amount paid is incorrect">Amount paid is incorrect</option>
                    <option value="Duplicate booking">Duplicate booking</option>
                    <option value="Booking dates no longer available">Booking dates no longer available</option>
                    <option value="Suspicious or fraudulent activity">Suspicious or fraudulent activity</option>
                    <option value="Guest requested cancellation">Guest requested cancellation</option>
                    <option value="Other">Other (specify below)</option>
                </select>
            </div>
            <div id="rejectOtherWrapper" style="display: none; margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-pen" style="color: #ef4444;"></i> Please specify
                </label>
                <input type="text" name="reject_reason_other" id="reject_reason_other" placeholder="Type the reason here..." maxlength="200" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-sticky-note" style="color: #64748b;"></i> Additional Notes <span style="color: #94a3b8;">(optional)</span>
                </label>
                <textarea name="reject_notes" id="reject_notes" rows="3" placeholder="Add more details..." maxlength="500" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeRejectModal()" style="flex: 1; padding: 12px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" name="reject_payment" style="flex: 1; padding: 12px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-times-circle"></i> Reject Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT REBOOK MODAL -->
<div class="modal" id="rejectRebookModal">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header" style="border-bottom: 2px solid #fee2e2;">
            <h3 style="color: #991b1b;"><i class="fas fa-undo" style="color: #ef4444;"></i> <span id="rejectRebookTitle">Reject Rebook?</span></h3>
            <button class="close" onclick="closeRejectRebookModal()">&times;</button>
        </div>
        <form method="POST" id="rejectRebookForm">
            <input type="hidden" name="booking_id" id="reject_rebook_booking_id">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-comment-alt" style="color: #ef4444;"></i> Reason for Rejection <span style="color: #dc2626;">*</span>
                </label>
                <select name="reject_reason" id="reject_rebook_reason_select" required onchange="toggleRejectRebookOther()" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                    <option value="">-- Select a reason --</option>
                    <option value="New dates are not available">New dates are not available</option>
                    <option value="Conflicts with existing booking">Conflicts with existing booking</option>
                    <option value="Invalid rebook request">Invalid rebook request</option>
                    <option value="Guest requested cancellation">Guest requested cancellation</option>
                    <option value="Policy violation">Policy violation</option>
                    <option value="Suspicious or fraudulent activity">Suspicious or fraudulent activity</option>
                    <option value="Other">Other (specify below)</option>
                </select>
            </div>
            <div id="rejectRebookOtherWrapper" style="display: none; margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-pen" style="color: #ef4444;"></i> Please specify
                </label>
                <input type="text" name="reject_reason_other" id="reject_rebook_reason_other" placeholder="Type the reason here..." maxlength="200" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-sticky-note" style="color: #64748b;"></i> Additional Notes <span style="color: #94a3b8;">(optional)</span>
                </label>
                <textarea name="reject_notes" id="reject_rebook_notes" rows="3" placeholder="Add more details..." maxlength="500" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeRejectRebookModal()" style="flex: 1; padding: 12px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" name="reject_rebook" style="flex: 1; padding: 12px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-undo"></i> Reject Rebook
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ADMIN CANCEL MODAL -->
<div class="modal" id="adminCancelModal">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header" style="border-bottom: 2px solid #fee2e2;">
            <h3 style="color: #991b1b;"><i class="fas fa-ban" style="color: #ef4444;"></i> Cancel Booking</h3>
            <button class="close" onclick="closeAdminCancelModal()">&times;</button>
        </div>
        <form method="POST" id="adminCancelForm">
            <input type="hidden" name="admin_cancel_booking" value="1">
            <input type="hidden" name="booking_id" id="admin_cancel_booking_id">
            <input type="hidden" name="booking_type" id="admin_cancel_booking_type">
            <p style="margin:0 0 14px; font-size:15px; font-weight:600; color:#1e293b;">Are you sure you want to cancel this booking?</p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 18px;">
                <div style="display: flex; justify-content: space-between; padding: 3px 0; font-size: 13px;">
                    <span style="color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase;">Reference</span>
                    <span id="adminCancelRef" style="font-weight: 700; color: #0B2447; font-family: 'Courier New', monospace;">—</span>
                </div>
                <div style="display: flex; justify-content: space-between; padding: 3px 0; font-size: 13px;">
                    <span style="color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase;">Guest</span>
                    <span id="adminCancelGuest" style="font-weight: 600; color: #0B2447;">—</span>
                </div>
            </div>
            <div id="adminCancelCreditNotice" style="display:none; background:#f5f3ff; border:1px solid #ddd6fe; color:#5b21b6; border-radius:12px; padding:10px 14px; margin-bottom:15px; font-size:13px; line-height:1.5;">
                <i class="fas fa-redo"></i> <strong><span id="adminCancelCreditAmount">₱0.00</span> has already been paid.</strong>
                Reservation fees are non-refundable, so this amount stays recorded as a <strong>rebooking credit</strong>.
                The booking will show as <strong>Rebooking Required</strong> and its dates will be released.
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-comment-alt" style="color: #ef4444;"></i> Reason for Cancellation <span style="color: #dc2626;">*</span>
                </label>
                <select name="cancel_reason" id="admin_cancel_reason_select" required onchange="toggleAdminCancelOther()" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
                    <option value="">-- Select a reason --</option>
                    <option value="Guest requested cancellation">Guest requested cancellation</option>
                    <option value="Accidental booking">Accidental booking</option>
                    <option value="Payment issue">Payment issue</option>
                    <option value="Schedule conflict">Schedule conflict</option>
                    <option value="Duplicate booking">Duplicate booking</option>
                    <option value="Suspicious activity">Suspicious activity</option>
                    <option value="Other">Other (specify below)</option>
                </select>
            </div>
            <div id="adminCancelOtherWrapper" style="display: none; margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-pen" style="color: #ef4444;"></i> Please specify
                </label>
                <input type="text" name="cancel_reason_other" id="admin_cancel_reason_other" placeholder="Type the reason here..." maxlength="200" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #1e293b; font-size: 13px;">
                    <i class="fas fa-sticky-note" style="color: #64748b;"></i> Additional Notes <span style="color: #94a3b8;">(optional)</span>
                </label>
                <textarea name="cancel_notes" id="admin_cancel_notes" rows="3" placeholder="Add more details..." maxlength="500" style="width: 100%; padding: 10px 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;"></textarea>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeAdminCancelModal()" style="flex: 1; min-height: 46px; padding: 12px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" style="flex: 1; min-height: 46px; padding: 12px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-ban"></i> Confirm Cancellation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW BOOKING MODAL -->
<div class="modal view-booking-modal pkg-booking-modal" id="viewBookingModal">
    <div class="modal-content pkg-modal-box">
        <div class="pkg-modal-header">
            <div class="pkg-title">
                <i class="fas fa-box-open" id="viewBookingIcon"></i>
                <span id="viewBookingTitle">Booking Details</span>
            </div>
            <button class="close" onclick="closeViewBookingModal()" aria-label="Close">&times;</button>
        </div>
        <div class="pkg-modal-body" id="viewBookingContent"></div>
    </div>
</div>

<!-- LOGOUT MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon"><i class="fas fa-sign-out-alt"></i></div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="?logout=1" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script>
/* ============================================================
   SIDEBAR
   ============================================================ */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');
    const willOpen = !sidebar.classList.contains('open');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    toggleBtn.classList.toggle('active');
    if (willOpen && window.innerWidth <= 1024) {
        document.body.classList.add('sidebar-open-mobile');
    } else {
        document.body.classList.remove('sidebar-open-mobile');
    }
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : 'auto';
}

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth > 1024 && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.getElementById('menuToggle').classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
        document.body.style.overflow = 'auto';
    }
});

/* ============================================================
   TAB SWITCHING
   ============================================================ */
function showBookingType(type) {
    ['house', 'tour', 'food', 'package', 'rebook', 'history'].forEach(function(t) {
        var el = document.getElementById(t + '-section');
        if (el) el.classList.remove('active');
    });
    document.querySelectorAll('.booking-tab').forEach(function(tab) { tab.classList.remove('active'); });

    var tabMap = { 'house': 0, 'tour': 1, 'food': 2, 'package': 3, 'rebook': 4, 'history': 5 };
    var sectionEl = document.getElementById(type + '-section');
    var tabs = document.querySelectorAll('.booking-tab');

    if (sectionEl) sectionEl.classList.add('active');
    if (tabMap[type] !== undefined && tabs[tabMap[type]]) tabs[tabMap[type]].classList.add('active');

    if (history.replaceState) history.replaceState(null, '', '#' + type);
}

/* ============================================================
   CONFIRM MODAL
   ============================================================ */

// ============================================================
// Payment state (mirrors includes/PaymentService.php)
// ============================================================
function payInfo(b) {
    var total = parseFloat(b.grand_total !== undefined && b.grand_total !== null ? b.grand_total : (b.total_amount || 0)) || 0;
    var isComponent = !!(b.package_id && parseInt(b.package_id, 10) > 0 && (b.grand_total === undefined || b.grand_total === null));
    var isLegacy = !!(b.original_booking_id && parseInt(b.original_booking_id, 10) > 0);
    var fee = parseFloat(b.reservation_fee_amount || 0) || Math.min(1000, total);
    var paid = (b.amount_paid !== undefined && b.amount_paid !== null) ? (parseFloat(b.amount_paid) || 0)
             : ((b.payment_status === 'paid' || b.payment_status === 'reservation_paid') ? Math.min(fee, total) : 0);
    if (isComponent || isLegacy) { fee = 0; paid = 0; }
    var balance = Math.max(0, Math.round((total - paid) * 100) / 100);
    var state;
    if (isLegacy) state = 'legacy_rebook';
    else if (isComponent) state = 'component';
    else if (b.booking_status === 'cancelled') state = paid > 0 ? 'rebook_required' : (b.payment_status === 'cancelled' ? 'rejected' : 'cancelled');
    else if (b.payment_status === 'cancelled') state = 'rejected';
    else if (paid <= 0) state = 'unpaid';
    else if (balance <= 0) state = 'paid';
    else state = 'reservation_paid';
    var labels = {
        unpaid: ['Pending Payment', 'badge-warning', 'fa-clock'],
        reservation_paid: ['Reservation Fee Paid', 'badge-info', 'fa-receipt'],
        paid: ['Fully Paid', 'badge-success', 'fa-check-circle'],
        rebook_required: ['Rebooking Required', 'badge-rebook', 'fa-redo'],
        cancelled: ['Cancelled (unpaid)', 'badge-danger', 'fa-times-circle'],
        rejected: ['Payment Rejected', 'badge-danger', 'fa-times-circle'],
        component: ['Part of package', 'badge-info', 'fa-box-open'],
        legacy_rebook: ['Old rebooking record', 'badge-info', 'fa-history']
    };
    var l = labels[state];
    return { state: state, total: total, fee: fee, paid: paid, balance: balance,
             badge: '<span class="badge ' + l[1] + '"><i class="fas ' + l[2] + '"></i> ' + l[0] + '</span>' };
}
function pesoJs(n) { return '₱' + (parseFloat(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function payRowsHtml(b, rowClass, labelClass, valueClass) {
    var p = payInfo(b);
    var row = function (icon, label, value) {
        return '<div class="' + rowClass + '"><span class="' + labelClass + '"><i class="fas ' + icon + '"></i> ' + label + '</span><span class="' + valueClass + '">' + value + '</span></div>';
    };
    var h = row('fa-money-bill-wave', 'Total Booking Price', '<strong>' + pesoJs(p.total) + '</strong>');
    if (p.state === 'component') return h + row('fa-box-open', 'Payment', 'Paid with the package');
    if (p.state === 'legacy_rebook') return h + row('fa-history', 'Payment', 'Old rebooking record — not a separate sale');
    h += row('fa-receipt', 'Reservation Fee', pesoJs(p.fee));
    h += row('fa-coins', 'Amount Paid', pesoJs(p.paid));
    if (p.state === 'rebook_required') h += row('fa-redo', 'Rebooking Credit', '<strong style="color:#6d28d9;">' + pesoJs(p.paid) + '</strong> (non-refundable)');
    else if (p.state !== 'cancelled' && p.state !== 'rejected') h += row('fa-wallet', 'Balance Due', '<strong style="color:' + (p.balance > 0 ? '#b45309' : '#047857') + ';">' + pesoJs(p.balance) + '</strong>');
    if (b.reservation_paid_at) h += row('fa-calendar-check', 'Reservation Fee Received', escapeHtml(b.reservation_paid_at));
    if (b.balance_paid_at) h += row('fa-calendar-check', 'Balance Received', escapeHtml(b.balance_paid_at));
    return h;
}

function openConfirmModal(actionType, bookingId, reference, guestName, extra, amount) {
    var modal = document.getElementById('confirmActionModal');
    var content = document.getElementById('confirmActionContent');
    var iconInner = document.getElementById('confirmActionIconInner');
    var titleEl = document.getElementById('confirmActionTitle');
    var msgEl = document.getElementById('confirmActionMessage');
    var refEl = document.getElementById('confirmActionRef');
    var guestEl = document.getElementById('confirmActionGuest');
    var itemRow = document.getElementById('confirmActionItemRow');
    var itemLabel = document.getElementById('confirmActionItemLabel');
    var itemEl = document.getElementById('confirmActionItem');
    var warningText = document.getElementById('confirmActionWarningText');
    var bookingIdInput = document.getElementById('confirmActionBookingId');
    var bookingTypeInput = document.getElementById('confirmActionBookingType');
    var typeInput = document.getElementById('confirmActionType');
    var submitBtn = document.getElementById('confirmActionSubmitBtn');

    content.classList.remove('rebook-variant', 'payment-variant', 'package-variant');

    bookingIdInput.value = bookingId;
    refEl.textContent = reference;
    guestEl.textContent = guestName || '—';

    if (actionType === 'rebook') {
        content.classList.add('rebook-variant');
        iconInner.className = 'fas fa-redo';
        titleEl.textContent = 'Confirm Rebook?';
        msgEl.textContent = 'The guest has updated their stay dates. Confirming will lock in the new dates.';
        itemLabel.textContent = 'House';
        itemEl.textContent = extra || '—';
        itemRow.style.display = 'flex';
        warningText.innerHTML = 'The amount already paid is <strong>carried forward</strong> — no new reservation fee. The new dates will be <strong>locked in</strong> and the guest will receive a confirmation email.';
        submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Yes, Confirm Rebook';
        bookingTypeInput.value = 'house';
        typeInput.value = 'rebook';
        submitBtn.name = 'confirm_rebook';
    } else if (actionType === 'payment') {
        if (extra === 'package') {
            content.classList.add('package-variant');
            iconInner.className = 'fas fa-box-open';
        } else {
            content.classList.add('payment-variant');
            iconInner.className = 'fas fa-check-circle';
        }
        titleEl.textContent = 'Confirm Reservation Fee?';
        msgEl.textContent = 'Check the GCash proof: the guest should have paid the reservation fee shown below.';
        itemLabel.textContent = 'Reservation fee';
        itemEl.innerHTML = pesoJs(amount);
        itemRow.style.display = 'flex';
        warningText.innerHTML = 'This records <strong>' + pesoJs(amount) + ' received</strong>. The booking becomes <strong>Reservation Fee Paid</strong>; the remaining balance is collected on arrival with <strong>Mark Balance Paid</strong>.';
        submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Yes, Fee Received';
        bookingTypeInput.value = extra || '';
        typeInput.value = 'payment';
        submitBtn.name = 'confirm_payment';
    }

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function openBalanceModal(d) {
    document.getElementById('balance_booking_id').value = d.id;
    document.getElementById('balance_booking_type').value = d.type;
    document.getElementById('balanceRef').textContent = d.ref || '—';
    document.getElementById('balanceGuest').textContent = d.guest || '—';
    document.getElementById('balanceTotal').textContent = pesoJs(d.total);
    document.getElementById('balancePaid').textContent = pesoJs(d.paid);
    document.getElementById('balanceDue').textContent = pesoJs(d.balance);
    var now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    var input = document.getElementById('balance_received_at');
    input.value = now.toISOString().slice(0, 16);
    input.max = input.value;
    if (d.reserved_at) input.min = String(d.reserved_at).replace(' ', 'T').slice(0, 16);
    document.getElementById('balanceModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeBalanceModal() {
    document.getElementById('balanceModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Prevent double submission of any action form (server side is idempotent too)
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post') return;
    if (form.dataset.submitting === '1') { e.preventDefault(); return; }
    form.dataset.submitting = '1';
    var submitter = e.submitter;
    if (submitter && submitter.name && !form.querySelector('input[type=hidden][name="' + submitter.name + '"]')) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value || '1';
        form.appendChild(h);
    }
    setTimeout(function () {
        form.querySelectorAll('button[type=submit], input[type=submit]').forEach(function (b) {
            b.disabled = true;
            if (!b.dataset.origHtml) b.dataset.origHtml = b.innerHTML;
            b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
        });
    }, 0);
}, true);

function closeConfirmModal() {
    document.getElementById('confirmActionModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

/* ============================================================
   REJECT MODAL
   ============================================================ */
function openRejectModal(bookingId, bookingType, reference) {
    document.getElementById('reject_booking_id').value = bookingId;
    document.getElementById('reject_booking_type').value = bookingType;
    document.getElementById('reject_reason_select').value = '';
    document.getElementById('reject_reason_other').value = '';
    document.getElementById('reject_notes').value = '';
    document.getElementById('rejectOtherWrapper').style.display = 'none';
    document.getElementById('reject_reason_other').required = false;
    document.getElementById('rejectModalTitle').textContent = 'Reject Payment — ' + reference + '?';
    document.getElementById('rejectReasonModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeRejectModal() {
    document.getElementById('rejectReasonModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function toggleRejectOther() {
    var select = document.getElementById('reject_reason_select');
    var wrapper = document.getElementById('rejectOtherWrapper');
    var input = document.getElementById('reject_reason_other');
    if (select.value === 'Other') {
        wrapper.style.display = 'block';
        input.required = true;
        input.focus();
    } else {
        wrapper.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

/* ============================================================
   REJECT REBOOK MODAL
   ============================================================ */
function openRejectRebookModal(bookingId, reference) {
    document.getElementById('reject_rebook_booking_id').value = bookingId;
    document.getElementById('reject_rebook_reason_select').value = '';
    document.getElementById('reject_rebook_reason_other').value = '';
    document.getElementById('reject_rebook_notes').value = '';
    document.getElementById('rejectRebookOtherWrapper').style.display = 'none';
    document.getElementById('reject_rebook_reason_other').required = false;
    document.getElementById('rejectRebookTitle').textContent = 'Reject Rebook — ' + reference + '?';
    document.getElementById('rejectRebookModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeRejectRebookModal() {
    document.getElementById('rejectRebookModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function toggleRejectRebookOther() {
    var select = document.getElementById('reject_rebook_reason_select');
    var wrapper = document.getElementById('rejectRebookOtherWrapper');
    var input = document.getElementById('reject_rebook_reason_other');
    if (select.value === 'Other') {
        wrapper.style.display = 'block';
        input.required = true;
        input.focus();
    } else {
        wrapper.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

/* ============================================================
   ADMIN CANCEL MODAL
   ============================================================ */
function openAdminCancelModal(bookingType, bookingId, reference, guestName, amountPaid) {
    var creditBox = document.getElementById('adminCancelCreditNotice');
    if (creditBox) {
        var paidNum = parseFloat(amountPaid || 0) || 0;
        creditBox.style.display = paidNum > 0 ? 'block' : 'none';
        var amt = document.getElementById('adminCancelCreditAmount');
        if (amt) amt.textContent = pesoJs(paidNum);
    }
    document.getElementById('admin_cancel_booking_id').value = bookingId;
    document.getElementById('admin_cancel_booking_type').value = bookingType;
    document.getElementById('adminCancelRef').textContent = reference;
    document.getElementById('adminCancelGuest').textContent = guestName || '—';
    document.getElementById('admin_cancel_reason_select').value = '';
    document.getElementById('admin_cancel_reason_other').value = '';
    document.getElementById('admin_cancel_notes').value = '';
    document.getElementById('adminCancelOtherWrapper').style.display = 'none';
    document.getElementById('admin_cancel_reason_other').required = false;
    document.getElementById('adminCancelModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeAdminCancelModal() {
    document.getElementById('adminCancelModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function toggleAdminCancelOther() {
    var select = document.getElementById('admin_cancel_reason_select');
    var wrapper = document.getElementById('adminCancelOtherWrapper');
    var input = document.getElementById('admin_cancel_reason_other');
    if (select.value === 'Other') {
        wrapper.style.display = 'block';
        input.required = true;
        input.focus();
    } else {
        wrapper.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

/* ============================================================
   PROOF MODAL
   ============================================================ */
var currentGcashRef = '';

function viewProof(filename, reference, gcashReference) {
    var imgEl = document.getElementById('proofImage');
    if (imgEl) {
        imgEl.src = 'uploads/payments/' + filename + '?t=' + new Date().getTime();
        imgEl.alt = 'Payment Proof - ' + reference;
    }

    var captionEl = document.getElementById('proofCaption');
    if (captionEl) {
        captionEl.innerHTML = '<strong>Booking Ref: ' + reference + '</strong><br>Payment proof uploaded by user.';
    }

    var gcashBox = document.getElementById('proofGcashBox');
    var gcashEl = document.getElementById('proofGcashRef');
    var copyBtn = document.getElementById('copyRefBtn');

    if (gcashReference && gcashReference.trim() !== '') {
        currentGcashRef = gcashReference.trim();
        gcashEl.textContent = currentGcashRef;
        gcashEl.style.fontStyle = 'normal';
        gcashEl.style.color = '#065f46';
        gcashBox.style.display = 'flex';
        gcashBox.style.background = 'linear-gradient(135deg, #ecfdf5, #d1fae5)';
        gcashBox.style.borderColor = '#10b981';
        copyBtn.style.display = 'inline-flex';
    } else {
        currentGcashRef = '';
        gcashEl.textContent = 'Not provided';
        gcashEl.style.fontStyle = 'italic';
        gcashEl.style.color = '#94a3b8';
        gcashBox.style.background = '#f8fafc';
        gcashBox.style.borderColor = '#cbd5e1';
        copyBtn.style.display = 'none';
    }

    document.getElementById('proofModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeProofModal() {
    document.getElementById('proofModal').classList.remove('show');
    document.body.style.overflow = 'auto';
    currentGcashRef = '';
}

function copyGcashRef() {
    if (!currentGcashRef) return;
    var btn = document.getElementById('copyRefBtn');

    function showCopied() {
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.style.background = '#10b981';
        btn.style.color = 'white';
        setTimeout(function() {
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            btn.style.background = 'white';
            btn.style.color = '#10b981';
        }, 1800);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(currentGcashRef).then(showCopied).catch(function() {
            fallbackCopy(currentGcashRef, btn);
        });
    } else {
        fallbackCopy(currentGcashRef, btn);
    }
}

function fallbackCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.style.background = '#10b981';
        btn.style.color = 'white';
        setTimeout(function() {
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            btn.style.background = 'white';
            btn.style.color = '#10b981';
        }, 1800);
    } catch(e) {
        alert('Copy failed. Reference: ' + text);
    }
    document.body.removeChild(ta);
}

/* ============================================================
   HELPERS
   ============================================================ */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatTimeDisplay(timeStr) {
    if (!timeStr) return 'N/A';
    try {
        var parts = timeStr.split(':');
        var hours = parseInt(parts[0]);
        var minutes = parts[1] || '00';
        var ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        return hours + ':' + minutes + ' ' + ampm;
    } catch(e) { return timeStr; }
}

function parseDateForDisplay(dateStr) {
    if (!dateStr) return 'N/A';
    var parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    var date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

function formatGuestNamesDisplay(guest_names) {
    if (!guest_names) return 'No guest names recorded';
    var names = guest_names;
    var arr = [];
    if (typeof names === 'string') {
        if (names.startsWith('[') || names.startsWith('{')) {
            try {
                var parsed = JSON.parse(names);
                if (Array.isArray(parsed)) arr = parsed.filter(function(n) { return n && n.trim && n.trim().length > 0; });
                else if (typeof parsed === 'object') arr = Object.values(parsed).filter(function(v) { return typeof v === 'string' && v.trim().length > 0; });
            } catch(e) {}
        }
        if (arr.length === 0 && names.length > 0) {
            if (names.includes('\n')) arr = names.split('\n').map(function(n) { return n.trim(); }).filter(function(n) { return n.length > 0; });
            else if (names.includes(',')) arr = names.split(',').map(function(n) { return n.trim(); }).filter(function(n) { return n.length > 0; });
            else arr = [names.trim()];
        }
    }
    return arr.length > 0 ? arr.join(', ') : 'No guest names recorded';
}

/* ============================================================
   VIEW BOOKING
   ============================================================ */
// Cancellation info (reason / when / who) for the booking details — only for cancelled bookings
function cancellationDetails(b) {
    var out = [];
    if (!b || b.booking_status !== 'cancelled') return out;
    if (b.cancellation_reason) out.push({ label: 'Cancellation Reason', value: '<span style="white-space:pre-line;">' + escapeHtml(b.cancellation_reason) + '</span>' });
    if (b.cancelled_at) {
        var d = new Date(String(b.cancelled_at).replace(' ', 'T'));
        out.push({ label: 'Cancelled At', value: isNaN(d.getTime()) ? escapeHtml(b.cancelled_at) : d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + ' at ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) });
    }
    if (b.cancelled_by) out.push({ label: 'Cancelled By', value: escapeHtml(b.cancelled_by) });
    return out;
}
function cancellationRowsHtml(b, rowCls, labelCls, valueCls) {
    return cancellationDetails(b).map(function (d) {
        return '<div class="' + rowCls + '"><span class="' + labelCls + '"><i class="fas fa-ban"></i> ' + d.label + '</span><span class="' + valueCls + '">' + d.value + '</span></div>';
    }).join('');
}

function viewBooking(type, booking) {
    try {
        var bookingData = typeof booking === 'string' ? JSON.parse(booking) : booking;

        var typeLabel = '';
        var iconClass = 'fas fa-box-open';
        if (type === 'house') { typeLabel = 'House Booking'; iconClass = 'fas fa-home'; }
        else if (type === 'tour') { typeLabel = 'Tour Booking'; iconClass = 'fas fa-umbrella-beach'; }
        else if (type === 'food') { typeLabel = 'Food Booking'; iconClass = 'fas fa-utensils'; }
        else if (type === 'package') { typeLabel = 'Package Booking'; iconClass = 'fas fa-box-open'; }

        var titleEl = document.getElementById('viewBookingTitle');
        if (titleEl) titleEl.textContent = typeLabel;

        var iconEl = document.getElementById('viewBookingIcon');
        if (iconEl) iconEl.className = iconClass;

        var proofBlockHtml = '';
        var hasProof = bookingData.payment_proof && bookingData.payment_proof !== '';
        var hasGcash = bookingData.gcash_reference && bookingData.gcash_reference.trim() !== '';

        if (hasProof || hasGcash) {
            proofBlockHtml += '<div class="pkg-section">';
            proofBlockHtml += '<div class="pkg-section-label"><i class="fas fa-receipt"></i> Payment Proof</div>';

            if (hasGcash) {
                proofBlockHtml += '<div class="pkg-gcash-card">';
                proofBlockHtml += '<div class="pkg-gcash-icon"><i class="fas fa-hashtag"></i></div>';
                proofBlockHtml += '<div class="pkg-gcash-details">';
                proofBlockHtml += '<div class="pkg-gcash-label">GCash Reference</div>';
                proofBlockHtml += '<div class="pkg-gcash-number">' + escapeHtml(bookingData.gcash_reference) + '</div>';
                proofBlockHtml += '</div>';
                proofBlockHtml += '<button type="button" class="pkg-copy-btn" onclick="copyTextToClipboard(\'' + escapeHtml(bookingData.gcash_reference).replace(/'/g, "\\'") + '\', this)">';
                proofBlockHtml += '<i class="fas fa-copy"></i> Copy</button>';
                proofBlockHtml += '</div>';
            }

            if (hasProof) {
                proofBlockHtml += '<div class="pkg-proof-image-wrapper">';
                proofBlockHtml += '<img src="uploads/payments/' + escapeHtml(bookingData.payment_proof) + '?t=' + Date.now() + '" alt="Payment Proof" onclick="openImageFullscreen(\'uploads/payments/' + escapeHtml(bookingData.payment_proof) + '\')" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';">';
                proofBlockHtml += '<div style="display:none; padding:20px; color:#94a3b8; font-size:13px; background:white; border-radius:10px;">Proof image not found.</div>';
                proofBlockHtml += '<div class="pkg-proof-helper"><i class="fas fa-search-plus"></i> Tap image to view fullscreen</div>';
                proofBlockHtml += '</div>';
            } else {
                proofBlockHtml += '<div style="text-align:center; padding:16px; color:#94a3b8; font-size:12px; font-style:italic;">No payment proof uploaded.</div>';
            }

            proofBlockHtml += '</div>';
        }

        var html = proofBlockHtml;
        html += '<div class="pkg-row">';
        html += '<span class="pkg-label"><i class="fas fa-chevron-right"></i> Reference Number</span>';
        html += '<span class="pkg-value" style="font-family:\'Courier New\',monospace; letter-spacing:0.5px;">' + escapeHtml(bookingData.reference_number || 'N/A') + '</span>';
        html += '</div>';

        if (type === 'package') {
            if (bookingData.house_name) {
                html += '<div class="pkg-section-header"><i class="fas fa-home"></i> House <i class="fas fa-chevron-down pkg-chevron"></i></div>';
                html += '<div class="pkg-item-card">';
                html += '<div class="pkg-item-title"><i class="fas fa-home"></i> ' + escapeHtml(bookingData.house_name) + '</div>';
                if (bookingData.house_ref) html += '<div class="pkg-item-detail"><i class="fas fa-hashtag"></i> Ref: <strong style="margin-left:4px;color:#0B2447;">' + escapeHtml(bookingData.house_ref) + '</strong></div>';
                if (bookingData.house_check_in && bookingData.house_check_out) {
                    var pkgCheckIn = parseDateForDisplay(bookingData.house_check_in);
                    if (bookingData.house_check_in_time) pkgCheckIn += ' • ' + formatTimeDisplay(bookingData.house_check_in_time);
                    var pkgCheckOut = parseDateForDisplay(bookingData.house_check_out);
                    if (bookingData.house_check_out_time) pkgCheckOut += ' • ' + formatTimeDisplay(bookingData.house_check_out_time);
                    html += '<div class="pkg-item-detail"><i class="fas fa-calendar-alt"></i> ' + escapeHtml(pkgCheckIn) + ' &rarr; ' + escapeHtml(pkgCheckOut) + '</div>';
                }
                if (bookingData.house_guests) html += '<div class="pkg-item-detail"><i class="fas fa-users"></i> ' + escapeHtml(bookingData.house_guests) + ' guest(s)</div>';
                if (bookingData.house_guest_names) html += '<div class="pkg-item-detail"><i class="fas fa-user-friends"></i> ' + escapeHtml(formatGuestNamesDisplay(bookingData.house_guest_names)) + '</div>';
                if (bookingData.house_amount) html += '<div class="pkg-item-detail"><span class="pkg-item-subtotal">₱' + parseFloat(bookingData.house_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</span></div>';
                html += '</div>';
            }

            if (bookingData.tour_name) {
                html += '<div class="pkg-section-header"><i class="fas fa-umbrella-beach"></i> Tour <i class="fas fa-chevron-down pkg-chevron"></i></div>';
                html += '<div class="pkg-item-card tour">';
                html += '<div class="pkg-item-title"><i class="fas fa-ship"></i> ' + escapeHtml(bookingData.tour_name) + '</div>';
                if (bookingData.tour_ref) html += '<div class="pkg-item-detail"><i class="fas fa-hashtag"></i> Ref: <strong style="margin-left:4px;color:#0B2447;">' + escapeHtml(bookingData.tour_ref) + '</strong></div>';
                if (bookingData.tour_date) {
                    var dateLine = parseDateForDisplay(bookingData.tour_date);
                    if (bookingData.tour_time) dateLine += ' at ' + formatTimeDisplay(bookingData.tour_time);
                    html += '<div class="pkg-item-detail"><i class="fas fa-calendar-alt"></i> ' + escapeHtml(dateLine) + '</div>';
                }
                if (bookingData.tour_guests) html += '<div class="pkg-item-detail"><i class="fas fa-users"></i> ' + escapeHtml(bookingData.tour_guests) + ' guest(s)</div>';
                if (bookingData.tour_guest_name) html += '<div class="pkg-item-detail"><i class="fas fa-user"></i> ' + escapeHtml(bookingData.tour_guest_name) + '</div>';
                if (bookingData.tour_contact) html += '<div class="pkg-item-detail"><i class="fas fa-phone"></i> ' + escapeHtml(bookingData.tour_contact) + '</div>';
                if (bookingData.tour_special_requests) html += '<div class="pkg-item-detail"><i class="fas fa-comment"></i> <em>' + escapeHtml(bookingData.tour_special_requests) + '</em></div>';
                if (bookingData.tour_amount) html += '<div class="pkg-item-detail"><span class="pkg-item-subtotal">₱' + parseFloat(bookingData.tour_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</span></div>';
                html += '</div>';
            }

            if (bookingData.food_name) {
                html += '<div class="pkg-section-header"><i class="fas fa-utensils"></i> Food <i class="fas fa-chevron-down pkg-chevron"></i></div>';
                html += '<div class="pkg-item-card food">';
                html += '<div class="pkg-item-title"><i class="fas fa-utensils"></i> ' + escapeHtml(bookingData.food_name) + '</div>';
                if (bookingData.food_ref) html += '<div class="pkg-item-detail"><i class="fas fa-hashtag"></i> Ref: <strong style="margin-left:4px;color:#0B2447;">' + escapeHtml(bookingData.food_ref) + '</strong></div>';
                if (bookingData.food_size) html += '<div class="pkg-item-detail"><i class="fas fa-arrows-alt-h"></i> Size: ' + escapeHtml(bookingData.food_size) + '</div>';
                if (bookingData.food_qty) html += '<div class="pkg-item-detail"><i class="fas fa-shopping-bag"></i> Qty: ' + escapeHtml(bookingData.food_qty) + '</div>';
                if (bookingData.food_date) {
                    var dateLine2 = parseDateForDisplay(bookingData.food_date);
                    if (bookingData.food_time) dateLine2 += ' at ' + formatTimeDisplay(bookingData.food_time);
                    html += '<div class="pkg-item-detail"><i class="fas fa-calendar-alt"></i> ' + escapeHtml(dateLine2) + '</div>';
                }
                if (bookingData.food_persons) html += '<div class="pkg-item-detail"><i class="fas fa-users"></i> ' + escapeHtml(bookingData.food_persons) + ' person(s)</div>';
                if (bookingData.food_fulfillment) {
                    var badge = bookingData.food_fulfillment === 'delivery' ? '<span class="badge badge-delivery"><i class="fas fa-truck"></i> Delivery</span>' : '<span class="badge badge-pickup"><i class="fas fa-store"></i> Pickup</span>';
                    html += '<div class="pkg-item-detail"><i class="fas fa-truck"></i> Method: ' + badge + '</div>';
                }
                if (bookingData.food_delivery_address && bookingData.food_fulfillment === 'delivery') html += '<div class="pkg-item-detail"><i class="fas fa-map-marker-alt"></i> ' + escapeHtml(bookingData.food_delivery_address) + '</div>';
                if (bookingData.food_contact) html += '<div class="pkg-item-detail"><i class="fas fa-phone"></i> ' + escapeHtml(bookingData.food_contact) + '</div>';
                if (bookingData.food_special_requests) html += '<div class="pkg-item-detail"><i class="fas fa-comment"></i> <em>' + escapeHtml(bookingData.food_special_requests) + '</em></div>';
                if (bookingData.food_amount) html += '<div class="pkg-item-detail"><span class="pkg-item-subtotal">₱' + parseFloat(bookingData.food_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</span></div>';
                html += '</div>';
            }

            if (bookingData.contact_number) {
                html += '<div class="pkg-row"><span class="pkg-label"><i class="fas fa-phone"></i> Contact Number</span><span class="pkg-value"><a href="tel:' + escapeHtml(bookingData.contact_number) + '" style="color:#0B2447; text-decoration:none;">' + escapeHtml(bookingData.contact_number) + '</a></span></div>';
            }
            if (bookingData.special_requests) {
                html += '<div class="pkg-row"><span class="pkg-label"><i class="fas fa-comment"></i> Special Requests</span><span class="pkg-value" style="font-weight:500; color:#475569; white-space:pre-line;">' + escapeHtml(bookingData.special_requests) + '</span></div>';
            }
            html += payRowsHtml(bookingData, 'pkg-row', 'pkg-label', 'pkg-value');

            var paymentBadge = payInfo(bookingData).badge;
            html += '<div class="pkg-row"><span class="pkg-label"><i class="fas fa-credit-card"></i> Payment Status</span><span class="pkg-value">' + paymentBadge + '</span></div>';

            var statusBadge = '';
            if (bookingData.booking_status === 'confirmed') statusBadge = '<span class="pkg-status-pill confirmed"><i class="fas fa-check-circle"></i> CONFIRMED</span>';
            else if (bookingData.booking_status === 'pending') statusBadge = '<span class="pkg-status-pill pending"><i class="fas fa-clock"></i> PENDING</span>';
            else if (bookingData.booking_status === 'completed') statusBadge = '<span class="pkg-status-pill confirmed"><i class="fas fa-check-double"></i> COMPLETED</span>';
            else if (bookingData.booking_status === 'cancelled') statusBadge = '<span class="pkg-status-pill cancelled"><i class="fas fa-times-circle"></i> CANCELLED</span>';
            else statusBadge = '<span class="pkg-status-pill pending"><i class="fas fa-clock"></i> PENDING</span>';
            html += '<div class="pkg-row"><span class="pkg-label"><i class="fas fa-box"></i> Booking Status</span><span class="pkg-value">' + statusBadge + '</span></div>';
            html += cancellationRowsHtml(bookingData, 'pkg-row', 'pkg-label', 'pkg-value');

            if (bookingData.created_at) {
                var bookedDate = new Date(bookingData.created_at);
                if (!isNaN(bookedDate.getTime())) {
                    var dateStr = bookedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                    var timeStr = bookedDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                    html += '<div class="pkg-row" style="border-bottom:none;"><span class="pkg-label"><i class="fas fa-clock"></i> Submitted On</span><span class="pkg-value" style="color:#64748b; font-weight:500; font-size:13px;">' + dateStr + ' at ' + timeStr + '</span></div>';
                }
            }
        } else {
            var details = [];
            details.push({ label: 'Reference Number', value: escapeHtml(bookingData.reference_number || 'N/A') });
            details.push({ label: 'Type', value: escapeHtml(typeLabel) });

            if (type === 'house') {
                if (bookingData.house_name) {
                    var houseInfo = '<div style="background:#f0f7fb; border-left:3px solid #4DA6D9; border-radius:8px; padding:10px 12px; text-align:left;">';
                    houseInfo += '<div style="font-weight:700; color:#0B2447; font-size:13px; margin-bottom:6px;"><i class="fas fa-home" style="color:#4DA6D9;"></i> ' + escapeHtml(bookingData.house_name) + '</div>';
                    if (bookingData.check_in_date && bookingData.check_out_date) houseInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-calendar-alt" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(parseDateForDisplay(bookingData.check_in_date)) + ' &rarr; ' + escapeHtml(parseDateForDisplay(bookingData.check_out_date)) + '</div>';
                    if (bookingData.number_of_guests) houseInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-users" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.number_of_guests) + ' guest(s)</div>';
                    if (bookingData.total_amount) houseInfo += '<div style="font-size:12px; color:#10b981; font-weight:700; margin-top:4px;">₱' + parseFloat(bookingData.total_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</div>';
                    houseInfo += '</div>';
                    details.push({ label: '🏠 House', value: houseInfo });
                }
                var checkInDisplay = parseDateForDisplay(bookingData.check_in_date);
                if (bookingData.check_in_time) checkInDisplay += ' • ' + formatTimeDisplay(bookingData.check_in_time);
                details.push({ label: 'Check In', value: escapeHtml(checkInDisplay) });
                var checkOutDisplay = parseDateForDisplay(bookingData.check_out_date);
                if (bookingData.check_out_time) checkOutDisplay += ' • ' + formatTimeDisplay(bookingData.check_out_time);
                details.push({ label: 'Check Out', value: escapeHtml(checkOutDisplay) });
                details.push({ label: 'Number of Pax', value: escapeHtml(bookingData.number_of_guests || '1') });
            } else if (type === 'tour') {
                if (bookingData.tour_name) {
                    var tourInfo = '<div style="background:#f0f7fb; border-left:3px solid #10b981; border-radius:8px; padding:10px 12px; text-align:left;">';
                    tourInfo += '<div style="font-weight:700; color:#0B2447; font-size:13px; margin-bottom:6px;"><i class="fas fa-umbrella-beach" style="color:#10b981;"></i> ' + escapeHtml(bookingData.tour_name) + '</div>';
                    if (bookingData.booking_date) tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-calendar-alt" style="color:#10b981; width:14px;"></i> ' + escapeHtml(parseDateForDisplay(bookingData.booking_date)) + (bookingData.preferred_time ? ' at ' + escapeHtml(formatTimeDisplay(bookingData.preferred_time)) : '') + '</div>';
                    if (bookingData.number_of_guests) tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-users" style="color:#10b981; width:14px;"></i> ' + escapeHtml(bookingData.number_of_guests) + ' guest(s)</div>';
                    if (bookingData.guest_name) tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-user" style="color:#10b981; width:14px;"></i> ' + escapeHtml(bookingData.guest_name) + '</div>';
                    if (bookingData.contact_number) tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-phone" style="color:#10b981; width:14px;"></i> ' + escapeHtml(bookingData.contact_number) + '</div>';
                    if (bookingData.total_amount) tourInfo += '<div style="font-size:12px; color:#10b981; font-weight:700; margin-top:4px;">₱' + parseFloat(bookingData.total_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</div>';
                    tourInfo += '</div>';
                    details.push({ label: '🏖️ Tour', value: tourInfo });
                }
                if (bookingData.guest_name) details.push({ label: 'Guest Name', value: escapeHtml(bookingData.guest_name) });
                if (bookingData.contact_number) details.push({ label: 'Contact Number', value: '<a href="tel:' + escapeHtml(bookingData.contact_number) + '" style="color:#0B2447; font-weight:600; text-decoration:none;"><i class="fas fa-phone"></i> ' + escapeHtml(bookingData.contact_number) + '</a>' });
                details.push({ label: 'Booking Date', value: escapeHtml(parseDateForDisplay(bookingData.booking_date)) });
                if (bookingData.preferred_time) details.push({ label: 'Preferred Time', value: '<span class="badge badge-info"><i class="fas fa-clock"></i> ' + escapeHtml(formatTimeDisplay(bookingData.preferred_time)) + '</span>' });
                details.push({ label: 'Number of Pax', value: escapeHtml(bookingData.number_of_guests || '1') });
                if (bookingData.special_requests) details.push({ label: 'Special Requests', value: '<em style="color:#475569;">' + escapeHtml(bookingData.special_requests) + '</em>' });
            } else if (type === 'food') {
                if (bookingData.food_name) {
                    var foodInfo = '<div style="background:#f0f7fb; border-left:3px solid #f59e0b; border-radius:8px; padding:10px 12px; text-align:left;">';
                    foodInfo += '<div style="font-weight:700; color:#0B2447; font-size:13px; margin-bottom:6px;"><i class="fas fa-utensils" style="color:#f59e0b;"></i> ' + escapeHtml(bookingData.food_name) + '</div>';
                    if (bookingData.size_variant) foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-arrows-alt-h" style="color:#f59e0b; width:14px;"></i> ' + escapeHtml(bookingData.size_variant) + '</div>';
                    if (bookingData.preferred_date) foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-calendar-alt" style="color:#f59e0b; width:14px;"></i> ' + escapeHtml(parseDateForDisplay(bookingData.preferred_date)) + (bookingData.preferred_time ? ' at ' + escapeHtml(formatTimeDisplay(bookingData.preferred_time)) : '') + '</div>';
                    if (bookingData.number_of_persons) foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-users" style="color:#f59e0b; width:14px;"></i> ' + escapeHtml(bookingData.number_of_persons) + ' person(s)</div>';
                    if (bookingData.quantity) foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-shopping-bag" style="color:#f59e0b; width:14px;"></i> Qty: ' + escapeHtml(bookingData.quantity) + '</div>';
                    if (bookingData.total_amount) foodInfo += '<div style="font-size:12px; color:#10b981; font-weight:700; margin-top:4px;">₱' + parseFloat(bookingData.total_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</div>';
                    foodInfo += '</div>';
                    details.push({ label: '🍽️ Food', value: foodInfo });
                }
                if (bookingData.size_variant) details.push({ label: 'Package Size', value: escapeHtml(bookingData.size_variant) });
                if (bookingData.preferred_date) details.push({ label: 'Preferred Date', value: escapeHtml(parseDateForDisplay(bookingData.preferred_date)) });
                if (bookingData.preferred_time) details.push({ label: 'Preferred Time', value: '<span class="badge badge-info"><i class="fas fa-clock"></i> ' + escapeHtml(formatTimeDisplay(bookingData.preferred_time)) + '</span>' });
                if (bookingData.number_of_persons) details.push({ label: 'Number of Persons', value: '<span class="badge badge-info"><i class="fas fa-users"></i> ' + escapeHtml(bookingData.number_of_persons) + '</span>' });
                details.push({ label: 'Quantity (Packages)', value: escapeHtml(bookingData.quantity || '1') });
                var method = bookingData.fulfillment_method || 'pickup';
                var methodBadge = method === 'delivery' ? '<span class="badge badge-delivery"><i class="fas fa-truck"></i> Delivery</span>' : '<span class="badge badge-pickup"><i class="fas fa-store"></i> Pickup</span>';
                details.push({ label: 'Fulfillment', value: methodBadge });
                if (bookingData.contact_number) details.push({ label: 'Contact Number', value: '<a href="tel:' + escapeHtml(bookingData.contact_number) + '" style="color:#0B2447; font-weight:600; text-decoration:none;"><i class="fas fa-phone"></i> ' + escapeHtml(bookingData.contact_number) + '</a>' });
                if (method === 'delivery' && bookingData.delivery_address) details.push({ label: 'Delivery Address', value: '<span style="font-weight:500; color:#334155;">' + escapeHtml(bookingData.delivery_address) + '</span>' });
                if (bookingData.special_requests) details.push({ label: 'Special Requests', value: '<em style="color:#475569;">' + escapeHtml(bookingData.special_requests) + '</em>' });
            }

            (function () {
                var p = payInfo(bookingData);
                details.push({ label: 'Total Booking Price', value: '<strong style="color:#0B2447; font-size:16px;">' + pesoJs(p.total) + '</strong>' });
                if (p.state !== 'component' && p.state !== 'legacy_rebook') {
                    details.push({ label: 'Reservation Fee', value: pesoJs(p.fee) });
                    details.push({ label: 'Amount Paid', value: pesoJs(p.paid) });
                    if (p.state === 'rebook_required') details.push({ label: 'Rebooking Credit', value: '<strong style="color:#6d28d9;">' + pesoJs(p.paid) + '</strong> (non-refundable)' });
                    else if (p.state !== 'cancelled' && p.state !== 'rejected') details.push({ label: 'Balance Due', value: '<strong style="color:' + (p.balance > 0 ? '#b45309' : '#047857') + ';">' + pesoJs(p.balance) + '</strong>' });
                    if (bookingData.reservation_paid_at) details.push({ label: 'Reservation Fee Received', value: escapeHtml(bookingData.reservation_paid_at) });
                    if (bookingData.balance_paid_at) details.push({ label: 'Balance Received', value: escapeHtml(bookingData.balance_paid_at) });
                } else {
                    details.push({ label: 'Payment', value: p.state === 'component' ? 'Paid with the package' : 'Old rebooking record — not a separate sale' });
                }
            })();
            var paymentBadge2 = payInfo(bookingData).badge;
            details.push({ label: 'Payment Status', value: paymentBadge2 });

            var statusBadge2 = '';
            if (bookingData.booking_status === 'confirmed') statusBadge2 = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Confirmed</span>';
            else if (bookingData.booking_status === 'pending') statusBadge2 = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>';
            else if (bookingData.booking_status === 'completed') statusBadge2 = '<span class="badge badge-success"><i class="fas fa-check-double"></i> Completed</span>';
            else if (bookingData.booking_status === 'cancelled') statusBadge2 = '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelled</span>';
            else statusBadge2 = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>';
            details.push({ label: 'Booking Status', value: statusBadge2 });
            cancellationDetails(bookingData).forEach(function (d) { details.push(d); });

            if (type === 'house' && bookingData.rebook_count && parseInt(bookingData.rebook_count) > 0) {
                details.push({ label: 'Rebook Count', value: '<span class="badge badge-purple"><i class="fas fa-redo"></i> Rebook #' + escapeHtml(bookingData.rebook_count) + '/2</span>' });
                if (bookingData.rebook_confirmed_at) details.push({ label: 'Rebook Confirmed', value: escapeHtml(bookingData.rebook_confirmed_at) });
            }

            if (bookingData.created_at) {
                var bookedDate2 = new Date(bookingData.created_at);
                if (!isNaN(bookedDate2.getTime())) {
                    var dateStr2 = bookedDate2.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                    var timeStr2 = bookedDate2.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                    details.push({ label: 'Booked On', value: dateStr2 + ' at ' + timeStr2 });
                }
            }

            details.forEach(function(item) {
                html += '<div class="pkg-row"><span class="pkg-label"><i class="fas fa-chevron-right"></i> ' + item.label + '</span><span class="pkg-value">' + item.value + '</span></div>';
            });

            var nameArray = [];
            if (bookingData.guest_names) {
                var names = bookingData.guest_names;
                if (typeof names === 'string') {
                    if (names.startsWith('[') || names.startsWith('{')) {
                        try {
                            var parsed = JSON.parse(names);
                            if (Array.isArray(parsed)) nameArray = parsed.filter(function(n) { return n && n.trim && n.trim().length > 0; });
                            else if (typeof parsed === 'object') nameArray = Object.values(parsed).filter(function(v) { return typeof v === 'string' && v.trim().length > 0; });
                        } catch(e) {}
                    }
                    if (nameArray.length === 0 && names.length > 0) {
                        if (names.includes('\n')) nameArray = names.split('\n').map(function(n) { return n.trim(); }).filter(function(n) { return n.length > 0; });
                        else if (names.includes(',')) nameArray = names.split(',').map(function(n) { return n.trim(); }).filter(function(n) { return n.length > 0; });
                        else nameArray = [names.trim()];
                    }
                }
            }
            if (nameArray.length > 0) {
                html += '<div class="guest-list-section"><div class="guest-list-title"><i class="fas fa-users"></i> Guest List (' + nameArray.length + ')</div>';
                nameArray.forEach(function(name, index) {
                    html += '<div class="guest-item"><div class="guest-number">' + (index + 1) + '</div><div class="guest-name">' + escapeHtml(name) + '</div><div class="guest-detail">Guest ' + (index + 1) + ' of ' + nameArray.length + '</div></div>';
                });
                html += '</div>';
            }
        }

        var contentEl = document.getElementById('viewBookingContent');
        if (contentEl) contentEl.innerHTML = html;

        var modalEl = document.getElementById('viewBookingModal');
        if (modalEl) modalEl.classList.add('show');

        document.body.style.overflow = 'hidden';

    } catch (err) {
        console.error('viewBooking error:', err);
        alert('Failed to open booking details. Please refresh the page and try again.');
    }
}

function copyTextToClipboard(text, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            setTimeout(function() { btn.innerHTML = orig; }, 1500);
        }).catch(function() { fallbackCopySimple(text, btn); });
    } else {
        fallbackCopySimple(text, btn);
    }
}

function fallbackCopySimple(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(function() { btn.innerHTML = orig; }, 1500);
    } catch(e) { alert('Copy failed. Reference: ' + text); }
    document.body.removeChild(ta);
}

function openImageFullscreen(src) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.95);z-index:999999;display:flex;align-items:center;justify-content:center;padding:20px;cursor:zoom-out;';
    var img = document.createElement('img');
    img.src = src + '?t=' + Date.now();
    img.style.cssText = 'max-width:100%;max-height:100%;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.5);';
    overlay.appendChild(img);
    overlay.onclick = function() {
        document.body.removeChild(overlay);
        document.body.style.overflow = 'auto';
    };
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
}

function closeViewBookingModal() {
    var modal = document.getElementById('viewBookingModal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

/* ============================================================
   PRINT & EXPORT HISTORY
   ============================================================ */
function printHistory() {
    var table = document.getElementById('historyTable');
    if (!table) { alert('No history table found.'); return; }

    var printWindow = window.open('', '_blank', 'width=1200,height=800');
    var siteName = <?php echo json_encode($site_name); ?>;
    var now = new Date();
    var dateStr = now.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    var timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

    var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Booking History</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; padding: 30px; color: #1e293b; }';
    html += 'h1 { color: #0B2447; font-size: 24px; margin-bottom: 4px; }';
    html += '.subtitle { color: #64748b; font-size: 13px; margin-bottom: 20px; }';
    html += 'table { width: 100%; border-collapse: collapse; margin-top: 15px; }';
    html += 'th { background: #0B2447; color: white; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; }';
    html += 'td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 12px; }';
    html += 'tr:nth-child(even) { background: #f8fafc; }';
    html += '.badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }';
    html += '.completed, .paid { background: #d1fae5; color: #065f46; }';
    html += '.cancelled { background: #fee2e2; color: #991b1b; }';
    html += '.pending { background: #fef3c7; color: #92400e; }';
    html += '.footer { margin-top: 30px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 15px; }';
    html += '</style></head><body>';
    html += '<h1>📋 Booking History</h1>';
    html += '<div class="subtitle"><strong>' + siteName + '</strong> &nbsp;|&nbsp; Generated: ' + dateStr + ' at ' + timeStr + ' &nbsp;|&nbsp; Total: ' + <?php echo $total_history; ?> + ' bookings</div>';

    var clone = table.cloneNode(true);
    var rows = clone.querySelectorAll('tr');
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('th, td');
        if (cells.length > 0) cells[cells.length - 1].remove();
    });

    clone.querySelectorAll('.badge').forEach(function(b) {
        var text = b.textContent.trim().toLowerCase();
        var cls = '';
        if (text.indexOf('completed') !== -1) cls = 'completed';
        else if (text.indexOf('cancelled') !== -1) cls = 'cancelled';
        else if (text.indexOf('paid') !== -1) cls = 'paid';
        else if (text.indexOf('pending') !== -1) cls = 'pending';
        b.className = 'badge ' + cls;
        b.innerHTML = text.charAt(0).toUpperCase() + text.slice(1);
    });

    html += clone.outerHTML;
    html += '<div class="footer">© ' + now.getFullYear() + ' ' + siteName + ' — Booking History Report</div>';
    html += '</body></html>';

    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); }, 400);
}

function exportHistoryCSV() {
    var table = document.getElementById('historyTable');
    if (!table) { alert('No history table found.'); return; }

    var rows = table.querySelectorAll('tr');
    var csv = [];

    var headerCells = rows[0].querySelectorAll('th');
    var headerRow = [];
    for (var i = 0; i < headerCells.length - 1; i++) {
        headerRow.push('"' + headerCells[i].textContent.trim().replace(/"/g, '""') + '"');
    }
    csv.push(headerRow.join(','));

    for (var r = 1; r < rows.length; r++) {
        var cells = rows[r].querySelectorAll('td');
        if (cells.length === 0) continue;
        var dataRow = [];
        for (var c = 0; c < cells.length - 1; c++) {
            var text = cells[c].textContent.trim().replace(/\s+/g, ' ').replace(/"/g, '""');
            dataRow.push('"' + text + '"');
        }
        csv.push(dataRow.join(','));
    }

    var csvContent = '\uFEFF' + csv.join('\n');
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    var now = new Date();
    var filename = 'booking_history_' + now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + '.csv';
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

/* ============================================================
   LOGOUT MODAL
   ============================================================ */
function openLogoutModal(event) {
    if (event) event.preventDefault();
    var sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        var overlay = document.getElementById('sidebarOverlay');
        var toggleBtn = document.getElementById('menuToggle');
        if (overlay) overlay.classList.remove('active');
        if (toggleBtn) toggleBtn.classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
    }
    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

/* ============================================================
   ESC KEY + OUTSIDE CLICK
   ============================================================ */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('open')) toggleSidebar();
        closeProofModal();
        closeViewBookingModal();
        closeConfirmModal();
        closeRejectModal();
        closeRejectRebookModal();
        closeAdminCancelModal();
        var logoutModal = document.getElementById('logoutModal');
        if (logoutModal && logoutModal.classList.contains('show')) closeLogoutModal();
    }
});

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
};

/* ============================================================
   INIT
   ============================================================ */
document.addEventListener('DOMContentLoaded', function() {

    var urlParams = new URLSearchParams(window.location.search);
    var requestedTab = urlParams.get('tab');
    var statusParam = urlParams.get('status');
    if (requestedTab && ['house', 'tour', 'food', 'package', 'rebook', 'history'].indexOf(requestedTab) !== -1) {
        showBookingType(requestedTab);
    } else if (statusParam === 'rebook' || statusParam === 'pending_rebook') {
        showBookingType('rebook');
    } else if (statusParam === 'history') {
        showBookingType('history');
    } else if (window.location.hash) {
        var hashTab = window.location.hash.replace('#', '');
        if (['house', 'tour', 'food', 'package', 'rebook', 'history'].indexOf(hashTab) !== -1) {
            showBookingType(hashTab);
        }
    }

    var rejectModal = document.getElementById('rejectReasonModal');
    if (rejectModal) rejectModal.addEventListener('click', function(e) { if (e.target === this) closeRejectModal(); });
    var confirmModal = document.getElementById('confirmActionModal');
    if (confirmModal) confirmModal.addEventListener('click', function(e) { if (e.target === this) closeConfirmModal(); });
    var rejectRebookModal = document.getElementById('rejectRebookModal');
    if (rejectRebookModal) rejectRebookModal.addEventListener('click', function(e) { if (e.target === this) closeRejectRebookModal(); });
    var adminCancelModal = document.getElementById('adminCancelModal');
    if (adminCancelModal) adminCancelModal.addEventListener('click', function(e) { if (e.target === this) closeAdminCancelModal(); });
    var logoutModal = document.getElementById('logoutModal');
    if (logoutModal) logoutModal.addEventListener('click', function(e) { if (e.target === this) closeLogoutModal(); });

    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(function() { alert.remove(); }, 500);
        });
    }, 5000);

    <?php if($view == 'calendar'): ?>
    var calendarEl = document.getElementById('calendar');
    if (calendarEl) {
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridWeek' },
            events: <?php echo json_encode($calendar_events); ?>,
            eventContent: function(arg) {
                var props = arg.event.extendedProps || {};
                if (props.type === 'blocked') {
                    return { html: '<div class="fc-modern-event blocked"><strong>🚫 BLOCKED</strong><br><span>' + (props.item_name || 'Date blocked') + '</span></div>' };
                }
                var status = (props.payment || props.status || 'pending').toString().toLowerCase();
                var icon = props.type === 'house' ? '🏠' : props.type === 'tour' ? '🏖' : props.type === 'food' ? '🍽' : '📦';
                var statusText = status === 'paid' ? 'FULLY PAID' : (status === 'reservation_paid' ? 'FEE PAID' : 'PENDING');
                return { html: '<div class="fc-modern-event"><strong>' + icon + ' ' + statusText + '</strong><br><span>' + (props.guest_name || arg.event.title) + '</span></div>' };
            },
            eventClick: function(info) {
                var props = info.event.extendedProps;

                if (props.type === 'blocked') {
                    var typeLabel = props.block_type ? props.block_type.replace('_', ' ').toUpperCase() : 'BLOCKED';
                    alert('🚫 BLOCKED DATE\n\nItem: ' + props.item_name + '\nDate: ' + props.block_date + '\nType: ' + typeLabel + '\nReason: ' + (props.reason || 'No reason provided') + '\n\nTo unblock, go to Blocked Dates page.');
                    return;
                }

                var booking = {
                    reference_number: props.reference, type: props.type, id: props.id,
                    guest_name: props.guest_name, email: props.email, item_name: props.item_name,
                    check_in_date: props.check_in, check_in_time: props.check_in_time || '',
                    check_out_date: props.check_out, check_out_time: props.check_out_time || '',
                    number_of_guests: props.guests, total_amount: props.total,
                    payment_status: props.payment, booking_status: props.status,
                    payment_proof: props.proof, gcash_reference: props.gcash_reference || '',
                    guest_names: props.guest_names || '',
                    fulfillment_method: props.fulfillment_method || 'pickup',
                    delivery_address: props.delivery_address || '',
                    contact_number: props.contact_number || ''
                };
                viewBooking(props.type, booking);
            },
            height: 'auto', contentHeight: 'auto', aspectRatio: 1.6,
            nowIndicator: true, dayMaxEvents: true, weekends: true
        });
        calendar.render();
    }
    <?php endif; ?>
});

document.addEventListener("DOMContentLoaded", function(){

    const search = document.getElementById("bookingSearch");

    const rows = document.querySelectorAll(".booking-row");


    if(!search) return;


    search.addEventListener("input", function(){

        const keyword = this.value.toLowerCase().trim();


        rows.forEach(row => {

            const text = row.dataset.search;


            if(text.includes(keyword)){

                row.style.display = "";

            }else{

                row.style.display = "none";

            }

        });

    });

});
document.addEventListener("DOMContentLoaded", function(){

const search = document.getElementById("bookingSearch");
const rows = document.querySelectorAll(".booking-row");

search.addEventListener("input", function(){

    let keyword = this.value.toLowerCase();

    rows.forEach(row=>{

        if(row.dataset.search.includes(keyword)){
            row.style.display="";
        }else{
            row.style.display="none";
        }

    });

});

});

document.addEventListener("DOMContentLoaded", function(){

const search = document.getElementById("bookingSearch");

search.addEventListener("input", function(){

let keyword = this.value.toLowerCase();

document.querySelectorAll(".booking-row").forEach(row=>{

row.style.display =
row.dataset.search.includes(keyword)
? ""
: "none";

});

});

});

</script>

<?php require __DIR__ . '/includes/walkin-modal.php'; ?>
</body>
</html>
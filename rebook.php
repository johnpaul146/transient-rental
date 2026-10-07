<?php
session_start();
require_once 'database.php';
require_once 'config/mail_config.php';
require_once 'includes/EmailNotifications.php';
require_once 'includes/PaymentService.php';

// ✅ NEW: Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ✅ NEW: Helper — real client IP
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================================
// AUTO-ADD rebook columns safely if missing
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebook_count'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebook_count INT NOT NULL DEFAULT 0");
    } else {
        $pdo->exec("UPDATE house_bookings SET rebook_count = 0 WHERE rebook_count IS NULL");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebooked_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebooked_at DATETIME DEFAULT NULL");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'rebook_confirmed_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings ADD COLUMN rebook_confirmed_at DATETIME DEFAULT NULL");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM house_bookings LIKE 'previous_check_in_date'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE house_bookings 
            ADD COLUMN previous_check_in_date DATE DEFAULT NULL,
            ADD COLUMN previous_check_out_date DATE DEFAULT NULL,
            ADD COLUMN previous_number_of_guests INT DEFAULT NULL,
            ADD COLUMN previous_total_amount DECIMAL(10,2) DEFAULT NULL");
    }
} catch(PDOException $e) {}

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get guest info
$stmt = $pdo->prepare("SELECT * FROM guests WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$guest = $stmt->fetch();

// Get booking details
$booking_id = isset($_GET['booking_id']) ? $_GET['booking_id'] : 0;
if($booking_id == 0) {
    header("Location: profile.php");
    exit();
}

$stmt = $pdo->prepare("SELECT b.*, h.house_name, h.image, h.capacity 
                       FROM house_bookings b 
                       JOIN houses h ON b.house_id = h.id 
                       WHERE b.id = ? AND b.guest_id = ? AND (b.package_id IS NULL OR b.package_id = 0)");
$stmt->execute([(int)$booking_id, $guest['id'] ?? 0]);
$booking = $stmt->fetch();

if(!$booking) {
    header("Location: profile.php");
    exit();
}

// Check if rebook is allowed
function canRebook($pdo, $booking_id, $user_id, &$message = '') {
    $stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $guest = $stmt->fetch();
    if(!$guest) {
        $message = "Guest profile not found.";
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM house_bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    if(!$booking || (int)$booking['guest_id'] !== (int)$guest['id']) {
        $message = "Booking not found.";
        return false;
    }
    if(!empty($booking['original_booking_id'])) {
        $message = "This is an old rebooking record.";
        return false;
    }
    
    // A paid reservation may be rebooked (no refunds). A cancelled paid reservation
    // keeps its payment as a rebooking credit and may be rebooked too.
    if(!in_array($booking['booking_status'], ['confirmed', 'cancelled'], true)) {
        $message = "Only confirmed bookings or paid cancelled reservations can be rebooked (completed stays cannot).";
        return false;
    }
    
    if(PaymentService::amounts($booking)['paid'] <= 0) {
        $message = "Only bookings with a paid reservation fee can be rebooked.";
        return false;
    }
    
    $rebook_count = $booking['rebook_count'] ?? 0;
    if($rebook_count >= 2) {
        $message = "You have already rebooked " . $rebook_count . " times. Maximum of 2 rebooks allowed.";
        return false;
    }
    
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $booking_date = new DateTime($booking['created_at']);
    $booking_date->setTime(0, 0, 0);
    $days_diff = $today->diff($booking_date)->days;
    
    if ($days_diff > 7) {
        $message = "Rebook option expired. You can only rebook within 7 days from booking date. The amount already paid is carried forward when you rebook.";
        return false;
    }
    
    return true;
}

$message = '';
$can_rebook = canRebook($pdo, $booking_id, $_SESSION['user_id'], $message);

if(!$can_rebook) {
    // ✅ NEW: Log blocked rebook attempt
    if (class_exists('SystemLogger')) {
        SystemLogger::log(
            $pdo,
            'rebook_blocked',
            'booking',
            "Guest '" . ($_SESSION['username'] ?? 'Unknown') . "' attempted to rebook booking #{$booking_id} but was blocked — Reason: {$message}",
            (int)$booking_id,
            'house_booking',
            null,
            [
                'reason'  => $message,
                'ip'      => getClientIp(),
                'page'    => 'rebook.php'
            ],
            'failed'
        );
    }

    header("Location: profile.php?error=" . urlencode($message));
    exit();
}

// ============================================================
// GET BOOKED DATES FOR CALENDAR
// ============================================================
function getBookedDates($pdo, $house_id, $exclude_booking_id = null) {
    // Same rule as new bookings (AvailabilityService): confirmed/completed stays
    // occupy nights [check-in, check-out); blocked dates are one-night ranges.
    $own_ref = null;
    if ($exclude_booking_id) {
        $r = $pdo->prepare("SELECT reference_number FROM house_bookings WHERE id = ?");
        $r->execute([$exclude_booking_id]);
        $own_ref = $r->fetchColumn() ?: null;
    }
    return AvailabilityService::houseOccupiedRanges($pdo, $house_id, $exclude_booking_id, $own_ref);
}

$booked_dates = getBookedDates($pdo, $booking['house_id'], $booking_id);

// ============================================================
// CALCULATE ORIGINAL NIGHTS (LOCKED)
// ============================================================
$orig_check_in = new DateTime($booking['check_in_date']);
$orig_check_out = new DateTime($booking['check_out_date']);
$ORIGINAL_NIGHTS = $orig_check_out->diff($orig_check_in)->days;
$price_per_night = $ORIGINAL_NIGHTS > 0 ? $booking['total_amount'] / $ORIGINAL_NIGHTS : $booking['total_amount'];

// ============================================================
// Get remaining rebooks (based on rebook_count, same record)
// ============================================================
// Limit is per booking: maximum 2 rebooks for THIS booking (same rule as the backend).
$remaining_rebooks = max(0, 2 - (int)($booking['rebook_count'] ?? 0));

// ============================================================
// HANDLE REBOOK SUBMISSION — UPDATE ONLY
// ✅ NIGHTS MUST BE EXACTLY SAME AS ORIGINAL
// ✅ NO NEW PAYMENT (amount already paid is carried forward)
// ✅ STATUS → pending (wait for admin confirm)
// ============================================================
if(isset($_POST['confirm_rebook'])) {
    try {
        $check_in = (string)($_POST['check_in'] ?? '');
        $check_out = (string)($_POST['check_out'] ?? '');
        $guests = (int)($_POST['guests'] ?? 0);
        if ($guests < 1) throw new Exception("Please enter the number of guests.");
        if (!empty($booking['capacity']) && $guests > (int)$booking['capacity']) {
            throw new Exception("This house accommodates up to " . (int)$booking['capacity'] . " guests.");
        }
        
        if(empty($check_in) || empty($check_out)) {
            throw new Exception("Please select both check-in and check-out dates.");
        }
        
        $check_in_date = new DateTime($check_in);
        $check_out_date = new DateTime($check_out);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        if($check_in_date < $today) {
            throw new Exception("Selected date is no longer available. Please choose a future date.");
        }
        
        if($check_out_date <= $check_in_date) {
            throw new Exception("Check-out date must be after check-in date.");
        }
        
        // ✅ ENFORCE: NEW NIGHTS MUST EQUAL ORIGINAL NIGHTS
        $new_nights = $check_out_date->diff($check_in_date)->days;
        
        if($new_nights != $ORIGINAL_NIGHTS) {
            throw new Exception(
                "Invalid stay length. Your original booking was " . $ORIGINAL_NIGHTS . " night(s). " .
                "You must rebook exactly " . $ORIGINAL_NIGHTS . " night(s). " .
                "You selected " . $new_nights . " night(s)."
            );
        }
        
        // Check for conflicts with other bookings
        $booked_dates_check = getBookedDates($pdo, $booking['house_id'], $booking_id);
        foreach($booked_dates_check as $booked) {
            $booked_in = new DateTime($booked['check_in_date']);
            $booked_out = new DateTime($booked['check_out_date']);
            if($check_in_date < $booked_out && $check_out_date > $booked_in) {
                throw new Exception("Selected dates are not available. Please choose different dates.");
            }
        }
        
        // House price is per NIGHT. Guests never multiply the price (capacity is checked above).
        $total = round($price_per_night * $ORIGINAL_NIGHTS, 2);
        
        // UPDATE EXISTING BOOKING (HINDI INSERT)
        // payment status/amount unchanged (amount already paid is carried forward)
        // booking_status = 'pending' → wait for admin confirm
        // previous_* columns back up the CURRENT (pre-rebook) values
        // so Cancel Rebook can actually restore them later.
        $stmt = $pdo->prepare("UPDATE house_bookings SET 
            previous_check_in_date = ?,
            previous_check_out_date = ?,
            previous_number_of_guests = ?,
            previous_total_amount = ?,
            previous_booking_status = ?,
            check_in_date = ?,
            check_out_date = ?,
            number_of_guests = ?,
            total_amount = ?,
            booking_status = 'pending',
            rebook_count = COALESCE(rebook_count, 0) + 1,
            rebooked_at = NOW(),
            rebook_confirmed_at = NULL
            WHERE id = ? AND guest_id = ?");
        $stmt->execute([
            $booking['check_in_date'],
            $booking['check_out_date'],
            $booking['number_of_guests'],
            $booking['total_amount'],
            $booking['booking_status'],
            $check_in,
            $check_out,
            $guests,
            $total,
            $booking_id,
            $booking['guest_id']
        ]);

        // ✅ NEW: Log successful rebook
        if (class_exists('SystemLogger')) {
            $new_rebook_count = ($booking['rebook_count'] ?? 0) + 1;

            SystemLogger::log(
                $pdo,
                'rebook',
                'booking',
                "Guest '" . ($_SESSION['username'] ?? 'Unknown') . "' rebooked house booking '{$booking['reference_number']}' ({$booking['house_name']}) — new dates: {$check_in} to {$check_out} ({$new_nights} nights, {$guests} pax, ₱" . number_format($total, 2) . ") — Rebook #{$new_rebook_count}/2",
                (int)$booking_id,
                'house_booking',
                [
                    'old_check_in'  => $booking['check_in_date'],
                    'old_check_out' => $booking['check_out_date'],
                    'old_guests'    => $booking['number_of_guests'],
                    'old_total'     => $booking['total_amount']
                ],
                [
                    'new_check_in'  => $check_in,
                    'new_check_out' => $check_out,
                    'new_guests'    => $guests,
                    'new_total'     => $total,
                    'rebook_count'  => $new_rebook_count,
                    'ip'            => getClientIp()
                ]
            );
        }

        // Notify admin/staff that a guest has rebooked and needs confirmation
        try {
            EmailNotifications::sendRebookAlert($booking_id, $pdo);
        } catch (Throwable $mailEx) {
            error_log("Rebook alert email failed (booking $booking_id): " . $mailEx->getMessage());
        }
        
        header("Location: profile.php?rebooked=1");
        exit();
        
    } catch(Exception $e) {
        $error = $e->getMessage();

        // ✅ NEW: Log failed rebook attempt
        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'rebook_failed',
                'booking',
                "Guest '" . ($_SESSION['username'] ?? 'Unknown') . "' FAILED to rebook booking '{$booking['reference_number']}' — Error: " . $e->getMessage(),
                (int)$booking_id,
                'house_booking',
                null,
                [
                    'error'    => $e->getMessage(),
                    'attempted_check_in'  => $_POST['check_in'] ?? null,
                    'attempted_check_out' => $_POST['check_out'] ?? null,
                    'attempted_guests'    => $_POST['guests'] ?? null,
                    'ip'       => getClientIp(),
                    'page'     => 'rebook.php'
                ],
                'failed'
            );
        }
    }
}

// Get dynamic content for logo
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch(PDOException $e) {}

$nav_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $nav_logo = $content['site_settings']['logo_path'];
}
$nav_logo_exists = !empty($nav_logo) && file_exists($nav_logo) && !is_dir($nav_logo);
$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Rebook - <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ============================================================
           HEADER
           ============================================================ */
        .header {
            background: #0B2447;
            padding: 12px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 2px solid rgba(77, 166, 217, 0.2);
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .header-content {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .logo-wrapper {
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            flex-shrink: 0;
            min-width: 0;
        }

        .logo-wrapper .logo-image {
            height: 50px;
            width: 50px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #4DA6D9;
            padding: 2px;
            background: white;
            flex-shrink: 0;
        }

        .logo-wrapper .logo-image-placeholder {
            height: 50px;
            width: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            border: 2px solid #4DA6D9;
            flex-shrink: 0;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
            min-width: 0;
        }

        .brand-text .brand-name {
            font-size: 20px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.5px;
        }

        .brand-text .brand-tagline {
            font-size: 11px;
            color: #7bb8f0;
            font-weight: 500;
        }

        .btn-back {
            padding: 8px 16px;
            border-radius: 8px;
            background: #64748b;
            color: white;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-back:hover {
            background: #475569;
            color: white;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 12px;
                padding: 0 15px;
            }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder {
                height: 42px;
                width: 42px;
            }
            .brand-text .brand-name { font-size: 16px; }
            .brand-text .brand-tagline { font-size: 10px; }
            .btn-back { width: 100%; justify-content: center; }
        }

        @media (max-width: 480px) {
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder {
                height: 38px;
                width: 38px;
            }
            .brand-text .brand-name { font-size: 15px; }
            .btn-back { font-size: 13px; padding: 7px 14px; }
        }

        /* ============================================================
           MAIN CONTAINER
           ============================================================ */
        .main-container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }

        @media (max-width: 768px) {
            .main-container { margin: 20px auto; padding: 0 15px; }
        }
        @media (max-width: 480px) {
            .main-container { margin: 15px auto; padding: 0 12px; }
        }

        /* ============================================================
           ALERTS
           ============================================================ */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 14px;
            word-break: break-word;
        }

        .alert-success {
            background: #e6f7e6;
            color: #10b981;
            border-left: 4px solid #10b981;
        }

        .alert-danger {
            background: #fee2e2;
            color: #ef4444;
            border-left: 4px solid #ef4444;
        }

        .alert i { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

        @media (max-width: 480px) {
            .alert { padding: 12px 15px; font-size: 13px; }
        }

        /* ============================================================
           REBOOK CARD
           ============================================================ */
        .rebook-card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #e8f0fe;
        }

        .rebook-card .card-header {
            text-align: center;
            border-bottom: 2px solid #e8f0fe;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .rebook-card .card-header .icon {
            font-size: 60px;
            color: #f59e0b;
            margin-bottom: 10px;
            display: block;
        }

        .rebook-card .card-header h2 {
            font-size: 28px;
            font-weight: 700;
            color: #0B2447;
            margin-bottom: 6px;
        }

        .rebook-card .card-header p {
            color: #64748b;
            font-size: 14px;
            margin: 0;
        }

        @media (max-width: 768px) {
            .rebook-card { padding: 25px 20px; border-radius: 20px; }
            .rebook-card .card-header .icon { font-size: 48px; }
            .rebook-card .card-header h2 { font-size: 22px; }
            .rebook-card .card-header p { font-size: 13px; }
        }

        @media (max-width: 480px) {
            .rebook-card { padding: 20px 15px; border-radius: 16px; }
            .rebook-card .card-header .icon { font-size: 42px; }
            .rebook-card .card-header h2 { font-size: 19px; }
            .rebook-card .card-header p { font-size: 12px; }
        }

        /* ============================================================
           POLICY LIST
           ============================================================ */
        .policy-list {
            background: #f8fafc;
            padding: 20px 25px;
            border-radius: 12px;
            margin: 20px 0;
            border: 1px solid #e2e8f0;
            list-style: none;
        }

        .policy-list li {
            list-style: none;
            color: #475569;
            font-size: 14px;
            padding: 10px 0;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border-bottom: 1px solid #e8f0fe;
            line-height: 1.5;
            word-break: break-word;
        }

        .policy-list li:last-child { border-bottom: none; padding-bottom: 0; }
        .policy-list li:first-child { padding-top: 0; }

        .policy-list li i { flex-shrink: 0; margin-top: 3px; font-size: 14px; }
        .policy-list li i.check { color: #10b981; }
        .policy-list li i.times { color: #ef4444; }
        .policy-list li i.info { color: #3b82f6; }

        @media (max-width: 480px) {
            .policy-list { padding: 15px 18px; }
            .policy-list li { font-size: 12.5px; padding: 8px 0; gap: 8px; }
            .policy-list li i { font-size: 12px; margin-top: 2px; }
        }

        /* ============================================================
           BOOKING INFO
           ============================================================ */
        .booking-info {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            border: 1px solid #e2e8f0;
        }

        .booking-info .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e8f0fe;
            align-items: flex-start;
            gap: 15px;
        }

        .booking-info .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .booking-info .info-row:first-child { padding-top: 0; }

        .booking-info .info-row .label {
            color: #64748b;
            font-size: 14px;
            flex-shrink: 0;
        }

        .booking-info .info-row .value {
            font-weight: 600;
            color: #0B2447;
            font-size: 14px;
            text-align: right;
            word-break: break-word;
        }

        @media (max-width: 480px) {
            .booking-info { padding: 15px; }
            .booking-info .info-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 3px;
                padding: 8px 0;
            }
            .booking-info .info-row .label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; font-weight: 700; }
            .booking-info .info-row .value { text-align: left; font-size: 13px; }
        }

        /* ============================================================
           FORM
           ============================================================ */
        .form-group { margin-bottom: 15px; }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #0B2447;
            font-size: 13px;
        }

        .form-group label i { color: #4DA6D9; margin-right: 5px; }

        .form-control, .form-select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e8f0fe;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
            font-family: inherit;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #4DA6D9;
            box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }

        .form-control[readonly] {
            background: #f1f5f9;
            cursor: not-allowed;
            color: #475569;
            font-weight: 600;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }

        /* ============================================================
           CALENDAR
           ============================================================ */
        .calendar-container {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #e2e8f0;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            gap: 10px;
        }

        .calendar-header h5 {
            font-weight: 700;
            color: #0B2447;
            margin: 0;
            font-size: 16px;
        }

        .calendar-nav {
            display: flex;
            gap: 8px;
        }

        .calendar-nav button {
            background: #e2e8f0;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
        }

        .calendar-nav button:hover {
            background: #4DA6D9;
            color: white;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }

        .calendar-grid .day-name {
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            padding: 6px 0;
            text-transform: uppercase;
        }

        .calendar-grid .day {
            text-align: center;
            padding: 10px 0;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            color: #334155;
            user-select: none;
            background: white;
            border: 1px solid transparent;
        }

        .calendar-grid .day:hover:not(.disabled):not(.booked):not(.past):not(.selected):not(.in-range) {
            background: #e0f0fa;
            border-color: #4DA6D9;
            transform: scale(1.05);
        }

        .calendar-grid .day.selected,
        .calendar-grid .day.in-range {
            background: #4DA6D9 !important;
            color: white !important;
            font-weight: 700;
        }

        .calendar-grid .day.range-start {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        .calendar-grid .day.range-end {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .calendar-grid .day.in-range {
            border-radius: 0;
        }

        .calendar-grid .day.booked {
            background: #fee2e2 !important;
            color: #dc2626 !important;
            cursor: not-allowed !important;
            text-decoration: line-through;
            border-color: #fecaca;
        }

        .calendar-grid .day.past {
            color: #cbd5e1 !important;
            cursor: not-allowed !important;
            background: #f8fafc;
        }

        .calendar-grid .day.disabled {
            cursor: not-allowed;
            opacity: 0.3;
            background: transparent;
        }

        .calendar-legend {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 15px;
            font-size: 11px;
            color: #64748b;
            flex-wrap: wrap;
        }

        .calendar-legend .dot {
            width: 12px;
            height: 12px;
            border-radius: 4px;
            display: inline-block;
            margin-right: 4px;
            vertical-align: middle;
        }

        .calendar-legend .dot.available { background: #ffffff; border: 1px solid #cbd5e1; }
        .calendar-legend .dot.booked { background: #fee2e2; border: 1px solid #dc2626; }
        .calendar-legend .dot.selected { background: #4DA6D9; border: 1px solid #4DA6D9; }

        .calendar-info {
            text-align: center;
            font-size: 13px;
            color: #64748b;
            padding: 12px;
            background: white;
            border-radius: 8px;
            margin-top: 15px;
            border: 1px solid #e8f0fe;
            line-height: 1.5;
        }

        .calendar-info strong { color: #0B2447; }

        @media (max-width: 768px) {
            .calendar-container { padding: 15px; }
            .calendar-header h5 { font-size: 14px; }
            .calendar-nav button { width: 32px; height: 32px; }
            .calendar-grid .day { padding: 8px 0; font-size: 12px; }
            .calendar-grid .day-name { font-size: 9px; padding: 4px 0; }
            .calendar-info { font-size: 12px; padding: 10px; }
        }

        @media (max-width: 480px) {
            .calendar-container { padding: 12px 10px; }
            .calendar-header h5 { font-size: 13px; }
            .calendar-nav button { width: 30px; height: 30px; font-size: 12px; }
            .calendar-grid { gap: 3px; }
            .calendar-grid .day { padding: 7px 0; font-size: 11px; border-radius: 6px; }
            .calendar-grid .day-name { font-size: 8px; padding: 3px 0; }
            .calendar-legend { font-size: 10px; gap: 10px; margin-top: 12px; }
            .calendar-legend .dot { width: 10px; height: 10px; }
            .calendar-info { font-size: 11px; padding: 8px; }
        }

        /* ============================================================
           SUMMARY BOX
           ============================================================ */
        .summary-box {
            background: linear-gradient(135deg, #0B2447, #4DA6D9);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            box-shadow: 0 10px 30px rgba(11, 36, 71, 0.2);
        }

        .summary-box .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            gap: 15px;
        }

        .summary-box .summary-row:not(:last-child) {
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }

        .summary-box .summary-row .label {
            opacity: 0.9;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-box .summary-row .label i { font-size: 14px; }

        .summary-box .summary-row .value {
            font-weight: 700;
            font-size: 20px;
            text-align: right;
            word-break: break-word;
        }

        @media (max-width: 480px) {
            .summary-box { padding: 16px; }
            .summary-box .summary-row .label { font-size: 13px; }
            .summary-box .summary-row .value { font-size: 17px; }
        }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn-primary {
            width: 100%;
            padding: 15px;
            background: #F4B400;
            color: #0B2447;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }

        .btn-primary:hover:not(:disabled) {
            background: #e6a800;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.45);
        }

        .btn-primary:disabled {
            background: #cbd5e1;
            color: #64748b;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .btn-secondary {
            width: 100%;
            padding: 15px;
            background: #e2e8f0;
            color: #475569;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
            color: #334155;
            transform: translateY(-2px);
        }

        @media (max-width: 480px) {
            .btn-primary, .btn-secondary {
                font-size: 14px;
                padding: 13px;
            }
        }
    </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="header-content">
        <a href="profile.php" class="logo-wrapper">
            <?php if($nav_logo_exists): ?>
                <img src="<?php echo htmlspecialchars($nav_logo); ?>?<?php echo time(); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" class="logo-image">
            <?php else: ?>
                <div class="logo-image-placeholder"><i class="fas fa-home"></i></div>
            <?php endif; ?>
            <div class="brand-text">
                <span class="brand-name"><?php echo htmlspecialchars($site_name); ?></span>
                <span class="brand-tagline">Your Home Away From Home</span>
            </div>
        </a>
        <a href="profile.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<div class="main-container">
    
    <?php if(isset($error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>
    
    <div class="rebook-card">
        <div class="card-header">
            <div class="icon"><i class="fas fa-redo-alt"></i></div>
            <h2>Rebook Your Stay</h2>
            <p>Please review the rebooking policy and select new dates</p>
        </div>
        
        <div class="policy-list">
            <li><i class="fas fa-check-circle check"></i> <span><strong>Maximum of 2 rebooks</strong> per booking</span></li>
            <li><i class="fas fa-clock info"></i> <span><strong>Within 7 days</strong> from your booking date</span></li>
            <li><i class="fas fa-check-circle check"></i> <span>Bookings that are <strong>confirmed or cancelled with money received</strong> can be rebooked — completed bookings cannot</span></li>
            <li><i class="fas fa-info-circle info"></i> <span>The <strong>amount already paid is carried forward</strong> — no second ₱1,000 reservation fee, no refund</span></li>
            <li><i class="fas fa-lock info" style="color:#f59e0b;"></i> <span>Stay length must be exactly <strong><?php echo $ORIGINAL_NIGHTS; ?> night(s)</strong></span></li>
            <li><i class="fas fa-clock info"></i> <span>Status will be <strong>pending</strong> until admin confirms</span></li>
            <li><i class="fas fa-info-circle info"></i> <span>You have <strong><?php echo $remaining_rebooks; ?></strong> rebook(s) remaining</span></li>
        </div>
        
        <div class="booking-info">
            <div class="info-row">
                <span class="label">Booking Reference</span>
                <span class="value"><?php echo htmlspecialchars($booking['reference_number']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">House</span>
                <span class="value"><?php echo htmlspecialchars($booking['house_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Original Check-in</span>
                <span class="value"><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Original Check-out</span>
                <span class="value"><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Stay Length</span>
                <span class="value"><?php echo $ORIGINAL_NIGHTS; ?> night(s)</span>
            </div>
            <div class="info-row">
                <span class="label">Price per night</span>
                <span class="value">₱<?php echo number_format($price_per_night, 2); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Rebooks used</span>
                <span class="value"><?php echo $booking['rebook_count'] ?? 0; ?> / 2</span>
            </div>
        </div>
        
        <form method="POST" id="rebookForm">
            <div class="calendar-container">
                <div class="calendar-header">
                    <h5 id="calendarMonthYear"></h5>
                    <div class="calendar-nav">
                        <button type="button" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                        <button type="button" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="calendar-grid" id="calendarGrid"></div>
                <div class="calendar-legend">
                    <span><span class="dot available"></span> Available</span>
                    <span><span class="dot booked"></span> Booked</span>
                    <span><span class="dot selected"></span> Selected</span>
                </div>
                <div class="calendar-info" id="calendarInfo">
                    Select your check-in date. Check-out will auto-set to exactly <strong><?php echo $ORIGINAL_NIGHTS; ?> night(s)</strong> later.
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Check-in Date *</label>
                    <input type="date" name="check_in" id="check_in" class="form-control" readonly required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-check"></i> Check-out Date * <span style="color:#f59e0b; font-size:11px;">(auto)</span></label>
                    <input type="date" name="check_out" id="check_out" class="form-control" readonly required>
                </div>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-users"></i> Number of Pax *</label>
                <select name="guests" id="guests" class="form-select" required>
                    <?php $max_pax = max(1, (int)($booking['capacity'] ?? 10)); for($i=1; $i<=$max_pax; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i == ($booking['number_of_guests'] ?? 1) ? 'selected' : ''; ?>><?php echo $i; ?> Pax</option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="summary-box">
                <div class="summary-row">
                    <span class="label"><i class="fas fa-moon"></i> Number of nights</span>
                    <span class="value" id="summaryNights"><?php echo $ORIGINAL_NIGHTS; ?></span>
                </div>
                <div class="summary-row">
                    <span class="label"><i class="fas fa-money-bill-wave"></i> Total amount</span>
                    <span class="value" id="summaryTotal">₱<?php echo number_format($price_per_night * $ORIGINAL_NIGHTS, 2); ?></span>
                </div>
                <?php $rb_paid = PaymentService::amounts($booking)['paid']; ?>
                <div class="summary-row">
                    <span class="label"><i class="fas fa-receipt"></i> Already paid (carried forward)</span>
                    <span class="value">₱<?php echo number_format($rb_paid, 2); ?></span>
                </div>
                <div class="summary-row">
                    <span class="label"><i class="fas fa-wallet"></i> Balance on arrival</span>
                    <span class="value" id="summaryBalance">₱<?php echo number_format(max(0, $price_per_night * $ORIGINAL_NIGHTS - $rb_paid), 2); ?></span>
                </div>
                <p style="font-size:12px;color:#64748b;margin:8px 0 0;line-height:1.5;">
                    No new reservation fee is charged — the amount you already paid stays with this booking.
                    The price is per night; the number of guests does not change it (maximum <?php echo (int)($booking['capacity'] ?? 0) ?: '—'; ?> guests).
                </p>
            </div>
            
            <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $price_per_night * $ORIGINAL_NIGHTS; ?>">
            
            <button type="submit" name="confirm_rebook" class="btn-primary" id="confirmBtn">
                <i class="fas fa-check-circle"></i> Confirm Rebook
            </button>
            
            <a href="profile.php" class="btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
        </form>
    </div>
</div>

<script>
// ============================================================
// REBOOK CALENDAR — LOCKED STAY LENGTH
// ============================================================
var currentMonth = new Date().getMonth();
var currentYear = new Date().getFullYear();
var selectedCheckIn = null;
var selectedCheckOut = null;
var pricePerNight = <?php echo $price_per_night; ?>;
var ORIGINAL_NIGHTS = <?php echo $ORIGINAL_NIGHTS; ?>;
var bookedDates = <?php echo json_encode($booked_dates); ?>;

function parseDateYMD(dateStr) {
    if (!dateStr) return null;
    var parts = dateStr.split('-');
    return new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
}

function formatDateYMD(dateObj) {
    var y = dateObj.getFullYear();
    var m = String(dateObj.getMonth() + 1).padStart(2, '0');
    var d = String(dateObj.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
}

function addDays(dateObj, days) {
    var d = new Date(dateObj);
    d.setDate(d.getDate() + days);
    return d;
}

function isDateBooked(dateObj) {
    for (var j = 0; j < bookedDates.length; j++) {
        var bookedIn = parseDateYMD(bookedDates[j].check_in_date);
        var bookedOut = parseDateYMD(bookedDates[j].check_out_date);
        // nights rule: a stay occupies [check-in, check-out); check-out day is free
        if (bookedIn && bookedOut && dateObj >= bookedIn && dateObj < bookedOut) {
            return true;
        }
    }
    return false;
}

function hasConflict(checkInDate, checkOutDate) {
    for (var j = 0; j < bookedDates.length; j++) {
        var bookedIn = parseDateYMD(bookedDates[j].check_in_date);
        var bookedOut = parseDateYMD(bookedDates[j].check_out_date);
        if (bookedIn && bookedOut && checkInDate < bookedOut && checkOutDate > bookedIn) {
            return true;
        }
    }
    return false;
}

function isValidCheckIn(dateObj, today) {
    if (dateObj < today) return false;
    
    var checkOutObj = addDays(dateObj, ORIGINAL_NIGHTS);
    var maxAllowed = addDays(today, 365);
    if (checkOutObj > maxAllowed) return false;
    
    if (hasConflict(dateObj, checkOutObj)) return false;
    
    return true;
}

function renderCalendar(month, year) {
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('calendarMonthYear').textContent = monthNames[month] + ' ' + year;
    
    var grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';
    
    var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    for (var i = 0; i < 7; i++) {
        var nameDiv = document.createElement('div');
        nameDiv.className = 'day-name';
        nameDiv.textContent = dayNames[i];
        grid.appendChild(nameDiv);
    }
    
    var today = new Date();
    today.setHours(0,0,0,0);
    
    for (var i = 0; i < firstDay; i++) {
        var emptyDiv = document.createElement('div');
        emptyDiv.className = 'day disabled';
        grid.appendChild(emptyDiv);
    }
    
    for (var day = 1; day <= daysInMonth; day++) {
        var dateObj = new Date(year, month, day);
        dateObj.setHours(0,0,0,0);
        var dateStr = formatDateYMD(dateObj);
        
        var dayDiv = document.createElement('div');
        dayDiv.className = 'day';
        dayDiv.textContent = day;
        dayDiv.dataset.date = dateStr;
        
        var inStr = selectedCheckIn ? formatDateYMD(selectedCheckIn) : null;
        var outStr = selectedCheckOut ? formatDateYMD(selectedCheckOut) : null;
        
        if (dateObj < today) {
            dayDiv.classList.add('past');
        } else if (isDateBooked(dateObj)) {
            dayDiv.classList.add('booked');
        }
        
        if (dateStr === inStr) {
            dayDiv.classList.add('selected', 'range-start');
        }
        else if (dateStr === outStr) {
            dayDiv.classList.add('selected', 'range-end');
        }
        else if (selectedCheckIn && selectedCheckOut && 
                 dateObj > selectedCheckIn && dateObj < selectedCheckOut) {
            dayDiv.classList.add('in-range');
        }
        
        dayDiv.onclick = function() { selectDate(this); };
        grid.appendChild(dayDiv);
    }
}

function changeMonth(delta) {
    currentMonth += delta;
    if (currentMonth < 0) { currentMonth = 11; currentYear--; }
    else if (currentMonth > 11) { currentMonth = 0; currentYear++; }
    renderCalendar(currentMonth, currentYear);
}

function selectDate(element) {
    if (element.classList.contains('booked') || element.classList.contains('past') || element.classList.contains('disabled')) {
        return;
    }
    
    var date = element.dataset.date;
    var selectedDate = parseDateYMD(date);
    var today = new Date(); today.setHours(0,0,0,0);
    
    if (!selectedDate) return;
    
    if (!isValidCheckIn(selectedDate, today)) {
        var expectedOut = addDays(selectedDate, ORIGINAL_NIGHTS);
        
        if (selectedDate < today) {
            alert('❌ Date is in the past.');
            return;
        }
        if (isDateBooked(selectedDate)) {
            alert('❌ This date is already booked.');
            return;
        }
        if (hasConflict(selectedDate, expectedOut)) {
            alert('❌ This check-in conflicts with an existing booking.\n\nYour stay would be ' + ORIGINAL_NIGHTS + ' night(s), ending on ' + formatDateYMD(expectedOut) + ', which overlaps with another booking.\n\nPlease pick a different check-in date.');
            return;
        }
        alert('❌ Invalid check-in date.');
        return;
    }
    
    if (selectedCheckIn && formatDateYMD(selectedCheckIn) === date) {
        clearSelection();
        document.getElementById('calendarInfo').innerHTML = 'Select your check-in date. Check-out will auto-set to exactly <strong>' + ORIGINAL_NIGHTS + ' night(s)</strong> later.';
        renderCalendar(currentMonth, currentYear);
        return;
    }
    
    selectedCheckIn = selectedDate;
    selectedCheckOut = addDays(selectedCheckIn, ORIGINAL_NIGHTS);
    
    document.getElementById('check_in').value = formatDateYMD(selectedCheckIn);
    document.getElementById('check_out').value = formatDateYMD(selectedCheckOut);
    
    updateSummary();
    
    document.getElementById('calendarInfo').innerHTML = 
        '✅ Check-in: <strong>' + formatDateYMD(selectedCheckIn) + '</strong> → ' +
        'Check-out: <strong>' + formatDateYMD(selectedCheckOut) + '</strong> ' +
        '(' + ORIGINAL_NIGHTS + ' night' + (ORIGINAL_NIGHTS > 1 ? 's' : '') + ')';
    
    renderCalendar(currentMonth, currentYear);
}

function clearSelection() {
    selectedCheckIn = null;
    selectedCheckOut = null;
    document.getElementById('check_in').value = '';
    document.getElementById('check_out').value = '';
    document.getElementById('summaryNights').textContent = ORIGINAL_NIGHTS;
    var total = pricePerNight * ORIGINAL_NIGHTS;
    document.getElementById('summaryTotal').textContent = '₱' + total.toFixed(2);
    document.getElementById('total_amount').value = total;
    updateSummary();
}

function updateSummary() {
    // House price is per night: the number of guests does not change the total
    var total = pricePerNight * ORIGINAL_NIGHTS;
    document.getElementById('summaryNights').textContent = ORIGINAL_NIGHTS;
    document.getElementById('summaryTotal').textContent = '₱' + total.toFixed(2);
    document.getElementById('total_amount').value = total;
    var balEl = document.getElementById('summaryBalance');
    if (balEl) balEl.textContent = '₱' + Math.max(0, total - <?php echo json_encode((float)PaymentService::amounts($booking)['paid']); ?>).toFixed(2);
}

document.getElementById('guests').addEventListener('change', updateSummary);

// FORM VALIDATION
document.getElementById('rebookForm').addEventListener('submit', function(e) {
    var checkIn = document.getElementById('check_in').value;
    var checkOut = document.getElementById('check_out').value;
    
    if (!checkIn || !checkOut) {
        e.preventDefault();
        alert('Please select a check-in date.');
        return false;
    }
    
    var checkInDate = parseDateYMD(checkIn);
    var checkOutDate = parseDateYMD(checkOut);
    var nights = Math.round((checkOutDate - checkInDate) / (1000 * 60 * 60 * 24));
    
    if (nights !== ORIGINAL_NIGHTS) {
        e.preventDefault();
        alert('❌ Invalid stay length!\n\nYou must rebook exactly ' + ORIGINAL_NIGHTS + ' night(s).\nYou selected ' + nights + ' night(s).');
        return false;
    }
    
    return true;
});

// Initial render
renderCalendar(currentMonth, currentYear);
</script>

</body>
</html>
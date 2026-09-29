<?php
session_start();

// ✅ FIX #1: Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $post_max = ini_get('post_max_size');
    $upload_error = "⚠️ Your upload was too large for the server to accept (limit is currently {$post_max}). Please choose a smaller image.";
}

require_once 'database.php';
require_once 'config/mail_config.php';
require_once 'includes/EmailNotifications.php';

if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

require_once 'includes/TermsGate.php';
$termsGate = new TermsGate($pdo);

if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// AUTO-ADD rebook columns
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

// Auto-add gcash/proof columns + cancelled_at
try {
    foreach (['house_bookings', 'tour_bookings', 'food_bookings'] as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'gcash_reference'")->fetchAll();
        if (empty($cols)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN gcash_reference VARCHAR(30) DEFAULT NULL");
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'proof_uploaded_at'")->fetchAll();
        if (empty($cols)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN proof_uploaded_at DATETIME DEFAULT NULL");
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'payment_proof'")->fetchAll();
        if (empty($cols)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN payment_proof VARCHAR(255) DEFAULT NULL");
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'cancelled_at'")->fetchAll();
        if (empty($cols)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN cancelled_at DATETIME DEFAULT NULL");
    }
    // Also add for package_bookings if table exists
    $cols = $pdo->query("SHOW COLUMNS FROM package_bookings LIKE 'gcash_reference'")->fetchAll();
    if (empty($cols)) $pdo->exec("ALTER TABLE package_bookings ADD COLUMN gcash_reference VARCHAR(30) DEFAULT NULL");
    $cols = $pdo->query("SHOW COLUMNS FROM package_bookings LIKE 'payment_proof'")->fetchAll();
    if (empty($cols)) $pdo->exec("ALTER TABLE package_bookings ADD COLUMN payment_proof VARCHAR(255) DEFAULT NULL");
    $cols = $pdo->query("SHOW COLUMNS FROM package_bookings LIKE 'proof_uploaded_at'")->fetchAll();
    if (empty($cols)) $pdo->exec("ALTER TABLE package_bookings ADD COLUMN proof_uploaded_at DATETIME DEFAULT NULL");
    $cols = $pdo->query("SHOW COLUMNS FROM package_bookings LIKE 'cancelled_at'")->fetchAll();
    if (empty($cols)) $pdo->exec("ALTER TABLE package_bookings ADD COLUMN cancelled_at DATETIME DEFAULT NULL");
} catch(PDOException $e) {}

// ✅ AUTO-ADD package_id column sa individual booking tables
try {
    foreach (['house_bookings', 'tour_bookings', 'food_bookings'] as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'package_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN package_id INT DEFAULT NULL");
        }
    }
} catch(PDOException $e) {}

// ✅ AUTO-ADD completed_at column sa lahat ng booking tables
try {
    foreach (['house_bookings', 'tour_bookings', 'food_bookings', 'package_bookings'] as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'completed_at'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN completed_at DATETIME DEFAULT NULL");
        }
    }
} catch(PDOException $e) {}

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function formatDateDisplay($dateStr) {
    if (!$dateStr) return 'N/A';
    try { return (new DateTime($dateStr))->format('M d, Y'); }
    catch(Exception $e) { return $dateStr; }
}
function formatDateTimeDisplay($dateStr) {
    if (!$dateStr) return 'N/A';
    try { return (new DateTime($dateStr))->format('M d, Y h:i A'); }
    catch(Exception $e) { return $dateStr; }
}
// ✅ NEW: Format TIME column (HH:MM:SS) for display
function formatTimeDisplay($timeStr) {
    if (!$timeStr) return '';
    try {
        return (new DateTime($timeStr))->format('h:i A');
    } catch(Exception $e) {
        return $timeStr;
    }
}
function decodeJson($value) {
    if (!$value) return [];
    $decoded = json_decode($value, true);
    return $decoded ?: [];
}
function getGoogleMapsUrl($address) {
    if (empty($address) || $address == '#') return '#';
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address);
}
function getBookedDates($pdo, $house_id, $exclude_booking_id = null) {
    $query = "SELECT check_in_date, check_out_date FROM house_bookings 
              WHERE house_id = ? AND booking_status != 'cancelled'";
    if($exclude_booking_id) $query .= " AND id != ?";
    $stmt = $pdo->prepare($query);
    if($exclude_booking_id) $stmt->execute([$house_id, $exclude_booking_id]);
    else $stmt->execute([$house_id]);
    return $stmt->fetchAll();
}
function getProfilePhoto($guest) {
    if ($guest && !empty($guest['profile_photo'])) {
        $user_id = $_SESSION['user_id'] ?? 0;
        $paths = [
            "uploads/profile/user_" . $user_id . "/" . $guest['profile_photo'],
            "uploads/user_" . $user_id . "/" . $guest['profile_photo'],
            "uploads/profile/" . $guest['profile_photo']
        ];
        foreach($paths as $path) { if (file_exists($path)) return $path; }
    }
    return null;
}
function getIDPhoto($guest) {
    if ($guest && !empty($guest['id_photo'])) {
        $user_id = $_SESSION['user_id'] ?? 0;
        $paths = [
            "uploads/profile/user_" . $user_id . "/" . $guest['id_photo'],
            "uploads/user_" . $user_id . "/" . $guest['id_photo']
        ];
        foreach($paths as $path) { if (file_exists($path)) return $path; }
    }
    return null;
}
function canGiveFeedback($booking) {
    return $booking['booking_status'] == 'completed' && empty($booking['feedback_text']);
}
function hasFeedback($booking) {
    return !empty($booking['feedback_text']);
}
function getBookingStatusLabel($status, $payment_status = null, $rebook_count = 0, $rebooked_at = null, $rebook_confirmed_at = null) {
    if (!empty($rebooked_at) && empty($rebook_confirmed_at)) {
        return '<span class="badge badge-warning"><i class="fas fa-redo"></i> Pending Rebook</span>';
    }
    if (!empty($rebooked_at) && !empty($rebook_confirmed_at)) {
        if ($payment_status == 'paid') return '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Confirmed</span>';
        return '<span class="badge badge-info"><i class="fas fa-hourglass-half"></i> Awaiting Payment</span>';
    }
    if ($status == 'confirmed' && $payment_status == 'paid') {
        return '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Confirmed</span>';
    }
    if ($status == 'completed') {
        return '<span class="badge badge-completed"><i class="fas fa-check-double"></i> Completed</span>';
    }
    if ($status == 'cancelled') {
        return '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelled</span>';
    }
    if ($status == 'confirmed' && $payment_status != 'paid') {
        return '<span class="badge badge-info"><i class="fas fa-hourglass-half"></i> Awaiting Payment</span>';
    }
    return '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>';
}
function isPendingRebook($booking) {
    if (empty($booking['rebooked_at'])) return false;
    if (!empty($booking['rebook_confirmed_at'])) return false;
    if (in_array($booking['booking_status'] ?? '', ['cancelled', 'completed'])) return false;
    return true;
}

function canRebook($pdo, $booking_id, $user_id, &$message = '') {
    $stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $guest = $stmt->fetch();
    if(!$guest) { $message = "Guest profile not found."; return false; }

    $stmt = $pdo->prepare("
        SELECT booking_status, payment_status, rebook_count, created_at,
               check_in_date, check_out_date,
               previous_check_in_date, previous_check_out_date
        FROM house_bookings WHERE id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    if(!$booking) { $message = "Booking not found."; return false; }

    if($booking['booking_status'] != 'completed' && $booking['booking_status'] != 'confirmed') {
        $message = "Only confirmed or completed bookings can be rebooked."; return false;
    }
    if($booking['payment_status'] != 'paid') {
        $message = "Only paid bookings can be rebooked."; return false;
    }

    $rebook_count = (int)($booking['rebook_count'] ?? 0);
    if($rebook_count >= 2) {
        $message = "You have already rebooked this booking " . $rebook_count . " time(s). Maximum of 2 rebooks allowed per booking.";
        return false;
    }

    try {
        $reference_date = new DateTime($booking['created_at']);
        $reference_date->setTime(0, 0, 0);

        $today = new DateTime();
        $today->setTime(0, 0, 0);

        $days_elapsed = (int)$today->diff($reference_date)->days;

        if ($days_elapsed > 7) {
            $message = "Rebook option expired. You can only rebook within 7 days from your booking date (" . $reference_date->format('M d, Y') . ").";
            return false;
        }
    } catch (Exception $e) {
        $message = "Invalid booking date. Please contact support.";
        return false;
    }

    return true;
}

function getRebookCount($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $guest = $stmt->fetch();
    if(!$guest) return 0;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(rebook_count), 0) FROM house_bookings WHERE guest_id = ? AND rebook_count > 0");
    $stmt->execute([$guest['id']]);
    return (int)$stmt->fetchColumn();
}
function getRemainingRebooks($pdo, $user_id) {
    return max(0, 2 - getRebookCount($pdo, $user_id));
}

function getRebookDaysRemaining($pdo, $booking_id) {
    $stmt = $pdo->prepare("
        SELECT created_at, booking_status, payment_status
        FROM house_bookings WHERE id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    if(!$booking) return 0;
    if($booking['booking_status'] != 'completed' && $booking['booking_status'] != 'confirmed') return 0;
    if($booking['payment_status'] != 'paid') return 0;

    try {
        $created = new DateTime($booking['created_at']);
        $created->setTime(0, 0, 0);
        $today = new DateTime();
        $today->setTime(0, 0, 0);

        $elapsed = (int)$today->diff($created)->days;
        $remaining = 7 - $elapsed;
        return max(0, $remaining);
    } catch (Exception $e) {
        return 0;
    }
}

function computeRebookInfo($pdo, $booking, $user_id) {
    $info = [
        'days_remaining' => 0,
        'remaining_rebooks' => getRemainingRebooks($pdo, $user_id),
        'rebooks_used' => 0,
        'is_eligible' => false,
        'is_expired' => false,
        'is_maxed' => false,
        'is_pending_rebook' => isPendingRebook($booking),
        'rebook_status' => 'unavailable',
        'rebook_message' => ''
    ];
    $info['rebooks_used'] = 2 - $info['remaining_rebooks'];
    $info['days_remaining'] = getRebookDaysRemaining($pdo, $booking['id']);

    $is_rebookable_status = in_array($booking['booking_status'], ['confirmed', 'completed']);
    if ($is_rebookable_status && $booking['payment_status'] == 'paid') {
        $msg = '';
        $can = canRebook($pdo, $booking['id'], $user_id, $msg);
        $info['is_eligible'] = $can;
        $info['rebook_message'] = $msg;

        if ($can) {
            $info['rebook_status'] = 'eligible';
        } else {
            if (($booking['rebook_count'] ?? 0) >= 2) {
                $info['is_maxed'] = true;
                $info['rebook_status'] = 'maxed';
            } elseif ($info['days_remaining'] <= 0) {
                $info['is_expired'] = true;
                $info['rebook_status'] = 'expired';
            } else {
                $info['rebook_status'] = 'other';
            }
        }
    }
    return $info;
}

// AUTH CHECK
if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ============================================================
// ✅ HANDLE OVERALL FEEDBACK
// ============================================================
if (isset($_POST['submit_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $rating       = (int)($_POST['rating'] ?? 0);
        $comment      = trim($_POST['comment'] ?? '');
        $user_id      = (int)$_SESSION['user_id'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        if ($rating < 1 || $rating > 5) throw new Exception("Please select a rating between 1 and 5.");

        $check = $pdo->prepare("SELECT id FROM overall_feedback WHERE user_id = ?");
        $check->execute([$user_id]);

        $is_update = false;
        if ($check->fetch()) {
            $is_update = true;
            $stmt = $pdo->prepare("UPDATE overall_feedback 
                                   SET rating = ?, comment = ?, updated_at = NOW(), is_anonymous = ? 
                                   WHERE user_id = ?");
            $stmt->execute([$rating, $comment, $is_anonymous, $user_id]);
            $_SESSION['flash_feedback_success'] = "Thank you! Your feedback has been updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO overall_feedback (user_id, rating, comment, is_anonymous) 
                                   VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $rating, $comment, $is_anonymous]);
            $_SESSION['flash_feedback_success'] = "Thank you for your feedback! Your review has been submitted.";
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                $is_update ? 'update' : 'create',
                'review',
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' " . ($is_update ? "updated" : "submitted") . " overall review from Profile — Rating: {$rating}/5" . ($is_anonymous ? " (Anonymous)" : ""),
                null,
                'overall_feedback',
                null,
                [
                    'rating' => $rating,
                    'anonymous' => $is_anonymous,
                    'has_comment' => !empty($comment),
                    'page' => 'profile.php'
                ]
            );
        }

        header("Location: profile.php?feedback_success=1");
        exit();
    } catch (Exception $e) {
        $error = "Failed to submit feedback: " . $e->getMessage();
    }
}

if (isset($_SESSION['flash_feedback_success'])) {
    $success = $_SESSION['flash_feedback_success'];
    unset($_SESSION['flash_feedback_success']);
}

// FETCH CONTENT & SETTINGS
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}
$hero_path = $content['site_settings']['hero_image_path'] ?? 'uploads/hero/hero-bg.jpg';
$hero_exists = file_exists($hero_path);
$location_address = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';
$maps_url = getGoogleMapsUrl($location_address);
$default_map_url = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863.123456789!2d119.1234567!3d16.1234567!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTbCsDA3JzI0LjAiTiAxMTnCsDA3JzI0LjAiRQ!5e0!3m2!1sen!2sph!4v1234567890';
$map_embed = !empty($google_maps_embed) && $google_maps_embed != '#' ? $google_maps_embed : $default_map_url;
$facebook_link = $content['social']['facebook'] ?? '#';

// GCash settings
$gcash_settings = [];
$stmt = $pdo->query("SELECT content_key, content_value FROM site_content WHERE section_name = 'gcash'");
while($row = $stmt->fetch()) {
    $gcash_settings[$row['content_key']] = $row['content_value'];
}
$gcash_name = $gcash_settings['account_name'] ?? 'Juan Dela Cruz';
$gcash_number = $gcash_settings['number'] ?? '09123456789';
$gcash_qr = $gcash_settings['qr_code'] ?? '';
$gcash_instructions = $gcash_settings['instructions'] ?? "1. Open GCash app\n2. Click 'Pay QR' or 'Scan QR'\n3. Scan the QR code above\n4. Enter the exact amount shown\n5. Complete the payment\n6. Take a screenshot of the transaction\n7. Upload screenshot as proof of payment";

$gcash_qr_path = 'uploads/gcash/' . $gcash_qr;
$gcash_qr_exists = !empty($gcash_qr) && file_exists($gcash_qr_path) && !is_dir($gcash_qr_path);

if (!$gcash_qr_exists) {
    $latest_file = null;
    $latest_time = 0;
    foreach (glob('uploads/gcash/gcash_qr.*') as $f) {
        $mtime = @filemtime($f);
        if ($mtime !== false && $mtime > $latest_time) {
            $latest_time = $mtime;
            $latest_file = $f;
        }
    }
    if ($latest_file !== null) {
        $gcash_qr_path = $latest_file;
        $gcash_qr_exists = true;
        $new_filename = basename($latest_file);

        $check = $pdo->prepare("SELECT id FROM site_content WHERE section_name = 'gcash' AND content_key = 'qr_code'");
        $check->execute();
        if ($check->fetch()) {
            $pdo->prepare("UPDATE site_content SET content_value = ? WHERE section_name = 'gcash' AND content_key = 'qr_code'")
                ->execute([$new_filename]);
        } else {
            $pdo->prepare("INSERT INTO site_content (section_name, content_key, content_value) VALUES ('gcash', 'qr_code', ?)")
                ->execute([$new_filename]);
        }
        $gcash_qr = $new_filename;
    }
}
$gcash_qr_version = $gcash_qr_exists ? @filemtime($gcash_qr_path) : 0;

// FETCH USER & GUEST
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$stmt = $pdo->prepare("SELECT * FROM guests WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$guest = $stmt->fetch();

$user_has_feedback = false;
$user_feedback = null;
if(isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_feedback = $stmt->fetch();
    $user_has_feedback = ($user_feedback !== false);
}

if (!file_exists('uploads/payments/')) {
    mkdir('uploads/payments/', 0777, true);
}

// ============================================================
// ✅ HANDLE POST ACTIONS
// ============================================================

// ---- REBOOK BOOKING ----
if(isset($_POST['rebook_booking'])) {
    try {
        $booking_id = $_POST['booking_id'];
        $check_in = $_POST['check_in'];
        $check_out = $_POST['check_out'];
        $guests = $_POST['guests'];

        $stmt = $pdo->prepare("SELECT * FROM house_bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $original_booking = $stmt->fetch();
        if(!$original_booking) throw new Exception("Booking not found");

        $message = '';
        if(!canRebook($pdo, $booking_id, $_SESSION['user_id'], $message)) throw new Exception($message);

        $check_in_date = new DateTime($check_in);
        $check_out_date = new DateTime($check_out);
        $today = new DateTime(); $today->setTime(0, 0, 0);

        if($check_in_date < $today) throw new Exception("Check-in date cannot be in the past.");
        if($check_out_date <= $check_in_date) throw new Exception("Check-out must be after check-in.");

        $booked_dates = getBookedDates($pdo, $original_booking['house_id'], $booking_id);
        foreach($booked_dates as $booked) {
            $booked_in = new DateTime($booked['check_in_date']);
            $booked_out = new DateTime($booked['check_out_date']);
            if($check_in_date < $booked_out && $check_out_date > $booked_in) throw new Exception("Selected dates are not available.");
        }

        $nights = $check_out_date->diff($check_in_date)->days;
        $orig_nights = (new DateTime($original_booking['check_out_date']))->diff(new DateTime($original_booking['check_in_date']))->days;
        $price_per_night = $orig_nights > 0 ? $original_booking['total_amount'] / $orig_nights : $original_booking['total_amount'];
        $total = $price_per_night * $nights * $guests;

        $stmt = $pdo->prepare("UPDATE house_bookings SET 
            previous_check_in_date = ?, previous_check_out_date = ?,
            previous_number_of_guests = ?, previous_total_amount = ?,
            check_in_date = ?, check_out_date = ?, number_of_guests = ?, total_amount = ?,
            booking_status = 'pending',
            rebook_count = COALESCE(rebook_count, 0) + 1,
            rebooked_at = NOW(), rebook_confirmed_at = NULL
            WHERE id = ?");
        $stmt->execute([
            $original_booking['check_in_date'], $original_booking['check_out_date'],
            $original_booking['number_of_guests'], $original_booking['total_amount'],
            $check_in, $check_out, $guests, $total, $booking_id
        ]);

        try { EmailNotifications::sendRebookAlert($booking_id, $pdo); } catch (Throwable $mailEx) {}

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'rebook',
                'booking',
                "Guest rebooked house booking '{$original_booking['reference_number']}' — new dates: {$check_in} to {$check_out} ({$nights} nights)",
                (int)$booking_id,
                'house_booking',
                [
                    'old_check_in'  => $original_booking['check_in_date'],
                    'old_check_out' => $original_booking['check_out_date'],
                    'old_total'     => $original_booking['total_amount']
                ],
                [
                    'new_check_in'  => $check_in,
                    'new_check_out' => $check_out,
                    'new_total'     => $total,
                    'new_guests'    => $guests
                ]
            );
        }

        header("Location: profile.php?rebooked=1");
        exit();
    } catch(Exception $e) {
        $error = "❌ " . $e->getMessage();
    }
}

// ---- CANCEL REBOOK ----
if(isset($_POST['cancel_rebook'])) {
    try {
        $booking_id = $_POST['booking_id'];

        $stmt = $pdo->prepare("SELECT id, guest_id, booking_status, payment_status, 
            rebook_count, rebooked_at, rebook_confirmed_at,
            previous_check_in_date, previous_check_out_date,
            previous_number_of_guests, previous_total_amount, reference_number
            FROM house_bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        if(!$booking) throw new Exception("Booking not found.");

        $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
        $guest_stmt->execute([$_SESSION['user_id']]);
        $guest = $guest_stmt->fetch();
        if(!$guest || $guest['id'] != $booking['guest_id']) throw new Exception("You don't have permission to cancel this rebook.");
        if(empty($booking['rebooked_at'])) throw new Exception("This booking has no pending rebook to cancel.");
        if(!empty($booking['rebook_confirmed_at'])) throw new Exception("This rebook has already been confirmed by admin.");

        $new_rebook_count = max(0, ($booking['rebook_count'] ?? 0) - 1);
        $has_backup = !empty($booking['previous_check_in_date']) && !empty($booking['previous_check_out_date']);

        if ($has_backup) {
            $stmt = $pdo->prepare("UPDATE house_bookings SET 
                check_in_date = previous_check_in_date,
                check_out_date = previous_check_out_date,
                number_of_guests = previous_number_of_guests,
                total_amount = previous_total_amount,
                previous_check_in_date = NULL, previous_check_out_date = NULL,
                previous_number_of_guests = NULL, previous_total_amount = NULL,
                booking_status = 'confirmed',
                rebook_count = ?, rebooked_at = NULL, rebook_confirmed_at = NULL
                WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE house_bookings SET 
                booking_status = 'confirmed',
                rebook_count = ?, rebooked_at = NULL, rebook_confirmed_at = NULL
                WHERE id = ?");
        }
        $stmt->execute([$new_rebook_count, $booking_id]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'cancel_rebook',
                'booking',
                "Guest cancelled pending rebook for booking '{$booking['reference_number']}' (restored original dates)",
                (int)$booking_id,
                'house_booking',
                null,
                ['rebook_count_after' => $new_rebook_count]
            );
        }

        header("Location: profile.php?rebook_cancelled=1");
        exit();
    } catch(Exception $e) {
        $error = "❌ " . $e->getMessage();
    }
}

// ============================================================
// ✅ FIXED: CANCEL UNPAID BOOKING (house / tour / food / package)
// ✅ NOW SENDS CANCELLATION EMAIL
// ============================================================
if(isset($_POST['cancel_booking'])) {
    try {
        $booking_id   = (int)($_POST['booking_id'] ?? 0);
        $booking_type = $_POST['booking_type'] ?? '';

        if (!in_array($booking_type, ['house', 'tour', 'food', 'package'])) {
            throw new Exception("Invalid booking type.");
        }

        $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
        $guest_stmt->execute([$_SESSION['user_id']]);
        $guestRow = $guest_stmt->fetch();
        if (!$guestRow) throw new Exception("Guest profile not found.");

        $table = $booking_type . '_bookings';
        $has_rebooked_at = ($booking_type === 'house');

        if ($has_rebooked_at) {
            $stmt = $pdo->prepare("SELECT id, reference_number, payment_status, booking_status, rebooked_at 
                                   FROM `$table` WHERE id = ? AND guest_id = ?");
        } else {
            $stmt = $pdo->prepare("SELECT id, reference_number, payment_status, booking_status, NULL AS rebooked_at 
                                   FROM `$table` WHERE id = ? AND guest_id = ?");
        }
        $stmt->execute([$booking_id, $guestRow['id']]);
        $booking = $stmt->fetch();

        if (!$booking) throw new Exception("Booking not found or does not belong to you.");

        if ($booking['payment_status'] === 'paid') {
            throw new Exception("This booking has already been paid and cannot be cancelled. Please use the Rebook feature instead.");
        }

        if ($booking['booking_status'] === 'cancelled') {
            throw new Exception("This booking is already cancelled.");
        }

        if ($booking_type === 'house') {
            $pdo->prepare("UPDATE `$table` 
                           SET booking_status = 'cancelled', 
                               cancelled_at = NOW(),
                               rebooked_at = NULL,
                               rebook_confirmed_at = NULL
                           WHERE id = ?")
                ->execute([$booking_id]);
        } else {
            $pdo->prepare("UPDATE `$table` 
                           SET booking_status = 'cancelled', 
                               cancelled_at = NOW()
                           WHERE id = ?")
                ->execute([$booking_id]);
        }

        // ✅ SEND CANCELLATION EMAIL (package-aware via EmailNotifications)
        try {
            if (method_exists('EmailNotifications', 'sendBookingCancelled')) {
                EmailNotifications::sendBookingCancelled(
                    $booking_id,
                    $booking_type,
                    $pdo,
                    'Cancelled by guest (free cancellation — booking was unpaid)',
                    $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Guest'
                );
            }
        } catch (Throwable $mailEx) {
            error_log("Cancel booking email failed: " . $mailEx->getMessage());
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'update',
                'booking',
                "Guest cancelled unpaid {$booking_type} booking '{$booking['reference_number']}' (free cancellation)",
                $booking_id,
                "{$booking_type}_booking",
                ['old_status' => $booking['booking_status'], 'payment_status' => $booking['payment_status']],
                ['new_status' => 'cancelled'],
                'warning'
            );
        }

        header("Location: profile.php?cancelled=1");
        exit();

    } catch(Exception $e) {
        $error = "❌ " . $e->getMessage();
    }
}

// ---- CONFIRM PAYMENT (admin/staff) ----
if(isset($_POST['confirm_payment']) && isset($_SESSION['user_id'])) {
    try {
        $check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $check->execute([$_SESSION['user_id']]);
        if(!in_array($check->fetchColumn(), ['admin', 'staff'])) throw new Exception("Only admin or staff can confirm payments.");

        $booking_type = $_POST['booking_type'];
        $booking_id = $_POST['booking_id'];
        $table = $booking_type . '_bookings';
        $pdo->prepare("UPDATE `$table` SET payment_status = 'paid', booking_status = 'confirmed', paid_at = NOW() WHERE id = ?")->execute([$booking_id]);
        try { EmailNotifications::sendPaymentConfirmation($booking_id, $booking_type, $pdo); } catch (Throwable $e) {}

        header("Location: profile.php?confirmed=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed to confirm payment: " . $e->getMessage();
    }
}

// ============================================================
// ✅ FIXED: COMPLETE BOOKING (house / tour / food / package)
// ============================================================
if(isset($_GET['complete_booking'])) {
    try {
        $check = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $check->execute([$_SESSION['user_id']]);
        if(!in_array($check->fetchColumn(), ['admin', 'staff'])) throw new Exception("Only admin or staff can complete bookings.");

        $booking_id   = (int)$_GET['complete_booking'];
        $booking_type = $_GET['booking_type'] ?? 'house';

        $allowed_tables = [
            'house'   => 'house_bookings',
            'tour'    => 'tour_bookings',
            'food'    => 'food_bookings',
            'package' => 'package_bookings'
        ];
        if (!isset($allowed_tables[$booking_type])) throw new Exception("Invalid booking type.");

        $table = $allowed_tables[$booking_type];

        $pdo->prepare("UPDATE `$table` SET booking_status = 'completed', completed_at = NOW() WHERE id = ?")
            ->execute([$booking_id]);

        // ✅ Email — package-aware na via EmailNotifications
        try { EmailNotifications::sendBookingCompleted($booking_id, $booking_type, $pdo); } catch (Throwable $e) {
            error_log("Complete booking email failed: " . $e->getMessage());
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'update',
                'booking',
                "Admin/Staff '{$_SESSION['username']}' marked {$booking_type} booking #{$booking_id} as COMPLETED",
                $booking_id,
                "{$booking_type}_booking"
            );
        }

        header("Location: profile.php?completed=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed: " . $e->getMessage();
    }
}

// ---- UPLOAD PROOF ----
if(isset($_POST['upload_proof'])) {
    try {
        $booking_type = $_POST['booking_type'] ?? '';
        $booking_id   = $_POST['booking_id'] ?? 0;
        $reference    = $_POST['reference'] ?? '';
        $gcash_reference = trim($_POST['gcash_reference'] ?? '');

        if (!in_array($booking_type, ['house', 'tour', 'food', 'package'])) throw new Exception("Invalid booking type.");
        if (empty($gcash_reference)) throw new Exception("GCash reference number is required.");
        if (!preg_match('/^[0-9]{6,20}$/', $gcash_reference)) throw new Exception("GCash reference number must be 6-20 digits.");

        $new_filename = null;
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $safe_ref = preg_replace('/[^A-Za-z0-9\-]/', '', $reference);
        if (empty($safe_ref)) $safe_ref = 'ref';

        $upload_dir = 'uploads/payments/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) throw new Exception("Cannot create uploads/payments directory.");
        }
        if (!is_writable($upload_dir)) throw new Exception("uploads/payments is not writable. Check folder permissions.");

        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES["payment_proof"]["name"], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext)) throw new Exception("Invalid file format. Please upload JPG, PNG, GIF, or PDF.");
            $new_filename = time() . '_' . $safe_ref . '.' . $ext;
            if (!move_uploaded_file($_FILES["payment_proof"]["tmp_name"], $upload_dir . $new_filename)) throw new Exception("Failed to upload file. Please try again.");
        } elseif (isset($_POST['payment_proof_base64']) && !empty($_POST['payment_proof_base64'])) {
            $base64_data = $_POST['payment_proof_base64'];
            if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $base64_data, $matches)) throw new Exception("Invalid camera image format.");
            $ext = ($matches[1] === 'jpeg' || $matches[1] === 'jpg') ? 'jpg' : $matches[1];
            $image_parts = explode(';base64,', $base64_data);
            $decoded = base64_decode($image_parts[1]);
            if ($decoded === false) throw new Exception("Failed to decode camera image.");
            if (strlen($decoded) > 5 * 1024 * 1024) throw new Exception("Camera image is too large.");
            $new_filename = time() . '_' . $safe_ref . '_camera.' . $ext;
            if (file_put_contents($upload_dir . $new_filename, $decoded) === false) throw new Exception("Failed to save camera image.");
        } else {
            throw new Exception("Please upload a payment proof OR take a photo.");
        }

        $table = $booking_type . '_bookings';
        $pdo->prepare("UPDATE `$table` SET payment_proof = ?, gcash_reference = ?, proof_uploaded_at = NOW() WHERE id = ?")
            ->execute([$new_filename, $gcash_reference, $booking_id]);

        try { EmailNotifications::sendPaymentProofAlert($booking_id, $booking_type, $pdo); } catch (Throwable $e) {}
        try { EmailNotifications::sendPaymentUnderReview($booking_id, $booking_type, $pdo); } catch (Throwable $e) {}

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'upload_proof',
                'booking',
                "Guest uploaded payment proof for {$booking_type} booking {$reference} — GCash Ref: {$gcash_reference}",
                (int)$booking_id,
                "{$booking_type}_booking",
                null,
                ['gcash_reference' => $gcash_reference, 'filename' => $new_filename]
            );
        }

        header("Location: profile.php?proof_uploaded=1");
        exit();
    } catch(Exception $e) {
        $error = "❌ " . $e->getMessage();
    }
}

// ---- SUBMIT PER-BOOKING FEEDBACK ----
if(isset($_POST['submit_feedback'])) {
    try {
        $booking_type = $_POST['booking_type'];
        $booking_id = $_POST['booking_id'];
        $rating = $_POST['rating'];
        $feedback = $_POST['feedback'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
        $table = $booking_type . '_bookings';
        $pdo->prepare("UPDATE `$table` SET feedback_rating = ?, feedback_text = ?, feedback_date = NOW(), feedback_is_anonymous = ? WHERE id = ?")
            ->execute([$rating, $feedback, $is_anonymous, $booking_id]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'create',
                'review',
                "Guest submitted {$booking_type} feedback for booking #{$booking_id} — Rating: {$rating}/5" . ($is_anonymous ? " (Anonymous)" : ""),
                (int)$booking_id,
                "{$booking_type}_booking",
                null,
                ['rating' => $rating, 'anonymous' => $is_anonymous]
            );
        }

        header("Location: profile.php?feedback=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed: " . $e->getMessage();
    }
}

// ---- EDIT PER-BOOKING FEEDBACK ----
if(isset($_POST['edit_house_feedback']) || isset($_POST['edit_tour_feedback']) || isset($_POST['edit_food_feedback'])) {
    try {
        if(isset($_POST['edit_house_feedback'])) $booking_type = 'house';
        elseif(isset($_POST['edit_tour_feedback'])) $booking_type = 'tour';
        else $booking_type = 'food';

        $booking_id = $_POST['booking_id'];
        $rating = $_POST['rating'];
        $feedback = $_POST['feedback'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
        $table = $booking_type . '_bookings';
        $pdo->prepare("UPDATE `$table` SET feedback_rating = ?, feedback_text = ?, feedback_date = NOW(), feedback_is_anonymous = ? WHERE id = ?")
            ->execute([$rating, $feedback, $is_anonymous, $booking_id]);

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'update',
                'review',
                "Guest updated {$booking_type} feedback for booking #{$booking_id} — Rating: {$rating}/5" . ($is_anonymous ? " (Anonymous)" : ""),
                (int)$booking_id,
                "{$booking_type}_booking",
                null,
                ['rating' => $rating, 'anonymous' => $is_anonymous]
            );
        }

        header("Location: profile.php?feedback_updated=1");
        exit();
    } catch(Exception $e) {
        $error = "Failed: " . $e->getMessage();
    }
}

// ---- LOGOUT ----
if(isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out from Profile",
            (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

// FETCH USER BOOKINGS
$house_bookings = [];
if($guest) {
    $stmt = $pdo->prepare("SELECT b.*, b.guest_names, h.house_name, h.image 
                           FROM house_bookings b 
                           JOIN houses h ON b.house_id = h.id 
                           WHERE b.guest_id = ? 
                             AND (b.package_id IS NULL OR b.package_id = 0)
                           ORDER BY b.created_at DESC");
    $stmt->execute([$guest['id']]);
    $house_bookings = $stmt->fetchAll();
}
$tour_bookings = [];
if($guest) {
    $stmt = $pdo->prepare("SELECT b.*, t.tour_name, t.image 
                           FROM tour_bookings b 
                           JOIN tours t ON b.tour_id = t.id 
                           WHERE b.guest_id = ? 
                             AND (b.package_id IS NULL OR b.package_id = 0)
                           ORDER BY b.created_at DESC");
    $stmt->execute([$guest['id']]);
    $tour_bookings = $stmt->fetchAll();
}
$food_bookings = [];
if($guest) {
    try {
        $stmt = $pdo->prepare("SELECT b.*, f.name as food_name, f.price as food_price, f.image as food_image
                               FROM food_bookings b 
                               JOIN food_items f ON b.food_id = f.id 
                               WHERE b.guest_id = ? 
                                 AND (b.package_id IS NULL OR b.package_id = 0)
                               ORDER BY b.created_at DESC");
        $stmt->execute([$guest['id']]);
        $food_bookings = $stmt->fetchAll();
    } catch(PDOException $e) { $food_bookings = []; }
}

// ✅ FETCH PACKAGE BOOKINGS — WITH FULL DETAIL FIELDS (including times)
$package_bookings = [];
if($guest) {
    try {
        $has_pkg_table = !empty($pdo->query("SHOW TABLES LIKE 'package_bookings'")->fetchAll());
        if ($has_pkg_table) {
            $stmt = $pdo->prepare("SELECT 
                pb.*,
                hb.house_id, h.house_name,
                hb.check_in_date AS house_check_in,
                hb.check_in_time AS house_check_in_time,
                hb.check_out_date AS house_check_out,
                hb.check_out_time AS house_check_out_time,
                hb.number_of_guests AS house_guests,
                hb.total_amount AS house_amount,
                hb.guest_names AS house_guest_names,
                tb.tour_id, t.tour_name,
                tb.booking_date AS tour_date,
                tb.preferred_time AS tour_time,
                tb.number_of_guests AS tour_guests,
                tb.guest_name AS tour_guest_name,
                tb.contact_number AS tour_contact,
                tb.total_amount AS tour_amount,
                fb.food_id, f.name as food_name,
                fb.size_variant AS food_size,
                fb.preferred_date AS food_date,
                fb.preferred_time AS food_time,
                fb.number_of_persons AS food_persons,
                fb.quantity AS food_qty,
                fb.guest_name AS food_guest_name,
                fb.total_amount AS food_amount
                FROM package_bookings pb
                LEFT JOIN house_bookings hb ON pb.house_booking_id = hb.id
                LEFT JOIN houses h ON hb.house_id = h.id
                LEFT JOIN tour_bookings tb ON pb.tour_booking_id = tb.id
                LEFT JOIN tours t ON tb.tour_id = t.id
                LEFT JOIN food_bookings fb ON pb.food_booking_id = fb.id
                LEFT JOIN food_items f ON fb.food_id = f.id
                WHERE pb.guest_id = ? 
                ORDER BY pb.created_at DESC");
            $stmt->execute([$guest['id']]);
            $package_bookings = $stmt->fetchAll();
        }
    } catch(PDOException $e) { $package_bookings = []; }
}

$is_admin = in_array($user['role'] ?? '', ['admin', 'staff']);
$total_bookings = count($house_bookings) + count($tour_bookings) + count($food_bookings) + count($package_bookings);
$id_photo = getIDPhoto($guest);
$profile_photo = getProfilePhoto($guest);

$pending_count = 0;
foreach($house_bookings as $b) if($b['booking_status'] == 'pending') $pending_count++;
foreach($tour_bookings as $b) if($b['booking_status'] == 'pending') $pending_count++;
foreach($food_bookings as $b) if($b['booking_status'] == 'pending') $pending_count++;
foreach($package_bookings as $b) if($b['booking_status'] == 'pending') $pending_count++;

// TERMS handling
if (isset($_POST['accept_terms']) && isset($_SESSION['user_id'])) {
    $termsGate->accept((int)$_SESSION['user_id'], getClientIp());
    unset($_SESSION['show_terms_modal']);
    $_SESSION['terms_accepted'] = true;

    if (class_exists('SystemLogger')) {
        SystemLogger::log(
            $pdo,
            'accept',
            'terms',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' accepted Terms & Privacy Policy (v" . $termsGate->getCurrentVersion() . ") from Profile",
            (int)$_SESSION['user_id'],
            'user',
            null,
            ['version' => $termsGate->getCurrentVersion(), 'ip' => getClientIp(), 'page' => 'profile.php']
        );
    }

    header("Location: profile.php?terms_accepted=1");
    exit();
}

$force_must_accept = false;
if (isset($_SESSION['show_terms_modal']) && $_SESSION['show_terms_modal']) {
    $force_must_accept = true;
}
if (!$force_must_accept && isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'guest') {
    if (!$termsGate->hasAccepted((int)$_SESSION['user_id'])) {
        $force_must_accept = true;
    }
}
$termsContent = $termsGate->getContent();
$termsVersion = $termsGate->getCurrentVersion();

// ✅ Determine default tab from URL
$default_tab = 'houses';
if (isset($_GET['tab'])) {
    $requested_tab = $_GET['tab'];
    if (in_array($requested_tab, ['houses', 'tours', 'food', 'packages', 'rebooks', 'history'])) {
        $default_tab = $requested_tab;
    }
} elseif (isset($_GET['upload_proof'])) {
    $default_tab = 'packages';
}

// LOGO PATHS
$sidebar_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $sidebar_logo = $content['site_settings']['logo_path'];
}
$sidebar_logo_exists = !empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#0B2447">
    <title>My Dashboard - Transient House & Tours</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ============================================================
           RESET & BASE
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb; min-height: 100vh; overflow-x: hidden;
            font-size: 14px; line-height: 1.5; color: #1e293b;
        }
        img { max-width: 100%; height: auto; display: block; }
        button, input, select, textarea { font-family: inherit; font-size: inherit; }

        /* ============================================================
           HEADER — logo sits right next to hamburger on mobile
           ============================================================ */
        .header {
            background: #0B2447;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            padding: 10px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 2px solid rgba(77, 166, 217, 0.2);
        }
        .header-content {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        /* Hamburger is part of the normal flex flow — no more position: fixed */
        .menu-toggle {
            display: none;
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(77, 166, 217, 0.3);
            border-radius: 10px;
            width: 44px;
            height: 44px;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.25); transform: scale(1.05); }
        .menu-toggle .fa-bars { transition: transform 0.3s ease; }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: scale(0.8);
        }

        .logo-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 1;
            min-width: 0;
            flex: 1;
        }
        .logo-wrapper .logo-image,
        .logo-wrapper .logo-image-placeholder {
            height: 50px;
            width: 50px;
            border-radius: 12px;
            flex-shrink: 0;
        }
        .logo-wrapper .logo-image {
            object-fit: cover;
            border: 2px solid #4DA6D9;
            padding: 2px;
            background: white;
            transition: transform 0.3s ease;
        }
        .logo-wrapper .logo-image:hover { transform: scale(1.05); }
        .logo-wrapper .logo-image-placeholder {
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid #4DA6D9;
        }
        .logo-wrapper .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
            min-width: 0;
        }
        .logo-wrapper .brand-text .brand-name {
            font-size: 20px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .logo-wrapper .brand-text .brand-tagline {
            font-size: 11px;
            color: #7bb8f0;
            font-weight: 500;
            letter-spacing: 0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .desktop-nav {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: nowrap;
            flex-shrink: 0;
            min-width: 0;
        }
        .desktop-nav a {
            padding: 8px 12px;
            border-radius: 8px;
            color: #b3d9ff;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            white-space: nowrap;
            flex-shrink: 0;
            line-height: 1.2;
        }
        .desktop-nav a i { font-size: 13px; }
        .desktop-nav a:hover { background: rgba(77, 166, 217, 0.2); color: white; }
        .desktop-nav a.active-nav { background: rgba(77, 166, 217, 0.25); color: white; }
        .desktop-nav .btn-logout {
            background: #ef4444;
            color: white;
            border-radius: 8px;
            padding: 8px 14px;
        }
        .desktop-nav .btn-logout:hover { background: #dc2626; }
        .desktop-nav .btn-rate {
            background: #F4B400;
            color: #0B2447;
            border-radius: 8px;
            padding: 8px 14px;
        }
        .desktop-nav .btn-rate:hover { background: #e6a800; color: #0B2447; }

        /* SIDEBAR */
        .sidebar {
            position: fixed;
            top: 0;
            left: -320px;
            width: 300px;
            height: 100vh;
            background: #0B2447;
            box-shadow: 4px 0 30px rgba(0,0,0,0.3);
            padding: 25px 0;
            transition: left 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            border-right: 2px solid rgba(77, 166, 217, 0.15);
        }
        .sidebar.open { left: 0; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }

        .sidebar-header {
            padding: 0 20px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 20px;
        }
        .sidebar-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .sidebar-header .logo {
            font-size: 22px;
            font-weight: 700;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }
        .sidebar-header .logo .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
        }
        .sidebar-header .logo img {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            object-fit: cover;
            border: 2px solid #4DA6D9;
            padding: 2px;
            background: white;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
        }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main {
            font-size: 18px;
            font-weight: 700;
            color: white;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-header .logo .logo-text .sub {
            font-size: 10px;
            color: #7bb8f0;
            font-weight: 400;
            letter-spacing: 0.3px;
        }

        .sidebar-close-btn {
            display: none;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            color: #e0eeff;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .sidebar-close-btn:hover {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
            transform: rotate(90deg);
        }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 20px;
            color: #b3d9ff;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-weight: 500;
            font-size: 14px;
        }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav i { color: #7bb8f0; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* ============================================================
           RESPONSIVE HEADER
           ============================================================ */
        @media (max-width: 1200px) {
            .desktop-nav .btn-logout .logout-text { display: none; }
            .desktop-nav .btn-logout { padding: 8px 12px; }
            .desktop-nav .btn-rate .rate-text { display: none; }
            .desktop-nav .btn-rate { padding: 8px 12px; }
        }

        /* Hide desktop nav and show hamburger on mobile / tablet */
        @media (max-width: 1100px) {
            .desktop-nav { display: none !important; }
            .menu-toggle { display: flex; }
            .sidebar-close-btn { display: flex; }

            /* ✅ KEY FIX: header-content is now tight, so logo sits next to hamburger */
            .header-content {
                padding-left: 20px;
                padding-right: 20px;
                gap: 10px;
                justify-content: flex-start;
            }
        }

        @media (max-width: 768px) {
            .header { padding: 8px 0; }
            .header-content {
                padding-left: 14px;
                padding-right: 14px;
                gap: 8px;
            }
            .menu-toggle {
                width: 40px;
                height: 40px;
                font-size: 18px;
                border-radius: 9px;
            }
            .logo-wrapper { gap: 10px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder { height: 40px; width: 40px; border-radius: 10px; }
            .logo-wrapper .brand-text .brand-name { font-size: 16px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 10px; }
        }

        @media (max-width: 480px) {
            .header { padding: 7px 0; }
            .header-content {
                padding-left: 12px;
                padding-right: 12px;
                gap: 8px;
            }
            .menu-toggle {
                width: 38px;
                height: 38px;
                font-size: 17px;
                border-radius: 9px;
            }
            .logo-wrapper { gap: 9px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder {
                height: 36px;
                width: 36px;
                border-radius: 9px;
            }
            .logo-wrapper .brand-text .brand-name { font-size: 14.5px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 9.5px; }
        }

        @media (max-width: 360px) {
            .header-content { padding-left: 10px; padding-right: 10px; gap: 7px; }
            .menu-toggle { width: 36px; height: 36px; font-size: 16px; }
            .logo-wrapper .logo-image,
            .logo-wrapper .logo-image-placeholder { height: 34px; width: 34px; }
            .logo-wrapper .brand-text .brand-name { font-size: 13.5px; }
            .logo-wrapper .brand-text .brand-tagline { display: none; }
        }

        /* HERO */
        .hero {
            <?php if($hero_exists): ?>
            background: linear-gradient(rgba(11, 36, 71, 0.5), rgba(11, 36, 71, 0.6)), url('<?php echo $hero_path; ?>?<?php echo time(); ?>');
            background-size: cover; background-position: center;
            <?php else: ?>
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            <?php endif; ?>
            padding: 80px 0; color: white; text-align: center; position: relative;
        }
        .hero-content { max-width: 800px; margin: 0 auto; padding: 0 20px; position: relative; z-index: 1; }
        .hero h1 { font-size: 48px; font-weight: 700; margin-bottom: 20px; text-shadow: 0 2px 25px rgba(0,0,0,0.25); word-wrap: break-word; }
        .hero h1 i { color: #7bb8f0; }
        .hero p { font-size: 18px; margin-bottom: 30px; opacity: 0.95; text-shadow: 0 1px 15px rgba(0,0,0,0.15); }

        .main-container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; animation: slideDown 0.3s ease; word-break: break-word; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #e6f7e6; color: #10b981; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #ef4444; border-left: 4px solid #ef4444; }
        .alert i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #4DA6D9; border-radius: 16px; padding: 25px 20px; text-align: center; box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2); transition: transform 0.3s; border: 1px solid rgba(255,255,255,0.15); }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(77, 166, 217, 0.3); }
        .stat-icon { width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 20px; border: 2px solid rgba(255,255,255,0.1); }
        .stat-number { font-size: 28px; font-weight: 700; color: white; word-break: break-word; }
        .stat-label { color: rgba(255,255,255,0.9); font-size: 13px; }

        /* PROFILE GRID */
        .profile-grid { display: grid; grid-template-columns: 350px 1fr; gap: 30px; margin-top: 30px; align-items: start; }
        .profile-card { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); height: fit-content; border: 1px solid #e8f0fe; position: sticky; top: 90px; }
        .profile-header { text-align: center; margin-bottom: 25px; }
        .avatar { width: 120px; height: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 48px; overflow: hidden; border: 4px solid #4DA6D9; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); }
        .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .avatar .default-icon { font-size: 48px; color: white; }
        .profile-name { font-size: 22px; font-weight: 700; color: #0B2447; word-break: break-word; }
        .profile-role { color: #4DA6D9; font-size: 14px; margin-bottom: 10px; }
        .profile-info { border-top: 1px solid #e8f0fe; padding-top: 20px; }
        .info-item { display: flex; align-items: flex-start; gap: 15px; padding: 10px 0; border-bottom: 1px solid #e8f0fe; }
        .info-item:last-child { border-bottom: none; }
        .info-icon { width: 35px; color: #4DA6D9; font-size: 16px; flex-shrink: 0; padding-top: 2px; }
        .info-label { font-size: 12px; color: #94a8b8; }
        .info-value { font-weight: 600; color: #0B2447; font-size: 13px; word-break: break-word; }
        .btn-edit { width: 100%; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; text-align: center; margin-top: 20px; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2); }
        .btn-edit:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3); }

        .id-photo-display { margin-top: 15px; padding-top: 15px; border-top: 1px solid #e8f0fe; }
        .id-photo-display .id-photo-label { font-size: 12px; color: #94a8b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
        .id-photo-display .id-photo-container { display: flex; align-items: center; gap: 12px; margin-top: 8px; flex-wrap: wrap; }
        .id-photo-display .id-photo-container img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 2px solid #e2e8f0; }
        .id-photo-display .id-photo-container .no-photo { color: #94a3b8; font-size: 13px; font-style: italic; }
        .id-photo-display .id-photo-container .id-details { font-size: 13px; color: #475569; word-break: break-word; }
        .id-photo-display .id-photo-container .id-details strong { color: #1e293b; }

        .health-info-section { margin-top: 15px; border-top: 2px solid #e8f0fe; padding-top: 15px; }
        .health-info-section h5 { font-size: 13px; font-weight: 700; color: #0B2447; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .health-info-section h5 i { color: #ef4444; }
        .health-tag { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; margin: 3px; }
        .health-tag.asthma { background: #fef3c7; color: #f59e0b; }
        .health-tag.allergy { background: #fee2e2; color: #ef4444; }
        .health-tag.medical { background: #dbeafe; color: #3b82f6; }
        .health-tag.dietary { background: #e6f7e6; color: #10b981; }
        .health-tag.access { background: #f3e8ff; color: #8b5cf6; }

        .bookings-section { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #e8f0fe; min-width: 0; }
        .section-tabs { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 2px solid #e8f0fe; padding-bottom: 15px; flex-wrap: wrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
        .section-tabs::-webkit-scrollbar { display: none; }
        .tab { padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; color: #64748b; transition: all 0.3s; white-space: nowrap; font-size: 14px; user-select: none; }
        .tab:hover { background: #f1f5f9; color: #4DA6D9; }
        .tab.active { background: #4DA6D9; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e8f0fe; position: relative; background: white; }
        table { width: 100%; border-collapse: collapse; min-width: 850px; background: white; }
        thead th { text-align: left; padding: 12px 14px; background: #f8fafc; color: #0B2447; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; border-bottom: 2px solid #e8f0fe; }
        tbody td { padding: 12px 14px; border-bottom: 1px solid #e8f0fe; color: #475569; font-size: 13px; vertical-align: top; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f8fafc; }

        /* ✅ NEW: Time display under date */
        .booking-time-row { font-size: 11px; color: #64748b; margin-top: 2px; }
        .booking-time-row i { color: #4DA6D9; font-size: 10px; }

        .action-cell { display: flex; flex-direction: column; gap: 5px; min-width: 130px; }
        .action-row { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }

        .btn-sm { padding: 5px 12px; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; line-height: 1.4; min-height: 28px; }
        .btn-sm:hover { transform: translateY(-2px); }
        .btn-pay { background: #10b981; color: white; }
        .btn-pay:hover { background: #059669; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-cancel-booking { background: #ef4444; color: white; }
        .btn-cancel-booking:hover { background: #dc2626; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
        .btn-complete { background: #8b5cf6; color: white; }
        .btn-complete:hover { background: #7c3aed; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }
        .btn-feedback { background: #8b5cf6; color: white; }
        .btn-feedback:hover { background: #7c3aed; }
        .btn-view-feedback { background: #0ea5e9; color: white; }
        .btn-view-feedback:hover { background: #0284c7; }
        .btn-edit-feedback { background: #f59e0b; color: #0B2447; }
        .btn-edit-feedback:hover { background: #d97706; }

        .btn-rebook-link { background: #f59e0b; color: #0B2447; padding: 5px 14px; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; white-space: nowrap; line-height: 1.4; min-height: 28px; }
        .btn-rebook-link:hover { background: #d97706; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); color: #0B2447; text-decoration: none; }
        .btn-rebook-link:disabled, .btn-rebook-link.disabled { background: #cbd5e1; color: #94a3b8; cursor: not-allowed; pointer-events: none; transform: none; box-shadow: none; }

        .btn-cancel-rebook { background: #f59e0b; color: #0B2447; padding: 5px 12px; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; white-space: nowrap; min-height: 28px; }
        .btn-cancel-rebook:hover { background: #d97706; transform: translateY(-2px); color: #0B2447; }

        .btn-view-booking { background: #4DA6D9; color: white; padding: 4px 12px; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; white-space: nowrap; min-height: 28px; }
        .btn-view-booking:hover { background: #3a8bbf; transform: translateY(-2px); color: white; }

        /* MOBILE CARDS */
        .booking-cards-mobile { display: none; flex-direction: column; gap: 12px; }
        @media (min-width: 769px) { .booking-cards-mobile { display: none !important; } }
        @media (max-width: 768px) {
            .table-responsive.desktop-table { display: none !important; }
            .booking-cards-mobile { display: flex; }
        }

        .booking-card-mobile { background: white; border-radius: 14px; padding: 14px; border: 1px solid #e8f0fe; box-shadow: 0 4px 12px rgba(0,0,0,0.04); display: flex; flex-direction: column; gap: 10px; }
        .booking-card-mobile .card-top-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .booking-card-mobile .card-ref { font-weight: 700; color: #0B2447; font-size: 13px; word-break: break-all; line-height: 1.4; }
        .booking-card-mobile .card-badges { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; flex-shrink: 0; }
        .booking-card-mobile .card-row { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: #475569; }
        .booking-card-mobile .card-row i { width: 16px; color: #4DA6D9; flex-shrink: 0; text-align: center; padding-top: 3px; }
        .booking-card-mobile .card-row .card-label { color: #94a3b8; font-size: 10px; font-weight: 700; min-width: 46px; text-transform: uppercase; letter-spacing: 0.3px; padding-top: 2px; }
        .booking-card-mobile .card-row .card-value { font-weight: 600; color: #0B2447; word-break: break-word; flex: 1; min-width: 0; }
        .booking-card-mobile .card-proof { display: flex; align-items: center; gap: 10px; padding-top: 8px; border-top: 1px solid #f1f5f9; }
        .booking-card-mobile .card-proof-thumb { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 1px solid #e2e8f0; background: #f8fafc; cursor: pointer; flex-shrink: 0; }
        .booking-card-mobile .card-proof-thumb.no-proof { display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 18px; cursor: default; }
        .booking-card-mobile .card-proof-info { flex: 1; min-width: 0; }
        .booking-card-mobile .card-proof-info .card-label { font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
        .booking-card-mobile .card-proof-info .card-gcash { font-family: 'Courier New', monospace; font-size: 12px; font-weight: 700; color: #065f46; word-break: break-all; }
        .booking-card-mobile .card-actions { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 8px; border-top: 1px solid #f1f5f9; }
        .booking-card-mobile .btn-card-action { flex: 1; min-width: 100px; min-height: 42px; padding: 10px 14px; border: none; border-radius: 10px; font-weight: 700; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; transition: all 0.2s; -webkit-tap-highlight-color: rgba(0,0,0,0.1); touch-action: manipulation; }
        .booking-card-mobile .btn-card-action:active { transform: scale(0.97); }
        .booking-card-mobile .btn-more { background: linear-gradient(135deg, #4DA6D9, #3a8bbf); color: white; box-shadow: 0 4px 12px rgba(77, 166, 217, 0.25); }
        .booking-card-mobile .btn-confirm-mobile { background: #10b981; color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25); }
        .booking-card-mobile .btn-reject-mobile { background: #ef4444; color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25); }
        .booking-card-mobile .btn-rebook-confirm-mobile { background: linear-gradient(135deg, #8b5cf6, #a78bfa); color: white; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.25); }
        .booking-card-mobile .btn-pay-mobile { background: linear-gradient(135deg, #10b981, #059669); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25); }
        .booking-card-mobile .btn-cancel-booking-mobile { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25); }
        .booking-card-mobile .btn-cancel-rebook-mobile { background: #f59e0b; color: #0B2447; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25); }

        .rebook-info-container { display: flex; flex-direction: column; gap: 4px; margin-top: 4px; }
        .rebook-info-container .badge { font-size: 10px; padding: 3px 10px; display: inline-block; }
        .badge-days { background: #e0f2fe; color: #0284c7; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-days.warning { background: #fef3c7; color: #92400e; }
        .badge-days.danger { background: #fee2e2; color: #991b1b; }
        .badge-rebooks { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-rebooks.warning { background: #fef3c7; color: #92400e; }
        .badge-expired { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-maxed { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-completed { background: #d1fae5; color: #065f46; }

        .rebook-pending-badge { display: inline-block; margin-top: 5px; padding: 3px 10px; background: #fef3c7; color: #92400e; border-radius: 12px; font-size: 10px; font-weight: 600; border: 1px solid #fcd34d; white-space: nowrap; }
        .rebook-pending-badge i { margin-right: 3px; }
        .feedback-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: #d1fae5; color: #065f46; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-info { background: #dbeafe; color: #3b82f6; }
        .badge-danger { background: #fee2e2; color: #ef4444; }

        /* REBOOK POLICY POPUP */
        .rebook-policy-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(5px); padding: 20px; overflow-y: auto; }
        .rebook-policy-overlay.show { display: flex; }
        .rebook-policy-popup { background: white; border-radius: 24px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 35px 30px; box-shadow: 0 30px 80px rgba(0,0,0,0.3); text-align: center; }
        .rebook-policy-popup .popup-icon { font-size: 60px; color: #f59e0b; margin-bottom: 15px; }
        .rebook-policy-popup h3 { font-size: 22px; font-weight: 700; color: #1e293b; margin-bottom: 10px; }
        .rebook-policy-popup p { color: #64748b; font-size: 14px; line-height: 1.7; margin-bottom: 20px; }
        .rebook-policy-popup .policy-list { text-align: left; background: #f8fafc; padding: 15px 20px; border-radius: 12px; margin: 15px 0; }
        .rebook-policy-popup .policy-list li { color: #475569; font-size: 13px; padding: 5px 0; list-style: none; }
        .rebook-policy-popup .policy-list li i { margin-right: 8px; }
        .rebook-policy-popup .policy-list li i.check { color: #10b981; }
        .rebook-policy-popup .policy-list li i.info { color: #3b82f6; }
        .rebook-policy-popup .btn-proceed { padding: 12px 25px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 15px; flex: 1; min-width: 140px; }
        .rebook-policy-popup .btn-proceed:hover { transform: translateY(-2px); }
        .rebook-policy-popup .btn-cancel-popup { padding: 12px 25px; background: #64748b; color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 15px; flex: 1; min-width: 140px; }
        .rebook-policy-popup .btn-cancel-popup:hover { background: #475569; }
        .rebook-policy-popup .popup-actions { display: flex; justify-content: center; gap: 10px; margin-top: 20px; flex-wrap: wrap; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; padding: 30px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 60px rgba(0,0,0,0.3); -webkit-overflow-scrolling: touch; }
        .modal-lg { max-width: 700px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; gap: 10px; }
        .modal-header h3 { font-size: 18px; font-weight: 600; color: #0B2447; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; word-break: break-word; margin: 0; }
        .modal-header h3 i { color: #4DA6D9; flex-shrink: 0; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a8b8; transition: color 0.3s; background: none; border: none; padding: 0 10px; line-height: 1; flex-shrink: 0; }
        .modal-header .close:hover { color: #ef4444; }

        .view-booking-modal .modal-content { max-width: 700px; overflow-x: hidden; }
        .view-booking-modal .booking-detail-item { display: flex; justify-content: space-between; padding: 10px 15px; border-bottom: 1px solid #e8f0fe; align-items: flex-start; gap: 10px; }
        .view-booking-modal .booking-detail-item:last-child { border-bottom: none; }
        .view-booking-modal .booking-detail-item .label { font-weight: 600; color: #475569; font-size: 13px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .view-booking-modal .booking-detail-item .value { font-weight: 600; color: #0B2447; font-size: 13px; text-align: right; word-break: break-word; flex: 1; }

        .view-booking-header {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 10px;
            flex-wrap: nowrap !important;
            padding-bottom: 12px;
            margin-bottom: 0 !important;
            border-bottom: none !important;
        }
        .view-booking-title-wrap {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            margin: 0 !important;
            min-width: 0;
            flex: 1 1 auto;
            flex-wrap: nowrap !important;
        }
        .view-booking-title-wrap > i {
            flex-shrink: 0;
            font-size: 18px;
            color: #4DA6D9;
        }
        .view-booking-title-text {
            font-size: 18px;
            font-weight: 700;
            color: #0B2447;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
            flex: 1 1 auto;
        }
        .view-booking-modal .modal-header .close {
            flex-shrink: 0;
            align-self: center;
        }
        .view-booking-ref-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f0f7fb;
            border: 1px solid #d4e4f0;
            border-radius: 10px;
            padding: 10px 14px;
            margin: 0 0 18px 0;
            font-size: 13px;
            font-weight: 700;
            color: #0B2447;
            letter-spacing: 0.5px;
            word-break: break-all;
            overflow-wrap: anywhere;
            line-height: 1.4;
        }
        .view-booking-ref-bar > i {
            color: #4DA6D9;
            flex-shrink: 0;
            font-size: 12px;
        }
        .view-booking-ref-bar > span {
            min-width: 0;
            flex: 1 1 auto;
        }

        @media (max-width: 768px) {
            .view-booking-title-text { font-size: 15px; }
            .view-booking-title-wrap > i { font-size: 16px; }
            .view-booking-ref-bar { font-size: 12px; padding: 9px 12px; margin-bottom: 14px; }
        }
        @media (max-width: 480px) {
            .view-booking-title-text { font-size: 14px; }
            .view-booking-title-wrap > i { font-size: 15px; }
            .view-booking-ref-bar { font-size: 11.5px; padding: 8px 10px; letter-spacing: 0.3px; }
        }

        .qr-code { text-align: center; padding: 20px; background: #f8fafc; border-radius: 16px; margin: 20px 0; border: 2px dashed #e8f0fe; }
        .qr-code img { max-width: 250px; width: 100%; height: auto; border-radius: 12px; margin: 0 auto; }

        .prev-rating-banner { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; text-align: center; margin-bottom: 18px; }
        .prev-rating-banner .prev-label { font-size: 14px; color: #475569; margin-bottom: 8px; font-weight: 500; }
        .prev-rating-banner .prev-stars { display: flex; justify-content: center; gap: 6px; font-size: 26px; margin-bottom: 8px; line-height: 1; }
        .prev-rating-banner .prev-stars i { transition: color 0.2s ease; }
        .prev-rating-banner .prev-hint { font-size: 12.5px; color: #94a3b8; }

        .overall-rating-label { text-align: center; font-weight: 700; color: #0f172a; font-size: 15px; margin-bottom: 12px; display: block; }
        .big-rating-input { display: flex; justify-content: center; gap: 8px; font-size: 40px; line-height: 1; margin-bottom: 4px; }
        .big-rating-input i { color: #cbd5e1; transition: color 0.15s ease, transform 0.15s ease; user-select: none; cursor: pointer; }
        .big-rating-input i.active { color: #f59e0b; }
        .big-rating-input i.preview { color: #f59e0b; transform: scale(1.08); }
        .big-rating-input i:hover { transform: scale(1.08); }

        .rating-word { text-align: center; color: #64748b; font-size: 14px; margin: 4px 0 18px 0; min-height: 20px; font-weight: 500; }
        .review-section-label { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: block; }
        .review-textarea { width: 100%; padding: 12px 14px; border: 1px solid #d4e4f0; border-radius: 10px; background: #f8faff; color: #1a3a5c; font-family: inherit; font-size: 14px; resize: vertical; min-height: 85px; transition: border-color 0.2s, background 0.2s; margin-bottom: 14px; }
        .review-textarea:focus { outline: none; border-color: #4DA6D9; background: #ffffff; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.12); }
        .anon-row { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; cursor: pointer; }
        .anon-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: #7c3aed; cursor: pointer; flex-shrink: 0; }
        .anon-row .anon-text { font-weight: 600; color: #1e293b; font-size: 14px; display: flex; align-items: center; gap: 6px; }
        .anon-row .anon-text i { color: #7c3aed; }
        .anon-row .anon-note { margin-left: auto; font-size: 11.5px; color: #94a3b8; font-style: italic; }
        .update-review-btn { width: 100%; padding: 14px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.25); }
        .update-review-btn:hover { background: #e6a800; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.4); }
        .btn-cancel-review { flex: 1; padding: 14px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; }
        .btn-cancel-review:hover { background: #cbd5e1; transform: translateY(-2px); }

        .alert-overlay { position: fixed; top: 20px; right: 20px; z-index: 3000; display: none; }
        .alert-overlay.show { display: flex; align-items: center; justify-content: center; inset: 0; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); }
        .alert-box { background: white; border-radius: 20px; width: 90%; max-width: 400px; padding: 35px 30px 25px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.15); border-top: 6px solid #10b981; animation: alertPopIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
        @keyframes alertPopIn { from { opacity: 0; transform: translateY(-20px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .alert-box .alert-icon { font-size: 60px; margin-bottom: 15px; line-height: 1; display: block; color: #10b981; }
        .alert-box h3 { font-size: 24px; font-weight: 700; margin-bottom: 10px; color: #10b981; }
        .alert-box p { color: #4a6a8c; margin-bottom: 22px; font-size: 15px; line-height: 1.5; word-wrap: break-word; }
        .alert-box .btn-popup-ok { background: #F4B400; color: #0B2447; border: none; padding: 12px 40px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3); transition: all 0.25s; min-width: 130px; }
        .alert-box .btn-popup-ok:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.45); background: #e6a800; color: #0B2447; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #0B2447; font-size: 13px; }
        .form-control, .form-select { width: 100%; padding: 12px 14px; border: 2px solid #e8f0fe; border-radius: 8px; font-size: 14px; transition: all 0.3s; background: #fafafa; min-height: 44px; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }
        textarea.form-control { min-height: 90px; resize: vertical; }

        .footer { background: #0B2447; color: white; padding: 60px 0 20px; margin-top: 50px; border-top: 2px solid rgba(77, 166, 217, 0.15); }
        .footer-content { max-width: 1300px; margin: 0 auto; padding: 0 20px; }
        .footer-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 40px; margin-bottom: 40px; }
        .footer-col h4 { font-size: 18px; font-weight: 600; margin-bottom: 20px; color: white; display: flex; align-items: center; gap: 10px; }
        .footer-col h4 i { color: #4DA6D9; flex-shrink: 0; }
        .footer-col p { color: #b3d9ff; line-height: 1.7; margin-bottom: 20px; font-size: 14px; }
        .footer-col ul { list-style: none; }
        .footer-col ul li { margin-bottom: 12px; color: #b3d9ff; display: flex; align-items: flex-start; gap: 10px; font-size: 14px; }
        .footer-col ul li i { width: 20px; color: #4DA6D9; flex-shrink: 0; padding-top: 3px; }
        .footer-col ul li a { color: #b3d9ff; text-decoration: none; word-break: break-word; }
        .footer-col ul li a:hover { color: white; }
        .social-links { display: flex; gap: 15px; }
        .social-links a { width: 40px; height: 40px; background: rgba(255,255,255,0.05); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; text-decoration: none; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.05); }
        .social-links a:hover { background: #4DA6D9; border-color: #7bb8f0; transform: translateY(-3px); }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,0.05); padding-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; color: #b3d9ff; font-size: 14px; }
        .footer-bottom-links { display: flex; gap: 20px; flex-wrap: wrap; }
        .footer-bottom-links a { color: #b3d9ff; text-decoration: none; transition: color 0.2s; cursor: pointer; }
        .footer-bottom-links a:hover { color: white; }
        .footer-map { width: 100%; max-width: 300px; height: 180px; border-radius: 10px; overflow: hidden; margin-bottom: 14px; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .footer-map iframe { width: 100%; height: 100%; border: 0; display: block; }

        .terms-modal { z-index: 30000 !important; }
        .terms-modal-content { max-width: 720px !important; padding: 0 !important; overflow: hidden !important; display: flex !important; flex-direction: column; max-height: 92vh !important; }
        .terms-modal-header { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); color: white; padding: 25px 30px; text-align: center; position: relative; overflow: hidden; flex-shrink: 0; }
        .terms-modal-icon { font-size: 42px; margin-bottom: 8px; position: relative; z-index: 1; color: #F4B400; }
        .terms-modal-header h3 { font-size: 22px; font-weight: 700; margin: 0 0 4px 0; color: white; position: relative; z-index: 1; }
        .terms-modal-header p { font-size: 13px; opacity: 0.9; margin: 0; color: #e0eeff; position: relative; z-index: 1; }
        .terms-modal-close { position: absolute; top: 14px; right: 14px; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.25); width: 38px; height: 38px; border-radius: 50%; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all 0.25s ease; z-index: 5; }
        .terms-modal-close:hover { background: #ef4444; border-color: #ef4444; transform: rotate(90deg); }
        .terms-scroll-container { flex: 1; overflow-y: auto; padding: 25px 30px; background: white; min-height: 0; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
        .terms-section { margin-bottom: 30px; }
        .terms-section:last-child { margin-bottom: 0; }
        .terms-section-title { display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 700; color: #0B2447; padding-bottom: 12px; border-bottom: 2px solid #4DA6D9; margin-bottom: 15px; }
        .terms-section-title i { color: #4DA6D9; font-size: 20px; flex-shrink: 0; }
        .terms-section-body { font-size: 13.5px; line-height: 1.7; color: #334155; white-space: pre-wrap; word-wrap: break-word; }
        .terms-divider { text-align: center; margin: 25px 0; position: relative; }
        .terms-divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, #cbd5e1, transparent); }
        .terms-divider span { position: relative; background: white; padding: 0 15px; color: #94a3b8; font-size: 12px; font-weight: 600; letter-spacing: 1px; }
        .terms-scroll-hint { text-align: center; padding: 12px; background: #fef3c7; color: #92400e; font-size: 12.5px; font-weight: 600; border-top: 1px solid #fde68a; flex-shrink: 0; transition: all 0.3s; }
        .terms-scroll-hint.done { background: #d1fae5; color: #065f46; border-top-color: #a7f3d0; }
        #termsAcceptForm { padding: 15px 25px 20px; border-top: 2px solid #e2e8f0; background: #f8fafc; flex-shrink: 0; display: none; animation: slideUp 0.3s ease; }
        #termsAcceptForm.visible { display: block; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .terms-checkbox-label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; padding: 12px 14px; background: white; border: 2px solid #e2e8f0; border-radius: 10px; margin-bottom: 12px; transition: all 0.2s; font-weight: 500; font-size: 13.5px; color: #1e293b; line-height: 1.5; }
        .terms-checkbox-label:hover { border-color: #4DA6D9; background: #f0f7fb; }
        .terms-checkbox-label input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: #4DA6D9; margin-top: 1px; flex-shrink: 0; }
        .terms-checkbox-text { flex: 1; }
        .terms-accept-btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
        .terms-accept-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45); }
        .terms-accept-btn:disabled { background: #cbd5e1; cursor: not-allowed; box-shadow: none; transform: none; color: #94a3b8; }
        .terms-modal-footer-note { text-align: center; padding: 10px 20px 15px; font-size: 11.5px; color: #94a3b8; background: #f8fafc; margin: 0; flex-shrink: 0; }

        .logout-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; animation: logoutFadeIn 0.2s ease; overscroll-behavior: contain; }
        .logout-modal-overlay.show { display: flex; }
        @keyframes logoutFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .logout-modal { background: white; border-radius: 24px; max-width: 400px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); animation: logoutSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border-top: 6px solid #ef4444; max-height: 90vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        @keyframes logoutSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .logout-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; animation: logoutPulse 2s ease-in-out infinite; }
        @keyframes logoutPulse { 0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); } 50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); } }
        .logout-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .logout-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .logout-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-logout-cancel, .btn-logout-confirm { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.25s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; -webkit-tap-highlight-color: rgba(0,0,0,0.1); touch-action: manipulation; }
        .btn-logout-cancel { background: #e2e8f0; color: #475569; }
        .btn-logout-cancel:hover, .btn-logout-cancel:active { background: #cbd5e1; transform: translateY(-2px); }
        .btn-logout-confirm { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3); }
        .btn-logout-confirm:hover, .btn-logout-confirm:active { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45); color: white; }

        @media (max-width: 1024px) {
            .profile-grid { grid-template-columns: 320px 1fr; gap: 20px; }
            .hero h1 { font-size: 40px; }
            .profile-card { position: static; }
            table { min-width: 800px; }
        }
        @media (max-width: 900px) {
            .profile-grid { grid-template-columns: 1fr; gap: 20px; }
            .profile-card { position: static; }
            .hero { padding: 60px 0; }
            .hero h1 { font-size: 34px; }
            .hero p { font-size: 16px; }
        }
        @media (max-width: 768px) {
            .main-container { padding: 0 15px; margin: 20px auto; }
            .hero { padding: 50px 0; }
            .hero h1 { font-size: 28px; }
            .hero p { font-size: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 18px 12px; border-radius: 12px; }
            .stat-icon { width: 40px; height: 40px; font-size: 16px; margin-bottom: 10px; }
            .stat-number { font-size: 22px; }
            .stat-label { font-size: 11px; }
            .profile-card { padding: 20px 18px; border-radius: 18px; }
            .avatar { width: 100px; height: 100px; font-size: 40px; }
            .profile-name { font-size: 20px; }
            .bookings-section { padding: 20px 15px; border-radius: 18px; }
            .section-tabs { gap: 6px; margin-bottom: 18px; padding-bottom: 12px; }
            .tab { padding: 8px 14px; font-size: 12px; }
            .alert { padding: 12px 15px; font-size: 13px; }
            .alert i { font-size: 16px; }
            table { min-width: 700px; }
            thead th { font-size: 10px; padding: 10px 10px; }
            tbody td { font-size: 12px; padding: 10px 10px; }
            .btn-sm, .btn-rebook-link, .btn-view-booking, .btn-cancel-rebook { font-size: 10px; padding: 5px 10px; min-height: 26px; }
            .modal { padding: 15px; }
            .modal-content { padding: 20px 18px; border-radius: 18px; max-height: 92vh; }
            .modal-header h3 { font-size: 16px; }
            .view-booking-modal .booking-detail-item { flex-direction: column; align-items: flex-start; gap: 4px; padding: 10px 8px; }
            .view-booking-modal .booking-detail-item .value { text-align: left; max-width: 100%; width: 100%; }
            .footer { padding: 40px 0 15px; }
            .footer-grid { gap: 25px; margin-bottom: 25px; }
            .footer-map { max-width: 100%; height: 160px; }
            .footer-bottom { flex-direction: column; text-align: center; }
            .big-rating-input { font-size: 34px; gap: 6px; }
            .prev-rating-banner .prev-stars { font-size: 22px; }
        }
        @media (max-width: 600px) {
            .terms-modal-content { max-width: 96% !important; max-height: 94vh !important; }
            .terms-modal-header { padding: 20px 18px; }
            .terms-modal-header h3 { font-size: 17px; }
            .terms-modal-icon { font-size: 32px; }
            .terms-modal-close { width: 32px; height: 32px; font-size: 14px; top: 10px; right: 10px; }
            .terms-scroll-container { padding: 18px 18px; }
            .terms-section-title { font-size: 15px; }
            .terms-section-body { font-size: 12.5px; }
            .terms-scroll-hint { font-size: 11px; padding: 10px; }
            #termsAcceptForm { padding: 12px 16px 14px; }
            .terms-checkbox-label { font-size: 12px; padding: 10px 12px; }
            .terms-accept-btn { font-size: 13px; padding: 12px; }
            .rebook-policy-popup { padding: 25px 20px; }
            .rebook-policy-popup .popup-icon { font-size: 48px; }
            .rebook-policy-popup h3 { font-size: 19px; }
        }
        @media (max-width: 480px) {
            body { font-size: 13px; }
            .main-container { padding: 0 12px; margin: 15px auto; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 14px 10px; border-radius: 10px; }
            .stat-icon { width: 34px; height: 34px; font-size: 14px; border-radius: 8px; margin-bottom: 8px; }
            .stat-number { font-size: 18px; }
            .stat-label { font-size: 10px; }
            .profile-card { padding: 18px 15px; border-radius: 16px; }
            .avatar { width: 88px; height: 88px; font-size: 36px; border-width: 3px; }
            .profile-name { font-size: 18px; }
            .bookings-section { padding: 15px 12px; border-radius: 16px; }
            .section-tabs { gap: 4px; }
            .tab { padding: 7px 10px; font-size: 11px; }
            .alert { padding: 10px 12px; font-size: 12px; border-radius: 8px; }
            .alert i { font-size: 14px; }
            table { min-width: 600px; }
            thead th { font-size: 9px; padding: 8px 8px; }
            tbody td { font-size: 11px; padding: 8px 8px; }
            .action-cell { min-width: 100px; }
            .btn-sm, .btn-rebook-link, .btn-view-booking, .btn-cancel-rebook { font-size: 9px; padding: 4px 8px; min-height: 24px; }
            .modal { padding: 10px; }
            .modal-content { padding: 16px 14px; border-radius: 16px; max-height: 94vh; }
            .modal-header { padding-bottom: 12px; margin-bottom: 15px; }
            .modal-header h3 { font-size: 15px; }
            .modal-header .close { font-size: 24px; }
            .form-control, .form-select { padding: 10px 12px; font-size: 13px; min-height: 40px; }
            .big-rating-input { font-size: 28px; gap: 4px; }
            .prev-rating-banner .prev-stars { font-size: 20px; }
            .update-review-btn { font-size: 13px; padding: 12px; }
            .btn-cancel-review { font-size: 12px; padding: 12px; }
            .footer { padding: 30px 0 12px; }
            .footer-content { padding: 0 12px; }
            .footer-grid { gap: 20px; }
            .footer-col h4 { font-size: 15px; margin-bottom: 12px; }
            .footer-col p, .footer-col ul li { font-size: 12px; }
            .footer-map { height: 140px; }
            .footer-bottom { font-size: 11px; padding-top: 15px; gap: 10px; }
            .footer-bottom-links { gap: 12px; }
            .terms-modal-header { padding: 16px 14px; }
            .terms-modal-header h3 { font-size: 15px; }
            .terms-modal-icon { font-size: 28px; }
            .terms-modal-close { width: 30px; height: 30px; font-size: 12px; top: 8px; right: 8px; }
            .terms-scroll-container { padding: 15px 15px; }
            .terms-section-title { font-size: 14px; }
            .terms-section-body { font-size: 12px; line-height: 1.6; }
            .terms-scroll-hint { font-size: 10.5px; padding: 8px 10px; }
            #termsAcceptForm { padding: 10px 14px 12px; }
            .terms-checkbox-label { font-size: 11.5px; padding: 9px 11px; gap: 8px; }
            .terms-checkbox-label input[type="checkbox"] { width: 18px; height: 18px; }
            .terms-accept-btn { font-size: 12.5px; padding: 11px; }
            .terms-modal-footer-note { font-size: 10.5px; padding: 8px 14px 12px; }
        }
        @media (max-width: 360px) {
            .hero h1 { font-size: 20px; }
            .hero p { font-size: 12px; }
            .stats-grid { grid-template-columns: 1fr; }
            .section-tabs { gap: 3px; }
            .tab { padding: 6px 8px; font-size: 10px; }
            .modal-content { padding: 14px 12px; }
            .modal-header h3 { font-size: 14px; }
        }
        @media (hover: none) and (pointer: coarse) {
            .tab, .btn-sm, .btn-view-booking, .btn-rebook-link, .btn-cancel-rebook { min-height: 36px; }
            .btn-sm, .btn-view-booking, .btn-rebook-link, .btn-cancel-rebook { padding: 7px 12px; }
            .rating-stars i, .rating-input i, .big-rating-input i { padding: 6px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; scroll-behavior: auto !important; }
        }
        @supports (padding: max(0px)) {
            .header-content, .main-container, .footer-content { padding-left: max(20px, env(safe-area-inset-left)); padding-right: max(20px, env(safe-area-inset-right)); }
            .footer { padding-bottom: max(20px, env(safe-area-inset-bottom)); }
        }
    </style>
</head>
<body>

<!-- SIDEBAR OVERLAY (mobile only) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR NAVIGATION (mobile only) -->
<div class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <div class="sidebar-header-top">
            <a href="index.php" class="logo">
                <?php if($sidebar_logo_exists): ?>
                    <img src="<?php echo htmlspecialchars($sidebar_logo); ?>?<?php echo time(); ?>" alt="Logo">
                <?php else: ?>
                    <div class="logo-icon"><i class="fas fa-umbrella-beach"></i></div>
                <?php endif; ?>
                <div class="logo-text">
                    <span class="main">Transient House</span>
                    <span class="sub">& Tours</span>
                </div>
            </a>
            <button class="sidebar-close-btn" onclick="toggleSidebar()" aria-label="Close menu">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <ul class="nav-menu">
        <li class="nav-item"><a href="index.php" class="nav-link"><i class="fas fa-home"></i><span>Home</span></a></li>
        <li class="nav-item"><a href="houses.php" class="nav-link"><i class="fas fa-home"></i><span>Houses</span></a></li>
        <li class="nav-item"><a href="tours.php" class="nav-link"><i class="fas fa-umbrella-beach"></i><span>Tours</span></a></li>
        <li class="nav-item"><a href="activities.php" class="nav-link"><i class="fas fa-water"></i><span>Activities</span></a></li>
        <li class="nav-item"><a href="food.php" class="nav-link"><i class="fas fa-utensils"></i><span>Food</span></a></li>
        <li class="nav-item"><a href="packages.php" class="nav-link"><i class="fas fa-box-open"></i><span>My Package</span></a></li>
        <li class="nav-item"><a href="profile.php?tab=packages" class="nav-link active-nav"><i class="fas fa-user"></i><span>Profile</span></a></li>

        <?php if(isset($_SESSION['user_id'])): ?>
            <?php if(isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'staff')): ?>
                <li class="nav-item"><a href="admin-dashboard.php" class="nav-link"><i class="fas fa-cog"></i><span>Dashboard</span></a></li>
            <?php else: ?>
                <li class="nav-item">
                    <a href="#" onclick="openOverallFeedbackModal(); return false;" class="nav-link"
                       style="background: #F4B400; color: #0B2447; border-radius: 8px; margin: 0 20px; justify-content: center; font-weight: 700;">
                        <i class="fas fa-star"></i>
                        <span><?php echo $user_has_feedback ? 'Edit Review' : 'Rate Us'; ?></span>
                    </a>
                </li>
            <?php endif; ?>
            <div class="nav-divider"></div>
            <li class="nav-item">
                <a href="#" class="nav-link" onclick="openLogoutModal(event); return false;" style="color: #ef4444;">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>
</div>

<!-- HEADER -->
<div class="header">
    <div class="header-content">
        <!-- ✅ HAMBURGER IS FIRST → LOGO SITS RIGHT NEXT TO IT -->
        <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle Menu">
            <i class="fas fa-bars"></i>
        </button>

        <a href="index.php" class="logo-wrapper">
            <?php 
            $logo_actual_path = $content['site_settings']['logo_path'] ?? 'uploads/logos/logo.png';
            $logo_exists = !empty($logo_actual_path) && file_exists($logo_actual_path) && !is_dir($logo_actual_path);
            if(!$logo_exists && file_exists('uploads/logos/logo.png')) {
                $logo_exists = true; $logo_actual_path = 'uploads/logos/logo.png';
            }
            if($logo_exists): ?>
                <img src="<?php echo htmlspecialchars($logo_actual_path); ?>?<?php echo time(); ?>" alt="Logo" class="logo-image">
            <?php else: ?>
                <div class="logo-image-placeholder"><i class="fas fa-home"></i></div>
            <?php endif; ?>
            <div class="brand-text">
                <span class="brand-name">Transient House & Tours</span>
                <span class="brand-tagline">Your Home Away From Home</span>
            </div>
        </a>

        <div class="desktop-nav">
            <a href="index.php"><i class="fas fa-home"></i> Home</a>
            <a href="houses.php"><i class="fas fa-home"></i> Houses</a>
            <a href="tours.php"><i class="fas fa-umbrella-beach"></i> Tours</a>
            <a href="activities.php"><i class="fas fa-water"></i> Activities</a>
            <a href="food.php"><i class="fas fa-utensils"></i> Food</a>
            <a href="packages.php"><i class="fas fa-box-open"></i> My Package</a>
            <a href="profile.php?tab=packages" class="active-nav"><i class="fas fa-user"></i> Profile</a>

            <?php if(isset($_SESSION['user_id'])): ?>
                <?php if(isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'staff')): ?>
                    <a href="admin-dashboard.php"><i class="fas fa-cog"></i> Dashboard</a>
                <?php else: ?>
                    <?php if($user_has_feedback): ?>
                        <a href="#" onclick="openOverallFeedbackModal(); return false;" class="btn-rate">
                            <i class="fas fa-star"></i> <span class="rate-text">Edit</span>
                        </a>
                    <?php else: ?>
                        <a href="#" onclick="openOverallFeedbackModal(); return false;" class="btn-rate">
                            <i class="fas fa-star"></i> <span class="rate-text">Rate Us</span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="#" onclick="openLogoutModal(event); return false;" class="btn-logout"><i class="fas fa-sign-out-alt"></i> <span class="logout-text">Logout</span></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- HERO -->
<div class="hero">
    <div class="hero-content">
        <h1><i class="fas fa-user-circle"></i> Welcome back, <?php echo $guest ? htmlspecialchars($guest['full_name']) : htmlspecialchars($user['username']); ?>!</h1>
        <p>Manage your bookings, view payment options, and track your reservations</p>
    </div>
</div>

<!-- MAIN CONTAINER -->
<div class="main-container">
    
    <!-- ALERTS -->
    <?php if(isset($_GET['cancelled'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>✅ Booking cancelled successfully. Since it was unpaid, no fees were charged.</span></div>
    <?php endif; ?>
    <?php if(isset($_GET['proof_uploaded'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>✅ Payment proof uploaded! Please wait for admin confirmation.</span></div>
    <?php endif; ?>
    <?php if(isset($_GET['rebooked'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>✅ Rebook submitted! Waiting for admin confirmation.</span></div>
    <?php endif; ?>
    <?php if(isset($_GET['rebook_cancelled'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>✅ Pending rebook cancelled! Your original booking has been restored.</span></div>
    <?php endif; ?>
    <?php if(isset($_GET['confirmed'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>✅ Payment confirmed!</span></div>
    <?php endif; ?>
    <?php if(isset($_GET['completed'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>✅ Booking marked as completed!</span></div>
    <?php endif; ?>
    <?php if(isset($_GET['feedback'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>Thank you for your feedback!</span></div>
    <?php endif; ?>
    <?php if(isset($_GET['feedback_updated'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span>Your feedback has been updated!</span></div>
    <?php endif; ?>
    <?php if(isset($success) && !isset($_GET['feedback_success'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span><?php echo $success; ?></span></div>
    <?php endif; ?>
    <?php if(isset($error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <span><?php echo $error; ?></span></div>
    <?php endif; ?>
    <?php if(isset($upload_error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <span><?php echo $upload_error; ?></span></div>
    <?php endif; ?>
    
    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-home"></i></div>
            <div class="stat-number"><?php echo count($house_bookings); ?></div>
            <div class="stat-label">House Bookings</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-umbrella-beach"></i></div>
            <div class="stat-number"><?php echo count($tour_bookings); ?></div>
            <div class="stat-label">Tour Bookings</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-utensils"></i></div>
            <div class="stat-number"><?php echo count($food_bookings); ?></div>
            <div class="stat-label">Food Bookings</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-box-open"></i></div>
            <div class="stat-number"><?php echo count($package_bookings); ?></div>
            <div class="stat-label">Package Bookings</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-number"><?php echo $pending_count; ?></div>
            <div class="stat-label">Pending</div>
        </div>
    </div>
    
    <!-- PROFILE GRID -->
    <div class="profile-grid">
        
        <!-- PROFILE CARD -->
        <div class="profile-card">
            <div class="profile-header">
                <div class="avatar">
                    <?php if ($profile_photo): ?>
                        <img src="<?php echo $profile_photo; ?>?<?php echo time(); ?>" alt="Profile Photo">
                    <?php else: ?>
                        <span class="default-icon"><i class="fas fa-user"></i></span>
                    <?php endif; ?>
                </div>
                <div class="profile-name"><?php echo $guest ? htmlspecialchars($guest['full_name']) : htmlspecialchars($user['username']); ?></div>
                <div class="profile-role"><?php echo ucfirst($user['role']); ?></div>
            </div>
            <div class="profile-info">
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-user"></i></div>
                    <div style="min-width:0;flex:1;">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['username']); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-envelope"></i></div>
                    <div style="min-width:0;flex:1;">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                    </div>
                </div>
                <?php if($guest): ?>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-phone"></i></div>
                    <div style="min-width:0;flex:1;">
                        <div class="info-label">Contact Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($guest['contact_number'] ?? 'Not provided'); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div style="min-width:0;flex:1;">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?php echo htmlspecialchars($guest['address'] ?? 'Not provided'); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-id-card"></i></div>
                    <div style="min-width:0;flex:1;">
                        <div class="info-label">ID Type / Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($guest['id_type'] ?? 'N/A'); ?>: <?php echo htmlspecialchars($guest['id_number'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon"><i class="fas fa-phone-alt"></i></div>
                    <div style="min-width:0;flex:1;">
                        <div class="info-label">Emergency Contact</div>
                        <div class="info-value"><?php echo htmlspecialchars($guest['emergency_contact'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($guest['emergency_number'] ?? 'N/A'); ?>)</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ID PHOTO -->
            <div class="id-photo-display">
                <div class="id-photo-label"><i class="fas fa-id-card"></i> ID Photo</div>
                <div class="id-photo-container">
                    <?php if($id_photo): ?>
                        <img src="<?php echo $id_photo; ?>?<?php echo time(); ?>" alt="ID Photo">
                        <div class="id-details">
                            <div><strong>Type:</strong> <?php echo htmlspecialchars($guest['id_type'] ?? 'N/A'); ?></div>
                            <div><strong>Number:</strong> <?php echo htmlspecialchars($guest['id_number'] ?? 'N/A'); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="no-photo"><i class="fas fa-id-card" style="font-size:30px;display:block;color:#cbd5e1;"></i><span>No ID photo uploaded</span></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- HEALTH INFO -->
            <?php if($guest): ?>
            <div class="health-info-section">
                <h5><i class="fas fa-heartbeat"></i> Health Information</h5>
                <?php if($guest['has_asthma']): ?>
                    <span class="health-tag asthma"><i class="fas fa-lungs"></i> Asthma</span>
                    <?php if($guest['asthma_severity']): ?><span style="font-size:11px;color:#64748b;">(<?php echo ucfirst($guest['asthma_severity']); ?>)</span><?php endif; ?>
                <?php endif; ?>
                <?php if($guest['has_allergies']): ?>
                    <span class="health-tag allergy"><i class="fas fa-allergies"></i> Allergies</span>
                    <?php $allergy_types = decodeJson($guest['allergy_types']); if(!empty($allergy_types)): ?>
                        <span style="font-size:11px;color:#64748b;">(<?php echo implode(', ', $allergy_types); ?>)</span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if($guest['has_medical_condition']): ?><span class="health-tag medical"><i class="fas fa-notes-medical"></i> Medical Condition</span><?php endif; ?>
                <?php if($guest['has_dietary']): ?><span class="health-tag dietary"><i class="fas fa-utensils"></i> Dietary Restriction</span><?php endif; ?>
                <?php if($guest['has_accessibility']): ?><span class="health-tag access"><i class="fas fa-wheelchair"></i> Accessibility Need</span><?php endif; ?>
                <?php if(!$guest['has_asthma'] && !$guest['has_allergies'] && !$guest['has_medical_condition'] && !$guest['has_dietary'] && !$guest['has_accessibility']): ?>
                    <p style="color:#94a3b8;font-size:13px;margin:5px 0;">No health conditions reported</p>
                <?php endif; ?>
                <?php if($guest['emergency_medication']): ?>
                    <div style="margin-top:8px;font-size:13px;color:#475569;word-break:break-word;">
                        <i class="fas fa-pills" style="color:#4DA6D9;"></i> Emergency Medication: <?php echo htmlspecialchars($guest['emergency_medication']); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <a href="edit-profile.php" class="btn-edit"><i class="fas fa-edit"></i> Edit Profile</a>
        </div>
        
        <!-- BOOKINGS SECTION -->
        <div class="bookings-section">
            <div class="section-tabs">
                <div class="tab <?php echo $default_tab === 'houses' ? 'active' : ''; ?>" onclick="showTab('houses')">House Bookings</div>
                <div class="tab <?php echo $default_tab === 'tours' ? 'active' : ''; ?>" onclick="showTab('tours')">Tour Bookings</div>
                <div class="tab <?php echo $default_tab === 'food' ? 'active' : ''; ?>" onclick="showTab('food')">Food Bookings</div>
                <div class="tab <?php echo $default_tab === 'packages' ? 'active' : ''; ?>" onclick="showTab('packages')">Package Bookings</div>
                <div class="tab <?php echo $default_tab === 'rebooks' ? 'active' : ''; ?>" onclick="showTab('rebooks')">Rebooks</div>
                <div class="tab <?php echo $default_tab === 'history' ? 'active' : ''; ?>" onclick="showTab('history')">History</div>
            </div>
            
            <!-- HOUSE BOOKINGS TAB -->
            <div id="houses-tab" class="tab-content <?php echo $default_tab === 'houses' ? 'active' : ''; ?>" style="display:<?php echo $default_tab === 'houses' ? 'block' : 'none'; ?>;">
                <?php 
                $active_house = array_filter($house_bookings, function($b) {
                    return $b['booking_status'] != 'cancelled' && $b['booking_status'] != 'completed';
                });
                ?>
                <?php if(empty($active_house)): ?>
                    <p style="text-align:center;padding:40px 15px;color:#94a3b8;">No active house bookings. <a href="houses.php" style="color:#4DA6D9;font-weight:600;">Browse Houses</a></p>
                <?php else: ?>
                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref #</th><th>House</th><th>Booked On</th><th>Check In</th>
                                    <th>Check Out</th><th>Total</th><th>Payment</th><th>Status</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($active_house as $booking): 
                                    $rb = computeRebookInfo($pdo, $booking, $_SESSION['user_id']);
                                    $pending_rebook = !empty($booking['rebooked_at']) 
                                                   && empty($booking['rebook_confirmed_at'])
                                                   && $booking['payment_status'] == 'paid'
                                                   && !in_array($booking['booking_status'] ?? '', ['cancelled', 'completed']);
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['house_name']); ?></td>
                                    <td><?php echo formatDateTimeDisplay($booking['created_at']); ?></td>
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
                                    <td>₱<?php echo number_format($booking['total_amount']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo getBookingStatusLabel(
                                            $booking['booking_status'],
                                            $booking['payment_status'],
                                            $booking['rebook_count'] ?? 0,
                                            $booking['rebooked_at'] ?? null,
                                            $booking['rebook_confirmed_at'] ?? null
                                        ); ?>
                                        <?php if($pending_rebook): ?>
                                            <div class="rebook-pending-badge" style="display:block; margin-top:5px;">
                                                <i class="fas fa-hourglass-half"></i> Waiting for admin confirmation
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("house", <?php echo json_encode($booking); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            
                                            <?php if($pending_rebook): ?>
                                                <div class="action-row">
                                                    <button class="btn-cancel-rebook" onclick="cancelRebook(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-undo"></i> Cancel Rebook
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if($booking['payment_status'] == 'pending'): ?>
                                                <div class="action-row">
                                                    <?php if(!$booking['payment_proof']): ?>
                                                        <button class="btn-sm btn-pay" onclick="openPaymentModal('house', <?php echo $booking['id']; ?>, <?php echo $booking['total_amount']; ?>, '<?php echo $booking['reference_number']; ?>')">
                                                            <i class="fas fa-credit-card"></i> Pay
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge badge-info">Proof Submitted</span>
                                                    <?php endif; ?>
                                                    <button class="btn-sm btn-cancel-booking" onclick="openCancelBookingModal('house', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-times-circle"></i> Cancel
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if($booking['booking_status'] == 'confirmed' && $booking['payment_status'] == 'paid' && !$pending_rebook): ?>
                                                <?php if($rb['is_eligible']): ?>
                                                    <div class="action-row">
                                                        <button class="btn-rebook-link" onclick="showRebookPolicyPopup(
                                                            '<?php echo $booking['id']; ?>',
                                                            '<?php echo htmlspecialchars($booking['house_name']); ?>',
                                                            '<?php echo htmlspecialchars($booking['reference_number']); ?>',
                                                            <?php echo $rb['remaining_rebooks']; ?>,
                                                            <?php echo $rb['days_remaining']; ?>
                                                        )">
                                                            <i class="fas fa-redo"></i> Rebook
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="action-row">
                                                        <button type="button" class="btn-rebook-link disabled" disabled
                                                                title="<?php echo htmlspecialchars($rb['rebook_message'] ?: 'Not eligible for rebook'); ?>">
                                                            <?php if($rb['is_maxed']): ?>
                                                                <i class="fas fa-ban"></i> Max Rebooks Used
                                                            <?php elseif($rb['is_expired']): ?>
                                                                <i class="fas fa-clock"></i> Rebook Expired
                                                            <?php else: ?>
                                                                <i class="fas fa-lock"></i> Unavailable to Rebook
                                                            <?php endif; ?>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if($is_admin && $booking['booking_status'] == 'confirmed' && $booking['payment_status'] == 'paid'): ?>
                                                <div class="action-row" style="margin-top:4px;">
                                                    <a href="?complete_booking=<?php echo $booking['id']; ?>&booking_type=house" class="btn-sm btn-complete" onclick="return confirm('Mark as completed?')">
                                                        <i class="fas fa-check-double"></i> Complete
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="booking-cards-mobile">
                        <?php foreach($active_house as $booking): 
                            $rb = computeRebookInfo($pdo, $booking, $_SESSION['user_id']);
                            $pending_rebook = !empty($booking['rebooked_at']) 
                                           && empty($booking['rebook_confirmed_at'])
                                           && $booking['payment_status'] == 'paid'
                                           && !in_array($booking['booking_status'] ?? '', ['cancelled', 'completed']);
                        ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-hashtag" style="color:#4DA6D9; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($booking['reference_number']); ?>
                                </div>
                                <div class="card-badges">
                                    <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($booking['payment_status']); ?>
                                    </span>
                                    <?php if($pending_rebook): ?>
                                        <span class="badge badge-warning"><i class="fas fa-clock"></i> Rebook</span>
                                    <?php endif; ?>
                                </div>
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
                                <?php if(!empty($booking['payment_proof'])): ?>
                                    <img class="card-proof-thumb"
                                         src="uploads/payments/<?php echo htmlspecialchars($booking['payment_proof']); ?>?t=<?php echo time(); ?>"
                                         alt="Proof"
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
                                <button type="button" class="btn-card-action btn-more"
                                        onclick='viewBooking("house", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>

                                <?php if($pending_rebook): ?>
                                    <button type="button" class="btn-card-action btn-cancel-rebook-mobile"
                                            onclick="cancelRebook(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-undo"></i> Cancel Rebook
                                    </button>
                                <?php elseif($booking['payment_status'] == 'pending'): ?>
                                    <?php if(!$booking['payment_proof']): ?>
                                        <button type="button" class="btn-card-action btn-pay-mobile"
                                                onclick="openPaymentModal('house', <?php echo $booking['id']; ?>, <?php echo $booking['total_amount']; ?>, '<?php echo $booking['reference_number']; ?>')">
                                            <i class="fas fa-credit-card"></i> Pay
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-card-action btn-cancel-booking-mobile"
                                            onclick="openCancelBookingModal('house', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-times-circle"></i> Cancel
                                    </button>
                                <?php elseif($booking['booking_status'] == 'confirmed' && $booking['payment_status'] == 'paid' && $rb['is_eligible']): ?>
                                    <button type="button" class="btn-card-action btn-rebook-confirm-mobile"
                                            onclick="showRebookPolicyPopup(
                                                '<?php echo $booking['id']; ?>',
                                                '<?php echo htmlspecialchars($booking['house_name']); ?>',
                                                '<?php echo htmlspecialchars($booking['reference_number']); ?>',
                                                <?php echo $rb['remaining_rebooks']; ?>,
                                                <?php echo $rb['days_remaining']; ?>
                                            )">
                                        <i class="fas fa-redo"></i> Rebook
                                    </button>
                                <?php elseif($booking['booking_status'] == 'confirmed' && $booking['payment_status'] == 'paid' && !$rb['is_eligible']): ?>
                                    <button type="button" class="btn-card-action" disabled
                                            style="background:#cbd5e1; color:#94a3b8; cursor:not-allowed;"
                                            title="<?php echo htmlspecialchars($rb['rebook_message'] ?: 'Not eligible for rebook'); ?>">
                                        <?php if($rb['is_maxed']): ?>
                                            <i class="fas fa-ban"></i> Max Rebooks
                                        <?php elseif($rb['is_expired']): ?>
                                            <i class="fas fa-clock"></i> Expired
                                        <?php else: ?>
                                            <i class="fas fa-lock"></i> Unavailable
                                        <?php endif; ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- TOUR BOOKINGS TAB -->
            <div id="tours-tab" class="tab-content <?php echo $default_tab === 'tours' ? 'active' : ''; ?>" style="display:<?php echo $default_tab === 'tours' ? 'block' : 'none'; ?>;">
                <?php 
                $active_tour = array_filter($tour_bookings, function($b) {
                    return $b['booking_status'] != 'cancelled' && $b['booking_status'] != 'completed';
                });
                ?>
                <?php if(empty($active_tour)): ?>
                    <p style="text-align:center;padding:40px 15px;color:#94a3b8;">No active tour bookings. <a href="tours.php" style="color:#4DA6D9;font-weight:600;">Browse Tours</a></p>
                <?php else: ?>
                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref #</th><th>Tour</th><th>Guest Name</th><th>Contact #</th>
                                    <th>Date</th><th>Time</th><th>Pax</th><th>Total</th>
                                    <th>Payment</th><th>Status</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($active_tour as $booking): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['tour_name']); ?></td>
                                    <td>
                                        <?php if(!empty($booking['guest_name'])): ?>
                                            <span class="badge badge-info" style="background:#e0f2fe; color:#0369a1;">
                                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($booking['guest_name']); ?>
                                            </span>
                                        <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($booking['contact_number'])): ?>
                                            <a href="tel:<?php echo htmlspecialchars($booking['contact_number']); ?>" style="color:#0B2447; font-weight:600; text-decoration:none; font-size:12px;">
                                                <i class="fas fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($booking['contact_number']); ?>
                                            </a>
                                        <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                    </td>
                                    <td><?php echo formatDateDisplay($booking['booking_date']); ?></td>
                                    <td>
                                        <?php if(!empty($booking['preferred_time'])): ?>
                                            <span class="badge badge-info"><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($booking['preferred_time'])); ?></span>
                                        <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                    </td>
                                    <td><?php echo $booking['number_of_guests']; ?></td>
                                    <td>₱<?php echo number_format($booking['total_amount']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getBookingStatusLabel($booking['booking_status'], $booking['payment_status']); ?></td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("tour", <?php echo json_encode($booking); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <div class="action-row">
                                                <?php if($booking['payment_status'] == 'pending'): ?>
                                                    <?php if(!$booking['payment_proof']): ?>
                                                        <button class="btn-sm btn-pay" onclick="openPaymentModal('tour', <?php echo $booking['id']; ?>, <?php echo $booking['total_amount']; ?>, '<?php echo $booking['reference_number']; ?>')">
                                                            <i class="fas fa-credit-card"></i> Pay
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge badge-info">Proof Submitted</span>
                                                    <?php endif; ?>
                                                    <button class="btn-sm btn-cancel-booking" onclick="openCancelBookingModal('tour', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-times-circle"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($is_admin && $booking['booking_status'] == 'confirmed' && $booking['payment_status'] == 'paid'): ?>
                                                <div class="action-row" style="margin-top:4px;">
                                                    <a href="?complete_booking=<?php echo $booking['id']; ?>&booking_type=tour" class="btn-sm btn-complete" onclick="return confirm('Mark as completed?')">
                                                        <i class="fas fa-check-double"></i> Complete
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="booking-cards-mobile">
                        <?php foreach($active_tour as $booking): ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-hashtag" style="color:#4DA6D9; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($booking['reference_number']); ?>
                                </div>
                                <div class="card-badges">
                                    <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($booking['payment_status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-umbrella-beach"></i>
                                <span class="card-label">Tour</span>
                                <span class="card-value"><?php echo htmlspecialchars($booking['tour_name']); ?></span>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-calendar-alt"></i>
                                <span class="card-label">Date</span>
                                <span class="card-value"><?php echo formatDateDisplay($booking['booking_date']); ?></span>
                            </div>

                            <div class="card-proof">
                                <?php if(!empty($booking['payment_proof'])): ?>
                                    <img class="card-proof-thumb"
                                         src="uploads/payments/<?php echo htmlspecialchars($booking['payment_proof']); ?>?t=<?php echo time(); ?>"
                                         alt="Proof"
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
                                <button type="button" class="btn-card-action btn-more"
                                        onclick='viewBooking("tour", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>

                                <?php if($booking['payment_status'] == 'pending'): ?>
                                    <?php if(!$booking['payment_proof']): ?>
                                        <button type="button" class="btn-card-action btn-pay-mobile"
                                                onclick="openPaymentModal('tour', <?php echo $booking['id']; ?>, <?php echo $booking['total_amount']; ?>, '<?php echo $booking['reference_number']; ?>')">
                                            <i class="fas fa-credit-card"></i> Pay
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-card-action btn-cancel-booking-mobile"
                                            onclick="openCancelBookingModal('tour', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-times-circle"></i> Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- FOOD BOOKINGS TAB -->
            <div id="food-tab" class="tab-content <?php echo $default_tab === 'food' ? 'active' : ''; ?>" style="display:<?php echo $default_tab === 'food' ? 'block' : 'none'; ?>;">
                <?php 
                $active_food = array_filter($food_bookings, function($b) {
                    return $b['booking_status'] != 'cancelled' && $b['booking_status'] != 'completed';
                });
                ?>
                <?php if(empty($active_food)): ?>
                    <p style="text-align:center;padding:40px 15px;color:#94a3b8;">No active food reservations. <a href="food.php" style="color:#4DA6D9;font-weight:600;">View Food Packages</a></p>
                <?php else: ?>
                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref #</th><th>Food Package</th><th>Guest Name</th><th>Date & Time</th>
                                    <th>Persons</th><th>Qty</th><th>Total</th><th>Payment</th><th>Status</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($active_food as $booking): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($booking['food_name']); ?>
                                        <?php if(!empty($booking['size_variant'])): ?>
                                            <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                                <i class="fas fa-arrows-alt-h"></i> <?php echo htmlspecialchars($booking['size_variant']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($booking['guest_name'])): ?>
                                            <span class="badge badge-info" style="background:#e0f2fe; color:#0369a1;">
                                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($booking['guest_name']); ?>
                                            </span>
                                        <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($booking['preferred_date'])): ?>
                                            <div style="font-weight:600; color:#0B2447; font-size:12px;">
                                                <i class="fas fa-calendar-alt" style="color:#4DA6D9;"></i> 
                                                <?php echo date('M d, Y', strtotime($booking['preferred_date'])); ?>
                                            </div>
                                            <?php if(!empty($booking['preferred_time'])): ?>
                                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                                    <i class="fas fa-clock" style="color:#4DA6D9;"></i> 
                                                    <?php echo date('h:i A', strtotime($booking['preferred_time'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($booking['number_of_persons'])): ?>
                                            <span class="badge badge-info"><i class="fas fa-users"></i> <?php echo $booking['number_of_persons']; ?></span>
                                        <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
                                    </td>
                                    <td><?php echo $booking['quantity']; ?></td>
                                    <td>₱<?php echo number_format($booking['total_amount']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getBookingStatusLabel($booking['booking_status'], $booking['payment_status']); ?></td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("food", <?php echo json_encode($booking); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <div class="action-row">
                                                <?php if($booking['payment_status'] == 'pending'): ?>
                                                    <?php if(!$booking['payment_proof']): ?>
                                                        <button class="btn-sm btn-pay" onclick="openPaymentModal('food', <?php echo $booking['id']; ?>, <?php echo $booking['total_amount']; ?>, '<?php echo $booking['reference_number']; ?>')">
                                                            <i class="fas fa-credit-card"></i> Pay
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge badge-info">Proof Submitted</span>
                                                    <?php endif; ?>
                                                    <button class="btn-sm btn-cancel-booking" onclick="openCancelBookingModal('food', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-times-circle"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($is_admin && $booking['booking_status'] == 'confirmed' && $booking['payment_status'] == 'paid'): ?>
                                                <div class="action-row" style="margin-top:4px;">
                                                    <a href="?complete_booking=<?php echo $booking['id']; ?>&booking_type=food" class="btn-sm btn-complete" onclick="return confirm('Mark as completed?')">
                                                        <i class="fas fa-check-double"></i> Complete
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="booking-cards-mobile">
                        <?php foreach($active_food as $booking): ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-hashtag" style="color:#4DA6D9; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($booking['reference_number']); ?>
                                </div>
                                <div class="card-badges">
                                    <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($booking['payment_status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-utensils"></i>
                                <span class="card-label">Food</span>
                                <span class="card-value"><?php echo htmlspecialchars($booking['food_name']); ?></span>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-money-bill"></i>
                                <span class="card-label">Total</span>
                                <span class="card-value">₱<?php echo number_format($booking['total_amount']); ?></span>
                            </div>

                            <div class="card-proof">
                                <?php if(!empty($booking['payment_proof'])): ?>
                                    <img class="card-proof-thumb"
                                         src="uploads/payments/<?php echo htmlspecialchars($booking['payment_proof']); ?>?t=<?php echo time(); ?>"
                                         alt="Proof"
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
                                <button type="button" class="btn-card-action btn-more"
                                        onclick='viewBooking("food", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>

                                <?php if($booking['payment_status'] == 'pending'): ?>
                                    <?php if(!$booking['payment_proof']): ?>
                                        <button type="button" class="btn-card-action btn-pay-mobile"
                                                onclick="openPaymentModal('food', <?php echo $booking['id']; ?>, <?php echo $booking['total_amount']; ?>, '<?php echo $booking['reference_number']; ?>')">
                                            <i class="fas fa-credit-card"></i> Pay
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-card-action btn-cancel-booking-mobile"
                                            onclick="openCancelBookingModal('food', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-times-circle"></i> Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ✅ PACKAGE BOOKINGS TAB -->
            <div id="packages-tab" class="tab-content <?php echo $default_tab === 'packages' ? 'active' : ''; ?>" style="display:<?php echo $default_tab === 'packages' ? 'block' : 'none'; ?>;">
                <?php 
                $active_packages = array_filter($package_bookings, function($b) {
                    return $b['booking_status'] != 'cancelled' && $b['booking_status'] != 'completed';
                });
                ?>
                <?php if(empty($active_packages)): ?>
                    <p style="text-align:center;padding:40px 15px;color:#94a3b8;">No active package bookings. <a href="packages.php" style="color:#4DA6D9;font-weight:600;">Build a Package</a></p>
                <?php else: ?>
                    <!-- Desktop Table -->
                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref #</th><th>Items</th><th>Booked On</th>
                                    <th>Grand Total</th><th>Payment</th><th>Status</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($active_packages as $pkg): 
                                    $items = [];
                                    if (!empty($pkg['house_name'])) $items[] = '<i class="fas fa-home"></i> ' . htmlspecialchars($pkg['house_name']);
                                    if (!empty($pkg['tour_name'])) $items[] = '<i class="fas fa-umbrella-beach"></i> ' . htmlspecialchars($pkg['tour_name']);
                                    if (!empty($pkg['food_name'])) $items[] = '<i class="fas fa-utensils"></i> ' . htmlspecialchars($pkg['food_name']);
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($pkg['reference_number']); ?></strong></td>
                                    <td>
                                        <?php if(empty($items)): ?>
                                            <span style="color:#cbd5e1;">—</span>
                                        <?php else: ?>
                                            <?php echo implode('<br>', $items); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatDateTimeDisplay($pkg['created_at']); ?></td>
                                    <td><strong style="color:#10b981;">₱<?php echo number_format($pkg['grand_total'], 2); ?></strong></td>
                                    <td>
                                        <span class="badge badge-<?php echo $pkg['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                            <?php echo ucfirst($pkg['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getBookingStatusLabel($pkg['booking_status'], $pkg['payment_status']); ?></td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("package", <?php echo json_encode($pkg); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <?php if($pkg['payment_status'] == 'pending'): ?>
                                                <div class="action-row">
                                                    <?php if(empty($pkg['payment_proof'])): ?>
                                                        <button class="btn-sm btn-pay" onclick="openPaymentModal('package', <?php echo $pkg['id']; ?>, <?php echo $pkg['grand_total']; ?>, '<?php echo $pkg['reference_number']; ?>')">
                                                            <i class="fas fa-credit-card"></i> Pay
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge badge-info">Proof Submitted</span>
                                                    <?php endif; ?>
                                                    <button class="btn-sm btn-cancel-booking" onclick="openCancelBookingModal('package', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>')">
                                                        <i class="fas fa-times-circle"></i> Cancel
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                            <?php if($is_admin && $pkg['booking_status'] == 'confirmed' && $pkg['payment_status'] == 'paid'): ?>
                                                <div class="action-row" style="margin-top:4px;">
                                                    <a href="?complete_booking=<?php echo $pkg['id']; ?>&booking_type=package" class="btn-sm btn-complete" onclick="return confirm('Mark as completed?')">
                                                        <i class="fas fa-check-double"></i> Complete
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Cards -->
                    <div class="booking-cards-mobile">
                        <?php foreach($active_packages as $pkg): 
                            $itemsMobile = [];
                            if (!empty($pkg['house_name'])) {
                                $line = '🏠 ' . htmlspecialchars($pkg['house_name']);
                                if (!empty($pkg['house_check_in'])) {
                                    $line .= '<br><small style="color:#94a3b8;font-weight:400;font-size:11px;">📅 ' . date('M d', strtotime($pkg['house_check_in'])) 
                                          . (!empty($pkg['house_check_in_time']) ? ' (' . formatTimeDisplay($pkg['house_check_in_time']) . ')' : '')
                                          . ' → ' . date('M d, Y', strtotime($pkg['house_check_out']))
                                          . (!empty($pkg['house_check_out_time']) ? ' (' . formatTimeDisplay($pkg['house_check_out_time']) . ')' : '')
                                          . '</small>';
                                }
                                $itemsMobile[] = $line;
                            }
                            if (!empty($pkg['tour_name'])) {
                                $line = '🏖️ ' . htmlspecialchars($pkg['tour_name']);
                                if (!empty($pkg['tour_date'])) {
                                    $line .= '<br><small style="color:#94a3b8;font-weight:400;font-size:11px;">📅 ' . date('M d, Y', strtotime($pkg['tour_date'])) . '</small>';
                                }
                                $itemsMobile[] = $line;
                            }
                            if (!empty($pkg['food_name'])) {
                                $line = '🍽️ ' . htmlspecialchars($pkg['food_name']);
                                if (!empty($pkg['food_date'])) {
                                    $line .= '<br><small style="color:#94a3b8;font-weight:400;font-size:11px;">📅 ' . date('M d, Y', strtotime($pkg['food_date'])) . '</small>';
                                }
                                $itemsMobile[] = $line;
                            }
                        ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-box-open" style="color:#F4B400; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($pkg['reference_number']); ?>
                                </div>
                                <div class="card-badges">
                                    <span class="badge badge-<?php echo $pkg['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($pkg['payment_status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-box-open"></i>
                                <span class="card-label">Items</span>
                                <span class="card-value"><?php echo implode('<br><br>', $itemsMobile); ?></span>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-money-bill"></i>
                                <span class="card-label">Total</span>
                                <span class="card-value" style="color:#10b981; font-size:15px;">₱<?php echo number_format($pkg['grand_total'], 2); ?></span>
                            </div>

                            <div class="card-proof">
                                <?php if(!empty($pkg['payment_proof'])): ?>
                                    <img class="card-proof-thumb"
                                         src="uploads/payments/<?php echo htmlspecialchars($pkg['payment_proof']); ?>?t=<?php echo time(); ?>"
                                         alt="Proof"
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
                                <button type="button" class="btn-card-action btn-more"
                                        onclick='viewBooking("package", <?php echo htmlspecialchars(json_encode($pkg), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>

                                <?php if($pkg['payment_status'] == 'pending'): ?>
                                    <?php if(empty($pkg['payment_proof'])): ?>
                                        <button type="button" class="btn-card-action btn-pay-mobile"
                                                onclick="openPaymentModal('package', <?php echo $pkg['id']; ?>, <?php echo $pkg['grand_total']; ?>, '<?php echo $pkg['reference_number']; ?>')">
                                            <i class="fas fa-credit-card"></i> Pay
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-card-action btn-cancel-booking-mobile"
                                            onclick="openCancelBookingModal('package', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>')">
                                        <i class="fas fa-times-circle"></i> Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- REBOOKS TAB -->
            <div id="rebooks-tab" class="tab-content <?php echo $default_tab === 'rebooks' ? 'active' : ''; ?>" style="display:<?php echo $default_tab === 'rebooks' ? 'block' : 'none'; ?>;">
                <h4 style="margin-bottom:15px;font-size:16px;"><i class="fas fa-redo"></i> Rebooked House Bookings</h4>
                <?php 
                $rebooked_house = array_filter($house_bookings, function($b) {
                    return ($b['rebook_count'] ?? 0) > 0;
                });
                ?>
                <?php if(empty($rebooked_house)): ?>
                    <p style="text-align:center;padding:40px 15px;color:#94a3b8;">You haven't rebooked any house stay yet.</p>
                <?php else: ?>
                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref #</th><th>House</th><th>Rebooked On</th><th>New Check In</th>
                                    <th>New Check Out</th><th>Total</th><th>Status</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rebooked_house as $booking): 
                                    $is_pending = !empty($booking['rebooked_at']) && empty($booking['rebook_confirmed_at'])
                                               && $booking['payment_status'] == 'paid'
                                               && !in_array($booking['booking_status'] ?? '', ['cancelled', 'completed']);
                                    $is_locked = !empty($booking['rebook_confirmed_at']);
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['house_name']); ?></td>
                                    <td><?php echo !empty($booking['rebooked_at']) ? formatDateTimeDisplay($booking['rebooked_at']) : 'N/A'; ?></td>
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
                                    <td>₱<?php echo number_format($booking['total_amount']); ?></td>
                                    <td>
                                        <?php echo getBookingStatusLabel(
                                            $booking['booking_status'], $booking['payment_status'],
                                            $booking['rebook_count'] ?? 0,
                                            $booking['rebooked_at'] ?? null,
                                            $booking['rebook_confirmed_at'] ?? null
                                        ); ?>
                                        <?php if($is_pending): ?>
                                            <div class="rebook-pending-badge" style="display:block; margin-top:6px;">
                                                <i class="fas fa-hourglass-half"></i> Waiting for admin confirmation
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("house", <?php echo json_encode($booking); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <?php if($is_pending): ?>
                                                <div class="action-row">
                                                    <button class="btn-cancel-rebook" onclick="cancelRebook(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-undo"></i> Cancel Rebook
                                                    </button>
                                                </div>
                                            <?php elseif($is_locked): ?>
                                                <div class="action-row">
                                                    <span class="badge badge-success" style="font-size:10px;">
                                                        <i class="fas fa-lock"></i> Locked by Admin
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="booking-cards-mobile">
                        <?php foreach($rebooked_house as $booking): 
                            $is_pending = !empty($booking['rebooked_at']) && empty($booking['rebook_confirmed_at'])
                                       && $booking['payment_status'] == 'paid'
                                       && !in_array($booking['booking_status'] ?? '', ['cancelled', 'completed']);
                        ?>
                        <div class="booking-card-mobile">
                            <div class="card-top-row">
                                <div class="card-ref">
                                    <i class="fas fa-redo" style="color:#8b5cf6; font-size:11px;"></i>
                                    <?php echo htmlspecialchars($booking['reference_number']); ?>
                                </div>
                                <div class="card-badges">
                                    <?php if($is_pending): ?>
                                        <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><i class="fas fa-check-circle"></i> Confirmed</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-home"></i>
                                <span class="card-label">House</span>
                                <span class="card-value"><?php echo htmlspecialchars($booking['house_name']); ?></span>
                            </div>

                            <div class="card-row">
                                <i class="fas fa-calendar-alt"></i>
                                <span class="card-label">New Dates</span>
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

                            <div class="card-actions">
                                <button type="button" class="btn-card-action btn-more"
                                        onclick='viewBooking("house", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-eye"></i> More / View
                                </button>

                                <?php if($is_pending): ?>
                                    <button type="button" class="btn-card-action btn-cancel-rebook-mobile"
                                            onclick="cancelRebook(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-undo"></i> Cancel Rebook
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- HISTORY TAB -->
            <div id="history-tab" class="tab-content <?php echo $default_tab === 'history' ? 'active' : ''; ?>" style="display:<?php echo $default_tab === 'history' ? 'block' : 'none'; ?>;">
                <h4 style="margin-bottom:15px;font-size:16px;"><i class="fas fa-history"></i> Completed & Cancelled Bookings</h4>
                <?php 
                $completed_house = array_filter($house_bookings, function($b) {
                    return $b['booking_status'] == 'cancelled' || $b['booking_status'] == 'completed';
                });
                $completed_tour = array_filter($tour_bookings, function($b) {
                    return $b['booking_status'] == 'cancelled' || $b['booking_status'] == 'completed';
                });
                $completed_food = array_filter($food_bookings, function($b) {
                    return $b['booking_status'] == 'cancelled' || $b['booking_status'] == 'completed';
                });
                $completed_package = array_filter($package_bookings, function($b) {
                    return $b['booking_status'] == 'cancelled' || $b['booking_status'] == 'completed';
                });
                ?>
                <?php if(empty($completed_house) && empty($completed_tour) && empty($completed_food) && empty($completed_package)): ?>
                    <p style="text-align:center;padding:40px 15px;color:#94a3b8;">No booking history yet.</p>
                <?php else: ?>
                    <div class="table-responsive desktop-table">
                        <table>
                            <thead>
                                <tr><th>Type</th><th>Ref #</th><th>Item</th><th>Booked On</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Feedback</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($completed_house as $booking): 
                                    $rb = computeRebookInfo($pdo, $booking, $_SESSION['user_id']);
                                ?>
                                <tr>
                                    <td><span class="badge badge-info"><i class="fas fa-home"></i> House</span></td>
                                    <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['house_name']); ?></td>
                                    <td><?php echo formatDateTimeDisplay($booking['created_at']); ?></td>
                                    <td>
                                        <?php echo formatDateDisplay($booking['check_in_date']); ?>
                                        <?php if (!empty($booking['check_in_time'])): ?>
                                            <div class="booking-time-row">
                                                <i class="fas fa-clock"></i>
                                                <?php echo formatTimeDisplay($booking['check_in_time']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>₱<?php echo number_format($booking['total_amount']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getBookingStatusLabel($booking['booking_status'], $booking['payment_status'],
                                        $booking['rebook_count'] ?? 0, $booking['rebooked_at'] ?? null, $booking['rebook_confirmed_at'] ?? null); ?></td>
                                    <td>
                                        <?php if(hasFeedback($booking)): ?>
                                            <span class="feedback-badge"><i class="fas fa-star" style="color:#f59e0b;"></i> <?php echo $booking['feedback_rating']; ?>/5</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">No feedback</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("house", <?php echo json_encode($booking); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <?php if($booking['booking_status'] == 'completed' && $booking['payment_status'] == 'paid' && $rb['is_eligible']): ?>
                                                <div class="action-row">
                                                    <button class="btn-rebook-link" onclick="showRebookPolicyPopup(
                                                        '<?php echo $booking['id']; ?>',
                                                        '<?php echo htmlspecialchars($booking['house_name']); ?>',
                                                        '<?php echo htmlspecialchars($booking['reference_number']); ?>',
                                                        <?php echo $rb['remaining_rebooks']; ?>,
                                                        <?php echo $rb['days_remaining']; ?>
                                                    )">
                                                        <i class="fas fa-redo"></i> Rebook
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                            <div class="action-row" style="margin-top:6px;">
                                                <?php if(canGiveFeedback($booking)): ?>
                                                    <button class="btn-sm btn-feedback" onclick="openFeedbackModal('house', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['house_name']); ?>')">
                                                        <i class="fas fa-star"></i> Rate
                                                    </button>
                                                <?php elseif(hasFeedback($booking)): ?>
                                                    <button class="btn-sm btn-view-feedback" onclick="viewFeedback('<?php echo htmlspecialchars($booking['feedback_text']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn-sm btn-edit-feedback" onclick="editFeedback('house', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['house_name']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo addslashes($booking['feedback_text']); ?>', <?php echo !empty($booking['feedback_is_anonymous']) ? 'true' : 'false'; ?>)">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php foreach($completed_tour as $booking): ?>
                                <tr>
                                    <td><span class="badge badge-info"><i class="fas fa-umbrella-beach"></i> Tour</span></td>
                                    <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['tour_name']); ?></td>
                                    <td><?php echo formatDateTimeDisplay($booking['created_at']); ?></td>
                                    <td><?php echo formatDateDisplay($booking['booking_date']); ?></td>
                                    <td>₱<?php echo number_format($booking['total_amount']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getBookingStatusLabel($booking['booking_status'], $booking['payment_status']); ?></td>
                                    <td>
                                        <?php if(hasFeedback($booking)): ?>
                                            <span class="feedback-badge"><i class="fas fa-star" style="color:#f59e0b;"></i> <?php echo $booking['feedback_rating']; ?>/5</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">No feedback</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("tour", <?php echo json_encode($booking); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <div class="action-row">
                                                <?php if(canGiveFeedback($booking)): ?>
                                                    <button class="btn-sm btn-feedback" onclick="openFeedbackModal('tour', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['tour_name']); ?>')">
                                                        <i class="fas fa-star"></i> Rate
                                                    </button>
                                                <?php elseif(hasFeedback($booking)): ?>
                                                    <button class="btn-sm btn-view-feedback" onclick="viewFeedback('<?php echo htmlspecialchars($booking['feedback_text']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn-sm btn-edit-feedback" onclick="editFeedback('tour', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['tour_name']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo addslashes($booking['feedback_text']); ?>', <?php echo !empty($booking['feedback_is_anonymous']) ? 'true' : 'false'; ?>)">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php foreach($completed_food as $booking): ?>
                                <tr>
                                    <td><span class="badge badge-info"><i class="fas fa-utensils"></i> Food</span></td>
                                    <td><strong><?php echo htmlspecialchars($booking['reference_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['food_name']); ?></td>
                                    <td><?php echo formatDateTimeDisplay($booking['created_at']); ?></td>
                                    <td><?php echo formatDateDisplay($booking['preferred_date'] ?? $booking['created_at']); ?></td>
                                    <td>₱<?php echo number_format($booking['total_amount']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getBookingStatusLabel($booking['booking_status'], $booking['payment_status']); ?></td>
                                    <td>
                                        <?php if(hasFeedback($booking)): ?>
                                            <span class="feedback-badge"><i class="fas fa-star" style="color:#f59e0b;"></i> <?php echo $booking['feedback_rating']; ?>/5</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">No feedback</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("food", <?php echo json_encode($booking); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <div class="action-row">
                                                <?php if(canGiveFeedback($booking)): ?>
                                                    <button class="btn-sm btn-feedback" onclick="openFeedbackModal('food', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['food_name']); ?>')">
                                                        <i class="fas fa-star"></i> Rate
                                                    </button>
                                                <?php elseif(hasFeedback($booking)): ?>
                                                    <button class="btn-sm btn-view-feedback" onclick="viewFeedback('<?php echo htmlspecialchars($booking['feedback_text']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn-sm btn-edit-feedback" onclick="editFeedback('food', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['food_name']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo addslashes($booking['feedback_text']); ?>', <?php echo !empty($booking['feedback_is_anonymous']) ? 'true' : 'false'; ?>)">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <?php foreach($completed_package as $pkg): 
                                    $items = [];
                                    if (!empty($pkg['house_name'])) $items[] = '🏠 ' . htmlspecialchars($pkg['house_name']);
                                    if (!empty($pkg['tour_name'])) $items[] = '🏖️ ' . htmlspecialchars($pkg['tour_name']);
                                    if (!empty($pkg['food_name'])) $items[] = '🍽️ ' . htmlspecialchars($pkg['food_name']);
                                    $itemsStr = !empty($items) ? implode(', ', $items) : '—';
                                ?>
                                <tr>
                                    <td><span class="badge badge-info" style="background:#e0f2fe; color:#0369a1;"><i class="fas fa-box-open"></i> Package</span></td>
                                    <td><strong><?php echo htmlspecialchars($pkg['reference_number']); ?></strong></td>
                                    <td style="max-width:250px; word-break:break-word;"><?php echo $itemsStr; ?></td>
                                    <td><?php echo formatDateTimeDisplay($pkg['created_at']); ?></td>
                                    <td><?php echo formatDateDisplay($pkg['created_at']); ?></td>
                                    <td>₱<?php echo number_format($pkg['grand_total'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $pkg['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($pkg['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo getBookingStatusLabel($pkg['booking_status'], $pkg['payment_status']); ?></td>
                                    <td>
                                        <?php if(hasFeedback($pkg)): ?>
                                            <span class="feedback-badge"><i class="fas fa-star" style="color:#f59e0b;"></i> <?php echo $pkg['feedback_rating']; ?>/5</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">No feedback</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <div class="action-row">
                                                <button class="btn-view-booking" onclick='viewBooking("package", <?php echo json_encode($pkg); ?>)'>
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </div>
                                            <div class="action-row">
                                                <?php if(canGiveFeedback($pkg)): ?>
                                                    <button class="btn-sm btn-feedback" onclick="openFeedbackModal('package', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>', 'Package')">
                                                        <i class="fas fa-star"></i> Rate
                                                    </button>
                                                <?php elseif(hasFeedback($pkg)): ?>
                                                    <button class="btn-sm btn-view-feedback" onclick="viewFeedback('<?php echo htmlspecialchars($pkg['feedback_text']); ?>', <?php echo $pkg['feedback_rating']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>')">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="booking-cards-mobile">
                        <?php foreach($completed_house as $booking): ?>
                            <div class="booking-card-mobile">
                                <div class="card-top-row">
                                    <div class="card-ref">
                                        <i class="fas fa-home" style="color:#4DA6D9; font-size:11px;"></i>
                                        <?php echo htmlspecialchars($booking['reference_number']); ?>
                                    </div>
                                    <div class="card-badges">
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </div>
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
                                <div class="card-actions">
                                    <button type="button" class="btn-card-action btn-more"
                                            onclick='viewBooking("house", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-eye"></i> More / View
                                    </button>
                                    <?php if(canGiveFeedback($booking)): ?>
                                        <button type="button" class="btn-card-action" style="background:#8b5cf6;color:white;"
                                                onclick="openFeedbackModal('house', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['house_name']); ?>')">
                                            <i class="fas fa-star"></i> Rate
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach($completed_tour as $booking): ?>
                            <div class="booking-card-mobile">
                                <div class="card-top-row">
                                    <div class="card-ref">
                                        <i class="fas fa-umbrella-beach" style="color:#4DA6D9; font-size:11px;"></i>
                                        <?php echo htmlspecialchars($booking['reference_number']); ?>
                                    </div>
                                    <div class="card-badges">
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-umbrella-beach"></i>
                                    <span class="card-label">Tour</span>
                                    <span class="card-value"><?php echo htmlspecialchars($booking['tour_name']); ?></span>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span class="card-label">Date</span>
                                    <span class="card-value"><?php echo formatDateDisplay($booking['booking_date']); ?></span>
                                </div>
                                <div class="card-actions">
                                    <button type="button" class="btn-card-action btn-more"
                                            onclick='viewBooking("tour", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-eye"></i> More / View
                                    </button>
                                    <?php if(canGiveFeedback($booking)): ?>
                                        <button type="button" class="btn-card-action" style="background:#8b5cf6;color:white;"
                                                onclick="openFeedbackModal('tour', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['tour_name']); ?>')">
                                            <i class="fas fa-star"></i> Rate
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach($completed_food as $booking): ?>
                            <div class="booking-card-mobile">
                                <div class="card-top-row">
                                    <div class="card-ref">
                                        <i class="fas fa-utensils" style="color:#4DA6D9; font-size:11px;"></i>
                                        <?php echo htmlspecialchars($booking['reference_number']); ?>
                                    </div>
                                    <div class="card-badges">
                                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-utensils"></i>
                                    <span class="card-label">Food</span>
                                    <span class="card-value"><?php echo htmlspecialchars($booking['food_name']); ?></span>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-money-bill"></i>
                                    <span class="card-label">Total</span>
                                    <span class="card-value">₱<?php echo number_format($booking['total_amount']); ?></span>
                                </div>
                                <div class="card-actions">
                                    <button type="button" class="btn-card-action btn-more"
                                            onclick='viewBooking("food", <?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-eye"></i> More / View
                                    </button>
                                    <?php if(canGiveFeedback($booking)): ?>
                                        <button type="button" class="btn-card-action" style="background:#8b5cf6;color:white;"
                                                onclick="openFeedbackModal('food', <?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>', '<?php echo htmlspecialchars($booking['food_name']); ?>')">
                                            <i class="fas fa-star"></i> Rate
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach($completed_package as $pkg): ?>
                            <div class="booking-card-mobile">
                                <div class="card-top-row">
                                    <div class="card-ref">
                                        <i class="fas fa-box-open" style="color:#0ea5e9; font-size:11px;"></i>
                                        <?php echo htmlspecialchars($pkg['reference_number']); ?>
                                    </div>
                                    <div class="card-badges">
                                        <span class="badge badge-<?php echo $pkg['payment_status'] == 'paid' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($pkg['payment_status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-box-open"></i>
                                    <span class="card-label">Package</span>
                                    <span class="card-value">₱<?php echo number_format($pkg['grand_total'], 2); ?></span>
                                </div>
                                <div class="card-row">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span class="card-label">Booked</span>
                                    <span class="card-value"><?php echo formatDateDisplay($pkg['created_at']); ?></span>
                                </div>
                                <div class="card-actions">
                                    <button type="button" class="btn-card-action btn-more"
                                            onclick='viewBooking("package", <?php echo htmlspecialchars(json_encode($pkg), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-eye"></i> More / View
                                    </button>
                                    <?php if(canGiveFeedback($pkg)): ?>
                                        <button type="button" class="btn-card-action" style="background:#8b5cf6;color:white;"
                                                onclick="openFeedbackModal('package', <?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['reference_number']); ?>', 'Package')">
                                            <i class="fas fa-star"></i> Rate
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ✅ VIEW BOOKING MODAL -->
<div class="modal view-booking-modal" id="viewBookingModal">
    <div class="modal-content">
        <div class="modal-header view-booking-header">
            <h3 class="view-booking-title-wrap">
                <i class="fas fa-calendar-check"></i>
                <span class="view-booking-title-text" id="viewBookingTitle">Booking Details</span>
            </h3>
            <button class="close" onclick="closeViewBookingModal()">&times;</button>
        </div>
        <div class="view-booking-ref-bar">
            <i class="fas fa-hashtag"></i>
            <span id="viewBookingReference">N/A</span>
        </div>
        <div id="viewBookingContent"></div>
    </div>
</div>

<!-- REBOOK POLICY POPUP -->
<div class="rebook-policy-overlay" id="rebookPolicyPopup">
    <div class="rebook-policy-popup">
        <div class="popup-icon"><i class="fas fa-info-circle"></i></div>
        <h3>📋 Rebook Policy</h3>
        <p>Please read the rebooking policy carefully before proceeding:</p>
        <div class="policy-list">
            <li><i class="fas fa-check-circle check"></i> <strong>Maximum of 2 rebooks</strong> per booking</li>
            <li><i class="fas fa-clock info"></i> <strong>Within 7 days</strong> from your original check-in date</li>
            <li><i class="fas fa-check-circle check"></i> Only <strong>confirmed &amp; paid</strong> bookings can be rebooked</li>
            <li><i class="fas fa-info-circle info"></i> Payment stays <strong>PAID</strong> — no new payment needed</li>
            <li><i class="fas fa-clock info"></i> Status will be <strong>pending</strong> until admin confirms</li>
        </div>

        <div style="background:#f1f5f9; padding:14px 16px; border-radius:12px; margin:15px 0;">
            <div style="display:flex; justify-content:space-between; font-size:13px; color:#475569; margin-bottom:8px;">
                <span><i class="fas fa-redo"></i> Rebooks Used</span>
                <span><strong id="popupRebookCount">0</strong> / 2</span>
            </div>
            <div style="height:8px; background:#e2e8f0; border-radius:10px; overflow:hidden;">
                <div id="popupRebookBar" style="height:100%; width:0%; background:linear-gradient(90deg,#f59e0b,#fbbf24); transition:width 0.3s;"></div>
            </div>
        </div>

        <div style="background:#eff6ff; padding:12px 16px; border-radius:12px; margin:15px 0;">
            <div style="display:flex; justify-content:space-between; font-size:13px; color:#1e40af;">
                <span><i class="fas fa-clock"></i> Days Remaining to Rebook</span>
                <span><strong id="popupDaysRemaining">0</strong> days</span>
            </div>
        </div>

        <div style="background:#f8fafc;padding:15px;border-radius:12px;margin:15px 0;">
            <p style="margin:0;font-size:14px;color:#1e293b;">
                <strong>Booking:</strong> <span id="popupHouseName"></span><br>
                <strong>Reference:</strong> <span id="popupReference"></span>
            </p>
        </div>
        <div class="popup-actions">
            <button class="btn-proceed" onclick="proceedToRebook()"><i class="fas fa-redo"></i> Proceed to Rebook</button>
            <button class="btn-cancel-popup" onclick="closeRebookPolicyPopup()"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<!-- PAYMENT MODAL -->
<div class="modal" id="paymentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-mobile-alt"></i> GCash Payment</h3>
            <span class="close" onclick="closePaymentModal()">×</span>
        </div>
        <div id="paymentContent">
            <div class="qr-code">
                <?php if($gcash_qr_exists): ?>
                    <img src="<?php echo htmlspecialchars($gcash_qr_path); ?>?v=<?php echo $gcash_qr_version; ?>"
                         alt="GCash QR Code"
                         onerror="this.onerror=null; this.src='uploads/gcash/gcash_qr.png?v=<?php echo time(); ?>';">
                <?php else: ?>
                    <div style="background:#e2e8f0;padding:30px;border-radius:12px;">
                        <i class="fas fa-qrcode" style="font-size:48px;color:#94a3b8;"></i>
                        <p class="mt-2">QR Code will appear here</p>
                    </div>
                <?php endif; ?>
            </div>
            <div style="background:#f8fafc;padding:15px;border-radius:12px;margin:15px 0;">
                <p><strong><i class="fas fa-user"></i> Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?></p>
                <p><strong><i class="fas fa-mobile-alt"></i> GCash Number:</strong> <?php echo htmlspecialchars($gcash_number); ?></p>
                <p><strong><i class="fas fa-money-bill"></i> Amount to Pay:</strong> <span id="modal_amount" style="color:#10b981;font-size:20px;font-weight:bold;">₱0.00</span></p>
            </div>
            <div style="background:#fef3c7;padding:15px;border-radius:12px;margin:15px 0;">
                <p><strong><i class="fas fa-info-circle"></i> Instructions:</strong></p>
                <p style="white-space:pre-line;font-size:13px;"><?php echo nl2br(htmlspecialchars($gcash_instructions)); ?></p>
            </div>

            <form method="POST" enctype="multipart/form-data" id="uploadProofForm">
                <input type="hidden" name="upload_proof" value="1">
                <input type="hidden" name="booking_type" id="modal_booking_type">
                <input type="hidden" name="booking_id" id="modal_booking_id">
                <input type="hidden" name="reference" id="modal_reference">

                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:8px;color:#1e293b;font-weight:600;">
                        <i class="fas fa-hashtag" style="color:#4DA6D9;"></i> GCash Reference Number
                        <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="gcash_reference" id="modal_gcash_reference" class="form-control"
                           placeholder="e.g., 0123456789012" maxlength="20" required
                           inputmode="numeric"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '');"
                           style="padding:12px;width:100%;border:2px solid #e2e8f0;border-radius:8px;font-size:15px;letter-spacing:1px;font-weight:600;text-align:center;background:#f8faff;">
                    <small style="color:#94a3b8;display:block;margin-top:5px;">
                        <i class="fas fa-info-circle"></i> Find this in your GCash transaction receipt
                    </small>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:8px;color:#1e293b;font-weight:600;">
                        <i class="fas fa-image" style="color:#4DA6D9;"></i> Payment Proof (Screenshot) <span style="color:#dc2626;">*</span>
                    </label>

                    <input type="file" name="payment_proof" id="payment_proof_input" accept="image/*,.pdf" style="display:none;">
                    <input type="hidden" name="payment_proof_base64" id="payment_proof_base64">

                    <div id="proofPreviewBox" onclick="document.getElementById('payment_proof_input').click()"
                         style="border:2px dashed #cbd5e1;border-radius:12px;padding:20px;text-align:center;cursor:pointer;background:#f8fafc;transition:all 0.3s;min-height:130px;display:flex;align-items:center;justify-content:center;flex-direction:column;">
                        <i class="fas fa-cloud-upload-alt" style="font-size:36px;color:#94a3b8;margin-bottom:8px;"></i>
                        <span style="color:#64748b;font-weight:600;font-size:13px;">Tap to upload a screenshot</span>
                        <span style="color:#94a3b8;font-size:11px;margin-top:4px;">JPG, PNG, or PDF</span>
                    </div>

                    <button type="button" onclick="openProofCamera()"
                            style="width:100%;padding:12px;margin-top:10px;background:linear-gradient(135deg,#10b981,#059669);color:white;border:none;border-radius:10px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="fas fa-camera"></i> Take a Photo Instead
                    </button>

                    <button type="button" id="removeProofBtn" onclick="removeProof(event)"
                            style="width:100%;padding:10px;margin-top:8px;background:#fee2e2;color:#dc2626;border:none;border-radius:10px;font-weight:600;cursor:pointer;display:none;align-items:center;justify-content:center;gap:6px;">
                        <i class="fas fa-times"></i> Remove Proof
                    </button>
                </div>

                <button type="submit" id="submitProofBtn"
                        style="width:100%;padding:14px;background:linear-gradient(135deg,#10b981,#059669);color:white;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class="fas fa-paper-plane"></i> Submit Proof
                </button>
            </form>
        </div>
    </div>
</div>

<!-- PROOF CAMERA MODAL -->
<div class="modal" id="proofCameraModal" style="z-index: 40000;">
    <div class="modal-content" style="max-width: 600px; padding: 20px;">
        <div class="modal-header">
            <h3><i class="fas fa-camera"></i> Take a Photo</h3>
            <span class="close" onclick="closeProofCamera()">×</span>
        </div>
        <video id="proofCameraVideo" autoplay playsinline muted
               style="width:100%;max-height:60vh;border-radius:12px;background:#000;border:2px solid #4DA6D9;object-fit:cover;"></video>
        <div style="display:flex;gap:10px;margin-top:15px;flex-wrap:wrap;">
            <button type="button" onclick="captureProofPhoto()"
                    style="flex:1;min-width:130px;padding:14px;background:#10b981;color:white;border:none;border-radius:10px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <i class="fas fa-camera"></i> Capture
            </button>
            <button type="button" onclick="closeProofCamera()"
                    style="flex:1;min-width:130px;padding:14px;background:#64748b;color:white;border:none;border-radius:10px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<!-- CANCEL REBOOK MODAL -->
<div class="modal" id="cancelRebookModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-undo"></i> Cancel Pending Rebook</h3>
            <span class="close" onclick="closeCancelRebookModal()">×</span>
        </div>
        <div style="text-align:center;padding:15px;">
            <i class="fas fa-question-circle" style="font-size:44px;color:#f59e0b;margin-bottom:12px;"></i>
            <p><strong>Are you sure you want to cancel this pending rebook?</strong></p>
            <p style="font-size:14px;color:#64748b;">Ref: <strong id="cancel_rebook_reference"></strong></p>
            <div style="background:#fef3c7;padding:12px;border-radius:10px;margin:15px 0;font-size:13px;color:#92400e;text-align:left;">
                <i class="fas fa-info-circle"></i> <strong>Note:</strong> Your booking will be restored to its <strong>original state</strong>.
            </div>
            <form method="POST" style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px;justify-content:center;">
                <input type="hidden" name="booking_id" id="cancel_rebook_booking_id">
                <button type="submit" name="cancel_rebook" style="padding:12px 24px;background:#ef4444;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;flex:1;min-width:140px;">
                    <i class="fas fa-check-circle"></i> Yes, Cancel
                </button>
                <button type="button" style="background:#64748b;color:white;border:none;padding:12px 24px;border-radius:8px;font-weight:600;cursor:pointer;flex:1;min-width:140px;" onclick="closeCancelRebookModal()">
                    <i class="fas fa-times"></i> No, Keep It
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ✅ CANCEL UNPAID BOOKING MODAL -->
<div class="modal" id="cancelBookingModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle"></i> Cancel Booking</h3>
            <span class="close" onclick="closeCancelBookingModal()">&times;</span>
        </div>
        <div style="text-align:center;padding:15px;">
            <i class="fas fa-exclamation-triangle" style="font-size:44px;color:#ef4444;margin-bottom:12px;"></i>
            <p><strong>Are you sure you want to cancel this booking?</strong></p>
            <p style="font-size:14px;color:#64748b;">Ref: <strong id="cancel_booking_reference"></strong></p>
            <div style="background:#dbeafe;padding:12px;border-radius:10px;margin:15px 0;font-size:13px;color:#1e40af;text-align:left;">
                <i class="fas fa-info-circle"></i> <strong>Note:</strong> Since you haven't paid yet, this cancellation is <strong>FREE</strong>. You can book again anytime.
            </div>
            <form method="POST" style="margin-top:20px;display:flex;flex-wrap:wrap;gap:10px;justify-content:center;">
                <input type="hidden" name="cancel_booking" value="1">
                <input type="hidden" name="booking_type" id="cancel_booking_type">
                <input type="hidden" name="booking_id" id="cancel_booking_id">
                <button type="submit" style="padding:12px 24px;background:#ef4444;color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;flex:1;min-width:140px;">
                    <i class="fas fa-check-circle"></i> Yes, Cancel Booking
                </button>
                <button type="button" style="background:#64748b;color:white;border:none;padding:12px 24px;border-radius:8px;font-weight:600;cursor:pointer;flex:1;min-width:140px;" onclick="closeCancelBookingModal()">
                    <i class="fas fa-times"></i> No, Keep It
                </button>
            </form>
        </div>
    </div>
</div>

<!-- FEEDBACK MODAL -->
<div class="modal" id="feedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star"></i> <span id="feedbackModalTitle">Rate Your Experience</span></h3>
            <span class="close" onclick="closeFeedbackModal()">×</span>
        </div>
        <div id="feedbackContent">
            <p style="color:#64748b;margin-bottom:15px;">How was your experience with <strong id="feedback_item_name"></strong>?</p>
            <p style="color:#64748b;font-size:13px;margin-bottom:15px;">Reference: <strong id="feedback_reference"></strong></p>
            <form method="POST" id="feedbackForm">
                <input type="hidden" name="booking_type" id="feedback_booking_type">
                <input type="hidden" name="booking_id" id="feedback_booking_id">
                <div style="text-align:center;margin-bottom:20px;">
                    <label style="display:block;margin-bottom:10px;font-weight:600;color:#1e293b;">Your Rating</label>
                    <div class="rating-stars" id="ratingStars">
                        <i class="fas fa-star" data-rating="1" onclick="setRating(1)"></i>
                        <i class="fas fa-star" data-rating="2" onclick="setRating(2)"></i>
                        <i class="fas fa-star" data-rating="3" onclick="setRating(3)"></i>
                        <i class="fas fa-star" data-rating="4" onclick="setRating(4)"></i>
                        <i class="fas fa-star" data-rating="5" onclick="setRating(5)"></i>
                    </div>
                    <input type="hidden" name="rating" id="feedback_rating" value="0" required>
                    <span id="ratingText" style="font-size:14px;color:#64748b;">Select a rating</span>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:8px;font-weight:600;color:#1e293b;"><i class="fas fa-comment"></i> Your Feedback</label>
                    <textarea name="feedback" id="feedback_text" class="form-control" rows="4" placeholder="Share your experience..." style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:10px;resize:vertical;"></textarea>
                </div>
                <div style="margin-bottom:15px;display:flex;align-items:center;gap:10px;padding:10px 15px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
                    <input type="checkbox" name="is_anonymous" id="feedback_is_anonymous" value="1">
                    <label for="feedback_is_anonymous" style="margin-bottom:0;cursor:pointer;font-weight:500;color:#1e293b;">
                        <i class="fas fa-user-secret" style="color:#7c3aed;"></i> Post as Anonymous
                    </label>
                </div>
                <button type="submit" name="submit_feedback" id="feedbackSubmitBtn" style="width:100%;padding:12px;background:linear-gradient(135deg,#4DA6D9,#7bb8f0);color:white;border:none;border-radius:10px;font-weight:600;cursor:pointer;">
                    <i class="fas fa-paper-plane"></i> Submit Feedback
                </button>
            </form>
        </div>
    </div>
</div>

<!-- EDIT FEEDBACK MODAL -->
<div class="modal" id="editFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Your Review</h3>
            <span class="close" onclick="closeEditFeedbackModal()">×</span>
        </div>
        <div id="editFeedbackContent">
            <p style="color:#64748b;margin-bottom:15px;">Editing your review for <strong id="edit_feedback_item_name"></strong></p>
            <p style="color:#64748b;font-size:13px;margin-bottom:15px;">Reference: <strong id="edit_feedback_reference"></strong></p>
            <form method="POST" id="editFeedbackForm">
                <input type="hidden" name="booking_type" id="edit_feedback_booking_type">
                <input type="hidden" name="booking_id" id="edit_feedback_booking_id">
                <div style="text-align:center;margin-bottom:20px;">
                    <label style="display:block;margin-bottom:10px;font-weight:600;color:#1e293b;">Your Rating</label>
                    <div class="rating-stars" id="editRatingStars">
                        <i class="fas fa-star" data-rating="1" onclick="setEditRating(1)"></i>
                        <i class="fas fa-star" data-rating="2" onclick="setEditRating(2)"></i>
                        <i class="fas fa-star" data-rating="3" onclick="setEditRating(3)"></i>
                        <i class="fas fa-star" data-rating="4" onclick="setEditRating(4)"></i>
                        <i class="fas fa-star" data-rating="5" onclick="setEditRating(5)"></i>
                    </div>
                    <input type="hidden" name="rating" id="edit_feedback_rating" value="0" required>
                    <span id="editRatingText" style="font-size:14px;color:#64748b;">Select a rating</span>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:8px;font-weight:600;color:#1e293b;"><i class="fas fa-comment"></i> Your Feedback</label>
                    <textarea name="feedback" id="edit_feedback_text" class="form-control" rows="4" style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:10px;resize:vertical;"></textarea>
                </div>
                <div style="margin-bottom:15px;display:flex;align-items:center;gap:10px;padding:10px 15px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
                    <input type="checkbox" name="is_anonymous" id="edit_feedback_is_anonymous" value="1">
                    <label for="edit_feedback_is_anonymous" style="margin-bottom:0;cursor:pointer;font-weight:500;color:#1e293b;">
                        <i class="fas fa-user-secret" style="color:#7c3aed;"></i> Post as Anonymous                    </label>
                </div>
                <button type="submit" name="edit_feedback_submit" id="editFeedbackSubmitBtn" style="width:100%;padding:12px;background:#f59e0b;color:#0B2447;border:none;border-radius:10px;font-weight:600;cursor:pointer;">
                    <i class="fas fa-edit"></i> Update Feedback
                </button>
            </form>
        </div>
    </div>
</div>

<!-- VIEW FEEDBACK MODAL -->
<div class="modal" id="viewFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star"></i> Your Feedback</h3>
            <span class="close" onclick="closeViewFeedbackModal()">×</span>
        </div>
        <div id="viewFeedbackContent">
            <p style="color:#64748b;font-size:13px;margin-bottom:15px;">Reference: <strong id="view_feedback_reference"></strong></p>
            <div style="text-align:center;margin-bottom:15px;">
                <div id="view_feedback_stars" style="font-size:24px;"></div>
            </div>
            <div style="background:#f8fafc;padding:15px;border-radius:10px;">
                <p id="view_feedback_text" style="color:#1e293b;font-style:italic;margin:0;">No feedback text provided.</p>
            </div>
        </div>
    </div>
</div>

<!-- OVERALL FEEDBACK MODAL -->
<div class="modal" id="overallFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-star" style="color: #f59e0b;"></i>
                <?php echo $user_has_feedback ? 'Edit Your Review' : 'Rate Your Experience'; ?>
            </h3>
            <span class="close" onclick="closeOverallFeedbackModal()">&times;</span>
        </div>

        <div id="overallFeedbackContent">
            <?php if(isset($_SESSION['user_id'])): ?>

                <?php if($user_has_feedback): ?>
                    <div class="prev-rating-banner">
                        <div class="prev-label">You previously rated:</div>
                        <div class="prev-stars">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star"
                                   style="color: <?php echo $i <= (int)$user_feedback['rating'] ? '#f59e0b' : '#cbd5e1'; ?>;"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="prev-hint">Update your rating and review below</div>
                    </div>

                    <label class="overall-rating-label">Your Overall Rating</label>

                    <form method="POST" action="profile.php" id="overallFeedbackForm">
                        <input type="hidden" name="submit_feedback" value="1">
                        <input type="hidden" name="rating" id="overall_feedback_rating" value="<?php echo (int)$user_feedback['rating']; ?>" required>

                        <div class="big-rating-input" id="bigRatingInput">
                            <i class="fas fa-star <?php echo (int)$user_feedback['rating'] >= 1 ? 'active' : ''; ?>" data-rating="1" onclick="setBigRating(1)" onmouseenter="previewBigRating(1)"></i>
                            <i class="fas fa-star <?php echo (int)$user_feedback['rating'] >= 2 ? 'active' : ''; ?>" data-rating="2" onclick="setBigRating(2)" onmouseenter="previewBigRating(2)"></i>
                            <i class="fas fa-star <?php echo (int)$user_feedback['rating'] >= 3 ? 'active' : ''; ?>" data-rating="3" onclick="setBigRating(3)" onmouseenter="previewBigRating(3)"></i>
                            <i class="fas fa-star <?php echo (int)$user_feedback['rating'] >= 4 ? 'active' : ''; ?>" data-rating="4" onclick="setBigRating(4)" onmouseenter="previewBigRating(4)"></i>
                            <i class="fas fa-star <?php echo (int)$user_feedback['rating'] >= 5 ? 'active' : ''; ?>" data-rating="5" onclick="setBigRating(5)" onmouseenter="previewBigRating(5)"></i>
                        </div>

                        <div class="rating-word" id="bigRatingWord">
                            <?php
                            $ratingTexts = [1=>'Very Poor', 2=>'Poor', 3=>'Average', 4=>'Good', 5=>'Excellent!'];
                            echo $ratingTexts[(int)$user_feedback['rating']] ?? 'Average';
                            ?>
                        </div>

                        <label class="review-section-label" for="overall_comment">Your Comment</label>
                        <textarea name="comment" id="overall_comment" class="review-textarea" rows="3" placeholder="Share your overall experience..."><?php echo htmlspecialchars($user_feedback['comment']); ?></textarea>

                        <label class="anon-row" for="overall_is_anonymous">
                            <input type="checkbox" name="is_anonymous" id="overall_is_anonymous" value="1" <?php echo (!empty($user_feedback['is_anonymous'])) ? 'checked' : ''; ?>>
                            <span class="anon-text"><i class="fas fa-user-secret"></i> Post as Anonymous</span>
                            <span class="anon-note">Your name will not be shown publicly</span>
                        </label>

                        <div style="display:flex; gap:10px;">
                            <button type="button" class="btn-cancel-review" onclick="closeOverallFeedbackModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="update-review-btn" style="flex:2;">
                                <i class="fas fa-paper-plane"></i> Update Review
                            </button>
                        </div>
                    </form>

                <?php else: ?>
                    <label class="overall-rating-label">Your Overall Rating</label>

                    <form method="POST" action="profile.php" id="overallFeedbackForm">
                        <input type="hidden" name="submit_feedback" value="1">
                        <input type="hidden" name="rating" id="overall_feedback_rating" value="0" required>

                        <div class="big-rating-input" id="bigRatingInput">
                            <i class="fas fa-star" data-rating="1" onclick="setBigRating(1)" onmouseenter="previewBigRating(1)"></i>
                            <i class="fas fa-star" data-rating="2" onclick="setBigRating(2)" onmouseenter="previewBigRating(2)"></i>
                            <i class="fas fa-star" data-rating="3" onclick="setBigRating(3)" onmouseenter="previewBigRating(3)"></i>
                            <i class="fas fa-star" data-rating="4" onclick="setBigRating(4)" onmouseenter="previewBigRating(4)"></i>
                            <i class="fas fa-star" data-rating="5" onclick="setBigRating(5)" onmouseenter="previewBigRating(5)"></i>
                        </div>

                        <div class="rating-word" id="bigRatingWord">Select a rating</div>

                        <label class="review-section-label" for="overall_comment">Your Comment</label>
                        <textarea name="comment" id="overall_comment" class="review-textarea"
                                  rows="3" placeholder="Share your overall experience..."></textarea>

                        <label class="anon-row" for="overall_is_anonymous">
                            <input type="checkbox" name="is_anonymous" id="overall_is_anonymous" value="1">
                            <span class="anon-text"><i class="fas fa-user-secret"></i> Post as Anonymous</span>
                            <span class="anon-note">Your name will not be shown publicly</span>
                        </label>

                        <div style="display:flex; gap:10px;">
                            <button type="button" class="btn-cancel-review" onclick="closeOverallFeedbackModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="update-review-btn" style="flex:2;">
                                <i class="fas fa-paper-plane"></i> Submit Review
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

            <?php else: ?>
                <div style="text-align:center; padding:30px; color:#4a6a8c;">
                    <i class="fas fa-lock" style="font-size:48px; margin-bottom:15px; color:#4DA6D9;"></i>
                    <p>Please <a href="login.php" style="color:#4DA6D9; font-weight:600; text-decoration:none;">login</a> to leave a review.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CENTERED SUCCESS POPUP -->
<?php if(isset($_GET['feedback_success'])): ?>
<div class="alert-overlay show" id="feedbackSuccessAlert">
    <div class="alert-box">
        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Thank You!</h3>
        <p><?php echo isset($success) ? htmlspecialchars($success) : 'Your feedback has been recorded.'; ?></p>
        <button class="btn-popup-ok" onclick="dismissAlert()">OK</button>
    </div>
</div>
<?php endif; ?>

<!-- FOOTER -->
<div class="footer">
    <div class="footer-content">
        <div class="footer-grid">
            <div class="footer-col">
                <h4><i class="fas fa-home"></i> Transient House & Tours</h4>
                <p><?php echo htmlspecialchars($content['footer']['company_description'] ?? 'Your trusted partner for comfortable accommodations and exciting island adventures.'); ?></p>
                <div class="social-links">
                    <a href="<?php echo htmlspecialchars($facebook_link); ?>" target="_blank" title="Facebook" rel="noopener">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                </div>
            </div>
            <div class="footer-col">
                <h4><i class="fas fa-link"></i> Quick Links</h4>
                <ul>
                    <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="houses.php"><i class="fas fa-chevron-right"></i> Houses</a></li>
                    <li><a href="tours.php"><i class="fas fa-chevron-right"></i> Tours</a></li>
                    <li><a href="activities.php"><i class="fas fa-chevron-right"></i> Activities</a></li>
                    <li><a href="food.php"><i class="fas fa-chevron-right"></i> Food</a></li>
                    <li><a href="packages.php"><i class="fas fa-chevron-right"></i> My Package</a></li>
                    <li><a href="reviews.php"><i class="fas fa-chevron-right"></i> Reviews</a></li>
                    <li><a href="profile.php?tab=packages"><i class="fas fa-chevron-right"></i> My Profile</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4><i class="fas fa-info-circle"></i> Contact Info</h4>
                <div class="footer-map">
                    <iframe src="<?php echo htmlspecialchars($map_embed); ?>" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
                <ul>
                    <li>
                        <i class="fas fa-map-marker-alt"></i>
                        <?php if ($maps_url != '#'): ?>
                            <a href="<?php echo $maps_url; ?>" target="_blank" rel="noopener noreferrer" style="color: #b3d9ff; text-decoration: none;">
                                <?php echo htmlspecialchars($location_address); ?>
                                <i class="fas fa-external-link-alt" style="font-size: 10px; margin-left: 4px; opacity: 0.6;"></i>
                            </a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($location_address); ?>
                        <?php endif; ?>
                    </li>
                    <li><i class="fas fa-phone"></i><a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $content['footer']['phone'] ?? '+639123456789'); ?>" style="color: #b3d9ff; text-decoration: none;"><?php echo htmlspecialchars($content['footer']['phone'] ?? '+63 912 345 6789'); ?></a></li>
                    <li><i class="fas fa-envelope"></i><a href="mailto:<?php echo htmlspecialchars($content['footer']['email'] ?? 'info@transientrental.com'); ?>" style="color: #b3d9ff; text-decoration: none;"><?php echo htmlspecialchars($content['footer']['email'] ?? 'info@transientrental.com'); ?></a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <div>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($content['footer']['copyright'] ?? 'Transient House & Tours. All rights reserved.'); ?></div>
            <div class="footer-bottom-links">
                <a onclick="reopenTermsModal('privacy'); return false;"><?php echo htmlspecialchars($content['footer']['privacy_policy'] ?? 'Privacy Policy'); ?></a>
                <a onclick="reopenTermsModal('terms'); return false;"><?php echo htmlspecialchars($content['footer']['terms_of_service'] ?? 'Terms of Service'); ?></a>
            </div>
        </div>
    </div>
</div>

<!-- LOGOUT CONFIRMATION MODAL -->
<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="logout.php" class="btn-logout-confirm">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<!-- TERMS MODAL -->
<div class="modal terms-modal" id="termsModal"
     data-must-accept="<?php echo $force_must_accept ? '1' : '0'; ?>">
    <div class="modal-content terms-modal-content">

        <div class="terms-modal-header">
            <div class="terms-modal-icon">
                <i class="fas fa-file-contract"></i>
            </div>
            <h3 id="termsModalTitle">Before You Continue</h3>
            <p id="termsModalSubtitle">Please review our Terms &amp; Privacy Policy</p>

            <button type="button"
                    class="terms-modal-close"
                    id="termsModalCloseBtn"
                    onclick="closeTermsReadOnly()"
                    aria-label="Close"
                    style="display: none;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="terms-scroll-container" id="termsScrollContainer">
            <div class="terms-section" id="termsSection">
                <div class="terms-section-title">
                    <i class="fas fa-scroll"></i>
                    <span id="termsTitleText"><?php echo htmlspecialchars($termsContent['terms_title']); ?></span>
                </div>
                <div class="terms-section-body" id="termsBody">
                    <?php
                    $termsBody = $termsContent['terms_body'];
                    if (preg_match('/<[^>]+>/', $termsBody)) {
                        echo $termsBody;
                    } else {
                        echo nl2br(htmlspecialchars($termsBody));
                    }
                    ?>
                </div>
            </div>

            <div class="terms-divider">
                <span>END OF TERMS &amp; CONDITIONS</span>
            </div>

            <div class="terms-section" id="privacySection">
                <div class="terms-section-title">
                    <i class="fas fa-shield-alt"></i>
                    <span id="privacyTitleText"><?php echo htmlspecialchars($termsContent['privacy_title']); ?></span>
                </div>
                <div class="terms-section-body" id="privacyBody">
                    <?php
                    $privacyBody = $termsContent['privacy_body'];
                    if (preg_match('/<[^>]+>/', $privacyBody)) {
                        echo $privacyBody;
                    } else {
                        echo nl2br(htmlspecialchars($privacyBody));
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="terms-scroll-hint" id="termsScrollHint">
            <i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox
        </div>

        <form method="POST" id="termsAcceptForm">
            <label class="terms-checkbox-label" for="terms_agree">
                <input type="checkbox" name="agree" id="terms_agree" value="1" disabled>
                <span class="terms-checkbox-text">
                    <i class="fas fa-check-circle" style="color: #10b981;"></i>
                    I understand and accept the <strong>Terms &amp; Conditions</strong> and <strong>Privacy Policy</strong>.
                </span>
            </label>
            <button type="submit" name="accept_terms" class="terms-accept-btn" id="termsAcceptBtn" disabled>
                <i class="fas fa-check"></i> Accept &amp; Continue
            </button>
        </form>

        <p class="terms-modal-footer-note" id="termsModalFooterNote">
            You can review these documents anytime from the footer links.
        </p>
    </div>
</div>

<script>
// ============================================================
// ✅ DEFAULT TAB FROM PHP (URL parameter)
// ============================================================
var DEFAULT_TAB = '<?php echo $default_tab; ?>';

// ============================================================
// SIDEBAR TOGGLE — mobile only
// ============================================================
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('menuToggle');

    if (!sidebar || !overlay || !toggleBtn) return;

    const willOpen = !sidebar.classList.contains('open');

    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    toggleBtn.classList.toggle('active');

    if (willOpen && window.innerWidth <= 1100) {
        document.body.classList.add('sidebar-open-mobile');
    } else {
        document.body.classList.remove('sidebar-open-mobile');
    }

    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('open')) toggleSidebar();

        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) closeLogoutModal();
    }
});

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar && window.innerWidth > 1100 && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.getElementById('menuToggle').classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
        document.body.style.overflow = 'auto';
    }
});

// ============================================================
// DISMISS CENTERED ALERT POPUP
// ============================================================
function dismissAlert() {
    document.querySelectorAll('.alert-overlay').forEach(function(alert) {
        alert.style.display = 'none';
        alert.classList.remove('show');
    });
    if (window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.delete('feedback_success');
        window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
    }
}

// ============================================================
// LOGOUT CONFIRMATION MODAL
// ============================================================
function openLogoutModal(event) {
    if (event) event.preventDefault();

    const sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('menuToggle');
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        if (toggleBtn) toggleBtn.classList.remove('active');
        document.body.classList.remove('sidebar-open-mobile');
    }

    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';

    setTimeout(function() {
        const cancelBtn = document.querySelector('.btn-logout-cancel');
        if (cancelBtn) cancelBtn.focus();
    }, 100);
}

function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeLogoutModal();
        });
    }
});

// ============================================================
// ✅ VIEW BOOKING MODAL (WITH CHECK-IN / CHECK-OUT TIMES)
// ============================================================
function viewBooking(type, booking) {
    let bookingData = typeof booking === 'string' ? JSON.parse(booking) : booking;
    document.getElementById('viewBookingReference').textContent = bookingData.reference_number || 'N/A';
    
    let typeLabel = '';
    if (type === 'house') typeLabel = 'House Booking';
    else if (type === 'tour') typeLabel = 'Tour Booking';
    else if (type === 'food') typeLabel = 'Food Reservation';
    else if (type === 'package') typeLabel = 'Package Booking';
    document.getElementById('viewBookingTitle').textContent = typeLabel;
    
    function parseDateForDisplay(dateStr) {
        if (!dateStr) return 'N/A';
        var parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        var date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
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
    
    function formatDateTimeDisplay(dateTimeStr) {
        if (!dateTimeStr) return 'N/A';
        try {
            var d = new Date(dateTimeStr.replace(' ', 'T'));
            if (isNaN(d.getTime())) return dateTimeStr;
            return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + ' at ' +
                   d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        } catch(e) { return dateTimeStr; }
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    
    let proofBlockHtml = '';
    const hasProof = bookingData.payment_proof && bookingData.payment_proof !== '';
    const hasGcash = bookingData.gcash_reference && bookingData.gcash_reference.trim() !== '';

    if (hasProof || hasGcash) {
        proofBlockHtml += `<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:14px;">`;
        proofBlockHtml += `<div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;"><i class="fas fa-receipt"></i> Payment Proof</div>`;

        if (hasGcash) {
            proofBlockHtml += `
                <div style="display:flex; align-items:center; gap:10px; background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:2px solid #10b981; border-radius:10px; padding:10px 14px; margin-bottom:12px;">
                    <div style="width:38px;height:38px;background:#10b981;color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">
                        <i class="fas fa-hashtag"></i>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:10px;color:#065f46;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">GCash Reference</div>
                        <div style="font-size:17px;font-weight:800;color:#065f46;letter-spacing:1.5px;font-family:'Courier New',monospace;word-break:break-all;">${escapeHtml(bookingData.gcash_reference)}</div>
                    </div>
                    <button type="button" onclick="copyTextToClipboard('${escapeHtml(bookingData.gcash_reference).replace(/'/g, "\\'")}', this)"
                        style="background:white;color:#10b981;border:1px solid #10b981;padding:7px 12px;border-radius:8px;font-weight:700;font-size:11px;cursor:pointer;white-space:nowrap;">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>`;
        }

        if (hasProof) {
            proofBlockHtml += `
                <div style="text-align:center;">
                    <img src="uploads/payments/${escapeHtml(bookingData.payment_proof)}?t=${Date.now()}"
                         alt="Payment Proof"
                         style="max-width:100%;max-height:340px;border-radius:10px;border:1px solid #e2e8f0;background:white;cursor:pointer;"
                         onclick="openImageFullscreen('uploads/payments/${escapeHtml(bookingData.payment_proof)}')"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <div style="display:none; padding:20px; color:#94a3b8; font-size:13px; background:white; border-radius:10px;">Proof image not found.</div>
                    <div style="font-size:11px; color:#94a3b8; margin-top:8px;">
                        <i class="fas fa-search-plus"></i> Tap image to view fullscreen
                    </div>
                </div>`;
        } else {
            proofBlockHtml += `<div style="text-align:center; padding:16px; color:#94a3b8; font-size:12px; font-style:italic;">No payment proof uploaded.</div>`;
        }

        proofBlockHtml += `</div>`;
    }

    let guestNamesDisplay = 'No guest names recorded';
    if (bookingData.guest_names) {
        let names = bookingData.guest_names;
        let nameArray = [];
        if (typeof names === 'string' && (names.startsWith('[') || names.startsWith('{'))) {
            try {
                let parsed = JSON.parse(names);
                if (Array.isArray(parsed)) nameArray = parsed.filter(n => n && n.trim && n.trim().length > 0);
                else if (typeof parsed === 'object') nameArray = Object.values(parsed).filter(v => typeof v === 'string' && v.trim().length > 0);
            } catch(e) {}
        }
        if (nameArray.length === 0 && typeof names === 'string' && names.length > 0) {
            if (names.includes('\n')) nameArray = names.split('\n').map(n => n.trim()).filter(n => n.length > 0);
            else if (names.includes(',')) nameArray = names.split(',').map(n => n.trim()).filter(n => n.length > 0);
            else nameArray = [names.trim()];
        }
        if (nameArray.length > 0) guestNamesDisplay = nameArray.join(', ');
    }
    
    let details = [];
    details.push({ label: 'Reference Number', value: escapeHtml(bookingData.reference_number || 'N/A') });
    
    if (type === 'house') {
        details.push({ label: 'House', value: escapeHtml(bookingData.house_name || 'N/A') });

        var checkInDisplay = parseDateForDisplay(bookingData.check_in_date);
        if (bookingData.check_in_time) {
            checkInDisplay += ' • ' + formatTimeDisplay(bookingData.check_in_time);
        }
        details.push({ label: 'Check In', value: escapeHtml(checkInDisplay) });

        var checkOutDisplay = parseDateForDisplay(bookingData.check_out_date);
        if (bookingData.check_out_time) {
            checkOutDisplay += ' • ' + formatTimeDisplay(bookingData.check_out_time);
        }
        details.push({ label: 'Check Out', value: escapeHtml(checkOutDisplay) });

        details.push({ label: 'Number of Pax', value: escapeHtml(bookingData.number_of_guests || '1') });
        details.push({ label: 'Guest Names', value: escapeHtml(guestNamesDisplay) });
    } else if (type === 'tour') {
        details.push({ label: 'Tour', value: escapeHtml(bookingData.tour_name || 'N/A') });
        if (bookingData.guest_name) details.push({ label: 'Guest Name', value: '<span style="color:#0369a1; font-weight:600;"><i class="fas fa-user"></i> ' + escapeHtml(bookingData.guest_name) + '</span>' });
        if (bookingData.contact_number) details.push({ label: 'Contact Number', value: '<a href="tel:' + escapeHtml(bookingData.contact_number) + '" style="color:#0B2447; font-weight:600; text-decoration:none;"><i class="fas fa-phone"></i> ' + escapeHtml(bookingData.contact_number) + '</a>' });
        details.push({ label: 'Booking Date', value: escapeHtml(parseDateForDisplay(bookingData.booking_date)) });
        if (bookingData.preferred_time) details.push({ label: 'Preferred Time', value: '<span class="badge badge-info"><i class="fas fa-clock"></i> ' + escapeHtml(formatTimeDisplay(bookingData.preferred_time)) + '</span>' });
        details.push({ label: 'Number of Pax', value: escapeHtml(bookingData.number_of_guests || '1') });
        if (bookingData.special_requests) details.push({ label: 'Special Requests', value: '<em style="color:#475569;">' + escapeHtml(bookingData.special_requests) + '</em>' });
    } else if (type === 'food') {
        details.push({ label: 'Food Package', value: escapeHtml(bookingData.food_name || 'N/A') });
        if (bookingData.guest_name) details.push({ label: 'Guest Name', value: '<span style="color:#0369a1; font-weight:600;"><i class="fas fa-user"></i> ' + escapeHtml(bookingData.guest_name) + '</span>' });
        if (bookingData.size_variant) details.push({ label: 'Package Size', value: escapeHtml(bookingData.size_variant) });
        if (bookingData.preferred_date) details.push({ label: 'Preferred Date', value: escapeHtml(parseDateForDisplay(bookingData.preferred_date)) });
        if (bookingData.preferred_time) details.push({ label: 'Preferred Time', value: '<span class="badge badge-info"><i class="fas fa-clock"></i> ' + escapeHtml(formatTimeDisplay(bookingData.preferred_time)) + '</span>' });
        if (bookingData.number_of_persons) details.push({ label: 'Number of Persons', value: '<span class="badge badge-info"><i class="fas fa-users"></i> ' + escapeHtml(bookingData.number_of_persons) + '</span>' });
        details.push({ label: 'Quantity (Packages)', value: escapeHtml(bookingData.quantity || '1') });
        if (bookingData.special_requests) details.push({ label: 'Special Requests', value: '<em style="color:#475569;">' + escapeHtml(bookingData.special_requests) + '</em>' });
    } else if (type === 'package') {
        if (bookingData.house_name) {
            let houseInfo = '<div style="background:#f0f7fb; border-left:3px solid #4DA6D9; border-radius:8px; padding:10px 12px; margin-bottom:8px; text-align:left;">';
            houseInfo += '<div style="font-weight:700; color:#0B2447; font-size:13px; margin-bottom:6px;"><i class="fas fa-home" style="color:#4DA6D9;"></i> ' + escapeHtml(bookingData.house_name) + '</div>';

            if (bookingData.house_check_in && bookingData.house_check_out) {
                var pkgCheckIn = parseDateForDisplay(bookingData.house_check_in);
                if (bookingData.house_check_in_time) {
                    pkgCheckIn += ' • ' + formatTimeDisplay(bookingData.house_check_in_time);
                }
                var pkgCheckOut = parseDateForDisplay(bookingData.house_check_out);
                if (bookingData.house_check_out_time) {
                    pkgCheckOut += ' • ' + formatTimeDisplay(bookingData.house_check_out_time);
                }
                houseInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-calendar-alt" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(pkgCheckIn) + ' &rarr; ' + escapeHtml(pkgCheckOut) + '</div>';
            }

            if (bookingData.house_guests) {
                houseInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-users" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.house_guests) + ' guest(s)</div>';
            }
            if (bookingData.house_guest_names) {
                houseInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-user-friends" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.house_guest_names) + '</div>';
            }
            if (bookingData.house_amount) {
                houseInfo += '<div style="font-size:12px; color:#10b981; font-weight:700; margin-top:4px;">₱' + parseFloat(bookingData.house_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</div>';
            }
            houseInfo += '</div>';
            details.push({ label: '🏠 House', value: houseInfo });
        }

        if (bookingData.tour_name) {
            let tourInfo = '<div style="background:#f0f7fb; border-left:3px solid #4DA6D9; border-radius:8px; padding:10px 12px; margin-bottom:8px; text-align:left;">';
            tourInfo += '<div style="font-weight:700; color:#0B2447; font-size:13px; margin-bottom:6px;"><i class="fas fa-umbrella-beach" style="color:#4DA6D9;"></i> ' + escapeHtml(bookingData.tour_name) + '</div>';
            if (bookingData.tour_date) {
                tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-calendar-alt" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(parseDateForDisplay(bookingData.tour_date)) + (bookingData.tour_time ? ' at ' + escapeHtml(formatTimeDisplay(bookingData.tour_time)) : '') + '</div>';
            }
            if (bookingData.tour_guests) {
                tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-users" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.tour_guests) + ' guest(s)</div>';
            }
            if (bookingData.tour_guest_name) {
                tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-user" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.tour_guest_name) + '</div>';
            }
            if (bookingData.tour_contact) {
                tourInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-phone" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.tour_contact) + '</div>';
            }
            if (bookingData.tour_amount) {
                tourInfo += '<div style="font-size:12px; color:#10b981; font-weight:700; margin-top:4px;">₱' + parseFloat(bookingData.tour_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</div>';
            }
            tourInfo += '</div>';
            details.push({ label: '🏖️ Tour', value: tourInfo });
        }

        if (bookingData.food_name) {
            let foodInfo = '<div style="background:#f0f7fb; border-left:3px solid #4DA6D9; border-radius:8px; padding:10px 12px; margin-bottom:8px; text-align:left;">';
            foodInfo += '<div style="font-weight:700; color:#0B2447; font-size:13px; margin-bottom:6px;"><i class="fas fa-utensils" style="color:#4DA6D9;"></i> ' + escapeHtml(bookingData.food_name) + '</div>';
            if (bookingData.food_size) {
                foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-arrows-alt-h" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.food_size) + '</div>';
            }
            if (bookingData.food_date) {
                foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-calendar-alt" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(parseDateForDisplay(bookingData.food_date)) + (bookingData.food_time ? ' at ' + escapeHtml(formatTimeDisplay(bookingData.food_time)) : '') + '</div>';
            }
            if (bookingData.food_persons) {
                foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-users" style="color:#4DA6D9; width:14px;"></i> ' + escapeHtml(bookingData.food_persons) + ' person(s)</div>';
            }
            if (bookingData.food_qty) {
                foodInfo += '<div style="font-size:12px; color:#475569; margin-bottom:3px;"><i class="fas fa-shopping-bag" style="color:#4DA6D9; width:14px;"></i> Qty: ' + escapeHtml(bookingData.food_qty) + '</div>';
            }
            if (bookingData.food_amount) {
                foodInfo += '<div style="font-size:12px; color:#10b981; font-weight:700; margin-top:4px;">₱' + parseFloat(bookingData.food_amount).toLocaleString('en-US', {minimumFractionDigits:2}) + '</div>';
            }
            foodInfo += '</div>';
            details.push({ label: '🍽️ Food', value: foodInfo });
        }

        if (bookingData.contact_number) {
            details.push({ label: 'Contact Number', value: escapeHtml(bookingData.contact_number) });
        }
        if (bookingData.special_requests) {
            details.push({ label: 'Special Requests', value: '<em style="color:#475569;">' + escapeHtml(bookingData.special_requests) + '</em>' });
        }
    }
    
    details.push({ label: 'Total Amount', value: '<strong style="color:#10b981; font-size:16px;">₱' + parseFloat(bookingData.grand_total || bookingData.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) + '</strong>' });
    
    let paymentBadge = '';
    if (bookingData.payment_status === 'paid') paymentBadge = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Paid</span>';
    else if (bookingData.payment_status === 'pending') paymentBadge = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>';
    else if (bookingData.payment_status === 'cancelled') paymentBadge = '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelled</span>';
    else paymentBadge = '<span class="badge badge-info">' + escapeHtml(bookingData.payment_status || 'N/A') + '</span>';
    details.push({ label: 'Payment Status', value: paymentBadge });
    
    let statusBadge = '';
    if (bookingData.booking_status === 'confirmed') statusBadge = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Confirmed</span>';
    else if (bookingData.booking_status === 'pending') statusBadge = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>';
    else if (bookingData.booking_status === 'completed') statusBadge = '<span class="badge badge-success"><i class="fas fa-check-double"></i> Completed</span>';
    else if (bookingData.booking_status === 'cancelled') statusBadge = '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelled</span>';
    else statusBadge = '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>';
    details.push({ label: 'Booking Status', value: statusBadge });
    
    if (bookingData.created_at) {
        var bookedDate = new Date(bookingData.created_at);
        if (isNaN(bookedDate.getTime())) {
            var parts = bookingData.created_at.split(' ');
            var dateParts = parts[0] ? parts[0].split('-') : [];
            var timeParts = parts[1] ? parts[1].split(':') : [];
            if (dateParts.length === 3) {
                bookedDate = new Date(parseInt(dateParts[0]), parseInt(dateParts[1]) - 1, parseInt(dateParts[2]), parseInt(timeParts[0] || 0), parseInt(timeParts[1] || 0));
                details.push({ label: 'Submitted On', value: bookedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + ' at ' + bookedDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) });
            }
        } else {
            details.push({ label: 'Submitted On', value: bookedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) + ' at ' + bookedDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) });
        }
    }
    
    let html = proofBlockHtml;
    details.forEach(item => {
        html += `<div class="booking-detail-item"><span class="label"><i class="fas fa-chevron-right" style="color: #4DA6D9; font-size: 10px;"></i> ${item.label}</span><span class="value">${item.value}</span></div>`;
    });
    
    document.getElementById('viewBookingContent').innerHTML = html;
    document.getElementById('viewBookingModal').classList.add('show');
    document.body.style.overflow = 'hidden';
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
    ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.select();
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
    overlay.onclick = function() { document.body.removeChild(overlay); document.body.style.overflow = 'auto'; };
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
}

function closeViewBookingModal() {
    document.getElementById('viewBookingModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// REBOOK POLICY POPUP
// ============================================================
var rebookBookingId = null;

function showRebookPolicyPopup(bookingId, houseName, reference, remainingRebooks, daysRemaining) {
    rebookBookingId = bookingId;
    document.getElementById('popupHouseName').textContent = houseName;
    document.getElementById('popupReference').textContent = reference;

    const used = 2 - remainingRebooks;
    document.getElementById('popupRebookCount').textContent = used;
    document.getElementById('popupDaysRemaining').textContent = daysRemaining;

    const bar = document.getElementById('popupRebookBar');
    if (bar) bar.style.width = (used / 2 * 100) + '%';

    document.getElementById('rebookPolicyPopup').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeRebookPolicyPopup() {
    document.getElementById('rebookPolicyPopup').classList.remove('show');
    document.body.style.overflow = 'auto';
    rebookBookingId = null;
}

function proceedToRebook() {
    if (rebookBookingId) {
        window.location.href = 'rebook.php?booking_id=' + rebookBookingId;
    } else {
        alert('Booking ID not found. Please try again.');
    }
}

// ============================================================
// TAB SWITCHING
// ============================================================
function showTab(tab) {
    ['houses', 'tours', 'food', 'packages', 'rebooks', 'history'].forEach(t => {
        const el = document.getElementById(t + '-tab');
        if (el) el.style.display = 'none';
    });
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    
    const tabMap = {'houses':0, 'tours':1, 'food':2, 'packages':3, 'rebooks':4, 'history':5};
    const target = document.getElementById(tab + '-tab');
    const buttons = document.querySelectorAll('.tab');
    
    if (target) target.style.display = 'block';
    if (tabMap[tab] !== undefined && buttons[tabMap[tab]]) {
        buttons[tabMap[tab]].classList.add('active');
    }
}

// ============================================================
// PAYMENT MODAL
// ============================================================
function openPaymentModal(type, id, amount, reference) {
    document.getElementById('modal_booking_type').value = type;
    document.getElementById('modal_booking_id').value = id;
    document.getElementById('modal_reference').value = reference;
    document.getElementById('modal_amount').innerHTML = '₱' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits:2});

    var gcashRefInput = document.getElementById('modal_gcash_reference');
    if (gcashRefInput) gcashRefInput.value = '';

    removeProof();

    document.getElementById('paymentModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// PAYMENT PROOF
// ============================================================
var proofCameraStream = null;

document.addEventListener('DOMContentLoaded', function() {
    var fileInput = document.getElementById('payment_proof_input');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                var file = this.files[0];
                document.getElementById('payment_proof_base64').value = '';

                if (file.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function(ev) {
                        var box = document.getElementById('proofPreviewBox');
                        box.innerHTML = '<img src="' + ev.target.result + '" style="max-width:100%;max-height:250px;border-radius:10px;border:2px solid #10b981;">';
                        box.style.borderColor = '#10b981';
                        box.style.borderStyle = 'solid';
                    };
                    reader.readAsDataURL(file);
                } else {
                    var box = document.getElementById('proofPreviewBox');
                    box.innerHTML = '<i class="fas fa-file-pdf" style="font-size:48px;color:#dc2626;margin-bottom:8px;"></i><span style="color:#1e293b;font-weight:600;">' + file.name + '</span>';
                    box.style.borderColor = '#10b981';
                    box.style.borderStyle = 'solid';
                }

                document.getElementById('removeProofBtn').style.display = 'flex';
            }
        });
    }

    var proofForm = document.getElementById('uploadProofForm');
    if (proofForm) {
        proofForm.addEventListener('submit', function(e) {
            var gcashRef = document.getElementById('modal_gcash_reference').value.trim();
            var hasFile = document.getElementById('payment_proof_input').files.length > 0;
            var hasBase64 = document.getElementById('payment_proof_base64').value.trim().length > 0;

            if (!gcashRef || gcashRef.length < 6) {
                e.preventDefault();
                alert('Please enter a valid GCash reference number (at least 6 digits).');
                document.getElementById('modal_gcash_reference').focus();
                return false;
            }

            if (!hasFile && !hasBase64) {
                e.preventDefault();
                alert('Please upload a payment proof OR take a photo.');
                return false;
            }

            setTimeout(function() {
                var btn = document.getElementById('submitProofBtn');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                }
            }, 50);

            return true;
        });
    }
});

function removeProof(event) {
    if (event) event.stopPropagation();
    var proofInput = document.getElementById('payment_proof_input');
    var proofBase64 = document.getElementById('payment_proof_base64');
    if (proofInput) proofInput.value = '';
    if (proofBase64) proofBase64.value = '';

    var box = document.getElementById('proofPreviewBox');
    if (box) {
        box.innerHTML = '<i class="fas fa-cloud-upload-alt" style="font-size:36px;color:#94a3b8;margin-bottom:8px;"></i><span style="color:#64748b;font-weight:600;font-size:13px;">Tap to upload a screenshot</span><span style="color:#94a3b8;font-size:11px;margin-top:4px;">JPG, PNG, or PDF</span>';
        box.style.borderColor = '#cbd5e1';
        box.style.borderStyle = 'dashed';
    }

    var removeBtn = document.getElementById('removeProofBtn');
    if (removeBtn) removeBtn.style.display = 'none';
}

function openProofCamera() {
    var modal = document.getElementById('proofCameraModal');
    var video = document.getElementById('proofCameraVideo');

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';

    navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
    })
    .then(function(stream) {
        proofCameraStream = stream;
        video.srcObject = stream;
        video.play();
    })
    .catch(function(err) {
        alert('Unable to access camera. Please allow camera access or upload a file instead.\n\n' + err.message);
        closeProofCamera();
    });
}

function closeProofCamera() {
    var modal = document.getElementById('proofCameraModal');
    var video = document.getElementById('proofCameraVideo');

    if (proofCameraStream) {
        proofCameraStream.getTracks().forEach(function(track) { track.stop(); });
        proofCameraStream = null;
    }
    if (video) video.srcObject = null;
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

function captureProofPhoto() {
    var video = document.getElementById('proofCameraVideo');
    var canvas = document.createElement('canvas');

    if (!video.videoWidth || !video.videoHeight) {
        alert('Camera is not ready yet. Please wait a moment and try again.');
        return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    var dataUrl = canvas.toDataURL('image/jpeg', 0.9);

    document.getElementById('payment_proof_base64').value = dataUrl;
    document.getElementById('payment_proof_input').value = '';

    var box = document.getElementById('proofPreviewBox');
    box.innerHTML = '<img src="' + dataUrl + '" style="max-width:100%;max-height:250px;border-radius:10px;border:2px solid #10b981;">';
    box.style.borderColor = '#10b981';
    box.style.borderStyle = 'solid';

    document.getElementById('removeProofBtn').style.display = 'flex';

    closeProofCamera();
}

// ============================================================
// CANCEL REBOOK MODAL
// ============================================================
function cancelRebook(bookingId, reference) {
    document.getElementById('cancel_rebook_booking_id').value = bookingId;
    document.getElementById('cancel_rebook_reference').textContent = reference;
    document.getElementById('cancelRebookModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeCancelRebookModal() {
    document.getElementById('cancelRebookModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// CANCEL UNPAID BOOKING MODAL
// ============================================================
function openCancelBookingModal(type, bookingId, reference) {
    document.getElementById('cancel_booking_type').value = type;
    document.getElementById('cancel_booking_id').value = bookingId;
    document.getElementById('cancel_booking_reference').textContent = reference;
    document.getElementById('cancelBookingModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeCancelBookingModal() {
    document.getElementById('cancelBookingModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('DOMContentLoaded', function() {
    const m = document.getElementById('cancelBookingModal');
    if (m) m.addEventListener('click', function(e) { if (e.target === this) closeCancelBookingModal(); });
});

// ============================================================
// FEEDBACK MODALS
// ============================================================
var selectedRating = 0;
var ratingTexts = {1:'Very Poor',2:'Poor',3:'Average',4:'Good',5:'Excellent!'};
var editSelectedRating = 0;

function openFeedbackModal(type, id, reference, itemName) {
    document.getElementById('feedback_booking_type').value = type;
    document.getElementById('feedback_booking_id').value = id;
    document.getElementById('feedback_reference').textContent = reference;
    document.getElementById('feedback_item_name').textContent = itemName;
    document.getElementById('feedback_text').value = '';
    document.getElementById('feedback_rating').value = 0;
    document.getElementById('feedback_is_anonymous').checked = false;
    selectedRating = 0;
    document.getElementById('ratingText').textContent = 'Select a rating';
    document.querySelectorAll('#ratingStars i').forEach(star => star.classList.remove('active'));
    document.getElementById('feedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editFeedback(type, id, reference, itemName, existingRating, existingText, isAnonymous) {
    document.getElementById('edit_feedback_booking_type').value = type;
    document.getElementById('edit_feedback_booking_id').value = id;
    document.getElementById('edit_feedback_reference').textContent = reference;
    document.getElementById('edit_feedback_item_name').textContent = itemName;
    document.getElementById('edit_feedback_text').value = existingText || '';
    document.getElementById('edit_feedback_rating').value = existingRating;
    document.getElementById('edit_feedback_is_anonymous').checked = isAnonymous === true || isAnonymous === 'true';
    editSelectedRating = existingRating;
    document.getElementById('editRatingText').textContent = ratingTexts[existingRating] || 'Select a rating';
    document.getElementById('editFeedbackSubmitBtn').name = type == 'house' ? 'edit_house_feedback' : (type == 'tour' ? 'edit_tour_feedback' : 'edit_food_feedback');
    document.querySelectorAll('#editRatingStars i').forEach(function(star, index) {
        if (index < existingRating) star.classList.add('active');
        else star.classList.remove('active');
    });
    document.getElementById('editFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeEditFeedbackModal() {
    document.getElementById('editFeedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function setEditRating(rating) {
    editSelectedRating = rating;
    document.getElementById('edit_feedback_rating').value = rating;
    document.getElementById('editRatingText').textContent = ratingTexts[rating] || 'Select a rating';
    document.querySelectorAll('#editRatingStars i').forEach(function(star, index) {
        if (index < rating) star.classList.add('active');
        else star.classList.remove('active');
    });
}

function closeFeedbackModal() {
    document.getElementById('feedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function setRating(rating) {
    selectedRating = rating;
    document.getElementById('feedback_rating').value = rating;
    document.getElementById('ratingText').textContent = ratingTexts[rating] || 'Select a rating';
    document.querySelectorAll('#ratingStars i').forEach(function(star, index) {
        if (index < rating) star.classList.add('active');
        else star.classList.remove('active');
    });
}

function viewFeedback(text, rating, reference) {
    document.getElementById('view_feedback_reference').textContent = reference;
    document.getElementById('view_feedback_text').textContent = text || 'No feedback text provided.';
    var starsHtml = '';
    for (var i = 1; i <= 5; i++) {
        if (i <= rating) starsHtml += '<i class="fas fa-star" style="color:#f59e0b;"></i>';
        else starsHtml += '<i class="far fa-star" style="color:#f59e0b;"></i>';
    }
    document.getElementById('view_feedback_stars').innerHTML = starsHtml + ' (' + rating + '/5)';
    document.getElementById('viewFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeViewFeedbackModal() {
    document.getElementById('viewFeedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// OVERALL FEEDBACK
// ============================================================
var overallSelectedRating = <?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>;
var overallRatingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

function openOverallFeedbackModal(event) {
    if (event) event.preventDefault();
    var sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) toggleSidebar();

    syncBigRatingStars(overallSelectedRating);
    var wordEl = document.getElementById('bigRatingWord');
    if (wordEl) {
        wordEl.textContent = overallSelectedRating > 0
            ? overallRatingTexts[overallSelectedRating]
            : 'Select a rating';
    }

    document.getElementById('overallFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeOverallFeedbackModal() {
    document.getElementById('overallFeedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
    previewBigRating(0);
    syncBigRatingStars(overallSelectedRating);
}

function setBigRating(rating) {
    overallSelectedRating = rating;
    var hidden = document.getElementById('overall_feedback_rating');
    if (hidden) hidden.value = rating;
    var wordEl = document.getElementById('bigRatingWord');
    if (wordEl) wordEl.textContent = overallRatingTexts[rating] || 'Select a rating';
    syncBigRatingStars(rating);
}

function previewBigRating(rating) {
    var stars = document.querySelectorAll('#bigRatingInput i');
    stars.forEach(function(star, index) {
        star.classList.remove('preview');
        if (rating > 0 && index < rating) star.classList.add('preview');
    });
    if (rating > 0) {
        var wordEl = document.getElementById('bigRatingWord');
        if (wordEl) wordEl.textContent = overallRatingTexts[rating] || 'Select a rating';
    }
}

function syncBigRatingStars(rating) {
    var stars = document.querySelectorAll('#bigRatingInput i');
    stars.forEach(function(star, index) {
        star.classList.remove('preview');
        if (index < rating) star.classList.add('active');
        else star.classList.remove('active');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var container = document.getElementById('bigRatingInput');
    if (container) {
        container.addEventListener('mouseleave', function() {
            previewBigRating(0);
            syncBigRatingStars(overallSelectedRating);
            var wordEl = document.getElementById('bigRatingWord');
            if (wordEl) {
                wordEl.textContent = overallSelectedRating > 0
                    ? overallRatingTexts[overallSelectedRating]
                    : 'Select a rating';
            }
        });
    }
});

// ============================================================
// CLOSE MODALS ON OUTSIDE CLICK
// ============================================================
window.onclick = function(event) {
    if(event.target.classList.contains('modal')) {
        if (event.target.id === 'termsModal') {
            var termsModal = document.getElementById('termsModal');
            if (termsModal.dataset.mustAccept === '1') return;
            termsModal.classList.remove('show');
            document.body.style.overflow = 'auto';
            return;
        }
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
    if(event.target.classList.contains('rebook-policy-overlay')) {
        closeRebookPolicyPopup();
    }
}

document.addEventListener('keydown', function(event) {
    if(event.key === 'Escape') {
        var termsModal = document.getElementById('termsModal');
        if (termsModal && termsModal.classList.contains('show') && termsModal.dataset.mustAccept === '1') {
            event.stopPropagation(); event.preventDefault(); return;
        }
        var logoutModal = document.getElementById('logoutModal');
        if (logoutModal && logoutModal.classList.contains('show')) {
            closeLogoutModal(); return;
        }
        document.querySelectorAll('.modal.show').forEach(function(modal) { modal.classList.remove('show'); });
        closeRebookPolicyPopup();
        closeViewBookingModal();
        closeCancelRebookModal();
        closeCancelBookingModal();
        document.body.style.overflow = 'auto';
    }
});

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        alert.style.transition = 'opacity 0.5s';
        setTimeout(function() { alert.remove(); }, 500);
    });
}, 5000);

// ============================================================
// ✅ TAB INITIALIZATION
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    <?php if($user_has_feedback && $user_feedback['rating'] > 0): ?>
        var rating = <?php echo (int)$user_feedback['rating']; ?>;
        document.querySelectorAll('#overallRatingInput i').forEach(function(star, index) {
            if (index < rating) star.classList.add('active');
            else star.classList.remove('active');
        });
        document.getElementById('overallRatingText').textContent = overallRatingTexts[rating] || 'Select a rating';
    <?php endif; ?>

    var urlParams = new URLSearchParams(window.location.search);
    var requestedTab = urlParams.get('tab') || DEFAULT_TAB;
    var validTabs = ['houses', 'tours', 'food', 'packages', 'rebooks', 'history'];

    if (requestedTab && validTabs.indexOf(requestedTab) !== -1) {
        showTab(requestedTab);
    } else {
        showTab('houses');
    }
});

// ============================================================
// TERMS MODAL LOGIC
// ============================================================
var __termsState = { modal: null, mustAccept: false, hasReachedBottom: false };

(function () {
    var modal = document.getElementById('termsModal');
    if (!modal) return;

    __termsState.modal = modal;
    __termsState.mustAccept = modal.dataset.mustAccept === '1';

    var closeBtn   = document.getElementById('termsModalCloseBtn');
    var acceptForm = document.getElementById('termsAcceptForm');
    var scrollHint = document.getElementById('termsScrollHint');
    var scrollBox  = document.getElementById('termsScrollContainer');
    var checkbox   = document.getElementById('terms_agree');
    var acceptBtn  = document.getElementById('termsAcceptBtn');
    var titleEl    = document.getElementById('termsModalTitle');
    var subtitleEl = document.getElementById('termsModalSubtitle');

    function unlockAcceptForm() {
        if (!__termsState.mustAccept) return;
        if (__termsState.hasReachedBottom) return;
        __termsState.hasReachedBottom = true;
        acceptForm.classList.add('visible');
        checkbox.disabled = false;
        scrollHint.classList.add('done');
        scrollHint.innerHTML = '<i class="fas fa-check-circle"></i> You\'ve read everything. Please check the box below.';
        setTimeout(function() {
            scrollBox.scrollTo({ top: scrollBox.scrollHeight, behavior: 'smooth' });
        }, 100);
    }

    function wireScrollListener() {
        if (!scrollBox) return;
        if (scrollBox.dataset.scrollWired === '1') return;
        scrollBox.dataset.scrollWired = '1';
        var needsScroll = scrollBox.scrollHeight > scrollBox.clientHeight + 5;
        if (!needsScroll) unlockAcceptForm();
        scrollBox.addEventListener('scroll', function () {
            var atBottom = (scrollBox.scrollTop + scrollBox.clientHeight) >= (scrollBox.scrollHeight - 15);
            if (atBottom) unlockAcceptForm();
        });
    }

    window.openTermsMustAccept = function () {
        __termsState.mustAccept = true;
        __termsState.hasReachedBottom = false;
        modal.dataset.mustAccept = '1';
        titleEl.textContent = 'Before You Continue';
        subtitleEl.textContent = 'Please review our Terms & Privacy Policy';
        closeBtn.style.display = 'none';
        scrollHint.style.display = 'block';
        scrollHint.classList.remove('done');
        scrollHint.innerHTML = '<i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox';
        acceptForm.classList.remove('visible');
        checkbox.disabled = true;
        checkbox.checked = false;
        acceptBtn.disabled = true;
        scrollBox.scrollTop = 0;
        wireScrollListener();
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    };

    window.openTermsReadOnly = function () {
        __termsState.mustAccept = false;
        modal.dataset.mustAccept = '0';
        titleEl.textContent = 'Terms & Privacy Policy';
        subtitleEl.textContent = 'Review our policies at any time';
        closeBtn.style.display = 'flex';
        scrollHint.style.display = 'none';
        acceptForm.classList.remove('visible');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    };

    window.closeTermsReadOnly = function () {
        if (__termsState.mustAccept) return;
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    };

    modal.addEventListener('click', function (e) {
        if (e.target === modal && __termsState.mustAccept) e.stopPropagation();
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('show') && __termsState.mustAccept) {
            e.stopPropagation(); e.preventDefault();
        }
    }, true);

    checkbox.addEventListener('change', function () {
        acceptBtn.disabled = !checkbox.checked;
    });

    if (__termsState.mustAccept) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        wireScrollListener();
    } else {
        wireScrollListener();
    }
})();

function reopenTermsModal(tabName) {
    var modal = document.getElementById('termsModal');
    if (!modal) return false;
    var mustAccept = modal.dataset.mustAccept === '1';
    if (mustAccept) {
        if (typeof window.openTermsMustAccept === 'function') window.openTermsMustAccept();
    } else {
        if (typeof window.openTermsReadOnly === 'function') window.openTermsReadOnly();
    }
    return false;
}

// ============================================================
// ✅ SCROLL POSITION PRESERVATION FIX
// ============================================================
(function() {
    var SCROLL_KEY = 'profile_scroll_position_' + (window.location.pathname || 'default');

    function restoreScrollPosition() {
        var savedScroll = sessionStorage.getItem(SCROLL_KEY);
        if (savedScroll !== null) {
            var scrollY = parseInt(savedScroll, 10);
            if (!isNaN(scrollY) && scrollY > 0) {
                window.scrollTo(0, scrollY);
                setTimeout(function() { window.scrollTo(0, scrollY); }, 50);
                setTimeout(function() { window.scrollTo(0, scrollY); }, 200);
                setTimeout(function() { window.scrollTo(0, scrollY); }, 400);
            }
            sessionStorage.removeItem(SCROLL_KEY);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreScrollPosition);
    } else {
        restoreScrollPosition();
    }
    window.addEventListener('load', restoreScrollPosition);

    document.addEventListener('submit', function(e) {
        try {
            sessionStorage.setItem(SCROLL_KEY, window.scrollY || window.pageYOffset || 0);
        } catch(err) { }
    }, true);

    document.addEventListener('click', function(e) {
        var target = e.target.closest('a');
        if (target && target.href && target.href.indexOf('?') !== -1) {
            try {
                if (target.hostname === window.location.hostname) {
                    sessionStorage.setItem(SCROLL_KEY, window.scrollY || window.pageYOffset || 0);
                }
            } catch(err) { }
        }
    }, true);
})();
</script>

</body>
</html>
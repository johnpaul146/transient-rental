<?php
session_start();
require_once 'database.php';
require_once 'includes/TermsGate.php';
TermsGate::enforceGuest($pdo); // Terms & Privacy must be accepted before guest features

// ✅ NEW: Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

require_once 'includes/PaymentService.php';

// ✨ TERMS: Load the terms gate
require_once 'includes/TermsGate.php';
$termsGate = new TermsGate($pdo);

// 🔒 Helper: real client IP (used by terms acceptance)
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
// ✅ BLOCKING STATUSES — ONLY these block dates (turn red)
//    Pending bookings DO NOT block other guests
//    Applies to: houses, tours, AND food
// ============================================================
$blocking_statuses = ['confirmed', 'approved', 'completed'];
$blocking_placeholders = "'" . implode("','", $blocking_statuses) . "'";

// ============================================================
// ✅ AUTO-CREATE blocked_dates TABLE (safety)
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

// Get dynamic content
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

// Get logo path
$logo_path = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
}

// Get hero image
$hero_path = 'uploads/hero/hero-bg.jpg';
$home_hero_path = $content['site_settings']['hero_image_path'] ?? $hero_path;
if (!empty($home_hero_path) && file_exists($home_hero_path) && !is_dir($home_hero_path)) {
    $hero_path = $home_hero_path;
}
$page_hero_filename = basename((string)($content['site_settings']['houses_hero_image'] ?? ''));
$page_hero_path = 'uploads/hero/houses/' . $page_hero_filename;
if ($page_hero_filename !== '' && file_exists($page_hero_path) && !is_dir($page_hero_path)) {
    $hero_path = $page_hero_path;
}
$hero_exists = !empty($hero_path) && file_exists($hero_path) && !is_dir($hero_path);

// Get GCash settings
$gcash_settings = [];
$stmt = $pdo->query("SELECT content_key, content_value FROM site_content WHERE section_name = 'gcash'");
while($row = $stmt->fetch()) {
    $gcash_settings[$row['content_key']] = $row['content_value'];
}

$gcash_cfg = PaymentService::gcashConfig($pdo);
$gcash_configured = $gcash_cfg['configured'];   // placeholders are never shown to guests
$gcash_name = $gcash_settings['account_name'] ?? '';
$gcash_number = $gcash_settings['number'] ?? '';
$gcash_qr = $gcash_settings['qr_code'] ?? '';
$gcash_instructions = $gcash_settings['instructions'] ?? "1. Open GCash app\n2. Click 'Pay QR' or 'Scan QR'\n3. Scan the QR code above\n4. Enter the exact amount shown\n5. Complete the payment\n6. Take a screenshot of the transaction\n7. Upload screenshot as proof of payment";

// ✅ Get Booking Terms content from database (editable via edit-content.php)
$bookingTermsTitle = $content['booking_terms']['title'] ?? 'Booking Terms & Conditions';
$bookingTermsBody = $content['booking_terms']['body'] ?? '';

// Fallback default if empty
if (empty(trim($bookingTermsBody))) {
    $bookingTermsBody = '<h3>📋 Booking Policy</h3>
<p>By confirming your booking, you agree to the following terms:</p>

<h3>💳 Reservation Fee &amp; Payment</h3>
<ul>
    <li>A <strong>₱1,000 reservation fee</strong> is required to secure your booking.</li>
    <li>The reservation fee <strong>forms part of the total booking amount</strong>.</li>
    <li>The <strong>remaining balance is payable upon arrival / check-in</strong>.</li>
    <li>Upload your GCash reference and screenshot of the reservation fee through your profile.</li>
</ul>

<h3>❌ Cancellation Policy</h3>
<ul>
    <li><strong>Reservation fees are non-refundable.</strong></li>
    <li>Unpaid reservations may be cancelled at no charge.</li>
    <li>Paid reservations cannot be cancelled for a refund, but they <strong>may be rebooked</strong> subject to availability and the rebooking rules. The amount already paid is carried forward.</li>
    <li>No-shows forfeit the reservation fee.</li>
</ul>

<h3>🔄 Rebooking Policy</h3>
<ul>
    <li>House, Tour, Food and Package bookings that are <strong>confirmed or cancelled with money received</strong> may be rebooked instead of cancelled. <strong>Completed bookings cannot be rebooked.</strong></li>
    <li>Rebooking is allowed a maximum of <strong>2 times per booking</strong>.</li>
    <li>Rebooking must be requested within <strong>7 days from booking date</strong>. The amount already paid is carried forward — no second reservation fee and no refund.</li>
    <li>The <strong>stay duration (number of nights) must remain the same</strong> when rebooking — dates may change, but the length of stay cannot be shortened or extended.</li>
    <li>Rebooking is subject to <strong>admin approval</strong> and availability.</li>
    <li>Your reservation fee is <strong>carried forward</strong> — no second reservation fee is charged.</li>
    <li>If the new dates are unavailable, you may choose different dates within the allowed rebook window.</li>
</ul>

<h3>⏳ Unpaid Reservations</h3>
<ul>
    <li>Bookings without a confirmed reservation fee may be cancelled by the owner.</li>
</ul>

<h3>👥 Guest Policy</h3>
<ul>
    <li>The number of guests must not exceed the <strong>declared pax count</strong>.</li>
    <li>All guest names must be <strong>accurate and complete</strong>.</li>
    <li>Additional guests beyond the declared pax will not be accommodated.</li>
</ul>

<h3>✅ Acknowledgment</h3>
<p>By checking the box below and clicking "Accept &amp; Confirm Booking", you acknowledge that you have read, understood, and agreed to these terms — including the <strong>non-refundable reservation fee</strong> and <strong>rebook-only option</strong> for paid reservations.</p>';
}

// Generate reference number
function generateReferenceNumber($prefix = 'HS') {
    return $prefix . '-' . date('Ymd') . '-' . rand(1000, 9999);
}

// ============================================================
// ✨ TERMS: Handle acceptance submission
// ============================================================
if (isset($_POST['accept_terms']) && isset($_SESSION['user_id'])) {
    $termsGate->accept((int)$_SESSION['user_id'], getClientIp());
    unset($_SESSION['show_terms_modal']);
    $_SESSION['terms_accepted'] = true;
    header("Location: houses.php?terms_accepted=1");
    exit();
}

// ============================================================
// HANDLE HOUSE BOOKING
// ============================================================
if(isset($_POST['logged_booking']) && isset($_SESSION['user_id'])) {
    try {
        $pdo->beginTransaction();
        
        $dateErr = AvailabilityService::futureDateError($_POST['check_in'] ?? '');
        if ($dateErr !== null) throw new Exception($dateErr);
        $dateErr = AvailabilityService::futureDateError($_POST['check_out'] ?? '');
        if ($dateErr !== null) throw new Exception($dateErr);
        $check_in = new DateTime($_POST['check_in']);
        $check_out = new DateTime($_POST['check_out']);
        
        if($check_out <= $check_in) throw new Exception("Check-out must be after check-in");
        
        // Check-out TIME always follows check-in TIME (AvailabilityService::houseStayTimes).
        // A posted check_out_time is ignored, so a forged value is never saved.
        try {
            $stayTimes = AvailabilityService::houseStayTimes($_POST['check_in_time'] ?? '14:00');
        } catch (InvalidArgumentException $e) {
            throw new Exception("Invalid check-in time format");
        }
        $check_in_time_db  = $stayTimes['in'];
        $check_out_time_db = $stayTimes['out'];
        $check_in_time  = substr($check_in_time_db, 0, 5);
        $check_out_time = substr($check_out_time_db, 0, 5);

        // Same rule everywhere (AvailabilityService): confirmed/completed stays hold the
        // nights [check-in, check-out); blocked dates count for those nights only.
        // Pending reservations may overlap — the first confirmed fee wins, and the
        // check is repeated when the fee is confirmed.
        $house_conflict = AvailabilityService::houseConflict($pdo, (int)$_POST['house_id'], $_POST['check_in'], $_POST['check_out'], [], [], $check_in_time_db, $check_out_time_db);
        if ($house_conflict !== null) {
            throw new Exception("Selected dates are not available — " . $house_conflict . " Please choose different dates.");
        }

        $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
        $guest_stmt->execute([$_SESSION['user_id']]);
        $guest = $guest_stmt->fetch();
        
        if(!$guest) {
            throw new Exception("Guest profile not found. Please complete your profile.");
        }
        
        $guest_id = $guest['id'];
        
        $house = $pdo->prepare("SELECT price_per_night FROM houses WHERE id = ?");
        $house->execute([$_POST['house_id']]);
        $price = $house->fetchColumn();
        
        $nights = $check_out->diff($check_in)->days;
        $total = $price * $nights;
        $reference = generateReferenceNumber();
        
        $guests = intval($_POST['guests']);
        if($guests < 1) $guests = 1;
        if($guests > 20) $guests = 20;
        
        // ✅ SERVER-SIDE VALIDATION: Guest names must match pax and contain only letters
        $guest_names = isset($_POST['guest_names']) ? trim($_POST['guest_names']) : '';
        
        // Parse guest names (one per line)
        $names_array = array_values(array_filter(array_map('trim', explode("\n", $guest_names))));
        $names_count = count($names_array);
        
        if($names_count !== $guests) {
            throw new Exception("Please provide exactly " . $guests . " guest name(s). You entered " . $names_count . ".");
        }
        
        // ✅ Validate each name: only letters, spaces, hyphens, periods, apostrophes
        foreach($names_array as $name) {
            if(!preg_match("/^[a-zA-ZÀ-ÿ\s\-'.]+$/u", $name)) {
                throw new Exception("Guest names can only contain letters, spaces, hyphens, periods, and apostrophes. Invalid name: " . $name);
            }
            if(strlen($name) < 2) {
                throw new Exception("Guest name is too short: " . $name);
            }
        }
        
        // Store as newline-separated
        $guest_names_clean = implode("\n", $names_array);
        
        // ✅ UPDATED: Include check_in_time & check_out_time
        // ✅ NEW: booking_status = 'pending' (not 'confirmed' — admin must confirm)
        $stmt = $pdo->prepare("INSERT INTO house_bookings 
            (guest_id, house_id, reference_number, check_in_date, check_in_time, check_out_date, check_out_time, 
             number_of_guests, total_amount, reservation_fee_amount, guest_names, payment_status, booking_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())");
        $stmt->execute([
            $guest_id, 
            $_POST['house_id'], 
            $reference, 
            $_POST['check_in'], 
            $check_in_time_db,
            $_POST['check_out'], 
            $check_out_time_db,
            $guests, 
            $total, 
            PaymentService::feeFor($total),
            $guest_names_clean
        ]);
        
        $booking_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // ✅ NEW: Log house booking
        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'create',
                'booking',
                "Guest booked house '{$_POST['house_name']}' (Ref: {$reference}) — ₱" . number_format($total, 2),
                $booking_id,
                'house_booking',
                null,
                [
                    'house_id' => $_POST['house_id'],
                    'house_name' => $_POST['house_name'],
                    'check_in' => $_POST['check_in'],
                    'check_in_time' => $check_in_time,
                    'check_out' => $_POST['check_out'],
                    'check_out_time' => $check_out_time,
                    'guests' => $guests,
                    'nights' => $nights,
                    'total' => $total,
                    'reference' => $reference
                ]
            );
        }
        
        $_SESSION['last_booking'] = [
            'reference' => $reference,
            'total' => $total,
            'house_name' => $_POST['house_name'],
            'check_in' => $_POST['check_in'],
            'check_in_time' => $check_in_time,
            'check_out' => $_POST['check_out'],
            'check_out_time' => $check_out_time
        ];
        
        $success = "Booking received! Your reference number is: " . $reference . ". Pay the reservation fee of " . PaymentService::peso(PaymentService::feeFor($total)) . " to secure it.";
        
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Booking failed: " . $e->getMessage();
    }
}

// Handle per-booking feedback submission
if(isset($_POST['submit_house_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $booking_id = $_POST['booking_id'];
        $rating = $_POST['rating'];
        $feedback = $_POST['feedback'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE house_bookings SET feedback_rating = ?, feedback_text = ?, feedback_date = NOW(), feedback_is_anonymous = ? WHERE id = ?");
        $stmt->execute([$rating, $feedback, $is_anonymous, $booking_id]);
        
        // ✅ NEW: Log per-booking feedback
        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'create',
                'review',
                "Guest submitted house feedback — Rating: {$rating}/5",
                $booking_id,
                'house_booking',
                null,
                ['rating' => $rating, 'anonymous' => $is_anonymous]
            );
        }
        
        $success = "Thank you for your feedback!";
        
    } catch(Exception $e) {
        $error = "Failed to submit feedback: " . $e->getMessage();
    }
}

// ============================================================
// ✅ FIX: Handle OVERALL FEEDBACK submission (Edit Review modal)
// ============================================================
if(isset($_POST['submit_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $rating = intval($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        // Validate rating
        if ($rating < 1 || $rating > 5) {
            throw new Exception("Please select a valid rating (1-5 stars).");
        }

        // Check if user already has feedback
        $check = $pdo->prepare("SELECT id FROM overall_feedback WHERE user_id = ?");
        $check->execute([$_SESSION['user_id']]);
        $existing = $check->fetch();

        if ($existing) {
            // UPDATE existing review
            try {
                $stmt = $pdo->prepare("UPDATE overall_feedback 
                    SET rating = ?, comment = ?, is_anonymous = ?, updated_at = NOW() 
                    WHERE user_id = ?");
                $stmt->execute([$rating, $comment, $is_anonymous, $_SESSION['user_id']]);
            } catch (PDOException $pe) {
                $stmt = $pdo->prepare("UPDATE overall_feedback 
                    SET rating = ?, comment = ?, is_anonymous = ? 
                    WHERE user_id = ?");
                $stmt->execute([$rating, $comment, $is_anonymous, $_SESSION['user_id']]);
            }
            $action = 'update';
            $feedback_id = $existing['id'];
        } else {
            // INSERT new review
            try {
                $stmt = $pdo->prepare("INSERT INTO overall_feedback 
                    (user_id, rating, comment, is_anonymous, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$_SESSION['user_id'], $rating, $comment, $is_anonymous]);
            } catch (PDOException $pe) {
                $stmt = $pdo->prepare("INSERT INTO overall_feedback 
                    (user_id, rating, comment, is_anonymous, created_at) 
                    VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$_SESSION['user_id'], $rating, $comment, $is_anonymous]);
            }
            $action = 'create';
            $feedback_id = $pdo->lastInsertId();
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                $action,
                'review',
                "Guest " . ($action === 'update' ? 'updated' : 'submitted') . " overall feedback — Rating: {$rating}/5",
                $feedback_id,
                'overall_feedback',
                null,
                ['rating' => $rating, 'anonymous' => $is_anonymous, 'comment_length' => strlen($comment)]
            );
        }

        $_SESSION['feedback_success_msg'] = "Thank you! Your review has been " . ($action === 'update' ? 'updated' : 'submitted') . " successfully.";
        header("Location: houses.php?feedback_success=1");
        exit();

    } catch(Exception $e) {
        $error = "Failed to submit review: " . $e->getMessage();
    }
}

// Get houses
$houses = $pdo->query("SELECT * FROM houses ORDER BY id")->fetchAll();

// Get house galleries
$house_gallery = [];
foreach($houses as $house) {
    $stmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC");
    $stmt->execute([$house['id']]);
    $house_gallery[$house['id']] = $stmt->fetchAll();
}

// ============================================================
// ✅ FIXED: Get booked dates — only confirmed/approved/completed block
//    Pending bookings do NOT turn dates red
// ============================================================
$booked_dates = [];
foreach($houses as $house) {
    $stmt = $pdo->prepare("SELECT check_in_date, check_out_date FROM house_bookings WHERE house_id = ? AND booking_status IN ({$blocking_placeholders})");
    $stmt->execute([$house['id']]);
    $booked_dates[$house['id']] = $stmt->fetchAll();
}

// ============================================================
// ✅ NEW: Get BLOCKED dates for each house (from blocked_dates table)
// ============================================================
$blocked_dates_map = [];
foreach($houses as $house) {
    $stmt = $pdo->prepare("SELECT block_date, reason, block_type FROM blocked_dates WHERE item_type = 'house' AND item_id = ? AND block_date >= CURDATE() ORDER BY block_date ASC");
    $stmt->execute([$house['id']]);
    $blocked_dates_map[$house['id']] = $stmt->fetchAll();
}

// Get user bookings
$user_bookings = [];
if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] == 'guest') {
    $stmt = $pdo->prepare("SELECT b.*, h.house_name FROM house_bookings b JOIN houses h ON b.house_id = h.id JOIN guests g ON b.guest_id = g.id WHERE g.user_id = ? ORDER BY b.created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $user_bookings = $stmt->fetchAll();
}

// Check if user has overall feedback
$user_has_feedback = false;
$user_feedback = null;
if(isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_feedback = $stmt->fetch();
    $user_has_feedback = ($user_feedback !== false);
}

function getGoogleMapsUrl($address) {
    if (empty($address) || $address == '#') return '#';
    $encoded_address = urlencode($address);
    return 'https://www.google.com/maps/search/?api=1&query=' . $encoded_address;
}

$location_address = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';
$maps_url = getGoogleMapsUrl($location_address);

$default_map_url = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863.123456789!2d119.1234567!3d16.1234567!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTbCsDA3JzI0LjAiTiAxMTnCsDA3JzI0LjAiRQ!5e0!3m2!1sen!2sph!4v1234567890';
$map_embed = !empty($google_maps_embed) && $google_maps_embed != '#' ? $google_maps_embed : $default_map_url;

$facebook_link = $content['social']['facebook'] ?? '#';

function getHouseMainImage($house, $gallery) {
    if(!empty($house['image']) && $house['image'] != 'default-house.jpg') {
        $main_path = 'uploads/houses/' . $house['image'];
        $gallery_path = 'uploads/houses/gallery/' . $house['image'];
        
        if (file_exists($main_path)) return $main_path;
        elseif (file_exists($gallery_path)) return $gallery_path;
    }
    
    foreach($gallery as $img) {
        if($img['is_main'] == 1) return 'uploads/houses/gallery/' . $img['image'];
    }
    
    if(!empty($gallery)) return 'uploads/houses/gallery/' . $gallery[0]['image'];
    
    return 'https://via.placeholder.com/600x400/4DA6D9/ffffff?text=House+Image';
}

function formatDateDisplay($dateStr) {
    if (!$dateStr) return 'N/A';
    try {
        $date = new DateTime($dateStr);
        return $date->format('M d, Y');
    } catch(Exception $e) {
        return $dateStr;
    }
}

function formatDateTimeDisplay($dateStr) {
    if (!$dateStr) return 'N/A';
    try {
        $date = new DateTime($dateStr);
        return $date->format('M d, Y h:i A');
    } catch(Exception $e) {
        return $dateStr;
    }
}

function formatTimeDisplay($timeStr) {
    if (!$timeStr) return 'N/A';
    try {
        $time = new DateTime($timeStr);
        return $time->format('h:i A');
    } catch(Exception $e) {
        return $timeStr;
    }
}

function renderFeedbackStars($rating) {
    $html = '';
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5;
    
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $fullStars) {
            $html .= '<i class="fas fa-star" style="color: #f59e0b; font-size: 14px;"></i>';
        } elseif ($i == $fullStars + 1 && $halfStar) {
            $html .= '<i class="fas fa-star-half-alt" style="color: #f59e0b; font-size: 14px;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color: #d1d5db; font-size: 14px;"></i>';
        }
    }
    return $html;
}

function parseAmenities($amenities) {
    if (empty($amenities)) return [];
    if (strpos($amenities, "\n") !== false) {
        return array_values(array_filter(array_map('trim', explode("\n", $amenities))));
    }
    return array_values(array_filter(array_map('trim', explode(",", $amenities))));
}

function getAmenityIcon($amenity) {
    $amenity = strtolower($amenity);
    if (strpos($amenity, 'aircon') !== false || strpos($amenity, 'air conditioning') !== false) return 'fa-snowflake';
    if (strpos($amenity, 'bed') !== false) return 'fa-bed';
    if (strpos($amenity, 'kitchen') !== false) return 'fa-utensils';
    if (strpos($amenity, 'parking') !== false) return 'fa-parking';
    if (strpos($amenity, 'wifi') !== false) return 'fa-wifi';
    if (strpos($amenity, 'tv') !== false) return 'fa-tv';
    if (strpos($amenity, 'pool') !== false) return 'fa-swimmer';
    if (strpos($amenity, 'boat') !== false) return 'fa-ship';
    return 'fa-check-circle';
}

// ✨ TERMS: Compute mode for the modal
$force_must_accept = false;
if (isset($_SESSION['show_terms_modal']) && $_SESSION['show_terms_modal']) {
    $force_must_accept = true;
}
if (!$force_must_accept
    && isset($_SESSION['user_id'])
    && isset($_SESSION['role'])
    && $_SESSION['role'] === 'guest'
) {
    if (!$termsGate->hasAccepted((int)$_SESSION['user_id'])) {
        $force_must_accept = true;
    }
}

$termsContent = $termsGate->getContent();
$termsVersion = $termsGate->getCurrentVersion();

$sidebar_logo = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $sidebar_logo = $content['site_settings']['logo_path'];
}
$sidebar_logo_exists = !empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo);

$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transient Houses - Hundred Islands</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
        }

        /* HEADER */
        .header {
            background: #0B2447;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            padding: 12px 0;
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
            padding: 0;
            background: transparent;
            transition: transform 0.3s ease;
            display: block;
            flex-shrink: 0;
        }

        .logo-wrapper .logo-image:hover { transform: scale(1.05); }

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
            font-weight: 700;
            border: 2px solid #4DA6D9;
            flex-shrink: 0;
        }

        .brand-text { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
        .brand-text .brand-name { font-size: 20px; font-weight: 700; color: white; letter-spacing: -0.5px; white-space: nowrap; }
        .brand-text .brand-tagline { font-size: 11px; color: #7bb8f0; font-weight: 500; letter-spacing: 0.3px; white-space: nowrap; }

        /* DESKTOP NAV */
        .desktop-nav {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: nowrap;
            flex-shrink: 1;
            min-width: 0;
            max-width: 100%;
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

        /* HAMBURGER MENU */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 1001;
            background: #0B2447;
            color: white;
            border: none;
            border-radius: 12px;
            width: 48px;
            height: 48px;
            font-size: 22px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(77, 166, 217, 0.2);
        }

        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle {
            opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8);
        }

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
            padding: 0;
            background: transparent;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
            display: block;
        }

        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main {
            font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; letter-spacing: 0.3px; }

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
            position: relative;
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

        /* RESPONSIVE HEADER */
        @media (max-width: 1200px) {
            .desktop-nav .btn-logout .logout-text { display: none; }
            .desktop-nav .btn-logout { padding: 8px 12px; }
            .desktop-nav .btn-rate .rate-text { display: none; }
            .desktop-nav .btn-rate { padding: 8px 12px; }
        }

        @media (max-width: 1100px) {
            .desktop-nav { display: none !important; }
            .menu-toggle { display: flex; }
            .header-content { padding-left: 65px; }
            .sidebar-close-btn { display: flex; }
        }

        @media (max-width: 768px) {
            .header-content { padding-left: 60px; padding-right: 15px; gap: 10px; }
            .logo-wrapper { gap: 10px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 42px; width: 42px; }
            .brand-text .brand-name { font-size: 17px; }
            .brand-text .brand-tagline { font-size: 10px; }
        }

        @media (max-width: 480px) {
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .sidebar { width: 85%; max-width: 300px; }
            .header-content { padding-left: 58px; padding-right: 10px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 36px; width: 36px; border-radius: 10px; }
            .brand-text .brand-name { font-size: 15px; }
            .brand-text .brand-tagline { font-size: 9px; }
        }

        /* HERO */
        .hero {
            <?php if($hero_exists): ?>
            background: linear-gradient(rgba(11, 36, 71, 0.5), rgba(11, 36, 71, 0.6)), url('<?php echo $hero_path; ?>?<?php echo time(); ?>');
            background-size: cover;
            background-position: center;
            <?php else: ?>
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            <?php endif; ?>
            padding: 120px 20px 70px;
            color: white;
            text-align: center;
            position: relative;
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0;
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .hero h1 {
            font-size: 48px;
            font-weight: 700;
            margin: 0 0 20px 0;
            text-shadow: 0 2px 25px rgba(0,0,0,0.25);
            text-align: center;
            line-height: 1.2;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .hero h1 i { color: #7bb8f0; flex-shrink: 0; }

        .hero p {
            font-size: 18px;
            margin: 0;
            opacity: 0.95;
            text-shadow: 0 1px 15px rgba(0,0,0,0.15);
            text-align: center;
            max-width: 700px;
        }

        .main-container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

        .section-title { text-align: center; margin-bottom: 40px; }
        .section-title h2 { font-size: 32px; font-weight: 700; color: #0B2447; margin-bottom: 10px; }
        .section-title h2 i { color: #4DA6D9; }
        .section-title .underline {
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #4DA6D9, #7bb8f0);
            border-radius: 2px;
            margin: 0 auto;
        }

        .house-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 30px;
        }

        .house-card {
            background: #4DA6D9;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(77, 166, 217, 0.2);
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.15);
            cursor: pointer;
            display: flex;
            flex-direction: column;
        }

        .house-card:hover { transform: translateY(-8px); box-shadow: 0 20px 50px rgba(77, 166, 217, 0.3); }

        .house-image-wrapper { position: relative; overflow: hidden; height: 220px; flex-shrink: 0; }
        .house-image { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .house-card:hover .house-image { transform: scale(1.05); }

        .house-image-wrapper .featured-badge {
            position: absolute; top: 15px; left: 15px;
            background: #F4B400; color: #0B2447;
            padding: 4px 14px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; z-index: 2;
        }

        .house-image-wrapper .gallery-count {
            position: absolute; bottom: 15px; right: 15px;
            background: rgba(0,0,0,0.6); color: white;
            padding: 4px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 500;
            backdrop-filter: blur(5px); z-index: 2;
        }

        .house-image-wrapper .status-badge {
            position: absolute; top: 15px; right: 15px;
            padding: 4px 14px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.5px; z-index: 2;
        }

        .status-available { background: rgba(16, 185, 129, 0.9); color: white; }
        .status-booked { background: rgba(239, 68, 68, 0.9); color: white; }
        .status-maintenance { background: rgba(245, 158, 11, 0.9); color: white; }

        .house-content { padding: 25px; flex: 1; display: flex; flex-direction: column; }

        .house-name {
            font-size: 20px; font-weight: 700; color: white;
            margin-bottom: 6px; line-height: 1.3;
        }

        .house-name i { font-size: 18px; opacity: 0.8; margin-right: 8px; }

        .house-desc {
            color: rgba(255,255,255,0.9); font-size: 14px;
            line-height: 1.6; margin-bottom: 15px;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
            min-height: 44px;
        }

        .house-features {
            display: flex; gap: 20px;
            margin: 10px 0 15px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.15);
        }

        .feature { display: flex; align-items: center; gap: 6px; font-size: 14px; color: rgba(255,255,255,0.9); }
        .feature i { font-size: 16px; color: white; }

        .house-price { font-size: 26px; font-weight: 700; color: #F4B400; margin: 8px 0; }
        .house-price small { font-size: 14px; font-weight: 400; color: rgba(255,255,255,0.7); }

        .amenities-preview {
            background: rgba(255,255,255,0.08);
            border-radius: 8px; padding: 10px 12px;
            margin: 8px 0 12px; font-size: 12px;
            color: rgba(255,255,255,0.9); line-height: 1.8;
        }

        .amenities-preview .amenities-title {
            display: block; font-weight: 700;
            color: #F4B400; margin-bottom: 4px;
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;
        }

        .amenities-preview .amenity-line { display: flex; align-items: flex-start; gap: 6px; padding: 1px 0; }
        .amenities-preview .amenity-line i { color: #F4B400; font-size: 11px; margin-top: 3px; flex-shrink: 0; }

        .house-actions { margin-top: auto; padding-top: 15px; }

        .btn-view-photos {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 16px;
            background: rgba(255,255,255,0.15);
            color: white; border: 1px solid rgba(255,255,255,0.2);
            border-radius: 20px; font-size: 12px;
            font-weight: 500; cursor: pointer;
            transition: all 0.3s; margin-bottom: 10px;
        }

        .btn-view-photos:hover { background: rgba(255,255,255,0.25); transform: scale(1.02); }

        .btn-book {
            width: 100%; padding: 14px;
            background: #F4B400; color: #0B2447;
            border: none; border-radius: 12px;
            font-weight: 600; font-size: 15px;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center;
            justify-content: center; gap: 8px;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
        }

        .btn-book:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(244, 180, 0, 0.4);
            background: #e6a800;
        }

        .btn-book:disabled {
            background: rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.5);
            cursor: not-allowed; transform: none; box-shadow: none;
        }

        /* GALLERY MODAL */
        .gallery-modal {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 9999; align-items: center;
            justify-content: center; padding: 20px;
        }

        .gallery-modal.open { display: flex !important; }

        .gallery-modal-content {
            background: rgba(0,0,0,0.9);
            border-radius: 16px;
            max-width: 900px; width: 100%;
            max-height: 95vh; position: relative;
            display: flex; flex-direction: column;
            align-items: center;
        }

        .gallery-modal-close {
            position: absolute; top: 15px; right: 20px;
            font-size: 32px; cursor: pointer;
            color: white; z-index: 10;
            background: rgba(0,0,0,0.6);
            width: 45px; height: 45px;
            border-radius: 50%; display: flex;
            align-items: center; justify-content: center;
            transition: all 0.3s; border: none;
        }

        .gallery-modal-close:hover { background: rgba(255,255,255,0.2); transform: rotate(90deg); }

        .gallery-slider-container {
            position: relative; width: 100%;
            height: 500px; background: #000;
            border-radius: 16px 16px 0 0; overflow: hidden;
        }

        .gallery-slider-container .slider-image { width: 100%; height: 100%; object-fit: contain; transition: opacity 0.4s ease; }

        .gallery-slider-nav {
            position: absolute; top: 50%;
            transform: translateY(-50%);
            background: rgba(0,0,0,0.6); color: white;
            border: none; width: 50px; height: 50px;
            border-radius: 50%; font-size: 24px;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center;
            justify-content: center; z-index: 5;
        }

        .gallery-slider-nav:hover { background: rgba(255,255,255,0.2); }
        .gallery-slider-prev { left: 15px; }
        .gallery-slider-next { right: 15px; }

        .gallery-slider-counter {
            position: absolute; bottom: 20px; left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.7); color: white;
            padding: 6px 18px; border-radius: 20px;
            font-size: 14px; z-index: 5; font-weight: 500;
        }

        .gallery-thumbnails {
            display: flex; gap: 8px;
            padding: 12px 20px; overflow-x: auto;
            background: rgba(0,0,0,0.8);
            width: 100%; border-radius: 0 0 16px 16px;
        }

        .gallery-thumbnail {
            width: 70px; height: 50px;
            object-fit: cover; border-radius: 6px;
            cursor: pointer; transition: all 0.3s;
            border: 3px solid transparent; flex-shrink: 0;
        }

        .gallery-thumbnail:hover { transform: scale(1.05); }
        .gallery-thumbnail.active { border-color: #F4B400; }

        /* MODALS */
        .modal {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 3000;
            align-items: center; justify-content: center;
            backdrop-filter: blur(5px);
        }

        .modal.show { display: flex; }

        .modal-content {
            background: white; border-radius: 24px;
            width: 90%; max-width: 550px;
            max-height: 90vh; overflow-y: auto; padding: 30px;
        }

        .modal-lg { max-width: 800px; }

        .modal-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 20px;
            padding-bottom: 15px; border-bottom: 2px solid #e8f0fe;
        }

        .modal-header h3 { font-size: 22px; font-weight: 700; color: #0B2447; display: flex; align-items: center; gap: 10px; }
        .modal-header h3 i { color: #4DA6D9; }

        .modal-header .close {
            font-size: 28px; cursor: pointer;
            color: #94a3b8; transition: color 0.3s;
            background: none; border: none;
            padding: 0 10px; line-height: 1;
        }

        .modal-header .close:hover { color: #ef4444; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }

        .form-control, .form-select {
            width: 100%; padding: 10px 14px;
            border: 2px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; transition: border-color 0.3s;
            background: #fafafa;
        }

        .form-control:focus, .form-select:focus {
            outline: none; border-color: #4DA6D9;
            background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

        .time-helper {
            display: block;
            color: #94a3b8;
            font-size: 11px;
            margin-top: 4px;
            font-weight: 400;
        }
        .time-helper i { color: #4DA6D9; }
        .form-control.time-locked, .form-control.time-locked:focus { background: #f1f5f9; color: #475569; cursor: not-allowed; border-style: dashed; box-shadow: none; }

        .btn-primary {
            width: 100%; padding: 12px;
            background: #F4B400; color: #0B2447;
            border: none; border-radius: 10px;
            font-weight: 600; cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);
        }

        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3); background: #e6a800; }

        /* Guest Names Field */
        .guest-names-field {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            margin: 10px 0;
            border: 1px solid #e2e8f0;
        }

        .guest-names-field .guest-names-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 8px;
            flex-wrap: wrap; gap: 8px;
        }

        .guest-names-field .guest-names-header label {
            font-weight: 600; color: #1e293b;
            font-size: 13px; margin: 0;
        }

        .guest-names-field .guest-count-badge {
            background: #e8f0fe; color: #4DA6D9;
            padding: 2px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
        }

        .guest-names-field .guest-count-badge.match { background: #d1fae5; color: #065f46; }
        .guest-names-field .guest-count-badge.over { background: #fee2e2; color: #991b1b; }
        .guest-names-field .guest-count-badge.under { background: #fef3c7; color: #92400e; }

        .guest-names-field .guest-name-row {
            display: flex; align-items: center;
            gap: 8px; margin-bottom: 8px;
        }

        .guest-names-field .guest-name-row .guest-badge {
            min-width: 28px; height: 28px;
            background: #4DA6D9; color: white;
            border-radius: 50%; display: flex;
            align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; flex-shrink: 0;
        }

        .guest-names-field .guest-name-row .guest-name-input {
            flex: 1; padding: 10px 14px;
            border: 2px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; font-family: inherit;
            background: white; transition: all 0.2s;
        }

        .guest-names-field .guest-name-row .guest-name-input:focus {
            outline: none; border-color: #4DA6D9;
            box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1);
        }

        .guest-names-field .guest-name-row .guest-name-input.invalid { border-color: #ef4444; background: #fef2f2; }
        .guest-names-field .guest-name-row .guest-name-input.valid { border-color: #10b981; }

        .guest-names-field .help-text {
            font-size: 11px; color: #94a3b8;
            margin-top: 5px; display: flex;
            align-items: center; gap: 4px;
        }

        .guest-names-field .help-text.error { color: #ef4444; font-weight: 600; }

        /* CALENDAR */
        .calendar-container { background: white; border-radius: 16px; padding: 20px; margin: 15px 0; border: 1px solid #e8f0fe; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .calendar-header h4 { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0; }
        .calendar-nav { display: flex; gap: 10px; }

        .calendar-nav button {
            background: #f1f5f9; border: none;
            padding: 6px 12px; border-radius: 6px;
            cursor: pointer; font-size: 14px; transition: all 0.3s;
        }

        .calendar-nav button:hover { background: #4DA6D9; color: white; }

        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }

        .calendar-grid .day-name {
            text-align: center; font-size: 11px;
            font-weight: 600; color: #94a3b8;
            padding: 5px; text-transform: uppercase;
        }

        .calendar-grid .day {
            text-align: center; padding: 8px 0;
            border-radius: 8px; font-size: 14px;
            cursor: pointer; transition: all 0.2s;
            position: relative;
        }

        .calendar-grid .day:hover:not(.disabled):not(.booked):not(.blocked):not(.past) { background: #eef2ff; transform: scale(1.05); }
        .calendar-grid .day.selected { background: #4DA6D9; color: white; border-radius: 8px; }
        .calendar-grid .day.booked { background: #fee2e2 !important; color: #dc2626 !important; cursor: not-allowed !important; text-decoration: line-through !important; }
        .calendar-grid .day.blocked {
            background: #fecaca !important;
            color: #991b1b !important;
            cursor: not-allowed !important;
            text-decoration: line-through !important;
            position: relative;
        }
        .calendar-grid .day.blocked::after {
            content: '🚫';
            position: absolute;
            top: -3px;
            right: 1px;
            font-size: 9px;
            line-height: 1;
        }
        .calendar-grid .day.past { color: #cbd5e1 !important; cursor: not-allowed !important; }
        .calendar-grid .day.disabled { cursor: not-allowed; opacity: 0.5; }

        .calendar-legend {
            display: flex; gap: 20px;
            margin-top: 12px; justify-content: center;
            font-size: 12px; color: #64748b; flex-wrap: wrap;
        }

        .calendar-legend .dot { width: 14px; height: 14px; border-radius: 4px; display: inline-block; }
        .calendar-legend .dot.available { background: #d1fae5; border: 1px solid #10b981; }
        .calendar-legend .dot.booked { background: #fee2e2; border: 1px solid #dc2626; }
        .calendar-legend .dot.blocked { background: #fecaca; border: 1px solid #991b1b; }
        .calendar-legend .dot.selected { background: #4DA6D9; border: 1px solid #4DA6D9; }

        .calendar-info {
            text-align: center; margin-top: 10px;
            font-size: 13px; color: #64748b;
            padding: 8px; background: #f8fafc; border-radius: 8px;
        }

        /* ALERT */
        .alert-overlay { position: fixed; top: 20px; right: 20px; z-index: 3000; }

        .alert-box {
            background: white; border-radius: 12px;
            padding: 15px 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: flex; align-items: center;
            gap: 10px; min-width: 300px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
        .alert-box.success { border-left: 4px solid #10b981; }
        .alert-box.error { border-left: 4px solid #ef4444; }
        .alert-box i { font-size: 18px; }
        .alert-box.success i { color: #10b981; }
        .alert-box.error i { color: #ef4444; }

        /* PAYMENT POPUP */
        .payment-popup-overlay {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); z-index: 9999;
            align-items: center; justify-content: center;
            backdrop-filter: blur(5px);
        }

        .payment-popup-overlay.show { display: flex; }

        .payment-popup {
            background: white; border-radius: 24px;
            max-width: 500px; width: 95%;
            max-height: 90vh; overflow-y: auto;
            padding: 35px; animation: popupSlideIn 0.3s ease;
            box-shadow: 0 30px 80px rgba(0,0,0,0.3);
        }

        @keyframes popupSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .payment-popup .popup-header {
            text-align: center; margin-bottom: 25px;
            padding-bottom: 20px; border-bottom: 2px solid #e8f0fe;
        }

        .payment-popup .popup-header .success-icon { font-size: 60px; color: #10b981; margin-bottom: 10px; }
        .payment-popup .popup-header h2 { color: #1e293b; font-weight: 700; margin: 0; }
        .payment-popup .popup-header p { color: #64748b; margin: 5px 0 0; font-size: 14px; }

        .payment-popup .payment-detail {
            display: flex; justify-content: space-between;
            padding: 10px 0; border-bottom: 1px solid #f1f5f9;
        }

        .payment-popup .payment-detail:last-child { border-bottom: none; }
        .payment-popup .payment-detail .label { color: #64748b; font-weight: 500; }
        .payment-popup .payment-detail .value { font-weight: 600; color: #1e293b; }
        .payment-popup .payment-detail .value.amount { color: #10b981; font-size: 20px; }

        .payment-popup .qr-section {
            text-align: center; padding: 20px;
            background: #f8fafc; border-radius: 16px; margin: 15px 0;
        }

        .payment-popup .qr-section img { max-width: 200px; height: auto; border-radius: 12px; }
        .payment-popup .qr-section .no-qr { padding: 30px; background: #e2e8f0; border-radius: 12px; color: #94a3b8; }

        .payment-popup .instructions {
            background: #fef3c7; padding: 15px;
            border-radius: 12px; font-size: 13px;
            color: #92400e; margin: 15px 0;
        }

        .payment-popup .instructions strong { display: block; margin-bottom: 5px; }

        .payment-popup .popup-actions { display: flex; gap: 10px; margin-top: 20px; }

        .payment-popup .popup-actions .btn-close-popup {
            flex: 1; padding: 12px;
            background: #64748b; color: white;
            border: none; border-radius: 10px;
            font-weight: 600; cursor: pointer;
            transition: all 0.3s;
        }

        .payment-popup .popup-actions .btn-close-popup:hover { background: #475569; transform: translateY(-2px); }

        .payment-popup .popup-actions .btn-pay-now {
            flex: 1; padding: 12px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white; border: none; border-radius: 10px;
            font-weight: 600; cursor: pointer;
            transition: all 0.3s;
        }

        .payment-popup .popup-actions .btn-pay-now:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3); }

        .payment-popup .ref-number {
            background: #e6f7e6; color: #10b981;
            padding: 8px 15px; border-radius: 8px;
            font-weight: 600; display: inline-block; font-size: 14px;
        }


        /* RESPONSIVE */
        @media (max-width: 768px) {
            .hero { padding: 100px 20px 50px; }
            .hero h1 { font-size: 32px; gap: 8px; }
            .hero p { font-size: 16px; }

            .house-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; max-width: 95%; }
            .gallery-slider-container { height: 350px; }
            .gallery-slider-nav { width: 40px; height: 40px; font-size: 18px; }
            .gallery-thumbnail { width: 55px; height: 40px; }
            .payment-popup { padding: 20px; }
        }

        @media (max-width: 480px) {
            .modal-content { padding: 15px; }
            .hero { padding: 95px 15px 45px; }
            .hero h1 { font-size: 26px; gap: 6px; }
            .hero p { font-size: 14px; }
            .gallery-slider-container { height: 280px; }
            .gallery-slider-nav { width: 35px; height: 35px; font-size: 14px; }
            .gallery-thumbnail { width: 45px; height: 35px; }
            .gallery-modal-close { width: 35px; height: 35px; font-size: 24px; top: 10px; right: 12px; }
        }

        /* UTILITY */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-info { background: #dbeafe; color: #3b82f6; }
        .badge-feedback { background: #d1fae5; color: #065f46; }

        .no-houses { text-align: center; padding: 60px; background: white; border-radius: 20px; }
        .no-houses i { font-size: 60px; color: #cbd5e1; margin-bottom: 20px; }
        .no-houses h3 { color: #1e293b; margin-bottom: 10px; }
        .no-houses p { color: #64748b; }

        /* BOOKING TERMS MODAL */
        .booking-terms-modal { z-index: 3000 !important; }

        .booking-terms-content {
            max-width: 720px !important; padding: 0 !important;
            overflow: hidden !important; display: flex !important;
            flex-direction: column; max-height: 92vh !important;
        }

        .booking-terms-header {
            background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%);
            color: white; padding: 25px 30px;
            text-align: center; position: relative;
            overflow: hidden; flex-shrink: 0;
        }

        .booking-terms-icon {
            font-size: 42px; margin-bottom: 8px;
            position: relative; z-index: 1; color: #F4B400;
        }

        .booking-terms-header h3 {
            font-size: 22px; font-weight: 700;
            margin: 0 0 4px 0; color: white;
            position: relative; z-index: 1;
        }

        .booking-terms-header p {
            font-size: 13px; opacity: 0.9;
            margin: 0; color: #e0eeff;
            position: relative; z-index: 1;
        }

        .booking-terms-close {
            position: absolute; top: 14px; right: 14px;
            background: rgba(255,255,255,0.15); color: white;
            border: 1px solid rgba(255,255,255,0.25);
            width: 38px; height: 38px; border-radius: 50%;
            cursor: pointer; font-size: 16px; display: flex;
            align-items: center; justify-content: center;
            transition: all 0.25s ease; z-index: 5;
        }

        .booking-terms-close:hover {
            background: #ef4444; border-color: #ef4444;
            transform: rotate(90deg);
        }

        .booking-terms-scroll {
            flex: 1; overflow-y: auto;
            padding: 25px 30px; background: white; min-height: 0;
        }

        .booking-terms-scroll::-webkit-scrollbar { width: 8px; }
        .booking-terms-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .booking-terms-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .booking-terms-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .booking-terms-body {
            font-size: 13.5px; line-height: 1.7;
            color: #334155; word-wrap: break-word;
        }

        .booking-terms-body h1,
        .booking-terms-body h2,
        .booking-terms-body h3 {
            color: #0B2447; margin: 16px 0 8px; font-weight: 700;
        }

        .booking-terms-body h1 { font-size: 18px; }
        .booking-terms-body h2 { font-size: 16px; }
        .booking-terms-body h3 { font-size: 14px; }
        .booking-terms-body p { margin: 0 0 12px; }
        .booking-terms-body ul,
        .booking-terms-body ol { padding-left: 22px; margin: 8px 0 12px; }
        .booking-terms-body li { margin-bottom: 5px; }

        .booking-terms-scroll-hint {
            text-align: center; padding: 12px;
            background: #fef3c7; color: #92400e;
            font-size: 12.5px; font-weight: 600;
            border-top: 1px solid #fde68a;
            flex-shrink: 0; transition: all 0.3s;
        }

        .booking-terms-scroll-hint.done {
            background: #d1fae5; color: #065f46;
            border-top-color: #a7f3d0;
        }

        #bookingTermsAcceptForm {
            padding: 15px 25px 20px;
            border-top: 2px solid #e2e8f0;
            background: #f8fafc; flex-shrink: 0;
            display: none; animation: slideUp 0.3s ease;
        }

        #bookingTermsAcceptForm.visible { display: block; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .booking-terms-checkbox-label {
            display: flex; align-items: flex-start;
            gap: 10px; cursor: pointer;
            padding: 12px 14px; background: white;
            border: 2px solid #e2e8f0; border-radius: 10px;
            margin-bottom: 12px; transition: all 0.2s;
            font-weight: 500; font-size: 13.5px;
            color: #1e293b; line-height: 1.5;
        }

        .booking-terms-checkbox-label:hover {
            border-color: #4DA6D9; background: #f0f7fb;
        }

        .booking-terms-checkbox-label input[type="checkbox"] {
            width: 20px; height: 20px; cursor: pointer;
            accent-color: #4DA6D9; margin-top: 1px; flex-shrink: 0;
        }

        .booking-terms-checkbox-text { flex: 1; }

        .booking-terms-accept-btn {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white; border: none; border-radius: 10px;
            font-weight: 700; font-size: 15px;
            cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center;
            justify-content: center; gap: 8px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .booking-terms-accept-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45);
        }

        .booking-terms-accept-btn:disabled {
            background: #cbd5e1; cursor: not-allowed;
            box-shadow: none; transform: none; color: #94a3b8;
        }

        .booking-terms-footer-note {
            text-align: center; padding: 10px 20px 15px;
            font-size: 11.5px; color: #94a3b8;
            background: #f8fafc; margin: 0; flex-shrink: 0;
        }

        @media (max-width: 600px) {
            .booking-terms-content { max-width: 95% !important; max-height: 94vh !important; }
            .booking-terms-header { padding: 20px; }
            .booking-terms-header h3 { font-size: 18px; }
            .booking-terms-icon { font-size: 34px; }
            .booking-terms-close { width: 32px; height: 32px; font-size: 14px; top: 10px; right: 10px; }
            .booking-terms-scroll { padding: 18px 20px; }
            .booking-terms-body { font-size: 13px; }
            .booking-terms-scroll-hint { font-size: 11.5px; padding: 10px; }
            #bookingTermsAcceptForm { padding: 12px 18px 15px; }
            .booking-terms-checkbox-label { font-size: 12.5px; padding: 10px 12px; }
            .booking-terms-accept-btn { font-size: 14px; padding: 12px; }
        }

        /* ✅ CENTERED SUCCESS POPUP — for overall feedback */
        .alert-overlay.show {
            display: flex; align-items: center; justify-content: center;
            inset: 0; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }
        .alert-box.success-popup {
            background: white; border-radius: 20px;
            width: 90%; max-width: 400px;
            padding: 35px 30px 25px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            border-top: 6px solid #10b981;
            animation: alertPopIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            min-width: unset; display: block;
        }
        @keyframes alertPopIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.9); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .alert-box.success-popup .alert-icon {
            font-size: 60px; margin-bottom: 15px; line-height: 1;
            display: block; color: #10b981;
        }
        .alert-box.success-popup h3 {
            font-size: 24px; font-weight: 700; margin-bottom: 10px; color: #10b981;
        }
        .alert-box.success-popup p {
            color: #4a6a8c; margin-bottom: 22px; font-size: 15px;
            line-height: 1.5; word-wrap: break-word;
        }
        .alert-box.success-popup .btn-popup-ok {
            background: #F4B400; color: #0B2447; border: none;
            padding: 12px 40px; border-radius: 10px;
            font-weight: 700; font-size: 15px; cursor: pointer;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3);
            transition: all 0.25s; min-width: 130px;
            -webkit-tap-highlight-color: rgba(0,0,0,0.1);
            touch-action: manipulation;
        }
        .alert-box.success-popup .btn-popup-ok:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(244, 180, 0, 0.45);
            background: #e6a800; color: #0B2447;
        }
        @media (max-width: 480px) {
            .alert-box.success-popup { padding: 28px 22px 20px; border-radius: 16px; }
            .alert-box.success-popup .alert-icon { font-size: 50px; margin-bottom: 12px; }
            .alert-box.success-popup h3 { font-size: 20px; }
            .alert-box.success-popup p { font-size: 14px; margin-bottom: 18px; }
            .alert-box.success-popup .btn-popup-ok { padding: 11px 32px; font-size: 14px; }
        }

        /* ✅ NEW: Bookings table with time rows */
        .booking-time-row {
            display: flex;
            align-items: center;
            gap: 4px;
            color: #64748b;
            font-size: 11.5px;
            margin-top: 3px;
        }
        .booking-time-row i {
            font-size: 10px;
            color: #4DA6D9;
        }

        /* ============================================================
           ✅ REDESIGNED: EDIT REVIEW MODAL (matches provided image)
           ============================================================ */
        .review-modal-content {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08), 0 8px 20px rgba(0, 0, 0, 0.06);
            width: 100%;
            max-width: 520px;
            padding: 28px 32px 32px;
            position: relative;
            transition: all 0.2s ease;
            max-height: 92vh;
            overflow-y: auto;
            border: none;
        }

        .review-modal-content .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            padding-bottom: 0;
            border-bottom: none;
        }

        .review-modal-content .modal-header h3 {
            font-size: 22px;
            font-weight: 700;
            color: #0B2447;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.3px;
            margin: 0;
        }

        .review-modal-content .modal-header h3 i {
            color: #F4B400;
            font-size: 24px;
        }

        .review-modal-content .modal-header .close {
            font-size: 22px;
            color: #94a3b8;
            cursor: pointer;
            transition: color 0.2s;
            padding: 4px 8px;
            border-radius: 8px;
            line-height: 1;
            background: none;
            border: none;
        }

        .review-modal-content .modal-header .close:hover {
            color: #ef4444;
            background: #f1f5f9;
        }

        .previous-rating-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 18px 20px;
            text-align: center;
            margin-bottom: 24px;
            border: 1px solid #eef2f6;
        }

        .previous-rating-box .label {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 8px;
            letter-spacing: 0.2px;
        }

        .previous-rating-box .stars {
            font-size: 28px;
            margin: 4px 0 6px;
            letter-spacing: 4px;
        }

        .previous-rating-box .stars .filled { color: #F4B400; }
        .previous-rating-box .stars .empty { color: #d1d9e6; }

        .previous-rating-box .helper {
            font-size: 12.5px;
            color: #94a3b8;
            font-weight: 400;
            margin-top: 2px;
        }

        .review-section-label {
            font-size: 14px;
            font-weight: 600;
            color: #0B2447;
            margin-bottom: 6px;
            display: block;
            letter-spacing: 0.2px;
        }

        .current-rating-section {
            text-align: center;
            margin-bottom: 22px;
        }

        .current-rating-stars {
            font-size: 34px;
            letter-spacing: 6px;
            margin: 2px 0 4px;
            cursor: default;
        }

        .current-rating-stars .filled { color: #F4B400; }
        .current-rating-stars .empty { color: #d1d9e6; }

        .current-rating-stars i {
            cursor: pointer;
            transition: transform 0.15s ease, color 0.15s ease;
            font-style: normal;
        }

        .current-rating-stars i:hover { transform: scale(1.15); }

        .rating-text {
            font-size: 15px;
            color: #3b5e8a;
            font-weight: 500;
            margin-top: 2px;
        }

        .comment-wrapper { margin-bottom: 22px; }

        .comment-wrapper textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            color: #1e293b;
            background: #fafcff;
            resize: vertical;
            min-height: 85px;
            transition: border-color 0.25s, box-shadow 0.25s;
            line-height: 1.5;
        }

        .comment-wrapper textarea:focus {
            outline: none;
            border-color: #4DA6D9;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(77, 166, 217, 0.08);
        }

        .comment-wrapper textarea::placeholder {
            color: #a0b4cc;
            font-weight: 400;
        }

        .anonymous-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid #eef2f6;
            cursor: pointer;
            transition: background 0.2s;
        }

        .anonymous-toggle:hover { background: #f1f5f9; }

        .anonymous-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #7c3aed;
            cursor: pointer;
            flex-shrink: 0;
            border-radius: 4px;
        }

        .anonymous-toggle .toggle-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            cursor: pointer;
            flex: 1;
        }

        .anonymous-toggle .toggle-label i {
            color: #7c3aed;
            font-size: 15px;
        }

        .anonymous-toggle .toggle-hint {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 400;
            white-space: nowrap;
        }

        .btn-update-review {
            width: 100%;
            padding: 16px 20px;
            background: #F4B400;
            color: #0B2447;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(244, 180, 0, 0.25);
            letter-spacing: 0.2px;
        }

        .btn-update-review:hover {
            background: #e6a800;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(244, 180, 0, 0.4);
        }

        .btn-update-review:active {
            transform: translateY(0);
            box-shadow: 0 4px 12px rgba(244, 180, 0, 0.3);
        }

        .btn-update-review i { font-size: 18px; }

        @media (max-width: 520px) {
            .review-modal-content {
                padding: 20px 18px 24px;
                border-radius: 20px;
            }
            .review-modal-content .modal-header h3 { font-size: 19px; }
            .previous-rating-box { padding: 14px 16px; }
            .previous-rating-box .stars { font-size: 24px; }
            .current-rating-stars { font-size: 28px; letter-spacing: 4px; }
            .anonymous-toggle { flex-wrap: wrap; gap: 8px; padding: 10px 14px; }
            .anonymous-toggle .toggle-hint {
                font-size: 11px; white-space: normal;
                width: 100%; margin-left: 30px;
            }
            .btn-update-review { padding: 14px 16px; font-size: 15px; }
        }

        /* ============================================================
           SHOPEE-STYLE VIEW MODAL
           ============================================================ */
        .shopee-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 4000;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: shopeeFadeIn 0.25s ease;
            overscroll-behavior: contain;
        }
        .shopee-modal.show { display: flex; }
        @keyframes shopeeFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .shopee-modal-content {
            background: white;
            border-radius: 24px;
            width: 100%;
            max-width: 980px;
            max-height: 92vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 30px 80px rgba(0,0,0,0.35);
            animation: shopeeSlideUp 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }
        @keyframes shopeeSlideUp { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .shopee-close-btn {
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 20;
            background: rgba(0,0,0,0.45);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s;
        }
        .shopee-close-btn:hover { background: #ef4444; transform: rotate(90deg); }

        .shopee-modal-body {
            display: flex;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        .shopee-gallery {
            width: 45%;
            background: #f4f7fa;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            border-right: 1px solid #e8f0fe;
        }
        .shopee-main-image-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            min-height: 0;
            position: relative;
        }
        .shopee-main-image {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            border-radius: 16px;
            object-fit: contain;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            transition: opacity 0.3s;
        }
        .shopee-main-image.placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 80px;
            color: #cbd5e1;
            background: #eef2f7;
        }
        .shopee-thumbnails {
            display: flex;
            gap: 8px;
            padding: 12px 20px 20px;
            overflow-x: auto;
            flex-shrink: 0;
            justify-content: center;
            flex-wrap: wrap;
        }
        .shopee-thumb {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            border: 3px solid transparent;
            cursor: pointer;
            transition: all 0.25s;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .shopee-thumb:hover { transform: translateY(-2px); }
        .shopee-thumb.active { border-color: #F4B400; box-shadow: 0 4px 12px rgba(244,180,0,0.3); }

        .shopee-details {
            flex: 1;
            padding: 30px 32px 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            min-width: 0;
            background: white;
        }
        .shopee-details::-webkit-scrollbar { width: 6px; }
        .shopee-details::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .shopee-detail-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .shopee-detail-badges .badge {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .shopee-detail-title {
            font-size: 24px;
            font-weight: 800;
            color: #0B2447;
            line-height: 1.3;
            margin-bottom: 6px;
        }
        .shopee-detail-category {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .shopee-detail-features {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .shopee-detail-feature {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            color: #475569;
            font-weight: 500;
        }
        .shopee-detail-feature i { color: #4DA6D9; font-size: 13px; }

        .shopee-price-section {
            background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 20px;
            border: 1px solid #d4e4f0;
        }
        .shopee-price-main {
            font-size: 34px;
            font-weight: 800;
            color: #4DA6D9;
            line-height: 1.1;
        }
        .shopee-price-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
            font-weight: 500;
        }

        .shopee-detail-section {
            margin-bottom: 20px;
        }
        .shopee-detail-section h4 {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .shopee-detail-section h4 i { color: #4DA6D9; }
        .shopee-detail-section p {
            font-size: 13.5px;
            color: #475569;
            line-height: 1.7;
            margin: 0;
        }
        .shopee-amenities-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: #f8fafc;
            border-radius: 10px;
            padding: 14px 16px;
            border: 1px solid #e8f0fe;
        }
        .shopee-amenity-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 13px;
            color: #475569;
            line-height: 1.6;
        }
        .shopee-amenity-item i {
            color: #F4B400;
            font-size: 12px;
            margin-top: 4px;
            flex-shrink: 0;
        }

        .shopee-actions {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid #e8f0fe;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .shopee-actions .btn-book {
            flex: 1;
            min-width: 200px;
            padding: 15px 24px;
            background: linear-gradient(135deg, #F4B400, #e6a800);
            color: #0B2447;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            box-shadow: 0 6px 20px rgba(244, 180, 0, 0.35);
        }
        .shopee-actions .btn-book:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(244, 180, 0, 0.5);
        }
        .shopee-actions .btn-book:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }
        .shopee-actions .btn-secondary {
            padding: 15px 24px;
            background: #f1f5f9;
            color: #475569;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s;
            white-space: nowrap;
        }
        .shopee-actions .btn-secondary:hover { background: #e2e8f0; transform: translateY(-2px); }

        .shopee-login-note {
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
            margin-top: 12px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px dashed #cbd5e1;
        }
        .shopee-login-note a { color: #4DA6D9; font-weight: 600; text-decoration: none; }
        .shopee-login-note a:hover { text-decoration: underline; }

        @media (max-width: 820px) {
            .shopee-modal-body { flex-direction: column; }
            .shopee-gallery {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #e8f0fe;
                max-height: 320px;
            }
            .shopee-main-image-wrap { padding: 16px; min-height: 200px; }
            .shopee-main-image { max-height: 240px; }
            .shopee-thumbnails { padding: 10px 14px 14px; }
            .shopee-thumb { width: 48px; height: 48px; }
            .shopee-details { padding: 20px 20px 20px; }
            .shopee-detail-title { font-size: 20px; }
            .shopee-price-main { font-size: 28px; }
            .shopee-actions { flex-direction: column; }
            .shopee-actions .btn-book { min-width: unset; width: 100%; }
            .shopee-actions .btn-secondary { width: 100%; }
        }
        @media (max-width: 480px) {
            .shopee-modal { padding: 10px; }
            .shopee-modal-content { border-radius: 18px; max-height: 95vh; }
            .shopee-gallery { max-height: 240px; }
            .shopee-main-image-wrap { padding: 12px; min-height: 150px; }
            .shopee-main-image { max-height: 180px; }
            .shopee-thumb { width: 40px; height: 40px; }
            .shopee-details { padding: 16px 16px 16px; }
            .shopee-detail-title { font-size: 18px; }
            .shopee-price-main { font-size: 24px; }
            .shopee-price-section { padding: 14px 16px; }
            .shopee-actions .btn-book { font-size: 15px; padding: 13px 18px; }
            .shopee-close-btn { width: 34px; height: 34px; font-size: 15px; top: 10px; right: 10px; }
        }
    </style>
<?php echo PaymentService::css(); ?>
</head>
<body>

<?php include 'components/navbar.php'; ?>

<!-- PAGE HERO -->
<?php if (isset($error) && strpos((string)$error, 'Booking failed') === 0): ?>
<div class="alert-overlay">
    <div class="alert-box error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
</div>
<?php endif; ?>

<div class="hero">
    <div class="hero-content">
        <h1><i class="fas fa-home"></i> Houses</h1>
        <p>Comfortable transient stays near the Hundred Islands gateway</p>
    </div>
</div>

<div class="main-container">
    
    <div class="section-title">
        <h2>Available Houses</h2>
        <div class="underline"></div>
    </div>
    
    <?php if(empty($houses)): ?>
        <div class="no-houses">
            <i class="fas fa-home"></i>
            <h3>No Houses Available</h3>
            <p>Please check back later or contact us for inquiries.</p>
        </div>
    <?php else: ?>
        <div class="house-grid">
            <?php foreach($houses as $house): 
                $gallery = $house_gallery[$house['id']] ?? [];
                $gallery_count = count($gallery);
                $amenities = parseAmenities($house['amenities'] ?? '');
                $display_amenities = array_slice($amenities, 0, 5);
            ?>
            <div class="house-card" onclick="openShopeeView(<?php echo $house['id']; ?>)">
                <div class="house-image-wrapper">
                    <img src="<?php echo getHouseMainImage($house, $gallery); ?>" 
                         class="house-image" 
                         alt="<?php echo htmlspecialchars($house['house_name']); ?>"
                         onerror="this.src='https://via.placeholder.com/600x400/4DA6D9/ffffff?text=House+Image'">
                    
                    <?php if(isset($house['is_featured']) && $house['is_featured']): ?>
                    <span class="featured-badge"><i class="fas fa-star"></i> Featured</span>
                    <?php endif; ?>
                    
                    <span class="status-badge status-<?php echo $house['status'] ?? 'available'; ?>">
                        <?php echo ucfirst($house['status'] ?? 'Available'); ?>
                    </span>
                    
                    <?php if($gallery_count > 0): ?>
                    <span class="gallery-count">
                        <i class="fas fa-images"></i> <?php echo $gallery_count; ?>
                    </span>
                    <?php endif; ?>
                </div>
                
                <div class="house-content">
                    <h3 class="house-name">
                        <i class="fas fa-home"></i>
                        <?php echo htmlspecialchars($house['house_name']); ?>
                    </h3>
                    
                    <?php if(!empty($house['description'])): ?>
                        <div class="house-desc">
                            <?php echo htmlspecialchars(substr($house['description'], 0, 120)) . (strlen($house['description']) > 120 ? '...' : ''); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="house-features">
                        <div class="feature">
                            <i class="fas fa-users"></i>
                            <span><?php echo $house['capacity'] ?? 'N/A'; ?> pax</span>
                        </div>
                        <div class="feature">
                            <i class="fas fa-bed"></i>
                            <span><?php echo $house['bedrooms'] ?? 'N/A'; ?> beds</span>
                        </div>
                    </div>
                    
                    <div class="house-price">
                        ₱<?php echo number_format($house['price_per_night']); ?> <small>/night</small>
                    </div>
                    
                    <?php if(!empty($display_amenities)): ?>
                        <div class="amenities-preview">
                            <span class="amenities-title">
                                <i class="fas fa-check-circle"></i> Inclusions:
                            </span>
                            <?php foreach($display_amenities as $amenity): ?>
                                <?php if(!empty($amenity)): ?>
                                    <div class="amenity-line">
                                        <i class="fas fa-check"></i>
                                        <span><?php echo htmlspecialchars($amenity); ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if(count($amenities) > 5): ?>
                                <div class="amenity-line" style="color: rgba(255,255,255,0.65); font-style: italic;">
                                    <i class="fas fa-plus-circle"></i>
                                    <span>+<?php echo count($amenities) - 5; ?> more inclusions</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="house-actions" onclick="event.stopPropagation();">
                        <?php if($gallery_count > 0): ?>
                        <button class="btn-view-photos" onclick="event.stopPropagation(); openGalleryModal(<?php echo $house['id']; ?>, '<?php echo addslashes($house['house_name']); ?>')">
                            <i class="fas fa-images"></i> View Photos (<?php echo $gallery_count; ?>)
                        </button>
                        <?php endif; ?>
                        
                        <?php if(($house['status'] ?? 'available') == 'available'): ?>
                            <?php if(isset($_SESSION['user_id'])): ?>
                                <button class="btn-book" onclick="event.stopPropagation(); bookHouse(<?php echo $house['id']; ?>, <?php echo $house['price_per_night']; ?>, '<?php echo addslashes($house['house_name']); ?>')">
                                    <i class="fas fa-calendar-check"></i> Book Now
                                </button>
                            <?php else: ?>
                                <a href="login.php?redirect=houses.php" class="btn-book" style="text-decoration: none;" onclick="event.stopPropagation()">
                                    <i class="fas fa-sign-in-alt"></i> Login to Book
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="btn-book" disabled>
                                <i class="fas fa-clock"></i> Not Available
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- MY BOOKINGS -->
    <?php if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] == 'guest' && !empty($user_bookings)): ?>
    <div style="margin-top: 60px;">
        <div class="section-title">
            <h2><i class="fas fa-calendar-check"></i> My House Bookings</h2>
            <div class="underline"></div>
        </div>
        <div style="background: white; border-radius: 20px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 750px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e8f0fe;">
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">Ref #</th>
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">House</th>
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">Check In</th>
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">Check Out</th>
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">Total</th>
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">Payment</th>
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">Status</th>
                        <th style="padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($user_bookings as $booking): ?>
                    <tr style="border-bottom: 1px solid #e8f0fe;">
                        <td style="padding: 12px;"><strong><?php echo htmlspecialchars($booking['reference_number'] ?? 'N/A'); ?></strong></td>
                        <td style="padding: 12px;"><?php echo htmlspecialchars($booking['house_name']); ?></td>
                        <td style="padding: 12px;">
                            <?php echo formatDateDisplay($booking['check_in_date']); ?>
                            <div class="booking-time-row">
                                <i class="fas fa-clock"></i>
                                <?php echo formatTimeDisplay($booking['check_in_time'] ?? '14:00:00'); ?>
                            </div>
                        </td>
                        <td style="padding: 12px;">
                            <?php echo formatDateDisplay($booking['check_out_date']); ?>
                            <div class="booking-time-row">
                                <i class="fas fa-clock"></i>
                                <?php echo formatTimeDisplay($booking['check_out_time'] ?? '12:00:00'); ?>
                            </div>
                        </td>
                        <td style="padding: 12px;"><?php echo PaymentService::guestAmountCell($booking); ?></td>
                        <td style="padding: 12px;">
                            <?php echo PaymentService::guestBadge($booking); ?>
                        </td>
                        <td style="padding: 12px;">
                            <span class="badge badge-info"><?php echo ucfirst($booking['booking_status']); ?></span>
                        </td>
                        <td style="padding: 12px;">
                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                <?php if(PaymentService::canPayReservation($booking)): ?>
                                    <a href="profile.php#houses-tab" class="btn" style="padding: 4px 12px; background: #10b981; color: white; border: none; border-radius: 6px; font-size: 11px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-credit-card"></i> Pay
                                    </a>
                                <?php endif; ?>
                                
                                <?php if(($booking['booking_status'] == 'completed' || PaymentService::isSecured($booking)) && empty($booking['feedback_text'])): ?>
                                    <button class="btn" style="padding: 4px 12px; background: #8b5cf6; color: white; border: none; border-radius: 6px; font-size: 11px; cursor: pointer;" onclick="openFeedbackModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['house_name']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-star"></i> Rate
                                    </button>
                                <?php endif; ?>
                                
                                <?php if(!empty($booking['feedback_text'])): ?>
                                    <button class="btn" style="padding: 4px 12px; background: #0ea5e9; color: white; border: none; border-radius: 6px; font-size: 11px; cursor: pointer;" onclick="viewFeedback('<?php echo htmlspecialchars($booking['feedback_text']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<!-- GALLERY SLIDER MODAL -->
<div id="galleryModal" class="gallery-modal" onclick="if(event.target === this) closeGalleryModal()">
    <div class="gallery-modal-content">
        <button class="gallery-modal-close" onclick="closeGalleryModal()">&times;</button>
        
        <div class="gallery-slider-container">
            <img id="sliderMainImage" class="slider-image" src="" alt="House Photo">
            <button class="gallery-slider-nav gallery-slider-prev" onclick="changeSliderImage(-1)">❮</button>
            <button class="gallery-slider-nav gallery-slider-next" onclick="changeSliderImage(1)">❯</button>
            <div class="gallery-slider-counter" id="sliderCounter">1 / 1</div>
        </div>
        
        <div class="gallery-thumbnails" id="sliderThumbnails"></div>
    </div>
</div>

<!-- ============================================================
     SHOPEE-STYLE VIEW MODAL
     ============================================================ -->
<div class="shopee-modal" id="shopeeViewModal" onclick="if(event.target === this) closeShopeeView()">
    <div class="shopee-modal-content">
        <button class="shopee-close-btn" onclick="closeShopeeView()" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="shopee-modal-body" id="shopeeModalBody">
            <!-- Content will be injected by JS -->
        </div>
    </div>
</div>

<!-- BOOKING MODAL -->
<div class="modal" id="bookingModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-calendar-check"></i> Book This House</h3>
            <span class="close" onclick="hideModal('booking')">&times;</span>
        </div>
        <div class="modal-body" style="padding: 25px; max-height: 80vh; overflow-y: auto;">
            <form method="POST" id="bookingForm">
                <input type="hidden" name="house_id" id="booking_house_id">
                <input type="hidden" name="house_name" id="booking_house_name">
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-home"></i> House</label>
                        <input type="text" id="display_house" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Price/Night</label>
                        <input type="text" id="display_price" class="form-control" readonly>
                    </div>
                </div>
                
                <div class="calendar-container">
                    <div class="calendar-header">
                        <h4 id="calendarMonthYear"></h4>
                        <div class="calendar-nav">
                            <button type="button" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                            <button type="button" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                    <div class="calendar-legend">
                        <span><span class="dot available"></span> Available</span>
                        <span><span class="dot booked"></span> Booked</span>
                        <span><span class="dot blocked"></span> Blocked</span>
                        <span><span class="dot selected"></span> Selected</span>
                    </div>
                    <div class="calendar-info" id="calendarInfo">Select check-in and check-out dates</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Check-in Date *</label>
                        <input type="date" name="check_in" id="check_in" class="form-control" min="<?php echo date('Y-m-d'); ?>" required onchange="updateCalendarSelection()">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Check-out Date *</label>
                        <input type="date" name="check_out" id="check_out" class="form-control" min="<?php echo date('Y-m-d'); ?>" required onchange="updateCalendarSelection()">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Check-in Time *</label>
                        <input type="time" name="check_in_time" id="check_in_time" class="form-control" value="14:00" required oninput="syncCheckoutTime()" onchange="syncCheckoutTime(); updateCalendarInfo()">
                        <small class="time-helper">
                            <i class="fas fa-info-circle"></i> Standard check-in: 2:00 PM
                        </small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Check-out Time <small style="color:#94a3b8;font-weight:400;">(automatic)</small></label>
                        <input type="time" name="check_out_time" id="check_out_time" value="14:00" required readonly tabindex="-1" aria-readonly="true" class="form-control time-locked">
                        <small class="time-helper">
                            <i class="fas fa-lock"></i> Checkout time follows your check-in time.
                        </small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-users"></i> Number of Pax * <small style="color: #94a3b8; font-weight: 400;">(max 20)</small></label>
                    <input type="number" name="guests" id="guests" class="form-control" min="1" max="20" placeholder="Enter number of pax" required onchange="rebuildGuestNameInputs()" oninput="validatePax(this)">
                </div>
                
                <div class="guest-names-field">
                    <div class="guest-names-header">
                        <label><i class="fas fa-users"></i> Guest Names</label>
                        <span class="guest-count-badge" id="guestCountBadge">0 / 0</span>
                    </div>
                    <div id="guestNamesList"></div>
                    <input type="hidden" name="guest_names" id="guest_names" value="">
                    <div class="help-text" id="guestNamesHelp">
                        <i class="fas fa-info-circle"></i> Enter the number of pax above to add guest name fields
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 20px; border-radius: 16px; margin: 20px 0;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                        <span><i class="fas fa-calendar-alt" style="color: #4DA6D9;"></i> Stay Duration:</span>
                        <span><strong id="display_days">0</strong> day(s) / <strong id="display_nights">0</strong> night(s)</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 18px; padding-top: 5px;">
                        <span><i class="fas fa-money-bill-wave" style="color: #10b981;"></i> Total amount:</span>
                        <span><strong style="color: #10b981;">₱<span id="display_total">0.00</span></strong></span>
                    </div>
                    <input type="hidden" name="total_amount" id="total_amount">
                </div>
                
                <button type="button" id="confirmBookingBtn" class="btn-primary" onclick="requestBookingWithTerms()">
                    <i class="fas fa-check-circle"></i> Confirm Booking
                </button>
            </form>
        </div>
    </div>
</div>

<!-- FEEDBACK MODAL -->
<div class="modal" id="feedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star" style="color: #f59e0b;"></i> Rate Your Stay</h3>
            <span class="close" onclick="closeFeedbackModal()">&times;</span>
        </div>
        <div id="feedbackContent">
            <p style="color: #64748b; margin-bottom: 15px;">
                How was your stay at <strong id="feedback_house_name"></strong>?
            </p>
            <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;">
                Reference: <strong id="feedback_reference"></strong>
            </p>
            
            <form method="POST" id="feedbackForm">
                <input type="hidden" name="booking_id" id="feedback_booking_id">
                
                <div style="text-align: center; margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #1e293b;">Your Rating</label>
                    <div class="rating-stars" id="ratingStars">
                        <i class="fas fa-star" data-rating="1" onclick="setRating(1)"></i>
                        <i class="fas fa-star" data-rating="2" onclick="setRating(2)"></i>
                        <i class="fas fa-star" data-rating="3" onclick="setRating(3)"></i>
                        <i class="fas fa-star" data-rating="4" onclick="setRating(4)"></i>
                        <i class="fas fa-star" data-rating="5" onclick="setRating(5)"></i>
                    </div>
                    <input type="hidden" name="rating" id="feedback_rating" value="0" required>
                    <span id="ratingText" style="font-size: 14px; color: #64748b;">Select a rating</span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b;">
                        <i class="fas fa-comment"></i> Your Feedback
                    </label>
                    <textarea name="feedback" id="feedback_text" class="form-control" rows="4" placeholder="Share your experience..." style="width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 10px; resize: vertical;"></textarea>
                </div>
                
                <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; padding: 10px 15px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <input type="checkbox" name="is_anonymous" id="feedback_is_anonymous" value="1">
                    <label for="feedback_is_anonymous" style="margin-bottom: 0; cursor: pointer; font-weight: 500; color: #1e293b;">
                        <i class="fas fa-user-secret" style="color: #7c3aed;"></i> Post as Anonymous
                    </label>
                    <span style="font-size: 12px; color: #94a3b8; margin-left: auto;">
                        Your name will not be shown publicly
                    </span>
                </div>
                
                <button type="submit" name="submit_house_feedback" id="feedbackSubmitBtn" style="width: 100%; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                    <i class="fas fa-paper-plane"></i> Submit Feedback
                </button>
            </form>
        </div>
    </div>
</div>

<!-- VIEW FEEDBACK MODAL -->
<div class="modal" id="viewFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star" style="color: #f59e0b;"></i> Your Feedback</h3>
            <span class="close" onclick="closeViewFeedbackModal()">&times;</span>
        </div>
        <div id="viewFeedbackContent">
            <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;">
                Reference: <strong id="view_feedback_reference"></strong>
            </p>
            <div style="text-align: center; margin-bottom: 15px;">
                <div id="view_feedback_stars" style="font-size: 24px;"></div>
            </div>
            <div style="background: #f8fafc; padding: 15px; border-radius: 10px;">
                <p id="view_feedback_text" style="color: #1e293b; font-style: italic; margin: 0;">No feedback text provided.</p>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     ✅ REDESIGNED: OVERALL FEEDBACK MODAL (Edit Your Review)
     ============================================================ -->
<div class="modal" id="overallFeedbackModal">
    <div class="modal-content review-modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-star"></i> <?php echo $user_has_feedback ? 'Edit Your Review' : 'Rate Your Experience'; ?>
            </h3>
            <button class="close" onclick="closeOverallFeedbackModal()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <?php if(isset($_SESSION['user_id'])): ?>
            <form method="POST" action="houses.php" id="overallFeedbackForm">
                <?php if($user_has_feedback): ?>
                    <div class="previous-rating-box">
                        <div class="label">You previously rated:</div>
                        <div class="stars">
                            <?php for($i=1; $i<=5; $i++): ?>
                                <span class="<?php echo $i <= $user_feedback['rating'] ? 'filled' : 'empty'; ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <div class="helper">Update your rating and review below</div>
                    </div>
                <?php endif; ?>

                <div class="current-rating-section">
                    <span class="review-section-label">Your Overall Rating</span>
                    <div class="current-rating-stars" id="overallRatingInput">
                        <i class="fas fa-star" data-rating="1" onclick="setOverallRating(1)"></i>
                        <i class="fas fa-star" data-rating="2" onclick="setOverallRating(2)"></i>
                        <i class="fas fa-star" data-rating="3" onclick="setOverallRating(3)"></i>
                        <i class="fas fa-star" data-rating="4" onclick="setOverallRating(4)"></i>
                        <i class="fas fa-star" data-rating="5" onclick="setOverallRating(5)"></i>
                    </div>
                    <input type="hidden" name="rating" id="overall_feedback_rating" value="<?php echo $user_has_feedback ? $user_feedback['rating'] : 0; ?>" required>
                    <p class="rating-text" id="overallRatingText">
                        <?php 
                        if($user_has_feedback && $user_feedback['rating'] > 0) {
                            $ratingTexts = [1=>'Very Poor', 2=>'Poor', 3=>'Average', 4=>'Good', 5=>'Excellent!'];
                            echo $ratingTexts[$user_feedback['rating']] ?? 'Select a rating';
                        } else {
                            echo 'Select a rating';
                        }
                        ?>
                    </p>
                </div>

                <div class="comment-wrapper">
                    <label class="review-section-label" for="overallComment">Your Comment</label>
                    <textarea name="comment" id="overallComment" placeholder="Share your overall experience..."><?php echo $user_has_feedback ? htmlspecialchars($user_feedback['comment']) : ''; ?></textarea>
                </div>

                <div class="anonymous-toggle">
                    <input type="checkbox" name="is_anonymous" id="overall_is_anonymous" value="1" <?php echo ($user_has_feedback && isset($user_feedback['is_anonymous']) && $user_feedback['is_anonymous']) ? 'checked' : ''; ?>>
                    <label class="toggle-label" for="overall_is_anonymous">
                        <i class="fas fa-user-secret"></i> Post as Anonymous
                    </label>
                    <span class="toggle-hint">Your name will not be shown publicly</span>
                </div>

                <button type="submit" name="submit_feedback" class="btn-update-review">
                    <i class="fas fa-paper-plane"></i> <?php echo $user_has_feedback ? 'Update Review' : 'Submit Review'; ?>
                </button>
            </form>
        <?php else: ?>
            <div style="text-align: center; padding: 30px; color: #4a6a8c;">
                <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 15px; color: #4DA6D9;"></i>
                <p>Please <a href="login.php" style="color: #4DA6D9; font-weight: 600; text-decoration: none;">login</a> to leave a review.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- BOOKING TERMS MODAL -->
<div class="modal booking-terms-modal" id="bookingTermsModal">
    <div class="modal-content booking-terms-content">

        <div class="booking-terms-header">
            <div class="booking-terms-icon">
                <i class="fas fa-file-contract"></i>
            </div>
            <h3 id="bookingTermsModalTitle"><?php echo htmlspecialchars($bookingTermsTitle); ?></h3>
            <p id="bookingTermsModalSubtitle">Please read and accept our booking terms</p>

            <button type="button"
                    class="booking-terms-close"
                    id="bookingTermsCloseBtn"
                    onclick="closeBookingTermsModal()"
                    aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="booking-terms-scroll" id="bookingTermsScrollContainer">

            <div class="booking-terms-body" id="bookingTermsBody">
                <?php
                if (preg_match('/<[^>]+>/', $bookingTermsBody)) {
                    echo $bookingTermsBody;
                } else {
                    echo nl2br(htmlspecialchars($bookingTermsBody));
                }
                ?>
            </div>

        </div>

        <div class="booking-terms-scroll-hint" id="bookingTermsScrollHint">
            <i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox
        </div>

        <form id="bookingTermsAcceptForm">
            <label class="booking-terms-checkbox-label" for="booking_terms_agree">
                <input type="checkbox" id="booking_terms_agree" value="1" disabled>
                <span class="booking-terms-checkbox-text">
                    <i class="fas fa-check-circle" style="color: #10b981;"></i>
                    I understand there is <strong>NO CANCELLATION</strong> and only <strong>REBOOKING</strong> is allowed. I accept these terms.
                </span>
            </label>
            <button type="button" class="booking-terms-accept-btn" id="bookingTermsAcceptBtn" disabled onclick="confirmBookingAfterTerms()">
                <i class="fas fa-check"></i> Accept &amp; Confirm Booking
            </button>
        </form>

        <p class="booking-terms-footer-note">
            You can review these terms anytime before confirming.
        </p>
    </div>
</div>

<!-- GCASH PAYMENT POPUP -->
<div class="payment-popup-overlay" id="paymentPopup">
    <div class="payment-popup">
        <div class="popup-header">
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <h2>Booking Received! 🎉</h2>
            <p>Pay the reservation fee via GCash to secure your booking</p>
        </div>
        
        <div class="popup-body">
            <div style="text-align: center; margin-bottom: 15px;">
                <span class="ref-number" id="popup_reference">HS-20241201-1234</span>
            </div>
            
            <div class="payment-detail">
                <span class="label">House</span>
                <span class="value" id="popup_house">House Name</span>
            </div>

            <div class="payment-detail">
                <span class="label">Check-in</span>
                <span class="value" id="popup_checkin">—</span>
            </div>
            <div class="payment-detail">
                <span class="label">Check-out</span>
                <span class="value" id="popup_checkout">—</span>
            </div>

            <div class="payment-detail">
                <span class="label">Total Price</span>
                <span class="value" id="popup_amount">₱0.00</span>
            </div>
            <div class="payment-detail">
                <span class="label">Reservation Fee (pay now)</span>
                <span class="value amount" id="popup_fee">₱0.00</span>
            </div>
            <div class="payment-detail">
                <span class="label">Balance on Arrival</span>
                <span class="value" id="popup_balance">₱0.00</span>
            </div>
            <p style="font-size:12px;color:#64748b;margin:6px 0 0;line-height:1.5;">
                Pay only the reservation fee now to secure your booking. It is part of your total price and is non-refundable;
                paid reservations may be rebooked. The remaining balance is paid upon arrival / check-in.
            </p>
            
            <div class="qr-section">
                <?php if($gcash_qr && file_exists("uploads/gcash/" . $gcash_qr)): ?>
                    <img src="uploads/gcash/<?php echo $gcash_qr; ?>" alt="GCash QR Code">
                <?php else: ?>
                    <div class="no-qr">
                        <i class="fas fa-qrcode" style="font-size: 48px; display: block; margin-bottom: 10px; color: #94a3b8;"></i>
                        <p>QR Code will appear here</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($gcash_configured): ?>
            <div style="background: #f8fafc; padding: 12px; border-radius: 12px; margin: 10px 0;">
                <p style="margin: 0; font-size: 14px;"><strong><i class="fas fa-user"></i> Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?></p>
                <p style="margin: 0; font-size: 14px;"><strong><i class="fas fa-mobile-alt"></i> GCash Number:</strong> <?php echo htmlspecialchars($gcash_number); ?></p>
            </div>
            <?php else: ?>
            <div style="background: #fff7ed; border:1px solid #fed7aa; color:#9a3412; padding: 12px; border-radius: 12px; margin: 10px 0; font-size:13px;">
                <strong><i class="fas fa-exclamation-triangle"></i> Payment account configuration required.</strong>
                The GCash payment details have not been set up yet. Please contact us before sending any payment.
            </div>
            <?php endif; ?>
            
            <div class="instructions">
                <strong><i class="fas fa-info-circle"></i> How to Pay:</strong>
                <p style="margin: 5px 0 0; white-space: pre-line;"><?php echo nl2br(htmlspecialchars($gcash_instructions)); ?></p>
            </div>
        </div>
        
        <div class="popup-actions">
            <button class="btn-pay-now" onclick="window.location.href='profile.php'">
                <i class="fas fa-upload"></i> Upload Proof
            </button>
            <button class="btn-close-popup" onclick="closePaymentPopup()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- FOOTER -->
<?php include 'components/footer.php'; ?>

<script>
// ============================================================
// ✅ SHOPEE-STYLE VIEW MODAL — HOUSE DATA
// ============================================================
const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;

const houseData = <?php
    $hd = [];
    foreach($houses as $house) {
        $gallery = $house_gallery[$house['id']] ?? [];
        $mainImage = getHouseMainImage($house, $gallery);
        $amenities = parseAmenities($house['amenities'] ?? '');
        $hd[$house['id']] = [
            'id' => (int)$house['id'],
            'name' => $house['house_name'],
            'description' => $house['description'] ?? '',
            'price' => (float)$house['price_per_night'],
            'capacity' => $house['capacity'] ?? 0,
            'bedrooms' => $house['bedrooms'] ?? 0,
            'status' => $house['status'] ?? 'available',
            'is_featured' => isset($house['is_featured']) && $house['is_featured'],
            'main_image' => $mainImage,
            'gallery' => $gallery,
            'amenities' => $amenities,
        ];
    }
    echo json_encode($hd);
?>;

// ✅ NEW: Blocked dates per house (from blocked_dates table)
const blockedDatesData = <?php echo json_encode($blocked_dates_map); ?>;

const houseGalleryData = <?php
    $gd = [];
    foreach($houses as $house) {
        $gallery = $house_gallery[$house['id']] ?? [];
        if (!empty($gallery)) $gd[$house['id']] = $gallery;
    }
    echo json_encode($gd);
?>;

let shopeeCurrentHouseId = null;
let shopeeCurrentImageIndex = 0;

function openShopeeView(houseId) {
    const house = houseData[houseId];
    if (!house) return;

    shopeeCurrentHouseId = houseId;
    shopeeCurrentImageIndex = 0;

    const isAvailable = (house.status === 'available');
    const isFeatured = house.is_featured;

    // Build gallery images array
    let images = [];
    if (house.gallery && house.gallery.length > 0) {
        images = house.gallery.map(function(img) {
            return { src: 'uploads/houses/gallery/' + img.image, image: img.image };
        });
    } else if (house.main_image) {
        images = [{ src: house.main_image, image: '' }];
    }

    // Build badges
    let badgesHtml = '';
    if (isFeatured) {
        badgesHtml += '<span class="badge badge-featured" style="background:#F4B400; color:#0B2447; padding:4px 14px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px;"><i class="fas fa-star"></i> Featured</span>';
    }

    // Build thumbnails
    let thumbsHtml = '';
    if (images.length > 1) {
        images.forEach(function(img, idx) {
            thumbsHtml += '<img src="' + img.src + '" class="shopee-thumb' + (idx === 0 ? ' active' : '') + '" onclick="switchShopeeImage(' + idx + ')" alt="Thumb ' + (idx+1) + '">';
        });
    }

    // Build actions
    let actionsHtml = '';
    if (isAvailable) {
        if (isLoggedIn) {
            actionsHtml = '<button class="btn-book" onclick="event.stopPropagation(); closeShopeeView(); bookHouse(' + house.id + ', ' + house.price + ', \'' + escapeJs(house.name) + '\')">' +
                '<i class="fas fa-calendar-check"></i> Book Now</button>';
        } else {
            actionsHtml = '<a href="login.php?redirect=houses.php" class="btn-book" style="text-decoration:none;" onclick="event.stopPropagation();">' +
                '<i class="fas fa-sign-in-alt"></i> Login to Book</a>';
        }
    } else {
        actionsHtml = '<button class="btn-book" disabled><i class="fas fa-clock"></i> Not Available</button>';
    }

    // Login note
    let loginNote = '';
    if (!isLoggedIn && isAvailable) {
        loginNote = '<div class="shopee-login-note"><i class="fas fa-lock"></i> You need to <a href="login.php?redirect=houses.php">login</a> or <a href="register.php">register</a> to book this house.</div>';
    }

    // Description
    let descHtml = '';
    if (house.description) {
        descHtml = '<div class="shopee-detail-section"><h4><i class="fas fa-align-left"></i> Description</h4><p>' + nl2br(escapeHtml(house.description)) + '</p></div>';
    }

    // Amenities
    let amenitiesHtml = '';
    if (house.amenities && house.amenities.length > 0) {
        amenitiesHtml = '<div class="shopee-detail-section"><h4><i class="fas fa-list-check"></i> Inclusions / Amenities</h4><div class="shopee-amenities-list">';
        house.amenities.forEach(function(a) {
            amenitiesHtml += '<div class="shopee-amenity-item"><i class="fas fa-check-circle"></i> <span>' + escapeHtml(a) + '</span></div>';
        });
        amenitiesHtml += '</div></div>';
    }

    // Main image
    let mainImageHtml = '';
    if (images.length > 0) {
        mainImageHtml = '<img id="shopeeMainImg" class="shopee-main-image" src="' + images[0].src + '" alt="' + escapeHtml(house.name) + '">';
    } else {
        mainImageHtml = '<div class="shopee-main-image placeholder"><i class="fas fa-home"></i></div>';
    }

    const bodyHtml = `
        <div class="shopee-gallery">
            <div class="shopee-main-image-wrap" id="shopeeMainImageWrap">
                ${mainImageHtml}
            </div>
            <div class="shopee-thumbnails" id="shopeeThumbContainer">
                ${thumbsHtml}
            </div>
        </div>
        <div class="shopee-details">
            <div class="shopee-detail-badges">${badgesHtml}</div>
            <h2 class="shopee-detail-title">${escapeHtml(house.name)}</h2>
            <div class="shopee-detail-category"><i class="fas fa-home"></i> Transient House</div>
            <div class="shopee-detail-features">
                <span class="shopee-detail-feature"><i class="fas fa-users"></i> ${house.capacity || 'N/A'} pax</span>
                <span class="shopee-detail-feature"><i class="fas fa-bed"></i> ${house.bedrooms || 'N/A'} bedrooms</span>
            </div>
            <div class="shopee-price-section">
                <div class="shopee-price-main">₱${formatNumber(house.price)}<span style="font-size:14px; color:#64748b; font-weight:500; margin-left:4px;">/night</span></div>
                <div class="shopee-price-label">Price per Night</div>
            </div>
            ${descHtml}
            ${amenitiesHtml}
            <div class="shopee-actions">
                ${actionsHtml}
                <button class="btn-secondary" onclick="closeShopeeView()"><i class="fas fa-arrow-left"></i> Back</button>
            </div>
            ${loginNote}
        </div>
    `;

    document.getElementById('shopeeModalBody').innerHTML = bodyHtml;

    const mainWrap = document.getElementById('shopeeMainImageWrap');
    if (mainWrap) {
        mainWrap.addEventListener('click', function(e) {
            if (images.length > 1) {
                switchShopeeImage((shopeeCurrentImageIndex + 1) % images.length);
            }
        });
    }

    window.__shopeeImages = images;
    document.getElementById('shopeeViewModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function switchShopeeImage(index) {
    const images = window.__shopeeImages || [];
    if (index < 0 || index >= images.length) return;
    shopeeCurrentImageIndex = index;

    const mainImg = document.getElementById('shopeeMainImg');
    if (mainImg) {
        mainImg.src = images[index].src;
        mainImg.style.opacity = '0.5';
        setTimeout(function() { mainImg.style.opacity = '1'; }, 150);
    }

    document.querySelectorAll('.shopee-thumb').forEach(function(thumb, idx) {
        thumb.classList.toggle('active', idx === index);
    });
}

function closeShopeeView() {
    document.getElementById('shopeeViewModal').classList.remove('show');
    document.body.style.overflow = 'auto';
    shopeeCurrentHouseId = null;
    window.__shopeeImages = [];
}

// Helpers
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

function escapeJs(text) {
    if (!text) return '';
    return text.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '');
}

function formatNumber(num) {
    return parseFloat(num).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function nl2br(str) {
    return str.replace(/\n/g, '<br>');
}

// ============================================================
// DATE HELPERS
// ============================================================
function parseDateYMD(dateStr) {
    if (!dateStr) return null;
    var parts = dateStr.split('-');
    return new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
}

function formatDateDisplay(dateStr) {
    if (!dateStr) return '';
    var parts = dateStr.split('-');
    var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return monthNames[parseInt(parts[1]) - 1] + ' ' + parseInt(parts[2]) + ', ' + parseInt(parts[0]);
}

function formatTimeDisplay(time24) {
    if (!time24) return '';
    var parts = time24.split(':');
    var hours = parseInt(parts[0]);
    var minutes = parts[1];
    var ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    return hours + ':' + minutes + ' ' + ampm;
}

// DISMISS ALERT
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
// OVERALL FEEDBACK
// ============================================================
var overallSelectedRating = <?php echo $user_has_feedback ? $user_feedback['rating'] : 0; ?>;
var overallRatingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

function openOverallFeedbackModal(event) {
    if (event) event.preventDefault();
    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();
    document.getElementById('overallFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeOverallFeedbackModal() {
    document.getElementById('overallFeedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function setOverallRating(rating) {
    overallSelectedRating = rating;
    document.getElementById('overall_feedback_rating').value = rating;
    document.getElementById('overallRatingText').textContent = overallRatingTexts[rating] || 'Select a rating';
    document.querySelectorAll('#overallRatingInput i').forEach(function(star, index) {
        star.classList.toggle('active', index < rating);
        if (index < rating) {
            star.style.color = '#F4B400';
        } else {
            star.style.color = '#d1d9e6';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if($user_has_feedback && $user_feedback['rating'] > 0): ?>
        var rating = <?php echo $user_feedback['rating']; ?>;
        document.querySelectorAll('#overallRatingInput i').forEach(function(star, index) {
            star.classList.toggle('active', index < rating);
            if (index < rating) {
                star.style.color = '#F4B400';
            } else {
                star.style.color = '#d1d9e6';
            }
        });
        document.getElementById('overallRatingText').textContent = overallRatingTexts[rating] || 'Select a rating';
    <?php else: ?>
        document.querySelectorAll('#overallRatingInput i').forEach(function(star) {
            star.style.color = '#d1d9e6';
        });
    <?php endif; ?>
});

// ============================================================
// BOOKING STATE
// ============================================================
let currentPrice = 0;
let currentHouseId = 0;
let currentHouseName = '';
let currentBookedDates = [];
let currentMonth = new Date().getMonth();
let currentYear = new Date().getFullYear();
let selectedStartDate = null;
let selectedEndDate = null;
let selectedRating = 0;
let ratingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

var __pendingBookingSubmit = false;

function showModal(type, event) {
    if (event) event.preventDefault();
    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();
    var modal = document.getElementById(type + 'Modal');
    if(modal) modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function hideModal(type) {
    var modal = document.getElementById(type + 'Modal');
    if(modal) modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

function showPaymentPopup(reference, houseName, total, checkIn, checkInTime, checkOut, checkOutTime) {
    document.getElementById('popup_reference').textContent = reference;
    document.getElementById('popup_house').textContent = houseName;
    var totalNum = parseFloat(total) || 0;
    var feeNum = Math.min(<?php echo json_encode(PaymentService::RESERVATION_FEE); ?>, totalNum);
    var pesoFmt = function (n) { return '₱' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    document.getElementById('popup_amount').textContent = pesoFmt(totalNum);
    document.getElementById('popup_fee').textContent = pesoFmt(feeNum);
    document.getElementById('popup_balance').textContent = pesoFmt(Math.max(0, totalNum - feeNum));

    var checkinEl = document.getElementById('popup_checkin');
    var checkoutEl = document.getElementById('popup_checkout');
    if (checkinEl) {
        if (checkIn) {
            var ciDate = formatDateDisplay(checkIn);
            var ciTime = checkInTime ? formatTimeDisplay(checkInTime) : '';
            checkinEl.textContent = ciDate + (ciTime ? ' • ' + ciTime : '');
        } else {
            checkinEl.textContent = '—';
        }
    }
    if (checkoutEl) {
        if (checkOut) {
            var coDate = formatDateDisplay(checkOut);
            var coTime = checkOutTime ? formatTimeDisplay(checkOutTime) : '';
            checkoutEl.textContent = coDate + (coTime ? ' • ' + coTime : '');
        } else {
            checkoutEl.textContent = '—';
        }
    }

    document.getElementById('paymentPopup').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closePaymentPopup() {
    document.getElementById('paymentPopup').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// ============================================================
// GUEST NAME SANITIZER
// ============================================================
function sanitizeName(value) {
    return value
        .replace(/[^a-zA-ZÀ-ÿ\s\-'.]/g, '')
        .replace(/\s{2,}/g, ' ')
        .slice(0, 50);
}

function validatePax(input) {
    let raw = input.value.trim();
    if (raw === '') { rebuildGuestNameInputs(); return; }
    let val = parseInt(raw);
    if (isNaN(val) || val < 1) {
        input.value = 1;
    } else if (val > 20) {
        input.value = 20;
        alert('Maximum of 20 guests allowed.');
    }
    rebuildGuestNameInputs();
    calculateTotal();
}

function rebuildGuestNameInputs() {
    const paxInput = document.getElementById('guests');
    const listEl = document.getElementById('guestNamesList');
    const badgeEl = document.getElementById('guestCountBadge');
    const helpEl = document.getElementById('guestNamesHelp');
    const hiddenEl = document.getElementById('guest_names');

    if (!paxInput || !listEl || !badgeEl || !hiddenEl) return;

    const isFreshReset = listEl.dataset.freshReset === '1';
    let currentNames = [];

    if (!isFreshReset) {
        const existingInputs = listEl.querySelectorAll('.guest-name-input');
        if (existingInputs.length > 0) {
            currentNames = Array.from(existingInputs).map(inp => inp.value);
        }
    } else {
        delete listEl.dataset.freshReset;
    }

    const pax = parseInt(paxInput.value) || 0;
    listEl.innerHTML = '';

    if (pax <= 0) {
        badgeEl.textContent = '0 / 0';
        badgeEl.classList.remove('match', 'over', 'under');
        badgeEl.classList.add('under');
        if (helpEl) {
            helpEl.classList.remove('error');
            helpEl.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of pax above to add guest name fields';
        }
        syncGuestNamesHidden();
        return;
    }

    for (let i = 0; i < pax; i++) {
        const row = document.createElement('div');
        row.className = 'guest-name-row';

        const badge = document.createElement('span');
        badge.className = 'guest-badge';
        badge.textContent = (i + 1);

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'guest-name-input';
        input.placeholder = 'Enter guest ' + (i + 1) + ' full name';
        input.maxLength = 50;
        input.autocomplete = 'off';
        input.value = currentNames[i] || '';

        input.addEventListener('input', function() {
            const before = this.value;
            const after = sanitizeName(before);
            if (before !== after) {
                const pos = this.selectionStart;
                const diff = before.length - after.length;
                this.value = after;
                try { this.setSelectionRange(pos - diff, pos - diff); } catch (e) {}
            }
            this.classList.toggle('invalid', this.value.trim().length > 0 && this.value.trim().length < 2);
            this.classList.toggle('valid', this.value.trim().length >= 2);
            updateGuestCountBadge();
            syncGuestNamesHidden();
        });

        input.addEventListener('keypress', function(e) {
            const char = String.fromCharCode(e.which);
            if (!/[a-zA-ZÀ-ÿ\s\-'.]/.test(char)) {
                e.preventDefault();
            }
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text');
            const cleaned = sanitizeName(pasted);
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.slice(0, start) + cleaned + this.value.slice(end);
            updateGuestCountBadge();
            syncGuestNamesHidden();
        });

        row.appendChild(badge);
        row.appendChild(input);
        listEl.appendChild(row);
    }

    updateGuestCountBadge();
    syncGuestNamesHidden();
}

function updateGuestCountBadge() {
    const badgeEl = document.getElementById('guestCountBadge');
    const helpEl = document.getElementById('guestNamesHelp');
    if (!badgeEl) return;

    const inputs = document.querySelectorAll('#guestNamesList .guest-name-input');
    const filled = Array.from(inputs).filter(i => i.value.trim().length >= 2).length;
    const total = inputs.length;

    badgeEl.textContent = filled + ' / ' + total;
    badgeEl.classList.remove('match', 'over', 'under');

    if (total === 0) {
        badgeEl.classList.add('under');
        if (helpEl) {
            helpEl.classList.remove('error');
            helpEl.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of pax above to add guest name fields';
        }
        return;
    }

    if (filled === total && total > 0) {
        badgeEl.classList.add('match');
        if (helpEl) {
            helpEl.classList.remove('error');
            helpEl.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> All guest names filled. Ready to book!';
        }
    } else {
        badgeEl.classList.add('under');
        if (helpEl) {
            helpEl.classList.remove('error');
            helpEl.innerHTML = '<i class="fas fa-info-circle"></i> Enter the full name of each guest (letters only, no numbers or special characters)';
        }
    }
}

function syncGuestNamesHidden() {
    const inputs = document.querySelectorAll('#guestNamesList .guest-name-input');
    const names = Array.from(inputs).map(i => i.value.trim()).filter(n => n.length > 0);
    document.getElementById('guest_names').value = names.join('\n');
}

function calculateTotal() {
    let checkIn = document.getElementById('check_in').value;
    let checkOut = document.getElementById('check_out').value;
    let guestsRaw = document.getElementById('guests').value.trim();
    let guests = parseInt(guestsRaw) || 0;

    if (guestsRaw !== '' && guests < 1) {
        guests = 1;
        document.getElementById('guests').value = 1;
    }
    if (guests > 20) {
        alert('Maximum of 20 guests allowed.');
        guests = 20;
        document.getElementById('guests').value = 20;
    }
    
    if(checkIn && checkOut) {
        var start = parseDateYMD(checkIn);
        var end = parseDateYMD(checkOut);
        var startUTC = Date.UTC(start.getFullYear(), start.getMonth(), start.getDate());
        var endUTC = Date.UTC(end.getFullYear(), end.getMonth(), end.getDate());
        var nights = Math.round((endUTC - startUTC) / (1000 * 60 * 60 * 24));
        
        if(nights <= 0) {
            alert('Check-out must be after check-in');
            document.getElementById('check_out').value = '';
            return;
        }
        
        var days = nights + 1;
        var total = nights * currentPrice;
        
        document.getElementById('display_days').textContent = days;
        document.getElementById('display_nights').textContent = nights;
        document.getElementById('display_total').textContent = total.toFixed(2);
        document.getElementById('total_amount').value = total;
        
        var guestsDisplay = guests > 0 ? guests + ' guest(s)' : 'guests not yet set';
        document.getElementById('calendarInfo').innerHTML = '✅ Selected: <strong>' + formatDateDisplay(checkIn) + '</strong> to <strong>' + formatDateDisplay(checkOut) + '</strong> (' + nights + ' nights, ' + days + ' days) | ' + guestsDisplay;
    }
}

function generateCalendar(month, year, bookedDates) {
    const grid = document.getElementById('calendarGrid');
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    
    document.getElementById('calendarMonthYear').textContent = monthNames[month] + ' ' + year;
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    let html = '';
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    dayNames.forEach(day => { html += `<div class="day-name">${day}</div>`; });
    
    for (let i = 0; i < firstDay; i++) html += `<div class="day disabled"></div>`;
    
    for (let day = 1; day <= daysInMonth; day++) {
        var dateObj = new Date(year, month, day);
        var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        var isPast = dateObj < today;
        
        var isBooked = false;
        var isBlocked = false;
        for (var j = 0; j < bookedDates.length; j++) {
            var bookedIn = parseDateYMD(bookedDates[j].check_in_date);
            var bookedOut = parseDateYMD(bookedDates[j].check_out_date);
            // nights rule: [check-in, check-out) — the check-out day is free for the next guest
            if (bookedIn && bookedOut && dateObj >= bookedIn && dateObj < bookedOut) {
                if (bookedDates[j]._is_blocked) {
                    isBlocked = true;
                } else {
                    isBooked = true;
                }
                break;
            }
        }
        
        var classes = 'day';
        if (isPast) classes += ' past';
        if (isBlocked) classes += ' blocked';
        else if (isBooked) classes += ' booked';
        if (selectedStartDate && dateStr === selectedStartDate) classes += ' selected';
        if (selectedEndDate && dateStr === selectedEndDate) classes += ' selected';
        
        html += `<div class="${classes}" data-date="${dateStr}" onclick="selectDate('${dateStr}')">${day}</div>`;
    }
    
    grid.innerHTML = html;
    updateCalendarInfo();
}

function changeMonth(delta) {
    currentMonth += delta;
    if (currentMonth > 11) { currentMonth = 0; currentYear++; }
    else if (currentMonth < 0) { currentMonth = 11; currentYear--; }
    generateCalendar(currentMonth, currentYear, currentBookedDates);
}

function selectDate(dateStr) {
    const checkInInput = document.getElementById('check_in');
    const checkOutInput = document.getElementById('check_out');
    var selectedDate = parseDateYMD(dateStr);
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (selectedStartDate === dateStr && !selectedEndDate) {
        selectedStartDate = null;
        checkInInput.value = '';
        generateCalendar(currentMonth, currentYear, currentBookedDates);
        updateCalendarInfo();
        return;
    }
    
    if (selectedDate < today) { alert('❌ Cannot select past dates.'); return; }
    
    // ✅ Check for booked OR blocked nights.
    // Picking check-in: that night must be free. Picking check-out: every night from
    // check-in up to (not including) the check-out day must be free — the check-out
    // day itself may be someone else's check-in day (12 NN check-out / 2 PM check-in).
    var pickingCheckOut = !!(selectedStartDate && !selectedEndDate && selectedDate > parseDateYMD(selectedStartDate));
    var rangeStart = pickingCheckOut ? parseDateYMD(selectedStartDate) : selectedDate;
    var rangeEnd = pickingCheckOut ? selectedDate : new Date(selectedDate.getFullYear(), selectedDate.getMonth(), selectedDate.getDate() + 1);
    var conflict = null;
    for (var j = 0; j < currentBookedDates.length; j++) {
        var bookedIn = parseDateYMD(currentBookedDates[j].check_in_date);
        var bookedOut = parseDateYMD(currentBookedDates[j].check_out_date);
        if (bookedIn && bookedOut && rangeStart < bookedOut && rangeEnd > bookedIn) {
            conflict = currentBookedDates[j];
            break;
        }
    }
    
    if (conflict) {
        if (conflict._is_blocked) {
            var reasonLabel = conflict._reason || 'Not available';
            var blockTypeLabel = (conflict._block_type || '').replace(/_/g, ' ');
            alert('🚫 This date is BLOCKED.\n\nReason: ' + reasonLabel + '\nType: ' + blockTypeLabel + '\n\nPlease select another date.');
        } else {
            alert('❌ This date is already booked. Please select another date.');
        }
        return;
    }
    
    if (selectedStartDate && !selectedEndDate) {
        var start = parseDateYMD(selectedStartDate);
        if (start && selectedDate <= start) { alert('❌ Check-out must be after check-in date.'); return; }
    }
    
    if (!selectedStartDate || (selectedStartDate && selectedEndDate)) {
        selectedStartDate = dateStr;
        selectedEndDate = null;
        checkInInput.value = dateStr;
        checkOutInput.value = '';
    } else {
        selectedEndDate = dateStr;
        checkOutInput.value = dateStr;
        calculateTotal();
    }
    
    generateCalendar(currentMonth, currentYear, currentBookedDates);
    updateCalendarInfo();
}

// Business rule: check-out TIME always follows check-in TIME (the date still follows the nights).
function syncCheckoutTime() {
    var i = document.getElementById('check_in_time'), o = document.getElementById('check_out_time');
    if (i && o) o.value = i.value;
}

function updateCalendarInfo() {
    syncCheckoutTime();
    const info = document.getElementById('calendarInfo');
    
    var checkInTimeEl = document.getElementById('check_in_time');
    var checkOutTimeEl = document.getElementById('check_out_time');
    var checkInTime = checkInTimeEl ? checkInTimeEl.value : '';
    var checkOutTime = checkOutTimeEl ? checkOutTimeEl.value : '';
    
    if (selectedStartDate && selectedEndDate) {
        var start = parseDateYMD(selectedStartDate);
        var end = parseDateYMD(selectedEndDate);
        if (start && end) {
            var startUTC = Date.UTC(start.getFullYear(), start.getMonth(), start.getDate());
            var endUTC = Date.UTC(end.getFullYear(), end.getMonth(), end.getDate());
            var nights = Math.round((endUTC - startUTC) / (1000 * 60 * 60 * 24));
            var days = nights + 1;
            var guests = parseInt(document.getElementById('guests').value) || 0;
            var guestsDisplay = guests > 0 ? guests + ' guest(s)' : 'guests not yet set';
            
            var checkInLabel = formatDateDisplay(selectedStartDate) + (checkInTime ? ' ' + formatTimeDisplay(checkInTime) : '');
            var checkOutLabel = formatDateDisplay(selectedEndDate) + (checkOutTime ? ' ' + formatTimeDisplay(checkOutTime) : '');
            
            info.innerHTML = '✅ Selected: <strong>' + checkInLabel + '</strong> to <strong>' + checkOutLabel + '</strong> (' + nights + ' nights, ' + days + ' days) | ' + guestsDisplay;
        }
    } else if (selectedStartDate) {
        var checkInLabel = formatDateDisplay(selectedStartDate) + (checkInTime ? ' ' + formatTimeDisplay(checkInTime) : '');
        info.innerHTML = '📅 Check-in: <strong>' + checkInLabel + '</strong> - Select check-out date';
    } else {
        info.innerHTML = '📆 Select check-in and check-out dates';
    }
}

function updateCalendarSelection() {
    // Typed dates bypass the calendar: reject anything before today
    var _t = new Date();
    var _today = _t.getFullYear() + '-' + String(_t.getMonth() + 1).padStart(2, '0') + '-' + String(_t.getDate()).padStart(2, '0');
    ['check_in', 'check_out'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.value && el.value < _today) {
            el.value = '';
            alert('Selected date is no longer available. Please choose a future date.');
        }
    });
    const checkIn = document.getElementById('check_in').value;
    const checkOut = document.getElementById('check_out').value;
    
    if (checkIn) {
        selectedStartDate = checkIn;
        var date = parseDateYMD(checkIn);
        if (date) {
            currentMonth = date.getMonth();
            currentYear = date.getFullYear();
            generateCalendar(currentMonth, currentYear, currentBookedDates);
        }
    }
    if (checkOut) { selectedEndDate = checkOut; calculateTotal(); }
    updateCalendarInfo();
}

function bookHouse(houseId, price, houseName) {
    <?php if(!isset($_SESSION['user_id'])): ?>
        window.location.href = 'login.php?redirect=houses.php';
        return;
    <?php endif; ?>
    
    currentPrice = price;
    currentHouseId = houseId;
    currentHouseName = houseName;
    selectedStartDate = null;
    selectedEndDate = null;
    
    document.getElementById('booking_house_id').value = houseId;
    document.getElementById('booking_house_name').value = houseName;
    document.getElementById('display_house').value = houseName;
    document.getElementById('display_price').value = '₱' + price.toFixed(2);
    
    document.getElementById('check_in').value = '';
    document.getElementById('check_out').value = '';
    document.getElementById('check_in_time').value = '14:00';
    document.getElementById('check_out_time').value = '14:00';
    document.getElementById('guests').value = '';
    document.getElementById('display_days').textContent = '0';
    document.getElementById('display_nights').textContent = '0';
    document.getElementById('display_total').textContent = '0.00';
    
    // ✅ Get existing booked dates
    currentBookedDates = <?php echo json_encode($booked_dates); ?>[houseId] || [];
    
    // ✅ NEW: Get blocked dates for this house
    var blocked = blockedDatesData[houseId] || [];
    
    // ✅ Each blocked date is a one-night range [date, next day) (appears red in calendar)
    blocked.forEach(function(bd) {
        var p = String(bd.block_date).split('-');
        var nx = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10) + 1);
        currentBookedDates.push({
            check_in_date: bd.block_date,
            check_out_date: nx.getFullYear() + '-' + String(nx.getMonth() + 1).padStart(2, '0') + '-' + String(nx.getDate()).padStart(2, '0'),
            _is_blocked: true,
            _reason: bd.reason || 'Blocked',
            _block_type: bd.block_type || 'walk_in'
        });
    });
    
    document.getElementById('guestNamesList').innerHTML = '';
    document.getElementById('guest_names').value = '';
    document.getElementById('guestNamesList').dataset.freshReset = '1';
    rebuildGuestNameInputs();
    updateGuestCountBadge();
    
    const today = new Date();
    currentMonth = today.getMonth();
    currentYear = today.getFullYear();
    generateCalendar(currentMonth, currentYear, currentBookedDates);
    
    showModal('booking');
}

function requestBookingWithTerms() {
    syncGuestNamesHidden();
    if (!validateBookingFormBeforeTerms()) return;
    __pendingBookingSubmit = true;
    openBookingTermsModal();
}

function validateBookingFormBeforeTerms() {
    const checkIn = document.getElementById('check_in').value;
    const checkOut = document.getElementById('check_out').value;
    syncCheckoutTime();
    const checkInTime = document.getElementById('check_in_time').value;
    const checkOutTime = document.getElementById('check_out_time').value;

    if (!checkIn) { alert('❌ Please select a check-in date.'); return false; }
    if (!checkOut) { alert('❌ Please select a check-out date.'); return false; }
    if (!checkInTime) { alert('❌ Please select a check-in time.'); document.getElementById('check_in_time').focus(); return false; }
    if (!checkOutTime) { alert('❌ Please select a check-out time.'); document.getElementById('check_out_time').focus(); return false; }

    const paxRaw = document.getElementById('guests').value.trim();
    const guests = parseInt(paxRaw);

    if (paxRaw === '' || isNaN(guests) || guests < 1) {
        alert('❌ Please enter the number of pax (minimum 1).');
        document.getElementById('guests').focus();
        return false;
    }

    if (guests > 20) {
        alert('❌ Maximum of 20 guests allowed.');
        document.getElementById('guests').focus();
        return false;
    }

    const inputs = document.querySelectorAll('#guestNamesList .guest-name-input');
    const names = Array.from(inputs).map(i => i.value.trim()).filter(n => n.length > 0);

    if (names.length !== guests) {
        alert('❌ Please provide exactly ' + guests + ' guest name(s).\n\nYou entered ' + names.length + ' name(s).');
        return false;
    }

    for (let i = 0; i < names.length; i++) {
        const name = names[i];
        if (name.length < 2) {
            alert('❌ Guest ' + (i + 1) + ' name is too short. Please enter the full name.');
            inputs[i].focus();
            return false;
        }
        if (!/^[a-zA-ZÀ-ÿ\s\-'.]+$/.test(name)) {
            alert('❌ Guest ' + (i + 1) + ' name contains invalid characters. Only letters, spaces, hyphens, periods, and apostrophes are allowed.');
            inputs[i].focus();
            return false;
        }
    }

    return true;
}

function submitPendingBooking() {
    var bookingForm = document.getElementById('bookingForm');
    if (!bookingForm) return;

    syncGuestNamesHidden();

    var existing = bookingForm.querySelector('input[name="logged_booking"][data-dynamic="1"]');
    if (existing) existing.remove();

    var hiddenBtn = document.createElement('input');
    hiddenBtn.type = 'hidden';
    hiddenBtn.name = 'logged_booking';
    hiddenBtn.value = '1';
    hiddenBtn.setAttribute('data-dynamic', '1');
    bookingForm.appendChild(hiddenBtn);

    __pendingBookingSubmit = false;
    bookingForm.submit();
}

function confirmBookingAfterTerms() {
    closeBookingTermsModal();
    submitPendingBooking();
}

var __bookingTermsState = {
    hasReachedBottom: false
};

function openBookingTermsModal() {
    var modal = document.getElementById('bookingTermsModal');
    if (!modal) return;

    __bookingTermsState.hasReachedBottom = false;

    var closeBtn = document.getElementById('bookingTermsCloseBtn');
    var acceptForm = document.getElementById('bookingTermsAcceptForm');
    var scrollHint = document.getElementById('bookingTermsScrollHint');
    var scrollBox = document.getElementById('bookingTermsScrollContainer');
    var checkbox = document.getElementById('booking_terms_agree');
    var acceptBtn = document.getElementById('bookingTermsAcceptBtn');

    scrollHint.style.display = 'block';
    scrollHint.classList.remove('done');
    scrollHint.innerHTML = '<i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox';
    acceptForm.classList.remove('visible');
    checkbox.disabled = true;
    checkbox.checked = false;
    acceptBtn.disabled = true;

    scrollBox.scrollTop = 0;

    if (scrollBox.dataset.scrollWired !== '1') {
        scrollBox.dataset.scrollWired = '1';

        scrollBox.addEventListener('scroll', function() {
            var atBottom = (scrollBox.scrollTop + scrollBox.clientHeight) >= (scrollBox.scrollHeight - 15);
            if (atBottom) unlockBookingTermsAcceptForm();
        });
    }

    setTimeout(function() {
        var needsScroll = scrollBox.scrollHeight > scrollBox.clientHeight + 5;
        if (!needsScroll) {
            unlockBookingTermsAcceptForm();
        }
    }, 100);

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function unlockBookingTermsAcceptForm() {
    if (__bookingTermsState.hasReachedBottom) return;
    __bookingTermsState.hasReachedBottom = true;

    var acceptForm = document.getElementById('bookingTermsAcceptForm');
    var scrollHint = document.getElementById('bookingTermsScrollHint');
    var checkbox = document.getElementById('booking_terms_agree');

    acceptForm.classList.add('visible');
    checkbox.disabled = false;

    scrollHint.classList.add('done');
    scrollHint.innerHTML = '<i class="fas fa-check-circle"></i> You\'ve read everything. Please check the box below.';
}

function closeBookingTermsModal() {
    var modal = document.getElementById('bookingTermsModal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    __pendingBookingSubmit = false;
}

document.addEventListener('DOMContentLoaded', function() {
    var checkbox = document.getElementById('booking_terms_agree');
    var acceptBtn = document.getElementById('bookingTermsAcceptBtn');
    if (checkbox && acceptBtn) {
        checkbox.addEventListener('change', function() {
            acceptBtn.disabled = !checkbox.checked;
        });
    }
});

let sliderImages = [];
let sliderCurrentIndex = 0;
let sliderAutoInterval = null;
let sliderIsHovering = false;

function openGalleryModal(houseId, houseName) {
    document.getElementById('sliderMainImage').style.display = 'none';
    document.getElementById('sliderCounter').textContent = 'Loading...';
    document.getElementById('sliderThumbnails').innerHTML = '';
    
    var modal = document.getElementById('galleryModal');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    
    fetch('get_house_gallery.php?house_id=' + houseId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                document.getElementById('sliderMainImage').style.display = 'block';
                document.getElementById('sliderMainImage').src = 'https://via.placeholder.com/800x550/0B2447/ffffff?text=Error+Loading+Photos';
                document.getElementById('sliderCounter').textContent = 'Error';
                return;
            }
            
            if (data.images && data.images.length > 0) {
                sliderImages = data.images;
                sliderCurrentIndex = 0;
                var mainImage = document.getElementById('sliderMainImage');
                mainImage.src = 'uploads/houses/gallery/' + sliderImages[0]['image'];
                mainImage.style.display = 'block';
                updateSliderThumbnails();
                updateSliderCounter();
                startSliderAutoSlide();
            } else {
                document.getElementById('sliderMainImage').style.display = 'block';
                document.getElementById('sliderMainImage').src = 'https://via.placeholder.com/800x550/0B2447/ffffff?text=No+Photos+Available';
                document.getElementById('sliderCounter').textContent = '0 / 0';
            }
        })
        .catch(error => {
            document.getElementById('sliderMainImage').style.display = 'block';
            document.getElementById('sliderMainImage').src = 'https://via.placeholder.com/800x550/0B2447/ffffff?text=Error+Loading+Photos';
            document.getElementById('sliderCounter').textContent = 'Error';
        });
}

function updateSliderThumbnails() {
    var container = document.getElementById('sliderThumbnails');
    container.innerHTML = '';
    sliderImages.forEach(function(img, index) {
        var thumb = document.createElement('img');
        thumb.src = 'uploads/houses/gallery/' + img.image;
        thumb.className = 'gallery-thumbnail';
        if (index === sliderCurrentIndex) thumb.classList.add('active');
        thumb.onclick = function() { goToSliderImage(index); };
        container.appendChild(thumb);
    });
}

function updateSliderCounter() {
    document.getElementById('sliderCounter').textContent = (sliderCurrentIndex + 1) + ' / ' + sliderImages.length;
}

function changeSliderImage(direction) {
    if (sliderImages.length === 0) return;
    sliderCurrentIndex += direction;
    if (sliderCurrentIndex < 0) sliderCurrentIndex = sliderImages.length - 1;
    if (sliderCurrentIndex >= sliderImages.length) sliderCurrentIndex = 0;
    document.getElementById('sliderMainImage').src = 'uploads/houses/gallery/' + sliderImages[sliderCurrentIndex]['image'];
    document.querySelectorAll('.gallery-thumbnail').forEach(function(thumb, idx) {
        thumb.classList.toggle('active', idx === sliderCurrentIndex);
    });
    updateSliderCounter();
    resetSliderAutoSlide();
}

function goToSliderImage(index) {
    if (index < 0 || index >= sliderImages.length) return;
    sliderCurrentIndex = index;
    document.getElementById('sliderMainImage').src = 'uploads/houses/gallery/' + sliderImages[sliderCurrentIndex]['image'];
    document.querySelectorAll('.gallery-thumbnail').forEach(function(thumb, idx) {
        thumb.classList.toggle('active', idx === index);
    });
    updateSliderCounter();
    resetSliderAutoSlide();
}

function startSliderAutoSlide() {
    if (sliderAutoInterval) clearInterval(sliderAutoInterval);
    if (sliderImages.length > 1) {
        sliderAutoInterval = setInterval(function() { if (!sliderIsHovering) changeSliderImage(1); }, 4000);
    }
}

function stopSliderAutoSlide() {
    if (sliderAutoInterval) { clearInterval(sliderAutoInterval); sliderAutoInterval = null; }
}

function resetSliderAutoSlide() {
    stopSliderAutoSlide();
    if (sliderImages.length > 1 && !sliderIsHovering) startSliderAutoSlide();
}

function closeGalleryModal() {
    document.getElementById('galleryModal').classList.remove('open');
    document.body.style.overflow = 'auto';
    stopSliderAutoSlide();
    sliderIsHovering = false;
}

document.addEventListener('DOMContentLoaded', function() {
    var sliderContainer = document.querySelector('.gallery-slider-container');
    if (sliderContainer) {
        sliderContainer.addEventListener('mouseenter', function() { sliderIsHovering = true; stopSliderAutoSlide(); });
        sliderContainer.addEventListener('mouseleave', function() { sliderIsHovering = false; if (sliderImages.length > 1) startSliderAutoSlide(); });
    }
});

function openFeedbackModal(bookingId, houseName, reference) {
    document.getElementById('feedback_booking_id').value = bookingId;
    document.getElementById('feedback_house_name').textContent = houseName;
    document.getElementById('feedback_reference').textContent = reference;
    document.getElementById('feedback_text').value = '';
    document.getElementById('feedback_rating').value = 0;
    document.getElementById('feedback_is_anonymous').checked = false;
    selectedRating = 0;
    document.getElementById('ratingText').textContent = 'Select a rating';
    document.querySelectorAll('#ratingStars i').forEach(star => star.classList.remove('active'));
    document.getElementById('feedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeFeedbackModal() {
    document.getElementById('feedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function setRating(rating) {
    selectedRating = rating;
    document.getElementById('feedback_rating').value = rating;
    document.getElementById('ratingText').textContent = ratingTexts[rating] || 'Select a rating';
    document.querySelectorAll('#ratingStars i').forEach((star, index) => {
        star.classList.toggle('active', index < rating);
    });
}

function viewFeedback(text, rating, reference) {
    document.getElementById('view_feedback_reference').textContent = reference;
    document.getElementById('view_feedback_text').textContent = text || 'No feedback text provided.';
    var starsHtml = '';
    for (var i = 1; i <= 5; i++) {
        starsHtml += i <= rating ? '<i class="fas fa-star" style="color: #f59e0b;"></i>' : '<i class="far fa-star" style="color: #f59e0b;"></i>';
    }
    document.getElementById('view_feedback_stars').innerHTML = starsHtml + ' (' + rating + '/5)';
    document.getElementById('viewFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeViewFeedbackModal() {
    document.getElementById('viewFeedbackModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.getElementById('bookingForm').addEventListener('submit', function(e) {
    if (__pendingBookingSubmit) {
        return true;
    }

    e.preventDefault();
    requestBookingWithTerms();
    return false;
});

window.onclick = function(event) {
    if(event.target.classList.contains('modal')) {
        if (event.target.id === 'bookingTermsModal') {
            return;
        }
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
    if(event.target.classList.contains('payment-popup-overlay')) closePaymentPopup();
    if(event.target.classList.contains('gallery-modal')) closeGalleryModal();
}

document.addEventListener('keydown', function(event) {
    if(event.key === 'Escape') {
        var termsModal = document.getElementById('bookingTermsModal');
        if (termsModal && termsModal.classList.contains('show')) {
            return;
        }
        var shopeeModal = document.getElementById('shopeeViewModal');
        if (shopeeModal && shopeeModal.classList.contains('show')) {
            closeShopeeView();
            return;
        }
        document.querySelectorAll('.modal.show').forEach(function(modal) { modal.classList.remove('show'); });
        closeGalleryModal();
        closePaymentPopup();
        closeFeedbackModal();
        closeViewFeedbackModal();
        closeOverallFeedbackModal();
        document.body.style.overflow = 'auto';
    }
});

setTimeout(function() {
    document.querySelectorAll('.alert-overlay:not(.show)').forEach(function(alert) { alert.style.display = 'none'; });
}, 5000);

<?php if(isset($_SESSION['last_booking'])): ?>
    window.onload = function() {
        showPaymentPopup(
            '<?php echo $_SESSION['last_booking']['reference']; ?>',
            '<?php echo addslashes($_SESSION['last_booking']['house_name']); ?>',
            <?php echo $_SESSION['last_booking']['total']; ?>,
            '<?php echo $_SESSION['last_booking']['check_in'] ?? ''; ?>',
            '<?php echo $_SESSION['last_booking']['check_in_time'] ?? ''; ?>',
            '<?php echo $_SESSION['last_booking']['check_out'] ?? ''; ?>',
            '<?php echo $_SESSION['last_booking']['check_out_time'] ?? ''; ?>'
        );
        <?php unset($_SESSION['last_booking']); ?>
    };
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    rebuildGuestNameInputs();
});

function reopenTermsModal(tabName) {
    var modal = document.getElementById('bookingTermsModal');
    if (modal) {
        openBookingTermsModal();
    }
    return false;
}
</script>

</body>
</html>
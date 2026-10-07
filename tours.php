<?php
session_start();
require_once 'database.php';

if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

require_once 'includes/PaymentService.php';
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

if (!function_exists('buildFullPhone')) {
    function buildFullPhone($suffix, $number) {
        $clean_number = preg_replace('/[^0-9]/', '', $number);
        if (empty($clean_number)) return '';
        return trim($suffix) . $clean_number;
    }
}

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

// ============================================================
// ✅ NAME VALIDATION (for guest name)
// ============================================================
if (!function_exists('validateGuestName')) {
    function validateGuestName($name) {
        $name = trim($name);
        if ($name === '') return "Guest name is required";
        if (strlen($name) > 60) return "Guest name must not exceed 60 characters";
        if (preg_match('/[0-9]/', $name)) return "Guest name cannot contain numbers";
        if (!preg_match("/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/u", $name)) {
            return "Guest name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.)";
        }
        return null;
    }
}

$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

$logo_path = 'uploads/logos/logo.png';
if(isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
}

$hero_path = 'uploads/hero/hero-bg.jpg';
$home_hero_path = $content['site_settings']['hero_image_path'] ?? $hero_path;
if (!empty($home_hero_path) && file_exists($home_hero_path) && !is_dir($home_hero_path)) {
    $hero_path = $home_hero_path;
}
$page_hero_filename = basename((string)($content['site_settings']['tours_hero_image'] ?? ''));
$page_hero_path = 'uploads/hero/tours/' . $page_hero_filename;
if ($page_hero_filename !== '' && file_exists($page_hero_path) && !is_dir($page_hero_path)) {
    $hero_path = $page_hero_path;
}
$hero_exists = !empty($hero_path) && file_exists($hero_path) && !is_dir($hero_path);

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

function getGoogleMapsUrl($address) {
    if (empty($address) || $address == '#') return '#';
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address);
}

$location_address = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';
$maps_url = getGoogleMapsUrl($location_address);
$default_map_url = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863.123456789!2d119.1234567!3d16.1234567!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTbCsDA3JzI0LjAiTiAxMTnCsDA3JzI0LjAiRQ!5e0!3m2!1sen!2sph!4v1234567890';
$map_embed = !empty($google_maps_embed) && $google_maps_embed != '#' ? $google_maps_embed : $default_map_url;
$facebook_link = $content['social']['facebook'] ?? '#';

function generateReferenceNumber($prefix = 'TOUR') {
    return $prefix . '-' . date('Ymd') . '-' . rand(1000, 9999);
}

function getBoatType($max_guests) {
    if ($max_guests <= 5) return 'Small Boat';
    elseif ($max_guests <= 10) return 'Medium Boat';
    elseif ($max_guests <= 15) return 'Large Boat';
    else return 'Deluxe Boat';
}
function getBoatCapacityLabel($max_guests) {
    if ($max_guests <= 5) return '1-5 PAX';
    elseif ($max_guests <= 10) return '6-10 PAX';
    elseif ($max_guests <= 15) return '11-15 PAX';
    else return '16-20 PAX';
}
function getBoatBadgeClass($max_guests) {
    if ($max_guests <= 5) return 'boat-badge-small';
    elseif ($max_guests <= 10) return 'boat-badge-medium';
    elseif ($max_guests <= 15) return 'boat-badge-large';
    else return 'boat-badge-deluxe';
}

function findTourFolder($folder_name) {
    $base = 'uploads/tours/gallery/';
    if (!is_dir($base)) return null;
    if (is_dir($base . $folder_name)) return $folder_name;

    foreach (scandir($base) as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        if (strcasecmp($dir, $folder_name) === 0) return $dir;
    }
    return null;
}

function getTourGalleryFromFolder($tour_name, $folder_name_from_db = null) {
    $gallery = [];
    $folder_name = !empty($folder_name_from_db)
        ? $folder_name_from_db
        : str_replace(' ', '_', trim($tour_name));

    $resolved = findTourFolder($folder_name);
    if ($resolved === null) return $gallery;
    $folder_name = $resolved;

    $gallery_path = 'uploads/tours/gallery/' . $folder_name . '/';
    if (is_dir($gallery_path)) {
        $files = scandir($gallery_path);
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $sort_order = 0;
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $image_extensions)) {
                $gallery[] = [
                    'image' => $file,
                    'is_main' => ($sort_order == 0) ? 1 : 0,
                    'sort_order' => $sort_order,
                    'folder' => $folder_name
                ];
                $sort_order++;
            }
        }
    }
    return $gallery;
}

if (isset($_POST['submit_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $rating       = (int)($_POST['rating'] ?? 0);
        $comment      = trim($_POST['comment'] ?? '');
        $user_id      = (int)$_SESSION['user_id'];
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        if ($rating < 1 || $rating > 5) {
            throw new Exception("Please select a rating between 1 and 5.");
        }

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
                "User " . ($is_update ? "updated" : "submitted") . " overall feedback ({$rating}/5 stars)",
                $user_id,
                'user'
            );
        }

        header("Location: tours.php?feedback_success=1");
        exit();

    } catch (Exception $e) {
        $error = "Failed to submit feedback: " . $e->getMessage();
    }
}

if (isset($_SESSION['flash_feedback_success'])) {
    $success = $_SESSION['flash_feedback_success'];
    unset($_SESSION['flash_feedback_success']);
}

if (isset($_POST['accept_terms']) && isset($_SESSION['user_id'])) {
    $termsGate->accept((int)$_SESSION['user_id'], getClientIp());
    unset($_SESSION['show_terms_modal']);
    $_SESSION['terms_accepted'] = true;
    header("Location: tours.php?terms_accepted=1");
    exit();
}

if(isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

if(isset($_POST['tour_booking']) && isset($_SESSION['user_id'])) {
    try {
        $pdo->beginTransaction();

        $booking_date = $_POST['booking_date'];
        $number_of_guests = (int)$_POST['number_of_guests'];
        $tour_id = $_POST['tour_id'];
        $guest_name = trim($_POST['guest_name'] ?? '');
        $preferred_time = $_POST['preferred_time'] ?? '';
        $today = date('Y-m-d');

        $contact_number_raw = trim($_POST['contact_number'] ?? '');
        $contact_number_raw = preg_replace('/[^0-9]/', '', $contact_number_raw);

        if (substr($contact_number_raw, 0, 1) === '0') {
            $contact_number_raw = substr($contact_number_raw, 1);
        }

        if(empty($booking_date)) throw new Exception("Please select a booking date from the calendar");
        $dateErr = AvailabilityService::futureDateError($booking_date);
        if($dateErr !== null) throw new Exception($dateErr);
        
        // ✅ GUEST NAME VALIDATION — letters only
        $guest_name_error = validateGuestName($guest_name);
        if ($guest_name_error) throw new Exception($guest_name_error);

        if(empty($contact_number_raw)) {
            throw new Exception("Contact number is required");
        }
        if(!preg_match('/^[0-9]{10}$/', $contact_number_raw)) {
            throw new Exception("PH mobile number must be exactly 10 digits (e.g., 9123456789)");
        }
        if(empty($preferred_time)) throw new Exception("Please select a preferred time");

        $contact_number = '+63' . $contact_number_raw;

        $check_tour = $pdo->prepare("SELECT status, max_guests, price_per_boat, tour_name FROM tours WHERE id = ?");
        $check_tour->execute([$tour_id]);
        $tour = $check_tour->fetch();

        if(!$tour) throw new Exception("Tour not found");
        if($tour['status'] != 'available') throw new Exception("Tour is not available for booking");
        if($number_of_guests > $tour['max_guests']) throw new Exception("Maximum guests allowed is " . $tour['max_guests']);

        // ✅ Check for existing confirmed/completed booking
        $conflict_check = $pdo->prepare("
            SELECT id FROM tour_bookings
            WHERE tour_id = ?
              AND booking_date = ?
              AND booking_status IN ('confirmed', 'completed')
        ");
        $conflict_check->execute([$tour_id, $booking_date]);
        if($conflict_check->fetch()) {
            throw new Exception("This boat is already booked on " . date('M d, Y', strtotime($booking_date)) . ". Please select a different date.");
        }

        // ✅ NEW: Check against blocked_dates table
        $check_blocked = $pdo->prepare("SELECT block_date, reason, block_type FROM blocked_dates 
            WHERE item_type = 'tour' 
            AND item_id = ? 
            AND block_date = ?");
        $check_blocked->execute([$tour_id, $booking_date]);
        $blocked_conflict = $check_blocked->fetch();
        if ($blocked_conflict) {
            $reason = $blocked_conflict['reason'] ?: 'Not available';
            $btype = ucwords(str_replace('_', ' ', $blocked_conflict['block_type']));
            throw new Exception("This date is BLOCKED — " . $btype . ": " . $reason . ". Please choose a different date.");
        }

        $guest_stmt = $pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
        $guest_stmt->execute([$_SESSION['user_id']]);
        $guest = $guest_stmt->fetch();
        if(!$guest) throw new Exception("Guest profile not found. Please complete your profile.");

        $guest_id = $guest['id'];
        $total = $tour['price_per_boat'];
        $reference = generateReferenceNumber();

        $stmt = $pdo->prepare("INSERT INTO tour_bookings
            (guest_id, tour_id, reference_number, booking_date, number_of_guests,
             guest_name, contact_number, preferred_time,
             total_amount, reservation_fee_amount, special_requests, payment_status, booking_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())");
        $stmt->execute([
            $guest_id, $tour_id, $reference, $booking_date, $number_of_guests,
            $guest_name, $contact_number, $preferred_time,
            $total, PaymentService::feeFor($total), $_POST['special_requests'] ?? null
        ]);

        $booking_id = $pdo->lastInsertId();
        $pdo->commit();

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'create',
                'booking',
                "New tour booking: {$tour['tour_name']} ({$reference}) — ₱" . number_format($total, 2) . " — {$number_of_guests} pax on " . date('M d, Y', strtotime($booking_date)),
                $booking_id,
                'tour_booking'
            );
        }

        $_SESSION['last_tour_booking'] = [
            'reference' => $reference,
            'total' => $total,
            'tour_name' => $tour['tour_name']
        ];

        $success = "Tour booking submitted! Ref#: " . $reference . ". Pay the reservation fee of " . PaymentService::peso(PaymentService::feeFor($total)) . " and upload the proof in your profile to secure it.";

    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "Booking failed: " . $e->getMessage();
    }
}

if(isset($_POST['submit_tour_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $booking_id = (int)$_POST['booking_id'];
        $rating = (int)$_POST['rating'];
        $stmt = $pdo->prepare("UPDATE tour_bookings SET feedback_rating = ?, feedback_text = ?, feedback_date = NOW(), feedback_is_anonymous = ? WHERE id = ?");
        $stmt->execute([$_POST['rating'], $_POST['feedback'], isset($_POST['is_anonymous']) ? 1 : 0, $booking_id]);
        $success = "Thank you for your feedback!";

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'create',
                'review',
                "User submitted tour feedback ({$rating}/5 stars) for booking #{$booking_id}",
                $booking_id,
                'tour_booking'
            );
        }
    } catch(Exception $e) {
        $error = "Failed to submit feedback: " . $e->getMessage();
    }
}

if(isset($_POST['edit_tour_feedback']) && isset($_SESSION['user_id'])) {
    try {
        $booking_id = (int)$_POST['booking_id'];
        $rating = (int)$_POST['rating'];
        $stmt = $pdo->prepare("UPDATE tour_bookings SET feedback_rating = ?, feedback_text = ?, feedback_date = NOW(), feedback_is_anonymous = ? WHERE id = ?");
        $stmt->execute([$_POST['rating'], $_POST['feedback'], isset($_POST['is_anonymous']) ? 1 : 0, $booking_id]);
        $success = "Your feedback has been updated successfully!";

        if (class_exists('SystemLogger')) {
            SystemLogger::log(
                $pdo,
                'update',
                'review',
                "User updated tour feedback ({$rating}/5 stars) for booking #{$booking_id}",
                $booking_id,
                'tour_booking'
            );
        }
    } catch(Exception $e) {
        $error = "Failed to update feedback: " . $e->getMessage();
    }
}

$tours = $pdo->query("SELECT * FROM tours ORDER BY id")->fetchAll();

$tour_gallery = [];
foreach($tours as $tour) {
    $tour_gallery[$tour['id']] = getTourGalleryFromFolder(
        $tour['tour_name'],
        $tour['folder_name'] ?? null
    );
}

// ✅ Get booked dates (confirmed/completed only)
$tour_booked_dates = [];
foreach($tours as $tour) {
    $stmt = $pdo->prepare("
        SELECT booking_date 
        FROM tour_bookings 
        WHERE tour_id = ? 
          AND booking_status IN ('confirmed', 'completed')
    ");
    $stmt->execute([$tour['id']]);
    $tour_booked_dates[$tour['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================================
// ✅ NEW: Get BLOCKED dates for each tour
// ============================================================
$tour_blocked_dates = [];
foreach($tours as $tour) {
    $stmt = $pdo->prepare("SELECT block_date, reason, block_type FROM blocked_dates WHERE item_type = 'tour' AND item_id = ? AND block_date >= CURDATE() ORDER BY block_date ASC");
    $stmt->execute([$tour['id']]);
    $tour_blocked_dates[$tour['id']] = $stmt->fetchAll();
}

$user_tour_bookings = [];
if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] == 'guest') {
    $stmt = $pdo->prepare("SELECT b.*, t.tour_name, t.price_per_boat
                          FROM tour_bookings b
                          JOIN tours t ON b.tour_id = t.id
                          JOIN guests g ON b.guest_id = g.id
                          WHERE g.user_id = ?
                          ORDER BY b.created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $user_tour_bookings = $stmt->fetchAll();
}

$user_has_feedback = false;
$user_feedback = null;
if(isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_feedback = $stmt->fetch();
    $user_has_feedback = ($user_feedback !== false);
}

function getTourMainImage($tour, $gallery) {
    if (!empty($gallery)) {
        foreach ($gallery as $img) {
            if (!empty($img['is_main']) && $img['is_main'] == 1) {
                $path = 'uploads/tours/gallery/' . $img['folder'] . '/' . $img['image'];
                if (file_exists($path)) return $path;
            }
        }
        $first = $gallery[0];
        $path = 'uploads/tours/gallery/' . $first['folder'] . '/' . $first['image'];
        if (file_exists($path)) return $path;
    }

    if (!empty($tour['folder_name'])) {
        $resolved = findTourFolder($tour['folder_name']);
        if ($resolved !== null) {
            $folder_path = 'uploads/tours/gallery/' . $resolved . '/';
            $files = scandir($folder_path);
            $exts = ['jpg','jpeg','png','gif','webp','bmp'];
            foreach ($files as $file) {
                if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $exts)) {
                    return $folder_path . $file;
                }
            }
        }
    }

    $derived = str_replace(' ', '_', trim($tour['tour_name'] ?? ''));
    if (!empty($derived)) {
        $resolved = findTourFolder($derived);
        if ($resolved !== null) {
            $folder_path = 'uploads/tours/gallery/' . $resolved . '/';
            $files = scandir($folder_path);
            $exts = ['jpg','jpeg','png','gif','webp','bmp'];
            foreach ($files as $file) {
                if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $exts)) {
                    return $folder_path . $file;
                }
            }
        }
    }

    if (!empty($tour['image']) && $tour['image'] != 'default-tour.jpg') {
        $legacy = 'uploads/tours/' . $tour['image'];
        if (file_exists($legacy)) return $legacy;

        $folder = str_replace(' ', '_', trim($tour['tour_name']));
        $alt = 'uploads/tours/gallery/' . $folder . '/' . $tour['image'];
        if (file_exists($alt)) return $alt;
    }

    return 'https://via.placeholder.com/600x400/4DA6D9/ffffff?text=Boat+Tour';
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
    <title>Boat Tours - Hundred Islands</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; }

        .header { background: #0B2447; box-shadow: 0 4px 20px rgba(0,0,0,0.3); padding: 12px 0; position: sticky; top: 0; z-index: 100; border-bottom: 2px solid rgba(77, 166, 217, 0.2); }
        .header-content { max-width: 1300px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; gap: 15px; }
        .logo-wrapper { display: flex; align-items: center; gap: 15px; text-decoration: none; flex-shrink: 0; min-width: 0; }
        .logo-wrapper .logo-image { height: 50px; width: 50px; border-radius: 12px; object-fit: cover; border: 2px solid #4DA6D9; padding: 2px; background: white; transition: transform 0.3s ease; flex-shrink: 0; }
        .logo-wrapper .logo-image:hover { transform: scale(1.05); }
        .logo-wrapper .logo-image-placeholder { height: 50px; width: 50px; border-radius: 12px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; font-weight: 700; border: 2px solid #4DA6D9; flex-shrink: 0; }
        .logo-wrapper .brand-text { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
        .logo-wrapper .brand-text .brand-name { font-size: 20px; font-weight: 700; color: white; letter-spacing: -0.5px; }
        .logo-wrapper .brand-text .brand-tagline { font-size: 11px; color: #7bb8f0; font-weight: 500; letter-spacing: 0.3px; }

        .desktop-nav { display: flex; gap: 4px; align-items: center; flex-wrap: nowrap; flex-shrink: 1; min-width: 0; max-width: 100%; }
        .desktop-nav a { padding: 8px 12px; border-radius: 8px; color: #b3d9ff; text-decoration: none; font-weight: 500; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; white-space: nowrap; flex-shrink: 0; line-height: 1.2; }
        .desktop-nav a i { font-size: 13px; }
        .desktop-nav a:hover { background: rgba(77, 166, 217, 0.2); color: white; }
        .desktop-nav a.active-nav { background: rgba(77, 166, 217, 0.25); color: white; }
        .desktop-nav .btn-logout { background: #ef4444; color: white; border-radius: 8px; padding: 8px 14px; }
        .desktop-nav .btn-logout:hover { background: #dc2626; }
        .desktop-nav .btn-rate { background: #F4B400; color: #0B2447; border-radius: 8px; padding: 8px 14px; }
        .desktop-nav .btn-rate:hover { background: #e6a800; color: #0B2447; }

        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.3); align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        .menu-toggle .fa-bars { transition: transform 0.3s ease; }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8); }

        .sidebar { position: fixed; top: 0; left: -320px; width: 300px; height: 100vh; background: #0B2447; box-shadow: 4px 0 30px rgba(0,0,0,0.3); padding: 25px 0; transition: left 0.3s ease; z-index: 1000; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); }
        .sidebar.open { left: 0; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }

        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); }
        .sidebar-header .logo img { width: 48px; height: 48px; border-radius: 14px; object-fit: cover; border: 2px solid #4DA6D9; padding: 2px; background: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3); }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; letter-spacing: 0.5px; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; letter-spacing: 0.3px; }

        .sidebar-close { position: absolute; top: 12px; right: 16px; font-size: 24px; color: #94a3b8; cursor: pointer; transition: color 0.3s; background: none; border: none; padding: 8px; z-index: 10; }
        .sidebar-close:hover { color: #ef4444; }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav i { color: #7bb8f0; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 999; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }

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
        }
        @media (max-width: 768px) {
            .header-content { padding-left: 60px; padding-right: 15px; gap: 10px; }
            .logo-wrapper { gap: 10px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 42px; width: 42px; }
            .logo-wrapper .brand-text .brand-name { font-size: 17px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 10px; }
        }
        @media (max-width: 480px) {
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .sidebar { width: 85%; max-width: 300px; }
            .header-content { padding-left: 58px; padding-right: 10px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 36px; width: 36px; border-radius: 10px; }
            .logo-wrapper .brand-text .brand-name { font-size: 15px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 9px; }
        }

        .hero { <?php if($hero_exists): ?> background: linear-gradient(rgba(11, 36, 71, 0.5), rgba(11, 36, 71, 0.6)), url('<?php echo $hero_path; ?>?<?php echo time(); ?>'); background-size: cover; background-position: center; <?php else: ?> background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); <?php endif; ?> padding: 120px 20px 70px; color: white; text-align: center; position: relative; }
        .hero-content { max-width: 800px; margin: 0 auto; padding: 0 20px; position: relative; z-index: 1; }
        .hero h1 { font-size: 48px; font-weight: 700; margin-bottom: 20px; text-shadow: 0 2px 25px rgba(0,0,0,0.25); }
        .hero h1 i { color: #7bb8f0; }
        .hero p { font-size: 18px; margin-bottom: 30px; opacity: 0.95; text-shadow: 0 1px 15px rgba(0,0,0,0.15); }

        .main-container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        .section-title { text-align: center; margin-bottom: 40px; }
        .section-title h2 { font-size: 32px; font-weight: 700; color: #0B2447; margin-bottom: 10px; }
        .section-title h2 i { color: #4DA6D9; }
        .section-title .underline { width: 80px; height: 4px; background: linear-gradient(90deg, #4DA6D9, #7bb8f0); border-radius: 2px; margin: 0 auto; }

        .tour-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 25px; }
        .tour-card:hover { transform: translateY(-6px); box-shadow: 0 15px 40px rgba(77, 166, 217, 0.25); }
        .tour-image-wrapper { position: relative; overflow: hidden; height: 220px; flex-shrink: 0; }
        .tour-image { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
        .tour-card:hover .tour-image { transform: scale(1.05); }
        .tour-image-wrapper .badge-container { position: absolute; top: 12px; left: 12px; display: flex; gap: 6px; flex-wrap: wrap; z-index: 2; }
        .tour-image-wrapper .badge-container .badge { padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-featured { background: #F4B400; color: #0B2447; }
        .badge-featured i { margin-right: 4px; }
        .tour-image-wrapper .boat-badge { position: absolute; top: 12px; right: 12px; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; }
        .boat-badge-small { background: rgba(16, 185, 129, 0.9); color: white; }
        .boat-badge-medium { background: rgba(59, 130, 246, 0.9); color: white; }
        .boat-badge-large { background: rgba(245, 158, 11, 0.9); color: white; }
        .boat-badge-deluxe { background: rgba(239, 68, 68, 0.9); color: white; }
        .tour-image-wrapper .status-badge { position: absolute; bottom: 12px; right: 12px; padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; }
        .status-available { background: rgba(16, 185, 129, 0.9); color: white; }
        .status-fully_booked { background: rgba(239, 68, 68, 0.9); color: white; }
        .status-seasonal { background: rgba(245, 158, 11, 0.9); color: white; }
        .tour-image-wrapper .gallery-count { position: absolute; bottom: 12px; left: 12px; background: rgba(0,0,0,0.6); color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; backdrop-filter: blur(5px); z-index: 2; }
        .tour-image-wrapper .gallery-count i { margin-right: 4px; }
        .tour-content { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        
        .tour-places {
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 8px 10px;
            margin: 8px 0 12px;
        }
        .tour-places-header {
            display: flex; align-items: center; gap: 6px;
            font-size: 11.5px; font-weight: 700;
            color: #F4B400; text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 6px;
        }


       
        .tour-actions { margin-top: auto; padding-top: 12px; display: flex; flex-direction: column; gap: 8px; }
        .btn-book { width: 100%; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.25); }
        .btn-book:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.35); background: #e6a800; }
        .btn-book:disabled { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.4); cursor: not-allowed; transform: none; box-shadow: none; }
        a.btn-book { text-decoration: none; }

        .empty-state { text-align: center; padding: 120px 20px 70px; background: white; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .empty-state i { font-size: 60px; color: #cbd5e1; margin-bottom: 20px; }
        .empty-state h3 { color: #1e293b; margin-bottom: 10px; }
        .empty-state p { color: #94a3b8; margin-bottom: 0; }

        .gallery-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
        .gallery-modal.open { display: flex !important; }
        .gallery-modal-content { background: rgba(0,0,0,0.9); border-radius: 16px; max-width: 900px; width: 100%; max-height: 95vh; position: relative; display: flex; flex-direction: column; align-items: center; }
        .gallery-modal-close { position: absolute; top: 15px; right: 20px; font-size: 32px; cursor: pointer; color: white; z-index: 10; background: rgba(0,0,0,0.6); width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s; border: none; }
        .gallery-modal-close:hover { background: rgba(255,255,255,0.2); transform: rotate(90deg); }
        .gallery-slider-container { position: relative; width: 100%; height: 500px; background: #000; border-radius: 16px 16px 0 0; overflow: hidden; }
        .gallery-slider-container .slider-image { width: 100%; height: 100%; object-fit: contain; transition: opacity 0.4s ease; }
        .gallery-slider-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.6); color: white; border: none; width: 50px; height: 50px; border-radius: 50%; font-size: 24px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; z-index: 5; }
        .gallery-slider-nav:hover { background: rgba(255,255,255,0.2); }
        .gallery-slider-prev { left: 15px; }
        .gallery-slider-next { right: 15px; }
        .gallery-slider-counter { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.7); color: white; padding: 6px 18px; border-radius: 20px; font-size: 14px; z-index: 5; font-weight: 500; }
        .gallery-thumbnails { display: flex; gap: 8px; padding: 12px 20px; overflow-x: auto; background: rgba(0,0,0,0.8); width: 100%; border-radius: 0 0 16px 16px; }
        .gallery-thumbnail { width: 70px; height: 50px; object-fit: cover; border-radius: 6px; cursor: pointer; transition: all 0.3s; border: 3px solid transparent; flex-shrink: 0; }
        .gallery-thumbnail:hover { transform: scale(1.05); }
        .gallery-thumbnail.active { border-color: #F4B400; }

        .bookings-table-wrapper { background: white; border-radius: 20px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow-x: auto; }
        .bookings-table-wrapper table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .bookings-table-wrapper table thead { background: #f8fafc; border-bottom: 2px solid #e8f0fe; }
        .bookings-table-wrapper table thead th { padding: 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 600; letter-spacing: 0.3px; }
        .bookings-table-wrapper table tbody tr { border-bottom: 1px solid #e8f0fe; }
        .bookings-table-wrapper table tbody td { padding: 12px; font-size: 13px; color: #475569; vertical-align: middle; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-success { background: #e6f7e6; color: #10b981; }
        .badge-warning { background: #fef3c7; color: #f59e0b; }
        .badge-info { background: #dbeafe; color: #3b82f6; }
        .badge-danger { background: #fee2e2; color: #ef4444; }
        .badge-feedback { background: #d1fae5; color: #065f46; }
        .feedback-text { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12px; color: #64748b; }
        .btn-sm { padding: 4px 12px; border: none; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-sm:hover { transform: translateY(-2px); }
        .btn-pay { background: #10b981; color: white; }
        .btn-pay:hover { background: #059669; }
        .btn-rate { background: #8b5cf6; color: white; }
        .btn-rate:hover { background: #7c3aed; }
        .btn-view { background: #0ea5e9; color: white; }
        .btn-view:hover { background: #0284c7; }
        .btn-edit { background: #f59e0b; color: #0B2447; }
        .btn-edit:hover { background: #d97706; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 3000; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 550px; max-height: 90vh; overflow-y: auto; padding: 30px; }
        .modal-lg { max-width: 700px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
        .modal-header h3 { font-size: 22px; font-weight: 700; color: #0B2447; display: flex; align-items: center; gap: 10px; }
        .modal-header h3 i { color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; transition: color 0.3s; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-control, .form-select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }
        .form-control.error { border-color: #dc2626; background: #fee2e2; }
        .btn-primary { width: 100%; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3); background: #e6a800; }

        .phone-input-group { display: flex; gap: 8px; align-items: stretch; }
        .phone-suffix-select { min-width: 95px; max-width: 115px; padding: 10px 8px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 13px; font-weight: 600; background: #f8fafc; color: #0B2447; cursor: not-allowed; transition: all 0.3s; flex-shrink: 0; font-family: inherit; opacity: 0.85; }
        .phone-input-wrapper { position: relative; flex: 1; min-width: 0; }
        .phone-input-wrapper .form-control { padding-left: 38px; padding-right: 55px; font-family: 'Courier New', monospace; font-weight: 600; letter-spacing: 0.5px; }
        .phone-input-wrapper .phone-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; pointer-events: none; }
        .phone-input-wrapper .digit-count { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); font-size: 10px; color: #94a3b8; background: #f1f5f9; padding: 2px 6px; border-radius: 10px; font-weight: 600; pointer-events: none; }
        .phone-input-wrapper .digit-count.complete { color: #10b981; background: #d1fae5; }
        @media (max-width: 480px) { .phone-suffix-select { min-width: 85px; font-size: 12px; padding: 10px 6px; } }

        .name-input-wrapper { position: relative; }
        .name-input-wrapper .form-control { padding-right: 40px; }
        .name-input-wrapper .name-hint-icon {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%);
            color: #cbd5e1; font-size: 13px;
            pointer-events: none; transition: color 0.3s;
        }
        .name-input-wrapper .form-control:focus ~ .name-hint-icon { color: #4DA6D9; }
        .name-error-msg {
            color: #dc2626; font-size: 12px;
            margin-top: 5px; display: none;
            align-items: center; gap: 5px;
        }
        .name-error-msg.show { display: flex; }

        .calendar-container { background: #f8fafc; border-radius: 16px; padding: 20px; margin: 15px 0; border: 1px solid #e8f0fe; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .calendar-header h4 { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0; }
        .calendar-nav { display: flex; gap: 10px; }
        .calendar-nav button { background: #e2e8f0; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; }
        .calendar-nav button:hover { background: #4DA6D9; color: white; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-grid .day-name { text-align: center; font-size: 11px; font-weight: 600; color: #94a3b8; padding: 5px; text-transform: uppercase; }
        .calendar-grid .day { text-align: center; padding: 8px 0; border-radius: 8px; font-size: 14px; cursor: pointer; transition: all 0.2s; position: relative; }
        .calendar-grid .day:hover:not(.disabled):not(.booked):not(.blocked):not(.past) { background: #eef2ff; transform: scale(1.05); }
        .calendar-grid .day.selected { background: #4DA6D9; color: white; font-weight: 700; box-shadow: 0 4px 12px rgba(77, 166, 217, 0.4); }
        .calendar-grid .day.booked { background: #fee2e2 !important; color: #dc2626 !important; cursor: not-allowed !important; text-decoration: line-through !important; font-weight: 600; }
        .calendar-grid .day.blocked {
            background: #fecaca !important;
            color: #991b1b !important;
            cursor: not-allowed !important;
            text-decoration: line-through !important;
            font-weight: 600;
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
        .calendar-legend { display: flex; gap: 20px; margin-top: 12px; justify-content: center; font-size: 12px; color: #64748b; flex-wrap: wrap; }
        .calendar-legend .dot { width: 14px; height: 14px; border-radius: 4px; display: inline-block; margin-right: 4px; vertical-align: middle; }
        .calendar-legend .dot.available { background: #d1fae5; border: 1px solid #10b981; }
        .calendar-legend .dot.booked { background: #fee2e2; border: 1px solid #dc2626; }
        .calendar-legend .dot.blocked { background: #fecaca; border: 1px solid #991b1b; }
        .calendar-legend .dot.selected { background: #4DA6D9; border: 1px solid #4DA6D9; }
        .calendar-info { text-align: center; margin-top: 10px; font-size: 13px; color: #64748b; padding: 8px; background: white; border-radius: 8px; }
        .selected-date-display { text-align: center; margin-top: 10px; padding: 10px; background: #e6f7e6; border-radius: 8px; color: #10b981; font-weight: 600; font-size: 14px; display: none; }
        .selected-date-display.show { display: block; }

        .rating-input { display: flex; justify-content: center; gap: 10px; font-size: 32px; cursor: pointer; }
        .rating-input i { color: #d4dce4; transition: all 0.2s; }
        .rating-input i:hover, .rating-input i.active { color: #f59e0b; transform: scale(1.1); }
        .feedback-message { text-align: center; padding: 15px; border-radius: 12px; margin-bottom: 15px; background: #f8faff; border: 1px solid #e8f0fe; }
        .feedback-message p { color: #4a6a8c; margin-bottom: 5px; }
        .feedback-message .stars-display { font-size: 28px; margin: 5px 0; }

        .alert-overlay { position: fixed; top: 20px; right: 20px; z-index: 3000; }
        .alert-box { background: white; border-radius: 12px; padding: 15px 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 10px; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
        .alert-box.success { border-left: 4px solid #10b981; }
        .alert-box.error { border-left: 4px solid #ef4444; }
        .alert-box i { font-size: 18px; }
        .alert-box.success i { color: #10b981; }
        .alert-box.error i { color: #ef4444; }

        .alert-overlay.show { display: flex; align-items: center; justify-content: center; inset: 0; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); }
        .alert-box.success-popup { background: white; border-radius: 20px; width: 90%; max-width: 400px; padding: 35px 30px 25px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.15); border-top: 6px solid #10b981; animation: alertPopIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); min-width: unset; display: block; }
        @keyframes alertPopIn { from { opacity: 0; transform: translateY(-20px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .alert-box.success-popup .alert-icon { font-size: 60px; margin-bottom: 15px; line-height: 1; display: block; color: #10b981; }
        .alert-box.success-popup h3 { font-size: 24px; font-weight: 700; margin-bottom: 10px; color: #10b981; }
        .alert-box.success-popup p { color: #4a6a8c; margin-bottom: 22px; font-size: 15px; line-height: 1.5; word-wrap: break-word; }
        .alert-box.success-popup .btn-popup-ok { background: #F4B400; color: #0B2447; border: none; padding: 12px 40px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.3); transition: all 0.25s; min-width: 130px; -webkit-tap-highlight-color: rgba(0,0,0,0.1); touch-action: manipulation; }
        .alert-box.success-popup .btn-popup-ok:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.45); background: #e6a800; color: #0B2447; }
        @media (max-width: 480px) {
            .alert-box.success-popup { padding: 28px 22px 20px; border-radius: 16px; }
            .alert-box.success-popup .alert-icon { font-size: 50px; margin-bottom: 12px; }
            .alert-box.success-popup h3 { font-size: 20px; }
            .alert-box.success-popup p { font-size: 14px; margin-bottom: 18px; }
            .alert-box.success-popup .btn-popup-ok { padding: 11px 32px; font-size: 14px; }
        }

        .payment-popup-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .payment-popup-overlay.show { display: flex; }
        .payment-popup { background: white; border-radius: 24px; max-width: 500px; width: 95%; max-height: 90vh; overflow-y: auto; padding: 35px; animation: popupSlideIn 0.3s ease; box-shadow: 0 30px 80px rgba(0,0,0,0.3); }
        @keyframes popupSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .payment-popup .popup-header { text-align: center; margin-bottom: 25px; padding-bottom: 20px; border-bottom: 2px solid #e8f0fe; }
        .payment-popup .popup-header .success-icon { font-size: 60px; color: #10b981; margin-bottom: 10px; }
        .payment-popup .popup-header h2 { color: #1e293b; font-weight: 700; margin: 0; }
        .payment-popup .popup-header p { color: #64748b; margin: 5px 0 0; font-size: 14px; }
        .payment-popup .payment-detail { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .payment-popup .payment-detail:last-child { border-bottom: none; }
        .payment-popup .payment-detail .label { color: #64748b; font-weight: 500; }
        .payment-popup .payment-detail .value { font-weight: 600; color: #1e293b; }
        .payment-popup .payment-detail .value.amount { color: #10b981; font-size: 20px; }
        .payment-popup .qr-section { text-align: center; padding: 20px; background: #f8fafc; border-radius: 16px; margin: 15px 0; }
        .payment-popup .qr-section img { max-width: 200px; height: auto; border-radius: 12px; }
        .payment-popup .qr-section .no-qr { padding: 30px; background: #e2e8f0; border-radius: 12px; color: #94a3b8; }
        .payment-popup .instructions { background: #fef3c7; padding: 15px; border-radius: 12px; font-size: 13px; color: #92400e; margin: 15px 0; }
        .payment-popup .instructions strong { display: block; margin-bottom: 5px; }
        .payment-popup .popup-actions { display: flex; gap: 10px; margin-top: 20px; }
        .payment-popup .popup-actions .btn-close-popup { flex: 1; padding: 12px; background: #64748b; color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .payment-popup .popup-actions .btn-close-popup:hover { background: #475569; transform: translateY(-2px); }
        .payment-popup .popup-actions .btn-pay-now { flex: 1; padding: 12px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .payment-popup .popup-actions .btn-pay-now:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3); }
        .payment-popup .ref-number { background: #e6f7e6; color: #10b981; padding: 8px 15px; border-radius: 8px; font-weight: 600; display: inline-block; font-size: 14px; }

        .shopee-modal { display: none; position: fixed; inset: 0; z-index: 4000; background: rgba(0,0,0,0.5); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); align-items: center; justify-content: center; padding: 20px; animation: shopeeFadeIn 0.25s ease; overscroll-behavior: contain; }
        .shopee-modal.show { display: flex; }
        @keyframes shopeeFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .shopee-modal-content { background: white; border-radius: 24px; width: 100%; max-width: 980px; max-height: 92vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 30px 80px rgba(0,0,0,0.35); animation: shopeeSlideUp 0.35s cubic-bezier(0.34, 1.56, 0.64, 1); position: relative; }
        @keyframes shopeeSlideUp { from { opacity: 0; transform: translateY(30px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .shopee-close-btn { position: absolute; top: 14px; right: 14px; z-index: 20; background: rgba(0,0,0,0.45); color: white; border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.25s; }
        .shopee-close-btn:hover { background: #ef4444; transform: rotate(90deg); }

        .shopee-modal-body { display: flex; flex: 1; min-height: 0; overflow: hidden; }

        .shopee-gallery { width: 45%; background: #f4f7fa; display: flex; flex-direction: column; flex-shrink: 0; border-right: 1px solid #e8f0fe; }
        .shopee-main-image-wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 24px; min-height: 0; position: relative; }
        .shopee-main-image { max-width: 100%; max-height: 100%; width: auto; height: auto; border-radius: 16px; object-fit: contain; box-shadow: 0 8px 30px rgba(0,0,0,0.08); transition: opacity 0.3s; }
        .shopee-main-image.placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 80px; color: #cbd5e1; background: #eef2f7; }
        .shopee-thumbnails { display: flex; gap: 8px; padding: 12px 20px 20px; overflow-x: auto; flex-shrink: 0; justify-content: center; flex-wrap: wrap; }
        .shopee-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; border: 3px solid transparent; cursor: pointer; transition: all 0.25s; flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .shopee-thumb:hover { transform: translateY(-2px); }
        .shopee-thumb.active { border-color: #F4B400; box-shadow: 0 4px 12px rgba(244,180,0,0.3); }

        .shopee-details { flex: 1; padding: 30px 32px 24px; overflow-y: auto; display: flex; flex-direction: column; min-width: 0; background: white; }
        .shopee-details::-webkit-scrollbar { width: 6px; }
        .shopee-details::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .shopee-detail-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .shopee-detail-badges .badge { padding: 4px 14px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; }

        .shopee-detail-title { font-size: 24px; font-weight: 800; color: #0B2447; line-height: 1.3; margin-bottom: 6px; }
        .shopee-detail-category { font-size: 13px; color: #64748b; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }

        .shopee-detail-pax { display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; padding: 5px 14px; border-radius: 20px; font-size: 12px; color: #475569; font-weight: 500; margin-bottom: 16px; width: fit-content; }

        .shopee-price-section { background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; border: 1px solid #d4e4f0; }
        .shopee-price-main { font-size: 34px; font-weight: 800; color: #4DA6D9; line-height: 1.1; }
        .shopee-price-label { font-size: 12px; color: #64748b; margin-top: 2px; font-weight: 500; }

        .shopee-detail-section { margin-bottom: 20px; }
        .shopee-detail-section h4 { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .shopee-detail-section h4 i { color: #4DA6D9; }
        .shopee-detail-section p { font-size: 13.5px; color: #475569; line-height: 1.7; margin: 0; }


        .shopee-actions { margin-top: auto; padding-top: 20px; border-top: 1px solid #e8f0fe; display: flex; gap: 12px; flex-wrap: wrap; }
        .shopee-actions .btn-book { flex: 1; min-width: 200px; padding: 15px 24px; background: linear-gradient(135deg, #F4B400, #e6a800); color: #0B2447; border: none; border-radius: 12px; font-weight: 700; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.3s; box-shadow: 0 6px 20px rgba(244, 180, 0, 0.35); }
        .shopee-actions .btn-book:hover:not(:disabled) { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(244, 180, 0, 0.5); }
        .shopee-actions .btn-book:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; box-shadow: none; transform: none; }
        .shopee-actions .btn-secondary { padding: 15px 24px; background: #f1f5f9; color: #475569; border: none; border-radius: 12px; font-weight: 600; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; white-space: nowrap; }
        .shopee-actions .btn-secondary:hover { background: #e2e8f0; transform: translateY(-2px); }

        .shopee-login-note { font-size: 12px; color: #94a3b8; text-align: center; margin-top: 12px; padding: 10px; background: #f8fafc; border-radius: 10px; border: 1px dashed #cbd5e1; }
        .shopee-login-note a { color: #4DA6D9; font-weight: 600; text-decoration: none; }
        .shopee-login-note a:hover { text-decoration: underline; }


        @media (max-width: 820px) {
            .shopee-modal-body { flex-direction: column; }
            .shopee-gallery { width: 100%; border-right: none; border-bottom: 1px solid #e8f0fe; max-height: 320px; }
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

        @media (max-width: 992px) { .tour-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); } }
        @media (max-width: 768px) {
            .header-content { padding-left: 65px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 42px; width: 42px; }
            .logo-wrapper .brand-text .brand-name { font-size: 18px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 10px; }
            .hero { padding: 100px 16px 50px; }
            .hero h1 { font-size: 32px; }
            .hero p { font-size: 16px; }
            .tour-grid { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; max-width: 95%; }
            .gallery-slider-container { height: 350px; }
            .gallery-slider-nav { width: 40px; height: 40px; font-size: 18px; }
            .gallery-thumbnail { width: 55px; height: 40px; }
            .payment-popup { padding: 20px; }
            .bookings-table-wrapper table { min-width: 600px; }
        }
        @media (max-width: 480px) {
            .modal-content { padding: 15px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 40px; width: 40px; }
            .logo-wrapper .brand-text .brand-name { font-size: 16px; }
            .logo-wrapper .brand-text .brand-tagline { font-size: 9px; }
            .header-content { padding-left: 58px; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .sidebar { width: 85%; max-width: 300px; left: -320px; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon, .sidebar-header .logo img { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
            .hero h1 { font-size: 28px; }
            .tour-image-wrapper { height: 180px; }
            .gallery-slider-container { height: 280px; }
            .gallery-slider-nav { width: 35px; height: 35px; font-size: 14px; }
            .gallery-thumbnail { width: 45px; height: 35px; }
            .gallery-modal-close { width: 35px; height: 35px; font-size: 24px; top: 10px; right: 12px; }
            .bookings-table-wrapper table { min-width: 500px; }
            .bookings-table-wrapper table thead th, .bookings-table-wrapper table tbody td { padding: 8px; font-size: 11px; }
            .btn-sm { font-size: 9px; padding: 3px 8px; }
            .calendar-grid .day { padding: 6px 0; font-size: 12px; }
        }

        .terms-modal { z-index: 3000 !important; }
        .terms-modal-content { max-width: 720px !important; padding: 0 !important; overflow: hidden !important; display: flex !important; flex-direction: column; max-height: 92vh !important; }
        .terms-modal-header { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); color: white; padding: 25px 30px; text-align: center; position: relative; overflow: hidden; flex-shrink: 0; }
        .terms-modal-icon { font-size: 42px; margin-bottom: 8px; position: relative; z-index: 1; color: #F4B400; }
        .terms-modal-header h3 { font-size: 22px; font-weight: 700; margin: 0 0 4px 0; color: white; position: relative; z-index: 1; }
        .terms-modal-header p { font-size: 13px; opacity: 0.9; margin: 0; color: #e0eeff; position: relative; z-index: 1; }
        .terms-modal-close { position: absolute; top: 14px; right: 14px; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.25); width: 38px; height: 38px; border-radius: 50%; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all 0.25s ease; z-index: 5; }
        .terms-modal-close:hover { background: #ef4444; border-color: #ef4444; transform: rotate(90deg); }
        .terms-scroll-container { flex: 1; overflow-y: auto; padding: 25px 30px; background: white; min-height: 0; scroll-behavior: smooth; }
        .terms-scroll-container::-webkit-scrollbar { width: 8px; }
        .terms-scroll-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .terms-scroll-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .terms-section { margin-bottom: 30px; }
        .terms-section:last-child { margin-bottom: 0; }
        .terms-section-title { display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 700; color: #0B2447; padding-bottom: 12px; border-bottom: 2px solid #4DA6D9; margin-bottom: 15px; }
        .terms-section-title i { color: #4DA6D9; font-size: 20px; }
        .terms-section-body { font-size: 13.5px; line-height: 1.7; color: #334155; white-space: pre-wrap; word-wrap: break-word; }
        .terms-section-body h1, .terms-section-body h2, .terms-section-body h3 { color: #0B2447; margin: 16px 0 8px; font-weight: 700; }
        .terms-section-body p { margin: 0 0 12px; }
        .terms-section-body ul, .terms-section-body ol { padding-left: 22px; margin: 8px 0 12px; }
        .terms-section-body li { margin-bottom: 5px; }
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
        @media (max-width: 600px) {
            .terms-modal-content { max-width: 95% !important; max-height: 94vh !important; }
            .terms-modal-header { padding: 20px; }
            .terms-modal-header h3 { font-size: 18px; }
            .terms-modal-icon { font-size: 34px; }
            .terms-modal-close { width: 32px; height: 32px; font-size: 14px; top: 10px; right: 10px; }
            .terms-scroll-container { padding: 18px 20px; }
            .terms-section-title { font-size: 16px; }
            .terms-section-body { font-size: 13px; }
            .terms-scroll-hint { font-size: 11.5px; padding: 10px; }
            #termsAcceptForm { padding: 12px 18px 15px; }
            .terms-checkbox-label { font-size: 12.5px; padding: 10px 12px; }
            .terms-accept-btn { font-size: 14px; padding: 12px; }
        }

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
        @media (max-width: 480px) {
            .logout-modal { padding: 28px 22px 20px; border-radius: 20px; }
            .logout-modal-icon { width: 65px; height: 65px; font-size: 28px; margin-bottom: 14px; }
            .logout-modal h3 { font-size: 19px; }
            .logout-modal p { font-size: 13px; margin-bottom: 20px; }
            .logout-modal-actions { flex-direction: column-reverse; }
            .btn-logout-cancel, .btn-logout-confirm { width: 100%; }
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
        <h1><i class="fas fa-ship"></i> Boat Tours</h1>
        <p>Choose the right boat for your Hundred Islands adventure</p>
    </div>
</div>

<div class="main-container">

    <div class="section-title">
        <h2><i class="fas fa-ship"></i> Available Boat Tours</h2>
        <div class="underline"></div>
    </div>

    <?php if(empty($tours)): ?>
        <div class="empty-state">
            <i class="fas fa-ship"></i>
            <h3>No Tours Available</h3>
            <p>Please check back later for exciting tour packages!</p>
        </div>
    <?php else: ?>
        <div class="tour-grid">
            <?php foreach($tours as $tour):
                $gallery = $tour_gallery[$tour['id']] ?? [];
                $gallery_count = count($gallery);
                $boat_type = getBoatType($tour['max_guests']);
                $boat_label = getBoatCapacityLabel($tour['max_guests']);
                $boat_badge_class = getBoatBadgeClass($tour['max_guests']);
                $is_featured = isset($tour['is_featured']) && $tour['is_featured'];
            ?>
            <div class="tour-card" onclick="openShopeeView(<?php echo $tour['id']; ?>)">
                <div class="tour-image-wrapper">
                    <img src="<?php echo getTourMainImage($tour, $gallery); ?>"
                         class="tour-image"
                         alt="<?php echo htmlspecialchars($tour['tour_name']); ?>"
                         onerror="this.src='https://via.placeholder.com/600x400/4DA6D9/ffffff?text=Boat+Tour'">
                    <div class="badge-container">
                        <?php if($is_featured): ?>
                            <span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                        <?php endif; ?>
                    </div>
                    <span class="boat-badge <?php echo $boat_badge_class; ?>"><?php echo $boat_type; ?></span>
                    <span class="status-badge status-<?php echo $tour['status'] ?? 'available'; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $tour['status'] ?? 'available')); ?>
                    </span>
                    <?php if($gallery_count > 0): ?>
                    <span class="gallery-count"><i class="fas fa-images"></i> <?php echo $gallery_count; ?></span>
                    <?php endif; ?>
                </div>

                <div class="tour-content">
                    <h3 class="tour-name"><i class="fas fa-ship"></i> <?php echo htmlspecialchars($tour['tour_name']); ?></h3>

                    <?php if(!empty($tour['description'])): ?>
                        <div class="tour-desc">
                            <?php echo htmlspecialchars(substr($tour['description'], 0, 120)) . (strlen($tour['description']) > 120 ? '...' : ''); ?>
                        </div>
                    <?php endif; ?>

                    <div class="tour-info">
                        <div class="info-item">
                            <i class="fas fa-users"></i>
                            <span>Capacity: <?php echo $boat_label; ?></span>
                        </div>
                    </div>

                    <div class="tour-places">
                        <div class="tour-places-header">
                            <i class="fas fa-map-marked-alt"></i> Destinations
                        </div>
                        <div style="color:white;font-size:13px;font-weight:700;">12–14 islands</div>
                    </div>

                    <div class="tour-price">
                        ₱<?php echo number_format($tour['price_per_boat']); ?> <small>/boat</small>
                    </div>

                    <div class="tour-actions" onclick="event.stopPropagation();">
                        <?php if(($tour['status'] ?? 'available') == 'available'): ?>
                            <?php if(isset($_SESSION['user_id'])): ?>
                                <button class="btn-book" onclick="event.stopPropagation(); bookTour(<?php echo $tour['id']; ?>, <?php echo $tour['price_per_boat']; ?>, '<?php echo addslashes($tour['tour_name']); ?>', <?php echo $tour['max_guests']; ?>)">
                                    <i class="fas fa-calendar-check"></i> Book Now
                                </button>
                            <?php else: ?>
                                <a href="login.php?redirect=tours.php" class="btn-book" onclick="event.stopPropagation();">
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

    <?php if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] == 'guest' && !empty($user_tour_bookings)): ?>
    <div style="margin-top: 60px;">
        <div class="section-title">
            <h2><i class="fas fa-calendar-check"></i> My Tour Bookings</h2>
            <div class="underline"></div>
        </div>
        <div class="bookings-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Ref #</th><th>Tour</th><th>Date</th><th>Time</th><th>Pax</th>
                        <th>Total</th><th>Payment</th><th>Status</th><th>Feedback</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($user_tour_bookings as $booking): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($booking['reference_number'] ?? 'N/A'); ?></strong></td>
                        <td><?php echo htmlspecialchars($booking['tour_name']); ?></td>
                        <td><?php echo formatDateDisplay($booking['booking_date']); ?></td>
                        <td>
                            <?php if(!empty($booking['preferred_time'])): ?>
                                <span class="badge badge-info"><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($booking['preferred_time'])); ?></span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $booking['number_of_guests']; ?></td>
                        <td><?php echo PaymentService::guestAmountCell($booking); ?></td>
                        <td>
                            <?php echo PaymentService::guestBadge($booking); ?>
                        </td>
                        <td>
                            <?php
                            $status_class = 'badge-info';
                            if($booking['booking_status'] == 'pending') $status_class = 'badge-warning';
                            elseif($booking['booking_status'] == 'confirmed') $status_class = 'badge-info';
                            elseif($booking['booking_status'] == 'completed') $status_class = 'badge-success';
                            elseif($booking['booking_status'] == 'cancelled') $status_class = 'badge-danger';
                            ?>
                            <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($booking['booking_status']); ?></span>
                        </td>
                        <td>
                            <?php if(!empty($booking['feedback_text'])): ?>
                                <span class="badge badge-feedback"><i class="fas fa-star" style="color: #f59e0b;"></i> <?php echo $booking['feedback_rating']; ?>/5</span>
                                <div class="feedback-text" title="<?php echo htmlspecialchars($booking['feedback_text']); ?>">
                                    <?php echo htmlspecialchars($booking['feedback_text']); ?>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-warning">No feedback</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                <?php if(PaymentService::canPayReservation($booking)): ?>
                                    <a href="profile.php#tours-tab" class="btn-sm btn-pay" style="text-decoration:none;">
                                        <i class="fas fa-credit-card"></i> Pay
                                    </a>
                                <?php endif; ?>

                                <?php if(($booking['booking_status'] == 'completed' || PaymentService::isSecured($booking)) && empty($booking['feedback_text'])): ?>
                                    <button class="btn-sm btn-rate" onclick="openTourFeedbackModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['tour_name']); ?>', '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-star"></i> Rate
                                    </button>
                                <?php endif; ?>

                                <?php if(!empty($booking['feedback_text'])): ?>
                                    <button class="btn-sm btn-view" onclick="viewTourFeedback('<?php echo htmlspecialchars($booking['feedback_text']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo htmlspecialchars($booking['reference_number']); ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn-sm btn-edit" onclick="editTourFeedback(<?php echo $booking['id']; ?>, '<?php echo addslashes($booking['tour_name']); ?>', '<?php echo addslashes($booking['reference_number']); ?>', <?php echo $booking['feedback_rating']; ?>, '<?php echo addslashes($booking['feedback_text']); ?>', <?php echo isset($booking['feedback_is_anonymous']) && $booking['feedback_is_anonymous'] ? 'true' : 'false'; ?>)">
                                        <i class="fas fa-edit"></i> Edit
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

<div id="galleryModal" class="gallery-modal" onclick="if(event.target === this) closeGalleryModal()">
    <div class="gallery-modal-content">
        <button class="gallery-modal-close" onclick="closeGalleryModal()">&times;</button>
        <div class="gallery-slider-container">
            <img id="sliderMainImage" class="slider-image" src="" alt="Tour Photo">
            <button class="gallery-slider-nav gallery-slider-prev" onclick="changeSliderImage(-1)">❮</button>
            <button class="gallery-slider-nav gallery-slider-next" onclick="changeSliderImage(1)">❯</button>
            <div class="gallery-slider-counter" id="sliderCounter">1 / 1</div>
        </div>
        <div class="gallery-thumbnails" id="sliderThumbnails"></div>
    </div>
</div>

<div class="shopee-modal" id="shopeeViewModal" onclick="if(event.target === this) closeShopeeView()">
    <div class="shopee-modal-content">
        <button class="shopee-close-btn" onclick="closeShopeeView()" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="shopee-modal-body" id="shopeeModalBody">
        </div>
    </div>
</div>

<div class="modal" id="tourBookingModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-ship"></i> Book This Tour</h3>
            <span class="close" onclick="hideModal('tourBooking')">&times;</span>
        </div>
        <div class="modal-body" style="padding: 25px; max-height: 80vh; overflow-y: auto;">
            <form method="POST" id="tourBookingForm">
                <input type="hidden" name="tour_id" id="tour_booking_id">
                <input type="hidden" name="tour_name" id="tour_booking_name">

                <div class="form-group">
                    <label><i class="fas fa-ship"></i> Tour Package</label>
                    <input type="text" id="display_tour_name" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Price per Boat</label>
                    <input type="text" id="display_tour_price" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Booking Date *</label>
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
                        <div class="calendar-info" id="calendarInfo">Select a date for your tour</div>
                        <div class="selected-date-display" id="selectedDateDisplay"></div>
                    </div>
                    <input type="hidden" name="booking_date" id="tour_date" required>
                </div>

                <!-- ✅ GUEST NAME with restricted input (letters only) -->
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Guest Name *</label>
                    <div class="name-input-wrapper">
                        <input type="text"
                               name="guest_name"
                               id="tour_guest_name"
                               class="form-control"
                               placeholder="Full name of lead guest"
                               maxlength="60"
                               data-name-field="1"
                               autocomplete="name"
                               required>
                        <i class="fas fa-font name-hint-icon"></i>
                    </div>
                    <div class="name-error-msg" id="guestNameError">
                        <i class="fas fa-exclamation-circle"></i> <span id="guestNameErrorText"></span>
                    </div>
                    <small style="color:#94a3b8;font-size:11px;">
                        <i class="fas fa-info-circle"></i> The person who will be on-site for this tour. Letters only — no numbers or special characters.
                    </small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Contact Number *</label>
                    <div class="phone-input-group">
                        <select name="contact_suffix" id="tour_contact_suffix" class="phone-suffix-select" aria-label="Country code" disabled>
                            <option value="+63" selected>+63 🇵🇭</option>
                        </select>
                        <div class="phone-input-wrapper">
                            <i class="fas fa-phone phone-icon"></i>
                            <input type="tel"
                                   name="contact_number"
                                   id="tour_contact"
                                   class="form-control"
                                   placeholder="9123456789"
                                   maxlength="10"
                                   inputmode="numeric"
                                   autocomplete="tel"
                                   required
                                   oninput="validateTourPhone(this)">
                            <span class="digit-count" id="tour_contact_count">0/10</span>
                        </div>
                    </div>
                    <small style="color:#94a3b8;font-size:11px;">
                        <i class="fas fa-info-circle"></i> Enter 10-digit PH mobile number (e.g., 9123456789)
                    </small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Preferred Time *</label>
                    <select name="preferred_time" id="tour_time" class="form-select" required>
                        <option value="">Select Time Slot</option>
                        <option value="06:00:00">🌅 6:00 AM — Sunrise Tour</option>
                        <option value="07:00:00">☀️ 7:00 AM — Early Morning</option>
                        <option value="08:00:00">☀️ 8:00 AM — Morning Tour</option>
                        <option value="09:00:00">☀️ 9:00 AM — Mid-Morning</option>
                        <option value="10:00:00">☀️ 10:00 AM — Late Morning</option>
                        <option value="11:00:00">☀️ 11:00 AM — Pre-Noon</option>
                        <option value="12:00:00">🍽️ 12:00 PM — Noon</option>
                        <option value="13:00:00">🌤️ 1:00 PM — Early Afternoon</option>
                        <option value="14:00:00">🌤️ 2:00 PM — Afternoon</option>
                        <option value="15:00:00">🌤️ 3:00 PM — Mid-Afternoon</option>
                        <option value="16:00:00">🌇 4:00 PM — Sunset Tour</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-users"></i> Number of Pax *</label>
                    <input type="number" name="number_of_guests" id="tour_guests" class="form-control" min="1" required onchange="calculateTourTotal()">
                    <small id="maxGuestsWarning" style="color: #f59e0b; display: none;"></small>
                    <small style="color:#94a3b8;font-size:11px;">Max capacity: <strong id="display_max_capacity">0</strong> pax</small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Special Requests</label>
                    <textarea name="special_requests" class="form-control" rows="3" placeholder="Any dietary restrictions, special occasions, or requests?"></textarea>
                </div>

                <div style="background: #f8fafc; padding: 20px; border-radius: 16px; margin: 20px 0;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span><i class="fas fa-users"></i> Number of Pax:</span>
                        <span><strong id="display_guests">0</strong></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 18px;">
                        <span><i class="fas fa-money-bill-wave" style="color: #10b981;"></i> Total amount:</span>
                        <span><strong style="color: #10b981;">₱<span id="display_tour_total">0.00</span></strong></span>
                    </div>
                    <input type="hidden" name="total_amount" id="tour_total_amount">
                </div>

                <div style="background: #fef3c7; padding: 12px; border-radius: 10px; margin-bottom: 15px; font-size: 12px; color: #92400e;">
                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> Your booking will be reviewed by admin. You'll receive a confirmation once approved.
                </div>

                <button type="submit" name="tour_booking" class="btn-primary">
                    <i class="fas fa-check-circle"></i> Submit Booking
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="tourFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star" style="color: #f59e0b;"></i> <span id="tourFeedbackModalTitle">Rate Your Tour</span></h3>
            <span class="close" onclick="closeTourFeedbackModal()">&times;</span>
        </div>
        <div id="tourFeedbackContent">
            <p style="color: #64748b; margin-bottom: 15px;">How was your tour experience with <strong id="tour_feedback_tour_name"></strong>?</p>
            <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;">Reference: <strong id="tour_feedback_reference"></strong></p>
            <form method="POST" id="tourFeedbackForm">
                <input type="hidden" name="booking_id" id="tour_feedback_booking_id">
                <div style="text-align: center; margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #1e293b;">Your Rating</label>
                    <div class="rating-stars" id="tourRatingStars">
                        <i class="fas fa-star" data-rating="1" onclick="setTourRating(1)"></i>
                        <i class="fas fa-star" data-rating="2" onclick="setTourRating(2)"></i>
                        <i class="fas fa-star" data-rating="3" onclick="setTourRating(3)"></i>
                        <i class="fas fa-star" data-rating="4" onclick="setTourRating(4)"></i>
                        <i class="fas fa-star" data-rating="5" onclick="setTourRating(5)"></i>
                    </div>
                    <input type="hidden" name="rating" id="tour_feedback_rating" value="0" required>
                    <span id="tourRatingText" style="font-size: 14px; color: #64748b;">Select a rating</span>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b;"><i class="fas fa-comment"></i> Your Feedback</label>
                    <textarea name="feedback" id="tour_feedback_text" class="form-control" rows="4" placeholder="Share your experience..."></textarea>
                </div>
                <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; padding: 10px 15px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <input type="checkbox" name="is_anonymous" id="tour_feedback_is_anonymous" value="1">
                    <label for="tour_feedback_is_anonymous" style="margin-bottom: 0; cursor: pointer; font-weight: 500; color: #1e293b;">
                        <i class="fas fa-user-secret" style="color: #7c3aed;"></i> Post as Anonymous
                    </label>
                </div>
                <button type="submit" name="submit_tour_feedback" id="tourFeedbackSubmitBtn" class="btn-primary">
                    <i class="fas fa-paper-plane"></i> Submit Feedback
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="viewTourFeedbackModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star" style="color: #f59e0b;"></i> Your Feedback</h3>
            <span class="close" onclick="closeViewTourFeedbackModal()">&times;</span>
        </div>
        <div id="viewTourFeedbackContent">
            <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;">Reference: <strong id="view_tour_feedback_reference"></strong></p>
            <div style="text-align: center; margin-bottom: 15px;">
                <div id="view_tour_feedback_stars" style="font-size: 24px;"></div>
            </div>
            <div style="background: #f8fafc; padding: 15px; border-radius: 10px;">
                <p id="view_tour_feedback_text" style="color: #1e293b; font-style: italic; margin: 0;">No feedback text provided.</p>
            </div>
        </div>
    </div>
</div>

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
                    <div class="feedback-message">
                        <p>You previously rated:</p>
                        <div class="stars-display">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star"
                                   style="color: <?php echo $i <= (int)$user_feedback['rating'] ? '#f59e0b' : '#d4dce4'; ?>;"></i>
                            <?php endfor; ?>
                        </div>
                        <p style="font-size: 13px; margin-top: 5px;">Update your rating and review below</p>
                    </div>
                <?php else: ?>
                    <p style="color: #64748b; margin-bottom: 15px; text-align: center;">
                        How would you rate your overall experience with us?
                    </p>
                <?php endif; ?>

                <form method="POST" action="tours.php" id="overallFeedbackForm">
                    <input type="hidden" name="submit_feedback" value="1">

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; text-align: center; font-weight: 600; color: #0B2447; margin-bottom: 10px;">
                            Your Overall Rating
                        </label>
                        <div class="rating-input" id="overallRatingInput">
                            <i class="fas fa-star" data-rating="1" onclick="setOverallRating(1)"></i>
                            <i class="fas fa-star" data-rating="2" onclick="setOverallRating(2)"></i>
                            <i class="fas fa-star" data-rating="3" onclick="setOverallRating(3)"></i>
                            <i class="fas fa-star" data-rating="4" onclick="setOverallRating(4)"></i>
                            <i class="fas fa-star" data-rating="5" onclick="setOverallRating(5)"></i>
                        </div>
                        <input type="hidden" name="rating" id="overall_feedback_rating"
                               value="<?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>" required>
                        <p id="overallRatingText" style="text-align: center; color: #4a6a8c; margin-top: 5px;">
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

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #0B2447;">
                            Your Comment
                        </label>
                        <textarea name="comment" class="form-control" rows="3"
                                  placeholder="Share your overall experience..."
                                  style="resize: vertical; width: 100%; padding: 12px; border: 1px solid #d4e4f0; border-radius: 8px; background: #f8faff; color: #1a3a5c;"><?php echo $user_has_feedback ? htmlspecialchars($user_feedback['comment']) : ''; ?></textarea>
                    </div>

                    <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; padding: 10px 15px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <input type="checkbox" name="is_anonymous" id="overall_is_anonymous" value="1"
                               <?php echo ($user_has_feedback && !empty($user_feedback['is_anonymous'])) ? 'checked' : ''; ?>>
                        <label for="overall_is_anonymous" style="margin-bottom: 0; cursor: pointer; font-weight: 500; color: #1e293b;">
                            <i class="fas fa-user-secret" style="color: #7c3aed;"></i> Post as Anonymous
                        </label>
                        <span style="font-size: 12px; color: #94a3b8; margin-left: auto;">
                            Your name will not be shown publicly
                        </span>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn"
                                onclick="closeOverallFeedbackModal()"
                                style="flex: 1; padding: 12px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 600; cursor: pointer;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit"
                                style="flex: 2; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2);">
                            <i class="fas fa-paper-plane"></i>
                            <?php echo $user_has_feedback ? 'Update Review' : 'Submit Review'; ?>
                        </button>
                    </div>
                </form>

            <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #4a6a8c;">
                    <i class="fas fa-lock" style="font-size: 48px; margin-bottom: 15px; color: #4DA6D9;"></i>
                    <p>Please <a href="login.php" style="color: #4DA6D9; font-weight: 600; text-decoration: none;">login</a> to leave a review.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="payment-popup-overlay" id="paymentPopup">
    <div class="payment-popup">
        <div class="popup-header">
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <h2>Booking Submitted! 🎉</h2>
            <p>Pay the reservation fee via GCash to secure your booking</p>
        </div>
        <div class="popup-body">
            <div style="text-align: center; margin-bottom: 15px;">
                <span class="ref-number" id="popup_reference">TOUR-20241201-1234</span>
            </div>
            <div class="payment-detail">
                <span class="label">Tour Package</span>
                <span class="value" id="popup_tour">Tour Name</span>
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
                paid reservations may be rebooked. The remaining balance is paid upon arrival or before the service begins.
            </p>
            <div class="qr-section">
                <?php if($gcash_qr && file_exists("uploads/gcash/" . $gcash_qr)): ?>
                    <img src="uploads/gcash/<?php echo $gcash_qr; ?>" alt="GCash QR Code">
                <?php else: ?>
                    <div class="no-qr"><i class="fas fa-qrcode" style="font-size: 48px;"></i><p>QR Code will appear here</p></div>
                <?php endif; ?>
            </div>
            <?php if ($gcash_configured): ?>
            <div style="background: #f8fafc; padding: 12px; border-radius: 12px; margin: 10px 0;">
                <p style="margin: 0; font-size: 14px;"><strong>Account Name:</strong> <?php echo htmlspecialchars($gcash_name); ?></p>
                <p style="margin: 0; font-size: 14px;"><strong>GCash Number:</strong> <?php echo htmlspecialchars($gcash_number); ?></p>
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
            <div style="background: #fef3c7; padding: 12px; border-radius: 10px; margin-top: 15px; font-size: 12px; color: #92400e;">
                <i class="fas fa-clock"></i> <strong>Status:</strong> Pending Admin Confirmation
            </div>
        </div>
        <div class="popup-actions">
            <button class="btn-pay-now" onclick="window.location.href='profile.php'"><i class="fas fa-upload"></i> Upload Proof</button>
            <button class="btn-close-popup" onclick="closePaymentPopup()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<?php include 'components/footer.php'; ?>

<div class="modal terms-modal" id="termsModal"
     data-must-accept="<?php echo $force_must_accept ? '1' : '0'; ?>">
    <div class="modal-content terms-modal-content">
        <div class="terms-modal-header">
            <div class="terms-modal-icon"><i class="fas fa-file-contract"></i></div>
            <h3 id="termsModalTitle">Before You Continue</h3>
            <p id="termsModalSubtitle">Please review our Terms &amp; Privacy Policy</p>
            <button type="button" class="terms-modal-close" id="termsModalCloseBtn" onclick="closeTermsReadOnly()" aria-label="Close" style="display: none;">
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
                    if (preg_match('/<[^>]+>/', $termsBody)) { echo $termsBody; }
                    else { echo nl2br(htmlspecialchars($termsBody)); }
                    ?>
                </div>
            </div>

            <div class="terms-divider"><span>END OF TERMS &amp; CONDITIONS</span></div>

            <div class="terms-section" id="privacySection">
                <div class="terms-section-title">
                    <i class="fas fa-shield-alt"></i>
                    <span id="privacyTitleText"><?php echo htmlspecialchars($termsContent['privacy_title']); ?></span>
                </div>
                <div class="terms-section-body" id="privacyBody">
                    <?php
                    $privacyBody = $termsContent['privacy_body'];
                    if (preg_match('/<[^>]+>/', $privacyBody)) { echo $privacyBody; }
                    else { echo nl2br(htmlspecialchars($privacyBody)); }
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

<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="logout-modal-icon"><i class="fas fa-sign-out-alt"></i></div>
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

<script>
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) closeLogoutModal();
    }
});

// ============================================================
// ✅ GUEST NAME FIELD — Restrict to letters only
// ============================================================
function setupGuestNameInput() {
    const input = document.getElementById('tour_guest_name');
    const errorBox = document.getElementById('guestNameError');
    const errorText = document.getElementById('guestNameErrorText');
    if (!input) return;

    const NAME_PATTERN = /^[a-zA-ZÀ-ÿñÑ\s\-'.]*$/;

    input.addEventListener('input', function() {
        const original = this.value;
        let cleaned = this.value.replace(/[^a-zA-ZÀ-ÿñÑ\s\-'.]/g, '');
        cleaned = cleaned.replace(/\s+/g, ' ');

        if (original !== cleaned) {
            const cursorPos = this.selectionStart;
            const removed = original.length - cleaned.length;
            this.value = cleaned;
            try {
                this.setSelectionRange(
                    Math.max(0, cursorPos - removed),
                    Math.max(0, cursorPos - removed)
                );
            } catch (e) {}
        }

        if (cleaned.length > 0) {
            if (/\d/.test(cleaned)) {
                errorBox.classList.add('show');
                errorText.textContent = 'Guest name cannot contain numbers.';
                input.classList.add('error');
            } else if (!NAME_PATTERN.test(cleaned)) {
                errorBox.classList.add('show');
                errorText.textContent = 'Guest name contains invalid characters.';
                input.classList.add('error');
            } else {
                errorBox.classList.remove('show');
                input.classList.remove('error');
            }
        } else {
            errorBox.classList.remove('show');
            input.classList.remove('error');
        }
    });

    input.addEventListener('keypress', function(e) {
        if (e.which === 8 || e.which === 0 || e.which === 13) return;
        if (e.ctrlKey || e.metaKey) return;
        const char = String.fromCharCode(e.which);
        if (!/^[a-zA-ZÀ-ÿñÑ\s\-'.]$/.test(char)) {
            e.preventDefault();
        }
    });

    input.addEventListener('paste', function(e) {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        const cleaned = pasted.replace(/[^a-zA-ZÀ-ÿñÑ\s\-'.]/g, '').replace(/\s+/g, ' ');
        const start = this.selectionStart;
        const end = this.selectionEnd;
        const currentValue = this.value;
        const newValue = (currentValue.slice(0, start) + cleaned + currentValue.slice(end)).slice(0, 60);
        this.value = newValue;
        const newPos = start + cleaned.length;
        try {
            this.setSelectionRange(newPos, newPos);
        } catch (err) {}
        this.dispatchEvent(new Event('input'));
    });

    input.addEventListener('drop', function(e) {
        e.preventDefault();
    });
}

// ============================================================
// SHOPEE MODAL
// ============================================================
const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;

const tourData = <?php
    $td = [];
    foreach($tours as $tour) {
        $gallery = $tour_gallery[$tour['id']] ?? [];
        $mainImage = getTourMainImage($tour, $gallery);
        $td[$tour['id']] = [
            'id' => (int)$tour['id'],
            'name' => $tour['tour_name'],
            'description' => $tour['description'] ?? '',
            'destinations' => '12–14 islands',
            'price' => (float)$tour['price_per_boat'],
            'max_guests' => (int)$tour['max_guests'],
            'status' => $tour['status'] ?? 'available',
            'is_featured' => isset($tour['is_featured']) && $tour['is_featured'],
            'main_image' => $mainImage,
            'gallery' => $gallery,
            'boat_type' => getBoatType($tour['max_guests']),
            'boat_label' => getBoatCapacityLabel($tour['max_guests']),
            'boat_badge_class' => getBoatBadgeClass($tour['max_guests']),
        ];
    }
    echo json_encode($td);
?>;

// ✅ Blocked dates per tour
const tourBlockedDatesData = <?php echo json_encode($tour_blocked_dates); ?>;

let shopeeCurrentTourId = null;
let shopeeCurrentImageIndex = 0;

function openShopeeView(tourId) {
    const tour = tourData[tourId];
    if (!tour) return;

    shopeeCurrentTourId = tourId;
    shopeeCurrentImageIndex = 0;

    const isAvailable = (tour.status === 'available');
    const isFeatured = tour.is_featured;

    let images = [];
    if (tour.gallery && tour.gallery.length > 0) {
        images = tour.gallery.map(function(img) {
            return { src: 'uploads/tours/gallery/' + img.folder + '/' + img.image, folder: img.folder, image: img.image };
        });
    } else if (tour.main_image) {
        images = [{ src: tour.main_image, folder: '', image: '' }];
    }

    let badgesHtml = '';
    if (isFeatured) {
        badgesHtml += '<span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>';
    }
    badgesHtml += '<span class="badge ' + tour.boat_badge_class + '" style="background: rgba(77,166,217,0.9); color: white;"><i class="fas fa-ship"></i> ' + escapeHtml(tour.boat_type) + '</span>';

    let thumbsHtml = '';
    if (images.length > 1) {
        images.forEach(function(img, idx) {
            thumbsHtml += '<img src="' + img.src + '" class="shopee-thumb' + (idx === 0 ? ' active' : '') + '" onclick="switchShopeeImage(' + idx + ')" alt="Thumb ' + (idx+1) + '">';
        });
    }

    let actionsHtml = '';
    if (isAvailable) {
        if (isLoggedIn) {
            actionsHtml = '<button class="btn-book" onclick="event.stopPropagation(); closeShopeeView(); bookTour(' + tour.id + ', ' + tour.price + ', \'' + escapeJs(tour.name) + '\', ' + tour.max_guests + ')">' +
                '<i class="fas fa-calendar-check"></i> Book Now</button>';
        } else {
            actionsHtml = '<a href="login.php?redirect=tours.php" class="btn-book" style="text-decoration:none;" onclick="event.stopPropagation();">' +
                '<i class="fas fa-sign-in-alt"></i> Login to Book</a>';
        }
    } else {
        actionsHtml = '<button class="btn-book" disabled><i class="fas fa-clock"></i> Not Available</button>';
    }

    let loginNote = '';
    if (!isLoggedIn && isAvailable) {
        loginNote = '<div class="shopee-login-note"><i class="fas fa-lock"></i> You need to <a href="login.php?redirect=tours.php">login</a> or <a href="register.php">register</a> to book this tour.</div>';
    }

    let descHtml = '';
    if (tour.description) {
        descHtml = '<div class="shopee-detail-section"><h4><i class="fas fa-align-left"></i> Description</h4><p>' + nl2br(escapeHtml(tour.description)) + '</p></div>';
    }

    let destinationsHtml = '<div class="shopee-detail-section"><h4><i class="fas fa-map-marked-alt"></i> Destinations</h4><p><strong>12–14 islands</strong></p></div>';

    let mainImageHtml = '';
    if (images.length > 0) {
        mainImageHtml = '<img id="shopeeMainImg" class="shopee-main-image" src="' + images[0].src + '" alt="' + escapeHtml(tour.name) + '">';
    } else {
        mainImageHtml = '<div class="shopee-main-image placeholder"><i class="fas fa-ship"></i></div>';
    }

    const statusLabel = tour.status.replace('_', ' ');
    const statusClass = 'status-' + tour.status;

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
            <h2 class="shopee-detail-title">${escapeHtml(tour.name)}</h2>
            <div class="shopee-detail-category"><i class="fas fa-ship"></i> Boat Tour Package</div>
            <div class="shopee-detail-pax"><i class="fas fa-users"></i> Capacity: ${escapeHtml(tour.boat_label)} (Max ${tour.max_guests} pax)</div>
            <div class="shopee-price-section">
                <div class="shopee-price-main">₱${formatNumber(tour.price)}<span style="font-size:14px; color:#64748b; font-weight:500; margin-left:4px;">/boat</span></div>
                <div class="shopee-price-label">Price per Boat</div>
            </div>
            <div style="margin-bottom:16px;">
                <span class="status-badge ${statusClass}" style="display:inline-block; position:static; padding:6px 16px; border-radius:20px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">${statusLabel}</span>
            </div>
            ${descHtml}
            ${destinationsHtml}
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
    shopeeCurrentTourId = null;
    window.__shopeeImages = [];
}

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

function validateTourPhone(input) {
    let cleaned = input.value.replace(/[^0-9]/g, '');
    if (cleaned.length > 10) cleaned = cleaned.slice(0, 10);
    if (cleaned.startsWith('0')) cleaned = cleaned.substring(1);

    if (input.value !== cleaned) {
        const cursorPos = input.selectionStart;
        const removed = input.value.length - cleaned.length;
        input.value = cleaned;
        try {
            const newPos = Math.max(0, cursorPos - removed);
            input.setSelectionRange(newPos, newPos);
        } catch (e) {}
    }

    const counterEl = document.getElementById('tour_contact_count');
    if (counterEl) {
        counterEl.textContent = cleaned.length + '/10';
        counterEl.classList.toggle('complete', cleaned.length === 10);
    }
}

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

let sliderImages = [];
let sliderCurrentIndex = 0;
let sliderAutoInterval = null;
let sliderIsHovering = false;

function openGalleryModal(tourId, tourName, gallery) {
    if (gallery.length === 0) { alert('No photos available for this tour.'); return; }
    sliderImages = gallery;
    sliderCurrentIndex = 0;
    const mainImage = document.getElementById('sliderMainImage');
    mainImage.src = 'uploads/tours/gallery/' + gallery[0]['folder'] + '/' + gallery[0]['image'];
    mainImage.alt = tourName + ' Photo 1';
    mainImage.style.display = 'block';
    const thumbnailsContainer = document.getElementById('sliderThumbnails');
    thumbnailsContainer.innerHTML = '';
    gallery.forEach(function(img, index) {
        const thumb = document.createElement('img');
        thumb.src = 'uploads/tours/gallery/' + img['folder'] + '/' + img['image'];
        thumb.className = 'gallery-thumbnail';
        if (index === 0) thumb.classList.add('active');
        thumb.onclick = function() { goToSliderImage(index); };
        thumbnailsContainer.appendChild(thumb);
    });
    document.getElementById('sliderCounter').textContent = '1 / ' + gallery.length;
    document.getElementById('galleryModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    startSliderAutoSlide();
}

function changeSliderImage(direction) {
    if (sliderImages.length === 0) return;
    sliderCurrentIndex += direction;
    if (sliderCurrentIndex < 0) sliderCurrentIndex = sliderImages.length - 1;
    if (sliderCurrentIndex >= sliderImages.length) sliderCurrentIndex = 0;
    const mainImage = document.getElementById('sliderMainImage');
    const img = sliderImages[sliderCurrentIndex];
    mainImage.src = 'uploads/tours/gallery/' + img['folder'] + '/' + img['image'];
    document.querySelectorAll('.gallery-thumbnail').forEach(function(thumb, idx) {
        thumb.classList.toggle('active', idx === sliderCurrentIndex);
    });
    document.getElementById('sliderCounter').textContent = (sliderCurrentIndex + 1) + ' / ' + sliderImages.length;
    resetSliderAutoSlide();
}

function goToSliderImage(index) {
    if (index < 0 || index >= sliderImages.length) return;
    sliderCurrentIndex = index;
    const mainImage = document.getElementById('sliderMainImage');
    const img = sliderImages[index];
    mainImage.src = 'uploads/tours/gallery/' + img['folder'] + '/' + img['image'];
    document.querySelectorAll('.gallery-thumbnail').forEach(function(thumb, idx) {
        thumb.classList.toggle('active', idx === index);
    });
    document.getElementById('sliderCounter').textContent = (index + 1) + ' / ' + sliderImages.length;
    resetSliderAutoSlide();
}

function startSliderAutoSlide() {
    if (sliderAutoInterval) { clearInterval(sliderAutoInterval); sliderAutoInterval = null; }
    if (sliderImages.length > 1) {
        sliderAutoInterval = setInterval(function() { if (!sliderIsHovering) changeSliderImage(1); }, 4000);
    }
}
function stopSliderAutoSlide() { if (sliderAutoInterval) { clearInterval(sliderAutoInterval); sliderAutoInterval = null; } }
function resetSliderAutoSlide() {
    stopSliderAutoSlide();
    if (sliderImages.length > 1 && !sliderIsHovering) {
        sliderAutoInterval = setInterval(function() { if (!sliderIsHovering) changeSliderImage(1); }, 4000);
    }
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
    setupGuestNameInput();
});

var overallSelectedRating = <?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>;
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
    });
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if($user_has_feedback && $user_feedback['rating'] > 0): ?>
        var rating = <?php echo (int)$user_feedback['rating']; ?>;
        document.querySelectorAll('#overallRatingInput i').forEach(function(star, index) {
            star.classList.toggle('active', index < rating);
        });
        document.getElementById('overallRatingText').textContent = overallRatingTexts[rating] || 'Select a rating';
    <?php endif; ?>
});

let currentTourId = 0;
let currentTourName = '';
let currentTourPrice = 0;
let currentMaxGuests = 0;

var calMonth = new Date().getMonth();
var calYear = new Date().getFullYear();
var selectedDate = null;
var currentBookedDates = [];
var allTourBookedDates = <?php echo json_encode($tour_booked_dates); ?>;
var allTourBlockedDates = <?php echo json_encode($tour_blocked_dates); ?>;

function showModal(type) { var modal = document.getElementById(type + 'Modal'); if(modal) modal.classList.add('show'); document.body.style.overflow = 'hidden'; }
function hideModal(type) { var modal = document.getElementById(type + 'Modal'); if(modal) modal.classList.remove('show'); document.body.style.overflow = 'auto'; }

function showPaymentPopup(reference, tourName, total) {
    document.getElementById('popup_reference').textContent = reference;
    document.getElementById('popup_tour').textContent = tourName;
    var totalNum = parseFloat(total) || 0;
    var feeNum = Math.min(<?php echo json_encode(PaymentService::RESERVATION_FEE); ?>, totalNum);
    var pesoFmt = function (n) { return '₱' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    document.getElementById('popup_amount').textContent = pesoFmt(totalNum);
    document.getElementById('popup_fee').textContent = pesoFmt(feeNum);
    document.getElementById('popup_balance').textContent = pesoFmt(Math.max(0, totalNum - feeNum));
    document.getElementById('paymentPopup').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closePaymentPopup() { document.getElementById('paymentPopup').classList.remove('show'); document.body.style.overflow = 'auto'; }

function calculateTourTotal() {
    let guests = document.getElementById('tour_guests').value;
    let total = currentTourPrice;
    document.getElementById('display_guests').textContent = guests;
    document.getElementById('display_tour_total').textContent = total.toFixed(2);
    document.getElementById('tour_total_amount').value = total;
    if(guests > currentMaxGuests) {
        document.getElementById('maxGuestsWarning').textContent = 'Maximum ' + currentMaxGuests + ' guests allowed!';
        document.getElementById('maxGuestsWarning').style.display = 'block';
    } else {
        document.getElementById('maxGuestsWarning').style.display = 'none';
    }
}

function bookTour(tourId, price, tourName, maxGuests) {
    <?php if(!isset($_SESSION['user_id'])): ?>
        window.location.href = 'login.php?redirect=tours.php';
        return;
    <?php endif; ?>

    currentTourId = tourId;
    currentTourName = tourName;
    currentTourPrice = price;
    currentMaxGuests = maxGuests;
    currentBookedDates = allTourBookedDates[tourId] || [];

    // ✅ Merge blocked dates into currentBookedDates
    var blocked = allTourBlockedDates[tourId] || [];
    blocked.forEach(function(bd) {
        if (currentBookedDates.indexOf(bd.block_date) === -1) {
            currentBookedDates.push(bd.block_date);
        }
    });

    document.getElementById('tour_booking_id').value = tourId;
    document.getElementById('tour_booking_name').value = tourName;
    document.getElementById('display_tour_name').value = tourName;
    document.getElementById('display_tour_price').value = '₱' + price.toFixed(2);
    document.getElementById('display_max_capacity').textContent = maxGuests;

    var today = new Date();
    calMonth = today.getMonth();
    calYear = today.getFullYear();
    selectedDate = null;
    document.getElementById('tour_date').value = '';
    document.getElementById('selectedDateDisplay').classList.remove('show');
    document.getElementById('calendarInfo').textContent = 'Select a date for your tour';
    renderCalendar(calMonth, calYear);

    document.getElementById('tour_guests').value = 1;
    document.getElementById('display_guests').textContent = '1';
    document.getElementById('display_tour_total').textContent = price.toFixed(2);
    document.getElementById('tour_total_amount').value = price;
    document.getElementById('tour_time').value = '';

    document.getElementById('tour_contact').value = '';
    document.getElementById('tour_contact_count').textContent = '0/10';
    document.getElementById('tour_contact_count').classList.remove('complete');

    document.getElementById('tour_guest_name').value = '';
    document.getElementById('guestNameError').classList.remove('show');
    document.getElementById('tour_guest_name').classList.remove('error');

    showModal('tourBooking');
}

function renderCalendar(month, year) {
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('calendarMonthYear').textContent = monthNames[month] + ' ' + year;
    var grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(n) {
        var d = document.createElement('div');
        d.className = 'day-name';
        d.textContent = n;
        grid.appendChild(d);
    });
    var today = new Date();
    today.setHours(0,0,0,0);
    for (var i = 0; i < firstDay; i++) {
        var e = document.createElement('div');
        e.className = 'day disabled';
        grid.appendChild(e);
    }
    for (var day = 1; day <= daysInMonth; day++) {
        var dateObj = new Date(year, month, day);
        var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        var d = document.createElement('div');
        d.className = 'day';
        d.textContent = day;
        d.dataset.date = dateStr;
        if (dateObj < today) d.classList.add('past');
        
        // Check if date is in booked dates
        if (currentBookedDates.indexOf(dateStr) !== -1) {
            // Determine if it's a blocked date or a regular booking
            var isBlocked = false;
            var blockedList = allTourBlockedDates[currentTourId] || [];
            for (var k = 0; k < blockedList.length; k++) {
                if (blockedList[k].block_date === dateStr) {
                    isBlocked = true;
                    d.dataset.blockReason = blockedList[k].reason || '';
                    d.dataset.blockType = blockedList[k].block_type || '';
                    break;
                }
            }
            if (isBlocked) {
                d.classList.add('blocked');
            } else {
                d.classList.add('booked');
            }
        }
        
        if (selectedDate === dateStr) d.classList.add('selected');
        d.onclick = function() { selectDate(this); };
        grid.appendChild(d);
    }
}

function changeMonth(delta) {
    calMonth += delta;
    if (calMonth < 0) { calMonth = 11; calYear--; }
    else if (calMonth > 11) { calMonth = 0; calYear++; }
    renderCalendar(calMonth, calYear);
}

function selectDate(el) {
    if (el.classList.contains('booked') || el.classList.contains('past') || el.classList.contains('disabled')) return;
    
    // ✅ Handle blocked date click with reason
    if (el.classList.contains('blocked')) {
        var reason = el.dataset.blockReason || 'Not available';
        var type = el.dataset.blockType || '';
        var typeLabel = type.replace(/_/g, ' ');
        alert('🚫 This date is BLOCKED.\n\nReason: ' + reason + (typeLabel ? '\nType: ' + typeLabel : '') + '\n\nPlease select another date.');
        return;
    }
    
    var date = el.dataset.date;
    if (!date) return;
    if (selectedDate === date) {
        selectedDate = null;
        el.classList.remove('selected');
        document.getElementById('tour_date').value = '';
        document.getElementById('selectedDateDisplay').classList.remove('show');
        document.getElementById('calendarInfo').textContent = 'Select a date for your tour';
        return;
    }
    document.querySelectorAll('#calendarGrid .day.selected').forEach(function(d) { d.classList.remove('selected'); });
    selectedDate = date;
    el.classList.add('selected');
    document.getElementById('tour_date').value = date;
    var p = date.split('-');
    var mn = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var disp = mn[parseInt(p[1]) - 1] + ' ' + parseInt(p[2]) + ', ' + p[0];
    var de = document.getElementById('selectedDateDisplay');
    de.innerHTML = '<i class="fas fa-check-circle"></i> Selected: ' + disp;
    de.classList.add('show');
    document.getElementById('calendarInfo').textContent = '✅ Date selected!';
}

function makeTourPayment(bookingId, amount) {
    window.location.href = 'profile.php#tours-tab';
}

let tourSelectedRating = 0;
let tourRatingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

function openTourFeedbackModal(bookingId, tourName, reference) {
    document.getElementById('tourFeedbackModalTitle').textContent = 'Rate Your Tour';
    document.getElementById('tour_feedback_booking_id').value = bookingId;
    document.getElementById('tour_feedback_tour_name').textContent = tourName;
    document.getElementById('tour_feedback_reference').textContent = reference;
    document.getElementById('tour_feedback_text').value = '';
    document.getElementById('tour_feedback_rating').value = 0;
    document.getElementById('tour_feedback_is_anonymous').checked = false;
    tourSelectedRating = 0;
    document.getElementById('tourRatingText').textContent = 'Select a rating';
    document.getElementById('tourFeedbackSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Feedback';
    document.getElementById('tourFeedbackSubmitBtn').name = 'submit_tour_feedback';
    document.querySelectorAll('#tourRatingStars i').forEach(star => star.classList.remove('active'));
    document.getElementById('tourFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function editTourFeedback(bookingId, tourName, reference, existingRating, existingText, isAnonymous) {
    document.getElementById('tourFeedbackModalTitle').textContent = 'Edit Your Review';
    document.getElementById('tour_feedback_booking_id').value = bookingId;
    document.getElementById('tour_feedback_tour_name').textContent = tourName;
    document.getElementById('tour_feedback_reference').textContent = reference;
    document.getElementById('tour_feedback_text').value = existingText || '';
    document.getElementById('tour_feedback_rating').value = existingRating;
    document.getElementById('tour_feedback_is_anonymous').checked = isAnonymous === true || isAnonymous === 'true';
    tourSelectedRating = existingRating;
    document.getElementById('tourRatingText').textContent = tourRatingTexts[existingRating] || 'Select a rating';
    document.getElementById('tourFeedbackSubmitBtn').innerHTML = '<i class="fas fa-edit"></i> Update Feedback';
    document.getElementById('tourFeedbackSubmitBtn').name = 'edit_tour_feedback';
    document.querySelectorAll('#tourRatingStars i').forEach((star, index) => {
        star.classList.toggle('active', index < existingRating);
    });
    document.getElementById('tourFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeTourFeedbackModal() { document.getElementById('tourFeedbackModal').classList.remove('show'); document.body.style.overflow = 'auto'; }

function setTourRating(rating) {
    tourSelectedRating = rating;
    document.getElementById('tour_feedback_rating').value = rating;
    document.getElementById('tourRatingText').textContent = tourRatingTexts[rating] || 'Select a rating';
    document.querySelectorAll('#tourRatingStars i').forEach((star, index) => {
        star.classList.toggle('active', index < rating);
    });
}

function viewTourFeedback(text, rating, reference) {
    document.getElementById('view_tour_feedback_reference').textContent = reference;
    document.getElementById('view_tour_feedback_text').textContent = text || 'No feedback text provided.';
    var starsHtml = '';
    for (var i = 1; i <= 5; i++) {
        starsHtml += i <= rating
            ? '<i class="fas fa-star" style="color: #f59e0b;"></i>'
            : '<i class="far fa-star" style="color: #f59e0b;"></i>';
    }
    document.getElementById('view_tour_feedback_stars').innerHTML = starsHtml + ' (' + rating + '/5)';
    document.getElementById('viewTourFeedbackModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeViewTourFeedbackModal() { document.getElementById('viewTourFeedbackModal').classList.remove('show'); document.body.style.overflow = 'auto'; }

document.getElementById('tourBookingForm').addEventListener('submit', function(e) {
    var bookingDate = document.getElementById('tour_date').value;
    if (!bookingDate) { e.preventDefault(); alert('Please select a booking date from the calendar.'); return false; }
    
    var guestName = document.getElementById('tour_guest_name').value.trim();
    if (!guestName) { 
        e.preventDefault(); 
        alert('Please enter the guest name.'); 
        document.getElementById('tour_guest_name').focus();
        return false; 
    }
    if (guestName.length > 60) {
        e.preventDefault();
        alert('Guest name must not exceed 60 characters.');
        return false;
    }
    if (/\d/.test(guestName)) {
        e.preventDefault();
        alert('Guest name cannot contain numbers.');
        document.getElementById('tour_guest_name').focus();
        return false;
    }
    if (!/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/.test(guestName)) {
        e.preventDefault();
        alert('Guest name can only contain letters, spaces, hyphens (-), apostrophes (\'), and periods (.).');
        document.getElementById('tour_guest_name').focus();
        return false;
    }
    
    var contact = document.getElementById('tour_contact').value.trim();
    if (!contact) {
        e.preventDefault();
        alert('Please enter your contact number.');
        document.getElementById('tour_contact').focus();
        return false;
    }
    if (!/^[0-9]{10}$/.test(contact)) {
        e.preventDefault();
        alert('PH mobile number must be exactly 10 digits (e.g., 9123456789).');
        document.getElementById('tour_contact').focus();
        return false;
    }
    
    var time = document.getElementById('tour_time').value;
    if (!time) { e.preventDefault(); alert('Please select a preferred time.'); return false; }
    let guests = parseInt(document.getElementById('tour_guests').value);
    if (isNaN(guests) || guests < 1) { e.preventDefault(); alert('Please enter a valid number of guests (minimum 1).'); return false; }
    if (guests > currentMaxGuests) { e.preventDefault(); alert('Maximum of ' + currentMaxGuests + ' guests allowed.'); return false; }
    return true;
});

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
    if(event.target.classList.contains('payment-popup-overlay')) { closePaymentPopup(); }
    if(event.target.classList.contains('gallery-modal')) { closeGalleryModal(); }
}

document.addEventListener('keydown', function(event) {
    if(event.key === 'Escape') {
        var lightbox = document.getElementById('placeLightbox');
        if (lightbox && lightbox.classList.contains('show')) {
            closePlaceLightbox();
            return;
        }
        var termsModal = document.getElementById('termsModal');
        if (termsModal && termsModal.classList.contains('show') && termsModal.dataset.mustAccept === '1') {
            event.stopPropagation();
            event.preventDefault();
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
        closeTourFeedbackModal();
        closeViewTourFeedbackModal();
        closeOverallFeedbackModal();
        document.body.style.overflow = 'auto';
    }
});

setTimeout(function() {
    document.querySelectorAll('.alert-overlay:not(.show)').forEach(function(alert) { alert.style.display = 'none'; });
}, 5000);

<?php if(isset($_SESSION['last_tour_booking'])): ?>
    window.onload = function() {
        showPaymentPopup(
            '<?php echo $_SESSION['last_tour_booking']['reference']; ?>',
            '<?php echo addslashes($_SESSION['last_tour_booking']['tour_name']); ?>',
            <?php echo $_SESSION['last_tour_booking']['total']; ?>
        );
        <?php unset($_SESSION['last_tour_booking']); ?>
    };
<?php endif; ?>

function openLogoutModal(event) {
    if (event) event.preventDefault();

    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();

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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('logoutModal');
        if (modal && modal.classList.contains('show')) closeLogoutModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeLogoutModal();
        });
    }
});

var __termsState = { modal: null, mustAccept: false, hasReachedBottom: false };

(function () {
    var modal = document.getElementById('termsModal');
    if (!modal) return;

    __termsState.modal = modal;
    __termsState.mustAccept = modal.dataset.mustAccept === '1';

    var closeBtn      = document.getElementById('termsModalCloseBtn');
    var acceptForm    = document.getElementById('termsAcceptForm');
    var scrollHint    = document.getElementById('termsScrollHint');
    var scrollBox     = document.getElementById('termsScrollContainer');
    var checkbox      = document.getElementById('terms_agree');
    var acceptBtn     = document.getElementById('termsAcceptBtn');
    var titleEl       = document.getElementById('termsModalTitle');
    var subtitleEl    = document.getElementById('termsModalSubtitle');

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
            e.stopPropagation();
            e.preventDefault();
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
</script>

</body>
</html>
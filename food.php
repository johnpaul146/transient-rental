<?php
session_start();
require_once 'database.php';

// ✅ Load SystemLogger
if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

// ✨ TERMS: Load the terms gate
require_once 'includes/TermsGate.php';
$termsGate = new TermsGate($pdo);

// 🔒 Helper: real client IP
if (!function_exists('getClientIp')) {
    function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ✅ NEW: Auto-create blocked_dates table (safety)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_dates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_type ENUM('house', 'tour', 'food') NOT NULL,
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

// ✅ NEW: Ensure 'food' is in the ENUM (for older tables)
try {
    $pdo->exec("ALTER TABLE blocked_dates MODIFY COLUMN item_type ENUM('house', 'tour', 'food') NOT NULL");
} catch(PDOException $e) {}

function generateReferenceNumber($prefix = 'FOOD') {
    return $prefix . '-' . date('Ymd') . '-' . rand(1000, 9999);
}

// ============================================================
// ✅ GUEST NAME VALIDATION — Letters only
// ============================================================
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

// ============================================================
// ✅ HANDLE OVERALL FEEDBACK — STAYS ON food.php
// ============================================================
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
        $is_update = (bool)$check->fetch();

        if ($is_update) {
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
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' " . ($is_update ? "updated" : "submitted") . " overall review from Food page — Rating: {$rating}/5" . ($is_anonymous ? " (Anonymous)" : ""),
                null,
                'overall_feedback',
                null,
                [
                    'rating' => $rating,
                    'anonymous' => $is_anonymous,
                    'has_comment' => !empty($comment),
                    'page' => 'food.php'
                ]
            );
        }

        header("Location: food.php?feedback_success=1");
        exit();

    } catch (Exception $e) {
        $error = "Failed to submit feedback: " . $e->getMessage();
    }
}

if (isset($_SESSION['flash_feedback_success'])) {
    $success = $_SESSION['flash_feedback_success'];
    unset($_SESSION['flash_feedback_success']);
}

// ============================================================
// ✅ HANDLE FOOD PACKAGE RESERVATION — WITH CONTACT + DELIVERY/PICKUP
// ============================================================
if (isset($_POST['order_food']) && isset($_SESSION['user_id'])) {
    try {
        $pdo->beginTransaction();

        $guest_stmt = $pdo->prepare("SELECT id, full_name, contact_number FROM guests WHERE user_id = ?");
        $guest_stmt->execute([$_SESSION['user_id']]);
        $guest = $guest_stmt->fetch();

        if (!$guest) {
            throw new Exception("Guest profile not found. Please complete your profile.");
        }

        $guest_id = $guest['id'];
        $food_id = (int)$_POST['food_id'];
        $guest_name = trim($_POST['guest_name'] ?? '');
        $preferred_date = $_POST['preferred_date'] ?? '';
        $preferred_time = $_POST['preferred_time'] ?? '';
        $special_requests = trim($_POST['special_requests'] ?? '');
        $size_variant = isset($_POST['size_variant']) ? (int)$_POST['size_variant'] : null;

        // ✅ Fulfillment method (delivery or pickup)
        $fulfillment_method = $_POST['fulfillment_method'] ?? 'pickup';
        if (!in_array($fulfillment_method, ['delivery', 'pickup'])) {
            $fulfillment_method = 'pickup';
        }

        // ✅ Contact number (PH only, 10 digits)
        $contact_number_raw = trim($_POST['contact_number'] ?? '');
        $contact_number_raw = preg_replace('/[^0-9]/', '', $contact_number_raw);

        // Auto-strip leading 0
        if (substr($contact_number_raw, 0, 1) === '0') {
            $contact_number_raw = substr($contact_number_raw, 1);
        }

        // ✅ Delivery address (required only if delivery)
        $delivery_address = trim($_POST['delivery_address'] ?? '');

        // ---------- VALIDATION ----------
        $guest_name_error = validateGuestName($guest_name);
        if ($guest_name_error) throw new Exception($guest_name_error);

        if (empty($preferred_date)) throw new Exception("Please select a preferred date.");
        if ($preferred_date < date('Y-m-d')) throw new Exception("Preferred date cannot be in the past.");
        if (empty($preferred_time)) throw new Exception("Please select a preferred time.");

        if (empty($contact_number_raw)) {
            throw new Exception("Contact number is required.");
        }
        if (!preg_match('/^[0-9]{10}$/', $contact_number_raw)) {
            throw new Exception("PH mobile number must be exactly 10 digits (e.g., 9123456789).");
        }

        // ✅ Always +63 for PH
        $contact_number = '+63' . $contact_number_raw;

        if ($fulfillment_method === 'delivery' && empty($delivery_address)) {
            throw new Exception("Delivery address is required when choosing Delivery.");
        }

        // ---------- FOOD ITEM ----------
        $food_stmt = $pdo->prepare("SELECT * FROM food_items WHERE id = ?");
        $food_stmt->execute([$food_id]);
        $food = $food_stmt->fetch();

        if (!$food) throw new Exception("Food package not found.");
        if (!$food['is_available']) throw new Exception("This food package is currently unavailable.");

        // ✅ NEW: Check against blocked_dates table
        $check_blocked = $pdo->prepare("SELECT block_date, reason, block_type FROM blocked_dates 
            WHERE item_type = 'food' 
            AND item_id = ? 
            AND block_date = ?");
        $check_blocked->execute([$food_id, $preferred_date]);
        $blocked_conflict = $check_blocked->fetch();
        if ($blocked_conflict) {
            $reason = $blocked_conflict['reason'] ?: 'Not available';
            $btype = ucwords(str_replace('_', ' ', $blocked_conflict['block_type']));
            throw new Exception("This date is BLOCKED — " . $btype . ": " . $reason . ". Please choose a different date.");
        }

        $price = $food['price'];
        $size_variant_text = '';

        if (!empty($food['size_variations'])) {
            $variations = json_decode($food['size_variations'], true);
            if (is_array($variations) && isset($size_variant) && isset($variations[$size_variant])) {
                $price = $variations[$size_variant]['price'];
                $size_variant_text = $variations[$size_variant]['size'];
            }
        }

        $quantity = 1;
        $total = $price;
        $reference = generateReferenceNumber();

        $stmt = $pdo->prepare("INSERT INTO food_bookings 
            (guest_id, guest_name, food_id, reference_number,
             quantity, size_variant, preferred_date, preferred_time,
             special_requests, contact_number, fulfillment_method, delivery_address,
             total_amount, payment_status, booking_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())");
        $stmt->execute([
            $guest_id,
            $guest_name,
            $food_id,
            $reference,
            $quantity,
            $size_variant_text,
            $preferred_date,
            $preferred_time,
            $special_requests ?: null,
            $contact_number,
            $fulfillment_method,
            $fulfillment_method === 'delivery' ? $delivery_address : null,
            $total
        ]);

        $booking_id = $pdo->lastInsertId();
        $pdo->commit();

        if (class_exists('SystemLogger')) {
            $log_desc = "Guest '{$guest_name}' reserved food '{$food['name']}' (Ref: {$reference}) — ₱" . number_format($total, 2);
            if (!empty($size_variant_text)) $log_desc .= " — Size: {$size_variant_text}";
            $log_desc .= " — " . ucfirst($fulfillment_method);

            SystemLogger::log(
                $pdo,
                'create',
                'booking',
                $log_desc,
                $booking_id,
                'food_booking',
                null,
                [
                    'food_id' => $food_id,
                    'food_name' => $food['name'],
                    'size_variant' => $size_variant_text,
                    'preferred_date' => $preferred_date,
                    'preferred_time' => $preferred_time,
                    'total' => $total,
                    'reference' => $reference,
                    'guest_id' => $guest_id,
                    'contact_number' => $contact_number,
                    'fulfillment_method' => $fulfillment_method,
                    'delivery_address' => $delivery_address,
                    'page' => 'food.php'
                ]
            );
        }

        $_SESSION['last_food_booking'] = [
            'reference' => $reference,
            'total' => $total,
            'food_name' => $food['name']
        ];

        header("Location: food.php?order_success=1");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Order failed: " . $e->getMessage();
    }
}

// ============================================================
// GET DYNAMIC CONTENT
// ============================================================
$content = [];
$stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
while ($row = $stmt->fetch()) {
    $content[$row['section_name']][$row['content_key']] = $row['content_value'];
}

$logo_path = 'uploads/logos/logo.png';
if (isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $logo_path = $content['site_settings']['logo_path'];
}

$hero_path = 'uploads/hero/hero-bg.jpg';
$home_hero_path = $content['site_settings']['hero_image_path'] ?? $hero_path;
if (!empty($home_hero_path) && file_exists($home_hero_path) && !is_dir($home_hero_path)) {
    $hero_path = $home_hero_path;
}
$page_hero_filename = basename((string)($content['site_settings']['food_hero_image'] ?? ''));
$page_hero_path = 'uploads/hero/food/' . $page_hero_filename;
if ($page_hero_filename !== '' && file_exists($page_hero_path) && !is_dir($page_hero_path)) {
    $hero_path = $page_hero_path;
}
$hero_exists = !empty($hero_path) && file_exists($hero_path) && !is_dir($hero_path);

function getGoogleMapsUrl($address) {
    if (empty($address) || $address == '#') return '#';
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address);
}

$location_address = $content['location']['address'] ?? $content['footer']['address'] ?? '123 Transient Street, City';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';
$maps_url = getGoogleMapsUrl($location_address);
$default_map_url = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863.123456789!2d119.1234567!3d16.1234567!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTbCsDA3JzI0LjAiTiAxMTnCsDA3JzI0LjAiRQ!5e0!3m2!1sen!2sph!4v1234567890';
$map_embed = !empty($google_maps_embed) && $google_maps_embed != '#' ? $google_maps_embed : $default_map_url;
$facebook_link = trim((string)($content['social']['facebook'] ?? ''));

// ============================================================
// GET FOOD ITEMS
// ============================================================
$food_items = [];
try {
    $stmt = $pdo->query("SELECT * FROM food_items ORDER BY category, name");
    $food_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $food_items = [];
}

$food_gallery = [];
foreach ($food_items as $food) {
    $food_gallery[$food['id']] = getFoodGalleryImages($food['id'], $food['name']);
}

// ✅ NEW: Get BLOCKED dates for each food item
$food_blocked_dates = [];
foreach ($food_items as $food) {
    try {
        $stmt = $pdo->prepare("SELECT block_date, reason, block_type FROM blocked_dates 
            WHERE item_type = 'food' 
            AND item_id = ? 
            AND block_date >= CURDATE() 
            ORDER BY block_date ASC");
        $stmt->execute([$food['id']]);
        $food_blocked_dates[$food['id']] = $stmt->fetchAll();
    } catch(PDOException $e) {
        $food_blocked_dates[$food['id']] = [];
    }
}

function getFoodGalleryImages($food_id, $food_name) {
    $images = [];
    $food_folder = str_replace(' ', '_', trim($food_name)) . '_' . $food_id;
    $gallery_path = 'uploads/foods/gallery/' . $food_folder . '/';

    if (file_exists($gallery_path)) {
        $files = scandir($gallery_path);
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $image_extensions)) {
                $images[] = ['image' => $file, 'folder' => $food_folder];
            }
        }
    }
    return $images;
}

$grouped_food = [];
foreach ($food_items as $food) {
    $category = $food['category'] ?? 'Other';
    if (!isset($grouped_food[$category])) $grouped_food[$category] = [];
    $grouped_food[$category][] = $food;
}

// Get user's guest info
$guest = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT id, full_name, contact_number FROM guests WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $guest = $stmt->fetch();
}

// Check if user has feedback
$user_has_feedback = false;
$user_feedback = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_feedback = $stmt->fetch();
    $user_has_feedback = ($user_feedback !== false);
}

// ============================================================
// ✨ TERMS: Handle acceptance submission
// ============================================================
if (isset($_POST['accept_terms']) && isset($_SESSION['user_id'])) {
    $termsGate->accept((int)$_SESSION['user_id'], getClientIp());

    if (class_exists('SystemLogger')) {
        SystemLogger::log(
            $pdo,
            'accept',
            'terms',
            "User '" . ($_SESSION['username'] ?? 'Unknown') . "' accepted Terms & Privacy Policy (v" . $termsGate->getCurrentVersion() . ") from Food page",
            (int)$_SESSION['user_id'],
            'user',
            null,
            ['version' => $termsGate->getCurrentVersion(), 'ip' => getClientIp(), 'page' => 'food.php']
        );
    }

    unset($_SESSION['show_terms_modal']);
    $_SESSION['terms_accepted'] = true;
    header("Location: food.php?terms_accepted=1");
    exit();
}

// LOGOUT
if (isset($_GET['logout'])) {
    if (class_exists('SystemLogger') && isset($_SESSION['user_id'])) {
        SystemLogger::log($pdo, 'logout', 'auth', "User '" . ($_SESSION['username'] ?? 'Unknown') . "' logged out", (int)$_SESSION['user_id'], 'user');
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

// HELPER FUNCTIONS
function getFoodMainImage($food, $gallery) {
    if (!empty($gallery)) return 'uploads/foods/gallery/' . $gallery[0]['folder'] . '/' . $gallery[0]['image'];
    if (!empty($food['image']) && $food['image'] != 'default-food.jpg') return 'uploads/foods/' . $food['image'];
    return null;
}

function getCategoryIcon($category) {
    $icons = ['boodle_regular' => 'fas fa-utensils','boodle_special' => 'fas fa-star','bilao' => 'fas fa-box','breakfast' => 'fas fa-sun','lunch' => 'fas fa-utensils','dinner' => 'fas fa-moon','snack' => 'fas fa-cookie','beverage' => 'fas fa-coffee'];
    return $icons[$category] ?? 'fas fa-utensils';
}

function getCategoryDisplayName($category) {
    $names = ['boodle_regular' => 'Boodle Fight (Regular)','boodle_special' => 'Boodle Fight (Special)','bilao' => 'Bilao Packages','breakfast' => 'Breakfast','lunch' => 'Lunch','dinner' => 'Dinner','snack' => 'Snacks','beverage' => 'Beverages'];
    return $names[$category] ?? ucfirst(str_replace('_', ' ', $category));
}

function getCategoryBadgeClass($category) {
    $classes = ['boodle_special' => 'category-special','boodle_regular' => 'category-regular','bilao' => 'category-bilao','breakfast' => 'category-breakfast','lunch' => 'category-lunch','dinner' => 'category-dinner','snack' => 'category-snack','beverage' => 'category-beverage'];
    return $classes[$category] ?? 'category-default';
}

function getCategoryColor($category) {
    $colors = ['boodle_special' => '#F4B400','boodle_regular' => '#2d5a7a','bilao' => '#4DA6D9','breakfast' => '#f59e0b','lunch' => '#10b981','dinner' => '#6366f1','snack' => '#ef4444','beverage' => '#8b5cf6'];
    return $colors[$category] ?? '#4DA6D9';
}

// ✨ TERMS: Compute mode for the modal
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

$sidebar_logo = 'uploads/logos/logo.png';
if (isset($content['site_settings']['logo_path']) && !empty($content['site_settings']['logo_path'])) {
    $sidebar_logo = $content['site_settings']['logo_path'];
}
$sidebar_logo_exists = !empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo);

// ============================================================
// ✅ COMPUTE PACKAGE CART COUNT (for badge in nav)
// ============================================================
$package_count = 0;
if (isset($_SESSION['package_cart'])) {
    if (!empty($_SESSION['package_cart']['house'])) $package_count++;
    if (!empty($_SESSION['package_cart']['food']))  $package_count++;
    if (!empty($_SESSION['package_cart']['tour']))  $package_count++;
}

$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Food Menu - Transient House & Tours</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================================================ */
        /* RESET & BASE */
        /* ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f7fb;
            min-height: 100vh;
        }

        /* ============================================================ */
        /* HEADER */
        /* ============================================================ */
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
            display: flex; align-items: center; gap: 15px;
            text-decoration: none; flex-shrink: 0; min-width: 0;
        }

        .logo-wrapper .logo-image {
            height: 50px; width: 50px; border-radius: 12px;
            object-fit: cover; border: 2px solid #4DA6D9;
            padding: 2px; background: white;
            transition: transform 0.3s ease; flex-shrink: 0;
        }

        .logo-wrapper .logo-image:hover { transform: scale(1.05); }

        .logo-wrapper .logo-image-placeholder {
            height: 50px; width: 50px; border-radius: 12px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 24px; font-weight: 700;
            border: 2px solid #4DA6D9; flex-shrink: 0;
        }

        .brand-text { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
        .brand-text .brand-name { font-size: 20px; font-weight: 700; color: white; letter-spacing: -0.5px; white-space: nowrap; }
        .brand-text .brand-tagline { font-size: 11px; color: #7bb8f0; font-weight: 500; letter-spacing: 0.3px; white-space: nowrap; }

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

        .menu-toggle {
            display: none;
            position: fixed;
            top: 12px; left: 12px;
            z-index: 1001;
            background: #0B2447;
            color: white;
            border: none; border-radius: 12px;
            width: 48px; height: 48px;
            font-size: 22px; cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            align-items: center; justify-content: center;
            border: 1px solid rgba(77, 166, 217, 0.2);
        }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        .menu-toggle .fa-bars { transition: transform 0.3s ease; }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }

        body.sidebar-open-mobile .menu-toggle {
            opacity: 0; visibility: hidden; pointer-events: none;
            transform: scale(0.8);
        }

        .sidebar {
            position: fixed;
            top: 0; left: -320px;
            width: 300px; height: 100vh;
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
            display: flex; align-items: center;
            justify-content: space-between; gap: 8px;
        }

        .sidebar-header .logo {
            font-size: 22px; font-weight: 700; color: white;
            text-decoration: none; display: flex;
            align-items: center; gap: 12px;
            flex: 1; min-width: 0;
        }
        .sidebar-header .logo .logo-icon {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #4DA6D9, #7bb8f0);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: white; flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
            overflow: hidden;
        }
        .sidebar-header .logo img {
            width: 48px; height: 48px;
            border-radius: 14px; object-fit: cover;
            border: 2px solid #4DA6D9; padding: 2px;
            background: white; flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(77, 166, 217, 0.3);
        }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main {
            font-size: 18px; font-weight: 700; color: white;
            letter-spacing: 0.5px; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; font-weight: 400; letter-spacing: 0.3px; }

        .sidebar-close-btn {
            display: none;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            color: #e0eeff;
            width: 36px; height: 36px;
            border-radius: 10px; font-size: 16px;
            cursor: pointer; flex-shrink: 0;
            align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .sidebar-close-btn:hover {
            background: #ef4444; border-color: #ef4444;
            color: white; transform: rotate(90deg);
        }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; position: relative; }
        .nav-link {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 20px; color: #b3d9ff;
            text-decoration: none; transition: all 0.3s;
            border-left: 3px solid transparent;
            font-weight: 500; font-size: 14px;
        }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav i { color: #7bb8f0; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 15px 20px; }

        .sidebar-overlay {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999; opacity: 0;
            transition: opacity 0.3s ease;
        }
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

        .hero { <?php if ($hero_exists): ?> background: linear-gradient(rgba(11, 36, 71, 0.5), rgba(11, 36, 71, 0.6)), url('<?php echo $hero_path; ?>?<?php echo time(); ?>'); background-size: cover; background-position: center; <?php else: ?> background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); <?php endif; ?> padding: 120px 20px 70px; color: white; text-align: center; position: relative; }
        .hero-content { max-width: 800px; margin: 0 auto; padding: 0 20px; position: relative; z-index: 1; }
        .hero h1 { font-size: 48px; font-weight: 700; margin-bottom: 20px; text-shadow: 0 2px 25px rgba(0,0,0,0.25); }
        .hero h1 i { color: #7bb8f0; }
        .hero p { font-size: 18px; margin-bottom: 30px; opacity: 0.95; text-shadow: 0 1px 15px rgba(0,0,0,0.15); }

        .main-container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        .section-title { text-align: center; margin-bottom: 40px; }
        .section-title h2 { font-size: 32px; font-weight: 700; color: #0B2447; margin-bottom: 10px; }
        .section-title h2 i { color: #4DA6D9; }
        .section-title .underline { width: 80px; height: 4px; background: linear-gradient(90deg, #4DA6D9, #7bb8f0); border-radius: 2px; margin: 0 auto; }

        .category-section { margin-bottom: 50px; }
        .category-header { display: flex; align-items: center; gap: 15px; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
        .category-header .category-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; flex-shrink: 0; }
        .category-header .category-name { font-size: 24px; font-weight: 700; color: #0B2447; }
        .category-header .category-count { margin-left: auto; background: #f1f5f9; color: #64748b; padding: 4px 14px; border-radius: 20px; font-size: 13px; font-weight: 500; }

        .food-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; }
        .food-card { background: #4DA6D9; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 15px rgba(77, 166, 217, 0.15); transition: all 0.3s ease; border: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; cursor: pointer; }
        .food-card:hover { transform: translateY(-6px); box-shadow: 0 15px 40px rgba(77, 166, 217, 0.25); }
        .food-card .food-image-wrapper { position: relative; height: 200px; overflow: hidden; flex-shrink: 0; }
        .food-card .food-image-wrapper img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
        .food-card:hover .food-image-wrapper img { transform: scale(1.05); }
        .food-card .food-image-wrapper .placeholder-image { width: 100%; height: 100%; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.6); font-size: 48px; }
        .food-card .food-image-wrapper .badge-container { position: absolute; top: 12px; left: 12px; display: flex; gap: 6px; flex-wrap: wrap; }
        .food-card .food-image-wrapper .badge-container .badge { padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-featured { background: #F4B400; color: #0B2447; }
        .badge-special { background: #F4B400; color: #0B2447; }
        .badge-regular { background: rgba(255,255,255,0.2); color: white; }
        .badge-bilao { background: rgba(255,255,255,0.2); color: white; }
        .food-card .food-image-wrapper .status-badge { position: absolute; top: 12px; right: 12px; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-available { background: rgba(16, 185, 129, 0.9); color: white; }
        .status-unavailable { background: rgba(239, 68, 68, 0.9); color: white; }
        .food-card .food-content { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        .food-card .food-content .food-name { font-size: 18px; font-weight: 700; color: white; margin-bottom: 4px; }
        .food-card .food-content .food-category-label { font-size: 12px; color: rgba(255,255,255,0.7); margin-bottom: 8px; display: inline-block; }
        .food-card .food-content .pax-badge { display: inline-block; background: rgba(255,255,255,0.12); padding: 2px 10px; border-radius: 12px; font-size: 11px; color: rgba(255,255,255,0.85); margin-bottom: 8px; }
        .food-card .food-content .food-description { color: rgba(255,255,255,0.9); font-size: 13px; line-height: 1.6; margin-bottom: 12px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 42px; }
        .food-card .food-content .inclusions { background: rgba(255,255,255,0.08); border-radius: 8px; padding: 10px 12px; margin: 8px 0 12px; font-size: 12px; color: rgba(255,255,255,0.9); line-height: 1.6; }
        .food-card .food-content .inclusions i { color: #F4B400; margin-right: 4px; }
        .food-card .food-content .price-section { margin: 8px 0; }
        .food-card .food-content .price-section .price { font-size: 24px; font-weight: 700; color: #F4B400; }
        .food-card .food-content .price-section .price-original { font-size: 14px; color: rgba(255,255,255,0.4); text-decoration: line-through; margin-right: 8px; }
        .food-card .food-content .variations { margin: 8px 0; }
        .food-card .food-content .variations .variation-item { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; color: rgba(255,255,255,0.85); border-bottom: 1px solid rgba(255,255,255,0.05); }
        .food-card .food-content .variations .variation-item:last-child { border-bottom: none; }
        .food-card .food-content .variations .variation-item .var-size { font-weight: 500; }
        .food-card .food-content .variations .variation-item .var-price { color: #F4B400; font-weight: 600; }
        .food-card .food-content .trusted-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,215,0,0.12); border: 1px solid rgba(255,215,0,0.2); padding: 4px 14px; border-radius: 20px; font-size: 10px; color: #F4B400; margin: 8px 0; }
        .food-card .food-content .actions { margin-top: auto; padding-top: 12px; display: flex; flex-direction: column; gap: 8px; }
        .food-card .food-content .actions .btn-view-photos { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 14px; background: rgba(255,255,255,0.12); color: white; border: 1px solid rgba(255,255,255,0.15); border-radius: 20px; font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.3s; width: 100%; }
        .food-card .food-content .actions .btn-view-photos:hover { background: rgba(255,255,255,0.2); }
        .food-card .food-content .actions .btn-order { width: 100%; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.25); }
        .food-card .food-content .actions .btn-order:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.35); background: #e6a800; }
        .food-card .food-content .actions .btn-order:disabled { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.4); cursor: not-allowed; transform: none; box-shadow: none; }
        .food-card.category-special { background: linear-gradient(135deg, #0B2447 0%, #1a3a5c 50%, #4DA6D9 100%); border-color: #F4B400; }
        .food-card.category-regular { background: #2d5a7a; }
        .food-card.category-bilao { background: #4DA6D9; }

        .empty-state { text-align: center; padding: 120px 20px 70px; background: white; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .empty-state i { font-size: 60px; color: #cbd5e1; margin-bottom: 20px; }
        .empty-state h3 { color: #1e293b; margin-bottom: 10px; }
        .empty-state p { color: #94a3b8; margin-bottom: 0; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 3000; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 24px; width: 90%; max-width: 550px; max-height: 90vh; overflow-y: auto; padding: 30px; }
        .modal-lg { max-width: 700px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; }
        .modal-header h3 { font-size: 20px; font-weight: 700; color: #0B2447; display: flex; align-items: center; gap: 10px; }
        .modal-header h3 i { color: #4DA6D9; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; transition: color 0.3s; background: none; border: none; padding: 0 10px; line-height: 1; }
        .modal-header .close:hover { color: #ef4444; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-control, .form-select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }
        .form-control.error { border-color: #dc2626; background: #fee2e2; }

        .phone-input-group { display: flex; gap: 8px; align-items: stretch; }
        .phone-suffix-select { min-width: 95px; max-width: 115px; padding: 10px 8px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 13px; font-weight: 600; background: #f8fafc; color: #0B2447; cursor: not-allowed; opacity: 0.85; flex-shrink: 0; font-family: inherit; }
        .phone-input-wrapper { position: relative; flex: 1; min-width: 0; }
        .phone-input-wrapper .form-control { padding-left: 38px; padding-right: 55px; font-family: 'Courier New', monospace; font-weight: 600; letter-spacing: 0.5px; }
        .phone-input-wrapper .phone-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; pointer-events: none; }
        .phone-input-wrapper .digit-count { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); font-size: 10px; color: #94a3b8; background: #f1f5f9; padding: 2px 6px; border-radius: 10px; font-weight: 600; pointer-events: none; }
        .phone-input-wrapper .digit-count.complete { color: #10b981; background: #d1fae5; }

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

        .fulfillment-method-group { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .fulfillment-option { position: relative; cursor: pointer; display: block; }
        .fulfillment-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .fulfillment-option-label { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 14px 10px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; text-align: center; transition: all 0.2s; cursor: pointer; min-height: 90px; }
        .fulfillment-option-label i { font-size: 22px; color: #94a3b8; transition: color 0.2s; }
        .fulfillment-option-label strong { font-size: 13px; color: #1e293b; font-weight: 700; }
        .fulfillment-option-label small { font-size: 10.5px; color: #94a3b8; line-height: 1.2; }
        .fulfillment-option input[type="radio"]:checked + .fulfillment-option-label { background: #e0f0fa; border-color: #4DA6D9; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.15); }
        .fulfillment-option input[type="radio"]:checked + .fulfillment-option-label i { color: #4DA6D9; }
        .fulfillment-option:hover .fulfillment-option-label { border-color: #4DA6D9; background: #f0f7fb; }

        .btn-primary { width: 100%; padding: 12px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.2); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.3); background: #e6a800; }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* CALENDAR */
        .calendar-container { background: #f8fafc; border-radius: 16px; padding: 20px; margin: 10px 0; border: 1px solid #e8f0fe; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .calendar-header h4 { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0; }
        .calendar-nav { display: flex; gap: 10px; }
        .calendar-nav button { background: #e2e8f0; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; }
        .calendar-nav button:hover { background: #4DA6D9; color: white; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-grid .day-name { text-align: center; font-size: 11px; font-weight: 600; color: #94a3b8; padding: 5px; text-transform: uppercase; }
        .calendar-grid .day { text-align: center; padding: 8px 0; border-radius: 8px; font-size: 14px; cursor: pointer; transition: all 0.2s; position: relative; }
        .calendar-grid .day:hover:not(.disabled):not(.past):not(.blocked) { background: #eef2ff; transform: scale(1.05); }
        .calendar-grid .day.selected { background: #4DA6D9; color: white; font-weight: 700; box-shadow: 0 4px 12px rgba(77, 166, 217, 0.4); }
        .calendar-grid .day.past { color: #cbd5e1 !important; cursor: not-allowed !important; }
        .calendar-grid .day.disabled { cursor: not-allowed; opacity: 0.5; }
        /* ✅ NEW: Blocked date styling */
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
        .calendar-legend { display: flex; gap: 20px; margin-top: 12px; justify-content: center; font-size: 12px; color: #64748b; flex-wrap: wrap; }
        .calendar-legend .dot { width: 14px; height: 14px; border-radius: 4px; display: inline-block; margin-right: 4px; vertical-align: middle; }
        .calendar-legend .dot.available { background: #d1fae5; border: 1px solid #10b981; }
        .calendar-legend .dot.selected { background: #4DA6D9; border: 1px solid #4DA6D9; }
        /* ✅ NEW: Blocked legend dot */
        .calendar-legend .dot.blocked { background: #fecaca; border: 1px solid #991b1b; }
        .calendar-info { text-align: center; margin-top: 10px; font-size: 13px; color: #64748b; padding: 8px; background: white; border-radius: 8px; }
        .selected-date-display { text-align: center; margin-top: 10px; padding: 10px; background: #e6f7e6; border-radius: 8px; color: #10b981; font-weight: 600; font-size: 14px; display: none; }
        .selected-date-display.show { display: block; }

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

        .alert-overlay { position: fixed; top: 20px; right: 20px; z-index: 3000; }
        .alert-box { background: white; border-radius: 12px; padding: 15px 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 10px; min-width: 300px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }
        .alert-box.success { border-left: 4px solid #10b981; }
        .alert-box.error { border-left: 4px solid #ef4444; }
        .alert-box i { font-size: 18px; }
        .alert-box.success i { color: #10b981; }
        .alert-box.error i { color: #ef4444; }

        .alert-overlay.show {
            display: flex; align-items: center; justify-content: center;
            inset: 0; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }
        .alert-box.success-popup {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 400px;
            padding: 35px 30px 25px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            border-top: 6px solid #10b981;
            animation: alertPopIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            min-width: unset;
            display: block;
        }
        @keyframes alertPopIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.9); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .alert-box.success-popup .alert-icon { font-size: 60px; margin-bottom: 15px; line-height: 1; display: block; color: #10b981; }
        .alert-box.success-popup h3 { font-size: 24px; font-weight: 700; margin-bottom: 10px; color: #10b981; }
        .alert-box.success-popup p { color: #4a6a8c; margin-bottom: 22px; font-size: 15px; line-height: 1.5; word-wrap: break-word; }
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

        .order-summary-box { background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%); padding: 20px; border-radius: 16px; margin: 20px 0; border: 2px dashed #4DA6D9; }
        .order-summary-box .summary-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 14px; color: #1e293b; }
        .order-summary-box .summary-row.total-row { border-top: 2px solid #4DA6D9; padding-top: 12px; margin-top: 8px; font-size: 20px; }

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

        @media (max-width: 768px) {
            .header-content { padding-left: 65px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 40px; width: 40px; }
            .brand-text .brand-name { font-size: 17px; }
            .brand-text .brand-tagline { font-size: 10px; }
            .hero { padding: 100px 16px 50px; }
            .hero h1 { font-size: 32px; }
            .hero p { font-size: 16px; }
            .food-grid { grid-template-columns: 1fr; }
            .category-header { flex-wrap: wrap; }
            .category-header .category-count { margin-left: 0; }
            .modal-content { padding: 20px; max-width: 95%; }
            .gallery-slider-container { height: 350px; }
            .gallery-slider-nav { width: 40px; height: 40px; font-size: 18px; }
            .gallery-thumbnail { width: 55px; height: 40px; }
        }
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
        @media (max-width: 480px) {
            .modal-content { padding: 15px; }
            .brand-text .brand-name { font-size: 15px; }
            .logo-wrapper .logo-image, .logo-wrapper .logo-image-placeholder { height: 35px; width: 35px; }
            .menu-toggle { width: 42px; height: 42px; font-size: 18px; top: 10px; left: 10px; border-radius: 10px; }
            .sidebar { width: 85%; max-width: 300px; left: -320px; }
            .sidebar-header .logo .logo-text .main { font-size: 16px; }
            .sidebar-header .logo .logo-icon, .sidebar-header .logo img { width: 40px; height: 40px; font-size: 18px; }
            .nav-link { padding: 10px 16px; font-size: 13px; }
            .hero h1 { font-size: 28px; }
            .food-card .food-image-wrapper { height: 160px; }
            .gallery-slider-container { height: 280px; }
            .gallery-slider-nav { width: 35px; height: 35px; font-size: 14px; }
            .gallery-thumbnail { width: 45px; height: 35px; }
            .gallery-modal-close { width: 35px; height: 35px; font-size: 24px; top: 10px; right: 12px; }
            .calendar-grid .day { padding: 6px 0; font-size: 12px; }
            .fulfillment-method-group { grid-template-columns: 1fr 1fr; gap: 8px; }
            .fulfillment-option-label { padding: 10px 6px; min-height: 80px; }
            .fulfillment-option-label i { font-size: 18px; }
            .fulfillment-option-label strong { font-size: 12px; }
            .fulfillment-option-label small { font-size: 9.5px; }
        }

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

        .shopee-detail-pax {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            color: #475569;
            font-weight: 500;
            margin-bottom: 16px;
            width: fit-content;
        }

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
        .shopee-price-main .original {
            font-size: 16px;
            color: #94a3b8;
            text-decoration: line-through;
            font-weight: 500;
            margin-left: 8px;
        }
        .shopee-price-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
            font-weight: 500;
        }

        .shopee-variations {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }
        .shopee-variation-item {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #475569;
            padding: 6px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .shopee-variation-item:last-child { border-bottom: none; }
        .shopee-variation-item .size { font-weight: 500; }
        .shopee-variation-item .price { font-weight: 700; color: #4DA6D9; }

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
        .shopee-inclusions {
            background: #f8fafc;
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 13px;
            color: #475569;
            line-height: 1.8;
            border: 1px solid #e8f0fe;
        }
        .shopee-inclusions i { color: #F4B400; margin-right: 6px; }

        .shopee-trusted {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,215,0,0.1);
            border: 1px solid rgba(255,215,0,0.25);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 11px;
            color: #B8860B;
            margin-bottom: 16px;
            font-weight: 500;
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
    

        /* ============================================================
           FOOD CARD DESIGN SYSTEM OVERRIDE
           ============================================================ */

        .food-card {
            background:#ffffff !important;
            border:1px solid #E8F0FE !important;
            border-radius:24px !important;
            box-shadow:0 15px 40px rgba(6,38,61,.08) !important;
            color:#06263D !important;
        }

        .food-card .food-content {
            background:#ffffff !important;
            color:#06263D !important;
            padding:24px !important;
        }

        .food-card .food-content .food-name,
        .food-card h3,
        .food-card h4 {
            color:#06263D !important;
            font-weight:800 !important;
        }

        .food-card .food-category-label,
        .food-card .food-description,
        .food-card .food-desc {
            color:#475569 !important;
        }

        .food-card .price,
        .food-card .price-section .price,
        .food-card .food-price,
        .food-card .price-tag {
            color:#0B7CC1 !important;
            font-weight:800 !important;
        }

        .food-card .variations .variation-item,
        .food-card .pax-badge {
            color:#475569 !important;
        }

        .food-card .featured-badge,
        .food-card .badge-featured,
        .food-card .badge-special {
            background:#F4B400 !important;
            color:#06263D !important;
        }

        .food-card .actions .btn-order {
            background:#F4B400 !important;
            color:#06263D !important;
        }

        /* ============================================================
   FOOD CARD FINAL LAYOUT FIX
   ============================================================ */

.food-grid {
    align-items: stretch;
}

.food-card {
    background:#FFFFFF !important;
    border:1px solid #E8F0FE !important;
    border-radius:24px !important;
    box-shadow:0 15px 40px rgba(6,38,61,.08) !important;
    display:flex !important;
    flex-direction:column !important;
    height:auto !important;
}

.food-card .food-content {
    background:#FFFFFF !important;
    padding:22px !important;
    display:flex !important;
    flex-direction:column !important;
    flex:1;
}

.food-card .food-name {
    color:#06263D !important;
    font-weight:800 !important;
}

.food-card .food-category-label {
    color:#64748B !important;
}

.food-card .food-description {
    color:#475569 !important;
}

.food-card .inclusions {
    background:#F8FAFC !important;
    color:#475569 !important;
}

.food-card .price-section {
    margin-top:auto !important;
    padding-top:20px;
}

.food-card .price {
    color:#0B7CC1 !important;
    font-weight:800 !important;
}

.food-card .variations .variation-item {
    color:#475569 !important;
}

.food-card .variations .var-price {
    color:#0B7CC1 !important;
}

.food-card .actions {
    margin-top:20px !important;
}

.food-card .btn-order {
    background:#F4B400 !important;
    color:#06263D !important;
}
</style>
</head>
<body>
<?php include 'components/navbar.php'; ?>

<!-- ALERTS -->
<?php if (isset($success) || isset($_GET['order_success'])): ?>
<div class="alert-overlay">
    <div class="alert-box success">
        <i class="fas fa-check-circle"></i>
        <?php echo isset($_GET['order_success']) ? "Food package reserved successfully! Please upload your payment proof in your profile to finalize." : $success; ?>
    </div>
</div>
<?php endif; ?>
<?php if (isset($error)): ?>
<div class="alert-overlay">
    <div class="alert-box error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
</div>
<?php endif; ?>

<!-- CENTERED SUCCESS POPUP -->
<?php if (isset($success) && isset($_GET['feedback_success']) || isset($_GET['feedback_success'])): ?>
<div class="alert-overlay show" id="feedbackSuccessAlert">
    <div class="alert-box success-popup">
        <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Thank You!</h3>
        <p><?php echo isset($success) ? htmlspecialchars($success) : 'Your feedback has been recorded.'; ?></p>
        <button class="btn-popup-ok" onclick="dismissAlert()">OK</button>
    </div>
</div>
<?php endif; ?>

<!-- HERO -->
<div class="hero">
    <div class="hero-content">
        <h1><i class="fas fa-utensils"></i> Our Food Menu</h1>
        <p>Delicious food packages and refreshments for your stay</p>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-container">
    <div class="section-title">
        <h2><i class="fas fa-utensils"></i> Food Packages</h2>
        <div class="underline"></div>
    </div>

    <?php if (empty($food_items)): ?>
        <div class="empty-state">
            <i class="fas fa-utensils"></i>
            <h3>No Food Packages Available</h3>
            <p>Please check back later for our delicious menu!</p>
        </div>
    <?php else: ?>
        <?php foreach ($grouped_food as $category => $category_food):
            $category_color = getCategoryColor($category);
            $category_badge = getCategoryBadgeClass($category);
        ?>
        <div class="category-section">
            <div class="category-header">
                <div class="category-icon" style="background: <?php echo $category_color; ?>;">
                    <i class="<?php echo getCategoryIcon($category); ?>"></i>
                </div>
                <span class="category-name"><?php echo getCategoryDisplayName($category); ?></span>
                <span class="category-count"><?php echo count($category_food); ?> packages</span>
            </div>

            <div class="food-grid">
                <?php foreach ($category_food as $food):
                    $gallery = $food_gallery[$food['id']] ?? [];
                    $has_gallery = !empty($gallery);
                    $main_image = getFoodMainImage($food, $gallery);
                    $size_variations = !empty($food['size_variations']) ? json_decode($food['size_variations'], true) : [];
                    $has_variations = !empty($size_variations) && is_array($size_variations);
                ?>
                <div class="food-card <?php echo $category_badge; ?>" onclick="openShopeeView(<?php echo $food['id']; ?>)">
                    <div class="food-image-wrapper">
                        <?php if ($main_image): ?>
                            <img src="<?php echo htmlspecialchars($main_image); ?>?<?php echo time(); ?>" alt="<?php echo htmlspecialchars($food['name']); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                            <div class="placeholder-image" style="display: none;"><i class="fas fa-utensils"></i></div>
                        <?php else: ?>
                            <div class="placeholder-image"><i class="fas fa-utensils"></i></div>
                        <?php endif; ?>

                        <div class="badge-container">
                            <?php if ($food['is_featured']): ?>
                                <span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                            <?php endif; ?>
                            <?php if ($category == 'boodle_special'): ?>
                                <span class="badge badge-special"><i class="fas fa-crown"></i> Special</span>
                            <?php elseif ($category == 'boodle_regular'): ?>
                                <span class="badge badge-regular">Regular</span>
                            <?php elseif ($category == 'bilao'): ?>
                                <span class="badge badge-bilao"><i class="fas fa-box"></i> Bilao</span>
                            <?php endif; ?>
                        </div>

                        <span class="status-badge status-<?php echo $food['is_available'] ? 'available' : 'unavailable'; ?>">
                            <?php echo $food['is_available'] ? 'Available' : 'Unavailable'; ?>
                        </span>
                    </div>

                    <div class="food-content">
                        <h4 class="food-name"><?php echo htmlspecialchars($food['name']); ?></h4>

                        <?php if (!empty($food['pax_range'])): ?>
                            <span class="pax-badge"><i class="fas fa-users"></i> <?php echo htmlspecialchars($food['pax_range']); ?></span>
                        <?php endif; ?>

                        <span class="food-category-label"><?php echo getCategoryDisplayName($category); ?></span>

                        <?php if (!empty($food['description'])): ?>
                            <div class="food-description"><?php echo nl2br(htmlspecialchars($food['description'])); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($food['inclusions'])): ?>
                            <div class="inclusions">
                                <i class="fas fa-check-circle"></i> <strong>Inclusions:</strong><br>
                                <?php echo nl2br(htmlspecialchars($food['inclusions'])); ?>
                            </div>
                        <?php endif; ?>

                        <div class="price-section">
                            <?php if ($has_variations): ?>
                                <div class="variations">
                                    <?php foreach ($size_variations as $var): ?>
                                        <div class="variation-item">
                                            <span class="var-size">
                                                <?php echo htmlspecialchars($var['size']); ?>
                                                <?php if (!empty($var['pax'])): ?>
                                                    <span style="font-size: 11px; color: rgba(255,255,255,0.5);">(<?php echo htmlspecialchars($var['pax']); ?>)</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="var-price">₱<?php echo number_format($var['price']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="price">
                                    <?php if (!empty($food['price_original']) && $food['price_original'] > $food['price']): ?>
                                        <span class="price-original">₱<?php echo number_format($food['price_original']); ?></span>
                                    <?php endif; ?>
                                    ₱<?php echo number_format($food['price']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($category == 'boodle_special'): ?>
                            <div class="trusted-badge">
                                <i class="fas fa-check-circle"></i> Trusted by Mayor Bryan & LGU Alaminos
                            </div>
                        <?php endif; ?>

                        <div class="actions" onclick="event.stopPropagation();">
                            <?php if ($has_gallery): ?>
                                <button class="btn-view-photos" onclick="event.stopPropagation(); openGalleryModal(<?php echo $food['id']; ?>, '<?php echo addslashes($food['name']); ?>')">
                                    <i class="fas fa-images"></i> View Photos (<?php echo count($gallery); ?>)
                                </button>
                            <?php endif; ?>

                            <?php if ($food['is_available']): ?>
                                <?php if ($is_logged_in): ?>
                                    <button class="btn-order" onclick="event.stopPropagation(); orderFood(<?php echo $food['id']; ?>, '<?php echo addslashes($food['name']); ?>', <?php echo $food['price']; ?>)">
                                        <i class="fas fa-calendar-check"></i> Reserve Package
                                    </button>
                                <?php else: ?>
                                    <a href="login.php?redirect=food.php" class="btn-order" style="text-decoration: none;" onclick="event.stopPropagation();">
                                        <i class="fas fa-sign-in-alt"></i> Login to Book
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <button class="btn-order" disabled><i class="fas fa-clock"></i> Unavailable</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- GALLERY SLIDER MODAL -->
<div id="galleryModal" class="gallery-modal" onclick="if(event.target === this) closeGalleryModal()">
    <div class="gallery-modal-content">
        <button class="gallery-modal-close" onclick="closeGalleryModal()">&times;</button>
        <div class="gallery-slider-container">
            <img id="sliderMainImage" class="slider-image" src="" alt="Food Photo">
            <button class="gallery-slider-nav gallery-slider-prev" onclick="changeSliderImage(-1)">❮</button>
            <button class="gallery-slider-nav gallery-slider-next" onclick="changeSliderImage(1)">❯</button>
            <div class="gallery-slider-counter" id="sliderCounter">1 / 1</div>
        </div>
        <div class="gallery-thumbnails" id="sliderThumbnails"></div>
    </div>
</div>

<!-- SHOPEE-STYLE VIEW MODAL -->
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

<!-- FOOD ORDER MODAL -->
<div class="modal" id="foodOrderModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-calendar-check"></i> Reserve Food Package</h3>
            <span class="close" onclick="hideModal('foodOrder')">&times;</span>
        </div>
        <div class="modal-body" style="padding: 25px; max-height: 80vh; overflow-y: auto;">
            <form method="POST" action="food.php" id="foodOrderForm">
                <input type="hidden" name="food_id" id="food_order_id">
                <input type="hidden" name="food_name" id="food_order_name">

                <div class="form-group">
                    <label><i class="fas fa-utensils"></i> Food Package</label>
                    <input type="text" id="food_display_name" class="form-control" readonly style="background: #f1f5f9; font-weight: 600; color: #0B2447;">
                </div>

                <div class="form-group" id="sizeVariantGroup" style="display:none;">
                    <label><i class="fas fa-arrows-alt-h"></i> Package Size / Variant <span style="color:#dc2626;">*</span></label>
                    <select name="size_variant" id="food_size_variant" class="form-select" onchange="updateFoodPrice()" required></select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Price</label>
                    <input type="text" id="food_display_price" class="form-control" readonly style="background: #f1f5f9; font-weight: 600; color: #10b981;">
                </div>

                <!-- ✅ GUEST NAME — Letters only -->
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Guest Name <span style="color:#dc2626;">*</span></label>
                    <div class="name-input-wrapper">
                        <input type="text"
                               name="guest_name"
                               id="food_guest_name"
                               class="form-control"
                               placeholder="Full name of person ordering"
                               maxlength="60"
                               data-name-field="1"
                               autocomplete="name"
                               required
                               value="<?php echo $guest ? htmlspecialchars($guest['full_name']) : ''; ?>">
                        <i class="fas fa-font name-hint-icon"></i>
                    </div>
                    <div class="name-error-msg" id="foodGuestNameError">
                        <i class="fas fa-exclamation-circle"></i> <span id="foodGuestNameErrorText"></span>
                    </div>
                    <small style="color:#94a3b8;font-size:11px;">
                        <i class="fas fa-info-circle"></i> Letters only — no numbers or special characters
                    </small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Contact Number <span style="color:#dc2626;">*</span></label>
                    <div class="phone-input-group">
                        <select class="phone-suffix-select" disabled aria-label="Country code">
                            <option value="+63" selected>+63 🇵🇭</option>
                        </select>
                        <div class="phone-input-wrapper">
                            <i class="fas fa-phone phone-icon"></i>
                            <input type="tel"
                                   name="contact_number"
                                   id="food_contact_number"
                                   class="form-control"
                                   placeholder="9123456789"
                                   maxlength="10"
                                   inputmode="numeric"
                                   autocomplete="tel"
                                   required
                                   oninput="validateFoodPhone(this)">
                            <span class="digit-count" id="food_contact_count">0/10</span>
                        </div>
                    </div>
                    <small style="color:#94a3b8;font-size:11px;">
                        <i class="fas fa-info-circle"></i> Enter 10-digit PH mobile number (e.g., 9123456789)
                    </small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-truck"></i> Fulfillment Method <span style="color:#dc2626;">*</span></label>
                    <div class="fulfillment-method-group">
                        <label class="fulfillment-option">
                            <input type="radio" name="fulfillment_method" value="pickup" checked onchange="toggleDeliveryAddress()">
                            <span class="fulfillment-option-label">
                                <i class="fas fa-store"></i>
                                <strong>Pickup</strong>
                                <small>Pick up at our location</small>
                            </span>
                        </label>
                        <label class="fulfillment-option">
                            <input type="radio" name="fulfillment_method" value="delivery" onchange="toggleDeliveryAddress()">
                            <span class="fulfillment-option-label">
                                <i class="fas fa-truck"></i>
                                <strong>Delivery</strong>
                                <small>Deliver to your address</small>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="form-group" id="deliveryAddressGroup" style="display:none;">
                    <label><i class="fas fa-map-marker-alt"></i> Delivery Address <span style="color:#dc2626;">*</span></label>
                    <textarea name="delivery_address" id="food_delivery_address" class="form-control" rows="2" placeholder="Complete delivery address (house no., street, barangay, city)"></textarea>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Preferred Date <span style="color:#dc2626;">*</span></label>
                    <div class="calendar-container">
                        <div class="calendar-header">
                            <h4 id="foodCalendarMonthYear"></h4>
                            <div class="calendar-nav">
                                <button type="button" onclick="changeFoodMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                                <button type="button" onclick="changeFoodMonth(1)"><i class="fas fa-chevron-right"></i></button>
                            </div>
                        </div>
                        <div class="calendar-grid" id="foodCalendarGrid"></div>
                        <div class="calendar-legend">
                            <span><span class="dot available"></span> Available</span>
                            <span><span class="dot blocked"></span> Blocked</span>
                            <span><span class="dot selected"></span> Selected</span>
                        </div>
                        <div class="calendar-info" id="foodCalendarInfo">Select a date for your food order</div>
                        <div class="selected-date-display" id="foodSelectedDateDisplay"></div>
                    </div>
                    <input type="hidden" name="preferred_date" id="food_preferred_date" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Preferred Time <span style="color:#dc2626;">*</span></label>
                    <select name="preferred_time" id="food_preferred_time" class="form-select" required>
                        <option value="">Select Time Slot</option>
                        <option value="06:00:00">🌅 6:00 AM — Breakfast</option>
                        <option value="07:00:00">☀️ 7:00 AM — Breakfast</option>
                        <option value="08:00:00">☀️ 8:00 AM — Breakfast</option>
                        <option value="09:00:00">☀️ 9:00 AM — Brunch</option>
                        <option value="10:00:00">☀️ 10:00 AM — Brunch</option>
                        <option value="11:00:00">☀️ 11:00 AM — Pre-Lunch</option>
                        <option value="12:00:00">🍽️ 12:00 PM — Lunch</option>
                        <option value="13:00:00">🍽️ 1:00 PM — Lunch</option>
                        <option value="14:00:00">🌤️ 2:00 PM — Late Lunch</option>
                        <option value="15:00:00">🌤️ 3:00 PM — Snack</option>
                        <option value="16:00:00">🌤️ 4:00 PM — Snack</option>
                        <option value="17:00:00">🌇 5:00 PM — Early Dinner</option>
                        <option value="18:00:00">🌙 6:00 PM — Dinner</option>
                        <option value="19:00:00">🌙 7:00 PM — Dinner</option>
                        <option value="20:00:00">🌙 8:00 PM — Late Dinner</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Special Requests</label>
                    <textarea name="special_requests" id="food_special_requests" class="form-control" rows="3" placeholder="e.g., Food allergies, dietary restrictions, less spicy, extra rice..."></textarea>
                </div>

                <div class="order-summary-box">
                    <div class="summary-row total-row" style="border-top:none; padding-top:0; margin-top:0;">
                        <span><i class="fas fa-money-bill-wave" style="color:#10b981;"></i> <strong>Total Amount:</strong></span>
                        <span><strong style="color: #10b981; font-size: 22px;">₱<span id="food_display_total">0.00</span></strong></span>
                    </div>
                    <input type="hidden" name="total_amount" id="food_total_amount">
                </div>

                <?php if (!isset($_SESSION['user_id'])): ?>
                <div style="padding: 12px; background: #fef3c7; border-radius: 8px; margin-bottom: 15px;">
                    <i class="fas fa-exclamation-triangle"></i> Please <a href="login.php">login</a> to place a reservation.
                </div>
                <?php endif; ?>

                <div style="background: #fef3c7; padding: 12px; border-radius: 10px; margin-bottom: 15px; font-size: 12px; color: #92400e;">
                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> Your reservation will be reviewed by admin. Please upload your payment proof in your profile to finalize.
                </div>

                <button type="submit" name="order_food" class="btn-primary" <?php echo !isset($_SESSION['user_id']) ? 'disabled' : ''; ?>>
                    <i class="fas fa-check-circle"></i> Submit Reservation
                </button>
            </form>
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
            <?php if (isset($_SESSION['user_id'])): ?>

                <?php if ($user_has_feedback): ?>
                    <div class="prev-rating-banner">
                        <div class="prev-label">You previously rated:</div>
                        <div class="prev-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star"
                                   style="color: <?php echo $i <= (int)$user_feedback['rating'] ? '#f59e0b' : '#cbd5e1'; ?>;"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="prev-hint">Update your rating and review below</div>
                    </div>

                    <label class="overall-rating-label">Your Overall Rating</label>

                    <form method="POST" action="food.php" id="overallFeedbackForm">
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
                            $ratingTexts = [1 => 'Very Poor', 2 => 'Poor', 3 => 'Average', 4 => 'Good', 5 => 'Excellent!'];
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

                    <form method="POST" action="food.php" id="overallFeedbackForm">
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
                        <textarea name="comment" id="overall_comment" class="review-textarea" rows="3" placeholder="Share your overall experience..."></textarea>

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

<!-- FOOTER -->
<?php include 'components/footer.php'; ?>

<!-- TERMS & PRIVACY MODAL -->
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

<script>
// ============================================================
// ✅ FOOD DATA FOR SHOPEE VIEW & GALLERY
// ============================================================
const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;

const foodGalleryData = <?php
    $data = [];
    foreach ($food_items as $food) {
        $gallery = $food_gallery[$food['id']] ?? [];
        if (!empty($gallery)) $data[$food['id']] = $gallery;
    }
    echo json_encode($data);
?>;

const foodSizeVariations = <?php
    $variationsData = [];
    foreach ($food_items as $food) {
        $variations = !empty($food['size_variations']) ? json_decode($food['size_variations'], true) : [];
        if (!empty($variations) && is_array($variations)) $variationsData[$food['id']] = $variations;
    }
    echo json_encode($variationsData);
?>;

// ✅ NEW: Blocked dates per food item
const foodBlockedDatesData = <?php echo json_encode($food_blocked_dates); ?>;

// Complete food data for Shopee view
const foodData = <?php
    $fd = [];
    foreach ($food_items as $food) {
        $gallery = $food_gallery[$food['id']] ?? [];
        $variations = !empty($food['size_variations']) ? json_decode($food['size_variations'], true) : [];
        $mainImage = '';
        if (!empty($gallery)) {
            $mainImage = 'uploads/foods/gallery/' . $gallery[0]['folder'] . '/' . $gallery[0]['image'];
        } elseif (!empty($food['image']) && $food['image'] != 'default-food.jpg') {
            $mainImage = 'uploads/foods/' . $food['image'];
        }
        $fd[$food['id']] = [
            'id' => (int)$food['id'],
            'name' => $food['name'],
            'category' => $food['category'],
            'description' => $food['description'] ?? '',
            'inclusions' => $food['inclusions'] ?? '',
            'price' => (float)$food['price'],
            'price_original' => (float)($food['price_original'] ?? 0),
            'pax_range' => $food['pax_range'] ?? '',
            'is_available' => (bool)$food['is_available'],
            'is_featured' => (bool)$food['is_featured'],
            'main_image' => $mainImage,
            'gallery' => $gallery,
            'variations' => $variations,
            'category_display' => getCategoryDisplayName($food['category']),
            'category_badge' => getCategoryBadgeClass($food['category']),
        ];
    }
    echo json_encode($fd);
?>;

// ============================================================
// SHOPEE-STYLE VIEW MODAL
// ============================================================
let shopeeCurrentFoodId = null;
let shopeeCurrentImageIndex = 0;

function openShopeeView(foodId) {
    const food = foodData[foodId];
    if (!food) return;

    shopeeCurrentFoodId = foodId;
    shopeeCurrentImageIndex = 0;

    const isAvailable = food.is_available;
    const hasVariations = food.variations && food.variations.length > 0;

    let images = [];
    if (food.gallery && food.gallery.length > 0) {
        images = food.gallery.map(function(img) {
            return { src: 'uploads/foods/gallery/' + img.folder + '/' + img.image, folder: img.folder, image: img.image };
        });
    } else if (food.main_image) {
        images = [{ src: food.main_image, folder: '', image: '' }];
    }

    let badgesHtml = '';
    if (food.is_featured) {
        badgesHtml += '<span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>';
    }
    if (food.category === 'boodle_special') {
        badgesHtml += '<span class="badge badge-special"><i class="fas fa-crown"></i> Special</span>';
    } else if (food.category === 'boodle_regular') {
        badgesHtml += '<span class="badge badge-regular">Regular</span>';
    } else if (food.category === 'bilao') {
        badgesHtml += '<span class="badge badge-bilao"><i class="fas fa-box"></i> Bilao</span>';
    }

    let priceHtml = '';
    if (hasVariations) {
        priceHtml = '<div class="shopee-variations">';
        food.variations.forEach(function(v) {
            priceHtml += '<div class="shopee-variation-item">';
            priceHtml += '<span class="size">' + escapeHtml(v.size) + (v.pax ? ' <small>(' + escapeHtml(v.pax) + ')</small>' : '') + '</span>';
            priceHtml += '<span class="price">₱' + formatNumber(v.price) + '</span>';
            priceHtml += '</div>';
        });
        priceHtml += '</div>';
    } else {
        if (food.price_original > food.price) {
            priceHtml = '<div class="shopee-price-main">₱' + formatNumber(food.price) + '<span class="original">₱' + formatNumber(food.price_original) + '</span></div>';
        } else {
            priceHtml = '<div class="shopee-price-main">₱' + formatNumber(food.price) + '</div>';
        }
    }

    let thumbsHtml = '';
    if (images.length > 1) {
        images.forEach(function(img, idx) {
            thumbsHtml += '<img src="' + img.src + '" class="shopee-thumb' + (idx === 0 ? ' active' : '') + '" onclick="switchShopeeImage(' + idx + ')" alt="Thumb ' + (idx+1) + '">';
        });
    }

    let actionsHtml = '';
    if (isAvailable) {
        if (isLoggedIn) {
            actionsHtml = '<button class="btn-book" onclick="event.stopPropagation(); orderFood(' + food.id + ', \'' + escapeJs(food.name) + '\', ' + food.price + ')">' +
                '<i class="fas fa-calendar-check"></i> Reserve Package</button>';
        } else {
            actionsHtml = '<a href="login.php?redirect=food.php" class="btn-book" style="text-decoration:none;" onclick="event.stopPropagation();">' +
                '<i class="fas fa-sign-in-alt"></i> Login to Book</a>';
        }
    } else {
        actionsHtml = '<button class="btn-book" disabled><i class="fas fa-clock"></i> Unavailable</button>';
    }

    let loginNote = '';
    if (!isLoggedIn && isAvailable) {
        loginNote = '<div class="shopee-login-note"><i class="fas fa-lock"></i> You need to <a href="login.php?redirect=food.php">login</a> or <a href="register.php">register</a> to book this package.</div>';
    }

    let descHtml = '';
    if (food.description) {
        descHtml = '<div class="shopee-detail-section"><h4><i class="fas fa-align-left"></i> Description</h4><p>' + nl2br(escapeHtml(food.description)) + '</p></div>';
    }

    let incHtml = '';
    if (food.inclusions) {
        incHtml = '<div class="shopee-detail-section"><h4><i class="fas fa-list-check"></i> Inclusions</h4><div class="shopee-inclusions"><i class="fas fa-check-circle"></i> ' + nl2br(escapeHtml(food.inclusions)) + '</div></div>';
    }

    let trustedHtml = '';
    if (food.category === 'boodle_special') {
        trustedHtml = '<div class="shopee-trusted"><i class="fas fa-check-circle"></i> Trusted by Mayor Bryan & LGU Alaminos</div>';
    }

    let mainImageHtml = '';
    if (images.length > 0) {
        mainImageHtml = '<img id="shopeeMainImg" class="shopee-main-image" src="' + images[0].src + '" alt="' + escapeHtml(food.name) + '">';
    } else {
        mainImageHtml = '<div class="shopee-main-image placeholder"><i class="fas fa-utensils"></i></div>';
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
            <h2 class="shopee-detail-title">${escapeHtml(food.name)}</h2>
            <div class="shopee-detail-category"><i class="fas fa-tag"></i> ${escapeHtml(food.category_display)}</div>
            ${food.pax_range ? '<div class="shopee-detail-pax"><i class="fas fa-users"></i> ' + escapeHtml(food.pax_range) + '</div>' : ''}
            <div class="shopee-price-section">
                ${priceHtml}
                <div class="shopee-price-label">Price</div>
            </div>
            ${trustedHtml}
            ${descHtml}
            ${incHtml}
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
    shopeeCurrentFoodId = null;
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

// ============================================================
// GALLERY SLIDER (standalone photos button)
// ============================================================
let sliderImages = [];
let sliderCurrentIndex = 0;
let sliderAutoInterval = null;
let sliderIsHovering = false;

function openGalleryModal(foodId, foodName) {
    const gallery = foodGalleryData[foodId] || [];
    if (gallery.length === 0) { alert('No photos available for this food package.'); return; }

    sliderImages = gallery;
    sliderCurrentIndex = 0;

    const mainImage = document.getElementById('sliderMainImage');
    mainImage.src = 'uploads/foods/gallery/' + gallery[0]['folder'] + '/' + gallery[0]['image'];
    mainImage.style.display = 'block';

    const thumbnailsContainer = document.getElementById('sliderThumbnails');
    thumbnailsContainer.innerHTML = '';
    gallery.forEach(function(img, index) {
        const thumb = document.createElement('img');
        thumb.src = 'uploads/foods/gallery/' + img['folder'] + '/' + img['image'];
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
    const img = sliderImages[sliderCurrentIndex];
    document.getElementById('sliderMainImage').src = 'uploads/foods/gallery/' + img['folder'] + '/' + img['image'];
    document.querySelectorAll('.gallery-thumbnail').forEach(function(thumb, idx) {
        thumb.classList.toggle('active', idx === sliderCurrentIndex);
    });
    document.getElementById('sliderCounter').textContent = (sliderCurrentIndex + 1) + ' / ' + sliderImages.length;
    resetSliderAutoSlide();
}

function goToSliderImage(index) {
    if (index < 0 || index >= sliderImages.length) return;
    sliderCurrentIndex = index;
    const img = sliderImages[index];
    document.getElementById('sliderMainImage').src = 'uploads/foods/gallery/' + img['folder'] + '/' + img['image'];
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
});

// ============================================================
// MODAL FUNCTIONS
// ============================================================
function showModal(type) {
    document.getElementById(type + 'Modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function hideModal(type) {
    document.getElementById(type + 'Modal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

window.onclick = function(event) {
    if (!event.target.classList.contains('modal')) return;

    if (event.target.id === 'termsModal') {
        var termsModal = document.getElementById('termsModal');
        if (termsModal.dataset.mustAccept === '1') return;
        termsModal.classList.remove('show');
        document.body.style.overflow = 'auto';
        return;
    }

    event.target.classList.remove('show');
    document.body.style.overflow = 'auto';
    if (event.target.classList.contains('gallery-modal')) { closeGalleryModal(); }
}

// ============================================================
// FOOD CALENDAR STATE
// ============================================================
var foodCalMonth = new Date().getMonth();
var foodCalYear = new Date().getFullYear();
var foodSelectedDate = null;

// ✅ NEW: Track current food item & its blocked dates
var currentFoodIdForCalendar = 0;
var currentFoodBlockedDates = [];

function renderFoodCalendar(month, year) {
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    document.getElementById('foodCalendarMonthYear').textContent = monthNames[month] + ' ' + year;

    var grid = document.getElementById('foodCalendarGrid');
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

        if (dateObj < today) {
            d.classList.add('past');
        } else {
            // ✅ NEW: Check if date is blocked
            var isBlocked = false;
            for (var k = 0; k < currentFoodBlockedDates.length; k++) {
                if (currentFoodBlockedDates[k].block_date === dateStr) {
                    isBlocked = true;
                    d.dataset.blockReason = currentFoodBlockedDates[k].reason || '';
                    d.dataset.blockType = currentFoodBlockedDates[k].block_type || '';
                    break;
                }
            }
            if (isBlocked) {
                d.classList.add('blocked');
            } else {
                d.onclick = function() { selectFoodDate(this); };
            }
        }

        if (foodSelectedDate === dateStr) {
            d.classList.add('selected');
        }

        grid.appendChild(d);
    }
}

function changeFoodMonth(delta) {
    foodCalMonth += delta;
    if (foodCalMonth < 0) { foodCalMonth = 11; foodCalYear--; }
    else if (foodCalMonth > 11) { foodCalMonth = 0; foodCalYear++; }
    renderFoodCalendar(foodCalMonth, foodCalYear);
}

function selectFoodDate(el) {
    if (el.classList.contains('past') || el.classList.contains('disabled')) return;

    // ✅ NEW: Handle blocked date click with reason
    if (el.classList.contains('blocked')) {
        var reason = el.dataset.blockReason || 'Not available';
        var type = el.dataset.blockType || '';
        var typeLabel = type.replace(/_/g, ' ');
        alert('🚫 This date is BLOCKED.\n\nReason: ' + reason + (typeLabel ? '\nType: ' + typeLabel : '') + '\n\nPlease select another date.');
        return;
    }

    var date = el.dataset.date;
    if (!date) return;

    document.querySelectorAll('#foodCalendarGrid .day.selected').forEach(function(d) {
        d.classList.remove('selected');
    });

    foodSelectedDate = date;
    el.classList.add('selected');
    document.getElementById('food_preferred_date').value = date;

    var parts = date.split('-');
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var displayStr = monthNames[parseInt(parts[1]) - 1] + ' ' + parseInt(parts[2]) + ', ' + parts[0];

    var display = document.getElementById('foodSelectedDateDisplay');
    display.innerHTML = '<i class="fas fa-check-circle"></i> Selected: ' + displayStr;
    display.classList.add('show');

    document.getElementById('foodCalendarInfo').textContent = '✅ Date selected!';
}

// ============================================================
// ✅ PH-ONLY PHONE VALIDATION
// ============================================================
function validateFoodPhone(input) {
    let cleaned = input.value.replace(/[^0-9]/g, '');

    if (cleaned.length > 10) {
        cleaned = cleaned.slice(0, 10);
    }

    if (cleaned.startsWith('0')) {
        cleaned = cleaned.substring(1);
    }

    if (input.value !== cleaned) {
        const cursorPos = input.selectionStart;
        const removed = input.value.length - cleaned.length;
        input.value = cleaned;
        try {
            const newPos = Math.max(0, cursorPos - removed);
            input.setSelectionRange(newPos, newPos);
        } catch (e) {}
    }

    const counterEl = document.getElementById('food_contact_count');
    if (counterEl) {
        counterEl.textContent = cleaned.length + '/10';
        counterEl.classList.toggle('complete', cleaned.length === 10);
    }
}

// ============================================================
// ✅ GUEST NAME INPUT — Letters only restriction
// ============================================================
function setupGuestNameInput() {
    const input = document.getElementById('food_guest_name');
    const errorBox = document.getElementById('foodGuestNameError');
    const errorText = document.getElementById('foodGuestNameErrorText');
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
// ✅ TOGGLE DELIVERY ADDRESS
// ============================================================
function toggleDeliveryAddress() {
    const method = document.querySelector('input[name="fulfillment_method"]:checked');
    const addressGroup = document.getElementById('deliveryAddressGroup');
    const addressInput = document.getElementById('food_delivery_address');

    if (!method || !addressGroup) return;

    if (method.value === 'delivery') {
        addressGroup.style.display = 'block';
        addressInput.required = true;
    } else {
        addressGroup.style.display = 'none';
        addressInput.required = false;
        addressInput.value = '';
    }
}

// ============================================================
// FOOD PACKAGE RESERVATION
// ============================================================
function orderFood(foodId, foodName, price) {
    closeShopeeView();

    document.getElementById('food_order_id').value = foodId;
    document.getElementById('food_order_name').value = foodName;
    document.getElementById('food_display_name').value = foodName;

    const variations = foodSizeVariations[foodId] || [];
    const sizeVariantGroup = document.getElementById('sizeVariantGroup');
    const sizeSelect = document.getElementById('food_size_variant');

    if (variations.length > 0) {
        sizeVariantGroup.style.display = 'block';
        sizeSelect.innerHTML = '';
        variations.forEach(function(variant, index) {
            const option = document.createElement('option');
            option.value = index;
            option.dataset.price = variant.price;
            option.textContent = variant.size + (variant.pax ? ' (' + variant.pax + ')' : '') + ' - ₱' + parseFloat(variant.price).toFixed(2);
            sizeSelect.appendChild(option);
        });
        document.getElementById('food_display_price').value = '₱' + parseFloat(variations[0].price).toFixed(2);
    } else {
        sizeVariantGroup.style.display = 'none';
        document.getElementById('food_display_price').value = '₱' + parseFloat(price).toFixed(2);
    }

    document.getElementById('food_preferred_time').value = '';
    document.getElementById('food_special_requests').value = '';

    const contactInput = document.getElementById('food_contact_number');
    if (contactInput) {
        contactInput.value = '';
        const counterEl = document.getElementById('food_contact_count');
        if (counterEl) {
            counterEl.textContent = '0/10';
            counterEl.classList.remove('complete');
        }
    }

    const guestNameError = document.getElementById('foodGuestNameError');
    const guestNameInput = document.getElementById('food_guest_name');
    if (guestNameError) guestNameError.classList.remove('show');
    if (guestNameInput) guestNameInput.classList.remove('error');

    const pickupRadio = document.querySelector('input[name="fulfillment_method"][value="pickup"]');
    if (pickupRadio) {
        pickupRadio.checked = true;
        toggleDeliveryAddress();
    }

    // ✅ NEW: Set current food ID & load blocked dates
    currentFoodIdForCalendar = foodId;
    currentFoodBlockedDates = foodBlockedDatesData[foodId] || [];

    var today = new Date();
    foodCalMonth = today.getMonth();
    foodCalYear = today.getFullYear();
    foodSelectedDate = null;
    document.getElementById('food_preferred_date').value = '';
    document.getElementById('foodSelectedDateDisplay').classList.remove('show');
    document.getElementById('foodCalendarInfo').textContent = 'Select a date for your food order';
    renderFoodCalendar(foodCalMonth, foodCalYear);

    updateFoodTotal();
    showModal('foodOrder');
}

function updateFoodTotal() {
    const priceText = document.getElementById('food_display_price').value;
    const price = parseFloat(priceText.replace('₱', '')) || 0;
    const total = price;

    document.getElementById('food_display_total').textContent = total.toFixed(2);
    document.getElementById('food_total_amount').value = total;
}

function updateFoodPrice() {
    var select = document.getElementById('food_size_variant');
    if (select) {
        var selectedOption = select.options[select.selectedIndex];
        var price = parseFloat(selectedOption.dataset.price) || 0;
        document.getElementById('food_display_price').value = '₱' + price.toFixed(2);
        updateFoodTotal();
    }
}

// ============================================================
// FORM VALIDATION
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    toggleDeliveryAddress();
    setupGuestNameInput();

    const form = document.getElementById('foodOrderForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            var guestName = document.getElementById('food_guest_name').value.trim();
            if (!guestName) {
                e.preventDefault();
                alert('Please enter the guest name.');
                document.getElementById('food_guest_name').focus();
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
                document.getElementById('food_guest_name').focus();
                return false;
            }
            if (!/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/.test(guestName)) {
                e.preventDefault();
                alert("Guest name can only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.).");
                document.getElementById('food_guest_name').focus();
                return false;
            }

            var contact = document.getElementById('food_contact_number').value.trim();
            if (!contact) {
                e.preventDefault();
                alert('Please enter your contact number.');
                document.getElementById('food_contact_number').focus();
                return false;
            }
            if (!/^[0-9]{10}$/.test(contact)) {
                e.preventDefault();
                alert('PH mobile number must be exactly 10 digits (e.g., 9123456789).');
                document.getElementById('food_contact_number').focus();
                return false;
            }

            var preferredDate = document.getElementById('food_preferred_date').value;
            if (!preferredDate) { e.preventDefault(); alert('Please select a preferred date from the calendar.'); return false; }

            var preferredTime = document.getElementById('food_preferred_time').value;
            if (!preferredTime) { e.preventDefault(); alert('Please select a preferred time.'); return false; }

            var method = document.querySelector('input[name="fulfillment_method"]:checked');
            if (method && method.value === 'delivery') {
                var address = document.getElementById('food_delivery_address').value.trim();
                if (!address) {
                    e.preventDefault();
                    alert('Please enter the delivery address.');
                    document.getElementById('food_delivery_address').focus();
                    return false;
                }
            }

            return true;
        });
    }
});

// ============================================================
// OVERALL FEEDBACK
// ============================================================
var overallSelectedRating = <?php echo $user_has_feedback ? (int)$user_feedback['rating'] : 0; ?>;
var overallRatingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

function openOverallFeedbackModal(event) {
    if (event) event.preventDefault();
    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();

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
        if (rating > 0 && index < rating) {
            star.classList.add('preview');
        }
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
        if (index < rating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
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

document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') return;

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

    var logoutModal = document.getElementById('logoutModal');
    if (logoutModal && logoutModal.classList.contains('show')) {
        closeLogoutModal();
        return;
    }

    var galleryModal = document.getElementById('galleryModal');
    if (galleryModal && galleryModal.classList.contains('open')) {
        closeGalleryModal();
        return;
    }

    document.querySelectorAll('.modal.show').forEach(function(modal) {
        modal.classList.remove('show');
    });
    document.body.style.overflow = 'auto';
});

setTimeout(function() {
    document.querySelectorAll('.alert-overlay:not(.show)').forEach(function(alert) { alert.style.display = 'none'; });
}, 5000);

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

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('logoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeLogoutModal();
            }
        });
    }
});

var __termsState = {
    modal: null,
    mustAccept: false,
    hasReachedBottom: false
};

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
        if (!needsScroll) {
            unlockAcceptForm();
        }

        scrollBox.addEventListener('scroll', function () {
            var atBottom = (scrollBox.scrollTop + scrollBox.clientHeight) >= (scrollBox.scrollHeight - 15);
            if (atBottom) {
                unlockAcceptForm();
            }
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
        if (e.target === modal && __termsState.mustAccept) {
            e.stopPropagation();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape'
            && modal.classList.contains('show')
            && __termsState.mustAccept) {
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
        if (typeof window.openTermsMustAccept === 'function') {
            window.openTermsMustAccept();
        }
    } else {
        if (typeof window.openTermsReadOnly === 'function') {
            window.openTermsReadOnly();
        }
    }
    return false;
}
</script>

</body>
</html>
<?php
session_start();
require_once 'database.php';

if (file_exists('includes/SystemLogger.php')) {
    require_once 'includes/SystemLogger.php';
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=packages.php");
    exit();
}

// ============================================================
// ✅ AUTO-ADD package_id COLUMN (safety check)
// ============================================================
try {
    foreach (['house_bookings', 'tour_bookings', 'food_bookings'] as $table) {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'package_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN package_id INT DEFAULT NULL");
        }
    }
} catch(PDOException $e) {}

// ============================================================
// ✅ NEW: AUTO-ADD check_in_time / check_out_time
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM `house_bookings` LIKE 'check_in_time'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `house_bookings` ADD COLUMN check_in_time TIME DEFAULT '14:00:00' AFTER check_in_date");
    }
    $cols = $pdo->query("SHOW COLUMNS FROM `house_bookings` LIKE 'check_out_time'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `house_bookings` ADD COLUMN check_out_time TIME DEFAULT '12:00:00' AFTER check_out_date");
    }
} catch(PDOException $e) {}

// ============================================================
// ✅ BLOCKING STATUSES — ONLY these block dates (turn red)
//    Pending bookings DO NOT block other guests
//    Applies to: houses, tours, AND food
// ============================================================
$blocking_statuses = ['confirmed', 'approved', 'completed'];
$blocking_placeholders = "'" . implode("','", $blocking_statuses) . "'";

// ============================================================
// HANDLE OVERALL FEEDBACK
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
        $is_update = (bool)$check->fetch();

        if ($is_update) {
            $stmt = $pdo->prepare("UPDATE overall_feedback SET rating = ?, comment = ?, updated_at = NOW(), is_anonymous = ? WHERE user_id = ?");
            $stmt->execute([$rating, $comment, $is_anonymous, $user_id]);
            $_SESSION['flash_feedback_success'] = "Thank you! Your feedback has been updated successfully.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO overall_feedback (user_id, rating, comment, is_anonymous) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $rating, $comment, $is_anonymous]);
            $_SESSION['flash_feedback_success'] = "Thank you for your feedback! Your review has been submitted.";
        }

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, $is_update ? 'update' : 'create', 'review',
                "User '" . ($_SESSION['username'] ?? 'Unknown') . "' " . ($is_update ? "updated" : "submitted") . " overall review from Packages page — Rating: {$rating}/5" . ($is_anonymous ? " (Anonymous)" : ""),
                null, 'overall_feedback', null,
                ['rating' => $rating, 'anonymous' => $is_anonymous, 'has_comment' => !empty($comment), 'page' => 'packages.php']);
        }

        header("Location: packages.php?feedback_success=1");
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
// Initialize package cart
// ============================================================
if (!isset($_SESSION['package_cart'])) {
    $_SESSION['package_cart'] = ['house' => null, 'food' => null, 'tour' => null];
}

$cart = &$_SESSION['package_cart'];

if (!is_array($cart['house']) || !isset($cart['house']['price'])) $cart['house'] = null;
if (!is_array($cart['food'])  || !isset($cart['food']['price']))  $cart['food']  = null;
if (!is_array($cart['tour'])  || !isset($cart['tour']['price']))  $cart['tour']  = null;

$has_house = !empty($cart['house']);
$has_food  = !empty($cart['food']);
$has_tour  = !empty($cart['tour']);
$has_any   = $has_house || $has_food || $has_tour;

// ============================================================
// REMOVE / CLEAR
// ============================================================
if (isset($_GET['remove'])) {
    $type = $_GET['remove'];
    if (in_array($type, ['house', 'food', 'tour'])) {
        unset($_SESSION['package_cart'][$type]);
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'update', 'booking', "Guest removed {$type} from package cart", null, 'package', null, ['removed_item' => $type], 'warning');
        }
    }
    header("Location: packages.php");
    exit();
}

if (isset($_GET['clear'])) {
    $_SESSION['package_cart'] = ['house' => null, 'food' => null, 'tour' => null];
    if (class_exists('SystemLogger')) {
        SystemLogger::log($pdo, 'delete', 'booking', "Guest cleared entire package cart", null, 'package', null, null, 'warning');
    }
    header("Location: packages.php");
    exit();
}

// ============================================================
// ADD HOUSE TO PACKAGE  ✅ UPDATED with check-in/check-out times
// ============================================================
if (isset($_POST['add_house_to_package'])) {
    try {
        $house_id = (int)$_POST['house_id'];
        $check_in = $_POST['check_in'] ?? '';
        $check_out = $_POST['check_out'] ?? '';
        $check_in_time  = $_POST['check_in_time']  ?? '14:00';
        $check_out_time = $_POST['check_out_time'] ?? '12:00';
        $guests = (int)($_POST['guests'] ?? 1);
        $guest_names_json = $_POST['guest_names_json'] ?? '[]';

        if (empty($house_id)) throw new Exception("Please select a house.");
        if (empty($check_in)) throw new Exception("Check-in date is required.");
        if (empty($check_out)) throw new Exception("Check-out date is required.");

        if (!preg_match('/^\d{2}:\d{2}$/', $check_in_time))  throw new Exception("Invalid check-in time format.");
        if (!preg_match('/^\d{2}:\d{2}$/', $check_out_time)) throw new Exception("Invalid check-out time format.");

        if ($guests < 1) $guests = 1;
        if ($guests > 20) $guests = 20;

        $check_in_dt = new DateTime($check_in);
        $check_out_dt = new DateTime($check_out);
        $today = new DateTime();
        $today->setTime(0,0,0);

        if ($check_in_dt < $today) throw new Exception("Check-in date cannot be in the past.");
        if ($check_out_dt <= $check_in_dt) throw new Exception("Check-out must be after check-in.");

        $nights = $check_out_dt->diff($check_in_dt)->days;
        $days = $nights + 1;

        $names_array = json_decode($guest_names_json, true);
        if (!is_array($names_array)) $names_array = [];
        $names_array = array_values(array_filter(array_map('trim', $names_array)));

        if (count($names_array) !== $guests) {
            throw new Exception("Please provide exactly {$guests} guest name(s). You provided " . count($names_array) . ".");
        }

        foreach ($names_array as $n) {
            if (mb_strlen($n) < 2) throw new Exception("Each guest name must be at least 2 characters. Invalid: {$n}");
            if (!preg_match("/^[a-zA-ZÀ-ÿ\s\-'.]+$/u", $n)) {
                throw new Exception("Guest names can only contain letters, spaces, hyphens, periods, and apostrophes. Invalid: {$n}");
            }
        }
        $guest_names_clean = implode("\n", $names_array);

        $stmt = $pdo->prepare("SELECT id, house_name, price_per_night, status FROM houses WHERE id = ?");
        $stmt->execute([$house_id]);
        $house = $stmt->fetch();
        if (!$house) throw new Exception("House not found.");
        if ($house['status'] !== 'available') throw new Exception("This house is not available.");

        // ✅ FIX: Only block if booking is confirmed/approved/completed (NOT pending)
        global $blocking_placeholders;
        $conflict = $pdo->prepare("
            SELECT id FROM house_bookings 
            WHERE house_id = ? 
              AND booking_status IN ({$blocking_placeholders})
              AND NOT (check_out_date <= ? OR check_in_date > ?)
        ");
        $conflict->execute([$house_id, $check_in, $check_out]);
        if ($conflict->fetch()) throw new Exception("Selected dates are not available. Please choose different dates.");

        $total_price = $house['price_per_night'] * $nights;

        $_SESSION['package_cart']['house'] = [
            'id'            => $house['id'],
            'name'          => $house['house_name'],
            'check_in'      => $check_in,
            'check_in_time' => $check_in_time,   // ✅ NEW
            'check_out'     => $check_out,
            'check_out_time'=> $check_out_time,  // ✅ NEW
            'guests'        => $guests,
            'guest_names'   => $guest_names_clean,
            'nights'        => $nights,
            'days'          => $days,
            'price'         => $total_price
        ];

        header("Location: packages.php");
        exit();

    } catch (Exception $e) {
        $modal_error = $e->getMessage();
        $open_modal = 'house';
    }
}

// ============================================================
// ADD FOOD TO PACKAGE
// ============================================================
if (isset($_POST['add_food_to_package'])) {
    try {
        $food_id = (int)$_POST['food_id'];
        $preferred_date = $_POST['preferred_date'] ?? '';
        $preferred_time = $_POST['preferred_time'] ?? '';
        $fulfillment_method = $_POST['fulfillment_method'] ?? 'pickup';
        $delivery_address = trim($_POST['delivery_address'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $special_requests = trim($_POST['special_requests'] ?? '');
        $size_variant = isset($_POST['size_variant']) ? (int)$_POST['size_variant'] : null;

        if (empty($food_id)) throw new Exception("Please select a food item.");
        if (empty($preferred_date)) throw new Exception("Preferred date is required.");
        if (empty($preferred_time)) throw new Exception("Preferred time is required.");
        if (!in_array($fulfillment_method, ['delivery', 'pickup'])) $fulfillment_method = 'pickup';

        $today = date('Y-m-d');
        if ($preferred_date < $today) throw new Exception("Preferred date cannot be in the past.");

        $contact_number = preg_replace('/[^0-9]/', '', $contact_number);
        if (substr($contact_number, 0, 1) === '0') $contact_number = substr($contact_number, 1);
        if (empty($contact_number)) throw new Exception("Contact number is required.");
        if (!preg_match('/^[0-9]{10}$/', $contact_number)) throw new Exception("PH mobile number must be exactly 10 digits (e.g., 9123456789).");
        $contact_full = '+63' . $contact_number;

        if ($fulfillment_method === 'delivery' && empty($delivery_address)) throw new Exception("Delivery address is required when choosing Delivery.");

        $stmt = $pdo->prepare("SELECT id, name, price, is_available, size_variations FROM food_items WHERE id = ?");
        $stmt->execute([$food_id]);
        $food = $stmt->fetch();
        if (!$food) throw new Exception("Food item not found.");
        if (!$food['is_available']) throw new Exception("This food item is currently unavailable.");

        $price = $food['price'];
        $size_variant_text = '';
        $size_variant_index = null;

        if (!empty($food['size_variations'])) {
            $variations = json_decode($food['size_variations'], true);
            if (is_array($variations) && isset($size_variant) && isset($variations[$size_variant])) {
                $price = $variations[$size_variant]['price'];
                $size_variant_text = $variations[$size_variant]['size'];
                $size_variant_index = $size_variant;
            }
        }

        $_SESSION['package_cart']['food'] = [
            'id'                  => $food['id'],
            'name'                => $food['name'],
            'preferred_date'      => $preferred_date,
            'preferred_time'      => $preferred_time,
            'fulfillment_method'  => $fulfillment_method,
            'delivery_address'    => $fulfillment_method === 'delivery' ? $delivery_address : null,
            'contact_number'      => $contact_full,
            'special_requests'    => $special_requests,
            'size_variant'        => $size_variant_text,
            'size_variant_index'  => $size_variant_index,
            'price'               => $price
        ];

        header("Location: packages.php");
        exit();
    } catch (Exception $e) {
        $modal_error = $e->getMessage();
        $open_modal = 'food';
    }
}

// ============================================================
// ADD TOUR TO PACKAGE
// ============================================================
if (isset($_POST['add_tour_to_package'])) {
    try {
        $tour_id = (int)$_POST['tour_id'];
        $booking_date = $_POST['booking_date'] ?? '';
        $preferred_time = $_POST['preferred_time'] ?? '';
        $number_of_guests = (int)($_POST['number_of_guests'] ?? 1);
        $guest_name = trim($_POST['guest_name'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $special_requests = trim($_POST['special_requests'] ?? '');

        if (empty($tour_id)) throw new Exception("Please select a tour.");
        if (empty($booking_date)) throw new Exception("Booking date is required.");
        if (empty($preferred_time)) throw new Exception("Preferred time is required.");
        if (empty($guest_name)) throw new Exception("Guest name is required.");

        $today = date('Y-m-d');
        if ($booking_date < $today) throw new Exception("Booking date cannot be in the past.");

        $contact_number = preg_replace('/[^0-9]/', '', $contact_number);
        if (substr($contact_number, 0, 1) === '0') $contact_number = substr($contact_number, 1);
        if (empty($contact_number)) throw new Exception("Contact number is required.");
        if (!preg_match('/^[0-9]{10}$/', $contact_number)) throw new Exception("PH mobile number must be exactly 10 digits (e.g., 9123456789).");
        $contact_full = '+63' . $contact_number;

        $stmt = $pdo->prepare("SELECT id, tour_name, price_per_boat, max_guests, status FROM tours WHERE id = ?");
        $stmt->execute([$tour_id]);
        $tour = $stmt->fetch();
        if (!$tour) throw new Exception("Tour not found.");
        if ($tour['status'] !== 'available') throw new Exception("This tour is not available.");

        if ($number_of_guests < 1) $number_of_guests = 1;
        if ($number_of_guests > $tour['max_guests']) throw new Exception("Maximum guests allowed for this tour is " . $tour['max_guests'] . ".");

        // ✅ FIXED: Only block if booking is confirmed/approved/completed (NOT pending)
        $conflict = $pdo->prepare("SELECT id FROM tour_bookings WHERE tour_id = ? AND booking_date = ? AND booking_status IN ({$blocking_placeholders})");
        $conflict->execute([$tour_id, $booking_date]);
        if ($conflict->fetch()) throw new Exception("This boat is already booked on " . date('M d, Y', strtotime($booking_date)) . ". Please select a different date.");

        $_SESSION['package_cart']['tour'] = [
            'id'                => $tour['id'],
            'name'              => $tour['tour_name'],
            'booking_date'      => $booking_date,
            'preferred_time'    => $preferred_time,
            'number_of_guests'  => $number_of_guests,
            'guest_name'        => $guest_name,
            'contact_number'    => $contact_full,
            'special_requests'  => $special_requests,
            'price'             => $tour['price_per_boat']
        ];

        header("Location: packages.php");
        exit();
    } catch (Exception $e) {
        $modal_error = $e->getMessage();
        $open_modal = 'tour';
    }
}

// ============================================================
// FINAL PACKAGE BOOKING CONFIRMATION
// ============================================================
$booking_success = null;
$booking_error = null;

if (isset($_POST['confirm_package_booking'])) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, full_name, contact_number FROM guests WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $guest = $stmt->fetch();
        if (!$guest) throw new Exception("Guest profile not found. Please complete your profile.");
        $guest_id = $guest['id'];

        $package_ref = 'PKG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $house_amount = 0.00;
        $tour_amount  = 0.00;
        $food_amount  = 0.00;
        $created_bookings = [];
        $house_booking_id = null;
        $tour_booking_id  = null;
        $food_booking_id  = null;
        $package_contact  = $guest['contact_number'] ?? null;
        $package_requests = [];

        if ($has_house) {
            $h = $cart['house'];

            // ✅ Only block for confirmed/approved/completed
            $conflict = $pdo->prepare("
                SELECT id FROM house_bookings 
                WHERE house_id = ? 
                  AND booking_status IN ({$blocking_placeholders})
                  AND NOT (check_out_date <= ? OR check_in_date > ?)
            ");
            $conflict->execute([$h['id'], $h['check_in'], $h['check_out']]);
            if ($conflict->fetch()) throw new Exception("House '{$h['name']}' is no longer available for the selected dates.");

            $ref = $package_ref . '-H';

            // ✅ NEW: Include check-in/check-out time
            $check_in_time_db  = ($h['check_in_time']  ?? '14:00') . ':00';
            $check_out_time_db = ($h['check_out_time'] ?? '12:00') . ':00';

            // ✅ NEW: booking_status = 'pending' (not 'confirmed' — admin must confirm)
            $stmt = $pdo->prepare("INSERT INTO house_bookings 
                (guest_id, house_id, reference_number, check_in_date, check_in_time, check_out_date, check_out_time,
                 number_of_guests, total_amount, guest_names, payment_status, booking_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())");
            $stmt->execute([
                $guest_id, $h['id'], $ref, 
                $h['check_in'], $check_in_time_db,
                $h['check_out'], $check_out_time_db,
                $h['guests'], $h['price'], $h['guest_names']
            ]);
            $house_booking_id = (int)$pdo->lastInsertId();
            $created_bookings[] = ['type' => 'house', 'id' => $house_booking_id, 'ref' => $ref, 'name' => $h['name']];
            $house_amount += (float)$h['price'];
        }

        if ($has_food) {
            $f = $cart['food'];
            $ref = $package_ref . '-F';

            if (!empty($f['contact_number'])) $package_contact = $f['contact_number'];
            if (!empty($f['special_requests'])) $package_requests[] = "Food: " . $f['special_requests'];

            $stmt = $pdo->prepare("INSERT INTO food_bookings 
                (guest_id, guest_name, food_id, reference_number, quantity, size_variant,
                 preferred_date, preferred_time, special_requests,
                 contact_number, fulfillment_method, delivery_address,
                 total_amount, payment_status, booking_status, created_at)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())");
            $stmt->execute([
                $guest_id, $guest['full_name'], $f['id'], $ref,
                $f['size_variant'] ?? null, $f['preferred_date'], $f['preferred_time'],
                $f['special_requests'] ?? null, $f['contact_number'], $f['fulfillment_method'],
                $f['fulfillment_method'] === 'delivery' ? ($f['delivery_address'] ?? null) : null,
                $f['price']
            ]);
            $food_booking_id = (int)$pdo->lastInsertId();
            $created_bookings[] = ['type' => 'food', 'id' => $food_booking_id, 'ref' => $ref, 'name' => $f['name']];
            $food_amount += (float)$f['price'];
        }

        if ($has_tour) {
            $t = $cart['tour'];
            // ✅ FIXED: Only block if booking is confirmed/approved/completed
            $conflict = $pdo->prepare("SELECT id FROM tour_bookings WHERE tour_id = ? AND booking_date = ? AND booking_status IN ({$blocking_placeholders})");
            $conflict->execute([$t['id'], $t['booking_date']]);
            if ($conflict->fetch()) throw new Exception("Tour '{$t['name']}' is no longer available on " . date('M d, Y', strtotime($t['booking_date'])) . ".");

            $ref = $package_ref . '-T';
            if (!empty($t['contact_number'])) $package_contact = $t['contact_number'];
            if (!empty($t['special_requests'])) $package_requests[] = "Tour: " . $t['special_requests'];

            $stmt = $pdo->prepare("INSERT INTO tour_bookings 
                (guest_id, tour_id, reference_number, booking_date, preferred_time,
                 number_of_guests, guest_name, contact_number, special_requests,
                 total_amount, payment_status, booking_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())");
            $stmt->execute([
                $guest_id, $t['id'], $ref, $t['booking_date'], $t['preferred_time'],
                $t['number_of_guests'], $t['guest_name'], $t['contact_number'],
                $t['special_requests'] ?? null, $t['price']
            ]);
            $tour_booking_id = (int)$pdo->lastInsertId();
            $created_bookings[] = ['type' => 'tour', 'id' => $tour_booking_id, 'ref' => $ref, 'name' => $t['name']];
            $tour_amount += (float)$t['price'];
        }

        $grand_total = $house_amount + $tour_amount + $food_amount;
        $package_requests_text = !empty($package_requests) ? implode("\n", $package_requests) : null;

        $stmt = $pdo->prepare("INSERT INTO package_bookings 
            (reference_number, guest_id, house_booking_id, tour_booking_id, food_booking_id,
             house_amount, tour_amount, food_amount, grand_total,
             contact_number, special_requests,
             payment_status, booking_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())");
        $stmt->execute([
            $package_ref, $guest_id,
            $house_booking_id, $tour_booking_id, $food_booking_id,
            $house_amount, $tour_amount, $food_amount, $grand_total,
            $package_contact, $package_requests_text
        ]);
        $package_id = (int)$pdo->lastInsertId();

        if ($house_booking_id) $pdo->prepare("UPDATE house_bookings SET package_id = ? WHERE id = ?")->execute([$package_id, $house_booking_id]);
        if ($tour_booking_id)  $pdo->prepare("UPDATE tour_bookings SET package_id = ? WHERE id = ?")->execute([$package_id, $tour_booking_id]);
        if ($food_booking_id)  $pdo->prepare("UPDATE food_bookings SET package_id = ? WHERE id = ?")->execute([$package_id, $food_booking_id]);

        $pdo->commit();

        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'create', 'booking',
                "Guest created PACKAGE booking {$package_ref} — " . count($created_bookings) . " item(s), Total: ₱" . number_format($grand_total, 2),
                $package_id, 'package', null,
                ['package_reference' => $package_ref, 'items' => $created_bookings, 'grand_total' => $grand_total]);
        }

        $_SESSION['package_cart'] = ['house' => null, 'food' => null, 'tour' => null];
        $_SESSION['last_package_booking'] = [
            'id' => $package_id, 'reference' => $package_ref,
            'total' => $grand_total, 'items_count' => count($created_bookings)
        ];

        // Fetch GCash settings
        $gcash_popup_settings = [];
        try {
            $stmt = $pdo->query("SELECT content_key, content_value FROM site_content WHERE section_name = 'gcash'");
            while ($row = $stmt->fetch()) {
                $gcash_popup_settings[$row['content_key']] = $row['content_value'];
            }
        } catch (PDOException $e) {
            error_log("GCash popup settings fetch failed: " . $e->getMessage());
        }

        $gcash_name_popup   = $gcash_popup_settings['account_name'] ?? 'Juan Dela Cruz';
        $gcash_number_popup = $gcash_popup_settings['number'] ?? '09123456789';
        $gcash_qr_popup     = $gcash_popup_settings['qr_code'] ?? '';

        $qr_path_popup   = '';
        $qr_exists_popup = false;

        if (!empty($gcash_qr_popup)) {
            $candidate = 'uploads/gcash/' . $gcash_qr_popup;
            if (file_exists($candidate) && !is_dir($candidate)) {
                $qr_path_popup   = $candidate;
                $qr_exists_popup = true;
            }
        }

        if (!$qr_exists_popup) {
            $latest_file = null;
            $latest_time = 0;
            $search_dirs = ['uploads/gcash/', 'uploads/'];

            foreach ($search_dirs as $dir) {
                if (!is_dir($dir)) continue;
                foreach (glob($dir . 'gcash_qr.*') as $f) {
                    $mtime = @filemtime($f);
                    if ($mtime !== false && $mtime > $latest_time) {
                        $latest_time = $mtime;
                        $latest_file = $f;
                    }
                }
                if ($latest_file !== null) break;
            }

            if ($latest_file !== null) {
                $qr_path_popup   = $latest_file;
                $qr_exists_popup = true;

                try {
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
                } catch (PDOException $e) {
                    error_log("QR auto-save failed: " . $e->getMessage());
                }
            }
        }

        $booking_success = [
            'id'             => $package_id,
            'reference'      => $package_ref,
            'total'          => $grand_total,
            'items_count'    => count($created_bookings),
            'package_name'   => 'Package Booking',
            'guest_name'     => $guest['full_name'],
            'gcash_name'     => $gcash_name_popup,
            'gcash_number'   => $gcash_number_popup,
            'qr_image'       => $qr_path_popup,
            'qr_exists'      => $qr_exists_popup
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $booking_error = $e->getMessage();
    }
}

// ============================================================
// GET DYNAMIC CONTENT
// ============================================================
$content = [];
try {
    $stmt = $pdo->query("SELECT section_name, content_key, content_value FROM site_content");
    while ($row = $stmt->fetch()) {
        $content[$row['section_name']][$row['content_key']] = $row['content_value'];
    }
} catch (PDOException $e) {}

$sidebar_logo = 'uploads/logos/logo.png';
if (!empty($content['site_settings']['logo_path'])) $sidebar_logo = $content['site_settings']['logo_path'];
$sidebar_logo_exists = !empty($sidebar_logo) && file_exists($sidebar_logo) && !is_dir($sidebar_logo);

$hero_path = 'uploads/hero/hero-bg.jpg';
if (!empty($content['site_settings']['hero_image_path'])) $hero_path = $content['site_settings']['hero_image_path'];
$hero_exists = !empty($hero_path) && file_exists($hero_path) && !is_dir($hero_path);

$site_name = $content['site_settings']['site_name'] ?? 'Transient House & Tours';

$facebook_link = $content['social']['facebook'] ?? '#';
$location_address = $content['location']['address'] ?? $content['footer']['address'] ?? 'Inansuana, Lucap, Alaminos, Philippines, 2404';
$google_maps_embed = $content['location']['google_maps_embed'] ?? '';

function getGoogleMapsUrl($address) {
    if (empty($address) || $address == '#') return '#';
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address);
}
$maps_url = getGoogleMapsUrl($location_address);

$default_map_url = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3863.123456789!2d119.1234567!3d16.1234567!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMTbCsDA3JzI0LjAiTiAxMTnCsDA3JzI0LjAiRQ!5e0!3m2!1sen!2sph!4v1234567890';
$map_embed = !empty($google_maps_embed) && $google_maps_embed != '#' ? $google_maps_embed : $default_map_url;

$grand_total = 0;
if ($has_house && isset($cart['house']['price'])) $grand_total += (float)$cart['house']['price'];
if ($has_food  && isset($cart['food']['price']))  $grand_total += (float)$cart['food']['price'];
if ($has_tour  && isset($cart['tour']['price']))  $grand_total += (float)$cart['tour']['price'];

// ============================================================
// FETCH ITEMS
// ============================================================
$houses = [];
$food_items = [];
$tours = [];

$result = $pdo->query("SELECT * FROM houses WHERE status = 'available' ORDER BY house_name");
if ($result !== false) {
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) $houses = $rows;
}

$result = $pdo->query("SELECT * FROM food_items WHERE is_available = 1 ORDER BY category, name");
if ($result !== false) {
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) $food_items = $rows;
}

$result = $pdo->query("SELECT * FROM tours WHERE status = 'available' ORDER BY tour_name");
if ($result !== false) {
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) $tours = $rows;
}

$open_modal = $open_modal ?? null;

$user_has_feedback = false;
$user_feedback = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM overall_feedback WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_feedback = $stmt->fetch();
    $user_has_feedback = ($user_feedback !== false);
}

// ============================================================
// HELPER: Build photo gallery
// ============================================================
if (!function_exists('buildItemGallery')) {
    function buildItemGallery($type, $item) {
        $images = [];
        if (!is_array($item)) return $images;
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) return $images;

        if ($type === 'house') {
            global $pdo;
            try {
                $stmt = $pdo->prepare("SELECT image FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC, id ASC");
                $stmt->execute([$id]);
                $rows = $stmt->fetchAll();
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $f = $row['image'] ?? '';
                        if (empty($f)) continue;
                        $p1 = 'uploads/houses/gallery/' . $f;
                        $p2 = 'uploads/houses/' . $f;
                        if (file_exists($p1)) $images[] = $p1;
                        elseif (file_exists($p2)) $images[] = $p2;
                    }
                }
            } catch (PDOException $e) {}

            if (empty($images) && !empty($item['image']) && $item['image'] !== 'default-house.jpg') {
                $p1 = 'uploads/houses/' . $item['image'];
                $p2 = 'uploads/houses/gallery/' . $item['image'];
                if (file_exists($p1)) $images[] = $p1;
                elseif (file_exists($p2)) $images[] = $p2;
            }
        } elseif ($type === 'tour') {
            $folder = $item['folder_name'] ?? '';
            if (empty($folder)) $folder = str_replace(' ', '_', trim($item['tour_name'] ?? ''));
            $base = 'uploads/tours/gallery/' . $folder . '/';
            $exts = ['jpg','jpeg','png','gif','webp','bmp'];
            if (is_dir($base)) {
                foreach (scandir($base) as $file) {
                    if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $exts)) $images[] = $base . $file;
                }
            }
            if (empty($images) && !empty($item['image']) && $item['image'] !== 'default-tour.jpg') {
                $p1 = 'uploads/tours/' . $item['image'];
                if (file_exists($p1)) $images[] = $p1;
            }
        } elseif ($type === 'food') {
            $name = $item['name'] ?? '';
            $folder = str_replace(' ', '_', trim($name)) . '_' . $id;
            $base = 'uploads/foods/gallery/' . $folder . '/';
            $exts = ['jpg','jpeg','png','gif','webp','bmp'];
            if (is_dir($base)) {
                foreach (scandir($base) as $file) {
                    if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $exts)) $images[] = $base . $file;
                }
            }
            if (empty($images) && !empty($item['image']) && $item['image'] !== 'default-food.jpg') {
                $p1 = 'uploads/foods/' . $item['image'];
                if (file_exists($p1)) $images[] = $p1;
            }
        }
        return $images;
    }

    function getMainImage($type, $id, $item = null) {
        global $house_galleries, $food_galleries, $tour_galleries;

        if ($type === 'house' && !empty($house_galleries[$id])) return $house_galleries[$id][0];
        if ($type === 'food'  && !empty($food_galleries[$id]))  return $food_galleries[$id][0];
        if ($type === 'tour'  && !empty($tour_galleries[$id]))  return $tour_galleries[$id][0];

        if ($item && is_array($item) && !empty($item['image'])) {
            $folder = 'uploads/' . $type . 's/';
            $gfolder = 'uploads/' . $type . 's/gallery/';
            $defaults = ['house' => 'default-house.jpg', 'food' => 'default-food.jpg', 'tour' => 'default-tour.jpg'];
            if (!isset($defaults[$type]) || $item['image'] !== $defaults[$type]) {
                if (file_exists($folder . $item['image'])) return $folder . $item['image'];
                if (file_exists($gfolder . $item['image'])) return $gfolder . $item['image'];
            }
        }
        return null;
    }
}

$house_galleries = [];
foreach ($houses as $h) {
    if (is_array($h) && isset($h['id'])) {
        $house_galleries[$h['id']] = buildItemGallery('house', $h);
    }
}
$food_galleries = [];
foreach ($food_items as $fi) {
    if (is_array($fi) && isset($fi['id'])) {
        $food_galleries[$fi['id']] = buildItemGallery('food', $fi);
    }
}
$tour_galleries = [];
foreach ($tours as $t) {
    if (is_array($t) && isset($t['id'])) {
        $tour_galleries[$t['id']] = buildItemGallery('tour', $t);
    }
}

// ============================================================
// ✅ FIXED: FETCH BOOKED DATES — only confirmed/approved/completed block
//    Pending bookings do NOT block dates (do NOT turn red)
// ============================================================

// --- HOUSE BOOKED DATES ---
$house_booked_dates = [];
foreach ($houses as $h) {
    if (!is_array($h) || !isset($h['id'])) continue;
    try {
        $stmt = $pdo->prepare("
            SELECT check_in_date, check_out_date 
            FROM house_bookings 
            WHERE house_id = ? 
              AND booking_status IN ({$blocking_placeholders})
        ");
        $stmt->execute([$h['id']]);
        $rows = $stmt->fetchAll();
        $dates = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $start = new DateTime($row['check_in_date']);
                $end = new DateTime($row['check_out_date']);
                $end->modify('+1 day');
                $period = new DatePeriod($start, new DateInterval('P1D'), $end);
                foreach ($period as $date) $dates[] = $date->format('Y-m-d');
            }
        }
        $house_booked_dates[$h['id']] = array_values(array_unique($dates));
    } catch (PDOException $e) {
        $house_booked_dates[$h['id']] = [];
    }
}

// --- TOUR BOOKED DATES --- ✅ FIXED: Now uses $blocking_placeholders
$tour_booked_dates = [];
foreach ($tours as $t) {
    if (!is_array($t) || !isset($t['id'])) continue;
    try {
        $stmt = $pdo->prepare("SELECT booking_date FROM tour_bookings WHERE tour_id = ? AND booking_status IN ({$blocking_placeholders})");
        $stmt->execute([$t['id']]);
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $tour_booked_dates[$t['id']] = is_array($result) ? $result : [];
    } catch (PDOException $e) {
        $tour_booked_dates[$t['id']] = [];
    }
}

// --- FOOD BOOKED DATES --- ✅ FIXED: Now uses $blocking_placeholders
// Previously used "booking_status != 'cancelled'" which incorrectly blocked
// dates for pending bookings. Now only confirmed/approved/completed block.
$food_booked_dates = [];
foreach ($food_items as $fi) {
    if (!is_array($fi) || !isset($fi['id'])) continue;
    try {
        $stmt = $pdo->prepare("SELECT preferred_date FROM food_bookings WHERE food_id = ? AND booking_status IN ({$blocking_placeholders})");
        $stmt->execute([$fi['id']]);
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $food_booked_dates[$fi['id']] = is_array($result) ? $result : [];
    } catch (PDOException $e) {
        $food_booked_dates[$fi['id']] = [];
    }
}

// ============================================================
// BUILD FULL ITEM DETAIL
// ============================================================
function parseListHelper($str) {
    if (empty($str)) return [];
    if (strpos($str, "\n") !== false) {
        return array_values(array_filter(array_map('trim', explode("\n", $str))));
    }
    return array_values(array_filter(array_map('trim', explode(",", $str))));
}

$items_detail = [];

foreach ($houses as $h) {
    if (!is_array($h) || !isset($h['id'])) continue;
    $items_detail['house_' . $h['id']] = [
        'type' => 'house',
        'id' => (int)$h['id'],
        'name' => $h['house_name'] ?? '',
        'description' => $h['description'] ?? '',
        'price' => (float)($h['price_per_night'] ?? 0),
        'price_unit' => 'per night',
        'capacity' => (int)($h['capacity'] ?? 0),
        'bedrooms' => (int)($h['bedrooms'] ?? 0),
        'amenities' => parseListHelper($h['amenities'] ?? ''),
        'gallery' => $house_galleries[$h['id']] ?? [],
        'booked_dates' => $house_booked_dates[$h['id']] ?? []
    ];
}

foreach ($food_items as $fi) {
    if (!is_array($fi) || !isset($fi['id'])) continue;
    $variations = !empty($fi['size_variations']) ? json_decode($fi['size_variations'], true) : [];
    $has_var = !empty($variations) && is_array($variations);

    $items_detail['food_' . $fi['id']] = [
        'type' => 'food',
        'id' => (int)$fi['id'],
        'name' => $fi['name'] ?? '',
        'description' => $fi['description'] ?? '',
        'price' => (float)($fi['price'] ?? 0),
        'price_unit' => 'per order',
        'category' => $fi['category'] ?? '',
        'pax_range' => $fi['pax_range'] ?? '',
        'amenities' => [],
        'variations' => $has_var ? $variations : [],
        'gallery' => $food_galleries[$fi['id']] ?? []
    ];
}

foreach ($tours as $t) {
    if (!is_array($t) || !isset($t['id'])) continue;
    $items_detail['tour_' . $t['id']] = [
        'type' => 'tour',
        'id' => (int)$t['id'],
        'name' => $t['tour_name'] ?? '',
        'description' => $t['description'] ?? '',
        'price' => (float)($t['price_per_boat'] ?? 0),
        'price_unit' => 'per boat',
        'capacity' => (int)($t['max_guests'] ?? 0),
        'destinations' => '12–14 islands',
        'amenities' => parseListHelper($t['inclusions'] ?? $t['amenities'] ?? ''),
        'gallery' => $tour_galleries[$t['id']] ?? []
    ];
}

// ============================================================
// Pre-compute main images for cart display
// ============================================================
$cart_house_img = null;
$cart_food_img  = null;
$cart_tour_img  = null;

if ($has_house && isset($cart['house']['id'])) {
    $hid = (int)$cart['house']['id'];
    $found_house = null;
    foreach ($houses as $hh) { if (isset($hh['id']) && (int)$hh['id'] === $hid) { $found_house = $hh; break; } }
    $cart_house_img = getMainImage('house', $hid, $found_house);
}
if ($has_food && isset($cart['food']['id'])) {
    $fid = (int)$cart['food']['id'];
    $found_food = null;
    foreach ($food_items as $ff) { if (isset($ff['id']) && (int)$ff['id'] === $fid) { $found_food = $ff; break; } }
    $cart_food_img = getMainImage('food', $fid, $found_food);
}
if ($has_tour && isset($cart['tour']['id'])) {
    $tid = (int)$cart['tour']['id'];
    $found_tour = null;
    foreach ($tours as $tt) { if (isset($tt['id']) && (int)$tt['id'] === $tid) { $found_tour = $tt; break; } }
    $cart_tour_img = getMainImage('tour', $tid, $found_tour);
}

// ============================================================
// ✅ UPDATED: Build data for JS edit-persistence (with times)
// ============================================================
$cart_data_js = [
    'house' => $has_house ? [
        'id' => (int)($cart['house']['id'] ?? 0),
        'check_in' => $cart['house']['check_in'] ?? '',
        'check_in_time' => $cart['house']['check_in_time'] ?? '14:00',   // ✅ NEW
        'check_out' => $cart['house']['check_out'] ?? '',
        'check_out_time' => $cart['house']['check_out_time'] ?? '12:00', // ✅ NEW
        'guests' => (int)($cart['house']['guests'] ?? 0),
        'guest_names' => array_values(array_filter(array_map('trim', explode("\n", (string)($cart['house']['guest_names'] ?? '')))))
    ] : null,
    'food' => $has_food ? [
        'id' => (int)($cart['food']['id'] ?? 0),
        'preferred_date' => $cart['food']['preferred_date'] ?? '',
        'preferred_time' => $cart['food']['preferred_time'] ?? '',
        'fulfillment_method' => $cart['food']['fulfillment_method'] ?? 'pickup',
        'delivery_address' => $cart['food']['delivery_address'] ?? '',
        'contact_number' => preg_replace('/^\+63/', '', (string)($cart['food']['contact_number'] ?? '')),
        'special_requests' => $cart['food']['special_requests'] ?? '',
        'size_variant_index' => $cart['food']['size_variant_index'] ?? null,
        'size_variant' => $cart['food']['size_variant'] ?? ''
    ] : null,
    'tour' => $has_tour ? [
        'id' => (int)($cart['tour']['id'] ?? 0),
        'booking_date' => $cart['tour']['booking_date'] ?? '',
        'preferred_time' => $cart['tour']['preferred_time'] ?? '',
        'number_of_guests' => (int)($cart['tour']['number_of_guests'] ?? 0),
        'guest_name' => $cart['tour']['guest_name'] ?? '',
        'contact_number' => preg_replace('/^\+63/', '', (string)($cart['tour']['contact_number'] ?? '')),
        'special_requests' => $cart['tour']['special_requests'] ?? ''
    ] : null
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#0B2447">
    <title>Build Your Package - <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/design-system.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f7fb; min-height: 100vh; overflow-x: hidden; }

        /* ✅ NEW: time-helper text */
        .time-helper { display: block; color: #94a3b8; font-size: 11px; margin-top: 4px; font-weight: 400; }
        .time-helper i { color: #4DA6D9; }

        .header { background: #0B2447; box-shadow: 0 4px 20px rgba(0,0,0,0.3); padding: 12px 0; position: sticky; top: 0; z-index: 100; border-bottom: 2px solid rgba(77, 166, 217, 0.2); }
        .header-content { max-width: 1300px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; gap: 15px; }
        .logo-wrapper { display: flex; align-items: center; gap: 15px; text-decoration: none; flex-shrink: 0; min-width: 0; }
        .logo-wrapper .logo-image { height: 50px; width: 50px; border-radius: 12px; object-fit: cover; border: 2px solid #4DA6D9; padding: 2px; background: white; flex-shrink: 0; transition: transform 0.3s; }
        .logo-wrapper .logo-image:hover { transform: scale(1.05); }
        .logo-wrapper .logo-image-placeholder { height: 50px; width: 50px; border-radius: 12px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; flex-shrink: 0; }
        .brand-text { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; }
        .brand-text .brand-name { font-size: 20px; font-weight: 700; color: white; letter-spacing: -0.5px; white-space: nowrap; }
        .brand-text .brand-tagline { font-size: 11px; color: #7bb8f0; font-weight: 500; white-space: nowrap; }

        .desktop-nav { display: flex; gap: 4px; align-items: center; flex-wrap: nowrap; flex-shrink: 1; min-width: 0; max-width: 100%; }
        .desktop-nav a { padding: 8px 12px; border-radius: 8px; color: #b3d9ff; text-decoration: none; font-weight: 500; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; white-space: nowrap; flex-shrink: 0; line-height: 1.2; }
        .desktop-nav a i { font-size: 13px; }
        .desktop-nav a:hover { background: rgba(77, 166, 217, 0.2); color: white; }
        .desktop-nav a.active-nav { background: rgba(77, 166, 217, 0.25); color: white; }
        .desktop-nav .btn-package { background: #F4B400; color: #0B2447; border-radius: 8px; padding: 8px 14px; font-weight: 700; }
        .desktop-nav .btn-package:hover { background: #e6a800; color: #0B2447; }
        .desktop-nav .btn-logout { background: #ef4444; color: white; border-radius: 8px; padding: 8px 14px; }
        .desktop-nav .btn-logout:hover { background: #dc2626; }
        .desktop-nav .btn-rate { background: #F4B400; color: #0B2447; border-radius: 8px; padding: 8px 14px; }
        .desktop-nav .btn-rate:hover { background: #e6a800; color: #0B2447; }

        .menu-toggle { display: none; position: fixed; top: 12px; left: 12px; z-index: 1001; background: #0B2447; color: white; border: none; border-radius: 12px; width: 48px; height: 48px; font-size: 22px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.3); align-items: center; justify-content: center; border: 1px solid rgba(77, 166, 217, 0.2); }
        .menu-toggle:hover { background: rgba(77, 166, 217, 0.2); transform: scale(1.05); }
        .menu-toggle.active .fa-bars { transform: rotate(90deg); }
        body.sidebar-open-mobile .menu-toggle { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.8); }

        .sidebar { position: fixed; top: 0; left: -320px; width: 300px; height: 100vh; background: #0B2447; box-shadow: 4px 0 30px rgba(0,0,0,0.3); padding: 25px 0; transition: left 0.3s ease; z-index: 1000; overflow-y: auto; border-right: 2px solid rgba(77, 166, 217, 0.15); }
        .sidebar.open { left: 0; }
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(77, 166, 217, 0.3); border-radius: 10px; }

        .sidebar-header { padding: 0 20px 25px; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 20px; }
        .sidebar-header-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .sidebar-header .logo { font-size: 22px; font-weight: 700; color: white; text-decoration: none; display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
        .sidebar-header .logo .logo-icon { width: 48px; height: 48px; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; flex-shrink: 0; overflow: hidden; }
        .sidebar-header .logo img { width: 48px; height: 48px; border-radius: 14px; object-fit: cover; border: 2px solid #4DA6D9; padding: 2px; background: white; flex-shrink: 0; }
        .sidebar-header .logo .logo-text { display: flex; flex-direction: column; min-width: 0; }
        .sidebar-header .logo .logo-text .main { font-size: 18px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-header .logo .logo-text .sub { font-size: 10px; color: #7bb8f0; }
        .sidebar-close-btn { display: none; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); color: #e0eeff; width: 36px; height: 36px; border-radius: 10px; font-size: 16px; cursor: pointer; flex-shrink: 0; align-items: center; justify-content: center; transition: all 0.2s; }
        .sidebar-close-btn:hover { background: #ef4444; border-color: #ef4444; color: white; transform: rotate(90deg); }

        .nav-menu { list-style: none; padding: 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 14px; padding: 12px 20px; color: #b3d9ff; text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-weight: 500; font-size: 14px; }
        .nav-link i { width: 22px; font-size: 16px; text-align: center; flex-shrink: 0; }
        .nav-link:hover { background: rgba(77, 166, 217, 0.15); color: white; border-left-color: #4DA6D9; }
        .nav-link.active-nav { background: rgba(77, 166, 217, 0.2); color: white; border-left-color: #4DA6D9; }
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

        .hero { padding: 120px 20px 70px; color: white; text-align: center; position: relative; <?php if($hero_exists): ?> background: linear-gradient(rgba(11, 36, 71, 0.5), rgba(11, 36, 71, 0.6)), url('<?php echo htmlspecialchars($hero_path); ?>?<?php echo time(); ?>'); background-size: cover; background-position: center; <?php else: ?> background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); <?php endif; ?> }
        .hero-content { max-width: 800px; margin: 0 auto; position: relative; z-index: 1; }
        .hero h1 { font-size: 42px; font-weight: 700; margin-bottom: 15px; text-shadow: 0 2px 25px rgba(0,0,0,0.25); line-height: 1.2; }
        .hero h1 i { color: #F4B400; }
        .hero p { font-size: 17px; opacity: 0.95; text-shadow: 0 1px 15px rgba(0,0,0,0.15); }

        @media (max-width: 768px) { .hero { padding: 100px 16px 50px; } .hero h1 { font-size: 30px; margin-bottom: 10px; } .hero p { font-size: 15px; } }
        @media (max-width: 480px) { .hero { padding: 95px 14px 45px; } .hero h1 { font-size: 24px; } .hero h1 i { display: block; margin-bottom: 8px; } .hero p { font-size: 13.5px; } }

        .main-container { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        @media (max-width: 768px) { .main-container { padding: 0 15px; margin: 20px auto; } }
        @media (max-width: 480px) { .main-container { padding: 0 12px; margin: 18px auto; } }

        .step-1 .choice-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 25px; margin-top: 30px; }
        .choice-card { background: white; border-radius: 20px; padding: 35px 25px; text-align: center; text-decoration: none; color: #0B2447; border: 3px solid #e8f0fe; transition: all 0.3s; display: flex; flex-direction: column; align-items: center; box-shadow: 0 10px 30px rgba(0,0,0,0.05); cursor: pointer; font-family: inherit; }
        .choice-card:hover { transform: translateY(-8px); border-color: #4DA6D9; box-shadow: 0 20px 50px rgba(77, 166, 217, 0.25); color: #0B2447; }
        .choice-card .choice-icon { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; font-size: 34px; color: white; margin-bottom: 20px; }
        .choice-card h3 { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
        .choice-card p { font-size: 14px; color: #64748b; margin-bottom: 20px; }
        .choice-card .btn-select { padding: 10px 24px; background: #F4B400; color: #0B2447; border-radius: 10px; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; }

        @media (max-width: 640px) {
            .step-1 .choice-grid { grid-template-columns: 1fr; gap: 15px; margin-top: 20px; }
            .choice-card { padding: 28px 20px; }
            .choice-card .choice-icon { width: 65px; height: 65px; font-size: 28px; margin-bottom: 15px; }
            .choice-card h3 { font-size: 19px; }
            .choice-card p { font-size: 13px; margin-bottom: 15px; }
        }

        .package-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 12px; }
        .package-header h2 { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0; }
        .btn-clear-all { padding: 8px 16px; background: #fee2e2; color: #ef4444; border: none; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
        .btn-clear-all:hover { background: #fecaca; color: #dc2626; }

        @media (max-width: 640px) {
            .package-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .package-header h2 { font-size: 20px; text-align: center; }
            .btn-clear-all { justify-content: center; width: 100%; padding: 10px 16px; }
        }

        .cart-item { background: white; border-radius: 16px; padding: 22px; margin-bottom: 18px; border: 2px solid #e8f0fe; display: flex; gap: 20px; align-items: flex-start; transition: all 0.3s; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
        .cart-item:hover { border-color: #4DA6D9; box-shadow: 0 8px 25px rgba(77,166,217,0.15); }
        .cart-item .item-thumb { width: 110px; height: 110px; border-radius: 14px; flex-shrink: 0; overflow: hidden; position: relative; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .cart-item .item-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .cart-item .item-thumb .thumb-fallback { color: rgba(255,255,255,0.9); font-size: 36px; display: flex; align-items: center; justify-content: center; }
        .cart-item .item-thumb.house { background: linear-gradient(135deg, #4DA6D9, #3a8bbf); }
        .cart-item .item-thumb.food  { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .cart-item .item-thumb.tour  { background: linear-gradient(135deg, #10b981, #059669); }
        .cart-item .item-body { flex: 1; min-width: 0; }
        .cart-item .item-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
        .cart-item .item-title { font-size: 18px; font-weight: 700; color: #0B2447; margin: 0; }
        .cart-item .item-type-label { font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; background: #eef2ff; color: #4DA6D9; }
        .cart-item .item-detail { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: #475569; margin-bottom: 5px; }
        .cart-item .item-detail i { width: 16px; color: #4DA6D9; padding-top: 2px; flex-shrink: 0; }
        .cart-item .item-detail strong { color: #0B2447; }
        .cart-item .item-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }
        .cart-item .btn-view-item { padding: 8px 14px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; }
        .cart-item .btn-view-item:hover { background: #bae6fd; color: #075985; }
        .cart-item .btn-edit-item { padding: 8px 14px; background: #F4B400; color: #0B2447; border: none; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; }
        .cart-item .btn-edit-item:hover { background: #e6a800; color: #0B2447; }
        .cart-item .btn-remove-item { padding: 8px 12px; background: #fee2e2; color: #ef4444; border: none; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; }
        .cart-item .btn-remove-item:hover { background: #fecaca; color: #dc2626; }
        .cart-item .item-price { font-size: 20px; font-weight: 700; color: #10b981; margin-top: 10px; display: block; }

        @media (max-width: 640px) {
            .cart-item { flex-direction: column; gap: 15px; padding: 18px; }
            .cart-item .item-thumb { width: 100%; height: 200px; }
            .cart-item .item-header { flex-direction: column; align-items: stretch; }
            .cart-item .item-actions { width: 100%; justify-content: stretch; }
            .cart-item .item-actions > * { flex: 1; justify-content: center; }
            .cart-item .item-title { font-size: 16px; }
            .cart-item .item-price { font-size: 18px; }
        }

        .add-more-section { background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 25px; margin-bottom: 25px; text-align: center; }
        .add-more-section h3 { font-size: 15px; font-weight: 700; color: #475569; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 0.5px; }
        .add-more-section .add-buttons { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .btn-add-more { padding: 12px 24px; background: white; color: #0B2447; border: 2px solid #4DA6D9; border-radius: 10px; font-weight: 700; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; cursor: pointer; font-family: inherit; }
        .btn-add-more:hover { background: #4DA6D9; color: white; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(77,166,217,0.3); }

        @media (max-width: 640px) { .add-more-section { padding: 18px; } .btn-add-more { width: 100%; justify-content: center; } }

        .summary-box { background: linear-gradient(135deg, #0B2447, #4DA6D9); color: white; border-radius: 20px; padding: 28px; box-shadow: 0 15px 40px rgba(11,36,71,0.25); }
        .summary-box .summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.15); gap: 10px; }
        .summary-box .summary-row:last-of-type { border-bottom: none; }
        .summary-box .summary-row span:first-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .summary-box .summary-total { display: flex; justify-content: space-between; padding-top: 15px; margin-top: 10px; border-top: 2px solid rgba(255,255,255,0.3); font-size: 26px; font-weight: 700; }
        .summary-box .summary-total .total-value { color: #F4B400; }

        .btn-confirm { width: 100%; padding: 16px; background: #F4B400; color: #0B2447; border: none; border-radius: 12px; font-weight: 700; font-size: 16px; cursor: pointer; margin-top: 20px; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 6px 20px rgba(244,180,0,0.3); font-family: inherit; }
        .btn-confirm:hover { background: #e6a800; transform: translateY(-2px); box-shadow: 0 10px 30px rgba(244,180,0,0.45); }
        .terms-note { text-align: center; font-size: 12px; color: #94a3b8; margin-top: 15px; padding: 0 10px; line-height: 1.5; }

        @media (max-width: 640px) {
            .summary-box { padding: 22px 20px; border-radius: 16px; }
            .summary-box .summary-total { font-size: 22px; }
            .summary-box .summary-row { font-size: 13px; }
            .btn-confirm { padding: 14px; font-size: 15px; }
        }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; animation: modalFadeIn 0.2s ease; }
        .modal.show { display: flex; }
        @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal-content { background: white; border-radius: 24px; width: 100%; max-width: 550px; max-height: 92vh; overflow-y: auto; padding: 30px; animation: modalSlideIn 0.3s ease; box-shadow: 0 30px 80px rgba(0,0,0,0.4); -webkit-overflow-scrolling: touch; }
        .modal-content.modal-lg { max-width: 800px; }
        @keyframes modalSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 2px solid #e8f0fe; margin-bottom: 20px; gap: 10px; }
        .modal-header h3 { font-size: 20px; font-weight: 700; color: #0B2447; margin: 0; display: flex; align-items: center; gap: 10px; min-width: 0; }
        .modal-header h3 i { color: #4DA6D9; flex-shrink: 0; }
        .modal-header .close { font-size: 28px; cursor: pointer; color: #94a3b8; background: none; border: none; padding: 0 10px; line-height: 1; transition: color 0.3s; flex-shrink: 0; font-family: inherit; }
        .modal-header .close:hover { color: #ef4444; }

        @media (max-width: 600px) {
            .modal { padding: 12px; }
            .modal-content { padding: 20px 16px; border-radius: 20px; max-height: 94vh; }
            .modal-header h3 { font-size: 16px; }
            .modal-header .close { font-size: 24px; padding: 0 6px; }
        }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1e293b; font-size: 13px; }
        .form-group label i { color: #4DA6D9; margin-right: 4px; }
        .form-control, .form-select { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: border-color 0.3s; background: #fafafa; font-family: inherit; color: #0B2447; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #4DA6D9; background: white; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }
        .form-control[readonly] { background: #f1f5f9; font-weight: 600; color: #0B2447; }
        .form-control.price-display { color: #10b981; font-weight: 700; font-size: 15px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; gap: 12px; } }

        .phone-input-group { display: flex; gap: 8px; align-items: stretch; }
        .phone-suffix-select { min-width: 100px; max-width: 115px; padding: 11px 8px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 13px; font-weight: 600; background: #f8fafc; color: #0B2447; cursor: not-allowed; opacity: 0.9; flex-shrink: 0; font-family: inherit; }
        .phone-input-wrapper { position: relative; flex: 1; min-width: 0; }
        .phone-input-wrapper .form-control { padding-left: 38px; padding-right: 58px; font-family: 'Courier New', monospace; font-weight: 600; letter-spacing: 0.5px; }
        .phone-input-wrapper .phone-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; pointer-events: none; }
        .phone-input-wrapper .digit-count { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 10.5px; color: #94a3b8; background: #f1f5f9; padding: 2px 7px; border-radius: 10px; font-weight: 700; pointer-events: none; }
        .phone-input-wrapper .digit-count.complete { color: #10b981; background: #d1fae5; }
        @media (max-width: 400px) { .phone-suffix-select { min-width: 80px; font-size: 12px; padding: 10px 6px; } }

        .helper-text { display: flex; align-items: center; gap: 5px; font-size: 11.5px; color: #94a3b8; margin-top: 5px; line-height: 1.4; }
        .helper-text i { color: #4DA6D9; flex-shrink: 0; }

        .fulfillment-method-group { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .fulfillment-option { position: relative; cursor: pointer; display: block; margin: 0; }
        .fulfillment-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .fulfillment-option-label { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; padding: 16px 12px; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; text-align: center; transition: all 0.2s; cursor: pointer; min-height: 92px; }
        .fulfillment-option-label i { font-size: 22px; color: #94a3b8; transition: color 0.2s; }
        .fulfillment-option-label strong { font-size: 13px; color: #1e293b; font-weight: 700; }
        .fulfillment-option-label small { font-size: 10.5px; color: #94a3b8; line-height: 1.25; }
        .fulfillment-option input[type="radio"]:checked + .fulfillment-option-label { background: #e0f0fa; border-color: #4DA6D9; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.15); }
        .fulfillment-option input[type="radio"]:checked + .fulfillment-option-label i { color: #4DA6D9; }
        .fulfillment-option:hover .fulfillment-option-label { border-color: #4DA6D9; background: #f0f7fb; }

        @media (max-width: 400px) {
            .fulfillment-method-group { gap: 8px; }
            .fulfillment-option-label { padding: 12px 8px; min-height: 84px; }
            .fulfillment-option-label i { font-size: 18px; }
            .fulfillment-option-label strong { font-size: 12px; }
            .fulfillment-option-label small { font-size: 9.5px; }
        }

        .guest-names-field { background: #f8fafc; border-radius: 12px; padding: 15px; margin: 10px 0; border: 1px solid #e2e8f0; }
        .guest-names-field .guest-names-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px; }
        .guest-names-field .guest-names-header label { font-weight: 600; color: #1e293b; font-size: 13px; margin: 0; }
        .guest-names-field .guest-count-badge { background: #e8f0fe; color: #4DA6D9; padding: 2px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .guest-names-field .guest-count-badge.match { background: #d1fae5; color: #065f46; }
        .guest-names-field .guest-count-badge.over { background: #fee2e2; color: #991b1b; }
        .guest-names-field .guest-count-badge.under { background: #fef3c7; color: #92400e; }
        .guest-names-field .guest-name-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .guest-names-field .guest-name-row .guest-badge { min-width: 28px; height: 28px; background: #4DA6D9; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
        .guest-names-field .guest-name-row .guest-name-input { flex: 1; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; font-family: inherit; background: white; transition: all 0.2s; min-width: 0; }
        .guest-names-field .guest-name-row .guest-name-input:focus { outline: none; border-color: #4DA6D9; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.1); }
        .guest-names-field .guest-name-row .guest-name-input.invalid { border-color: #ef4444; background: #fef2f2; }
        .guest-names-field .guest-name-row .guest-name-input.valid { border-color: #10b981; }
        .guest-names-field .help-text { font-size: 11px; color: #94a3b8; margin-top: 5px; display: flex; align-items: center; gap: 4px; line-height: 1.4; }
        .guest-names-field .help-text.error { color: #ef4444; font-weight: 600; }
        .guest-names-field .help-text.ok { color: #10b981; font-weight: 600; }
        @media (max-width: 480px) { .guest-names-field { padding: 12px; } }

        .calendar-container { background: white; border-radius: 16px; padding: 20px; margin: 15px 0; border: 1px solid #e8f0fe; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; gap: 8px; }
        .calendar-header h4 { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0; }
        .calendar-nav { display: flex; gap: 8px; }
        .calendar-nav button { background: #f1f5f9; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; color: #475569; font-family: inherit; }
        .calendar-nav button:hover { background: #4DA6D9; color: white; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-grid .day-name { text-align: center; font-size: 11px; font-weight: 600; color: #94a3b8; padding: 5px 0; text-transform: uppercase; }
        .calendar-grid .day { text-align: center; padding: 8px 0; border-radius: 8px; font-size: 14px; cursor: pointer; transition: all 0.2s; position: relative; color: #334155; }
        .calendar-grid .day:hover:not(.disabled):not(.booked):not(.past) { background: #eef2ff; transform: scale(1.05); }
        .calendar-grid .day.selected { background: #4DA6D9; color: white; border-radius: 8px; font-weight: 700; }
        .calendar-grid .day.in-range { background: #dbeafe; color: #0B2447; }
        .calendar-grid .day.booked { background: #fee2e2 !important; color: #dc2626 !important; cursor: not-allowed !important; text-decoration: line-through !important; }
        .calendar-grid .day.past { color: #cbd5e1 !important; cursor: not-allowed !important; }
        .calendar-grid .day.disabled { cursor: not-allowed; opacity: 0.5; }
        .calendar-legend { display: flex; gap: 20px; margin-top: 12px; justify-content: center; font-size: 12px; color: #64748b; flex-wrap: wrap; }
        .calendar-legend .dot { width: 14px; height: 14px; border-radius: 4px; display: inline-block; margin-right: 4px; vertical-align: middle; }
        .calendar-legend .dot.available { background: #d1fae5; border: 1px solid #10b981; }
        .calendar-legend .dot.booked { background: #fee2e2; border: 1px solid #dc2626; }
        .calendar-legend .dot.selected { background: #4DA6D9; border: 1px solid #4DA6D9; }
        .calendar-info { text-align: center; margin-top: 10px; font-size: 13px; color: #64748b; padding: 8px; background: #f8fafc; border-radius: 8px; line-height: 1.5; }
        .calendar-info.success { color: #10b981; font-weight: 600; background: #f0fdf4; }

        @media (max-width: 480px) {
            .calendar-container { padding: 14px; }
            .calendar-header h4 { font-size: 14px; }
            .calendar-nav button { padding: 5px 9px; font-size: 12px; }
            .calendar-grid { gap: 3px; }
            .calendar-grid .day-name { font-size: 10px; padding: 4px 0; }
            .calendar-grid .day { padding: 7px 0; font-size: 13px; }
            .calendar-legend { gap: 12px; font-size: 11px; }
            .calendar-info { font-size: 12px; padding: 6px; }
        }

        .order-summary-box { background: linear-gradient(135deg, #f0f7fb 0%, #e8f4fc 100%); padding: 16px 20px; border-radius: 14px; margin: 18px 0; border: 2px dashed #4DA6D9; }
        .order-summary-box .summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 15px; color: #1e293b; gap: 10px; }
        .order-summary-box .summary-row .total-amount { font-size: 22px; font-weight: 800; color: #10b981; }

        @media (max-width: 480px) {
            .order-summary-box { padding: 12px 14px; }
            .order-summary-box .summary-row { font-size: 14px; }
            .order-summary-box .summary-row .total-amount { font-size: 20px; }
        }

        .warning-banner { background: #fef3c7; padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #92400e; display: flex; align-items: flex-start; gap: 8px; line-height: 1.5; }
        .warning-banner i { color: #f59e0b; margin-top: 2px; flex-shrink: 0; }

        .stay-summary-box { background: #f8fafc; padding: 20px; border-radius: 16px; margin: 20px 0; }
        .stay-summary-box .stay-row { display: flex; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; font-size: 14px; gap: 10px; }
        .stay-summary-box .stay-row.total-row { border-bottom: none; padding-top: 5px; font-size: 18px; }
        .stay-summary-box .stay-row.total-row strong { color: #10b981; }

        .tour-summary-box { background: #f0f9ff; border: 1px solid #bae6fd; padding: 18px 22px; border-radius: 14px; margin: 20px 0; }
        .tour-summary-box .tour-summary-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 15px; color: #0f172a; gap: 10px; }
        .tour-summary-box .tour-summary-row:first-child { border-bottom: 1px solid #e0f2fe; padding-bottom: 10px; margin-bottom: 6px; }
        .tour-summary-box .tour-summary-row .tour-total-value { color: #10b981; font-weight: 800; font-size: 20px; }
        .tour-warning { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; color: #92400e; margin: 16px 0 0; display: flex; gap: 8px; line-height: 1.5; }
        .tour-warning i { color: #f59e0b; flex-shrink: 0; margin-top: 2px; }

        .btn-primary-submit { width: 100%; padding: 14px; background: #F4B400; color: #0B2447; border: none; border-radius: 12px; font-weight: 700; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.35); font-family: inherit; margin-top: 20px; }
        .btn-primary-submit:hover { background: #e6a800; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244, 180, 0, 0.5); }
        .btn-submit-modal { width: 100%; padding: 14px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; margin-top: 20px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; box-shadow: 0 4px 15px rgba(244,180,0,0.3); font-family: inherit; }
        .btn-submit-modal:hover { background: #e6a800; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(244,180,0,0.45); }

        .selected-item-badge { display: flex; align-items: center; gap: 10px; background: linear-gradient(135deg, #e0f0fa, #d1eaf5); border-left: 4px solid #4DA6D9; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 700; color: #0B2447; font-size: 14px; flex-wrap: wrap; }
        .selected-item-badge i { color: #4DA6D9; }
        .selected-item-badge span { margin-left: auto; color: #10b981; font-size: 16px; font-weight: 700; }

        .btn-back-list { background: none; border: none; color: #4DA6D9; font-weight: 600; cursor: pointer; margin-bottom: 15px; font-size: 13px; padding: 0; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
        .btn-back-list:hover { color: #3a8bbf; text-decoration: underline; }

        .item-pick-list { display: flex; flex-direction: column; gap: 10px; max-height: 55vh; overflow-y: auto; padding-right: 5px; -webkit-overflow-scrolling: touch; }
        .item-pick-list::-webkit-scrollbar { width: 6px; }
        .item-pick-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .item-pick { display: flex; align-items: stretch; gap: 14px; padding: 12px; background: #ffffff; border: 2px solid #e8f0fe; border-radius: 14px; cursor: pointer; transition: all 0.2s ease; text-align: left; position: relative; overflow: hidden; }
        .item-pick:hover { border-color: #4DA6D9; background: #f0f7fb; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(77,166,217,0.18); }
        .item-pick-thumb { width: 90px; height: 90px; border-radius: 10px; flex-shrink: 0; overflow: hidden; background: linear-gradient(135deg, #4DA6D9, #7bb8f0); display: flex; align-items: center; justify-content: center; position: relative; }
        .item-pick-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .item-pick-thumb .thumb-icon { color: rgba(255,255,255,0.85); font-size: 32px; }
        .item-pick-thumb.food { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .item-pick-thumb.tour { background: linear-gradient(135deg, #10b981, #059669); }
        .item-pick-info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 4px; padding: 2px 0; }
        .item-pick-info strong { color: #0B2447; font-size: 15px; font-weight: 700; line-height: 1.25; word-break: break-word; display: block; }
        .item-pick-info .ip-price { color: #10b981; font-size: 14px; font-weight: 700; }
        .item-pick-info .ip-price small { color: #94a3b8; font-size: 11px; font-weight: 500; margin-left: 2px; }
        .item-pick-info .ip-desc { color: #64748b; font-size: 12px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-top: 1px; }
        .item-pick-info .ip-meta { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 3px; }
        .item-pick-info .ip-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 20px; background: #eef2ff; color: #4DA6D9; white-space: nowrap; }
        .item-pick-info .ip-chip.gold { background: #fef3c7; color: #b45309; }
        .item-pick-info .ip-chip.green { background: #d1fae5; color: #065f46; }
        .item-pick-info .ip-chip.gray { background: #f1f5f9; color: #475569; }
        .item-pick-actions { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; flex-shrink: 0; }
        .item-pick .btn-item-view { padding: 6px 12px; border-radius: 8px; font-weight: 700; font-size: 11px; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s; cursor: pointer; border: 1px solid transparent; font-family: inherit; }
        .item-pick .btn-item-view.house { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .item-pick .btn-item-view.house:hover { background: #bae6fd; color: #075985; }
        .item-pick .btn-item-view.food { background: #fef3c7; color: #b45309; border-color: #fcd34d; }
        .item-pick .btn-item-view.food:hover { background: #fde68a; color: #92400e; }
        .item-pick .btn-item-view.tour { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .item-pick .btn-item-view.tour:hover { background: #a7f3d0; color: #064e3b; }
        .item-pick .ip-arrow { color: #cbd5e1; font-size: 14px; transition: all 0.2s; }
        .item-pick:hover .ip-arrow { color: #4DA6D9; transform: translateX(3px); }

        @media (max-width: 480px) {
            .item-pick { gap: 10px; padding: 10px; }
            .item-pick-thumb { width: 70px; height: 70px; }
            .item-pick-thumb .thumb-icon { font-size: 24px; }
            .item-pick-info strong { font-size: 13.5px; }
            .item-pick-info .ip-price { font-size: 13px; }
            .item-pick-info .ip-desc { font-size: 11px; -webkit-line-clamp: 1; }
            .item-pick .btn-item-view { font-size: 10px; padding: 5px 10px; }
            .item-pick .ip-arrow { display: none; }
        }

        .house-detail-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 100001; align-items: center; justify-content: center; padding: 20px; animation: modalFadeIn 0.2s ease; }
        .house-detail-modal.show { display: flex; }
        .house-detail-content { background: white; border-radius: 20px; width: 100%; max-width: 900px; max-height: 94vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 30px 90px rgba(0,0,0,0.5); animation: modalSlideIn 0.3s ease; position: relative; }
        .house-detail-close { position: absolute; top: 15px; right: 15px; z-index: 10; width: 42px; height: 42px; border-radius: 50%; background: rgba(0,0,0,0.55); color: white; border: none; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.25s; font-family: inherit; }
        .house-detail-close:hover { background: #ef4444; transform: rotate(90deg); }
        .house-detail-scroll { overflow-y: auto; flex: 1; min-height: 0; -webkit-overflow-scrolling: touch; }
        .house-detail-scroll::-webkit-scrollbar { width: 6px; }
        .house-detail-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .hd-gallery { position: relative; width: 100%; height: 420px; background: #0B2447; flex-shrink: 0; }
        .hd-gallery img.hd-main-img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .hd-gallery-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: white; border: none; width: 46px; height: 46px; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; z-index: 3; font-family: inherit; }
        .hd-gallery-nav:hover { background: rgba(0,0,0,0.8); transform: translateY(-50%) scale(1.1); }
        .hd-gallery-prev { left: 15px; }
        .hd-gallery-next { right: 15px; }
        .hd-gallery-counter { position: absolute; bottom: 15px; right: 15px; background: rgba(0,0,0,0.7); color: white; padding: 5px 14px; border-radius: 20px; font-size: 12.5px; font-weight: 600; z-index: 3; }
        .hd-thumbs { display: flex; gap: 8px; padding: 12px 18px; overflow-x: auto; background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; -webkit-overflow-scrolling: touch; }
        .hd-thumb { width: 78px; height: 58px; object-fit: cover; border-radius: 8px; cursor: pointer; transition: all 0.2s; border: 3px solid transparent; flex-shrink: 0; }
        .hd-thumb.active { border-color: #F4B400; }
        .hd-header-block { padding: 22px 28px 15px; border-bottom: 1px solid #e2e8f0; }
        .hd-name { font-size: 24px; font-weight: 700; color: #0B2447; margin: 0 0 10px 0; line-height: 1.3; }
        .hd-price-row { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
        .hd-price { font-size: 30px; font-weight: 800; color: #10b981; line-height: 1; }
        .hd-price-unit { font-size: 14px; color: #64748b; font-weight: 500; }
        .hd-quick-tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .hd-tag { display: inline-flex; align-items: center; gap: 6px; background: #eef2ff; color: #4DA6D9; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .hd-tag.green { background: #d1fae5; color: #059669; }
        .hd-tag.gold { background: #fef3c7; color: #b45309; }
        .hd-section { padding: 20px 28px; border-bottom: 1px solid #f1f5f9; }
        .hd-section:last-of-type { border-bottom: none; }
        .hd-section-title { font-size: 15px; font-weight: 700; color: #0B2447; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; }
        .hd-section-title i { color: #4DA6D9; }
        .hd-description { font-size: 14px; line-height: 1.7; color: #475569; white-space: pre-line; }
        .hd-specs { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
        .hd-spec-item { background: #f8fafc; padding: 14px 12px; border-radius: 12px; text-align: center; border: 1px solid #e8f0fe; }
        .hd-spec-item i { color: #4DA6D9; font-size: 20px; margin-bottom: 6px; display: block; }
        .hd-spec-item .hd-spec-value { font-size: 16px; font-weight: 700; color: #0B2447; display: block; }
        .hd-spec-item .hd-spec-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-top: 2px; display: block; }
        .hd-amenities { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
        .hd-amenity { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #f8fafc; border-radius: 10px; border-left: 3px solid #10b981; font-size: 13px; color: #1e293b; font-weight: 500; }
        .hd-amenity i { color: #10b981; font-size: 14px; flex-shrink: 0; }
        .hd-variations { display: flex; flex-direction: column; gap: 8px; }
        .hd-variation { display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; font-size: 14px; gap: 10px; }
        .hd-variation .hd-var-label { font-weight: 600; color: #0B2447; }
        .hd-variation .hd-var-price { font-weight: 700; color: #10b981; font-size: 15px; }
        .hd-footer { padding: 18px 28px 22px; background: #f8fafc; border-top: 1px solid #e2e8f0; flex-shrink: 0; display: flex; gap: 12px; }
        .hd-footer .btn-hd-close { flex: 1; padding: 14px; background: #e2e8f0; color: #475569; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; font-family: inherit; }
        .hd-footer .btn-hd-select { flex: 2; padding: 14px; background: linear-gradient(135deg, #F4B400, #e6a800); color: #0B2447; border: none; border-radius: 12px; font-weight: 700; font-size: 15px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; box-shadow: 0 4px 15px rgba(244,180,0,0.35); font-family: inherit; }
        .hd-footer .btn-hd-select.food { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .hd-footer .btn-hd-select.tour { background: linear-gradient(135deg, #10b981, #059669); color: white; }

        @media (max-width: 768px) {
            .house-detail-modal { padding: 12px; }
            .house-detail-content { max-height: 96vh; border-radius: 16px; }
            .hd-gallery { height: 260px; }
            .hd-name { font-size: 19px; }
            .hd-price { font-size: 24px; }
            .hd-header-block, .hd-section { padding-left: 18px; padding-right: 18px; }
            .hd-footer { padding: 14px 18px 18px; flex-direction: column-reverse; }
            .hd-footer .btn-hd-close, .hd-footer .btn-hd-select { flex: unset; width: 100%; }
        }
        @media (max-width: 480px) {
            .hd-gallery { height: 220px; }
            .hd-gallery-nav { width: 38px; height: 38px; font-size: 16px; }
            .hd-name { font-size: 17px; }
            .hd-price { font-size: 22px; }
            .hd-thumb { width: 60px; height: 45px; }
            .hd-specs { grid-template-columns: repeat(2, 1fr); }
            .hd-amenities { grid-template-columns: 1fr; }
        }

        .confirmation-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 99999; align-items: center; justify-content: center; padding: 20px; animation: logoutFadeIn 0.2s ease; overscroll-behavior: contain; }
        .confirmation-modal-overlay.show { display: flex; }
        @keyframes logoutFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .confirmation-modal { background: white; border-radius: 24px; max-width: 420px; width: 100%; padding: 35px 30px 25px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,0.4); animation: logoutSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border-top: 6px solid #ef4444; max-height: 90vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        @keyframes logoutSlideIn { from { opacity: 0; transform: translateY(-30px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .confirmation-modal.gold { border-top-color: #F4B400; }
        .confirmation-modal.gold .confirmation-modal-icon { background: linear-gradient(135deg, #fef3c7, #fcd34d); color: #d97706; }
        .confirmation-modal.gold h3 { color: #92400e; }
        .confirmation-modal.gold .btn-confirm-yes { background: linear-gradient(135deg, #F4B400, #e6a800); color: #0B2447; box-shadow: 0 4px 15px rgba(244, 180, 0, 0.35); }
        .confirmation-modal.gold .btn-confirm-yes:hover { box-shadow: 0 8px 25px rgba(244, 180, 0, 0.5); }

        .confirmation-modal-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #fee2e2, #fecaca); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; font-size: 36px; color: #ef4444; animation: logoutPulse 2s ease-in-out infinite; }
        @keyframes logoutPulse { 0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); } 50% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); } }
        .confirmation-modal h3 { font-size: 22px; font-weight: 700; color: #991b1b; margin-bottom: 8px; }
        .confirmation-modal p { color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 25px; }
        .confirmation-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-confirm-no, .btn-confirm-yes { flex: 1; min-width: 130px; min-height: 48px; padding: 13px 18px; border: none; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.25s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; -webkit-tap-highlight-color: rgba(0,0,0,0.1); touch-action: manipulation; font-family: inherit; }
        .btn-confirm-no { background: #e2e8f0; color: #475569; }
        .btn-confirm-no:hover, .btn-confirm-no:active { background: #cbd5e1; transform: translateY(-2px); }
        .btn-confirm-yes { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3); }
        .btn-confirm-yes:hover, .btn-confirm-yes:active { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239, 68, 68, 0.45); color: white; }

        @media (max-width: 480px) {
            .confirmation-modal-overlay { padding: 12px; }
            .confirmation-modal { padding: 28px 22px 20px; border-radius: 20px; }
            .confirmation-modal-icon { width: 65px; height: 65px; font-size: 28px; margin-bottom: 14px; }
            .confirmation-modal h3 { font-size: 19px; }
            .confirmation-modal p { font-size: 13px; margin-bottom: 20px; }
            .confirmation-modal-actions { flex-direction: column-reverse; }
            .btn-confirm-no, .btn-confirm-yes { width: 100%; }
        }

        .terms-modal { z-index: 100002 !important; }
        .terms-modal-content { max-width: 720px !important; padding: 0 !important; overflow: hidden !important; display: flex !important; flex-direction: column; max-height: 92vh !important; background: white; border-radius: 24px; width: 100%; box-shadow: 0 30px 80px rgba(0,0,0,0.4); animation: modalSlideIn 0.3s ease; }
        .terms-modal-header { background: linear-gradient(135deg, #0B2447 0%, #0B3D91 50%, #4DA6D9 100%); color: white; padding: 25px 30px; text-align: center; position: relative; overflow: hidden; flex-shrink: 0; }
        .terms-modal-icon { font-size: 42px; margin-bottom: 8px; position: relative; z-index: 1; color: #F4B400; }
        .terms-modal-header h3 { font-size: 22px; font-weight: 700; margin: 0 0 4px 0; color: white; position: relative; z-index: 1; }
        .terms-modal-header p { font-size: 13px; opacity: 0.9; margin: 0; color: #e0eeff; position: relative; z-index: 1; }
        .terms-modal-close { position: absolute; top: 14px; right: 14px; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.25); width: 38px; height: 38px; border-radius: 50%; cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all 0.25s ease; z-index: 5; font-family: inherit; }
        .terms-modal-close:hover { background: #ef4444; border-color: #ef4444; transform: rotate(90deg); }
        .terms-scroll-container { flex: 1; overflow-y: auto; padding: 25px 30px; background: white; min-height: 0; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; }
        .terms-scroll-container::-webkit-scrollbar { width: 8px; }
        .terms-scroll-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .terms-scroll-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .terms-section { margin-bottom: 25px; }
        .terms-section:last-child { margin-bottom: 0; }
        .terms-section-title { display: flex; align-items: center; gap: 10px; font-size: 17px; font-weight: 700; color: #0B2447; padding-bottom: 10px; border-bottom: 2px solid #4DA6D9; margin-bottom: 12px; }
        .terms-section-title i { color: #4DA6D9; font-size: 18px; }
        .terms-section-body { font-size: 13.5px; line-height: 1.7; color: #334155; }
        .terms-section-body h3 { color: #0B2447; margin: 14px 0 6px; font-weight: 700; font-size: 15px; }
        .terms-section-body p { margin: 0 0 10px; }
        .terms-section-body ul { padding-left: 22px; margin: 6px 0 12px; }
        .terms-section-body li { margin-bottom: 5px; }
        .terms-scroll-hint { text-align: center; padding: 12px; background: #fef3c7; color: #92400e; font-size: 12.5px; font-weight: 600; border-top: 1px solid #fde68a; flex-shrink: 0; transition: all 0.3s; }
        .terms-scroll-hint.done { background: #d1fae5; color: #065f46; border-top-color: #a7f3d0; }
        #bookingTermsAcceptForm { padding: 15px 25px 20px; border-top: 2px solid #e2e8f0; background: #f8fafc; flex-shrink: 0; display: none; animation: slideUp 0.3s ease; }
        #bookingTermsAcceptForm.visible { display: block; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .terms-checkbox-label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; padding: 12px 14px; background: white; border: 2px solid #e2e8f0; border-radius: 10px; margin-bottom: 12px; transition: all 0.2s; font-weight: 500; font-size: 13.5px; color: #1e293b; line-height: 1.5; }
        .terms-checkbox-label:hover { border-color: #4DA6D9; background: #f0f7fb; }
        .terms-checkbox-label input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: #4DA6D9; margin-top: 1px; flex-shrink: 0; }
        .terms-checkbox-text { flex: 1; }
        .terms-accept-btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); font-family: inherit; }
        .terms-accept-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45); }
        .terms-accept-btn:disabled { background: #cbd5e1; cursor: not-allowed; box-shadow: none; transform: none; color: #94a3b8; }
        .terms-modal-footer-note { text-align: center; padding: 10px 20px 15px; font-size: 11.5px; color: #94a3b8; background: #f8fafc; margin: 0; flex-shrink: 0; }

        @media (max-width: 600px) {
            .terms-modal { padding: 10px !important; }
            .terms-modal-content { max-width: 100% !important; max-height: 96vh !important; border-radius: 16px; }
            .terms-modal-header { padding: 20px; }
            .terms-modal-header h3 { font-size: 18px; }
            .terms-modal-icon { font-size: 34px; }
            .terms-modal-close { width: 32px; height: 32px; font-size: 14px; top: 10px; right: 10px; }
            .terms-scroll-container { padding: 18px 20px; }
            .terms-section-title { font-size: 15px; }
            .terms-section-body { font-size: 13px; }
            .terms-scroll-hint { font-size: 11.5px; padding: 10px; }
            #bookingTermsAcceptForm { padding: 12px 18px 15px; }
            .terms-checkbox-label { font-size: 12.5px; padding: 10px 12px; }
            .terms-accept-btn { font-size: 14px; padding: 12px; }
        }

        .prev-rating-banner { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; text-align: center; margin-bottom: 18px; }
        .prev-rating-banner .prev-label { font-size: 14px; color: #475569; margin-bottom: 8px; font-weight: 500; }
        .prev-rating-banner .prev-stars { display: flex; justify-content: center; gap: 6px; font-size: 26px; margin-bottom: 8px; line-height: 1; }
        .prev-rating-banner .prev-hint { font-size: 12.5px; color: #94a3b8; }
        .overall-rating-label { text-align: center; font-weight: 700; color: #0f172a; font-size: 15px; margin-bottom: 12px; display: block; }
        .big-rating-input { display: flex; justify-content: center; gap: 8px; font-size: 40px; line-height: 1; margin-bottom: 4px; }
        .big-rating-input i { color: #cbd5e1; transition: color 0.15s ease, transform 0.15s ease; user-select: none; cursor: pointer; }
        .big-rating-input i.active { color: #f59e0b; }
        .big-rating-input i.preview { color: #f59e0b; transform: scale(1.08); }
        .rating-word { text-align: center; color: #64748b; font-size: 14px; margin: 4px 0 18px 0; min-height: 20px; font-weight: 500; }
        .review-section-label { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: block; }
        .review-textarea { width: 100%; padding: 12px 14px; border: 1px solid #d4e4f0; border-radius: 10px; background: #f8faff; color: #1a3a5c; font-family: inherit; font-size: 14px; resize: vertical; min-height: 85px; transition: border-color 0.2s; margin-bottom: 14px; }
        .review-textarea:focus { outline: none; border-color: #4DA6D9; background: #ffffff; box-shadow: 0 0 0 3px rgba(77, 166, 217, 0.12); }
        .anon-row { display: flex; align-items: center; gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; cursor: pointer; }
        .anon-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: #7c3aed; cursor: pointer; flex-shrink: 0; }
        .anon-row .anon-text { font-weight: 600; color: #1e293b; font-size: 14px; display: flex; align-items: center; gap: 6px; }
        .anon-row .anon-note { margin-left: auto; font-size: 11.5px; color: #94a3b8; font-style: italic; }
        .update-review-btn { width: 100%; padding: 14px; background: #F4B400; color: #0B2447; border: none; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; font-family: inherit; }
        .btn-cancel-review { flex: 1; padding: 14px; background: #e2e8f0; color: #475569; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; font-family: inherit; }
        .success-popup-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(5px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
        .success-popup-overlay.show { display: flex; }
        .success-popup { background: white; border-radius: 24px; max-width: 460px; width: 100%; padding: 40px 30px 30px; text-align: center; border-top: 6px solid #10b981; animation: popIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
        .success-popup .success-icon { font-size: 70px; color: #10b981; margin-bottom: 15px; }
        .success-popup h3 { font-size: 24px; font-weight: 700; color: #10b981; margin-bottom: 10px; }
        .success-popup p { color: #4a6a8c; font-size: 15px; margin-bottom: 20px; line-height: 1.6; }
        .success-popup .package-ref { background: #f0f7fb; padding: 14px; border-radius: 12px; font-family: 'Courier New', monospace; font-weight: 700; color: #0B2447; font-size: 16px; margin-bottom: 20px; word-break: break-all; }
        .success-popup .btn-view { width: 100%; padding: 14px; background: #F4B400; color: #0B2447; border: none; border-radius: 12px; font-weight: 700; font-size: 15px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; }

        @media (max-width: 480px) {
            .big-rating-input { font-size: 34px; gap: 6px; }
            .anon-row { flex-wrap: wrap; gap: 6px; }
            .anon-row .anon-note { margin-left: 0; width: 100%; }
            .success-popup { padding: 30px 22px 22px; border-radius: 20px; }
            .success-popup .success-icon { font-size: 56px; }
            .success-popup h3 { font-size: 20px; }
            .success-popup p { font-size: 14px; }
        }

        .gcash-popup-overlay { display: none; position: fixed; inset: 0; background: rgba(11, 36, 71, 0.65); z-index: 99999; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; }
        .gcash-popup-overlay.show { display: flex; }
        .gcash-popup { background: #ffffff; border-radius: 24px; max-width: 460px; width: 100%; padding: 32px 26px 24px; text-align: center; box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4); border-top: 6px solid #10b981; animation: gcashPopIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); max-height: 94vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        @keyframes gcashPopIn { from { opacity: 0; transform: translateY(-30px) scale(0.92); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .gcash-success-icon { width: 70px; height: 70px; border-radius: 50%; background: #10b981; color: white; display: flex; align-items: center; justify-content: center; font-size: 34px; margin: 0 auto 15px; box-shadow: 0 8px 25px rgba(16, 185, 129, 0.35); }
        .gcash-title { font-size: 22px; font-weight: 800; color: #0B2447; margin-bottom: 6px; }
        .gcash-subtitle { font-size: 14px; color: #64748b; margin-bottom: 18px; line-height: 1.5; }
        .gcash-divider { height: 1px; background: linear-gradient(90deg, transparent, #e2e8f0, transparent); margin: 4px 0 16px; }
        .gcash-ref-badge { display: inline-block; background: #d1fae5; color: #065f46; font-family: 'Courier New', monospace; font-weight: 700; font-size: 14px; padding: 8px 18px; border-radius: 20px; margin-bottom: 18px; word-break: break-all; }
        .gcash-ref-badge i { margin-right: 4px; }
        .gcash-detail-box { background: #f8fafc; border-radius: 12px; padding: 4px 16px; margin-bottom: 18px; text-align: left; }
        .gcash-detail-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; gap: 12px; }
        .gcash-detail-row:last-child { border-bottom: none; }
        .gcash-detail-row span { color: #64748b; }
        .gcash-detail-row strong { color: #0B2447; font-weight: 700; }
        .gcash-detail-row .gcash-amount { color: #10b981; font-size: 18px; font-weight: 800; }
        .gcash-qr-box { background: #f1f5f9; border-radius: 16px; padding: 18px; margin-bottom: 18px; display: flex; align-items: center; justify-content: center; min-height: 200px; }
        .gcash-qr-box img { max-width: 200px; max-height: 200px; width: 100%; height: auto; display: block; border-radius: 8px; background: white; padding: 6px; }
        .gcash-qr-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94a3b8; gap: 8px; }
        .gcash-qr-placeholder i { font-size: 72px; color: #cbd5e1; }
        .gcash-qr-placeholder small { font-size: 12px; font-weight: 600; }
        .gcash-account-box { background: #f8fafc; border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; text-align: left; }
        .gcash-account-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 13.5px; gap: 10px; }
        .gcash-account-row span { color: #64748b; }
        .gcash-account-row strong { color: #0B2447; font-weight: 700; }
        .gcash-howto-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; text-align: left; }
        .gcash-howto-title { font-size: 13.5px; font-weight: 700; color: #92400e; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .gcash-howto-title i { color: #f59e0b; }
        .gcash-howto-list { margin: 0; padding-left: 20px; font-size: 12.5px; color: #78350f; line-height: 1.7; }
        .gcash-howto-list li { margin-bottom: 2px; }
        .gcash-status-banner { background: #fef3c7; color: #92400e; padding: 10px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; margin-bottom: 18px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .gcash-status-banner i { color: #f59e0b; }
        .gcash-actions { display: flex; gap: 10px; }
        .gcash-btn-upload, .gcash-btn-close { flex: 1; padding: 13px 16px; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s; font-family: inherit; border: none; }
        .gcash-btn-upload { flex: 2; background: linear-gradient(135deg, #10b981, #059669); color: white; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); }
        .gcash-btn-upload:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.45); color: white; }
        .gcash-btn-close { background: #e2e8f0; color: #475569; }
        .gcash-btn-close:hover { background: #cbd5e1; color: #334155; transform: translateY(-2px); }

        @media (max-width: 480px) {
            .gcash-popup-overlay { padding: 12px; }
            .gcash-popup { padding: 26px 20px 20px; border-radius: 20px; }
            .gcash-success-icon { width: 60px; height: 60px; font-size: 28px; margin-bottom: 12px; }
            .gcash-title { font-size: 19px; }
            .gcash-subtitle { font-size: 13px; margin-bottom: 14px; }
            .gcash-qr-box { min-height: 170px; padding: 14px; }
            .gcash-qr-box img { max-width: 170px; max-height: 170px; }
            .gcash-actions { flex-direction: column-reverse; }
            .gcash-btn-upload, .gcash-btn-close { flex: unset; width: 100%; }
        }
        .alert-overlay { display: none !important; }
    </style>
</head>
<body>

<?php include 'components/navbar.php'; ?>

<!-- PAGE HERO -->
<div class="hero">
    <div class="hero-content">
        <h1><i class="fas fa-box-open"></i> Build Your Package</h1>
        <p>Combine your stay, boat tour, and food in one booking flow</p>
    </div>
</div>

<div class="main-container">

<?php if ($booking_error): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #ef4444; display: flex; align-items: center; gap: 10px; font-size: 14px; line-height: 1.5;">
        <i class="fas fa-exclamation-circle" style="font-size: 20px; flex-shrink: 0;"></i>
        <span><?php echo htmlspecialchars($booking_error); ?></span>
    </div>
<?php endif; ?>

<?php if (!$has_any): ?>
<div class="step-1">

    <div style="text-align:center; margin-bottom:30px;">

        <h2 style="font-size:24px; font-weight:800; color:#06263D; margin-bottom:8px;">
            Build Your Island Vacation
        </h2>

        <p style="color:#64748b; font-size:15px;">
            Start with your stay, then add tours and food to create your complete package.
        </p>

    </div>

    <div class="choice-grid">
        <button type="button" class="choice-card" onclick="openModal('house')">
            <div class="choice-icon"><i class="fas fa-home"></i></div>
       <h3>Stay</h3>

<p>
Choose your transient house accommodation first.
</p>

<span class="btn-select">
    <i class="fas fa-home"></i>
    Add Stay
</span>
        </button>

        <button type="button" class="choice-card" onclick="openModal('food')">
            <div class="choice-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);"><i class="fas fa-utensils"></i></div>
           
<h3>Food</h3>

<p>
Add meals and food packages to complete your trip.
</p>

<span class="btn-select">
    <i class="fas fa-utensils"></i>
    Add Food
</span>
        <button type="button" class="choice-card" onclick="openModal('tour')">
            <div class="choice-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="fas fa-umbrella-beach"></i></div>
          <h3>Island Adventure</h3>

<p>
Add island tours and activities to your package.
</p>

<span class="btn-select">
    <i class="fas fa-ship"></i>
    Add Tour
</span>
        </button>
    </div>
</div>
<?php endif; ?>

<?php if ($has_any): ?>
<div class="step-2">
    <div class="package-header">
        <h2><i class="fas fa-clipboard-list" style="color: #4DA6D9;"></i> Review Your Package</h2>
        <button type="button" class="btn-clear-all" onclick="openClearAllModal()">
            <i class="fas fa-trash-alt"></i> Clear All
        </button>
    </div>

    <p style="color: #64748b; font-size: 14px; margin-bottom: 25px; line-height: 1.5;">
        <i class="fas fa-info-circle" style="color: #4DA6D9;"></i>
Your package is almost ready. Add or adjust your stay, tours, and food before confirming your booking.    </p>

    <?php if ($has_house): $h = $cart['house']; ?>
    <div class="cart-item">
        <div class="item-thumb house">
            <?php if ($cart_house_img): ?>
                <img src="<?php echo htmlspecialchars($cart_house_img); ?>?v=<?php echo time(); ?>" alt="<?php echo htmlspecialchars($h['name'] ?? ''); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <span class="thumb-fallback" style="display:none;"><i class="fas fa-home"></i></span>
            <?php else: ?>
                <span class="thumb-fallback"><i class="fas fa-home"></i></span>
            <?php endif; ?>
        </div>
        <div class="item-body">
            <div class="item-header">
                <div>
                    <span class="item-type-label">House Booking</span>
                    <h4 class="item-title"><?php echo htmlspecialchars($h['name'] ?? ''); ?></h4>
                </div>
                <div class="item-actions">
                    <button type="button" class="btn-view-item" onclick="openItemDetail('house_<?php echo (int)($h['id'] ?? 0); ?>')">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <button type="button" class="btn-edit-item" onclick="editHouse()">
                        <i class="fas fa-pen"></i> Edit
                    </button>
                    <button type="button" class="btn-remove-item" onclick="openRemoveModal('house', '<?php echo htmlspecialchars($h['name'] ?? '', ENT_QUOTES); ?>')">
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
            </div>

            <!-- ✅ Check-in with TIME -->
            <div class="item-detail"><i class="fas fa-calendar-alt"></i>
                <span>
                    <strong>Check-in:</strong>
                    <?php echo date('M d, Y', strtotime($h['check_in'] ?? 'now')); ?>
                    <?php if (!empty($h['check_in_time'])): ?>
                        • <?php echo date('h:i A', strtotime($h['check_in_time'])); ?>
                    <?php endif; ?>
                </span>
            </div>
            <!-- ✅ Check-out with TIME -->
            <div class="item-detail"><i class="fas fa-calendar-check"></i>
                <span>
                    <strong>Check-out:</strong>
                    <?php echo date('M d, Y', strtotime($h['check_out'] ?? 'now')); ?>
                    <?php if (!empty($h['check_out_time'])): ?>
                        • <?php echo date('h:i A', strtotime($h['check_out_time'])); ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="item-detail"><i class="fas fa-moon"></i>
                <span><strong>Duration:</strong> <?php echo (int)($h['days'] ?? (($h['nights'] ?? 0) + 1)); ?> day<?php echo ((int)($h['days'] ?? (($h['nights'] ?? 0) + 1))) > 1 ? 's' : ''; ?> / <?php echo (int)($h['nights'] ?? 0); ?> night<?php echo ((int)($h['nights'] ?? 0)) > 1 ? 's' : ''; ?></span>
            </div>
            <div class="item-detail"><i class="fas fa-users"></i>
                <span><strong>Guests:</strong> <?php echo (int)($h['guests'] ?? 0); ?> pax</span>
            </div>
            <?php if (!empty($h['guest_names'])): ?>
            <div class="item-detail"><i class="fas fa-user-friends"></i>
                <span><strong>Names:</strong> <?php echo htmlspecialchars(str_replace("\n", ", ", $h['guest_names'])); ?></span>
            </div>
            <?php endif; ?>

            <span class="item-price">₱<?php echo number_format((float)($h['price'] ?? 0), 2); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_food): $f = $cart['food']; ?>
    <div class="cart-item">
        <div class="item-thumb food">
            <?php if ($cart_food_img): ?>
                <img src="<?php echo htmlspecialchars($cart_food_img); ?>?v=<?php echo time(); ?>" alt="<?php echo htmlspecialchars($f['name'] ?? ''); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <span class="thumb-fallback" style="display:none;"><i class="fas fa-utensils"></i></span>
            <?php else: ?>
                <span class="thumb-fallback"><i class="fas fa-utensils"></i></span>
            <?php endif; ?>
        </div>
        <div class="item-body">
            <div class="item-header">
                <div>
                    <span class="item-type-label" style="background: #fef3c7; color: #d97706;">Food Order</span>
                    <h4 class="item-title"><?php echo htmlspecialchars($f['name'] ?? ''); ?></h4>
                </div>
                <div class="item-actions">
                    <button type="button" class="btn-view-item" onclick="openItemDetail('food_<?php echo (int)($f['id'] ?? 0); ?>')">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <button type="button" class="btn-edit-item" onclick="editFood()">
                        <i class="fas fa-pen"></i> Edit
                    </button>
                    <button type="button" class="btn-remove-item" onclick="openRemoveModal('food', '<?php echo htmlspecialchars($f['name'] ?? '', ENT_QUOTES); ?>')">
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
            </div>

            <div class="item-detail"><i class="fas fa-calendar-alt"></i>
                <span><strong>Date:</strong> <?php echo date('M d, Y', strtotime($f['preferred_date'] ?? 'now')); ?></span>
            </div>
            <div class="item-detail"><i class="fas fa-clock"></i>
                <span><strong>Time:</strong> <?php echo date('h:i A', strtotime($f['preferred_time'] ?? 'now')); ?></span>
            </div>
            <div class="item-detail"><i class="fas fa-truck"></i>
                <span><strong>Method:</strong> <?php echo ucfirst($f['fulfillment_method'] ?? 'pickup'); ?></span>
            </div>
            <?php if (!empty($f['size_variant'])): ?>
            <div class="item-detail"><i class="fas fa-box"></i>
                <span><strong>Size:</strong> <?php echo htmlspecialchars($f['size_variant']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($f['delivery_address'])): ?>
            <div class="item-detail"><i class="fas fa-map-marker-alt"></i>
                <span><strong>Address:</strong> <?php echo htmlspecialchars($f['delivery_address']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($f['contact_number'])): ?>
            <div class="item-detail"><i class="fas fa-phone"></i>
                <span><strong>Contact:</strong> <?php echo htmlspecialchars($f['contact_number']); ?></span>
            </div>
            <?php endif; ?>

            <span class="item-price">₱<?php echo number_format((float)($f['price'] ?? 0), 2); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($has_tour): $t = $cart['tour']; ?>
    <div class="cart-item">
        <div class="item-thumb tour">
            <?php if ($cart_tour_img): ?>
                <img src="<?php echo htmlspecialchars($cart_tour_img); ?>?v=<?php echo time(); ?>" alt="<?php echo htmlspecialchars($t['name'] ?? ''); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <span class="thumb-fallback" style="display:none;"><i class="fas fa-umbrella-beach"></i></span>
            <?php else: ?>
                <span class="thumb-fallback"><i class="fas fa-umbrella-beach"></i></span>
            <?php endif; ?>
        </div>
        <div class="item-body">
            <div class="item-header">
                <div>
                    <span class="item-type-label" style="background: #d1fae5; color: #059669;">Tour Booking</span>
                    <h4 class="item-title"><?php echo htmlspecialchars($t['name'] ?? ''); ?></h4>
                </div>
                <div class="item-actions">
                    <button type="button" class="btn-view-item" onclick="openItemDetail('tour_<?php echo (int)($t['id'] ?? 0); ?>')">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <button type="button" class="btn-edit-item" onclick="editTour()">
                        <i class="fas fa-pen"></i> Edit
                    </button>
                    <button type="button" class="btn-remove-item" onclick="openRemoveModal('tour', '<?php echo htmlspecialchars($t['name'] ?? '', ENT_QUOTES); ?>')">
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
            </div>

            <div class="item-detail"><i class="fas fa-calendar-alt"></i>
                <span><strong>Date:</strong> <?php echo date('M d, Y', strtotime($t['booking_date'] ?? 'now')); ?></span>
            </div>
            <div class="item-detail"><i class="fas fa-clock"></i>
                <span><strong>Time:</strong> <?php echo date('h:i A', strtotime($t['preferred_time'] ?? 'now')); ?></span>
            </div>
            <div class="item-detail"><i class="fas fa-users"></i>
                <span><strong>Pax:</strong> <?php echo (int)($t['number_of_guests'] ?? 0); ?></span>
            </div>
            <?php if (!empty($t['guest_name'])): ?>
            <div class="item-detail"><i class="fas fa-user"></i>
                <span><strong>Lead Guest:</strong> <?php echo htmlspecialchars($t['guest_name']); ?></span>
            </div>
            <?php endif; ?>

            <span class="item-price">₱<?php echo number_format((float)($t['price'] ?? 0), 2); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$has_house || !$has_food || !$has_tour): ?>
    <div class="add-more-section">
        <h3><i class="fas fa-plus-circle"></i> Complete Your Vacation Package</h3>
        <div class="add-buttons">
            <?php if (!$has_house): ?>
                <button type="button" class="btn-add-more" onclick="openModal('house')">
                    <i class="fas fa-home"></i> Add House
                </button>
            <?php endif; ?>
            <?php if (!$has_food): ?>
                <button type="button" class="btn-add-more" onclick="openModal('food')">
                    <i class="fas fa-utensils"></i> Add Food
                </button>
            <?php endif; ?>
            <?php if (!$has_tour): ?>
                <button type="button" class="btn-add-more" onclick="openModal('tour')">
                    <i class="fas fa-umbrella-beach"></i> Add Tour
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="summary-box">
        <h3 style="font-size: 18px; margin-bottom: 15px;">
            <i class="fas fa-receipt"></i> Package Summary
        </h3>

        <?php if ($has_house): ?>
        <div class="summary-row">
            <span><i class="fas fa-home"></i> House — <?php echo htmlspecialchars($h['name'] ?? ''); ?></span>
            <span>₱<?php echo number_format((float)($h['price'] ?? 0), 2); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($has_food): ?>
        <div class="summary-row">
            <span><i class="fas fa-utensils"></i> Food — <?php echo htmlspecialchars($f['name'] ?? ''); ?></span>
            <span>₱<?php echo number_format((float)($f['price'] ?? 0), 2); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($has_tour): ?>
        <div class="summary-row">
            <span><i class="fas fa-umbrella-beach"></i> Tour — <?php echo htmlspecialchars($t['name'] ?? ''); ?></span>
            <span>₱<?php echo number_format((float)($t['price'] ?? 0), 2); ?></span>
        </div>
        <?php endif; ?>

        <div class="summary-total">
            <span>TOTAL</span>
            <span class="total-value">₱<?php echo number_format($grand_total, 2); ?></span>
        </div>

        <button type="button" class="btn-confirm" onclick="openBookingTermsModal()">
            <i class="fas fa-check-circle"></i> Confirm &amp; Book Now
        </button>

        <p class="terms-note">
            <i class="fas fa-info-circle"></i>
            By confirming, you agree to our Terms &amp; Conditions.
            Payment proof will be uploaded after confirmation.
        </p>
    </div>
</div>
<?php endif; ?>

</div><!-- /main-container -->

<!-- ============================================================ -->
<!-- MODAL: ADD HOUSE  ✅ UPDATED with times                       -->
<!-- ============================================================ -->
<div class="modal" id="houseModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-home"></i> Add House to Package</h3>
            <button type="button" class="close" onclick="closeModal('house')">&times;</button>
        </div>

        <?php if (isset($modal_error) && $open_modal === 'house'): ?>
        <div class="modal-error" style="background:#fee2e2;border-left:4px solid #ef4444;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;align-items:flex-start;gap:10px;">
            <i class="fas fa-exclamation-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;margin-top:2px;"></i>
            <span><?php echo htmlspecialchars($modal_error); ?></span>
        </div>
        <?php endif; ?>

        <?php if (empty($houses)): ?>
            <div style="text-align:center;padding:40px 20px;color:#94a3b8;">
                <i class="fas fa-home" style="font-size:48px;color:#cbd5e1;display:block;margin-bottom:15px;"></i>
                <p>No houses available at the moment.</p>
            </div>
        <?php else: ?>

        <div id="houseStep1">
            <p style="color:#64748b; font-size:13px; margin-bottom:15px; line-height:1.5;">
                <i class="fas fa-info-circle" style="color:#4DA6D9;"></i>
                Tap a house to add it to your package. Click <strong>View</strong> to see full details.
            </p>
            <div class="item-pick-list">
                <?php foreach ($houses as $h): 
                    if (!is_array($h)) continue;
                    $thumbSrc = null;
                    if (!empty($h['image']) && $h['image'] !== 'default-house.jpg') {
                        if (file_exists('uploads/houses/' . $h['image'])) $thumbSrc = 'uploads/houses/' . $h['image'];
                        elseif (file_exists('uploads/houses/gallery/' . $h['image'])) $thumbSrc = 'uploads/houses/gallery/' . $h['image'];
                    }
                    if (!$thumbSrc && !empty($house_galleries[$h['id']][0])) $thumbSrc = $house_galleries[$h['id']][0];
                    $shortDesc = trim($h['description'] ?? '');
                    if (mb_strlen($shortDesc) > 80) $shortDesc = mb_substr($shortDesc, 0, 80) . '…';
                ?>
                <div class="item-pick"
                     data-id="<?php echo (int)$h['id']; ?>"
                     data-name="<?php echo htmlspecialchars($h['house_name'] ?? '', ENT_QUOTES); ?>"
                     data-price="<?php echo (float)($h['price_per_night'] ?? 0); ?>"
                     data-capacity="<?php echo (int)($h['capacity'] ?? 20); ?>"
                     onclick="selectHouse(this)">

                    <div class="item-pick-thumb house">
                        <?php if ($thumbSrc): ?>
                            <img src="<?php echo htmlspecialchars($thumbSrc); ?>?v=<?php echo time(); ?>"
                                 alt="<?php echo htmlspecialchars($h['house_name'] ?? ''); ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <span class="thumb-icon" style="display:none;"><i class="fas fa-home"></i></span>
                        <?php else: ?>
                            <span class="thumb-icon"><i class="fas fa-home"></i></span>
                        <?php endif; ?>
                    </div>

                    <div class="item-pick-info">
                        <strong><?php echo htmlspecialchars($h['house_name'] ?? ''); ?></strong>
                        <div class="ip-price">₱<?php echo number_format((float)($h['price_per_night'] ?? 0)); ?><small>/night</small></div>
                        <div class="ip-meta">
                            <span class="ip-chip"><i class="fas fa-users"></i> <?php echo (int)($h['capacity'] ?? 0); ?> pax</span>
                            <?php if (!empty($h['bedrooms'])): ?>
                                <span class="ip-chip gray"><i class="fas fa-bed"></i> <?php echo (int)$h['bedrooms']; ?> bed<?php echo $h['bedrooms'] != 1 ? 's' : ''; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($shortDesc): ?>
                            <div class="ip-desc"><?php echo htmlspecialchars($shortDesc); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="item-pick-actions">
                        <button type="button" class="btn-item-view house"
                                onclick="event.stopPropagation(); openItemDetail('house_<?php echo (int)$h['id']; ?>')"
                                title="View full details">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <i class="fas fa-chevron-right ip-arrow"></i>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="POST" id="houseForm" style="display:none;">
            <input type="hidden" name="add_house_to_package" value="1">
            <input type="hidden" name="house_id" id="h_house_id">
            <input type="hidden" name="check_in" id="h_check_in" required>
            <input type="hidden" name="nights" id="h_nights">
            <input type="hidden" name="total_price" id="h_total_price">
            <input type="hidden" name="guest_names_json" id="h_guest_names_json">

            <button type="button" class="btn-back-list" onclick="backToHouseList()">
                <i class="fas fa-arrow-left"></i> Back to list
            </button>

            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-home"></i> House</label>
                    <input type="text" id="h_display_house" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Price/Night</label>
                    <input type="text" id="h_display_price" class="form-control" readonly>
                </div>
            </div>

            <div class="calendar-container">
                <div class="calendar-header">
                    <h4 id="houseCalendarMonthYear"></h4>
                    <div class="calendar-nav">
                        <button type="button" onclick="changeHouseMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                        <button type="button" onclick="changeHouseMonth(1)"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="calendar-grid" id="houseCalendarGrid"></div>
                <div class="calendar-legend">
                    <span><span class="dot available"></span> Available</span>
                    <span><span class="dot booked"></span> Booked</span>
                    <span><span class="dot selected"></span> Selected</span>
                </div>
                <div class="calendar-info" id="houseCalendarInfo">Click a date to pick your check-in. Then click your check-out.</div>
            </div>

            <!-- ✅ Check-in / Check-out DATES -->
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Check-in Date *</label>
                    <input type="date" id="h_check_in_display" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Check-out Date *</label>
                    <input type="date" name="check_out" id="h_check_out" class="form-control" readonly required>
                </div>
            </div>

            <!-- ✅ NEW: Check-in / Check-out TIMES -->
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Check-in Time *</label>
                    <input type="time" name="check_in_time" id="h_check_in_time" class="form-control" value="14:00" required>
                    <small class="time-helper"><i class="fas fa-info-circle"></i> Standard check-in: 2:00 PM</small>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-clock"></i> Check-out Time *</label>
                    <input type="time" name="check_out_time" id="h_check_out_time" class="form-control" value="12:00" required>
                    <small class="time-helper"><i class="fas fa-info-circle"></i> Standard check-out: 12:00 PM</small>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-users"></i> Number of Pax * <small style="color:#94a3b8; font-weight:400;">(max <span id="h_max_capacity">20</span>)</small></label>
                <input type="number" name="guests" id="h_guests" class="form-control"
                       min="1" max="20" placeholder="Enter number of pax" required
                       onchange="rebuildGuestNameInputs(); calcHouseTotal();"
                       oninput="validatePax(this)">
            </div>

            <div class="guest-names-field">
                <div class="guest-names-header">
                    <label><i class="fas fa-users"></i> Guest Names</label>
                    <span class="guest-count-badge" id="guestCountBadge">0 / 0</span>
                </div>
                <div id="guestNamesList"></div>
                <div class="help-text" id="guestNamesHelp">
                    <i class="fas fa-info-circle"></i> Enter the number of pax above to add guest name fields
                </div>
            </div>

            <div class="stay-summary-box">
                <div class="stay-row">
                    <span><i class="fas fa-calendar-alt" style="color:#4DA6D9;"></i> Stay Duration:</span>
                    <span><strong id="h_display_days">0</strong> day(s) / <strong id="h_display_nights">0</strong> night(s)</span>
                </div>
                <div class="stay-row total-row">
                    <span><i class="fas fa-money-bill-wave" style="color:#10b981;"></i> Total amount:</span>
                    <span><strong>₱<span id="h_display_total">0.00</span></strong></span>
                </div>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-cancel-review" style="flex:1;" onclick="backToHouseList()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button type="submit" class="btn-submit-modal" style="flex:2; margin-top:0;">
                    <i class="fas fa-plus-circle"></i> Add House to Package
                </button>
            </div>
        </form>

        <?php endif; ?>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: ADD FOOD (unchanged)                                  -->
<!-- ============================================================ -->
<div class="modal" id="foodModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-calendar-check"></i> Reserve Food Package</h3>
            <button type="button" class="close" onclick="closeModal('food')">&times;</button>
        </div>

        <?php if (isset($modal_error) && $open_modal === 'food'): ?>
        <div class="modal-error" style="background:#fee2e2;border-left:4px solid #ef4444;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;gap:10px;">
            <i class="fas fa-exclamation-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;margin-top:2px;"></i>
            <span><?php echo htmlspecialchars($modal_error); ?></span>
        </div>
        <?php endif; ?>

        <?php if (empty($food_items)): ?>
            <div style="text-align:center;padding:40px 20px;color:#94a3b8;">
                <i class="fas fa-utensils" style="font-size:48px;color:#cbd5e1;display:block;margin-bottom:15px;"></i>
                <p>No food items available at the moment.</p>
            </div>
        <?php else: ?>

        <div id="foodStep1">
            <p style="color:#64748b; font-size:13px; margin-bottom:15px; line-height:1.5;">
                <i class="fas fa-info-circle" style="color:#4DA6D9;"></i>
                Tap a food item to add it. Click <strong>View</strong> to see full details.
            </p>
            <div class="item-pick-list">
                <?php foreach ($food_items as $food):
                    if (!is_array($food)) continue;
                    $variations = !empty($food['size_variations']) ? json_decode($food['size_variations'], true) : [];
                    $has_var = !empty($variations) && is_array($variations);
                    $display_price = $has_var ? ($variations[0]['price'] ?? 0) : ($food['price'] ?? 0);

                    $thumbSrc = null;
                    if (!empty($food_galleries[$food['id']][0])) $thumbSrc = $food_galleries[$food['id']][0];
                    elseif (!empty($food['image']) && $food['image'] !== 'default-food.jpg' && file_exists('uploads/foods/' . $food['image'])) $thumbSrc = 'uploads/foods/' . $food['image'];

                    $shortDesc = trim($food['description'] ?? '');
                    if (mb_strlen($shortDesc) > 80) $shortDesc = mb_substr($shortDesc, 0, 80) . '…';
                ?>
                <div class="item-pick"
                     data-id="<?php echo (int)$food['id']; ?>"
                     data-name="<?php echo htmlspecialchars($food['name'] ?? '', ENT_QUOTES); ?>"
                     data-price="<?php echo (float)$display_price; ?>"
                     data-variations='<?php echo htmlspecialchars(json_encode($has_var ? $variations : []), ENT_QUOTES); ?>'
                     onclick="selectFood(this)">

                    <div class="item-pick-thumb food">
                        <?php if ($thumbSrc): ?>
                            <img src="<?php echo htmlspecialchars($thumbSrc); ?>?v=<?php echo time(); ?>"
                                 alt="<?php echo htmlspecialchars($food['name'] ?? ''); ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <span class="thumb-icon" style="display:none;"><i class="fas fa-utensils"></i></span>
                        <?php else: ?>
                            <span class="thumb-icon"><i class="fas fa-utensils"></i></span>
                        <?php endif; ?>
                    </div>

                    <div class="item-pick-info">
                        <strong><?php echo htmlspecialchars($food['name'] ?? ''); ?></strong>
                        <div class="ip-price">₱<?php echo number_format($display_price); ?><?php if ($has_var): ?><small>· <?php echo count($variations); ?> sizes</small><?php endif; ?></div>
                        <div class="ip-meta">
                            <?php if (!empty($food['category'])): ?>
                                <span class="ip-chip gold"><i class="fas fa-tag"></i> <?php echo htmlspecialchars(str_replace('_', ' ', $food['category'])); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($food['pax_range'])): ?>
                                <span class="ip-chip"><i class="fas fa-users"></i> <?php echo htmlspecialchars($food['pax_range']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($shortDesc): ?>
                            <div class="ip-desc"><?php echo htmlspecialchars($shortDesc); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="item-pick-actions">
                        <button type="button" class="btn-item-view food"
                                onclick="event.stopPropagation(); openItemDetail('food_<?php echo (int)$food['id']; ?>')"
                                title="View full details">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <i class="fas fa-chevron-right ip-arrow"></i>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="POST" id="foodForm" style="display:none;">
            <input type="hidden" name="add_food_to_package" value="1">
            <input type="hidden" name="food_id" id="f_food_id">
            <input type="hidden" name="total_price" id="f_total_price">

            <button type="button" class="btn-back-list" onclick="backToFoodList()">
                <i class="fas fa-arrow-left"></i> Back to list
            </button>

            <div class="form-group">
                <label><i class="fas fa-utensils"></i> Food Package</label>
                <input type="text" id="f_display_name" class="form-control" readonly>
            </div>

            <div class="form-group" id="f_size_group" style="display:none;">
                <label><i class="fas fa-arrows-alt-h"></i> Package Size / Variant *</label>
                <select name="size_variant" id="f_size_variant" class="form-control" onchange="calcFoodTotal()" required></select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-tag"></i> Price</label>
                <input type="text" id="f_display_price" class="form-control price-display" readonly>
            </div>

            <div class="form-group">
                <label><i class="fas fa-phone"></i> Contact Number *</label>
                <div class="phone-input-group">
                    <select class="phone-suffix-select" disabled aria-label="Country code">
                        <option value="+63" selected>+63 🇵🇭</option>
                    </select>
                    <div class="phone-input-wrapper">
                        <i class="fas fa-phone phone-icon"></i>
                        <input type="tel"
                               name="contact_number"
                               id="f_contact"
                               class="form-control"
                               placeholder="9123456789"
                               maxlength="10"
                               inputmode="numeric"
                               autocomplete="tel"
                               required
                               oninput="validatePhone(this, 'f_contact_count')">
                        <span class="digit-count" id="f_contact_count">0/10</span>
                    </div>
                </div>
                <div class="helper-text">
                    <i class="fas fa-info-circle"></i> Enter 10-digit PH mobile number (e.g., 9123456789)
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-truck"></i> Fulfillment Method *</label>
                <div class="fulfillment-method-group">
                    <label class="fulfillment-option">
                        <input type="radio" name="fulfillment_method" value="pickup" checked onchange="toggleDelivery()">
                        <span class="fulfillment-option-label">
                            <i class="fas fa-store"></i>
                            <strong>Pickup</strong>
                            <small>Pick up at our location</small>
                        </span>
                    </label>
                    <label class="fulfillment-option">
                        <input type="radio" name="fulfillment_method" value="delivery" onchange="toggleDelivery()">
                        <span class="fulfillment-option-label">
                            <i class="fas fa-truck"></i>
                            <strong>Delivery</strong>
                            <small>Deliver to your address</small>
                        </span>
                    </label>
                </div>
            </div>

            <div class="form-group" id="f_delivery_group" style="display:none;">
                <label><i class="fas fa-map-marker-alt"></i> Delivery Address *</label>
                <textarea name="delivery_address" id="f_delivery_address" class="form-control" rows="2" placeholder="House no., street, barangay, city"></textarea>
            </div>

            <div class="form-group">
                <label><i class="fas fa-calendar-alt"></i> Preferred Date *</label>
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
                        <span><span class="dot selected"></span> Selected</span>
                    </div>
                    <div class="calendar-info" id="foodCalendarInfo">Select a date for your food order</div>
                </div>
                <input type="hidden" name="preferred_date" id="f_preferred_date" required>
            </div>

            <div class="form-group">
                <label><i class="fas fa-clock"></i> Preferred Time *</label>
                <select name="preferred_time" id="f_preferred_time" class="form-control" required>
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
                <textarea name="special_requests" id="f_special_requests" class="form-control" rows="3" placeholder="e.g., Food allergies, dietary restrictions, less spicy, extra rice..."></textarea>
            </div>

            <div class="order-summary-box">
                <div class="summary-row">
                    <span><i class="fas fa-money-bill-wave" style="color:#10b981;"></i> <strong>Total Amount:</strong></span>
                    <span class="total-amount" id="f_price_display">₱0.00</span>
                </div>
            </div>

            <div class="warning-banner">
                <i class="fas fa-info-circle"></i>
                <span><strong>Note:</strong> Your reservation will be reviewed by admin. Please upload your payment proof in your profile to finalize.</span>
            </div>

            <button type="submit" class="btn-primary-submit">
                <i class="fas fa-check-circle"></i> Submit Reservation
            </button>
        </form>

        <?php endif; ?>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: ADD TOUR (unchanged)                                  -->
<!-- ============================================================ -->
<div class="modal" id="tourModal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3><i class="fas fa-ship"></i> Book This Tour</h3>
            <button type="button" class="close" onclick="closeModal('tour')">&times;</button>
        </div>

        <?php if (isset($modal_error) && $open_modal === 'tour'): ?>
        <div class="modal-error" style="background:#fee2e2;border-left:4px solid #ef4444;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;display:flex;gap:10px;">
            <i class="fas fa-exclamation-circle" style="color:#ef4444;font-size:16px;flex-shrink:0;margin-top:2px;"></i>
            <span><?php echo htmlspecialchars($modal_error); ?></span>
        </div>
        <?php endif; ?>

        <?php if (empty($tours)): ?>
            <div style="text-align:center;padding:40px 20px;color:#94a3b8;">
                <i class="fas fa-umbrella-beach" style="font-size:48px;color:#cbd5e1;display:block;margin-bottom:15px;"></i>
                <p>No tours available at the moment.</p>
            </div>
        <?php else: ?>

        <div id="tourStep1">
            <p style="color:#64748b; font-size:13px; margin-bottom:15px; line-height:1.5;">
                <i class="fas fa-info-circle" style="color:#4DA6D9;"></i>
                Tap a tour to book it. Click <strong>View</strong> to see full details.
            </p>
            <div class="item-pick-list">
                <?php foreach ($tours as $tour):
                    if (!is_array($tour)) continue;
                    $thumbSrc = null;
                    if (!empty($tour_galleries[$tour['id']][0])) $thumbSrc = $tour_galleries[$tour['id']][0];
                    elseif (!empty($tour['image']) && $tour['image'] !== 'default-tour.jpg' && file_exists('uploads/tours/' . $tour['image'])) $thumbSrc = 'uploads/tours/' . $tour['image'];

                    $shortDesc = trim($tour['description'] ?? '');
                    if (mb_strlen($shortDesc) > 80) $shortDesc = mb_substr($shortDesc, 0, 80) . '…';
                ?>
                <div class="item-pick"
                     data-id="<?php echo (int)$tour['id']; ?>"
                     data-name="<?php echo htmlspecialchars($tour['tour_name'] ?? '', ENT_QUOTES); ?>"
                     data-price="<?php echo (float)($tour['price_per_boat'] ?? 0); ?>"
                     data-max="<?php echo (int)($tour['max_guests'] ?? 0); ?>"
                     onclick="selectTour(this)">

                    <div class="item-pick-thumb tour">
                        <?php if ($thumbSrc): ?>
                            <img src="<?php echo htmlspecialchars($thumbSrc); ?>?v=<?php echo time(); ?>"
                                 alt="<?php echo htmlspecialchars($tour['tour_name'] ?? ''); ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <span class="thumb-icon" style="display:none;"><i class="fas fa-umbrella-beach"></i></span>
                        <?php else: ?>
                            <span class="thumb-icon"><i class="fas fa-umbrella-beach"></i></span>
                        <?php endif; ?>
                    </div>

                    <div class="item-pick-info">
                        <strong><?php echo htmlspecialchars($tour['tour_name'] ?? ''); ?></strong>
                        <div class="ip-price">₱<?php echo number_format((float)($tour['price_per_boat'] ?? 0)); ?><small>/boat</small></div>
                        <div class="ip-meta">
                            <span class="ip-chip green"><i class="fas fa-users"></i> max <?php echo (int)($tour['max_guests'] ?? 0); ?> pax</span>
                            <span class="ip-chip"><i class="fas fa-map-marked-alt"></i> 12–14 islands</span>
                        </div>
                        <?php if ($shortDesc): ?>
                            <div class="ip-desc"><?php echo htmlspecialchars($shortDesc); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="item-pick-actions">
                        <button type="button" class="btn-item-view tour"
                                onclick="event.stopPropagation(); openItemDetail('tour_<?php echo (int)$tour['id']; ?>')"
                                title="View full details">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <i class="fas fa-chevron-right ip-arrow"></i>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="POST" id="tourForm" style="display:none;">
            <input type="hidden" name="add_tour_to_package" value="1">
            <input type="hidden" name="tour_id" id="t_tour_id">
            <input type="hidden" name="total_price" id="t_total_price">
            <input type="hidden" name="booking_date" id="t_booking_date" required>

            <button type="button" class="btn-back-list" onclick="backToTourList()">
                <i class="fas fa-arrow-left"></i> Back to list
            </button>

            <div class="form-group">
                <label><i class="fas fa-ship"></i> Tour Package</label>
                <input type="text" id="t_display_tour" class="form-control" readonly>
            </div>

            <div class="form-group">
                <label><i class="fas fa-tag"></i> Price per Boat</label>
                <input type="text" id="t_display_price" class="form-control" readonly>
            </div>

            <div class="form-group">
                <label><i class="fas fa-calendar-alt"></i> Booking Date *</label>
                <div class="calendar-container">
                    <div class="calendar-header">
                        <h4 id="tourCalendarMonthYear"></h4>
                        <div class="calendar-nav">
                            <button type="button" onclick="changeTourMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                            <button type="button" onclick="changeTourMonth(1)"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    <div class="calendar-grid" id="tourCalendarGrid"></div>
                    <div class="calendar-legend">
                        <span><span class="dot available"></span> Available</span>
                        <span><span class="dot booked"></span> Booked</span>
                        <span><span class="dot selected"></span> Selected</span>
                    </div>
                    <div class="calendar-info" id="tourCalendarInfo">Select a date for your tour</div>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-user"></i> Guest Name *</label>
                <input type="text" name="guest_name" id="t_guest_name" class="form-control" required placeholder="Full name of lead guest" maxlength="100">
            </div>

            <div class="form-group">
                <label><i class="fas fa-phone"></i> Contact Number *</label>
                <div class="phone-input-group">
                    <select class="phone-suffix-select" disabled>
                        <option value="+63" selected>+63 🇵🇭</option>
                    </select>
                    <div class="phone-input-wrapper">
                        <i class="fas fa-phone phone-icon"></i>
                        <input type="tel" name="contact_number" id="t_contact" class="form-control" placeholder="9123456789" maxlength="10" inputmode="numeric" required oninput="validatePhone(this, 't_contact_count')">
                        <span class="digit-count" id="t_contact_count">0/10</span>
                    </div>
                </div>
                <div class="helper-text"><i class="fas fa-info-circle"></i> Enter 10-digit PH mobile number (e.g., 9123456789)</div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-clock"></i> Preferred Time *</label>
                <select name="preferred_time" id="t_preferred_time" class="form-control" required>
                    <option value="">Select Time Slot</option>
                    <option value="06:00:00">6:00 AM — Sunrise</option>
                    <option value="07:00:00">7:00 AM</option>
                    <option value="08:00:00">8:00 AM</option>
                    <option value="09:00:00">9:00 AM</option>
                    <option value="10:00:00">10:00 AM</option>
                    <option value="11:00:00">11:00 AM</option>
                    <option value="12:00:00">12:00 PM</option>
                    <option value="13:00:00">1:00 PM</option>
                    <option value="14:00:00">2:00 PM</option>
                    <option value="15:00:00">3:00 PM</option>
                    <option value="16:00:00">4:00 PM — Sunset</option>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-users"></i> Number of Pax *</label>
                <input type="number" name="number_of_guests" id="t_guests" class="form-control" min="1" value="1" required>
                <div class="helper-text">Max capacity: <span id="t_max_label">0</span> pax</div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-comment"></i> Special Requests</label>
                <textarea name="special_requests" id="t_special_requests" class="form-control" rows="2" placeholder="Any dietary restrictions, special occasions..."></textarea>
            </div>

            <div class="tour-summary-box">
                <div class="tour-summary-row">
                    <span><i class="fas fa-users" style="color:#4DA6D9;"></i> Number of Pax:</span>
                    <strong id="t_summary_pax">1</strong>
                </div>
                <div class="tour-summary-row">
                    <span><i class="fas fa-money-bill-wave" style="color:#10b981;"></i> Total amount:</span>
                    <span class="tour-total-value" id="t_summary_total">₱0.00</span>
                </div>
            </div>

            <div class="tour-warning">
                <i class="fas fa-info-circle"></i>
                <span><strong>Note:</strong> Your booking will be reviewed by admin. Please upload your payment proof after confirmation.</span>
            </div>

            <button type="submit" class="btn-submit-modal">
                <i class="fas fa-check"></i> Add Tour to Package
            </button>
        </form>

        <?php endif; ?>
    </div>
</div>

<!-- ============================================================ -->
<!-- SHOPEE-STYLE ITEM DETAIL MODAL                                -->
<!-- ============================================================ -->
<div class="house-detail-modal" id="houseDetailModal" onclick="if(event.target === this) closeHouseDetail()">
    <div class="house-detail-content">
        <button class="house-detail-close" onclick="closeHouseDetail()" aria-label="Close">
            <i class="fas fa-times"></i>
        </button>

        <div class="house-detail-scroll">
            <div class="hd-gallery">
                <img id="hdMainImage" class="hd-main-img" src="" alt="Item Photo">
                <button type="button" class="hd-gallery-nav hd-gallery-prev" onclick="changeHdImage(-1)">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button type="button" class="hd-gallery-nav hd-gallery-next" onclick="changeHdImage(1)">
                    <i class="fas fa-chevron-right"></i>
                </button>
                <div class="hd-gallery-counter" id="hdCounter">1 / 1</div>
            </div>

            <div class="hd-thumbs" id="hdThumbs"></div>

            <div class="hd-header-block">
                <h2 class="hd-name" id="hdName">Item Name</h2>
                <div class="hd-price-row">
                    <span class="hd-price" id="hdPrice">₱0</span>
                    <span class="hd-price-unit" id="hdPriceUnit">per night</span>
                </div>
                <div class="hd-quick-tags" id="hdQuickTags"></div>
            </div>

            <div class="hd-section" id="hdDescSection">
                <h3 class="hd-section-title"><i class="fas fa-info-circle"></i> Description</h3>
                <p class="hd-description" id="hdDescription"></p>
            </div>

            <div class="hd-section" id="hdSpecsSection">
                <h3 class="hd-section-title"><i class="fas fa-list-ul"></i> Specifications</h3>
                <div class="hd-specs" id="hdSpecs"></div>
            </div>

            <div class="hd-section" id="hdVariationsSection" style="display:none;">
                <h3 class="hd-section-title"><i class="fas fa-box"></i> Available Sizes & Pricing</h3>
                <div class="hd-variations" id="hdVariations"></div>
            </div>

            <div class="hd-section" id="hdAmenitiesSection">
                <h3 class="hd-section-title" id="hdAmenitiesTitle"><i class="fas fa-check-circle"></i> Inclusions</h3>
                <div class="hd-amenities" id="hdAmenities"></div>
            </div>
        </div>

        <div class="hd-footer">
            <button type="button" class="btn-hd-close" onclick="closeHouseDetail()">
                <i class="fas fa-times"></i> Close
            </button>
            <button type="button" class="btn-hd-select" id="hdSelectBtn">
                <i class="fas fa-plus-circle"></i> Select This Item
            </button>
        </div>
    </div>
</div>

<!-- OVERALL FEEDBACK MODAL (unchanged) -->
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
                                <i class="fas fa-star" style="color: <?php echo $i <= (int)($user_feedback['rating'] ?? 0) ? '#f59e0b' : '#cbd5e1'; ?>;"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="prev-hint">Update your rating and review below</div>
                    </div>

                    <label class="overall-rating-label">Your Overall Rating</label>

                    <form method="POST" action="packages.php" id="overallFeedbackForm">
                        <input type="hidden" name="submit_feedback" value="1">
                        <input type="hidden" name="rating" id="overall_feedback_rating" value="<?php echo (int)($user_feedback['rating'] ?? 0); ?>" required>

                        <div class="big-rating-input" id="bigRatingInput">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?php echo (int)($user_feedback['rating'] ?? 0) >= $i ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>" onclick="setBigRating(<?php echo $i; ?>)" onmouseenter="previewBigRating(<?php echo $i; ?>)"></i>
                            <?php endfor; ?>
                        </div>

                        <div class="rating-word" id="bigRatingWord">
                            <?php
                            $ratingTexts = [1=>'Very Poor', 2=>'Poor', 3=>'Average', 4=>'Good', 5=>'Excellent!'];
                            echo $ratingTexts[(int)($user_feedback['rating'] ?? 0)] ?? 'Average';
                            ?>
                        </div>

                        <label class="review-section-label" for="overall_comment">Your Comment</label>
                        <textarea name="comment" id="overall_comment" class="review-textarea" rows="3"><?php echo htmlspecialchars($user_feedback['comment'] ?? ''); ?></textarea>

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

                    <form method="POST" action="packages.php" id="overallFeedbackForm">
                        <input type="hidden" name="submit_feedback" value="1">
                        <input type="hidden" name="rating" id="overall_feedback_rating" value="0" required>

                        <div class="big-rating-input" id="bigRatingInput">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star" data-rating="<?php echo $i; ?>" onclick="setBigRating(<?php echo $i; ?>)" onmouseenter="previewBigRating(<?php echo $i; ?>)"></i>
                            <?php endfor; ?>
                        </div>

                        <div class="rating-word" id="bigRatingWord">Select a rating</div>

                        <label class="review-section-label" for="overall_comment">Your Comment</label>
                        <textarea name="comment" id="overall_comment" class="review-textarea" rows="3"></textarea>

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
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($booking_success): 
    $gcash_name   = $booking_success['gcash_name']   ?? 'Juan Dela Cruz';
    $gcash_number = $booking_success['gcash_number'] ?? '09123456789';
    $qr_path      = $booking_success['qr_image']     ?? '';
    $qr_exists    = !empty($booking_success['qr_exists']) 
                  ? $booking_success['qr_exists'] 
                  : (!empty($qr_path) && file_exists($qr_path) && !is_dir($qr_path));
    $qr_version   = $qr_exists ? @filemtime($qr_path) : time();
    $pkg_label    = $booking_success['package_name'] ?? 'Package Booking';
    $guest_name   = $booking_success['guest_name']   ?? '';
    $booking_ref  = $booking_success['reference']    ?? '';
    $booking_total = $booking_success['total']       ?? 0;
?>
<div class="gcash-popup-overlay show" id="gcashSuccessPopup">
    <div class="gcash-popup">
        <div class="gcash-success-icon">
            <i class="fas fa-check"></i>
        </div>

        <h3 class="gcash-title">Booking Submitted! 🎉</h3>
        <p class="gcash-subtitle">Please complete your payment via GCash</p>

        <div class="gcash-divider"></div>

        <div class="gcash-ref-badge">
            <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking_ref); ?>
        </div>

        <div class="gcash-detail-box">
            <div class="gcash-detail-row">
                <span><?php echo htmlspecialchars($pkg_label); ?></span>
                <strong><?php echo htmlspecialchars($guest_name); ?></strong>
            </div>
            <div class="gcash-detail-row">
                <span>Total Amount</span>
                <strong class="gcash-amount">₱<?php echo number_format((float)$booking_total, 2); ?></strong>
            </div>
        </div>

        <div class="gcash-qr-box">
            <?php if ($qr_exists): ?>
                <img src="<?php echo htmlspecialchars($qr_path); ?>?v=<?php echo $qr_version; ?>" 
                     alt="GCash QR Code"
                     onerror="this.onerror=null; this.src='uploads/gcash/gcash_qr.png?v=<?php echo time(); ?>';">
            <?php else: ?>
                <div class="gcash-qr-placeholder">
                    <i class="fas fa-qrcode"></i>
                    <small>GCash QR Code not uploaded yet</small>
                    <small style="font-size:10px; margin-top:4px; color:#94a3b8;">Please contact admin</small>
                </div>
            <?php endif; ?>
        </div>

        <div class="gcash-account-box">
            <div class="gcash-account-row">
                <span>Account Name:</span>
                <strong><?php echo htmlspecialchars($gcash_name); ?></strong>
            </div>
            <div class="gcash-account-row">
                <span>GCash Number:</span>
                <strong><?php echo htmlspecialchars($gcash_number); ?></strong>
            </div>
        </div>

        <div class="gcash-howto-box">
            <div class="gcash-howto-title">
                <i class="fas fa-info-circle"></i> How to Pay:
            </div>
            <ol class="gcash-howto-list">
                <li>Open GCash app</li>
                <li>Click 'Pay QR' or 'Scan QR'</li>
                <li>Scan the QR code above</li>
                <li>Enter the exact amount shown</li>
                <li>Complete the payment</li>
                <li>Take a screenshot of the transaction</li>
                <li>Upload screenshot as proof of payment</li>
            </ol>
        </div>

        <div class="gcash-status-banner">
            <i class="fas fa-clock"></i> Status: Pending Admin Confirmation
        </div>

        <div class="gcash-actions">
            <a href="profile.php?tab=packages&upload_proof=<?php echo urlencode($booking_ref); ?>" class="gcash-btn-upload">
                <i class="fas fa-upload"></i> Upload Proof
            </a>
            <a href="profile.php?tab=packages" class="gcash-btn-close">
                <i class="fas fa-times"></i> Close
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CONFIRMATION MODALS -->
<div class="confirmation-modal-overlay" id="logoutModal">
    <div class="confirmation-modal">
        <div class="confirmation-modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <h3>Logout?</h3>
        <p>Are you sure you want to sign out from your account?</p>
        <div class="confirmation-modal-actions">
            <button type="button" class="btn-confirm-no" onclick="closeLogoutModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="logout.php" class="btn-confirm-yes">
                <i class="fas fa-sign-out-alt"></i> Yes, Logout
            </a>
        </div>
    </div>
</div>

<div class="confirmation-modal-overlay" id="clearAllModal">
    <div class="confirmation-modal">
        <div class="confirmation-modal-icon">
            <i class="fas fa-trash-alt"></i>
        </div>
        <h3>Clear All Items?</h3>
        <p>This will remove <strong>all items</strong> from your package. This action cannot be undone.</p>
        <div class="confirmation-modal-actions">
            <button type="button" class="btn-confirm-no" onclick="closeClearAllModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="?clear=1" class="btn-confirm-yes">
                <i class="fas fa-trash-alt"></i> Yes, Clear All
            </a>
        </div>
    </div>
</div>

<div class="confirmation-modal-overlay" id="removeItemModal">
    <div class="confirmation-modal">
        <div class="confirmation-modal-icon">
            <i class="fas fa-times-circle"></i>
        </div>
        <h3>Remove Item?</h3>
        <p>Are you sure you want to remove <strong id="removeItemName">this item</strong> from your package?</p>
        <div class="confirmation-modal-actions">
            <button type="button" class="btn-confirm-no" onclick="closeRemoveModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="#" id="removeItemLink" class="btn-confirm-yes">
                <i class="fas fa-trash-alt"></i> Yes, Remove
            </a>
        </div>
    </div>
</div>

<div class="modal terms-modal" id="bookingTermsModal">
    <div class="terms-modal-content">
        <div class="terms-modal-header">
            <div class="terms-modal-icon">
                <i class="fas fa-file-contract"></i>
            </div>
            <h3>Booking Terms &amp; Conditions</h3>
            <p>Please read carefully before confirming</p>
            <button type="button" class="terms-modal-close" id="bookingTermsCloseBtn" onclick="closeBookingTermsModal()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="terms-scroll-container" id="bookingTermsScrollContainer">
            <div class="terms-section">
                <div class="terms-section-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Important: No Refund Policy</span>
                </div>
                <div class="terms-section-body">
                    <h3>❌ No Cancellation / No Refund</h3>
                    <ul>
                        <li>All bookings are <strong>final and non-cancellable</strong>.</li>
                        <li>Once a booking is confirmed, it <strong>cannot be cancelled</strong> and payments are <strong>non-refundable</strong>.</li>
                        <li>No-shows will result in <strong>full forfeiture</strong> of payment.</li>
                    </ul>

                    <h3>🔄 Rebooking Policy</h3>
                    <ul>
                        <li>Guests may <strong>rebook</strong> instead of cancelling.</li>
                        <li>Rebooking is allowed a maximum of <strong>2 times per booking</strong>.</li>
                        <li>Rebooking must be requested within <strong>7 days from the original booking date</strong>.</li>
                        <li>The <strong>stay duration (number of nights) must remain the same</strong> when rebooking.</li>
                        <li>Rebooking is subject to <strong>admin approval</strong> and availability.</li>
                    </ul>

                    <h3>💳 Payment Terms</h3>
                    <ul>
                        <li>Payment must be completed to confirm your booking.</li>
                        <li>Proof of payment (GCash reference + screenshot) must be uploaded through your profile.</li>
                        <li>Bookings with pending payments may be subject to cancellation by admin.</li>
                    </ul>

                    <h3>👥 Guest Policy</h3>
                    <ul>
                        <li>The number of guests must not exceed the <strong>declared pax count</strong>.</li>
                        <li>All guest names must be <strong>accurate and complete</strong>.</li>
                        <li>Additional guests beyond the declared pax will not be accommodated.</li>
                    </ul>

                    <h3>📋 Package Booking</h3>
                    <ul>
                        <li>All items in this package (House, Food, Tour) will be booked together.</li>
                        <li>Partial cancellation of package items is <strong>not allowed</strong>.</li>
                        <li>Changes to any item must be made before confirming.</li>
                    </ul>

                    <h3>✅ Acknowledgment</h3>
                    <p>By checking the box below and clicking <strong>"I Understand &amp; Confirm Booking"</strong>, you acknowledge that you have read, understood, and agreed to these terms — including the <strong>no cancellation policy</strong> and <strong>no refund policy</strong>.</p>
                </div>
            </div>
        </div>

        <div class="terms-scroll-hint" id="bookingTermsScrollHint">
            <i class="fas fa-arrow-down"></i> Scroll to the bottom to enable the checkbox
        </div>

        <form method="POST" id="bookingTermsAcceptForm">
            <label class="terms-checkbox-label" for="booking_terms_agree">
                <input type="checkbox" id="booking_terms_agree" value="1" disabled>
                <span class="terms-checkbox-text">
                    <i class="fas fa-check-circle" style="color: #10b981;"></i>
                    I understand there is <strong>NO CANCELLATION</strong> and <strong>NO REFUND</strong>. I accept these terms.
                </span>
            </label>
            <button type="submit" name="confirm_package_booking" class="terms-accept-btn" id="bookingTermsAcceptBtn" disabled>
                <i class="fas fa-check-circle"></i> I Understand &amp; Confirm Booking
            </button>
        </form>

        <p class="terms-modal-footer-note">
            Your booking will be reviewed by admin after confirmation.
        </p>
    </div>
</div>

<!-- FOOTER -->
<?php include 'components/footer.php'; ?>

<script>
const HOUSE_BOOKED_DATES = <?php echo json_encode($house_booked_dates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const TOUR_BOOKED_DATES  = <?php echo json_encode($tour_booked_dates,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const FOOD_BOOKED_DATES  = <?php echo json_encode($food_booked_dates,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const ITEMS_DETAIL = <?php echo json_encode($items_detail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const CART_DATA = <?php echo json_encode($cart_data_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const hd = document.getElementById('houseDetailModal');
        if (hd && hd.classList.contains('show')) { closeHouseDetail(); return; }
        const fb = document.getElementById('overallFeedbackModal');
        if (fb && fb.classList.contains('show')) closeOverallFeedbackModal();
    }
});

function openModal(type, editId) {
    if (type === 'house') {
        document.getElementById('houseStep1').style.display = 'block';
        document.getElementById('houseForm').style.display = 'none';
    }
    if (type === 'food') {
        document.getElementById('foodStep1').style.display = 'block';
        document.getElementById('foodForm').style.display = 'none';
    }
    if (type === 'tour') {
        document.getElementById('tourStep1').style.display = 'block';
        document.getElementById('tourForm').style.display = 'none';
    }
    document.getElementById(type + 'Modal').classList.add('show');
    document.body.style.overflow = 'hidden';

    if (editId) {
        setTimeout(function() {
            const pick = document.querySelector('#' + type + 'Modal .item-pick[data-id="' + editId + '"]');
            if (pick) pick.click();
        }, 100);
    }
}

function closeModal(type) {
    document.getElementById(type + 'Modal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        if (e.target.id === 'bookingTermsModal') return;
        e.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
});

function openLogoutModal(event) {
    if (event) event.preventDefault();
    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();
    document.getElementById('logoutModal').classList.add('show');
    document.body.style.overflow = 'hidden';
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

function openClearAllModal() {
    document.getElementById('clearAllModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeClearAllModal() {
    document.getElementById('clearAllModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('clearAllModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeClearAllModal();
        });
    }
});

function openRemoveModal(type, name) {
    document.getElementById('removeItemName').textContent = name;
    document.getElementById('removeItemLink').href = '?remove=' + type;
    document.getElementById('removeItemModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeRemoveModal() {
    document.getElementById('removeItemModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('removeItemModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeRemoveModal();
        });
    }
});

var __bookingTermsState = { hasReachedBottom: false };

function openBookingTermsModal() {
    var modal = document.getElementById('bookingTermsModal');
    if (!modal) return;

    __bookingTermsState.hasReachedBottom = false;

    var acceptForm = document.getElementById('bookingTermsAcceptForm');
    var scrollHint = document.getElementById('bookingTermsScrollHint');
    var scrollBox  = document.getElementById('bookingTermsScrollContainer');
    var checkbox   = document.getElementById('booking_terms_agree');
    var acceptBtn  = document.getElementById('bookingTermsAcceptBtn');

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
        if (!needsScroll) unlockBookingTermsAcceptForm();
    }, 100);

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function unlockBookingTermsAcceptForm() {
    if (__bookingTermsState.hasReachedBottom) return;
    __bookingTermsState.hasReachedBottom = true;

    var acceptForm = document.getElementById('bookingTermsAcceptForm');
    var scrollHint = document.getElementById('bookingTermsScrollHint');
    var checkbox   = document.getElementById('booking_terms_agree');

    acceptForm.classList.add('visible');
    checkbox.disabled = false;

    scrollHint.classList.add('done');
    scrollHint.innerHTML = '<i class="fas fa-check-circle"></i> You\'ve read everything. Please check the box below.';
}

// Footer "Privacy Policy" / "Terms & Conditions" links call this on every page that renders the shared footer
function reopenTermsModal(tabName) {
    if (document.getElementById('bookingTermsModal')) {
        openBookingTermsModal();
    }
    return false;
}

function closeBookingTermsModal() {
    var modal = document.getElementById('bookingTermsModal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = 'auto';
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

// ✅ UPDATED: editHouse restores check-in/check-out TIMES
function editHouse() {
    if (!CART_DATA || !CART_DATA.house) return;
    var d = CART_DATA.house;

    openModal('house');

    var pick = document.querySelector('#houseModal .item-pick[data-id="' + d.id + '"]');
    if (!pick) return;

    setTimeout(function() {
        pick.click();

        setTimeout(function() {
            var guestsInput = document.getElementById('h_guests');
            guestsInput.value = d.guests;
            document.getElementById('h_max_capacity').textContent = guestsInput.max;

            rebuildGuestNameInputs();
            var nameInputs = document.querySelectorAll('#guestNamesList .guest-name-input');
            d.guest_names.forEach(function(name, i) {
                if (nameInputs[i]) {
                    nameInputs[i].value = name;
                    nameInputs[i].classList.add('valid');
                }
            });
            updateGuestCountBadge();
            syncGuestNamesHidden();

            houseSelectedStart = d.check_in;
            houseSelectedEnd = d.check_out;
            document.getElementById('h_check_in').value = d.check_in;
            document.getElementById('h_check_in_display').value = d.check_in;
            document.getElementById('h_check_out').value = d.check_out;

            // ✅ Restore times
            if (d.check_in_time)  document.getElementById('h_check_in_time').value  = d.check_in_time;
            if (d.check_out_time) document.getElementById('h_check_out_time').value = d.check_out_time;

            document.getElementById('houseCalendarInfo').innerHTML =
                '✅ Stay: <strong>' + formatDateDisplay(d.check_in) + '</strong> → <strong>' + formatDateDisplay(d.check_out) + '</strong>';

            renderHouseCalendar(houseCalMonth, houseCalYear);
            calcHouseTotal();
        }, 120);
    }, 150);
}

function editFood() {
    if (!CART_DATA || !CART_DATA.food) return;
    var d = CART_DATA.food;

    openModal('food');

    var pick = document.querySelector('#foodModal .item-pick[data-id="' + d.id + '"]');
    if (!pick) return;

    setTimeout(function() {
        pick.click();

        setTimeout(function() {
            if (d.size_variant_index !== null && d.size_variant_index !== undefined) {
                var sizeSelect = document.getElementById('f_size_variant');
                if (sizeSelect && sizeSelect.options.length > d.size_variant_index) {
                    sizeSelect.selectedIndex = d.size_variant_index;
                    calcFoodTotal();
                }
            }

            foodSelectedDate = d.preferred_date;
            document.getElementById('f_preferred_date').value = d.preferred_date;

            renderFoodCalendar(foodCalMonth, foodCalYear);
            var infoEl = document.getElementById('foodCalendarInfo');
            if (infoEl) {
                infoEl.innerHTML = '✅ Selected: <strong>' + formatDateDisplay(d.preferred_date) + '</strong>';
                infoEl.classList.add('success');
            }

            document.getElementById('f_preferred_time').value = d.preferred_time;

            var phoneVal = (d.contact_number || '').replace(/^\+63/, '').replace(/^0/, '');
            document.getElementById('f_contact').value = phoneVal;
            validatePhone(document.getElementById('f_contact'), 'f_contact_count');

            var radios = document.querySelectorAll('input[name="fulfillment_method"]');
            radios.forEach(function(r) {
                r.checked = (r.value === d.fulfillment_method);
            });
            toggleDelivery();
            if (d.fulfillment_method === 'delivery') {
                document.getElementById('f_delivery_address').value = d.delivery_address || '';
            }

            document.getElementById('f_special_requests').value = d.special_requests || '';
        }, 120);
    }, 150);
}

function editTour() {
    if (!CART_DATA || !CART_DATA.tour) return;
    var d = CART_DATA.tour;

    openModal('tour');

    var pick = document.querySelector('#tourModal .item-pick[data-id="' + d.id + '"]');
    if (!pick) return;

    setTimeout(function() {
        pick.click();

        setTimeout(function() {
            tourSelectedDate = d.booking_date;
            document.getElementById('t_booking_date').value = d.booking_date;
            renderTourCalendar(tourCalMonth, tourCalYear);
            var infoEl = document.getElementById('tourCalendarInfo');
            if (infoEl) infoEl.innerHTML = '✅ Date selected: <strong>' + formatDateDisplay(d.booking_date) + '</strong>';

            document.getElementById('t_preferred_time').value = d.preferred_time;
            document.getElementById('t_guest_name').value = d.guest_name;

            var phoneVal = (d.contact_number || '').replace(/^\+63/, '').replace(/^0/, '');
            document.getElementById('t_contact').value = phoneVal;
            validatePhone(document.getElementById('t_contact'), 't_contact_count');

            document.getElementById('t_guests').value = d.number_of_guests;
            updateTourSummary();

            document.getElementById('t_special_requests').value = d.special_requests || '';
        }, 120);
    }, 150);
}

var hdImages = [];
var hdIndex = 0;
var hdCurrentItemId = 0;
var hdCurrentItemType = '';

function openItemDetail(detailKey) {
    var item = ITEMS_DETAIL[detailKey];
    if (!item) return;

    var numericId = item.id;
    var itemType = item.type || 'house';

    hdCurrentItemId = numericId;
    hdCurrentItemType = itemType;
    hdImages = item.gallery || [];
    hdIndex = 0;

    document.getElementById('hdName').textContent = item.name;
    document.getElementById('hdPrice').textContent = '₱' + Number(item.price).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('hdPriceUnit').textContent = item.price_unit || '';

    var tagsHtml = '';
    if (itemType === 'house') {
        if (item.capacity) tagsHtml += '<span class="hd-tag green"><i class="fas fa-users"></i> Up to ' + item.capacity + ' pax</span>';
        if (item.bedrooms) tagsHtml += '<span class="hd-tag"><i class="fas fa-bed"></i> ' + item.bedrooms + ' bedroom' + (item.bedrooms > 1 ? 's' : '') + '</span>';
        if (item.amenities && item.amenities.length) tagsHtml += '<span class="hd-tag gold"><i class="fas fa-check-circle"></i> ' + item.amenities.length + ' inclusions</span>';
    } else if (itemType === 'food') {
        if (item.category) tagsHtml += '<span class="hd-tag gold"><i class="fas fa-tag"></i> ' + escapeHtml(item.category.replace(/_/g, ' ')) + '</span>';
        if (item.pax_range) tagsHtml += '<span class="hd-tag green"><i class="fas fa-users"></i> ' + escapeHtml(item.pax_range) + '</span>';
        if (item.variations && item.variations.length) tagsHtml += '<span class="hd-tag"><i class="fas fa-box"></i> ' + item.variations.length + ' sizes available</span>';
    } else if (itemType === 'tour') {
        if (item.capacity) tagsHtml += '<span class="hd-tag green"><i class="fas fa-users"></i> Max ' + item.capacity + ' pax</span>';
        if (item.amenities && item.amenities.length) tagsHtml += '<span class="hd-tag gold"><i class="fas fa-check-circle"></i> ' + item.amenities.length + ' inclusions</span>';
    }
    document.getElementById('hdQuickTags').innerHTML = tagsHtml;

    var desc = (item.description || '').trim();
    if (desc) {
        document.getElementById('hdDescSection').style.display = 'block';
        document.getElementById('hdDescription').textContent = desc;
    } else {
        document.getElementById('hdDescSection').style.display = 'none';
    }

    var specsHtml = '';
    if (itemType === 'house') {
        specsHtml += '<div class="hd-spec-item"><i class="fas fa-users"></i><span class="hd-spec-value">' + (item.capacity || '—') + '</span><span class="hd-spec-label">Max Pax</span></div>';
        specsHtml += '<div class="hd-spec-item"><i class="fas fa-bed"></i><span class="hd-spec-value">' + (item.bedrooms || '—') + '</span><span class="hd-spec-label">Bedrooms</span></div>';
        specsHtml += '<div class="hd-spec-item"><i class="fas fa-tag"></i><span class="hd-spec-value">₱' + Number(item.price).toLocaleString() + '</span><span class="hd-spec-label">Per Night</span></div>';
    } else if (itemType === 'food') {
        if (item.category) specsHtml += '<div class="hd-spec-item"><i class="fas fa-tag"></i><span class="hd-spec-value">' + escapeHtml(item.category.replace(/_/g, ' ')) + '</span><span class="hd-spec-label">Category</span></div>';
        if (item.pax_range) specsHtml += '<div class="hd-spec-item"><i class="fas fa-users"></i><span class="hd-spec-value">' + escapeHtml(item.pax_range) + '</span><span class="hd-spec-label">Pax Range</span></div>';
        if (item.variations && item.variations.length) specsHtml += '<div class="hd-spec-item"><i class="fas fa-box"></i><span class="hd-spec-value">' + item.variations.length + '</span><span class="hd-spec-label">Sizes</span></div>';
        specsHtml += '<div class="hd-spec-item"><i class="fas fa-money-bill-wave"></i><span class="hd-spec-value">₱' + Number(item.price).toLocaleString() + '</span><span class="hd-spec-label">Base Price</span></div>';
    } else if (itemType === 'tour') {
        specsHtml += '<div class="hd-spec-item"><i class="fas fa-users"></i><span class="hd-spec-value">' + (item.capacity || '—') + '</span><span class="hd-spec-label">Max Pax</span></div>';
        specsHtml += '<div class="hd-spec-item"><i class="fas fa-map-marked-alt"></i><span class="hd-spec-value">' + escapeHtml(item.destinations || '12–14 islands') + '</span><span class="hd-spec-label">Destinations</span></div>';
        specsHtml += '<div class="hd-spec-item"><i class="fas fa-tag"></i><span class="hd-spec-value">₱' + Number(item.price).toLocaleString() + '</span><span class="hd-spec-label">Per Boat</span></div>';
    }
    document.getElementById('hdSpecs').innerHTML = specsHtml;
    document.getElementById('hdSpecsSection').style.display = specsHtml ? 'block' : 'none';

    if (itemType === 'food' && item.variations && item.variations.length > 0) {
        var varHtml = '';
        item.variations.forEach(function(v) {
            varHtml += '<div class="hd-variation">';
            varHtml += '<span class="hd-var-label">' + escapeHtml(v.size || 'Size') + (v.pax ? ' <small>(' + escapeHtml(v.pax) + ')</small>' : '') + '</span>';
            varHtml += '<span class="hd-var-price">₱' + Number(v.price).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</span>';
            varHtml += '</div>';
        });
        document.getElementById('hdVariations').innerHTML = varHtml;
        document.getElementById('hdVariationsSection').style.display = 'block';
    } else {
        document.getElementById('hdVariationsSection').style.display = 'none';
    }

    var amenHtml = '';
    var amenTitle = 'Inclusions & Amenities';
    if (itemType === 'tour') amenTitle = 'Tour Inclusions';
    if (itemType === 'food') amenTitle = 'What\'s Included';
    document.getElementById('hdAmenitiesTitle').innerHTML = '<i class="fas fa-check-circle"></i> ' + amenTitle;

    if (item.amenities && item.amenities.length > 0) {
        item.amenities.forEach(function(a) {
            if (!a) return;
            var icon = getAmenityIcon(a);
            amenHtml += '<div class="hd-amenity"><i class="fas ' + icon + '"></i><span>' + escapeHtml(a) + '</span></div>';
        });
        document.getElementById('hdAmenitiesSection').style.display = 'block';
    } else {
        document.getElementById('hdAmenitiesSection').style.display = 'none';
    }
    document.getElementById('hdAmenities').innerHTML = amenHtml;

    renderHdGallery();

    var selectBtn = document.getElementById('hdSelectBtn');
    if (itemType === 'house') {
        selectBtn.className = 'btn-hd-select';
        selectBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Select This House';
    } else if (itemType === 'food') {
        selectBtn.className = 'btn-hd-select food';
        selectBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Select This Food';
    } else if (itemType === 'tour') {
        selectBtn.className = 'btn-hd-select tour';
        selectBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Select This Tour';
    }

    selectBtn.onclick = function() {
        closeHouseDetail();
        setTimeout(function() {
            var modalId = itemType + 'Modal';
            var pick = document.querySelector('#' + modalId + ' .item-pick[data-id="' + numericId + '"]');
            if (pick) {
                if (!document.getElementById(modalId).classList.contains('show')) {
                    openModal(itemType);
                }
                setTimeout(function() { pick.click(); }, 80);
            }
        }, 200);
    };

    document.getElementById('houseDetailModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function renderHdGallery() {
    var mainImg = document.getElementById('hdMainImage');
    var counter = document.getElementById('hdCounter');
    var thumbsBox = document.getElementById('hdThumbs');

    if (!hdImages || hdImages.length === 0) {
        mainImg.src = 'https://via.placeholder.com/900x500/0B2447/ffffff?text=No+Photos';
        counter.textContent = '0 / 0';
        thumbsBox.innerHTML = '';
        return;
    }

    mainImg.src = hdImages[hdIndex];
    counter.textContent = (hdIndex + 1) + ' / ' + hdImages.length;

    thumbsBox.innerHTML = '';
    hdImages.forEach(function(img, idx) {
        var t = document.createElement('img');
        t.src = img;
        t.className = 'hd-thumb' + (idx === hdIndex ? ' active' : '');
        t.onclick = function() { hdIndex = idx; renderHdGallery(); };
        thumbsBox.appendChild(t);
    });
}

function changeHdImage(dir) {
    if (!hdImages || hdImages.length === 0) return;
    hdIndex += dir;
    if (hdIndex < 0) hdIndex = hdImages.length - 1;
    if (hdIndex >= hdImages.length) hdIndex = 0;
    renderHdGallery();
}

function closeHouseDetail() {
    document.getElementById('houseDetailModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function getAmenityIcon(amenity) {
    var a = amenity.toLowerCase();
    if (a.indexOf('aircon') !== -1 || a.indexOf('air conditioning') !== -1) return 'fa-snowflake';
    if (a.indexOf('bed') !== -1) return 'fa-bed';
    if (a.indexOf('kitchen') !== -1) return 'fa-utensils';
    if (a.indexOf('parking') !== -1) return 'fa-parking';
    if (a.indexOf('wifi') !== -1 || a.indexOf('wi-fi') !== -1) return 'fa-wifi';
    if (a.indexOf('tv') !== -1) return 'fa-tv';
    if (a.indexOf('pool') !== -1) return 'fa-swimmer';
    if (a.indexOf('boat') !== -1) return 'fa-ship';
    if (a.indexOf('bathroom') !== -1) return 'fa-bath';
    if (a.indexOf('shower') !== -1) return 'fa-shower';
    if (a.indexOf('meal') !== -1 || a.indexOf('food') !== -1) return 'fa-utensils';
    if (a.indexOf('guide') !== -1) return 'fa-user-tie';
    if (a.indexOf('life') !== -1 || a.indexOf('vest') !== -1) return 'fa-life-ring';
    if (a.indexOf('snorkel') !== -1) return 'fa-mask';
    if (a.indexOf('island') !== -1) return 'fa-umbrella-beach';
    return 'fa-check-circle';
}

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

var houseCalMonth = new Date().getMonth();
var houseCalYear = new Date().getFullYear();
var houseSelectedStart = null;
var houseSelectedEnd = null;
var houseCurrentBooked = [];
var currentHousePrice = 0;
var currentHouseCapacity = 20;

function selectHouse(el) {
    var price = parseFloat(el.dataset.price);
    var id = el.dataset.id;
    currentHousePrice = price;
    currentHouseCapacity = parseInt(el.dataset.capacity) || 20;

    document.getElementById('h_house_id').value = id;
    document.getElementById('h_display_house').value = el.dataset.name;
    document.getElementById('h_display_price').value = '₱' + price.toLocaleString('en-US', {minimumFractionDigits: 2});

    document.getElementById('houseStep1').style.display = 'none';
    document.getElementById('houseForm').style.display = 'block';

    houseCurrentBooked = HOUSE_BOOKED_DATES[id] || [];
    houseSelectedStart = null;
    houseSelectedEnd = null;
    document.getElementById('h_check_in').value = '';
    document.getElementById('h_check_in_display').value = '';
    document.getElementById('h_check_out').value = '';
    document.getElementById('h_guests').value = '';
    document.getElementById('h_display_days').textContent = '0';
    document.getElementById('h_display_nights').textContent = '0';
    document.getElementById('h_display_total').textContent = '0.00';

    // ✅ Reset times to defaults
    document.getElementById('h_check_in_time').value = '14:00';
    document.getElementById('h_check_out_time').value = '12:00';

    document.getElementById('houseCalendarInfo').textContent = 'Click a date to pick your check-in. Then click your check-out.';

    document.getElementById('h_guests').max = currentHouseCapacity;
    document.getElementById('h_max_capacity').textContent = currentHouseCapacity;

    var listEl = document.getElementById('guestNamesList');
    listEl.innerHTML = '';
    listEl.dataset.freshReset = '1';
    rebuildGuestNameInputs();

    var today = new Date();
    houseCalMonth = today.getMonth();
    houseCalYear = today.getFullYear();
    renderHouseCalendar(houseCalMonth, houseCalYear);
}

function backToHouseList() {
    document.getElementById('houseStep1').style.display = 'block';
    document.getElementById('houseForm').style.display = 'none';
}

function renderHouseCalendar(month, year) {
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('houseCalendarMonthYear').textContent = monthNames[month] + ' ' + year;

    var grid = document.getElementById('houseCalendarGrid');
    grid.innerHTML = '';

    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(n) {
        var d = document.createElement('div'); d.className = 'day-name'; d.textContent = n; grid.appendChild(d);
    });

    var today = new Date(); today.setHours(0,0,0,0);
    for (var i = 0; i < firstDay; i++) {
        var e = document.createElement('div'); e.className = 'day disabled'; grid.appendChild(e);
    }

    var startObj = houseSelectedStart ? new Date(houseSelectedStart + 'T00:00:00') : null;
    var endObj = houseSelectedEnd ? new Date(houseSelectedEnd + 'T00:00:00') : null;

    for (var day = 1; day <= daysInMonth; day++) {
        var dateObj = new Date(year, month, day);
        var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        var d = document.createElement('div');
        d.className = 'day'; d.textContent = day; d.dataset.date = dateStr;

        if (dateObj < today) d.classList.add('past');
        if (houseCurrentBooked.indexOf(dateStr) !== -1) d.classList.add('booked');

        if (houseSelectedStart === dateStr || houseSelectedEnd === dateStr) d.classList.add('selected');
        else if (startObj && endObj && dateObj > startObj && dateObj < endObj) d.classList.add('in-range');

        d.onclick = function() { selectHouseDate(this); };
        grid.appendChild(d);
    }
}

function changeHouseMonth(delta) {
    houseCalMonth += delta;
    if (houseCalMonth < 0) { houseCalMonth = 11; houseCalYear--; }
    else if (houseCalMonth > 11) { houseCalMonth = 0; houseCalYear++; }
    renderHouseCalendar(houseCalMonth, houseCalYear);
}

function selectHouseDate(el) {
    if (el.classList.contains('booked') || el.classList.contains('past') || el.classList.contains('disabled')) return;
    var date = el.dataset.date;
    if (!date) return;
    var pickedDate = new Date(date + 'T00:00:00');

    if (houseSelectedStart === date && !houseSelectedEnd) {
        houseSelectedStart = null;
        document.getElementById('h_check_in').value = '';
        document.getElementById('h_check_in_display').value = '';
        document.getElementById('h_check_out').value = '';
        document.getElementById('h_display_days').textContent = '0';
        document.getElementById('h_display_nights').textContent = '0';
        document.getElementById('h_display_total').textContent = '0.00';
        document.getElementById('h_total_price').value = 0;
        document.getElementById('houseCalendarInfo').innerHTML = 'Select check-in date';
        renderHouseCalendar(houseCalMonth, houseCalYear);
        return;
    }

    if (houseSelectedEnd === date && houseSelectedStart) {
        houseSelectedEnd = null;
        document.getElementById('h_check_out').value = '';
        document.getElementById('h_display_days').textContent = '0';
        document.getElementById('h_display_nights').textContent = '0';
        document.getElementById('h_display_total').textContent = '0.00';
        document.getElementById('h_total_price').value = 0;
        document.getElementById('houseCalendarInfo').innerHTML = '✅ Check-in: <strong>' + formatDateDisplay(houseSelectedStart) + '</strong> — now click a later date for check-out';
        renderHouseCalendar(houseCalMonth, houseCalYear);
        return;
    }

    if (!houseSelectedStart || (houseSelectedStart && houseSelectedEnd)) {
        houseSelectedStart = date;
        houseSelectedEnd = null;
        document.getElementById('h_check_in').value = date;
        document.getElementById('h_check_in_display').value = date;
        document.getElementById('h_check_out').value = '';
        document.getElementById('h_display_days').textContent = '0';
        document.getElementById('h_display_nights').textContent = '0';
        document.getElementById('h_display_total').textContent = '0.00';
        document.getElementById('h_total_price').value = 0;
        document.getElementById('houseCalendarInfo').innerHTML = '✅ Check-in: <strong>' + formatDateDisplay(date) + '</strong> — now click a later date for check-out';
        renderHouseCalendar(houseCalMonth, houseCalYear);
        return;
    }

    var startObj = new Date(houseSelectedStart + 'T00:00:00');
    if (pickedDate <= startObj) { alert('❌ Check-out must be after check-in. Please pick a later date.'); return; }

    var conflictDate = '';
    for (var d = new Date(startObj); d < pickedDate; d.setDate(d.getDate() + 1)) {
        var dStr = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
        if (houseCurrentBooked.indexOf(dStr) !== -1) { conflictDate = dStr; break; }
    }
    if (conflictDate) { alert('❌ Your stay range includes a booked date (' + conflictDate + '). Please choose different dates.'); return; }

    houseSelectedEnd = date;
    document.getElementById('h_check_out').value = date;
    document.getElementById('houseCalendarInfo').innerHTML = '✅ Stay: <strong>' + formatDateDisplay(houseSelectedStart) + '</strong> → <strong>' + formatDateDisplay(date) + '</strong>';
    renderHouseCalendar(houseCalMonth, houseCalYear);
    calcHouseTotal();
}

function formatDateDisplay(dateStr) {
    if (!dateStr) return '';
    var p = dateStr.split('-');
    var monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return monthNames[parseInt(p[1]) - 1] + ' ' + parseInt(p[2]) + ', ' + p[0];
}

function calcHouseTotal() {
    var checkIn = document.getElementById('h_check_in').value;
    var checkOut = document.getElementById('h_check_out').value;
    if (!checkIn || !checkOut || !currentHousePrice) {
        document.getElementById('h_display_days').textContent = '0';
        document.getElementById('h_display_nights').textContent = '0';
        document.getElementById('h_display_total').textContent = '0.00';
        document.getElementById('h_total_price').value = 0;
        return;
    }

    var startUTC = Date.UTC.apply(null, checkIn.split('-').map(Number).map((v,i)=>i===1?v-1:v));
    var endUTC = Date.UTC.apply(null, checkOut.split('-').map(Number).map((v,i)=>i===1?v-1:v));
    var nights = Math.round((endUTC - startUTC) / (1000*60*60*24));

    if (nights <= 0) {
        document.getElementById('h_display_days').textContent = '0';
        document.getElementById('h_display_nights').textContent = '0';
        document.getElementById('h_display_total').textContent = '0.00';
        document.getElementById('h_total_price').value = 0;
        return;
    }
    var days = nights + 1;
    var total = currentHousePrice * nights;

    document.getElementById('h_nights').value = nights;
    document.getElementById('h_display_days').textContent = days;
    document.getElementById('h_display_nights').textContent = nights;
    document.getElementById('h_total_price').value = total;
    document.getElementById('h_display_total').textContent = total.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function sanitizeName(value) {
    return value.replace(/[^a-zA-ZÀ-ÿ\s\-'.]/g, '').replace(/\s{2,}/g, ' ').slice(0, 50);
}

function validatePax(input) {
    let raw = input.value.trim();
    if (raw === '') { rebuildGuestNameInputs(); return; }
    let val = parseInt(raw);
    let maxCap = currentHouseCapacity || 20;
    if (isNaN(val) || val < 1) input.value = 1;
    else if (val > maxCap) { input.value = maxCap; alert('Maximum of ' + maxCap + ' guests allowed for this house.'); }
    rebuildGuestNameInputs();
    calcHouseTotal();
}

function rebuildGuestNameInputs() {
    const paxInput = document.getElementById('h_guests');
    const listEl = document.getElementById('guestNamesList');
    const badgeEl = document.getElementById('guestCountBadge');
    const helpEl = document.getElementById('guestNamesHelp');
    if (!paxInput || !listEl || !badgeEl) return;

    const isFreshReset = listEl.dataset.freshReset === '1';
    let currentNames = [];
    if (!isFreshReset) {
        const existingInputs = listEl.querySelectorAll('.guest-name-input');
        if (existingInputs.length > 0) currentNames = Array.from(existingInputs).map(inp => inp.value);
    } else delete listEl.dataset.freshReset;

    const pax = parseInt(paxInput.value) || 0;
    listEl.innerHTML = '';

    if (pax <= 0) {
        badgeEl.textContent = '0 / 0';
        badgeEl.classList.remove('match', 'over', 'under');
        badgeEl.classList.add('under');
        if (helpEl) {
            helpEl.classList.remove('error', 'ok');
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
        input.placeholder = 'Guest ' + (i + 1) + ' full name';
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
            if (!/[a-zA-ZÀ-ÿ\s\-'.]/.test(char)) e.preventDefault();
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

    if (total === 0) { badgeEl.classList.add('under'); return; }
    if (filled === total && total > 0) {
        badgeEl.classList.add('match');
        if (helpEl) { helpEl.classList.remove('error'); helpEl.classList.add('ok'); helpEl.innerHTML = '<i class="fas fa-check-circle"></i> All guest names filled. Ready to add!'; }
    } else {
        badgeEl.classList.add('under');
        if (helpEl) { helpEl.classList.remove('ok'); helpEl.classList.add('error'); helpEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please fill all ' + total + ' guest name(s).'; }
    }
}

function syncGuestNamesHidden() {
    const inputs = document.querySelectorAll('#guestNamesList .guest-name-input');
    const names = Array.from(inputs).map(i => i.value.trim()).filter(n => n.length > 0);
    const hidden = document.getElementById('h_guest_names_json');
    if (hidden) hidden.value = JSON.stringify(names);
}

var foodCalMonth = new Date().getMonth();
var foodCalYear = new Date().getFullYear();
var foodSelectedDate = null;
var foodCurrentBooked = [];
var selectedFood = null;
var selectedFoodVariations = [];

function selectFood(el) {
    selectedFood = { id: el.dataset.id, name: el.dataset.name, price: parseFloat(el.dataset.price) };
    try { selectedFoodVariations = JSON.parse(el.dataset.variations || '[]'); } catch (e) { selectedFoodVariations = []; }

    document.getElementById('f_food_id').value = selectedFood.id;
    document.getElementById('f_display_name').value = selectedFood.name;

    var sizeGroup = document.getElementById('f_size_group');
    var sizeSelect = document.getElementById('f_size_variant');

    if (selectedFoodVariations.length > 0) {
        sizeGroup.style.display = 'block';
        sizeSelect.innerHTML = '';
        selectedFoodVariations.forEach(function(v, idx) {
            var opt = document.createElement('option');
            opt.value = idx;
            opt.dataset.price = v.price;
            opt.textContent = v.size + (v.pax ? ' (' + v.pax + ')' : '') + ' - ₱' + parseFloat(v.price).toLocaleString('en-US', {minimumFractionDigits: 2});
            sizeSelect.appendChild(opt);
        });
        document.getElementById('f_display_price').value = '₱' + parseFloat(selectedFoodVariations[0].price).toLocaleString('en-US', {minimumFractionDigits: 2});
    } else {
        sizeGroup.style.display = 'none';
        document.getElementById('f_display_price').value = '₱' + selectedFood.price.toLocaleString('en-US', {minimumFractionDigits: 2});
    }

    document.getElementById('foodStep1').style.display = 'none';
    document.getElementById('foodForm').style.display = 'block';

    foodCurrentBooked = FOOD_BOOKED_DATES[selectedFood.id] || [];
    foodSelectedDate = null;
    document.getElementById('f_preferred_date').value = '';
    document.getElementById('f_preferred_time').value = '';
    document.getElementById('f_contact').value = '';
    document.getElementById('f_contact_count').textContent = '0/10';
    document.getElementById('f_contact_count').classList.remove('complete');
    document.getElementById('f_delivery_address').value = '';
    document.querySelector('input[name="fulfillment_method"][value="pickup"]').checked = true;
    toggleDelivery();

    var today = new Date();
    foodCalMonth = today.getMonth();
    foodCalYear = today.getFullYear();
    renderFoodCalendar(foodCalMonth, foodCalYear);
    calcFoodTotal();
}

function backToFoodList() {
    document.getElementById('foodStep1').style.display = 'block';
    document.getElementById('foodForm').style.display = 'none';
}

function renderFoodCalendar(month, year) {
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('foodCalendarMonthYear').textContent = monthNames[month] + ' ' + year;

    var grid = document.getElementById('foodCalendarGrid');
    grid.innerHTML = '';

    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(n) {
        var d = document.createElement('div'); d.className = 'day-name'; d.textContent = n; grid.appendChild(d);
    });

    var today = new Date(); today.setHours(0,0,0,0);
    for (var i = 0; i < firstDay; i++) {
        var e = document.createElement('div'); e.className = 'day disabled'; grid.appendChild(e);
    }

    for (var day = 1; day <= daysInMonth; day++) {
        var dateObj = new Date(year, month, day);
        var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        var d = document.createElement('div');
        d.className = 'day'; d.textContent = day; d.dataset.date = dateStr;

        if (dateObj < today) d.classList.add('past');
        if (foodCurrentBooked.indexOf(dateStr) !== -1) d.classList.add('booked');
        if (foodSelectedDate === dateStr) d.classList.add('selected');

        d.onclick = function() { selectFoodDate(this); };
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
    if (el.classList.contains('booked') || el.classList.contains('past') || el.classList.contains('disabled')) return;
    var date = el.dataset.date;
    if (!date) return;

    if (foodSelectedDate === date) {
        foodSelectedDate = null; el.classList.remove('selected');
        document.getElementById('f_preferred_date').value = '';
        document.getElementById('foodCalendarInfo').textContent = 'Select a date for your food order';
        document.getElementById('foodCalendarInfo').classList.remove('success');
        return;
    }

    document.querySelectorAll('#foodCalendarGrid .day.selected').forEach(function(d) { d.classList.remove('selected'); });

    foodSelectedDate = date; el.classList.add('selected');
    document.getElementById('f_preferred_date').value = date;
    document.getElementById('foodCalendarInfo').innerHTML = '✅ Selected: <strong>' + formatDateDisplay(date) + '</strong>';
    document.getElementById('foodCalendarInfo').classList.add('success');
}

function calcFoodTotal() {
    var price = selectedFood ? selectedFood.price : 0;
    if (selectedFoodVariations.length > 0) {
        var sizeSelect = document.getElementById('f_size_variant');
        var opt = sizeSelect.options[sizeSelect.selectedIndex];
        if (opt && opt.dataset.price) price = parseFloat(opt.dataset.price);
    }
    document.getElementById('f_display_price').value = '₱' + price.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('f_total_price').value = price;
    document.getElementById('f_price_display').textContent = '₱' + price.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function toggleDelivery() {
    var method = document.querySelector('input[name="fulfillment_method"]:checked');
    if (!method) return;
    var group = document.getElementById('f_delivery_group');
    var addr = document.getElementById('f_delivery_address');
    if (method.value === 'delivery') { group.style.display = 'block'; addr.required = true; }
    else { group.style.display = 'none'; addr.required = false; addr.value = ''; }
}

var tourCalMonth = new Date().getMonth();
var tourCalYear = new Date().getFullYear();
var tourSelectedDate = null;
var tourCurrentBooked = [];
var selectedTour = null;

function selectTour(el) {
    selectedTour = { id: el.dataset.id, name: el.dataset.name, price: parseFloat(el.dataset.price), max: parseInt(el.dataset.max) };
    document.getElementById('t_tour_id').value = selectedTour.id;
    document.getElementById('t_display_tour').value = selectedTour.name;
    document.getElementById('t_display_price').value = '₱' + selectedTour.price.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('t_max_label').textContent = selectedTour.max;
    document.getElementById('t_guests').max = selectedTour.max;
    document.getElementById('t_total_price').value = selectedTour.price;

    document.getElementById('tourStep1').style.display = 'none';
    document.getElementById('tourForm').style.display = 'block';

    tourCurrentBooked = TOUR_BOOKED_DATES[selectedTour.id] || [];
    tourSelectedDate = null;
    document.getElementById('t_booking_date').value = '';
    document.getElementById('tourCalendarInfo').textContent = 'Select a date for your tour';
    updateTourSummary();

    var today = new Date();
    tourCalMonth = today.getMonth();
    tourCalYear = today.getFullYear();
    renderTourCalendar(tourCalMonth, tourCalYear);
}

function backToTourList() {
    document.getElementById('tourStep1').style.display = 'block';
    document.getElementById('tourForm').style.display = 'none';
}

function renderTourCalendar(month, year) {
    var firstDay = new Date(year, month, 1).getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('tourCalendarMonthYear').textContent = monthNames[month] + ' ' + year;

    var grid = document.getElementById('tourCalendarGrid');
    grid.innerHTML = '';

    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(n) {
        var d = document.createElement('div'); d.className = 'day-name'; d.textContent = n; grid.appendChild(d);
    });

    var today = new Date(); today.setHours(0,0,0,0);
    for (var i = 0; i < firstDay; i++) {
        var e = document.createElement('div'); e.className = 'day disabled'; grid.appendChild(e);
    }

    for (var day = 1; day <= daysInMonth; day++) {
        var dateObj = new Date(year, month, day);
        var dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
        var d = document.createElement('div');
        d.className = 'day'; d.textContent = day; d.dataset.date = dateStr;

        if (dateObj < today) d.classList.add('past');
        if (tourCurrentBooked.indexOf(dateStr) !== -1) d.classList.add('booked');
        if (tourSelectedDate === dateStr) d.classList.add('selected');

        d.onclick = function() { selectTourDate(this); };
        grid.appendChild(d);
    }
}

function changeTourMonth(delta) {
    tourCalMonth += delta;
    if (tourCalMonth < 0) { tourCalMonth = 11; tourCalYear--; }
    else if (tourCalMonth > 11) { tourCalMonth = 0; tourCalYear++; }
    renderTourCalendar(tourCalMonth, tourCalYear);
}

function selectTourDate(el) {
    if (el.classList.contains('booked') || el.classList.contains('past') || el.classList.contains('disabled')) return;
    var date = el.dataset.date;
    if (!date) return;

    if (tourSelectedDate === date) {
        tourSelectedDate = null; el.classList.remove('selected');
        document.getElementById('t_booking_date').value = '';
        document.getElementById('tourCalendarInfo').textContent = 'Select a date for your tour';
        return;
    }

    document.querySelectorAll('#tourCalendarGrid .day.selected').forEach(function(d) { d.classList.remove('selected'); });

    tourSelectedDate = date; el.classList.add('selected');
    document.getElementById('t_booking_date').value = date;
    document.getElementById('tourCalendarInfo').innerHTML = '✅ Date selected: <strong>' + formatDateDisplay(date) + '</strong>';
}

function updateTourSummary() {
    var paxInput = document.getElementById('t_guests');
    var pax = parseInt(paxInput.value) || 1;
    document.getElementById('t_summary_pax').textContent = pax;
    var total = selectedTour ? selectedTour.price : 0;
    document.getElementById('t_summary_total').textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function validatePhone(input, counterId) {
    let cleaned = input.value.replace(/[^0-9]/g, '');
    if (cleaned.length > 10) cleaned = cleaned.slice(0, 10);
    if (cleaned.startsWith('0')) cleaned = cleaned.substring(1);
    if (input.value !== cleaned) input.value = cleaned;
    const counter = document.getElementById(counterId);
    if (counter) {
        counter.textContent = cleaned.length + '/10';
        counter.classList.toggle('complete', cleaned.length === 10);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var tContact = document.getElementById('t_contact');
    if (tContact) tContact.addEventListener('input', function() { validatePhone(this, 't_contact_count'); });

    var tGuests = document.getElementById('t_guests');
    if (tGuests) {
        tGuests.addEventListener('input', updateTourSummary);
        tGuests.addEventListener('change', updateTourSummary);
    }
});

document.getElementById('foodForm').addEventListener('submit', function(e) {
    var date = document.getElementById('f_preferred_date').value;
    if (!date) { e.preventDefault(); alert('Please select a preferred date from the calendar.'); return false; }
    var time = document.getElementById('f_preferred_time').value;
    if (!time) { e.preventDefault(); alert('Please select a preferred time slot.'); return false; }
    var contact = document.getElementById('f_contact').value;
    if (!/^[0-9]{10}$/.test(contact)) { e.preventDefault(); alert('PH mobile number must be exactly 10 digits (e.g., 9123456789).'); return false; }
    var method = document.querySelector('input[name="fulfillment_method"]:checked').value;
    if (method === 'delivery') {
        var addr = document.getElementById('f_delivery_address').value.trim();
        if (!addr) { e.preventDefault(); alert('Delivery address is required when choosing Delivery.'); return false; }
    }
    return true;
});

document.getElementById('tourForm').addEventListener('submit', function(e) {
    var date = document.getElementById('t_booking_date').value;
    if (!date) { e.preventDefault(); alert('Please select a booking date from the calendar.'); return false; }
    var guestName = document.getElementById('t_guest_name').value.trim();
    if (guestName.length < 2) { e.preventDefault(); alert('Please enter the lead guest full name.'); return false; }
    var contact = document.getElementById('t_contact').value;
    if (!/^[0-9]{10}$/.test(contact)) { e.preventDefault(); alert('PH mobile number must be exactly 10 digits (e.g., 9123456789).'); return false; }
    var time = document.getElementById('t_preferred_time').value;
    if (!time) { e.preventDefault(); alert('Please select a preferred time slot.'); return false; }
    var guests = parseInt(document.getElementById('t_guests').value);
    if (!guests || guests < 1) { e.preventDefault(); alert('Please enter the number of pax (minimum 1).'); return false; }
    if (guests > selectedTour.max) { e.preventDefault(); alert('Maximum guests for this tour is ' + selectedTour.max + '.'); return false; }
    return true;
});

// ✅ UPDATED: houseForm submit validates times too
document.getElementById('houseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    e.stopPropagation();

    var date = document.getElementById('h_check_in').value;
    if (!date) { alert('Please select a check-in date from the calendar.'); return false; }
    var checkOut = document.getElementById('h_check_out').value;
    if (!checkOut) { alert('Please select a check-out date from the calendar.'); return false; }

    var checkInTime = document.getElementById('h_check_in_time').value;
    var checkOutTime = document.getElementById('h_check_out_time').value;
    if (!checkInTime) { alert('Please select a check-in time.'); document.getElementById('h_check_in_time').focus(); return false; }
    if (!checkOutTime) { alert('Please select a check-out time.'); document.getElementById('h_check_out_time').focus(); return false; }

    var paxRaw = document.getElementById('h_guests').value.trim();
    var pax = parseInt(paxRaw);
    if (paxRaw === '' || isNaN(pax) || pax < 1) {
        alert('Please enter a valid number of guests (minimum 1).');
        document.getElementById('h_guests').focus();
        return false;
    }
    if (pax > currentHouseCapacity) {
        alert('Maximum of ' + currentHouseCapacity + ' guests allowed for this house.');
        document.getElementById('h_guests').focus();
        return false;
    }

    var inputs = document.querySelectorAll('#guestNamesList .guest-name-input');
    var names = [];
    var hasError = false;

    if (inputs.length !== pax) { alert('Please wait a moment while guest fields update, then try again.'); return false; }

    for (var i = 0; i < inputs.length; i++) {
        var val = inputs[i].value.trim();
        if (val === '') { alert('Please enter guest name #' + (i + 1) + '.'); inputs[i].focus(); hasError = true; break; }
        if (val.length < 2) { alert('Guest name #' + (i + 1) + ' is too short.'); inputs[i].focus(); hasError = true; break; }
        if (!/^[a-zA-ZÀ-ÿ\s\-'.]+$/.test(val)) { alert('Guest name #' + (i + 1) + ' contains invalid characters.'); inputs[i].focus(); hasError = true; break; }
        names.push(val);
    }

    if (hasError) return false;
    if (names.length !== pax) { alert('Please provide exactly ' + pax + ' guest name(s).'); return false; }

    document.getElementById('h_guest_names_json').value = JSON.stringify(names);
    this.submit();
    return true;
});

<?php if (isset($modal_error) && !empty($open_modal)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openModal('<?php echo $open_modal; ?>');
});
<?php endif; ?>

var overallSelectedRating = <?php echo $user_has_feedback ? (int)($user_feedback['rating'] ?? 0) : 0; ?>;
var overallRatingTexts = { 1: 'Very Poor', 2: 'Poor', 3: 'Average', 4: 'Good', 5: 'Excellent!' };

function openOverallFeedbackModal(event) {
    if (event) event.preventDefault();
    if (window.closeGuestNavDrawer) window.closeGuestNavDrawer();
    syncBigRatingStars(overallSelectedRating);
    var wordEl = document.getElementById('bigRatingWord');
    if (wordEl) wordEl.textContent = overallSelectedRating > 0 ? overallRatingTexts[overallSelectedRating] : 'Select a rating';
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
            if (wordEl) wordEl.textContent = overallSelectedRating > 0 ? overallRatingTexts[overallSelectedRating] : 'Select a rating';
        });
    }
});

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
</script>

</body>
</html>
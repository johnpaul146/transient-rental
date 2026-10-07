<?php
/**
 * ============================================================================
 * WalkInBookingService — bookings created by staff/admin for guests at the counter.
 * ============================================================================
 * Walk-in bookings are NORMAL bookings in the normal tables (house_bookings,
 * tour_bookings, food_bookings, package_bookings). They differ only by:
 *    booking_source = 'walk_in'   (how the booking was created)
 *    guests.guest_source = 'walk_in' (how the guest profile was created)
 * so booking management, the dashboard, sales reports, rebooking, cancellation
 * and the payment ledger treat them exactly like online bookings (no double
 * counting, no second code path for money).
 *
 * GUESTS
 *  guests.user_id is NOT NULL, so a walk-in guest gets a PLACEHOLDER user:
 *    username  walkin_<random>      email  walkin-<random>@walkin.invalid
 *    password  random, never revealed  -> the account cannot sign in
 *  (If staff type a real e-mail address, that address is used instead of the
 *  placeholder so the guest also gets booking e-mails.)
 *  No OTP, password, profile photo or ID upload is required.
 *
 * AVAILABILITY / VALIDATION — same rules as the guest flow, via AvailabilityService:
 *  future dates only · house/tour/food must be available · house capacity ·
 *  tour max guests · house conflicts + blocked dates · tour boat-per-date +
 *  blocked dates · food blocked dates · package = every included item valid.
 *  The item rows are locked (FOR UPDATE) while checking and inserting.
 *
 * PAYMENT
 *  pay_later  booking stays Pending / Unpaid (guest pays later, as online)
 *  cash|gcash the ₱1,000 reservation fee is recorded through
 *             PaymentService::confirmReservation (normal booking_payments ledger
 *             row, status Reservation Paid, booking Confirmed, dates blocked).
 *             Nothing writes money outside the ledger.
 * ============================================================================
 */

require_once __DIR__ . '/AvailabilityService.php';
require_once __DIR__ . '/PaymentService.php';
if (file_exists(__DIR__ . '/SystemLogger.php')) require_once __DIR__ . '/SystemLogger.php';

class WalkInBookingService {

    const PLACEHOLDER_DOMAIN = 'walkin.invalid';
    const SOURCE = 'walk_in';
    const TYPES = ['house', 'tour', 'food', 'package'];
    const PAYMENT_OPTIONS = ['pay_later', 'cash', 'gcash'];

    private static $schemaChecked = false;

    // ------------------------------------------------------------------ schema

    /**
     * Make sure the walk-in columns exist (same SQL as migrations/2026_10_walkin_staff.sql,
     * additive and idempotent). Lets the site keep working if the SQL was not run yet.
     */
    public static function ensureSchema(PDO $pdo) {
        if (self::$schemaChecked) return;
        $targets = ['guests' => 'guest_source', 'house_bookings' => 'booking_source', 'tour_bookings' => 'booking_source',
                    'food_bookings' => 'booking_source', 'package_bookings' => 'booking_source'];
        foreach ($targets as $table => $col) {
            try {
                $has = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
                if (!$has) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` ENUM('online','walk_in') NOT NULL DEFAULT 'online'");
                }
            } catch (PDOException $e) {
                error_log("WalkInBookingService::ensureSchema($table.$col): " . $e->getMessage());
            }
        }
        self::$schemaChecked = true;
    }

    public static function isPlaceholderEmail($email) {
        return is_string($email) && preg_match('/@' . preg_quote(self::PLACEHOLDER_DOMAIN, '/') . '$/i', trim($email)) === 1;
    }

    // ------------------------------------------------------------- validation

    public static function validateName($name) {
        $name = trim((string)$name);
        if ($name === '') return 'Guest full name is required.';
        if (mb_strlen($name) < 2) return 'Guest name is too short.';
        if (mb_strlen($name) > 60) return 'Guest name must not exceed 60 characters.';
        if (preg_match('/[0-9]/', $name)) return 'Guest name cannot contain numbers.';
        if (!preg_match("/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/u", $name)) return "Guest name can only contain letters, spaces, hyphens (-), apostrophes (') and periods (.).";
        return null;
    }

    /** '09xx…', '9xx…', '+639xx…', '639xx…' -> '+639XXXXXXXXX' (PH mobile, same format as online bookings). */
    public static function normalizePhone($raw) {
        $d = preg_replace('/[^0-9]/', '', (string)$raw);
        if (strpos($d, '63') === 0 && strlen($d) === 12) $d = substr($d, 2);
        if (strpos($d, '0') === 0) $d = substr($d, 1);
        if (!preg_match('/^9[0-9]{9}$/', $d)) {
            throw new InvalidArgumentException('Contact number must be a valid PH mobile number (e.g. 09123456789).');
        }
        return '+63' . $d;
    }

    private static function cleanDate($v, $label) {
        $err = AvailabilityService::futureDateError($v);
        if ($err !== null) throw new InvalidArgumentException($err);
        return trim((string)$v);
    }

    private static function cleanTime($v, $default, $label) {
        $v = trim((string)$v);
        if ($v === '') $v = $default;
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) throw new InvalidArgumentException("Invalid $label time.");
        return strlen($v) === 5 ? $v . ':00' : $v;
    }

    private static function clip($v, $max) {
        $v = trim((string)$v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    // ----------------------------------------------------------------- guests

    /** Search guests (online + walk-in) by name, contact number or e-mail. */
    public static function searchGuests(PDO $pdo, $q, $limit = 8) {
        $q = trim((string)$q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $digits = preg_replace('/[^0-9]/', '', $q);
        // 0917… / +63917… / 917… all find the same number whichever way it was stored
        if (strlen($digits) > 3 && strpos($digits, '63') === 0) $digits = substr($digits, 2);
        elseif (strlen($digits) > 1 && $digits[0] === '0') $digits = substr($digits, 1);
        $digitLike = $digits !== '' ? '%' . $digits . '%' : $like;
        $st = $pdo->prepare("SELECT g.id, g.full_name, g.contact_number, g.email, g.guest_source, u.email AS user_email
                               FROM guests g JOIN users u ON u.id = g.user_id
                              WHERE g.full_name LIKE ? OR g.email LIKE ? OR u.email LIKE ? OR u.username LIKE ?
                                 OR REPLACE(REPLACE(REPLACE(g.contact_number, '+', ''), ' ', ''), '-', '') LIKE ?
                              ORDER BY g.full_name ASC LIMIT " . (int)$limit);
        $st->execute([$like, $like, $like, $like, $digitLike]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mail = $r['email'] ?: $r['user_email'];
            $out[] = ['id' => (int)$r['id'], 'name' => $r['full_name'], 'contact' => $r['contact_number'],
                      'email' => self::isPlaceholderEmail($mail) ? '' : (string)$mail,
                      'source' => $r['guest_source'] ?? 'online'];
        }
        return $out;
    }

    /**
     * Existing guest -> its guests row. New -> placeholder user + walk-in guest.
     * Must run inside the caller's transaction.
     * Returns ['id' => guests.id, 'name' => ..., 'contact' => ..., 'created' => bool]
     */
    private static function resolveGuest(PDO $pdo, array $in) {
        $mode = ($in['guest_mode'] ?? 'new') === 'existing' ? 'existing' : 'new';

        if ($mode === 'existing') {
            $gid = (int)($in['guest_id'] ?? 0);
            if ($gid <= 0) throw new InvalidArgumentException('Please search and select a guest, or create a new walk-in guest.');
            $st = $pdo->prepare('SELECT id, full_name, contact_number FROM guests WHERE id = ?');
            $st->execute([$gid]);
            $g = $st->fetch(PDO::FETCH_ASSOC);
            if (!$g) throw new InvalidArgumentException('The selected guest no longer exists.');
            return ['id' => (int)$g['id'], 'name' => $g['full_name'], 'contact' => $g['contact_number'], 'created' => false];
        }

        $name = trim((string)($in['wg_name'] ?? ''));
        if (($e = self::validateName($name)) !== null) throw new InvalidArgumentException($e);
        $contact = self::normalizePhone($in['wg_contact'] ?? '');

        $email = trim((string)($in['wg_email'] ?? ''));
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) throw new InvalidArgumentException('Please enter a valid e-mail address, or leave it empty.');
            if (self::isPlaceholderEmail($email)) throw new InvalidArgumentException('Please enter a real e-mail address, or leave it empty.');
            $dupe = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            $dupe->execute([$email]);
            if ($dupe->fetchColumn()) throw new InvalidArgumentException('That e-mail already belongs to a registered guest. Use "Search existing guest" instead.');
        }

        // Same person (same name + number) already on file? Reuse them instead of creating a duplicate.
        $same = $pdo->prepare("SELECT id FROM guests WHERE LOWER(full_name) = LOWER(?) AND contact_number = ? LIMIT 1");
        $same->execute([$name, $contact]);
        if ($same->fetchColumn()) {
            throw new InvalidArgumentException("A guest named {$name} with this contact number already exists. Use “Search existing guest” to book for them.");
        }

        $emName = trim((string)($in['wg_emergency_name'] ?? ''));
        if ($emName !== '' && !preg_match("/^[a-zA-ZÀ-ÿñÑ\s\-'.]+$/u", $emName)) throw new InvalidArgumentException('Emergency contact name can only contain letters, spaces, hyphens, apostrophes and periods.');
        $emNumber = trim((string)($in['wg_emergency_number'] ?? ''));
        if ($emNumber !== '') $emNumber = self::normalizePhone($emNumber);

        $username = null;
        for ($i = 0; $i < 5; $i++) {
            $cand = 'walkin_' . bin2hex(random_bytes(5));
            $c = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
            $c->execute([$cand]);
            if (!$c->fetchColumn()) { $username = $cand; break; }
        }
        if ($username === null) throw new RuntimeException('Could not create the walk-in guest. Please try again.');
        $userEmail = $email !== '' ? $email : ($username . '@' . self::PLACEHOLDER_DOMAIN);

        // Random password nobody knows: the placeholder account cannot sign in.
        $pdo->prepare("INSERT INTO users (username, password, email, fullname, role) VALUES (?, ?, ?, ?, 'guest')")
            ->execute([$username, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $userEmail, $name]);
        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO guests (user_id, full_name, contact_number, email, address, id_type, id_number, emergency_contact, emergency_number, guest_source)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'walk_in')")
            ->execute([$userId, $name, $contact, $email !== '' ? $email : null, self::clip($in['wg_address'] ?? '', 500),
                       self::clip($in['wg_id_type'] ?? '', 50), self::clip($in['wg_id_number'] ?? '', 100),
                       $emName !== '' ? mb_substr($emName, 0, 200) : null, $emNumber !== '' ? $emNumber : null]);
        return ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'contact' => $contact, 'created' => true];
    }

    // ------------------------------------------------------------ references

    private static function newReference(PDO $pdo, $table, $prefix) {
        for ($i = 0; $i < 10; $i++) {
            $ref = $prefix . '-' . date('Ymd') . '-' . random_int(1000, 9999);
            $c = $pdo->prepare("SELECT 1 FROM `$table` WHERE reference_number = ?");
            $c->execute([$ref]);
            if (!$c->fetchColumn()) return $ref;
        }
        throw new RuntimeException('Could not generate a unique reference number. Please try again.');
    }

    // --------------------------------------------------------- item builders
    // Each builder validates one item and returns a plan array; nothing is written yet.

    private static function planHouse(PDO $pdo, array $in, array $guest) {
        $houseId = (int)($in['house_id'] ?? 0);
        $checkIn = self::cleanDate($in['check_in'] ?? '', 'check-in');
        $checkOut = self::cleanDate($in['check_out'] ?? '', 'check-out');
        if ($checkOut <= $checkIn) throw new InvalidArgumentException('Check-out must be after check-in.');
        $pax = (int)($in['guests'] ?? 0);
        if ($pax < 1) throw new InvalidArgumentException('Number of guests must be at least 1.');

        AvailabilityService::lockItem($pdo, 'house', $houseId);
        $st = $pdo->prepare('SELECT id, house_name, price_per_night, capacity, status FROM houses WHERE id = ?');
        $st->execute([$houseId]);
        $h = $st->fetch(PDO::FETCH_ASSOC);
        if (!$h) throw new InvalidArgumentException('Please choose a house.');
        if ($h['status'] !== 'available') throw new InvalidArgumentException("House '{$h['house_name']}' is not available for booking.");
        if ($pax > (int)$h['capacity']) throw new InvalidArgumentException("'{$h['house_name']}' fits up to {$h['capacity']} guest(s).");
        if (($msg = AvailabilityService::houseConflict($pdo, $houseId, $checkIn, $checkOut)) !== null) {
            throw new InvalidArgumentException("House '{$h['house_name']}': $msg");
        }

        $nights = (int)(new DateTime($checkIn))->diff(new DateTime($checkOut))->days;
        $names = array_values(array_filter(array_map('trim', explode("\n", (string)($in['guest_names'] ?? '')))));
        if ($names) {
            if (count($names) !== $pax) throw new InvalidArgumentException('Guest names: enter exactly ' . $pax . ' name(s) (one per line), or leave it empty.');
            foreach ($names as $n) {
                if (($e = self::validateName($n)) !== null) throw new InvalidArgumentException("Guest names: $e ($n)");
            }
        } else {
            $names = [$guest['name']];
        }
        return ['type' => 'house', 'row' => $h, 'name' => $h['house_name'], 'price' => round((float)$h['price_per_night'] * $nights, 2),
                'check_in' => $checkIn, 'check_out' => $checkOut, 'nights' => $nights, 'pax' => $pax,
                'in_time' => self::cleanTime($in['check_in_time'] ?? '', '14:00', 'check-in'),
                'out_time' => self::cleanTime($in['check_out_time'] ?? '', '12:00', 'check-out'),
                'names' => implode("\n", $names)];
    }

    private static function planTour(PDO $pdo, array $in, array $guest) {
        $tourId = (int)($in['tour_id'] ?? 0);
        $date = self::cleanDate($in['tour_date'] ?? '', 'tour');
        $pax = (int)($in['tour_guests'] ?? 0);
        if ($pax < 1) throw new InvalidArgumentException('Number of guests must be at least 1.');
        $time = trim((string)($in['tour_time'] ?? ''));
        if ($time === '') throw new InvalidArgumentException('Please select a tour time.');

        AvailabilityService::lockItem($pdo, 'tour', $tourId);
        $st = $pdo->prepare('SELECT id, tour_name, price_per_boat, max_guests, status FROM tours WHERE id = ?');
        $st->execute([$tourId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new InvalidArgumentException('Please choose a boat/tour.');
        if ($t['status'] !== 'available') throw new InvalidArgumentException("Tour '{$t['tour_name']}' is not available for booking.");
        if ($pax > (int)$t['max_guests']) throw new InvalidArgumentException("'{$t['tour_name']}' allows up to {$t['max_guests']} guest(s).");
        if (($msg = AvailabilityService::tourConflict($pdo, $tourId, $date)) !== null) {
            throw new InvalidArgumentException("Tour '{$t['tour_name']}': $msg");
        }
        return ['type' => 'tour', 'row' => $t, 'name' => $t['tour_name'], 'price' => round((float)$t['price_per_boat'], 2),
                'date' => $date, 'pax' => $pax, 'time' => self::cleanTime($time, '08:00', 'tour'),
                'requests' => self::clip($in['tour_requests'] ?? ($in['special_requests'] ?? ''), 1000)];
    }

    private static function planFood(PDO $pdo, array $in, array $guest) {
        $foodId = (int)($in['food_id'] ?? 0);
        $date = self::cleanDate($in['food_date'] ?? '', 'food');
        $time = trim((string)($in['food_time'] ?? ''));
        if ($time === '') throw new InvalidArgumentException('Please select a food time.');
        $method = ($in['fulfillment'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
        $addr = self::clip($in['delivery_address'] ?? '', 500);
        if ($method === 'delivery' && $addr === null) throw new InvalidArgumentException('Delivery address is required for delivery.');

        AvailabilityService::lockItem($pdo, 'food', $foodId);
        $st = $pdo->prepare('SELECT id, name, price, is_available, size_variations FROM food_items WHERE id = ?');
        $st->execute([$foodId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) throw new InvalidArgumentException('Please choose a food package.');
        if (!(int)$f['is_available']) throw new InvalidArgumentException("'{$f['name']}' is currently unavailable.");
        if (($msg = AvailabilityService::foodConflict($pdo, $foodId, $date)) !== null) {
            throw new InvalidArgumentException("Food '{$f['name']}': $msg");
        }

        $price = (float)$f['price'];
        $sizeText = '';
        if (!empty($f['size_variations'])) {
            $vars = json_decode($f['size_variations'], true);
            if (is_array($vars) && !empty($vars)) {
                $idx = isset($in['size_variant']) && $in['size_variant'] !== '' ? (int)$in['size_variant'] : -1;
                if (!isset($vars[$idx])) throw new InvalidArgumentException("Please choose a size for '{$f['name']}'.");
                $price = (float)$vars[$idx]['price'];
                $sizeText = (string)$vars[$idx]['size'];
            }
        }
        return ['type' => 'food', 'row' => $f, 'name' => $f['name'], 'price' => round($price, 2), 'date' => $date,
                'time' => self::cleanTime($time, '12:00', 'food'), 'size' => $sizeText, 'method' => $method,
                'address' => $method === 'delivery' ? $addr : null, 'requests' => self::clip($in['food_requests'] ?? ($in['special_requests'] ?? ''), 1000)];
    }

    // --------------------------------------------------------------- inserts

    private static function insertHouse(PDO $pdo, array $p, array $guest, $ref, $gcashRef, $withFee) {
        $pdo->prepare("INSERT INTO house_bookings
            (guest_id, house_id, reference_number, check_in_date, check_in_time, check_out_date, check_out_time,
             number_of_guests, total_amount, reservation_fee_amount, guest_names, gcash_reference, payment_status, booking_status, booking_source, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'walk_in', NOW())")
            ->execute([$guest['id'], $p['row']['id'], $ref, $p['check_in'], $p['in_time'], $p['check_out'], $p['out_time'],
                       $p['pax'], $p['price'], $withFee ? PaymentService::feeFor($p['price']) : 0, $p['names'], $gcashRef]);
        return (int)$pdo->lastInsertId();
    }

    private static function insertTour(PDO $pdo, array $p, array $guest, $ref, $gcashRef, $withFee) {
        $pdo->prepare("INSERT INTO tour_bookings
            (guest_id, tour_id, reference_number, booking_date, number_of_guests, guest_name, contact_number, preferred_time,
             total_amount, reservation_fee_amount, special_requests, gcash_reference, payment_status, booking_status, booking_source, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'walk_in', NOW())")
            ->execute([$guest['id'], $p['row']['id'], $ref, $p['date'], $p['pax'], $guest['name'], $guest['contact'], $p['time'],
                       $p['price'], $withFee ? PaymentService::feeFor($p['price']) : 0, $p['requests'], $gcashRef]);
        return (int)$pdo->lastInsertId();
    }

    private static function insertFood(PDO $pdo, array $p, array $guest, $ref, $gcashRef, $withFee) {
        $pdo->prepare("INSERT INTO food_bookings
            (guest_id, guest_name, food_id, reference_number, quantity, size_variant, preferred_date, preferred_time,
             special_requests, contact_number, fulfillment_method, delivery_address, total_amount, reservation_fee_amount,
             gcash_reference, booking_date, payment_status, booking_status, booking_source, created_at)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending', 'pending', 'walk_in', NOW())")
            ->execute([$guest['id'], $guest['name'], $p['row']['id'], $ref, $p['size'], $p['date'], $p['time'],
                       $p['requests'], $guest['contact'], $p['method'], $p['address'], $p['price'],
                       $withFee ? PaymentService::feeFor($p['price']) : 0, $gcashRef]);
        return (int)$pdo->lastInsertId();
    }

    // ------------------------------------------------------------ entry point

    /**
     * Create a walk-in booking.
     *
     * $in keys (all strings, straight from the form):
     *   guest_mode existing|new, guest_id | wg_name, wg_contact, wg_email, wg_address, wg_id_type, wg_id_number,
     *   wg_emergency_name, wg_emergency_number
     *   booking_type house|tour|food|package   (package: package_items[] = house,tour,food)
     *   house: house_id, check_in, check_out, check_in_time, check_out_time, guests, guest_names
     *   tour:  tour_id, tour_date, tour_time, tour_guests, tour_requests
     *   food:  food_id, food_date, food_time, size_variant, fulfillment, delivery_address, food_requests
     *   payment_option pay_later|cash|gcash, gcash_reference
     *
     * Returns ['type','id','reference','total','guest_id','guest_name','guest_created','payment' => [...]]
     * Throws InvalidArgumentException (message is safe to show) for validation problems.
     */
    public static function create(PDO $pdo, array $in, $staffUserId, $staffRole = 'staff') {
        self::ensureSchema($pdo);

        $type = (string)($in['booking_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException('Please choose a booking type.');
        $payOpt = (string)($in['payment_option'] ?? 'pay_later');
        if (!in_array($payOpt, self::PAYMENT_OPTIONS, true)) throw new InvalidArgumentException('Please choose a payment option.');
        $gcashRef = null;
        if ($payOpt === 'gcash') {
            $gcashRef = trim((string)($in['gcash_reference'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9\- ]{6,30}$/', $gcashRef)) {
                throw new InvalidArgumentException('Enter the GCash reference number shown on the guest\'s payment (6–30 letters/numbers).');
            }
        }
        $paying = ($payOpt !== 'pay_later');

        $items = [];
        if ($type === 'package') {
            $chosen = array_values(array_intersect(['house', 'tour', 'food'], (array)($in['package_items'] ?? [])));
            if (!$chosen) throw new InvalidArgumentException('Choose at least one item for the package.');
        }

        $pdo->beginTransaction();
        try {
            $guest = self::resolveGuest($pdo, $in);

            if ($type === 'house') $items['house'] = self::planHouse($pdo, $in, $guest);
            if ($type === 'tour')  $items['tour']  = self::planTour($pdo, $in, $guest);
            if ($type === 'food')  $items['food']  = self::planFood($pdo, $in, $guest);
            if ($type === 'package') {
                foreach ($chosen as $c) {
                    $items[$c] = ($c === 'house') ? self::planHouse($pdo, $in, $guest)
                               : (($c === 'tour') ? self::planTour($pdo, $in, $guest) : self::planFood($pdo, $in, $guest));
                }
            }

            $bookingId = 0; $reference = ''; $total = 0.0; $logItems = [];
            if ($type !== 'package') {
                $p = reset($items);
                $total = $p['price'];
                if ($type === 'house') { $reference = self::newReference($pdo, 'house_bookings', 'HS');   $bookingId = self::insertHouse($pdo, $p, $guest, $reference, $gcashRef, true); }
                if ($type === 'tour')  { $reference = self::newReference($pdo, 'tour_bookings', 'TOUR');  $bookingId = self::insertTour($pdo, $p, $guest, $reference, $gcashRef, true); }
                if ($type === 'food')  { $reference = self::newReference($pdo, 'food_bookings', 'FOOD');  $bookingId = self::insertFood($pdo, $p, $guest, $reference, $gcashRef, true); }
                $logItems[] = ['type' => $type, 'name' => $p['name'], 'amount' => $total];
            } else {
                // One package row; component rows carry no money (same as the online package flow).
                $reference = 'PKG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $ids = ['house' => null, 'tour' => null, 'food' => null];
                $amt = ['house' => 0.0, 'tour' => 0.0, 'food' => 0.0];
                foreach ($items as $k => $p) {
                    $ref = $reference . '-' . strtoupper($k[0]);
                    if ($k === 'house') $ids[$k] = self::insertHouse($pdo, $p, $guest, $ref, null, false);
                    if ($k === 'tour')  $ids[$k] = self::insertTour($pdo, $p, $guest, $ref, null, false);
                    if ($k === 'food')  $ids[$k] = self::insertFood($pdo, $p, $guest, $ref, null, false);
                    $amt[$k] = $p['price'];
                    $logItems[] = ['type' => $k, 'name' => $p['name'], 'amount' => $p['price']];
                }
                $total = round($amt['house'] + $amt['tour'] + $amt['food'], 2);
                $pdo->prepare("INSERT INTO package_bookings
                    (reference_number, guest_id, house_booking_id, tour_booking_id, food_booking_id,
                     house_amount, tour_amount, food_amount, grand_total, reservation_fee_amount,
                     contact_number, special_requests, gcash_reference, payment_status, booking_status, booking_source, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'walk_in', NOW())")
                    ->execute([$reference, $guest['id'], $ids['house'], $ids['tour'], $ids['food'],
                               $amt['house'], $amt['tour'], $amt['food'], $total, PaymentService::feeFor($total),
                               $guest['contact'], self::clip($in['special_requests'] ?? '', 1000), $gcashRef]);
                $bookingId = (int)$pdo->lastInsertId();
                foreach (['house' => 'house_bookings', 'tour' => 'tour_bookings', 'food' => 'food_bookings'] as $k => $tbl) {
                    if ($ids[$k]) $pdo->prepare("UPDATE `$tbl` SET package_id = ? WHERE id = ?")->execute([$bookingId, $ids[$k]]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $actor = ucfirst($staffRole === 'admin' ? 'admin' : 'staff');
        if (class_exists('SystemLogger')) {
            SystemLogger::log($pdo, 'create', 'booking',
                "{$actor} created walk-in booking — Guest: {$guest['name']} — Reference: {$reference} — Source: Walk-in",
                $bookingId, $type === 'package' ? 'package' : $type . '_booking', null,
                ['reference' => $reference, 'type' => $type, 'total' => $total, 'items' => $logItems,
                 'booking_source' => 'walk_in', 'guest_created' => $guest['created'], 'payment_option' => $payOpt]);
        }

        // Payment (separate step so the booking itself is never lost if recording fails).
        $payment = ['option' => $payOpt, 'recorded' => false, 'message' => 'Payment pending — the guest can pay the reservation fee later.'];
        if ($paying) {
            try {
                $methodLabel = $payOpt === 'cash' ? 'Cash' : 'GCash';
                $note = "Walk-in: reservation fee received via {$methodLabel} at the counter" . ($gcashRef ? " (ref {$gcashRef})" : '');
                $r = PaymentService::confirmReservation($pdo, $type, $bookingId, (int)$staffUserId, $payOpt, $note);
                if ($r['status'] === 'recorded') {
                    $payment = ['option' => $payOpt, 'recorded' => true, 'amount' => $r['amount'], 'payment_status' => $r['payment_status'],
                                'message' => "{$methodLabel} reservation fee of " . PaymentService::peso($r['amount']) . ' recorded. Booking confirmed.'];
                    if (class_exists('SystemLogger')) {
                        SystemLogger::log($pdo, 'confirm_payment', 'booking',
                            "{$actor} recorded walk-in {$methodLabel} reservation fee " . PaymentService::peso($r['amount']) . " — Reference: {$reference}",
                            $bookingId, $type === 'package' ? 'package' : $type . '_booking', null,
                            ['amount' => $r['amount'], 'method' => $payOpt, 'source' => 'walk_in']);
                    }
                } else {
                    $payment['message'] = 'Payment was already recorded for this booking.';
                }
            } catch (Throwable $e) {
                error_log('WalkInBookingService payment failed: ' . $e->getMessage());
                $payment['message'] = 'Booking created, but the payment could NOT be recorded: ' . $e->getMessage() . ' The booking is Pending — record the payment from Booking Management.';
                $payment['error'] = true;
            }
        }

        return ['type' => $type, 'id' => $bookingId, 'reference' => $reference, 'total' => $total,
                'guest_id' => $guest['id'], 'guest_name' => $guest['name'], 'guest_created' => $guest['created'],
                'payment' => $payment];
    }
}

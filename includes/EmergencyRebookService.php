<?php
/**
 * EmergencyRebookService — "Request Emergency Rebook" (Phase 7.7).
 *
 * WHEN  A guest's booking is otherwise rebook-eligible but the NORMAL rebook is closed because
 *         (a) the 2-rebook limit is used up, and/or (b) the 7-day request window has expired.
 *       Those are the ONLY two rules an Emergency Rebook bypasses. Everything else is the normal rule, unchanged:
 *       booking status (never completed), ownership, paid credit (a cancelled booking needs money received), package
 *       child items, a tour/food date that already passed, and — for the NEW schedule — past date/time, availability,
 *       blocked dates, House capacity, Tour resource, Food delivery stay, Package House window and atomicity.
 *       Eligibility = RebookService::{house,service}BlockReason($b, true) is clean AND (limit used up OR window expired).
 *       If the normal rebook is still open, Emergency is NOT offered.
 *
 * FLOW  request()  -> row in `emergency_rebook_requests` (status pending). THE BOOKING IS NOT TOUCHED.
 *       approve()  -> Admin/Staff. ONE transaction, same lock order as every confirming path (booking [+ package parts]
 *                     -> request -> house -> tour -> food): re-checks everything, applies the schedule (all parts or none),
 *                     marks the request approved. Any failure rolls back, the request stays pending and reviewable.
 *       reject()   -> Admin/Staff, a reason is required. cancel() -> the guest, own pending request only.
 *       One active (pending) request per booking: UNIQUE(booking_type, booking_id, pending_lock) in the database AND a
 *       locked check. A normal pending rebook and an Emergency pending request can never exist together (both entry
 *       points check the other inside their locked transaction).
 *
 * MONEY Never touched. No ledger row (booking_payments), no second fee, no refund, amount_paid / payment_status / totals
 *       unchanged, a Package stays one financial transaction.
 * COUNT The normal `rebook_count` keeps its meaning (successful NORMAL rebooks). An approved Emergency Rebook does not
 *       change it; the approval is recorded in `emergency_rebook_requests` (+ the audit log), so the 2-rebook rule is
 *       not weakened and the emergency history stays separately auditable.
 */

require_once __DIR__ . '/RebookService.php';

class EmergencyRebookService {

    const TYPES = ['house', 'tour', 'food', 'package'];
    const REASON_MIN = 10;
    const REASON_MAX = 500;
    const NOTE_MAX = 500;
    const DUPLICATE_MESSAGE = 'You already have an Emergency Rebook request waiting for Admin/Staff approval for this booking.';
    const ELIGIBILITY_LABELS = [
        'limit_exhausted' => 'Rebook limit reached (2 of 2 used)',
        'window_expired'  => '7-day rebook window expired',
        'both'            => 'Rebook limit reached and 7-day window expired',
    ];
    const ELIGIBILITY_SHORT = ['limit_exhausted' => 'Limit reached', 'window_expired' => 'Window expired', 'both' => 'Limit reached + window expired'];

    private static $ready = null;

    /** Is the Phase 7.7 table there? (Pages hide the feature if the migration has not been run.) */
    public static function tableReady(PDO $pdo) {
        if (self::$ready !== null) return self::$ready;
        try { $pdo->query('SELECT id FROM `emergency_rebook_requests` LIMIT 0'); self::$ready = true; }
        catch (PDOException $e) { self::$ready = false; }
        return self::$ready;
    }

    // ------------------------------------------------------------------ eligibility

    /**
     * ['eligible' => bool, 'reason' => limit_exhausted|window_expired|both|null, 'message' => guest-safe text when not eligible].
     * $b = the booking row as stored (house/tour/food/package).
     */
    public static function eligibility($type, array $b) {
        if (!in_array($type, self::TYPES, true)) return ['eligible' => false, 'reason' => null, 'message' => 'Unknown booking type.'];
        $why = $type === 'house' ? RebookService::houseBlockReason($b, true) : RebookService::serviceBlockReason($type, $b, true);
        if ($why !== null) return ['eligible' => false, 'reason' => null, 'message' => $why];
        $limit = RebookService::remaining($b) <= 0;
        $window = RebookService::daysLeft($b) <= 0;
        if (!$limit && !$window) return ['eligible' => false, 'reason' => null, 'message' => 'Normal rebooking is still available for this booking, so an Emergency Rebook is not needed.'];
        return ['eligible' => true, 'reason' => $limit && $window ? 'both' : ($limit ? 'limit_exhausted' : 'window_expired'), 'message' => ''];
    }

    public static function canRequest($type, array $b) { return self::eligibility($type, $b)['eligible']; }

    // ------------------------------------------------------------------ input

    /** Reason text: trimmed, control characters removed, 10..500 characters. Throws InvalidArgumentException (guest-safe). */
    public static function cleanReason($raw) {
        if (!is_scalar($raw)) throw new InvalidArgumentException('Please tell us the reason for your Emergency Rebook request.');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$raw);
        $s = trim(preg_replace('/\r\n?/', "\n", (string)$s));
        $len = mb_strlen($s, 'UTF-8');
        if ($len === 0) throw new InvalidArgumentException('Please tell us the reason for your Emergency Rebook request.');
        if ($len < self::REASON_MIN) throw new InvalidArgumentException('Please give a little more detail about the reason (at least ' . self::REASON_MIN . ' characters).');
        if ($len > self::REASON_MAX) throw new InvalidArgumentException('The reason is too long (maximum ' . self::REASON_MAX . ' characters).');
        return $s;
    }

    /** PH mobile number -> '+639XXXXXXXXX'. Accepts 9123456789, 09123456789, 639123456789, +63 912 345 6789. */
    public static function normalizeContact($raw) {
        if (!is_scalar($raw)) throw new InvalidArgumentException('Please enter your contact number.');
        $d = preg_replace('/\D+/', '', (string)$raw);
        if ($d === '') throw new InvalidArgumentException('Please enter your contact number.');
        if (strlen($d) === 12 && strpos($d, '63') === 0) $d = substr($d, 2);
        elseif (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
        if (!preg_match('/^9\d{9}$/', $d)) throw new InvalidArgumentException('Please enter a valid PH mobile number (e.g., 9123456789).');
        return '+63' . $d;
    }

    /** Posted schedule fields -> plan() input. Only these fields are read from the browser. */
    public static function inputFromPost($type, array $src) {
        if ($type === 'house') {
            $g = function ($k) use ($src) { return isset($src[$k]) && is_scalar($src[$k]) ? trim((string)$src[$k]) : ''; };
            // Phase 7.9.3: the guest may also choose the check-in TIME (key absent = keep the saved time). Check-out time is never read from the browser.
            $timeKey = array_key_exists('check_in_time', $src) ? 'check_in_time' : (array_key_exists('new_time', $src) ? 'new_time' : null);
            $time = null;
            if ($timeKey !== null) $time = is_scalar($src[$timeKey]) ? trim((string)$src[$timeKey]) : '';   // '' / non-scalar -> rejected by planHouse as invalid
            return ['date' => $g('new_date') !== '' ? $g('new_date') : $g('check_in'), 'guests' => $g('new_guests') !== '' ? $g('new_guests') : $g('guests'), 'time' => $time];   // rebook.php field names
        }
        return RebookService::inputFromRequest($type, $src);
    }

    private static function isDuplicateKey(PDOException $e) {
        return ($e->errorInfo[1] ?? null) == 1062 || strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'duplicate') !== false;
    }

    private static function planFor(PDO $pdo, $type, array $b, array $in, $parts, $lock) {
        if ($type === 'house') return RebookService::planHouse($pdo, $b, $in['date'] ?? '', $in['guests'] ?? '', $lock, array_key_exists('time', $in) ? $in['time'] : null);
        return RebookService::plan($pdo, $type, $b, $in, $parts, $lock);
    }

    /** Read-only validation of a schedule for the guest page's live preview (same rules the request and the approval run). */
    public static function preview(PDO $pdo, $type, array $b, array $in) {
        return self::planFor($pdo, $type, $b, $in, $type === 'package' ? RebookService::packageView($pdo, $b) : null, false);
    }

    /** House schedule -> input for planHouse (used by approval). */
    private static function inputFromSchedule($type, array $s) {
        if ($type === 'house') return ['date' => (string)($s['in'] ?? ''), 'guests' => (string)($s['guests'] ?? ''), 'time' => isset($s['in_time']) ? (string)$s['in_time'] : null];   // Phase 7.9.3: re-plan with the requested check-in time
        return RebookService::inputFromSchedule($type, $s);
    }

    private static function decode(array $r) {
        $r['original'] = json_decode((string)$r['original_schedule'], true) ?: [];
        $r['requested'] = json_decode((string)$r['requested_schedule'], true) ?: [];
        return $r;
    }

    // ------------------------------------------------------------------ guest: request

    /**
     * The guest submits an Emergency Rebook REQUEST. Nothing on the booking changes.
     * $in = inputFromPost(). Throws RuntimeException / InvalidArgumentException with guest-safe messages.
     * Returns ['id','reference','type','original','requested','eligibility'].
     */
    public static function request(PDO $pdo, $type, $id, $guestId, array $in, $reason, $contact, $userId) {
        if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException('Unknown booking type.');
        if (!self::tableReady($pdo)) throw new RuntimeException('Emergency Rebook is temporarily unavailable. Please contact us.');
        $reason = self::cleanReason($reason);
        $contact = self::normalizeContact($contact);
        $table = PaymentService::TABLES[$type];
        $pdo->beginTransaction();
        try {
            $b = RebookService::lockBooking($pdo, $table, $id);                                    // 1. the booking (and package parts)
            if ((int)$b['guest_id'] !== (int)$guestId) throw new RuntimeException('Booking not found.');
            $parts = $type === 'package' ? PaymentService::lockPackageParts($pdo, $b) : null;
            $el = self::eligibility($type, $b);
            if (!$el['eligible']) throw new RuntimeException($el['message']);
            if ($type !== 'house' && RebookService::pendingRequest($pdo, $type, $b['id'], true)) throw new RuntimeException(RebookService::PENDING_MESSAGE);   // normal pending: never both
            if (RebookService::emergencyPending($pdo, $type, $b['id'], true)) throw new RuntimeException(self::DUPLICATE_MESSAGE);                          // 2. the request
            $r = self::planFor($pdo, $type, $b, $in, $parts, false);
            if (!$r['ok']) throw new InvalidArgumentException($r['message']);
            $orig = RebookService::currentSchedule($type, $b, $parts);
            $ins = $pdo->prepare("INSERT INTO emergency_rebook_requests
                    (booking_type, booking_id, reference_number, guest_id, status, pending_lock, eligibility, regular_rebook_count, original_status,
                     original_schedule, requested_schedule, reason, contact_number, requested_by, requested_role, requested_at)
                    VALUES (?, ?, ?, ?, 'pending', 1, ?, ?, ?, ?, ?, ?, ?, ?, 'guest', NOW())");
            $ins->execute([$type, (int)$b['id'], (string)$b['reference_number'], (int)$b['guest_id'], $el['reason'], (int)($b['rebook_count'] ?? 0), (string)$b['booking_status'],
                           json_encode($orig), json_encode($r['schedule']), $reason, $contact, $userId ?: null]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
            return ['id' => $newId, 'reference' => $b['reference_number'], 'type' => $type, 'original' => $orig, 'requested' => $r['schedule'], 'eligibility' => $el['reason']];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (self::isDuplicateKey($e)) throw new RuntimeException(self::DUPLICATE_MESSAGE);      // a second request raced this one
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------ lookups

    public static function pendingRequest(PDO $pdo, $type, $bookingId) {
        if (!self::tableReady($pdo)) return null;
        $st = $pdo->prepare("SELECT * FROM emergency_rebook_requests WHERE booking_type = ? AND booking_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $st->execute([(string)$type, (int)$bookingId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::decode($r) : null;
    }

    public static function pendingCount(PDO $pdo) {
        if (!self::tableReady($pdo)) return 0;
        try { return (int)$pdo->query("SELECT COUNT(*) FROM emergency_rebook_requests WHERE status = 'pending'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    /** [type][booking_id] => pending request (one guest's, or all), for marking rows in lists. */
    public static function pendingMap(PDO $pdo, $guestId = null) {
        $map = ['house' => [], 'tour' => [], 'food' => [], 'package' => []];
        if (!self::tableReady($pdo)) return $map;
        try {
            $st = $pdo->prepare("SELECT * FROM emergency_rebook_requests WHERE status = 'pending'" . ($guestId !== null ? ' AND guest_id = ?' : '') . ' ORDER BY id');
            $st->execute($guestId !== null ? [(int)$guestId] : []);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['booking_type']][(int)$r['booking_id']] = self::decode($r);
        } catch (PDOException $e) { error_log('[EmergencyRebook] pendingMap: ' . $e->getMessage()); }
        return $map;
    }

    /**
     * The latest REJECTED request of each booking of one guest decided in the last $days days, for the guest's own screen:
     * [type][booking_id] => ['id','reviewed_at','review_note','reference_number']. Reviewer identity is never exposed.
     */
    public static function recentRejections(PDO $pdo, $guestId, $days = 14) {
        $out = ['house' => [], 'tour' => [], 'food' => [], 'package' => []];
        if (!self::tableReady($pdo)) return $out;
        try {
            $st = $pdo->prepare("SELECT id, booking_type, booking_id, reference_number, reviewed_at, review_note FROM emergency_rebook_requests
                                  WHERE guest_id = ? AND status = 'rejected' AND reviewed_at >= DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY) ORDER BY id");
            $st->execute([(int)$guestId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['booking_type']][(int)$r['booking_id']] = $r;   // later rows (newer) win
        } catch (PDOException $e) {}
        // a newer pending request hides an older rejection
        return $out;
    }

    /**
     * Pending requests for Booking Management, newest first, each with the booking facts the review needs: guest name,
     * item name, payment state/amounts, current rebook count and the contact/reason the guest gave. One query per table.
     */
    public static function pendingRequests(PDO $pdo, $guestId = null) {
        if (!self::tableReady($pdo)) return [];
        try {
            $st = $pdo->prepare("SELECT r.*, COALESCE(NULLIF(g.full_name, ''), NULLIF(u.fullname, ''), u.username, 'Guest') AS guest_name
                                   FROM emergency_rebook_requests r LEFT JOIN guests g ON g.id = r.guest_id LEFT JOIN users u ON u.id = g.user_id
                                  WHERE r.status = 'pending'" . ($guestId !== null ? ' AND r.guest_id = ?' : '') . ' ORDER BY r.requested_at DESC, r.id DESC');
            $st->execute($guestId !== null ? [(int)$guestId] : []);
            $rows = array_map([self::class, 'decode'], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log('[EmergencyRebook] pendingRequests: ' . $e->getMessage());
            return [];
        }
        $ids = [];
        foreach ($rows as $r) $ids[$r['booking_type']][] = (int)$r['booking_id'];
        $item = ['house' => ['houses', 'house_id', 'house_name'], 'tour' => ['tours', 'tour_id', 'tour_name'], 'food' => ['food_items', 'food_id', 'name']];
        $info = [];
        foreach ($ids as $type => $list) {
            $table = PaymentService::TABLES[$type];
            $ph = implode(',', array_fill(0, count($list), '?'));
            $nameSql = isset($item[$type]) ? ", (SELECT i.`{$item[$type][2]}` FROM `{$item[$type][0]}` i WHERE i.id = b.`{$item[$type][1]}`) AS item_name" : ", '' AS item_name";
            try {
                $q = $pdo->prepare("SELECT b.*$nameSql FROM `$table` b WHERE b.id IN ($ph)");
                $q->execute($list);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) $info[$type][(int)$b['id']] = $b;
            } catch (PDOException $e) { error_log('[EmergencyRebook] lookup ' . $table . ': ' . $e->getMessage()); }
        }
        foreach ($rows as $k => $r) $rows[$k]['booking'] = $info[$r['booking_type']][(int)$r['booking_id']] ?? null;
        return $rows;
    }

    // ------------------------------------------------------------------ Admin / Staff: approve

    private static function peek(PDO $pdo, $requestId) {
        if (!self::tableReady($pdo)) return null;
        $st = $pdo->prepare('SELECT id, booking_type, booking_id FROM emergency_rebook_requests WHERE id = ?');
        $st->execute([(int)$requestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ($r && in_array($r['booking_type'], self::TYPES, true)) ? $r : null;
    }

    private static function lockRequest(PDO $pdo, $requestId) {
        $st = $pdo->prepare('SELECT * FROM emergency_rebook_requests WHERE id = ? FOR UPDATE');
        $st->execute([(int)$requestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::decode($r) : null;
    }

    /**
     * Approve. ONE transaction. Lock order: booking (+ package parts) -> request -> house -> tour -> food. Verifies the booking is
     * still exactly as it was when the guest asked, re-runs the full validation with the items locked (availability, blocked
     * dates, capacity, tour resource, delivery stay, package window, past date/time), applies EVERY part or NONE, and marks the
     * request approved. The only rules not re-applied are the 2-rebook limit and the 7-day window. `rebook_count` and all money
     * fields are left untouched.
     * Throws AvailabilityConflictException (new schedule no longer available) or RuntimeException (staff-safe message); on any
     * failure nothing changed and the request is still pending.
     */
    public static function approve(PDO $pdo, $requestId, $userId, $role) {
        $peek = self::peek($pdo, $requestId);
        if (!$peek) throw new RuntimeException('Emergency Rebook request not found.');
        $type = $peek['booking_type'];
        $table = PaymentService::TABLES[$type];
        $pdo->beginTransaction();
        try {
            $b = RebookService::lockBooking($pdo, $table, $peek['booking_id']);
            $parts = $type === 'package' ? PaymentService::lockPackageParts($pdo, $b) : null;
            $req = self::lockRequest($pdo, $requestId);
            if (!$req || $req['status'] !== 'pending') throw new RuntimeException('This Emergency Rebook request has already been handled. Please refresh.');
            if ((int)$b['guest_id'] !== (int)$req['guest_id']) throw new RuntimeException('This request does not match its booking. Please reject it.');
            if ((string)$b['booking_status'] !== (string)$req['original_status']) {
                throw new RuntimeException('The booking changed after the request (it is now ' . $b['booking_status'] . '). Reject this request; the guest can submit a new one.');
            }
            // Business rules (everything except the limit / window): same as the normal confirmation plus the house ones.
            if (!in_array($b['booking_status'], ['confirmed', 'cancelled'], true)) throw new RuntimeException('Only confirmed or paid-cancelled bookings can be rebooked (completed bookings cannot).');
            if (!empty($b['package_id'])) throw new RuntimeException('This item is part of a package. Rebook the whole package instead.');
            if ($type === 'house' && !empty($b['original_booking_id'])) throw new RuntimeException('This is an old rebooking record.');
            $paid = $type === 'house' ? PaymentService::amounts($b)['paid'] : (float)($b['amount_paid'] ?? 0);
            if ($paid <= 0) throw new RuntimeException('Only bookings with a paid reservation fee can be rebooked.');
            if ($type === 'house' && !empty($b['rebooked_at']) && empty($b['rebook_confirmed_at'])) throw new RuntimeException('A normal rebook request is waiting for this booking. Decide it first.');
            if ($type !== 'house' && RebookService::pendingRequest($pdo, $type, $b['id'], true)) throw new RuntimeException('A normal rebook request is waiting for this booking. Decide it first.');

            $cur = RebookService::currentSchedule($type, $b, $parts);
            if (json_encode($cur) !== json_encode($req['original'])) {
                throw new RuntimeException('The booking schedule changed after the request. Reject this request; the guest can submit a new one.');
            }
            $r = self::planFor($pdo, $type, $b, self::inputFromSchedule($type, $req['requested']), $parts, true);
            if (!$r['ok']) throw new AvailabilityConflictException($r['message']);
            if (json_encode($r['schedule']) !== json_encode($req['requested'])) throw new RuntimeException('The requested schedule could not be applied exactly. Reject this request; the guest can submit a new one.');

            if ($type === 'house') self::applyHouse($pdo, $b, $r['schedule'], $userId);
            else RebookService::applySchedule($pdo, $type, $b, $parts, $cur, $r['schedule'], $userId, false);   // false: normal rebook_count untouched

            $up = $pdo->prepare("UPDATE emergency_rebook_requests SET status = 'approved', pending_lock = NULL, reviewed_by = ?, reviewed_role = ?, reviewed_at = NOW()
                                  WHERE id = ? AND status = 'pending'");
            $up->execute([$userId ?: null, substr((string)$role, 0, 20), (int)$req['id']]);
            if ($up->rowCount() !== 1) throw new RuntimeException('This request changed while approving. Please refresh.');
            $pdo->commit();
            return ['type' => $type, 'booking_id' => (int)$b['id'], 'reference' => $b['reference_number'], 'request' => $req,
                    'old_date' => RebookService::scheduleStart($type, $cur), 'new_date' => RebookService::scheduleStart($type, $r['schedule']),
                    'rebook_count' => (int)($b['rebook_count'] ?? 0)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** House: new dates/guests on the booking row (the previous values are kept in previous_* like a normal confirmed rebook). */
    private static function applyHouse(PDO $pdo, array $b, array $new, $userId) {
        // Phase 7.9.3: the check-in/out TIMES move with the dates, and the old ones are kept in previous_*_time (only when those columns exist).
        $withTimes = RebookService::houseTimeColumnsReady($pdo) && !empty($new['in_time']) && !empty($new['out_time']);
        $old = RebookService::houseTimes($b);
        $up = $pdo->prepare("UPDATE house_bookings SET
                previous_check_in_date = ?, previous_check_out_date = ?, previous_number_of_guests = ?, previous_total_amount = ?, previous_booking_status = NULL,
                " . ($withTimes ? "previous_check_in_time = ?, previous_check_out_time = ?, check_in_time = ?, check_out_time = ?," : "") . "
                check_in_date = ?, check_out_date = ?, number_of_guests = ?,
                booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL, rebooked_at = NOW(), rebook_confirmed_at = NOW()
              WHERE id = ? AND booking_status IN ('confirmed','cancelled')");
        $args = [$b['check_in_date'], $b['check_out_date'], $b['number_of_guests'], $b['total_amount']];
        if ($withTimes) { $args[] = $old['in']; $args[] = $old['out']; $args[] = $new['in_time']; $args[] = $new['out_time']; }
        $up->execute(array_merge($args, [$new['in'], $new['out'], (int)$new['guests'], (int)$b['id']]));
        if ($up->rowCount() !== 1) throw new RuntimeException('The booking changed while approving. Please refresh.');
        AvailabilityService::releaseForBooking($pdo, 'house', $b['house_id'], $b['reference_number']);
        AvailabilityService::blockForBooking($pdo, 'house', $b['house_id'], $new['in'], $new['out'], $b['reference_number'], $userId ?: null);
    }

    // ------------------------------------------------------------------ reject / guest cancel

    private static function close(PDO $pdo, $requestId, $newStatus, $actorId, $actorRole, $note, $guestId) {
        $peek = self::peek($pdo, $requestId);
        if (!$peek) throw new RuntimeException('Emergency Rebook request not found.');
        $table = PaymentService::TABLES[$peek['booking_type']];
        $pdo->beginTransaction();
        try {
            $b = RebookService::lockBooking($pdo, $table, $peek['booking_id']);
            $req = self::lockRequest($pdo, $requestId);
            if (!$req) throw new RuntimeException('Emergency Rebook request not found.');
            if ($guestId !== null && ((int)$req['guest_id'] !== (int)$guestId || (int)$b['guest_id'] !== (int)$guestId)) throw new RuntimeException('Emergency Rebook request not found.');
            if ($req['status'] !== 'pending') throw new RuntimeException('This Emergency Rebook request has already been handled.');
            $up = $pdo->prepare("UPDATE emergency_rebook_requests SET status = ?, pending_lock = NULL, reviewed_by = ?, reviewed_role = ?, reviewed_at = NOW(), review_note = ?
                                  WHERE id = ? AND status = 'pending'");
            $up->execute([$newStatus, $actorId ?: null, substr((string)$actorRole, 0, 20), $note !== null ? mb_substr((string)$note, 0, self::NOTE_MAX) : null, (int)$req['id']]);
            if ($up->rowCount() !== 1) throw new RuntimeException('This request changed meanwhile. Please refresh.');
            $pdo->commit();
            return $req;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Reject needs a reason (3..500 characters). Returns the request row as it was. */
    public static function reject(PDO $pdo, $requestId, $userId, $role, $note) {
        $note = trim((string)$note);
        if ($note === '') throw new InvalidArgumentException('Please enter the reason for rejecting this Emergency Rebook request.');
        if (mb_strlen($note, 'UTF-8') < 3) throw new InvalidArgumentException('Please give a clearer reason for rejecting this request.');
        if (mb_strlen($note, 'UTF-8') > self::NOTE_MAX) throw new InvalidArgumentException('The rejection reason is too long (maximum ' . self::NOTE_MAX . ' characters).');
        return self::close($pdo, $requestId, 'rejected', $userId, $role, $note, null);
    }

    /** The guest cancels their own pending request ($guestId = guests.id). */
    public static function cancel(PDO $pdo, $requestId, $guestId, $userId) {
        return self::close($pdo, $requestId, 'cancelled', $userId, 'guest', 'Cancelled by the guest', (int)$guestId);
    }

    // ------------------------------------------------------------------ audit log

    /**
     * Audit entry for one Emergency event: service, reference, actor role and request id are always included; no SQL, no
     * payment-proof data, no other guests' data. $event: request | approve | reject | cancel.
     */
    public static function log(PDO $pdo, $event, array $req, $actorRole, array $extra = []) {
        if (!class_exists('SystemLogger')) return;
        $verbs = ['request' => 'requested', 'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled by the guest'];
        if (!isset($verbs[$event])) return;
        $type = (string)$req['booking_type'];
        $role = $actorRole === 'admin' ? 'admin' : ($actorRole === 'staff' ? 'staff' : 'guest');
        $desc = 'Emergency Rebook ' . $verbs[$event] . ': ' . $type . ' booking ' . $req['reference_number'] . ' (request #' . (int)$req['id'] . ') by ' . $role;
        $new = array_merge(['service' => $type, 'reference' => $req['reference_number'], 'actor_role' => $role, 'request_id' => (int)$req['id']], $extra);
        SystemLogger::log($pdo, 'emergency_rebook_' . $event, 'booking', $desc, (int)$req['booking_id'], $type . '_booking', null, $new, $event === 'reject' ? 'warning' : 'success');
    }
}

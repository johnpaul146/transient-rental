<?php
/**
 * RebookService — "no refunds, rebook instead" for every service.
 *
 *  HOUSE    Guest requests new dates (profile.php / rebook.php); owner/staff approve.
 *           The booking row itself carries the request (previous_* columns keep the original), as before.
 *           confirmHouseRebook() re-checks the new dates with the house locked and changes nothing if they
 *           were taken in the meantime. Reject / guest Cancel restore the previous_* values (locked, Phase 7.6).
 *  TOUR / FOOD / PACKAGE  (Phase 7.6)
 *           The guest (or staff assisting a guest) submits a REQUEST: a new date + time (tour/food), or for a
 *           package an independent schedule per part (house check-in date + time + guests, tour date + time,
 *           food date + time). The request is stored in `rebook_requests`; the booking itself is NOT touched
 *           while it waits. Admin/Staff Confirm applies it in ONE transaction after re-checking everything with
 *           the items locked (house -> tour -> food); Reject or guest Cancel only close the request.
 *           At most one pending request per booking (unique key + locked check).
 *
 *  In every case the money already received stays on the booking
 *  (amount_paid is never touched, no ledger row is written, no second
 *  ₱1,000 fee, price unchanged). Limits: 2 successful rebooks per booking, requested within 7 days of the
 *  booking date — the same rule as house rebooking. A pending request does not use up a rebook: the count
 *  goes up only when a request is confirmed (house keeps its own counting: +1 on request, -1 on reject/cancel).
 */

require_once __DIR__ . '/AvailabilityService.php';
require_once __DIR__ . '/PaymentService.php';

class RebookService {

    const MAX_REBOOKS = 2;
    const WINDOW_DAYS = 7;
    const SERVICE_TYPES = ['tour', 'food', 'package'];

    /** Remaining rebooks for ONE booking (the backend rule). */
    public static function remaining(array $booking) {
        return max(0, self::MAX_REBOOKS - (int)($booking['rebook_count'] ?? 0));
    }

    /** Days left in the 7-day rebook window (0 = expired). */
    public static function daysLeft(array $booking) {
        if (empty($booking['created_at'])) return 0;
        try {
            $created = new DateTime(substr((string)$booking['created_at'], 0, 10));
            $today = new DateTime('today');
            $elapsed = (int)$today->diff($created)->days;
            // eligible through day 7 (booking day = day 0); count includes today
            return max(0, self::WINDOW_DAYS + 1 - $elapsed);
        } catch (Exception $e) { return 0; }
    }

    /**
     * Why a tour/food/package booking cannot be rebooked, or null if it can.
     * $booking is the row as stored (for packages: the package_bookings row).
     * $ignoreLimitWindow (Phase 7.7, Emergency Rebook eligibility ONLY): skips exactly two rules — the 2-rebook limit and
     * the 7-day window. Every other rule (status, paid credit, package child, passed date) still applies.
     */
    public static function serviceBlockReason($type, array $booking, $ignoreLimitWindow = false) {
        if (!in_array($type, self::SERVICE_TYPES, true)) return 'Unknown booking type.';
        if ($type !== 'package' && !empty($booking['package_id'])) return 'This item is part of a package. Rebook the whole package instead.';
        // Same rule for every service: confirmed or cancelled WITH money received; never completed.
        if (!in_array($booking['booking_status'] ?? '', ['confirmed', 'cancelled'], true)) {
            return 'Only confirmed bookings, or paid bookings that were cancelled, can be rebooked (completed bookings cannot).';
        }
        if ((float)($booking['amount_paid'] ?? 0) <= 0) return 'Only bookings with a paid reservation fee can be rebooked.';
        if (!$ignoreLimitWindow) {
            if (self::remaining($booking) <= 0) return 'This booking has already been rebooked ' . self::MAX_REBOOKS . ' times (maximum).';
            if (self::daysLeft($booking) <= 0) return 'Rebooking is only possible within ' . self::WINDOW_DAYS . ' days from booking date.';
        }
        // A confirmed tour whose date has already passed has taken place; only a cancelled-with-credit one may move.
        if ($type === 'tour' && ($booking['booking_status'] ?? '') !== 'cancelled'
            && !empty($booking['booking_date']) && $booking['booking_date'] < date('Y-m-d')) {
            return 'This tour date has already passed, so it can no longer be rebooked.';
        }
        // Same for food: a confirmed order whose date has passed has been served.
        if ($type === 'food' && ($booking['booking_status'] ?? '') !== 'cancelled'
            && !empty($booking['preferred_date']) && $booking['preferred_date'] < date('Y-m-d')) {
            return 'This food order date has already passed, so it can no longer be rebooked.';
        }
        return null;
    }

    public static function canRebookService($type, array $booking) {
        return self::serviceBlockReason($type, $booking) === null;
    }

    private static function parseDate($d) {
        $d = trim((string)$d);
        $dt = DateTime::createFromFormat('!Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d) throw new InvalidArgumentException('Please choose a valid date.');
        if ($dt < new DateTime('today')) throw new InvalidArgumentException('Selected date is no longer available. Please choose a future date.');
        if ($dt > (new DateTime('today'))->modify('+365 days')) throw new InvalidArgumentException('Please choose a date within the next 12 months.');
        return $dt;
    }

    /**
     * A DELIVERY order must land inside a confirmed stay of the guest, at the same unit it was booked for
     * (the unit never changes). Uses the same rule as ordering (AvailabilityService::foodDeliveryResolve):
     * the new date and the unchanged time must be inside the house window. Pickup orders: nothing to check.
     * Returns a guest-safe message or null.
     */
    private static function foodDeliveryError(PDO $pdo, array $b, $newYmd, $newTime = null) {
        if (($b['fulfillment_method'] ?? 'pickup') !== 'delivery') return null;
        $unit = trim((string)($b['delivery_address'] ?? ''));
        $time = $newTime !== null ? (string)$newTime : (string)($b['preferred_time'] ?? '');
        $o = AvailabilityService::foodDeliveryStays($pdo, (int)$b['guest_id']);
        $cands = [];
        foreach ($o['stays'] as $st) if ($unit === '' || strcasecmp($st['house_name'], $unit) === 0) $cands[] = $st;
        if (!$cands) {
            return 'Delivery needs a confirmed house stay' . ($unit !== '' ? ' at ' . $unit : '') . '. No such stay was found, so this order cannot be moved.';
        }
        $err = null;
        foreach ($cands as $st) {
            try { AvailabilityService::foodDeliveryResolve($pdo, (int)$b['guest_id'], $newYmd, $time, $st['id']); return null; }
            catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
        }
        if (count($cands) > 1) {
            $w = [];
            foreach ($cands as $st) { $win = AvailabilityService::packageWindow(['check_in' => $st['check_in'], 'check_in_time' => $st['check_in_time'], 'check_out' => $st['check_out'], 'check_out_time' => $st['check_out_time']]); if ($win) $w[] = $win['start_label'] . ' to ' . $win['end_label']; }
            return 'Food delivery must be during your stay at ' . $unit . ' (' . implode('; ', $w) . ').';
        }
        return $err;
    }

    public static function lockBooking(PDO $pdo, $table, $id) {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? FOR UPDATE");
        $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Booking not found.');
        return $row;
    }

    // ------------------------------------------------------------------
    // HOUSE — owner approval of a requested rebook
    // ------------------------------------------------------------------

    /**
     * Approve a pending house rebook. Throws AvailabilityConflictException if
     * the new dates are taken (nothing is changed then).
     */
    public static function confirmHouseRebook(PDO $pdo, $bookingId, $userId) {
        $pdo->beginTransaction();
        try {
            $b = self::lockBooking($pdo, 'house_bookings', $bookingId);
            if (empty($b['rebooked_at']) || !empty($b['rebook_confirmed_at']) || $b['booking_status'] !== 'pending') {
                throw new RuntimeException('This booking has no rebook request waiting for approval.');
            }
            AvailabilityService::lockItem($pdo, 'house', $b['house_id']);
            AvailabilityService::assertFree(
                AvailabilityService::houseConflict($pdo, $b['house_id'], $b['check_in_date'], $b['check_out_date'], [$b['id']], [$b['reference_number']], $b['check_in_time'] ?? '14:00:00', $b['check_out_time'] ?? '12:00:00'),
                'House');
            $up = $pdo->prepare("UPDATE house_bookings
                                    SET booking_status = 'confirmed', rebook_confirmed_at = NOW(), previous_booking_status = NULL,
                                        cancelled_at = NULL, cancellation_reason = NULL
                                  WHERE id = ? AND booking_status = 'pending' AND rebook_confirmed_at IS NULL");
            $up->execute([(int)$b['id']]);
            if ($up->rowCount() !== 1) throw new RuntimeException('The rebook request changed while confirming. Please refresh.');
            // Old dates are free again; the new nights are blocked. The amount already paid is carried forward.
            AvailabilityService::releaseForBooking($pdo, 'house', $b['house_id'], $b['reference_number']);
            AvailabilityService::blockForBooking($pdo, 'house', $b['house_id'], $b['check_in_date'], $b['check_out_date'], $b['reference_number'], $userId ?: null);
            $pdo->commit();
            return $b;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // HOUSE — guest REQUESTS a rebook (rebook.php). Rules unchanged: 2 per booking, 7-day window,
    // same number of nights, the guest may change the check-in TIME (check-out time follows it, never edited
    // separately — Phase 7.9.3), payment carried forward, status 'pending' until confirmHouseRebook() above.
    // ------------------------------------------------------------------

    /** Why this house booking cannot be rebooked right now, or null if it can. $b = the house_bookings row. */
    public static function houseBlockReason(array $b, $ignoreLimitWindow = false) {   // $ignoreLimitWindow: Emergency Rebook eligibility only (Phase 7.7)
        if (!empty($b['original_booking_id'])) return 'This is an old rebooking record.';
        if (!empty($b['package_id'])) return 'This house is part of a package. Rebook the whole package instead.';
        if (($b['booking_status'] ?? '') === 'pending' && !empty($b['rebooked_at']) && empty($b['rebook_confirmed_at'])) {
            return 'A rebook request for this booking is already waiting for confirmation. You can cancel it from your dashboard if you want to choose other dates.';
        }
        if (!in_array($b['booking_status'] ?? '', ['confirmed', 'cancelled'], true)) {
            return 'Only confirmed bookings or paid cancelled reservations can be rebooked (completed stays cannot).';
        }
        if (PaymentService::amounts($b)['paid'] <= 0) return 'Only bookings with a paid reservation fee can be rebooked.';
        if ($ignoreLimitWindow) return null;
        $count = (int)($b['rebook_count'] ?? 0);
        if ($count >= self::MAX_REBOOKS) {
            return 'You have already rebooked this booking ' . $count . ' time(s). Maximum of ' . self::MAX_REBOOKS . ' rebooks allowed per booking.';
        }
        if (self::daysLeft($b) <= 0) {
            $when = !empty($b['created_at']) ? ' (' . date('M d, Y', strtotime(substr((string)$b['created_at'], 0, 10))) . ')' : '';
            return 'Rebook option expired. You can only rebook within ' . self::WINDOW_DAYS . ' days from booking date' . $when . '. The amount already paid is carried forward when you rebook.';
        }
        return null;
    }

    /** The house row plus its name and capacity (display + guest-count limit). */
    public static function houseView(PDO $pdo, array $b) {
        $b['house_name'] = ''; $b['capacity'] = 0;
        try {
            $n = $pdo->prepare('SELECT house_name, capacity FROM houses WHERE id = ?'); $n->execute([(int)$b['house_id']]);
            if ($h = $n->fetch(PDO::FETCH_ASSOC)) { $b['house_name'] = (string)$h['house_name']; $b['capacity'] = (int)$h['capacity']; }
        } catch (PDOException $e) {}
        return $b;
    }

    /** Saved check-in / check-out times of the booking ('HH:MM:SS'). A rebook never changes them. */
    public static function houseTimes(array $b) {
        $t = function ($v, $default) {
            $v = trim((string)$v);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?/', $v)) return $default;
            $v = substr($v, 0, 8);
            return strlen($v) === 5 ? $v . ':00' : $v;
        };
        return ['in' => $t($b['check_in_time'] ?? '', '14:00:00'), 'out' => $t($b['check_out_time'] ?? '', '12:00:00')];
    }

    /**
     * Phase 7.9.3 — are the two history columns (previous_check_in_time / previous_check_out_time) there? They let a pending
     * rebook that changed the TIME be undone exactly. Until the migration has run, only the date can be changed (as before).
     */
    private static $houseTimeCols = null;
    public static function houseTimeColumnsReady(PDO $pdo) {
        if (self::$houseTimeCols !== null) return self::$houseTimeCols;
        try {
            $n = 0;
            foreach ($pdo->query("SHOW COLUMNS FROM house_bookings")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (in_array($c['Field'], ['previous_check_in_time', 'previous_check_out_time'], true)) $n++;
            }
            self::$houseTimeCols = ($n === 2);
        } catch (PDOException $e) { self::$houseTimeCols = false; }
        return self::$houseTimeCols;
    }

    const TIME_INVALID_MESSAGE = 'Please choose a valid check-in time.';
    const TIME_UNAVAILABLE_MESSAGE = 'Changing the check-in time is not available yet. Please keep your current check-in time.';

    /**
     * The check-in / check-out TIMES a House rebook would use. $time = what the guest chose ('HH:MM', or null = not sent = keep the
     * saved CHECK-IN time). Check-out time is NEVER taken from the browser and has NO legacy exception (Phase 7.9.3.1): it ALWAYS equals
     * the requested check-in time (AvailabilityService::houseStayTimes). A legacy 14:00 -> 12:00 booking therefore becomes 14:00 -> 14:00.
     * Returns ['in','out','changed' => bool]; 'changed' = the requested times differ from the saved ones (check-in OR check-out time),
     * which is what needs the previous_*_time columns. Throws InvalidArgumentException (guest-safe) for an empty or malformed time.
     */
    public static function houseRequestTimes(array $b, $time) {
        $cur = self::houseTimes($b);
        if ($time === null) $in = $cur['in'];
        else {
            if (!is_string($time) && !is_numeric($time)) throw new InvalidArgumentException(self::TIME_INVALID_MESSAGE);
            try { $t = AvailabilityService::houseStayTimes((string)$time); }
            catch (InvalidArgumentException $e) { throw new InvalidArgumentException(self::TIME_INVALID_MESSAGE); }
            $in = $t['in'];
        }
        $out = $in;
        return ['in' => $in, 'out' => $out, 'changed' => ($in !== $cur['in'] || $out !== $cur['out'])];
    }

    /** Number of nights of the booking as saved (this is the LOCKED stay length). */
    public static function houseNights(array $b) {
        return (int)(new DateTime(substr((string)$b['check_out_date'], 0, 10)))->diff(new DateTime(substr((string)$b['check_in_date'], 0, 10)))->days;
    }

    /**
     * Turn an availability message into text that is safe for a guest: other guests' booking references and
     * the owner's internal block notes are never shown. Dates are fine (the calendar already shows them).
     */
    public static function houseSafeMessage($msg) {
        $msg = (string)$msg;
        if (preg_match('/^Already reserved from (.+?) to (.+?) \(booking /', $msg, $m)) {
            return 'The house is already reserved from ' . $m[1] . ' to ' . $m[2] . '.';
        }
        if (preg_match('/^(.+?) is blocked/', $msg, $m)) return $m[1] . ' is not available (blocked by the owner).';
        return $msg;
    }

    const SAME_DATE_MESSAGE = 'Please choose a different check-in date or time.';

    /**
     * Would a stay starting on $newIn work? Same checks requestHouseRebook() makes (no locks — display only; the
     * real check runs again inside the transaction). The check-out DATE is always check-in + the original nights.
     * Returns ['ok' => bool, 'message' => string, 'plan' => ['in','out','in_time','out_time','nights','old_in','old_out']|null].
     */
    public static function houseDateCheck(PDO $pdo, array $b, $newIn, $time = null) {
        try { $new = self::parseDate($newIn); $times = self::houseRequestTimes($b, $time); }
        catch (InvalidArgumentException $e) { return ['ok' => false, 'message' => $e->getMessage(), 'plan' => null]; }
        $nights = self::houseNights($b);
        $in = $new->format('Y-m-d');
        $out = (clone $new)->modify('+' . $nights . ' days')->format('Y-m-d');
        $plan = ['in' => $in, 'out' => $out, 'in_time' => $times['in'], 'out_time' => $times['out'], 'nights' => $nights,
                 'old_in' => substr((string)$b['check_in_date'], 0, 10), 'old_out' => substr((string)$b['check_out_date'], 0, 10)];
        if ($nights < 1) return ['ok' => false, 'message' => 'This booking has no stay length to move.', 'plan' => $plan];
        if ($in === $plan['old_in'] && !$times['changed']) return ['ok' => false, 'message' => self::SAME_DATE_MESSAGE, 'plan' => $plan];
        if ($times['changed'] && !self::houseTimeColumnsReady($pdo)) return ['ok' => false, 'message' => self::TIME_UNAVAILABLE_MESSAGE, 'plan' => $plan];
        if ($pe = self::pastTimeError('Check-in', $in, $times['in'])) return ['ok' => false, 'message' => $pe, 'plan' => $plan];
        $m = AvailabilityService::houseConflict($pdo, $b['house_id'], $in, $out, [$b['id']], [$b['reference_number']], $times['in'], $times['out']);
        if ($m) {
            $kind = self::houseStartKind($pdo, $b['house_id'], $in, $times['in'], [$b['id']], [$b['reference_number']], $nights);
            return ['ok' => false, 'message' => self::startKindMessage($kind, $nights, self::houseSafeMessage($m)), 'plan' => $plan, 'kind' => $kind];
        }
        return ['ok' => true, 'message' => '', 'plan' => $plan];
    }

    /**
     * Phase 7.9 — calendar meaning only (read-only, no rule change). When a fixed-length stay cannot start on $in, say WHY:
     *   'hard'  = the night of $in itself is taken (a holding booking overlaps [in+time, in+1 day+time)) or can never be part of a
     *             stay (the date, or the day after it, is a hard owner/orphan block — a stay sleeping that night must check out on
     *             or after the next day) — no stay at this check-in time can include that night;
     *   'start' = that night is free, but the whole N-night stay from $in would run into a later booking/block. Such a day can
     *             still lie INSIDE a valid stay that starts earlier (exact-time turnover), so it must not look "booked".
     * Uses the same AvailabilityService::houseConflict() as every booking path; nothing here decides availability.
     */
    public static function houseStartKind(PDO $pdo, $houseId, $in, $time, array $excludeIds, array $ownRefs, $nights) {
        if ((int)$nights <= 1) return 'hard';
        try { $next = (new DateTime($in))->modify('+1 day')->format('Y-m-d'); } catch (Exception $e) { return 'hard'; }
        return AvailabilityService::houseConflict($pdo, $houseId, $in, $next, $excludeIds, $ownRefs, $time, $time) === null ? 'start' : 'hard';
    }

    /** Guest-facing reason for a day that cannot be a check-in. */
    public static function startKindMessage($kind, $nights, $why) {
        if ($kind !== 'start') return $why;
        return 'This date is free, but a ' . (int)$nights . '-night stay starting here would overlap another reservation. ' . $why;
    }

    /**
     * THE validation for a House rebook, without any side effect (Phase 7.7: used by Emergency Rebook request + approval; the
     * normal flow keeps its own inline copy above). Same rules as requestHouseRebook(): future date within 12 months, same
     * number of nights, saved check-in/out times, guests 1..capacity, a different check-in date, and availability (exact
     * datetime overlap, owner blocks). $lock = true locks the house first (approval). It does NOT look at the rebook limit
     * or window — callers decide eligibility. Returns ['ok','message','schedule','changed'].
     */
    public static function planHouse(PDO $pdo, array $b, $newIn, $guests, $lock = false, $time = null) {
        try { $new = self::parseDate($newIn); $times = self::houseRequestTimes($b, $time); }
        catch (InvalidArgumentException $e) { return self::planFail($e->getMessage()); }
        $v = self::houseView($pdo, $b);
        $gRaw = trim((string)$guests);
        if (!preg_match('/^[1-9]\d{0,3}$/', $gRaw)) return self::planFail('Please enter the number of guests.');
        $g = (int)$gRaw;
        if ($v['capacity'] > 0 && $g > $v['capacity']) return self::planFail('This house accommodates up to ' . $v['capacity'] . ' guests.');
        $nights = self::houseNights($b);
        if ($nights < 1) return self::planFail('This booking has no stay length to move.');
        $in = $new->format('Y-m-d');
        $out = (clone $new)->modify('+' . $nights . ' days')->format('Y-m-d');
        $s = ['house_id' => (int)$b['house_id'], 'in' => $in, 'in_time' => $times['in'], 'out' => $out, 'out_time' => $times['out'], 'nights' => $nights, 'guests' => $g];
        if ($in === substr((string)$b['check_in_date'], 0, 10) && !$times['changed']) return self::planFail(self::SAME_DATE_MESSAGE, $s);
        if ($times['changed'] && !self::houseTimeColumnsReady($pdo)) return self::planFail(self::TIME_UNAVAILABLE_MESSAGE, $s);
        if ($pe = self::pastTimeError('Check-in', $in, $times['in'])) return self::planFail($pe, $s);
        if ($lock) AvailabilityService::lockItem($pdo, 'house', $b['house_id']);
        $m = AvailabilityService::houseConflict($pdo, $b['house_id'], $in, $out, [$b['id']], [$b['reference_number']], $times['in'], $times['out']);
        if ($m) return self::planFail(self::houseSafeMessage($m), $s);
        return ['ok' => true, 'message' => '', 'schedule' => $s, 'changed' => true];
    }

    /** Is an Emergency Rebook request (Phase 7.7) waiting for this booking? Safe when the table does not exist yet. */
    public static function emergencyPending(PDO $pdo, $type, $bookingId, $lock = false) {
        try {
            $st = $pdo->prepare("SELECT id FROM emergency_rebook_requests WHERE booking_type = ? AND booking_id = ? AND status = 'pending' LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
            $st->execute([(string)$type, (int)$bookingId]);
            return (bool)$st->fetchColumn();
        } catch (PDOException $e) { return false; }
    }
    const EMERGENCY_PENDING_MESSAGE = 'An Emergency Rebook request for this booking is already waiting for Admin/Staff approval.';

    /**
     * Guest requests new dates for a house booking. Everything is decided HERE from the saved booking: the nights,
     * the times, the price and the payment fields are never taken from the browser — only the new check-in date and
     * the number of guests are. The booking becomes 'pending' (owner/staff confirm or reject in Booking Management);
     * the previous dates are backed up so a rejection or a guest cancel restores them. No money is touched.
     * Returns ['reference','house_name','old_in','old_out','new_in','new_out','nights','guests','total','rebook_count'].
     * Throws AvailabilityConflictException / RuntimeException / InvalidArgumentException (guest-safe messages).
     */
    public static function requestHouseRebook(PDO $pdo, $bookingId, $guestId, $newIn, $guests, $time = null) {
        $new = self::parseDate($newIn);
        $pdo->beginTransaction();
        try {
            $b = self::lockBooking($pdo, 'house_bookings', $bookingId);
            if ((int)$b['guest_id'] !== (int)$guestId) throw new RuntimeException('Booking not found.');
            if ($why = self::houseBlockReason($b)) throw new RuntimeException($why);
            if (self::emergencyPending($pdo, 'house', $b['id'], true)) throw new RuntimeException(self::EMERGENCY_PENDING_MESSAGE);   // Phase 7.7: never both at once
            $v = self::houseView($pdo, $b);
            $guests = (int)$guests;
            if ($guests < 1) throw new InvalidArgumentException('Please enter the number of guests.');
            if ($v['capacity'] > 0 && $guests > $v['capacity']) throw new InvalidArgumentException('This house accommodates up to ' . $v['capacity'] . ' guests.');

            $nights = self::houseNights($b);
            if ($nights < 1) throw new RuntimeException('This booking has no stay length to move.');
            $cur = self::houseTimes($b);                      // the schedule as it is NOW (kept in previous_* for a restore)
            $times = self::houseRequestTimes($b, $time);      // Phase 7.9.3: the guest may change the check-in TIME; check-out time follows it
            $in = $new->format('Y-m-d');
            // Choosing the stay's current check-in date AND time is not a rebook: nothing is changed, nothing is counted.
            if ($in === substr((string)$b['check_in_date'], 0, 10) && !$times['changed']) throw new InvalidArgumentException(self::SAME_DATE_MESSAGE);
            $timeCols = self::houseTimeColumnsReady($pdo);
            if ($times['changed'] && !$timeCols) throw new InvalidArgumentException(self::TIME_UNAVAILABLE_MESSAGE);
            if ($pe = self::pastTimeError('Check-in', $in, $times['in'])) throw new InvalidArgumentException($pe);
            $out = (clone $new)->modify('+' . $nights . ' days')->format('Y-m-d');
            AvailabilityService::lockItem($pdo, 'house', $b['house_id']);
            $m = AvailabilityService::houseConflict($pdo, $b['house_id'], $in, $out, [$b['id']], [$b['reference_number']], $times['in'], $times['out']);
            if ($m) throw new AvailabilityConflictException(self::houseSafeMessage($m));

            // House price is per NIGHT; guests never multiply it and the nights never change, so the total stays the same.
            $ppn = $nights > 0 ? $b['total_amount'] / $nights : $b['total_amount'];
            $total = round($ppn * $nights, 2);
            // With the history columns the previous TIMES are saved too (so a reject / guest cancel restores them exactly) and the new
            // times are written; without them (migration not run) the times are left alone and only the date can have changed.
            $timeSet = $timeCols ? "previous_check_in_time = ?, previous_check_out_time = ?, check_in_time = ?, check_out_time = ?," : '';
            $up = $pdo->prepare("UPDATE house_bookings SET
                    previous_check_in_date = ?, previous_check_out_date = ?, previous_number_of_guests = ?,
                    previous_total_amount = ?, previous_booking_status = ?,
                    $timeSet
                    check_in_date = ?, check_out_date = ?, number_of_guests = ?, total_amount = ?,
                    booking_status = 'pending', rebook_count = COALESCE(rebook_count, 0) + 1,
                    rebooked_at = NOW(), rebook_confirmed_at = NULL
                  WHERE id = ? AND guest_id = ? AND booking_status IN ('confirmed','cancelled')");
            $args = [$b['check_in_date'], $b['check_out_date'], $b['number_of_guests'], $b['total_amount'], $b['booking_status']];
            if ($timeCols) { $args[] = $cur['in']; $args[] = $cur['out']; $args[] = $times['in']; $args[] = $times['out']; }
            array_push($args, $in, $out, $guests, $total, (int)$b['id'], (int)$b['guest_id']);
            $up->execute($args);
            if ($up->rowCount() !== 1) throw new RuntimeException('This booking changed while you were rebooking. Please refresh and try again.');
            $pdo->commit();
            return ['reference' => $b['reference_number'], 'house_name' => $v['house_name'], 'old_in' => $b['check_in_date'], 'old_out' => $b['check_out_date'],
                    'old_guests' => $b['number_of_guests'], 'old_total' => $b['total_amount'],
                    'old_in_time' => $cur['in'], 'old_out_time' => $cur['out'], 'new_in_time' => $times['in'], 'new_out_time' => $times['out'],
                    'new_in' => $in, 'new_out' => $out, 'nights' => $nights, 'guests' => $guests, 'total' => $total, 'rebook_count' => (int)($b['rebook_count'] ?? 0) + 1];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // HOUSE — guest CANCELS a pending request / owner REJECTS it (Phase 7.6: one locked place for both)
    // Same restore as before: the previous_* values come back, the status before the request comes back, the
    // request does not use up a rebook (count - 1). Only a request that is still waiting can be undone, so a
    // crafted or late request can never revert a rebook that was already confirmed.
    // ------------------------------------------------------------------

    /** SQL condition for "this house booking has a rebook request waiting" (same rule as the Rebooks tab). */
    const HOUSE_PENDING_SQL = "((rebooked_at IS NOT NULL AND rebook_confirmed_at IS NULL) OR (booking_status = 'pending' AND rebook_count > 0)) AND booking_status NOT IN ('cancelled','completed')";

    private static function housePendingRow(array $b) {
        if (in_array($b['booking_status'] ?? '', ['cancelled', 'completed'], true)) return false;
        if (!empty($b['rebooked_at']) && empty($b['rebook_confirmed_at'])) return true;
        return ($b['booking_status'] ?? '') === 'pending' && (int)($b['rebook_count'] ?? 0) > 0;
    }

    /**
     * Undo a pending house rebook request. $guestId (guest cancel) must own the booking; null = owner/staff reject.
     * $reblock: re-mark the restored nights as taken (owner reject did this before; guest cancel never released them).
     * Returns the row as it was before the undo. Throws RuntimeException (guest-safe message).
     */
    private static function undoHouseRebook(PDO $pdo, $bookingId, $guestId, $reblock, $userId = null) {
        $pdo->beginTransaction();
        try {
            $b = self::lockBooking($pdo, 'house_bookings', $bookingId);
            if ($guestId !== null && (int)$b['guest_id'] !== (int)$guestId) throw new RuntimeException("You don't have permission to cancel this rebook.");
            if (!empty($b['package_id'])) throw new RuntimeException('This house is part of a package.');
            if (!self::housePendingRow($b)) {
                throw new RuntimeException(!empty($b['rebook_confirmed_at'])
                    ? 'This rebook has already been confirmed.'
                    : 'This booking has no pending rebook request.');
            }
            $hasBackup = !empty($b['previous_check_in_date']) && !empty($b['previous_check_out_date']);
            $restore = in_array($b['previous_booking_status'] ?? '', ['confirmed', 'cancelled', 'completed', 'pending'], true)
                ? $b['previous_booking_status'] : 'confirmed';
            if ($reblock) AvailabilityService::releaseForBooking($pdo, 'house', $b['house_id'], $b['reference_number']);
            if ($hasBackup) {
                // Phase 7.9.3: the saved previous TIMES come back with the dates. A request made before the migration has NULL there
                // (it could not change a time), so COALESCE keeps the time the row already has — exactly what that request needs.
                $timeCols = self::houseTimeColumnsReady($pdo);
                $timeRestore = $timeCols ? "check_in_time = COALESCE(previous_check_in_time, check_in_time), check_out_time = COALESCE(previous_check_out_time, check_out_time),
                        previous_check_in_time = NULL, previous_check_out_time = NULL," : '';
                $up = $pdo->prepare("UPDATE house_bookings SET check_in_date = previous_check_in_date, check_out_date = previous_check_out_date,
                        $timeRestore
                        number_of_guests = previous_number_of_guests, total_amount = previous_total_amount,
                        previous_check_in_date = NULL, previous_check_out_date = NULL, previous_number_of_guests = NULL, previous_total_amount = NULL,
                        booking_status = ?, previous_booking_status = NULL, rebook_count = GREATEST(0, rebook_count - 1),
                        rebooked_at = NULL, rebook_confirmed_at = NULL
                      WHERE id = ? AND " . self::HOUSE_PENDING_SQL);
            } else {
                $up = $pdo->prepare("UPDATE house_bookings SET booking_status = ?, previous_booking_status = NULL, rebook_count = GREATEST(0, rebook_count - 1),
                        rebooked_at = NULL, rebook_confirmed_at = NULL
                      WHERE id = ? AND " . self::HOUSE_PENDING_SQL);
            }
            $up->execute([$restore, (int)$b['id']]);
            if ($up->rowCount() !== 1) throw new RuntimeException('This rebook request changed meanwhile. Please refresh.');
            if ($reblock && $hasBackup && $restore !== 'cancelled') {
                AvailabilityService::blockForBooking($pdo, 'house', $b['house_id'], $b['previous_check_in_date'], $b['previous_check_out_date'], $b['reference_number'], $userId ?: null);
            }
            $pdo->commit();
            $b['restored_status'] = $restore;
            $b['rebook_count_after'] = max(0, (int)$b['rebook_count'] - 1);
            return $b;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Guest cancels their own pending house rebook request (profile.php). */
    public static function cancelHouseRebook(PDO $pdo, $bookingId, $guestId) {
        return self::undoHouseRebook($pdo, $bookingId, (int)$guestId, false);
    }

    /** Owner/staff reject a pending house rebook request (booking-management.php). */
    public static function rejectHouseRebook(PDO $pdo, $bookingId, $userId) {
        return self::undoHouseRebook($pdo, $bookingId, null, true, $userId);
    }

    // ==================================================================
    // TOUR / FOOD / PACKAGE — rebook REQUESTS awaiting Admin/Staff approval (Phase 7.6)
    // ==================================================================

    /** Time slots the booking forms offer (tours.php / food.php / packages.php). A booking's current time is always accepted too. */
    const TOUR_TIMES = ['06:00:00', '07:00:00', '08:00:00', '09:00:00', '10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00'];
    const FOOD_TIMES = ['06:00:00', '07:00:00', '08:00:00', '09:00:00', '10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00',
                        '17:00:00', '18:00:00', '19:00:00', '20:00:00'];
    const NO_CHANGE_MESSAGE = 'Please change at least one date or time.';
    const PENDING_MESSAGE = 'A rebook request for this booking is already waiting for confirmation. You can cancel it from your dashboard if you want to choose another schedule.';

    private static $requestTableReady = null;

    /** Is the Phase 7.6 table there? (Pages degrade gracefully if the migration has not been run yet.) */
    public static function requestTableReady(PDO $pdo) {
        if (self::$requestTableReady !== null) return self::$requestTableReady;
        try { $pdo->query('SELECT id FROM `rebook_requests` LIMIT 0'); self::$requestTableReady = true; }
        catch (PDOException $e) { self::$requestTableReady = false; }
        return self::$requestTableReady;
    }

    /** 'HH:MM' / 'HH:MM:SS' -> 'HH:MM:SS', or '' when not a valid time. */
    public static function normTime($t) {
        $t = trim((string)$t);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $t, $m)) return '';
        return $m[1] . ':' . $m[2] . ':' . (isset($m[3]) && $m[3] !== '' ? $m[3] : '00');
    }

    /** Selectable times for a tour/food rebook: the standard slots plus the booking's current time. */
    public static function timeChoices($type, $current = '') {
        $list = $type === 'tour' ? self::TOUR_TIMES : self::FOOD_TIMES;
        $cur = self::normTime($current);
        if ($cur !== '' && !in_array($cur, $list, true)) { $list[] = $cur; sort($list); }
        return $list;
    }

    /** null when the date is a real date, today or later and within 12 months; otherwise a guest-safe message. */
    private static function dateRuleError($ymd) {
        try { self::parseDate($ymd); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    }

    /** A schedule set for TODAY must still be ahead. */
    private static function pastTimeError($label, $ymd, $time) {
        if ($ymd !== date('Y-m-d') || $time === '') return null;
        $at = strtotime($ymd . ' ' . $time);
        if ($at !== false && $at <= time()) return $label . ' time (' . date('g:i A', $at) . ') has already passed today. Please choose a later time or date.';
        return null;
    }

    /** Availability messages without other guests' references or the owner's private block notes. */
    public static function safeMessage($msg) {
        $msg = (string)$msg;
        if (preg_match('/^Already reserved from (.+?) to (.+?) \(booking /', $msg)) return self::houseSafeMessage($msg);
        if (preg_match('/^(.+?) is blocked\b/', $msg, $m)) return $m[1] . ' is not available.';
        return trim(preg_replace('/\s*\(booking [^)]*\)/', '', $msg));
    }

    /** The schedule a booking has NOW (what a request would change). */
    public static function currentSchedule($type, array $b, $parts = null) {
        if ($type === 'house') {
            $t = self::houseTimes($b);
            return ['house_id' => (int)$b['house_id'], 'in' => substr((string)$b['check_in_date'], 0, 10), 'in_time' => $t['in'],
                    'out' => substr((string)$b['check_out_date'], 0, 10), 'out_time' => $t['out'], 'nights' => self::houseNights($b), 'guests' => (int)$b['number_of_guests']];
        }
        if ($type === 'tour') {
            return ['tour_id' => (int)$b['tour_id'], 'date' => substr((string)$b['booking_date'], 0, 10), 'time' => self::normTime($b['preferred_time'] ?? '')];
        }
        if ($type === 'food') {
            return ['food_id' => (int)$b['food_id'], 'date' => substr((string)($b['preferred_date'] ?? ''), 0, 10), 'time' => self::normTime($b['preferred_time'] ?? ''),
                    'method' => ($b['fulfillment_method'] ?? '') === 'delivery' ? 'delivery' : 'pickup', 'address' => (string)($b['delivery_address'] ?? '')];
        }
        $out = ['house' => null, 'tour' => null, 'food' => null];
        if (!empty($parts['house'])) {
            $h = $parts['house']; $t = self::houseTimes($h);
            $out['house'] = ['house_id' => (int)$h['house_id'], 'in' => substr((string)$h['check_in_date'], 0, 10), 'in_time' => $t['in'],
                             'out' => substr((string)$h['check_out_date'], 0, 10), 'out_time' => $t['out'], 'nights' => self::houseNights($h),
                             'guests' => (int)$h['number_of_guests']];
        }
        if (!empty($parts['tour'])) $out['tour'] = self::currentSchedule('tour', $parts['tour']);
        if (!empty($parts['food']) && !empty($parts['food']['preferred_date'])) $out['food'] = self::currentSchedule('food', $parts['food']);
        return $out;
    }

    /** Posted fields -> the input array plan() expects (only these fields are ever read from the browser). */
    public static function inputFromRequest($type, array $src) {
        $g = function ($k) use ($src) { return isset($src[$k]) && is_scalar($src[$k]) ? trim((string)$src[$k]) : ''; };
        if ($type !== 'package') return ['date' => $g('new_date'), 'time' => $g('new_time')];
        return ['house' => ['date' => $g('h_date'), 'time' => $g('h_time'), 'guests' => $g('h_guests')],
                'tour'  => ['date' => $g('t_date'), 'time' => $g('t_time')],
                'food'  => ['date' => $g('f_date'), 'time' => $g('f_time')]];
    }

    /** A stored requested schedule -> plan() input (used by Confirm). */
    public static function inputFromSchedule($type, array $s) {
        if ($type !== 'package') return ['date' => (string)($s['date'] ?? ''), 'time' => (string)($s['time'] ?? '')];
        $in = [];
        if (!empty($s['house'])) $in['house'] = ['date' => $s['house']['in'], 'time' => $s['house']['in_time'], 'guests' => (string)$s['house']['guests']];
        if (!empty($s['tour']))  $in['tour']  = ['date' => $s['tour']['date'], 'time' => $s['tour']['time']];
        if (!empty($s['food']))  $in['food']  = ['date' => $s['food']['date'], 'time' => $s['food']['time']];
        return $in;
    }

    private static function planFail($message, $schedule = null) {
        return ['ok' => false, 'message' => $message, 'schedule' => $schedule, 'changed' => false];
    }

    /**
     * THE validation for a tour/food/package rebook — used for the live preview, for the request and again for
     * Confirm (then with $lock = true: the house/tour/food rows are locked in that order before availability is
     * read, the same order every confirming path uses). Nothing from the browser is used except the date / time /
     * house guests in $in; resources, nights, item, size, quantity, method, delivery unit, price and payment come
     * from the saved booking. Returns ['ok' => bool, 'message' => guest-safe text, 'schedule' => normalised
     * requested schedule (null if unusable), 'changed' => bool].
     */
    public static function plan(PDO $pdo, $type, array $b, array $in, $parts = null, $lock = false) {
        if ($type === 'tour')    return self::planTour($pdo, $b, $in, $lock);
        if ($type === 'food')    return self::planFood($pdo, $b, $in, $lock);
        if ($type === 'package') return self::planPackage($pdo, $b, $parts ?: self::packageView($pdo, $b), $in, $lock);
        return self::planFail('Unknown booking type.');
    }

    private static function planTour(PDO $pdo, array $b, array $in, $lock) {
        $cur = self::currentSchedule('tour', $b);
        $date = trim((string)($in['date'] ?? '')); $time = self::normTime($in['time'] ?? '');
        $s = ['tour_id' => $cur['tour_id'], 'date' => $date, 'time' => $time];
        if ($e = self::dateRuleError($date)) return self::planFail($e, $s);
        if ($time === '' || !in_array($time, self::timeChoices('tour', $cur['time']), true)) return self::planFail('Please choose a valid tour time.', $s);
        $changed = ($date !== $cur['date'] || $time !== $cur['time']);
        if (!$changed && ($b['booking_status'] ?? '') !== 'cancelled') return self::planFail(self::NO_CHANGE_MESSAGE, $s);
        if ($e = self::pastTimeError('Tour', $date, $time)) return self::planFail($e, $s);
        if ($lock) AvailabilityService::lockItem($pdo, 'tour', $cur['tour_id']);
        if ($m = AvailabilityService::tourConflict($pdo, $cur['tour_id'], $date, [$b['id']], [$b['reference_number']])) return self::planFail(self::safeMessage($m), $s);
        return ['ok' => true, 'message' => '', 'schedule' => $s, 'changed' => $changed];
    }

    private static function planFood(PDO $pdo, array $b, array $in, $lock) {
        $cur = self::currentSchedule('food', $b);
        $date = trim((string)($in['date'] ?? '')); $time = self::normTime($in['time'] ?? '');
        $s = ['food_id' => $cur['food_id'], 'date' => $date, 'time' => $time, 'method' => $cur['method'], 'address' => $cur['address']];
        if ($e = self::dateRuleError($date)) return self::planFail($e, $s);
        if ($time === '' || !in_array($time, self::timeChoices('food', $cur['time']), true)) return self::planFail('Please choose a valid food time.', $s);
        $changed = ($date !== $cur['date'] || $time !== $cur['time']);
        if (!$changed && ($b['booking_status'] ?? '') !== 'cancelled') return self::planFail(self::NO_CHANGE_MESSAGE, $s);
        if ($e = self::pastTimeError('Food', $date, $time)) return self::planFail($e, $s);
        // Delivery (standalone order): must still be inside the guest's confirmed stay at the same unit — the same rule as ordering.
        if ($m = self::foodDeliveryError($pdo, $b, $date, $time)) return self::planFail('Delivery: ' . $m, $s);
        if ($lock) AvailabilityService::lockItem($pdo, 'food', $cur['food_id']);
        if ($m = AvailabilityService::foodConflict($pdo, $cur['food_id'], $date, [$b['reference_number']])) return self::planFail(self::safeMessage($m), $s);
        return ['ok' => true, 'message' => '', 'schedule' => $s, 'changed' => $changed];
    }

    /**
     * Package: every part gets its OWN new schedule (no common day offset). House: same unit and same number of
     * nights; new check-in date + time; check-out date = check-in + nights; check-out time follows the check-in time
     * (AvailabilityService::houseStayTimes) — a house left completely unchanged keeps its saved times. Guests 1..capacity.
     * Tour: same boat, new date + time. Food: same order, new date + time; delivery still needs the package house.
     * With a house in the package, the tour and food must fall inside the NEW house stay (packageWindowError).
     * Availability is checked for every part that changes (and for every part when the package was cancelled with a
     * rebooking credit, because it gave up its dates then).
     */
    private static function planPackage(PDO $pdo, array $p, array $parts, array $in, $lock) {
        $cur = self::currentSchedule('package', $p, $parts);
        if (!$cur['house'] && !$cur['tour'] && !$cur['food']) return self::planFail('This package has no dated items to move.');
        $cancelled = ($p['booking_status'] ?? '') === 'cancelled';
        $s = ['house' => null, 'tour' => null, 'food' => null];
        $chg = ['house' => false, 'tour' => false, 'food' => false];

        if ($ch = $cur['house']) {
            $hi = is_array($in['house'] ?? null) ? $in['house'] : [];
            $date = trim((string)($hi['date'] ?? ''));
            $time = self::normTime($hi['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !DateTime::createFromFormat('!Y-m-d', $date) || DateTime::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) return self::planFail('House: please choose a valid check-in date.');
            if ($time === '') return self::planFail('House: please choose a valid check-in time.');
            if ($ch['nights'] < 1) return self::planFail('House: this stay has no length to move.');
            $chg['house'] = ($date !== $ch['in'] || $time !== $ch['in_time']);
            if ($chg['house']) {
                $st = AvailabilityService::houseStayTimes($time);              // check-out time = check-in time (current rule)
                $out = (new DateTime($date))->modify('+' . $ch['nights'] . ' days')->format('Y-m-d');
                $s['house'] = ['house_id' => $ch['house_id'], 'in' => $date, 'in_time' => $st['in'], 'out' => $out, 'out_time' => $st['out'], 'nights' => $ch['nights']];
            } else {
                $s['house'] = ['house_id' => $ch['house_id'], 'in' => $ch['in'], 'in_time' => $ch['in_time'], 'out' => $ch['out'], 'out_time' => $ch['out_time'], 'nights' => $ch['nights']];
            }
            $gRaw = trim((string)($hi['guests'] ?? ''));
            if (!preg_match('/^[1-9]\d{0,2}$/', $gRaw)) return self::planFail('House: please enter the number of guests.', $s);
            $guests = (int)$gRaw;
            $cap = (int)self::houseView($pdo, $parts['house'])['capacity'];
            if ($cap > 0 && $guests > $cap) return self::planFail('House: this house accommodates up to ' . $cap . ' guests.', $s);
            $s['house']['guests'] = $guests;
        }
        foreach (['tour', 'food'] as $k) {
            if (!($cc = $cur[$k])) continue;
            $ii = is_array($in[$k] ?? null) ? $in[$k] : [];
            $date = trim((string)($ii['date'] ?? '')); $time = self::normTime($ii['time'] ?? '');
            $label = ucfirst($k);
            $s[$k] = ['date' => $date, 'time' => $time] + ($k === 'tour' ? ['tour_id' => $cc['tour_id']] : ['food_id' => $cc['food_id'], 'method' => $cc['method']]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return self::planFail("$label: please choose a valid date.", $s);
            if ($time === '' || !in_array($time, self::timeChoices($k, $cc['time']), true)) return self::planFail("$label: please choose a valid time.", $s);
            $chg[$k] = ($date !== $cc['date'] || $time !== $cc['time']);
        }
        $any = $chg['house'] || $chg['tour'] || $chg['food'];
        if (!$any && !$cancelled) return self::planFail(self::NO_CHANGE_MESSAGE, $s);

        // Dates / times of the parts that change: today or later (within 12 months); today only if still ahead.
        foreach (['house' => 'House', 'tour' => 'Tour', 'food' => 'Food'] as $k => $label) {
            if (!$s[$k] || !($chg[$k] || $cancelled)) continue;
            $d = $k === 'house' ? $s[$k]['in'] : $s[$k]['date'];
            $t = $k === 'house' ? $s[$k]['in_time'] : $s[$k]['time'];
            if ($e = self::dateRuleError($d)) return self::planFail("$label: $e", $s);
            if ($e = self::pastTimeError($label, $d, $t)) return self::planFail($e, $s);
        }
        // Delivery needs the package house (the order is delivered to that unit); the window below decides the timing.
        if ($s['food'] && $s['food']['method'] === 'delivery' && !$s['house']) {
            return self::planFail('Food: delivery requires a house in the package because the order is delivered to your booked unit.', $s);
        }
        // Package schedule window: tour and food inside the NEW house stay (both ends included).
        if ($s['house']) {
            $items = [];
            if ($s['tour']) $items[] = ['label' => 'Tour', 'date' => $s['tour']['date'], 'time' => $s['tour']['time']];
            if ($s['food']) $items[] = ['label' => 'Food', 'date' => $s['food']['date'], 'time' => $s['food']['time']];
            $win = ['check_in' => $s['house']['in'], 'check_in_time' => $s['house']['in_time'], 'check_out' => $s['house']['out'], 'check_out_time' => $s['house']['out_time']];
            if ($items && ($err = AvailabilityService::packageWindowError($win, $items)) !== null) return self::planFail($err, $s);
        }
        // Availability, locked in the same order as every confirming path (house -> tour -> food).
        if ($s['house'] && ($chg['house'] || $cancelled)) {
            $h = $parts['house'];
            if ($lock) AvailabilityService::lockItem($pdo, 'house', $h['house_id']);
            $m = AvailabilityService::houseConflict($pdo, $h['house_id'], $s['house']['in'], $s['house']['out'], [$h['id']], [$h['reference_number']], $s['house']['in_time'], $s['house']['out_time']);
            if ($m) return self::planFail('House: ' . self::safeMessage($m), $s);
        }
        if ($s['tour'] && ($chg['tour'] || $cancelled)) {
            $t = $parts['tour'];
            if ($lock) AvailabilityService::lockItem($pdo, 'tour', $t['tour_id']);
            $m = AvailabilityService::tourConflict($pdo, $t['tour_id'], $s['tour']['date'], [$t['id']], [$t['reference_number']]);
            if ($m) return self::planFail('Tour: ' . self::safeMessage($m), $s);
        }
        if ($s['food'] && ($chg['food'] || $cancelled)) {
            $f = $parts['food'];
            if ($lock) AvailabilityService::lockItem($pdo, 'food', $f['food_id']);
            $m = AvailabilityService::foodConflict($pdo, $f['food_id'], $s['food']['date'], [$f['reference_number']]);
            if ($m) return self::planFail('Food: ' . self::safeMessage($m), $s);
        }
        return ['ok' => true, 'message' => '', 'schedule' => $s, 'changed' => $any, 'parts_changed' => $chg];
    }

    /** First day of a schedule (for logs / the dashboard's "recent rebooks"). */
    public static function scheduleStart($type, $s) {
        if (!is_array($s)) return null;
        if ($type === 'house') return $s['in'] ?? null;
        if ($type !== 'package') return $s['date'] ?? null;
        $d = array_filter([$s['house']['in'] ?? null, $s['tour']['date'] ?? null, $s['food']['date'] ?? null]);
        return $d ? min($d) : null;
    }

    private static function fmtDt($d, $t = '') {
        $ts = strtotime(trim($d . ' ' . $t));
        if ($ts === false) return (string)$d;
        return date('M j, Y', $ts) . ($t !== '' && $t !== null ? ' · ' . date('g:i A', $ts) : '');
    }

    /** Human lines for a schedule: [['label' => 'Tour', 'text' => 'Nov 6, 2026 · 8:00 AM'], ...] (no HTML). */
    public static function scheduleLines($type, $s) {
        if (!is_array($s)) return [];
        if ($type === 'house') return [['label' => 'House', 'text' => self::fmtDt($s['in'] ?? '', $s['in_time'] ?? '') . ' to ' . self::fmtDt($s['out'] ?? '', $s['out_time'] ?? '')
            . (isset($s['guests']) ? ' · ' . (int)$s['guests'] . ' guest' . ((int)$s['guests'] === 1 ? '' : 's') : '')]];
        if ($type === 'tour') return [['label' => 'Tour', 'text' => self::fmtDt($s['date'] ?? '', $s['time'] ?? '')]];
        if ($type === 'food') return [['label' => 'Food' . (($s['method'] ?? '') === 'delivery' ? ' (delivery)' : ' (pickup)'), 'text' => self::fmtDt($s['date'] ?? '', $s['time'] ?? '')]];
        $out = [];
        if (!empty($s['house'])) $out[] = ['label' => 'House', 'text' => self::fmtDt($s['house']['in'], $s['house']['in_time']) . ' to ' . self::fmtDt($s['house']['out'], $s['house']['out_time'])
            . (isset($s['house']['guests']) ? ' · ' . (int)$s['house']['guests'] . ' guest' . ((int)$s['house']['guests'] === 1 ? '' : 's') : '')];
        if (!empty($s['tour'])) $out[] = ['label' => 'Tour', 'text' => self::fmtDt($s['tour']['date'], $s['tour']['time'])];
        if (!empty($s['food'])) $out[] = ['label' => 'Food', 'text' => self::fmtDt($s['food']['date'], $s['food']['time'])];
        return $out;
    }

    /** The pending request of one booking (FOR UPDATE inside a transaction when $lock), or null. */
    public static function pendingRequest(PDO $pdo, $type, $bookingId, $lock = false) {
        if (!self::requestTableReady($pdo)) return null;
        $st = $pdo->prepare("SELECT * FROM rebook_requests WHERE booking_type = ? AND booking_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([(string)$type, (int)$bookingId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::decodeRequest($r) : null;
    }

    private static function decodeRequest(array $r) {
        $r['original'] = json_decode((string)$r['original_schedule'], true) ?: [];
        $r['requested'] = json_decode((string)$r['requested_schedule'], true) ?: [];
        return $r;
    }

    /** Number of tour/food/package requests waiting (0 when the table is not there yet). */
    public static function pendingCount(PDO $pdo) {
        if (!self::requestTableReady($pdo)) return 0;
        try { return (int)$pdo->query("SELECT COUNT(*) FROM rebook_requests WHERE status = 'pending'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    /**
     * Pending requests, newest first (all, or one guest's), with the booking facts the Rebooks views need:
     * guest name, item name, payment status, amounts, rebook count. One query per table, never per row.
     */
    public static function pendingRequests(PDO $pdo, $guestId = null) {
        if (!self::requestTableReady($pdo)) return [];
        try {
            $sql = "SELECT r.*, COALESCE(NULLIF(u.fullname, ''), u.username, 'Guest') AS guest_name
                      FROM rebook_requests r LEFT JOIN guests g ON g.id = r.guest_id LEFT JOIN users u ON u.id = g.user_id
                     WHERE r.status = 'pending'" . ($guestId !== null ? ' AND r.guest_id = ?' : '') . " ORDER BY r.requested_at DESC, r.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($guestId !== null ? [(int)$guestId] : []);
            $rows = array_map([self::class, 'decodeRequest'], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log('[RebookService] pendingRequests: ' . $e->getMessage());
            return [];
        }
        $ids = [];
        foreach ($rows as $r) $ids[$r['booking_type']][] = (int)$r['booking_id'];
        $info = [];
        $item = ['tour' => ['tours', 'tour_id', 'tour_name'], 'food' => ['food_items', 'food_id', 'name']];
        foreach ($ids as $type => $list) {
            $table = PaymentService::TABLES[$type];
            $ph = implode(',', array_fill(0, count($list), '?'));
            $nameSql = isset($item[$type]) ? ", (SELECT i.`{$item[$type][2]}` FROM `{$item[$type][0]}` i WHERE i.id = b.`{$item[$type][1]}`) AS item_name" : ", '' AS item_name";
            try {
                $q = $pdo->prepare("SELECT b.*$nameSql FROM `$table` b WHERE b.id IN ($ph)");
                $q->execute($list);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) $info[$type][(int)$b['id']] = $b;
            } catch (PDOException $e) { error_log('[RebookService] pendingRequests lookup ' . $table . ': ' . $e->getMessage()); }
        }
        foreach ($rows as $k => $r) $rows[$k]['booking'] = $info[$r['booking_type']][(int)$r['booking_id']] ?? null;
        return $rows;
    }

    /** [type][booking_id] => pending request, for marking rows in lists. */
    public static function pendingMap(PDO $pdo, $guestId = null) {
        $map = ['tour' => [], 'food' => [], 'package' => []];
        foreach (self::pendingRequests($pdo, $guestId) as $r) $map[$r['booking_type']][(int)$r['booking_id']] = $r;
        return $map;
    }

    private static function isDuplicateKey(PDOException $e) {
        return ($e->errorInfo[1] ?? null) == 1062 || strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'duplicate') !== false;
    }

    /**
     * Guest (or staff assisting the guest) submits a tour/food/package rebook REQUEST. Nothing on the booking changes.
     * $in = inputFromRequest(). Throws RuntimeException / InvalidArgumentException with guest-safe messages.
     * Returns ['id', 'reference', 'original', 'requested'].
     */
    public static function requestServiceRebook(PDO $pdo, $type, $id, $guestId, array $in, $userId, $role) {
        if (!in_array($type, self::SERVICE_TYPES, true)) throw new InvalidArgumentException('Unknown booking type.');
        if (!self::requestTableReady($pdo)) throw new RuntimeException('Rebooking is temporarily unavailable. Please contact us.');
        $table = PaymentService::TABLES[$type];
        $pdo->beginTransaction();
        try {
            $b = self::lockBooking($pdo, $table, $id);                                    // 1. the booking (and package parts)
            if ((int)$b['guest_id'] !== (int)$guestId) throw new RuntimeException('Booking not found.');
            if ($why = self::serviceBlockReason($type, $b)) throw new RuntimeException($why);
            $parts = $type === 'package' ? PaymentService::lockPackageParts($pdo, $b) : null;
            if (self::pendingRequest($pdo, $type, $b['id'], true)) throw new RuntimeException(self::PENDING_MESSAGE);   // 2. the request
            if (self::emergencyPending($pdo, $type, $b['id'], true)) throw new RuntimeException(self::EMERGENCY_PENDING_MESSAGE);   // Phase 7.7: never both at once
            $r = self::plan($pdo, $type, $b, $in, $parts, false);
            if (!$r['ok']) throw new InvalidArgumentException($r['message']);
            $orig = self::currentSchedule($type, $b, $parts);
            $ins = $pdo->prepare("INSERT INTO rebook_requests
                    (booking_type, booking_id, reference_number, guest_id, status, pending_lock, original_status, original_schedule, requested_schedule,
                     requested_by, requested_role, requested_at)
                    VALUES (?, ?, ?, ?, 'pending', 1, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([$type, (int)$b['id'], (string)$b['reference_number'], (int)$b['guest_id'], (string)$b['booking_status'],
                           json_encode($orig), json_encode($r['schedule']), $userId ?: null, $role !== '' ? substr((string)$role, 0, 20) : null]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();
            return ['id' => $newId, 'reference' => $b['reference_number'], 'original' => $orig, 'requested' => $r['schedule']];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (self::isDuplicateKey($e)) throw new RuntimeException(self::PENDING_MESSAGE);   // a second request raced this one
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** booking_type / booking_id of a request (no lock), or null. */
    private static function peekRequest(PDO $pdo, $requestId) {
        if (!self::requestTableReady($pdo)) return null;
        $st = $pdo->prepare('SELECT id, booking_type, booking_id FROM rebook_requests WHERE id = ?');
        $st->execute([(int)$requestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ($r && in_array($r['booking_type'], self::SERVICE_TYPES, true)) ? $r : null;
    }

    private static function lockRequest(PDO $pdo, $requestId) {
        $st = $pdo->prepare('SELECT * FROM rebook_requests WHERE id = ? FOR UPDATE');
        $st->execute([(int)$requestId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::decodeRequest($r) : null;
    }

    /**
     * Admin/Staff confirm. ONE transaction: lock the booking (+ package parts), lock the request, verify the booking is
     * still as it was when the guest asked, re-run plan() with the items locked, apply every part, +1 rebook (once),
     * mark the request confirmed. Any failure rolls everything back and the request stays pending.
     * Throws RuntimeException (message safe to show to staff). Returns ['type','booking_id','reference','old_date','new_date','request'].
     */
    public static function confirmServiceRebook(PDO $pdo, $requestId, $userId, $role) {
        $peek = self::peekRequest($pdo, $requestId);
        if (!$peek) throw new RuntimeException('Rebook request not found.');
        $type = $peek['booking_type'];
        $table = PaymentService::TABLES[$type];
        $pdo->beginTransaction();
        try {
            $b = self::lockBooking($pdo, $table, $peek['booking_id']);
            $parts = $type === 'package' ? PaymentService::lockPackageParts($pdo, $b) : null;
            $req = self::lockRequest($pdo, $requestId);
            if (!$req || $req['status'] !== 'pending') throw new RuntimeException('This rebook request has already been handled. Please refresh.');
            if ((string)$b['booking_status'] !== (string)$req['original_status']) {
                throw new RuntimeException('The booking changed after the request (it is now ' . $b['booking_status'] . '). Reject this request; the guest can submit a new one.');
            }
            if (!in_array($b['booking_status'], ['confirmed', 'cancelled'], true)) throw new RuntimeException('Only confirmed or paid-cancelled bookings can be rebooked.');
            if ($type !== 'package' && !empty($b['package_id'])) throw new RuntimeException('This item is part of a package.');
            if ((float)($b['amount_paid'] ?? 0) <= 0) throw new RuntimeException('Only bookings with a paid reservation fee can be rebooked.');
            if (self::remaining($b) <= 0) throw new RuntimeException('This booking has already been rebooked ' . self::MAX_REBOOKS . ' times (maximum).');
            $cur = self::currentSchedule($type, $b, $parts);
            if (json_encode($cur) !== json_encode($req['original'])) {
                throw new RuntimeException('The booking schedule changed after the request. Reject this request; the guest can submit a new one.');
            }
            $r = self::plan($pdo, $type, $b, self::inputFromSchedule($type, $req['requested']), $parts, true);
            if (!$r['ok']) throw new AvailabilityConflictException($r['message']);
            if (json_encode($r['schedule']) !== json_encode($req['requested'])) throw new RuntimeException('The requested schedule could not be applied exactly. Reject this request; the guest can submit a new one.');

            self::applySchedule($pdo, $type, $b, $parts, $cur, $r['schedule'], $userId);

            $up = $pdo->prepare("UPDATE rebook_requests SET status = 'confirmed', pending_lock = NULL, reviewed_by = ?, reviewed_role = ?, reviewed_at = NOW()
                                  WHERE id = ? AND status = 'pending'");
            $up->execute([$userId ?: null, substr((string)$role, 0, 20), (int)$req['id']]);
            if ($up->rowCount() !== 1) throw new RuntimeException('This rebook request changed while confirming. Please refresh.');
            $pdo->commit();
            return ['type' => $type, 'booking_id' => (int)$b['id'], 'reference' => $b['reference_number'], 'request' => $req,
                    'old_date' => self::scheduleStart($type, $cur), 'new_date' => self::scheduleStart($type, $r['schedule']),
                    'rebook_count' => (int)$b['rebook_count'] + 1];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Inside a locked transaction (confirmServiceRebook / Emergency approval): write the new schedule. Money fields are never
     * touched. $countRebook = false (Emergency Rebook, Phase 7.7): the normal `rebook_count` is left exactly as it is —
     * Emergency approvals are recorded in `emergency_rebook_requests` — but `rebooked_at` still records the change.
     */
    public static function applySchedule(PDO $pdo, $type, array $b, $parts, array $cur, array $new, $userId, $countRebook = true) {
        $inc = $countRebook ? 'rebook_count = rebook_count + 1, ' : '';
        $wasCancelled = ($b['booking_status'] ?? '') === 'cancelled';
        if ($type === 'tour') {
            $pdo->prepare("UPDATE tour_bookings SET booking_date = ?, preferred_time = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL,
                                  $inc rebooked_at = NOW() WHERE id = ?")
                ->execute([$new['date'], $new['time'], (int)$b['id']]);
            AvailabilityService::releaseForBooking($pdo, 'tour', $b['tour_id'], $b['reference_number']);
            AvailabilityService::blockForBooking($pdo, 'tour', $b['tour_id'], $new['date'], null, $b['reference_number'], $userId ?: null);
            return;
        }
        if ($type === 'food') {
            $pdo->prepare("UPDATE food_bookings SET preferred_date = ?, preferred_time = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL,
                                  $inc rebooked_at = NOW() WHERE id = ?")
                ->execute([$new['date'], $new['time'], (int)$b['id']]);
            return;
        }
        // Package: each part gets its own schedule; the package keeps its single payment and counts ONE rebook.
        if ($new['house'] && ($h = $parts['house'])) {
            $pdo->prepare("UPDATE house_bookings SET check_in_date = ?, check_in_time = ?, check_out_date = ?, check_out_time = ?, number_of_guests = ?,
                                  booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL WHERE id = ?")
                ->execute([$new['house']['in'], $new['house']['in_time'], $new['house']['out'], $new['house']['out_time'], (int)$new['house']['guests'], (int)$h['id']]);
            if ($wasCancelled || $new['house']['in'] !== $cur['house']['in'] || $new['house']['out'] !== $cur['house']['out']) {
                AvailabilityService::releaseForBooking($pdo, 'house', $h['house_id'], $h['reference_number']);
                AvailabilityService::blockForBooking($pdo, 'house', $h['house_id'], $new['house']['in'], $new['house']['out'], $h['reference_number'], $userId ?: null);
            }
        }
        if ($new['tour'] && ($t = $parts['tour'])) {
            $pdo->prepare("UPDATE tour_bookings SET booking_date = ?, preferred_time = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL WHERE id = ?")
                ->execute([$new['tour']['date'], $new['tour']['time'], (int)$t['id']]);
            if ($wasCancelled || $new['tour']['date'] !== $cur['tour']['date']) {
                AvailabilityService::releaseForBooking($pdo, 'tour', $t['tour_id'], $t['reference_number']);
                AvailabilityService::blockForBooking($pdo, 'tour', $t['tour_id'], $new['tour']['date'], null, $t['reference_number'], $userId ?: null);
            }
        }
        if ($new['food'] && ($f = $parts['food'])) {
            $pdo->prepare("UPDATE food_bookings SET preferred_date = ?, preferred_time = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL WHERE id = ?")
                ->execute([$new['food']['date'], $new['food']['time'], (int)$f['id']]);
        }
        $pdo->prepare("UPDATE package_bookings SET booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL,
                              $inc rebooked_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([(int)$b['id']]);
        PaymentService::syncPackageComponents($pdo, (int)$b['id']);   // parts mirror the package payment state
    }

    /**
     * Close a pending request without touching the booking: owner/staff Reject ($guestId null) or guest Cancel.
     * Same lock order as Confirm (booking, then request). Returns the request row as it was.
     */
    private static function closeServiceRequest(PDO $pdo, $requestId, $newStatus, $actorId, $actorRole, $note, $guestId) {
        $peek = self::peekRequest($pdo, $requestId);
        if (!$peek) throw new RuntimeException('Rebook request not found.');
        $table = PaymentService::TABLES[$peek['booking_type']];
        $pdo->beginTransaction();
        try {
            $b = self::lockBooking($pdo, $table, $peek['booking_id']);
            $req = self::lockRequest($pdo, $requestId);
            if (!$req) throw new RuntimeException('Rebook request not found.');
            if ($guestId !== null && ((int)$req['guest_id'] !== (int)$guestId || (int)$b['guest_id'] !== (int)$guestId)) throw new RuntimeException('Rebook request not found.');
            if ($req['status'] !== 'pending') throw new RuntimeException('This rebook request has already been handled.');
            $up = $pdo->prepare("UPDATE rebook_requests SET status = ?, pending_lock = NULL, reviewed_by = ?, reviewed_role = ?, reviewed_at = NOW(), review_note = ?
                                  WHERE id = ? AND status = 'pending'");
            $up->execute([$newStatus, $actorId ?: null, substr((string)$actorRole, 0, 20), $note !== null ? mb_substr((string)$note, 0, 500) : null, (int)$req['id']]);
            if ($up->rowCount() !== 1) throw new RuntimeException('This rebook request changed meanwhile. Please refresh.');
            $pdo->commit();
            return $req;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function rejectServiceRebook(PDO $pdo, $requestId, $userId, $role, $note) {
        return self::closeServiceRequest($pdo, $requestId, 'rejected', $userId, $role, $note, null);
    }

    /** Guest cancels their own pending request ($guestId = guests.id, $userId = users.id). */
    public static function cancelServiceRebook(PDO $pdo, $requestId, $guestId, $userId) {
        return self::closeServiceRequest($pdo, $requestId, 'cancelled', $userId, 'guest', 'Cancelled by the guest', (int)$guestId);
    }

    /** First service day of a package (from its parts). */
    public static function packageStartDate(array $parts) {
        $dates = [];
        if ($parts['house']) $dates[] = $parts['house']['check_in_date'];
        if ($parts['tour'])  $dates[] = $parts['tour']['booking_date'];
        if ($parts['food'] && !empty($parts['food']['preferred_date'])) $dates[] = $parts['food']['preferred_date'];
        return $dates ? min($dates) : null;
    }

    /**
     * One calendar day for a rebook dashboard (display only; the request and Confirm run plan() again).
     * $part: 'tour' | 'food' (standalone) or 'house' | 'tour' | 'food' of a package. $ctx (package): the house
     * check-in date/time being chosen (h_date/h_time), so tour/food days outside that NEW stay show as unavailable.
     * Returns ['ok' => bool, 'message' => string].
     */
    public static function dayCheck(PDO $pdo, $type, array $b, $part, $date, array $ctx = [], $parts = null) {
        if ($e = self::dateRuleError($date)) return ['ok' => false, 'message' => $e];
        if ($type === 'tour') {
            $m = AvailabilityService::tourConflict($pdo, $b['tour_id'], $date, [$b['id']], [$b['reference_number']]);
            return $m ? ['ok' => false, 'message' => self::safeMessage($m)] : ['ok' => true, 'message' => ''];
        }
        if ($type === 'food') {
            if (($b['fulfillment_method'] ?? '') === 'delivery' && ($m = self::foodDeliveryDayError($pdo, $b, $date))) return ['ok' => false, 'message' => 'Delivery: ' . $m];
            $m = AvailabilityService::foodConflict($pdo, $b['food_id'], $date, [$b['reference_number']]);
            return $m ? ['ok' => false, 'message' => self::safeMessage($m)] : ['ok' => true, 'message' => ''];
        }
        // package parts
        $parts = $parts ?: self::packageView($pdo, $b);
        if ($part === 'house' && ($h = $parts['house'])) {
            $nights = self::houseNights($h);
            $time = self::normTime($ctx['h_time'] ?? '') ?: self::houseTimes($h)['in'];
            try { $st = AvailabilityService::houseStayTimes($time); } catch (InvalidArgumentException $e) { return ['ok' => false, 'message' => 'Invalid check-in time.']; }
            $out = (new DateTime($date))->modify('+' . $nights . ' days')->format('Y-m-d');
            $m = AvailabilityService::houseConflict($pdo, $h['house_id'], $date, $out, [$h['id']], [$h['reference_number']], $st['in'], $st['out']);
            if (!$m) return ['ok' => true, 'message' => ''];
            $kind = self::houseStartKind($pdo, $h['house_id'], $date, $st['in'], [$h['id']], [$h['reference_number']], $nights);   // Phase 7.9: calendar meaning only
            return ['ok' => false, 'message' => self::startKindMessage($kind, $nights, self::safeMessage($m)), 'kind' => $kind];
        }
        if (($part === 'tour' || $part === 'food') && ($x = $parts[$part])) {
            if (($h = $parts['house']) && ($w = self::packageWindowFor($h, $ctx))) {
                if ($date < substr($w['start'], 0, 10) || $date > substr($w['end'], 0, 10)) return ['ok' => false, 'message' => 'Outside your house stay (' . $w['start_label'] . ' to ' . $w['end_label'] . ').'];
            }
            $m = $part === 'tour'
                ? AvailabilityService::tourConflict($pdo, $x['tour_id'], $date, [$x['id']], [$x['reference_number']])
                : AvailabilityService::foodConflict($pdo, $x['food_id'], $date, [$x['reference_number']]);
            return $m ? ['ok' => false, 'message' => self::safeMessage($m)] : ['ok' => true, 'message' => ''];
        }
        return ['ok' => false, 'message' => 'Unknown item.'];
    }

    /** The package house window for the check-in date/time being chosen (falls back to the saved stay). */
    public static function packageWindowFor(array $h, array $ctx) {
        $nights = self::houseNights($h);
        $cur = self::houseTimes($h);
        $in = (string)($ctx['h_date'] ?? '');
        $time = self::normTime($ctx['h_time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in) || $time === '') { $in = substr((string)$h['check_in_date'], 0, 10); $time = $cur['in']; }
        $unchanged = ($in === substr((string)$h['check_in_date'], 0, 10) && $time === $cur['in']);
        try { $out = (new DateTime($in))->modify('+' . $nights . ' days')->format('Y-m-d'); } catch (Exception $e) { return null; }
        return AvailabilityService::packageWindow(['check_in' => $in, 'check_in_time' => $time, 'check_out' => $out,
                                                   'check_out_time' => $unchanged ? $cur['out'] : $time]);
    }

    /** Day-level delivery check for the calendar: the date must touch one of the guest's stays at the order's unit. */
    private static function foodDeliveryDayError(PDO $pdo, array $b, $date) {
        $unit = trim((string)($b['delivery_address'] ?? ''));
        foreach (AvailabilityService::foodDeliveryStays($pdo, (int)$b['guest_id'])['stays'] as $st) {
            if ($unit !== '' && strcasecmp($st['house_name'], $unit) !== 0) continue;
            if ($date >= $st['check_in'] && $date <= $st['check_out']) return null;
        }
        return 'Delivery must be during your confirmed stay' . ($unit !== '' ? ' at ' . $unit : '') . '.';
    }

    // ------------------------------------------------------------------
    // Package Rebook Dashboard helpers (read-only; used by package-rebook.php)
    // ------------------------------------------------------------------

    /** Last day to rebook: booking date + WINDOW_DAYS (Y-m-d), or null. */
    public static function deadline(array $booking) {
        if (empty($booking['created_at'])) return null;
        try { return (new DateTime(substr((string)$booking['created_at'], 0, 10)))->modify('+' . self::WINDOW_DAYS . ' days')->format('Y-m-d'); }
        catch (Exception $e) { return null; }
    }

    /** The package's house/tour/food rows (no locking) with an 'item_name' for display. */
    public static function packageView(PDO $pdo, array $p) {
        $parts = ['house' => null, 'tour' => null, 'food' => null];
        $names = ['house' => 'SELECT house_name FROM houses WHERE id = ?', 'tour' => 'SELECT tour_name FROM tours WHERE id = ?', 'food' => 'SELECT name FROM food_items WHERE id = ?'];
        $fk = ['house' => 'house_id', 'tour' => 'tour_id', 'food' => 'food_id'];
        foreach (['house' => 'house_booking_id', 'tour' => 'tour_booking_id', 'food' => 'food_booking_id'] as $t => $col) {
            if (empty($p[$col])) continue;
            $st = $pdo->prepare('SELECT * FROM `' . PaymentService::TABLES[$t] . '` WHERE id = ?');
            $st->execute([(int)$p[$col]]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            try {
                $n = $pdo->prepare($names[$t]); $n->execute([(int)$row[$fk[$t]]]);
                $row['item_name'] = (string)$n->fetchColumn();
            } catch (PDOException $e) { $row['item_name'] = ''; }
            $parts[$t] = $row;
        }
        return $parts;
    }

    /** Tour dashboard: the tour row plus its name and boat (no locking). */
    public static function tourView(PDO $pdo, array $b) {
        $b['item_name'] = ''; $b['boat_label'] = '';
        try {
            $n = $pdo->prepare('SELECT tour_name, max_guests FROM tours WHERE id = ?'); $n->execute([(int)$b['tour_id']]);
            if ($t = $n->fetch(PDO::FETCH_ASSOC)) { $b['item_name'] = (string)$t['tour_name']; $b['max_guests'] = (int)$t['max_guests']; }
        } catch (PDOException $e) {}
        return $b;
    }

    /** Food dashboard: the order row plus the item name. */
    public static function foodView(PDO $pdo, array $b) {
        $b['item_name'] = '';
        try {
            $n = $pdo->prepare('SELECT name FROM food_items WHERE id = ?'); $n->execute([(int)$b['food_id']]);
            $b['item_name'] = (string)$n->fetchColumn();
        } catch (PDOException $e) {}
        return $b;
    }

    /** Delivery orders: the guest's confirmed stay window(s) at the order's unit, for display. */
    public static function foodStayWindows(PDO $pdo, array $b) {
        if (($b['fulfillment_method'] ?? 'pickup') !== 'delivery') return [];
        $unit = trim((string)($b['delivery_address'] ?? '')); $out = [];
        foreach (AvailabilityService::foodDeliveryStays($pdo, (int)$b['guest_id'])['stays'] as $st) {
            if ($unit !== '' && strcasecmp($st['house_name'], $unit) !== 0) continue;
            if ($w = AvailabilityService::packageWindow(['check_in' => $st['check_in'], 'check_in_time' => $st['check_in_time'], 'check_out' => $st['check_out'], 'check_out_time' => $st['check_out_time']])) $out[] = $st['house_name'] . ': ' . $w['start_label'] . ' to ' . $w['end_label'];
        }
        return $out;
    }

    /** Earlier first days / dates this booking had before earlier rebooks, newest first: [['date' => 'Y-m-d', 'at' => datetime], ...]. */
    public static function previousStartDates(PDO $pdo, $bookingId, $targetType = 'package_booking') {
        try {
            $st = $pdo->prepare("SELECT old_values, created_at FROM system_logs WHERE action = 'rebook' AND target_type = ? AND target_id = ? ORDER BY id DESC");
            $st->execute([(string)$targetType, (int)$bookingId]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $v = json_decode((string)$r['old_values'], true);
                if (is_array($v) && !empty($v['old_date'])) $out[] = ['date' => $v['old_date'], 'at' => $r['created_at']];
            }
            return $out;
        } catch (PDOException $e) { return []; }
    }
}

<?php
/**
 * AvailabilityService — one availability rule for the whole system.
 *
 * HOUSE  A stay occupies the NIGHTS from check-in up to (not including)
 *        check-out. Check-out is 12:00 PM and check-in 2:00 PM, so a new guest
 *        may check in on the day the previous guest checks out.
 *          overlap  = existing.check_in < new.check_out AND existing.check_out > new.check_in   (exact datetimes, live bookings)
 *          blocked  = see houseBlockRows() below: owner / unverified blocks are HARD full-day blocks
 *                     (block_date between new.check_in AND new.check_out, both ends included)
 *        Confirming a stay marks only its nights (never the check-out day).
 *        blocked_dates rows are NOT all alike (Phase 7.9.1, houseBlockRows()):
 *          - LIVE auto-block ("Auto-blocked from booking #REF" and REF is a holding booking of this house): only a
 *            calendar marker. The booking's own exact check-in/check-out datetimes decide (the overlap rule above).
 *          - MANUAL / OWNER block (any other row): a HARD FULL-DAY block. A stay may not check in on it, sleep on it
 *            or check out on it.
 *          - ORPHAN auto-block (REF is not a live booking of this house): fails SAFE as a hard full-day block. It is never
 *            ignored and never deleted here; the encounter is written to the PHP error log for Admin diagnosis.
 *          - HELD marker (REF is a pending rebook that still reserves its old nights): keeps the old nights rule.
 * TOUR   One boat per date: a confirmed/completed booking or a blocked date
 *        on that date is a conflict.
 * FOOD   Orders are not exclusive; only a blocked date for the item conflicts.
 *
 * Only reservations whose fee has been confirmed hold dates
 * (booking_status confirmed/completed — package parts are confirmed together
 * with their package). Pending reservations may overlap; the first one whose
 * fee is confirmed wins, and the check is repeated inside the confirming
 * transaction while the house/tour row is locked (lockItem), so two
 * overlapping reservations can never both be confirmed.
 */

/** A date is already taken. The message is safe to show to owner/staff/guest. */
class AvailabilityConflictException extends RuntimeException {}

class AvailabilityService {

    const HOLDING_STATUSES = ['confirmed', 'completed'];
    const ITEM_TABLES = ['house' => 'houses', 'tour' => 'tours', 'food' => 'food_items'];

    /** Reason text written on dates blocked for a booking (also used to find/release them). */
    public static function autoReason($reference) {
        return 'Auto-blocked from booking #' . $reference;
    }

    /**
     * Lock the house/tour/food row FOR UPDATE. Every path that confirms dates
     * for the same item takes this lock first, so the availability check and
     * the confirmation happen as one step. Must be called inside a transaction.
     */
    public static function lockItem(PDO $pdo, $type, $itemId) {
        if (!isset(self::ITEM_TABLES[$type])) throw new InvalidArgumentException('Unknown item type.');
        $st = $pdo->prepare('SELECT id FROM `' . self::ITEM_TABLES[$type] . '` WHERE id = ? FOR UPDATE');
        $st->execute([(int)$itemId]);
        return (bool)$st->fetchColumn();
    }

    private static function inList(array $ids) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        return $ids ?: [0];
    }

    private static function fmt($d) {
        $t = strtotime((string)$d);
        return $t ? date('M j, Y', $t) : (string)$d;
    }

    /**
     * House conflict for the stay [$in, $out). Returns null when free, else a
     * message. $excludeIds: house_bookings ids to ignore (the booking itself);
     * $ownRefs: references whose auto-blocked dates belong to this booking.
     */
    public static function houseConflict(PDO $pdo, $houseId, $in, $out, array $excludeIds = [], array $ownRefs = [], $inTime = null, $outTime = null) {
        if (!$in || !$out || $out <= $in) return 'Check-out must be after check-in.';
        $ex = self::inList($excludeIds);
        $ph = implode(',', array_fill(0, count($ex), '?'));
        $holding = "'" . implode("','", self::HOLDING_STATUSES) . "'";
        // Time-aware overlap when the caller knows the stay's times: [in+inTime, out+outTime) against each
        // holding booking's own stored times (old rows keep whatever times they were saved with).
        // Without times it stays the date-only rule (nights [in, out)).
        $timed = ($inTime !== null && $outTime !== null);
        if ($timed) {
            // Candidates on the same dates (inclusive), then compare full date+time in PHP — avoids
            // DB-specific DATE/TIME concatenation behaviour. Old rows keep whatever times they were saved with.
            $inDt  = $in  . ' ' . self::normTime($inTime);
            $outDt = $out . ' ' . self::normTime($outTime);
            $st = $pdo->prepare("SELECT reference_number, check_in_date, check_in_time, check_out_date, check_out_time FROM house_bookings
                                  WHERE house_id = ? AND booking_status IN ($holding)
                                    AND check_in_date <= ? AND check_out_date >= ? AND id NOT IN ($ph)
                                  ORDER BY check_in_date");
            $st->execute(array_merge([(int)$houseId, $out, $in], $ex));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $bIn  = substr((string)$b['check_in_date'], 0, 10)  . ' ' . self::normTime(substr((string)($b['check_in_time']  ?: '14:00:00'), 0, 8));
                $bOut = substr((string)$b['check_out_date'], 0, 10) . ' ' . self::normTime(substr((string)($b['check_out_time'] ?: '12:00:00'), 0, 8));
                if ($bIn < $outDt && $bOut > $inDt) {
                    $from = self::fmt($b['check_in_date'])  . ' ' . date('g:i A', strtotime($bIn));
                    $to   = self::fmt($b['check_out_date']) . ' ' . date('g:i A', strtotime($bOut));
                    return 'Already reserved from ' . $from . ' to ' . $to . ' (booking ' . $b['reference_number'] . ').';
                }
            }
        } else {
            $st = $pdo->prepare("SELECT reference_number, check_in_date, check_out_date FROM house_bookings
                                  WHERE house_id = ? AND booking_status IN ($holding)
                                    AND check_in_date < ? AND check_out_date > ? AND id NOT IN ($ph)
                                  ORDER BY check_in_date LIMIT 1");
            $st->execute(array_merge([(int)$houseId, $out, $in], $ex));
            if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
                return 'Already reserved from ' . self::fmt($b['check_in_date']) . ' to ' . self::fmt($b['check_out_date']) . ' (booking ' . $b['reference_number'] . ').';
            }
        }
        return self::houseBlockConflict($pdo, $houseId, $in, $out, $ownRefs);
    }

    /** 'HH:MM' or 'HH:MM:SS' -> 'HH:MM:SS' (no validation; see houseStayTimes). */
    private static function normTime($t) {
        $t = trim((string)$t);
        return strlen($t) === 5 ? $t . ':00' : $t;
    }

    /**
     * BUSINESS RULE (new house bookings): check-out TIME = check-in TIME. The check-out DATE still
     * comes from the number of nights the guest picked. Single place the rule lives — standalone,
     * package and walk-in all call this, so a forged check_out_time can never be saved.
     * Returns ['in' => 'HH:MM:SS', 'out' => 'HH:MM:SS'] (identical). Throws InvalidArgumentException
     * for a missing/malformed check-in time (the posted check-out time is ignored on purpose).
     */
    public static function houseStayTimes($checkInTime) {
        $t = trim((string)$checkInTime);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t)) {
            throw new InvalidArgumentException('Invalid check-in time format.');
        }
        $t = self::normTime($t);
        return ['in' => $t, 'out' => $t];
    }

    // ------------------------------------------------------------------
    // PACKAGE SCHEDULE WINDOW (new package bookings only)
    // The house stay [check-in datetime, check-out datetime] is the package's schedule window:
    // a tour or food item in the same package must fall inside it, both ends included.
    // Packages without a house are not constrained. Single shared rule — packages.php (add + confirm)
    // and WalkInBookingService both call packageWindowError().
    // ------------------------------------------------------------------

    /**
     * The window for a package's house, or null if the house has no usable dates.
     * $house: ['check_in' => 'Y-m-d', 'check_in_time' => 'HH:MM[:SS]', 'check_out' => 'Y-m-d'].
     * Check-out TIME follows check-in TIME (houseStayTimes), exactly like the saved booking.
     * Returns ['start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s', 'start_label' => ..., 'end_label' => ...].
     */
    public static function packageWindow(array $house) {
        $in = (string)($house['check_in'] ?? ''); $out = (string)($house['check_out'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $out) || $out < $in) return null;
        try { $t = self::houseStayTimes($house['check_in_time'] ?? '14:00'); } catch (InvalidArgumentException $e) { return null; }
        // A SAVED booking may carry its own check-out time (older bookings kept 12:00 PM); the package builder
        // does not send one, so new packages still follow the check-in time.
        $outTime = $t['out'];
        $savedOut = trim((string)($house['check_out_time'] ?? ''));
        if ($savedOut !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?/', $savedOut)) $outTime = self::normTime(substr($savedOut, 0, 8));
        $start = $in . ' ' . $t['in']; $end = $out . ' ' . $outTime;
        return ['start' => $start, 'end' => $end,
                'start_label' => date('M j, Y g:i A', strtotime($start)), 'end_label' => date('M j, Y g:i A', strtotime($end))];
    }

    /**
     * null when every item is inside the house window (or there is no house), else a guest-safe message.
     * $items: [['label' => 'Tour', 'date' => 'Y-m-d', 'time' => 'HH:MM[:SS]'], ...]
     */
    public static function packageWindowError($house, array $items) {
        if (empty($house)) return null;                       // no house in the package: nothing to follow
        $w = self::packageWindow($house);
        if ($w === null) return 'The house stay dates are invalid. Please set your house check-in and check-out first.';
        foreach ($items as $it) {
            $label = (string)($it['label'] ?? 'Item');
            $d = (string)($it['date'] ?? ''); $t = trim((string)($it['time'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t)) {
                return "$label date or time is invalid.";
            }
            $dt = $d . ' ' . self::normTime($t);
            if ($dt < $w['start'] || $dt > $w['end']) {
                return "$label must be during your house stay: " . $w['start_label'] . ' to ' . $w['end_label']
                     . ' (you chose ' . date('M j, Y g:i A', strtotime($dt)) . ').';
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // FOOD DELIVERY (standalone food orders and walk-in food)
    // Delivery is only offered to a guest who has a CONFIRMED house stay, only inside that stay's window
    // (same packageWindowError() rule as packages), and always goes to that booked house/unit.
    // Pickup is never restricted.
    // ------------------------------------------------------------------

    /** House bookings whose status lets the guest receive food delivery. */
    const FOOD_DELIVERY_STAY_STATUSES = ['confirmed'];

    /**
     * The guest's own confirmed house stays that have not ended yet, oldest first:
     * [['id','house_name','reference','check_in','check_in_time','check_out','check_out_time'], ...].
     * 'pending' = number of the guest's house bookings still waiting for confirmation (for a helpful message).
     */
    public static function foodDeliveryStays(PDO $pdo, $guestId) {
        $in = "'" . implode("','", self::FOOD_DELIVERY_STAY_STATUSES) . "'";
        $st = $pdo->prepare("SELECT hb.id, hb.reference_number, hb.check_in_date, hb.check_in_time, hb.check_out_date, hb.check_out_time, h.house_name
                               FROM house_bookings hb JOIN houses h ON h.id = hb.house_id
                              WHERE hb.guest_id = ? AND hb.booking_status IN ($in) AND hb.check_out_date >= CURDATE()
                              ORDER BY hb.check_in_date, hb.id");
        $st->execute([(int)$guestId]);
        $stays = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stays[] = ['id' => (int)$r['id'], 'house_name' => (string)$r['house_name'], 'reference' => (string)$r['reference_number'],
                        'check_in' => substr((string)$r['check_in_date'], 0, 10), 'check_in_time' => substr((string)($r['check_in_time'] ?: '14:00:00'), 0, 8),
                        'check_out' => substr((string)$r['check_out_date'], 0, 10), 'check_out_time' => substr((string)($r['check_out_time'] ?: '12:00:00'), 0, 8)];
        }
        $p = $pdo->prepare("SELECT COUNT(*) FROM house_bookings WHERE guest_id = ? AND booking_status = 'pending' AND check_out_date >= CURDATE()");
        $p->execute([(int)$guestId]);
        return ['stays' => $stays, 'pending' => (int)$p->fetchColumn()];
    }

    /**
     * Decide whether food can be delivered on $date at $time for this guest.
     * Returns ['stay_id' => int, 'address' => house/unit name (from the DATABASE, never from the browser)].
     * Throws InvalidArgumentException (guest-safe message) when delivery is not allowed.
     * $stayId (optional) = the booking the guest picked when they have several; ignored if it is not theirs.
     */
    public static function foodDeliveryResolve(PDO $pdo, $guestId, $date, $time, $stayId = null) {
        $o = self::foodDeliveryStays($pdo, $guestId);
        $stays = $o['stays'];
        if (!$stays) {
            throw new InvalidArgumentException($o['pending'] > 0
                ? 'Delivery is available only for guests with a confirmed house reservation. Your house reservation is still waiting for confirmation, so please choose Pickup for now.'
                : 'Delivery is available only for guests with a confirmed house reservation. Please choose Pickup or book a house first.');
        }
        $item = [['label' => 'Food delivery', 'date' => $date, 'time' => $time]];
        $toWindow = function (array $s) { return ['check_in' => $s['check_in'], 'check_in_time' => $s['check_in_time'], 'check_out' => $s['check_out'], 'check_out_time' => $s['check_out_time']]; };

        $pick = null;
        $sid = (int)$stayId;
        if ($sid > 0) {
            foreach ($stays as $s) if ($s['id'] === $sid) $pick = $s;
            if ($pick === null) throw new InvalidArgumentException('Please choose one of your house stays for delivery.');
        } elseif (count($stays) === 1) {
            $pick = $stays[0];
        } else {
            $match = [];
            foreach ($stays as $s) if (self::packageWindowError($toWindow($s), $item) === null) $match[] = $s;
            if (count($match) === 1) $pick = $match[0];
            elseif (count($match) > 1) throw new InvalidArgumentException('You have more than one house stay at that time. Please choose which house to deliver to.');
            else {
                $parts = [];
                foreach ($stays as $s) { $w = self::packageWindow($toWindow($s)); if ($w) $parts[] = $s['house_name'] . ': ' . $w['start_label'] . ' to ' . $w['end_label']; }
                throw new InvalidArgumentException('Food delivery must be during one of your house stays (' . implode('; ', $parts) . ').');
            }
        }
        if (($err = self::packageWindowError($toWindow($pick), $item)) !== null) throw new InvalidArgumentException($err);
        return ['stay_id' => $pick['id'], 'address' => $pick['house_name']];
    }

    /** Tour (one boat per date). */
    public static function tourConflict(PDO $pdo, $tourId, $date, array $excludeIds = [], array $ownRefs = []) {
        if (!$date) return 'Please choose a date.';
        $ex = self::inList($excludeIds);
        $ph = implode(',', array_fill(0, count($ex), '?'));
        $holding = "'" . implode("','", self::HOLDING_STATUSES) . "'";
        $st = $pdo->prepare("SELECT reference_number FROM tour_bookings
                              WHERE tour_id = ? AND booking_date = ? AND booking_status IN ($holding) AND id NOT IN ($ph) LIMIT 1");
        $st->execute(array_merge([(int)$tourId, $date], $ex));
        if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
            return 'The boat is already booked on ' . self::fmt($date) . ' (booking ' . $b['reference_number'] . ').';
        }
        return self::blockedConflict($pdo, 'tour', $tourId, $date, $date, $ownRefs, false);
    }

    /** Food (only blocked dates). */
    public static function foodConflict(PDO $pdo, $foodId, $date, array $ownRefs = []) {
        if (!$date) return 'Please choose a date.';
        return self::blockedConflict($pdo, 'food', $foodId, $date, $date, $ownRefs, false);
    }

    // ------------------------------------------------------------------
    // HOUSE blocked_dates — Phase 7.9.1. One classification used by the server check AND the calendars.
    // ------------------------------------------------------------------

    const AUTO_REASON_PREFIX = 'Auto-blocked from booking #';
    const BLOCK_MANUAL = 'manual';     // owner/staff block: hard full-day
    const BLOCK_ORPHAN = 'orphan';     // auto-block with no live booking behind it: hard full-day (fail safe)
    const BLOCK_LIVE   = 'live';       // marker of a holding booking: that booking's exact times decide
    const BLOCK_HELD   = 'held';       // marker of a pending rebook that still reserves its old nights: nights rule

    /** The booking reference inside an auto-block reason, or null when the reason is not an auto-block (manual block). */
    public static function autoReasonRef($reason) {
        $reason = (string)$reason;
        if (strncmp($reason, self::AUTO_REASON_PREFIX, strlen(self::AUTO_REASON_PREFIX)) !== 0) return null;
        $ref = trim(substr($reason, strlen(self::AUTO_REASON_PREFIX)));
        return $ref === '' ? null : $ref;
    }

    /** Hard full-day kinds: the calendar date itself is unusable for check-in, overnight AND check-out. */
    public static function isHardBlock($kind) {
        return $kind === self::BLOCK_MANUAL || $kind === self::BLOCK_ORPHAN;
    }

    /**
     * Every blocked_dates row of a house with $from <= block_date <= $to (both inclusive), classified.
     * Rows whose reason is one of $ownRefs' auto-blocks are left out (a booking never blocks itself).
     * Returns [['date' => 'Y-m-d', 'reason' => ?string, 'block_type' => ?string, 'kind' => BLOCK_*, 'ref' => ?string], ...] by date.
     * Read-only; works with or without a surrounding transaction (under lockItem() it sees the locked state).
     */
    public static function houseBlockRows(PDO $pdo, $houseId, $from, $to, array $ownRefs = []) {
        $own = array_values(array_unique(array_map([self::class, 'autoReason'], array_filter($ownRefs))));
        $ownSql = $own ? ' AND (reason IS NULL OR reason NOT IN (' . implode(',', array_fill(0, count($own), '?')) . '))' : '';
        $st = $pdo->prepare("SELECT block_date, reason, block_type FROM blocked_dates
                              WHERE item_type = 'house' AND item_id = ? AND block_date BETWEEN ? AND ? $ownSql ORDER BY block_date, id");
        $st->execute(array_merge([(int)$houseId, $from, $to], $own));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $refs = [];
        foreach ($rows as $r) { if (($ref = self::autoReasonRef($r['reason'])) !== null) $refs[$ref] = true; }
        $bookings = [];
        if ($refs) {
            $keys = array_keys($refs);
            $bs = $pdo->prepare("SELECT reference_number, booking_status, check_in_date, check_out_date, previous_check_in_date, previous_check_out_date,
                                        rebooked_at, rebook_confirmed_at, rebook_count
                                   FROM house_bookings WHERE house_id = ? AND reference_number IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
            $bs->execute(array_merge([(int)$houseId], $keys));
            foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) $bookings[(string)$b['reference_number']] = $b;
        }
        $out = [];
        foreach ($rows as $r) {
            $d = substr((string)$r['block_date'], 0, 10);
            $ref = self::autoReasonRef($r['reason']);
            $kind = self::BLOCK_MANUAL;
            if ($ref !== null) $kind = self::classifyAutoBlock($d, $bookings[$ref] ?? null);
            $out[] = ['date' => $d, 'reason' => $r['reason'], 'block_type' => $r['block_type'], 'kind' => $kind, 'ref' => $ref];
        }
        return $out;
    }

    /** LIVE / HELD / ORPHAN for one auto-block date given the booking it names (null = no such booking at this house). */
    private static function classifyAutoBlock($date, $b) {
        if (!$b) return self::BLOCK_ORPHAN;
        $status = (string)$b['booking_status'];
        $in = substr((string)$b['check_in_date'], 0, 10); $out = substr((string)$b['check_out_date'], 0, 10);
        if (in_array($status, self::HOLDING_STATUSES, true)) {
            return ($date >= $in && $date < $out) ? self::BLOCK_LIVE : self::BLOCK_ORPHAN;   // a marker outside the booking's nights is stale
        }
        if ($status === 'pending') {
            // A pending rebook request keeps its OLD nights reserved until the owner decides (previous_* = the old stay).
            $pIn = !empty($b['previous_check_in_date']) ? substr((string)$b['previous_check_in_date'], 0, 10) : $in;
            $pOut = !empty($b['previous_check_out_date']) ? substr((string)$b['previous_check_out_date'], 0, 10) : $out;
            if ($date >= $pIn && $date < $pOut) return self::BLOCK_HELD;
        }
        return self::BLOCK_ORPHAN;   // cancelled / rejected / moved on: a leftover marker, never trusted, never ignored
    }

    /** House conflict from blocked_dates for the stay [$in, $out]: hard blocks on ANY day of it (check-out day included), held markers on its nights. */
    private static function houseBlockConflict(PDO $pdo, $houseId, $in, $out, array $ownRefs) {
        foreach (self::houseBlockRows($pdo, $houseId, $in, $out, $ownRefs) as $r) {
            $d = $r['date'];
            if ($r['kind'] === self::BLOCK_LIVE) continue;                   // the booking row itself already decided (exact times)
            if ($r['kind'] === self::BLOCK_HELD) {
                if ($d >= $in && $d < $out) return self::fmt($d) . ' is blocked (Pending rebook).';
                continue;
            }
            if ($r['kind'] === self::BLOCK_ORPHAN) {
                static $logged = [];
                $key = (int)$houseId . '|' . $d . '|' . (string)$r['ref'];
                if (empty($logged[$key])) {
                    $logged[$key] = true;
                    error_log('[AvailabilityService] Orphan auto-block treated as a hard block: house ' . (int)$houseId . ' ' . $d . ' reason "' . (string)$r['reason'] . '" (no live booking with that reference at this house). Review in Blocked Dates; nothing was deleted.');
                }
                return self::fmt($d) . ' is not available.';
            }
            $why = ucwords(str_replace('_', ' ', (string)$r['block_type']));
            $reason = trim((string)$r['reason']);
            return self::fmt($d) . ' is blocked' . ($why !== '' ? ' (' . $why . ($reason !== '' ? ': ' . $reason : '') . ')' : '') . '.';
        }
        return null;
    }

    /**
     * Phase 7.9.1 — for calendars: the HARD full-day dates (manual + orphan) of a house in [$from, $to].
     * A stay [in, out] is invalid when any of these falls on in..out inclusive — exactly houseConflict()'s rule.
     */
    public static function houseHardBlockDates(PDO $pdo, $houseId, $from, $to, array $ownRefs = []) {
        $dates = [];
        foreach (self::houseBlockRows($pdo, $houseId, $from, $to, $ownRefs) as $r) {
            if (self::isHardBlock($r['kind'])) $dates[$r['date']] = true;
        }
        return array_keys($dates);
    }

    /** tour/food (one date). House blocks go through houseBlockConflict(); $halfOpen is kept for compatibility. */
    private static function blockedConflict(PDO $pdo, $type, $itemId, $from, $to, array $ownRefs, $halfOpen) {
        $own = array_values(array_unique(array_map([self::class, 'autoReason'], array_filter($ownRefs))));
        $ownSql = '';
        if ($own) $ownSql = ' AND (reason IS NULL OR reason NOT IN (' . implode(',', array_fill(0, count($own), '?')) . '))';
        $range = $halfOpen ? 'block_date >= ? AND block_date < ?' : 'block_date BETWEEN ? AND ?';
        $st = $pdo->prepare("SELECT block_date, reason, block_type FROM blocked_dates
                              WHERE item_type = ? AND item_id = ? AND $range $ownSql ORDER BY block_date LIMIT 1");
        $st->execute(array_merge([$type, (int)$itemId, $from, $to], $own));
        if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
            $why = ucwords(str_replace('_', ' ', (string)$b['block_type']));
            $reason = trim((string)$b['reason']);
            return self::fmt($b['block_date']) . ' is blocked' . ($why !== '' ? ' (' . $why . ($reason !== '' ? ': ' . $reason : '') . ')' : '') . '.';
        }
        return null;
    }

    const PAST_DATE_MESSAGE = 'Selected date is no longer available. Please choose a future date.';

    /**
     * Booking/rebooking dates must be real YYYY-MM-DD dates that are today or later
     * (server time, Asia/Manila). Returns null when valid, otherwise a guest-safe message.
     */
    public static function futureDateError($date) {
        $d = trim((string)$date);
        $dt = DateTime::createFromFormat('!Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d) return 'Please choose a valid date.';
        if ($dt < new DateTime('today')) return self::PAST_DATE_MESSAGE;
        return null;
    }

    /** Throws AvailabilityConflictException when $message is not null. */
    public static function assertFree($message, $label) {
        if ($message !== null) throw new AvailabilityConflictException($label . ': ' . $message);
    }

    /**
     * Dates a house is unavailable, for calendars: each range is
     * {check_in_date, check_out_date} meaning the nights [in, out).
     * A blocked date D is returned as the one-night range [D, D+1); manual / orphan blocks also carry 'hard' => true
     * (the whole day is unusable, check-out included).
     */
    public static function houseOccupiedRanges(PDO $pdo, $houseId, $excludeId = null, $ownRef = null) {
        $holding = "'" . implode("','", self::HOLDING_STATUSES) . "'";
        $st = $pdo->prepare("SELECT check_in_date, check_out_date FROM house_bookings
                              WHERE house_id = ? AND booking_status IN ($holding) AND id <> ? AND check_out_date >= CURDATE()");
        $st->execute([(int)$houseId, (int)$excludeId]);
        $ranges = $st->fetchAll(PDO::FETCH_ASSOC);
        // Phase 7.9.1: blocked dates are classified. Live markers duplicate their booking row (already a range above);
        // HARD blocks (manual / orphan) are returned with 'hard' => true so a calendar can refuse them as a check-out day too.
        $far = date('Y-m-d', strtotime('+5 years'));
        foreach (self::houseBlockRows($pdo, $houseId, date('Y-m-d'), $far, $ownRef ? [$ownRef] : []) as $r) {
            if ($r['kind'] === self::BLOCK_LIVE) continue;
            $range = ['check_in_date' => $r['date'], 'check_out_date' => date('Y-m-d', strtotime($r['date'] . ' +1 day'))];
            if (self::isHardBlock($r['kind'])) $range['hard'] = true;
            $ranges[] = $range;
        }
        return $ranges;
    }

    /** Nights of a stay as Y-m-d strings (check-in .. day before check-out). */
    public static function houseNights($in, $out) {
        $nights = [];
        $d = new DateTime($in);
        $end = new DateTime($out);
        while ($d < $end) { $nights[] = $d->format('Y-m-d'); $d->modify('+1 day'); }
        return $nights;
    }

    /**
     * Mark a confirmed booking's dates as taken in blocked_dates (calendar
     * marker; conflicts are prevented by the locked check above).
     * House: its nights only. Tour/food: the single date.
     */
    public static function blockForBooking(PDO $pdo, $type, $itemId, $start, $end, $reference, $userId = null) {
        if (empty($itemId) || empty($start)) return 0;
        $dates = ($type === 'house') ? self::houseNights($start, $end ?: $start) : [substr((string)$start, 0, 10)];
        $st = $pdo->prepare("INSERT IGNORE INTO blocked_dates (item_type, item_id, block_date, reason, block_type, blocked_by)
                             VALUES (?, ?, ?, ?, 'walk_in', ?)");
        $n = 0;
        foreach ($dates as $d) {
            $st->execute([$type, (int)$itemId, $d, self::autoReason($reference), $userId]);
            $n += $st->rowCount();
        }
        return $n;
    }

    /** Remove the dates auto-blocked for one booking reference. */
    public static function releaseForBooking(PDO $pdo, $type, $itemId, $reference) {
        $st = $pdo->prepare("DELETE FROM blocked_dates WHERE item_type = ? AND item_id = ? AND reason = ?");
        $st->execute([$type, (int)$itemId, self::autoReason($reference)]);
        return $st->rowCount();
    }
}

<?php
/**
 * AvailabilityService — one availability rule for the whole system.
 *
 * HOUSE  A stay occupies the NIGHTS from check-in up to (not including)
 *        check-out. Check-out is 12:00 PM and check-in 2:00 PM, so a new guest
 *        may check in on the day the previous guest checks out.
 *          overlap  = existing.check_in < new.check_out AND existing.check_out > new.check_in
 *          blocked  = block_date >= new.check_in AND block_date < new.check_out
 *        Confirming a stay blocks only its nights (never the check-out day).
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
    public static function houseConflict(PDO $pdo, $houseId, $in, $out, array $excludeIds = [], array $ownRefs = []) {
        if (!$in || !$out || $out <= $in) return 'Check-out must be after check-in.';
        $ex = self::inList($excludeIds);
        $ph = implode(',', array_fill(0, count($ex), '?'));
        $holding = "'" . implode("','", self::HOLDING_STATUSES) . "'";
        $st = $pdo->prepare("SELECT reference_number, check_in_date, check_out_date FROM house_bookings
                              WHERE house_id = ? AND booking_status IN ($holding)
                                AND check_in_date < ? AND check_out_date > ? AND id NOT IN ($ph)
                              ORDER BY check_in_date LIMIT 1");
        $st->execute(array_merge([(int)$houseId, $out, $in], $ex));
        if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
            return 'Already reserved from ' . self::fmt($b['check_in_date']) . ' to ' . self::fmt($b['check_out_date']) . ' (booking ' . $b['reference_number'] . ').';
        }
        return self::blockedConflict($pdo, 'house', $houseId, $in, $out, $ownRefs, true);
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

    /** $halfOpen: house nights [from, to); otherwise the single/inclusive range. */
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

    /** Throws AvailabilityConflictException when $message is not null. */
    public static function assertFree($message, $label) {
        if ($message !== null) throw new AvailabilityConflictException($label . ': ' . $message);
    }

    /**
     * Dates a house is unavailable, for calendars: each range is
     * {check_in_date, check_out_date} meaning the nights [in, out).
     * A blocked date D is returned as the one-night range [D, D+1).
     */
    public static function houseOccupiedRanges(PDO $pdo, $houseId, $excludeId = null, $ownRef = null) {
        $holding = "'" . implode("','", self::HOLDING_STATUSES) . "'";
        $st = $pdo->prepare("SELECT check_in_date, check_out_date FROM house_bookings
                              WHERE house_id = ? AND booking_status IN ($holding) AND id <> ? AND check_out_date >= CURDATE()");
        $st->execute([(int)$houseId, (int)$excludeId]);
        $ranges = $st->fetchAll(PDO::FETCH_ASSOC);
        $sql = "SELECT block_date FROM blocked_dates WHERE item_type = 'house' AND item_id = ? AND block_date >= CURDATE()";
        $params = [(int)$houseId];
        if ($ownRef) { $sql .= ' AND (reason IS NULL OR reason <> ?)'; $params[] = self::autoReason($ownRef); }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $ranges[] = ['check_in_date' => $d, 'check_out_date' => date('Y-m-d', strtotime($d . ' +1 day'))];
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

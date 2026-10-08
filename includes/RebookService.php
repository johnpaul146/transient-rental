<?php
/**
 * RebookService — "no refunds, rebook instead" for every service.
 *
 *  HOUSE    Guest requests new dates (profile.php / rebook.php); owner approves.
 *           confirmHouseRebook() re-checks the new dates with the house locked
 *           and changes nothing if they were taken in the meantime.
 *  TOUR / FOOD / PACKAGE
 *           The price does not change with the date, so the guest picks a new
 *           date and it is applied at once — but only after the availability
 *           check passes inside a transaction with the items locked.
 *           A package moves as one: every part shifts by the same number of
 *           days (house nights unchanged), each part is checked, and the
 *           package keeps its single payment.
 *
 *  In every case the money already received stays on the booking
 *  (amount_paid is never touched, no ledger row is written, no second
 *  ₱1,000 fee). Limits: 2 rebooks per booking, within 7 days of the booking
 *  date — the same rule as house rebooking.
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
     */
    public static function serviceBlockReason($type, array $booking) {
        if (!in_array($type, self::SERVICE_TYPES, true)) return 'Unknown booking type.';
        if ($type !== 'package' && !empty($booking['package_id'])) return 'This item is part of a package. Rebook the whole package instead.';
        // Same rule for every service: confirmed or cancelled WITH money received; never completed.
        if (!in_array($booking['booking_status'] ?? '', ['confirmed', 'cancelled'], true)) {
            return 'Only confirmed bookings, or paid bookings that were cancelled, can be rebooked (completed bookings cannot).';
        }
        if ((float)($booking['amount_paid'] ?? 0) <= 0) return 'Only bookings with a paid reservation fee can be rebooked.';
        if (self::remaining($booking) <= 0) return 'This booking has already been rebooked ' . self::MAX_REBOOKS . ' times (maximum).';
        if (self::daysLeft($booking) <= 0) return 'Rebooking is only possible within ' . self::WINDOW_DAYS . ' days from booking date.';
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

    private static function lockBooking(PDO $pdo, $table, $id) {
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
    // TOUR / FOOD / PACKAGE — guest picks a new date
    // ------------------------------------------------------------------

    /**
     * Rebook a tour/food booking or a whole package to $newDate (for a
     * package: the new FIRST day; all parts move by the same number of days).
     * $guestId: owner check. Returns ['reference', 'old_date', 'new_date'].
     * Throws AvailabilityConflictException / RuntimeException / InvalidArgumentException.
     */
    public static function rebookService(PDO $pdo, $type, $id, $newDate, $guestId, $userId = null) {
        if (!in_array($type, self::SERVICE_TYPES, true)) throw new InvalidArgumentException('Unknown booking type.');
        $new = self::parseDate($newDate);
        $table = PaymentService::TABLES[$type];

        $pdo->beginTransaction();
        try {
            $b = self::lockBooking($pdo, $table, $id);
            if ((int)$b['guest_id'] !== (int)$guestId) throw new RuntimeException('Booking not found.');
            $why = self::serviceBlockReason($type, $b);
            if ($why !== null) throw new RuntimeException($why);

            if ($type === 'tour') {
                $old = $b['booking_date'];
                if ($new->format('Y-m-d') === $old && $b['booking_status'] !== 'cancelled') throw new InvalidArgumentException('Please choose a different date.');
                AvailabilityService::lockItem($pdo, 'tour', $b['tour_id']);
                AvailabilityService::assertFree(AvailabilityService::tourConflict($pdo, $b['tour_id'], $new->format('Y-m-d'), [$b['id']], [$b['reference_number']]), 'Tour');
                $pdo->prepare("UPDATE tour_bookings SET booking_date = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL,
                                      rebook_count = rebook_count + 1, rebooked_at = NOW() WHERE id = ?")
                    ->execute([$new->format('Y-m-d'), (int)$b['id']]);
                AvailabilityService::releaseForBooking($pdo, 'tour', $b['tour_id'], $b['reference_number']);
                AvailabilityService::blockForBooking($pdo, 'tour', $b['tour_id'], $new->format('Y-m-d'), null, $b['reference_number'], $userId);
            } elseif ($type === 'food') {
                $old = $b['preferred_date'];
                if ($new->format('Y-m-d') === $old && $b['booking_status'] !== 'cancelled') throw new InvalidArgumentException('Please choose a different date.');
                AvailabilityService::lockItem($pdo, 'food', $b['food_id']);   // same lock order: house -> tour -> food
                AvailabilityService::assertFree(AvailabilityService::foodConflict($pdo, $b['food_id'], $new->format('Y-m-d'), [$b['reference_number']]), 'Food');
                $pdo->prepare("UPDATE food_bookings SET preferred_date = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL,
                                      rebook_count = rebook_count + 1, rebooked_at = NOW() WHERE id = ?")
                    ->execute([$new->format('Y-m-d'), (int)$b['id']]);
            } else {
                $old = self::rebookPackage($pdo, $b, $new, $userId);
            }
            $pdo->commit();
            return ['reference' => $b['reference_number'], 'old_date' => $old, 'new_date' => $new->format('Y-m-d')];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** First service day of a package (from its parts). */
    public static function packageStartDate(array $parts) {
        $dates = [];
        if ($parts['house']) $dates[] = $parts['house']['check_in_date'];
        if ($parts['tour'])  $dates[] = $parts['tour']['booking_date'];
        if ($parts['food'] && !empty($parts['food']['preferred_date'])) $dates[] = $parts['food']['preferred_date'];
        return $dates ? min($dates) : null;
    }

    private static function shift($date, $days) {
        return $date ? (new DateTime($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d') : $date;
    }

    /** Inside rebookService's transaction. Returns the old start date. */
    private static function rebookPackage(PDO $pdo, array $p, DateTime $newStart, $userId) {
        $parts = PaymentService::lockPackageParts($pdo, $p);
        $start = self::packageStartDate($parts);
        if (!$start) throw new RuntimeException('This package has no dated items to move.');
        $days = (int)round((strtotime($newStart->format('Y-m-d')) - strtotime($start)) / 86400);
        if ($days === 0 && $p['booking_status'] !== 'cancelled') throw new InvalidArgumentException('Please choose a different date.');

        // Check every part at its new date (house before tour = same lock order as payment confirmation)
        if ($h = $parts['house']) {
            AvailabilityService::lockItem($pdo, 'house', $h['house_id']);
            AvailabilityService::assertFree(AvailabilityService::houseConflict($pdo, $h['house_id'], self::shift($h['check_in_date'], $days), self::shift($h['check_out_date'], $days), [$h['id']], [$h['reference_number']], $h['check_in_time'] ?? '14:00:00', $h['check_out_time'] ?? '12:00:00'), 'House');
        }
        if ($t = $parts['tour']) {
            AvailabilityService::lockItem($pdo, 'tour', $t['tour_id']);
            AvailabilityService::assertFree(AvailabilityService::tourConflict($pdo, $t['tour_id'], self::shift($t['booking_date'], $days), [$t['id']], [$t['reference_number']]), 'Tour');
        }
        if (($f = $parts['food']) && !empty($f['preferred_date'])) {
            AvailabilityService::lockItem($pdo, 'food', $f['food_id']);   // same lock order: house -> tour -> food
            AvailabilityService::assertFree(AvailabilityService::foodConflict($pdo, $f['food_id'], self::shift($f['preferred_date'], $days), [$f['reference_number']]), 'Food');
        }

        // Move the parts (no money on parts; the package keeps its single payment)
        if ($h) {
            $pdo->prepare("UPDATE house_bookings SET check_in_date = ?, check_out_date = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL WHERE id = ?")
                ->execute([self::shift($h['check_in_date'], $days), self::shift($h['check_out_date'], $days), (int)$h['id']]);
            AvailabilityService::releaseForBooking($pdo, 'house', $h['house_id'], $h['reference_number']);
            AvailabilityService::blockForBooking($pdo, 'house', $h['house_id'], self::shift($h['check_in_date'], $days), self::shift($h['check_out_date'], $days), $h['reference_number'], $userId);
        }
        if ($t) {
            $pdo->prepare("UPDATE tour_bookings SET booking_date = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL WHERE id = ?")
                ->execute([self::shift($t['booking_date'], $days), (int)$t['id']]);
            AvailabilityService::releaseForBooking($pdo, 'tour', $t['tour_id'], $t['reference_number']);
            AvailabilityService::blockForBooking($pdo, 'tour', $t['tour_id'], self::shift($t['booking_date'], $days), null, $t['reference_number'], $userId);
        }
        if ($f) {
            $pdo->prepare("UPDATE food_bookings SET preferred_date = ?, booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL WHERE id = ?")
                ->execute([self::shift($f['preferred_date'], $days), (int)$f['id']]);
        }
        $pdo->prepare("UPDATE package_bookings SET booking_status = 'confirmed', cancelled_at = NULL, cancellation_reason = NULL,
                              rebook_count = rebook_count + 1, rebooked_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([(int)$p['id']]);
        PaymentService::syncPackageComponents($pdo, (int)$p['id']);   // parts mirror the package payment state
        return $start;
    }
}

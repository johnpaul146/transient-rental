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

    /** Tour moved to TODAY: its booked time slot must still be ahead. Returns a message or null. */
    private static function tourTimeError($newYmd, $time) {
        if ($newYmd !== date('Y-m-d') || empty($time)) return null;
        $slot = strtotime($newYmd . ' ' . $time);
        if ($slot !== false && $slot <= time()) {
            return 'Your tour time (' . date('g:i A', $slot) . ') has already passed today. Please choose a later date.';
        }
        return null;
    }

    /** Food moved to TODAY: its booked time must still be ahead. Returns a message or null. */
    private static function foodTimeError($newYmd, $time) {
        if ($newYmd !== date('Y-m-d') || empty($time)) return null;
        $slot = strtotime($newYmd . ' ' . $time);
        if ($slot !== false && $slot <= time()) {
            return 'Your food time (' . date('g:i A', $slot) . ') has already passed today. Please choose a later date.';
        }
        return null;
    }

    /**
     * A DELIVERY order must land inside a confirmed stay of the guest, at the same unit it was booked for
     * (the unit never changes). Uses the same rule as ordering (AvailabilityService::foodDeliveryResolve):
     * the new date and the unchanged time must be inside the house window. Pickup orders: nothing to check.
     * Returns a guest-safe message or null.
     */
    private static function foodDeliveryError(PDO $pdo, array $b, $newYmd) {
        if (($b['fulfillment_method'] ?? 'pickup') !== 'delivery') return null;
        $unit = trim((string)($b['delivery_address'] ?? ''));
        $time = (string)($b['preferred_time'] ?? '');
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
                if ($tErr = self::tourTimeError($new->format('Y-m-d'), $b['preferred_time'] ?? '')) throw new InvalidArgumentException($tErr);
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
                if ($fErr = self::foodTimeError($new->format('Y-m-d'), $b['preferred_time'] ?? '')) throw new InvalidArgumentException($fErr);
                if ($dErr = self::foodDeliveryError($pdo, $b, $new->format('Y-m-d'))) throw new InvalidArgumentException($dErr);
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

        // Stay-window guard: if the tour/food sat inside the house stay before the move, they must still
        // be inside it after the move (they shift with the house, so this can only fail on corrupt data).
        if ($h) {
            $win = function ($hh, $d) {
                return ['check_in' => self::shift($hh['check_in_date'], $d), 'check_out' => self::shift($hh['check_out_date'], $d),
                        'check_in_time' => $hh['check_in_time'] ?? '14:00:00', 'check_out_time' => $hh['check_out_time'] ?? '12:00:00'];
            };
            $items = function ($d) use ($t, $f) {
                $o = [];
                if ($t && !empty($t['preferred_time'])) $o[] = ['label' => 'Tour', 'date' => self::shift($t['booking_date'], $d), 'time' => $t['preferred_time']];
                if ($f && !empty($f['preferred_date']) && !empty($f['preferred_time'])) $o[] = ['label' => 'Food', 'date' => self::shift($f['preferred_date'], $d), 'time' => $f['preferred_time']];
                return $o;
            };
            if (AvailabilityService::packageWindowError($win($h, 0), $items(0)) === null) {
                $err = AvailabilityService::packageWindowError($win($h, $days), $items($days));
                if ($err !== null) throw new InvalidArgumentException($err);
            }
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

    /** Where every part lands when the package's first day becomes $newStart (Y-m-d). */
    public static function packagePlan(array $parts, $newStart) {
        $start = self::packageStartDate($parts);
        $days = (int)round((strtotime($newStart) - strtotime($start)) / 86400);
        $plan = ['days' => $days, 'start' => $newStart, 'house' => null, 'tour' => null, 'food' => null];
        if ($h = $parts['house']) $plan['house'] = ['in' => self::shift($h['check_in_date'], $days), 'out' => self::shift($h['check_out_date'], $days),
            'in_time' => $h['check_in_time'] ?: '14:00:00', 'out_time' => $h['check_out_time'] ?: '12:00:00'];
        if ($t = $parts['tour']) $plan['tour'] = ['date' => self::shift($t['booking_date'], $days), 'time' => $t['preferred_time'] ?: null];
        if (($f = $parts['food']) && !empty($f['preferred_date'])) $plan['food'] = ['date' => self::shift($f['preferred_date'], $days), 'time' => $f['preferred_time'] ?: null];
        return $plan;
    }

    /**
     * Is $newDate a possible new first day? Same checks rebookPackage() makes (without locks, for display only;
     * the real check always runs again inside the transaction).
     * Returns ['ok' => bool, 'message' => string, 'plan' => array|null].
     */
    public static function packageDateCheck(PDO $pdo, array $p, array $parts, $newDate) {
        try { $new = self::parseDate($newDate)->format('Y-m-d'); }
        catch (InvalidArgumentException $e) { return ['ok' => false, 'message' => $e->getMessage(), 'plan' => null]; }
        $start = self::packageStartDate($parts);
        if (!$start) return ['ok' => false, 'message' => 'This package has no dated items to move.', 'plan' => null];
        $plan = self::packagePlan($parts, $new);
        $d = $plan['days'];
        if ($d === 0 && ($p['booking_status'] ?? '') !== 'cancelled') return ['ok' => false, 'message' => 'This is your current first day.', 'plan' => $plan];
        $fail = function ($m) use ($plan) { return ['ok' => false, 'message' => $m, 'plan' => $plan]; };
        if ($h = $parts['house']) {
            $m = AvailabilityService::houseConflict($pdo, $h['house_id'], $plan['house']['in'], $plan['house']['out'], [$h['id']], [$h['reference_number']], $h['check_in_time'] ?? '14:00:00', $h['check_out_time'] ?? '12:00:00');
            if ($m) return $fail('House: ' . $m);
        }
        if ($t = $parts['tour']) {
            $m = AvailabilityService::tourConflict($pdo, $t['tour_id'], $plan['tour']['date'], [$t['id']], [$t['reference_number']]);
            if ($m) return $fail('Tour: ' . $m);
        }
        if (($f = $parts['food']) && $plan['food']) {
            $m = AvailabilityService::foodConflict($pdo, $f['food_id'], $plan['food']['date'], [$f['reference_number']]);
            if ($m) return $fail('Food: ' . $m);
        }
        if ($h = $parts['house']) {   // tour/food must stay inside the (moved) house stay
            $win = ['check_in' => $plan['house']['in'], 'check_out' => $plan['house']['out'], 'check_in_time' => $plan['house']['in_time'], 'check_out_time' => $plan['house']['out_time']];
            $items = [];
            if ($plan['tour'] && $plan['tour']['time']) $items[] = ['label' => 'Tour', 'date' => $plan['tour']['date'], 'time' => $plan['tour']['time']];
            if ($plan['food'] && $plan['food']['time']) $items[] = ['label' => 'Food', 'date' => $plan['food']['date'], 'time' => $plan['food']['time']];
            $orig = AvailabilityService::packageWindowError(['check_in' => $h['check_in_date'], 'check_out' => $h['check_out_date'], 'check_in_time' => $h['check_in_time'] ?? '14:00:00', 'check_out_time' => $h['check_out_time'] ?? '12:00:00'],
                self::origItems($parts));
            if ($orig === null && ($err = AvailabilityService::packageWindowError($win, $items))) return $fail($err);
        }
        return ['ok' => true, 'message' => '', 'plan' => $plan];
    }

    private static function origItems(array $parts) {
        $o = [];
        if ($parts['tour'] && !empty($parts['tour']['preferred_time'])) $o[] = ['label' => 'Tour', 'date' => $parts['tour']['booking_date'], 'time' => $parts['tour']['preferred_time']];
        if ($parts['food'] && !empty($parts['food']['preferred_date']) && !empty($parts['food']['preferred_time'])) $o[] = ['label' => 'Food', 'date' => $parts['food']['preferred_date'], 'time' => $parts['food']['preferred_time']];
        return $o;
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

    /**
     * Is $newDate a possible new date for this tour booking? Same checks rebookService() makes (no locks, display only).
     * Only the DATE moves: guests (pax), time slot and every money field stay as they are.
     * Returns ['ok' => bool, 'message' => string, 'plan' => ['date','time','old_date']|null].
     */
    public static function tourDateCheck(PDO $pdo, array $b, $newDate) {
        try { $new = self::parseDate($newDate)->format('Y-m-d'); }
        catch (InvalidArgumentException $e) { return ['ok' => false, 'message' => $e->getMessage(), 'plan' => null]; }
        $plan = ['date' => $new, 'time' => $b['preferred_time'] ?: null, 'old_date' => $b['booking_date']];
        $fail = function ($m) use ($plan) { return ['ok' => false, 'message' => $m, 'plan' => $plan]; };
        if ($new === $b['booking_date'] && ($b['booking_status'] ?? '') !== 'cancelled') return $fail('This is your current tour date.');
        if ($m = self::tourTimeError($new, $b['preferred_time'] ?? '')) return $fail($m);
        if ($m = AvailabilityService::tourConflict($pdo, $b['tour_id'], $new, [$b['id']], [$b['reference_number']])) return $fail($m);
        return ['ok' => true, 'message' => '', 'plan' => $plan];
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

    /**
     * Is $newDate a possible new date for this food order? Same checks rebookService() makes (no locks, display only).
     * Only the DATE moves: quantity, size, time, method, unit and every money field stay as they are.
     * Returns ['ok' => bool, 'message' => string, 'plan' => ['date','time','method','address','old_date']|null].
     */
    public static function foodDateCheck(PDO $pdo, array $b, $newDate) {
        try { $new = self::parseDate($newDate)->format('Y-m-d'); }
        catch (InvalidArgumentException $e) { return ['ok' => false, 'message' => $e->getMessage(), 'plan' => null]; }
        $plan = ['date' => $new, 'time' => $b['preferred_time'] ?: null, 'method' => $b['fulfillment_method'] ?: 'pickup',
                 'address' => $b['delivery_address'] ?: null, 'old_date' => $b['preferred_date']];
        $fail = function ($m) use ($plan) { return ['ok' => false, 'message' => $m, 'plan' => $plan]; };
        if ($new === $b['preferred_date'] && ($b['booking_status'] ?? '') !== 'cancelled') return $fail('This is your current date.');
        if ($m = self::foodTimeError($new, $b['preferred_time'] ?? '')) return $fail($m);
        if ($m = self::foodDeliveryError($pdo, $b, $new)) return $fail('Delivery: ' . $m);
        if ($m = AvailabilityService::foodConflict($pdo, $b['food_id'], $new, [$b['reference_number']])) return $fail($m);
        return ['ok' => true, 'message' => '', 'plan' => $plan];
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

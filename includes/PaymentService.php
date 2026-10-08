<?php
/**
 * ============================================================================
 * PaymentService — reservation fee, balance, cancellation-credit and package
 * payment rules for Transient House & Tours.
 * ============================================================================
 * BUSINESS RULES
 *  - A ₱1,000 reservation fee secures a booking (or the booking total if it is
 *    lower). It is PART of the booking total.
 *  - The remaining balance is paid on arrival / before the service, recorded
 *    by owner/staff with "Mark Balance as Paid".
 *  - No refunds. A paid reservation that cannot go ahead keeps its payment as
 *    a rebooking credit (booking_status = cancelled, amount_paid > 0).
 *  - A package is ONE transaction: the reservation fee and all money belong
 *    to package_bookings. Component rows (package_id set) carry no money and
 *    mirror the package payment state.
 *
 * STORAGE (see migrations/2026_10_payment_rebooking_integrity.sql)
 *  <table>.reservation_fee_amount  fee required
 *  <table>.amount_paid             money received so far (= SUM of ledger)
 *  <table>.reservation_paid_at     when the reservation fee was confirmed
 *  <table>.balance_paid_at         when the balance was received
 *  booking_payments                one row per money event; UNIQUE
 *                                  (booking_type, booking_id, payment_type)
 *                                  makes recording idempotent.
 *  payment_status: pending | reservation_paid | paid | cancelled
 *    pending           no money received
 *    reservation_paid  reservation fee received, balance due
 *    paid              fully settled (balance due = 0)
 *    cancelled         payment rejected (no money received)
 * ============================================================================
 */

require_once __DIR__ . '/AvailabilityService.php';

class PaymentService {

    const RESERVATION_FEE = 1000.00;

    const TABLES = [
        'house'   => 'house_bookings',
        'tour'    => 'tour_bookings',
        'food'    => 'food_bookings',
        'package' => 'package_bookings',
    ];

    const METHODS = ['cash' => 'Cash', 'gcash' => 'GCash', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'];

    /** Reservation fee required for a booking total. */
    public static function feeFor($total) {
        $t = round(max(0.0, (float)$total), 2);
        return min(self::RESERVATION_FEE, $t);
    }

    const FOOD_MAX_QUANTITY = 100;

    /**
     * ONE place that prices a food order: unit price (from the DATABASE row — size variation when the
     * item has sizes, otherwise the item price) x quantity. Nothing the browser sends as a price is used.
     *   $food      : food_items row (needs price, size_variations)
     *   $sizeInput : posted size index (string/int) — required and must exist when the item has sizes
     *   $qtyInput  : posted quantity — must be a whole number 1..FOOD_MAX_QUANTITY (no decimals, text, 0, negatives)
     * Returns ['unit' => float, 'size' => string, 'size_index' => ?int, 'quantity' => int, 'total' => float].
     * Throws InvalidArgumentException with a guest-safe message.
     */
    public static function foodLine(array $food, $sizeInput, $qtyInput = 1) {
        $unit = $food['price'] ?? null;
        $size = ''; $idx = null;
        $vars = !empty($food['size_variations']) ? json_decode((string)$food['size_variations'], true) : null;
        if (is_array($vars) && !empty($vars)) {
            $raw = is_string($sizeInput) ? trim($sizeInput) : $sizeInput;
            if (!is_scalar($raw) || $raw === '' || !preg_match('/^\d{1,3}$/', (string)$raw) || !isset($vars[(int)$raw]) || !is_array($vars[(int)$raw])) {
                throw new InvalidArgumentException('Please choose a valid size.');
            }
            $idx  = (int)$raw;
            $unit = $vars[$idx]['price'] ?? null;
            $size = trim((string)($vars[$idx]['size'] ?? ''));
        }
        if (!is_numeric($unit) || (float)$unit < 0) throw new InvalidArgumentException('This food item has no valid price. Please contact us.');

        $q = is_string($qtyInput) ? trim($qtyInput) : $qtyInput;
        if (is_int($q)) $q = (string)$q;
        if (!is_string($q) || !preg_match('/^[1-9]\d{0,3}$/', $q)) {
            throw new InvalidArgumentException('Quantity must be a whole number of at least 1.');
        }
        $qty = (int)$q;
        if ($qty > self::FOOD_MAX_QUANTITY) throw new InvalidArgumentException('Quantity cannot be more than ' . self::FOOD_MAX_QUANTITY . '. Please contact us for larger orders.');

        $unit = round((float)$unit, 2);
        return ['unit' => $unit, 'size' => $size, 'size_index' => $idx, 'quantity' => $qty, 'total' => round($unit * $qty, 2)];
    }

    public static function peso($amount, $decimals = 2) {
        return '₱' . number_format((float)$amount, $decimals);
    }

    // ------------------------------------------------------------------
    // Reading a booking row (works on rows from any of the four tables)
    // ------------------------------------------------------------------

    public static function isComponent(array $row) {
        return !empty($row['package_id']) && (int)$row['package_id'] !== 0 && !isset($row['grand_total']);
    }

    public static function isLegacyRebook(array $row) {
        return !empty($row['original_booking_id']);
    }

    /**
     * Money facts for a row. Tolerates rows read before the migration ran
     * (falls back to the old payment_status) so pages never crash.
     */
    public static function amounts(array $row) {
        $total = isset($row['grand_total']) ? (float)$row['grand_total'] : (float)($row['total_amount'] ?? 0);
        $status = strtolower((string)($row['payment_status'] ?? 'pending'));
        $fee = (isset($row['reservation_fee_amount']) && (float)$row['reservation_fee_amount'] > 0)
            ? (float)$row['reservation_fee_amount'] : self::feeFor($total);
        if (array_key_exists('amount_paid', $row)) {
            $paid = (float)$row['amount_paid'];
        } else {
            $paid = in_array($status, ['paid', 'reservation_paid'], true) ? min($fee, $total) : 0.0;
        }
        if (self::isComponent($row) || self::isLegacyRebook($row)) { $fee = 0.0; $paid = 0.0; }
        $balance = max(0.0, round($total - $paid, 2));
        $credit  = max(0.0, round($paid - $total, 2));
        return ['total' => $total, 'fee' => $fee, 'paid' => $paid, 'balance' => $balance, 'credit' => $credit];
    }

    /**
     * One combined state used for labels and allowed actions:
     *  unpaid | reservation_paid | paid | rebook_required | cancelled |
     *  rejected | component | legacy_rebook
     */
    public static function state(array $row) {
        if (self::isLegacyRebook($row)) return 'legacy_rebook';
        if (self::isComponent($row)) return 'component';
        $a = self::amounts($row);
        $pay = strtolower((string)($row['payment_status'] ?? 'pending'));
        $booking = strtolower((string)($row['booking_status'] ?? 'pending'));
        if ($booking === 'cancelled') {
            if ($a['paid'] > 0) return 'rebook_required';
            return $pay === 'cancelled' ? 'rejected' : 'cancelled';
        }
        if ($pay === 'cancelled') return 'rejected';
        if ($a['paid'] <= 0) return 'unpaid';
        if ($a['balance'] <= 0) return 'paid';
        return 'reservation_paid';
    }

    /** Owner/staff wording. */
    public static function ownerLabel($state) {
        $map = [
            'unpaid'           => ['Pending Payment', 'badge-warning', 'fa-clock'],
            'reservation_paid' => ['Reservation Fee Paid', 'badge-info', 'fa-receipt'],
            'paid'             => ['Fully Paid', 'badge-success', 'fa-check-circle'],
            'rebook_required'  => ['Rebooking Required', 'badge-rebook', 'fa-redo'],
            'cancelled'        => ['Cancelled (unpaid)', 'badge-danger', 'fa-times-circle'],
            'rejected'         => ['Payment Rejected', 'badge-danger', 'fa-times-circle'],
            'component'        => ['Part of package', 'badge-info', 'fa-box-open'],
            'legacy_rebook'    => ['Old rebooking record', 'badge-info', 'fa-history'],
        ];
        return $map[$state] ?? $map['unpaid'];
    }

    /** Customer wording. */
    public static function guestLabel($state) {
        $map = [
            'unpaid'           => ['Awaiting Reservation Fee', 'badge-warning', 'fa-clock'],
            'reservation_paid' => ['Reservation Secured', 'badge-success', 'fa-shield-alt'],
            'paid'             => ['Fully Paid', 'badge-success', 'fa-check-circle'],
            'rebook_required'  => ['Rebooking Required', 'badge-info', 'fa-redo'],
            'cancelled'        => ['Cancelled', 'badge-danger', 'fa-times-circle'],
            'rejected'         => ['Payment Not Accepted', 'badge-danger', 'fa-times-circle'],
            'component'        => ['Part of package', 'badge-info', 'fa-box-open'],
            'legacy_rebook'    => ['Rebooking record', 'badge-info', 'fa-history'],
        ];
        return $map[$state] ?? $map['unpaid'];
    }

    private static function badgeHtml(array $label) {
        return '<span class="badge ' . $label[1] . '"><i class="fas ' . $label[2] . '"></i> '
             . htmlspecialchars($label[0], ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** Reservation fee (or more) received and the booking is not cancelled. */
    public static function isSecured(array $row) {
        return in_array(self::state($row), ['reservation_paid', 'paid'], true);
    }

    /** Guest may pay the reservation fee (upload proof) for this row. */
    public static function canPayReservation(array $row) {
        return self::state($row) === 'unpaid';
    }

    public static function adminBadge(array $row) { return self::badgeHtml(self::ownerLabel(self::state($row))); }
    public static function guestBadge(array $row) { return self::badgeHtml(self::guestLabel(self::state($row))); }

    /** Owner/staff amount cell: Total / Fee / Paid / Balance. */
    public static function adminAmountCell(array $row) {
        $s = self::state($row);
        $a = self::amounts($row);
        $h = '<div class="pay-cell"><strong>' . self::peso($a['total'], 0) . '</strong>';
        if ($s === 'component') {
            return $h . '<small class="pay-line">Paid with the package</small></div>';
        }
        if ($s === 'legacy_rebook') {
            return $h . '<small class="pay-line">Not a separate sale</small></div>';
        }
        $h .= '<small class="pay-line">Fee ' . self::peso($a['fee'], 0) . ' · Paid ' . self::peso($a['paid'], 0) . '</small>';
        if ($s === 'rebook_required') {
            $h .= '<small class="pay-line pay-credit">Credit ' . self::peso($a['paid'], 0) . ' for rebooking</small>';
        } elseif (!in_array($s, ['cancelled', 'rejected'], true)) {
            $h .= '<small class="pay-line ' . ($a['balance'] > 0 ? 'pay-due' : 'pay-ok') . '">Balance '
                . self::peso($a['balance'], 0) . '</small>';
        }
        if ($a['credit'] > 0 && $s !== 'rebook_required') {
            $h .= '<small class="pay-line pay-credit">Excess credit ' . self::peso($a['credit'], 0) . ' — owner review</small>';
        }
        return $h . '</div>';
    }

    /** Customer amount cell: Total / Reservation fee / Balance on arrival. */
    public static function guestAmountCell(array $row) {
        $s = self::state($row);
        $a = self::amounts($row);
        $h = '<div class="pay-cell"><strong>' . self::peso($a['total'], 0) . '</strong>';
        if ($s === 'component' || $s === 'legacy_rebook') return $h . '</div>';
        if ($s === 'unpaid') {
            $h .= '<small class="pay-line">Reservation fee ' . self::peso($a['fee'], 0) . '</small>';
            $h .= '<small class="pay-line">Balance on arrival ' . self::peso(max(0, $a['total'] - $a['fee']), 0) . '</small>';
        } elseif ($s === 'reservation_paid') {
            $h .= '<small class="pay-line pay-ok">' . self::peso($a['paid'], 0) . ' received</small>';
            $h .= '<small class="pay-line pay-due">Balance ' . self::peso($a['balance'], 0) . ' — pay upon arrival</small>';
        } elseif ($s === 'paid') {
            $h .= '<small class="pay-line pay-ok">Fully paid</small>';
        } elseif ($s === 'rebook_required') {
            $h .= '<small class="pay-line pay-credit">' . self::peso($a['paid'], 0) . ' credit kept for rebooking (non-refundable)</small>';
        }
        return $h . '</div>';
    }

    /** CSS for the amount cells / extra badges (include once per page). */
    public static function css() {
        return '<style>
.pay-cell{display:flex;flex-direction:column;gap:1px;line-height:1.3}
.pay-cell .pay-line{font-size:11px;color:#64748b;font-weight:500;white-space:nowrap}
.pay-cell .pay-due{color:#b45309}.pay-cell .pay-ok{color:#047857}.pay-cell .pay-credit{color:#6d28d9}
.badge.badge-rebook{background:#ede9fe;color:#6d28d9}
.badge.badge-info{background:#dbeafe;color:#1d4ed8}.badge.badge-danger{background:#fee2e2;color:#b91c1c}
.pay-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin:10px 0}
.pay-summary .row-line{display:flex;justify-content:space-between;gap:10px;font-size:14px;padding:3px 0}
.pay-summary .row-line.strong{font-weight:700;color:#0B2447}
.pay-summary .row-line.due{color:#b45309;font-weight:600}
.pay-note{font-size:12px;color:#64748b;margin-top:6px;line-height:1.5}
</style>';
    }

    // ------------------------------------------------------------------
    // GCash configuration (never publish placeholder account details)
    // ------------------------------------------------------------------

    public static function gcashConfig(PDO $pdo) {
        $cfg = ['account_name' => '', 'number' => '', 'qr_code' => '', 'instructions' => ''];
        try {
            $st = $pdo->query("SELECT content_key, content_value FROM site_content WHERE section_name = 'gcash'");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cfg[$r['content_key']] = (string)$r['content_value'];
        } catch (PDOException $e) {
            error_log('[PaymentService] gcash config read failed: ' . $e->getMessage());
        }
        $name = strtolower(trim($cfg['account_name']));
        $num  = preg_replace('/[\s\-]/', '', (string)$cfg['number']);
        $cfg['configured'] = ($name !== '' && $name !== 'juan dela cruz' && $num !== '' && $num !== '09123456789');
        return $cfg;
    }

    // ------------------------------------------------------------------
    // Recording money (idempotent, transactional)
    // ------------------------------------------------------------------

    private static function tableFor($type) {
        if (!isset(self::TABLES[$type])) throw new InvalidArgumentException('Invalid booking type.');
        return self::TABLES[$type];
    }

    private static function lockRow(PDO $pdo, $type, $id) {
        $table = self::tableFor($type);
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? FOR UPDATE");
        $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Booking not found.');
        return $row;
    }

    private static function insertLedger(PDO $pdo, $type, array $row, $kind, $amount, $method, $userId, $receivedAt, $notes = null) {
        $st = $pdo->prepare("INSERT INTO booking_payments
            (booking_type, booking_id, reference_number, payment_type, amount, payment_method, gcash_reference, received_at, recorded_by, source, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'app', ?)");
        $st->execute([$type, (int)$row['id'], (string)$row['reference_number'], $kind, round($amount, 2), $method,
                      $row['gcash_reference'] ?? null, $receivedAt, $userId ?: null, $notes]);
    }

    private static function isDuplicateKey(PDOException $e) {
        return ($e->errorInfo[1] ?? null) == 1062 || strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'duplicate') !== false;
    }

    /** Package parts as rows, locked FOR UPDATE. ['house' => row|null, 'tour' => ..., 'food' => ...] */
    public static function lockPackageParts(PDO $pdo, array $package) {
        $parts = ['house' => null, 'tour' => null, 'food' => null];
        foreach (['house' => 'house_booking_id', 'tour' => 'tour_booking_id', 'food' => 'food_booking_id'] as $t => $col) {
            if (empty($package[$col])) continue;
            $st = $pdo->prepare('SELECT * FROM `' . self::TABLES[$t] . '` WHERE id = ? FOR UPDATE');
            $st->execute([(int)$package[$col]]);
            $parts[$t] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $parts;
    }

    /**
     * Throws AvailabilityConflictException if the booking's dates are taken.
     * Locks the house/tour rows first (always house before tour) so concurrent
     * confirmations of the same item run one after the other.
     */
    public static function assertDatesFree(PDO $pdo, $type, array $row) {
        $checks = [];
        if ($type === 'package') {
            foreach (self::lockPackageParts($pdo, $row) as $t => $part) if ($part) $checks[] = [$t, $part];
        } else {
            $checks[] = [$type, $row];
        }
        usort($checks, function ($a, $b) { $o = ['house' => 0, 'tour' => 1, 'food' => 2]; return $o[$a[0]] <=> $o[$b[0]]; });
        foreach ($checks as $c) {
            list($t, $r) = $c;
            if ($t === 'house') {
                AvailabilityService::lockItem($pdo, 'house', $r['house_id']);
                AvailabilityService::assertFree(AvailabilityService::houseConflict($pdo, $r['house_id'], $r['check_in_date'], $r['check_out_date'], [$r['id']], [$r['reference_number']], $r['check_in_time'] ?? '14:00:00', $r['check_out_time'] ?? '12:00:00'), 'House');
            } elseif ($t === 'tour') {
                AvailabilityService::lockItem($pdo, 'tour', $r['tour_id']);
                AvailabilityService::assertFree(AvailabilityService::tourConflict($pdo, $r['tour_id'], $r['booking_date'], [$r['id']], [$r['reference_number']]), 'Tour');
            } elseif ($t === 'food') {
                AvailabilityService::lockItem($pdo, 'food', $r['food_id']);   // same lock order: house -> tour -> food
                AvailabilityService::assertFree(AvailabilityService::foodConflict($pdo, $r['food_id'], $r['preferred_date'], [$r['reference_number']]), 'Food');
            }
        }
    }

    /** Mark the confirmed booking's (or package parts') dates as taken. Same transaction. */
    private static function blockConfirmedDates(PDO $pdo, $type, array $row, array $components, $userId) {
        if ($type === 'house') {
            AvailabilityService::blockForBooking($pdo, 'house', $row['house_id'], $row['check_in_date'], $row['check_out_date'], $row['reference_number'], $userId ?: null);
        } elseif ($type === 'tour') {
            AvailabilityService::blockForBooking($pdo, 'tour', $row['tour_id'], $row['booking_date'], null, $row['reference_number'], $userId ?: null);
        } elseif ($type === 'package') {
            foreach ($components as $c) {
                if ($c['type'] === 'house') {
                    $st = $pdo->prepare('SELECT house_id, check_in_date, check_out_date FROM house_bookings WHERE id = ?');
                    $st->execute([$c['id']]);
                    if ($h = $st->fetch(PDO::FETCH_ASSOC)) AvailabilityService::blockForBooking($pdo, 'house', $h['house_id'], $h['check_in_date'], $h['check_out_date'], $c['reference'], $userId ?: null);
                } elseif ($c['type'] === 'tour') {
                    $st = $pdo->prepare('SELECT tour_id, booking_date FROM tour_bookings WHERE id = ?');
                    $st->execute([$c['id']]);
                    if ($t = $st->fetch(PDO::FETCH_ASSOC)) AvailabilityService::blockForBooking($pdo, 'tour', $t['tour_id'], $t['booking_date'], null, $c['reference'], $userId ?: null);
                }
            }
        }
    }

    /**
     * Confirm the reservation fee (after checking the guest's proof).
     * Returns ['status' => 'recorded'|'already', 'amount' => fee, 'payment_status' => ...,
     *          'components' => [ ['type'=>..,'id'=>..], ... ] ]
     */
    public static function confirmReservation(PDO $pdo, $type, $id, $userId, $method = 'gcash', $notes = null) {
        // $method / $notes are optional: the default (gcash + "from payment proof") is the
        // online flow. Walk-in cash/GCash at the counter passes its own method and note.
        if (!isset(self::METHODS[$method])) throw new InvalidArgumentException('Unknown payment method.');
        if ($notes === null || $notes === '') $notes = 'Reservation fee confirmed from payment proof';
        $notes = mb_substr((string)$notes, 0, 255);
        $table = self::tableFor($type);
        $pdo->beginTransaction();
        try {
            $row = self::lockRow($pdo, $type, $id);
            if (self::isComponent($row)) throw new RuntimeException('This item is part of a package. Confirm the payment on the package.');
            if (self::isLegacyRebook($row)) throw new RuntimeException('This is an old rebooking record; payments belong to the original booking.');
            if (($row['booking_status'] ?? '') === 'cancelled') throw new RuntimeException('This booking is cancelled.');

            $a = self::amounts($row);
            if ($row['payment_status'] !== 'pending' || $a['paid'] > 0) {
                $pdo->rollBack();
                return ['status' => 'already', 'amount' => 0, 'payment_status' => $row['payment_status'], 'components' => []];
            }
            $fee = self::feeFor($a['total']);
            if ($fee <= 0) throw new RuntimeException('This booking has no amount to pay.');
            $newStatus = ($fee >= $a['total']) ? 'paid' : 'reservation_paid';

            // Re-check the dates while the house/tour rows are locked. Overlapping
            // pending reservations may exist; only the first one confirmed gets the
            // dates. On conflict nothing is recorded (no ledger row, no amount, no status).
            self::assertDatesFree($pdo, $type, $row);

            try {
                self::insertLedger($pdo, $type, $row, 'reservation_fee', $fee, $method, $userId, date('Y-m-d H:i:s'), $notes);
            } catch (PDOException $e) {
                if (self::isDuplicateKey($e)) { $pdo->rollBack(); return ['status' => 'already', 'amount' => 0, 'payment_status' => $row['payment_status'], 'components' => []]; }
                throw $e;
            }

            $now = date('Y-m-d H:i:s');
            $paidAtSql = ($type === 'package') ? '' : ', paid_at = :now2';
            $balanceSql = ($newStatus === 'paid') ? ', balance_paid_at = NULL' : '';
            $sql = "UPDATE `$table` SET payment_status = :st, reservation_fee_amount = :fee,
                        amount_paid = amount_paid + :fee2, reservation_paid_at = :now $paidAtSql $balanceSql,
                        booking_status = CASE WHEN booking_status IN ('pending','') THEN 'confirmed' ELSE booking_status END
                    WHERE id = :id AND payment_status = 'pending'";
            $params = [':st' => $newStatus, ':fee' => $fee, ':fee2' => $fee, ':now' => $now, ':id' => (int)$row['id']];
            if ($paidAtSql !== '') $params[':now2'] = $now;
            $up = $pdo->prepare($sql);
            $up->execute($params);
            if ($up->rowCount() !== 1) throw new RuntimeException('Payment state changed while confirming. Please refresh and try again.');

            $components = ($type === 'package') ? self::syncPackageComponents($pdo, (int)$row['id']) : [];
            self::blockConfirmedDates($pdo, $type, $row, $components, $userId);
            $pdo->commit();
            return ['status' => 'recorded', 'amount' => $fee, 'payment_status' => $newStatus, 'components' => $components];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Record the remaining balance received on arrival.
     * $receivedAt: 'Y-m-d H:i' or null (now). Must not be in the future or
     * before the reservation fee was confirmed.
     */
    public static function recordBalance(PDO $pdo, $type, $id, $userId, $method, $receivedAt = null, $notes = null) {
        $table = self::tableFor($type);
        if (!isset(self::METHODS[$method])) throw new InvalidArgumentException('Please choose how the balance was paid.');
        $now = new DateTime('now');
        if ($receivedAt === null || $receivedAt === '') {
            $when = $now;
        } else {
            $when = DateTime::createFromFormat('Y-m-d\TH:i', $receivedAt) ?: DateTime::createFromFormat('Y-m-d H:i', $receivedAt);
            if (!$when) throw new InvalidArgumentException('Invalid payment date.');
            if ($when > (clone $now)->modify('+5 minutes')) throw new InvalidArgumentException('Payment date cannot be in the future.');
        }
        $notes = $notes !== null ? mb_substr(trim((string)$notes), 0, 255) : null;

        $pdo->beginTransaction();
        try {
            $row = self::lockRow($pdo, $type, $id);
            if (self::isComponent($row)) throw new RuntimeException('This item is part of a package. Record the balance on the package.');
            if (self::isLegacyRebook($row)) throw new RuntimeException('This is an old rebooking record.');
            if (($row['booking_status'] ?? '') === 'cancelled') throw new RuntimeException('This booking is cancelled (rebooking required). Record the balance after it is rebooked.');
            $a = self::amounts($row);
            if ($row['payment_status'] === 'paid' || $a['balance'] <= 0) {
                $pdo->rollBack();
                return ['status' => 'already', 'amount' => 0];
            }
            if ($row['payment_status'] !== 'reservation_paid' || $a['paid'] <= 0) {
                throw new RuntimeException('Confirm the reservation fee first.');
            }
            if (!empty($row['reservation_paid_at']) && $when->format('Y-m-d H:i:s') < $row['reservation_paid_at']) {
                throw new InvalidArgumentException('Balance date cannot be before the reservation fee was confirmed (' . $row['reservation_paid_at'] . ').');
            }
            $balance = $a['balance'];
            $at = $when->format('Y-m-d H:i:s');
            try {
                self::insertLedger($pdo, $type, $row, 'balance', $balance, $method, $userId, $at, $notes);
            } catch (PDOException $e) {
                if (self::isDuplicateKey($e)) { $pdo->rollBack(); return ['status' => 'already', 'amount' => 0]; }
                throw $e;
            }
            $up = $pdo->prepare("UPDATE `$table` SET amount_paid = amount_paid + ?, balance_paid_at = ?, payment_status = 'paid'
                                  WHERE id = ? AND payment_status = 'reservation_paid'");
            $up->execute([$balance, $at, (int)$row['id']]);
            if ($up->rowCount() !== 1) throw new RuntimeException('Payment state changed while saving. Please refresh and try again.');
            if ($type === 'package') self::syncPackageComponents($pdo, (int)$row['id']);
            $pdo->commit();
            return ['status' => 'recorded', 'amount' => $balance, 'received_at' => $at];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mirror the package payment state onto its components; confirm pending
     * components when the package is confirmed; cancel them with the package.
     * Returns the component list [['type','id','reference']] for date blocking.
     * Must be called inside a transaction or on its own.
     */
    public static function syncPackageComponents(PDO $pdo, $packageId) {
        $st = $pdo->prepare("SELECT * FROM package_bookings WHERE id = ?");
        $st->execute([(int)$packageId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return [];
        $out = [];
        foreach (['house' => 'house_booking_id', 'tour' => 'tour_booking_id', 'food' => 'food_booking_id'] as $type => $col) {
            if (empty($p[$col])) continue;
            $table = self::TABLES[$type];
            $bookingSql = '';
            if ($p['booking_status'] === 'cancelled') {
                $bookingSql = ", booking_status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW())";
            } elseif ($p['booking_status'] === 'confirmed') {
                $bookingSql = ", booking_status = CASE WHEN booking_status IN ('pending','') THEN 'confirmed' ELSE booking_status END";
            }
            $pdo->prepare("UPDATE `$table` SET payment_status = ?, amount_paid = 0, reservation_fee_amount = 0 $bookingSql
                           WHERE id = ? AND package_id = ?")
                ->execute([$p['payment_status'], (int)$p[$col], (int)$p['id']]);
            $r = $pdo->prepare("SELECT id, reference_number FROM `$table` WHERE id = ?");
            $r->execute([(int)$p[$col]]);
            if ($c = $r->fetch(PDO::FETCH_ASSOC)) $out[] = ['type' => $type, 'id' => (int)$c['id'], 'reference' => $c['reference_number']];
        }
        return $out;
    }

    /**
     * Cancel a booking. Unpaid -> normal cancellation. Paid -> the payment is
     * kept as a rebooking credit (non-refundable). Package -> components too.
     * Returns ['credit' => amount kept, 'reference' => ...].
     */
    public static function cancelBooking(PDO $pdo, $type, $id, $reason, $guestId = null) {
        $table = self::tableFor($type);
        $pdo->beginTransaction();
        try {
            $row = self::lockRow($pdo, $type, $id);
            if ($guestId !== null && (int)$row['guest_id'] !== (int)$guestId) throw new RuntimeException('Booking not found or does not belong to you.');
            if (self::isComponent($row)) throw new RuntimeException('This item is part of a package. Cancel the whole package instead.');
            if ($row['booking_status'] === 'cancelled') throw new RuntimeException('This booking is already cancelled.');
            if ($row['booking_status'] === 'completed') throw new RuntimeException('Completed bookings cannot be cancelled.');
            if ($guestId !== null) {
                // Guest cancellation only before a payment proof is sent and before the booking is confirmed.
                if (!empty($row['payment_proof'])) throw new RuntimeException("Cancellation is unavailable after payment proof submission. Please wait for verification.");
                if ($row['booking_status'] === 'confirmed') throw new RuntimeException('Confirmed bookings cannot be cancelled. You may rebook your reservation subject to availability and the rebooking rules.');
            }
            $a = self::amounts($row);
            $extra = ($type === 'house') ? ', rebooked_at = NULL, rebook_confirmed_at = NULL' : '';
            $reasonSql = ", cancellation_reason = ?";
            $pdo->prepare("UPDATE `$table` SET booking_status = 'cancelled', cancelled_at = NOW() $reasonSql $extra WHERE id = ?")
                ->execute([mb_substr((string)$reason, 0, 2000), (int)$row['id']]);
            if ($type === 'package') self::syncPackageComponents($pdo, (int)$row['id']);
            $pdo->commit();
            return ['credit' => $a['paid'], 'reference' => $row['reference_number']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Reasons an administrator can pick when cancelling a booking (validated on the server). */
    const ADMIN_CANCEL_REASONS = [
        'Guest requested cancellation', 'Accidental booking', 'Payment issue', 'Schedule conflict',
        'Duplicate booking', 'Suspicious activity', 'Other',
    ];

    /**
     * Who cancelled? Read from the existing audit log (system_logs, action 'cancel_booking') — no new column.
     * Returns [bookingId => 'Full Name'] for the ids that have a log entry (newest entry wins).
     * Automatic "Expired" cancellations have no entry and are labelled by the caller.
     */
    public static function cancellationActors(PDO $pdo, $type, array $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !in_array($type, ['house', 'tour', 'food', 'package'], true)) return [];
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT target_id, fullname, username FROM system_logs
                                  WHERE action = 'cancel_booking' AND target_type = ? AND target_id IN ($ph)
                                  ORDER BY id DESC");
            $st->execute(array_merge([$type . '_booking'], $ids));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tid = (int)$r['target_id'];
                if (!isset($out[$tid])) $out[$tid] = trim((string)($r['fullname'] ?: $r['username'])) ?: 'Administrator';
            }
            return $out;
        } catch (PDOException $e) {
            error_log('[PaymentService] cancellationActors: ' . $e->getMessage());
            return [];
        }
    }

    /** Release auto-blocked dates for one booking reference (exact match, so a
     *  package reference never releases its parts' dates by prefix). */
    public static function releaseBlockedDates(PDO $pdo, $type, $itemId, $reference) {
        if (!in_array($type, ['house', 'tour', 'food'], true) || empty($itemId)) return;
        try {
            AvailabilityService::releaseForBooking($pdo, $type, $itemId, $reference);
        } catch (PDOException $e) {
            error_log('[PaymentService] release blocked dates failed: ' . $e->getMessage());
        }
    }

    const EXPIRED_REASON = 'Expired: the reservation fee was not received before the service date.';

    /**
     * Past-date housekeeping (run by Booking Management):
     *  - a SECURED booking (reservation fee received) whose date has passed
     *    becomes 'completed' (payment status is never changed here, so a balance
     *    still due stays visible under Outstanding Balances);
     *  - a booking with NO money and NO payment proof whose date has passed
     *    becomes 'cancelled' with an "Expired" reason — it was never secured,
     *    so it is not a completed sale. Bookings with an unverified proof stay
     *    pending so staff can still check the payment.
     *  - package parts follow their package.
     * completed_at is set whenever a booking becomes completed.
     */
    public static function autoCompleteSql() {
        $r = "'" . self::EXPIRED_REASON . "'";
        $noProof = "(payment_proof IS NULL OR payment_proof = '')";
        $standalone = "(package_id IS NULL OR package_id = 0)";
        $sql = [];
        foreach ([
            ['house_bookings', 'check_out_date < CURDATE()', ' AND original_booking_id IS NULL'],
            ['tour_bookings',  'booking_date < CURDATE()', ''],
            ['food_bookings',  'preferred_date IS NOT NULL AND preferred_date < CURDATE()', ''],
        ] as $d) {
            list($t, $past, $extra) = $d;
            $sql[] = "UPDATE $t SET booking_status = 'completed', completed_at = COALESCE(completed_at, NOW())
                       WHERE $past AND booking_status IN ('pending','confirmed') AND $standalone AND amount_paid > 0$extra";
            $sql[] = "UPDATE $t SET booking_status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()), cancellation_reason = $r
                       WHERE $past AND booking_status IN ('pending','confirmed') AND $standalone
                         AND payment_status = 'pending' AND amount_paid = 0 AND $noProof$extra";
        }
        // Packages: all parts' dates passed
        $pkgPast = "(hb.id IS NULL OR hb.check_out_date < CURDATE())
                AND (tb.id IS NULL OR tb.booking_date < CURDATE())
                AND (fb.id IS NULL OR fb.preferred_date IS NULL OR fb.preferred_date < CURDATE())
                AND (hb.id IS NOT NULL OR tb.id IS NOT NULL OR fb.id IS NOT NULL)";
        $pkgJoin = "package_bookings p
                LEFT JOIN house_bookings hb ON p.house_booking_id = hb.id
                LEFT JOIN tour_bookings tb ON p.tour_booking_id = tb.id
                LEFT JOIN food_bookings fb ON p.food_booking_id = fb.id";
        $sql[] = "UPDATE $pkgJoin SET p.booking_status = 'completed', p.completed_at = COALESCE(p.completed_at, NOW())
                   WHERE p.booking_status IN ('pending','confirmed') AND p.amount_paid > 0 AND $pkgPast";
        $sql[] = "UPDATE $pkgJoin SET p.booking_status = 'cancelled', p.cancelled_at = COALESCE(p.cancelled_at, NOW()), p.cancellation_reason = $r
                   WHERE p.booking_status IN ('pending','confirmed') AND p.payment_status = 'pending' AND p.amount_paid = 0
                     AND (p.payment_proof IS NULL OR p.payment_proof = '') AND $pkgPast";
        // Parts follow the package
        foreach (['house_bookings' => 'house_booking_id', 'tour_bookings' => 'tour_booking_id', 'food_bookings' => 'food_booking_id'] as $t => $col) {
            $sql[] = "UPDATE $t c JOIN package_bookings p ON p.id = c.package_id AND p.$col = c.id
                         SET c.booking_status = 'completed', c.completed_at = COALESCE(c.completed_at, p.completed_at, NOW())
                       WHERE p.booking_status = 'completed' AND c.booking_status IN ('pending','confirmed')";
            $sql[] = "UPDATE $t c JOIN package_bookings p ON p.id = c.package_id AND p.$col = c.id
                         SET c.booking_status = 'cancelled', c.cancelled_at = COALESCE(c.cancelled_at, p.cancelled_at, NOW())
                       WHERE p.booking_status = 'cancelled' AND c.booking_status IN ('pending','confirmed')";
        }
        return $sql;
    }
}

<?php
/**
 * ============================================================================
 * ReportExporter — Sales Analytics & Reports data layer (READ-ONLY)
 * ============================================================================
 * Used only by reports.php. Every query in this file is a SELECT; nothing here
 * writes to the database.
 *
 * Requires migration 2026_10 (payment ledger + amount columns). If it has not
 * been applied, the report shows a clear message instead of wrong figures.
 *
 * WHAT IS ONE TRANSACTION
 * ----------------------------------------------------------------------------
 *   - a standalone house / tour / food booking (package_id IS NULL OR 0)
 *   - one package_bookings row (its house/tour/food rows are COMPONENTS and are
 *     never counted again; they carry no money of their own)
 *   - old rebooking copies (house_bookings.original_booking_id IS NOT NULL,
 *     reference "RE-...") are EXCLUDED from sales: the money belongs to the
 *     original booking.
 *
 * MONEY MODEL (payment ledger = booking_payments)
 * ----------------------------------------------------------------------------
 *   Cash Collected            = SUM(booking_payments.amount) with received_at in
 *                               the period. Split into Reservation Fees and
 *                               Balance Payments. This is real money received.
 *   Confirmed Booking Value   = SUM(total) of bookings CREATED in the period,
 *                               not cancelled, with the reservation fee received.
 *   Remaining Balance         = SUM(MAX(total - amount_paid, 0)) of the same set.
 *   Fully Paid Bookings       = same set with nothing left to pay.
 *   Awaiting Reservation Fee  = created in the period, not cancelled, nothing paid.
 *   Rebooking Credits Held    = AS OF TODAY: money paid on cancelled bookings
 *                               (no refunds; kept for rebooking) plus any excess
 *                               paid over a lower rebooked total.
 *   Outstanding Balances      = AS OF TODAY: active/completed bookings whose fee
 *                               was received but the balance is still unpaid.
 *
 *   Example: A 12,000 fee paid, B 6,000 fee paid, C 11,000 unpaid
 *            -> Cash 2,000 · Booking Value 18,000 · Balance 16,000 · C awaiting fee.
 *
 * DATES
 *   Booking activity (value, status mix, cancellations) -> created_at.
 *   Cash -> booking_payments.received_at. Guests served -> service date.
 *   Times are compared as stored; the database session uses Philippine time
 *   (+08:00). Ledger rows created by the migration keep the time the old server
 *   stored (see the data-quality notice).
 *
 * All money is summed in integer centavos so KPI, trend, tables, CSV and print
 * agree to the centavo.
 * ============================================================================
 */

require_once 'database.php';
require_once __DIR__ . '/PaymentService.php';

/** Exception whose message is safe to show to an administrator. */
class ReportException extends Exception {}

class ReportExporter {

    const MAX_RANGE_DAYS = 1096;          // ~3 years
    const PREVIEW_ROW_LIMIT = 5000;       // preview payload cap (CSV is never capped)
    const REPORT_TZ = 'Asia/Manila';

    const SERVICES = ['all', 'house', 'tour', 'food', 'package'];
    // Combined payment states (PaymentService::state). 'cancelled' covers unpaid cancellations and rejected payments.
    const PAYMENTS = ['all', 'unpaid', 'reservation_paid', 'paid', 'rebook_required', 'cancelled'];
    const PAYMENT_ALIASES = ['pending' => 'unpaid'];   // links saved before the update
    const STATUSES = ['all', 'pending', 'confirmed', 'completed', 'cancelled'];
    const REPORT_TYPES = ['bookings', 'revenue', 'balances', 'credits', 'users', 'houses', 'tours', 'food', 'packages', 'activities', 'feedback'];

    const TYPE_LABELS = ['house' => 'House', 'tour' => 'Tour', 'food' => 'Food', 'package' => 'Package'];
    const PAYMENT_LABELS = [
        'all' => 'All', 'unpaid' => 'Awaiting reservation fee', 'reservation_paid' => 'Reservation fee paid',
        'paid' => 'Fully paid', 'rebook_required' => 'Rebooking required (credit)', 'cancelled' => 'Cancelled / rejected (unpaid)',
    ];

    private $pdo;
    private $memo = [];

    public function __construct($pdoInstance = null) {
        global $pdo;
        $this->pdo = $pdoInstance ?: $pdo;
        if (!$this->pdo instanceof PDO) {
            throw new RuntimeException('Database connection is not available.');
        }
    }

    // =========================================================================
    // FILTER VALIDATION
    // =========================================================================

    /**
     * Validate request filters. Throws ReportException with a safe message.
     * Returns a normalised array used by every method in this class.
     */
    public static function normalizeFilters(array $src) {
        $tz = new DateTimeZone(self::REPORT_TZ);
        $today = new DateTime('now', $tz);

        $fromRaw = isset($src['date_from']) ? trim((string)$src['date_from']) : '';
        $toRaw   = isset($src['date_to'])   ? trim((string)$src['date_to'])   : '';

        if ($fromRaw === '' && $toRaw === '') {
            $fromRaw = $today->format('Y-m-01');
            $toRaw   = $today->format('Y-m-t');
        }
        $from = self::parseDate($fromRaw, '"From"');
        $to   = self::parseDate($toRaw, '"To"');

        if ($from > $to) {
            throw new ReportException('The "From" date must be on or before the "To" date.');
        }
        $days = (int)$from->diff($to)->days + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            throw new ReportException('The selected range is too large. Please choose a range of 3 years or less.');
        }

        $pick = function ($key, array $allowed, $label, array $aliases = []) use ($src) {
            $v = isset($src[$key]) ? strtolower(trim((string)$src[$key])) : 'all';
            if ($v === '') $v = 'all';
            if (isset($aliases[$v])) $v = $aliases[$v];
            if (!in_array($v, $allowed, true)) {
                throw new ReportException('Invalid ' . $label . ' filter.');
            }
            return $v;
        };

        $q = isset($src['q']) ? (string)$src['q'] : '';
        $q = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $q);
        $q = trim(mb_substr((string)$q, 0, 100));

        return [
            'date_from' => $from->format('Y-m-d'),
            'date_to'   => $to->format('Y-m-d'),
            'days'      => $days,
            'service'   => $pick('service', self::SERVICES, 'service'),
            'payment'   => $pick('payment', self::PAYMENTS, 'payment status', self::PAYMENT_ALIASES),
            'status'    => $pick('status', self::STATUSES, 'booking status'),
            'q'         => $q,
            '_normalized' => true,
        ];
    }

    private static function parseDate($value, $label) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ReportException('Invalid ' . $label . ' date. Please use the date picker (YYYY-MM-DD).');
        }
        $d = DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::REPORT_TZ));
        $errors = DateTime::getLastErrors();
        if (!$d || $d->format('Y-m-d') !== $value || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ReportException('Invalid ' . $label . ' date.');
        }
        $year = (int)$d->format('Y');
        if ($year < 2000 || $year > 2100) {
            throw new ReportException('The ' . $label . ' date must be between the years 2000 and 2100.');
        }
        return $d;
    }

    private function ensureFilters($filters) {
        $this->ensureSchema();
        if (is_array($filters) && !empty($filters['_normalized'])) return $filters;
        return self::normalizeFilters(is_array($filters) ? $filters : []);
    }

    /** Stops with a clear message when the 2026_10 database update is missing. */
    private function ensureSchema() {
        if (isset($this->memo['schema_ok'])) return;
        try {
            $n = (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                          WHERE TABLE_SCHEMA = DATABASE()
                                            AND ((TABLE_NAME = 'house_bookings' AND COLUMN_NAME = 'amount_paid')
                                              OR (TABLE_NAME = 'package_bookings' AND COLUMN_NAME = 'amount_paid')
                                              OR (TABLE_NAME = 'booking_payments' AND COLUMN_NAME = 'received_at'))")->fetchColumn();
        } catch (PDOException $e) {
            error_log('[reports] schema check failed: ' . $e->getMessage());
            $n = 0;
        }
        if ($n < 3) {
            throw new ReportException('Sales reports need the October 2026 database update (payment ledger). Please run migrations/2026_10_payment_rebooking_integrity.sql first.');
        }
        $this->memo['schema_ok'] = true;
    }

    /** Previous period of equal length, immediately before the selected range. */
    private function previousPeriod(array $f) {
        $tz = new DateTimeZone(self::REPORT_TZ);
        $to = new DateTime($f['date_from'], $tz);
        $to->modify('-1 day');
        $from = clone $to;
        $from->modify('-' . ($f['days'] - 1) . ' days');
        $p = $f;
        $p['date_from'] = $from->format('Y-m-d');
        $p['date_to']   = $to->format('Y-m-d');
        return $p;
    }

    // =========================================================================
    // SQL BUILDING BLOCKS
    // =========================================================================

    private static function normStatus($col) {
        return "CASE WHEN $col IS NULL OR $col = '' THEN 'pending' ELSE LOWER($col) END";
    }

    /** One row per customer transaction (standalone bookings + packages; no RE- copies). */
    private function transactionUnionSql() {
        $hbPay = self::normStatus('b.payment_status');
        $hbSt  = self::normStatus('b.booking_status');
        $pPay  = self::normStatus('p.payment_status');
        $pSt   = self::normStatus('p.booking_status');
        $guest = "COALESCE(NULLIF(TRIM(g.full_name), ''), NULLIF(TRIM(u.fullname), ''), 'Unknown guest')";

        return "
        SELECT 'house' AS tx_type, b.id AS tx_id, b.reference_number AS reference,
               COALESCE(h.house_name, CONCAT('House #', b.house_id)) AS item_name,
               b.guest_id AS guest_id, $guest AS guest_name,
               b.created_at AS created_at, b.check_in_date AS service_date, b.check_out_date AS service_end,
               b.number_of_guests AS pax, b.total_amount AS amount,
               b.reservation_fee_amount AS fee, b.amount_paid AS paid_amt,
               b.reservation_paid_at AS res_paid_at, b.balance_paid_at AS bal_paid_at, b.cancelled_at AS cancelled_at,
               $hbPay AS payment_status, $hbSt AS booking_status,
               CASE WHEN b.booking_status IS NULL OR b.booking_status = '' THEN 1 ELSE 0 END AS status_was_blank,
               0 AS comp_house, 0 AS comp_tour, 0 AS comp_food,
               0 AS has_house, 0 AS has_tour, 0 AS has_food,
               COALESCE(b.rebook_count, 0) AS rebook_count
          FROM house_bookings b
          LEFT JOIN houses h ON h.id = b.house_id
          LEFT JOIN guests g ON g.id = b.guest_id
          LEFT JOIN users  u ON u.id = g.user_id
         WHERE (b.package_id IS NULL OR b.package_id = 0) AND b.original_booking_id IS NULL

        UNION ALL
        SELECT 'tour', b.id, b.reference_number,
               COALESCE(t.tour_name, CONCAT('Tour #', b.tour_id)),
               b.guest_id, $guest,
               b.created_at, b.booking_date, NULL,
               b.number_of_guests, b.total_amount,
               b.reservation_fee_amount, b.amount_paid,
               b.reservation_paid_at, b.balance_paid_at, b.cancelled_at,
               $hbPay, $hbSt,
               CASE WHEN b.booking_status IS NULL OR b.booking_status = '' THEN 1 ELSE 0 END,
               0, 0, 0, 0, 0, 0, 0
          FROM tour_bookings b
          LEFT JOIN tours  t ON t.id = b.tour_id
          LEFT JOIN guests g ON g.id = b.guest_id
          LEFT JOIN users  u ON u.id = g.user_id
         WHERE (b.package_id IS NULL OR b.package_id = 0)

        UNION ALL
        SELECT 'food', b.id, b.reference_number,
               COALESCE(f.name, CONCAT('Food #', b.food_id)),
               b.guest_id, $guest,
               b.created_at, b.preferred_date, NULL,
               b.quantity, b.total_amount,
               b.reservation_fee_amount, b.amount_paid,
               b.reservation_paid_at, b.balance_paid_at, b.cancelled_at,
               $hbPay, $hbSt,
               CASE WHEN b.booking_status IS NULL OR b.booking_status = '' THEN 1 ELSE 0 END,
               0, 0, 0, 0, 0, 0, 0
          FROM food_bookings b
          LEFT JOIN food_items f ON f.id = b.food_id
          LEFT JOIN guests g ON g.id = b.guest_id
          LEFT JOIN users  u ON u.id = g.user_id
         WHERE (b.package_id IS NULL OR b.package_id = 0)

        UNION ALL
        SELECT 'package', p.id, p.reference_number,
               COALESCE(NULLIF(CONCAT_WS(' + ', hh.house_name, tt.tour_name, ff.name), ''), '(no items)'),
               p.guest_id, $guest,
               p.created_at, COALESCE(hb.check_in_date, tb.booking_date, fb.preferred_date), hb.check_out_date,
               GREATEST(COALESCE(hb.number_of_guests, 0), COALESCE(tb.number_of_guests, 0)), p.grand_total,
               p.reservation_fee_amount, p.amount_paid,
               p.reservation_paid_at, p.balance_paid_at, p.cancelled_at,
               $pPay, $pSt,
               CASE WHEN p.booking_status IS NULL OR p.booking_status = '' THEN 1 ELSE 0 END,
               p.house_amount, p.tour_amount, p.food_amount,
               CASE WHEN p.house_booking_id IS NOT NULL THEN 1 ELSE 0 END,
               CASE WHEN p.tour_booking_id  IS NOT NULL THEN 1 ELSE 0 END,
               CASE WHEN p.food_booking_id  IS NOT NULL THEN 1 ELSE 0 END,
               0
          FROM package_bookings p
          LEFT JOIN house_bookings hb ON hb.id = p.house_booking_id
          LEFT JOIN houses hh         ON hh.id = hb.house_id
          LEFT JOIN tour_bookings tb  ON tb.id = p.tour_booking_id
          LEFT JOIN tours tt          ON tt.id = tb.tour_id
          LEFT JOIN food_bookings fb  ON fb.id = p.food_booking_id
          LEFT JOIN food_items ff     ON ff.id = fb.food_id
          LEFT JOIN guests g ON g.id = p.guest_id
          LEFT JOIN users  u ON u.id = g.user_id";
    }

    /**
     * One row per SERVICE UNIT (standalone bookings + package components).
     * Used only for service-utilisation analytics; never for overall money.
     * A component takes its package's status and payment progress.
     */
    private function unitUnionSql() {
        $pk = "SELECT p.id, p.created_at, p.grand_total, p.amount_paid,
                      " . self::normStatus('p.payment_status') . " AS payment_status,
                      " . self::normStatus('p.booking_status') . " AS booking_status
                 FROM package_bookings p";
        $parts = [];
        $defs = [
            'house' => ['house_bookings', 'houses', 'house_id', 'house_name', 'b.check_in_date', 'b.number_of_guests', "CONCAT('House #', b.house_id)", 'b.original_booking_id IS NULL'],
            'tour'  => ['tour_bookings', 'tours', 'tour_id', 'tour_name', 'b.booking_date', 'b.number_of_guests', "CONCAT('Tour #', b.tour_id)", '1 = 1'],
            'food'  => ['food_bookings', 'food_items', 'food_id', 'name', 'b.preferred_date', 'b.quantity', "CONCAT('Food #', b.food_id)", '1 = 1'],
        ];
        foreach ($defs as $type => $d) {
            list($table, $itemTable, $fk, $nameCol, $svc, $pax, $fallback, $cond) = $d;
            $pay = self::normStatus('b.payment_status');
            $st  = self::normStatus('b.booking_status');
            $parts[] = "
            SELECT '$type' AS unit_type, b.$fk AS item_id, COALESCE(i.$nameCol, $fallback) AS item_name,
                   CASE WHEN b.package_id IS NOT NULL AND b.package_id <> 0 THEN 1 ELSE 0 END AS is_pkg,
                   CASE WHEN b.package_id IS NOT NULL AND b.package_id <> 0 AND pk.id IS NULL THEN 1 ELSE 0 END AS is_orphan,
                   b.reference_number AS reference, b.guest_id AS guest_id,
                   COALESCE(pk.created_at, b.created_at) AS created_at,
                   $svc AS service_date, $pax AS pax, b.total_amount AS amount,
                   COALESCE(pk.payment_status, $pay) AS payment_status,
                   COALESCE(pk.booking_status, $st)  AS booking_status,
                   CASE WHEN pk.id IS NOT NULL THEN pk.grand_total ELSE b.total_amount END AS st_total,
                   CASE WHEN pk.id IS NOT NULL THEN pk.amount_paid ELSE b.amount_paid END AS st_paid
              FROM $table b
              LEFT JOIN $itemTable i ON i.id = b.$fk
              LEFT JOIN ($pk) pk ON pk.id = b.package_id AND b.package_id <> 0
             WHERE $cond";
        }
        return implode("\n UNION ALL \n", $parts);
    }

    /** Service and booking-status filters (SQL). The payment filter is applied in PHP on the combined state. */
    private function appendFilterSql(array $f, &$where, &$params, $typeCol, $pkgCol = null) {
        if ($f['service'] !== 'all') {
            if ($pkgCol === null) {
                $where[] = "t.$typeCol = :f_service";
                $params[':f_service'] = $f['service'];
            } elseif ($f['service'] === 'package') {
                $where[] = "t.$pkgCol = 1";
            } else {
                $where[] = "t.$typeCol = :f_service AND t.$pkgCol = 0";
                $params[':f_service'] = $f['service'];
            }
        }
        if ($f['status'] !== 'all') {
            $where[] = 't.booking_status = :f_status';
            $params[':f_status'] = $f['status'];
        }
    }

    private static function paymentMatches(array $f, $state) {
        if ($f['payment'] === 'all') return true;
        if ($f['payment'] === 'cancelled') return $state === 'cancelled' || $state === 'rejected';
        return $state === $f['payment'];
    }

    private static function bounds(array $f) {
        return [$f['date_from'] . ' 00:00:00', $f['date_to'] . ' 23:59:59'];
    }

    private static function cents($amount) {
        return (int)round(((float)$amount) * 100);
    }

    private static function cleanDateTime($v) {
        if ($v === null) return null;
        $v = (string)$v;
        if ($v === '' || strpos($v, '0000-00-00') === 0) return null;
        // Normalise to 'Y-m-d H:i:s' / 'Y-m-d' so string comparisons with range bounds are exact
        return strlen($v) > 19 ? substr($v, 0, 19) : $v;
    }

    /** Combined payment state from cents (mirrors PaymentService::state). */
    private static function stateOf($payStatus, $bookingStatus, $totalC, $paidC) {
        if ($bookingStatus === 'cancelled') {
            if ($paidC > 0) return 'rebook_required';
            return $payStatus === 'cancelled' ? 'rejected' : 'cancelled';
        }
        if ($payStatus === 'cancelled') return 'rejected';
        if ($paidC <= 0) return 'unpaid';
        if ($paidC >= $totalC) return 'paid';
        return 'reservation_paid';
    }

    private static function stateLabel($state) {
        return PaymentService::ownerLabel($state)[0];
    }

    /** Adds money fields shared by period rows and position rows. */
    private static function decorateMoney(array &$r) {
        $r['created_at']   = self::cleanDateTime($r['created_at']);
        $r['service_date'] = self::cleanDateTime($r['service_date']);
        $r['service_end']  = self::cleanDateTime($r['service_end']);
        $r['res_paid_at']  = self::cleanDateTime($r['res_paid_at']);
        $r['bal_paid_at']  = self::cleanDateTime($r['bal_paid_at']);
        $r['cancelled_at'] = self::cleanDateTime($r['cancelled_at']);
        $r['cents']    = self::cents($r['amount']);
        $r['fee_c']    = self::cents($r['fee']);
        $r['paid_c']   = self::cents($r['paid_amt']);
        $r['pax']      = (int)$r['pax'];
        $r['guest_id'] = (int)$r['guest_id'];
        $r['is_cancelled'] = ($r['booking_status'] === 'cancelled');
        $r['state']    = self::stateOf($r['payment_status'], $r['booking_status'], $r['cents'], $r['paid_c']);
        $r['secured']  = $r['paid_c'] > 0;
        // Balance only exists on live bookings; a cancelled booking's money is a credit.
        $r['bal_c']    = (!$r['is_cancelled'] && $r['secured']) ? max(0, $r['cents'] - $r['paid_c']) : 0;
        $r['credit_c'] = $r['is_cancelled'] ? $r['paid_c'] : max(0, $r['paid_c'] - $r['cents']);
    }

    // =========================================================================
    // DATASETS
    // =========================================================================

    /**
     * Transactions relevant to the period (created, serviced or with money
     * received in range), with every classification flag computed once and the
     * period's ledger rows attached. Everything else derives from this list,
     * which is what keeps all totals in agreement.
     */
    public function getTransactions(array $f) {
        $key = 'tx|' . $f['date_from'] . '|' . $f['date_to'] . '|' . $f['service'] . '|' . $f['payment'] . '|' . $f['status'];
        if (isset($this->memo[$key])) return $this->memo[$key];

        list($start, $end) = self::bounds($f);
        $params = [':c1' => $start, ':c2' => $end, ':s1' => $f['date_from'], ':s2' => $f['date_to'], ':l1' => $start, ':l2' => $end];
        $where = ['((t.created_at BETWEEN :c1 AND :c2) OR (t.service_date BETWEEN :s1 AND :s2)
                    OR EXISTS (SELECT 1 FROM booking_payments bp WHERE bp.booking_type = t.tx_type AND bp.booking_id = t.tx_id AND bp.received_at BETWEEN :l1 AND :l2))'];
        $this->appendFilterSql($f, $where, $params, 'tx_type');

        $sql = 'SELECT t.* FROM (' . $this->transactionUnionSql() . ') t WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            self::decorateMoney($r);
            if (!self::paymentMatches($f, $r['state'])) continue;
            $r['in_created'] = $r['created_at'] !== null && $r['created_at'] >= $start && $r['created_at'] <= $end;
            $sd = $r['service_date'] !== null ? substr($r['service_date'], 0, 10) : null;
            $r['in_service'] = $sd !== null && $sd >= $f['date_from'] && $sd <= $f['date_to'];
            $r['cohort']     = $r['in_created'] && !$r['is_cancelled'];
            $r['payments']   = [];        // ledger rows received in the period
            $r['cash_c'] = 0; $r['fee_cash_c'] = 0; $r['bal_cash_c'] = 0;
            $r['undated_c']  = 0;          // ledger rows without a receipt date
            $r['migration_cash'] = false;
            $rows[$r['tx_type'] . '|' . $r['tx_id']] = $r;
        }

        if ($rows) {
            // Ledger rows in the period, plus undated ones (shown in Data Quality)
            $stmt = $this->pdo->prepare("SELECT booking_type, booking_id, payment_type, amount, received_at, payment_method, source
                                           FROM booking_payments
                                          WHERE received_at BETWEEN ? AND ? OR received_at IS NULL");
            $stmt->execute([$start, $end]);
            while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $k = $p['booking_type'] . '|' . $p['booking_id'];
                if (!isset($rows[$k])) continue;
                $amt = self::cents($p['amount']);
                $p['received_at'] = self::cleanDateTime($p['received_at']);
                if ($p['received_at'] === null) {
                    if ($rows[$k]['in_created']) $rows[$k]['undated_c'] += $amt;
                    continue;
                }
                $p['cents'] = $amt;
                $rows[$k]['payments'][] = $p;
                $rows[$k]['cash_c'] += $amt;
                if ($p['payment_type'] === 'balance') $rows[$k]['bal_cash_c'] += $amt; else $rows[$k]['fee_cash_c'] += $amt;
                if ($p['source'] === 'migration') $rows[$k]['migration_cash'] = true;
            }
        }
        foreach ($rows as &$r) $r['listed'] = $r['in_created'] || $r['cash_c'] > 0;
        unset($r);
        return $this->memo[$key] = array_values($rows);
    }

    /**
     * Money positions AS OF TODAY (not limited to the period): every
     * transaction that has received money. Used for Outstanding Balances and
     * Rebooking Credits. Only the service filter applies.
     */
    public function getPositions(array $f) {
        $key = 'pos|' . $f['service'];
        if (isset($this->memo[$key])) return $this->memo[$key];
        $where = ['t.paid_amt > 0'];
        $params = [];
        if ($f['service'] !== 'all') { $where[] = 't.tx_type = :f_service'; $params[':f_service'] = $f['service']; }
        $stmt = $this->pdo->prepare('SELECT t.* FROM (' . $this->transactionUnionSql() . ') t WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            self::decorateMoney($r);
            $rows[] = $r;
        }
        return $this->memo[$key] = $rows;
    }

    /** Service units (standalone + package components) for utilisation analytics. */
    public function getUnits(array $f) {
        $key = 'units|' . $f['date_from'] . '|' . $f['date_to'] . '|' . $f['service'] . '|' . $f['payment'] . '|' . $f['status'];
        if (isset($this->memo[$key])) return $this->memo[$key];

        list($start, $end) = self::bounds($f);
        $params = [':c1' => $start, ':c2' => $end];
        $where = ['t.created_at BETWEEN :c1 AND :c2'];
        $this->appendFilterSql($f, $where, $params, 'unit_type', 'is_pkg');

        $sql = 'SELECT t.* FROM (' . $this->unitUnionSql() . ') t WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $r['created_at'] = self::cleanDateTime($r['created_at']);
            $r['cents'] = self::cents($r['amount']);
            $r['pax']   = (int)$r['pax'];
            $cancelled = ($r['booking_status'] === 'cancelled');
            $state = self::stateOf($r['payment_status'], $r['booking_status'], self::cents($r['st_total']), self::cents($r['st_paid']));
            if (!self::paymentMatches($f, $state)) continue;
            $r['booked']        = !$cancelled;
            $r['cancelled_new'] = $cancelled;
            $r['secured']       = !$cancelled && self::cents($r['st_paid']) > 0;
            $rows[] = $r;
        }
        return $this->memo[$key] = $rows;
    }

    // =========================================================================
    // ANALYTICS
    // =========================================================================

    private function computeKpis(array $rows) {
        $k = [
            'cash_c' => 0, 'fee_cash_c' => 0, 'bal_cash_c' => 0, 'payments' => 0, 'fee_payments' => 0, 'bal_payments' => 0,
            'value_c' => 0, 'secured_count' => 0, 'balance_c' => 0, 'balance_count' => 0,
            'fully_paid_count' => 0, 'fully_paid_c' => 0,
            'reservations' => 0, 'reservations_total' => 0, 'cancelled_count' => 0,
            'pending_count' => 0, 'pending_c' => 0, 'pending_past_service' => 0,
            'pax_served' => 0, 'undated_count' => 0, 'undated_c' => 0,
        ];
        $today = (new DateTime('now', new DateTimeZone(self::REPORT_TZ)))->format('Y-m-d');
        foreach ($rows as $r) {
            if ($r['cash_c'] > 0) {
                $k['cash_c'] += $r['cash_c'];
                $k['fee_cash_c'] += $r['fee_cash_c'];
                $k['bal_cash_c'] += $r['bal_cash_c'];
                foreach ($r['payments'] as $p) {
                    $k['payments']++;
                    if ($p['payment_type'] === 'balance') $k['bal_payments']++; else $k['fee_payments']++;
                }
            }
            if ($r['in_created']) {
                $k['reservations_total']++;
                if ($r['is_cancelled']) $k['cancelled_count']++; else $k['reservations']++;
            }
            if ($r['cohort'] && $r['secured']) {
                $k['value_c'] += $r['cents'];
                $k['secured_count']++;
                $k['balance_c'] += $r['bal_c'];
                if ($r['bal_c'] > 0) $k['balance_count']++;
                if ($r['state'] === 'paid') { $k['fully_paid_count']++; $k['fully_paid_c'] += $r['cents']; }
            }
            if ($r['cohort'] && $r['state'] === 'unpaid') {
                $k['pending_count']++;
                $k['pending_c'] += $r['cents'];
                $sd = $r['service_date'] ? substr($r['service_date'], 0, 10) : null;
                if ($r['booking_status'] === 'completed' || ($sd !== null && $sd < $today)) $k['pending_past_service']++;
            }
            if ($r['in_service'] && !$r['is_cancelled'] && $r['tx_type'] !== 'food') $k['pax_served'] += $r['pax'];
            if ($r['undated_c'] > 0) { $k['undated_count']++; $k['undated_c'] += $r['undated_c']; }
        }
        return [
            'cash_collected'       => $k['cash_c'] / 100,
            'fees_collected'       => $k['fee_cash_c'] / 100,
            'balances_collected'   => $k['bal_cash_c'] / 100,
            'payments_count'       => $k['payments'],
            'fee_payments'         => $k['fee_payments'],
            'balance_payments'     => $k['bal_payments'],
            'booking_value'        => $k['value_c'] / 100,
            'secured_count'        => $k['secured_count'],
            'avg_booking_value'    => $k['secured_count'] > 0 ? round($k['value_c'] / $k['secured_count']) / 100 : null,
            'remaining_balance'    => $k['balance_c'] / 100,
            'balance_count'        => $k['balance_count'],
            'fully_paid_count'     => $k['fully_paid_count'],
            'fully_paid_value'     => $k['fully_paid_c'] / 100,
            'reservations'         => $k['reservations'],
            'reservations_total'   => $k['reservations_total'],
            'cancelled_count'      => $k['cancelled_count'],
            'cancellation_rate'    => $k['reservations_total'] > 0 ? round($k['cancelled_count'] * 100 / $k['reservations_total'], 1) : null,
            'pending_count'        => $k['pending_count'],
            'pending_amount'       => $k['pending_c'] / 100,
            'pending_past_service' => $k['pending_past_service'],
            'pax_served'           => $k['pax_served'],
            'undated_count'        => $k['undated_count'],
            'undated_amount'       => $k['undated_c'] / 100,
        ];
    }

    /** As-of-today balances and credits. */
    private function computePositions(array $positions) {
        $o = ['bal_c' => 0, 'bal_n' => 0, 'bal_completed' => 0, 'cr_c' => 0, 'cr_n' => 0, 'cr_cancelled' => 0, 'cr_excess' => 0];
        foreach ($positions as $r) {
            if ($r['bal_c'] > 0) { $o['bal_c'] += $r['bal_c']; $o['bal_n']++; if ($r['booking_status'] === 'completed') $o['bal_completed']++; }
            if ($r['credit_c'] > 0) { $o['cr_c'] += $r['credit_c']; $o['cr_n']++; if ($r['is_cancelled']) $o['cr_cancelled']++; else $o['cr_excess']++; }
        }
        return [
            'outstanding_amount' => $o['bal_c'] / 100, 'outstanding_count' => $o['bal_n'], 'outstanding_completed' => $o['bal_completed'],
            'credits_amount' => $o['cr_c'] / 100, 'credits_count' => $o['cr_n'], 'credits_cancelled' => $o['cr_cancelled'], 'credits_excess' => $o['cr_excess'],
        ];
    }

    private function countNewGuests(array $f) {
        list($start, $end) = self::bounds($f);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'guest' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$start, $end]);
        return (int)$stmt->fetchColumn();
    }

    private static function pctChange($cur, $prev) {
        if ($cur === null || $prev === null) return null;
        if ((float)$prev == 0.0) return null;          // never show fake % without a base
        return round((($cur - $prev) / abs($prev)) * 100, 1);
    }

    /** Cash trend: the same ledger rows, bucketed by date received. */
    private function buildTrend(array $f, array $rows) {
        $tz = new DateTimeZone(self::REPORT_TZ);
        $from = new DateTime($f['date_from'], $tz);
        $to   = new DateTime($f['date_to'], $tz);
        $gran = $f['days'] <= 31 ? 'day' : ($f['days'] <= 180 ? 'week' : 'month');

        $bucketOf = function (DateTime $d) use ($gran) {
            if ($gran === 'day') return $d->format('Y-m-d');
            if ($gran === 'week') { $m = clone $d; $m->modify('-' . ((int)$m->format('N') - 1) . ' days'); return $m->format('Y-m-d'); }
            return $d->format('Y-m');
        };

        $buckets = [];
        $cursor = clone $from;
        while ($cursor <= $to) {
            $k = $bucketOf($cursor);
            if (!isset($buckets[$k])) {
                if ($gran === 'day') $label = $cursor->format('M j');
                elseif ($gran === 'week') $label = 'Wk of ' . $cursor->format('M j');
                else $label = $cursor->format('M Y');
                $buckets[$k] = ['label' => $label, 'house' => 0, 'tour' => 0, 'food' => 0, 'package' => 0];
            }
            $cursor->modify('+1 day');
        }
        foreach ($rows as $r) {
            foreach ($r['payments'] as $p) {
                $k = $bucketOf(new DateTime(substr($p['received_at'], 0, 10), $tz));
                if (isset($buckets[$k])) $buckets[$k][$r['tx_type']] += $p['cents'];
            }
        }
        $out = ['granularity' => $gran, 'labels' => [], 'series' => ['house' => [], 'tour' => [], 'food' => [], 'package' => []], 'total' => []];
        $sum = 0;
        foreach ($buckets as $b) {
            $out['labels'][] = $b['label'];
            $t = 0;
            foreach (['house', 'tour', 'food', 'package'] as $s) { $out['series'][$s][] = $b[$s] / 100; $t += $b[$s]; }
            $out['total'][] = $t / 100;
            $sum += $t;
        }
        $out['sum'] = $sum / 100;
        return $out;
    }

    /** Cash collected by transaction type (each package once). */
    private function buildMix(array $rows) {
        $mix = ['house' => [0, 0], 'tour' => [0, 0], 'food' => [0, 0], 'package' => [0, 0]];
        $total = 0;
        foreach ($rows as $r) {
            if ($r['cash_c'] <= 0) continue;
            $mix[$r['tx_type']][0] += $r['cash_c'];
            $mix[$r['tx_type']][1]++;
            $total += $r['cash_c'];
        }
        $items = [];
        foreach ($mix as $type => $v) {
            $items[] = ['key' => $type, 'label' => self::TYPE_LABELS[$type], 'amount' => $v[0] / 100, 'count' => $v[1],
                        'pct' => $total > 0 ? round($v[0] * 100 / $total, 1) : 0];
        }
        return ['items' => $items, 'total' => $total / 100];
    }

    private function buildStatusMix(array $rows) {
        $b = ['pending' => [0, 0], 'confirmed' => [0, 0], 'completed' => [0, 0], 'cancelled' => [0, 0]];
        $p = ['unpaid' => [0, 0], 'reservation_paid' => [0, 0], 'paid' => [0, 0], 'rebook_required' => [0, 0], 'cancelled' => [0, 0]];
        $n = 0;
        foreach ($rows as $r) {
            if (!$r['in_created']) continue;
            $n++;
            $bs = isset($b[$r['booking_status']]) ? $r['booking_status'] : 'pending';
            $b[$bs][0]++; $b[$bs][1] += $r['cents'];
            $ps = ($r['state'] === 'rejected') ? 'cancelled' : $r['state'];
            if (!isset($p[$ps])) $ps = 'unpaid';
            $p[$ps][0]++; $p[$ps][1] += $r['cents'];
        }
        $labels = ['unpaid' => 'Awaiting Fee', 'reservation_paid' => 'Fee Paid', 'paid' => 'Fully Paid',
                   'rebook_required' => 'Rebook Credit', 'cancelled' => 'Cancelled'];
        $fmt = function ($arr) use ($n, $labels) {
            $o = [];
            foreach ($arr as $k => $v) $o[] = ['key' => $k, 'label' => $labels[$k] ?? ucfirst($k), 'count' => $v[0], 'amount' => $v[1] / 100, 'pct' => $n > 0 ? round($v[0] * 100 / $n, 1) : 0];
            return $o;
        };
        return ['total' => $n, 'booking' => $fmt($b), 'payment' => $fmt($p)];
    }

    /** Aggregate service units per item (secured value = reservation fee received). */
    private function aggregateUnits(array $units, $type) {
        $agg = [];
        foreach ($units as $u) {
            if ($u['unit_type'] !== $type) continue;
            $id = (int)$u['item_id'];
            if (!isset($agg[$id])) $agg[$id] = ['item_id' => $id, 'name' => $u['item_name'], 'bookings' => 0, 'pkg_bookings' => 0, 'cancelled' => 0, 'paid' => 0, 'rev_c' => 0, 'pax' => 0];
            $a = &$agg[$id];
            if ($u['booked']) { $a['bookings']++; $a['pax'] += $u['pax']; if ($u['is_pkg']) $a['pkg_bookings']++; }
            if ($u['cancelled_new']) $a['cancelled']++;
            if ($u['secured']) { $a['paid']++; $a['rev_c'] += $u['cents']; }
            unset($a);
        }
        return $agg;
    }

    private function topList(array $agg, $limit = 5) {
        $list = array_values(array_filter($agg, function ($a) { return $a['bookings'] > 0 || $a['paid'] > 0; }));
        usort($list, function ($x, $y) {
            if ($x['rev_c'] !== $y['rev_c']) return $y['rev_c'] <=> $x['rev_c'];
            if ($x['bookings'] !== $y['bookings']) return $y['bookings'] <=> $x['bookings'];
            return strcmp($x['name'], $y['name']);
        });
        $out = [];
        foreach (array_slice($list, 0, $limit) as $a) {
            $out[] = ['name' => $a['name'], 'bookings' => $a['bookings'], 'pkg_bookings' => $a['pkg_bookings'], 'paid' => $a['paid'],
                      'revenue' => $a['rev_c'] / 100, 'avg' => $a['paid'] > 0 ? round($a['rev_c'] / $a['paid']) / 100 : null, 'pax' => $a['pax']];
        }
        return $out;
    }

    private static function compositionLabel(array $r) {
        $p = [];
        if ((int)$r['has_house']) $p[] = 'House';
        if ((int)$r['has_tour'])  $p[] = 'Tour';
        if ((int)$r['has_food'])  $p[] = 'Food';
        return $p ? implode(' + ', $p) : '(no items)';
    }

    private function aggregatePackages(array $rows) {
        $agg = [];
        foreach ($rows as $r) {
            if ($r['tx_type'] !== 'package') continue;
            $k = self::compositionLabel($r);
            if (!isset($agg[$k])) $agg[$k] = ['name' => $k, 'created' => 0, 'cancelled' => 0, 'paid' => 0, 'rev_c' => 0, 'cash_c' => 0, 'h_c' => 0, 't_c' => 0, 'f_c' => 0];
            if ($r['in_created']) { $agg[$k]['created']++; if ($r['is_cancelled']) $agg[$k]['cancelled']++; }
            if ($r['cohort'] && $r['secured']) {
                $agg[$k]['paid']++; $agg[$k]['rev_c'] += $r['cents'];
                $agg[$k]['h_c'] += self::cents($r['comp_house']);
                $agg[$k]['t_c'] += self::cents($r['comp_tour']);
                $agg[$k]['f_c'] += self::cents($r['comp_food']);
            }
            $agg[$k]['cash_c'] += $r['cash_c'];
        }
        uasort($agg, function ($x, $y) { return [$y['rev_c'], $y['created']] <=> [$x['rev_c'], $x['created']]; });
        return $agg;
    }

    /** guest_ids that paid money (dated) before the period. */
    private function priorPayingGuests(array $f) {
        $sql = 'SELECT DISTINCT t.guest_id FROM (' . $this->transactionUnionSql() . ") t
                  JOIN booking_payments bp ON bp.booking_type = t.tx_type AND bp.booking_id = t.tx_id
                 WHERE bp.received_at IS NOT NULL AND bp.received_at < :before";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':before' => $f['date_from'] . ' 00:00:00']);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
        return $ids;
    }

    private function customerAggregates(array $rows) {
        $c = [];
        foreach ($rows as $r) {
            $g = $r['guest_id'];
            if (!isset($c[$g])) $c[$g] = ['guest_id' => $g, 'name' => $r['guest_name'], 'reservations' => 0, 'secured' => 0, 'paid' => 0, 'rev_c' => 0, 'last_paid' => null];
            if ($r['cohort']) { $c[$g]['reservations']++; if ($r['secured']) $c[$g]['secured']++; }
            if ($r['cash_c'] > 0) {
                $c[$g]['paid'] += count($r['payments']);
                $c[$g]['rev_c'] += $r['cash_c'];
                foreach ($r['payments'] as $p) {
                    if ($c[$g]['last_paid'] === null || $p['received_at'] > $c[$g]['last_paid']) $c[$g]['last_paid'] = $p['received_at'];
                }
            }
        }
        return $c;
    }

    private function buildCustomers(array $f, array $rows) {
        $agg = $this->customerAggregates($rows);
        $paying = array_filter($agg, function ($a) { return $a['rev_c'] > 0; });
        $prior = $this->priorPayingGuests($f);
        $returning = 0;
        foreach ($paying as $g => $a) if (isset($prior[$g])) $returning++;
        uasort($paying, function ($x, $y) { return [$y['rev_c'], $y['paid']] <=> [$x['rev_c'], $x['paid']]; });
        $top = [];
        foreach (array_slice($paying, 0, 5, true) as $g => $a) {
            $top[] = ['name' => $a['name'], 'transactions' => $a['paid'], 'revenue' => $a['rev_c'] / 100,
                      'returning' => isset($prior[$g]), 'last_paid' => $a['last_paid']];
        }
        $unique = count($paying);
        return [
            'new_guests' => $this->countNewGuests($f),
            'unique_paying' => $unique,
            'returning' => $returning,
            'returning_rate' => $unique > 0 ? round($returning * 100 / $unique, 1) : null,
            'top' => $top,
        ];
    }

    private function databaseClockOffsetMinutes() {
        try {
            $m = (int)$this->pdo->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())')->fetchColumn();
            return (int)(round($m / 15) * 15);
        } catch (PDOException $e) {
            error_log('[reports] DB clock check failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function formatOffset($min) {
        $sign = $min < 0 ? '−' : '+';
        $min = abs($min);
        return 'UTC' . $sign . sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    /** Data-quality / owner-attention notices for the selected period. */
    public function getDataQuality(array $f, array $rows = null, array $units = null, array $positions = null) {
        if ($rows === null) $rows = $this->getTransactions($f);
        if ($units === null) $units = $this->getUnits($f);
        if ($positions === null) $positions = $this->getPositions($f);
        $notices = [];
        $item = function ($r, $explain, $cents = null) {
            return ['reference' => $r['reference'], 'type' => self::TYPE_LABELS[$r['tx_type']] ?? ucfirst($r['tx_type']),
                    'amount' => ($cents === null ? $r['cents'] : $cents) / 100, 'status' => ucfirst($r['booking_status']) . ' · ' . self::stateLabel($r['state']), 'note' => $explain];
        };

        // 1. Stays completed while the balance has not been recorded (as of today)
        $list = [];
        foreach ($positions as $r) {
            if ($r['booking_status'] === 'completed' && $r['bal_c'] > 0) $list[] = $item($r, 'Stay/service completed — balance of ' . PaymentService::peso($r['bal_c'] / 100) . ' not recorded as received', $r['bal_c']);
        }
        if ($list) {
            $notices[] = ['level' => 'warning', 'code' => 'completed_balance',
                'title' => count($list) . ' completed booking(s) still show a balance due',
                'detail' => 'The reservation fee was received but the balance on arrival was never recorded. If the guest paid, use "Mark Balance as Paid" in Booking Management with the real date. Amounts shown are the balance due. Not limited to the selected period.',
                'items' => $list];
        }

        // 2. Completed without any payment (period)
        $list = [];
        foreach ($rows as $r) if ($r['in_created'] && $r['booking_status'] === 'completed' && $r['state'] === 'unpaid') $list[] = $item($r, 'Marked completed but no payment was ever recorded');
        if ($list) {
            $notices[] = ['level' => 'warning', 'code' => 'completed_unpaid',
                'title' => count($list) . ' completed booking(s) have no payment recorded',
                'detail' => 'Before the October 2026 update, past bookings were auto-completed by date even when unpaid (now they expire instead). The report does not assume they were paid; they are not in Cash Collected or Booking Value.',
                'items' => $list];
        }

        // 3. Payments without a receipt date
        $list = [];
        foreach ($rows as $r) if ($r['undated_c'] > 0) $list[] = $item($r, 'Payment recorded without a date — not placed in any period', $r['undated_c']);
        if ($list) {
            $notices[] = ['level' => 'warning', 'code' => 'undated_payment',
                'title' => count($list) . ' payment(s) have no date received',
                'detail' => 'These amounts are counted in Amount Paid and Booking Value, but cannot appear in Cash Collected because no date was recorded. No date has been assumed.',
                'items' => $list];
        }

        // 4. Unpaid bookings past their service date
        $today = (new DateTime('now', new DateTimeZone(self::REPORT_TZ)))->format('Y-m-d');
        $list = [];
        foreach ($rows as $r) {
            if (!($r['cohort'] && $r['state'] === 'unpaid') || $r['booking_status'] === 'completed') continue;
            $sd = $r['service_date'] ? substr($r['service_date'], 0, 10) : null;
            if ($sd !== null && $sd < $today) $list[] = $item($r, 'Service date passed while the reservation fee is still unpaid');
        }
        if ($list) {
            $notices[] = ['level' => 'info', 'code' => 'unpaid_past',
                'title' => count($list) . ' unpaid booking(s) are past their service date',
                'detail' => 'They remain under Awaiting Reservation Fee and are not counted as money received.',
                'items' => $list];
        }

        // 5. Package / component consistency (package row is authoritative)
        $pkgIds = [];
        foreach ($rows as $r) if ($r['tx_type'] === 'package' && $r['listed']) $pkgIds[] = (int)$r['tx_id'];
        if ($pkgIds) {
            $in = implode(',', array_fill(0, count($pkgIds), '?'));
            $sql = "SELECT p.reference_number, p.grand_total,
                           " . self::normStatus('p.booking_status') . " AS p_status, " . self::normStatus('p.payment_status') . " AS p_pay,
                           " . self::normStatus('hb.booking_status') . " AS h_status, " . self::normStatus('hb.payment_status') . " AS h_pay, hb.id AS h_id, hb.amount_paid AS h_amt,
                           " . self::normStatus('tb.booking_status') . " AS t_status, " . self::normStatus('tb.payment_status') . " AS t_pay, tb.id AS t_id, tb.amount_paid AS t_amt,
                           " . self::normStatus('fb.booking_status') . " AS f_status, " . self::normStatus('fb.payment_status') . " AS f_pay, fb.id AS f_id, fb.amount_paid AS f_amt
                      FROM package_bookings p
                      LEFT JOIN house_bookings hb ON hb.id = p.house_booking_id
                      LEFT JOIN tour_bookings  tb ON tb.id = p.tour_booking_id
                      LEFT JOIN food_bookings  fb ON fb.id = p.food_booking_id
                     WHERE p.id IN ($in)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($pkgIds);
            $list = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $issues = [];
                foreach (['h' => 'house', 't' => 'tour', 'f' => 'food'] as $px => $lbl) {
                    if (empty($p[$px . '_id'])) continue;
                    if ($p['p_status'] === 'cancelled' && $p[$px . '_status'] !== 'cancelled') $issues[] = "$lbl part is " . $p[$px . '_status'];
                    if ($p['p_status'] !== 'cancelled' && $p[$px . '_status'] === 'cancelled') $issues[] = "$lbl part is cancelled";
                    if ($p[$px . '_pay'] !== $p['p_pay']) $issues[] = "$lbl part payment is " . $p[$px . '_pay'];
                    if ((float)$p[$px . '_amt'] != 0.0) $issues[] = "$lbl part carries its own payment amount";
                }
                if ($issues) {
                    $list[] = ['reference' => $p['reference_number'], 'type' => 'Package', 'amount' => self::cents($p['grand_total']) / 100,
                               'status' => 'Package: ' . ucfirst($p['p_status']) . ' / ' . ucfirst(str_replace('_', ' ', $p['p_pay'])), 'note' => ucfirst(implode('; ', $issues))];
                }
            }
            if ($list) {
                $notices[] = ['level' => 'info', 'code' => 'package_desync',
                    'title' => count($list) . ' package(s) have parts whose status differs from the package',
                    'detail' => 'The package record is used for all money. Confirming or cancelling the package again in Booking Management re-syncs its parts.',
                    'items' => $list];
            }
        }

        // 6. Orphan package components
        $orphans = [];
        foreach ($units as $u) if ((int)$u['is_orphan']) $orphans[$u['reference']] = ['reference' => $u['reference'], 'type' => ucfirst($u['unit_type']), 'amount' => $u['cents'] / 100, 'status' => ucfirst($u['booking_status']), 'note' => 'Has a package_id but the package record was not found; not counted'];
        if ($orphans) {
            $notices[] = ['level' => 'warning', 'code' => 'orphan_components',
                'title' => count($orphans) . ' package part(s) point to a missing package',
                'detail' => 'These rows are excluded from transactions and money totals because their package record does not exist.',
                'items' => array_values($orphans)];
        }

        // 7. Old rebooking copies (RE-) — excluded from sales
        try {
            $legacy = $this->pdo->query("SELECT reference_number, total_amount, " . self::normStatus('booking_status') . " AS st
                                           FROM house_bookings WHERE original_booking_id IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $legacy = []; error_log('[reports] legacy check failed: ' . $e->getMessage()); }
        if ($legacy && in_array($f['service'], ['all', 'house'], true)) {
            $list = [];
            foreach ($legacy as $l) $list[] = ['reference' => $l['reference_number'], 'type' => 'House', 'amount' => self::cents($l['total_amount']) / 100, 'status' => ucfirst($l['st']), 'note' => 'Old-style rebooking copy — excluded from all sales figures'];
            $notices[] = ['level' => 'info', 'code' => 'legacy_rebook',
                'title' => count($list) . ' old rebooking record(s) excluded from sales',
                'detail' => 'Before the October 2026 update a rebooking could create a separate "RE-" record. Its money belongs to the original booking, so these copies are never counted.',
                'items' => $list];
        }

        // 8. Owner review list left by the migration
        try {
            $hasReview = (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_2026_10_owner_review'")->fetchColumn();
            if ($hasReview) {
                $rv = $this->pdo->query("SELECT category, booking_type, reference_number, total_amount, details
                                           FROM migration_2026_10_owner_review WHERE resolved_at IS NULL ORDER BY category, id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
                if ($rv) {
                    $list = [];
                    foreach ($rv as $x) $list[] = ['reference' => $x['reference_number'] ?: '—', 'type' => ucfirst((string)$x['booking_type']) ?: '—',
                                                   'amount' => self::cents($x['total_amount']) / 100, 'status' => ucwords(str_replace('_', ' ', $x['category'])), 'note' => $x['details']];
                    $notices[] = ['level' => 'warning', 'code' => 'owner_review',
                        'title' => count($list) . ' record(s) from the October 2026 update need owner review',
                        'detail' => 'These could not be corrected automatically without guessing money or dates. After checking each one, mark it resolved (resolved_at) in the migration_2026_10_owner_review table.',
                        'items' => $list];
                }
            }
        } catch (PDOException $e) { error_log('[reports] owner review check failed: ' . $e->getMessage()); }

        // 9. Blank statuses (should not exist after the update)
        $blank = 0;
        foreach ($rows as $r) if ($r['listed'] && (int)$r['status_was_blank']) $blank++;
        if ($blank) {
            $notices[] = ['level' => 'info', 'code' => 'blank_status',
                'title' => $blank . ' transaction(s) have an empty booking status — shown as Pending',
                'detail' => 'Run the validation script (migrations/2026_10_validation.sql); after the October 2026 update this should be 0.',
                'items' => []];
        }

        // 10. Time notes
        $migrationCash = false;
        foreach ($rows as $r) if ($r['migration_cash']) { $migrationCash = true; break; }
        if ($migrationCash) {
            $notices[] = ['level' => 'system', 'code' => 'historical_time',
                'title' => 'Some payments in this period were recorded before the October 2026 update',
                'detail' => 'Those dates are shown exactly as the old server stored them (server clock, about 12 hours behind Philippine time), so a payment made near midnight may appear on the neighbouring day. Payments recorded after the update use Philippine time.',
                'items' => []];
        }
        $offset = $this->databaseClockOffsetMinutes();
        if ($offset !== null && $offset !== 480) {
            $notices[] = ['level' => 'system', 'code' => 'timezone',
                'title' => 'Database session is ' . self::formatOffset($offset) . ', not Philippine time (UTC+08:00)',
                'detail' => 'database.php should set the session time zone to +08:00. Until it does, new booking and payment times are stored in server time.',
                'items' => []];
        }

        return $notices;
    }

    /** Everything the dashboard needs, in one response. */
    public function getAnalytics($filters) {
        $f = $this->ensureFilters($filters);
        $rows  = $this->getTransactions($f);
        $units = $this->getUnits($f);
        $positions = $this->getPositions($f);

        $kpis = $this->computeKpis($rows);
        $kpis['new_guests'] = $this->countNewGuests($f);
        $kpis += $this->computePositions($positions);

        $pf = $this->previousPeriod($f);
        $prev = $this->computeKpis($this->getTransactions($pf));
        $prev['new_guests'] = $this->countNewGuests($pf);
        $changes = [];
        foreach (['cash_collected', 'fees_collected', 'booking_value', 'remaining_balance', 'fully_paid_count', 'reservations', 'pax_served', 'new_guests', 'pending_amount'] as $k) {
            $changes[$k] = self::pctChange($kpis[$k], $prev[$k]);
        }
        $changes['cancellation_rate_pts'] = ($kpis['cancellation_rate'] !== null && $prev['cancellation_rate'] !== null)
            ? round($kpis['cancellation_rate'] - $prev['cancellation_rate'], 1) : null;

        $trend = $this->buildTrend($f, $rows);
        $mix = $this->buildMix($rows);

        $packages = [];
        foreach ($this->aggregatePackages($rows) as $p) {
            if ($p['created'] === 0 && $p['paid'] === 0 && $p['cash_c'] === 0) continue;
            $packages[] = ['name' => $p['name'], 'bookings' => $p['created'] - $p['cancelled'], 'cancelled' => $p['cancelled'], 'paid' => $p['paid'],
                           'revenue' => $p['rev_c'] / 100, 'cash' => $p['cash_c'] / 100, 'avg' => $p['paid'] > 0 ? round($p['rev_c'] / $p['paid']) / 100 : null];
        }

        $activityRows = 0;
        try { $activityRows = (int)$this->pdo->query('SELECT COUNT(*) FROM activity_bookings')->fetchColumn(); }
        catch (PDOException $e) { error_log('[reports] activity_bookings check failed: ' . $e->getMessage()); }

        return [
            'filters'  => $this->publicFilters($f),
            'previous' => ['date_from' => $pf['date_from'], 'date_to' => $pf['date_to']],
            'kpis'     => $kpis,
            'prev_kpis'=> $prev,
            'changes'  => $changes,
            'trend'    => $trend,
            'mix'      => $mix,
            'status'   => $this->buildStatusMix($rows),
            'performance' => [
                'houses'   => $this->topList($this->aggregateUnits($units, 'house')),
                'tours'    => $this->topList($this->aggregateUnits($units, 'tour')),
                'food'     => $this->topList($this->aggregateUnits($units, 'food')),
                'packages' => array_slice($packages, 0, 5),
            ],
            'customers' => $this->buildCustomers($f, $rows),
            'quality'   => $this->getDataQuality($f, $rows, $units, $positions),
            'activity_booking_rows' => $activityRows,
            'reconciliation' => [
                'kpi_cash'   => $kpis['cash_collected'],
                'trend_sum'  => $trend['sum'],
                'mix_total'  => $mix['total'],
                'fees_plus_balances' => round($kpis['fees_collected'] + $kpis['balances_collected'], 2),
                'consistent' => (self::cents($kpis['cash_collected']) === self::cents($trend['sum'])
                                 && self::cents($trend['sum']) === self::cents($mix['total'])
                                 && self::cents($kpis['cash_collected']) === self::cents($kpis['fees_collected']) + self::cents($kpis['balances_collected'])),
            ],
        ];
    }

    public function publicFilters(array $f) {
        return ['date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'days' => $f['days'],
                'service' => $f['service'], 'payment' => $f['payment'], 'status' => $f['status']];
    }

    public static function filterSummary(array $f) {
        $svc = ['all' => 'All services', 'house' => 'House', 'tour' => 'Tour', 'food' => 'Food', 'package' => 'Package'];
        $st  = ['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        return 'Service: ' . $svc[$f['service']] . ' | Payment: ' . self::PAYMENT_LABELS[$f['payment']] . ' | Booking status: ' . $st[$f['status']];
    }

    // =========================================================================
    // REPORT TABLES (shared by preview, CSV and print)
    // =========================================================================

    /**
     * Returns: title, headers, types (text|money|int|date|datetime|status|pct),
     * rows (raw values), sum (column indexes summed in the totals row),
     * totals, notes.
     */
    public function getReportTable($type, $filters) {
        $f = $this->ensureFilters($filters);
        switch ($type) {
            case 'bookings':   $t = $this->tableTransactions($f); break;
            case 'revenue':    $t = $this->tableRevenue($f); break;
            case 'balances':   $t = $this->tableBalances($f); break;
            case 'credits':    $t = $this->tableCredits($f); break;
            case 'users':      $t = $this->tableCustomers($f); break;
            case 'houses':     $t = $this->tableItems($f, 'house'); break;
            case 'tours':      $t = $this->tableItems($f, 'tour'); break;
            case 'food':       $t = $this->tableItems($f, 'food'); break;
            case 'packages':   $t = $this->tablePackages($f); break;
            case 'activities': $t = $this->tableActivities(); break;
            case 'feedback':   $t = $this->tableFeedback($f); break;
            default: throw new ReportException('Unknown report type.');
        }
        if (!isset($t['notes'])) $t['notes'] = [];
        $t['type'] = $type;
        $t['totals'] = self::computeTotals($t);
        return $t;
    }

    public static function computeTotals(array $t) {
        if (empty($t['sum'])) return null;
        $tot = array_fill(0, count($t['headers']), null);
        $tot[0] = 'TOTAL (' . count($t['rows']) . (count($t['rows']) === 1 ? ' row)' : ' rows)');
        foreach ($t['sum'] as $i) {
            $acc = 0;
            foreach ($t['rows'] as $row) {
                $v = $row[$i];
                if ($v === null || $v === '') continue;
                $acc += ($t['types'][$i] === 'money') ? self::cents($v) : (int)$v;
            }
            $tot[$i] = ($t['types'][$i] === 'money') ? $acc / 100 : $acc;
        }
        return $tot;
    }

    /** Case-insensitive "any cell contains" search — mirrors the JS search. */
    public static function applySearch(array $t, $q) {
        $q = trim((string)$q);
        if ($q === '') return $t;
        $needle = mb_strtolower($q);
        $t['rows'] = array_values(array_filter($t['rows'], function ($row) use ($needle) {
            foreach ($row as $cell) {
                if ($cell !== null && mb_strpos(mb_strtolower((string)$cell), $needle) !== false) return true;
            }
            return false;
        }));
        $t['totals'] = self::computeTotals($t);
        $t['search'] = $q;
        return $t;
    }

    private static function serviceText(array $r) {
        $svc = $r['service_date'] ? substr($r['service_date'], 0, 10) : null;
        if ($svc && $r['service_end'] && substr($r['service_end'], 0, 10) !== $svc) $svc .= ' → ' . substr($r['service_end'], 0, 10);
        return $svc;
    }

    private function tableTransactions(array $f) {
        $rows = array_filter($this->getTransactions($f), function ($r) { return $r['listed']; });
        usort($rows, function ($a, $b) {
            $x = $a['created_at'] ?? ''; $y = $b['created_at'] ?? '';
            return strcmp($y, $x);
        });
        $out = [];
        foreach ($rows as $r) {
            if ($r['cash_c'] > 0 && !$r['in_created']) $treat = 'Cash received (booked earlier)';
            elseif ($r['cohort'] && $r['secured']) $treat = $r['bal_c'] > 0 ? 'Booking value · balance due' : 'Booking value · fully paid';
            elseif ($r['cohort']) $treat = 'Awaiting reservation fee';
            elseif ($r['is_cancelled'] && $r['secured']) $treat = 'Cancelled · kept as rebooking credit';
            elseif ($r['is_cancelled']) $treat = 'Cancelled · no money';
            else $treat = '—';
            $out[] = [
                $r['reference'],
                self::TYPE_LABELS[$r['tx_type']],
                $r['item_name'],
                $r['guest_name'],
                $r['created_at'],
                self::serviceText($r),
                $r['pax'],
                $r['cents'] / 100,
                $r['paid_c'] / 100,
                $r['bal_c'] / 100,
                self::stateLabel($r['state']),
                ucfirst($r['booking_status']),
                $r['res_paid_at'],
                $r['bal_paid_at'],
                $r['cash_c'] / 100,
                $treat,
            ];
        }
        return [
            'title' => 'Detailed Transactions',
            'headers' => ['Reference', 'Transaction Type', 'Service / Item', 'Guest', 'Booking Created', 'Service Date', 'Pax / Qty', 'Total (PHP)', 'Amount Paid (PHP)', 'Balance Due (PHP)', 'Payment Status', 'Booking Status', 'Fee Received', 'Balance Received', 'Cash in Period (PHP)', 'Treatment'],
            'types' => ['text', 'text', 'text', 'text', 'datetime', 'text', 'int', 'money', 'money', 'money', 'status', 'status', 'datetime', 'datetime', 'money', 'text'],
            'rows' => $out,
            'sum' => [7, 8, 9, 14],
            'notes' => [
                'Lists transactions created in the period or with money received in the period. Each package is one row; its parts are not listed separately. Old "RE-" rebooking copies are excluded.',
                '"Cash in Period" total equals the Cash Collected KPI. Amount Paid and Balance Due are as of today. Cancelled bookings show no balance — any money paid is a rebooking credit (no refunds).',
            ],
        ];
    }

    private function tableRevenue(array $f) {
        $tz = new DateTimeZone(self::REPORT_TZ);
        $days = [];
        $cursor = new DateTime($f['date_from'], $tz);
        $end = new DateTime($f['date_to'], $tz);
        while ($cursor <= $end) {
            $days[$cursor->format('Y-m-d')] = ['house' => 0, 'tour' => 0, 'food' => 0, 'package' => 0, 'fee' => 0, 'bal' => 0, 'n' => 0];
            $cursor->modify('+1 day');
        }
        foreach ($this->getTransactions($f) as $r) {
            foreach ($r['payments'] as $p) {
                $d = substr($p['received_at'], 0, 10);
                if (!isset($days[$d])) continue;
                $days[$d][$r['tx_type']] += $p['cents'];
                if ($p['payment_type'] === 'balance') $days[$d]['bal'] += $p['cents']; else $days[$d]['fee'] += $p['cents'];
                $days[$d]['n']++;
            }
        }
        $out = [];
        foreach ($days as $d => $v) {
            $total = $v['house'] + $v['tour'] + $v['food'] + $v['package'];
            $out[] = [$d, $v['house'] / 100, $v['tour'] / 100, $v['food'] / 100, $v['package'] / 100, $total / 100, $v['fee'] / 100, $v['bal'] / 100, $v['n']];
        }
        return [
            'title' => 'Daily Cash Collected',
            'headers' => ['Date Received', 'House (PHP)', 'Tour (PHP)', 'Food (PHP)', 'Package (PHP)', 'Daily Total (PHP)', 'Reservation Fees (PHP)', 'Balance Payments (PHP)', 'Payments'],
            'types' => ['date', 'money', 'money', 'money', 'money', 'money', 'money', 'money', 'int'],
            'rows' => $out,
            'sum' => [1, 2, 3, 4, 5, 6, 7, 8],
            'notes' => ['Money actually received, by the date it was received (payment ledger). Each package is counted once in the Package column.',
                        'Daily Total = Reservation Fees + Balance Payments. Payments without a recorded date are listed under Data Quality, not here.'],
        ];
    }

    private function tableBalances(array $f) {
        $out = [];
        foreach ($this->getPositions($f) as $r) {
            if ($r['bal_c'] <= 0) continue;
            $note = $r['booking_status'] === 'completed' ? 'Stay/service completed — balance not recorded' : 'Collect on arrival';
            $out[] = [$r['reference'], self::TYPE_LABELS[$r['tx_type']], $r['item_name'], $r['guest_name'], self::serviceText($r),
                      ucfirst($r['booking_status']), $r['cents'] / 100, $r['paid_c'] / 100, $r['bal_c'] / 100, $r['res_paid_at'], $note];
        }
        usort($out, function ($x, $y) { return strcmp((string)$x[4], (string)$y[4]); });
        return [
            'title' => 'Outstanding Balances',
            'headers' => ['Reference', 'Transaction Type', 'Service / Item', 'Guest', 'Service Date', 'Booking Status', 'Total (PHP)', 'Amount Paid (PHP)', 'Balance Due (PHP)', 'Fee Received', 'Note'],
            'types' => ['text', 'text', 'text', 'text', 'text', 'status', 'money', 'money', 'money', 'datetime', 'text'],
            'rows' => $out,
            'sum' => [6, 7, 8],
            'notes' => ['As of today, for all dates (the date range and status filters do not apply; the service filter does). Bookings whose reservation fee was received but whose balance is still unpaid, including completed stays.',
                        'Record a balance in Booking Management → "Mark Balance as Paid". Unpaid reservations (no fee yet) are not listed here.'],
        ];
    }

    private function tableCredits(array $f) {
        $out = [];
        foreach ($this->getPositions($f) as $r) {
            if ($r['credit_c'] <= 0) continue;
            $why = $r['is_cancelled'] ? 'Cancelled after payment — kept for rebooking (no refund)' : 'Paid more than the rebooked total — excess kept as credit';
            $out[] = [$r['reference'], self::TYPE_LABELS[$r['tx_type']], $r['item_name'], $r['guest_name'], self::serviceText($r),
                      ucfirst($r['booking_status']), $r['cancelled_at'], $r['cents'] / 100, $r['paid_c'] / 100, $r['credit_c'] / 100, $why];
        }
        usort($out, function ($x, $y) { return strcmp((string)$y[6], (string)$x[6]); });
        return [
            'title' => 'Rebooking Credits Held',
            'headers' => ['Reference', 'Transaction Type', 'Service / Item', 'Guest', 'Original Service Date', 'Booking Status', 'Cancelled On', 'Booking Total (PHP)', 'Amount Paid (PHP)', 'Credit Held (PHP)', 'Reason'],
            'types' => ['text', 'text', 'text', 'text', 'text', 'status', 'datetime', 'money', 'money', 'money', 'text'],
            'rows' => $out,
            'sum' => [7, 8, 9],
            'notes' => ['As of today, for all dates (the date range and status filters do not apply). Payments are non-refundable; this money stays with the business and is carried into the guest\'s rebooking.',
                        'Credits are not counted again when the guest rebooks — the same amount is simply carried forward.'],
        ];
    }

    private function tableCustomers(array $f) {
        $rows = $this->getTransactions($f);
        $agg = $this->customerAggregates(array_filter($rows, function ($r) { return $r['listed']; }));
        $prior = $this->priorPayingGuests($f);
        list($start, $end) = self::bounds($f);

        $stmt = $this->pdo->prepare("SELECT u.id AS user_id, u.fullname, u.created_at, g.id AS guest_id, g.full_name
                                       FROM users u LEFT JOIN guests g ON g.user_id = u.id
                                      WHERE u.role = 'guest' AND u.created_at BETWEEN ? AND ?");
        $stmt->execute([$start, $end]);
        $newByGuest = [];
        $newNoGuest = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
            if ($n['guest_id']) $newByGuest[(int)$n['guest_id']] = $n; else $newNoGuest[] = $n;
        }

        $ids = array_unique(array_merge(array_keys($agg), array_keys($newByGuest)));
        $reg = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $this->pdo->prepare("SELECT g.id, u.created_at, u.role FROM guests g LEFT JOIN users u ON u.id = g.user_id WHERE g.id IN ($in)");
            $s->execute(array_values($ids));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $x) $reg[(int)$x['id']] = $x;
        }

        $out = [];
        foreach ($ids as $gid) {
            $a = $agg[$gid] ?? ['name' => trim($newByGuest[$gid]['full_name'] ?? '') ?: ($newByGuest[$gid]['fullname'] ?? 'Unknown guest'), 'reservations' => 0, 'secured' => 0, 'paid' => 0, 'rev_c' => 0, 'last_paid' => null];
            $out[] = [
                $a['name'],
                $reg[$gid]['created_at'] ?? null,
                isset($newByGuest[$gid]) ? 'Yes' : 'No',
                $a['reservations'],
                $a['secured'],
                $a['rev_c'] / 100,
                $a['last_paid'],
                $a['rev_c'] > 0 ? (isset($prior[$gid]) ? 'Returning' : 'First-time') : '—',
            ];
        }
        foreach ($newNoGuest as $n) {
            $out[] = [$n['fullname'] ?: 'Unknown guest', $n['created_at'], 'Yes', 0, 0, 0, null, '—'];
        }
        usort($out, function ($x, $y) { return [$y[5], $y[3], $x[0]] <=> [$x[5], $x[3], $y[0]]; });

        return [
            'title' => 'Customers',
            'headers' => ['Customer', 'Registered', 'New in Period', 'Reservations', 'Fee Paid', 'Cash Collected (PHP)', 'Last Payment', 'Customer Type'],
            'types' => ['text', 'datetime', 'text', 'int', 'int', 'money', 'datetime', 'text'],
            'rows' => $out,
            'sum' => [3, 4, 5],
            'notes' => ['Customers who booked or paid in the period, plus new guest accounts (role = guest). Reservations = created in the period and not cancelled; Fee Paid = of those, how many paid the reservation fee.',
                        'Only business-relevant fields are shown; contact, ID, emergency and health details are intentionally excluded.'],
        ];
    }

    private function tableItems(array $f, $kind) {
        $units = $this->getUnits($f);
        $agg = $this->aggregateUnits($units, $kind);

        if ($kind === 'house') {
            $items = $this->pdo->query('SELECT id, house_name AS name, price_per_night AS price, capacity AS cap, status FROM houses ORDER BY house_name')->fetchAll(PDO::FETCH_ASSOC);
            $title = 'House Performance'; $priceH = 'Rate / Night (PHP)'; $capH = 'Capacity'; $paxH = 'Guests Booked';
        } elseif ($kind === 'tour') {
            $items = $this->pdo->query('SELECT id, tour_name AS name, price_per_boat AS price, max_guests AS cap, status FROM tours ORDER BY tour_name')->fetchAll(PDO::FETCH_ASSOC);
            $title = 'Tour Performance'; $priceH = 'Price / Boat (PHP)'; $capH = 'Max Guests'; $paxH = 'Guests Booked';
        } else {
            $items = $this->pdo->query("SELECT id, name, price, category AS cap, CASE WHEN is_available = 1 THEN 'available' ELSE 'unavailable' END AS status FROM food_items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $title = 'Food Performance'; $priceH = 'Base Price (PHP)'; $capH = 'Category'; $paxH = 'Quantity Ordered';
        }

        $out = [];
        $seen = [];
        foreach ($items as $it) {
            $id = (int)$it['id'];
            $seen[$id] = true;
            $a = $agg[$id] ?? ['bookings' => 0, 'pkg_bookings' => 0, 'cancelled' => 0, 'paid' => 0, 'rev_c' => 0, 'pax' => 0];
            $cap = $kind === 'food' ? (($it['cap'] === null || $it['cap'] === '') ? '—' : ucwords(str_replace('_', ' ', $it['cap']))) : (int)$it['cap'];
            $out[] = [$it['name'], (float)$it['price'], $cap, ucwords(str_replace('_', ' ', (string)$it['status'])),
                      $a['bookings'], $a['pkg_bookings'], $a['cancelled'], $a['paid'], $a['rev_c'] / 100,
                      $a['paid'] > 0 ? round($a['rev_c'] / $a['paid']) / 100 : null, $a['pax']];
        }
        foreach ($agg as $id => $a) {   // bookings whose item record was deleted
            if (isset($seen[$id])) continue;
            $out[] = [$a['name'] . ' (deleted item)', null, '—', '—', $a['bookings'], $a['pkg_bookings'], $a['cancelled'], $a['paid'], $a['rev_c'] / 100,
                      $a['paid'] > 0 ? round($a['rev_c'] / $a['paid']) / 100 : null, $a['pax']];
        }
        usort($out, function ($x, $y) { return [$y[8], $y[4], $x[0]] <=> [$x[8], $x[4], $y[0]]; });

        $label = ucfirst($kind);
        return [
            'title' => $title,
            'headers' => [$label, $priceH, $capH, 'Status', 'Bookings', 'via Package', 'Cancelled', 'Fee Paid', 'Secured Value incl. Package Share (PHP)', 'Avg. Secured Value (PHP)', $paxH],
            'types' => ['text', 'money', $kind === 'food' ? 'text' : 'int', 'text', 'int', 'int', 'int', 'int', 'money', 'money', 'int'],
            'rows' => $out,
            'sum' => [4, 5, 6, 7, 8, 10],
            'notes' => [
                "Service utilisation for bookings created in the selected period. Secured Value = booking value of non-cancelled bookings whose reservation fee was received. It is not cash collected.",
                "Includes the $kind part of package bookings (\"via Package\"), valued at its share of the package. Overall money counts each package once, so this view does not add up with the package totals.",
            ],
        ];
    }

    private function tablePackages(array $f) {
        $out = [];
        foreach ($this->aggregatePackages($this->getTransactions($f)) as $p) {
            $out[] = [$p['name'], $p['created'], $p['cancelled'], $p['paid'], $p['rev_c'] / 100,
                      $p['paid'] > 0 ? round($p['rev_c'] / $p['paid']) / 100 : null, $p['cash_c'] / 100, $p['h_c'] / 100, $p['t_c'] / 100, $p['f_c'] / 100];
        }
        return [
            'title' => 'Package Performance',
            'headers' => ['Package Composition', 'Packages Created', 'Cancelled', 'Fee Paid', 'Booking Value (PHP)', 'Avg. Package Value (PHP)', 'Cash in Period (PHP)', 'House Share (PHP)', 'Tour Share (PHP)', 'Food Share (PHP)'],
            'types' => ['text', 'int', 'int', 'int', 'money', 'money', 'money', 'money', 'money', 'money'],
            'rows' => $out,
            'sum' => [1, 2, 3, 4, 6, 7, 8, 9],
            'notes' => ['Each package is one transaction with one ₱1,000 reservation fee. Booking Value = package total of packages created in the period with the fee received. House/Tour/Food shares split that same value for information only; they are not added again.'],
        ];
    }

    private function tableActivities() {
        $rows = $this->pdo->query('SELECT name, category, price, price_unit, status, is_featured FROM activities ORDER BY category, name')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $a) {
            $out[] = [$a['name'], $a['category'] ?: 'Other', (float)$a['price'], $a['price_unit'] ?: '—', ucfirst((string)$a['status']), $a['is_featured'] ? 'Yes' : 'No'];
        }
        return [
            'title' => 'Activities Catalog',
            'headers' => ['Activity', 'Category', 'Price (PHP)', 'Price Unit', 'Status', 'Featured'],
            'types' => ['text', 'text', 'money', 'text', 'text', 'text'],
            'rows' => $out,
            'sum' => [],
            'notes' => ['Catalog only — the system does not record paid activity bookings, so activity sales are not reported and the date range does not apply.'],
        ];
    }

    private function tableFeedback(array $f) {
        list($start, $end) = self::bounds($f);
        $stmt = $this->pdo->prepare("SELECT o.rating, o.comment, o.created_at, o.is_anonymous, u.fullname, u.username
                                       FROM overall_feedback o LEFT JOIN users u ON u.id = o.user_id
                                      WHERE o.created_at BETWEEN ? AND ? ORDER BY o.created_at DESC");
        $stmt->execute([$start, $end]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = $r['is_anonymous'] ? 'Anonymous' : (trim((string)$r['fullname']) ?: ($r['username'] ?: 'Unknown'));
            $comment = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$r['comment'])));
            if (mb_strlen($comment) > 300) $comment = mb_substr($comment, 0, 300) . '…';
            $out[] = [$name, (int)$r['rating'], $comment, $r['created_at']];
        }
        return [
            'title' => 'Customer Feedback',
            'headers' => ['Customer', 'Rating (1-5)', 'Comment', 'Submitted'],
            'types' => ['text', 'int', 'text', 'datetime'],
            'rows' => $out,
            'sum' => [],
            'notes' => ['Overall site feedback submitted in the period. Service / payment / booking-status filters do not apply.'],
        ];
    }

    // =========================================================================
    // CSV
    // =========================================================================

    /** Neutralise spreadsheet formula injection in text cells. */
    private static function csvText($v) {
        $s = (string)$v;
        if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) $s = "'" . $s;
        return $s;
    }

    private static function csvCell($v, $type) {
        if ($v === null || $v === '') return '';
        switch ($type) {
            case 'money': return number_format((float)$v, 2, '.', '');
            case 'int':   return is_numeric($v) ? (string)(int)$v : self::csvText($v);
            case 'pct':   return number_format((float)$v, 1, '.', '');
            case 'datetime': return self::csvText(substr((string)$v, 0, 16));
            default: return self::csvText($v);
        }
    }

    /** Stream a report table as CSV. Discards any buffered output first. */
    public function exportReport($type, $filters, $siteName = 'Transient House & Tours') {
        $f = $this->ensureFilters($filters);
        $t = $this->getReportTable($type, $f);
        if ($f['q'] !== '') $t = self::applySearch($t, $f['q']);

        while (ob_get_level() > 0) ob_end_clean();
        $period = $f['date_from'] . '_to_' . $f['date_to'];
        $fname = preg_replace('/[^A-Za-z0-9_\-]/', '_', strtolower(str_replace(' ', '_', $t['title']))) . '_' . $period . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $put = function (array $row) use ($out) { fputcsv($out, $row, ',', '"', ''); };

        $generated = (new DateTime('now', new DateTimeZone(self::REPORT_TZ)))->format('Y-m-d H:i') . ' (Asia/Manila)';
        $put([self::csvText($siteName) . ' — ' . $t['title']]);
        if ($type === 'activities') {
            $put(['Period', 'Not applicable (catalog)']);
        } elseif ($type === 'balances' || $type === 'credits') {
            $put(['Period', 'As of ' . $generated . ' (all dates)']);
            $put(['Filters', self::filterSummary($f)]);
        } else {
            $put(['Period', $f['date_from'] . ' to ' . $f['date_to']]);
            $put(['Filters', self::filterSummary($f)]);
        }
        if ($f['q'] !== '') $put(['Search', self::csvText($f['q'])]);
        $put(['Generated', $generated]);
        $put(['Currency', 'PHP (Philippine Peso)']);
        $put([]);
        $put(array_map([self::class, 'csvText'], $t['headers']));
        foreach ($t['rows'] as $row) {
            $line = [];
            foreach ($row as $i => $v) $line[] = self::csvCell($v, $t['types'][$i]);
            $put($line);
        }
        if ($t['totals'] !== null) {
            $line = [];
            foreach ($t['totals'] as $i => $v) $line[] = $i === 0 ? $v : self::csvCell($v, $t['types'][$i]);
            $put($line);
        }
        if (!empty($t['notes'])) {
            $put([]);
            foreach ($t['notes'] as $n) $put(['Note', self::csvText($n)]);
        }
        fclose($out);
        exit;
    }

    // =========================================================================
    // BACKWARD-COMPATIBLE ENTRY POINTS (same method names as before)
    // =========================================================================

    private function previewRows($type, $filters) { return $this->getReportTable($type, $filters)['rows']; }

    public function exportBookings($filters = [])   { $this->exportReport('bookings', $filters); }
    public function exportRevenue($filters = [])    { $this->exportReport('revenue', $filters); }
    public function exportUsers($filters = [])      { $this->exportReport('users', $filters); }
    public function exportHouses($filters = [])     { $this->exportReport('houses', $filters); }
    public function exportTours($filters = [])      { $this->exportReport('tours', $filters); }
    public function exportFood($filters = [])       { $this->exportReport('food', $filters); }
    public function exportFeedback($filters = [])   { $this->exportReport('feedback', $filters); }
    public function exportActivities($filters = []) { $this->exportReport('activities', $filters); }
    public function exportOverallRating($filters = []) { $this->exportReport('feedback', $filters); }

    public function getBookingsData($filters = [])   { return $this->previewRows('bookings', $filters); }
    public function getRevenueData($filters = [])    { return $this->previewRows('revenue', $filters); }
    public function getUsersData($filters = [])      { return $this->previewRows('users', $filters); }
    public function getHousesData($filters = [])     { return $this->previewRows('houses', $filters); }
    public function getToursData($filters = [])      { return $this->previewRows('tours', $filters); }
    public function getFoodData($filters = [])      { return $this->previewRows('food', $filters); }
    public function getFeedbackData($filters = [])   { return $this->previewRows('feedback', $filters); }
    public function getActivitiesData($filters = []) { return $this->previewRows('activities', $filters); }
    public function getOverallRatingData($filters = []) { return $this->previewRows('feedback', $filters); }
}

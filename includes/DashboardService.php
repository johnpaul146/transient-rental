<?php
/**
 * DashboardService — READ-ONLY queries for the admin/staff dashboard (Phase 8.2 / 8.3).
 *
 * Nothing here writes to the database or touches booking, payment, cancellation
 * or rebooking logic. Definitions deliberately mirror booking-management.php so a
 * dashboard number always matches the list it links to:
 *
 *  - Package component rows (package_id set) are never counted on their own; the
 *    package counts once.
 *  - "Pending"  = payment still pending (house: or a rebook awaiting confirmation),
 *                 booking not cancelled/completed.
 *  - "Paid"     = Reservation Fee Paid or Fully Paid, not cancelled/completed.
 *
 * Staff-safe by construction: no method returns a payment amount, refund/credit
 * figure or cancellation reason, so the same data can be shown to admin and staff.
 */
class DashboardService
{
    private const OWN = '(package_id IS NULL OR package_id = 0)';

    /** Counts that match booking-management.php: total, pending, paid, pending rebooks. */
    public static function bookingCounts(PDO $pdo): array
    {
        $out = [
            'total' => 0, 'pending' => 0, 'paid' => 0, 'pending_rebooks' => 0,
            'by_type' => ['house' => 0, 'tour' => 0, 'food' => 0, 'package' => 0],
        ];
        $tables = ['house' => 'house_bookings', 'tour' => 'tour_bookings', 'food' => 'food_bookings', 'package' => 'package_bookings'];
        foreach ($tables as $type => $table) {
            $own  = $type === 'package' ? '1=1' : self::OWN;
            $live = "booking_status NOT IN ('cancelled','completed')";
            $pend = $type === 'house'
                ? "(payment_status = 'pending' OR (booking_status = 'pending' AND (rebooked_at IS NOT NULL OR rebook_count > 0)))"
                : "payment_status = 'pending'";
            $reb  = $type === 'house'
                ? "SUM(CASE WHEN $live AND ((rebooked_at IS NOT NULL AND rebook_confirmed_at IS NULL) OR (booking_status = 'pending' AND rebook_count > 0)) THEN 1 ELSE 0 END)"
                : '0';
            try {
                $r = $pdo->query("SELECT COUNT(*),
                                         SUM(CASE WHEN $pend AND $live THEN 1 ELSE 0 END),
                                         SUM(CASE WHEN payment_status IN ('reservation_paid','paid') AND $live THEN 1 ELSE 0 END),
                                         $reb
                                    FROM `$table` WHERE $own")->fetch(PDO::FETCH_NUM);
            } catch (PDOException $e) {
                error_log('[DashboardService] bookingCounts ' . $table . ': ' . $e->getMessage());
                if ($type !== 'house') continue;
                try {   // old database without the rebook columns: plain definition, no rebooks
                    $r = $pdo->query("SELECT COUNT(*),
                                             SUM(CASE WHEN payment_status = 'pending' AND $live THEN 1 ELSE 0 END),
                                             SUM(CASE WHEN payment_status IN ('reservation_paid','paid') AND $live THEN 1 ELSE 0 END), 0
                                        FROM `$table` WHERE $own")->fetch(PDO::FETCH_NUM);
                } catch (PDOException $e2) { continue; }
            }
            $out['by_type'][$type] = (int)$r[0];
            $out['total']         += (int)$r[0];
            $out['pending']       += (int)$r[1];
            $out['paid']          += (int)$r[2];
            $out['pending_rebooks'] += (int)$r[3];
        }
        return $out;
    }

    /**
     * Bookings whose first day falls in [$from, $to] (Y-m-d), soonest first.
     * Packages appear as ONE "Package" row on their first day; their component
     * rows are only listed on a different day (e.g. the tour on day 2) so nothing
     * is shown twice. $paymentIn (optional) limits to payment statuses, e.g. ['pending'].
     * Rows: type, ref, d, item, guest, st, pay, pid. Returns at most 200 rows.
     */
    public static function starts(PDO $pdo, string $from, string $to, ?array $paymentIn = null): array
    {
        $pay = function (string $col) use ($paymentIn): string {
            if ($paymentIn === null) return '';
            $allowed = array_values(array_intersect($paymentIn, ['pending', 'reservation_paid', 'paid']));
            if (!$allowed) return ' AND 1 = 0';
            return " AND $col IN ('" . implode("','", $allowed) . "')";
        };
        $live = "IN ('pending','confirmed')";
        $sql = "
            SELECT 'House' AS type, b.reference_number AS ref, b.check_in_date AS d, h.house_name AS item,
                   COALESCE(u.fullname, '') AS guest, b.booking_status AS st, b.payment_status AS pay, b.package_id AS pid
              FROM house_bookings b LEFT JOIN houses h ON h.id = b.house_id LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN users u ON u.id = g.user_id
             WHERE b.check_in_date BETWEEN ? AND ? AND b.booking_status $live" . $pay('b.payment_status') . "
            UNION ALL
            SELECT 'Tour', b.reference_number, b.booking_date, t.tour_name, COALESCE(u.fullname, b.guest_name, ''),
                   b.booking_status, b.payment_status, b.package_id
              FROM tour_bookings b LEFT JOIN tours t ON t.id = b.tour_id LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN users u ON u.id = g.user_id
             WHERE b.booking_date BETWEEN ? AND ? AND b.booking_status $live" . $pay('b.payment_status') . "
            UNION ALL
            SELECT 'Food', b.reference_number, b.preferred_date, f.name, COALESCE(u.fullname, b.guest_name, ''),
                   b.booking_status, b.payment_status, b.package_id
              FROM food_bookings b LEFT JOIN food_items f ON f.id = b.food_id LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN users u ON u.id = g.user_id
             WHERE b.preferred_date BETWEEN ? AND ? AND b.booking_status $live" . $pay('b.payment_status') . "
            UNION ALL
            SELECT 'Package', x.ref, x.d, x.item, x.guest, x.st, x.pay, x.pid FROM (
                SELECT pk.reference_number AS ref, pk.id AS pid,
                       DATE(LEAST(COALESCE(hb.check_in_date, DATE('9999-12-31')), COALESCE(tb.booking_date, DATE('9999-12-31')), COALESCE(fb.preferred_date, DATE('9999-12-31')))) AS d,
                       CONCAT_WS(' + ', h.house_name, t.tour_name, f.name) AS item,
                       COALESCE(u.fullname, '') AS guest, pk.booking_status AS st, pk.payment_status AS pay
                  FROM package_bookings pk
                  LEFT JOIN house_bookings hb ON hb.id = pk.house_booking_id LEFT JOIN houses h ON h.id = hb.house_id
                  LEFT JOIN tour_bookings tb  ON tb.id = pk.tour_booking_id   LEFT JOIN tours t ON t.id = tb.tour_id
                  LEFT JOIN food_bookings fb  ON fb.id = pk.food_booking_id   LEFT JOIN food_items f ON f.id = fb.food_id
                  LEFT JOIN guests g ON g.id = pk.guest_id LEFT JOIN users u ON u.id = g.user_id
                 WHERE pk.booking_status $live" . $pay('pk.payment_status') . "
            ) x WHERE x.d BETWEEN ? AND ?
            ORDER BY d ASC, ref ASC LIMIT 200";
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$from, $to, $from, $to, $from, $to, $from, $to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[DashboardService] starts: ' . $e->getMessage());
            return [];
        }
        // One row per package on its first day: hide that package's component rows on the same day.
        $pkgDay = [];
        foreach ($rows as &$r) {
            $r['d'] = substr((string)$r['d'], 0, 10);
            if ($r['type'] === 'Package') $pkgDay[(int)$r['pid']] = $r['d'];
        }
        unset($r);
        $out = [];
        foreach ($rows as $r) {
            if ($r['type'] !== 'Package' && !empty($r['pid']) && ($pkgDay[(int)$r['pid']] ?? null) === $r['d']) continue;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Recent rebooks, newest first, from the audit log. Shows reference, guest, item type,
     * who rebooked, and the old → new date only — never an amount or a reason.
     * Rows: type, ref, guest, by, old, new, at, search.
     */
    public static function recentRebooks(PDO $pdo, int $days = 14, int $limit = 6): array
    {
        $map = ['house_booking' => ['House', 'house_bookings'], 'tour_booking' => ['Tour', 'tour_bookings'],
                'food_booking' => ['Food', 'food_bookings'], 'package_booking' => ['Package', 'package_bookings']];
        try {
            $st = $pdo->prepare("SELECT target_id, target_type, role, fullname, username, old_values, new_values, created_at
                                   FROM system_logs
                                  WHERE action = 'rebook' AND module = 'booking' AND status = 'success'
                                    AND target_type IN ('house_booking','tour_booking','food_booking','package_booking')
                                    AND created_at >= ?
                                  ORDER BY id DESC LIMIT " . (int)$limit);
            $st->execute([date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
            $logs = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[DashboardService] recentRebooks: ' . $e->getMessage());
            return [];
        }
        $ids = [];
        foreach ($logs as $l) $ids[$l['target_type']][] = (int)$l['target_id'];
        $info = [];
        foreach ($ids as $tt => $list) {
            $table = $map[$tt][1];
            $ph = implode(',', array_fill(0, count($list), '?'));
            try {
                $q = $pdo->prepare("SELECT b.id, b.reference_number, COALESCE(u.fullname, " . ($tt === 'house_booking' || $tt === 'package_booking' ? "''" : "b.guest_name, ''") . ") AS guest
                                      FROM `$table` b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN users u ON u.id = g.user_id
                                     WHERE b.id IN ($ph)");
                $q->execute($list);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $info[$tt][(int)$r['id']] = $r;
            } catch (PDOException $e) {
                error_log('[DashboardService] recentRebooks lookup ' . $table . ': ' . $e->getMessage());
            }
        }
        $out = [];
        foreach ($logs as $l) {
            $r = $info[$l['target_type']][(int)$l['target_id']] ?? null;
            if (!$r) continue;                       // booking row no longer exists
            $o = json_decode((string)$l['old_values'], true) ?: [];
            $n = json_decode((string)$l['new_values'], true) ?: [];
            $by = strtolower((string)$l['role']);
            $by = $by === 'admin' ? 'Admin' : ($by === 'staff' ? 'Staff' : 'Guest');
            $who = $by === 'Guest' ? 'Guest' : $by . ' ' . trim((string)($l['fullname'] ?: $l['username']));
            $out[] = ['type' => $map[$l['target_type']][0], 'ref' => (string)$r['reference_number'], 'guest' => (string)$r['guest'],
                      'by' => trim($who), 'old' => self::dateOrNull($o['old_date'] ?? $o['old_check_in'] ?? null),
                      'new' => self::dateOrNull($n['new_date'] ?? $n['new_check_in'] ?? null), 'at' => (string)$l['created_at']];
        }
        return $out;
    }

    /**
     * Recent cancellations, newest first, read from the booking rows themselves (so automatic
     * expiries and rejected payments show up too). The cancellation reason is NEVER selected —
     * only whether it was an automatic expiry — and no amounts or credit are returned.
     * Rows: type, ref, guest, by, at.
     */
    public static function recentCancellations(PDO $pdo, int $days = 14, int $limit = 6): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $arms = [
            ['House', 'house_bookings', "COALESCE(u.fullname, '')", self::OWN],
            ['Tour', 'tour_bookings', "COALESCE(u.fullname, b.guest_name, '')", self::OWN],
            ['Food', 'food_bookings', "COALESCE(u.fullname, b.guest_name, '')", self::OWN],
            ['Package', 'package_bookings', "COALESCE(u.fullname, '')", '1=1'],
        ];
        $parts = []; $params = [];
        foreach ($arms as [$label, $table, $guest, $own]) {
            $parts[] = "SELECT '$label' AS type, b.id AS bid, b.reference_number AS ref, $guest AS guest, b.cancelled_at AS at,
                               CASE WHEN b.cancellation_reason LIKE 'Expired:%' THEN 1 ELSE 0 END AS auto
                          FROM `$table` b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN users u ON u.id = g.user_id
                         WHERE b.booking_status = 'cancelled' AND b.cancelled_at IS NOT NULL AND b.cancelled_at >= ? AND $own";
            $params[] = $since;
        }
        try {
            $st = $pdo->prepare(implode(' UNION ALL ', $parts) . ' ORDER BY at DESC LIMIT ' . (int)$limit);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[DashboardService] recentCancellations: ' . $e->getMessage());
            return [];
        }
        $byType = [];
        foreach ($rows as $r) $byType[strtolower($r['type'])][] = (int)$r['bid'];
        $actors = [];
        if (class_exists('PaymentService')) {
            foreach ($byType as $type => $ids) $actors[$type] = PaymentService::cancellationActors($pdo, $type, $ids);
        }
        $out = [];
        foreach ($rows as $r) {
            $type = strtolower($r['type']);
            $by = $actors[$type][(int)$r['bid']] ?? ($r['auto'] ? 'System (automatic)' : '');
            $out[] = ['type' => $r['type'], 'ref' => (string)$r['ref'], 'guest' => (string)$r['guest'], 'by' => (string)$by, 'at' => (string)$r['at']];
        }
        return $out;
    }

    /**
     * SQL fragment for the dashboard's raw system-log feed when the viewer is STAFF.
     * Booking log descriptions can carry payment amounts ("... — ₱3,000.00", "reservation fee of ...")
     * or cancellation/rejection reasons, so staff only get the safe rebook-confirmation entries
     * from the booking module. (Rebooks and cancellations have their own staff-safe panel.)
     * Admin keeps the unfiltered feed.
     */
    public static function staffLogFeedFilter(): string
    {
        return "(module <> 'booking' OR action = 'confirm_rebook')";
    }

    private static function dateOrNull($v): ?string
    {
        $v = (string)$v;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}

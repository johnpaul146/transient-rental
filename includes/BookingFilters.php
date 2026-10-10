<?php
/**
 * BookingFilters — READ-ONLY filter helper for booking-management.php (Phase 8.4a).
 *
 * Validates the GET filters (service, arrival-date range, quick filter, search) and turns
 * them into SQL fragments + bound parameters for the four list queries. It never writes to
 * the database and never touches payment, cancellation, rebooking or permission logic.
 *
 * Arrival date per service (approved rule):
 *   house   = check_in_date          tour = booking_date          food = preferred_date
 *   package = earliest of its house check-in / tour date / food date
 *
 * Every value that reaches SQL is whitelisted or validated here and bound as a parameter;
 * only fixed strings built in this class are concatenated.
 */
class BookingFilters
{
    public const SERVICES   = ['all', 'house', 'tour', 'food', 'package'];
    public const DATES      = ['', 'today', 'next7', 'custom'];
    public const QUICK      = ['', 'soon', 'verify', 'rebooked'];
    public const SOON_DAYS  = 3;      // "Starting soon" window
    public const MAX_SPAN   = 366;    // longest custom range, in days
    public const SEARCH_MAX = 100;

    public const SERVICE_LABELS = ['all' => 'All services', 'house' => 'House', 'tour' => 'Tour', 'food' => 'Food', 'package' => 'Package'];
    public const DATE_LABELS    = ['' => 'Any date', 'today' => 'Today', 'next7' => 'Next 7 days', 'custom' => 'Custom range'];
    public const QUICK_LABELS   = ['soon' => 'Starting soon (3 days)', 'verify' => 'Payment verification needed', 'rebooked' => 'Rebooked'];

    /** Calendar RESOURCE filter (read-only, calendar view only): a house unit (houses.id) or a tour boat (tours.id). */
    public const RESOURCE_SERVICES = ['house', 'tour'];
    public const RESOURCE_LABELS   = ['house' => ['field' => 'Unit', 'all' => 'All units'], 'tour' => ['field' => 'Boat', 'all' => 'All boats']];

    private const NONE = '9999-12-31';

    /**
     * Validate the request. Returns:
     *  service, date, from, to (valid Y-m-d or ''), quick, search,
     *  bounds [from|null, to|null] (resolved arrival range), notice (string|''), active (int count).
     */
    public static function parse(array $get, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $str = function (string $k) use ($get): string { return isset($get[$k]) && is_string($get[$k]) ? trim($get[$k]) : ''; };

        $service = $str('service');
        if (!in_array($service, self::SERVICES, true)) $service = 'all';
        $quick = $str('quick');
        if (!in_array($quick, self::QUICK, true)) $quick = '';
        $date = $str('date');
        if (!in_array($date, self::DATES, true)) $date = '';

        $search = $str('search');
        if (function_exists('mb_substr')) $search = mb_substr($search, 0, self::SEARCH_MAX, 'UTF-8');
        else $search = substr($search, 0, self::SEARCH_MAX);
        $search = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $search) ?? '';
        $search = trim($search);

        $resourceRaw = $str('resource');
        $from = self::validDate($str('from'));
        $to   = self::validDate($str('to'));
        $notice = '';
        $bounds = [null, null];

        if ($date === 'today') {
            $bounds = [$today, $today];
        } elseif ($date === 'next7') {
            $bounds = [$today, date('Y-m-d', strtotime($today . ' +7 days'))];
        } elseif ($date === 'custom') {
            if (($str('from') !== '' && $from === '') || ($str('to') !== '' && $to === '')) {
                $notice = 'The date range was not understood, so it was ignored. Use the date pickers.';
                $date = ''; $from = $to = '';
            } elseif ($from === '' && $to === '') {
                $date = '';                                   // custom with no dates = no date filter
            } elseif ($from !== '' && $to !== '' && $from > $to) {
                $notice = 'The "From" date is after the "To" date, so the date range was ignored.';
                $date = ''; $from = $to = '';
            } elseif ($from !== '' && $to !== '' && (strtotime($to) - strtotime($from)) / 86400 > self::MAX_SPAN) {
                $notice = 'The date range is longer than ' . self::MAX_SPAN . ' days, so it was ignored.';
                $date = ''; $from = $to = '';
            } else {
                $bounds = [$from !== '' ? $from : null, $to !== '' ? $to : null];
            }
        }
        if ($date !== 'custom') { $from = $to = ''; }

        $active = ($service !== 'all' ? 1 : 0) + ($date !== '' ? 1 : 0) + ($quick !== '' ? 1 : 0) + ($search !== '' ? 1 : 0);
        return ['service' => $service, 'date' => $date, 'from' => $from, 'to' => $to, 'quick' => $quick,
                'search' => $search, 'bounds' => $bounds, 'notice' => $notice, 'active' => $active, 'today' => $today,
                'resource' => '', 'resource_type' => '', 'resource_id' => 0, 'resource_name' => '', 'resource_raw' => $resourceRaw];
    }

    /**
     * Validate the calendar RESOURCE filter against the real records. The value is typed ("house-5" / "tour-3") so a
     * unit id can never be mistaken for a boat id. $available = ['house' => [id => name], 'tour' => [id => name]]
     * (loaded by the page). Anything that is not a known resource of the CURRENT service falls back to "all" and the
     * page keeps working: malformed / unknown / deleted ids add a short notice; a resource of another service (normal
     * when the Service dropdown is switched without JavaScript) is dropped silently. The resource only narrows the
     * calendar; list queries never use it. Counts as an active filter only in the calendar view.
     */
    public static function resolveResource(array $f, array $available, string $view): array
    {
        $raw = (string)($f['resource_raw'] ?? '');
        $f['resource'] = ''; $f['resource_type'] = ''; $f['resource_id'] = 0; $f['resource_name'] = '';
        if ($raw === '' || !in_array($f['service'], self::RESOURCE_SERVICES, true)) return $f;
        if (!preg_match('/^(house|tour)-([1-9][0-9]{0,8})$/', $raw, $m)) {
            $f['notice'] = $f['notice'] ?: 'The selected unit / boat was not understood, so all are shown.';
            return $f;
        }
        if ($m[1] !== $f['service']) return $f;                      // other service's resource: ignore
        $id = (int)$m[2];
        if (!isset($available[$m[1]][$id])) {
            $f['notice'] = $f['notice'] ?: 'The selected unit / boat was not found, so all are shown.';
            return $f;
        }
        $f['resource'] = $m[1] . '-' . $id; $f['resource_type'] = $m[1]; $f['resource_id'] = $id;
        $f['resource_name'] = (string)$available[$m[1]][$id];
        if ($view === 'calendar') $f['active']++;
        return $f;
    }

    /**
     * SQL to append to a list query's WHERE (starts with " AND ..." or is empty), its bound
     * parameters, and an ORDER BY override (or null to keep the page's existing order).
     * $service is house|tour|food|package. Table aliases: b (house/tour/food) and p (package),
     * with the package query's existing hb / tb / fb joins.
     */
    public static function fragment(string $service, array $f): array
    {
        $where = ''; $params = [];
        if ($f['service'] !== 'all' && $f['service'] !== $service) {
            return ['where' => ' AND 1 = 0', 'params' => [], 'order' => null];   // other service is filtered out: no rows, no scan
        }
        $a = $service === 'package' ? 'p' : 'b';
        $arrival = self::arrivalExpr($service);

        [$lo, $hi] = $f['bounds'];
        $needsArrival = $lo !== null || $hi !== null || $f['quick'] === 'soon';
        if ($needsArrival && $service === 'package') $where .= " AND $arrival < '" . self::NONE . "'";   // a package with no dated part never matches a date filter
        if ($lo !== null) { $where .= " AND $arrival >= :bf_lo"; $params[':bf_lo'] = $lo; }
        if ($hi !== null) { $where .= " AND $arrival <= :bf_hi"; $params[':bf_hi'] = $hi; }

        $order = null;
        if ($f['quick'] === 'soon') {
            $where .= " AND $arrival BETWEEN :bf_soon_lo AND :bf_soon_hi AND $a.booking_status IN ('pending','confirmed')";
            $params[':bf_soon_lo'] = $f['today'];
            $params[':bf_soon_hi'] = date('Y-m-d', strtotime($f['today'] . ' +' . self::SOON_DAYS . ' days'));
            $order = "$arrival ASC, $a.reference_number ASC";
        } elseif ($f['quick'] === 'verify') {
            // Same rule as the dashboard's "Payments to verify": proof uploaded, nothing confirmed yet.
            $where .= " AND $a.payment_status = 'pending' AND $a.booking_status = 'pending' AND $a.payment_proof IS NOT NULL AND $a.payment_proof <> ''";
        } elseif ($f['quick'] === 'rebooked') {
            $where .= " AND ($a.rebook_count > 0 OR $a.rebooked_at IS NOT NULL)";
        }
        return ['where' => $where, 'params' => $params, 'order' => $order];
    }

    /** Arrival-date expression (a DATE) for a service. */
    public static function arrivalExpr(string $service): string
    {
        switch ($service) {
            case 'house': return 'DATE(b.check_in_date)';
            case 'tour':  return 'DATE(b.booking_date)';
            case 'food':  return 'DATE(b.preferred_date)';
            default:
                $n = "DATE('" . self::NONE . "')";
                return "DATE(LEAST(COALESCE(hb.check_in_date, $n), COALESCE(tb.booking_date, $n), COALESCE(fb.preferred_date, $n)))";
        }
    }

    /** "%term%" for LIKE with % _ \ escaped, so a search for "%" or "_" matches only those characters. */
    public static function likeTerm(string $search): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    }

    /** Query string (leading "?") for the current state with overrides applied; defaults are omitted. HTML-escape when echoing. */
    public static function url(array $state, array $override = []): string
    {
        $s = array_merge($state, $override);
        $q = ['status' => $s['status'] ?? 'all', 'view' => $s['view'] ?? 'list'];
        foreach (['search', 'service', 'date', 'from', 'to', 'quick'] as $k) {
            $v = (string)($s[$k] ?? '');
            if ($v === '' || ($k === 'service' && $v === 'all')) continue;
            if (($k === 'from' || $k === 'to') && ($s['date'] ?? '') !== 'custom') continue;
            $q[$k] = $v;
        }
        // calendar resource: only meaningful together with its own service (house-N with service=house, tour-N with service=tour)
        $svc = (string)($s['service'] ?? 'all'); $res = (string)($s['resource'] ?? '');
        if ($res !== '' && in_array($svc, self::RESOURCE_SERVICES, true) && preg_match('/^' . $svc . '-[1-9][0-9]{0,8}$/', $res)) $q['resource'] = $res;
        return '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    private static function validDate(string $v): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return '';
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : '';
    }
}

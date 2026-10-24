<?php
/**
 * GroupHealth — the "senior citizens in the group" + "any guest with a health condition?" details that
 * REPLACE the individual guest-name list for NEW house bookings (online standalone, house inside a package,
 * and staff walk-in).
 *
 * Columns on house_bookings (added by database/migrations/2026_10_20_house_senior_health.sql when missing):
 *   senior_citizens          INT NULL        — NULL = historical booking, never recorded
 *   has_health_condition     TINYINT(1) NULL — NULL = never recorded (NOT converted to "No")
 *   health_condition_details VARCHAR(500) NULL
 *
 * The server is the authority: every flow calls GroupHealth::parse() with the raw POST data.
 * The old house_bookings.guest_names column is kept untouched (historical bookings still show their names).
 */
class GroupHealth {
    const DETAILS_MAX = 500;
    const NOT_RECORDED = 'Not recorded';

    /** True when the three columns exist (so the code also works until the migration has been run). */
    public static function columnsReady(PDO $pdo) {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM house_bookings")->fetchAll(PDO::FETCH_COLUMN, 0);
            $ready = in_array('senior_citizens', $cols, true)
                  && in_array('has_health_condition', $cols, true)
                  && in_array('health_condition_details', $cols, true);
        } catch (Throwable $e) {
            $ready = false;
        }
        if (!$ready) error_log('GroupHealth: house_bookings.senior_citizens / has_health_condition / health_condition_details are missing - run database/migrations/2026_10_20_house_senior_health.sql');
        return $ready;
    }

    /**
     * SELECT-list fragment for the three columns of a house_bookings alias, each aliased with $prefix
     * (e.g. selectSql($pdo, 'hb', 'house_') -> "hb.senior_citizens AS house_senior_citizens, ..."), or NULLs when the
     * migration has not been run yet, so a package/booking query never fails on a missing column.
     */
    public static function selectSql(PDO $pdo, $alias, $prefix) {
        $out = [];
        foreach (['senior_citizens', 'has_health_condition', 'health_condition_details'] as $c) {
            $out[] = (self::columnsReady($pdo) ? $alias . '.' . $c : 'NULL') . ' AS ' . $prefix . $c;
        }
        return implode(', ', $out);
    }

    /**
     * Validate the submitted values.
     * @param array $in      raw POST-like array (senior_citizens, has_health_condition, health_condition_details)
     * @param int   $guests  total number of guests of the booking
     * @return array{senior:int, has_health:int, details:?string}
     * @throws InvalidArgumentException with a guest-readable message
     */
    public static function parse(array $in, $guests) {
        $guests = (int)$guests;
        $rawSenior = isset($in['senior_citizens']) ? trim((string)$in['senior_citizens']) : '';
        if ($rawSenior === '') throw new InvalidArgumentException('Please enter the number of senior citizens in your group (0 if none).');
        if (!preg_match('/^[0-9]{1,3}$/', $rawSenior)) throw new InvalidArgumentException('Senior citizens must be a whole number (0 or more).');
        $senior = (int)$rawSenior;
        if ($senior > $guests) throw new InvalidArgumentException('Senior citizens (' . $senior . ') cannot exceed the total number of guests (' . $guests . ').');

        $rawHealth = isset($in['has_health_condition']) ? strtolower(trim((string)$in['has_health_condition'])) : '';
        if ($rawHealth === 'yes' || $rawHealth === '1') $has = 1;
        elseif ($rawHealth === 'no' || $rawHealth === '0') $has = 0;
        else throw new InvalidArgumentException('Please answer whether any guest has a health condition (Yes or No).');

        $details = null;
        if ($has === 1) {
            $d = trim(preg_replace('/\s+/u', ' ', (string)($in['health_condition_details'] ?? '')));
            if ($d !== '') {
                if (mb_strlen($d) > self::DETAILS_MAX) throw new InvalidArgumentException('Health condition details must not exceed ' . self::DETAILS_MAX . ' characters.');
                $details = $d;
            }
        }
        return ['senior' => $senior, 'has_health' => $has, 'details' => $details];
    }

    /** Save the validated values on an already-inserted house booking. No-op (logged) before the migration is run. */
    public static function save(PDO $pdo, $houseBookingId, array $data) {
        if (!self::columnsReady($pdo)) return false;
        $st = $pdo->prepare("UPDATE house_bookings SET senior_citizens = ?, has_health_condition = ?, health_condition_details = ? WHERE id = ?");
        $st->execute([$data['senior'], $data['has_health'], $data['details'], (int)$houseBookingId]);
        return true;
    }

    /** "3", "0" or "Not recorded" (NULL / missing column). */
    public static function seniorLabel($row) {
        if (!is_array($row) || !array_key_exists('senior_citizens', $row) || $row['senior_citizens'] === null || $row['senior_citizens'] === '') return self::NOT_RECORDED;
        return (string)(int)$row['senior_citizens'];
    }

    /** "No", "Yes", "Yes — details…" or "Not recorded". Plain text (escape on output). */
    public static function healthLabel($row) {
        if (!is_array($row) || !array_key_exists('has_health_condition', $row) || $row['has_health_condition'] === null || $row['has_health_condition'] === '') return self::NOT_RECORDED;
        if ((int)$row['has_health_condition'] !== 1) return 'No';
        $d = trim((string)($row['health_condition_details'] ?? ''));
        return $d === '' ? 'Yes' : 'Yes — ' . $d;
    }
}

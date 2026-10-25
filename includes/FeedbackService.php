<?php
/**
 * FeedbackService (Phase 7.9) — ONE rule for per-booking ratings (House / Food / Package), used by the Rate buttons
 * (profile.php, houses.php) AND by every server-side feedback handler, so a forged POST gets the same answer as the UI.
 *
 * A NEW rating is allowed only when ALL of these hold:
 *   - the booking belongs to the signed-in guest (guests.user_id = session user);
 *   - it is not cancelled and has no feedback yet (an existing rating is changed through Edit);
 *   - the service has really finished, in the application timezone (Asia/Manila, set in database.php):
 *       House: NOW >= check_out_date + check_out_time   (stay over — not before, not during)
 *     and the booking was secured: status 'completed', or 'confirmed' with money received (housekeeping may lag);
 *       Food / Package: status 'completed' (unchanged rule; completion already means the date has passed).
 * EDIT is allowed for the owner when feedback already exists (historical feedback is kept as it is).
 * Storage: the rating is written only when the table has the feedback columns (feedback_rating, feedback_text, feedback_date).
 * If they are missing (production house_bookings / package_bookings as of the Phase 7.8 dump) the Rate button
 * stays hidden and a submit is refused with a friendly message — never an SQL error.
 */
class FeedbackService {

    // Tours/Boat was retired: a rating can no longer be created or edited for a tour booking (old feedback stays in the database).
    const TABLES = ['house' => 'house_bookings', 'food' => 'food_bookings', 'package' => 'package_bookings'];
    const TEXT_MAX = 2000;
    const NOT_AVAILABLE = 'Ratings for this service are not available yet. Thank you for your interest — please leave an overall review instead.';

    private static $ready = [];

    /** Does this booking table have the per-booking feedback columns? (cached per request) */
    public static function storageReady(PDO $pdo, $type) {
        if (!isset(self::TABLES[$type])) return false;
        if (array_key_exists($type, self::$ready)) return self::$ready[$type];
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM `' . self::TABLES[$type] . '`')->fetchAll(PDO::FETCH_COLUMN);
            self::$ready[$type] = !array_diff(['feedback_rating', 'feedback_text', 'feedback_date'], $cols);
        } catch (PDOException $e) {
            self::$ready[$type] = false;
        }
        return self::$ready[$type];
    }

    /** End of the service as 'Y-m-d H:i:s' (House), or null when it cannot be determined. */
    public static function endsAt($type, array $b) {
        if ($type === 'house') {
            $d = substr((string)($b['check_out_date'] ?? ''), 0, 10);
            $t = substr((string)($b['check_out_time'] ?? ''), 0, 8);
            if ($t === '') $t = '12:00:00';
        } else {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        if (strlen($t) === 5) $t .= ':00';
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return null;
        return $d . ' ' . $t;
    }

    public static function hasFeedback(array $b) {
        return trim((string)($b['feedback_text'] ?? '')) !== '' || (isset($b['feedback_rating']) && $b['feedback_rating'] !== '' && (int)$b['feedback_rating'] > 0);
    }

    /** Why a NEW rating is not possible (null = allowed). Pure function of the row and $now; ownership is checked by the caller. */
    public static function rateBlockReason($type, array $b, $now = null) {
        if (!isset(self::TABLES[$type])) return 'Unknown booking type.';
        $status = (string)($b['booking_status'] ?? '');
        if ($status === 'cancelled') return 'Cancelled bookings cannot be rated.';
        if (self::hasFeedback($b)) return 'You already rated this booking. Use Edit to change it.';
        if ($type === 'house') {
            $secured = $status === 'completed' || ($status === 'confirmed' && (float)($b['amount_paid'] ?? 0) > 0);
            if (!$secured) return 'Only confirmed bookings can be rated.';
            $end = self::endsAt($type, $b);
            if ($end === null) return 'This booking cannot be rated.';
            $now = $now ?: date('Y-m-d H:i:s');
            if ($now < $end) {
                return 'You can rate your stay after check-out (' . date('M j, Y g:i A', strtotime($end)) . ').';
            }
            return null;
        }
        return $status === 'completed' ? null : 'You can rate this booking once it is completed.';
    }

    /** UI: show the Rate button? (eligible AND the rating can actually be stored) */
    public static function canRate(PDO $pdo, $type, array $b) {
        return self::storageReady($pdo, $type) && self::rateBlockReason($type, $b) === null;
    }

    /** The booking row, only if it belongs to the signed-in user (guests.user_id). */
    public static function loadOwned(PDO $pdo, $type, $id, $userId) {
        if (!isset(self::TABLES[$type])) return null;
        if (!is_scalar($id) || !preg_match('/^[1-9]\d{0,9}$/', (string)$id)) return null;
        $st = $pdo->prepare('SELECT b.* FROM `' . self::TABLES[$type] . '` b JOIN guests g ON g.id = b.guest_id WHERE b.id = ? AND g.user_id = ?');
        $st->execute([(int)$id, (int)$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Save a rating. $mode 'create' (new) or 'edit' (existing feedback). Returns ['id','rating','anonymous'].
     * Throws InvalidArgumentException with a guest-safe message; nothing is written unless every check passes.
     */
    public static function save(PDO $pdo, $type, $id, $userId, $rating, $text, $anonymous, $mode = 'create') {
        $type = (string)$type;
        if (!isset(self::TABLES[$type])) throw new InvalidArgumentException('Unknown booking type.');
        $r = is_scalar($rating) ? trim((string)$rating) : '';
        if (!preg_match('/^[1-5]$/', $r)) throw new InvalidArgumentException('Please select a rating from 1 to 5 stars.');
        $text = trim((string)$text);
        if (mb_strlen($text, 'UTF-8') > self::TEXT_MAX) throw new InvalidArgumentException('Your feedback is too long (maximum ' . self::TEXT_MAX . ' characters).');
        $b = self::loadOwned($pdo, $type, $id, $userId);
        if (!$b) throw new InvalidArgumentException('Booking not found.');
        if (!self::storageReady($pdo, $type)) throw new InvalidArgumentException(self::NOT_AVAILABLE);
        if ($mode === 'edit') {
            if (!self::hasFeedback($b)) throw new InvalidArgumentException('There is no feedback to edit for this booking.');
        } elseif (($why = self::rateBlockReason($type, $b)) !== null) {
            throw new InvalidArgumentException($why);
        }
        $st = $pdo->prepare('UPDATE `' . self::TABLES[$type] . '` SET feedback_rating = ?, feedback_text = ?, feedback_date = NOW(), feedback_is_anonymous = ? WHERE id = ?');
        $st->execute([(int)$r, $text, $anonymous ? 1 : 0, (int)$b['id']]);
        return ['id' => (int)$b['id'], 'rating' => (int)$r, 'anonymous' => $anonymous ? 1 : 0];
    }
}

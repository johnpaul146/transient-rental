<?php
/**
 * InputRules — ONE shared definition of "valid person name" and "valid PH mobile number"
 * for the walk-in booking form (server = authoritative, the modal embeds the same patterns).
 *
 * Names   : Unicode letters (+ combining marks), spaces, apostrophe (' or ’), hyphen, period.
 *           Must start with a letter, 2–60 chars. Digits, brackets, operators, HTML, and
 *           punctuation-only strings are rejected.
 * Phones  : accepted as typed ONLY if they contain digits plus an optional leading "+" and
 *           harmless spaces/hyphens. Letters/other symbols are REJECTED (never stripped).
 *           Formats: 09XXXXXXXXX · 9XXXXXXXXX · 639XXXXXXXXX · +639XXXXXXXXX
 *           -> normalised to +639XXXXXXXXX (same stored format as the online forms).
 */
class InputRules {
    const NAME_MIN = 2;
    const NAME_MAX = 60;
    const NAME_MESSAGE = 'Name may contain letters, spaces, apostrophes, hyphens, and periods only.';
    const PHONE_MESSAGE = 'Enter a valid contact number.';

    /** PCRE (server) pattern. */
    const NAME_REGEX = "/^[\\p{L}\\p{M}][\\p{L}\\p{M} '’.\\-]*$/u";
    /** The same rule as a JS (u-flag) RegExp source, for the modal. */
    const NAME_REGEX_JS = "^[\\p{L}\\p{M}][\\p{L}\\p{M} '’.\\-]*$";
    /** Characters a phone may be typed with (letters/other symbols are rejected, never stripped). */
    const PHONE_TYPED_REGEX = '/^\\+?[0-9][0-9 \\-]*$/';
    const PHONE_TYPED_REGEX_JS = '^\\+?[0-9][0-9 \\-]*$';

    /** Trim + collapse inner whitespace. */
    public static function cleanName($raw) {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$raw));
    }

    /**
     * @return string|null  null when valid, otherwise a short message (no label prefix).
     */
    public static function nameError($raw, $required = true) {
        $name = self::cleanName($raw);
        if ($name === '') return $required ? 'Name is required.' : null;
        if (mb_strlen($name) < self::NAME_MIN) return 'Name is too short.';
        if (mb_strlen($name) > self::NAME_MAX) return 'Name must not exceed ' . self::NAME_MAX . ' characters.';
        if (!preg_match(self::NAME_REGEX, $name)) return self::NAME_MESSAGE;
        return null;
    }

    /**
     * Strict PH mobile normaliser.
     * @return string|null '+639XXXXXXXXX', or null when the input is not a valid number.
     */
    public static function normalizePhone($raw) {
        $raw = trim((string)$raw);
        if ($raw === '' || !preg_match(self::PHONE_TYPED_REGEX, $raw)) return null;
        $d = preg_replace('/[^0-9]/', '', $raw);
        if (strpos($d, '63') === 0 && strlen($d) === 12) $d = substr($d, 2);
        if (strpos($d, '0') === 0) $d = substr($d, 1);
        return preg_match('/^9[0-9]{9}$/', $d) ? '+63' . $d : null;
    }

    /** True when a PHONE_TYPED/normalise-valid number. */
    public static function phoneError($raw, $required = true) {
        $raw = trim((string)$raw);
        if ($raw === '') return $required ? self::PHONE_MESSAGE : null;
        return self::normalizePhone($raw) === null ? self::PHONE_MESSAGE : null;
    }
}

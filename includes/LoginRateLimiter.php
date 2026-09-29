<?php
/**
 * Login Rate Limiter — Fixed 5-Minute Lockout
 * 
 * Policy:
 *   - 5 failed attempts → lockout for 5 minutes (FIXED — no doubling)
 *   - Successful login resets everything
 *   - After the 5-minute lockout expires, the attempt counter resets to 0
 *
 * Every return value from check() and recordFailure() includes:
 *   - 'reset_in' => int seconds (countdown for UI)
 */

class LoginRateLimiter
{
    private PDO $pdo;

    /** Max attempts before lockout */
    private const MAX_ATTEMPTS = 5;

    /** Fixed lockout duration in seconds (5 minutes) */
    private const LOCKOUT_SECONDS = 300;

    /** Attempt window — reset attempts if last attempt was more than this long ago */
    private const ATTEMPT_WINDOW_SECONDS = 3600; // 1 hour

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    /**
     * Check if the identifier + IP is currently locked out.
     *
     * Returns:
     *   [
     *     'locked'        => bool,
     *     'attempts_left' => int,     // 0 when locked
     *     'remaining'     => int sec, // lockout seconds remaining (0 if not locked)
     *     'reset_in'      => int sec, // countdown for UI
     *     'message'       => string,
     *     'level'         => int,     // always 1 if locked, 0 otherwise
     *   ]
     */
    public function check(string $identifier, string $ip): array
    {
        $identifier = strtolower(trim($identifier));
        $ip         = $this->normalizeIp($ip);

        $row = $this->getRow($identifier, $ip);

        // ── No row: fresh user, everything at defaults ─────────────
        if (!$row) {
            return [
                'locked'        => false,
                'attempts_left' => self::MAX_ATTEMPTS,
                'remaining'     => 0,
                'reset_in'      => 0,
                'message'       => '',
                'level'         => 0,
            ];
        }

        // ── Currently locked out? ──────────────────────────────────
        if (!empty($row['locked_until'])) {
            $lockedUntil = strtotime($row['locked_until']);
            $now         = time();

            if ($now < $lockedUntil) {
                $remaining = $lockedUntil - $now;
                return [
                    'locked'        => true,
                    'attempts_left' => 0,
                    'remaining'     => $remaining,
                    'reset_in'      => $remaining,
                    'message'       => $this->formatMessage($remaining),
                    'level'         => 1,
                ];
            }

            // Lockout expired — clear locked_until and reset attempt_count
            $this->pdo->prepare("
                UPDATE login_attempts 
                SET locked_until = NULL, attempt_count = 0 
                WHERE identifier = ? AND ip_address = ?
            ")->execute([$identifier, $ip]);

            $row['attempt_count'] = 0;
            $row['locked_until']  = null;
        }

        // ── Inactivity window expired? ─────────────────────────────
        $attemptCount = (int)$row['attempt_count'];
        $lastAttempt  = !empty($row['last_attempt']) ? strtotime($row['last_attempt']) : 0;
        $now          = time();

        if ($lastAttempt > 0 && ($now - $lastAttempt) > self::ATTEMPT_WINDOW_SECONDS) {
            // Window passed — reset attempt count
            $this->pdo->prepare("
                UPDATE login_attempts 
                SET attempt_count = 0 
                WHERE identifier = ? AND ip_address = ?
            ")->execute([$identifier, $ip]);

            $attemptCount = 0;
            $resetIn      = 0;
        } else {
            // Still inside window — countdown to when attempts reset
            $resetIn = $lastAttempt > 0
                ? max(0, ($lastAttempt + self::ATTEMPT_WINDOW_SECONDS) - $now)
                : 0;
        }

        return [
            'locked'        => false,
            'attempts_left' => max(0, self::MAX_ATTEMPTS - $attemptCount),
            'remaining'     => 0,
            'reset_in'      => $resetIn,
            'message'       => '',
            'level'         => 0,
        ];
    }

    /**
     * Record a failed login attempt.
     *
     * Returns:
     *   [
     *     'locked'        => bool,
     *     'attempts_left' => int,     // only present when NOT locked
     *     'remaining'     => int sec, // lockout duration (only when locked)
     *     'reset_in'      => int sec, // countdown for UI
     *     'message'       => string,  // only when locked
     *     'level'         => int,     // 1 when locked, 0 otherwise
     *   ]
     */
    public function recordFailure(string $identifier, string $ip): array
    {
        $identifier = strtolower(trim($identifier));
        $ip         = $this->normalizeIp($ip);

        $row = $this->getRow($identifier, $ip);

        // ── First ever failure for this identifier+IP ──────────────
        if (!$row) {
            $this->pdo->prepare("
                INSERT INTO login_attempts 
                    (identifier, ip_address, attempt_count, lockout_level, last_attempt)
                VALUES (?, ?, 1, 0, NOW())
            ")->execute([$identifier, $ip]);

            return [
                'locked'        => false,
                'attempts_left' => self::MAX_ATTEMPTS - 1,   // 4
                'remaining'     => 0,
                'reset_in'      => self::ATTEMPT_WINDOW_SECONDS,
                'message'       => '',
                'level'         => 0,
            ];
        }

        $newCount = (int)$row['attempt_count'] + 1;

        // ── Trigger lockout on the 5th fail ────────────────────────
        if ($newCount >= self::MAX_ATTEMPTS) {
            $lockoutSeconds = self::LOCKOUT_SECONDS; // ✅ FIXED: always 5 minutes
            $lockedUntil    = date('Y-m-d H:i:s', time() + $lockoutSeconds);

            $this->pdo->prepare("
                UPDATE login_attempts 
                SET attempt_count = 0,
                    lockout_level = 1,
                    locked_until  = ?,
                    last_attempt  = NOW()
                WHERE identifier = ? AND ip_address = ?
            ")->execute([$lockedUntil, $identifier, $ip]);

            return [
                'locked'        => true,
                'attempts_left' => 0,
                'remaining'     => $lockoutSeconds,
                'reset_in'      => $lockoutSeconds,
                'level'         => 1,
                'message'       => $this->formatMessage($lockoutSeconds),
            ];
        }

        // ── Just increment ─────────────────────────────────────────
        $this->pdo->prepare("
            UPDATE login_attempts 
            SET attempt_count = ?, last_attempt = NOW()
            WHERE identifier = ? AND ip_address = ?
        ")->execute([$newCount, $identifier, $ip]);

        return [
            'locked'        => false,
            'attempts_left' => self::MAX_ATTEMPTS - $newCount,
            'remaining'     => 0,
            'reset_in'      => self::ATTEMPT_WINDOW_SECONDS,
            'message'       => '',
            'level'         => 0,
        ];
    }

    /**
     * Clear all attempts for identifier + IP (call on successful login).
     */
    public function clear(string $identifier, string $ip): void
    {
        $identifier = strtolower(trim($identifier));
        $ip         = $this->normalizeIp($ip);

        $this->pdo->prepare("
            DELETE FROM login_attempts 
            WHERE identifier = ? AND ip_address = ?
        ")->execute([$identifier, $ip]);
    }

    // ============================================================
    // Internal helpers
    // ============================================================

    private function getRow(string $identifier, string $ip): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM login_attempts 
            WHERE identifier = ? AND ip_address = ?
        ");
        $stmt->execute([$identifier, $ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);

        if ($ip === '::1' || $ip === '::ffff:127.0.0.1') {
            return '127.0.0.1';
        }

        if (stripos($ip, '::ffff:') === 0) {
            $candidate = substr($ip, 7);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $candidate;
            }
        }

        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return $ip;
    }

    private function formatMessage(int $seconds): string
    {
        $time = $this->humanTime($seconds);
        return "Too many failed attempts. Please try again in {$time}.";
    }

    public static function humanTime(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds !== 1 ? 's' : '');
        }
        $minutes = ceil($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
        }
        $hours = ceil($minutes / 60);
        return $hours . ' hour' . ($hours !== 1 ? 's' : '');
    }

    private function ensureTableExists(): void
    {
        try {
            $this->pdo->query("SELECT 1 FROM login_attempts LIMIT 1");
        } catch (PDOException $e) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(255) NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    attempt_count INT NOT NULL DEFAULT 0,
                    lockout_level INT NOT NULL DEFAULT 0,
                    locked_until DATETIME DEFAULT NULL,
                    last_attempt DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_identifier_ip (identifier, ip_address),
                    INDEX idx_locked_until (locked_until)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }
}
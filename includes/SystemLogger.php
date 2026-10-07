<?php
/**
 * SystemLogger
 * 
 * Centralized audit trail logger for all admin/staff actions.
 * 
 * @version 2.0
 */
class SystemLogger
{
    // ────────────────────────────────────────────────
    // Whitelists — para consistent ang data
    // ────────────────────────────────────────────────
    private const ALLOWED_ACTIONS = [
        'create', 'update', 'delete', 'view',
        'login', 'login_failed', 'logout', 'register', 'verify_device',
        'confirm_payment', 'reject_payment',
        'confirm_rebook', 'rebook', 'cancel_rebook', 'reject_rebook', 'rebook_failed',
        'cancel_booking', 'balance_paid', 'rebook_blocked',
        'upload_proof', 'upload', 'export', 'import',
        'settings_change', 'password_change', 'error'
    ];

    private const ALLOWED_MODULES = [
        'auth', 'user', 'house', 'tour', 'activity', 'food',
        'booking', 'review', 'content', 'system', 'report', 'settings', 'profile',
        'blocked_dates'
    ];

    // ────────────────────────────────────────────────
    // Sensitive keys — huwag i-record ang values
    // ────────────────────────────────────────────────
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'reset_token', 'otp',
        'api_key', 'secret', 'token', 'credit_card', 'cvv'
    ];

    // ────────────────────────────────────────────────
    // User cache — para hindi paulit-ulit ang SELECT
    // ────────────────────────────────────────────────
    private static ?array $userCache = null;

    // ════════════════════════════════════════════════
    // MAIN LOG METHOD
    // ════════════════════════════════════════════════
    public static function log(
        PDO $pdo,
        string $action,
        string $module,
        string $description,
        ?int $target_id = null,
        ?string $target_type = null,
        ?array $old_values = null,
        ?array $new_values = null,
        string $status = 'success',
        ?int $user_id = null
    ): void {
        try {
            // ── 1. Session guard (safe kahit walang session) ──
            if (session_status() === PHP_SESSION_ACTIVE) {
                $uid      = $user_id ?? ($_SESSION['user_id'] ?? null);
                $username = $_SESSION['username'] ?? null;
                $fullname = $_SESSION['fullname'] ?? null;
                $role     = $_SESSION['role']     ?? null;
            } else {
                $uid      = $user_id;
                $username = null;
                $fullname = null;
                $role     = null;
            }

            // ── 2. User lookup (cached, with fullname) ──
            if ($uid && (!$username || !$fullname)) {
                if (self::$userCache === null || (self::$userCache['id'] ?? null) !== $uid) {
                    $stmt = $pdo->prepare("SELECT id, username, fullname, role FROM users WHERE id = ?");
                    $stmt->execute([$uid]);
                    self::$userCache = $stmt->fetch() ?: [];
                }
                $username = $username ?: (self::$userCache['username'] ?? null);
                $fullname = $fullname ?: (self::$userCache['fullname'] ?? null);
                $role     = $role     ?: (self::$userCache['role']     ?? null);
            }

            // ── 3. Validate action & module ──
            if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
                error_log("SystemLogger: unknown action '{$action}'");
                $action = 'unknown';
            }
            if (!in_array($module, self::ALLOWED_MODULES, true)) {
                error_log("SystemLogger: unknown module '{$module}'");
                $module = 'unknown';
            }

            // ── 4. Defaults + sanitize ──
            $target_type = $target_type ?? $module;
            $description = mb_substr($description, 0, 1000);

            $old_json = $old_values !== null
                ? json_encode(self::sanitize($old_values), JSON_UNESCAPED_UNICODE)
                : null;
            $new_json = $new_values !== null
                ? json_encode(self::sanitize($new_values), JSON_UNESCAPED_UNICODE)
                : null;

            // ── 5. Request context ──
            $ip = self::getClientIp();
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

            // ── 6. Insert ──
            $stmt = $pdo->prepare("
                INSERT INTO system_logs
                    (user_id, username, fullname, role, action, module, description,
                     target_id, target_type, old_values, new_values,
                     ip_address, user_agent, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $uid, $username, $fullname, $role,
                $action, $module, $description,
                $target_id, $target_type,
                $old_json, $new_json,
                $ip, $ua, $status
            ]);

        } catch (PDOException $e) {
            error_log("SystemLogger error: " . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════
    // CONVENIENCE WRAPPERS
    // ════════════════════════════════════════════════

    public static function created(PDO $pdo, string $module, string $desc, ?int $id = null, ?array $new = null): void {
        self::log($pdo, 'create', $module, $desc, $id, $module, null, $new);
    }

    public static function updated(PDO $pdo, string $module, string $desc, ?int $id = null, ?array $old = null, ?array $new = null): void {
        self::log($pdo, 'update', $module, $desc, $id, $module, $old, $new);
    }

    public static function deleted(PDO $pdo, string $module, string $desc, ?int $id = null, ?array $old = null): void {
        self::log($pdo, 'delete', $module, $desc, $id, $module, $old, null, 'warning');
    }

    public static function failed(PDO $pdo, string $module, string $desc, ?int $id = null, ?array $context = null): void {
        self::log($pdo, 'error', $module, $desc, $id, $module, null, $context, 'failed');
    }

    public static function login(PDO $pdo, int $user_id, string $username): void {
        self::log($pdo, 'login', 'auth', "User '{$username}' logged in", $user_id, 'user', null, null, 'success', $user_id);
    }

    public static function loginFailed(PDO $pdo, string $username, string $reason = 'Invalid credentials'): void {
        self::log($pdo, 'login_failed', 'auth', "Failed login attempt for '{$username}': {$reason}", null, 'user', null, null, 'failed');
    }

    // ════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════

    /**
     * Get real client IP (supports Cloudflare, proxies).
     */
    public static function getClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR']  ?? null,
            $_SERVER['HTTP_X_REAL_IP']        ?? null,
            $_SERVER['HTTP_CLIENT_IP']        ?? null,
            $_SERVER['REMOTE_ADDR']           ?? null,
        ];

        foreach ($candidates as $ip) {
            if (empty($ip)) continue;
            $ip = trim(explode(',', $ip)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }

    /**
     * Recursively redact sensitive keys from old/new values.
     */
    private static function sanitize(array $values): array
    {
        foreach ($values as $k => $v) {
            if (in_array(strtolower((string)$k), self::SENSITIVE_KEYS, true)) {
                $values[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $values[$k] = self::sanitize($v);
            }
        }
        return $values;
    }

    /**
     * Clear the user cache (useful kung nagbago ang user sa loob ng request).
     */
    public static function clearCache(): void
    {
        self::$userCache = null;
    }
}
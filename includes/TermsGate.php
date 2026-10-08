<?php
/**
 * TermsGate — handles Terms & Privacy acceptance
 *
 * Flow:
 *   1. Admin edits terms in edit-content.php
 *   2. The version hash changes → users must re-accept
 *   3. On login, if user hasn't accepted current version, modal pops up
 *   4. User scrolls both tabs, checks box, clicks Accept
 *   5. Acceptance is recorded in user_terms_acceptance
 */

class TermsGate
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    /**
     * Compute the current version hash from site_content rows.
     * Only depends on terms + privacy section content.
     */
    public function getCurrentVersion(): string
    {
        $stmt = $this->pdo->query("
            SELECT section_name, content_key, content_value
            FROM site_content
            WHERE (section_name = 'terms' OR section_name = 'privacy')
            ORDER BY section_name, content_key
        ");
        $buffer = '';
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $buffer .= $row['section_name'] . ':' . $row['content_key'] . '=' . $row['content_value'] . '|';
        }
        return substr(md5($buffer), 0, 8);
    }

    /**
     * Has the user accepted the CURRENT version?
     */
    public function hasAccepted(int $userId): bool
    {
        $version = $this->getCurrentVersion();
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM user_terms_acceptance
            WHERE user_id = ? AND terms_version = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $version]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Record acceptance.
     */
    public function accept(int $userId, ?string $ip = null): void
    {
        $version = $this->getCurrentVersion();
        $stmt = $this->pdo->prepare("
            INSERT INTO user_terms_acceptance (user_id, terms_version, accepted_at, ip_address)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE accepted_at = NOW(), ip_address = VALUES(ip_address)
        ");
        $stmt->execute([$userId, $version, $ip]);
    }

    /**
     * Server-side gate for guest pages. A logged-in GUEST who has not accepted the
     * CURRENT Terms & Privacy is sent to the homepage, where the acceptance modal opens,
     * instead of using booking / profile / payment / rebook / cancel / feedback features.
     *
     * Never affects: admin, staff, anonymous visitors, login, OTP / new-device verification,
     * forgot-password, logout (none of those call this).
     *
     * $isHome = true  -> homepage: a plain page view is allowed (that is where the modal shows);
     *                    state-changing requests are refused, except the `accept_terms` POST,
     *                    which index.php's existing handler records and answers first.
     */
    public static function enforceGuest(PDO $pdo, bool $isHome = false): void
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'guest') {
            return;
        }
        $gate = new self($pdo);
        if ($gate->hasAccepted((int)$_SESSION['user_id'])) {
            return;
        }
        $_SESSION['show_terms_modal'] = true;

        if ($isHome) {
            $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
            if (!$isPost && !isset($_GET['delete_feedback'])) return;      // view the homepage -> modal
            if ($isPost && isset($_POST['accept_terms'])) return;          // the acceptance itself
        }
        if (!headers_sent()) {
            header('Location: index.php?terms_required=1');
        }
        exit();
    }

    /**
     * Get the terms + privacy content from site_content.
     * Provides sensible defaults if not yet set.
     */
    public function getContent(): array
    {
        $out = [
            'terms_title'   => 'Terms & Conditions',
            'terms_body'    => '',
            'privacy_title' => 'Privacy Policy',
            'privacy_body'  => '',
        ];

        $stmt = $this->pdo->query("
            SELECT section_name, content_key, content_value
            FROM site_content
            WHERE section_name IN ('terms','privacy')
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['section_name'] === 'terms') {
                if ($row['content_key'] === 'title') $out['terms_title'] = $row['content_value'];
                if ($row['content_key'] === 'body')  $out['terms_body']  = $row['content_value'];
            }
            if ($row['section_name'] === 'privacy') {
                if ($row['content_key'] === 'title') $out['privacy_title'] = $row['content_value'];
                if ($row['content_key'] === 'body')  $out['privacy_body']  = $row['content_value'];
            }
        }

        // Fallbacks if admin hasn't saved anything yet
        if (trim($out['terms_body']) === '') {
            $out['terms_body'] = "Welcome to Transient House & Tours.\n\n"
                . "By using our services, you agree to our booking terms, payment policies, "
                . "cancellation rules, and house guidelines. Please contact us for the full "
                . "Terms & Conditions document.";
        }
        if (trim($out['privacy_body']) === '') {
            $out['privacy_body'] = "Your privacy is important to us.\n\n"
                . "We collect only the information needed to process your bookings "
                . "(name, contact, email, ID, payment proof). We do not sell your data. "
                . "Please contact us for the full Privacy Policy document.";
        }

        return $out;
    }

    /**
     * Auto-create the acceptance table on first use.
     */
    private function ensureTableExists(): void
    {
        try {
            $this->pdo->query("SELECT 1 FROM user_terms_acceptance LIMIT 1");
        } catch (PDOException $e) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS user_terms_acceptance (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    terms_version VARCHAR(20) NOT NULL,
                    accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    UNIQUE KEY unique_user_version (user_id, terms_version),
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }
}
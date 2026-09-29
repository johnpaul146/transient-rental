<?php
/**
 * migrate_passwords.php
 * 
 * ONE-TIME MIGRATION TOOL
 * 
 * Purpose: Convert any remaining plain-text passwords in the `users`
 * table into secure bcrypt hashes using password_hash().
 * 
 * Usage:
 *   1. Run this file ONCE in the browser:  http://localhost/your-project/migrate_passwords.php
 *   2. Verify output — every user should be either "Migrated" or "SKIP"ped.
 *   3. DELETE this file immediately after. Do NOT leave it on a live server.
 * 
 * Notes:
 *   - Safe to run multiple times — already-hashed passwords are skipped.
 *   - Weak plain-text passwords are NOT hashed (they'd stay weak). Instead,
 *     they are replaced with a random unknown value so the user MUST reset
 *     via "Forgot Password". This prevents weak passwords from ever working.
 *   - Compatible with PHP 7.4+ / 8.x
 */

// ── Basic safety: block execution from a public-facing environment ──
// Uncomment the lines below if you're paranoid about this file being
// accidentally left on a production server. For local dev, leave as-is.

// if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '::1') {
//     http_response_code(403);
//     exit('Forbidden — this migration tool can only be run from localhost.');
// }

require_once 'database.php';

// ── Pretty output (works both in browser and CLI) ──
$isCli = (php_sapi_name() === 'cli');
$nl    = $isCli ? "\n" : "<br>\n";
$pre   = !$isCli;

if ($pre) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
    echo '<title>Password Migration</title>';
    echo '<style>
        body { font-family: ui-monospace, Menlo, Consolas, monospace; 
               background: #0B2447; color: #e0eeff; padding: 30px; line-height: 1.7; }
        h1 { color: #F4B400; margin-bottom: 20px; }
        .ok  { color: #10b981; }
        .skip{ color: #f59e0b; }
        .err { color: #ef4444; }
        .box { background: rgba(255,255,255,0.05); border-left: 4px solid #4DA6D9;
               padding: 15px 20px; border-radius: 8px; margin: 15px 0; }
        .done{ background: rgba(16,185,129,0.15); border-left: 4px solid #10b981;
               padding: 15px 20px; border-radius: 8px; margin-top: 25px; font-size: 18px; }
    </style></head><body>';
    echo '<h1>🔐 Password Migration Tool</h1>';
    echo '<div class="box">Scanning <strong>users</strong> table for plain-text passwords…</div>';
    echo '<pre>';
}

echo "=== migrate_passwords.php started at " . date('Y-m-d H:i:s') . " ==={$nl}{$nl}";

// ── Fetch all users ──
try {
    $stmt  = $pdo->query("SELECT id, username, password FROM users ORDER BY id ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "❌ FATAL: Cannot read users table — " . htmlspecialchars($e->getMessage()) . "{$nl}";
    if ($pre) echo '</pre></body></html>';
    exit;
}

$total     = count($users);
$migrated  = 0;
$skipped   = 0;
$already   = 0;
$failed    = 0;

echo "Found {$total} user(s).{$nl}{$nl}";

// ── Loop through each user ──
foreach ($users as $u) {
    $id       = (int)$u['id'];
    $username = (string)$u['username'];
    $pwd      = (string)$u['password'];

    // Is it already a hash?
    $looksHashed = (bool) preg_match('/^\$2[aby]\$/', $pwd)
                || (strpos($pwd, '$argon') === 0);

    if ($looksHashed) {
        $already++;
        echo "⏭️  SKIP (already hashed): <strong>{$username}</strong>{$nl}";
        continue;
    }

    // It's plain-text. Check complexity.
    $compliant = strlen($pwd) >= 8
        && preg_match('/[A-Z]/', $pwd)
        && preg_match('/[0-9]/', $pwd);

    if (!$compliant) {
        // ── Weak password ──
        // Replace with an unguessable random value so it can NEVER be used.
        // The user must reset via "Forgot Password".
        $randomJunk = bin2hex(random_bytes(32));          // 64 hex chars
        $newHash    = password_hash($randomJunk, PASSWORD_DEFAULT);

        try {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([$newHash, $id]);
            $skipped++;
            echo "⚠️  WEAK → replaced with random: <strong>{$username}</strong>";
            echo " — must reset via Forgot Password{$nl}";
        } catch (PDOException $e) {
            $failed++;
            echo "❌ FAILED to neutralize '{$username}': ";
            echo htmlspecialchars($e->getMessage()) . "{$nl}";
        }
        continue;
    }

    // ── Compliant plain-text → hash it ──
    try {
        $newHash = password_hash($pwd, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([$newHash, $id]);
        $migrated++;
        echo "✅ MIGRATED: <strong>{$username}</strong>{$nl}";
    } catch (PDOException $e) {
        $failed++;
        echo "❌ FAILED to migrate '{$username}': ";
        echo htmlspecialchars($e->getMessage()) . "{$nl}";
    }
}

// ── Summary ──
echo "{$nl}=== Summary ==={$nl}";
echo "Total users:        {$total}{$nl}";
echo "Already hashed:     {$already}{$nl}";
echo "Migrated (strong):  {$migrated}{$nl}";
echo "Weakened (reset):   {$skipped}{$nl}";
echo "Failed:             {$failed}{$nl}";
echo "{$nl}=== Finished at " . date('Y-m-d H:i:s') . " ==={$nl}";

if ($pre) {
    echo '</pre>';
    echo '<div class="done">';
    echo '✅ <strong>Migration complete.</strong><br>';
    echo '🗑️ <strong>Delete this file</strong> (<code>migrate_passwords.php</code>) now — it is a one-time tool.';
    echo '</div>';
    echo '</body></html>';
}
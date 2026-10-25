<?php
/**
 * MailBranding — one place that hands out the shared PHPMailer object with the Jerry Dino's Stay sender display name.
 *
 * Why: config/mail_config.php (server-specific, holds the SMTP login) builds the mailer with setFrom(<address>, 'Transient House & Tours').
 * That file is never shipped or overwritten by an upgrade, so the display name is corrected here, at run time:
 *   - only the display NAME changes (From name and the matching Reply-To name); the sender ADDRESS, SMTP host, login and
 *     every other setting come from the server's own config/mail_config.php untouched;
 *   - it changes the name only while it is still blank or the old "Transient House & Tours"; a name you set yourself in
 *     config/mail_config.php is left as it is. Editing setFrom() there to "Jerry Dino's Stay" makes this a no-op.
 */
class MailBranding
{
    const NAME = "Jerry Dino's Stay";

    /** The shared mailer from config/mail_config.php with the corrected sender display name. */
    public static function mailer()
    {
        require_once __DIR__ . '/../config/mail_config.php';
        $mail = MailConfig::getInstance()->getMailer();
        self::apply($mail);
        return $mail;
    }

    /** Fix the display name on a mailer object (safe to call repeatedly). */
    public static function apply($mail)
    {
        if (!is_object($mail)) return $mail;
        $name = trim((string)($mail->FromName ?? ''));
        if ($name === '' || stripos($name, 'Transient House') !== false) {
            $mail->FromName = self::NAME;
        }
        // Reply-To made by setFrom() carries the same old name: rebuild it with the same address and the new name.
        $fix = [];
        $replyTos = method_exists($mail, 'getReplyToAddresses') ? (array)$mail->getReplyToAddresses() : [];
        foreach ($replyTos as $addr => $pair) {
            $n = trim((string)($pair[1] ?? ''));
            if ($n === '' || stripos($n, 'Transient House') !== false) $fix[] = (string)$addr;
        }
        if ($fix && method_exists($mail, 'clearReplyTos') && method_exists($mail, 'addReplyTo')) {
            $mail->clearReplyTos();
            foreach ($fix as $addr) $mail->addReplyTo($addr, self::NAME);
        }
        return $mail;
    }
}

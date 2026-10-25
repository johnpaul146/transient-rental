<?php
require_once __DIR__ . '/MailBranding.php';
// includes/EmailHelper.php

require_once __DIR__ . '/../config/mail_config.php';

class EmailHelper {
    
    /**
     * Send test email
     */
    public static function sendTestEmail($to, $name) {
        $mail = MailBranding::mailer();
        
        try {
            $mail->clearAddresses();
            $mail->addAddress($to, $name);
            
            $mail->Subject = '✅ Test Email - PHPMailer Working!';
            $mail->Body = '
                <h2>🎉 PHPMailer is Working!</h2>
                <p>This is a test email from your Jerry Dino\'s Stay system.</p>
                <p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>
                <p>If you received this, your email setup is successful!</p>
            ';
            $mail->AltBody = 'PHPMailer is working! Test email from Jerry Dino\'s Stay.';
            
            return $mail->send();
            
        } catch (Exception $e) {
            error_log("Email error: " . $mail->ErrorInfo);
            return false;
        }
    }
}
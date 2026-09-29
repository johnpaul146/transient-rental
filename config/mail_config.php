<?php
// config/mail_config.php

require_once __DIR__ . '/../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/SMTP.php';
require_once __DIR__ . '/../phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailConfig {
    private static $instance = null;
    private $mailer;
    
    private function __construct() {
        $this->mailer = new PHPMailer(true);
        
        // TEMP: verbose debug so we can see the real SMTP error.
        // Set back to SMTP::DEBUG_OFF once the issue is found.
        $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
        $this->mailer->Debugoutput = function($str, $level) {
            error_log("PHPMailer [$level]: " . trim($str));
        };
        
        $this->mailer->isSMTP();
        $this->mailer->Host       = 'smtp.gmail.com';
        $this->mailer->SMTPAuth   = true;
        $this->mailer->Username   = 'johnpaulnavarro0105@gmail.com';
        $this->mailer->Password   = 'bddlvlucrmknhsky';  // <-- App Password
        
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port       = 587;
        
        $this->mailer->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $this->mailer->setFrom('johnpaulnavarro0105@gmail.com', 'Transient House & Tours');
        $this->mailer->isHTML(true);
        $this->mailer->CharSet = 'UTF-8';
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getMailer() {
        return $this->mailer;
    }
}
?>
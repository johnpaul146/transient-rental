<?php
// includes/EmailNotifications.php

require_once __DIR__ . '/../config/mail_config.php';

class EmailNotifications {

    public static $lastError = '';
    private static $columnCache = [];

    // ============================================================
    // COLUMN DETECTION HELPERS
    // ============================================================
    private static function getGuestNameColumn($pdo) {
        if (isset(self::$columnCache['guest_name'])) return self::$columnCache['guest_name'];
        $cols = $pdo->query("SHOW COLUMNS FROM guests")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['full_name', 'fullname', 'name', 'guest_name'] as $c) {
            if (in_array($c, $cols)) { self::$columnCache['guest_name'] = $c; return $c; }
        }
        self::$columnCache['guest_name'] = 'id';
        return 'id';
    }

    private static function getUserEmailColumn($pdo) {
        if (isset(self::$columnCache['user_email'])) return self::$columnCache['user_email'];
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['email', 'email_address', 'user_email'] as $c) {
            if (in_array($c, $cols)) { self::$columnCache['user_email'] = $c; return $c; }
        }
        self::$columnCache['user_email'] = 'username';
        return 'username';
    }

    private static function getUsernameColumn($pdo) {
        if (isset(self::$columnCache['username'])) return self::$columnCache['username'];
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['username', 'user_name', 'name'] as $c) {
            if (in_array($c, $cols)) { self::$columnCache['username'] = $c; return $c; }
        }
        self::$columnCache['username'] = 'id';
        return 'id';
    }

    private static function hasColumn($pdo, $table, $column) {
        $key = "{$table}.{$column}";
        if (isset(self::$columnCache[$key])) return self::$columnCache[$key];
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            $result = $stmt->fetch() !== false;
            self::$columnCache[$key] = $result;
            return $result;
        } catch (Exception $e) {
            self::$columnCache[$key] = false;
            return false;
        }
    }

    // ============================================================
    // ✅ UPDATED: getBookingDetails — NOW SUPPORTS PACKAGE
    // ============================================================
    private static function getBookingDetails($booking_id, $booking_type, $pdo) {
        // ---- PACKAGE ----
        if ($booking_type == 'package') {
            return self::getPackageBookingDetails($booking_id, $pdo);
        }

        // ---- HOUSE / TOUR / FOOD ----
        $table = ''; $join = ''; $item_column = '';

        if ($booking_type == 'house') {
            $table = 'house_bookings';
            $join = 'JOIN houses h ON b.house_id = h.id';
            $item_column = 'h.house_name';
        } elseif ($booking_type == 'tour') {
            $table = 'tour_bookings';
            $join = 'JOIN tours t ON b.tour_id = t.id';
            $item_column = 't.tour_name';
        } elseif ($booking_type == 'food') {
            $table = 'food_bookings';
            $join = 'JOIN food_items f ON b.food_id = f.id';
            $item_column = 'f.name';
        } else {
            self::$lastError = "Unknown booking type: $booking_type";
            return null;
        }

        $guest_name_col = self::getGuestNameColumn($pdo);
        $user_email_col = self::getUserEmailColumn($pdo);
        $username_col   = self::getUsernameColumn($pdo);

        $has_gcash = self::hasColumn($pdo, $table, 'gcash_reference');
        $gcash_select = $has_gcash ? 'b.gcash_reference,' : "'' as gcash_reference,";

        $sql = "SELECT b.*, $gcash_select $item_column as item_name,
                       u.`$user_email_col` as email, u.`$username_col` as username,
                       g.`$guest_name_col` as guest_name
                FROM `$table` b
                $join
                JOIN guests g ON b.guest_id = g.id
                JOIN users u ON g.user_id = u.id
                WHERE b.id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$booking_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            self::$lastError = "Booking #$booking_id not found.";
            return null;
        }
        return $row;
    }

    // ============================================================
    // ✅ NEW: Load package + all its sub-bookings in one shot
    // ============================================================
    private static function getPackageBookingDetails($package_id, $pdo) {
        $guest_name_col = self::getGuestNameColumn($pdo);
        $user_email_col = self::getUserEmailColumn($pdo);
        $username_col   = self::getUsernameColumn($pdo);

        $sql = "SELECT p.*,
                       u.`$user_email_col` as email,
                       u.`$username_col` as username,
                       g.`$guest_name_col` as guest_name,

                       h.house_name,
                       hb.reference_number AS house_ref,
                       hb.check_in_date    AS house_check_in,
                       hb.check_out_date   AS house_check_out,
                       hb.number_of_guests AS house_guests,
                       hb.guest_names      AS house_guest_names,
                       hb.total_amount     AS house_amount,

                       t.tour_name,
                       tb.reference_number AS tour_ref,
                       tb.booking_date     AS tour_date,
                       tb.preferred_time   AS tour_time,
                       tb.number_of_guests AS tour_guests,
                       tb.guest_name       AS tour_guest_name,
                       tb.contact_number   AS tour_contact,
                       tb.special_requests AS tour_special_requests,
                       tb.total_amount     AS tour_amount,

                       f.name              AS food_name,
                       fb.reference_number AS food_ref,
                       fb.size_variant     AS food_size,
                       fb.quantity         AS food_qty,
                       fb.preferred_date   AS food_date,
                       fb.preferred_time   AS food_time,
                       fb.number_of_persons AS food_persons,
                       fb.fulfillment_method AS food_fulfillment,
                       fb.delivery_address  AS food_delivery_address,
                       fb.contact_number    AS food_contact,
                       fb.special_requests  AS food_special_requests,
                       fb.total_amount      AS food_amount

                FROM package_bookings p
                LEFT JOIN guests g ON p.guest_id = g.id
                LEFT JOIN users u ON g.user_id = u.id
                LEFT JOIN house_bookings hb ON p.house_booking_id = hb.id
                LEFT JOIN houses h ON hb.house_id = h.id
                LEFT JOIN tour_bookings tb ON p.tour_booking_id = tb.id
                LEFT JOIN tours t ON tb.tour_id = t.id
                LEFT JOIN food_bookings fb ON p.food_booking_id = fb.id
                LEFT JOIN food_items f ON fb.food_id = f.id
                WHERE p.id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$package_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            self::$lastError = "Package #$package_id not found.";
            return null;
        }

        $parts = [];
        if (!empty($row['house_name'])) $parts[] = $row['house_name'];
        if (!empty($row['tour_name']))  $parts[] = $row['tour_name'];
        if (!empty($row['food_name']))  $parts[] = $row['food_name'];
        $row['item_name']        = implode(' + ', $parts);
        $row['total_amount']     = $row['grand_total'] ?? 0;
        $row['reference_number'] = $row['reference_number'] ?? '';
        $row['gcash_reference']  = $row['gcash_reference'] ?? '';

        return $row;
    }

    private static function getAdminEmails($pdo) {
        try {
            $user_email_col = self::getUserEmailColumn($pdo);
            $username_col   = self::getUsernameColumn($pdo);

            $stmt = $pdo->prepare("SELECT `$user_email_col` as email, `$username_col` as username, role 
                                   FROM users 
                                   WHERE LOWER(role) IN ('admin', 'staff') 
                                   AND `$user_email_col` IS NOT NULL AND `$user_email_col` != ''");
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($result)) {
                $stmt = $pdo->prepare("SELECT `$user_email_col` as email, `$username_col` as username, role 
                                       FROM users 
                                       WHERE role IN ('admin', 'staff', 'Administrator', 'Admin') 
                                       AND `$user_email_col` IS NOT NULL AND `$user_email_col` != ''");
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $result;
        } catch (Exception $e) {
            error_log("getAdminEmails ERROR: " . $e->getMessage());
            return [];
        }
    }

    // ============================================================
    // 🎨 SHARED DARK BLUE EMAIL WRAPPER
    // ============================================================
    private static function wrapTemplate($params) {
        $badge        = $params['badge_text']    ?? 'TRANSIT HOUSE & TOURS';
        $title        = $params['title']         ?? 'Notification';
        $ref_code     = $params['ref_code']      ?? '';
        $greeting     = $params['greeting_name'] ?? 'Guest';
        $intro        = $params['intro_text']    ?? '';
        $content      = $params['content']       ?? '';
        $grad_from    = $params['gradient_from'] ?? '#0B2447';
        $grad_to      = $params['gradient_to']   ?? '#4DA6D9';
        $footer_note  = $params['footer_note']   ?? 'This is a system-generated email. Please do not reply.';

        $ref_html = !empty($ref_code)
            ? '<tr><td align="center" style="padding-top: 8px;"><span style="color:#bae6fd; font-size: 13px; font-weight: 600;">Ref Code: ' . htmlspecialchars($ref_code) . '</span></td></tr>'
            : '';

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin:0; padding:0; background-color:#0a1628; font-family: Arial, Helvetica, sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0a1628; padding: 24px 12px;">
    <tr>
      <td align="center">

        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#0f1e33; border-radius:16px; overflow:hidden; border:1px solid #1e3a5f;">

          <!-- HEADER -->
          <tr>
            <td align="center" style="background: linear-gradient(135deg, ' . $grad_from . ' 0%, ' . $grad_to . ' 100%); padding: 30px 20px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td align="center">
                    <span style="display:inline-block; background-color: rgba(255,255,255,0.15); color:#F4B400; padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; border: 1px solid rgba(255,255,255,0.25);">
                      ' . htmlspecialchars($badge) . '
                    </span>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding-top: 16px;">
                    <h1 style="margin:0; color:#ffffff; font-size: 26px; font-weight: 800; letter-spacing: -0.5px;">
                      ' . $title . '
                    </h1>
                  </td>
                </tr>
                ' . $ref_html . '
              </table>
            </td>
          </tr>

          <!-- GREETING -->
          <tr>
            <td style="padding: 28px 28px 12px 28px;">
              <p style="margin:0 0 8px 0; color:#ffffff; font-size: 16px; font-weight: 700;">
                Hello ' . htmlspecialchars($greeting) . ',
              </p>
              <p style="margin:0; color:#94a3b8; font-size: 13.5px; line-height: 1.6;">
                ' . $intro . '
              </p>
            </td>
          </tr>

          <!-- MAIN CONTENT -->
          <tr>
            <td style="padding: 0 28px;">
              ' . $content . '
            </td>
          </tr>

          <!-- SPACER -->
          <tr><td style="height: 24px;"></td></tr>

          <!-- FOOTER -->
          <tr>
            <td align="center" style="background-color: #0a1628; padding: 20px;">
              <p style="margin:0; color:#475569; font-size: 11px; line-height: 1.5;">
                &copy; ' . date('Y') . ' Transient House &amp; Tours. All rights reserved.<br>
                ' . htmlspecialchars($footer_note) . '
              </p>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>';
    }

    // ============================================================
    // 🧩 SHARED HTML BLOCKS
    // ============================================================
    private static function datePairBlock($checkin_label, $checkin_value, $checkout_label, $checkout_value) {
        return '
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="48%" valign="top" style="background-color: #0c2a4d; border: 1px solid #1e40af; border-radius: 12px; padding: 16px;">
              <p style="margin:0 0 8px 0; color:#60a5fa; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;">
                ' . $checkin_label . '
              </p>
              <p style="margin:0; color:#ffffff; font-size: 14px; font-weight: 700; line-height: 1.4;">
                ' . $checkin_value . '
              </p>
            </td>
            <td width="4%"></td>
            <td width="48%" valign="top" style="background-color: #0c2a4d; border: 1px solid #1e40af; border-radius: 12px; padding: 16px;">
              <p style="margin:0 0 8px 0; color:#60a5fa; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;">
                ' . $checkout_label . '
              </p>
              <p style="margin:0; color:#ffffff; font-size: 14px; font-weight: 700; line-height: 1.4;">
                ' . $checkout_value . '
              </p>
            </td>
          </tr>
        </table>';
    }

    private static function infoCardBlock($title, $rows) {
        $rows_html = '';
        $total = count($rows);
        $i = 0;
        foreach ($rows as $label => $value) {
            $i++;
            $border = ($i < $total) ? 'border-bottom: 1px solid #1e3a5f;' : '';
            $rows_html .= '
                <tr>
                  <td style="padding: 14px 18px; ' . $border . ' color:#64748b; font-size:12px; width: 42%; vertical-align: top;">' . $label . '</td>
                  <td style="padding: 14px 18px; ' . $border . ' color:#ffffff; font-size:13px; font-weight:600; vertical-align: top;">' . $value . '</td>
                </tr>';
        }

        return '
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #0a1628; border: 1px solid #1e3a5f; border-radius: 12px; overflow:hidden; margin-top: 12px;">
          <tr>
            <td colspan="2" style="padding: 14px 18px; border-bottom: 1px solid #1e3a5f;">
              <p style="margin:0; color:#ffffff; font-size: 12px; font-weight: 700; letter-spacing: 0.5px;">' . $title . '</p>
            </td>
          </tr>
          ' . $rows_html . '
        </table>';
    }

    private static function totalBlock($total, $note = '') {
        return '
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #292112; border: 1px solid #b45309; border-radius: 12px; margin-top: 12px;">
          <tr>
            <td style="padding: 18px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="color:#fbbf24; font-size: 14px; font-weight: 700; vertical-align: middle;">
                    Total Amount Payable:
                  </td>
                  <td align="right" style="color:#fbbf24; font-size: 22px; font-weight: 800;">
                    ₱' . $total . '
                  </td>
                </tr>
              </table>
              ' . (!empty($note) ? '<p style="margin: 10px 0 0 0; color:#fcd34d; font-size: 12px; line-height: 1.5;">' . $note . '</p>' : '') . '
            </td>
          </tr>
        </table>';
    }

    private static function noteBlock($html, $color = 'blue') {
        $palettes = [
            'amber' => ['bg' => '#1c1508', 'border' => '#b45309', 'text' => '#fcd34d'],
            'green' => ['bg' => '#082018', 'border' => '#10b981', 'text' => '#6ee7b7'],
            'blue'  => ['bg' => '#0c2a4d', 'border' => '#4DA6D9', 'text' => '#93c5fd'],
            'red'   => ['bg' => '#2a0f12', 'border' => '#ef4444', 'text' => '#fca5a5'],
            'purple'=> ['bg' => '#0c2a4d', 'border' => '#4DA6D9', 'text' => '#93c5fd'],
        ];
        $p = $palettes[$color] ?? $palettes['blue'];

        return '
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: ' . $p['bg'] . '; border-left: 4px solid ' . $p['border'] . '; border-radius: 8px; margin-top: 12px;">
          <tr>
            <td style="padding: 14px 18px; color: ' . $p['text'] . '; font-size: 12.5px; line-height: 1.6;">
              ' . $html . '
            </td>
          </tr>
        </table>';
    }

    private static function gcashBlock($gcash) {
        if (empty($gcash)) {
            return '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #292112; border: 1px solid #b45309; border-radius: 10px; margin-top: 12px;">
              <tr><td style="padding: 14px; text-align: center; color: #fcd34d; font-size: 12px; font-weight: 600;">
                ⚠️ GCash Reference: Not provided
              </td></tr>
            </table>';
        }
        return '
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #082018; border: 2px solid #10b981; border-radius: 10px; margin-top: 12px;">
          <tr>
            <td style="padding: 16px 18px; text-align: center;">
              <p style="margin:0 0 4px 0; color:#6ee7b7; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;">
                🔢 GCash Reference Number
              </p>
              <p style="margin:0; color:#10b981; font-size: 22px; font-weight: 800; font-family: \'Courier New\', monospace; letter-spacing: 2px;">
                ' . htmlspecialchars($gcash) . '
              </p>
            </td>
          </tr>
        </table>';
    }

    private static function packageItemsBlock($pkg) {
        $html = '';
        if (!empty($pkg['house_name'])) {
            $html .= '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #0a1628; border-left: 4px solid #4DA6D9; border-radius: 10px; margin-top: 10px;">
              <tr><td style="padding: 14px 18px;">
                <p style="margin:0 0 8px 0; color:#ffffff; font-size:13px; font-weight:700;">🏠 ' . htmlspecialchars($pkg['house_name']) . '</p>
                <p style="margin:0; color:#94a3b8; font-size:12px; line-height:1.6;">
                  Ref: <strong style="color:#60a5fa;">' . htmlspecialchars($pkg['house_ref'] ?? 'N/A') . '</strong><br>
                  ' . self::fmtDate($pkg['house_check_in'] ?? null) . ' → ' . self::fmtDate($pkg['house_check_out'] ?? null) . '<br>
                  Guests: ' . (int)($pkg['house_guests'] ?? 0) . '<br>
                  Amount: <strong style="color:#fbbf24;">₱' . number_format((float)($pkg['house_amount'] ?? 0), 2) . '</strong>
                </p>
              </td></tr>
            </table>';
        }
        if (!empty($pkg['tour_name'])) {
            $html .= '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #0a1628; border-left: 4px solid #10b981; border-radius: 10px; margin-top: 10px;">
              <tr><td style="padding: 14px 18px;">
                <p style="margin:0 0 8px 0; color:#ffffff; font-size:13px; font-weight:700;">🏖️ ' . htmlspecialchars($pkg['tour_name']) . '</p>
                <p style="margin:0; color:#94a3b8; font-size:12px; line-height:1.6;">
                  Ref: <strong style="color:#60a5fa;">' . htmlspecialchars($pkg['tour_ref'] ?? 'N/A') . '</strong><br>
                  Date: ' . self::fmtDate($pkg['tour_date'] ?? null) . (($pkg['tour_time'] ?? '') ? ' at ' . htmlspecialchars($pkg['tour_time']) : '') . '<br>
                  Guests: ' . (int)($pkg['tour_guests'] ?? 0) . '<br>
                  Amount: <strong style="color:#fbbf24;">₱' . number_format((float)($pkg['tour_amount'] ?? 0), 2) . '</strong>
                </p>
              </td></tr>
            </table>';
        }
        if (!empty($pkg['food_name'])) {
            $fulfillment = $pkg['food_fulfillment'] ?? 'pickup';
            $html .= '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #0a1628; border-left: 4px solid #f59e0b; border-radius: 10px; margin-top: 10px;">
              <tr><td style="padding: 14px 18px;">
                <p style="margin:0 0 8px 0; color:#ffffff; font-size:13px; font-weight:700;">🍽️ ' . htmlspecialchars($pkg['food_name']) . '</p>
                <p style="margin:0; color:#94a3b8; font-size:12px; line-height:1.6;">
                  Ref: <strong style="color:#60a5fa;">' . htmlspecialchars($pkg['food_ref'] ?? 'N/A') . '</strong><br>
                  ' . ($pkg['food_size'] ? 'Size: ' . htmlspecialchars($pkg['food_size']) . '<br>' : '') . '
                  Qty: ' . (int)($pkg['food_qty'] ?? 1) . '<br>
                  ' . ($pkg['food_date'] ? 'Date: ' . self::fmtDate($pkg['food_date']) . (($pkg['food_time'] ?? '') ? ' at ' . htmlspecialchars($pkg['food_time']) : '') . '<br>' : '') . '
                  Method: ' . strtoupper($fulfillment) . ($fulfillment === 'delivery' && !empty($pkg['food_delivery_address']) ? '<br>Deliver to: ' . htmlspecialchars($pkg['food_delivery_address']) : '') . '<br>
                  Amount: <strong style="color:#fbbf24;">₱' . number_format((float)($pkg['food_amount'] ?? 0), 2) . '</strong>
                </p>
              </td></tr>
            </table>';
        }
        return $html;
    }

    // ============================================================
    // 📧 SEND METHODS
    // ============================================================

    public static function sendBookingConfirmation($booking_id, $booking_type, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking || empty($booking['email'])) {
                self::$lastError = !$booking ? 'Booking not found.' : 'Guest has no email on file.';
                return false;
            }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $type_label = ucfirst($booking_type);
            $mail->Subject = "✅ Booking Confirmation - $type_label #{$booking['reference_number']}";

            $body = ($booking_type === 'package')
                ? self::buildPackageBookingConfirmationEmail($booking)
                : self::buildBookingConfirmationEmail($booking, $booking_type);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendBookingConfirmation error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendNewBookingAlert($booking_id, $booking_type, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking) { self::$lastError = 'Booking not found.'; return false; }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();

            $admins = self::getAdminEmails($pdo);
            if (!empty($admins)) {
                foreach ($admins as $admin) {
                    if (!empty($admin['email'])) {
                        $mail->addAddress($admin['email'], $admin['username'] ?: 'Admin');
                    }
                }
            } else {
                $mail->addAddress('johnpaulnavarro0105@gmail.com', 'Admin (Fallback)');
            }

            $type_label = ucfirst($booking_type);
            $mail->Subject = "🔔 New $type_label Booking - #{$booking['reference_number']}";

            $body = ($booking_type === 'package')
                ? self::buildPackageNewBookingAlertEmail($booking)
                : self::buildNewBookingAlertEmail($booking, $booking_type);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendNewBookingAlert error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendPaymentUnderReview($booking_id, $booking_type, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking || empty($booking['email'])) {
                self::$lastError = !$booking ? 'Booking not found.' : 'Guest has no email on file.';
                return false;
            }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $type_label = ucfirst($booking_type);
            $mail->Subject = "⏳ Payment Received - Under Review - $type_label #{$booking['reference_number']}";

            $body = ($booking_type === 'package')
                ? self::buildPackagePaymentUnderReviewEmail($booking)
                : self::buildPaymentUnderReviewEmail($booking, $booking_type);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendPaymentUnderReview error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendPaymentConfirmation($booking_id, $booking_type, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking || !$booking['email']) {
                self::$lastError = !$booking ? 'Booking not found.' : 'Guest has no email on file.';
                return false;
            }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $type_label = ucfirst($booking_type);
            $mail->Subject = "💰 Payment Confirmed - $type_label #{$booking['reference_number']}";

            $body = ($booking_type === 'package')
                ? self::buildPackagePaymentConfirmationEmail($booking)
                : self::buildPaymentConfirmationEmail($booking, $booking_type);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendPaymentConfirmation error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendPaymentRejected($booking_id, $booking_type, $pdo, $reason = '', $rejected_by = '') {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking || !$booking['email']) {
                self::$lastError = !$booking ? 'Booking not found.' : 'Guest has no email on file.';
                return false;
            }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $type_label = ucfirst($booking_type);
            $mail->Subject = "❌ Booking Rejected - $type_label #{$booking['reference_number']}";

            $body = ($booking_type === 'package')
                ? self::buildPackagePaymentRejectedEmail($booking, $reason, $rejected_by)
                : self::buildPaymentRejectedEmail($booking, $booking_type, $reason, $rejected_by);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendPaymentRejected error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendBookingCompleted($booking_id, $booking_type, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking || !$booking['email']) return false;

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $type_label = ucfirst($booking_type);
            $mail->Subject = "⭐ $type_label Completed - Share Your Feedback!";

            $body = ($booking_type === 'package')
                ? self::buildPackageBookingCompletedEmail($booking)
                : self::buildBookingCompletedEmail($booking, $booking_type);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendBookingCompleted error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendBookingCancelled($booking_id, $booking_type, $pdo, $reason = '', $cancelled_by = '') {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking || !$booking['email']) {
                self::$lastError = !$booking ? 'Booking not found.' : 'Guest has no email on file.';
                return false;
            }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $type_label = ucfirst($booking_type);
            $mail->Subject = "❌ $type_label Booking Cancelled - #{$booking['reference_number']}";

            $body = ($booking_type === 'package')
                ? self::buildPackageBookingCancelledEmail($booking, $reason, $cancelled_by)
                : self::buildBookingCancelledEmail($booking, $booking_type, $reason, $cancelled_by);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendBookingCancelled error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendPaymentProofAlert($booking_id, $booking_type, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, $booking_type, $pdo);
            if (!$booking) { self::$lastError = 'Booking not found.'; return false; }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();

            $admins = self::getAdminEmails($pdo);
            if (!empty($admins)) {
                foreach ($admins as $admin) {
                    if (!empty($admin['email'])) {
                        $mail->addAddress($admin['email'], $admin['username'] ?: 'Admin');
                    }
                }
            } else {
                $mail->addAddress('johnpaulnavarro0105@gmail.com', 'Admin (Fallback)');
            }

            $type_label = ucfirst($booking_type);
            $mail->Subject = "📷 New Payment Proof - $type_label #{$booking['reference_number']}";

            $body = ($booking_type === 'package')
                ? self::buildPackagePaymentProofAlertEmail($booking)
                : self::buildPaymentProofAlertEmail($booking, $booking_type);

            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendPaymentProofAlert error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendRebookAlert($booking_id, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, 'house', $pdo);
            if (!$booking) { self::$lastError = 'Booking not found.'; return false; }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();

            $admins = self::getAdminEmails($pdo);
            if (!empty($admins)) {
                foreach ($admins as $admin) {
                    if (!empty($admin['email'])) {
                        $mail->addAddress($admin['email'], $admin['username'] ?: 'Admin');
                    }
                }
            } else {
                $mail->addAddress('johnpaulnavarro0105@gmail.com', 'Admin (Fallback)');
            }

            $ref = $booking['reference_number'] ?? $booking_id;
            $mail->Subject = "🔄 Rebook Request - House #$ref Awaiting Confirmation";

            $body = self::buildRebookAlertEmail($booking);
            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendRebookAlert error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendRebookConfirmation($booking_id, $pdo) {
        try {
            $booking = self::getBookingDetails($booking_id, 'house', $pdo);
            if (!$booking || empty($booking['email'])) {
                self::$lastError = !$booking ? 'Booking not found.' : 'Guest has no email on file.';
                return false;
            }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $ref = $booking['reference_number'] ?? $booking_id;
            $mail->Subject = "🎉 Rebook Confirmed! New Dates Locked In - #$ref";

            $body = self::buildRebookConfirmationEmail($booking);
            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendRebookConfirmation error: " . $e->getMessage());
            return false;
        }
    }

    public static function sendRebookRejected($booking_id, $pdo, $reason = '', $rejected_by = '') {
        try {
            $booking = self::getBookingDetails($booking_id, 'house', $pdo);
            if (!$booking || empty($booking['email'])) {
                self::$lastError = !$booking ? 'Booking not found.' : 'Guest has no email on file.';
                return false;
            }

            $mail = MailConfig::getInstance()->getMailer();
            $mail->clearAddresses();
            $mail->addAddress($booking['email'], $booking['guest_name'] ?? $booking['username']);

            $ref = $booking['reference_number'] ?? $booking_id;
            $mail->Subject = "❌ Rebook Request Rejected - #$ref";

            $body = self::buildRebookRejectedEmail($booking, $reason, $rejected_by);
            $mail->Body    = $body;
            $mail->AltBody = self::toPlainText($body);

            $result = $mail->send();
            if (!$result) self::$lastError = $mail->ErrorInfo;
            return $result;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log("sendRebookRejected error: " . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // 🔧 UTILITY
    // ============================================================
    private static function toPlainText($html) {
        $text = str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '<p>', '</tr>', '</td>'],
            ["\n", "\n", "\n", "\n\n", "", "\n", " | "],
            $html
        );
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private static function fmtDate($date) {
        if (empty($date) || $date === '0000-00-00') return 'N/A';
        try {
            return date('l, F j, Y', strtotime($date));
        } catch (Exception $e) {
            return $date;
        }
    }

    private static function fmtDateTime($datetime) {
        if (empty($datetime)) return 'N/A';
        try {
            return date('l, F j, Y \a\t g:i A', strtotime($datetime));
        } catch (Exception $e) {
            return $datetime;
        }
    }

    // ============================================================
    // 🎨 TEMPLATE BUILDERS — HOUSE / TOUR / FOOD
    // ============================================================

    private static function buildBookingConfirmationEmail($booking, $type) {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $total      = number_format((float)($booking['total_amount'] ?? 0), 2);
        $pax        = (int)($booking['number_of_guests'] ?? 1);
        $contact    = htmlspecialchars($booking['contact_number'] ?? $booking['email'] ?? '');
        $notes      = !empty($booking['special_requests']) ? htmlspecialchars($booking['special_requests']) : 'None specified';
        $booked     = self::fmtDateTime($booking['created_at'] ?? null);

        $checkin  = self::fmtDate($booking['check_in_date']  ?? null);
        $checkout = self::fmtDate($booking['check_out_date'] ?? null);

        if ($type !== 'house') {
            $single = self::fmtDate($booking['booking_date'] ?? $booking['preferred_date'] ?? null);
            $checkin = $single;
            $checkout = $single;
        }

        $content = '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #0a1628; border: 1px solid #1e3a5f; border-radius: 12px;">
              <tr>
                <td style="padding: 16px 18px; border-bottom: 1px solid #1e3a5f;">
                  <p style="margin:0; color:#ffffff; font-size: 13px; font-weight: 700; letter-spacing: 0.5px;">
                    📋 OFFICIAL RESERVATION SCHEDULE
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding: 16px 18px;">
                  <p style="margin:0 0 6px 0; color:#64748b; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">
                    Date &amp; Time You Booked:
                  </p>
                  <p style="margin:0; color:#ffffff; font-size: 13.5px; font-weight: 600;">
                    📅 ' . $booked . '
                  </p>
                </td>
              </tr>
            </table>

            <div style="height: 12px;"></div>

            ' . self::datePairBlock('🛬 CHECK-IN SCHEDULE', $checkin, '🛫 CHECK-OUT SCHEDULE', $checkout) . '

            ' . self::infoCardBlock('📋 RESERVATION DETAILS', [
                'Accommodation / Item:'   => $item,
                'Reservation Type:'       => strtoupper($type) . ' Booking',
                'Registered Guests:'      => $pax . ' Pax',
                'Guest Contact:'          => $contact,
                'Special Notes:'          => $notes,
            ]) . '

            ' . self::totalBlock($total, '💳 <strong>GCash Payment Verification:</strong> Please upload your payment receipt screenshot or enter your GCash reference code to finalize confirmation.') . '

            ' . self::noteBlock('<strong>⚠️ Important Check-In Reminders:</strong><br>
                • Please arrive on time for your check-in.<br>
                • Bring a valid government-issued ID.<br>
                • Contact us at least 24 hours before if you need to reschedule.', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '🌴 TRANSIT HOUSE & TOURS',
            'title'         => 'Booking Confirmation',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Thank you for choosing Transient House &amp; Tours! Your reservation details and stay schedule have been successfully registered. Below is your official schedule summary:',
            'content'       => $content,
            'gradient_from' => '#0B2447',
            'gradient_to'   => '#4DA6D9',
        ]);
    }

    private static function buildNewBookingAlertEmail($booking, $type) {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $total      = number_format((float)($booking['total_amount'] ?? 0), 2);
        $pax        = (int)($booking['number_of_guests'] ?? 1);
        $contact    = htmlspecialchars($booking['contact_number'] ?? $booking['email'] ?? '');
        $booked     = self::fmtDateTime($booking['created_at'] ?? null);

        $content = '
            ' . self::infoCardBlock('📋 NEW BOOKING DETAILS', [
                'Guest Name:'      => $guest,
                'Contact:'         => $contact,
                'Booking Type:'    => strtoupper($type) . ' Booking',
                'Item:'            => $item,
                'Number of Pax:'   => $pax . ' Person(s)',
                'Amount:'          => '₱' . $total,
                'Booked On:'       => $booked,
            ]) . '

            ' . self::noteBlock('<strong>Action Required:</strong> A new booking has been submitted and is awaiting admin confirmation. Please log in to <strong>Booking Management</strong> to review the details.', 'amber');

        return self::wrapTemplate([
            'badge_text'    => '🔔 ADMIN NOTIFICATION',
            'title'         => 'New ' . $type_label . ' Booking',
            'ref_code'      => $ref,
            'greeting_name' => 'Admin',
            'intro_text'    => 'A new booking was just submitted and requires your review.',
            'content'       => $content,
            'gradient_from' => '#0B2447',
            'gradient_to'   => '#4DA6D9',
        ]);
    }

    private static function buildPaymentUnderReviewEmail($booking, $type) {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $total      = number_format((float)($booking['total_amount'] ?? 0), 2);
        $gcash      = $booking['gcash_reference'] ?? '';

        $content = '
            ' . self::noteBlock('<strong>What happens next?</strong><br>Our team is now verifying your payment. This usually takes a few hours. You will receive another email once your booking is officially confirmed.', 'blue') . '

            ' . self::gcashBlock($gcash) . '

            ' . self::infoCardBlock('📋 BOOKING DETAILS', [
                'Reference Number:' => $ref,
                $type_label . ':'   => $item,
                'Amount:'           => '₱' . $total,
                'Status:'           => '<span style="color: #38bdf8;">⏳ Pending Confirmation</span>',
            ]) . '

            ' . self::noteBlock('You can check your booking status anytime by logging into your account.', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '⏳ PAYMENT UNDER REVIEW',
            'title'         => 'Payment Received',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'We have received your payment proof. Our team is now verifying the details.',
            'content'       => $content,
            'gradient_from' => '#0369a1',
            'gradient_to'   => '#0ea5e9',
        ]);
    }

    private static function buildPaymentConfirmationEmail($booking, $type) {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $total      = number_format((float)($booking['total_amount'] ?? 0), 2);
        $gcash      = $booking['gcash_reference'] ?? '';

        $rows = [
            'Item:'             => $item,
            'Reference:'        => $ref,
            'Amount Paid:'      => '₱' . $total,
            'Payment Status:'   => '<span style="color: #38bdf8;">✅ PAID</span>',
        ];

        $content = '';
        if (!empty($gcash)) {
            $content .= self::gcashBlock($gcash);
        }

        $content .= self::infoCardBlock('📋 PAYMENT DETAILS', $rows);

        $content .= self::noteBlock('Your booking is now confirmed. We look forward to hosting you! 🌴', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '✅ PAYMENT CONFIRMED',
            'title'         => 'Payment Confirmed!',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Great news! Your payment has been successfully confirmed.',
            'content'       => $content,
            'gradient_from' => '#0284c7',
            'gradient_to'   => '#38bdf8',
        ]);
    }

    private static function buildPaymentRejectedEmail($booking, $type, $reason = '', $rejected_by = '') {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $total      = number_format((float)($booking['total_amount'] ?? 0), 2);

        if (empty($reason)) $reason = 'Payment proof could not be verified.';

        $reason_html      = htmlspecialchars($reason);
        $rejected_by_html = !empty($rejected_by) ? htmlspecialchars($rejected_by) : 'Administrator';

        $content = '
            ' . self::noteBlock(
                '<strong>❌ Reason for Rejection:</strong><br>' . $reason_html .
                '<br><br><span style="color: #94a3b8; font-size: 11.5px;">' .
                '<strong>Rejected by:</strong> ' . $rejected_by_html .
                '</span>',
                'red'
            ) . '

            ' . self::infoCardBlock('📋 BOOKING DETAILS', [
                'Item:'             => $item,
                'Reference:'        => $ref,
                'Amount:'           => '₱' . $total,
                'Status:'           => '<span style="color: #f87171;">⚠️ Booking Removed</span>',
            ]) . '

            ' . self::noteBlock(
                '<strong>What you can do:</strong><br>' .
                'If you believe this is a mistake, please contact us immediately. ' .
                'Otherwise, you may submit a new booking with a valid payment proof.',
                'blue'
            );

        return self::wrapTemplate([
            'badge_text'    => '❌ BOOKING REJECTED',
            'title'         => 'Booking Rejected',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'We regret to inform you that your ' . strtolower($type_label) . ' booking has been rejected. Please see the reason below.',
            'content'       => $content,
            'gradient_from' => '#7f1d1d',
            'gradient_to'   => '#dc2626',
        ]);
    }

    private static function buildBookingCompletedEmail($booking, $type) {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';

        $content = '
            ' . self::noteBlock('<strong>⭐ We\'d love your feedback!</strong><br>Your opinion helps us improve our services and helps other guests make informed decisions.', 'amber') . '

            ' . self::infoCardBlock('📋 BOOKING DETAILS', [
                'Item:'             => $item,
                'Reference:'        => $ref,
                'Status:'           => '<span style="color: #38bdf8;">✅ Completed</span>',
            ]) . '

            ' . self::noteBlock('Log in to your account to leave a rating and review for <strong>' . $item . '</strong>.', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '⭐ BOOKING COMPLETED',
            'title'         => 'How Was Your Experience?',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Thank you for staying with us! Your ' . strtolower($type_label) . ' booking has been completed.',
            'content'       => $content,
            'gradient_from' => '#0369a1',
            'gradient_to'   => '#38bdf8',
        ]);
    }

    private static function buildBookingCancelledEmail($booking, $type, $reason = '', $cancelled_by = '') {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';

        if (empty($reason)) $reason = 'Cancellation requested.';

        $reason_html       = htmlspecialchars($reason);
        $cancelled_by_html = !empty($cancelled_by) ? htmlspecialchars($cancelled_by) : 'Administrator';

        $content = '
            ' . self::noteBlock(
                '<strong>❌ Reason for Cancellation:</strong><br>' . $reason_html .
                '<br><br><span style="color: #94a3b8; font-size: 11.5px;">' .
                '<strong>Cancelled by:</strong> ' . $cancelled_by_html .
                '</span>',
                'red'
            ) . '

            ' . self::infoCardBlock('📋 CANCELLED BOOKING', [
                'Item:'             => $item,
                'Reference:'        => $ref,
                'Status:'           => '<span style="color: #f87171;">❌ Cancelled</span>',
            ]) . '

            ' . self::noteBlock('If you did not request this cancellation or have questions, please contact us immediately.', 'red') . '

            ' . self::noteBlock('Log in to your account to browse other available bookings.', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '❌ BOOKING CANCELLED',
            'title'         => 'Booking Cancelled',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Your ' . strtolower($type_label) . ' booking has been cancelled.',
            'content'       => $content,
            'gradient_from' => '#7f1d1d',
            'gradient_to'   => '#dc2626',
        ]);
    }

    private static function buildPaymentProofAlertEmail($booking, $type) {
        $type_label = ucfirst($type);
        $item       = htmlspecialchars($booking['item_name'] ?? 'N/A');
        $ref        = $booking['reference_number'] ?? 'N/A';
        $guest      = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $total      = number_format((float)($booking['total_amount'] ?? 0), 2);
        $gcash      = $booking['gcash_reference'] ?? '';

        $content = self::gcashBlock($gcash);

        $content .= self::infoCardBlock('📋 BOOKING DETAILS', [
            'Guest:'            => $guest,
            $type_label . ':'   => $item,
            'Reference:'        => $ref,
            'Amount:'           => '₱' . $total,
        ]);

        $content .= self::noteBlock('<strong>⚠️ Verify the GCash reference number matches the screenshot</strong> before confirming the payment. Log in to <strong>Booking Management</strong> to review this payment.', 'amber');

        return self::wrapTemplate([
            'badge_text'    => '📷 PAYMENT PROOF ALERT',
            'title'         => 'New Proof Uploaded',
            'ref_code'      => $ref,
            'greeting_name' => 'Admin',
            'intro_text'    => 'A guest has uploaded payment proof for review.',
            'content'       => $content,
            'gradient_from' => '#0B2447',
            'gradient_to'   => '#4DA6D9',
        ]);
    }

    private static function buildRebookAlertEmail($booking) {
        $ref     = $booking['reference_number'] ?? 'N/A';
        $house   = htmlspecialchars($booking['item_name'] ?? 'House');
        $guest   = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $new_dates = self::fmtDate($booking['check_in_date'] ?? null) . ' → ' . self::fmtDate($booking['check_out_date'] ?? null);
        $old_dates = (!empty($booking['previous_check_in_date']) && !empty($booking['previous_check_out_date']))
            ? self::fmtDate($booking['previous_check_in_date']) . ' → ' . self::fmtDate($booking['previous_check_out_date'])
            : 'Previous dates unavailable';
        $pax = (int)($booking['number_of_guests'] ?? 1);
        $rebook_count = (int)($booking['rebook_count'] ?? 1);

        $content = '
            ' . self::noteBlock('<strong>Action Required:</strong> The guest\'s payment is already <strong>PAID</strong>. Please open Booking Management to confirm and lock in their new dates.', 'amber') . '

            ' . self::datePairBlock('📅 PREVIOUS DATES', '<span style="text-decoration: line-through; color: #94a3b8;">' . $old_dates . '</span>', '✨ REQUESTED NEW DATES', '<span style="color: #60a5fa;">' . $new_dates . '</span>') . '

            ' . self::infoCardBlock('📋 REBOOK DETAILS', [
                'Guest Name:'       => $guest,
                'House:'            => $house,
                'Number of Pax:'    => $pax . ' Person(s)',
                'Rebook Count:'     => '#' . $rebook_count . ' of 2',
            ]) . '

            ' . self::noteBlock('Log in to <strong>Booking Management → Rebookings Tab</strong> and click <strong>"Confirm Rebook"</strong> to finalize.', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '🔄 REBOOK REQUEST',
            'title'         => 'Rebook Awaiting Confirmation',
            'ref_code'      => $ref,
            'greeting_name' => 'Admin',
            'intro_text'    => 'A guest has updated their stay dates on an already-paid booking.',
            'content'       => $content,
            'gradient_from' => '#0B2447',
            'gradient_to'   => '#4DA6D9',
        ]);
    }

    private static function buildRebookConfirmationEmail($booking) {
        $ref   = $booking['reference_number'] ?? 'N/A';
        $house = htmlspecialchars($booking['item_name'] ?? 'House');
        $guest = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $checkin  = self::fmtDate($booking['check_in_date']  ?? null);
        $checkout = self::fmtDate($booking['check_out_date'] ?? null);
        $pax  = (int)($booking['number_of_guests'] ?? 1);

        $content = '
            ' . self::noteBlock('<strong>🎉 Your new stay dates are officially locked in!</strong><br>Our management has confirmed your rebook. Your payment remains valid.', 'blue') . '

            ' . self::datePairBlock('🛬 NEW CHECK-IN', $checkin, '🛫 NEW CHECK-OUT', $checkout) . '

            ' . self::infoCardBlock('📋 REBOOK DETAILS', [
                'House:'            => $house,
                'Number of Pax:'    => $pax . ' Person(s)',
                'Payment Status:'   => '<span style="color: #38bdf8;">✅ PAID</span>',
                'Booking Status:'   => '<span style="color: #38bdf8;">✅ CONFIRMED</span>',
            ]) . '

            ' . self::noteBlock('We look forward to hosting you on your updated dates! 🌴', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '🎉 REBOOK CONFIRMED',
            'title'         => 'Rebook Confirmed!',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Great news! Your rebooked dates have been confirmed by our management.',
            'content'       => $content,
            'gradient_from' => '#0284c7',
            'gradient_to'   => '#38bdf8',
        ]);
    }

    private static function buildRebookRejectedEmail($booking, $reason = '', $rejected_by = '') {
        $ref   = $booking['reference_number'] ?? 'N/A';
        $house = htmlspecialchars($booking['item_name'] ?? 'House');
        $guest = $booking['guest_name'] ?? $booking['username'] ?? 'Guest';
        $pax   = (int)($booking['number_of_guests'] ?? 1);

        $restored_checkin  = self::fmtDate($booking['check_in_date']  ?? null);
        $restored_checkout = self::fmtDate($booking['check_out_date'] ?? null);

        if (empty($reason)) $reason = 'Rebook request could not be approved.';

        $reason_html      = htmlspecialchars($reason);
        $rejected_by_html = !empty($rejected_by) ? htmlspecialchars($rejected_by) : 'Administrator';

        $content = '
            ' . self::noteBlock(
                '<strong>❌ Reason for Rejection:</strong><br>' . $reason_html .
                '<br><br><span style="color: #94a3b8; font-size: 11.5px;">' .
                '<strong>Rejected by:</strong> ' . $rejected_by_html .
                '</span>',
                'red'
            ) . '

            ' . self::noteBlock(
                '<strong>✅ Your original booking dates remain active.</strong><br>' .
                'The rebook request has been declined, so your stay will proceed on the original schedule below.',
                'blue'
            ) . '

            ' . self::datePairBlock(
                '🛬 ORIGINAL CHECK-IN',
                $restored_checkin,
                '🛫 ORIGINAL CHECK-OUT',
                $restored_checkout
            ) . '

            ' . self::infoCardBlock('📋 BOOKING DETAILS', [
                'House:'            => $house,
                'Reference:'        => $ref,
                'Number of Pax:'    => $pax . ' Person(s)',
                'Payment Status:'   => '<span style="color: #38bdf8;">✅ PAID</span>',
                'Booking Status:'   => '<span style="color: #94a3b8;">⚠️ Rebook Declined — Original Dates Restored</span>',
            ]) . '

            ' . self::noteBlock(
                '<strong>What you can do:</strong><br>' .
                '• Your original booking is still valid — you can proceed as planned.<br>' .
                '• If you have questions about this decision, please contact us.<br>' .
                '• You may submit a new rebook request if you still need to change dates (subject to approval).',
                'amber'
            );

        return self::wrapTemplate([
            'badge_text'    => '❌ REBOOK REJECTED',
            'title'         => 'Rebook Request Declined',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'We regret to inform you that your rebook request has been declined. Your original booking dates have been restored.',
            'content'       => $content,
            'gradient_from' => '#7f1d1d',
            'gradient_to'   => '#dc2626',
        ]);
    }

    // ============================================================
    // ✅✅✅ PACKAGE TEMPLATE BUILDERS
    // ============================================================

    private static function buildPackageBookingConfirmationEmail($pkg) {
        $ref    = $pkg['reference_number'] ?? 'N/A';
        $guest  = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';
        $total  = number_format((float)($pkg['grand_total'] ?? 0), 2);
        $booked = self::fmtDateTime($pkg['created_at'] ?? null);
        $contact = htmlspecialchars($pkg['contact_number'] ?? $pkg['email'] ?? '');

        $content = '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #0a1628; border: 1px solid #1e3a5f; border-radius: 12px;">
              <tr><td style="padding: 16px 18px; border-bottom: 1px solid #1e3a5f;">
                <p style="margin:0; color:#ffffff; font-size: 13px; font-weight: 700;">📋 OFFICIAL PACKAGE SCHEDULE</p>
              </td></tr>
              <tr><td style="padding: 16px 18px;">
                <p style="margin:0 0 6px 0; color:#64748b; font-size: 11px; font-weight: 700; text-transform: uppercase;">Date &amp; Time You Booked:</p>
                <p style="margin:0; color:#ffffff; font-size: 13.5px; font-weight: 600;">📅 ' . $booked . '</p>
              </td></tr>
            </table>

            <div style="height: 12px;"></div>

            ' . self::infoCardBlock('📋 RESERVATION DETAILS', [
                'Package Type:'     => 'BUNDLE PACKAGE',
                'Guest Contact:'    => $contact,
            ]) . '

            <p style="margin: 20px 0 0 0; color:#ffffff; font-size: 13px; font-weight: 700;">📦 Package Items Included</p>

            ' . self::packageItemsBlock($pkg) . '

            ' . self::totalBlock($total, '💳 <strong>GCash Payment Verification:</strong> Please upload your payment receipt screenshot or enter your GCash reference code to finalize confirmation.') . '

            ' . self::noteBlock('<strong>⚠️ Important Reminders:</strong><br>
                • Please arrive on time for each scheduled item.<br>
                • Bring a valid government-issued ID.<br>
                • Contact us at least 24 hours before if you need to reschedule.', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '🌴 TRANSIT HOUSE & TOURS',
            'title'         => 'Package Booking Confirmation',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Thank you for choosing Transient House &amp; Tours! Your package booking has been successfully registered. Below are your bundled items:',
            'content'       => $content,
            'gradient_from' => '#0B2447',
            'gradient_to'   => '#4DA6D9',
        ]);
    }

    private static function buildPackageNewBookingAlertEmail($pkg) {
        $ref    = $pkg['reference_number'] ?? 'N/A';
        $guest  = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';
        $total  = number_format((float)($pkg['grand_total'] ?? 0), 2);
        $booked = self::fmtDateTime($pkg['created_at'] ?? null);
        $contact = htmlspecialchars($pkg['contact_number'] ?? $pkg['email'] ?? '');

        $itemCount = 0;
        if (!empty($pkg['house_name'])) $itemCount++;
        if (!empty($pkg['tour_name']))  $itemCount++;
        if (!empty($pkg['food_name']))  $itemCount++;

        $content = '
            ' . self::infoCardBlock('📋 NEW PACKAGE BOOKING', [
                'Guest Name:'      => $guest,
                'Contact:'         => $contact,
                'Booking Type:'    => 'PACKAGE BOOKING',
                'Items Included:'  => $itemCount . ' item(s)',
                'Amount:'          => '₱' . $total,
                'Booked On:'       => $booked,
            ]) . '

            <p style="margin: 20px 0 0 0; color:#ffffff; font-size: 13px; font-weight: 700;">📦 Bundle Contents</p>

            ' . self::packageItemsBlock($pkg) . '

            ' . self::noteBlock('<strong>Action Required:</strong> A new package booking was just submitted and awaits admin confirmation. Log in to <strong>Booking Management</strong> to review.', 'amber');

        return self::wrapTemplate([
            'badge_text'    => '🔔 ADMIN NOTIFICATION',
            'title'         => 'New Package Booking',
            'ref_code'      => $ref,
            'greeting_name' => 'Admin',
            'intro_text'    => 'A new package booking was just submitted and requires your review.',
            'content'       => $content,
            'gradient_from' => '#0B2447',
            'gradient_to'   => '#4DA6D9',
        ]);
    }

    private static function buildPackagePaymentUnderReviewEmail($pkg) {
        $ref   = $pkg['reference_number'] ?? 'N/A';
        $guest = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';
        $total = number_format((float)($pkg['grand_total'] ?? 0), 2);
        $gcash = $pkg['gcash_reference'] ?? '';

        $content = '
            ' . self::noteBlock('<strong>What happens next?</strong><br>Our team is now verifying your package payment. You will receive another email once confirmed.', 'blue') . '

            ' . self::gcashBlock($gcash) . '

            ' . self::infoCardBlock('📋 PACKAGE DETAILS', [
                'Reference Number:' => $ref,
                'Total Amount:'     => '₱' . $total,
                'Status:'           => '<span style="color: #38bdf8;">⏳ Pending Confirmation</span>',
            ]) . '

            <p style="margin: 20px 0 0 0; color:#ffffff; font-size: 13px; font-weight: 700;">📦 Bundle Contents</p>

            ' . self::packageItemsBlock($pkg);

        return self::wrapTemplate([
            'badge_text'    => '⏳ PAYMENT UNDER REVIEW',
            'title'         => 'Package Payment Received',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'We have received your payment proof for your package booking.',
            'content'       => $content,
            'gradient_from' => '#0369a1',
            'gradient_to'   => '#0ea5e9',
        ]);
    }

    private static function buildPackagePaymentConfirmationEmail($pkg) {
        $ref   = $pkg['reference_number'] ?? 'N/A';
        $guest = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';
        $total = number_format((float)($pkg['grand_total'] ?? 0), 2);
        $gcash = $pkg['gcash_reference'] ?? '';

        $content = '';
        if (!empty($gcash)) {
            $content .= self::gcashBlock($gcash);
        }

        $content .= self::infoCardBlock('📋 PACKAGE PAYMENT DETAILS', [
            'Reference:'        => $ref,
            'Amount Paid:'      => '₱' . $total,
            'Payment Status:'   => '<span style="color: #38bdf8;">✅ PAID</span>',
            'Booking Status:'   => '<span style="color: #38bdf8;">✅ CONFIRMED</span>',
        ]);

        $content .= '<p style="margin: 20px 0 0 0; color:#ffffff; font-size: 13px; font-weight: 700;">📦 Your Package Contents</p>';
        $content .= self::packageItemsBlock($pkg);

        $content .= self::noteBlock('Your package is now fully confirmed. We look forward to hosting you! 🌴', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '✅ PACKAGE CONFIRMED',
            'title'         => 'Package Payment Confirmed!',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Great news! Your package payment has been confirmed and your booking is now locked in.',
            'content'       => $content,
            'gradient_from' => '#0284c7',
            'gradient_to'   => '#38bdf8',
        ]);
    }

    private static function buildPackagePaymentRejectedEmail($pkg, $reason = '', $rejected_by = '') {
        $ref   = $pkg['reference_number'] ?? 'N/A';
        $guest = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';
        $total = number_format((float)($pkg['grand_total'] ?? 0), 2);

        if (empty($reason)) $reason = 'Payment proof could not be verified.';

        $reason_html      = htmlspecialchars($reason);
        $rejected_by_html = !empty($rejected_by) ? htmlspecialchars($rejected_by) : 'Administrator';

        $content = '
            ' . self::noteBlock(
                '<strong>❌ Reason for Rejection:</strong><br>' . $reason_html .
                '<br><br><span style="color: #94a3b8; font-size: 11.5px;">' .
                '<strong>Rejected by:</strong> ' . $rejected_by_html .
                '</span>',
                'red'
            ) . '

            ' . self::infoCardBlock('📋 PACKAGE DETAILS', [
                'Reference:'        => $ref,
                'Amount:'           => '₱' . $total,
                'Status:'           => '<span style="color: #f87171;">⚠️ Booking Removed</span>',
            ]) . '

            ' . self::noteBlock(
                '<strong>What you can do:</strong><br>' .
                'If you believe this is a mistake, please contact us immediately. ' .
                'Otherwise, you may submit a new package booking with a valid payment proof.',
                'blue'
            );

        return self::wrapTemplate([
            'badge_text'    => '❌ PACKAGE REJECTED',
            'title'         => 'Package Booking Rejected',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'We regret to inform you that your package booking has been rejected. Please see the reason below.',
            'content'       => $content,
            'gradient_from' => '#7f1d1d',
            'gradient_to'   => '#dc2626',
        ]);
    }

    private static function buildPackageBookingCompletedEmail($pkg) {
        $ref   = $pkg['reference_number'] ?? 'N/A';
        $guest = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';

        $content = '
            ' . self::noteBlock('<strong>⭐ We\'d love your feedback!</strong><br>Your opinion helps us improve our package offerings.', 'amber') . '

            ' . self::infoCardBlock('📋 PACKAGE DETAILS', [
                'Reference:'        => $ref,
                'Status:'           => '<span style="color: #38bdf8;">✅ Completed</span>',
            ]) . '

            <p style="margin: 20px 0 0 0; color:#ffffff; font-size: 13px; font-weight: 700;">📦 Items You Experienced</p>

            ' . self::packageItemsBlock($pkg) . '

            ' . self::noteBlock('Log in to your account to leave a rating and review.', 'blue');

        return self::wrapTemplate([
            'badge_text'    => '⭐ PACKAGE COMPLETED',
            'title'         => 'How Was Your Package Experience?',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Thank you for booking our package! We hope you had a wonderful experience.',
            'content'       => $content,
            'gradient_from' => '#0369a1',
            'gradient_to'   => '#38bdf8',
        ]);
    }

    private static function buildPackageBookingCancelledEmail($pkg, $reason = '', $cancelled_by = '') {
        $ref   = $pkg['reference_number'] ?? 'N/A';
        $guest = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';

        if (empty($reason)) $reason = 'Cancellation requested.';

        $reason_html       = htmlspecialchars($reason);
        $cancelled_by_html = !empty($cancelled_by) ? htmlspecialchars($cancelled_by) : 'Administrator';

        $content = '
            ' . self::noteBlock(
                '<strong>❌ Reason for Cancellation:</strong><br>' . $reason_html .
                '<br><br><span style="color: #94a3b8; font-size: 11.5px;">' .
                '<strong>Cancelled by:</strong> ' . $cancelled_by_html .
                '</span>',
                'red'
            ) . '

            ' . self::infoCardBlock('📋 CANCELLED PACKAGE', [
                'Reference:'        => $ref,
                'Status:'           => '<span style="color: #f87171;">❌ Cancelled</span>',
            ]) . '

            <p style="margin: 20px 0 0 0; color:#ffffff; font-size: 13px; font-weight: 700;">📦 Cancelled Items</p>

            ' . self::packageItemsBlock($pkg) . '

            ' . self::noteBlock('If you did not request this cancellation or have questions, please contact us immediately.', 'red');

        return self::wrapTemplate([
            'badge_text'    => '❌ PACKAGE CANCELLED',
            'title'         => 'Package Booking Cancelled',
            'ref_code'      => $ref,
            'greeting_name' => $guest,
            'intro_text'    => 'Your package booking has been cancelled.',
            'content'       => $content,
            'gradient_from' => '#7f1d1d',
            'gradient_to'   => '#dc2626',
        ]);
    }

    private static function buildPackagePaymentProofAlertEmail($pkg) {
        $ref   = $pkg['reference_number'] ?? 'N/A';
        $guest = $pkg['guest_name'] ?? $pkg['username'] ?? 'Guest';
        $total = number_format((float)($pkg['grand_total'] ?? 0), 2);
        $gcash = $pkg['gcash_reference'] ?? '';

        $content = self::gcashBlock($gcash);

        $content .= self::infoCardBlock('📋 PACKAGE BOOKING DETAILS', [
            'Guest:'            => $guest,
            'Reference:'        => $ref,
            'Amount:'           => '₱' . $total,
        ]);

        $content .= '<p style="margin: 20px 0 0 0; color:#ffffff; font-size: 13px; font-weight: 700;">📦 Bundle Contents</p>';
        $content .= self::packageItemsBlock($pkg);

        $content .= self::noteBlock('<strong>⚠️ Verify the GCash reference number matches the screenshot</strong> before confirming. Log in to <strong>Booking Management</strong>.', 'amber');

        return self::wrapTemplate([
            'badge_text'    => '📷 PACKAGE PAYMENT PROOF',
            'title'         => 'New Package Payment Proof',
            'ref_code'      => $ref,
            'greeting_name' => 'Admin',
            'intro_text'    => 'A guest has uploaded payment proof for a package booking.',
            'content'       => $content,
            'gradient_from' => '#0B2447',
            'gradient_to'   => '#4DA6D9',
        ]);
    }
}
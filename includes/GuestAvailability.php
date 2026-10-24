<?php
/**
 * GuestAvailability — read-only month availability for the GUEST booking calendars (green "available" dates).
 *
 * It contains NO availability rules. Every answer comes from WalkInBookingService::availabilityMonth(), which calls
 * AvailabilityService::houseConflict() / foodConflict() — the same calls the booking handlers make — so a date is only
 * painted green when a booking starting there would really be accepted. The booking handlers still re-validate on submit.
 *
 * Request:  GET <page>?ajax=avail_month&type=house|food&id=<item id>&ym=YYYY-MM[&time=HH:MM]
 * Response: {"ok":true,"days":{"YYYY-MM-DD":"past|booked|blocked|outside", ...}}  (days that are FREE are absent)
 *           {"ok":false,"error":"..."}
 * Only dates and a kind label are returned: no references, names or payment data.
 */
require_once __DIR__ . '/AvailabilityService.php';
require_once __DIR__ . '/WalkInBookingService.php';

class GuestAvailability {
    /** Call near the top of a guest page (after database.php). Sends JSON and exits only for ?ajax=avail_month. */
    public static function handle(PDO $pdo) {
        if (($_GET['ajax'] ?? '') !== 'avail_month') return;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $type = (string)($_GET['type'] ?? 'house');
            if (!in_array($type, ['house', 'food'], true)) throw new InvalidArgumentException('Unknown item type.');
            $r = WalkInBookingService::availabilityMonth($pdo, $type, $_GET['id'] ?? 0, (string)($_GET['ym'] ?? ''), ['time' => (string)($_GET['time'] ?? '')]);
            echo json_encode(['ok' => true, 'days' => $r['days']]);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('guest availability failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Availability could not be loaded.']);
        }
        exit();
    }
}

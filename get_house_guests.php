<?php
// Guest names and stay dates for one house — staff/admin only.
// (No page currently calls this endpoint; it is kept for compatibility but
//  never answers without an authenticated admin or staff session.)
ini_set('display_errors', '0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once 'database.php';

// Check if house_id is provided
$house_id = (int)($_GET['house_id'] ?? 0);
if ($house_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'House ID required']);
    exit();
}

try {
    // Get all bookings for this house with guest names
    $stmt = $pdo->prepare("SELECT 
                            b.id, 
                            b.reference_number, 
                            b.check_in_date, 
                            b.check_out_date, 
                            b.number_of_guests, 
                            b.payment_status, 
                            b.booking_status,
                            b.guest_names,
                            g.full_name as guest_name
                           FROM house_bookings b
                           LEFT JOIN guests g ON b.guest_id = g.id
                           WHERE b.house_id = ? 
                           AND b.booking_status != 'cancelled'
                           ORDER BY b.check_in_date DESC");
    $stmt->execute([$house_id]);
    $bookings = $stmt->fetchAll();
    
    $guests = [];
    foreach($bookings as $booking) {
        // Check if guest_names field exists and has data
        if(!empty($booking['guest_names'])) {
            // Try to parse JSON
            $guest_names = $booking['guest_names'];
            if (is_string($guest_names) && ($guest_names[0] == '[' || $guest_names[0] == '{')) {
                try {
                    $decoded = json_decode($guest_names, true);
                    if (is_array($decoded)) {
                        $names = $decoded;
                    } else {
                        $names = [$guest_names];
                    }
                } catch(Exception $e) {
                    // Not JSON, split by newlines or commas
                    if (strpos($guest_names, "\n") !== false) {
                        $names = array_filter(array_map('trim', explode("\n", $guest_names)));
                    } else if (strpos($guest_names, ',') !== false) {
                        $names = array_filter(array_map('trim', explode(',', $guest_names)));
                    } else {
                        $names = [$guest_names];
                    }
                }
            } else if (strpos($guest_names, "\n") !== false) {
                $names = array_filter(array_map('trim', explode("\n", $guest_names)));
            } else if (strpos($guest_names, ',') !== false) {
                $names = array_filter(array_map('trim', explode(',', $guest_names)));
            } else {
                $names = [$guest_names];
            }
            
            foreach($names as $name) {
                if(!empty($name)) {
                    // FORCE format dates as YYYY-MM-DD without timezone conversion
                    $check_in = $booking['check_in_date'];
                    $check_out = $booking['check_out_date'];
                    
                    // If date is in DateTime format, convert to string
                    if ($check_in instanceof DateTime) {
                        $check_in = $check_in->format('Y-m-d');
                    }
                    if ($check_out instanceof DateTime) {
                        $check_out = $check_out->format('Y-m-d');
                    }
                    
                    $guests[] = [
                        'name' => $name,
                        'reference_number' => $booking['reference_number'],
                        'check_in_date' => $check_in,
                        'check_out_date' => $check_out,
                        'payment_status' => $booking['payment_status']
                    ];
                }
            }
        } else {
            // Fallback: use guest_name from guests table
            $check_in = $booking['check_in_date'];
            $check_out = $booking['check_out_date'];
            
            if ($check_in instanceof DateTime) {
                $check_in = $check_in->format('Y-m-d');
            }
            if ($check_out instanceof DateTime) {
                $check_out = $check_out->format('Y-m-d');
            }
            
            $guests[] = [
                'name' => $booking['guest_name'] ?? 'Guest',
                'reference_number' => $booking['reference_number'],
                'check_in_date' => $check_in,
                'check_out_date' => $check_out,
                'payment_status' => $booking['payment_status']
            ];
        }
    }
    
    echo json_encode(['guests' => $guests]);
} catch(Exception $e) {
    error_log('get_house_guests failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load guests.']);
}
?>
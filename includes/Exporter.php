<?php
/**
 * ReportExporter - CSV Export Class
 * Handles all CSV exports for the reservation system
 * 
 * FIXED: Removed feedback_rating and feedback_text columns that don't exist
 * FIXED: Proper error handling for missing tables
 * FIXED: Added better guest names formatting
 */

require_once 'database.php';

class ReportExporter {
    private $pdo;

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }

    /**
     * Download CSV file
     */
    private function downloadCSV($data, $filename, $headers) {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");

        // Write headers
        fputcsv($output, $headers);

        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Format guest names with commas
     */
    private function formatGuestNames($guest_names) {
        if (empty($guest_names)) return '-';
        
        if (is_string($guest_names)) {
            // Try to parse JSON
            if (($guest_names[0] == '[' || $guest_names[0] == '{')) {
                try {
                    $decoded = json_decode($guest_names, true);
                    if (is_array($decoded)) {
                        if (isset($decoded[0]) && is_string($decoded[0])) {
                            return implode(', ', $decoded);
                        }
                        $names = array_values(array_filter($decoded, function($v) {
                            return is_string($v) && !empty($v);
                        }));
                        if (!empty($names)) {
                            return implode(', ', $names);
                        }
                    }
                } catch(Exception $e) {
                    // Not valid JSON, continue
                }
            }
            
            // Split by newlines
            if (strpos($guest_names, "\n") !== false) {
                $names = array_filter(array_map('trim', explode("\n", $guest_names)));
                return implode(', ', $names);
            }
            
            // Split by commas
            if (strpos($guest_names, ',') !== false) {
                $names = array_filter(array_map('trim', explode(',', $guest_names)));
                return implode(', ', $names);
            }
            
            return $guest_names;
        }
        
        return '-';
    }

    /**
     * Export Bookings (House + Tour + Food)
     */
    public function exportBookings($filters = []) {
        $bookings = [];

        // --- House Bookings ---
        $sql = "SELECT 
                    b.id,
                    b.reference_number,
                    'House' as type,
                    h.house_name as item_name,
                    u.username as guest_username,
                    g.full_name as guest_name,
                    g.contact_number as guest_phone,
                    u.email as guest_email,
                    b.check_in_date as start_date,
                    b.check_out_date as end_date,
                    b.number_of_guests as pax,
                    b.total_amount,
                    b.payment_status,
                    b.booking_status,
                    b.created_at as booking_date,
                    b.paid_at as payment_date,
                    IFNULL(b.guest_names, g.full_name) as guest_names
                FROM house_bookings b
                JOIN houses h ON b.house_id = h.id
                JOIN guests g ON b.guest_id = g.id
                JOIN users u ON g.user_id = u.id
                WHERE b.booking_status != 'cancelled'";

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= " AND DATE(b.created_at) BETWEEN :date_from AND :date_to";
        }
        if (!empty($filters['status'])) {
            $sql .= " AND b.booking_status = :status";
        }
        if (!empty($filters['payment'])) {
            $sql .= " AND b.payment_status = :payment";
        }

        $sql .= " ORDER BY b.created_at DESC";

        $stmt = $this->pdo->prepare($sql);

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $stmt->bindValue(':date_from', $filters['date_from']);
            $stmt->bindValue(':date_to', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $stmt->bindValue(':status', $filters['status']);
        }
        if (!empty($filters['payment'])) {
            $stmt->bindValue(':payment', $filters['payment']);
        }

        $stmt->execute();
        $house_bookings = $stmt->fetchAll();
        $bookings = array_merge($bookings, $house_bookings);

        // --- Tour Bookings ---
        $sql = "SELECT 
                    b.id,
                    b.reference_number,
                    'Tour' as type,
                    t.tour_name as item_name,
                    u.username as guest_username,
                    g.full_name as guest_name,
                    g.contact_number as guest_phone,
                    u.email as guest_email,
                    b.booking_date as start_date,
                    b.booking_date as end_date,
                    b.number_of_guests as pax,
                    b.total_amount,
                    b.payment_status,
                    b.booking_status,
                    b.created_at as booking_date,
                    b.paid_at as payment_date,
                    NULL as guest_names
                FROM tour_bookings b
                JOIN tours t ON b.tour_id = t.id
                JOIN guests g ON b.guest_id = g.id
                JOIN users u ON g.user_id = u.id
                WHERE b.booking_status != 'cancelled'";

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= " AND DATE(b.created_at) BETWEEN :date_from AND :date_to";
        }
        if (!empty($filters['status'])) {
            $sql .= " AND b.booking_status = :status";
        }
        if (!empty($filters['payment'])) {
            $sql .= " AND b.payment_status = :payment";
        }

        $sql .= " ORDER BY b.created_at DESC";

        $stmt = $this->pdo->prepare($sql);

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $stmt->bindValue(':date_from', $filters['date_from']);
            $stmt->bindValue(':date_to', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $stmt->bindValue(':status', $filters['status']);
        }
        if (!empty($filters['payment'])) {
            $stmt->bindValue(':payment', $filters['payment']);
        }

        $stmt->execute();
        $tour_bookings = $stmt->fetchAll();
        $bookings = array_merge($bookings, $tour_bookings);

        // --- Food Bookings ---
        try {
            $sql = "SELECT 
                        b.id,
                        b.reference_number,
                        'Food' as type,
                        f.name as item_name,
                        u.username as guest_username,
                        g.full_name as guest_name,
                        g.contact_number as guest_phone,
                        u.email as guest_email,
                        b.created_at as start_date,
                        b.created_at as end_date,
                        b.quantity as pax,
                        b.total_amount,
                        b.payment_status,
                        b.booking_status,
                        b.created_at as booking_date,
                        b.paid_at as payment_date,
                        NULL as guest_names
                    FROM food_bookings b
                    JOIN food_items f ON b.food_id = f.id
                    JOIN guests g ON b.guest_id = g.id
                    JOIN users u ON g.user_id = u.id
                    WHERE b.booking_status != 'cancelled'";

            if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
                $sql .= " AND DATE(b.created_at) BETWEEN :date_from AND :date_to";
            }
            if (!empty($filters['status'])) {
                $sql .= " AND b.booking_status = :status";
            }
            if (!empty($filters['payment'])) {
                $sql .= " AND b.payment_status = :payment";
            }

            $sql .= " ORDER BY b.created_at DESC";

            $stmt = $this->pdo->prepare($sql);

            if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
                $stmt->bindValue(':date_from', $filters['date_from']);
                $stmt->bindValue(':date_to', $filters['date_to']);
            }
            if (!empty($filters['status'])) {
                $stmt->bindValue(':status', $filters['status']);
            }
            if (!empty($filters['payment'])) {
                $stmt->bindValue(':payment', $filters['payment']);
            }

            $stmt->execute();
            $food_bookings = $stmt->fetchAll();
            $bookings = array_merge($bookings, $food_bookings);
        } catch (PDOException $e) {
            // Food bookings table might not exist yet
            error_log("Food bookings export failed: " . $e->getMessage());
        }

        // Sort by date
        usort($bookings, function($a, $b) {
            return strtotime($b['booking_date']) - strtotime($a['booking_date']);
        });

        // Format data for CSV
        $csvData = [];
        foreach ($bookings as $booking) {
            // Format guest names
            $guestNames = $this->formatGuestNames($booking['guest_names'] ?? '-');
            
            $csvData[] = [
                $booking['reference_number'] ?? 'N/A',
                $booking['type'] ?? 'N/A',
                $booking['item_name'] ?? 'N/A',
                $booking['guest_name'] ?? $booking['guest_username'] ?? 'N/A',
                $booking['guest_phone'] ?? 'N/A',
                $booking['guest_email'] ?? 'N/A',
                date('Y-m-d', strtotime($booking['start_date'] ?? $booking['booking_date'])),
                isset($booking['end_date']) && $booking['end_date'] != $booking['start_date'] ? date('Y-m-d', strtotime($booking['end_date'])) : '-',
                $booking['pax'] ?? 1,
                '₱' . number_format($booking['total_amount'] ?? 0, 2),
                ucfirst($booking['payment_status'] ?? 'pending'),
                ucfirst($booking['booking_status'] ?? 'pending'),
                date('Y-m-d h:i A', strtotime($booking['booking_date'])),
                !empty($booking['payment_date']) ? date('Y-m-d h:i A', strtotime($booking['payment_date'])) : '-',
                '-', // Rating - removed feedback_rating
                '-', // Feedback - removed feedback_text
                $guestNames
            ];
        }

        $headers = [
            'Reference', 'Type', 'Item', 'Guest Name', 'Phone', 'Email',
            'Start Date', 'End Date', 'Pax', 'Total Amount',
            'Payment Status', 'Booking Status', 'Booking Date',
            'Payment Date', 'Rating', 'Feedback', 'Guest Names'
        ];

        $filename = 'bookings_export';
        $this->downloadCSV($csvData, $filename, $headers);
    }

    /**
     * Export Revenue Report
     */
    public function exportRevenue($filters = []) {
        $revenue = [];
        $date_from = $filters['date_from'] ?? date('Y-m-01');
        $date_to = $filters['date_to'] ?? date('Y-m-t');

        // Get date range for grouping
        $start = new DateTime($date_from);
        $end = new DateTime($date_to);
        $end->modify('+1 day');

        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        foreach ($period as $date) {
            $day = $date->format('Y-m-d');
            $revenue[$day] = [
                'date' => $day,
                'houses' => 0,
                'tours' => 0,
                'food' => 0,
                'total' => 0
            ];
        }

        // House Revenue
        $sql = "SELECT DATE(paid_at) as paid_date, COALESCE(SUM(total_amount), 0) as amount
                FROM house_bookings
                WHERE payment_status = 'paid'
                AND paid_at BETWEEN :date_from AND :date_to
                GROUP BY DATE(paid_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':date_from', $date_from . ' 00:00:00');
        $stmt->bindValue(':date_to', $date_to . ' 23:59:59');
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (isset($revenue[$row['paid_date']])) {
                $revenue[$row['paid_date']]['houses'] = (float)$row['amount'];
            }
        }

        // Tour Revenue
        $sql = "SELECT DATE(paid_at) as paid_date, COALESCE(SUM(total_amount), 0) as amount
                FROM tour_bookings
                WHERE payment_status = 'paid'
                AND paid_at BETWEEN :date_from AND :date_to
                GROUP BY DATE(paid_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':date_from', $date_from . ' 00:00:00');
        $stmt->bindValue(':date_to', $date_to . ' 23:59:59');
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (isset($revenue[$row['paid_date']])) {
                $revenue[$row['paid_date']]['tours'] = (float)$row['amount'];
            }
        }

        // Food Revenue
        try {
            $sql = "SELECT DATE(paid_at) as paid_date, COALESCE(SUM(total_amount), 0) as amount
                    FROM food_bookings
                    WHERE payment_status = 'paid'
                    AND paid_at BETWEEN :date_from AND :date_to
                    GROUP BY DATE(paid_at)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':date_from', $date_from . ' 00:00:00');
            $stmt->bindValue(':date_to', $date_to . ' 23:59:59');
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                if (isset($revenue[$row['paid_date']])) {
                    $revenue[$row['paid_date']]['food'] = (float)$row['amount'];
                }
            }
        } catch (PDOException $e) {
            // Food bookings table might not exist
        }

        // Calculate totals
        $grand_total = 0;
        foreach ($revenue as &$day) {
            $day['total'] = $day['houses'] + $day['tours'] + $day['food'];
            $grand_total += $day['total'];
        }

        // Format for CSV
        $csvData = [];
        foreach ($revenue as $day) {
            $csvData[] = [
                date('M d, Y', strtotime($day['date'])),
                '₱' . number_format($day['houses'], 2),
                '₱' . number_format($day['tours'], 2),
                '₱' . number_format($day['food'], 2),
                '₱' . number_format($day['total'], 2)
            ];
        }

        // Add summary row
        $csvData[] = ['---', '---', '---', '---', '---'];
        $csvData[] = [
            'TOTAL',
            '₱' . number_format(array_sum(array_column($revenue, 'houses')), 2),
            '₱' . number_format(array_sum(array_column($revenue, 'tours')), 2),
            '₱' . number_format(array_sum(array_column($revenue, 'food')), 2),
            '₱' . number_format($grand_total, 2)
        ];

        $headers = ['Date', 'House Revenue', 'Tour Revenue', 'Food Revenue', 'Total Revenue'];

        $filename = 'revenue_report_' . $date_from . '_to_' . $date_to;
        $this->downloadCSV($csvData, $filename, $headers);
    }

    /**
     * Export Users
     */
    public function exportUsers($filters = []) {
        $sql = "SELECT 
                    u.id,
                    u.username,
                    u.fullname,
                    u.email,
                    u.role,
                    u.created_at as registration_date,
                    g.contact_number as phone,
                    g.address,
                    g.id_type,
                    g.id_number,
                    g.emergency_contact,
                    g.emergency_number,
                    g.profile_photo
                FROM users u
                LEFT JOIN guests g ON u.id = g.user_id
                WHERE 1=1";

        if (!empty($filters['role'])) {
            $sql .= " AND u.role = :role";
        }

        $sql .= " ORDER BY u.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        if (!empty($filters['role'])) {
            $stmt->bindValue(':role', $filters['role']);
        }
        $stmt->execute();
        $users = $stmt->fetchAll();

        // Get booking counts for each user
        $csvData = [];
        foreach ($users as $user) {
            // Get guest id
            $guest_stmt = $this->pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
            $guest_stmt->execute([$user['id']]);
            $guest = $guest_stmt->fetch();

            $total_bookings = 0;
            $total_spent = 0;

            if ($guest) {
                // House bookings
                $stmt = $this->pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM house_bookings WHERE guest_id = ? AND payment_status = 'paid'");
                $stmt->execute([$guest['id']]);
                $result = $stmt->fetch();
                $total_bookings += $result['count'];
                $total_spent += $result['total'];

                // Tour bookings
                $stmt = $this->pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM tour_bookings WHERE guest_id = ? AND payment_status = 'paid'");
                $stmt->execute([$guest['id']]);
                $result = $stmt->fetch();
                $total_bookings += $result['count'];
                $total_spent += $result['total'];

                // Food bookings
                try {
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM food_bookings WHERE guest_id = ? AND payment_status = 'paid'");
                    $stmt->execute([$guest['id']]);
                    $result = $stmt->fetch();
                    $total_bookings += $result['count'];
                    $total_spent += $result['total'];
                } catch (PDOException $e) {
                    // Food bookings table might not exist
                }
            }

            $csvData[] = [
                $user['id'],
                $user['username'] ?? 'N/A',
                $user['fullname'] ?? 'N/A',
                $user['email'] ?? 'N/A',
                $user['phone'] ?? 'N/A',
                $user['address'] ?? 'N/A',
                ucfirst($user['role'] ?? 'guest'),
                date('M d, Y', strtotime($user['registration_date'])),
                $total_bookings,
                '₱' . number_format($total_spent, 2),
                $user['id_type'] ?? 'N/A',
                $user['id_number'] ?? 'N/A',
                $user['emergency_contact'] ?? 'N/A',
                $user['emergency_number'] ?? 'N/A'
            ];
        }

        $headers = [
            'ID', 'Username', 'Full Name', 'Email', 'Phone', 'Address',
            'Role', 'Registered Date', 'Total Bookings', 'Total Spent',
            'ID Type', 'ID Number', 'Emergency Contact', 'Emergency Number'
        ];

        $filename = 'users_export';
        $this->downloadCSV($csvData, $filename, $headers);
    }

    /**
     * Export Houses
     */
    public function exportHouses() {
        $sql = "SELECT 
                    h.id,
                    h.house_name,
                    h.description,
                    h.price_per_night,
                    h.capacity,
                    h.bedrooms,
                    h.amenities,
                    h.status,
                    h.created_at,
                    (SELECT COUNT(*) FROM house_bookings WHERE house_id = h.id AND booking_status != 'cancelled') as total_bookings,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM house_bookings WHERE house_id = h.id AND payment_status = 'paid') as total_revenue
                FROM houses h
                ORDER BY h.id";

        $stmt = $this->pdo->query($sql);
        $houses = $stmt->fetchAll();

        $csvData = [];
        foreach ($houses as $house) {
            $csvData[] = [
                $house['id'],
                $house['house_name'],
                substr($house['description'] ?? '', 0, 100),
                '₱' . number_format($house['price_per_night'], 2),
                $house['capacity'],
                $house['bedrooms'],
                $house['amenities'] ?? 'N/A',
                ucfirst($house['status']),
                $house['total_bookings'] ?? 0,
                '₱' . number_format($house['total_revenue'] ?? 0, 2),
                date('M d, Y', strtotime($house['created_at']))
            ];
        }

        $headers = [
            'ID', 'House Name', 'Description', 'Price Per Night',
            'Capacity', 'Bedrooms', 'Amenities', 'Status',
            'Total Bookings', 'Total Revenue', 'Created Date'
        ];

        $filename = 'houses_export';
        $this->downloadCSV($csvData, $filename, $headers);
    }

    /**
     * Export Tours
     */
    public function exportTours() {
        $sql = "SELECT 
                    t.id,
                    t.tour_name,
                    t.description,
                    t.price_per_boat,
                    t.max_guests as boat_capacity,
                    t.status,
                    t.created_at,
                    (SELECT COUNT(*) FROM tour_bookings WHERE tour_id = t.id AND booking_status != 'cancelled') as total_bookings,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM tour_bookings WHERE tour_id = t.id AND payment_status = 'paid') as total_revenue
                FROM tours t
                ORDER BY t.id";

        $stmt = $this->pdo->query($sql);
        $tours = $stmt->fetchAll();

        $csvData = [];
        foreach ($tours as $tour) {
            $csvData[] = [
                $tour['id'],
                $tour['tour_name'],
                substr($tour['description'] ?? '', 0, 100),
                '₱' . number_format($tour['price_per_boat'], 2),
                $tour['boat_capacity'],
                ucfirst($tour['status']),
                $tour['total_bookings'] ?? 0,
                '₱' . number_format($tour['total_revenue'] ?? 0, 2),
                date('M d, Y', strtotime($tour['created_at']))
            ];
        }

        $headers = [
            'ID', 'Tour Name', 'Description', 'Price Per Boat',
            'Boat Capacity', 'Status', 'Total Bookings',
            'Total Revenue', 'Created Date'
        ];

        $filename = 'tours_export';
        $this->downloadCSV($csvData, $filename, $headers);
    }

    /**
     * Export Food Items
     */
    public function exportFood() {
        try {
            $sql = "SELECT 
                        f.id,
                        f.name,
                        f.description,
                        f.price,
                        f.category,
                        f.is_available,
                        f.is_featured,
                        f.pax_range,
                        f.inclusions,
                        f.created_at,
                        (SELECT COUNT(*) FROM food_bookings WHERE food_id = f.id AND booking_status != 'cancelled') as total_orders,
                        (SELECT COALESCE(SUM(total_amount), 0) FROM food_bookings WHERE food_id = f.id AND payment_status = 'paid') as total_revenue
                    FROM food_items f
                    ORDER BY f.category, f.name";

            $stmt = $this->pdo->query($sql);
            $food_items = $stmt->fetchAll();

            $csvData = [];
            foreach ($food_items as $food) {
                $csvData[] = [
                    $food['id'],
                    $food['name'],
                    substr($food['description'] ?? '', 0, 100),
                    '₱' . number_format($food['price'], 2),
                    ucfirst(str_replace('_', ' ', $food['category'])),
                    $food['pax_range'] ?? 'N/A',
                    $food['is_available'] ? 'Yes' : 'No',
                    $food['is_featured'] ? 'Yes' : 'No',
                    $food['total_orders'] ?? 0,
                    '₱' . number_format($food['total_revenue'] ?? 0, 2),
                    date('M d, Y', strtotime($food['created_at']))
                ];
            }

            $headers = [
                'ID', 'Name', 'Description', 'Price', 'Category',
                'PAX Range', 'Available', 'Featured',
                'Total Orders', 'Total Revenue', 'Created Date'
            ];

            $filename = 'food_export';
            $this->downloadCSV($csvData, $filename, $headers);
        } catch (PDOException $e) {
            // Food items table might not exist
            $this->downloadCSV([['No food data available']], 'food_export', ['Message']);
        }
    }

    /**
     * Export Feedback
     */
    public function exportFeedback($filters = []) {
        $sql = "SELECT 
                    f.id,
                    f.rating,
                    f.comment,
                    f.created_at,
                    f.is_anonymous,
                    u.username,
                    u.fullname,
                    u.email,
                    u.role
                FROM overall_feedback f
                JOIN users u ON f.user_id = u.id
                WHERE 1=1";

        if (!empty($filters['rating'])) {
            $sql .= " AND f.rating = :rating";
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= " AND DATE(f.created_at) BETWEEN :date_from AND :date_to";
        }

        $sql .= " ORDER BY f.created_at DESC";

        $stmt = $this->pdo->prepare($sql);

        if (!empty($filters['rating'])) {
            $stmt->bindValue(':rating', $filters['rating']);
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $stmt->bindValue(':date_from', $filters['date_from']);
            $stmt->bindValue(':date_to', $filters['date_to']);
        }

        $stmt->execute();
        $feedback = $stmt->fetchAll();

        $csvData = [];
        foreach ($feedback as $row) {
            $csvData[] = [
                $row['id'],
                $row['is_anonymous'] ? 'Anonymous' : ($row['username'] ?? 'N/A'),
                $row['fullname'] ?? $row['username'] ?? 'N/A',
                $row['email'] ?? 'N/A',
                $row['rating'] . '/5',
                substr(strip_tags($row['comment'] ?? ''), 0, 200),
                date('M d, Y h:i A', strtotime($row['created_at'])),
                $row['is_anonymous'] ? 'Yes' : 'No'
            ];
        }

        $headers = [
            'ID', 'Username', 'Full Name', 'Email',
            'Rating', 'Comment', 'Created Date', 'Anonymous'
        ];

        $filename = 'feedback_export';
        $this->downloadCSV($csvData, $filename, $headers);
    }

    /**
     * Export Activities
     */
    public function exportActivities() {
        $sql = "SELECT 
                    id,
                    name,
                    description,
                    category,
                    price,
                    price_unit,
                    price_note,
                    status,
                    is_featured,
                    created_at
                FROM activities
                ORDER BY category, name";

        $stmt = $this->pdo->query($sql);
        $activities = $stmt->fetchAll();

        $csvData = [];
        foreach ($activities as $activity) {
            $csvData[] = [
                $activity['id'],
                $activity['name'],
                $activity['category'] ?? 'Other',
                substr($activity['description'] ?? '', 0, 100),
                '₱' . number_format($activity['price'], 2),
                $activity['price_unit'] ?? 'N/A',
                $activity['price_note'] ?? 'N/A',
                ucfirst($activity['status']),
                $activity['is_featured'] ? 'Yes' : 'No',
                date('M d, Y', strtotime($activity['created_at']))
            ];
        }

        $headers = [
            'ID', 'Name', 'Category', 'Description',
            'Price', 'Price Unit', 'Price Note',
            'Status', 'Featured', 'Created Date'
        ];

        $filename = 'activities_export';
        $this->downloadCSV($csvData, $filename, $headers);
    }

    // ============================================================
    // DATA RETRIEVAL METHODS FOR PREVIEW
    // ============================================================

    /**
     * Get Bookings Data for Preview - FIXED
     */
    public function getBookingsData($filters = []) {
        $bookings = [];

        // --- House Bookings ---
        $sql = "SELECT 
                    b.reference_number,
                    'House' as type,
                    h.house_name as item_name,
                    u.username as guest_username,
                    g.full_name as guest_name,
                    g.contact_number as guest_phone,
                    u.email as guest_email,
                    b.check_in_date as start_date,
                    b.check_out_date as end_date,
                    b.number_of_guests as pax,
                    b.total_amount,
                    b.payment_status,
                    b.booking_status,
                    b.created_at as booking_date,
                    b.paid_at as payment_date,
                    IFNULL(b.guest_names, g.full_name) as guest_names
                FROM house_bookings b
                JOIN houses h ON b.house_id = h.id
                JOIN guests g ON b.guest_id = g.id
                JOIN users u ON g.user_id = u.id
                WHERE b.booking_status != 'cancelled'";

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= " AND DATE(b.created_at) BETWEEN :date_from AND :date_to";
        }
        if (!empty($filters['status'])) {
            $sql .= " AND b.booking_status = :status";
        }
        if (!empty($filters['payment'])) {
            $sql .= " AND b.payment_status = :payment";
        }

        $sql .= " ORDER BY b.created_at DESC";

        $stmt = $this->pdo->prepare($sql);

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $stmt->bindValue(':date_from', $filters['date_from']);
            $stmt->bindValue(':date_to', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $stmt->bindValue(':status', $filters['status']);
        }
        if (!empty($filters['payment'])) {
            $stmt->bindValue(':payment', $filters['payment']);
        }

        $stmt->execute();
        $house_bookings = $stmt->fetchAll();
        $bookings = array_merge($bookings, $house_bookings);

        // --- Tour Bookings ---
        $sql = "SELECT 
                    b.reference_number,
                    'Tour' as type,
                    t.tour_name as item_name,
                    u.username as guest_username,
                    g.full_name as guest_name,
                    g.contact_number as guest_phone,
                    u.email as guest_email,
                    b.booking_date as start_date,
                    b.booking_date as end_date,
                    b.number_of_guests as pax,
                    b.total_amount,
                    b.payment_status,
                    b.booking_status,
                    b.created_at as booking_date,
                    b.paid_at as payment_date,
                    NULL as guest_names
                FROM tour_bookings b
                JOIN tours t ON b.tour_id = t.id
                JOIN guests g ON b.guest_id = g.id
                JOIN users u ON g.user_id = u.id
                WHERE b.booking_status != 'cancelled'";

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= " AND DATE(b.created_at) BETWEEN :date_from AND :date_to";
        }
        if (!empty($filters['status'])) {
            $sql .= " AND b.booking_status = :status";
        }
        if (!empty($filters['payment'])) {
            $sql .= " AND b.payment_status = :payment";
        }

        $sql .= " ORDER BY b.created_at DESC";

        $stmt = $this->pdo->prepare($sql);

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $stmt->bindValue(':date_from', $filters['date_from']);
            $stmt->bindValue(':date_to', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $stmt->bindValue(':status', $filters['status']);
        }
        if (!empty($filters['payment'])) {
            $stmt->bindValue(':payment', $filters['payment']);
        }

        $stmt->execute();
        $tour_bookings = $stmt->fetchAll();
        $bookings = array_merge($bookings, $tour_bookings);

        // --- Food Bookings ---
        try {
            $sql = "SELECT 
                        b.reference_number,
                        'Food' as type,
                        f.name as item_name,
                        u.username as guest_username,
                        g.full_name as guest_name,
                        g.contact_number as guest_phone,
                        u.email as guest_email,
                        b.created_at as start_date,
                        b.created_at as end_date,
                        b.quantity as pax,
                        b.total_amount,
                        b.payment_status,
                        b.booking_status,
                        b.created_at as booking_date,
                        b.paid_at as payment_date,
                        NULL as guest_names
                    FROM food_bookings b
                    JOIN food_items f ON b.food_id = f.id
                    JOIN guests g ON b.guest_id = g.id
                    JOIN users u ON g.user_id = u.id
                    WHERE b.booking_status != 'cancelled'";

            if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
                $sql .= " AND DATE(b.created_at) BETWEEN :date_from AND :date_to";
            }
            if (!empty($filters['status'])) {
                $sql .= " AND b.booking_status = :status";
            }
            if (!empty($filters['payment'])) {
                $sql .= " AND b.payment_status = :payment";
            }

            $sql .= " ORDER BY b.created_at DESC";

            $stmt = $this->pdo->prepare($sql);

            if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
                $stmt->bindValue(':date_from', $filters['date_from']);
                $stmt->bindValue(':date_to', $filters['date_to']);
            }
            if (!empty($filters['status'])) {
                $stmt->bindValue(':status', $filters['status']);
            }
            if (!empty($filters['payment'])) {
                $stmt->bindValue(':payment', $filters['payment']);
            }

            $stmt->execute();
            $food_bookings = $stmt->fetchAll();
            $bookings = array_merge($bookings, $food_bookings);
        } catch (PDOException $e) {
            // Food bookings table might not exist yet
            error_log("Food bookings preview failed: " . $e->getMessage());
        }

        // Sort by date
        usort($bookings, function($a, $b) {
            return strtotime($b['booking_date']) - strtotime($a['booking_date']);
        });

        // Format data for preview
        $csvData = [];
        foreach ($bookings as $booking) {
            // Format guest names with commas
            $guestNames = $this->formatGuestNames($booking['guest_names'] ?? '-');
            
            $csvData[] = [
                $booking['reference_number'] ?? 'N/A',
                $booking['type'] ?? 'N/A',
                $booking['item_name'] ?? 'N/A',
                $booking['guest_name'] ?? $booking['guest_username'] ?? 'N/A',
                $booking['guest_phone'] ?? 'N/A',
                $booking['guest_email'] ?? 'N/A',
                date('Y-m-d', strtotime($booking['start_date'] ?? $booking['booking_date'])),
                isset($booking['end_date']) && $booking['end_date'] != $booking['start_date'] ? date('Y-m-d', strtotime($booking['end_date'])) : '-',
                $booking['pax'] ?? 1,
                '₱' . number_format($booking['total_amount'] ?? 0, 2),
                ucfirst($booking['payment_status'] ?? 'pending'),
                ucfirst($booking['booking_status'] ?? 'pending'),
                date('Y-m-d h:i A', strtotime($booking['booking_date'])),
                !empty($booking['payment_date']) ? date('Y-m-d h:i A', strtotime($booking['payment_date'])) : '-',
                '-', // Rating - removed
                '-', // Feedback - removed
                $guestNames
            ];
        }

        return $csvData;
    }

    /**
     * Get Revenue Data for Preview
     */
    public function getRevenueData($filters = []) {
        $revenue = [];
        $date_from = $filters['date_from'] ?? date('Y-m-01');
        $date_to = $filters['date_to'] ?? date('Y-m-t');

        // Get date range for grouping
        $start = new DateTime($date_from);
        $end = new DateTime($date_to);
        $end->modify('+1 day');

        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        foreach ($period as $date) {
            $day = $date->format('Y-m-d');
            $revenue[$day] = [
                'date' => $day,
                'houses' => 0,
                'tours' => 0,
                'food' => 0,
                'total' => 0
            ];
        }

        // House Revenue
        $sql = "SELECT DATE(paid_at) as paid_date, COALESCE(SUM(total_amount), 0) as amount
                FROM house_bookings
                WHERE payment_status = 'paid'
                AND paid_at BETWEEN :date_from AND :date_to
                GROUP BY DATE(paid_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':date_from', $date_from . ' 00:00:00');
        $stmt->bindValue(':date_to', $date_to . ' 23:59:59');
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (isset($revenue[$row['paid_date']])) {
                $revenue[$row['paid_date']]['houses'] = (float)$row['amount'];
            }
        }

        // Tour Revenue
        $sql = "SELECT DATE(paid_at) as paid_date, COALESCE(SUM(total_amount), 0) as amount
                FROM tour_bookings
                WHERE payment_status = 'paid'
                AND paid_at BETWEEN :date_from AND :date_to
                GROUP BY DATE(paid_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':date_from', $date_from . ' 00:00:00');
        $stmt->bindValue(':date_to', $date_to . ' 23:59:59');
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (isset($revenue[$row['paid_date']])) {
                $revenue[$row['paid_date']]['tours'] = (float)$row['amount'];
            }
        }

        // Food Revenue
        try {
            $sql = "SELECT DATE(paid_at) as paid_date, COALESCE(SUM(total_amount), 0) as amount
                    FROM food_bookings
                    WHERE payment_status = 'paid'
                    AND paid_at BETWEEN :date_from AND :date_to
                    GROUP BY DATE(paid_at)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':date_from', $date_from . ' 00:00:00');
            $stmt->bindValue(':date_to', $date_to . ' 23:59:59');
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                if (isset($revenue[$row['paid_date']])) {
                    $revenue[$row['paid_date']]['food'] = (float)$row['amount'];
                }
            }
        } catch (PDOException $e) {
            // Food bookings table might not exist
        }

        // Format for preview
        $csvData = [];
        foreach ($revenue as $day) {
            $csvData[] = [
                date('M d, Y', strtotime($day['date'])),
                '₱' . number_format($day['houses'], 2),
                '₱' . number_format($day['tours'], 2),
                '₱' . number_format($day['food'], 2),
                '₱' . number_format($day['total'], 2)
            ];
        }

        // Add summary row
        $csvData[] = ['---', '---', '---', '---', '---'];
        $csvData[] = [
            'TOTAL',
            '₱' . number_format(array_sum(array_column($revenue, 'houses')), 2),
            '₱' . number_format(array_sum(array_column($revenue, 'tours')), 2),
            '₱' . number_format(array_sum(array_column($revenue, 'food')), 2),
            '₱' . number_format(array_sum(array_column($revenue, 'total')), 2)
        ];

        return $csvData;
    }

    /**
     * Get Users Data for Preview
     */
    public function getUsersData($filters = []) {
        $sql = "SELECT 
                    u.id,
                    u.username,
                    u.fullname,
                    u.email,
                    u.role,
                    u.created_at as registration_date,
                    g.contact_number as phone,
                    g.address,
                    g.id_type,
                    g.id_number,
                    g.emergency_contact,
                    g.emergency_number,
                    g.profile_photo
                FROM users u
                LEFT JOIN guests g ON u.id = g.user_id
                WHERE 1=1";

        if (!empty($filters['role'])) {
            $sql .= " AND u.role = :role";
        }

        $sql .= " ORDER BY u.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        if (!empty($filters['role'])) {
            $stmt->bindValue(':role', $filters['role']);
        }
        $stmt->execute();
        $users = $stmt->fetchAll();

        $csvData = [];
        foreach ($users as $user) {
            $guest_stmt = $this->pdo->prepare("SELECT id FROM guests WHERE user_id = ?");
            $guest_stmt->execute([$user['id']]);
            $guest = $guest_stmt->fetch();

            $total_bookings = 0;
            $total_spent = 0;

            if ($guest) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM house_bookings WHERE guest_id = ? AND payment_status = 'paid'");
                $stmt->execute([$guest['id']]);
                $result = $stmt->fetch();
                $total_bookings += $result['count'];
                $total_spent += $result['total'];

                $stmt = $this->pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM tour_bookings WHERE guest_id = ? AND payment_status = 'paid'");
                $stmt->execute([$guest['id']]);
                $result = $stmt->fetch();
                $total_bookings += $result['count'];
                $total_spent += $result['total'];

                try {
                    $stmt = $this->pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM food_bookings WHERE guest_id = ? AND payment_status = 'paid'");
                    $stmt->execute([$guest['id']]);
                    $result = $stmt->fetch();
                    $total_bookings += $result['count'];
                    $total_spent += $result['total'];
                } catch (PDOException $e) {}
            }

            $csvData[] = [
                $user['id'],
                $user['username'] ?? 'N/A',
                $user['fullname'] ?? 'N/A',
                $user['email'] ?? 'N/A',
                $user['phone'] ?? 'N/A',
                $user['address'] ?? 'N/A',
                ucfirst($user['role'] ?? 'guest'),
                date('M d, Y', strtotime($user['registration_date'])),
                $total_bookings,
                '₱' . number_format($total_spent, 2),
                $user['id_type'] ?? 'N/A',
                $user['id_number'] ?? 'N/A',
                $user['emergency_contact'] ?? 'N/A',
                $user['emergency_number'] ?? 'N/A'
            ];
        }

        return $csvData;
    }

    /**
     * Get Houses Data for Preview
     */
    public function getHousesData() {
        $sql = "SELECT 
                    h.id,
                    h.house_name,
                    h.description,
                    h.price_per_night,
                    h.capacity,
                    h.bedrooms,
                    h.amenities,
                    h.status,
                    h.created_at,
                    (SELECT COUNT(*) FROM house_bookings WHERE house_id = h.id AND booking_status != 'cancelled') as total_bookings,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM house_bookings WHERE house_id = h.id AND payment_status = 'paid') as total_revenue
                FROM houses h
                ORDER BY h.id";

        $stmt = $this->pdo->query($sql);
        $houses = $stmt->fetchAll();

        $csvData = [];
        foreach ($houses as $house) {
            $csvData[] = [
                $house['id'],
                $house['house_name'],
                substr($house['description'] ?? '', 0, 100),
                '₱' . number_format($house['price_per_night'], 2),
                $house['capacity'],
                $house['bedrooms'],
                $house['amenities'] ?? 'N/A',
                ucfirst($house['status']),
                $house['total_bookings'] ?? 0,
                '₱' . number_format($house['total_revenue'] ?? 0, 2),
                date('M d, Y', strtotime($house['created_at']))
            ];
        }

        return $csvData;
    }

    /**
     * Get Tours Data for Preview
     */
    public function getToursData() {
        $sql = "SELECT 
                    t.id,
                    t.tour_name,
                    t.description,
                    t.price_per_boat,
                    t.max_guests as boat_capacity,
                    t.status,
                    t.created_at,
                    (SELECT COUNT(*) FROM tour_bookings WHERE tour_id = t.id AND booking_status != 'cancelled') as total_bookings,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM tour_bookings WHERE tour_id = t.id AND payment_status = 'paid') as total_revenue
                FROM tours t
                ORDER BY t.id";

        $stmt = $this->pdo->query($sql);
        $tours = $stmt->fetchAll();

        $csvData = [];
        foreach ($tours as $tour) {
            $csvData[] = [
                $tour['id'],
                $tour['tour_name'],
                substr($tour['description'] ?? '', 0, 100),
                '₱' . number_format($tour['price_per_boat'], 2),
                $tour['boat_capacity'],
                ucfirst($tour['status']),
                $tour['total_bookings'] ?? 0,
                '₱' . number_format($tour['total_revenue'] ?? 0, 2),
                date('M d, Y', strtotime($tour['created_at']))
            ];
        }

        return $csvData;
    }

    /**
     * Get Food Data for Preview
     */
    public function getFoodData() {
        try {
            $sql = "SELECT 
                        f.id,
                        f.name,
                        f.description,
                        f.price,
                        f.category,
                        f.is_available,
                        f.is_featured,
                        f.pax_range,
                        f.inclusions,
                        f.created_at,
                        (SELECT COUNT(*) FROM food_bookings WHERE food_id = f.id AND booking_status != 'cancelled') as total_orders,
                        (SELECT COALESCE(SUM(total_amount), 0) FROM food_bookings WHERE food_id = f.id AND payment_status = 'paid') as total_revenue
                    FROM food_items f
                    ORDER BY f.category, f.name";

            $stmt = $this->pdo->query($sql);
            $food_items = $stmt->fetchAll();

            $csvData = [];
            foreach ($food_items as $food) {
                $csvData[] = [
                    $food['id'],
                    $food['name'],
                    substr($food['description'] ?? '', 0, 100),
                    '₱' . number_format($food['price'], 2),
                    ucfirst(str_replace('_', ' ', $food['category'])),
                    $food['pax_range'] ?? 'N/A',
                    $food['is_available'] ? 'Yes' : 'No',
                    $food['is_featured'] ? 'Yes' : 'No',
                    $food['total_orders'] ?? 0,
                    '₱' . number_format($food['total_revenue'] ?? 0, 2),
                    date('M d, Y', strtotime($food['created_at']))
                ];
            }

            return $csvData;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get Feedback Data for Preview
     */
    public function getFeedbackData($filters = []) {
        $sql = "SELECT 
                    f.id,
                    f.rating,
                    f.comment,
                    f.created_at,
                    f.is_anonymous,
                    u.username,
                    u.fullname,
                    u.email,
                    u.role
                FROM overall_feedback f
                JOIN users u ON f.user_id = u.id
                WHERE 1=1";

        if (!empty($filters['rating'])) {
            $sql .= " AND f.rating = :rating";
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= " AND DATE(f.created_at) BETWEEN :date_from AND :date_to";
        }

        $sql .= " ORDER BY f.created_at DESC";

        $stmt = $this->pdo->prepare($sql);

        if (!empty($filters['rating'])) {
            $stmt->bindValue(':rating', $filters['rating']);
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $stmt->bindValue(':date_from', $filters['date_from']);
            $stmt->bindValue(':date_to', $filters['date_to']);
        }

        $stmt->execute();
        $feedback = $stmt->fetchAll();

        $csvData = [];
        foreach ($feedback as $row) {
            $csvData[] = [
                $row['id'],
                $row['is_anonymous'] ? 'Anonymous' : ($row['username'] ?? 'N/A'),
                $row['fullname'] ?? $row['username'] ?? 'N/A',
                $row['email'] ?? 'N/A',
                $row['rating'] . '/5',
                substr(strip_tags($row['comment'] ?? ''), 0, 200),
                date('M d, Y h:i A', strtotime($row['created_at'])),
                $row['is_anonymous'] ? 'Yes' : 'No'
            ];
        }

        return $csvData;
    }

    /**
     * Get Activities Data for Preview
     */
    public function getActivitiesData() {
        $sql = "SELECT 
                    id,
                    name,
                    description,
                    category,
                    price,
                    price_unit,
                    price_note,
                    status,
                    is_featured,
                    created_at
                FROM activities
                ORDER BY category, name";

        $stmt = $this->pdo->query($sql);
        $activities = $stmt->fetchAll();

        $csvData = [];
        foreach ($activities as $activity) {
            $csvData[] = [
                $activity['id'],
                $activity['name'],
                $activity['category'] ?? 'Other',
                substr($activity['description'] ?? '', 0, 100),
                '₱' . number_format($activity['price'], 2),
                $activity['price_unit'] ?? 'N/A',
                $activity['price_note'] ?? 'N/A',
                ucfirst($activity['status']),
                $activity['is_featured'] ? 'Yes' : 'No',
                date('M d, Y', strtotime($activity['created_at']))
            ];
        }

        return $csvData;
    }

    // ============================================================
    // OVERALL RATING - Export & Preview
    // ============================================================

    /**
     * Get Overall Rating Data for Preview
     */
    public function getOverallRatingData($filters = []) {
        $sql = "SELECT 
                    f.id,
                    f.rating,
                    f.comment,
                    f.created_at,
                    f.is_anonymous,
                    u.username,
                    u.fullname,
                    u.email,
                    u.role
                FROM overall_feedback f
                JOIN users u ON f.user_id = u.id
                WHERE 1=1";

        if (!empty($filters['rating'])) {
            $sql .= " AND f.rating = :rating";
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= " AND DATE(f.created_at) BETWEEN :date_from AND :date_to";
        }

        $sql .= " ORDER BY f.created_at DESC";

        $stmt = $this->pdo->prepare($sql);

        if (!empty($filters['rating'])) {
            $stmt->bindValue(':rating', $filters['rating']);
        }
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $stmt->bindValue(':date_from', $filters['date_from']);
            $stmt->bindValue(':date_to', $filters['date_to']);
        }

        $stmt->execute();
        $feedback = $stmt->fetchAll();

        // Get rating stats
        $stats = $this->pdo->query("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM overall_feedback")->fetch();
        $avg_rating = $stats['avg_rating'] ? round($stats['avg_rating'], 1) : 0;
        $total_reviews = $stats['total'] ?? 0;

        // Get rating distribution
        $distribution = [];
        for ($i = 5; $i >= 1; $i--) {
            $count = $this->pdo->query("SELECT COUNT(*) FROM overall_feedback WHERE rating = $i")->fetchColumn();
            $percentage = $total_reviews > 0 ? round(($count / $total_reviews) * 100) : 0;
            $distribution[] = [$i . ' ★', $count, $percentage . '%'];
        }

        $csvData = [];

        // Add summary rows
        $csvData[] = ['--- OVERALL RATING SUMMARY ---', '', ''];
        $csvData[] = ['Average Rating', $avg_rating . ' / 5', ''];
        $csvData[] = ['Total Reviews', $total_reviews, ''];
        $csvData[] = ['', '', ''];
        $csvData[] = ['Rating', 'Count', 'Percentage'];

        // Add distribution
        foreach ($distribution as $row) {
            $csvData[] = $row;
        }

        $csvData[] = ['', '', ''];
        $csvData[] = ['--- DETAILED REVIEWS ---', '', ''];

        // Add detailed reviews
        foreach ($feedback as $row) {
            $csvData[] = [
                $row['id'],
                $row['is_anonymous'] ? 'Anonymous' : ($row['username'] ?? 'N/A'),
                $row['fullname'] ?? $row['username'] ?? 'N/A',
                $row['email'] ?? 'N/A',
                $row['rating'] . '/5',
                substr(strip_tags($row['comment'] ?? ''), 0, 200),
                date('M d, Y h:i A', strtotime($row['created_at'])),
                $row['is_anonymous'] ? 'Yes' : 'No'
            ];
        }

        return $csvData;
    }

    /**
     * Export Overall Rating Report
     */
    public function exportOverallRating($filters = []) {
        $data = $this->getOverallRatingData($filters);
        
        // Find where detailed reviews start
        $start_index = 0;
        foreach ($data as $index => $row) {
            if (is_array($row) && isset($row[0]) && $row[0] == '--- DETAILED REVIEWS ---') {
                $start_index = $index + 1;
                break;
            }
        }
        
        // Extract detailed reviews
        $detailed_data = array_slice($data, $start_index);
        
        $headers = ['ID', 'Username', 'Full Name', 'Email', 'Rating', 'Comment', 'Created Date', 'Anonymous'];
        $filename = 'overall_rating_report';
        $this->downloadCSV($detailed_data, $filename, $headers);
    }
}
?>
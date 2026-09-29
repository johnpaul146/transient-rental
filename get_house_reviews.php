<?php
session_start();

$host = 'localhost';
$dbname = 'rental_management';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

if(!isset($_GET['house_id'])) {
    echo json_encode(['error' => 'House ID required']);
    exit();
}

$house_id = $_GET['house_id'];

// Get reviews with user info
$stmt = $pdo->prepare("SELECT r.*, u.username 
                       FROM house_reviews r 
                       JOIN users u ON r.user_id = u.id 
                       WHERE r.house_id = ? 
                       ORDER BY r.created_at DESC");
$stmt->execute([$house_id]);
$reviews = $stmt->fetchAll();

echo json_encode(['reviews' => $reviews]);
?>
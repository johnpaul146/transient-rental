<?php
require_once 'database.php';

// Check if house_id is provided
if(!isset($_GET['house_id'])) {
    echo json_encode(['error' => 'House ID required']);
    exit();
}

$house_id = $_GET['house_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM house_gallery WHERE house_id = ? ORDER BY is_main DESC, sort_order ASC");
    $stmt->execute([$house_id]);
    $images = $stmt->fetchAll();
    
    echo json_encode(['images' => $images]);
} catch(Exception $e) {
    echo json_encode(['error' => 'Failed to load gallery']);
}
?>
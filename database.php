<?php

date_default_timezone_set('Asia/Manila');

define('DB_HOST', 'localhost');
define('DB_NAME', 'foodbbfd_jerrydino');
define('DB_USER', 'foodbbfd_jerryuser');
define('DB_PASS', 'FoodConnectSystem202608242005');


try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Use Philippine time for this MySQL session
    $pdo->exec("SET time_zone = '+08:00'");

} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

function getDB() {
    global $pdo;
    return $pdo;
}

?>
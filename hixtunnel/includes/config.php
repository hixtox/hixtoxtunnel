<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Database configuration
$db_host = 'localhost'; // Changed to explicit localhost IP
$db_name = 'hixtunnel';
$db_user = 'hixtunnel'; 
$db_pass = 'HixTunnel@123'; 

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true, // Use persistent connections
        PDO::ATTR_TIMEOUT => 5, // 5 seconds timeout
    ];
    
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    
    // Test the connection
    $pdo->query("SELECT 1");
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    echo "<pre>Error: " . $e->getMessage() . "\n";
    echo "DSN: mysql:host=$db_host;dbname=$db_name\n";
    echo "User: $db_user</pre>";
    die();
}

// Helper functions
function redirect($url) {
    header("Location: $url");
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Set timezone
date_default_timezone_set('UTC');

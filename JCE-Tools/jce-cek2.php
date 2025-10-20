<?php
ob_start();

header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/error2.log');

require_once __DIR__ . '/vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Failed to load .env file: " . $e->getMessage());
    exit();
}

if (!isset($_ENV['DB_USER']) || !isset($_ENV['DB_PASS']) || !isset($_ENV['DB_NAME'])) {
    error_log("Missing environment variables");
    exit();
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$validApiKey = 'JCE-TOOLS-8274827490142820785613720428042187';

if ($apiKey !== $validApiKey) {
    error_log("Invalid API Key");
    exit();
}

$servername = "127.0.0.1";
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];
$dbname = $_ENV['DB_NAME'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed", "error" => $conn->connect_error]);
    exit();
}

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!$data || !isset($data["hwid"])) {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
    exit();
}

$hwid = $conn->real_escape_string($data["hwid"]);
$checkOnly = $data["checkOnly"] ?? true;

$checkQuery = "SELECT id, expiry_date FROM jce_all_pc WHERE hwid_encrypted = '$hwid'";
$checkResult = $conn->query($checkQuery);

if ($checkResult->num_rows > 0) {
    $row = $checkResult->fetch_assoc();
    $expiryDate = $row['expiry_date'];

    if ($expiryDate !== null && strtotime($expiryDate) < time()) {
        echo json_encode(["status" => "error", "message" => "HWID expired"]);
        exit();
    }

    if ($checkOnly) {
        echo json_encode(["status" => "found", "expiry_date" => $expiryDate]);
    } else {
        echo json_encode(["status" => "success", "message" => "HWID found, process continue", "expiry_date" => $expiryDate]);
    }
    exit();
} else {
    if ($checkOnly) {
        echo json_encode(["status" => "not_found"]);
        exit();
    }
    // Tambahkan expiry date disini, misal 1 bulan dari sekarang.
    $expiryDate = date('Y-m-d H:i:s', strtotime('+1 month'));
    $insertQuery = "INSERT INTO jce_all_pc (hwid_encrypted, timestamp, expiry_date) VALUES ('$hwid', NOW(), '$expiryDate')";
    if ($conn->query($insertQuery)) {
        // Hapus HWID terlama jika lebih dari 5
        $countQuery = "SELECT COUNT(*) AS total FROM jce_all_pc";
        $countResult = $conn->query($countQuery);
        $countRow = $countResult->fetch_assoc();
        if ($countRow["total"] > 5) {
            $deleteQuery = "DELETE FROM jce_all_pc ORDER BY timestamp ASC LIMIT 1";
            $conn->query($deleteQuery);
        }
        echo json_encode(["status" => "success", "message" => "HWID added", "expiry_date" => $expiryDate]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to add HWID", "error" => $conn->error]);
    }
    exit();
}

$conn->close();
?>
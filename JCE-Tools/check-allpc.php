<?php
ob_start();

header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/error.log');

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
    error_log("Database connection failed: " . $conn->connect_error);
    exit();
}

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON format", "received" => $input]);
    error_log("Invalid JSON format: " . $input);
    exit();
}

if (!isset($data["hwid"])) {
    echo json_encode(["status" => "error", "message" => "HWID is required"]);
    error_log("HWID is required: " . json_encode($data));
    exit();
}

$hwid = $conn->real_escape_string($data["hwid"]);
$query = "SELECT * FROM user_jce WHERE hwid_encrypted = '$hwid'";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "HWID not found"]);
    error_log("HWID not found: " . $hwid);
    exit();
}

$row = $result->fetch_assoc();

$expiry_date = strtotime($row["expiry_date"]);
$current_time = time();
$new_counter = $row["counter"] + 1;

$updateCounter = "UPDATE user_jce SET counter = counter + 1 WHERE hwid_encrypted = '$hwid'";
if (!$conn->query($updateCounter)) {
    error_log("Failed to update counter: " . $conn->error);
}

$response = [];
if ($current_time > $expiry_date) {
    $response = [
        "status" => "error",
        "message" => "HWID expired",
        "expiry_date" => $row["expiry_date"],
        "counter" => $new_counter,
        "nama" => $row["Nama"],
        "sk" => $row["sk"],
        "sck" => $row["sck"],
        "maintenance" => $row["maintenance"]
    ];
    error_log("HWID expired: " . $hwid);
} else {
    $response = [
        "status" => "success",
        "message" => "HWID valid",
        "expiry_date" => $row["expiry_date"],
        "counter" => $new_counter,
        "nama" => $row["Nama"],
        "sk" => $row["sk"],
        "sck" => $row["sck"],
        "maintenance" => $row["maintenance"]
    ];
    $successLog = fopen(__DIR__ . '/success.log', 'a');
    if ($successLog) {
        $logMessage = date('[Y-m-d H:i:s]') . " - " . $row["Nama"] . " - HWID valid: " . $hwid . PHP_EOL;
        fwrite($successLog, $logMessage);
        fclose($successLog);
    } else {
        error_log("Failed to open success.log");
    }
}

$conn->close();

echo json_encode($response);
?>

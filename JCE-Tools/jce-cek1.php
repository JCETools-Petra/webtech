<?php
ob_start();

header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/error.log');

// === Fungsi blokir IP ===
function isIpBlocked($ip) {
    $blockFile = __DIR__ . '/blocked_ips.txt';
    if (!file_exists($blockFile)) {
        return false;
    }
    $blocked = file($blockFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return in_array($ip, $blocked);
}
function blockIp($ip) {
    $blockFile = __DIR__ . '/blocked_ips.txt';
    // Agar tidak double, cek dulu
    $blocked = file_exists($blockFile) ? file($blockFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    if (!in_array($ip, $blocked)) {
        file_put_contents($blockFile, $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// === Cek apakah IP diblokir ===
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
if (isIpBlocked($clientIp)) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(["status" => "error", "message" => "Your IP is blocked."]);
    exit();
}

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
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
    $requestTime = date('[Y-m-d H:i:s]');
    $inputBody = file_get_contents("php://input");

    $logData = "$requestTime | Invalid API Key Attempt | IP: $clientIp | User Agent: $userAgent | API Key: $apiKey | Body: $inputBody" . PHP_EOL;

    error_log($logData, 3, __DIR__ . '/intruder.log'); // Simpan ke file khusus
    error_log("Invalid API Key"); // Tetap log ke error.log

    // Blokir IP otomatis!
    blockIp($clientIp);

    echo json_encode(["status" => "error", "message" => "Invalid API Key", "ip" => $clientIp]);
    exit();
}

$servername = "127.0.0.1";
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];
$dbname = $_ENV['DB_NAME'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed", "error" => "Database error."]);
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
    echo json_encode(["status" => "error", "message" => "HWID is required", "received" => $data]);
    error_log("HWID is required: " . json_encode($data));
    exit();
}

$hwid = $data["hwid"];

// Gunakan prepared statement untuk SELECT
$stmt = $conn->prepare("SELECT Nama, expiry_date, counter, sk, sck, maintenance FROM user_jce WHERE hwid_encrypted = ?");
$stmt->bind_param('s', $hwid);
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    echo json_encode(["status" => "error", "message" => "Database query failed"]);
    error_log("Database query failed: " . $conn->error);
    exit();
}

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $expiry_date = strtotime($row["expiry_date"]);
    $current_time = time();
    $new_counter = $row["counter"] + 1;
    $sk = $row['sk'];
    $sck = $row['sck'];
    $maintenance = $row['maintenance'];
    $nama = $row['Nama'];

    // Gunakan prepared statement untuk UPDATE
    $updateStmt = $conn->prepare("UPDATE user_jce SET counter = counter + 1 WHERE hwid_encrypted = ?");
    $updateStmt->bind_param('s', $hwid);
    if (!$updateStmt->execute()) {
        error_log("Failed to update counter: " . $conn->error);
    }
    $updateStmt->close();

    if ($current_time > $expiry_date) {
        $response = [
            "status" => "error",
            "message" => "HWID expired",
            "expiry_date" => $row["expiry_date"],
            "counter" => $new_counter,
            "nama" => $nama,
            "sk" => $sk,
            "sck" => $sck,
            "maintenance" => $maintenance
        ];
        error_log("HWID expired: " . $hwid);
    } else {
        $response = [
            "status" => "success",
            "message" => "HWID valid",
            "expiry_date" => $row["expiry_date"],
            "counter" => $new_counter,
            "nama" => $nama,
            "sk" => $sk,
            "sck" => $sck,
            "maintenance" => $maintenance
        ];
        $successLog = fopen(__DIR__ . '/success.log', 'a');
        if ($successLog) {
            $logMessage = date('[Y-m-d H:i:s]') . " - " . $nama . " - HWID valid: " . $hwid . PHP_EOL;
            fwrite($successLog, $logMessage);
            fclose($successLog);
        } else {
            error_log("Failed to open success.log");
        }
    }
} else {
    $response = ["status" => "error", "message" => "HWID not found"];
    error_log("HWID not found: " . $hwid);
}

$stmt->close();
$conn->close();

echo json_encode($response);
?>

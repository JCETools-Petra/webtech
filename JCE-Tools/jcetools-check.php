<?php
ob_start();

header("Content-Type: application/json");

// [PERBAIKAN] Menggunakan __DIR__ bukan DIR
require_once __DIR__ . '/vendor/autoload.php';

try {
    // [PERBAIKAN] Menggunakan __DIR__ bukan DIR
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Server configuration error."]);
    exit();
}

$config = [
    'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'db_user' => $_ENV['DB_USER'],
    'db_pass' => $_ENV['DB_PASS'],
    'db_name' => $_ENV['DB_NAME'],
    'valid_api_key' => 'JCE-TOOLS-8274827490142820785613720428042187',
];

function log_message(string $level, string $message) {
    // [PERBAIKAN] Menggunakan __DIR__ bukan DIR
    $logFile = __DIR__ . '/' . $level . '.log';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $formattedMessage = date('[Y-m-d H:i:s]') . " | IP: $clientIp | " . $message . PHP_EOL;
    file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (hash_equals($config['valid_api_key'], $apiKey) === false) {
    log_message('intruder', "Invalid API Key Attempt: $apiKey");
    echo json_encode(["status" => "error", "message" => "Invalid API Key."]);
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    log_message('error', 'Database connection failed: ' . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Database connection error."]);
    exit();
}

$input = file_get_contents("php://input");
$data = json_decode($input, true);
if (!isset($data["hwid"]) || !is_string($data["hwid"])) {
    log_message('error', 'HWID is missing or invalid in request.');
    echo json_encode(["status" => "error", "message" => "Invalid request format."]);
    exit();
}
$hwid = $data["hwid"];

try {
    $stmt = $conn->prepare("SELECT Nama, expiry_date FROM user_jce WHERE hwid_encrypted = ?");
    $stmt->bind_param('s', $hwid);
    $stmt->execute();
    $result = $stmt->get_result();
    $response = [];

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $expiry_date = strtotime($row["expiry_date"]);
        if (time() > $expiry_date) {
            $response = ["status" => "error", "message" => "HWID has expired.", "expiry_date" => $row["expiry_date"]];
        } else {
            $updateStmt = $conn->prepare("UPDATE user_jce SET counter = counter + 1 WHERE hwid_encrypted = ?");
            $updateStmt->bind_param('s', $hwid);
            $updateStmt->execute();
            $updateStmt->close();
            $response = ["status" => "success", "message" => "HWID valid", "expiry_date" => $row["expiry_date"]];
        }
    } else {
        $response = ["status" => "error", "message" => "HWID not found."];
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    log_message('error', 'Database query failed: ' . $e->getMessage());
    $response = ["status" => "error", "message" => "Database query error."];
}

$conn->close();
echo json_encode($response);
?>
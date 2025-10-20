<?php
// Menyembunyikan peringatan
error_reporting(E_ALL & ~E_WARNING);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$servername = $_ENV['DB_HOST'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];
$dbname = $_ENV['DB_NAME'];

$logFile = 'ip_block_log.json';
$successLogFile = 'success.log';
$key = hex2bin("4A4345544F4F4C532D31383330");
$iv = hex2bin("1234567890ABCDEF12345678");

function getUserIP(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

function encryptHwid(string $plaintext, string $key, string $iv): string {
    return openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

function binToHex(string $binaryData): string {
    return bin2hex($binaryData);
}

function updateIpLog(string $ip, string $logFile, string $action): void {
    $ipLogs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
    if (!isset($ipLogs[$ip])) {
        $ipLogs[$ip] = ['violations' => 0, 'block_time' => 0];
    }
    if ($action === 'increment') {
        $ipLogs[$ip]['violations']++;
        if ($ipLogs[$ip]['violations'] >= 3) {
            $ipLogs[$ip]['block_time'] = time();
        }
    } elseif ($action === 'reset') {
        $ipLogs[$ip]['violations'] = 0;
        $ipLogs[$ip]['block_time'] = 0;
    }
    file_put_contents($logFile, json_encode($ipLogs, JSON_PRETTY_PRINT));
}

function replaceEncryptedHWIDInDB(string $originalEncrypted, string $newEncrypted, string $successLogFile, mysqli $conn, string $ip): array {
    $stmt_select = null;
    $stmt_update = null;
    try {
        $sql_select = "SELECT nama FROM user_jce WHERE hwid_encrypted = ?";
        $stmt_select = $conn->prepare($sql_select);
        $stmt_select->bind_param("s", $originalEncrypted);
        $stmt_select->execute();
        $stmt_select->bind_result($username);
        $stmt_select->fetch();
        
        if (!$username) {
            updateIpLog($ip, 'ip_block_log.json', 'increment');
            error_log("[replaceEncryptedHWIDInDB] ERROR: HWID tidak ditemukan!");
            return ["status" => "error", "message" => "HWID tidak ditemukan!"];
        }
        
        // Pastikan hasil diambil sepenuhnya
        $stmt_select->free_result();
        
        $sql_update = "UPDATE user_jce SET hwid_encrypted = ? WHERE hwid_encrypted = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ss", $newEncrypted, $originalEncrypted);
        
        if ($stmt_update->execute() && $stmt_update->affected_rows > 0) {
            updateIpLog($ip, 'ip_block_log.json', 'reset');
            $logMessage = sprintf("[%s] HWID berhasil diganti dari '%s' ke '%s' oleh pengguna: %s (IP: %s)\n", 
                date('Y-m-d H:i:s'), $originalEncrypted, $newEncrypted, $username, $ip);
            file_put_contents($successLogFile, $logMessage, FILE_APPEND);
            error_log("[replaceEncryptedHWIDInDB] SUCCESS: " . $logMessage);
            return ["status" => "success", "message" => "HWID [" . $username . "] berhasil diganti!"];
        } else {
            updateIpLog($ip, 'ip_block_log.json', 'increment');
            error_log("[replaceEncryptedHWIDInDB] ERROR: Gagal menyimpan perubahan HWID: " . $stmt_update->error);
            return ["status" => "error", "message" => "Gagal menyimpan perubahan HWID: " . $stmt_update->error];
        }
    } catch (Exception $e) {
        error_log("[replaceEncryptedHWIDInDB] Exception: " . $e->getMessage());
        return ["status" => "error", "message" => "Terjadi kesalahan: " . $e->getMessage()];
    } finally {
        if ($stmt_update !== null) {
            $stmt_update->close();
        }
        if ($stmt_select !== null) {
            $stmt_select->close();
        }
        if ($conn !== null) {
            $conn->close();
        }
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = getUserIP();
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            updateIpLog($ip, $logFile, 'increment');
            error_log("[API Request] Database connection failed: " . $conn->connect_error);
            echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
            exit;
        }

        if (isset($_POST['api_key']) && hash_equals($_ENV['API_KEY'], $_POST['api_key'])) {
            $originalText = filter_input(INPUT_POST, 'original_hwid', FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => "/^[0-9\-]+$/"]]);
            $newText = filter_input(INPUT_POST, 'new_hwid', FILTER_VALIDATE_REGEXP, ["options" => ["regexp" => "/^[0-9\-]+$/"]]);

            if ($originalText === false || $newText === false) {
                updateIpLog($ip, $logFile, 'increment');
                error_log("[API Request] Invalid input. original: " . $originalText . " new: " . $newText);
                echo json_encode(["status" => "error", "message" => "Invalid input! Only numbers and '-' are allowed."]);
                exit;
            }

            $originalEncrypted = binToHex(encryptHwid($originalText, $key, $iv));
            $newEncrypted = binToHex(encryptHwid($newText, $key, $iv));

            $result = replaceEncryptedHWIDInDB($originalEncrypted, $newEncrypted, $successLogFile, $conn, $ip);
            error_log("[API Response] " . json_encode($result));
            echo json_encode($result);
            exit;
        } else {
            updateIpLog($ip, $logFile, 'increment');
            error_log("[API Request] Unauthorized access.");
            echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
            exit;
        }
    } catch (Exception $e) {
        error_log("[API Request] Exception: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Terjadi kesalahan: " . $e->getMessage()]);
    } finally {
        if (isset($conn)) {
            $conn->close();
        }
    }
} else {
    error_log("[API Request] Invalid request method.");
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
}
?>
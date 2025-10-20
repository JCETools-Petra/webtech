<?php
session_start();

require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Konfigurasi dari .env
$servername = $_ENV['DB_HOST'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];
$dbname = $_ENV['DB_NAME'];

// Pengaturan file log & kunci enkripsi
$logFile = 'ip_block_log.json';
$successLogFile = 'success.log';
$successCounterFile = 'success_counter.txt';
$key = hex2bin("4A4345544F4F4C532D31383330");
$iv = hex2bin("1234567890ABCDEF12345678");

// --- KUMPULAN FUNGSI ---

function getUserIP() {
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

function encryptHwid($plaintext, $key, $iv) {
    return openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

function binToHex($binaryData) {
    return bin2hex($binaryData);
}

/**
 * FUNGSI BARU: Mengirim notifikasi WhatsApp via Fonnte
 * @param string $oldHwidTeks HWID lama dalam bentuk teks
 * @param string $newHwidTeks HWID baru dalam bentuk teks
 * @param string $userNama Nama pengguna dari database
 * @param string $ip Alamat IP pengguna
 */
function sendFonnteNotification($oldHwidTeks, $newHwidTeks, $userNama, $ip) {
    $fonnteToken = $_ENV['FONNTE_TOKEN'] ?? '';
    $targetNumber = $_ENV['ADMIN_WHATSAPP_NUMBER'] ?? '';

    // Jangan kirim jika token atau nomor tidak ada
    if (empty($fonnteToken) || empty($targetNumber)) {
        return; 
    }

    $message = "🔔 *Notifikasi Ganti HWID Berhasil* 🔔\n\n" .
               "Pengguna: *{$userNama}*\n" .
               "IP Address: *{$ip}*\n\n" .
               "HWID Lama: `{$oldHwidTeks}`\n" .
               "HWID Baru: `{$newHwidTeks}`";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => ['target' => $targetNumber, 'message' => $message],
        CURLOPT_HTTPHEADER => ["Authorization: " . $fonnteToken],
    ]);

    curl_exec($curl);
    curl_close($curl);
}

function updateIpLog($ip, $logFile, $action) {
    $ipLogs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) ?? [] : [];
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

function isIpBlocked($ip, $logFile) {
    if (!file_exists($logFile)) return false;
    $ipLogs = json_decode(file_get_contents($logFile), true) ?? [];
    return isset($ipLogs[$ip]) && $ipLogs[$ip]['violations'] >= 3;
}

function validateCsrfToken($csrfToken) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $_SESSION['notification'] = ["status" => "error", "message" => "Sesi tidak valid, silakan coba lagi."];
        header("Location: index.php");
        exit;
    }
}

/**
 * Fungsi utama yang dimodifikasi untuk memanggil notifikasi Fonnte
 */
function replaceEncryptedHWIDInDB($originalText, $newText, $originalEncrypted, $newEncrypted, $successLogFile) {
    global $successCounterFile;
    $ip = getUserIP();
    $conn = new mysqli($GLOBALS['servername'], $GLOBALS['username'], $GLOBALS['password'], $GLOBALS['dbname']);
    
    if ($conn->connect_error) {
        updateIpLog($ip, $GLOBALS['logFile'], 'increment');
        $_SESSION['notification'] = ["status" => "error", "message" => "Koneksi database gagal."];
        header("Location: index.php");
        exit;
    }

    $username = "N/A"; // Default username
    $sql_select = "SELECT nama FROM user_jce WHERE hwid_encrypted = ?";
    if ($stmt_select = $conn->prepare($sql_select)) {
        $stmt_select->bind_param("s", $originalEncrypted);
        $stmt_select->execute();
        $result = $stmt_select->get_result();
        if ($row = $result->fetch_assoc()) {
            $username = $row['nama'];
        }
        $stmt_select->close();
    }

    $sql_update = "UPDATE user_jce SET hwid_encrypted = ? WHERE hwid_encrypted = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("ss", $newEncrypted, $originalEncrypted);

    if ($stmt_update->execute()) {
        if ($stmt_update->affected_rows > 0) {
            updateIpLog($ip, $GLOBALS['logFile'], 'reset');
            
            $successCount = file_exists($successCounterFile) ? (int)file_get_contents($successCounterFile) : 0;
            file_put_contents($successCounterFile, $successCount + 1);
            
            $logMessage = sprintf(
                "[%s] HWID diganti dari '%s' ke '%s' oleh pengguna: %s (IP: %s)\n",
                date('Y-m-d H:i:s'), $originalEncrypted, $newEncrypted, $username, $ip
            );
            file_put_contents($successLogFile, $logMessage, FILE_APPEND);
            
            $_SESSION['notification'] = ["status" => "success", "message" => "HWID berhasil diganti!"];
            
            // PANGGIL FUNGSI NOTIFIKASI WHATSAPP DI SINI
            sendFonnteNotification($originalText, $newText, $username, $ip);
            
        } else {
            updateIpLog($ip, $GLOBALS['logFile'], 'increment');
            $_SESSION['notification'] = ["status" => "error", "message" => "HWID Lama tidak ditemukan!"];
        }
    } else {
        updateIpLog($ip, $GLOBALS['logFile'], 'increment');
        $_SESSION['notification'] = ["status" => "error", "message" => "Gagal menyimpan perubahan: " . $stmt_update->error];
    }

    $stmt_update->close();
    $conn->close();
    header("Location: index.php");
    exit;
}

// --- ALUR EKSEKUSI UTAMA ---

$ip = getUserIP();
if (isIpBlocked($ip, $logFile)) {
    $_SESSION['notification'] = ["status" => "error", "message" => "Akses Anda diblokir karena terlalu banyak percobaan gagal."];
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $originalText = $_POST['textbox1'] ?? '';
    $newText = $_POST['textbox2'] ?? '';

    if (empty($originalText) || empty($newText) || !preg_match('/^[0-9-]+$/', $originalText) || !preg_match('/^[0-9-]+$/', $newText)) {
        updateIpLog($ip, $logFile, 'increment');
        $_SESSION['notification'] = ["status" => "error", "message" => "Input tidak valid! Hanya angka dan tanda '-' yang diizinkan."];
        header("Location: index.php");
        exit;
    }

    $originalEncrypted = binToHex(encryptHwid($originalText, $key, $iv));
    $newEncrypted = binToHex(encryptHwid($newText, $key, $iv));
    
    // Panggil fungsi utama dengan parameter teks asli
    replaceEncryptedHWIDInDB($originalText, $newText, $originalEncrypted, $newEncrypted, $successLogFile);
}
?>
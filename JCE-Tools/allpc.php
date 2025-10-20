<?php
header("Content-Type: application/json");
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '/vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Gagal memuat file .env: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Kesalahan konfigurasi server."]);
    exit();
}

// --- KONEKSI DATABASE ---
$servername = $_ENV['DB_HOST'] ?? "127.0.0.1";
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];
$dbname = $_ENV['DB_NAME'];
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    error_log("Koneksi database gagal: " . $conn->connect_error);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit();
}

// --- VALIDASI API KEY ---
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$validApiKey = $_ENV['APP_API_KEY'] ?? 'JCE-TOOLS-8274827490142820785613720428042187';

if ($apiKey !== $validApiKey) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Akses ditolak"]);
    $conn->close();
    exit();
}

// --- PROSES INPUT ---
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Fungsi untuk mengambil pengaturan teks tombol dari global_settings
function getButtonDisplayTexts($conn) {
    $texts = [
        'button3_text' => null,
        'button_custom_text' => null // Untuk buttonStatus1
    ];

    $setting_b3_result = $conn->query("SELECT setting_value FROM `global_settings` WHERE setting_key = 'button3_text' LIMIT 1");
    if ($setting_b3_result && $setting_b3_result->num_rows > 0) {
        $texts['button3_text'] = $setting_b3_result->fetch_assoc()['setting_value'];
    }

    $setting_bc_result = $conn->query("SELECT setting_value FROM `global_settings` WHERE setting_key = 'button_custom_text_key' LIMIT 1");
    if ($setting_bc_result && $setting_bc_result->num_rows > 0) {
        $texts['button_custom_text'] = $setting_bc_result->fetch_assoc()['setting_value'];
    }
    return $texts;
}


// BRANCH 0: Handle request to explicitly blacklist an HWID
if (isset($data["action"]) && $data["action"] === "blacklist_hwid" && isset($data["hwid"])) {
    $hwid_to_blacklist = $conn->real_escape_string($data["hwid"]);
    
    $stmt = $conn->prepare("INSERT IGNORE INTO `hwid_blacklist` (hwid_encrypted, reason) VALUES (?, ?)");
    if (!$stmt) { /* ... error handling ... */ }
    $reason = "3 failed login attempts";
    $stmt->bind_param("ss", $hwid_to_blacklist, $reason);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "HWID berhasil diblokir."]);
    } else { /* ... error handling ... */ }
    $stmt->close();
    $conn->close();
    exit();
}
// BRANCH 1: User is trying to login
elseif (isset($data["login_code"]) && isset($data["hwid"])) {
    $login_code_from_input = $conn->real_escape_string($data["login_code"]);
    $hwid_from_input = $conn->real_escape_string($data["hwid"]);

    // Ambil expiry_date, s3_username, DAN button3_s3_key dari login_codes
    $code_stmt = $conn->prepare("SELECT expiry_date, s3_username, button3_s3_key FROM `login_codes` WHERE kode = ? AND is_active = 1 LIMIT 1");
    if (!$code_stmt) { 
        error_log("Prepare statement failed for login_code check: (" . $conn->errno . ") " . $conn->error);
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Kesalahan database (prepare code)."]);
        $conn->close();
        exit();
    }
    $code_stmt->bind_param("s", $login_code_from_input);
    $code_stmt->execute();
    $code_result = $code_stmt->get_result();
    $code_stmt->close();

    if ($code_result->num_rows > 0) {
        $row = $code_result->fetch_assoc();
        $expiry_date = $row['expiry_date'];
        $s3_username_for_button2 = $row['s3_username']; 
        $s3_key_for_button3 = $row['button3_s3_key']; // S3 Key untuk button3 dari login_codes

        if ($expiry_date !== null && strtotime($expiry_date) < strtotime(date("Y-m-d H:i:s"))) {
            echo json_encode(["status" => "login_failed_expired", "message" => "Kode sudah kedaluwarsa."]);
            $conn->close();
            exit();
        }

        $hwid_stmt = $conn->prepare("SELECT id FROM `hwid_blacklist` WHERE hwid_encrypted = ? LIMIT 1");
        if (!$hwid_stmt) { /* ... error handling ... */ }
        $hwid_stmt->bind_param("s", $hwid_from_input);
        $hwid_stmt->execute();
        $hwid_result = $hwid_stmt->get_result();
        $hwid_stmt->close();

        if ($hwid_result->num_rows > 0) {
            echo json_encode(["status" => "hwid_blacklisted", "message" => "Akses ditolak. PC Anda telah diblokir."]);
            $conn->close();
            exit();
        }
        
        $buttonDisplayTexts = getButtonDisplayTexts($conn);
        
        echo json_encode([
            "status" => "login_success",
            "message" => "Login berhasil.",
            "s3_username" => $s3_username_for_button2,      // Untuk download utama (button2)
            "button3_s3_key" => $s3_key_for_button3,       // S3 Key untuk button3 (dari login_codes)
            "button3_text" => $buttonDisplayTexts['button3_text'],         // Teks display untuk button3
            "button_custom_text" => $buttonDisplayTexts['button_custom_text'] // Teks display untuk buttonStatus1
        ]);

    } else {
        echo json_encode(["status" => "login_failed_invalid_code", "message" => "Kode tidak valid atau tidak aktif."]);
    }

    $conn->close();
    exit();
}
// BRANCH 2: Direct HWID check (untuk FormLogin_Load atau Form1)
elseif (isset($data["hwid"])) {
    $hwid_from_input = $conn->real_escape_string($data["hwid"]);

    $stmt = $conn->prepare("SELECT id FROM `hwid_blacklist` WHERE hwid_encrypted = ? LIMIT 1");
    if (!$stmt) { /* ... error handling ... */ }
    $stmt->bind_param("s", $hwid_from_input);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    
    $buttonDisplayTexts = getButtonDisplayTexts($conn);

    // Untuk BRANCH 2, s3_username dan button3_s3_key tidak diambil dari login_codes
    // karena tidak ada login_code yang diproses. Kirim null.
    if ($result->num_rows > 0) { // HWID diblokir
        $response = [
            "status" => "success", 
            "message" => "HWID terdaftar dalam blacklist.",
            "s3_username" => null,
            "button3_s3_key" => null,
            "button3_text" => $buttonDisplayTexts['button3_text'],
            "button_custom_text" => $buttonDisplayTexts['button_custom_text']
        ];
    } else { // HWID bersih
        $response = [
            "status" => "error", 
            "message" => "HWID tidak terdaftar dalam blacklist.",
            "s3_username" => null,
            "button3_s3_key" => null,
            "button3_text" => $buttonDisplayTexts['button3_text'],
            "button_custom_text" => $buttonDisplayTexts['button_custom_text']
        ];
    }
    
    echo json_encode($response);
    $conn->close();
    exit();
}
// No valid action parameter provided
else {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Input tidak valid."]);
    if ($conn && $conn->ping()) {
        $conn->close();
    }
    exit();
}
?>

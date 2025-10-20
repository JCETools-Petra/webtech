<?php
header("Content-Type: application/json");
error_reporting(0);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/error.log');

// =================== TAMBAHKAN BLOK INI ===================
// Blok ini WAJIB ada untuk memuat koneksi dari file .env
require_once __DIR__ . '/vendor/autoload.php';
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Gagal memuat file .env di get_credentials.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Kesalahan konfigurasi server .env"]);
    exit();
}
// ==========================================================

// Ganti dengan detail koneksi database Anda
$servername = "127.0.0.1";
$username = $_ENV['DB_USER']; // Sekarang ini akan berfungsi
$password = $_ENV['DB_PASS']; // Sekarang ini akan berfungsi
$dbname = $_ENV['DB_NAME'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    // Tambahkan pesan error agar lebih jelas saat debugging
    error_log("Koneksi DB gagal di get_credentials.php: " . $conn->connect_error);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit();
}

// Ambil kredensial 'default' dari tabel s3_config
$query = "SELECT client_key_encrypted, secret_key_encrypted FROM s3_config WHERE config_name = 'default' LIMIT 1";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $response = [
        "status" => "success",
        "sk" => $row['client_key_encrypted'],
        "sck" => $row['secret_key_encrypted']
    ];
    echo json_encode($response);
} else {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Konfigurasi kredensial tidak ditemukan di tabel s3_config."]);
}

$conn->close();
?>
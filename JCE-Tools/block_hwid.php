<?php
header("Content-Type: application/json");
// Sembunyikan error untuk production
error_reporting(0);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/error.log');

// ============== BLOK KODE YANG DITAMBAHKAN ==============
// Blok ini WAJIB ada untuk memuat koneksi dari file .env
require_once __DIR__ . '/vendor/autoload.php';
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Gagal memuat file .env di block_hwid.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Kesalahan konfigurasi server .env"]);
    exit();
}
// ========================================================


// Ganti dengan detail koneksi database Anda
$servername = "127.0.0.1";
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];
$dbname = $_ENV['DB_NAME'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit();
}

// Ambil data POST dari aplikasi C#
$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!isset($data["hwid"])) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "HWID dibutuhkan"]);
    exit();
}

$hwid = $conn->real_escape_string($data["hwid"]);

// Query untuk memasukkan HWID ke tabel blacklist `allpc`
// IGNORE digunakan agar tidak terjadi error jika HWID sudah ada
$query = "INSERT IGNORE INTO allpc (hwid_encrypted) VALUES ('$hwid')";

if ($conn->query($query) === TRUE) {
    echo json_encode(["status" => "success", "message" => "HWID berhasil diblokir."]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Gagal memblokir HWID.", "error" => $conn->error]);
}

$conn->close();
?>
<?php
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;

// --- Inisialisasi ---
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "==================================================\n";
echo "Mulai skrip pengecekan kedaluwarsa pada " . date('Y-m-d H:i:s') . "\n";
echo "==================================================\n";


// --- Koneksi Database ---
$conn = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error . "\n");
}

// --- Fungsi Kirim Notifikasi (Tetap sama) ---
function sendExpiryNotification($targetNumber, $message) {
    $fonnteToken = $_ENV['FONNTE_TOKEN'] ?? '';
    if (empty($fonnteToken)) return false;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['target' => $targetNumber, 'message' => $message]),
        CURLOPT_HTTPHEADER => ["Authorization: " . $fonnteToken],
    ]);
    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    return $httpcode === 200;
}

// ==================================================
// --- TUGAS 1: PROSES NOTIFIKASI H-7 (7 HARI) ---
// ==================================================
echo "\n--- Memulai Pengecekan H-7 ---\n";

// 1. Cari pengguna yang kedaluwarsa dalam 7 hari & notif H-7 belum terkirim
$sql_h7 = "SELECT id, Nama, expiry_date, phone_number FROM user_jce 
           WHERE DATE(expiry_date) = CURDATE() + INTERVAL 7 DAY 
           AND notif_h7_sent = 0 
           AND phone_number IS NOT NULL AND phone_number != ''";

$result_h7 = $conn->query($sql_h7);

if ($result_h7 && $result_h7->num_rows > 0) {
    echo "Ditemukan " . $result_h7->num_rows . " pengguna untuk notifikasi H-7.\n";
    while($row = $result_h7->fetch_assoc()) {
        $formattedDate = date('d F Y', strtotime($row['expiry_date']));
        $message = "Halo *{$row['Nama']}* 👋,\n\n" .
                   "Layanan JCE Tools Anda akan berakhir dalam kurang lebih *7 hari lagi*, pada tanggal *{$formattedDate}*.\n\n" .
                   "Pastikan Anda melakukan perpanjangan untuk menghindari gangguan. Terima kasih!";
        
        echo "Memproses H-7 untuk pengguna: " . $row['Nama'] . "\n";
        $isSent = sendExpiryNotification($row['phone_number'], $message);

        if ($isSent) {
            // Jika berhasil, update flag notif_h7_sent menjadi 1
            $updateSql = "UPDATE user_jce SET notif_h7_sent = 1 WHERE id = ?";
            $stmt = $conn->prepare($updateSql);
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $stmt->close();
            echo "-> Notifikasi H-7 berhasil dikirim dan status diupdate.\n";
        } else {
            echo "-> Gagal mengirim notifikasi H-7 ke Fonnte.\n";
        }
    }
} else {
    echo "Tidak ada pengguna untuk notifikasi H-7 hari ini.\n";
}

// ==================================================
// --- TUGAS 2: PROSES NOTIFIKASI H-1 (1 HARI) ---
// ==================================================
echo "\n--- Memulai Pengecekan H-1 ---\n";

// 2. Cari pengguna yang kedaluwarsa besok & notif H-1 belum terkirim
$sql_h1 = "SELECT id, Nama, expiry_date, phone_number FROM user_jce 
           WHERE DATE(expiry_date) = CURDATE() + INTERVAL 1 DAY 
           AND notif_h1_sent = 0 
           AND phone_number IS NOT NULL AND phone_number != ''";

$result_h1 = $conn->query($sql_h1);

if ($result_h1 && $result_h1->num_rows > 0) {
    echo "Ditemukan " . $result_h1->num_rows . " pengguna untuk notifikasi H-1.\n";
    while($row = $result_h1->fetch_assoc()) {
        $formattedDate = date('d F Y', strtotime($row['expiry_date']));
        $message = "Halo *{$row['Nama']}* 👋,\n\n" .
                   "Ini adalah pengingat terakhir. Layanan JCE Tools Anda akan berakhir *besok*, pada tanggal *{$formattedDate}*.\n\n" .
                   "Silakan lakukan perpanjangan sekarang untuk melanjutkan layanan tanpa gangguan. Terima kasih!";
        
        echo "Memproses H-1 untuk pengguna: " . $row['Nama'] . "\n";
        $isSent = sendExpiryNotification($row['phone_number'], $message);

        if ($isSent) {
            // Jika berhasil, update flag notif_h1_sent menjadi 1
            $updateSql = "UPDATE user_jce SET notif_h1_sent = 1 WHERE id = ?";
            $stmt = $conn->prepare($updateSql);
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $stmt->close();
            echo "-> Notifikasi H-1 berhasil dikirim dan status diupdate.\n";
        } else {
            echo "-> Gagal mengirim notifikasi H-1 ke Fonnte.\n";
        }
    }
} else {
    echo "Tidak ada pengguna untuk notifikasi H-1 hari ini.\n";
}

$conn->close();
echo "\n==================================================\n";
echo "Skrip selesai.\n";
echo "==================================================\n";
?>
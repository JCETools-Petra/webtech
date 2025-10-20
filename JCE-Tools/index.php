<?php
session_start();

// File log pelanggaran
$logFile = 'ip_block_log.json';

// Fungsi untuk mendapatkan IP pengguna
function getUserIP() {
    return $_SERVER['REMOTE_ADDR'];
}

// Fungsi untuk memeriksa apakah IP telah diblokir
function isIpBlocked($ip, $logFile) {
    if (file_exists($logFile)) {
        $ipLogs = json_decode(file_get_contents($logFile), true) ?? [];
        if (isset($ipLogs[$ip]) && $ipLogs[$ip]['violations'] >= 3) {
            $blockTime = $ipLogs[$ip]['block_time'] ?? 0;
            if (time() - $blockTime > 86400) {
                unset($ipLogs[$ip]);
                file_put_contents($logFile, json_encode($ipLogs, JSON_PRETTY_PRINT));
                return false;
            }
            return true;
        }
    }
    return false;
}

// Periksa apakah IP telah diblokir
$ip = getUserIP();
if (isIpBlocked($ip, $logFile)) {
    header("HTTP/1.1 403 Forbidden");
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>403 Forbidden</title><style>body {font-family: Arial, sans-serif;text-align: center;padding: 50px;background-color: #f8f8f8;color: #333;}h1 {font-size: 48px;margin-bottom: 20px;}p {font-size: 18px;}</style></head><body><h1>AKSES ANDA DIBLOKIR</h1><p>Hubungi owner untuk membuka akses, </p><a href='https://wa.me/6281299430992'>Klik di sini</a></body></html>";
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_expiry']) || time() > $_SESSION['csrf_token_expiry']) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_expiry'] = time() + 3600;
}

// Ambil notifikasi
$notification = $_SESSION['notification'] ?? null;
unset($_SESSION['notification']);

// Koneksi database
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$servername = $_ENV['DB_HOST'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];
$dbname = $_ENV['DB_NAME'];

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ambil jumlah user yang masih aktif
$result = $conn->query("SELECT COUNT(*) as active_users FROM user_jce WHERE expiry_date > NOW()");
$row = $result->fetch_assoc();
$activeUserCount = $row['active_users'];

$conn->close();

// File untuk menyimpan jumlah hit
$successCounterFile = 'success_counter.txt';
$successCount = file_exists($successCounterFile) ? (int)file_get_contents($successCounterFile) : 0;

// File untuk menyimpan IP pengunjung
$visitorIpFile = 'visitor_ips.txt';
$visitTime = date('Y-m-d H:i:s');
$logEntry = "IP: {$ip} | Waktu: {$visitTime}\n";
file_put_contents($visitorIpFile, $logEntry, FILE_APPEND);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JCE Tools - HWID Editor</title>
    <style>
        html {
            height: 100%;
        }

        body {
            height: 100%;
            margin: 0;
            overflow: hidden;
            background: linear-gradient(to bottom, #202020, #111119);
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
        }

        .logo {
            margin-bottom: 20px;
            width: 150px;
            height: auto;
            animation: fadeIn 1s ease;
        }

        form {
            background-color: #1E1E1E;
            padding: 35px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 400px;
            animation: fadeIn 1s ease;
            z-index: 10;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: bold;
        }

        input[type="text"] {
            width: 94%;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #2E2E2E;
            color: #FFFFFF;
            border: 1px solid #3E3E3E;
            border-radius: 500px;
            transition: background-color 0.3s;
        }

        input[type="text"]:focus {
            background-color: #3E3E3E;
            outline: none;
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: #007BFF;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
        }

        button:hover {
            background-color: #0056b3;
            transform: scale(1.05);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notification {
            position: fixed;
            top: 20px; /* Pindahkan ke bagian atas */
            left: 50%;
            transform: translateX(-50%);
            padding: 15px 20px;
            font-weight: bold;
            border-radius: 5px;
            color: #FFFFFF;
            z-index: 10000; /* Pastikan di atas elemen lain */
            animation: slideIn 0.5s forwards, slideOut 2s 3.5s forwards; /* Ubah durasi slideOut */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2); /* Tambahkan sedikit shadow */
        }

        .notification.success {
            background-color: #28a745;
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.4);
        }

        .notification.error {
            background-color: #dc3545;
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.4);
        }

        .user-count,
        .hit-counter {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #333;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 14px;
            text-align: right;
        }

        .hit-counter {
            top: 40px;
        }
    </style>
</head>
<body>
    <?php if ($notification): ?>
        <div class="notification <?php echo htmlspecialchars($notification['status']); ?>">
            <?php echo htmlspecialchars($notification['message']); ?>
        </div>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var notification = document.querySelector('.notification');
        if (notification) {
            setTimeout(function() {
                notification.style.display = 'none'; // Atau notification.remove(); untuk menghapus elemen
            }, 3000); // 3000 milidetik = 3 detik
        }
    });
    </script>
    <div class="user-count">Cheat Status : ON 🟢 </div>
    <div class="hit-counter">Penggantian Berhasil: <?php echo $successCount; ?> kali</div>
    <img src="logo.png" alt="Logo" class="logo">
    <p>JCE TOOLS - HWID CHANGER</p>
    <form method="POST" action="process.php">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <label for="textbox1">HWID Lama:</label>
        <input type="text" id="textbox1" name="textbox1" required>
        <label for="textbox2">HWID Baru:</label>
        <input type="text" id="textbox2" name="textbox2" required>
        <button type="submit">Ganti HWID</button>
    </form>
</body>
</html>
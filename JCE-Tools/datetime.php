<?php

header("Content-Type: application/json");
date_default_timezone_set("Asia/Jakarta"); // Set zona waktu ke Asia/Jakarta

$now = new DateTime();
$milliSeconds = round(microtime(true) * 1000) % 1000; // Mendapatkan milidetik
$ipAddress = $_SERVER['REMOTE_ADDR']; // Mendapatkan alamat IP pengunjung

// Data yang akan dikirim sebagai response JSON
$data = [
    "year" => (int) $now->format("Y"),
    "month" => (int) $now->format("m"),
    "day" => (int) $now->format("d"),
    "hour" => (int) $now->format("H"),
    "minute" => (int) $now->format("i"),
    "seconds" => (int) $now->format("s"),
    "milliSeconds" => $milliSeconds,
    "dateTime" => $now->format("Y-m-d\TH:i:s.") . str_pad($milliSeconds, 3, "0", STR_PAD_LEFT) . "000",
    "date" => (string) $now->format("m/d/Y"),
    "time" => (string) $now->format("H:i"),
    "timeZone" => (string) "Asia/Jakarta",
    "dayOfWeek" => (string) $now->format("l"),
    "dstActive" => (bool) date("I"), // Menentukan apakah DST aktif
    "ipAddress" => $ipAddress // Tambahkan IP ke dalam response
];

// Simpan log akses ke file
$logEntry = sprintf("[%s] IP: %s\n", $now->format("Y-m-d H:i:s"), $ipAddress);
file_put_contents("allpclog.txt", $logEntry, FILE_APPEND);

// Tampilkan JSON sebagai output
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
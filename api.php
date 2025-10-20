<?php

// Bagian 1: Konfigurasi Database
// Ganti dengan detail database kamu
$servername = "localhost"; // Biasanya 'localhost' di shared hosting
$username = "apsx2353_dbhotel"; // Ganti dengan username database kamu
$password = "Petra1830!@#"; // Ganti dengan password database kamu
$dbname = "apsx2353_dbhotel"; // Ganti dengan nama database yang kamu buat

// Bagian 2: Header untuk JSON Response
// Memberi tahu klien (program VB.NET) bahwa responsnya adalah JSON
header('Content-Type: application/json');

// Bagian 3: Menerima dan Memproses Permintaan dari VB.NET
// Membaca data JSON yang dikirim melalui POST
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true); // Mengubah JSON string menjadi PHP Array

// Periksa apakah data JSON berhasil di-parse
if ($data === null) {
    // Jika gagal, kirim error JSON
    echo json_encode(["status" => "error", "message" => "Invalid JSON received"]);
    exit(); // Hentikan eksekusi skrip
}

// Periksa 'action' apa yang diminta oleh program VB.NET
$action = $data['action'] ?? ''; // Mengambil nilai 'action', default kosong jika tidak ada

// Bagian 4: Menghubungkan ke Database MySQL (sama seperti sebelumnya)
$conn = new mysqli($servername, $username, $password, $dbname);

// Periksa koneksi (sama seperti sebelumnya)
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database Connection failed: " . $conn->connect_error]);
    exit();
}

// Bagian 5: Menangani Berbagai Jenis Aksi (Permintaan) - TAMBAHKAN CASE BARU DI SINI
switch ($action) {
    case 'getRooms':
        // ... (Kode untuk getRooms) ...
        $sql = "SELECT room_id, room_number, room_type, price_per_night, status FROM rooms";
        $result = $conn->query($sql);
        $rooms = array();
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $rooms[] = $row;
            }
            echo json_encode(["status" => "success", "data" => $rooms]);
        } else {
            echo json_encode(["status" => "success", "data" => []]);
        }
        break;

    case 'checkIn':
        // ... (Kode untuk checkIn) ...
        // (Kode ini sudah ditambahkan sebelumnya)
         $first_name = $data['first_name'] ?? '';
        $last_name = $data['last_name'] ?? '';
        $phone = $data['phone'] ?? '';
        $email = $data['email'] ?? null;
        $id_number = $data['id_number'] ?? null;
        $room_id = $data['room_id'] ?? 0;
        $check_in_date = $data['check_in_date'] ?? '';
        $check_out_date = $data['check_out_date'] ?? '';

        if (empty($first_name) || empty($last_name) || empty($phone) || empty($room_id) || empty($check_in_date) || empty($check_out_date)) {
             echo json_encode(["status" => "error", "message" => "Data check-in tidak lengkap."]);
             $conn->close(); exit();
        }

        $stmt_check_room = $conn->prepare("SELECT status FROM rooms WHERE room_id = ?");
        $stmt_check_room->bind_param("i", $room_id);
        $stmt_check_room->execute();
        $result_check_room = $stmt_check_room->get_result();
        $room_data = $result_check_room->fetch_assoc();
        $stmt_check_room->close();

        if (!$room_data || $room_data['status'] !== 'Ready') {
             echo json_encode(["status" => "error", "message" => "Kamar tidak tersedia untuk check-in."]);
             $conn->close(); exit();
        }

        $conn->begin_transaction();
        $guest_id = 0;

        try {
            $stmt_guest = $conn->prepare("INSERT INTO guests (first_name, last_name, phone, email, id_number) VALUES (?, ?, ?, ?, ?)");
            $stmt_guest->bind_param("sssss", $first_name, $last_name, $phone, $email, $id_number);
            if (!$stmt_guest->execute()) { throw new Exception("Gagal menyimpan data tamu: " . $stmt_guest->error); }
            $guest_id = $conn->insert_id;
            $stmt_guest->close();

            $booking_status = 'Active';
            $stmt_booking = $conn->prepare("INSERT INTO bookings (room_id, guest_id, check_in_date, check_out_date, booking_status) VALUES (?, ?, ?, ?, ?)");
            $stmt_booking->bind_param("iissi", $room_id, $guest_id, $check_in_date, $check_out_date, $booking_status);
            if (!$stmt_booking->execute()) { throw new Exception("Gagal membuat booking: " . $stmt_booking->error); }
            $booking_id = $conn->insert_id;
            $stmt_booking->close();

            $new_room_status = 'Occupied';
            $stmt_room_update = $conn->prepare("UPDATE rooms SET status = ? WHERE room_id = ?");
            $stmt_room_update->bind_param("si", $new_room_status, $room_id);
            if (!$stmt_room_update->execute()) { throw new Exception("Gagal memperbarui status kamar: " . $stmt_room_update->error); }
            $stmt_room_update->close();

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Check-in berhasil!", "booking_id" => $booking_id, "guest_id" => $guest_id]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    case 'getActiveBookings':
        // ... (Kode untuk getActiveBookings) ...
         $sql = "SELECT
                    b.booking_id,
                    b.check_in_date,
                    b.check_out_date AS planned_check_out_date,
                    r.room_number,
                    r.room_type,
                    r.price_per_night,
                    g.guest_id,
                    g.first_name,
                    g.last_name,
                    g.phone
                FROM bookings b
                JOIN rooms r ON b.room_id = r.room_id
                JOIN guests g ON b.guest_id = g.guest_id
                WHERE b.booking_status = 'Active'";

        $result = $conn->query($sql);

        $activeBookings = array();
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $activeBookings[] = $row;
            }
            echo json_encode(["status" => "success", "data" => $activeBookings]);
        } else {
            echo json_encode(["status" => "success", "data" => []]);
        }
        break;

    case 'checkOut':
         // ... (Kode untuk checkOut) ...
         // (Kode ini sudah ditambahkan sebelumnya)
         $booking_id = $data['booking_id'] ?? 0;
        $actual_check_out_date_str = $data['actual_check_out_date'] ?? '';

        if (empty($booking_id) || empty($actual_check_out_date_str)) {
            echo json_encode(["status" => "error", "message" => "Data check-out tidak lengkap."]);
            $conn->close(); exit();
        }

        $stmt_check_booking = $conn->prepare("SELECT b.room_id, b.check_in_date, r.price_per_night FROM bookings b JOIN rooms r ON b.room_id = r.room_id WHERE b.booking_id = ? AND b.booking_status = 'Active'");
        $stmt_check_booking->bind_param("i", $booking_id);
        $stmt_check_booking->execute();
        $result_check_booking = $stmt_check_booking->get_result();
        $booking_data = $result_check_booking->fetch_assoc();
        $stmt_check_booking->close();

        if (!$booking_data) {
             echo json_encode(["status" => "error", "message" => "Booking tidak ditemukan atau statusnya tidak aktif."]);
             $conn->close(); exit();
        }

        $room_id_to_update = $booking_data['room_id'];
        $check_in_date_str = $booking_data['check_in_date'];
        $price_per_night = $booking_data['price_per_night'];

        try {
            $check_in_datetime = new DateTime($check_in_date_str);
            $actual_check_out_datetime = new DateTime($actual_check_out_date_str);
            $interval = $check_in_datetime->diff($actual_check_out_datetime);
            $numberOfNights = $interval->days;
             if ($check_in_datetime->format('Y-m-d') === $actual_check_out_datetime->format('Y-m-d')) {
                 $numberOfNights = 1;
            } elseif ($actual_check_out_datetime < $check_in_datetime) {
                throw new Exception("Tanggal check-out tidak valid (sebelum check-in).");
            }
            $totalPrice = $numberOfNights * $price_per_night;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Gagal menghitung harga: " . $e->getMessage()]);
            $conn->close(); exit();
        }

        $conn->begin_transaction();
        try {
            $new_booking_status = 'Completed';
            $stmt_booking_update = $conn->prepare("UPDATE bookings SET actual_check_out_date = ?, total_price = ?, booking_status = ? WHERE booking_id = ?");
            $stmt_booking_update->bind_param("sdsi", $actual_check_out_date_str, $totalPrice, $new_booking_status, $booking_id);
            if (!$stmt_booking_update->execute()) { throw new Exception("Gagal memperbarui data booking: " . $stmt_booking_update->error); }
            $stmt_booking_update->close();

            $new_room_status = 'Dirty';
            $stmt_room_update = $conn->prepare("UPDATE rooms SET status = ? WHERE room_id = ?");
            $stmt_room_update->bind_param("si", $new_room_status, $room_id_to_update);
            if (!$stmt_room_update->execute()) { throw new Exception("Gagal memperbarui status kamar: " . $stmt_room_update->error); }
            $stmt_room_update->close();

            $transaction_type = 'Booking Payment';
            $stmt_transaction = $conn->prepare("INSERT INTO transactions (booking_id, transaction_date, amount, transaction_type) VALUES (?, NOW(), ?, ?)");
            $stmt_transaction->bind_param("ids", $booking_id, $totalPrice, $transaction_type);
             if (!$stmt_transaction->execute()) {
                 // Log error jika perlu, tapi tidak harus fatal
                 // error_log("Gagal mencatat transaksi booking ID " . $booking_id . ": " . $stmt_transaction->error);
             }
            $stmt_transaction->close();


            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Check-out berhasil!", "booking_id" => $booking_id, "total_price" => $totalPrice]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    case 'updateRoomStatus':
        // Aksi: Mengubah Status Kamar (misal: Dirty -> Ready)
        // Membutuhkan data: room_id, new_status

        $room_id = $data['room_id'] ?? 0; // Pastikan ini integer
        $new_status = $data['new_status'] ?? '';

        // Validasi dasar data
        if (empty($room_id) || empty($new_status)) {
            echo json_encode(["status" => "error", "message" => "Data update status kamar tidak lengkap."]);
            $conn->close();
            exit();
        }

        // !!! PENTING: Validasi new_status agar sesuai dengan ENUM yang valid di tabel 'rooms'
        // Jika tidak divalidasi, bisa terjadi error di database atau input data tidak konsisten.
        $allowed_statuses = ['Ready', 'Occupied', 'Dirty', 'Maintenance']; // Sesuaikan dengan ENUM di tabel rooms
        if (!in_array($new_status, $allowed_statuses)) {
             echo json_encode(["status" => "error", "message" => "Status kamar tidak valid."]);
             $conn->close();
             exit();
        }


        // Lakukan update status kamar di tabel rooms
        $stmt_room_update = $conn->prepare("UPDATE rooms SET status = ? WHERE room_id = ?");
        // 's' untuk string (status), 'i' untuk integer (room_id)
        $stmt_room_update->bind_param("si", $new_status, $room_id);

        if ($stmt_room_update->execute()) {
            // Jika update berhasil
            echo json_encode(["status" => "success", "message" => "Status kamar berhasil diperbarui.", "room_id" => $room_id, "new_status" => $new_status]);
        } else {
            // Jika update gagal
            echo json_encode(["status" => "error", "message" => "Gagal memperbarui status kamar: " . $stmt_room_update->error]);
        }

        $stmt_room_update->close();

        break; // Akhir dari case 'updateRoomStatus'


    default:
        // ... (Kode untuk default) ...
        echo json_encode(["status" => "error", "message" => "Unknown action: " . $action]);
        break;
}

// Bagian 6: Menutup Koneksi Database (sama seperti sebelumnya)
$conn->close();

?>
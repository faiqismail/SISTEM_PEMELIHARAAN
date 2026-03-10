<?php
include "inc/config.php";

// Daftar semua role yang ada
$roles = [
    'admin', 'pengawas', 'angkutan_dalam', 'angkutan_luar',
    'alat_berat_wilayah_1', 'alat_berat_wilayah_2',
    'alat_berat_wilayah_3', 'pergudangan'
];

// Multi-tab: tab_id dari URL (bukan cookie)
$tab_id = get_armada_tab_id();

// Cari session role mana yang sedang aktif di tab ini
foreach ($roles as $role) {
    // Tutup session sebelumnya jika ada
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Nama session unik per tab (jika ada tab_id)
    if ($tab_id) {
        $sessionName = 'ARMADA_' . strtoupper($role) . '_' . $tab_id;
    } else {
        $sessionName = 'ARMADA_' . strtoupper($role);
    }

    session_name($sessionName);
    session_start();

    // Cek apakah session ini yang aktif
    if (!empty($_SESSION['id_login']) && isset($_SESSION['role']) && $_SESSION['role'] === $role) {

        // Hapus token dari database
        $token_to_delete = $_SESSION['db_token'] ?? null;
        if ($token_to_delete && isset($connection)) {
            $sql  = "DELETE FROM user_sessions WHERE session_token = ?";
            $stmt = $connection->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $token_to_delete);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Hapus cookie session PHP untuk role+tab ini
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Hancurkan session role ini (tab_id tidak pakai cookie lagi)
        $_SESSION = [];
        session_destroy();

        // Sudah ketemu dan logout, stop loop
        break;
    }
}

// Redirect ke halaman login dengan tab yang sama
header('Location: ' . url_with_tab('index.php'));
exit;
?>
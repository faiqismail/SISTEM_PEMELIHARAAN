<?php
// SET TIMEZONE INDONESIA
date_default_timezone_set('Asia/Jakarta');


// 🔑 WAJIB: include koneksi database
include "../inc/config.php";

requireAuth('angkutan_luar');

// ==========================
// AMBIL ID PERMINTAAN
// ==========================
$id_permintaan = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_permintaan <= 0) {
    die("ID Permintaan tidak valid");
}

// ==========================
// PROSES RESET STATUS KE PENGAJUAN
// ==========================
if (isset($_POST['reset_status'])) {
    // Hapus semua data detail
    mysqli_query($connection, "DELETE FROM perbaikan_detail WHERE id_permintaan = '$id_permintaan'");
    mysqli_query($connection, "DELETE FROM sparepart_detail WHERE id_permintaan = '$id_permintaan'");
    
    // Reset data permintaan ke status awal
    $query_reset = "
        UPDATE permintaan_perbaikan SET
            total_perbaikan = 0,
            total_sparepart = 0,
            grand_total = 0,
            admin_sa = NULL,
            ttd_sa = NULL,
            tgl_diperiksa_sa = NULL,
            status = 'Diajukan'
        WHERE id_permintaan = '$id_permintaan'
    ";
    
    if (mysqli_query($connection, $query_reset)) {
        echo "<script>
            alert('✅ Status berhasil direset ke Pengajuan Awal. Semua data detail telah dihapus.');
            window.location.href='service_advisor.php?id=$id_permintaan';
        </script>";
    } else {
        echo "<script>
            alert('❌ Gagal mereset status: " . mysqli_error($connection) . "');
        </script>";
    }
    exit;
}

// ==========================
// PROSES HAPUS ITEM JASA
// ==========================
if (isset($_POST['hapus_jasa'])) {
    $id_detail = intval($_POST['id_detail']);
    
    if (mysqli_query($connection, "DELETE FROM perbaikan_detail WHERE id = '$id_detail'")) {
        // Recalculate total
        $query_recalc = "
            SELECT 
                COALESCE(SUM(pd.subtotal), 0) as total_jasa,
                COALESCE(SUM(sd.subtotal), 0) as total_sparepart
            FROM permintaan_perbaikan p
            LEFT JOIN perbaikan_detail pd ON p.id_permintaan = pd.id_permintaan
            LEFT JOIN sparepart_detail sd ON p.id_permintaan = sd.id_permintaan
            WHERE p.id_permintaan = '$id_permintaan'
            GROUP BY p.id_permintaan
        ";
        $result_recalc = mysqli_query($connection, $query_recalc);
        $data_recalc = mysqli_fetch_assoc($result_recalc);
        
        $total_jasa = $data_recalc['total_jasa'] ?? 0;
        $total_sparepart = $data_recalc['total_sparepart'] ?? 0;
        $grand_total = $total_jasa + $total_sparepart;
        
        mysqli_query($connection, "
            UPDATE permintaan_perbaikan SET
                total_perbaikan = '$total_jasa',
                total_sparepart = '$total_sparepart',
                grand_total = '$grand_total'
            WHERE id_permintaan = '$id_permintaan'
        ");
        
        echo "<script>
            alert('✅ Item jasa berhasil dihapus');
            window.location.href='service_advisor.php?id=$id_permintaan';
        </script>";
    } else {
        echo "<script>alert('❌ Gagal menghapus item jasa');</script>";
    }
    exit;
}

// ==========================
// PROSES HAPUS ITEM SPAREPART
// ==========================
if (isset($_POST['hapus_sparepart'])) {
    $id_detail = intval($_POST['id_detail']);
    
    if (mysqli_query($connection, "DELETE FROM sparepart_detail WHERE id = '$id_detail'")) {
        // Recalculate total
        $query_recalc = "
            SELECT 
                COALESCE(SUM(pd.subtotal), 0) as total_jasa,
                COALESCE(SUM(sd.subtotal), 0) as total_sparepart
            FROM permintaan_perbaikan p
            LEFT JOIN perbaikan_detail pd ON p.id_permintaan = pd.id_permintaan
            LEFT JOIN sparepart_detail sd ON p.id_permintaan = sd.id_permintaan
            WHERE p.id_permintaan = '$id_permintaan'
            GROUP BY p.id_permintaan
        ";
        $result_recalc = mysqli_query($connection, $query_recalc);
        $data_recalc = mysqli_fetch_assoc($result_recalc);
        
        $total_jasa = $data_recalc['total_jasa'] ?? 0;
        $total_sparepart = $data_recalc['total_sparepart'] ?? 0;
        $grand_total = $total_jasa + $total_sparepart;
        
        mysqli_query($connection, "
            UPDATE permintaan_perbaikan SET
                total_perbaikan = '$total_jasa',
                total_sparepart = '$total_sparepart',
                grand_total = '$grand_total'
            WHERE id_permintaan = '$id_permintaan'
        ");
        
        echo "<script>
            alert('✅ Item sparepart berhasil dihapus');
            window.location.href='service_advisor.php?id=$id_permintaan';
        </script>";
    } else {
        echo "<script>alert('❌ Gagal menghapus item sparepart');</script>";
    }
    exit;
}

// ==========================
// PROSES UPDATE ITEM JASA
// ==========================
if (isset($_POST['update_jasa'])) {
    $id_detail = intval($_POST['id_detail']);
    $qty = intval($_POST['qty']);
    $harga = floatval($_POST['harga']);
    $subtotal = $qty * $harga;
    
    $query_update = "
        UPDATE perbaikan_detail SET
            qty = '$qty',
            harga = '$harga',
            subtotal = '$subtotal'
        WHERE id = '$id_detail'
    ";
    
    if (mysqli_query($connection, $query_update)) {
        // Recalculate total
        $query_recalc = "
            SELECT 
                COALESCE(SUM(pd.subtotal), 0) as total_jasa,
                COALESCE(SUM(sd.subtotal), 0) as total_sparepart
            FROM permintaan_perbaikan p
            LEFT JOIN perbaikan_detail pd ON p.id_permintaan = pd.id_permintaan
            LEFT JOIN sparepart_detail sd ON p.id_permintaan = sd.id_permintaan
            WHERE p.id_permintaan = '$id_permintaan'
            GROUP BY p.id_permintaan
        ";
        $result_recalc = mysqli_query($connection, $query_recalc);
        $data_recalc = mysqli_fetch_assoc($result_recalc);
        
        $total_jasa = $data_recalc['total_jasa'] ?? 0;
        $total_sparepart = $data_recalc['total_sparepart'] ?? 0;
        $grand_total = $total_jasa + $total_sparepart;
        
        mysqli_query($connection, "
            UPDATE permintaan_perbaikan SET
                total_perbaikan = '$total_jasa',
                total_sparepart = '$total_sparepart',
                grand_total = '$grand_total'
            WHERE id_permintaan = '$id_permintaan'
        ");
        
        echo "<script>
            alert('✅ Item jasa berhasil diupdate');
            window.location.href='service_advisor.php?id=$id_permintaan';
        </script>";
    } else {
        echo "<script>alert('❌ Gagal mengupdate item jasa');</script>";
    }
    exit;
}

// ==========================
// PROSES UPDATE ITEM SPAREPART
// ==========================
if (isset($_POST['update_sparepart'])) {
    $id_detail = intval($_POST['id_detail']);
    $qty = intval($_POST['qty']);
    $harga = floatval($_POST['harga']);
    $subtotal = $qty * $harga;
    
    $query_update = "
        UPDATE sparepart_detail SET
            qty = '$qty',
            harga = '$harga',
            subtotal = '$subtotal'
        WHERE id = '$id_detail'
    ";
    
    if (mysqli_query($connection, $query_update)) {
        // Recalculate total
        $query_recalc = "
            SELECT 
                COALESCE(SUM(pd.subtotal), 0) as total_jasa,
                COALESCE(SUM(sd.subtotal), 0) as total_sparepart
            FROM permintaan_perbaikan p
            LEFT JOIN perbaikan_detail pd ON p.id_permintaan = pd.id_permintaan
            LEFT JOIN sparepart_detail sd ON p.id_permintaan = sd.id_permintaan
            WHERE p.id_permintaan = '$id_permintaan'
            GROUP BY p.id_permintaan
        ";
        $result_recalc = mysqli_query($connection, $query_recalc);
        $data_recalc = mysqli_fetch_assoc($result_recalc);
        
        $total_jasa = $data_recalc['total_jasa'] ?? 0;
        $total_sparepart = $data_recalc['total_sparepart'] ?? 0;
        $grand_total = $total_jasa + $total_sparepart;
        
        mysqli_query($connection, "
            UPDATE permintaan_perbaikan SET
                total_perbaikan = '$total_jasa',
                total_sparepart = '$total_sparepart',
                grand_total = '$grand_total'
            WHERE id_permintaan = '$id_permintaan'
        ");
        
        echo "<script>
            alert('✅ Item sparepart berhasil diupdate');
            window.location.href='service_advisor.php?id=$id_permintaan';
        </script>";
    } else {
        echo "<script>alert('❌ Gagal mengupdate item sparepart');</script>";
    }
    exit;
}

// ==========================
// AMBIL DATA USER LOGIN (SERVICE ADVISOR)
// ==========================
$user_login = $_SESSION['username'] ?? '';
$user_ttd = $_SESSION['ttd'] ?? '';
$user_id = $_SESSION['id_user'] ?? 0;

// Query untuk mendapatkan data user yang login
$query_user = "SELECT username, ttd FROM users WHERE id_user = '$user_id' LIMIT 1";
$result_user = mysqli_query($connection, $query_user);
$data_user = mysqli_fetch_assoc($result_user);

if ($data_user) {
    $user_login = $data_user['username'];
    $user_ttd = $data_user['ttd'];
}

// ==========================
// AMBIL DATA PERMINTAAN
// ==========================
$query_permintaan = "
    SELECT p.*, k.nopol, k.jenis_kendaraan, k.bidang,
           u_sa.username AS sa_nama, u_sa.ttd AS sa_ttd,
           r.nama_rekanan, r.ttd_rekanan
    FROM permintaan_perbaikan p
    LEFT JOIN kendaraan k 
        ON p.id_kendaraan = k.id_kendaraan
    LEFT JOIN users u_sa
        ON p.admin_sa = u_sa.id_user
    LEFT JOIN rekanan r
        ON p.id_rekanan = r.id_rekanan
    WHERE p.id_permintaan = '$id_permintaan'
";
$result_permintaan = mysqli_query($connection, $query_permintaan);
$data_permintaan = mysqli_fetch_assoc($result_permintaan);

// ==========================
// AMBIL DETAIL JASA
// ==========================
$query_jasa_detail = "
    SELECT * 
    FROM perbaikan_detail 
    WHERE id_permintaan = '$id_permintaan'
";
$result_jasa_detail = mysqli_query($connection, $query_jasa_detail);

// ==========================
// AMBIL DETAIL SPAREPART - DENGAN JOIN
// ==========================
$query_sparepart_detail = "
    SELECT sd.*, s.kode_sparepart, s.nama_sparepart
    FROM sparepart_detail sd
    LEFT JOIN sparepart s ON sd.id_sparepart = s.id_sparepart
    WHERE sd.id_permintaan = '$id_permintaan'
";
$result_sparepart_detail = mysqli_query($connection, $query_sparepart_detail);

// ==========================
// DATA MASTER REKANAN
// ==========================
$result_rekanan = mysqli_query($connection, "SELECT * FROM rekanan ORDER BY nama_rekanan");

// ==========================
// AMBIL DATA UNTUK STATUS TRACKING
// ==========================
$query_status_tracking = "
    SELECT 
        p.*,
        u_pengawas.username AS pengawas_nama,
        u_pengawas.ttd AS pengawas_ttd,
        u_sa.username AS sa_nama,
        u_sa.ttd AS sa_ttd,
        u_karu.username AS karu_nama,
        u_karu.ttd AS karu_ttd
    FROM permintaan_perbaikan p
    LEFT JOIN users u_pengawas ON p.id_pengaju = u_pengawas.id_user
    LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
    LEFT JOIN users u_karu ON p.admin_karu_qc = u_karu.id_user
    WHERE p.id_permintaan = '$id_permintaan'
    LIMIT 1
";
$result_status_tracking = mysqli_query($connection, $query_status_tracking);
$data_status = mysqli_fetch_assoc($result_status_tracking);

// Cek apakah sudah diperiksa SA
$sudah_diperiksa_sa = !empty($data_permintaan['tgl_diperiksa_sa']);

// ==========================
// PROSES UPDATE DETAIL - DENGAN VALIDASI
// ==========================
if (isset($_POST['update_detail'])) {

    $total_jasa = 0;
    $total_sparepart = 0;

    // HAPUS DATA LAMA
    mysqli_query($connection, "DELETE FROM perbaikan_detail WHERE id_permintaan = '$id_permintaan'");
    mysqli_query($connection, "DELETE FROM sparepart_detail WHERE id_permintaan = '$id_permintaan'");

    // ======================
    // INSERT JASA
    // ======================
    if (!empty($_POST['jasa_id'])) {
        foreach ($_POST['jasa_id'] as $i => $id_jasa) {

            $kode   = mysqli_real_escape_string($connection, $_POST['jasa_kode'][$i]);
            $nama   = mysqli_real_escape_string($connection, $_POST['jasa_nama'][$i]);
            $qty    = intval($_POST['jasa_qty'][$i]);
            $harga  = floatval($_POST['jasa_harga'][$i]);
            $subtotal = $qty * $harga;

            $total_jasa += $subtotal;

            mysqli_query($connection, "
                INSERT INTO perbaikan_detail (
                    id_permintaan,
                    id_jasa,
                    kode_pekerjaan,
                    nama_pekerjaan,
                    qty,
                    harga,
                    subtotal
                ) VALUES (
                    '$id_permintaan',
                    '$id_jasa',
                    '$kode',
                    '$nama',
                    '$qty',
                    '$harga',
                    '$subtotal'
                )
            ");
        }
    }

    // ======================
    // INSERT SPAREPART - DENGAN VALIDASI ID
    // ======================
    if (!empty($_POST['sparepart_id'])) {
        foreach ($_POST['sparepart_id'] as $i => $id_sparepart) {

            // PERBAIKAN: Validasi id_sparepart tidak kosong
            if (empty($id_sparepart)) {
                continue; // Skip jika id kosong
            }

            $qty    = intval($_POST['sparepart_qty'][$i]);
            $harga  = floatval($_POST['sparepart_harga'][$i]);
            $subtotal = $qty * $harga;

            $total_sparepart += $subtotal;

            mysqli_query($connection, "
                INSERT INTO sparepart_detail (
                    id_permintaan,
                    id_sparepart,
                    qty,
                    harga,
                    subtotal
                ) VALUES (
                    '$id_permintaan',
                    '$id_sparepart',
                    '$qty',
                    '$harga',
                    '$subtotal'
                )
            ");
        }
    }

    // ======================
    // UPDATE TOTAL HEADER + TTD SERVICE ADVISOR + STATUS
    // ======================
    $grand_total = $total_jasa + $total_sparepart;
    $tgl_sa = date('Y-m-d H:i:s'); // Menggunakan timezone Asia/Jakarta yang sudah diset
    $admin_sa_id = intval($user_id);
    $ttd_sa = mysqli_real_escape_string($connection, $user_ttd);

    mysqli_query($connection, "
        UPDATE permintaan_perbaikan SET
            total_perbaikan = '$total_jasa',
            total_sparepart = '$total_sparepart',
            grand_total     = '$grand_total',
            admin_sa = '$admin_sa_id',
            ttd_sa = '$ttd_sa',
            tgl_diperiksa_sa = '$tgl_sa',
            status = 'Diperiksa_SA'
        WHERE id_permintaan = '$id_permintaan'
    ");

    echo "<script>
        alert('✅ Detail perbaikan berhasil disimpan dan TTD Service Advisor telah diperbarui');
        window.location.href='service_advisor.php?id=$id_permintaan';
    </script>";
    exit;
}

// ==========================
// DATA MASTER - HANYA YANG AKTIF
// ==========================
$result_jasa = mysqli_query($connection, "SELECT * FROM master_jasa WHERE aktif = 'Y' ORDER BY kode_pekerjaan");
$result_sparepart = mysqli_query($connection, "SELECT * FROM sparepart WHERE aktif = 'Y' ORDER BY kode_sparepart");

include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Service Advisor - Detail Perbaikan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:rgb(185, 224, 204);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            margin-left: 290px;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid rgb(9, 120, 83);
        }

        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: rgb(9, 120, 83);
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }

        .alert-info {
            background: #dbeafe;
            border-left: 4px solid rgb(9, 120, 83);
            color: #1e40af;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #555;
        }

        input[type="text"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary {
            background: rgb(9, 120, 83);
            color: white;
        }

        .btn-primary:hover {
            background:rgb(15, 175, 122);
        }

        .btn-success {
            background: rgb(9, 120, 83);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
            padding: 8px 15px;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-edit {
            background: #3b82f6;
            color: white;
            padding: 8px 15px;
        }

        .btn-edit:hover {
            background: #2563eb;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table thead {
            background: rgb(9, 120, 83);
            color: white;
        }

        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        table tbody tr:hover {
            background: #f3f4f6;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            padding: 10px;
            background: #f9fafb;
            border-radius: 5px;
        }

        .detail-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .detail-value {
            font-weight: 600;
            color: #1f2937;
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .status-item {
            text-align: center;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .status-item .title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #374151;
        }

        .status-badge {
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: bold;
            margin: 10px 0;
            display: inline-block;
        }

        .status-menunggu {
            background: #fef3c7;
            color: #92400e;
        }

        .status-selesai {
            background: #dcfce7;
            color: #166534;
        }

        .sub-status {
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 13px;
            margin: 5px 0;
        }

        .name {
            font-size: 14px;
            font-weight: 600;
            margin: 8px 0;
            color: #1f2937;
        }

        .time {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .add-item-form {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            align-items: end;
        }

        .add-item-form > div {
            flex: 1;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 30px 0;
        }

        .select2-container--default .select2-selection--single {
            height: 42px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 5px;
            
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 30px;
            
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
            
        }

        .ttd-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .ttd-image {
            cursor: pointer;
            border-radius: 5px;
            padding: 5px;
            background: white;
        }

        .ttd-image:hover {
            border-color: #667eea;
        }

        .action-buttons {
            margin-bottom: 20px;
            margin-left: 290px;
            transition: margin-left 0.3s ease;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: white;
            color: rgb(9, 120, 83);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        .btn-back:hover {
            background: rgb(9, 120, 83);
            color: white;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #333;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .action-btn-group {
            display: flex;
            gap: 5px;
            justify-content: center;
        }

        
@media screen and (max-width: 1024px) {
    /* Adjust container for tablets */
    .container {
        margin-left: 0 !important;
        padding: 10px;
    }

    .action-buttons {
        margin-left: 0 !important;
        padding: 10px;
    }
}

@media screen and (max-width: 768px) {
    /* Prevent horizontal scroll */
    body, html {
        overflow-x: hidden !important;
        max-width: 100vw;
    }

    body {
        padding: 10px !important;
        padding-top: 70px !important;
    }

    /* Container full width */
    .container {
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* Action buttons */
    .action-buttons {
        margin: 0 0 15px 0 !important;
        padding: 0 10px !important;
    }

    .btn-back {
        width: 100%;
        justify-content: center;
        padding: 10px 20px !important;
        font-size: 14px !important;
    }

    /* Card styling */
    .card {
        padding: 15px !important;
        border-radius: 8px !important;
        margin: 0 10px 15px 10px !important;
    }

    /* Page title */
    .page-title {
        font-size: 18px !important;
        margin-bottom: 20px !important;
        padding-bottom: 12px !important;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .page-title button {
        width: 100% !important;
        float: none !important;
        margin-top: 10px;
        font-size: 13px !important;
        padding: 10px 15px !important;
    }

    /* Section title */
    .section-title {
        font-size: 16px !important;
        margin: 20px 0 12px 0 !important;
    }

    /* Alert */
    .alert {
        padding: 12px 15px !important;
        font-size: 13px !important;
        margin-bottom: 15px !important;
        flex-direction: column;
        align-items: flex-start !important;
    }

    .alert i {
        font-size: 18px !important;
        margin-bottom: 8px;
    }

    /* Detail grid - Single column */
    .detail-grid {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
        margin-bottom: 15px !important;
    }

    .detail-item {
        padding: 8px !important;
    }

    .detail-label {
        font-size: 11px !important;
        margin-bottom: 4px !important;
    }

    .detail-value {
        font-size: 13px !important;
    }

    /* Form group */
    .form-group {
        margin-bottom: 12px !important;
    }

    label {
        font-size: 12px !important;
        margin-bottom: 4px !important;
    }

    input[type="text"],
    input[type="number"],
    select,
    textarea {
        padding: 8px 10px !important;
        font-size: 13px !important;
        border-radius: 6px !important;
    }

    /* Add item form - Stack vertical */
    .add-item-form {
        flex-direction: column !important;
        gap: 12px !important;
        margin-bottom: 12px !important;
    }

    .add-item-form > div {
        width: 100% !important;
        max-width: 100% !important;
    }

    .add-item-form button {
        width: 100% !important;
        padding: 10px !important;
    }

    /* Select2 mobile optimization */
    .select2-container {
        width: 100% !important;
    }

    .select2-container--default .select2-selection--single {
        height: 40px !important;
        padding: 4px 8px !important;
        font-size: 13px !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 30px !important;
        font-size: 13px !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 38px !important;
    }

    /* Table - Card style */
    table {
        font-size: 11px !important;
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    table thead {
        display: none !important;
    }

    table tbody {
        display: block;
    }

    table tbody tr {
        display: block;
        margin-bottom: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 12px;
        background: #f9fafb;
    }

    table tbody td {
        display: block;
        text-align: left !important;
        padding: 6px 0 !important;
        border: none !important;
    }

    table tbody td::before {
        content: attr(data-label);
        font-weight: 700;
        color: rgb(9, 120, 83);
        display: block;
        margin-bottom: 4px;
        font-size: 10px;
        text-transform: uppercase;
    }

    /* Add data-label attributes via JS or manually */
    table tbody tr td:nth-child(1)::before { content: 'Kode'; }
    table tbody tr td:nth-child(2)::before { content: 'Nama'; }
    table tbody tr td:nth-child(3)::before { content: 'Qty'; }
    table tbody tr td:nth-child(4)::before { content: 'Harga'; }
    table tbody tr td:nth-child(5)::before { content: 'Subtotal'; }
    table tbody tr td:nth-child(6)::before { content: 'Aksi'; }

    /* Action button group */
    .action-btn-group {
        flex-direction: column !important;
        gap: 6px !important;
        width: 100%;
    }

    .action-btn-group button,
    .action-btn-group form {
        width: 100% !important;
    }

    .action-btn-group .btn {
        width: 100% !important;
        justify-content: center;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Button styling */
    .btn {
        font-size: 13px !important;
        padding: 10px 20px !important;
        border-radius: 6px !important;
    }

    .btn-sm {
        font-size: 11px !important;
        padding: 8px 15px !important;
    }

    /* Total sections */
    .text-right {
        text-align: center !important;
        font-size: 16px !important;
        margin-top: 12px !important;
        padding: 12px !important;
        background: #f3f4f6;
        border-radius: 8px;
    }

    /* Grand total */
    .text-right[style*="font-size: 24px"] {
        font-size: 18px !important;
        padding: 15px !important;
    }

    .text-right div[style*="font-size: 24px"] {
        font-size: 18px !important;
    }

    /* Divider */
    .divider {
        margin: 20px 0 !important;
    }

    /* Status grid - 2x2 on mobile */
    .status-grid {
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
        margin: 15px 0 !important;
    }

    .status-item {
        padding: 12px 8px !important;
        font-size: 11px !important;
    }

    .status-item .title {
        font-size: 11px !important;
        margin-bottom: 8px !important;
    }

    .status-badge {
        padding: 6px 10px !important;
        font-size: 10px !important;
        margin: 8px 0 !important;
    }

    .sub-status {
        padding: 5px 8px !important;
        font-size: 10px !important;
        margin: 4px 0 !important;
    }

    .status-item .name {
        font-size: 11px !important;
        margin: 6px 0 !important;
    }

    .status-item .time {
        font-size: 9px !important;
    }

    /* TTD images in status */
    .status-item .ttd-wrapper img,
    .ttd-wrapper img {
        max-height: 40px !important;
        max-width: 100px !important;
        margin: 4px 0 !important;
    }

    /* Modal */
    .modal-content {
        margin: 5% auto !important;
        padding: 20px !important;
        width: 95% !important;
        max-width: 95% !important;
    }

    .modal-header {
        font-size: 16px !important;
        margin-bottom: 15px !important;
    }

    .modal-footer {
        flex-direction: column !important;
        gap: 8px !important;
    }

    .modal-footer .btn {
        width: 100% !important;
    }

    /* Select2 dropdown */
    .select2-container--default .select2-results__option {
        font-size: 13px !important;
        padding: 10px !important;
    }

    /* Final submit button */
    button[type="submit"][name="update_detail"] {
        font-size: 14px !important;
        padding: 12px !important;
    }
}

/* Small mobile (< 480px) */
@media screen and (max-width: 480px) {
    body {
        font-size: 12px !important;
    }

    .card {
        padding: 12px !important;
        margin: 0 5px 12px 5px !important;
    }

    .page-title {
        font-size: 16px !important;
    }

    .section-title {
        font-size: 14px !important;
    }

    .alert {
        font-size: 12px !important;
        padding: 10px 12px !important;
    }

    .detail-label {
        font-size: 10px !important;
    }

    .detail-value {
        font-size: 12px !important;
    }

    table {
        font-size: 10px !important;
    }

    .status-grid {
        grid-template-columns: 1fr !important;
        gap: 8px !important;
    }

    .status-item {
        padding: 10px !important;
    }

    .text-right {
        font-size: 14px !important;
    }

    .text-right[style*="font-size: 24px"] {
        font-size: 16px !important;
    }

    button[type="submit"][name="update_detail"] {
        font-size: 13px !important;
        padding: 10px !important;
    }
}

/* Landscape mode */
@media screen and (max-width: 768px) and (orientation: landscape) {
    body {
        padding-top: 60px !important;
    }

    .status-grid {
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 8px !important;
    }

    .status-item {
        padding: 8px 6px !important;
    }

    .status-item .title {
        font-size: 10px !important;
    }

    .ttd-wrapper img {
        max-height: 35px !important;
    }
}

/* Utility classes */
@media screen and (max-width: 768px) {
    /* Hide desktop-only elements */
    .desktop-only {
        display: none !important;
    }

    /* Full width elements */
    .mobile-full-width {
        width: 100% !important;
    }

    /* Better touch targets */
    button,
    a,
    input,
    select {
        min-height: 44px;
        touch-action: manipulation;
    }

    /* Prevent text selection on buttons */
    button {
        -webkit-user-select: none;
        user-select: none;
    }

    /* Smooth scrolling */
    * {
        -webkit-overflow-scrolling: touch;
    }

    /* Fix input zoom on iOS */
    input[type="text"],
    input[type="number"],
    select,
    textarea {
        font-size: 16px !important;
    }
}

/* Print - hide on mobile */
@media print {
    .action-buttons,
    .btn,
    .add-item-form {
        display: none !important;
    }
}
    </style>
</head>
<body>
    <div class="action-buttons no-print">
        <a href="list_kendaraan.php" class="btn-back">
            <i class="fas fa-arrow-left"></i>
            Kembali
        </a>
    </div>

    <div class="container">
        <?php if ($data_permintaan): ?>
        
        <!-- Alert Status -->
        <?php if ($sudah_diperiksa_sa): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle" style="font-size: 20px;"></i>
            <div>
                <strong>Status: Sudah Diperiksa SA</strong><br>
                Anda masih bisa mengedit atau menghapus detail jika terdapat kesalahan. Gunakan tombol "Reset ke Pengajuan" jika ingin mengulang dari awal.
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
            <div>
                <strong>Perhatian!</strong><br>
                Silakan tambahkan detail jasa dan sparepart, lalu klik tombol "Update Detail Perbaikan" untuk menyimpan dan mengubah status.
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="mainForm">
            <div class="card">
                <div class="page-title">
                    🔧 Form Service Advisor - Detail Perbaikan
                    
                    <?php if ($sudah_diperiksa_sa): ?>
                    <button type="button" class="btn btn-danger" style="float: right;" onclick="confirmReset()">
                        <i class="fas fa-undo"></i> Reset ke Pengajuan
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Detail Perbaikan -->
                <div class="section-title">Informasi Kendaraan</div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">No Pengajuan:</div>
                        <div class="detail-value"><?= $data_permintaan['nomor_pengajuan'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Nomor Asset:</div>
                        <div class="detail-value"><?= $data_permintaan['nopol'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Jenis Kendaraan:</div>
                        <div class="detail-value"><?= $data_permintaan['jenis_kendaraan'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Bidang:</div>
                        <div class="detail-value"><?= $data_permintaan['bidang'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Rekanan:</div>
                        <div class="detail-value"><?= $data_permintaan['nama_rekanan'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Tanggal Dibuat:</div>
                        <div class="detail-value"><?= date('d/m/Y H:i', strtotime($data_permintaan['created_at'])) ?></div>
                    </div>
                    <div class="detail-item" style="grid-column: 1 / -1;">
                        <div class="detail-label">Keluhan:</div>
                        <div class="detail-value"><?= $data_permintaan['keluhan_awal'] ?></div>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Tambah Jasa Perbaikan -->
                <div class="section-title">🔨 Jasa Perbaikan</div>
                
                <div class="add-item-form">
                    <div>
                        <label>Jasa Perbaikan</label>
                        <select id="select_jasa" class="select2-jasa" style="width: 100%;">
                            <option value="">Pilih Jasa Perbaikan</option>
                            <?php 
                            mysqli_data_seek($result_jasa, 0);
                            while($row = mysqli_fetch_assoc($result_jasa)): 
                            ?>
                                <option value="<?= $row['id_jasa'] ?>" 
                                        data-kode="<?= $row['kode_pekerjaan'] ?>"
                                        data-nama="<?= $row['nama_pekerjaan'] ?>"
                                        data-harga="<?= $row['harga'] ?>">
                                    <?= $row['kode_pekerjaan'] ?> - <?= $row['nama_pekerjaan'] ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div style="max-width: 100px;">
                        <label>Qty</label>
                        <input type="number" id="qty_jasa" value="1" min="1">
                    </div>
                    <div style="max-width: 150px;">
                        <button type="button" class="btn btn-primary" onclick="addJasa()">+ Tambah</button>
                    </div>
                </div>

                <table id="table_jasa">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Jasa Perbaikan</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-center" style="width: 150px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_jasa">
                        <?php if (mysqli_num_rows($result_jasa_detail) > 0): ?>
                            <?php while($jasa = mysqli_fetch_assoc($result_jasa_detail)): ?>
                                <tr>
                                    <td><?= $jasa['kode_pekerjaan'] ?></td>
                                    <td><?= $jasa['nama_pekerjaan'] ?></td>
                                    <td class="text-center"><?= $jasa['qty'] ?></td>
                                    <td class="text-right">Rp <?= number_format($jasa['harga'], 0, ',', '.') ?></td>
                                    <td class="text-right">Rp <?= number_format($jasa['subtotal'], 0, ',', '.') ?></td>
                                    <td class="text-center">
                                        <div class="action-btn-group">
                                            <button type="button" class="btn btn-edit btn-sm" 
                                                    onclick="editJasa(<?= $jasa['id'] ?>, '<?= $jasa['nama_pekerjaan'] ?>', <?= $jasa['qty'] ?>, <?= $jasa['harga'] ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus item ini?')">
                                                <input type="hidden" name="id_detail" value="<?= $jasa['id'] ?>">
                                                <button type="submit" name="hapus_jasa" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">Belum ada data</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="text-right" style="margin-top: 15px; font-size: 18px; font-weight: bold;">
                    Total Jasa: Rp <span id="total_jasa"><?= number_format($data_permintaan['total_perbaikan'], 0, ',', '.') ?></span>
                </div>

                <div class="divider"></div>

                <!-- Tambah Sparepart -->
                <div class="section-title">🔩 Sparepart</div>
                
                <div class="add-item-form">
                    <div>
                        <label>Sparepart</label>
                        <select id="select_sparepart" class="select2-sparepart" style="width: 100%;">
                            <option value="">Pilih Sparepart</option>
                            <?php 
                            mysqli_data_seek($result_sparepart, 0);
                            while($row = mysqli_fetch_assoc($result_sparepart)): 
                            ?>
                                <option value="<?= $row['id_sparepart'] ?>" 
                                        data-kode="<?= $row['kode_sparepart'] ?>"
                                        data-nama="<?= $row['nama_sparepart'] ?>"
                                        data-harga="<?= $row['harga'] ?>">
                                    <?= $row['kode_sparepart'] ?> - <?= $row['nama_sparepart'] ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div style="max-width: 100px;">
                        <label>Qty</label>
                        <input type="number" id="qty_sparepart" value="1" min="1">
                    </div>
                    <div style="max-width: 150px;">
                        <button type="button" class="btn btn-primary" onclick="addSparepart()">+ Tambah</button>
                    </div>
                </div>

                <table id="table_sparepart">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Sparepart</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-center" style="width: 150px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_sparepart">
                        <?php if (mysqli_num_rows($result_sparepart_detail) > 0): ?>
                            <?php while($sparepart = mysqli_fetch_assoc($result_sparepart_detail)): ?>
                                <tr>
                                    <td><?= $sparepart['kode_sparepart'] ?? '-' ?></td>
                                    <td><?= $sparepart['nama_sparepart'] ?></td>
                                    <td class="text-center"><?= $sparepart['qty'] ?></td>
                                    <td class="text-right">Rp <?= number_format($sparepart['harga'], 0, ',', '.') ?></td>
                                    <td class="text-right">Rp <?= number_format($sparepart['subtotal'], 0, ',', '.') ?></td>
                                    <td class="text-center">
                                        <div class="action-btn-group">
                                            <button type="button" class="btn btn-edit btn-sm" 
                                                    onclick="editSparepart(<?= $sparepart['id'] ?>, '<?= $sparepart['nama_sparepart'] ?>', <?= $sparepart['qty'] ?>, <?= $sparepart['harga'] ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus item ini?')">
                                                <input type="hidden" name="id_detail" value="<?= $sparepart['id'] ?>">
                                                <button type="submit" name="hapus_sparepart" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">Belum ada data</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="text-right" style="margin-top: 15px; font-size: 18px; font-weight: bold;">
                    Total Sparepart: Rp <span id="total_sparepart"><?= number_format($data_permintaan['total_sparepart'], 0, ',', '.') ?></span>
                </div>

                <div class="divider"></div>

                <!-- GRAND TOTAL -->
                <div class="text-right" style="font-size: 24px; font-weight: bold; padding: 20px; background: #f3f4f6; border-radius: 8px;">
                    <div style="color: #667eea;">GRAND TOTAL</div>
                    <div style="color: #1f2937; margin-top: 10px;">
                        Rp <span id="grand_total"><?= number_format($data_permintaan['grand_total'], 0, ',', '.') ?></span>
                    </div>
                </div>

                <div class="divider"></div>

                <?php
                /* ===============================
                   STATUS TRACKING
                ================================ */
                $sql = "
                SELECT p.*,
                    u_driver.username AS driver_nama,
                    u_driver.ttd AS driver_ttd,
                    u_sa.username AS sa_nama,
                    u_sa.ttd AS sa_ttd,
                    u_karu.username AS karu_nama,
                    u_karu.ttd AS karu_ttd
                FROM permintaan_perbaikan p
                LEFT JOIN users u_driver ON p.id_pengaju = u_driver.id_user
                LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
                LEFT JOIN users u_karu ON p.admin_karu_qc = u_karu.id_user
                WHERE p.id_permintaan = '$id_permintaan'
                LIMIT 1
                ";
                $data = mysqli_fetch_assoc(mysqli_query($connection, $sql));
                ?>
                
                <?php if ($data): ?>
                    <div class="section-title">📋 Status Tracking</div>
                    <div class="status-grid">

                        <!-- DRIVER -->
                        <div class="status-item">
                            <div class="title">Pengawas</div>

                            <?php if (!empty($data['tgl_pengajuan'])): ?>
                                <div class="status-badge status-selesai">✓ Diajukan</div>

                                <?php if (!empty($data['driver_ttd'])): ?>
                                    <div class="ttd-wrapper" style="margin:8px 0;">
                                        <img src="../uploads/ttd/<?= htmlspecialchars($data['driver_ttd']) ?>"
                                             alt="TTD Driver"
                                             style="height:50px;max-width:120px;object-fit:contain;">
                                    </div>
                                <?php endif; ?>

                                <div class="name"><?= htmlspecialchars($data['driver_nama']) ?></div>

                                <div class="time">
                                    <?= date('d/m/Y H:i', strtotime($data['tgl_pengajuan'])) ?>
                                </div>
                            <?php else: ?>
                                <div class="status-badge status-menunggu">⏳ Menunggu</div>
                            <?php endif; ?>
                        </div>

                        <!-- SERVICE ADVISOR -->
                        <div class="status-item">
                            <div class="title">Service Advisor</div>

                            <?php if (!empty($data['tgl_diperiksa_sa'])): ?>
                                <div class="status-badge status-selesai">✓ Diperiksa</div>

                                <?php if (!empty($data['sa_ttd'])): ?>
                                    <div class="ttd-wrapper" style="margin:8px 0;">
                                        <img src="../uploads/ttd/<?= htmlspecialchars($data['sa_ttd']) ?>"
                                             alt="TTD Service Advisor"
                                             style="height:50px;max-width:120px;object-fit:contain;">
                                    </div>
                                <?php endif; ?>

                                <div class="name"><?= htmlspecialchars($data['sa_nama']) ?></div>

                                <div class="time">
                                    <?= date('d/m/Y H:i', strtotime($data['tgl_diperiksa_sa'])) ?>
                                </div>
                            <?php else: ?>
                                <div class="status-badge status-menunggu">⏳ Menunggu</div>
                                <div class="name" style="font-size: 12px; color: #6b7280; margin-top: 10px;">
                                    (Akan otomatis setelah update)
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- KARU QC -->
                        <div class="status-item">
                            <div class="title">KARU QC</div>

                            <!-- STATUS MULAI -->
                            <?php if (!empty($data['tgl_disetujui_karu_qc'])): ?>
                                <div class="sub-status status-selesai">✓ Disetujui & Mulai</div>
                                <?php if (!empty($data['karu_ttd'])): ?>
                                    <div class="ttd-wrapper" style="margin:8px 0;">
                                        <img src="../uploads/ttd/<?= htmlspecialchars($data['karu_ttd']) ?>"
                                             alt="TTD KARU QC"
                                             style="height:50px;max-width:120px;object-fit:contain;">
                                    </div>
                                <?php endif; ?>
                                <div class="time" style="margin-bottom: 15px;">
                                    <?= date('d/m/Y H:i', strtotime($data['tgl_disetujui_karu_qc'])) ?>
                                </div>
                            <?php else: ?>
                                <div class="sub-status status-menunggu" style="margin-bottom: 15px;">⏳ Menunggu Mulai</div>
                            <?php endif; ?>

                            <!-- STATUS SELESAI -->
                            <?php if (!empty($data['tgl_selesai'])): ?>
                                <div class="sub-status status-selesai">✓ Selesai</div>

                                <?php if (!empty($data['karu_ttd'])): ?>
                                    <div class="ttd-wrapper" style="margin:8px 0;">
                                        <img src="../uploads/ttd/<?= htmlspecialchars($data['karu_ttd']) ?>"
                                             alt="TTD KARU QC"
                                             style="height:50px;max-width:120px;object-fit:contain;">
                                    </div>
                                <?php endif; ?>

                                <div class="name"><?= htmlspecialchars($data['karu_nama']) ?></div>

                                <div class="time">
                                    <?= date('d/m/Y H:i', strtotime($data['tgl_selesai'])) ?>
                                </div>
                            <?php else: ?>
                                <div class="sub-status status-menunggu">⏳ Menunggu Selesai</div>
                            <?php endif; ?>
                        </div>

                        <!-- REKANAN -->
                        <div class="status-item">
                            <div class="title">Rekanan</div>
                            <?php if (!empty($data_status['tgl_selesai'])): ?>
                                <div class="status-badge status-selesai">✓ Selesai</div>
                                
                                <?php if (!empty($data_permintaan['ttd_rekanan'])): ?>
                                    <div class="ttd-wrapper">
                                        <img src="../uploads/ttd_rekanan/<?= htmlspecialchars($data_permintaan['ttd_rekanan']) ?>"
                                             alt="TTD Rekanan"
                                             style="height:50px;max-width:120px;object-fit:contain;">
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($data_permintaan['nama_rekanan'])): ?>
                                    <div class="name"><?= $data_permintaan['nama_rekanan'] ?></div>
                                <?php endif; ?>
                                <div class="time"><?= date('d/m/Y H:i', strtotime($data_status['tgl_selesai'])) ?></div>
                            <?php else: ?>
                                <div class="status-badge status-menunggu">⏳ Menunggu</div>
                                <?php if (!empty($data_permintaan['nama_rekanan'])): ?>
                                    <div class="name" style="margin-top: 10px; font-size: 13px; color: #6b7280;">
                                        (<?= $data_permintaan['nama_rekanan'] ?>)
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endif; ?>

                <div class="divider"></div>

                <button type="submit" name="update_detail" class="btn btn-success" style="width: 100%; font-size: 16px; padding: 15px;">
                SIMPAN DATA & SUDAH DIPERIKSA, KIRIM KE QC
                            </button>
            </div>
        </form>
        <?php else: ?>
        <div class="card">
            <div class="text-center" style="padding: 40px;">
                <h2>Data tidak ditemukan</h2>
                <p>Silakan pilih data perbaikan yang valid</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Edit Jasa -->
    <div id="modalEditJasa" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <i class="fas fa-edit"></i> Edit Jasa Perbaikan
            </div>
            <form method="POST" id="formEditJasa">
                <input type="hidden" name="id_detail" id="edit_jasa_id">
                
                <div class="form-group">
                    <label>Nama Jasa</label>
                    <input type="text" id="edit_jasa_nama" readonly style="background: #f3f4f6;">
                </div>
                
                <div class="form-group">
                    <label>Qty</label>
                    <input type="number" name="qty" id="edit_jasa_qty" min="1" required>
                </div>
                
                <div class="form-group">
                    <label>Harga</label>
                    <input type="number" name="harga" id="edit_jasa_harga" min="0" step="0.01" required>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="closeModalJasa()">Batal</button>
                    <button type="submit" name="update_jasa" class="btn btn-success">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Sparepart -->
    <div id="modalEditSparepart" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <i class="fas fa-edit"></i> Edit Sparepart
            </div>
            <form method="POST" id="formEditSparepart">
                <input type="hidden" name="id_detail" id="edit_sparepart_id">
                
                <div class="form-group">
                    <label>Nama Sparepart</label>
                    <input type="text" id="edit_sparepart_nama" readonly style="background: #f3f4f6;">
                </div>
                
                <div class="form-group">
                    <label>Qty</label>
                    <input type="number" name="qty" id="edit_sparepart_qty" min="1" required>
                </div>
                
                <div class="form-group">
                    <label>Harga</label>
                    <input type="number" name="harga" id="edit_sparepart_harga" min="0" step="0.01" required>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="closeModalSparepart()">Batal</button>
                    <button type="submit" name="update_sparepart" class="btn btn-success">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Form Hidden untuk Reset -->
    <form method="POST" id="formReset" style="display: none;">
        <input type="hidden" name="reset_status" value="1">
    </form>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // Initialize Select2 dengan search
        $(document).ready(function() {
            $('.select2-jasa').select2({
                placeholder: 'Cari Jasa Perbaikan...',
                allowClear: true,
                width: '100%'
            });

            $('.select2-sparepart').select2({
                placeholder: 'Cari Sparepart...',
                allowClear: true,
                width: '100%'
            });
        });

        // Load data dari database dengan JOIN untuk sparepart
        let jasaData = <?= json_encode(mysqli_fetch_all(mysqli_query($connection, "SELECT * FROM perbaikan_detail WHERE id_permintaan = '$id_permintaan'"), MYSQLI_ASSOC)) ?>;
        let sparepartData = <?= json_encode(mysqli_fetch_all(mysqli_query($connection, "
            SELECT sd.*, s.kode_sparepart, s.nama_sparepart
            FROM sparepart_detail sd
            LEFT JOIN sparepart s ON sd.id_sparepart = s.id_sparepart
            WHERE sd.id_permintaan = '$id_permintaan'
        "), MYSQLI_ASSOC)) ?>;

        // Convert database data to format used by JS
        jasaData = jasaData.map(item => ({
            id: item.id_jasa,
            kode: item.kode_pekerjaan,
            nama: item.nama_pekerjaan,
            qty: parseInt(item.qty),
            harga: parseFloat(item.harga),
            subtotal: parseFloat(item.subtotal)
        }));

        // PERBAIKAN: Pastikan kode dan nama sparepart ada dengan nilai default
        sparepartData = sparepartData.map(item => ({
            id: item.id_sparepart,
            kode: item.kode_sparepart || '-',
            nama: item.nama_sparepart || 'Sparepart',
            qty: parseInt(item.qty),
            harga: parseFloat(item.harga),
            subtotal: parseFloat(item.subtotal)
        }));

        function formatRupiah(angka) {
            return new Intl.NumberFormat('id-ID').format(angka);
        }

        function addJasa() {
            const select = $('#select_jasa');
            const option = select.find(':selected');
            const qty = parseInt($('#qty_jasa').val());
            
            if (!option.val()) {
                alert('Pilih jasa perbaikan terlebih dahulu!');
                return;
            }

            const data = {
                id: option.val(),
                kode: option.data('kode'),
                nama: option.data('nama'),
                harga: parseFloat(option.data('harga')),
                qty: qty,
                subtotal: parseFloat(option.data('harga')) * qty
            };

            jasaData.push(data);
            renderJasa();
            calculateTotal();
            
            select.val(null).trigger('change');
            $('#qty_jasa').val(1);
        }

        function removeJasa(index) {
            if (confirm('Yakin ingin menghapus jasa ini?')) {
                jasaData.splice(index, 1);
                renderJasa();
                calculateTotal();
            }
        }

        function renderJasa() {
            const tbody = document.getElementById('tbody_jasa');
            
            if (jasaData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center">Belum ada data</td></tr>';
                return;
            }

            let html = '';
            jasaData.forEach((item, index) => {
                html += `
                    <tr>
                        <td>${item.kode}</td>
                        <td>${item.nama}</td>
                        <td class="text-center">${item.qty}</td>
                        <td class="text-right">Rp ${formatRupiah(item.harga)}</td>
                        <td class="text-right">Rp ${formatRupiah(item.subtotal)}</td>
                        <td class="text-center">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeJasa(${index})">
                                <i class="fas fa-trash"></i> Hapus
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            // PERBAIKAN: Hidden inputs dipisah dari loop untuk menghindari masalah struktur HTML
            jasaData.forEach((item) => {
                html += `
                    <input type="hidden" name="jasa_id[]" value="${item.id}">
                    <input type="hidden" name="jasa_kode[]" value="${item.kode}">
                    <input type="hidden" name="jasa_nama[]" value="${item.nama}">
                    <input type="hidden" name="jasa_qty[]" value="${item.qty}">
                    <input type="hidden" name="jasa_harga[]" value="${item.harga}">
                `;
            });
            
            tbody.innerHTML = html;
        }

        function addSparepart() {
            const select = $('#select_sparepart');
            const option = select.find(':selected');
            const qty = parseInt($('#qty_sparepart').val());
            
            if (!option.val()) {
                alert('Pilih sparepart terlebih dahulu!');
                return;
            }

            const data = {
                id: option.val(),
                kode: option.data('kode'),
                nama: option.data('nama'),
                harga: parseFloat(option.data('harga')),
                qty: qty,
                subtotal: parseFloat(option.data('harga')) * qty
            };

            sparepartData.push(data);
            renderSparepart();
            calculateTotal();
            
            select.val(null).trigger('change');
            $('#qty_sparepart').val(1);
        }

        function removeSparepart(index) {
            if (confirm('Yakin ingin menghapus sparepart ini?')) {
                sparepartData.splice(index, 1);
                renderSparepart();
                calculateTotal();
            }
        }

        function renderSparepart() {
            const tbody = document.getElementById('tbody_sparepart');
            
            if (sparepartData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center">Belum ada data</td></tr>';
                return;
            }
            
            let html = '';
            sparepartData.forEach((item, index) => {
                html += `
                    <tr>
                        <td>${item.kode}</td>
                        <td>${item.nama}</td>
                        <td class="text-center">${item.qty}</td>
                        <td class="text-right">Rp ${formatRupiah(item.harga)}</td>
                        <td class="text-right">Rp ${formatRupiah(item.subtotal)}</td>
                        <td class="text-center">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeSparepart(${index})">
                                <i class="fas fa-trash"></i> Hapus
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            // PERBAIKAN: Hidden inputs dipisah dan validasi id tidak kosong
            sparepartData.forEach((item) => {
                if (item.id) { // Hanya tambahkan jika id tidak kosong
                    html += `
                        <input type="hidden" name="sparepart_id[]" value="${item.id}">
                        <input type="hidden" name="sparepart_qty[]" value="${item.qty}">
                        <input type="hidden" name="sparepart_harga[]" value="${item.harga}">
                    `;
                }
            });
            
            tbody.innerHTML = html;
        }

        function calculateTotal() {
            const totalJasa = jasaData.reduce((sum, item) => sum + item.subtotal, 0);
            const totalSparepart = sparepartData.reduce((sum, item) => sum + item.subtotal, 0);
            const grandTotal = totalJasa + totalSparepart;

            document.getElementById('total_jasa').textContent = formatRupiah(totalJasa);
            document.getElementById('total_sparepart').textContent = formatRupiah(totalSparepart);
            document.getElementById('grand_total').textContent = formatRupiah(grandTotal);
        }

        // Modal Edit Jasa
        function editJasa(id, nama, qty, harga) {
            document.getElementById('edit_jasa_id').value = id;
            document.getElementById('edit_jasa_nama').value = nama;
            document.getElementById('edit_jasa_qty').value = qty;
            document.getElementById('edit_jasa_harga').value = harga;
            document.getElementById('modalEditJasa').style.display = 'block';
        }

        function closeModalJasa() {
            document.getElementById('modalEditJasa').style.display = 'none';
        }

        // Modal Edit Sparepart
        function editSparepart(id, nama, qty, harga) {
            document.getElementById('edit_sparepart_id').value = id;
            document.getElementById('edit_sparepart_nama').value = nama;
            document.getElementById('edit_sparepart_qty').value = qty;
            document.getElementById('edit_sparepart_harga').value = harga;
            document.getElementById('modalEditSparepart').style.display = 'block';
        }

        function closeModalSparepart() {
            document.getElementById('modalEditSparepart').style.display = 'none';
        }

        // Close modal ketika klik di luar modal
        window.onclick = function(event) {
            const modalJasa = document.getElementById('modalEditJasa');
            const modalSparepart = document.getElementById('modalEditSparepart');
            
            if (event.target == modalJasa) {
                closeModalJasa();
            }
            if (event.target == modalSparepart) {
                closeModalSparepart();
            }
        }

        // Confirm Reset
        function confirmReset() {
            if (confirm('⚠️ PERHATIAN!\n\nAnda akan mereset status ke Pengajuan Awal.\nSemua data detail jasa dan sparepart akan DIHAPUS PERMANEN.\n\nApakah Anda yakin ingin melanjutkan?')) {
                if (confirm('Konfirmasi sekali lagi: Yakin ingin menghapus semua data detail?')) {
                    document.getElementById('formReset').submit();
                }
            }
        }

        // Validasi sebelum submit
        document.getElementById('mainForm').addEventListener('submit', function(e) {
            if (jasaData.length === 0 && sparepartData.length === 0) {
                e.preventDefault();
                alert('❌ Minimal harus ada 1 jasa perbaikan atau sparepart!');
                return false;
            }

            if (!confirm('Apakah Anda yakin data sudah benar dan ingin menyimpan?')) {
                e.preventDefault();
                return false;
            }
        });

        // Load initial data
        renderJasa();
        renderSparepart();
        calculateTotal();
    </script>
</body>
</html>
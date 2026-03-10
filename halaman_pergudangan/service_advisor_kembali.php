<?php
// SET TIMEZONE INDONESIA
date_default_timezone_set('Asia/Jakarta');

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('pergudangan');

// ==========================
// AMBIL ID PERMINTAAN
// ==========================
$id_permintaan = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_permintaan <= 0) {
    die("ID Permintaan tidak valid");
}

// ==========================
// AMBIL DATA USER LOGIN (ADMIN)
// ==========================
$user_login = $_SESSION['username'] ?? '';
$user_ttd = $_SESSION['ttd'] ?? '';
$user_id = $_SESSION['id_user'] ?? 0;

$query_user = "SELECT username, ttd FROM users WHERE id_user = '$user_id' LIMIT 1";
$result_user = mysqli_query($connection, $query_user);
$data_user = mysqli_fetch_assoc($result_user);

if ($data_user) {
    $user_login = $data_user['username'];
    $user_ttd = $data_user['ttd'];
}

// ==========================
// PROSES KIRIM KE KARU
// ==========================
if (isset($_POST['kirim_ke_karu'])) {
    $query_kirim = "
        UPDATE permintaan_perbaikan SET
            status = 'QC'
        WHERE id_permintaan = '$id_permintaan'
    ";
    
    if (mysqli_query($connection, $query_kirim)) {
        echo "<script>
            alert('✅ Berhasil dikirim ke KARU!\\n\\nStatus diubah menjadi QC');
            window.location.href='list_kendaraan.php';
        </script>";
    } else {
        echo "<script>
            alert('❌ Gagal mengirim ke KARU: " . mysqli_error($connection) . "');
        </script>";
    }
    exit;
}

// ==========================
// PROSES UPDATE & SIMPAN (TANPA UBAH STATUS)
// ==========================
if (isset($_POST['update_data'])) {
    
    // HAPUS DATA LAMA
    mysqli_query($connection, "DELETE FROM perbaikan_detail WHERE id_permintaan = '$id_permintaan'");
    mysqli_query($connection, "DELETE FROM sparepart_detail WHERE id_permintaan = '$id_permintaan'");

    $total_jasa = 0;
    $total_sparepart = 0;

    // ======================
    // INSERT JASA BARU
    // ======================
    if (!empty($_POST['jasa_id'])) {
        foreach ($_POST['jasa_id'] as $i => $id_jasa) {
            $kode = mysqli_real_escape_string($connection, $_POST['jasa_kode'][$i]);
            $nama = mysqli_real_escape_string($connection, $_POST['jasa_nama'][$i]);
            $qty = intval($_POST['jasa_qty'][$i]);
            $harga = floatval($_POST['jasa_harga'][$i]);
            $subtotal = $qty * $harga;

            $total_jasa += $subtotal;

            mysqli_query($connection, "
                INSERT INTO perbaikan_detail (
                    id_permintaan, id_jasa, kode_pekerjaan, nama_pekerjaan, qty, harga, subtotal
                ) VALUES (
                    '$id_permintaan', '$id_jasa', '$kode', '$nama', '$qty', '$harga', '$subtotal'
                )
            ");
        }
    }

    // ======================
    // INSERT SPAREPART BARU
    // ======================
    if (!empty($_POST['sparepart_id'])) {
        foreach ($_POST['sparepart_id'] as $i => $id_sparepart) {
            if (empty($id_sparepart)) continue; // Skip jika kosong

            $qty = intval($_POST['sparepart_qty'][$i]);
            $harga = floatval($_POST['sparepart_harga'][$i]);
            $subtotal = $qty * $harga;

            $total_sparepart += $subtotal;

            mysqli_query($connection, "
                INSERT INTO sparepart_detail (
                    id_permintaan, id_sparepart, qty, harga, subtotal
                ) VALUES (
                    '$id_permintaan', '$id_sparepart', '$qty', '$harga', '$subtotal'
                )
            ");
        }
    }

    // ======================
    // UPDATE TOTAL SAJA (STATUS TIDAK BERUBAH)
    // ======================
    $grand_total = $total_jasa + $total_sparepart;

    $query_update = "
        UPDATE permintaan_perbaikan SET
            total_perbaikan = '$total_jasa',
            total_sparepart = '$total_sparepart',
            grand_total = '$grand_total'
        WHERE id_permintaan = '$id_permintaan'
    ";
    
    if (mysqli_query($connection, $query_update)) {
        echo "<script>
            alert('✅ Data berhasil disimpan!\\n\\n📊 Total diperbaharui:\\n• Jasa: Rp " . number_format($total_jasa, 0, ',', '.') . "\\n• Sparepart: Rp " . number_format($total_sparepart, 0, ',', '.') . "\\n• Grand Total: Rp " . number_format($grand_total, 0, ',', '.') . "');
            window.location.href='list_kendaraan.php';
        </script>";
    } else {
        echo "<script>
            alert('❌ Gagal menyimpan data: " . mysqli_error($connection) . "');
        </script>";
    }
    exit;
}

// ==========================
// AMBIL DATA PERMINTAAN
// ==========================
$query_permintaan = "
    SELECT p.*, k.nopol, k.jenis_kendaraan, k.bidang,
           u_driver.username AS driver_nama,
           u_sa.username AS sa_nama,
           u_karu.username AS karu_nama,
           r.nama_rekanan
    FROM permintaan_perbaikan p
    LEFT JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    LEFT JOIN users u_driver ON p.id_pengaju = u_driver.id_user
    LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
    LEFT JOIN users u_karu ON p.admin_karu_qc = u_karu.id_user
    LEFT JOIN rekanan r ON p.id_rekanan = r.id_rekanan
    WHERE p.id_permintaan = '$id_permintaan'
";
$result_permintaan = mysqli_query($connection, $query_permintaan);
$data_permintaan = mysqli_fetch_assoc($result_permintaan);

if (!$data_permintaan) {
    die("Data permintaan tidak ditemukan");
}

// ==========================
// AMBIL DETAIL JASA
// ==========================
$query_jasa_detail = "
    SELECT pd.*, mj.kode_pekerjaan, mj.nama_pekerjaan
    FROM perbaikan_detail pd
    LEFT JOIN master_jasa mj ON pd.id_jasa = mj.id_jasa
    WHERE pd.id_permintaan = '$id_permintaan'
";
$result_jasa_detail = mysqli_query($connection, $query_jasa_detail);

// ==========================
// AMBIL DETAIL SPAREPART
// ==========================
$query_sparepart_detail = "
    SELECT sd.*, s.kode_sparepart, s.nama_sparepart
    FROM sparepart_detail sd
    LEFT JOIN sparepart s ON sd.id_sparepart = s.id_sparepart
    WHERE sd.id_permintaan = '$id_permintaan'
";
$result_sparepart_detail = mysqli_query($connection, $query_sparepart_detail);

// ==========================
// DATA MASTER - UNTUK DROPDOWN
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
    <title>Update Jasa & Sparepart</title>
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

        .alert-info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
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
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
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
            background: rgb(10, 171, 117);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
            padding: 8px 15px;
        }

        .btn-danger:hover {
            background: #dc2626;
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

        .action-buttons {
            margin-bottom: 20px;
            margin-left: 290px;
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

        .add-item-form {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            align-items: end;
        }

        .add-item-form > div {
            flex: 1;
        }

        .timeline-note {
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 6px;
            position: relative;
        }

        .timeline-note:last-child {
            margin-bottom: 0;
        }

        .note-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .note-icon {
            font-size: 18px;
            margin-right: 10px;
        }

        .note-title {
            font-size: 15px;
            font-weight: 600;
        }

        .note-content {
            color: #1f2937;
            line-height: 1.6;
            padding-left: 28px;
        }

        .note-timestamp {
            margin-top: 8px;
            padding-left: 28px;
        }

        .note-timestamp small {
            color: #78716c;
            font-size: 12px;
        }

        .notes-empty {
            text-align: center;
            color: #9ca3af;
            padding: 30px;
        }

        .notes-empty i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .button-group button {
            flex: 1;
        }

        @media screen and (max-width: 1024px) {
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
            body, html {
                overflow-x: hidden !important;
                max-width: 100vw;
            }

            body {
                padding: 10px !important;
                padding-top: 70px !important;
            }

            .container {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

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

            .card {
                padding: 15px !important;
                border-radius: 8px !important;
                margin: 0 10px 15px 10px !important;
            }

            .page-title {
                font-size: 18px !important;
                margin-bottom: 20px !important;
                padding-bottom: 12px !important;
                line-height: 1.4;
            }

            .section-title {
                font-size: 16px !important;
                margin: 20px 0 12px 0 !important;
                padding-bottom: 6px !important;
            }

            .alert {
                padding: 12px 15px !important;
                font-size: 13px !important;
                margin-bottom: 15px !important;
                flex-direction: column;
                align-items: flex-start !important;
            }

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

            .timeline-note {
                margin-bottom: 15px !important;
                padding: 12px !important;
            }

            .note-header {
                margin-bottom: 8px !important;
            }

            .note-icon {
                font-size: 16px !important;
                margin-right: 8px !important;
            }

            .note-title {
                font-size: 13px !important;
            }

            .note-content {
                font-size: 13px !important;
                padding-left: 24px !important;
                line-height: 1.5 !important;
            }

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

            table {
                font-size: 11px !important;
                display: block;
                overflow-x: auto;
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

            .button-group {
                flex-direction: column;
            }

            .btn {
                font-size: 13px !important;
                padding: 10px 20px !important;
            }
        }
    </style>
</head>
<body>
    <div class="action-buttons">
        <a href="list_kendaraan.php" class="btn-back">
            <i class="fas fa-arrow-left"></i>
            Kembali
        </a>
    </div>

    <div class="container">
        <form method="POST" id="mainForm">
            <div class="card">
                <div class="page-title">
                    🔧 Update Jasa & Sparepart
                </div>

                <!-- Info Alert -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Informasi:</strong> Update data jasa dan sparepart
                    </div>
                </div>
                
                <!-- Detail Perbaikan -->
                <div class="section-title">📋 Informasi Kendaraan</div>
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
                </div>

                <div class="divider"></div>

                <!-- Riwayat Catatan Lengkap -->
                <div class="section-title">📝 Riwayat Catatan</div>
                <div style="background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                    
                    <!-- Keluhan Awal (Pengaju) -->
                    <?php if (!empty($data_permintaan['keluhan_awal'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #ef4444;">
                        <div class="note-header">
                            <i class="fas fa-exclamation-triangle note-icon" style="color: #ef4444;"></i>
                            <strong class="note-title" style="color: #ef4444;">Keluhan Awal (Pengaju)</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['keluhan_awal'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Catatan QC -->
                    <?php if (!empty($data_permintaan['catatan_qc'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #3b82f6;">
                        <div class="note-header">
                            <i class="fas fa-clipboard-check note-icon" style="color: #3b82f6;"></i>
                            <strong class="note-title" style="color: #3b82f6;">Catatan QC</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['catatan_qc'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Catatan SA Sebelumnya -->
                    <?php if (!empty($data_permintaan['catatan_sa'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #8b5cf6;">
                        <div class="note-header">
                            <i class="fas fa-user-tie note-icon" style="color: #8b5cf6;"></i>
                            <strong class="note-title" style="color: #8b5cf6;">Catatan SA Sebelumnya</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['catatan_sa'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Catatan Pengawas -->
                    <?php if (!empty($data_permintaan['catatan_pengawas'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #10b981;">
                        <div class="note-header">
                            <i class="fas fa-user-shield note-icon" style="color: #10b981;"></i>
                            <strong class="note-title" style="color: #10b981;">Catatan Pengawas</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['catatan_pengawas'])) ?>
                        </div>
                        <?php if (!empty($data_permintaan['tgl_selesaian_pengawas'])): ?>
                        <div class="note-timestamp">
                            <small>
                                <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($data_permintaan['tgl_selesaian_pengawas'])) ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Jika tidak ada catatan sama sekali -->
                    <?php if (empty($data_permintaan['keluhan_awal']) && 
                              empty($data_permintaan['catatan_qc']) && 
                              empty($data_permintaan['catatan_sa']) &&
                              empty($data_permintaan['catatan_pengawas'])): ?>
                    <div class="notes-empty">
                        <i class="fas fa-inbox"></i>
                        <p>Belum ada catatan tersedia</p>
                    </div>
                    <?php endif; ?>

                </div>

                <div class="divider"></div>

                <!-- Jasa Perbaikan -->
                <div class="section-title">🔨 Jasa Perbaikan</div>
                
                <div class="add-item-form">
                    <div>
                        <label>Pilih Jasa</label>
                        <select id="select_jasa" class="select2-jasa" style="width: 100%;">
                            <option value="">-- Pilih Jasa --</option>
                            <?php 
                            mysqli_data_seek($result_jasa, 0);
                            while($row = mysqli_fetch_assoc($result_jasa)): 
                            ?>
                                <option value="<?= $row['id_jasa'] ?>" 
                                        data-kode="<?= $row['kode_pekerjaan'] ?>"
                                        data-nama="<?= $row['nama_pekerjaan'] ?>"
                                        data-harga="<?= $row['harga'] ?>">
                                    <?= $row['kode_pekerjaan'] ?> - <?= $row['nama_pekerjaan'] ?> (Rp <?= number_format($row['harga'], 0, ',', '.') ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div style="max-width: 100px;">
                        <label>Qty</label>
                        <input type="number" id="qty_jasa" value="1" min="1">
                    </div>
                    <div style="max-width: 150px;">
                        <button type="button" class="btn btn-primary" onclick="addJasa()">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama Jasa</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-center" style="width: 100px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_jasa">
                        <tr>
                            <td colspan="6" class="text-center">Belum ada data</td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-right" style="margin-top: 15px; font-size: 18px; font-weight: bold;">
                    Total Jasa: Rp <span id="total_jasa">0</span>
                </div>

                <div class="divider"></div>

                <!-- Sparepart -->
                <div class="section-title">🔩 Sparepart</div>
                
                <div class="add-item-form">
                    <div>
                        <label>Pilih Sparepart</label>
                        <select id="select_sparepart" class="select2-sparepart" style="width: 100%;">
                            <option value="">-- Pilih Sparepart --</option>
                            <?php 
                            mysqli_data_seek($result_sparepart, 0);
                            while($row = mysqli_fetch_assoc($result_sparepart)): 
                            ?>
                                <option value="<?= $row['id_sparepart'] ?>" 
                                        data-kode="<?= $row['kode_sparepart'] ?>"
                                        data-nama="<?= $row['nama_sparepart'] ?>"
                                        data-harga="<?= $row['harga'] ?>">
                                    <?= $row['kode_sparepart'] ?> - <?= $row['nama_sparepart'] ?> (Rp <?= number_format($row['harga'], 0, ',', '.') ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div style="max-width: 100px;">
                        <label>Qty</label>
                        <input type="number" id="qty_sparepart" value="1" min="1">
                    </div>
                    <div style="max-width: 150px;">
                        <button type="button" class="btn btn-primary" onclick="addSparepart()">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama Sparepart</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-center" style="width: 100px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_sparepart">
                        <tr>
                            <td colspan="6" class="text-center">Belum ada data</td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-right" style="margin-top: 15px; font-size: 18px; font-weight: bold;">
                    Total Sparepart: Rp <span id="total_sparepart">0</span>
                </div>

                <div class="divider"></div>

                <!-- GRAND TOTAL -->
                <div class="text-right" style="font-size: 24px; font-weight: bold; padding: 20px; background: #f3f4f6; border-radius: 8px;">
                    <div style="color: #667eea;">GRAND TOTAL</div>
                    <div style="color: #1f2937; margin-top: 10px;">
                        Rp <span id="grand_total">0</span>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Button Actions -->
                <div class="button-group">
                    <button type="submit" name="update_data" class="btn btn-success">
                        <i class="fas fa-save"></i> SIMPAN PERUBAHAN
                    </button>
                    
                    <?php if ($data_permintaan['persetujuan_pengawas'] == 'Disetujui'): ?>
                    <button type="submit" name="kirim_ke_karu" class="btn btn-warning" onclick="return confirm('Kirim ke KARU untuk QC?\n\nStatus akan diubah menjadi QC');">
                        <i class="fas fa-paper-plane"></i> KIRIM KE KARU
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- jQuery & Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // Initialize Select2
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

        // Load data dari database
        let jasaData = <?= json_encode(mysqli_fetch_all($result_jasa_detail, MYSQLI_ASSOC)) ?>;
        let sparepartData = <?= json_encode(mysqli_fetch_all($result_sparepart_detail, MYSQLI_ASSOC)) ?>;

        // Convert ke format yang dibutuhkan
        jasaData = jasaData.map(item => ({
            id: item.id_jasa,
            kode: item.kode_pekerjaan,
            nama: item.nama_pekerjaan,
            qty: parseInt(item.qty),
            harga: parseFloat(item.harga),
            subtotal: parseFloat(item.subtotal)
        }));

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
                        <td data-label="Kode">${item.kode}
                            <input type="hidden" name="jasa_id[]" value="${item.id}">
                            <input type="hidden" name="jasa_kode[]" value="${item.kode}">
                            <input type="hidden" name="jasa_nama[]" value="${item.nama}">
                            <input type="hidden" name="jasa_qty[]" value="${item.qty}">
                            <input type="hidden" name="jasa_harga[]" value="${item.harga}">
                        </td>
                        <td data-label="Nama Jasa">${item.nama}</td>
                        <td data-label="Qty" class="text-center">${item.qty}</td>
                        <td data-label="Harga" class="text-right">Rp ${formatRupiah(item.harga)}</td>
                        <td data-label="Subtotal" class="text-right">Rp ${formatRupiah(item.subtotal)}</td>
                        <td data-label="Aksi" class="text-center">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeJasa(${index})">
                                <i class="fas fa-trash"></i> Hapus
                            </button>
                        </td>
                    </tr>
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
                        <td data-label="Kode">${item.kode}
                            <input type="hidden" name="sparepart_id[]" value="${item.id}">
                            <input type="hidden" name="sparepart_qty[]" value="${item.qty}">
                            <input type="hidden" name="sparepart_harga[]" value="${item.harga}">
                        </td>
                        <td data-label="Nama Sparepart">${item.nama}</td>
                        <td data-label="Qty" class="text-center">${item.qty}</td>
                        <td data-label="Harga" class="text-right">Rp ${formatRupiah(item.harga)}</td>
                        <td data-label="Subtotal" class="text-right">Rp ${formatRupiah(item.subtotal)}</td>
                        <td data-label="Aksi" class="text-center">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeSparepart(${index})">
                                <i class="fas fa-trash"></i> Hapus
                            </button>
                        </td>
                    </tr>
                `;
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

        // Validasi sebelum submit
        document.getElementById('mainForm').addEventListener('submit', function(e) {
            // Skip validasi jika tombol kirim ke KARU yang diklik
            if (e.submitter && e.submitter.name === 'kirim_ke_karu') {
                return true;
            }

            if (jasaData.length === 0 && sparepartData.length === 0) {
                e.preventDefault();
                alert('❌ Minimal harus ada 1 jasa perbaikan atau sparepart!');
                return false;
            }

            if (!confirm('Apakah Anda yakin ingin menyimpan perubahan?')) {
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
<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('admin');
// Mulai output buffering untuk menghindari error header
ob_start();

include "navbar.php";

/* =====================
   FUNGSI AUTO PILIH FOTO DARI FOLDER
===================== */
function getRandomPhotoFromFolder() {
    $folder = '../fotodata/';
    
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
        return null;
    }
    
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $files = [];
    
    if ($handle = opendir($folder)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) {
                    $files[] = $file;
                }
            }
        }
        closedir($handle);
    }
    
    if (count($files) > 0) {
        return $files[array_rand($files)];
    }
    
    return null;
}

/* =====================
   SIMPAN REKANAN
===================== */
if (isset($_POST['simpan'])) {

    $nama   = mysqli_real_escape_string($connection, $_POST['nama_rekanan']);
    $alamat = mysqli_real_escape_string($connection, $_POST['alamat']);
    $telp   = mysqli_real_escape_string($connection, $_POST['telp']);

    $ttd = null;
    
    $randomPhoto = getRandomPhotoFromFolder();
    
    if ($randomPhoto) {
        $ext = pathinfo($randomPhoto, PATHINFO_EXTENSION);
        $ttd = 'ttd_' . time() . '_' . uniqid() . '.' . $ext;
        copy('../fotodata/' . $randomPhoto, '../uploads/ttd_rekanan/' . $ttd);
    }

    mysqli_query($connection, "INSERT INTO rekanan 
        (nama_rekanan, alamat, telp, ttd_rekanan, aktif)
        VALUES ('$nama','$alamat','$telp','$ttd','Y')");

    echo "<script>
        alert('✅ Data rekanan berhasil ditambahkan dengan QR Code otomatis!');
        window.location.href='rekanan.php?success=create';
    </script>";
    exit;
}

/* =====================
   UPDATE REKANAN
===================== */
if (isset($_POST['update'])) {

    $id     = mysqli_real_escape_string($connection, $_POST['id_rekanan']);
    $nama   = mysqli_real_escape_string($connection, $_POST['nama_rekanan']);
    $alamat = mysqli_real_escape_string($connection, $_POST['alamat']);
    $telp   = mysqli_real_escape_string($connection, $_POST['telp']);
    $aktif  = mysqli_real_escape_string($connection, $_POST['aktif']);

    $old = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT ttd_rekanan FROM rekanan WHERE id_rekanan='$id'")
    );

    $ttd_sql = "";

    if (isset($_POST['refresh_foto'])) {
        if (!empty($old['ttd_rekanan']) && file_exists('../uploads/ttd_rekanan/'.$old['ttd_rekanan'])) {
            unlink('../uploads/ttd_rekanan/'.$old['ttd_rekanan']);
        }

        $randomPhoto = getRandomPhotoFromFolder();
        
        if ($randomPhoto) {
            $ext = pathinfo($randomPhoto, PATHINFO_EXTENSION);
            $ttd = 'ttd_' . time() . '_' . uniqid() . '.' . $ext;
            copy('../fotodata/' . $randomPhoto, '../uploads/ttd_rekanan/' . $ttd);
            $ttd_sql = ", ttd_rekanan='$ttd'";
        }
    }

    mysqli_query($connection, "UPDATE rekanan SET
        nama_rekanan='$nama',
        alamat='$alamat',
        telp='$telp',
        aktif='$aktif'
        $ttd_sql
        WHERE id_rekanan='$id'");

    echo "<script>
        alert('✅ Data rekanan berhasil diperbarui!');
        window.location.href='rekanan.php?success=update';
    </script>";
    exit;
}

/* =====================
   HAPUS REKANAN - CEK RELASI
===================== */
if (isset($_GET['hapus'])) {

    $id = mysqli_real_escape_string($connection, $_GET['hapus']);

    $check_perm = mysqli_query($connection, "
        SELECT COUNT(*) AS total
        FROM permintaan_perbaikan
        WHERE id_rekanan = '$id'
    ");

    $permintaan = mysqli_fetch_assoc($check_perm);

    if ($permintaan['total'] > 0) {
        echo "<script>
            alert('⚠️ Data tidak dapat dihapus!\\n\\nData rekanan ini masih digunakan pada {$permintaan['total']} permintaan perbaikan\\n\\nHapus data terkait terlebih dahulu atau nonaktifkan data ini.');
            window.location.href='rekanan.php?error=relasi&total={$permintaan['total']}';
        </script>";
        exit;
    }

    $old = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT ttd_rekanan FROM rekanan WHERE id_rekanan='$id'")
    );

    if (!empty($old['ttd_rekanan']) && file_exists('../uploads/ttd_rekanan/'.$old['ttd_rekanan'])) {
        unlink('../uploads/ttd_rekanan/'.$old['ttd_rekanan']);
    }

    mysqli_query($connection, "DELETE FROM rekanan WHERE id_rekanan='$id'");

    echo "<script>
        alert('✅ Data rekanan berhasil dihapus!');
        window.location.href='rekanan.php?success=delete';
    </script>";
    exit;
}

/* =====================
   NONAKTIFKAN REKANAN
===================== */
if (isset($_GET['nonaktif'])) {
    $id_rekanan = mysqli_real_escape_string($connection, $_GET['nonaktif']);
    mysqli_query($connection,"UPDATE rekanan SET aktif='N' WHERE id_rekanan='$id_rekanan'");
    echo "<script>window.location.href='rekanan.php?success=nonaktif';</script>";
    exit;
}

/* =====================
   AKTIFKAN KEMBALI REKANAN
===================== */
if (isset($_GET['aktifkan'])) {
    $id_rekanan = mysqli_real_escape_string($connection, $_GET['aktifkan']);
    mysqli_query($connection,"UPDATE rekanan SET aktif='Y' WHERE id_rekanan='$id_rekanan'");
    echo "<script>window.location.href='rekanan.php?success=aktifkan';</script>";
    exit;
}

/* =====================
   MODE EDIT
===================== */
$edit = false;
if (isset($_GET['edit'])) {
    $edit = true;
    $id   = mysqli_real_escape_string($connection, $_GET['edit']);
    $e    = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT * FROM rekanan WHERE id_rekanan='$id'")
    );
}

/* =====================
   SEARCH & FILTER STATUS
===================== */
$keyword       = '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'semua';
$where_conditions = [];

if (isset($_GET['search'])) {
    $keyword = mysqli_real_escape_string($connection, $_GET['search']);
    $where_conditions[] = "(nama_rekanan LIKE '%$keyword%' OR alamat LIKE '%$keyword%' OR telp LIKE '%$keyword%')";
}

if ($filter_status == 'aktif') {
    $where_conditions[] = "aktif='Y'";
} elseif ($filter_status == 'nonaktif') {
    $where_conditions[] = "aktif='N'";
}

$where = count($where_conditions) > 0 ? "WHERE " . implode(' AND ', $where_conditions) : "";

$per_page = 20;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

$result_count = mysqli_query($connection, "SELECT COUNT(*) AS total FROM rekanan $where");
$row_count    = $result_count ? mysqli_fetch_assoc($result_count) : ['total' => 0];
$total_rows   = (int)($row_count['total'] ?? 0);
$total_pages  = $total_rows > 0 ? (int)ceil($total_rows / $per_page) : 1;

$photoCount = 0;
if (is_dir('../fotodata/')) {
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if ($handle = opendir('../fotodata/')) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) $photoCount++;
            }
        }
        closedir($handle);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Management Rekanan</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        display: flex;
        background:rgb(185, 224, 204);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        min-height: 100vh;
    }
    .app-wrapper { display: flex; width: 100%; min-height: 100vh; }
    .main-content { flex: 1; padding: 30px; }
    @media (max-width: 1023px) {
        .main-content { margin-left: 0; padding-top: 90px; padding: 20px; }
    }
    .layout-container {
        display: grid;
        grid-template-columns: 420px 1fr;
        gap: 25px;
        max-width: 100%;
    }
    @media (max-width: 992px) {
        .layout-container { grid-template-columns: 1fr; }
    }
    .form-section {
        background: rgba(255,255,255,0.98);
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        height: fit-content;
        position: sticky;
        top: 30px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        animation: slideInLeft 0.5s ease-out;
    }
    @keyframes slideInLeft {
        from { opacity: 0; transform: translateX(-30px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    @keyframes slideInRight {
        from { opacity: 0; transform: translateX(30px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    .form-section h4 {
        color: #667eea;
        font-weight: 700;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 3px solid #667eea;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .form-section h4 i { font-size: 1.5rem; }
    .data-section {
        background: rgba(255,255,255,0.98);
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        animation: slideInRight 0.5s ease-out;
    }
    .data-section h4 { color: #667eea; font-weight: 700; display: flex; align-items: center; gap: 10px; }
    .table-wrapper {
        overflow-x: auto;
        overflow-y: auto;
        max-height: calc(100vh - 250px);
        margin-top: 20px;
        border-radius: 12px;
        border: 1px solid #e0e0e0;
    }
    .table-wrapper::-webkit-scrollbar { width: 10px; height: 10px; }
    .table-wrapper::-webkit-scrollbar-track { background: linear-gradient(135deg,#f5f7fa,#c3cfe2); border-radius: 10px; }
    .table-wrapper::-webkit-scrollbar-thumb { background: linear-gradient(135deg,#667eea,#764ba2); border-radius: 10px; }
    .table-wrapper::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg,#764ba2,#667eea); }
    .search-box { position: relative; margin-bottom: 20px; }
    .search-box input {
        width: 100%; padding: 12px 16px 12px 45px;
        border-radius: 50px; border: 2px solid #e0e0e0;
        transition: all 0.3s; font-size: 0.95rem;
    }
    .search-box input:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102,126,234,0.1);
        transform: translateY(-2px); outline: none;
    }
    .search-box i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #667eea; font-size: 1.1rem; }
    .table { margin-bottom: 0; width: 100%; border-collapse: collapse; }
    .table thead th {
        position: sticky; top: 0;
        background:rgb(9, 120, 83); color: white; z-index: 10;
        font-weight: 600; text-transform: uppercase;
        font-size: 0.85rem; letter-spacing: 0.5px;
        padding: 15px 12px; border: none;
    }
    .table tbody tr { transition: all 0.3s; border-bottom: 1px solid #f0f0f0; background: white; }
    .table tbody tr:hover {
        background: linear-gradient(to right, rgba(102,126,234,0.05), rgba(118,75,162,0.05));
        transform: scale(1.01);
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
    .table tbody td { padding: 12px; vertical-align: middle; }
    .form-label { font-weight: 600; margin-bottom: 10px; color: #333; display: flex; align-items: center; gap: 8px; }
    .form-label i { color: #667eea; }
    .form-control, .form-select {
        width: 100%; border-radius: 12px; border: 2px solid #e0e0e0;
        padding: 12px 16px; transition: all 0.3s; font-size: 0.95rem;
    }
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102,126,234,0.1);
        transform: translateY(-2px); outline: none;
    }
    textarea.form-control { resize: vertical; min-height: 80px; }
    .preview-image {
        max-height: 100px; margin-top: 12px; border-radius: 12px;
        border: 3px solid #667eea; padding: 5px; background: white;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .btn {
        border-radius: 12px; padding: 12px 24px; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.5px;
        transition: all 0.3s; border: none; font-size: 0.9rem;
        cursor: pointer; text-decoration: none; display: inline-block; text-align: center;
    }
    .btn:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.2); }
    .btn:active { transform: translateY(-1px); }
    .btn-primary { background:rgb(9, 120, 83); color: white; }
    .btn-success { background: linear-gradient(135deg,#56ab2f,#a8e063); color: white; }
    .btn-warning { background: linear-gradient(135deg,#f093fb,#f5576c); color: white; border: none; }
    .btn-danger  { background: linear-gradient(135deg,#eb3349,#f45c43); color: white; }
    .btn-secondary { background: linear-gradient(135deg,#bdc3c7,#95a5a6); color: white; }
    .btn-info    { background: linear-gradient(135deg,#00c6ff,#0072ff); color: white; }
    .btn-orange  { background: linear-gradient(135deg,#ff9966,#ff5e62); color: white; }
    .btn-group-action { display: flex; gap: 8px; justify-content: flex-start; align-items: center; flex-wrap: nowrap; }
    .btn-sm { padding: 8px 16px; font-size: 0.85rem; }
    .empty-state { text-align: center; padding: 60px 20px; color: #999; }
    .empty-state i {
        font-size: 4rem; margin-bottom: 20px; opacity: 0.3;
        background: linear-gradient(135deg,#667eea,#764ba2);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .empty-state p { font-size: 1.1rem; font-weight: 500; }
    .modal-overlay {
        display: none; position: fixed; top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.8); z-index: 9999;
        justify-content: center; align-items: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-dialog { position: relative; max-width: 90%; max-height: 90vh; }
    .modal-content { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
    .modal-header {
        background: linear-gradient(135deg,#667eea,#764ba2); color: white;
        padding: 20px; display: flex; justify-content: space-between; align-items: center;
    }
    .modal-title { margin: 0; font-size: 1.2rem; font-weight: 600; }
    .btn-close {
        background: transparent; border: none; color: white; font-size: 1.5rem;
        cursor: pointer; width: 30px; height: 30px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 50%; transition: all 0.3s;
    }
    .btn-close:hover { background: rgba(255,255,255,0.2); transform: rotate(90deg); }
    .modal-body { padding: 20px; text-align: center; }
    .modal-body img { max-width: 100%; max-height: 70vh; border-radius: 10px; }
    .mb-3 { margin-bottom: 1rem; }
    .mb-4 { margin-bottom: 1.5rem; }
    .d-grid { display: grid; }
    .gap-2 { gap: 0.5rem; }
    .d-flex { display: flex; }
    .justify-content-between { justify-content: space-between; }
    .align-items-center { align-items: center; }
    .mb-0 { margin-bottom: 0; }
    .text-center { text-align: center; }
    .text-muted { color: #6c757d; }
    .text-uppercase-input { text-transform: uppercase; }
    .filter-select {
        cursor: pointer; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 12px center; padding-right: 35px;
    }
    @media (max-width: 1023px) {
        .app-wrapper { flex-direction: column; }
        .main-content { margin-left: 0 !important; padding: 15px !important; padding-top: 80px !important; }
        .layout-container { grid-template-columns: 1fr !important; gap: 15px !important; }
        .form-section { position: relative !important; top: 0 !important; padding: 20px !important; }
        .table { display: block !important; }
        .table thead { display: none !important; }
        .table tbody { display: block !important; }
        .table tbody tr { display: block !important; margin-bottom: 15px !important; border: 2px solid #e0e0e0 !important; border-radius: 12px !important; padding: 15px !important; }
        .table tbody td { display: block !important; width: 100% !important; text-align: left !important; padding: 8px 0 !important; border: none !important; }
        .table tbody td::before { content: attr(data-label); font-weight: 700; color: #667eea; display: block; margin-bottom: 5px; font-size: 12px; }
    }
</style>
</head>
<body>

<div class="app-wrapper">
<div class="main-content">

    <!-- Alert Messages -->
    <?php if (isset($_GET['success'])): ?>
    <div class="bg-green-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg alert-success-notification">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-2xl mr-3"></i>
            <span>
                <?php
                if ($_GET['success'] == 'create')    echo 'Data berhasil ditambahkan!';
                elseif ($_GET['success'] == 'update')   echo 'Data berhasil diperbarui!';
                elseif ($_GET['success'] == 'delete')   echo 'Data berhasil dihapus permanen!';
                elseif ($_GET['success'] == 'nonaktif') echo 'Data berhasil dinonaktifkan!';
                elseif ($_GET['success'] == 'aktifkan') echo 'Data berhasil diaktifkan kembali!';
                ?>
            </span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="bg-red-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg alert-error-notification">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
            <span>
                <?php
                if ($_GET['error'] == 'relasi') {
                    $total = isset($_GET['total']) ? htmlspecialchars($_GET['total']) : '0';
                    echo 'Data tidak dapat dihapus! Rekanan ini masih digunakan pada <strong>' . $total . '</strong> permintaan perbaikan';
                }
                ?>
            </span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <div class="layout-container">

        <!-- FORM SECTION (KIRI) -->
        <div class="form-section">
            <h4>
                <i class="fas fa-<?= $edit ? 'edit' : 'plus-circle' ?>"></i>
                <?= $edit ? 'Edit Rekanan' : 'Tambah Rekanan' ?>
            </h4>

            <form method="POST">
                <?php if ($edit): ?>
                    <input type="hidden" name="id_rekanan" value="<?= $e['id_rekanan'] ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-user"></i> Nama Rekanan *
                    </label>
                    <input type="text" name="nama_rekanan" class="form-control text-uppercase-input"
                           id="namaRekanan"
                           value="<?= $edit ? htmlspecialchars($e['nama_rekanan']) : '' ?>"
                           placeholder="Masukkan nama rekanan"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-phone"></i> No. Telepon
                    </label>
                    <input type="text"
                           name="telp"
                           class="form-control"
                           value="<?= $edit ? htmlspecialchars($e['telp']) : '' ?>"
                           placeholder="Contoh: 081*********"
                           inputmode="numeric"
                           pattern="[0-9]*"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-map-marker-alt"></i> Alamat
                    </label>
                    <textarea name="alamat" class="form-control text-uppercase-input" rows="3"
                              id="alamatRekanan"
                              placeholder="Masukkan alamat lengkap"><?= $edit ? htmlspecialchars($e['alamat']) : '' ?></textarea>
                </div>

                <?php if ($edit): ?>
                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-toggle-on"></i> Status Aktif
                    </label>
                    <select name="aktif" class="form-select">
                        <option value="Y" <?= ($e['aktif'] ?? 'Y') == 'Y' ? 'selected' : '' ?>>✅ Aktif</option>
                        <option value="N" <?= ($e['aktif'] ?? 'Y') == 'N' ? 'selected' : '' ?>>❌ Tidak Aktif</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>
                        Rekanan yang tidak aktif tidak akan muncul di daftar pilihan
                    </p>
                </div>
                <?php else: ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-3">
                    <p class="text-xs text-green-700">
                        <i class="fas fa-check-circle mr-1"></i>
                        Data baru otomatis berstatus <strong>AKTIF</strong>
                    </p>
                </div>
                <?php endif; ?>

                <div class="mb-4">
                    <label class="form-label">
                        <i class="fas fa-qrcode"></i> QR CODE Otomatis
                    </label>
                    <?php if ($edit && !empty($e['ttd_rekanan'])): ?>
                        <img src="../uploads/ttd_rekanan/<?= $e['ttd_rekanan'] ?>"
                             class="preview-image" alt="QR Code">
                        <div class="form-check mt-3" style="padding-left: 1.5rem;">
                            <input type="checkbox" name="refresh_foto" id="refreshFoto"
                                   class="form-check-input" value="1"
                                   style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="refreshFoto" style="margin-left: 8px; cursor: pointer; font-size: 0.9rem;">
                                <i class="fas fa-sync-alt"></i> Ganti dengan QR Code baru
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="info-box-qr" style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 12px; border-radius: 8px; margin-top: 10px;">
                            <i class="fas fa-info-circle" style="color: #2196F3;"></i>
                            <span style="font-size: 0.9rem; color: #333; margin-left: 8px;">
                                QR Code akan dipilih otomatis
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" name="<?= $edit ? 'update' : 'simpan' ?>"
                            class="btn btn-<?= $edit ? 'success' : 'primary' ?>">
                        <i class="fas fa-<?= $edit ? 'check' : 'save' ?>"></i>
                        <?= $edit ? 'Update Data' : 'Simpan Data' ?>
                    </button>
                    <?php if ($edit): ?>
                        <a href="rekanan.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- DATA SECTION (KANAN) -->
        <div class="data-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">
                    <i class="fas fa-list"></i> Data Rekanan
                </h4>
                <?php
                $total_aktif    = mysqli_num_rows(mysqli_query($connection, "SELECT id_rekanan FROM rekanan WHERE aktif='Y'"));
                $total_nonaktif = mysqli_num_rows(mysqli_query($connection, "SELECT id_rekanan FROM rekanan WHERE aktif='N'"));
                ?>
                <div class="flex gap-2">
                    <span class="px-3 py-1 bg-green-500 text-white rounded-full text-xs font-semibold">
                        ✓ Aktif: <?= $total_aktif ?>
                    </span>
                    <span class="px-3 py-1 bg-red-500 text-white rounded-full text-xs font-semibold">
                        ✗ Nonaktif: <?= $total_nonaktif ?>
                    </span>
                </div>
            </div>

            <!-- Filter & Search -->
            <div class="mb-3 space-y-3">
                <div class="flex gap-2 items-center">
                    <label class="text-sm font-semibold text-gray-700 whitespace-nowrap">
                        <i class="fas fa-filter mr-1"></i> Filter:
                    </label>
                    <select id="filterStatus"
                            class="filter-select flex-1 px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all">
                        <option value="semua"    <?= $filter_status == 'semua'    ? 'selected' : '' ?>>🔍 Semua Status</option>
                        <option value="aktif"    <?= $filter_status == 'aktif'    ? 'selected' : '' ?>>✅ Aktif Saja</option>
                        <option value="nonaktif" <?= $filter_status == 'nonaktif' ? 'selected' : '' ?>>❌ Tidak Aktif Saja</option>
                    </select>
                </div>

                <form method="GET" class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text"
                           name="search"
                           value="<?= htmlspecialchars($keyword) ?>"
                           class="form-control"
                           placeholder="Cari berdasarkan nama, alamat, atau telepon..."
                           id="searchInput">
                    <input type="hidden" name="status" value="<?= $filter_status ?>">
                </form>
            </div>

            <!-- TABLE -->
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th width="50">No</th>
                            <th width="200">Nama</th>
                            <th width="250">Alamat</th>
                            <th width="120">Telepon</th>
                            <th width="80">QR CODE</th>
                            <th width="80">Status</th>
                            <th width="180">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $no = $offset + 1;
                    $q  = mysqli_query(
                        $connection,
                        "SELECT * FROM rekanan $where ORDER BY aktif DESC, id_rekanan DESC LIMIT $per_page OFFSET $offset"
                    );
                    if (mysqli_num_rows($q) > 0):
                        while ($r = mysqli_fetch_assoc($q)):
                    ?>
                    <tr data-status="<?= $r['aktif'] ?>">
                        <td class="text-center" data-label="No"><?= $no++ ?></td>
                        <td data-label="Nama Rekanan"><?= htmlspecialchars($r['nama_rekanan']) ?></td>
                        <td data-label="Alamat"><?= htmlspecialchars($r['alamat']) ?></td>
                        <td data-label="Telepon"><?= htmlspecialchars($r['telp']) ?></td>
                        <td class="text-center" data-label="QR Code">
                            <?php if ($r['ttd_rekanan']): ?>
                                <img src="../uploads/ttd_rekanan/<?= $r['ttd_rekanan'] ?>"
                                     style="max-height:50px; cursor:pointer;"
                                     onclick="showImage('../uploads/ttd_rekanan/<?= $r['ttd_rekanan'] ?>')"
                                     title="Klik untuk memperbesar">
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center" data-label="Status">
                            <?php if ($r['aktif'] == 'Y'): ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>Aktif
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                    <i class="fas fa-times-circle mr-1"></i>Nonaktif
                                </span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Aksi">
                            <div class="btn-group-action">
                                <a href="?edit=<?= $r['id_rekanan'] ?>"
                                   class="btn btn-info btn-sm" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($r['aktif'] == 'Y'): ?>
                                <a href="?nonaktif=<?= $r['id_rekanan'] ?>"
                                   onclick="return confirm('⚠️ Nonaktifkan rekanan ini?\n\nRekanan yang dinonaktifkan tidak akan muncul di daftar pilihan.')"
                                   class="btn btn-orange btn-sm" title="Nonaktifkan">
                                    <i class="fas fa-eye-slash"></i>
                                </a>
                                <?php else: ?>
                                <a href="?aktifkan=<?= $r['id_rekanan'] ?>"
                                   onclick="return confirm('✅ Aktifkan kembali rekanan ini?')"
                                   class="btn btn-success btn-sm" title="Aktifkan">
                                    <i class="fas fa-check"></i>
                                </a>
                                <?php endif; ?>
                                <a href="?hapus=<?= $r['id_rekanan'] ?>"
                                   onclick="return confirm('🗑️ HAPUS PERMANEN?\n\n⚠️ Data akan dihapus dari database dan TIDAK BISA dikembalikan!\n\nYakin ingin melanjutkan?')"
                                   class="btn btn-danger btn-sm" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <p class="mb-0">
                                    <?php
                                    if ($keyword != '' && $filter_status != 'semua')
                                        echo 'Tidak ada data yang sesuai dengan filter dan pencarian';
                                    elseif ($keyword)
                                        echo 'Data tidak ditemukan untuk pencarian "' . htmlspecialchars($keyword) . '"';
                                    elseif ($filter_status == 'aktif')
                                        echo 'Tidak ada rekanan yang aktif';
                                    elseif ($filter_status == 'nonaktif')
                                        echo 'Tidak ada rekanan yang nonaktif';
                                    else
                                        echo 'Belum ada data rekanan';
                                    ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ===================== PAGINATION BARU ===================== -->
            <?php if ($total_pages > 1): ?>
            <?php
            $base_params = [
                'status' => $filter_status,
                'search' => $keyword,
            ];
            $prev_page  = max(1, $page - 1);
            $next_page  = min($total_pages, $page + 1);
            $prev_qs    = http_build_query(array_merge($base_params, ['page' => $prev_page]));
            $next_qs    = http_build_query(array_merge($base_params, ['page' => $next_page]));

            $window     = 2;
            $page_start = max(2, $page - $window);
            $page_end   = min($total_pages - 1, $page + $window);
            ?>
            <div class="mt-4 space-y-2">

                <!-- Info teks -->
                <p class="text-sm text-gray-600">
                    Menampilkan
                    <span class="font-semibold"><?= $total_rows > 0 ? ($offset + 1) : 0 ?> – <?= min($offset + $per_page, $total_rows) ?></span>
                    dari <span class="font-semibold"><?= $total_rows ?></span> data rekanan
                </p>

                <!-- Tombol pagination -->
                <div class="flex flex-wrap items-center gap-1">

                    <!-- Prev -->
                    <a href="?<?= htmlspecialchars($prev_qs) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page <= 1
                                    ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        <i class="fas fa-chevron-left text-xs mr-1"></i> Prev
                    </a>

                    <!-- Halaman 1 -->
                    <?php $qs1 = http_build_query(array_merge($base_params, ['page' => 1])); ?>
                    <a href="?<?= htmlspecialchars($qs1) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page === 1
                                    ? 'bg-green-700 text-white border-green-700'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        1
                    </a>

                    <!-- Ellipsis kiri -->
                    <?php if ($page_start > 2): ?>
                    <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                    <?php endif; ?>

                    <!-- Halaman tengah -->
                    <?php for ($i = $page_start; $i <= $page_end; $i++):
                        $qsi = http_build_query(array_merge($base_params, ['page' => $i]));
                    ?>
                    <a href="?<?= htmlspecialchars($qsi) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $i === $page
                                    ? 'bg-green-700 text-white border-green-700'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>

                    <!-- Ellipsis kanan -->
                    <?php if ($page_end < $total_pages - 1): ?>
                    <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                    <?php endif; ?>

                    <!-- Halaman terakhir -->
                    <?php if ($total_pages > 1):
                        $qsN = http_build_query(array_merge($base_params, ['page' => $total_pages]));
                    ?>
                    <a href="?<?= htmlspecialchars($qsN) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page === $total_pages
                                    ? 'bg-green-700 text-white border-green-700'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        <?= $total_pages ?>
                    </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <a href="?<?= htmlspecialchars($next_qs) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page >= $total_pages
                                    ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        Next <i class="fas fa-chevron-right text-xs ml-1"></i>
                    </a>

                    <!-- Jump to page -->
                    <form method="GET" class="inline-flex items-center gap-1 ml-1"
                          onsubmit="this.page.value = Math.max(1, Math.min(<?= $total_pages ?>, parseInt(this.page.value) || 1))">
                        <?php foreach ($base_params as $pk => $pv): ?>
                        <input type="hidden" name="<?= htmlspecialchars($pk) ?>" value="<?= htmlspecialchars($pv) ?>">
                        <?php endforeach; ?>
                        <span class="text-sm text-gray-500 whitespace-nowrap">Ke:</span>
                        <input type="number" name="page"
                               min="1" max="<?= $total_pages ?>"
                               placeholder="<?= $page ?>"
                               class="w-14 px-2 py-1.5 border border-gray-300 rounded-lg text-sm text-center focus:border-green-600 focus:outline-none">
                        <button type="submit"
                                class="px-3 py-1.5 text-white rounded-lg text-sm transition hover:opacity-90"
                                style="background:rgb(9,120,83);">
                            <i class="fas fa-arrow-right text-xs"></i>
                        </button>
                    </form>

                </div>
            </div>
            <?php endif; ?>
            <!-- ===================== END PAGINATION ===================== -->

        </div>
    </div>
</div>
</div>

<!-- Modal preview gambar -->
<div class="modal-overlay" id="imageModal" onclick="hideModal()">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Preview QR Code</h5>
                <button type="button" class="btn-close" onclick="hideModal()">×</button>
            </div>
            <div class="modal-body">
                <img id="modalImage" src="" alt="Preview QR Code">
            </div>
        </div>
    </div>
</div>

<script>
function autoCapitalize(inputId) {
    const input = document.getElementById(inputId);
    if (input) {
        input.addEventListener('input', function() {
            const s = this.selectionStart, e = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(s, e);
        });
    }
}

window.addEventListener('DOMContentLoaded', function() {
    autoCapitalize('namaRekanan');
    autoCapitalize('alamatRekanan');
});

// Auto hide alert success/error saja
setTimeout(() => {
    document.querySelectorAll('.alert-success-notification, .alert-error-notification').forEach(alert => {
        alert.style.transition = 'all 0.3s ease-out';
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-20px)';
        setTimeout(() => alert.remove(), 300);
    });
}, 5000);

// Filter status
const filterStatus = document.getElementById('filterStatus');
if (filterStatus) {
    filterStatus.addEventListener('change', function() {
        const status = this.value;
        const search = document.getElementById('searchInput').value;
        let url = 'rekanan.php?status=' + status;
        if (search) url += '&search=' + encodeURIComponent(search);
        window.location.href = url;
    });
}

// Live search
let searchTimeout;
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const filterValue = filterStatus.value;
        searchTimeout = setTimeout(() => {
            let url = 'rekanan.php?status=' + filterValue;
            if (this.value) url += '&search=' + encodeURIComponent(this.value);
            window.location.href = url;
        }, 800);
    });
}

// Modal preview
function showImage(src) {
    const modal    = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    modal.classList.add('active');
    modalImg.src = src;
    document.body.style.overflow = 'hidden';
}
function hideModal() {
    document.getElementById('imageModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideModal();
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>
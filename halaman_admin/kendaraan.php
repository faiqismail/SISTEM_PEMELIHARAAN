<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('admin');

/* =====================
   DAFTAR BIDANG
===================== */
$bidang_options = [
    'ANGKUTAN DALAM',
    'ANGKUTAN LUAR',
    'ALAT BERAT WILAYAH 1',
    'ALAT BERAT WILAYAH 2',
    'ALAT BERAT WILAYAH 3',
    'PERGUDANGAN'
];

/* =====================
   DAFTAR JENIS KENDARAAN
===================== */
$jenis_kendaraan_options = [
    'BOX',
    'DUMP TRUCK',
    'EXCAVATOR',
    'FLAT TRUCK',
    'FORKLIFT',
    'TRAILER',
    'TRONTON',
    'WHEEL LOADER',
    'WING BOX'
];

/* =====================
   DAFTAR STATUS KENDARAAN
===================== */
$status_options = [
    'Aktif',
    'Tidak Aktif'
];

/* =====================
   TAHUN KENDARAAN (range)
===================== */
$current_year = date('Y');
$tahun_options = range($current_year, 1990);

/* =====================
   SIMPAN KENDARAAN
===================== */
if (isset($_POST['simpan'])) {
    $nopol  = mysqli_real_escape_string($connection, strtoupper($_POST['nopol']));
    $jenis  = mysqli_real_escape_string($connection, $_POST['jenis_kendaraan']);
    $bidang = mysqli_real_escape_string($connection, $_POST['bidang']);
    $tahun  = intval($_POST['tahun_kendaraan']);
    $status = 'Aktif';

    // 🔍 Cek apakah nopol yang sama sudah ADA dan AKTIF
    $cek_aktif = mysqli_query($connection,
        "SELECT id_kendaraan FROM kendaraan WHERE nopol='$nopol' AND status='Aktif'"
    );

    if (mysqli_num_rows($cek_aktif) > 0) {
        echo "<script>
            alert('❌ Tidak bisa menyimpan!\\nNomor Asset [ $nopol ] sudah terdaftar dan masih AKTIF.\\n\\nHapus atau ubah nomor asset kendaraan aktif tersebut terlebih dahulu sebelum menambah ulang.');
            window.history.back();
        </script>";
        exit;
    }

    // ✅ Boleh simpan jika nopol sama tapi statusnya sudah Tidak Aktif
    mysqli_query($connection,
        "INSERT INTO kendaraan (nopol, jenis_kendaraan, bidang, tahun_kendaraan, status)
         VALUES ('$nopol','$jenis','$bidang','$tahun','$status')"
    );

    echo "<script>
        alert('✅ Data kendaraan berhasil disimpan');
        window.location.href='kendaraan.php';
    </script>";
    exit;
}

/* =====================
   UPDATE KENDARAAN
===================== */
if (isset($_POST['update'])) {
    $id     = intval($_POST['id_kendaraan']);
    $nopol  = mysqli_real_escape_string($connection, strtoupper($_POST['nopol']));
    $jenis  = mysqli_real_escape_string($connection, $_POST['jenis_kendaraan']);
    $bidang = mysqli_real_escape_string($connection, $_POST['bidang']);
    $tahun  = intval($_POST['tahun_kendaraan']);
    $status = mysqli_real_escape_string($connection, $_POST['status']);

    // 🔍 Jika ingin mengaktifkan, cek apakah ada nopol SAMA yang sudah AKTIF di data lain
    if ($status == 'Aktif') {
        $cek_aktif = mysqli_query($connection,
            "SELECT id_kendaraan FROM kendaraan
             WHERE nopol='$nopol' AND id_kendaraan != '$id' AND status='Aktif'"
        );

        if (mysqli_num_rows($cek_aktif) > 0) {
            echo "<script>
                alert('❌ Tidak bisa mengaktifkan!\\nNomor Asset [ $nopol ] sudah digunakan oleh kendaraan lain yang masih AKTIF.\\n\\nHapus atau ubah nomor asset kendaraan aktif tersebut terlebih dahulu agar tidak bentrok.');
                window.history.back();
            </script>";
            exit;
        }
    }

    // ✅ Aman untuk diupdate
    mysqli_query($connection,
        "UPDATE kendaraan SET
            nopol='$nopol',
            jenis_kendaraan='$jenis',
            bidang='$bidang',
            tahun_kendaraan='$tahun',
            status='$status'
         WHERE id_kendaraan='$id'"
    );

    echo "<script>
        alert('✅ Data kendaraan berhasil diperbarui');
        window.location.href='kendaraan.php';
    </script>";
    exit;
}

/* =====================
   HAPUS KENDARAAN
===================== */
if (isset($_GET['hapus'])) {
    $id = intval($_GET['hapus']);
    try {
        mysqli_query($connection, "DELETE FROM kendaraan WHERE id_kendaraan='$id'");
        echo "<script>
            alert('✅ Data kendaraan berhasil dihapus');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1451) {
            echo "<script>
                alert('❌ Data kendaraan masih digunakan di Permintaan Perbaikan, data tidak bisa di hapus hanya bisa di Nonaktifkan ');
                window.location.href='kendaraan.php';
            </script>";
            exit;
        }
        echo "<script>
            alert('❌ Gagal menghapus data!');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    }
}

/* =====================
   MODE EDIT
===================== */
$edit = false;
if (isset($_GET['edit'])) {
    $edit = true;
    $id   = mysqli_real_escape_string($connection, $_GET['edit']);
    $e    = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT * FROM kendaraan WHERE id_kendaraan='$id'")
    );
}

/* =====================
   SEARCH & FILTER
===================== */
$keyword        = '';
$filter_status  = isset($_GET['status'])     ? $_GET['status']     : 'semua';
$filter_umur    = isset($_GET['umur'])       ? $_GET['umur']       : 'semua';
$where_conditions = [];

if (isset($_GET['search'])) {
    $keyword = mysqli_real_escape_string($connection, $_GET['search']);
    $where_conditions[] = "(nopol LIKE '%$keyword%' OR jenis_kendaraan LIKE '%$keyword%' OR bidang LIKE '%$keyword%')";
}

if ($filter_status != 'semua') {
    $filter_status_safe = mysqli_real_escape_string($connection, $filter_status);
    $where_conditions[] = "status='$filter_status_safe'";
}

$where = '';
if (count($where_conditions) > 0) {
    $where = "WHERE " . implode(' AND ', $where_conditions);
}

// Sorting berdasarkan filter umur
$order_by = "ORDER BY id_kendaraan DESC";
if ($filter_umur == 'tertua') {
    $order_by = "ORDER BY tahun_kendaraan ASC";
} elseif ($filter_umur == 'terbaru') {
    $order_by = "ORDER BY tahun_kendaraan DESC";
} elseif ($filter_umur == 'sedang') {
    // Mendekati median tahun
    $order_by = "ORDER BY ABS(tahun_kendaraan - (SELECT AVG(tahun_kendaraan) FROM kendaraan)) ASC";
}

include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Kendaraan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: rgb(185, 224, 204);
            min-height: 100vh;
        }
        .main-container { padding: 20px; max-width: 1700px; margin: 0 auto; }

        .page-header {
            background: white; padding: 25px 30px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 25px;
        }
        .page-header h1 {
            font-size: 28px; font-weight: 700; color: #2c3e50;
            display: flex; align-items: center; gap: 12px; margin: 0;
        }
        .page-header h1 i { color: #667eea; font-size: 30px; }

        .content-layout { display: grid; grid-template-columns: 400px 1fr; gap: 25px; align-items: start; }

        /* FORM */
        .form-container {
            background: white; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 25px; position: sticky; top: 20px;
        }
        .form-title {
            font-size: 20px; font-weight: 700; color: #2c3e50;
            margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
            padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;
        }
        .form-title i { color: #667eea; }
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-weight: 600; color: #2c3e50; margin-bottom: 8px; font-size: 14px; }
        .form-label i { margin-right: 5px; color: #667eea; }
        .form-input, .form-select {
            width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; transition: all 0.3s ease; background: #f8f9fa;
        }
        .form-input:focus, .form-select:focus {
            outline: none; border-color: #667eea; background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-select {
            cursor: pointer; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 15px center; padding-right: 40px;
        }
        .info-text { font-size: 12px; color: #6c757d; margin-top: 5px; font-style: italic; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn {
            flex: 1; padding: 12px 20px; border-radius: 8px; font-weight: 600;
            font-size: 14px; border: none; cursor: pointer; transition: all 0.3s ease;
            display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none;
        }
        .btn-primary { background: rgb(9, 120, 83); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(9,120,83,0.4); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; transform: translateY(-2px); }

        /* TABLE SIDE */
        .data-container {
            background: white; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 25px; display: flex; flex-direction: column;
            height: calc(100vh - 200px);
        }
        .data-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0; flex-shrink: 0;
        }
        .data-title { font-size: 20px; font-weight: 700; color: #2c3e50; display: flex; align-items: center; gap: 10px; }
        .data-title i { color: #667eea; }
        .stats-badge { padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600; color: white; }

        /* Filter row */
        .filter-search-container { display: flex; gap: 10px; margin-bottom: 20px; flex-shrink: 0; flex-wrap: wrap; }
        .filter-box { flex: 0 0 160px; }
        .filter-umur-box { flex: 0 0 175px; }
        .search-box { flex: 1; min-width: 160px; position: relative; }
        .filter-select {
            width: 100%; padding: 12px 40px 12px 15px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; transition: all 0.3s ease;
            background: #f8f9fa; cursor: pointer; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 15px center;
        }
        .filter-select:focus {
            outline: none; border-color: #667eea;
            background-color: white; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #999; font-size: 16px; }
        .search-input {
            width: 100%; padding: 12px 15px 12px 45px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; transition: all 0.3s ease; background: #f8f9fa;
        }
        .search-input:focus { outline: none; border-color: #667eea; background: white; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }

        .table-scroll {
            flex: 1; overflow-y: auto; overflow-x: hidden;
            border-radius: 8px; border: 1px solid #e0e0e0;
        }
        .table-scroll::-webkit-scrollbar { width: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .table-scroll::-webkit-scrollbar-thumb { background: rgb(9, 120, 83); border-radius: 4px; }

        table { width: 100%; border-collapse: collapse; }
        thead { position: sticky; top: 0; z-index: 10; background: rgb(9, 120, 83); }
        thead th {
            padding: 15px 12px; text-align: left; font-weight: 600;
            font-size: 13px; color: white; white-space: nowrap;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        tbody tr { border-bottom: 1px solid #f0f0f0; transition: all 0.2s ease; }
        tbody tr:hover { background: #f8f9fa; }
        tbody td { padding: 15px 12px; font-size: 14px; color: #555; }

        .nopol-badge {
            display: inline-block; padding: 6px 12px;
            background: linear-gradient(135deg, rgb(105,92,15), rgb(205,174,38));
            color: white; border-radius: 6px; font-weight: 700;
            font-size: 13px; letter-spacing: 0.5px;
        }
        .bidang-badge { display: inline-block; padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .bidang-angkutan-dalam  { background: linear-gradient(135deg,#e8f5e9,#c8e6c9); color:#2e7d32; border:1px solid #81c784; }
        .bidang-angkutan-luar   { background: linear-gradient(135deg,#f3e5f5,#e1bee7); color:#7b1fa2; border:1px solid #ce93d8; }
        .bidang-alat-berat-1    { background: linear-gradient(135deg,#fff3e0,#ffe0b2); color:#e65100; border:1px solid #ffb74d; }
        .bidang-alat-berat-2    { background: linear-gradient(135deg,#fce4ec,#f8bbd0); color:#c2185b; border:1px solid #f06292; }
        .bidang-alat-berat-3    { background: linear-gradient(135deg,#e0f2f1,#b2dfdb); color:#00695c; border:1px solid #4db6ac; }
        .bidang-gudang          { background: linear-gradient(135deg,#ede7f6,#d1c4e9); color:#512da8; border:1px solid #9575cd; }

        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .status-aktif      { background: linear-gradient(135deg,#d4edda,#c3e6cb); color:#155724; border:1px solid #28a745; }
        .status-tidak-aktif{ background: linear-gradient(135deg,#f8d7da,#f5c6cb); color:#721c24; border:1px solid #dc3545; }

        /* Umur badge */
        .umur-badge {
            display: inline-block; padding: 5px 10px;
            border-radius: 12px; font-size: 12px; font-weight: 700; white-space: nowrap;
        }
        .umur-baru   { background: linear-gradient(135deg,#e3f2fd,#bbdefb); color:#1565c0; border:1px solid #64b5f6; }
        .umur-sedang { background: linear-gradient(135deg,#fff8e1,#ffecb3); color:#f57f17; border:1px solid #ffd54f; }
        .umur-tua    { background: linear-gradient(135deg,#fbe9e7,#ffccbc); color:#bf360c; border:1px solid #ff8a65; }

        .action-btns { display: flex; gap: 6px; }
        .btn-action {
            padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;
            border: none; cursor: pointer; transition: all 0.2s ease;
            display: inline-flex; align-items: center; gap: 5px; text-decoration: none;
        }
        .btn-edit         { background:#fff3cd; color:#856404; }
        .btn-edit:hover   { background:#ffc107; color:white; }
        .btn-edit-inactive{ background:#ffe5cc; color:#cc6600; border:1px dashed #ff9933; }
        .btn-edit-inactive:hover { background:#ff9933; color:white; }
        .btn-delete       { background:#f8d7da; color:#721c24; }
        .btn-delete:hover { background:#dc3545; color:white; }

        .no-data { text-align:center; padding:60px 20px; color:#999; }
        .no-data i { font-size:48px; margin-bottom:15px; opacity:0.3; }
        .no-data p { font-size:14px; margin:0; }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            body { overflow-x: hidden; }
            .main-container { padding: 8px; width:100%; max-width:100vw; }
            .page-header { padding:10px; margin-bottom:8px; }
            .page-header h1 { font-size:16px; }
            .content-layout { display:block; width:100%; }
            .form-container { position:static; padding:10px; margin-bottom:8px; width:100%; }
            .form-title { font-size:15px; margin-bottom:10px; }
            .form-group { margin-bottom:10px; }
            .form-label { font-size:11px; }
            .form-input, .form-select { padding:8px; font-size:12px; }
            .btn { padding:9px; font-size:11px; }
            .data-container { padding:10px; height:auto; }
            .data-header { flex-wrap:wrap; gap:5px; }
            .data-title { font-size:15px; }
            .stats-badge { padding:4px 8px; font-size:10px; }
            .filter-search-container { flex-direction:column; gap:8px; }
            .filter-box, .filter-umur-box { flex:1; width:100%; }
            .filter-select { padding:8px 28px 8px 10px; font-size:11px; }
            .search-input { padding:8px 8px 8px 32px; font-size:11px; }
            .table-scroll { height:320px; max-height:320px; overflow-x:auto; overflow-y:auto; -webkit-overflow-scrolling:touch; }
            table { min-width:800px; width:max-content; }
            thead th { padding:10px 8px; font-size:10px; }
            tbody td { padding:10px 8px; font-size:11px; white-space:nowrap; }
            .nopol-badge { font-size:10px; padding:4px 8px; }
            .bidang-badge, .status-badge, .umur-badge { font-size:9px; padding:4px 8px; }
            .action-btns { flex-direction:column; gap:3px; }
            .btn-action { padding:5px 8px; font-size:10px; width:100%; }
            thead th:last-child, tbody td:last-child { position:sticky; right:0; background:white; box-shadow:-2px 0 5px rgba(0,0,0,0.1); z-index:5; }
            thead th:last-child { background:rgb(9,120,83); z-index:15; }
        }
        @media (max-width: 374px) {
            .table-scroll { height:250px; max-height:250px; }
            table { min-width:750px; }
        }
        @media (max-width: 768px) and (orientation: landscape) {
            .table-scroll { height:200px; max-height:200px; }
        }
    </style>
</head>
<body>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-car"></i> Data Asset Armada</h1>
    </div>

    <div class="content-layout">
        <!-- =========== FORM INPUT =========== -->
        <div class="form-container">
            <h2 class="form-title">
                <i class="<?= $edit ? 'fas fa-edit' : 'fas fa-plus-circle' ?>"></i>
                <?= $edit ? 'Edit Kendaraan' : 'Tambah Asset' ?>
            </h2>

            <form method="POST">
                <?php if ($edit): ?>
                    <input type="hidden" name="id_kendaraan" value="<?= $e['id_kendaraan'] ?>">
                    <?php if ($e['status'] == 'Tidak Aktif'): ?>
                    <div style="background:#fff3cd;border:2px solid #ffc107;border-radius:8px;padding:12px;margin-bottom:15px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-exclamation-triangle" style="color:#856404;font-size:20px;"></i>
                            <div>
                                <strong style="color:#856404;font-size:14px;">PERINGATAN!</strong>
                                <p style="color:#856404;font-size:12px;margin:5px 0 0 0;">
                                    Kendaraan ini berstatus <strong>TIDAK AKTIF</strong>.
                                    Silakan ubah status jika kendaraan sudah beroperasi kembali.
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Nomor Asset -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-id-card"></i> Nomor Asset</label>
                    <input type="text" name="nopol" class="form-input"
                           placeholder="Contoh: W 1234 XYZ"
                           value="<?= $edit ? htmlspecialchars($e['nopol']) : '' ?>"
                           style="text-transform:uppercase;" required>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Otomatis diubah ke HURUF KAPITAL</small>
                </div>

                <!-- Jenis Kendaraan -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-truck"></i> Jenis Kendaraan</label>
                    <select name="jenis_kendaraan" class="form-select" required>
                        <option value="">-- Pilih Jenis Kendaraan --</option>
                        <?php foreach ($jenis_kendaraan_options as $jenis_opt): ?>
                            <option value="<?= $jenis_opt ?>" <?= ($edit && $e['jenis_kendaraan'] == $jenis_opt) ? 'selected' : '' ?>>
                                <?= $jenis_opt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Pilih jenis kendaraan dari dropdown</small>
                </div>

                <!-- Bidang -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-building"></i> Bidang</label>
                    <select name="bidang" class="form-select" required>
                        <option value="">-- Pilih Bidang --</option>
                        <?php foreach ($bidang_options as $bidang_opt): ?>
                            <option value="<?= $bidang_opt ?>" <?= ($edit && $e['bidang'] == $bidang_opt) ? 'selected' : '' ?>>
                                <?= $bidang_opt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Pilih bidang dari dropdown</small>
                </div>

                <!-- Tahun Kendaraan -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-calendar-alt"></i> Tahun Kendaraan</label>
                    <select name="tahun_kendaraan" class="form-select" required>
                        <option value="">-- Pilih Tahun --</option>
                        <?php foreach ($tahun_options as $th): ?>
                            <option value="<?= $th ?>" <?= ($edit && $e['tahun_kendaraan'] == $th) ? 'selected' : '' ?>>
                                <?= $th ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Tahun pembuatan / rakitan kendaraan</small>
                </div>

                <!-- Status (hanya saat Edit) -->
                <?php if ($edit): ?>
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-toggle-on"></i> Status Kendaraan</label>
                    <select name="status" class="form-select" required>
                        <?php foreach ($status_options as $status_opt): ?>
                            <option value="<?= $status_opt ?>" <?= ($e['status'] == $status_opt) ? 'selected' : '' ?>>
                                <?= $status_opt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text">
                        <i class="fas fa-info-circle"></i>
                        <?= $e['status'] == 'Aktif' ? 'Kendaraan ini sedang <strong>AKTIF</strong>' : 'Kendaraan ini <strong>TIDAK AKTIF</strong>' ?>
                    </small>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <small class="info-text" style="display:block;background:#d4edda;padding:10px;border-radius:5px;color:#155724;">
                        <i class="fas fa-info-circle"></i> Status kendaraan baru otomatis <strong>AKTIF</strong>
                    </small>
                </div>
                <?php endif; ?>

                <div class="btn-group">
                    <button type="submit" name="<?= $edit ? 'update' : 'simpan' ?>" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= $edit ? 'Update' : 'Simpan' ?>
                    </button>
                    <?php if ($edit): ?>
                        <a href="kendaraan.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- =========== DATA TABLE =========== -->
        <div class="data-container">
            <div class="data-header">
                <h2 class="data-title"><i class="fas fa-list"></i> Daftar Asset</h2>
                <?php
                $aktif       = mysqli_num_rows(mysqli_query($connection, "SELECT id_kendaraan FROM kendaraan WHERE status='Aktif'"));
                $tidak_aktif = mysqli_num_rows(mysqli_query($connection, "SELECT id_kendaraan FROM kendaraan WHERE status='Tidak Aktif'"));
                ?>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <span class="stats-badge" style="background:#28a745;" id="badgeAktif">✓ Aktif: <?= $aktif ?></span>
                    <span class="stats-badge" style="background:#dc3545;" id="badgeTidakAktif">✗ Tidak Aktif: <?= $tidak_aktif ?></span>
                </div>
            </div>

            <!-- Filter & Search -->
            <div class="filter-search-container">
                <!-- Filter Status -->
                <div class="filter-box">
                    <select id="filterStatus" class="filter-select">
                        <option value="semua" <?= $filter_status == 'semua' ? 'selected' : '' ?>>🔍 Semua Status</option>
                        <option value="Aktif" <?= $filter_status == 'Aktif' ? 'selected' : '' ?>>✓ Aktif</option>
                        <option value="Tidak Aktif" <?= $filter_status == 'Tidak Aktif' ? 'selected' : '' ?>>✗ Tidak Aktif</option>
                    </select>
                </div>
                <!-- Filter Umur -->
                <div class="filter-umur-box">
                    <select id="filterUmur" class="filter-select">
                        <option value="semua" <?= $filter_umur == 'semua'   ? 'selected' : '' ?>> Semua Umur</option>
                        <option value="tertua" <?= $filter_umur == 'tertua' ? 'selected' : '' ?>> Tertua </option>
                        <option value="sedang" <?= $filter_umur == 'sedang' ? 'selected' : '' ?>> Sedang</option>
                        <option value="terbaru" <?= $filter_umur == 'terbaru' ? 'selected' : '' ?>> Terbaru </option>
                    </select>
                </div>
                <!-- Search -->
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input"
                           placeholder="Cari nomor asset, jenis, bidang..."
                           value="<?= htmlspecialchars($keyword) ?>">
                </div>
            </div>

            <!-- Table -->
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th style="width:45px;text-align:center;">No</th>
                            <th>Nomor Asset</th>
                            <th>Jenis Kendaraan</th>
                            <th>Bidang</th>
                            <th style="text-align:center;">Tahun</th>
                            <th style="text-align:center;">Umur</th>
                            <th style="text-align:center;">Status</th>
                            <th style="width:140px;text-align:center;">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php
                        $no = 1;
                        $q  = mysqli_query($connection, "SELECT * FROM kendaraan $where $order_by");

                        if (mysqli_num_rows($q) > 0):
                            while ($k = mysqli_fetch_assoc($q)):
                                // Badge bidang
                                $badge_class = 'bidang-badge';
                                switch ($k['bidang']) {
                                    case 'ANGKUTAN DALAM':    $badge_class .= ' bidang-angkutan-dalam'; break;
                                    case 'ANGKUTAN LUAR':     $badge_class .= ' bidang-angkutan-luar';  break;
                                    case 'ALAT BERAT WILAYAH 1': $badge_class .= ' bidang-alat-berat-1'; break;
                                    case 'ALAT BERAT WILAYAH 2': $badge_class .= ' bidang-alat-berat-2'; break;
                                    case 'ALAT BERAT WILAYAH 3': $badge_class .= ' bidang-alat-berat-3'; break;
                                    case 'PERGUDANGAN':       $badge_class .= ' bidang-gudang'; break;
                                }

                                // Hitung umur
                                $tahun_unit = intval($k['tahun_kendaraan']);
                                $umur_tahun = ($tahun_unit > 0) ? ($current_year - $tahun_unit) : null;

                                // Kategori umur: ≤3 = baru, 4-10 = sedang, >10 = tua
                                $umur_label = '-';
                                $umur_class = '';
                                if ($umur_tahun !== null) {
                                    $umur_label = $umur_tahun . ' Tahun';
                                    if ($umur_tahun <= 3)       { $umur_class = 'umur-badge umur-baru'; }
                                    elseif ($umur_tahun <= 10)  { $umur_class = 'umur-badge umur-sedang'; }
                                    else                        { $umur_class = 'umur-badge umur-tua'; }
                                }

                                $is_aktif = ($k['status'] == 'Aktif');
                                $status_class = $is_aktif ? 'status-badge status-aktif' : 'status-badge status-tidak-aktif';
                        ?>
                        <tr data-status="<?= htmlspecialchars($k['status']) ?>"
                            data-tahun="<?= $tahun_unit ?>"
                            data-umur="<?= $umur_tahun ?>">
                            <td style="text-align:center;font-weight:700;color:#667eea;"><?= $no++ ?></td>
                            <td><span class="nopol-badge"><?= htmlspecialchars($k['nopol']) ?></span></td>
                            <td style="font-weight:600;color:#2c3e50;"><?= htmlspecialchars($k['jenis_kendaraan']) ?: '-' ?></td>
                            <td>
                                <?php if ($k['bidang']): ?>
                                    <span class="<?= $badge_class ?>"><?= htmlspecialchars($k['bidang']) ?></span>
                                <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                            </td>
                            <td style="text-align:center;font-weight:700;color:#2c3e50;">
                                <?= $tahun_unit > 0 ? $tahun_unit : '-' ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($umur_class): ?>
                                    <span class="<?= $umur_class ?>"><?= $umur_label ?></span>
                                <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="<?= $status_class ?>"><?= $is_aktif ? '✓ Aktif' : '✗ Tidak Aktif' ?></span>
                            </td>
                            <td>
                                <div class="action-btns" style="justify-content:center;">
                                    <a href="kendaraan.php?edit=<?= $k['id_kendaraan'] ?>"
                                       class="btn-action btn-edit <?= !$is_aktif ? 'btn-edit-inactive' : '' ?>"
                                       <?= !$is_aktif ? 'onclick="return confirmEditInactive();"' : '' ?>
                                       title="<?= !$is_aktif ? 'Kendaraan tidak aktif' : 'Edit kendaraan' ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="kendaraan.php?hapus=<?= $k['id_kendaraan'] ?>"
                                       class="btn-action btn-delete"
                                       onclick="return confirm('Hapus kendaraan <?= htmlspecialchars($k['nopol']) ?>?')">
                                        <i class="fas fa-trash"></i> Hapus
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="8" class="no-data">
                                <i class="fas fa-inbox"></i>
                                <p><?= $keyword
                                    ? "Tidak ditemukan data dengan kata kunci '$keyword'"
                                    : ($filter_status != 'semua' ? "Tidak ada kendaraan dengan status $filter_status" : 'Belum ada data kendaraan') ?></p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Auto uppercase nopol
document.querySelector('input[name="nopol"]').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// Konfirmasi edit kendaraan tidak aktif
function confirmEditInactive() {
    return confirm('⚠️ Kendaraan TIDAK AKTIF!\n\nYakin ingin edit?');
}

// ========== REDIRECT on filter change (status & umur) ==========
function buildUrl() {
    const status  = document.getElementById('filterStatus').value;
    const umur    = document.getElementById('filterUmur').value;
    const keyword = document.getElementById('searchInput').value;
    let url = 'kendaraan.php?status=' + encodeURIComponent(status) + '&umur=' + encodeURIComponent(umur);
    if (keyword) url += '&search=' + encodeURIComponent(keyword);
    return url;
}

document.getElementById('filterStatus').addEventListener('change', function() {
    window.location.href = buildUrl();
});

document.getElementById('filterUmur').addEventListener('change', function() {
    window.location.href = buildUrl();
});

// ========== LIVE SEARCH (client-side) ==========
document.getElementById('searchInput').addEventListener('input', function() {
    const keyword      = this.value.toLowerCase();
    const filterStatus = document.getElementById('filterStatus').value;
    const rows         = document.querySelectorAll('#tableBody tr[data-status]');
    let visibleNo = 1, aktifCount = 0, tidakAktifCount = 0;

    rows.forEach(row => {
        const nopol  = row.cells[1]?.textContent.toLowerCase() || '';
        const jenis  = row.cells[2]?.textContent.toLowerCase() || '';
        const bidang = row.cells[3]?.textContent.toLowerCase() || '';
        const status = row.getAttribute('data-status');

        const statusMatch  = filterStatus === 'semua' || status === filterStatus;
        const keywordMatch = !keyword || nopol.includes(keyword) || jenis.includes(keyword) || bidang.includes(keyword);

        if (statusMatch && keywordMatch) {
            row.style.display = '';
            row.cells[0].textContent = visibleNo++;
            if (status === 'Aktif') aktifCount++;
            else tidakAktifCount++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('badgeAktif').textContent     = `✓ Aktif: ${aktifCount}`;
    document.getElementById('badgeTidakAktif').textContent = `✗ Tidak Aktif: ${tidakAktifCount}`;

    // Tampilkan pesan jika tidak ada hasil
    const noDataRow = document.querySelector('.no-data');
    if (noDataRow) {
        const parentRow = noDataRow.closest('tr');
        const visibleCount = visibleNo - 1;
        if (visibleCount === 0) {
            parentRow.style.display = '';
            let message = 'Tidak ada data yang sesuai';
            if (keyword && filterStatus !== 'semua') {
                message = `Tidak ditemukan "${keyword}" dengan status ${filterStatus}`;
            } else if (keyword) {
                message = `Tidak ditemukan data dengan kata kunci "${keyword}"`;
            } else if (filterStatus !== 'semua') {
                message = `Tidak ada kendaraan dengan status ${filterStatus}`;
            }
            noDataRow.querySelector('p').textContent = message;
        } else {
            parentRow.style.display = 'none';
        }
    }
});

// Scroll ke atas saat edit
<?php if ($edit): ?>
window.scrollTo({ top: 0, behavior: 'smooth' });
<?php endif; ?>

// Trigger live search saat page load jika ada filter
window.addEventListener('load', function() {
    const keyword = document.getElementById('searchInput').value;
    if (keyword) {
        document.getElementById('searchInput').dispatchEvent(new Event('input'));
    }
});
</script>

</body>
</html>
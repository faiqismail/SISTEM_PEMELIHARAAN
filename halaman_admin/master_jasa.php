<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('admin');
// Mulai output buffering untuk menghindari error header
ob_start();

include "navbar.php";

/* =====================
   SIMPAN / UPDATE
===================== */
if (isset($_POST['simpan'])) {

    $id_jasa = trim($_POST['id_jasa']);
    $kode    = mysqli_real_escape_string($connection, trim($_POST['kode']));
    $nama    = mysqli_real_escape_string($connection, trim($_POST['nama']));
    $harga   = mysqli_real_escape_string($connection, trim($_POST['harga']));
    $aktif   = mysqli_real_escape_string($connection, $_POST['aktif']);

    if ($id_jasa == '') {
        $cek = mysqli_query($connection, "
            SELECT id_jasa FROM master_jasa 
            WHERE kode_pekerjaan='$kode' 
            AND aktif='Y'
            LIMIT 1
        ");
    } else {
        $cek = mysqli_query($connection, "
            SELECT id_jasa FROM master_jasa 
            WHERE kode_pekerjaan='$kode' 
            AND aktif='Y'
            AND id_jasa != '$id_jasa'
            LIMIT 1
        ");
    }

    if (mysqli_num_rows($cek) > 0) {
        echo "<script>
            alert('❌ Kode pekerjaan \"$kode\" sudah digunakan oleh jasa yang masih AKTIF!\\n\\nTips: Nonaktifkan jasa lama terlebih dahulu jika ingin menggunakan kode yang sama dengan harga baru.');
            window.history.back();
        </script>";
        exit;
    }

    if ($id_jasa == '') {
        mysqli_query($connection, "
            INSERT INTO master_jasa (kode_pekerjaan, nama_pekerjaan, harga, aktif)
            VALUES ('$kode','$nama','$harga','Y')
        ");
        echo "<script>
            alert('✅ Data jasa berhasil ditambahkan');
            window.location.href='master_jasa.php';
        </script>";
        exit;
    } else {
        mysqli_query($connection, "
            UPDATE master_jasa SET
                kode_pekerjaan='$kode',
                nama_pekerjaan='$nama',
                harga='$harga',
                aktif='$aktif'
            WHERE id_jasa='$id_jasa'
        ");
        echo "<script>
            alert('✅ Data jasa berhasil diperbarui');
            window.location.href='master_jasa.php';
        </script>";
        exit;
    }
}

/* =====================
   DELETE
===================== */
if (isset($_GET['hapus'])) {
    $id_jasa = mysqli_real_escape_string($connection, $_GET['hapus']);

    $has_relation        = false;
    $table_with_relation = '';

    $check = mysqli_query($connection, "
        SELECT COUNT(*) as total FROM perbaikan_detail 
        WHERE id_jasa='$id_jasa' LIMIT 1
    ");

    if ($check) {
        $result = mysqli_fetch_assoc($check);
        if ($result['total'] > 0) {
            $has_relation        = true;
            $table_with_relation = 'perbaikan_detail';
        }
    }

    if ($has_relation) {
        echo "<script>
            alert('⚠️ Data tidak dapat dihapus!\\n\\nData jasa ini masih digunakan pada tabel " . $table_with_relation . "\\n\\nHapus data terkait terlebih dahulu atau nonaktifkan data ini.');
            window.location.href='master_jasa.php?error=relasi&table=$table_with_relation';
        </script>";
        exit;
    } else {
        mysqli_query($connection,"DELETE FROM master_jasa WHERE id_jasa='$id_jasa'");
        echo "<script>window.location.href='master_jasa.php?success=delete';</script>";
        exit;
    }
}

/* =====================
   NONAKTIFKAN
===================== */
if (isset($_GET['nonaktif'])) {
    $id_jasa = mysqli_real_escape_string($connection, $_GET['nonaktif']);
    mysqli_query($connection,"UPDATE master_jasa SET aktif='N' WHERE id_jasa='$id_jasa'");
    echo "<script>window.location.href='master_jasa.php?success=nonaktif';</script>";
    exit;
}

/* =====================
   AKTIFKAN KEMBALI
===================== */
if (isset($_GET['aktifkan'])) {
    $id_jasa = mysqli_real_escape_string($connection, $_GET['aktifkan']);

    $check_data = mysqli_query($connection, "SELECT kode_pekerjaan, nama_pekerjaan FROM master_jasa WHERE id_jasa='$id_jasa'");
    $data = mysqli_fetch_assoc($check_data);
    $kode = $data['kode_pekerjaan'];

    $cek_duplikat = mysqli_query($connection, "
        SELECT id_jasa, nama_pekerjaan FROM master_jasa 
        WHERE kode_pekerjaan='$kode' 
        AND aktif='Y' 
        AND id_jasa != '$id_jasa'
        LIMIT 1
    ");

    if (mysqli_num_rows($cek_duplikat) > 0) {
        $jasa_aktif = mysqli_fetch_assoc($cek_duplikat);
        echo "<script>
            alert('❌ Tidak dapat mengaktifkan!\\n\\nKode \"$kode\" sudah digunakan oleh:\\n" . addslashes($jasa_aktif['nama_pekerjaan']) . "\\n\\nSilakan:\\n1. Nonaktifkan jasa tersebut terlebih dahulu, ATAU\\n2. Ubah kode jasa ini sebelum mengaktifkan');
            window.location.href='master_jasa.php?error=duplikat_aktif&kode=$kode';
        </script>";
        exit;
    }

    mysqli_query($connection,"UPDATE master_jasa SET aktif='Y' WHERE id_jasa='$id_jasa'");
    echo "<script>window.location.href='master_jasa.php?success=aktifkan';</script>";
    exit;
}

/* =====================
   EDIT
===================== */
$edit = null;
if (isset($_GET['edit'])) {
    $id_edit = mysqli_real_escape_string($connection, $_GET['edit']);
    $q       = mysqli_query($connection,"SELECT * FROM master_jasa WHERE id_jasa='$id_edit'");
    $edit    = mysqli_fetch_assoc($q);
}

/* =====================
   SEARCH & FILTER STATUS
===================== */
$search        = isset($_GET['search']) ? mysqli_real_escape_string($connection, $_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'semua';

$where_conditions = [];

if ($search != '') {
    $where_conditions[] = "(kode_pekerjaan LIKE '%$search%' OR nama_pekerjaan LIKE '%$search%')";
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

$result_count = mysqli_query($connection, "SELECT COUNT(*) AS total FROM master_jasa $where");
$row_count    = $result_count ? mysqli_fetch_assoc($result_count) : ['total' => 0];
$total_rows   = (int)($row_count['total'] ?? 0);
$total_pages  = $total_rows > 0 ? (int)ceil($total_rows / $per_page) : 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Jasa</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background:rgb(185, 224, 204);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        .container {
            margin-left: 250px;
            transition: margin-left 0.3s ease;
        }
        @media (max-width: 1023px) {
            .container {
                margin-left: 0;
                padding-top: 70px;
            }
        }
        .scroll-container {
            max-height: calc(100vh - 250px);
            overflow-y: auto;
            overflow-x: auto;
        }
        .scroll-container::-webkit-scrollbar { width: 8px; }
        .scroll-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .scroll-container::-webkit-scrollbar-thumb { background: black; border-radius: 10px; }
        .scroll-container::-webkit-scrollbar-thumb:hover { background: #5568d3; }
        .alert { animation: slideDown 0.3s ease-out; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .form-input:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .btn-action { transition: all 0.3s ease; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .card { backdrop-filter: blur(10px); background: rgba(255,255,255,0.95); }
        .table-row { transition: all 0.2s ease; }
        .table-row:hover { background: rgba(102,126,234,0.05); transform: scale(1.01); }
        @media (max-width: 1023px) {
            .main-content { padding-top: 70px; }
            .container { margin-left: 0 !important; padding-top: 70px; }
            .scroll-container { max-height: 500px; }
        }
        @media (max-width: 640px) {
            .card { padding: 1rem !important; }
            .scroll-container { max-height: 400px; }
            table { font-size: 0.75rem; }
            .btn-action { padding: 0.4rem 0.6rem !important; font-size: 0.7rem !important; }
        }
        @media (min-width: 1536px) {
            .container { max-width: calc(100% - 250px) !important; margin-left: 250px; padding-left: 2rem; padding-right: 2rem; }
        }
        @media (min-width: 1280px) and (max-width: 1535px) {
            .container { max-width: calc(100% - 250px) !important; margin-left: 250px; padding-left: 1.5rem; padding-right: 1.5rem; }
        }
        @media (min-width: 1024px) and (max-width: 1279px) {
            .container { max-width: calc(100% - 250px) !important; margin-left: 250px; padding-left: 1rem; padding-right: 1rem; }
        }
        .filter-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 35px;
        }
    </style>
</head>
<body class="font-sans">

<div class="container mx-auto px-2 sm:px-4 py-4 sm:py-8 max-w-full">

    <!-- Alert Messages -->
    <?php if (isset($_GET['success'])): ?>
    <div class="alert bg-green-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg">
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
    <div class="alert bg-red-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
            <span>
                <?php
                if ($_GET['error'] == 'relasi') {
                    $table_name = isset($_GET['table']) ? htmlspecialchars($_GET['table']) : 'perbaikan';
                    echo 'Data tidak dapat dihapus! Jasa ini masih digunakan pada <strong>' . $table_name . '</strong>';
                } elseif ($_GET['error'] == 'duplikat_aktif') {
                    $kode = isset($_GET['kode']) ? htmlspecialchars($_GET['kode']) : '';
                    echo 'Tidak dapat mengaktifkan! Kode <strong>' . $kode . '</strong> sudah digunakan oleh jasa lain yang masih aktif.';
                }
                ?>
            </span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
    <div class="alert bg-red-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
            <span><?= $error ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 lg:gap-6">

        <!-- Form Input (Kiri) -->
        <div class="xl:col-span-5 2xl:col-span-4">
            <div class="card rounded-2xl shadow-2xl p-4 sm:p-6 sticky top-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-edit mr-2 text-purple-600"></i>
                    <?= isset($edit) ? 'Edit Data' : 'Tambah Data' ?>
                </h2>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="id_jasa" value="<?= $edit['id_jasa'] ?? '' ?>">

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-barcode mr-1"></i> Kode Pekerjaan
                        </label>
                        <input type="text"
                               name="kode"
                               value="<?= $edit['kode_pekerjaan'] ?? '' ?>"
                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                               placeholder="Contoh: SV001"
                               required>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Boleh sama dengan kode yang tidak aktif
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-tag mr-1"></i> Nama Jasa
                        </label>
                        <input type="text"
                               name="nama"
                               id="namaJasa"
                               value="<?= $edit['nama_pekerjaan'] ?? '' ?>"
                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                               placeholder="Contoh: Service Berkala"
                               style="text-transform: uppercase;"
                               required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-money-bill-wave mr-1"></i> Harga (Rp)
                        </label>
                        <input type="text"
                               name="harga"
                               id="hargaInput"
                               value="<?= isset($edit['harga']) ? number_format($edit['harga'], 0, ',', '.') : '' ?>"
                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                               placeholder="Contoh: 150000 atau 150.000"
                               required>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Format otomatis:
                        </p>
                        <p class="text-xs text-blue-600 mt-1">
                            <i class="fas fa-lightbulb mr-1"></i>
                            Ketik angka saja, titik koma otomatis. Contoh: <strong>150000</strong> → <strong>Rp 150.000</strong>
                        </p>
                    </div>

                    <?php if (isset($edit)): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-toggle-on mr-1"></i> Status Aktif
                        </label>
                        <select name="aktif"
                                class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all">
                            <option value="Y" <?= ($edit['aktif'] ?? 'Y') == 'Y' ? 'selected' : '' ?>>✅ Aktif</option>
                            <option value="N" <?= ($edit['aktif'] ?? 'Y') == 'N' ? 'selected' : '' ?>>❌ Tidak Aktif</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Jasa yang tidak aktif tidak akan muncul di daftar pilihan
                        </p>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="aktif" value="Y">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                        <p class="text-xs text-green-700">
                            <i class="fas fa-check-circle mr-1"></i>
                            Data baru otomatis berstatus <strong>AKTIF</strong>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="pt-4 space-y-2">
                        <button type="submit"
                                name="simpan"
                                class="btn-action w-full text-white py-3 rounded-lg font-semibold shadow-lg"
                                style="background:rgb(9, 120, 83);">
                            <i class="fas fa-save mr-2"></i>
                            <?= isset($edit) ? 'Update Data' : 'Simpan Data' ?>
                        </button>

                        <?php if (isset($edit)): ?>
                        <a href="master_jasa.php"
                           class="btn-action block w-full bg-gray-500 text-white py-3 rounded-lg font-semibold hover:bg-gray-600 text-center shadow-lg">
                            <i class="fas fa-times mr-2"></i>
                            Batal Edit
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Data Table (Kanan) -->
        <div class="xl:col-span-7 2xl:col-span-8">
            <div class="card rounded-2xl shadow-2xl p-4 sm:p-6">

                <!-- Filter & Search Bar -->
                <div class="mb-6 space-y-3">
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

                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text"
                                   id="searchInput"
                                   value="<?= htmlspecialchars($search) ?>"
                                   class="w-full pl-12 pr-10 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                                   placeholder="Ketik untuk mencari kode atau nama jasa...">
                            <?php if ($search != ''): ?>
                            <button onclick="clearSearch()"
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times-circle text-xl"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Table Header with Stats -->
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-list mr-2 text-purple-600"></i>
                        Data Jasa
                    </h2>
                    <?php
                    $total_aktif    = mysqli_num_rows(mysqli_query($connection, "SELECT * FROM master_jasa WHERE aktif='Y'"));
                    $total_nonaktif = mysqli_num_rows(mysqli_query($connection, "SELECT * FROM master_jasa WHERE aktif='N'"));
                    ?>
                    <div class="flex gap-2">
                        <span class="px-3 py-1 bg-green-500 text-white rounded-full text-xs font-semibold" id="badgeAktif">
                            ✓ Aktif: <?= $total_aktif ?>
                        </span>
                        <span class="px-3 py-1 bg-red-500 text-white rounded-full text-xs font-semibold" id="badgeNonaktif">
                            ✗ Nonaktif: <?= $total_nonaktif ?>
                        </span>
                    </div>
                </div>

                <!-- Scrollable Table -->
                <div class="scroll-container rounded-lg border-2 border-gray-200 overflow-x-auto">
                    <table class="w-full table-fixed min-w-[1000px]">
                        <thead class="text-white sticky top-0" style="background:rgb(9, 120, 83);">
                            <tr>
                                <th class="px-3 py-4 text-left font-semibold w-[14%]">Kode</th>
                                <th class="px-3 py-4 text-left font-semibold w-[32%]">Nama Jasa</th>
                                <th class="px-3 py-4 text-right font-semibold w-[13%]">Harga</th>
                                <th class="px-3 py-4 text-center font-semibold w-[10%]">Status</th>
                                <th class="px-3 py-4 text-center font-semibold w-[31%]">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="tableBody">
                            <?php
                            $q  = mysqli_query(
                                $connection,
                                "SELECT * FROM master_jasa $where ORDER BY aktif DESC, kode_pekerjaan ASC LIMIT $per_page OFFSET $offset"
                            );
                            $no = 0;
                            if (mysqli_num_rows($q) > 0) {
                                while ($d = mysqli_fetch_assoc($q)) {
                                    $no++;
                            ?>
                            <tr class="table-row <?= $d['aktif'] == 'N' ? 'opacity-60 bg-gray-100' : '' ?>" data-status="<?= $d['aktif'] ?>">
                                <td class="px-3 py-4 w-[14%]">
                                    <span class="font-mono font-semibold text-purple-600 text-sm">
                                        <?= htmlspecialchars($d['kode_pekerjaan']) ?>
                                    </span>
                                </td>
                                <td class="px-3 py-4 text-gray-800 w-[32%]">
                                    <div class="break-words leading-tight text-sm">
                                        <?= htmlspecialchars($d['nama_pekerjaan']) ?>
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-right w-[13%]">
                                    <div class="font-semibold text-green-600 whitespace-nowrap text-sm">
                                        Rp <?= number_format($d['harga'], 0, ',', '.') ?>
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-center w-[10%]">
                                    <?php if ($d['aktif'] == 'Y'): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                            <i class="fas fa-check-circle mr-1"></i>Aktif
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                            <i class="fas fa-times-circle mr-1"></i>Nonaktif
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 text-center w-[31%]">
                                    <div class="flex gap-1.5 justify-center flex-wrap">
                                        <a href="?edit=<?= $d['id_jasa'] ?>"
                                           class="btn-action px-3 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-xs font-semibold shadow-md"
                                           title="Edit Data">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($d['aktif'] == 'Y'): ?>
                                        <a href="?nonaktif=<?= $d['id_jasa'] ?>"
                                           onclick="return confirm('⚠️ Nonaktifkan jasa ini?\n\nJasa yang dinonaktifkan tidak akan muncul di daftar pilihan.')"
                                           class="btn-action px-3 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 text-xs font-semibold shadow-md"
                                           title="Nonaktifkan">
                                            <i class="fas fa-eye-slash"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="?aktifkan=<?= $d['id_jasa'] ?>"
                                           onclick="return confirm('✅ Aktifkan kembali jasa ini?\n\nCatatan: Sistem akan mengecek duplikasi kode terlebih dahulu.')"
                                           class="btn-action px-3 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-xs font-semibold shadow-md"
                                           title="Aktifkan">
                                            <i class="fas fa-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="?hapus=<?= $d['id_jasa'] ?>"
                                           onclick="return confirm('🗑️ HAPUS PERMANEN?\n\n⚠️ Data akan dihapus dari database dan TIDAK BISA dikembalikan!\n\nYakin ingin melanjutkan?')"
                                           class="btn-action px-3 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 text-xs font-semibold shadow-md"
                                           title="Hapus Permanen">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                            ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <div class="text-gray-400">
                                        <i class="fas fa-inbox text-6xl mb-4"></i>
                                        <p class="text-lg font-semibold">
                                            <?php
                                            if ($search != '' && $filter_status != 'semua')
                                                echo 'Tidak ada data yang sesuai dengan filter dan pencarian';
                                            elseif ($search != '')
                                                echo 'Tidak ada data yang sesuai dengan pencarian';
                                            elseif ($filter_status == 'aktif')
                                                echo 'Tidak ada jasa yang aktif';
                                            elseif ($filter_status == 'nonaktif')
                                                echo 'Tidak ada jasa yang nonaktif';
                                            else
                                                echo 'Belum ada data jasa';
                                            ?>
                                        </p>
                                        <?php if ($search != '' || $filter_status != 'semua'): ?>
                                        <a href="master_jasa.php" class="text-purple-600 hover:text-purple-700 mt-2 inline-block">
                                            Lihat semua data
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===================== PAGINATION BARU ===================== -->
                <?php if ($total_pages > 1): ?>
                <?php
                $base_params = [
                    'status' => $filter_status,
                    'search' => $search,
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
                        dari <span class="font-semibold"><?= $total_rows ?></span> data jasa
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

                <!-- Info baris ditampilkan -->
                <?php if ($no > 0): ?>
                <div class="mt-2 text-sm text-gray-600 flex items-center justify-between">
                    <span>
                        <i class="fas fa-info-circle mr-1"></i>
                        Ditampilkan: <strong id="displayCount"><?= $no ?></strong> baris (per halaman)
                    </span>
                    <?php if ($no > 5): ?>
                    <span class="text-gray-500">
                        <i class="fas fa-arrow-down mr-1"></i>
                        Scroll untuk melihat lebih banyak
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<script>
    // Auto-capitalize Nama Jasa
    const namaJasaInput = document.getElementById('namaJasa');
    if (namaJasaInput) {
        namaJasaInput.addEventListener('input', function() {
            const s = this.selectionStart, e = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(s, e);
        });
    }

    // Auto-hide alert setelah 5 detik
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'all 0.3s ease-out';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);

    // Format harga otomatis
    const hargaInput = document.getElementById('hargaInput');
    if (hargaInput) {
        if (hargaInput.value) formatHarga();
        hargaInput.addEventListener('input', formatHarga);
        hargaInput.addEventListener('blur',  formatHarga);

        function formatHarga() {
            let value = hargaInput.value.replace(/\D/g, '');
            if (value === '') { hargaInput.value = ''; return; }
            hargaInput.value = parseInt(value).toLocaleString('id-ID');
        }

        hargaInput.form.addEventListener('submit', function() {
            hargaInput.value = hargaInput.value.replace(/\./g, '').replace(/,/g, '');
        });
    }

    // Filter status
    const filterStatus = document.getElementById('filterStatus');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            const status = this.value;
            const search = document.getElementById('searchInput').value;
            let url = 'master_jasa.php?status=' + status;
            if (search) url += '&search=' + encodeURIComponent(search);
            window.location.href = url;
        });
    }

    // Live search
    const searchInput = document.getElementById('searchInput');
    let searchTimeout;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchValue = this.value.trim();
            const filterValue = filterStatus.value;
            searchTimeout = setTimeout(() => {
                let url = 'master_jasa.php?status=' + filterValue;
                if (searchValue !== '') url += '&search=' + encodeURIComponent(searchValue);
                window.location.href = url;
            }, 800);
        });
    }

    function clearSearch() {
        window.location.href = 'master_jasa.php?status=' + filterStatus.value;
    }

    // Update badge (client-side)
    function updateBadgeCounts() {
        const rows = document.querySelectorAll('#tableBody tr:not(.no-data)');
        let aktifCount = 0, nonaktifCount = 0, visibleCount = 0;
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                visibleCount++;
                const status = row.getAttribute('data-status');
                if (status === 'Y') aktifCount++;
                else if (status === 'N') nonaktifCount++;
            }
        });
        document.getElementById('badgeAktif').textContent    = '✓ Aktif: '    + aktifCount;
        document.getElementById('badgeNonaktif').textContent = '✗ Nonaktif: ' + nonaktifCount;
        document.getElementById('displayCount').textContent  = visibleCount;
    }

    // Highlight hasil pencarian
    const searchTerm = '<?= addslashes(htmlspecialchars($search)) ?>';
    if (searchTerm) {
        document.querySelectorAll('.table-row').forEach(row => {
            row.querySelectorAll('td').forEach(cell => {
                if (!cell.querySelector('.btn-action')) {
                    const regex = new RegExp('(' + searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                    cell.innerHTML = cell.textContent.replace(regex, '<mark class="bg-yellow-300 px-1 rounded">$1</mark>');
                }
            });
        });
    }
</script>

</body>
</html>
<?php
ob_end_flush();
?>
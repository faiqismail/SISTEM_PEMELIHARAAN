<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('alat_berat_wilayah_2');

// Fokus hanya pada Alat Berat Wilayah 2
$bidang_target = 'Alat Berat Wilayah 2';

// Function untuk format jangka waktu
function formatJangkaWaktu($tgl_mulai, $tgl_selesai) {
    if (!$tgl_mulai || !$tgl_selesai) {
        return '-';
    }
    
    $start = new DateTime($tgl_mulai);
    $end = new DateTime($tgl_selesai);
    $diff = $start->diff($end);
    
    $total_detik = abs(strtotime($tgl_selesai) - strtotime($tgl_mulai));
    
    if ($total_detik < 60) {
        return $total_detik . ' detik';
    }
    
    if ($total_detik < 3600) {
        $menit = floor($total_detik / 60);
        return $menit . ' menit';
    }
    
    if ($total_detik < 86400) {
        $jam = floor($total_detik / 3600);
        $menit = floor(($total_detik % 3600) / 60);
        if ($menit > 0) {
            return $jam . ' jam ' . $menit . ' menit';
        }
        return $jam . ' jam';
    }
    
    $hari = $diff->days;
    $jam = $diff->h;
    
    if ($jam > 0) {
        return $hari . ' hari ' . $jam . ' jam';
    }
    return $hari . ' hari';
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $search = isset($_GET['search']) ? mysqli_real_escape_string($connection, $_GET['search']) : '';
    $bulan = isset($_GET['bulan']) ? mysqli_real_escape_string($connection, $_GET['bulan']) : '';
    $tahun = isset($_GET['tahun']) ? mysqli_real_escape_string($connection, $_GET['tahun']) : date('Y');
    $rekanan = isset($_GET['rekanan']) ? mysqli_real_escape_string($connection, $_GET['rekanan']) : '';
    $tgl_dari = isset($_GET['tgl_dari']) ? mysqli_real_escape_string($connection, $_GET['tgl_dari']) : '';
    $tgl_sampai = isset($_GET['tgl_sampai']) ? mysqli_real_escape_string($connection, $_GET['tgl_sampai']) : '';
    
    $where = "WHERE p.status = 'Selesai' AND k.bidang = '$bidang_target'";
    
    if ($search != '') {
        $where .= " AND (k.nopol LIKE '%$search%' OR p.nomor_pengajuan LIKE '%$search%')";
    }
    if ($bulan != '') {
        $where .= " AND MONTH(p.tgl_selesai) = '$bulan'";
    }
    if ($tahun != '') {
        $where .= " AND YEAR(p.tgl_selesai) = '$tahun'";
    }
    if ($rekanan != '') {
        $where .= " AND p.id_rekanan = '$rekanan'";
    }
    if ($tgl_dari != '' && $tgl_sampai != '') {
        $where .= " AND DATE(p.tgl_selesai) BETWEEN '$tgl_dari' AND '$tgl_sampai'";
    } elseif ($tgl_dari != '') {
        $where .= " AND DATE(p.tgl_selesai) >= '$tgl_dari'";
    } elseif ($tgl_sampai != '') {
        $where .= " AND DATE(p.tgl_selesai) <= '$tgl_sampai'";
    }
    
    $query = "
    SELECT 
        p.*,
        k.nopol,
        k.jenis_kendaraan,
        k.bidang,
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
    $where
    ORDER BY p.tgl_selesai DESC
    ";
    
    $result = mysqli_query($connection, $query);
    
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=Riwayat_Alat_Berat_Wilayah_1_" . date('Ymd_His') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<style>';
    echo 'table { border-collapse: collapse; font-family: Arial; }';
    echo 'th { background-color: #10b981; color: white; font-weight: bold; padding: 10px; border: 1px solid #000; text-align: center; }';
    echo 'td { padding: 8px; border: 1px solid #000; vertical-align: top; }';
    echo '.number { mso-number-format:"\@"; }';
    echo '.currency { mso-number-format:"#,##0"; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>No</th>';
    echo '<th>No. Pengajuan</th>';
    echo '<th>Nomor Asset</th>';
    echo '<th>Jenis Kendaraan</th>';
    echo '<th>Bidang</th>';
    echo '<th>Pengaju/Driver</th>';
    echo '<th>Tanggal Diajukan</th>';
    echo '<th>Diperiksa Oleh (SA)</th>';
    echo '<th>Tanggal Diperiksa SA</th>';
    echo '<th>Disetujui Oleh (KARU QC)</th>';
    echo '<th>Tanggal Disetujui KARU QC</th>';
    echo '<th>Tanggal Selesai</th>';
    echo '<th>Jangka Waktu Pengerjaan</th>';
    echo '<th>Rekanan</th>';
    echo '<th>Biaya Jasa</th>';
    echo '<th>Biaya Sparepart</th>';
    echo '<th>Total Biaya</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (mysqli_num_rows($result) > 0) {
        $no = 1;
        while ($row = mysqli_fetch_assoc($result)) {
            $jangka_waktu = formatJangkaWaktu($row['tgl_disetujui_karu_qc'], $row['tgl_selesai']);
            
            echo '<tr>';
            echo '<td style="text-align: center;">' . $no++ . '</td>';
            echo '<td class="number">' . htmlspecialchars($row['nomor_pengajuan']) . '</td>';
            echo '<td class="number">' . htmlspecialchars($row['nopol']) . '</td>';
            echo '<td>' . htmlspecialchars($row['jenis_kendaraan']) . '</td>';
            echo '<td>' . htmlspecialchars($row['bidang']) . '</td>';
            echo '<td>' . htmlspecialchars($row['driver_nama'] ?? '-') . '</td>';
            echo '<td>' . ($row['tgl_pengajuan'] ? date('d/m/Y H:i', strtotime($row['tgl_pengajuan'])) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['sa_nama'] ?? '-') . '</td>';
            echo '<td>' . ($row['tgl_diperiksa_sa'] ? date('d/m/Y H:i', strtotime($row['tgl_diperiksa_sa'])) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['karu_nama'] ?? '-') . '</td>';
            echo '<td>' . ($row['tgl_disetujui_karu_qc'] ? date('d/m/Y H:i', strtotime($row['tgl_disetujui_karu_qc'])) : '-') . '</td>';
            echo '<td>' . ($row['tgl_selesai'] ? date('d/m/Y H:i', strtotime($row['tgl_selesai'])) : '-') . '</td>';
            echo '<td>' . $jangka_waktu . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_rekanan'] ?? '-') . '</td>';
            echo '<td class="currency">' . number_format($row['total_perbaikan'], 0, ',', '.') . '</td>';
            echo '<td class="currency">' . number_format($row['total_sparepart'], 0, ',', '.') . '</td>';
            echo '<td class="currency">' . number_format($row['grand_total'], 0, ',', '.') . '</td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}

include "navbar.php";

$search = isset($_GET['search']) ? mysqli_real_escape_string($connection, $_GET['search']) : '';
$bulan = isset($_GET['bulan']) ? mysqli_real_escape_string($connection, $_GET['bulan']) : '';
$tahun = isset($_GET['tahun']) ? mysqli_real_escape_string($connection, $_GET['tahun']) : date('Y');
$rekanan = isset($_GET['rekanan']) ? mysqli_real_escape_string($connection, $_GET['rekanan']) : '';
$tgl_dari = isset($_GET['tgl_dari']) ? mysqli_real_escape_string($connection, $_GET['tgl_dari']) : '';
$tgl_sampai = isset($_GET['tgl_sampai']) ? mysqli_real_escape_string($connection, $_GET['tgl_sampai']) : '';

$where = "WHERE p.status = 'Selesai' AND k.bidang = '$bidang_target'";

if ($search != '') {
    $where .= " AND (k.nopol LIKE '%$search%' OR p.nomor_pengajuan LIKE '%$search%')";
}
if ($bulan != '') {
    $where .= " AND MONTH(p.tgl_selesai) = '$bulan'";
}
if ($tahun != '') {
    $where .= " AND YEAR(p.tgl_selesai) = '$tahun'";
}
if ($rekanan != '') {
    $where .= " AND p.id_rekanan = '$rekanan'";
}
if ($tgl_dari != '' && $tgl_sampai != '') {
    $where .= " AND DATE(p.tgl_selesai) BETWEEN '$tgl_dari' AND '$tgl_sampai'";
} elseif ($tgl_dari != '') {
    $where .= " AND DATE(p.tgl_selesai) >= '$tgl_dari'";
} elseif ($tgl_sampai != '') {
    $where .= " AND DATE(p.tgl_selesai) <= '$tgl_sampai'";
}

$query = "
SELECT 
    p.*,
    k.nopol,
    k.jenis_kendaraan,
    k.bidang,
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
$where
ORDER BY p.tgl_selesai DESC
";

$result = mysqli_query($connection, $query);

$query_totals = "
SELECT 
    SUM(p.total_perbaikan) AS total_jasa,
    SUM(p.total_sparepart) AS total_sparepart,
    SUM(p.grand_total) AS grand_total
FROM permintaan_perbaikan p
LEFT JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
$where
";
$result_totals = mysqli_query($connection, $query_totals);
$totals = mysqli_fetch_assoc($result_totals);

$query_rekanan = "SELECT id_rekanan, nama_rekanan FROM rekanan ORDER BY nama_rekanan";
$result_rekanan = mysqli_query($connection, $query_rekanan);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Perbaikan - Alat Berat Wilayah 2</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:rgb(185, 224, 204); min-height: 100vh; }
        .main-container { padding: 20px; max-width: 100%; margin: 0 auto; }
        
        .page-header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-header-icon {
            font-size: 36px;
        }
        
        .page-header-content h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a6b3a;
            margin-bottom: 5px;
        }
        
        .page-header-content p {
            font-size: 14px;
            color: #6b7280;
        }
        
        .stats-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #10b981; }
        .stat-label { font-size: 13px; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; font-weight: 600; }
        .stat-value { font-size: 24px; font-weight: 700; color: #2c3e50; }
        .stat-value.money { color: rgb(9, 120, 83); }
        .filter-section { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 8px; }
        .filter-label { font-size: 13px; font-weight: 600; color: #2c3e50; }
        .filter-input { padding: 10px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; transition: all 0.3s ease; background: #f8f9fa; }
        .filter-input:focus { outline: none; border-color: #10b981; background: white; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
        .btn-reset, .btn-excel { padding: 10px 20px; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .btn-reset { background: #6c757d; }
        .btn-reset:hover { background: #5a6268; transform: translateY(-2px); }
        .btn-excel { background: linear-gradient(135deg, #10b981 0%,rgb(4, 76, 53) 100%); }
        .btn-excel:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4); }
        .table-container { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 25px; overflow-x: auto; }
        .table-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0; flex-wrap: wrap; gap: 10px; }
        .table-title { font-size: 20px; font-weight: 700; color: #2c3e50; display: flex; align-items: center; gap: 10px; }
        .count-badge { background:rgb(9, 120, 83); color: white; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .table-scroll { overflow-x: auto; overflow-y: auto; max-height: 600px; border-radius: 8px; border: 1px solid #e0e0e0; }
        .table-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #10b981; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; min-width: 1400px; }
        thead { position: sticky; top: 0; z-index: 10; background:rgb(9, 120, 83); }
        thead th { color: white; padding: 12px 10px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; white-space: nowrap; }
        tbody tr { border-bottom: 1px solid #f0f0f0; background: white; transition: all 0.2s; }
        tbody tr:hover { background: #f8fffe; }
        tbody td { padding: 12px 10px; font-size: 13px; color: #374151; vertical-align: top; }
        .nopol-badge { display: inline-block; padding: 6px 12px; background: linear-gradient(135deg,rgb(105, 92, 15),rgb(205, 174, 38)); color: white; border-radius: 6px; font-weight: 700; font-size: 13px; letter-spacing: 0.5px; white-space: nowrap; }
        .timeline { display: flex; flex-direction: column; gap: 8px; }
        .timeline-item { display: flex; align-items: center; gap: 8px; font-size: 11px; }
        .timeline-icon { width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .timeline-icon.green { background: #dcfce7; color: #059669; }
        .timeline-date { color: #6b7280; font-size: 11px; }
        .cost-breakdown { display: flex; flex-direction: column; gap: 5px; font-size: 12px; }
        .cost-item { display: flex; justify-content: space-between; gap: 10px; }
        .cost-label { color: #6b7280; }
        .cost-value { font-weight: 600; color: #1f2937; white-space: nowrap; }
        .cost-total { padding-top: 5px; border-top: 1px solid #e5e7eb; margin-top: 5px; }
        .cost-total .cost-value { color: #10b981; font-size: 14px; }
        .duration-badge { display: inline-block; padding: 6px 12px; background: #fef3c7; color: #92400e; border-radius: 12px; font-weight: 600; font-size: 12px; white-space: nowrap; }
        .btn-detail { padding: 8px 16px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .btn-detail:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4); }
        .no-data { text-align: center; padding: 60px 20px; color: #999; }
        .no-data i { font-size: 48px; margin-bottom: 15px; opacity: 0.3; }
        @media (max-width: 768px) {
            .main-container { padding: 15px; }
            .stats-container, .filter-grid { grid-template-columns: 1fr; }
            .filter-section, .table-container { padding: 15px; }
            .table-header { flex-direction: column; align-items: flex-start; }
            table { min-width: 1200px; }
            .page-header { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>

<div class="main-container">
    <div class="page-header">
        <div class="page-header-icon">🚜</div>
        <div class="page-header-content">
            <h1>RIWAYAT PERBAIKAN - ALAT BERAT WILAYAH 2</h1>
            <p>Data history perbaikan kendaraan yang telah selesai dikerjakan</p>
        </div>
    </div>

    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-label">Total Unit Dikerjakan</div>
            <div class="stat-value"><?= mysqli_num_rows($result) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Biaya Jasa</div>
            <div class="stat-value money">Rp <?= number_format($totals['total_jasa'] ?? 0, 0, ',', '.') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Biaya Sparepart</div>
            <div class="stat-value money">Rp <?= number_format($totals['total_sparepart'] ?? 0, 0, ',', '.') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Keseluruhan</div>
            <div class="stat-value money">Rp <?= number_format($totals['grand_total'] ?? 0, 0, ',', '.') ?></div>
        </div>
    </div>

    <div class="filter-section">
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Cari Nomor Asset / No. Pengajuan</label>
                    <input type="text" name="search" class="filter-input" placeholder="Ketik untuk mencari..." value="<?= htmlspecialchars($search) ?>" id="searchInput">
                </div>
                <div class="filter-group">
                    <label class="filter-label">Rekanan</label>
                    <select name="rekanan" class="filter-input" id="rekananInput">
                        <option value="">Semua Rekanan</option>
                        <?php while ($row_rekanan = mysqli_fetch_assoc($result_rekanan)): ?>
                            <option value="<?= $row_rekanan['id_rekanan'] ?>" <?= $rekanan == $row_rekanan['id_rekanan'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($row_rekanan['nama_rekanan']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Bulan</label>
                    <select name="bulan" class="filter-input" id="bulanInput">
                        <option value="">Semua Bulan</option>
                        <?php 
                        $bulan_list = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                        for($i=1; $i<=12; $i++): ?>
                            <option value="<?=$i?>" <?= $bulan == $i ? 'selected' : '' ?>><?=$bulan_list[$i-1]?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Tahun</label>
                    <select name="tahun" class="filter-input" id="tahunInput">
                        <?php
                        $current_year = date('Y');
                        for ($y = $current_year; $y >= $current_year - 5; $y--):
                            $selected = ($tahun == $y) ? 'selected' : '';
                            echo "<option value='$y' $selected>$y</option>";
                        endfor;
                        ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Tanggal Dari</label>
                    <input type="date" name="tgl_dari" class="filter-input" value="<?= htmlspecialchars($tgl_dari) ?>" id="tglDariInput">
                </div>
                <div class="filter-group">
                    <label class="filter-label">Tanggal Sampai</label>
                    <input type="date" name="tgl_sampai" class="filter-input" value="<?= htmlspecialchars($tgl_sampai) ?>" id="tglSampaiInput">
                </div>
                <div class="filter-group">
                    <a href="riwayat_kendaraan.php" class="btn-reset"><i class="fas fa-redo"></i> Reset</a>
                </div>
                <div class="filter-group">
                    <button type="button" onclick="exportExcel()" class="btn-excel"><i class="fas fa-file-excel"></i> Export Excel</button>
                </div>
            </div>
        </form>
    </div>

    <div class="table-container">
        <div class="table-header">
            <h2 class="table-title"><i class="fas fa-list"></i> Data Riwayat Perbaikan</h2>
            <span class="count-badge"><?= mysqli_num_rows($result) ?> Data</span>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">No</th>
                        <th style="width: 150px;">No. Pengajuan</th>
                        <th style="width: 120px;">Nomor Asset</th>
                        <th style="width: 150px;">Jenis Kendaraan</th>
                        <th style="width: 250px;">Timeline Proses</th>
                        <th style="width: 150px;">Jangka Waktu</th>
                        <th style="width: 150px;">Rekanan</th>
                        <th style="width: 180px;">Rincian Biaya</th>
                        <th style="width: 100px; text-align: center;">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (mysqli_num_rows($result) > 0):
                        $no = 1;
                        mysqli_data_seek($result, 0);
                        while ($row = mysqli_fetch_assoc($result)):
                            $jangka_waktu = formatJangkaWaktu($row['tgl_disetujui_karu_qc'], $row['tgl_selesai']);
                    ?>
                    <tr>
                        <td style="text-align: center; font-weight: 700; color: #10b981;"><?= $no++ ?></td>
                        <td><span style="font-family: monospace; font-weight: 600; color: #6366f1;"><?= htmlspecialchars($row['nomor_pengajuan']) ?></span></td>
                        <td><span class="nopol-badge"><?= htmlspecialchars($row['nopol']) ?></span></td>
                        <td><strong><?= htmlspecialchars($row['jenis_kendaraan']) ?></strong></td>
                        <td>
                            <div class="timeline">
                                <?php if ($row['tgl_pengajuan']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon green"><i class="fas fa-check" style="font-size: 8px;"></i></div>
                                    <div><strong style="font-size: 11px;">Diajukan</strong><div class="timeline-date"><?= date('d/m/Y H:i', strtotime($row['tgl_pengajuan'])) ?></div></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['tgl_diperiksa_sa']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon green"><i class="fas fa-check" style="font-size: 8px;"></i></div>
                                    <div><strong style="font-size: 11px;">Diperiksa SA</strong><div class="timeline-date"><?= date('d/m/Y H:i', strtotime($row['tgl_diperiksa_sa'])) ?></div></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['tgl_disetujui_karu_qc']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon green"><i class="fas fa-check" style="font-size: 8px;"></i></div>
                                    <div><strong style="font-size: 11px;">Disetujui KARU QC</strong><div class="timeline-date"><?= date('d/m/Y H:i', strtotime($row['tgl_disetujui_karu_qc'])) ?></div></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['tgl_selesai']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon green"><i class="fas fa-check" style="font-size: 8px;"></i></div>
                                    <div><strong style="font-size: 11px; color: #10b981;">Selesai</strong><div class="timeline-date"><?= date('d/m/Y H:i', strtotime($row['tgl_selesai'])) ?></div></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="text-align: center;">
                            <?php if ($jangka_waktu != '-'): ?>
                                <span class="duration-badge"><i class="fas fa-clock"></i> <?= $jangka_waktu ?></span>
                            <?php else: ?>
                                <span style="color: #9ca3af;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['nama_rekanan']): ?>
                                <span style="font-weight: 600; color: #1f2937;"><?= htmlspecialchars($row['nama_rekanan']) ?></span>
                            <?php else: ?>
                                <span style="color: #9ca3af;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cost-breakdown">
                                <div class="cost-item">
                                    <span class="cost-label">Jasa:</span>
                                    <span class="cost-value">Rp <?= number_format($row['total_perbaikan'], 0, ',', '.') ?></span>
                                </div>
                                <div class="cost-item">
                                    <span class="cost-label">Sparepart:</span>
                                    <span class="cost-value">Rp <?= number_format($row['total_sparepart'], 0, ',', '.') ?></span>
                                </div>
                                <div class="cost-item cost-total">
                                    <span class="cost-label"><strong>Total:</strong></span>
                                    <span class="cost-value">Rp <?= number_format($row['grand_total'], 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </td>
                        <td style="text-align: center;">
                            <a href="print_form_perbaikan.php?id=<?= $row['id_permintaan'] ?>" class="btn-detail"><i class="fas fa-eye"></i> Detail</a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="9">
                            <div class="no-data">
                                <i class="fas fa-inbox"></i>
                                <p>Tidak ada data riwayat perbaikan yang selesai</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let filterTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => { document.getElementById('filterForm').submit(); }, 800);
});
document.getElementById('bulanInput').addEventListener('change', function() { document.getElementById('filterForm').submit(); });
document.getElementById('tahunInput').addEventListener('change', function() { document.getElementById('filterForm').submit(); });
document.getElementById('rekananInput').addEventListener('change', function() { document.getElementById('filterForm').submit(); });
document.getElementById('tglDariInput').addEventListener('change', function() { document.getElementById('filterForm').submit(); });
document.getElementById('tglSampaiInput').addEventListener('change', function() { document.getElementById('filterForm').submit(); });

function exportExcel() {
    const search = document.getElementById('searchInput').value;
    const bulan = document.getElementById('bulanInput').value;
    const tahun = document.getElementById('tahunInput').value;
    const rekanan = document.getElementById('rekananInput').value;
    const tgl_dari = document.getElementById('tglDariInput').value;
    const tgl_sampai = document.getElementById('tglSampaiInput').value;
    
    let url = '?export=excel';
    if (search) url += '&search=' + encodeURIComponent(search);
    if (bulan) url += '&bulan=' + encodeURIComponent(bulan);
    if (tahun) url += '&tahun=' + encodeURIComponent(tahun);
    if (rekanan) url += '&rekanan=' + encodeURIComponent(rekanan);
    if (tgl_dari) url += '&tgl_dari=' + encodeURIComponent(tgl_dari);
    if (tgl_sampai) url += '&tgl_sampai=' + encodeURIComponent(tgl_sampai);
    
    window.location.href = url;
}
</script>

</body>
</html>
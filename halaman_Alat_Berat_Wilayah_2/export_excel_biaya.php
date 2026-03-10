<?php

include "../inc/config.php";
requireAuth('alat_berat_wilayah_2');


/* =========================
   AMBIL FILTER
========================= */
$filter_rekanan   = $_GET['rekanan'] ?? '';
$filter_bidang    = $_GET['bidang'] ?? '';
$filter_nopol     = $_GET['nopol'] ?? '';
$filter_periode   = $_GET['periode'] ?? '';
$filter_tipe      = isset($_GET['tipe']) ? trim($_GET['tipe']) : ''; // BARU: Filter tipe
$tanggal_dari     = $_GET['tanggal_dari'] ?? '';
$tanggal_sampai   = $_GET['tanggal_sampai'] ?? '';
$is_plain         = isset($_GET['plain']);

/* =========================
   WHERE CONDITION
========================= */
$where_conditions = [
    "pp.status = 'Selesai'",
    "pp.tgl_selesai IS NOT NULL"
];

if ($filter_rekanan != '') {
    $where_conditions[] = "r.id_rekanan = '" . mysqli_real_escape_string($connection, $filter_rekanan) . "'";
}

if ($filter_bidang != '') {
    $where_conditions[] = "k.bidang = '" . mysqli_real_escape_string($connection, $filter_bidang) . "'";
}

if ($filter_nopol != '') {
    $where_conditions[] = "k.nopol LIKE '%" . mysqli_real_escape_string($connection, $filter_nopol) . "%'";
}

/* =========================
   FILTER TANGGAL
========================= */
if ($tanggal_dari != '' && $tanggal_sampai != '') {
    $where_conditions[] = "
        DATE(pp.tgl_selesai) BETWEEN 
        '" . mysqli_real_escape_string($connection, $tanggal_dari) . "' 
        AND 
        '" . mysqli_real_escape_string($connection, $tanggal_sampai) . "'
    ";
} elseif ($filter_periode != '') {
    switch ($filter_periode) {
        case 'minggu_ini':
            $where_conditions[] = "YEARWEEK(pp.tgl_selesai,1) = YEARWEEK(CURDATE(),1)";
            break;
        case 'bulan_ini':
            $where_conditions[] = "
                MONTH(pp.tgl_selesai) = MONTH(CURDATE())
                AND YEAR(pp.tgl_selesai) = YEAR(CURDATE())
            ";
            break;
        case 'tahun_ini':
            $where_conditions[] = "YEAR(pp.tgl_selesai) = YEAR(CURDATE())";
            break;
    }
}

$where_clause = " WHERE " . implode(" AND ", $where_conditions);

/* =========================
   QUERY DATA
========================= */
$sql_jasa = "
SELECT 
    pp.id_permintaan,
    pp.nomor_pengajuan,
    pp.tgl_selesai AS tanggal_selesai,
    k.bidang,
    k.nopol,
    r.nama_rekanan,
    'JASA' AS tipe,
    pd.nama_pekerjaan AS nama_item,
    pd.qty,
    pd.harga,
    pd.subtotal
FROM permintaan_perbaikan pp
JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
JOIN perbaikan_detail pd ON pp.id_permintaan = pd.id_permintaan
$where_clause
";

$sql_sparepart = "
SELECT 
    pp.id_permintaan,
    pp.nomor_pengajuan,
    pp.tgl_selesai AS tanggal_selesai,
    k.bidang,
    k.nopol,
    r.nama_rekanan,
    'SPAREPART' AS tipe,
    sp.nama_sparepart AS nama_item,
    sd.qty,
    sd.harga,
    sd.subtotal
FROM permintaan_perbaikan pp
JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
JOIN sparepart_detail sd ON pp.id_permintaan = sd.id_permintaan
JOIN sparepart sp ON sd.id_sparepart = sp.id_sparepart
$where_clause
";

// BARU: Kondisi berdasarkan filter tipe
if ($filter_tipe == 'JASA') {
    $sql = $sql_jasa . " ORDER BY nama_rekanan, nopol, tanggal_selesai DESC";
} elseif ($filter_tipe == 'SPAREPART') {
    $sql = $sql_sparepart . " ORDER BY nama_rekanan, nopol, tanggal_selesai DESC";
} else {
    // Jika tidak ada filter atau "SEMUA", tampilkan keduanya dengan UNION
    $sql = $sql_jasa . " UNION ALL " . $sql_sparepart . " ORDER BY nama_rekanan, nopol, tanggal_selesai DESC";
}

$result = mysqli_query($connection, $sql);

/* =========================
   HEADER EXCEL
========================= */
$filename = "Laporan_Biaya_Perbaikan_" . date('Y-m-d_His') . ".xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

/* =========================
   HITUNG GRAND TOTAL
========================= */
$grand_jasa = $grand_sparepart = $grand_total = 0;

if ($result) {
    while ($r = mysqli_fetch_assoc($result)) {
        if ($r['tipe'] == 'JASA') $grand_jasa += $r['subtotal'];
        else $grand_sparepart += $r['subtotal'];
        $grand_total += $r['subtotal'];
    }
    mysqli_data_seek($result, 0);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid black;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4472C4;
            color: white;
            font-weight: bold;
        }
        .rekanan-header {
            background-color: #B4C7E7;
            font-weight: bold;
        }
        .group-header {
            background-color: #D9E2F3;
            font-weight: bold;
        }
        .summary-row {
            background-color: #FFF2CC;
            font-weight: bold;
        }
        .rekanan-summary-row {
            background-color: #E2DFED;
            font-weight: bold;
        }
        .grand-total-row {
            background-color: #C6E0B4;
            font-weight: bold;
            font-size: 14px;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="title">LAPORAN BIAYA PERBAIKAN</div>
    <div style="text-align: center; margin-bottom: 20px;">
        Laporan Transaksi Jasa & Sparepart dengan Status Selesai
        <?php if ($filter_tipe != ''): ?>
            <br><strong>Filter Tipe: <?= htmlspecialchars($filter_tipe) ?></strong>
        <?php endif; ?>
    </div>
    
    <?php if (!$is_plain): ?>
    <!-- Grand Total at Top -->
    <table style="margin-bottom: 20px;">
        <tr class="grand-total-row">
            <?php if ($filter_tipe == '' || $filter_tipe == 'JASA'): ?>
            <td class="text-center" style="width: <?= ($filter_tipe == 'JASA') ? '100%' : '33%' ?>;">
                <strong>Grand Total Jasa</strong><br>
                Rp <?= number_format($grand_jasa, 0, ',', '.') ?>
            </td>
            <?php endif; ?>
            
            <?php if ($filter_tipe == '' || $filter_tipe == 'SPAREPART'): ?>
            <td class="text-center" style="width: <?= ($filter_tipe == 'SPAREPART') ? '100%' : '33%' ?>;">
                <strong>Grand Total Sparepart</strong><br>
                Rp <?= number_format($grand_sparepart, 0, ',', '.') ?>
            </td>
            <?php endif; ?>
            
            <?php if ($filter_tipe == ''): ?>
            <td class="text-center" style="width: 34%;">
                <strong>Grand Total Keseluruhan</strong><br>
                Rp <?= number_format($grand_total, 0, ',', '.') ?>
            </td>
            <?php endif; ?>
        </tr>
    </table>
    <?php endif; ?>
    
    <table>
        <thead>
            <tr>
                <th class="text-center">No</th>
                <th>No. Pengajuan</th>
                <th>Tanggal</th>
                <th>Bulan</th>
                <th>Tahun</th>
                <th>Bidang</th>
                <th>Nomor Asset</th>
                <th>Rekanan</th>
                <th>Tipe</th>
                <th>Nama Item</th>
                <th class="text-center">QTY</th>
                <th class="text-right">Harga Satuan</th>
                <th class="text-right">Total Harga</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result && mysqli_num_rows($result) > 0) {
                $no = 1;
                $current_nopol = '';
                $current_rekanan = '';
                $current_group_key = '';
                
                $group_jasa = 0;
                $group_sparepart = 0;
                $group_total = 0;
                
                $rekanan_jasa = 0;
                $rekanan_sparepart = 0;
                $rekanan_total = 0;
                
                while ($row = mysqli_fetch_assoc($result)) {
                    $tanggal = date('d-m-Y', strtotime($row['tanggal_selesai']));
                    $bulan   = date('F', strtotime($row['tanggal_selesai']));
                    $tahun   = date('Y', strtotime($row['tanggal_selesai']));

                    
                    $group_key = $row['nama_rekanan'] . '|' . $row['nopol'];
                    
                    // Summary untuk grup sebelumnya
                    if (!$is_plain && $current_group_key != '' && $current_group_key != $group_key) {
                        $prev_group_parts = explode('|', $current_group_key);
                        $prev_nopol = isset($prev_group_parts[1]) ? $prev_group_parts[1] : $current_nopol;
                        
                        echo "<tr class='summary-row'>
                            <td colspan='10' class='text-right'><strong>Nomor Asset " . htmlspecialchars($prev_nopol) . ":</strong></td>
                            <td class='text-center'></td>
                            <td class='text-right'>";
                        
                        if ($filter_tipe == '' || $filter_tipe == 'JASA') {
                            echo "Jasa: Rp " . number_format($group_jasa, 0, ',', '.');
                            if ($filter_tipe == '') echo "<br>";
                        }
                        if ($filter_tipe == '' || $filter_tipe == 'SPAREPART') {
                            echo "Sparepart: Rp " . number_format($group_sparepart, 0, ',', '.');
                        }
                        
                        echo "</td>
                            <td class='text-right'><strong>Rp " . number_format($group_total, 0, ',', '.') . "</strong></td>
                        </tr>";
                        
                        $group_jasa = 0;
                        $group_sparepart = 0;
                        $group_total = 0;
                    }
                    
                    // Summary untuk rekanan sebelumnya
                    if (!$is_plain && $current_rekanan != '' && $current_rekanan != $row['nama_rekanan']) {
                        echo "<tr class='rekanan-summary-row'>
                            <td colspan='10' class='text-right'><strong>Total untuk rekanan " . htmlspecialchars($current_rekanan) . ":</strong></td>
                            <td class='text-center'></td>
                            <td class='text-right'>";
                        
                        if ($filter_tipe == '' || $filter_tipe == 'JASA') {
                            echo "Jasa: Rp " . number_format($rekanan_jasa, 0, ',', '.');
                            if ($filter_tipe == '') echo "<br>";
                        }
                        if ($filter_tipe == '' || $filter_tipe == 'SPAREPART') {
                            echo "Sparepart: Rp " . number_format($rekanan_sparepart, 0, ',', '.');
                        }
                        
                        echo "</td>
                            <td class='text-right'><strong>Rp " . number_format($rekanan_total, 0, ',', '.') . "</strong></td>
                        </tr>";
                        
                        $rekanan_jasa = 0;
                        $rekanan_sparepart = 0;
                        $rekanan_total = 0;
                    }
                    
                    // Header rekanan baru
                    if (!$is_plain && $current_rekanan != $row['nama_rekanan']) {
                        echo "<tr class='rekanan-header'>
                            <td colspan='13'>REKANAN: " . htmlspecialchars($row['nama_rekanan']) . "</td>
                        </tr>";
                    }
                    
                    // Header grup baru (nopol)
                    if (!$is_plain && $current_group_key != $group_key) {
                        echo "<tr class='group-header'>
                            <td colspan='13'>NOPOL: " . htmlspecialchars($row['nopol']) . " | BIDANG: " . htmlspecialchars($row['bidang']) . "</td>
                        </tr>";
                    }
                    
                    $current_nopol = $row['nopol'];
                    $current_rekanan = $row['nama_rekanan'];
                    $current_group_key = $group_key;
                    $total = $row['subtotal'];
                    
                    if ($row['tipe'] == 'JASA') {
                        $group_jasa += $total;
                        $rekanan_jasa += $total;
                    } else {
                        $group_sparepart += $total;
                        $rekanan_sparepart += $total;
                    }
                    $group_total += $total;
                    $rekanan_total += $total;
                    
                    echo "<tr>";
                    echo "<td class='text-center'>" . $no++ . "</td>";
                    echo "<td>" . htmlspecialchars($row['nomor_pengajuan']) . "</td>";
                    echo "<td>" . $tanggal . "</td>";
                    echo "<td>" . $bulan . "</td>";
                    echo "<td>" . $tahun . "</td>";
                    echo "<td>" . htmlspecialchars($row['bidang']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['nopol']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['nama_rekanan']) . "</td>";
                    echo "<td>" . $row['tipe'] . "</td>";
                    echo "<td>" . htmlspecialchars($row['nama_item']) . "</td>";
                    echo "<td class='text-center'>" . number_format($row['qty'], 0, ',', '.') . "</td>";
                    echo "<td class='text-right'>Rp " . number_format($row['harga'], 0, ',', '.') . "</td>";
                    echo "<td class='text-right'>Rp " . number_format($total, 0, ',', '.') . "</td>";
                    echo "</tr>";
                }
                
                // Summary terakhir grup
                if (!$is_plain && $group_total > 0) {
                    echo "<tr class='summary-row'>
                        <td colspan='10' class='text-right'><strong>Total untuk Asset " . htmlspecialchars($current_nopol) . ":</strong></td>
                        <td class='text-center'></td>
                        <td class='text-right'>";
                    
                    if ($filter_tipe == '' || $filter_tipe == 'JASA') {
                        echo "Jasa: Rp " . number_format($group_jasa, 0, ',', '.');
                        if ($filter_tipe == '') echo "<br>";
                    }
                    if ($filter_tipe == '' || $filter_tipe == 'SPAREPART') {
                        echo "Sparepart: Rp " . number_format($group_sparepart, 0, ',', '.');
                    }
                    
                    echo "</td>
                        <td class='text-right'><strong>Rp " . number_format($group_total, 0, ',', '.') . "</strong></td>
                    </tr>";
                }
                
                // Summary terakhir rekanan
                if (!$is_plain && $rekanan_total > 0) {
                    echo "<tr class='rekanan-summary-row'>
                        <td colspan='10' class='text-right'><strong>Total untuk rekanan " . htmlspecialchars($current_rekanan) . ":</strong></td>
                        <td class='text-center'></td>
                        <td class='text-right'>";
                    
                    if ($filter_tipe == '' || $filter_tipe == 'JASA') {
                        echo "Jasa: Rp " . number_format($rekanan_jasa, 0, ',', '.');
                        if ($filter_tipe == '') echo "<br>";
                    }
                    if ($filter_tipe == '' || $filter_tipe == 'SPAREPART') {
                        echo "Sparepart: Rp " . number_format($rekanan_sparepart, 0, ',', '.');
                    }
                    
                    echo "</td>
                        <td class='text-right'><strong>Rp " . number_format($rekanan_total, 0, ',', '.') . "</strong></td>
                    </tr>";
                }
                
                // Grand Total at Bottom
                if (!$is_plain) {
                    echo "<tr class='grand-total-row'>
                        <td colspan='10' class='text-right'><strong>GRAND TOTAL:</strong></td>
                        <td class='text-center'></td>
                        <td class='text-right'>";
                    
                    if ($filter_tipe == '' || $filter_tipe == 'JASA') {
                        echo "Jasa: Rp " . number_format($grand_jasa, 0, ',', '.');
                        if ($filter_tipe == '') echo "<br>";
                    }
                    if ($filter_tipe == '' || $filter_tipe == 'SPAREPART') {
                        echo "Sparepart: Rp " . number_format($grand_sparepart, 0, ',', '.');
                    }
                    
                    echo "</td>
                        <td class='text-right'><strong>Rp " . number_format($grand_total, 0, ',', '.') . "</strong></td>
                    </tr>";
                }
            } else {
                echo "<tr><td colspan='13' style='text-align: center;'>Tidak ada data perbaikan yang ditemukan</td></tr>";
            }
            ?>
        </tbody>
    </table>
</body>
</html>

<?php
mysqli_close($connection);
?>
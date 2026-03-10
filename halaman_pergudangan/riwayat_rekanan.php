<?php
// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('pergudangan');

// ==================== KONFIGURASI PAGINATION ====================
$view_all = isset($_GET['view_all']) && $_GET['view_all'] == '1'; // Flag untuk lihat semua data

if ($view_all) {
    // Jika view all, tidak ada limit
    $records_per_page = PHP_INT_MAX; // Set ke unlimited
    $current_page = 1;
    $offset = 0;
} else {
    // Normal pagination
    $records_per_page = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($current_page - 1) * $records_per_page;
}

// Get filter parameters
$filter_rekanan = isset($_GET['rekanan']) ? trim($_GET['rekanan']) : '';
$filter_nopol = isset($_GET['nopol']) ? trim($_GET['nopol']) : '';
$filter_periode = isset($_GET['periode']) ? trim($_GET['periode']) : '';
$filter_tipe = isset($_GET['tipe']) ? trim($_GET['tipe']) : '';
$tanggal_dari = isset($_GET['tanggal_dari']) ? trim($_GET['tanggal_dari']) : '';
$tanggal_sampai = isset($_GET['tanggal_sampai']) ? trim($_GET['tanggal_sampai']) : '';

// Cek apakah user sudah melakukan filter/search
$has_filter = !empty($filter_rekanan) || !empty($filter_nopol) || 
              !empty($filter_periode) || !empty($filter_tipe) || 
              !empty($tanggal_dari) || !empty($tanggal_sampai);

// Jika tidak ada filter, set default ke bulan dan tahun ini
if (!$has_filter && !isset($_GET['show_all_time'])) {
    $filter_periode = 'bulan_ini';
}

// ==================== OPTIMASI: Lazy Loading untuk Dropdown ====================
// Hanya load dropdown yang dibutuhkan, bukan semua data
$query_rekanan = "SELECT DISTINCT r.id_rekanan, r.nama_rekanan 
                  FROM rekanan r 
                  WHERE EXISTS (
                      SELECT 1 FROM permintaan_perbaikan pp 
                      INNER JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                      WHERE pp.id_rekanan = r.id_rekanan 
                      AND pp.status = 'Selesai' 
                      AND pp.tgl_selesai IS NOT NULL
                      AND k.bidang = 'pergudangan'
                  )
                  ORDER BY r.nama_rekanan
                  LIMIT 100"; // Batasi jumlah untuk performa
$result_rekanan = mysqli_query($connection, $query_rekanan);

// ==================== BUILD WHERE CLAUSE ====================
$where_conditions = [
    "pp.status = 'Selesai'", 
    "pp.tgl_selesai IS NOT NULL",
    "k.bidang = 'pergudangan'" // FILTER TETAP UNTUK BIDANG
];

if (!empty($filter_rekanan)) {
    $where_conditions[] = "r.id_rekanan = '" . mysqli_real_escape_string($connection, $filter_rekanan) . "'";
}

if (!empty($filter_nopol)) {
    $filter_nopol_escaped = mysqli_real_escape_string($connection, trim($filter_nopol));
    $where_conditions[] = "k.nopol LIKE '%" . $filter_nopol_escaped . "%'";
}

if (!empty($tanggal_dari) && !empty($tanggal_sampai)) {
    $where_conditions[] = "DATE(pp.tgl_selesai) BETWEEN '" . mysqli_real_escape_string($connection, $tanggal_dari) . "' 
              AND '" . mysqli_real_escape_string($connection, $tanggal_sampai) . "'";
} elseif (!empty($filter_periode)) {
    switch ($filter_periode) {
        case 'minggu_ini':
            $where_conditions[] = "YEARWEEK(pp.tgl_selesai, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'bulan_ini':
            $where_conditions[] = "MONTH(pp.tgl_selesai) = MONTH(CURDATE()) AND YEAR(pp.tgl_selesai) = YEAR(CURDATE())";
            break;
        case 'tahun_ini':
            $where_conditions[] = "YEAR(pp.tgl_selesai) = YEAR(CURDATE())";
            break;
    }
} elseif (!isset($_GET['show_all_time'])) {
    // Jika tidak ada filter periode dan bukan show_all_time, default ke bulan ini
    $where_conditions[] = "MONTH(pp.tgl_selesai) = MONTH(CURDATE()) AND YEAR(pp.tgl_selesai) = YEAR(CURDATE())";
}

$where_clause = " WHERE " . implode(" AND ", $where_conditions);

// ==================== COUNT TOTAL RECORDS (untuk pagination) ====================
$count_sql = "SELECT COUNT(*) as total FROM (
    SELECT pp.id_permintaan FROM permintaan_perbaikan pp
    INNER JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
    INNER JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
    INNER JOIN perbaikan_detail pd ON pp.id_permintaan = pd.id_permintaan
    " . $where_clause;

if (!empty($filter_tipe)) {
    if ($filter_tipe == 'JASA') {
        $count_sql .= ") as temp";
    } elseif ($filter_tipe == 'SPAREPART') {
        $count_sql = "SELECT COUNT(*) as total FROM (
            SELECT pp.id_permintaan FROM permintaan_perbaikan pp
            INNER JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
            INNER JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
            INNER JOIN sparepart_detail sd ON pp.id_permintaan = sd.id_permintaan
            " . $where_clause . ") as temp";
    } else {
        $sparepart_count = "SELECT pp.id_permintaan FROM permintaan_perbaikan pp
            INNER JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
            INNER JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
            INNER JOIN sparepart_detail sd ON pp.id_permintaan = sd.id_permintaan
            " . $where_clause;
        $count_sql .= " UNION ALL " . $sparepart_count . ") as temp";
    }
} else {
    $sparepart_count = "SELECT pp.id_permintaan FROM permintaan_perbaikan pp
        INNER JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
        INNER JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
        INNER JOIN sparepart_detail sd ON pp.id_permintaan = sd.id_permintaan
        " . $where_clause;
    $count_sql .= " UNION ALL " . $sparepart_count . ") as temp";
}

$count_result = mysqli_query($connection, $count_sql);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

// ==================== MAIN QUERY dengan PAGINATION ====================
$sql_jasa = "SELECT 
    pp.id_permintaan,
    pp.tgl_selesai,
    pp.nomor_pengajuan,
    k.bidang,
    k.nopol,
    r.nama_rekanan,
    'JASA' as tipe,
    pd.nama_pekerjaan as nama_item,
    pd.qty,
    pd.harga,
    pd.subtotal
FROM permintaan_perbaikan pp
INNER JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
INNER JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
INNER JOIN perbaikan_detail pd ON pp.id_permintaan = pd.id_permintaan
" . $where_clause;

$sql_sparepart = "SELECT 
    pp.id_permintaan,
    pp.tgl_selesai,
    pp.nomor_pengajuan,
    k.bidang,
    k.nopol,
    r.nama_rekanan,
    'SPAREPART' as tipe,
    sp.nama_sparepart as nama_item,
    sd.qty,
    sd.harga,
    sd.subtotal
FROM permintaan_perbaikan pp
INNER JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
INNER JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
INNER JOIN sparepart_detail sd ON pp.id_permintaan = sd.id_permintaan
INNER JOIN sparepart sp ON sd.id_sparepart = sp.id_sparepart
" . $where_clause;

if (!empty($filter_tipe)) {
    if ($filter_tipe == 'JASA') {
        $sql = $sql_jasa;
    } elseif ($filter_tipe == 'SPAREPART') {
        $sql = $sql_sparepart;
    } else {
        $sql = $sql_jasa . " UNION ALL " . $sql_sparepart;
    }
} else {
    $sql = $sql_jasa . " UNION ALL " . $sql_sparepart;
}

$sql .= " ORDER BY nama_rekanan, nopol, tgl_selesai DESC, id_permintaan, tipe";

// Hanya tambahkan LIMIT jika tidak view all
if (!$view_all) {
    $sql .= " LIMIT " . $offset . ", " . $records_per_page;
}

$result = mysqli_query($connection, $sql);

// ==================== HITUNG GRAND TOTAL (untuk halaman ini saja) ====================
$grand_jasa = 0;
$grand_sparepart = 0;
$grand_total = 0;

if ($result && mysqli_num_rows($result) > 0) {
    mysqli_data_seek($result, 0);
    while ($row = mysqli_fetch_assoc($result)) {
        $total = $row['subtotal'];
        if ($row['tipe'] == 'JASA') {
            $grand_jasa += $total;
        } else {
            $grand_sparepart += $total;
        }
        $grand_total += $total;
    }
    mysqli_data_seek($result, 0);
}

// ==================== HITUNG GRAND TOTAL KESELURUHAN (optional, untuk info) ====================
$sql_grand_total = "SELECT 
    SUM(CASE WHEN tipe = 'JASA' THEN subtotal ELSE 0 END) as total_jasa,
    SUM(CASE WHEN tipe = 'SPAREPART' THEN subtotal ELSE 0 END) as total_sparepart,
    SUM(subtotal) as total_keseluruhan
FROM (
    " . $sql_jasa . "
    UNION ALL
    " . $sql_sparepart . "
) as all_data";

$grand_total_result = mysqli_query($connection, $sql_grand_total);
$grand_total_data = mysqli_fetch_assoc($grand_total_result);

include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Biaya Perbaikan - pergudangan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            padding-left: 270px;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg,rgb(105, 92, 15),rgb(205, 174, 38));
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .filter-section {
            background: linear-gradient(to right, #f8f9fa, #e9ecef);
            padding: 25px;
            border-bottom: 3px solid #667eea;
        }
        
        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .btn-filter {
            background: linear-gradient(135deg,rgb(89, 119, 255) 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(76, 107, 246, 0.4);
        }
        
        .btn-reset {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(245, 87, 108, 0.4);
        }
        
        .btn-export {
            background: linear-gradient(135deg,rgb(25, 139, 238) 0%,rgb(0, 164, 172) 100%);
            color: white;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(79, 172, 254, 0.4);
        }

        .btn-export-plain {
            background: linear-gradient(135deg, #11998e 0%,rgb(14, 188, 81) 100%);
            color: white;
        }
        
        .btn-export-plain:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(17, 153, 142, 0.4);
        }
        
        .content-section {
            padding: 25px;
        }
        
        /* PAGINATION STYLES */
        .pagination-info {
            background: linear-gradient(to right, #e8f5e9, #c8e6c9);
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .pagination-info .info-text {
            font-size: 14px;
            color: #1b5e20;
            font-weight: 600;
        }
        
        .pagination-info .limit-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .pagination-info .limit-selector select {
            padding: 8px 12px;
            border: 2px solid #4caf50;
            border-radius: 6px;
            font-weight: 600;
            color: #1b5e20;
            background: white;
            cursor: pointer;
        }
        
        .pagination-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .pagination-controls button,
        .pagination-controls a {
            padding: 8px 15px;
            border: 2px solid #667eea;
            background: white;
            color: #667eea;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .pagination-controls button:hover,
        .pagination-controls a:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }
        
        .pagination-controls button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .pagination-controls .current-page {
            background: #667eea;
            color: white;
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: 700;
        }
        
        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            min-width: 1200px;
        }
        
        thead {
            background:rgb(9, 120, 83);
            color: white;
        }
        
        th {
            padding: 14px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 3px solid #fff;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }
        
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            color: #333;
        }
        
        tbody tr {
            transition: all 0.2s ease;
        }
        
        tbody tr:hover {
            background: #f8f9ff;
        }
        
        .group-header {
            background: linear-gradient(to right, #e3f2fd, #bbdefb) !important;
            font-weight: 700;
            color: #1565c0;
            font-size: 14px;
        }
        
        .group-header td {
            border-bottom: 2px solid #64b5f6;
            padding: 14px 10px;
        }

        .rekanan-header {
            background: linear-gradient(to right, #e1f5fe, #b3e5fc) !important;
            font-weight: 700;
            color: #01579b;
            font-size: 15px;
        }
        
        .rekanan-header td {
            border-bottom: 3px solid #0288d1;
            padding: 16px 10px;
        }

        .tipe-label {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .tipe-jasa {
            background: #e3f2fd;
            color: #1565c0;
        }

        .tipe-sparepart {
            background: #fff3e0;
            color: #e65100;
        }
        
        .summary-row {
            background: linear-gradient(to right, #fff9c4, #fff59d) !important;
            font-weight: 600;
            color: #f57f17;
        }
        
        .summary-row td {
            border-bottom: 1px solid #fdd835;
            padding: 10px;
            font-size: 13px;
        }

        .summary-info {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #f57f17;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 4px 0;
        }

        .summary-item {
            text-align: center;
            padding: 4px 6px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 4px;
        }

        .summary-item .label {
            font-size: 10px;
            display: block;
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .summary-item .value {
            font-size: 13px;
            font-weight: 700;
        }

        .rekanan-summary-row {
            background: linear-gradient(to right, #f3e5f5, #e1bee7) !important;
            font-weight: 600;
            color: #6a1b9a;
        }
        
        .rekanan-summary-row td {
            border-bottom: 2px solid #ba68c8;
            padding: 10px;
            font-size: 13px;
        }

        .rekanan-summary-info {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #6a1b9a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .rekanan-summary-row .summary-item {
            background: rgba(255, 255, 255, 0.7);
            padding: 4px 6px;
        }

        .rekanan-summary-row .summary-item .label {
            font-size: 10px;
            margin-bottom: 2px;
        }

        .rekanan-summary-row .value {
            font-size: 13px;
        }

        .grand-total-top {
            background: linear-gradient(to right, #c8e6c9, #a5d6a7);
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .grand-total-top .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .grand-total-top .summary-item {
            text-align: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .grand-total-top .summary-item .label {
            font-size: 11px;
            display: block;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1b5e20;
            font-weight: 600;
        }

        .grand-total-top .summary-item .value {
            font-size: 18px;
            font-weight: 700;
            color: #1b5e20;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #999;
            font-size: 16px;
            font-style: italic;
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 40px;
        }
        
        .loading-spinner.active {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 1024px) {
            body {
                padding-left: 20px;
            }
        }

        @media (max-width: 768px) {
            .grand-total-top {
                padding: 10px;
                margin-bottom: 12px;
                border-radius: 8px;
            }

            .grand-total-top .summary-grid {
                display: flex;
                flex-direction: column;
                gap: 8px;
                width: 100%;
            }

            .grand-total-top .summary-item {
                padding: 8px 10px;
                border-radius: 6px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                width: 100%;
                box-sizing: border-box;
            }

            .grand-total-top .summary-item .label {
                font-size: 10px;
                margin-bottom: 0;
                text-align: left;
                flex: 1;
                display: flex;
                align-items: center;
                gap: 5px;
            }

            .grand-total-top .summary-item .label i {
                font-size: 10px;
            }

            .grand-total-top .summary-item .value {
                font-size: 13px;
                text-align: right;
                white-space: nowrap;
                font-weight: 700;
            }
            
            .pagination-controls {
                gap: 5px;
            }
            
            .pagination-controls button,
            .pagination-controls a {
                padding: 6px 10px;
                font-size: 12px;
            }
        }
        
        @media print {
            body {
                background: white;
                padding-left: 0;
            }
            .filter-section, .button-group, .pagination-controls, .pagination-info {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-file-invoice-dollar"></i> LAPORAN BIAYA PERBAIKAN</h1>
            <p>Laporan Transaksi Jasa & Sparepart - pergudangan</p>
            <?php if (!$has_filter && !isset($_GET['show_all_time'])): ?>
            <p style="margin-top: 10px; background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 8px; display: inline-block;">
                <i class="fas fa-info-circle"></i> Default menampilkan data <strong>Bulan Ini</strong>. 
                Gunakan filter untuk melihat periode lain.
            </p>
            <?php endif; ?>
        </div>
        
        <div class="filter-section">
            <div class="filter-title">
                <i class="fas fa-filter"></i> Filter Data
            </div>
            <form method="GET" action="" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="fas fa-building"></i> Rekanan:</label>
                        <select name="rekanan" id="rekanan">
                            <option value="">-- Semua Rekanan --</option>
                            <?php 
                            if ($result_rekanan) {
                                mysqli_data_seek($result_rekanan, 0);
                                while($row = mysqli_fetch_assoc($result_rekanan)): 
                            ?>
                                <option value="<?= $row['id_rekanan'] ?>" 
                                    <?= $filter_rekanan == $row['id_rekanan'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($row['nama_rekanan']) ?>
                                </option>
                            <?php 
                                endwhile;
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-car"></i> Nomor Asset:</label>
                        <input type="text" name="nopol" id="nopol" value="<?= htmlspecialchars($filter_nopol) ?>" 
                               placeholder="Cari Nomor Asset..." autocomplete="off">
                        <small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                            <i class="fas fa-info-circle"></i> Kosongkan untuk menampilkan semua
                        </small>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-tags"></i> Tipe:</label>
                        <select name="tipe" id="tipe">
                            <option value="">-- Semua Tipe --</option>
                            <option value="JASA" <?= $filter_tipe == 'JASA' ? 'selected' : '' ?>>Jasa</option>
                            <option value="SPAREPART" <?= $filter_tipe == 'SPAREPART' ? 'selected' : '' ?>>Sparepart</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="fas fa-clock"></i> Periode Cepat:</label>
                        <select name="periode" id="periode">
                            <option value="">-- Pilih Periode --</option>
                            <option value="minggu_ini" <?= $filter_periode == 'minggu_ini' ? 'selected' : '' ?>>
                                Minggu Ini
                            </option>
                            <option value="bulan_ini" <?= $filter_periode == 'bulan_ini' ? 'selected' : '' ?>>
                                Bulan Ini
                            </option>
                            <option value="tahun_ini" <?= $filter_periode == 'tahun_ini' ? 'selected' : '' ?>>
                                Tahun Ini
                            </option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> Tanggal Dari:</label>
                        <input type="date" name="tanggal_dari" value="<?= htmlspecialchars($tanggal_dari) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-check"></i> Tanggal Sampai:</label>
                        <input type="date" name="tanggal_sampai" value="<?= htmlspecialchars($tanggal_sampai) ?>">
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search"></i> Filter Data
                    </button>
                    <button type="button" class="btn-reset" onclick="resetToDefault()">
                        <i class="fas fa-redo"></i> Reset ke Bulan Ini
                    </button>
                    <button type="button" class="btn-reset" onclick="showAllTime()" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="fas fa-clock"></i> Tampilkan Semua Periode
                    </button>
                    <button type="button" class="btn-export" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export Excel (Format)
                    </button>
                    <button type="button" class="btn-export-plain" onclick="exportToExcelPlain()">
                        <i class="fas fa-table"></i> Export Excel (Polosan)
                    </button>
                    <button type="button" class="btn-export" onclick="viewAllData()" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-eye"></i> Lihat Semua Data
                    </button>
                </div>
            </form>
        </div>
        
        <div class="content-section">
            <!-- Warning jika view all -->
            <?php if ($view_all): ?>
            <div style="background: linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%); padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 5px solid #ff6b6b;">
                <div style="display: flex; align-items: center; gap: 10px; color: #c92a2a; font-weight: 700;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 24px;"></i>
                    <div>
                        <div style="font-size: 16px; margin-bottom: 5px;">MODE LIHAT SEMUA DATA</div>
                        <div style="font-size: 13px; font-weight: 500;">
                            Menampilkan <?= number_format($total_records) ?> record tanpa pagination. 
                            Klik tombol di bawah untuk kembali ke mode pagination.
                        </div>
                    </div>
                </div>
                <button type="button" onclick="backToPagination()" 
                    style="margin-top: 10px; background: #c92a2a; color: white; padding: 8px 15px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    <i class="fas fa-arrow-left"></i> Kembali ke Mode Pagination
                </button>
            </div>
            <?php endif; ?>

            <!-- Pagination Info -->
            <?php if (!$view_all): ?>
            <div class="pagination-info">
                <div class="info-text">
                    <i class="fas fa-info-circle"></i>
                    Menampilkan data <?= number_format($offset + 1) ?> - <?= number_format(min($offset + $records_per_page, $total_records)) ?> 
                    dari <?= number_format($total_records) ?> total record
                    <?php if (!$has_filter && !isset($_GET['show_all_time'])): ?>
                        <span style="color: #2e7d32; font-weight: 700; margin-left: 10px;">
                            <i class="fas fa-calendar-check"></i> (Bulan Ini)
                        </span>
                    <?php elseif (isset($_GET['show_all_time'])): ?>
                        <span style="color: #1565c0; font-weight: 700; margin-left: 10px;">
                            <i class="fas fa-globe"></i> (Semua Periode)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="limit-selector">
                    <label><i class="fas fa-list-ol"></i> Data per halaman:</label>
                    <select id="limitSelect" onchange="changeLimit()">
                        <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $records_per_page == 200 ? 'selected' : '' ?>>200</option>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <!-- Total Halaman Ini -->
            <?php if ($result && mysqli_num_rows($result) > 0): ?>
            <div class="grand-total-top">
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="label"><i class="fas fa-wrench"></i> Total Jasa (Halaman Ini)</span>
                        <span class="value">Rp <?= number_format($grand_jasa, 0, ',', '.') ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label"><i class="fas fa-cog"></i> Total Sparepart (Halaman Ini)</span>
                        <span class="value">Rp <?= number_format($grand_sparepart, 0, ',', '.') ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label"><i class="fas fa-calculator"></i> Total Halaman Ini</span>
                        <span class="value">Rp <?= number_format($grand_total, 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pagination Controls Top -->
            <?php if ($total_pages > 1 && !$view_all): ?>
            <div class="pagination-controls">
                <?php if ($current_page > 1): ?>
                    <a href="?page=1<?= buildQueryString() ?>">
                        <i class="fas fa-angle-double-left"></i> Awal
                    </a>
                    <a href="?page=<?= $current_page - 1 ?><?= buildQueryString() ?>">
                        <i class="fas fa-angle-left"></i> Prev
                    </a>
                <?php endif; ?>

                <span class="current-page">
                    <i class="fas fa-file-alt"></i> Hal <?= $current_page ?> / <?= $total_pages ?>
                </span>

                <?php if ($current_page < $total_pages): ?>
                    <a href="?page=<?= $current_page + 1 ?><?= buildQueryString() ?>">
                        Next <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?page=<?= $total_pages ?><?= buildQueryString() ?>">
                        Akhir <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Loading Spinner -->
            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner"></div>
                <p style="margin-top: 15px; color: #667eea; font-weight: 600;">Memuat data...</p>
            </div>
            
            <div class="table-wrapper" id="tableWrapper">
                <table id="dataTable">
                    <thead>
                        <tr>
                            <th class="text-center">No</th>
                            <th>No. Pengajuan</th>
                            <th>Tanggal</th>
                            <th>Bulan</th>
                            <th>Tahun</th>
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
                            $no = $offset + 1;
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
                                $tanggal = date('d-m-Y', strtotime($row['tgl_selesai']));
                                $bulan = date('F', strtotime($row['tgl_selesai']));
                                $tahun = date('Y', strtotime($row['tgl_selesai']));
                                
                                $group_key = $row['nama_rekanan'] . '|' . $row['nopol'];
                                
                                if ($current_group_key != '' && $current_group_key != $group_key) {
                                    $prev_group_parts = explode('|', $current_group_key);
                                    $prev_nopol = isset($prev_group_parts[1]) ? $prev_group_parts[1] : $current_nopol;
                                    
                                    echo "<tr class='summary-row'>
                                        <td colspan='12'>
                                            <div class='summary-info'>
                                                <i class='fas fa-info-circle'></i>
                                                <span>Total untuk Nomor Asset " . htmlspecialchars($prev_nopol) . "</span>
                                            </div>
                                            <div class='summary-grid'>
                                                <div class='summary-item'>
                                                    <span class='label'><i class='fas fa-wrench'></i> Total Jasa</span>
                                                    <span class='value'>Rp " . number_format($group_jasa, 0, ',', '.') . "</span>
                                                </div>
                                                <div class='summary-item'>
                                                    <span class='label'><i class='fas fa-cog'></i> Total Sparepart</span>
                                                    <span class='value'>Rp " . number_format($group_sparepart, 0, ',', '.') . "</span>
                                                </div>
                                                <div class='summary-item'>
                                                    <span class='label'><i class='fas fa-calculator'></i> Total Keseluruhan</span>
                                                    <span class='value'>Rp " . number_format($group_total, 0, ',', '.') . "</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>";
                                    
                                    $group_jasa = 0;
                                    $group_sparepart = 0;
                                    $group_total = 0;
                                }
                                
                                if ($current_rekanan != '' && $current_rekanan != $row['nama_rekanan']) {
                                    echo "<tr class='rekanan-summary-row'>
                                        <td colspan='12'>
                                            <div class='rekanan-summary-info'>
                                                <i class='fas fa-info-circle'></i>
                                                <span>Total untuk rekanan " . htmlspecialchars($current_rekanan) . "</span>
                                            </div>
                                            <div class='summary-grid'>
                                                <div class='summary-item'>
                                                    <span class='label'><i class='fas fa-wrench'></i> Total Jasa Rekanan</span>
                                                    <span class='value'>Rp " . number_format($rekanan_jasa, 0, ',', '.') . "</span>
                                                </div>
                                                <div class='summary-item'>
                                                    <span class='label'><i class='fas fa-cog'></i> Total Sparepart Rekanan</span>
                                                    <span class='value'>Rp " . number_format($rekanan_sparepart, 0, ',', '.') . "</span>
                                                </div>
                                                <div class='summary-item'>
                                                    <span class='label'><i class='fas fa-calculator'></i> Total Rekanan</span>
                                                    <span class='value'>Rp " . number_format($rekanan_total, 0, ',', '.') . "</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>";
                                    
                                    $rekanan_jasa = 0;
                                    $rekanan_sparepart = 0;
                                    $rekanan_total = 0;
                                }
                                
                                if ($current_rekanan != $row['nama_rekanan']) {
                                    echo "<tr class='rekanan-header'>
                                        <td colspan='12'>
                                            <i class='fas fa-building'></i> REKANAN: " . htmlspecialchars($row['nama_rekanan']) . "
                                        </td>
                                    </tr>";
                                }
                                
                                if ($current_group_key != $group_key) {
                                    echo "<tr class='group-header'>
                                        <td colspan='12'>
                                            <i class='fas fa-car'></i> NOMOR ASSET: " . htmlspecialchars($row['nopol']) . "
                                        </td>
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
                                
                                $tipe_class = $row['tipe'] == 'JASA' ? 'tipe-jasa' : 'tipe-sparepart';
                                
                                echo "<tr>";
                                echo "<td class='text-center'>" . $no++ . "</td>";
                                echo "<td>" . htmlspecialchars($row['nomor_pengajuan']) . "</td>";
                                echo "<td>" . $tanggal . "</td>";
                                echo "<td>" . $bulan . "</td>";
                                echo "<td>" . $tahun . "</td>";
                                echo "<td><strong>" . htmlspecialchars($row['nopol']) . "</strong></td>";
                                echo "<td>" . htmlspecialchars($row['nama_rekanan']) . "</td>";
                                echo "<td><span class='tipe-label $tipe_class'>" . $row['tipe'] . "</span></td>";
                                echo "<td>" . htmlspecialchars($row['nama_item']) . "</td>";
                                echo "<td class='text-center'>" . number_format($row['qty'], 0, ',', '.') . "</td>";
                                echo "<td class='text-right'>Rp " . number_format($row['harga'], 0, ',', '.') . "</td>";
                                echo "<td class='text-right'><strong>Rp " . number_format($total, 0, ',', '.') . "</strong></td>";
                                echo "</tr>";
                            }
                            
                            if ($group_total > 0) {
                                echo "<tr class='summary-row'>
                                    <td colspan='12'>
                                        <div class='summary-info'>
                                            <i class='fas fa-info-circle'></i>
                                            <span>Total untuk Asset " . htmlspecialchars($current_nopol) . "</span>
                                        </div>
                                        <div class='summary-grid'>
                                            <div class='summary-item'>
                                                <span class='label'><i class='fas fa-wrench'></i> Total Jasa</span>
                                                <span class='value'>Rp " . number_format($group_jasa, 0, ',', '.') . "</span>
                                            </div>
                                            <div class='summary-item'>
                                                <span class='label'><i class='fas fa-cog'></i> Total Sparepart</span>
                                                <span class='value'>Rp " . number_format($group_sparepart, 0, ',', '.') . "</span>
                                            </div>
                                            <div class='summary-item'>
                                                <span class='label'><i class='fas fa-calculator'></i> Total Keseluruhan</span>
                                                <span class='value'>Rp " . number_format($group_total, 0, ',', '.') . "</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>";
                            }
                            
                            if ($rekanan_total > 0) {
                                echo "<tr class='rekanan-summary-row'>
                                    <td colspan='12'>
                                        <div class='rekanan-summary-info'>
                                            <i class='fas fa-info-circle'></i>
                                            <span>Total untuk rekanan " . htmlspecialchars($current_rekanan) . "</span>
                                        </div>
                                        <div class='summary-grid'>
                                            <div class='summary-item'>
                                                <span class='label'><i class='fas fa-wrench'></i> Total Jasa Rekanan</span>
                                                <span class='value'>Rp " . number_format($rekanan_jasa, 0, ',', '.') . "</span>
                                            </div>
                                            <div class='summary-item'>
                                                <span class='label'><i class='fas fa-cog'></i> Total Sparepart Rekanan</span>
                                                <span class='value'>Rp " . number_format($rekanan_sparepart, 0, ',', '.') . "</span>
                                            </div>
                                            <div class='summary-item'>
                                                <span class='label'><i class='fas fa-calculator'></i> Total Rekanan</span>
                                                <span class='value'>Rp " . number_format($rekanan_total, 0, ',', '.') . "</span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>";
                            }
                        } else {
                            $no_data_text = "Tidak ada data perbaikan yang ditemukan";
                            if (!$has_filter && !isset($_GET['show_all_time'])) {
                                $no_data_text .= " untuk <strong>Bulan Ini</strong>";
                            }
                            echo "<tr><td colspan='12' class='no-data'>
                                <i class='fas fa-inbox'></i><br>
                                " . $no_data_text . "
                            </td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Grand Total Keseluruhan (SEMUA DATA) - Di Bawah Tabel -->
            <?php if ($result && mysqli_num_rows($result) > 0 && $grand_total_data): ?>
            <div class="grand-total-top" style="background: linear-gradient(to right, #fff9c4, #fff59d); margin-top: 20px;">
                <div style="text-align: center; margin-bottom: 10px; font-weight: 700; color: #f57f17;">
                    <i class="fas fa-chart-line"></i> GRAND TOTAL KESELURUHAN (SEMUA DATA)
                </div>
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="label"><i class="fas fa-money-bill-wave"></i> Grand Total Jasa</span>
                        <span class="value">Rp <?= number_format($grand_total_data['total_jasa'], 0, ',', '.') ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label"><i class="fas fa-money-bill-wave"></i> Grand Total Sparepart</span>
                        <span class="value">Rp <?= number_format($grand_total_data['total_sparepart'], 0, ',', '.') ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label"><i class="fas fa-money-check-alt"></i> Grand Total Semua</span>
                        <span class="value">Rp <?= number_format($grand_total_data['total_keseluruhan'], 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pagination Controls Bottom -->
            <?php if ($total_pages > 1 && !$view_all): ?>
            <div class="pagination-controls">
                <?php if ($current_page > 1): ?>
                    <a href="?page=1<?= buildQueryString() ?>">
                        <i class="fas fa-angle-double-left"></i> Awal
                    </a>
                    <a href="?page=<?= $current_page - 1 ?><?= buildQueryString() ?>">
                        <i class="fas fa-angle-left"></i> Prev
                    </a>
                <?php endif; ?>

                <span class="current-page">
                    <i class="fas fa-file-alt"></i> Hal <?= $current_page ?> / <?= $total_pages ?>
                </span>

                <?php if ($current_page < $total_pages): ?>
                    <a href="?page=<?= $current_page + 1 ?><?= buildQueryString() ?>">
                        Next <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?page=<?= $total_pages ?><?= buildQueryString() ?>">
                        Akhir <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function exportToExcel() {
            const params = new URLSearchParams(new FormData(document.querySelector('form')));
            params.append('export', 'excel'); // Tambahkan flag export
            window.location.href = 'export_excel_biaya.php?' + params.toString();
        }

        function exportToExcelPlain() {
            const params = new URLSearchParams(new FormData(document.querySelector('form')));
            params.append('plain', '1');
            params.append('export', 'excel'); // Tambahkan flag export
            window.location.href = 'export_excel_biaya.php?' + params.toString();
        }

        function viewAllData() {
            if (confirm('⚠️ PERHATIAN!\n\nAnda akan melihat SEMUA DATA tanpa pagination.\n\nJika data sangat banyak (>1000 record), halaman mungkin lambat atau browser crash.\n\nLanjutkan?')) {
                const params = new URLSearchParams(window.location.search);
                params.set('view_all', '1'); // Flag untuk lihat semua
                params.delete('page'); // Hapus pagination
                params.delete('limit'); // Hapus limit
                
                // Show loading
                document.getElementById('loadingSpinner').classList.add('active');
                document.getElementById('tableWrapper').style.display = 'none';
                
                window.location.href = '?' + params.toString();
            }
        }

        function backToPagination() {
            const params = new URLSearchParams(window.location.search);
            params.delete('view_all'); // Hapus flag view all
            params.set('page', '1'); // Reset ke halaman 1
            window.location.href = '?' + params.toString();
        }

        function resetToDefault() {
            // Reset ke halaman utama (bulan ini)
            window.location.href = '<?= $_SERVER['PHP_SELF'] ?>';
        }

        function showAllTime() {
            // Tampilkan semua periode (tanpa filter waktu)
            const params = new URLSearchParams(window.location.search);
            
            // Hapus semua filter waktu
            params.delete('periode');
            params.delete('tanggal_dari');
            params.delete('tanggal_sampai');
            
            // Set flag show_all_time
            params.set('show_all_time', '1');
            params.set('page', '1');
            
            // Show loading
            document.getElementById('loadingSpinner').classList.add('active');
            
            window.location.href = '?' + params.toString();
        }

        function changeLimit() {
            const limit = document.getElementById('limitSelect').value;
            const params = new URLSearchParams(window.location.search);
            params.set('limit', limit);
            params.set('page', '1'); // Reset ke halaman 1
            params.delete('view_all'); // Hapus flag view all
            window.location.href = '?' + params.toString();
        }
        
        // Show loading on form submit
        document.getElementById('filterForm').addEventListener('submit', function() {
            document.getElementById('loadingSpinner').classList.add('active');
            document.getElementById('tableWrapper').style.opacity = '0.5';
        });
        
        document.querySelectorAll('input[type="date"]').forEach(input => {
            input.addEventListener('change', function() {
                if (this.value) {
                    document.getElementById('periode').value = '';
                }
            });
        });
        
        document.getElementById('periode').addEventListener('change', function() {
            if (this.value) {
                document.querySelectorAll('input[type="date"]').forEach(input => {
                    input.value = '';
                });
            }
        });
    </script>
</body>
</html>

<?php
// Helper function untuk build query string
function buildQueryString() {
    $params = $_GET;
    unset($params['page']); // Remove page parameter
    
    if (empty($params)) {
        return '';
    }
    
    return '&' . http_build_query($params);
}

mysqli_close($connection);
?>
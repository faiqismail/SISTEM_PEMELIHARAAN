<?php
include "../inc/config.php";
requireAuth('admin');

// Get current month and year
$current_month = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
$current_year = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');
$view_type = isset($_GET['view']) ? $_GET['view'] : 'sparepart';
$period_type = isset($_GET['period']) ? $_GET['period'] : 'bulan';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$selected_bidang = isset($_GET['bidang']) ? $_GET['bidang'] : ''; // NEW: Filter bidang

// Get list bidang dari tabel kendaraan
$query_bidang = "SELECT DISTINCT bidang FROM kendaraan WHERE bidang IS NOT NULL AND bidang != '' ORDER BY bidang";
$result_bidang = mysqli_query($connection, $query_bidang);

// Handle Excel Export
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    header('Content-Type: application/vnd.ms-excel');
    $filename = "Analisa_" . ($view_type == 'sparepart' ? 'Sparepart' : 'Jasa') . 
                "_" . ($period_type == 'tahun' ? $current_year : $current_month . "_" . $current_year) . 
                "_" . date('YmdHis') . ".xls";
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Build query berdasarkan view type
    $search_condition = "";
    if (!empty($search_query)) {
        $search_query_escaped = mysqli_real_escape_string($connection, $search_query);
        if ($view_type == 'sparepart') {
            $search_condition = " AND (sp.nama_sparepart LIKE '%$search_query_escaped%' OR sp.kode_sparepart LIKE '%$search_query_escaped%')";
        } else {
            $search_condition = " AND (mj.nama_pekerjaan LIKE '%$search_query_escaped%' OR mj.kode_pekerjaan LIKE '%$search_query_escaped%')";
        }
    }
    
// NEW: Filter bidang untuk export
$bidang_condition = "";
if (!empty($selected_bidang)) {
    $selected_bidang_escaped = mysqli_real_escape_string($connection, $selected_bidang);
    $bidang_condition = " AND k.bidang = '$selected_bidang_escaped'";
}

if ($view_type == 'sparepart') {
    if ($period_type == 'tahun') {
        $sql = "SELECT 
            sp.id_sparepart,
            sp.kode_sparepart,
            sp.nama_sparepart,
            sp.harga,
            COUNT(spd.id_detail) as jumlah_pemakaian,
            SUM(spd.qty) as total_qty,
            SUM(spd.subtotal) as total_biaya
        FROM sparepart sp
        LEFT JOIN sparepart_detail spd ON sp.id_sparepart = spd.id_sparepart
        LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
        WHERE YEAR(pp.tgl_selesai) = '$current_year'
        AND pp.status = 'Selesai'
        AND spd.id_detail IS NOT NULL
        $search_condition
        $bidang_condition
        GROUP BY sp.id_sparepart
        ORDER BY total_qty DESC";
    } else {
        $sql = "SELECT 
            sp.id_sparepart,
            sp.kode_sparepart,
            sp.nama_sparepart,
            sp.harga,
            COUNT(spd.id_detail) as jumlah_pemakaian,
            SUM(spd.qty) as total_qty,
            SUM(spd.subtotal) as total_biaya
        FROM sparepart sp
        LEFT JOIN sparepart_detail spd ON sp.id_sparepart = spd.id_sparepart
        LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
        WHERE MONTH(pp.tgl_selesai) = '$current_month' 
        AND YEAR(pp.tgl_selesai) = '$current_year'
        AND pp.status = 'Selesai'
        AND spd.id_detail IS NOT NULL
        $search_condition
        $bidang_condition
        GROUP BY sp.id_sparepart
        ORDER BY total_qty DESC";
    }
} else {
    if ($period_type == 'tahun') {
        $sql = "SELECT 
            mj.id_jasa,
            mj.kode_pekerjaan,
            mj.nama_pekerjaan,
            mj.harga,
            COUNT(pd.id_detail) as jumlah_pemakaian,
            SUM(pd.qty) as total_qty,
            SUM(pd.subtotal) as total_biaya
        FROM master_jasa mj
        LEFT JOIN perbaikan_detail pd ON mj.id_jasa = pd.id_jasa
        LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
        WHERE YEAR(pp.tgl_selesai) = '$current_year'
        AND pp.status = 'Selesai'
        AND pd.id_detail IS NOT NULL
        $search_condition
        $bidang_condition
        GROUP BY mj.id_jasa
        ORDER BY total_qty DESC";
    } else {
        $sql = "SELECT 
            mj.id_jasa,
            mj.kode_pekerjaan,
            mj.nama_pekerjaan,
            mj.harga,
            COUNT(pd.id_detail) as jumlah_pemakaian,
            SUM(pd.qty) as total_qty,
            SUM(pd.subtotal) as total_biaya
        FROM master_jasa mj
        LEFT JOIN perbaikan_detail pd ON mj.id_jasa = pd.id_jasa
        LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
        WHERE MONTH(pp.tgl_selesai) = '$current_month' 
        AND YEAR(pp.tgl_selesai) = '$current_year'
        AND pp.status = 'Selesai'
        AND pd.id_detail IS NOT NULL
        $search_condition
        $bidang_condition
        GROUP BY mj.id_jasa
        ORDER BY total_qty DESC";
    }
}
    
    $result = mysqli_query($connection, $sql);
    
    $bulan_array = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
        '04' => 'April', '05' => 'Mei', '06' => 'Juni',
        '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
        '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    $periode_text = $period_type == 'tahun' ? "Tahun $current_year" : $bulan_array[$current_month] . " $current_year";
    
    if ($export_type == 'formatted') {
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head>';
        echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
        echo '<style>';
        echo 'table { border-collapse: collapse; width: 100%; }';
        echo 'th, td { border: 1px solid #000; padding: 8px; text-align: left; }';
        echo 'th { background-color: #4472C4; color: white; font-weight: bold; text-align: center; }';
        echo '.header-title { font-size: 18px; font-weight: bold; color: #1e40af; text-align: center; margin-bottom: 5px; }';
        echo '.header-info { font-size: 12px; color: #666; text-align: center; margin-bottom: 10px; }';
        echo '.text-right { text-align: right; }';
        echo '.text-center { text-align: center; }';
        echo '.total-row { background-color: #FFD966; font-weight: bold; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        
        echo '<div class="header-title">LAPORAN ANALISA RIWAYAT PERBAIKAN</div>';
        echo '<div class="header-info">';
        echo '<strong>Jenis:</strong> ' . ($view_type == 'sparepart' ? 'SPAREPART' : 'JASA') . '<br>';
        echo '<strong>Periode:</strong> ' . $periode_text . '<br>';
        if (!empty($selected_bidang)) {
            echo '<strong>Bidang:</strong> ' . htmlspecialchars($selected_bidang) . '<br>';
        }
        echo '<strong>Tanggal Cetak:</strong> ' . date('d/m/Y H:i:s') . '<br>';
        echo '</div>';
        echo '<br>';
        
        echo '<table>';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 50px;">No</th>';
        echo '<th style="width: 120px;">Kode</th>';
        echo '<th style="width: 200px;">Nama ' . ($view_type == 'sparepart' ? 'Sparepart' : 'Jasa') . '</th>';
        echo '<th style="width: 120px;">Harga Satuan</th>';
        echo '<th style="width: 80px;">Transaksi</th>';
        echo '<th style="width: 80px;">Total Qty</th>';
        echo '<th style="width: 130px;">Total Biaya</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $no = 1;
        $grand_total = 0;
        $grand_qty = 0;
        $grand_transaksi = 0;
        
        while ($row = mysqli_fetch_assoc($result)) {
            $grand_total += $row['total_biaya'];
            $grand_qty += $row['total_qty'];
            $grand_transaksi += $row['jumlah_pemakaian'];
            
            echo '<tr>';
            echo '<td class="text-center">' . $no++ . '</td>';
            echo '<td>' . htmlspecialchars($row[$view_type == 'sparepart' ? 'kode_sparepart' : 'kode_pekerjaan']) . '</td>';
            echo '<td>' . htmlspecialchars($row[$view_type == 'sparepart' ? 'nama_sparepart' : 'nama_pekerjaan']) . '</td>';
            echo '<td class="text-right">' . number_format($row['harga'], 0, ',', '.') . '</td>';
            echo '<td class="text-center">' . $row['jumlah_pemakaian'] . '</td>';
            echo '<td class="text-center">' . $row['total_qty'] . '</td>';
            echo '<td class="text-right">' . number_format($row['total_biaya'], 0, ',', '.') . '</td>';
            echo '</tr>';
        }
        
        echo '<tr class="total-row">';
        echo '<td colspan="4" class="text-right">TOTAL KESELURUHAN:</td>';
        echo '<td class="text-center">' . $grand_transaksi . '</td>';
        echo '<td class="text-center">' . $grand_qty . '</td>';
        echo '<td class="text-right">' . number_format($grand_total, 0, ',', '.') . '</td>';
        echo '</tr>';
        
        echo '</tbody>';
        echo '</table>';
        echo '</body>';
        echo '</html>';
        
    } else {
        // Export polosan
        echo '<html>';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /></head>';
        echo '<body>';
        echo '<h3>LAPORAN ANALISA RIWAYAT PERBAIKAN</h3>';
        echo '<p>Jenis: ' . ($view_type == 'sparepart' ? 'SPAREPART' : 'JASA') . '</p>';
        echo '<p>Periode: ' . $periode_text . '</p>';
        if (!empty($selected_bidang)) {
            echo '<p>Bidang: ' . htmlspecialchars($selected_bidang) . '</p>';
        }
        echo '<br>';
        
        echo '<table border="1">';
        echo '<tr>';
        echo '<th>No</th><th>Kode</th><th>Nama</th><th>Harga</th><th>Transaksi</th><th>Qty</th><th>Total</th>';
        echo '</tr>';
        
        $no = 1;
        $grand_total = 0;
        mysqli_data_seek($result, 0);
        while ($row = mysqli_fetch_assoc($result)) {
            $grand_total += $row['total_biaya'];
            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            echo '<td>' . htmlspecialchars($row[$view_type == 'sparepart' ? 'kode_sparepart' : 'kode_pekerjaan']) . '</td>';
            echo '<td>' . htmlspecialchars($row[$view_type == 'sparepart' ? 'nama_sparepart' : 'nama_pekerjaan']) . '</td>';
            echo '<td>' . number_format($row['harga'], 0, ',', '.') . '</td>';
            echo '<td>' . $row['jumlah_pemakaian'] . '</td>';
            echo '<td>' . $row['total_qty'] . '</td>';
            echo '<td>' . number_format($row['total_biaya'], 0, ',', '.') . '</td>';
            echo '</tr>';
        }
        
        echo '<tr>';
        echo '<td colspan="6"><strong>TOTAL</strong></td>';
        echo '<td><strong>' . number_format($grand_total, 0, ',', '.') . '</strong></td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</body>';
        echo '</html>';
    }
    
    exit;
}

// Query untuk analisa sparepart
$search_condition_sparepart = "";
$search_condition_jasa = "";
if (!empty($search_query)) {
    $search_query_escaped = mysqli_real_escape_string($connection, $search_query);
    $search_condition_sparepart = " AND (sp.nama_sparepart LIKE '%$search_query_escaped%' OR sp.kode_sparepart LIKE '%$search_query_escaped%')";
    $search_condition_jasa = " AND (mj.nama_pekerjaan LIKE '%$search_query_escaped%' OR mj.kode_pekerjaan LIKE '%$search_query_escaped%')";
}

/// NEW: Filter bidang
$bidang_condition = "";
if (!empty($selected_bidang)) {
    $selected_bidang_escaped = mysqli_real_escape_string($connection, $selected_bidang);
    $bidang_condition = " AND k.bidang = '$selected_bidang_escaped'";
}

if ($period_type == 'tahun') {
    $sql_sparepart = "SELECT 
        sp.id_sparepart,
        sp.kode_sparepart,
        sp.nama_sparepart,
        sp.harga,
        COUNT(spd.id_detail) as jumlah_pemakaian,
        SUM(spd.qty) as total_qty,
        SUM(spd.subtotal) as total_biaya
    FROM sparepart sp
    LEFT JOIN sparepart_detail spd ON sp.id_sparepart = spd.id_sparepart
    LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
    LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
    WHERE YEAR(pp.tgl_selesai) = '$current_year'
    AND pp.status = 'Selesai'
    AND spd.id_detail IS NOT NULL
    $search_condition_sparepart
    $bidang_condition
    GROUP BY sp.id_sparepart
    ORDER BY total_qty DESC";

    $sql_jasa = "SELECT 
        mj.id_jasa,
        mj.kode_pekerjaan,
        mj.nama_pekerjaan,
        mj.harga,
        COUNT(pd.id_detail) as jumlah_pemakaian,
        SUM(pd.qty) as total_qty,
        SUM(pd.subtotal) as total_biaya
    FROM master_jasa mj
    LEFT JOIN perbaikan_detail pd ON mj.id_jasa = pd.id_jasa
    LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
    LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
    WHERE YEAR(pp.tgl_selesai) = '$current_year'
    AND pp.status = 'Selesai'
    AND pd.id_detail IS NOT NULL
    $search_condition_jasa
    $bidang_condition
    GROUP BY mj.id_jasa
    ORDER BY total_qty DESC";
} else {
    $sql_sparepart = "SELECT 
        sp.id_sparepart,
        sp.kode_sparepart,
        sp.nama_sparepart,
        sp.harga,
        COUNT(spd.id_detail) as jumlah_pemakaian,
        SUM(spd.qty) as total_qty,
        SUM(spd.subtotal) as total_biaya
    FROM sparepart sp
    LEFT JOIN sparepart_detail spd ON sp.id_sparepart = spd.id_sparepart
    LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
    LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
    WHERE MONTH(pp.tgl_selesai) = '$current_month' 
    AND YEAR(pp.tgl_selesai) = '$current_year'
    AND pp.status = 'Selesai'
    AND spd.id_detail IS NOT NULL
    $search_condition_sparepart
    $bidang_condition
    GROUP BY sp.id_sparepart
    ORDER BY total_qty DESC";

    $sql_jasa = "SELECT 
        mj.id_jasa,
        mj.kode_pekerjaan,
        mj.nama_pekerjaan,
        mj.harga,
        COUNT(pd.id_detail) as jumlah_pemakaian,
        SUM(pd.qty) as total_qty,
        SUM(pd.subtotal) as total_biaya
    FROM master_jasa mj
    LEFT JOIN perbaikan_detail pd ON mj.id_jasa = pd.id_jasa
    LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
    LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
    WHERE MONTH(pp.tgl_selesai) = '$current_month' 
    AND YEAR(pp.tgl_selesai) = '$current_year'
    AND pp.status = 'Selesai'
    AND pd.id_detail IS NOT NULL
    $search_condition_jasa
    $bidang_condition
    GROUP BY mj.id_jasa
    ORDER BY total_qty DESC";
}
$result_sparepart = mysqli_query($connection, $sql_sparepart);
$result_jasa = mysqli_query($connection, $sql_jasa);

function getChartDataQty($connection, $id, $type, $period_type, $current_month, $current_year, $selected_bidang = '') {
    $data = array();
    
    // Filter bidang
    $bidang_condition = "";
    if (!empty($selected_bidang)) {
        $selected_bidang_escaped = mysqli_real_escape_string($connection, $selected_bidang);
        $bidang_condition = " AND k.bidang = '$selected_bidang_escaped'";
    }
    
    if ($period_type == 'tahun') {
        for ($m = 1; $m <= 12; $m++) {
            $month = str_pad($m, 2, '0', STR_PAD_LEFT);
            
            if ($type == 'sparepart') {
                $sql = "SELECT COALESCE(SUM(spd.qty), 0) as total
                        FROM sparepart_detail spd
                        LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE spd.id_sparepart = '$id'
                        AND MONTH(pp.tgl_selesai) = '$month'
                        AND YEAR(pp.tgl_selesai) = '$current_year'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            } else {
                $sql = "SELECT COALESCE(SUM(pd.qty), 0) as total
                        FROM perbaikan_detail pd
                        LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE pd.id_jasa = '$id'
                        AND MONTH(pp.tgl_selesai) = '$month'
                        AND YEAR(pp.tgl_selesai) = '$current_year'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            }
            
            $result = mysqli_query($connection, $sql);
            $row = mysqli_fetch_assoc($result);
            $data[] = floatval($row['total']);
        }
    } else {
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $current_month, $current_year);
        
        for ($d = 1; $d <= $days_in_month; $d++) {
            $day = str_pad($d, 2, '0', STR_PAD_LEFT);
            
            if ($type == 'sparepart') {
                $sql = "SELECT COALESCE(SUM(spd.qty), 0) as total
                        FROM sparepart_detail spd
                        LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE spd.id_sparepart = '$id'
                        AND DATE(pp.tgl_selesai) = '$current_year-$current_month-$day'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            } else {
                $sql = "SELECT COALESCE(SUM(pd.qty), 0) as total
                        FROM perbaikan_detail pd
                        LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE pd.id_jasa = '$id'
                        AND DATE(pp.tgl_selesai) = '$current_year-$current_month-$day'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            }
            
            $result = mysqli_query($connection, $sql);
            $row = mysqli_fetch_assoc($result);
            $data[] = floatval($row['total']);
        }
    }
    
    return $data;
}
function getOverallChartData($connection, $type, $period_type, $current_month, $current_year, $selected_bidang = '') {
    $data = array();
    
    // Filter bidang
    $bidang_condition = "";
    if (!empty($selected_bidang)) {
        $selected_bidang_escaped = mysqli_real_escape_string($connection, $selected_bidang);
        $bidang_condition = " AND k.bidang = '$selected_bidang_escaped'";
    }
    
    if ($period_type == 'tahun') {
        for ($m = 1; $m <= 12; $m++) {
            $month = str_pad($m, 2, '0', STR_PAD_LEFT);
            
            if ($type == 'sparepart') {
                $sql = "SELECT COALESCE(SUM(spd.subtotal), 0) as total
                        FROM sparepart_detail spd
                        LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE MONTH(pp.tgl_selesai) = '$month'
                        AND YEAR(pp.tgl_selesai) = '$current_year'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            } else {
                $sql = "SELECT COALESCE(SUM(pd.subtotal), 0) as total
                        FROM perbaikan_detail pd
                        LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE MONTH(pp.tgl_selesai) = '$month'
                        AND YEAR(pp.tgl_selesai) = '$current_year'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            }
            
            $result = mysqli_query($connection, $sql);
            $row = mysqli_fetch_assoc($result);
            $data[] = floatval($row['total']);
        }
    } else {
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $current_month, $current_year);
        
        for ($d = 1; $d <= $days_in_month; $d++) {
            $day = str_pad($d, 2, '0', STR_PAD_LEFT);
            
            if ($type == 'sparepart') {
                $sql = "SELECT COALESCE(SUM(spd.subtotal), 0) as total
                        FROM sparepart_detail spd
                        LEFT JOIN permintaan_perbaikan pp ON spd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE DATE(pp.tgl_selesai) = '$current_year-$current_month-$day'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            } else {
                $sql = "SELECT COALESCE(SUM(pd.subtotal), 0) as total
                        FROM perbaikan_detail pd
                        LEFT JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
                        LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                        WHERE DATE(pp.tgl_selesai) = '$current_year-$current_month-$day'
                        AND pp.status = 'Selesai'
                        $bidang_condition";
            }
            
            $result = mysqli_query($connection, $sql);
            $row = mysqli_fetch_assoc($result);
            $data[] = floatval($row['total']);
        }
    }
    
    return $data;
}

// Get overall chart data dengan filter bidang
$overall_chart_data = getOverallChartData($connection, $view_type, $period_type, $current_month, $current_year, $selected_bidang);

// Hitung total keseluruhan
$total_sparepart = 0;
$total_jasa = 0;

$temp_result_sparepart = mysqli_query($connection, $sql_sparepart);
while ($row = mysqli_fetch_assoc($temp_result_sparepart)) {
    $total_sparepart += $row['total_biaya'];
}

$temp_result_jasa = mysqli_query($connection, $sql_jasa);
while ($row = mysqli_fetch_assoc($temp_result_jasa)) {
    $total_jasa += $row['total_biaya'];
}

$grand_total = $total_sparepart + $total_jasa;

$bulan_array = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
    '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisa Riwayat - Sistem Bengkel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:rgb(185, 224, 204);
            padding: 20px;
            padding-left: 280px;
            transition: padding 0.3s;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
        }

        .page-header {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #1e40af;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .page-header p {
            color: #64748b;
            font-size: 14px;
        }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group select,
        .form-group input {
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            width: 100%;
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #1e40af;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding-left: 40px;
            width: 100%;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            bottom: 13px;
            color: #94a3b8;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }

        .btn-secondary {
            background: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .btn-success {
            background:rgb(9, 120, 83);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-info {
            background: linear-gradient(135deg,rgb(105, 92, 15),rgb(205, 174, 38));
            color: white;
        }

        .btn-info:hover {
            background: #0284c7;
        }

        .view-toggle {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .view-btn {
            padding: 12px 24px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #334155;
            flex: 1;
            min-width: 200px;
            justify-content: center;
        }

        .view-btn.active {
            background: #1e40af;
            color: white;
            border-color: #1e40af;
        }

        .view-btn:hover:not(.active) {
            background: #f8fafc;
        }

        .export-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .export-buttons .btn {
            flex: 1;
            min-width: 200px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #1e40af;
        }

        .stat-card.success {
            border-left-color: #10b981;
        }

        .stat-card.warning {
            border-left-color: #f59e0b;
        }

        .stat-card.danger {
            border-left-color: #ef4444;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stat-value {
            font-size: clamp(20px, 4vw, 28px);
            font-weight: bold;
            color: #1e293b;
            word-break: break-word;
        }

        .overall-chart-container {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }

        .overall-chart-container h2 {
            font-size: clamp(16px, 3vw, 18px);
            color: #1e293b;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .overall-chart-wrapper {
            height: 350px;
            position: relative;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .table-header h2 {
            font-size: clamp(16px, 3vw, 18px);
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        thead {
            background: #f8fafc;
        }

        th {
            padding: 16px 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        td {
            padding: 16px 12px;
            font-size: 14px;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-primary {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-info {
            background: #e0e7ff;
            color: #3730a3;
        }

        .text-right {
            text-align: right;
        }

        .text-bold {
            font-weight: 700;
        }

        .total-row {
            background: #f1f5f9;
            font-weight: 700;
            font-size: 15px;
        }

        .total-row td {
            padding: 20px 12px;
            border-top: 2px solid #cbd5e1;
        }

        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #94a3b8;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .chart-cell {
            width: 280px;
            min-width: 280px;
            padding: 8px !important;
        }

        .chart-container {
            width: 100%;
            height: 60px;
            position: relative;
            cursor: pointer;
            transition: all 0.3s;
        }

        .chart-container:hover {
            transform: scale(1.02);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            animation: fadeIn 0.3s;
            overflow-y: auto;
            padding: 20px;
        }

        .modal.active {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 16px;
            width: 90%;
            max-width: 1200px;
            max-height: 90vh;
            overflow: auto;
            position: relative;
            animation: slideIn 0.3s;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
            gap: 15px;
        }

        .modal-header h3 {
            font-size: clamp(16px, 3vw, 20px);
            color: #1e293b;
            font-weight: 700;
            flex: 1;
        }

        .close-modal {
            background: #ef4444;
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            min-width: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .close-modal:hover {
            background: #dc2626;
            transform: rotate(90deg);
        }

        .modal-chart-wrapper {
            height: 500px;
            position: relative;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 1024px) {
            body {
                padding-left: 20px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 12px;
            }
            
            .export-buttons {
                flex-direction: column;
            }
            
            .export-buttons .btn {
                min-width: auto;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include "navbar.php"; ?>

    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-chart-bar"></i> Analisa Riwayat Perbaikan
                <?php if (!empty($selected_bidang)): ?>
                    <span class="badge badge-info">
                        <i class="fas fa-filter"></i> <?= htmlspecialchars($selected_bidang) ?>
                    </span>
                <?php endif; ?>
            </h1>
            <p>Analisa penggunaan sparepart dan jasa dengan visualisasi grafik interaktif</p>
        </div>

        <div class="filter-section">
            <div class="filter-grid">
                <!-- NEW: Filter Bidang -->
                <div class="form-group">
                    <label><i class="fas fa-building"></i> Bidang</label>
                    <select id="bidangSelect" onchange="updateData()">
                        <option value="">Semua Bidang</option>
                        <?php 
                        mysqli_data_seek($result_bidang, 0);
                        while ($row_bidang = mysqli_fetch_assoc($result_bidang)): 
                        ?>
                            <option value="<?= htmlspecialchars($row_bidang['bidang']) ?>" 
                                    <?= $selected_bidang == $row_bidang['bidang'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($row_bidang['bidang']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Periode</label>
                    <select id="periodType" onchange="updatePeriod()">
                        <option value="bulan" <?= $period_type == 'bulan' ? 'selected' : '' ?>>Per Bulan</option>
                        <option value="tahun" <?= $period_type == 'tahun' ? 'selected' : '' ?>>Per Tahun</option>
                    </select>
                </div>
                
                <div class="form-group" id="monthSelect" style="<?= $period_type == 'tahun' ? 'display:none' : '' ?>">
                    <label><i class="fas fa-calendar"></i> Bulan</label>
                    <select id="bulanSelect" onchange="updateData()">
                        <?php foreach ($bulan_array as $key => $value): ?>
                            <option value="<?= $key ?>" <?= $current_month == $key ? 'selected' : '' ?>>
                                <?= $value ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> Tahun</label>
                    <select id="tahunSelect" onchange="updateData()">
                        <?php for ($year = date('Y'); $year >= 2020; $year--): ?>
                            <option value="<?= $year ?>" <?= $current_year == $year ? 'selected' : '' ?>>
                                <?= $year ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-search"></i> Cari Data</label>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Cari sparepart atau jasa..." 
                               value="<?= htmlspecialchars($search_query) ?>" onkeyup="handleSearch(event)">
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                        <i class="fas fa-times"></i> Reset
                    </button>
                </div>
            </div>
        </div>

        <div class="view-toggle">
            <a href="#" onclick="changeView('sparepart'); return false;" 
               class="view-btn <?= $view_type == 'sparepart' ? 'active' : '' ?>">
                <i class="fas fa-cogs"></i> Analisa Sparepart
            </a>
            <a href="#" onclick="changeView('jasa'); return false;" 
               class="view-btn <?= $view_type == 'jasa' ? 'active' : '' ?>">
                <i class="fas fa-wrench"></i> Analisa Jasa
            </a>
        </div>

        <div class="export-buttons">
            <button onclick="exportExcel('plain')" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export Excel Polosan
            </button>
            <button onclick="exportExcel('formatted')" class="btn btn-info">
                <i class="fas fa-file-excel"></i> Export Excel Format
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">
                    <i class="fas fa-calendar-check"></i> Periode
                </div>
                <div class="stat-value">
                    <?= $period_type == 'tahun' ? "Tahun $current_year" : $bulan_array[$current_month] . " $current_year" ?>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">
                    <i class="fas fa-cogs"></i> Total Biaya Sparepart
                </div>
                <div class="stat-value">
                    Rp <?= number_format($total_sparepart, 0, ',', '.') ?>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">
                    <i class="fas fa-wrench"></i> Total Biaya Jasa
                </div>
                <div class="stat-value">
                    Rp <?= number_format($total_jasa, 0, ',', '.') ?>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">
                    <i class="fas fa-calculator"></i> Total Keseluruhan
                </div>
                <div class="stat-value">
                    Rp <?= number_format($grand_total, 0, ',', '.') ?>
                </div>
            </div>
        </div>

        <div class="overall-chart-container">
            <h2>
                <i class="fas fa-chart-line"></i> 
                Grafik Pengeluaran Keseluruhan <?= $view_type == 'sparepart' ? 'Sparepart' : 'Jasa' ?> 
                - <?= $period_type == 'tahun' ? "Tahun $current_year" : $bulan_array[$current_month] . " $current_year" ?>
            </h2>
            <div class="overall-chart-wrapper">
                <canvas id="overallChart"></canvas>
            </div>
        </div>

        <?php if ($view_type == 'sparepart'): ?>
            <div class="table-container">
                <div class="table-header">
                    <h2><i class="fas fa-cogs"></i> Detail Penggunaan Sparepart dengan Tren Qty</h2>
                </div>
                <?php if (mysqli_num_rows($result_sparepart) > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Kode</th>
                                    <th>Nama Sparepart</th>
                                    <th class="text-right">Harga</th>
                                    <th class="text-right">Transaksi</th>
                                    <th class="text-right">Qty</th>
                                    <th class="text-right">Total</th>
                                    <th>Tren Qty (Klik untuk Zoom)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = 1;
                                $subtotal = 0;
                                while ($row = mysqli_fetch_assoc($result_sparepart)): 
                                    $subtotal += $row['total_biaya'];
                                    $chart_data = getChartDataQty($connection, $row['id_sparepart'], 'sparepart', $period_type, $current_month, $current_year, $selected_bidang);
                                    $chart_id = 'chart_sp_' . $row['id_sparepart'];
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <span class="badge badge-primary">
                                                <?= htmlspecialchars($row['kode_sparepart']) ?>
                                            </span>
                                        </td>
                                        <td class="text-bold"><?= htmlspecialchars($row['nama_sparepart']) ?></td>
                                        <td class="text-right">
                                            Rp <?= number_format($row['harga'], 0, ',', '.') ?>
                                        </td>
                                        <td class="text-right">
                                            <span class="badge badge-success">
                                                <?= $row['jumlah_pemakaian'] ?>x
                                            </span>
                                        </td>
                                        <td class="text-right text-bold">
                                            <?= $row['total_qty'] ?> pcs
                                        </td>
                                        <td class="text-right text-bold">
                                            Rp <?= number_format($row['total_biaya'], 0, ',', '.') ?>
                                        </td>
                                        <td class="chart-cell">
                                            <div class="chart-container" onclick="openModal('<?= $chart_id ?>', '<?= addslashes($row['nama_sparepart']) ?>', <?= json_encode($chart_data) ?>)">
                                                <canvas id="<?= $chart_id ?>"></canvas>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td colspan="7" class="text-right">
                                        <i class="fas fa-calculator"></i> TOTAL KESELURUHAN:
                                        <strong>Rp <?= number_format($subtotal, 0, ',', '.') ?></strong>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-inbox"></i>
                        <p>Tidak ada data sparepart untuk periode ini</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="table-container">
                <div class="table-header">
                    <h2><i class="fas fa-wrench"></i> Detail Penggunaan Jasa dengan Tren Qty</h2>
                </div>
                <?php if (mysqli_num_rows($result_jasa) > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Kode</th>
                                    <th>Nama Jasa</th>
                                    <th class="text-right">Harga</th>
                                    <th class="text-right">Transaksi</th>
                                    <th class="text-right">Qty</th>
                                    <th class="text-right">Total</th>
                                    <th>Tren Qty (Klik untuk Zoom)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = 1;
                                $subtotal = 0;
                                while ($row = mysqli_fetch_assoc($result_jasa)): 
                                    $subtotal += $row['total_biaya'];
                                    $chart_data = getChartDataQty($connection, $row['id_jasa'], 'jasa', $period_type, $current_month, $current_year, $selected_bidang);
                                    $chart_id = 'chart_js_' . $row['id_jasa'];
                                ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <span class="badge badge-primary">
                                                <?= htmlspecialchars($row['kode_pekerjaan']) ?>
                                            </span>
                                        </td>
                                        <td class="text-bold"><?= htmlspecialchars($row['nama_pekerjaan']) ?></td>
                                        <td class="text-right">
                                            Rp <?= number_format($row['harga'], 0, ',', '.') ?>
                                        </td>
                                        <td class="text-right">
                                            <span class="badge badge-success">
                                                <?= $row['jumlah_pemakaian'] ?>x
                                            </span>
                                        </td>
                                        <td class="text-right text-bold">
                                            <?= $row['total_qty'] ?> unit
                                        </td>
                                        <td class="text-right text-bold">
                                            Rp <?= number_format($row['total_biaya'], 0, ',', '.') ?>
                                        </td>
                                        <td class="chart-cell">
                                            <div class="chart-container" onclick="openModal('<?= $chart_id ?>', '<?= addslashes($row['nama_pekerjaan']) ?>', <?= json_encode($chart_data) ?>)">
                                                <canvas id="<?= $chart_id ?>"></canvas>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td colspan="7" class="text-right">
                                        <i class="fas fa-calculator"></i> TOTAL KESELURUHAN:
                                        <strong>Rp <?= number_format($subtotal, 0, ',', '.') ?></strong>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-inbox"></i>
                        <p>Tidak ada data jasa untuk periode ini</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="chartModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"></h3>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-chart-wrapper">
                <canvas id="modalChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        let searchTimeout;
        let modalChartInstance = null;
        const periodType = '<?= $period_type ?>';
        const currentMonth = '<?= $current_month ?>';
        const currentYear = '<?= $current_year ?>';
        const viewType = '<?= $view_type ?>';
        const searchQuery = '<?= addslashes($search_query) ?>';
        const selectedBidang = '<?= addslashes($selected_bidang) ?>';

        function exportExcel(type) {
            const period = document.getElementById('periodType').value;
            const bulan = document.getElementById('bulanSelect').value;
            const tahun = document.getElementById('tahunSelect').value;
            const view = viewType;
            const search = document.getElementById('searchInput').value;
            const bidang = document.getElementById('bidangSelect').value;
            
            let url = `?export=${type}&period=${period}&tahun=${tahun}&view=${view}`;
            if (period === 'bulan') {
                url += `&bulan=${bulan}`;
            }
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }
            if (bidang) {
                url += `&bidang=${encodeURIComponent(bidang)}`;
            }
            
            window.location.href = url;
        }

        function changeView(type) {
            const period = document.getElementById('periodType').value;
            const bulan = document.getElementById('bulanSelect').value;
            const tahun = document.getElementById('tahunSelect').value;
            const search = document.getElementById('searchInput').value;
            const bidang = document.getElementById('bidangSelect').value;
            
            let url = `?view=${type}&period=${period}&tahun=${tahun}`;
            if (period === 'bulan') {
                url += `&bulan=${bulan}`;
            }
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }
            if (bidang) {
                url += `&bidang=${encodeURIComponent(bidang)}`;
            }
            
            window.location.href = url;
        }

        function updatePeriod() {
            const period = document.getElementById('periodType').value;
            const monthSelect = document.getElementById('monthSelect');
            
            if (period === 'tahun') {
                monthSelect.style.display = 'none';
            } else {
                monthSelect.style.display = 'block';
            }
            
            updateData();
        }

        function updateData() {
            const period = document.getElementById('periodType').value;
            const bulan = document.getElementById('bulanSelect').value;
            const tahun = document.getElementById('tahunSelect').value;
            const search = document.getElementById('searchInput').value;
            const bidang = document.getElementById('bidangSelect').value;
            
            let url = `?view=${viewType}&period=${period}&tahun=${tahun}`;
            if (period === 'bulan') {
                url += `&bulan=${bulan}`;
            }
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }
            if (bidang) {
                url += `&bidang=${encodeURIComponent(bidang)}`;
            }
            
            window.location.href = url;
        }

        function handleSearch(event) {
            if (event.key === 'Enter') {
                updateData();
            } else {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    updateData();
                }, 800);
            }
        }

        function clearSearch() {
            document.getElementById('searchInput').value = '';
            document.getElementById('bidangSelect').value = '';
            updateData();
        }

        function openModal(chartId, name, data) {
            const modal = document.getElementById('chartModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalCanvas = document.getElementById('modalChart');
            
            modalTitle.innerHTML = `<i class="fas fa-chart-line"></i> Tren Qty - ${name}`;
            modal.classList.add('active');
            
            if (modalChartInstance) {
                modalChartInstance.destroy();
            }
            
            const labels = periodType === 'tahun' 
                ? ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des']
                : Array.from({length: data.length}, (_, i) => (i + 1).toString());
            
            const ctx = modalCanvas.getContext('2d');
            modalChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Quantity',
                        data: data,
                        borderColor: '#1e40af',
                        backgroundColor: 'rgba(30, 64, 175, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#1e40af',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: { size: 14, weight: 'bold' },
                                padding: 15
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 14, weight: 'bold' },
                            bodyFont: { size: 13 },
                            cornerRadius: 8
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: { size: 12 },
                                callback: function(value) {
                                    return value + ' pcs';
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 12 }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        function closeModal() {
            const modal = document.getElementById('chartModal');
            modal.classList.remove('active');
            
            if (modalChartInstance) {
                modalChartInstance.destroy();
                modalChartInstance = null;
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('chartModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const overallData = <?= json_encode($overall_chart_data) ?>;
            const labels = periodType === 'tahun' 
                ? ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des']
                : Array.from({length: overallData.length}, (_, i) => (i + 1).toString());
            
            const ctx = document.getElementById('overallChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total Biaya (Rp)',
                        data: overallData,
                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 2,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: { size: 14, weight: 'bold' },
                                padding: 15
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 14, weight: 'bold' },
                            bodyFont: { size: 13 },
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: { size: 12 },
                                callback: function(value) {
                                    return 'Rp ' + value.toLocaleString('id-ID');
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 12 }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });

            <?php if ($view_type == 'sparepart'): ?>
                <?php mysqli_data_seek($result_sparepart, 0); ?>
                <?php while ($row = mysqli_fetch_assoc($result_sparepart)): ?>
                    <?php 
                    $chart_data = getChartDataQty($connection, $row['id_sparepart'], 'sparepart', $period_type, $current_month, $current_year, $selected_bidang);
                    $chart_id = 'chart_sp_' . $row['id_sparepart'];
                    ?>
                    createMiniChart('<?= $chart_id ?>', <?= json_encode($chart_data) ?>);
                <?php endwhile; ?>
            <?php else: ?>
                <?php mysqli_data_seek($result_jasa, 0); ?>
                <?php while ($row = mysqli_fetch_assoc($result_jasa)): ?>
                    <?php 
                    $chart_data = getChartDataQty($connection, $row['id_jasa'], 'jasa', $period_type, $current_month, $current_year, $selected_bidang);
                    $chart_id = 'chart_js_' . $row['id_jasa'];
                    ?>
                    createMiniChart('<?= $chart_id ?>', <?= json_encode($chart_data) ?>);
                <?php endwhile; ?>
            <?php endif; ?>
        });

        function createMiniChart(chartId, data) {
            const canvas = document.getElementById(chartId);
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: Array.from({length: data.length}, (_, i) => ''),
                    datasets: [{
                        data: data,
                        borderColor: '#1e40af',
                        backgroundColor: 'rgba(30, 64, 175, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 0,
                        pointHoverRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    },
                    scales: {
                        y: {
                            display: false,
                            beginAtZero: true
                        },
                        x: {
                            display: false
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
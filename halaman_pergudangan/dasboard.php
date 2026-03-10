<?php
include "../inc/config.php";
requireAuth('pergudangan');

$id_login = $_SESSION['id_login'];
$username = $_SESSION['username'];
$id_user = $_SESSION['id_user'];

$sql_user = "SELECT username, role FROM users WHERE id_user = '$id_user'";
$result_user = mysqli_query($connection, $sql_user);
$user_data = mysqli_fetch_assoc($result_user);
$username = $user_data['username'] ?? 'User';
$role = $user_data['role'] ?? 'Staff';

$tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');
$bulan = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
$periode = isset($_GET['periode']) ? $_GET['periode'] : 'bulan';
$bidang = 'pergudangan'; // Fixed filter untuk pergudangan

function getStatistikUmum($connection, $tahun, $bulan, $periode, $bidang) {
    // Permintaan berdasarkan created_at
    $where_created = $periode == 'bulan' 
        ? "YEAR(pp.created_at) = '$tahun' AND MONTH(pp.created_at) = '$bulan'"
        : "YEAR(pp.created_at) = '$tahun'";
    
    // Biaya selesai berdasarkan tgl_selesai
    $where_selesai = $periode == 'bulan' 
        ? "YEAR(pp.tgl_selesai) = '$tahun' AND MONTH(pp.tgl_selesai) = '$bulan'"
        : "YEAR(pp.tgl_selesai) = '$tahun'";
    
    // Filter bidang jika dipilih
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    
    $sql = "SELECT 
                COALESCE((SELECT COUNT(*) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE $where_created $bidang_filter), 0) as total_permintaan,
                COALESCE((SELECT COUNT(*) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE $where_created AND pp.status = 'Selesai' $bidang_filter), 0) as total_selesai,
                COALESCE((SELECT COUNT(*) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE $where_created AND pp.status = 'Diajukan' $bidang_filter), 0) as total_diajukan,
                COALESCE((SELECT COUNT(*) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE $where_created AND pp.status IN ('Disetujui_KARU_QC', 'Dikembalikan_sa', 'QC') $bidang_filter), 0) as total_proses,
                COALESCE((SELECT SUM(pp.total_perbaikan) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE pp.status = 'Selesai' AND $where_selesai $bidang_filter), 0) as total_biaya,
                COALESCE((SELECT SUM(pp.total_sparepart) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE pp.status = 'Selesai' AND $where_selesai $bidang_filter), 0) as total_sparepart,
                COALESCE((SELECT SUM(pp.grand_total) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE pp.status = 'Selesai' AND $where_selesai $bidang_filter), 0) as grand_total,
                COALESCE((SELECT AVG(DATEDIFF(pp.tgl_selesai, pp.created_at)) FROM permintaan_perbaikan pp LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan WHERE pp.status = 'Selesai' AND $where_created AND pp.tgl_selesai IS NOT NULL $bidang_filter), 0) as avg_completion_days";
    
    $result = mysqli_query($connection, $sql);
    return mysqli_fetch_assoc($result);
}

function getDataPerBulan($connection, $tahun, $bidang) {
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    
    $sql = "SELECT 
                MONTH(pp.created_at) as bulan,
                COALESCE(COUNT(*), 0) as total_permintaan,
                COALESCE(SUM(CASE WHEN pp.status = 'Selesai' THEN 1 ELSE 0 END), 0) as total_selesai,
                COALESCE(SUM(CASE WHEN pp.status = 'Selesai' THEN pp.total_perbaikan ELSE 0 END), 0) as biaya_jasa,
                COALESCE(SUM(CASE WHEN pp.status = 'Selesai' THEN pp.total_sparepart ELSE 0 END), 0) as biaya_sparepart,
                COALESCE(SUM(CASE WHEN pp.status = 'Selesai' THEN pp.grand_total ELSE 0 END), 0) as total_biaya
            FROM permintaan_perbaikan pp
            LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
            WHERE YEAR(pp.created_at) = '$tahun' $bidang_filter
            GROUP BY MONTH(pp.created_at)
            ORDER BY bulan";
    
    $result = mysqli_query($connection, $sql);
    $data = [];
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}

function getPermintaanBelumSelesai($connection, $tahun, $bidang, $is_filtered = false) {
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    
    // Jika tidak difilter: tampilkan tahun ini ATAU tahun yang punya belum selesai
    // Jika difilter: hanya tahun yang dipilih
    if ($is_filtered) {
        $tahun_condition = "YEAR(pp.created_at) = '$tahun'";
        $having_condition = "";
    } else {
        $tahun_condition = "YEAR(pp.created_at) <= '$tahun'";
        // Hanya tampilkan tahun ini ATAU tahun yang masih ada belum selesai
        $having_condition = "HAVING (tahun = '$tahun' OR total_belum_selesai > 0)";
    }
    
    $sql = "SELECT 
                tahun_data.tahun,
                tahun_data.total_permintaan,
                tahun_data.total_selesai,
                tahun_data.total_belum_selesai,
                COALESCE(biaya_selesai.biaya_jasa_selesai, 0) as biaya_jasa_selesai,
                COALESCE(biaya_selesai.biaya_sparepart_selesai, 0) as biaya_sparepart_selesai,
                COALESCE(biaya_selesai.total_biaya_selesai, 0) as total_biaya_selesai,
                COALESCE(biaya_proses.biaya_jasa_proses, 0) as biaya_jasa_proses,
                COALESCE(biaya_proses.biaya_sparepart_proses, 0) as biaya_sparepart_proses,
                COALESCE(biaya_proses.total_biaya_proses, 0) as total_biaya_proses
            FROM (
                SELECT 
                    YEAR(pp.created_at) as tahun,
                    COUNT(*) as total_permintaan,
                    SUM(CASE WHEN pp.status = 'Selesai' THEN 1 ELSE 0 END) as total_selesai,
                    SUM(CASE WHEN pp.status != 'Selesai' THEN 1 ELSE 0 END) as total_belum_selesai
                FROM permintaan_perbaikan pp
                LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                WHERE $tahun_condition $bidang_filter
                GROUP BY YEAR(pp.created_at)
                $having_condition
            ) tahun_data
            LEFT JOIN (
                SELECT 
                    YEAR(pp.created_at) as tahun,
                    SUM(pp.total_perbaikan) as biaya_jasa_selesai,
                    SUM(pp.total_sparepart) as biaya_sparepart_selesai,
                    SUM(pp.grand_total) as total_biaya_selesai
                FROM permintaan_perbaikan pp
                LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                WHERE pp.status = 'Selesai'
                AND $tahun_condition
                $bidang_filter
                GROUP BY YEAR(pp.created_at)
            ) biaya_selesai ON tahun_data.tahun = biaya_selesai.tahun
            LEFT JOIN (
                SELECT 
                    YEAR(pp.created_at) as tahun,
                    SUM(COALESCE(pp.total_perbaikan, 0)) as biaya_jasa_proses,
                    SUM(COALESCE(pp.total_sparepart, 0)) as biaya_sparepart_proses,
                    SUM(COALESCE(pp.grand_total, 0)) as total_biaya_proses
                FROM permintaan_perbaikan pp
                LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
                WHERE pp.status != 'Selesai'
                AND $tahun_condition
                $bidang_filter
                GROUP BY YEAR(pp.created_at)
            ) biaya_proses ON tahun_data.tahun = biaya_proses.tahun
            ORDER BY tahun_data.tahun DESC";
    
    $result = mysqli_query($connection, $sql);
    $data = [];
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}

function getDistribusiStatus($connection, $tahun, $bulan, $periode, $bidang) {
    $where = $periode == 'bulan' 
        ? "YEAR(pp.created_at) = '$tahun' AND MONTH(pp.created_at) = '$bulan'"
        : "YEAR(pp.created_at) = '$tahun'";
    
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    
    $sql = "SELECT pp.status, COUNT(*) as jumlah
            FROM permintaan_perbaikan pp
            LEFT JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
            WHERE $where $bidang_filter
            GROUP BY pp.status";
    
    $result = mysqli_query($connection, $sql);
    $data = [];
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}

function getTopKendaraan($connection, $tahun, $bulan, $periode, $bidang) {
    $where = $periode == 'bulan' 
        ? "YEAR(pp.created_at) = '$tahun' AND MONTH(pp.created_at) = '$bulan'"
        : "YEAR(pp.created_at) = '$tahun'";
    
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    
    $sql = "SELECT 
                k.nopol,
                k.jenis_kendaraan,
                k.bidang,
                COUNT(pp.id_permintaan) as jumlah_perbaikan,
                COALESCE(SUM(CASE WHEN pp.status = 'Selesai' THEN pp.grand_total ELSE 0 END), 0) as total_biaya
            FROM permintaan_perbaikan pp
            JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
            WHERE $where $bidang_filter
            GROUP BY pp.id_kendaraan
            ORDER BY jumlah_perbaikan DESC
            LIMIT 10";
    
    $result = mysqli_query($connection, $sql);
    $data = [];
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}

function getTopSparepart($connection, $tahun, $bulan, $periode, $bidang) {
    $where = $periode == 'bulan' 
        ? "YEAR(pp.created_at) = '$tahun' AND MONTH(pp.created_at) = '$bulan'"
        : "YEAR(pp.created_at) = '$tahun'";
    
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    
    $sql = "SELECT 
                sp.kode_sparepart,
                sp.nama_sparepart,
                COUNT(sd.id_detail) as jumlah,
                COALESCE(SUM(sd.harga), 0) as total_biaya
            FROM sparepart_detail sd
            JOIN permintaan_perbaikan pp ON sd.id_permintaan = pp.id_permintaan
            JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
            JOIN sparepart sp ON sd.id_sparepart = sp.id_sparepart
            WHERE $where $bidang_filter
            GROUP BY sd.id_sparepart
            ORDER BY jumlah DESC
            LIMIT 6";
    
    $result = mysqli_query($connection, $sql);
    $data = [];
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}

function getTopJasa($connection, $tahun, $bulan, $periode, $bidang) {
    $where = $periode == 'bulan' 
        ? "YEAR(pp.created_at) = '$tahun' AND MONTH(pp.created_at) = '$bulan'"
        : "YEAR(pp.created_at) = '$tahun'";
    
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    
    $sql = "SELECT 
                mj.kode_pekerjaan,
                mj.nama_pekerjaan,
                COUNT(pd.id_detail) as jumlah,
                COALESCE(SUM(pd.harga), 0) as total_biaya
            FROM perbaikan_detail pd
            JOIN permintaan_perbaikan pp ON pd.id_permintaan = pp.id_permintaan
            JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
            JOIN master_jasa mj ON pd.id_jasa = mj.id_jasa
            WHERE $where $bidang_filter
            GROUP BY pd.id_jasa
            ORDER BY jumlah DESC
            LIMIT 6";
    
    $result = mysqli_query($connection, $sql);
    $data = [];
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    return $data;
}
// Tambahkan di bagian atas setelah definisi fungsi-fungsi lainnya, sebelum pemanggilan data
function getStatusKendaraanRingkasan($connection, $bidang) {
    $bidang_filter = $bidang != '' ? "AND k.bidang = '$bidang'" : '';
    $analysis_period = 12; // 12 bulan terakhir
    $date_limit = date('Y-m-d H:i:s', strtotime("-$analysis_period months"));
    
    // Hitung total asset berdasarkan filter bidang
    // Jika bidang diisi, hitung kendaraan di bidang tersebut
    // Jika bidang kosong, hitung semua kendaraan dari semua bidang
    $bidang_filter_total = $bidang != '' ? "WHERE k.bidang = '$bidang'" : '';
    
    $query_total = "
        SELECT COUNT(DISTINCT k.nopol) as total_kendaraan
        FROM kendaraan k
        $bidang_filter_total
    ";
    $result_total = mysqli_query($connection, $query_total);
    $total_asset = mysqli_fetch_assoc($result_total)['total_kendaraan'];
    
    // Query untuk mengambil data perbaikan per kendaraan (sama seperti di status_kendaraan.php)
    $query = "
        SELECT 
            k.id_kendaraan,
            k.nopol,
            k.jenis_kendaraan,
            k.bidang,
            pp.tgl_pengajuan,
            pp.tgl_dikembalikan,
            pp.tgl_disetujui_karu_qc,
            pp.created_at,
            r.nama_rekanan
        FROM kendaraan k
        LEFT JOIN permintaan_perbaikan pp ON k.id_kendaraan = pp.id_kendaraan
        LEFT JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
        WHERE pp.tgl_pengajuan IS NOT NULL 
        AND pp.tgl_pengajuan >= '$date_limit'
        $bidang_filter
        ORDER BY k.nopol ASC, pp.created_at DESC
    ";
    
    $result = mysqli_query($connection, $query);
    
    // Kelompokkan data per kendaraan
    $vehicles = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $nopol = $row['nopol'];
        if (!isset($vehicles[$nopol])) {
            $vehicles[$nopol] = [
                'jenis' => $row['jenis_kendaraan'],
                'bidang' => $row['bidang'],
                'repairs' => []
            ];
        }
        $vehicles[$nopol]['repairs'][] = $row;
    }
    
    // Fungsi helper untuk menghitung durasi dalam jam
    function getDurasiJam($start, $end) {
        $diff = strtotime($end) - strtotime($start);
        return max(0, $diff / 3600);
    }
    
    // Hitung statistik per kendaraan (LOGIKA SAMA PERSIS dengan status_kendaraan.php)
    $status_count = ['Stabil' => 0, 'Perlu Perhatian' => 0, 'Sering Rusak' => 0, 'Data Lama' => 0];
    
    foreach ($vehicles as $nopol => $data) {
        $repairs = $data['repairs'];
        $total_repairs = count($repairs);
        
        if ($total_repairs == 0) continue;
        
        $total_uptime = 0;
        $total_downtime = 0;
        $completed_repairs = 0;
        $last_repair_date = null;
        
        for ($i = 0; $i < count($repairs); $i++) {
            $repair = $repairs[$i];
            $tgl_mulai = $repair['tgl_pengajuan'];
            $tgl_selesai = $repair['tgl_dikembalikan'] ?: $repair['tgl_disetujui_karu_qc'];
            
            if ($i == 0) {
                $last_repair_date = $tgl_mulai;
            }
            
            if ($tgl_selesai) {
                // Hitung downtime dalam jam
                $downtime = getDurasiJam($tgl_mulai, $tgl_selesai);
                $total_downtime += $downtime;
                $completed_repairs++;
                
                if (isset($repairs[$i + 1])) {
                    // Hitung uptime ke perbaikan berikutnya
                    $uptime = getDurasiJam($tgl_selesai, $repairs[$i + 1]['tgl_pengajuan']);
                    $total_uptime += $uptime;
                } else {
                    // Uptime sampai sekarang, dengan batasan periode analisis
                    $now = date('Y-m-d H:i:s');
                    $days_since_repair = getDurasiJam($tgl_selesai, $now) / 24;
                    
                    // Batasi maksimal sesuai periode analisis
                    $max_uptime_days = $analysis_period * 30;
                    $uptime_hours = min($days_since_repair * 24, $max_uptime_days * 24);
                    $total_uptime += $uptime_hours;
                }
            }
        }
        
        if ($completed_repairs == 0) {
            continue;
        }
        
        // Hitung rata-rata (dalam jam)
        $avg_uptime = $completed_repairs > 0 ? $total_uptime / $completed_repairs : 0;
        $avg_downtime = $completed_repairs > 0 ? $total_downtime / $completed_repairs : 0;
        
        // Cek apakah data sudah lama
        $days_since_last = (strtotime(date('Y-m-d H:i:s')) - strtotime($last_repair_date)) / (3600 * 24);
        $months_since_last = $days_since_last / 30;
        
        // Threshold disesuaikan dengan periode analisis
        $threshold_data_lama = max(6, $analysis_period / 2);
        
        // LOGIKA PENENTUAN STATUS - SAMA PERSIS dengan status_kendaraan.php
        $status = 'Stabil';
        if ($months_since_last > $threshold_data_lama) {
            $status = 'Data Lama';
        } elseif ($avg_uptime < $avg_downtime * 2) {
            $status = 'Perlu Perhatian';
        }
        if ($avg_downtime > $avg_uptime) {
            $status = 'Sering Rusak';
        }
        
        $status_count[$status]++;
    }
    
    // Hitung total kendaraan yang dianalisis
    $total_analyzed = $status_count['Stabil'] + 
                      $status_count['Perlu Perhatian'] + 
                      $status_count['Sering Rusak'] + 
                      $status_count['Data Lama'];
    
    return [
        'total_kendaraan' => $total_asset, // Total kendaraan sesuai filter bidang
        'total_analyzed' => $total_analyzed, // Total yang dianalisis
        'stabil' => $status_count['Stabil'],
        'perlu_perhatian' => $status_count['Perlu Perhatian'],
        'sering_rusak' => $status_count['Sering Rusak'],
        'data_lama' => $status_count['Data Lama']
    ];
}
// Panggil fungsi ini setelah variabel lainnya
// Deteksi apakah user melakukan filter tahun
$is_filtered = isset($_GET['tahun']) && $_GET['tahun'] != date('Y');

$statusKendaraan = getStatusKendaraanRingkasan($connection, $bidang);
$statistik = getStatistikUmum($connection, $tahun, $bulan, $periode, $bidang);
$dataBulanan = getDataPerBulan($connection, $tahun, $bidang);
$distribusiStatus = getDistribusiStatus($connection, $tahun, $bulan, $periode, $bidang);
$topKendaraan = getTopKendaraan($connection, $tahun, $bulan, $periode, $bidang);
$topSparepart = getTopSparepart($connection, $tahun, $bulan, $periode, $bidang);
$topJasa = getTopJasa($connection, $tahun, $bulan, $periode, $bidang);
$permintaanBelumSelesai = getPermintaanBelumSelesai($connection, $tahun, $bidang, $is_filtered);

$namaBulan = [
    1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
];

function safeNumber($value) {
    return ($value === null || $value === '') ? 0 : (float)$value;
}

function formatRupiah($angka) {
    $angka = safeNumber($angka);
    
    if($angka >= 1000000000) {
        return 'Rp ' . number_format($angka/1000000000, 1, ',', '.') . 'M';
    } elseif($angka >= 1000000) {
        return 'Rp ' . number_format($angka/1000000, 1, ',', '.') . 'Jt';
    } elseif($angka >= 1000) {
        return 'Rp ' . number_format($angka/1000, 0, ',', '.') . 'Rb';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function formatRupiahLengkap($angka) {
    $angka = safeNumber($angka);
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function formatHari($hari) {
    $hari = safeNumber($hari);
    if($hari == 0) {
        return 'Belum ada data';
    }
    
    $hariInt = floor($hari);
    $jam = round(($hari - $hariInt) * 24);
    
    if($hari < 1) {
        return '< 1 hari';
    } elseif($jam > 0 && $hariInt > 0) {
        return $hariInt . ' hari ' . $jam . ' jam';
    } else {
        return $hariInt . ' hari';
    }
}

function getStatusBadge($status) {
    $badges = [
        'Diajukan' => 'bg-yellow-100 text-yellow-800',
        'Disetujui_KARU_QC' => 'bg-blue-100 text-blue-800',
        'Dikerjakan' => 'bg-purple-100 text-purple-800',
        'QC' => 'bg-orange-100 text-orange-800',
        'Selesai' => 'bg-green-100 text-green-800'
    ];
    return $badges[$status] ?? 'bg-gray-100 text-gray-800';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Sistem Perbaikan Armada</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body, html { height: 100vh; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .app-wrapper { display: flex; height: 100vh; overflow: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .dashboard-header { flex-shrink: 0; height: 70px; background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .dashboard-body { flex: 1; overflow: hidden; padding: 12px; display: grid; grid-template-columns: repeat(12, 1fr); grid-template-rows: repeat(6, 1fr); gap: 10px; background:rgb(185, 224, 204); }
        .stat-cards { grid-column: 1 / 13; grid-row: 1 / 2; }
        .chart-trend { grid-column: 1 / 5; grid-row: 2 / 5; }
        .chart-status { grid-column: 5 / 8; grid-row: 2 / 4; }
        .chart-sparepart { grid-column: 8 / 11; grid-row: 2 / 4; }
        .chart-jasa { grid-column: 11 / 13; grid-row: 2 / 4; }
        .table-kendaraan { grid-column: 5 / 13; grid-row: 4 / 5; }
        .table-bulanan { grid-column: 1 / 13; grid-row: 5 / 7; }
        .widget { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 12px; overflow: hidden; display: flex; flex-direction: column; }
        .widget-header { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px; display: flex; align-items: center; flex-shrink: 0; }
        .widget-body { flex: 1; overflow: hidden; position: relative; }
        .chart-container { position: absolute; top: 0; left: 0; right: 0; bottom: 0; }
        .table-scroll { overflow-y: auto; max-height: 100%; }
        .table-scroll::-webkit-scrollbar { width: 4px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 2px; }
        table { width: 100%; font-size: 10px; border-collapse: collapse; }
        th { background: #f9fafb; padding: 6px 8px; text-align: left; font-weight: 600; color: #6b7280; font-size: 9px; text-transform: uppercase; position: sticky; top: 0; z-index: 10; }
        th.align-middle { vertical-align: middle; }
        td { padding: 5px 8px; border-bottom: 1px solid #f3f4f6; }
        tr:hover { background: #f9fafb; }
        select option { color: #000000; }
        @media (max-width: 1023px) {
            .sidebar { position: fixed; left: -240px; transition: left 0.3s; z-index: 1000; }
            .sidebar.show { left: 0; }
            body, html { height: auto; overflow: auto; }
            .dashboard-body { display: flex; flex-direction: column; height: auto; overflow: visible; }
            .widget { min-height: 300px; margin-bottom: 12px; }
            .chart-container { position: relative; height: 250px; }
        }

        /* Responsive Design untuk Mobile */
@media (max-width: 768px) {
    /* Wrapper dan Layout Utama */
    .app-wrapper {
        flex-direction: column;
    }
    
    .main-content {
        width: 100%;
    }
    
    /* Header Dashboard */
    .dashboard-header {
        height: auto !important;
        padding: 12px !important;
        flex-direction: column;
        gap: 12px;
    }
    
    .dashboard-header > div {
        width: 100%;
        justify-content: center;
    }
    
    .dashboard-header h1 {
        font-size: 16px !important;
    }
    
    .dashboard-header form {
        flex-wrap: wrap;
        justify-content: center;
        gap: 8px !important;
    }
    
    .dashboard-header select {
        font-size: 11px !important;
        padding: 6px 8px !important;
        min-width: 100px;
    }
    
    /* Dashboard Body - Grid menjadi Stack */
    .dashboard-body {
        display: flex !important;
        flex-direction: column !important;
        height: auto !important;
        overflow-y: auto !important;
        padding: 8px !important;
        gap: 12px !important;
    }
    
    /* Stat Cards - 2 Kolom di Mobile */
    .stat-cards {
        width: 100%;
    }
    
    .stat-cards .grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
    }
    
    .stat-cards > div > div {
        padding: 12px !important;
    }
    
    .stat-cards .text-2xl {
        font-size: 20px !important;
    }
    
    .stat-cards .text-xs {
        font-size: 10px !important;
    }
    
    .stat-cards i {
        font-size: 24px !important;
    }
    
    /* Widget Container */
    .widget {
        width: 100% !important;
        min-height: 300px !important;
        height: auto !important;
        padding: 12px !important;
    }
    
    .widget-header {
        font-size: 12px !important;
        margin-bottom: 12px !important;
    }
    
    .widget-header i {
        font-size: 14px !important;
    }
    
    /* Chart Container */
    .chart-container {
        position: relative !important;
        height: 250px !important;
        width: 100%;
    }
    
    /* Tables */
    .table-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    table {
        font-size: 9px !important;
        min-width: 600px;
    }
    
    th {
        font-size: 8px !important;
        padding: 4px 6px !important;
        white-space: nowrap;
    }
    
    td {
        padding: 4px 6px !important;
        white-space: nowrap;
    }
    
    /* Tabel Bulanan - Khusus */
    .table-bulanan table {
        min-width: 900px;
    }
    
    /* Progress Bar */
    .table-bulanan td > div {
        flex-direction: column;
        gap: 4px;
    }
    
    .table-bulanan .max-w-xs {
        max-width: 100% !important;
    }
    
    /* Badges dan Tags */
    td span.inline-block {
        font-size: 8px !important;
        padding: 2px 6px !important;
    }
    
    /* Sembunyikan elemen yang tidak penting di mobile */
    .dashboard-header .border-l {
        display: none;
    }
    
    /* Overflow handling */
    body, html {
        height: auto !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
    }
}

/* Tablet Portrait (768px - 1023px) */
@media (min-width: 768px) and (max-width: 1023px) {
    .dashboard-body {
        display: grid !important;
        grid-template-columns: repeat(6, 1fr) !important;
        grid-template-rows: auto !important;
        gap: 12px !important;
        padding: 12px !important;
        height: auto !important;
    }
    
    .stat-cards {
        grid-column: 1 / 7 !important;
    }
    
    .chart-trend {
        grid-column: 1 / 7 !important;
        min-height: 300px;
    }
    
    .chart-status {
        grid-column: 1 / 4 !important;
        min-height: 300px;
    }
    
    .chart-sparepart {
        grid-column: 4 / 7 !important;
        min-height: 300px;
    }
    
    .chart-jasa {
        grid-column: 1 / 7 !important;
        min-height: 250px;
    }
    
    .table-kendaraan {
        grid-column: 1 / 7 !important;
    }
    
    .table-bulanan {
        grid-column: 1 / 7 !important;
    }
    
    .chart-container {
        position: relative !important;
        height: 100%;
    }
}

/* Small Mobile (max-width: 480px) */
@media (max-width: 480px) {
    .stat-cards .grid {
        grid-template-columns: 1fr !important;
    }
    
    .dashboard-header h1 {
        font-size: 14px !important;
    }
    
    .widget {
        min-height: 280px !important;
    }
    
    table {
        font-size: 8px !important;
    }
    
    th {
        font-size: 7px !important;
        padding: 3px 4px !important;
    }
    
    td {
        padding: 3px 4px !important;
    }
}

/* Landscape Orientation */
@media (max-width: 768px) and (orientation: landscape) {
    .stat-cards .grid {
        grid-template-columns: repeat(4, 1fr) !important;
    }
    
    .chart-container {
        height: 220px !important;
    }
}

/* Touch Optimization */
@media (hover: none) and (pointer: coarse) {
    select {
        font-size: 16px !important; /* Prevent zoom on iOS */
    }
    
    .table-scroll {
        -webkit-overflow-scrolling: touch;
    }
    
    tr:active {
        background: #f3f4f6 !important;
    }
}

/* Tambahkan di bagian style */
.status-kendaraan { grid-column: 1 / 5; grid-row: 2 / 3; }

/* Update posisi widget lainnya */
.chart-trend { grid-column: 1 / 5; grid-row: 3 / 5; } /* Pindah ke bawah */
.chart-status { grid-column: 5 / 8; grid-row: 2 / 4; }
.chart-sparepart { grid-column: 8 / 11; grid-row: 2 / 4; }
.chart-jasa { grid-column: 11 / 13; grid-row: 2 / 4; }
.table-kendaraan { grid-column: 5 / 13; grid-row: 4 / 5; }
.table-bulanan { grid-column: 1 / 13; grid-row: 5 / 7; }

/* Responsive Mobile */
@media (max-width: 768px) {
    .status-kendaraan .flex {
        flex-direction: column !important;
        gap: 12px !important;
    }
    
    .status-kendaraan .flex-1 {
        width: 100%;
    }
    
    .status-kendaraan {
        min-height: 350px !important;
    }
}

/* Tablet */
@media (min-width: 768px) and (max-width: 1023px) {
    .status-kendaraan {
        grid-column: 1 / 7 !important;
        min-height: 200px;
    }
    
    .chart-trend {
        grid-column: 1 / 7 !important;
    }
}

/* Grid positioning untuk Status Kendaraan */
.status-kendaraan { 
    grid-column: 1 / 5; 
    grid-row: 2 / 3; 
}

/* Update posisi chart trend agar tidak tertimpa */
.chart-trend { 
    grid-column: 1 / 5; 
    grid-row: 3 / 5; 
}

/* Responsive untuk Status Kendaraan */
@media (max-width: 768px) {
    .dashboard-body {
        display: flex !important;
        flex-direction: column !important;
    }
    
    .status-kendaraan {
        width: 100%;
        order: 2; /* Letakkan setelah stat-cards */
    }
    
    .chart-trend {
        width: 100%;
        order: 3;
    }
    
    /* Grid 2x2 untuk cards di mobile */
    .status-cards-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 12px !important;
        padding: 0 !important;
    }
    
    .status-card-item {
        width: 100% !important;
    }
}

/* Tablet */
@media (min-width: 768px) and (max-width: 1023px) {
    .status-kendaraan {
        grid-column: 1 / 7 !important;
    }
    
    .chart-trend {
        grid-column: 1 / 7 !important;
    }
}
* Responsive untuk angka besar */
@media (max-width: 768px) {
    .status-card-item {
        min-height: 100px !important;
        padding: 12px 8px !important;
    }
    
    .status-card-item > div > div:first-child {
        font-size: 28px !important;
    }
    
    .status-card-item > div > div:nth-child(2) {
        font-size: 9px !important;
    }
}

@media (max-width: 480px) {
    .status-card-item > div > div:first-child {
        font-size: 24px !important;
    }
    
    .status-card-item > div > div:nth-child(2) {
        font-size: 8px !important;
    }
    
    .status-card-item > div > div:nth-child(3) {
        width: 32px !important;
        height: 2px !important;
    }
}

/* Untuk angka 3 digit (100-999) */
@media (min-width: 769px) {
    .status-card-item > div > div:first-child {
        font-size: clamp(24px, 2.5vw, 32px) !important;
    }
}
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include "navbar.php"; ?>
        <div class="main-content">
            <div class="dashboard-header text-white shadow-lg flex items-center justify-between px-4">
                <div class="flex items-center gap-3">
                    <i class="fas fa-chart-line text-xl"></i>
                    <h1 class="text-lg font-bold">Dashboard - pergudangan</h1>
                </div>
                <div class="flex items-center gap-4">
                    <form method="GET" action="" class="flex items-center gap-2">
                        <select name="periode" class="text-xs px-2 py-1 border border-white/30 rounded bg-white/10 text-white" onchange="this.form.submit()" style="color: white;">
                            <option value="bulan" <?= $periode == 'bulan' ? 'selected' : '' ?> style="color: black;">Bulan</option>
                            <option value="tahun" <?= $periode == 'tahun' ? 'selected' : '' ?> style="color: black;">Tahun</option>
                        </select>
                        <?php if($periode == 'bulan'): ?>
                        <select name="bulan" class="text-xs px-2 py-1 border border-white/30 rounded bg-white/10 text-white" onchange="this.form.submit()" style="color: white;">
                            <?php foreach([1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'] as $key => $nama): ?>
                                <option value="<?= $key ?>" <?= $bulan == $key ? 'selected' : '' ?> style="color: black;"><?= $nama ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <select name="tahun" class="text-xs px-2 py-1 border border-white/30 rounded bg-white/10 text-white" onchange="this.form.submit()" style="color: white;">
                            <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?= $y ?>" <?= $tahun == $y ? 'selected' : '' ?> style="color: black;"><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                    <div class="flex items-center gap-2 border-l border-white/30 pl-4"></div>
                </div>
            </div>
            <div class="dashboard-body">
                <div class="stat-cards">
                    <div class="grid grid-cols-4 gap-2 h-full">
                        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow p-3 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-xs opacity-90 mb-1">Total Permintaan</div>
                                    <div class="text-2xl font-bold"><?= number_format(safeNumber($statistik['total_permintaan'])) ?></div>
                                </div>
                                <i class="fas fa-file-alt text-3xl opacity-30"></i>
                            </div>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow p-3 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-xs opacity-90 mb-1">Selesai</div>
                                    <div class="text-2xl font-bold"><?= number_format(safeNumber($statistik['total_selesai'])) ?></div>
                                </div>
                                <i class="fas fa-check-circle text-3xl opacity-30"></i>
                            </div>
                        </div>
                        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-lg shadow p-3 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-xs opacity-90 mb-1">Proses Perbaikan</div>
                                    <div class="text-2xl font-bold"><?= number_format(safeNumber($statistik['total_proses'])) ?></div>
                                </div>
                                <i class="fas fa-cog text-3xl opacity-30"></i>
                            </div>
                        </div>
                        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow p-3 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-xs opacity-90 mb-1">Rata-rata Waktu Selesai</div>
                                    <div class="text-2xl font-bold"><?= formatHari($statistik['avg_completion_days']) ?></div>
                                    <div class="text-xs opacity-75 mt-1">Dari pengajuan hingga selesai</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

<!-- Widget Status Kendaraan - Optimized untuk angka 100+ -->
<div class="status-kendaraan widget">
    <div class="widget-header">
        <i class="fas fa-car text-teal-600 mr-2"></i>
        Status Kondisi Kendaraan (12 Bulan Terakhir)
    </div>
    <div class="widget-body">
        <div class="status-cards-grid" style="display: flex; align-items: center; justify-content: center; gap: 12px; height: 100%; padding: 0 12px;">
            
            <!-- Card Stabil -->
            <div class="status-card-item" style="flex: 1; background: linear-gradient(135deg, #d4f4dd 0%, #f0fdf4 100%); border-radius: 12px; padding: 16px 12px; border: 2px solid #52c41a; box-shadow: 0 4px 12px rgba(0,0,0,0.08); min-height: 110px; display: flex; align-items: center; justify-content: center;">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; width: 100%;">
                    <div style="font-size: 32px; font-weight: 800; color: #52c41a; line-height: 1;">
                        <?= number_format($statusKendaraan['stabil'] ?? 0) ?>
                    </div>
                    <div style="font-size: 10px; color: #237804; font-weight: 700; text-transform: uppercase; text-align: center; letter-spacing: 0.3px; line-height: 1.2;">
                        STABIL
                    </div>
                    <div style="width: 40px; height: 3px; background: #52c41a; border-radius: 2px; margin-top: 2px;"></div>
                </div>
            </div>

            <!-- Card Perlu Perhatian -->
            <div class="status-card-item" style="flex: 1; background: linear-gradient(135deg, #fff4e6 0%, #fffbf0 100%); border-radius: 12px; padding: 16px 12px; border: 2px solid #faad14; box-shadow: 0 4px 12px rgba(0,0,0,0.08); min-height: 110px; display: flex; align-items: center; justify-content: center;">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; width: 100%;">
                    <div style="font-size: 32px; font-weight: 800; color: #faad14; line-height: 1;">
                        <?= number_format($statusKendaraan['perlu_perhatian'] ?? 0) ?>
                    </div>
                    <div style="font-size: 10px; color: #ad6800; font-weight: 700; text-transform: uppercase; text-align: center; letter-spacing: 0.3px; line-height: 1.2;">
                        PERLU<br>PERHATIAN
                    </div>
                    <div style="width: 40px; height: 3px; background: #faad14; border-radius: 2px; margin-top: 2px;"></div>
                </div>
            </div>

            <!-- Card Sering Rusak -->
            <div class="status-card-item" style="flex: 1; background: linear-gradient(135deg, #fff1f0 0%, #fff5f5 100%); border-radius: 12px; padding: 16px 12px; border: 2px solid #ff4d4f; box-shadow: 0 4px 12px rgba(0,0,0,0.08); min-height: 110px; display: flex; align-items: center; justify-content: center;">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; width: 100%;">
                    <div style="font-size: 32px; font-weight: 800; color: #ff4d4f; line-height: 1;">
                        <?= number_format($statusKendaraan['sering_rusak'] ?? 0) ?>
                    </div>
                    <div style="font-size: 10px; color: #a8071a; font-weight: 700; text-transform: uppercase; text-align: center; letter-spacing: 0.3px; line-height: 1.2;">
                        SERING<br>RUSAK
                    </div>
                    <div style="width: 40px; height: 3px; background: #ff4d4f; border-radius: 2px; margin-top: 2px;"></div>
                </div>
            </div>

            <!-- Card Total Asset -->
            <div class="status-card-item" style="flex: 1; background: linear-gradient(135deg, #e8eaf6 0%, #f3f4f6 100%); border-radius: 12px; padding: 16px 12px; border: 2px solid #667eea; box-shadow: 0 4px 12px rgba(0,0,0,0.08); min-height: 110px; display: flex; align-items: center; justify-content: center;">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; width: 100%;">
                    <div style="font-size: 32px; font-weight: 800; color: #667eea; line-height: 1;">
                        <?= number_format($statusKendaraan['total_kendaraan'] ?? 0) ?>
                    </div>
                    <div style="font-size: 10px; color: #4338ca; font-weight: 700; text-transform: uppercase; text-align: center; letter-spacing: 0.3px; line-height: 1.2;">
                        TOTAL<br>ASSET
                    </div>
                    <div style="width: 40px; height: 3px; background: #667eea; border-radius: 2px; margin-top: 2px;"></div>
                </div>
            </div>

        </div>
    </div>
</div>
                <div class="chart-trend widget">
                    <div class="widget-header">
                        <i class="fas fa-chart-line text-blue-600 mr-2"></i>
                        Trend Permintaan & Penyelesaian <?= $tahun ?>
                    </div>
                    <div class="widget-body">
                        <div class="chart-container">
                            <canvas id="chartTrend"></canvas>
                        </div>
                    </div>
                </div>
                <div class="chart-status widget">
                    <div class="widget-header">
                        <i class="fas fa-chart-pie text-green-600 mr-2"></i>
                        Status Perbaikan
                    </div>
                    <div class="widget-body">
                        <div class="chart-container">
                            <canvas id="chartStatus"></canvas>
                        </div>
                    </div>
                </div>
                <div class="chart-sparepart widget">
                    <div class="widget-header">
                        <i class="fas fa-cogs text-orange-600 mr-2"></i>
                        Top Sparepart
                    </div>
                    <div class="widget-body">
                        <div class="chart-container">
                            <canvas id="chartSparepart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="chart-jasa widget">
                    <div class="widget-header">
                        <i class="fas fa-wrench text-purple-600 mr-2"></i>
                        Top Jasa
                    </div>
                    <div class="widget-body">
                        <div class="chart-container">
                            <canvas id="chartJasa"></canvas>
                        </div>
                    </div>
                </div>
                <div class="table-kendaraan widget">
                    <div class="widget-header">
                        <i class="fas fa-truck text-red-600 mr-2"></i>
                        Top 10 Kendaraan Terbanyak Perbaikan
                    </div>
                    <div class="widget-body">
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nomor Asset</th>
                                        <th>Jenis Kendaraan</th>
                                        <th>Bidang</th>
                                        <th class="text-center">Jumlah</th>
                                        <th class="text-right">Total Biaya</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($topKendaraan) > 0): ?>
                                        <?php foreach($topKendaraan as $k): ?>
                                        <tr>
                                            <td class="font-semibold text-gray-800"><?= htmlspecialchars($k['nopol']) ?></td>
                                            <td class="text-gray-600"><?= htmlspecialchars($k['jenis_kendaraan']) ?></td>
                                            <td><span class="inline-block px-2 py-0.5 bg-cyan-100 text-cyan-800 rounded text-xs font-semibold"><?= htmlspecialchars($k['bidang']) ?></span></td>
                                            <td class="text-center">
                                                <span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-800 rounded font-semibold">
                                                    <?= safeNumber($k['jumlah_perbaikan']) ?>x
                                                </span>
                                            </td>
                                            <td class="text-right font-semibold"><?= formatRupiah($k['total_biaya']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-gray-500 py-4">Tidak ada data</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="table-bulanan widget">
                    <div class="widget-header">
                        <i class="fas fa-calendar-check text-indigo-600 mr-2"></i>
                        Ringkasan Permintaan Per Tahun (Termasuk Tahun Lalu yang Belum Selesai)
                    </div>
                    <div class="widget-body">
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="align-middle">Tahun</th>
                                        <th rowspan="2" class="text-center align-middle">Total<br>Permintaan</th>
                                        <th rowspan="2" class="text-center align-middle">Selesai</th>
                                        <th rowspan="2" class="text-center align-middle">Belum<br>Selesai</th>
                                        <th colspan="3" class="text-center bg-green-50">Biaya Selesai (Tahun Ini)</th>
                                        <th colspan="3" class="text-center bg-yellow-50">Biaya Dalam Proses</th>
                                        <th rowspan="2" class="text-center align-middle">Progress</th>
                                    </tr>
                                    <tr>
                                        <th class="text-right bg-green-50">Jasa</th>
                                        <th class="text-right bg-green-50">Sparepart</th>
                                        <th class="text-right bg-green-50">Total</th>
                                        <th class="text-right bg-yellow-50">Jasa</th>
                                        <th class="text-right bg-yellow-50">Sparepart</th>
                                        <th class="text-right bg-yellow-50">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $grandTotalPermintaan = 0;
                                    $grandTotalSelesai = 0;
                                    $grandTotalBelumSelesai = 0;
                                    $grandBiayaJasaSelesai = 0;
                                    $grandBiayaSparepartSelesai = 0;
                                    $grandTotalBiayaSelesai = 0;
                                    $grandBiayaJasaProses = 0;
                                    $grandBiayaSparepartProses = 0;
                                    $grandTotalBiayaProses = 0;
                                    
                                    if(count($permintaanBelumSelesai) > 0): 
                                        foreach($permintaanBelumSelesai as $pbs): 
                                            $progress = $pbs['total_permintaan'] > 0 ? round(($pbs['total_selesai'] / $pbs['total_permintaan']) * 100) : 0;
                                            $is_current_year = ($pbs['tahun'] == $tahun);
                                            
                                            $grandTotalPermintaan += $pbs['total_permintaan'];
                                            $grandTotalSelesai += $pbs['total_selesai'];
                                            $grandTotalBelumSelesai += $pbs['total_belum_selesai'];
                                            $grandBiayaJasaSelesai += $pbs['biaya_jasa_selesai'];
                                            $grandBiayaSparepartSelesai += $pbs['biaya_sparepart_selesai'];
                                            $grandTotalBiayaSelesai += $pbs['total_biaya_selesai'];
                                            $grandBiayaJasaProses += $pbs['biaya_jasa_proses'];
                                            $grandBiayaSparepartProses += $pbs['biaya_sparepart_proses'];
                                            $grandTotalBiayaProses += $pbs['total_biaya_proses'];
                                    ?>
                                    <tr class="<?= $is_current_year ? 'bg-blue-50 font-semibold' : '' ?>">
                                        <td class="font-bold text-gray-800">
                                            <?= $pbs['tahun'] ?>
                                            <?= $is_current_year ? '<span class="ml-2 px-2 py-0.5 bg-blue-500 text-white rounded text-xs">Tahun Ini</span>' : '' ?>
                                        </td>
                                        <td class="text-center text-gray-700"><?= number_format($pbs['total_permintaan']) ?></td>
                                        <td class="text-center">
                                            <span class="inline-block px-2 py-0.5 bg-green-100 text-green-800 rounded font-semibold">
                                                <?= number_format($pbs['total_selesai']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if($pbs['total_belum_selesai'] > 0): ?>
                                            <span class="inline-block px-2 py-0.5 bg-red-100 text-red-800 rounded font-semibold">
                                                <?= number_format($pbs['total_belum_selesai']) ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-gray-400">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-semibold text-green-700 bg-green-50"><?= formatRupiah($pbs['biaya_jasa_selesai']) ?></td>
                                        <td class="text-right font-semibold text-green-700 bg-green-50"><?= formatRupiah($pbs['biaya_sparepart_selesai']) ?></td>
                                        <td class="text-right font-bold text-green-800 bg-green-50"><?= formatRupiah($pbs['total_biaya_selesai']) ?></td>
                                        <td class="text-right font-semibold text-yellow-700 bg-yellow-50"><?= formatRupiah($pbs['biaya_jasa_proses']) ?></td>
                                        <td class="text-right font-semibold text-yellow-700 bg-yellow-50"><?= formatRupiah($pbs['biaya_sparepart_proses']) ?></td>
                                        <td class="text-right font-bold text-yellow-800 bg-yellow-50"><?= formatRupiah($pbs['total_biaya_proses']) ?></td>
                                        <td class="text-center">
                                            <div class="flex items-center gap-2 justify-center">
                                                <div class="flex-1 max-w-xs bg-gray-200 rounded-full h-4 overflow-hidden">
                                                    <div class="<?= $progress >= 80 ? 'bg-green-500' : ($progress >= 50 ? 'bg-yellow-500' : 'bg-red-500') ?> h-full rounded-full transition-all" style="width: <?= $progress ?>%"></div>
                                                </div>
                                                <span class="text-sm font-bold text-gray-700 w-12"><?= $progress ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <tr class="bg-indigo-50 font-bold border-t-2 border-indigo-300">
                                        <td class="text-gray-800">TOTAL KESELURUHAN</td>
                                        <td class="text-center text-gray-800"><?= number_format($grandTotalPermintaan) ?></td>
                                        <td class="text-center text-green-800"><?= number_format($grandTotalSelesai) ?></td>
                                        <td class="text-center text-red-800"><?= number_format($grandTotalBelumSelesai) ?></td>
                                        <td class="text-right text-green-700 bg-green-100"><?= formatRupiah($grandBiayaJasaSelesai) ?></td>
                                        <td class="text-right text-green-700 bg-green-100"><?= formatRupiah($grandBiayaSparepartSelesai) ?></td>
                                        <td class="text-right text-green-900 bg-green-100"><?= formatRupiah($grandTotalBiayaSelesai) ?></td>
                                        <td class="text-right text-yellow-700 bg-yellow-100"><?= formatRupiah($grandBiayaJasaProses) ?></td>
                                        <td class="text-right text-yellow-700 bg-yellow-100"><?= formatRupiah($grandBiayaSparepartProses) ?></td>
                                        <td class="text-right text-yellow-900 bg-yellow-100"><?= formatRupiah($grandTotalBiayaProses) ?></td>
                                        <td class="text-center text-gray-800">
                                            <?= $grandTotalPermintaan > 0 ? round(($grandTotalSelesai / $grandTotalPermintaan) * 100) : 0 ?>%
                                        </td>
                                    </tr>
                                    
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="11" class="text-center text-gray-500 py-4">
                                            <i class="fas fa-inbox text-gray-400 text-2xl mb-2"></i>
                                            <div>Tidak ada data</div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        const dataBulanan = <?= json_encode($dataBulanan) ?>;
        const distribusiStatus = <?= json_encode($distribusiStatus) ?>;
        const topSparepart = <?= json_encode($topSparepart) ?>;
        const topJasa = <?= json_encode($topJasa) ?>;
        const namaBulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        Chart.defaults.font.size = 10;
        Chart.defaults.font.family = 'system-ui';
        new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: {
                labels: dataBulanan.map(d => namaBulan[d.bulan]),
                datasets: [{
                    label: 'Permintaan',
                    data: dataBulanan.map(d => d.total_permintaan || 0),
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2
                }, {
                    label: 'Selesai',
                    data: dataBulanan.map(d => d.total_selesai || 0),
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        display: true,
                        position: 'bottom',
                        labels: { boxWidth: 10, padding: 8, font: { size: 9 } }
                    },
                    tooltip: { enabled: true }
                },
                scales: {
                    y: { 
                        beginAtZero: true,
                        ticks: { font: { size: 8 } },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: { 
                        ticks: { font: { size: 8 } },
                        grid: { display: false }
                    }
                }
            }
        });
        if(distribusiStatus.length > 0) {
            const statusMap = {};
            distribusiStatus.forEach(d => {
                const status = d.status;
                if(status === 'Disetujui_KARU_QC' || status === 'Dikerjakan' || status === 'QC' || status === 'Disetujui_SA') {
                    statusMap['Dalam Proses'] = (statusMap['Dalam Proses'] || 0) + parseInt(d.jumlah);
                } else if(status === 'Diajukan') {
                    statusMap['Diajukan'] = (statusMap['Diajukan'] || 0) + parseInt(d.jumlah);
                } else if(status === 'Selesai') {
                    statusMap['Selesai'] = (statusMap['Selesai'] || 0) + parseInt(d.jumlah);
                } else if(status === 'Dikembalikan_sa') {
                    statusMap['Dikembalikan'] = (statusMap['Dikembalikan'] || 0) + parseInt(d.jumlah);
                } else {
                    statusMap[status.replace(/_/g, ' ')] = (statusMap[status.replace(/_/g, ' ')] || 0) + parseInt(d.jumlah);
                }
            });
            
            const labels = Object.keys(statusMap);
            const data = Object.values(statusMap);
            const colors = {
                'Diajukan': '#fbbf24',
                'Dalam Proses': '#3b82f6', 
                'Selesai': '#10b981',
                'Dikembalikan': '#ef4444'
            };
            const bgColors = labels.map(label => colors[label] || '#8b5cf6');
            
            new Chart(document.getElementById('chartStatus'), {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: bgColors
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'bottom',
                            labels: { boxWidth: 10, padding: 6, font: { size: 8 } }
                        }
                    }
                }
            });
        }
        if(topSparepart.length > 0) {
            new Chart(document.getElementById('chartSparepart'), {
                type: 'bar',
                data: {
                    labels: topSparepart.map(d => {
                        const nama = d.nama_sparepart || d.kode_sparepart;
                        return nama.length > 15 ? nama.substring(0, 15) + '...' : nama;
                    }),
                    datasets: [{
                        label: 'Jumlah',
                        data: topSparepart.map(d => d.jumlah || 0),
                        backgroundColor: 'rgba(249, 115, 22, 0.8)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { 
                            beginAtZero: true,
                            ticks: { font: { size: 7 } },
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        y: { 
                            ticks: { font: { size: 7 } },
                            grid: { display: false }
                        }
                    }
                }
            });
        }
        if(topJasa.length > 0) {
            new Chart(document.getElementById('chartJasa'), {
                type: 'bar',
                data: {
                    labels: topJasa.map(d => {
                        const nama = d.nama_pekerjaan || d.kode_pekerjaan;
                        return nama.length > 12 ? nama.substring(0, 12) + '...' : nama;
                    }),
                    datasets: [{
                        label: 'Jumlah',
                        data: topJasa.map(d => d.jumlah || 0),
                        backgroundColor: 'rgba(139, 92, 246, 0.8)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { 
                            beginAtZero: true,
                            ticks: { font: { size: 7 } },
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        y: { 
                            ticks: { font: { size: 7 } },
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
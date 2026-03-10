<?php
include "../inc/config.php";
requireAuth('angkutan_luar');

// Optimasi untuk dataset besar
set_time_limit(300); // 5 menit timeout (default 30 detik)
ini_set('memory_limit', '512M'); // Naikkan memory limit jika perlu

// Ambil parameter filter periode
$analysis_period = isset($_GET['period']) ? (int)$_GET['period'] : 12; // Default 12 bulan
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

// Hitung tanggal batas berdasarkan periode
$date_limit = date('Y-m-d H:i:s', strtotime("-$analysis_period months"));

// Batasi maksimal records untuk prevent timeout
$max_records = 50000;

// Query untuk mengambil data perbaikan per kendaraan - HANYA ANGKUTAN LUAR
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
    AND k.bidang = 'ANGKUTAN LUAR'
    ORDER BY k.nopol ASC, pp.created_at DESC
    LIMIT $max_records
";

$result = mysqli_query($connection, $query);
$total_records = mysqli_num_rows($result);

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

// Fungsi untuk format durasi (DIPERBAIKI untuk jangka panjang)
function formatDurasi($start, $end) {
    $start_time = strtotime($start);
    $end_time = strtotime($end);
    $diff = $end_time - $start_time;
    
    if ($diff < 0) return '0 detik';
    
    $seconds = $diff;
    $minutes = floor($seconds / 60);
    $hours = floor($minutes / 60);
    $days = floor($hours / 24);
    $months = floor($days / 30);
    $years = floor($days / 365);
    
    // Untuk durasi lebih dari 1 tahun
    if ($years > 0) {
        $remaining_months = floor(($days % 365) / 30);
        if ($remaining_months > 0) {
            return $years . ' tahun ' . $remaining_months . ' bulan';
        }
        return $years . ' tahun';
    }
    // Untuk durasi lebih dari 1 bulan
    elseif ($months > 0) {
        $remaining_days = $days % 30;
        if ($remaining_days > 0) {
            return $months . ' bulan ' . $remaining_days . ' hari';
        }
        return $months . ' bulan';
    }
    // Untuk durasi lebih dari 1 hari
    elseif ($days > 0) {
        $remaining_hours = $hours % 24;
        if ($remaining_hours > 0) {
            return $days . ' hari ' . $remaining_hours . ' jam';
        }
        return $days . ' hari';
    }
    elseif ($hours > 0) {
        $remaining_minutes = $minutes % 60;
        if ($remaining_minutes > 0) {
            return $hours . ' jam ' . $remaining_minutes . ' menit';
        }
        return $hours . ' jam';
    }
    elseif ($minutes > 0) {
        return $minutes . ' menit';
    } else {
        return $seconds . ' detik';
    }
}

function getDurasiJam($start, $end) {
    $diff = strtotime($end) - strtotime($start);
    return max(0, $diff / 3600);
}

function getDurasiHari($start, $end) {
    $diff = strtotime($end) - strtotime($start);
    return max(0, $diff / (3600 * 24));
}

function formatTanggalIndo($date) {
    if (!$date) return '-';
    $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
    $d = date('d', strtotime($date));
    $m = $bulan[(int)date('m', strtotime($date))];
    $y = date('Y', strtotime($date));
    return "$d $m $y";
}

// Hitung statistik
$status_count = ['Stabil' => 0, 'Perlu Perhatian' => 0, 'Sering Rusak' => 0, 'Data Lama' => 0];
$vehicle_stats = [];
$rekanan_stats = [];

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
            $downtime = getDurasiHari($tgl_mulai, $tgl_selesai);
            $total_downtime += $downtime;
            $completed_repairs++;
            
            $rekanan_name = $repair['nama_rekanan'] ?: 'Tidak Ada Rekanan';
            if (!isset($rekanan_stats[$rekanan_name])) {
                $rekanan_stats[$rekanan_name] = [
                    'total_downtime' => 0,
                    'count' => 0
                ];
            }
            $rekanan_stats[$rekanan_name]['total_downtime'] += $downtime;
            $rekanan_stats[$rekanan_name]['count']++;
            
            if (isset($repairs[$i + 1])) {
                $uptime = getDurasiHari($tgl_selesai, $repairs[$i + 1]['tgl_pengajuan']);
                $total_uptime += $uptime;
            } else {
                // PERBAIKAN: Batasi uptime maksimal sesuai periode analisis
                $now = date('Y-m-d H:i:s');
                $days_since_repair = getDurasiHari($tgl_selesai, $now);
                
                // Jika sudah lebih dari periode analisis, gunakan periode analisis sebagai batas
                $max_uptime = $analysis_period * 30; // konversi bulan ke hari
                $uptime = min($days_since_repair, $max_uptime);
                $total_uptime += $uptime;
            }
        }
    }
    
    if ($completed_repairs == 0) {
        continue;
    }
    
    $avg_uptime = $completed_repairs > 0 ? $total_uptime / $completed_repairs : 0;
    $avg_downtime = $completed_repairs > 0 ? $total_downtime / $completed_repairs : 0;
    
    // Cek apakah data sudah lama (threshold disesuaikan dengan periode analisis)
    $months_since_last = getDurasiHari($last_repair_date, date('Y-m-d H:i:s')) / 30;
    
    // Threshold disesuaikan: untuk analisis 12 bulan = 6 bulan, 24 bulan = 12 bulan, dst
    $threshold_data_lama = max(6, $analysis_period / 2); // Minimal 6 bulan
    
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
    
    $vehicle_stats[$nopol] = [
        'jenis' => $data['jenis'],
        'bidang' => $data['bidang'],
        'avg_uptime' => $avg_uptime,
        'avg_downtime' => $avg_downtime,
        'total_repairs' => $completed_repairs,
        'status' => $status,
        'repairs' => $repairs,
        'last_repair' => $last_repair_date,
        'months_since_last' => $months_since_last
    ];
}

foreach ($rekanan_stats as $name => &$stat) {
    $stat['avg_downtime'] = $stat['count'] > 0 ? $stat['total_downtime'] / $stat['count'] : 0;
}

$total_vehicles = count($vehicle_stats);
include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Kondisi Kendaraan - ANGKUTAN LUAR</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: rgb(185, 224, 204);  
            min-height: 100vh;
        }

        .main-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }

        @media (min-width: 1025px) {
            .main-container {
                margin-left: 15px;
            }
        }

        @media (max-width: 1024px) {
            .main-container {
                margin-left: 0;
                padding-top: 80px;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 80px 12px 20px 12px;
            }
        }

        @media (max-width: 768px) {
            #rekananPieChart { 
                max-width: 150px; 
                max-height: 150px; 
            }
        }
        
        @media (max-width: 640px) {
            div[style*="grid-template-columns: 1fr 2fr"] {
                grid-template-columns: 1fr !important;
            }
        }

        .header {
            background: white;
            padding: 24px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            margin-bottom: 20px;
            text-align: center;
        }

        .header h1 {
            font-size: clamp(20px, 5vw, 32px);
            background: rgb(9, 120, 83);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            font-weight: 800;
        }

        .header p {
            color: #666;
            font-size: clamp(12px, 3vw, 15px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
        }

        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
            }
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.2);
        }

        .stat-value {
            font-size: clamp(32px, 8vw, 48px);
            font-weight: 800;
            background: rgb(9, 120, 83);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: clamp(11px, 2.5vw, 14px);
            color: #666;
            font-weight: 600;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        @media (max-width: 640px) {
            .card {
                padding: 16px;
            }
        }

        .card-title {
            font-size: clamp(16px, 4vw, 18px);
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .legend-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .legend-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .legend-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .legend-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 2px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .legend-dot.green { background: linear-gradient(135deg, #52c41a 0%, #73d13d 100%); }
        .legend-dot.yellow { background: linear-gradient(135deg, #faad14 0%, #ffc53d 100%); }
        .legend-dot.red { background: linear-gradient(135deg, #ff4d4f 0%, #ff7875 100%); }

        .legend-text {
            font-size: clamp(11px, 2.5vw, 13px);
            color: #555;
            line-height: 1.5;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        @media (min-width: 640px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .filter-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .filter-group label {
            display: block;
            font-size: clamp(11px, 2.5vw, 13px);
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
        }

        .filter-input {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: clamp(12px, 3vw, 14px);
            font-family: inherit;
            transition: all 0.3s;
        }

        .filter-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: clamp(12px, 3vw, 14px);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-reset {
            background: #ff4d4f;
            color: white;
        }

        .btn-reset:hover {
            background: #ff7875;
        }

        .limit-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 15px;
        }

        .limit-selector label {
            font-weight: 600;
            color: #333;
            font-size: clamp(12px, 3vw, 14px);
        }

        .limit-btn {
            padding: 8px 16px;
            border: 2px solid #667eea;
            background: white;
            color: #667eea;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: clamp(11px, 2.5vw, 14px);
            transition: all 0.2s;
        }

        .limit-btn:hover,
        .limit-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .vehicle-card {
            background: white;
            padding: 16px 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 12px;
            transition: all 0.3s;
        }

        .vehicle-card.timeline-open {
            background: linear-gradient(135deg, rgb(196, 190, 190) 0%, rgb(94, 92, 92) 100%) !important;
            box-shadow: 0 12px 48px rgba(0, 0, 0, 0.25) !important;
            transform: scale(1.01);
        }

        .vehicle-card.bg-stabil {
            background: linear-gradient(135deg, rgb(133, 205, 153) 0%, #f0fdf4 100%);
            border-left: 5px solid #52c41a;
        }

        .vehicle-card.bg-perhatian {
            background: linear-gradient(135deg, rgb(196, 177, 136) 0%, #fffbf0 100%);
            border-left: 5px solid #faad14;
        }

        .vehicle-card.bg-rusak {
            background: linear-gradient(135deg, rgb(218, 160, 156) 0%, #fff5f5 100%);
            border-left: 5px solid #ff4d4f;
        }

        .vehicle-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }

        .vehicle-header {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .vehicle-nopol {
            font-size: clamp(16px, 3vw, 20px);
            font-weight: 800;
            color: #333;
            min-width: 120px;
        }

        .status-display {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: clamp(11px, 2.5vw, 13px);
            font-weight: 700;
            white-space: nowrap;
        }

        .status-display.stabil {
            background: #52c41a;
            color: white;
        }

        .status-display.perhatian {
            background: #faad14;
            color: white;
        }

        .status-display.rusak {
            background: #ff4d4f;
            color: white;
        }

        .vehicle-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            flex-wrap: wrap;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: clamp(10px, 2vw, 12px);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f0f0f0;
            color: #666;
            white-space: nowrap;
        }

        .vehicle-actions {
            margin-left: auto;
        }

        @media (max-width: 1200px) {
            .vehicle-header {
                gap: 8px;
            }
            .badge {
                font-size: 10px;
                padding: 4px 8px;
            }
        }

        @media (max-width: 768px) {
            .vehicle-card {
                padding: 12px 16px;
            }
            .vehicle-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .vehicle-info {
                width: 100%;
            }
            .vehicle-actions {
                width: 100%;
                margin-left: 0;
            }
            .btn-primary {
                width: 100%;
            }
        }

        .timeline-list {
            margin-top: 15px;
            max-height: 500px;
            overflow-y: auto;
            padding-right: 8px;
        }

        .timeline-list::-webkit-scrollbar {
            width: 6px;
        }

        .timeline-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .timeline-list::-webkit-scrollbar-thumb {
            background: rgb(9, 120, 83);
            border-radius: 10px;
        }

        .timeline-item {
            padding: 12px 14px;
            margin-bottom: 8px;
            border-radius: 10px;
            font-size: clamp(11px, 2.5vw, 14px);
            font-weight: 500;
            transition: transform 0.2s;
        }

        .timeline-item:hover {
            transform: translateX(5px);
        }

        .timeline-item.up {
            background: linear-gradient(90deg, #d4f4dd 0%, #f0fdf4 100%);
            color: #237804;
            border-left: 4px solid #52c41a;
        }

        .timeline-item.down {
            background: linear-gradient(90deg, #fff1f0 0%, #fff5f5 100%);
            color: #a8071a;
            border-left: 4px solid #ff4d4f;
        }

        .timeline-content {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        @media (min-width: 640px) {
            .timeline-content {
                flex-direction: row;
                align-items: center;
                gap: 12px;
            }
        }

        .timeline-type {
            font-weight: 700;
            font-size: clamp(11px, 2.5vw, 13px);
        }

        .timeline-dates {
            flex: 1;
            font-size: clamp(11px, 2.5vw, 13px);
        }

        .timeline-duration {
            font-weight: 700;
            font-size: clamp(11px, 2.5vw, 13px);
        }

        .rekanan-bar-item {
            margin-bottom: 15px;
        }

        .rekanan-bar-header {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 6px;
            font-size: clamp(11px, 2.5vw, 13px);
        }

        @media (min-width: 640px) {
            .rekanan-bar-header {
                flex-direction: row;
                justify-content: space-between;
            }
        }

        .rekanan-bar {
            background: #e0e0e0;
            height: 24px;
            border-radius: 12px;
            overflow: hidden;
        }

        .rekanan-bar-fill {
            background: rgb(9, 120, 83);
            height: 100%;
            transition: width 0.3s;
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #999;
            background: white;
            border-radius: 16px;
        }

        .no-data h3 {
            font-size: clamp(18px, 4vw, 24px);
            margin-bottom: 10px;
        }

        .text-center { text-align: center; }
        .mt-15 { margin-top: 15px; }
        .mb-15 { margin-bottom: 15px; }
        
        @media (max-width: 640px) {
            .hide-mobile { display: none; }
        }
    </style>
</head>
<body>
    <!-- Loading Indicator -->
    <div id="pageLoader" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.95); z-index: 9999; display: flex; align-items: center; justify-content: center; flex-direction: column;">
        <div style="font-size: 48px; margin-bottom: 20px;">⏳</div>
        <div style="font-size: 18px; font-weight: 600; color: #667eea;">Memuat Data...</div>
        <div style="font-size: 14px; color: #666; margin-top: 10px;">Mohon tunggu, sedang menganalisis <?php echo number_format($total_records); ?> data perbaikan</div>
        <div style="font-size: 12px; color: #999; margin-top: 5px;">Periode: <?php echo $analysis_period; ?> bulan | ANGKUTAN LUAR</div>
    </div>

    <div class="main-container">
        <!-- Header -->
        <div class="header">
            <h1>Dashboard Kondisi Kendaraan</h1>
            <p>Analisis Performa & Riwayat Perbaikan Asset - ANGKUTAN LUAR</p>
            <div style="margin-top: 10px; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; display: inline-block;">
                <span style="color: white; font-weight: 600; font-size: 14px;">
                    📅 Periode Analisis: <?php echo $analysis_period; ?> Bulan Terakhir
                    (<?php echo date('d M Y', strtotime($date_limit)); ?> - <?php echo date('d M Y'); ?>)
                </span>
            </div>
        </div>

        <!-- Filter Periode Analisis -->
        <div class="card">
            <div class="card-title">📊 Atur Periode Analisis</div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <span style="font-weight: 600; color: #333;">Pilih Periode:</span>
                <button class="limit-btn <?php echo $analysis_period == 3 ? 'active' : ''; ?>" onclick="setPeriod(3)">3 Bulan</button>
                <button class="limit-btn <?php echo $analysis_period == 6 ? 'active' : ''; ?>" onclick="setPeriod(6)">6 Bulan</button>
                <button class="limit-btn <?php echo $analysis_period == 12 ? 'active' : ''; ?>" onclick="setPeriod(12)">12 Bulan</button>
                <button class="limit-btn <?php echo $analysis_period == 24 ? 'active' : ''; ?>" onclick="setPeriod(24)">24 Bulan</button>
                <button class="limit-btn <?php echo $analysis_period == 36 ? 'active' : ''; ?>" onclick="setPeriod(36)">36 Bulan</button>
                <span style="color: #999; font-size: 12px; margin-left: auto;">
                    💡 Semakin panjang periode, semakin lama loading
                </span>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $status_count['Stabil']; ?></div>
                <div class="stat-label">Stabil</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $status_count['Perlu Perhatian']; ?></div>
                <div class="stat-label">Perlu Perhatian</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $status_count['Sering Rusak']; ?></div>
                <div class="stat-label">Sering Rusak</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_vehicles; ?></div>
                <div class="stat-label">Aset Ber-Riwayat</div>
            </div>
        </div>

        <!-- Legend -->
        <div class="card">
            <div class="card-title">📋 Keterangan Status & Rekomendasi</div>
            <div class="legend-grid">
                <div class="legend-item">
                    <div class="legend-dot green"></div>
                    <div class="legend-text">
                        <strong>✓ Stabil:</strong> Waktu operasional jauh lebih lama dari waktu perbaikan (uptime &gt; 2x downtime).
                        <br><small style="color: #666; margin-top: 4px; display: block;">
                            <strong>Rekomendasi:</strong> Pertahankan jadwal perawatan rutin dan dokumentasikan pola operasional untuk best practice.
                        </small>
                    </div>
                </div>
                <div class="legend-item">
                    <div class="legend-dot yellow"></div>
                    <div class="legend-text">
                        <strong>⚠ Perlu Perhatian:</strong> Waktu operasional dan perbaikan hampir seimbang (uptime &lt; 2x downtime).
                        <br><small style="color: #666; margin-top: 4px; display: block;">
                            <strong>Rekomendasi:</strong> Lakukan inspeksi mendalam, identifikasi komponen yang sering rusak, pertimbangkan preventive maintenance lebih intensif.
                        </small>
                    </div>
                </div>
                <div class="legend-item">
                    <div class="legend-dot red"></div>
                    <div class="legend-text">
                        <strong>✕ Sering Rusak:</strong> Waktu perbaikan lebih lama dari waktu operasional (downtime &gt; uptime).
                        <br><small style="color: #666; margin-top: 4px; display: block;">
                            <strong>Rekomendasi:</strong> Prioritas tinggi! Evaluasi kelayakan kendaraan, analisis cost-benefit replacement vs repair, review kualitas bengkel/rekanan.
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Collapsible Box 1: Interpretasi Metrik -->
            <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid #667eea; cursor: pointer; transition: all 0.3s;" onclick="toggleCollapse('metrik')">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-weight: 700; color: #333; display: flex; align-items: center; gap: 8px;">
                        💡 Interpretasi Metrik
                    </div>
                    <div id="icon-metrik" style="font-weight: 700; color: #667eea; font-size: 18px; transition: transform 0.3s;">▼</div>
                </div>
                <div id="content-metrik" style="display: none; margin-top: 12px;">
                    <ul style="margin: 0; padding-left: 20px; color: #555; font-size: clamp(11px, 2.5vw, 13px); line-height: 1.6;">
                        <li><strong>Rata-rata UP Time:</strong> Durasi kendaraan operasional antara kejadian perbaikan</li>
                        <li><strong>Rata-rata DOWN Time:</strong> Durasi kendaraan tidak dapat beroperasi (dalam perbaikan)</li>
                        <li><strong>Time Between Failures (TBF):</strong> Interval waktu antar kerusakan - semakin tinggi semakin baik</li>
                        <li><strong>Mean Time To Repair (MTTR):</strong> Rata-rata waktu perbaikan - semakin rendah semakin efisien</li>
                    </ul>
                </div>
            </div>

            <!-- Collapsible Box 2: Strategi Optimasi -->
            <div style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 10px; border-left: 4px solid #ffc107; cursor: pointer; transition: all 0.3s;" onclick="toggleCollapse('strategi')">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-weight: 700; color: #856404; display: flex; align-items: center; gap: 8px;">
                        🎯 Strategi Optimasi Fleet
                    </div>
                    <div id="icon-strategi" style="font-weight: 700; color: #ffc107; font-size: 18px; transition: transform 0.3s;">▼</div>
                </div>
                <div id="content-strategi" style="display: none; margin-top: 12px;">
                    <ul style="margin: 0; padding-left: 20px; color: #856404; font-size: clamp(11px, 2.5vw, 13px); line-height: 1.6;">
                        <li><strong>Preventive Maintenance:</strong> Jadwal servis berkala berdasarkan jam operasi atau kilometer</li>
                        <li><strong>Spare Part Inventory:</strong> Stock komponen yang sering rusak untuk meminimalkan downtime</li>
                        <li><strong>Evaluasi Rekanan:</strong> Monitor performa bengkel berdasarkan waktu perbaikan dan frekuensi kerusakan berulang</li>
                        <li><strong>Life Cycle Costing:</strong> Hitung total cost of ownership untuk keputusan replacement</li>
                        <li><strong>Driver Training:</strong> Pelatihan operator untuk mengurangi kerusakan akibat human error</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Limit Selector -->
        <div class="card">
            <div class="limit-selector">
                <label>📊 Tampilkan:</label>
                <button class="limit-btn <?php echo $limit == 20 ? 'active' : ''; ?>" onclick="setLimit(20)">20</button>
                <button class="limit-btn <?php echo $limit == 50 ? 'active' : ''; ?>" onclick="setLimit(50)">50</button>
                <button class="limit-btn <?php echo $limit == 100 ? 'active' : ''; ?>" onclick="setLimit(100)">100</button>
                <button class="limit-btn <?php echo $limit == 200 ? 'active' : ''; ?>" onclick="setLimit(200)">200</button>
                <button class="limit-btn <?php echo $limit == 300 ? 'active' : ''; ?>" onclick="setLimit(300)">300</button>
                <button class="limit-btn <?php echo $limit == 9999 ? 'active' : ''; ?>" onclick="setLimit(9999)">Semua</button>
                <span style="color: #666; font-size: clamp(11px, 2.5vw, 13px);" class="hide-mobile">Total: <?php echo count($vehicle_stats); ?></span>
            </div>
        </div>

        <!-- Filter - BIDANG DIHAPUS -->
        <div class="card">
            <div class="card-title">🔍 Filter Data</div>
            <div class="filter-grid">
                <div class="filter-group">
                    <label>📅 Tanggal Dari</label>
                    <input type="date" class="filter-input" id="filterDateFrom">
                </div>
                <div class="filter-group">
                    <label>📅 Tanggal Sampai</label>
                    <input type="date" class="filter-input" id="filterDateTo">
                </div>
                <div class="filter-group">
                    <label>🔍 Cari Asset</label>
                    <input type="text" class="filter-input" id="filterNopol" placeholder="Ketik Asset...">
                </div>
                <div class="filter-group">
                    <label>📊 Status</label>
                    <select class="filter-input" id="filterStatus">
                        <option value="">Semua Status</option>
                        <option value="Stabil">Stabil</option>
                        <option value="Perlu Perhatian">Perlu Perhatian</option>
                        <option value="Sering Rusak">Sering Rusak</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>🔧 Rekanan</label>
                    <select class="filter-input" id="filterRekanan">
                        <option value="">Semua Rekanan</option>
                        <?php
                        foreach (array_keys($rekanan_stats) as $rekanan) {
                            echo "<option value='" . htmlspecialchars($rekanan) . "'>" . htmlspecialchars($rekanan) . "</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="mt-15 text-center">
                <button class="btn btn-reset" onclick="resetFilters()">🔄 Reset Filter</button>
            </div>
        </div>

        <!-- Rekanan Performance Stats -->
        <div class="card" id="rekananChart">
            <div class="card-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <span>📊 Performa Rekanan</span>
                <button class="btn btn-primary" onclick="toggleRekananTable()" style="padding: 8px 16px; font-size: 13px;">
                    <span id="toggleTableIcon">📋</span> <span id="toggleTableText">Lihat Detail</span>
                </button>
            </div>
            
            <!-- Rekanan Speed Stats Grid -->
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-top: 20px;">
                <!-- Left: Pie Chart -->
                <div style="background: white; padding: 15px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div style="font-weight: 700; color: #333; margin-bottom: 10px; font-size: 13px;">📊 Distribusi Rekanan</div>
                    <div style="width: 100px; height: 100px;">
                        <canvas id="rekananPieChart"></canvas>
                    </div>
                    <div style="margin-top: 10px; font-size: 11px; color: #666; text-align: center;">
                        Total: <strong><?php echo count($rekanan_stats); ?></strong> Rekanan
                    </div>
                </div>
                
                <!-- Right: Stats Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                    <?php 
                    $speed_stats = ['cepat' => 0, 'sedang' => 0, 'lambat' => 0];
                    
                    foreach ($rekanan_stats as $rekanan_stat) {
                        $avg_days = $rekanan_stat['avg_downtime'];
                        
                        if ($avg_days < 1) {
                            $speed_stats['cepat']++;
                        } elseif ($avg_days <= 3) {
                            $speed_stats['sedang']++;
                        } else {
                            $speed_stats['lambat']++;
                        }
                    }
                    ?>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #d4f4dd 0%, #f0fdf4 100%); position: relative;">
                        <div class="stat-value" style="color: #52c41a;"><?php echo $speed_stats['cepat']; ?></div>
                        <div class="stat-label" style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                             Rekanan Cepat
                            <span class="info-icon" onclick="showInfo('cepat')" style="cursor: pointer; font-size: 14px; color: #52c41a;">ⓘ</span>
                        </div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #fff4e6 0%, #fffbf0 100%); position: relative;">
                        <div class="stat-value" style="color: #faad14;"><?php echo $speed_stats['sedang']; ?></div>
                        <div class="stat-label" style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                             Rekanan Sedang
                            <span class="info-icon" onclick="showInfo('sedang')" style="cursor: pointer; font-size: 14px; color: #faad14;">ⓘ</span>
                        </div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #fff1f0 0%, #fff5f5 100%); position: relative;">
                        <div class="stat-value" style="color: #ff4d4f;"><?php echo $speed_stats['lambat']; ?></div>
                        <div class="stat-label" style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                             Rekanan Lambat
                            <span class="info-icon" onclick="showInfo('lambat')" style="cursor: pointer; font-size: 14px; color: #ff4d4f;">ⓘ</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Info Tooltip -->
            <div id="speedInfoTooltip" style="display: none; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; border-left: 4px solid #667eea;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong style="color: #333; font-size: 15px;">📌 Kriteria Kecepatan Rekanan</strong>
                    <button onclick="closeInfo()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #999;">×</button>
                </div>
                <div id="speedInfoContent" style="color: #555; font-size: 13px; line-height: 1.6;"></div>
            </div>
            
            <!-- Tabel Detail Rekanan (Hidden by default) -->
            <div id="rekananTableContainer" style="display: none; margin-top: 20px;">
                <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                    <input type="text" id="searchRekanan" class="filter-input" placeholder="🔍 Cari nama rekanan..." style="flex: 1; min-width: 200px;">
                    <select id="filterSpeed" class="filter-input" style="min-width: 150px;">
                        <option value="">Semua Kecepatan</option>
                        <option value="cepat"> Cepat (&lt; 1 hari)</option>
                        <option value="sedang"> Sedang (1-3 hari)</option>
                        <option value="lambat"> Lambat (&gt; 3 hari)</option>
                    </select>
                </div>
                
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: clamp(11px, 2.5vw, 14px);">
                        <thead>
                            <tr style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">No</th>
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Nama Rekanan</th>
                                <th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Jumlah Perbaikan</th>
                                <th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Rata-rata Waktu</th>
                                <th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Kategori</th>
                            </tr>
                        </thead>
                        <tbody id="rekananTableBody">
                            <?php 
                            $no = 1;
                            foreach ($rekanan_stats as $rekanan_name => $rekanan_stat): 
                                $avg_days = $rekanan_stat['avg_downtime'];
                                $avg_format = '';
                                
                                if ($avg_days >= 1) {
                                    $days = (int)floor($avg_days);
                                    $hours = (int)round(($avg_days - $days) * 24);
                                    $avg_format = $days . ' hari' . ($hours > 0 ? ' ' . $hours . ' jam' : '');
                                } else {
                                    $hours = (int)floor($avg_days * 24);
                                    $minutes = (int)round(($avg_days * 24 - $hours) * 60);
                                    if ($hours > 0) {
                                        $avg_format = $hours . ' jam' . ($minutes > 0 ? ' ' . $minutes . ' menit' : '');
                                    } else {
                                        $avg_format = $minutes . ' menit';
                                    }
                                }
                                
                                $speed_status = '';
                                $speed_class = '';
                                if ($avg_days < 1) {
                                    $speed_status = '⚡ Cepat';
                                    $speed_class = 'cepat';
                                } elseif ($avg_days <= 3) {
                                    $speed_status = '⏱️ Sedang';
                                    $speed_class = 'sedang';
                                } else {
                                    $speed_status = '🐌 Lambat';
                                    $speed_class = 'lambat';
                                }
                            ?>
                            <tr class="rekanan-row" data-rekanan="<?php echo strtolower(htmlspecialchars($rekanan_name)); ?>" data-speed="<?php echo $speed_class; ?>" style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px; border: 1px solid #ddd; text-align: center;"><?php echo $no++; ?></td>
                                <td style="padding: 12px; border: 1px solid #ddd; font-weight: 600;"><?php echo htmlspecialchars($rekanan_name); ?></td>
                                <td style="padding: 12px; border: 1px solid #ddd; text-align: center;"><?php echo $rekanan_stat['count']; ?></td>
                                <td style="padding: 12px; border: 1px solid #ddd; text-align: center;"><?php echo $avg_format; ?></td>
                                <td style="padding: 12px; border: 1px solid #ddd; text-align: center;">
                                    <span style="padding: 4px 12px; border-radius: 12px; font-weight: 600; font-size: 12px; display: inline-block;
                                        <?php 
                                        if ($speed_class == 'cepat') echo 'background: #52c41a; color: white;';
                                        elseif ($speed_class == 'sedang') echo 'background: #faad14; color: white;';
                                        else echo 'background: #ff4d4f; color: white;';
                                        ?>">
                                        <?php echo $speed_status; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div id="noRekananData" style="display: none; text-align: center; padding: 20px; color: #999;">
                    <p>Tidak ada data yang sesuai dengan filter</p>
                </div>
            </div>
        </div>

        <!-- Vehicles -->
        <div id="vehicleList">
            <?php if (count($vehicle_stats) > 0): ?>
                <?php 
                $max_timeline_items = 100; // Batasi timeline untuk performa
                
                foreach ($vehicle_stats as $nopol => $stat): 
                    $bg_class = '';
                    if ($stat['status'] == 'Stabil') $bg_class = 'bg-stabil';
                    elseif ($stat['status'] == 'Perlu Perhatian') $bg_class = 'bg-perhatian';
                    elseif ($stat['status'] == 'Sering Rusak') $bg_class = 'bg-rusak';
                    
                    $status_display_class = '';
                    if ($stat['status'] == 'Stabil') $status_display_class = 'stabil';
                    elseif ($stat['status'] == 'Perlu Perhatian') $status_display_class = 'perhatian';
                    elseif ($stat['status'] == 'Sering Rusak') $status_display_class = 'rusak';
                    
                    // Format waktu rata-rata
                    $avg_up_format = '';
                    if ($stat['avg_uptime'] >= 24) {
                        $days = (int)floor($stat['avg_uptime'] / 24);
                        $hours = (int)round(fmod($stat['avg_uptime'], 24));
                        $avg_up_format = $days . ' hari' . ($hours > 0 ? ' ' . $hours . ' jam' : '');
                    } elseif ($stat['avg_uptime'] >= 1) {
                        $hours_int = (int)floor($stat['avg_uptime']);
                        $minutes = (int)round(($stat['avg_uptime'] - $hours_int) * 60);
                        $avg_up_format = $hours_int . ' jam' . ($minutes > 0 ? ' ' . $minutes . ' menit' : '');
                    } else {
                        $minutes = (int)round($stat['avg_uptime'] * 60);
                        $avg_up_format = $minutes . ' menit';
                    }
                    
                    $avg_down_format = '';
                    if ($stat['avg_downtime'] >= 24) {
                        $days = (int)floor($stat['avg_downtime'] / 24);
                        $hours = (int)round(fmod($stat['avg_downtime'], 24));
                        $avg_down_format = $days . ' hari' . ($hours > 0 ? ' ' . $hours . ' jam' : '');
                    } elseif ($stat['avg_downtime'] >= 1) {
                        $hours_int = (int)floor($stat['avg_downtime']);
                        $minutes = (int)round(($stat['avg_downtime'] - $hours_int) * 60);
                        $avg_down_format = $hours_int . ' jam' . ($minutes > 0 ? ' ' . $minutes . ' menit' : '');
                    } else {
                        $minutes = (int)round($stat['avg_downtime'] * 60);
                        $avg_down_format = $minutes . ' menit';
                    }
                ?>
                <div class="vehicle-card <?php echo $bg_class; ?>" data-nopol="<?php echo htmlspecialchars($nopol); ?>" 
                     data-status="<?php echo htmlspecialchars($stat['status']); ?>"
                     data-bidang="<?php echo htmlspecialchars($stat['bidang']); ?>"
                     data-repairs='<?php echo json_encode($stat['repairs']); ?>'>
                    <div class="vehicle-header">
                        <div class="vehicle-nopol"><?php echo htmlspecialchars($nopol); ?></div>
                        
                        <div class="vehicle-info">
                            <span class="badge">🚙 <?php echo htmlspecialchars($stat['jenis']); ?></span>
                            <span class="badge" title="Rata-rata waktu operasional">🟢 Rata-rata UP: <?php echo $avg_up_format; ?></span>
                            <span class="badge" title="Rata-rata waktu perbaikan">🔴 Rata-rata DOWN: <?php echo $avg_down_format; ?></span>
                            <span class="badge">📝 <?php echo $stat['total_repairs']; ?> Riwayat</span>
                        </div>

                        <div class="status-display <?php echo $status_display_class; ?>">
                            <?php 
                            if ($stat['status'] == 'Stabil') echo '✓ Stabil';
                            elseif ($stat['status'] == 'Perlu Perhatian') echo '⚠ Perhatian';
                            elseif ($stat['status'] == 'Sering Rusak') echo '✕ Rusak';
                            ?>
                        </div>
                        
                        <div class="vehicle-actions">
                            <button class="btn btn-primary" onclick="toggleTimeline('<?php echo htmlspecialchars($nopol); ?>')">
                                👁️ Lihat Detail
                            </button>
                        </div>
                    </div>
                    <div class="timeline-list" id="timeline-<?php echo htmlspecialchars($nopol); ?>" style="display: none;">
                        <?php
                        $count = 0;
                        $repairs_reversed = array_reverse($stat['repairs']);
                        
                        for ($i = 0; $i < count($repairs_reversed) && $count < $max_timeline_items; $i++) {
                            $repair = $repairs_reversed[$i];
                            $tgl_mulai = $repair['tgl_pengajuan'];
                            $tgl_selesai = $repair['tgl_dikembalikan'] ?: $repair['tgl_disetujui_karu_qc'];
                            
                            if ($tgl_selesai) {
                                $downtime = formatDurasi($tgl_mulai, $tgl_selesai);
                                $rekanan = $repair['nama_rekanan'] ?: 'Tidak Ada Rekanan';
                                echo '<div class="timeline-item down">';
                                echo '<div class="timeline-content">';
                                echo '<span class="timeline-type">DOWN</span>';
                                echo '<span class="timeline-dates">' . formatTanggalIndo($tgl_mulai) . ' – ' . formatTanggalIndo($tgl_selesai) . '</span>';
                                echo '<span class="timeline-duration">' . $downtime . '</span>';
                                echo '<span class="badge">🔧 ' . htmlspecialchars($rekanan) . '</span>';
                                echo '</div>';
                                echo '</div>';
                                $count++;
                                
                                if (isset($repairs_reversed[$i + 1])) {
                                    $next_repair_date = $repairs_reversed[$i + 1]['tgl_pengajuan'];
                                    $uptime = formatDurasi($tgl_selesai, $next_repair_date);
                                    
                                    echo '<div class="timeline-item up">';
                                    echo '<div class="timeline-content">';
                                    echo '<span class="timeline-type">UP</span>';
                                    echo '<span class="timeline-dates">' . formatTanggalIndo($tgl_selesai) . ' – ' . formatTanggalIndo($next_repair_date) . '</span>';
                                    echo '<span class="timeline-duration">' . $uptime . '</span>';
                                    echo '</div>';
                                    echo '</div>';
                                    $count++;
                                } else {
                                    $uptime = formatDurasi($tgl_selesai, date('Y-m-d H:i:s'));
                                    
                                    echo '<div class="timeline-item up">';
                                    echo '<div class="timeline-content">';
                                    echo '<span class="timeline-type">UP</span>';
                                    echo '<span class="timeline-dates">' . formatTanggalIndo($tgl_selesai) . ' – Sekarang</span>';
                                    echo '<span class="timeline-duration">' . $uptime . '</span>';
                                    echo '</div>';
                                    echo '</div>';
                                    $count++;
                                }
                            }
                        }
                        
                        // Info jika ada lebih banyak data
                        $total_timeline_items = 0;
                        foreach ($stat['repairs'] as $repair) {
                            if ($repair['tgl_dikembalikan'] || $repair['tgl_disetujui_karu_qc']) {
                                $total_timeline_items += 2; // DOWN + UP
                            }
                        }
                        
                        if ($total_timeline_items > $max_timeline_items): 
                        ?>
                        <div style="text-align: center; padding: 15px; margin-top: 10px; background: #fff3cd; border-radius: 8px; color: #856404; font-size: 13px;">
                            ⚠️ Menampilkan <?php echo $max_timeline_items; ?> dari <?php echo $total_timeline_items; ?> kejadian. 
                            Data lengkap tersedia di sistem.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">
                    <h3>📭 Tidak ada data kendaraan</h3>
                    <p>Belum ada riwayat perbaikan yang tercatat untuk ANGKUTAN LUAR</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Hide loader setelah halaman selesai dimuat
        window.addEventListener('load', function() {
            document.getElementById('pageLoader').style.display = 'none';
        });
        
        // Fallback jika load event tidak trigger
        setTimeout(function() {
            document.getElementById('pageLoader').style.display = 'none';
        }, 5000);

        // Render Pie Chart untuk Rekanan
        const ctx = document.getElementById('rekananPieChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [' Cepat', ' Sedang', ' Lambat'],
                    datasets: [{
                        data: [
                            <?php echo $speed_stats['cepat']; ?>,
                            <?php echo $speed_stats['sedang']; ?>,
                            <?php echo $speed_stats['lambat']; ?>
                        ],
                        backgroundColor: [
                            '#52c41a',
                            '#faad14',
                            '#ff4d4f'
                        ],
                        borderWidth: 3,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 10,
                                font: {
                                    size: 7,
                                    family: "'Inter', sans-serif"
                                },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = <?php echo count($rekanan_stats); ?>;
                                    const value = context.parsed;
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return context.label + ': ' + value;
                                }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
        }

        function showInfo(type) {
            const tooltip = document.getElementById('speedInfoTooltip');
            const content = document.getElementById('speedInfoContent');
            
            let infoText = '';
            
            if (type === 'cepat') {
                infoText = `
                    <div style="padding: 10px; background: #d4f4dd; border-radius: 8px; margin-bottom: 5px;">
                        <strong style="color: #237804;">⚡ Rekanan Cepat</strong>
                        <p style="margin: 5px 0 0 0;">Waktu perbaikan rata-rata <strong>kurang dari 1 hari</strong></p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">✓ Respons cepat, efisien, minim downtime kendaraan</p>
                    </div>
                `;
            } else if (type === 'sedang') {
                infoText = `
                    <div style="padding: 10px; background: #fff4e6; border-radius: 8px; margin-bottom: 5px;">
                        <strong style="color: #ad6800;">⏱️ Rekanan Sedang</strong>
                        <p style="margin: 5px 0 0 0;">Waktu perbaikan rata-rata <strong>1 sampai 3 hari</strong></p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">⚠ Performa standar, masih dalam batas wajar</p>
                    </div>
                `;
            } else if (type === 'lambat') {
                infoText = `
                    <div style="padding: 10px; background: #fff1f0; border-radius: 8px; margin-bottom: 5px;">
                        <strong style="color: #a8071a;">🐌 Rekanan Lambat</strong>
                        <p style="margin: 5px 0 0 0;">Waktu perbaikan rata-rata <strong>lebih dari 3 hari</strong></p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">✕ Perlu evaluasi, downtime terlalu lama</p>
                    </div>
                `;
            }
            
            content.innerHTML = infoText;
            tooltip.style.display = 'block';
            tooltip.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function closeInfo() {
            document.getElementById('speedInfoTooltip').style.display = 'none';
        }

        function toggleCollapse(id) {
            const content = document.getElementById('content-' + id);
            const icon = document.getElementById('icon-' + id);
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';
                icon.textContent = '▲';
            } else {
                content.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
                icon.textContent = '▼';
            }
        }

        function toggleRekananTable() {
            const tableContainer = document.getElementById('rekananTableContainer');
            const toggleIcon = document.getElementById('toggleTableIcon');
            const toggleText = document.getElementById('toggleTableText');
            
            if (tableContainer.style.display === 'none') {
                tableContainer.style.display = 'block';
                toggleIcon.textContent = '📊';
                toggleText.textContent = 'Sembunyikan Detail';
            } else {
                tableContainer.style.display = 'none';
                toggleIcon.textContent = '📋';
                toggleText.textContent = 'Lihat Detail';
            }
        }

        function filterRekananTable() {
            const searchValue = document.getElementById('searchRekanan').value.toLowerCase();
            const speedValue = document.getElementById('filterSpeed').value;
            const rows = document.querySelectorAll('.rekanan-row');
            const noDataMsg = document.getElementById('noRekananData');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const rekananName = row.dataset.rekanan;
                const speedClass = row.dataset.speed;
                
                const matchSearch = rekananName.includes(searchValue);
                const matchSpeed = !speedValue || speedClass === speedValue;
                
                if (matchSearch && matchSpeed) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0) {
                noDataMsg.style.display = 'block';
            } else {
                noDataMsg.style.display = 'none';
            }
        }

        document.getElementById('searchRekanan')?.addEventListener('input', filterRekananTable);
        document.getElementById('filterSpeed')?.addEventListener('change', filterRekananTable);

        function setPeriod(months) {
            const url = new URL(window.location.href);
            url.searchParams.set('period', months);
            url.searchParams.delete('limit');
            window.location.href = url.toString();
        }

        function setLimit(limit) {
            const url = new URL(window.location.href);
            url.searchParams.set('limit', limit);
            window.location.href = url.toString();
        }

        function toggleTimeline(nopol) {
            const timeline = document.getElementById('timeline-' + nopol);
            const card = document.querySelector(`[data-nopol="${nopol}"]`);
            
            if (timeline.style.display === 'none') {
                timeline.style.display = 'block';
                card.classList.add('timeline-open');
            } else {
                timeline.style.display = 'none';
                card.classList.remove('timeline-open');
            }
        }

        function resetFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterNopol').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterRekanan').value = '';
            applyFilters();
        }

        const filterDateFrom = document.getElementById('filterDateFrom');
        const filterDateTo = document.getElementById('filterDateTo');
        const filterNopol = document.getElementById('filterNopol');
        const filterStatus = document.getElementById('filterStatus');
        const filterRekanan = document.getElementById('filterRekanan');

        function applyFilters() {
            const dateFromValue = filterDateFrom.value;
            const dateToValue = filterDateTo.value;
            const nopolValue = filterNopol.value.toLowerCase();
            const statusValue = filterStatus.value;
            const rekananValue = filterRekanan.value;
            
            const cards = document.querySelectorAll('.vehicle-card');
            
            cards.forEach(card => {
                const nopol = card.dataset.nopol.toLowerCase();
                const status = card.dataset.status;
                const repairs = JSON.parse(card.dataset.repairs || '[]');
                
                const matchNopol = nopol.includes(nopolValue);
                const matchStatus = !statusValue || status === statusValue;
                
                let matchDate = true;
                let matchRekanan = true;
                
                if (dateFromValue || dateToValue || rekananValue) {
                    matchDate = false;
                    matchRekanan = rekananValue ? false : true;
                    
                    repairs.forEach(repair => {
                        const repairDate = new Date(repair.tgl_pengajuan);
                        const fromDate = dateFromValue ? new Date(dateFromValue) : null;
                        const toDate = dateToValue ? new Date(dateToValue) : null;
                        
                        const dateInRange = (!fromDate || repairDate >= fromDate) && 
                                          (!toDate || repairDate <= toDate);
                        
                        if (dateInRange) {
                            matchDate = true;
                        }
                        
                        if (rekananValue && repair.nama_rekanan === rekananValue) {
                            matchRekanan = true;
                        }
                    });
                }
                
                if (matchNopol && matchStatus && matchDate && matchRekanan) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        filterDateFrom.addEventListener('change', applyFilters);
        filterDateTo.addEventListener('change', applyFilters);
        filterNopol.addEventListener('input', applyFilters);
        filterStatus.addEventListener('change', applyFilters);
        filterRekanan.addEventListener('change', applyFilters);
    </script>
</body>
</html>

<?php mysqli_close($connection); ?>
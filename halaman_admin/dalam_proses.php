<?php

include "../inc/config.php";
requireAuth('admin');

// Daftar bidang
$bidang_list = [
    'Angkutan Dalam',
    'Angkutan Luar',
    'Alat Berat Wilayah 1',
    'Alat Berat Wilayah 2',
    'Alat Berat Wilayah 3',
    'Pergudangan'
];

// Mode tampilan: 'top5' atau 'carousel'
$display_mode = isset($_GET['mode']) ? $_GET['mode'] : 'top5';

// Query untuk semua bidang
$sql = "
SELECT
    pp.id_permintaan,
    pp.nomor_pengajuan,
    pp.status,
    pp.tgl_disetujui_karu_qc,
    k.nopol,
    k.bidang,
    r.nama_rekanan,
    k.jenis_kendaraan
FROM permintaan_perbaikan pp
JOIN kendaraan k ON pp.id_kendaraan = k.id_kendaraan
JOIN rekanan r ON pp.id_rekanan = r.id_rekanan
WHERE pp.status IN ('Disetujui_KARU_QC','QC','Dikembalikan_sa')
ORDER BY pp.tgl_disetujui_karu_qc ASC
";

$result = mysqli_query($connection, $sql);
if (!$result) {
    die(mysqli_error($connection));
}

// Kelompokkan data per bidang
$data_per_bidang = [];
while ($row = mysqli_fetch_assoc($result)) {
    $bidang = trim($row['bidang']);
    if (!isset($data_per_bidang[$bidang])) {
        $data_per_bidang[$bidang] = [];
    }
    $data_per_bidang[$bidang][] = $row;
}

// Fungsi hitung durasi
function hitungDurasi($tgl_mulai) {
    $mulai = new DateTime($tgl_mulai);
    $now = new DateTime();
    
    if ($mulai > $now) {
        return [
            'hari' => 0,
            'jam' => 0,
            'menit' => 0,
            'total_jam' => 0,
            'label' => '0j 0m'
        ];
    }
    
    $diff = $mulai->diff($now);
    $total_jam = ($diff->d * 24) + $diff->h;
    
    return [
        'hari' => $diff->d,
        'jam' => $diff->h,
        'menit' => $diff->i,
        'total_jam' => $total_jam,
        'label' => $diff->d > 0 ? $diff->d . 'd ' . $diff->h . 'j' : $diff->h . 'j ' . $diff->i . 'm'
    ];
}

// Fungsi get priority class
function getPriorityClass($total_jam) {
    if ($total_jam >= 48) return 'urgent';
    if ($total_jam >= 24) return 'high';
    if ($total_jam >= 12) return 'medium';
    return 'normal';
}

// Fungsi get priority score untuk sorting
function getPriorityScore($total_jam) {
    if ($total_jam >= 48) return 4;
    if ($total_jam >= 24) return 3;
    if ($total_jam >= 12) return 2;
    return 1;
}

// Fungsi get icon bidang
function getIconBidang($bidang) {
    if (strpos($bidang, 'Angkutan') !== false) return '🚚';
    if (strpos($bidang, 'Alat Berat') !== false) return '🚜';
    if (strpos($bidang, 'Gudang') !== false) return '📦';
    return '🔧';
}

// Fungsi hitung statistik per bidang
function hitungStatistik($data) {
    $stats = [
        'urgent' => 0,
        'high' => 0,
        'medium' => 0,
        'normal' => 0,
        'total' => count($data)
    ];
    
    foreach ($data as $item) {
        $durasi = hitungDurasi($item['tgl_disetujui_karu_qc']);
        $priority = getPriorityClass($durasi['total_jam']);
        $stats[$priority]++;
    }
    
    return $stats;
}

include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Jadwal Perbaikan</title>
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
            overflow-x: hidden;
    overflow-y: auto;
        }

        /* ========================================
           DESKTOP & LAPTOP LAYOUT (>1024px)
        ======================================== */
        .main-content {
            margin-left: 20px;                 /* geser ke kiri */
            padding: 5px ;
            max-width: 100%;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
            overflow-y: auto;

        }

        .content-wrapper {
            max-width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* Header Container */
        .header-container {
            background: white;
            padding: 12px 15px;
            border-radius: 10px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            flex-shrink: 0;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-title h1 {
            color: #1a6b3a;
            font-size: 20px;
            font-weight: bold;
            white-space: nowrap;
        }

        .header-title i {
            color: #f59e0b;
            font-size: 24px;
        }

        .datetime-display {
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, #1a6b3a 0%, #2d8f4f 100%);
            padding: 6px 15px;
            border-radius: 8px;
            color: white;
        }

        .datetime-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .datetime-item i {
            color: #fbbf24;
            font-size: 12px;
        }

        .separator {
            color: rgba(255,255,255,0.5);
            font-weight: bold;
        }

        .timezone {
            background: #f59e0b;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
        }

        .header-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .legend-compact {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .legend-item-compact {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
        }

        .legend-color {
            width: 18px;
            height: 18px;
            border-radius: 3px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .legend-color.urgent { background: #dc2626; }
        .legend-color.high { background: #f59e0b; }
        .legend-color.medium { background: #3b82f6; }
        .legend-color.normal { background: #10b981; }

        .legend-text {
            color: #475569;
            font-weight: 600;
        }

        .mode-toggle {
            display: flex;
            gap: 8px;
        }

        .mode-btn {
            padding: 6px 14px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            transition: all 0.3s;
            text-decoration: none;
            white-space: nowrap;
        }

        .mode-btn:hover {
            background: #f8fafc;
            border-color: #1a6b3a;
        }

        .mode-btn.active {
            background: linear-gradient(135deg, #1a6b3a 0%, #2d8f4f 100%);
            color: white;
            border-color: #1a6b3a;
        }

        /* Grid Bidang */
        .bidang-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            flex: 1;
            overflow-y: auto;
            padding-right: 8px;
            max-height: calc(100vh - 150px);
        }

        .bidang-grid::-webkit-scrollbar {
            width: 8px;
        }

        .bidang-grid::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }

        .bidang-grid::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 10px;
        }

        .bidang-card {
            background: white;
            border-radius: 10px;
            padding: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.12);
            transition: transform 0.3s;
            display: flex;
            flex-direction: column;
            height: fit-content;
        }

        .bidang-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }

        .bidang-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
            flex-shrink: 0;
        }

        .bidang-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bidang-title h2 {
            color: #1a6b3a;
            font-size: 14px;
            font-weight: bold;
        }

        .bidang-title .icon {
            font-size: 20px;
        }

        .bidang-count {
            background: linear-gradient(135deg, #1a6b3a 0%, #2d8f4f 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: bold;
            font-size: 11px;
            box-shadow: 0 2px 6px rgba(26, 107, 58, 0.3);
        }

        .bidang-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-bottom: 8px;
            flex-shrink: 0;
        }

        .stat-item {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 6px 8px;
            border-radius: 5px;
            text-align: center;
            border-left: 3px solid;
            transition: all 0.3s;
        }

        .stat-item:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        }

        .stat-item.urgent { border-left-color: #dc2626; }
        .stat-item.high { border-left-color: #f59e0b; }
        .stat-item.medium { border-left-color: #3b82f6; }
        .stat-item.normal { border-left-color: #10b981; }

        .stat-number {
            font-size: 16px;
            font-weight: bold;
            color: #1e293b;
            line-height: 1;
        }

        .stat-label {
            font-size: 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            margin-top: 2px;
        }

        .antrian-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            overflow-y: auto;
            max-height: 350px;
            padding-right: 4px;
        }

        .antrian-list::-webkit-scrollbar {
            width: 6px;
        }

        .antrian-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .antrian-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .antrian-item {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 8px;
            padding: 8px;
            border-left: 4px solid #cbd5e1;
            transition: all 0.3s;
        }

        .antrian-item.urgent {
            border-left-color: #dc2626;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            animation: pulse 2s infinite;
        }

        .antrian-item.high {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        }

        .antrian-item.medium {
            border-left-color: #3b82f6;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        }

        .antrian-item.normal {
            border-left-color: #10b981;
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }

        .antrian-item:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .item-nopol {
            font-size: 15px;
            font-weight: bold;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .priority-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }

        .priority-badge.urgent {
            background: #dc2626;
            color: white;
        }

        .priority-badge.high {
            background: #f59e0b;
            color: white;
        }

        .priority-badge.medium {
            background: #3b82f6;
            color: white;
        }

        .priority-badge.normal {
            background: #10b981;
            color: white;
        }

        .item-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 5px;
            margin-top: 6px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            color: #475569;
        }

        .detail-item i {
            width: 14px;
            color: #64748b;
            font-size: 10px;
        }

        .duration-display {
            margin-top: 6px;
            padding: 6px;
            background: rgba(255,255,255,0.7);
            border-radius: 5px;
            text-align: center;
        }

        .duration-display .time {
            font-size: 14px;
            font-weight: bold;
            color: #1e293b;
        }

        .duration-display .label {
            font-size: 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 2px;
        }

        .no-data {
            text-align: center;
            padding: 20px 15px;
            color: #94a3b8;
        }

        .no-data i {
            font-size: 30px;
            margin-bottom: 8px;
            opacity: 0.5;
        }

        .no-data p {
            font-size: 11px;
        }

        .view-more {
            background: linear-gradient(135deg, #1a6b3a 0%, #2d8f4f 100%);
            color: white;
            padding: 6px;
            border-radius: 6px;
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin-top: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .view-more:hover {
            background: linear-gradient(135deg, #155230 0%, #1a6b3a 100%);
            transform: scale(1.02);
        }

        .carousel-controls {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .carousel-btn {
            background: #64748b;
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }

        .carousel-btn:hover {
            background: #475569;
        }

        .carousel-indicator {
            font-size: 12px;
            color: #64748b;
            padding: 8px 14px;
            background: #f1f5f9;
            border-radius: 6px;
            font-weight: 600;
        }

        /* ========================================
           RESPONSIVE BREAKPOINTS
        ======================================== */

        /* Large Desktop - 1440px+ */
        @media (min-width: 1920px) {
            .main-content {
                padding: 25px;
            }
            
            .bidang-grid {
                gap: 20px;
            }
            
            .header-title h1 {
                font-size: 28px;
            }
        }

        /* Desktop - 1366px to 1440px */
        @media (max-width: 1440px) {
            .bidang-title h2 {
                font-size: 16px;
            }
            
            .stat-number {
                font-size: 18px;
            }
        }

        /* Laptop - 1024px to 1366px */
        @media (max-width: 1366px) {
            .main-content {
                margin-left: 260px;
                padding: 18px;
                width: calc(100% - 260px);
            }

            .bidang-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
            }

            .header-title h1 {
                font-size: 22px;
            }

            .bidang-title h2 {
                font-size: 15px;
            }

            .stat-number {
                font-size: 17px;
            }
        }

        /* Tablet Landscape - 1024px */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                padding-top: 80px;
                width: 100%;
            }

            .content-wrapper {
                height: auto;
                min-height: calc(100vh - 95px);
            }

            .bidang-grid {
                grid-template-columns: repeat(2, 1fr);
                overflow-y: visible;
                max-height: none;
            }

            .header-title h1 {
                font-size: 20px;
            }

            .datetime-display {
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px;
            }

            .bidang-card {
                max-height: none;
            }

            .antrian-list {
                max-height: 400px;
            }
        }

        /* Tablet Portrait - 768px */
        @media (max-width: 768px) {
            .main-content {
                padding: 12px;
                padding-top: 75px;
            }

            .header-container {
                padding: 15px;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .header-title {
                width: 100%;
            }

            .header-title h1 {
                font-size: 18px;
            }

            .datetime-display {
                width: 100%;
                justify-content: center;
                padding: 8px 12px;
            }

            .separator {
                display: none;
            }

            .header-bottom {
                flex-direction: column;
                align-items: stretch;
            }

            .legend-compact {
                width: 100%;
                justify-content: space-between;
            }

            .legend-item-compact {
                font-size: 10px;
            }

            .mode-toggle {
                width: 100%;
            }

            .mode-btn {
                flex: 1;
                text-align: center;
                padding: 10px 15px;
            }

            .bidang-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .bidang-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .item-details {
                grid-template-columns: 1fr;
            }
        }

        /* Mobile - 480px */
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
                padding-top: 70px;
            }

            .header-container {
                padding: 12px;
            }

            .header-title h1 {
                font-size: 16px;
            }

            .header-title i {
                font-size: 20px;
            }

            .datetime-item {
                font-size: 11px;
            }

            .timezone {
                font-size: 9px;
            }

            .legend-item-compact {
                font-size: 9px;
            }

            .legend-color {
                width: 16px;
                height: 16px;
            }

            .mode-btn {
                font-size: 11px;
                padding: 8px 12px;
            }

            .bidang-title h2 {
                font-size: 14px;
            }

            .bidang-count {
                font-size: 11px;
                padding: 4px 10px;
            }

            .stat-number {
                font-size: 15px;
            }

            .stat-label {
                font-size: 8px;
            }

            .item-nopol {
                font-size: 15px;
            }

            .priority-badge {
                font-size: 9px;
                padding: 3px 8px;
            }

            .detail-item {
                font-size: 10px;
            }

            .duration-display .time {
                font-size: 14px;
            }
        }

        /* Very Small Mobile - 360px */
        @media (max-width: 360px) {
            .header-title h1 {
                font-size: 14px;
            }

            .datetime-item {
                font-size: 10px;
            }

            .bidang-title h2 {
                font-size: 13px;
            }

            .stat-number {
                font-size: 14px;
            }

            .item-nopol {
                font-size: 13px;
            }
        }

        /* Landscape Mobile */
        @media (max-width: 768px) and (orientation: landscape) {
            .main-content {
                padding: 8px;
                padding-top: 65px;
            }

            .bidang-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .antrian-list {
                max-height: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="content-wrapper">
            <div class="header-container">
                <div class="header-top">
                    <div class="header-title">
                        <i class="fas fa-tools"></i>
                        <h1>MONITORING JADWAL PERBAIKAN</h1>
                    </div>
                    <div class="datetime-display">
                        <div class="datetime-item">
                            <i class="fas fa-calendar-day"></i>
                            <span id="currentDate"></span>
                        </div>
                        <span class="separator">|</span>
                        <div class="datetime-item">
                            <i class="fas fa-clock"></i>
                            <span id="currentTime"></span>
                        </div>
                        <span class="timezone">WIB</span>
                    </div>
                </div>

                <div class="header-bottom">
                    <div class="legend-compact">
                        <div class="legend-item-compact">
                            <div class="legend-color urgent"></div>
                            <span class="legend-text">URGENT ≥2 hari</span>
                        </div>
                        <div class="legend-item-compact">
                            <div class="legend-color high"></div>
                            <span class="legend-text">PRIORITAS 1-2 hari</span>
                        </div>
                        <div class="legend-item-compact">
                            <div class="legend-color medium"></div>
                            <span class="legend-text">NORMAL 12-24 jam</span>
                        </div>
                        <div class="legend-item-compact">
                            <div class="legend-color normal"></div>
                            <span class="legend-text">BARU <12 jam</span>
                        </div>
                    </div>

                    <div class="mode-toggle">
                        <a href="?mode=top5" class="mode-btn <?= $display_mode == 'top5' ? 'active' : '' ?>">
                            <i class="fas fa-star"></i> TOP 5 Prioritas
                        </a>
                        <a href="?mode=carousel" class="mode-btn <?= $display_mode == 'carousel' ? 'active' : '' ?>">
                            <i class="fas fa-sync"></i> Carousel Auto
                        </a>
                    </div>
                </div>
            </div>

            <div class="bidang-grid">
                <?php foreach ($bidang_list as $bidang): ?>
                    <?php 
                    $data = isset($data_per_bidang[$bidang]) ? $data_per_bidang[$bidang] : [];
                    $icon = getIconBidang($bidang);
                    $stats = hitungStatistik($data);
                    $total_count = $stats['total'];
                    
                    // Sort by priority
                    usort($data, function($a, $b) {
                        $durasi_a = hitungDurasi($a['tgl_disetujui_karu_qc']);
                        $durasi_b = hitungDurasi($b['tgl_disetujui_karu_qc']);
                        $score_a = getPriorityScore($durasi_a['total_jam']);
                        $score_b = getPriorityScore($durasi_b['total_jam']);
                        return $score_b - $score_a;
                    });
                    
                    if ($display_mode == 'top5') {
                        $display_data = array_slice($data, 0, 5);
                        $remaining = $total_count - 5;
                    } else {
                        $display_data = $data;
                    }
                    ?>
                    
                    <div class="bidang-card" data-bidang="<?= htmlspecialchars($bidang) ?>">
                        <div class="bidang-header">
                            <div class="bidang-title">
                                <span class="icon"><?= $icon ?></span>
                                <h2><?= htmlspecialchars($bidang) ?></h2>
                            </div>
                            <div class="bidang-count">
                                <?= $total_count ?> Unit
                            </div>
                        </div>

                        <div class="bidang-stats">
                            <div class="stat-item urgent">
                                <div class="stat-number"><?= $stats['urgent'] ?></div>
                                <div class="stat-label">🚨 Urgent</div>
                            </div>
                            <div class="stat-item high">
                                <div class="stat-number"><?= $stats['high'] ?></div>
                                <div class="stat-label">⚠️ Prioritas</div>
                            </div>
                            <div class="stat-item medium">
                                <div class="stat-number"><?= $stats['medium'] ?></div>
                                <div class="stat-label">⏰ Normal</div>
                            </div>
                            <div class="stat-item normal">
                                <div class="stat-number"><?= $stats['normal'] ?></div>
                                <div class="stat-label">✅ Baru</div>
                            </div>
                        </div>

                        <div class="antrian-list" id="list-<?= md5($bidang) ?>">
                            <?php if ($total_count > 0): ?>
                                <?php if ($display_mode == 'top5'): ?>
                                    <?php foreach ($display_data as $item): ?>
                                        <?php 
                                        $durasi = hitungDurasi($item['tgl_disetujui_karu_qc']);
                                        $priority = getPriorityClass($durasi['total_jam']);
                                        ?>
                                        
                                        <div class="antrian-item <?= $priority ?>">
                                            <div class="item-header">
                                                <div class="item-nopol">
                                                    <i class="fas fa-truck"></i>
                                                    <?= htmlspecialchars($item['nopol']) ?>
                                                </div>
                                                <span class="priority-badge <?= $priority ?>">
                                                    <?php
                                                    switch($priority) {
                                                        case 'urgent': echo '🚨 URGENT'; break;
                                                        case 'high': echo '⚠️ PRIORITAS'; break;
                                                        case 'medium': echo '⏰ NORMAL'; break;
                                                        case 'normal': echo '✅ BARU'; break;
                                                    }
                                                    ?>
                                                </span>
                                            </div>

                                            <div class="item-details">
                                                <div class="detail-item">
                                                    <i class="fas fa-hashtag"></i>
                                                    <span><?= htmlspecialchars($item['nomor_pengajuan']) ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class="fas fa-building"></i>
                                                    <span><?= htmlspecialchars($item['nama_rekanan']) ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class="fas fa-car"></i>
                                                    <span><?= htmlspecialchars($item['jenis_kendaraan']) ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class="fas fa-calendar"></i>
                                                    <span><?= date('d/m/Y H:i', strtotime($item['tgl_disetujui_karu_qc'])) ?></span>
                                                </div>
                                            </div>

                                            <div class="duration-display">
                                                <div class="time">
                                                    <?php if ($durasi['hari'] > 0): ?>
                                                        <?= $durasi['hari'] ?> Hari <?= $durasi['jam'] ?> Jam
                                                    <?php else: ?>
                                                        <?= $durasi['jam'] ?> Jam <?= $durasi['menit'] ?> Menit
                                                    <?php endif; ?>
                                                </div>
                                                <div class="label">Waktu Perbaikan</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if ($remaining > 0): ?>
                                        <div class="view-more">
                                            <i class="fas fa-plus-circle"></i> 
                                            Lihat <?= $remaining ?> Perbaikan Lainnya
                                        </div>
                                    <?php endif; ?>
                                    
                                <?php else: ?>
                                    <?php foreach ($data as $index => $item): ?>
                                        <?php 
                                        $durasi = hitungDurasi($item['tgl_disetujui_karu_qc']);
                                        $priority = getPriorityClass($durasi['total_jam']);
                                        $display_style = $index > 0 ? 'display:none;' : '';
                                        ?>
                                        
                                        <div class="antrian-item carousel-item <?= $priority ?>" style="<?= $display_style ?>" data-index="<?= $index ?>">
                                            <div class="item-header">
                                                <div class="item-nopol">
                                                    <i class="fas fa-truck"></i>
                                                    <?= htmlspecialchars($item['nopol']) ?>
                                                </div>
                                                <span class="priority-badge <?= $priority ?>">
                                                    <?php
                                                    switch($priority) {
                                                        case 'urgent': echo '🚨 URGENT'; break;
                                                        case 'high': echo '⚠️ PRIORITAS'; break;
                                                        case 'medium': echo '⏰ NORMAL'; break;
                                                        case 'normal': echo '✅ BARU'; break;
                                                    }
                                                    ?>
                                                </span>
                                            </div>

                                            <div class="item-details">
                                                <div class="detail-item">
                                                    <i class="fas fa-hashtag"></i>
                                                    <span><?= htmlspecialchars($item['nomor_pengajuan']) ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class="fas fa-building"></i>
                                                    <span><?= htmlspecialchars($item['nama_rekanan']) ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class="fas fa-car"></i>
                                                    <span><?= htmlspecialchars($item['jenis_kendaraan']) ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <i class="fas fa-calendar"></i>
                                                    <span><?= date('d/m/Y H:i', strtotime($item['tgl_disetujui_karu_qc'])) ?></span>
                                                </div>
                                            </div>

                                            <div class="duration-display">
                                                <div class="time">
                                                    <?php if ($durasi['hari'] > 0): ?>
                                                        <?= $durasi['hari'] ?> Hari <?= $durasi['jam'] ?> Jam
                                                    <?php else: ?>
                                                        <?= $durasi['jam'] ?> Jam <?= $durasi['menit'] ?> Menit
                                                    <?php endif; ?>
                                                </div>
                                                <div class="label">Waktu Perbaikan</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if ($total_count > 1): ?>
                                        <div class="carousel-controls">
                                            <button class="carousel-btn" onclick="prevItem('<?= md5($bidang) ?>')">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                            <span class="carousel-indicator">
                                                <span id="current-<?= md5($bidang) ?>">1</span> / <?= $total_count ?>
                                            </span>
                                            <button class="carousel-btn" onclick="nextItem('<?= md5($bidang) ?>')">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-check-circle"></i>
                                    <p>Tidak ada unit dalam perbaikan</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Carousel state per bidang
        const carouselStates = {};
        
        // Initialize carousel states
        document.querySelectorAll('.bidang-card').forEach(card => {
            const bidangHash = card.querySelector('.antrian-list').id.replace('list-', '');
            const items = card.querySelectorAll('.carousel-item');
            if (items.length > 0) {
                carouselStates[bidangHash] = {
                    current: 0,
                    total: items.length
                };
            }
        });

        function showItem(bidangHash, index) {
            const list = document.getElementById('list-' + bidangHash);
            const items = list.querySelectorAll('.carousel-item');
            
            items.forEach((item, i) => {
                item.style.display = i === index ? 'block' : 'none';
            });
            
            const indicator = document.getElementById('current-' + bidangHash);
            if (indicator) {
                indicator.textContent = index + 1;
            }
        }

        function nextItem(bidangHash) {
            if (!carouselStates[bidangHash]) return;
            
            carouselStates[bidangHash].current++;
            if (carouselStates[bidangHash].current >= carouselStates[bidangHash].total) {
                carouselStates[bidangHash].current = 0;
            }
            
            showItem(bidangHash, carouselStates[bidangHash].current);
        }

        function prevItem(bidangHash) {
            if (!carouselStates[bidangHash]) return;
            
            carouselStates[bidangHash].current--;
            if (carouselStates[bidangHash].current < 0) {
                carouselStates[bidangHash].current = carouselStates[bidangHash].total - 1;
            }
            
            showItem(bidangHash, carouselStates[bidangHash].current);
        }

        // Auto carousel - rotate every 5 seconds
        <?php if ($display_mode == 'carousel'): ?>
        setInterval(() => {
            Object.keys(carouselStates).forEach(bidangHash => {
                nextItem(bidangHash);
            });
        }, 5000);
        <?php endif; ?>

        // Fungsi update waktu real-time
        function updateDateTime() {
            const now = new Date();
            
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            
            const dayName = days[now.getDay()];
            const date = now.getDate();
            const month = months[now.getMonth()];
            const year = now.getFullYear();
            
            const dateString = `${dayName}, ${date} ${month} ${year}`;
            
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds}`;
            
            document.getElementById('currentDate').textContent = dateString;
            document.getElementById('currentTime').textContent = timeString;
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);
        
        // Auto refresh halaman setiap 60 detik
        setInterval(function() {
            location.reload();
        }, 60000);
    </script>
</body>
</html>

<?php mysqli_close($connection); ?>
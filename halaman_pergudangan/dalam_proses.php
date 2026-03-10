<?php

include "../inc/config.php";
requireAuth('pergudangan');

// Fokus hanya pada pergudangan
$bidang_target = 'pergudangan';

// Mode tampilan: 'all' atau 'carousel'
$display_mode = isset($_GET['mode']) ? $_GET['mode'] : 'all';

// Query hanya untuk pergudangan
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
  AND k.bidang = 'pergudangan'
ORDER BY pp.tgl_disetujui_karu_qc ASC
";

$result = mysqli_query($connection, $sql);
if (!$result) {
    die(mysqli_error($connection));
}

// Ambil semua data
$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
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

// Fungsi hitung statistik
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

// Sort by priority
usort($data, function($a, $b) {
    $durasi_a = hitungDurasi($a['tgl_disetujui_karu_qc']);
    $durasi_b = hitungDurasi($b['tgl_disetujui_karu_qc']);
    $score_a = getPriorityScore($durasi_a['total_jam']);
    $score_b = getPriorityScore($durasi_b['total_jam']);
    return $score_b - $score_a;
});

$stats = hitungStatistik($data);
$total_count = $stats['total'];

include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring pergudangan</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: rgb(185, 224, 204);
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .main-content {
            margin-left: 20px;
            padding: 15px;
            max-width: 100%;
            min-height: 100vh;
            box-sizing: border-box;
            -webkit-overflow-scrolling: touch;
        }

        .content-wrapper {
            max-width: 100%;
            display: flex;
            flex-direction: column;
            gap: 15px;
            padding-bottom: 20px;
        }

        /* Header Container */
        .header-container {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            flex-shrink: 0;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e5e7eb;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title h1 {
            color: #1a6b3a;
            font-size: 24px;
            font-weight: bold;
        }

        .header-title .icon {
            font-size: 32px;
        }

        .datetime-display {
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, #1a6b3a 0%, #2d8f4f 100%);
            padding: 8px 18px;
            border-radius: 8px;
            color: white;
        }

        .datetime-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        .datetime-item i {
            color: #fbbf24;
        }

        .separator {
            color: rgba(255,255,255,0.5);
            font-weight: bold;
        }

        .timezone {
            background: #f59e0b;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }

        .header-middle {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 12px;
        }

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            flex: 1;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 12px 15px;
            border-radius: 8px;
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .stat-card.total { border-left-color: #6366f1; }
        .stat-card.urgent { border-left-color: #dc2626; }
        .stat-card.high { border-left-color: #f59e0b; }
        .stat-card.medium { border-left-color: #3b82f6; }
        .stat-card.normal { border-left-color: #10b981; }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #1e293b;
            line-height: 1;
        }

        .stat-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 4px;
        }

        .header-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .legend-compact {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .legend-item-compact {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
        }

        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
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
            gap: 10px;
        }

        .mode-btn {
            padding: 8px 16px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            transition: all 0.3s;
            text-decoration: none;
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

        /* Main Data Container */
        .data-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
        }

        .antrian-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 15px;
            padding-right: 10px;
        }

        .antrian-grid::-webkit-scrollbar {
            width: 10px;
        }

        .antrian-grid::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .antrian-grid::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .antrian-item {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 10px;
            padding: 15px;
            border-left: 5px solid #cbd5e1;
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
            50% { opacity: 0.85; }
        }

        .antrian-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .item-nopol {
            font-size: 18px;
            font-weight: bold;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .priority-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
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
            gap: 8px;
            margin-bottom: 12px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #475569;
        }

        .detail-item i {
            width: 16px;
            color: #64748b;
        }

        .duration-display {
            margin-top: 10px;
            padding: 10px;
            background: rgba(255,255,255,0.8);
            border-radius: 8px;
            text-align: center;
        }

        .duration-display .time {
            font-size: 18px;
            font-weight: bold;
            color: #1e293b;
        }

        .duration-display .label {
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 3px;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .no-data i {
            font-size: 64px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .no-data p {
            font-size: 16px;
        }

        /* Carousel Mode */
        .carousel-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            padding: 20px;
            width: 100%;
        }

        .carousel-item-large {
            width: 100%;
            max-width: 800px;
        }

        .carousel-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
        }

        .carousel-btn {
            background: linear-gradient(135deg, #1a6b3a 0%, #2d8f4f 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .carousel-btn:hover {
            background: linear-gradient(135deg, #155230 0%, #1a6b3a 100%);
            transform: scale(1.05);
        }

        .carousel-indicator {
            font-size: 18px;
            color: #1a6b3a;
            padding: 12px 24px;
            background: #f1f5f9;
            border-radius: 8px;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
                padding-top: 85px;
            }

            .stats-summary {
                grid-template-columns: repeat(3, 1fr);
            }

            .antrian-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 10px;
                padding-top: 80px;
            }

            .content-wrapper {
                gap: 12px;
            }

            .header-container {
                padding: 12px;
            }

            .header-top {
                flex-direction: column;
                gap: 10px;
            }

            .header-middle {
                flex-direction: column;
            }

            .stats-summary {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .stat-card {
                padding: 10px;
            }

            .stat-number {
                font-size: 24px;
            }

            .header-bottom {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }

            .legend-compact {
                flex-wrap: wrap;
                gap: 10px;
            }

            .mode-toggle {
                width: 100%;
            }

            .mode-btn {
                flex: 1;
                text-align: center;
            }

            .antrian-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .data-container {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 8px;
                padding-top: 75px;
            }

            .header-title h1 {
                font-size: 16px;
            }

            .datetime-item {
                font-size: 11px;
            }

            .stats-summary {
                grid-template-columns: repeat(2, 1fr);
                gap: 6px;
            }

            .stat-number {
                font-size: 20px;
            }

            .stat-label {
                font-size: 9px;
            }

            .legend-item-compact {
                font-size: 10px;
            }

            .antrian-item {
                padding: 12px;
            }

            .item-nopol {
                font-size: 16px;
            }

            .data-container {
                padding: 12px;
            }

            .carousel-container {
                padding: 10px;
                gap: 15px;
            }

            .carousel-btn {
                padding: 10px 16px;
                font-size: 12px;
            }

            .carousel-indicator {
                font-size: 14px;
                padding: 10px 16px;
            }
        }

        /* Landscape Mobile */
        @media (max-width: 896px) and (orientation: landscape) {
            .main-content {
                padding: 8px;
                padding-top: 70px;
            }

            .header-container {
                padding: 10px;
            }

            .stats-summary {
                grid-template-columns: repeat(5, 1fr);
                gap: 8px;
            }

            .stat-card {
                padding: 8px 10px;
            }

            .antrian-grid {
                grid-template-columns: repeat(2, 1fr);
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
                        <span class="icon">🚜</span>
                        <h1>MONITORING pergudangan</h1>
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

                <div class="header-middle">
                    <div class="stats-summary">
                        <div class="stat-card total">
                            <div class="stat-number"><?= $total_count ?></div>
                            <div class="stat-label">📊 Total Unit</div>
                        </div>
                        <div class="stat-card urgent">
                            <div class="stat-number"><?= $stats['urgent'] ?></div>
                            <div class="stat-label">🚨 Urgent</div>
                        </div>
                        <div class="stat-card high">
                            <div class="stat-number"><?= $stats['high'] ?></div>
                            <div class="stat-label">⚠️ Prioritas</div>
                        </div>
                        <div class="stat-card medium">
                            <div class="stat-number"><?= $stats['medium'] ?></div>
                            <div class="stat-label">⏰ Normal</div>
                        </div>
                        <div class="stat-card normal">
                            <div class="stat-number"><?= $stats['normal'] ?></div>
                            <div class="stat-label">✅ Baru</div>
                        </div>
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
                        <a href="?mode=all" class="mode-btn <?= $display_mode == 'all' ? 'active' : '' ?>">
                            <i class="fas fa-th"></i> Lihat Semua
                        </a>
                        <a href="?mode=carousel" class="mode-btn <?= $display_mode == 'carousel' ? 'active' : '' ?>">
                            <i class="fas fa-sync"></i> Carousel Auto
                        </a>
                    </div>
                </div>
            </div>

            <div class="data-container">
                <?php if ($total_count > 0): ?>
                    <?php if ($display_mode == 'all'): ?>
                        <div class="antrian-grid">
                            <?php foreach ($data as $item): ?>
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
                        </div>
                    <?php else: ?>
                        <div class="carousel-container" id="carouselContainer">
                            <?php foreach ($data as $index => $item): ?>
                                <?php 
                                $durasi = hitungDurasi($item['tgl_disetujui_karu_qc']);
                                $priority = getPriorityClass($durasi['total_jam']);
                                $display_style = $index > 0 ? 'display:none;' : '';
                                ?>
                                
                                <div class="antrian-item carousel-item-large <?= $priority ?>" style="<?= $display_style ?>" data-index="<?= $index ?>">
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

                            <div class="carousel-controls">
                                <button class="carousel-btn" onclick="prevItem()">
                                    <i class="fas fa-chevron-left"></i> Sebelumnya
                                </button>
                                <span class="carousel-indicator">
                                    <span id="currentIndex">1</span> / <?= $total_count ?>
                                </span>
                                <button class="carousel-btn" onclick="nextItem()">
                                    Selanjutnya <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-check-circle"></i>
                        <p>Tidak ada unit dalam perbaikan saat ini</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let currentIndex = 0;
        const totalItems = <?= $total_count ?>;

        function showItem(index) {
            const items = document.querySelectorAll('.carousel-item-large');
            items.forEach((item, i) => {
                item.style.display = i === index ? 'block' : 'none';
            });
            document.getElementById('currentIndex').textContent = index + 1;
        }

        function nextItem() {
            currentIndex++;
            if (currentIndex >= totalItems) {
                currentIndex = 0;
            }
            showItem(currentIndex);
        }

        function prevItem() {
            currentIndex--;
            if (currentIndex < 0) {
                currentIndex = totalItems - 1;
            }
            showItem(currentIndex);
        }

        <?php if ($display_mode == 'carousel' && $total_count > 0): ?>
        // Auto carousel - rotate every 5 seconds
        setInterval(() => {
            nextItem();
        }, 5000);
        <?php endif; ?>

        // Update DateTime
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
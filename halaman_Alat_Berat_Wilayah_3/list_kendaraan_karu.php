<?php
include "../inc/config.php";
requireAuth('alat_berat_wilayah_3');

ob_start();

include "navbar.php";

// Filter pencarian dan status
$search = isset($_GET['search']) ? mysqli_real_escape_string($connection, $_GET['search']) : '';

// Query HANYA untuk status QC (DISETUJUI)
$where = "WHERE p.status = 'QC' AND k.bidang = 'Alat Berat Wilayah 3'";

if ($search != '') {
    $where .= " AND (k.nopol LIKE '%$search%' OR k.jenis_kendaraan LIKE '%$search%' OR k.bidang LIKE '%$search%' OR p.nomor_pengajuan LIKE '%$search%')";
}

$query = "
SELECT 
    k.id_kendaraan, 
    k.nopol, 
    k.jenis_kendaraan, 
    k.bidang, 
    p.keluhan_awal AS keluhan, 
    p.nomor_pengajuan, 
    p.id_permintaan, 
    p.status, 
    p.tgl_pengajuan,
    p.tgl_persetujuan_pengawas,
    p.catatan_qc,
    p.catatan_pengawas,
    p.catatan_sa,
    p.persetujuan_pengawas,
    p.grand_total,
    -- Tentukan asal berdasarkan catatan_sa (LOGIKA BARU)
    CASE 
        WHEN p.catatan_sa IS NULL OR p.catatan_sa = '' THEN 'QC'
        ELSE 'SA'
    END AS badge_asal,
    u.username AS pengaju_nama, 
    u_sa.username AS sa_nama
FROM kendaraan k
INNER JOIN permintaan_perbaikan p ON k.id_kendaraan = p.id_kendaraan
LEFT JOIN users u ON p.id_pengaju = u.id_user
LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
$where
ORDER BY p.tgl_persetujuan_pengawas DESC
";

// Hitung total data disetujui
$count_qc = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='QC' AND k.bidang = 'Alat Berat Wilayah 3'
"));

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Disetujui - KARU QC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">   
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background:rgb(185, 224, 204); min-height: 100vh; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { padding: 30px; }
        @media (max-width: 1023px) { .main-content { margin-left: 0; padding-top: 90px; } }
        
        .card-section { 
            background: rgba(255, 255, 255, 0.98); 
            border-radius: 20px; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
            padding: 25px; 
            backdrop-filter: blur(10px); 
            border: 1px solid rgba(255,255,255,0.2); 
            animation: slideIn 0.5s ease-out; 
        }
        
        @keyframes slideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .filter-section {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-box { 
            position: relative; 
            flex: 1;
            min-width: 250px;
        }
        
        .search-box input { 
            width: 100%; 
            padding: 12px 15px 12px 45px; 
            border-radius: 50px; 
            border: 2px solid #e0e0e0; 
            transition: all 0.3s; 
            font-size: 0.95rem; 
        }
        
        .search-box input:focus { 
            border-color: #667eea; 
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1); 
            outline: none; 
        }
        
        .search-box i { 
            position: absolute; 
            left: 18px; 
            top: 50%; 
            transform: translateY(-50%); 
            color: #667eea; 
            font-size: 1.1rem;
        }
        
        .clear-search { 
            position: absolute; 
            right: 18px; 
            top: 50%; 
            transform: translateY(-50%); 
            cursor: pointer; 
            color: #999; 
            display: none; 
            font-size: 1.2rem;
        }
        
        .clear-search:hover { color: #667eea; }
        
        .table-scroll { 
            overflow-x: auto; 
            overflow-y: auto; 
            max-height: calc(100vh - 330px); 
            border-radius: 12px; 
            border: 1px solid #e0e0e0; 
            margin-top: 15px; 
        }
        
        .table-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); border-radius: 10px; }
        .table-scroll::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #764ba2 0%, #667eea 100%); }
        
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        thead { position: sticky; top: 0; z-index: 10; }
        thead th { 
            background:rgb(9, 120, 83);
            color: white; 
            padding: 14px 12px; 
            font-size: 0.85rem; 
            font-weight: 600; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        tbody tr { 
            border-bottom: 1px solid #f0f0f0; 
            background: white; 
            transition: all 0.3s; 
        }
        
        tbody tr:hover { 
            background: linear-gradient(to right, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05)); 
            transform: scale(1.005); 
        }
        
        tbody td { 
            padding: 12px; 
            font-size: 0.85rem; 
            vertical-align: middle; 
        }
        
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .status-approved { 
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); 
            color: white; 
        }

        .status-wrapper {
            display: flex;
            flex-direction: column;
            gap: 5px;
            align-items: center;
        }

        /* Badge Asal Persetujuan */
        .asal-badge {
            font-size: 0.65rem;
            padding: 3px 8px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            margin-top: 4px;
        }

        .asal-qc {
            background: #ede9fe;
            color: #7c3aed;
            border: 1px solid #c4b5fd;
        }

        .asal-sa {
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #fcd34d;
        }
        
        .btn-action { 
            padding: 7px 14px; 
            border-radius: 8px; 
            font-size: 0.8rem; 
            font-weight: 600; 
            border: none; 
            cursor: pointer; 
            transition: all 0.3s; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            gap: 5px; 
        }
        
        .btn-detail { 
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); 
            color: white; 
        }
        
        .btn-action:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.2); 
        }
        
        .section-title { 
            color: #667eea; 
            font-weight: 700; 
            font-size: 1.5rem; 
            margin-bottom: 20px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding-bottom: 15px; 
            border-bottom: 3px solid #667eea; 
        }
        
        .empty-state { 
            text-align: center; 
            padding: 60px 20px; 
            color: #999; 
        }
        
        .empty-state i { 
            font-size: 4rem; 
            margin-bottom: 20px; 
            opacity: 0.3; 
        }
        
        .alert-notification { 
            position: fixed; 
            top: 90px; 
            right: 30px; 
            min-width: 350px; 
            max-width: 500px; 
            padding: 18px 24px; 
            border-radius: 12px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); 
            z-index: 10000; 
            animation: slideInRight 0.4s ease-out; 
            display: flex; 
            align-items: center; 
            gap: 15px; 
        }
        
        .alert-success { 
            background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%); 
            color: white; 
        }
        
        .alert-error { 
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%); 
            color: white; 
        }
        
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
        
        .stat-card {
            min-width: 250px;
            padding: 25px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            margin-bottom: 20px;
        }
        
        .stat-card i {
            font-size: 3rem;
            opacity: 0.8;
        }
        
        .stat-info {
            display: flex;
            flex-direction: column;
        }
        
        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
        }

        /* Link kembali ke halaman QC */
        .btn-link-back {
            padding: 12px 24px;
            border-radius: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-link-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>

<?php if ($success_message): ?>
<div class="alert-notification alert-success" id="alertNotification">
    <i class="fas fa-check-circle text-2xl"></i>
    <div style="flex: 1;">
        <strong style="display: block; margin-bottom: 4px;">Berhasil!</strong>
        <p style="margin: 0; font-size: 0.9rem;"><?= htmlspecialchars($success_message) ?></p>
    </div>
    <button onclick="closeAlert()" style="background: transparent; border: none; color: white; cursor: pointer; font-size: 1.5rem;">&times;</button>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert-notification alert-error" id="alertNotification">
    <i class="fas fa-exclamation-circle text-2xl"></i>
    <div style="flex: 1;">
        <strong style="display: block; margin-bottom: 4px;">Gagal!</strong>
        <p style="margin: 0; font-size: 0.9rem;"><?= htmlspecialchars($error_message) ?></p>
    </div>
    <button onclick="closeAlert()" style="background: transparent; border: none; color: white; cursor: pointer; font-size: 1.5rem;">&times;</button>
</div>
<?php endif; ?>

<div class="main-content">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: rgb(9, 120, 83); font-size: 2.5rem; font-weight: 700; margin-bottom: 10px;">
            <i class="fas fa-check-double"></i> Karu - Monitoring
        </h1>
       
    </div>

    <div class="card-section">
        <h2 class="section-title">
            <i class="fas fa-check-circle"></i> Daftar Pengajuan yang Sudah Disetujui 
        </h2>

        <!-- Ringkasan Statistik -->
        <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <div class="stat-info">
                <span class="stat-label">Total Pengajuan Disetujui</span>
                <span class="stat-value"><?= $count_qc ?></span>
            </div>
        </div>

        <!-- Filter dan Pencarian -->
        <div class="filter-section">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Nomer Asset, Jenis, Bidang, atau No Pengajuan..." oninput="handleSearch(this.value)">
                <i class="fas fa-times-circle clear-search" id="clearSearch" onclick="clearSearch()" style="<?= $search ? 'display: block;' : '' ?>"></i>
            </div>
        </div>

        <!-- Tabel Data Disetujui -->
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>No Pengajuan</th>
                        <th>Nomor Asset</th>
                        <th>Jenis Kendaraan</th>
                        <th>Bidang</th>
                        <th>Keluhan</th>
                        <th>Status</th>
                        <th>Tanggal Disetujui</th>
                        <th>Grand Total</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $result = mysqli_query($connection, $query);
                if (mysqli_num_rows($result) > 0):
                    while ($data = mysqli_fetch_assoc($result)):
                        $tanggal_display = !empty($data['tgl_persetujuan_pengawas']) ? date('d/m/Y H:i', strtotime($data['tgl_persetujuan_pengawas'])) : '-';
                        $badge_asal = $data['badge_asal'];
                ?>
                    <tr>
                        <td><span style="font-family: monospace; font-weight: 600; color: #667eea;"><?= htmlspecialchars($data['nomor_pengajuan']) ?></span></td>
                        <td><strong><?= htmlspecialchars($data['nopol']) ?></strong></td>
                        <td><?= htmlspecialchars($data['jenis_kendaraan']) ?></td>
                        <td style="white-space: nowrap;">
                            <span style="background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; display: inline-block; white-space: nowrap;">
                                <?= htmlspecialchars($data['bidang']) ?>
                            </span>
                        </td>
                        <td style="max-width: 200px;">
                            <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($data['keluhan']) ?>">
                                <?= htmlspecialchars($data['keluhan']) ?>
                            </div>
                        </td>
                        <td style="text-align: center;">
                            <div class="status-wrapper">
                                <span class="status-badge status-approved">
                                    <i class="fas fa-check-circle"></i> DISETUJUI
                                </span>
                                
                                <!-- BADGE ASAL PERSETUJUAN -->
                                <span class="asal-badge asal-<?= strtolower($badge_asal) ?>">
                                    <i class="fas fa-<?= $badge_asal == 'QC' ? 'user-tie' : 'user-cog' ?>"></i>
                                    PERSETUJUAN DARI <?= $badge_asal ?>
                                </span>
                            </div>
                        </td>
                        <td style="white-space: nowrap;"><?= $tanggal_display ?></td>
                        <td style="text-align: right; font-weight: 600; color: #059669;">
                            <?= $data['grand_total'] ? 'Rp ' . number_format($data['grand_total'], 0, ',', '.') : '-' ?>
                        </td>
                        <td style="text-align: center;">
                            <a href="karu_approval.php?id=<?= $data['id_permintaan'] ?>" 
                               class="btn-action btn-detail"
                               title="Lihat Detail">
                                <i class="fas fa-eye"></i> Detail
                            </a>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p style="font-size: 1.1rem; margin-top: 10px;">
                                    <?= $search ? 'Data tidak ditemukan dengan kata kunci yang dicari' : 'Belum ada data pengajuan yang disetujui' ?>
                                </p>
                                <?php if ($search): ?>
                                <button onclick="clearSearch()" style="margin-top: 15px; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 50px; cursor: pointer; font-weight: 600;">
                                    <i class="fas fa-times-circle"></i> Hapus Pencarian
                                </button>
                                <?php endif; ?>
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
function closeAlert() {
    const alert = document.getElementById('alertNotification');
    if (alert) {
        alert.style.animation = 'slideOutRight 0.4s ease-out';
        setTimeout(() => alert.remove(), 400);
    }
}

window.addEventListener('DOMContentLoaded', function() {
    const alert = document.getElementById('alertNotification');
    if (alert) {
        setTimeout(() => closeAlert(), 5000);
    }
});

let searchTimeout;
function handleSearch(value) {
    const clearBtn = document.getElementById('clearSearch');
    clearBtn.style.display = value ? 'block' : 'none';
    
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        if (value) {
            window.location.href = 'list_kendaraan_disetujui.php?search=' + encodeURIComponent(value);
        } else {
            window.location.href = 'list_kendaraan_disetujui.php';
        }
    }, 800);
}

function clearSearch() {
    window.location.href = 'list_kendaraan_disetujui.php';
}
</script>

</body>
</html>
<?php
ob_end_flush();
?>
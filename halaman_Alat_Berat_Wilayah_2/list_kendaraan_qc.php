<?php
include "../inc/config.php";
requireAuth('alat_berat_wilayah_2');

ob_start();

include "navbar.php";

/* =========================
   KEMBALIKAN KE SA (INPUT CATATAN)
========================= */
if (isset($_POST['kembalikan_sa'])) {
    $id_permintaan = mysqli_real_escape_string($connection, $_POST['id_permintaan']);
    $catatan = strtoupper(trim(mysqli_real_escape_string($connection, $_POST['catatan']))); // Auto uppercase
    
    if (empty($catatan)) {
        $_SESSION['error_message'] = "Catatan QC harus diisi!";
        header('Location: ' . url_with_tab('list_kendaraan_qc.php'));
        exit;
    }
    
    $update = mysqli_query($connection, "
        UPDATE permintaan_perbaikan 
        SET status = 'Dikembalikan_sa',
            catatan_qc = '$catatan',
            tgl_dikembalikan = NOW()
        WHERE id_permintaan = '$id_permintaan'
    ");
    
    if ($update) {
        $_SESSION['success_message'] = "Pengajuan berhasil dikembalikan ke SA dengan catatan!";
    } else {
        $_SESSION['error_message'] = "Gagal mengembalikan pengajuan!";
    }
    
    header('Location: ' . url_with_tab('list_kendaraan_qc.php'));
    exit;
}

/* =========================
   MINTA PERSETUJUAN PENGAWAS
========================= */
if (isset($_POST['minta_persetujuan_pengawas'])) {
    $id_permintaan = mysqli_real_escape_string($connection, $_POST['id_permintaan']);
    $catatan = strtoupper(trim(mysqli_real_escape_string($connection, $_POST['catatan']))); // Auto uppercase
    
    if (empty($catatan)) {
        $_SESSION['error_message'] = "Catatan QC untuk pengawas harus diisi!";
        header('Location: ' . url_with_tab('list_kendaraan_qc.php'));
        exit;
    }
    
    $update = mysqli_query($connection, "
        UPDATE permintaan_perbaikan 
        SET status = 'Minta_Persetujuan_Pengawas',
            persetujuan_pengawas = 'Menunggu',
            catatan_qc = '$catatan',
            catatan_pengawas = '$catatan',
            tgl_persetujuan_pengawas = NOW()
        WHERE id_permintaan = '$id_permintaan'
    ");
    
    if ($update) {
        $_SESSION['success_message'] = "Pengajuan berhasil dikirim ke Pengawas untuk persetujuan!";
    } else {
        $_SESSION['error_message'] = "Gagal mengirim pengajuan ke Pengawas!";
    }
    
    header('Location: ' . url_with_tab('list_kendaraan_qc.php'));
    exit;
}

// Filter pencarian dan status
$search = isset($_GET['search']) ? mysqli_real_escape_string($connection, $_GET['search']) : '';
$filter_status = isset($_GET['status']) ? mysqli_real_escape_string($connection, $_GET['status']) : '';

// Query HANYA untuk status KARU QC (TANPA STATUS QC/DISETUJUI)
$where = "WHERE (p.status IN ('Diperiksa_SA', 'Disetujui_KARU_QC') 
          OR (p.status = 'Minta_Persetujuan_Pengawas' AND p.persetujuan_pengawas = 'Menunggu'))
          AND k.bidang = 'Alat Berat Wilayah 2'";

if ($search != '') {
    $where .= " AND (k.nopol LIKE '%$search%' OR k.jenis_kendaraan LIKE '%$search%' OR k.bidang LIKE '%$search%' OR p.nomor_pengajuan LIKE '%$search%')";
}

// Filter khusus untuk status
if ($filter_status != '') {
    if ($filter_status == 'Menunggu_Pengawas') {
        $where = "WHERE p.status = 'Minta_Persetujuan_Pengawas' AND p.persetujuan_pengawas = 'Menunggu' AND k.bidang = 'Alat Berat Wilayah 2'";
        if ($search != '') {
            $where .= " AND (k.nopol LIKE '%$search%' OR k.jenis_kendaraan LIKE '%$search%' OR k.bidang LIKE '%$search%' OR p.nomor_pengajuan LIKE '%$search%')";
        }
    } else {
        $where = "WHERE p.status = '$filter_status' AND k.bidang = 'Alat Berat Wilayah 2'";
        if ($search != '') {
            $where .= " AND (k.nopol LIKE '%$search%' OR k.jenis_kendaraan LIKE '%$search%' OR k.bidang LIKE '%$search%' OR p.nomor_pengajuan LIKE '%$search%')";
        }
    }
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
    p.tgl_diperiksa_sa,
    p.tgl_disetujui_karu_qc,
    p.tgl_dikembalikan,
    p.tgl_persetujuan_pengawas,
    p.catatan_qc,
    p.catatan_pengawas,
    p.catatan_sa,
    p.persetujuan_pengawas,
    p.grand_total,
    u.username AS pengaju_nama, 
    u_sa.username AS sa_nama
FROM kendaraan k
INNER JOIN permintaan_perbaikan p ON k.id_kendaraan = p.id_kendaraan
LEFT JOIN users u ON p.id_pengaju = u.id_user
LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
$where
ORDER BY 
    CASE 
        WHEN p.status = 'Diperiksa_SA' THEN 1
        WHEN p.status = 'Disetujui_KARU_QC' THEN 2
        WHEN p.status = 'Minta_Persetujuan_Pengawas' AND p.persetujuan_pengawas = 'Menunggu' THEN 3
    END,
    p.tgl_diperiksa_sa DESC
";

// Hitung jumlah per status
$count_diperiksa = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Diperiksa_SA' AND k.bidang = 'Alat Berat Wilayah 2'
"));

$count_disetujui_karu = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Disetujui_KARU_QC' AND k.bidang = 'Alat Berat Wilayah 2'
"));

$count_menunggu_pengawas = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Minta_Persetujuan_Pengawas' AND p.persetujuan_pengawas='Menunggu' AND k.bidang = 'Alat Berat Wilayah 2'
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
    <title>KARU QC - Monitoring Status</title>
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
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 10px 20px;
            border-radius: 50px;
            border: 2px solid #e0e0e0;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        
        .filter-btn.status-diperiksa.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #10b981;
        }
        
        .filter-btn.status-disetujui.active {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            border-color: #fbbf24;
        }

        .filter-btn.status-menunggu.active {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-color: #3b82f6;
        }
        
        .badge-count {
            background: rgba(255,255,255,0.3);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        
        .table-scroll { 
            overflow-x: auto; 
            overflow-y: auto; 
            max-height: calc(100vh - 380px); 
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
        
        .status-diperiksa { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            color: white; 
        }
        
        .status-disetujui { 
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); 
            color: white; 
        }

        .status-menunggu { 
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); 
            color: white; 
        }

        .status-wrapper {
            display: flex;
            flex-direction: column;
            gap: 5px;
            align-items: center;
        }

        .pengawas-name {
            font-size: 0.7rem;
            color: #3b82f6;
            font-weight: 600;
            background: #dbeafe;
            padding: 3px 10px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
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
        
        .btn-return { 
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
            color: white; 
        }

        .btn-approve { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
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
        
        .stats-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            flex: 1;
            min-width: 180px;
            padding: 20px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .stat-card.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .stat-card.yellow {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        }

        .stat-card.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        
        .stat-info {
            display: flex;
            flex-direction: column;
        }
        
        .stat-label {
            font-size: 0.75rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9998;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            z-index: 9999;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-container.active {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, -50%) scale(1);
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .modal-header h3 {
            color: #667eea;
            font-size: 1.5rem;
            font-weight: 700;
            flex: 1;
        }

        .modal-close {
            background: #f0f0f0;
            border: none;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: #e0e0e0;
            transform: rotate(90deg);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 0.9rem;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
            transition: all 0.3s;
            text-transform: uppercase;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .catatan-sebelumnya {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .catatan-sebelumnya h4 {
            color: #d97706;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .catatan-sebelumnya p {
            color: #92400e;
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .btn-modal {
            padding: 10px 25px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-cancel {
            background: #f0f0f0;
            color: #666;
        }

        .btn-cancel:hover {
            background: #e0e0e0;
        }

        .btn-submit {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        }

        .btn-submit-approve {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-submit-approve:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        /* Link ke halaman Disetujui */
        .btn-link-disetujui {
            padding: 12px 24px;
            border-radius: 50px;
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }

        .btn-link-disetujui:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }
    </style>
</head>
<body>

<!-- Modal Kembalikan ke SA -->
<div class="modal-overlay" id="modalOverlay"></div>
<div class="modal-container" id="modalKembalikan">
    <div class="modal-header">
        <i class="fas fa-undo-alt" style="color: #f59e0b; font-size: 1.5rem;"></i>
        <h3>Kembalikan ke SA</h3>
        <button type="button" class="modal-close" onclick="closeModal()">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <form method="POST" action="" id="formKembalikan">
        <input type="hidden" name="id_permintaan" id="modal_id_permintaan_kembalikan">
        <input type="hidden" name="kembalikan_sa" value="1">
        
        <!-- Tampilkan catatan sebelumnya jika ada -->
        <div id="catatan_sebelumnya_kembalikan" style="display: none;">
            <div class="catatan-sebelumnya">
                <h4>
                    <i class="fas fa-history"></i> Catatan QC Sebelumnya:
                </h4>
                <p id="text_catatan_sebelumnya_kembalikan"></p>
            </div>
        </div>

        <div class="form-group">
            <label for="catatan_kembalikan">
                <i class="fas fa-comment-dots"></i> Catatan QC <span style="color: red;">*</span>
            </label>
            <textarea 
                name="catatan" 
                id="catatan_kembalikan" 
                placeholder="TULISKAN CATATAN UNTUK SA (MISAL: DATA KURANG LENGKAP, HARGA TERLALU TINGGI, DLL)"
                required
                oninput="this.value = this.value.toUpperCase()"></textarea>
            <small style="color: #999; font-size: 0.8rem;">
                <i class="fas fa-info-circle"></i> Catatan ini akan dilihat oleh SA. Anda dapat mengedit atau menambahi catatan sebelumnya.
            </small>
        </div>
        
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-cancel" onclick="closeModal()">
                <i class="fas fa-times"></i> Batal
            </button>
            <button type="submit" class="btn-modal btn-submit">
                <i class="fas fa-paper-plane"></i> Kirim ke SA
            </button>
        </div>
    </form>
</div>

<!-- Modal Minta Persetujuan Pengawas -->
<div class="modal-container" id="modalPersetujuan">
    <div class="modal-header">
        <i class="fas fa-user-check" style="color: #10b981; font-size: 1.5rem;"></i>
        <h3>Minta Persetujuan Pengawas</h3>
        <button type="button" class="modal-close" onclick="closeModal()">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <form method="POST" action="" id="formPersetujuan">
        <input type="hidden" name="id_permintaan" id="modal_id_permintaan_persetujuan">
        <input type="hidden" name="minta_persetujuan_pengawas" value="1">
        
        <!-- Tampilkan catatan sebelumnya jika ada -->
        <div id="catatan_sebelumnya_persetujuan" style="display: none;">
            <div class="catatan-sebelumnya">
                <h4>
                    <i class="fas fa-history"></i> Catatan QC Sebelumnya:
                </h4>
                <p id="text_catatan_sebelumnya_persetujuan"></p>
            </div>
        </div>

        <div class="form-group">
            <label for="catatan_persetujuan">
                <i class="fas fa-comment-dots"></i> Catatan QC untuk Pengawas <span style="color: red;">*</span>
            </label>
            <textarea 
                name="catatan" 
                id="catatan_persetujuan" 
                placeholder="TULISKAN CATATAN UNTUK PENGAWAS (MISAL: SUDAH DIPERIKSA DAN SESUAI, MOHON PERSETUJUAN, DLL)"
                required
                oninput="this.value = this.value.toUpperCase()"></textarea>
            <small style="color: #999; font-size: 0.8rem;">
                <i class="fas fa-info-circle"></i> Catatan ini akan dilihat oleh Pengawas. Anda dapat mengedit atau menambahi catatan sebelumnya.
            </small>
        </div>
        
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-cancel" onclick="closeModal()">
                <i class="fas fa-times"></i> Batal
            </button>
            <button type="submit" class="btn-modal btn-submit-approve">
                <i class="fas fa-paper-plane"></i> Kirim ke Pengawas
            </button>
        </div>
    </form>
</div>

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
            <i class="fas fa-clipboard-check"></i> QC - Monitoring
        </h1>
        
    </div>

    <div class="card-section">
        <h2 class="section-title">
            <i class="fas fa-list-check"></i> Daftar Pengajuan KARU & QC
        </h2>

        <!-- Ringkasan Statistik -->
        <div class="stats-summary">
            <div class="stat-card green">
                <i class="fas fa-check-circle"></i>
                <div class="stat-info">
                    <span class="stat-label">DIPERIKSA SA</span>
                    <span class="stat-value"><?= $count_diperiksa ?></span>
                </div>
            </div>
            <div class="stat-card yellow">
                <i class="fas fa-check-double"></i>
                <div class="stat-info">
                    <span class="stat-label">DIPERIKSA QC</span>
                    <span class="stat-value"><?= $count_disetujui_karu ?></span>
                </div>
            </div>
            <div class="stat-card blue">
                <i class="fas fa-clock"></i>
                <div class="stat-info">
                    <span class="stat-label">MENUNGGU PENGAWAS</span>
                    <span class="stat-value"><?= $count_menunggu_pengawas ?></span>
                </div>
            </div>
        </div>

        <!-- Filter dan Pencarian -->
        <div class="filter-section">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Nomer Asset, Jenis, Bidang, atau No Pengajuan..." oninput="handleSearch(this.value)">
                <i class="fas fa-times-circle clear-search" id="clearSearch" onclick="clearSearch()" style="<?= $search ? 'display: block;' : '' ?>"></i>
            </div>
            
            <div class="filter-buttons">
                <button class="filter-btn <?= $filter_status == '' ? 'active' : '' ?>" onclick="filterStatus('')">
                    <i class="fas fa-list"></i> Semua
                </button>
                <button class="filter-btn status-diperiksa <?= $filter_status == 'Diperiksa_SA' ? 'active' : '' ?>" onclick="filterStatus('Diperiksa_SA')">
                    <i class="fas fa-check-circle"></i> Diperiksa SA <span class="badge-count"><?= $count_diperiksa ?></span>
                </button>
                <button class="filter-btn status-disetujui <?= $filter_status == 'Disetujui_KARU_QC' ? 'active' : '' ?>" onclick="filterStatus('Disetujui_KARU_QC')">
                    <i class="fas fa-check-double"></i> Diperiksa QC <span class="badge-count"><?= $count_disetujui_karu ?></span>
                </button>
                <button class="filter-btn status-menunggu <?= $filter_status == 'Menunggu_Pengawas' ? 'active' : '' ?>" onclick="filterStatus('Menunggu_Pengawas')">
                    <i class="fas fa-clock"></i> Menunggu Pengawas <span class="badge-count"><?= $count_menunggu_pengawas ?></span>
                </button>
            </div>
        </div>

        <!-- Tabel Gabungan -->
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
                        <th>Tanggal</th>
                        <th>Grand Total</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $result = mysqli_query($connection, $query);
                if (mysqli_num_rows($result) > 0):
                    while ($data = mysqli_fetch_assoc($result)):
                        // Tentukan tanggal yang relevan berdasarkan status
                        $tanggal_display = '';
                        if ($data['status'] == 'Diperiksa_SA') {
                            $tanggal_display = !empty($data['tgl_diperiksa_sa']) ? date('d/m/Y H:i', strtotime($data['tgl_diperiksa_sa'])) : '-';
                        } elseif ($data['status'] == 'Disetujui_KARU_QC') {
                            $tanggal_display = !empty($data['tgl_disetujui_karu_qc']) ? date('d/m/Y H:i', strtotime($data['tgl_disetujui_karu_qc'])) : '-';
                        } elseif ($data['status'] == 'Minta_Persetujuan_Pengawas') {
                            $tanggal_display = !empty($data['tgl_persetujuan_pengawas']) ? date('d/m/Y H:i', strtotime($data['tgl_persetujuan_pengawas'])) : '-';
                        }
                        
                        // Status badge class
                        $status_class = '';
                        $status_text = '';
                        $status_icon = '';
                        
                        if ($data['status'] == 'Diperiksa_SA') {
                            $status_class = 'status-diperiksa';
                            $status_text = 'Diperiksa SA';
                            $status_icon = 'fa-check-circle';
                        } elseif ($data['status'] == 'Disetujui_KARU_QC') {
                            $status_class = 'status-disetujui';
                            $status_text = 'DIPERIKSA QC';
                            $status_icon = 'fa-check-double';
                        } elseif ($data['status'] == 'Minta_Persetujuan_Pengawas') {
                            if ($data['persetujuan_pengawas'] == 'Menunggu') {
                                $status_class = 'status-menunggu';
                                $status_text = 'Menunggu Pengawas';
                                $status_icon = 'fa-clock';
                            }
                        }
                        
                        // Escape catatan_qc untuk JavaScript
                        $catatan_qc_escaped = htmlspecialchars($data['catatan_qc'] ?? '', ENT_QUOTES);
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
                                <span class="status-badge <?= $status_class ?>">
                                    <i class="fas <?= $status_icon ?>"></i> <?= $status_text ?>
                                </span>
                                
                                <?php if ($data['status'] == 'Minta_Persetujuan_Pengawas' && $data['persetujuan_pengawas'] == 'Menunggu'): ?>
                                    <span class="pengawas-name">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($data['pengaju_nama']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="white-space: nowrap;"><?= $tanggal_display ?></td>
                        <td style="text-align: right; font-weight: 600; color: #059669;">
                            <?= $data['grand_total'] ? 'Rp ' . number_format($data['grand_total'], 0, ',', '.') : '-' ?>
                        </td>
                        <td style="text-align: center;">
                            <div style="display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;">
                                <a href="karu_qc_approval.php?id=<?= $data['id_permintaan'] ?>" 
                                   class="btn-action btn-detail"
                                   title="Lihat Detail">
                                    <i class="fas fa-eye"></i> Detail
                                </a>
                                
                                <?php if ($data['status'] == 'Disetujui_KARU_QC'): ?>
                                    <!-- Tombol Kembalikan ke SA -->
                                    <button type="button"
                                            class="btn-action btn-return"
                                            onclick='openModalKembalikan(<?= $data['id_permintaan'] ?>, "<?= htmlspecialchars($data['nomor_pengajuan'], ENT_QUOTES) ?>", "<?= $catatan_qc_escaped ?>")'
                                            title="Kembalikan ke SA">
                                        <i class="fas fa-undo-alt"></i> Ke SA
                                    </button>
                                    
                                    <!-- Tombol Minta Persetujuan Pengawas -->
                                    <button type="button"
                                            class="btn-action btn-approve"
                                            onclick='openModalPersetujuan(<?= $data['id_permintaan'] ?>, "<?= htmlspecialchars($data['nomor_pengajuan'], ENT_QUOTES) ?>", "<?= $catatan_qc_escaped ?>")'
                                            title="Minta Persetujuan Pengawas">
                                        <i class="fas fa-user-check"></i> Ke Pengawas
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p style="font-size: 1.1rem; margin-top: 10px;">
                                    <?= $search || $filter_status ? 'Data tidak ditemukan dengan filter yang dipilih' : 'Belum ada data di KARU QC' ?>
                                </p>
                                <?php if ($search || $filter_status): ?>
                                <button onclick="clearAllFilters()" style="margin-top: 15px; padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 50px; cursor: pointer; font-weight: 600;">
                                    <i class="fas fa-times-circle"></i> Hapus Filter
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
        const currentParams = new URLSearchParams(window.location.search);
        if (value) {
            currentParams.set('search', value);
        } else {
            currentParams.delete('search');
        }
        window.location.href = 'list_kendaraan_qc.php?' + currentParams.toString();
    }, 800);
}

function clearSearch() {
    const currentParams = new URLSearchParams(window.location.search);
    currentParams.delete('search');
    window.location.href = 'list_kendaraan_qc.php?' + currentParams.toString();
}

function filterStatus(status) {
    const currentParams = new URLSearchParams(window.location.search);
    if (status) {
        currentParams.set('status', status);
    } else {
        currentParams.delete('status');
    }
    window.location.href = 'list_kendaraan_qc.php?' + currentParams.toString();
}

function clearAllFilters() {
    window.location.href = 'list_kendaraan_qc.php';
}

// Modal functions untuk Kembalikan ke SA
function openModalKembalikan(idPermintaan, nomorPengajuan, catatanSebelumnya) {
    document.getElementById('modal_id_permintaan_kembalikan').value = idPermintaan;
    
    if (catatanSebelumnya && catatanSebelumnya.trim() !== '') {
        const catatanUpper = catatanSebelumnya.toUpperCase();
        document.getElementById('catatan_sebelumnya_kembalikan').style.display = 'block';
        document.getElementById('text_catatan_sebelumnya_kembalikan').textContent = catatanUpper;
        document.getElementById('catatan_kembalikan').value = catatanUpper;
    } else {
        document.getElementById('catatan_sebelumnya_kembalikan').style.display = 'none';
        document.getElementById('catatan_kembalikan').value = '';
    }
    
    const overlay = document.getElementById('modalOverlay');
    const modal = document.getElementById('modalKembalikan');
    
    overlay.classList.add('active');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Modal functions untuk Minta Persetujuan Pengawas
function openModalPersetujuan(idPermintaan, nomorPengajuan, catatanSebelumnya) {
    document.getElementById('modal_id_permintaan_persetujuan').value = idPermintaan;
    
    if (catatanSebelumnya && catatanSebelumnya.trim() !== '') {
        const catatanUpper = catatanSebelumnya.toUpperCase();
        document.getElementById('catatan_sebelumnya_persetujuan').style.display = 'block';
        document.getElementById('text_catatan_sebelumnya_persetujuan').textContent = catatanUpper;
        document.getElementById('catatan_persetujuan').value = catatanUpper;
    } else {
        document.getElementById('catatan_sebelumnya_persetujuan').style.display = 'none';
        document.getElementById('catatan_persetujuan').value = '';
    }
    
    const overlay = document.getElementById('modalOverlay');
    const modal = document.getElementById('modalPersetujuan');
    
    overlay.classList.add('active');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    const overlay = document.getElementById('modalOverlay');
    const modals = document.querySelectorAll('.modal-container');
    
    overlay.classList.remove('active');
    modals.forEach(modal => modal.classList.remove('active'));
    document.body.style.overflow = 'auto';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

document.getElementById('modalOverlay').addEventListener('click', function() {
    closeModal();
});

document.querySelectorAll('.modal-container').forEach(modal => {
    modal.addEventListener('click', function(e) {
        e.stopPropagation();
    });
});
</script>

</body>
</html>
<?php
ob_end_flush();
?>
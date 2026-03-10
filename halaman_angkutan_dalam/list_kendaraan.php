<?php
// ✅ ob_start HARUS di baris PERTAMA — sebelum apapun
ob_start();

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";

requireAuth('angkutan_dalam');

// ============================================================
// PROSES POST/GET — SEBELUM NAVBAR
// ============================================================

// PROSES KIRIM PERSETUJUAN KE PENGAWAS
if (isset($_POST['kirim_persetujuan'])) {
    $id_permintaan = mysqli_real_escape_string($connection, $_POST['id_permintaan']);
    $catatan_sa = mysqli_real_escape_string($connection, $_POST['catatan_sa']);
    
    $result = mysqli_query($connection, "
        UPDATE permintaan_perbaikan 
        SET persetujuan_pengawas = 'Menunggu',
            tgl_persetujuan_pengawas = NOW(),
            catatan_sa = '$catatan_sa'
        WHERE id_permintaan = '$id_permintaan'
    ");
    
    if ($result) {
        $_SESSION['success_message'] = "Permintaan persetujuan berhasil dikirim ke Pengawas!";
    } else {
        $_SESSION['error_message'] = "Gagal mengirim permintaan persetujuan!";
    }
    
    ob_end_clean();
    header('Location: ' . url_with_tab('list_kendaraan.php'));
    exit;
}

// PROSES EDIT CATATAN SA (JIKA DITOLAK)
if (isset($_POST['edit_catatan_sa'])) {
    $id_permintaan = mysqli_real_escape_string($connection, $_POST['id_permintaan']);
    $catatan_sa = mysqli_real_escape_string($connection, $_POST['catatan_sa_edit']);
    
    $result = mysqli_query($connection, "
        UPDATE permintaan_perbaikan 
        SET persetujuan_pengawas = 'Menunggu',
            tgl_persetujuan_pengawas = NOW(),
            catatan_sa = '$catatan_sa',
            catatan_pengawas = NULL
        WHERE id_permintaan = '$id_permintaan'
    ");
    
    if ($result) {
        $_SESSION['success_message'] = "Catatan berhasil diperbarui dan dikirim ulang ke Pengawas!";
    } else {
        $_SESSION['error_message'] = "Gagal memperbarui catatan!";
    }
    
    ob_end_clean();
    header('Location: ' . url_with_tab('list_kendaraan.php'));
    exit;
}

if (isset($_GET['hapus'])) {
    $id_permintaan = mysqli_real_escape_string($connection, $_GET['hapus']);
    $result = mysqli_query($connection, "DELETE FROM permintaan_perbaikan WHERE id_permintaan='$id_permintaan'");
    
    if ($result) {
        $_SESSION['success_message'] = "Pengajuan perbaikan berhasil dihapus!";
    } else {
        $_SESSION['error_message'] = "Gagal menghapus pengajuan perbaikan!";
    }
    
    ob_end_clean();
    header('Location: ' . url_with_tab('list_kendaraan.php'));
    exit;
}

// ============================================================
// NAVBAR — di-include SETELAH logika redirect selesai
// ============================================================
include "navbar.php";

// ============================================================
// QUERY & FILTER
// ============================================================

$search = isset($_GET['search']) ? mysqli_real_escape_string($connection, $_GET['search']) : '';
$filter_status = isset($_GET['status']) ? mysqli_real_escape_string($connection, $_GET['status']) : '';

$where = "WHERE p.status IN ('Diajukan', 'Diperiksa_SA', 'Dikembalikan_sa') AND k.bidang = 'ANGKUTAN DALAM'";

if ($search != '') {
    $where .= " AND (k.nopol LIKE '%$search%' OR k.jenis_kendaraan LIKE '%$search%' OR k.bidang LIKE '%$search%' OR p.nomor_pengajuan LIKE '%$search%')";
}

if ($filter_status != '') {
    if ($filter_status == 'Disetujui_Pengawas') {
        $where .= " AND p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas = 'Disetujui'";
    } elseif ($filter_status == 'Ditolak_Pengawas') {
        $where .= " AND p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas = 'Ditolak'";
    } elseif ($filter_status == 'Menunggu_Persetujuan') {
        $where .= " AND p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas = 'Menunggu'";
    } elseif ($filter_status == 'Belum_Kirim') {
        $where .= " AND p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas IS NULL";
    } else {
        $where .= " AND p.status = '$filter_status'";
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
    p.tgl_dikembalikan,
    p.grand_total,
    p.persetujuan_pengawas,
    p.catatan_pengawas,
    p.catatan_sa,
    p.catatan_qc,
    p.keterangan_kembalikan,
    p.tgl_persetujuan_pengawas,
    u.username AS pengaju_nama, 
    u_sa.username AS sa_nama
FROM kendaraan k
INNER JOIN permintaan_perbaikan p ON k.id_kendaraan = p.id_kendaraan
LEFT JOIN users u ON p.id_pengaju = u.id_user
LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
$where
ORDER BY 
    CASE 
        WHEN p.status = 'Diajukan' THEN 1
        WHEN p.status = 'Diperiksa_SA' THEN 2
        WHEN p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas IS NULL THEN 3
        WHEN p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas = 'Ditolak' THEN 4
        WHEN p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas = 'Menunggu' THEN 5
        WHEN p.status = 'Dikembalikan_sa' AND p.persetujuan_pengawas = 'Disetujui' THEN 6
    END,
    p.tgl_pengajuan DESC
";

$count_diajukan = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Diajukan' AND k.bidang = 'ANGKUTAN DALAM'
"));

$count_diperiksa = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Diperiksa_SA' AND k.bidang = 'ANGKUTAN DALAM'
"));

$count_belum_kirim = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Dikembalikan_sa' AND p.persetujuan_pengawas IS NULL AND k.bidang = 'ANGKUTAN DALAM'
"));

$count_menunggu = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Dikembalikan_sa' AND p.persetujuan_pengawas='Menunggu' AND k.bidang = 'ANGKUTAN DALAM'
"));

$count_ditolak = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Dikembalikan_sa' AND p.persetujuan_pengawas='Ditolak' AND k.bidang = 'ANGKUTAN DALAM'
"));

$count_disetujui_pengawas = mysqli_num_rows(mysqli_query($connection, "
    SELECT p.* FROM permintaan_perbaikan p
    INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    WHERE p.status='Dikembalikan_sa' AND p.persetujuan_pengawas='Disetujui' AND k.bidang = 'ANGKUTAN DALAM'
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
    <title>Service Advisor - List Kendaraan</title>
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
        
        .filter-btn.status-diajukan.active {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            border-color: #fbbf24;
        }
        
        .filter-btn.status-diperiksa.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #10b981;
        }
        
        .filter-btn.status-belum-kirim.active {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            border-color: #06b6d4;
        }

        .filter-btn.status-menunggu.active {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border-color: #f59e0b;
        }

        .filter-btn.status-ditolak.active {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-color: #ef4444;
        }

        .filter-btn.status-disetujui.active {
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            border-color: #8b5cf6;
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
        
        .status-diajukan { 
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); 
            color: white; 
        }
        
        .status-diperiksa { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            color: white; 
        }
        
        .status-dikembalikan { 
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); 
            color: white; 
        }

        .status-belum-kirim {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
        }

        .status-menunggu-persetujuan {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .status-disetujui-pengawas {
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            color: white;
        }

        .status-ditolak-pengawas {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
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
        
        .btn-delete { 
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); 
            color: white; 
        }

        .btn-approval {
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            color: white;
        }

        .btn-edit-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
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
            min-width: 150px;
            padding: 15px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            font-weight: 600;
        }
        
        .stat-card.yellow {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        }
        
        .stat-card.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .stat-card.cyan {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .stat-card.orange {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        
        .stat-card.red {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .stat-card.purple {
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
        }
        
        .stat-card i {
            font-size: 2rem;
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
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            animation: fadeIn 0.3s ease-out;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 16px;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }

        .modal-header h3 {
            color: #667eea;
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #999;
            cursor: pointer;
            transition: all 0.3s;
        }

        .modal-close:hover {
            color: #667eea;
            transform: rotate(90deg);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            margin-bottom: 12px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .info-label {
            font-weight: 600;
            color: #667eea;
            min-width: 150px;
        }

        .info-value {
            color: #2c3e50;
            flex: 1;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-modal {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-cancel {
            background: #95a5a6;
            color: white;
        }

        .btn-cancel:hover {
            background: #7f8c8d;
        }

        .btn-submit {
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            color: white;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        .approval-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 5px;
        }

        .approval-menunggu {
            background: #fef3c7;
            color: #92400e;
        }

        .approval-disetujui {
            background: #ddd6fe;
            color: #5b21b6;
        }

        .approval-ditolak {
            background: #fee2e2;
            color: #991b1b;
        }

        .catatan-box {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.85rem;
        }

        .catatan-label {
            font-weight: 700;
            color: #991b1b;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .catatan-text {
            color: #7f1d1d;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .form-label.required::after {
            content: ' *';
            color: #ef4444;
        }

        textarea.form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
            transition: all 0.3s;
        }

        textarea.form-control:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .history-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .history-title {
            font-weight: 700;
            color: #667eea;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
        }

        .history-item {
            background: white;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 4px solid #667eea;
        }

        .history-item:last-child {
            margin-bottom: 0;
        }

        .history-label {
            font-weight: 600;
            color: #667eea;
            font-size: 0.85rem;
            margin-bottom: 5px;
        }

        .history-text {
            color: #2c3e50;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .info-box {
            background: #e0f2fe;
            border-left: 4px solid #3b82f6;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .info-box p {
            margin: 0;
            color: #1e40af;
            font-size: 0.9rem;
            display: flex;
            align-items: start;
            gap: 8px;
        }

        .info-box i {
            margin-top: 2px;
        }

        textarea[name="catatan_sa"],
        textarea[name="catatan_sa_edit"] {
            text-transform: uppercase !important;
        }

        .pengaju-info {
            margin-top: 6px;
            font-size: 0.75rem;
            color: #6b7280;
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .pengaju-info i {
            color: #f59e0b;
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

<!-- Modal Persetujuan Baru -->
<div class="modal" id="approvalModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-paper-plane"></i> Kirim Persetujuan ke Pengawas</h3>
            <button class="modal-close" onclick="closeModal('approvalModal')">&times;</button>
        </div>
        <form method="POST" id="approvalForm">
            <div class="modal-body">
                <input type="hidden" name="id_permintaan" id="modal_id_permintaan">
                
                <!-- Info Pengajuan -->
                <div class="info-item">
                    <div class="info-label">Nomor Pengajuan:</div>
                    <div class="info-value" id="modal_nomor"></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Nomor Asset:</div>
                    <div class="info-value" id="modal_nopol"></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Grand Total:</div>
                    <div class="info-value" id="modal_total"></div>
                </div>

                <!-- Riwayat Catatan -->
                <div class="history-section">
                    <div class="history-title">
                        <i class="fas fa-history"></i> Riwayat Catatan
                    </div>
                    
                    <div class="history-item" id="keluhan_awal_section" style="display: none;">
                        <div class="history-label"><i class="fas fa-exclamation-triangle"></i> Keluhan Awal (Pengaju)</div>
                        <div class="history-text" id="modal_keluhan_awal"></div>
                    </div>
                    
                    <div class="history-item" id="catatan_qc_section" style="display: none;">
                        <div class="history-label"><i class="fas fa-clipboard-check"></i> Catatan QC</div>
                        <div class="history-text" id="modal_catatan_qc"></div>
                    </div>
                </div>

                <!-- Form Catatan SA -->
                <div class="form-group">
                    <label class="form-label required">
                        <i class="fas fa-comment-medical"></i> Catatan Service Advisor
                    </label>
                    <textarea 
                        name="catatan_sa" 
                        class="form-control" 
                        placeholder="Tuliskan catatan Anda untuk Pengawas mengenai hasil pemeriksaan, estimasi biaya, atau informasi penting lainnya..."
                        required
                    ></textarea>
                </div>

                <div class="info-box">
                    <p>
                        <i class="fas fa-info-circle"></i>
                        <span>Semua informasi di atas (keluhan awal, catatan QC, dan catatan SA) akan dikirim ke Pengawas untuk dipertimbangkan dalam memberikan persetujuan.</span>
                    </p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-modal btn-cancel" onclick="closeModal('approvalModal')">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="submit" name="kirim_persetujuan" class="btn-modal btn-submit">
                    <i class="fas fa-paper-plane"></i> Kirim Persetujuan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Catatan (Jika Ditolak) -->
<div class="modal" id="editCatatanModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit & Kirim Ulang ke Pengawas</h3>
            <button class="modal-close" onclick="closeModal('editCatatanModal')">&times;</button>
        </div>
        <form method="POST" id="editCatatanForm">
            <div class="modal-body">
                <input type="hidden" name="id_permintaan" id="edit_id_permintaan">
                
                <!-- Info Pengajuan -->
                <div class="info-item">
                    <div class="info-label">Nomor Pengajuan:</div>
                    <div class="info-value" id="edit_nomor"></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Nomor Asset:</div>
                    <div class="info-value" id="edit_nopol"></div>
                </div>

                <!-- Info Penolakan dengan Catatan Pengawas -->
                <div class="catatan-box">
                    <div class="catatan-label">
                        <i class="fas fa-times-circle"></i> Ditolak oleh Pengawas
                    </div>
                    <div class="catatan-text" id="edit_catatan_pengawas" style="font-weight: 600; margin-top: 8px;">
                        <!-- Catatan pengawas akan diisi via JavaScript -->
                    </div>
                </div>

                <!-- Riwayat Catatan Lengkap -->
                <div class="history-section" style="margin-top: 15px;">
                    <div class="history-title">
                        <i class="fas fa-history"></i> Riwayat Catatan Sebelumnya
                    </div>
                    
                    <div class="history-item" id="edit_keluhan_section" style="display: none; border-left-color: #e74c3c;">
                        <div class="history-label" style="color: #e74c3c;">
                            <i class="fas fa-exclamation-triangle"></i> Keluhan Awal (Pengaju)
                        </div>
                        <div class="history-text" id="edit_keluhan_awal"></div>
                    </div>
                    
                    <div class="history-item" id="edit_qc_section" style="display: none; border-left-color: #3498db;">
                        <div class="history-label" style="color: #3498db;">
                            <i class="fas fa-clipboard-check"></i> Catatan QC
                        </div>
                        <div class="history-text" id="edit_catatan_qc"></div>
                    </div>
                    
                    <div class="history-item" id="edit_sa_section" style="display: none; border-left-color: #9b59b6;">
                        <div class="history-label" style="color: #9b59b6;">
                            <i class="fas fa-user-tie"></i> Catatan SA Sebelumnya
                        </div>
                        <div class="history-text" id="edit_catatan_sa_lama"></div>
                    </div>
                </div>

                <!-- Form Edit Catatan SA -->
                <div class="form-group">
                    <label class="form-label required">
                        <i class="fas fa-comment-medical"></i> Perbarui Catatan Service Advisor
                    </label>
                    <textarea 
                        name="catatan_sa_edit" 
                        id="edit_catatan_sa_textarea"
                        class="form-control" 
                        placeholder="Perbaiki atau tambahkan informasi sesuai catatan pengawas..."
                        required
                    ></textarea>
                </div>

                <div class="info-box" style="background: #fef3c7; border-left-color: #f59e0b;">
                    <p style="color: #92400e;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Pastikan Anda telah memperbaiki atau melengkapi informasi sesuai dengan catatan pengawas sebelum mengirim ulang.</span>
                    </p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-modal btn-cancel" onclick="closeModal('editCatatanModal')">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="submit" name="edit_catatan_sa" class="btn-modal btn-submit">
                    <i class="fas fa-paper-plane"></i> Kirim Ulang
                </button>
            </div>
        </form>
    </div>
</div>

<div class="main-content">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: rgb(9, 120, 83); font-size: 2.5rem; font-weight: 700; margin-bottom: 10px;">
            <i class="fas fa-clipboard-list"></i> Service Advisor - List Kendaraan
        </h1>
    </div>

    <div class="card-section">
        <h2 class="section-title">
            <i class="fas fa-table"></i> Daftar Semua Kendaraan
        </h2>

        <!-- Ringkasan Statistik -->
        <div class="stats-summary">
            <div class="stat-card yellow">
                <i class="fas fa-hourglass-half"></i>
                <div class="stat-info">
                    <span class="stat-label">Diajukan</span>
                    <span class="stat-value"><?= $count_diajukan ?></span>
                </div>
            </div>
            <div class="stat-card green">
                <i class="fas fa-check-circle"></i>
                <div class="stat-info">
                    <span class="stat-label">Diperiksa SA</span>
                    <span class="stat-value"><?= $count_diperiksa ?></span>
                </div>
            </div>
            <div class="stat-card cyan">
                <i class="fas fa-clock"></i>
                <div class="stat-info">
                    <span class="stat-label">Di kembalikan QC</span>
                    <span class="stat-value"><?= $count_belum_kirim ?></span>
                </div>
            </div>
            <div class="stat-card orange">
                <i class="fas fa-paper-plane"></i>
                <div class="stat-info">
                    <span class="stat-label">Menunggu</span>
                    <span class="stat-value"><?= $count_menunggu ?></span>
                </div>
            </div>
            <div class="stat-card red">
                <i class="fas fa-times-circle"></i>
                <div class="stat-info">
                    <span class="stat-label">Ditolak</span>
                    <span class="stat-value"><?= $count_ditolak ?></span>
                </div>
            </div>
            <div class="stat-card purple">
                <i class="fas fa-user-check"></i>
                <div class="stat-info">
                    <span class="stat-label">Disetujui</span>
                    <span class="stat-value"><?= $count_disetujui_pengawas ?></span>
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
                <button class="filter-btn status-diajukan <?= $filter_status == 'Diajukan' ? 'active' : '' ?>" onclick="filterStatus('Diajukan')">
                    <i class="fas fa-hourglass-half"></i> Diajukan <span class="badge-count"><?= $count_diajukan ?></span>
                </button>
                <button class="filter-btn status-diperiksa <?= $filter_status == 'Diperiksa_SA' ? 'active' : '' ?>" onclick="filterStatus('Diperiksa_SA')">
                    <i class="fas fa-check-circle"></i> Diperiksa <span class="badge-count"><?= $count_diperiksa ?></span>
                </button>
                <button class="filter-btn status-belum-kirim <?= $filter_status == 'Belum_Kirim' ? 'active' : '' ?>" onclick="filterStatus('Belum_Kirim')">
                    <i class="fas fa-clock"></i> Di kembalikan QC <span class="badge-count"><?= $count_belum_kirim ?></span>
                </button>
                <button class="filter-btn status-menunggu <?= $filter_status == 'Menunggu_Persetujuan' ? 'active' : '' ?>" onclick="filterStatus('Menunggu_Persetujuan')">
                    <i class="fas fa-paper-plane"></i> Menunggu <span class="badge-count"><?= $count_menunggu ?></span>
                </button>
                <button class="filter-btn status-ditolak <?= $filter_status == 'Ditolak_Pengawas' ? 'active' : '' ?>" onclick="filterStatus('Ditolak_Pengawas')">
                    <i class="fas fa-times-circle"></i> Ditolak <span class="badge-count"><?= $count_ditolak ?></span>
                </button>
                <button class="filter-btn status-disetujui <?= $filter_status == 'Disetujui_Pengawas' ? 'active' : '' ?>" onclick="filterStatus('Disetujui_Pengawas')">
                    <i class="fas fa-user-check"></i> Disetujui <span class="badge-count"><?= $count_disetujui_pengawas ?></span>
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
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $result = mysqli_query($connection, $query);
                if (mysqli_num_rows($result) > 0):
                    while ($data = mysqli_fetch_assoc($result)):
                        $tanggal_display = '';
                        if ($data['status'] == 'Diajukan') {
                            $tanggal_display = date('d/m/Y H:i', strtotime($data['tgl_pengajuan']));
                        } elseif ($data['status'] == 'Diperiksa_SA') {
                            $tanggal_display = date('d/m/Y H:i', strtotime($data['tgl_diperiksa_sa']));
                        } elseif ($data['status'] == 'Dikembalikan_sa') {
                            if ($data['persetujuan_pengawas'] == 'Disetujui' || $data['persetujuan_pengawas'] == 'Ditolak') {
                                $tanggal_display = date('d/m/Y H:i', strtotime($data['tgl_persetujuan_pengawas']));
                            } else {
                                $tanggal_display = date('d/m/Y H:i', strtotime($data['tgl_dikembalikan']));
                            }
                        }
                        
                        $status_class = '';
                        $status_text = '';
                        $status_icon = '';
                        if ($data['status'] == 'Diajukan') {
                            $status_class = 'status-diajukan';
                            $status_text = 'Diajukan';
                            $status_icon = 'fa-clock';
                        } elseif ($data['status'] == 'Diperiksa_SA') {
                            $status_class = 'status-diperiksa';
                            $status_text = 'Diperiksa SA';
                            $status_icon = 'fa-check';
                        } elseif ($data['status'] == 'Dikembalikan_sa') {
                            if ($data['persetujuan_pengawas'] == 'Menunggu') {
                                $status_class = 'status-menunggu-persetujuan';
                                $status_text = 'Menunggu Persetujuan';
                                $status_icon = 'fa-paper-plane';
                            } elseif ($data['persetujuan_pengawas'] == 'Disetujui') {
                                $status_class = 'status-disetujui-pengawas';
                                $status_text = 'Disetujui Pengawas';
                                $status_icon = 'fa-check-circle';
                            } elseif ($data['persetujuan_pengawas'] == 'Ditolak') {
                                $status_class = 'status-ditolak-pengawas';
                                $status_text = 'Ditolak Pengawas';
                                $status_icon = 'fa-times-circle';
                            } else {
                                $status_class = 'status-belum-kirim';
                                $status_text = 'Di kembalikan QC';
                                $status_icon = 'fa-clock';
                            }
                        }
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
                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($data['keluhan']) ?>">
                            <?= htmlspecialchars($data['keluhan']) ?>
                        </td>
                        <td style="text-align: center;">
                            <span class="status-badge <?= $status_class ?>">
                                <i class="fas <?= $status_icon ?>"></i> <?= $status_text ?>
                            </span>
                            <?php if ($data['status'] == 'Dikembalikan_sa' && $data['persetujuan_pengawas'] == 'Menunggu'): ?>
                                <div class="pengaju-info">
                                    <i class="fas fa-user"></i>
                                    <span style="font-weight: 600;">Pengaju: <?= htmlspecialchars($data['pengaju_nama']) ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;"><?= $tanggal_display ?></td>
                        <td style="text-align: right; font-weight: 600; color: #059669;">
                            <?= $data['grand_total'] ? 'Rp ' . number_format($data['grand_total'], 0, ',', '.') : '-' ?>
                        </td>
                        <td style="text-align: center;">
                            <div style="display: flex; gap: 5px; justify-content: center; flex-wrap: nowrap;">
                            <?php if ($data['status'] == 'Dikembalikan_sa'): ?>
                                <?php if ($data['persetujuan_pengawas'] == 'Ditolak'): ?>
                                    <a href="service_advisor_kembali.php?id=<?= $data['id_permintaan'] ?>" 
                                       class="btn-action btn-detail">
                                        <i class="fas fa-undo"></i> Edit
                                    </a>
                                    
                                    <button onclick='openEditCatatanModal(
                                        <?= $data['id_permintaan'] ?>, 
                                        <?= json_encode($data['nomor_pengajuan']) ?>, 
                                        <?= json_encode($data['nopol']) ?>, 
                                        <?= json_encode($data['catatan_sa'] ?? '') ?>,
                                        <?= json_encode($data['catatan_pengawas'] ?? '') ?>,
                                        <?= json_encode($data['keluhan'] ?? '') ?>,
                                        <?= json_encode($data['catatan_qc'] ?? '') ?>
                                    )' 
                                            class="btn-action btn-edit-warning">
                                        <i class="fas fa-edit"></i> Edit Catatan & Kirim Ulang
                                    </button>
                                    
                                <?php elseif (is_null($data['persetujuan_pengawas'])): ?>
                                    <a href="service_advisor_kembali.php?id=<?= $data['id_permintaan'] ?>" 
                                       class="btn-action btn-detail">
                                        <i class="fas fa-undo"></i> Edit
                                    </a>
                                    
                                    <?php if (!is_null($data['grand_total']) && $data['grand_total'] > 0): ?>
                                    <button onclick='openApprovalModal(
                                        <?= $data['id_permintaan'] ?>, 
                                        <?= json_encode($data['nomor_pengajuan']) ?>, 
                                        <?= json_encode($data['nopol']) ?>, 
                                        <?= $data['grand_total'] ?>,
                                        <?= json_encode($data['keluhan'] ?? '') ?>,
                                        <?= json_encode($data['catatan_qc'] ?? '') ?>
                                    )' 
                                            class="btn-action btn-approval">
                                        <i class="fas fa-paper-plane"></i> Minta Persetujuan
                                    </button>
                                    <?php endif; ?>
                                    
                                <?php elseif ($data['persetujuan_pengawas'] == 'Menunggu'): ?>
                                    <span class="approval-badge approval-menunggu">
                                        <i class="fas fa-clock"></i> Menunggu Persetujuan
                                    </span>
                                <?php elseif ($data['persetujuan_pengawas'] == 'Disetujui'): ?>
                                    <a href="service_advisor_kembali.php?id=<?= $data['id_permintaan'] ?>" 
                                       class="btn-action btn-detail">
                                        <i class="fas fa-eye"></i> Lihat
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="service_advisor.php?id=<?= $data['id_permintaan'] ?>" 
                                   class="btn-action btn-detail">
                                    <i class="fas fa-eye"></i> Detail
                                </a>
                            <?php endif; ?>

                            <?php if ($data['status'] == 'Diajukan'): ?>
                            <a href="?hapus=<?= $data['id_permintaan'] ?>" 
                               onclick="return confirm('⚠️ PERINGATAN PENGHAPUSAN PERMANEN ⚠️\n\n📋 No Pengajuan: <?= htmlspecialchars($data['nomor_pengajuan']) ?>\n🚗 Kendaraan: <?= htmlspecialchars($data['nopol']) ?>\n\n❌ DATA AKAN DIHAPUS PERMANEN!\n❌ TIDAK DAPAT DIPULIHKAN KEMBALI!\n❌ PENGAWAS TIDAK AKAN TAHU ALASAN PENGHAPUSAN!\n\nApakah Anda yakin ingin melanjutkan?')" 
                               class="btn-action btn-delete">
                                <i class="fas fa-trash"></i>
                            </a>
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
                                    <?= $search || $filter_status ? 'Data tidak ditemukan dengan filter yang dipilih' : 'Belum ada data kendaraan' ?>
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
    
    // ========================================
    // AUTO UPPERCASE UNTUK TEXTAREA CATATAN
    // ========================================
    const textareaCatatanSA = document.querySelector('textarea[name="catatan_sa"]');
    const textareaCatatanSAEdit = document.querySelector('textarea[name="catatan_sa_edit"]');
    
    // Function untuk auto uppercase
    function makeUppercase(textarea) {
        if (textarea) {
            textarea.addEventListener('input', function() {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(start, end);
            });
        }
    }
    
    // Terapkan ke kedua textarea
    makeUppercase(textareaCatatanSA);
    makeUppercase(textareaCatatanSAEdit);
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
        window.location.href = 'list_kendaraan.php?' + currentParams.toString();
    }, 800);
}

function clearSearch() {
    const currentParams = new URLSearchParams(window.location.search);
    currentParams.delete('search');
    window.location.href = 'list_kendaraan.php?' + currentParams.toString();
}

function filterStatus(status) {
    const currentParams = new URLSearchParams(window.location.search);
    if (status) {
        currentParams.set('status', status);
    } else {
        currentParams.delete('status');
    }
    window.location.href = 'list_kendaraan.php?' + currentParams.toString();
}

function clearAllFilters() {
    window.location.href = 'list_kendaraan.php';
}

function openApprovalModal(id, nomor, nopol, total, keluhan, catatanQC) {
    document.getElementById('modal_id_permintaan').value = id;
    document.getElementById('modal_nomor').textContent = nomor;
    document.getElementById('modal_nopol').textContent = nopol;
    document.getElementById('modal_total').textContent = 'Rp ' + total.toLocaleString('id-ID');
    
    if (keluhan && keluhan.trim() !== '') {
        document.getElementById('modal_keluhan_awal').textContent = keluhan;
        document.getElementById('keluhan_awal_section').style.display = 'block';
    } else {
        document.getElementById('keluhan_awal_section').style.display = 'none';
    }
    
    if (catatanQC && catatanQC.trim() !== '') {
        document.getElementById('modal_catatan_qc').textContent = catatanQC;
        document.getElementById('catatan_qc_section').style.display = 'block';
    } else {
        document.getElementById('catatan_qc_section').style.display = 'none';
    }
    
    document.getElementById('approvalModal').classList.add('show');
}

function openEditCatatanModal(id, nomor, nopol, catatanSALama, catatanPengawas, keluhan, catatanQC) {
    document.getElementById('edit_id_permintaan').value = id;
    document.getElementById('edit_nomor').textContent = nomor;
    document.getElementById('edit_nopol').textContent = nopol;
    
    // Catatan Pengawas (alasan penolakan)
    document.getElementById('edit_catatan_pengawas').textContent = catatanPengawas || 'Tidak ada catatan penolakan';
    
    // Keluhan Awal
    if (keluhan && keluhan.trim() !== '') {
        document.getElementById('edit_keluhan_awal').textContent = keluhan;
        document.getElementById('edit_keluhan_section').style.display = 'block';
    } else {
        document.getElementById('edit_keluhan_section').style.display = 'none';
    }
    
    // Catatan QC
    if (catatanQC && catatanQC.trim() !== '') {
        document.getElementById('edit_catatan_qc').textContent = catatanQC;
        document.getElementById('edit_qc_section').style.display = 'block';
    } else {
        document.getElementById('edit_qc_section').style.display = 'none';
    }
    
    // Catatan SA Sebelumnya
    if (catatanSALama && catatanSALama.trim() !== '') {
        document.getElementById('edit_catatan_sa_lama').textContent = catatanSALama;
        document.getElementById('edit_sa_section').style.display = 'block';
    } else {
        document.getElementById('edit_sa_section').style.display = 'none';
    }
    
    // Set value textarea dengan catatan SA sebelumnya
    document.getElementById('edit_catatan_sa_textarea').value = catatanSALama || '';
    
    document.getElementById('editCatatanModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

window.onclick = function(event) {
    const approvalModal = document.getElementById('approvalModal');
    const editModal = document.getElementById('editCatatanModal');
    
    if (event.target == approvalModal) {
        closeModal('approvalModal');
    }
    if (event.target == editModal) {
        closeModal('editCatatanModal');
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal('approvalModal');
        closeModal('editCatatanModal');
    }
});
</script>

</body>
</html>
<?php
ob_end_flush();
?>
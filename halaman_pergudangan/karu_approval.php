<?php
// ========================================
// SET TIMEZONE INDONESIA - PENTING!
// ========================================
date_default_timezone_set('Asia/Jakarta');


// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('pergudangan');


// ==========================
// AMBIL ID PERMINTAAN
// ==========================
$id_permintaan = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id_permintaan <= 0) {
    die("❌ ID Permintaan tidak valid");
}

// ==========================
// AMBIL DATA USER LOGIN (KARU QC)
// ==========================
$user_login = $_SESSION['username'] ?? '';
$user_ttd = $_SESSION['ttd'] ?? '';
$user_id = $_SESSION['id_user'] ?? 0;

// Query untuk mendapatkan data user yang login
$query_user = "SELECT username, ttd FROM users WHERE id_user = '$user_id' LIMIT 1";
$result_user = mysqli_query($connection, $query_user);
$data_user = mysqli_fetch_assoc($result_user);

if ($data_user) {
    $user_login = $data_user['username'];
    $user_ttd = $data_user['ttd'];
}

// ==========================
// PROSES APPROVE & MULAI KERJA
// ==========================
if (isset($_POST['approve_mulai'])) {
    $id_rekanan = mysqli_real_escape_string($connection, $_POST['id_rekanan']);
    
    if (empty($id_rekanan)) {
        echo "<script>
            alert('Harap pilih Rekanan terlebih dahulu!');
            window.location.href='karu_qc_approval.php?id=$id_permintaan';
        </script>";
        exit;
    }
    
    $tgl_mulai = date('Y-m-d H:i:s');
    $admin_karu_id = intval($user_id);
    $ttd_karu_qc = mysqli_real_escape_string($connection, $user_ttd);

    // Catatan QC — opsional, paksa uppercase
    $catatan_qc = mysqli_real_escape_string($connection, strtoupper(trim($_POST['catatan_qc'] ?? '')));
    
    mysqli_query($connection, "
        UPDATE permintaan_perbaikan SET
            id_rekanan = '$id_rekanan',
            admin_karu_qc = '$admin_karu_id',
            ttd_karu_qc = '$ttd_karu_qc',
            tgl_disetujui_karu_qc = '$tgl_mulai',
            catatan_qc = '$catatan_qc',
            status = 'Disetujui_KARU_QC'
        WHERE id_permintaan = '$id_permintaan'
    ");
    
    echo "<script>
        alert('Pekerjaan berhasil disetujui dan dimulai! TTD KARU QC telah disimpan.');
        window.location.href='karu_qc_approval.php?id=$id_permintaan';
    </script>";
    exit;
}

// ==========================
// PROSES SELESAI KERJA
// ==========================
if (isset($_POST['selesai_kerja'])) {
    $tgl_selesai = date('Y-m-d H:i:s');
    $ttd_qc = mysqli_real_escape_string($connection, $user_ttd);
    
    // Cek apakah sudah disetujui/dimulai
    $check = mysqli_fetch_assoc(mysqli_query($connection, "
        SELECT tgl_disetujui_karu_qc 
        FROM permintaan_perbaikan 
        WHERE id_permintaan = '$id_permintaan'
    "));
    
    if (empty($check['tgl_disetujui_karu_qc'])) {
        echo "<script>
            alert('Pekerjaan belum dimulai! Harap approve terlebih dahulu.');
            window.location.href='karu_qc_approval.php?id=$id_permintaan';
        </script>";
        exit;
    }
    
    mysqli_query($connection, "
        UPDATE permintaan_perbaikan SET
            ttd_qc = '$ttd_qc',
            tgl_selesai = '$tgl_selesai',
            status = 'Selesai'
        WHERE id_permintaan = '$id_permintaan'
    ");
    
    echo "<script>
        alert('Pekerjaan berhasil diselesaikan! TTD QC telah disimpan.');
        window.location.href='karu_qc_approval.php?id=$id_permintaan';
    </script>";
    exit;
}

// ==========================
// AMBIL DATA PERMINTAAN
// ==========================
$query_permintaan = "
    SELECT p.*, k.nopol, k.jenis_kendaraan, k.bidang,
           u_sa.username AS sa_nama, u_sa.ttd AS sa_ttd,
           r.nama_rekanan, r.ttd_rekanan
    FROM permintaan_perbaikan p
    LEFT JOIN kendaraan k 
        ON p.id_kendaraan = k.id_kendaraan
    LEFT JOIN users u_sa
        ON p.admin_sa = u_sa.id_user
    LEFT JOIN rekanan r
        ON p.id_rekanan = r.id_rekanan
    WHERE p.id_permintaan = '$id_permintaan'
";
$result_permintaan = mysqli_query($connection, $query_permintaan);
$data_permintaan = mysqli_fetch_assoc($result_permintaan);

// ==========================
// AMBIL DETAIL JASA
// ==========================
$query_jasa_detail = "
    SELECT * 
    FROM perbaikan_detail 
    WHERE id_permintaan = '$id_permintaan'
";
$result_jasa_detail = mysqli_query($connection, $query_jasa_detail);

// ==========================
// AMBIL DETAIL SPAREPART
// ==========================
$query_sparepart_detail = "
    SELECT sd.*, s.kode_sparepart, s.nama_sparepart
    FROM sparepart_detail sd
    LEFT JOIN sparepart s ON sd.id_sparepart = s.id_sparepart
    WHERE sd.id_permintaan = '$id_permintaan'
";
$result_sparepart_detail = mysqli_query($connection, $query_sparepart_detail);

// ==========================
// DATA MASTER REKANAN
// ==========================
$result_rekanan = mysqli_query($connection, 
    "SELECT * 
     FROM rekanan 
     WHERE aktif = 'Y'
     ORDER BY nama_rekanan"
);

// ==========================
// AMBIL DATA STATUS TRACKING
// ==========================
$query_status = "
    SELECT 
        p.*,
        u_driver.username AS driver_nama,
        u_driver.ttd AS driver_ttd,
        u_sa.username AS sa_nama,
        u_sa.ttd AS sa_ttd,
        u_karu.username AS karu_nama,
        u_karu.ttd AS karu_ttd_approve,
        u_qc.username AS qc_nama,
        u_qc.ttd AS qc_ttd_selesai
    FROM permintaan_perbaikan p
    LEFT JOIN users u_driver ON p.id_pengaju = u_driver.id_user
    LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
    LEFT JOIN users u_karu ON p.admin_karu_qc = u_karu.id_user
    LEFT JOIN users u_qc ON p.admin_karu_qc = u_qc.id_user
    WHERE p.id_permintaan = '$id_permintaan'
    LIMIT 1
";
$data_status = mysqli_fetch_assoc(mysqli_query($connection, $query_status));
include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval & Monitoring</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- jQuery (required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
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
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            margin-left: 290px;
        }

        /* Button Back */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: white;
            color: rgb(9, 120, 83);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgb(185, 224, 204);
            transition: all 0.3s ease;
        }
        .btn-back:hover {
            background: rgb(9, 120, 83);
            color: white;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid rgb(9, 120, 83);
        }

        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: rgb(9, 120, 83);
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #555;
        }

        input[type="text"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            padding: 15px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
            width: 100%;
            margin-bottom: 10px;
        }

        .btn-primary {
            background:rgb(9, 120, 83);
            color: white;
        }

        .btn-primary:hover {
            background: rgb(15, 173, 120);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table thead {
            background: rgb(9, 120, 83);
            color: white;
        }

        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        table tbody tr:hover {
            background: #f3f4f6;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            padding: 10px;
            background: #f9fafb;
            border-radius: 5px;
        }

        .detail-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .detail-value {
            font-weight: 600;
            color: #1f2937;
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .status-item {
            text-align: center;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .status-item .title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #374151;
        }

        .status-badge {
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: bold;
            margin: 10px 0;
            display: inline-block;
        }

        .status-menunggu {
            background: #fef3c7;
            color: #92400e;
        }

        .status-selesai {
            background: #dcfce7;
            color: #166534;
        }

        .sub-status {
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 13px;
            margin: 5px 0;
        }

        .name {
            font-size: 14px;
            font-weight: 600;
            margin: 8px 0;
            color: #1f2937;
        }

        .time {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 30px 0;
        }

        .total-box {
            background: linear-gradient(135deg,rgb(105, 92, 15),rgb(205, 174, 38));
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
        }

        .total-box .label {
            font-size: 16px;
            margin-bottom: 10px;
        }

        .total-box .amount {
            font-size: 32px;
            font-weight: bold;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-info {
            background:rgb(185, 224, 204);
            color:rgb(0, 0, 0);
            border-left: 4px solid rgb(9, 120, 83);
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .rekanan-box {
            background: #f0fdf4;
            border: 2px solid #10b981;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .rekanan-box .label {
            font-size: 14px;
            color: #065f46;
            margin-bottom: 5px;
        }

        .rekanan-box .value {
            font-size: 18px;
            font-weight: bold;
            color: #047857;
        }

        .ttd-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .ttd-image {
            cursor: pointer;
            padding: 5px;
            background: white;
        }

        .ttd-wrapper img {
            height: 50px;
            max-width: 120px;
            object-fit: contain;
            padding: 3px;
        }

        /* Select2 Custom Styling */
        .select2-container--default .select2-selection--single {
            height: 42px;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 5px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 30px;
            padding-left: 10px;
            color: #333;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
            right: 5px;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 8px;
        }

        .select2-dropdown {
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: rgb(9, 120, 83);
        }

        .select2-container {
            width: 100% !important;
        }

        /* =============================================
           CATATAN QC — Styling
        ============================================= */
        .catatan-qc-box {
            background: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 18px;
            margin: 10px 0 0 0;
        }

        .catatan-qc-box .catatan-label {
            font-size: 13px;
            font-weight: 600;
            color: #065f46;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .catatan-qc-box textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #a7f3d0;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1f2937;
            background: white;
            resize: vertical;
            min-height: 75px;
            transition: border-color 0.2s;
            text-transform: uppercase;
        }

        .catatan-qc-box textarea:focus {
            outline: none;
            border-color: rgb(9, 120, 83);
            box-shadow: 0 0 0 3px rgba(9, 120, 83, 0.15);
        }

        .catatan-qc-box textarea::placeholder {
            color: #9ca3af;
        }

        /* Tampilan catatan yang sudah tersimpan (read-only) */
        .catatan-qc-readonly {
            background: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 15px 18px;
            margin: 10px 0 0 0;
        }

        .catatan-qc-readonly .catatan-label {
            font-size: 13px !important;
            font-weight: 600 !important;
            color: #065f46 !important;
            margin-bottom: 6px !important;
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .catatan-qc-readonly .catatan-text {
            font-size: 14px !important;
            color: #1f2937 !important;
            line-height: 1.6 !important;
            white-space: pre-wrap !important;
            word-wrap: break-word !important;
            text-align: left !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        /* Catatan Timeline Styles */
        .timeline-note {
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 6px;
            position: relative;
        }

        .timeline-note:last-child {
            margin-bottom: 0;
        }

        .note-header {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .note-icon {
            font-size: 18px;
            margin-right: 10px;
        }

        .note-title {
            font-size: 15px;
            font-weight: 600;
        }

        .note-content {
            color: #1f2937;
            line-height: 1.6;
            padding-left: 28px;
        }

        .note-timestamp {
            margin-top: 8px;
            padding-left: 28px;
        }

        .note-timestamp small {
            color: #78716c;
            font-size: 12px;
        }

        .notes-empty {
            text-align: center;
            color: #9ca3af;
            padding: 30px;
        }

        .notes-empty i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media screen and (max-width: 1024px) {
            .container {
                margin-left: 0 !important;
                padding: 10px;
            }

            .action-buttons {
                margin-left: 0 !important;
            }
        }

        @media screen and (max-width: 768px) {
            body, html {
                overflow-x: hidden !important;
                max-width: 100vw;
            }

            body {
                padding: 10px !important;
                padding-top: 70px !important;
            }

            .container {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .action-buttons {
                flex-direction: column;
                gap: 10px !important;
                margin-bottom: 15px !important;
                padding: 0 10px !important;
            }

            .btn-back {
                width: 100%;
                justify-content: center;
                padding: 10px 20px !important;
                font-size: 14px !important;
            }

            .card {
                padding: 15px !important;
                border-radius: 8px !important;
                margin: 0 10px 15px 10px !important;
            }

            .page-title {
                font-size: 18px !important;
                margin-bottom: 20px !important;
                padding-bottom: 12px !important;
                line-height: 1.4;
            }

            .section-title {
                font-size: 16px !important;
                margin: 20px 0 12px 0 !important;
                padding-bottom: 6px !important;
            }

            .alert {
                padding: 12px 15px !important;
                font-size: 13px !important;
                margin-bottom: 15px !important;
            }

            .alert-info,
            .alert-warning {
                line-height: 1.5;
            }

            .detail-grid {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
                margin-bottom: 15px !important;
            }

            .detail-item {
                padding: 8px !important;
            }

            .detail-label {
                font-size: 11px !important;
                margin-bottom: 4px !important;
            }

            .detail-value {
                font-size: 13px !important;
                word-wrap: break-word;
            }

            /* Timeline Notes - Mobile */
            .timeline-note {
                margin-bottom: 15px !important;
                padding: 12px !important;
            }

            .note-header {
                margin-bottom: 8px !important;
            }

            .note-icon {
                font-size: 16px !important;
                margin-right: 8px !important;
            }

            .note-title {
                font-size: 13px !important;
            }

            .note-content {
                font-size: 13px !important;
                padding-left: 24px !important;
                line-height: 1.5 !important;
            }

            .note-timestamp {
                padding-left: 24px !important;
                margin-top: 6px !important;
            }

            .note-timestamp small {
                font-size: 11px !important;
            }

            .notes-empty {
                padding: 20px !important;
            }

            .notes-empty i {
                font-size: 28px !important;
            }

            .form-group {
                margin-bottom: 12px !important;
            }

            label {
                font-size: 12px !important;
                margin-bottom: 4px !important;
            }

            input[type="text"],
            input[type="number"],
            select,
            textarea {
                padding: 8px 10px !important;
                font-size: 14px !important;
                border-radius: 6px !important;
            }

            textarea {
                min-height: 60px !important;
            }

            .select2-container {
                width: 100% !important;
            }

            .select2-container--default .select2-selection--single {
                height: 40px !important;
                padding: 4px 8px !important;
                font-size: 14px !important;
            }

            .select2-container--default .select2-selection--single .select2-selection__rendered {
                line-height: 30px !important;
                font-size: 14px !important;
                padding-left: 8px !important;
            }

            .select2-container--default .select2-selection--single .select2-selection__arrow {
                height: 38px !important;
            }

            .select2-dropdown {
                font-size: 14px !important;
            }

            .select2-container--default .select2-results__option {
                padding: 10px !important;
                font-size: 13px !important;
            }

            table {
                font-size: 11px !important;
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            table thead {
                display: none !important;
            }

            table tbody {
                display: block;
            }

            table tbody tr {
                display: block;
                margin-bottom: 12px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 12px;
                background: #f9fafb;
            }

            table tbody td {
                display: block;
                text-align: left !important;
                padding: 6px 0 !important;
                border: none !important;
            }

            table tbody td::before {
                content: attr(data-label);
                font-weight: 700;
                color: rgb(9, 120, 83);
                display: block;
                margin-bottom: 4px;
                font-size: 10px;
                text-transform: uppercase;
            }

            table tbody tr td:nth-child(1)::before { content: 'Kode'; }
            table tbody tr td:nth-child(2)::before { content: 'Nama'; }
            table tbody tr td:nth-child(3)::before { content: 'Qty'; }
            table tbody tr td:nth-child(4)::before { content: 'Harga'; }
            table tbody tr td:nth-child(5)::before { content: 'Subtotal'; }

            table tbody tr td[colspan] {
                text-align: center !important;
                padding: 20px !important;
                color: #9ca3af;
            }

            table tbody tr td[colspan]::before {
                display: none !important;
            }

            .btn {
                font-size: 14px !important;
                padding: 12px 20px !important;
                border-radius: 6px !important;
            }

            .btn-primary,
            .btn-success {
                width: 100%;
                justify-content: center;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .text-right {
                text-align: center !important;
                font-size: 16px !important;
                margin-top: 12px !important;
                padding: 12px !important;
                background: #f3f4f6;
                border-radius: 8px;
            }

            .total-box {
                padding: 15px !important;
                margin: 15px 0 !important;
            }

            .total-box .label {
                font-size: 14px !important;
                margin-bottom: 8px !important;
            }

            .total-box .amount {
                font-size: 24px !important;
                word-break: break-word;
            }

            .rekanan-box {
                padding: 12px !important;
                margin: 15px 0 !important;
            }

            .rekanan-box .label {
                font-size: 12px !important;
            }

            .rekanan-box .value {
                font-size: 16px !important;
                word-wrap: break-word;
            }

            .status-grid {
                grid-template-columns: 1fr 1fr !important;
                gap: 10px !important;
                margin: 15px 0 !important;
            }

            .status-item {
                padding: 12px 8px !important;
                font-size: 11px !important;
            }

            .status-item .title {
                font-size: 11px !important;
                margin-bottom: 8px !important;
            }

            .status-badge {
                padding: 6px 10px !important;
                font-size: 10px !important;
                margin: 8px 0 !important;
            }

            .sub-status {
                padding: 5px 8px !important;
                font-size: 10px !important;
                margin: 4px 0 !important;
            }

            .status-item .name {
                font-size: 11px !important;
                margin: 6px 0 !important;
                word-wrap: break-word;
            }

            .status-item .time {
                font-size: 9px !important;
                line-height: 1.3;
            }

            .ttd-wrapper {
                margin: 6px 0 !important;
            }

            .ttd-wrapper img,
            .ttd-image {
                max-height: 40px !important;
                max-width: 100px !important;
                margin: 4px 0 !important;
            }

            .divider {
                margin: 20px 0 !important;
            }

            /* Catatan QC mobile */
            .catatan-qc-box,
            .catatan-qc-readonly {
                padding: 12px 14px !important;
            }

            .catatan-qc-box .catatan-label,
            .catatan-qc-readonly .catatan-label {
                font-size: 12px !important;
            }

            .catatan-qc-box textarea {
                min-height: 60px !important;
                font-size: 14px !important;
            }

            .catatan-qc-readonly .catatan-text {
                font-size: 13px !important;
            }
        }

        /* Small mobile (< 480px) */
        @media screen and (max-width: 480px) {
            body {
                font-size: 12px !important;
            }

            .card {
                padding: 12px !important;
                margin: 0 5px 12px 5px !important;
            }

            .page-title {
                font-size: 16px !important;
            }

            .section-title {
                font-size: 14px !important;
            }

            .alert {
                font-size: 12px !important;
                padding: 10px 12px !important;
            }

            .detail-label {
                font-size: 10px !important;
            }

            .detail-value {
                font-size: 12px !important;
            }

            /* Timeline notes */
            .timeline-note {
                padding: 10px !important;
            }

            .note-title {
                font-size: 12px !important;
            }

            .note-content {
                font-size: 12px !important;
            }

            table {
                font-size: 10px !important;
            }

            table tbody td::before {
                font-size: 9px !important;
            }

            .btn {
                font-size: 13px !important;
                padding: 10px 16px !important;
            }

            .text-right {
                font-size: 14px !important;
            }

            .total-box .label {
                font-size: 12px !important;
            }

            .total-box .amount {
                font-size: 20px !important;
            }

            .status-grid {
                grid-template-columns: 1fr !important;
                gap: 8px !important;
            }

            .status-item {
                padding: 10px !important;
            }

            .ttd-wrapper img {
                max-height: 35px !important;
                max-width: 90px !important;
            }
        }

        /* Landscape mode */
        @media screen and (max-width: 768px) and (orientation: landscape) {
            body {
                padding-top: 60px !important;
            }

            .card {
                padding: 12px !important;
            }

            .page-title {
                font-size: 16px !important;
            }

            .section-title {
                font-size: 14px !important;
            }

            .status-grid {
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 8px !important;
            }

            .status-item {
                padding: 8px 6px !important;
            }

            .status-item .title {
                font-size: 10px !important;
            }

            .ttd-wrapper img {
                max-height: 35px !important;
            }
        }

        /* Utility classes for mobile */
        @media screen and (max-width: 768px) {
            button,
            a,
            input,
            select {
                min-height: 44px;
                touch-action: manipulation;
            }

            button {
                -webkit-user-select: none;
                user-select: none;
            }

            * {
                -webkit-overflow-scrolling: touch;
            }

            input[type="text"],
            input[type="number"],
            select,
            textarea {
                font-size: 16px !important;
            }

            .detail-value,
            table tbody td,
            .alert {
                word-wrap: break-word;
                word-break: break-word;
                white-space: normal;
            }

            .select2-search__field {
                font-size: 16px !important;
            }

            .select2-results__option {
                line-height: 1.4 !important;
            }

            select[name="id_rekanan"] {
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 10px center;
                padding-right: 35px !important;
            }

            .alert i {
                font-size: 16px;
                margin-right: 8px;
            }

            .status-badge,
            .sub-status {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 100%;
            }

            .detail-item[style*="grid-column: 1 / -1"] .detail-value {
                font-size: 12px !important;
                line-height: 1.5;
            }

            .text-center {
                text-align: center !important;
            }

            form + form {
                margin-top: 10px;
            }
        }

        /* High DPI screens */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            input[type="text"],
            input[type="number"],
            select,
            textarea {
                border-width: 0.5px !important;
            }

            table tbody tr {
                border-width: 0.5px !important;
            }
        }

        /* Print */
        @media print {
            .action-buttons,
            .btn,
            form {
                display: none !important;
            }

            .alert {
                page-break-inside: avoid;
            }

            .status-grid {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Button Back -->
        <div class="action-buttons">
            <a href="list_kendaraan_karu.php" class="btn-back">
                <i class="fas fa-arrow-left"></i>
                Kembali
            </a>
        </div>

        <?php if ($data_permintaan): ?>
        
            <div class="card">
                <div class="page-title">🔧 KARU QC - Approval & Monitoring Perbaikan</div>
                
                <!-- Informasi Status -->
                <?php if (!empty($data_status['tgl_selesai'])): ?>
                    <div class="alert alert-info">
                        ✅ <strong>Status:</strong> Pekerjaan ini sudah SELESAI pada 
                        <?= date('d/m/Y H:i', strtotime($data_status['tgl_selesai'])) ?>
                    </div>
                <?php elseif (!empty($data_status['tgl_disetujui_karu_qc'])): ?>
                    <div class="alert alert-warning">
                        ⏳ <strong>Status:</strong> Pekerjaan sedang dalam proses pengerjaan oleh rekanan.
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        📋 <strong>Status:</strong> Menunggu approval untuk memulai pekerjaan.
                    </div>
                <?php endif; ?>

                <!-- Detail Perbaikan -->
                <div class="section-title">📋 Informasi Kendaraan & Pengajuan</div>
                
                <?php
                $status = $data_status['status'] ?? '';
                ?>
                
                <!-- BUKA FORM jika status Diperiksa_SA -->
                <?php if ($status === 'Diperiksa_SA'): ?>
                <form method="POST" id="formApproval">
                <?php endif; ?>
                
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">No Pengajuan:</div>
                        <div class="detail-value"><?= $data_permintaan['nomor_pengajuan'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Nomor Asset:</div>
                        <div class="detail-value"><?= $data_permintaan['nopol'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Jenis Kendaraan:</div>
                        <div class="detail-value"><?= $data_permintaan['jenis_kendaraan'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Bidang:</div>
                        <div class="detail-value"><?= $data_permintaan['bidang'] ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Tanggal Dibuat:</div>
                        <div class="detail-value"><?= date('d/m/Y H:i', strtotime($data_permintaan['created_at'])) ?></div>
                    </div>
                    
                    <!-- SELECT REKANAN -->
                    <div class="detail-item">
                        <div class="detail-label">Rekanan yang Mengerjakan: <span style="color: red;">*</span></div>
                        <div class="detail-value">
                            <?php if ($status === 'Diperiksa_SA'): ?>
                                <select name="id_rekanan" id="select_rekanan" required 
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;">
                                    <option value="">-- Pilih Rekanan --</option>
                                    <?php 
                                    mysqli_data_seek($result_rekanan, 0);
                                    while($rekanan = mysqli_fetch_assoc($result_rekanan)): 
                                    ?>
                                        <option value="<?= $rekanan['id_rekanan'] ?>">
                                            <?= $rekanan['nama_rekanan'] ?> - <?= $rekanan['telp'] ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            <?php else: ?>
                                <?php if (!empty($data_permintaan['nama_rekanan'])): ?>
                                    <span style="color: #047857; font-size: 16px;">
                                        🏢 <?= $data_permintaan['nama_rekanan'] ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #dc2626;">Belum dipilih</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- TUTUP FORM jika status Diperiksa_SA -->
                <?php if ($status === 'Diperiksa_SA'): ?>
                </form>
                <?php endif; ?>

                <!-- =============================================
                     RIWAYAT CATATAN LENGKAP
                     Tampil jika status = QC atau Selesai
                ============================================= -->
                <?php if (in_array($status, ['QC', 'Selesai'])): ?>

                <div class="divider"></div>

                <div class="section-title">📝 Riwayat Catatan Lengkap</div>
                <div style="background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                    
                    <!-- Keluhan Awal (Pengaju) -->
                    <?php if (!empty($data_permintaan['keluhan_awal'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #ef4444;">
                        <div class="note-header">
                            <i class="fas fa-exclamation-triangle note-icon" style="color: #ef4444;"></i>
                            <strong class="note-title" style="color: #ef4444;">Keluhan Awal (Pengaju)</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['keluhan_awal'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Catatan QC -->
                    <?php if (!empty($data_permintaan['catatan_qc'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #3b82f6;">
                        <div class="note-header">
                            <i class="fas fa-clipboard-check note-icon" style="color: #3b82f6;"></i>
                            <strong class="note-title" style="color: #3b82f6;">Catatan QC</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['catatan_qc'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Catatan SA Sebelumnya -->
                    <?php if (!empty($data_permintaan['catatan_sa'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #8b5cf6;">
                        <div class="note-header">
                            <i class="fas fa-user-tie note-icon" style="color: #8b5cf6;"></i>
                            <strong class="note-title" style="color: #8b5cf6;">Catatan SA</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['catatan_sa'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Catatan Pengawas -->
                    <?php if (!empty($data_permintaan['catatan_pengawas'])): ?>
                    <div class="timeline-note" style="border-left: 4px solid #10b981;">
                        <div class="note-header">
                            <i class="fas fa-user-shield note-icon" style="color: #10b981;"></i>
                            <strong class="note-title" style="color: #10b981;">Catatan Pengawas</strong>
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data_permintaan['catatan_pengawas'])) ?>
                        </div>
                        <?php if (!empty($data_permintaan['tgl_selesaian_pengawas'])): ?>
                        <div class="note-timestamp">
                            <small>
                                <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($data_permintaan['tgl_selesaian_pengawas'])) ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Jika tidak ada catatan sama sekali -->
                    <?php if (empty($data_permintaan['keluhan_awal']) && 
                              empty($data_permintaan['catatan_qc']) && 
                              empty($data_permintaan['catatan_sa']) &&
                              empty($data_permintaan['catatan_pengawas'])): ?>
                    <div class="notes-empty">
                        <i class="fas fa-inbox"></i>
                        <p>Belum ada catatan tersedia</p>
                    </div>
                    <?php endif; ?>

                </div>

                <?php endif; ?>
                <!-- END RIWAYAT CATATAN LENGKAP -->

                <!-- =============================================
                     CATATAN QC — Form / Tampilan (untuk status Diperiksa_SA)
                ============================================= -->

                <!-- Diperiksa_SA : bisa isi (textarea, di dalam formApproval) -->
                <?php if ($status === 'Diperiksa_SA'): ?>
                    <div class="catatan-qc-box">
                        <div class="catatan-label">
                            <i class="fas fa-clipboard-list" style="color: #065f46;"></i>
                            Catatan QC di kirim ke Karu <span style="color: #6b7280; font-weight: 400;">(opsional)</span>
                        </div>
                        <textarea name="catatan_qc" form="formApproval" placeholder="Isi catatan dari QC di sini..."><?= htmlspecialchars($data_permintaan['catatan_qc'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>

                <div class="divider"></div>

                <!-- Detail Jasa Perbaikan -->
                <div class="section-title">🔨 Detail Jasa Perbaikan</div>
                <table>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Jasa Perbaikan</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result_jasa_detail) > 0): ?>
                            <?php while($jasa = mysqli_fetch_assoc($result_jasa_detail)): ?>
                                <tr>
                                    <td><?= $jasa['kode_pekerjaan'] ?></td>
                                    <td><?= $jasa['nama_pekerjaan'] ?></td>
                                    <td class="text-center"><?= $jasa['qty'] ?></td>
                                    <td class="text-right">Rp <?= number_format($jasa['harga'], 0, ',', '.') ?></td>
                                    <td class="text-right">Rp <?= number_format($jasa['subtotal'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">Tidak ada data</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="text-right" style="margin-top: 15px; font-size: 18px; font-weight: bold;">
                    Total Jasa: Rp <?= number_format($data_permintaan['total_perbaikan'], 0, ',', '.') ?>
                </div>

                <div class="divider"></div>

                <!-- Detail Sparepart -->
                <div class="section-title">🔩 Detail Sparepart</div>
                <table>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Sparepart</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result_sparepart_detail) > 0): ?>
                            <?php while($sparepart = mysqli_fetch_assoc($result_sparepart_detail)): ?>
                                <tr>
                                    <td><?= $sparepart['kode_sparepart'] ?? '-' ?></td>
                                    <td><?= $sparepart['nama_sparepart'] ?></td>
                                    <td class="text-center"><?= $sparepart['qty'] ?></td>
                                    <td class="text-right">Rp <?= number_format($sparepart['harga'], 0, ',', '.') ?></td>
                                    <td class="text-right">Rp <?= number_format($sparepart['subtotal'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center">Tidak ada data</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="text-right" style="margin-top: 15px; font-size: 18px; font-weight: bold;">
                    Total Sparepart: Rp <?= number_format($data_permintaan['total_sparepart'], 0, ',', '.') ?>
                </div>

                <div class="divider"></div>

                <!-- GRAND TOTAL -->
                <div class="total-box">
                    <div class="label">GRAND TOTAL</div>
                    <div class="amount">Rp <?= number_format($data_permintaan['grand_total'], 0, ',', '.') ?></div>
                </div>

                <div class="divider"></div>

                <!-- Status Tracking -->
                <div class="section-title">📊 Status Tracking</div>
                <div class="status-grid">

                    <!-- DRIVER -->
                    <div class="status-item">
                        <div class="title">Pengawas</div>
                        <?php if (!empty($data_status['tgl_pengajuan'])): ?>
                            <div class="status-badge status-selesai">✓ Diajukan</div>
                            <?php if (!empty($data_status['driver_ttd'])): ?>
                                <div class="ttd-wrapper">
                                    <img src="../uploads/ttd/<?= htmlspecialchars($data_status['driver_ttd']) ?>"
                                         alt="TTD Driver">
                                </div>
                            <?php endif; ?>
                            <div class="name"><?= htmlspecialchars($data_status['driver_nama']) ?></div>
                            <div class="time"><?= date('d/m/Y H:i', strtotime($data_status['tgl_pengajuan'])) ?></div>
                        <?php else: ?>
                            <div class="status-badge status-menunggu">⏳ Menunggu</div>
                        <?php endif; ?>
                    </div>

                    <!-- SERVICE ADVISOR -->
                    <div class="status-item">
                        <div class="title">Service Advisor</div>
                        <?php if (!empty($data_status['tgl_diperiksa_sa'])): ?>
                            <div class="status-badge status-selesai">✓ Diperiksa</div>
                            <?php if (!empty($data_status['sa_ttd'])): ?>
                                <div class="ttd-wrapper">
                                    <img src="../uploads/ttd/<?= htmlspecialchars($data_status['sa_ttd']) ?>"
                                         alt="TTD SA">
                                </div>
                            <?php endif; ?>
                            <div class="name"><?= htmlspecialchars($data_status['sa_nama']) ?></div>
                            <div class="time"><?= date('d/m/Y H:i', strtotime($data_status['tgl_diperiksa_sa'])) ?></div>
                        <?php else: ?>
                            <div class="status-badge status-menunggu">⏳ Menunggu</div>
                        <?php endif; ?>
                    </div>

                    <!-- KARU QC -->
                    <div class="status-item">
                        <div class="title">KARU QC</div>
                        
                        <?php if (!empty($data_status['tgl_disetujui_karu_qc'])): ?>
                            <div class="sub-status status-selesai">✓ Disetujui & Mulai</div>
                            
                            <?php if (!empty($data_status['ttd_karu_qc'])): ?>
                                <div class="ttd-wrapper">
                                    <img src="../uploads/ttd/<?= htmlspecialchars($data_status['ttd_karu_qc']) ?>"
                                         alt="TTD KARU QC">
                                </div>
                            <?php endif; ?>
                            
                            <div class="name"><?= htmlspecialchars($data_status['karu_nama']) ?></div>
                            <div class="time" style="margin-bottom: 15px;">
                                <?= date('d/m/Y H:i', strtotime($data_status['tgl_disetujui_karu_qc'])) ?>
                            </div>
                        <?php else: ?>
                            <div class="sub-status status-menunggu" style="margin-bottom: 15px;">⏳ Belum Dimulai</div>
                        <?php endif; ?>

                        <?php if (!empty($data_status['tgl_selesai'])): ?>
                            <div class="sub-status status-selesai">✓ Selesai Dikerjakan</div>
                            
                            <?php if (!empty($data_status['ttd_qc'])): ?>
                                <div class="ttd-wrapper">
                                    <img src="../uploads/ttd/<?= htmlspecialchars($data_status['ttd_qc']) ?>"
                                         alt="TTD QC">
                                </div>
                            <?php endif; ?>
                            
                            <div class="name"><?= htmlspecialchars($data_status['qc_nama']) ?></div>
                            <div class="time"><?= date('d/m/Y H:i', strtotime($data_status['tgl_selesai'])) ?></div>
                        <?php else: ?>
                            <div class="sub-status status-menunggu">⏳ Belum Selesai</div>
                        <?php endif; ?>
                    </div>

                    <!-- REKANAN -->
                    <div class="status-item">
                        <div class="title">Rekanan</div>
                        <?php if (!empty($data_status['tgl_selesai'])): ?>
                            <div class="status-badge status-selesai">✓ Selesai</div>
                            
                            <?php if (!empty($data_permintaan['ttd_rekanan'])): ?>
                                <div class="ttd-wrapper">
                                    <img src="../uploads/ttd_rekanan/<?= htmlspecialchars($data_permintaan['ttd_rekanan']) ?>"
                                         alt="TTD Rekanan">
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($data_permintaan['nama_rekanan'])): ?>
                                <div class="name"><?= $data_permintaan['nama_rekanan'] ?></div>
                            <?php endif; ?>
                            <div class="time"><?= date('d/m/Y H:i', strtotime($data_status['tgl_selesai'])) ?></div>
                        <?php else: ?>
                            <div class="status-badge status-menunggu">⏳ Menunggu</div>
                            <?php if (!empty($data_permintaan['nama_rekanan'])): ?>
                                <div class="name" style="margin-top: 10px; font-size: 13px; color: #6b7280;">
                                    (<?= $data_permintaan['nama_rekanan'] ?>)
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                </div>

                <div class="divider"></div>

                <!-- =====================
                     FORM ACTIONS
                ===================== -->

                <?php if ($status === 'Diperiksa_SA'): ?>

                    <button type="submit" name="approve_mulai" class="btn btn-primary" form="formApproval">
                        ✅ Setujui & Mulai Dikerjakan 
                    </button>

                <?php elseif ($status === 'QC'): ?>

                    <form method="POST" id="formSelesai"
                          onsubmit="return confirm('Apakah Anda yakin pekerjaan sudah selesai?');">
                        <button type="submit" name="selesai_kerja" class="btn btn-success">
                            ✅ Pekerjaan Selesai
                        </button>
                    </form>

                <?php elseif ($status === 'Selesai'): ?>

                    <div class="alert alert-info" style="text-align:center;">
                        ✅ <strong>Pekerjaan Sudah Selesai</strong><br>
                        Tidak ada aksi yang diperlukan.
                    </div>

                <?php endif; ?>

            </div>

        <?php else: ?>
        <div class="card">
            <div class="text-center" style="padding: 40px;">
                <h2>❌ Data tidak ditemukan</h2>
                <p>Silakan pilih data perbaikan yang valid</p>
                <a href="dashboard_karu_qc.php" class="btn btn-primary" style="display: inline-block; width: auto; margin-top: 20px;">
                    ← Kembali ke Dashboard
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        $(document).ready(function() {
            $('#select_rekanan').select2({
                placeholder: '-- Pilih atau Cari Rekanan --',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() {
                        return "Rekanan tidak ditemukan";
                    },
                    searching: function() {
                        return "Mencari...";
                    },
                    inputTooShort: function() {
                        return "Ketik untuk mencari";
                    }
                }
            });
        });
    </script>
</body>
</html>
<?php
include "../inc/config.php";
requireAuth('pengawas');

date_default_timezone_set('Asia/Jakarta');

$id_user = $_SESSION['id_user'];
$nama_user = $_SESSION['username'] ?? 'Pengawas';
$role_user = $_SESSION['role'] ?? 'pengawas';
$id_permintaan = isset($_GET['id']) ? mysqli_real_escape_string($connection, $_GET['id']) : '';

if (empty($id_permintaan)) {
    header('Location: ' . url_with_tab('pengajuan_perbaikan.php'));
    exit;
}

/* =======================
   PROSES PERSETUJUAN
======================= */
if (isset($_POST['action_persetujuan'])) {
    $persetujuan = mysqli_real_escape_string($connection, $_POST['persetujuan']);
    $catatan = strtoupper(mysqli_real_escape_string($connection, $_POST['catatan_pengawas']));
    $tgl_persetujuan = date('Y-m-d H:i:s');

    if (empty($catatan)) {
        $_SESSION['error_msg'] = 'Catatan pengawas wajib diisi.';
    } else {
        // Ambil data untuk cek status dan asal_persetujuan
        $check = mysqli_query($connection, "
            SELECT status, catatan_sa FROM permintaan_perbaikan 
            WHERE id_permintaan = '$id_permintaan'
        ");
        $current_data = mysqli_fetch_assoc($check);
        $current_status = $current_data['status'];

        // ========================================
        // LOGIC ASAL PERSETUJUAN
        // ========================================
        // Jika catatan_sa NULL atau kosong → dari QC
        // Jika catatan_sa ada isinya → dari SA
        $asal_persetujuan = (empty($current_data['catatan_sa']) || is_null($current_data['catatan_sa'])) ? 'QC' : 'SA';

        // Get TTD Pengawas
        $user_ttd = mysqli_fetch_assoc(mysqli_query($connection, "SELECT ttd FROM users WHERE id_user='$id_user'"));
        $ttd_pengawas = $user_ttd['ttd'];

        // ========================================
        // LOGIKA STATUS BARU:
        // Baik dari QC maupun SA:
        //   - Disetujui → status tetap 'Dikembalikan_sa', persetujuan_pengawas = 'Disetujui'
        //                 SA membaca persetujuan_pengawas='Disetujui' untuk tindak lanjut
        //   - Ditolak   → status tetap 'Dikembalikan_sa', persetujuan_pengawas = 'Ditolak'
        // SA yang menentukan langkah selanjutnya, bukan langsung ke QC
        // ========================================
        if ($current_status === 'Minta_Persetujuan_Pengawas' || $current_status === 'Dikembalikan_sa') {
            if ($persetujuan === 'Disetujui') {
                // Disetujui → kembali ke SA, SA yang tindak lanjut
                $new_status = 'Dikembalikan_sa';
                $tgl_field = 'tgl_persetujuan_pengawas';
            } else {
                // Ditolak → kembali ke SA dengan catatan penolakan
                $new_status = 'Dikembalikan_sa';
                $tgl_field = 'tgl_dikembalikan';
            }
        } else {
            // Status lain — tidak ubah status
            $new_status = $current_status;
            $tgl_field = null;
        }

        // Update database
        if ($tgl_field) {
            mysqli_query($connection, "
                UPDATE permintaan_perbaikan SET
                    persetujuan_pengawas = '$persetujuan',
                    catatan_pengawas = '$catatan',
                    tgl_persetujuan_pengawas = '$tgl_persetujuan',
                    ttd_pengawas = '$ttd_pengawas',
                    asal_persetujuan = '$asal_persetujuan',
                    status = '$new_status',
                    $tgl_field = '$tgl_persetujuan'
                WHERE id_permintaan = '$id_permintaan'
            ");
        } else {
            mysqli_query($connection, "
                UPDATE permintaan_perbaikan SET
                    persetujuan_pengawas = '$persetujuan',
                    catatan_pengawas = '$catatan',
                    tgl_persetujuan_pengawas = '$tgl_persetujuan',
                    ttd_pengawas = '$ttd_pengawas',
                    asal_persetujuan = '$asal_persetujuan'
                WHERE id_permintaan = '$id_permintaan'
            ");
        }

        if ($persetujuan === 'Disetujui') {
            $_SESSION['success_msg'] = "✅ Pengajuan berhasil disetujui! Pengajuan telah dikembalikan ke Service Advisor untuk ditindaklanjuti.";
        } else {
            $_SESSION['success_msg'] = "❌ Pengajuan berhasil ditolak! Pengajuan dikembalikan ke Service Advisor.";
        }

        header('Location: ' . url_with_tab('pengajuan_perbaikan.php'));
        exit;
    }
}

/* =======================
   GET DATA PERMINTAAN
   Pengawas bisa melihat dari berbagai status yang membutuhkan persetujuan:
   - Minta_Persetujuan_Pengawas (dari QC)
   - Dikembalikan_sa dengan persetujuan_pengawas = Menunggu (dari SA)
======================= */
$query = mysqli_query($connection, "
    SELECT 
        p.*,
        k.nopol,
        k.jenis_kendaraan,
        k.bidang,
        k.status AS status_kendaraan,
        r.nama_rekanan,
        u.username AS pengaju_nama
    FROM permintaan_perbaikan p
    JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    LEFT JOIN rekanan r ON p.id_rekanan = r.id_rekanan
    LEFT JOIN users u ON p.id_pengaju = u.id_user
    WHERE p.id_permintaan = '$id_permintaan'
      AND p.persetujuan_pengawas = 'Menunggu'
      AND p.status IN ('Minta_Persetujuan_Pengawas', 'Dikembalikan_sa')
");

if (mysqli_num_rows($query) == 0) {
    header('Location: ' . url_with_tab('pengajuan_perbaikan.php'));
    exit;
}

$data = mysqli_fetch_assoc($query);

// Deteksi asal persetujuan untuk display
// Jika status Minta_Persetujuan_Pengawas → dari QC
// Jika status Dikembalikan_sa dan ada catatan_sa → dari SA
if ($data['status'] === 'Minta_Persetujuan_Pengawas') {
    $asal_from = 'QC';
} else {
    $asal_from = (!empty($data['catatan_sa'])) ? 'SA' : 'QC';
}

/* =======================
   GET DETAIL PERBAIKAN
======================= */
$query_perbaikan = mysqli_query($connection, "
    SELECT 
        pd.nama_pekerjaan,
        pd.kode_pekerjaan,
        pd.qty,
        pd.harga,
        pd.subtotal
    FROM perbaikan_detail pd
    WHERE pd.id_permintaan = '$id_permintaan'
    ORDER BY pd.id_detail ASC
");

$detail_perbaikan = [];
while ($row = mysqli_fetch_assoc($query_perbaikan)) {
    $detail_perbaikan[] = $row;
}

/* =======================
   GET DETAIL SPAREPART
======================= */
$query_sparepart = mysqli_query($connection, "
    SELECT 
        s.nama_sparepart,
        s.kode_sparepart,
        sd.qty,
        sd.harga,
        sd.subtotal
    FROM sparepart_detail sd
    JOIN sparepart s ON sd.id_sparepart = s.id_sparepart
    WHERE sd.id_permintaan = '$id_permintaan'
    ORDER BY sd.id_detail ASC
");

$detail_sparepart = [];
while ($row = mysqli_fetch_assoc($query_sparepart)) {
    $detail_sparepart[] = $row;
}

$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Persetujuan - <?= htmlspecialchars($data['nomor_pengajuan']) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            padding: 20px;
        }

        .main-content {
            margin-left: 260px;
            transition: margin-left 0.3s ease;
        }

        /* Paper Container */
        .paper-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        /* Header Section */
        .paper-header {
            background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%);
            color: white;
            padding: 25px 30px;
            position: relative;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .doc-title {
            font-size: 1.5em;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9em;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-3px);
        }

        .doc-number {
            font-size: 1.1em;
            font-weight: 600;
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 6px;
            display: inline-block;
        }

        /* Content Section */
        .paper-content {
            padding: 30px;
        }

        /* Alert Messages */
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9em;
        }

        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }

        /* Asal Persetujuan Info Badge */
        .asal-info {
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            border-left: 4px solid #8b5cf6;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .asal-info.sa {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left-color: #f59e0b;
        }

        .asal-info h4 {
            font-size: 0.9em;
            color: #7c3aed;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
        }

        .asal-info.sa h4 {
            color: #d97706;
        }

        .asal-info p {
            color: #5b21b6;
            font-size: 0.9em;
            margin: 0;
            line-height: 1.5;
        }

        .asal-info.sa p {
            color: #92400e;
        }

        /* Alur Info Box */
        .alur-info {
            background: #e0f2fe;
            border-left: 4px solid #0ea5e9;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alur-info h4 {
            font-size: 0.9em;
            color: #0369a1;
            margin-bottom: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alur-steps {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .alur-step {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85em;
        }

        .alur-step .step-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85em;
            font-weight: 700;
            flex-shrink: 0;
        }

        .alur-step .step-icon.approve {
            background: #d1fae5;
            color: #065f46;
        }

        .alur-step .step-icon.reject {
            background: #fee2e2;
            color: #991b1b;
        }

        .alur-step .step-text {
            color: #0c4a6e;
            line-height: 1.4;
        }

        .alur-step .step-text strong {
            color: #0369a1;
        }

        /* Status Info Badge */
        .status-info {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }

        .status-info strong {
            color: #856404;
            display: block;
            margin-bottom: 5px;
        }

        .status-info p {
            color: #856404;
            margin: 0;
        }

        /* Section */
        .section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 1.1em;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 2px solid #667eea;
        }

        /* Info Grid */
        .info-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 12px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-label {
            font-size: 0.8em;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 0.95em;
            color: #2c3e50;
            font-weight: 600;
        }

        /* Timeline */
        .timeline-compact {
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 6px;
            border-left: 3px solid #667eea;
            margin-bottom: 15px;
            font-size: 0.85em;
        }

        .timeline-compact p {
            margin: 3px 0;
            color: #495057;
        }

        .timeline-compact strong {
            color: #667eea;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85em;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #f39c12;
        }

        /* Catatan */
        .notes-container {
            display: grid;
            gap: 12px;
        }

        .note-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            border-left: 3px solid #dee2e6;
        }

        .note-item.keluhan { border-left-color: #e74c3c; }
        .note-item.qc { border-left-color: #3498db; }
        .note-item.sa { border-left-color: #9b59b6; }

        .note-header {
            font-size: 0.85em;
            font-weight: 700;
            color: #495057;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .note-content {
            font-size: 0.9em;
            color: #2c3e50;
            line-height: 1.5;
        }

        .note-content.empty {
            color: #adb5bd;
            font-style: italic;
        }

        /* Biaya Table */
        .biaya-detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85em;
            margin-bottom: 15px;
        }

        .biaya-detail-table thead {
            background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%);
            color: white;
        }

        .biaya-detail-table thead th {
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9em;
        }

        .biaya-detail-table thead th:nth-child(2),
        .biaya-detail-table thead th:nth-child(3),
        .biaya-detail-table thead th:nth-child(4) {
            text-align: right;
        }

        .biaya-detail-table tbody tr {
            border-bottom: 1px solid #e9ecef;
        }

        .biaya-detail-table tbody tr:hover {
            background: #f8f9fa;
        }

        .biaya-detail-table tbody td {
            padding: 10px 8px;
        }

        .biaya-detail-table tbody td:nth-child(2),
        .biaya-detail-table tbody td:nth-child(3),
        .biaya-detail-table tbody td:nth-child(4) {
            text-align: right;
            font-weight: 600;
        }

        .biaya-summary {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
        }

        .biaya-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.9em;
        }

        .biaya-summary-row.total {
            border-top: 2px solid #667eea;
            padding-top: 10px;
            margin-top: 8px;
            font-weight: 700;
            font-size: 1.1em;
            color: #667eea;
        }

        .biaya-summary-label {
            color: #6c757d;
            font-weight: 600;
        }

        .biaya-summary-value {
            color: #2c3e50;
            font-weight: 700;
        }

        .no-data {
            text-align: center;
            padding: 15px;
            color: #6c757d;
            font-style: italic;
            font-size: 0.9em;
        }

        /* Form */
        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
            font-size: 0.9em;
        }

        .form-label .required {
            color: #e74c3c;
        }

        textarea.form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.9em;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            transition: all 0.3s;
            text-transform: uppercase;
        }

        textarea.form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        textarea.form-control.error {
            border-color: #e74c3c;
        }

        .error-msg {
            color: #e74c3c;
            font-size: 0.8em;
            margin-top: 4px;
            display: none;
            align-items: center;
            gap: 4px;
        }

        .error-msg.show {
            display: flex;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            flex: 1;
            min-width: 150px;
        }

        .btn-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 204, 113, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }

            body {
                padding: 10px;
            }

            .paper-header {
                padding: 20px 15px;
            }

            .paper-content {
                padding: 20px 15px;
            }

            .doc-title {
                font-size: 1.2em;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .info-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                min-width: 100%;
            }

            .biaya-detail-table {
                font-size: 0.75em;
            }

            .alur-steps {
                gap: 8px;
            }
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .btn-back,
            .action-buttons {
                display: none;
            }

            .paper-container {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

<?php include "navbar_pengawas.php"; ?>

<div class="main-content">
    <div class="paper-container">
        <!-- Paper Header -->
        <div class="paper-header">
            <div class="header-top">
                <h1 class="doc-title">
                    <i class="fas fa-clipboard-check"></i>
                    Persetujuan Pengajuan Perbaikan
                </h1>
                <a href="pengajuan_perbaikan.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>
            <div class="doc-number">
                <i class="fas fa-hashtag"></i> <?= htmlspecialchars($data['nomor_pengajuan']) ?>
            </div>
        </div>

        <!-- Paper Content -->
        <div class="paper-content">
            <!-- Alert Messages -->
            <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success_msg) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
            <?php endif; ?>

            <!-- Info Asal Persetujuan -->
            <div class="asal-info <?= $asal_from == 'SA' ? 'sa' : '' ?>">
                <h4>
                    <i class="fas fa-<?= $asal_from == 'QC' ? 'clipboard-check' : 'user-tie' ?>"></i>
                    Sumber Pengajuan: <strong><?= $asal_from == 'QC' ? ' QC' : 'SERVICE ADVISOR' ?></strong>
                </h4>
                <p>
                    <?php if ($asal_from == 'QC'): ?>
                        Pengajuan ini dikirimkan dari <strong> QC</strong> untuk mendapatkan persetujuan Anda.
                    <?php else: ?>
                        Pengajuan ini dikirimkan dari <strong>SERVICE ADVISOR</strong> untuk mendapatkan persetujuan Anda.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Alur Setelah Keputusan -->
            <div class="alur-info">
                <h4>
                    <i class="fas fa-route"></i> Alur Setelah Keputusan Anda
                </h4>
                <div class="alur-steps">
                    <div class="alur-step">
                        <div class="step-icon approve">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="step-text">
                            Jika <strong>DISETUJUI</strong> → Pengajuan dikembalikan ke <strong>Service Advisor</strong> dengan status <strong>Disetujui Pengawas</strong>. SA yang akan menentukan langkah selanjutnya.
                        </div>
                    </div>
                    <div class="alur-step">
                        <div class="step-icon reject">
                            <i class="fas fa-times"></i>
                        </div>
                        <div class="step-text">
                            Jika <strong>DITOLAK</strong> → Pengajuan dikembalikan ke <strong>Service Advisor</strong> dengan catatan penolakan Anda.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Timeline -->
            <div class="timeline-compact">
                <p><i class="fas fa-clock"></i> <strong>Status:</strong> <span class="badge">Menunggu Persetujuan Anda</span></p>
                <p><i class="fas fa-calendar"></i> <strong>Tanggal Pengajuan:</strong> <?= date('d F Y, H:i', strtotime($data['tgl_pengajuan'])) ?> WIB</p>
                <?php if (!empty($data['tgl_persetujuan_pengawas'])): ?>
                <p><i class="fas fa-paper-plane"></i> <strong>Dikirim ke Pengawas:</strong> <?= date('d F Y, H:i', strtotime($data['tgl_persetujuan_pengawas'])) ?> WIB</p>
                <?php endif; ?>
            </div>

            <!-- Section 1: Info Kendaraan -->
            <div class="section">
                <h3 class="section-title">
                    <i class="fas fa-car"></i> Informasi Kendaraan
                </h3>
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Nomor Polisi / Asset</div>
                        <div class="info-value"><?= htmlspecialchars($data['nopol']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Jenis Kendaraan</div>
                        <div class="info-value"><?= htmlspecialchars($data['jenis_kendaraan']) ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Bidang</div>
                        <div class="info-value"><?= htmlspecialchars($data['bidang']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Rekanan Bengkel</div>
                        <div class="info-value"><?= !empty($data['nama_rekanan']) ? htmlspecialchars($data['nama_rekanan']) : 'Belum ditentukan' ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">Diajukan Oleh</div>
                        <div class="info-value"><?= htmlspecialchars($data['pengaju_nama']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Riwayat Catatan -->
            <div class="section">
                <h3 class="section-title">
                    <i class="fas fa-history"></i> Riwayat Catatan
                </h3>
                <div class="notes-container">
                    <div class="note-item keluhan">
                        <div class="note-header">
                            <i class="fas fa-exclamation-triangle"></i> 1. Keluhan Awal
                        </div>
                        <div class="note-content <?= empty($data['keluhan_awal']) ? 'empty' : '' ?>">
                            <?= !empty($data['keluhan_awal']) ? nl2br(htmlspecialchars($data['keluhan_awal'])) : 'Tidak ada catatan' ?>
                        </div>
                    </div>

                    <div class="note-item qc">
                        <div class="note-header">
                            <i class="fas fa-clipboard-check"></i> 2. Catatan QC
                        </div>
                        <div class="note-content <?= empty($data['catatan_qc']) ? 'empty' : '' ?>">
                            <?= !empty($data['catatan_qc']) ? nl2br(htmlspecialchars($data['catatan_qc'])) : 'Tidak ada catatan dari QC' ?>
                        </div>
                    </div>

                    <?php if (!empty($data['catatan_sa'])): ?>
                    <div class="note-item sa">
                        <div class="note-header">
                            <i class="fas fa-user-tie"></i> 3. Catatan Service Advisor
                        </div>
                        <div class="note-content">
                            <?= nl2br(htmlspecialchars($data['catatan_sa'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 3: Rincian Biaya -->
            <div class="section">
                <h3 class="section-title">
                    <i class="fas fa-calculator"></i> Rincian Biaya Perbaikan
                </h3>

                <!-- Detail Jasa Perbaikan -->
                <h4 style="font-size: 0.95em; color: #495057; margin-bottom: 10px; font-weight: 600;">
                    <i class="fas fa-wrench"></i> Jasa Perbaikan
                </h4>
                <?php if (count($detail_perbaikan) > 0): ?>
                <table class="biaya-detail-table">
                    <thead>
                        <tr>
                            <th>Nama Pekerjaan</th>
                            <th>Qty</th>
                            <th>Harga Satuan</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detail_perbaikan as $item): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($item['nama_pekerjaan']) ?>
                                <br><small style="color: #6c757d; font-size: 0.85em;"><?= htmlspecialchars($item['kode_pekerjaan']) ?></small>
                            </td>
                            <td><?= number_format($item['qty'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($item['harga'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i> Tidak ada item jasa perbaikan
                </div>
                <?php endif; ?>

                <!-- Detail Sparepart -->
                <h4 style="font-size: 0.95em; color: #495057; margin: 20px 0 10px 0; font-weight: 600;">
                    <i class="fas fa-cogs"></i> Sparepart
                </h4>
                <?php if (count($detail_sparepart) > 0): ?>
                <table class="biaya-detail-table">
                    <thead>
                        <tr>
                            <th>Nama Sparepart</th>
                            <th>Qty</th>
                            <th>Harga Satuan</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detail_sparepart as $item): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($item['nama_sparepart']) ?>
                                <br><small style="color: #6c757d; font-size: 0.85em;"><?= htmlspecialchars($item['kode_sparepart']) ?></small>
                            </td>
                            <td><?= number_format($item['qty'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($item['harga'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i> Tidak ada item sparepart
                </div>
                <?php endif; ?>

                <!-- Summary -->
                <div class="biaya-summary">
                    <div class="biaya-summary-row">
                        <span class="biaya-summary-label"><i class="fas fa-wrench"></i> Total Jasa Perbaikan</span>
                        <span class="biaya-summary-value">Rp <?= number_format($data['total_perbaikan'], 0, ',', '.') ?></span>
                    </div>
                    <div class="biaya-summary-row">
                        <span class="biaya-summary-label"><i class="fas fa-cogs"></i> Total Sparepart</span>
                        <span class="biaya-summary-value">Rp <?= number_format($data['total_sparepart'], 0, ',', '.') ?></span>
                    </div>
                    <div class="biaya-summary-row total">
                        <span><i class="fas fa-money-bill-wave"></i> GRAND TOTAL</span>
                        <span>Rp <?= number_format($data['grand_total'], 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>

            <!-- Section 4: Form Keputusan -->
            <div class="section">
                <h3 class="section-title">
                    <i class="fas fa-gavel"></i> Keputusan Pengawas
                </h3>

                <div style="background: #f8f9fa; border-radius: 8px; padding: 14px; margin-bottom: 18px; border: 1px solid #e9ecef; font-size: 0.88em; color: #495057; line-height: 1.7;">
                    <i class="fas fa-info-circle" style="color: #667eea;"></i>
                    <strong> Catatan:</strong> Keputusan Anda (disetujui maupun ditolak) akan dikirimkan kembali ke
                    <strong>Service Advisor</strong>. SA yang berwenang menentukan langkah proses selanjutnya.
                </div>

                <form method="POST" id="formPersetujuan">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-comment-dots"></i> Catatan / Alasan <span class="required">*</span>
                        </label>
                        <textarea
                            name="catatan_pengawas"
                            id="catatan_pengawas"
                            class="form-control"
                            placeholder="Tuliskan catatan atau alasan persetujuan/penolakan Anda..."
                            required
                        ></textarea>
                        <div class="error-msg" id="errorMsg">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Catatan wajib diisi</span>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="button" class="btn btn-success" onclick="submitKeputusan('Disetujui')">
                            <i class="fas fa-thumbs-up"></i> Setujui 
                        </button>

                        <button type="button" class="btn btn-danger" onclick="submitKeputusan('Ditolak')">
                            <i class="fas fa-thumbs-down"></i> Tolak 
                        </button>

                        <a href="pengajuan_perbaikan.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    </div>

                    <input type="hidden" name="action_persetujuan" value="1">
                    <input type="hidden" name="persetujuan" id="persetujuan_value">
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-capitalize textarea
document.getElementById('catatan_pengawas').addEventListener('input', function() {
    const start = this.selectionStart;
    const end = this.selectionEnd;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(start, end);

    if (this.value.trim()) {
        this.classList.remove('error');
        document.getElementById('errorMsg').classList.remove('show');
    }
});

function submitKeputusan(jenis) {
    const catatan = document.getElementById('catatan_pengawas').value.trim();
    const errorMsg = document.getElementById('errorMsg');
    const textarea = document.getElementById('catatan_pengawas');

    if (!catatan) {
        textarea.classList.add('error');
        errorMsg.classList.add('show');
        textarea.focus();
        return;
    }

    textarea.classList.remove('error');
    errorMsg.classList.remove('show');

    const pesan = jenis === 'Disetujui'
        ? '✅ Anda akan MENYETUJUI pengajuan ini.\n\nSetelah disetujui, pengajuan akan dikembalikan ke Service Advisor untuk ditindaklanjuti.\n\nLanjutkan?'
        : '❌ Anda akan MENOLAK pengajuan ini.\n\nPengajuan akan dikembalikan ke Service Advisor beserta catatan penolakan Anda.\n\nLanjutkan?';

    if (confirm(pesan)) {
        document.getElementById('persetujuan_value').value = jenis;
        document.getElementById('formPersetujuan').submit();
    }
}
</script>

</body>
</html>
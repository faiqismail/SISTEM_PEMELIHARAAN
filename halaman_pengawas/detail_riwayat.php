<?php
include "../inc/config.php";
requireAuth('pengawas');
$id_permintaan = isset($_GET['id']) ? intval($_GET['id']) : 0;
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : 'all'; // all, jasa, sparepart


// ==========================
// AMBIL ID PERMINTAAN
// ==========================
$id_permintaan = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ==========================
// CEK ID PERMINTAAN
// ==========================
if ($id_permintaan <= 0) {
    die("❌ ID Permintaan tidak valid atau tidak ditemukan");
}

// Query data permintaan dengan semua relasi
$query = "
    SELECT 
        p.*,
        k.nopol, k.jenis_kendaraan, k.bidang,
        r.nama_rekanan, r.ttd_rekanan,
        u_driver.username AS driver_nama, u_driver.ttd AS driver_ttd,
        u_sa.username AS sa_nama, u_sa.ttd AS sa_ttd,
        u_karu.username AS karu_nama, u_karu.ttd AS karu_ttd,
        u_qc.username AS qc_nama, u_qc.ttd AS qc_ttd
    FROM permintaan_perbaikan p
    LEFT JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    LEFT JOIN rekanan r ON p.id_rekanan = r.id_rekanan
    LEFT JOIN users u_driver ON p.id_pengaju = u_driver.id_user
    LEFT JOIN users u_sa ON p.admin_sa = u_sa.id_user
    LEFT JOIN users u_karu ON p.admin_karu_qc = u_karu.id_user
    LEFT JOIN users u_qc ON p.admin_karu_qc = u_qc.id_user
    WHERE p.id_permintaan = '$id_permintaan'
";
$data = mysqli_fetch_assoc(mysqli_query($connection, $query));

// Query detail jasa
$jasa_detail = mysqli_query($connection, "
    SELECT * FROM perbaikan_detail WHERE id_permintaan = '$id_permintaan'
");

// Query detail sparepart
$sparepart_detail = mysqli_query($connection, "
    SELECT sd.*, s.kode_sparepart, s.nama_sparepart
    FROM sparepart_detail sd
    LEFT JOIN sparepart s ON sd.id_sparepart = s.id_sparepart
    WHERE sd.id_permintaan = '$id_permintaan'
");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Perbaikan - <?= $data['nomor_pengajuan'] ?></title>
    <style>
       @media print {
    .no-print { display: none !important; }
    @page { 
        margin: 10mm;
        size: A4;
    }
    body {
        background: white !important;
        padding: 0 !important;
    }
    .form-wrapper {
        box-shadow: none !important;
        margin-bottom: 0 !important;
        page-break-after: always;
    }
    .form-wrapper:last-child {
        page-break-after: auto;
    }
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.4;
    background: #f5f5f5;
    padding: 10px;
}

.print-buttons {
    text-align: center;
    margin-bottom: 15px;
    background: white;
    padding: 12px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.print-buttons button {
    padding: 10px 20px;
    margin: 5px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
    color: white;
    transition: all 0.3s;
}

.btn-print-all { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.btn-print-jasa { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
.btn-print-part { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
.btn-back { background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); }

.btn-active {
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5), 0 0 0 5px #4f46e5;
    transform: scale(1.05);
}

.print-buttons button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.form-wrapper {
    background: white;
    padding: 15px;
    margin-bottom: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    page-break-after: always;
    border-radius: 10px;
}

.form-wrapper:last-child {
    page-break-after: auto;
}

.form-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    border: 2px solid #000;
    padding: 12px;
    margin-bottom: 15px;
    border-radius: 5px;
}

.logo-section {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}

.logo {
    width: 70px;
    height: 70px;
}

.logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.form-title {
    text-align: center;
    margin-bottom: 10px;
}

.form-title h2 {
    font-size: 11pt;
    margin-bottom: 5px;
    color: #1f2937;
    font-weight: bold;
}

.form-title h3 {
    font-size: 10pt;
    font-weight: bold;
    color: #2d6a4f;
}

.form-code {
    border: 2px solid #000;
    padding: 8px 15px;
    font-weight: bold;
    font-size: 10pt;
    border-radius: 5px;
    background: #f9fafb;
}

.info-section {
    display: grid;
    grid-template-columns: 1fr;
    gap: 6px;
    margin-bottom: 12px;
    padding: 10px;
    background: #f9fafb;
    border-radius: 5px;
}

.info-item {
    display: flex;
    gap: 8px;
    font-size: 9pt;
    word-break: break-word;
}

.info-label {
    font-weight: bold;
    min-width: 110px;
    flex-shrink: 0;
}

.keluhan-box {
    margin: 12px 0;
    padding: 10px;
    border: 2px solid #000;
    border-radius: 5px;
    background: #fffbeb;
}

.keluhan-box strong {
    display: block;
    margin-bottom: 6px;
    color: #92400e;
    font-size: 9pt;
}

.keluhan-box p {
    font-size: 9pt;
    word-break: break-word;
}

.main-table {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0;
    font-size: 8pt;
}

.main-table th,
.main-table td {
    border: 1px solid #000;
    padding: 6px 4px;
    text-align: left;
}

.main-table th {
    background: #e5e7eb;
    font-weight: bold;
    text-align: center;
    font-size: 8pt;
}

.main-table td.center {
    text-align: center;
}

.main-table td.right {
    text-align: right;
}

.main-table tbody tr {
    min-height: 40px;
}

.main-table tbody td {
    vertical-align: top;
    word-break: break-word;
}

.main-table tfoot {
    background: #f3f4f6;
    font-weight: bold;
}

.signature-section {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin-top: 20px;
    text-align: center;
}

.signature-box {
    border: 2px solid #000;
    padding: 8px 6px;
    min-height: 120px;
    border-radius: 5px;
    background: #fafafa;
}

.signature-box .title {
    font-weight: bold;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 2px solid #d1d5db;
    font-size: 8pt;
}

.signature-box .content {
    margin-top: 8px;
}

.signature-box img {
    max-height: 40px;
    max-width: 100px;
    margin: 4px 0;
}

.signature-box .name {
    font-size: 8pt;
    margin-top: 4px;
    font-weight: 600;
    word-break: break-word;
}

.signature-box .position {
    font-size: 7pt;
    color: #6b7280;
    margin-top: 2px;
}

.signature-box .date-time {
    font-size: 7pt;
    color: #374151;
    margin-top: 4px;
    font-style: italic;
}

.location-text {
    text-align: right;
    margin-top: 15px;
    margin-bottom: 12px;
    font-size: 9pt;
    font-weight: bold;
}

.total-section {
    margin-top: 20px;
    padding: 15px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: 3px solid #5568d3;
    text-align: center;
    border-radius: 10px;
    color: white;
}

.total-section .label {
    font-size: 10pt;
    font-weight: bold;
    margin-bottom: 10px;
}

.total-section .amount {
    font-size: 16pt;
    font-weight: bold;
}

.total-section .breakdown {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 2px solid rgba(255,255,255,0.3);
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
    font-size: 9pt;
}

.empty-row {
    height: 50px;
}

/* Tablet dan layar menengah */
@media (min-width: 600px) {
    body {
        padding: 15px;
    }
    
    .form-wrapper {
        padding: 20px;
    }
    
    .form-header {
        flex-direction: row;
        justify-content: space-between;
    }
    
    .logo-section {
        margin-bottom: 0;
    }
    
    .logo {
        width: 80px;
        height: 80px;
    }
    
    .form-title {
        flex: 1;
        padding: 0 15px;
        margin-bottom: 0;
    }
    
    .form-title h2 {
        font-size: 13pt;
    }
    
    .form-title h3 {
        font-size: 12pt;
    }
    
    .info-section {
        grid-template-columns: 1fr 1fr;
    }
    
    .info-item {
        font-size: 9.5pt;
    }
    
    .main-table {
        font-size: 9pt;
    }
    
    .main-table th {
        font-size: 9pt;
    }
    
    .signature-section {
        grid-template-columns: repeat(4, 1fr);
    }
    
    .total-section .breakdown {
        grid-template-columns: 1fr 1fr;
    }
}

/* Desktop */
@media (min-width: 992px) {
    body {
        padding: 20px;
    }
    
    .form-wrapper {
        padding: 25px;
    }
    
    .logo {
        width: 100px;
        height: 100px;
    }
    
    .form-title h2 {
        font-size: 15pt;
    }
    
    .form-title h3 {
        font-size: 14pt;
    }
    
    .form-code {
        font-size: 12pt;
    }
    
    .info-item {
        font-size: 10pt;
    }
    
    .info-label {
        min-width: 150px;
    }
    
    .main-table {
        font-size: 10pt;
    }
    
    .main-table th {
        font-size: 10pt;
    }
    
    .main-table th,
    .main-table td {
        padding: 10px;
    }
    
    .signature-box {
        min-height: 140px;
    }
    
    .signature-box .title {
        font-size: 10pt;
    }
    
    .signature-box .name {
        font-size: 9pt;
    }
    
    .signature-box .position {
        font-size: 8pt;
    }
    
    .signature-box .date-time {
        font-size: 8pt;
    }
    
    .signature-box img {
        max-height: 50px;
        max-width: 130px;
    }
    
    .location-text {
        font-size: 11pt;
    }
    
    .total-section .label {
        font-size: 13pt;
    }
    
    .total-section .amount {
        font-size: 22pt;
    }
    
    .total-section .breakdown {
        font-size: 11pt;
    }
    
    .empty-row {
        height: 80px;
    }
}

@media print {
    .total-section {
        background: #667eea !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
    </style>
</head>
<body>

    <!-- Print Buttons -->
    <div class="print-buttons no-print">
       
        <button class="btn-back" onclick="window.location.href='riwayat.php'">
            ← TUTUP
        </button>
    </div>

    <?php if ($jenis == 'all' || $jenis == 'jasa'): ?>
    <!-- FORM 1: JASA PERBAIKAN -->
    <div class="form-wrapper" id="form-jasa">
        <!-- Header -->
        <div class="form-header">
            <div class="logo-section">
                <div class="logo">
                    <img src="../foto/logo1.png" alt="Logo Perusahaan">
                </div>
            </div>
            <div class="form-title">
                <h2>FORM PERMINTAAN PERBAIKAN KENDARAAN</h2>
                <h3>PT PETROKOPINDO CIPTA SELARAS</h3>
            </div>
            <div class="form-code">
                <?= $data['nomor_pengajuan'] ?>
            </div>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-item">
                <span class="info-label">JENIS KENDARAAN</span>
                <span>: <?= $data['jenis_kendaraan'] ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">NOMOR ASSET</span>
                <span>: <?= $data['nopol'] ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">REKANAN</span>
                <span>: <?= $data['nama_rekanan'] ?? '-' ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">BIDANG</span>
                <span>: <?= $data['bidang'] ?></span>
            </div>
        </div>

        <!-- Keluhan Section -->
        <div class="keluhan-box">
            <strong>KELUHAN / KERUSAKAN :</strong>
            <p><?= $data['keluhan_awal'] ?></p>
        </div>

        <!-- Table Jasa -->
        <table class="main-table">
            <thead>
                <tr>
                    <th style="width: 40px;">NO</th>
                    <th>KELUHAN / KERUSAKAN </th>
                    <th style="width: 60px;">QTY</th>
                    <th style="width: 130px;">HARGA</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                $total_jasa = 0;
                $has_data = false;
                
                mysqli_data_seek($jasa_detail, 0);
                
                if (mysqli_num_rows($jasa_detail) > 0):
                    while($jasa = mysqli_fetch_assoc($jasa_detail)):
                        $total_jasa += $jasa['subtotal'];
                        $has_data = true;
                ?>
                <tr>
                    <td class="center"><?= $no++ ?></td>
                    <td><?= $jasa['nama_pekerjaan'] ?></td>
                    <td class="center"><?= $jasa['qty'] ?></td>
                    <td class="right">Rp <?= number_format($jasa['subtotal'], 0, ',', '.') ?></td>
                </tr>
                <?php 
                    endwhile;
                endif;
                
                if (!$has_data):
                ?>
                <tr>
                    <td class="center">-</td>
                    <td class="empty-row"></td>
                    <td class="center">-</td>
                    <td class="right">-</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="right">TOTAL JASA:</td>
                    <td class="right">Rp <?= number_format($total_jasa, 0, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Location & Date -->
        <div class="location-text">
            GRESIK, <?= date('d F Y', strtotime($data['created_at'])) ?>
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="title">DIAJUKAN,</div>
                <div class="content">
                    <?php if (!empty($data['driver_ttd'])): ?>
                        <img src="../uploads/ttd/<?= $data['driver_ttd'] ?>" alt="TTD Driver">
                    <?php endif; ?>
                    <div class="name"><?= $data['driver_nama'] ?? '-' ?></div>
                    <div class="position">PENGAWAS</div>
                    <?php if (!empty($data['tgl_pengajuan'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_pengajuan'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="signature-box">
                <div class="title">DIPERIKSA,</div>
                <div class="content">
                    <?php if (!empty($data['sa_ttd'])): ?>
                        <img src="../uploads/ttd/<?= $data['sa_ttd'] ?>" alt="TTD SA">
                    <?php endif; ?>
                    <div class="name"><?= $data['sa_nama'] ?? '-' ?></div>
                    <div class="position">SERVICE ADVISOR</div>
                    <?php if (!empty($data['tgl_diperiksa_sa'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_diperiksa_sa'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="signature-box">
                <div class="title">DISETUJUI,</div>
                <div class="content">
                    <?php if (!empty($data['ttd_karu_qc'])): ?>
                        <img src="../uploads/ttd/<?= $data['ttd_karu_qc'] ?>" alt="TTD KARU">
                    <?php endif; ?>
                    <div class="name"><?= $data['karu_nama'] ?? '-' ?></div>
                    <div class="position">KARU QC</div>
                    <?php if (!empty($data['tgl_disetujui_karu_qc'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_disetujui_karu_qc'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="signature-box">
                <div class="title">REKANAN</div>
                <div class="content">
                    <?php if (!empty($data['ttd_rekanan'])): ?>
                        <img src="../uploads/ttd_rekanan/<?= $data['ttd_rekanan'] ?>" alt="TTD Rekanan">
                    <?php endif; ?>
                    <div class="name"><?= $data['nama_rekanan'] ?? '-' ?></div>
                    <?php if (!empty($data['tgl_selesai'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_selesai'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($jenis == 'all' || $jenis == 'sparepart'): ?>
    <!-- FORM 2: SPAREPART -->
    <div class="form-wrapper" id="form-sparepart">
        <!-- Header -->
        <div class="form-header">
            <div class="logo-section">
                <div class="logo">
                    <img src="../foto/logo1.png" alt="Logo Perusahaan">
                </div>
            </div>
            <div class="form-title">
                <h2>PERMINTAAN BARANG / SPAREPART</h2>
                <h3>PT PETROKOPINDO CIPTA SELARAS</h3>
            </div>
            <div class="form-code">
                <?= $data['nomor_pengajuan'] ?>
            </div>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-item">
                <span class="info-label">NOMOR ASSET</span>
                <span>: <?= $data['nopol'] ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">BIDANG</span>
                <span>: <?= $data['bidang'] ?></span>
            </div>
        </div>

        <!-- Table Sparepart -->
        <table class="main-table">
            <thead>
                <tr>
                    <th style="width: 40px;">NO</th>
                    <th>KELUHAN / KERUSAKAN</th>
                    <th style="width: 80px;">JUMLAH</th>
                    <th style="width: 130px;">HARGA</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                $total_sparepart = 0;
                $has_data_part = false;
                
                mysqli_data_seek($sparepart_detail, 0);
                
                if (mysqli_num_rows($sparepart_detail) > 0):
                    while($part = mysqli_fetch_assoc($sparepart_detail)):
                        $total_sparepart += $part['subtotal'];
                        $has_data_part = true;
                ?>
                <tr>
                    <td class="center"><?= $no++ ?></td>
                    <td><?= $part['nama_sparepart'] ?></td>
                    <td class="center"><?= $part['qty'] ?></td>
                    <td class="right">Rp <?= number_format($part['subtotal'], 0, ',', '.') ?></td>
                </tr>
                <?php 
                    endwhile;
                endif;
                
                if (!$has_data_part):
                ?>
                <tr>
                    <td class="center">-</td>
                    <td class="empty-row"></td>
                    <td class="center">-</td>
                    <td class="right">-</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="right">TOTAL SPAREPART:</td>
                    <td class="right">Rp <?= number_format($total_sparepart, 0, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Location & Date -->
        <div class="location-text">
            GRESIK, <?= date('d F Y', strtotime($data['created_at'])) ?>
        </div>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="title">DIAJUKAN,</div>
                <div class="content">
                    <?php if (!empty($data['driver_ttd'])): ?>
                        <img src="../uploads/ttd/<?= $data['driver_ttd'] ?>" alt="TTD Driver">
                    <?php endif; ?>
                    <div class="name"><?= $data['driver_nama'] ?? '-' ?></div>
                    <div class="position">PENGAWAS</div>
                    <?php if (!empty($data['tgl_pengajuan'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_pengajuan'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="signature-box">
                <div class="title">DIPERIKSA,</div>
                <div class="content">
                    <?php if (!empty($data['ttd_sa'])): ?>
                        <img src="../uploads/ttd/<?= $data['ttd_sa'] ?>" alt="TTD QC">
                    <?php endif; ?>
                    <div class="name"><?= $data['sa_nama'] ?? '-' ?></div>
                    <div class="position">SERVICE ADVISOR</div>
                    <?php if (!empty($data['tgl_diperiksa_sa'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_diperiksa_sa'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="signature-box">
                <div class="title">DISETUJUI,</div>
                <div class="content">
                    <?php if (!empty($data['ttd_karu_qc'])): ?>
                        <img src="../uploads/ttd/<?= $data['ttd_karu_qc'] ?>" alt="TTD KARU">
                    <?php endif; ?>
                    <div class="name"><?= $data['karu_nama'] ?? '-' ?></div>
                    <div class="position">KARU QC</div>
                    <?php if (!empty($data['tgl_disetujui_karu_qc'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_disetujui_karu_qc'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="signature-box">
                <div class="title">REKANAN</div>
                <div class="content">
                    <?php if (!empty($data['ttd_rekanan'])): ?>
                        <img src="../uploads/ttd_rekanan/<?= $data['ttd_rekanan'] ?>" alt="TTD Rekanan">
                    <?php endif; ?>
                    <div class="name"><?= $data['nama_rekanan'] ?? '-' ?></div>
                    <?php if (!empty($data['tgl_selesai'])): ?>
                        <div class="date-time"><?= date('d/m/Y H:i', strtotime($data['tgl_selesai'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($jenis == 'all'): ?>
    <!-- TOTAL KESELURUHAN -->
    <div class="total-section no-print">
        <div class="label">TOTAL KESELURUHAN BIAYA PERBAIKAN</div>
        <div class="amount">Rp <?= number_format($data['grand_total'], 0, ',', '.') ?></div>
        <div class="breakdown">
            <div>Total Jasa: Rp <?= number_format($data['total_perbaikan'], 0, ',', '.') ?></div>
            <div>Total Sparepart: Rp <?= number_format($data['total_sparepart'], 0, ',', '.') ?></div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function printAll() {
            // Redirect ke URL tanpa parameter jenis atau dengan jenis=all
            window.location.href = 'print_form_perbaikan.php?id=<?= $id_permintaan ?>';
        }

        function printJasa() {
            window.location.href = 'print_form_perbaikan.php?id=<?= $id_permintaan ?>&jenis=jasa';
        }

        function printPart() {
            window.location.href = 'print_form_perbaikan.php?id=<?= $id_permintaan ?>&jenis=sparepart';
        }

        // Auto print jika parameter print=1
        <?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>
    </script>

</body>
</html>
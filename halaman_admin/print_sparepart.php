<?php
// ✅ ob_start HARUS di baris PERTAMA
ob_start();

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";

requireAuth('admin');

// ============================================================
// AMBIL ID YANG DIPILIH DARI list_kendaraan.php
// ============================================================
$ids_raw = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
$ids = array_filter(array_map('intval', $ids_raw), function ($v) {
    return $v > 0;
});

if (empty($ids)) {
    die('<div style="padding:40px;font-family:sans-serif;text-align:center;">
            <h2 style="color:#dc2626;">Tidak ada data yang dipilih untuk dicetak.</h2>
            <p><a href="list_kendaraan.php">Kembali ke daftar</a></p>
         </div>');
}

$ids_str = implode(',', $ids);

// ============================================================
// AMBIL DATA HEADER PERMINTAAN (HANYA YANG SUDAH DISETUJUI PENGAWAS)
// ============================================================
$query = "
SELECT 
    p.id_permintaan,
    p.nomor_pengajuan,
    p.tgl_pengajuan,
    p.tgl_persetujuan_pengawas,
    p.keluhan_awal,
    p.catatan_sa,
    p.catatan_pengawas,
    p.grand_total,
    k.nopol,
    k.jenis_kendaraan,
    k.bidang,
    u_unit.nama AS unit_nama,
    u_unit.jabatan AS unit_jabatan,
    u_unit.ttd AS unit_ttd,
    u_karu.nama AS karu_qc_nama,
    u_karu.jabatan AS karu_qc_jabatan,
    u_karu.ttd AS karu_qc_ttd,
    u_qc.nama AS qc_nama,
    u_qc.jabatan AS qc_jabatan,
    u_qc.ttd AS qc_ttd
FROM permintaan_perbaikan p
INNER JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
LEFT JOIN users u_unit ON p.id_pengaju = u_unit.id_user
LEFT JOIN users u_karu ON p.admin_karu_qc = u_karu.id_user
LEFT JOIN users u_qc ON p.admin_karu_qc = u_qc.id_user
WHERE p.id_permintaan IN ($ids_str)
  AND p.status = 'Dikembalikan_sa'
  AND p.persetujuan_pengawas = 'Disetujui'
ORDER BY FIELD(p.id_permintaan, $ids_str)
";

// Fungsi bantu: kalau jabatan kosong atau cuma tanda "-", anggap tidak ada (kosongkan)
function bersihkan_jabatan($jabatan) {
    $jabatan = trim((string)($jabatan ?? ''));
    return ($jabatan === '' || $jabatan === '-') ? '' : $jabatan;
}

// ============================================================
// TAMBAHAN: KARU QC DIPILIH SAAT CETAK (TIDAK DISIMPAN KE DATABASE)
// Diambil dari parameter GET "karu_qc" yang dikirim dari modal
// pilih Karu QC di list_kendaraan.php. Karena proses approval
// Karu QC belum berjalan di sistem, data ini murni untuk
// tampilan form cetak dan tidak pernah menyentuh tabel apa pun.
// ============================================================
$karu_qc_id = isset($_GET['karu_qc']) ? (int) $_GET['karu_qc'] : 0;
$karu_qc_pilihan = null;

if ($karu_qc_id > 0) {
    $karu_qc_id_safe = (int) $karu_qc_id;
    $karu_result = mysqli_query($connection, "SELECT nama, jabatan, ttd FROM users WHERE id_user = $karu_qc_id_safe AND status = 'Aktif' LIMIT 1");
    if ($karu_result && mysqli_num_rows($karu_result) > 0) {
        $karu_qc_pilihan = mysqli_fetch_assoc($karu_result);
        $karu_qc_pilihan['nama']    = trim((string)($karu_qc_pilihan['nama'] ?? ''));
        $karu_qc_pilihan['jabatan'] = bersihkan_jabatan($karu_qc_pilihan['jabatan']);
        $karu_qc_pilihan['ttd']     = trim((string)($karu_qc_pilihan['ttd'] ?? ''));
    }
}

// Tanggal & jam Karu QC = waktu saat halaman cetak dibuka (real-time, TIDAK dari database)
$tgl_cetak_karu_qc = date('d/m/Y H:i');

$result = mysqli_query($connection, $query);

$data_cetak = [];
while ($row = mysqli_fetch_assoc($result)) {

    // Unit -> id_pengaju (yang mengajukan permintaan)
    $row['unit_nama'] = trim((string)($row['unit_nama'] ?? ''));
    $row['unit_jabatan'] = bersihkan_jabatan($row['unit_jabatan']);
    $row['unit_ttd'] = trim((string)($row['unit_ttd'] ?? ''));

    // QC -> admin_karu_qc (sama seperti Karu QC, sesuai pola di file lain)
    $row['qc_nama'] = trim((string)($row['qc_nama'] ?? ''));
    $row['qc_jabatan'] = bersihkan_jabatan($row['qc_jabatan']);
    $row['qc_ttd'] = trim((string)($row['qc_ttd'] ?? ''));

    // Karu QC -> admin_karu_qc (relasi ke tabel users) — hanya sebagai fallback
    // jika tidak ada pilihan Karu QC manual dari modal cetak.
    $row['karu_qc_nama'] = trim((string)($row['karu_qc_nama'] ?? ''));
    $row['karu_qc_jabatan'] = bersihkan_jabatan($row['karu_qc_jabatan']);
    $row['karu_qc_ttd'] = trim((string)($row['karu_qc_ttd'] ?? ''));

    // Ambil detail sparepart untuk id_permintaan ini
    $id_permintaan_safe = (int) $row['id_permintaan'];
    $sparepart_query = "
        SELECT sd.qty, sp.kode_sparepart, sp.nama_sparepart, sp.satuan
        FROM sparepart_detail sd
        INNER JOIN sparepart sp ON sd.id_sparepart = sp.id_sparepart
        WHERE sd.id_permintaan = $id_permintaan_safe
        ORDER BY sd.id_detail ASC
    ";
    $sparepart_result = mysqli_query($connection, $sparepart_query);
    $spareparts = [];
    while ($sp = mysqli_fetch_assoc($sparepart_result)) {
        $spareparts[] = $sp;
    }

    $row['spareparts'] = $spareparts;
    $data_cetak[] = $row;
}

if (empty($data_cetak)) {
    die('<div style="padding:40px;font-family:sans-serif;text-align:center;">
            <h2 style="color:#dc2626;">Data tidak ditemukan / belum Disetujui Unit.</h2>
            <p><a href="list_kendaraan.php">Kembali ke daftar</a></p>
         </div>');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cetak Form Sparepart</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background:rgb(185, 224, 204);
    }

    /* ====== TOOLBAR (TIDAK IKUT TERCETAK) ====== */
    .toolbar {
        position: sticky;
        top: 0;
        background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%);
        color: white;
        padding: 14px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 999;
    }

    .toolbar h2 {
        font-size: 1rem;
        font-weight: 600;
    }

    .toolbar button {
        background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
    }

    .toolbar button:hover {
        opacity: 0.9;
    }

    .toolbar .btn-back {
        background: #475569;
        margin-right: 10px;
    }

    /* ====== UKURAN KERTAS A5 ====== */
    @page {
        size: A5;
        margin: 8mm;
    }

    .page {
        width: 148mm;
        min-height: 210mm;
        background: white;
        margin: 20px auto;
        padding: 8mm 8mm 20mm 8mm;
        box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        page-break-after: always;
        position: relative;
    }

    .page:last-child {
        page-break-after: auto;
    }

    /* ====== HEADER FORM ====== */
    .form-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 3px solid #097853;
        padding-bottom: 8px;
        margin-bottom: 10px;
    }

    .form-header .company {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-header .logo img {
        height: 34px;
        width: auto;
        max-width: 150px;
        object-fit: contain;
        display: block;
    }

    .form-header .company-name {
        font-size: 11px;
        font-weight: 700;
        color: #097853;
        line-height: 1.2;
    }

    .form-header .company-name span {
        display: block;
        font-size: 8px;
        font-weight: 400;
        color: #555;
    }

    .form-header .nomor-part {
        font-size: 10px;
        font-weight: 700;
        color: #333;
    }

    .form-title {
        text-align: center;
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 12px;
        color: #111;
    }

    .form-title .page-info {
        font-size: 9px;
        font-weight: 600;
        color: #6d28d9;
        margin-top: 3px;
    }

    /* ====== INFO ATAS ====== */
    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4px 10px;
        font-size: 10px;
        margin-bottom: 10px;
    }

    .info-grid .full {
        grid-column: 1 / -1;
    }

    .info-row {
        display: flex;
    }

    .info-row .label {
        font-weight: 700;
        min-width: 78px;
    }

    .info-row .value {
        flex: 1;
        border-bottom: 1px dotted #999;
    }

    /* ====== TABEL SPAREPART ====== */
    .list-title {
        text-align: center;
        font-weight: 700;
        font-size: 11px;
        margin: 10px 0 6px;
        text-transform: uppercase;
    }

    table.sparepart-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 9.5px;
        margin-bottom: 10px;
    }

    table.sparepart-table th,
    table.sparepart-table td {
        border: 1px solid #333;
        padding: 4px 6px;
    }

    table.sparepart-table th {
        background: #097853;
        color: white;
        font-weight: 600;
        text-align: center;
    }

    table.sparepart-table td.no {
        text-align: center;
        width: 18px;
    }

    table.sparepart-table td.jumlah {
        text-align: center;
        width: 45px;
    }

    table.sparepart-table td.kode {
        width: 55px;
        text-align: center;
        font-family: monospace;
    }

    table.sparepart-table tr.empty-row td {
        height: 16px;
    }

    /* ====== CATATAN / KETERANGAN PERSETUJUAN ====== */
    .approval-note {
        background: #f3f0ff;
        border-left: 3px solid #8b5cf6;
        padding: 6px 8px;
        font-size: 9px;
        margin-bottom: 10px;
        border-radius: 4px;
    }

    .approval-note b {
        color: #6d28d9;
    }

    /* ====== TANDA TANGAN ====== */
    .ttd-section {
        margin-top: 20px;
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        text-align: center;
        font-size: 9.5px;
    }

    .ttd-section .ttd-wrapper {
        height: 14mm;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 4px 0 2px;
    }

    .ttd-section .ttd-photo {
        max-height: 14mm;
        max-width: 70%;
        object-fit: contain;
        display: block;
    }

    .ttd-section .ttd-nama {
        font-size: 9px;
        font-weight: 600;
        color: #333;
        margin-top: 2px;
        min-height: 11px;
    }

    .ttd-section .ttd-line {
        border-top: 1px solid #333;
        font-size: 1px;
        line-height: 1;
        margin-top: 4px;
        padding-top: 0;
    }

    .ttd-section .ttd-jabatan {
        font-size: 8px;
        font-weight: 400;
        color: #777;
        font-style: italic;
        margin-top: 3px;
    }

    .ttd-section .ttd-title {
        font-weight: 600;
        margin-bottom: 0;
    }

    /* ====== TAMBAHAN: Tanggal/jam Karu QC saat cetak ====== */
    .ttd-section .ttd-tanggal-cetak {
        font-size: 7.5px;
        color: #6d28d9;
        margin-top: 2px;
        font-weight: 600;
    }

    .continue-note {
        text-align: center;
        font-size: 9px;
        color: #888;
        font-style: italic;
        margin-top: 10px;
        padding-top: 8px;
        border-top: 1px dashed #ccc;
    }

    .footer-note {
        position: absolute;
        bottom: 6mm;
        left: 8mm;
        right: 8mm;
        font-size: 7.5px;
        color: #999;
        text-align: center;
        border-top: 1px solid #eee;
        padding-top: 4px;
    }

    /* ====== MODE PRINT ====== */
    @media print {
        body { background: white; }
        .toolbar { display: none; }
        .page {
            margin: 0;
            box-shadow: none;
            width: auto;
            min-height: auto;
        }
    }
</style>
</head>
<body>

<div class="toolbar">
    <h2><i class="fas fa-print"></i> Preview Cetak (<?= count($data_cetak) ?> Dokumen) — Ukuran A5
        <?php if ($karu_qc_pilihan): ?>
            &middot; Karu QC: <?= htmlspecialchars($karu_qc_pilihan['nama']) ?>
        <?php endif; ?>
    </h2>
    <div>
        <button class="btn-back" onclick="window.close()">Tutup</button>
        <button onclick="window.print()">🖨️ Cetak Sekarang</button>
    </div>
</div>

<?php
// Jumlah baris sparepart maksimal per halaman A5 agar tulisan TIDAK diperkecil.
// Jika data lebih banyak dari ini, otomatis dibuat ke halaman berikutnya (dokumen yang sama).
$maxRowsPerPage = 10;

foreach ($data_cetak as $data):
    $spareparts = $data['spareparts'];
    $totalItems = count($spareparts);
    $chunks = $totalItems > 0 ? array_chunk($spareparts, $maxRowsPerPage) : [[]];
    $totalPages = count($chunks);
    $rowNumber = 1;

    foreach ($chunks as $pageIndex => $chunkItems):
        $isFirstPage = ($pageIndex === 0);
        $isLastPage = ($pageIndex === $totalPages - 1);
?>
<div class="page">

    <div class="form-header">
        <div class="company">
            <div class="logo">
                <img src="../foto/logo.png" alt="Logo Perusahaan">
            </div>
           
        </div>
        <div class="nomor-part"><?= htmlspecialchars($data['nomor_pengajuan']) ?></div>
    </div>

    <div class="form-title">
        FORM PERMINTAAN SPAREPART ARMADA
        <?php if ($totalPages > 1): ?>
            <div class="page-info">Halaman <?= $pageIndex + 1 ?> dari <?= $totalPages ?></div>
        <?php endif; ?>
    </div>

    <?php if ($isFirstPage): ?>
    <div class="info-grid">
        <div class="info-row">
            <div class="label">Nomor Polisi</div>
            <div class="value"><?= htmlspecialchars($data['nopol']) ?></div>
        </div>
        <div class="info-row">
            <div class="label">Pemohon</div>
            <div class="value"><?= $data['unit_nama'] ? htmlspecialchars($data['unit_nama']) : '-' ?></div>
        </div>
        <div class="info-row">
            <div class="label">Bidang</div>
            <div class="value"><?= htmlspecialchars($data['bidang']) ?></div>
        </div>
        <div class="info-row">
            <div class="label">Tanggal</div>
            <div class="value"><?= date('d/m/Y H:i', strtotime($data['tgl_pengajuan'])) ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="info-grid">
        <div class="info-row">
            <div class="label">Nomor Polisi</div>
            <div class="value"><?= htmlspecialchars($data['nopol']) ?></div>
        </div>
        <div class="info-row">
            <div class="label">No. Pengajuan</div>
            <div class="value"><?= htmlspecialchars($data['nomor_pengajuan']) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="list-title">
        List Permintaan Sparepart<?= (!$isFirstPage) ? ' (Lanjutan)' : '' ?>
    </div>

    <table class="sparepart-table">
        <thead>
            <tr>
                <th style="width:18px;">No</th>
                <th style="width:55px;">Kode</th>
                <th>Sparepart</th>
                <th style="width:45px;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($chunkItems)): ?>
                <?php foreach ($chunkItems as $sp): ?>
                <tr>
                    <td class="no"><?= $rowNumber++ ?></td>
                    <td class="kode"><?= htmlspecialchars($sp['kode_sparepart'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($sp['nama_sparepart']) ?></td>
                    <td class="jumlah"><?= (int)$sp['qty'] ?> Pcs</td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align:center; color:#999;">Tidak ada data sparepart</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($isLastPage): ?>
        <?php if (!empty($data['catatan_pengawas']) || !empty($data['catatan_sa'])): ?>
        <div class="approval-note">
            <b>Disetujui Pemohon</b>
            pada <?= $data['tgl_persetujuan_pengawas'] ? date('d/m/Y H:i', strtotime($data['tgl_persetujuan_pengawas'])) : '-' ?>
          
        </div>
        <?php endif; ?>

        <div class="ttd-section">
            <div>
                <div class="ttd-title">Unit,</div>
                <div class="ttd-wrapper">
                    <?php if (!empty($data['unit_ttd'])): ?>
                        <img class="ttd-photo" src="../uploads/ttd/<?= htmlspecialchars($data['unit_ttd']) ?>" alt="TTD Unit">
                    <?php else: ?>
                        <img class="ttd-photo" src="../fotodata/pcs.png" alt="Foto">
                    <?php endif; ?>
                </div>
                <div class="ttd-nama"><?= $data['unit_nama'] ? htmlspecialchars($data['unit_nama']) : '&nbsp;' ?></div>
                <div class="ttd-line">&nbsp;</div>
                <div class="ttd-jabatan"><?= $data['unit_jabatan'] ? htmlspecialchars($data['unit_jabatan']) : '&nbsp;' ?></div>
            </div>
            <div>
                <div class="ttd-title">QC,</div>
                <div class="ttd-wrapper">
                    <?php if (!empty($data['qc_ttd'])): ?>
                        <img class="ttd-photo" src="../uploads/ttd/<?= htmlspecialchars($data['qc_ttd']) ?>" alt="TTD QC">
                    <?php else: ?>
                        <img class="ttd-photo" src="../fotodata/pcs.png" alt="Foto">
                    <?php endif; ?>
                </div>
                <div class="ttd-nama"><?= $data['qc_nama'] ? htmlspecialchars($data['qc_nama']) : '&nbsp;' ?></div>
                <div class="ttd-line">&nbsp;</div>
                <div class="ttd-jabatan"><?= $data['qc_jabatan'] ? htmlspecialchars($data['qc_jabatan']) : '&nbsp;' ?></div>
            </div>
            <div>
                <div class="ttd-title">Karu QC,</div>
                <div class="ttd-wrapper">
                    <?php if ($karu_qc_pilihan && !empty($karu_qc_pilihan['ttd'])): ?>
                        <img class="ttd-photo" src="../uploads/ttd/<?= htmlspecialchars($karu_qc_pilihan['ttd']) ?>" alt="TTD Karu QC">
                    <?php else: ?>
                        <img class="ttd-photo" src="../fotodata/pcs.png" alt="Foto">
                    <?php endif; ?>
                </div>
                <div class="ttd-nama">
                    <?= $karu_qc_pilihan && $karu_qc_pilihan['nama'] ? htmlspecialchars($karu_qc_pilihan['nama']) : '&nbsp;' ?>
                </div>
                <div class="ttd-line">&nbsp;</div>
                <div class="ttd-jabatan">
                    <?= $karu_qc_pilihan && $karu_qc_pilihan['jabatan'] ? htmlspecialchars($karu_qc_pilihan['jabatan']) : '&nbsp;' ?>
                </div>
              
            </div>
        </div>
    <?php else: ?>
        <div class="continue-note">
            Bersambung ke halaman berikutnya (<?= $totalItems - ($rowNumber - 1) ?> item lagi)...
        </div>
    <?php endif; ?>

    <div class="footer-note">
        Dicetak dari sistem &middot; <?= date('d/m/Y H:i') ?> &middot; No. Pengajuan: <?= htmlspecialchars($data['nomor_pengajuan']) ?>
    </div>

</div>
<?php
    endforeach; // chunks
endforeach; // data_cetak
?>

</body>
</html>
<?php
ob_end_flush();
?>
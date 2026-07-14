<?php
include "../inc/config.php";
requireAuth('pengawas');
date_default_timezone_set('Asia/Jakarta');

$id_user = $_SESSION['id_user'];
$user_info = mysqli_fetch_assoc(
    mysqli_query($connection, "SELECT username, role FROM users WHERE id_user='$id_user'")
);
$nama_user = $user_info['username'];
$role_user = $user_info['role'];

// ═══════════════════════════════════════════════════════════
// BIDANG USER — dikunci dari SESSION hasil login (bukan input bebas)
// Semua data pada halaman ini hanya untuk bidang milik user.
// ═══════════════════════════════════════════════════════════
$bidang_user = $_SESSION['bidang'] ?? '';
$bidang_esc  = mysqli_real_escape_string($connection, $bidang_user);
$bidang_where = $bidang_user !== '' ? "AND k.bidang = '$bidang_esc'" : "AND 1=0";

// ═══════════════════════════════════════════════════════════
// FILTER PARAMETERS (bidang DIHAPUS dari filter bebas)
// ═══════════════════════════════════════════════════════════
$filter_tahun   = isset($_GET['filter_tahun'])   ? (int)$_GET['filter_tahun']   : (int)date('Y');
$filter_bulan   = isset($_GET['filter_bulan'])   ? (int)$_GET['filter_bulan']   : 0;
$filter_tanggal = isset($_GET['filter_tanggal']) ? mysqli_real_escape_string($connection, $_GET['filter_tanggal']) : '';

// Build date WHERE clause
$date_where = "1=1";
if (!empty($filter_tanggal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_tanggal)) {
    $date_where = "DATE(p.tgl_pengajuan) = '$filter_tanggal'";
} elseif ($filter_bulan > 0) {
    $date_where = "YEAR(p.tgl_pengajuan) = '$filter_tahun' AND MONTH(p.tgl_pengajuan) = '$filter_bulan'";
} elseif ($filter_tahun > 0) {
    $date_where = "YEAR(p.tgl_pengajuan) = '$filter_tahun'";
}

// ═══════════════════════════════════════════════════════════
// DROPDOWN DATA (dropdown bidang DIHAPUS, tinggal daftar tahun)
// ═══════════════════════════════════════════════════════════
$tahun_list_q = mysqli_query($connection,
    "SELECT DISTINCT YEAR(tgl_pengajuan) AS thn FROM permintaan_perbaikan ORDER BY thn DESC");
$tahun_list = [];
while ($r = mysqli_fetch_assoc($tahun_list_q)) $tahun_list[] = (int)$r['thn'];
if (empty($tahun_list)) $tahun_list = [(int)date('Y')];
if (!in_array((int)date('Y'), $tahun_list)) array_unshift($tahun_list, (int)date('Y'));

// ═══════════════════════════════════════════════════════════
// STATS CARDS
// DISAMAKAN 1:1 DENGAN LOGIKA DI pengajuan_perbaikan.php
// ═══════════════════════════════════════════════════════════
// - proses                    : masih jalan normal di alur SA/QC, belum ada
//                                keputusan persetujuan_pengawas, dan belum Selesai.
// - perlu_persetujuan_unit    : status Dikembalikan_sa / Minta_Persetujuan_Pengawas
//                                DAN persetujuan_pengawas masih 'Menunggu'
//                                -> benar-benar menunggu KEPUTUSAN pengawas.
// - tindak_lanjut_pemeliharaan: persetujuan_pengawas = 'Disetujui' DAN status
//                                belum 'Selesai' -> sedang dikerjakan di bengkel.
// - ditolak_unit               : persetujuan_pengawas = 'Ditolak' DAN status
//                                belum 'Selesai' -> ditolak, menunggu SA.
// - selesai                   : status Selesai.
// Total = proses + perlu_persetujuan_unit + tindak_lanjut_pemeliharaan
//         + ditolak_unit + selesai
// ═══════════════════════════════════════════════════════════
$stats_q = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN p.status != 'Selesai'
                      AND p.status NOT IN ('Minta_Persetujuan_Pengawas','Dikembalikan_sa')
                      AND (p.persetujuan_pengawas IS NULL OR p.persetujuan_pengawas NOT IN ('Disetujui','Ditolak'))
                 THEN 1 ELSE 0 END) AS proses,
        SUM(CASE WHEN p.status IN ('Dikembalikan_sa','Minta_Persetujuan_Pengawas')
                      AND p.persetujuan_pengawas = 'Menunggu'
                 THEN 1 ELSE 0 END) AS perlu_persetujuan_unit,
        SUM(CASE WHEN p.status != 'Selesai'
                      AND p.persetujuan_pengawas = 'Disetujui'
                 THEN 1 ELSE 0 END) AS tindak_lanjut_pemeliharaan,
        SUM(CASE WHEN p.status != 'Selesai'
                      AND p.persetujuan_pengawas = 'Ditolak'
                 THEN 1 ELSE 0 END) AS ditolak_unit,
        SUM(CASE WHEN p.status = 'Selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(p.grand_total) AS total_biaya,
        COUNT(DISTINCT p.id_kendaraan) AS unit_aktif
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     WHERE $date_where $bidang_where"));

$total                      = (int)($stats_q['total'] ?? 0);
$proses                     = (int)($stats_q['proses'] ?? 0);
$perlu_persetujuan_unit     = (int)($stats_q['perlu_persetujuan_unit'] ?? 0);
$tindak_lanjut_pemeliharaan = (int)($stats_q['tindak_lanjut_pemeliharaan'] ?? 0);
$ditolak_unit               = (int)($stats_q['ditolak_unit'] ?? 0);
$selesai                    = (int)($stats_q['selesai'] ?? 0);
$total_biaya                = $stats_q['total_biaya'] ?? 0;
$unit_aktif                 = (int)($stats_q['unit_aktif'] ?? 0);

// ═══════════════════════════════════════════════════════════
// STATUS BREAKDOWN PER BIDANG (mini-bar chart)
// Karena akses sudah dikunci per bidang user, breakdown ini otomatis
// hanya akan menampilkan satu baris (bidang milik user).
// ═══════════════════════════════════════════════════════════
$bidang_stats_q = mysqli_query($connection,
    "SELECT k.bidang,
        COUNT(*) AS total,
        SUM(CASE WHEN p.status='Selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN p.status!='Selesai' THEN 1 ELSE 0 END) AS proses
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     WHERE $date_where $bidang_where AND k.bidang IS NOT NULL AND k.bidang != ''
     GROUP BY k.bidang ORDER BY total DESC LIMIT 8");
$bidang_stats = [];
while ($r = mysqli_fetch_assoc($bidang_stats_q)) $bidang_stats[] = $r;

// ═══════════════════════════════════════════════════════════
// TOP 10 KENDARAAN TERBANYAK PENGAJUAN
// ═══════════════════════════════════════════════════════════
$top10_q = mysqli_query($connection,
    "SELECT k.nopol, k.jenis_kendaraan, k.bidang, k.tahun_kendaraan,
            COUNT(p.id_permintaan) AS jumlah_pengajuan,
            SUM(p.grand_total) AS total_biaya
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     WHERE $date_where $bidang_where
     GROUP BY k.id_kendaraan, k.nopol, k.jenis_kendaraan, k.bidang, k.tahun_kendaraan
     ORDER BY jumlah_pengajuan DESC, total_biaya DESC
     LIMIT 10");
$top10_list = [];
while ($r = mysqli_fetch_assoc($top10_q)) $top10_list[] = $r;
$max_top10 = !empty($top10_list) ? (int)$top10_list[0]['jumlah_pengajuan'] : 1;

// ═══════════════════════════════════════════════════════════
// PANEL KANAN — Analisis Biaya
// ═══════════════════════════════════════════════════════════
$avg_q = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT AVG(p.grand_total) AS avg_biaya,
            MAX(p.grand_total) AS max_biaya,
            MIN(CASE WHEN p.grand_total>0 THEN p.grand_total END) AS min_biaya,
            SUM(CASE WHEN p.status!='Selesai' THEN p.grand_total ELSE 0 END) AS biaya_proses,
            SUM(CASE WHEN p.status='Selesai' THEN p.grand_total ELSE 0 END) AS biaya_selesai
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan=k.id_kendaraan
     WHERE $date_where $bidang_where"));
$avg_biaya    = $avg_q['avg_biaya']    ?? 0;
$max_biaya    = $avg_q['max_biaya']    ?? 0;
$min_biaya    = $avg_q['min_biaya']    ?? 0;
$biaya_proses = $avg_q['biaya_proses'] ?? 0;
$biaya_selesai_nominal = $avg_q['biaya_selesai'] ?? 0;

// ═══════════════════════════════════════════════════════════
// DAFTAR NOMOR ASSET PER KATEGORI STATUS (untuk tab panel kanan)
// Query & kategori disamakan 1:1 dengan filter_status di pengajuan_perbaikan.php
// sehingga jumlah baris = angka pada kartu terkait di atas.
// ═══════════════════════════════════════════════════════════

// Tab 1: Proses (masih di alur SA/QC, belum ada keputusan pengawas)
$q_proses = mysqli_query($connection,
    "SELECT k.nopol, k.jenis_kendaraan, k.bidang, p.status, p.nomor_pengajuan, p.tgl_pengajuan
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan=k.id_kendaraan
     WHERE $date_where $bidang_where
       AND p.status != 'Selesai'
       AND p.status NOT IN ('Minta_Persetujuan_Pengawas','Dikembalikan_sa')
       AND (p.persetujuan_pengawas IS NULL OR p.persetujuan_pengawas NOT IN ('Disetujui','Ditolak'))
     ORDER BY p.tgl_pengajuan DESC");
$list_proses = [];
while($r = mysqli_fetch_assoc($q_proses)) $list_proses[] = $r;

// Tab 2: Perlu Persetujuan Unit (menunggu keputusan pengawas)
$q_persetujuan = mysqli_query($connection,
    "SELECT k.nopol, k.jenis_kendaraan, k.bidang, p.status, p.nomor_pengajuan, p.tgl_pengajuan
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan=k.id_kendaraan
     WHERE $date_where $bidang_where
       AND p.status IN ('Dikembalikan_sa','Minta_Persetujuan_Pengawas')
       AND p.persetujuan_pengawas = 'Menunggu'
     ORDER BY p.tgl_pengajuan DESC");
$list_persetujuan = [];
while($r = mysqli_fetch_assoc($q_persetujuan)) $list_persetujuan[] = $r;

// Tab 3: Tindak Lanjut Pemeliharaan (sudah disetujui, dikerjakan bengkel)
$q_pemeliharaan = mysqli_query($connection,
    "SELECT k.nopol, k.jenis_kendaraan, k.bidang, p.status, p.nomor_pengajuan, p.tgl_pengajuan, p.tgl_persetujuan_pengawas
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan=k.id_kendaraan
     WHERE $date_where $bidang_where
       AND p.status != 'Selesai'
       AND p.persetujuan_pengawas = 'Disetujui'
     ORDER BY p.tgl_pengajuan DESC");
$list_pemeliharaan = [];
while($r = mysqli_fetch_assoc($q_pemeliharaan)) $list_pemeliharaan[] = $r;

// Tab 4: Ditolak Unit
$q_ditolak = mysqli_query($connection,
    "SELECT k.nopol, k.jenis_kendaraan, k.bidang, p.status, p.nomor_pengajuan, p.tgl_pengajuan, p.tgl_persetujuan_pengawas
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan=k.id_kendaraan
     WHERE $date_where $bidang_where
       AND p.status != 'Selesai'
       AND p.persetujuan_pengawas = 'Ditolak'
     ORDER BY p.tgl_pengajuan DESC");
$list_ditolak = [];
while($r = mysqli_fetch_assoc($q_ditolak)) $list_ditolak[] = $r;

// Tab 5: Selesai
$q_selesai = mysqli_query($connection,
    "SELECT k.nopol, k.jenis_kendaraan, k.bidang, p.status, p.nomor_pengajuan,
            p.tgl_selesai, p.grand_total
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan=k.id_kendaraan
     WHERE $date_where $bidang_where
       AND p.status='Selesai'
     ORDER BY p.tgl_selesai DESC");
$list_selesai = [];
while($r = mysqli_fetch_assoc($q_selesai)) $list_selesai[] = $r;

// ═══════════════════════════════════════════════════════════
// RINGKASAN PER TAHUN
// ═══════════════════════════════════════════════════════════
$tahun_where = $bidang_where;
$ringkasan_q = mysqli_query($connection,
    "SELECT
        YEAR(p.tgl_pengajuan) AS tahun,
        COUNT(*) AS total_permintaan,
        SUM(CASE WHEN p.status='Selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN p.status!='Selesai' THEN 1 ELSE 0 END) AS belum_selesai,
        SUM(CASE WHEN p.status='Selesai' THEN p.total_perbaikan ELSE 0 END) AS biaya_selesai_jasa,
        SUM(CASE WHEN p.status='Selesai' THEN p.total_sparepart ELSE 0 END) AS biaya_selesai_spare,
        SUM(CASE WHEN p.status='Selesai' THEN p.grand_total ELSE 0 END) AS biaya_selesai_total,
        SUM(CASE WHEN p.status!='Selesai' THEN p.total_perbaikan ELSE 0 END) AS biaya_proses_jasa,
        SUM(CASE WHEN p.status!='Selesai' THEN p.total_sparepart ELSE 0 END) AS biaya_proses_spare,
        SUM(CASE WHEN p.status!='Selesai' THEN p.grand_total ELSE 0 END) AS biaya_proses_total
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     WHERE 1=1 $tahun_where
     GROUP BY YEAR(p.tgl_pengajuan)
     ORDER BY tahun DESC");
$ringkasan_list = [];
while ($r = mysqli_fetch_assoc($ringkasan_q)) $ringkasan_list[] = $r;

$ring_total = ['total_permintaan'=>0,'selesai'=>0,'belum_selesai'=>0,
               'biaya_selesai_jasa'=>0,'biaya_selesai_spare'=>0,'biaya_selesai_total'=>0,
               'biaya_proses_jasa'=>0,'biaya_proses_spare'=>0,'biaya_proses_total'=>0];
foreach ($ringkasan_list as $rr) {
    foreach ($ring_total as $k => $v) $ring_total[$k] += $rr[$k];
}

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════
function rupiah($n) {
    if ($n >= 1e9) return 'Rp '.number_format($n/1e9,1,',','.').'M';
    if ($n >= 1e6) return 'Rp '.number_format($n/1e6,1,',','.').'Jt';
    if ($n >= 1e3) return 'Rp '.round($n/1e3).' rb';
    return 'Rp '.number_format($n,0,',','.');
}
function buildUrl($params=[]) {
    $p = array_merge($_GET, $params);
    return '?'.http_build_query(array_filter($p, fn($v)=>$v!==''&&$v!==null&&$v!=='0'&&$v!==0));
}
$nama_bulan_full  = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$nama_bulan_short = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'];

if (!empty($filter_tanggal))    $periode_label = date('d M Y', strtotime($filter_tanggal));
elseif ($filter_bulan > 0)      $periode_label = $nama_bulan_full[$filter_bulan].' '.$filter_tahun;
else                            $periode_label = 'Tahun '.$filter_tahun;

$tahun_ini = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Unit</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root {
    --green-900: #0d3d1f;
    --green-800: #165a2e;
    --green-700: #1e7a40;
    --green-600: #248a3d;
    --green-500: #2ecc71;
    --green-100: #d4f5e2;
    --green-50:  #f0faf5;
    --accent:    #00d48a;
    --blue:      #3b82f6;
    --amber:     #f59e0b;
    --purple:    #8b5cf6;
    --teal:      #16a085;
    --red:       #ef4444;
    --gray-900:  #111827;
    --gray-700:  #374151;
    --gray-500:  #6b7280;
    --gray-300:  #d1d5db;
    --gray-100:  #f3f4f6;
    --white:     #ffffff;
    --bg:        #edf7f2;
    --card-shadow: 0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
    --card-radius: 14px;
}

* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: Arial, sans-serif;
    background: rgb(185, 224, 204);
    color: var(--gray-900);
    overflow-x: hidden;
    font-size: 15px;
}

.main-content { margin-left: 260px; min-height: 100vh; transition: margin-left .3s; }
.main-content.full-width { margin-left: 0; }

/* ─── FILTER BAR ─── */
.filter-bar {
    background: #fff;
    padding: 7px 22px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    border-bottom: 2px solid var(--green-100);
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    position: sticky;
    top: 0;
    z-index: 100;
}
.filter-bar .filter-label {
    font-size: .85em;
    font-weight: 700;
    color: var(--green-700);
    text-transform: uppercase;
    letter-spacing: .06em;
    white-space: nowrap;
    display: flex; align-items: center; gap: 5px;
}
.filter-bar select,
.filter-bar input[type="date"] {
    padding: 5px 10px;
    border: 2px solid var(--gray-300);
    border-radius: 8px;
    font-size: 1.0em;
    font-family: inherit;
    font-weight: 600;
    color: var(--gray-700);
    background: var(--gray-100);
    cursor: pointer;
    transition: border-color .2s, background .2s;
    min-width: 110px;
}
.filter-bar select:focus,
.filter-bar input[type="date"]:focus {
    outline: none;
    border-color: var(--green-600);
    background: #fff;
}
.filter-bar .sep { width: 1px; height: 22px; background: var(--gray-300); flex-shrink: 0; }
.filter-bar .bidang-lock {
    margin-left: auto;
    font-size: .85em;
    font-weight: 700;
    color: var(--green-900);
    background: var(--green-100);
    padding: 5px 12px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.btn-apply {
    padding: 6px 15px;
    background: var(--green-700);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 1.0em;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background .2s, transform .15s;
    font-family: inherit;
}
.btn-apply:hover { background: var(--green-800); transform: translateY(-1px); }
.btn-reset-filter {
    padding: 6px 12px;
    background: var(--gray-100);
    color: var(--gray-500);
    border: 2px solid var(--gray-300);
    border-radius: 8px;
    font-size: 1.0em;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 5px;
    transition: all .2s;
    font-family: inherit;
    text-decoration: none;
}
.btn-reset-filter:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ─── MAIN BODY ─── */
.body-wrap {
    padding: 6px 22px 8px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
}

/* ─── STAT CARDS ROW ─── */
.stats-row {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 10px;
    align-items: stretch;
}
.stat-card {
    background: var(--white);
    border-radius: var(--card-radius);
    padding: 8px 12px 7px;
    box-shadow: var(--card-shadow);
    position: relative;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: left;
    border: none;
    font-family: inherit;
    width: 100%;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 22px rgba(0,0,0,.10); }
.stat-card:focus-visible { outline: 2px solid var(--green-600); outline-offset: 2px; }
.stat-card::after {
    content: '';
    position: absolute;
    bottom: -16px; right: -16px;
    width: 66px; height: 66px;
    border-radius: 50%;
    opacity: .07;
}
.stat-card.c-total          { border-top: 3px solid var(--blue); }
.stat-card.c-proses         { border-top: 3px solid var(--amber); }
.stat-card.c-persetujuan    { border-top: 3px solid var(--purple); }
.stat-card.c-ditolak        { border-top: 3px solid var(--red); }
.stat-card.c-pemeliharaan   { border-top: 3px solid var(--teal); }
.stat-card.c-selesai        { border-top: 3px solid var(--green-500); }
.stat-card.c-total::after        { background: var(--blue); }
.stat-card.c-proses::after       { background: var(--amber); }
.stat-card.c-persetujuan::after  { background: var(--purple); }
.stat-card.c-ditolak::after      { background: var(--red); }
.stat-card.c-pemeliharaan::after { background: var(--teal); }
.stat-card.c-selesai::after      { background: var(--green-500); }
.stat-icon {
    width: 28px; height: 28px;
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85em; margin-bottom: 5px;
}
.c-total        .stat-icon { background: #eff6ff; color: var(--blue); }
.c-proses       .stat-icon { background: #fffbeb; color: var(--amber); }
.c-persetujuan  .stat-icon { background: #f5f3ff; color: var(--purple); }
.c-ditolak      .stat-icon { background: #fef2f2; color: var(--red); }
.c-pemeliharaan .stat-icon { background: #f0fdfa; color: var(--teal); }
.c-selesai      .stat-icon { background: #f0fdf4; color: var(--green-500); }
.stat-num {
    font-size: 1.4em;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 3px;
    letter-spacing: -.03em;
}
.c-total        .stat-num { color: var(--blue); }
.c-proses       .stat-num { color: var(--amber); }
.c-persetujuan  .stat-num { color: var(--purple); }
.c-ditolak      .stat-num { color: var(--red); }
.c-pemeliharaan .stat-num { color: var(--teal); }
.c-selesai      .stat-num { color: var(--green-600); }
.stat-lbl {
    font-size: .92em;
    font-weight: 700;
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.stat-sub { font-size: .76em; color: var(--gray-300); margin-top: 2px; }
.stat-card .stat-click-hint {
    font-size: .72em;
    color: var(--gray-300);
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 3px;
}

/* ─── MIDDLE ROW ─── */
.middle-row {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 10px;
}
.card {
    background: var(--white);
    border-radius: var(--card-radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
}
.card-head {
    padding: 8px 12px;
    border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px;
}
.card-head h3 {
    font-size: 1.0em;
    font-weight: 700;
    color: var(--gray-900);
    display: flex; align-items: center; gap: 6px;
}
.card-head h3 i { color: var(--green-600); }
.card-body { padding: 8px 12px; }

/* Bidang breakdown */
.bidang-list { display: flex; flex-direction: column; gap: 6px; max-height: 180px; overflow-y: auto; }
.bidang-row { display: grid; grid-template-columns: minmax(130px, auto) 1fr 46px; gap: 8px; align-items: center; }
.bidang-name { font-size: .96em; font-weight: 600; color: var(--gray-700); white-space: nowrap; overflow: visible; text-overflow: unset; }
.bidang-bars { display: flex; flex-direction: column; gap: 3px; }
.bar-row { display: flex; align-items: center; gap: 4px; }
.bar-track { flex: 1; height: 6px; background: var(--gray-100); border-radius: 4px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 4px; transition: width .6s ease; }
.bar-fill.fill-proses  { background: linear-gradient(90deg, var(--amber), #fbbf24); }
.bar-fill.fill-selesai { background: linear-gradient(90deg, var(--green-500), #6ee7b7); }
.bar-num { font-size: .78em; color: var(--gray-500); min-width: 18px; text-align: right; }
.bidang-total-badge { background: var(--gray-100); color: var(--gray-700); font-size: .82em; font-weight: 700; padding: 2px 6px; border-radius: 20px; text-align: center; }

/* Analisis Biaya card (dipindah ke middle-row) */
.biaya-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 6px; }
.biaya-box { border-radius: 8px; padding: 7px 10px; }
.biaya-box .v { font-size: 1.0em; font-weight: 800; }
.biaya-box .l { font-size: .82em; color: var(--gray-500); margin-top: 2px; }
.biaya-minmax { display: flex; justify-content: space-between; font-size: .85em; padding-top: 6px; border-top: 1px solid var(--gray-100); color: var(--gray-500); }

/* TOP 10 TABLE */
.top10-table-wrap { overflow-x: auto; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.top10-table { width: 100%; min-width: 680px; border-collapse: collapse; }
.top10-table thead th {
    background: rgb(9,120,83);
    color: #fff;
    font-size: 1.0em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 7px 10px;
    text-align: left;
    border-bottom: 2px solid rgba(255,255,255,.2);
    white-space: nowrap;
}
.top10-table thead th:last-child { text-align: right; }
.top10-table tbody td {
    padding: 6px 10px;
    border-bottom: 1px solid var(--gray-100);
    vertical-align: middle;
    font-size: .96em;
}
.top10-table tbody tr:hover { background: var(--green-50); }
.top10-table tbody tr:last-child td { border-bottom: none; }
.top10-table-wrap::after {
    content: '← geser untuk lihat lebih banyak →';
    display: block;
    text-align: center;
    font-size: 11px;
    color: #9ca3af;
    padding: 4px 0 2px;
    letter-spacing: .04em;
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px; height: 22px;
    border-radius: 50%;
    font-size: .82em;
    font-weight: 800;
}
.rank-1 { background: #fef3c7; color: #92400e; border: 2px solid #f59e0b; }
.rank-2 { background: #f3f4f6; color: #374151; border: 2px solid #9ca3af; }
.rank-3 { background: #fef2f2; color: #991b1b; border: 2px solid #fca5a5; }
.rank-other { background: var(--gray-100); color: var(--gray-500); border: 2px solid var(--gray-300); }

.nopol-badge {
    background: #0d9488;
    color: #fff;
    padding: 3px 8px;
    border-radius: 5px;
    font-weight: 700;
    font-size: 1.0em;
    display: inline-block;
    white-space: nowrap;
}
.bidang-badge {
    background: #dbeafe;
    color: #1d4ed8;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: .94em;
    font-weight: 600;
    display: inline-block;
}
.jumlah-bar-wrap { display: flex; align-items: center; gap: 7px; }
.jumlah-bar-track {
    flex: 1;
    height: 7px;
    background: var(--gray-100);
    border-radius: 4px;
    overflow: hidden;
    min-width: 70px;
}
.jumlah-bar-fill {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, #ef4444, #f97316);
    transition: width .6s ease;
}
.jumlah-num { font-weight: 800; color: var(--red); font-size: 1.0em; min-width: 18px; }
.biaya-num { font-weight: 700; color: var(--green-700); text-align: right; display: block; }

/* RINGKASAN PER TAHUN */
.ringkasan-section,
.card.ringkasan-section {
    width: 100% !important;
    max-width: 100% !important;
    display: block !important;
    box-sizing: border-box !important;
}
.ringkasan-table-wrap { overflow-x: auto; width: 100%; }
.ringkasan-table { width: 100%; border-collapse: collapse; table-layout: auto; }
.ringkasan-table th {
    background: rgb(9,120,83);
    color: #fff;
    font-size: 1.0em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 8px 10px;
    text-align: center;
    white-space: nowrap;
}
.ringkasan-table th.th-left { text-align: left; }
.ringkasan-table .th-group {
    background: rgba(255,255,255,.12);
    font-size: .94em;
    letter-spacing: .08em;
    padding: 5px 10px;
    text-transform: uppercase;
    border-bottom: 1px solid rgba(255,255,255,.2);
}
.ringkasan-table tbody td {
    padding: 7px 10px;
    border-bottom: 1px solid var(--gray-100);
    vertical-align: middle;
    font-size: .94em;
    text-align: center;
}
.ringkasan-table tbody td.td-left { text-align: left; }
.ringkasan-table tbody tr:hover { background: var(--green-50); }
.ringkasan-table tfoot td {
    padding: 7px 10px;
    font-size: .94em;
    text-align: center;
    background: #f0fdf4;
    border-top: 2px solid var(--green-200, #a7f3d0);
    font-weight: 700;
    color: var(--green-800);
}
.ringkasan-table tfoot td.td-left { text-align: left; }

.tahun-chip { display: inline-flex; align-items: center; gap: 5px; font-weight: 800; font-size: 1.0em; color: var(--gray-900); }
.tahun-ini-badge { background: var(--blue); color: #fff; padding: 2px 7px; border-radius: 10px; font-size: .94em; font-weight: 700; }
.num-green  { color: var(--green-700); font-weight: 700; }
.num-amber  { color: var(--amber); font-weight: 700; }
.num-red    { color: var(--red); font-weight: 700; }
.num-purple { color: var(--purple); font-weight: 700; }
.num-blue   { color: var(--blue); font-weight: 700; }

.progress-wrap { display: flex; align-items: center; gap: 7px; justify-content: center; }
.progress-track { width: 70px; height: 9px; background: var(--gray-100); border-radius: 5px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 5px; transition: width .6s ease; }
.progress-lbl { font-size: .85em; font-weight: 800; min-width: 30px; }

.empty-state { text-align: center; padding: 30px; color: var(--gray-500); }
.empty-state i { font-size: 2.5em; opacity: .35; margin-bottom: 10px; display: block; }

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--gray-100); }
::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 3px; }
.bidang-list::-webkit-scrollbar { width: 3px; }

/* ─── BOTTOM ROW ─── */
.bottom-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    align-items: start;
}
.top10-scroll-wrap {
    overflow-x: auto;
    overflow-y: auto;
    max-height: 220px;
    -webkit-overflow-scrolling: touch;
}
.top10-scroll-wrap thead { position: sticky; top: 0; z-index: 5; }

/* ─── ANALISIS PANEL ─── */
.analisis-panel { display: flex; flex-direction: column; gap: 8px; }
.analisis-block { background: var(--white); border-radius: var(--card-radius); box-shadow: var(--card-shadow); overflow: hidden; }
.analisis-block-head {
    padding: 8px 12px;
    border-bottom: 1px solid var(--gray-100);
    font-size: .96em;
    font-weight: 700;
    color: var(--gray-900);
    display: flex; align-items: center; gap: 6px;
}
.analisis-block-body { padding: 10px 12px; display: flex; flex-direction: column; gap: 7px; }
.metric-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.metric-label { font-size: .83em; color: var(--gray-500); font-weight: 500; flex: 1; }
.metric-val { font-size: 1.0em; font-weight: 800; color: var(--gray-900); white-space: nowrap; }
.metric-val.green { color: var(--green-700); }
.metric-val.red   { color: var(--red); }
.metric-val.amber { color: var(--amber); }
.metric-val.blue  { color: var(--blue); }
.metric-val.purple { color: var(--purple); }
.metric-divider { height: 1px; background: var(--gray-100); margin: 2px 0; }

/* Status tabs */
.status-tabs { display: flex; border-bottom: 2px solid var(--gray-100); width: 100%; overflow-x: auto; }
.stab {
    flex: 1;
    padding: 7px 5px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: .92em;
    font-weight: 700;
    color: var(--gray-400);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all .18s;
    font-family: inherit;
    white-space: nowrap;
}
.stab:hover { color: var(--gray-700); }
.stab.active { color: var(--gray-900); border-bottom-color: rgb(9,120,83); }
.stab-count {
    background: #f3f4f6;
    color: var(--gray-600);
    padding: 1px 5px;
    border-radius: 10px;
    font-size: .9em;
    font-weight: 800;
}
.stab-content { display: none; }
.stab-content.active { display: block; }

.status-scroll { overflow-y: auto; max-height: 200px; }
.status-tbl { width: 100%; border-collapse: collapse; }
.status-tbl thead { position: sticky; top: 0; z-index: 3; }
.status-tbl thead th {
    background: #f8f9fa;
    color: var(--gray-500);
    font-size: .94em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 7px 8px;
    border-bottom: 1px solid var(--gray-200, #e5e7eb);
    white-space: nowrap;
    text-align: center;
}
.status-tbl tbody td {
    padding: 5px 8px;
    border-bottom: 1px solid var(--gray-100);
    vertical-align: middle;
    text-align: center;
}
.status-tbl tbody tr:hover { background: var(--green-50); }
.status-tbl tbody tr:last-child td { border-bottom: none; }

/* RESPONSIVE */
@media (max-width: 1280px) {
    .stats-row { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1100px) {
    .bottom-row { grid-template-columns: 1fr; }
    .middle-row { grid-template-columns: 1fr; }
}
@media (max-width: 1024px) {
    .main-content { margin-left: 0; }
    .body-wrap { padding: 10px 14px 16px; }
    .filter-bar { padding: 10px 14px; }
    .stats-row { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 640px) {
    .main-content { margin-left: 0 !important; }
    .body-wrap { padding: 8px 10px 14px; gap: 10px; }
    .filter-bar { padding: 10px 12px; gap: 7px; flex-wrap: wrap; position: sticky; }
    .filter-bar .filter-label { width: 100%; margin-bottom: 2px; font-size: .80em; }
    .filter-bar select, .filter-bar input[type="date"] { flex: 1 1 calc(50% - 4px); min-width: 0; font-size: 13px; padding: 7px 8px; }
    .filter-bar .sep { display: none; }
    .filter-bar .bidang-lock { margin-left: 0; width: 100%; justify-content: center; }
    .btn-apply, .btn-reset-filter { flex: 1 1 calc(50% - 4px); justify-content: center; font-size: 13px; padding: 8px 10px; }
    .stats-row { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .stat-num { font-size: 1.3em; }
    .stat-lbl { font-size: .74em; }
    .stat-card { padding: 8px 10px 6px; }
    .middle-row { grid-template-columns: 1fr; gap: 10px; }
    .biaya-grid { grid-template-columns: 1fr 1fr; }
    .bidang-list { max-height: 180px; }
    .bidang-row { grid-template-columns: minmax(90px, auto) 1fr 38px; }
    .bidang-name { font-size: .82em; }
    .bottom-row { grid-template-columns: 1fr; gap: 10px; }
    .top10-scroll-wrap { overflow-x: auto; overflow-y: auto; max-height: 280px; -webkit-overflow-scrolling: touch; }
    .top10-table { min-width: 560px; }
    .top10-table thead th { font-size: .76em; padding: 7px 8px; }
    .top10-table tbody td { font-size: .82em; padding: 6px 8px; }
    .nopol-badge { font-size: .82em; padding: 2px 6px; }
    .bidang-badge { font-size: .78em; }
    .stab { font-size: .72em; padding: 8px 4px; gap: 3px; }
    .stab i { display: none; }
    .status-scroll { max-height: 220px; }
    .status-tbl thead th { font-size: .78em; padding: 7px 7px; }
    .status-tbl tbody td { font-size: .82em; padding: 5px 7px; }
    .ringkasan-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .ringkasan-table { min-width: 700px; }
    .ringkasan-table th { font-size: .74em; padding: 7px 8px; }
    .ringkasan-table tbody td { font-size: .80em; padding: 6px 8px; }
    .ringkasan-table tfoot td { font-size: .80em; padding: 6px 8px; }
    .card-head h3 { font-size: .90em; }
    .progress-track { width: 55px; }
    .progress-lbl { font-size: .78em; }
    .ringkasan-table-wrap::after { content: '← geser →'; display: block; text-align: center; font-size: 11px; color: #9ca3af; padding: 4px 0 2px; letter-spacing: .04em; }
    .card-head { padding: 8px 10px; flex-wrap: wrap; gap: 4px; }
    .card-body { padding: 8px 10px; }
    .stat-sub, .stat-click-hint { display: none; }
}
</style>
</head>
<body>

<?php include "navbar_pengawas.php"; ?>

<div class="main-content" id="mainContent">

<!-- ═══ FILTER BAR (bidang DIHAPUS, otomatis terkunci ke bidang user) ═══ -->
<form method="GET" class="filter-bar" id="filterForm">
    <span class="filter-label"><i class="fas fa-sliders-h"></i> Filter</span>

    <select name="filter_tahun" id="ft_tahun">
        <?php foreach($tahun_list as $thn): ?>
        <option value="<?=$thn?>" <?=$thn==$filter_tahun?'selected':''?>><?=$thn?></option>
        <?php endforeach; ?>
    </select>

    <select name="filter_bulan" id="ft_bulan">
        <option value="0">Semua Bulan</option>
        <?php for($m=1;$m<=12;$m++): ?>
        <option value="<?=$m?>" <?=$filter_bulan==$m?'selected':''?>><?=$nama_bulan_full[$m]?></option>
        <?php endfor; ?>
    </select>

    <input type="date" name="filter_tanggal" id="ft_tanggal"
        value="<?=htmlspecialchars($filter_tanggal)?>"
        title="Filter per tanggal (prioritas lebih tinggi dari bulan)">

    <div class="sep"></div>

    <button type="submit" class="btn-apply">
        <i class="fas fa-search"></i> Terapkan
    </button>

    <a href="dashboard_pengawas.php" class="btn-reset-filter">
        <i class="fas fa-undo"></i> Reset
    </a>

    <?php if ($bidang_user !== ''): ?>
    <span class="bidang-lock"><i class="fas fa-building"></i> Bidang: <?=htmlspecialchars($bidang_user)?></span>
    <?php else: ?>
    <span class="bidang-lock" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-triangle-exclamation"></i> Bidang belum diset</span>
    <?php endif; ?>
</form>

<!-- ═══ BODY ═══ -->
<div class="body-wrap">

    <?php if ($bidang_user === ''): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:12px;padding:12px 16px;font-size:.9em;">
        <i class="fas fa-circle-exclamation"></i>
        Akun Anda belum memiliki bidang yang terdaftar, sehingga data dashboard tidak dapat ditampilkan. Silakan hubungi administrator.
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <!-- Proses + Perlu Persetujuan Unit + Tindak Lanjut Pemeliharaan + Ditolak Unit + Selesai = Total -->
    <!-- Setiap kartu bisa diklik untuk membuka tab yang sesuai di panel "Status Kendaraan" di bawah -->
    <div class="stats-row">
        <button type="button" class="stat-card c-total" onclick="goToTab('tab-proses')">
            <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
            <div class="stat-num"><?=number_format($total)?></div>
            <div class="stat-lbl">Total Keseluruhan</div>
            <div class="stat-sub">Semua pengajuan bidang ini</div>
        </button>
        <button type="button" class="stat-card c-proses" onclick="goToTab('tab-proses')">
            <div class="stat-icon"><i class="fas fa-spinner"></i></div>
            <div class="stat-num"><?=number_format($proses)?></div>
            <div class="stat-lbl">Proses</div>
            <div class="stat-sub">Masih di SA atau QC</div>
        </button>
        <button type="button" class="stat-card c-persetujuan" onclick="goToTab('tab-persetujuan')">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-num"><?=number_format($perlu_persetujuan_unit)?></div>
            <div class="stat-lbl">Perlu Persetujuan Unit</div>
            <div class="stat-sub">Menunggu keputusan Anda</div>
        </button>
        <button type="button" class="stat-card c-ditolak" onclick="goToTab('tab-ditolak')">
            <div class="stat-icon"><i class="fas fa-ban"></i></div>
            <div class="stat-num"><?=number_format($ditolak_unit)?></div>
            <div class="stat-lbl">Ditolak Unit</div>
            <div class="stat-sub">Menunggu keputusan SA</div>
        </button>
        <button type="button" class="stat-card c-pemeliharaan" onclick="goToTab('tab-pemeliharaan')">
            <div class="stat-icon"><i class="fas fa-screwdriver-wrench"></i></div>
            <div class="stat-num"><?=number_format($tindak_lanjut_pemeliharaan)?></div>
            <div class="stat-lbl">Dikerjakan di Bengkel</div>
            <div class="stat-sub">Telah disetujui Anda</div>
        </button>
        <button type="button" class="stat-card c-selesai" onclick="goToTab('tab-selesai')">
            <div class="stat-icon"><i class="fas fa-check-double"></i></div>
            <div class="stat-num"><?=number_format($selesai)?></div>
            <div class="stat-lbl">Selesai</div>
            <div class="stat-sub">Telah diselesaikan</div>
        </button>
    </div>

    <!-- MIDDLE ROW: bidang breakdown + analisis biaya -->
    <div class="middle-row">

        <!-- Bidang breakdown bar -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-building"></i> Breakdown Bidang</h3>
                <span style="font-size:.92em;color:var(--gray-500);"><?=count($bidang_stats)?> bidang</span>
            </div>
            <div class="card-body">
                <?php if (empty($bidang_stats)): ?>
                <div class="empty-state" style="padding:16px;">
                    <i class="fas fa-inbox"></i>
                    <p style="font-size:.90em;">Tidak ada data</p>
                </div>
                <?php else: ?>
                <div style="display:grid;grid-template-columns:minmax(130px,auto) 1fr 46px;gap:8px;padding-bottom:6px;border-bottom:1px solid var(--gray-100);margin-bottom:8px;">
                    <div style="font-size:.96em;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.06em;">Bidang</div>
                    <div style="display:flex;gap:14px;font-size:.96em;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:.06em;">
                        <span style="color:var(--amber);">● Proses</span>
                        <span style="color:var(--green-500);">● Selesai</span>
                    </div>
                    <div style="font-size:.96em;font-weight:700;color:var(--gray-500);text-transform:uppercase;text-align:center;">Total</div>
                </div>
                <?php $max_b = max(array_column($bidang_stats, 'total')); $max_b = $max_b ?: 1; ?>
                <div class="bidang-list">
                <?php foreach ($bidang_stats as $bs):
                    $pct_p = round(($bs['proses']/$max_b)*100);
                    $pct_s = round(($bs['selesai']/$max_b)*100);
                ?>
                <div class="bidang-row">
                    <div class="bidang-name" title="<?=htmlspecialchars($bs['bidang'])?>"><?=htmlspecialchars($bs['bidang'])?></div>
                    <div class="bidang-bars">
                        <div class="bar-row">
                            <div class="bar-track"><div class="bar-fill fill-proses" style="width:<?=$pct_p?>%"></div></div>
                            <span class="bar-num" style="color:var(--amber);"><?=$bs['proses']?></span>
                        </div>
                        <div class="bar-row">
                            <div class="bar-track"><div class="bar-fill fill-selesai" style="width:<?=$pct_s?>%"></div></div>
                            <span class="bar-num" style="color:var(--green-600);"><?=$bs['selesai']?></span>
                        </div>
                    </div>
                    <div class="bidang-total-badge"><?=$bs['total']?></div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Analisis Biaya -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-coins" style="color:var(--purple);"></i> Analisis Biaya</h3>
                <span style="font-size:.90em;color:var(--gray-500);"><?=htmlspecialchars($periode_label)?></span>
            </div>
            <div class="card-body">
                <div class="biaya-grid">
                    <div class="biaya-box" style="background:#f5f3ff;">
                        <div class="v" style="color:var(--purple);"><?=rupiah($total_biaya)?></div>
                        <div class="l">Total Semua</div>
                    </div>
                    <div class="biaya-box" style="background:#f0fdf4;">
                        <div class="v" style="color:var(--green-700);"><?=rupiah($biaya_selesai_nominal)?></div>
                        <div class="l">Sudah Selesai</div>
                    </div>
                    <div class="biaya-box" style="background:#fffbeb;">
                        <div class="v" style="color:var(--amber);"><?=rupiah($biaya_proses)?></div>
                        <div class="l">Dalam Proses</div>
                    </div>
                    <div class="biaya-box" style="background:#eff6ff;">
                        <div class="v" style="color:var(--blue);"><?=$avg_biaya>0?rupiah($avg_biaya):'-'?></div>
                        <div class="l">Rata-rata</div>
                    </div>
                </div>
                <div class="biaya-minmax">
                    <span><i class="fas fa-arrow-up" style="color:var(--red);"></i> Tertinggi: <?=$max_biaya>0?rupiah($max_biaya):'-'?></span>
                    <span><i class="fas fa-arrow-down" style="color:var(--green-600);"></i> Terendah: <?=$min_biaya>0?rupiah($min_biaya):'-'?></span>
                </div>
            </div>
        </div>

    </div><!-- /middle-row -->

    <!-- ═══ BOTTOM ROW: TOP10 + STATUS KENDARAAN ═══ -->
    <div class="bottom-row">

        <!-- TOP 10 KENDARAAN -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-truck" style="color:var(--red);"></i> Top 10 Kendaraan Terbanyak Perbaikan</h3>
                <span style="font-size:.90em;color:var(--gray-500);"><?=htmlspecialchars($periode_label)?></span>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="top10-scroll-wrap">
                    <table class="top10-table">
                        <thead>
                            <tr>
                                <th style="width:40px;text-align:center;">#</th>
                                <th style="width:100px;">Nomor Asset</th>
                                <th style="width:160px;">Jenis Kendaraan</th>
                                <th style="width:160px;">Bidang</th>
                                <th style="width:110px;">Pengajuan</th>
                                <th style="width:110px;text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($top10_list)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state" style="padding:16px;">
                                    <i class="fas fa-inbox"></i>
                                    <p>Tidak ada data</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($top10_list as $idx => $t):
                            $rank  = $idx + 1;
                            $rclass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
                            $pct   = $max_top10 > 0 ? round(($t['jumlah_pengajuan']/$max_top10)*100) : 0;
                        ?>
 <tr style="text-align:center;">
                                <td style="text-align:center;">
                                <span class="rank-badge <?=$rclass?>"><?=$rank?></span>
                            </td>
                            <td><span class="nopol-badge"><?=htmlspecialchars($t['nopol'])?></span></td>
                            <td style="font-weight:600;color:var(--gray-700);"><?=htmlspecialchars($t['jenis_kendaraan'])?></td>
                            <td>
                                <?php if(!empty($t['bidang'])): ?>
                                <span class="bidang-badge"><?=htmlspecialchars($t['bidang'])?></span>
                                <?php else: ?><span style="color:var(--gray-300);">—</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="jumlah-bar-wrap">
                                    <span class="jumlah-num"><?=(int)$t['jumlah_pengajuan']?></span>
                                    <span style="font-size:.92em;color:var(--gray-400);">pengajuan</span>
                                </div>
                            </td>
                            <td style="text-align:right;"><span class="biaya-num"><?=$t['total_biaya']>0?rupiah($t['total_biaya']):'—'?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PANEL KANAN: STATUS KENDARAAN -->
        <div class="card" style="overflow:hidden;" id="statusPanel">
            <div class="analisis-block" style="flex:1;">
                <div class="analisis-block-head" style="padding:0;border-bottom:none;">
                    <div class="status-tabs">
                        <!-- Tab count disamakan 1:1 dengan kartu status di atas -->
                        <button class="stab active" id="stab-tab-proses" onclick="activateTab('tab-proses')" style="color:var(--amber);">
                            <i class="fas fa-spinner"></i> Proses
                            <span class="stab-count"><?=count($list_proses)?></span>
                        </button>
                        <button class="stab" id="stab-tab-persetujuan" onclick="activateTab('tab-persetujuan')" style="color:var(--purple);">
                            <i class="fas fa-user-check"></i> Persetujuan
                            <span class="stab-count" style="background:#f5f3ff;color:#7c3aed;"><?=count($list_persetujuan)?></span>
                        </button>
                        <button class="stab" id="stab-tab-ditolak" onclick="activateTab('tab-ditolak')" style="color:var(--red);">
                            <i class="fas fa-ban"></i> Ditolak
                            <span class="stab-count" style="background:#fee2e2;color:#dc2626;"><?=count($list_ditolak)?></span>
                        </button>
                        <button class="stab" id="stab-tab-pemeliharaan" onclick="activateTab('tab-pemeliharaan')" style="color:var(--teal);">
                            <i class="fas fa-screwdriver-wrench"></i> Bengkel
                            <span class="stab-count" style="background:#f0fdfa;color:#0f766e;"><?=count($list_pemeliharaan)?></span>
                        </button>
                        <button class="stab" id="stab-tab-selesai" onclick="activateTab('tab-selesai')">
                            <i class="fas fa-check-double"></i> Selesai
                            <span class="stab-count" style="background:#dcfce7;color:#15803d;"><?=count($list_selesai)?></span>
                        </button>
                    </div>
                </div>

                <!-- TAB: PROSES -->
                <div id="tab-proses" class="stab-content active">
                    <div class="status-scroll">
                        <table class="status-tbl">
                            <thead>
                                <tr>
                                    <th>Nomor Asset</th>
                                    <th>Jenis</th>
                                    <th>Tgl</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($list_proses)): ?>
                            <tr><td colspan="3" style="padding:14px;color:var(--gray-400);font-size:.96em;">Tidak ada data</td></tr>
                            <?php else: foreach($list_proses as $lp): ?>
                            <tr>
                                <td><span class="nopol-badge" style="font-size:.83em;padding:2px 6px;"><?=htmlspecialchars($lp['nopol'])?></span></td>
                                <td style="font-size:.95em;color:var(--gray-700);font-weight:600;"><?=htmlspecialchars($lp['jenis_kendaraan'])?></td>
                                <td style="font-size:.90em;color:var(--gray-400);white-space:nowrap;"><?=!empty($lp['tgl_pengajuan'])?date('d/m/y',strtotime($lp['tgl_pengajuan'])):'-'?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: PERLU PERSETUJUAN UNIT -->
                <div id="tab-persetujuan" class="stab-content">
                    <div class="status-scroll">
                        <table class="status-tbl">
                            <thead>
                                <tr>
                                    <th>Nomor Asset</th>
                                    <th>Jenis</th>
                                    <th>Tgl</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($list_persetujuan)): ?>
                            <tr><td colspan="3" style="padding:14px;color:var(--gray-400);font-size:.96em;">Tidak ada data</td></tr>
                            <?php else: foreach($list_persetujuan as $lm): ?>
                            <tr>
                                <td><span class="nopol-badge" style="font-size:.83em;padding:2px 6px;"><?=htmlspecialchars($lm['nopol'])?></span></td>
                                <td style="font-size:.95em;color:var(--gray-700);font-weight:600;"><?=htmlspecialchars($lm['jenis_kendaraan'])?></td>
                                <td style="font-size:.90em;color:var(--gray-400);white-space:nowrap;"><?=!empty($lm['tgl_pengajuan'])?date('d/m/y',strtotime($lm['tgl_pengajuan'])):'-'?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: DITOLAK UNIT -->
                <div id="tab-ditolak" class="stab-content">
                    <div class="status-scroll">
                        <table class="status-tbl">
                            <thead>
                                <tr>
                                    <th>Nomor Asset</th>
                                    <th>Jenis</th>
                                    <th>Tgl</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($list_ditolak)): ?>
                            <tr><td colspan="3" style="padding:14px;color:var(--gray-400);font-size:.96em;">Tidak ada data</td></tr>
                            <?php else: foreach($list_ditolak as $ld): ?>
                            <tr>
                                <td><span class="nopol-badge" style="font-size:.83em;padding:2px 6px;"><?=htmlspecialchars($ld['nopol'])?></span></td>
                                <td style="font-size:.95em;color:var(--gray-700);font-weight:600;"><?=htmlspecialchars($ld['jenis_kendaraan'])?></td>
                                <td style="font-size:.90em;color:var(--gray-400);white-space:nowrap;"><?=!empty($ld['tgl_pengajuan'])?date('d/m/y',strtotime($ld['tgl_pengajuan'])):'-'?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: TINDAK LANJUT PEMELIHARAAN (dikerjakan bengkel) -->
                <div id="tab-pemeliharaan" class="stab-content">
                    <div class="status-scroll">
                        <table class="status-tbl">
                            <thead>
                                <tr>
                                    <th>Nomor Asset</th>
                                    <th>Jenis</th>
                                    <th>Tgl</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($list_pemeliharaan)): ?>
                            <tr><td colspan="3" style="padding:14px;color:var(--gray-400);font-size:.96em;">Tidak ada data</td></tr>
                            <?php else: foreach($list_pemeliharaan as $lpm): ?>
                            <tr>
                                <td><span class="nopol-badge" style="font-size:.83em;padding:2px 6px;"><?=htmlspecialchars($lpm['nopol'])?></span></td>
                                <td style="font-size:.95em;color:var(--gray-700);font-weight:600;"><?=htmlspecialchars($lpm['jenis_kendaraan'])?></td>
                                <td style="font-size:.90em;color:var(--gray-400);white-space:nowrap;"><?=!empty($lpm['tgl_pengajuan'])?date('d/m/y',strtotime($lpm['tgl_pengajuan'])):'-'?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: SELESAI -->
                <div id="tab-selesai" class="stab-content">
                    <div class="status-scroll">
                        <table class="status-tbl">
                            <thead>
                                <tr>
                                    <th>Nomor Asset</th>
                                    <th>Jenis</th>
                                    <th>Tgl Selesai</th>
                                    <th>Biaya</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($list_selesai)): ?>
                            <tr><td colspan="4" style="padding:14px;color:var(--gray-400);font-size:.96em;">Tidak ada data</td></tr>
                            <?php else: foreach($list_selesai as $ls): ?>
                            <tr>
                                <td><span class="nopol-badge" style="font-size:.83em;padding:2px 6px;"><?=htmlspecialchars($ls['nopol'])?></span></td>
                                <td style="font-size:.95em;color:var(--gray-700);font-weight:600;"><?=htmlspecialchars($ls['jenis_kendaraan'])?></td>
                                <td style="font-size:.90em;color:var(--gray-400);white-space:nowrap;"><?=!empty($ls['tgl_selesai'])?date('d/m/y',strtotime($ls['tgl_selesai'])):'-'?></td>
                                <td style="font-size:.95em;font-weight:700;color:var(--green-700);"><?=$ls['grand_total']>0?rupiah($ls['grand_total']):'—'?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

    </div><!-- /bottom-row -->

    <!-- ═══ RINGKASAN PERMINTAAN PER TAHUN — FULL WIDTH ═══ -->
    <div class="card ringkasan-section">
        <div class="card-head" style="flex-direction:column;align-items:flex-start;gap:6px;">
            <div style="display:flex;align-items:center;gap:8px;width:100%;">
                <h3><i class="fas fa-calendar-check"></i> Ringkasan Permintaan Per Tahun</h3>
                <span style="font-size:.90em;color:var(--gray-500);font-weight:400;margin-left:auto;">(Termasuk belum selesai tahun lalu)</span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <div style="background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:20px;font-size:.92em;font-weight:700;display:flex;align-items:center;gap:4px;">
                    <i class="fas fa-check-circle"></i> Selesai: <strong><?=(int)$ring_total['selesai']?></strong>
                </div>
                <div style="background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:20px;font-size:.92em;font-weight:700;display:flex;align-items:center;gap:4px;">
                    <i class="fas fa-clock"></i> Masih Proses: <strong><?=(int)$ring_total['belum_selesai']?></strong>
                </div>
                <div style="background:#eff6ff;color:#1d4ed8;padding:3px 10px;border-radius:20px;font-size:.92em;font-weight:700;display:flex;align-items:center;gap:4px;">
                    <i class="fas fa-list"></i> Total: <strong><?=(int)$ring_total['total_permintaan']?></strong>
                </div>
                <?php
                $overall_pct = $ring_total['total_permintaan']>0 ? round(($ring_total['selesai']/$ring_total['total_permintaan'])*100) : 0;
                $chip_c  = $overall_pct>=70?'#15803d':($overall_pct>=40?'#92400e':'#991b1b');
                $chip_bg = $overall_pct>=70?'#dcfce7':($overall_pct>=40?'#fef3c7':'#fee2e2');
                ?>
                <div style="background:<?=$chip_bg?>;color:<?=$chip_c?>;padding:3px 10px;border-radius:20px;font-size:.92em;font-weight:700;display:flex;align-items:center;gap:4px;">
                    <i class="fas fa-chart-pie"></i> Progress: <strong><?=$overall_pct?>%</strong>
                </div>
            </div>
        </div>
        <div class="card-body" style="padding:0 0 4px;">
            <div class="ringkasan-table-wrap">
                <table class="ringkasan-table">
                    <thead>
                        <tr>
                            <th class="th-left" rowspan="2">Tahun</th>
                            <th rowspan="2">Total</th>
                            <th rowspan="2" style="background:#166534;color:#fff;">✓ Selesai</th>
                            <th rowspan="2" style="background:#991b1b;color:#fff;">⏳ Proses</th>
                            <th colspan="3" class="th-group" style="background:rgba(22,101,52,.85);">💰 Biaya Sudah Selesai</th>
                            <th colspan="3" class="th-group" style="background:rgba(154,52,18,.85);">⚙️ Biaya Masih Proses</th>
                            <th rowspan="2">Progress</th>
                        </tr>
                        <tr>
                            <th>Jasa</th>
                            <th>Sparepart</th>
                            <th>Total</th>
                            <th style="background:rgba(154,52,18,.6);">Jasa</th>
                            <th style="background:rgba(154,52,18,.6);">Sparepart</th>
                            <th style="background:rgba(154,52,18,.6);">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($ringkasan_list)): ?>
                    <tr>
                        <td colspan="11">
                            <div class="empty-state" style="padding:24px;">
                                <i class="fas fa-inbox"></i><p>Tidak ada data</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($ringkasan_list as $rk):
                        $pct_done   = $rk['total_permintaan'] > 0 ? round(($rk['selesai']/$rk['total_permintaan'])*100) : 0;
                        $is_now     = ((int)$rk['tahun'] === $tahun_ini);
                        $prog_color = $pct_done >= 70 ? '#22c55e' : ($pct_done >= 40 ? '#f59e0b' : '#ef4444');
                    ?>
                    <tr>
                        <td class="td-left">
                            <div class="tahun-chip">
                                <?=(int)$rk['tahun']?>
                                <?php if($is_now): ?><span class="tahun-ini-badge">Tahun Ini</span><?php endif; ?>
                            </div>
                        </td>
                        <td><strong><?=(int)$rk['total_permintaan']?></strong></td>
                        <td>
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#dcfce7;color:#15803d;font-weight:800;font-size:.90em;">
                                <?=(int)$rk['selesai']?>
                            </span>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#fee2e2;color:#dc2626;font-weight:800;font-size:.90em;">
                                <?=(int)$rk['belum_selesai']?>
                            </span>
                        </td>
                        <td class="num-blue"><?=rupiah($rk['biaya_selesai_jasa'])?></td>
                        <td class="num-blue"><?=rupiah($rk['biaya_selesai_spare'])?></td>
                        <td class="num-green"><?=rupiah($rk['biaya_selesai_total'])?></td>
                        <td class="num-amber"><?=rupiah($rk['biaya_proses_jasa'])?></td>
                        <td class="num-amber"><?=rupiah($rk['biaya_proses_spare'])?></td>
                        <td class="num-red"><?=rupiah($rk['biaya_proses_total'])?></td>
                        <td>
                            <div class="progress-wrap">
                                <div class="progress-track">
                                    <div class="progress-fill" style="width:<?=$pct_done?>%;background:<?=$prog_color?>;"></div>
                                </div>
                                <span class="progress-lbl" style="color:<?=$prog_color?>;"><?=$pct_done?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($ringkasan_list)): ?>
                    <tfoot>
                        <?php
                        $tot_pct  = $ring_total['total_permintaan'] > 0 ? round(($ring_total['selesai']/$ring_total['total_permintaan'])*100) : 0;
                        $prog_c2  = $tot_pct>=70?'#22c55e':($tot_pct>=40?'#f59e0b':'#ef4444');
                        ?>
                        <tr>
                            <td class="td-left" style="font-weight:800;color:var(--green-800);">Total Keseluruhan</td>
                            <td><strong><?=(int)$ring_total['total_permintaan']?></strong></td>
                            <td><strong style="color:#15803d;"><?=(int)$ring_total['selesai']?></strong></td>
                            <td><strong style="color:#dc2626;"><?=(int)$ring_total['belum_selesai']?></strong></td>
                            <td style="color:var(--blue);font-weight:700;"><?=rupiah($ring_total['biaya_selesai_jasa'])?></td>
                            <td style="color:var(--blue);font-weight:700;"><?=rupiah($ring_total['biaya_selesai_spare'])?></td>
                            <td style="color:var(--green-700);font-weight:800;"><?=rupiah($ring_total['biaya_selesai_total'])?></td>
                            <td style="color:var(--amber);font-weight:700;"><?=rupiah($ring_total['biaya_proses_jasa'])?></td>
                            <td style="color:var(--amber);font-weight:700;"><?=rupiah($ring_total['biaya_proses_spare'])?></td>
                            <td style="color:var(--red);font-weight:800;"><?=rupiah($ring_total['biaya_proses_total'])?></td>
                            <td>
                                <div class="progress-wrap">
                                    <div class="progress-track">
                                        <div class="progress-fill" style="width:<?=$tot_pct?>%;background:<?=$prog_c2?>;"></div>
                                    </div>
                                    <span class="progress-lbl" style="color:<?=$prog_c2?>;"><?=$tot_pct?>%</span>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div><!-- /ringkasan -->

</div><!-- /body-wrap -->
</div><!-- /main-content -->

<script>
// ═══ STATUS TABS ═══
function activateTab(tabId) {
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.stab-content').forEach(c => c.classList.remove('active'));
    const btn = document.getElementById('stab-' + tabId);
    if (btn) btn.classList.add('active');
    const content = document.getElementById(tabId);
    if (content) content.classList.add('active');
}

// Dipanggil saat kartu status di atas diklik: aktifkan tab terkait
// lalu scroll halaman ke panel "Status Kendaraan" supaya langsung terlihat.
function goToTab(tabId) {
    activateTab(tabId);
    const panel = document.getElementById('statusPanel');
    if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ═══ Filter tanggal & bulan sync ═══
const ftTanggal = document.getElementById('ft_tanggal');
const ftBulan   = document.getElementById('ft_bulan');
if(ftTanggal && ftBulan){
    ftTanggal.addEventListener('change',()=>{ if(ftTanggal.value) ftBulan.value='0'; });
    ftBulan.addEventListener('change',()=>{ if(ftBulan.value!='0') ftTanggal.value=''; });
}
</script>
</body>
</html>
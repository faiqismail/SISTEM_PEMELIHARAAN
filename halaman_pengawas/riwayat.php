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
// PAGINATION
// ═══════════════════════════════════════════════════════════
$per_page   = 25; // baris per halaman — ringan di browser
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset     = ($page - 1) * $per_page;

// ═══════════════════════════════════════════════════════════
// DROPDOWN FILTER — ambil sekali, cache di session ringan
// ═══════════════════════════════════════════════════════════
$bidang_list_q = mysqli_query($connection,
    "SELECT DISTINCT k.bidang
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     WHERE p.id_pengaju='$id_user' AND p.status='Selesai'
       AND k.bidang IS NOT NULL AND k.bidang != ''
     ORDER BY k.bidang ASC");
$bidang_list = [];
while ($r = mysqli_fetch_assoc($bidang_list_q)) $bidang_list[] = $r['bidang'];

$jenis_list_q = mysqli_query($connection,
    "SELECT DISTINCT k.jenis_kendaraan
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     WHERE p.id_pengaju='$id_user' AND p.status='Selesai'
       AND k.jenis_kendaraan IS NOT NULL AND k.jenis_kendaraan != ''
     ORDER BY k.jenis_kendaraan ASC");
$jenis_list = [];
while ($r = mysqli_fetch_assoc($jenis_list_q)) $jenis_list[] = $r['jenis_kendaraan'];

// ═══════════════════════════════════════════════════════════
// FILTER TABEL
// ═══════════════════════════════════════════════════════════
$search_nopol   = isset($_GET['search_nopol'])   ? trim($_GET['search_nopol'])   : '';
$search_tanggal = isset($_GET['search_tanggal']) ? trim($_GET['search_tanggal']) : '';
$search_nomor   = isset($_GET['search_nomor'])   ? trim($_GET['search_nomor'])   : '';
$search_bidang  = isset($_GET['search_bidang'])  ? trim($_GET['search_bidang'])  : '';
$search_jenis   = isset($_GET['search_jenis'])   ? trim($_GET['search_jenis'])   : '';

// Bangun WHERE — pakai prepared-style escape
$where = "WHERE p.id_pengaju = '$id_user' AND p.status = 'Selesai'";
if ($search_nopol   !== '') $where .= " AND k.nopol LIKE '%".mysqli_real_escape_string($connection,$search_nopol)."%'";
if ($search_tanggal !== '') $where .= " AND DATE(p.tgl_selesai) = '".mysqli_real_escape_string($connection,$search_tanggal)."'";
if ($search_nomor   !== '') $where .= " AND p.nomor_pengajuan LIKE '%".mysqli_real_escape_string($connection,$search_nomor)."%'";
if ($search_bidang  !== '') $where .= " AND k.bidang = '".mysqli_real_escape_string($connection,$search_bidang)."'";
if ($search_jenis   !== '') $where .= " AND k.jenis_kendaraan = '".mysqli_real_escape_string($connection,$search_jenis)."'";

$ada_filter = $search_nopol||$search_tanggal||$search_nomor||$search_bidang||$search_jenis;

// ═══════════════════════════════════════════════════════════
// HITUNG TOTAL ROWS — untuk pagination (query ringan, no SELECT *)
// ═══════════════════════════════════════════════════════════
$count_q = mysqli_query($connection,
    "SELECT COUNT(*) AS total
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     $where");
$total_rows  = (int)(mysqli_fetch_assoc($count_q)['total'] ?? 0);
$total_pages = max(1, ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;

// ═══════════════════════════════════════════════════════════
// FILTER TAHUN UNTUK 4 CARD
// ═══════════════════════════════════════════════════════════
$tahun_sekarang = (int)date('Y');
$filter_tahun   = isset($_GET['filter_tahun']) ? (int)$_GET['filter_tahun'] : $tahun_sekarang;

$tahun_list_q = mysqli_query($connection,
    "SELECT DISTINCT YEAR(tgl_pengajuan) AS thn
     FROM permintaan_perbaikan
     WHERE id_pengaju='$id_user' AND status='Selesai'
     ORDER BY thn DESC");
$tahun_list = [];
while ($r = mysqli_fetch_assoc($tahun_list_q)) $tahun_list[] = (int)$r['thn'];
if (empty($tahun_list)) $tahun_list = [$tahun_sekarang];

$bulan_ini       = (int)date('n');
$bulan_lalu      = $bulan_ini == 1 ? 12 : $bulan_ini - 1;
$tahun_lalu      = $filter_tahun - 1;

// ═══════════════════════════════════════════════════════════
// 4 CARD QUERIES — semua dibatasi YEAR(), sangat cepat
// ═══════════════════════════════════════════════════════════
// Satu query GROUP BY bulan sekaligus — lebih efisien dari 4 query terpisah
$card_q = mysqli_fetch_assoc(mysqli_query($connection,
    "SELECT
        SUM(CASE WHEN YEAR(tgl_pengajuan)='$filter_tahun' THEN 1 ELSE 0 END)                            AS total_thn,
        SUM(CASE WHEN YEAR(tgl_pengajuan)='$tahun_lalu'   THEN 1 ELSE 0 END)                            AS total_thn_lalu,
        SUM(CASE WHEN YEAR(tgl_pengajuan)='$filter_tahun' AND MONTH(tgl_pengajuan)='$bulan_ini'  THEN 1 ELSE 0 END) AS total_bln_ini,
        SUM(CASE WHEN YEAR(tgl_pengajuan)='$filter_tahun' AND MONTH(tgl_pengajuan)='$bulan_lalu' THEN 1 ELSE 0 END) AS total_bln_lalu,
        SUM(CASE WHEN YEAR(tgl_pengajuan)='$filter_tahun' THEN grand_total ELSE 0 END)                  AS total_biaya,
        AVG(CASE WHEN YEAR(tgl_pengajuan)='$filter_tahun' THEN grand_total ELSE NULL END)                AS avg_biaya,
        SUM(CASE WHEN YEAR(tgl_pengajuan)='$filter_tahun' THEN 1 ELSE 0 END)                            AS jumlah_unit
     FROM permintaan_perbaikan
     WHERE id_pengaju='$id_user' AND status='Selesai'
       AND YEAR(tgl_pengajuan) IN ('$filter_tahun','$tahun_lalu')"));

$total_tahun      = (int)($card_q['total_thn']      ?? 0);
$total_tahun_lalu = (int)($card_q['total_thn_lalu'] ?? 0);
$bln_ini_total    = (int)($card_q['total_bln_ini']  ?? 0);
$bln_lalu_total   = (int)($card_q['total_bln_lalu'] ?? 0);
$total_biaya      = $card_q['total_biaya'] ?? 0;
$avg_biaya        = $card_q['avg_biaya']   ?? 0;

$delta_thn     = $total_tahun - $total_tahun_lalu;
$delta_thn_pct = $total_tahun_lalu > 0 ? round(abs($delta_thn/$total_tahun_lalu)*100) : ($total_tahun > 0 ? 100 : 0);
$delta_bln     = $bln_ini_total - $bln_lalu_total;
$delta_bln_pct = $bln_lalu_total > 0 ? round(abs($delta_bln/$bln_lalu_total)*100) : ($bln_ini_total > 0 ? 100 : 0);
$rata_per_bulan = $bulan_ini > 0 ? round($total_tahun / $bulan_ini, 1) : 0;
$rata_lalu      = $total_tahun_lalu > 0 ? round($total_tahun_lalu / 12, 1) : 0;

// ═══════════════════════════════════════════════════════════
// QUERY TABEL — LIMIT + OFFSET (hanya ambil 25 baris)
// ═══════════════════════════════════════════════════════════
$data_q = mysqli_query($connection,
    "SELECT p.id_permintaan, p.nomor_pengajuan, p.keluhan_awal,
            p.tgl_pengajuan, p.tgl_diperiksa_sa, p.tgl_disetujui_karu_qc, p.tgl_selesai,
            p.total_perbaikan, p.total_sparepart, p.grand_total,
            k.nopol, k.jenis_kendaraan, k.bidang,
            r.nama_rekanan
     FROM permintaan_perbaikan p
     JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
     LEFT JOIN rekanan r ON p.id_rekanan = r.id_rekanan
     $where
     ORDER BY p.tgl_selesai DESC, p.id_permintaan DESC
     LIMIT $per_page OFFSET $offset");

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════
function rupiah($n) {
    if ($n >= 1_000_000_000) return 'Rp '.number_format($n/1_000_000_000,1,',','.').' M';
    if ($n >= 1_000_000)     return 'Rp '.number_format($n/1_000_000,1,',','.').' jt';
    if ($n >= 1_000)         return 'Rp '.round($n/1_000).' rb';
    return 'Rp '.number_format($n,0,',','.');
}
function formatDurasi($a, $b) {
    if (empty($a)||empty($b)) return '-';
    $iv = (new DateTime($a))->diff(new DateTime($b));
    if ($iv->d > 0) return $iv->d.'h '.($iv->h>0?$iv->h.'j':'');
    if ($iv->h > 0) return $iv->h.'j '.($iv->i>0?$iv->i.'m':'');
    if ($iv->i > 0) return $iv->i.' mnt';
    return $iv->s.' dtk';
}
function tglFmt($t) { return $t && $t!='0000-00-00 00:00:00' ? date('d/m/Y H:i',strtotime($t)) : '-'; }

// URL helper — jaga semua parameter saat ganti page
function buildUrl($params = []) {
    $p = array_merge($_GET, $params);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}

$nama_bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Perbaikan</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: rgb(185,224,204); overflow-x: hidden; }

        /* Sidebar */
        .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: linear-gradient(180deg,#2c3e50,#34495e); color: white; z-index: 1000; transition: transform .3s; overflow-y: auto; }
        .sidebar.hidden { transform: translateX(-100%); }
        .sidebar-header { padding: 20px; background: rgba(0,0,0,.2); border-bottom: 1px solid rgba(255,255,255,.1); }
        .sidebar-header .user-info { display: flex; align-items: center; gap: 12px; }
        .sidebar-header .avatar { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg,#667eea,#764ba2); display: flex; align-items: center; justify-content: center; font-size: 1.5em; font-weight: bold; }
        .sidebar-header .user-name { font-size: 1.1em; font-weight: 600; margin-bottom: 3px; }
        .sidebar-header .user-role { font-size: .85em; opacity: .8; }
        .nav-menu { padding: 20px 0; }
        .nav-item { padding: 15px 20px; display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,.8); text-decoration: none; transition: all .3s; cursor: pointer; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,.1); color: white; border-left: 4px solid #3498db; }
        .nav-item i { width: 20px; text-align: center; }
        .mobile-toggle { position: fixed; top: 15px; left: 15px; z-index: 1001; background: #2c3e50; color: white; border: none; padding: 12px 15px; border-radius: 8px; cursor: pointer; display: none; }

        /* Layout */
        .main-content { margin-left: 260px; min-height: 100vh; transition: margin-left .3s; }
        .header { color: white; background: linear-gradient(180deg,#248a3d,#0d3d1f); padding: 16px 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .header h1 { font-size: 1.8em; }

        /* Filter Tahun Bar */
        .filter-bar { padding: 14px 30px 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-bar label { font-size: .82em; font-weight: 700; color: #2c3e50; white-space: nowrap; }
        .filter-bar select { padding: 7px 32px 7px 12px; border: 2px solid #d5e8d4; border-radius: 8px; font-size: .88em; font-weight: 600; color: #2c3e50; background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2395a5a6'/%3E%3C/svg%3E") no-repeat right 10px center; appearance: none; cursor: pointer; }
        .filter-bar select:focus { outline: none; border-color: #27ae60; }
        .filter-bar .filter-hint { font-size: .75em; color: #95a5a6; }

        /* Stat Cards */
        .stats-container { padding: 14px 30px 16px; display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; }
        .sc { background: #fff; border-radius: 14px; padding: 20px 20px 16px; box-shadow: 0 2px 12px rgba(0,0,0,.07); transition: transform .2s, box-shadow .2s; position: relative; overflow: hidden; }
        .sc:hover { transform: translateY(-3px); box-shadow: 0 6px 22px rgba(0,0,0,.12); }
        .sc::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:14px 14px 0 0; }
        .sc-1::before{background:#1abc9c;} .sc-2::before{background:#3498db;} .sc-3::before{background:#9b59b6;} .sc-4::before{background:#e67e22;}
        .sc-icon { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1em; margin-bottom:12px; }
        .sc-1 .sc-icon{background:#eafaf6;color:#1abc9c;} .sc-2 .sc-icon{background:#eaf4fc;color:#3498db;} .sc-3 .sc-icon{background:#f5eef8;color:#9b59b6;} .sc-4 .sc-icon{background:#fef5e7;color:#e67e22;}
        .sc-label { font-size:.7em; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#95a5a6; margin-bottom:3px; }
        .sc-value { font-size:2.5em; font-weight:800; line-height:1; margin-bottom:12px; }
        .sc-1 .sc-value{color:#1abc9c;} .sc-2 .sc-value{color:#3498db;} .sc-3 .sc-value{color:#9b59b6;} .sc-4 .sc-value{color:#e67e22;}
        .sc-divider { border:none; border-top:1px solid #f2f2f2; margin-bottom:10px; }
        .sc-compare { display:flex; align-items:center; justify-content:space-between; font-size:.78em; }
        .sc-compare-left { color:#7f8c8d; }
        .sc-compare-left span { display:block; font-size:.88em; margin-top:1px; color:#bdc3c7; }
        .delta { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:.78em; font-weight:700; }
        .delta-up{background:#eafaf6;color:#1abc9c;} .delta-down{background:#fdedec;color:#e74c3c;} .delta-flat{background:#f0f3f4;color:#95a5a6;}
        .sc-bars { margin-top:10px; display:flex; flex-direction:column; gap:5px; }
        .sc-bar-row { display:flex; align-items:center; gap:8px; font-size:.74em; }
        .sc-bar-row .bar-name { width:26px; color:#7f8c8d; white-space:nowrap; }
        .sc-bar-row .bar-track { flex:1; background:#f0f3f4; border-radius:4px; height:6px; overflow:hidden; }
        .sc-bar-row .bar-fill  { height:100%; border-radius:4px; }
        .sc-bar-row .bar-num   { width:20px; text-align:right; font-weight:700; color:#2c3e50; }

        /* Table section */
        .content-section { padding: 0 30px 30px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.08); margin-bottom: 30px; overflow: hidden; }
        .card-header { padding: 18px 25px; border-bottom: 1px solid #ecf0f1; background: #fafbfc; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
        .card-header h3 { color: #2c3e50; font-size: 1.2em; display: flex; align-items: center; gap: 10px; margin:0; }
        .card-body { padding: 20px 25px; }

        /* info row di bawah judul */
        .table-info { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .info-badge { background:#eaf4fc; color:#2980b9; border:1px solid #aed6f1; padding:3px 10px; border-radius:20px; font-size:.78em; font-weight:600; }

        /* Search grid */
        .search-box { display: grid; grid-template-columns: repeat(5,1fr) auto; gap: 10px; align-items: end; margin-bottom: 12px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { margin-bottom: 5px; font-weight: 600; color: #2c3e50; font-size: .82em; display:flex; align-items:center; gap:4px; }
        .form-control { padding: 9px 11px; border: 2px solid #ecf0f1; border-radius: 8px; font-size: .85em; transition: border-color .2s; background: white; width: 100%; }
        .form-control:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,.1); }
        select.form-control { appearance: none; background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2395a5a6'/%3E%3C/svg%3E") no-repeat right 10px center; padding-right: 26px; cursor: pointer; }
        .tgl-note { font-size:.65em; color:#27ae60; font-weight:600; margin-top:2px; }

        /* Filter tags */
        .filter-active-row { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; align-items:center; }
        .filter-tag { display:inline-flex; align-items:center; gap:4px; background:#eaf4fc; color:#2980b9; border:1px solid #aed6f1; padding:3px 10px; border-radius:20px; font-size:.76em; font-weight:600; }
        .filter-tag .rm { cursor:pointer; color:#7fb3d3; }
        .filter-tag .rm:hover { color:#e74c3c; }
        .btn-reset { padding: 9px 14px; background: #ecf0f1; color: #7f8c8d; border: 2px solid #dfe6e9; border-radius: 8px; font-size: .82em; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all .2s; white-space: nowrap; }
        .btn-reset:hover { background: #e74c3c; color: white; border-color: #e74c3c; }

        /* Table */
        .table-wrapper { overflow-x: auto; overflow-y: auto; max-height: 560px; }
        .table { width: 100%; border-collapse: collapse; min-width: 1350px; }
        .table thead { position: sticky; top: 0; z-index: 10; }
        .table th { background: rgb(9,120,83); color: white; padding: 13px 10px; text-align: left; font-weight: 600; white-space: nowrap; font-size: .88em; }
        .table td { padding: 11px 10px; border-bottom: 1px solid #ecf0f1; vertical-align: middle; font-size: .88em; }
        .table tbody tr:hover { background: #f8f9fa; }
        .nomor-badge   { background: linear-gradient(135deg,#667eea,#764ba2); color: white; padding: 3px 9px; border-radius: 12px; font-weight: bold; font-size: .82em; white-space: nowrap; display: inline-block; }
        .nopol-badge   { background: #16a085; color: white; padding: 4px 9px; border-radius: 5px; font-weight: 600; font-size: .82em; display: inline-block; }
        .bidang-badge  { background: #8e44ad; color: white; padding: 3px 9px; border-radius: 12px; font-size: .78em; display: inline-block; }
        .keluhan-badge { background: #3498db; color: white; padding: 3px 9px; border-radius: 12px; font-size: .78em; display: inline-block; }
        .durasi-badge  { background: #f39c12; color: white; padding: 4px 9px; border-radius: 15px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; font-size: .82em; white-space: nowrap; }
        .timeline-step { display: flex; align-items: flex-start; gap: 7px; margin-bottom: 6px; font-size: .82em; }
        .timeline-step:last-child { margin-bottom: 0; }
        .timeline-step i { color: #27ae60; margin-top: 2px; font-size: .88em; }
        .timeline-step .step-content { flex: 1; }
        .timeline-step strong { display: block; margin-bottom: 1px; color: #2c3e50; font-size: .95em; }
        .timeline-step small { color: #7f8c8d; }
        .biaya-info > div { margin-bottom: 3px; font-size: .82em; }
        .biaya-info strong { color: #2c3e50; }
        .biaya-total { border-top: 1px solid #ddd; margin-top: 4px; padding-top: 4px; }
        .biaya-total .amount { color: #27ae60; font-weight: bold; }
        .btn { padding: 7px 12px; border: none; border-radius: 7px; cursor: pointer; font-size: .82em; font-weight: 600; transition: all .25s; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-info { background: #3498db; color: white; }
        .btn-info:hover { background: #2980b9; transform: translateY(-1px); }

        /* ── PAGINATION ── */
        .pagination-wrap { display:flex; justify-content:space-between; align-items:center; padding: 14px 0 0; flex-wrap:wrap; gap:10px; }
        .pagination-info { font-size:.82em; color:#7f8c8d; }
        .pagination { display:flex; gap:4px; flex-wrap:wrap; }
        .pg-btn { padding: 6px 11px; border: 2px solid #ecf0f1; border-radius: 7px; background: white; color: #2c3e50; font-size: .82em; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .2s; display:inline-flex; align-items:center; }
        .pg-btn:hover { border-color: #3498db; color: #3498db; }
        .pg-btn.active { background: rgb(9,120,83); border-color: rgb(9,120,83); color: white; cursor: default; }
        .pg-btn.disabled { opacity: .4; cursor: not-allowed; pointer-events: none; }
        .pg-ellipsis { padding: 6px 8px; color: #95a5a6; font-size: .82em; display:inline-flex; align-items:center; }

        .empty-state { text-align: center; padding: 50px 20px; color: #95a5a6; }
        .empty-state i { font-size: 3.5em; margin-bottom: 16px; opacity: .5; }
        .loading { display: none; text-align: center; padding: 20px; color: #7f8c8d; }

        @media (max-width: 1100px) {
            .stats-container { grid-template-columns: repeat(2,1fr); }
            .search-box { grid-template-columns: repeat(3,1fr); }
        }
        @media (max-width: 768px) {
            .mobile-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); box-shadow: 2px 0 10px rgba(0,0,0,.3); }
            .main-content { margin-left: 0; }
            .header { padding: 60px 15px 15px; }
            .header h1 { font-size: 1.3em; }
            .filter-bar { padding: 12px 15px 0; }
            .stats-container { padding: 12px 15px; grid-template-columns: repeat(2,1fr); }
            .content-section { padding: 0 15px 30px; }
            .card-body { padding: 15px; }
            .search-box { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 480px) {
            .stats-container { grid-template-columns: 1fr; }
            .search-box { grid-template-columns: 1fr; }
        }
        .table-wrapper::-webkit-scrollbar, .sidebar::-webkit-scrollbar { width: 7px; height: 7px; }
        .table-wrapper::-webkit-scrollbar-track, .sidebar::-webkit-scrollbar-track { background: #f1f1f1; }
        .table-wrapper::-webkit-scrollbar-thumb, .sidebar::-webkit-scrollbar-thumb { background: #bdc3c7; border-radius: 4px; }
    </style>
</head>
<body>
<?php include "navbar_pengawas.php"; ?>

<div class="main-content" id="mainContent">
    <div class="header">
        <h1><i class="fas fa-history"></i> Riwayat Perbaikan</h1>
        <div style="display:flex;gap:15px;align-items:center;"><span><i class="fas fa-calendar"></i> <?= date('d F Y') ?></span></div>
    </div>

    <!-- Filter Tahun Card -->
    <div class="filter-bar">
        <label><i class="fas fa-filter" style="color:#27ae60;"></i> Ringkasan tahun:</label>
        <select onchange="gantiTahun(this.value)">
            <?php foreach ($tahun_list as $thn): ?>
            <option value="<?= $thn ?>" <?= $thn==$filter_tahun?'selected':'' ?>><?= $thn ?></option>
            <?php endforeach; ?>
        </select>
        <span class="filter-hint">* hanya mempengaruhi 4 kartu di atas</span>
    </div>

    <!-- 4 Stat Cards -->
    <div class="stats-container">
        <!-- Card 1 -->
        <div class="sc sc-1">
            <div class="sc-icon"><i class="fas fa-check-double"></i></div>
            <div class="sc-label">Total Selesai <?= $filter_tahun ?></div>
            <div class="sc-value"><?= number_format($total_tahun) ?></div>
            <hr class="sc-divider">
            <div class="sc-compare">
                <div class="sc-compare-left"><?= $tahun_lalu ?>: <strong><?= $total_tahun_lalu ?></strong><span>Tahun lalu</span></div>
                <?php if($delta_thn>0):?><span class="delta delta-up"><i class="fas fa-arrow-up"></i> <?=$delta_thn_pct?>%</span>
                <?php elseif($delta_thn<0):?><span class="delta delta-down"><i class="fas fa-arrow-down"></i> <?=$delta_thn_pct?>%</span>
                <?php else:?><span class="delta delta-flat"><i class="fas fa-minus"></i> Sama</span><?php endif;?>
            </div>
            <?php $mxT=max($total_tahun,$total_tahun_lalu,1);?>
            <div class="sc-bars">
                <div class="sc-bar-row"><div class="bar-name"><?=$filter_tahun?></div><div class="bar-track"><div class="bar-fill" style="width:<?=round(($total_tahun/$mxT)*100)?>%;background:#1abc9c;"></div></div><div class="bar-num"><?=$total_tahun?></div></div>
                <div class="sc-bar-row"><div class="bar-name"><?=$tahun_lalu?></div><div class="bar-track"><div class="bar-fill" style="width:<?=round(($total_tahun_lalu/$mxT)*100)?>%;background:#a9dfcf;"></div></div><div class="bar-num"><?=$total_tahun_lalu?></div></div>
            </div>
        </div>
        <!-- Card 2 -->
        <div class="sc sc-2">
            <div class="sc-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="sc-label">Selesai <?=$nama_bulan[$bulan_ini]?> <?=$filter_tahun?></div>
            <div class="sc-value"><?=$bln_ini_total?></div>
            <hr class="sc-divider">
            <div class="sc-compare">
                <div class="sc-compare-left"><?=$nama_bulan[$bulan_lalu]?>: <strong><?=$bln_lalu_total?></strong><span>Bulan sebelumnya</span></div>
                <?php if($delta_bln>0):?><span class="delta delta-up"><i class="fas fa-arrow-up"></i> <?=$delta_bln_pct?>%</span>
                <?php elseif($delta_bln<0):?><span class="delta delta-down"><i class="fas fa-arrow-down"></i> <?=$delta_bln_pct?>%</span>
                <?php else:?><span class="delta delta-flat"><i class="fas fa-minus"></i> Sama</span><?php endif;?>
            </div>
            <?php $mxB=max($bln_ini_total,$bln_lalu_total,1);?>
            <div class="sc-bars">
                <div class="sc-bar-row"><div class="bar-name"><?=$nama_bulan[$bulan_ini]?></div><div class="bar-track"><div class="bar-fill" style="width:<?=round(($bln_ini_total/$mxB)*100)?>%;background:#3498db;"></div></div><div class="bar-num"><?=$bln_ini_total?></div></div>
                <div class="sc-bar-row"><div class="bar-name"><?=$nama_bulan[$bulan_lalu]?></div><div class="bar-track"><div class="bar-fill" style="width:<?=round(($bln_lalu_total/$mxB)*100)?>%;background:#aed6f1;"></div></div><div class="bar-num"><?=$bln_lalu_total?></div></div>
            </div>
        </div>
        <!-- Card 3 -->
        <div class="sc sc-3">
            <div class="sc-icon"><i class="fas fa-chart-line"></i></div>
            <div class="sc-label">Rata-rata / Bulan (<?=$filter_tahun?>)</div>
            <div class="sc-value"><?=$rata_per_bulan?></div>
            <hr class="sc-divider">
            <div class="sc-compare">
                <div class="sc-compare-left">Total: <strong><?=$total_tahun?> unit</strong><span>÷ <?=$bulan_ini?> bulan berjalan</span></div>
                <?php $dr=$rata_per_bulan-$rata_lalu;?>
                <?php if($dr>0):?><span class="delta delta-up"><i class="fas fa-arrow-up"></i> lebih</span>
                <?php elseif($dr<0):?><span class="delta delta-down"><i class="fas fa-arrow-down"></i> kurang</span>
                <?php else:?><span class="delta delta-flat"><i class="fas fa-minus"></i> Sama</span><?php endif;?>
            </div>
            <?php $mxR=max($rata_per_bulan,$rata_lalu,1);?>
            <div class="sc-bars">
                <div class="sc-bar-row"><div class="bar-name"><?=$filter_tahun?></div><div class="bar-track"><div class="bar-fill" style="width:<?=round(($rata_per_bulan/$mxR)*100)?>%;background:#9b59b6;"></div></div><div class="bar-num"><?=$rata_per_bulan?></div></div>
                <div class="sc-bar-row"><div class="bar-name"><?=$tahun_lalu?></div><div class="bar-track"><div class="bar-fill" style="width:<?=round(($rata_lalu/$mxR)*100)?>%;background:#d2b4de;"></div></div><div class="bar-num"><?=$rata_lalu?></div></div>
            </div>
        </div>
        <!-- Card 4 -->
        <div class="sc sc-4">
            <div class="sc-icon"><i class="fas fa-coins"></i></div>
            <div class="sc-label">Total Biaya <?=$filter_tahun?></div>
            <div class="sc-value" style="font-size:<?=strlen(rupiah($total_biaya))>12?'1.4em':'1.8em'?>;"><?=rupiah($total_biaya)?></div>
            <hr class="sc-divider">
            <div class="sc-compare">
                <div class="sc-compare-left">Rata-rata / unit<span><?=rupiah($avg_biaya)?> per pengajuan</span></div>
                <span class="delta delta-flat"><?=$card_q['jumlah_unit']??0?> unit</span>
            </div>
            <div style="margin-top:10px;">
                <div style="font-size:.68em;color:#bdc3c7;margin-bottom:3px;">Total biaya <?=$filter_tahun?></div>
                <div style="background:#fdebd0;border-radius:5px;height:6px;overflow:hidden;"><div style="height:100%;border-radius:5px;background:linear-gradient(90deg,#e67e22,#f0b27a);width:100%;"></div></div>
                <div style="font-size:.68em;color:#95a5a6;margin-top:2px;text-align:right;">Rp <?=number_format($total_biaya,0,',','.')?></div>
            </div>
        </div>
    </div>

    <!-- TABEL -->
    <div class="content-section">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list-check"></i> Daftar Riwayat Perbaikan</h3>
                <div class="table-info">
                    <span class="info-badge"><i class="fas fa-database"></i> <?= number_format($total_rows) ?> total data</span>
                    <span class="info-badge"><i class="fas fa-file-lines"></i> Hal. <?=$page?> / <?=$total_pages?></span>
                    <?php if($ada_filter):?><span class="info-badge" style="background:#fef9e7;color:#b7950b;border-color:#f9e79f;"><i class="fas fa-filter"></i> Filter aktif</span><?php endif;?>
                </div>
            </div>
            <div class="card-body">

                <!-- Filter -->
                <div class="search-box">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> No. Pengajuan</label>
                        <input type="text" id="s_nomor" class="form-control" placeholder="Cari nomor..." value="<?=htmlspecialchars($search_nomor)?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-car"></i> Nomor Asset</label>
                        <input type="text" id="s_nopol" class="form-control" placeholder="Cari Nomor Asset..." value="<?=htmlspecialchars($search_nopol)?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar-check" style="color:#27ae60;"></i> Tanggal Selesai</label>
                        <input type="date" id="s_tanggal" class="form-control" value="<?=htmlspecialchars($search_tanggal)?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Bidang</label>
                        <select id="s_bidang" class="form-control">
                            <option value="">-- Semua Bidang --</option>
                            <?php foreach($bidang_list as $b):?>
                            <option value="<?=htmlspecialchars($b)?>" <?=$search_bidang==$b?'selected':''?>><?=htmlspecialchars($b)?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-truck"></i> Jenis Kendaraan</label>
                        <select id="s_jenis" class="form-control">
                            <option value="">-- Semua Jenis --</option>
                            <?php foreach($jenis_list as $j):?>
                            <option value="<?=htmlspecialchars($j)?>" <?=$search_jenis==$j?'selected':''?>><?=htmlspecialchars($j)?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <button class="btn-reset" onclick="resetFilter()">
                        <i class="fas fa-rotate-left"></i> Reset
                    </button>
                </div>

                <!-- Badge filter aktif -->
                <?php if($ada_filter):?>
                <div class="filter-active-row">
                    <span style="font-size:.76em;color:#7f8c8d;font-weight:600;">Filter aktif:</span>
                    <?php if($search_nomor):?><span class="filter-tag">No: <strong><?=htmlspecialchars($search_nomor)?></strong> <span class="rm" onclick="hapusFilter('search_nomor')">✕</span></span><?php endif;?>
                    <?php if($search_nopol):?><span class="filter-tag">Asset: <strong><?=htmlspecialchars($search_nopol)?></strong> <span class="rm" onclick="hapusFilter('search_nopol')">✕</span></span><?php endif;?>
                    <?php if($search_tanggal):?><span class="filter-tag"><i class="fas fa-calendar-check" style="color:#27ae60;"></i> <?=htmlspecialchars($search_tanggal)?> <span class="rm" onclick="hapusFilter('search_tanggal')">✕</span></span><?php endif;?>
                    <?php if($search_bidang):?><span class="filter-tag">Bidang: <strong><?=htmlspecialchars($search_bidang)?></strong> <span class="rm" onclick="hapusFilter('search_bidang')">✕</span></span><?php endif;?>
                    <?php if($search_jenis):?><span class="filter-tag">Jenis: <strong><?=htmlspecialchars($search_jenis)?></strong> <span class="rm" onclick="hapusFilter('search_jenis')">✕</span></span><?php endif;?>
                </div>
                <?php endif;?>

                <div class="loading" id="loading"><i class="fas fa-spinner fa-spin"></i> Memuat...</div>

                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:40px;">No</th>
                                <th>No. Pengajuan</th>
                                <th>Nomor Asset</th>
                                <th>Jenis Kendaraan</th>
                                <th>Bidang</th>
                                <th>Keluhan</th>
                                <th style="width:190px;">Timeline Proses</th>
                                <th>Waktu Pengerjaan</th>
                                <th>Rekanan</th>
                                <th>Rincian Biaya</th>
                                <th style="width:80px;">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $no_start = $offset + 1;
                        $no = $no_start;
                        if ($data_q && mysqli_num_rows($data_q) > 0):
                            while ($d = mysqli_fetch_assoc($data_q)):
                                $durasi = formatDurasi($d['tgl_disetujui_karu_qc'], $d['tgl_selesai']);
                        ?>
                        <tr>
                            <td style="color:#95a5a6;font-size:.8em;"><?=$no++?></td>
                            <td><span class="nomor-badge"><?=htmlspecialchars($d['nomor_pengajuan'])?></span></td>
                            <td><span class="nopol-badge"><?=htmlspecialchars($d['nopol'])?></span></td>
                            <td><?=htmlspecialchars($d['jenis_kendaraan'])?></td>
                            <td><span class="bidang-badge"><?=htmlspecialchars($d['bidang'])?></span></td>
                            <td><span class="keluhan-badge"><?=htmlspecialchars($d['keluhan_awal'])?></span></td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:5px;">
                                    <div class="timeline-step"><i class="fas fa-check-circle"></i><div class="step-content"><strong>Diajukan</strong><small><?=tglFmt($d['tgl_pengajuan'])?></small></div></div>
                                    <div class="timeline-step"><i class="fas fa-check-circle"></i><div class="step-content"><strong>Diperiksa SA</strong><small><?=tglFmt($d['tgl_diperiksa_sa'])?></small></div></div>
                                    <div class="timeline-step"><i class="fas fa-check-circle"></i><div class="step-content"><strong>Disetujui KARU QC</strong><small><?=tglFmt($d['tgl_disetujui_karu_qc'])?></small></div></div>
                                    <div class="timeline-step"><i class="fas fa-check-circle"></i><div class="step-content"><strong style="color:#27ae60;">Selesai</strong><small><?=tglFmt($d['tgl_selesai'])?></small></div></div>
                                </div>
                            </td>
                            <td><span class="durasi-badge"><i class="fas fa-clock"></i> <?=$durasi?></span></td>
                            <td><?=!empty($d['nama_rekanan'])?htmlspecialchars($d['nama_rekanan']):'-'?></td>
                            <td>
                                <div class="biaya-info">
                                    <div><strong>Jasa:</strong> Rp <?=number_format($d['total_perbaikan'],0,',','.')?></div>
                                    <div><strong>Suku cadang:</strong> Rp <?=number_format($d['total_sparepart'],0,',','.')?></div>
                                    <div class="biaya-total"><strong>Total:</strong> <span class="amount">Rp <?=number_format($d['grand_total'],0,',','.')?></span></div>
                                </div>
                            </td>
                            <td><a href="detail_riwayat.php?id=<?=$d['id_permintaan']?>" class="btn btn-info"><i class="fas fa-eye"></i></a></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <h3>Tidak ada data</h3>
                                    <p><?=$ada_filter?'Tidak ada data yang sesuai dengan filter.':'Belum ada perbaikan yang selesai.'?></p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION -->
                <?php if($total_pages > 1): ?>
                <div class="pagination-wrap">
                    <div class="pagination-info">
                        Menampilkan <?= number_format($offset+1) ?>–<?= number_format(min($offset+$per_page,$total_rows)) ?>
                        dari <?= number_format($total_rows) ?> data
                    </div>
                    <div class="pagination">
                        <!-- Prev -->
                        <a href="<?=buildUrl(['page'=>$page-1])?>" class="pg-btn <?=$page<=1?'disabled':''?>"><i class="fas fa-chevron-left"></i></a>

                        <?php
                        // Tampilkan maks 7 tombol halaman
                        $range = 2;
                        $start = max(1, $page - $range);
                        $end   = min($total_pages, $page + $range);

                        if ($start > 1) {
                            echo '<a href="'.buildUrl(['page'=>1]).'" class="pg-btn">1</a>';
                            if ($start > 2) echo '<span class="pg-ellipsis">…</span>';
                        }
                        for ($i = $start; $i <= $end; $i++) {
                            $cls = $i == $page ? 'active' : '';
                            echo "<a href=\"".buildUrl(['page'=>$i])."\" class=\"pg-btn $cls\">$i</a>";
                        }
                        if ($end < $total_pages) {
                            if ($end < $total_pages - 1) echo '<span class="pg-ellipsis">…</span>';
                            echo '<a href="'.buildUrl(['page'=>$total_pages]).'" class="pg-btn">'.$total_pages.'</a>';
                        }
                        ?>

                        <!-- Next -->
                        <a href="<?=buildUrl(['page'=>$page+1])?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>"><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script>
function gantiTahun(thn) {
    const p = new URLSearchParams(window.location.search);
    p.set('filter_tahun', thn);
    p.delete('page'); // reset ke hal 1
    window.location.href = '?' + p.toString();
}
function hapusFilter(key) {
    const p = new URLSearchParams(window.location.search);
    p.delete(key);
    p.delete('page');
    window.location.href = '?' + p.toString();
}
function resetFilter() {
    const p = new URLSearchParams(window.location.search);
    ['search_nomor','search_nopol','search_tanggal','search_bidang','search_jenis'].forEach(k=>p.delete(k));
    p.delete('page');
    window.location.href = '?' + p.toString();
}

// Auto search — reset ke halaman 1 setiap kali filter berubah
let st;
function doSearch() {
    clearTimeout(st);
    st = setTimeout(() => {
        const p = new URLSearchParams(window.location.search);
        const fields = {
            search_nomor:   document.getElementById('s_nomor').value,
            search_nopol:   document.getElementById('s_nopol').value,
            search_tanggal: document.getElementById('s_tanggal').value,
            search_bidang:  document.getElementById('s_bidang').value,
            search_jenis:   document.getElementById('s_jenis').value,
        };
        for (const [k,v] of Object.entries(fields)) v ? p.set(k,v) : p.delete(k);
        p.delete('page'); // kembali ke hal 1
        document.getElementById('loading').style.display = 'block';
        window.location.href = '?' + p.toString();
    }, 700);
}
['s_nomor','s_nopol'].forEach(id=>document.getElementById(id).addEventListener('input',doSearch));
['s_tanggal','s_bidang','s_jenis'].forEach(id=>document.getElementById(id).addEventListener('change',doSearch));

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('active'); }
document.addEventListener('click', function(e) {
    const s=document.getElementById('sidebar'), t=document.querySelector('.mobile-toggle');
    if (window.innerWidth<=768&&s&&t&&!s.contains(e.target)&&!t.contains(e.target)) s.classList.remove('active');
});
window.addEventListener('load',()=>{ document.getElementById('loading').style.display='none'; });
</script>
</body>
</html>
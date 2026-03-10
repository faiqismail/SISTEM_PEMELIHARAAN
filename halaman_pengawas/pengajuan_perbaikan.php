<?php
include "../inc/config.php";
requireAuth('pengawas');

date_default_timezone_set('Asia/Jakarta');

$id_user = $_SESSION['id_user'];
$role    = $_SESSION['role'];

/* =======================
   GET USER INFO
======================= */
$user_info = mysqli_fetch_assoc(
    mysqli_query($connection, "SELECT username, role FROM users WHERE id_user='$id_user'")
);
$nama_user = $user_info['username'];
$role_user = $user_info['role'];

/* =======================
   SIMPAN PENGAJUAN
======================= */
if (isset($_POST['ajukan'])) {
    $id_kendaraan = mysqli_real_escape_string($connection, $_POST['id_kendaraan']);
    $keluhan      = strtoupper(mysqli_real_escape_string($connection, $_POST['keluhan_awal']));
    $tgl          = date('Y-m-d H:i:s');
    $tahun        = date('Y');

    $query_last = mysqli_query($connection, "
        SELECT nomor_pengajuan 
        FROM permintaan_perbaikan 
        WHERE nomor_pengajuan LIKE '$tahun-%' 
        ORDER BY nomor_pengajuan DESC 
        LIMIT 1
    ");

    if (mysqli_num_rows($query_last) > 0) {
        $last_row    = mysqli_fetch_assoc($query_last);
        $last_number = $last_row['nomor_pengajuan'];
        $last_urut   = intval(substr($last_number, 5));
        $new_urut    = $last_urut + 1;
    } else {
        $new_urut = 1;
    }

    $nomor_pengajuan = $tahun . '-' . str_pad($new_urut, 2, '0', STR_PAD_LEFT);

    $u = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT ttd FROM users WHERE id_user='$id_user'")
    );
    $ttd_pengawas = $u['ttd'];

    mysqli_query($connection, "
        INSERT INTO permintaan_perbaikan (
            nomor_pengajuan, id_kendaraan, id_pengaju, keluhan_awal,
            status, tgl_pengajuan, ttd_pengawas, created_at
        ) VALUES (
            '$nomor_pengajuan', '$id_kendaraan', '$id_user', '$keluhan',
            'Diajukan', '$tgl', '$ttd_pengawas', '$tgl'
        )
    ");

    header('Location: ' . url_with_tab('pengajuan_perbaikan.php'));
    exit;
}

/* =======================
   UPDATE PENGAJUAN
======================= */
if (isset($_POST['update'])) {
    $id_permintaan = mysqli_real_escape_string($connection, $_POST['id_permintaan']);
    $id_kendaraan  = mysqli_real_escape_string($connection, $_POST['id_kendaraan']);
    $keluhan       = strtoupper(mysqli_real_escape_string($connection, $_POST['keluhan_awal']));

    mysqli_query($connection, "
        UPDATE permintaan_perbaikan SET
            id_kendaraan = '$id_kendaraan',
            keluhan_awal = '$keluhan'
        WHERE id_permintaan = '$id_permintaan' AND id_pengaju = '$id_user'
    ");

    header('Location: ' . url_with_tab('pengajuan_perbaikan.php'));
    exit;
}

/* =======================
   DELETE PENGAJUAN
======================= */
if (isset($_GET['delete'])) {
    $id_permintaan = mysqli_real_escape_string($connection, $_GET['delete']);
    mysqli_query($connection, "
        DELETE FROM permintaan_perbaikan 
        WHERE id_permintaan = '$id_permintaan' AND id_pengaju = '$id_user'
    ");
    header('Location: ' . url_with_tab('pengajuan_perbaikan.php'));
    exit;
}

/* =======================
   FILTER DASHBOARD (Bulan & Tahun)
======================= */
$dash_bulan = isset($_GET['dash_bulan']) ? (int)$_GET['dash_bulan'] : 0;
$dash_tahun = isset($_GET['dash_tahun']) ? (int)$_GET['dash_tahun'] : 0;

// Bangun WHERE untuk dashboard stats
$dash_where = "WHERE id_pengaju = '$id_user'";
if ($dash_tahun > 0) {
    $dash_where .= " AND YEAR(tgl_pengajuan) = '$dash_tahun'";
}
if ($dash_bulan > 0) {
    $dash_where .= " AND MONTH(tgl_pengajuan) = '$dash_bulan'";
}

/* =======================
   GET STATISTIK (dengan filter dashboard)
======================= */
$stats_query = mysqli_query($connection, "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status NOT IN ('Selesai') THEN 1 ELSE 0 END) AS proses,
        SUM(CASE WHEN status IN ('Dikembalikan_sa','Minta_Persetujuan_Pengawas') AND persetujuan_pengawas = 'Menunggu' THEN 1 ELSE 0 END) AS perlu_persetujuan,
        SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) AS selesai
    FROM permintaan_perbaikan
    $dash_where
");
$stats = mysqli_fetch_assoc($stats_query);

/* =======================
   AMBIL DAFTAR TAHUN TERSEDIA
======================= */
$tahun_query = mysqli_query($connection, "
    SELECT DISTINCT YEAR(tgl_pengajuan) AS thn 
    FROM permintaan_perbaikan 
    WHERE id_pengaju = '$id_user' 
    ORDER BY thn DESC
");
$list_tahun = [];
while ($r = mysqli_fetch_assoc($tahun_query)) {
    $list_tahun[] = $r['thn'];
}

/* =======================
   FILTER TABEL DATA
======================= */
$filter_status  = isset($_GET['filter_status'])  ? mysqli_real_escape_string($connection, $_GET['filter_status'])  : '';
$filter_bidang  = isset($_GET['filter_bidang'])  ? mysqli_real_escape_string($connection, $_GET['filter_bidang'])  : '';
$filter_jenis   = isset($_GET['filter_jenis'])   ? mysqli_real_escape_string($connection, $_GET['filter_jenis'])   : '';
$search_nopol   = isset($_GET['search_nopol'])   ? mysqli_real_escape_string($connection, $_GET['search_nopol'])   : '';
$search_tanggal = isset($_GET['search_tanggal']) ? mysqli_real_escape_string($connection, $_GET['search_tanggal']) : '';
$search_nomor   = isset($_GET['search_nomor'])   ? mysqli_real_escape_string($connection, $_GET['search_nomor'])   : '';

$where_clause = "WHERE p.id_pengaju = '$id_user' AND p.status != 'Selesai'";

if (!empty($filter_status)) {
    if ($filter_status === 'Disetujui_KARU_QC') {
        $where_clause = "WHERE p.id_pengaju = '$id_user' AND p.status IN ('Disetujui_KARU_QC', 'QC', 'Dikembalikan_sa')";
    } elseif ($filter_status === 'Perlu_Persetujuan') {
        $where_clause = "WHERE p.id_pengaju = '$id_user' AND p.status IN ('Dikembalikan_sa','Minta_Persetujuan_Pengawas') AND p.persetujuan_pengawas = 'Menunggu'";
    } elseif ($filter_status === 'Selesai') {
        $where_clause = "WHERE p.id_pengaju = '$id_user' AND p.status = 'Selesai'";
    } else {
        $where_clause = "WHERE p.id_pengaju = '$id_user' AND p.status = '$filter_status'";
    }
}
if (!empty($search_nopol))   $where_clause .= " AND k.nopol LIKE '%$search_nopol%'";
if (!empty($search_tanggal) && strtotime($search_tanggal) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_tanggal)) {
    $where_clause .= " AND DATE(p.tgl_pengajuan) = '$search_tanggal'";
}
if (!empty($search_nomor))   $where_clause .= " AND p.nomor_pengajuan LIKE '%$search_nomor%'";
if (!empty($filter_bidang))  $where_clause .= " AND k.bidang = '$filter_bidang'";
if (!empty($filter_jenis))   $where_clause .= " AND k.jenis_kendaraan LIKE '%$filter_jenis%'";

/* =======================
   GET LIST BIDANG & JENIS (untuk dropdown filter)
======================= */
$list_bidang = [];
$qb = mysqli_query($connection, "
    SELECT DISTINCT k.bidang 
    FROM kendaraan k 
    JOIN permintaan_perbaikan p ON k.id_kendaraan = p.id_kendaraan
    WHERE p.id_pengaju = '$id_user'
      AND p.status != 'Selesai'
      AND k.bidang IS NOT NULL AND k.bidang != '' 
    ORDER BY k.bidang ASC
");
while ($rb = mysqli_fetch_assoc($qb)) $list_bidang[] = $rb['bidang'];

$list_jenis = [];
$qj = mysqli_query($connection, "
    SELECT DISTINCT k.jenis_kendaraan 
    FROM kendaraan k 
    JOIN permintaan_perbaikan p ON k.id_kendaraan = p.id_kendaraan
    WHERE p.id_pengaju = '$id_user'
      AND p.status != 'Selesai'
      AND k.jenis_kendaraan IS NOT NULL AND k.jenis_kendaraan != '' 
    ORDER BY k.jenis_kendaraan ASC
");
while ($rj = mysqli_fetch_assoc($qj)) $list_jenis[] = $rj['jenis_kendaraan'];

/* =======================
   PAGINATION
======================= */
$per_page   = 25;
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$count_q    = mysqli_query($connection, "
    SELECT COUNT(*) AS total
    FROM permintaan_perbaikan p
    JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
    LEFT JOIN rekanan r ON p.id_rekanan = r.id_rekanan
    $where_clause
");
$total_data  = mysqli_fetch_assoc($count_q)['total'];
$total_pages = max(1, ceil($total_data / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;
$from_record = $total_data > 0 ? $offset + 1 : 0;
$to_record   = min($offset + $per_page, $total_data);

/* =======================
   GET DATA FOR EDIT
======================= */
$edit_data = null;
if (isset($_GET['edit'])) {
    $id_edit    = mysqli_real_escape_string($connection, $_GET['edit']);
    $edit_query = mysqli_query($connection, "
        SELECT * FROM permintaan_perbaikan 
        WHERE id_permintaan = '$id_edit' AND id_pengaju = '$id_user'
    ");
    $edit_data = mysqli_fetch_assoc($edit_query);
}

$success_msg = $_SESSION['success_msg'] ?? '';
unset($_SESSION['success_msg']);

// Nama bulan Indonesia
$nama_bulan = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
    7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];

/* =======================
   HELPER: BUILD URL PAGINATION
======================= */
function buildPageUrl($p) {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengajuan Perbaikan</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: rgb(185, 224, 204);
            overflow-x: hidden;
        }

        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        .main-content.full-width { margin-left: 0; }

        /* ======= HEADER ======= */
        .header {
            background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%);
            color: #fff;
            padding: 16px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 { font-size: 1.6em; }
        .header-info { display: flex; gap: 15px; align-items: center; font-size: 0.9em; }

        /* ======= ALERT ======= */
        .alert {
            position: fixed; top: 80px; right: 30px;
            padding: 15px 20px; border-radius: 8px;
            display: flex; align-items: center; gap: 10px;
            z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        .alert-success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
        .alert-info    { background: #d1ecf1; border-left: 4px solid #17a2b8; color: #0c5460; }

        /* ======= DASHBOARD STATS SECTION ======= */
        .stats-section {
            padding: 20px 30px 0 30px;
        }

        /* Filter bulan/tahun di atas cards */
        .dash-filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            background: white;
            padding: 12px 20px;
            border-radius: 12px 12px 0 0;
            border-bottom: 2px solid #ecf0f1;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        .dash-filter-bar label {
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.88em;
            white-space: nowrap;
        }
        .dash-filter-bar select {
            padding: 7px 12px;
            border: 2px solid #ecf0f1;
            border-radius: 7px;
            font-size: 0.88em;
            color: #2c3e50;
            background: #f8f9fa;
            cursor: pointer;
            transition: border-color 0.2s;
            min-width: 130px;
        }
        .dash-filter-bar select:focus { outline: none; border-color: #248a3d; }
        .dash-filter-bar .btn-reset-dash {
            padding: 7px 14px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 7px;
            font-size: 0.85em;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .dash-filter-bar .btn-reset-dash:hover { background: #c0392b; }
        .dash-period-label {
            margin-left: auto;
            font-size: 0.82em;
            color: #7f8c8d;
            font-style: italic;
            white-space: nowrap;
        }

        /* Stats Cards Grid */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 25px;
        }

        .stat-card {
            padding: 22px 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-top: 4px solid;
            position: relative;
            overflow: hidden;
            cursor: default;
        }
        .stat-card:not(:last-child) { border-right: 1px solid #f0f0f0; }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            z-index: 1;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            bottom: -20px; right: -20px;
            width: 80px; height: 80px;
            border-radius: 50%;
            opacity: 0.06;
        }

        .stat-card.total        { border-top-color: #3498db; }
        .stat-card.proses       { border-top-color: #f39c12; }
        .stat-card.persetujuan  { border-top-color: #9b59b6; }
        .stat-card.selesai      { border-top-color: #2ecc71; }

        .stat-card.total::after        { background: #3498db; }
        .stat-card.proses::after       { background: #f39c12; }
        .stat-card.persetujuan::after  { background: #9b59b6; }
        .stat-card.selesai::after      { background: #2ecc71; }

        .stat-icon {
            width: 46px; height: 46px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3em;
            margin-bottom: 12px;
        }
        .stat-card.total .stat-icon       { background: rgba(52,152,219,0.12); color: #3498db; }
        .stat-card.proses .stat-icon      { background: rgba(243,156,18,0.12); color: #f39c12; }
        .stat-card.persetujuan .stat-icon { background: rgba(155,89,182,0.12); color: #9b59b6; }
        .stat-card.selesai .stat-icon     { background: rgba(46,204,113,0.12); color: #2ecc71; }

        .stat-number {
            font-size: 2.2em;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 6px;
        }
        .stat-card.total .stat-number       { color: #3498db; }
        .stat-card.proses .stat-number      { color: #f39c12; }
        .stat-card.persetujuan .stat-number { color: #9b59b6; }
        .stat-card.selesai .stat-number     { color: #2ecc71; }

        .stat-label {
            font-size: 0.88em;
            font-weight: 600;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .stat-sub {
            font-size: 0.75em;
            color: #bdc3c7;
            margin-top: 3px;
        }

        /* ======= CONTENT SECTION ======= */
        .content-section { padding: 0 30px 30px 30px; }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }
        .card-header {
            padding: 18px 25px;
            border-bottom: 1px solid #ecf0f1;
            background: #fafbfc;
        }
        .card-header h3 {
            color: #2c3e50;
            font-size: 1.15em;
            display: flex; align-items: center; gap: 10px;
        }
        .card-body { padding: 22px 25px; }

        /* ======= FORM ======= */
        .form-row { display: grid; grid-template-columns: 1fr; gap: 18px; margin-bottom: 18px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { margin-bottom: 7px; font-weight: 600; color: #2c3e50; font-size: 0.93em; }
        .form-control {
            padding: 11px 14px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 0.93em;
            transition: all 0.3s;
            background: white;
            width: 100%;
        }
        .form-control:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }
        textarea.form-control { resize: vertical; min-height: 110px; font-family: inherit; text-transform: uppercase; }

        /* Custom Select */
        .custom-select-wrapper { position: relative; width: 100%; }
        .search-select-input { padding-right: 40px; cursor: pointer; user-select: none; }
        .select-arrow {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%); color: #7f8c8d;
            pointer-events: none; transition: transform 0.3s; font-size: 0.88em;
        }
        .custom-select-wrapper.active .select-arrow { transform: translateY(-50%) rotate(180deg); }
        .select-dropdown {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            background: white; border: 2px solid #3498db; border-radius: 8px;
            max-height: 260px; overflow-y: auto; z-index: 1000; display: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        .select-dropdown.show { display: block; animation: dropDown 0.18s ease-out; }
        @keyframes dropDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        .dropdown-item {
            padding: 12px 15px; cursor: pointer; transition: all 0.2s;
            border-bottom: 1px solid #f0f0f0;
            display: flex; align-items: center; gap: 10px; font-size: 0.92em;
        }
        .dropdown-item:last-child { border-bottom: none; }
        .dropdown-item:hover { background: #f8f9fa; color: #3498db; padding-left: 18px; }
        .dropdown-item.active { background: #3498db; color: white; font-weight: 600; }
        .dropdown-item.active:hover { background: #2980b9; }
        .dropdown-item.hidden { display: none; }
        .dropdown-item::before { content: '\f1b9'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 0.82em; opacity: 0.4; }
        .dropdown-item.active::before { content: '\f00c'; opacity: 1; }
        .dropdown-empty { padding: 12px 15px; color: #95a5a6; font-style: italic; text-align: center; font-size: 0.88em; }

        /* ======= BUTTONS ======= */
        .btn {
            padding: 11px 22px; border: none; border-radius: 8px;
            cursor: pointer; font-size: 0.92em; font-weight: 600;
            transition: all 0.3s; display: inline-flex;
            align-items: center; justify-content: center; gap: 8px; text-decoration: none;
        }
        .btn-primary   { background: #3498db; color: white; }
        .btn-primary:hover   { background: #2980b9; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(52,152,219,0.4); }
        .btn-success   { background: #2ecc71; color: white; }
        .btn-success:hover   { background: #27ae60; transform: translateY(-2px); }
        .btn-warning   { background: #f39c12; color: white; }
        .btn-warning:hover   { background: #e67e22; }
        .btn-danger    { background: #e74c3c; color: white; }
        .btn-danger:hover    { background: #c0392b; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .btn-sm { padding: 7px 14px; font-size: 0.83em; }
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }

        /* ======= SEARCH BOX ======= */
        .search-box {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px; margin-bottom: 18px;
        }

        /* ======= TABLE ======= */
        .table-wrapper { overflow-x: auto; overflow-y: auto; max-height: 70vh; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.07); }
        .table { width: 100%; border-collapse: collapse; min-width: 1150px; background: white; }
        .table thead { position: sticky; top: 0; z-index: 10; }
        .table th {
            background: rgb(9,120,83); color: white;
            padding: 16px 13px; text-align: left; font-weight: 600;
            white-space: nowrap; font-size: 0.87em;
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .table td { padding: 13px; border-bottom: 1px solid #ecf0f1; vertical-align: top; }
        .table tbody tr { transition: background 0.2s; }
        .table tbody tr:hover { background: #f8fffe; }

        /* ======= BADGES ======= */
        .nopol-badge {
            background: #1abc9c; color: white;
            padding: 5px 12px; border-radius: 20px;
            font-weight: bold; font-size: 0.88em;
            display: inline-block; white-space: nowrap;
        }
        .bidang-badge {
            background: #3498db; color: white;
            padding: 3px 9px; border-radius: 12px;
            font-size: 0.78em; display: inline-block; margin-top: 4px;
        }
        .nomor-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 4px 11px;
            border-radius: 14px; font-weight: bold;
            font-size: 0.88em; white-space: nowrap;
        }

        /* ======= TIMELINE ======= */
        .timeline-proses { display: flex; flex-direction: column; gap: 7px; }
        .timeline-item { display: flex; align-items: flex-start; gap: 7px; font-size: 0.83em; }
        .timeline-item.completed i.tl-icon { color: #27ae60; font-size: 1em; }
        .timeline-item.pending i.tl-icon   { color: #bdc3c7; font-size: 1em; }
        .timeline-item.waiting i.tl-icon   { color: #f39c12; font-size: 1em; }
        .timeline-status  { font-weight: 600; color: #2c3e50; }
        .timeline-date    { font-size: 0.88em; color: #7f8c8d; }
        .timeline-note    { font-size: 0.82em; color: #f39c12; font-style: italic; margin-top: 2px; }

        /* ======= APPROVAL ======= */
        .approval-status {
            margin-top: 5px; padding: 5px 9px; border-radius: 7px;
            font-size: 0.8em; display: inline-flex; align-items: center; gap: 5px;
        }
        .approval-menunggu  { background: #fff3cd; border-left: 3px solid #f39c12; color: #856404; }
        .approval-disetujui { background: #d4edda; border-left: 3px solid #28a745; color: #155724; }
        .approval-ditolak   { background: #f8d7da; border-left: 3px solid #dc3545; color: #721c24; }

        .asal-badge {
            font-size: 0.73em; padding: 2px 9px; border-radius: 10px;
            display: inline-flex; align-items: center; gap: 4px;
            font-weight: 600; margin-top: 3px;
        }
        .asal-qc { background: #ede9fe; color: #7c3aed; border: 1px solid #c4b5fd; }
        .asal-sa { background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; }

        .persetujuan-btns { display: flex; gap: 7px; margin-top: 7px; flex-wrap: wrap; }
        .btn-setuju {
            background: #8b5cf6; color: white; border: none;
            padding: 6px 12px; border-radius: 6px;
            font-size: 0.8em; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: 5px;
            transition: background 0.2s; text-decoration: none;
        }
        .btn-setuju:hover { background: #7c3aed; }

        /* ======= BIAYA ======= */
        .rincian-biaya { display: table; border-collapse: collapse; font-size: 0.83em; width: auto; }
        .biaya-item { display: table-row; }
        .biaya-label { display: table-cell; padding: 2px 8px 2px 0; color: #7f8c8d; white-space: nowrap; vertical-align: baseline; }
        .biaya-value { display: table-cell; padding: 2px 0; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; color: #2c3e50; vertical-align: baseline; }
        .biaya-total .biaya-label { font-weight: 700; color: #2c3e50; padding-top: 7px; border-top: 2px solid #ecf0f1; }
        .biaya-total .biaya-value { font-weight: 700; color: #27ae60; padding-top: 7px; border-top: 2px solid #ecf0f1; }

        .action-btns { display: flex; gap: 7px; flex-wrap: wrap; }
        .keluhan-text { text-transform: uppercase; }

        /* ======= EMPTY ======= */
        .empty-state { text-align: center; padding: 50px 20px; color: #95a5a6; }
        .empty-state i { font-size: 3.5em; margin-bottom: 15px; opacity: 0.45; }

        /* ======= PAGINATION ======= */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 2px solid #f0f0f0;
        }
        .pagination-info {
            font-size: 0.88em;
            color: #7f8c8d;
        }
        .pagination-info strong { color: #2c3e50; }
        .pagination-pages {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        .pg-btn {
            min-width: 36px; height: 36px;
            padding: 0 10px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            background: white;
            color: #2c3e50;
            font-size: 0.88em;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
        }
        .pg-btn:hover:not(.disabled):not(.active) {
            background: #f0faf5;
            border-color: #248a3d;
            color: #248a3d;
        }
        .pg-btn.active {
            background: rgb(9,120,83);
            border-color: rgb(9,120,83);
            color: white;
            cursor: default;
        }
        .pg-btn.disabled {
            opacity: 0.38;
            cursor: not-allowed;
            pointer-events: none;
        }
        .pg-btn.ellipsis {
            border-color: transparent;
            background: transparent;
            cursor: default;
            pointer-events: none;
            letter-spacing: 1px;
        }

        /* ======= RESPONSIVE ======= */
        @media (max-width: 1024px) {
            .stats-container { grid-template-columns: repeat(2, 1fr); }
            .stat-card:nth-child(2) { border-right: 1px solid #f0f0f0; }
            .stat-card:nth-child(even) { border-right: none; }
            .stat-card:nth-child(1),
            .stat-card:nth-child(2) { border-bottom: 1px solid #f0f0f0; }
        }

        @media (max-width: 768px) {
            .main-content   { margin-left: 0; }
            .header         { padding: 60px 15px 15px 15px; }
            .stats-section  { padding: 15px 15px 0 15px; }
            .content-section { padding: 15px; }
            .stats-container { grid-template-columns: repeat(2, 1fr); }
            .dash-filter-bar { padding: 10px 14px; gap: 8px; }
            .dash-filter-bar select { min-width: 110px; }
            .dash-period-label { display: none; }
            .stat-card { padding: 16px 14px; }
            .stat-number { font-size: 1.8em; }
            .card-body { padding: 15px; }
            .search-box { grid-template-columns: 1fr 1fr; }
            .pagination-bar { justify-content: center; }
        }

        @media (max-width: 480px) {
            .stats-container { grid-template-columns: 1fr 1fr; }
            .search-box { grid-template-columns: 1fr; }
            .header h1 { font-size: 1.2em; }
            .btn-group { gap: 8px; }
        }

        /* Scrollbar */
        .table-wrapper::-webkit-scrollbar { width: 7px; height: 7px; }
        .table-wrapper::-webkit-scrollbar-track { background: #f1f1f1; }
        .table-wrapper::-webkit-scrollbar-thumb { background: #bdc3c7; border-radius: 4px; }
    </style>
</head>
<body>

<?php include "navbar_pengawas.php"; ?>

<div class="main-content" id="mainContent">

    <!-- Header -->
    <div class="header">
        <h1><i class="fas fa-tools"></i> Pengajuan Perbaikan</h1>
        <div class="header-info">
            <span><i class="fas fa-calendar"></i> <?= date('d F Y') ?></span>
        </div>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success" id="alertSuccess">
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($success_msg) ?></span>
    </div>
    <script>
        setTimeout(() => { const a = document.getElementById('alertSuccess'); if(a) a.style.display='none'; }, 5000);
    </script>
    <?php endif; ?>

    <!-- ===== DASHBOARD STATS ===== -->
    <div class="stats-section">

        <!-- Filter Bulan & Tahun -->
        <form method="GET" id="dashFilterForm" class="dash-filter-bar">
            <!-- Pertahankan filter tabel jika ada -->
            <?php if(!empty($filter_status))  echo "<input type='hidden' name='filter_status' value='".htmlspecialchars($filter_status)."'>"; ?>
            <?php if(!empty($search_nopol))   echo "<input type='hidden' name='search_nopol' value='".htmlspecialchars($search_nopol)."'>"; ?>
            <?php if(!empty($search_tanggal)) echo "<input type='hidden' name='search_tanggal' value='".htmlspecialchars($search_tanggal)."'>"; ?>
            <?php if(!empty($search_nomor))   echo "<input type='hidden' name='search_nomor' value='".htmlspecialchars($search_nomor)."'>"; ?>
            <?php if(!empty($filter_bidang))  echo "<input type='hidden' name='filter_bidang' value='".htmlspecialchars($filter_bidang)."'>"; ?>
            <?php if(!empty($filter_jenis))   echo "<input type='hidden' name='filter_jenis' value='".htmlspecialchars($filter_jenis)."'>"; ?>

            <label><i class="fas fa-chart-bar"></i> Dashboard:</label>

            <!-- Pilih Bulan -->
            <select name="dash_bulan" onchange="document.getElementById('dashFilterForm').submit()">
                <option value="0">— Semua Bulan —</option>
                <?php foreach($nama_bulan as $num => $nm): ?>
                    <option value="<?= $num ?>" <?= ($dash_bulan == $num) ? 'selected' : '' ?>><?= $nm ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Pilih Tahun -->
            <select name="dash_tahun" onchange="document.getElementById('dashFilterForm').submit()">
                <option value="0">— Semua Tahun —</option>
                <?php foreach($list_tahun as $thn): ?>
                    <option value="<?= $thn ?>" <?= ($dash_tahun == $thn) ? 'selected' : '' ?>><?= $thn ?></option>
                <?php endforeach; ?>
                <?php if(!in_array(date('Y'), $list_tahun)): ?>
                    <option value="<?= date('Y') ?>" <?= ($dash_tahun == date('Y')) ? 'selected' : '' ?>><?= date('Y') ?></option>
                <?php endif; ?>
            </select>

            <?php if($dash_bulan > 0 || $dash_tahun > 0): ?>
                <a href="pengajuan_perbaikan.php" class="btn-reset-dash">
                    <i class="fas fa-times"></i> Reset
                </a>
            <?php endif; ?>

            <span class="dash-period-label">
                <i class="fas fa-calendar-alt"></i>
                <?php
                if ($dash_bulan > 0 && $dash_tahun > 0)
                    echo "Menampilkan: " . $nama_bulan[$dash_bulan] . " " . $dash_tahun;
                elseif ($dash_bulan > 0)
                    echo "Menampilkan: " . $nama_bulan[$dash_bulan] . " (Semua Tahun)";
                elseif ($dash_tahun > 0)
                    echo "Menampilkan: Tahun " . $dash_tahun;
                else
                    echo "Menampilkan: Semua Periode";
                ?>
            </span>
        </form>

        <!-- Stat Cards -->
        <div class="stats-container">
            <!-- 1. Total Keseluruhan -->
            <div class="stat-card total">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Keseluruhan</div>
                <div class="stat-sub">Semua pengajuan</div>
            </div>

            <!-- 2. Proses -->
            <div class="stat-card proses">
                <div class="stat-icon"><i class="fas fa-spinner"></i></div>
                <div class="stat-number"><?= $stats['proses'] ?></div>
                <div class="stat-label">Proses</div>
                <div class="stat-sub">Belum selesai</div>
            </div>

            <!-- 3. Perlu Persetujuan -->
            <div class="stat-card persetujuan">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number"><?= $stats['perlu_persetujuan'] ?></div>
                <div class="stat-label">Perlu Persetujuan</div>
                <div class="stat-sub">Menunggu keputusan Anda</div>
            </div>

            <!-- 4. Selesai -->
            <div class="stat-card selesai">
                <div class="stat-icon"><i class="fas fa-check-double"></i></div>
                <div class="stat-number"><?= $stats['selesai'] ?></div>
                <div class="stat-label">Selesai</div>
                <div class="stat-sub">Telah diselesaikan</div>
            </div>
        </div>

    </div><!-- /stats-section -->


    <!-- ===== CONTENT SECTION ===== -->
    <div class="content-section">

        <!-- Form Card -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-<?= $edit_data ? 'edit' : 'plus-circle' ?>"></i>
                    <?= $edit_data ? 'Edit Pengajuan' : 'Form Pengajuan Baru' ?>
                </h3>
            </div>
            <div class="card-body">
                <?php if (!$edit_data): ?>
                <div class="alert alert-info" style="position:static;animation:none;margin-bottom:16px;">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Nomor pengajuan berikutnya:</strong>
                        <?php
                        $tahun_now  = date('Y');
                        $qn = mysqli_query($connection, "
                            SELECT nomor_pengajuan FROM permintaan_perbaikan
                            WHERE nomor_pengajuan LIKE '$tahun_now-%'
                            ORDER BY nomor_pengajuan DESC LIMIT 1
                        ");
                        if (mysqli_num_rows($qn) > 0) {
                            $lr = mysqli_fetch_assoc($qn);
                            $nu = intval(substr($lr['nomor_pengajuan'], 5)) + 1;
                        } else { $nu = 1; }
                        echo "<span class='nomor-badge'>" . $tahun_now . '-' . str_pad($nu, 2, '0', STR_PAD_LEFT) . "</span>";
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" id="formPengajuan">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="id_permintaan" value="<?= $edit_data['id_permintaan'] ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-car"></i> Nomor Asset</label>
                            <div class="custom-select-wrapper">
                                <input type="text" id="search_kendaraan_input"
                                    class="form-control search-select-input"
                                    placeholder="Ketik atau pilih Nomor Asset..."
                                    autocomplete="off" readonly
                                    onfocus="this.removeAttribute('readonly')">
                                <div class="select-dropdown" id="kendaraan_dropdown">
                                    <div class="dropdown-item" data-value="">-- Pilih Nomor Asset --</div>
                                    <?php
                                    $k = mysqli_query($connection, "SELECT * FROM kendaraan WHERE status='Aktif' ORDER BY nopol");
                                    while ($r = mysqli_fetch_assoc($k)) {
                                        $sel = ($edit_data && $edit_data['id_kendaraan'] == $r['id_kendaraan']) ? 'active' : '';
                                        echo "<div class='dropdown-item $sel' data-value='{$r['id_kendaraan']}' data-text='{$r['nopol']}'>{$r['nopol']}</div>";
                                    }
                                    ?>
                                </div>
                                <input type="hidden" name="id_kendaraan" id="id_kendaraan" required>
                                <i class="fas fa-chevron-down select-arrow"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Keluhan Kendaraan *</label>
                        <textarea name="keluhan_awal" id="keluhan_awal" class="form-control"
                            placeholder="Deskripsikan keluhan atau masalah pada kendaraan..." required><?= $edit_data ? htmlspecialchars($edit_data['keluhan_awal']) : '' ?></textarea>
                    </div>

                    <div class="btn-group">
                        <?php if ($edit_data): ?>
                            <button type="submit" name="update" class="btn btn-success">
                                <i class="fas fa-save"></i> Update Pengajuan
                            </button>
                            <a href="pengajuan_perbaikan.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Batal
                            </a>
                        <?php else: ?>
                            <button type="submit" name="ajukan" class="btn btn-primary">
                                <i class="fas fa-rocket"></i> Ajukan Perbaikan
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Table Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Daftar Pengajuan Saya</h3>
            </div>
            <div class="card-body">
                <div class="search-box">
                    <div class="form-group">
                        <label><i class="fas fa-filter"></i> Filter Proses</label>
                        <select id="filter_status" class="form-control">
                            <option value="">-- Semua Proses --</option>
                            <option value="Diajukan"          <?= ($filter_status=='Diajukan')          ?'selected':''?>>Diajukan</option>
                            <option value="Diperiksa_SA"      <?= ($filter_status=='Diperiksa_SA')      ?'selected':''?>>Diperiksa SA</option>
                            <option value="Disetujui_KARU_QC" <?= ($filter_status=='Disetujui_KARU_QC') ?'selected':''?>>Disetujui KARU QC</option>
                            <option value="Perlu_Persetujuan" <?= ($filter_status=='Perlu_Persetujuan') ?'selected':''?>>&#9888; Perlu Persetujuan Pengawas</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Bidang</label>
                        <select id="filter_bidang" class="form-control">
                            <option value="">-- Semua Bidang --</option>
                            <?php foreach ($list_bidang as $bd): ?>
                                <option value="<?= htmlspecialchars($bd) ?>" <?= ($filter_bidang==$bd)?'selected':''?>><?= htmlspecialchars($bd) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-truck"></i> Jenis Kendaraan</label>
                        <select id="filter_jenis" class="form-control">
                            <option value="">-- Semua Jenis --</option>
                            <?php foreach ($list_jenis as $jk): ?>
                                <option value="<?= htmlspecialchars($jk) ?>" <?= ($filter_jenis==$jk)?'selected':''?>><?= htmlspecialchars($jk) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Nomor Pengajuan</label>
                        <input type="text" id="search_nomor" class="form-control"
                            placeholder="Cari nomor..." value="<?= htmlspecialchars($search_nomor) ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-car"></i> Nomor Asset</label>
                        <input type="text" id="search_nopol" class="form-control"
                            placeholder="Cari Nomor Asset..." value="<?= htmlspecialchars($search_nopol) ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Tanggal Pengajuan</label>
                        <input type="date" id="search_tanggal" class="form-control"
                            value="<?= htmlspecialchars($search_tanggal) ?>">
                    </div>
                </div>
                <?php
                $ada_filter = (!empty($filter_status) || !empty($filter_bidang) || !empty($filter_jenis) || !empty($search_nopol) || !empty($search_tanggal) || !empty($search_nomor));
                $reset_url  = 'pengajuan_perbaikan.php';
                // Pertahankan filter dashboard jika ada
                $dash_params = [];
                if ($dash_bulan > 0) $dash_params[] = 'dash_bulan=' . $dash_bulan;
                if ($dash_tahun > 0) $dash_params[] = 'dash_tahun=' . $dash_tahun;
                if (!empty($dash_params)) $reset_url .= '?' . implode('&', $dash_params);
                ?>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                    <div style="font-size:0.85em; color:#7f8c8d;">
                        <?php if ($ada_filter): ?>
                            <i class="fas fa-filter" style="color:#e74c3c;"></i>
                            <strong style="color:#e74c3c;">Filter aktif</strong> — menampilkan hasil yang difilter
                        <?php else: ?>
                            <i class="fas fa-list"></i> Menampilkan semua data aktif
                        <?php endif; ?>
                    </div>
                    <?php if ($ada_filter): ?>
                    <a href="<?= $reset_url ?>" class="btn btn-danger btn-sm" style="white-space:nowrap;">
                        <i class="fas fa-times-circle"></i> Reset Filter
                    </a>
                    <?php endif; ?>
                </div>

                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:40px;">NO</th>
                                <th style="width:115px;">NO. PENGAJUAN</th>
                                <th style="width:130px;">NOMOR ASSET</th>
                                <th style="width:140px;">JENIS KENDARAAN</th>
                                <th style="width:110px;">BIDANG</th>
                                <th style="width:190px;">KELUHAN</th>
                                <th style="width:270px;">TIMELINE PROSES</th>
                                <th style="width:110px;">REKANAN</th>
                                <th style="width:140px;">RINCIAN BIAYA</th>
                                <th style="width:130px;">KETERANGAN</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                        <?php
                        $no = $offset + 1;
                        $q  = mysqli_query($connection, "
                            SELECT p.*, k.nopol, k.jenis_kendaraan, k.bidang, r.nama_rekanan
                            FROM permintaan_perbaikan p
                            JOIN kendaraan k ON p.id_kendaraan = k.id_kendaraan
                            LEFT JOIN rekanan r ON p.id_rekanan = r.id_rekanan
                            $where_clause
                            ORDER BY p.nomor_pengajuan DESC
                            LIMIT $per_page OFFSET $offset
                        ");

                        if (mysqli_num_rows($q) > 0):
                            while ($d = mysqli_fetch_assoc($q)):
                                $status_current     = $d['status'];
                                $tgl_pengajuan      = !empty($d['tgl_pengajuan']);
                                $tgl_diperiksa_sa   = !empty($d['tgl_diperiksa_sa']);
                                $tgl_disetujui_karu = !empty($d['tgl_disetujui_karu_qc']);
                                $tgl_selesai        = !empty($d['tgl_selesai']);

                                $show_persetujuan = in_array($status_current, ['Dikembalikan_sa','Minta_Persetujuan_Pengawas','QC','Disetujui_KARU_QC','Selesai'])
                                                 || !is_null($d['persetujuan_pengawas']);

                                $need_approval_btn = (in_array($status_current, ['Dikembalikan_sa','Minta_Persetujuan_Pengawas'])
                                                 && $d['persetujuan_pengawas'] === 'Menunggu');
                        ?>
                            <tr>
                                <td style="text-align:center;font-weight:bold;color:#2c3e50;"><?= $no++ ?></td>
                                <td><span class="nomor-badge"><?= htmlspecialchars($d['nomor_pengajuan']) ?></span></td>
                                <td><span class="nopol-badge"><?= htmlspecialchars($d['nopol']) ?></span></td>
                                <td><strong style="color:#2c3e50;font-size:0.9em;"><?= htmlspecialchars($d['jenis_kendaraan']) ?></strong></td>
                                <td><span class="bidang-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($d['bidang']) ?></span></td>
                                <td>
                                    <span class="keluhan-text" style="font-size:0.83em;color:#2c3e50;line-height:1.4;">
                                        <?= htmlspecialchars($d['keluhan_awal']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="timeline-proses">
                                        <!-- 1. Diajukan -->
                                        <div class="timeline-item <?= $tgl_pengajuan?'completed':'pending' ?>">
                                            <i class="fas fa-check-circle tl-icon"></i>
                                            <div style="flex:1;">
                                                <div class="timeline-status">Diajukan</div>
                                                <?php if ($tgl_pengajuan): ?>
                                                    <div class="timeline-date"><?= date('d/m/Y H:i', strtotime($d['tgl_pengajuan'])) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <!-- 2. Diperiksa SA -->
                                        <div class="timeline-item <?= $tgl_diperiksa_sa?'completed':'pending' ?>">
                                            <i class="fas fa-check-circle tl-icon"></i>
                                            <div style="flex:1;">
                                                <div class="timeline-status">Diperiksa SA</div>
                                                <?php if ($tgl_diperiksa_sa): ?>
                                                    <div class="timeline-date"><?= date('d/m/Y H:i', strtotime($d['tgl_diperiksa_sa'])) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <!-- 3. Disetujui KARU QC -->
                                        <div class="timeline-item <?= $tgl_disetujui_karu?'completed':'pending' ?>">
                                            <i class="fas fa-check-circle tl-icon"></i>
                                            <div style="flex:1;">
                                                <div class="timeline-status">Disetujui KARU QC</div>
                                                <?php if ($tgl_disetujui_karu): ?>
                                                    <div class="timeline-date"><?= date('d/m/Y H:i', strtotime($d['tgl_disetujui_karu_qc'])) ?></div>
                                                    <div class="timeline-note"><i class="fas fa-wrench"></i> Proses dikerjakan di bengkel</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <!-- 4. Persetujuan Pengawas -->
                                        <?php if ($show_persetujuan): ?>
                                        <div class="timeline-item <?= ($d['persetujuan_pengawas']==='Menunggu')?'waiting':'completed' ?>">
                                            <i class="fas fa-<?= ($d['persetujuan_pengawas']==='Disetujui')?'check-circle':(($d['persetujuan_pengawas']==='Ditolak')?'times-circle':'clock') ?> tl-icon"></i>
                                            <div style="flex:1;">
                                                <div class="timeline-status">Persetujuan Pengawas</div>
                                                <div class="approval-status approval-<?= strtolower($d['persetujuan_pengawas']) ?>">
                                                    <i class="fas fa-<?= ($d['persetujuan_pengawas']==='Disetujui')?'thumbs-up':(($d['persetujuan_pengawas']==='Ditolak')?'thumbs-down':'hourglass-half') ?>"></i>
                                                    <strong>
                                                        <?php
                                                        if ($d['persetujuan_pengawas']==='Menunggu')   echo 'Menunggu Persetujuan';
                                                        elseif ($d['persetujuan_pengawas']==='Disetujui') echo 'Disetujui';
                                                        elseif ($d['persetujuan_pengawas']==='Ditolak')  echo 'Ditolak';
                                                        ?>
                                                    </strong>
                                                </div>
                                                <?php if ($d['persetujuan_pengawas']==='Disetujui' && !empty($d['asal_persetujuan'])): ?>
                                                    <div class="asal-badge asal-<?= strtolower($d['asal_persetujuan']) ?>">
                                                        <i class="fas fa-<?= $d['asal_persetujuan']=='QC'?'user-tie':'user-cog' ?>"></i>
                                                        Dari <?= $d['asal_persetujuan'] ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($d['tgl_persetujuan_pengawas'])): ?>
                                                    <div class="timeline-date"><?= date('d/m/Y H:i', strtotime($d['tgl_persetujuan_pengawas'])) ?></div>
                                                <?php endif; ?>
                                                <?php if ($need_approval_btn): ?>
                                                <div class="persetujuan-btns">
                                                    <a href="detail_persetujuan_pengawas.php?id=<?= $d['id_permintaan'] ?>" class="btn-setuju">
                                                        <i class="fas fa-eye"></i> Lihat & Putuskan
                                                    </a>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <!-- 5. Selesai -->
                                        <div class="timeline-item <?= $tgl_selesai?'completed':'pending' ?>">
                                            <i class="fas fa-check-circle tl-icon"></i>
                                            <div style="flex:1;">
                                                <div class="timeline-status">Selesai</div>
                                                <?php if ($tgl_selesai): ?>
                                                    <div class="timeline-date"><?= date('d/m/Y H:i', strtotime($d['tgl_selesai'])) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($d['nama_rekanan'])): ?>
                                        <strong style="color:#27ae60;font-size:0.88em;">
                                            <i class="fas fa-tools"></i> <?= htmlspecialchars($d['nama_rekanan']) ?>
                                        </strong>
                                    <?php else: ?>
                                        <span style="color:#95a5a6;font-style:italic;font-size:0.85em;">
                                            <i class="fas fa-hourglass-half"></i> Menunggu
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="rincian-biaya">
                                        <div class="biaya-item">
                                            <span class="biaya-label">Jasa</span>
                                            <span class="biaya-value">Rp <?= number_format($d['total_perbaikan'],0,',','.') ?></span>
                                        </div>
                                        <div class="biaya-item">
                                            <span class="biaya-label">Sparepart</span>
                                            <span class="biaya-value">Rp <?= number_format($d['total_sparepart'],0,',','.') ?></span>
                                        </div>
                                        <div class="biaya-item biaya-total">
                                            <span class="biaya-label">Total</span>
                                            <span class="biaya-value">Rp <?= number_format($d['grand_total'],0,',','.') ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <?php if ($d['status']==='Diajukan'): ?>
                                            <a href="?edit=<?= $d['id_permintaan'] ?>" class="btn btn-warning btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="?delete=<?= $d['id_permintaan'] ?>" class="btn btn-danger btn-sm"
                                               onclick="return confirm('⚠️ HAPUS PENGAJUAN PERMANEN?\n\nNo: <?= htmlspecialchars($d['nomor_pengajuan']) ?>\n\n❌ Data TIDAK DAPAT dikembalikan!\n\nYakin hapus?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php elseif (in_array($d['status'],['Diperiksa_SA','Disetujui_KARU_QC','Minta_Persetujuan_Pengawas','QC','Dikembalikan_sa','Selesai'])): ?>
                                            <a href="detail_pengajuan.php?id=<?= $d['id_permintaan'] ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> Detail
                                            </a>
                                        <?php else: ?>
                                            <span style="color:#95a5a6;font-size:0.83em;">
                                                <i class="fas fa-lock"></i> Terkunci
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                            <tr>
                                <td colspan="10">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h3>Tidak ada data</h3>
                                        <p>Belum ada pengajuan perbaikan yang ditemukan</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ===== PAGINATION BAR ===== -->
                <?php if ($total_data > 0): ?>
                <div class="pagination-bar">

                    <!-- Info -->
                    <div class="pagination-info">
                        Menampilkan <strong><?= $from_record ?></strong>–<strong><?= $to_record ?></strong>
                        dari <strong><?= $total_data ?></strong> data
                        <?php if ($total_pages > 1): ?>
                            &nbsp;·&nbsp; Halaman <strong><?= $page ?></strong> / <strong><?= $total_pages ?></strong>
                        <?php endif; ?>
                    </div>

                    <!-- Tombol halaman (hanya tampil jika > 1 halaman) -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-pages">

                        <!-- Prev -->
                        <?php if ($page > 1): ?>
                            <a href="<?= buildPageUrl($page - 1) ?>" class="pg-btn" title="Halaman sebelumnya">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="pg-btn disabled"><i class="fas fa-chevron-left"></i></span>
                        <?php endif; ?>

                        <?php
                        // Smart ellipsis: selalu tampilkan halaman 1, terakhir,
                        // dan 2 di kiri-kanan halaman aktif
                        $range  = 2; // jumlah halaman di kiri & kanan aktif
                        $dots_l = false;
                        $dots_r = false;

                        for ($i = 1; $i <= $total_pages; $i++) {
                            $show = ($i == 1)
                                 || ($i == $total_pages)
                                 || ($i >= $page - $range && $i <= $page + $range);

                            if (!$show) {
                                if ($i < $page && !$dots_l) {
                                    echo '<span class="pg-btn ellipsis">…</span>';
                                    $dots_l = true;
                                } elseif ($i > $page && !$dots_r) {
                                    echo '<span class="pg-btn ellipsis">…</span>';
                                    $dots_r = true;
                                }
                                continue;
                            }

                            if ($i == $page) {
                                echo "<span class='pg-btn active'>$i</span>";
                            } else {
                                echo "<a href='" . buildPageUrl($i) . "' class='pg-btn'>$i</a>";
                            }
                        }
                        ?>

                        <!-- Next -->
                        <?php if ($page < $total_pages): ?>
                            <a href="<?= buildPageUrl($page + 1) ?>" class="pg-btn" title="Halaman berikutnya">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="pg-btn disabled"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>

                    </div>
                    <?php endif; ?>

                </div>
                <?php endif; ?>
                <!-- ===== END PAGINATION ===== -->

            </div>
        </div>

    </div><!-- /content-section -->
</div><!-- /main-content -->

<script>
// ===== Custom Select Kendaraan =====
const searchInput   = document.getElementById('search_kendaraan_input');
const dropdown      = document.getElementById('kendaraan_dropdown');
const hiddenInput   = document.getElementById('id_kendaraan');
const wrapper       = searchInput?.parentElement;
let dropdownItems   = [];
let currentIndex    = -1;

if (searchInput && dropdown) {
    dropdownItems = Array.from(dropdown.querySelectorAll('.dropdown-item'));

    <?php if ($edit_data): ?>
    const activeItem = dropdown.querySelector('.dropdown-item.active');
    if (activeItem) {
        searchInput.value = activeItem.getAttribute('data-text') || activeItem.textContent.trim();
        hiddenInput.value = activeItem.getAttribute('data-value');
    }
    <?php endif; ?>

    searchInput.addEventListener('focus', () => showDropdown());
    searchInput.addEventListener('click', (e) => { e.stopPropagation(); showDropdown(); });
    searchInput.addEventListener('input', function() {
        filterDropdown(this.value.toLowerCase().trim());
        hiddenInput.value = '';
        showDropdown();
    });

    dropdownItems.forEach(item => {
        item.addEventListener('click',     (e) => { e.preventDefault(); e.stopPropagation(); selectItem(item); });
        item.addEventListener('mousedown', (e) => e.preventDefault());
    });

    document.addEventListener('click', (e) => { if (!wrapper.contains(e.target)) hideDropdown(); });
    dropdown.addEventListener('click', (e) => e.stopPropagation());

    searchInput.addEventListener('keydown', function(e) {
        const visible = dropdownItems.filter(i => !i.classList.contains('hidden'));
        if (e.key === 'ArrowDown')  { e.preventDefault(); if (!dropdown.classList.contains('show')) showDropdown(); currentIndex = Math.min(currentIndex+1, visible.length-1); highlightItem(visible, currentIndex); }
        else if (e.key === 'ArrowUp')  { e.preventDefault(); currentIndex = Math.max(currentIndex-1, 0); highlightItem(visible, currentIndex); }
        else if (e.key === 'Enter')    { if (currentIndex >= 0 && dropdown.classList.contains('show')) { e.preventDefault(); selectItem(visible[currentIndex]); } }
        else if (e.key === 'Escape')   hideDropdown();
    });
}

function showDropdown()  { dropdown.classList.add('show');    wrapper.classList.add('active');    filterDropdown(searchInput.value.toLowerCase().trim()); }
function hideDropdown()  { dropdown.classList.remove('show'); wrapper.classList.remove('active'); currentIndex = -1; dropdownItems.forEach(i => { if (!i.classList.contains('active')) i.style.background=''; }); }

function selectItem(item) {
    const val  = item.getAttribute('data-value');
    const text = item.getAttribute('data-text') || item.textContent.trim();
    if (val !== '') {
        searchInput.value = text; hiddenInput.value = val;
        dropdownItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
    } else {
        searchInput.value = ''; hiddenInput.value = '';
        dropdownItems.forEach(i => i.classList.remove('active'));
    }
    hideDropdown();
}

function highlightItem(items, index) {
    items.forEach((item, i) => {
        if (i === index) { item.style.background='#e8f4f8'; item.scrollIntoView({block:'nearest',behavior:'smooth'}); }
        else if (!item.classList.contains('active')) item.style.background='';
    });
}

function filterDropdown(val) {
    let hasVisible = false; currentIndex = -1;
    dropdownItems.forEach(item => {
        const text  = (item.getAttribute('data-text') || item.textContent).toLowerCase().trim();
        const value = item.getAttribute('data-value');
        if (value === '') { item.classList.toggle('hidden', val !== ''); return; }
        if (text.includes(val)) { item.classList.remove('hidden'); hasVisible = true; }
        else item.classList.add('hidden');
        if (!item.classList.contains('active')) item.style.background = '';
    });
    let emptyMsg = dropdown.querySelector('.dropdown-empty');
    if (!hasVisible && val) {
        if (!emptyMsg) {
            emptyMsg = document.createElement('div');
            emptyMsg.className = 'dropdown-empty';
            emptyMsg.innerHTML = '<i class="fas fa-search"></i> Tidak ada kendaraan ditemukan';
            dropdown.appendChild(emptyMsg);
        }
        emptyMsg.classList.remove('hidden');
    } else if (emptyMsg) emptyMsg.classList.add('hidden');
}

// ===== Search / Filter Tabel =====
// Reset ke page=1 saat filter berubah
let searchTimeout;
['filter_status','filter_bidang','filter_jenis','search_nomor','search_nopol','search_tanggal'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => { clearTimeout(searchTimeout); searchTimeout = setTimeout(performSearch, 500); });
    if (['filter_status','filter_bidang','filter_jenis'].includes(id)) el.addEventListener('change', performSearch);
});

function performSearch() {
    const p = new URLSearchParams();
    const fs = document.getElementById('filter_status').value;
    const fb = document.getElementById('filter_bidang').value;
    const fj = document.getElementById('filter_jenis').value;
    const sn = document.getElementById('search_nomor').value;
    const sp = document.getElementById('search_nopol').value;
    const st = document.getElementById('search_tanggal').value;
    // pertahankan filter dashboard
    const db = new URLSearchParams(window.location.search);
    if (db.get('dash_bulan')) p.append('dash_bulan', db.get('dash_bulan'));
    if (db.get('dash_tahun')) p.append('dash_tahun', db.get('dash_tahun'));
    if (fs) p.append('filter_status', fs);
    if (fb) p.append('filter_bidang', fb);
    if (fj) p.append('filter_jenis', fj);
    if (sn) p.append('search_nomor', sn);
    if (sp) p.append('search_nopol', sp);
    if (st) p.append('search_tanggal', st);
    // page sengaja tidak disertakan → otomatis kembali ke halaman 1
    window.location.href = '?' + p.toString();
}
</script>

</body>
</html>
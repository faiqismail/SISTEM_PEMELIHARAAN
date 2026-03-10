<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
requireAuth('admin');

/* =====================
   FUNGSI AUTO PILIH FOTO DARI FOLDER
===================== */
function getRandomPhotoFromFolder() {
    $folder = '../fotodata/';
    if (!is_dir($folder)) { mkdir($folder, 0777, true); return null; }
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $files = [];
    if ($handle = opendir($folder)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) $files[] = $file;
            }
        }
        closedir($handle);
    }
    return count($files) > 0 ? $files[array_rand($files)] : null;
}

/* =====================
   DAFTAR ROLE SISTEM
===================== */
$available_roles = [
    'admin'               => ['label' => 'Admin',               'badge' => 'badge-admin'],
    'pengawas'            => ['label' => 'Pengawas',            'badge' => 'badge-driver'],
    'angkutan_dalam'      => ['label' => 'Angkutan Dalam',      'badge' => 'badge-angkutan-dalam'],
    'angkutan_luar'       => ['label' => 'Angkutan Luar',       'badge' => 'badge-angkutan-luar'],
    'alat_berat_wilayah_1'=> ['label' => 'Alat Berat Wilayah 1','badge' => 'badge-alat-berat-1'],
    'alat_berat_wilayah_2'=> ['label' => 'Alat Berat Wilayah 2','badge' => 'badge-alat-berat-2'],
    'alat_berat_wilayah_3'=> ['label' => 'Alat Berat Wilayah 3','badge' => 'badge-alat-berat-3'],
    'pergudangan'         => ['label' => 'Pergudangan',         'badge' => 'badge-gudang'],
];

/* =====================
   FUNGSI CEK RELASI USER
===================== */
function cekRelasiUser($connection, $id_user) {
    $relasi = [];
    $check = mysqli_query($connection, "SELECT COUNT(*) AS total FROM permintaan_perbaikan WHERE admin_sa = '$id_user'");
    $result = mysqli_fetch_assoc($check);
    if ($result && $result['total'] > 0) $relasi[] = "Permintaan Perbaikan sebagai Admin SA ({$result['total']})";
    $check2 = mysqli_query($connection, "SELECT COUNT(*) AS total FROM permintaan_perbaikan WHERE id_pengaju = '$id_user'");
    $result2 = mysqli_fetch_assoc($check2);
    if ($result2 && $result2['total'] > 0) $relasi[] = "Permintaan Perbaikan sebagai Pengaju/Driver ({$result2['total']})";
    return $relasi;
}

/* =====================
   SIMPAN USER
===================== */
if (isset($_POST['simpan'])) {
    $username = mysqli_real_escape_string($connection, trim($_POST['username']));
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role     = mysqli_real_escape_string($connection, $_POST['role']);

    $cek = mysqli_query($connection, "SELECT id_user FROM users WHERE username='$username' LIMIT 1");
    if (mysqli_num_rows($cek) > 0) {
        echo "<script>alert('❌ Username \"$username\" sudah digunakan!'); window.history.back();</script>"; exit;
    }

    $ttd = null;
    $randomPhoto = getRandomPhotoFromFolder();
    if ($randomPhoto) {
        $ext = pathinfo($randomPhoto, PATHINFO_EXTENSION);
        $ttd = 'ttd_' . time() . '_' . uniqid() . '.' . $ext;
        copy('../fotodata/' . $randomPhoto, '../uploads/ttd/' . $ttd);
    }

    mysqli_query($connection, "INSERT INTO users (username, password, role, status, ttd, created_at) VALUES ('$username', '$password', '$role', 'Aktif', '$ttd', NOW())");
    echo "<script>alert('✅ User berhasil ditambahkan dengan QR Code otomatis dan status Aktif!'); window.location.href='users.php';</script>"; exit;
}

/* =====================
   UPDATE USER
===================== */
if (isset($_POST['update'])) {
    $id_user  = intval($_POST['id_user']);
    $username = mysqli_real_escape_string($connection, trim($_POST['username']));
    $role     = mysqli_real_escape_string($connection, $_POST['role']);
    $status   = mysqli_real_escape_string($connection, $_POST['status']);

    $cek = mysqli_query($connection, "SELECT id_user FROM users WHERE username='$username' AND id_user != '$id_user' LIMIT 1");
    if (mysqli_num_rows($cek) > 0) {
        echo "<script>alert('❌ Username \"$username\" sudah digunakan user lain!'); window.history.back();</script>"; exit;
    }

    $data = mysqli_fetch_assoc(mysqli_query($connection, "SELECT ttd FROM users WHERE id_user='$id_user'"));
    $ttd  = $data['ttd'];

    $relationInfo = cekRelasiUser($connection, $id_user);
    $hasRelation  = !empty($relationInfo);

    $ttd_sql = "";
    if (!$hasRelation && isset($_POST['refresh_foto'])) {
        if ($ttd && file_exists('../uploads/ttd/' . $ttd)) unlink('../uploads/ttd/' . $ttd);
        $randomPhoto = getRandomPhotoFromFolder();
        if ($randomPhoto) {
            $ext = pathinfo($randomPhoto, PATHINFO_EXTENSION);
            $ttd = 'ttd_' . time() . '_' . uniqid() . '.' . $ext;
            copy('../fotodata/' . $randomPhoto, '../uploads/ttd/' . $ttd);
            $ttd_sql = ", ttd='$ttd'";
        }
    }

    $password_update = "";
    if (!empty($_POST['password'])) {
        $new_password    = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $password_update = ", password='$new_password'";
    }

    mysqli_query($connection, "UPDATE users SET username='$username', role='$role', status='$status' $ttd_sql $password_update WHERE id_user='$id_user'");
    echo "<script>alert('✅ Data user berhasil diperbarui!'); window.location.href='users.php';</script>"; exit;
}

/* =====================
   HAPUS USER (CEK RELASI)
===================== */
if (isset($_GET['hapus'])) {
    $id = mysqli_real_escape_string($connection, $_GET['hapus']);
    $relationInfo = cekRelasiUser($connection, $id);
    if (!empty($relationInfo)) {
        $relationText = implode(', ', $relationInfo);
        echo "<script>alert('❌ User tidak dapat dihapus karena masih memiliki data terkait:\\n\\n" . addslashes($relationText) . "\\n\\nSilakan hapus data terkait terlebih dahulu atau ubah status user menjadi \"Tidak Aktif\".'); window.location.href='users.php';</script>"; exit;
    }
    $data = mysqli_fetch_assoc(mysqli_query($connection, "SELECT ttd FROM users WHERE id_user='$id'"));
    if (!empty($data['ttd']) && file_exists('../uploads/ttd/'.$data['ttd'])) unlink('../uploads/ttd/'.$data['ttd']);
    mysqli_query($connection, "DELETE FROM users WHERE id_user='$id'");
    echo "<script>alert('✅ User berhasil dihapus!'); window.location.href='users.php';</script>"; exit;
}

/* =====================
   MODE EDIT
===================== */
$edit = false;
$hasRelation  = false;
$relationInfo = [];
if (isset($_GET['edit'])) {
    $edit = true;
    $id   = mysqli_real_escape_string($connection, $_GET['edit']);
    $e    = mysqli_fetch_assoc(mysqli_query($connection, "SELECT * FROM users WHERE id_user='$id'"));
    $relationInfo = cekRelasiUser($connection, $id);
    $hasRelation  = !empty($relationInfo);
}

/* =====================
   STATISTIK & PAGINATION
===================== */
$photoCount = 0;
if (is_dir('../fotodata/')) {
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if ($handle = opendir('../fotodata/')) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) $photoCount++;
            }
        }
        closedir($handle);
    }
}

$stats_query = mysqli_query($connection, "SELECT status, role, COUNT(*) as total FROM users GROUP BY status, role");
$stats = ['total' => 0, 'aktif' => 0, 'tidak_aktif' => 0, 'by_role' => []];
while ($row = mysqli_fetch_assoc($stats_query)) {
    $stats['total']++;
    if ($row['status'] == 'Aktif') $stats['aktif'] += $row['total'];
    else $stats['tidak_aktif'] += $row['total'];
    if (!isset($stats['by_role'][$row['role']])) $stats['by_role'][$row['role']] = ['aktif' => 0, 'tidak_aktif' => 0];
    if ($row['status'] == 'Aktif') $stats['by_role'][$row['role']]['aktif'] = $row['total'];
    else $stats['by_role'][$row['role']]['tidak_aktif'] = $row['total'];
}

$total_users   = mysqli_fetch_assoc(mysqli_query($connection, "SELECT COUNT(*) AS total FROM users"))['total'];
$user_per_page = 20;
$user_page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$user_offset   = ($user_page - 1) * $user_per_page;
$user_total_pages = $total_users > 0 ? (int)ceil($total_users / $user_per_page) : 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen User</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { overflow-x: hidden; width: 100%; max-width: 100vw; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: rgb(185, 224, 204);
            min-height: 100vh;
            display: flex;
        }
        .app-wrapper { display: flex; width: 100%; min-height: 100vh; overflow-x: hidden; }
        .main-content { flex: 1; padding: 20px; margin-left: 0; transition: margin-left 0.3s ease; width: 100%; max-width: 100%; overflow-x: hidden; }
        @media (max-width: 1023px) {
            .main-content { margin-left: 0; padding: 10px; padding-top: 70px; }
        }
        .container-custom { max-width: 1600px; margin: 0 auto; width: 100%; }
        .page-header { background: white; padding: 25px 30px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); margin-bottom: 25px; }
        .page-header h1 { font-size: 28px; font-weight: 700; color: #2c3e50; margin: 0; display: flex; align-items: center; gap: 15px; }
        .page-header h1 i { color: #667eea; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 15px; transition: transform 0.3s ease; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .stat-icon.total      { background: linear-gradient(135deg,#667eea,#764ba2); color: white; }
        .stat-icon.aktif      { background: linear-gradient(135deg,#56ab2f,#a8e063); color: white; }
        .stat-icon.tidak-aktif{ background: linear-gradient(135deg,#eb3349,#f45c43); color: white; }
        .stat-content h3 { font-size: 28px; font-weight: 700; color: #2c3e50; margin: 0; }
        .stat-content p  { font-size: 13px; color: #7f8c8d; margin: 0; }
        .filter-section { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .filter-title { font-size: 16px; font-weight: 600; color: #2c3e50; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 13px; font-weight: 600; color: #2c3e50; }
        .filter-select { padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 13px; transition: all 0.3s; background: #f8f9fa; width: 100%; }
        .filter-select:focus { outline: none; border-color: #667eea; background: white; }
        .filter-buttons { display: flex; gap: 10px; align-items: flex-end; }
        .btn-filter { padding: 10px 20px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 6px; }
        .btn-apply { background: rgb(9,120,83); color: white; }
        .btn-apply:hover { background: rgb(7,100,70); }
        .btn-reset { background: #e0e0e0; color: #555; }
        .btn-reset:hover { background: #d0d0d0; }
        .two-column-layout { display: grid; grid-template-columns: 450px 1fr; gap: 20px; align-items: start; }
        @media (max-width: 1200px) { .two-column-layout { grid-template-columns: 1fr; } }
        .card-custom { background: white; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); padding: 25px; animation: slideUp 0.5s ease-out; width: 100%; max-width: 100%; }
        .form-card { position: sticky; top: 20px; max-height: calc(100vh - 40px); overflow-y: auto; }
        .form-card::-webkit-scrollbar { width: 8px; }
        .form-card::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .form-card::-webkit-scrollbar-thumb { background: #667eea; border-radius: 10px; }
        .data-card { display: flex; flex-direction: column; max-height: calc(100vh - 40px); }
        .card-header-fixed { flex-shrink: 0; padding-bottom: 20px; border-bottom: 2px solid #f0f0f0; margin-bottom: 20px; }
        .table-scroll-container { flex: 1; overflow-y: auto; overflow-x: auto; width: 100%; }
        .table-scroll-container::-webkit-scrollbar { width: 8px; height: 8px; }
        .table-scroll-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .table-scroll-container::-webkit-scrollbar-thumb { background: #667eea; border-radius: 10px; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .card-title { font-size: 20px; font-weight: 600; color: #2c3e50; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .card-title i { color: #667eea; }
        .form-group { margin-bottom: 18px; }
        .form-label { font-weight: 600; color: #2c3e50; margin-bottom: 8px; display: block; font-size: 13px; }
        .form-control, .form-select { width: 100%; padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; transition: all 0.3s ease; background: #f8f9fa; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #667eea; background: white; box-shadow: 0 0 0 4px rgba(102,126,234,0.1); }
        .form-control:disabled, .form-select:disabled { background: #e9ecef; cursor: not-allowed; opacity: 0.6; }
        .password-toggle { position: relative; }
        .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #999; transition: all 0.3s ease; }
        .toggle-password:hover { color: #667eea; }
        .info-text { font-size: 11px; color: #6c757d; margin-top: 5px; font-style: italic; }
        .alert-warning { background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px; padding: 15px; margin-bottom: 20px; display: flex; gap: 12px; align-items: flex-start; }
        .alert-warning i { color: #856404; font-size: 20px; flex-shrink: 0; margin-top: 2px; }
        .alert-content { flex: 1; }
        .alert-title { font-weight: 700; color: #856404; margin-bottom: 8px; font-size: 14px; }
        .alert-message { color: #856404; font-size: 12px; line-height: 1.6; }
        .relation-list { margin-top: 8px; padding-left: 20px; }
        .relation-list li { color: #856404; font-size: 12px; margin-bottom: 4px; }
        .field-locked { background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px; padding: 12px; margin-top: 10px; display: flex; align-items: center; gap: 10px; }
        .field-locked i { color: #856404; font-size: 18px; }
        .field-locked-text { color: #856404; font-size: 12px; font-weight: 600; }
        .info-badge { background: linear-gradient(135deg,#667eea,#764ba2); color: white; padding: 8px 16px; border-radius: 20px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 15px; box-shadow: 0 4px 15px rgba(102,126,234,0.3); }
        .preview-ttd { margin-top: 12px; padding: 12px; background: #f8f9fa; border-radius: 10px; display: inline-flex; align-items: center; gap: 10px; }
        .preview-ttd img { height: 50px; max-width: 150px; object-fit: contain; border-radius: 8px; background: white; padding: 5px; }
        .form-check { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .form-check-input { width: 18px; height: 18px; cursor: pointer; flex-shrink: 0; }
        .form-check label { cursor: pointer; font-size: 0.9rem; margin: 0; }
        .alert-info { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 12px; border-radius: 8px; margin-top: 10px; }
        .alert-info i { color: #2196F3; }
        .alert-info span { font-size: 0.9rem; color: #333; margin-left: 8px; }
        .btn-custom { padding: 10px 25px; border-radius: 10px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; justify-content: center; }
        .btn-primary-custom { background: rgb(9,120,83); color: white; box-shadow: 0 4px 15px rgba(9,120,83,0.4); width: 100%; }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(9,120,83,0.5); }
        .btn-secondary-custom { background: #6c757d; color: white; width: 100%; }
        .btn-secondary-custom:hover { background: #5a6268; transform: translateY(-2px); }
        .search-wrapper { position: relative; margin-bottom: 0; }
        .search-input { width: 100%; padding: 10px 40px 10px 40px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; transition: all 0.3s ease; background: #f8f9fa; }
        .search-input:focus { outline: none; border-color: #667eea; background: white; box-shadow: 0 0 0 4px rgba(102,126,234,0.1); }
        .search-icon  { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #999; }
        .clear-search { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #999; cursor: pointer; display: none; }
        .clear-search:hover { color: #667eea; }
        table { width: 100%; border-collapse: collapse; background: white; min-width: 900px; }
        thead { background: rgb(9,120,83); color: white; position: sticky; top: 0; z-index: 10; }
        thead th { padding: 15px; text-align: left; font-weight: 600; font-size: 13px; white-space: nowrap; }
        tbody tr { border-bottom: 1px solid #f0f0f0; transition: all 0.3s ease; }
        tbody tr:hover { background: #f8f9fa; }
        tbody td { padding: 15px; font-size: 13px; color: #555; }
        .badge-role { display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: capitalize; white-space: nowrap; }
        .badge-admin          { background: linear-gradient(135deg,#e3f2fd,#bbdefb); color: #1976d2; border: 1px solid #90caf9; }
        .badge-driver         { background: linear-gradient(135deg,#fff3cd,#ffe5a1); color: #856404; border: 1px solid #ffd966; }
        .badge-angkutan-dalam { background: linear-gradient(135deg,#e8f5e9,#c8e6c9); color: #2e7d32; border: 1px solid #81c784; }
        .badge-angkutan-luar  { background: linear-gradient(135deg,#f3e5f5,#e1bee7); color: #7b1fa2; border: 1px solid #ce93d8; }
        .badge-alat-berat-1   { background: linear-gradient(135deg,#fff3e0,#ffe0b2); color: #e65100; border: 1px solid #ffb74d; }
        .badge-alat-berat-2   { background: linear-gradient(135deg,#fce4ec,#f8bbd0); color: #c2185b; border: 1px solid #f06292; }
        .badge-alat-berat-3   { background: linear-gradient(135deg,#e0f2f1,#b2dfdb); color: #00695c; border: 1px solid #4db6ac; }
        .badge-gudang         { background: linear-gradient(135deg,#ede7f6,#d1c4e9); color: #512da8; border: 1px solid #9575cd; }
        .badge-status { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-status.aktif       { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .badge-status.tidak-aktif { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .ttd-container { display: flex; justify-content: center; align-items: center; min-height: 50px; }
        .ttd-image { height: 45px; max-width: 120px; object-fit: contain; padding: 5px; cursor: pointer; transition: all 0.3s ease; }
        .ttd-image:hover { transform: scale(1.1); }
        .no-ttd { color: #999; font-style: italic; font-size: 12px; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
        .btn-action { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-edit   { background: #fff3cd; color: #856404; }
        .btn-edit:hover   { background: #ffc107; color: white; transform: translateY(-2px); }
        .btn-delete { background: #f8d7da; color: #721c24; }
        .btn-delete:hover { background: #dc3545; color: white; transform: translateY(-2px); }
        .no-data { text-align: center; padding: 40px; color: #999; font-style: italic; }
        .no-data i { font-size: 48px; margin-bottom: 15px; opacity: 0.3; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; }
        .modal-content { position: relative; max-width: 90%; max-height: 90%; }
        .modal-content img { max-width: 100%; max-height: 90vh; border-radius: 10px; }
        .modal-close { position: absolute; top: -40px; right: 0; color: white; font-size: 30px; cursor: pointer; background: rgba(255,255,255,0.2); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; }
        .modal-close:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }

        /* ===================================
           MOBILE RESPONSIVE
        =================================== */
        @media (max-width: 1023px) {
            html, body { overflow-x: hidden !important; width: 100% !important; max-width: 100vw !important; }
            .app-wrapper { overflow-x: hidden !important; max-width: 100vw !important; }
            .main-content { padding: 10px !important; padding-top: 70px !important; width: 100% !important; max-width: 100vw !important; overflow-x: hidden !important; }
            .container-custom { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            .page-header { padding: 15px !important; margin-bottom: 15px !important; }
            .page-header h1 { font-size: 18px !important; gap: 8px !important; }
            .stats-grid { grid-template-columns: 1fr !important; gap: 10px !important; }
            .stat-card { padding: 15px !important; }
            .stat-icon { width: 45px !important; height: 45px !important; font-size: 18px !important; }
            .stat-content h3 { font-size: 22px !important; }
            .stat-content p  { font-size: 12px !important; }
            .filter-section { padding: 15px !important; margin-bottom: 15px !important; }
            .filter-title { font-size: 14px !important; margin-bottom: 12px !important; }
            .filter-grid { grid-template-columns: 1fr !important; gap: 10px !important; }
            .filter-buttons { flex-direction: column !important; width: 100% !important; gap: 8px !important; }
            .btn-filter { width: 100% !important; justify-content: center; padding: 10px 15px !important; }
            .two-column-layout { grid-template-columns: 1fr !important; gap: 15px !important; }
            .form-card { position: relative !important; top: 0 !important; max-height: none !important; padding: 15px !important; }
            .data-card { max-height: none !important; padding: 15px !important; }
            .card-title { font-size: 16px !important; margin-bottom: 15px !important; }
            .form-group { margin-bottom: 15px !important; }
            .form-label { font-size: 12px !important; }
            .form-control, .form-select { font-size: 14px !important; padding: 10px !important; }
            .info-badge { font-size: 12px !important; padding: 6px 12px !important; margin-bottom: 12px !important; }
            .alert-warning { padding: 12px !important; gap: 10px !important; }
            .alert-title { font-size: 13px !important; }
            .alert-message { font-size: 11px !important; }
            .preview-ttd { flex-direction: column !important; align-items: flex-start !important; padding: 10px !important; }
            .form-check label { font-size: 13px !important; }
            .btn-custom { padding: 12px 20px !important; font-size: 14px !important; }
            .table-scroll-container { overflow-x: hidden !important; width: 100% !important; max-width: 100% !important; }
            table { display: block !important; min-width: auto !important; width: 100% !important; }
            thead { display: none !important; }
            tbody { display: block !important; width: 100% !important; }
            tbody tr { display: block !important; margin-bottom: 12px !important; border: 1px solid #e0e0e0 !important; border-radius: 10px !important; padding: 12px !important; width: 100% !important; }
            tbody td { display: block !important; width: 100% !important; text-align: left !important; padding: 8px 0 !important; border: none !important; }
            tbody td::before { content: attr(data-label); font-weight: 700; color: #667eea; display: block; margin-bottom: 5px; font-size: 11px; text-transform: uppercase; }
            .action-buttons { justify-content: flex-start !important; gap: 6px !important; }
            .btn-action { font-size: 11px !important; padding: 6px 10px !important; }
            .ttd-container { justify-content: flex-start !important; }
            .ttd-image { height: 40px !important; }
        }

        @media (max-width: 480px) {
            .page-header h1 { font-size: 16px !important; }
            .card-title { font-size: 14px !important; }
            .stat-content h3 { font-size: 20px !important; }
            .form-control, .form-select { font-size: 13px !important; }
            .btn-custom { font-size: 13px !important; padding: 10px 16px !important; }
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include "navbar.php"; ?>

    <div class="main-content">
        <div class="container-custom">

            <!-- Page Header -->
            <div class="page-header">
                <h1>
                    <i class="fas fa-users-cog"></i>
                    Manajemen Pengguna
                </h1>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total"><i class="fas fa-users"></i></div>
                    <div class="stat-content"><h3><?= $total_users ?></h3><p>Total Pengguna</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon aktif"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content"><h3><?= $stats['aktif'] ?></h3><p>Pengguna Aktif</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon tidak-aktif"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-content"><h3><?= $stats['tidak_aktif'] ?></h3><p>Pengguna Tidak Aktif</p></div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-title"><i class="fas fa-filter"></i> Filter Data Pengguna</div>
                <div class="filter-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-toggle-on"></i> Status</label>
                        <select id="filterStatus" class="filter-select">
                            <option value="">Semua Status</option>
                            <option value="Aktif">Aktif (<?= $stats['aktif'] ?>)</option>
                            <option value="Tidak Aktif">Tidak Aktif (<?= $stats['tidak_aktif'] ?>)</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-user-tag"></i> Role / Wilayah</label>
                        <select id="filterRole" class="filter-select">
                            <option value="">Semua Role</option>
                            <?php foreach ($available_roles as $role_key => $role_data):
                                $total_role = isset($stats['by_role'][$role_key])
                                    ? ($stats['by_role'][$role_key]['aktif'] + $stats['by_role'][$role_key]['tidak_aktif']) : 0;
                            ?>
                            <option value="<?= $role_key ?>"><?= $role_data['label'] ?> (<?= $total_role ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-buttons">
                        <button class="btn-filter btn-apply" onclick="applyFilter()">
                            <i class="fas fa-check"></i> Terapkan Filter
                        </button>
                        <button class="btn-filter btn-reset" onclick="resetFilter()">
                            <i class="fas fa-redo"></i> Reset Filter
                        </button>
                    </div>
                </div>
            </div>

            <div class="two-column-layout">

                <!-- FORM -->
                <div class="card-custom form-card">
                    <h2 class="card-title">
                        <i class="<?= $edit ? 'fas fa-edit' : 'fas fa-user-plus' ?>"></i>
                        <?= $edit ? 'Edit Pengguna' : 'Tambah Pengguna' ?>
                    </h2>

                    <?php if ($edit && $hasRelation): ?>
                    <div class="alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div class="alert-content">
                            <div class="alert-title">⚠️ User Memiliki Data Terkait</div>
                            <div class="alert-message">
                                Pengguna ini memiliki relasi dengan data lain. <strong>Role dan QR Code</strong> dinonaktifkan untuk mencegah perubahan yang dapat mempengaruhi dokumen yang sudah dibuat.
                                <ul class="relation-list">
                                    <?php foreach ($relationInfo as $info): ?>
                                    <li><i class="fas fa-link"></i> <?= htmlspecialchars($info) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST">
                        <?php if ($edit): ?>
                        <input type="hidden" name="id_user" value="<?= $e['id_user'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-user"></i> Username</label>
                            <input type="text" name="username" class="form-control"
                                   placeholder="Masukkan username"
                                   value="<?= $edit ? htmlspecialchars($e['username']) : '' ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-lock"></i> Password <?= $edit ? '(opsional)' : '' ?>
                            </label>
                            <div class="password-toggle">
                                <input type="password" name="password" id="passwordInput" class="form-control"
                                       placeholder="<?= $edit ? 'Kosongkan jika tidak diubah' : 'Masukkan password' ?>"
                                       <?= $edit ? '' : 'required' ?>>
                                <i class="fas fa-eye toggle-password" id="togglePassword"></i>
                            </div>
                            <?php if ($edit): ?>
                            <small class="info-text"><i class="fas fa-info-circle"></i> Kosongkan jika tidak ingin mengubah password</small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user-tag"></i> Sebagai
                                <?php if ($edit && $hasRelation): ?><span style="color:#dc3545;font-size:11px;">(Dikunci)</span><?php endif; ?>
                            </label>
                            <?php if ($edit && $hasRelation): ?>
                            <input type="hidden" name="role" value="<?= $e['role'] ?>">
                            <select class="form-select" disabled>
                                <option value="">Pilih Role</option>
                                <?php foreach ($available_roles as $role_key => $role_data): ?>
                                <option value="<?= $role_key ?>" <?= ($e['role'] == $role_key) ? 'selected' : '' ?>><?= $role_data['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="info-text" style="color:#dc3545;"><i class="fas fa-lock"></i> Role tidak dapat diubah karena user memiliki data terkait</small>
                            <?php else: ?>
                            <select name="role" class="form-select" required>
                                <option value="">Pilih Role</option>
                                <?php foreach ($available_roles as $role_key => $role_data): ?>
                                <option value="<?= $role_key ?>" <?= ($edit && $e['role'] == $role_key) ? 'selected' : '' ?>><?= $role_data['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>

                        <?php if ($edit): ?>
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-toggle-on"></i> Status Akun</label>
                            <select name="status" class="form-select" required>
                                <option value="Aktif"       <?= (isset($e['status']) && $e['status'] == 'Aktif')       ? 'selected' : '' ?>>✓ Aktif - User dapat login</option>
                                <option value="Tidak Aktif" <?= (isset($e['status']) && $e['status'] == 'Tidak Aktif') ? 'selected' : '' ?>>✗ Tidak Aktif - User tidak dapat login</option>
                            </select>
                            <small class="info-text"><i class="fas fa-info-circle"></i> User dengan status "Tidak Aktif" tidak dapat mengakses sistem</small>
                        </div>
                        <?php else: ?>
                        <div class="alert-info" style="margin-bottom:18px;">
                            <i class="fas fa-check-circle"></i>
                            <span>Status user baru otomatis: <strong>Aktif</strong></span>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-qrcode"></i> QR CODE Otomatis
                                <?php if ($edit && $hasRelation): ?><span style="color:#dc3545;font-size:11px;">(Dikunci)</span><?php endif; ?>
                            </label>
                            <?php if (!$edit || !$hasRelation): ?>
                                <?php if ($edit && !empty($e['ttd'])): ?>
                                <div class="preview-ttd">
                                    <i class="fas fa-image" style="color:#667eea;"></i>
                                    <span style="font-size:12px;color:#666;">QR Code Saat ini:</span>
                                    <img src="../uploads/ttd/<?= $e['ttd'] ?>" alt="QR Code">
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="refresh_foto" id="refreshFoto" class="form-check-input" value="1">
                                    <label for="refreshFoto"><i class="fas fa-sync-alt"></i> Ganti dengan QR Code baru dari folder (Random)</label>
                                </div>
                                <?php else: ?>
                                <div class="alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    <span>QR Code akan dipilih secara otomatis dan random dari <?= $photoCount ?> foto yang tersedia di folder</span>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                            <div class="field-locked">
                                <i class="fas fa-lock"></i>
                                <div class="field-locked-text">QR Code dinonaktifkan karena user ini sudah memiliki data terkait. QR Code yang tersimpan tidak dapat diubah untuk menjaga integritas dokumen yang sudah dibuat.</div>
                            </div>
                            <?php if (!empty($e['ttd'])): ?>
                            <div style="margin-top:10px;padding:10px;background:#e3f2fd;border-radius:8px;font-size:12px;color:#1976d2;text-align:center;">
                                <i class="fas fa-check-circle"></i> QR Code sudah tersimpan di sistem dan digunakan pada dokumen
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top:25px;display:flex;flex-direction:column;gap:10px;">
                            <button type="submit" name="<?= $edit ? 'update' : 'simpan' ?>" class="btn-custom btn-primary-custom">
                                <i class="fas fa-save"></i>
                                <?= $edit ? 'Update Pengguna' : 'Simpan Pengguna' ?>
                            </button>
                            <?php if ($edit): ?>
                            <a href="users.php" class="btn-custom btn-secondary-custom">
                                <i class="fas fa-times"></i> Batal Edit
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- DATA TABLE -->
                <div class="card-custom data-card">
                    <div class="card-header-fixed">
                        <h2 class="card-title"><i class="fas fa-table"></i> Data Pengguna</h2>
                        <div class="search-wrapper">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="searchInput" class="search-input" placeholder="Cari username atau role...">
                            <i class="fas fa-times clear-search" id="clearSearch"></i>
                        </div>
                    </div>

                    <div class="table-scroll-container">
                        <table id="userTable">
                            <thead>
                                <tr>
                                    <th style="width:50px;">No</th>
                                    <th>USERNAME</th>
                                    <th style="width:200px;">SEBAGAI</th>
                                    <th style="width:100px;">STATUS</th>
                                    <th style="width:120px;text-align:center;">QR CODE</th>
                                    <th style="width:160px;text-align:center;">AKSI</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php
                                $no = $user_offset + 1;
                                $q  = mysqli_query($connection, "SELECT * FROM users ORDER BY id_user DESC LIMIT $user_per_page OFFSET $user_offset");
                                if (mysqli_num_rows($q) > 0) {
                                    while ($u = mysqli_fetch_assoc($q)) {
                                        $roleInfo   = isset($available_roles[$u['role']])
                                            ? $available_roles[$u['role']]
                                            : ['label' => ucfirst(str_replace('_', ' ', $u['role'])), 'badge' => 'badge-admin'];
                                        $userStatus = isset($u['status']) ? $u['status'] : 'Aktif';
                                ?>
                                <tr data-status="<?= $userStatus ?>" data-role="<?= $u['role'] ?>">
                                    <td style="text-align:center;font-weight:600;color:#667eea;" data-label="No"><?= $no++ ?></td>
                                    <td style="font-weight:600;color:#2c3e50;" data-label="Username"><?= htmlspecialchars($u['username']) ?></td>
                                    <td data-label="Sebagai">
                                        <span class="badge-role <?= $roleInfo['badge'] ?>"><?= $roleInfo['label'] ?></span>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge-status <?= strtolower(str_replace(' ', '-', $userStatus)) ?>"><?= $userStatus ?></span>
                                    </td>
                                    <td data-label="QR Code">
                                        <div class="ttd-container">
                                            <?php if (!empty($u['ttd'])): ?>
                                            <img src="../uploads/ttd/<?= $u['ttd'] ?>" alt="QR Code" class="ttd-image"
                                                 onclick="showModal('../uploads/ttd/<?= $u['ttd'] ?>')">
                                            <?php else: ?>
                                            <span class="no-ttd">Tidak ada</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Aksi">
                                        <div class="action-buttons">
                                            <a href="users.php?edit=<?= $u['id_user'] ?>" class="btn-action btn-edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="users.php?hapus=<?= $u['id_user'] ?>"
                                               class="btn-action btn-delete"
                                               onclick="return confirm('⚠️ KONFIRMASI HAPUS USER\n\nApakah Anda yakin ingin menghapus user:\n<?= htmlspecialchars($u['username']) ?>?\n\nPeringatan: Jika user ini memiliki data terkait, penghapusan akan dibatalkan.')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                                    }
                                } else {
                                ?>
                                <tr>
                                    <td colspan="6" class="no-data">
                                        <i class="fas fa-inbox"></i><br>Belum ada data user
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <!-- ===================== PAGINATION BARU ===================== -->
                        <?php if ($user_total_pages > 1): ?>
                        <?php
                        $window     = 2;
                        $page_start = max(2, $user_page - $window);
                        $page_end   = min($user_total_pages - 1, $user_page + $window);
                        $prev_p     = max(1, $user_page - 1);
                        $next_p     = min($user_total_pages, $user_page + 1);
                        ?>
                        <div class="mt-4 space-y-2" style="padding: 16px 0 8px;">

                            <!-- Info teks -->
                            <p class="text-sm text-gray-600">
                                Menampilkan
                                <span class="font-semibold"><?= $total_users > 0 ? ($user_offset + 1) : 0 ?> – <?= min($user_offset + $user_per_page, $total_users) ?></span>
                                dari <span class="font-semibold"><?= $total_users ?></span> pengguna
                            </p>

                            <!-- Tombol -->
                            <div class="flex flex-wrap items-center gap-1">

                                <!-- Prev -->
                                <a href="?page=<?= $prev_p ?>"
                                   class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                                          <?= $user_page <= 1
                                                ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                                    <i class="fas fa-chevron-left text-xs mr-1"></i> Prev
                                </a>

                                <!-- Halaman 1 -->
                                <a href="?page=1"
                                   class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                                          <?= $user_page === 1
                                                ? 'bg-green-700 text-white border-green-700'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                                    1
                                </a>

                                <!-- Ellipsis kiri -->
                                <?php if ($page_start > 2): ?>
                                <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                                <?php endif; ?>

                                <!-- Halaman tengah -->
                                <?php for ($i = $page_start; $i <= $page_end; $i++): ?>
                                <a href="?page=<?= $i ?>"
                                   class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                                          <?= $i === $user_page
                                                ? 'bg-green-700 text-white border-green-700'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                                    <?= $i ?>
                                </a>
                                <?php endfor; ?>

                                <!-- Ellipsis kanan -->
                                <?php if ($page_end < $user_total_pages - 1): ?>
                                <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                                <?php endif; ?>

                                <!-- Halaman terakhir -->
                                <?php if ($user_total_pages > 1): ?>
                                <a href="?page=<?= $user_total_pages ?>"
                                   class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                                          <?= $user_page === $user_total_pages
                                                ? 'bg-green-700 text-white border-green-700'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                                    <?= $user_total_pages ?>
                                </a>
                                <?php endif; ?>

                                <!-- Next -->
                                <a href="?page=<?= $next_p ?>"
                                   class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                                          <?= $user_page >= $user_total_pages
                                                ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                                : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                                    Next <i class="fas fa-chevron-right text-xs ml-1"></i>
                                </a>

                                <!-- Jump to page -->
                                <form method="GET" class="inline-flex items-center gap-1 ml-1"
                                      onsubmit="this.page.value = Math.max(1, Math.min(<?= $user_total_pages ?>, parseInt(this.page.value) || 1))">
                                    <span class="text-sm text-gray-500 whitespace-nowrap">Ke:</span>
                                    <input type="number" name="page"
                                           min="1" max="<?= $user_total_pages ?>"
                                           placeholder="<?= $user_page ?>"
                                           class="w-14 px-2 py-1.5 border border-gray-300 rounded-lg text-sm text-center focus:border-green-600 focus:outline-none">
                                    <button type="submit"
                                            class="px-3 py-1.5 text-white rounded-lg text-sm transition hover:opacity-90"
                                            style="background:rgb(9,120,83);">
                                        <i class="fas fa-arrow-right text-xs"></i>
                                    </button>
                                </form>

                            </div>
                        </div>
                        <?php endif; ?>
                        <!-- ===================== END PAGINATION ===================== -->

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Modal Preview QR Code -->
<div class="modal-overlay" id="modalOverlay" onclick="hideModal()">
    <div class="modal-content">
        <span class="modal-close" onclick="hideModal()">&times;</span>
        <img id="modalImage" src="" alt="QR Code Preview">
    </div>
</div>

<script>
    // Password Toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput  = document.getElementById('passwordInput');
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }

    // Search
    const searchInput = document.getElementById('searchInput');
    const clearSearch = document.getElementById('clearSearch');
    const tableBody   = document.getElementById('tableBody');
    const rows        = tableBody.getElementsByTagName('tr');

    searchInput.addEventListener('input', function() {
        const searchValue = this.value.toLowerCase();
        let visibleCount = 0;
        clearSearch.style.display = searchValue ? 'block' : 'none';

        for (let i = 0; i < rows.length; i++) {
            const username = rows[i].getElementsByTagName('td')[1];
            const role     = rows[i].getElementsByTagName('td')[2];
            if (username && role) {
                const match = username.textContent.toLowerCase().indexOf(searchValue) > -1
                           || role.textContent.toLowerCase().indexOf(searchValue) > -1;
                rows[i].style.display = match ? '' : 'none';
                if (match) visibleCount++;
            }
        }

        let noResultRow = document.getElementById('noResultRow');
        if (visibleCount === 0 && searchValue !== '') {
            if (!noResultRow) {
                noResultRow = document.createElement('tr');
                noResultRow.id = 'noResultRow';
                noResultRow.innerHTML = '<td colspan="6" class="no-data"><i class="fas fa-search"></i><br>Tidak ada hasil untuk "' + searchValue + '"</td>';
                tableBody.appendChild(noResultRow);
            }
        } else if (noResultRow) {
            noResultRow.remove();
        }
    });

    clearSearch.addEventListener('click', function() {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });

    // Filter
    function applyFilter() {
        const statusFilter = document.getElementById('filterStatus').value;
        const roleFilter   = document.getElementById('filterRole').value;
        let visibleCount   = 0;

        for (let row of rows) {
            const status  = row.getAttribute('data-status');
            const role    = row.getAttribute('data-role');
            let showRow   = true;
            if (statusFilter && status !== statusFilter) showRow = false;
            if (roleFilter   && role   !== roleFilter)   showRow = false;
            row.style.display = showRow ? '' : 'none';
            if (showRow) visibleCount++;
        }

        let noResultRow = document.getElementById('noResultRow');
        if (visibleCount === 0) {
            if (!noResultRow) {
                noResultRow = document.createElement('tr');
                noResultRow.id = 'noResultRow';
                noResultRow.innerHTML = '<td colspan="6" class="no-data"><i class="fas fa-filter"></i><br>Tidak ada data yang sesuai dengan filter</td>';
                tableBody.appendChild(noResultRow);
            }
        } else if (noResultRow) {
            noResultRow.remove();
        }
    }

    function resetFilter() {
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterRole').value   = '';
        document.getElementById('searchInput').value  = '';
        clearSearch.style.display = 'none';
        for (let row of rows) row.style.display = '';
        const noResultRow = document.getElementById('noResultRow');
        if (noResultRow) noResultRow.remove();
    }

    // Modal
    function showModal(imageSrc) {
        document.getElementById('modalOverlay').style.display = 'flex';
        document.getElementById('modalImage').src = imageSrc;
    }
    function hideModal() {
        document.getElementById('modalOverlay').style.display = 'none';
    }
    document.addEventListener('keydown', e => { if (e.key === 'Escape') hideModal(); });

    <?php if ($edit): ?>
    window.scrollTo({ top: 0, behavior: 'smooth' });
    <?php endif; ?>
</script>
</body>
</html>
<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
require_once "../inc/xlsx_helper.php";
require_once "../inc/csv_helper.php"; // <-- baru: untuk Template & Import (format CSV, anti-rusak saat di-save ulang Excel)
requireAuth('admin');

/* =====================
   FUNGSI AUTO PILIH FOTO DARI FOLDER
===================== */
function getRandomPhotoFromFolder() {
    $folder = '../fotodata/';

    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
        return null;
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $files = [];

    if ($handle = opendir($folder)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExt)) {
                    $files[] = $file;
                }
            }
        }
        closedir($handle);
    }

    if (count($files) > 0) {
        return $files[array_rand($files)];
    }

    return null;
}

/* =====================================================================
   FITUR: DOWNLOAD FORMAT / TEMPLATE (CSV) - PHP NATIVE, TANPA LIBRARY
   Harus diproses SEBELUM ada output HTML apapun.

   CATATAN PERBAIKAN PENTING:
   Sebelumnya template pakai trik "HTML disamarkan jadi .xls". Trik ini
   RUSAK begitu file dibuka lalu di-SAVE ULANG oleh Excel, karena Excel
   otomatis mengubahnya jadi struktur frameset multi-file (sheet001.htm,
   sheet002.htm, dst) — bukan lagi satu tabel HTML polos. Akibatnya saat
   diimport, yang terbaca malah potongan kode JavaScript pembangun tab
   Excel, sehingga data yang masuk jadi ngaco/rusak.

   SOLUSI: pakai format CSV murni (teks biasa dipisah koma/titik-koma).
   CSV TIDAK PERNAH berubah struktur walau berkali-kali dibuka & disimpan
   ulang oleh Excel, jadi jauh lebih aman untuk proses download -> edit
   di Excel -> upload lagi (roundtrip).
===================================================================== */
if (isset($_GET['template']) && $_GET['template'] == '1') {

    // CATATAN: nomor telepon contoh diberi awalan apostrof (').
    // Ini trik standar Excel: kalau sebuah sel di file CSV diawali
    // apostrof, Excel otomatis memperlakukan sel itu sebagai TEXT
    // (bukan angka) saat file dibuka -> angka 0 di depan nomor telepon
    // TIDAK hilang. Apostrofnya sendiri tidak akan tampil di Excel.
    $dataRows = [
        ['nama', 'alamat', 'telepon', 'status'],
        ['CV CONTOH JAYA ABADI', 'Jl. Contoh No. 123, Jombang', "'081234567890", 'Y'],
    ];

    native_csv_output($dataRows, 'template_import_rekanan.csv');
}

/* =====================================================================
   FITUR: EXPORT DATA (XLS) - SELALU SEMUA DATA (tidak dibatasi
   pagination), tapi tetap mengikuti filter status/pencarian yang aktif.
   Harus diproses SEBELUM ada output HTML apapun.
===================================================================== */
if (isset($_GET['export']) && $_GET['export'] == '1') {

    $exp_keyword = '';
    $exp_status  = isset($_GET['status']) ? $_GET['status'] : 'semua';
    $exp_where_conditions = [];

    if (isset($_GET['search']) && $_GET['search'] !== '') {
        $exp_keyword = mysqli_real_escape_string($connection, $_GET['search']);
        $exp_where_conditions[] = "(nama_rekanan LIKE '%$exp_keyword%' OR alamat LIKE '%$exp_keyword%' OR telp LIKE '%$exp_keyword%')";
    }
    if ($exp_status == 'aktif') {
        $exp_where_conditions[] = "aktif='Y'";
    } elseif ($exp_status == 'nonaktif') {
        $exp_where_conditions[] = "aktif='N'";
    }
    $exp_where = count($exp_where_conditions) > 0 ? "WHERE " . implode(' AND ', $exp_where_conditions) : "";

    // TIDAK PAKAI LIMIT/OFFSET -> semua data yang cocok filter ikut terekspor
    $exp_q = mysqli_query($connection, "SELECT * FROM rekanan $exp_where ORDER BY aktif DESC, id_rekanan DESC");

    $dataRows = [['No', 'Nama Rekanan', 'Alamat', 'Telepon', 'Status']];

    $exp_no = 1;
    if ($exp_q && mysqli_num_rows($exp_q) > 0) {
        while ($r = mysqli_fetch_assoc($exp_q)) {
            $dataRows[] = [
                $exp_no++,
               
                $r['nama_rekanan'],
                $r['alamat'],
                $r['telp'],
                $r['aktif'] == 'Y' ? 'Aktif' : 'Tidak Aktif',
                
            ];
        }
    }

    native_xlsx_output([
        ['name' => 'Data Rekanan', 'rows' => $dataRows, 'headerRows' => 1, 'widths' => [6, 8, 25, 35, 15, 12, 18]],
    ], 'export_rekanan_' . date('Ymd_His') . '.xls');
}

// Mulai output buffering untuk menghindari error header
ob_start();

include "navbar.php";

/* =====================
   HELPER: bangun query string filter yang sedang aktif
===================== */
function currentFilterQuery() {
    $params = [];
    if (isset($_GET['search']) && $_GET['search'] !== '') $params['search'] = $_GET['search'];
    if (isset($_GET['status']) && $_GET['status'] !== '') $params['status'] = $_GET['status'];
    if (isset($_GET['page']) && $_GET['page'] !== '')     $params['page']   = $_GET['page'];
    return http_build_query($params);
}

/* =====================================================================
   FITUR: IMPORT DATA (CSV) - PHP NATIVE, TANPA LIBRARY
   Format kolom WAJIB: nama | alamat | telepon | status

   CATATAN PERBAIKAN PENTING:
   Import sekarang HANYA menerima file .csv (bukan .xls/.xlsx lagi),
   karena format HTML-as-xls terbukti rusak begitu file di-save ulang
   oleh Excel. CSV jauh lebih tahan banting untuk proses roundtrip
   download -> edit -> upload.

   PRINSIP "GAGAL YA GAGAL, JANGAN DIPAKSA MASUK":
   - Kalau ekstensi file salah          -> STOP, tidak ada yang diproses.
   - Kalau file tidak bisa dibaca sama sekali -> STOP total.
   - Kalau header/struktur kolom TIDAK PERSIS "nama, alamat, telepon,
     status" -> STOP total, TIDAK ADA satu baris pun yang dimasukkan
     ke database. Ini mencegah kasus data ngaco masuk ke tabel seperti
     yang terjadi sebelumnya.
   - Baru setelah strukturnya valid, baris data diproses satu per satu;
     hanya baris yang nama-nya kosong yang dilewati (bukan seluruh file
     digagalkan), dan itu dilaporkan jelas ke user.
===================================================================== */
if (isset($_POST['import_submit'])) {

    if (!isset($_FILES['file_import']) || $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
        echo "<script>
            alert('⚠️ Tidak ada file yang diupload atau terjadi error saat upload!');
            window.location.href='rekanan.php';
        </script>";
        exit;
    }

    $fileTmp  = $_FILES['file_import']['tmp_name'];
    $fileName = $_FILES['file_import']['name'];
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // ===== VALIDASI 1: EKSTENSI FILE =====
    if ($ext !== 'csv') {
        $pesanExt = "❌ Import GAGAL!\\n\\nFormat file harus .csv (hasil download dari tombol 'Download Format').\\nFile yang Anda upload berformat: ." . $ext . "\\n\\nTidak ada data yang diproses.";
        echo "<script>
            alert('" . addslashes($pesanExt) . "');
            window.location.href='rekanan.php';
        </script>";
        exit;
    }

    // ===== VALIDASI 2: FILE BISA DIBACA =====
    try {
        $rows = native_csv_read($fileTmp);
    } catch (Exception $ex) {
        echo "<script>
            alert('❌ Import GAGAL!\\n\\nFile tidak bisa dibaca atau isinya rusak/kosong.\\nSilakan download ulang \\'Download Format\\' dan isi langsung di file tersebut, jangan ubah strukturnya.\\n\\nTidak ada data yang diproses.');
            window.location.href='rekanan.php';
        </script>";
        exit;
    }

    // ===== VALIDASI 3: STRUKTUR HEADER HARUS PERSIS =====
    // Baris pertama WAJIB persis: nama, alamat, telepon, status (urutan tidak boleh berubah)
    $expectedHeader = ['nama', 'alamat', 'telepon', 'status'];
    $headerRow = array_map(function ($v) {
        return strtolower(trim((string) $v));
    }, $rows[0]);

    $headerValid = true;
    foreach ($expectedHeader as $i => $col) {
        if (!isset($headerRow[$i]) || $headerRow[$i] !== $col) {
            $headerValid = false;
            break;
        }
    }

    if (!$headerValid) {
        echo "<script>
            alert('❌ Import GAGAL!\\n\\nStruktur kolom file tidak sesuai format yang diminta.\\nHeader baris pertama WAJIB persis: nama, alamat, telepon, status (urutan tidak boleh diubah).\\n\\nSilakan download ulang \\'Download Format\\', isi datanya langsung di file itu, jangan ubah/hapus/tambah kolom.\\n\\nTidak ada data yang diproses.');
            window.location.href='rekanan.php';
        </script>";
        exit;
    }

    // Buang baris header, sisanya baris data
    array_shift($rows);

    if (count($rows) === 0) {
        echo "<script>
            alert('⚠️ Import dibatalkan: file hanya berisi header, tidak ada baris data di bawahnya.');
            window.location.href='rekanan.php';
        </script>";
        exit;
    }

    // ===== PROSES BARIS DATA (baru sampai sini kalau struktur file sudah pasti valid) =====
    $berhasil = 0;
    $dilewati = 0;
    $baris_dilewati = [];
    $rowNo = 1; // baris 1 = header, data dimulai dari baris 2

    foreach ($rows as $row) {
        $rowNo++;

        $nama   = isset($row[0]) ? mb_strtoupper(trim((string) $row[0]), 'UTF-8') : '';
$alamat = isset($row[1]) ? mb_strtoupper(trim((string) $row[1]), 'UTF-8') : '';
$telp   = isset($row[2]) ? trim((string) $row[2]) : '';
$aktif  = isset($row[3]) ? strtoupper(trim((string) $row[3])) : '';

        // Lewati baris kosong total
        if ($nama === '' && $alamat === '' && $telp === '') continue;

        // Nama Rekanan wajib
        if ($nama === '') {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
            continue;
        }

        if (!in_array($aktif, ['Y', 'N'])) {
            $aktif = 'Y';
        }

        // Bersihkan nomor telepon: hanya angka
        $telp = preg_replace('/[^0-9]/', '', $telp);

        // PERBAIKAN: Excel sering MENGHAPUS angka 0 di depan nomor telepon
        // saat file CSV dibuka/disimpan, karena kolom itu dianggap angka
        // biasa (0812... dianggap sama dengan 812...). Kalau nomor yang
        // terbaca ternyata tidak diawali 0 padahal polanya khas nomor HP
        // Indonesia (diawali angka 8, panjang wajar 9-13 digit), sistem
        // otomatis mengembalikan angka 0 di depannya.
        if ($telp !== '' && $telp[0] === '8' && strlen($telp) >= 8 && strlen($telp) <= 13) {
            $telp = '0' . $telp;
        }

        $nama_esc   = mysqli_real_escape_string($connection, $nama);
        $alamat_esc = mysqli_real_escape_string($connection, $alamat);
        $telp_esc   = mysqli_real_escape_string($connection, $telp);

        $ttd = null;
        $randomPhoto = getRandomPhotoFromFolder();
        if ($randomPhoto) {
            $extPhoto = pathinfo($randomPhoto, PATHINFO_EXTENSION);
            $ttd = 'ttd_' . time() . '_' . uniqid() . '.' . $extPhoto;
            copy('../fotodata/' . $randomPhoto, '../uploads/ttd_rekanan/' . $ttd);
        }

        $ok = mysqli_query($connection, "INSERT INTO rekanan
            (nama_rekanan, alamat, telp, ttd_rekanan, aktif)
            VALUES ('$nama_esc','$alamat_esc','$telp_esc','$ttd','$aktif')");

        if ($ok) {
            $berhasil++;
        } else {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
        }
    }

    if ($berhasil === 0 && $dilewati === 0) {
        echo "<script>
            alert('⚠️ Tidak ada data valid untuk diimport. Pastikan kolom nama sudah diisi di baris data.');
            window.location.href='rekanan.php';
        </script>";
        exit;
    }

    $pesan = "✅ Import selesai!\\n\\nBerhasil ditambahkan: $berhasil data";
    if ($dilewati > 0) {
        $pesan .= "\\nDilewati: $dilewati data (baris ke: " . implode(', ', $baris_dilewati) . ")\\nPastikan kolom nama terisi.";
    }

    echo "<script>
        alert('" . $pesan . "');
        window.location.href='rekanan.php?success=import&count=$berhasil&skip=$dilewati';
    </script>";
    exit;
}

/* =====================
   SIMPAN REKANAN
===================== */
if (isset($_POST['simpan'])) {

    $nama   = mysqli_real_escape_string($connection, $_POST['nama_rekanan']);
    $alamat = mysqli_real_escape_string($connection, $_POST['alamat']);
    $telp   = mysqli_real_escape_string($connection, $_POST['telp']);

    $ttd = null;

    $randomPhoto = getRandomPhotoFromFolder();

    if ($randomPhoto) {
        $ext = pathinfo($randomPhoto, PATHINFO_EXTENSION);
        $ttd = 'ttd_' . time() . '_' . uniqid() . '.' . $ext;
        copy('../fotodata/' . $randomPhoto, '../uploads/ttd_rekanan/' . $ttd);
    }

    mysqli_query($connection, "INSERT INTO rekanan
        (nama_rekanan, alamat, telp, ttd_rekanan, aktif)
        VALUES ('$nama','$alamat','$telp','$ttd','Y')");

    echo "<script>
        alert('✅ Data rekanan berhasil ditambahkan dengan QR Code otomatis!');
        window.location.href='rekanan.php?success=create';
    </script>";
    exit;
}

/* =====================
   UPDATE REKANAN
===================== */
if (isset($_POST['update'])) {

    $id     = mysqli_real_escape_string($connection, $_POST['id_rekanan']);
    $nama   = mysqli_real_escape_string($connection, $_POST['nama_rekanan']);
    $alamat = mysqli_real_escape_string($connection, $_POST['alamat']);
    $telp   = mysqli_real_escape_string($connection, $_POST['telp']);
    $aktif  = mysqli_real_escape_string($connection, $_POST['aktif']);

    $old = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT ttd_rekanan FROM rekanan WHERE id_rekanan='$id'")
    );

    $ttd_sql = "";

    if (isset($_POST['refresh_foto'])) {
        if (!empty($old['ttd_rekanan']) && file_exists('../uploads/ttd_rekanan/'.$old['ttd_rekanan'])) {
            unlink('../uploads/ttd_rekanan/'.$old['ttd_rekanan']);
        }

        $randomPhoto = getRandomPhotoFromFolder();

        if ($randomPhoto) {
            $ext = pathinfo($randomPhoto, PATHINFO_EXTENSION);
            $ttd = 'ttd_' . time() . '_' . uniqid() . '.' . $ext;
            copy('../fotodata/' . $randomPhoto, '../uploads/ttd_rekanan/' . $ttd);
            $ttd_sql = ", ttd_rekanan='$ttd'";
        }
    }

    mysqli_query($connection, "UPDATE rekanan SET
        nama_rekanan='$nama',
        alamat='$alamat',
        telp='$telp',
        aktif='$aktif'
        $ttd_sql
        WHERE id_rekanan='$id'");

    echo "<script>
        alert('✅ Data rekanan berhasil diperbarui!');
        window.location.href='rekanan.php?success=update';
    </script>";
    exit;
}

/* =====================
   HAPUS REKANAN - CEK RELASI
===================== */
if (isset($_GET['hapus'])) {

    $id = mysqli_real_escape_string($connection, $_GET['hapus']);

    $check_perm = mysqli_query($connection, "
        SELECT COUNT(*) AS total
        FROM permintaan_perbaikan
        WHERE id_rekanan = '$id'
    ");

    $permintaan = mysqli_fetch_assoc($check_perm);

    if ($permintaan['total'] > 0) {
        echo "<script>
            alert('⚠️ Data tidak dapat dihapus!\\n\\nData rekanan ini masih digunakan pada {$permintaan['total']} permintaan perbaikan\\n\\nHapus data terkait terlebih dahulu atau nonaktifkan data ini.');
            window.location.href='rekanan.php?error=relasi&total={$permintaan['total']}';
        </script>";
        exit;
    }

    $old = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT ttd_rekanan FROM rekanan WHERE id_rekanan='$id'")
    );

    if (!empty($old['ttd_rekanan']) && file_exists('../uploads/ttd_rekanan/'.$old['ttd_rekanan'])) {
        unlink('../uploads/ttd_rekanan/'.$old['ttd_rekanan']);
    }

    mysqli_query($connection, "DELETE FROM rekanan WHERE id_rekanan='$id'");

    echo "<script>
        alert('✅ Data rekanan berhasil dihapus!');
        window.location.href='rekanan.php?success=delete';
    </script>";
    exit;
}

/* =====================
   NONAKTIFKAN REKANAN
===================== */
if (isset($_GET['nonaktif'])) {
    $id_rekanan = mysqli_real_escape_string($connection, $_GET['nonaktif']);
    mysqli_query($connection,"UPDATE rekanan SET aktif='N' WHERE id_rekanan='$id_rekanan'");
    echo "<script>window.location.href='rekanan.php?success=nonaktif';</script>";
    exit;
}

/* =====================
   AKTIFKAN KEMBALI REKANAN
===================== */
if (isset($_GET['aktifkan'])) {
    $id_rekanan = mysqli_real_escape_string($connection, $_GET['aktifkan']);
    mysqli_query($connection,"UPDATE rekanan SET aktif='Y' WHERE id_rekanan='$id_rekanan'");
    echo "<script>window.location.href='rekanan.php?success=aktifkan';</script>";
    exit;
}

/* =====================================================================
   FITUR: AKSI MASSAL (CENTANG) - Nonaktifkan / Hapus banyak sekaligus
===================================================================== */
if (isset($_POST['bulk_action']) && isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {

    $action = $_POST['bulk_action'];
    $ids    = array_map(function ($v) use ($connection) {
        return mysqli_real_escape_string($connection, $v);
    }, $_POST['selected_ids']);

    $ids = array_filter($ids, function ($v) { return is_numeric($v); });

    $backQuery = isset($_POST['back_query']) ? $_POST['back_query'] : '';
    $redirect  = 'rekanan.php' . ($backQuery ? '?' . $backQuery : '');

    if (count($ids) === 0) {
        echo "<script>
            alert('⚠️ Tidak ada data yang dicentang!');
            window.location.href='" . $redirect . "';
        </script>";
        exit;
    }

    $idListSql = implode(',', $ids);

    if ($action === 'nonaktif') {

        mysqli_query($connection, "UPDATE rekanan SET aktif='N' WHERE id_rekanan IN ($idListSql)");
        $jumlah = count($ids);

        echo "<script>
            alert('✅ $jumlah data rekanan berhasil dinonaktifkan!');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_nonaktif';
        </script>";
        exit;

    } elseif ($action === 'aktifkan') {

        mysqli_query($connection, "UPDATE rekanan SET aktif='Y' WHERE id_rekanan IN ($idListSql)");
        $jumlah = count($ids);

        echo "<script>
            alert('✅ $jumlah data rekanan berhasil diaktifkan kembali!');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_aktifkan';
        </script>";
        exit;

    } elseif ($action === 'hapus') {

        $berhasil_hapus = 0;
        $gagal_relasi   = 0;

        foreach ($ids as $id) {
            $check_perm = mysqli_query($connection, "
                SELECT COUNT(*) AS total FROM permintaan_perbaikan WHERE id_rekanan = '$id'
            ");
            $permintaan = mysqli_fetch_assoc($check_perm);

            if ($permintaan['total'] > 0) {
                $gagal_relasi++;
                continue;
            }

            $old = mysqli_fetch_assoc(
                mysqli_query($connection, "SELECT ttd_rekanan FROM rekanan WHERE id_rekanan='$id'")
            );
            if (!empty($old['ttd_rekanan']) && file_exists('../uploads/ttd_rekanan/'.$old['ttd_rekanan'])) {
                unlink('../uploads/ttd_rekanan/'.$old['ttd_rekanan']);
            }

            mysqli_query($connection, "DELETE FROM rekanan WHERE id_rekanan='$id'");
            $berhasil_hapus++;
        }

        $pesan = "✅ $berhasil_hapus data rekanan berhasil dihapus!";
        if ($gagal_relasi > 0) {
            $pesan .= "\\n⚠️ $gagal_relasi data TIDAK dihapus karena masih dipakai pada permintaan perbaikan (hanya bisa dinonaktifkan).";
        }

        echo "<script>
            alert('" . $pesan . "');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_hapus';
        </script>";
        exit;
    }
}

/* =====================
   MODE EDIT
===================== */
$edit = false;
if (isset($_GET['edit'])) {
    $edit = true;
    $id   = mysqli_real_escape_string($connection, $_GET['edit']);
    $e    = mysqli_fetch_assoc(
        mysqli_query($connection, "SELECT * FROM rekanan WHERE id_rekanan='$id'")
    );
}

/* =====================
   SEARCH & FILTER STATUS
===================== */
$keyword       = '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'semua';
$where_conditions = [];

if (isset($_GET['search'])) {
    $keyword = mysqli_real_escape_string($connection, $_GET['search']);
    $where_conditions[] = "(nama_rekanan LIKE '%$keyword%' OR alamat LIKE '%$keyword%' OR telp LIKE '%$keyword%')";
}

if ($filter_status == 'aktif') {
    $where_conditions[] = "aktif='Y'";
} elseif ($filter_status == 'nonaktif') {
    $where_conditions[] = "aktif='N'";
}

$where = count($where_conditions) > 0 ? "WHERE " . implode(' AND ', $where_conditions) : "";

// ===== PAGINATION: 50 data per halaman =====
$per_page = 50;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

$result_count = mysqli_query($connection, "SELECT COUNT(*) AS total FROM rekanan $where");
$row_count    = $result_count ? mysqli_fetch_assoc($result_count) : ['total' => 0];
$total_rows   = (int)($row_count['total'] ?? 0);
$total_pages  = $total_rows > 0 ? (int)ceil($total_rows / $per_page) : 1;

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

// query string filter yang sedang aktif, untuk export & redirect bulk action
$filterQueryString = currentFilterQuery();
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Management Rekanan</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html { overflow-x: hidden; }
    body {
        display: flex;
        background:rgb(185, 224, 204);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        min-height: 100vh;
        overflow-x: hidden;
    }
    .app-wrapper { display: flex; width: 100%; min-height: 100vh; }
    .main-content { flex: 1; padding: 30px; min-width: 0; overflow-x: hidden; }
    @media (max-width: 1023px) {
        .main-content { margin-left: 0; padding-top: 90px; padding: 20px; }
    }
    .layout-container {
        display: grid;
        grid-template-columns: 420px minmax(0, 1fr);
        gap: 25px;
        max-width: 100%;
        min-width: 0;
    }
    @media (max-width: 992px) {
        .layout-container { grid-template-columns: minmax(0, 1fr); }
    }
    .form-section {
        background: rgba(255,255,255,0.98);
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        height: fit-content;
        position: sticky;
        top: 30px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        animation: slideInLeft 0.5s ease-out;
        min-width: 0;
    }
    @keyframes slideInLeft {
        from { opacity: 0; transform: translateX(-30px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    @keyframes slideInRight {
        from { opacity: 0; transform: translateX(30px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    .form-section h4 {
        color: #667eea;
        font-weight: 700;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 3px solid #667eea;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .form-section h4 i { font-size: 1.5rem; }
    .data-section {
        background: rgba(255,255,255,0.98);
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        animation: slideInRight 0.5s ease-out;
        min-width: 0;
    }
    .data-section h4 { color: #667eea; font-weight: 700; display: flex; align-items: center; gap: 10px; }
    .table-wrapper {
        overflow-x: auto;
        overflow-y: auto;
        max-height: calc(100vh - 250px);
        margin-top: 20px;
        border-radius: 12px;
        border: 1px solid #e0e0e0;
    }
    .table-wrapper::-webkit-scrollbar { width: 10px; height: 10px; }
    .table-wrapper::-webkit-scrollbar-track { background: linear-gradient(135deg,#f5f7fa,#c3cfe2); border-radius: 10px; }
    .table-wrapper::-webkit-scrollbar-thumb { background: linear-gradient(135deg,#667eea,#764ba2); border-radius: 10px; }
    .table-wrapper::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg,#764ba2,#667eea); }
    .search-box { position: relative; margin-bottom: 20px; }
    .search-box input {
        width: 100%; padding: 12px 16px 12px 45px;
        border-radius: 50px; border: 2px solid #e0e0e0;
        transition: all 0.3s; font-size: 0.95rem;
    }
    .search-box input:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102,126,234,0.1);
        transform: translateY(-2px); outline: none;
    }
    .search-box i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #667eea; font-size: 1.1rem; }
    .table { margin-bottom: 0; width: 100%; border-collapse: collapse; }
    .table thead th {
        position: sticky; top: 0;
        background:rgb(9, 120, 83); color: white; z-index: 10;
        font-weight: 600; text-transform: uppercase;
        font-size: 0.85rem; letter-spacing: 0.5px;
        padding: 15px 12px; border: none;
        white-space: nowrap;
    }
    .table tbody tr { transition: all 0.3s; border-bottom: 1px solid #f0f0f0; background: white; }
    .table tbody tr:hover {
        background: linear-gradient(to right, rgba(102,126,234,0.05), rgba(118,75,162,0.05));
        transform: scale(1.01);
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
    .table tbody tr.row-selected { background: rgba(102,126,234,0.12) !important; }
    .table tbody td { padding: 12px; vertical-align: middle; }
    /* Kolom Nama & Telepon dibuat 1 baris (nowrap), kolom Alamat tetap
       boleh wrap karena isinya memang panjang -> tabel yang scroll ke
       samping kalau kepanjangan, bukan teks yang malah kepotong turun. */
    .table tbody td[data-label="No"],
    .table tbody td[data-label="Nama Rekanan"],
    .table tbody td[data-label="Telepon"],
    .table tbody td[data-label="Status"] {
        white-space: nowrap;
    }
    .table tbody td[data-label="Alamat"] {
        min-width: 220px;
        max-width: 320px;
    }
    .form-label { font-weight: 600; margin-bottom: 10px; color: #333; display: flex; align-items: center; gap: 8px; }
    .form-label i { color: #667eea; }
    .form-control, .form-select {
        width: 100%; border-radius: 12px; border: 2px solid #e0e0e0;
        padding: 12px 16px; transition: all 0.3s; font-size: 0.95rem;
    }
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102,126,234,0.1);
        transform: translateY(-2px); outline: none;
    }
    textarea.form-control { resize: vertical; min-height: 80px; }
    .preview-image {
        max-height: 100px; margin-top: 12px; border-radius: 12px;
        border: 3px solid #667eea; padding: 5px; background: white;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .btn {
        border-radius: 12px; padding: 12px 24px; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.5px;
        transition: all 0.3s; border: none; font-size: 0.9rem;
        cursor: pointer; text-decoration: none; display: inline-block; text-align: center;
    }
    .btn:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.2); }
    .btn:active { transform: translateY(-1px); }
    .btn-primary { background:rgb(9, 120, 83); color: white; }
    .btn-success { background: linear-gradient(135deg,#56ab2f,#a8e063); color: white; }
    .btn-warning { background: linear-gradient(135deg,#f093fb,#f5576c); color: white; border: none; }
    .btn-danger  { background: linear-gradient(135deg,#eb3349,#f45c43); color: white; }
    .btn-secondary { background: linear-gradient(135deg,#bdc3c7,#95a5a6); color: white; }
    .btn-info    { background: linear-gradient(135deg,#00c6ff,#0072ff); color: white; }
    .btn-orange  { background: linear-gradient(135deg,#ff9966,#ff5e62); color: white; }
    .btn-excel   { background: linear-gradient(135deg,#1d976c,#93f9b9); color: #063; }
    .btn-import  { background: linear-gradient(135deg,#11998e,#38ef7d); color: #063; }
    .btn-group-action { display: flex; gap: 8px; justify-content: flex-start; align-items: center; flex-wrap: nowrap; }
    .btn-sm { padding: 8px 16px; font-size: 0.85rem; }
    .btn:disabled, .btn.disabled {
        opacity: 0.5; cursor: not-allowed; transform: none !important; box-shadow: none !important;
    }
    .empty-state { text-align: center; padding: 60px 20px; color: #999; }
    .empty-state i {
        font-size: 4rem; margin-bottom: 20px; opacity: 0.3;
        background: linear-gradient(135deg,#667eea,#764ba2);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .empty-state p { font-size: 1.1rem; font-weight: 500; }
    .modal-overlay {
        display: none; position: fixed; top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.8); z-index: 9999;
        justify-content: center; align-items: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-dialog { position: relative; max-width: 90%; max-height: 90vh; }
    .modal-content { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
    .modal-header {
        background: linear-gradient(135deg,#667eea,#764ba2); color: white;
        padding: 20px; display: flex; justify-content: space-between; align-items: center;
    }
    .modal-title { margin: 0; font-size: 1.2rem; font-weight: 600; }
    .btn-close {
        background: transparent; border: none; color: white; font-size: 1.5rem;
        cursor: pointer; width: 30px; height: 30px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 50%; transition: all 0.3s;
    }
    .btn-close:hover { background: rgba(255,255,255,0.2); transform: rotate(90deg); }
    .modal-body { padding: 20px; text-align: center; }
    .modal-body img { max-width: 100%; max-height: 70vh; border-radius: 10px; }
    .mb-3 { margin-bottom: 1rem; }
    .mb-4 { margin-bottom: 1.5rem; }
    .d-grid { display: grid; }
    .gap-2 { gap: 0.5rem; }
    .d-flex { display: flex; }
    .justify-content-between { justify-content: space-between; }
    .align-items-center { align-items: center; }
    .mb-0 { margin-bottom: 0; }
    .text-center { text-align: center; }
    .text-muted { color: #6c757d; }
    .text-uppercase-input { text-transform: uppercase; }
    .filter-select {
        cursor: pointer; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 12px center; padding-right: 35px;
    }
    .toolbar-import-export {
        display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;
    }
    .bulk-bar {
        display: none;
        align-items: center;
        gap: 10px;
        background: #fff8e1;
        border: 2px dashed #f0b429;
        border-radius: 12px;
        padding: 10px 16px;
        margin-bottom: 15px;
        flex-wrap: wrap;
    }
    .bulk-bar.active { display: flex; }
    .bulk-bar span { font-weight: 600; color: #7a5b00; font-size: 0.9rem; }
    .note-box {
        background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 8px;
        padding: 14px 16px; margin-bottom: 15px; font-size: 0.85rem; color: #333;
    }
    .note-box ul { margin: 6px 0 0 18px; padding: 0; }
    .note-box li { margin-bottom: 4px; }
    .checkbox-col { width: 40px; text-align: center; }
    @media (max-width: 1023px) {
        .app-wrapper { flex-direction: column; }
        .main-content { margin-left: 0 !important; padding: 15px !important; padding-top: 80px !important; }
        .layout-container { grid-template-columns: minmax(0, 1fr) !important; gap: 15px !important; }
        .form-section { position: relative !important; top: 0 !important; padding: 20px !important; }
        .table { display: block !important; }
        .table thead { display: none !important; }
        .table tbody { display: block !important; }
        .table tbody tr { display: block !important; margin-bottom: 15px !important; border: 2px solid #e0e0e0 !important; border-radius: 12px !important; padding: 15px !important; }
        .table tbody td { display: block !important; width: 100% !important; text-align: left !important; padding: 8px 0 !important; border: none !important; white-space: normal !important; }
        .table tbody td::before { content: attr(data-label); font-weight: 700; color: #667eea; display: block; margin-bottom: 5px; font-size: 12px; }
        .checkbox-col::before { content: '' !important; }
    }
</style>
</head>
<body>

<div class="app-wrapper">
<div class="main-content">

    <!-- Alert Messages -->
    <?php if (isset($_GET['success'])): ?>
    <div class="bg-green-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg alert-success-notification">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-2xl mr-3"></i>
            <span>
                <?php
                if ($_GET['success'] == 'create')        echo 'Data berhasil ditambahkan!';
                elseif ($_GET['success'] == 'update')     echo 'Data berhasil diperbarui!';
                elseif ($_GET['success'] == 'delete')     echo 'Data berhasil dihapus permanen!';
                elseif ($_GET['success'] == 'nonaktif')   echo 'Data berhasil dinonaktifkan!';
                elseif ($_GET['success'] == 'aktifkan')   echo 'Data berhasil diaktifkan kembali!';
                elseif ($_GET['success'] == 'bulk_nonaktif') echo 'Data terpilih berhasil dinonaktifkan!';
                elseif ($_GET['success'] == 'bulk_aktifkan') echo 'Data terpilih berhasil diaktifkan kembali!';
                elseif ($_GET['success'] == 'bulk_hapus')    echo 'Data terpilih berhasil diproses (hapus)!';
                elseif ($_GET['success'] == 'import') {
                    $cnt  = isset($_GET['count']) ? (int)$_GET['count'] : 0;
                    $skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;
                    echo "Import selesai! $cnt data ditambahkan" . ($skip > 0 ? ", $skip dilewati." : ".");
                }
                ?>
            </span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="bg-red-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg alert-error-notification">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
            <span>
                <?php
                if ($_GET['error'] == 'relasi') {
                    $total = isset($_GET['total']) ? htmlspecialchars($_GET['total']) : '0';
                    echo 'Data tidak dapat dihapus! Rekanan ini masih digunakan pada <strong>' . $total . '</strong> permintaan perbaikan';
                }
                ?>
            </span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <div class="layout-container">

        <!-- FORM SECTION (KIRI) -->
        <div class="form-section">
            <h4>
                <i class="fas fa-<?= $edit ? 'edit' : 'plus-circle' ?>"></i>
                <?= $edit ? 'Edit Rekanan' : 'Tambah Rekanan' ?>
            </h4>

            <form method="POST">
                <?php if ($edit): ?>
                    <input type="hidden" name="id_rekanan" value="<?= $e['id_rekanan'] ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-user"></i> Nama Rekanan *
                    </label>
                    <input type="text" name="nama_rekanan" class="form-control text-uppercase-input"
                           id="namaRekanan"
                           value="<?= $edit ? htmlspecialchars($e['nama_rekanan']) : '' ?>"
                           placeholder="Masukkan nama rekanan"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-phone"></i> No. Telepon
                    </label>
                    <input type="text"
                           name="telp"
                           class="form-control"
                           value="<?= $edit ? htmlspecialchars($e['telp']) : '' ?>"
                           placeholder="Contoh: 081*********"
                           inputmode="numeric"
                           pattern="[0-9]*"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-map-marker-alt"></i> Alamat
                    </label>
                    <textarea name="alamat" class="form-control text-uppercase-input" rows="3"
                              id="alamatRekanan"
                              placeholder="Masukkan alamat lengkap"><?= $edit ? htmlspecialchars($e['alamat']) : '' ?></textarea>
                </div>

                <?php if ($edit): ?>
                <div class="mb-3">
                    <label class="form-label">
                        <i class="fas fa-toggle-on"></i> Status Aktif
                    </label>
                    <select name="aktif" class="form-select">
                        <option value="Y" <?= ($e['aktif'] ?? 'Y') == 'Y' ? 'selected' : '' ?>>✅ Aktif</option>
                        <option value="N" <?= ($e['aktif'] ?? 'Y') == 'N' ? 'selected' : '' ?>>❌ Tidak Aktif</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>
                        Rekanan yang tidak aktif tidak akan muncul di daftar pilihan
                    </p>
                </div>
                <?php else: ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-3">
                    <p class="text-xs text-green-700">
                        <i class="fas fa-check-circle mr-1"></i>
                        Data baru otomatis berstatus <strong>AKTIF</strong>
                    </p>
                </div>
                <?php endif; ?>

                <div class="mb-4">
                    <label class="form-label">
                        <i class="fas fa-qrcode"></i> QR CODE Otomatis
                    </label>
                    <?php if ($edit && !empty($e['ttd_rekanan'])): ?>
                        <img src="../uploads/ttd_rekanan/<?= $e['ttd_rekanan'] ?>"
                             class="preview-image" alt="QR Code">
                        <div class="form-check mt-3" style="padding-left: 1.5rem;">
                            <input type="checkbox" name="refresh_foto" id="refreshFoto"
                                   class="form-check-input" value="1"
                                   style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="refreshFoto" style="margin-left: 8px; cursor: pointer; font-size: 0.9rem;">
                                <i class="fas fa-sync-alt"></i> Ganti dengan QR Code baru
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="info-box-qr" style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 12px; border-radius: 8px; margin-top: 10px;">
                            <i class="fas fa-info-circle" style="color: #2196F3;"></i>
                            <span style="font-size: 0.9rem; color: #333; margin-left: 8px;">
                                QR Code akan dipilih otomatis
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" name="<?= $edit ? 'update' : 'simpan' ?>"
                            class="btn btn-<?= $edit ? 'success' : 'primary' ?>">
                        <i class="fas fa-<?= $edit ? 'check' : 'save' ?>"></i>
                        <?= $edit ? 'Update Data' : 'Simpan Data' ?>
                    </button>
                    <?php if ($edit): ?>
                        <a href="rekanan.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- DATA SECTION (KANAN) -->
        <div class="data-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">
                    <i class="fas fa-list"></i> Data Rekanan
                </h4>
                <?php
                $total_aktif    = mysqli_num_rows(mysqli_query($connection, "SELECT id_rekanan FROM rekanan WHERE aktif='Y'"));
                $total_nonaktif = mysqli_num_rows(mysqli_query($connection, "SELECT id_rekanan FROM rekanan WHERE aktif='N'"));
                ?>
                <div class="flex gap-2">
                    <span class="px-3 py-1 bg-green-500 text-white rounded-full text-xs font-semibold">
                        ✓ Aktif: <?= $total_aktif ?>
                    </span>
                    <span class="px-3 py-1 bg-red-500 text-white rounded-full text-xs font-semibold">
                        ✗ Nonaktif: <?= $total_nonaktif ?>
                    </span>
                </div>
            </div>

            <!-- ===================== CATATAN IMPORT (COLLAPSIBLE) ===================== -->
            <div class="note-box" style="padding: 0; overflow: hidden;">
                <button type="button" onclick="toggleNoteImport()" id="noteImportToggle"
                        style="width:100%; background:transparent; border:none; cursor:pointer;
                               padding:14px 16px; text-align:left; display:flex;
                               justify-content:space-between; align-items:center;
                               font-size:0.85rem; color:#333;">
                    <span><i class="fas fa-circle-info"></i> <strong>Catatan sebelum Import Data</strong> (klik untuk lihat)</span>
                    <i class="fas fa-chevron-down" id="noteImportIcon" style="transition: transform 0.25s;"></i>
                </button>
                <div id="noteImportContent" style="display:none; padding: 0 16px 14px 16px;">
                    <ul>
                        <li>Klik <strong>"Download Format"</strong> dulu untuk mendapat file contoh kolom yang benar (.csv).</li>
                        <li>Edit file .csv itu langsung di Excel, lalu simpan (biarkan tetap format .csv, jangan ganti ke .xlsx).</li>
                        <li>JANGAN mengubah nama header / urutan kolom: <strong>nama, alamat, telepon, status</strong> — kalau strukturnya berubah, import akan <strong>GAGAL TOTAL</strong> (tidak ada data yang dipaksa masuk).</li>
                        <li>JANGAN mengisi kolom ID atau QR Code — keduanya otomatis dibuat sistem.</li>
                        <li>Kolom <strong>nama wajib diisi</strong>, kolom telepon hanya angka.</li>
                        <li><strong>Nomor telepon diawali 0</strong> (misal 081331003702): sistem otomatis mengembalikan angka 0 di depan kalau sampai hilang saat diedit di Excel. Kalau mau lebih aman, klik kanan kolom Telepon → <em>Format Cells → Text</em> sebelum mengetik nomornya.</li>
                        <li>Kolom status isi <strong>Y</strong> atau <strong>N</strong> saja (kosong = otomatis Y).</li>
                        <li>Import hanya MENAMBAH data baru, bukan mengubah data lama (untuk itu pakai tombol Edit).</li>
                        <li>Saat <strong>Export</strong>, semua data akan ditarik (tidak hanya yang tampil di halaman ini).</li>
                    </ul>
                </div>
            </div>

            <div class="toolbar-import-export">
                <a href="rekanan.php?template=1" class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-arrow-down"></i> Download Format
                </a>
                <button type="button" class="btn btn-import btn-sm" onclick="showImportModal()">
                    <i class="fas fa-file-import"></i> Import Data
                </button>
                <a href="rekanan.php?export=1<?= $filterQueryString ? '&' . htmlspecialchars($filterQueryString) : '' ?>" class="btn btn-excel btn-sm">
    <i class="fas fa-file-excel"></i> Export Excel <?= ($keyword !== '' || $filter_status !== 'semua') ? '(Sesuai Filter)' : '(Semua Data)' ?>
</a>
            </div>

            <!-- Filter & Search -->
            <div class="mb-3 space-y-3">
                <div class="flex gap-2 items-center">
                    <label class="text-sm font-semibold text-gray-700 whitespace-nowrap">
                        <i class="fas fa-filter mr-1"></i> Filter:
                    </label>
                    <select id="filterStatus"
                            class="filter-select flex-1 px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all">
                        <option value="semua"    <?= $filter_status == 'semua'    ? 'selected' : '' ?>>🔍 Semua Status</option>
                        <option value="aktif"    <?= $filter_status == 'aktif'    ? 'selected' : '' ?>>✅ Aktif Saja</option>
                        <option value="nonaktif" <?= $filter_status == 'nonaktif' ? 'selected' : '' ?>>❌ Tidak Aktif Saja</option>
                    </select>
                </div>

                <form method="GET" class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text"
                           name="search"
                           value="<?= htmlspecialchars($keyword) ?>"
                           class="form-control"
                           placeholder="Cari berdasarkan nama, alamat, atau telepon..."
                           id="searchInput">
                    <input type="hidden" name="status" value="<?= $filter_status ?>">
                </form>
            </div>

            <!-- ===================== BAR AKSI MASSAL (muncul kalau ada yang dicentang) ===================== -->
            <div class="bulk-bar" id="bulkBar">
                <span><i class="fas fa-square-check"></i> <span id="bulkCount">0</span> data dipilih</span>
                <button type="button" class="btn btn-orange btn-sm" id="btnBulkNonaktif" onclick="submitBulk('nonaktif')">
                    <i class="fas fa-eye-slash"></i> Nonaktifkan Terpilih
                </button>
                <button type="button" class="btn btn-success btn-sm" id="btnBulkAktifkan" onclick="submitBulk('aktifkan')" style="display:none;">
                    <i class="fas fa-check"></i> Aktifkan Terpilih
                </button>
                <button type="button" class="btn btn-danger btn-sm" onclick="submitBulk('hapus')">
                    <i class="fas fa-trash"></i> Hapus Terpilih
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">
                    <i class="fas fa-xmark"></i> Batal Pilih
                </button>
            </div>

            <!-- FORM pembungkus tabel untuk aksi massal -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                <input type="hidden" name="back_query" value="<?= htmlspecialchars($filterQueryString) ?>">

                <!-- TABLE -->
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="checkbox-col">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" title="Pilih semua">
                                </th>
                                <th width="50">No</th>
                                <th width="200">Nama</th>
                                <th width="250">Alamat</th>
                                <th width="120">Telepon</th>
                                <th width="80">QR CODE</th>
                                <th width="80">Status</th>
                                <th width="180">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $no = $offset + 1;
                        $q  = mysqli_query(
                            $connection,
                            "SELECT * FROM rekanan $where ORDER BY aktif DESC, id_rekanan DESC LIMIT $per_page OFFSET $offset"
                        );
                        if (mysqli_num_rows($q) > 0):
                            while ($r = mysqli_fetch_assoc($q)):
                        ?>
                        <tr data-status="<?= $r['aktif'] ?>">
                            <td class="checkbox-col" data-label="Pilih">
                                <input type="checkbox" class="row-checkbox" name="selected_ids[]"
                                       value="<?= $r['id_rekanan'] ?>" onclick="onRowCheck(this)">
                            </td>
                            <td class="text-center" data-label="No"><?= $no++ ?></td>
                            <td data-label="Nama Rekanan"><?= htmlspecialchars($r['nama_rekanan']) ?></td>
                            <td data-label="Alamat"><?= htmlspecialchars($r['alamat']) ?></td>
                            <td data-label="Telepon"><?= htmlspecialchars($r['telp']) ?></td>
                            <td class="text-center" data-label="QR Code">
                                <?php if ($r['ttd_rekanan']): ?>
                                    <img src="../uploads/ttd_rekanan/<?= $r['ttd_rekanan'] ?>"
                                         style="max-height:50px; cursor:pointer;"
                                         onclick="showImage('../uploads/ttd_rekanan/<?= $r['ttd_rekanan'] ?>')"
                                         title="Klik untuk memperbesar">
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" data-label="Status">
                                <?php if ($r['aktif'] == 'Y'): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i>Aktif
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                        <i class="fas fa-times-circle mr-1"></i>Nonaktif
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi">
                                <div class="btn-group-action">
                                    <a href="?edit=<?= $r['id_rekanan'] ?>"
                                       class="btn btn-info btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($r['aktif'] == 'Y'): ?>
                                    <a href="?nonaktif=<?= $r['id_rekanan'] ?>"
                                       onclick="return confirm('⚠️ Nonaktifkan rekanan ini?\n\nRekanan yang dinonaktifkan tidak akan muncul di daftar pilihan.')"
                                       class="btn btn-orange btn-sm" title="Nonaktifkan">
                                        <i class="fas fa-eye-slash"></i>
                                    </a>
                                    <?php else: ?>
                                    <a href="?aktifkan=<?= $r['id_rekanan'] ?>"
                                       onclick="return confirm('✅ Aktifkan kembali rekanan ini?')"
                                       class="btn btn-success btn-sm" title="Aktifkan">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="?hapus=<?= $r['id_rekanan'] ?>"
                                       onclick="return confirm('🗑️ HAPUS PERMANEN?\n\n⚠️ Data akan dihapus dari database dan TIDAK BISA dikembalikan!\n\nYakin ingin melanjutkan?')"
                                       class="btn btn-danger btn-sm" title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <i class="fas fa-folder-open"></i>
                                    <p class="mb-0">
                                        <?php
                                        if ($keyword != '' && $filter_status != 'semua')
                                            echo 'Tidak ada data yang sesuai dengan filter dan pencarian';
                                        elseif ($keyword)
                                            echo 'Data tidak ditemukan untuk pencarian "' . htmlspecialchars($keyword) . '"';
                                        elseif ($filter_status == 'aktif')
                                            echo 'Tidak ada rekanan yang aktif';
                                        elseif ($filter_status == 'nonaktif')
                                            echo 'Tidak ada rekanan yang nonaktif';
                                        else
                                            echo 'Belum ada data rekanan';
                                        ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <!-- akhir form bulk -->

            <!-- ===================== PAGINATION (50 data / halaman) ===================== -->
            <?php if ($total_pages > 1): ?>
            <?php
            $base_params = [
                'status' => $filter_status,
                'search' => $keyword,
            ];
            $prev_page  = max(1, $page - 1);
            $next_page  = min($total_pages, $page + 1);
            $prev_qs    = http_build_query(array_merge($base_params, ['page' => $prev_page]));
            $next_qs    = http_build_query(array_merge($base_params, ['page' => $next_page]));

            $window     = 2;
            $page_start = max(2, $page - $window);
            $page_end   = min($total_pages - 1, $page + $window);
            ?>
            <div class="mt-4 space-y-2">

                <!-- Info teks -->
                <p class="text-sm text-gray-600">
                    Menampilkan
                    <span class="font-semibold"><?= $total_rows > 0 ? ($offset + 1) : 0 ?> – <?= min($offset + $per_page, $total_rows) ?></span>
                    dari <span class="font-semibold"><?= $total_rows ?></span> data rekanan (50 data / halaman)
                </p>

                <!-- Tombol pagination -->
                <div class="flex flex-wrap items-center gap-1">

                    <!-- Prev -->
                    <a href="?<?= htmlspecialchars($prev_qs) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page <= 1
                                    ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        <i class="fas fa-chevron-left text-xs mr-1"></i> Prev
                    </a>

                    <!-- Halaman 1 -->
                    <?php $qs1 = http_build_query(array_merge($base_params, ['page' => 1])); ?>
                    <a href="?<?= htmlspecialchars($qs1) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page === 1
                                    ? 'bg-green-700 text-white border-green-700'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        1
                    </a>

                    <!-- Ellipsis kiri -->
                    <?php if ($page_start > 2): ?>
                    <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                    <?php endif; ?>

                    <!-- Halaman tengah -->
                    <?php for ($i = $page_start; $i <= $page_end; $i++):
                        $qsi = http_build_query(array_merge($base_params, ['page' => $i]));
                    ?>
                    <a href="?<?= htmlspecialchars($qsi) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $i === $page
                                    ? 'bg-green-700 text-white border-green-700'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>

                    <!-- Ellipsis kanan -->
                    <?php if ($page_end < $total_pages - 1): ?>
                    <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                    <?php endif; ?>

                    <!-- Halaman terakhir -->
                    <?php if ($total_pages > 1):
                        $qsN = http_build_query(array_merge($base_params, ['page' => $total_pages]));
                    ?>
                    <a href="?<?= htmlspecialchars($qsN) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page === $total_pages
                                    ? 'bg-green-700 text-white border-green-700'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        <?= $total_pages ?>
                    </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <a href="?<?= htmlspecialchars($next_qs) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page >= $total_pages
                                    ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        Next <i class="fas fa-chevron-right text-xs ml-1"></i>
                    </a>

                    <!-- Jump to page -->
                    <form method="GET" class="inline-flex items-center gap-1 ml-1"
                          onsubmit="this.page.value = Math.max(1, Math.min(<?= $total_pages ?>, parseInt(this.page.value) || 1))">
                        <?php foreach ($base_params as $pk => $pv): ?>
                        <input type="hidden" name="<?= htmlspecialchars($pk) ?>" value="<?= htmlspecialchars($pv) ?>">
                        <?php endforeach; ?>
                        <span class="text-sm text-gray-500 whitespace-nowrap">Ke:</span>
                        <input type="number" name="page"
                               min="1" max="<?= $total_pages ?>"
                               placeholder="<?= $page ?>"
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

<!-- Modal preview gambar -->
<div class="modal-overlay" id="imageModal" onclick="hideModal()">
    <div class="modal-dialog" onclick="event.stopPropagation()">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Preview QR Code</h5>
                <button type="button" class="btn-close" onclick="hideModal()">×</button>
            </div>
            <div class="modal-body">
                <img id="modalImage" src="" alt="Preview QR Code">
            </div>
        </div>
    </div>
</div>

<!-- Modal Import Data (CSV) -->
<div class="modal-overlay" id="importModal" onclick="hideImportModal()">
    <div class="modal-dialog" onclick="event.stopPropagation()" style="max-width: 500px; width: 90%;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Data Rekanan</h5>
                <button type="button" class="btn-close" onclick="hideImportModal()">×</button>
            </div>
            <div class="modal-body" style="text-align: left;">
                <div class="note-box" style="margin-bottom: 15px;">
                    <strong><i class="fas fa-triangle-exclamation"></i> Sebelum upload, pastikan:</strong>
                    <ul>
                        <li>File hasil download dari tombol <strong>"Download Format"</strong>, header/urutan kolom belum diubah.</li>
                        <li>Kolom <strong>nama</strong> sudah terisi di setiap baris.</li>
                        <li>Format file <strong>.csv</strong> (bukan .xls/.xlsx).</li>
                        <li>Baris contoh (CV CONTOH JAYA ABADI) sebaiknya dihapus dulu bila tidak diperlukan.</li>
                        <li>Jika struktur file tidak sesuai, sistem akan <strong>menolak seluruh import</strong> (tidak ada data yang dipaksa masuk).</li>
                    </ul>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-file-csv"></i> Pilih File CSV</label>
                        <input type="file" name="file_import" accept=".csv" required class="form-control">
                    </div>
                    <button type="submit" name="import_submit" class="btn btn-import" style="width:100%;">
                        <i class="fas fa-upload"></i> Upload &amp; Import Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function autoCapitalize(inputId) {
    const input = document.getElementById(inputId);
    if (input) {
        input.addEventListener('input', function() {
            const s = this.selectionStart, e = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(s, e);
        });
    }
}

window.addEventListener('DOMContentLoaded', function() {
    autoCapitalize('namaRekanan');
    autoCapitalize('alamatRekanan');
});

// Auto hide alert success/error saja
setTimeout(() => {
    document.querySelectorAll('.alert-success-notification, .alert-error-notification').forEach(alert => {
        alert.style.transition = 'all 0.3s ease-out';
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-20px)';
        setTimeout(() => alert.remove(), 300);
    });
}, 5000);

// Filter status
const filterStatus = document.getElementById('filterStatus');
if (filterStatus) {
    filterStatus.addEventListener('change', function() {
        const status = this.value;
        const search = document.getElementById('searchInput').value;
        let url = 'rekanan.php?status=' + status;
        if (search) url += '&search=' + encodeURIComponent(search);
        window.location.href = url;
    });
}

// Live search
let searchTimeout;
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const filterValue = filterStatus.value;
        searchTimeout = setTimeout(() => {
            let url = 'rekanan.php?status=' + filterValue;
            if (this.value) url += '&search=' + encodeURIComponent(this.value);
            window.location.href = url;
        }, 800);
    });
}

// Modal preview QR code
function showImage(src) {
    const modal    = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    modal.classList.add('active');
    modalImg.src = src;
    document.body.style.overflow = 'hidden';
}
function hideModal() {
    document.getElementById('imageModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Modal import
function showImportModal() {
    document.getElementById('importModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function hideImportModal() {
    document.getElementById('importModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Toggle catatan import (collapsible)
function toggleNoteImport() {
    const content = document.getElementById('noteImportContent');
    const icon = document.getElementById('noteImportIcon');
    const isOpen = content.style.display === 'block';
    content.style.display = isOpen ? 'none' : 'block';
    icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { hideModal(); hideImportModal(); }
});

// ===================== FITUR CENTANG / BULK ACTION =====================
function toggleSelectAll(source) {
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.checked = source.checked;
        cb.closest('tr').classList.toggle('row-selected', source.checked);
    });
    updateBulkBar();
}

function onRowCheck(cb) {
    cb.closest('tr').classList.toggle('row-selected', cb.checked);
    const all = document.querySelectorAll('.row-checkbox');
    const checked = document.querySelectorAll('.row-checkbox:checked');
    document.getElementById('selectAll').checked = (all.length > 0 && all.length === checked.length);
    updateBulkBar();
}

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = checked.length;
    bar.classList.toggle('active', checked.length > 0);

    // Cek status baris yang dicentang: ada yang masih Aktif (Y) dan/atau Nonaktif (N)?
    let adaAktif = false;
    let adaNonaktif = false;
    checked.forEach(cb => {
        const status = cb.closest('tr').getAttribute('data-status');
        if (status === 'Y') adaAktif = true;
        if (status === 'N') adaNonaktif = true;
    });

    // Tombol "Nonaktifkan Terpilih" hanya muncul kalau ada data yang masih Aktif dicentang
    document.getElementById('btnBulkNonaktif').style.display = adaAktif ? 'inline-block' : 'none';
    // Tombol "Aktifkan Terpilih" hanya muncul kalau ada data Nonaktif dicentang
    document.getElementById('btnBulkAktifkan').style.display = adaNonaktif ? 'inline-block' : 'none';
}

function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.checked = false;
        cb.closest('tr').classList.remove('row-selected');
    });
    document.getElementById('selectAll').checked = false;
    updateBulkBar();
}

function submitBulk(action) {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length === 0) {
        alert('⚠️ Pilih minimal satu data terlebih dahulu!');
        return;
    }

    let confirmMsg = '';
    if (action === 'nonaktif') {
        confirmMsg = '⚠️ Nonaktifkan ' + checked.length + ' data rekanan terpilih?';
    } else if (action === 'aktifkan') {
        confirmMsg = '✅ Aktifkan kembali ' + checked.length + ' data rekanan terpilih?';
    } else if (action === 'hapus') {
        confirmMsg = '🗑️ HAPUS PERMANEN ' + checked.length + ' data rekanan terpilih?\n\n⚠️ Data yang masih dipakai pada permintaan perbaikan otomatis akan dilewati.\nData lainnya TIDAK BISA dikembalikan!';
    }

    if (!confirm(confirmMsg)) return;

    document.getElementById('bulkActionInput').value = action;
    document.getElementById('bulkForm').submit();
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>
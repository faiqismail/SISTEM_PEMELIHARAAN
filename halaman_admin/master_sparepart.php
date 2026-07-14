<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
require_once "../inc/xlsx_helper.php"; // dipakai untuk Export (tampilan .xls, tidak di-roundtrip)
require_once "../inc/csv_helper.php";  // dipakai untuk Template & Import (.csv, aman untuk roundtrip)
requireAuth('admin');

/* =====================================================================
   HELPER: bangun kondisi WHERE dari filter GET yang sedang aktif
   (dipakai bareng oleh: listing tabel & export, supaya hasil export
   SELALU sinkron dengan apa yang sedang difilter/dicari di halaman)
===================================================================== */
function buildSparepartWhere($connection) {
    $search        = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_status = isset($_GET['status']) ? $_GET['status'] : 'semua';
    $where_conditions = [];

    if ($search !== '') {
        $search_esc = mysqli_real_escape_string($connection, $search);
        $where_conditions[] = "(kode_sparepart LIKE '%$search_esc%' OR nama_sparepart LIKE '%$search_esc%')";
    }
    if ($filter_status === 'aktif') {
        $where_conditions[] = "aktif='Y'";
    } elseif ($filter_status === 'nonaktif') {
        $where_conditions[] = "aktif='N'";
    }

    $where = count($where_conditions) > 0 ? "WHERE " . implode(' AND ', $where_conditions) : '';
    return [$where, $search, $filter_status];
}

/* =====================================================================
   HELPER: cek apakah sebuah sparepart SUDAH PERNAH dipakai di permintaan
   perbaikan manapun (termasuk yang sudah Selesai / riwayat lama).
   Dipakai untuk mengunci HARGA saat edit, dan untuk mencegah hapus.
===================================================================== */
function sparepartJumlahPemakaian($connection, $id_sparepart) {
    $id_sparepart = mysqli_real_escape_string($connection, $id_sparepart);
    $q = mysqli_query($connection, "SELECT COUNT(*) AS total FROM sparepart_detail WHERE id_sparepart='$id_sparepart'");
    $r = $q ? mysqli_fetch_assoc($q) : ['total' => 0];
    return (int) ($r['total'] ?? 0);
}

/* =====================================================================
   FITUR: DOWNLOAD FORMAT / TEMPLATE (CSV) - PHP NATIVE, TANPA LIBRARY
   Harus diproses SEBELUM ada output HTML apapun.
===================================================================== */
if (isset($_GET['template']) && $_GET['template'] == '1') {

    $dataRows = [
        ['kode', 'nama', 'satuan', 'harga', 'status'],
        ['SP001', 'FILTER OLI', '1', '75000', 'Y'],
    ];

    native_csv_output($dataRows, 'template_import_sparepart.csv');
}

/* =====================================================================
   FITUR: EXPORT DATA (XLS untuk dilihat/dibuka di Excel) - PHP NATIVE
   Mengikuti filter status & pencarian yang SEDANG AKTIF di halaman
   (tidak dibatasi pagination -> semua data yang cocok filter ikut).
   Harus diproses SEBELUM ada output HTML apapun.
===================================================================== */
if (isset($_GET['export']) && $_GET['export'] == '1') {

    [$exp_where, , ] = buildSparepartWhere($connection);

    $exp_q = mysqli_query($connection, "
        SELECT sp.*, (SELECT COUNT(*) FROM sparepart_detail sd WHERE sd.id_sparepart = sp.id_sparepart) AS jumlah_pemakaian
        FROM sparepart sp $exp_where
        ORDER BY sp.aktif DESC, sp.kode_sparepart ASC
    ");

    $dataRows = [['No', 'Kode', 'Nama Sparepart', 'Satuan', 'Harga', 'Status', 'Pernah Dipakai', 'Tanggal Dibuat']];

    $exp_no = 1;
    if ($exp_q && mysqli_num_rows($exp_q) > 0) {
        while ($d = mysqli_fetch_assoc($exp_q)) {
            $dataRows[] = [
                $exp_no++,
                $d['kode_sparepart'],
                $d['nama_sparepart'],
                $d['satuan'],
                $d['harga'],
                $d['aktif'] == 'Y' ? 'Aktif' : 'Tidak Aktif',
                ((int) $d['jumlah_pemakaian'] > 0) ? 'Ya (' . $d['jumlah_pemakaian'] . 'x)' : 'Belum',
                $d['created_at'] ?? '',
            ];
        }
    }

    native_xlsx_output([
        ['name' => 'Data Sparepart', 'rows' => $dataRows, 'headerRows' => 1, 'widths' => [5, 6, 14, 32, 10, 14, 12, 14, 18]],
    ], 'export_sparepart_' . date('Ymd_His') . '.xls');
}

/* =====================================================================
   FITUR: IMPORT DATA (CSV) - PHP NATIVE, TANPA LIBRARY
   Format kolom WAJIB: kode | nama | satuan | harga | status

   PRINSIP "GAGAL YA GAGAL, JANGAN DIPAKSA MASUK" (sama seperti
   rekanan.php, kendaraan.php, & master_jasa.php):
   - Ekstensi salah / file tak terbaca / header tidak persis sesuai
     -> STOP TOTAL, tidak ada satupun baris yang masuk ke database.
   - Setelah struktur file valid, tiap baris data divalidasi lagi: kode,
     nama & satuan wajib diisi, harga wajib angka > 0, dan kode yang
     berstatus Aktif tidak boleh bentrok dengan kode Aktif lain (baik
     yang sudah ada di database maupun sesama baris di file yang sama).
     Baris yang tidak lolos DILEWATI (bukan menggagalkan seluruh file)
     dan dilaporkan jelas baris ke berapa saja.
===================================================================== */
if (isset($_POST['import_submit'])) {

    if (!isset($_FILES['file_import']) || $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
        echo "<script>
            alert('⚠️ Tidak ada file yang diupload atau terjadi error saat upload!');
            window.location.href='master_sparepart.php';
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
            window.location.href='master_sparepart.php';
        </script>";
        exit;
    }

    // ===== VALIDASI 2: FILE BISA DIBACA =====
    try {
        $rows = native_csv_read($fileTmp);
    } catch (Exception $ex) {
        echo "<script>
            alert('❌ Import GAGAL!\\n\\nFile tidak bisa dibaca atau isinya rusak/kosong.\\nSilakan download ulang \\'Download Format\\' dan isi langsung di file tersebut, jangan ubah strukturnya.\\n\\nTidak ada data yang diproses.');
            window.location.href='master_sparepart.php';
        </script>";
        exit;
    }

    // ===== VALIDASI 3: STRUKTUR HEADER HARUS PERSIS =====
    $expectedHeader = ['kode', 'nama', 'satuan', 'harga', 'status'];
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
            alert('❌ Import GAGAL!\\n\\nStruktur kolom file tidak sesuai format yang diminta.\\nHeader baris pertama WAJIB persis: kode, nama, satuan, harga, status (urutan tidak boleh diubah).\\n\\nSilakan download ulang \\'Download Format\\', isi datanya langsung di file itu, jangan ubah/hapus/tambah kolom.\\n\\nTidak ada data yang diproses.');
            window.location.href='master_sparepart.php';
        </script>";
        exit;
    }

    array_shift($rows);

    if (count($rows) === 0) {
        echo "<script>
            alert('⚠️ Import dibatalkan: file hanya berisi header, tidak ada baris data di bawahnya.');
            window.location.href='master_sparepart.php';
        </script>";
        exit;
    }

    // ===== PROSES BARIS DATA (baru sampai sini kalau struktur file sudah pasti valid) =====
    $berhasil = 0;
    $dilewati = 0;
    $baris_dilewati = [];
    $rowNo = 1; // baris 1 = header, data dimulai dari baris 2
    $kode_aktif_batch = []; // cegah bentrok kode aktif SESAMA baris di file yang sama

    foreach ($rows as $row) {
        $rowNo++;

        $kode_raw   = isset($row[0]) ? trim((string) $row[0]) : '';
        $nama_raw   = isset($row[1]) ? trim((string) $row[1]) : '';
        $satuan_raw = isset($row[2]) ? trim((string) $row[2]) : '';
        $harga_raw  = isset($row[3]) ? trim((string) $row[3]) : '';
        $status_raw = isset($row[4]) ? trim((string) $row[4]) : '';

        // Lewati baris kosong total
        if ($kode_raw === '' && $nama_raw === '' && $satuan_raw === '' && $harga_raw === '') continue;

        if ($kode_raw === '' || $nama_raw === '' || $satuan_raw === '') {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
            continue;
        }

        $harga = (int) preg_replace('/[^0-9]/', '', $harga_raw);
        if ($harga <= 0) {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
            continue;
        }

        $status_match = 'Y';
        if ($status_raw !== '') {
            $su = strtoupper($status_raw);
            if ($su === 'Y' || $su === 'AKTIF') {
                $status_match = 'Y';
            } elseif ($su === 'N' || $su === 'TIDAK AKTIF') {
                $status_match = 'N';
            } else {
                $dilewati++;
                $baris_dilewati[] = $rowNo;
                continue;
            }
        }

        // Cek bentrok kode AKTIF (baik yang sudah ada di database, maupun
        // yang baru saja berhasil ditambahkan dari baris lain di file yang sama)
        if ($status_match === 'Y') {
            if (in_array($kode_raw, $kode_aktif_batch, true)) {
                $dilewati++;
                $baris_dilewati[] = $rowNo;
                continue;
            }
            $kode_esc_check = mysqli_real_escape_string($connection, $kode_raw);
            $cek_aktif = mysqli_query($connection, "SELECT id_sparepart FROM sparepart WHERE kode_sparepart='$kode_esc_check' AND aktif='Y'");
            if ($cek_aktif && mysqli_num_rows($cek_aktif) > 0) {
                $dilewati++;
                $baris_dilewati[] = $rowNo;
                continue;
            }
        }

        $kode_esc   = mysqli_real_escape_string($connection, $kode_raw);
        $nama_esc   = mysqli_real_escape_string($connection, strtoupper($nama_raw));
        $satuan_esc = mysqli_real_escape_string($connection, strtoupper($satuan_raw));
        $status_esc = mysqli_real_escape_string($connection, $status_match);

        $ok = mysqli_query($connection, "INSERT INTO sparepart
            (kode_sparepart, nama_sparepart, satuan, harga, aktif)
            VALUES ('$kode_esc','$nama_esc','$satuan_esc','$harga','$status_esc')");

        if ($ok) {
            $berhasil++;
            if ($status_match === 'Y') {
                $kode_aktif_batch[] = $kode_raw;
            }
        } else {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
        }
    }

    if ($berhasil === 0 && $dilewati === 0) {
        echo "<script>
            alert('⚠️ Tidak ada data valid untuk diimport.');
            window.location.href='master_sparepart.php';
        </script>";
        exit;
    }

    $pesan = "✅ Import selesai!\\n\\nBerhasil ditambahkan: $berhasil data";
    if ($dilewati > 0) {
        $pesan .= "\\nDilewati: $dilewati data (baris ke: " . implode(', ', $baris_dilewati) . ")\\nPenyebab umum: kode/nama/satuan kosong, harga tidak valid, kode bentrok dengan sparepart lain yang masih Aktif, atau status tidak dikenali.";
    }

    echo "<script>
        alert('" . addslashes($pesan) . "');
        window.location.href='master_sparepart.php?success=import&count=$berhasil&skip=$dilewati';
    </script>";
    exit;
}

/* =====================================================================
   FITUR: AKSI MASSAL (CENTANG) - Nonaktifkan / Aktifkan / Hapus banyak sekaligus
===================================================================== */
if (isset($_POST['bulk_action']) && isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {

    $action = $_POST['bulk_action'];
    $ids = array_values(array_filter(array_map('intval', $_POST['selected_ids']), function ($v) {
        return $v > 0;
    }));

    $backQuery = isset($_POST['back_query']) ? $_POST['back_query'] : '';
    $redirect  = 'master_sparepart.php' . ($backQuery ? '?' . $backQuery : '');

    if (count($ids) === 0) {
        echo "<script>
            alert('⚠️ Tidak ada data yang dicentang!');
            window.location.href='" . $redirect . "';
        </script>";
        exit;
    }

    $idListSql = implode(',', $ids);

    if ($action === 'nonaktif') {

        mysqli_query($connection, "UPDATE sparepart SET aktif='N' WHERE id_sparepart IN ($idListSql)");
        $jumlah = count($ids);

        echo "<script>
            alert('✅ $jumlah data sparepart berhasil dinonaktifkan!');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_nonaktif';
        </script>";
        exit;

    } elseif ($action === 'aktifkan') {

        // Setiap sparepart yang mau diaktifkan tetap dicek satu-satu:
        // kodenya tidak boleh bentrok dengan sparepart lain yang masih
        // Aktif (aturan sama seperti aktifkan satuan).
        $berhasil_aktif = 0;
        $gagal_bentrok  = 0;

        foreach ($ids as $id) {
            $r = mysqli_fetch_assoc(mysqli_query($connection, "SELECT kode_sparepart FROM sparepart WHERE id_sparepart='$id'"));
            if (!$r) continue;

            $kode_esc = mysqli_real_escape_string($connection, $r['kode_sparepart']);
            $cek = mysqli_query($connection, "
                SELECT id_sparepart FROM sparepart
                WHERE kode_sparepart='$kode_esc' AND id_sparepart != '$id' AND aktif='Y'
            ");

            if ($cek && mysqli_num_rows($cek) > 0) {
                $gagal_bentrok++;
                continue;
            }

            mysqli_query($connection, "UPDATE sparepart SET aktif='Y' WHERE id_sparepart='$id'");
            $berhasil_aktif++;
        }

        $pesan = "✅ $berhasil_aktif data sparepart berhasil diaktifkan kembali!";
        if ($gagal_bentrok > 0) {
            $pesan .= "\\n⚠️ $gagal_bentrok data TIDAK bisa diaktifkan karena kodenya masih dipakai sparepart lain yang sedang Aktif.";
        }

        echo "<script>
            alert('" . addslashes($pesan) . "');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_aktifkan';
        </script>";
        exit;

    } elseif ($action === 'hapus') {

        // Sparepart yang sudah pernah dipakai di permintaan perbaikan
        // (termasuk riwayat) otomatis dilewati -> tidak bisa dihapus,
        // hanya bisa dinonaktifkan.
        $berhasil_hapus = 0;
        $gagal_relasi   = 0;

        foreach ($ids as $id) {
            if (sparepartJumlahPemakaian($connection, $id) > 0) {
                $gagal_relasi++;
                continue;
            }
            mysqli_query($connection, "DELETE FROM sparepart WHERE id_sparepart='$id'");
            $berhasil_hapus++;
        }

        $pesan = "✅ $berhasil_hapus data sparepart berhasil dihapus!";
        if ($gagal_relasi > 0) {
            $pesan .= "\\n⚠️ $gagal_relasi data TIDAK dihapus karena sudah pernah dipakai di permintaan perbaikan / riwayat (hanya bisa dinonaktifkan).";
        }

        echo "<script>
            alert('" . addslashes($pesan) . "');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_hapus';
        </script>";
        exit;
    }
}

// Mulai output buffering untuk menghindari error header
ob_start();

include "navbar.php";

/* =====================
   SIMPAN / UPDATE
===================== */
if (isset($_POST['simpan'])) {

    $id_sparepart = trim($_POST['id_sparepart']);
    $kode         = mysqli_real_escape_string($connection, trim($_POST['kode_sparepart']));
    $nama         = mysqli_real_escape_string($connection, trim($_POST['nama_sparepart']));
    $satuan       = mysqli_real_escape_string($connection, trim($_POST['satuan']));
    $harga_input  = mysqli_real_escape_string($connection, trim($_POST['harga']));
    $aktif        = mysqli_real_escape_string($connection, $_POST['aktif']);

    // ===== KUNCI HARGA UNTUK SPAREPART YANG SUDAH PERNAH DIPAKAI =====
    // Pengecekan sisi server (bukan cuma sisi tampilan), supaya harga
    // tidak bisa diubah sekalipun orang mengakali lewat DevTools/form
    // yang dimodifikasi. Kalau sparepart ini sudah pernah masuk ke
    // permintaan perbaikan manapun (termasuk yang sudah Selesai /
    // riwayat lama), harga yang dipakai TETAP harga lama di database,
    // apapun yang dikirim dari form.
    $harga = $harga_input;
    if ($id_sparepart != '' && sparepartJumlahPemakaian($connection, $id_sparepart) > 0) {
        $row_lama = mysqli_fetch_assoc(mysqli_query($connection, "SELECT harga FROM sparepart WHERE id_sparepart='$id_sparepart'"));
        if ($row_lama) {
            $harga = mysqli_real_escape_string($connection, $row_lama['harga']);
        }
    }

    if ($id_sparepart == '') {
        $cek = mysqli_query($connection, "
            SELECT id_sparepart FROM sparepart 
            WHERE kode_sparepart='$kode' 
            AND aktif='Y'
            LIMIT 1
        ");
    } else {
        $cek = mysqli_query($connection, "
            SELECT id_sparepart FROM sparepart 
            WHERE kode_sparepart='$kode' 
            AND aktif='Y'
            AND id_sparepart != '$id_sparepart'
            LIMIT 1
        ");
    }

    if (mysqli_num_rows($cek) > 0) {
        echo "<script>
            alert('❌ Kode sparepart \"$kode\" sudah digunakan oleh sparepart yang masih AKTIF!\\n\\nTips: Nonaktifkan sparepart lama terlebih dahulu jika ingin menggunakan kode yang sama dengan harga baru.');
            window.history.back();
        </script>";
        exit;
    }

    if ($id_sparepart == '') {
        mysqli_query($connection, "
            INSERT INTO sparepart (kode_sparepart, nama_sparepart, satuan, harga, aktif)
            VALUES ('$kode','$nama','$satuan','$harga','Y')
        ");
        echo "<script>
            alert('✅ Data sparepart berhasil ditambahkan');
            window.location.href='master_sparepart.php';
        </script>";
        exit;
    } else {
        mysqli_query($connection, "
            UPDATE sparepart SET
                kode_sparepart='$kode',
                nama_sparepart='$nama',
                satuan='$satuan',
                harga='$harga',
                aktif='$aktif'
            WHERE id_sparepart='$id_sparepart'
        ");
        echo "<script>
            alert('✅ Data sparepart berhasil diperbarui');
            window.location.href='master_sparepart.php';
        </script>";
        exit;
    }
}

/* =====================
   DELETE
===================== */
if (isset($_GET['hapus'])) {
    $id_sparepart = mysqli_real_escape_string($connection, $_GET['hapus']);
    
    $has_relation = false;
    $table_with_relation = '';
    
    $check = mysqli_query($connection, "
        SELECT COUNT(*) as total FROM sparepart_detail 
        WHERE id_sparepart='$id_sparepart' LIMIT 1
    ");
    
    if ($check) {
        $result = mysqli_fetch_assoc($check);
        if ($result['total'] > 0) {
            $has_relation = true;
            $table_with_relation = 'sparepart_detail';
        }
    }
    
    if ($has_relation) {
        echo "<script>
            alert('⚠️ Data tidak dapat dihapus!\\n\\nData sparepart ini masih digunakan pada tabel " . $table_with_relation . "\\n\\nHapus data terkait terlebih dahulu atau nonaktifkan data ini.');
            window.location.href='master_sparepart.php?error=relasi&table=$table_with_relation';
        </script>";
        exit;
    } else {
        mysqli_query($connection,"DELETE FROM sparepart WHERE id_sparepart='$id_sparepart'");
        echo "<script>window.location.href='master_sparepart.php?success=delete';</script>";
        exit;
    }
}

/* =====================
   NONAKTIFKAN
===================== */
if (isset($_GET['nonaktif'])) {
    $id_sparepart = mysqli_real_escape_string($connection, $_GET['nonaktif']);
    mysqli_query($connection,"UPDATE sparepart SET aktif='N' WHERE id_sparepart='$id_sparepart'");
    echo "<script>window.location.href='master_sparepart.php?success=nonaktif';</script>";
    exit;
}

/* =====================
   AKTIFKAN KEMBALI
===================== */
if (isset($_GET['aktifkan'])) {
    $id_sparepart = mysqli_real_escape_string($connection, $_GET['aktifkan']);
    
    $check_data = mysqli_query($connection, "SELECT kode_sparepart, nama_sparepart FROM sparepart WHERE id_sparepart='$id_sparepart'");
    $data = mysqli_fetch_assoc($check_data);
    $kode = $data['kode_sparepart'];
    
    $cek_duplikat = mysqli_query($connection, "
        SELECT id_sparepart, nama_sparepart FROM sparepart 
        WHERE kode_sparepart='$kode' 
        AND aktif='Y' 
        AND id_sparepart != '$id_sparepart'
        LIMIT 1
    ");
    
    if (mysqli_num_rows($cek_duplikat) > 0) {
        $sparepart_aktif = mysqli_fetch_assoc($cek_duplikat);
        echo "<script>
            alert('❌ Tidak dapat mengaktifkan!\\n\\nKode \"$kode\" sudah digunakan oleh:\\n" . addslashes($sparepart_aktif['nama_sparepart']) . "\\n\\nSilakan:\\n1. Nonaktifkan sparepart tersebut terlebih dahulu, ATAU\\n2. Ubah kode sparepart ini sebelum mengaktifkan');
            window.location.href='master_sparepart.php?error=duplikat_aktif&kode=$kode';
        </script>";
        exit;
    }
    
    mysqli_query($connection,"UPDATE sparepart SET aktif='Y' WHERE id_sparepart='$id_sparepart'");
    echo "<script>window.location.href='master_sparepart.php?success=aktifkan';</script>";
    exit;
}

/* =====================
   EDIT
===================== */
$edit = null;
$sudah_terpakai = false;
if (isset($_GET['edit'])) {
    $id_edit = mysqli_real_escape_string($connection, $_GET['edit']);
    $q = mysqli_query($connection, "
        SELECT sp.*, (SELECT COUNT(*) FROM sparepart_detail sd WHERE sd.id_sparepart = sp.id_sparepart) AS jumlah_pemakaian
        FROM sparepart sp WHERE sp.id_sparepart='$id_edit'
    ");
    $edit = mysqli_fetch_assoc($q);
    $sudah_terpakai = $edit && (int) $edit['jumlah_pemakaian'] > 0;
}

/* =====================
   SEARCH & FILTER STATUS
===================== */
[$where, $search, $filter_status] = buildSparepartWhere($connection);

// ===== PAGINATION: 50 data per halaman =====
$per_page = 50;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

$result_count = mysqli_query($connection, "SELECT COUNT(*) AS total FROM sparepart $where");
$row_count    = $result_count ? mysqli_fetch_assoc($result_count) : ['total' => 0];
$total_rows   = (int)($row_count['total'] ?? 0);
$total_pages  = $total_rows > 0 ? (int)ceil($total_rows / $per_page) : 1;

// Query string filter yang sedang aktif (dipakai untuk link export & back_query bulk action)
function currentSparepartFilterQuery() {
    $params = [];
    if (isset($_GET['search']) && $_GET['search'] !== '') $params['search'] = $_GET['search'];
    if (isset($_GET['status']) && $_GET['status'] !== '') $params['status'] = $_GET['status'];
    if (isset($_GET['page'])   && $_GET['page'] !== '')   $params['page']   = $_GET['page'];
    return http_build_query($params);
}
$filterQueryString = currentSparepartFilterQuery();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Sparepart</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background:rgb(185, 224, 204);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        .container {
            margin-left: 250px;
            transition: margin-left 0.3s ease;
        }
        @media (max-width: 1023px) {
            .container {
                margin-left: 0;
                padding-top: 70px;
            }
        }
        .scroll-container {
            max-height: calc(100vh - 250px);
            overflow-y: auto;
            overflow-x: auto;
        }
        .scroll-container::-webkit-scrollbar { width: 8px; }
        .scroll-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .scroll-container::-webkit-scrollbar-thumb { background: black; border-radius: 10px; }
        .scroll-container::-webkit-scrollbar-thumb:hover { background: #5568d3; }
        .alert { animation: slideDown 0.3s ease-out; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .form-input:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .btn-action { transition: all 0.3s ease; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .card { backdrop-filter: blur(10px); background: rgba(255,255,255,0.95); }
        .table-row { transition: all 0.2s ease; }
        .table-row:hover { background: rgba(102,126,234,0.05); transform: scale(1.01); }
        @media (max-width: 1023px) {
            .main-content { padding-top: 70px; }
            .container { margin-left: 0 !important; padding-top: 70px; }
            .scroll-container { max-height: 500px; }
        }
        @media (max-width: 640px) {
            .card { padding: 1rem !important; }
            .scroll-container { max-height: 400px; }
            table { font-size: 0.75rem; }
            .btn-action { padding: 0.4rem 0.6rem !important; font-size: 0.7rem !important; }
        }
        @media (min-width: 1536px) {
            .container { max-width: calc(100% - 250px) !important; margin-left: 250px; padding-left: 2rem; padding-right: 2rem; }
        }
        @media (min-width: 1280px) and (max-width: 1535px) {
            .container { max-width: calc(100% - 250px) !important; margin-left: 250px; padding-left: 1.5rem; padding-right: 1.5rem; }
        }
        @media (min-width: 1024px) and (max-width: 1279px) {
            .container { max-width: calc(100% - 250px) !important; margin-left: 250px; padding-left: 1rem; padding-right: 1rem; }
        }
        .filter-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 35px;
        }

        /* Toolbar Import/Export */
        .toolbar-import-export { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; }
        .btn-sm { padding: 9px 16px; font-size: 0.85rem; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s ease; }
        .btn-sm:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-secondary-sm { background: #6c757d; color: white; }
        .btn-import-sm { background: linear-gradient(135deg,#11998e,#38ef7d); color: #063; }
        .btn-excel-sm  { background: linear-gradient(135deg,#1d976c,#93f9b9); color: #063; }

        /* Note box collapsible */
        .note-box { background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 8px; margin-bottom: 15px; font-size: 0.85rem; color: #333; overflow: hidden; }
        .note-box ul { margin: 6px 0 0 18px; padding: 0; }
        .note-box li { margin-bottom: 4px; }

        /* Checkbox & Bulk Action */
        .checkbox-col { width: 40px; text-align: center; }
        tbody tr.row-selected { background: rgba(102,126,234,0.12) !important; }
        .bulk-bar {
            display: none; align-items: center; gap: 10px;
            background: #fff8e1; border: 2px dashed #f0b429; border-radius: 12px;
            padding: 10px 16px; margin-bottom: 15px; flex-wrap: wrap;
        }
        .bulk-bar.active { display: flex; }
        .bulk-bar span { font-weight: 600; color: #7a5b00; font-size: 0.9rem; }
        .btn-orange-sm { background: linear-gradient(135deg,#ff9966,#ff5e62); color: white; }
        .btn-success-sm { background: linear-gradient(135deg,#56ab2f,#a8e063); color: white; }
        .btn-danger-sm { background: linear-gradient(135deg,#eb3349,#f45c43); color: white; }

        /* Modal (Import) */
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
        .modal-body { padding: 20px; }

        /* Lock badge (harga terkunci) */
        .lock-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px; border-radius: 10px; font-size: 10px; font-weight: 700;
            background: linear-gradient(135deg,#fff3cd,#ffe69c); color: #856404; border: 1px solid #ffd54f;
        }
    </style>
</head>
<body class="font-sans">

<div class="container mx-auto px-2 sm:px-4 py-4 sm:py-8 max-w-full">

    <!-- Alert Messages -->
    <?php if (isset($_GET['success'])): ?>
    <div class="alert bg-green-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-2xl mr-3"></i>
            <span>
                <?php
                if ($_GET['success'] == 'create')   echo 'Data berhasil ditambahkan!';
                elseif ($_GET['success'] == 'update')  echo 'Data berhasil diperbarui!';
                elseif ($_GET['success'] == 'delete')  echo 'Data berhasil dihapus permanen!';
                elseif ($_GET['success'] == 'nonaktif') echo 'Data berhasil dinonaktifkan!';
                elseif ($_GET['success'] == 'aktifkan') echo 'Data berhasil diaktifkan kembali!';
                elseif ($_GET['success'] == 'bulk_nonaktif') echo 'Data terpilih berhasil dinonaktifkan!';
                elseif ($_GET['success'] == 'bulk_aktifkan') echo 'Data terpilih berhasil diproses (aktifkan)!';
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
    <div class="alert bg-red-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
            <span>
                <?php
                if ($_GET['error'] == 'relasi') {
                    $table_name = isset($_GET['table']) ? htmlspecialchars($_GET['table']) : 'transaksi';
                    echo 'Data tidak dapat dihapus! Sparepart ini masih digunakan pada <strong>' . $table_name . '</strong>';
                } elseif ($_GET['error'] == 'duplikat_aktif') {
                    $kode = isset($_GET['kode']) ? htmlspecialchars($_GET['kode']) : '';
                    echo 'Tidak dapat mengaktifkan! Kode <strong>' . $kode . '</strong> sudah digunakan oleh sparepart lain yang masih aktif.';
                }
                ?>
            </span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
    <div class="alert bg-red-500 text-white px-6 py-4 rounded-lg mb-6 flex items-center justify-between shadow-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
            <span><?= $error ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 lg:gap-6">

        <!-- Form Input (Kiri) -->
        <div class="xl:col-span-5 2xl:col-span-4">
            <div class="card rounded-2xl shadow-2xl p-4 sm:p-6 sticky top-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-edit mr-2 text-purple-600"></i>
                    <?= isset($edit) ? 'Edit Data' : 'Tambah Data' ?>
                </h2>

                <?php if ($sudah_terpakai): ?>
                <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-3 mb-4">
                    <div class="flex items-start gap-2">
                        <i class="fas fa-lock text-yellow-600 mt-0.5"></i>
                        <p class="text-xs text-yellow-800">
                            <strong>Sparepart ini sudah pernah dipakai</strong> di <strong><?= (int) $edit['jumlah_pemakaian'] ?> permintaan perbaikan</strong> (termasuk yang sudah selesai / riwayat lama).
                            Untuk mencegah selisih perhitungan biaya di kemudian hari, <strong>harga tidak bisa diubah lagi</strong>.
                            Kalau butuh harga baru: nonaktifkan sparepart ini, lalu <strong>buat data sparepart baru</strong> dengan harga terkini.
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="id_sparepart" value="<?= $edit['id_sparepart'] ?? '' ?>">

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-barcode mr-1"></i> Kode Sparepart
                        </label>
                        <input type="text"
                               name="kode_sparepart"
                               value="<?= $edit['kode_sparepart'] ?? '' ?>"
                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                               placeholder="Contoh: SP001"
                               required>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Boleh sama dengan kode yang tidak aktif
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-box mr-1"></i> Nama Sparepart
                        </label>
                        <input type="text"
                               name="nama_sparepart"
                               id="namaSparepart"
                               value="<?= $edit['nama_sparepart'] ?? '' ?>"
                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                               placeholder="Contoh: Filter Oli"
                               style="text-transform: uppercase;"
                               required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-balance-scale mr-1"></i> Satuan
                        </label>
                        <input type="text"
                               name="satuan"
                               id="satuanInput"
                               value="<?= $edit['satuan'] ?? '' ?>"
                               class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                               placeholder="Contoh: UNIT, PCS, KG"
                               style="text-transform: uppercase;"
                               required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-money-bill-wave mr-1"></i> Harga (Rp)
                            <?php if ($sudah_terpakai): ?>
                            <span class="lock-badge"><i class="fas fa-lock"></i> Terkunci</span>
                            <?php endif; ?>
                        </label>
                        <input type="text"
                               name="harga"
                               id="hargaInput"
                               value="<?= isset($edit['harga']) ? number_format($edit['harga'], 0, ',', '.') : '' ?>"
                               <?= $sudah_terpakai ? 'readonly' : '' ?>
                               class="form-input w-full px-4 py-3 border-2 rounded-lg focus:outline-none transition-all <?= $sudah_terpakai ? 'bg-gray-100 border-gray-300 text-gray-500 cursor-not-allowed' : 'border-gray-300 focus:border-purple-500' ?>"
                               placeholder="Contoh: 150000 atau 150.000"
                               required>
                        <?php if ($sudah_terpakai): ?>
                        <p class="text-xs text-yellow-700 mt-1">
                            <i class="fas fa-lock mr-1"></i>
                            Harga dikunci karena sparepart ini sudah pernah dipakai di riwayat permintaan.
                        </p>
                        <?php else: ?>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Format otomatis:
                        </p>
                        <p class="text-xs text-blue-600 mt-1">
                            <i class="fas fa-lightbulb mr-1"></i>
                            Ketik angka saja, titik koma otomatis. Contoh: <strong>150000</strong> → <strong>Rp 150.000</strong>
                        </p>
                        <?php endif; ?>
                    </div>

                    <?php if (isset($edit)): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-toggle-on mr-1"></i> Status Aktif
                        </label>
                        <select name="aktif"
                                class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all">
                            <option value="Y" <?= ($edit['aktif'] ?? 'Y') == 'Y' ? 'selected' : '' ?>>✅ Aktif</option>
                            <option value="N" <?= ($edit['aktif'] ?? 'Y') == 'N' ? 'selected' : '' ?>>❌ Tidak Aktif</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            Sparepart yang tidak aktif tidak akan muncul di daftar pilihan
                        </p>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="aktif" value="Y">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                        <p class="text-xs text-green-700">
                            <i class="fas fa-check-circle mr-1"></i>
                            Data baru otomatis berstatus <strong>AKTIF</strong>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="pt-4 space-y-2">
                        <button type="submit"
                                name="simpan"
                                class="btn-action w-full text-white py-3 rounded-lg font-semibold shadow-lg"
                                style="background:rgb(9, 120, 83);">
                            <i class="fas fa-save mr-2"></i>
                            <?= isset($edit) ? 'Update Data' : 'Simpan Data' ?>
                        </button>

                        <?php if (isset($edit)): ?>
                        <a href="master_sparepart.php"
                           class="btn-action block w-full bg-gray-500 text-white py-3 rounded-lg font-semibold hover:bg-gray-600 text-center shadow-lg">
                            <i class="fas fa-times mr-2"></i>
                            Batal Edit
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Data Table (Kanan) -->
        <div class="xl:col-span-7 2xl:col-span-8">
            <div class="card rounded-2xl shadow-2xl p-4 sm:p-6">

                <!-- ===================== TOOLBAR IMPORT / EXPORT ===================== -->
                <div class="toolbar-import-export">
                    <a href="master_sparepart.php?template=1" class="btn-sm btn-secondary-sm">
                        <i class="fas fa-file-arrow-down"></i> Download Format
                    </a>
                    <button type="button" class="btn-sm btn-import-sm" onclick="showImportModal()">
                        <i class="fas fa-file-import"></i> Import Data
                    </button>
                    <a href="master_sparepart.php?export=1<?= $filterQueryString ? '&' . htmlspecialchars($filterQueryString) : '' ?>" class="btn-sm btn-excel-sm">
                        <i class="fas fa-file-excel"></i> Export Excel <?= ($search !== '' || $filter_status !== 'semua') ? '(Sesuai Filter)' : '(Semua Data)' ?>
                    </a>
                </div>

                <!-- ===================== CATATAN IMPORT (COLLAPSIBLE) ===================== -->
                <div class="note-box" style="padding: 0;">
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
                            <li>Header baris pertama WAJIB persis: <strong>kode, nama, satuan, harga, status</strong> — kalau strukturnya berubah, import akan <strong>GAGAL TOTAL</strong> (tidak ada data yang dipaksa masuk).</li>
                            <li>Kolom <strong>kode</strong>, <strong>nama</strong> & <strong>satuan</strong> wajib diisi, kolom <strong>harga</strong> wajib angka lebih dari 0.</li>
                            <li>Kolom <strong>status</strong> isi <strong>Y</strong> (Aktif) atau <strong>N</strong> (Tidak Aktif), kosong = otomatis Aktif.</li>
                            <li>Kode yang berstatus Aktif tidak boleh sama dengan kode sparepart lain yang masih Aktif — baris yang bentrok otomatis dilewati.</li>
                            <li>Import hanya MENAMBAH data baru, bukan mengubah data lama (untuk itu pakai tombol Edit).</li>
                            <li>Saat <strong>Export</strong>, data yang ditarik mengikuti filter/pencarian yang sedang aktif di halaman ini.</li>
                        </ul>
                    </div>
                </div>

                <!-- Filter & Search Bar -->
                <div class="mb-6 space-y-3">
                    <div class="flex gap-2 items-center">
                        <label class="text-sm font-semibold text-gray-700 whitespace-nowrap">
                            <i class="fas fa-filter mr-1"></i> Filter:
                        </label>
                        <select id="filterStatus"
                                class="filter-select flex-1 px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all">
                            <option value="semua"   <?= $filter_status == 'semua'    ? 'selected' : '' ?>>🔍 Semua Status</option>
                            <option value="aktif"   <?= $filter_status == 'aktif'    ? 'selected' : '' ?>>✅ Aktif Saja</option>
                            <option value="nonaktif"<?= $filter_status == 'nonaktif' ? 'selected' : '' ?>>❌ Tidak Aktif Saja</option>
                        </select>
                    </div>

                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <input type="text"
                                   id="searchInput"
                                   value="<?= htmlspecialchars($search) ?>"
                                   class="w-full pl-12 pr-10 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:outline-none transition-all"
                                   placeholder="Ketik untuk mencari kode atau nama sparepart...">
                            <?php if ($search != ''): ?>
                            <button onclick="clearSearch()"
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times-circle text-xl"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Table Header with Stats -->
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-list mr-2 text-purple-600"></i>
                        Data Sparepart
                    </h2>
                    <?php
                    $total_aktif    = mysqli_num_rows(mysqli_query($connection, "SELECT * FROM sparepart WHERE aktif='Y'"));
                    $total_nonaktif = mysqli_num_rows(mysqli_query($connection, "SELECT * FROM sparepart WHERE aktif='N'"));
                    ?>
                    <div class="flex gap-2">
                        <span class="px-3 py-1 bg-green-500 text-white rounded-full text-xs font-semibold" id="badgeAktif">
                            ✓ Aktif: <?= $total_aktif ?>
                        </span>
                        <span class="px-3 py-1 bg-red-500 text-white rounded-full text-xs font-semibold" id="badgeNonaktif">
                            ✗ Nonaktif: <?= $total_nonaktif ?>
                        </span>
                    </div>
                </div>

                <!-- ===================== BAR AKSI MASSAL (muncul kalau ada yang dicentang) ===================== -->
                <div class="bulk-bar" id="bulkBar">
                    <span><i class="fas fa-square-check"></i> <span id="bulkCount">0</span> data dipilih</span>
                    <button type="button" class="btn-sm btn-orange-sm" id="btnBulkNonaktif" onclick="submitBulk('nonaktif')">
                        <i class="fas fa-eye-slash"></i> Nonaktifkan Terpilih
                    </button>
                    <button type="button" class="btn-sm btn-success-sm" id="btnBulkAktifkan" onclick="submitBulk('aktifkan')" style="display:none;">
                        <i class="fas fa-check"></i> Aktifkan Terpilih
                    </button>
                    <button type="button" class="btn-sm btn-danger-sm" onclick="submitBulk('hapus')">
                        <i class="fas fa-trash"></i> Hapus Terpilih
                    </button>
                    <button type="button" class="btn-sm btn-secondary-sm" onclick="clearSelection()">
                        <i class="fas fa-xmark"></i> Batal Pilih
                    </button>
                </div>

                <!-- FORM pembungkus tabel untuk aksi massal -->
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                    <input type="hidden" name="back_query" value="<?= htmlspecialchars($filterQueryString) ?>">

                    <!-- Scrollable Table -->
                    <div class="scroll-container rounded-lg border-2 border-gray-200 overflow-x-auto">
                        <table class="w-full table-fixed min-w-[1150px]">
                            <thead class="text-white sticky top-0" style="background:rgb(9, 120, 83);">
                                <tr>
                                    <th class="px-2 py-4 checkbox-col">
                                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" title="Pilih semua">
                                    </th>
                                    <th class="px-3 py-4 text-left font-semibold w-[13%]">Kode</th>
                                    <th class="px-3 py-4 text-left font-semibold w-[25%]">Nama Sparepart</th>
                                    <th class="px-3 py-4 text-center font-semibold w-[9%]">Satuan</th>
                                    <th class="px-3 py-4 text-right font-semibold w-[12%]">Harga</th>
                                    <th class="px-3 py-4 text-center font-semibold w-[9%]">Status</th>
                                    <th class="px-3 py-4 text-center font-semibold w-[24%]">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="tableBody">
                                <?php
                                $q  = mysqli_query(
                                    $connection,
                                    "SELECT sp.*, (SELECT COUNT(*) FROM sparepart_detail sd WHERE sd.id_sparepart = sp.id_sparepart) AS jumlah_pemakaian
                                     FROM sparepart sp $where ORDER BY sp.aktif DESC, sp.kode_sparepart ASC LIMIT $per_page OFFSET $offset"
                                );
                                $no = 0;
                                if (mysqli_num_rows($q) > 0) {
                                    while ($d = mysqli_fetch_assoc($q)) {
                                        $no++;
                                        $pernah_dipakai = (int) $d['jumlah_pemakaian'] > 0;
                                ?>
                                <tr class="table-row <?= $d['aktif'] == 'N' ? 'opacity-60 bg-gray-100' : '' ?>" data-status="<?= $d['aktif'] ?>">
                                    <td class="px-2 py-4 checkbox-col">
                                        <input type="checkbox" class="row-checkbox" name="selected_ids[]"
                                               value="<?= $d['id_sparepart'] ?>" onclick="onRowCheck(this)">
                                    </td>
                                    <td class="px-3 py-4 w-[13%]">
                                        <span class="font-mono font-semibold text-purple-600 text-sm">
                                            <?= htmlspecialchars($d['kode_sparepart']) ?>
                                        </span>
                                        <?php if ($pernah_dipakai): ?>
                                        <div class="mt-1">
                                            <span class="lock-badge" title="Sudah dipakai di <?= (int) $d['jumlah_pemakaian'] ?> permintaan, harga terkunci">
                                                <i class="fas fa-lock"></i> Terpakai
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-gray-800 w-[25%]">
                                        <div class="break-words leading-tight text-sm">
                                            <?= htmlspecialchars($d['nama_sparepart']) ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-4 text-center w-[9%]">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                            <?= htmlspecialchars($d['satuan']) ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-4 text-right w-[12%]">
                                        <div class="font-semibold text-green-600 whitespace-nowrap text-sm">
                                            Rp <?= number_format($d['harga'], 0, ',', '.') ?>
                                        </div>
                                    </td>
                                    <td class="px-3 py-4 text-center w-[9%]">
                                        <?php if ($d['aktif'] == 'Y'): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i>Aktif
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                                <i class="fas fa-times-circle mr-1"></i>Nonaktif
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-center w-[24%]">
                                        <div class="flex gap-1.5 justify-center flex-wrap">
                                            <a href="?edit=<?= $d['id_sparepart'] ?>"
                                               class="btn-action px-3 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-xs font-semibold shadow-md"
                                               title="<?= $pernah_dipakai ? 'Edit (harga terkunci)' : 'Edit Data' ?>">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($d['aktif'] == 'Y'): ?>
                                            <a href="?nonaktif=<?= $d['id_sparepart'] ?>"
                                               onclick="return confirm('⚠️ Nonaktifkan sparepart ini?\n\nSparepart yang dinonaktifkan tidak akan muncul di daftar pilihan.')"
                                               class="btn-action px-3 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 text-xs font-semibold shadow-md"
                                               title="Nonaktifkan">
                                                <i class="fas fa-eye-slash"></i>
                                            </a>
                                            <?php else: ?>
                                            <a href="?aktifkan=<?= $d['id_sparepart'] ?>"
                                               onclick="return confirm('✅ Aktifkan kembali sparepart ini?\n\nCatatan: Sistem akan mengecek duplikasi kode terlebih dahulu.')"
                                               class="btn-action px-3 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-xs font-semibold shadow-md"
                                               title="Aktifkan">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <?php endif; ?>
                                            <a href="?hapus=<?= $d['id_sparepart'] ?>"
                                               onclick="return confirm('🗑️ HAPUS PERMANEN?\n\n⚠️ Data akan dihapus dari database dan TIDAK BISA dikembalikan!\n\nYakin ingin melanjutkan?')"
                                               class="btn-action px-3 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 text-xs font-semibold shadow-md"
                                               title="<?= $pernah_dipakai ? 'Tidak bisa dihapus (sudah pernah dipakai), hanya bisa dinonaktifkan' : 'Hapus Permanen' ?>">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                                    }
                                } else {
                                ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <div class="text-gray-400">
                                            <i class="fas fa-inbox text-6xl mb-4"></i>
                                            <p class="text-lg font-semibold">
                                                <?php
                                                if ($search != '' && $filter_status != 'semua')
                                                    echo 'Tidak ada data yang sesuai dengan filter dan pencarian';
                                                elseif ($search != '')
                                                    echo 'Tidak ada data yang sesuai dengan pencarian';
                                                elseif ($filter_status == 'aktif')
                                                    echo 'Tidak ada sparepart yang aktif';
                                                elseif ($filter_status == 'nonaktif')
                                                    echo 'Tidak ada sparepart yang nonaktif';
                                                else
                                                    echo 'Belum ada data sparepart';
                                                ?>
                                            </p>
                                            <?php if ($search != '' || $filter_status != 'semua'): ?>
                                            <a href="master_sparepart.php" class="text-purple-600 hover:text-purple-700 mt-2 inline-block">
                                                Lihat semua data
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php } ?>
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
                    'search' => $search,
                ];
                $prev_page  = max(1, $page - 1);
                $next_page  = min($total_pages, $page + 1);
                $prev_qs    = http_build_query(array_merge($base_params, ['page' => $prev_page]));
                $next_qs    = http_build_query(array_merge($base_params, ['page' => $next_page]));

                // Tampilkan halaman aktif ± 2 (window)
                $window     = 2;
                $page_start = max(2, $page - $window);
                $page_end   = min($total_pages - 1, $page + $window);
                ?>
                <div class="mt-4 space-y-2">

                    <!-- Info teks -->
                    <p class="text-sm text-gray-600">
                        Menampilkan
                        <span class="font-semibold"><?= $total_rows > 0 ? ($offset + 1) : 0 ?> – <?= min($offset + $per_page, $total_rows) ?></span>
                        dari <span class="font-semibold"><?= $total_rows ?></span> data sparepart (50 data / halaman)
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

                <!-- Info baris ditampilkan -->
                <?php if ($no > 0): ?>
                <div class="mt-2 text-sm text-gray-600 flex items-center justify-between">
                    <span>
                        <i class="fas fa-info-circle mr-1"></i>
                        Ditampilkan: <strong id="displayCount"><?= $no ?></strong> baris (per halaman)
                    </span>
                    <?php if ($no > 5): ?>
                    <span class="text-gray-500">
                        <i class="fas fa-arrow-down mr-1"></i>
                        Scroll untuk melihat lebih banyak
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<!-- Modal Import Data (CSV) -->
<div class="modal-overlay" id="importModal" onclick="hideImportModal()">
    <div class="modal-dialog" onclick="event.stopPropagation()" style="max-width: 500px; width: 90%;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Data Sparepart</h5>
                <button type="button" class="btn-close" onclick="hideImportModal()">×</button>
            </div>
            <div class="modal-body" style="text-align: left;">
                <div class="note-box" style="margin-bottom: 15px; padding: 14px 16px;">
                    <strong><i class="fas fa-triangle-exclamation"></i> Sebelum upload, pastikan:</strong>
                    <ul>
                        <li>File hasil download dari tombol <strong>"Download Format"</strong>, header/urutan kolom belum diubah.</li>
                        <li>Format file <strong>.csv</strong> (bukan .xls/.xlsx).</li>
                        <li>Kolom kode, nama, satuan, harga wajib terisi dengan benar.</li>
                        <li>Jika struktur file tidak sesuai, sistem akan <strong>menolak seluruh import</strong> (tidak ada data yang dipaksa masuk).</li>
                    </ul>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><i class="fas fa-file-csv mr-1"></i> Pilih File CSV</label>
                        <input type="file" name="file_import" accept=".csv" required class="form-input w-full px-4 py-3 border-2 border-gray-300 rounded-lg">
                    </div>
                    <button type="submit" name="import_submit" class="btn-action w-full text-white py-3 rounded-lg font-semibold shadow-lg" style="background:linear-gradient(135deg,#11998e,#38ef7d);">
                        <i class="fas fa-upload mr-2"></i> Upload &amp; Import Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Auto-capitalize
    const namaSparepart = document.getElementById('namaSparepart');
    const satuanInput   = document.getElementById('satuanInput');

    if (namaSparepart) {
        namaSparepart.addEventListener('input', function() {
            const s = this.selectionStart, e = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(s, e);
        });
    }
    if (satuanInput) {
        satuanInput.addEventListener('input', function() {
            const s = this.selectionStart, e = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(s, e);
        });
    }

    // Auto-hide alert setelah 5 detik
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'all 0.3s ease-out';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);

    // Format harga otomatis (tidak berlaku kalau readonly / terkunci)
    const hargaInput = document.getElementById('hargaInput');
    if (hargaInput && !hargaInput.hasAttribute('readonly')) {
        if (hargaInput.value) formatHarga();
        hargaInput.addEventListener('input', formatHarga);
        hargaInput.addEventListener('blur',  formatHarga);

        function formatHarga() {
            let value = hargaInput.value.replace(/\D/g, '');
            if (value === '') { hargaInput.value = ''; return; }
            hargaInput.value = parseInt(value).toLocaleString('id-ID');
        }

        hargaInput.form.addEventListener('submit', function() {
            hargaInput.value = hargaInput.value.replace(/\./g, '').replace(/,/g, '');
        });
    } else if (hargaInput) {
        // Tetap bersihkan format saat submit walau readonly, supaya nilai
        // yang terkirim tetap angka murni (server tetap memvalidasi ulang)
        hargaInput.form.addEventListener('submit', function() {
            hargaInput.value = hargaInput.value.replace(/\./g, '').replace(/,/g, '');
        });
    }

    // Filter status
    const filterStatus = document.getElementById('filterStatus');
    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            const status = this.value;
            const search = document.getElementById('searchInput').value;
            let url = 'master_sparepart.php?status=' + status;
            if (search) url += '&search=' + encodeURIComponent(search);
            window.location.href = url;
        });
    }

    // Live search
    const searchInput = document.getElementById('searchInput');
    let searchTimeout;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchValue = this.value.trim();
            const filterValue = filterStatus.value;
            searchTimeout = setTimeout(() => {
                let url = 'master_sparepart.php?status=' + filterValue;
                if (searchValue !== '') url += '&search=' + encodeURIComponent(searchValue);
                window.location.href = url;
            }, 800);
        });
    }

    function clearSearch() {
        window.location.href = 'master_sparepart.php?status=' + filterStatus.value;
    }

// Highlight hasil pencarian (aman: hanya ubah text node, bukan innerHTML)
const searchTerm = '<?= addslashes(htmlspecialchars($search)) ?>';
if (searchTerm) {
    const escaped = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp('(' + escaped + ')', 'gi');

    document.querySelectorAll('.table-row').forEach(row => {
        row.querySelectorAll('td').forEach(cell => {
            if (cell.querySelector('.btn-action') || cell.querySelector('input')) return;

            const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, null);
            const textNodes = [];
            let node;
            while ((node = walker.nextNode())) {
                if (regex.test(node.nodeValue)) textNodes.push(node);
                regex.lastIndex = 0; // reset karena pakai flag g
            }

            textNodes.forEach(textNode => {
                const parts = textNode.nodeValue.split(regex);
                if (parts.length <= 1) return;

                const frag = document.createDocumentFragment();
                parts.forEach((part, i) => {
                    if (i % 2 === 1) {
                        const mark = document.createElement('mark');
                        mark.className = 'bg-yellow-300 px-1 rounded';
                        mark.textContent = part;
                        frag.appendChild(mark);
                    } else if (part) {
                        frag.appendChild(document.createTextNode(part));
                    }
                });
                textNode.parentNode.replaceChild(frag, textNode);
            });
        });
    });
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
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') hideImportModal();
    });

    // Toggle catatan import (collapsible)
    function toggleNoteImport() {
        const content = document.getElementById('noteImportContent');
        const icon = document.getElementById('noteImportIcon');
        const isOpen = content.style.display === 'block';
        content.style.display = isOpen ? 'none' : 'block';
        icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
    }

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

        let adaAktif = false;
        let adaNonaktif = false;
        checked.forEach(cb => {
            const status = cb.closest('tr').getAttribute('data-status');
            if (status === 'Y') adaAktif = true;
            if (status === 'N') adaNonaktif = true;
        });

        document.getElementById('btnBulkNonaktif').style.display = adaAktif ? 'inline-flex' : 'none';
        document.getElementById('btnBulkAktifkan').style.display = adaNonaktif ? 'inline-flex' : 'none';
    }

    function clearSelection() {
        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.checked = false;
            cb.closest('tr').classList.remove('row-selected');
        });
        const selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;
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
            confirmMsg = '⚠️ Nonaktifkan ' + checked.length + ' data sparepart terpilih?';
        } else if (action === 'aktifkan') {
            confirmMsg = '✅ Aktifkan kembali ' + checked.length + ' data sparepart terpilih?\n\n(Sparepart yang kodenya bentrok dengan sparepart lain yang masih Aktif akan otomatis dilewati.)';
        } else if (action === 'hapus') {
            confirmMsg = '🗑️ HAPUS PERMANEN ' + checked.length + ' data sparepart terpilih?\n\n⚠️ Sparepart yang sudah pernah dipakai di permintaan perbaikan / riwayat otomatis akan dilewati (hanya bisa dinonaktifkan).\nData lainnya TIDAK BISA dikembalikan!';
        }

        if (!confirm(confirmMsg)) return;

        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkForm').submit();
    }
</script>

</body>
</html>
<?php
ob_end_flush();
?>
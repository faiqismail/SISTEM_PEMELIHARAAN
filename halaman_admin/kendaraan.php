<?php

// 🔑 WAJIB: include koneksi database
include "../inc/config.php";
require_once "../inc/xlsx_helper.php"; // dipakai untuk Export (tampilan .xls, tidak di-roundtrip)
require_once "../inc/csv_helper.php";  // dipakai untuk Template & Import (.csv, aman untuk roundtrip)
requireAuth('admin');

/* =====================
   DAFTAR BIDANG
===================== */
$bidang_options = [
    'ANGKUTAN DALAM',
    'ANGKUTAN LUAR',
    'ALAT BERAT WILAYAH 1',
    'ALAT BERAT WILAYAH 2',
    'ALAT BERAT WILAYAH 3',
    'PERGUDANGAN'
];

/* =====================
   DAFTAR JENIS KENDARAAN
===================== */
$jenis_kendaraan_options = [
    'BOX',
    'DUMP TRUCK',
    'EXCAVATOR',
    'FLAT TRUCK',
    'FORKLIFT',
    'TRAILER',
    'TRONTON',
    'WHEEL LOADER',
    'WING BOX'
];

/* =====================
   DAFTAR STATUS KENDARAAN
===================== */
$status_options = [
    'Aktif',
    'Tidak Aktif'
];

/* =====================
   TAHUN KENDARAAN (range)
===================== */
$current_year = date('Y');
$tahun_options = range($current_year, 1990);

/* =====================================================================
   HELPER: bangun kondisi WHERE dari filter GET yang sedang aktif
   (dipakai bareng oleh: listing tabel & export, supaya hasil export
   SELALU sinkron dengan apa yang sedang difilter/dicari di halaman)
===================================================================== */
function buildKendaraanWhere($connection) {
    $keyword       = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_status = isset($_GET['status']) ? $_GET['status'] : 'semua';
    $where_conditions = [];

    if ($keyword !== '') {
        $keyword_esc = mysqli_real_escape_string($connection, $keyword);
        $where_conditions[] = "(nopol LIKE '%$keyword_esc%' OR jenis_kendaraan LIKE '%$keyword_esc%' OR bidang LIKE '%$keyword_esc%')";
    }
    if ($filter_status !== 'semua' && $filter_status !== '') {
        $filter_status_safe = mysqli_real_escape_string($connection, $filter_status);
        $where_conditions[] = "status='$filter_status_safe'";
    }

    $where = count($where_conditions) > 0 ? "WHERE " . implode(' AND ', $where_conditions) : '';
    return [$where, $keyword, $filter_status];
}

/* =====================================================================
   FITUR: DOWNLOAD FORMAT / TEMPLATE (CSV) - PHP NATIVE, TANPA LIBRARY
   Harus diproses SEBELUM ada output HTML apapun.
===================================================================== */
if (isset($_GET['template']) && $_GET['template'] == '1') {

    $dataRows = [
        ['nopol', 'jenis_kendaraan', 'bidang', 'tahun_kendaraan', 'status'],
        ['W 1234 XYZ', 'TRONTON', 'ANGKUTAN DALAM', (string) $current_year, 'Aktif'],
    ];

    native_csv_output($dataRows, 'template_import_kendaraan.csv');
}

/* =====================================================================
   FITUR: EXPORT DATA (XLS untuk dilihat/dibuka di Excel) - PHP NATIVE
   Mengikuti filter status & pencarian yang SEDANG AKTIF di halaman
   (tidak dibatasi pagination -> semua data yang cocok filter ikut).
   Harus diproses SEBELUM ada output HTML apapun.
===================================================================== */
if (isset($_GET['export']) && $_GET['export'] == '1') {

    [$exp_where, , ] = buildKendaraanWhere($connection);

    $exp_q = mysqli_query($connection, "SELECT * FROM kendaraan $exp_where ORDER BY id_kendaraan DESC");

    $dataRows = [['No', 'Nomor Asset', 'Jenis Kendaraan', 'Bidang', 'Tahun', 'Umur (Tahun)', 'Status', 'Tanggal Dibuat']];

    $exp_no = 1;
    if ($exp_q && mysqli_num_rows($exp_q) > 0) {
        while ($k = mysqli_fetch_assoc($exp_q)) {
            $tahun_unit = intval($k['tahun_kendaraan']);
            $umur       = $tahun_unit > 0 ? ($current_year - $tahun_unit) : '';
            $dataRows[] = [
                $exp_no++,
                $k['nopol'],
                $k['jenis_kendaraan'],
                $k['bidang'],
                $tahun_unit > 0 ? $tahun_unit : '',
                $umur,
                $k['status'],
                $k['created_at'] ?? '',
            ];
        }
    }

    native_xlsx_output([
        ['name' => 'Data Kendaraan', 'rows' => $dataRows, 'headerRows' => 1, 'widths' => [5, 6, 15, 18, 20, 8, 12, 12, 18]],
    ], 'export_kendaraan_' . date('Ymd_His') . '.xls');
}

/* =====================================================================
   FITUR: IMPORT DATA (CSV) - PHP NATIVE, TANPA LIBRARY
   Format kolom WAJIB: nopol | jenis_kendaraan | bidang | tahun_kendaraan | status

   PRINSIP "GAGAL YA GAGAL, JANGAN DIPAKSA MASUK" (sama seperti rekanan.php):
   - Ekstensi salah / file tak terbaca / header tidak persis sesuai
     -> STOP TOTAL, tidak ada satupun baris yang masuk ke database.
   - Setelah struktur file valid, tiap baris data divalidasi lagi:
     jenis_kendaraan & bidang harus PERSIS salah satu dari daftar pilihan
     yang ada di form (dicek tanpa peduli besar-kecil huruf), tahun harus
     masuk akal (1990 s/d tahun berjalan), dan nopol yang berstatus Aktif
     tidak boleh bentrok dengan nopol Aktif lain (baik yang sudah ada di
     database maupun yang baru saja diimport di file yang sama). Baris
     yang tidak lolos akan DILEWATI (bukan seluruh file digagalkan) dan
     dilaporkan jelas baris ke berapa saja yang dilewati.
===================================================================== */
if (isset($_POST['import_submit'])) {

    if (!isset($_FILES['file_import']) || $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
        echo "<script>
            alert('⚠️ Tidak ada file yang diupload atau terjadi error saat upload!');
            window.location.href='kendaraan.php';
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
            window.location.href='kendaraan.php';
        </script>";
        exit;
    }

    // ===== VALIDASI 2: FILE BISA DIBACA =====
    try {
        $rows = native_csv_read($fileTmp);
    } catch (Exception $ex) {
        echo "<script>
            alert('❌ Import GAGAL!\\n\\nFile tidak bisa dibaca atau isinya rusak/kosong.\\nSilakan download ulang \\'Download Format\\' dan isi langsung di file tersebut, jangan ubah strukturnya.\\n\\nTidak ada data yang diproses.');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    }

    // ===== VALIDASI 3: STRUKTUR HEADER HARUS PERSIS =====
    $expectedHeader = ['nopol', 'jenis_kendaraan', 'bidang', 'tahun_kendaraan', 'status'];
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
            alert('❌ Import GAGAL!\\n\\nStruktur kolom file tidak sesuai format yang diminta.\\nHeader baris pertama WAJIB persis: nopol, jenis_kendaraan, bidang, tahun_kendaraan, status (urutan tidak boleh diubah).\\n\\nSilakan download ulang \\'Download Format\\', isi datanya langsung di file itu, jangan ubah/hapus/tambah kolom.\\n\\nTidak ada data yang diproses.');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    }

    // Buang baris header, sisanya baris data
    array_shift($rows);

    if (count($rows) === 0) {
        echo "<script>
            alert('⚠️ Import dibatalkan: file hanya berisi header, tidak ada baris data di bawahnya.');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    }

    // ===== PROSES BARIS DATA (baru sampai sini kalau struktur file sudah pasti valid) =====
    $berhasil = 0;
    $dilewati = 0;
    $baris_dilewati = [];
    $rowNo = 1; // baris 1 = header, data dimulai dari baris 2
    $nopol_aktif_batch = []; // cegah bentrok nopol aktif SESAMA baris di file yang sama

    foreach ($rows as $row) {
        $rowNo++;

        $nopol_raw  = isset($row[0]) ? trim((string) $row[0]) : '';
        $jenis_raw  = isset($row[1]) ? trim((string) $row[1]) : '';
        $bidang_raw = isset($row[2]) ? trim((string) $row[2]) : '';
        $tahun_raw  = isset($row[3]) ? trim((string) $row[3]) : '';
        $status_raw = isset($row[4]) ? trim((string) $row[4]) : '';

        // Lewati baris kosong total
        if ($nopol_raw === '' && $jenis_raw === '' && $bidang_raw === '' && $tahun_raw === '') continue;

        // Nomor Asset wajib
        $nopol = strtoupper($nopol_raw);
        if ($nopol === '') {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
            continue;
        }

        // Jenis kendaraan wajib PERSIS salah satu pilihan yang ada (case-insensitive)
        $jenis_match = null;
        foreach ($jenis_kendaraan_options as $opt) {
            if (strcasecmp($opt, $jenis_raw) === 0) {
                $jenis_match = $opt;
                break;
            }
        }
        if ($jenis_match === null) {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
            continue;
        }

        // Bidang wajib PERSIS salah satu pilihan yang ada (case-insensitive)
        $bidang_match = null;
        foreach ($bidang_options as $opt) {
            if (strcasecmp($opt, $bidang_raw) === 0) {
                $bidang_match = $opt;
                break;
            }
        }
        if ($bidang_match === null) {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
            continue;
        }

        // Tahun kendaraan wajib masuk akal
        $tahun = (int) preg_replace('/[^0-9]/', '', $tahun_raw);
        if ($tahun < 1990 || $tahun > (int) $current_year) {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
            continue;
        }

        // Status: default Aktif kalau kosong, kalau diisi harus cocok salah satu pilihan
        $status_match = 'Aktif';
        if ($status_raw !== '') {
            $status_found = false;
            foreach ($status_options as $opt) {
                if (strcasecmp($opt, $status_raw) === 0) {
                    $status_match = $opt;
                    $status_found = true;
                    break;
                }
            }
            if (!$status_found) {
                $dilewati++;
                $baris_dilewati[] = $rowNo;
                continue;
            }
        }

        // Cek bentrok nomor asset AKTIF (baik yang sudah ada di database, maupun
        // yang baru saja berhasil ditambahkan dari baris lain di file yang sama)
        if ($status_match === 'Aktif') {
            if (in_array($nopol, $nopol_aktif_batch, true)) {
                $dilewati++;
                $baris_dilewati[] = $rowNo;
                continue;
            }
            $nopol_esc_check = mysqli_real_escape_string($connection, $nopol);
            $cek_aktif = mysqli_query($connection, "SELECT id_kendaraan FROM kendaraan WHERE nopol='$nopol_esc_check' AND status='Aktif'");
            if ($cek_aktif && mysqli_num_rows($cek_aktif) > 0) {
                $dilewati++;
                $baris_dilewati[] = $rowNo;
                continue;
            }
        }

        $nopol_esc  = mysqli_real_escape_string($connection, $nopol);
        $jenis_esc  = mysqli_real_escape_string($connection, $jenis_match);
        $bidang_esc = mysqli_real_escape_string($connection, $bidang_match);
        $status_esc = mysqli_real_escape_string($connection, $status_match);

        $ok = mysqli_query($connection, "INSERT INTO kendaraan
            (nopol, jenis_kendaraan, bidang, tahun_kendaraan, status)
            VALUES ('$nopol_esc','$jenis_esc','$bidang_esc','$tahun','$status_esc')");

        if ($ok) {
            $berhasil++;
            if ($status_match === 'Aktif') {
                $nopol_aktif_batch[] = $nopol;
            }
        } else {
            $dilewati++;
            $baris_dilewati[] = $rowNo;
        }
    }

    if ($berhasil === 0 && $dilewati === 0) {
        echo "<script>
            alert('⚠️ Tidak ada data valid untuk diimport.');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    }

    $pesan = "✅ Import selesai!\\n\\nBerhasil ditambahkan: $berhasil data";
    if ($dilewati > 0) {
        $pesan .= "\\nDilewati: $dilewati data (baris ke: " . implode(', ', $baris_dilewati) . ")\\nPenyebab umum: nopol kosong/bentrok dengan yang masih Aktif, jenis kendaraan / bidang / status tidak sesuai daftar pilihan, atau tahun tidak wajar.";
    }

    echo "<script>
        alert('" . addslashes($pesan) . "');
        window.location.href='kendaraan.php?success=import&count=$berhasil&skip=$dilewati';
    </script>";
    exit;
}

/* =====================
   SIMPAN KENDARAAN
===================== */
if (isset($_POST['simpan'])) {
    $nopol  = mysqli_real_escape_string($connection, strtoupper($_POST['nopol']));
    $jenis  = mysqli_real_escape_string($connection, $_POST['jenis_kendaraan']);
    $bidang = mysqli_real_escape_string($connection, $_POST['bidang']);
    $tahun  = intval($_POST['tahun_kendaraan']);
    $status = 'Aktif';

    // 🔍 Cek apakah nopol yang sama sudah ADA dan AKTIF
    $cek_aktif = mysqli_query($connection,
        "SELECT id_kendaraan FROM kendaraan WHERE nopol='$nopol' AND status='Aktif'"
    );

    if (mysqli_num_rows($cek_aktif) > 0) {
        echo "<script>
            alert('❌ Tidak bisa menyimpan!\\nNomor Asset [ $nopol ] sudah terdaftar dan masih AKTIF.\\n\\nHapus atau ubah nomor asset kendaraan aktif tersebut terlebih dahulu sebelum menambah ulang.');
            window.history.back();
        </script>";
        exit;
    }

    // ✅ Boleh simpan jika nopol sama tapi statusnya sudah Tidak Aktif
    mysqli_query($connection,
        "INSERT INTO kendaraan (nopol, jenis_kendaraan, bidang, tahun_kendaraan, status)
         VALUES ('$nopol','$jenis','$bidang','$tahun','$status')"
    );

    echo "<script>
        alert('✅ Data kendaraan berhasil disimpan');
        window.location.href='kendaraan.php';
    </script>";
    exit;
}

/* =====================
   UPDATE KENDARAAN
===================== */
if (isset($_POST['update'])) {
    $id     = intval($_POST['id_kendaraan']);
    $nopol  = mysqli_real_escape_string($connection, strtoupper($_POST['nopol']));
    $jenis  = mysqli_real_escape_string($connection, $_POST['jenis_kendaraan']);
    $bidang = mysqli_real_escape_string($connection, $_POST['bidang']);
    $tahun  = intval($_POST['tahun_kendaraan']);
    $status = mysqli_real_escape_string($connection, $_POST['status']);

    // 🔍 Jika ingin mengaktifkan, cek apakah ada nopol SAMA yang sudah AKTIF di data lain
    if ($status == 'Aktif') {
        $cek_aktif = mysqli_query($connection,
            "SELECT id_kendaraan FROM kendaraan
             WHERE nopol='$nopol' AND id_kendaraan != '$id' AND status='Aktif'"
        );

        if (mysqli_num_rows($cek_aktif) > 0) {
            echo "<script>
                alert('❌ Tidak bisa mengaktifkan!\\nNomor Asset [ $nopol ] sudah digunakan oleh kendaraan lain yang masih AKTIF.\\n\\nHapus atau ubah nomor asset kendaraan aktif tersebut terlebih dahulu agar tidak bentrok.');
                window.history.back();
            </script>";
            exit;
        }
    }

    // ✅ Aman untuk diupdate
    mysqli_query($connection,
        "UPDATE kendaraan SET
            nopol='$nopol',
            jenis_kendaraan='$jenis',
            bidang='$bidang',
            tahun_kendaraan='$tahun',
            status='$status'
         WHERE id_kendaraan='$id'"
    );

    echo "<script>
        alert('✅ Data kendaraan berhasil diperbarui');
        window.location.href='kendaraan.php';
    </script>";
    exit;
}

/* =====================
   HAPUS KENDARAAN
===================== */
if (isset($_GET['hapus'])) {
    $id = intval($_GET['hapus']);
    try {
        mysqli_query($connection, "DELETE FROM kendaraan WHERE id_kendaraan='$id'");
        echo "<script>
            alert('✅ Data kendaraan berhasil dihapus');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1451) {
            echo "<script>
                alert('❌ Data kendaraan masih digunakan di Permintaan Perbaikan, data tidak bisa di hapus hanya bisa di Nonaktifkan ');
                window.location.href='kendaraan.php';
            </script>";
            exit;
        }
        echo "<script>
            alert('❌ Gagal menghapus data!');
            window.location.href='kendaraan.php';
        </script>";
        exit;
    }
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
    $redirect  = 'kendaraan.php' . ($backQuery ? '?' . $backQuery : '');

    if (count($ids) === 0) {
        echo "<script>
            alert('⚠️ Tidak ada data yang dicentang!');
            window.location.href='" . $redirect . "';
        </script>";
        exit;
    }

    $idListSql = implode(',', $ids);

    if ($action === 'nonaktif') {

        mysqli_query($connection, "UPDATE kendaraan SET status='Tidak Aktif' WHERE id_kendaraan IN ($idListSql)");
        $jumlah = count($ids);

        echo "<script>
            alert('✅ $jumlah data kendaraan berhasil dinonaktifkan!');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_nonaktif';
        </script>";
        exit;

    } elseif ($action === 'aktifkan') {

        // Setiap kendaraan yang mau diaktifkan tetap dicek satu-satu:
        // nomor asset-nya tidak boleh bentrok dengan kendaraan lain yang
        // masih Aktif (aturan sama seperti aktifkan/update satuan).
        $berhasil_aktif = 0;
        $gagal_bentrok  = 0;

        foreach ($ids as $id) {
            $r = mysqli_fetch_assoc(mysqli_query($connection, "SELECT nopol FROM kendaraan WHERE id_kendaraan='$id'"));
            if (!$r) continue;

            $nopol_esc = mysqli_real_escape_string($connection, $r['nopol']);
            $cek = mysqli_query($connection, "
                SELECT id_kendaraan FROM kendaraan
                WHERE nopol='$nopol_esc' AND id_kendaraan != '$id' AND status='Aktif'
            ");

            if ($cek && mysqli_num_rows($cek) > 0) {
                $gagal_bentrok++;
                continue;
            }

            mysqli_query($connection, "UPDATE kendaraan SET status='Aktif' WHERE id_kendaraan='$id'");
            $berhasil_aktif++;
        }

        $pesan = "✅ $berhasil_aktif data kendaraan berhasil diaktifkan kembali!";
        if ($gagal_bentrok > 0) {
            $pesan .= "\\n⚠️ $gagal_bentrok data TIDAK bisa diaktifkan karena nomor asset-nya masih dipakai kendaraan lain yang sedang Aktif.";
        }

        echo "<script>
            alert('" . addslashes($pesan) . "');
            window.location.href='" . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . "success=bulk_aktifkan';
        </script>";
        exit;

    } elseif ($action === 'hapus') {

        // Sama seperti hapus satuan: kendaraan yang masih dipakai di
        // Permintaan Perbaikan otomatis dilewati (tidak bisa dihapus,
        // hanya bisa dinonaktifkan).
        $berhasil_hapus = 0;
        $gagal_relasi   = 0;

        foreach ($ids as $id) {
            try {
                mysqli_query($connection, "DELETE FROM kendaraan WHERE id_kendaraan='$id'");
                $berhasil_hapus++;
            } catch (mysqli_sql_exception $ex) {
                $gagal_relasi++;
            }
        }

        $pesan = "✅ $berhasil_hapus data kendaraan berhasil dihapus!";
        if ($gagal_relasi > 0) {
            $pesan .= "\\n⚠️ $gagal_relasi data TIDAK dihapus karena masih dipakai di Permintaan Perbaikan (hanya bisa dinonaktifkan).";
        }

        echo "<script>
            alert('" . addslashes($pesan) . "');
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
        mysqli_query($connection, "SELECT * FROM kendaraan WHERE id_kendaraan='$id'")
    );
}

/* =====================
   SEARCH & FILTER
===================== */
[$where, $keyword, $filter_status] = buildKendaraanWhere($connection);
$filter_umur = isset($_GET['umur']) ? $_GET['umur'] : 'semua';

// Sorting berdasarkan filter umur
$order_by = "ORDER BY id_kendaraan DESC";
if ($filter_umur == 'tertua') {
    $order_by = "ORDER BY tahun_kendaraan ASC";
} elseif ($filter_umur == 'terbaru') {
    $order_by = "ORDER BY tahun_kendaraan DESC";
} elseif ($filter_umur == 'sedang') {
    // Mendekati median tahun
    $order_by = "ORDER BY ABS(tahun_kendaraan - (SELECT AVG(tahun_kendaraan) FROM kendaraan)) ASC";
}

// ===== PAGINATION: 50 data per halaman, mengikuti filter/pencarian aktif =====
$per_page = 50;
$page     = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

$result_count = mysqli_query($connection, "SELECT COUNT(*) AS total FROM kendaraan $where");
$row_count    = $result_count ? mysqli_fetch_assoc($result_count) : ['total' => 0];
$total_rows   = (int) ($row_count['total'] ?? 0);
$total_pages  = $total_rows > 0 ? (int) ceil($total_rows / $per_page) : 1;

// Query string filter yang sedang aktif (dipakai untuk link export & pagination)
function currentKendaraanFilterQuery() {
    $params = [];
    if (isset($_GET['search']) && $_GET['search'] !== '') $params['search'] = $_GET['search'];
    if (isset($_GET['status']) && $_GET['status'] !== '') $params['status'] = $_GET['status'];
    if (isset($_GET['umur'])   && $_GET['umur'] !== '')   $params['umur']   = $_GET['umur'];
    if (isset($_GET['page'])   && $_GET['page'] !== '')   $params['page']   = $_GET['page'];
    return http_build_query($params);
}
$filterQueryString = currentKendaraanFilterQuery();

include "navbar.php";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Kendaraan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { overflow-x: hidden; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: rgb(185, 224, 204);
            min-height: 100vh;
            overflow-x: hidden;
        }
        .main-container { padding: 20px; max-width: 1700px; margin: 0 auto; overflow-x: hidden; }

        .page-header {
            background: white; padding: 25px 30px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 25px;
        }
        .page-header h1 {
            font-size: 28px; font-weight: 700; color: #2c3e50;
            display: flex; align-items: center; gap: 12px; margin: 0;
        }
        .page-header h1 i { color: #667eea; font-size: 30px; }

        .content-layout {
            display: grid;
            grid-template-columns: 400px minmax(0, 1fr);
            gap: 25px;
            align-items: start;
            min-width: 0;
        }

        /* FORM */
        .form-container {
            background: white; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 25px; position: sticky; top: 20px;
            min-width: 0;
        }
        .form-title {
            font-size: 20px; font-weight: 700; color: #2c3e50;
            margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
            padding-bottom: 15px; border-bottom: 2px solid #f0f0f0;
        }
        .form-title i { color: #667eea; }
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-weight: 600; color: #2c3e50; margin-bottom: 8px; font-size: 14px; }
        .form-label i { margin-right: 5px; color: #667eea; }
        .form-input, .form-select {
            width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; transition: all 0.3s ease; background: #f8f9fa;
        }
        .form-input:focus, .form-select:focus {
            outline: none; border-color: #667eea; background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-select {
            cursor: pointer; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 15px center; padding-right: 40px;
        }
        .info-text { font-size: 12px; color: #6c757d; margin-top: 5px; font-style: italic; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn {
            flex: 1; padding: 12px 20px; border-radius: 8px; font-weight: 600;
            font-size: 14px; border: none; cursor: pointer; transition: all 0.3s ease;
            display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none;
        }
        .btn-primary { background: rgb(9, 120, 83); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(9,120,83,0.4); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; transform: translateY(-2px); }

        /* TABLE SIDE */
        .data-container {
            background: white; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 25px; display: flex; flex-direction: column;
            min-width: 0;
        }
        .data-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f0; flex-shrink: 0;
            flex-wrap: wrap; gap: 10px;
        }
        .data-title { font-size: 20px; font-weight: 700; color: #2c3e50; display: flex; align-items: center; gap: 10px; }
        .data-title i { color: #667eea; }
        .stats-badge { padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 600; color: white; white-space: nowrap; }

        /* Toolbar Import/Export */
        .toolbar-import-export { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; flex-shrink: 0; }
        .btn-sm { padding: 9px 16px; font-size: 0.85rem; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s ease; }
        .btn-sm:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-secondary-sm { background: #6c757d; color: white; }
        .btn-import-sm { background: linear-gradient(135deg,#11998e,#38ef7d); color: #063; }
        .btn-excel-sm  { background: linear-gradient(135deg,#1d976c,#93f9b9); color: #063; }

        /* Note box collapsible */
        .note-box { background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 8px; margin-bottom: 15px; font-size: 0.85rem; color: #333; flex-shrink: 0; overflow: hidden; }
        .note-box ul { margin: 6px 0 0 18px; padding: 0; }
        .note-box li { margin-bottom: 4px; }

        /* Filter row */
        .filter-search-container { display: flex; gap: 10px; margin-bottom: 20px; flex-shrink: 0; flex-wrap: wrap; }
        .filter-box { flex: 0 0 160px; }
        .filter-umur-box { flex: 0 0 175px; }
        .search-box { flex: 1; min-width: 160px; position: relative; }
        .filter-select {
            width: 100%; padding: 12px 40px 12px 15px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; transition: all 0.3s ease;
            background: #f8f9fa; cursor: pointer; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 15px center;
        }
        .filter-select:focus {
            outline: none; border-color: #667eea;
            background-color: white; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #999; font-size: 16px; }
        .search-input {
            width: 100%; padding: 12px 15px 12px 45px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 14px; transition: all 0.3s ease; background: #f8f9fa;
        }
        .search-input:focus { outline: none; border-color: #667eea; background: white; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }

        /* Kotak scroll tabel: SATU-SATUNYA elemen yang boleh discroll ke samping.
           overflow-x: auto -> kalau tabel lebih lebar dari kotak, munculin
           scrollbar HANYA di kotak ini, bukan di seluruh halaman. */
        .table-scroll {
            overflow-y: auto; overflow-x: auto; max-height: calc(100vh - 420px); min-height: 200px;
            border-radius: 8px; border: 1px solid #e0e0e0;
        }
        .table-scroll::-webkit-scrollbar { height: 8px; width: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .table-scroll::-webkit-scrollbar-thumb { background: rgb(9, 120, 83); border-radius: 4px; }

        table { width: 100%; border-collapse: collapse; }
        thead { position: sticky; top: 0; z-index: 10; background: rgb(9, 120, 83); }
        thead th {
            padding: 15px 12px; text-align: left; font-weight: 600;
            font-size: 13px; color: white; white-space: nowrap;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        tbody tr { border-bottom: 1px solid #f0f0f0; transition: all 0.2s ease; }
        tbody tr:hover { background: #f8f9fa; }
        tbody td { padding: 15px 12px; font-size: 14px; color: #555; }

        .nopol-badge {
            display: inline-block; padding: 6px 12px;
            background: linear-gradient(135deg, rgb(105,92,15), rgb(205,174,38));
            color: white; border-radius: 6px; font-weight: 700;
            font-size: 13px; letter-spacing: 0.5px;
            white-space: nowrap;   /* ⬅️ TAMBAHKAN INI */

        }
        .bidang-badge { display: inline-block; padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .bidang-angkutan-dalam  { background: linear-gradient(135deg,#e8f5e9,#c8e6c9); color:#2e7d32; border:1px solid #81c784; }
        .bidang-angkutan-luar   { background: linear-gradient(135deg,#f3e5f5,#e1bee7); color:#7b1fa2; border:1px solid #ce93d8; }
        .bidang-alat-berat-1    { background: linear-gradient(135deg,#fff3e0,#ffe0b2); color:#e65100; border:1px solid #ffb74d; }
        .bidang-alat-berat-2    { background: linear-gradient(135deg,#fce4ec,#f8bbd0); color:#c2185b; border:1px solid #f06292; }
        .bidang-alat-berat-3    { background: linear-gradient(135deg,#e0f2f1,#b2dfdb); color:#00695c; border:1px solid #4db6ac; }
        .bidang-gudang          { background: linear-gradient(135deg,#ede7f6,#d1c4e9); color:#512da8; border:1px solid #9575cd; }

        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .status-aktif      { background: linear-gradient(135deg,#d4edda,#c3e6cb); color:#155724; border:1px solid #28a745; }
        .status-tidak-aktif{ background: linear-gradient(135deg,#f8d7da,#f5c6cb); color:#721c24; border:1px solid #dc3545; }

        /* Umur badge */
        .umur-badge {
            display: inline-block; padding: 5px 10px;
            border-radius: 12px; font-size: 12px; font-weight: 700; white-space: nowrap;
        }
        .umur-baru   { background: linear-gradient(135deg,#e3f2fd,#bbdefb); color:#1565c0; border:1px solid #64b5f6; }
        .umur-sedang { background: linear-gradient(135deg,#fff8e1,#ffecb3); color:#f57f17; border:1px solid #ffd54f; }
        .umur-tua    { background: linear-gradient(135deg,#fbe9e7,#ffccbc); color:#bf360c; border:1px solid #ff8a65; }

        .action-btns { display: flex; gap: 6px; }
        .btn-action {
            padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;
            border: none; cursor: pointer; transition: all 0.2s ease;
            display: inline-flex; align-items: center; gap: 5px; text-decoration: none;
        }
        .btn-edit         { background:#fff3cd; color:#856404; }
        .btn-edit:hover   { background:#ffc107; color:white; }
        .btn-edit-inactive{ background:#ffe5cc; color:#cc6600; border:1px dashed #ff9933; }
        .btn-edit-inactive:hover { background:#ff9933; color:white; }
        .btn-delete       { background:#f8d7da; color:#721c24; }
        .btn-delete:hover { background:#dc3545; color:white; }

        .no-data { text-align:center; padding:60px 20px; color:#999; }
        .no-data i { font-size:48px; margin-bottom:15px; opacity:0.3; }
        .no-data p { font-size:14px; margin:0; }

        /* Checkbox & Bulk Action */
        .checkbox-col { width: 40px; text-align: center; }
        tbody tr.row-selected { background: rgba(102,126,234,0.12) !important; }
        .bulk-bar {
            display: none; align-items: center; gap: 10px;
            background: #fff8e1; border: 2px dashed #f0b429; border-radius: 12px;
            padding: 10px 16px; margin-bottom: 15px; flex-wrap: wrap; flex-shrink: 0;
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

        /* Kolom "Keterangan" (Aksi) selalu nempel di sisi kanan kotak tabel,
           supaya tombol Edit/Hapus tetap kelihatan walau tabel discroll ke
           kanan (berlaku di semua ukuran layar, bukan cuma HP). */
        thead th:last-child, tbody td:last-child {
            position: sticky;
            right: 0;
            background: white;
            box-shadow: -2px 0 5px rgba(0,0,0,0.08);
            z-index: 5;
        }
        thead th:last-child {
            background: rgb(9,120,83);
            z-index: 15;
        }
        thead th:nth-child(3), tbody td:nth-child(3) {
    white-space: nowrap;
}

        /* ============ RESPONSIVE LAPTOP (di bawah 1440px) ============ */
        @media (max-width: 1440px) and (min-width: 769px) {
            .main-container { max-width: 100%; padding: 15px; }
            .content-layout { grid-template-columns: 340px minmax(0, 1fr); gap: 15px; }
            .form-container { padding: 18px; }
            .data-container { padding: 18px; }
            thead th { padding: 12px 8px; font-size: 12px; }
            tbody td { padding: 12px 8px; font-size: 13px; }
            .nopol-badge { font-size: 12px; padding: 5px 10px; }
            .bidang-badge, .status-badge, .umur-badge { font-size: 11px; padding: 5px 10px; }
            .btn-action { padding: 5px 10px; font-size: 11px; }
        }

        /* Layar laptop kecil (di bawah 1200px): form ditumpuk ke atas,
           tabel jadi full width di bawahnya, biar nggak sempit-sempitan. */
        @media (max-width: 1200px) and (min-width: 769px) {
            .content-layout { grid-template-columns: minmax(0, 1fr); }
            .form-container { position: static; }
            .table-scroll { max-height: calc(100vh - 480px); }
            table { min-width: 900px; }
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 768px) {
            body { overflow-x: hidden; }
            .main-container { padding: 8px; width:100%; max-width:100vw; }
            .page-header { padding:10px; margin-bottom:8px; }
            .page-header h1 { font-size:16px; }
            .content-layout { display:block; width:100%; }
            .form-container { position:static; padding:10px; margin-bottom:8px; width:100%; }
            .form-title { font-size:15px; margin-bottom:10px; }
            .form-group { margin-bottom:10px; }
            .form-label { font-size:11px; }
            .form-input, .form-select { padding:8px; font-size:12px; }
            .btn { padding:9px; font-size:11px; }
            .data-container { padding:10px; }
            .data-header { flex-wrap:wrap; gap:5px; }
            .data-title { font-size:15px; }
            .stats-badge { padding:4px 8px; font-size:10px; }
            .filter-search-container { flex-direction:column; gap:8px; }
            .filter-box, .filter-umur-box { flex:1; width:100%; }
            .filter-select { padding:8px 28px 8px 10px; font-size:11px; }
            .search-input { padding:8px 8px 8px 32px; font-size:11px; }
            .table-scroll { height:320px; max-height:320px; overflow-x:auto; overflow-y:auto; -webkit-overflow-scrolling:touch; }
            table { min-width:800px; width:max-content; }
            thead th { padding:10px 8px; font-size:10px; }
            tbody td { padding:10px 8px; font-size:11px; white-space:nowrap; }
            .nopol-badge { font-size:10px; padding:4px 8px; }
            .bidang-badge, .status-badge, .umur-badge { font-size:9px; padding:4px 8px; }
            .action-btns { flex-direction:column; gap:3px; }
            .btn-action { padding:5px 8px; font-size:10px; width:100%; }
        }
        @media (max-width: 374px) {
            .table-scroll { height:250px; max-height:250px; }
            table { min-width:750px; }
        }
        @media (max-width: 768px) and (orientation: landscape) {
            .table-scroll { height:200px; max-height:200px; }
        }
    </style>
</head>
<body>

<div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-car"></i> Data Asset Armada</h1>
    </div>

    <div class="content-layout">
        <!-- =========== FORM INPUT =========== -->
        <div class="form-container">
            <h2 class="form-title">
                <i class="<?= $edit ? 'fas fa-edit' : 'fas fa-plus-circle' ?>"></i>
                <?= $edit ? 'Edit Kendaraan' : 'Tambah Asset' ?>
            </h2>

            <form method="POST">
                <?php if ($edit): ?>
                    <input type="hidden" name="id_kendaraan" value="<?= $e['id_kendaraan'] ?>">
                    <?php if ($e['status'] == 'Tidak Aktif'): ?>
                    <div style="background:#fff3cd;border:2px solid #ffc107;border-radius:8px;padding:12px;margin-bottom:15px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <i class="fas fa-exclamation-triangle" style="color:#856404;font-size:20px;"></i>
                            <div>
                                <strong style="color:#856404;font-size:14px;">PERINGATAN!</strong>
                                <p style="color:#856404;font-size:12px;margin:5px 0 0 0;">
                                    Kendaraan ini berstatus <strong>TIDAK AKTIF</strong>.
                                    Silakan ubah status jika kendaraan sudah beroperasi kembali.
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Nomor Asset -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-id-card"></i> Nomor Asset</label>
                    <input type="text" name="nopol" class="form-input"
                           placeholder="Contoh: W 1234 XYZ"
                           value="<?= $edit ? htmlspecialchars($e['nopol']) : '' ?>"
                           style="text-transform:uppercase;" required>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Otomatis diubah ke HURUF KAPITAL</small>
                </div>

                <!-- Jenis Kendaraan -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-truck"></i> Jenis Kendaraan</label>
                    <select name="jenis_kendaraan" class="form-select" required>
                        <option value="">-- Pilih Jenis Kendaraan --</option>
                        <?php foreach ($jenis_kendaraan_options as $jenis_opt): ?>
                            <option value="<?= $jenis_opt ?>" <?= ($edit && $e['jenis_kendaraan'] == $jenis_opt) ? 'selected' : '' ?>>
                                <?= $jenis_opt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Pilih jenis kendaraan dari dropdown</small>
                </div>

                <!-- Bidang -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-building"></i> Bidang</label>
                    <select name="bidang" class="form-select" required>
                        <option value="">-- Pilih Bidang --</option>
                        <?php foreach ($bidang_options as $bidang_opt): ?>
                            <option value="<?= $bidang_opt ?>" <?= ($edit && $e['bidang'] == $bidang_opt) ? 'selected' : '' ?>>
                                <?= $bidang_opt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Pilih bidang dari dropdown</small>
                </div>

                <!-- Tahun Kendaraan -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-calendar-alt"></i> Tahun Kendaraan</label>
                    <select name="tahun_kendaraan" class="form-select" required>
                        <option value="">-- Pilih Tahun --</option>
                        <?php foreach ($tahun_options as $th): ?>
                            <option value="<?= $th ?>" <?= ($edit && $e['tahun_kendaraan'] == $th) ? 'selected' : '' ?>>
                                <?= $th ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text"><i class="fas fa-info-circle"></i> Tahun pembuatan / rakitan kendaraan</small>
                </div>

                <!-- Status (hanya saat Edit) -->
                <?php if ($edit): ?>
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-toggle-on"></i> Status Kendaraan</label>
                    <select name="status" class="form-select" required>
                        <?php foreach ($status_options as $status_opt): ?>
                            <option value="<?= $status_opt ?>" <?= ($e['status'] == $status_opt) ? 'selected' : '' ?>>
                                <?= $status_opt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="info-text">
                        <i class="fas fa-info-circle"></i>
                        <?= $e['status'] == 'Aktif' ? 'Kendaraan ini sedang <strong>AKTIF</strong>' : 'Kendaraan ini <strong>TIDAK AKTIF</strong>' ?>
                    </small>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <small class="info-text" style="display:block;background:#d4edda;padding:10px;border-radius:5px;color:#155724;">
                        <i class="fas fa-info-circle"></i> Status kendaraan baru otomatis <strong>AKTIF</strong>
                    </small>
                </div>
                <?php endif; ?>

                <div class="btn-group">
                    <button type="submit" name="<?= $edit ? 'update' : 'simpan' ?>" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= $edit ? 'Update' : 'Simpan' ?>
                    </button>
                    <?php if ($edit): ?>
                        <a href="kendaraan.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- =========== DATA TABLE =========== -->
        <div class="data-container">
            <div class="data-header">
                <h2 class="data-title"><i class="fas fa-list"></i> Daftar Asset</h2>
                <?php
                $aktif       = mysqli_num_rows(mysqli_query($connection, "SELECT id_kendaraan FROM kendaraan WHERE status='Aktif'"));
                $tidak_aktif = mysqli_num_rows(mysqli_query($connection, "SELECT id_kendaraan FROM kendaraan WHERE status='Tidak Aktif'"));
                ?>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <span class="stats-badge" style="background:#28a745;">✓ Aktif: <?= $aktif ?></span>
                    <span class="stats-badge" style="background:#dc3545;">✗ Tidak Aktif: <?= $tidak_aktif ?></span>
                </div>
            </div>

            <!-- ===================== TOOLBAR IMPORT / EXPORT ===================== -->
            <div class="toolbar-import-export">
                <a href="kendaraan.php?template=1" class="btn-sm btn-secondary-sm">
                    <i class="fas fa-file-arrow-down"></i> Download Format
                </a>
                <button type="button" class="btn-sm btn-import-sm" onclick="showImportModal()">
                    <i class="fas fa-file-import"></i> Import Data
                </button>
                <a href="kendaraan.php?export=1<?= $filterQueryString ? '&' . htmlspecialchars($filterQueryString) : '' ?>" class="btn-sm btn-excel-sm">
                    <i class="fas fa-file-excel"></i> Export Excel <?= ($keyword !== '' || $filter_status !== 'semua') ? '(Sesuai Filter)' : '(Semua Data)' ?>
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
                        <li>Header baris pertama WAJIB persis: <strong>nopol, jenis_kendaraan, bidang, tahun_kendaraan, status</strong> — kalau strukturnya berubah, import akan <strong>GAGAL TOTAL</strong> (tidak ada data yang dipaksa masuk).</li>
                        <li>Kolom <strong>jenis_kendaraan</strong> harus salah satu dari: <?= implode(', ', $jenis_kendaraan_options) ?>.</li>
                        <li>Kolom <strong>bidang</strong> harus salah satu dari: <?= implode(', ', $bidang_options) ?>.</li>
                        <li>Kolom <strong>tahun_kendaraan</strong> harus angka wajar antara 1990 – <?= $current_year ?>.</li>
                        <li>Kolom <strong>status</strong> isi <strong>Aktif</strong> atau <strong>Tidak Aktif</strong> (kosong = otomatis Aktif).</li>
                        <li>Nomor Asset (nopol) yang berstatus Aktif tidak boleh sama dengan nomor asset lain yang masih Aktif — baris yang bentrok otomatis dilewati.</li>
                        <li>Import hanya MENAMBAH data baru, bukan mengubah data lama (untuk itu pakai tombol Edit).</li>
                        <li>Saat <strong>Export</strong>, data yang ditarik mengikuti filter/pencarian yang sedang aktif di halaman ini.</li>
                    </ul>
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

            <!-- Filter & Search -->
            <div class="filter-search-container">
                <!-- Filter Status -->
                <div class="filter-box">
                    <select id="filterStatus" class="filter-select">
                        <option value="semua" <?= $filter_status == 'semua' ? 'selected' : '' ?>>🔍 Semua Status</option>
                        <option value="Aktif" <?= $filter_status == 'Aktif' ? 'selected' : '' ?>>✓ Aktif</option>
                        <option value="Tidak Aktif" <?= $filter_status == 'Tidak Aktif' ? 'selected' : '' ?>>✗ Tidak Aktif</option>
                    </select>
                </div>
                <!-- Filter Umur -->
                <div class="filter-umur-box">
                    <select id="filterUmur" class="filter-select">
                        <option value="semua" <?= $filter_umur == 'semua'   ? 'selected' : '' ?>> Semua Umur</option>
                        <option value="tertua" <?= $filter_umur == 'tertua' ? 'selected' : '' ?>> Tertua </option>
                        <option value="sedang" <?= $filter_umur == 'sedang' ? 'selected' : '' ?>> Sedang</option>
                        <option value="terbaru" <?= $filter_umur == 'terbaru' ? 'selected' : '' ?>> Terbaru </option>
                    </select>
                </div>
                <!-- Search -->
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input"
                           placeholder="Cari nomor asset, jenis, bidang..."
                           value="<?= htmlspecialchars($keyword) ?>">
                </div>
            </div>

            <!-- FORM pembungkus tabel untuk aksi massal -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                <input type="hidden" name="back_query" value="<?= htmlspecialchars($filterQueryString) ?>">

                <!-- Table -->
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th class="checkbox-col">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" title="Pilih semua">
                                </th>
                                <th style="width:45px;text-align:center;">No</th>
                                <th>Nomor Asset</th>
                                <th>Jenis Kendaraan</th>
                                <th>Bidang</th>
                                <th style="text-align:center;">Tahun</th>
                                <th style="text-align:center;">Umur</th>
                                <th style="text-align:center;">Status</th>
                                <th style="width:140px;text-align:center;">Keterangan</th>
                            </tr>
                        </thead>
                    <tbody id="tableBody">
                        <?php
                        $no = $offset + 1;
                        $q  = mysqli_query($connection, "SELECT * FROM kendaraan $where $order_by LIMIT $per_page OFFSET $offset");

                        if ($q && mysqli_num_rows($q) > 0):
                            while ($k = mysqli_fetch_assoc($q)):
                                // Badge bidang
                                $badge_class = 'bidang-badge';
                                switch ($k['bidang']) {
                                    case 'ANGKUTAN DALAM':    $badge_class .= ' bidang-angkutan-dalam'; break;
                                    case 'ANGKUTAN LUAR':     $badge_class .= ' bidang-angkutan-luar';  break;
                                    case 'ALAT BERAT WILAYAH 1': $badge_class .= ' bidang-alat-berat-1'; break;
                                    case 'ALAT BERAT WILAYAH 2': $badge_class .= ' bidang-alat-berat-2'; break;
                                    case 'ALAT BERAT WILAYAH 3': $badge_class .= ' bidang-alat-berat-3'; break;
                                    case 'PERGUDANGAN':       $badge_class .= ' bidang-gudang'; break;
                                }

                                // Hitung umur
                                $tahun_unit = intval($k['tahun_kendaraan']);
                                $umur_tahun = ($tahun_unit > 0) ? ($current_year - $tahun_unit) : null;

                                // Kategori umur: ≤3 = baru, 4-10 = sedang, >10 = tua
                                $umur_label = '-';
                                $umur_class = '';
                                if ($umur_tahun !== null) {
                                    $umur_label = $umur_tahun . ' Tahun';
                                    if ($umur_tahun <= 3)       { $umur_class = 'umur-badge umur-baru'; }
                                    elseif ($umur_tahun <= 10)  { $umur_class = 'umur-badge umur-sedang'; }
                                    else                        { $umur_class = 'umur-badge umur-tua'; }
                                }

                                $is_aktif = ($k['status'] == 'Aktif');
                                $status_class = $is_aktif ? 'status-badge status-aktif' : 'status-badge status-tidak-aktif';
                        ?>
                        <tr data-status="<?= htmlspecialchars($k['status']) ?>">
                            <td class="checkbox-col">
                                <input type="checkbox" class="row-checkbox" name="selected_ids[]"
                                       value="<?= $k['id_kendaraan'] ?>" onclick="onRowCheck(this)">
                            </td>
                            <td style="text-align:center;font-weight:700;color:#667eea;"><?= $no++ ?></td>
                            <td><span class="nopol-badge"><?= htmlspecialchars($k['nopol']) ?></span></td>
                            <td style="font-weight:600;color:#2c3e50;"><?= htmlspecialchars($k['jenis_kendaraan']) ?: '-' ?></td>
                            <td>
                                <?php if ($k['bidang']): ?>
                                    <span class="<?= $badge_class ?>"><?= htmlspecialchars($k['bidang']) ?></span>
                                <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                            </td>
                            <td style="text-align:center;font-weight:700;color:#2c3e50;">
                                <?= $tahun_unit > 0 ? $tahun_unit : '-' ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($umur_class): ?>
                                    <span class="<?= $umur_class ?>"><?= $umur_label ?></span>
                                <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="<?= $status_class ?>"><?= $is_aktif ? '✓ Aktif' : '✗ Tidak Aktif' ?></span>
                            </td>
                            <td>
                                <div class="action-btns" style="justify-content:center;">
                                    <a href="kendaraan.php?edit=<?= $k['id_kendaraan'] ?>"
                                       class="btn-action btn-edit <?= !$is_aktif ? 'btn-edit-inactive' : '' ?>"
                                       <?= !$is_aktif ? 'onclick="return confirmEditInactive();"' : '' ?>
                                       title="<?= !$is_aktif ? 'Kendaraan tidak aktif' : 'Edit kendaraan' ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="kendaraan.php?hapus=<?= $k['id_kendaraan'] ?>"
                                       class="btn-action btn-delete"
                                       onclick="return confirm('Hapus kendaraan <?= htmlspecialchars($k['nopol']) ?>?')">
                                        <i class="fas fa-trash"></i> Hapus
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="9" class="no-data">
                                <i class="fas fa-inbox"></i>
                                <p><?= $keyword
                                    ? "Tidak ditemukan data dengan kata kunci '" . htmlspecialchars($keyword) . "'"
                                    : ($filter_status != 'semua' ? "Tidak ada kendaraan dengan status $filter_status" : 'Belum ada data kendaraan') ?></p>
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
                'umur'   => $filter_umur,
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
            <div class="mt-4" style="margin-top:16px;">
                <p class="text-sm text-gray-600" style="margin-bottom:8px;">
                    Menampilkan
                    <span class="font-semibold"><?= $total_rows > 0 ? ($offset + 1) : 0 ?> – <?= min($offset + $per_page, $total_rows) ?></span>
                    dari <span class="font-semibold"><?= $total_rows ?></span> data kendaraan (50 data / halaman)
                </p>

                <div class="flex flex-wrap items-center gap-1">
                    <a href="?<?= htmlspecialchars($prev_qs) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page <= 1
                                    ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        <i class="fas fa-chevron-left text-xs mr-1"></i> Prev
                    </a>

                    <?php $qs1 = http_build_query(array_merge($base_params, ['page' => 1])); ?>
                    <a href="?<?= htmlspecialchars($qs1) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page === 1
                                    ? 'bg-green-700 text-white border-green-700'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        1
                    </a>

                    <?php if ($page_start > 2): ?>
                    <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                    <?php endif; ?>

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

                    <?php if ($page_end < $total_pages - 1): ?>
                    <span class="px-2 py-1.5 text-gray-400 text-sm select-none">…</span>
                    <?php endif; ?>

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

                    <a href="?<?= htmlspecialchars($next_qs) ?>"
                       class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm font-medium
                              <?= $page >= $total_pages
                                    ? 'opacity-40 pointer-events-none bg-gray-100 text-gray-400 border-gray-200'
                                    : 'bg-white text-gray-700 hover:bg-gray-50 border-gray-300' ?>">
                        Next <i class="fas fa-chevron-right text-xs ml-1"></i>
                    </a>

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

<!-- Modal Import Data (CSV) -->
<div class="modal-overlay" id="importModal" onclick="hideImportModal()">
    <div class="modal-dialog" onclick="event.stopPropagation()" style="max-width: 500px; width: 90%;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import"></i> Import Data Kendaraan</h5>
                <button type="button" class="btn-close" onclick="hideImportModal()">×</button>
            </div>
            <div class="modal-body" style="text-align: left;">
                <div class="note-box" style="margin-bottom: 15px; padding: 14px 16px;">
                    <strong><i class="fas fa-triangle-exclamation"></i> Sebelum upload, pastikan:</strong>
                    <ul>
                        <li>File hasil download dari tombol <strong>"Download Format"</strong>, header/urutan kolom belum diubah.</li>
                        <li>Format file <strong>.csv</strong> (bukan .xls/.xlsx).</li>
                        <li>Isi kolom jenis_kendaraan, bidang, dan status sesuai daftar pilihan (lihat catatan di halaman).</li>
                        <li>Jika struktur file tidak sesuai, sistem akan <strong>menolak seluruh import</strong> (tidak ada data yang dipaksa masuk).</li>
                    </ul>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-file-csv"></i> Pilih File CSV</label>
                        <input type="file" name="file_import" accept=".csv" required class="form-input">
                    </div>
                    <button type="submit" name="import_submit" class="btn btn-import-sm" style="width:100%; justify-content:center; padding:12px; margin-top:10px;">
                        <i class="fas fa-upload"></i> Upload &amp; Import Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Auto uppercase nopol
document.querySelector('input[name="nopol"]').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

// Konfirmasi edit kendaraan tidak aktif
function confirmEditInactive() {
    return confirm('⚠️ Kendaraan TIDAK AKTIF!\n\nYakin ingin edit?');
}

// ========== REDIRECT saat filter/pencarian berubah (server-side, ikut pagination) ==========
function buildUrl() {
    const status  = document.getElementById('filterStatus').value;
    const umur    = document.getElementById('filterUmur').value;
    const keyword = document.getElementById('searchInput').value;
    let url = 'kendaraan.php?status=' + encodeURIComponent(status) + '&umur=' + encodeURIComponent(umur);
    if (keyword) url += '&search=' + encodeURIComponent(keyword);
    // page selalu direset ke 1 saat filter/pencarian berubah
    return url;
}

document.getElementById('filterStatus').addEventListener('change', function() {
    window.location.href = buildUrl();
});

document.getElementById('filterUmur').addEventListener('change', function() {
    window.location.href = buildUrl();
});

// Live search dengan debounce -> redirect ke server (bukan filter di browser),
// supaya data yang ditarik dari database memang cuma yang sesuai pencarian
let searchTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        window.location.href = buildUrl();
    }, 800);
});

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
        if (status === 'Aktif') adaAktif = true;
        if (status === 'Tidak Aktif') adaNonaktif = true;
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
        confirmMsg = '⚠️ Nonaktifkan ' + checked.length + ' data kendaraan terpilih?';
    } else if (action === 'aktifkan') {
        confirmMsg = '✅ Aktifkan kembali ' + checked.length + ' data kendaraan terpilih?\n\n(Kendaraan yang nomor asset-nya bentrok dengan kendaraan lain yang masih Aktif akan otomatis dilewati.)';
    } else if (action === 'hapus') {
        confirmMsg = '🗑️ HAPUS PERMANEN ' + checked.length + ' data kendaraan terpilih?\n\n⚠️ Data yang masih dipakai pada Permintaan Perbaikan otomatis akan dilewati (hanya bisa dinonaktifkan).\nData lainnya TIDAK BISA dikembalikan!';
    }

    if (!confirm(confirmMsg)) return;

    document.getElementById('bulkActionInput').value = action;
    document.getElementById('bulkForm').submit();
}

// Toggle catatan import (collapsible)
function toggleNoteImport() {
    const content = document.getElementById('noteImportContent');
    const icon = document.getElementById('noteImportIcon');
    const isOpen = content.style.display === 'block';
    content.style.display = isOpen ? 'none' : 'block';
    icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

// Scroll ke atas saat edit
<?php if ($edit): ?>
window.scrollTo({ top: 0, behavior: 'smooth' });
<?php endif; ?>
</script>

</body>
</html>
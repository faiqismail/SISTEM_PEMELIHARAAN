<?php
/* =====================================================================
   CSV HELPER — PHP NATIVE, TANPA EKSTENSI TAMBAHAN
   Dipakai KHUSUS untuk fitur Template & Import di rekanan.php.

   KENAPA CSV, BUKAN "HTML disamarkan jadi .xls" (seperti di xlsx_helper.php)?
   Trik HTML-as-xls memang bisa dibuka Excel, TAPI begitu file itu dibuka
   lalu di-SAVE ULANG oleh Excel, Excel mengubah strukturnya jadi frameset
   multi-file (sheet001.htm, sheet002.htm, dst) yang isinya kode JavaScript
   pembangun tab Excel — bukan lagi 1 tabel HTML polos. Kalau file hasil
   save-ulang itu diupload lagi, parser HTML akan salah baca dan
   menghasilkan data ngaco.

   CSV adalah teks polos (plain text) yang TIDAK PERNAH berubah struktur
   walau dibuka-edit-simpan berkali-kali oleh Excel/aplikasi spreadsheet
   apapun. Karena itu jauh lebih aman untuk alur:
   Download Format -> buka & edit di Excel -> Simpan -> Upload lagi.
===================================================================== */

/**
 * Bikin file .csv dan langsung kirim ke browser (force download).
 * $rows = [ [kolom1, kolom2, ...], [kolom1, kolom2, ...], ... ]
 * Baris pertama dianggap header oleh convention pemanggil (tidak ada
 * styling di CSV, jadi tidak perlu parameter headerRows di sini).
 */
function native_csv_output(array $rows, string $filename, string $delimiter = ';') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // BOM supaya Excel membaca karakter Indonesia (spasi/simbol) dengan benar
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($out, $row, $delimiter);
    }
    fclose($out);
    exit;
}

/**
 * Baca file .csv yang diupload user.
 * Return array of rows, tiap row = array kolom (index 0,1,2,...).
 * Otomatis mendeteksi delimiter (';' atau ',') dari baris pertama,
 * supaya tetap terbaca walau Excel regional Indonesia menyimpan
 * dengan titik-koma, atau Excel/OS lain menyimpan dengan koma.
 *
 * Melempar Exception kalau file tidak bisa dibaca / kosong — supaya
 * pemanggil bisa menggagalkan proses import dengan bersih, tanpa
 * memaksakan data yang tidak jelas untuk masuk ke database.
 */
function native_csv_read($filepath) {
    if (!is_readable($filepath)) {
        throw new Exception('Tidak bisa membaca file');
    }

    $content = file_get_contents($filepath);
    if ($content === false || trim($content) === '') {
        throw new Exception('File kosong atau tidak bisa dibaca');
    }

    // Buang BOM UTF-8 kalau ada
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    // Deteksi delimiter dari baris pertama
    $firstLine = strtok($content, "\r\n");
    $delimiter = (substr_count((string) $firstLine, ';') >= substr_count((string) $firstLine, ',')) ? ';' : ',';

    $lines = preg_split('/\r\n|\r|\n/', $content);

    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line, $delimiter);
    }

    if (count($rows) === 0) {
        throw new Exception('File tidak berisi data yang bisa dibaca');
    }

    return $rows;
}
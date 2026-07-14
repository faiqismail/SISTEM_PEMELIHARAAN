<?php
/* =====================================================================
   EXCEL HELPER — PHP NATIVE, TANPA EKSTENSI TAMBAHAN (tanpa ZipArchive)
   Menghasilkan file .xls berbasis HTML table yang dibaca Excel sebagai
   spreadsheet asli (kolom terpisah, bisa diwarnai, dsb).
   Hanya memakai fungsi PHP inti: DOMDocument (selalu tersedia).
===================================================================== */

function html_xls_escape($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/**
 * Bikin file .xls (HTML table) dan langsung kirim ke browser (force download).
 * $sheets = [ ['name'=>'Data Rekanan', 'rows'=>[[...],[...]], 'headerRows'=>1, 'widths'=>[25,35,15,10]], ... ]
 */
function native_xlsx_output(array $sheets, string $filename) {

    // Deklarasi nama-nama sheet (supaya Excel kasih nama tab sesuai urutan tabel di bawah)
    $wsNames = '';
    foreach ($sheets as $sheet) {
        $name = html_xls_escape($sheet['name']);
        $wsNames .= '<x:ExcelWorksheet><x:Name>' . $name . '</x:Name>'
                  . '<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet>';
    }

    $tables = '';
    foreach ($sheets as $sheet) {
        $rows       = $sheet['rows'];
        $headerRows = $sheet['headerRows'] ?? 0;
        $widths     = $sheet['widths'] ?? [];

        $colgroup = '';
        foreach ($widths as $w) {
            $px = (int) round($w * 7); // perkiraan lebar karakter -> pixel
            $colgroup .= '<col style="width:' . $px . 'px">';
        }

        $rowsHtml = '';
        foreach ($rows as $rIdx => $row) {
            $isHeader = ($rIdx + 1) <= $headerRows;
            $rowsHtml .= '<tr>';
            foreach ($row as $val) {
                if ($isHeader) {
                    $rowsHtml .= '<td style="background:#FFFF00;font-weight:bold;text-align:center;border:1px solid #000000;">'
                               . html_xls_escape($val) . '</td>';
                } else {
                    // mso-number-format:\@ memaksa Excel membaca sel sebagai TEKS
                    // (supaya nomor telepon yang diawali 0 tidak hilang angka depannya)
                    $rowsHtml .= '<td style="border:1px solid #000000; mso-number-format:\@;">'
                               . html_xls_escape($val) . '</td>';
                }
            }
            $rowsHtml .= '</tr>';
        }

        $tables .= '<table border="1" cellspacing="0" cellpadding="4">'
                 . ($colgroup ? '<colgroup>' . $colgroup . '</colgroup>' : '')
                 . '<tbody>' . $rowsHtml . '</tbody></table>';
    }

    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
          . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
          . 'xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
<x:ExcelWorkbook><x:ExcelWorksheets>' . $wsNames . '</x:ExcelWorksheets></x:ExcelWorkbook>
</xml><![endif]-->
</head>
<body>' . $tables . '</body></html>';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF"; // BOM supaya karakter Indonesia (spasi, simbol) terbaca benar
    echo $html;
    exit;
}

/**
 * Baca sheet PERTAMA dari file .xls (HTML table) yang diupload user.
 * Return array of rows, tiap row = array kolom (index 0,1,2,...).
 */
function native_xlsx_read($filepath) {
    $html = file_get_contents($filepath);
    if ($html === false) {
        throw new Exception('Tidak bisa membaca file');
    }

    // buang BOM kalau ada
    $html = preg_replace('/^\xEF\xBB\xBF/', '', $html);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) {
        throw new Exception('Tabel data tidak ditemukan di dalam file. Pastikan file hasil download dari tombol "Download Format" dan belum diubah formatnya.');
    }

    $table = $tables->item(0); // ambil sheet/tabel pertama saja
    $rows  = [];

    foreach ($table->getElementsByTagName('tr') as $tr) {
        $rowData = [];
        foreach ($tr->childNodes as $cell) {
            $tag = strtolower($cell->nodeName);
            if ($tag === 'td' || $tag === 'th') {
                $rowData[] = trim($cell->textContent);
            }
        }
        if (count($rowData) > 0) {
            $rows[] = $rowData;
        }
    }

    return $rows;
}
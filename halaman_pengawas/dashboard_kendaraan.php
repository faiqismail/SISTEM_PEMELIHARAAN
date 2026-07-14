<?php
include "../inc/config.php";
include "../inc/auth.php";

requireAuth('pengawas');

$id_login = $_SESSION['id_login'];
$id_user  = $_SESSION['id_user'];
$username = $_SESSION['username'] ?? 'User';
$role     = $_SESSION['role']     ?? 'Staff';

// =========================
// Bidang user (BUKAN dari GET lagi, tapi dari SESSION hasil login)
// Pengawas hanya boleh melihat data sesuai bidang yang melekat pada akunnya.
// =========================
$bidang_user = $_SESSION['bidang'] ?? '';

// Jaga-jaga: kalau ternyata bidang belum diset (harusnya sudah dicegah saat login),
// jangan tampilkan data siapa pun -> query dipaksa tidak match apapun.
if ($bidang_user === '') {
    $bidang_filter = "AND 1 = 0";
} else {
    $bidang_esc = mysqli_real_escape_string($connection, $bidang_user);
    $bidang_filter = "AND k.bidang = '$bidang_esc'";
}

// =========================
// Ambil semua kendaraan AKTIF pada BIDANG USER beserta info apakah sedang ada
// perbaikan berjalan (status permintaan_perbaikan != 'Selesai' dianggap RUSAK)
// =========================
$sql = "SELECT 
            k.id_kendaraan,
            k.nopol,
            k.jenis_kendaraan,
            k.bidang,
            k.tahun_kendaraan,
            k.status,
            (SELECT COUNT(*) 
                FROM permintaan_perbaikan pp 
                WHERE pp.id_kendaraan = k.id_kendaraan 
                AND pp.status != 'Selesai') AS ongoing_repair,
            (SELECT pp2.status 
                FROM permintaan_perbaikan pp2 
                WHERE pp2.id_kendaraan = k.id_kendaraan 
                ORDER BY pp2.created_at DESC LIMIT 1) AS status_perbaikan_terakhir
        FROM kendaraan k
        WHERE k.status = 'Aktif'
        $bidang_filter
        ORDER BY k.nopol ASC";

$result = mysqli_query($connection, $sql);

$list_ready = [];
$list_rusak = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        if ((int)$row['ongoing_repair'] > 0) {
            $list_rusak[] = $row;
        } else {
            $list_ready[] = $row;
        }
    }
}

$total_aktif = count($list_ready) + count($list_rusak);
$total_ready = count($list_ready);
$total_rusak = count($list_rusak);

// =========================
// Total PERMINTAAN PERBAIKAN yang masih berjalan (belum "Selesai") pada bidang user.
// Ini beda dengan $total_rusak (jumlah UNIT kendaraan), karena 1 kendaraan bisa
// punya lebih dari 1 permintaan yang masih berjalan.
// =========================
$sql_total_permintaan = "SELECT COUNT(*) AS total
        FROM permintaan_perbaikan pp
        INNER JOIN kendaraan k ON k.id_kendaraan = pp.id_kendaraan
        WHERE pp.status != 'Selesai'
        $bidang_filter";

$res_total_permintaan = mysqli_query($connection, $sql_total_permintaan);
$total_permintaan_berjalan = 0;
if ($res_total_permintaan) {
    $row_tp = mysqli_fetch_assoc($res_total_permintaan);
    $total_permintaan_berjalan = (int)($row_tp['total'] ?? 0);
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../foto/favicon.ico" type="image/x-icon">
<title>Dashboard Pantau Kendaraan</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    .dashboard-bg {
        font-family: 'Inter', sans-serif;
        background: rgb(185, 224, 204);
        min-height: 100vh;
        position: relative;
        margin-left: 260px; /* ← geser konten sesuai lebar sidebar fixed, biar tidak ketiban */
        transition: margin-left 0.3s ease;
    }

    @media (max-width: 768px) {
        .dashboard-bg {
            margin-left: 0; /* ← sidebar jadi off-canvas di mobile, jadi konten full width */
        }
    }

    /* Header gradient bar */
    .dashboard-header {
        background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%);
        padding: 1.1rem 1.5rem;
        border-radius: 0 0 22px 22px;
        box-shadow: 0 8px 24px -8px rgba(15, 155, 142, 0.45);
        margin-bottom: 1.5rem;
    }

    .no-data-row td { text-align: center; color: #94a3b8; padding: 2.5rem 0; }
    table tbody tr { transition: background-color .15s ease; }

    .stat-card { transition: transform .2s ease, box-shadow .2s ease; }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px -10px rgba(15,23,42,.18); }

    .page-btn {
        min-width: 2rem;
        height: 2rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid #e2e8f0;
        color: #475569;
        background: #fff;
        cursor: pointer;
        transition: background-color .15s ease, color .15s ease;
    }
    .page-btn:hover:not(:disabled) { background: #f1f5f9; }
    .page-btn:disabled { opacity: .4; cursor: not-allowed; }
    .page-btn.active { background: #0f172a; color: #fff; border-color: #0f172a; }
</style>
</head>
<body>
<?php include "navbar_pengawas.php"; ?>
<div class="dashboard-bg overflow-hidden">

 <!-- HEADER BAR -->
 <div class="dashboard-header relative z-10">
     <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:items-center md:justify-between gap-4">
         <div class="flex items-center gap-3 text-white">
             <span class="flex items-center justify-center w-10 h-10 rounded-xl bg-white/20 backdrop-blur-sm text-lg">
                 <i class="fa-solid fa-truck-fast"></i>
             </span>
             <div>
                 <h1 class="text-lg sm:text-xl font-bold leading-tight">Dashboard Pantau Kendaraan</h1>
             </div>
             <?php if ($bidang_user !== ''): ?>
                 <span class="ml-1 px-3 py-1 bg-white/20 rounded-full text-xs font-semibold text-white whitespace-nowrap">
                     <i class="fa-solid fa-building mr-1"></i>Bidang: <?= h($bidang_user) ?>
                 </span>
             <?php else: ?>
                 <span class="ml-1 px-3 py-1 bg-red-500/70 rounded-full text-xs font-semibold text-white whitespace-nowrap">
                     <i class="fa-solid fa-triangle-exclamation mr-1"></i>Bidang belum diset
                 </span>
             <?php endif; ?>
         </div>
         <!-- Dropdown pemilihan bidang DIHAPUS: pengawas otomatis hanya melihat bidangnya sendiri -->
     </div>
 </div>

 <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-10 relative z-10">

     <?php if ($bidang_user === ''): ?>
     <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm">
         <i class="fa-solid fa-circle-exclamation mr-2"></i>
         Akun Anda belum memiliki bidang yang terdaftar, sehingga data kendaraan tidak dapat ditampilkan.
         Silakan hubungi administrator untuk pengaturan bidang.
     </div>
     <?php endif; ?>

     <!-- SUMMARY CARDS -->
     <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
         <div class="stat-card bg-white/90 backdrop-blur rounded-2xl shadow-md border border-white/60 p-5 flex items-center gap-4 overflow-hidden relative">
             <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-500 text-white flex items-center justify-center text-lg shrink-0 shadow-lg shadow-indigo-500/30">
                 <i class="fa-solid fa-car"></i>
             </div>
             <div>
                 <p class="text-sm text-slate-500">Total Kendaraan Aktif</p>
                 <p class="text-2xl sm:text-3xl font-bold text-slate-900"><?= $total_aktif ?></p>
             </div>
         </div>

         <div class="stat-card bg-white/90 backdrop-blur rounded-2xl shadow-md border border-white/60 p-5 flex items-center gap-4 overflow-hidden relative">
             <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-teal-400 to-emerald-500 text-white flex items-center justify-center text-lg shrink-0 shadow-lg shadow-emerald-500/30">
                 <i class="fa-solid fa-circle-check"></i>
             </div>
             <div>
                 <p class="text-sm text-slate-500">Kendaraan Ready</p>
                 <p class="text-2xl sm:text-3xl font-bold text-emerald-600"><?= $total_ready ?></p>
             </div>
         </div>

         <div class="stat-card bg-white/90 backdrop-blur rounded-2xl shadow-md border border-white/60 p-5 flex items-center gap-4 overflow-hidden relative">
             <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-rose-400 to-orange-500 text-white flex items-center justify-center text-lg shrink-0 shadow-lg shadow-rose-500/30">
                 <i class="fa-solid fa-triangle-exclamation"></i>
             </div>
             <div>
                 <p class="text-sm text-slate-500">Asset Dalam Perbaikan</p>
                 <p class="text-2xl sm:text-3xl font-bold text-rose-600"><?= $total_rusak ?></p>
             </div>
         </div>

         <div class="stat-card bg-white/90 backdrop-blur rounded-2xl shadow-md border border-white/60 p-5 flex items-center gap-4 overflow-hidden relative">
             <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-400 to-orange-600 text-white flex items-center justify-center text-lg shrink-0 shadow-lg shadow-amber-500/30">
                 <i class="fa-solid fa-list-check"></i>
             </div>
             <div>
                 <p class="text-sm text-slate-500">Permintaan Perbaikan Berjalan</p>
                 <p class="text-2xl sm:text-3xl font-bold text-amber-600"><?= $total_permintaan_berjalan ?></p>
             </div>
         </div>
     </div>

     <!-- LIST READY & RUSAK -->
     <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

         <!-- READY -->
         <div class="bg-white/95 backdrop-blur rounded-2xl shadow-lg border border-white/60 overflow-hidden">
             <div class="flex items-center justify-between px-5 py-4 bg-gradient-to-r from-teal-500 to-emerald-500">
                 <span class="flex items-center gap-2 text-white font-semibold text-sm">
                     <i class="fa-solid fa-circle-check"></i> Kendaraan Ready
                 </span>
                 <span class="text-xs font-semibold bg-white/25 text-white px-2.5 py-1 rounded-full"><?= $total_ready ?> unit</span>
             </div>

             <div class="p-5">
                 <div class="relative mb-4">
                     <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                     <input type="text" id="searchReady"
                         class="w-full rounded-full border border-slate-200 pl-10 pr-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500"
                         placeholder="Cari No Asset, jenis kendaraan, atau tahun...">
                 </div>

                 <p id="countReady" class="text-xs text-slate-500 mb-3 px-1"></p>

                 <div class="overflow-y-auto max-h-[440px] rounded-xl border border-slate-100">
                     <table class="w-full text-sm" id="tableReady">
                         <thead class="sticky top-0 bg-emerald-50 text-emerald-700 uppercase text-xs">
                             <tr>
                                 <th class="text-left font-semibold px-4 py-3">No Asset</th>
                                 <th class="text-left font-semibold px-4 py-3">Jenis Kendaraan</th>
                                 <th class="text-left font-semibold px-4 py-3">Tahun</th>
                             </tr>
                         </thead>
                         <tbody class="divide-y divide-slate-100">
                             <?php if (empty($list_ready)): ?>
                                 <tr class="no-data-row"><td colspan="3"><i class="fa-solid fa-inbox mb-2 text-2xl block"></i>Tidak ada data kendaraan ready</td></tr>
                             <?php else: foreach ($list_ready as $r): ?>
                                 <tr class="hover:bg-emerald-50/60">
                                     <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap"><?= h($r['nopol']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['jenis_kendaraan']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['tahun_kendaraan']) ?></td>
                                 </tr>
                             <?php endforeach; endif; ?>
                         </tbody>
                     </table>
                 </div>

                 <div id="paginationReady" class="flex items-center justify-center gap-1.5 mt-4"></div>
             </div>
         </div>

         <!-- RUSAK -->
         <div class="bg-white/95 backdrop-blur rounded-2xl shadow-lg border border-white/60 overflow-hidden">
             <div class="flex items-center justify-between px-5 py-4 bg-gradient-to-r from-rose-500 to-orange-500">
                 <span class="flex items-center gap-2 text-white font-semibold text-sm">
                     <i class="fa-solid fa-triangle-exclamation"></i> Rusak / Dalam Perbaikan
                 </span>
                 <span class="text-xs font-semibold bg-white/25 text-white px-2.5 py-1 rounded-full"><?= $total_rusak ?> unit</span>
             </div>

             <div class="p-5">
                 <div class="relative mb-4">
                     <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                     <input type="text" id="searchRusak"
                         class="w-full rounded-full border border-slate-200 pl-10 pr-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-rose-500/40 focus:border-rose-500"
                         placeholder="Cari No Asset, jenis kendaraan, atau tahun...">
                 </div>

                 <p id="countRusak" class="text-xs text-slate-500 mb-3 px-1"></p>

                 <div class="overflow-y-auto max-h-[440px] rounded-xl border border-slate-100">
                     <table class="w-full text-sm" id="tableRusak">
                         <thead class="sticky top-0 bg-rose-50 text-rose-700 uppercase text-xs">
                             <tr>
                                 <th class="text-left font-semibold px-4 py-3">No Asset</th>
                                 <th class="text-left font-semibold px-4 py-3">Jenis Kendaraan</th>
                                 <th class="text-left font-semibold px-4 py-3">Tahun</th>
                                 <th class="text-center font-semibold px-4 py-3">Permintaan </th>
                             </tr>
                         </thead>
                         <tbody class="divide-y divide-slate-100">
                             <?php if (empty($list_rusak)): ?>
                                 <tr class="no-data-row"><td colspan="4"><i class="fa-solid fa-check mb-2 text-2xl block"></i>Tidak ada kendaraan yang rusak</td></tr>
                             <?php else: foreach ($list_rusak as $r): ?>
                                 <tr class="hover:bg-rose-50/60">
                                     <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap"><?= h($r['nopol']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['jenis_kendaraan']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['tahun_kendaraan']) ?></td>
                                     <td class="px-4 py-3 text-center">
                                         <?php if ((int)$r['ongoing_repair'] > 1): ?>
                                             <span class="inline-flex items-center gap-1 bg-orange-100 text-orange-700 font-bold px-2.5 py-1 rounded-full text-xs" title="Kendaraan ini punya lebih dari 1 permintaan perbaikan yang masih berjalan">
                                                 <i class="fa-solid"></i> <?= (int)$r['ongoing_repair'] ?>x
                                             </span>
                                         <?php else: ?>
                                             <span class="text-slate-500 text-xs font-semibold"><?= (int)$r['ongoing_repair'] ?>x</span>
                                         <?php endif; ?>
                                     </td>
                                 </tr>
                             <?php endforeach; endif; ?>
                         </tbody>
                     </table>
                 </div>

                 <div id="paginationRusak" class="flex items-center justify-center gap-1.5 mt-4"></div>
             </div>
         </div>

     </div>
 </main>
</div>

<script>
/**
 * attachTable: gabungan search + pagination client-side.
 * Data tetap sama (sudah di-render server), tapi ditampilkan per halaman
 * supaya render awal tidak berat kalau datanya banyak.
 */
function attachTable(inputId, tableId, countId, paginationId, pageSize) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    const countEl = document.getElementById(countId);
    const paginationEl = document.getElementById(paginationId);
    if (!table) return;

    const allRows = Array.from(table.querySelectorAll('tbody tr'))
        .filter(row => !row.classList.contains('no-data-row'));
    const totalRows = allRows.length;

    let filteredRows = allRows.slice();
    let currentPage = 1;

    function render() {
        const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        // sembunyikan semua baris dulu
        allRows.forEach(row => { row.style.display = 'none'; });

        // tampilkan hanya baris pada halaman aktif
        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        filteredRows.slice(start, end).forEach(row => { row.style.display = ''; });

        // update label jumlah data
        if (countEl) {
            const keyword = input ? input.value.trim() : '';
            if (keyword === '') {
                countEl.textContent = totalRows > 0
                    ? `Total ${totalRows} data — Halaman ${currentPage} dari ${totalPages}`
                    : '';
            } else {
                countEl.textContent = filteredRows.length > 0
                    ? `Ditemukan ${filteredRows.length} dari ${totalRows} data — Halaman ${currentPage} dari ${totalPages}`
                    : `Ditemukan 0 dari ${totalRows} data`;
            }
        }

        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        if (!paginationEl) return;
        paginationEl.innerHTML = '';
        if (filteredRows.length === 0 || totalPages <= 1) return;

        function makeBtn(label, page, opts) {
            opts = opts || {};
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'page-btn' + (opts.active ? ' active' : '');
            btn.textContent = label;
            btn.disabled = !!opts.disabled;
            btn.addEventListener('click', function () {
                currentPage = page;
                render();
            });
            return btn;
        }

        paginationEl.appendChild(makeBtn('‹', currentPage - 1, { disabled: currentPage === 1 }));

        // tampilkan maksimal 5 nomor halaman di sekitar halaman aktif
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + 4);
        startPage = Math.max(1, endPage - 4);

        if (startPage > 1) {
            paginationEl.appendChild(makeBtn('1', 1));
            if (startPage > 2) {
                const dots = document.createElement('span');
                dots.className = 'text-xs text-slate-400 px-1';
                dots.textContent = '…';
                paginationEl.appendChild(dots);
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            paginationEl.appendChild(makeBtn(String(p), p, { active: p === currentPage }));
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const dots = document.createElement('span');
                dots.className = 'text-xs text-slate-400 px-1';
                dots.textContent = '…';
                paginationEl.appendChild(dots);
            }
            paginationEl.appendChild(makeBtn(String(totalPages), totalPages));
        }

        paginationEl.appendChild(makeBtn('›', currentPage + 1, { disabled: currentPage === totalPages }));
    }

    if (input) {
        input.addEventListener('keyup', function () {
            const keyword = this.value.toLowerCase().trim();
            filteredRows = allRows.filter(row => row.textContent.toLowerCase().includes(keyword));
            currentPage = 1;
            render();
        });
    }

    render();
}

// 10 baris per halaman, silakan diubah sesuai kebutuhan
attachTable('searchReady', 'tableReady', 'countReady', 'paginationReady', 30);
attachTable('searchRusak', 'tableRusak', 'countRusak', 'paginationRusak', 30);
</script>
</body>
</html>
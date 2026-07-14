<?php
include "../inc/config.php";
include "../inc/auth.php";

checkAuth('admin');

$id_login = $_SESSION['id_login'];
$id_user  = $_SESSION['id_user'];
$username = $_SESSION['username'] ?? 'User';
$role     = $_SESSION['role']     ?? 'Staff';

// =========================
// Filter (untuk tabel detail Ready/Rusak di bawah)
// =========================
$bidang = isset($_GET['bidang']) ? trim($_GET['bidang']) : '';
$bidang_esc = mysqli_real_escape_string($connection, $bidang);
$bidang_filter = $bidang !== '' ? "AND k.bidang = '$bidang_esc'" : '';

// List bidang untuk dropdown
$query_bidang = "SELECT DISTINCT bidang FROM kendaraan WHERE bidang IS NOT NULL AND bidang != '' ORDER BY bidang";
$result_bidang = mysqli_query($connection, $query_bidang);

// =========================
// RINGKASAN PER BIDANG (SELALU tampil semua bidang, TIDAK terpengaruh filter)
// Ready, Rusak (unit), dan Permintaan Perbaikan yang masih jalan (bisa >1 per unit)
// =========================
$sql_summary = "SELECT 
            k.bidang,
            COUNT(*) AS total_aktif,
            SUM(CASE WHEN ongoing.cnt > 0 THEN 1 ELSE 0 END) AS total_rusak,
            SUM(CASE WHEN ongoing.cnt IS NULL OR ongoing.cnt = 0 THEN 1 ELSE 0 END) AS total_ready,
            SUM(COALESCE(ongoing.cnt, 0)) AS total_permintaan_jalan
        FROM kendaraan k
        LEFT JOIN (
            SELECT id_kendaraan, COUNT(*) AS cnt
            FROM permintaan_perbaikan
            WHERE status != 'Selesai'
            GROUP BY id_kendaraan
        ) ongoing ON ongoing.id_kendaraan = k.id_kendaraan
        WHERE k.status = 'Aktif'
        GROUP BY k.bidang
        ORDER BY k.bidang ASC";

$result_summary = mysqli_query($connection, $sql_summary);
$bidang_summary = [];
if ($result_summary) {
    while ($row = mysqli_fetch_assoc($result_summary)) {
        $bidang_summary[] = $row;
    }
}

// =========================
// Ambil semua kendaraan AKTIF (sesuai filter bidang) beserta info apakah
// sedang ada perbaikan berjalan (status permintaan_perbaikan != 'Selesai' = RUSAK)
// Ini untuk tabel detail Ready/Rusak di bawah, TETAP ikut filter seperti semula.
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
// Total PERMINTAAN PERBAIKAN yang masih berjalan (belum "Selesai"), ikut filter bidang.
// Beda dengan $total_rusak (jumlah UNIT), karena 1 kendaraan bisa punya >1 permintaan jalan.
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
include "navbar.php";
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
    }

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

    .bidang-card {
        transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        text-decoration: none;
    }
    .bidang-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -8px rgba(15,23,42,.18); }
    .bidang-card.active { border-color: #0f172a; box-shadow: 0 0 0 2px #0f172a inset; }

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
             <?php if ($bidang !== ''): ?>
                 <span class="ml-1 px-3 py-1 bg-white/20 rounded-full text-xs font-semibold text-white whitespace-nowrap">
                     <i class="fa-solid fa-filter mr-1"></i><?= h($bidang) ?>
                 </span>
             <?php endif; ?>
         </div>

         <form method="GET" class="flex items-center gap-2 w-full md:w-auto">
             <div class="relative flex-1 md:w-56">
                 <select name="bidang" onchange="this.form.submit()"
                     class="w-full appearance-none rounded-xl border-0 bg-white/95 pl-3 pr-9 py-2.5 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-white/70">
                     <option value="">Semua Bidang</option>
                     <?php if ($result_bidang): while ($b = mysqli_fetch_assoc($result_bidang)): ?>
                         <option value="<?= h($b['bidang']) ?>" <?= $bidang === $b['bidang'] ? 'selected' : '' ?>>
                             <?= h($b['bidang']) ?>
                         </option>
                     <?php endwhile; endif; ?>
                 </select>
                 <i class="fa-solid fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400 pointer-events-none"></i>
             </div>
             <?php if ($bidang !== ''): ?>
                 <a href="?" class="shrink-0 text-sm px-3 py-2.5 rounded-xl bg-white/20 text-white hover:bg-white/30 transition">
                     <i class="fa-solid fa-rotate-left"></i>
                 </a>
             <?php endif; ?>
         </form>
     </div>
 </div>

 <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-10 relative z-10">

     <!-- ===================================================== -->
     <!-- RINGKASAN PER BIDANG - SELALU TAMPIL SEMUA BIDANG      -->
     <!-- Ready, Rusak, dan Permintaan Jalan per bidang.         -->
     <!-- Tidak hilang / tidak berubah jadi 1 bidang saat filter -->
     <!-- dipakai. Klik kartu = shortcut untuk filter tabel.     -->
     <!-- ===================================================== -->
     <div class="mb-8">
         <h2 class="text-sm font-bold text-slate-700 mb-3 px-1">
             <i class="fa-solid fa-building mr-1.5 text-slate-500"></i>Ringkasan Tiap Bidang
         </h2>

         <?php if (empty($bidang_summary)): ?>
             <div class="bg-white/80 rounded-2xl border border-white/60 p-5 text-sm text-slate-500 text-center">
                 Belum ada data kendaraan aktif.
             </div>
         <?php else: ?>
         <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
             <?php foreach ($bidang_summary as $bs):
                 $is_active = ($bidang !== '' && $bidang === $bs['bidang']);
                 $link = '?bidang=' . urlencode($bs['bidang']);
             ?>
             <a href="<?= h($link) ?>"
                class="bidang-card bg-white/90 backdrop-blur rounded-2xl shadow-md border-2 <?= $is_active ? 'active border-slate-900' : 'border-white/60' ?> p-4 flex flex-col gap-2">
                 <p class="text-xs font-bold text-slate-700 truncate" title="<?= h($bs['bidang']) ?>">
                     <?= h($bs['bidang']) ?>
                 </p>
                 <div class="flex items-center justify-between text-xs">
                     <span class="inline-flex items-center gap-1 text-emerald-600 font-semibold">
                         <i class="fa-solid fa-circle-check"></i> Ready
                     </span>
                     <span class="font-bold text-emerald-600"><?= (int)$bs['total_ready'] ?></span>
                 </div>
                 <div class="flex items-center justify-between text-xs">
                     <span class="inline-flex items-center gap-1 text-rose-600 font-semibold">
                         <i class="fa-solid fa-triangle-exclamation"></i> Rusak
                     </span>
                     <span class="font-bold text-rose-600"><?= (int)$bs['total_rusak'] ?></span>
                 </div>
                 <div class="flex items-center justify-between text-xs">
                     <span class="inline-flex items-center gap-1 text-amber-600 font-semibold">
                         <i class="fa-solid fa-list-check"></i> Permintaan Perbaikan Berjalan
                     </span>
                     <span class="font-bold text-amber-600"><?= (int)$bs['total_permintaan_jalan'] ?></span>
                 </div>
                 <p class="text-[11px] text-slate-400 pt-1 border-t border-slate-100">
                     Total Asset: <?= (int)$bs['total_aktif'] ?>
                 </p>
             </a>
             <?php endforeach; ?>
         </div>
         <?php endif; ?>
     </div>

     <!-- SUMMARY CARDS (mengikuti filter / total keseluruhan) -->
     <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
         <div class="stat-card bg-white/90 backdrop-blur rounded-2xl shadow-md border border-white/60 p-5 flex items-center gap-4 overflow-hidden relative">
             <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-500 text-white flex items-center justify-center text-lg shrink-0 shadow-lg shadow-indigo-500/30">
                 <i class="fa-solid fa-car"></i>
             </div>
             <div>
                 <p class="text-sm text-slate-500">Total Kendaraan Aktif<?= $bidang !== '' ? ' (' . h($bidang) . ')' : '' ?></p>
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

     <!-- LIST READY & RUSAK (mengikuti filter bidang) -->
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
                         placeholder="Cari No Asset, jenis kendaraan, bidang, atau tahun...">
                 </div>

                 <p id="countReady" class="text-xs text-slate-500 mb-3 px-1"></p>

                 <div class="overflow-y-auto max-h-[440px] rounded-xl border border-slate-100">
                     <table class="w-full text-sm" id="tableReady">
                         <thead class="sticky top-0 bg-emerald-50 text-emerald-700 uppercase text-xs">
                             <tr>
                                 <th class="text-left font-semibold px-4 py-3">No Asset</th>
                                 <th class="text-left font-semibold px-4 py-3">Jenis Kendaraan</th>
                                 <th class="text-left font-semibold px-4 py-3">Bidang</th>
                                 <th class="text-left font-semibold px-4 py-3">Tahun</th>
                             </tr>
                         </thead>
                         <tbody class="divide-y divide-slate-100">
                             <?php if (empty($list_ready)): ?>
                                 <tr class="no-data-row"><td colspan="4"><i class="fa-solid fa-inbox mb-2 text-2xl block"></i>Tidak ada data kendaraan ready</td></tr>
                             <?php else: foreach ($list_ready as $r): ?>
                                 <tr class="hover:bg-emerald-50/60">
                                     <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap"><?= h($r['nopol']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['jenis_kendaraan']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['bidang']) ?></td>
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
                         placeholder="Cari No Asset, jenis kendaraan, bidang, atau tahun...">
                 </div>

                 <p id="countRusak" class="text-xs text-slate-500 mb-3 px-1"></p>

                 <div class="overflow-y-auto max-h-[440px] rounded-xl border border-slate-100">
                     <table class="w-full text-sm" id="tableRusak">
                         <thead class="sticky top-0 bg-rose-50 text-rose-700 uppercase text-xs">
                             <tr>
                                 <th class="text-left font-semibold px-4 py-3">No Asset</th>
                                 <th class="text-left font-semibold px-4 py-3">Jenis Kendaraan</th>
                                 <th class="text-left font-semibold px-4 py-3">Bidang</th>
                                 <th class="text-left font-semibold px-4 py-3">Tahun</th>
                                 <th class="text-center font-semibold px-4 py-3">Permintaan</th>
                             </tr>
                         </thead>
                         <tbody class="divide-y divide-slate-100">
                             <?php if (empty($list_rusak)): ?>
                                 <tr class="no-data-row"><td colspan="5"><i class="fa-solid fa-check mb-2 text-2xl block"></i>Tidak ada kendaraan yang rusak</td></tr>
                             <?php else: foreach ($list_rusak as $r): ?>
                                 <tr class="hover:bg-rose-50/60">
                                     <td class="px-4 py-3 font-semibold text-slate-800 whitespace-nowrap"><?= h($r['nopol']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['jenis_kendaraan']) ?></td>
                                     <td class="px-4 py-3 text-slate-600"><?= h($r['bidang']) ?></td>
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

        allRows.forEach(row => { row.style.display = 'none'; });

        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        filteredRows.slice(start, end).forEach(row => { row.style.display = ''; });

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

// batas 50 baris per halaman biar tidak berat saat dibuka
attachTable('searchReady', 'tableReady', 'countReady', 'paginationReady', 50);
attachTable('searchRusak', 'tableRusak', 'countRusak', 'paginationRusak', 50);
</script>
</body>
</html>
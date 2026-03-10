# Desain: Multi-Akun per Tab (Session per Tab)

## Masalah
- Cookie browser **dibagi semua tab**. Login terakhir menimpa cookie `armada_tab_id`, jadi semua tab ikut session user terakhir.
- Yang diinginkan: **satu tab = satu session**. Tab 1 (faiq1) dan Tab 2 (faiq3) tetap masing-masing punya user sendiri meski di browser yang sama.

## Ide Utama
- **Tab ID** tidak disimpan di cookie (supaya tidak dipakai bersama tab lain).
- Tab ID **diambil dari URL** (`?armada_tab=xxx`) dan di **sessionStorage** (per tab) di sisi client.
- Setiap request (link, form, redirect) **selalu membawa** `armada_tab` di URL.
- Session PHP diberi nama unik per tab: `ARMADA_{ROLE}_{tab_id}`.

## Alur

### 1. Pertama kali buka (tanpa `armada_tab`)
- User buka `index.php` atau `halaman_admin/dasboard.php` tanpa query.
- PHP (di `config.php`) cek: tidak ada `$_GET['armada_tab']` → kirim HTML kecil + script.
- Script: ambil atau buat ID dari `sessionStorage`, lalu **redirect** ke URL yang sama + `?armada_tab=xxx`.
- Request berikutnya sudah punya `armada_tab` di URL.

### 2. Login
- Form action: `index.php?armada_tab=xxx` (dari URL halaman login).
- Setelah login sukses: session dipakai nama `ARMADA_{ROLE}_{tab_id}`.
- **Tidak set cookie** `armada_tab_id` lagi.
- Redirect ke dashboard dengan URL yang sama tab: `halaman_admin/dasboard.php?armada_tab=xxx`.

### 3. Navigasi (link & form)
- Semua link dan form **harus** mengirim `armada_tab` di URL.
- **Cara 1 (backend):** Setiap output link/form pakai helper `url_with_tab('path')`.
- **Cara 2 (frontend):** Satu script JS (`armada-tab.js`) baca `armada_tab` dari URL/sessionStorage, lalu **menambah** param itu ke semua `<a href>` dan `<form action>` yang masih belum ada.
- Implementasi memakai **kombinasi**: redirect & form action di PHP pakai `url_with_tab`; link di sisi client dipatch oleh JS agar tidak harus ubah tiap file PHP.

### 4. Logout
- Link logout: `../logout.php?armada_tab=xxx`.
- `logout.php` baca `armada_tab` dari GET, hancurkan session `ARMADA_{ROLE}_{tab_id}` untuk tab itu saja.
- Redirect ke `index.php?armada_tab=xxx` (tab yang sama, siap login lagi).

### 5. Tab baru / buka link di tab baru
- Jika user “buka di tab baru” dengan link yang **sudah** berisi `armada_tab=xxx`, tab baru akan pakai **session yang sama** (satu user, dua tab).
- Jika user buka URL **tanpa** `armada_tab` (mis. bookmark), script redirect akan buat **tab_id baru** di sessionStorage tab itu → tab baru dapat session kosong (harus login lagi). Perilaku ini wajar: satu tab = satu konteks login.

## Perubahan Backend (PHP)

| File | Perubahan |
|------|-----------|
| `inc/config.php` | `get_armada_tab_id()`, redirect jika tidak ada tab, `url_with_tab()`, `initSession()` pakai tab_id, `requireAuth` redirect pakai `url_with_tab` |
| `inc/auth.php` | Ambil tab dari `get_armada_tab_id()` (bukan cookie), redirect pakai `url_with_tab` |
| `index.php` | Hapus set cookie tab; form action & redirect pakai `url_with_tab` / `get_armada_tab_id()` |
| `logout.php` | Baca tab dari GET; redirect ke login pakai `url_with_tab('index.php')` |
| Halaman lain | Setiap `header('Location: ...')` pakai `url_with_tab(...)` agar tab tidak hilang |

## Perubahan Frontend (JS)

| File | Perubahan |
|------|-----------|
| `js/armada-tab.js` | Baru. Sync `armada_tab` URL ↔ sessionStorage; tambah `armada_tab` ke semua link & form internal |
| Layout (navbar, dll.) | Sertakan script `armada-tab.js` agar setiap halaman yang punya link/form otomatis membawa tab |
| `index.php` (login) | Sertakan script yang sama (atau inline) agar form login punya tab di action |

## Helper PHP

- **`get_armada_tab_id()`**  
  Mengembalikan `$_GET['armada_tab'] ?? $_POST['armada_tab'] ?? null`.

- **`url_with_tab($path)`**  
  Mengembalikan `$path` yang sudah ditambah `?armada_tab=xxx` atau `&armada_tab=xxx` (jika sudah ada query).

- **Session name**  
  `ARMADA_{ROLE}_{tab_id}` (tanpa tab fallback ke `ARMADA_{ROLE}` untuk kompatibilitas).

## Ringkasan Manfaat
- Satu browser bisa punya **beberapa tab dengan user berbeda** (faiq1 di tab 1, faiq3 di tab 2).
- Refresh tab tidak mengubah user di tab itu (karena tab_id tetap di URL).
- Logout hanya menghapus session **tab itu**, tab lain tetap login.

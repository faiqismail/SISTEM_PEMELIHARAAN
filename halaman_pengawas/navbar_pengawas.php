<?php
// navbar.php
// NAVBAR TIDAK BOLEH URUS SESSION / LOGIN / DATABASE

$username = $_SESSION['username'] ?? 'User';
$role     = $_SESSION['role'] ?? 'user';

// Deteksi halaman aktif
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
    /* Mobile Toggle */
    .mobile-toggle {
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1001;
        background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%);
        color: white;
        border: none;
        padding: 12px 15px;
        border-radius: 8px;
        cursor: pointer;
        display: none;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }

    /* ─── SIDEBAR — font-size FIXED 14px agar konsisten di semua halaman ─── */
    /* Tanpa ini, nilai em akan ikut body masing-masing halaman dan jadi beda ukuran */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 260px;
        height: 100vh;
        background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%) !important;
        color: white;
        z-index: 1000;
        transition: transform 0.3s ease;
        overflow-y: auto;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 14px; /* ← FIX: nilai tetap, tidak ikut body halaman */
    }

    .sidebar.hidden {
        transform: translateX(-100%);
    }

    /* Logo Section */
    .sidebar-logo {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 15px 10px;
        background: white !important;
        border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }

    .sidebar-logo img {
        width: 160px;
        max-width: 100%;
        height: auto;
        object-fit: contain;
    }

    /* User Info Section */
    .sidebar-header {
        padding: 20px !important;
        background: rgba(0, 0, 0, 0.2) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .sidebar-header .user-info {
        display: flex !important;
        align-items: center;
        gap: 12px;
    }

    .sidebar-header .avatar {
        width: 50px !important;
        height: 50px !important;
        border-radius: 50%;
        background: linear-gradient(135deg, rgb(199, 184, 10), rgb(255, 252, 90)) !important;
        display: flex !important;
        align-items: center;
        justify-content: center;
        font-size: 20px;   /* ← px bukan em agar tidak dipengaruhi body */
        font-weight: bold;
        color: white;
        flex-shrink: 0;
    }

    .sidebar-header .user-details {
        flex: 1;
        min-width: 0;
    }

    .sidebar-header .user-name {
        font-size: 15px;   /* ← px tetap */
        font-weight: 600;
        margin-bottom: 3px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: white;
    }

    .sidebar-header .user-role {
        font-size: 12px;   /* ← px tetap */
        opacity: 0.85;
        color: #ecf0f1;
    }

    .nav-menu {
        padding: 20px 0 !important;
    }

    .nav-item {
        padding: 15px 20px !important;
        display: flex !important;
        align-items: center;
        gap: 12px;
        color: rgba(255, 255, 255, 0.85) !important;
        text-decoration: none !important;
        transition: all 0.3s ease;
        border-left: 4px solid transparent;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 14px;   /* ← px tetap */
    }

    .nav-item:hover,
    .nav-item.active {
        background: rgba(255, 255, 255, 0.1) !important;
        color: white !important;
        border-left: 4px solid rgb(205, 174, 38) !important;
    }

    .nav-item i {
        width: 20px;
        text-align: center;
        font-size: 15px;   /* ← ikon sedikit lebih besar dari teks */
    }

    /* Scrollbar */
    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.2); }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.3); border-radius: 4px; }
    .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.5); }

    /* Responsive */
    @media (max-width: 768px) {
        .mobile-toggle { display: block; }
        .sidebar { transform: translateX(-100%); }
        .sidebar.active {
            transform: translateX(0);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
        }
    }
</style>

<!-- Mobile Toggle Button -->
<button class="mobile-toggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">

    <!-- Logo Section -->
    <div class="sidebar-logo">
        <img src="../foto/logo.png" alt="Logo Sidebar" class="logo-animated">
    </div>

    <!-- User Info Section -->
    <div class="sidebar-header">
        <div class="user-info">
            <div class="avatar">
                <?php
                $initial = strtoupper(substr($nama_user, 0, 1));
                echo $initial;
                ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?= htmlspecialchars($nama_user) ?></div>
                <div class="user-role">
                    <i class="fas fa-user-tag"></i>
                    <?= ($role_user === 'pengawas') ? 'Pengawas' : ucfirst($role_user); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Menu Navigation -->
    <div class="nav-menu">
        <?php
        $current_page = basename($_SERVER['PHP_SELF']);
        ?>
        <a href="dashboard_pengawas.php" class="nav-item <?= ($current_page == 'dashboard_pengawas.php') ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>

        <a href="pengajuan_perbaikan.php" class="nav-item <?= ($current_page == 'pengajuan_perbaikan.php') ? 'active' : '' ?>">
            <i class="fas fa-tools"></i>
            <span>Pengajuan Perbaikan</span>
        </a>

        <a href="riwayat.php" class="nav-item <?= ($current_page == 'riwayat.php') ? 'active' : '' ?>">
            <i class="fas fa-history"></i>
            <span>Riwayat Perbaikan</span>
        </a>

        <a href="../logout.php" class="nav-item" onclick="return confirm('Yakin ingin keluar?')">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('active');
}

document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.mobile-toggle');
    if (window.innerWidth <= 768) {
        if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove('active');
        }
    }
});

window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        document.getElementById('sidebar').classList.remove('active');
    }
});
</script>
<!-- Multi-tab: bawa armada_tab di semua link & form -->
<script src="../js/armada-tab.js"></script>
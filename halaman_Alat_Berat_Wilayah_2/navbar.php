<?php
// navbar.php
// NAVBAR TIDAK BOLEH URUS SESSION / LOGIN / DATABASE

$username = $_SESSION['username'] ?? 'User';
$role     = $_SESSION['role'] ?? 'user';
$role_label = $role;

if ($role === 'alat_berat_wilayah_2') {
    $role_label = 'ALAT BERAT WILAYAH 2';
}
// Deteksi halaman aktif
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Mobile Toggle Button -->
<button class="mobile-toggle" id="mobileToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Navbar -->
<aside class="sidebar" id="sidebar">
    <!-- Mobile Close Button - HANYA 1 DI POJOK KANAN ATAS -->
    <button class="mobile-close" id="mobileClose">
        <i class="fas fa-times"></i>
    </button>
    
    <div class="sidebar-header" style="
        display:flex;
        justify-content:center;
        align-items:center;
        padding:15px 10px;
        background:white;
    ">
        <img src="../foto/logo.png" alt="Logo Sidebar" class="logo-animated" style="
            width:160px;
            max-width:100%;
            height:auto;
            object-fit:contain;
        ">
    </div>

    <!-- User Profile -->
    <div class="user-profile">
        <div class="flex items-center gap-3">
            <div class="user-avatar">
                <?= strtoupper(substr($username, 0, 1)) ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($username) ?></div>
                <div class="user-role">
                    <i class="fas fa-user-tag mr-1"></i>
                    <?= htmlspecialchars($role_label) ?>
                </div>
            </div>
        </div>
    </div>
    
    <nav class="sidebar-menu">
        <a href="dasboard.php" class="menu-item <?= $current_page == 'dasboard.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="dasboard_prevetif.php" class="menu-item <?= $current_page == 'dasboard_preventif.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard Preventif</span>
        </a>
        
       
        
        <div class="menu-divider"></div>
        
        <div class="menu-label">Data Asset </div>
        

<a href="kendaraan.php" class="menu-item <?= $current_page == 'kendaraan.php' ? 'active' : '' ?>">
    <i class="fas fa-car-side"></i>
    <span>Management Asset</span>
</a>

<a href="kondisi.php" class="menu-item <?= $current_page == 'kondisi.php' ? 'active' : '' ?>">
    <i class="fas fa-clipboard-check"></i>
    <span>Kondisi Asset</span>
</a>

        <!-- Menu Riwayat dengan Submenu -->
        <div class="menu-dropdown">
            <div class="menu-item dropdown-toggle <?= in_array($current_page, ['riwayat_kendaraan.php', 'analisa_riwayat.php', 'riwayat_rekanan.php']) ? 'active' : '' ?>" onclick="toggleDropdown(this)">
                <i class="fas fa-history"></i>
                <span>Riwayat & Laporan</span>
                <i class="fas fa-chevron-down dropdown-icon"></i>
            </div>
            <div class="dropdown-content">
                <a href="riwayat_kendaraan.php" class="submenu-item <?= $current_page == 'riwayat_kendaraan.php' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Riwayat Perbaikan</span>
                </a>
                <a href="analisa_riwayat.php" class="submenu-item <?= $current_page == 'analisa_riwayat.php' ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Analisa Jasa & Sparepart</span>
                </a>
                <a href="riwayat_rekanan.php" class="submenu-item <?= $current_page == 'riwayat_rekanan.php' ? 'active' : '' ?>">
                    <i class="fas fa-handshake"></i>
                    <span>Laporan Biaya Perbaikan</span>
                </a>
            </div>
        </div>
        
        <div class="menu-divider"></div>
        
        <div class="menu-label">Operasional</div>
        
        <a href="list_kendaraan.php" class="menu-item <?= $current_page == 'list_kendaraan.php' || $current_page == 'service_advisor.php' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-list"></i>
            <span>Service Advisor</span>
        </a>
        
        <a href="list_kendaraan_qc.php" class="menu-item <?= $current_page == 'list_kendaraan_qc.php' || $current_page == 'karu_qc_approval.php' ? 'active' : '' ?>">
            <i class="fas fa-check-double"></i>
            <span>QC</span>
        </a>
        <a href="list_kendaraan_karu.php" class="menu-item <?= $current_page == 'list_kendaraan_karu.php' || $current_page == 'karu_approval.php' ? 'active' : '' ?>">
            <i class="fas fa-check-double"></i>
            <span>Karu</span>
        </a>
        <a href="dalam_proses.php" class="menu-item <?= $current_page == 'dalam_proses.php' ? 'active' : '' ?>">
    <i class="fas fa-tools"></i>
    <span>Dalam Proses</span>
</a>

        <div class="menu-divider"></div>
        
        <a href="../logout.php" class="menu-item" onclick="return confirm('Apakah Anda yakin ingin logout?')">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </nav>
</aside>

<style>
    /* Base Styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    .flex {
        display: flex;
    }
    
    .items-center {
        align-items: center;
    }
    
    .gap-2 {
        gap: 8px;
    }
    
    .gap-3 {
        gap: 12px;
    }
    
    .mr-1 {
        margin-right: 4px;
    }
    
    /* Sidebar Styles */
    .sidebar {
        width: 260px;
        background: linear-gradient(180deg, #248a3d 0%, #0d3d1f 100%);
        color: white;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        z-index: 1000;
        transition: transform 0.3s ease;
        overflow: hidden;
    }
    
    .sidebar-header {
        padding: 20px 16px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        flex-shrink: 0;
    }
    
    .sidebar-header h2 {
        font-size: 18px;
        font-weight: bold;
    }
    
    .user-profile {
        padding: 16px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        background: rgba(0,0,0,0.1);
        flex-shrink: 0;
    }
    
    .user-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg,rgb(105, 92, 15),rgb(205, 174, 38));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: bold;
        color: white;
        flex-shrink: 0;
    }
    
    .user-info {
        color: white;
        flex: 1;
        min-width: 0;
    }
    
    .user-name {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .user-role {
        font-size: 11px;
        color: rgba(255,255,255,0.7);
    }
    
    .sidebar-menu {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px 0 20px 0;
        min-height: 0;
    }

    .sidebar-menu::-webkit-scrollbar {
        width: 8px;
    }

    .sidebar-menu::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.2);
        border-radius: 10px;
        margin: 4px;
    }

    .sidebar-menu::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.4);
        border-radius: 10px;
        border: 2px solid transparent;
        background-clip: padding-box;
    }

    .sidebar-menu::-webkit-scrollbar-thumb:hover {
        background: rgba(255,255,255,0.6);
        background-clip: padding-box;
    }

    /* Firefox */
    .sidebar-menu {
        scrollbar-width: thin;
        scrollbar-color: rgba(255,255,255,0.4) rgba(0,0,0,0.2);
    }
    
    .menu-item {
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: rgba(255,255,255,0.9);
        text-decoration: none;
        transition: all 0.2s;
        cursor: pointer;
        font-size: 14px;
        border-left: 3px solid transparent;
        position: relative;
    }
    
    .menu-item:hover {
        background: rgba(255,255,255,0.1);
        color: white;
    }
    
    .menu-item.active {
        background: rgba(255,255,255,0.15);
        color: white;
        border-left: 3px solid #fbbf24;
    }
    
    .menu-item i {
        width: 20px;
        text-align: center;
        flex-shrink: 0;
    }
    
    /* Dropdown Styles */
    .menu-dropdown {
        position: relative;
    }
    
    .dropdown-toggle {
        justify-content: space-between;
    }
    
    .dropdown-icon {
        margin-left: auto;
        transition: transform 0.3s ease;
        font-size: 12px;
    }
    
    .dropdown-toggle.open .dropdown-icon {
        transform: rotate(180deg);
    }
    
    .dropdown-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background: rgba(0,0,0,0.2);
    }
    
    .dropdown-content.show {
        max-height: 300px;
    }
    
    .submenu-item {
        padding: 10px 20px 10px 52px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        transition: all 0.2s;
        cursor: pointer;
        font-size: 13px;
        border-left: 3px solid transparent;
    }
    
    .submenu-item:hover {
        background: rgba(255,255,255,0.1);
        color: white;
    }
    
    .submenu-item.active {
        background: rgba(255,255,255,0.15);
        color: white;
        border-left: 3px solid #fbbf24;
    }
    
    .submenu-item i {
        width: 16px;
        text-align: center;
        flex-shrink: 0;
        font-size: 12px;
    }
    
    .menu-divider {
        height: 1px;
        background: rgba(255,255,255,0.1);
        margin: 8px 16px;
    }
    
    .menu-label {
        padding: 12px 20px 8px 20px;
        font-size: 10px;
        text-transform: uppercase;
        color: rgba(255,255,255,0.5);
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    /* Mobile Toggle Button - HANYA TAMPIL DI MOBILE/TABLET */
    .mobile-toggle {
        display: none;
        position: fixed;
        top: 16px;
        left: 16px;
        z-index: 999;
        background: rgb(20, 39, 18);
        color: white;
        border: none;
        padding: 12px 16px;
        border-radius: 8px;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
    }
    
    .mobile-toggle:hover {
        background: #1e3a8a;
        transform: scale(1.05);
    }

    .mobile-toggle:active {
        transform: scale(0.95);
    }
    
    .mobile-toggle i {
        font-size: 20px;
    }
    
    /* Mobile Close Button - HANYA 1 DI POJOK KANAN ATAS */
    .mobile-close {
        display: none;
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(227, 177, 26, 0.9);
        color: white;
        border: none;
        padding: 10px;
        border-radius: 50%;
        cursor: pointer;
        transition: all 0.3s ease;
        z-index: 1001;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        width: 40px;
        height: 40px;
        align-items: center;
        justify-content: center;
    }
    
    .mobile-close:hover {
        background: rgba(220, 38, 38, 1);
        transform: rotate(90deg) scale(1.1);
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.5);
    }

    .mobile-close:active {
        transform: rotate(90deg) scale(0.95);
    }

    .mobile-close i {
        font-size: 18px;
    }
    
    /* Sidebar Overlay */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 999;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .sidebar-overlay.show {
        display: block;
        opacity: 1;
    }

    /* ========================================
       DESKTOP (≥1024px) - SIDEBAR TETAP TAMPIL
    ======================================== */
    @media (min-width: 1024px) {
        /* Sembunyikan semua tombol mobile */
        .mobile-toggle,
        .mobile-close,
        .sidebar-overlay {
            display: none !important;
        }
        
        /* Sidebar tetap tampil */
        .sidebar {
            transform: translateX(0) !important;
        }
        
        /* Body padding untuk ruang sidebar */
        body {
            padding-left: 260px;
        }
    }

    /* ========================================
       TABLET & MOBILE (<1024px)
    ======================================== */
    @media (max-width: 1023px) {
        /* Sidebar tersembunyi secara default */
        .sidebar {
            transform: translateX(-100%);
        }
        
        /* Sidebar tampil saat class 'show' */
        .sidebar.show {
            transform: translateX(0);
        }
        
        /* Tampilkan tombol toggle */
        .mobile-toggle {
            display: block !important;
        }
        
        /* Tampilkan tombol close */
        .mobile-close {
            display: flex !important;
        }
        
        /* Body tanpa padding */
        body {
            padding-left: 0 !important;
        }

        /* Sidebar menu height adjustment */
        .sidebar-menu {
            max-height: calc(100vh - 180px);
            padding-bottom: 30px;
        }
    }

    /* ========================================
       MOBILE (max-width: 767px)
    ======================================== */
    @media (max-width: 767px) {
        .sidebar {
            width: 280px;
            max-width: 85vw;
        }
        
        .sidebar-header h2 {
            font-size: 16px;
        }
        
        .user-profile {
            padding: 12px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            font-size: 18px;
        }
        
        .user-name {
            font-size: 13px;
        }
        
        .user-role {
            font-size: 10px;
        }
        
        .menu-item {
            padding: 14px 16px;
            font-size: 13px;
        }
        
        .submenu-item {
            padding: 10px 16px 10px 48px;
            font-size: 12px;
        }
        
        .menu-label {
            padding: 12px 16px 8px 16px;
            font-size: 9px;
        }
        
        .mobile-toggle {
            top: 12px;
            left: 12px;
            padding: 10px 14px;
        }
        
        .mobile-toggle i {
            font-size: 18px;
        }

        .mobile-close {
            top: 8px;
            right: 8px;
            width: 36px;
            height: 36px;
        }

        .mobile-close i {
            font-size: 16px;
        }

        .sidebar-menu {
            max-height: calc(100vh - 160px);
            padding-bottom: 40px;
        }

        .sidebar-menu::-webkit-scrollbar {
            width: 10px;
        }

        .sidebar-menu::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.5);
        }
    }

    /* ========================================
       SMALL MOBILE (max-width: 479px)
    ======================================== */
    @media (max-width: 479px) {
        .sidebar {
            width: 100%;
            max-width: 100vw;
        }
        
        .menu-item span {
            font-size: 12px;
        }
        
        .submenu-item span {
            font-size: 11px;
        }

        .mobile-close {
            top: 6px;
            right: 6px;
        }

        .sidebar-menu {
            max-height: calc(100vh - 140px);
        }
    }
</style>

<script>
    // Function untuk toggle dropdown
    function toggleDropdown(element) {
        const dropdown = element.parentElement;
        const content = dropdown.querySelector('.dropdown-content');
        const toggle = dropdown.querySelector('.dropdown-toggle');
        
        toggle.classList.toggle('open');
        content.classList.toggle('show');
    }
    
    // JavaScript untuk toggle sidebar
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        const mobileClose = document.getElementById('mobileClose');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        // Auto-open dropdown jika ada submenu yang aktif
        const activeSubmenu = document.querySelector('.submenu-item.active');
        if (activeSubmenu) {
            const parentDropdown = activeSubmenu.closest('.menu-dropdown');
            if (parentDropdown) {
                const toggle = parentDropdown.querySelector('.dropdown-toggle');
                const content = parentDropdown.querySelector('.dropdown-content');
                toggle.classList.add('open');
                content.classList.add('show');
            }
        }
        
        // Open sidebar
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                sidebar.classList.add('show');
                sidebarOverlay.classList.add('show');
                document.body.style.overflow = 'hidden';
            });
        }
        
        // Close sidebar function
        function closeSidebar() {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Close button click
        if (mobileClose) {
            mobileClose.addEventListener('click', closeSidebar);
        }
        
        // Overlay click
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }
        
        // Close sidebar saat submenu item diklik (di mobile/tablet)
        const submenuItems = document.querySelectorAll('.submenu-item');
        submenuItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth < 1024) {
                    closeSidebar();
                }
            });
        });
        
        // Close sidebar saat menu item biasa diklik (di mobile/tablet)
        const menuItems = document.querySelectorAll('.menu-item:not(.dropdown-toggle)');
        menuItems.forEach(item => {
            item.addEventListener('click', function() {
                if (window.innerWidth < 1024) {
                    closeSidebar();
                }
            });
        });
        
        // Close sidebar saat resize ke desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                closeSidebar();
            }
        });

        // Keyboard shortcut - ESC untuk close sidebar
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                closeSidebar();
            }
        });
    });
</script>
<!-- Multi-tab: bawa armada_tab di semua link & form -->
<script src="../js/armada-tab.js"></script>
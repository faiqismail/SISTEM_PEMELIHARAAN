<?php
include "inc/config.php";


$error_message = '';
$session_error = '';

if (isset($_GET['expired']) && $_GET['expired'] == '1') {
    $session_error = 'session_expired';
}
if (isset($_GET['unauthorized']) && $_GET['unauthorized'] == '1') {
    $session_error = 'unauthorized';
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Query mengambil data user beserta status
    $sql = "SELECT id_user, username, password, role, status FROM users WHERE username = ? LIMIT 1";
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // CEK STATUS USER - HANYA AKTIF YANG BISA LOGIN
        if (isset($user['status']) && $user['status'] !== 'Aktif') {
            $error_message = "account_inactive";
        } else if (password_verify($password, $user['password'])) {

            // Multi-tab: pakai tab_id dari URL (tab ini), jangan generate baru / cookie
            $tab_id = get_armada_tab_id();

            session_write_close();

            $sessionName = 'ARMADA_' . strtoupper($user['role']) . '_' . $tab_id;
            session_name($sessionName);
            session_start();
            session_regenerate_id(true);

            $db_token = createDbSession($user['id_user'], $user['role']);

            $_SESSION['id_login'] = $user['id_user'];
            $_SESSION['id_user']  = $user['id_user'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $_SESSION['db_token'] = $db_token;
            $_SESSION['tab_id']   = $tab_id;

            // Redirect dengan URL yang bawa armada_tab agar tab ini tetap pakai session ini
            $redir = [
                'admin' => 'halaman_admin/dasboard.php',
                'pengawas' => 'halaman_pengawas/pengajuan_perbaikan.php',
                'angkutan_dalam' => 'halaman_angkutan_dalam/dasboard.php',
                'angkutan_luar' => 'halaman_angkutan_luar/dasboard.php',
                'alat_berat_wilayah_1' => 'halaman_alat_berat_wilayah_1/dasboard.php',
                'alat_berat_wilayah_2' => 'halaman_alat_berat_wilayah_2/dasboard.php',
                'alat_berat_wilayah_3' => 'halaman_alat_berat_wilayah_3/dasboard.php',
                'pergudangan' => 'halaman_Pergudangan/dasboard.php',
            ];
            $path = $redir[$user['role']] ?? null;
            if ($path) {
                header('Location: ' . url_with_tab($path));
            } else {
                die("Role tidak valid");
            }
            exit;

        } else {
            $error_message = "Password salah";
        }
    } else {
        $error_message = "Username tidak ditemukan";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="foto/favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemeliharaan Armada - Login</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e5a2e 0%, #2d7a3f 50%, #8b7b3a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated Background Pattern */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(255, 215, 0, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(34, 139, 34, 0.1) 0%, transparent 50%);
            animation: backgroundMove 15s ease-in-out infinite;
            pointer-events: none;
        }

        @keyframes backgroundMove {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }

        .login-wrapper {
            width: 100%;
            max-width: 1200px;
            display: flex;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.6s ease-out;
            position: relative;
            z-index: 1;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Left Side - Branding */
        .login-branding {
            flex: 1;
            background: linear-gradient(135deg, #1e5a2e 0%, #2d7a3f 50%, #3a7a3f 100%);
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-branding::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 215, 0, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .branding-content {
            position: relative;
            z-index: 1;
        }

        .branding-icon {
            font-size: 80px;
            margin-bottom: 30px;
            animation: float 3s ease-in-out infinite;
            color: #ffd700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .branding-content h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .branding-content p {
            font-size: 16px;
            opacity: 0.95;
            line-height: 1.6;
        }

        .feature-list {
            margin-top: 40px;
            text-align: left;
        }

        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .feature-item i {
            margin-right: 12px;
            font-size: 18px;
            color: #ffd700;
        }

        /* Right Side - Login Form */
        .login-form-section {
            flex: 1;
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            width: 100%;
            max-width: 350px;
            height: auto;
            object-fit: contain;
            margin-bottom: 20px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
        }

        .login-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e5a2e;
            margin-bottom: 10px;
            text-align: center;
        }

        .login-subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 30px;
            text-align: center;
        }

        /* Alert Styles */
        .error-alert {
            padding: 15px 18px;
            background: #fee;
            border-left: 4px solid #dc3545;
            border-radius: 8px;
            color: #dc3545;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            animation: shake 0.4s;
        }

        .warning-alert {
            padding: 15px 18px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 8px;
            color: #856404;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            animation: slideDown 0.5s ease-out;
        }

        .info-alert {
            padding: 15px 18px;
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            border-radius: 8px;
            color: #0c5460;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            animation: slideDown 0.5s ease-out;
        }

        /* Alert untuk akun tidak aktif */
        .inactive-alert {
            padding: 15px 18px;
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            border-radius: 8px;
            color: #721c24;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            animation: shake 0.4s;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-alert i,
        .warning-alert i,
        .info-alert i,
        .inactive-alert i {
            margin-right: 12px;
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content strong {
            display: block;
            margin-bottom: 5px;
            font-size: 15px;
        }

        .alert-content p {
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #2d7a3f;
            font-size: 16px;
        }

        .form-control {
            width: 100%;
            padding: 14px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-control:focus {
            outline: none;
            border-color: #2d7a3f;
            background: white;
            box-shadow: 0 0 0 4px rgba(45, 122, 63, 0.1);
        }

        .password-container {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            font-size: 18px;
            transition: color 0.3s;
        }

        .toggle-password:hover {
            color: #2d7a3f;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1e5a2e 0%, #2d7a3f 50%, #8b7b3a 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(30, 90, 46, 0.4);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        /* Shimmer Effect - Garis Bergerak */
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 215, 0, 0.3),
                rgba(255, 215, 0, 0.6),
                rgba(255, 215, 0, 0.3),
                transparent
            );
            animation: shimmer 3s infinite;
            z-index: -1;
        }

        @keyframes shimmer {
            0% {
                left: -100%;
            }
            100% {
                left: 100%;
            }
        }

        /* Border Animation */
        .btn-login::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 10px;
            padding: 2px;
            background: linear-gradient(
                45deg,
                #ffd700,
                #1e5a2e,
                #2d7a3f,
                #8b7b3a,
                #ffd700
            );
            -webkit-mask: 
                linear-gradient(#fff 0 0) content-box, 
                linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            opacity: 0;
            transition: opacity 0.3s ease;
            animation: rotateBorder 4s linear infinite;
        }

        @keyframes rotateBorder {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }

        .btn-login:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 20px rgba(255, 215, 0, 0.5);
        }

        .btn-login:hover::after {
            opacity: 1;
        }

        .btn-login:active {
            transform: translateY(0) scale(0.98);
        }

        /* Ripple Effect saat diklik */
        .btn-login-ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.6);
            transform: scale(0);
            animation: ripple 0.6s ease-out;
            pointer-events: none;
        }

        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Pulse Animation Subtle */
        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 4px 15px rgba(30, 90, 46, 0.4);
            }
            50% {
                box-shadow: 0 4px 20px rgba(255, 215, 0, 0.6);
            }
        }

        .btn-login {
            animation: pulse 2s ease-in-out infinite;
        }

        .btn-login:hover {
            animation: none;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .login-branding {
                display: none;
            }

            .login-wrapper {
                max-width: 500px;
            }
        }

        @media (max-width: 576px) {
            .login-form-section {
                padding: 40px 30px;
            }

            .login-title {
                font-size: 24px;
            }

            .logo {
                max-width: 280px;
            }

            .form-control {
                padding: 12px 45px;
                font-size: 14px;
            }

            .btn-login {
                padding: 12px;
                font-size: 15px;
            }

            .error-alert,
            .warning-alert,
            .info-alert,
            .inactive-alert {
                padding: 12px 15px;
            }

            .alert-content strong {
                font-size: 14px;
            }

            .alert-content p {
                font-size: 12px;
            }
        }

        @media (max-width: 380px) {
            body {
                padding: 15px;
            }

            .login-form-section {
                padding: 30px 20px;
            }

            .login-title {
                font-size: 22px;
            }

            .logo {
                max-width: 240px;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- Left Side - Branding -->
        <div class="login-branding">
            <div class="branding-content">
                <div class="branding-icon">
                    <i class="fas fa-truck"></i>
                </div>
                <h1>Sistem Pemeliharaan Armada</h1>
                <p>Kelola dan monitoring perbaikan armada dengan mudah dan efisien</p>
                
                <div class="feature-list">
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Monitoring Real-time</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Manajemen Jadwal Perawatan</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Laporan Lengkap & Detail</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Multi-User Access Control</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="login-form-section">
            <div class="logo-container">
                <img src="foto/logo.png" alt="Logo Petrokopindo Cipta Selaras" class="logo">
            </div>
            
            <h2 class="login-title">Selamat Datang</h2>
            <p class="login-subtitle">Masuk ke akun Anda untuk melanjutkan</p>

            <?php if ($session_error === 'session_expired'): ?>
                <div class="warning-alert">
                    <i class="fas fa-clock"></i>
                    <div class="alert-content">
                        <strong>Sesi Habis!</strong>
                        <p>Sesi Anda telah berakhir. Silakan login kembali untuk melanjutkan.</p>
                    </div>
                </div>
            <?php elseif ($session_error === 'unauthorized'): ?>
                <div class="error-alert">
                    <i class="fas fa-ban"></i>
                    <div class="alert-content">
                        <strong>Akses Ditolak!</strong>
                        <p>Anda tidak memiliki izin untuk mengakses halaman tersebut.</p>
                    </div>
                </div>
            <?php elseif ($error_message === 'account_inactive'): ?>
                <div class="inactive-alert">
                    <i class="fas fa-user-slash"></i>
                    <div class="alert-content">
                        <strong>Akun Tidak Aktif!</strong>
                        <p>Akun Anda telah dinonaktifkan oleh administrator. Silakan hubungi admin untuk mengaktifkan kembali akun Anda.</p>
                    </div>
                </div>
            <?php elseif ($error_message): ?>
                <div class="error-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="alert-content">
                        <p><?php echo $error_message; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php?armada_tab=<?= urlencode(get_armada_tab_id() ?? '') ?>">
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-wrapper password-container">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required>
                        <span class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye-slash"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" class="btn-login" onclick="createRipple(event)">
                    <i class="fas fa-sign-in-alt"></i> Masuk
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Password
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password i');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            }
        }

        // Ripple Effect saat Button diklik
        function createRipple(event) {
            const button = event.currentTarget;
            const ripple = document.createElement('span');
            const diameter = Math.max(button.clientWidth, button.clientHeight);
            const radius = diameter / 2;

            ripple.style.width = ripple.style.height = `${diameter}px`;
            ripple.style.left = `${event.clientX - button.offsetLeft - radius}px`;
            ripple.style.top = `${event.clientY - button.offsetTop - radius}px`;
            ripple.classList.add('btn-login-ripple');

            const existingRipple = button.getElementsByClassName('btn-login-ripple')[0];
            if (existingRipple) {
                existingRipple.remove();
            }

            button.appendChild(ripple);
        }

        // Auto-hide alert after 10 seconds
        window.onload = function() {
            const alerts = document.querySelectorAll('.warning-alert, .error-alert, .info-alert, .inactive-alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 10000); // 10 seconds
            });
        };
    </script>
    <!-- Multi-tab: bawa armada_tab di link/form (login form action sudah di-set dari PHP) -->
    <script src="js/armada-tab.js"></script>
</body>
</html>
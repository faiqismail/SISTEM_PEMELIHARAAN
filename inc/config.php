<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'armada';

$connection = mysqli_connect($host, $user, $pass, $db);
if (!$connection) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

date_default_timezone_set('Asia/Jakarta');

// ========== MULTI-TAB: Tab ID dari URL (bukan cookie) ==========
if (!function_exists('get_armada_tab_id')) {
    function get_armada_tab_id() {
        $t = $_GET['armada_tab'] ?? $_POST['armada_tab'] ?? null;
        return ($t !== null && $t !== '') ? $t : null;
    }
}

if (!function_exists('url_with_tab')) {
    function url_with_tab($path) {
        $tab = get_armada_tab_id();
        if ($tab === null) return $path;
        $sep = (strpos($path, '?') !== false) ? '&' : '?';
        return $path . $sep . 'armada_tab=' . urlencode($tab);
    }
}

// Jika request tanpa armada_tab, tampilkan loading screen animasi truk
$tid = get_armada_tab_id();
if ($tid === null || $tid === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Memuat Sistem Armada...</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    background: #d4edda;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Nunito', sans-serif;
    overflow: hidden;
    position: relative;
  }

  /* Animated radial background */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
      radial-gradient(ellipse at 20% 50%, rgba(72,199,120,0.25) 0%, transparent 60%),
      radial-gradient(ellipse at 80% 20%, rgba(40,167,69,0.2)  0%, transparent 50%),
      radial-gradient(ellipse at 60% 80%, rgba(144,238,144,0.3) 0%, transparent 55%);
    animation: bgPulse 4s ease-in-out infinite alternate;
    pointer-events: none;
  }

  @keyframes bgPulse {
    from { opacity: 0.7; }
    to   { opacity: 1;   }
  }

  /* Floating bubbles */
  .bubbles {
    position: fixed;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
  }
  .bubble {
    position: absolute;
    bottom: -60px;
    border-radius: 50%;
    background: rgba(40,167,69,0.12);
    animation: floatUp linear infinite;
  }
  .bubble:nth-child(1)  { width:22px; height:22px; left:5%;   animation-duration:7s;   animation-delay:0s;   }
  .bubble:nth-child(2)  { width:12px; height:12px; left:15%;  animation-duration:9s;   animation-delay:1s;   }
  .bubble:nth-child(3)  { width:28px; height:28px; left:25%;  animation-duration:6s;   animation-delay:2s;   }
  .bubble:nth-child(4)  { width:14px; height:14px; left:38%;  animation-duration:8s;   animation-delay:0.5s; }
  .bubble:nth-child(5)  { width:20px; height:20px; left:52%;  animation-duration:10s;  animation-delay:1.5s; }
  .bubble:nth-child(6)  { width:9px;  height:9px;  left:65%;  animation-duration:7.5s; animation-delay:3s;   }
  .bubble:nth-child(7)  { width:18px; height:18px; left:78%;  animation-duration:9s;   animation-delay:0.8s; }
  .bubble:nth-child(8)  { width:13px; height:13px; left:90%;  animation-duration:6.5s; animation-delay:2.5s; }

  @keyframes floatUp {
    0%   { transform: translateY(0)      scale(0.5); opacity: 0;   }
    10%  { opacity: 0.8; }
    90%  { opacity: 0.8; }
    100% { transform: translateY(-110vh) scale(1);   opacity: 0;   }
  }

  /* Glassmorphism card */
  .card {
    background: rgba(255,255,255,0.6);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1.5px solid rgba(255,255,255,0.85);
    border-radius: 30px;
    padding: 44px 52px 42px;
    text-align: center;
    box-shadow:
      0 24px 64px rgba(40,167,69,0.18),
      0 4px 20px rgba(0,0,0,0.06);
    max-width: 460px;
    width: 92vw;
    position: relative;
    z-index: 10;
    animation: cardIn 0.7s cubic-bezier(0.34,1.56,0.64,1) both;
  }

  @keyframes cardIn {
    from { opacity:0; transform: scale(0.82) translateY(36px); }
    to   { opacity:1; transform: scale(1)    translateY(0);    }
  }

  /* ── SCENE: Road + Truck ── */
  .scene {
    position: relative;
    width: 340px;
    height: 96px;
    margin: 0 auto 6px;
    overflow: hidden;
  }

  /* Trees decoration */
  .tree {
    position: absolute;
    bottom: 20px;
    animation: treeScroll 1.4s linear infinite;
  }
  .tree:nth-child(1) { left: -20px;  animation-delay: 0s; }
  .tree:nth-child(2) { left: 160px;  animation-delay: -0.7s; }

  @keyframes treeScroll {
    from { transform: translateX(0);    opacity: 1; }
    to   { transform: translateX(-380px); opacity: 1; }
  }

  /* Road */
  .road {
    position: absolute;
    bottom: 0;
    left: -10px; right: -10px;
    height: 24px;
    background: #37474f;
    border-radius: 4px;
  }
  .road::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: #546e7a;
    border-radius: 4px 4px 0 0;
  }

  /* Dashed lane lines */
  .lane-line {
    position: absolute;
    bottom: 8px;
    height: 5px;
    width: 38px;
    background: #f9a825;
    border-radius: 3px;
    animation: laneMove 0.55s linear infinite;
  }
  .lane-line:nth-child(1) { left: 0px;  }
  .lane-line:nth-child(2) { left: 88px; animation-delay: -0.18s; }
  .lane-line:nth-child(3) { left: 176px; animation-delay: -0.36s; }
  .lane-line:nth-child(4) { left: 264px; animation-delay: -0.1s;  }

  @keyframes laneMove {
    from { transform: translateX(0);   }
    to   { transform: translateX(-88px); }
  }

  /* Truck wrapper — bounces on road */
  .truck-wrap {
    position: absolute;
    bottom: 22px;
    left: 50px;
    animation: truckBounce 0.32s ease-in-out infinite alternate;
  }

  @keyframes truckBounce {
    from { transform: translateY(0);   }
    to   { transform: translateY(-4px); }
  }

  /* Exhaust puffs from stack */
  .puff {
    position: absolute;
    border-radius: 50%;
    background: rgba(140,140,140,0.55);
    animation: puffAnim 0.85s ease-out infinite;
  }
  .puff:nth-child(1) { width:11px; height:11px; top:10px; left:93px; animation-delay:0s;    }
  .puff:nth-child(2) { width:8px;  height:8px;  top:4px;  left:87px; animation-delay:0.28s; }
  .puff:nth-child(3) { width:6px;  height:6px;  top:-1px; left:82px; animation-delay:0.56s; }

  @keyframes puffAnim {
    0%   { opacity:0.8; transform: translateY(0)    scale(1);   }
    100% { opacity:0;   transform: translateY(-24px) scale(2.2); }
  }

  /* ── TRUCK SVG ── */
  .truck-svg { width:170px; height:64px; }

  /* Brand */
  .brand {
    font-family: 'Bebas Neue', sans-serif;
    font-size: 2.5rem;
    letter-spacing: 4px;
    color: #1b5e20;
    line-height: 1;
    margin-top: 18px;
    margin-bottom: 3px;
  }

  .subtitle {
    font-size: 0.78rem;
    color: #4caf50;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 26px;
  }

  /* Progress bar */
  .progress-wrap {
    background: rgba(40,167,69,0.15);
    border-radius: 100px;
    height: 10px;
    overflow: hidden;
    margin-bottom: 13px;
  }

  .progress-bar {
    height: 100%;
    border-radius: 100px;
    background: linear-gradient(90deg, #1b5e20, #43a047, #66bb6a, #43a047, #1b5e20);
    background-size: 300% 100%;
    animation:
      progressFill 2s cubic-bezier(0.4,0,0.2,1) forwards,
      shimmer 1.2s linear infinite;
    width: 0%;
  }

  @keyframes progressFill {
    0%   { width: 0%;  }
    30%  { width: 45%; }
    60%  { width: 70%; }
    85%  { width: 88%; }
    100% { width: 96%; }
  }

  @keyframes shimmer {
    0%   { background-position: 300% 0;  }
    100% { background-position: -300% 0; }
  }

  .status-text {
    font-size: 0.80rem;
    color: #388e3c;
    font-weight: 600;
    letter-spacing: 0.6px;
    animation: blink 1.5s ease-in-out infinite;
  }

  @keyframes blink {
    0%,100% { opacity:1;   }
    50%      { opacity:0.4; }
  }

  /* Dot loader */
  .dot-loader {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 7px;
    margin-top: 18px;
  }

  .dot-loader span {
    width: 9px; height: 9px;
    border-radius: 50%;
    background: #43a047;
    display: inline-block;
    animation: dotJump 1s ease-in-out infinite;
  }
  .dot-loader span:nth-child(1) { animation-delay: 0s;    }
  .dot-loader span:nth-child(2) { animation-delay: 0.15s; background:#66bb6a; }
  .dot-loader span:nth-child(3) { animation-delay: 0.30s; }

  @keyframes dotJump {
    0%,100% { transform: translateY(0);   opacity:0.5; }
    50%      { transform: translateY(-9px); opacity:1; }
  }

  /* ── RESPONSIVE: Tablet ── */
  @media (max-width: 520px) {
    .card {
      padding: 32px 24px 32px;
      border-radius: 22px;
      width: 94vw;
    }

    .scene {
      width: 100%;
      max-width: 300px;
      height: 82px;
    }

    .truck-wrap {
      left: 20px;
      bottom: 20px;
    }

    .truck-svg {
      width: 140px;
      height: 52px;
    }

    .puff:nth-child(1) { left: 76px; top: 8px;  width:9px;  height:9px;  }
    .puff:nth-child(2) { left: 70px; top: 3px;  width:7px;  height:7px;  }
    .puff:nth-child(3) { left: 65px; top: -1px; width:5px;  height:5px;  }

    .brand {
      font-size: 2rem;
      letter-spacing: 3px;
      margin-top: 14px;
    }

    .subtitle {
      font-size: 0.68rem;
      letter-spacing: 1.5px;
      margin-bottom: 20px;
    }

    .progress-wrap {
      height: 9px;
      margin-bottom: 11px;
    }

    .status-text {
      font-size: 0.75rem;
    }

    .dot-loader {
      margin-top: 14px;
      gap: 6px;
    }

    .dot-loader span {
      width: 8px; height: 8px;
    }

    /* Fewer / smaller bubbles on mobile */
    .bubble:nth-child(3),
    .bubble:nth-child(5),
    .bubble:nth-child(7) {
      display: none;
    }
  }

  /* ── RESPONSIVE: Small phones (≤360px) ── */
  @media (max-width: 360px) {
    .card {
      padding: 26px 16px 26px;
      border-radius: 18px;
    }

    .scene {
      max-width: 260px;
      height: 72px;
    }

    .truck-wrap {
      left: 10px;
      bottom: 18px;
    }

    .truck-svg {
      width: 120px;
      height: 46px;
    }

    .puff:nth-child(1) { left: 65px; top: 7px;  width:8px; height:8px; }
    .puff:nth-child(2) { left: 59px; top: 2px;  width:6px; height:6px; }
    .puff:nth-child(3) { left: 54px; top: -1px; width:4px; height:4px; }

    .brand {
      font-size: 1.7rem;
      letter-spacing: 2px;
      margin-top: 12px;
    }

    .subtitle {
      font-size: 0.62rem;
      letter-spacing: 1px;
      margin-bottom: 16px;
    }

    .lane-line:nth-child(4) { display: none; }
  }

  /* ── RESPONSIVE: Landscape mobile ── */
  @media (max-height: 500px) and (orientation: landscape) {
    body { align-items: flex-start; padding-top: 12px; }

    .card {
      padding: 18px 32px 18px;
      border-radius: 18px;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: center;
      gap: 0 24px;
      max-width: 560px;
    }

    .scene {
      width: 220px;
      height: 72px;
      flex-shrink: 0;
      margin-bottom: 0;
    }

    .truck-wrap { left: 10px; bottom: 18px; }
    .truck-svg  { width: 130px; height: 50px; }

    .puff:nth-child(1) { left: 74px; }
    .puff:nth-child(2) { left: 68px; }
    .puff:nth-child(3) { left: 63px; }

    .text-block {
      flex: 1;
      min-width: 160px;
      text-align: left;
    }

    .brand        { font-size: 1.8rem; margin-top: 0; }
    .subtitle     { font-size: 0.65rem; margin-bottom: 10px; }
    .progress-wrap { margin-bottom: 8px; }
    .status-text  { font-size: 0.72rem; }
    .dot-loader   { justify-content: flex-start; margin-top: 10px; }
  }
</style>
</head>
<body>

<!-- Floating bubbles -->
<div class="bubbles">
  <div class="bubble"></div><div class="bubble"></div>
  <div class="bubble"></div><div class="bubble"></div>
  <div class="bubble"></div><div class="bubble"></div>
  <div class="bubble"></div><div class="bubble"></div>
</div>

<div class="card">

  <!-- SCENE -->
  <div class="scene">
    <!-- Trees -->
    <svg class="tree" style="left:-20px" width="18" height="30" viewBox="0 0 18 30">
      <rect x="7" y="20" width="4" height="10" rx="1" fill="#795548"/>
      <polygon points="9,2 18,20 0,20" fill="#388e3c"/>
      <polygon points="9,8 16,22 2,22" fill="#43a047"/>
    </svg>
    <svg class="tree" style="left:200px" width="14" height="24" viewBox="0 0 14 24">
      <rect x="5" y="16" width="4" height="8" rx="1" fill="#795548"/>
      <polygon points="7,1 14,16 0,16" fill="#2e7d32"/>
      <polygon points="7,6 13,18 1,18" fill="#388e3c"/>
    </svg>

    <!-- Truck wrapper + puffs -->
    <div class="truck-wrap">
      <div class="puff"></div>
      <div class="puff"></div>
      <div class="puff"></div>

      <svg class="truck-svg" viewBox="0 0 170 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- === TRAILER === -->
        <!-- Body shadow -->
        <rect x="2" y="16" width="100" height="34" rx="5" fill="#1a5c22" opacity="0.4"/>
        <!-- Body -->
        <rect x="1" y="13" width="100" height="34" rx="5" fill="#2e7d32"/>
        <!-- Body highlight top -->
        <rect x="1" y="13" width="100" height="6" rx="5" fill="#43a047"/>
        <!-- Vertical ribs -->
        <rect x="10" y="17" width="3" height="26" rx="1.5" fill="rgba(255,255,255,0.18)"/>
        <rect x="22" y="17" width="3" height="26" rx="1.5" fill="rgba(255,255,255,0.18)"/>
        <rect x="34" y="17" width="3" height="26" rx="1.5" fill="rgba(255,255,255,0.18)"/>
        <rect x="46" y="17" width="3" height="26" rx="1.5" fill="rgba(255,255,255,0.18)"/>
        <!-- Logo badge -->
        <rect x="57" y="22" width="38" height="18" rx="3" fill="rgba(0,0,0,0.25)"/>
        <text x="76" y="35" text-anchor="middle" font-family="'Bebas Neue',Arial Black,sans-serif" font-size="10" font-weight="900" fill="white" letter-spacing="1.5">ARMADA</text>
        <!-- Trailer-cab connector -->
        <rect x="99" y="24" width="8" height="16" rx="2" fill="#1b5e20"/>

        <!-- === CAB === -->
        <!-- Cab body -->
        <rect x="104" y="20" width="56" height="27" rx="6" fill="#1b5e20"/>
        <!-- Cab roof curve -->
        <rect x="106" y="18" width="46" height="8" rx="4" fill="#2e7d32"/>
        <!-- Windshield -->
        <rect x="120" y="24" width="28" height="14" rx="3" fill="#b3e5fc" opacity="0.9"/>
        <!-- Windshield divider -->
        <line x1="134" y1="24" x2="134" y2="38" stroke="rgba(255,255,255,0.5)" stroke-width="1.5"/>
        <!-- Windshield glare -->
        <rect x="122" y="26" width="8" height="4" rx="2" fill="rgba(255,255,255,0.5)"/>
        <!-- Side door panel -->
        <rect x="104" y="26" width="15" height="18" rx="2" fill="#155724" opacity="0.5"/>
        <!-- Door handle -->
        <rect x="107" y="33" width="6" height="2" rx="1" fill="#a5d6a7"/>
        <!-- Grill front -->
        <rect x="156" y="26" width="8" height="21" rx="3" fill="#0d3b17"/>
        <rect x="157" y="29" width="6" height="2" rx="1" fill="#1b5e20"/>
        <rect x="157" y="33" width="6" height="2" rx="1" fill="#1b5e20"/>
        <rect x="157" y="37" width="6" height="2" rx="1" fill="#1b5e20"/>
        <!-- Headlight -->
        <rect x="157" y="41" width="6" height="5" rx="1.5" fill="#fff9c4"/>
        <rect x="157" y="41" width="6" height="5" rx="1.5" fill="#ffee58" opacity="0.6"/>
        <!-- Exhaust stack -->
        <rect x="147" y="8"  width="7" height="14" rx="2.5" fill="#546e7a"/>
        <rect x="148" y="6"  width="5" height="4"  rx="1.5" fill="#455a64"/>
        <!-- Side mirror -->
        <rect x="158" y="24" width="6" height="4" rx="1" fill="#2e7d32"/>

        <!-- === WHEELS === -->
        <!-- Trailer wheels (dual) -->
        <circle cx="22"  cy="51" r="10" fill="#212121"/>
        <circle cx="22"  cy="51" r="6"  fill="#37474f"/>
        <circle cx="22"  cy="51" r="2.5" fill="#78909c"/>
        <circle cx="48"  cy="51" r="10" fill="#212121"/>
        <circle cx="48"  cy="51" r="6"  fill="#37474f"/>
        <circle cx="48"  cy="51" r="2.5" fill="#78909c"/>
        <circle cx="74"  cy="51" r="10" fill="#212121"/>
        <circle cx="74"  cy="51" r="6"  fill="#37474f"/>
        <circle cx="74"  cy="51" r="2.5" fill="#78909c"/>
        <!-- Cab wheels -->
        <circle cx="122" cy="51" r="10" fill="#212121"/>
        <circle cx="122" cy="51" r="6"  fill="#37474f"/>
        <circle cx="122" cy="51" r="2.5" fill="#78909c"/>
        <circle cx="150" cy="51" r="10" fill="#212121"/>
        <circle cx="150" cy="51" r="6"  fill="#37474f"/>
        <circle cx="150" cy="51" r="2.5" fill="#78909c"/>
      </svg>
    </div>

    <!-- Road -->
    <div class="road">
      <div class="lane-line"></div>
      <div class="lane-line"></div>
      <div class="lane-line"></div>
      <div class="lane-line"></div>
    </div>
  </div>

  <div class="text-block">
    <div class="brand">SISTEM ARMADA</div>
    <div class="subtitle">Manajemen Kendaraan &amp; Logistik</div>

    <div class="progress-wrap">
      <div class="progress-bar"></div>
    </div>

    <div class="status-text">Menyiapkan sistem, harap tunggu...</div>

    <div class="dot-loader">
      <span></span><span></span><span></span>
    </div>
  </div>

</div>

<script>
(function(){
  var t = sessionStorage.getItem('armada_tab_id');
  if (!t) {
    var a = new Uint8Array(8);
    crypto.getRandomValues(a);
    t = Array.from(a, function(x){ return ('0' + x.toString(16)).slice(-2); }).join('');
    sessionStorage.setItem('armada_tab_id', t);
  }
  // Delay supaya animasi loading terlihat, lalu redirect
  setTimeout(function(){
    var u = window.location.pathname
          + (window.location.search || '')
          + (window.location.search ? '&' : '?')
          + 'armada_tab=' + t;
    if (window.location.hash) u += window.location.hash;
    window.location.replace(u);
  }, 2000);
})();
</script>
</body>
</html>
HTML;
    exit;
}

if (!function_exists('initSession')) {
    function initSession($role = null) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $tab = get_armada_tab_id();
        $base = $role ? 'ARMADA_' . strtoupper($role) : 'ARMADA_LOGIN';
        $sessionName = $tab ? $base . '_' . $tab : $base;
        session_name($sessionName);
        session_start();
    }
}

// ✅ Buat session token di DB saat login
if (!function_exists('createDbSession')) {
    function createDbSession($id_user, $role) {
        global $connection;

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+8 hours'));
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

        $sql  = "INSERT INTO user_sessions (id_user, session_token, role, expires_at, ip_address) 
                 VALUES (?, ?, ?, ?, ?)";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("issss", $id_user, $token, $role, $expires, $ip);
        $stmt->execute();

        return $token;
    }
}

// ✅ Validasi token dari DB
if (!function_exists('validateDbSession')) {
    function validateDbSession($token) {
        global $connection;
        
        $sql = "SELECT us.*, u.username, u.role, u.status 
                FROM user_sessions us
                JOIN users u ON us.id_user = u.id_user
                WHERE us.session_token = ? 
                  AND us.expires_at > NOW()
                  AND u.status = 'Aktif'
                LIMIT 1";
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows === 1 ? $result->fetch_assoc() : null;
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth($required_role) {
        initSession($required_role);
        
        if (empty($_SESSION['id_login'])) {
            header('Location: ' . url_with_tab('../index.php?expired=1'));
            exit;
        }
        if ($_SESSION['role'] !== $required_role) {
            header('Location: ' . url_with_tab('../index.php?unauthorized=1'));
            exit;
        }
        return true;
    }
}
?>
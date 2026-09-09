<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/version.php';

// --- HANDLE AJAX TEST CONNECTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_connect') {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) ob_clean();

    if (!mh_csrf_check($_POST['csrf'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Sesi CSRF kedaluwarsa, silakan muat ulang halaman.']);
        exit;
    }

    $host = trim($_POST['host'] ?? '');
    $user = trim($_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $port = trim($_POST['port'] ?? '') !== '' ? (int) $_POST['port'] : 8728;
    $ssl  = !empty($_POST['ssl']);

    if (!$host || !$user) {
        echo json_encode(['success' => false, 'message' => 'IP Host dan Username wajib diisi.']);
        exit;
    }

    if (!function_exists('mh_test_reachable') || !mh_test_reachable($host, $port, 3)) {
        echo json_encode(['success' => false, 'message' => "Port $port di IP $host tidak terjangkau (Timeout/Firewall)."]);
        exit;
    }

    try {
        $api = mh_connect($host, $user, $pass, $port, $ssl);
        if ($api) {
            $api->disconnect();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Login API MikroTik gagal. Cek kembali username & password.']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Kesalahan API: ' . $e->getMessage()]);
    }
    exit;
}

// --- NORMAL LOGIN FORM SUBMIT ---
if (mh_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mh_csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $host = trim($_POST['host'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $pass = (string) ($_POST['pass'] ?? '');
        $port = trim($_POST['port'] ?? '') !== '' ? (int) $_POST['port'] : 8728;
        $ssl  = isset($_POST['ssl']);

        if ($host === '' || $user === '') {
            $error = 'IP router dan username wajib diisi.';
        } elseif (!mh_test_reachable($host, $port, 3)) {
            $error = "Tidak bisa menjangkau $host:$port. Periksa IP, jaringan, atau firewall router.";
        } else {
            $api = mh_connect($host, $user, $pass, $port, $ssl);
            if ($api) {
                $_SESSION['mh_host'] = $host;
                $_SESSION['mh_user'] = $user;
                $_SESSION['mh_pass'] = $pass;
                $_SESSION['mh_port'] = $port;
                $_SESSION['mh_ssl']  = $ssl;

                // Baca timezone router sekali saat login
                $tz = @$api->comm('/system/clock/print');
                if (!empty($tz[0]['time-zone-name']) && $tz[0]['time-zone-name'] !== 'manual') {
                    $_SESSION['mh_tz'] = $tz[0]['time-zone-name'];
                }

                $api->disconnect();
                header('Location: index.php');
                exit;
            } else {
                $error = "IP $host terjangkau, tapi login API gagal. Periksa kembali username dan password.";
            }
        }
    }
}

$csrf = mh_csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Sinkronisasi Router</title>
<script>(function(){try{document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<link rel="stylesheet" href="assets/style.css?v=<?= MH_ASSET_VER ?>">
<link rel="manifest" href="assets/manifest.json?v=<?= MH_ASSET_VER ?>">
<meta name="theme-color" content="#0A1220">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="icon" href="assets/icons/favicon-32.png" sizes="32x32">
<style>
  /* Container Utama */
  body.mh-login-body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 10px;
    box-sizing: border-box;
    margin: 0;
    position: relative;
    overflow: hidden;
  }

  /* Canvas Background Partikel Cyberpunk */
  #cyber-particles {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 0;
    pointer-events: none;
  }

  /* Compact Cyberpunk Card */
  .mh-login-card {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 380px;
    padding: 16px 18px !important;
    box-sizing: border-box;
    margin: auto;
  }

  /* Penyesuaian Ukuran Font & Space */
  .mh-login-mark {
    margin-bottom: 6px !important;
  }
  .mh-login-mark svg {
    width: 26px !important;
    height: 26px !important;
  }

  .mh-login-card h1 {
    font-size: 1.15rem !important;
    margin: 0 0 4px 0 !important;
  }

  .mh-login-sub {
    font-size: 0.78rem !important;
    margin-bottom: 12px !important;
    line-height: 1.2 !important;
  }

  .mh-field {
    margin-bottom: 8px !important;
  }

  .mh-field span {
    font-size: 0.68rem !important;
    margin-bottom: 3px !important;
    letter-spacing: 0.5px;
  }

  .mh-field input {
    padding: 7px 10px !important;
    font-size: 0.82rem !important;
    height: auto !important;
  }

  .mh-checkbox {
    font-size: 0.75rem !important;
    margin-top: 4px;
    margin-bottom: 8px !important;
  }

  /* Grid 2 Kolom untuk Username & Port */
  .mh-grid-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }

  /* Ukuran Tombol */
  button.mh-btn-primary, 
  button.mh-btn-secondary {
    padding: 8px 12px !important;
    font-size: 0.78rem !important;
    height: auto !important;
  }

  /* Layout Baris Tombol Batal & Masuk */
  .mh-login-action-row {
    display: flex;
    gap: 8px;
    margin-top: 8px;
  }
  .mh-login-action-row button {
    margin-top: 0 !important;
  }
  .mh-btn-cancel {
    flex: 1;
  }
  .mh-btn-submit {
    flex: 2;
  }

  /* Footer Text */
  .mh-login-note {
    font-size: 10px !important;
    margin-top: 10px !important;
    margin-bottom: 0 !important;
  }
</style>
</head>
<body class="mh-login-body">

  <!-- Background Partikel Canvas -->
  <canvas id="cyber-particles"></canvas>

  <main class="mh-login-card">
    <!-- Icon Logo Wi-Fi Cyberpunk -->
    <div class="mh-login-mark" aria-hidden="true">
      <svg viewBox="0 0 48 48">
        <path d="M24 34a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z" fill="currentColor"/>
        <path d="M12 27c6.6-6.6 17.4-6.6 24 0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <path d="M5 18c10.5-10.5 27.5-10.5 38 0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.55"/>
      </svg>
    </div>

    <h1>DZW Monitoring Sales</h1>
    <p class="mh-login-sub">Masukkan IP, Username & Password MikroTik</p>

    <?php if ($error): ?>
      <div class="mh-alert" style="padding: 6px 10px; font-size: 0.75rem; margin-bottom: 8px;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div id="mhTestAlert" class="mh-hidden" style="padding: 6px 10px; font-size: 0.75rem; margin-bottom: 8px;"></div>

    <form method="post" autocomplete="off" id="mhLoginForm">
      <input type="hidden" name="csrf" id="mhCsrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

      <label class="mh-field">
        <span>IP ADDRESS / HOST ROUTER</span>
        <input type="text" id="mhHost" name="host" placeholder="192.168.88.1" required value="<?= htmlspecialchars($_POST['host'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      </label>

      <!-- Layout 2 Kolom Sejajar -->
      <div class="mh-grid-2col">
        <label class="mh-field">
          <span>USERNAME ROUTER</span>
          <input type="text" id="mhUser" name="user" placeholder="admin" required value="<?= htmlspecialchars($_POST['user'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <label class="mh-field">
          <span>PORT API</span>
          <input type="number" id="mhPort" name="port" placeholder="8728" value="<?= htmlspecialchars($_POST['port'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>
      </div>

      <label class="mh-field">
        <span>PASSWORD ROUTER</span>
        <input type="password" id="mhPass" name="pass" placeholder="••••••••">
      </label>

      <div>
        <label class="mh-checkbox">
          <input type="checkbox" id="mhSsl" name="ssl" <?= isset($_POST['ssl']) ? 'checked' : '' ?>>
          <span>Gunakan API-SSL (port 8729)</span>
        </label>
      </div>

      <!-- Tombol Tes Koneksi Full-Width -->
      <button type="button" class="mh-btn-secondary" id="mhBtnTest" onclick="mhTestConnection()" style="width: 100%;">⚡ TES KONEKSI</button>

      <!-- Tombol Batal & Masuk Berdampingan -->
      <div class="mh-login-action-row">
        <button type="button" class="mh-btn-secondary mh-btn-cancel" onclick="window.location.reload();">BATAL</button>
        <button type="submit" class="mh-btn-primary mh-btn-submit" id="mhLoginBtn" disabled title="Silakan lakukan tes koneksi terlebih dahulu">START MONITORING</button>
      </div>
    </form>

    <!-- Footer Green Neon -->
    <p class="mh-login-note" style="text-align: center; color: var(--green, #2cf0a6); text-shadow: 0 0 6px var(--green, #2cf0a6), 0 0 12px rgba(44, 240, 166, 0.4); font-family: var(--font-mono, monospace);">
      by Afif Mohammed - dzw.my.id
    </p>

  </main>

  <script>
    // --- SCRIPT BACKGROUND PARTIKEL CYBERPUNK ---
    (function() {
      var canvas = document.getElementById('cyber-particles');
      var ctx = canvas.getContext('2d');
      var particles = [];
      var particleCount = 45; // Jumlah partikel optimal agar tidak berat

      function resize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
      }
      window.addEventListener('resize', resize);
      resize();

      function Particle() {
        this.reset();
      }

      Particle.prototype.reset = function() {
        this.x = Math.random() * canvas.width;
        this.y = Math.random() * canvas.height;
        this.size = Math.random() * 2 + 0.5;
        this.speedX = (Math.random() - 0.5) * 0.4;
        this.speedY = (Math.random() - 0.5) * 0.4;
        this.opacity = Math.random() * 0.6 + 0.2;
        // Warna cyan / emerald cyberpunk
        this.color = Math.random() > 0.5 ? '0, 229, 255' : '44, 240, 166';
      };

      Particle.prototype.update = function() {
        this.x += this.speedX;
        this.y += this.speedY;

        if (this.x < 0 || this.x > canvas.width || this.y < 0 || this.y > canvas.height) {
          this.reset();
        }
      };

      Particle.prototype.draw = function() {
        ctx.beginPath();
        ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(' + this.color + ', ' + this.opacity + ')';
        ctx.shadowBlur = 8;
        ctx.shadowColor = 'rgba(' + this.color + ', 0.8)';
        ctx.fill();
        ctx.shadowBlur = 0; // Reset blur
      };

      for (var i = 0; i < particleCount; i++) {
        particles.push(new Particle());
      }

      function animate() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // Garis penghubung tipis antar partikel yang berdekatan
        for (var a = 0; a < particles.length; a++) {
          for (var b = a + 1; b < particles.length; b++) {
            var dx = particles[a].x - particles[b].x;
            var dy = particles[a].y - particles[b].y;
            var dist = Math.sqrt(dx * dx + dy * dy);

            if (dist < 100) {
              ctx.beginPath();
              ctx.strokeStyle = 'rgba(0, 229, 255, ' + (0.15 - dist / 700) + ')';
              ctx.lineWidth = 0.5;
              ctx.moveTo(particles[a].x, particles[a].y);
              ctx.lineTo(particles[b].x, particles[b].y);
              ctx.stroke();
            }
          }
        }

        for (var i = 0; i < particles.length; i++) {
          particles[i].update();
          particles[i].draw();
        }

        requestAnimationFrame(animate);
      }

      animate();
    })();

    // --- FORM LOGIC ---
    var inputs = ['mhHost', 'mhUser', 'mhPass', 'mhPort', 'mhSsl'];
    inputs.forEach(function(id) {
      var el = document.getElementById(id);
      if (el) {
        el.addEventListener('input', resetLoginState);
        el.addEventListener('change', resetLoginState);
      }
    });

    function resetLoginState() {
      var btnSubmit = document.getElementById('mhLoginBtn');
      btnSubmit.disabled = true;
      btnSubmit.title = 'Silakan lakukan tes koneksi terlebih dahulu';
    }

    function mhTestConnection() {
      var host = document.getElementById('mhHost').value.trim();
      var user = document.getElementById('mhUser').value.trim();
      var pass = document.getElementById('mhPass').value;
      var port = document.getElementById('mhPort').value || 8728;
      var ssl  = document.getElementById('mhSsl').checked ? 1 : 0;
      var csrf = document.getElementById('mhCsrf').value;

      var alertBox  = document.getElementById('mhTestAlert');
      var btnTest   = document.getElementById('mhBtnTest');
      var btnSubmit = document.getElementById('mhLoginBtn');

      if (!host || !user) {
        alertBox.className = 'mh-alert';
        alertBox.textContent = 'Harap isi IP Router dan Username terlebih dahulu!';
        return;
      }

      btnTest.disabled = true;
      btnTest.textContent = 'Menguji koneksi...';
      alertBox.className = 'mh-hidden';

      var formData = new FormData();
      formData.append('action', 'test_connect');
      formData.append('host', host);
      formData.append('user', user);
      formData.append('pass', pass);
      formData.append('port', port);
      formData.append('ssl', ssl);
      formData.append('csrf', csrf);

      fetch('login.php', { method: 'POST', body: formData })
        .then(function(res) { 
          if (!res.ok) {
            throw new Error('HTTP status ' + res.status);
          }
          return res.json(); 
        })
        .then(function(data) {
          btnTest.disabled = false;
          btnTest.textContent = '⚡ TES KONEKSI';

          if (data.success) {
            alertBox.className = 'mh-alert-success';
            alertBox.textContent = '🟢 Koneksi ke MikroTik Berhasil!.';
            
            btnSubmit.disabled = false;
            btnSubmit.removeAttribute('title');
          } else {
            alertBox.className = 'mh-alert';
            alertBox.textContent = data.message || '🔴 Gagal terhubung ke router.';
            btnSubmit.disabled = true;
          }
        })
        .catch(function(err) {
          btnTest.disabled = false;
          btnTest.textContent = '⚡ TES KONEKSI';
          alertBox.className = 'mh-alert';
          alertBox.textContent = '🔴 Gagal memproses tes koneksi (' + err.message + ').';
          btnSubmit.disabled = true;
        });
    }

    document.getElementById('mhLoginForm').addEventListener('submit', function () {
      var btn = document.getElementById('mhLoginBtn');
      btn.disabled = true;
      btn.textContent = 'Menyimpan sesi...';
    });

    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('service-worker.js').catch(function () {});
      });
    }
  </script>
</body>
</html>
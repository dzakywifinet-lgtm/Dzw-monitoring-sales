<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/version.php';
mh_require_login();

$host = $_SESSION['mh_host'];
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mh_csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } elseif (($_POST['action'] ?? '') === 'save_fup') {
        $quota = isset($_POST['quota_gb']) ? (float) str_replace(',', '.', $_POST['quota_gb']) : 0;
        $iface = trim($_POST['interface'] ?? '');
        $resetDay = isset($_POST['reset_day']) ? (int) $_POST['reset_day'] : 1;
        if ($quota < 0) { $quota = 0; }
        if ($resetDay < 1) { $resetDay = 1; }
        if ($resetDay > 28) { $resetDay = 28; }
        mh_save_settings($host, array('quota_gb' => $quota, 'interface' => $iface, 'reset_day' => $resetDay));
        $msg = 'Pengaturan FUP tersimpan.';
    } elseif (($_POST['action'] ?? '') === 'sync_now') {
        $currentUsage = isset($_POST['current_usage_gb']) ? (float) str_replace(',', '.', $_POST['current_usage_gb']) : 0;
        mh_sync_bandwidth($host, $currentUsage);
        $msg = 'Pemakaian berhasil disinkronkan.';
    } elseif (($_POST['action'] ?? '') === 'save_telegram') {
        $token = trim($_POST['telegram_token'] ?? '');
        $chatId = trim($_POST['telegram_chat_id'] ?? '');
        $enabled = isset($_POST['telegram_enabled']);
        mh_save_settings($host, array('telegram_token' => $token, 'telegram_chat_id' => $chatId, 'telegram_enabled' => $enabled));
        $msg = 'Pengaturan Telegram tersimpan.';
    } elseif (($_POST['action'] ?? '') === 'telegram_test') {
        $cur = mh_get_settings($host);
        if ($cur['telegram_token'] === '' || $cur['telegram_chat_id'] === '') {
            $error = 'Isi dan simpan Bot Token & Chat ID dulu sebelum kirim tes.';
        } else {
            $ok = mh_telegram_send($cur['telegram_token'], $cur['telegram_chat_id'], "\xF0\x9F\x94\x94 Tes notifikasi dari dashboard penghasilan berhasil.");
            if ($ok) {
                $msg = 'Pesan tes terkirim. Cek Telegram Anda.';
            } else {
                $error = 'Gagal mengirim pesan tes. Periksa kembali Bot Token & Chat ID.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'cron_enable') {
        if (!function_exists('openssl_encrypt')) {
            $error = 'Ekstensi OpenSSL PHP tidak aktif di server ini, Mode Cron tidak bisa diaktifkan.';
        } else {
            // Verifikasi ulang kredensial sesi saat ini benar-benar bisa login, sebelum disimpan untuk cron.
            $testApi = mh_connect(
                $_SESSION['mh_host'],
                $_SESSION['mh_user'],
                $_SESSION['mh_pass'],
                isset($_SESSION['mh_port']) ? $_SESSION['mh_port'] : 8728,
                isset($_SESSION['mh_ssl']) ? $_SESSION['mh_ssl'] : false
            );
            if (!$testApi) {
                $error = 'Gagal memverifikasi ulang koneksi router. Silakan keluar & masuk lagi, lalu aktifkan Mode Cron sekali lagi.';
            } else {
                $testApi->disconnect();
                $saved = mh_save_cron_creds(
                    $host,
                    $_SESSION['mh_user'],
                    $_SESSION['mh_pass'],
                    isset($_SESSION['mh_port']) ? $_SESSION['mh_port'] : 8728,
                    isset($_SESSION['mh_ssl']) ? $_SESSION['mh_ssl'] : false
                );
                if (!$saved) {
                    $error = 'Gagal menyimpan kredensial cron (periksa hak tulis folder data/).';
                } else {
                    $curSettings = mh_get_settings($host);
                    $token = $curSettings['cron_token'] !== '' ? $curSettings['cron_token'] : mh_generate_cron_token();
                    mh_save_settings($host, array('cron_enabled' => true, 'cron_token' => $token));
                    $msg = 'Mode Cron diaktifkan. Salin perintah cron di bawah ke crontab server Anda.';
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'cron_disable') {
        mh_delete_cron_creds($host);
        mh_save_settings($host, array('cron_enabled' => false, 'cron_token' => ''));
        $msg = 'Mode Cron dinonaktifkan dan kredensial tersimpan sudah dihapus.';
    } elseif (($_POST['action'] ?? '') === 'cron_regen_token') {
        mh_save_settings($host, array('cron_token' => mh_generate_cron_token()));
        $msg = 'Token baru dibuat. Perbarui perintah cron di crontab server Anda dengan yang baru di bawah ini.';
    } elseif (($_POST['action'] ?? '') === 'save_voucher_profile') {
        $hotspotName = trim($_POST['voucher_hotspot_name'] ?? '');
        $loginAddress = trim($_POST['voucher_login_address'] ?? '');
        $footerNote = trim($_POST['voucher_footer_note'] ?? '');
        $removeLogo = isset($_POST['remove_logo']);

        $curVoucherSettings = mh_get_settings($host);
        $logoFilename = $curVoucherSettings['voucher_logo'];
        $uploadDir = __DIR__ . '/assets/uploads';

        if ($removeLogo && $logoFilename !== '') {
            @unlink($uploadDir . '/' . $logoFilename);
            $logoFilename = '';
        }

        if (!empty($_FILES['voucher_logo_file']['name']) && $_FILES['voucher_logo_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExt = array('png' => true, 'jpg' => true, 'jpeg' => true, 'webp' => true, 'gif' => true);
            $ext = strtolower(pathinfo($_FILES['voucher_logo_file']['name'], PATHINFO_EXTENSION));
            $size = (int) $_FILES['voucher_logo_file']['size'];
            if (!isset($allowedExt[$ext])) {
                $error = 'Format logo tidak didukung. Pakai PNG/JPG/WEBP/GIF.';
            } elseif ($size > 2 * 1024 * 1024) {
                $error = 'Ukuran logo maksimal 2MB.';
            } else {
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                if ($logoFilename !== '') {
                    @unlink($uploadDir . '/' . $logoFilename); // buang logo lama, jangan menumpuk file yatim
                }
                $newFilename = 'logo_' . mh_settings_key($host) . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['voucher_logo_file']['tmp_name'], $uploadDir . '/' . $newFilename)) {
                    $logoFilename = $newFilename;
                } else {
                    $error = 'Gagal menyimpan file logo (periksa hak tulis folder assets/uploads/).';
                }
            }
        }

        if ($error === '') {
            mh_save_settings($host, array(
                'voucher_hotspot_name'  => $hotspotName,
                'voucher_login_address' => $loginAddress,
                'voucher_footer_note'   => $footerNote,
                'voucher_logo'          => $logoFilename,
            ));
            $msg = 'Profil voucher untuk cetak tersimpan.';
        }
    }
}

$settings = mh_get_settings($host);
// Baca status terakhir dari penyimpanan lokal (tanpa perlu hubungi router - supaya halaman ini ringan/cepat).
$state = mh_track_bandwidth($host, null, $settings['reset_day']);

$quotaBytes = $settings['quota_gb'] * 1024 * 1024 * 1024;
$usedBytes = $state['cycle_rx_bytes'] + $state['cycle_tx_bytes'] + $state['sync_offset_bytes'];
$percent = $quotaBytes > 0 ? round(($usedBytes / $quotaBytes) * 100, 1) : 0;
$remainBytes = $quotaBytes > 0 ? max(0, $quotaBytes - $usedBytes) : 0;
$hasData = $state['last_update'] !== '';

$cronScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'https' : 'http';
$cronDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$cronUrl = $cronScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'domain-anda') . $cronDir . '/cron.php?token=' . $settings['cron_token'];
$cronCmd = '*/2 * * * * curl -s "' . $cronUrl . '" >/dev/null 2>&1';

$csrf = mh_csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pengaturan Kuota FUP &middot; Laporan Penghasilan</title>
<script>(function(){try{var t=localStorage.getItem('mh-theme')||'dark';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="stylesheet" href="assets/style.css?v=<?= MH_ASSET_VER ?>">
<link rel="manifest" href="assets/manifest.json?v=<?= MH_ASSET_VER ?>">
<meta name="theme-color" content="#0A1220">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="icon" href="assets/icons/favicon-32.png" sizes="32x32">
</head>
<body>
<div class="mh-shell">
  <header class="mh-topbar">
    <div class="mh-brand">
      <svg viewBox="0 0 48 48" width="22" height="22" aria-hidden="true">
        <path d="M24 34a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z" fill="currentColor"/>
        <path d="M12 27c6.6-6.6 17.4-6.6 24 0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <path d="M5 18c10.5-10.5 27.5-10.5 38 0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.55"/>
      </svg>
      <div>
        <div class="mh-brand-title">Kuota FUP (Fair Usage Policy)</div>
        <div class="mh-brand-sub"><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="mh-topbar-right">
      <a class="mh-btn-ghost" href="index.php">&larr; Kembali ke dashboard</a>
    </div>
  </header>

  <main class="mh-main" style="max-width:560px;">

    <section class="mh-panel">
      <?php if (!$hasData): ?>
        <div class="mh-alert">Belum ada data terekam. Buka dashboard dulu (dengan interface WAN sudah diisi di bawah) supaya pemakaian mulai terpantau.</div>
      <?php else: ?>
        <h2>Status saat ini: <span class="<?= $quotaBytes > 0 ? ($percent >= 100 ? 'mh-text-danger' : ($percent >= 80 ? 'mh-text-warn' : 'mh-text-ok')) : '' ?>"><?= number_format($percent, 1, ',', '.') ?>% (<?= mh_format_bytes($usedBytes) ?> / <?= $quotaBytes > 0 ? mh_format_bytes($quotaBytes) : '-' ?>)</span></h2>
      <?php endif; ?>
      <p class="mh-panel-desc">
        Pemakaian dihitung dari traffic interface WAN MikroTik yang mengarah ke Indihome,
        diakumulasi otomatis dan reset tiap tanggal yang ditentukan. Bukan dari data resmi
        Indihome &mdash; jadi pakai fitur &ldquo;Sinkronisasi&rdquo; di bawah kalau mau menyamakan
        dengan angka asli di myIndiHome/MyTelkomsel.
      </p>

      <?php if ($hasData): ?>
      <div class="mh-status-row">
        <div><span class="mh-hint">Sisa Kuota</span><br><strong><?= $quotaBytes > 0 ? mh_format_bytes($remainBytes) : '-' ?></strong></div>
        <div><span class="mh-hint">Siklus Berjalan</span><br><strong><?= htmlspecialchars($state['cycle_start'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span class="mh-hint">Terakhir Update</span><br><strong><?= htmlspecialchars($state['last_update'], ENT_QUOTES, 'UTF-8') ?></strong></div>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($msg): ?><div class="mh-alert mh-alert-ok" style="margin:0 0 16px;"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mh-alert" style="margin:0 0 16px;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <section class="mh-panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_fup">

        <label class="mh-field">
          <span>Total Kuota FUP (GB)</span>
          <input type="number" step="0.1" min="0" name="quota_gb" value="<?= htmlspecialchars($settings['quota_gb'], ENT_QUOTES, 'UTF-8') ?>" placeholder="contoh: 4000">
        </label>

        <label class="mh-field">
          <span>Interface WAN yang Dipantau</span>
          <input type="text" name="interface" value="<?= htmlspecialchars($settings['interface'], ENT_QUOTES, 'UTF-8') ?>" placeholder="contoh: ether1">
        </label>
        <p class="mh-field-hint">Nama interface persis seperti di MikroTik &mdash; interface yang langsung ke modem Indihome, bukan interface LAN.</p>

        <label class="mh-field">
          <span>Tanggal Reset Tiap Bulan</span>
          <input type="number" min="1" max="28" name="reset_day" value="<?= htmlspecialchars($settings['reset_day'], ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <button type="submit" class="mh-btn-primary">Simpan Pengaturan FUP</button>
      </form>
    </section>

    <section class="mh-panel">
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="sync_now">

        <label class="mh-field">
          <span>Sinkronisasi Pemakaian Saat Ini (GB)</span>
          <input type="number" step="0.01" min="0" name="current_usage_gb" placeholder="contoh: 114.76">
        </label>
        <p class="mh-field-hint">Cek angka pemakaian bulan ini di app myIndiHome/MyTelkomsel, lalu masukkan di sini. Dashboard akan menyamakan angkanya, dan pemakaian berikutnya tetap dihitung otomatis dari MikroTik di atas angka ini.</p>

        <button type="submit" class="mh-btn-primary">Sinkronkan Sekarang</button>
      </form>
    </section>

    <section class="mh-panel">
      <h2>Notifikasi Telegram</h2>
      <p class="mh-panel-desc">
        Dapat notifikasi ke Telegram tiap kali ada voucher baru terjual &mdash;
        cuma sekali per voucher, tidak berulang-ulang. Butuh Bot Token (buat lewat
        <strong>@BotFather</strong> di Telegram) dan Chat ID tujuan (bisa dapat dari
        <strong>@userinfobot</strong>, atau ID grup kalau ingin dikirim ke grup).
      </p>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_telegram">

        <label class="mh-field">
          <span>Bot Token</span>
          <input type="text" name="telegram_token" value="<?= htmlspecialchars($settings['telegram_token'], ENT_QUOTES, 'UTF-8') ?>" placeholder="123456789:AAExxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
        </label>

        <label class="mh-field">
          <span>Chat ID</span>
          <input type="text" name="telegram_chat_id" value="<?= htmlspecialchars($settings['telegram_chat_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="contoh: 123456789">
        </label>

        <label class="mh-checkbox" style="margin-bottom:16px;">
          <input type="checkbox" name="telegram_enabled" <?= $settings['telegram_enabled'] ? 'checked' : '' ?>>
          <span>Aktifkan notifikasi voucher baru</span>
        </label>

        <button type="submit" class="mh-btn-primary">Simpan Pengaturan Telegram</button>
      </form>

      <form method="post" autocomplete="off" style="margin-top:10px;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="telegram_test">
        <button type="submit" class="mh-btn-ghost" style="width:100%; text-align:center;">Kirim Pesan Tes</button>
      </form>
    </section>

    <section class="mh-panel">
      <h2>Profil Voucher untuk Cetak <span class="mh-hint">&middot; muncul di semua struk/kartu voucher yang dicetak</span></h2>
      <p class="mh-panel-desc">
        Nama hotspot, alamat login, catatan kaki, dan logo di sini akan otomatis muncul
        di setiap struk/kartu voucher yang dicetak dari halaman Voucher (popup Generate Hotspot &amp; PPPoE).
      </p>

      <form method="post" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_voucher_profile">

        <label class="mh-field">
          <span>Nama Hotspot</span>
          <input type="text" name="voucher_hotspot_name" value="<?= htmlspecialchars($settings['voucher_hotspot_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="cth: Dzakywifi.net">
        </label>

        <label class="mh-field">
          <span>Alamat Login (opsional)</span>
          <input type="text" name="voucher_login_address" value="<?= htmlspecialchars($settings['voucher_login_address'], ENT_QUOTES, 'UTF-8') ?>" placeholder="cth: dzakywifi.net">
        </label>
        <p class="mh-field-hint">Ditampilkan di voucher supaya pelanggan tahu ke mana harus login.</p>

        <label class="mh-field">
          <span>Catatan Kaki Voucher (opsional)</span>
          <input type="text" name="voucher_footer_note" value="<?= htmlspecialchars($settings['voucher_footer_note'], ENT_QUOTES, 'UTF-8') ?>" placeholder="cth: Terima kasih telah menggunakan layanan kami">
        </label>

        <label class="mh-field"><span>Logo</span></label>
        <div class="mh-logo-upload-row">
          <div class="mh-logo-preview" id="voucherLogoPreview">
            <?php if ($settings['voucher_logo'] !== ''): ?>
              <img src="assets/uploads/<?= htmlspecialchars($settings['voucher_logo'], ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>" alt="Logo">
            <?php else: ?>
              <span class="mh-logo-preview-icon">&#128247;</span>
            <?php endif; ?>
          </div>
          <div class="mh-logo-upload-controls">
            <label class="mh-btn-ghost mh-file-btn">
              Pilih File
              <input type="file" name="voucher_logo_file" id="voucherLogoInput" accept=".png,.jpg,.jpeg,.webp,.gif" style="display:none;">
            </label>
            <span class="mh-hint" id="voucherLogoFileName">Tidak ada file yang dipilih</span>
            <?php if ($settings['voucher_logo'] !== ''): ?>
              <label class="mh-checkbox" style="margin-top:8px;">
                <input type="checkbox" name="remove_logo" value="1">
                <span>Hapus logo saat ini</span>
              </label>
            <?php endif; ?>
          </div>
        </div>
        <p class="mh-field-hint">PNG/JPG/WEBP/GIF, maks 2MB. Background transparan (PNG) hasilnya paling rapi.</p>

        <button type="submit" class="mh-btn-primary" style="margin-top:16px;">Simpan Profil Voucher</button>
      </form>
    </section>

    <section class="mh-panel">
      <h2>Mode Cron <span class="mh-hint">&middot; notifikasi &amp; pencatatan tetap jalan walau dashboard tidak dibuka</span></h2>
      <p class="mh-panel-desc">
        Saat aktif, username &amp; password router disimpan <strong>terenkripsi</strong>
        (AES-256-CBC) di server, kuncinya disimpan terpisah &mdash; keduanya sudah
        diblokir dari akses langsung lewat browser. Jadwalkan perintah cron di bawah
        supaya pengecekan bandwidth, kategori pemakaian, dan notifikasi Telegram voucher
        baru tetap berjalan sendiri di server, tanpa perlu sesi browser aktif.
      </p>

      <?php if (!empty($settings['cron_enabled']) && $settings['cron_token'] !== ''): ?>
        <div class="mh-status-row" style="margin:0 0 16px;">
          <div><span class="mh-hint">Status</span><br><strong class="mh-text-ok">Aktif</strong></div>
        </div>

        <label class="mh-field">
          <span>Perintah cron (contoh: jalan tiap 2 menit)</span>
          <div class="mh-copy-row">
            <input type="text" id="cronCmd" class="mh-mono-input" readonly value="<?= htmlspecialchars($cronCmd, ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="mh-btn-ghost" id="cronCopyBtn" onclick="mhCopyCron()">Salin</button>
          </div>
        </label>
        <p class="mh-field-hint">
          Tempel baris di atas ke crontab server Anda (<code>crontab -e</code>). Endpoint
          <code>cron.php</code> memakai token rahasia di URL sebagai pengaman, bukan sesi login.
        </p>

        <div class="mh-cron-actions">
          <form method="post" autocomplete="off" onsubmit="return confirm('Buat token baru? Perintah cron yang lama tidak akan berfungsi lagi sampai crontab diperbarui dengan token baru.');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="cron_regen_token">
            <button type="submit" class="mh-btn-ghost">Buat Token Baru</button>
          </form>
          <form method="post" autocomplete="off" onsubmit="return confirm('Nonaktifkan Mode Cron dan hapus kredensial router yang tersimpan untuk cron? Tindakan ini langsung menghapus kredensial.');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="cron_disable">
            <button type="submit" class="mh-btn-ghost mh-btn-ghost-danger">Nonaktifkan &amp; Hapus Kredensial</button>
          </form>
        </div>
      <?php else: ?>
        <div class="mh-status-row" style="margin:0 0 16px;">
          <div><span class="mh-hint">Status</span><br><strong class="mh-hint">Nonaktif</strong></div>
        </div>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="cron_enable">
          <button type="submit" class="mh-btn-primary">Aktifkan Pengecekan Latar Belakang (Cron)</button>
        </form>
        <p class="mh-field-hint" style="margin-top:12px;">Memakai username &amp; password router yang sama seperti sesi login Anda saat ini.</p>
      <?php endif; ?>
    </section>

  </main>
</div>
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('service-worker.js').catch(function () {});
    });
  }

  (function () {
    var input = document.getElementById('voucherLogoInput');
    var label = document.getElementById('voucherLogoFileName');
    if (!input || !label) return;
    input.addEventListener('change', function () {
      label.textContent = input.files && input.files[0] ? input.files[0].name : 'Tidak ada file yang dipilih';
    });
  })();

  function mhCopyCron() {
    var input = document.getElementById('cronCmd');
    var btn = document.getElementById('cronCopyBtn');
    if (!input) return;
    var done = function () {
      if (!btn) return;
      var old = btn.textContent;
      btn.textContent = 'Tersalin!';
      setTimeout(function () { btn.textContent = old; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(input.value).then(done).catch(function () {
        input.select();
        document.execCommand('copy');
        done();
      });
    } else {
      input.select();
      document.execCommand('copy');
      done();
    }
  }
</script>
</body>
</html>

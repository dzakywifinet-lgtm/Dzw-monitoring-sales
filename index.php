<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/version.php';
mh_require_login();

$rawHost = $_SESSION['mh_host'];
$connectError = '';
$dash = mh_dash_defaults();

/**
 * PENTING (kecepatan buka dashboard): halaman ini TIDAK menghubungi router
 * secara langsung setiap dibuka - itu yang bikin "masuk dashboard" terasa
 * lama setelah sinkronisasi/login atau setelah kembali dari Pengaturan,
 * karena dulu index.php menunggu beberapa panggilan API MikroTik (identitas,
 * daftar interface, semua data voucher, dst) selesai dulu sebelum halaman
 * bisa tampil sama sekali.
 *
 * Sekarang: baca cache hasil poll terakhir (lihat mh_update_dash_cache() -
 * ditulis tiap kali api/live.php atau api/sales.php jalan, termasuk dari Mode
 * Cron) supaya halaman langsung tampil TANPA menunggu router. Begitu halaman
 * tampil, assets/app.js langsung memanggil api/live.php + api/sales.php agar
 * data tersegarkan dalam hitungan detik. Router baru dihubungi langsung di
 * sini kalau memang belum pernah ada cache sama sekali (mis. baru pertama
 * kali login di instalasi ini).
 */
$cache = mh_get_dash_cache($rawHost);
if ($cache) {
    $dash = mh_merge_dash_data($dash, $cache);
} else {
    $api = mh_connect_from_session();
    if ($api) {
        $dash = mh_merge_dash_data($dash, mh_build_dashboard($api, $rawHost));
        $api->disconnect();
    } else {
        $connectError = 'Tidak bisa terhubung ke router saat ini. Menampilkan halaman kosong, coba muat ulang.';
    }
}

$identity = $dash['identity'];
$interfaces = $dash['interfaces'];
$host = htmlspecialchars($rawHost, ENT_QUOTES, 'UTF-8');
$yearly = $dash['yearly'];
$bw = $dash['bandwidth'];
$usage = $dash['usage'];
$defaultIface = $bw['interface_set'] ? $bw['interface'] : (isset($interfaces[0]) ? $interfaces[0]['name'] : '');

$weekMax = 1;
foreach ($dash['week'] as $d) { if ($d['total'] > $weekMax) { $weekMax = $d['total']; } }
$yearMax = 1;
foreach ($yearly['months'] as $m) { if ($m['total'] > $yearMax) { $yearMax = $m['total']; } }

$csrf = mh_csrf_token();
$percentClamped = max(0, min(100, $bw['percent']));
$circumference = 2 * M_PI * 52;
$gaugeOffset = $circumference * (1 - $percentClamped / 100);
$gaugeClass = 'mh-gauge-unset';
if ($bw['quota_gb'] > 0) {
    $gaugeClass = $bw['percent'] >= 100 ? 'mh-gauge-over' : ($bw['percent'] >= 80 ? 'mh-gauge-warn' : 'mh-gauge-ok');
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" id="viewportMeta" content="width=device-width, initial-scale=1">
<title>Laporan Penghasilan &middot; <?= htmlspecialchars($identity, ENT_QUOTES, 'UTF-8') ?></title>
<script>(function(){try{var t=localStorage.getItem('mh-theme')||'dark';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<link rel="stylesheet" href="assets/style.css?v=<?= MH_ASSET_VER ?>">
<link rel="manifest" href="assets/manifest.json?v=<?= MH_ASSET_VER ?>">
<meta name="theme-color" content="#0A1220">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="icon" href="assets/icons/favicon-32.png" sizes="32x32">
</head>
<body data-server-time="<?= htmlspecialchars($dash['server_time'], ENT_QUOTES, 'UTF-8') ?>" data-server-date="<?= htmlspecialchars($dash['server_date'], ENT_QUOTES, 'UTF-8') ?>">
<!-- Partikel latar belakang (logic: assets/app.js, style: assets/style.css) -->
<canvas id="cyber-particles"></canvas>
<div class="mh-shell">

  <header class="mh-topbar">
    <div class="mh-brand">
      <svg viewBox="0 0 48 48" width="22" height="22" aria-hidden="true">
        <path d="M24 34a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z" fill="currentColor"/>
        <path d="M12 27c6.6-6.6 17.4-6.6 24 0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        <path d="M5 18c10.5-10.5 27.5-10.5 38 0" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.55"/>
      </svg>
      <div>
        <div class="mh-brand-title">Laporan penghasilan</div>
        <div class="mh-brand-sub"><?= htmlspecialchars($identity, ENT_QUOTES, 'UTF-8') ?> &middot; <?= $host ?></div>
      </div>
    </div>
    <div class="mh-router-info">
      <span title="Beban CPU router">CPU <strong id="infoCpu"><?= htmlspecialchars($dash['resource']['cpu'], ENT_QUOTES, 'UTF-8') ?></strong>%</span>
      <span title="Lama router menyala">Uptime <strong id="infoUptime"><?= htmlspecialchars($dash['resource']['uptime'], ENT_QUOTES, 'UTF-8') ?></strong></span>
      <span title="Jam di router">Jam <strong id="infoClock"><?= htmlspecialchars($dash['server_time'], ENT_QUOTES, 'UTF-8') ?></strong></span>
    </div>
    <div class="mh-topbar-right">
      <span class="mh-status" id="mhStatus"><span class="mh-dot" id="mhDot"></span><span id="mhStatusText">live</span></span>
      <button type="button" class="mh-btn-ghost mh-theme-toggle" id="themeToggle" title="Ganti tema terang/gelap" aria-label="Ganti tema">&#127769;</button>
      <a class="mh-btn-ghost" href="settings.php">Pengaturan</a>
      <a class="mh-btn-ghost" href="logout.php">Keluar</a>
    </div>
  </header>

  <?php if ($connectError): ?>
    <div class="mh-alert" style="margin:16px 24px;"><?= htmlspecialchars($connectError, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <main class="mh-main">

    <section class="mh-hero mh-hero-4">
      <div class="mh-stat mh-stat-primary">
        <span class="mh-stat-label">Hari ini</span>
        <span class="mh-stat-value" id="statTodayTotal"><?= mh_format_rp($dash['today_total']) ?></span>
        <span class="mh-stat-sub"><span id="statTodayCount"><?= $dash['today_count'] ?></span> voucher terjual</span>
      </div>
      <div class="mh-stat">
        <span class="mh-stat-label">Bulan ini &middot; <span id="statMonthLabel"><?= htmlspecialchars($dash['month_label'], ENT_QUOTES, 'UTF-8') ?></span></span>
        <span class="mh-stat-value" id="statMonthTotal"><?= mh_format_rp($dash['month_total']) ?></span>
        <span class="mh-stat-sub"><span id="statMonthCount"><?= $dash['month_count'] ?></span> voucher terjual</span>
      </div>
      <div class="mh-stat mh-stat-clickable" id="heroOnlineCard" title="Klik untuk lihat detail pengguna hotspot aktif">
        <span class="mh-stat-label">Hotspot online</span>
        <span class="mh-stat-value mh-stat-value-signal" id="statOnlineCount"><?= $dash['online_count'] ?></span>
        <span class="mh-stat-sub">sedang tersambung ke hotspot &middot; klik rincian</span>
      </div>
      <div class="mh-stat mh-stat-clickable" id="heroPppoeCard" title="Klik untuk lihat detail PPPoE aktif">
        <span class="mh-stat-label">PPPoE online</span>
        <span class="mh-stat-value mh-stat-value-signal" id="statPppoeCount"><?= $dash['pppoe_active_count'] ?></span>
        <span class="mh-stat-sub">sesi PPPoE tersambung &middot; klik rincian</span>
      </div>
    </section>

    <div class="mh-row-2 mh-row-2-traffic">
    <section class="mh-panel">
      <div class="mh-panel-head">
        <h2>Traffic real-time <span class="mh-hint">&middot; </span></h2>
        <?php if (!empty($interfaces)): ?>
          <select id="trafficIface" class="mh-select-inline">
            <?php foreach ($interfaces as $if): ?>
              <option value="<?= htmlspecialchars($if['name'], ENT_QUOTES, 'UTF-8') ?>" <?= $if['name'] === $defaultIface ? 'selected' : '' ?>><?= htmlspecialchars($if['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <?php if (empty($interfaces)): ?>
        <div class="mh-gauge-empty"><p>Tidak bisa membaca daftar interface dari router.</p></div>
      <?php else: ?>
        <div class="mh-wave-wrap">
          <canvas id="trafficChart" height="90"></canvas>
        </div>
        <div class="mh-traffic-divider"></div>
        <div class="mh-traffic-badges">
          <div class="mh-traffic-badge">
            <div class="mh-traffic-ring mh-traffic-ring-rx">
              <span class="mh-traffic-ring-value" id="trafficRxValue">-</span>
              <span class="mh-traffic-ring-unit" id="trafficRxUnit">Mbps</span>
            </div>
            <div class="mh-traffic-badge-label"><i class="mh-wave-dot mh-wave-dot-rx"></i> Download</div>
          </div>
          <div class="mh-traffic-badge">
            <div class="mh-traffic-ring mh-traffic-ring-tx">
              <span class="mh-traffic-ring-value" id="trafficTxValue">-</span>
              <span class="mh-traffic-ring-unit" id="trafficTxUnit">Mbps</span>
            </div>
            <div class="mh-traffic-badge-label"><i class="mh-wave-dot mh-wave-dot-tx"></i> Upload</div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="mh-panel">
      <div class="mh-panel-head">
        <h2>Acces Point & User static <span class="mh-hint">&middot;</span></h2>
        <?php
        $ipOnlineCountInit = 0;
        foreach ($dash['ip_bindings'] as $bInit) {
            if (!empty($bInit['online'])) { $ipOnlineCountInit++; }
        }
        ?>
        <span class="mh-badge mh-badge-online" id="ipBindingOnlineBadge"><span id="ipBindingOnlineCount"><?= $ipOnlineCountInit ?></span> online</span>
      </div>
      <div id="ipBindingList" class="mh-ipbinding-list">
        <?php
        // ?? dipakai supaya aman kalau masih ada cache lama (format sebelum v5,
        // belum punya field 'group'/'label') sesaat sebelum poll pertama jalan.
        $ipGroupLabels = array('ap' => 'Access Point', 'user' => 'Pengguna (USER)', 'other' => 'Lainnya');
        $ipGroups = array('ap' => array(), 'user' => array(), 'other' => array());
        foreach ($dash['ip_bindings'] as $b) {
            $gk = isset($ipGroups[$b['group'] ?? '']) ? $b['group'] : 'other';
            $ipGroups[$gk][] = $b;
        }
        ?>
        <?php if (empty($dash['ip_bindings'])): ?>
          <div class="mh-gauge-empty"><p>Belum ada IP Binding, atau semua nonaktif.</p></div>
        <?php else: foreach ($ipGroupLabels as $gk => $glabel): if (empty($ipGroups[$gk])) { continue; } ?>
          <div class="mh-ipbinding-group">
            <div class="mh-ipbinding-group-title"><?= htmlspecialchars($glabel, ENT_QUOTES, 'UTF-8') ?></div>
            <?php foreach ($ipGroups[$gk] as $b): ?>
              <div class="mh-ipbinding-row">
                <span class="mh-ip-dot <?= $b['online'] ? 'mh-ip-dot-on' : 'mh-ip-dot-off' ?>" title="<?= $b['online'] ? 'Online' : 'Offline' ?>"></span>
                <div class="mh-ipbinding-info">
                  <span class="mh-ipbinding-name"><?= htmlspecialchars($b['label'] ?? ($b['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="mh-ipbinding-sub"><?= htmlspecialchars(($b['address'] ?? '') !== '' ? $b['address'] : ($b['mac'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
    </div>

    <div class="mh-row-2">
    <section class="mh-panel">
      <h2>7 hari terakhir <span class="mh-hint">&middot; klik batang untuk rincian penjualan</span></h2>
      <div class="mh-bars" id="weekBars">
        <?php foreach ($dash['week'] as $i => $d): ?>
          <div class="mh-bar-col mh-bar-clickable" data-index="<?= $i ?>" data-label="<?= htmlspecialchars($d['date_label'], ENT_QUOTES, 'UTF-8') ?>" data-items='<?= htmlspecialchars(json_encode($d['items']), ENT_QUOTES, 'UTF-8') ?>' title="<?= mh_format_rp($d['total']) ?>">
            <div class="mh-bar-track">
              <div class="mh-bar-fill" style="height: <?= $d['total'] > 0 ? max(4, round($d['total'] / $weekMax * 100)) : 2 ?>%;"></div>
            </div>
            <span class="mh-bar-label"><?= htmlspecialchars($d['label'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="mh-panel">
      <h2>Grafik bulanan <span class="mh-hint">&middot; tahun <?= htmlspecialchars($yearly['year'], ENT_QUOTES, 'UTF-8') ?> &middot; klik batang untuk total sebulan</span></h2>
      <div class="mh-bars mh-bars-year" id="yearBars">
        <?php foreach ($yearly['months'] as $m): ?>
          <div class="mh-bar-col mh-bar-clickable mh-bar-month" data-label="<?= htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') ?>" data-total="<?= $m['total'] ?>" data-count="<?= $m['count'] ?>" title="<?= mh_format_rp($m['total']) ?>">
            <div class="mh-bar-track">
              <div class="mh-bar-fill mh-bar-fill-alt" style="height: <?= $m['total'] > 0 ? max(4, round($m['total'] / $yearMax * 100)) : 2 ?>%;"></div>
            </div>
            <span class="mh-bar-label"><?= htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    </div>

    <div class="mh-row-2">
    <section class="mh-panel">
      <h2>Bandwidth &amp; kuota FUP</h2>
      <?php if (!$bw['interface_set']): ?>
        <div class="mh-gauge-empty">
          <p>Interface WAN belum diatur.</p>
          <a class="mh-btn-ghost" href="settings.php">Atur di halaman Pengaturan</a>
        </div>
      <?php else: ?>
        <div class="mh-bw-grid">
          <div class="mh-gauge-wrap <?= $bw['quota_gb'] <= 0 ? 'mh-gauge-dim' : '' ?>">
            <svg viewBox="0 0 120 120" width="140" height="140">
              <circle cx="60" cy="60" r="52" fill="none" stroke="var(--panel-line)" stroke-width="12"/>
              <circle id="gaugeRing" class="<?= $gaugeClass ?>" cx="60" cy="60" r="52" fill="none" stroke-width="12"
                stroke-linecap="round" transform="rotate(-90 60 60)"
                stroke-dasharray="<?= round($circumference, 2) ?>"
                stroke-dashoffset="<?= round($gaugeOffset, 2) ?>"/>
              <text x="60" y="56" text-anchor="middle" class="mh-gauge-percent" id="gaugePercent"><?= number_format($bw['percent'], 1, ',', '.') ?>%</text>
              <text x="60" y="74" text-anchor="middle" class="mh-gauge-caption">terpakai</text>
            </svg>
            <div class="mh-gauge-info">
              <span id="usageBytes"><?= htmlspecialchars($bw['used_fmt'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="mh-hint">dari kuota <span id="usageQuota"><?= $bw['quota_gb'] > 0 ? htmlspecialchars($bw['quota_fmt'], ENT_QUOTES, 'UTF-8') : '-' ?></span></span>
            </div>
          </div>
          <div class="mh-bw-stats">
            <div class="mh-bw-row"><span>Download bulan ini</span><strong id="bwDown"><?= htmlspecialchars($bw['rx_fmt'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="mh-bw-row"><span>Upload bulan ini</span><strong id="bwUp"><?= htmlspecialchars($bw['tx_fmt'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="mh-bw-row"><span>Total bulan ini</span><strong id="bwTotal"><?= htmlspecialchars($bw['used_fmt'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="mh-bw-row mh-bw-row-muted">
              <span>Total bulan sebelumnya</span>
              <strong id="bwPrev"><?= $bw['prev_cycle'] ? mh_format_bytes($bw['prev_cycle']['total']) : '-' ?></strong>
            </div>
            <div class="mh-hint" id="bwUpdated">Diperbarui <?= htmlspecialchars($bw['last_update'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="mh-panel">
      <h2>Analisis pemakaian <span class="mh-hint">&middot; siklus berjalan</span></h2>
      <?php if (!$bw['interface_set']): ?>
        <div class="mh-gauge-empty">
          <p>Interface WAN belum diatur.</p>
          <a class="mh-btn-ghost" href="settings.php">Atur di halaman Pengaturan</a>
        </div>
      <?php elseif (empty($usage['items'])): ?>
        <div class="mh-gauge-empty">
          <p>Belum ada data. Pastikan skrip <code>usage-kategori-mikrotik.rsc</code> sudah dipasang di router.</p>
        </div>
      <?php else: ?>
        <div id="usageList" class="mh-usage-list">
          <?php foreach ($usage['items'] as $it): ?>
            <div class="mh-usage-row" data-key="<?= htmlspecialchars($it['key'], ENT_QUOTES, 'UTF-8') ?>">
              <div class="mh-usage-top">
                <span class="mh-usage-dot" style="background:<?= htmlspecialchars($it['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                <span class="mh-usage-label"><?= htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="mh-usage-fmt"><?= htmlspecialchars($it['fmt'], ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <div class="mh-usage-track">
                <div class="mh-usage-fill" style="width:<?= max(2, $it['percent']) ?>%;background:<?= htmlspecialchars($it['color'], ENT_QUOTES, 'UTF-8') ?>"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mh-hint" id="usageUpdated">Diperbarui <?= htmlspecialchars($usage['last_update'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
        <p class="mh-hint mh-usage-note">Berdasarkan domain yang dikenali (lihat <code>usage-kategori-mikrotik.rsc</code>) - "Web &amp; lainnya" adalah sisa pemakaian yang belum terklasifikasi.</p>
      <?php endif; ?>
    </section>
    </div>

    <section class="mh-panel">
      <h2>Transaksi terbaru <span class="mh-hint">&middot; geser untuk lihat semua</span></h2>
      <div class="mh-table-wrap mh-table-scroll">
        <table class="mh-table">
          <thead>
            <tr><th>Tanggal</th><th>Jam</th><th>Pengguna</th><th>Profil</th><th class="mh-num">Harga</th></tr>
          </thead>
          <tbody id="recentBody">
            <?php if (empty($dash['recent'])): ?>
              <tr><td colspan="5" class="mh-empty">Belum ada transaksi bulan ini.</td></tr>
            <?php else: foreach ($dash['recent'] as $r): ?>
              <tr>
                <td><?= htmlspecialchars(isset($r['date']) ? $r['date'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['time'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['user'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($r['profile'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="mh-num"><?= mh_format_rp($r['price']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ================= Generate Hotspot & PPPoE (fitur baru) ================= -->
    <section class="mh-panel mh-panel-compact">
      <div class="mh-panel-head">
        <h2>Generate Hotspot &amp; PPPoE <span class="mh-hint">&middot; buat voucher/akun baru </span></h2>
      </div>
      <button type="button" class="mh-btn-primary mh-btn-open-generate" id="btnOpenGenerate">Buka Generate Hotspot &amp; PPPoE</button>
    </section>

    <p class="mh-footnote">Diperbarui otomatis setiap 15 detik &middot; grafik bulanan &amp; bandwidth setiap 60 detik &middot; pembaruan terakhir <span id="lastUpdated"><?= $dash['server_time'] ?></span></p>

  </main>
</div>

<!-- Modal rincian penjualan harian -->
<div class="mh-modal-overlay" id="dayModal" hidden>
  <div class="mh-modal" role="dialog" aria-modal="true" aria-labelledby="dayModalTitle">
    <div class="mh-modal-head">
      <h3 id="dayModalTitle">Rincian penjualan</h3>
      <button type="button" class="mh-modal-close" data-close-modal aria-label="Tutup">&times;</button>
    </div>
    <div class="mh-modal-body" id="dayModalBody"></div>
  </div>
</div>

<!-- Modal total penjualan bulanan -->
<div class="mh-modal-overlay" id="monthModal" hidden>
  <div class="mh-modal mh-modal-sm" role="dialog" aria-modal="true" aria-labelledby="monthModalTitle">
    <div class="mh-modal-head">
      <h3 id="monthModalTitle">Total penjualan</h3>
      <button type="button" class="mh-modal-close" data-close-modal aria-label="Tutup">&times;</button>
    </div>
    <div class="mh-modal-body" id="monthModalBody"></div>
  </div>
</div>

<!-- Modal detail Hotspot aktif -->
<div class="mh-modal-overlay" id="hotspotActiveModal" hidden>
  <div class="mh-modal mh-modal-lg" role="dialog" aria-modal="true" aria-labelledby="hotspotActiveModalTitle">
    <div class="mh-modal-head">
      <h3 id="hotspotActiveModalTitle">Pengguna Hotspot Aktif</h3>
      <button type="button" class="mh-modal-close" data-close-modal aria-label="Tutup">&times;</button>
    </div>
    <div class="mh-modal-body" id="hotspotActiveModalBody"><p class="mh-hint">Memuat&hellip;</p></div>
  </div>
</div>

<!-- Modal detail PPPoE aktif -->
<div class="mh-modal-overlay" id="pppoeActiveModal" hidden>
  <div class="mh-modal mh-modal-lg" role="dialog" aria-modal="true" aria-labelledby="pppoeActiveModalTitle">
    <div class="mh-modal-head">
      <h3 id="pppoeActiveModalTitle">PPPoE Aktif</h3>
      <button type="button" class="mh-modal-close" data-close-modal aria-label="Tutup">&times;</button>
    </div>
    <div class="mh-modal-body" id="pppoeActiveModalBody"><p class="mh-hint">Memuat&hellip;</p></div>
  </div>
</div>

<!-- Modal Generate Hotspot & PPPoE (fitur baru - dipindah ke popup) -->
<div class="mh-modal-overlay" id="generateModal" hidden>
  <div class="mh-modal mh-modal-xl" role="dialog" aria-modal="true" aria-labelledby="generateModalTitle">
    <div class="mh-modal-head">
      <h3 id="generateModalTitle">Generate Hotspot &amp; PPPoE</h3>
      <button type="button" class="mh-modal-close" data-close-modal aria-label="Tutup">&times;</button>
    </div>
    <div class="mh-modal-body">
      <div id="generatePanel">
        <div class="mh-panel-head">
          <h4 class="mh-gen-subtitle">Sisa voucher per profile</h4>
          <button type="button" class="mh-btn-ghost" id="btnTestSaleNotif" title="Cek suara &amp; popup notifikasi tanpa perlu nunggu voucher asli terpakai">Tes Notifikasi</button>
          <button type="button" class="mh-btn-ghost" id="btnRefreshGenerate">Muat ulang</button>
        </div>

        <div id="voucherSummaryGrid" class="mh-voucher-summary-grid">
          <p class="mh-hint">Memuat ringkasan sisa voucher&hellip;</p>
        </div>

        <div class="mh-gen-tabs">
          <button type="button" class="mh-gen-tab mh-gen-tab-active" data-gen-tab="hotspot">Generate Voucher Hotspot</button>
          <button type="button" class="mh-gen-tab" data-gen-tab="pppoe">Buat Akun PPPoE</button>
        </div>

        <div id="genTabHotspot" class="mh-gen-tab-panel">
          <div id="genHotspotAlert" class="hidden mh-alert-box"></div>
          <form id="genHotspotForm" class="mh-gen-form">
            <label class="mh-field mh-field-sm2"><span>Qty</span><input type="number" name="count" value="1" min="1" max="500" required></label>
            <label class="mh-field"><span>Profile</span><select name="profile" id="genHotspotProfile" required><option value="">-- Memuat profile... --</option></select></label>
            <label class="mh-field"><span>Server</span><select name="server" id="genHotspotServer"><option value="all">-- Memuat server... --</option></select></label>
            <label class="mh-field"><span>User Mode</span>
              <select name="mode">
                <option value="same">Username = Password</option>
                <option value="different">Username &amp; Password Beda</option>
              </select>
            </label>
            <label class="mh-field mh-field-sm2"><span>Panjang Nama</span>
              <select name="name_length">
                <?php for ($len = 4; $len <= 10; $len++): ?>
                  <option value="<?= $len ?>" <?= $len === 6 ? 'selected' : '' ?>><?= $len ?></option>
                <?php endfor; ?>
              </select>
            </label>
            <label class="mh-field"><span>Prefix (opsional)</span><input type="text" name="prefix" maxlength="10" placeholder="cth: NET-"></label>
            <label class="mh-field"><span>Karakter</span>
              <select name="charset">
                <option value="mixed_safe">Random abcd2345</option>
                <option value="lower_numeric">Huruf Kecil + Angka</option>
                <option value="upper_numeric">Huruf Besar + Angka</option>
                <option value="lower">Huruf Kecil Saja</option>
                <option value="upper">Huruf Besar Saja</option>
                <option value="numeric">Angka Saja</option>
              </select>
            </label>
            <label class="mh-field"><span>Time Limit (opsional)</span><input type="text" name="time_limit" placeholder="cth: 5h, 1d, 30m"></label>
            <label class="mh-field"><span>Data Limit (opsional)</span>
              <div class="mh-gen-datalimit">
                <input type="number" step="0.1" min="0" name="data_limit" placeholder="cth: 500">
                <select name="data_limit_unit"><option value="MB">MB</option><option value="GB">GB</option></select>
              </div>
            </label>
            <label class="mh-field"><span>Comment (opsional)</span><input type="text" name="comment" placeholder="default: Batch tanggal-jam"></label>
            <div class="mh-gen-submit">
              <button type="submit" class="mh-btn-primary" id="btnGenHotspot">Generate Voucher</button>
            </div>
          </form>
          <div id="genHotspotResult" class="mh-gen-result hidden"></div>
        </div>

        <div id="genTabPppoe" class="mh-gen-tab-panel" hidden>
          <div id="genPppoeAlert" class="hidden mh-alert-box"></div>
          <form id="genPppoeForm" class="mh-gen-form">
            <label class="mh-field"><span>Username</span><input type="text" name="username" id="pppoeUsername" required autocomplete="off"></label>
            <label class="mh-field"><span>Password</span><input type="text" name="password" id="pppoePassword" required autocomplete="off"></label>
            <div class="mh-gen-submit mh-gen-submit-inline">
              <button type="button" class="mh-btn-ghost" id="btnPppoeRandom">Acak otomatis</button>
            </div>
            <label class="mh-field"><span>Profile PPPoE</span><select name="profile" id="genPppoeProfile" required><option value="">-- Memuat profile... --</option></select></label>
            <label class="mh-field"><span>Comment / Nama Pelanggan</span><input type="text" name="comment" placeholder="cth: USER- mas imam"></label>
            <label class="mh-field"><span>Local Address (opsional)</span><input type="text" name="local_address" placeholder="cth: 10.10.10.1"></label>
            <label class="mh-field"><span>Remote Address (opsional)</span><input type="text" name="remote_address" placeholder="cth: 10.10.10.50"></label>
            <div class="mh-gen-submit">
              <button type="submit" class="mh-btn-primary" id="btnGenPppoe">Buat Akun PPPoE</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal isi voucher per profile (lihat/hapus/cetak) -->
<div class="mh-modal-overlay" id="voucherProfileModal" hidden>
  <div class="mh-modal mh-modal-lg" role="dialog" aria-modal="true" aria-labelledby="voucherProfileModalTitle">
    <div class="mh-modal-head">
      <h3 id="voucherProfileModalTitle">Voucher</h3>
      <button type="button" class="mh-modal-close" id="voucherProfileModalClose" aria-label="Tutup">&times;</button>
    </div>
    <div class="mh-modal-body">
      <div class="mh-voucher-toolbar">
        <input type="text" id="voucherSearchInput" class="mh-voucher-search" placeholder="Cari username...">
        <select id="voucherCommentFilter" class="mh-select-inline mh-voucher-comment-filter">
          <option value="">-- Semua Comment --</option>
        </select>
      </div>

      <div class="mh-voucher-actions-row" id="voucherActionsRow">
        <button type="button" class="mh-btn-chip mh-btn-chip-danger" id="btnHapusComment">Hapus Comment</button>
        <button type="button" class="mh-btn-chip" data-print-layout="default">Default</button>
        <button type="button" class="mh-btn-chip" data-print-layout="kupon">Kupon</button>
        <button type="button" class="mh-btn-chip" data-print-layout="qr">QR</button>
        <button type="button" class="mh-btn-chip" data-print-layout="small">Small</button>
        <button type="button" class="mh-btn-chip" data-print-layout="thermal">Thermal</button>
        <button type="button" class="mh-btn-chip" data-print-layout="gridA4">Grid A4</button>
        <button type="button" class="mh-btn-chip mh-btn-chip-custom" id="btnCustomTemplate">Custom</button>
      </div>
      <p class="mh-hint mh-voucher-toolbar-hint">Pilih Comment untuk fokus ke satu batch generate &mdash; Hapus Comment &amp; Print di atas berlaku untuk voucher yang sedang tampil di tabel bawah (hasil filter search + comment).</p>

      <div class="mh-voucher-count" id="voucherCount">0 voucher</div>
      <div id="voucherProfileModalBody"><p class="mh-hint">Memuat&hellip;</p></div>
    </div>
  </div>
</div>

<!-- Modal Template Editor (cetak voucher custom) -->
<div class="mh-modal-overlay" id="templateEditorModal" hidden>
  <div class="mh-modal mh-modal-xl" role="dialog" aria-modal="true" aria-labelledby="templateEditorModalTitle">
    <div class="mh-modal-head">
      <h3 id="templateEditorModalTitle">Template Editor</h3>
      <button type="button" class="mh-modal-close" id="templateEditorModalClose" aria-label="Tutup">&times;</button>
    </div>
    <div class="mh-modal-body">
      <div class="mh-tpl-toolbar">
        <button type="button" class="mh-btn-primary mh-tpl-print-btn" id="tplBtnPrint">Cetak dengan Template Ini</button>
        <button type="button" class="mh-btn-ghost" id="tplBtnSave">Save</button>
        <button type="button" class="mh-btn-ghost" id="tplBtnLogo">Logo</button>
        <select id="tplSelectStart" class="mh-select-inline">
          <option value="">-- pilih template --</option>
        </select>
        <span class="mh-tpl-toolbar-spacer"></span>
        <button type="button" class="mh-btn-ghost mh-btn-ghost-danger" id="tplBtnDelete">Hapus</button>
        <button type="button" class="mh-btn-ghost mh-btn-ghost-danger" id="tplBtnReset">Reset</button>
      </div>
      <p class="mh-hint" id="tplPrintHint">Cetak akan memakai voucher yang sedang tampil di tabel popup Voucher (hasil filter search + comment saat ini).</p>

      <div class="mh-tpl-editor-grid">
        <div>
          <textarea id="tplTextarea" class="mh-tpl-textarea" spellcheck="false" placeholder="Tulis HTML template voucher di sini, pakai token seperti {{username}}, {{password}}, {{harga}}, dst - lihat panel Variable di kanan."></textarea>
          <p class="mh-field-hint">Ini HTML biasa + token <code>{{seperti_ini}}</code> - <strong>bukan</strong> kode PHP yang dieksekusi di server, jadi aman disimpan. Boleh pakai tag &lt;table&gt;, &lt;style&gt; inline, dsb - sama seperti bikin halaman HTML biasa.</p>
        </div>
        <div class="mh-tpl-varpanel">
          <div class="mh-tpl-varpanel-title">Variable</div>
          <div class="mh-tpl-var-list" id="tplVarList"></div>
        </div>
      </div>

      <div class="mh-tpl-preview-wrap">
        <div class="mh-gen-subtitle">Preview (contoh data)</div>
        <iframe id="tplPreviewFrame" class="mh-tpl-preview-frame" title="Preview template voucher"></iframe>
        <p class="mh-field-hint">Preview pakai data contoh (bukan voucher sungguhan), dan dirender terisolasi persis seperti hasil cetak asli - jadi kalau di sini sudah rapi, hasil print-nya juga bakal sama rapinya.</p>
      </div>
    </div>
  </div>
</div>

<script>window.MH_CSRF = <?= json_encode($csrf) ?>;</script>
<div id="voucherSaleNotifStack" class="mh-sale-notif-stack" aria-live="polite"></div>

<!-- Toggle mode tampilan paksa (pojok kanan bawah, desktop & mobile) -->
<div class="mh-view-mode-toggle">
  <button type="button" class="mh-view-mode-btn viewmode-toggle-btn" onclick="toggleViewMode()"></button>
</div>
<script src="assets/app.js?v=<?= MH_ASSET_VER ?>"></script>
<script src="assets/viewmode.js?v=<?= MH_ASSET_VER ?>"></script>
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('service-worker.js').catch(function () {});
    });
  }
</script>
</body>
</html>

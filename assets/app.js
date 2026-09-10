(function () {
  var REFRESH_MS = 15000;
  var SLOW_REFRESH_MS = 60000;
  var CIRCUMFERENCE = 2 * Math.PI * 52;

  var dot = document.getElementById('mhDot');
  var statusText = document.getElementById('mhStatusText');

  function formatRp(n) {
    return 'Rp ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function formatBytes(bytes) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    bytes = Number(bytes) || 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    return (bytes < 10 ? bytes.toFixed(2) : bytes.toFixed(1)) + ' ' + units[i];
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
  }

  function flash(el) {
    if (!el) return;
    el.classList.add('mh-flash');
    setTimeout(function () { el.classList.remove('mh-flash'); }, 700);
  }

  function setConnected(ok) {
    if (!dot) return;
    if (ok) {
      dot.classList.remove('mh-dot-off');
      statusText.textContent = 'live';
    } else {
      dot.classList.add('mh-dot-off');
      statusText.textContent = 'terputus';
    }
  }

  function wireBarClicks() {
    document.querySelectorAll('#weekBars .mh-bar-clickable').forEach(function (col) {
      col.onclick = function () {
        var items = [];
        try { items = JSON.parse(col.getAttribute('data-items') || '[]'); } catch (e) { items = []; }
        openDayModal(col.getAttribute('data-label') || '', items);
      };
    });
    document.querySelectorAll('#yearBars .mh-bar-clickable').forEach(function (col) {
      col.onclick = function () {
        openMonthModal(
          col.getAttribute('data-label') || '',
          Number(col.getAttribute('data-total')) || 0,
          Number(col.getAttribute('data-count')) || 0
        );
      };
    });
  }

  function renderWeekBars(items) {
    var wrap = document.getElementById('weekBars');
    if (!wrap || !items || !items.length) return;
    var max = 1;
    items.forEach(function (d) { if (d.total > max) max = d.total; });
    wrap.innerHTML = items.map(function (d, i) {
      var pct = d.total > 0 ? Math.max(4, Math.round((d.total / max) * 100)) : 2;
      return '<div class="mh-bar-col mh-bar-clickable" data-index="' + i + '" data-label="' + esc(d.date_label || d.label) +
        '" data-items=\'' + JSON.stringify(d.items || []).replace(/'/g, '&#39;') + '\' title="' + formatRp(d.total) + '">' +
        '<div class="mh-bar-track"><div class="mh-bar-fill" style="height:' + pct + '%;"></div></div>' +
        '<span class="mh-bar-label">' + esc(d.label) + '</span></div>';
    }).join('');
    wireBarClicks();
  }

  function renderYearBars(months) {
    var wrap = document.getElementById('yearBars');
    if (!wrap || !months || !months.length) return;
    var max = 1;
    months.forEach(function (m) { if (m.total > max) max = m.total; });
    wrap.innerHTML = months.map(function (m) {
      var pct = m.total > 0 ? Math.max(4, Math.round((m.total / max) * 100)) : 2;
      return '<div class="mh-bar-col mh-bar-clickable mh-bar-month" data-label="' + esc(m.label) + '" data-total="' + m.total + '" data-count="' + m.count + '" title="' + formatRp(m.total) + '">' +
        '<div class="mh-bar-track"><div class="mh-bar-fill mh-bar-fill-alt" style="height:' + pct + '%;"></div></div>' +
        '<span class="mh-bar-label">' + esc(m.label) + '</span></div>';
    }).join('');
    wireBarClicks();
  }

  function renderRecent(rows) {
    var body = document.getElementById('recentBody');
    if (!body) return;
    if (!rows || !rows.length) {
      body.innerHTML = '<tr><td colspan="5" class="mh-empty">Belum ada transaksi bulan ini.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (r) {
      return '<tr><td>' + esc(r.date || '-') + '</td><td>' + esc(r.time) + '</td><td>' + esc(r.user) + '</td><td>' + esc(r.profile) +
        '</td><td class="mh-num">' + formatRp(r.price) + '</td></tr>';
    }).join('');
  }

  function renderGauge(bw) {
    var ring = document.getElementById('gaugeRing');
    if (!ring || !bw) return;

    var pct = Number(bw.percent) || 0;
    var clamped = Math.max(0, Math.min(100, pct));
    ring.setAttribute('stroke-dashoffset', (CIRCUMFERENCE * (1 - clamped / 100)).toFixed(2));

    ring.classList.remove('mh-gauge-ok', 'mh-gauge-warn', 'mh-gauge-over', 'mh-gauge-unset');
    if (!bw.quota_gb || bw.quota_gb <= 0) {
      ring.classList.add('mh-gauge-unset');
    } else if (pct >= 100) {
      ring.classList.add('mh-gauge-over');
    } else if (pct >= 80) {
      ring.classList.add('mh-gauge-warn');
    } else {
      ring.classList.add('mh-gauge-ok');
    }

    setText('gaugePercent', pct.toFixed(1).replace('.', ',') + '%');
    setText('usageBytes', bw.used_fmt);
    setText('usageQuota', bw.quota_gb > 0 ? bw.quota_fmt : '-');
    setText('bwDown', bw.rx_fmt);
    setText('bwUp', bw.tx_fmt);
    setText('bwTotal', bw.used_fmt);
    setText('bwPrev', bw.prev_cycle ? formatBytes(bw.prev_cycle.total) : '-');
    setText('bwUpdated', 'Diperbarui ' + (bw.last_update || '-'));
  }

  function renderUsage(usage) {
    var wrap = document.getElementById('usageList');
    if (!wrap || !usage || !usage.items || !usage.items.length) return;
    usage.items.forEach(function (it) {
      var row = wrap.querySelector('.mh-usage-row[data-key="' + it.key + '"]');
      if (!row) return;
      var fmtEl = row.querySelector('.mh-usage-fmt');
      var fillEl = row.querySelector('.mh-usage-fill');
      if (fmtEl) fmtEl.textContent = it.fmt;
      if (fillEl) fillEl.style.width = Math.max(2, it.percent) + '%';
    });
    setText('usageUpdated', 'Diperbarui ' + (usage.last_update || '-'));
  }

  function setText(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
  }

  var IP_GROUP_ORDER = [['ap', 'Access Point'], ['user', 'Pengguna (USER)'], ['other', 'Lainnya']];

  function renderIpBindings(list) {
    var wrap = document.getElementById('ipBindingList');
    if (!wrap || !list) return;
    var onlineCount = 0;
    list.forEach(function (b) { if (b.online) onlineCount++; });
    setText('ipBindingOnlineCount', onlineCount);
    if (!list.length) {
      wrap.innerHTML = '<div class="mh-gauge-empty"><p>Belum ada IP Binding, atau semua nonaktif.</p></div>';
      return;
    }
    var groups = { ap: [], user: [], other: [] };
    list.forEach(function (b) {
      var g = groups[b.group] ? b.group : 'other';
      groups[g].push(b);
    });
    var html = '';
    IP_GROUP_ORDER.forEach(function (pair) {
      var key = pair[0], label = pair[1];
      if (!groups[key].length) return;
      html += '<div class="mh-ipbinding-group"><div class="mh-ipbinding-group-title">' + esc(label) + '</div>';
      groups[key].forEach(function (b) {
        var dotClass = b.online ? 'mh-ip-dot-on' : 'mh-ip-dot-off';
        var sub = b.address || b.mac || '';
        html += '<div class="mh-ipbinding-row">' +
          '<span class="mh-ip-dot ' + dotClass + '" title="' + (b.online ? 'Online' : 'Offline') + '"></span>' +
          '<div class="mh-ipbinding-info"><span class="mh-ipbinding-name">' + esc(b.label || b.name) + '</span>' +
          '<span class="mh-ipbinding-sub">' + esc(sub) + '</span></div></div>';
      });
      html += '</div>';
    });
    wrap.innerHTML = html;
  }

  // ---- Modal rincian harian ----
  var dayModal = document.getElementById('dayModal');
  var dayModalTitle = document.getElementById('dayModalTitle');
  var dayModalBody = document.getElementById('dayModalBody');

  function openDayModal(label, items) {
    if (!dayModal) return;
    dayModalTitle.textContent = 'Rincian penjualan \u00b7 ' + label;
    var total = 0;
    items.forEach(function (it) { total += Number(it.price) || 0; });
    var rowsHtml = !items.length
      ? '<p class="mh-empty" style="padding:12px 0;">Tidak ada penjualan pada hari ini.</p>'
      : '<table class="mh-table"><thead><tr><th>Jam</th><th>Pengguna</th><th>Profil</th><th class="mh-num">Harga</th></tr></thead><tbody>' +
        items.map(function (it) {
          return '<tr><td>' + esc(it.time) + '</td><td>' + esc(it.user) + '</td><td>' + esc(it.profile) +
            '</td><td class="mh-num">' + formatRp(it.price) + '</td></tr>';
        }).join('') + '</tbody></table>';
    dayModalBody.innerHTML = '<div class="mh-modal-total">' + formatRp(total) + ' <span class="mh-hint">total ' + items.length + ' voucher</span></div>' + rowsHtml;
    dayModal.hidden = false;
  }

  // ---- Modal total bulanan ----
  var monthModal = document.getElementById('monthModal');
  var monthModalTitle = document.getElementById('monthModalTitle');
  var monthModalBody = document.getElementById('monthModalBody');

  function openMonthModal(label, total, count) {
    if (!monthModal) return;
    monthModalTitle.textContent = 'Total penjualan \u00b7 ' + label;
    monthModalBody.innerHTML = '<div class="mh-modal-total">' + formatRp(total) + '</div>' +
      '<p class="mh-hint">' + count + ' voucher terjual sepanjang bulan ' + esc(label) + '.</p>';
    monthModal.hidden = false;
  }

  function closeModals() {
    if (dayModal) dayModal.hidden = true;
    if (monthModal) monthModal.hidden = true;
    var hsModal = document.getElementById('hotspotActiveModal');
    var ppModal = document.getElementById('pppoeActiveModal');
    var vpModal = document.getElementById('voucherProfileModal');
    var genModal = document.getElementById('generateModal');
    var tplModal = document.getElementById('templateEditorModal');
    if (hsModal) hsModal.hidden = true;
    if (ppModal) ppModal.hidden = true;
    if (vpModal) vpModal.hidden = true;
    if (genModal) genModal.hidden = true;
    if (tplModal) tplModal.hidden = true;
  }

  document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
    btn.addEventListener('click', closeModals);
  });
  [dayModal, monthModal].forEach(function (m) {
    if (m) m.addEventListener('click', function (e) { if (e.target === m) closeModals(); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModals(); });
  wireBarClicks();

  // ---- Modal detail Hotspot aktif (klik kartu "Pengguna online") ----
  var hotspotActiveModal = document.getElementById('hotspotActiveModal');
  var hotspotActiveModalBody = document.getElementById('hotspotActiveModalBody');

  function renderHotspotActiveTable(list) {
    if (!list || !list.length) {
      return '<p class="mh-empty" style="padding:12px 0;">Tidak ada pengguna hotspot yang online saat ini.</p>';
    }
    return '<div class="mh-table-wrap"><table class="mh-table"><thead><tr>' +
      '<th>User</th><th>IP Address</th><th>Uptime</th><th>Sisa Sesi</th><th class="mh-num">Total Data</th>' +
      '</tr></thead><tbody>' + list.map(function (u) {
        return '<tr><td>' + esc(u.user) + '</td><td>' + esc(u.address) + '</td><td>' + esc(u.uptime) +
          '</td><td>' + esc(u.remaining) + '</td><td class="mh-num">' + esc(u.total) + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  function openHotspotActiveModal() {
    if (!hotspotActiveModal) return;
    hotspotActiveModal.hidden = false;
    hotspotActiveModalBody.innerHTML = '<p class="mh-hint">Memuat&hellip;</p>';
    fetch('api/vouchers.php?action=hotspot_active', { credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 401) { window.location.href = 'login.php'; throw new Error('unauth'); }
        return res.json();
      })
      .then(function (data) {
        if (!data.ok) {
          hotspotActiveModalBody.innerHTML = '<p class="mh-empty">' + esc(data.error || 'Gagal memuat data.') + '</p>';
          return;
        }
        hotspotActiveModalBody.innerHTML = renderHotspotActiveTable(data.list);
      })
      .catch(function () {
        hotspotActiveModalBody.innerHTML = '<p class="mh-empty">Gagal memuat data.</p>';
      });
  }

  var heroOnlineCard = document.getElementById('heroOnlineCard');
  if (heroOnlineCard) heroOnlineCard.addEventListener('click', openHotspotActiveModal);

  // ---- Modal detail PPPoE aktif (klik kartu "PPPoE aktif") ----
  var pppoeActiveModal = document.getElementById('pppoeActiveModal');
  var pppoeActiveModalBody = document.getElementById('pppoeActiveModalBody');

  function renderPppoeActiveTable(list) {
    if (!list || !list.length) {
      return '<p class="mh-empty" style="padding:12px 0;">Tidak ada sesi PPPoE yang aktif saat ini.</p>';
    }
    return '<div class="mh-table-wrap"><table class="mh-table"><thead><tr>' +
      '<th>Nama (Comment)</th><th>Username</th><th>IP Address</th><th>Uptime</th>' +
      '</tr></thead><tbody>' + list.map(function (p) {
        return '<tr><td>' + esc(p.name) + '</td><td>' + esc(p.user) + '</td><td>' + esc(p.address) +
          '</td><td>' + esc(p.uptime) + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  function openPppoeActiveModal() {
    if (!pppoeActiveModal) return;
    pppoeActiveModal.hidden = false;
    pppoeActiveModalBody.innerHTML = '<p class="mh-hint">Memuat&hellip;</p>';
    fetch('api/pppoe.php?action=active', { credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 401) { window.location.href = 'login.php'; throw new Error('unauth'); }
        return res.json();
      })
      .then(function (data) {
        if (!data.ok) {
          pppoeActiveModalBody.innerHTML = '<p class="mh-empty">' + esc(data.error || 'Gagal memuat data.') + '</p>';
          return;
        }
        pppoeActiveModalBody.innerHTML = renderPppoeActiveTable(data.list);
      })
      .catch(function () {
        pppoeActiveModalBody.innerHTML = '<p class="mh-empty">Gagal memuat data.</p>';
      });
  }

  var heroPppoeCard = document.getElementById('heroPppoeCard');
  if (heroPppoeCard) heroPppoeCard.addEventListener('click', openPppoeActiveModal);

  // Supaya tombol close/klik-luar/Escape yang sudah ada juga menutup modal baru ini
  [hotspotActiveModal, pppoeActiveModal].forEach(function (m) {
    if (m) m.addEventListener('click', function (e) { if (e.target === m) closeModals(); });
  });
  document.querySelectorAll('#hotspotActiveModal [data-close-modal], #pppoeActiveModal [data-close-modal]').forEach(function (btn) {
    btn.addEventListener('click', closeModals);
  });

  // ---- Jam berjalan di topbar (dari waktu router, tik lokal supaya ringan) ----
  var clockBase = null;
  var clockBaseAt = 0;
  function initClock() {
    var t = document.body.getAttribute('data-server-time');
    if (!t) return;
    var parts = t.split(':').map(Number);
    clockBase = parts[0] * 3600 + parts[1] * 60 + parts[2];
    clockBaseAt = Date.now();
  }
  function tickClock() {
    if (clockBase === null) return;
    var elapsed = Math.floor((Date.now() - clockBaseAt) / 1000);
    var total = (clockBase + elapsed) % 86400;
    var hh = String(Math.floor(total / 3600)).padStart(2, '0');
    var mm = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
    var ss = String(total % 60).padStart(2, '0');
    setText('infoClock', hh + ':' + mm + ':' + ss);
  }
  initClock();
  setInterval(tickClock, 1000);

  // ---- Polling RINGAN (online, cpu/uptime, bandwidth, jam) - tiap 15 detik ----
  function tickLight() {
    fetch('api/live.php', { credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 401) { window.location.href = 'login.php'; throw new Error('unauth'); }
        return res.json();
      })
      .then(function (data) {
        if (!data.ok) { setConnected(false); return; }
        setConnected(true);
        var s = data.summary;

        setText('statOnlineCount', s.online_count);
        setText('statPppoeCount', s.pppoe_active_count);
        renderGauge(s.bandwidth);
        renderIpBindings(s.ip_bindings);
        setText('infoCpu', s.resource ? s.resource.cpu : '-');
        setText('infoUptime', s.resource ? s.resource.uptime : '-');
        checkNewLogins(s.new_logins);
        if (s.server_time) {
          var p = s.server_time.split(':').map(Number);
          clockBase = p[0] * 3600 + p[1] * 60 + p[2];
          clockBaseAt = Date.now();
        }
        setText('lastUpdated', s.server_time);
      })
      .catch(function () { setConnected(false); });
  }

  // ---- Polling PENJUALAN (hari ini/bulan ini/7 hari/transaksi/grafik bulanan) - tiap 60 detik ----
  // ---- Suara "cring" koin masuk (disintesis sendiri pakai Web Audio API -
  // TANPA file .mp3/.wav eksternal, jadi tidak bisa gagal gara-gara jaringan) ----
  var mhAudioCtx = null;
  function mhUnlockAudio() {
    if (mhAudioCtx) return;
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (AC) mhAudioCtx = new AC();
    } catch (e) {}
  }
  // Browser modern butuh 1x interaksi user dulu sebelum boleh bunyi - kita
  // "buka kunci" AudioContext-nya diam-diam di interaksi pertama apa saja.
  ['click', 'touchstart', 'keydown'].forEach(function (evt) {
    document.addEventListener(evt, mhUnlockAudio, { once: true, passive: true });
  });

  function playCoinSound() {
    mhUnlockAudio();
    if (!mhAudioCtx) return;
    try {
      if (mhAudioCtx.state === 'suspended') mhAudioCtx.resume();
      var now = mhAudioCtx.currentTime;
      // Dua nada cepat naik (mirip "cring" koin arcade klasik).
      var notes = [{ freq: 1568, start: 0, dur: 0.09 }, { freq: 2093, start: 0.07, dur: 0.16 }];
      notes.forEach(function (n) {
        var osc = mhAudioCtx.createOscillator();
        var gain = mhAudioCtx.createGain();
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(n.freq, now + n.start);
        gain.gain.setValueAtTime(0.0001, now + n.start);
        gain.gain.exponentialRampToValueAtTime(0.22, now + n.start + 0.012);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + n.start + n.dur);
        osc.connect(gain);
        gain.connect(mhAudioCtx.destination);
        osc.start(now + n.start);
        osc.stop(now + n.start + n.dur + 0.02);
      });
    } catch (e) {}
  }

  // ---- Notifikasi melayang "voucher terjual" (pojok kanan bawah, desktop & mobile) ----
  var saleNotifStack = document.getElementById('voucherSaleNotifStack');
  function showSaleNotification(profileName, priceRp) {
    if (!saleNotifStack) return;
    var card = document.createElement('div');
    card.className = 'mh-sale-notif';
    card.innerHTML =
      '<div class="mh-sale-notif-body">' +
      '<div class="mh-sale-notif-title">' + esc(profileName || 'Voucher') + '</div>' +
      '<div class="mh-sale-notif-price">' + esc(priceRp) + '</div>' +
      '</div>' +
      '<button type="button" class="mh-sale-notif-close" aria-label="Tutup">&times;</button>';

    var timer = setTimeout(function () { dismiss(); }, 60000);
    function dismiss() {
      clearTimeout(timer);
      card.classList.add('mh-sale-notif-leaving');
      setTimeout(function () { if (card.parentNode) card.parentNode.removeChild(card); }, 250);
    }
    card.querySelector('.mh-sale-notif-close').addEventListener('click', dismiss);

    saleNotifStack.appendChild(card);
    playCoinSound();
  }

  // ---- Notifikasi "user voucher login" (muncul 5 detik + suara "cring") ----
  // Terpisah dari showSaleNotification() di atas: sale-notification hanya
  // untuk transaksi yang tercatat aplikasi Mikhmon terpisah (lihat
  // checkNewSales), sedangkan ini untuk SETIAP user hotspot yang login -
  // termasuk voucher yang dibuat lewat fitur Generate bawaan dashboard ini,
  // yang sengaja tidak tercatat sebagai "transaksi" (lihat
  // mh_detect_new_hotspot_logins di includes/functions.php).
  function showLoginNotification(user) {
    if (!saleNotifStack) return;
    var card = document.createElement('div');
    card.className = 'mh-sale-notif mh-login-notif';
    card.innerHTML =
      '<div class="mh-sale-notif-body">' +
      '<div class="mh-sale-notif-title">Voucher login</div>' +
      '<div class="mh-sale-notif-price">' + esc(user || '-') + '</div>' +
      '</div>' +
      '<button type="button" class="mh-sale-notif-close" aria-label="Tutup">&times;</button>';

    var timer = setTimeout(function () { dismiss(); }, 60000);
    function dismiss() {
      clearTimeout(timer);
      card.classList.add('mh-sale-notif-leaving');
      setTimeout(function () { if (card.parentNode) card.parentNode.removeChild(card); }, 250);
    }
    card.querySelector('.mh-sale-notif-close').addEventListener('click', dismiss);

    saleNotifStack.appendChild(card);
    playCoinSound();
  }

  function checkNewLogins(newLogins) {
    if (!newLogins || !newLogins.length) return;
    newLogins.forEach(function (row) { showLoginNotification(row.user); });
  }

  // Lacak transaksi yang sudah pernah "dilihat" supaya tidak menotifikasi ulang
  // transaksi lama tiap kali poll, dan supaya saat dashboard baru dibuka tidak
  // langsung membanjiri notifikasi untuk semua transaksi lama hari itu.
  var seenSaleKeys = null; // null = belum pernah poll sama sekali
  function saleKeyOf(r) {
    return [r.date || '', r.time || '', r.user || '', r.profile || '', r.price || ''].join('|');
  }
  function checkNewSales(recent) {
    if (!recent) return;
    var currentKeys = {};
    recent.forEach(function (r) { currentKeys[saleKeyOf(r)] = r; });

    if (seenSaleKeys === null) {
      // Poll pertama kali sejak halaman dibuka - catat saja, jangan menotifikasi.
      seenSaleKeys = currentKeys;
      return;
    }

    var newOnes = [];
    Object.keys(currentKeys).forEach(function (k) {
      if (!seenSaleKeys[k]) newOnes.push(currentKeys[k]);
    });
    // Urutkan dari yang paling lama ke paling baru supaya notif paling baru
    // muncul paling atas tumpukan (ditambahkan paling akhir).
    newOnes.reverse().forEach(function (r) {
      showSaleNotification(r.profile, formatRp(r.price));
    });
    seenSaleKeys = currentKeys;
  }

  function tickSales() {
    fetch('api/sales.php', { credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 401) { window.location.href = 'login.php'; throw new Error('unauth'); }
        return res.json();
      })
      .then(function (data) {
        if (!data.ok) return;
        var s = data.summary;

        var todayEl = document.getElementById('statTodayTotal');
        if (todayEl && todayEl.textContent !== formatRp(s.today_total)) {
          todayEl.textContent = formatRp(s.today_total);
          flash(todayEl.closest('.mh-stat'));
        }
        setText('statTodayCount', s.today_count);

        var monthEl = document.getElementById('statMonthTotal');
        if (monthEl && monthEl.textContent !== formatRp(s.month_total)) {
          monthEl.textContent = formatRp(s.month_total);
          flash(monthEl.closest('.mh-stat'));
        }
        setText('statMonthCount', s.month_count);
        setText('statMonthLabel', s.month_label);

        renderWeekBars(s.week);
        renderRecent(s.recent);
        checkNewSales(s.recent);
        if (s.yearly) renderYearBars(s.yearly.months);
        if (s.usage) renderUsage(s.usage);
      })
      .catch(function () {});
  }

  tickLight();
  tickSales();
  setInterval(tickLight, REFRESH_MS);
  setInterval(tickSales, SLOW_REFRESH_MS);

  // ---- Toggle tema terang/gelap ----
  (function themeInit() {
    var btn = document.getElementById('themeToggle');
    var meta = document.querySelector('meta[name="theme-color"]');
    var COLORS = { dark: '#0A1220', light: '#F1F4FA' };

    function apply(theme) {
      document.documentElement.setAttribute('data-theme', theme);
      if (meta) meta.setAttribute('content', COLORS[theme] || COLORS.dark);
      if (btn) btn.textContent = theme === 'light' ? '\u2600\uFE0F' : '\uD83C\uDF19';
    }

    var current = document.documentElement.getAttribute('data-theme') || 'dark';
    apply(current);

    if (btn) {
      btn.addEventListener('click', function () {
        current = current === 'light' ? 'dark' : 'light';
        try { localStorage.setItem('mh-theme', current); } catch (e) {}
        apply(current);
      });
    }
  })();

  // ---- Partikel latar belakang cyberpunk (canvas, ikut warna tema aktif) ----
  (function initParticles() {
    var canvas = document.getElementById('cyber-particles');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var particles = [];
    var particleCount = 150;


  function resize() {
    canvas.width = Math.max(window.innerWidth, document.documentElement.clientWidth);
    canvas.height = Math.max(
    document.body.scrollHeight,
    document.documentElement.scrollHeight,
    window.innerHeight
   );
   
   if (particles.length) {
    particles.forEach(function (p) { p.reset(); });
  }
}
window.addEventListener('resize', resize);
resize();

    function Particle() {
      this.reset();
    }

    Particle.prototype.reset = function () {
      var isLight = document.documentElement.getAttribute('data-theme') === 'light';
      this.x = Math.random() * canvas.width;
      this.y = Math.random() * canvas.height;
      this.size = Math.random() * 2 + 1;
      this.speedX = (Math.random() - 0.5) * 0.5;
      this.speedY = (Math.random() - 0.5) * 0.5;
      this.opacity = Math.random() * 0.6 + 0.3;

      // Warna gelap/kebiruan saat mode terang, dan warna neon saat mode gelap
      if (isLight) {
        this.color = Math.random() > 0.5 ? '0, 119, 182' : '15, 76, 129';
      } else {
        this.color = Math.random() > 0.5 ? '0, 229, 255' : '44, 240, 166';
      }
    };

    Particle.prototype.update = function () {
      this.x += this.speedX;
      this.y += this.speedY;
      if (this.x < 0 || this.x > canvas.width || this.y < 0 || this.y > canvas.height) {
        this.reset();
      }
    };

    Particle.prototype.draw = function () {
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(' + this.color + ', ' + this.opacity + ')';
      ctx.shadowBlur = 10;
      ctx.shadowColor = 'rgba(' + this.color + ', 0.8)';
      ctx.fill();
      ctx.shadowBlur = 0;
    };

    for (var i = 0; i < particleCount; i++) {
      particles.push(new Particle());
    }

    function animate() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      for (var a = 0; a < particles.length; a++) {
        for (var b = a + 1; b < particles.length; b++) {
          var dx = particles[a].x - particles[b].x;
          var dy = particles[a].y - particles[b].y;
          var dist = Math.sqrt(dx * dx + dy * dy);

          if (dist < 120) {
            ctx.beginPath();
            ctx.strokeStyle = 'rgba(0, 229, 255, ' + (0.2 - dist / 600) + ')';
            ctx.lineWidth = 0.6;
            ctx.moveTo(particles[a].x, particles[a].y);
            ctx.lineTo(particles[b].x, particles[b].y);
            ctx.stroke();
          }
        }
      }

      for (var p = 0; p < particles.length; p++) {
        particles[p].update();
        particles[p].draw();
      }

      requestAnimationFrame(animate);
    }

    animate();
  })();

  

  // ---- Traffic real-time (canvas mandiri - TANPA library luar / CDN) ----
  // CATATAN PENTING (kenapa diganti dari versi Chart.js sebelumnya): versi
  // sebelumnya memuat library dari CDN (cdnjs.cloudflare.com). Kalau
  // perangkat yang buka dashboard ini tidak bisa akses internet ke luar
  // (jaringan lokal ISP/hotspot yang dibatasi, firewall, dsb - situasi yang
  // sangat umum untuk dashboard billing seperti ini), library gagal dimuat
  // dan SELURUH kartu traffic (grafik + angka Download/Upload) diam-diam
  // berhenti bekerja tanpa pesan error. Versi ini digambar manual pakai
  // Canvas API bawaan browser - 100% lokal, tidak butuh internet sama
  // sekali, jadi dijamin selalu jalan. Kehalusannya (smooth) didapat dari
  // animasi requestAnimationFrame + kurva Catmull-Rom, bukan dari library.
  (function trafficWave() {
    var select = document.getElementById('trafficIface');
    var canvas = document.getElementById('trafficChart');
    if (!select || !canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');

    var POLL_MS = 2000;
    var MAX_POINTS = 30;
    var EASE = 0.22; // makin besar = makin cepat "mengejar" nilai baru

    var rxTarget = [], txTarget = [], rxDisplay = [], txDisplay = [];
    for (var i = 0; i < MAX_POINTS; i++) { rxTarget.push(0); txTarget.push(0); rxDisplay.push(0); txDisplay.push(0); }

    var dpr = Math.max(1, window.devicePixelRatio || 1);

    function resizeCanvas() {
      var rect = canvas.parentElement.getBoundingClientRect();
      var w = Math.max(1, Math.round(rect.width));
      var h = Math.max(1, Math.round(rect.height));
      canvas.width = w * dpr;
      canvas.height = h * dpr;
    }

    // Kurva halus lewat titik-titik (Catmull-Rom -> Bezier), bukan garis patah-patah.
    function smoothPath(pts) {
      ctx.moveTo(pts[0][0], pts[0][1]);
      for (var i = 0; i < pts.length - 1; i++) {
        var p0 = pts[i === 0 ? 0 : i - 1];
        var p1 = pts[i];
        var p2 = pts[i + 1];
        var p3 = pts[i + 2 < pts.length ? i + 2 : i + 1];
        var c1x = p1[0] + (p2[0] - p0[0]) / 6;
        var c1y = p1[1] + (p2[1] - p0[1]) / 6;
        var c2x = p2[0] - (p3[0] - p1[0]) / 6;
        var c2y = p2[1] - (p3[1] - p1[1]) / 6;
        ctx.bezierCurveTo(c1x, c1y, c2x, c2y, p2[0], p2[1]);
      }
    }

    function drawSeries(values, maxVal, w, h, padTop, padBottom, strokeColor, fillColor) {
      var stepX = w / (MAX_POINTS - 1);
      var pts = [];
      for (var i = 0; i < values.length; i++) {
        var y = h - padBottom - (maxVal > 0 ? (values[i] / maxVal) : 0) * (h - padTop - padBottom);
        pts.push([i * stepX, y]);
      }
      ctx.beginPath();
      smoothPath(pts);
      ctx.lineWidth = 2.5 * dpr;
      ctx.strokeStyle = strokeColor;
      ctx.lineJoin = 'round';
      ctx.lineCap = 'round';
      ctx.shadowColor = strokeColor;
      ctx.shadowBlur = 7 * dpr;
      ctx.stroke();
      ctx.lineTo(pts[pts.length - 1][0], h);
      ctx.lineTo(pts[0][0], h);
      ctx.closePath();
      ctx.shadowBlur = 0;
      ctx.fillStyle = fillColor;
      ctx.fill();
    }

    function draw() {
      var w = canvas.width, h = canvas.height;
      if (!w || !h) return;
      ctx.clearRect(0, 0, w, h);
      var maxVal = 1;
      for (var i = 0; i < MAX_POINTS; i++) {
        maxVal = Math.max(maxVal, rxDisplay[i], txDisplay[i]);
      }
      var padTop = 8 * dpr, padBottom = 8 * dpr;
      drawSeries(txDisplay, maxVal, w, h, padTop, padBottom, '#FF2E9F', 'rgba(255,46,159,0.14)');
      drawSeries(rxDisplay, maxVal, w, h, padTop, padBottom, '#00F0FF', 'rgba(0,240,255,0.16)');
    }

    var rafRunning = false;
    function animate() {
      var stillMoving = false;
      for (var i = 0; i < MAX_POINTS; i++) {
        var dr = rxTarget[i] - rxDisplay[i];
        var dt = txTarget[i] - txDisplay[i];
        if (Math.abs(dr) > 0.05) { rxDisplay[i] += dr * EASE; stillMoving = true; } else { rxDisplay[i] = rxTarget[i]; }
        if (Math.abs(dt) > 0.05) { txDisplay[i] += dt * EASE; stillMoving = true; } else { txDisplay[i] = txTarget[i]; }
      }
      draw();
      if (stillMoving) {
        requestAnimationFrame(animate);
      } else {
        rafRunning = false;
      }
    }
    function kickAnimate() {
      if (!rafRunning) { rafRunning = true; requestAnimationFrame(animate); }
    }

    function formatBpsParts(bps) {
      bps = Number(bps) || 0;
      var bits = bps;
      var units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
      var u = 0;
      while (bits >= 1000 && u < units.length - 1) { bits /= 1000; u++; }
      return { value: (bits < 10 ? bits.toFixed(2) : bits.toFixed(1)), unit: units[u] };
    }

    var timer = null;

    function poll() {
      if (document.hidden) return;
      var iface = select.value;
      if (!iface) return;
      fetch('api/traffic.php?iface=' + encodeURIComponent(iface), { credentials: 'same-origin' })
        .then(function (res) {
          if (res.status === 401) { window.location.href = 'login.php'; throw new Error('unauth'); }
          return res.json();
        })
        .then(function (data) {
          if (!data.ok) return;
          var rxMbps = (Number(data.rx_bps) || 0) / 1000000;
          var txMbps = (Number(data.tx_bps) || 0) / 1000000;

          rxTarget.push(rxMbps); rxTarget.shift();
          txTarget.push(txMbps); txTarget.shift();
          kickAnimate();

          var rxParts = formatBpsParts(data.rx_bps);
          var txParts = formatBpsParts(data.tx_bps);
          setText('trafficRxValue', rxParts.value);
          setText('trafficRxUnit', rxParts.unit);
          setText('trafficTxValue', txParts.value);
          setText('trafficTxUnit', txParts.unit);
        })
        .catch(function () {});
    }

    function restart() {
      for (var i = 0; i < MAX_POINTS; i++) { rxTarget[i] = 0; txTarget[i] = 0; rxDisplay[i] = 0; txDisplay[i] = 0; }
      resizeCanvas();
      draw();
      poll();
      if (timer) clearInterval(timer);
      timer = setInterval(poll, POLL_MS);
    }

    select.addEventListener('change', function () {
      try { localStorage.setItem('mh-traffic-iface', select.value); } catch (e) {}
      restart();
    });

    try {
      var saved = localStorage.getItem('mh-traffic-iface');
      if (saved && select.querySelector('option[value="' + CSS.escape(saved) + '"]')) {
        select.value = saved;
      }
    } catch (e) {}

    window.addEventListener('resize', function () { resizeCanvas(); draw(); });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });

    restart();
  })();

  // ---- Panel "Generate Hotspot & PPPoE" (fitur baru) ----
  (function generatePanel() {
    var panel = document.getElementById('generatePanel');
    if (!panel) return;

    var CSRF = window.MH_CSRF || '';
    var summaryGrid = document.getElementById('voucherSummaryGrid');
    var hotspotProfileSel = document.getElementById('genHotspotProfile');
    var hotspotServerSel = document.getElementById('genHotspotServer');
    var pppoeProfileSel = document.getElementById('genPppoeProfile');

    function fillSelect(sel, values, placeholder) {
      if (!sel) return;
      if (!values || !values.length) {
        sel.innerHTML = '<option value="">-- ' + esc(placeholder) + ' --</option>';
        return;
      }
      sel.innerHTML = values.map(function (v) {
        return '<option value="' + esc(v) + '">' + esc(v) + '</option>';
      }).join('');
    }

    function renderSummary(list) {
      if (!summaryGrid) return;
      if (!list || !list.length) {
        summaryGrid.innerHTML = '<p class="mh-hint">Belum ada voucher hotspot di router.</p>';
        return;
      }
      summaryGrid.innerHTML = list.map(function (it) {
        return '<div class="mh-voucher-summary-card mh-voucher-summary-card-clickable" data-profile="' + esc(it.profile) + '" title="Klik untuk lihat/hapus/cetak voucher profile ini">' +
          '<div class="mh-voucher-summary-profile">' + esc(it.profile) + '</div>' +
          '<div class="mh-voucher-summary-unused">' + esc(it.unused) + '</div>' +
          '<div class="mh-voucher-summary-sub">sisa &middot; ' + esc(it.used) + ' terpakai / ' + esc(it.total) + ' total</div>' +
          '</div>';
      }).join('');
    }

    function loadSummary() {
      if (summaryGrid) summaryGrid.innerHTML = '<p class="mh-hint">Memuat ringkasan sisa voucher&hellip;</p>';
      fetch('api/vouchers.php?action=summary', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) {
            if (summaryGrid) summaryGrid.innerHTML = '<p class="mh-empty">' + esc(data.error || 'Gagal memuat.') + '</p>';
            return;
          }
          renderSummary(data.summary);
          fillSelect(hotspotProfileSel, data.profiles, 'Tidak ada profile');
          fillSelect(hotspotServerSel, data.servers, 'all');
        })
        .catch(function () {
          if (summaryGrid) summaryGrid.innerHTML = '<p class="mh-empty">Gagal memuat ringkasan.</p>';
        });
    }

    function loadPppoeProfiles() {
      fetch('api/pppoe.php?action=profiles', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) return;
          fillSelect(pppoeProfileSel, data.profiles, 'Tidak ada profile PPPoE');
        })
        .catch(function () {});
    }

    // ---- Info profil voucher untuk cetak (nama hotspot/alamat/footer/logo) ----
    // Diambil dari Pengaturan > "Profil Voucher untuk Cetak". Endpoint ini TIDAK
    // connect ke router, jadi aman dipanggil kapan saja tanpa bikin lambat.
    var printProfileInfo = { hotspot_name: '', login_address: '', footer_note: '', logo_url: '' };
    function loadPrintProfile(cb) {
      fetch('api/voucher_templates.php?action=profile', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            printProfileInfo = { hotspot_name: data.hotspot_name, login_address: data.login_address, footer_note: data.footer_note, logo_url: data.logo_url };
          }
          if (cb) cb();
        })
        .catch(function () { if (cb) cb(); });
    }

    // Ganti semua {{token}} di HTML template dengan nilai dari `data`. Token yang
    // tidak dikenal diganti string kosong (bukan error) supaya template tetap aman dicetak.
    function renderTemplate(html, data) {
      return String(html).replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (m, key) {
        var v = data[key];
        return (v === undefined || v === null) ? '' : String(v);
      });
    }

    // Harga jual dipakai kalau diisi di profile (kolom ke-5 on-login Mikhmon),
    // kalau tidak ada baru pakai harga modal - persis prioritas print.php Mikhmon.
    function pickDisplayPrice(p) {
      if (!p) return 0;
      return p.selling_price ? p.selling_price : p.price;
    }

    // URL login hotspot buat isi QR (persis format Mikhmon: http://<dnsname>/login?username=...&password=...).
    function voucherLoginUrl(v) {
      var host = (printProfileInfo.login_address || '').trim();
      if (!host) return '';
      host = host.replace(/^https?:\/\//i, '').replace(/\/+$/, '');
      return 'http://' + host + '/login?username=' + encodeURIComponent(v.username || '') +
        '&password=' + encodeURIComponent(v.password || '');
    }

    // Kanvas QR - dirender belakangan oleh QRious di dalam window print (lihat
    // openPrintWindow), supaya tiap voucher dapat kode QR unik ke halaman
    // login hotspot-nya sendiri, persis fitur "Print QR" di Mikhmon.
    var mhQrSeq = 0;
    function buildQrCanvas(v, sizePx) {
      var url = voucherLoginUrl(v);
      if (!url) return '';
      mhQrSeq++;
      return '<canvas class="mh-qr-canvas" id="mhqr' + mhQrSeq + '" data-qr-value="' + esc(url) + '" width="' + sizePx + '" height="' + sizePx + '"></canvas>';
    }

    // Info Harga/Masa Aktif/Kunci User dari profile (gaya Mikhmon) - dipakai
    // saat cetak supaya nota voucher menampilkan field yang sama seperti
    // nota Mikhmon (lihat api/vouchers.php?action=list_by_profile).
    var currentProfilePricing = { validity: '', price: 0, selling_price: 0, lock_user: '' };

    function voucherTokenData(v) {
      var p = currentProfilePricing;
      var hargaVal = v.harga !== undefined ? v.harga : (p ? pickDisplayPrice(p) : 0);
      return {
        username: v.username || '', password: v.password || '', profile: v.profile || '',
        harga: hargaVal ? formatRp(hargaVal) : '', durasi: v.durasi || v.time_limit || '', kuota: v.data_limit || '',
        masa_aktif: v.masa_aktif || (p ? p.validity : '') || '', kunci_user: v.kunci_user || (p ? p.lock_user : '') || '',
        comment: v.comment || '', nomor: v.nomor || '',
        hotspotname: printProfileInfo.hotspot_name || '', logo: printProfileInfo.logo_url || '',
        alamat_login: printProfileInfo.login_address || '', catatan_kaki: printProfileInfo.footer_note || ''
      };
    }

    // ---- Kartu voucher standar (dipakai layout Default/Kupon/Small/Grid A4) ----
    function buildStandardCard(v) {
      var samePass = v.password && v.password === v.username;
      var t = voucherTokenData(v);
      var meta = [];
      if (t.masa_aktif) meta.push('Masa Aktif: ' + esc(t.masa_aktif));
      if (v.durasi || v.time_limit) meta.push('Durasi: ' + esc(v.durasi || v.time_limit));
      if (v.data_limit) meta.push('Kuota: ' + esc(v.data_limit));
      if (t.harga) meta.push('Harga: ' + esc(t.harga));
      var headBits = [];
      if (printProfileInfo.logo_url) headBits.push('<img class="vc-logo" src="' + esc(printProfileInfo.logo_url) + '" alt="">');
      headBits.push('<span class="vc-hotspot">' + esc(printProfileInfo.hotspot_name || v.profile || '') + '</span>');
      return '<div class="voucher-card">' +
        '<div class="voucher-card-head">' + headBits.join('') + '</div>' +
        (printProfileInfo.login_address ? '<div class="voucher-card-login">' + esc(printProfileInfo.login_address) + '</div>' : '') +
        '<div class="voucher-card-row"><span>User</span><b>' + esc(v.username) + '</b></div>' +
        (samePass ? '' : '<div class="voucher-card-row"><span>Pass</span><b>' + esc(v.password) + '</b></div>') +
        (v.withQr ? '<div class="voucher-card-qr">' + buildQrCanvas(v, 84) + '</div>' : '') +
        (meta.length ? '<div class="voucher-card-meta">' + meta.join(' &middot; ') + '</div>' : '') +
        (printProfileInfo.footer_note ? '<div class="voucher-card-footer">' + esc(printProfileInfo.footer_note) + '</div>' : '') +
        '</div>';
    }

    // ---- Struk voucher gaya thermal (satu kolom sempit, mirip struk kasir) ----
    function buildThermalReceipt(v) {
      var samePass = v.password && v.password === v.username;
      var t = voucherTokenData(v);
      return '<div class="thermal-receipt">' +
        (printProfileInfo.logo_url ? '<img class="thermal-logo" src="' + esc(printProfileInfo.logo_url) + '" alt="">' : '') +
        '<div class="thermal-hotspot">' + esc(printProfileInfo.hotspot_name || v.profile || '') + '</div>' +
        (printProfileInfo.login_address ? '<div class="thermal-login">' + esc(printProfileInfo.login_address) + '</div>' : '') +
        '<div class="thermal-sep">- - - - - - - - - - - - - - -</div>' +
        '<div class="thermal-row">User: <b>' + esc(v.username) + '</b></div>' +
        (samePass ? '' : '<div class="thermal-row">Pass: <b>' + esc(v.password) + '</b></div>') +
        (v.withQr ? '<div class="thermal-qr">' + buildQrCanvas(v, 96) + '</div>' : '') +
        (t.masa_aktif ? '<div class="thermal-row">Masa Aktif: ' + esc(t.masa_aktif) + '</div>' : '') +
        (v.durasi || v.time_limit ? '<div class="thermal-row">Durasi: ' + esc(v.durasi || v.time_limit) + '</div>' : '') +
        (v.data_limit ? '<div class="thermal-row">Kuota: ' + esc(v.data_limit) + '</div>' : '') +
        (t.harga ? '<div class="thermal-row">Harga: ' + esc(t.harga) + '</div>' : '') +
        (printProfileInfo.footer_note ? '<div class="thermal-footer">' + esc(printProfileInfo.footer_note) + '</div>' : '') +
        '<div class="thermal-cut">&#9986;- - - - - - - - - - - - - - -</div>' +
        '</div>';
    }

    var PRINT_LAYOUT_CSS = {
      default: '.sheet{display:flex;flex-wrap:wrap;gap:8px;} .voucher-card{width:190px;padding:12px 14px;}',
      kupon: '.sheet{display:flex;flex-wrap:wrap;gap:10px;} .voucher-card{width:210px;padding:14px 16px;border-style:dashed;border-width:2px;border-radius:10px;background:linear-gradient(180deg,#fafafa,#fff);}',
      small: '.sheet{display:flex;flex-wrap:wrap;gap:5px;} .voucher-card{width:130px;padding:7px 9px;font-size:11px;}',
      gridA4: '@page{size:A4;margin:10mm;} .sheet{display:grid;grid-template-columns:repeat(4,1fr);gap:6mm;} .voucher-card{width:auto;}'
    };

    function printStandardLayout(layout, title, items) {
      if (!items || !items.length) { window.alert('Tidak ada voucher untuk dicetak.'); return; }
      var cardsHtml = items.map(buildStandardCard).join('');
      var needsQr = items.some(function (v) { return v.withQr; });
      openPrintWindow(title, items.length,
        '.voucher-card{border:1px solid #444;border-radius:6px;box-sizing:border-box;}' +
        '.voucher-card-head{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#333;border-bottom:1px solid #ddd;padding-bottom:4px;margin-bottom:6px;}' +
        '.vc-logo{height:18px;width:auto;}' +
        '.voucher-card-login{font-size:10px;color:#888;margin-bottom:4px;}' +
        '.voucher-card-row{display:flex;justify-content:space-between;font-size:13px;margin:2px 0;}' +
        '.voucher-card-row span{color:#777;}' +
        '.voucher-card-row b{font-family:monospace;letter-spacing:.03em;}' +
        '.voucher-card-qr{text-align:center;margin:6px 0;}' +
        '.voucher-card-meta{font-size:10px;color:#666;margin-top:6px;border-top:1px dashed #ddd;padding-top:4px;}' +
        '.voucher-card-footer{font-size:9.5px;color:#999;margin-top:4px;font-style:italic;}' +
        (PRINT_LAYOUT_CSS[layout] || PRINT_LAYOUT_CSS.default) +
        '@media print{ .voucher-card{break-inside:avoid;} }',
        '<div class="sheet">' + cardsHtml + '</div>',
        needsQr
      );
    }

    function printThermalLayout(title, items) {
      if (!items || !items.length) { window.alert('Tidak ada voucher untuk dicetak.'); return; }
      var html = items.map(buildThermalReceipt).join('');
      var needsQr = items.some(function (v) { return v.withQr; });
      openPrintWindow(title, items.length,
        'body{max-width:76mm;margin:0 auto;font-family:"Courier New",monospace;}' +
        '.thermal-receipt{padding:8px 4px;text-align:center;}' +
        '.thermal-logo{max-height:34px;margin-bottom:4px;}' +
        '.thermal-hotspot{font-weight:700;font-size:13px;}' +
        '.thermal-login{font-size:10.5px;color:#444;}' +
        '.thermal-sep,.thermal-cut{font-size:11px;color:#555;margin:6px 0;}' +
        '.thermal-row{font-size:12px;text-align:left;padding-left:8px;}' +
        '.thermal-qr{text-align:center;margin:6px 0;}' +
        '.thermal-footer{font-size:10px;color:#555;margin-top:6px;font-style:italic;}' +
        '@media print{ .thermal-receipt{break-inside:avoid;} }',
        html,
        needsQr
      );
    }

    // QRious dimuat LOKAL (assets/vendor/qrious.min.js) - BUKAN dari CDN.
    // Alasan: dashboard billing seperti ini sering dipasang di jaringan lokal
    // ISP/hotspot yang aksesnya ke internet luar dibatasi/diblokir - CDN bisa
    // gagal dimuat tanpa pesan error yang jelas, dan fitur cetak QR jadi diam-
    // diam rusak (persis kejadian sebelumnya dengan Chart.js). File lokal
    // dijamin selalu ada karena ikut dalam paket ZIP dashboard ini sendiri.
    var QR_LIB_SRC = 'assets/vendor/qrious.min.js';

    function openPrintWindow(title, count, css, bodyHtml, needsQr) {
      var win = window.open('', '_blank');
      if (!win) {
        window.alert('Popup diblokir browser. Izinkan popup untuk mencetak voucher.');
        return;
      }
      var qrBlock = needsQr
        ? '<script src="' + QR_LIB_SRC + '"><' + '/script>' +
          '<script>window.addEventListener("load",function(){' +
          'document.querySelectorAll(".mh-qr-canvas").forEach(function(c){' +
          'try{new QRious({element:c,value:c.getAttribute("data-qr-value"),size:Number(c.getAttribute("width"))||96});}catch(e){}' +
          '});' +
          '});<' + '/script>'
        : '';
      win.document.write(
        '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title><style>' +
        'body{font-family:Arial,Helvetica,sans-serif;margin:16px;background:#fff;color:#111;}' +
        '.mh-qr-canvas{width:auto;height:auto;max-width:100%;}' +
        css +
        '</style></head><body>' +
        '<h3 style="margin:0 0 12px;">' + esc(title) + ' &middot; ' + count + ' voucher</h3>' +
        bodyHtml +
        qrBlock +
        '<script>window.onload=function(){setTimeout(function(){window.print();},' + (needsQr ? 500 : 200) + ');};<' + '/script>' +
        '</body></html>'
      );
      win.document.close();
    }

    function printCustomLayout(title, items, templateHtml) {
      if (!items || !items.length) { window.alert('Tidak ada voucher untuk dicetak.'); return; }
      if (!templateHtml || !templateHtml.trim()) { window.alert('Template masih kosong. Isi dulu di Template Editor.'); return; }
      var cardsHtml = items.map(function (v, i) {
        var tokenData = voucherTokenData(v);
        tokenData.nomor = String(i + 1);
        return '<div class="voucher-card-custom">' + renderTemplate(templateHtml, tokenData) + '</div>';
      }).join('');
      openPrintWindow(title, items.length,
        '.sheet{display:flex;flex-wrap:wrap;gap:8px;} .voucher-card-custom{break-inside:avoid;}',
        '<div class="sheet">' + cardsHtml + '</div>'
      );
    }

    function printVouchers(layout, title, items) {
      if (layout === 'thermal') { printThermalLayout(title, items); return; }
      if (layout === 'custom') { printCustomLayout(title, items, lastActiveTemplateHtml); return; }
      printStandardLayout(layout, title, items);
    }

    // ---- Modal isi voucher per profile (klik kartu ringkasan) ----
    var voucherProfileModal = document.getElementById('voucherProfileModal');
    var voucherProfileModalTitle = document.getElementById('voucherProfileModalTitle');
    var voucherProfileModalBody = document.getElementById('voucherProfileModalBody');
    var voucherSearchInput = document.getElementById('voucherSearchInput');
    var voucherCommentFilter = document.getElementById('voucherCommentFilter');
    var voucherCountEl = document.getElementById('voucherCount');
    var btnHapusComment = document.getElementById('btnHapusComment');
    var currentProfileList = [];
    var currentFilteredList = [];
    var currentProfileName = '';

    function renderProfileTable(list) {
      if (!voucherProfileModalBody) return;
      if (!list.length) {
        voucherProfileModalBody.innerHTML = '<p class="mh-empty">Tidak ada voucher yang cocok.</p>';
        return;
      }
      voucherProfileModalBody.innerHTML = '<div class="mh-table-wrap mh-table-scroll"><table class="mh-table"><thead><tr>' +
        '<th>Username</th><th>Password</th><th>Comment</th><th>Terpakai</th><th>Status</th><th></th>' +
        '</tr></thead><tbody>' + list.map(function (v) {
          var statusHtml = v.used ? '<span class="mh-voucher-status mh-voucher-status-used">Terpakai</span>' : '<span class="mh-voucher-status mh-voucher-status-fresh">Aktif</span>';
          return '<tr data-username="' + esc(v.name) + '">' +
            '<td>' + esc(v.name) + '</td><td>' + esc(v.password) + '</td><td>' + esc(v.comment) + '</td>' +
            '<td>' + esc(v.uptime) + '</td><td>' + statusHtml + '</td>' +
            '<td><button type="button" class="mh-btn-ghost mh-btn-ghost-danger mh-voucher-del-btn" data-username="' + esc(v.name) + '">Hapus</button></td>' +
            '</tr>';
        }).join('') + '</tbody></table></div>';
    }

    function populateCommentFilter(list) {
      if (!voucherCommentFilter) return;
      var counts = {};
      list.forEach(function (v) {
        var c = v.comment || '(tanpa comment)';
        counts[c] = (counts[c] || 0) + 1;
      });
      var comments = Object.keys(counts).sort();
      var prevValue = voucherCommentFilter.value;
      voucherCommentFilter.innerHTML = '<option value="">-- Semua Comment --</option>' +
        comments.map(function (c) {
          return '<option value="' + esc(c) + '">' + esc(c) + ' (' + counts[c] + ')</option>';
        }).join('');
      if (comments.indexOf(prevValue) !== -1) voucherCommentFilter.value = prevValue;
    }

    function applyVoucherFilter() {
      var q = (voucherSearchInput && voucherSearchInput.value || '').trim().toLowerCase();
      var commentSel = (voucherCommentFilter && voucherCommentFilter.value) || '';
      currentFilteredList = currentProfileList.filter(function (v) {
        if (q && v.name.toLowerCase().indexOf(q) === -1) return false;
        if (commentSel) {
          var c = v.comment || '(tanpa comment)';
          if (c !== commentSel) return false;
        }
        return true;
      });
      renderProfileTable(currentFilteredList);
      if (voucherCountEl) voucherCountEl.textContent = currentFilteredList.length + ' voucher';
    }

    function openVoucherProfileModal(profile) {
      if (!voucherProfileModal) return;
      currentProfileName = profile;
      if (voucherProfileModalTitle) voucherProfileModalTitle.textContent = 'Voucher \u2014 ' + profile;
      voucherProfileModal.hidden = false;
      voucherProfileModalBody.innerHTML = '<p class="mh-hint">Memuat&hellip;</p>';
      if (voucherSearchInput) voucherSearchInput.value = '';
      loadPrintProfile();
      fetch('api/vouchers.php?action=list_by_profile&profile=' + encodeURIComponent(profile), { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) {
            voucherProfileModalBody.innerHTML = '<p class="mh-empty">' + esc(data.error || 'Gagal memuat.') + '</p>';
            return;
          }
          currentProfileList = data.list || [];
          currentProfilePricing = data.pricing || { validity: '', price: 0, selling_price: 0, lock_user: '' };
          populateCommentFilter(currentProfileList);
          applyVoucherFilter();
        })
        .catch(function () {
          voucherProfileModalBody.innerHTML = '<p class="mh-empty">Gagal memuat data.</p>';
        });
    }

    if (summaryGrid) {
      summaryGrid.addEventListener('click', function (e) {
        var card = e.target.closest('.mh-voucher-summary-card-clickable');
        if (!card) return;
        openVoucherProfileModal(card.getAttribute('data-profile'));
      });
    }

    if (voucherSearchInput) voucherSearchInput.addEventListener('input', applyVoucherFilter);
    if (voucherCommentFilter) voucherCommentFilter.addEventListener('change', applyVoucherFilter);

    if (voucherProfileModalBody) {
      voucherProfileModalBody.addEventListener('click', function (e) {
        var btn = e.target.closest('.mh-voucher-del-btn');
        if (!btn) return;
        var username = btn.getAttribute('data-username');
        if (!window.confirm('Hapus voucher "' + username + '" dari router? Tindakan ini tidak bisa dibatalkan.')) return;
        btn.disabled = true;
        btn.textContent = 'Menghapus...';
        var fd = new FormData();
        fd.append('name', username);
        fd.append('csrf', CSRF);
        fetch('api/vouchers.php?action=delete', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.ok) {
              currentProfileList = currentProfileList.filter(function (v) { return v.name !== username; });
              populateCommentFilter(currentProfileList);
              applyVoucherFilter();
              loadSummary();
            } else {
              window.alert(data.error || 'Gagal menghapus voucher.');
              btn.disabled = false;
              btn.textContent = 'Hapus';
            }
          })
          .catch(function () {
            window.alert('Gagal terhubung ke server.');
            btn.disabled = false;
            btn.textContent = 'Hapus';
          });
      });
    }

    if (btnHapusComment) {
      btnHapusComment.addEventListener('click', function () {
        if (!currentFilteredList.length) { window.alert('Tidak ada voucher yang sedang tampil untuk dihapus.'); return; }
        if (!window.confirm('Hapus SEMUA ' + currentFilteredList.length + ' voucher yang sedang tampil di tabel (hasil filter saat ini)? Tindakan ini tidak bisa dibatalkan.')) return;
        btnHapusComment.disabled = true;
        btnHapusComment.textContent = 'Menghapus...';
        var fd = new FormData();
        currentFilteredList.forEach(function (v) { fd.append('names[]', v.name); });
        fd.append('csrf', CSRF);
        fetch('api/vouchers.php?action=delete_bulk', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.ok) {
              var deletedSet = {};
              (data.deleted || []).forEach(function (n) { deletedSet[n] = true; });
              currentProfileList = currentProfileList.filter(function (v) { return !deletedSet[v.name]; });
              populateCommentFilter(currentProfileList);
              applyVoucherFilter();
              loadSummary();
              if (data.failed && data.failed.length) {
                window.alert((data.deleted || []).length + ' voucher terhapus, ' + data.failed.length + ' gagal.');
              }
            } else {
              window.alert(data.error || 'Gagal menghapus voucher.');
            }
          })
          .catch(function () { window.alert('Gagal terhubung ke server.'); })
          .finally(function () {
            btnHapusComment.disabled = false;
            btnHapusComment.textContent = 'Hapus Comment';
          });
      });
    }

    document.querySelectorAll('#voucherActionsRow [data-print-layout]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var layout = btn.getAttribute('data-print-layout');
        var items = currentFilteredList.map(function (v) {
          return { username: v.name, password: v.password, profile: currentProfileName, durasi: v.limit_uptime, time_limit: v.limit_uptime, data_limit: v.limit_bytes, comment: v.comment };
        });
        if (layout === 'qr') {
          // Cetak QR = layout Default + kode QR ke halaman login hotspot per
          // voucher (persis "Print QR" di Mikhmon) - butuh Alamat Login diisi
          // dulu di Pengaturan > Profil Voucher untuk Cetak.
          if (!printProfileInfo.login_address) {
            window.alert('Isi dulu "Alamat Login" di Pengaturan > Profil Voucher untuk Cetak, supaya QR bisa diarahkan ke halaman login hotspot.');
            return;
          }
          items.forEach(function (v) { v.withQr = true; });
          printVouchers('default', 'Voucher ' + currentProfileName, items);
          return;
        }
        printVouchers(layout, 'Voucher ' + currentProfileName, items);
      });
    });

    var btnCustomTemplate = document.getElementById('btnCustomTemplate');
    if (btnCustomTemplate) btnCustomTemplate.addEventListener('click', function () { openTemplateEditor(); });

    if (voucherProfileModal) {
      voucherProfileModal.addEventListener('click', function (e) { if (e.target === voucherProfileModal) voucherProfileModal.hidden = true; });
    }
    var voucherProfileModalClose = document.getElementById('voucherProfileModalClose');
    if (voucherProfileModalClose) {
      voucherProfileModalClose.addEventListener('click', function () { voucherProfileModal.hidden = true; });
    }

    // ---- Template Editor (cetak voucher custom, gaya "Mulai dari" + preview) ----
    var templateEditorModal = document.getElementById('templateEditorModal');
    var tplTextarea = document.getElementById('tplTextarea');
    var tplSelectStart = document.getElementById('tplSelectStart');
    var tplPreviewFrame = document.getElementById('tplPreviewFrame');
    var tplVarList = document.getElementById('tplVarList');
    var savedTemplates = [];
    var lastActiveTemplateHtml = '';
    var tplPreviewTimer = null;

    var TPL_VARIABLES = [
      { key: 'logo', label: 'Logo' },
      { key: 'hotspotname', label: 'Hotspotname' },
      { key: 'username', label: 'Username' },
      { key: 'password', label: 'Password' },
      { key: 'durasi', label: 'Durasi (waktu login)' },
      { key: 'kuota', label: 'Kuota Data' },
      { key: 'harga', label: 'Harga' },
      { key: 'masa_aktif', label: 'Masa Aktif (Validity)' },
      { key: 'kunci_user', label: 'Kunci User (Lock User)' },
      { key: 'nomor', label: 'Nomor Voucher' },
      { key: 'alamat_login', label: 'Alamat Login' },
      { key: 'catatan_kaki', label: 'Catatan Kaki' },
      { key: 'comment', label: 'Comment' }
    ];

    function renderVarPanel() {
      if (!tplVarList) return;
      tplVarList.innerHTML = TPL_VARIABLES.map(function (v) {
        return '<div class="mh-tpl-var-item"><b>' + esc(v.label) + ' :</b><code>{{' + v.key + '}}</code></div>';
      }).join('');
    }

    function updateTplPreview() {
      if (!tplPreviewFrame) return;
      var sample = {
        username: '420zrc', password: '420zrc', profile: currentProfileName || '5-JAM',
        harga: 'Rp 5.000', durasi: '5 Jam', kuota: '1 GB', masa_aktif: '1 Minggu', kunci_user: 'Disable', comment: 'Batch contoh', nomor: '1',
        hotspotname: printProfileInfo.hotspot_name || 'Nama Hotspot Anda',
        logo: printProfileInfo.logo_url || '',
        alamat_login: printProfileInfo.login_address || 'wifi.contoh.net',
        catatan_kaki: printProfileInfo.footer_note || ''
      };
      var body = renderTemplate(tplTextarea.value, sample);
      tplPreviewFrame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial,Helvetica,sans-serif;margin:12px;color:#111;}img{max-width:100%;}</style></head><body>' + body + '</body></html>';
    }

    function scheduleTplPreview() {
      if (tplPreviewTimer) clearTimeout(tplPreviewTimer);
      tplPreviewTimer = setTimeout(updateTplPreview, 250);
    }

    function loadTemplateList() {
      fetch('api/voucher_templates.php?action=list_templates', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          savedTemplates = (data.ok && data.templates) || [];
          if (tplSelectStart) {
            var prevValue = tplSelectStart.value;
            tplSelectStart.innerHTML = '<option value="">-- pilih template --</option>' +
              savedTemplates.map(function (t) { return '<option value="' + esc(t.name) + '">' + esc(t.name) + '</option>'; }).join('');
            if (savedTemplates.some(function (t) { return t.name === prevValue; })) tplSelectStart.value = prevValue;
          }
        })
        .catch(function () {});
    }

    function openTemplateEditor() {
      if (!templateEditorModal) return;
      templateEditorModal.hidden = false;
      renderVarPanel();
      loadPrintProfile(updateTplPreview);
      loadTemplateList();
      if (tplTextarea && !tplTextarea.value) {
        updateTplPreview();
      } else {
        updateTplPreview();
      }
    }

    if (tplTextarea) tplTextarea.addEventListener('input', function () { lastActiveTemplateHtml = tplTextarea.value; scheduleTplPreview(); });

    if (tplSelectStart) {
      tplSelectStart.addEventListener('change', function () {
        var name = tplSelectStart.value;
        var found = savedTemplates.filter(function (t) { return t.name === name; })[0];
        tplTextarea.value = found ? found.html : '';
        lastActiveTemplateHtml = tplTextarea.value;
        updateTplPreview();
      });
    }

    var tplBtnPrint = document.getElementById('tplBtnPrint');
    if (tplBtnPrint) {
      tplBtnPrint.addEventListener('click', function () {
        if (!currentFilteredList.length) {
          window.alert('Tidak ada voucher yang sedang tampil di tabel popup Voucher untuk dicetak. Buka dulu popup Voucher (klik kartu profile di ringkasan), lalu klik "Custom" lagi dari situ.');
          return;
        }
        var items = currentFilteredList.map(function (v) {
          return { username: v.name, password: v.password, profile: currentProfileName, durasi: v.limit_uptime, time_limit: v.limit_uptime, data_limit: v.limit_bytes, comment: v.comment };
        });
        printCustomLayout('Voucher ' + currentProfileName, items, tplTextarea.value);
      });
    }

    var tplBtnLogo = document.getElementById('tplBtnLogo');
    if (tplBtnLogo) {
      tplBtnLogo.addEventListener('click', function () {
        if (!tplTextarea) return;
        var pos = tplTextarea.selectionStart || tplTextarea.value.length;
        var token = '{{logo}}';
        tplTextarea.value = tplTextarea.value.slice(0, pos) + token + tplTextarea.value.slice(pos);
        tplTextarea.focus();
        tplTextarea.selectionStart = tplTextarea.selectionEnd = pos + token.length;
        lastActiveTemplateHtml = tplTextarea.value;
        updateTplPreview();
      });
    }

    var tplBtnReset = document.getElementById('tplBtnReset');
    if (tplBtnReset) {
      tplBtnReset.addEventListener('click', function () {
        if (!window.confirm('Kosongkan editor? Perubahan yang belum disimpan akan hilang.')) return;
        tplTextarea.value = '';
        if (tplSelectStart) tplSelectStart.value = '';
        lastActiveTemplateHtml = '';
        updateTplPreview();
      });
    }

    var tplBtnSave = document.getElementById('tplBtnSave');
    if (tplBtnSave) {
      tplBtnSave.addEventListener('click', function () {
        if (!tplTextarea.value.trim()) { window.alert('Editor masih kosong.'); return; }
        var defaultName = tplSelectStart && tplSelectStart.value ? tplSelectStart.value : '';
        var name = window.prompt('Nama template:', defaultName);
        if (name === null) return;
        name = name.trim();
        if (!name) { window.alert('Nama template wajib diisi.'); return; }
        var fd = new FormData();
        fd.append('name', name);
        fd.append('html', tplTextarea.value);
        fd.append('csrf', CSRF);
        fetch('api/voucher_templates.php?action=save_template', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.ok) {
              window.alert('Template "' + name + '" tersimpan.');
              loadTemplateList();
            } else {
              window.alert(data.error || 'Gagal menyimpan template.');
            }
          })
          .catch(function () { window.alert('Gagal terhubung ke server.'); });
      });
    }

    var tplBtnDelete = document.getElementById('tplBtnDelete');
    if (tplBtnDelete) {
      tplBtnDelete.addEventListener('click', function () {
        var name = tplSelectStart && tplSelectStart.value;
        if (!name) { window.alert('Pilih dulu template yang mau dihapus dari daftar "Mulai dari".'); return; }
        if (!window.confirm('Hapus template "' + name + '"? Tindakan ini tidak bisa dibatalkan.')) return;
        var fd = new FormData();
        fd.append('name', name);
        fd.append('csrf', CSRF);
        fetch('api/voucher_templates.php?action=delete_template', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.ok) {
              tplTextarea.value = '';
              lastActiveTemplateHtml = '';
              updateTplPreview();
              loadTemplateList();
            } else {
              window.alert(data.error || 'Gagal menghapus template.');
            }
          })
          .catch(function () { window.alert('Gagal terhubung ke server.'); });
      });
    }

    if (templateEditorModal) {
      templateEditorModal.addEventListener('click', function (e) { if (e.target === templateEditorModal) templateEditorModal.hidden = true; });
    }
    var templateEditorModalClose = document.getElementById('templateEditorModalClose');
    if (templateEditorModalClose) {
      templateEditorModalClose.addEventListener('click', function () { templateEditorModal.hidden = true; });
    }

    // ---- Tab switching ----
    var tabButtons = panel.querySelectorAll('[data-gen-tab]');
    var tabPanels = { hotspot: document.getElementById('genTabHotspot'), pppoe: document.getElementById('genTabPppoe') };
    tabButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        tabButtons.forEach(function (b) { b.classList.remove('mh-gen-tab-active'); });
        btn.classList.add('mh-gen-tab-active');
        var target = btn.getAttribute('data-gen-tab');
        Object.keys(tabPanels).forEach(function (k) {
          if (tabPanels[k]) tabPanels[k].hidden = (k !== target);
        });
      });
    });

    var refreshBtn = document.getElementById('btnRefreshGenerate');
    if (refreshBtn) refreshBtn.addEventListener('click', function () { loadSummary(); loadPppoeProfiles(); });

    var btnTestSaleNotif = document.getElementById('btnTestSaleNotif');
    if (btnTestSaleNotif) {
      btnTestSaleNotif.addEventListener('click', function () {
        showSaleNotification('Tes Notifikasi', formatRp(5000));
        setTimeout(function () { showLoginNotification('tes-user-123'); }, 700);
      });
    }

    // ---- Generate Voucher Hotspot ----
    var hotspotForm = document.getElementById('genHotspotForm');
    var hotspotAlert = document.getElementById('genHotspotAlert');
    var hotspotResult = document.getElementById('genHotspotResult');
    var btnGenHotspot = document.getElementById('btnGenHotspot');

    function showAlert(el, ok, text) {
      if (!el) return;
      el.className = 'mh-alert-box ' + (ok ? 'mh-alert-box-ok' : 'mh-alert-box-error');
      el.textContent = text;
      el.classList.remove('hidden');
    }

    var hotspotResultTimer = null;
    var lastGeneratedVouchers = [];

    if (hotspotForm) {
      hotspotForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(hotspotForm);
        fd.append('csrf', CSRF);
        if (btnGenHotspot) { btnGenHotspot.disabled = true; btnGenHotspot.textContent = 'Memproses...'; }
        if (hotspotAlert) hotspotAlert.classList.add('hidden');
        if (hotspotResult) { hotspotResult.classList.add('hidden'); hotspotResult.innerHTML = ''; }
        if (hotspotResultTimer) { clearTimeout(hotspotResultTimer); hotspotResultTimer = null; }

        fetch('api/vouchers.php?action=generate', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            var createdCount = (data.created || []).length;
            if (createdCount > 0) {
              var msg = createdCount + ' voucher berhasil dibuat &middot; masuk ke profile "' + esc((data.created[0] || {}).profile || '') + '".';
              if (data.failed && data.failed.length) msg += ' (' + data.failed.length + ' gagal)';
              showAlert(hotspotAlert, true, msg);
              lastGeneratedVouchers = data.created;
              if (hotspotResult) {
                hotspotResult.classList.remove('hidden');
                hotspotResult.innerHTML = '<div class="mh-gen-result-head">' +
                  '<span class="mh-hint">Daftar ini otomatis hilang dalam 10 detik &middot; catat/cetak dulu.</span>' +
                  '<button type="button" class="mh-btn-ghost" id="btnPrintGenerated">Cetak</button></div>' +
                  '<div class="mh-table-wrap mh-table-scroll"><table class="mh-table"><thead><tr>' +
                  '<th>Username</th><th>Password</th><th>Profile</th><th>Comment</th></tr></thead><tbody>' +
                  data.created.map(function (v) {
                    return '<tr><td>' + esc(v.name) + '</td><td>' + esc(v.password) + '</td><td>' + esc(v.profile) + '</td><td>' + esc(v.comment) + '</td></tr>';
                  }).join('') + '</tbody></table></div>';
                var printBtn = document.getElementById('btnPrintGenerated');
                if (printBtn) {
                  printBtn.addEventListener('click', function () {
                    printVouchers('default', 'Voucher Baru', lastGeneratedVouchers.map(function (v) {
                      return { username: v.name, password: v.password, profile: v.profile, time_limit: v.time_limit, data_limit: v.data_limit };
                    }));
                  });
                }
                hotspotResultTimer = setTimeout(function () {
                  hotspotResult.classList.add('hidden');
                  hotspotResult.innerHTML = '';
                }, 10000);
              }
              loadSummary();
            } else {
              showAlert(hotspotAlert, false, (data.failed && data.failed[0]) || data.error || 'Gagal membuat voucher.');
            }
          })
          .catch(function () {
            showAlert(hotspotAlert, false, 'Gagal terhubung ke server.');
          })
          .finally(function () {
            if (btnGenHotspot) { btnGenHotspot.disabled = false; btnGenHotspot.textContent = 'Generate Voucher'; }
          });
      });
    }

    // ---- Buat Akun PPPoE ----
    var pppoeForm = document.getElementById('genPppoeForm');
    var pppoeAlert = document.getElementById('genPppoeAlert');
    var btnGenPppoe = document.getElementById('btnGenPppoe');
    var btnPppoeRandom = document.getElementById('btnPppoeRandom');

    if (btnPppoeRandom) {
      btnPppoeRandom.addEventListener('click', function () {
        var code = Math.random().toString(36).slice(2, 8);
        var userEl = document.getElementById('pppoeUsername');
        var passEl = document.getElementById('pppoePassword');
        if (userEl) userEl.value = code;
        if (passEl) passEl.value = Math.random().toString(36).slice(2, 8);
      });
    }

    if (pppoeForm) {
      pppoeForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(pppoeForm);
        fd.append('csrf', CSRF);
        if (btnGenPppoe) { btnGenPppoe.disabled = true; btnGenPppoe.textContent = 'Memproses...'; }
        if (pppoeAlert) pppoeAlert.classList.add('hidden');

        fetch('api/pppoe.php?action=create', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.ok) {
              showAlert(pppoeAlert, true, 'Akun PPPoE "' + data.data.username + '" berhasil dibuat.');
              pppoeForm.reset();
            } else {
              showAlert(pppoeAlert, false, data.error || 'Gagal membuat akun PPPoE.');
            }
          })
          .catch(function () {
            showAlert(pppoeAlert, false, 'Gagal terhubung ke server.');
          })
          .finally(function () {
            if (btnGenPppoe) { btnGenPppoe.disabled = false; btnGenPppoe.textContent = 'Buat Akun PPPoE'; }
          });
      });
    }

    // ---- Modal "Generate Hotspot & PPPoE" (dibuka lewat tombol, bukan tampil terus) ----
    var generateModal = document.getElementById('generateModal');
    var btnOpenGenerate = document.getElementById('btnOpenGenerate');

    function openGenerateModal() {
      if (!generateModal) return;
      generateModal.hidden = false;
      loadSummary();
      loadPppoeProfiles();
    }

    if (btnOpenGenerate) btnOpenGenerate.addEventListener('click', openGenerateModal);
    if (generateModal) {
      generateModal.addEventListener('click', function (e) { if (e.target === generateModal) closeModals(); });
    }
  })();
})();

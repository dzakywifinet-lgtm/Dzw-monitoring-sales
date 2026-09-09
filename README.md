<img width="1600" height="1582" alt="fullpage_snapshot_dzw_my_id_2026-09-09-14-49-21" src="https://github.com/user-attachments/assets/e1a365f2-2871-4e68-b961-d78ea4d86ece" />
# Dashboard Penghasilan MikroTik

Dashboard web untuk memantau penjualan voucher Hotspot/PPPoE MikroTik secara real-time: transaksi, pengguna online, bandwidth, kuota FUP, hingga notifikasi Telegram — tanpa perlu buka Winbox.

## Fitur

- **Login aman** — masuk pakai IP, username, password API MikroTik. Koneksi (ping/TCP) dites dulu sebelum login API, supaya pesan error lebih jelas.
- **Dashboard utama**
  - Ringkasan penjualan hari ini & bulan ini, jumlah pengguna online.
  - Grafik 7 hari terakhir (klik untuk lihat rincian transaksi harian).
  - Grafik bulanan (klik untuk lihat total sebulan).
  - Bandwidth live & gauge kuota FUP.
  - Tabel transaksi terbaru.
  - Auto-refresh: data ringan tiap 15 detik, grafik/penjualan tiap 60 detik.
- **Generate Voucher** — buat voucher Hotspot & user PPPoE langsung dari dashboard, dengan pola username/password bergaya Mikhmon.
- **Cetak voucher** — beberapa layout nota (Default, Kupon, Small, Thermal, Grid A4, Custom via Template Editor), lengkap dengan harga, masa aktif, dan lock user. Termasuk cetak kode QR ke halaman login hotspot (perlu Alamat Login diisi dulu di Pengaturan).
- **Notifikasi**
  - Popup + suara di dashboard untuk voucher baru terjual maupun user baru login ke hotspot.
  - Notifikasi Telegram opsional untuk setiap voucher baru terjual (dengan pencegahan notifikasi ganda).
  - Tombol "Tes Notifikasi" untuk memastikan suara/popup berfungsi.
- **Analisis pemakaian** — kartu pemecahan trafik ke kategori Video, Layanan Google, Komunikasi, dan Web & lainnya (butuh script `usage-kategori-mikrotik.rsc` terpasang di router, khusus RouterOS 6.x).
- **Mode Cron** — jalankan pengecekan penjualan, bandwidth, dan notifikasi Telegram lewat crontab server, tanpa butuh sesi browser tetap terbuka. Endpoint `cron.php` dilindungi token rahasia dan kredensial router disimpan terenkripsi (AES-256-CBC).
- **PWA (Progressive Web App)** — bisa di-install sebagai aplikasi (Add to Home Screen) di Android maupun iOS, aset statis tetap cepat dimuat walau koneksi jelek.
- **Penyimpanan lokal anti-corrupt** — semua data (`data/*.json`) ditulis secara atomik dengan cadangan `.bak`.

## Struktur Proyek

```
dashboard/
├── login.php                 Halaman login
├── index.php                 Dashboard utama
├── settings.php              Pengaturan kuota FUP, Telegram, dan Mode Cron
├── cron.php                  Endpoint untuk dipanggil dari crontab server
├── logout.php                Keluar dari sesi
├── service-worker.js         Service worker untuk PWA
├── usage-kategori-mikrotik.rsc  Script RouterOS untuk fitur Analisis Pemakaian
├── api/
│   ├── live.php               Data ringan: online, CPU/uptime, traffic (poll 15 detik)
│   ├── sales.php               Data penjualan & grafik bulanan (poll 60 detik)
│   ├── pppoe.php                Data & aksi terkait user PPPoE
│   ├── traffic.php              Data trafik/bandwidth interface
│   ├── voucher_templates.php    CRUD template cetak voucher
│   └── vouchers.php             Data & aksi terkait voucher Hotspot
├── includes/
│   ├── auth.php                Autentikasi & sesi
│   ├── functions.php           Logika inti: koneksi router, olah data, cache
│   ├── storage.php             Penyimpanan lokal (baca/tulis JSON secara atomik)
│   └── version.php             Nomor versi aset (cache busting)
├── lib/
│   └── routeros_api.class.php  Class RouterOS API PHP (dari Mikhmon)
├── assets/
│   ├── app.js                  Logika front-end (dashboard, grafik, notifikasi)
│   ├── style.css                Tema & tampilan
│   ├── manifest.json            Manifest PWA
│   ├── icons/                   Ikon aplikasi (PWA)
│   ├── uploads/                 Upload (mis. logo) dari Pengaturan
│   └── vendor/                  Library JS pihak ketiga (disimpan lokal, bukan CDN)
└── data/                      Penyimpanan lokal pengaturan & data pemakaian (dilindungi .htaccess)
```

## 📋 Minimum Requirements

| Komponen | Spesifikasi Minimum | Keterangan |
| :--- | :--- | :--- |
| **RouterBoard** | MikroTik License Level 4 | Mendukung fitur API |
| **CPU Router** | Clock Speed 650 MHz | Untuk kelancaran penarikan data harian |
| **RouterOS** | v6.3x.x s/d v7.x | Kompatibel dengan skrip `.rsc` pemakaian |
| **Web Server** | Apache / NGINX / uHTTPd | Bisa di PC, STB, SBC, atau OpenWRT |
| **Versi PHP** | PHP 7.2 s/d PHP 8.x | Membutuhkan ekstensi `session`, `curl`, & `openssl` |

## 🚀 Instalasi

1. Salin seluruh isi folder ini ke direktori web server Anda (mis. `www/report` atau `www/Dzw-monitoring-sales`).
2. Pastikan PHP 7.2+ aktif dan folder `data/` bisa ditulis (*writable*) oleh PHP.
3. Buka `http://<IP-SERVER-ANDA>/Dzw-monitoring-sales` — Anda akan diarahkan ke halaman login.
4. Isi IP, username, dan password API MikroTik. Pastikan layanan API aktif di router:  
   `IP > Services > api` (port 8728, atau `api-ssl` 8729 jika pakai SSL).
5. Setelah masuk, buka halaman **Pengaturan** untuk mengisi:
   - Total Kuota FUP (GB)
   - Interface WAN yang dipantau (nama persis seperti di MikroTik, mis. `ether1`)
   - Tanggal reset bulanan (mengikuti tanggal reset FUP dari ISP)
   - *(Opsional)* Bot Token & Chat ID Telegram untuk notifikasi
6. Jika ingin menyamakan kuota dengan angka resmi ISP, gunakan fitur "Sinkronisasi Pemakaian Saat Ini" di halaman Pengaturan.



### Fitur Analisis Pemakaian (opsional)

Untuk mengaktifkan pemecahan kategori trafik, import `usage-kategori-mikrotik.rsc` ke router lewat *New Terminal* (`/import usage-kategori-mikrotik.rsc`). Script ini dibuat untuk RouterOS 6.x dan mensyaratkan client menggunakan router ini sebagai DNS. Tanpa script ini, kartu Analisis Pemakaian akan kosong.

### Instal sebagai Aplikasi (PWA)

- **Android/Chrome**: buka dashboard, lalu pilih "Instal aplikasi" dari address bar atau menu (⋮) > "Add to Home screen".
- **iPhone/Safari**: buka dashboard, tekan tombol Share, lalu pilih "Add to Home Screen".

## Setup Awal (Checklist)

Urutan dari nol sampai semua fitur (termasuk Analisis Pemakaian) jalan:

1. Deploy semua file ke web server, pastikan folder `data/` bisa ditulis (writable) oleh PHP.
2. Buka dashboard di browser, login pakai IP + username + password API MikroTik.
3. Buka halaman **Pengaturan**, isi:
   - Interface WAN yang Dipantau (nama persis seperti di MikroTik)
   - Total Kuota FUP (GB)
   - Tanggal reset bulanan
   - (Opsional) Bot Token & Chat ID Telegram
4. **(Opsional, kalau mau kartu Analisis Pemakaian aktif)** Buka Winbox/WebFig ke router → New Terminal → jalankan `/import usage-kategori-mikrotik.rsc`. Pastikan client hotspot memakai router ini sebagai DNS server (`/ip dns` → `allow-remote-requests=yes`, dan client tidak pakai DNS custom/DNS-over-HTTPS sendiri).
5. **(Opsional)** Aktifkan Mode Cron di Pengaturan kalau mau data tetap update meski dashboard tidak dibuka terus — salin perintah cron yang ditampilkan ke crontab server.
6. Tunggu beberapa menit agar data mulai terisi (bandwidth, kategori pemakaian butuh beberapa siklus poll dulu).

## Mode Cron

Mode Cron memungkinkan pengecekan bandwidth, kuota, kategori pemakaian, dan notifikasi Telegram tetap berjalan tanpa dashboard dibuka di browser.

1. Aktifkan Mode Cron di halaman Pengaturan.
2. Salin perintah cron yang ditampilkan ke crontab server (interval default `*/2`, bisa disesuaikan).
3. Gunakan tombol "Buat Token Baru" jika token pernah bocor/ter-share.
4. Gunakan tombol "Nonaktifkan & Hapus Kredensial" untuk menghapus kredensial tersimpan kapan saja.

## Keamanan

- Password router hanya disimpan di sesi PHP server dan hilang saat logout, kecuali Mode Cron diaktifkan (kredensial disimpan terenkripsi AES-256-CBC di `data/cron_creds.json`).
- Endpoint `cron.php` diamankan dengan token rahasia di URL, bukan password router.
- Sangat disarankan memasang dashboard ini di belakang HTTPS.
- Batasi akses API MikroTik hanya dari IP server web Anda (`Services > api > Available From`).
- Gunakan akun MikroTik terbatas (bukan admin utama) untuk dashboard ini bila memungkinkan.


## Catatan Akurasi Data Pemakaian

MikroTik tidak menyimpan riwayat bulanan secara otomatis. Dashboard ini mencatatnya sendiri di folder `data/` dengan membandingkan counter interface setiap kali dashboard dibuka/dipoll. Agar akurat:

- Biarkan dashboard sesekali terbuka, atau
- Jadwalkan cron memanggil `api/live.php` secara berkala (butuh cookie sesi hasil login yang masih valid):
  ```
  */10 * * * * curl -s -b cookie.txt https://<ip-server>/<domain-anda>/report/api/live.php > /dev/null
  ```
- Counter interface WAN yang reset ke 0 setelah router reboot sudah ditangani secara aman (delta negatif dianggap reset, bukan dikurangi).

## FAQ Singkat

**Kartu Analisis Pemakaian kosong / semua masuk "Web & lainnya"?**
Berarti script `.rsc` belum di-import ke router, atau client hotspot tidak pakai router ini sebagai DNS server. Cek `/ip dns` di MikroTik dan pastikan HP/laptop client tidak pakai DNS custom atau DNS-over-HTTPS di browser.

**Kuota FUP di dashboard beda dengan catatan ISP?**
MikroTik tidak menyimpan histori bulanan otomatis — dashboard menghitung sendiri sejak pertama kali dipantau. Pakai fitur "Sinkronisasi Pemakaian Saat Ini" di Pengaturan untuk menyamakan ke angka resmi ISP.

**Setelah clone ulang dari GitHub, kok semua data kosong?**
Normal — folder `data/` sengaja tidak ikut ter-commit (lihat `.gitignore`) karena isinya kredensial & data transaksi. Isi ulang Pengaturan; data lain (bandwidth, kategori pemakaian) akan terisi otomatis seiring waktu.

**Notifikasi Telegram tidak muncul?**
Cek Bot Token & Chat ID di Pengaturan sudah benar, dan bot sudah pernah di-`/start` dari akun Telegram yang Chat ID-nya dipakai.


## Kustomisasi

- **Interval poll**: ubah `REFRESH_MS` / `SLOW_REFRESH_MS` di `assets/app.js`.
- **Lama cache data voucher**: ubah `$ttl` pada `mh_fetch_all_vouchers_cached()` di `includes/functions.php` (default 55 detik).
- **Warna & tampilan**: `assets/style.css` — variabel warna `--ink`/`--panel`/`--blue`/`--green`/`--yellow`/`--purple` di bagian atas file. Font judul (Nunito) & font angka (JetBrains Mono) diatur lewat `@import` di baris pertama file yang sama.
- **Interval cron**: sesuaikan jadwal (mis. `*/5` untuk tiap 5 menit) sebelum ditempel ke crontab.
- **Ikon PWA**: ganti file di `assets/icons/` (ukuran sama), lalu perbarui `assets/manifest.json` bila perlu.

## References

* [MIKHMON v3](https://laksa19.github.io/?mikhmon/v3)


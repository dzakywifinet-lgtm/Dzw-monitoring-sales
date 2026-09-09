<?php
// Naikkan angka ini tiap kali assets/style.css atau assets/app.js diubah,
// supaya browser & PWA ambil versi baru (bukan versi lama dari cache).
// Dipakai sebagai query string (?v=...) pada tag <link>/<script> di semua
// halaman, dan sebagai nama cache di service-worker.js.
define('MH_ASSET_VER', '21.0.0');

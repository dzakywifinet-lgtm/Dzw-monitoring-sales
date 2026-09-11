// Service worker untuk dashboard penghasilan.
// Prinsip: aset statis (css/js/ikon) boleh dari cache biar app-shell cepat &
// bisa dibuka walau sinyal jelek. Data live (api/*.php) & halaman PHP TIDAK
// pernah disajikan basi - selalu diutamakan dari jaringan.

var CACHE_NAME = 'mh-shell-v22.1.2';
var STATIC_ASSETS = [
  'assets/style.css',
  'assets/app.js',
  'assets/manifest.json',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(STATIC_ASSETS);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== CACHE_NAME; }).map(function (k) { return caches.delete(k); })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function (event) {
  var url = new URL(event.request.url);

  // Data live & aksi (login/logout/pengaturan/api) - selalu network, tidak pernah cache.
  if (url.pathname.indexOf('/api/') !== -1 || event.request.method !== 'GET') {
    return;
  }

  var isStaticAsset = STATIC_ASSETS.some(function (a) { return url.pathname.indexOf(a) !== -1; });

  if (isStaticAsset) {
    // Cache-first untuk aset statis: cepat dibuka, tetap diperbarui diam-diam di belakang layar.
    event.respondWith(
      caches.match(event.request).then(function (cached) {
        var fetchPromise = fetch(event.request).then(function (res) {
          if (res && res.ok) {
            caches.open(CACHE_NAME).then(function (cache) { cache.put(event.request, res.clone()); });
          }
          return res;
        }).catch(function () { return cached; });
        return cached || fetchPromise;
      })
    );
    return;
  }

  // Halaman PHP (login/dashboard/pengaturan) - network-first, fallback cache kalau benar-benar offline.
  event.respondWith(
    fetch(event.request)
      .then(function (res) {
        if (res && res.ok) {
          var resClone = res.clone();
          caches.open(CACHE_NAME).then(function (cache) { cache.put(event.request, resClone); });
        }
        return res;
      })
      .catch(function () {
        return caches.match(event.request);
      })
  );
});

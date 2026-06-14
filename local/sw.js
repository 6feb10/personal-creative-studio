// ═══════════════════════════════════════════════
//  DreamStudio Local — Service Worker (offline-first)
// ═══════════════════════════════════════════════
const CACHE = 'dreamstudio-local-v6';

const ASSETS = [
  './',
  './index.html',
  './cradle.html', './forge.html', './desire.html', './reverie.html',
  './memoria.html', './sanctum.html', './chat.html', './illustrate.html',
  './spark.html', './settings.html',
  './css/style.css',
  './js/db.js', './js/ui.js', './js/store.js', './js/ai.js', './js/backup.js',
  './manifest.json',
  './icons/icon-192.png', './icons/icon-512.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(ASSETS).catch(() => {})).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// 自分のアセットはキャッシュ優先（オフライン動作）。
// 外部（フォント・AI API）はネットワーク優先で、フォントだけ取得後キャッシュ。
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  if (url.origin === self.location.origin) {
    e.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) { const clone = res.clone(); caches.open(CACHE).then((c) => c.put(req, clone)); }
        return res;
      }).catch(() => caches.match('./index.html')))
    );
    return;
  }

  if (url.host.includes('fonts.googleapis.com') || url.host.includes('fonts.gstatic.com')) {
    e.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        const clone = res.clone(); caches.open(CACHE).then((c) => c.put(req, clone)); return res;
      }).catch(() => hit))
    );
  }
  // それ以外（AI API等）は素通し
});

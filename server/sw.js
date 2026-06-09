// ═══════════════════════════════════════════════
//  DreamStudio — Service Worker
// ═══════════════════════════════════════════════
const CACHE_NAME = 'dreamstudio-v1';
const STATIC_ASSETS = [
  '/css/style.css',
  '/manifest.json'
];

// インストール時に静的アセットをキャッシュ
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

// 古いキャッシュの削除
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// ネットワークファースト戦略（常に最新データを優先）
self.addEventListener('fetch', event => {
  // API呼び出しやPOSTリクエストはキャッシュしない
  if (event.request.method !== 'GET') return;

  event.respondWith(
    fetch(event.request)
      .then(response => {
        // 成功したらキャッシュにも保存
        if (response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      })
      .catch(() => {
        // オフライン時はキャッシュから返す
        return caches.match(event.request);
      })
  );
});

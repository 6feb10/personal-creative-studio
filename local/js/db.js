// ═══════════════════════════════════════════════
//  DreamStudio Local — IndexedDB layer
//  サーバー不要。すべてのデータはこの端末のブラウザ内に保存されます。
// ═══════════════════════════════════════════════
const DB_NAME = 'dreamstudio';
const DB_VERSION = 1;

// オブジェクトストア（id 自動採番）。settings のみ key-value。
const STORES = [
  'bases', 'residents', 'novels',
  'images', 'imageFolders',
  'bookmarks', 'bookmarkFolders',
  'projects',
  'apiProviders', 'forgeTemplates', 'forgeGenerations',
];

let _dbPromise = null;

function openDB() {
  if (_dbPromise) return _dbPromise;
  _dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = (e) => {
      const db = req.result;
      for (const name of STORES) {
        if (!db.objectStoreNames.contains(name)) {
          db.createObjectStore(name, { keyPath: 'id', autoIncrement: true });
        }
      }
      if (!db.objectStoreNames.contains('settings')) {
        db.createObjectStore('settings', { keyPath: 'key' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return _dbPromise;
}

function tx(store, mode = 'readonly') {
  return openDB().then((db) => db.transaction(store, mode).objectStore(store));
}

function reqToPromise(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

// ── 汎用CRUD ──
const DB = {
  async all(store) {
    return reqToPromise((await tx(store)).getAll());
  },
  async get(store, id) {
    return reqToPromise((await tx(store)).get(Number(id)));
  },
  async put(store, obj) {
    // id があれば更新、無ければ新規（autoIncrement）
    const os = await tx(store, 'readwrite');
    const id = await reqToPromise(os.put(obj));
    return id;
  },
  async add(store, obj) {
    const now = Date.now();
    if (obj.createdAt == null) obj.createdAt = now;
    obj.updatedAt = now;
    return DB.put(store, obj);
  },
  async update(store, obj) {
    obj.updatedAt = Date.now();
    return DB.put(store, obj);
  },
  async del(store, id) {
    const os = await tx(store, 'readwrite');
    return reqToPromise(os.delete(Number(id)));
  },
  async clear(store) {
    const os = await tx(store, 'readwrite');
    return reqToPromise(os.clear());
  },
  async count(store) {
    return reqToPromise((await tx(store)).count());
  },

  // settings（key-value）
  async getSetting(key, fallback = null) {
    const row = await reqToPromise((await tx('settings')).get(key));
    return row ? row.value : fallback;
  },
  async setSetting(key, value) {
    const os = await tx('settings', 'readwrite');
    return reqToPromise(os.put({ key, value }));
  },

  // バックアップ用：全ストアをまとめて出し入れ
  storeNames() { return [...STORES]; },
  async bulkPut(store, rows) {
    const os = await tx(store, 'readwrite');
    for (const row of rows) await reqToPromise(os.put(row));
  },
};

// ── 初回シード（プリセットのテンプレート） ──
async function seedIfEmpty() {
  const tplCount = await DB.count('forgeTemplates');
  if (tplCount === 0) {
    const presets = [
      { name: '明るい日常', category: 'mood', content: '穏やかで明るい雰囲気。日常の何気ない出来事を丁寧に描写する。', isPreset: 1, sortOrder: 1 },
      { name: 'シリアス', category: 'mood', content: '緊張感のある重厚なトーン。心理描写を深く掘り下げる。', isPreset: 1, sortOrder: 2 },
      { name: '地の文多め', category: 'style', content: '情景描写と心情描写を厚めに、丁寧な地の文で進める。', isPreset: 1, sortOrder: 3 },
      { name: '会話中心', category: 'style', content: 'セリフを中心にテンポよく、キャラクターの掛け合いで進める。', isPreset: 1, sortOrder: 4 },
      { name: '出会いのシーン', category: 'situation', content: '登場人物が初めて出会う場面から物語を始める。', isPreset: 1, sortOrder: 5 },
      { name: '日常の一コマ', category: 'situation', content: '特別な事件のない、ありふれた一日の出来事を描く。', isPreset: 1, sortOrder: 6 },
    ];
    for (const p of presets) await DB.add('forgeTemplates', p);
  }
}

window.DB = DB;
window.seedIfEmpty = seedIfEmpty;

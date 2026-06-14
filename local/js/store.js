// ═══════════════════════════════════════════════
//  DreamStudio Local — domain helpers (built on DB)
// ═══════════════════════════════════════════════

const _imgUrlCache = new Map(); // id -> objectURL

const Store = {
  // 更新日時の新しい順
  byUpdatedDesc(rows) {
    return [...rows].sort((a, b) => (b.updatedAt || b.createdAt || 0) - (a.updatedAt || a.createdAt || 0));
  },
  byCreatedDesc(rows) {
    return [...rows].sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0));
  },
  byName(rows) {
    return [...rows].sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ja'));
  },

  // レコード群から使われているタグ一覧を集計
  collectTags(rows) {
    const set = new Set();
    for (const r of rows) for (const t of (r.tags || [])) set.add(t);
    return [...set].sort((a, b) => a.localeCompare(b, 'ja'));
  },

  // 画像レコード（blob）の表示用URL（キャッシュ付き）
  imageURL(rec) {
    if (!rec || !(rec.blob instanceof Blob)) return '';
    if (_imgUrlCache.has(rec.id)) return _imgUrlCache.get(rec.id);
    const url = URL.createObjectURL(rec.blob);
    _imgUrlCache.set(rec.id, url);
    return url;
  },

  // ノベル本文の {{img:ID}} を画像に展開（ビジュアルノベル表示）
  renderNovelBody(body, imageMap) {
    const parts = String(body || '').split(/(\{\{img:\d+\}\})/g);
    let html = '';
    for (const part of parts) {
      const m = part.match(/^\{\{img:(\d+)\}\}$/);
      if (m) {
        const img = imageMap.get(Number(m[1]));
        if (img) {
          const url = Store.imageURL(img);
          const cap = img.description ? `<figcaption class="still-caption">${DS.esc(img.description)}</figcaption>` : '';
          html += `<figure class="still-frame"><img src="${url}" alt="">${cap}</figure>`;
        }
      } else if (part.trim() !== '') {
        html += `<div class="novel-text-block">${DS.esc(part).replace(/\n/g, '<br>')}</div>`;
      }
    }
    return html;
  },

  // 本文中で参照されている画像IDを抽出
  imageIdsInBody(body) {
    const ids = new Set();
    for (const m of String(body || '').matchAll(/\{\{img:(\d+)\}\}/g)) ids.add(Number(m[1]));
    return [...ids];
  },

  // このAIプロバイダーが使える状態か（有効＋キーあり）
  async providersReady() {
    const list = await DB.all('apiProviders');
    return list.filter((p) => p.enabled && p.apiKey && p.apiKey.trim() !== '');
  },
  async hasAnyAI() {
    return (await Store.readyModels()).length > 0;
  },

  // 生成プルダウン用：有効プロバイダー × 登録モデルを平坦化
  // → [{ providerId, idx, provider, model, label }]
  async readyModels() {
    const ready = await Store.providersReady();
    const out = [];
    for (const p of ready) {
      const models = (window.AI && AI.modelsOf) ? AI.modelsOf(p) : [];
      models.forEach((m, idx) => {
        if (!m.model) return;
        out.push({ providerId: p.id, idx, provider: p, model: m.model, label: `${p.displayName || p.name} · ${m.model}` });
      });
    }
    return out;
  },
};

window.Store = Store;

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

  // 本文中の {{img:ID}} / {{h:見出し}} を取り除いた素のテキスト
  stripMarkers(s) {
    return String(s || '').replace(/\{\{img:\d+\}\}|\{\{h:[^}]*\}\}/g, '');
  },

  // ノベル本文の {{img:ID}}=画像 / {{h:見出し}}=シーン見出し を展開
  renderNovelBody(body, imageMap) {
    const parts = String(body || '').split(/(\{\{img:\d+\}\}|\{\{h:[^}]*\}\})/g);
    let html = '';
    let hi = 0;
    for (const part of parts) {
      let m = part.match(/^\{\{img:(\d+)\}\}$/);
      if (m) {
        const img = imageMap.get(Number(m[1]));
        if (img) {
          const url = Store.imageURL(img);
          const cap = img.description ? `<figcaption class="still-caption">${DS.esc(img.description)}</figcaption>` : '';
          html += `<figure class="still-frame"><img src="${url}" alt="">${cap}</figure>`;
        }
        continue;
      }
      m = part.match(/^\{\{h:([^}]*)\}\}$/);
      if (m) {
        hi += 1;
        html += `<h3 class="novel-heading" id="scene-${hi}">${DS.esc(m[1].trim())}</h3>`;
        continue;
      }
      if (part.trim() !== '') {
        html += `<div class="novel-text-block">${DS.esc(part).replace(/\n/g, '<br>')}</div>`;
      }
    }
    return html;
  },

  // 本文中の見出しから目次を組み立てる（{anchor, text} の配列）
  novelToc(body) {
    const toc = [];
    let i = 0;
    for (const m of String(body || '').matchAll(/\{\{h:([^}]*)\}\}/g)) {
      i += 1;
      toc.push({ anchor: 'scene-' + i, text: m[1].trim() });
    }
    return toc;
  },

  // 本文中で参照されている画像IDを抽出
  imageIdsInBody(body) {
    const ids = new Set();
    for (const m of String(body || '').matchAll(/\{\{img:(\d+)\}\}/g)) ids.add(Number(m[1]));
    return [...ids];
  },

  // 画像の表示順（sortOrder優先、無ければ作成日時の新しい順）
  bySort(rows) {
    return [...rows].sort((a, b) => {
      const ao = (a.sortOrder ?? Infinity), bo = (b.sortOrder ?? Infinity);
      if (ao !== bo) return ao - bo;
      return (b.createdAt || 0) - (a.createdAt || 0);
    });
  },

  // 棚（タグ条件）に画像が合致するか。matchMode: 'all'=全タグ一致 / それ以外=いずれか一致
  shelfMatch(shelf, img) {
    const want = shelf.tagNames || [];
    const have = img.tags || [];
    if (!want.length) return false;
    return shelf.matchMode === 'all' ? want.every((t) => have.includes(t)) : want.some((t) => have.includes(t));
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

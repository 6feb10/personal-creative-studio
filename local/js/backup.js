// ═══════════════════════════════════════════════
//  DreamStudio Local — backup (export / import)
//  全データを1つのJSONに書き出し/読み込み。端末の引っ越し手段。
// ═══════════════════════════════════════════════

function blobToDataURL(blob) {
  return new Promise((resolve, reject) => {
    const fr = new FileReader();
    fr.onload = () => resolve(fr.result);
    fr.onerror = () => reject(fr.error);
    fr.readAsDataURL(blob);
  });
}
async function dataURLToBlob(dataURL) {
  const res = await fetch(dataURL);
  return res.blob();
}

async function exportAll() {
  const data = { app: 'DreamStudio', version: 1, exportedAt: Date.now(), stores: {} };
  for (const name of DB.storeNames()) {
    const rows = await DB.all(name);
    if (name === 'images') {
      // Blob を dataURL に変換して同梱
      for (const r of rows) {
        if (r.blob instanceof Blob) { r.blob = { __dataURL: await blobToDataURL(r.blob) }; }
      }
    }
    data.stores[name] = rows;
  }
  data.stores.settings = await DB.all('settings');
  // APIキーはバックアップに含めない（漏えい防止）
  data.stores.settings = (data.stores.settings || []).filter((s) => s.key !== 'apiKeys');
  if (data.stores.apiProviders) {
    data.stores.apiProviders = data.stores.apiProviders.map((p) => ({ ...p, apiKey: '' }));
  }

  const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const stamp = new Date().toISOString().slice(0, 10);
  a.href = url; a.download = `dreamstudio-backup-${stamp}.json`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function importAll(file, { replace = true } = {}) {
  const text = await file.text();
  const data = JSON.parse(text);
  if (!data || data.app !== 'DreamStudio' || !data.stores) {
    throw new Error('DreamStudio のバックアップファイルではないようです。');
  }
  const allStores = [...DB.storeNames(), 'settings'];
  for (const name of allStores) {
    const rows = data.stores[name];
    if (!Array.isArray(rows)) continue;
    if (replace) await DB.clear(name);
    if (name === 'images') {
      for (const r of rows) {
        if (r.blob && r.blob.__dataURL) { r.blob = await dataURLToBlob(r.blob.__dataURL); }
      }
    }
    await DB.bulkPut(name, rows);
  }
}

window.Backup = { exportAll, importAll };

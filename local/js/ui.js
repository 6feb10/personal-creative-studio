// ═══════════════════════════════════════════════
//  DreamStudio Local — shared UI helpers
// ═══════════════════════════════════════════════

// HTMLエスケープ（PHPの h() 相当）
function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// 抜粋（PHPの excerpt() 相当）
function excerpt(text, len = 80) {
  const plain = String(text ?? '').replace(/<[^>]*>/g, '').replace(/\{\{img:\d+\}\}/g, '');
  return [...plain].length > len ? [...plain].slice(0, len).join('') + '…' : plain;
}

const qs = (sel, root = document) => root.querySelector(sel);
const qsa = (sel, root = document) => [...root.querySelectorAll(sel)];

// ── フラッシュメッセージ（ページ遷移をまたぐ） ──
function flash(msg, type = 'success') {
  sessionStorage.setItem('ds_flash', JSON.stringify({ msg, type }));
}
function showPendingFlash() {
  const raw = sessionStorage.getItem('ds_flash');
  if (!raw) return;
  sessionStorage.removeItem('ds_flash');
  try { const f = JSON.parse(raw); toast(f.msg, f.type); } catch {}
}
function toast(msg, type = 'success') {
  let host = qs('#ds-toast-host');
  if (!host) {
    host = document.createElement('div');
    host.id = 'ds-toast-host';
    host.style.cssText = 'position:fixed;top:calc(0.8rem + var(--safe-top));left:50%;transform:translateX(-50%);z-index:2000;width:calc(100% - 2rem);max-width:420px;pointer-events:none;';
    document.body.appendChild(host);
  }
  const t = document.createElement('div');
  t.className = 'flash flash-' + (type === 'error' ? 'error' : 'success');
  t.textContent = msg;
  t.style.cssText = 'pointer-events:auto;box-shadow:0 4px 16px rgba(40,30,55,0.12);';
  host.appendChild(t);
  setTimeout(() => { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 2600);
}

// ── 確認ダイアログ（Promise<boolean>） ──
function confirmDialog(message, okLabel = '削除', danger = true) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay show';
    overlay.innerHTML = `
      <div class="modal-box">
        <p class="modal-text">${esc(message)}</p>
        <div class="modal-actions">
          <button class="btn btn-sm" data-act="cancel">やめる</button>
          <button class="btn ${danger ? 'btn-danger' : 'btn-primary'} btn-sm" data-act="ok">${esc(okLabel)}</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    const close = (val) => { overlay.remove(); resolve(val); };
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) close(false);
      const act = e.target.closest('[data-act]')?.dataset.act;
      if (act === 'ok') close(true);
      if (act === 'cancel') close(false);
    });
  });
}

// ── 共通ページヘッダー ──
function pageHeader(backHref, backLabel, gateIcon) {
  return `<header class="page-header">
    <a href="${esc(backHref)}" class="back-btn">‹ ${esc(backLabel)}</a>
    <span class="page-gate-icon">${gateIcon}</span>
  </header>`;
}

// 日付整形
function fmtDate(ts) {
  if (!ts) return '';
  const d = new Date(ts);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}/${p(d.getMonth() + 1)}/${p(d.getDate())}`;
}

// ── アプリ初期化（各ページの先頭で呼ぶ） ──
async function appInit() {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
  try { await seedIfEmpty(); } catch (e) { console.error(e); }
  showPendingFlash();
}

window.DS = { esc, excerpt, qs, qsa, flash, toast, confirmDialog, pageHeader, fmtDate, appInit };

// ═══════════════════════════════════════════════
//  DreamStudio Local — AI provider layer
//  forge_api.php のブラウザ移植。キーは端末内のみ、呼び出しは直接。
//  ※ プロバイダーによりブラウザ直叩き(CORS)の可否が異なります。
// ═══════════════════════════════════════════════

// 設定画面の「追加」候補。endpoint/model は編集可。
const AI_PRESETS = [
  { providerType: 'OpenAI',   name: 'OpenAI',   displayName: 'OpenAI',   endpoint: 'https://api.openai.com/v1/chat/completions', model: 'gpt-4o' },
  { providerType: 'Claude',   name: 'Claude',   displayName: 'Claude',   endpoint: 'https://api.anthropic.com/v1/messages',      model: 'claude-sonnet-4-6' },
  { providerType: 'Gemini',   name: 'Gemini',   displayName: 'Gemini',   endpoint: 'https://generativelanguage.googleapis.com/v1beta/models/', model: 'gemini-2.5-flash' },
  { providerType: 'Grok',     name: 'Grok',     displayName: 'Grok',     endpoint: 'https://api.x.ai/v1/chat/completions',       model: 'grok-2' },
  { providerType: 'Deepseek', name: 'Deepseek', displayName: 'Deepseek', endpoint: 'https://api.deepseek.com/chat/completions',  model: 'deepseek-chat' },
];

// プロバイダー種別ごとの「モデル登録」カタログ。
// 設定でプルダウンから選ぶと料金が自動で入る（手動追加・編集も可）。料金は USD / 100万トークン。
const AI_MODEL_CATALOG = {
  Claude: [
    { model: 'claude-opus-4-8',   costInput: 5,    costOutput: 25 },
    { model: 'claude-sonnet-4-6', costInput: 3,    costOutput: 15 },
    { model: 'claude-haiku-4-5',  costInput: 1,    costOutput: 5 },
    { model: 'claude-opus-4-7',   costInput: 5,    costOutput: 25 },
    { model: 'claude-fable-5',    costInput: 10,   costOutput: 50 },
  ],
  OpenAI: [
    { model: 'gpt-5.5',        costInput: 5,    costOutput: 30 },
    { model: 'gpt-5.4',        costInput: 2.5,  costOutput: 15 },
    { model: 'gpt-5.4-mini',   costInput: 0.75, costOutput: 4.5 },
    { model: 'gpt-5.4-nano',   costInput: 0.2,  costOutput: 1.25 },
    { model: 'gpt-5.2',        costInput: 1.75, costOutput: 14 },
    { model: 'gpt-5.1',        costInput: 1.25, costOutput: 10 },
    { model: 'gpt-5',          costInput: 1.25, costOutput: 10 },
    { model: 'gpt-5-mini',     costInput: 0.25, costOutput: 2 },
    { model: 'gpt-5-nano',     costInput: 0.05, costOutput: 0.4 },
    { model: 'gpt-4.1',        costInput: 2,    costOutput: 8 },
    { model: 'gpt-4.1-mini',   costInput: 0.4,  costOutput: 1.6 },
    { model: 'gpt-4.1-nano',   costInput: 0.1,  costOutput: 0.4 },
    { model: 'gpt-4o',         costInput: 2.5,  costOutput: 10 },
    { model: 'gpt-4o-mini',    costInput: 0.15, costOutput: 0.6 },
    { model: 'o3',             costInput: 2,    costOutput: 8 },
    { model: 'o4-mini',        costInput: 1.1,  costOutput: 4.4 },
  ],
  // Gemini は Standard 料金。pro系は ≤200k トークンの単価（超過時はGoogle側で上がる）。
  Gemini: [
    { model: 'gemini-3.5-flash',                      costInput: 1.5,  costOutput: 9 },
    { model: 'gemini-3.1-pro-preview',                costInput: 2,    costOutput: 12 },
    { model: 'gemini-3-flash-preview',                costInput: 0.5,  costOutput: 3 },
    { model: 'gemini-3.1-flash-lite',                 costInput: 0.25, costOutput: 1.5 },
    { model: 'gemini-2.5-pro',                        costInput: 1.25, costOutput: 10 },
    { model: 'gemini-2.5-flash',                      costInput: 0.3,  costOutput: 2.5 },
    { model: 'gemini-2.5-flash-lite',                 costInput: 0.1,  costOutput: 0.4 },
    { model: 'gemini-2.5-flash-lite-preview-09-2025', costInput: 0.1,  costOutput: 0.4 },
  ],
  // 料金が未確定のものは 0。設定画面で編集してください。
  Grok:     [ { model: 'grok-2',           costInput: 0, costOutput: 0 } ],
  Deepseek: [ { model: 'deepseek-chat',    costInput: 0, costOutput: 0 } ],
};

// provider レコードの登録モデル一覧を正規化（旧形式：単一 model + 単価 も吸収）
function modelsOf(p) {
  if (p && Array.isArray(p.models) && p.models.length) return p.models;
  if (p && p.model) return [{ model: p.model, costInput: Number(p.costInput) || 0, costOutput: Number(p.costOutput) || 0 }];
  return [];
}

async function postJSON(url, body, headers) {
  try {
    const res = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body) });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { data = null; }
    if (!res.ok) {
      const msg = data?.error?.message || data?.error || `HTTP ${res.status}`;
      return { error: typeof msg === 'string' ? msg : JSON.stringify(msg) };
    }
    return { data };
  } catch (e) {
    return { error: '通信に失敗しました。このプロバイダーはブラウザから直接呼べない可能性があります（CORS）。設定でプロキシを使うか、別プロバイダーをお試しください。' };
  }
}

function buildResult(provider, text, inT, outT, cachedT) {
  const costIn = (inT / 1e6) * (Number(provider.costInput) || 0);
  const costOut = (outT / 1e6) * (Number(provider.costOutput) || 0);
  const r6 = (n) => Math.round(n * 1e6) / 1e6;
  return {
    text,
    provider: provider.displayName || provider.name,
    model: provider.model,
    inputTokens: inT, outputTokens: outT, cachedTokens: cachedT,
    costInput: r6(costIn), costOutput: r6(costOut), costTotal: r6(costIn + costOut),
  };
}

async function callOpenAICompatible(p, sys, user) {
  const url = p.proxy || p.endpoint;
  const headers = { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + p.apiKey };
  const body = {
    model: p.model,
    messages: [{ role: 'system', content: sys }, { role: 'user', content: user }],
  };
  if (p.providerType === 'OpenAI') {
    // GPT-5 / o系は max_tokens 廃止＆temperature固定。新パラメータを使い温度は既定(1)に任せる。
    body.max_completion_tokens = 4096;
  } else {
    body.max_tokens = 4096;
    body.temperature = 0.8;
  }
  const { data, error } = await postJSON(url, body, headers);
  if (error) return { error };
  const text = data?.choices?.[0]?.message?.content ?? '';
  const u = data?.usage ?? {};
  return buildResult(p, text, u.prompt_tokens || 0, u.completion_tokens || 0, u.prompt_tokens_details?.cached_tokens || 0);
}

async function callClaude(p, sys, user) {
  const headers = {
    'Content-Type': 'application/json',
    'x-api-key': p.apiKey,
    'anthropic-version': '2023-06-01',
    'anthropic-dangerous-direct-browser-access': 'true',
  };
  const { data, error } = await postJSON(p.proxy || p.endpoint, {
    model: p.model, system: sys,
    messages: [{ role: 'user', content: user }],
    max_tokens: 4096, temperature: 0.8,
  }, headers);
  if (error) return { error };
  let text = '';
  for (const block of (data?.content ?? [])) if (block.type === 'text') text += block.text;
  const u = data?.usage ?? {};
  return buildResult(p, text, u.input_tokens || 0, u.output_tokens || 0, u.cache_read_input_tokens || 0);
}

async function callGemini(p, sys, user) {
  const url = (p.proxy || (p.endpoint + p.model + ':generateContent')) + '?key=' + encodeURIComponent(p.apiKey);
  const { data, error } = await postJSON(url, {
    system_instruction: { parts: [{ text: sys }] },
    contents: [{ role: 'user', parts: [{ text: user }] }],
    generationConfig: { maxOutputTokens: 4096, temperature: 0.8 },
  }, { 'Content-Type': 'application/json' });
  if (error) return { error };
  const text = data?.candidates?.[0]?.content?.parts?.[0]?.text ?? '';
  const u = data?.usageMetadata ?? {};
  return buildResult(p, text, u.promptTokenCount || 0, u.candidatesTokenCount || 0, u.cachedContentTokenCount || 0);
}

// provider レコード + system/user → 結果。
// modelSel: 使うモデルの指定（登録モデル配列のindex / モデルID文字列 / {model,costInput,costOutput}）。
// 省略時は先頭の登録モデルを使用。
async function aiCall(provider, sys, user, modelSel) {
  if (!provider) return { error: 'プロバイダーが未設定です' };
  if (!provider.apiKey) return { error: (provider.displayName || provider.name) + ' のAPIキーが未設定です' };
  const models = modelsOf(provider);
  let sel = modelSel;
  if (typeof sel === 'number') sel = models[sel];
  else if (typeof sel === 'string') sel = models.find((m) => m.model === sel) || { model: sel, costInput: 0, costOutput: 0 };
  if (!sel || !sel.model) sel = models[0];
  if (!sel || !sel.model) return { error: (provider.displayName || provider.name) + ' のモデルが未設定です' };
  const p = { ...provider, model: sel.model, costInput: Number(sel.costInput) || 0, costOutput: Number(sel.costOutput) || 0 };
  switch (p.providerType) {
    case 'OpenAI': case 'Grok': case 'Deepseek': return callOpenAICompatible(p, sys, user);
    case 'Claude': return callClaude(p, sys, user);
    case 'Gemini': return callGemini(p, sys, user);
    default: return { error: '未対応のプロバイダータイプ: ' + p.providerType };
  }
}

async function aiCallById(providerId, sys, user) {
  const p = await DB.get('apiProviders', providerId);
  if (!p || !p.enabled) return { error: 'プロバイダーが未設定または無効です' };
  return aiCall(p, sys, user);
}

window.AI = { presets: AI_PRESETS, catalog: AI_MODEL_CATALOG, modelsOf, call: aiCall, callById: aiCallById };

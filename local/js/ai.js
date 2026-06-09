// ═══════════════════════════════════════════════
//  DreamStudio Local — AI provider layer
//  forge_api.php のブラウザ移植。キーは端末内のみ、呼び出しは直接。
//  ※ プロバイダーによりブラウザ直叩き(CORS)の可否が異なります。
// ═══════════════════════════════════════════════

// 設定画面の「追加」候補。endpoint/model は編集可。
const AI_PRESETS = [
  { providerType: 'OpenAI',   name: 'OpenAI',   displayName: 'OpenAI',   endpoint: 'https://api.openai.com/v1/chat/completions', model: 'gpt-4o' },
  { providerType: 'Claude',   name: 'Claude',   displayName: 'Claude',   endpoint: 'https://api.anthropic.com/v1/messages',      model: 'claude-sonnet-4-6' },
  { providerType: 'Gemini',   name: 'Gemini',   displayName: 'Gemini',   endpoint: 'https://generativelanguage.googleapis.com/v1beta/models/', model: 'gemini-2.0-flash' },
  { providerType: 'Grok',     name: 'Grok',     displayName: 'Grok',     endpoint: 'https://api.x.ai/v1/chat/completions',       model: 'grok-2' },
  { providerType: 'Deepseek', name: 'Deepseek', displayName: 'Deepseek', endpoint: 'https://api.deepseek.com/chat/completions',  model: 'deepseek-chat' },
];

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
  const proxy = p.proxy ? p.proxy : '';
  const url = proxy || p.endpoint;
  const headers = { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + p.apiKey };
  const { data, error } = await postJSON(url, {
    model: p.model,
    messages: [{ role: 'system', content: sys }, { role: 'user', content: user }],
    max_tokens: 4096, temperature: 0.8,
  }, headers);
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

// provider レコード + system/user → 結果
async function aiCall(provider, sys, user) {
  if (!provider) return { error: 'プロバイダーが未設定です' };
  if (!provider.apiKey) return { error: (provider.displayName || provider.name) + ' のAPIキーが未設定です' };
  switch (provider.providerType) {
    case 'OpenAI': case 'Grok': case 'Deepseek': return callOpenAICompatible(provider, sys, user);
    case 'Claude': return callClaude(provider, sys, user);
    case 'Gemini': return callGemini(provider, sys, user);
    default: return { error: '未対応のプロバイダータイプ: ' + provider.providerType };
  }
}

async function aiCallById(providerId, sys, user) {
  const p = await DB.get('apiProviders', providerId);
  if (!p || !p.enabled) return { error: 'プロバイダーが未設定または無効です' };
  return aiCall(p, sys, user);
}

window.AI = { presets: AI_PRESETS, call: aiCall, callById: aiCallById };

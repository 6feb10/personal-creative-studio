<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/forge_api.php';
requireAuth();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$resident = $pdo->prepare("SELECT r.*, b.name AS base_name FROM residents r LEFT JOIN bases b ON r.base_id=b.id WHERE r.id=?");
$resident->execute([$id]);
$resident = $resident->fetch();
if (!$resident) { header("Location: cradle.php"); exit; }

$enabledProviders = $pdo->query("SELECT * FROM api_providers WHERE enabled=1 ORDER BY display_name")->fetchAll();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    if ($_POST['_action'] === 'generate') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $extraDir = trim($_POST['extra_directions'] ?? '');

        $systemPrompt = "あなたは画像生成AI向けのキャラクタープロンプト作成の専門家です。
与えられたキャラクター設定を、画像生成AI（Stable Diffusion, Midjourney, DALL-E等）で使える
キャラクター描写プロンプトに変換してください。

## 出力ルール
- 英語で出力
- キャラクターの外見描写に集中する（性格は含めない）
- 以下の要素を含める:
  1. 性別・年齢感
  2. 髪型・髪色
  3. 目の色・形
  4. 体型・身長感
  5. 服装・アクセサリー
  6. 全体の雰囲気・系統
  7. 特徴的なポイント
- カンマ区切りの1つのプロンプトとして出力
- このプロンプトは他のシーンプロンプトと組み合わせて使われる
- だから背景やポーズは含めず、人物の外見のみに集中する

## 出力フォーマット
```
CHARACTER PROMPT:
(ここにキャラクタープロンプト)

補足:
(日本語で簡潔にどんなキャラか)
```";

        $charInfo = "## キャラクター: {$resident['name']}\n";
        foreach (['gender'=>'性別','height'=>'身長','body_type'=>'体型','hairstyle'=>'髪型','eye_color'=>'目の色','clothing'=>'服装','style'=>'系統','features'=>'特徴','physical_info'=>'身体情報'] as $k=>$label) {
            if (!empty($resident[$k])) $charInfo .= "{$label}: {$resident[$k]}\n";
        }

        $userPrompt = "以下のキャラクターの外見プロンプトを生成してください:\n\n" . $charInfo;
        if ($extraDir) $userPrompt .= "\n追加指示: " . $extraDir;

        $result = forgeCallAPI($pdo, $providerId, $systemPrompt, $userPrompt);

        if (!isset($result['error'])) {
            $stmt = $pdo->prepare("INSERT INTO forge_generations (provider,model,prompt_system,prompt_user,output,input_tokens,output_tokens,cached_tokens,cost_input,cost_output,cost_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$result['provider'],$result['model'],'キャラプロンプト生成',$charInfo,$result['text'],$result['input_tokens'],$result['output_tokens'],$result['cached_tokens'],$result['cost_input'],$result['cost_output'],$result['cost_total']]);
        }
    } elseif ($_POST['_action'] === 'save_prompt') {
        $prompt = trim($_POST['illust_prompt'] ?? '');
        $pdo->prepare("UPDATE residents SET illust_prompt=? WHERE id=?")->execute([$prompt, $id]);
        flash('💎 イラストプロンプトを保存した！');
        header("Location: cradle.php?action=resident&id={$id}");
        exit;
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>🎨 <?= h($resident['name']) ?> — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<?php include(__DIR__ . '/../pwa_head.php'); ?>
<style>
.char-summary {
    background: rgba(255,255,255,0.4); border: 1px solid rgba(100,200,255,0.15);
    border-radius: 12px; padding: 1rem; margin: 1rem 0;
    font-size: 0.82rem; line-height: 1.7; color: rgba(58,47,74,0.5);
}
.char-summary strong { color: var(--pearl); }
.prompt-result {
    background: rgba(255,255,255,0.4); border: 1px solid rgba(245,158,122,0.2);
    border-radius: 12px; padding: 1.2rem; margin: 1rem 0;
    font-size: 0.88rem; line-height: 1.8; color: var(--pearl);
    white-space: pre-wrap; word-wrap: break-word;
}
.prompt-edit {
    width: 100%; min-height: 120px; padding: 1rem;
    background: rgba(255,255,255,0.5); border: 1px solid rgba(245,158,122,0.2);
    border-radius: 12px; color: var(--pearl);
    font-family: 'Noto Sans JP', sans-serif; font-size: 0.88rem;
    line-height: 1.8; resize: vertical; outline: none;
}
.prompt-edit:focus { border-color: var(--rose-gold); }
.saved-prompt-box {
    background: rgba(245,158,122,0.06); border: 1px solid rgba(245,158,122,0.15);
    border-radius: 12px; padding: 1rem; margin: 1rem 0;
}
.saved-prompt-label {
    font-size: 0.65rem; color: var(--rose-gold); letter-spacing: 0.1em;
    margin-bottom: 0.4rem; font-family: 'M PLUS Rounded 1c', sans-serif;
}
.saved-prompt-text {
    font-size: 0.82rem; line-height: 1.7; color: var(--pearl);
    white-space: pre-wrap;
}
.copy-btn {
    display: flex; align-items: center; justify-content: center; gap: 0.4rem;
    width: 100%; padding: 0.7rem;
    background: rgba(245,158,122,0.12); border: 1px solid rgba(245,158,122,0.25);
    border-radius: 10px; color: var(--rose-gold);
    font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.8rem;
    cursor: pointer; -webkit-tap-highlight-color: transparent;
}
.copy-btn:active { transform: scale(0.97); }
.gen-stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.4rem; margin: 0.8rem 0; }
.gen-stat { background: rgba(255,255,255,0.3); border: 1px solid rgba(124,92,255,0.08); border-radius: 8px; padding: 0.5rem; text-align: center; }
.gen-stat-val { font-size: 0.85rem; color: var(--honey); font-family: 'M PLUS Rounded 1c', sans-serif; }
.gen-stat-label { font-size: 0.5rem; color: rgba(124,92,255,0.4); margin-top: 0.1rem; }
</style>
</head>
<body>
<div class="cosmos"></div><div class="noise"></div>
<div class="page">
<?php if ($flash): ?><div class="flash flash-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

<header class="page-header">
    <a href="cradle.php?action=resident&id=<?= $id ?>" class="back-btn">‹ <?= h($resident['name']) ?></a>
    <span class="page-gate-icon">🎨</span>
</header>
<h1 class="page-title"><?= h($resident['name']) ?></h1>
<p class="page-subtitle">Character Prompt</p>

<!-- 現在の外見データ -->
<div class="char-summary">
    <?php foreach (['gender'=>'性別','height'=>'身長','body_type'=>'体型','hairstyle'=>'髪型','eye_color'=>'目の色','clothing'=>'服装','style'=>'系統','features'=>'特徴'] as $k=>$label): ?>
        <?php if (!empty($resident[$k])): ?><strong><?= $label ?>:</strong> <?= h($resident[$k]) ?><br><?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- 保存済みプロンプトがある場合 -->
<?php if ($resident['illust_prompt']): ?>
<div class="saved-prompt-box">
    <div class="saved-prompt-label">💎 保存済みイラストプロンプト</div>
    <div class="saved-prompt-text"><?= h($resident['illust_prompt']) ?></div>
</div>
<button class="copy-btn mb-2" onclick="copyText('<?= h(addslashes($resident['illust_prompt'])) ?>')">📋 コピー</button>
<?php endif; ?>

<?php if (empty($enabledProviders)): ?>
    <div class="empty mt-3"><div class="empty-icon">⚙️</div><p class="empty-text">ForgeのAPI設定でキーを登録してね！</p></div>

<?php elseif ($result && !isset($result['error'])): ?>
<!-- ═══ 生成結果 ═══ -->
<div class="gen-stats">
    <div class="gen-stat"><div class="gen-stat-val" style="font-size:0.7rem;"><?= h($result['provider']) ?></div><div class="gen-stat-label">AI</div></div>
    <div class="gen-stat"><div class="gen-stat-val"><?= number_format($result['input_tokens'] + $result['output_tokens']) ?></div><div class="gen-stat-label">TOKENS</div></div>
    <div class="gen-stat"><div class="gen-stat-val">$<?= number_format($result['cost_total'], 4) ?></div><div class="gen-stat-label">COST</div></div>
</div>

<div class="prompt-result"><?= h($result['text']) ?></div>

<!-- 編集して保存 -->
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="save_prompt">
    <div class="form-group">
        <label class="form-label">プロンプトを編集して保存</label>
        <textarea name="illust_prompt" class="prompt-edit"><?= h($result['text']) ?></textarea>
        <div class="form-hint">💡 不要な部分を削除したり微調整してから保存できるよ</div>
    </div>
    <button type="submit" class="btn btn-primary btn-block">💎 この住人に保存</button>
</form>

<div class="mt-2" style="display:flex;gap:0.5rem;">
    <a href="resident_illust.php?id=<?= $id ?>" class="btn btn-sm" style="flex:1;">🎨 再生成</a>
    <button class="copy-btn" style="flex:1;" onclick="copyText(document.querySelector('.prompt-edit').value)">📋 コピー</button>
</div>

<?php elseif ($result && isset($result['error'])): ?>
<div class="flash flash-error">❌ <?= h($result['error']) ?></div>
<a href="resident_illust.php?id=<?= $id ?>" class="btn btn-block mt-2">🎨 やり直す</a>

<?php else: ?>
<!-- ═══ 生成フォーム ═══ -->
<form method="POST" class="mt-2" id="genForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="generate">

    <div class="form-group">
        <label class="form-label">AI</label>
        <select name="provider_id" class="form-select" required>
            <?php foreach ($enabledProviders as $ep): ?>
                <option value="<?= $ep['id'] ?>"><?= h($ep['display_name'] ?: $ep['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">追加指示（任意）</label>
        <textarea name="extra_directions" class="form-textarea" rows="3" placeholder="例: anime style, detailed eyes, upper body"></textarea>
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2" id="genBtn">🎨 プロンプト生成</button>
</form>

<script>
document.getElementById('genForm')?.addEventListener('submit', function() {
    var b = document.getElementById('genBtn');
    b.disabled = true; b.textContent = '🎨 生成中…'; b.style.opacity = '0.6';
});
</script>
<?php endif; ?>

</div>
<script>
function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        var btns = document.querySelectorAll('.copy-btn');
        btns.forEach(b => { var o = b.textContent; b.textContent = '✓ コピーした！'; setTimeout(() => b.textContent = o, 2000); });
    });
}
</script>
</body></html>

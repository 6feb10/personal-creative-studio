<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/forge_api.php';
require_once __DIR__ . '/forge_illust.php';
requireAuth();

$pdo = db();
$action = $_GET['action'] ?? 'form';

// プリフィル: Desireから来た場合
$prefillText = '';
$prefillNovelId = (int)($_GET['novel_id'] ?? 0);
$prefillNovelTitle = '';
if ($prefillNovelId) {
    $nv = $pdo->prepare("SELECT title, body FROM novels WHERE id=?");
    $nv->execute([$prefillNovelId]); $nv = $nv->fetch();
    if ($nv) {
        $prefillText = preg_replace('/\{\{img:\d+\}\}/', '', $nv['body']);
        $prefillNovelTitle = $nv['title'];
    }
}

// 住人一覧
$residents = $pdo->query("SELECT * FROM residents ORDER BY name")->fetchAll();
$enabledProviders = $pdo->query("SELECT * FROM api_providers WHERE enabled=1 ORDER BY display_name")->fetchAll();

// ── 生成処理 ──
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    if ($_POST['_action'] === 'generate_illust') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $sceneText = trim($_POST['scene_text'] ?? '');
        $extraDir = trim($_POST['extra_directions'] ?? '');
        $resIds = $_POST['resident_ids'] ?? [];

        if (!$sceneText) {
            flash('シーンテキストを入れてね', 'error');
        } else {
            // 選択された住人情報を取得
            $chars = [];
            foreach ($resIds as $rid) {
                $r = $pdo->prepare("SELECT * FROM residents WHERE id=?");
                $r->execute([(int)$rid]); $r = $r->fetch();
                if ($r) $chars[] = $r;
            }

            $result = generateIllustPrompt($pdo, $providerId, $sceneText, $chars, $extraDir);

            if (!isset($result['error'])) {
                // コスト記録
                $stmt = $pdo->prepare("INSERT INTO forge_generations (provider,model,prompt_system,prompt_user,output,input_tokens,output_tokens,cached_tokens,cost_input,cost_output,cost_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $result['provider'], $result['model'],
                    '挿絵プロンプト生成', $sceneText, $result['text'],
                    $result['input_tokens'], $result['output_tokens'], $result['cached_tokens'],
                    $result['cost_input'], $result['cost_output'], $result['cost_total']
                ]);
            }
        }
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>🎨 挿絵プロンプト — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<?php include(__DIR__ . '/../pwa_head.php'); ?>
<style>
.illust-result {
    background: rgba(255,255,255,0.4); border: 1px solid rgba(245,158,122,0.2);
    border-radius: 12px; padding: 1.2rem; margin: 1rem 0;
    font-family: 'Noto Sans JP', monospace;
    font-size: 0.88rem; line-height: 1.8; color: var(--pearl);
    white-space: pre-wrap; word-wrap: break-word;
}
.copy-btn {
    display: flex; align-items: center; justify-content: center; gap: 0.4rem;
    width: 100%; padding: 0.7rem;
    background: rgba(245,158,122,0.12); border: 1px solid rgba(245,158,122,0.25);
    border-radius: 10px; color: var(--rose-gold);
    font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.8rem;
    cursor: pointer; transition: all 0.3s;
    -webkit-tap-highlight-color: transparent;
}
.copy-btn:active { transform: scale(0.97); background: rgba(245,158,122,0.2); }
.scene-preview {
    background: rgba(255,255,255,0.3); border: 1px solid rgba(124,92,255,0.1);
    border-radius: 10px; padding: 0.8rem;
    font-size: 0.82rem; line-height: 1.7; color: rgba(58,47,74,0.5);
    max-height: 150px; overflow-y: auto; margin-bottom: 1rem;
}
.source-label {
    font-size: 0.7rem; color: var(--orchid); opacity: 0.5;
    letter-spacing: 0.1em; margin-bottom: 0.3rem;
}
.mat-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.mat-chip {
    display: flex; align-items: center; gap: 0.3rem;
    padding: 0.45rem 0.7rem; background: rgba(255,255,255,0.5);
    border: 1px solid rgba(124,92,255,0.12); border-radius: 8px;
    cursor: pointer; transition: all 0.2s;
}
.mat-chip input { accent-color: var(--orchid); width: 14px; height: 14px; }
.mat-chip span { font-size: 0.78rem; color: var(--pearl); }
.mat-chip:has(input:checked) { border-color: var(--orchid); background: rgba(124,92,255,0.12); }
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
    <a href="<?= $prefillNovelId ? "desire.php?action=view&id={$prefillNovelId}" : 'forge.php' ?>" class="back-btn">‹ <?= $prefillNovelId ? 'Desire' : 'Forge' ?></a>
    <span class="page-gate-icon">🎨</span>
</header>
<h1 class="page-title">挿絵プロンプト</h1>
<p class="page-subtitle">Scene to Image Prompt</p>

<?php if (empty($enabledProviders)): ?>
    <div class="empty mt-3"><div class="empty-icon">⚙️</div><p class="empty-text">先にForgeのAPI設定でキーを登録してね！</p></div>
<?php elseif ($result && !isset($result['error'])): ?>
<!-- ═══ RESULT ═══ -->
<div class="gen-stats">
    <div class="gen-stat"><div class="gen-stat-val" style="font-size:0.7rem;"><?= h($result['provider']) ?></div><div class="gen-stat-label">AI</div></div>
    <div class="gen-stat"><div class="gen-stat-val"><?= number_format($result['input_tokens'] + $result['output_tokens']) ?></div><div class="gen-stat-label">TOKENS</div></div>
    <div class="gen-stat"><div class="gen-stat-val">$<?= number_format($result['cost_total'], 4) ?></div><div class="gen-stat-label">COST</div></div>
</div>

<div class="illust-result" id="promptResult"><?= h($result['text']) ?></div>

<button class="copy-btn" onclick="copyPrompt()">📋 プロンプトをコピー</button>

<div class="mt-2" style="display:flex;gap:0.5rem;">
    <a href="illustrate.php<?= $prefillNovelId ? '?novel_id='.$prefillNovelId : '' ?>" class="btn btn-sm" style="flex:1;">🎨 もう一度</a>
    <?php if ($prefillNovelId): ?>
        <a href="desire.php?action=view&id=<?= $prefillNovelId ?>" class="btn btn-sm" style="flex:1;">📜 ノベルに戻る</a>
    <?php endif; ?>
</div>

<script>
function copyPrompt() {
    const text = document.getElementById('promptResult').textContent;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.querySelector('.copy-btn');
        btn.textContent = '✓ コピーした！';
        setTimeout(() => btn.textContent = '📋 プロンプトをコピー', 2000);
    });
}
</script>

<?php elseif ($result && isset($result['error'])): ?>
<div class="flash flash-error">❌ <?= h($result['error']) ?></div>
<a href="illustrate.php<?= $prefillNovelId ? '?novel_id='.$prefillNovelId : '' ?>" class="btn btn-block mt-2">🎨 やり直す</a>

<?php else: ?>
<!-- ═══ FORM ═══ -->

<?php if ($prefillNovelTitle): ?>
    <div class="source-label">📜 <?= h($prefillNovelTitle) ?> から</div>
<?php endif; ?>

<form method="POST" class="mt-2" id="illustForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="generate_illust">

    <div class="form-group">
        <label class="form-label">AI</label>
        <select name="provider_id" class="form-select" required>
            <?php foreach ($enabledProviders as $ep): ?>
                <option value="<?= $ep['id'] ?>"><?= h($ep['display_name'] ?: $ep['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">シーンテキスト</label>
        <textarea name="scene_text" class="form-textarea" rows="8" placeholder="挿絵にしたいシーンの文章を貼り付け or 入力" required><?= h($prefillText) ?></textarea>
        <div class="form-hint">💡 ノベル本文の一部をコピペしてもOK</div>
    </div>

    <?php if ($residents): ?>
    <div class="form-group">
        <label class="form-label">👤 登場キャラ（外見情報を参照）</label>
        <div class="mat-chips">
            <?php foreach ($residents as $r): ?>
                <label class="mat-chip"><input type="checkbox" name="resident_ids[]" value="<?= $r['id'] ?>"><span><?= h($r['name']) ?></span></label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-group">
        <label class="form-label">追加指示（任意）</label>
        <textarea name="extra_directions" class="form-textarea" rows="3" placeholder="例: アニメ風で、夕暮れの光で、クローズアップ"></textarea>
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2" id="illustBtn">🎨 プロンプト生成</button>
</form>

<script>
document.getElementById('illustForm')?.addEventListener('submit', function() {
    var b = document.getElementById('illustBtn');
    b.disabled = true; b.textContent = '🎨 生成中…'; b.style.opacity = '0.6';
});
</script>

<?php endif; ?>

</div>
</body></html>

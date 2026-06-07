<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

// ── 処理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    switch ($_POST['_action'] ?? '') {

        // ─── 拠点 ───
        case 'create_base':
        case 'update_base':
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($name === '') { flash('拠点名を入れてね', 'error'); break; }

            if ($_POST['_action'] === 'create_base') {
                $stmt = $pdo->prepare("INSERT INTO bases (name, description) VALUES (?,?)");
                $stmt->execute([$name, $desc]);
                $id = $pdo->lastInsertId();
                flash('🏠 拠点を創造した');
            } else {
                $pdo->prepare("UPDATE bases SET name=?, description=? WHERE id=?")->execute([$name, $desc, $id]);
                flash('🏠 拠点を更新した');
            }
            header("Location: cradle.php?action=base&id={$id}");
            exit;

        case 'delete_base':
            $pdo->prepare("DELETE FROM bases WHERE id=?")->execute([$id]);
            flash('🗑 拠点を消去した');
            header("Location: cradle.php");
            exit;

        // ─── 住人 ───
        case 'create_resident':
        case 'update_resident':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { flash('名前を入れてね', 'error'); break; }

            $gender = $_POST['gender'] ?? '';
            $baseId = ($_POST['base_id'] ?? '') ?: null;
            $height = trim($_POST['height'] ?? '');
            $bodyType = $_POST['body_type'] ?? '';
            $physicalInfo = trim($_POST['physical_info'] ?? '');
            $hairstyle = trim($_POST['hairstyle'] ?? '');
            $eyeColor = trim($_POST['eye_color'] ?? '');
            $clothing = trim($_POST['clothing'] ?? '');
            $style = trim($_POST['style'] ?? '');
            $features = trim($_POST['features'] ?? '');
            $personality = trim($_POST['personality'] ?? '');

            // パラメーター
            $paramNames = $_POST['param_name'] ?? [];
            $paramValues = $_POST['param_value'] ?? [];
            $params = [];
            foreach ($paramNames as $i => $pn) {
                $pn = trim($pn);
                $pv = (int)($paramValues[$i] ?? 5);
                if ($pn !== '') {
                    $params[] = ['name' => $pn, 'value' => max(0, min(10, $pv))];
                }
            }
            $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);

            // 自由項目
            $cfNames = $_POST['cf_name'] ?? [];
            $cfValues = $_POST['cf_value'] ?? [];
            $customFields = [];
            foreach ($cfNames as $i => $cn) {
                $cn = trim($cn);
                $cv = trim($cfValues[$i] ?? '');
                if ($cn !== '') {
                    $customFields[] = ['name' => $cn, 'value' => $cv];
                }
            }
            $cfJson = json_encode($customFields, JSON_UNESCAPED_UNICODE);

            if ($_POST['_action'] === 'create_resident') {
                $stmt = $pdo->prepare("INSERT INTO residents (name,gender,base_id,height,body_type,physical_info,hairstyle,eye_color,clothing,style,features,personality,params,custom_fields) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$name,$gender,$baseId,$height,$bodyType,$physicalInfo,$hairstyle,$eyeColor,$clothing,$style,$features,$personality,$paramsJson,$cfJson]);
                $id = $pdo->lastInsertId();
                flash('🏠 住人が生まれた');
            } else {
                $stmt = $pdo->prepare("UPDATE residents SET name=?,gender=?,base_id=?,height=?,body_type=?,physical_info=?,hairstyle=?,eye_color=?,clothing=?,style=?,features=?,personality=?,params=?,custom_fields=? WHERE id=?");
                $stmt->execute([$name,$gender,$baseId,$height,$bodyType,$physicalInfo,$hairstyle,$eyeColor,$clothing,$style,$features,$personality,$paramsJson,$cfJson,$id]);
                flash('🏠 住人を更新した');
            }

            // 相手候補
            $pdo->prepare("DELETE FROM resident_candidates WHERE resident_id=?")->execute([$id]);
            $candidates = $_POST['candidates'] ?? [];
            foreach ($candidates as $cid) {
                $cid = (int)$cid;
                if ($cid > 0 && $cid !== $id) {
                    $pdo->prepare("INSERT IGNORE INTO resident_candidates (resident_id, candidate_id) VALUES (?,?)")->execute([$id, $cid]);
                }
            }

            header("Location: cradle.php?action=resident&id={$id}");
            exit;

        case 'delete_resident':
            $r = $pdo->prepare("SELECT base_id FROM residents WHERE id=?"); $r->execute([$id]); $bId = $r->fetchColumn();
            $pdo->prepare("DELETE FROM residents WHERE id=?")->execute([$id]);
            flash('🗑 住人を消去した');
            header("Location: " . ($bId ? "cradle.php?action=base&id={$bId}" : "cradle.php"));
            exit;
    }
}

// ── データ取得 ──
$allBases = $pdo->query("SELECT * FROM bases ORDER BY name")->fetchAll();
$allResidents = $pdo->query("SELECT r.*, b.name AS base_name FROM residents r LEFT JOIN bases b ON r.base_id=b.id ORDER BY r.name")->fetchAll();

$flash = getFlash();

// ── ナレッジ書き出し ──
if ($action === 'export') {
    $exportType = $_GET['type'] ?? 'all';
    $output = "# 世界設定ナレッジ\n\n";

    if ($exportType === 'bases' || $exportType === 'all') {
        $output .= "## 拠点一覧\n\n";
        foreach ($allBases as $b) {
            $output .= "### {$b['name']}\n";
            if ($b['description']) $output .= "{$b['description']}\n";
            // 所属住人
            $members = $pdo->prepare("SELECT name FROM residents WHERE base_id=? ORDER BY name");
            $members->execute([$b['id']]);
            $mNames = $members->fetchAll(PDO::FETCH_COLUMN);
            if ($mNames) $output .= "所属住人: " . implode('、', $mNames) . "\n";
            $output .= "\n";
        }
    }

    if ($exportType === 'residents' || $exportType === 'all') {
        $output .= "## 住人一覧\n\n";
        foreach ($allResidents as $r) {
            $output .= "### {$r['name']}\n";
            if ($r['gender']) $output .= "性別: {$r['gender']}\n";
            if ($r['base_name']) $output .= "所属: {$r['base_name']}\n";
            if ($r['height']) $output .= "身長: {$r['height']}\n";
            if ($r['body_type']) $output .= "体型: {$r['body_type']}\n";
            if ($r['physical_info']) $output .= "身体情報: {$r['physical_info']}\n";
            if ($r['hairstyle']) $output .= "髪型: {$r['hairstyle']}\n";
            if ($r['eye_color']) $output .= "目の色: {$r['eye_color']}\n";
            if ($r['clothing']) $output .= "服装: {$r['clothing']}\n";
            if ($r['style']) $output .= "系統: {$r['style']}\n";
            if ($r['features']) $output .= "特徴: {$r['features']}\n";
            if ($r['personality']) $output .= "性格: {$r['personality']}\n";

            // パラメーター
            $params = json_decode($r['params'] ?? '[]', true) ?: [];
            if ($params) {
                $pStrs = [];
                foreach ($params as $p) $pStrs[] = "{$p['name']}: {$p['value']}/10";
                $output .= "パラメーター: " . implode('、', $pStrs) . "\n";
            }

            // 相手候補
            $cands = $pdo->prepare("SELECT r2.name FROM resident_candidates rc JOIN residents r2 ON rc.candidate_id=r2.id WHERE rc.resident_id=?");
            $cands->execute([$r['id']]);
            $cNames = $cands->fetchAll(PDO::FETCH_COLUMN);
            if ($cNames) $output .= "相手候補: " . implode('、', $cNames) . "\n";

            // 自由項目
            $cfs = json_decode($r['custom_fields'] ?? '[]', true) ?: [];
            foreach ($cfs as $cf) {
                if ($cf['name'] && $cf['value']) $output .= "{$cf['name']}: {$cf['value']}\n";
            }
            $output .= "\n";
        }
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="knowledge_' . $exportType . '_' . date('Ymd') . '.txt"');
    echo $output;
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Cradle — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
/* ═══ CRADLE SPECIFIC ═══ */
.base-card {
    background: var(--card-bg); border: 1px solid rgba(124,92,255,0.12);
    border-radius: 14px; padding: 1.3rem 1.2rem; margin-bottom: 0.8rem;
    position: relative; overflow: hidden;
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
}
.base-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(100,200,255,0.3), transparent);
    opacity: 0.5;
}
.base-card a { text-decoration: none; color: inherit; display: block; }
.base-name {
    font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 1rem;
    color: var(--pearl); letter-spacing: 0.04em;
}
.base-count { font-size: 0.7rem; color: rgba(100,200,255,0.4); margin-top: 0.2rem; }

.resident-card {
    display: flex; align-items: center; gap: 0.8rem;
    background: var(--card-bg); border: 1px solid rgba(255,126,182,0.12);
    border-radius: 12px; padding: 1rem; margin-bottom: 0.6rem;
    text-decoration: none; color: inherit;
    -webkit-tap-highlight-color: transparent;
    transition: all 0.3s;
}
.resident-card:active { transform: scale(0.98); }
.resident-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    background: linear-gradient(135deg, rgba(124,92,255,0.2), rgba(255,126,182,0.2));
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.resident-info { flex: 1; min-width: 0; }
.resident-name { font-size: 0.95rem; color: var(--pearl); }
.resident-sub { font-size: 0.75rem; color: rgba(124,92,255,0.4); margin-top: 0.1rem; }

/* ═══ DETAIL FIELDS ═══ */
.detail-section {
    margin-top: 1.5rem;
}
.detail-heading {
    font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.65rem;
    letter-spacing: 0.2em; color: var(--orchid); opacity: 0.5;
    text-transform: uppercase; margin-bottom: 0.6rem;
}
.detail-row {
    display: flex; gap: 0.5rem; padding: 0.5rem 0;
    border-bottom: 1px solid rgba(124,92,255,0.06);
    font-size: 0.88rem;
}
.detail-label { color: rgba(124,92,255,0.5); flex-shrink: 0; min-width: 70px; }
.detail-value { color: var(--pearl); flex: 1; word-break: break-word; white-space: pre-wrap; }

/* ═══ PARAMETER BAR ═══ */
.param-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
.param-name { font-size: 0.8rem; color: rgba(124,92,255,0.5); min-width: 70px; flex-shrink: 0; }
.param-bar-bg {
    flex: 1; height: 6px; background: rgba(124,92,255,0.1);
    border-radius: 3px; overflow: hidden;
}
.param-bar-fill {
    height: 100%; border-radius: 3px;
    background: linear-gradient(90deg, var(--orchid), var(--blush));
    transition: width 0.5s ease;
}
.param-val { font-size: 0.7rem; color: rgba(124,92,255,0.4); width: 28px; text-align: right; }

/* ═══ FORM: PARAM SLIDER ═══ */
.param-input-row {
    display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.6rem;
}
.param-input-row input[type="text"] { flex: 1; }
.param-input-row input[type="range"] {
    flex: 2; -webkit-appearance: none; height: 4px;
    background: rgba(124,92,255,0.2); border-radius: 2px; outline: none;
}
.param-input-row input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none; width: 18px; height: 18px;
    border-radius: 50%; background: var(--orchid); cursor: pointer;
}
.param-input-row .param-display {
    width: 28px; text-align: center; font-size: 0.8rem; color: var(--orchid);
}
.param-remove {
    width: 28px; height: 28px; border-radius: 6px;
    background: rgba(239,77,107,0.1); border: 1px solid rgba(239,77,107,0.2);
    color: var(--ember); cursor: pointer; font-size: 0.8rem;
    display: flex; align-items: center; justify-content: center;
}

/* ═══ CUSTOM FIELD ═══ */
.cf-row {
    display: flex; gap: 0.4rem; margin-bottom: 0.5rem; align-items: center;
}
.cf-row input { flex: 1; font-size: 0.85rem; padding: 0.6rem 0.7rem; }

/* ═══ EXPORT PANEL ═══ */
.export-panel {
    background: var(--card-bg); border: 1px solid rgba(124,92,255,0.15);
    border-radius: 14px; padding: 1.3rem; margin-top: 1.5rem;
}
.export-title {
    font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.8rem;
    color: var(--orchid); letter-spacing: 0.1em; margin-bottom: 1rem;
}
.export-links { display: flex; flex-direction: column; gap: 0.5rem; }
.export-link {
    display: block; padding: 0.7rem 1rem;
    background: rgba(124,92,255,0.08); border: 1px solid rgba(124,92,255,0.15);
    border-radius: 8px; text-decoration: none;
    color: var(--pearl); font-size: 0.85rem; transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.export-link:active { background: rgba(124,92,255,0.15); }

/* ═══ CANDIDATE CHECKBOXES ═══ */
.candidate-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem;
}
.candidate-check {
    display: flex; align-items: center; gap: 0.4rem;
    padding: 0.5rem 0.6rem;
    background: rgba(255,255,255,0.4); border: 1px solid rgba(124,92,255,0.1);
    border-radius: 8px; cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.candidate-check input { accent-color: var(--orchid); }
.candidate-check span { font-size: 0.8rem; color: var(--pearl); }

/* body type toggle */
.body-options { display: none; }
.body-options.show { display: block; }
</style>
<?php include(__DIR__ . '/../pwa_head.php'); ?>
</head>
<body>
<div class="cosmos"></div>
<div class="noise"></div>

<div class="page">

<?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════
//  LIST — 拠点一覧
// ═══════════════════════════════════════
if ($action === 'list'): ?>

<header class="page-header">
    <a href="../index.php" class="back-btn">‹ Home</a>
    <span class="page-gate-icon">🏠</span>
</header>
<h1 class="page-title">Cradle</h1>
<p class="page-subtitle">World Building</p>

<?php if (empty($allBases)): ?>
    <div class="empty mt-3"><div class="empty-icon">🏠</div><p class="empty-text">まだ世界は空っぽ。<br>最初の拠点を創造しよう。</p></div>
<?php else: ?>
    <div class="mt-3">
    <?php foreach ($allBases as $b):
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM residents WHERE base_id=?");
        $cnt->execute([$b['id']]); $cnt = $cnt->fetchColumn();
    ?>
        <div class="base-card"><a href="cradle.php?action=base&id=<?= $b['id'] ?>">
            <div class="base-name">🏛 <?= h($b['name']) ?></div>
            <div class="base-count">👤 <?= $cnt ?> 人の住人</div>
            <?php if ($b['description']): ?>
                <div class="card-excerpt mt-1"><?= h(excerpt($b['description'], 80)) ?></div>
            <?php endif; ?>
        </a></div>
    <?php endforeach; ?>
    </div>

    <!-- 所属なし住人 -->
    <?php
    $unassigned = $pdo->query("SELECT * FROM residents WHERE base_id IS NULL ORDER BY name")->fetchAll();
    if ($unassigned): ?>
        <div class="detail-heading mt-3">所属なし</div>
        <?php foreach ($unassigned as $r): ?>
            <a href="cradle.php?action=resident&id=<?= $r['id'] ?>" class="resident-card">
                <div class="resident-avatar"><?= $r['gender'] === '男性' ? '♂' : ($r['gender'] === '女性' ? '♀' : '◇') ?></div>
                <div class="resident-info">
                    <div class="resident-name"><?= h($r['name']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Export -->
<?php if ($allBases || $allResidents): ?>
<div class="export-panel">
    <div class="export-title">📄 ナレッジ書き出し</div>
    <div class="export-links">
        <a href="cradle.php?action=export&type=all" class="export-link">📋 拠点＋住人（すべて）</a>
        <a href="cradle.php?action=export&type=bases" class="export-link">🏛 拠点のみ</a>
        <a href="cradle.php?action=export&type=residents" class="export-link">👤 住人のみ</a>
    </div>
</div>
<?php endif; ?>

<a href="cradle.php?action=create_base" class="fab">＋</a>

<?php
// ═══════════════════════════════════════
//  BASE — 拠点詳細
// ═══════════════════════════════════════
elseif ($action === 'base'):
    $base = $pdo->prepare("SELECT * FROM bases WHERE id=?"); $base->execute([$id]); $base = $base->fetch();
    if (!$base) { header("Location: cradle.php"); exit; }
    $members = $pdo->prepare("SELECT * FROM residents WHERE base_id=? ORDER BY name"); $members->execute([$id]); $members = $members->fetchAll();
?>

<header class="page-header">
    <a href="cradle.php" class="back-btn">‹ Cradle</a>
    <span class="page-gate-icon">🏛</span>
</header>
<h1 class="page-title"><?= h($base['name']) ?></h1>
<?php if ($base['description']): ?>
    <p class="card-excerpt mt-1" style="white-space:pre-wrap;"><?= h($base['description']) ?></p>
<?php endif; ?>

<div class="detail-heading mt-3">住人（<?= count($members) ?>人）</div>

<?php if (empty($members)): ?>
    <p class="empty-text mt-1" style="font-size:0.85rem;">まだ誰もいない。</p>
<?php else: ?>
    <?php foreach ($members as $r): ?>
        <a href="cradle.php?action=resident&id=<?= $r['id'] ?>" class="resident-card">
            <div class="resident-avatar"><?= $r['gender'] === '男性' ? '♂' : ($r['gender'] === '女性' ? '♀' : '◇') ?></div>
            <div class="resident-info">
                <div class="resident-name"><?= h($r['name']) ?></div>
                <div class="resident-sub"><?= h($r['style'] ?: ($r['body_type'] ?: '')) ?></div>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>

<div class="novel-actions mt-3">
    <a href="cradle.php?action=edit_base&id=<?= $base['id'] ?>" class="btn btn-primary btn-sm" style="flex:1;">編集</a>
    <form method="POST" action="cradle.php?id=<?= $base['id'] ?>" style="flex:1;" onsubmit="return confirm('拠点を消す？ 住人は残るよ')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="_action" value="delete_base">
        <button type="submit" class="btn btn-danger btn-sm btn-block">削除</button>
    </form>
</div>

<a href="cradle.php?action=create_resident&base=<?= $base['id'] ?>" class="fab">👤</a>

<?php
// ═══════════════════════════════════════
//  RESIDENT — 住人詳細
// ═══════════════════════════════════════
elseif ($action === 'resident'):
    $r = $pdo->prepare("SELECT r.*, b.name AS base_name FROM residents r LEFT JOIN bases b ON r.base_id=b.id WHERE r.id=?");
    $r->execute([$id]); $r = $r->fetch();
    if (!$r) { header("Location: cradle.php"); exit; }

    $params = json_decode($r['params'] ?? '[]', true) ?: [];
    $cfs = json_decode($r['custom_fields'] ?? '[]', true) ?: [];
    $cands = $pdo->prepare("SELECT r2.id, r2.name FROM resident_candidates rc JOIN residents r2 ON rc.candidate_id=r2.id WHERE rc.resident_id=?");
    $cands->execute([$id]); $cands = $cands->fetchAll();
?>

<header class="page-header">
    <a href="<?= $r['base_id'] ? "cradle.php?action=base&id={$r['base_id']}" : 'cradle.php' ?>" class="back-btn">‹ <?= $r['base_name'] ? h($r['base_name']) : 'Cradle' ?></a>
    <span class="page-gate-icon">👤</span>
</header>
<h1 class="page-title"><?= h($r['name']) ?></h1>

<div class="detail-section">
    <div class="detail-heading">基本情報</div>
    <?php if ($r['gender']): ?><div class="detail-row"><span class="detail-label">性別</span><span class="detail-value"><?= h($r['gender']) ?></span></div><?php endif; ?>
    <?php if ($r['base_name']): ?><div class="detail-row"><span class="detail-label">所属</span><span class="detail-value"><a href="cradle.php?action=base&id=<?= $r['base_id'] ?>" style="color:var(--orchid);text-decoration:none;">🏛 <?= h($r['base_name']) ?></a></span></div><?php endif; ?>
    <?php if ($r['height']): ?><div class="detail-row"><span class="detail-label">身長</span><span class="detail-value"><?= h($r['height']) ?></span></div><?php endif; ?>
    <?php if ($r['body_type']): ?><div class="detail-row"><span class="detail-label">体型</span><span class="detail-value"><?= h($r['body_type']) ?></span></div><?php endif; ?>
    <?php if ($r['physical_info']): ?><div class="detail-row"><span class="detail-label">身体情報</span><span class="detail-value"><?= h($r['physical_info']) ?></span></div><?php endif; ?>
</div>

<div class="detail-section">
    <div class="detail-heading">外見</div>
    <?php if ($r['hairstyle']): ?><div class="detail-row"><span class="detail-label">髪型</span><span class="detail-value"><?= h($r['hairstyle']) ?></span></div><?php endif; ?>
    <?php if ($r['eye_color']): ?><div class="detail-row"><span class="detail-label">目の色</span><span class="detail-value"><?= h($r['eye_color']) ?></span></div><?php endif; ?>
    <?php if ($r['clothing']): ?><div class="detail-row"><span class="detail-label">服装</span><span class="detail-value"><?= h($r['clothing']) ?></span></div><?php endif; ?>
    <?php if ($r['style']): ?><div class="detail-row"><span class="detail-label">系統</span><span class="detail-value"><?= h($r['style']) ?></span></div><?php endif; ?>
    <?php if ($r['features']): ?><div class="detail-row"><span class="detail-label">特徴</span><span class="detail-value"><?= h($r['features']) ?></span></div><?php endif; ?>
</div>

<?php if ($r['personality']): ?>
<div class="detail-section">
    <div class="detail-heading">性格</div>
    <div class="card-excerpt" style="white-space:pre-wrap;line-height:1.8;"><?= h($r['personality']) ?></div>
</div>
<?php endif; ?>

<?php if ($params): ?>
<div class="detail-section">
    <div class="detail-heading">パラメーター</div>
    <?php foreach ($params as $p): ?>
        <div class="param-row">
            <span class="param-name"><?= h($p['name']) ?></span>
            <div class="param-bar-bg"><div class="param-bar-fill" style="width:<?= $p['value'] * 10 ?>%"></div></div>
            <span class="param-val"><?= $p['value'] ?>/10</span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cands): ?>
<div class="detail-section">
    <div class="detail-heading">相手候補</div>
    <?php foreach ($cands as $c): ?>
        <a href="cradle.php?action=resident&id=<?= $c['id'] ?>" class="resident-card">
            <div class="resident-avatar">🤝</div>
            <div class="resident-info"><div class="resident-name"><?= h($c['name']) ?></div></div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cfs): ?>
<div class="detail-section">
    <div class="detail-heading">その他</div>
    <?php foreach ($cfs as $cf): ?>
        <?php if ($cf['name'] && $cf['value']): ?>
            <div class="detail-row"><span class="detail-label"><?= h($cf['name']) ?></span><span class="detail-value"><?= h($cf['value']) ?></span></div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="novel-actions mt-3">
    <a href="cradle.php?action=edit_resident&id=<?= $r['id'] ?>" class="btn btn-primary btn-sm" style="flex:1;">編集</a>
    <a href="resident_illust.php?id=<?= $r['id'] ?>" class="btn btn-sm" style="flex:1;">🎨 イラスト</a>
    <a href="chat.php?id=<?= $r['id'] ?>" class="btn btn-sm" style="flex:1;">💬 対話</a>
    <form method="POST" action="cradle.php?id=<?= $r['id'] ?>" style="flex:1;" onsubmit="return confirm('この住人を消す？')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="_action" value="delete_resident">
        <button type="submit" class="btn btn-danger btn-sm btn-block">削除</button>
    </form>
</div>

<?php
// ═══════════════════════════════════════
//  CREATE / EDIT BASE
// ═══════════════════════════════════════
elseif ($action === 'create_base' || $action === 'edit_base'):
    $base = null;
    if ($action === 'edit_base' && $id) {
        $base = $pdo->prepare("SELECT * FROM bases WHERE id=?"); $base->execute([$id]); $base = $base->fetch();
    }
?>

<header class="page-header">
    <a href="<?= $base ? "cradle.php?action=base&id={$id}" : 'cradle.php' ?>" class="back-btn">‹ 戻る</a>
    <span class="page-gate-icon">🏛</span>
</header>
<h1 class="page-title"><?= $base ? '拠点を編集' : '拠点を創造' ?></h1>

<form method="POST" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="<?= $base ? 'update_base' : 'create_base' ?>">

    <div class="form-group">
        <label class="form-label">拠点名</label>
        <input type="text" name="name" class="form-input" placeholder="例：黄昏の学園" value="<?= h($base['name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label">どんな場所か</label>
        <textarea name="description" class="form-textarea" rows="5" placeholder="この場所の説明・設定"><?= h($base['description'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary btn-block mt-2">🏛 <?= $base ? '更新' : '創造する' ?></button>
</form>

<?php
// ═══════════════════════════════════════
//  CREATE / EDIT RESIDENT
// ═══════════════════════════════════════
elseif ($action === 'create_resident' || $action === 'edit_resident'):
    $r = null; $rParams = []; $rCfs = []; $rCands = [];
    $preBase = $_GET['base'] ?? '';

    if ($action === 'edit_resident' && $id) {
        $r = $pdo->prepare("SELECT * FROM residents WHERE id=?"); $r->execute([$id]); $r = $r->fetch();
        if ($r) {
            $rParams = json_decode($r['params'] ?? '[]', true) ?: [];
            $rCfs = json_decode($r['custom_fields'] ?? '[]', true) ?: [];
            $cStmt = $pdo->prepare("SELECT candidate_id FROM resident_candidates WHERE resident_id=?");
            $cStmt->execute([$id]); $rCands = $cStmt->fetchAll(PDO::FETCH_COLUMN);
            $preBase = $r['base_id'] ?? '';
        }
    }

    // デフォルトパラメーター
    if (empty($rParams)) {
        $rParams = [
            ['name' => '社交性', 'value' => 5],
            ['name' => '感情表現', 'value' => 5],
            ['name' => '行動力', 'value' => 5],
            ['name' => '好奇心', 'value' => 5],
        ];
    }

    // 他の住人（相手候補用）
    $otherResidents = $pdo->query("SELECT id, name FROM residents" . ($id ? " WHERE id != {$id}" : "") . " ORDER BY name")->fetchAll();
?>

<header class="page-header">
    <a href="<?= $r ? "cradle.php?action=resident&id={$id}" : ($preBase ? "cradle.php?action=base&id={$preBase}" : 'cradle.php') ?>" class="back-btn">‹ 戻る</a>
    <span class="page-gate-icon">👤</span>
</header>
<h1 class="page-title"><?= $r ? '住人を編集' : '住人を創造' ?></h1>

<form method="POST" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="<?= $r ? 'update_resident' : 'create_resident' ?>">

    <!-- 基本情報 -->
    <div class="detail-heading">基本情報</div>

    <div class="form-group">
        <label class="form-label">名前</label>
        <input type="text" name="name" class="form-input" value="<?= h($r['name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
        <label class="form-label">性別</label>
        <select name="gender" class="form-select" id="genderSelect">
            <option value="">— 選択 —</option>
            <option value="男性" <?= ($r['gender'] ?? '') === '男性' ? 'selected' : '' ?>>男性</option>
            <option value="女性" <?= ($r['gender'] ?? '') === '女性' ? 'selected' : '' ?>>女性</option>
            <option value="その他" <?= ($r['gender'] ?? '') === 'その他' ? 'selected' : '' ?>>その他</option>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">所属拠点</label>
        <select name="base_id" class="form-select">
            <option value="">— なし —</option>
            <?php foreach ($allBases as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $preBase == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">身長</label>
        <input type="text" name="height" class="form-input" placeholder="例：172cm" value="<?= h($r['height'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">体型</label>
        <!-- 男性用 -->
        <div class="body-options" id="bodyMale">
            <select name="body_type" class="form-select body-select-m">
                <option value="">— 選択 —</option>
                <?php foreach (['細身','標準','筋肉質','がっしり','大柄','ぽっちゃり','小柄'] as $bt): ?>
                    <option value="<?= $bt ?>" <?= ($r['body_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- 女性用 -->
        <div class="body-options" id="bodyFemale">
            <select name="body_type" class="form-select body-select-f">
                <option value="">— 選択 —</option>
                <?php foreach (['華奢','スレンダー','標準','ふくよか','ぽっちゃり','がっしり','小柄'] as $bt): ?>
                    <option value="<?= $bt ?>" <?= ($r['body_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- その他 -->
        <div class="body-options" id="bodyOther">
            <input type="text" name="body_type" class="form-input body-input-o" placeholder="自由入力" value="<?= h($r['body_type'] ?? '') ?>">
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">その他身体情報</label>
        <textarea name="physical_info" class="form-textarea" rows="2" placeholder="特技、癖、その他の設定など"><?= h($r['physical_info'] ?? '') ?></textarea>
    </div>

    <!-- 外見 -->
    <div class="detail-heading mt-3">外見</div>

    <div class="form-group">
        <label class="form-label">髪型</label>
        <input type="text" name="hairstyle" class="form-input" placeholder="例：肩までの黒髪、外ハネ" value="<?= h($r['hairstyle'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">目の色</label>
        <input type="text" name="eye_color" class="form-input" placeholder="例：琥珀色" value="<?= h($r['eye_color'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">服装</label>
        <textarea name="clothing" class="form-textarea" rows="2" placeholder="普段の服装"><?= h($r['clothing'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
        <label class="form-label">系統</label>
        <input type="text" name="style" class="form-input" placeholder="例：ストリート、クラシカル" value="<?= h($r['style'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">特徴</label>
        <textarea name="features" class="form-textarea" rows="2" placeholder="見た目の印象的な特徴"><?= h($r['features'] ?? '') ?></textarea>
    </div>

    <!-- 性格 -->
    <div class="detail-heading mt-3">性格</div>
    <div class="form-group">
        <textarea name="personality" class="form-textarea" rows="4" placeholder="性格、口調、行動パターン"><?= h($r['personality'] ?? '') ?></textarea>
    </div>

    <!-- パラメーター -->
    <div class="detail-heading mt-3">パラメーター（0〜10）</div>
    <div id="paramsContainer">
        <?php foreach ($rParams as $i => $p): ?>
            <div class="param-input-row">
                <input type="text" name="param_name[]" class="form-input" value="<?= h($p['name']) ?>" placeholder="項目名" style="flex:1;font-size:0.85rem;padding:0.5rem;">
                <input type="range" name="param_value[]" min="0" max="10" value="<?= $p['value'] ?>" oninput="this.nextElementSibling.textContent=this.value">
                <span class="param-display"><?= $p['value'] ?></span>
                <button type="button" class="param-remove" onclick="this.parentElement.remove()">✕</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm mt-1" onclick="addParam()">📊 パラメーター追加</button>

    <!-- 相手候補 -->
    <?php if ($otherResidents): ?>
    <div class="detail-heading mt-3">相手候補</div>
    <div class="candidate-grid">
        <?php foreach ($otherResidents as $or): ?>
            <label class="candidate-check">
                <input type="checkbox" name="candidates[]" value="<?= $or['id'] ?>" <?= in_array($or['id'], $rCands) ? 'checked' : '' ?>>
                <span><?= h($or['name']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 自由項目 -->
    <div class="detail-heading mt-3">自由項目</div>
    <div id="cfContainer">
        <?php foreach ($rCfs as $cf): ?>
            <div class="cf-row">
                <input type="text" name="cf_name[]" class="form-input" value="<?= h($cf['name']) ?>" placeholder="項目名">
                <input type="text" name="cf_value[]" class="form-input" value="<?= h($cf['value']) ?>" placeholder="内容">
                <button type="button" class="param-remove" onclick="this.parentElement.remove()">✕</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm mt-1" onclick="addCf()">＋ 自由項目追加</button>

    <button type="submit" class="btn btn-primary btn-block mt-3">👤 <?= $r ? '更新' : '創造する' ?></button>
</form>

<?php endif; ?>

</div>

<script>
// ═══ 性別で体型の選択肢を切り替え ═══
const genderSel = document.getElementById('genderSelect');
if (genderSel) {
    function updateBodyType() {
        const g = genderSel.value;
        document.querySelectorAll('.body-options').forEach(el => el.classList.remove('show'));
        // 非表示の体型inputをdisabledにして送信しない
        document.querySelectorAll('.body-select-m, .body-select-f, .body-input-o').forEach(el => el.disabled = true);

        if (g === '男性') {
            document.getElementById('bodyMale').classList.add('show');
            document.querySelector('.body-select-m').disabled = false;
        } else if (g === '女性') {
            document.getElementById('bodyFemale').classList.add('show');
            document.querySelector('.body-select-f').disabled = false;
        } else if (g) {
            document.getElementById('bodyOther').classList.add('show');
            document.querySelector('.body-input-o').disabled = false;
        }
    }
    genderSel.addEventListener('change', updateBodyType);
    updateBodyType();
}

// ═══ パラメーター追加 ═══
function addParam() {
    const c = document.getElementById('paramsContainer');
    const row = document.createElement('div');
    row.className = 'param-input-row';
    row.innerHTML = `
        <input type="text" name="param_name[]" class="form-input" placeholder="項目名" style="flex:1;font-size:0.85rem;padding:0.5rem;">
        <input type="range" name="param_value[]" min="0" max="10" value="5" oninput="this.nextElementSibling.textContent=this.value">
        <span class="param-display">5</span>
        <button type="button" class="param-remove" onclick="this.parentElement.remove()">✕</button>
    `;
    c.appendChild(row);
}

// ═══ 自由項目追加 ═══
function addCf() {
    const c = document.getElementById('cfContainer');
    const row = document.createElement('div');
    row.className = 'cf-row';
    row.innerHTML = `
        <input type="text" name="cf_name[]" class="form-input" placeholder="項目名">
        <input type="text" name="cf_value[]" class="form-input" placeholder="内容">
        <button type="button" class="param-remove" onclick="this.parentElement.remove()">✕</button>
    `;
    c.appendChild(row);
}
</script>
</body>
</html>

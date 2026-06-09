<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    switch ($_POST['_action'] ?? '') {

        case 'create_project':
        case 'update_project':
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $ci = trim($_POST['custom_instructions'] ?? '');
            $knowledge = trim($_POST['knowledge'] ?? '');
            $color = $_POST['color'] ?? '#7c5cff';
            if ($name === '') { flash('名前を入れてね', 'error'); break; }

            if ($_POST['_action'] === 'create_project') {
                $stmt = $pdo->prepare("INSERT INTO projects (name, description, custom_instructions, knowledge, color) VALUES (?,?,?,?,?)");
                $stmt->execute([$name, $desc, $ci, $knowledge, $color]);
                $id = $pdo->lastInsertId();
                flash('✦ プロジェクト作成');
            } else {
                $stmt = $pdo->prepare("UPDATE projects SET name=?, description=?, custom_instructions=?, knowledge=?, color=? WHERE id=?");
                $stmt->execute([$name, $desc, $ci, $knowledge, $color, $id]);
                flash('✦ 更新しました');
            }

            // リンク処理
            $pdo->prepare("DELETE FROM project_links WHERE project_id=?")->execute([$id]);
            $linkLabels = $_POST['link_label'] ?? [];
            $linkUrls = $_POST['link_url'] ?? [];
            $linkTypes = $_POST['link_type'] ?? [];
            foreach ($linkLabels as $i => $label) {
                $label = trim($label); $url = trim($linkUrls[$i] ?? ''); $type = $linkTypes[$i] ?? 'other';
                if ($label && $url) {
                    $pdo->prepare("INSERT INTO project_links (project_id, label, url, link_type, sort_order) VALUES (?,?,?,?,?)")
                        ->execute([$id, $label, $url, $type, $i]);
                }
            }

            // 拠点紐づけ
            $pdo->prepare("DELETE FROM project_bases WHERE project_id=?")->execute([$id]);
            foreach ($_POST['base_ids'] ?? [] as $bid) {
                $pdo->prepare("INSERT IGNORE INTO project_bases (project_id, base_id) VALUES (?,?)")->execute([$id, (int)$bid]);
            }

            // 住人紐づけ
            $pdo->prepare("DELETE FROM project_residents WHERE project_id=?")->execute([$id]);
            foreach ($_POST['resident_ids'] ?? [] as $rid) {
                $pdo->prepare("INSERT IGNORE INTO project_residents (project_id, resident_id) VALUES (?,?)")->execute([$id, (int)$rid]);
            }

            header("Location: memoria.php?action=view&id={$id}");
            exit;

        case 'add_persona':
            $name = trim($_POST['persona_name'] ?? '');
            $desc = trim($_POST['persona_desc'] ?? '');
            $projId = (int)($_POST['project_id'] ?? 0);
            if ($name && $projId) {
                $pdo->prepare("INSERT INTO personas (name, description, project_id) VALUES (?,?,?)")->execute([$name, $desc, $projId]);
                flash('👤 ペルソナ追加');
            }
            header("Location: memoria.php?action=view&id={$projId}");
            exit;

        case 'delete_persona':
            $pId = (int)($_POST['persona_id'] ?? 0);
            $pe = $pdo->prepare("SELECT project_id FROM personas WHERE id=?"); $pe->execute([$pId]); $projId = $pe->fetchColumn();
            $pdo->prepare("DELETE FROM personas WHERE id=?")->execute([$pId]);
            flash('👤 ペルソナ削除');
            header("Location: memoria.php?action=view&id={$projId}");
            exit;

        case 'delete_project':
            $pdo->prepare("DELETE FROM projects WHERE id=?")->execute([$id]);
            flash('🗑 プロジェクト削除');
            header("Location: memoria.php");
            exit;
    }
}

$projects = $pdo->query("SELECT * FROM projects ORDER BY updated_at DESC")->fetchAll();
$allBases = $pdo->query("SELECT id, name FROM bases ORDER BY name")->fetchAll();
$allResidents = $pdo->query("SELECT id, name FROM residents ORDER BY name")->fetchAll();

$project = null; $projLinks = []; $projPersonas = []; $projNovels = [];
$projBaseIds = []; $projResidentIds = []; $projBases = []; $projResidents = [];

if ($id > 0) {
    $project = $pdo->prepare("SELECT * FROM projects WHERE id=?"); $project->execute([$id]); $project = $project->fetch();
    if ($project) {
        $projLinks = $pdo->prepare("SELECT * FROM project_links WHERE project_id=? ORDER BY sort_order"); $projLinks->execute([$id]); $projLinks = $projLinks->fetchAll();
        $projPersonas = $pdo->prepare("SELECT * FROM personas WHERE project_id=? ORDER BY name"); $projPersonas->execute([$id]); $projPersonas = $projPersonas->fetchAll();
        $projNovels = $pdo->prepare("SELECT id, title, status, updated_at FROM novels WHERE project_id=? ORDER BY updated_at DESC"); $projNovels->execute([$id]); $projNovels = $projNovels->fetchAll();
        $projBaseIds = $pdo->prepare("SELECT base_id FROM project_bases WHERE project_id=?"); $projBaseIds->execute([$id]); $projBaseIds = $projBaseIds->fetchAll(PDO::FETCH_COLUMN);
        $projResidentIds = $pdo->prepare("SELECT resident_id FROM project_residents WHERE project_id=?"); $projResidentIds->execute([$id]); $projResidentIds = $projResidentIds->fetchAll(PDO::FETCH_COLUMN);
        $projBases = $pdo->prepare("SELECT b.* FROM bases b JOIN project_bases pb ON b.id=pb.base_id WHERE pb.project_id=? ORDER BY b.name"); $projBases->execute([$id]); $projBases = $projBases->fetchAll();
        $projResidents = $pdo->prepare("SELECT r.* FROM residents r JOIN project_residents pr ON r.id=pr.resident_id WHERE pr.project_id=? ORDER BY r.name"); $projResidents->execute([$id]); $projResidents = $projResidents->fetchAll();
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Memoria — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<?php include(__DIR__ . '/../pwa_head.php'); ?>
<style>
.link-row { display: flex; gap: 0.4rem; margin-bottom: 0.5rem; align-items: center; }
.link-row input, .link-row select { font-size: 0.85rem; padding: 0.6rem 0.7rem; }
.link-row .form-input { flex: 1; }
.link-type-select { width: 80px; flex-shrink: 0; }
.remove-link { width: 32px; height: 32px; flex-shrink: 0; background: rgba(239,77,107,0.1); border: 1px solid rgba(239,77,107,0.2); border-radius: 8px; color: var(--ember); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
.project-color { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 0.4rem; }
.linked-novel { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid rgba(124,92,255,0.08); }
.linked-novel a { color: var(--pearl); text-decoration: none; font-size: 0.9rem; }
.persona-item { display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid rgba(124,92,255,0.08); }
.section-heading { font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.7rem; letter-spacing: 0.2em; color: var(--orchid); opacity: 0.5; margin: 1.5rem 0 0.8rem; text-transform: uppercase; }
.ci-block { background: rgba(255,255,255,0.4); border: 1px solid rgba(124,92,255,0.1); border-radius: 10px; padding: 1rem; font-size: 0.85rem; line-height: 1.8; white-space: pre-wrap; word-wrap: break-word; color: rgba(58,47,74,0.6); max-height: 300px; overflow-y: auto; }
.cradle-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.cradle-chip { display: flex; align-items: center; gap: 0.3rem; padding: 0.4rem 0.7rem; background: rgba(255,255,255,0.4); border: 1px solid rgba(124,92,255,0.12); border-radius: 8px; cursor: pointer; -webkit-tap-highlight-color: transparent; transition: all 0.2s; }
.cradle-chip input { accent-color: var(--orchid); width: 14px; height: 14px; }
.cradle-chip span { font-size: 0.78rem; color: var(--pearl); }
.cradle-chip:has(input:checked) { border-color: var(--orchid); background: rgba(124,92,255,0.12); }
.linked-cradle-item { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.7rem; background: rgba(100,200,255,0.08); border: 1px solid rgba(100,200,255,0.15); border-radius: 8px; font-size: 0.78rem; color: var(--pearl); text-decoration: none; margin: 0.2rem; }
</style>
</head>
<body>
<div class="cosmos"></div><div class="noise"></div>
<div class="page">
<?php if ($flash): ?><div class="flash flash-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>
<header class="page-header"><a href="../index.php" class="back-btn">‹ Home</a><span class="page-gate-icon">📚</span></header>
<h1 class="page-title">Memoria</h1><p class="page-subtitle">Projects</p>

<?php if (empty($projects)): ?>
    <div class="empty mt-3"><div class="empty-icon">✦</div><p class="empty-text">まだ何も記録されていない。<br>最初の記憶を、ここに。</p></div>
<?php else: ?>
    <div class="mt-3">
    <?php foreach ($projects as $p): ?>
        <div class="card"><a href="memoria.php?action=view&id=<?= $p['id'] ?>">
            <div class="card-title"><span class="project-color" style="background:<?= h($p['color']) ?>"></span><?= h($p['name']) ?></div>
            <div class="card-excerpt"><?= h(excerpt($p['description'] ?? '', 80)) ?></div>
            <div class="card-meta mt-1"><?= date('Y.m.d', strtotime($p['updated_at'])) ?></div>
        </a></div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<a href="memoria.php?action=create" class="fab">＋</a>

<?php elseif ($action === 'view' && $project): ?>
<header class="page-header"><a href="memoria.php" class="back-btn">‹ Memoria</a><span class="page-gate-icon">📚</span></header>
<h1 class="page-title"><span class="project-color" style="background:<?= h($project['color']) ?>"></span><?= h($project['name']) ?></h1>
<?php if ($project['description']): ?><p class="card-excerpt"><?= nl2br(h($project['description'])) ?></p><?php endif; ?>

<!-- Cradle紐づけ -->
<?php if ($projBases || $projResidents): ?>
<p class="section-heading">🌀 Cradle 紐づけ</p>
<div>
    <?php foreach ($projBases as $b): ?><a href="cradle.php?action=base&id=<?= $b['id'] ?>" class="linked-cradle-item">🏛 <?= h($b['name']) ?></a><?php endforeach; ?>
    <?php foreach ($projResidents as $r): ?><a href="cradle.php?action=resident&id=<?= $r['id'] ?>" class="linked-cradle-item">👤 <?= h($r['name']) ?></a><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($projLinks): ?>
<p class="section-heading">Links</p>
<?php foreach ($projLinks as $lnk): ?>
    <div class="linked-novel"><a href="<?= h($lnk['url']) ?>" target="_blank" rel="noopener">
        <?php $icons = ['chatgpt'=>'🤖','gemini'=>'💎','claude'=>'🟠','gpts'=>'🧩','other'=>'🔗']; echo ($icons[$lnk['link_type']] ?? '🔗') . ' ' . h($lnk['label']); ?>
    </a><span class="tag"><?= h($lnk['link_type']) ?></span></div>
<?php endforeach; endif; ?>

<?php if ($project['custom_instructions']): ?>
<p class="section-heading">Custom Instructions</p>
<div class="ci-block"><?= h($project['custom_instructions']) ?></div>
<?php endif; ?>
<?php if ($project['knowledge']): ?>
<p class="section-heading">Knowledge</p>
<div class="ci-block"><?= h($project['knowledge']) ?></div>
<?php endif; ?>

<p class="section-heading">Personas</p>
<?php foreach ($projPersonas as $pe): ?>
    <div class="persona-item"><div><strong style="font-size:0.9rem;">👤 <?= h($pe['name']) ?></strong><?php if($pe['description']):?><div class="card-excerpt"><?= h(excerpt($pe['description'],60)) ?></div><?php endif;?></div>
        <form method="POST" onsubmit="return confirm('削除する？')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="delete_persona"><input type="hidden" name="persona_id" value="<?= $pe['id'] ?>"><button type="submit" class="remove-link">✕</button></form>
    </div>
<?php endforeach; ?>
<form method="POST" class="mt-2"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="add_persona"><input type="hidden" name="project_id" value="<?= $project['id'] ?>">
    <div style="display:flex;gap:0.4rem;"><input type="text" name="persona_name" class="form-input" placeholder="名前" style="flex:1;font-size:0.85rem;padding:0.6rem;"><input type="text" name="persona_desc" class="form-input" placeholder="説明" style="flex:1;font-size:0.85rem;padding:0.6rem;"><button type="submit" class="btn btn-sm">👤+</button></div>
</form>

<?php if ($projNovels): ?>
<p class="section-heading">Linked Novels</p>
<?php foreach ($projNovels as $n): ?>
    <div class="linked-novel"><a href="desire.php?action=view&id=<?= $n['id'] ?>">📜 <?= h($n['title']) ?></a><span class="badge badge-<?= $n['status'] ?>"><?= $n['status'] ?></span></div>
<?php endforeach; endif; ?>

<div style="display:flex;gap:0.5rem;margin-top:2rem;padding-top:1.5rem;border-top:1px solid rgba(124,92,255,0.1);">
    <a href="memoria.php?action=edit&id=<?= $project['id'] ?>" class="btn btn-primary btn-sm" style="flex:1;">編集</a>
    <form method="POST" style="flex:1;" onsubmit="return confirm('プロジェクトを削除する？')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="delete_project"><button type="submit" class="btn btn-danger btn-sm btn-block">削除</button></form>
</div>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
<header class="page-header"><a href="<?= $action === 'edit' ? "memoria.php?action=view&id={$id}" : 'memoria.php' ?>" class="back-btn">‹ 戻る</a><span class="page-gate-icon">📚</span></header>
<h1 class="page-title"><?= $action === 'create' ? '新しい記憶' : '編集' ?></h1>

<form method="POST" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="<?= $action === 'create' ? 'create_project' : 'update_project' ?>">

    <div class="form-group"><label class="form-label">Name</label><input type="text" name="name" class="form-input" value="<?= h($project['name'] ?? '') ?>" required></div>
    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-textarea" rows="3"><?= h($project['description'] ?? '') ?></textarea></div>
    <div class="form-group"><label class="form-label">Color</label><input type="color" name="color" value="<?= h($project['color'] ?? '#7c5cff') ?>" style="width:50px;height:36px;border:none;background:transparent;cursor:pointer;"></div>
    <div class="form-group"><label class="form-label">Custom Instructions</label><textarea name="custom_instructions" class="form-textarea" rows="6" placeholder="このプロジェクトのカスタム指示"><?= h($project['custom_instructions'] ?? '') ?></textarea></div>
    <div class="form-group"><label class="form-label">Knowledge</label><textarea name="knowledge" class="form-textarea" rows="6" placeholder="ナレッジ・設定資料"><?= h($project['knowledge'] ?? '') ?></textarea></div>

    <!-- Cradle紐づけ: 拠点 -->
    <?php if ($allBases): ?>
    <div class="form-group">
        <label class="form-label">🏛 紐づけ拠点</label>
        <div class="cradle-chips">
            <?php foreach ($allBases as $b): ?>
                <label class="cradle-chip"><input type="checkbox" name="base_ids[]" value="<?= $b['id'] ?>" <?= in_array($b['id'], $projBaseIds) ? 'checked' : '' ?>><span><?= h($b['name']) ?></span></label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cradle紐づけ: 住人 -->
    <?php if ($allResidents): ?>
    <div class="form-group">
        <label class="form-label">👤 紐づけ住人</label>
        <div class="cradle-chips">
            <?php foreach ($allResidents as $r): ?>
                <label class="cradle-chip"><input type="checkbox" name="resident_ids[]" value="<?= $r['id'] ?>" <?= in_array($r['id'], $projResidentIds) ? 'checked' : '' ?>><span><?= h($r['name']) ?></span></label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Links -->
    <div class="form-group">
        <label class="form-label">Links</label>
        <div id="linksContainer">
            <?php foreach ($projLinks as $lnk): ?>
            <div class="link-row">
                <input type="text" name="link_label[]" class="form-input" placeholder="ラベル" value="<?= h($lnk['label']) ?>">
                <input type="url" name="link_url[]" class="form-input" placeholder="URL" value="<?= h($lnk['url']) ?>">
                <select name="link_type[]" class="form-select link-type-select">
                    <?php foreach (['chatgpt','gemini','claude','gpts','other'] as $lt): ?><option value="<?= $lt ?>" <?= $lnk['link_type'] === $lt ? 'selected' : '' ?>><?= $lt ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="remove-link" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm mt-1" onclick="addLinkRow()">🔗 リンク追加</button>
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2"><?= $action === 'create' ? '✦ 作成' : '✦ 更新' ?></button>
</form>
<?php endif; ?>
</div>
<script>
function addLinkRow() {
    const c = document.getElementById('linksContainer');
    const row = document.createElement('div'); row.className = 'link-row';
    row.innerHTML = '<input type="text" name="link_label[]" class="form-input" placeholder="ラベル"><input type="url" name="link_url[]" class="form-input" placeholder="URL"><select name="link_type[]" class="form-select link-type-select"><option value="chatgpt">chatgpt</option><option value="gemini">gemini</option><option value="claude">claude</option><option value="gpts">gpts</option><option value="other">other</option></select><button type="button" class="remove-link" onclick="this.parentElement.remove()">✕</button>';
    c.appendChild(row);
}
</script>
</body></html>

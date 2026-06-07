<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

// ── 処理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    switch ($_POST['_action'] ?? '') {

        case 'create':
        case 'update':
            $url = trim($_POST['url'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $folderId = ($_POST['folder_id'] ?? '') ?: null;
            $tagsRaw = trim($_POST['tags'] ?? '');

            if (!$url || !$title) { flash('URLとタイトルは必須だよ', 'error'); break; }

            if ($_POST['_action'] === 'create') {
                $stmt = $pdo->prepare("INSERT INTO bookmarks (url, title, description, folder_id) VALUES (?,?,?,?)");
                $stmt->execute([$url, $title, $desc, $folderId]);
                $id = $pdo->lastInsertId();
                flash('🔖 ブックマーク追加');
            } else {
                $stmt = $pdo->prepare("UPDATE bookmarks SET url=?, title=?, description=?, folder_id=? WHERE id=?");
                $stmt->execute([$url, $title, $desc, $folderId, $id]);
                flash('🔖 更新しました');
            }

            // タグ
            $pdo->prepare("DELETE FROM bookmark_tag_map WHERE bookmark_id=?")->execute([$id]);
            if ($tagsRaw !== '') {
                $tagNames = array_unique(array_filter(array_map('trim', preg_split('/[,、\s]+/', $tagsRaw))));
                foreach ($tagNames as $tn) {
                    $pdo->prepare("INSERT IGNORE INTO bookmark_tags (name) VALUES (?)")->execute([$tn]);
                    $tagId = $pdo->query("SELECT id FROM bookmark_tags WHERE name=" . $pdo->quote($tn))->fetchColumn();
                    $pdo->prepare("INSERT IGNORE INTO bookmark_tag_map (bookmark_id, tag_id) VALUES (?,?)")->execute([$id, $tagId]);
                }
            }

            header("Location: sanctum.php");
            exit;

        case 'create_folder':
            $name = trim($_POST['folder_name'] ?? '');
            if ($name) {
                $pdo->prepare("INSERT INTO bookmark_folders (name) VALUES (?)")->execute([$name]);
                flash('📁 フォルダ作成');
            }
            header("Location: sanctum.php");
            exit;

        case 'delete':
            $pdo->prepare("DELETE FROM bookmarks WHERE id=?")->execute([$id]);
            flash('🗑 削除しました');
            header("Location: sanctum.php");
            exit;
    }
}

// ── データ取得 ──
$folders = $pdo->query("SELECT * FROM bookmark_folders ORDER BY name")->fetchAll();
$allTags = $pdo->query("SELECT id, name FROM bookmark_tags ORDER BY name")->fetchAll();

$filterFolder = $_GET['folder'] ?? '';
$filterTag = $_GET['tag'] ?? '';

$where = [];
$params = [];
if ($filterFolder) { $where[] = "b.folder_id = ?"; $params[] = $filterFolder; }
if ($filterTag) {
    $where[] = "b.id IN (SELECT bookmark_id FROM bookmark_tag_map m JOIN bookmark_tags t ON m.tag_id=t.id WHERE t.name=?)";
    $params[] = $filterTag;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT b.*, f.name AS folder_name,
    GROUP_CONCAT(bt.name SEPARATOR ',') AS tags
    FROM bookmarks b
    LEFT JOIN bookmark_folders f ON b.folder_id=f.id
    LEFT JOIN bookmark_tag_map btm ON b.id=btm.bookmark_id
    LEFT JOIN bookmark_tags bt ON btm.tag_id=bt.id
    {$whereSQL}
    GROUP BY b.id
    ORDER BY b.created_at DESC
");
$stmt->execute($params);
$bookmarks = $stmt->fetchAll();

// 編集用
$bookmark = null;
$bmTags = [];
if ($id > 0 && ($action === 'edit')) {
    $bookmark = $pdo->prepare("SELECT * FROM bookmarks WHERE id=?");
    $bookmark->execute([$id]);
    $bookmark = $bookmark->fetch();
    if ($bookmark) {
        $bmTags = $pdo->prepare("SELECT t.name FROM bookmark_tags t JOIN bookmark_tag_map m ON t.id=m.tag_id WHERE m.bookmark_id=?");
        $bmTags->execute([$id]);
        $bmTags = $bmTags->fetchAll(PDO::FETCH_COLUMN);
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Sanctum — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
.bm-card {
    position: relative;
}
.bm-url {
    font-size: 0.7rem; color: rgba(124,92,255,0.3);
    word-break: break-all; margin-top: 0.4rem;
}
.bm-desc {
    font-size: 0.85rem; color: rgba(58,47,74,0.5);
    line-height: 1.6; margin-top: 0.3rem;
}
.bm-actions {
    display: flex; gap: 0.4rem; margin-top: 0.6rem;
}
.bm-visit {
    display: inline-block; padding: 0.3rem 0.7rem;
    background: rgba(124,92,255,0.1);
    border: 1px solid rgba(124,92,255,0.2);
    border-radius: 8px; color: var(--orchid);
    font-size: 0.7rem; text-decoration: none;
    letter-spacing: 0.05em;
}
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

<?php if ($action === 'list'): ?>

<header class="page-header">
    <a href="../index.php" class="back-btn">‹ Home</a>
    <span class="page-gate-icon">🔖</span>
</header>

<h1 class="page-title">Sanctum</h1>
<p class="page-subtitle">Bookmarks</p>

<!-- Filters -->
<div class="filters mt-2">
    <a href="sanctum.php" class="filter-chip <?= (!$filterFolder && !$filterTag) ? 'active' : '' ?>">All</a>
    <?php foreach ($folders as $f): ?>
        <a href="sanctum.php?folder=<?= $f['id'] ?>" class="filter-chip <?= $filterFolder == $f['id'] ? 'active' : '' ?>">📁 <?= h($f['name']) ?></a>
    <?php endforeach; ?>
    <?php foreach ($allTags as $t): ?>
        <a href="sanctum.php?tag=<?= urlencode($t['name']) ?>" class="filter-chip <?= $filterTag === $t['name'] ? 'active' : '' ?>"><?= h($t['name']) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($bookmarks)): ?>
    <div class="empty mt-3">
        <div class="empty-icon">🔖</div>
        <p class="empty-text">まだブックマークがありません。<br>最初の一件を、ここに。</p>
    </div>
<?php else: ?>
    <?php foreach ($bookmarks as $bm): ?>
        <div class="card bm-card">
            <div class="flex-between">
                <span class="card-title"><?= h($bm['title']) ?></span>
                <?php if ($bm['folder_name']): ?><span class="tag">📁 <?= h($bm['folder_name']) ?></span><?php endif; ?>
            </div>
            <?php if ($bm['description']): ?>
                <div class="bm-desc"><?= nl2br(h($bm['description'])) ?></div>
            <?php endif; ?>
            <div class="bm-url"><?= h($bm['url']) ?></div>
            <?php if ($bm['tags']): ?>
                <div class="card-tags">
                    <?php foreach (explode(',', $bm['tags']) as $t): ?>
                        <span class="tag"><?= h(trim($t)) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="bm-actions">
                <a href="<?= h($bm['url']) ?>" target="_blank" rel="noopener" class="bm-visit">🔗 開く</a>
                <a href="sanctum.php?action=edit&id=<?= $bm['id'] ?>" class="bm-visit">✏️ 編集</a>
                <form method="POST" action="sanctum.php?id=<?= $bm['id'] ?>" onsubmit="return confirm('削除する？')" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="_action" value="delete">
                    <button type="submit" class="bm-visit" style="cursor:pointer;background:rgba(239,77,107,0.1);border-color:rgba(239,77,107,0.2);color:var(--ember);">🗑</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<a href="sanctum.php?action=create" class="fab">＋</a>

<?php elseif ($action === 'create' || $action === 'edit'): ?>

<header class="page-header">
    <a href="sanctum.php" class="back-btn">‹ Sanctum</a>
    <span class="page-gate-icon">🔖</span>
</header>

<h1 class="page-title"><?= $action === 'create' ? '新しいブックマーク' : '編集' ?></h1>

<form method="POST" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="<?= $action === 'create' ? 'create' : 'update' ?>">

    <div class="form-group">
        <label class="form-label">URL</label>
        <input type="url" name="url" class="form-input" placeholder="https://..." value="<?= h($bookmark['url'] ?? '') ?>" required>
    </div>

    <div class="form-group">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-input" placeholder="サイト名" value="<?= h($bookmark['title'] ?? '') ?>" required>
    </div>

    <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-textarea" rows="3" placeholder="説明・補足"><?= h($bookmark['description'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label">Folder</label>
        <select name="folder_id" class="form-select">
            <option value="">— なし —</option>
            <?php foreach ($folders as $f): ?>
                <option value="<?= $f['id'] ?>" <?= ($bookmark['folder_id'] ?? '') == $f['id'] ? 'selected' : '' ?>><?= h($f['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">Tags</label>
        <input type="text" name="tags" class="form-input" placeholder="カンマ区切り" value="<?= h(implode(', ', $bmTags)) ?>">
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2">
        <?= $action === 'create' ? '🔖 追加' : '🔖 更新' ?>
    </button>
</form>

<div class="section-divider"></div>

<form method="POST" class="mt-2">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="create_folder">
    <div class="form-group">
        <label class="form-label">New Folder</label>
        <div style="display:flex;gap:0.5rem;">
            <input type="text" name="folder_name" class="form-input" placeholder="フォルダ名" style="flex:1;">
            <button type="submit" class="btn btn-sm">📁+</button>
        </div>
    </div>
</form>

<?php endif; ?>

</div>
</body>
</html>

<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

// ── 処理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    switch ($_POST['_action'] ?? '') {

        case 'upload':
            if (!empty($_FILES['image']['name'])) {
                $file = $_FILES['image'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];

                if (!in_array($ext, $allowed)) { flash('対応形式: jpg, png, gif, webp', 'error'); break; }
                if ($file['size'] > MAX_UPLOAD_SIZE) { flash('ファイルが大きすぎる（10MBまで）', 'error'); break; }

                $uploadDir = realpath(__DIR__ . '/../uploads/images/');
                if (!$uploadDir) { mkdir(__DIR__ . '/../uploads/images/', 0755, true); $uploadDir = realpath(__DIR__ . '/../uploads/images/'); }

                $filename = uniqid('img_') . '.' . $ext;
                move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename);

                $folderId = ($_POST['folder_id'] ?? '') ?: null;
                $desc = trim($_POST['description'] ?? '');
                $tagsRaw = trim($_POST['tags'] ?? '');

                $stmt = $pdo->prepare("INSERT INTO images (filename, original_name, folder_id, description) VALUES (?,?,?,?)");
                $stmt->execute([$filename, $file['name'], $folderId, $desc]);
                $imgId = $pdo->lastInsertId();

                if ($tagsRaw !== '') {
                    $tagNames = array_unique(array_filter(array_map('trim', preg_split('/[,、\s]+/', $tagsRaw))));
                    foreach ($tagNames as $tn) {
                        $pdo->prepare("INSERT IGNORE INTO image_tags (name) VALUES (?)")->execute([$tn]);
                        $tagId = $pdo->query("SELECT id FROM image_tags WHERE name=" . $pdo->quote($tn))->fetchColumn();
                        $pdo->prepare("INSERT IGNORE INTO image_tag_map (image_id, tag_id) VALUES (?,?)")->execute([$imgId, $tagId]);
                    }
                }
                flash('🖼️ アップロード完了');
            }
            header("Location: reverie.php");
            exit;

        case 'create_folder':
            $name = trim($_POST['folder_name'] ?? '');
            if ($name) { $pdo->prepare("INSERT INTO image_folders (name) VALUES (?)")->execute([$name]); flash('📁 フォルダ作成'); }
            header("Location: reverie.php");
            exit;

        case 'delete':
            $img = $pdo->prepare("SELECT filename FROM images WHERE id=?"); $img->execute([$id]); $img = $img->fetch();
            if ($img) {
                @unlink(__DIR__ . '/../uploads/images/' . $img['filename']);
                $pdo->prepare("DELETE FROM images WHERE id=?")->execute([$id]);
                flash('🗑 削除しました');
            }
            header("Location: reverie.php");
            exit;
    }
}

// ── データ取得 ──
$folders = $pdo->query("SELECT * FROM image_folders ORDER BY name")->fetchAll();
$allTags = $pdo->query("SELECT id, name FROM image_tags ORDER BY name")->fetchAll();

$filterFolder = $_GET['folder'] ?? '';
$filterTag = $_GET['tag'] ?? '';

$where = []; $params = [];
if ($filterFolder) { $where[] = "i.folder_id = ?"; $params[] = $filterFolder; }
if ($filterTag) {
    $where[] = "i.id IN (SELECT image_id FROM image_tag_map m JOIN image_tags t ON m.tag_id=t.id WHERE t.name=?)";
    $params[] = $filterTag;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT i.*, f.name AS folder_name FROM images i LEFT JOIN image_folders f ON i.folder_id=f.id {$whereSQL} ORDER BY i.created_at DESC");
$stmt->execute($params);
$images = $stmt->fetchAll();

// 各画像がどのノベルで使われているか検索
$imageNovelLinks = [];
$allNovels = $pdo->query("SELECT id, title, body FROM novels")->fetchAll();
foreach ($allNovels as $nv) {
    preg_match_all('/\{\{img:(\d+)\}\}/', $nv['body'] ?? '', $matches);
    foreach ($matches[1] as $imgId) {
        $imgId = (int)$imgId;
        if (!isset($imageNovelLinks[$imgId])) $imageNovelLinks[$imgId] = [];
        $imageNovelLinks[$imgId][] = ['id' => $nv['id'], 'title' => $nv['title']];
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Reverie — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
.upload-area {
    border: 2px dashed rgba(124,92,255,0.2); border-radius: 14px;
    padding: 2rem 1rem; text-align: center; margin-bottom: 1rem;
    position: relative; transition: border-color 0.3s;
}
.upload-icon { font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.4; }
.upload-text { font-size: 0.8rem; color: rgba(124,92,255,0.4); }

/* ═══ LIGHTBOX ═══ */
.lightbox {
    display: none; position: fixed; inset: 0;
    background: rgba(20,15,28,0.95); z-index: 600;
    flex-direction: column; padding: 0;
    overflow-y: auto; -webkit-overflow-scrolling: touch;
}
.lightbox.show { display: flex; }
.lightbox-top {
    position: sticky; top: 0; z-index: 2;
    display: flex; justify-content: flex-end;
    padding: 1rem;
}
.lightbox-close {
    background: rgba(20,15,28,0.6); border: 1px solid rgba(124,92,255,0.2);
    color: var(--pearl); font-size: 1.2rem; cursor: pointer;
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    -webkit-tap-highlight-color: transparent;
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
}
.lightbox-img-wrap { padding: 0 1rem; flex-shrink: 0; }
.lightbox-img-wrap img {
    width: 100%; max-height: 60vh; object-fit: contain;
    border-radius: 8px; display: block;
}
.lightbox-info {
    padding: 1rem 1.2rem; flex-shrink: 0;
}
.lightbox-desc {
    font-size: 0.85rem; color: rgba(58,47,74,0.5); margin-bottom: 0.8rem;
}
.lightbox-novels {
    margin-top: 0.8rem;
}
.lightbox-novel-heading {
    font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.65rem;
    letter-spacing: 0.15em; color: var(--orchid); opacity: 0.5;
    text-transform: uppercase; margin-bottom: 0.5rem;
}
.lightbox-novel-link {
    display: block; padding: 0.6rem 0.8rem;
    background: rgba(124,92,255,0.08);
    border: 1px solid rgba(124,92,255,0.12);
    border-radius: 8px; margin-bottom: 0.4rem;
    text-decoration: none; color: var(--pearl);
    font-size: 0.85rem; transition: all 0.2s;
}
.lightbox-novel-link:active {
    background: rgba(124,92,255,0.15);
}
.lightbox-novel-link::before {
    content: '📜 ';
}
.lightbox-actions {
    display: flex; gap: 0.6rem; margin-top: 1rem;
    padding: 1rem 1.2rem 2rem;
}

/* used indicator on grid */
.gallery-item-used::after {
    content: '📜'; position: absolute;
    top: 4px; right: 4px;
    font-size: 0.6rem;
    background: rgba(20,15,28,0.7);
    border-radius: 4px; padding: 2px 4px;
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
    <span class="page-gate-icon">🖼️</span>
</header>
<h1 class="page-title">Reverie</h1>
<p class="page-subtitle">Gallery</p>

<div class="filters mt-2">
    <a href="reverie.php" class="filter-chip <?= (!$filterFolder && !$filterTag) ? 'active' : '' ?>">All</a>
    <?php foreach ($folders as $f): ?>
        <a href="reverie.php?folder=<?= $f['id'] ?>" class="filter-chip <?= $filterFolder == $f['id'] ? 'active' : '' ?>">📁 <?= h($f['name']) ?></a>
    <?php endforeach; ?>
    <?php foreach ($allTags as $t): ?>
        <a href="reverie.php?tag=<?= urlencode($t['name']) ?>" class="filter-chip <?= $filterTag === $t['name'] ? 'active' : '' ?>"><?= h($t['name']) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($images)): ?>
    <div class="empty"><div class="empty-icon">🖼️</div><p class="empty-text">まだ画像がありません。<br>最初の一枚を、ここに。</p></div>
<?php else: ?>
    <div class="gallery-grid">
        <?php foreach ($images as $img):
            $hasNovel = !empty($imageNovelLinks[$img['id']]);
        ?>
            <div class="gallery-item <?= $hasNovel ? 'gallery-item-used' : '' ?>"
                 onclick="openLightbox(<?= $img['id'] ?>)"
                 data-id="<?= $img['id'] ?>"
                 data-filename="<?= h($img['filename']) ?>"
                 data-desc="<?= h($img['description'] ?? '') ?>"
                 data-original="<?= h($img['original_name']) ?>">
                <img src="../uploads/images/<?= h($img['filename']) ?>" alt="" loading="lazy">
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ═══ LIGHTBOX ═══ -->
<div class="lightbox" id="lightbox">
    <div class="lightbox-top">
        <button class="lightbox-close" onclick="closeLightbox()">✕</button>
    </div>
    <div class="lightbox-img-wrap">
        <img id="lbImage" src="" alt="">
    </div>
    <div class="lightbox-info">
        <div class="lightbox-desc" id="lbDesc"></div>
        <div class="lightbox-novels" id="lbNovels"></div>
    </div>
    <div class="lightbox-actions">
        <form method="POST" id="lbDeleteForm" onsubmit="return confirm('削除する？ ノベルに挿入されてるスチルも消えるよ')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="_action" value="delete">
            <button type="submit" class="btn btn-danger btn-sm">🗑 削除</button>
        </form>
    </div>
</div>

<a href="reverie.php?action=upload" class="fab">＋</a>

<?php elseif ($action === 'upload'): ?>

<header class="page-header">
    <a href="reverie.php" class="back-btn">‹ Reverie</a>
    <span class="page-gate-icon">🖼️</span>
</header>
<h1 class="page-title">Upload</h1>

<form method="POST" enctype="multipart/form-data" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="upload">

    <div class="upload-area">
        <div class="upload-icon">🖼️</div>
        <p class="upload-text" id="uploadLabel">タップして画像を選択</p>
        <input type="file" name="image" accept="image/*" required
               style="position:absolute;inset:0;opacity:0;cursor:pointer;"
               onchange="document.getElementById('uploadLabel').textContent = this.files[0]?.name || 'タップして画像を選択'">
    </div>

    <div class="form-group">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-input" placeholder="この画像について">
    </div>

    <div class="form-group">
        <label class="form-label">Folder</label>
        <select name="folder_id" class="form-select">
            <option value="">— なし —</option>
            <?php foreach ($folders as $f): ?><option value="<?= $f['id'] ?>"><?= h($f['name']) ?></option><?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">Tags</label>
        <input type="text" name="tags" class="form-input" placeholder="カンマ区切り">
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2">🖼️ アップロード</button>
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

<script>
// ノベルリンクデータをJSに渡す
const novelLinks = <?= json_encode($imageNovelLinks, JSON_UNESCAPED_UNICODE) ?>;

function openLightbox(imgId) {
    const item = document.querySelector(`.gallery-item[data-id="${imgId}"]`);
    if (!item) return;

    document.getElementById('lbImage').src = '../uploads/images/' + item.dataset.filename;
    document.getElementById('lbDesc').textContent = item.dataset.desc || item.dataset.original;
    document.getElementById('lbDeleteForm').action = 'reverie.php?action=list&id=' + imgId;

    // ノベルリンク表示
    const novelsDiv = document.getElementById('lbNovels');
    const links = novelLinks[imgId] || [];
    if (links.length > 0) {
        let html = '<div class="lightbox-novel-heading">この画像が使われている物語</div>';
        links.forEach(n => {
            html += `<a href="desire.php?action=view&id=${n.id}" class="lightbox-novel-link">${n.title}</a>`;
        });
        novelsDiv.innerHTML = html;
    } else {
        novelsDiv.innerHTML = '';
    }

    document.getElementById('lightbox').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    document.getElementById('lightbox').classList.remove('show');
    document.body.style.overflow = '';
}
</script>
</body>
</html>

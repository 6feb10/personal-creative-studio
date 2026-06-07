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
            $title = trim($_POST['title'] ?? '');
            $body = $_POST['body'] ?? '';
            $projectId = ($_POST['project_id'] ?? '') ?: null;
            $personaId = ($_POST['persona_id'] ?? '') ?: null;
            $status = $_POST['status'] ?? 'draft';
            $tagsRaw = trim($_POST['tags'] ?? '');

            if ($title === '') {
                flash('タイトルを入れてね', 'error');
                break;
            }

            if ($_POST['_action'] === 'create') {
                $stmt = $pdo->prepare("INSERT INTO novels (title, body, project_id, persona_id, status) VALUES (?,?,?,?,?)");
                $stmt->execute([$title, $body, $projectId, $personaId, $status]);
                $id = $pdo->lastInsertId();
                flash('✨ 新しい物語が生まれた');
            } else {
                $stmt = $pdo->prepare("UPDATE novels SET title=?, body=?, project_id=?, persona_id=?, status=? WHERE id=?");
                $stmt->execute([$title, $body, $projectId, $personaId, $status, $id]);
                flash('📝 更新したよ');
            }

            // タグ処理
            $pdo->prepare("DELETE FROM novel_tag_map WHERE novel_id=?")->execute([$id]);
            if ($tagsRaw !== '') {
                $tagNames = array_unique(array_filter(array_map('trim', preg_split('/[,、\s]+/', $tagsRaw))));
                foreach ($tagNames as $tn) {
                    $pdo->prepare("INSERT IGNORE INTO novel_tags (name) VALUES (?)")->execute([$tn]);
                    $tagId = $pdo->query("SELECT id FROM novel_tags WHERE name=" . $pdo->quote($tn))->fetchColumn();
                    $pdo->prepare("INSERT IGNORE INTO novel_tag_map (novel_id, tag_id) VALUES (?,?)")->execute([$id, $tagId]);
                }
            }

            header("Location: desire.php?action=view&id={$id}");
            exit;

        case 'delete':
            $pdo->prepare("DELETE FROM novels WHERE id=?")->execute([$id]);
            flash('🗑 削除しました');
            header("Location: desire.php");
            exit;
    }
}

// ── データ取得 ──
$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll();
$personas = $pdo->query("SELECT id, name, project_id FROM personas ORDER BY name")->fetchAll();
$allTags = $pdo->query("SELECT id, name FROM novel_tags ORDER BY name")->fetchAll();

// ギャラリー画像（スチル挿入用）
$galleryImages = $pdo->query("
    SELECT i.id, i.filename, i.description, i.original_name, f.name AS folder_name
    FROM images i LEFT JOIN image_folders f ON i.folder_id = f.id
    ORDER BY i.created_at DESC
")->fetchAll();

// 個別取得
$novel = null;
$novelTags = [];
if ($id > 0) {
    $novel = $pdo->prepare("
        SELECT n.*, p.name AS project_name, pe.name AS persona_name
        FROM novels n
        LEFT JOIN projects p ON n.project_id = p.id
        LEFT JOIN personas pe ON n.persona_id = pe.id
        WHERE n.id = ?
    ");
    $novel->execute([$id]);
    $novel = $novel->fetch();

    if ($novel) {
        $novelTags = $pdo->prepare("SELECT t.name FROM novel_tags t JOIN novel_tag_map m ON t.id=m.tag_id WHERE m.novel_id=?");
        $novelTags->execute([$id]);
        $novelTags = $novelTags->fetchAll(PDO::FETCH_COLUMN);
    }
}

// リスト取得
$filterProject = $_GET['project'] ?? '';
$filterTag = $_GET['tag'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$where = [];
$params = [];
if ($filterProject) { $where[] = "n.project_id = ?"; $params[] = $filterProject; }
if ($filterTag) {
    $where[] = "n.id IN (SELECT novel_id FROM novel_tag_map m JOIN novel_tags t ON m.tag_id=t.id WHERE t.name=?)";
    $params[] = $filterTag;
}
if ($filterStatus) { $where[] = "n.status = ?"; $params[] = $filterStatus; }

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("
    SELECT n.*, p.name AS project_name
    FROM novels n LEFT JOIN projects p ON n.project_id = p.id
    {$whereSQL}
    ORDER BY n.updated_at DESC
");
$stmt->execute($params);
$novels = $stmt->fetchAll();

// スチル画像マップ（ID → info）
$imageMap = [];
foreach ($galleryImages as $gi) {
    $imageMap[$gi['id']] = $gi;
}

$flash = getFlash();

/**
 * ノベル本文をレンダリング
 * {{img:ID}} → スチル画像に変換
 */
function renderNovelBody(string $body, array $imageMap): string {
    // テキストを {{img:ID}} で分割
    $segments = preg_split('/\{\{img:(\d+)\}\}/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';

    for ($i = 0; $i < count($segments); $i++) {
        if ($i % 2 === 0) {
            // テキスト部分
            $text = $segments[$i];
            if (trim($text) !== '') {
                $html .= '<div class="novel-text-block">' . nl2br(h($text)) . '</div>';
            }
        } else {
            // 画像ID部分
            $imgId = (int)$segments[$i];
            if (isset($imageMap[$imgId])) {
                $img = $imageMap[$imgId];
                $html .= '<div class="still-frame">';
                $html .= '<img src="../uploads/images/' . h($img['filename']) . '" alt="' . h($img['description'] ?? '') . '" class="still-image" loading="lazy">';
                if ($img['description']) {
                    $html .= '<div class="still-caption">' . h($img['description']) . '</div>';
                }
                $html .= '</div>';
            } else {
                $html .= '<div class="still-frame still-missing">[ 画像が見つからない ]</div>';
            }
        }
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Desire — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
.novel-read-title {
    font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 1.3rem;
    background: linear-gradient(135deg, var(--pearl), var(--ember));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text; margin-bottom: 0.5rem; line-height: 1.4;
}
.novel-read-meta {
    font-size: 0.75rem; color: rgba(124,92,255,0.4);
    margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.8rem;
}
.novel-actions {
    display: flex; gap: 0.5rem; margin-top: 2rem; padding-top: 1.5rem;
    border-top: 1px solid rgba(124,92,255,0.1);
}
.char-count { text-align: right; font-size: 0.7rem; color: rgba(124,92,255,0.3); margin-top: 0.3rem; }

/* ═══ STILL / CG ═══ */
.novel-text-block {
    font-size: 1rem; line-height: 2.2; color: var(--pearl);
    font-weight: 300; white-space: pre-wrap; word-wrap: break-word;
}
.still-frame {
    margin: 2rem -1rem; position: relative; overflow: hidden;
    animation: still-fadein 0.6s ease;
}
.still-frame::before {
    content: ''; position: absolute; inset: 0;
    border-top: 1px solid rgba(124,92,255,0.15);
    border-bottom: 1px solid rgba(124,92,255,0.15);
    z-index: 2; pointer-events: none;
}
.still-frame::after {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 40px;
    background: linear-gradient(to bottom, var(--void), transparent);
    z-index: 2; pointer-events: none;
}
.still-image { width: 100%; display: block; filter: brightness(0.95) contrast(1.05); }
.still-caption {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 2rem 1rem 1rem;
    background: linear-gradient(to top, rgba(20,15,28,0.85), transparent);
    font-size: 0.8rem; color: rgba(58,47,74,0.6);
    font-style: italic; z-index: 3;
}
.still-missing {
    padding: 2rem; text-align: center; color: rgba(124,92,255,0.3);
    font-size: 0.85rem; border: 1px dashed rgba(124,92,255,0.15);
    border-radius: 10px; margin: 2rem 0;
}
@keyframes still-fadein { from { opacity: 0; transform: scale(1.02); } to { opacity: 1; transform: scale(1); } }

/* ═══ IMAGE PICKER ═══ */
.picker-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(20,15,28,0.95); z-index: 500; flex-direction: column;
}
.picker-overlay.show { display: flex; }
.picker-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.2rem; border-bottom: 1px solid rgba(124,92,255,0.15); flex-shrink: 0;
}
.picker-title { font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.85rem; color: var(--orchid); letter-spacing: 0.1em; }
.picker-close {
    background: none; border: none; color: var(--pearl); font-size: 1.3rem;
    cursor: pointer; -webkit-tap-highlight-color: transparent;
}
.picker-body { flex: 1; overflow-y: auto; padding: 0.8rem; -webkit-overflow-scrolling: touch; }
.picker-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
.picker-item {
    aspect-ratio: 1; overflow: hidden; border-radius: 6px; cursor: pointer;
    position: relative; border: 2px solid transparent; transition: border-color 0.2s;
    -webkit-tap-highlight-color: transparent;
}
.picker-item:active { border-color: var(--orchid); }
.picker-item img { width: 100%; height: 100%; object-fit: cover; }
.picker-item-label {
    position: absolute; bottom: 0; left: 0; right: 0; padding: 0.3rem;
    background: rgba(20,15,28,0.7); font-size: 0.55rem;
    color: rgba(58,47,74,0.6); text-align: center;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.picker-empty { text-align: center; padding: 3rem 1rem; color: rgba(124,92,255,0.3); font-size: 0.9rem; }
.insert-still-btn {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.5rem 1rem; background: rgba(124,92,255,0.1);
    border: 1px solid rgba(124,92,255,0.25); border-radius: 8px;
    color: var(--orchid); font-family: 'Noto Sans JP', sans-serif;
    font-size: 0.85rem; cursor: pointer; margin-bottom: 0.5rem;
    -webkit-tap-highlight-color: transparent; transition: all 0.3s;
}
.insert-still-btn:active { background: rgba(124,92,255,0.2); }
.still-count { font-size: 0.7rem; color: rgba(124,92,255,0.35); }
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
    <span class="page-gate-icon">📖</span>
</header>
<h1 class="page-title">Desire</h1>
<p class="page-subtitle">Novels</p>

<div class="filters mt-2">
    <a href="desire.php" class="filter-chip <?= (!$filterProject && !$filterTag && !$filterStatus) ? 'active' : '' ?>">All</a>
    <a href="desire.php?status=draft" class="filter-chip <?= $filterStatus === 'draft' ? 'active' : '' ?>">Draft</a>
    <a href="desire.php?status=complete" class="filter-chip <?= $filterStatus === 'complete' ? 'active' : '' ?>">Complete</a>
    <?php foreach ($allTags as $t): ?>
        <a href="desire.php?tag=<?= urlencode($t['name']) ?>" class="filter-chip <?= $filterTag === $t['name'] ? 'active' : '' ?>"><?= h($t['name']) ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($novels)): ?>
    <div class="empty"><div class="empty-icon">📖</div><p class="empty-text">まだ物語がありません。<br>最初のストーリーを、ここに。</p></div>
<?php else: ?>
    <?php foreach ($novels as $n): ?>
        <?php preg_match_all('/\{\{img:\d+\}\}/', $n['body'] ?? '', $sm); $sc = count($sm[0]); ?>
        <div class="card"><a href="desire.php?action=view&id=<?= $n['id'] ?>">
            <div class="flex-between mb-1">
                <span class="card-title"><?= h($n['title']) ?></span>
                <span class="badge badge-<?= $n['status'] ?>"><?= $n['status'] ?></span>
            </div>
            <div class="card-meta">
                <?= date('Y.m.d', strtotime($n['updated_at'])) ?>
                <?php if ($n['project_name']): ?> · <?= h($n['project_name']) ?><?php endif; ?>
                <?php if ($sc > 0): ?> · <span class="still-count">🖼 <?= $sc ?>枚</span><?php endif; ?>
            </div>
            <div class="card-excerpt"><?= h(excerpt(preg_replace('/\{\{img:\d+\}\}/', '', $n['body']), 100)) ?></div>
        </a></div>
    <?php endforeach; ?>
<?php endif; ?>
<a href="desire.php?action=create" class="fab">＋</a>

<?php elseif ($action === 'view' && $novel): ?>
<header class="page-header">
    <a href="desire.php" class="back-btn">‹ Desire</a>
    <span class="page-gate-icon">📖</span>
</header>
<h1 class="novel-read-title"><?= h($novel['title']) ?></h1>
<div class="novel-read-meta">
    <span><?= date('Y.m.d H:i', strtotime($novel['updated_at'])) ?></span>
    <span class="badge badge-<?= $novel['status'] ?>"><?= $novel['status'] ?></span>
    <?php if ($novel['project_name']): ?><span>📁 <?= h($novel['project_name']) ?></span><?php endif; ?>
    <?php if ($novel['persona_name']): ?><span>👤 <?= h($novel['persona_name']) ?></span><?php endif; ?>
</div>
<?php if ($novelTags): ?>
    <div class="card-tags mb-2"><?php foreach ($novelTags as $t): ?><span class="tag"><?= h($t) ?></span><?php endforeach; ?></div>
<?php endif; ?>
<div class="section-divider"></div>
<div class="novel-body"><?= renderNovelBody($novel['body'], $imageMap) ?></div>
<div class="novel-actions">
    <a href="desire.php?action=edit&id=<?= $novel['id'] ?>" class="btn btn-primary btn-sm" style="flex:1;">編集</a>
    <a href="illustrate.php?novel_id=<?= $novel['id'] ?>" class="btn btn-sm" style="flex:1;">🎨 挿絵</a>
    <a href="forge.php?action=generate&cont_novel=<?= $novel['id'] ?>" class="btn btn-sm" style="flex:1;">🔮 続きを鍛造</a>
    <button onclick="confirmDelete(<?= $novel['id'] ?>)" class="btn btn-danger btn-sm" style="flex:1;">削除</button>
</div>
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <p class="modal-text">この物語を削除しますか？</p>
        <div class="modal-actions">
            <button onclick="closeModal()" class="btn btn-sm">やめる</button>
            <form method="POST" style="flex:1;"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="delete"><button type="submit" class="btn btn-danger btn-sm btn-block">削除</button></form>
        </div>
    </div>
</div>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
<header class="page-header">
    <a href="<?= $action === 'edit' ? "desire.php?action=view&id={$id}" : 'desire.php' ?>" class="back-btn">‹ 戻る</a>
    <span class="page-gate-icon">📖</span>
</header>
<h1 class="page-title"><?= $action === 'create' ? '新しい物語' : '書き直す' ?></h1>

<form method="POST" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="<?= $action === 'create' ? 'create' : 'update' ?>">

    <div class="form-group">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-input" placeholder="無題の物語" value="<?= h($novel['title'] ?? '') ?>" required>
    </div>

    <div class="form-group">
        <label class="form-label">Body</label>
        <button type="button" class="insert-still-btn" onclick="openPicker()">🖼 スチル挿入</button>
        <textarea name="body" class="form-textarea" id="novelBody" placeholder="ここに、言葉を。

スチルを挿入したい場所にカーソルを置いて
「🖼 スチル挿入」ボタンをタップ" rows="15"><?= h($novel['body'] ?? '') ?></textarea>
        <div class="char-count" id="charCount">0 文字</div>
        <div class="form-hint">💡 スチルは本文中の好きな場所に何枚でも挿入できるよ</div>
    </div>

    <div class="form-group">
        <label class="form-label">Project</label>
        <select name="project_id" class="form-select" id="projectSelect">
            <option value="">— なし —</option>
            <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= ($novel['project_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">Persona</label>
        <select name="persona_id" class="form-select" id="personaSelect">
            <option value="">— なし —</option>
            <?php foreach ($personas as $pe): ?><option value="<?= $pe['id'] ?>" data-project="<?= $pe['project_id'] ?>" <?= ($novel['persona_id'] ?? '') == $pe['id'] ? 'selected' : '' ?>><?= h($pe['name']) ?></option><?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="form-label">Tags</label>
        <input type="text" name="tags" class="form-input" placeholder="カンマ区切り" value="<?= h(implode(', ', $novelTags)) ?>">
        <div class="form-hint">例: 恋愛, ファンタジー, 日常</div>
    </div>

    <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            <option value="draft" <?= ($novel['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft — 執筆中</option>
            <option value="complete" <?= ($novel['status'] ?? '') === 'complete' ? 'selected' : '' ?>>Complete — 完成</option>
        </select>
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2"><?= $action === 'create' ? '✨ 生み出す' : '📝 更新する' ?></button>
</form>

<!-- Image Picker -->
<div class="picker-overlay" id="pickerModal">
    <div class="picker-header">
        <span class="picker-title">🖼 スチルを選択</span>
        <button class="picker-close" onclick="closePicker()">✕</button>
    </div>
    <div class="picker-body">
        <?php if (empty($galleryImages)): ?>
            <div class="picker-empty">ギャラリーに画像がないよ。<br>先に Reverie でアップロードしてね。</div>
        <?php else: ?>
            <div class="picker-grid">
                <?php foreach ($galleryImages as $gi): ?>
                    <div class="picker-item" onclick="insertStill(<?= $gi['id'] ?>, '<?= h(addslashes($gi['description'] ?: $gi['original_name'])) ?>')">
                        <img src="../uploads/images/<?= h($gi['filename']) ?>" alt="" loading="lazy">
                        <div class="picker-item-label"><?= h($gi['description'] ?: $gi['original_name']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>

<script>
const body = document.getElementById('novelBody');
const counter = document.getElementById('charCount');
if (body && counter) {
    const update = () => {
        const text = body.value.replace(/\{\{img:\d+\}\}/g, '');
        counter.textContent = text.length.toLocaleString() + ' 文字';
    };
    body.addEventListener('input', update);
    update();
}

const projSel = document.getElementById('projectSelect');
const persSel = document.getElementById('personaSelect');
if (projSel && persSel) {
    const allOpts = [...persSel.querySelectorAll('option[data-project]')];
    projSel.addEventListener('change', () => {
        const pid = projSel.value;
        allOpts.forEach(o => { o.style.display = (!pid || o.dataset.project === pid) ? '' : 'none'; });
    });
}

function openPicker() { document.getElementById('pickerModal').classList.add('show'); }
function closePicker() { document.getElementById('pickerModal').classList.remove('show'); }

function insertStill(imgId, label) {
    const textarea = document.getElementById('novelBody');
    if (!textarea) return;
    const marker = '{{img:' + imgId + '}}';
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const before = text.substring(0, start);
    const after = text.substring(end);
    const nlB = before.length > 0 && !before.endsWith('\n') ? '\n' : '';
    const nlA = after.length > 0 && !after.startsWith('\n') ? '\n' : '';
    textarea.value = before + nlB + marker + nlA + after;
    const newPos = start + nlB.length + marker.length + nlA.length;
    textarea.selectionStart = textarea.selectionEnd = newPos;
    textarea.focus();
    if (counter) { const ct = textarea.value.replace(/\{\{img:\d+\}\}/g, ''); counter.textContent = ct.length.toLocaleString() + ' 文字'; }
    closePicker();
    const btn = document.querySelector('.insert-still-btn');
    if (btn) {
        const orig = btn.textContent;
        btn.textContent = '✓ 「' + label.substring(0, 10) + '」挿入';
        btn.style.borderColor = 'rgba(76,175,80,0.4)';
        btn.style.color = '#2e7d32';
        setTimeout(() => { btn.textContent = orig; btn.style.borderColor = ''; btn.style.color = ''; }, 2000);
    }
}

function confirmDelete() { document.getElementById('deleteModal')?.classList.add('show'); }
function closeModal() { document.getElementById('deleteModal')?.classList.remove('show'); }
</script>
</body>
</html>

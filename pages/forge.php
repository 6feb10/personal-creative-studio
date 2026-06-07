<?php
set_time_limit(300);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/forge_api.php';
requireAuth();

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$pdo = db();

// ── POST処理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    switch ($_POST['_action'] ?? '') {

        case 'generate':
            $providerId = (int)($_POST['provider_id'] ?? 0);
            $extraPrompt = trim($_POST['prompt'] ?? '');
            $prevContent = trim($_POST['previous_content'] ?? '');
            $contMode = $_POST['continuation_mode'] ?? '';

            $systemParts = ["あなたは優れた小説家です。日本語で、指示された設定・条件に基づいて創作してください。\n"];

            // Memoria
            $projId = ($_POST['project_id'] ?? '') ?: null;
            if ($projId) {
                $proj = $pdo->prepare("SELECT name, custom_instructions, knowledge FROM projects WHERE id=?");
                $proj->execute([$projId]); $proj = $proj->fetch();
                if ($proj) {
                    $systemParts[] = "## プロジェクト: {$proj['name']}";
                    if ($proj['custom_instructions']) $systemParts[] = "### カスタム指示\n{$proj['custom_instructions']}";
                    if ($proj['knowledge']) $systemParts[] = "### ナレッジ\n{$proj['knowledge']}";
                }
            }

            // Cradle
            foreach ($_POST['base_ids'] ?? [] as $bid) {
                $b = $pdo->prepare("SELECT * FROM bases WHERE id=?"); $b->execute([$bid]); $b = $b->fetch();
                if ($b) {
                    $systemParts[] = "## 拠点: {$b['name']}\n{$b['description']}";
                    $members = $pdo->prepare("SELECT name FROM residents WHERE base_id=?"); $members->execute([$bid]);
                    $names = $members->fetchAll(PDO::FETCH_COLUMN);
                    if ($names) $systemParts[] = "所属住人: " . implode('、', $names);
                }
            }
            foreach ($_POST['resident_ids'] ?? [] as $rid) {
                $r = $pdo->prepare("SELECT r.*, b.name AS base_name FROM residents r LEFT JOIN bases b ON r.base_id=b.id WHERE r.id=?");
                $r->execute([$rid]); $r = $r->fetch();
                if ($r) {
                    $info = "## 住人: {$r['name']}\n";
                    foreach (['gender'=>'性別','base_name'=>'所属','height'=>'身長','body_type'=>'体型','hairstyle'=>'髪型','eye_color'=>'目の色','clothing'=>'服装','style'=>'系統','features'=>'特徴','personality'=>'性格','physical_info'=>'身体情報'] as $k=>$label)
                        if (!empty($r[$k])) $info .= "{$label}: {$r[$k]}\n";
                    $params = json_decode($r['params'] ?? '[]', true) ?: [];
                    if ($params) { $ps=[]; foreach($params as $p) $ps[]="{$p['name']}: {$p['value']}/10"; $info .= "パラメーター: ".implode('、',$ps)."\n"; }
                    $cfs = json_decode($r['custom_fields'] ?? '[]', true) ?: [];
                    foreach ($cfs as $cf) if ($cf['name']&&$cf['value']) $info .= "{$cf['name']}: {$cf['value']}\n";
                    $systemParts[] = $info;
                }
            }

            // テンプレート
            foreach ($_POST['template_ids'] ?? [] as $tid) {
                $t = $pdo->prepare("SELECT name, content FROM forge_templates WHERE id=?"); $t->execute([$tid]); $t = $t->fetch();
                if ($t) $systemParts[] = "## 指示: {$t['name']}\n{$t['content']}";
            }

            // 続き情報
            if ($prevContent) {
                if ($contMode === 'summary') {
                    $systemParts[] = "## これまでのあらすじ\n以下は物語のこれまでの要約です。この続きを書いてください。\n\n{$prevContent}";
                } else {
                    $systemParts[] = "## ここまでの本文\n以下は物語のここまでの内容です。この直後の続きを自然に書いてください。\n\n{$prevContent}";
                }
            }

            $systemPrompt = implode("\n\n", $systemParts);
            $userPrompt = $extraPrompt ?: ($prevContent ? 'この続きを書いてください。' : '上記の設定に基づいて、印象的なシーンを書いてください。');

            $result = forgeCallAPI($pdo, $providerId, $systemPrompt, $userPrompt);

            if (isset($result['error'])) {
                flash('❌ ' . $result['error'], 'error');
                header("Location: forge.php?action=generate");
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO forge_generations (provider,model,prompt_system,prompt_user,output,input_tokens,output_tokens,cached_tokens,cost_input,cost_output,cost_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$result['provider'],$result['model'],$systemPrompt,$userPrompt,$result['text'],$result['input_tokens'],$result['output_tokens'],$result['cached_tokens'],$result['cost_input'],$result['cost_output'],$result['cost_total']]);
            header("Location: forge.php?action=result&id=" . $pdo->lastInsertId());
            exit;

        case 'summarize_for_continue':
            $providerId = (int)($_POST['provider_id'] ?? 0);
            $fullText = trim($_POST['full_text'] ?? '');
            if (!$fullText) { flash('本文がないよ', 'error'); header("Location: forge.php?action=generate"); exit; }

            $sysPr = "あなたは要約の専門家です。与えられた小説の内容を、続きを書くために必要な情報を漏らさず、簡潔に要約してください。キャラクターの名前、関係性、現在の状況、雰囲気を保持してください。日本語で要約してください。";
            $result = forgeCallAPI($pdo, $providerId, $sysPr, "以下の小説を要約してください：\n\n" . $fullText);

            if (isset($result['error'])) {
                flash('❌ 要約失敗: ' . $result['error'], 'error');
                header("Location: forge.php?action=generate");
                exit;
            }

            // 要約コストも記録
            $stmt = $pdo->prepare("INSERT INTO forge_generations (provider,model,prompt_system,prompt_user,output,input_tokens,output_tokens,cached_tokens,cost_input,cost_output,cost_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$result['provider'],$result['model'],$sysPr,'要約リクエスト',$result['text'],$result['input_tokens'],$result['output_tokens'],$result['cached_tokens'],$result['cost_input'],$result['cost_output'],$result['cost_total']]);

            // 要約結果を持って生成画面へ
            $_SESSION['forge_summary'] = $result['text'];
            $_SESSION['forge_summary_source'] = $_POST['source_label'] ?? '';
            header("Location: forge.php?action=generate&cont=summary");
            exit;

        case 'save_to_novel':
            $genId = (int)($_POST['gen_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $gen = $pdo->prepare("SELECT * FROM forge_generations WHERE id=?"); $gen->execute([$genId]); $gen = $gen->fetch();
            if ($gen && $title) {
                $stmt = $pdo->prepare("INSERT INTO novels (title, body, status) VALUES (?,?,'draft')");
                $stmt->execute([$title, $gen['output']]);
                $novelId = $pdo->lastInsertId();
                $pdo->prepare("UPDATE forge_generations SET saved_to_novel_id=? WHERE id=?")->execute([$novelId, $genId]);
                flash('💾 Desireにノベル登録した！');
                header("Location: ../pages/desire.php?action=view&id={$novelId}");
                exit;
            }
            flash('タイトルを入れてね', 'error');
            header("Location: forge.php?action=result&id={$genId}");
            exit;

        case 'append_to_novel':
            $genId = (int)($_POST['gen_id'] ?? 0);
            $novelId = (int)($_POST['novel_id'] ?? 0);
            $gen = $pdo->prepare("SELECT output FROM forge_generations WHERE id=?"); $gen->execute([$genId]); $gen = $gen->fetch();
            $novel = $pdo->prepare("SELECT body FROM novels WHERE id=?"); $novel->execute([$novelId]); $novel = $novel->fetch();
            if ($gen && $novel) {
                $newBody = $novel['body'] . "\n\n" . $gen['output'];
                $pdo->prepare("UPDATE novels SET body=? WHERE id=?")->execute([$newBody, $novelId]);
                $pdo->prepare("UPDATE forge_generations SET saved_to_novel_id=? WHERE id=?")->execute([$novelId, $genId]);
                flash('📝 ノベルに追記した！');
                header("Location: ../pages/desire.php?action=view&id={$novelId}");
                exit;
            }
            header("Location: forge.php?action=result&id={$genId}");
            exit;

        case 'update_settings':
            $ids = $_POST['provider_id'] ?? [];
            $dnames = $_POST['display_name'] ?? [];
            $keys = $_POST['api_key'] ?? [];
            $models = $_POST['model'] ?? [];
            $costsIn = $_POST['cost_input'] ?? [];
            $costsOut = $_POST['cost_output'] ?? [];
            $enabled = $_POST['enabled'] ?? [];
            foreach ($ids as $i => $pid) {
                $en = in_array($pid, $enabled) ? 1 : 0;
                $pdo->prepare("UPDATE api_providers SET display_name=?, api_key=?, model=?, cost_input=?, cost_output=?, enabled=? WHERE id=?")
                    ->execute([trim($dnames[$i]??''), trim($keys[$i]??''), trim($models[$i]??''), (float)($costsIn[$i]??0), (float)($costsOut[$i]??0), $en, $pid]);
            }
            flash('⚙️ API設定を更新した');
            header("Location: forge.php?action=settings");
            exit;

        case 'add_provider':
            $type = $_POST['new_provider_type'] ?? '';
            $dname = trim($_POST['new_display_name'] ?? '');
            $model = trim($_POST['new_model'] ?? '');
            if (!$type || !$dname || !$model) { flash('全部入れてね', 'error'); header("Location: forge.php?action=settings"); exit; }
            $eps = ['OpenAI'=>'https://api.openai.com/v1/chat/completions','Claude'=>'https://api.anthropic.com/v1/messages','Gemini'=>'https://generativelanguage.googleapis.com/v1beta/models/','Grok'=>'https://api.x.ai/v1/chat/completions','Deepseek'=>'https://api.deepseek.com/chat/completions'];
            $pdo->prepare("INSERT INTO api_providers (name,provider_type,display_name,model,endpoint,cost_input,cost_output,enabled) VALUES (?,?,?,?,?,0,0,0)")
                ->execute([$type,$type,$dname,$model,$eps[$type]??'']);
            flash('🤖 プロバイダー追加！');
            header("Location: forge.php?action=settings");
            exit;

        case 'delete_provider':
            $pdo->prepare("DELETE FROM api_providers WHERE id=?")->execute([$id]);
            flash('🗑 プロバイダー削除');
            header("Location: forge.php?action=settings");
            exit;

        case 'create_template': case 'update_template':
            $name = trim($_POST['name'] ?? ''); $cat = $_POST['category'] ?? 'custom'; $content = trim($_POST['content'] ?? '');
            if (!$name) { flash('名前を入れてね', 'error'); break; }
            if ($_POST['_action'] === 'create_template') { $pdo->prepare("INSERT INTO forge_templates (name,category,content,is_preset) VALUES (?,?,?,0)")->execute([$name,$cat,$content]); flash('📋 テンプレート作成'); }
            else { $pdo->prepare("UPDATE forge_templates SET name=?,category=?,content=? WHERE id=? AND is_preset=0")->execute([$name,$cat,$content,$id]); flash('📋 テンプレート更新'); }
            header("Location: forge.php?action=templates"); exit;

        case 'delete_template':
            $pdo->prepare("DELETE FROM forge_templates WHERE id=? AND is_preset=0")->execute([$id]);
            flash('🗑 テンプレート削除'); header("Location: forge.php?action=templates"); exit;

        case 'delete_generation':
            $pdo->prepare("DELETE FROM forge_generations WHERE id=?")->execute([$id]);
            flash('🗑 削除'); header("Location: forge.php"); exit;
    }
}

$providers = $pdo->query("SELECT * FROM api_providers ORDER BY provider_type, display_name, id")->fetchAll();
$enabledProviders = $pdo->query("SELECT * FROM api_providers WHERE enabled=1 ORDER BY display_name")->fetchAll();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Forge — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
.forge-menu{display:flex;flex-direction:column;gap:0.6rem;margin-top:1.5rem}
.forge-menu-item{display:flex;align-items:center;gap:0.8rem;background:var(--card-bg);border:1px solid rgba(240,160,32,0.12);border-radius:12px;padding:1.1rem;text-decoration:none;color:inherit;transition:all 0.3s;-webkit-tap-highlight-color:transparent}
.forge-menu-item:active{transform:scale(0.98)}.forge-menu-icon{font-size:1.3rem}.forge-menu-label{font-size:0.9rem;color:var(--pearl)}.forge-menu-sub{font-size:0.73rem;color:rgba(124,92,255,0.4);margin-top:0.1rem}
.gen-output{background:rgba(255,255,255,0.4);border:1px solid rgba(124,92,255,0.12);border-radius:12px;padding:1.2rem;margin:1rem 0;font-size:0.95rem;line-height:2;color:var(--pearl);white-space:pre-wrap;word-wrap:break-word;max-height:60vh;overflow-y:auto;-webkit-overflow-scrolling:touch}
.gen-stats{display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;margin:1rem 0}.gen-stat{background:rgba(255,255,255,0.3);border:1px solid rgba(124,92,255,0.08);border-radius:8px;padding:0.6rem 0.8rem;text-align:center}.gen-stat-val{font-size:1rem;color:var(--honey);font-family:'M PLUS Rounded 1c', sans-serif}.gen-stat-label{font-size:0.6rem;color:rgba(124,92,255,0.4);letter-spacing:0.1em;margin-top:0.2rem}
.provider-card{background:var(--card-bg);border:1px solid rgba(124,92,255,0.12);border-radius:12px;padding:1rem;margin-bottom:0.8rem}
.provider-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:0.8rem}
.provider-type-badge{font-size:0.6rem;padding:0.15rem 0.5rem;background:rgba(124,92,255,0.1);border:1px solid rgba(124,92,255,0.2);border-radius:10px;color:var(--orchid)}
.provider-actions{display:flex;align-items:center;gap:0.5rem}
.toggle-switch{position:relative;width:44px;height:24px}.toggle-switch input{opacity:0;width:0;height:0}.toggle-slider{position:absolute;inset:0;cursor:pointer;background:rgba(124,92,255,0.15);border-radius:12px;transition:0.3s}.toggle-slider::before{content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:rgba(124,92,255,0.4);border-radius:50%;transition:0.3s}.toggle-switch input:checked+.toggle-slider{background:rgba(124,92,255,0.4)}.toggle-switch input:checked+.toggle-slider::before{transform:translateX(20px);background:var(--orchid)}
.settings-input{width:100%;padding:0.6rem 0.7rem;background:rgba(255,255,255,0.5);border:1px solid rgba(124,92,255,0.15);border-radius:8px;color:var(--pearl);font-size:0.82rem;font-family:'Noto Sans JP', sans-serif;outline:none;-webkit-appearance:none}.settings-input:focus{border-color:var(--orchid)}
.settings-row{display:flex;gap:0.4rem;margin-top:0.4rem}.settings-label{font-size:0.6rem;color:rgba(124,92,255,0.4);letter-spacing:0.05em;margin-top:0.4rem}
.tpl-category{font-family:'M PLUS Rounded 1c', sans-serif;font-size:0.65rem;letter-spacing:0.2em;color:var(--orchid);opacity:0.5;text-transform:uppercase;margin:1.5rem 0 0.5rem}
.tpl-item{display:flex;align-items:center;justify-content:space-between;padding:0.7rem 0;border-bottom:1px solid rgba(124,92,255,0.06)}.tpl-name{font-size:0.88rem;color:var(--pearl)}.tpl-content{font-size:0.75rem;color:rgba(124,92,255,0.35);margin-top:0.1rem}
.usage-summary{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.5rem;margin:1rem 0}.usage-box{background:var(--card-bg);border:1px solid rgba(124,92,255,0.1);border-radius:10px;padding:0.8rem;text-align:center}.usage-val{font-size:1.1rem;color:var(--honey);font-family:'M PLUS Rounded 1c', sans-serif}.usage-label{font-size:0.55rem;color:rgba(124,92,255,0.4);letter-spacing:0.08em;margin-top:0.2rem}
.usage-bar-row{display:flex;align-items:center;gap:0.5rem;margin:0.4rem 0}.usage-bar-name{font-size:0.75rem;color:var(--pearl);min-width:65px}.usage-bar-bg{flex:1;height:8px;background:rgba(124,92,255,0.1);border-radius:4px;overflow:hidden}.usage-bar-fill{height:100%;border-radius:4px;transition:width 0.5s}.usage-bar-val{font-size:0.7rem;color:rgba(124,92,255,0.4);min-width:50px;text-align:right}
.mat-section{margin-top:1rem}.mat-heading{font-family:'M PLUS Rounded 1c', sans-serif;font-size:0.65rem;letter-spacing:0.15em;color:var(--orchid);opacity:0.5;margin-bottom:0.5rem}
.mat-chips{display:flex;flex-wrap:wrap;gap:0.4rem}.mat-chip{display:flex;align-items:center;gap:0.3rem;padding:0.45rem 0.7rem;background:rgba(255,255,255,0.5);border:1px solid rgba(124,92,255,0.12);border-radius:8px;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:all 0.2s}.mat-chip input{accent-color:var(--orchid);width:14px;height:14px}.mat-chip span{font-size:0.78rem;color:var(--pearl)}.mat-chip:has(input:checked){border-color:var(--orchid);background:rgba(124,92,255,0.12)}
.hist-provider{font-size:0.65rem;color:var(--honey)}.hist-saved{font-size:0.6rem;color:rgba(76,175,80,0.6)}
.add-provider-box{background:rgba(255,255,255,0.3);border:2px dashed rgba(124,92,255,0.15);border-radius:12px;padding:1rem;margin-top:1rem}
.add-provider-title{font-family:'M PLUS Rounded 1c', sans-serif;font-size:0.75rem;color:var(--orchid);letter-spacing:0.1em;margin-bottom:0.8rem}
.del-provider-btn{background:rgba(239,77,107,0.1);border:1px solid rgba(239,77,107,0.2);border-radius:6px;color:var(--ember);cursor:pointer;font-size:0.7rem;padding:0.2rem 0.5rem;-webkit-tap-highlight-color:transparent}
.cont-banner{background:rgba(124,92,255,0.08);border:1px solid rgba(124,92,255,0.15);border-radius:10px;padding:0.8rem 1rem;margin-bottom:1rem;font-size:0.82rem;color:var(--pearl)}
.cont-banner-label{font-size:0.65rem;color:var(--orchid);letter-spacing:0.1em;margin-bottom:0.3rem}
.cont-mode{display:flex;gap:0.5rem;margin:0.8rem 0}
.cont-mode label{flex:1;display:flex;align-items:center;justify-content:center;gap:0.3rem;padding:0.6rem;background:rgba(255,255,255,0.4);border:1px solid rgba(124,92,255,0.12);border-radius:8px;cursor:pointer;font-size:0.78rem;color:var(--pearl);-webkit-tap-highlight-color:transparent;transition:all 0.2s}
.cont-mode label:has(input:checked){border-color:var(--orchid);background:rgba(124,92,255,0.12)}
.cont-mode input{display:none}
</style>
<?php include(__DIR__ . '/../pwa_head.php'); ?>
</head>
<body>
<div class="cosmos"></div><div class="noise"></div>
<div class="page">
<?php if ($flash): ?><div class="flash flash-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

<?php
// ═══ LIST ═══
if ($action === 'list'): ?>
<header class="page-header"><a href="../index.php" class="back-btn">‹ Home</a><span class="page-gate-icon">✨</span></header>
<h1 class="page-title">Forge</h1><p class="page-subtitle">AI Generation</p>
<div class="forge-menu">
    <a href="forge.php?action=generate" class="forge-menu-item"><span class="forge-menu-icon">⚡</span><div><div class="forge-menu-label">生成する</div><div class="forge-menu-sub">素材を選んでAIに書いてもらう</div></div></a>
    <a href="forge.php?action=templates" class="forge-menu-item"><span class="forge-menu-icon">📋</span><div><div class="forge-menu-label">テンプレート</div><div class="forge-menu-sub">プリセット＆自作テンプレ管理</div></div></a>
    <a href="illustrate.php" class="forge-menu-item"><span class="forge-menu-icon">🎨</span><div><div class="forge-menu-label">挿絵プロンプト</div><div class="forge-menu-sub">シーンを画像生成AI向けに変換</div></div></a>
    <a href="forge.php?action=settings" class="forge-menu-item"><span class="forge-menu-icon">⚙️</span><div><div class="forge-menu-label">API設定</div><div class="forge-menu-sub">キー・モデル・コスト管理</div></div></a>
    <a href="forge.php?action=usage" class="forge-menu-item"><span class="forge-menu-icon">📊</span><div><div class="forge-menu-label">使用量</div><div class="forge-menu-sub">トークン・コスト・履歴</div></div></a>
</div>
<?php $recent = $pdo->query("SELECT * FROM forge_generations ORDER BY created_at DESC LIMIT 5")->fetchAll(); if ($recent): ?>
<div class="detail-heading" style="margin-top:2rem;">最近の生成</div>
<?php foreach ($recent as $g): ?>
    <div class="card"><a href="forge.php?action=result&id=<?= $g['id'] ?>"><div class="flex-between mb-1"><span class="hist-provider"><?= h($g['provider']) ?></span><?php if($g['saved_to_novel_id']):?><span class="hist-saved">📜 保存済</span><?php endif;?></div><div class="card-excerpt"><?= h(excerpt($g['output'],80)) ?></div><div class="card-meta mt-1"><?= date('Y.m.d H:i',strtotime($g['created_at'])) ?> · $<?= number_format($g['cost_total'],4) ?></div></a></div>
<?php endforeach; endif; ?>

<?php
// ═══ GENERATE ═══
elseif ($action === 'generate'):
    $projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll();
    $bases = $pdo->query("SELECT id, name FROM bases ORDER BY name")->fetchAll();
    $residents = $pdo->query("SELECT id, name FROM residents ORDER BY name")->fetchAll();
    $templates = $pdo->query("SELECT * FROM forge_templates ORDER BY is_preset DESC, category, sort_order")->fetchAll();
    $tplByCategory = []; foreach ($templates as $t) $tplByCategory[$t['category']][] = $t;
    $catNames = ['mood'=>'🎭 雰囲気','style'=>'✒️ 文体','situation'=>'📖 シチュエーション','custom'=>'📝 自作'];
    $novels = $pdo->query("SELECT id, title FROM novels ORDER BY updated_at DESC")->fetchAll();

    // 続き情報
    $contGenId = (int)($_GET['cont_gen'] ?? 0);
    $contNovelId = (int)($_GET['cont_novel'] ?? 0);
    $contSummary = $_GET['cont'] === 'summary' ? ($_SESSION['forge_summary'] ?? '') : '';
    $contSummarySource = $_SESSION['forge_summary_source'] ?? '';
    $contText = '';
    $contLabel = '';

    if ($contGenId) {
        $cg = $pdo->prepare("SELECT output FROM forge_generations WHERE id=?"); $cg->execute([$contGenId]); $cg = $cg->fetch();
        if ($cg) { $contText = $cg['output']; $contLabel = "生成#{$contGenId}の続き"; }
    } elseif ($contNovelId) {
        $cn = $pdo->prepare("SELECT title, body FROM novels WHERE id=?"); $cn->execute([$contNovelId]); $cn = $cn->fetch();
        if ($cn) { $contText = preg_replace('/\{\{img:\d+\}\}/', '', $cn['body']); $contLabel = "📜 " . $cn['title'] . " の続き"; }
    } elseif ($contSummary) {
        $contText = $contSummary;
        $contLabel = "要約済み: " . ($contSummarySource ?: '');
        unset($_SESSION['forge_summary'], $_SESSION['forge_summary_source']);
    }
?>
<header class="page-header"><a href="forge.php" class="back-btn">‹ Forge</a><span class="page-gate-icon">⚡</span></header>
<h1 class="page-title"><?= $contText ? '続きを鍛造' : '生成する' ?></h1>

<?php if (empty($enabledProviders)): ?>
    <div class="empty mt-3"><div class="empty-icon">⚙️</div><p class="empty-text">先にAPI設定でキーを登録してね！</p></div>
<?php else: ?>

<?php if ($contText): ?>
    <div class="cont-banner">
        <div class="cont-banner-label">📜 続きモード</div>
        <?= h($contLabel) ?> (<?= number_format(mb_strlen($contText)) ?>文字)
    </div>
<?php endif; ?>

<form method="POST" class="mt-2" id="genForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="generate">

    <div class="form-group"><label class="form-label">AI</label>
        <select name="provider_id" class="form-select" required>
            <?php foreach ($enabledProviders as $ep): ?><option value="<?= $ep['id'] ?>"><?= h($ep['display_name'] ?: $ep['name']) ?></option><?php endforeach; ?>
        </select>
    </div>

    <?php if ($contText): ?>
        <!-- 続きモード: 全文 or 要約 -->
        <?php if (!$contSummary && mb_strlen($contText) > 2000): ?>
        <div class="form-group">
            <label class="form-label">渡し方</label>
            <div class="cont-mode">
                <label><input type="radio" name="continuation_mode" value="full" checked>📄 全文</label>
                <label><input type="radio" name="continuation_mode" value="summary">📝 要約して渡す</label>
            </div>
            <div class="form-hint">長い場合は要約するとトークン節約に</div>
        </div>
        <?php else: ?>
            <input type="hidden" name="continuation_mode" value="<?= $contSummary ? 'summary' : 'full' ?>">
        <?php endif; ?>
        <textarea name="previous_content" style="display:none;"><?= h($contText) ?></textarea>
    <?php endif; ?>

    <!-- ノベルから続きを選択（新規生成時のみ） -->
    <?php if (!$contText && $novels): ?>
    <div class="mat-section"><div class="mat-heading">📜 既存ノベルの続きを書く</div>
        <select class="form-select" id="novelContSelect" onchange="if(this.value)location.href='forge.php?action=generate&cont_novel='+this.value;">
            <option value="">— 選ばない（新規生成）—</option>
            <?php foreach ($novels as $nv): ?><option value="<?= $nv['id'] ?>"><?= h($nv['title']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($projects): ?>
    <div class="mat-section"><div class="mat-heading">✦ Memoria</div>
        <select name="project_id" class="form-select"><option value="">— 使わない —</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select>
    </div><?php endif; ?>

    <?php if ($bases): ?><div class="mat-section"><div class="mat-heading">🏛 拠点</div><div class="mat-chips"><?php foreach ($bases as $b): ?><label class="mat-chip"><input type="checkbox" name="base_ids[]" value="<?= $b['id'] ?>"><span><?= h($b['name']) ?></span></label><?php endforeach; ?></div></div><?php endif; ?>

    <?php if ($residents): ?><div class="mat-section"><div class="mat-heading">👤 住人</div><div class="mat-chips"><?php foreach ($residents as $r): ?><label class="mat-chip"><input type="checkbox" name="resident_ids[]" value="<?= $r['id'] ?>"><span><?= h($r['name']) ?></span></label><?php endforeach; ?></div></div><?php endif; ?>

    <?php foreach ($tplByCategory as $cat => $tpls): ?><div class="mat-section"><div class="mat-heading"><?= $catNames[$cat] ?? $cat ?></div><div class="mat-chips"><?php foreach ($tpls as $t): ?><label class="mat-chip"><input type="checkbox" name="template_ids[]" value="<?= $t['id'] ?>"><span><?= h($t['name']) ?></span></label><?php endforeach; ?></div></div><?php endforeach; ?>

    <div class="form-group mt-2"><label class="form-label">追加プロンプト</label>
        <textarea name="prompt" class="form-textarea" rows="4" placeholder="<?= $contText ? '続きの指示（例: 2人が再会するシーンへ）' : '自由にプロンプトを' ?>"></textarea>
    </div>
    <button type="submit" class="btn btn-primary btn-block mt-2" id="genBtn">🔮 <?= $contText ? '続きを鍛造' : '鍛造する' ?></button>
</form>
<script>
document.getElementById('genForm')?.addEventListener('submit',function(e){
    // 要約モードの場合は別処理
    var mode = document.querySelector('input[name="continuation_mode"]:checked');
    if (mode && mode.value === 'summary') {
        e.preventDefault();
        var f = document.createElement('form');
        f.method = 'POST'; f.action = 'forge.php';
        var fields = {csrf_token:'<?= csrfToken()?>',_action:'summarize_for_continue',provider_id:document.querySelector('[name=provider_id]').value,full_text:document.querySelector('[name=previous_content]').value,source_label:'<?= addslashes($contLabel)?>'};
        for(var k in fields){var inp=document.createElement('input');inp.type='hidden';inp.name=k;inp.value=fields[k];f.appendChild(inp);}
        document.body.appendChild(f);f.submit();return;
    }
    var b=document.getElementById('genBtn');b.disabled=true;b.textContent='🔮 鍛造中…';b.style.opacity='0.6';
});
    // プロジェクト自動リンク
fetch('forge_project_data.php').then(r=>r.json()).then(data=>{
    var ps=document.querySelector('[name=project_id]');
    if(!ps)return;
    ps.addEventListener('change',function(){
        var d=data[this.value]||{bases:[],residents:[]};
        document.querySelectorAll('[name="base_ids[]"]').forEach(c=>c.checked=d.bases.includes(parseInt(c.value)));
        document.querySelectorAll('[name="resident_ids[]"]').forEach(c=>c.checked=d.residents.includes(parseInt(c.value)));
    });
});
</script>
<?php endif; ?>

<?php
// ═══ RESULT ═══
elseif ($action === 'result'):
    $gen = $pdo->prepare("SELECT * FROM forge_generations WHERE id=?"); $gen->execute([$id]); $gen = $gen->fetch();
    if (!$gen) { header("Location: forge.php"); exit; }
    $novels = $pdo->query("SELECT id, title FROM novels ORDER BY updated_at DESC")->fetchAll();
?>
<header class="page-header"><a href="forge.php" class="back-btn">‹ Forge</a><span class="page-gate-icon">⚡</span></header>
<h1 class="page-title">生成結果</h1>
<div class="gen-stats">
    <div class="gen-stat"><div class="gen-stat-val" style="font-size:0.75rem;"><?= h($gen['provider']) ?></div><div class="gen-stat-label">PROVIDER</div></div>
    <div class="gen-stat"><div class="gen-stat-val"><?= number_format($gen['input_tokens']) ?></div><div class="gen-stat-label">INPUT</div></div>
    <div class="gen-stat"><div class="gen-stat-val"><?= number_format($gen['output_tokens']) ?></div><div class="gen-stat-label">OUTPUT</div></div>
    <div class="gen-stat"><div class="gen-stat-val">$<?= number_format($gen['cost_total'],4) ?></div><div class="gen-stat-label">COST</div></div>
</div>
<?php if ($gen['cached_tokens']>0):?><div class="form-hint text-center">💾 キャッシュ: <?= number_format($gen['cached_tokens']) ?> tokens</div><?php endif;?>
<div class="gen-output"><?= nl2br(h($gen['output'])) ?></div>

<!-- 続きを鍛造 -->
<a href="forge.php?action=generate&cont_gen=<?= $gen['id'] ?>" class="btn btn-block mt-2">🔮 この続きを鍛造</a>

<?php if (!$gen['saved_to_novel_id']): ?>
<!-- 新規ノベル登録 -->
<form method="POST" class="mt-2">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="save_to_novel"><input type="hidden" name="gen_id" value="<?= $gen['id'] ?>">
    <div class="form-group"><label class="form-label">新規ノベルとしてDesireに登録</label><input type="text" name="title" class="form-input" placeholder="タイトル" required></div>
    <button type="submit" class="btn btn-primary btn-block">💾 Desireに登録</button>
</form>
<!-- 既存ノベルに追記 -->
<?php if ($novels): ?>
<form method="POST" class="mt-2">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="append_to_novel"><input type="hidden" name="gen_id" value="<?= $gen['id'] ?>">
    <div class="form-group"><label class="form-label">既存ノベルに追記</label>
        <select name="novel_id" class="form-select" required><option value="">— 選択 —</option><?php foreach($novels as $nv):?><option value="<?=$nv['id']?>"><?=h($nv['title'])?></option><?php endforeach;?></select>
    </div>
    <button type="submit" class="btn btn-block" onclick="return confirm('このノベルの末尾に追記するよ？')">📝 追記する</button>
</form>
<?php endif; ?>
<?php else: ?>
    <a href="desire.php?action=view&id=<?= $gen['saved_to_novel_id'] ?>" class="btn btn-primary btn-block mt-2">📜 Desireで読む</a>
<?php endif; ?>

<div class="mt-2"><form method="POST" action="forge.php?id=<?= $gen['id'] ?>" onsubmit="return confirm('削除？')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="delete_generation"><button type="submit" class="btn btn-danger btn-sm btn-block">🗑 この生成を削除</button></form></div>

<?php
// ═══ SETTINGS ═══
elseif ($action === 'settings'): ?>
<header class="page-header"><a href="forge.php" class="back-btn">‹ Forge</a><span class="page-gate-icon">⚙️</span></header>
<h1 class="page-title">API設定</h1>
<p class="form-hint mt-1">コストは $ / 100万トークン</p>

<form method="POST" class="mt-2" id="settingsForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="update_settings">
    <?php foreach ($providers as $p): ?>
    <div class="provider-card">
        <input type="hidden" name="provider_id[]" value="<?= $p['id'] ?>">
        <div class="provider-header">
            <span class="provider-type-badge"><?= h($p['provider_type'] ?: $p['name']) ?></span>
            <div class="provider-actions">
                <button type="button" class="del-provider-btn" onclick="deleteProvider(<?=$p['id']?>)">✕</button>
                <label class="toggle-switch"><input type="checkbox" name="enabled[]" value="<?= $p['id'] ?>" <?= $p['enabled']?'checked':'' ?>><span class="toggle-slider"></span></label>
            </div>
        </div>
        <div class="settings-label">表示名</div><input type="text" name="display_name[]" class="settings-input" value="<?= h($p['display_name'] ?: $p['name']) ?>">
        <div class="settings-label">APIキー</div><input type="password" name="api_key[]" class="settings-input" value="<?= h($p['api_key']) ?>" autocomplete="off">
        <div class="settings-label">モデル名</div><input type="text" name="model[]" class="settings-input" value="<?= h($p['model']) ?>">
        <div class="settings-row">
            <div style="flex:1"><div class="settings-label">入力コスト</div><input type="number" name="cost_input[]" class="settings-input" step="0.01" value="<?= $p['cost_input'] ?>"></div>
            <div style="flex:1"><div class="settings-label">出力コスト</div><input type="number" name="cost_output[]" class="settings-input" step="0.01" value="<?= $p['cost_output'] ?>"></div>
        </div>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary btn-block mt-2">⚙️ 保存する</button>
</form>

<!-- 削除用の独立フォーム -->
<form id="deleteProviderForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="delete_provider">
</form>

<div class="add-provider-box">
    <div class="add-provider-title">🤖 モデルを追加</div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="add_provider">
        <div class="settings-label">プロバイダータイプ</div>
        <select name="new_provider_type" class="settings-input" required><option value="OpenAI">OpenAI</option><option value="Claude">Claude</option><option value="Gemini">Gemini</option><option value="Grok">Grok</option><option value="Deepseek">Deepseek</option><option value="その他">その他</option></select>
        <div class="settings-label">表示名</div><input type="text" name="new_display_name" class="settings-input" placeholder="例: GPT-4o mini" required>
        <div class="settings-label">モデル名</div><input type="text" name="new_model" class="settings-input" placeholder="例: gpt-4o-mini" required>
        <button type="submit" class="btn btn-sm btn-block mt-2">＋ 追加</button>
    </form>
</div>

<script>
function deleteProvider(id) {
    if (!confirm('このモデルを削除する？')) return;
    var f = document.getElementById('deleteProviderForm');
    f.action = 'forge.php?id=' + id;
    f.submit();
}
</script>

<?php
// ═══ TEMPLATES ═══
elseif ($action === 'templates' || $action === 'create_template' || $action === 'edit_template'):
    if ($action === 'create_template' || $action === 'edit_template'):
        $tpl = null; if ($action==='edit_template'&&$id){$tpl=$pdo->prepare("SELECT * FROM forge_templates WHERE id=? AND is_preset=0");$tpl->execute([$id]);$tpl=$tpl->fetch();}
?>
<header class="page-header"><a href="forge.php?action=templates" class="back-btn">‹ テンプレート</a><span class="page-gate-icon">📋</span></header>
<h1 class="page-title"><?= $tpl?'編集':'新規テンプレート' ?></h1>
<form method="POST" class="mt-3"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="<?= $tpl?'update_template':'create_template' ?>">
    <div class="form-group"><label class="form-label">名前</label><input type="text" name="name" class="form-input" value="<?= h($tpl['name']??'') ?>" required></div>
    <div class="form-group"><label class="form-label">カテゴリ</label><select name="category" class="form-select"><option value="custom" <?=($tpl['category']??'')==='custom'?'selected':''?>>📝 自作</option><option value="mood" <?=($tpl['category']??'')==='mood'?'selected':''?>>🎭 雰囲気</option><option value="style" <?=($tpl['category']??'')==='style'?'selected':''?>>✒️ 文体</option><option value="situation" <?=($tpl['category']??'')==='situation'?'selected':''?>>📖 シチュエーション</option></select></div>
    <div class="form-group"><label class="form-label">内容</label><textarea name="content" class="form-textarea" rows="5"><?= h($tpl['content']??'') ?></textarea></div>
    <button type="submit" class="btn btn-primary btn-block mt-2">📋 <?= $tpl?'更新':'作成' ?></button>
</form>
<?php else:
    $allTpls=$pdo->query("SELECT * FROM forge_templates ORDER BY is_preset DESC, category, sort_order, name")->fetchAll();
    $grouped=[];foreach($allTpls as $t)$grouped[$t['category']][]=$t;
    $catNames=['mood'=>'🎭 雰囲気','style'=>'✒️ 文体','situation'=>'📖 シチュエーション','custom'=>'📝 自作'];
?>
<header class="page-header"><a href="forge.php" class="back-btn">‹ Forge</a><span class="page-gate-icon">📋</span></header>
<h1 class="page-title">テンプレート</h1>
<?php foreach($grouped as $cat=>$tpls):?><div class="tpl-category"><?=$catNames[$cat]??$cat?></div>
<?php foreach($tpls as $t):?><div class="tpl-item"><div><div class="tpl-name"><?=$t['is_preset']?'📌 ':''?><?=h($t['name'])?></div><div class="tpl-content"><?=h(excerpt($t['content'],50))?></div></div>
<?php if(!$t['is_preset']):?><div style="display:flex;gap:0.3rem"><a href="forge.php?action=edit_template&id=<?=$t['id']?>" class="btn btn-sm" style="padding:0.3rem 0.6rem;font-size:0.65rem">✏️</a><form method="POST" action="forge.php?id=<?=$t['id']?>" onsubmit="return confirm('削除？')"><input type="hidden" name="csrf_token" value="<?=csrfToken()?>"><input type="hidden" name="_action" value="delete_template"><button type="submit" class="btn btn-danger btn-sm" style="padding:0.3rem 0.6rem;font-size:0.65rem">✕</button></form></div><?php endif;?></div>
<?php endforeach;endforeach;?>
<a href="forge.php?action=create_template" class="fab">＋</a>
<?php endif; ?>

<?php
// ═══ USAGE ═══
elseif ($action === 'usage'):
    $totalGen=$pdo->query("SELECT COUNT(*) FROM forge_generations")->fetchColumn();
    $totalCost=$pdo->query("SELECT COALESCE(SUM(cost_total),0) FROM forge_generations")->fetchColumn();
    $totalTokens=$pdo->query("SELECT COALESCE(SUM(input_tokens+output_tokens),0) FROM forge_generations")->fetchColumn();
    $byProvider=$pdo->query("SELECT provider,COUNT(*) as cnt,SUM(input_tokens) as total_in,SUM(output_tokens) as total_out,SUM(cost_total) as total_cost FROM forge_generations GROUP BY provider ORDER BY total_cost DESC")->fetchAll();
    $daily=$pdo->query("SELECT DATE(created_at) as day,COUNT(*) as cnt,SUM(cost_total) as cost FROM forge_generations WHERE created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY day")->fetchAll();
    $monthly=$pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as month,COUNT(*) as cnt,SUM(cost_total) as cost,SUM(input_tokens+output_tokens) as tokens FROM forge_generations GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month DESC LIMIT 6")->fetchAll();
    $maxP=max(array_column($byProvider?:[['total_cost'=>1]],'total_cost'));
?>
<header class="page-header"><a href="forge.php" class="back-btn">‹ Forge</a><span class="page-gate-icon">📊</span></header>
<h1 class="page-title">使用量</h1>
<div class="usage-summary">
    <div class="usage-box"><div class="usage-val"><?=$totalGen?></div><div class="usage-label">GENERATIONS</div></div>
    <div class="usage-box"><div class="usage-val"><?=number_format($totalTokens)?></div><div class="usage-label">TOKENS</div></div>
    <div class="usage-box"><div class="usage-val">$<?=number_format($totalCost,3)?></div><div class="usage-label">COST</div></div>
</div>
<?php if($byProvider):?><div class="detail-heading mt-3">プロバイダー別</div>
<?php foreach($byProvider as $bp):$pct=$maxP>0?($bp['total_cost']/$maxP)*100:0;?>
<div class="usage-bar-row"><span class="usage-bar-name"><?=h($bp['provider'])?></span><div class="usage-bar-bg"><div class="usage-bar-fill" style="width:<?=$pct?>%;background:var(--honey)"></div></div><span class="usage-bar-val">$<?=number_format($bp['total_cost'],3)?></span></div>
<div style="font-size:0.65rem;color:rgba(124,92,255,0.3);margin:-0.2rem 0 0.4rem 70px"><?=$bp['cnt']?>回 · <?=number_format($bp['total_in']+$bp['total_out'])?> tk</div>
<?php endforeach;endif;?>
<?php if($daily):?><div class="detail-heading mt-3">日別推移</div><?php $mD=max(array_column($daily,'cost'));foreach($daily as $d):$pct=$mD>0?($d['cost']/$mD)*100:0;?>
<div class="usage-bar-row"><span class="usage-bar-name" style="min-width:55px"><?=date('m/d',strtotime($d['day']))?></span><div class="usage-bar-bg"><div class="usage-bar-fill" style="width:<?=$pct?>%;background:var(--honey)"></div></div><span class="usage-bar-val">$<?=number_format($d['cost'],3)?></span></div>
<?php endforeach;endif;?>
<?php if($monthly):?><div class="detail-heading mt-3">月間</div><?php foreach($monthly as $m):?>
<div class="card"><div class="flex-between"><span class="card-title"><?=h($m['month'])?></span><span style="font-size:0.85rem;color:var(--honey)">$<?=number_format($m['cost'],3)?></span></div><div class="card-meta"><?=$m['cnt']?>回 · <?=number_format($m['tokens'])?> tk</div></div>
<?php endforeach;endif;?>
<?php if(!$totalGen):?><div class="empty mt-3"><div class="empty-icon">📊</div><p class="empty-text">まだ生成履歴がないよ。</p></div><?php endif;?>

<?php endif; ?>
</div></body></html>

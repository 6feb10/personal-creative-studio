<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/forge_api.php';
requireAuth();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'interview';
$partnerId = (int)($_GET['partner'] ?? 0);

$resident = $pdo->prepare("SELECT r.*, b.name AS base_name FROM residents r LEFT JOIN bases b ON r.base_id=b.id WHERE r.id=?");
$resident->execute([$id]); $resident = $resident->fetch();
if (!$resident) { header("Location: cradle.php"); exit; }

$partner = null;
if ($mode === 'duo' && $partnerId) {
    $partner = $pdo->prepare("SELECT r.*, b.name AS base_name FROM residents r LEFT JOIN bases b ON r.base_id=b.id WHERE r.id=?");
    $partner->execute([$partnerId]); $partner = $partner->fetch();
}

$otherResidents = $pdo->prepare("SELECT id, name FROM residents WHERE id != ? ORDER BY name");
$otherResidents->execute([$id]); $otherResidents = $otherResidents->fetchAll();
$enabledProviders = $pdo->query("SELECT * FROM api_providers WHERE enabled=1 ORDER BY display_name")->fetchAll();

$chatKey = "chat_{$id}_{$partnerId}_{$mode}";
if (!isset($_SESSION[$chatKey])) $_SESSION[$chatKey] = ['messages' => [], 'total_tokens' => 0, 'total_cost' => 0];
$chat = &$_SESSION[$chatKey];
$history = &$chat['messages'];

// コンテキスト深度（デフォルト: 直近10往復 = 20メッセージ）
$contextDepth = (int)($_SESSION["chat_depth_{$id}"] ?? 20);

function buildCharacterPrompt(array $r): string {
    $p = "あなたは「{$r['name']}」というキャラクターです。以下の設定に忠実に、このキャラクターとして会話してください。\n\n## 基本情報\n";
    foreach (['gender'=>'性別','base_name'=>'所属','height'=>'身長','body_type'=>'体型','style'=>'系統','features'=>'特徴'] as $k=>$l)
        if (!empty($r[$k])) $p .= "{$l}: {$r[$k]}\n";
    if ($r['personality']) $p .= "\n## 性格\n{$r['personality']}\n";
    $params = json_decode($r['params'] ?? '[]', true) ?: [];
    if ($params) { $p .= "\n## 内面パラメーター\n"; foreach ($params as $pm) $p .= "- {$pm['name']}: {$pm['value']}/10\n"; }
    $cfs = json_decode($r['custom_fields'] ?? '[]', true) ?: [];
    foreach ($cfs as $cf) if ($cf['name']&&$cf['value']) $p .= "{$cf['name']}: {$cf['value']}\n";
    $p .= "\n## ルール\n- キャラの性格・口調・パラメーターに忠実に応答\n- 自然な日本語の会話文で応答。地の文不要\n- 必要なら短い動作描写のみ\n";
    return $p;
}

function buildDuoPrompt(array $a, array $b): string {
    global $pdo;
    $p = "あなたは2人のキャラクターの会話を描写します。\n\n## キャラ1: {$a['name']}\n";
    if ($a['personality']) $p .= "性格: {$a['personality']}\n";
    $params = json_decode($a['params']??'[]',true)?:[];
    if ($params){$ps=[];foreach($params as $pm)$ps[]="{$pm['name']}:{$pm['value']}/10";$p.="パラメーター: ".implode(', ',$ps)."\n";}
    if ($a['features']) $p .= "特徴: {$a['features']}\n";
    $p .= "\n## キャラ2: {$b['name']}\n";
    if ($b['personality']) $p .= "性格: {$b['personality']}\n";
    $params2 = json_decode($b['params']??'[]',true)?:[];
    if ($params2){$ps=[];foreach($params2 as $pm)$ps[]="{$pm['name']}:{$pm['value']}/10";$p.="パラメーター: ".implode(', ',$ps)."\n";}
    if ($b['features']) $p .= "特徴: {$b['features']}\n";
    $cands = $pdo->prepare("SELECT COUNT(*) FROM resident_candidates WHERE (resident_id=? AND candidate_id=?) OR (resident_id=? AND candidate_id=?)");
    $cands->execute([$a['id'],$b['id'],$b['id'],$a['id']]);
    if ($cands->fetchColumn()>0) $p .= "\n※ この2人は関係性のあるキャラクター同士です\n";
    $p .= "\n## ルール\n- 2人の性格・口調に忠実\n- 交互に台詞。名前つき（例: {$a['name']}「…」）\n- 3〜5往復で区切る\n- ユーザーの話題に沿って会話\n";
    return $p;
}

$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    switch ($_POST['_action'] ?? '') {

        case 'send':
            $providerId = (int)($_POST['provider_id'] ?? 0);
            $userMsg = trim($_POST['message'] ?? '');
            if (!$userMsg) break;

            $sysPrompt = ($mode === 'duo' && $partner) ? buildDuoPrompt($resident, $partner) : buildCharacterPrompt($resident);

            // コンテキスト深度に従ってメッセージを構築
            $contextMessages = $history;
            if ($contextDepth > 0 && count($contextMessages) > $contextDepth) {
                $contextMessages = array_slice($contextMessages, -$contextDepth);
            }

            // 要約がある場合は先頭に追加
            if (!empty($chat['summary'])) {
                $sysPrompt .= "\n\n## これまでの会話のあらすじ\n" . $chat['summary'];
            }

            $fullUserPrompt = '';
            foreach ($contextMessages as $m) {
                $label = $m['role'] === 'user' ? 'あなた' : ($mode==='duo' ? '2人' : $resident['name']);
                $fullUserPrompt .= "[{$label}]\n{$m['content']}\n\n";
            }
            $fullUserPrompt .= "[あなた]\n{$userMsg}";

            $result = forgeCallAPI($pdo, $providerId, $sysPrompt, $fullUserPrompt);

            if (isset($result['error'])) {
                flash('❌ ' . $result['error'], 'error');
            } else {
                $history[] = ['role'=>'user','content'=>$userMsg,'name'=>'あなた','tokens'=>0];
                $history[] = ['role'=>'assistant','content'=>$result['text'],
                    'name'=> $mode==='duo' ? "{$resident['name']}＆{$partner['name']}" : $resident['name'],
                    'tokens'=>$result['input_tokens']+$result['output_tokens'],
                    'cost'=>$result['cost_total']];
                $chat['total_tokens'] += $result['input_tokens'] + $result['output_tokens'];
                $chat['total_cost'] += $result['cost_total'];

                $label = $mode==='duo' ? "{$resident['name']}×{$partner['name']} 対話" : "{$resident['name']} 対話";
                $pdo->prepare("INSERT INTO forge_generations (provider,model,prompt_system,prompt_user,output,input_tokens,output_tokens,cached_tokens,cost_input,cost_output,cost_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$result['provider'],$result['model'],$label,$userMsg,$result['text'],$result['input_tokens'],$result['output_tokens'],$result['cached_tokens'],$result['cost_input'],$result['cost_output'],$result['cost_total']]);
            }
            header("Location: chat.php?id={$id}&mode={$mode}&partner={$partnerId}");
            exit;

        case 'summarize':
            $providerId = (int)($_POST['provider_id'] ?? 0);
            $allText = '';
            foreach ($history as $m) {
                $allText .= "[{$m['name']}] {$m['content']}\n\n";
            }
            $sysP = "あなたは要約の専門家です。以下のキャラクター同士の会話を、続きの会話に必要な情報を保持しつつ簡潔に要約してください。関係性の変化、感情の動き、重要な発言を漏らさないこと。日本語で要約。";
            $result = forgeCallAPI($pdo, $providerId, $sysP, "以下の会話を要約してください:\n\n" . $allText);

            if (!isset($result['error'])) {
                $chat['summary'] = $result['text'];
                $chat['messages'] = []; // 履歴をリセット（要約に置換）
                $chat['total_tokens'] += $result['input_tokens'] + $result['output_tokens'];
                $chat['total_cost'] += $result['cost_total'];
                $pdo->prepare("INSERT INTO forge_generations (provider,model,prompt_system,prompt_user,output,input_tokens,output_tokens,cached_tokens,cost_input,cost_output,cost_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$result['provider'],$result['model'],'対話要約','要約リクエスト',$result['text'],$result['input_tokens'],$result['output_tokens'],$result['cached_tokens'],$result['cost_input'],$result['cost_output'],$result['cost_total']]);
                flash('📝 会話を要約した。ここから新しい会話が始まるよ');
            } else {
                flash('❌ 要約失敗: ' . $result['error'], 'error');
            }
            header("Location: chat.php?id={$id}&mode={$mode}&partner={$partnerId}");
            exit;

        case 'set_depth':
            $d = (int)($_POST['depth'] ?? 20);
            $_SESSION["chat_depth_{$id}"] = $d;
            header("Location: chat.php?id={$id}&mode={$mode}&partner={$partnerId}");
            exit;

        case 'clear':
            $_SESSION[$chatKey] = ['messages'=>[],'total_tokens'=>0,'total_cost'=>0];
            flash('🗑 リセットした');
            header("Location: chat.php?id={$id}&mode={$mode}&partner={$partnerId}");
            exit;

        case 'save_to_novel':
            $title = trim($_POST['title'] ?? '');
            if ($title && $history) {
                $body = '';
                if (!empty($chat['summary'])) $body .= "【あらすじ】\n{$chat['summary']}\n\n---\n\n";
                foreach ($history as $h) {
                    if ($h['role']==='user') $body .= "——{$h['name']}——\n{$h['content']}\n\n";
                    else $body .= "{$h['content']}\n\n";
                }
                $pdo->prepare("INSERT INTO novels (title, body, status) VALUES (?,?,'draft')")->execute([$title, trim($body)]);
                flash('💾 Desireに保存した！');
                header("Location: desire.php?action=view&id=" . $pdo->lastInsertId());
                exit;
            }
            flash('タイトルを入れてね', 'error');
            header("Location: chat.php?id={$id}&mode={$mode}&partner={$partnerId}");
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>💬 <?= h($resident['name']) ?> — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<?php include(__DIR__ . '/../pwa_head.php'); ?>
<style>
.chat-container{display:flex;flex-direction:column;gap:0.8rem;padding:0.5rem 0 1rem;min-height:30vh}
.chat-bubble{max-width:88%;padding:0.8rem 1rem;border-radius:14px;font-size:0.9rem;line-height:1.8;white-space:pre-wrap;word-wrap:break-word;animation:bubble-in 0.3s ease}
@keyframes bubble-in{from{opacity:0;transform:translateY(10px)}}
.chat-user{align-self:flex-end;background:rgba(124,92,255,0.15);border:1px solid rgba(124,92,255,0.2);color:var(--pearl)}
.chat-char{align-self:flex-start;background:rgba(255,126,182,0.1);border:1px solid rgba(255,126,182,0.15);color:var(--pearl)}
.chat-char-name{font-size:0.65rem;font-family:'M PLUS Rounded 1c', sans-serif;color:var(--blush);letter-spacing:0.08em;margin-bottom:0.3rem}
.chat-user-name{font-size:0.65rem;color:var(--orchid);text-align:right;margin-bottom:0.3rem}
.chat-meta{font-size:0.58rem;color:rgba(124,92,255,0.3);margin-top:0.3rem}
.chat-meta-right{text-align:right}
.chat-input-area{position:sticky;bottom:0;background:linear-gradient(to top,var(--void) 80%,transparent);padding:1rem 0 calc(1rem + var(--safe-bottom))}
.chat-input-row{display:flex;gap:0.5rem;align-items:flex-end}
.chat-textarea{flex:1;padding:0.7rem 0.9rem;background:rgba(255,255,255,0.6);border:1px solid rgba(124,92,255,0.2);border-radius:12px;color:var(--pearl);font-family:'Noto Sans JP', sans-serif;font-size:0.95rem;outline:none;resize:none;max-height:120px;min-height:44px;-webkit-appearance:none}
.chat-textarea:focus{border-color:var(--orchid)}.chat-textarea::placeholder{color:rgba(124,92,255,0.25)}
.chat-send{width:44px;height:44px;flex-shrink:0;border-radius:50%;background:linear-gradient(135deg,var(--orchid),var(--blush));border:none;color:white;font-size:1.1rem;cursor:pointer;-webkit-tap-highlight-color:transparent;display:flex;align-items:center;justify-content:center}
.chat-send:active{transform:scale(0.9)}
.mode-tabs{display:flex;gap:0.5rem;margin:0.8rem 0}
.mode-tab{flex:1;padding:0.6rem;text-align:center;background:rgba(255,255,255,0.4);border:1px solid rgba(124,92,255,0.12);border-radius:10px;font-size:0.78rem;color:rgba(124,92,255,0.5);text-decoration:none;-webkit-tap-highlight-color:transparent;transition:all 0.2s}
.mode-tab.active{border-color:var(--orchid);background:rgba(124,92,255,0.12);color:var(--orchid)}
.chat-actions{display:flex;gap:0.4rem;margin-top:0.5rem;flex-wrap:wrap}
.chat-empty{text-align:center;padding:3rem 1rem;color:rgba(124,92,255,0.3);font-size:0.85rem;line-height:1.8}
.session-stats{display:flex;gap:0.5rem;margin:0.5rem 0;justify-content:center}
.session-stat{font-size:0.6rem;color:rgba(240,160,32,0.5);padding:0.2rem 0.6rem;background:rgba(240,160,32,0.06);border-radius:10px}
.context-bar{display:flex;align-items:center;gap:0.4rem;margin:0.5rem 0;font-size:0.7rem;color:rgba(124,92,255,0.4)}
.context-select{padding:0.3rem 0.5rem;background:rgba(255,255,255,0.5);border:1px solid rgba(124,92,255,0.15);border-radius:6px;color:var(--pearl);font-size:0.7rem;outline:none;-webkit-appearance:none}
.summary-banner{background:rgba(245,158,122,0.08);border:1px solid rgba(245,158,122,0.15);border-radius:10px;padding:0.7rem 0.8rem;margin:0.5rem 0;font-size:0.78rem;color:rgba(245,158,122,0.6);line-height:1.6}
.summary-label{font-size:0.6rem;color:var(--rose-gold);letter-spacing:0.1em;margin-bottom:0.3rem;font-family:'M PLUS Rounded 1c', sans-serif}
</style>
</head>
<body>
<div class="cosmos"></div><div class="noise"></div>
<div class="page">
<?php if ($flash): ?><div class="flash flash-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

<header class="page-header">
    <a href="cradle.php?action=resident&id=<?= $id ?>" class="back-btn">‹ <?= h($resident['name']) ?></a>
    <span class="page-gate-icon">💬</span>
</header>
<h1 class="page-title" style="font-size:1.1rem;">
    <?= $mode==='duo'&&$partner ? h($resident['name']).' × '.h($partner['name']) : h($resident['name']) ?>
</h1>

<div class="mode-tabs">
    <a href="chat.php?id=<?= $id ?>&mode=interview" class="mode-tab <?= $mode==='interview'?'active':'' ?>">💬 インタビュー</a>
    <a href="chat.php?id=<?= $id ?>&mode=duo" class="mode-tab <?= $mode==='duo'?'active':'' ?>">👥 デュオ</a>
</div>

<!-- セッション統計 -->
<?php if ($chat['total_tokens'] > 0): ?>
<div class="session-stats">
    <span class="session-stat">💬 <?= count($history) ?>メッセージ</span>
    <span class="session-stat">🔤 <?= number_format($chat['total_tokens']) ?> tk</span>
    <span class="session-stat">💰 $<?= number_format($chat['total_cost'], 4) ?></span>
</div>
<?php endif; ?>

<?php if ($mode==='duo' && !$partner && $otherResidents): ?>
<div style="margin-top:1rem;">
    <label class="form-label">会話相手を選んでね</label>
    <?php foreach ($otherResidents as $or): ?>
        <a href="chat.php?id=<?= $id ?>&mode=duo&partner=<?= $or['id'] ?>" class="card" style="display:block;margin-bottom:0.5rem;padding:0.8rem;">
            <span style="font-size:0.9rem;color:var(--pearl);">👤 <?= h($or['name']) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php elseif ($mode==='duo' && !$otherResidents): ?>
<div class="chat-empty">住人が2人以上必要だよ！</div>

<?php else: ?>

<!-- 要約バナー -->
<?php if (!empty($chat['summary'])): ?>
<div class="summary-banner">
    <div class="summary-label">📝 これまでのあらすじ</div>
    <?= h(mb_substr($chat['summary'], 0, 150)) ?><?= mb_strlen($chat['summary'])>150?'…':'' ?>
</div>
<?php endif; ?>

<!-- コンテキスト深度 -->
<form method="POST" class="context-bar">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="set_depth">
    <span>参照:</span>
    <select name="depth" class="context-select" onchange="this.form.submit()">
        <option value="6" <?= $contextDepth==6?'selected':'' ?>>直近3往復</option>
        <option value="10" <?= $contextDepth==10?'selected':'' ?>>直近5往復</option>
        <option value="20" <?= $contextDepth==20?'selected':'' ?>>直近10往復</option>
        <option value="0" <?= $contextDepth==0?'selected':'' ?>>全履歴</option>
    </select>
    <span style="color:rgba(124,92,255,0.25);">（トークン節約）</span>
</form>

<?php if (empty($history) && empty($chat['summary'])): ?>
<div class="chat-empty">
    <?= $mode==='duo' ? '話題を振ると2人が話し始めるよ。' : h($resident['name']).'に話しかけてみよう。' ?>
</div>
<?php endif; ?>

<div class="chat-container" id="chatContainer">
    <?php foreach ($history as $h): ?>
        <?php if ($h['role']==='user'): ?>
            <div class="chat-bubble chat-user">
                <div class="chat-user-name"><?= h($h['name']??'あなた') ?></div>
                <?= nl2br(h($h['content'])) ?>
            </div>
        <?php else: ?>
            <div class="chat-bubble chat-char">
                <div class="chat-char-name"><?= h($h['name']??$resident['name']) ?></div>
                <?= nl2br(h($h['content'])) ?>
                <?php if (!empty($h['tokens'])): ?>
                    <div class="chat-meta"><?= number_format($h['tokens']) ?> tk · $<?= number_format($h['cost']??0, 4) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- アクション -->
<?php if ($history): ?>
<div class="chat-actions">
    <form method="POST" style="flex:1;"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="clear"><button type="submit" class="btn btn-sm btn-block" onclick="return confirm('リセットする？')">🗑 リセット</button></form>
    <?php if (count($history) >= 6): ?>
    <form method="POST" style="flex:1;">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="summarize">
        <input type="hidden" name="provider_id" value="<?= $enabledProviders[0]['id'] ?? 0 ?>">
        <button type="submit" class="btn btn-sm btn-block" onclick="return confirm('会話を要約して新しく始める？')">📝 要約して続行</button>
    </form>
    <?php endif; ?>
</div>
<form method="POST" class="mt-1">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="save_to_novel">
    <div style="display:flex;gap:0.4rem;">
        <input type="text" name="title" class="form-input" placeholder="Desireに保存" style="flex:1;font-size:0.82rem;padding:0.5rem 0.7rem;">
        <button type="submit" class="btn btn-sm">💾</button>
    </div>
</form>
<?php endif; ?>

<!-- 入力 -->
<?php if (!empty($enabledProviders)): ?>
<div class="chat-input-area">
    <form method="POST" id="chatForm">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="_action" value="send">
        <?php if (count($enabledProviders)>1): ?>
        <select name="provider_id" class="form-select" style="margin-bottom:0.5rem;font-size:0.78rem;padding:0.5rem;">
            <?php foreach ($enabledProviders as $ep): ?><option value="<?= $ep['id'] ?>"><?= h($ep['display_name']?:$ep['name']) ?></option><?php endforeach; ?>
        </select>
        <?php else: ?><input type="hidden" name="provider_id" value="<?= $enabledProviders[0]['id'] ?>"><?php endif; ?>
        <div class="chat-input-row">
            <textarea name="message" class="chat-textarea" placeholder="<?= $mode==='duo'?'話題を振ってみて':h($resident['name']).'に話しかける…' ?>" rows="1" required id="chatInput"></textarea>
            <button type="submit" class="chat-send" id="sendBtn">▸</button>
        </div>
    </form>
</div>
<script>
var c=document.getElementById('chatContainer');
if(c&&c.children.length>0)c.lastElementChild.scrollIntoView({behavior:'smooth',block:'end'});
var ta=document.getElementById('chatInput');
if(ta)ta.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';});
document.getElementById('chatForm')?.addEventListener('submit',function(){var b=document.getElementById('sendBtn');b.disabled=true;b.style.opacity='0.5';});
</script>
<?php else: ?>
<div class="chat-empty" style="padding:1rem;">⚙️ ForgeのAPI設定でキーを登録してね</div>
<?php endif; ?>

<?php endif; ?>
</div></body></html>

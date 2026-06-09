<?php
// ═══════════════════════════════════════════════
//  DreamStudio — Installer
//  初回セットアップ用。テーブル作成と管理ユーザー登録を行う。
//  ⚠ セットアップ完了後は必ずこのファイルを削除してください。
// ═══════════════════════════════════════════════
require_once __DIR__ . '/config.php';

$step = 'form';
$errors = [];
$done = false;

// DB接続できるか確認
$dbReady = false;
try {
    db()->query('SELECT 1');
    $dbReady = true;
} catch (Throwable $e) {
    $errors[] = 'データベースに接続できません。config.php の DB_HOST / DB_NAME / DB_USER / DB_PASS を確認してください。（' . h($e->getMessage()) . '）';
}

// すでにユーザーが存在するか
$hasUser = false;
if ($dbReady) {
    try {
        $hasUser = (bool) db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (Throwable $e) {
        // users テーブルがまだ無い → これからインストール
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbReady) {
    if (!verifyCsrf()) {
        $errors[] = 'セッションの有効期限が切れました。ページを再読み込みしてやり直してください。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if ($username === '' || mb_strlen($username) > 100) $errors[] = 'ユーザー名を入力してください（100文字以内）。';
        if (mb_strlen($password) < 8) $errors[] = 'パスワードは8文字以上にしてください。';
        if ($password !== $password2) $errors[] = 'パスワードが一致しません。';

        if (!$errors) {
            try {
                // 1) スキーマを流し込む
                $sql = file_get_contents(__DIR__ . '/schema.sql');
                if ($sql === false) throw new RuntimeException('schema.sql が読み込めません。');
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    // コメント行だけのチャンクはスキップ
                    $meaningful = preg_replace('/^\s*(--.*)?$/m', '', $stmt);
                    if (trim($meaningful) === '') continue;
                    db()->exec($stmt);
                }

                // 2) 管理ユーザーを作成（重複時はスキップ）
                $exists = db()->prepare("SELECT id FROM users WHERE username = ?");
                $exists->execute([$username]);
                if ($exists->fetch()) {
                    $errors[] = 'そのユーザー名はすでに存在します。';
                } else {
                    $ins = db()->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
                    $ins->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                    $done = true;
                }
            } catch (Throwable $e) {
                $errors[] = 'インストール中にエラーが発生しました: ' . h($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>セットアップ — DreamStudio</title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.setup-wrap { position: relative; z-index: 2; max-width: 420px; margin: 0 auto; padding: 2.5rem 1.5rem; }
.setup-title { font-family: 'M PLUS Rounded 1c', sans-serif; font-weight: 800; font-size: 1.6rem;
  background: linear-gradient(135deg, var(--orchid), var(--blush)); -webkit-background-clip: text;
  -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.4rem; }
.setup-sub { color: rgba(58,47,74,0.6); font-size: 0.85rem; margin-bottom: 2rem; }
.setup-note { font-size: 0.8rem; line-height: 1.7; color: rgba(58,47,74,0.7); background: rgba(124,92,255,0.06);
  border: 1px solid rgba(124,92,255,0.14); border-radius: 12px; padding: 1rem; margin-top: 1.5rem; }
.setup-err { background: rgba(239,77,107,0.1); border: 1px solid rgba(239,77,107,0.3); color: var(--ember);
  border-radius: 10px; padding: 0.8rem 1rem; font-size: 0.85rem; margin-bottom: 1rem; line-height: 1.6; }
.setup-ok { background: rgba(76,175,80,0.12); border: 1px solid rgba(76,175,80,0.35); color: #2e7d32;
  border-radius: 12px; padding: 1.2rem; line-height: 1.8; }
</style>
<?php include(__DIR__ . '/pwa_head.php'); ?>
</head>
<body>
<div class="cosmos"></div>
<div class="noise"></div>
<div class="setup-wrap">
  <h1 class="setup-title">DreamStudio</h1>
  <p class="setup-sub">— セットアップ —</p>

  <?php foreach ($errors as $e): ?>
    <div class="setup-err"><?= $e /* 既にエスケープ済み or 固定文 */ ?></div>
  <?php endforeach; ?>

  <?php if ($done): ?>
    <div class="setup-ok">
      ✅ セットアップが完了しました。<br>
      <strong>⚠ セキュリティのため、必ず <code>install.php</code> を削除してください。</strong><br>
      削除後、<a href="login.php">ログインページ</a>からサインインできます。
    </div>
  <?php elseif ($dbReady && $hasUser): ?>
    <div class="setup-ok">
      このアプリはすでにセットアップ済みです。<br>
      <strong>⚠ <code>install.php</code> を削除してください。</strong><br>
      <a href="login.php">ログインページへ</a>
    </div>
  <?php elseif ($dbReady): ?>
    <p class="setup-sub" style="margin-bottom:1.5rem;">テーブルを作成し、最初の管理ユーザーを登録します。</p>
    <form method="POST" class="login-form" style="max-width:none;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="form-group">
        <label class="form-label">ユーザー名</label>
        <input type="text" name="username" class="form-input" autocomplete="username" required>
      </div>
      <div class="form-group">
        <label class="form-label">パスワード（8文字以上）</label>
        <input type="password" name="password" class="form-input" autocomplete="new-password" required>
      </div>
      <div class="form-group">
        <label class="form-label">パスワード（確認）</label>
        <input type="password" name="password2" class="form-input" autocomplete="new-password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">セットアップを実行</button>
    </form>
  <?php endif; ?>

  <?php if (!$hasUser && !$done): ?>
  <div class="setup-note">
    💡 先に config.php の DB 接続情報を設定しておいてください。<br>
    このウィザードが <code>schema.sql</code> を読み込んでテーブルを作成します。
  </div>
  <?php endif; ?>
</div>
</body>
</html>

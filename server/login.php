<?php
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
    $error = 'ユーザー名かパスワードが違います。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Enter — <?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.login-wrap {
	position: relative;
    z-index: 2;
    min-height: 100vh; min-height: 100dvh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 2rem 1.5rem;
}
.login-sigil { font-size: 2.5rem; margin-bottom: 1.5rem; opacity: 0.7; }
.login-title {
    font-family: 'M PLUS Rounded 1c', sans-serif;
    font-weight: 800;
    font-size: 1.4rem; letter-spacing: 0.04em;
    color: var(--pearl); margin-bottom: 2.5rem;
}
.login-form { width: 100%; max-width: 300px; }
.login-field {
    width: 100%; padding: 0.9rem 1rem;
    background: rgba(255,255,255,0.85);
    border: 1px solid rgba(124,92,255,0.25);
    border-radius: 10px; color: var(--pearl);
    font-family: 'Noto Sans JP', sans-serif;
    font-size: 1rem; margin-bottom: 1rem;
    outline: none; transition: border-color 0.3s;
    -webkit-appearance: none;
}
.login-field:focus { border-color: var(--orchid); }
.login-field::placeholder { color: rgba(58,47,74,0.4); }
.login-btn {
    width: 100%; padding: 0.9rem;
    background: linear-gradient(135deg, var(--orchid), var(--blush));
    border: none;
    border-radius: 10px; color: #fff;
    font-family: 'M PLUS Rounded 1c', sans-serif;
    font-weight: 700;
    font-size: 0.95rem; letter-spacing: 0.05em;
    cursor: pointer; transition: all 0.3s;
    -webkit-tap-highlight-color: transparent;
}
.login-btn:active { transform: scale(0.97); opacity: 0.9; }
.login-error {
    text-align: center; color: var(--ember);
    font-size: 0.85rem; margin-bottom: 1rem;
    font-family: 'Noto Sans JP', sans-serif;
}
</style>
<?php include(__DIR__ . '/pwa_head.php'); ?>
</head>
<body>
<div class="cosmos"></div>
<div class="noise"></div>

<div class="login-wrap">
    <div class="login-sigil">✦</div>
    <h1 class="login-title">DreamStudio</h1>

    <?php if ($error): ?>
        <p class="login-error"><?= h($error) ?></p>
    <?php endif; ?>

    <form class="login-form" method="POST">
        <input type="text" name="username" class="login-field" placeholder="ユーザー名" autocomplete="username" required>
        <input type="password" name="password" class="login-field" placeholder="パスワード" autocomplete="current-password" required>
        <button type="submit" class="login-btn">ログイン</button>
    </form>
</div>
</body>
</html>

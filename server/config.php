<?php
// ═══════════════════════════════════════════════
//  DreamStudio — Configuration
// ═══════════════════════════════════════════════

// ▼▼▼ カラフルボックスの情報に書き換えてね ▼▼▼
define('DB_HOST', 'ダミー');
define('DB_NAME', 'ダミー');
define('DB_USER', 'ダミー');
define('DB_PASS', 'ダミー');
// ▲▲▲

define('SITE_NAME', 'DreamStudio');
define('UPLOAD_DIR', __DIR__ . '/uploads/images/');
define('UPLOAD_URL', './uploads/images/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

session_start();

// DB接続
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

// 認証チェック
function requireAuth(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// CSRF対策
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// フラッシュメッセージ
function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function getFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ヘルパー
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function excerpt(string $text, int $len = 80): string {
    $plain = strip_tags($text);
    return mb_strlen($plain) > $len ? mb_substr($plain, 0, $len) . '…' : $plain;
}

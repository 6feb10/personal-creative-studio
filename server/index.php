<?php
require_once __DIR__ . '/config.php';
requireAuth();

$novelCount = db()->query("SELECT COUNT(*) FROM novels")->fetchColumn();
$imageCount = db()->query("SELECT COUNT(*) FROM images")->fetchColumn();
$projectCount = db()->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$bookmarkCount = db()->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn();

try { $baseCount = db()->query("SELECT COUNT(*) FROM bases")->fetchColumn(); $residentCount = db()->query("SELECT COUNT(*) FROM residents")->fetchColumn(); } catch (Exception $e) { $baseCount = 0; $residentCount = 0; }
try { $genCount = db()->query("SELECT COUNT(*) FROM forge_generations")->fetchColumn(); } catch (Exception $e) { $genCount = 0; }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= SITE_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@400;500;700;800&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.hero {
  min-height: 38vh;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  text-align: center; padding: 2rem 1.5rem 1rem;
}
.hero-sigil {
  width: 60px; height: 60px; position: relative;
  margin-bottom: 1.5rem; animation: breathe 4s ease-in-out infinite;
}
.hero-sigil::before {
  content: ''; position: absolute; inset: 0;
  border: 2px solid var(--orchid); border-radius: 50%;
  animation: spin 20s linear infinite;
}
.hero-sigil::after {
  content: '✦'; position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; color: var(--rose-gold);
}
@keyframes breathe { 0%,100% { filter: drop-shadow(0 0 6px rgba(124,92,255,0.25)); } 50% { filter: drop-shadow(0 0 14px rgba(124,92,255,0.4)); } }
@keyframes spin { to { transform: rotate(360deg); } }

.hero-title {
  font-family: 'M PLUS Rounded 1c', sans-serif; font-weight: 800;
  font-size: clamp(1.8rem, 8vw, 3rem);
  letter-spacing: 0.02em; line-height: 1.15;
  background: linear-gradient(135deg, #7c5cff 0%, #ff7eb6 60%, #f59e7a 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.hero-sub { font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.65rem; letter-spacing: 0.3em; color: var(--orchid); opacity: 0.7; margin-top: 0.6rem; }
.hero-quote { font-weight: 400; font-size: 0.9rem; color: rgba(58,47,74,0.6); margin-top: 1.5rem; line-height: 1.8; }

.gates { padding: 0 1rem 3rem; }
.gate {
  display: flex; align-items: center; gap: 1rem;
  background: var(--card-bg); border: 1px solid var(--card-border);
  border-radius: 14px; padding: 1.3rem 1.2rem; margin-bottom: 0.8rem;
  text-decoration: none; color: inherit; position: relative; overflow: hidden;
  transition: all 0.3s; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  -webkit-tap-highlight-color: transparent;
}
.gate::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, var(--orchid), transparent); opacity: 0.3; }
.gate:active { transform: scale(0.98); }
.gate-icon { font-size: 1.5rem; flex-shrink: 0; }
.gate-info { flex: 1; }
.gate-name { font-family: 'M PLUS Rounded 1c', sans-serif; font-weight: 700; font-size: 0.95rem; color: var(--pearl); letter-spacing: 0.02em; }
.gate-desc { font-size: 0.8rem; color: rgba(58,47,74,0.55); font-weight: 400; margin-top: 0.2rem; }
.gate-count { font-family: 'M PLUS Rounded 1c', sans-serif; font-size: 0.65rem; color: rgba(124,92,255,0.6); letter-spacing: 0.08em; }
.gate-arrow { color: var(--orchid); opacity: 0.25; font-size: 1.2rem; flex-shrink: 0; }

.gate--desire { border-color: rgba(255,107,138,0.12); } .gate--desire .gate-icon { text-shadow: 0 0 15px rgba(255,107,138,0.3); }
.gate--reverie { border-color: rgba(155,77,202,0.12); } .gate--reverie .gate-icon { text-shadow: 0 0 15px rgba(155,77,202,0.3); }
.gate--memoria { border-color: rgba(232,168,124,0.12); } .gate--memoria .gate-icon { text-shadow: 0 0 15px rgba(232,168,124,0.3); }
.gate--sanctum { border-color: rgba(212,114,140,0.12); } .gate--sanctum .gate-icon { text-shadow: 0 0 15px rgba(212,114,140,0.3); }
.gate--cradle { border-color: rgba(100,200,255,0.12); } .gate--cradle .gate-icon { text-shadow: 0 0 15px rgba(100,200,255,0.3); }
.gate--forge { border-color: rgba(255,179,71,0.12); } .gate--forge .gate-icon { text-shadow: 0 0 15px rgba(255,179,71,0.3); }

.logout-area { text-align: center; padding: 2rem 0; }
.logout-link { font-size: 0.7rem; color: rgba(124,92,255,0.5); text-decoration: none; letter-spacing: 0.15em; font-family: 'M PLUS Rounded 1c', sans-serif; }
</style>
<?php include(__DIR__ . '/pwa_head.php'); ?>
</head>
<body>
<div class="cosmos"></div>
<div class="noise"></div>
<div class="page" style="padding-top: var(--safe-top);">

  <section class="hero">
    <div class="hero-sigil"></div>
    <h1 class="hero-title">Dream<br>Studio</h1>
    <p class="hero-sub">— your creative studio —</p>
    <p class="hero-quote">思い描いた物語を、かたちにする場所。</p>
  </section>

  <nav class="gates">
    <a href="pages/cradle.php" class="gate gate--cradle">
      <span class="gate-icon">🏠</span>
      <div class="gate-info"><div class="gate-name">Cradle</div><div class="gate-desc">拠点とキャラクター — 世界設計</div></div>
      <span class="gate-count"><?= $baseCount ?>拠点 · <?= $residentCount ?>人</span><span class="gate-arrow">›</span>
    </a>
    <a href="pages/forge.php" class="gate gate--forge">
      <span class="gate-icon">✨</span>
      <div class="gate-info"><div class="gate-name">Forge</div><div class="gate-desc">AI生成 — 物語のたねを書く</div></div>
      <span class="gate-count"><?= $genCount ?></span><span class="gate-arrow">›</span>
    </a>
    <a href="pages/desire.php" class="gate gate--desire">
      <span class="gate-icon">📖</span>
      <div class="gate-info"><div class="gate-name">Desire</div><div class="gate-desc">ノベル — 物語を言葉に</div></div>
      <span class="gate-count"><?= $novelCount ?></span><span class="gate-arrow">›</span>
    </a>
    <a href="pages/reverie.php" class="gate gate--reverie">
      <span class="gate-icon">🖼️</span>
      <div class="gate-info"><div class="gate-name">Reverie</div><div class="gate-desc">ギャラリー — 画像の展示室</div></div>
      <span class="gate-count"><?= $imageCount ?></span><span class="gate-arrow">›</span>
    </a>
    <a href="pages/memoria.php" class="gate gate--memoria">
      <span class="gate-icon">📚</span>
      <div class="gate-info"><div class="gate-name">Memoria</div><div class="gate-desc">プロジェクト・ナレッジ</div></div>
      <span class="gate-count"><?= $projectCount ?></span><span class="gate-arrow">›</span>
    </a>
    <a href="pages/sanctum.php" class="gate gate--sanctum">
      <span class="gate-icon">🔖</span>
      <div class="gate-info"><div class="gate-name">Sanctum</div><div class="gate-desc">ブックマーク — 資料の棚</div></div>
      <span class="gate-count"><?= $bookmarkCount ?></span><span class="gate-arrow">›</span>
    </a>
  </nav>

  <div class="logout-area"><a href="logout.php" class="logout-link">log out</a></div>
</div>
</body>
</html>

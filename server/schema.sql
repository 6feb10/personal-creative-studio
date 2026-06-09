-- ═══════════════════════════════════════════════
--  DreamStudio — Database Schema
--  文字コード: utf8mb4 / エンジン: InnoDB
--  install.php から読み込まれます（手動で流し込んでもOK）
-- ═══════════════════════════════════════════════

-- ── 認証 ──
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══ Cradle（世界設計） ═══
CREATE TABLE IF NOT EXISTS bases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS residents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  gender VARCHAR(20),
  base_id INT NULL,
  height VARCHAR(50),
  body_type VARCHAR(100),
  physical_info TEXT,
  hairstyle VARCHAR(200),
  eye_color VARCHAR(100),
  clothing TEXT,
  style VARCHAR(200),
  features TEXT,
  personality TEXT,
  params JSON,
  custom_fields JSON,
  illust_prompt LONGTEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_residents_base (base_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- キャラ同士の関係（相手キャラ）
CREATE TABLE IF NOT EXISTS resident_candidates (
  resident_id INT NOT NULL,
  candidate_id INT NOT NULL,
  PRIMARY KEY (resident_id, candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══ Desire（ノベル管理） ═══
CREATE TABLE IF NOT EXISTS novels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(300) NOT NULL,
  body LONGTEXT,
  project_id INT NULL,
  persona_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_novels_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS novel_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS novel_tag_map (
  novel_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (novel_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══ Reverie（画像ギャラリー） ═══
CREATE TABLE IF NOT EXISTS image_folders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  original_name VARCHAR(255),
  folder_id INT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_images_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS image_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS image_tag_map (
  image_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (image_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══ Sanctum（ブックマーク） ═══
CREATE TABLE IF NOT EXISTS bookmark_folders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookmarks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  url VARCHAR(1000) NOT NULL,
  title VARCHAR(300),
  description TEXT,
  folder_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bookmarks_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookmark_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookmark_tag_map (
  bookmark_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (bookmark_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══ Memoria（プロジェクト） ═══
CREATE TABLE IF NOT EXISTS projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  description TEXT,
  custom_instructions LONGTEXT,
  knowledge LONGTEXT,
  color VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  description TEXT,
  project_id INT NOT NULL,
  INDEX idx_personas_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  label VARCHAR(200),
  url VARCHAR(1000),
  link_type VARCHAR(50),
  sort_order INT DEFAULT 0,
  INDEX idx_project_links_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_bases (
  project_id INT NOT NULL,
  base_id INT NOT NULL,
  PRIMARY KEY (project_id, base_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_residents (
  project_id INT NOT NULL,
  resident_id INT NOT NULL,
  PRIMARY KEY (project_id, resident_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══ Forge（AI生成） ═══
CREATE TABLE IF NOT EXISTS api_providers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  provider_type VARCHAR(50) NOT NULL,
  display_name VARCHAR(200),
  model VARCHAR(150),
  endpoint VARCHAR(500),
  api_key VARCHAR(500),
  cost_input DECIMAL(10,6) DEFAULT 0,
  cost_output DECIMAL(10,6) DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forge_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  category VARCHAR(50),
  content LONGTEXT,
  is_preset TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forge_generations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(100),
  model VARCHAR(150),
  prompt_system LONGTEXT,
  prompt_user LONGTEXT,
  output LONGTEXT,
  input_tokens INT DEFAULT 0,
  output_tokens INT DEFAULT 0,
  cached_tokens INT DEFAULT 0,
  cost_input DECIMAL(10,6) DEFAULT 0,
  cost_output DECIMAL(10,6) DEFAULT 0,
  cost_total DECIMAL(10,6) DEFAULT 0,
  saved_to_novel_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gen_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══ 初期データ（プリセットのテンプレート） ═══
INSERT INTO forge_templates (name, category, content, is_preset, sort_order) VALUES
  ('明るい日常', 'mood', '穏やかで明るい雰囲気。日常の何気ない出来事を丁寧に描写する。', 1, 1),
  ('シリアス', 'mood', '緊張感のある重厚なトーン。心理描写を深く掘り下げる。', 1, 2),
  ('地の文多め', 'style', '情景描写と心情描写を厚めに、丁寧な地の文で進める。', 1, 3),
  ('会話中心', 'style', 'セリフを中心にテンポよく、キャラクターの掛け合いで進める。', 1, 4),
  ('出会いのシーン', 'situation', '登場人物が初めて出会う場面から物語を始める。', 1, 5),
  ('日常の一コマ', 'situation', '特別な事件のない、ありふれた一日の出来事を描く。', 1, 6);

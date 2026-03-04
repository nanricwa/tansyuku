-- 短縮URLツール データベーススキーマ
-- MariaDB / MySQL

-- Xserver等では事前にコントロールパネルでDB作成済みのため、以下はコメントアウト
-- CREATE DATABASE IF NOT EXISTS url_shortener CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE url_shortener;

-- ユーザー
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- カテゴリ
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- グループ
CREATE TABLE `groups` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    rules_json TEXT,
    redirect_method VARCHAR(50) NOT NULL DEFAULT 'jump',
    memo TEXT,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    locked_destination_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- グループ転送先
CREATE TABLE group_destinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    destination_url TEXT NOT NULL,
    label VARCHAR(10) NOT NULL DEFAULT '',
    weight INT NOT NULL DEFAULT 1,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 短縮URL
CREATE TABLE urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL DEFAULT '',
    destination_url TEXT NOT NULL,
    redirect_type ENUM('jump', 'preserve') NOT NULL DEFAULT 'jump',
    title_bar_text VARCHAR(255) NOT NULL DEFAULT '',
    category_id INT NULL,
    group_id INT NULL,
    memo TEXT,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL,
    INDEX idx_slug (slug),
    INDEX idx_user_id (user_id),
    INDEX idx_category_id (category_id),
    INDEX idx_group_id (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- クリック記録
CREATE TABLE clicks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    url_id INT NOT NULL,
    destination_id INT NULL,
    clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    referer VARCHAR(1000) NOT NULL DEFAULT '',
    referer_domain VARCHAR(255) NOT NULL DEFAULT '',
    referer_type ENUM('direct', 'search', 'social', 'email', 'other') NOT NULL DEFAULT 'direct',
    device_type ENUM('pc', 'mobile', 'tablet', 'other') NOT NULL DEFAULT 'other',
    os VARCHAR(50) NOT NULL DEFAULT '',
    browser VARCHAR(50) NOT NULL DEFAULT '',
    region VARCHAR(100) NOT NULL DEFAULT '',
    is_unique TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE,
    INDEX idx_url_clicked (url_id, clicked_at),
    INDEX idx_clicked_at (clicked_at),
    INDEX idx_destination_id (destination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- コンバージョン
CREATE TABLE conversions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    url_id INT NOT NULL,
    click_id BIGINT NULL,
    destination_id INT NULL,
    converted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE,
    FOREIGN KEY (click_id) REFERENCES clicks(id) ON DELETE SET NULL,
    INDEX idx_destination_id (destination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 設定
CREATE TABLE settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 操作ログ
CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id INT NULL,
    details TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初期設定データ
INSERT INTO settings (setting_key, setting_value) VALUES
('base_url', 'https://ycscampaign.com/intro'),
('default_slug_type', 'custom'),
('default_redirect_type', 'jump'),
('clipboard_format', '/xxx.html'),
('error_page_url', ''),
('allow_anonymous_create', '0'),
('exclude_own_clicks', '0');

-- =============================================================================
-- 紹介（リファラル）機能
-- =============================================================================

-- 紹介キャンペーン
CREATE TABLE ref_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    destination_url TEXT NOT NULL,
    pass_params TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    memo TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 紹介メンバー
CREATE TABLE ref_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL DEFAULT '',
    group_label VARCHAR(100) NOT NULL DEFAULT '',
    memo TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 紹介訪問記録
CREATE TABLE ref_visits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    member_id INT NULL,
    intro_code VARCHAR(100) NOT NULL DEFAULT '',
    match_code VARCHAR(100) NOT NULL DEFAULT '',
    params_json TEXT,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    referer VARCHAR(1000) NOT NULL DEFAULT '',
    referer_type ENUM('direct','search','social','email','other') NOT NULL DEFAULT 'direct',
    device_type ENUM('pc','mobile','tablet','other') NOT NULL DEFAULT 'other',
    os VARCHAR(50) NOT NULL DEFAULT '',
    browser VARCHAR(50) NOT NULL DEFAULT '',
    is_unique TINYINT(1) NOT NULL DEFAULT 0,
    is_verified TINYINT(1) NOT NULL DEFAULT 1,
    visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES ref_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES ref_members(id) ON DELETE SET NULL,
    INDEX idx_campaign_visited (campaign_id, visited_at),
    INDEX idx_member_visited (member_id, visited_at),
    INDEX idx_intro_code (intro_code),
    INDEX idx_match_code (match_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 紹介コンバージョン
CREATE TABLE ref_conversions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visit_id BIGINT NULL,
    campaign_id INT NOT NULL,
    member_id INT NULL,
    converted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES ref_visits(id) ON DELETE SET NULL,
    FOREIGN KEY (campaign_id) REFERENCES ref_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES ref_members(id) ON DELETE SET NULL,
    INDEX idx_campaign (campaign_id),
    INDEX idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 管理者アカウントはインストーラー（install/index.php）で作成してください

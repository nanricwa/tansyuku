-- Migration 002: 紹介（リファラル）機能
-- 既存のDBに対して実行するマイグレーション

-- 紹介キャンペーン
CREATE TABLE IF NOT EXISTS ref_campaigns (
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

-- 紹介者（会員）
CREATE TABLE IF NOT EXISTS ref_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL DEFAULT '',
    group_label VARCHAR(100) NOT NULL DEFAULT '',
    memo TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 紹介アクセス記録
CREATE TABLE IF NOT EXISTS ref_visits (
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
CREATE TABLE IF NOT EXISTS ref_conversions (
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

-- 署名検証用シークレットキーを設定に追加
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('ref_signature_secret', '');

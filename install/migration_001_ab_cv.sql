-- Migration 001: A/Bテスト機能強化 & CVタグ対応
-- 既存のDBに対して実行するマイグレーション

-- clicksテーブルにdestination_idを追加（どのA/B転送先に振り分けられたか）
ALTER TABLE clicks ADD COLUMN destination_id INT NULL AFTER url_id;
ALTER TABLE clicks ADD INDEX idx_destination_id (destination_id);

-- conversionsテーブルにdestination_idを追加
ALTER TABLE conversions ADD COLUMN destination_id INT NULL AFTER click_id;
ALTER TABLE conversions ADD INDEX idx_destination_id (destination_id);

-- groupsテーブルにロック機能用カラムを追加
ALTER TABLE `groups` ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER memo;
ALTER TABLE `groups` ADD COLUMN locked_destination_id INT NULL AFTER is_locked;

-- group_destinationsにラベル列を追加（A, B, C...と識別用）
ALTER TABLE group_destinations ADD COLUMN label VARCHAR(10) NOT NULL DEFAULT '' AFTER destination_url;

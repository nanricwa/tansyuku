<?php
/**
 * マイグレーション実行スクリプト
 * 既存DBに対してスキーマ変更を適用する
 *
 * ブラウザからアクセス: https://example.com/intro/install/migrate.php
 * CLI: php install/migrate.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$isCli = php_sapi_name() === 'cli';

// config.php が存在するか確認
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    $msg = 'config.php が見つかりません。先にインストーラーを実行してください。';
    if ($isCli) { echo $msg . "\n"; exit(1); }
    die('<h3>' . $msg . '</h3>');
}

require_once $configFile;
require_once __DIR__ . '/../includes/db.php';

$db = Database::getConnection();
$results = [];

/**
 * カラムが存在するか確認
 */
function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * インデックスが存在するか確認
 */
function indexExists(PDO $db, string $table, string $indexName): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

// =============================================================================
// Migration 001: A/Bテスト機能強化 & CVタグ対応
// =============================================================================

// clicks.destination_id
if (!columnExists($db, 'clicks', 'destination_id')) {
    $db->exec('ALTER TABLE clicks ADD COLUMN destination_id INT NULL AFTER url_id');
    $results[] = '✅ clicks.destination_id を追加しました';
} else {
    $results[] = '⏭ clicks.destination_id は既に存在します';
}

if (!indexExists($db, 'clicks', 'idx_destination_id')) {
    $db->exec('ALTER TABLE clicks ADD INDEX idx_destination_id (destination_id)');
    $results[] = '✅ clicks.idx_destination_id インデックスを追加しました';
}

// conversions.destination_id
if (!columnExists($db, 'conversions', 'destination_id')) {
    $db->exec('ALTER TABLE conversions ADD COLUMN destination_id INT NULL AFTER click_id');
    $results[] = '✅ conversions.destination_id を追加しました';
} else {
    $results[] = '⏭ conversions.destination_id は既に存在します';
}

if (!indexExists($db, 'conversions', 'idx_destination_id')) {
    $db->exec('ALTER TABLE conversions ADD INDEX idx_destination_id (destination_id)');
    $results[] = '✅ conversions.idx_destination_id インデックスを追加しました';
}

// groups.is_locked
if (!columnExists($db, 'groups', 'is_locked')) {
    $db->exec('ALTER TABLE `groups` ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER memo');
    $results[] = '✅ groups.is_locked を追加しました';
} else {
    $results[] = '⏭ groups.is_locked は既に存在します';
}

// groups.locked_destination_id
if (!columnExists($db, 'groups', 'locked_destination_id')) {
    $db->exec('ALTER TABLE `groups` ADD COLUMN locked_destination_id INT NULL AFTER is_locked');
    $results[] = '✅ groups.locked_destination_id を追加しました';
} else {
    $results[] = '⏭ groups.locked_destination_id は既に存在します';
}

// group_destinations.label
if (!columnExists($db, 'group_destinations', 'label')) {
    $db->exec("ALTER TABLE group_destinations ADD COLUMN label VARCHAR(10) NOT NULL DEFAULT '' AFTER destination_url");
    $results[] = '✅ group_destinations.label を追加しました';

    // 既存データにA, B, C...のラベルを付与
    $groups = $db->query('SELECT DISTINCT group_id FROM group_destinations ORDER BY group_id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($groups as $gid) {
        $stmt = $db->prepare('SELECT id FROM group_destinations WHERE group_id = ? ORDER BY id');
        $stmt->execute([$gid]);
        $destIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($destIds as $i => $destId) {
            $label = chr(65 + $i); // A, B, C...
            $db->prepare('UPDATE group_destinations SET label = ? WHERE id = ?')->execute([$label, $destId]);
        }
    }
    $results[] = '✅ 既存の転送先にラベル（A, B, C...）を付与しました';
} else {
    $results[] = '⏭ group_destinations.label は既に存在します';
}

// =============================================================================
// Migration 002: 紹介（リファラル）機能
// =============================================================================

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!tableExists($db, 'ref_campaigns')) {
    $db->exec("CREATE TABLE ref_campaigns (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ ref_campaigns テーブルを作成しました';
} else {
    $results[] = '⏭ ref_campaigns は既に存在します';
}

if (!tableExists($db, 'ref_members')) {
    $db->exec("CREATE TABLE ref_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        code VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL DEFAULT '',
        group_label VARCHAR(100) NOT NULL DEFAULT '',
        memo TEXT,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ ref_members テーブルを作成しました';
} else {
    $results[] = '⏭ ref_members は既に存在します';
}

if (!tableExists($db, 'ref_visits')) {
    $db->exec("CREATE TABLE ref_visits (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ ref_visits テーブルを作成しました';
} else {
    $results[] = '⏭ ref_visits は既に存在します';
}

if (!tableExists($db, 'ref_conversions')) {
    $db->exec("CREATE TABLE ref_conversions (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = '✅ ref_conversions テーブルを作成しました';
} else {
    $results[] = '⏭ ref_conversions は既に存在します';
}

// 署名シークレットキー設定
$stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'ref_signature_secret'");
$stmt->execute();
if ((int)$stmt->fetchColumn() === 0) {
    $secret = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('ref_signature_secret', ?)")->execute([$secret]);
    $results[] = '✅ 署名シークレットキーを生成しました';
} else {
    $results[] = '⏭ 署名シークレットキーは既に存在します';
}

// =============================================================================
// Migration 003: 成約通知メール機能
// =============================================================================

if (!columnExists($db, 'ref_campaigns', 'notify_on_cv')) {
    $db->exec("ALTER TABLE ref_campaigns ADD COLUMN notify_on_cv TINYINT(1) NOT NULL DEFAULT 0 AFTER memo");
    $results[] = '✅ ref_campaigns.notify_on_cv を追加しました';
} else {
    $results[] = '⏭ ref_campaigns.notify_on_cv は既に存在します';
}

// =============================================================================
// 出力
// =============================================================================

if ($isCli) {
    echo "=== マイグレーション結果 ===\n";
    foreach ($results as $r) {
        echo $r . "\n";
    }
    echo "完了\n";
} else {
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>マイグレーション - URL Shortener</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <div class="container" style="max-width: 640px; margin-top: 40px;">
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="mb-4">マイグレーション結果</h3>
                <ul class="list-group mb-4">
                    <?php foreach ($results as $r): ?>
                        <li class="list-group-item"><?= $r ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="alert alert-success">マイグレーションが完了しました。</div>
                <a href="../admin/index.php" class="btn btn-primary">管理画面へ</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
}

<?php
/**
 * 短縮URLツール 設定ファイル
 */

// エラー表示（本番ではfalseに）
define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// DB接続情報
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// アプリケーション設定
define('APP_NAME', 'URL Shortener');
define('APP_VERSION', '1.0.0');
define('BASE_PATH', '/intro');

// セッション設定
define('SESSION_LIFETIME', 3600); // 1時間
define('SESSION_NAME', 'urlshortener_session');

// セキュリティ
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15分

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// 文字エンコーディング
mb_internal_encoding('UTF-8');

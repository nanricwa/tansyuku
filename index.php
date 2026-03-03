<?php
/**
 * リダイレクト処理（エントリーポイント）
 * 短縮URLへのアクセスを受けて、転送先へリダイレクト or iframe表示
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/click_tracker.php';

$slug = trim($_GET['slug'] ?? '');
$ext = trim($_GET['ext'] ?? '');

// スラッグが空ならログイン画面へ
if (empty($slug)) {
    header('Location: ' . BASE_PATH . '/admin/index.php');
    exit;
}

// biz.cgi形式のリクエスト対応
if ($slug === 'biz.cgi') {
    // biz.cgi?xxx のようなリクエスト
    $queryKeys = array_keys($_GET);
    $slug = '';
    foreach ($queryKeys as $key) {
        if ($key !== 'slug' && $key !== 'ext') {
            $slug = $key;
            break;
        }
    }
    if (empty($slug)) {
        header('Location: ' . BASE_PATH . '/admin/index.php');
        exit;
    }
}

// DBからURLを検索
$db = Database::getConnection();
$stmt = $db->prepare('SELECT * FROM urls WHERE slug = ? AND is_active = 1');
$stmt->execute([$slug]);
$url = $stmt->fetch();

if (!$url) {
    // エラーページ設定があればそちらへ
    $errorPage = Database::getSetting('error_page_url', '');
    if (!empty($errorPage)) {
        header('Location: ' . $errorPage);
        exit;
    }
    // 簡易エラーページ
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head><meta charset="UTF-8"><title>Not Found</title>
    <style>body{font-family:sans-serif;text-align:center;padding:80px 20px;background:#f8f9fa;}h1{color:#dc3545;}p{color:#666;}</style>
    </head>
    <body><h1>404</h1><p>指定された短縮URLは存在しないか、無効になっています。</p></body>
    </html>
    <?php
    exit;
}

// 有効期限チェック
if ($url['expires_at'] && strtotime($url['expires_at']) < time()) {
    $errorPage = Database::getSetting('error_page_url', '');
    if (!empty($errorPage)) {
        header('Location: ' . $errorPage);
        exit;
    }
    http_response_code(410);
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head><meta charset="UTF-8"><title>Expired</title>
    <style>body{font-family:sans-serif;text-align:center;padding:80px 20px;background:#f8f9fa;}h1{color:#ffc107;}p{color:#666;}</style>
    </head>
    <body><h1>期限切れ</h1><p>この短縮URLは有効期限が切れています。</p></body>
    </html>
    <?php
    exit;
}

// クリック記録
ClickTracker::track($url['id']);

// グループ振り分けがある場合
$destinationUrl = $url['destination_url'];
if ($url['group_id']) {
    $resolved = resolveGroupDestination($url['group_id'], $url['id']);
    if ($resolved) {
        $destinationUrl = $resolved;
    }
}

// 転送処理
if ($url['redirect_type'] === 'preserve') {
    // URL保持（iframe）
    $title = $url['title_bar_text'] ?: 'Redirecting...';
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
        <style>
            body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; }
            iframe { width: 100%; height: 100%; border: none; }
        </style>
    </head>
    <body>
        <iframe src="<?= htmlspecialchars($destinationUrl, ENT_QUOTES, 'UTF-8') ?>"></iframe>
    </body>
    </html>
    <?php
} else {
    // ジャンプ（302リダイレクト）
    header('Location: ' . $destinationUrl, true, 302);
}
exit;

/**
 * グループの振り分け先を決定
 */
function resolveGroupDestination(int $groupId, int $urlId): ?string
{
    $db = Database::getConnection();

    // グループの転送先リストを取得
    $stmt = $db->prepare('SELECT * FROM group_destinations WHERE group_id = ? ORDER BY id');
    $stmt->execute([$groupId]);
    $destinations = $stmt->fetchAll();

    if (empty($destinations)) {
        return null;
    }

    // グループのルールを取得
    $stmt = $db->prepare('SELECT * FROM `groups` WHERE id = ?');
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    if (!$group) {
        return null;
    }

    $rules = json_decode($group['rules_json'] ?? '{}', true) ?: [];

    // ルールに基づく固定判定
    if (!empty($rules['fix_on_conversion_rate_diff'])) {
        // 成約率の差に基づく固定ロジック
        // (Phase 3で詳細実装)
    }

    // 重み付きランダム振り分け
    $totalWeight = array_sum(array_column($destinations, 'weight'));
    $rand = random_int(1, max(1, $totalWeight));
    $cumulative = 0;

    foreach ($destinations as $dest) {
        $cumulative += $dest['weight'];
        if ($rand <= $cumulative) {
            return $dest['destination_url'];
        }
    }

    return $destinations[0]['destination_url'];
}

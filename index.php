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

// グループ振り分けがある場合
$destinationUrl = $url['destination_url'];
$destinationId = null;

if ($url['group_id']) {
    $resolved = resolveGroupDestination($url['group_id'], $url['id']);
    if ($resolved) {
        $destinationUrl = $resolved['url'];
        $destinationId = $resolved['destination_id'];
    }
}

// クリック記録（destination_id付き）
ClickTracker::track($url['id'], $destinationId);

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
 * @return array|null ['url' => string, 'destination_id' => int]
 */
function resolveGroupDestination(int $groupId, int $urlId): ?array
{
    $db = Database::getConnection();

    // グループ情報を取得
    $stmt = $db->prepare('SELECT * FROM `groups` WHERE id = ?');
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    if (!$group) {
        return null;
    }

    // グループの転送先リストを取得
    $stmt = $db->prepare('SELECT * FROM group_destinations WHERE group_id = ? ORDER BY id');
    $stmt->execute([$groupId]);
    $destinations = $stmt->fetchAll();

    if (empty($destinations)) {
        return null;
    }

    // ロック済みの場合: 固定先に転送
    if ($group['is_locked'] && $group['locked_destination_id']) {
        foreach ($destinations as $dest) {
            if ((int)$dest['id'] === (int)$group['locked_destination_id']) {
                return ['url' => $dest['destination_url'], 'destination_id' => (int)$dest['id']];
            }
        }
    }

    $rules = json_decode($group['rules_json'] ?? '{}', true) ?: [];

    // ルールに基づく自動ロック判定
    $rateDiffThreshold = (float)($rules['fix_on_conversion_rate_diff'] ?? 0);
    $countMin = (int)($rules['fix_on_conversion_count_min'] ?? 0);
    $fixMethod = $rules['fix_method'] ?? 'best';

    if ($rateDiffThreshold > 0 && $countMin > 0 && count($destinations) >= 2) {
        // 各転送先のクリック数・成約数を取得
        $destStats = [];
        foreach ($destinations as $dest) {
            $destId = (int)$dest['id'];

            // このdestinationのクリック数（ユニーク）
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM clicks WHERE destination_id = ? AND is_unique = 1'
            );
            $stmt->execute([$destId]);
            $uniqueClicks = (int)$stmt->fetchColumn();

            // このdestinationのCV数
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM conversions WHERE destination_id = ?'
            );
            $stmt->execute([$destId]);
            $conversions = (int)$stmt->fetchColumn();

            $cvRate = $uniqueClicks > 0 ? ($conversions / $uniqueClicks * 100) : 0;

            $destStats[] = [
                'id' => $destId,
                'destination_url' => $dest['destination_url'],
                'unique_clicks' => $uniqueClicks,
                'conversions' => $conversions,
                'cv_rate' => $cvRate,
            ];
        }

        // 全転送先の合計CV数が最低ラインを超えているかチェック
        $totalConversions = array_sum(array_column($destStats, 'conversions'));

        if ($totalConversions >= $countMin) {
            // 成約率でソート（降順）
            usort($destStats, fn($a, $b) => $b['cv_rate'] <=> $a['cv_rate']);
            $best = $destStats[0];
            $secondBest = $destStats[1];

            // 成約率の差が閾値を超えているか
            $diff = $best['cv_rate'] - $secondBest['cv_rate'];

            if ($diff >= $rateDiffThreshold) {
                // 勝者確定！ロックする
                $winnerId = ($fixMethod === 'first')
                    ? (int)$destinations[0]['id']  // 初回転送先を固定
                    : (int)$best['id'];            // 成約率最高を固定

                $stmt = $db->prepare('UPDATE `groups` SET is_locked = 1, locked_destination_id = ? WHERE id = ?');
                $stmt->execute([$winnerId, $groupId]);

                // 勝者にリダイレクト
                foreach ($destinations as $dest) {
                    if ((int)$dest['id'] === $winnerId) {
                        return ['url' => $dest['destination_url'], 'destination_id' => $winnerId];
                    }
                }
            }
        }
    }

    // 重み付きランダム振り分け
    $totalWeight = array_sum(array_column($destinations, 'weight'));
    $rand = random_int(1, max(1, $totalWeight));
    $cumulative = 0;

    foreach ($destinations as $dest) {
        $cumulative += $dest['weight'];
        if ($rand <= $cumulative) {
            return ['url' => $dest['destination_url'], 'destination_id' => (int)$dest['id']];
        }
    }

    return ['url' => $destinations[0]['destination_url'], 'destination_id' => (int)$destinations[0]['id']];
}

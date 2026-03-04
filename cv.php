<?php
/**
 * コンバージョン（成約）記録エンドポイント
 *
 * 成約ページにこのURLを指すタグを設置することで、成約を自動記録する。
 *
 * ■ 共通タグ（推奨）- slug不要。IP+UAから直近クリックを自動特定:
 *   <img src="https://example.com/intro/cv.php" width="1" height="1" style="display:none">
 *   → 複数チャネル（短縮URL）から同じサンクスページに来る場合、タグ1個でOK
 *
 * ■ 個別タグ - 特定の短縮URLのみ計測:
 *   <img src="https://example.com/intro/cv.php?slug=xxx" width="1" height="1" style="display:none">
 *
 * ■ JavaScript方式:
 *   <script src="https://example.com/intro/cv.php?format=js"></script>
 *
 * パラメータ:
 *   slug   = 短縮URLのスラッグ（省略時: 直近クリックから自動判定）
 *   format = 応答形式 'pixel'(default) または 'js'
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// CORSヘッダー（外部サイトからの呼び出し対応）
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$slug = trim($_GET['slug'] ?? '');
$format = trim($_GET['format'] ?? 'pixel');

$db = Database::getConnection();
$ip = getClientIp();
$ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

if (!empty($slug)) {
    // ── 個別モード: slug指定あり → そのURLに対してCV記録 ──
    $stmt = $db->prepare('SELECT id FROM urls WHERE slug = ? AND is_active = 1');
    $stmt->execute([$slug]);
    $url = $stmt->fetch();

    if (!$url) {
        outputResponse($format, false);
        exit;
    }

    recordConversion($db, (int)$url['id'], $ip, $ua);

} else {
    // ── 共通モード: slug省略 → IP+UAから直近72hのクリックを全て探してCV記録 ──
    //    同じ人が複数の短縮URLを踏んでいた場合、各URLに対してそれぞれ1件CVを記録する
    $stmt = $db->prepare(
        'SELECT c.url_id, c.id AS click_id, c.destination_id
         FROM clicks c
         WHERE c.ip_address = ? AND c.user_agent = ?
           AND c.clicked_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)
         ORDER BY c.clicked_at DESC'
    );
    $stmt->execute([$ip, $ua]);
    $recentClicks = $stmt->fetchAll();

    if (empty($recentClicks)) {
        outputResponse($format, false);
        exit;
    }

    // url_id ごとに最新の1クリックだけ取得（重複排除）
    $processedUrlIds = [];
    $recorded = false;

    foreach ($recentClicks as $click) {
        $urlId = (int)$click['url_id'];

        // 同じurl_idは最初（最新）の1件だけ処理
        if (isset($processedUrlIds[$urlId])) {
            continue;
        }
        $processedUrlIds[$urlId] = true;

        $result = recordConversion($db, $urlId, $ip, $ua, (int)$click['click_id'],
            $click['destination_id'] ? (int)$click['destination_id'] : null);
        if ($result) {
            $recorded = true;
        }
    }

    if (!$recorded) {
        outputResponse($format, true); // 重複で弾かれただけなのでtrue
        exit;
    }
}

outputResponse($format, true);
exit;

// =============================================================================

/**
 * コンバージョンを記録する
 *
 * @return bool 記録したらtrue、重複等で記録しなかったらfalse
 */
function recordConversion(PDO $db, int $urlId, string $ip, string $ua,
                          ?int $clickId = null, ?int $destinationId = null): bool
{
    // 重複コンバージョン防止: 同一 URL + IP + UA で24h以内に既にCVがあればスキップ
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM conversions cv
         INNER JOIN clicks c ON cv.click_id = c.id
         WHERE cv.url_id = ? AND c.ip_address = ? AND c.user_agent = ?
         AND cv.converted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
    $stmt->execute([$urlId, $ip, $ua]);

    if ((int)$stmt->fetchColumn() > 0) {
        return false; // 既に記録済み
    }

    // clickId未指定の場合（個別モード）は直近クリックを検索
    if ($clickId === null) {
        $stmt = $db->prepare(
            'SELECT id, destination_id FROM clicks
             WHERE url_id = ? AND ip_address = ? AND user_agent = ?
             AND clicked_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)
             ORDER BY clicked_at DESC LIMIT 1'
        );
        $stmt->execute([$urlId, $ip, $ua]);
        $click = $stmt->fetch();

        if ($click) {
            $clickId = (int)$click['id'];
            $destinationId = $click['destination_id'] ? (int)$click['destination_id'] : null;
        }
    }

    // CV記録
    $stmt = $db->prepare(
        'INSERT INTO conversions (url_id, click_id, destination_id, converted_at)
         VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([$urlId, $clickId, $destinationId]);

    return true;
}

/**
 * レスポンスを出力
 */
function outputResponse(string $format, bool $success): void
{
    if ($format === 'js') {
        header('Content-Type: application/javascript; charset=UTF-8');
        $status = $success ? 'ok' : 'error';
        echo "/* CV Tracking */ (function(){var d=document,i=d.createElement('div');i.style.display='none';i.setAttribute('data-cv-status','{$status}');d.body&&d.body.appendChild(i);})();";
    } else {
        // 1x1 透明GIF
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
}

/**
 * クライアントIPアドレスを取得
 */
function getClientIp(): string
{
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

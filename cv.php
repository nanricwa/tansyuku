<?php
/**
 * コンバージョン（成約）記録エンドポイント
 *
 * 成約ページにこのURLを指すタグを設置することで、成約を自動記録する。
 *
 * 使い方（HTMLピクセル）:
 *   <img src="https://example.com/intro/cv.php?slug=xxx" width="1" height="1" style="display:none">
 *
 * 使い方（JavaScript）:
 *   <script src="https://example.com/intro/cv.php?slug=xxx&format=js"></script>
 *
 * パラメータ:
 *   slug   = 短縮URLのスラッグ（必須）
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

if (empty($slug)) {
    outputResponse($format, false);
    exit;
}

$db = Database::getConnection();

// URLを検索
$stmt = $db->prepare('SELECT id, group_id FROM urls WHERE slug = ? AND is_active = 1');
$stmt->execute([$slug]);
$url = $stmt->fetch();

if (!$url) {
    outputResponse($format, false);
    exit;
}

$urlId = (int)$url['id'];
$ip = getClientIp();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// 重複コンバージョン防止: 同一 URL + IP + UA で24時間以内に既にCVがあればスキップ
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM conversions cv
     INNER JOIN clicks c ON cv.click_id = c.id
     WHERE cv.url_id = ? AND c.ip_address = ? AND c.user_agent = ?
     AND cv.converted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
);
$stmt->execute([$urlId, $ip, mb_substr($ua, 0, 500)]);

if ((int)$stmt->fetchColumn() > 0) {
    // 既に記録済み
    outputResponse($format, true);
    exit;
}

// このIPの直近のクリックを検索（72時間以内）
$stmt = $db->prepare(
    'SELECT id, destination_id FROM clicks
     WHERE url_id = ? AND ip_address = ? AND user_agent = ?
     AND clicked_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)
     ORDER BY clicked_at DESC LIMIT 1'
);
$stmt->execute([$urlId, $ip, mb_substr($ua, 0, 500)]);
$click = $stmt->fetch();

$clickId = $click ? (int)$click['id'] : null;
$destinationId = $click ? ($click['destination_id'] ? (int)$click['destination_id'] : null) : null;

// コンバージョンを記録
$stmt = $db->prepare(
    'INSERT INTO conversions (url_id, click_id, destination_id, converted_at)
     VALUES (?, ?, ?, NOW())'
);
$stmt->execute([$urlId, $clickId, $destinationId]);

outputResponse($format, true);
exit;

// =============================================================================

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

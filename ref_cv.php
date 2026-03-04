<?php
/**
 * 紹介コンバージョン記録エンドポイント
 *
 * 紹介経由で来た人が成約ページに到達したことを記録する。
 *
 * 使い方（共通タグ）:
 *   <img src="https://ycscampaign.com/intro/ref_cv.php" width="1" height="1" style="display:none">
 *
 * 使い方（キャンペーン指定）:
 *   <img src="https://ycscampaign.com/intro/ref_cv.php?campaign=fjbA1" width="1" height="1" style="display:none">
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$campaignSlug = trim($_GET['campaign'] ?? '');
$format = trim($_GET['format'] ?? 'pixel');

$db = Database::getConnection();
$ip = getClientIp();
$ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

if (!empty($campaignSlug)) {
    // 特定キャンペーンのCV
    $stmt = $db->prepare('SELECT id FROM ref_campaigns WHERE slug = ?');
    $stmt->execute([$campaignSlug]);
    $campaign = $stmt->fetch();

    if ($campaign) {
        recordRefConversion($db, (int)$campaign['id'], $ip, $ua);
    }
} else {
    // 共通モード: IP+UAから直近の紹介訪問を検索
    $stmt = $db->prepare(
        'SELECT campaign_id, member_id, id AS visit_id
         FROM ref_visits
         WHERE ip_address = ? AND user_agent = ?
         AND visited_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)
         ORDER BY visited_at DESC'
    );
    $stmt->execute([$ip, $ua]);
    $visits = $stmt->fetchAll();

    $processedCampaigns = [];
    foreach ($visits as $visit) {
        $cid = (int)$visit['campaign_id'];
        if (isset($processedCampaigns[$cid])) continue;
        $processedCampaigns[$cid] = true;

        recordRefConversion($db, $cid, $ip, $ua,
            (int)$visit['visit_id'],
            $visit['member_id'] ? (int)$visit['member_id'] : null);
    }
}

outputRefResponse($format);
exit;

// =============================================================================

function recordRefConversion(PDO $db, int $campaignId, string $ip, string $ua,
                              ?int $visitId = null, ?int $memberId = null): bool
{
    // 重複防止: 同一キャンペーン + IP + UA で24h以内
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM ref_conversions rc
         LEFT JOIN ref_visits rv ON rc.visit_id = rv.id
         WHERE rc.campaign_id = ? AND rv.ip_address = ? AND rv.user_agent = ?
         AND rc.converted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
    $stmt->execute([$campaignId, $ip, $ua]);
    if ((int)$stmt->fetchColumn() > 0) {
        return false;
    }

    // visitIdが未指定なら検索
    if ($visitId === null) {
        $stmt = $db->prepare(
            'SELECT id, member_id FROM ref_visits
             WHERE campaign_id = ? AND ip_address = ? AND user_agent = ?
             AND visited_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)
             ORDER BY visited_at DESC LIMIT 1'
        );
        $stmt->execute([$campaignId, $ip, $ua]);
        $visit = $stmt->fetch();
        if ($visit) {
            $visitId = (int)$visit['id'];
            $memberId = $visit['member_id'] ? (int)$visit['member_id'] : null;
        }
    }

    $stmt = $db->prepare(
        'INSERT INTO ref_conversions (visit_id, campaign_id, member_id, converted_at)
         VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([$visitId, $campaignId, $memberId]);
    return true;
}

function outputRefResponse(string $format): void
{
    if ($format === 'js') {
        header('Content-Type: application/javascript; charset=UTF-8');
        echo "/* Ref CV */ (function(){})();";
    } else {
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
}

function getClientIp(): string
{
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

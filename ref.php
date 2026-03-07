<?php
/**
 * 紹介リダイレクト処理
 *
 * URL形式: /ref/CAMPAIGN_SLUG?intro=CODE&match=XXX&sig=XXXX
 *
 * 1. パラメータを記録（紹介者、マッチ、デバイス等）
 * 2. キャンペーンの転送先URLにリダイレクト
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/useragent.php';
require_once __DIR__ . '/includes/referral.php';

$campaignSlug = trim($_GET['campaign'] ?? '');

if (empty($campaignSlug)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not Found</title></head><body><h1>404</h1></body></html>';
    exit;
}

$db = Database::getConnection();

// キャンペーンを検索
$stmt = $db->prepare('SELECT * FROM ref_campaigns WHERE slug = ? AND is_active = 1');
$stmt->execute([$campaignSlug]);
$campaign = $stmt->fetch();

if (!$campaign) {
    http_response_code(404);
    $errorPage = Database::getSetting('error_page_url', '');
    if (!empty($errorPage)) {
        header('Location: ' . $errorPage);
        exit;
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not Found</title>
    <style>body{font-family:sans-serif;text-align:center;padding:80px 20px;background:#f8f9fa;}h1{color:#dc3545;}</style>
    </head><body><h1>404</h1><p>指定されたキャンペーンは存在しないか、無効になっています。</p></body></html>';
    exit;
}

// 有効期間チェック
if ($campaign['starts_at'] && strtotime($campaign['starts_at']) > time()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Not Yet</title>
    <style>body{font-family:sans-serif;text-align:center;padding:80px 20px;background:#f8f9fa;}h1{color:#ffc107;}</style>
    </head><body><h1>準備中</h1><p>このキャンペーンはまだ開始されていません。</p></body></html>';
    exit;
}
if ($campaign['ends_at'] && strtotime($campaign['ends_at']) < time()) {
    http_response_code(410);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Ended</title>
    <style>body{font-family:sans-serif;text-align:center;padding:80px 20px;background:#f8f9fa;}h1{color:#6c757d;}</style>
    </head><body><h1>終了</h1><p>このキャンペーンは終了しました。</p></body></html>';
    exit;
}

// パラメータ取得
$introCode = trim($_GET['intro'] ?? '');
$matchCode = trim($_GET['match'] ?? '');
$sig = trim($_GET['sig'] ?? '');

// 署名検証
$isVerified = true;
if (!empty($sig)) {
    $isVerified = Referral::verifySignature($_GET);
}

// 紹介者を検索
$memberId = null;
if (!empty($introCode)) {
    $stmt = $db->prepare('SELECT id FROM ref_members WHERE code = ? AND is_active = 1');
    $stmt->execute([$introCode]);
    $member = $stmt->fetch();
    if ($member) {
        $memberId = (int)$member['id'];
    }
}

// アクセス情報を収集
$ip = Referral::getClientIp();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$device = UserAgentParser::parse($ua);
$refererType = classifyReferer($referer);
$isUnique = Referral::isUniqueVisit((int)$campaign['id'], $ip, $ua);

// 全GETパラメータをJSON保存（campaign, sigは除外）
$allParams = $_GET;
unset($allParams['campaign'], $allParams['sig']);
$paramsJson = !empty($allParams) ? json_encode($allParams, JSON_UNESCAPED_UNICODE) : null;

// アクセスを記録
$stmt = $db->prepare(
    'INSERT INTO ref_visits
     (campaign_id, member_id, intro_code, match_code, params_json,
      ip_address, user_agent, referer, referer_type, device_type, os, browser,
      is_unique, is_verified)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    (int)$campaign['id'],
    $memberId,
    $introCode,
    $matchCode,
    $paramsJson,
    $ip,
    mb_substr($ua, 0, 500),
    mb_substr($referer, 0, 1000),
    $refererType,
    $device['device_type'],
    $device['os'],
    $device['browser'],
    $isUnique ? 1 : 0,
    $isVerified ? 1 : 0,
]);

// 転送先URLを構築
$destinationUrl = $campaign['destination_url'];

// パラメータをパススルーする場合
if ($campaign['pass_params'] && !empty($allParams)) {
    $separator = (strpos($destinationUrl, '?') !== false) ? '&' : '?';
    $destinationUrl .= $separator . http_build_query($allParams);
}

// リダイレクト
header('Location: ' . $destinationUrl, true, 302);
exit;

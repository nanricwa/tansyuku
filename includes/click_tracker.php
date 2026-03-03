<?php
/**
 * クリック記録処理
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/useragent.php';
require_once __DIR__ . '/functions.php';

class ClickTracker
{
    /**
     * クリックを記録
     */
    public static function track(int $urlId): void
    {
        $db = Database::getConnection();

        $ip = self::getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        // 自分のクリック除外チェック
        if (Database::getSetting('exclude_own_clicks', '0') === '1') {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (isset($_SESSION['user_id'])) {
                return;
            }
        }

        // デバイス/OS/ブラウザ解析
        $device = UserAgentParser::parse($ua);

        // リファラー解析
        $refererDomain = extractRefererDomain($referer);
        $refererType = classifyReferer($referer);

        // ユニーク判定（同一URL + IP + UA の組み合わせで24h以内に既存があればユニークでない）
        $isUnique = self::isUniqueClick($urlId, $ip, $ua);

        $stmt = $db->prepare(
            'INSERT INTO clicks (url_id, ip_address, user_agent, referer, referer_domain, referer_type, device_type, os, browser, region, is_unique)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $urlId,
            $ip,
            mb_substr($ua, 0, 500),
            mb_substr($referer, 0, 1000),
            $refererDomain,
            $refererType,
            $device['device_type'],
            $device['os'],
            $device['browser'],
            '', // 地域は後でGeoIPで対応
            $isUnique ? 1 : 0,
        ]);
    }

    /**
     * ユニーククリック判定
     */
    private static function isUniqueClick(int $urlId, string $ip, string $ua): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM clicks WHERE url_id = ? AND ip_address = ? AND user_agent = ? AND clicked_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $stmt->execute([$urlId, $ip, mb_substr($ua, 0, 500)]);
        return (int)$stmt->fetchColumn() === 0;
    }

    /**
     * クライアントIPアドレスを取得
     */
    private static function getClientIp(): string
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
}

<?php
/**
 * 紹介（リファラル）機能ヘルパー
 */

require_once __DIR__ . '/db.php';

class Referral
{
    /**
     * 署名シークレットキーを取得（未設定なら自動生成）
     */
    public static function getSecret(): string
    {
        $secret = Database::getSetting('ref_signature_secret', '');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32));
            Database::setSetting('ref_signature_secret', $secret);
        }
        return $secret;
    }

    /**
     * パラメータから署名を生成
     * @param array $params ['intro' => 'TANAKA', 'match' => '202603']
     * @return string 12文字の署名
     */
    public static function generateSignature(array $params): string
    {
        // 署名対象のパラメータをソートして結合（sigは除外）
        unset($params['sig']);
        ksort($params);
        $payload = http_build_query($params);
        $hash = hash_hmac('sha256', $payload, self::getSecret());
        return substr($hash, 0, 12);
    }

    /**
     * 署名を検証
     * @param array $params 全GETパラメータ（sig含む）
     * @return bool
     */
    public static function verifySignature(array $params): bool
    {
        $sig = $params['sig'] ?? '';
        if (empty($sig)) {
            return false;
        }
        $expected = self::generateSignature($params);
        return hash_equals($expected, $sig);
    }

    /**
     * 紹介リンクを生成
     * @param string $campaignSlug キャンペーンスラッグ
     * @param string $introCode 紹介者コード
     * @param string $matchCode マッチコード（オプション）
     * @param array $extraParams 追加パラメータ
     * @return string 完全なURL
     */
    public static function buildReferralUrl(string $campaignSlug, string $introCode,
                                             string $matchCode = '', array $extraParams = []): string
    {
        $baseUrl = Database::getSetting('base_url', 'https://example.com/intro');
        $params = ['intro' => $introCode];
        if (!empty($matchCode)) {
            $params['match'] = $matchCode;
        }
        $params = array_merge($params, $extraParams);

        // 署名を付与
        $params['sig'] = self::generateSignature($params);

        return $baseUrl . '/ref/' . $campaignSlug . '?' . http_build_query($params);
    }

    /**
     * ユニーク訪問判定（キャンペーン + IP + UA で24h以内）
     */
    public static function isUniqueVisit(int $campaignId, string $ip, string $ua): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM ref_visits
             WHERE campaign_id = ? AND ip_address = ? AND user_agent = ?
             AND visited_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $stmt->execute([$campaignId, $ip, mb_substr($ua, 0, 500)]);
        return (int)$stmt->fetchColumn() === 0;
    }

    /**
     * キャンペーンの統計を取得
     */
    public static function getCampaignStats(int $campaignId): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT COUNT(*) FROM ref_visits WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);
        $totalVisits = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM ref_visits WHERE campaign_id = ? AND is_unique = 1');
        $stmt->execute([$campaignId]);
        $uniqueVisits = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM ref_conversions WHERE campaign_id = ?');
        $stmt->execute([$campaignId]);
        $conversions = (int)$stmt->fetchColumn();

        $cvRate = $uniqueVisits > 0 ? round($conversions / $uniqueVisits * 100, 2) : 0;

        return [
            'total_visits' => $totalVisits,
            'unique_visits' => $uniqueVisits,
            'conversions' => $conversions,
            'cv_rate' => $cvRate,
        ];
    }

    /**
     * 紹介者の統計を取得
     */
    public static function getMemberStats(int $memberId, ?int $campaignId = null): array
    {
        $db = Database::getConnection();
        $campaignWhere = $campaignId ? ' AND campaign_id = ?' : '';
        $params = [$memberId];
        if ($campaignId) $params[] = $campaignId;

        $stmt = $db->prepare('SELECT COUNT(*) FROM ref_visits WHERE member_id = ?' . $campaignWhere);
        $stmt->execute($params);
        $totalVisits = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM ref_visits WHERE member_id = ? AND is_unique = 1' . $campaignWhere);
        $stmt->execute($params);
        $uniqueVisits = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM ref_conversions WHERE member_id = ?' . $campaignWhere);
        $stmt->execute($params);
        $conversions = (int)$stmt->fetchColumn();

        $cvRate = $uniqueVisits > 0 ? round($conversions / $uniqueVisits * 100, 2) : 0;

        return [
            'total_visits' => $totalVisits,
            'unique_visits' => $uniqueVisits,
            'conversions' => $conversions,
            'cv_rate' => $cvRate,
        ];
    }

    /**
     * 発行済みリンクを記録（ON DUPLICATE KEY UPDATEで冪等）
     */
    public static function recordIssuedLink(int $campaignId, int $memberId,
                                             string $matchCode, string $fullUrl, ?int $issuedBy = null): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO ref_issued_links (campaign_id, member_id, match_code, full_url, issued_at, issued_by)
             VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE full_url = VALUES(full_url), issued_at = NOW(), issued_by = VALUES(issued_by)'
        );
        $stmt->execute([$campaignId, $memberId, $matchCode, $fullUrl, $issuedBy]);
    }

    /**
     * メンバーの発行済みリンク取得
     */
    public static function getIssuedLinks(int $memberId, ?int $campaignId = null): array
    {
        $db = Database::getConnection();
        $sql = 'SELECT il.*, rc.name AS campaign_name, rc.slug AS campaign_slug,
                       rc.is_active AS campaign_active, rc.starts_at, rc.ends_at,
                       rm.is_active AS member_active
                FROM ref_issued_links il
                JOIN ref_campaigns rc ON il.campaign_id = rc.id
                JOIN ref_members rm ON il.member_id = rm.id
                WHERE il.member_id = ?';
        $params = [$memberId];
        if ($campaignId) {
            $sql .= ' AND il.campaign_id = ?';
            $params[] = $campaignId;
        }
        $sql .= ' ORDER BY il.issued_at DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * リンクのステータス判定
     * @param array $link getIssuedLinks()の1行
     * @return array ['label' => '有効', 'color' => 'success']
     */
    public static function getLinkStatus(array $link): array
    {
        if (!$link['member_active']) {
            return ['label' => 'メンバー無効', 'color' => 'secondary'];
        }
        if (!$link['campaign_active']) {
            return ['label' => 'CP無効', 'color' => 'danger'];
        }
        $now = date('Y-m-d H:i:s');
        if (!empty($link['starts_at']) && $link['starts_at'] > $now) {
            return ['label' => '未開始', 'color' => 'warning'];
        }
        if (!empty($link['ends_at']) && $link['ends_at'] < $now) {
            return ['label' => '期限切れ', 'color' => 'secondary'];
        }
        return ['label' => '有効', 'color' => 'success'];
    }

    /**
     * クライアントIPアドレスを取得
     */
    public static function getClientIp(): string
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

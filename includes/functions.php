<?php
/**
 * ユーティリティ関数
 */

/**
 * HTMLエスケープ
 */
function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * スラッグ生成: index+アルファベット方式
 * 例: indexab, indexac, ...
 */
function generateAlphabetSlug(): string
{
    $db = Database::getConnection();
    // 既存のindex系スラッグの最大値を取得
    $stmt = $db->query("SELECT slug FROM urls WHERE slug LIKE 'index%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();

    if (!$last || $last === 'index') {
        return 'indexaa';
    }

    $suffix = substr($last, 5); // 'index' の後
    if (empty($suffix)) {
        return 'indexaa';
    }

    // アルファベットをインクリメント
    $next = incrementAlpha($suffix);
    return 'index' . $next;
}

/**
 * アルファベット文字列をインクリメント
 * aa -> ab -> ... -> az -> ba -> ... -> zz -> aaa
 */
function incrementAlpha(string $str): string
{
    $chars = str_split($str);
    $i = count($chars) - 1;
    while ($i >= 0) {
        if ($chars[$i] === 'z') {
            $chars[$i] = 'a';
            $i--;
        } else {
            $chars[$i] = chr(ord($chars[$i]) + 1);
            return implode('', $chars);
        }
    }
    return 'a' . implode('', $chars);
}

/**
 * スラッグ生成: ランダム単語方式
 */
function generateWordSlug(): string
{
    $words = [
        'apple', 'beach', 'cloud', 'dance', 'eagle', 'flame', 'grace', 'heart',
        'ivory', 'jewel', 'karma', 'lunar', 'magic', 'noble', 'ocean', 'peace',
        'quest', 'river', 'solar', 'tiger', 'ultra', 'vivid', 'waves', 'xenon',
        'youth', 'blaze', 'coral', 'delta', 'ember', 'frost', 'globe', 'haven',
        'ideas', 'jolly', 'light', 'maple', 'north', 'olive', 'pride', 'royal',
        'shine', 'terra', 'unity', 'valor', 'winds', 'amber', 'brave', 'crisp',
    ];

    $db = Database::getConnection();
    $maxAttempts = 100;

    for ($i = 0; $i < $maxAttempts; $i++) {
        $word = $words[array_rand($words)];
        $stmt = $db->prepare('SELECT COUNT(*) FROM urls WHERE slug = ?');
        $stmt->execute([$word]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $word;
        }
    }

    // 単語が枯渇した場合はランダム文字列
    return generateRandomSlug(8);
}

/**
 * ランダム文字列スラッグ
 */
function generateRandomSlug(int $length = 8): string
{
    $db = Database::getConnection();
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $maxAttempts = 100;

    for ($i = 0; $i < $maxAttempts; $i++) {
        $slug = '';
        for ($j = 0; $j < $length; $j++) {
            $slug .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM urls WHERE slug = ?');
        $stmt->execute([$slug]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
    }

    return bin2hex(random_bytes(4));
}

/**
 * スラッグが使用可能か
 */
function isSlugAvailable(string $slug, ?int $excludeId = null): bool
{
    $db = Database::getConnection();
    $reservedSlugs = ['admin', 'api', 'assets', 'includes', 'templates', 'install', 'vendor', 'data'];

    if (in_array(strtolower($slug), $reservedSlugs)) {
        return false;
    }

    if ($excludeId) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM urls WHERE slug = ? AND id != ?');
        $stmt->execute([$slug, $excludeId]);
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM urls WHERE slug = ?');
        $stmt->execute([$slug]);
    }

    return (int)$stmt->fetchColumn() === 0;
}

/**
 * 短縮URLのフルURLを生成
 */
function buildShortUrl(string $slug, string $format = ''): string
{
    $baseUrl = Database::getSetting('base_url', 'https://example.com/url');

    switch ($format) {
        case 'htm':
            return $baseUrl . '/' . $slug . '.htm';
        case 'html':
            return $baseUrl . '/' . $slug . '.html';
        case 'dir':
            return $baseUrl . '/' . $slug . '/';
        default:
            return $baseUrl . '/' . $slug;
    }
}

/**
 * ページネーション計算
 */
function paginate(int $totalItems, int $currentPage, int $perPage = 20): array
{
    $totalPages = max(1, ceil($totalItems / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total_items' => $totalItems,
        'total_pages' => $totalPages,
        'current_page' => $currentPage,
        'per_page' => $perPage,
        'offset' => $offset,
    ];
}

/**
 * フラッシュメッセージを設定
 */
function setFlash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * フラッシュメッセージを取得（取得後削除）
 */
function getFlash(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * リファラーの種別を判定
 */
function classifyReferer(string $referer): string
{
    if (empty($referer)) {
        return 'direct';
    }

    $domain = parse_url($referer, PHP_URL_HOST) ?? '';
    $domain = strtolower($domain);

    // 検索エンジン
    $searchEngines = ['google.', 'yahoo.', 'bing.', 'baidu.', 'duckduckgo.'];
    foreach ($searchEngines as $se) {
        if (strpos($domain, $se) !== false) {
            return 'search';
        }
    }

    // SNS
    $socialNetworks = ['twitter.com', 'x.com', 't.co', 'facebook.com', 'fb.com', 'instagram.com',
        'linkedin.com', 'youtube.com', 'tiktok.com', 'pinterest.com', 'line.me'];
    foreach ($socialNetworks as $sn) {
        if (strpos($domain, $sn) !== false) {
            return 'social';
        }
    }

    // メール
    $emailDomains = ['mail.google.com', 'outlook.live.com', 'mail.yahoo.'];
    foreach ($emailDomains as $ed) {
        if (strpos($domain, $ed) !== false) {
            return 'email';
        }
    }

    return 'other';
}

/**
 * リファラーからドメインを抽出
 */
function extractRefererDomain(string $referer): string
{
    if (empty($referer)) {
        return '';
    }
    return parse_url($referer, PHP_URL_HOST) ?? '';
}

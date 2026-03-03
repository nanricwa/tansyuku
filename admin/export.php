<?php
/**
 * CSVエクスポート
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();

$db = Database::getConnection();

$type = $_GET['type'] ?? 'analytics';
$urlId = (int)($_GET['url_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');

// BOMつきUTF-8でCSV出力
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="export_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');
// BOM
fwrite($output, "\xEF\xBB\xBF");

if ($type === 'urls') {
    // URL一覧エクスポート
    fputcsv($output, ['ID', '名前', 'スラッグ', '転送先URL', '短縮URL', '転送方法', 'カテゴリ', 'グループ',
        'トータルクリック', 'ユニーク', '成約数', '成約率', '作成日', '有効期限', 'メモ']);

    $sql = 'SELECT u.*, c.name AS category_name, g.name AS group_name,
            (SELECT COUNT(*) FROM clicks WHERE url_id = u.id) AS clicks_total,
            (SELECT COUNT(*) FROM clicks WHERE url_id = u.id AND is_unique = 1) AS clicks_unique,
            (SELECT COUNT(*) FROM conversions WHERE url_id = u.id) AS conversions_total
            FROM urls u
            LEFT JOIN categories c ON u.category_id = c.id
            LEFT JOIN `groups` g ON u.group_id = g.id';

    if (!Auth::isAdmin()) {
        $sql .= ' WHERE u.user_id = ' . (int)Auth::userId();
    }
    $sql .= ' ORDER BY u.id DESC';

    $rows = $db->query($sql)->fetchAll();
    foreach ($rows as $row) {
        $cvRate = $row['clicks_unique'] > 0 ? round($row['conversions_total'] / $row['clicks_unique'] * 100, 1) . '%' : '0%';
        fputcsv($output, [
            $row['id'], $row['name'], $row['slug'], $row['destination_url'],
            buildShortUrl($row['slug'], 'html'), $row['redirect_type'],
            $row['category_name'] ?? '', $row['group_name'] ?? '',
            $row['clicks_total'], $row['clicks_unique'], $row['conversions_total'], $cvRate,
            $row['created_at'], $row['expires_at'] ?? '', $row['memo'],
        ]);
    }
} elseif ($type === 'clicks' && $urlId) {
    // クリック詳細エクスポート
    fputcsv($output, ['日時', 'IPアドレス', 'リファラー', '参照元種別', 'デバイス', 'OS', 'ブラウザ', '地域', 'ユニーク']);

    $stmt = $db->prepare('SELECT * FROM clicks WHERE url_id = ? ORDER BY clicked_at DESC');
    $stmt->execute([$urlId]);
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['clicked_at'], $row['ip_address'], $row['referer'], $row['referer_type'],
            $row['device_type'], $row['os'], $row['browser'], $row['region'],
            $row['is_unique'] ? 'Yes' : 'No',
        ]);
    }
} else {
    // 解析サマリーエクスポート
    if ($urlId) {
        fputcsv($output, ['時間', 'トータル', 'ユニーク']);

        $stmt = $db->prepare(
            'SELECT HOUR(clicked_at) AS h, COUNT(*) AS total, SUM(is_unique) AS uniq
             FROM clicks WHERE url_id = ? AND DATE(clicked_at) = ? GROUP BY h ORDER BY h'
        );
        $stmt->execute([$urlId, $date]);
        $hourMap = [];
        while ($row = $stmt->fetch()) {
            $hourMap[(int)$row['h']] = $row;
        }
        for ($i = 0; $i < 24; $i++) {
            fputcsv($output, [
                $i . '時',
                (int)($hourMap[$i]['total'] ?? 0),
                (int)($hourMap[$i]['uniq'] ?? 0),
            ]);
        }
    }
}

fclose($output);
exit;

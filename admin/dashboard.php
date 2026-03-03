<?php
/**
 * ダッシュボード
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = 'ダッシュボード';

$isAdmin = Auth::isAdmin();
$userId = Auth::userId();

// 全体統計
$userFilter = $isAdmin ? '' : ' AND u.user_id = ' . (int)$userId;
$urlJoin = $isAdmin ? '' : ' INNER JOIN urls u ON c.url_id = u.id';

// 総URL数
if ($isAdmin) {
    $totalUrls = (int)$db->query('SELECT COUNT(*) FROM urls')->fetchColumn();
} else {
    $stmt = $db->prepare('SELECT COUNT(*) FROM urls WHERE user_id = ?');
    $stmt->execute([$userId]);
    $totalUrls = (int)$stmt->fetchColumn();
}

// 今日のクリック
if ($isAdmin) {
    $todayClicks = (int)$db->query("SELECT COUNT(*) FROM clicks WHERE DATE(clicked_at) = CURDATE()")->fetchColumn();
    $todayUnique = (int)$db->query("SELECT COUNT(*) FROM clicks WHERE DATE(clicked_at) = CURDATE() AND is_unique = 1")->fetchColumn();
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM clicks c INNER JOIN urls u ON c.url_id = u.id WHERE DATE(c.clicked_at) = CURDATE() AND u.user_id = ?");
    $stmt->execute([$userId]);
    $todayClicks = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM clicks c INNER JOIN urls u ON c.url_id = u.id WHERE DATE(c.clicked_at) = CURDATE() AND c.is_unique = 1 AND u.user_id = ?");
    $stmt->execute([$userId]);
    $todayUnique = (int)$stmt->fetchColumn();
}

// 総クリック
if ($isAdmin) {
    $allClicks = (int)$db->query('SELECT COUNT(*) FROM clicks')->fetchColumn();
    $allConversions = (int)$db->query('SELECT COUNT(*) FROM conversions')->fetchColumn();
} else {
    $stmt = $db->prepare('SELECT COUNT(*) FROM clicks c INNER JOIN urls u ON c.url_id = u.id WHERE u.user_id = ?');
    $stmt->execute([$userId]);
    $allClicks = (int)$stmt->fetchColumn();
    $stmt = $db->prepare('SELECT COUNT(*) FROM conversions cv INNER JOIN urls u ON cv.url_id = u.id WHERE u.user_id = ?');
    $stmt->execute([$userId]);
    $allConversions = (int)$stmt->fetchColumn();
}

// 過去7日間のクリック推移
$clicksTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    if ($isAdmin) {
        $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(is_unique) AS uniq FROM clicks WHERE DATE(clicked_at) = ?");
        $stmt->execute([$d]);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(is_unique) AS uniq FROM clicks c INNER JOIN urls u ON c.url_id = u.id WHERE DATE(c.clicked_at) = ? AND u.user_id = ?");
        $stmt->execute([$d, $userId]);
    }
    $row = $stmt->fetch();
    $clicksTrend[] = [
        'date' => date('m/d', strtotime($d)),
        'total' => (int)($row['total'] ?? 0),
        'unique' => (int)($row['uniq'] ?? 0),
    ];
}

// 人気URL TOP10
if ($isAdmin) {
    $topUrls = $db->query(
        'SELECT u.id, u.name, u.slug,
         COUNT(c.id) AS clicks_total,
         SUM(c.is_unique) AS clicks_unique
         FROM urls u
         LEFT JOIN clicks c ON c.url_id = u.id
         GROUP BY u.id
         ORDER BY clicks_total DESC
         LIMIT 10'
    )->fetchAll();
} else {
    $stmt = $db->prepare(
        'SELECT u.id, u.name, u.slug,
         COUNT(c.id) AS clicks_total,
         SUM(c.is_unique) AS clicks_unique
         FROM urls u
         LEFT JOIN clicks c ON c.url_id = u.id
         WHERE u.user_id = ?
         GROUP BY u.id
         ORDER BY clicks_total DESC
         LIMIT 10'
    );
    $stmt->execute([$userId]);
    $topUrls = $stmt->fetchAll();
}

// デバイス比率
if ($isAdmin) {
    $deviceStats = $db->query(
        "SELECT device_type, COUNT(*) AS cnt FROM clicks GROUP BY device_type ORDER BY cnt DESC"
    )->fetchAll();
} else {
    $stmt = $db->prepare(
        "SELECT c.device_type, COUNT(*) AS cnt FROM clicks c INNER JOIN urls u ON c.url_id = u.id WHERE u.user_id = ? GROUP BY c.device_type ORDER BY cnt DESC"
    );
    $stmt->execute([$userId]);
    $deviceStats = $stmt->fetchAll();
}

// 最近のクリック
if ($isAdmin) {
    $recentClicks = $db->query(
        'SELECT c.*, u.name AS url_name, u.slug FROM clicks c
         INNER JOIN urls u ON c.url_id = u.id
         ORDER BY c.clicked_at DESC LIMIT 10'
    )->fetchAll();
} else {
    $stmt = $db->prepare(
        'SELECT c.*, u.name AS url_name, u.slug FROM clicks c
         INNER JOIN urls u ON c.url_id = u.id
         WHERE u.user_id = ?
         ORDER BY c.clicked_at DESC LIMIT 10'
    );
    $stmt->execute([$userId]);
    $recentClicks = $stmt->fetchAll();
}

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-speedometer2 me-2"></i>ダッシュボード</h4>

<!-- サマリーカード -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">総URL数</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalUrls) ?></div>
                </div>
                <div class="stat-icon text-primary"><i class="bi bi-link-45deg"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">今日のクリック</div>
                    <div class="fs-3 fw-bold"><?= number_format($todayClicks) ?></div>
                </div>
                <div class="stat-icon text-success"><i class="bi bi-cursor-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">今日のユニーク</div>
                    <div class="fs-3 fw-bold"><?= number_format($todayUnique) ?></div>
                </div>
                <div class="stat-icon text-info"><i class="bi bi-person-check-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">総成約数</div>
                    <div class="fs-3 fw-bold"><?= number_format($allConversions) ?></div>
                </div>
                <div class="stat-icon text-warning"><i class="bi bi-trophy-fill"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- クリック推移グラフ -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-light">過去7日間のクリック推移</div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- デバイス比率 -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-light">デバイス比率</div>
            <div class="card-body">
                <div class="chart-container" style="height: 250px;">
                    <canvas id="deviceChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- 人気URL -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-light">人気URL TOP10</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>名前</th><th class="text-end">Total</th><th class="text-end">Unique</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topUrls as $tu): ?>
                        <tr>
                            <td>
                                <a href="<?= BASE_PATH ?>/admin/analytics.php?url_id=<?= $tu['id'] ?>" class="text-decoration-none">
                                    <?= h($tu['name'] ?: $tu['slug']) ?>
                                </a>
                            </td>
                            <td class="text-end"><?= number_format($tu['clicks_total']) ?></td>
                            <td class="text-end"><?= number_format($tu['clicks_unique'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topUrls)): ?>
                        <tr><td colspan="3" class="text-center text-muted">データなし</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 最近のクリック -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-light">最近のクリック</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>日時</th><th>URL</th><th>デバイス</th><th>参照元</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentClicks as $rc): ?>
                        <tr>
                            <td class="small"><?= date('H:i:s', strtotime($rc['clicked_at'])) ?></td>
                            <td class="small"><?= h($rc['url_name'] ?: $rc['slug']) ?></td>
                            <td><span class="badge bg-secondary"><?= h($rc['device_type']) ?></span></td>
                            <td class="small"><?= h($rc['referer_type']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentClicks)): ?>
                        <tr><td colspan="4" class="text-center text-muted">データなし</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
// クリック推移
const trendData = <?= json_encode($clicksTrend) ?>;
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendData.map(d => d.date),
        datasets: [
            {
                label: 'トータル',
                data: trendData.map(d => d.total),
                borderColor: 'rgba(54, 162, 235, 1)',
                backgroundColor: 'rgba(54, 162, 235, 0.1)',
                fill: true,
                tension: 0.3,
            },
            {
                label: 'ユニーク',
                data: trendData.map(d => d.unique),
                borderColor: 'rgba(75, 192, 192, 1)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                fill: true,
                tension: 0.3,
            }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
});

// デバイス比率
const deviceData = <?= json_encode($deviceStats) ?>;
const deviceColors = { pc: '#36A2EB', mobile: '#FF6384', tablet: '#FFCE56', other: '#C9CBCF' };
new Chart(document.getElementById('deviceChart'), {
    type: 'doughnut',
    data: {
        labels: deviceData.map(d => d.device_type),
        datasets: [{
            data: deviceData.map(d => d.cnt),
            backgroundColor: deviceData.map(d => deviceColors[d.device_type] || '#999'),
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

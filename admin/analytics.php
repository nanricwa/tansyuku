<?php
/**
 * 解析画面
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = '解析';

$urlId = (int)($_GET['url_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');
$viewMode = $_GET['view'] ?? 'hourly'; // hourly, daily, weekday, referer, device

// URL情報取得
$urlInfo = null;
if ($urlId) {
    $stmt = $db->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$urlId]);
    $urlInfo = $stmt->fetch();

    // 権限チェック
    if ($urlInfo && !Auth::isAdmin() && $urlInfo['user_id'] != Auth::userId()) {
        http_response_code(403);
        echo '権限がありません。';
        exit;
    }
}

// URL一覧（セレクト用）
if (Auth::isAdmin()) {
    $urlList = $db->query('SELECT id, name, slug FROM urls ORDER BY id DESC')->fetchAll();
} else {
    $stmt = $db->prepare('SELECT id, name, slug FROM urls WHERE user_id = ? ORDER BY id DESC');
    $stmt->execute([Auth::userId()]);
    $urlList = $stmt->fetchAll();
}

// 解析データ取得
$analyticsData = [];
$totalClicks = 0;
$uniqueClicks = 0;

if ($urlId && $urlInfo) {
    // 指定日のクリック数
    $stmt = $db->prepare('SELECT COUNT(*) FROM clicks WHERE url_id = ? AND DATE(clicked_at) = ?');
    $stmt->execute([$urlId, $date]);
    $totalClicks = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT COUNT(*) FROM clicks WHERE url_id = ? AND DATE(clicked_at) = ? AND is_unique = 1');
    $stmt->execute([$urlId, $date]);
    $uniqueClicks = (int)$stmt->fetchColumn();

    switch ($viewMode) {
        case 'hourly':
            // 時間帯別
            $stmt = $db->prepare(
                'SELECT HOUR(clicked_at) AS h,
                 COUNT(*) AS total,
                 SUM(is_unique) AS uniq
                 FROM clicks WHERE url_id = ? AND DATE(clicked_at) = ?
                 GROUP BY h ORDER BY h'
            );
            $stmt->execute([$urlId, $date]);
            $rows = $stmt->fetchAll();
            $hourMap = array_column($rows, null, 'h');
            for ($i = 0; $i < 24; $i++) {
                $analyticsData[] = [
                    'label' => $i . '時',
                    'total' => (int)($hourMap[$i]['total'] ?? 0),
                    'unique' => (int)($hourMap[$i]['uniq'] ?? 0),
                ];
            }
            break;

        case 'daily':
            // 日別（当月）
            $yearMonth = date('Y-m', strtotime($date));
            $stmt = $db->prepare(
                "SELECT DATE(clicked_at) AS d,
                 COUNT(*) AS total,
                 SUM(is_unique) AS uniq
                 FROM clicks WHERE url_id = ? AND DATE_FORMAT(clicked_at, '%Y-%m') = ?
                 GROUP BY d ORDER BY d"
            );
            $stmt->execute([$urlId, $yearMonth]);
            $rows = $stmt->fetchAll();
            $dayMap = array_column($rows, null, 'd');
            $daysInMonth = (int)date('t', strtotime($date));
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $key = $yearMonth . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                $analyticsData[] = [
                    'label' => $d . '日',
                    'total' => (int)($dayMap[$key]['total'] ?? 0),
                    'unique' => (int)($dayMap[$key]['uniq'] ?? 0),
                ];
            }
            break;

        case 'weekday':
            // 曜日別（全期間）
            $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
            $stmt = $db->prepare(
                'SELECT DAYOFWEEK(clicked_at) AS dow,
                 COUNT(*) AS total,
                 SUM(is_unique) AS uniq
                 FROM clicks WHERE url_id = ?
                 GROUP BY dow ORDER BY dow'
            );
            $stmt->execute([$urlId]);
            $rows = $stmt->fetchAll();
            $dowMap = array_column($rows, null, 'dow');
            for ($i = 1; $i <= 7; $i++) {
                $analyticsData[] = [
                    'label' => $weekdays[$i - 1],
                    'total' => (int)($dowMap[$i]['total'] ?? 0),
                    'unique' => (int)($dowMap[$i]['uniq'] ?? 0),
                ];
            }
            break;

        case 'referer':
            // リファラー種別
            $stmt = $db->prepare(
                'SELECT referer_type, COUNT(*) AS total, SUM(is_unique) AS uniq
                 FROM clicks WHERE url_id = ?
                 GROUP BY referer_type ORDER BY total DESC'
            );
            $stmt->execute([$urlId]);
            $analyticsData = $stmt->fetchAll();
            break;

        case 'device':
            // デバイス別
            $stmt = $db->prepare(
                'SELECT device_type, COUNT(*) AS total, SUM(is_unique) AS uniq
                 FROM clicks WHERE url_id = ?
                 GROUP BY device_type ORDER BY total DESC'
            );
            $stmt->execute([$urlId]);
            $analyticsData = $stmt->fetchAll();
            break;

        case 'browser':
            // ブラウザ別
            $stmt = $db->prepare(
                'SELECT browser, COUNT(*) AS total, SUM(is_unique) AS uniq
                 FROM clicks WHERE url_id = ?
                 GROUP BY browser ORDER BY total DESC LIMIT 20'
            );
            $stmt->execute([$urlId]);
            $analyticsData = $stmt->fetchAll();
            break;

        case 'os':
            // OS別
            $stmt = $db->prepare(
                'SELECT os, COUNT(*) AS total, SUM(is_unique) AS uniq
                 FROM clicks WHERE url_id = ?
                 GROUP BY os ORDER BY total DESC LIMIT 20'
            );
            $stmt->execute([$urlId]);
            $analyticsData = $stmt->fetchAll();
            break;
    }
}

// 月送り用
$prevMonth = date('Y-m-d', strtotime($date . ' -1 month'));
$nextMonth = date('Y-m-d', strtotime($date . ' +1 month'));

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-graph-up me-2"></i>解析</h4>

<!-- URL選択 -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small">URL</label>
                <select class="form-select form-select-sm" name="url_id" onchange="this.form.submit()">
                    <option value="">URLを選択してください</option>
                    <?php foreach ($urlList as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $urlId == $u['id'] ? 'selected' : '' ?>>
                            <?= h($u['name'] ?: $u['slug']) ?> (<?= h($u['slug']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">日付</label>
                <input type="date" class="form-control form-control-sm" name="date" value="<?= h($date) ?>"
                       onchange="this.form.submit()">
            </div>
            <div class="col-md-4">
                <label class="form-label small">表示</label>
                <select class="form-select form-select-sm" name="view" onchange="this.form.submit()">
                    <option value="hourly" <?= $viewMode === 'hourly' ? 'selected' : '' ?>>時間帯別</option>
                    <option value="daily" <?= $viewMode === 'daily' ? 'selected' : '' ?>>日別</option>
                    <option value="weekday" <?= $viewMode === 'weekday' ? 'selected' : '' ?>>曜日別</option>
                    <option value="referer" <?= $viewMode === 'referer' ? 'selected' : '' ?>>参照元別</option>
                    <option value="device" <?= $viewMode === 'device' ? 'selected' : '' ?>>デバイス別</option>
                    <option value="browser" <?= $viewMode === 'browser' ? 'selected' : '' ?>>ブラウザ別</option>
                    <option value="os" <?= $viewMode === 'os' ? 'selected' : '' ?>>OS別</option>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($urlInfo): ?>

<!-- 統計サマリー -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small"><?= h($date) ?> のクリック数</div>
                    <div class="fs-3 fw-bold"><?= number_format($totalClicks) ?></div>
                </div>
                <div class="stat-icon text-primary"><i class="bi bi-cursor-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small"><?= h($date) ?> のユニーク</div>
                    <div class="fs-3 fw-bold"><?= number_format($uniqueClicks) ?></div>
                </div>
                <div class="stat-icon text-success"><i class="bi bi-person-check-fill"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- グラフ -->
<?php if (in_array($viewMode, ['hourly', 'daily', 'weekday'])): ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="chart-container">
            <canvas id="analyticsChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- データテーブル -->
<div class="card">
    <div class="card-body">
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr>
                    <th>
                        <?php
                        echo match($viewMode) {
                            'referer' => '参照元',
                            'device' => 'デバイス',
                            'browser' => 'ブラウザ',
                            'os' => 'OS',
                            default => '時間/日付',
                        };
                        ?>
                    </th>
                    <th class="text-end">トータル</th>
                    <th class="text-end">ユニーク</th>
                    <th>割合</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $grandTotal = max(1, array_sum(array_column($analyticsData, 'total')));
                foreach ($analyticsData as $row):
                    $label = $row['label'] ?? $row['referer_type'] ?? $row['device_type'] ?? $row['browser'] ?? $row['os'] ?? '-';
                    $total = (int)$row['total'];
                    $unique = (int)($row['unique'] ?? $row['uniq'] ?? 0);
                    $pct = round($total / $grandTotal * 100, 1);
                ?>
                <tr>
                    <td><?= h($label) ?></td>
                    <td class="text-end"><?= number_format($total) ?></td>
                    <td class="text-end"><?= number_format($unique) ?></td>
                    <td>
                        <div class="progress" style="height: 18px; min-width: 100px;">
                            <div class="progress-bar" style="width: <?= $pct ?>%"><?= $pct ?>%</div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- CSV出力リンク -->
<div class="mt-3">
    <a href="<?= BASE_PATH ?>/admin/export.php?url_id=<?= $urlId ?>&date=<?= h($date) ?>&view=<?= h($viewMode) ?>"
       class="btn btn-sm btn-outline-success">
        <i class="bi bi-download me-1"></i>CSVエクスポート
    </a>
</div>

<?php if (in_array($viewMode, ['hourly', 'daily', 'weekday'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('analyticsChart').getContext('2d');
const chartData = <?= json_encode($analyticsData) ?>;
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: chartData.map(d => d.label),
        datasets: [
            {
                label: 'トータル',
                data: chartData.map(d => d.total),
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1,
            },
            {
                label: 'ユニーク',
                data: chartData.map(d => d.unique),
                backgroundColor: 'rgba(75, 192, 192, 0.6)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});
</script>
<?php endif; ?>

<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-graph-up fs-1"></i>
        <p class="mt-2">URLを選択してください</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

<?php
/**
 * 紹介分析
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/referral.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = '紹介分析';

// フィルタ
$campaignFilter = (int)($_GET['campaign'] ?? 0);
$memberFilter = (int)($_GET['member'] ?? 0);
$matchFilter = trim($_GET['match'] ?? '');
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['to'] ?? date('Y-m-d');

$campaigns = $db->query('SELECT * FROM ref_campaigns ORDER BY name')->fetchAll();
$allMembers = $db->query('SELECT * FROM ref_members ORDER BY name')->fetchAll();

// 全体統計
$where = ['rv.visited_at BETWEEN ? AND ?'];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if ($campaignFilter) {
    $where[] = 'rv.campaign_id = ?';
    $params[] = $campaignFilter;
}
if ($memberFilter) {
    $where[] = 'rv.member_id = ?';
    $params[] = $memberFilter;
}
if (!empty($matchFilter)) {
    $where[] = 'rv.match_code = ?';
    $params[] = $matchFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// 総計
$stmt = $db->prepare("SELECT COUNT(*) FROM ref_visits rv {$whereClause}");
$stmt->execute($params);
$totalVisits = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM ref_visits rv {$whereClause} AND rv.is_unique = 1");
$stmt->execute($params);
$uniqueVisits = (int)$stmt->fetchColumn();

$cvWhere = str_replace('rv.visited_at', 'rc.converted_at', $whereClause);
$cvWhere = str_replace('rv.campaign_id', 'rc.campaign_id', $cvWhere);
$cvWhere = str_replace('rv.member_id', 'rc.member_id', $cvWhere);
$cvWhere = str_replace('rv.match_code', 'rv2.match_code', $cvWhere);
$cvWhere = str_replace('rv.is_unique = 1 AND', '', $cvWhere);

if (!empty($matchFilter)) {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM ref_conversions rc
         LEFT JOIN ref_visits rv2 ON rc.visit_id = rv2.id
         {$cvWhere}"
    );
} else {
    $cvWhereSimple = str_replace('rv.', 'rc.', $whereClause);
    $cvWhereSimple = str_replace('rc.is_unique = 1 AND', '', $cvWhereSimple);
    $cvWhereSimple = str_replace('rc.visited_at', 'rc.converted_at', $cvWhereSimple);
    $stmt = $db->prepare("SELECT COUNT(*) FROM ref_conversions rc {$cvWhereSimple}");
}
$stmt->execute($params);
$totalCv = (int)$stmt->fetchColumn();
$cvRate = $uniqueVisits > 0 ? round($totalCv / $uniqueVisits * 100, 2) : 0;

// 紹介者別ランキング
$rankParams = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
$rankWhere = 'WHERE rv.visited_at BETWEEN ? AND ?';
if ($campaignFilter) {
    $rankWhere .= ' AND rv.campaign_id = ?';
    $rankParams[] = $campaignFilter;
}
if (!empty($matchFilter)) {
    $rankWhere .= ' AND rv.match_code = ?';
    $rankParams[] = $matchFilter;
}

$stmt = $db->prepare(
    "SELECT m.id, m.name, m.code, m.group_label,
     COUNT(rv.id) AS total_visits,
     SUM(rv.is_unique) AS unique_visits,
     (SELECT COUNT(*) FROM ref_conversions rc WHERE rc.member_id = m.id
      AND rc.converted_at BETWEEN ? AND ?) AS cv_count
     FROM ref_members m
     INNER JOIN ref_visits rv ON rv.member_id = m.id
     {$rankWhere}
     GROUP BY m.id
     ORDER BY unique_visits DESC
     LIMIT 50"
);
$rankParams2 = array_merge([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'], $rankParams);
$stmt->execute($rankParams2);
$memberRanking = $stmt->fetchAll();

// 日別推移
$dailyParams = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
$dailyWhere = 'WHERE rv.visited_at BETWEEN ? AND ?';
if ($campaignFilter) {
    $dailyWhere .= ' AND rv.campaign_id = ?';
    $dailyParams[] = $campaignFilter;
}

$stmt = $db->prepare(
    "SELECT DATE(rv.visited_at) AS day, COUNT(*) AS total, SUM(rv.is_unique) AS uniq
     FROM ref_visits rv {$dailyWhere}
     GROUP BY DATE(rv.visited_at) ORDER BY day"
);
$stmt->execute($dailyParams);
$dailyData = $stmt->fetchAll();

$dailyLabels = array_column($dailyData, 'day');
$dailyTotals = array_column($dailyData, 'total');
$dailyUniques = array_column($dailyData, 'uniq');

// デバイス別
$deviceParams = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
$deviceWhere = 'WHERE rv.visited_at BETWEEN ? AND ?';
if ($campaignFilter) {
    $deviceWhere .= ' AND rv.campaign_id = ?';
    $deviceParams[] = $campaignFilter;
}

$stmt = $db->prepare(
    "SELECT rv.device_type, COUNT(*) AS cnt FROM ref_visits rv {$deviceWhere} GROUP BY rv.device_type ORDER BY cnt DESC"
);
$stmt->execute($deviceParams);
$deviceData = $stmt->fetchAll();

// リファラー別
$stmt = $db->prepare(
    "SELECT rv.referer_type, COUNT(*) AS cnt FROM ref_visits rv {$deviceWhere} GROUP BY rv.referer_type ORDER BY cnt DESC"
);
$stmt->execute($deviceParams);
$refererData = $stmt->fetchAll();

// match別集計
$matchParams = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
$matchWhere = "WHERE rv.visited_at BETWEEN ? AND ? AND rv.match_code != ''";
if ($campaignFilter) {
    $matchWhere .= ' AND rv.campaign_id = ?';
    $matchParams[] = $campaignFilter;
}

$stmt = $db->prepare(
    "SELECT rv.match_code, COUNT(*) AS total, SUM(rv.is_unique) AS uniq
     FROM ref_visits rv {$matchWhere}
     GROUP BY rv.match_code ORDER BY total DESC"
);
$stmt->execute($matchParams);
$matchData = $stmt->fetchAll();

// キャンペーン別集計
$campParams = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
$stmt = $db->prepare(
    "SELECT c.name, c.slug, COUNT(rv.id) AS total, SUM(rv.is_unique) AS uniq
     FROM ref_campaigns c
     INNER JOIN ref_visits rv ON rv.campaign_id = c.id
     WHERE rv.visited_at BETWEEN ? AND ?
     GROUP BY c.id ORDER BY total DESC"
);
$stmt->execute($campParams);
$campData = $stmt->fetchAll();

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-bar-chart me-2"></i>紹介分析</h4>

<!-- フィルタ -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">期間（開始）</label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">期間（終了）</label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">キャンペーン</label>
                <select class="form-select form-select-sm" name="campaign">
                    <option value="0">全て</option>
                    <?php foreach ($campaigns as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $campaignFilter == $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">紹介者</label>
                <select class="form-select form-select-sm" name="member">
                    <option value="0">全て</option>
                    <?php foreach ($allMembers as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $memberFilter == $m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">matchコード</label>
                <input type="text" class="form-control form-control-sm" name="match" value="<?= h($matchFilter) ?>" placeholder="全て">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>絞り込み</button>
            </div>
        </form>
    </div>
</div>

<!-- 総計カード -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-primary"><i class="bi bi-eye"></i></div>
            <div class="fs-4 fw-bold"><?= number_format($totalVisits) ?></div>
            <div class="text-muted small">総アクセス</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-success"><i class="bi bi-person-check"></i></div>
            <div class="fs-4 fw-bold"><?= number_format($uniqueVisits) ?></div>
            <div class="text-muted small">ユニーク</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-warning"><i class="bi bi-trophy"></i></div>
            <div class="fs-4 fw-bold"><?= number_format($totalCv) ?></div>
            <div class="text-muted small">成約</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-info"><i class="bi bi-percent"></i></div>
            <div class="fs-4 fw-bold"><?= $cvRate ?>%</div>
            <div class="text-muted small">成約率</div>
        </div>
    </div>
</div>

<!-- 日別推移グラフ -->
<?php if (!empty($dailyData)): ?>
<div class="card mb-4">
    <div class="card-header bg-light"><i class="bi bi-graph-up me-1"></i>日別アクセス推移</div>
    <div class="card-body">
        <canvas id="dailyChart" height="80"></canvas>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- 紹介者ランキング -->
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header bg-light"><i class="bi bi-trophy me-1"></i>紹介者ランキング</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px">#</th>
                                <th>名前</th>
                                <th>コード</th>
                                <th>グループ</th>
                                <th class="text-center">アクセス</th>
                                <th class="text-center">ユニーク</th>
                                <th class="text-center">CV</th>
                                <th class="text-center">CV率</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($memberRanking as $i => $mr):
                                $mCvRate = $mr['unique_visits'] > 0 ? round($mr['cv_count'] / $mr['unique_visits'] * 100, 1) : 0;
                            ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td class="fw-bold"><?= h($mr['name']) ?></td>
                                <td><code><?= h($mr['code']) ?></code></td>
                                <td class="small"><?= h($mr['group_label'] ?: '-') ?></td>
                                <td class="text-center"><?= number_format($mr['total_visits']) ?></td>
                                <td class="text-center"><?= number_format($mr['unique_visits']) ?></td>
                                <td class="text-center fw-bold"><?= number_format($mr['cv_count']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $mCvRate > 0 ? 'success' : 'secondary' ?>"><?= $mCvRate ?>%</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($memberRanking)): ?>
                                <tr><td colspan="8" class="text-center text-muted py-3">データがありません</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- デバイス・リファラー -->
        <div class="card mb-4">
            <div class="card-header bg-light"><i class="bi bi-phone me-1"></i>デバイス別</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <?php foreach ($deviceData as $dd): ?>
                    <tr>
                        <td><?= h(ucfirst($dd['device_type'])) ?></td>
                        <td class="text-end fw-bold"><?= number_format($dd['cnt']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-light"><i class="bi bi-box-arrow-in-right me-1"></i>流入元</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <?php
                    $refLabels = ['direct'=>'直接','search'=>'検索','social'=>'SNS','email'=>'メール','other'=>'その他'];
                    foreach ($refererData as $rd): ?>
                    <tr>
                        <td><?= $refLabels[$rd['referer_type']] ?? $rd['referer_type'] ?></td>
                        <td class="text-end fw-bold"><?= number_format($rd['cnt']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <!-- matchコード別 -->
        <?php if (!empty($matchData)): ?>
        <div class="card mb-4">
            <div class="card-header bg-light"><i class="bi bi-hash me-1"></i>matchコード別</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>match</th><th class="text-center">計</th><th class="text-center">UQ</th></tr></thead>
                    <?php foreach ($matchData as $md): ?>
                    <tr>
                        <td><code><?= h($md['match_code']) ?></code></td>
                        <td class="text-center"><?= number_format($md['total']) ?></td>
                        <td class="text-center"><?= number_format($md['uniq']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- キャンペーン別 -->
        <?php if (!empty($campData) && !$campaignFilter): ?>
        <div class="card mb-4">
            <div class="card-header bg-light"><i class="bi bi-megaphone me-1"></i>キャンペーン別</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>キャンペーン</th><th class="text-center">計</th><th class="text-center">UQ</th></tr></thead>
                    <?php foreach ($campData as $cd): ?>
                    <tr>
                        <td><?= h($cd['name']) ?></td>
                        <td class="text-center"><?= number_format($cd['total']) ?></td>
                        <td class="text-center"><?= number_format($cd['uniq']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($dailyData)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($dailyLabels) ?>,
        datasets: [
            {
                label: '総アクセス',
                data: <?= json_encode(array_map('intval', $dailyTotals)) ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'ユニーク',
                data: <?= json_encode(array_map('intval', $dailyUniques)) ?>,
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,0.1)',
                fill: true,
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

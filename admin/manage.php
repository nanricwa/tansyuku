<?php
/**
 * URL管理一覧
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = '管理';

// フィルタ・検索パラメータ
$search = trim($_GET['search'] ?? '');
$categoryFilter = $_GET['category'] ?? '';
$groupFilter = $_GET['group'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// 許可されたソートカラム
$allowedSorts = ['name', 'slug', 'created_at', 'clicks_total', 'clicks_unique'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'created_at';
}

// クエリ組み立て
$where = [];
$params = [];

// 一般ユーザーは自分のURLのみ
if (!Auth::isAdmin()) {
    $where[] = 'u.user_id = ?';
    $params[] = Auth::userId();
}

if (!empty($search)) {
    $where[] = '(u.name LIKE ? OR u.slug LIKE ? OR u.destination_url LIKE ? OR u.memo LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($categoryFilter !== '') {
    $where[] = 'u.category_id = ?';
    $params[] = (int)$categoryFilter;
}

if ($groupFilter !== '') {
    $where[] = 'u.group_id = ?';
    $params[] = (int)$groupFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 件数取得
$countSql = "SELECT COUNT(*) FROM urls u {$whereClause}";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalItems = (int)$stmt->fetchColumn();
$pagination = paginate($totalItems, $page, $perPage);

// ソートカラムのマッピング
$orderColumn = match ($sortBy) {
    'clicks_total' => 'clicks_total',
    'clicks_unique' => 'clicks_unique',
    default => 'u.' . $sortBy,
};

// データ取得
$sql = "SELECT u.*,
        c.name AS category_name,
        g.name AS group_name,
        (SELECT COUNT(*) FROM clicks WHERE url_id = u.id) AS clicks_total,
        (SELECT COUNT(*) FROM clicks WHERE url_id = u.id AND is_unique = 1) AS clicks_unique,
        (SELECT COUNT(*) FROM conversions WHERE url_id = u.id) AS conversions_total
        FROM urls u
        LEFT JOIN categories c ON u.category_id = c.id
        LEFT JOIN `groups` g ON u.group_id = g.id
        {$whereClause}
        ORDER BY {$orderColumn} {$sortDir}
        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$urls = $stmt->fetchAll();

// カテゴリ・グループ一覧（フィルタ用）
$categories = $db->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
$groups = $db->query('SELECT * FROM `groups` ORDER BY name')->fetchAll();

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        $deleteId = (int)$_POST['delete_id'];
        // 権限チェック
        $stmt = $db->prepare('SELECT user_id FROM urls WHERE id = ?');
        $stmt->execute([$deleteId]);
        $owner = $stmt->fetch();
        if ($owner && (Auth::isAdmin() || $owner['user_id'] == Auth::userId())) {
            $db->prepare('DELETE FROM urls WHERE id = ?')->execute([$deleteId]);
            AuditLog::log('delete', 'url', $deleteId);
            setFlash('success', 'URLを削除しました。');
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

include __DIR__ . '/../templates/header.php';

/**
 * ソートリンクヘルパー
 */
function sortLink(string $column, string $label): string
{
    global $sortBy, $sortDir, $search, $categoryFilter, $groupFilter;
    $newDir = ($sortBy === $column && $sortDir === 'DESC') ? 'asc' : 'desc';
    $icon = '';
    if ($sortBy === $column) {
        $icon = $sortDir === 'ASC' ? ' <i class="bi bi-sort-up"></i>' : ' <i class="bi bi-sort-down"></i>';
    }
    $params = http_build_query(array_filter([
        'sort' => $column, 'dir' => $newDir,
        'search' => $search, 'category' => $categoryFilter, 'group' => $groupFilter,
    ], fn($v) => $v !== ''));
    return '<a href="?' . $params . '" class="text-decoration-none text-dark">' . $label . $icon . '</a>';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-table me-2"></i>URL管理</h4>
    <span class="text-muted"><?= $totalItems ?>件</span>
</div>

<!-- フィルタ・検索 -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="名前・スラッグ・URL・メモで検索" value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="category">
                    <option value="">全カテゴリ</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="group">
                    <option value="">全グループ</option>
                    <?php foreach ($groups as $grp): ?>
                        <option value="<?= $grp['id'] ?>" <?= $groupFilter == $grp['id'] ? 'selected' : '' ?>><?= h($grp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-search me-1"></i>検索</button>
            </div>
            <div class="col-md-2">
                <a href="<?= BASE_PATH ?>/admin/manage.php" class="btn btn-sm btn-outline-secondary w-100">リセット</a>
            </div>
        </form>
    </div>
</div>

<!-- URL一覧テーブル -->
<div class="table-responsive">
    <table class="table table-hover table-sm align-middle">
        <thead class="table-light">
            <tr>
                <th style="width:40px">No</th>
                <th><?= sortLink('name', '名前') ?></th>
                <th><?= sortLink('slug', 'スラッグ') ?></th>
                <th>転送先</th>
                <th class="text-center"><?= sortLink('clicks_total', 'Total') ?></th>
                <th class="text-center"><?= sortLink('clicks_unique', 'Unique') ?></th>
                <th class="text-center">成約率</th>
                <th>カテゴリ</th>
                <th>グループ</th>
                <th style="width:120px">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($urls)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">データがありません</td></tr>
            <?php endif; ?>
            <?php foreach ($urls as $i => $url): ?>
                <?php
                $cvRate = $url['clicks_unique'] > 0
                    ? round($url['conversions_total'] / $url['clicks_unique'] * 100, 1)
                    : 0;
                $shortUrl = buildShortUrl($url['slug'], 'html');
                ?>
                <tr>
                    <td class="text-muted"><?= $url['id'] ?></td>
                    <td>
                        <a href="<?= BASE_PATH ?>/admin/edit.php?id=<?= $url['id'] ?>" class="fw-bold text-decoration-none">
                            <?= h($url['name'] ?: $url['slug']) ?>
                        </a>
                        <?php if ($url['memo']): ?>
                            <br><small class="text-muted"><?= h(mb_strimwidth($url['memo'], 0, 50, '...')) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code><?= h($url['slug']) ?></code>
                        <button class="btn btn-copy btn-sm" data-copy="<?= h($shortUrl) ?>" title="コピー">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </td>
                    <td>
                        <a href="<?= h($url['destination_url']) ?>" target="_blank" class="text-truncate d-inline-block" style="max-width:200px;">
                            <?= h($url['destination_url']) ?>
                        </a>
                    </td>
                    <td class="text-center"><?= number_format($url['clicks_total']) ?></td>
                    <td class="text-center"><?= number_format($url['clicks_unique']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $cvRate > 0 ? 'info' : 'secondary' ?>"><?= $cvRate ?>%</span>
                    </td>
                    <td><?= h($url['category_name'] ?? '-') ?></td>
                    <td><?= h($url['group_name'] ?? '-') ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="<?= BASE_PATH ?>/admin/analytics.php?url_id=<?= $url['id'] ?>" class="btn btn-outline-primary" title="解析">
                                <i class="bi bi-graph-up"></i>
                            </a>
                            <a href="<?= BASE_PATH ?>/admin/edit.php?id=<?= $url['id'] ?>" class="btn btn-outline-secondary" title="編集">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php
                            $baseUrl = Database::getSetting('base_url', 'https://example.com/intro');
                            $cvTag = '<img src="' . $baseUrl . '/cv.php" width="1" height="1" style="display:none" alt="">';
                            ?>
                            <button class="btn btn-outline-warning btn-copy" data-copy="<?= h($cvTag) ?>" title="共通CVタグをコピー">
                                <i class="bi bi-tag"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('このURLを削除しますか？');">
                                <?= Csrf::tokenField() ?>
                                <input type="hidden" name="delete_id" value="<?= $url['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger" title="削除">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ページネーション -->
<?php if ($pagination['total_pages'] > 1): ?>
<nav>
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
            <?php
            $params = http_build_query(array_filter([
                'page' => $p, 'sort' => $sortBy, 'dir' => strtolower($sortDir),
                'search' => $search, 'category' => $categoryFilter, 'group' => $groupFilter,
            ], fn($v) => $v !== '' && $v !== 1));
            ?>
            <li class="page-item <?= $p === $pagination['current_page'] ? 'active' : '' ?>">
                <a class="page-link" href="?<?= $params ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

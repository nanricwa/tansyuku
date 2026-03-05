<?php
/**
 * 紹介者（会員）管理
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/referral.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = '紹介者管理';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/referral_members.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $groupLabel = trim($_POST['group_label'] ?? '');
        $memo = trim($_POST['memo'] ?? '');

        $errors = [];
        if (empty($name)) $errors[] = '名前を入力してください。';
        if (empty($code) || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            $errors[] = '紹介コードは英数字・ハイフン・アンダースコアのみです。';
        }

        if (empty($errors)) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM ref_members WHERE code = ?');
            $stmt->execute([$code]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'この紹介コードは既に使用されています。';
            }
        }

        if (empty($errors)) {
            $stmt = $db->prepare(
                'INSERT INTO ref_members (name, code, email, group_label, memo) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $code, $email, $groupLabel, $memo]);
            AuditLog::log('create', 'ref_member', (int)$db->lastInsertId());
            setFlash('success', '紹介者を追加しました。');
        } else {
            setFlash('danger', implode('<br>', $errors));
        }

    } elseif ($action === 'bulk_add') {
        // CSV一括登録（名前,コード,メール,グループ）
        $csvText = trim($_POST['csv_text'] ?? '');
        $lines = array_filter(explode("\n", $csvText));
        $added = 0;
        $skipped = 0;
        foreach ($lines as $line) {
            $parts = str_getcsv(trim($line));
            $mName = trim($parts[0] ?? '');
            $mCode = trim($parts[1] ?? '');
            $mEmail = trim($parts[2] ?? '');
            $mGroup = trim($parts[3] ?? '');

            if (empty($mName) || empty($mCode)) {
                $skipped++;
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mCode)) {
                $skipped++;
                continue;
            }

            // 重複チェック
            $stmt = $db->prepare('SELECT COUNT(*) FROM ref_members WHERE code = ?');
            $stmt->execute([$mCode]);
            if ((int)$stmt->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            $stmt = $db->prepare(
                'INSERT INTO ref_members (name, code, email, group_label) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$mName, $mCode, $mEmail, $mGroup]);
            $added++;
        }
        setFlash('success', "{$added}件追加、{$skipped}件スキップしました。");

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $groupLabel = trim($_POST['group_label'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $memo = trim($_POST['memo'] ?? '');

        if ($id && !empty($name) && !empty($code)) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM ref_members WHERE code = ? AND id != ?');
            $stmt->execute([$code, $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('danger', 'この紹介コードは既に使用されています。');
            } else {
                $stmt = $db->prepare(
                    'UPDATE ref_members SET name=?, code=?, email=?, group_label=?, is_active=?, memo=? WHERE id=?'
                );
                $stmt->execute([$name, $code, $email, $groupLabel, $isActive, $memo, $id]);
                AuditLog::log('update', 'ref_member', $id);
                setFlash('success', '紹介者を更新しました。');
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM ref_members WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'ref_member', $id);
            setFlash('success', '紹介者を削除しました。');
        }
    }

    header('Location: ' . BASE_PATH . '/admin/referral_members.php');
    exit;
}

// 検索・フィルタ
$search = trim($_GET['search'] ?? '');
$groupFilter = trim($_GET['group'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$where = [];
$params = [];
if (!empty($search)) {
    $where[] = '(m.name LIKE ? OR m.code LIKE ? OR m.email LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s]);
}
if (!empty($groupFilter)) {
    $where[] = 'm.group_label = ?';
    $params[] = $groupFilter;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- CSV エクスポート ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvStmt = $db->prepare(
        "SELECT m.*,
         (SELECT COUNT(*) FROM ref_visits WHERE member_id = m.id) AS visit_count,
         (SELECT COUNT(*) FROM ref_visits WHERE member_id = m.id AND is_unique = 1) AS unique_count,
         (SELECT COUNT(*) FROM ref_conversions WHERE member_id = m.id) AS cv_count,
         (SELECT COUNT(*) FROM ref_issued_links WHERE member_id = m.id) AS issued_count
         FROM ref_members m {$whereClause}
         ORDER BY m.name"
    );
    $csvStmt->execute($params);
    $csvMembers = $csvStmt->fetchAll();

    // 発行済みリンクを一括取得（メンバーIDでグループ化）
    $allIssuedLinks = [];
    if (!empty($csvMembers)) {
        $mIds = array_column($csvMembers, 'id');
        $ph = str_repeat('?,', count($mIds) - 1) . '?';
        $ilStmt = $db->prepare(
            "SELECT il.member_id, il.full_url, rc.name AS campaign_name, il.match_code,
                    rc.is_active AS campaign_active, rc.starts_at, rc.ends_at,
                    rm.is_active AS member_active
             FROM ref_issued_links il
             JOIN ref_campaigns rc ON il.campaign_id = rc.id
             JOIN ref_members rm ON il.member_id = rm.id
             WHERE il.member_id IN ({$ph})
             ORDER BY il.issued_at DESC"
        );
        $ilStmt->execute($mIds);
        foreach ($ilStmt->fetchAll() as $il) {
            $allIssuedLinks[$il['member_id']][] = $il;
        }
    }

    $filename = 'referral_members_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // BOM for Excel UTF-8
    echo "\xEF\xBB\xBF";

    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['名前', '紹介コード', 'メール', 'グループ', 'メモ', '状態',
                   'アクセス', 'ユニーク', 'CV', 'CV率', '発行URL数', '発行済みURL']);

    foreach ($csvMembers as $cm) {
        $cvRate = $cm['unique_count'] > 0 ? round($cm['cv_count'] / $cm['unique_count'] * 100, 1) : 0;

        // 発行済みURLをまとめる
        $urls = [];
        if (!empty($allIssuedLinks[$cm['id']])) {
            foreach ($allIssuedLinks[$cm['id']] as $il) {
                $status = Referral::getLinkStatus($il);
                $urls[] = $il['full_url'] . ' [' . $status['label'] . ']';
            }
        }

        fputcsv($fp, [
            $cm['name'],
            $cm['code'],
            $cm['email'],
            $cm['group_label'],
            $cm['memo'] ?? '',
            $cm['is_active'] ? '有効' : '無効',
            $cm['visit_count'],
            $cm['unique_count'],
            $cm['cv_count'],
            $cvRate . '%',
            $cm['issued_count'],
            implode("\n", $urls),
        ]);
    }
    fclose($fp);
    exit;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM ref_members m {$whereClause}");
$stmt->execute($params);
$totalItems = (int)$stmt->fetchColumn();
$pagination = paginate($totalItems, $page, $perPage);

$stmt = $db->prepare(
    "SELECT m.*,
     (SELECT COUNT(*) FROM ref_visits WHERE member_id = m.id) AS visit_count,
     (SELECT COUNT(*) FROM ref_visits WHERE member_id = m.id AND is_unique = 1) AS unique_count,
     (SELECT COUNT(*) FROM ref_conversions WHERE member_id = m.id) AS cv_count,
     (SELECT COUNT(*) FROM ref_issued_links WHERE member_id = m.id) AS issued_count
     FROM ref_members m {$whereClause}
     ORDER BY m.name
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
);
$stmt->execute($params);
$members = $stmt->fetchAll();

// グループ一覧（フィルタ用）
$groupLabels = $db->query("SELECT DISTINCT group_label FROM ref_members WHERE group_label != '' ORDER BY group_label")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>紹介者管理</h4>
    <span class="text-muted"><?= $totalItems ?>名</span>
</div>

<!-- 追加フォーム -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <a data-bs-toggle="collapse" href="#addMemberForm" class="text-decoration-none">
            <i class="bi bi-person-plus me-1"></i>紹介者を追加
        </a>
    </div>
    <div class="collapse" id="addMemberForm">
        <div class="card-body">
            <!-- 個別追加 -->
            <form method="post" class="mb-4">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="add">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">名前 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="田中太郎">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">紹介コード <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code" required placeholder="TANAKA"
                               pattern="[a-zA-Z0-9_-]+">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">メール</label>
                        <input type="email" class="form-control" name="email" placeholder="tanaka&#64;example.com">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">グループ</label>
                        <input type="text" class="form-control" name="group_label" placeholder="東京支部">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">メモ</label>
                        <input type="text" class="form-control" name="memo">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus me-1"></i>追加</button>
            </form>

            <!-- 一括追加 -->
            <hr>
            <form method="post">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="bulk_add">
                <label class="form-label fw-bold">一括追加（CSV形式）</label>
                <div class="alert alert-info small py-2">
                    形式: <code>名前,紹介コード,メール,グループ</code>（1行1件、メール・グループは省略可）
                </div>
                <textarea class="form-control font-monospace mb-2" name="csv_text" rows="5"
                          placeholder="田中太郎,TANAKA,tanaka&#64;example.com,東京&#10;鈴木花子,SUZUKI,suzuki&#64;example.com,大阪"></textarea>
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-upload me-1"></i>一括追加
                </button>
            </form>
        </div>
    </div>
</div>

<!-- 検索 -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="名前・コード・メールで検索" value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" name="group">
                    <option value="">全グループ</option>
                    <?php foreach ($groupLabels as $gl): ?>
                        <option value="<?= h($gl) ?>" <?= $groupFilter === $gl ? 'selected' : '' ?>><?= h($gl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>検索</button>
            </div>
            <div class="col-auto">
                <a href="<?= BASE_PATH ?>/admin/referral_members.php" class="btn btn-sm btn-outline-secondary">リセット</a>
            </div>
            <div class="col-auto ms-auto">
                <a href="?export=csv&search=<?= urlencode($search) ?>&group=<?= urlencode($groupFilter) ?>"
                   class="btn btn-sm btn-outline-success">
                    <i class="bi bi-download me-1"></i>CSVダウンロード
                </a>
            </div>
        </form>
    </div>
</div>

<!-- 一覧 -->
<div class="table-responsive">
    <table class="table table-hover table-sm align-middle">
        <thead class="table-light">
            <tr>
                <th>名前</th>
                <th>コード</th>
                <th>メール</th>
                <th>グループ</th>
                <th class="text-center">アクセス</th>
                <th class="text-center">ユニーク</th>
                <th class="text-center">CV</th>
                <th class="text-center">CV率</th>
                <th class="text-center">発行URL</th>
                <th>状態</th>
                <th style="width:120px">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($members)): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">紹介者がいません</td></tr>
            <?php endif; ?>
            <?php foreach ($members as $m):
                $cvRate = $m['unique_count'] > 0 ? round($m['cv_count'] / $m['unique_count'] * 100, 1) : 0;
            ?>
            <tr>
                <td class="fw-bold"><?= h($m['name']) ?></td>
                <td><code><?= h($m['code']) ?></code></td>
                <td class="small"><?= safeEmail($m['email']) ?></td>
                <td class="small"><?= h($m['group_label'] ?: '-') ?></td>
                <td class="text-center"><?= number_format($m['visit_count']) ?></td>
                <td class="text-center"><?= number_format($m['unique_count']) ?></td>
                <td class="text-center fw-bold"><?= number_format($m['cv_count']) ?></td>
                <td class="text-center">
                    <span class="badge bg-<?= $cvRate > 0 ? 'info' : 'secondary' ?>"><?= $cvRate ?>%</span>
                </td>
                <td class="text-center">
                    <?php if ($m['issued_count'] > 0): ?>
                        <a href="#" class="text-decoration-none" data-bs-toggle="modal"
                           data-bs-target="#issuedLinks<?= $m['id'] ?>"><?= $m['issued_count'] ?>件</a>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $m['is_active'] ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>' ?>
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                            data-bs-target="#editMember<?= $m['id'] ?>">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');">
                        <?= Csrf::tokenField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
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
            <li class="page-item <?= $p === $pagination['current_page'] ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&group=<?= urlencode($groupFilter) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<!-- 編集モーダル -->
<?php foreach ($members as $m): ?>
<div class="modal fade" id="editMember<?= $m['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">紹介者を編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">名前</label>
                        <input type="text" class="form-control" name="name" value="<?= h($m['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">紹介コード</label>
                        <input type="text" class="form-control" name="code" value="<?= h($m['code']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">メール</label>
                        <input type="email" class="form-control" name="email" value="<?= safeEmail($m['email']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">グループ</label>
                        <input type="text" class="form-control" name="group_label" value="<?= h($m['group_label']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">メモ</label>
                        <input type="text" class="form-control" name="memo" value="<?= h($m['memo']) ?>">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               <?= $m['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label">有効</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">更新</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- 発行済みリンク詳細モーダル -->
<?php foreach ($members as $m):
    if ($m['issued_count'] > 0):
        $issuedLinks = Referral::getIssuedLinks((int)$m['id']);
?>
<div class="modal fade" id="issuedLinks<?= $m['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= h($m['name']) ?> の発行済みリンク</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>キャンペーン</th>
                                <th>matchコード</th>
                                <th>リンク</th>
                                <th>状態</th>
                                <th>発行日</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($issuedLinks as $il):
                                $status = Referral::getLinkStatus($il);
                            ?>
                            <tr>
                                <td><?= h($il['campaign_name']) ?></td>
                                <td><code><?= h($il['match_code'] ?: '-') ?></code></td>
                                <td>
                                    <input type="text" class="form-control form-control-sm font-monospace"
                                           value="<?= h($il['full_url']) ?>" readonly style="min-width:200px">
                                </td>
                                <td><span class="badge bg-<?= $status['color'] ?>"><?= $status['label'] ?></span></td>
                                <td class="small"><?= date('Y/m/d', strtotime($il['issued_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>
<?php endif; endforeach; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

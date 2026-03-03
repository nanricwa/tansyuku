<?php
/**
 * グループ管理（A/Bテスト振り分け）
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = 'グループ管理';

// 処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/groups.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $redirectMethod = $_POST['redirect_method'] ?? 'jump';
        $memo = trim($_POST['memo'] ?? '');

        // ルール
        $rules = [
            'fix_on_conversion_rate_diff' => (float)($_POST['rule_cv_rate_diff'] ?? 0),
            'fix_on_conversion_count_min' => (int)($_POST['rule_cv_count_min'] ?? 0),
            'fix_method' => $_POST['rule_fix_method'] ?? 'first',
        ];

        if (!empty($name)) {
            $stmt = $db->prepare('INSERT INTO `groups` (name, rules_json, redirect_method, memo) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, json_encode($rules), $redirectMethod, $memo]);
            $groupId = (int)$db->lastInsertId();

            // 転送先を追加
            $destUrls = $_POST['dest_url'] ?? [];
            $destWeights = $_POST['dest_weight'] ?? [];
            for ($i = 0; $i < count($destUrls); $i++) {
                $dUrl = trim($destUrls[$i] ?? '');
                $dWeight = max(1, (int)($destWeights[$i] ?? 1));
                if (!empty($dUrl)) {
                    $stmt = $db->prepare('INSERT INTO group_destinations (group_id, destination_url, weight) VALUES (?, ?, ?)');
                    $stmt->execute([$groupId, $dUrl, $dWeight]);
                }
            }

            AuditLog::log('create', 'group', $groupId);
            setFlash('success', 'グループを追加しました。');
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $redirectMethod = $_POST['redirect_method'] ?? 'jump';
        $memo = trim($_POST['memo'] ?? '');

        $rules = [
            'fix_on_conversion_rate_diff' => (float)($_POST['rule_cv_rate_diff'] ?? 0),
            'fix_on_conversion_count_min' => (int)($_POST['rule_cv_count_min'] ?? 0),
            'fix_method' => $_POST['rule_fix_method'] ?? 'first',
        ];

        if ($id && !empty($name)) {
            $stmt = $db->prepare('UPDATE `groups` SET name = ?, rules_json = ?, redirect_method = ?, memo = ? WHERE id = ?');
            $stmt->execute([$name, json_encode($rules), $redirectMethod, $memo, $id]);

            // 転送先を再構築
            $db->prepare('DELETE FROM group_destinations WHERE group_id = ?')->execute([$id]);
            $destUrls = $_POST['dest_url'] ?? [];
            $destWeights = $_POST['dest_weight'] ?? [];
            for ($i = 0; $i < count($destUrls); $i++) {
                $dUrl = trim($destUrls[$i] ?? '');
                $dWeight = max(1, (int)($destWeights[$i] ?? 1));
                if (!empty($dUrl)) {
                    $stmt = $db->prepare('INSERT INTO group_destinations (group_id, destination_url, weight) VALUES (?, ?, ?)');
                    $stmt->execute([$id, $dUrl, $dWeight]);
                }
            }

            AuditLog::log('update', 'group', $id);
            setFlash('success', 'グループを更新しました。');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM `groups` WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'group', $id);
            setFlash('success', 'グループを削除しました。');
        }
    }

    header('Location: ' . BASE_PATH . '/admin/groups.php');
    exit;
}

// グループ一覧
$groups = $db->query(
    'SELECT g.*, COUNT(u.id) AS url_count
     FROM `groups` g
     LEFT JOIN urls u ON u.group_id = g.id
     GROUP BY g.id
     ORDER BY g.name'
)->fetchAll();

// 各グループの転送先
$groupDests = [];
foreach ($groups as $g) {
    $stmt = $db->prepare('SELECT * FROM group_destinations WHERE group_id = ? ORDER BY id');
    $stmt->execute([$g['id']]);
    $groupDests[$g['id']] = $stmt->fetchAll();
}

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-diagram-3 me-2"></i>グループ管理</h4>

<!-- 追加フォーム -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <a data-bs-toggle="collapse" href="#addGroupForm" class="text-decoration-none">
            <i class="bi bi-plus-circle me-1"></i>グループを追加する
        </a>
    </div>
    <div class="collapse" id="addGroupForm">
        <div class="card-body">
            <form method="post">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="add">

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">グループ名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">転送方式</label>
                        <select class="form-select" name="redirect_method">
                            <option value="jump">ジャンプ</option>
                            <option value="preserve">URL保持</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">メモ</label>
                        <input type="text" class="form-control" name="memo">
                    </div>
                </div>

                <h6 class="mt-3">振り分けルール</h6>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">成約率の差（%以上で固定）</label>
                        <input type="number" class="form-control" name="rule_cv_rate_diff" value="0" min="0" step="0.1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">最低成約数（これ以上で固定判定）</label>
                        <input type="number" class="form-control" name="rule_cv_count_min" value="0" min="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">固定方法</label>
                        <select class="form-select" name="rule_fix_method">
                            <option value="first">初回転送先を固定</option>
                            <option value="best">成約率最高を固定</option>
                        </select>
                    </div>
                </div>

                <h6 class="mt-3">転送先</h6>
                <div id="new-destinations">
                    <div class="row mb-2 dest-row">
                        <div class="col-md-8">
                            <input type="url" class="form-control form-control-sm" name="dest_url[]" placeholder="https://example.com/a">
                        </div>
                        <div class="col-md-2">
                            <input type="number" class="form-control form-control-sm" name="dest_weight[]" value="1" min="1" placeholder="比率">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="addDestRow('new-destinations')">+</button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-plus me-1"></i>追加</button>
            </form>
        </div>
    </div>
</div>

<!-- グループ一覧 -->
<?php foreach ($groups as $g):
    $rules = json_decode($g['rules_json'] ?? '{}', true) ?: [];
    $dests = $groupDests[$g['id']] ?? [];
?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <strong><?= h($g['name']) ?></strong>
            <span class="badge bg-secondary ms-2"><?= $g['url_count'] ?> URLs</span>
        </span>
        <div>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#editGroup<?= $g['id'] ?>">
                <i class="bi bi-pencil"></i> 編集
            </button>
            <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">ルール:</small>
                <p class="mb-1">
                    成約率の差が <strong><?= $rules['fix_on_conversion_rate_diff'] ?? 0 ?>%</strong> 以上で固定。
                    ただし成約数が <strong><?= $rules['fix_on_conversion_count_min'] ?? 0 ?></strong> 以上の場合。
                    固定方法: <strong><?= ($rules['fix_method'] ?? 'first') === 'first' ? '初回転送先を固定' : '成約率最高を固定' ?></strong>
                </p>
            </div>
            <div class="col-md-6">
                <small class="text-muted">転送先:</small>
                <?php foreach ($dests as $d): ?>
                    <div class="small">
                        <span class="badge bg-info"><?= $d['weight'] ?></span>
                        <?= h(mb_strimwidth($d['destination_url'], 0, 60, '...')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 編集フォーム（折りたたみ） -->
    <div class="collapse" id="editGroup<?= $g['id'] ?>">
        <div class="card-body border-top">
            <form method="post">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $g['id'] ?>">

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">グループ名</label>
                        <input type="text" class="form-control" name="name" value="<?= h($g['name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">転送方式</label>
                        <select class="form-select" name="redirect_method">
                            <option value="jump" <?= $g['redirect_method'] === 'jump' ? 'selected' : '' ?>>ジャンプ</option>
                            <option value="preserve" <?= $g['redirect_method'] === 'preserve' ? 'selected' : '' ?>>URL保持</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">メモ</label>
                        <input type="text" class="form-control" name="memo" value="<?= h($g['memo']) ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">成約率の差（%）</label>
                        <input type="number" class="form-control" name="rule_cv_rate_diff" value="<?= $rules['fix_on_conversion_rate_diff'] ?? 0 ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">最低成約数</label>
                        <input type="number" class="form-control" name="rule_cv_count_min" value="<?= $rules['fix_on_conversion_count_min'] ?? 0 ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">固定方法</label>
                        <select class="form-select" name="rule_fix_method">
                            <option value="first" <?= ($rules['fix_method'] ?? 'first') === 'first' ? 'selected' : '' ?>>初回転送先を固定</option>
                            <option value="best" <?= ($rules['fix_method'] ?? '') === 'best' ? 'selected' : '' ?>>成約率最高を固定</option>
                        </select>
                    </div>
                </div>

                <h6>転送先</h6>
                <div id="edit-destinations-<?= $g['id'] ?>">
                    <?php foreach ($dests as $d): ?>
                    <div class="row mb-2 dest-row">
                        <div class="col-md-8">
                            <input type="url" class="form-control form-control-sm" name="dest_url[]" value="<?= h($d['destination_url']) ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="number" class="form-control form-control-sm" name="dest_weight[]" value="<?= $d['weight'] ?>" min="1">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.dest-row').remove()">-</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-success mb-3" onclick="addDestRow('edit-destinations-<?= $g['id'] ?>')">
                    <i class="bi bi-plus"></i> 転送先追加
                </button>

                <div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check me-1"></i>更新</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($groups)): ?>
    <div class="text-center text-muted py-4">グループがありません</div>
<?php endif; ?>

<script>
function addDestRow(containerId) {
    var container = document.getElementById(containerId);
    var row = document.createElement('div');
    row.className = 'row mb-2 dest-row';
    row.innerHTML = '<div class="col-md-8"><input type="url" class="form-control form-control-sm" name="dest_url[]" placeholder="https://example.com/"></div>'
        + '<div class="col-md-2"><input type="number" class="form-control form-control-sm" name="dest_weight[]" value="1" min="1"></div>'
        + '<div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.dest-row\').remove()">-</button></div>';
    container.appendChild(row);
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

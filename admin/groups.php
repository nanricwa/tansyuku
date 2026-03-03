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
            'fix_method' => $_POST['rule_fix_method'] ?? 'best',
        ];

        if (!empty($name)) {
            $stmt = $db->prepare('INSERT INTO `groups` (name, rules_json, redirect_method, memo) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, json_encode($rules), $redirectMethod, $memo]);
            $groupId = (int)$db->lastInsertId();

            // 転送先を追加
            $destUrls = $_POST['dest_url'] ?? [];
            $destWeights = $_POST['dest_weight'] ?? [];
            $destLabels = $_POST['dest_label'] ?? [];
            $labelIndex = 0;
            for ($i = 0; $i < count($destUrls); $i++) {
                $dUrl = trim($destUrls[$i] ?? '');
                $dWeight = max(1, (int)($destWeights[$i] ?? 1));
                $dLabel = trim($destLabels[$i] ?? '') ?: chr(65 + $labelIndex); // A, B, C...
                if (!empty($dUrl)) {
                    $stmt = $db->prepare('INSERT INTO group_destinations (group_id, destination_url, label, weight) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$groupId, $dUrl, $dLabel, $dWeight]);
                    $labelIndex++;
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
            'fix_method' => $_POST['rule_fix_method'] ?? 'best',
        ];

        if ($id && !empty($name)) {
            $stmt = $db->prepare('UPDATE `groups` SET name = ?, rules_json = ?, redirect_method = ?, memo = ? WHERE id = ?');
            $stmt->execute([$name, json_encode($rules), $redirectMethod, $memo, $id]);

            // 転送先を再構築
            $db->prepare('DELETE FROM group_destinations WHERE group_id = ?')->execute([$id]);
            $destUrls = $_POST['dest_url'] ?? [];
            $destWeights = $_POST['dest_weight'] ?? [];
            $destLabels = $_POST['dest_label'] ?? [];
            $labelIndex = 0;
            for ($i = 0; $i < count($destUrls); $i++) {
                $dUrl = trim($destUrls[$i] ?? '');
                $dWeight = max(1, (int)($destWeights[$i] ?? 1));
                $dLabel = trim($destLabels[$i] ?? '') ?: chr(65 + $labelIndex);
                if (!empty($dUrl)) {
                    $stmt = $db->prepare('INSERT INTO group_destinations (group_id, destination_url, label, weight) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$id, $dUrl, $dLabel, $dWeight]);
                    $labelIndex++;
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
    } elseif ($action === 'unlock') {
        // ロック解除
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare('UPDATE `groups` SET is_locked = 0, locked_destination_id = NULL WHERE id = ?');
            $stmt->execute([$id]);
            AuditLog::log('update', 'group', $id, json_encode(['action' => 'unlock']));
            setFlash('success', 'グループのロックを解除しました。A/Bテストが再開されます。');
        }
    } elseif ($action === 'lock') {
        // 手動ロック
        $id = (int)($_POST['id'] ?? 0);
        $destId = (int)($_POST['lock_destination_id'] ?? 0);
        if ($id && $destId) {
            $stmt = $db->prepare('UPDATE `groups` SET is_locked = 1, locked_destination_id = ? WHERE id = ?');
            $stmt->execute([$destId, $id]);
            AuditLog::log('update', 'group', $id, json_encode(['action' => 'lock', 'destination_id' => $destId]));
            setFlash('success', '指定した転送先にロック（固定）しました。');
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

// 各グループの転送先 + 統計情報
$groupDests = [];
$groupStats = [];
foreach ($groups as $g) {
    $stmt = $db->prepare('SELECT * FROM group_destinations WHERE group_id = ? ORDER BY id');
    $stmt->execute([$g['id']]);
    $dests = $stmt->fetchAll();
    $groupDests[$g['id']] = $dests;

    // 各転送先のA/B統計を取得
    $stats = [];
    foreach ($dests as $d) {
        $destId = (int)$d['id'];

        // クリック数（全体）
        $stmt = $db->prepare('SELECT COUNT(*) FROM clicks WHERE destination_id = ?');
        $stmt->execute([$destId]);
        $totalClicks = (int)$stmt->fetchColumn();

        // ユニーククリック数
        $stmt = $db->prepare('SELECT COUNT(*) FROM clicks WHERE destination_id = ? AND is_unique = 1');
        $stmt->execute([$destId]);
        $uniqueClicks = (int)$stmt->fetchColumn();

        // CV数
        $stmt = $db->prepare('SELECT COUNT(*) FROM conversions WHERE destination_id = ?');
        $stmt->execute([$destId]);
        $conversions = (int)$stmt->fetchColumn();

        $cvRate = $uniqueClicks > 0 ? round($conversions / $uniqueClicks * 100, 2) : 0;

        $stats[$destId] = [
            'total_clicks' => $totalClicks,
            'unique_clicks' => $uniqueClicks,
            'conversions' => $conversions,
            'cv_rate' => $cvRate,
        ];
    }
    $groupStats[$g['id']] = $stats;
}

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-diagram-3 me-2"></i>グループ管理（A/Bテスト）</h4>

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

                <h6 class="mt-3"><i class="bi bi-gear me-1"></i>A/B自動最適化ルール</h6>
                <div class="alert alert-info small py-2">
                    <i class="bi bi-info-circle me-1"></i>
                    成約率の差が指定%以上開き、かつ合計成約数が最低ラインを超えた場合、勝者を自動ロック（固定）します。
                    0に設定すると自動最適化は無効です。
                </div>
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
                            <option value="best">成約率最高を固定（推奨）</option>
                            <option value="first">初回転送先を固定</option>
                        </select>
                    </div>
                </div>

                <h6 class="mt-3"><i class="bi bi-signpost-2 me-1"></i>転送先（A/B候補）</h6>
                <div id="new-destinations">
                    <div class="row mb-2 dest-row">
                        <div class="col-md-1">
                            <input type="text" class="form-control form-control-sm" name="dest_label[]" placeholder="A" value="A">
                        </div>
                        <div class="col-md-7">
                            <input type="url" class="form-control form-control-sm" name="dest_url[]" placeholder="https://example.com/page-a">
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
    $stats = $groupStats[$g['id']] ?? [];
    $isLocked = (bool)$g['is_locked'];
    $lockedDestId = $g['locked_destination_id'] ? (int)$g['locked_destination_id'] : null;

    // 最高CV率の転送先を特定
    $bestDestId = null;
    $bestCvRate = -1;
    foreach ($stats as $dId => $st) {
        if ($st['cv_rate'] > $bestCvRate && $st['unique_clicks'] > 0) {
            $bestCvRate = $st['cv_rate'];
            $bestDestId = $dId;
        }
    }
?>
<div class="card mb-3 <?= $isLocked ? 'border-success' : '' ?>">
    <div class="card-header d-flex justify-content-between align-items-center <?= $isLocked ? 'bg-success bg-opacity-10' : '' ?>">
        <span>
            <strong><?= h($g['name']) ?></strong>
            <span class="badge bg-secondary ms-2"><?= $g['url_count'] ?> URLs</span>
            <?php if ($isLocked): ?>
                <span class="badge bg-success ms-1"><i class="bi bi-lock-fill me-1"></i>ロック中</span>
            <?php else: ?>
                <span class="badge bg-primary ms-1"><i class="bi bi-shuffle me-1"></i>テスト中</span>
            <?php endif; ?>
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
        <!-- A/B比較テーブル -->
        <?php if (!empty($dests)): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px">ラベル</th>
                        <th>転送先URL</th>
                        <th class="text-center" style="width:60px">比率</th>
                        <th class="text-center" style="width:90px">クリック</th>
                        <th class="text-center" style="width:90px">ユニーク</th>
                        <th class="text-center" style="width:70px">CV数</th>
                        <th class="text-center" style="width:80px">CV率</th>
                        <th class="text-center" style="width:100px">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dests as $d):
                        $dId = (int)$d['id'];
                        $st = $stats[$dId] ?? ['total_clicks' => 0, 'unique_clicks' => 0, 'conversions' => 0, 'cv_rate' => 0];
                        $isBest = ($dId === $bestDestId && $st['unique_clicks'] > 0);
                        $isLockedDest = ($isLocked && $dId === $lockedDestId);
                        $label = $d['label'] ?: '-';
                    ?>
                    <tr class="<?= $isLockedDest ? 'table-success' : ($isBest ? 'table-warning' : '') ?>">
                        <td class="text-center">
                            <span class="badge bg-<?= $isLockedDest ? 'success' : ($isBest ? 'warning text-dark' : 'secondary') ?> fs-6">
                                <?= h($label) ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= h($d['destination_url']) ?>" target="_blank" class="text-truncate d-inline-block" style="max-width:300px;">
                                <?= h($d['destination_url']) ?>
                            </a>
                            <?php if ($isLockedDest): ?>
                                <i class="bi bi-lock-fill text-success ms-1" title="ロック中"></i>
                            <?php elseif ($isBest): ?>
                                <i class="bi bi-trophy-fill text-warning ms-1" title="現在最高"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= $d['weight'] ?></td>
                        <td class="text-center"><?= number_format($st['total_clicks']) ?></td>
                        <td class="text-center"><?= number_format($st['unique_clicks']) ?></td>
                        <td class="text-center fw-bold"><?= number_format($st['conversions']) ?></td>
                        <td class="text-center">
                            <span class="fw-bold <?= $isBest ? 'text-success' : '' ?>">
                                <?= $st['cv_rate'] ?>%
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if (!$isLocked): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('この転送先に固定しますか？A/Bテストは停止します。');">
                                <?= Csrf::tokenField() ?>
                                <input type="hidden" name="action" value="lock">
                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                <input type="hidden" name="lock_destination_id" value="<?= $dId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success" title="この転送先に固定">
                                    <i class="bi bi-lock"></i> 固定
                                </button>
                            </form>
                            <?php else: ?>
                                <?= $isLockedDest ? '<span class="text-success small">固定中</span>' : '' ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- CV率比較バー -->
        <?php
        $maxCvRate = max(array_column($stats, 'cv_rate') ?: [0]);
        if ($maxCvRate > 0):
        ?>
        <div class="mb-3">
            <small class="text-muted fw-bold">CV率比較</small>
            <?php foreach ($dests as $d):
                $dId = (int)$d['id'];
                $st = $stats[$dId] ?? ['cv_rate' => 0, 'conversions' => 0, 'unique_clicks' => 0];
                $barWidth = $maxCvRate > 0 ? ($st['cv_rate'] / $maxCvRate * 100) : 0;
                $label = $d['label'] ?: '-';
                $isBest = ($dId === $bestDestId);
            ?>
            <div class="d-flex align-items-center mb-1">
                <span class="badge bg-secondary me-2" style="width:30px"><?= h($label) ?></span>
                <div class="progress flex-grow-1" style="height: 20px;">
                    <div class="progress-bar <?= $isBest ? 'bg-success' : 'bg-primary' ?>"
                         style="width: <?= $barWidth ?>%">
                        <?= $st['cv_rate'] ?>% (<?= $st['conversions'] ?>/<?= $st['unique_clicks'] ?>)
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- ルール・ロック情報 -->
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">自動最適化ルール:</small>
                <?php if (($rules['fix_on_conversion_rate_diff'] ?? 0) > 0): ?>
                <p class="mb-1 small">
                    成約率の差が <strong><?= $rules['fix_on_conversion_rate_diff'] ?>%</strong> 以上、
                    合計成約数が <strong><?= $rules['fix_on_conversion_count_min'] ?? 0 ?></strong> 件以上で自動固定。
                    方法: <strong><?= ($rules['fix_method'] ?? 'best') === 'best' ? '成約率最高を固定' : '初回転送先を固定' ?></strong>
                </p>
                <?php else: ?>
                <p class="mb-1 small text-muted">自動最適化は無効（手動で固定してください）</p>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-end">
                <?php if ($isLocked): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('ロックを解除してA/Bテストを再開しますか？');">
                    <?= Csrf::tokenField() ?>
                    <input type="hidden" name="action" value="unlock">
                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-unlock me-1"></i>ロック解除（テスト再開）
                    </button>
                </form>
                <?php endif; ?>
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

                <h6><i class="bi bi-gear me-1"></i>自動最適化ルール</h6>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">成約率の差（%）</label>
                        <input type="number" class="form-control" name="rule_cv_rate_diff" value="<?= $rules['fix_on_conversion_rate_diff'] ?? 0 ?>" step="0.1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">最低成約数</label>
                        <input type="number" class="form-control" name="rule_cv_count_min" value="<?= $rules['fix_on_conversion_count_min'] ?? 0 ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">固定方法</label>
                        <select class="form-select" name="rule_fix_method">
                            <option value="best" <?= ($rules['fix_method'] ?? 'best') === 'best' ? 'selected' : '' ?>>成約率最高を固定</option>
                            <option value="first" <?= ($rules['fix_method'] ?? '') === 'first' ? 'selected' : '' ?>>初回転送先を固定</option>
                        </select>
                    </div>
                </div>

                <h6><i class="bi bi-signpost-2 me-1"></i>転送先</h6>
                <div id="edit-destinations-<?= $g['id'] ?>">
                    <?php foreach ($dests as $di => $d): ?>
                    <div class="row mb-2 dest-row">
                        <div class="col-md-1">
                            <input type="text" class="form-control form-control-sm" name="dest_label[]" value="<?= h($d['label'] ?: chr(65 + $di)) ?>" placeholder="A">
                        </div>
                        <div class="col-md-7">
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
    <div class="text-center text-muted py-4">
        <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>
        グループがありません。<br>
        上の「グループを追加する」からA/Bテストを始めましょう。
    </div>
<?php endif; ?>

<script>
var destCounter = 100;
function addDestRow(containerId) {
    var container = document.getElementById(containerId);
    var existingRows = container.querySelectorAll('.dest-row').length;
    var nextLabel = String.fromCharCode(65 + existingRows);
    var row = document.createElement('div');
    row.className = 'row mb-2 dest-row';
    row.innerHTML = '<div class="col-md-1"><input type="text" class="form-control form-control-sm" name="dest_label[]" placeholder="' + nextLabel + '" value="' + nextLabel + '"></div>'
        + '<div class="col-md-7"><input type="url" class="form-control form-control-sm" name="dest_url[]" placeholder="https://example.com/"></div>'
        + '<div class="col-md-2"><input type="number" class="form-control form-control-sm" name="dest_weight[]" value="1" min="1"></div>'
        + '<div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.dest-row\').remove()">-</button></div>';
    container.appendChild(row);
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

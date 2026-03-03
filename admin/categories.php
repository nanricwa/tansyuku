<?php
/**
 * カテゴリ管理
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = 'カテゴリ管理';

// 追加・更新・削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/categories.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if (!empty($name)) {
            $stmt = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
            $stmt->execute([$name, $sortOrder]);
            AuditLog::log('create', 'category', (int)$db->lastInsertId());
            setFlash('success', 'カテゴリを追加しました。');
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        if ($id && !empty($name)) {
            $stmt = $db->prepare('UPDATE categories SET name = ?, sort_order = ? WHERE id = ?');
            $stmt->execute([$name, $sortOrder, $id]);
            AuditLog::log('update', 'category', $id);
            setFlash('success', 'カテゴリを更新しました。');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'category', $id);
            setFlash('success', 'カテゴリを削除しました。');
        }
    }

    header('Location: ' . BASE_PATH . '/admin/categories.php');
    exit;
}

// カテゴリ一覧（URL数付き）
$categories = $db->query(
    'SELECT c.*, COUNT(u.id) AS url_count
     FROM categories c
     LEFT JOIN urls u ON u.category_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order, c.name'
)->fetchAll();

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-tags me-2"></i>カテゴリ管理</h4>

<!-- 追加フォーム -->
<div class="card mb-4">
    <div class="card-header bg-light">カテゴリを追加</div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?= Csrf::tokenField() ?>
            <input type="hidden" name="action" value="add">
            <div class="col-md-6">
                <label class="form-label">カテゴリ名</label>
                <input type="text" class="form-control" name="name" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">表示順</label>
                <input type="number" class="form-control" name="sort_order" value="0">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus me-1"></i>追加</button>
            </div>
        </form>
    </div>
</div>

<!-- カテゴリ一覧 -->
<div class="card">
    <div class="card-body">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>カテゴリ名</th>
                    <th>表示順</th>
                    <th>URL数</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="5" class="text-center text-muted">カテゴリがありません</td></tr>
                <?php endif; ?>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><?= $cat['id'] ?></td>
                    <td>
                        <form method="post" class="d-flex gap-2">
                            <?= Csrf::tokenField() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                            <input type="text" class="form-control form-control-sm" name="name" value="<?= h($cat['name']) ?>" style="max-width:200px;">
                    </td>
                    <td>
                            <input type="number" class="form-control form-control-sm" name="sort_order" value="<?= $cat['sort_order'] ?>" style="max-width:80px;">
                    </td>
                    <td><span class="badge bg-secondary"><?= $cat['url_count'] ?></span></td>
                    <td>
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check"></i></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');">
                            <?= Csrf::tokenField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>

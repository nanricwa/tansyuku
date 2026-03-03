<?php
/**
 * ユーザー管理（admin専用）
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireAdmin();

$db = Database::getConnection();
$pageTitle = 'ユーザー管理';

// 処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/users.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';

        $errors = [];
        if (empty($username)) $errors[] = 'ユーザー名を入力してください。';
        if (strlen($password) < 8) $errors[] = 'パスワードは8文字以上にしてください。';

        // 重複チェック
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ((int)$stmt->fetchColumn() > 0) $errors[] = 'このユーザー名は既に使用されています。';

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$username, $email, $hash, $role]);
            AuditLog::log('create', 'user', (int)$db->lastInsertId());
            setFlash('success', 'ユーザーを追加しました。');
        } else {
            setFlash('danger', implode('<br>', $errors));
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = $_POST['new_password'] ?? '';

        if ($id) {
            $stmt = $db->prepare('UPDATE users SET email = ?, role = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$email, $role, $isActive, $id]);

            if (!empty($newPassword) && strlen($newPassword) >= 8) {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->execute([$hash, $id]);
            }

            AuditLog::log('update', 'user', $id);
            setFlash('success', 'ユーザーを更新しました。');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // 自分自身は削除不可
        if ($id && $id !== Auth::userId()) {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'user', $id);
            setFlash('success', 'ユーザーを削除しました。');
        } else {
            setFlash('danger', '自分自身は削除できません。');
        }
    }

    header('Location: ' . BASE_PATH . '/admin/users.php');
    exit;
}

// ユーザー一覧
$users = $db->query(
    'SELECT u.*, (SELECT COUNT(*) FROM urls WHERE user_id = u.id) AS url_count
     FROM users u ORDER BY u.id'
)->fetchAll();

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-people me-2"></i>ユーザー管理</h4>

<!-- 追加フォーム -->
<div class="card mb-4">
    <div class="card-header bg-light">ユーザーを追加</div>
    <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
            <?= Csrf::tokenField() ?>
            <input type="hidden" name="action" value="add">
            <div class="col-md-3">
                <label class="form-label">ユーザー名 <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="username" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">メールアドレス</label>
                <input type="email" class="form-control" name="email">
            </div>
            <div class="col-md-2">
                <label class="form-label">パスワード <span class="text-danger">*</span></label>
                <input type="password" class="form-control" name="password" required minlength="8">
            </div>
            <div class="col-md-2">
                <label class="form-label">権限</label>
                <select class="form-select" name="role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus me-1"></i>追加</button>
            </div>
        </form>
    </div>
</div>

<!-- ユーザー一覧 -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>ユーザー名</th>
                    <th>メール</th>
                    <th>権限</th>
                    <th>URL数</th>
                    <th>状態</th>
                    <th>作成日</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><strong><?= h($u['username']) ?></strong></td>
                    <td><?= h($u['email']) ?></td>
                    <td><span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : 'secondary' ?>"><?= h($u['role']) ?></span></td>
                    <td><?= $u['url_count'] ?></td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-warning">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($u['created_at']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUser<?= $u['id'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if ($u['id'] !== Auth::userId()): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');">
                            <?= Csrf::tokenField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- 編集モーダル -->
                <div class="modal fade" id="editUser<?= $u['id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post">
                                <?= Csrf::tokenField() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">ユーザー編集: <?= h($u['username']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">メールアドレス</label>
                                        <input type="email" class="form-control" name="email" value="<?= h($u['email']) ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">権限</label>
                                        <select class="form-select" name="role">
                                            <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">新しいパスワード（変更する場合のみ）</label>
                                        <input type="password" class="form-control" name="new_password" minlength="8">
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $u['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label">有効</label>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                                    <button type="submit" class="btn btn-primary">更新</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>

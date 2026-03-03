<?php
/**
 * ログイン画面 / ログアウト処理
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::init();

// ログアウト処理
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    Auth::logout();
    header('Location: ' . BASE_PATH . '/admin/index.php');
    exit;
}

// ログイン済みならダッシュボードへ
if (Auth::isLoggedIn()) {
    header('Location: ' . BASE_PATH . '/admin/dashboard.php');
    exit;
}

$error = '';

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。もう一度お試しください。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'ユーザー名とパスワードを入力してください。';
        } else {
            $result = Auth::login($username, $password);
            if ($result['success']) {
                header('Location: ' . BASE_PATH . '/admin/dashboard.php');
                exit;
            }
            $error = $result['message'];
        }
    }
}

include __DIR__ . '/../templates/login_layout.php';
?>
<div class="login-container">
    <div class="card login-card">
        <div class="card-body">
            <div class="text-center mb-4">
                <i class="bi bi-link-45deg fs-1 text-primary"></i>
                <h4 class="mt-2"><?= h(APP_NAME) ?></h4>
                <p class="text-muted">管理画面にログイン</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <?= Csrf::tokenField() ?>
                <div class="mb-3">
                    <label for="username" class="form-label">ユーザー名</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= h($_POST['username'] ?? '') ?>" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">パスワード</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-1"></i>ログイン
                </button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

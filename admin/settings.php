<?php
/**
 * 設定画面
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = '設定';

// 更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/settings.php');
        exit;
    }

    $settingsToSave = [
        'base_url', 'default_slug_type', 'default_redirect_type',
        'clipboard_format', 'error_page_url', 'allow_anonymous_create',
        'exclude_own_clicks',
    ];

    foreach ($settingsToSave as $key) {
        $value = trim($_POST[$key] ?? '');
        Database::setSetting($key, $value);
    }

    // パスワード変更（自分のパスワード）
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (!empty($newPassword)) {
        if (strlen($newPassword) < 8) {
            setFlash('danger', 'パスワードは8文字以上にしてください。');
            header('Location: ' . BASE_PATH . '/admin/settings.php');
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            setFlash('danger', 'パスワードが一致しません。');
            header('Location: ' . BASE_PATH . '/admin/settings.php');
            exit;
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, Auth::userId()]);
    }

    AuditLog::log('update', 'settings', null);
    setFlash('success', '設定を更新しました。');
    header('Location: ' . BASE_PATH . '/admin/settings.php');
    exit;
}

// 現在の設定値取得
$settings = [
    'base_url' => Database::getSetting('base_url', 'https://example.com/url'),
    'default_slug_type' => Database::getSetting('default_slug_type', 'custom'),
    'default_redirect_type' => Database::getSetting('default_redirect_type', 'jump'),
    'clipboard_format' => Database::getSetting('clipboard_format', '/xxx.html'),
    'error_page_url' => Database::getSetting('error_page_url', ''),
    'allow_anonymous_create' => Database::getSetting('allow_anonymous_create', '0'),
    'exclude_own_clicks' => Database::getSetting('exclude_own_clicks', '0'),
];

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-gear me-2"></i>設定</h4>

<form method="post">
    <?= Csrf::tokenField() ?>

    <!-- 基本設定 -->
    <div class="card mb-4">
        <div class="card-header bg-light">基本設定</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label fw-bold">ドメイン（ベースURL）</label>
                <input type="url" class="form-control" name="base_url" value="<?= h($settings['base_url']) ?>">
                <div class="form-text">ここで設定した URL + スラッグ が短縮URLとなります。例: https://example.com/url</div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">短縮タイプの初期値</label>
                    <select class="form-select" name="default_slug_type">
                        <option value="alphabet" <?= $settings['default_slug_type'] === 'alphabet' ? 'selected' : '' ?>>index+アルファベット</option>
                        <option value="word" <?= $settings['default_slug_type'] === 'word' ? 'selected' : '' ?>>ランダム単語</option>
                        <option value="custom" <?= $settings['default_slug_type'] === 'custom' ? 'selected' : '' ?>>カスタム入力</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">転送タイプの初期値</label>
                    <select class="form-select" name="default_redirect_type">
                        <option value="jump" <?= $settings['default_redirect_type'] === 'jump' ? 'selected' : '' ?>>ジャンプ</option>
                        <option value="preserve" <?= $settings['default_redirect_type'] === 'preserve' ? 'selected' : '' ?>>URL保持</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">クリップボードコピー形式</label>
                    <select class="form-select" name="clipboard_format">
                        <option value="/xxx" <?= $settings['clipboard_format'] === '/xxx' ? 'selected' : '' ?>>/xxx</option>
                        <option value="/xxx.htm" <?= $settings['clipboard_format'] === '/xxx.htm' ? 'selected' : '' ?>>/xxx.htm</option>
                        <option value="/xxx.html" <?= $settings['clipboard_format'] === '/xxx.html' ? 'selected' : '' ?>>/xxx.html</option>
                        <option value="/xxx/" <?= $settings['clipboard_format'] === '/xxx/' ? 'selected' : '' ?>>/xxx/</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- 動作設定 -->
    <div class="card mb-4">
        <div class="card-header bg-light">動作設定</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label fw-bold">エラー時の表示ページURL</label>
                <input type="url" class="form-control" name="error_page_url" value="<?= h($settings['error_page_url']) ?>"
                       placeholder="https://example.com/404.html">
                <div class="form-text">存在しない短縮URLがクリックされた際に表示するページ。空の場合はデフォルトのエラーページを表示。</div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_anonymous_create" value="1"
                           <?= $settings['allow_anonymous_create'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold">ログイン無しでのURL短縮を許可</label>
                </div>
                <div class="form-text">有効にすると、ログインなしでURL短縮が可能になります。</div>
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="exclude_own_clicks" value="1"
                           <?= $settings['exclude_own_clicks'] === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold">自分のクリックをカウントしない</label>
                </div>
                <div class="form-text">ログイン中のユーザーのクリックを解析データから除外します。</div>
            </div>
        </div>
    </div>

    <!-- パスワード変更 -->
    <div class="card mb-4">
        <div class="card-header bg-light">パスワード変更</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">新しいパスワード</label>
                    <input type="password" class="form-control" name="new_password" minlength="8"
                           placeholder="変更する場合のみ入力">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">パスワード確認</label>
                    <input type="password" class="form-control" name="confirm_password" minlength="8"
                           placeholder="もう一度入力">
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">
        <i class="bi bi-check-circle me-1"></i>設定を更新
    </button>
</form>

<?php include __DIR__ . '/../templates/footer.php'; ?>

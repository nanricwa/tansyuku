<?php
/**
 * URL作成画面
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = 'URL作成';
$createdUrl = null;

// カテゴリ一覧
$categories = $db->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();

// グループ一覧
$groups = $db->query('SELECT * FROM `groups` ORDER BY name')->fetchAll();

// デフォルト値
$defaultSlugType = Database::getSetting('default_slug_type', 'custom');
$defaultRedirectType = Database::getSetting('default_redirect_type', 'jump');

// URL作成処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/create.php');
        exit;
    }

    $destinationUrl = trim($_POST['destination_url'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $slugType = $_POST['slug_type'] ?? 'custom';
    $customSlug = trim($_POST['custom_slug'] ?? '');
    $redirectType = $_POST['redirect_type'] ?? 'jump';
    $titleBarText = trim($_POST['title_bar_text'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
    $memo = trim($_POST['memo'] ?? '');
    $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    // バリデーション
    $errors = [];
    if (empty($destinationUrl)) {
        $errors[] = '転送先URLを入力してください。';
    } elseif (!filter_var($destinationUrl, FILTER_VALIDATE_URL)) {
        $errors[] = '有効なURLを入力してください。';
    }

    // スラッグ決定
    switch ($slugType) {
        case 'alphabet':
            $slug = generateAlphabetSlug();
            break;
        case 'word':
            $slug = generateWordSlug();
            break;
        case 'custom':
        default:
            $slug = $customSlug;
            if (empty($slug)) {
                $errors[] = 'カスタムスラッグを入力してください。';
            } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
                $errors[] = 'スラッグは英数字、ハイフン、アンダースコアのみ使用できます。';
            } elseif (!isSlugAvailable($slug)) {
                $errors[] = 'このスラッグは既に使用されています。';
            }
            break;
    }

    if (empty($errors)) {
        // 名前が空ならスラッグを使用
        if (empty($name)) {
            $name = $slug;
        }

        $stmt = $db->prepare(
            'INSERT INTO urls (user_id, slug, name, destination_url, redirect_type, title_bar_text, category_id, group_id, memo, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Auth::userId(), $slug, $name, $destinationUrl, $redirectType,
            $titleBarText, $categoryId, $groupId, $memo, $expiresAt
        ]);

        $urlId = (int)$db->lastInsertId();
        AuditLog::log('create', 'url', $urlId, json_encode(['slug' => $slug, 'destination' => $destinationUrl]));

        // 作成結果を表示
        $clipboardFormat = Database::getSetting('clipboard_format', '/xxx.html');
        $format = '';
        if (strpos($clipboardFormat, '.htm') !== false && strpos($clipboardFormat, '.html') === false) {
            $format = 'htm';
        } elseif (strpos($clipboardFormat, '.html') !== false) {
            $format = 'html';
        } elseif (substr($clipboardFormat, -1) === '/') {
            $format = 'dir';
        }

        $createdUrl = [
            'slug' => $slug,
            'urls' => [
                'default' => buildShortUrl($slug),
                'htm' => buildShortUrl($slug, 'htm'),
                'html' => buildShortUrl($slug, 'html'),
                'dir' => buildShortUrl($slug, 'dir'),
            ],
            'destination' => $destinationUrl,
            'clipboard_url' => buildShortUrl($slug, $format),
        ];

        setFlash('success', '短縮URLを作成しました。');
    } else {
        setFlash('danger', implode('<br>', $errors));
    }
}

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-plus-circle me-2"></i>URL作成</h4>

<?php if ($createdUrl): ?>
<div class="card border-success mb-4">
    <div class="card-header bg-success text-white">
        <i class="bi bi-check-circle me-1"></i>短縮URLが作成されました
    </div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label fw-bold">短縮URL</label>
            <?php foreach ($createdUrl['urls'] as $format => $url): ?>
            <div class="input-group mb-1">
                <span class="input-group-text" style="width: 80px;"><?= h($format) ?></span>
                <input type="text" class="form-control" value="<?= h($url) ?>" readonly>
                <button class="btn btn-outline-secondary btn-copy" type="button" data-copy="<?= h($url) ?>">
                    <i class="bi bi-clipboard"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mb-2">
            <label class="form-label fw-bold">転送先</label>
            <p><a href="<?= h($createdUrl['destination']) ?>" target="_blank"><?= h($createdUrl['destination']) ?></a></p>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="">
            <?= Csrf::tokenField() ?>

            <!-- 転送先URL -->
            <div class="mb-4">
                <label for="destination_url" class="form-label fw-bold">
                    <i class="bi bi-globe me-1"></i>転送先URL <span class="text-danger">*</span>
                </label>
                <input type="url" class="form-control form-control-lg" id="destination_url" name="destination_url"
                       placeholder="https://example.com/page" value="<?= h($_POST['destination_url'] ?? '') ?>" required>
            </div>

            <hr class="my-4">
            <h6 class="text-muted mb-3">オプション設定</h6>

            <div class="row">
                <!-- 名前 -->
                <div class="col-md-6 mb-3">
                    <label for="name" class="form-label">名前（未入力で短縮URLを使用）</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= h($_POST['name'] ?? '') ?>">
                </div>

                <!-- 有効期限 -->
                <div class="col-md-6 mb-3">
                    <label for="expires_at" class="form-label">有効期限</label>
                    <input type="datetime-local" class="form-control" id="expires_at" name="expires_at"
                           value="<?= h($_POST['expires_at'] ?? '') ?>">
                </div>
            </div>

            <!-- 短縮タイプ -->
            <div class="mb-3">
                <label class="form-label">短縮タイプ</label>
                <div class="d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="slug_type" id="slug_alphabet"
                               value="alphabet" <?= ($defaultSlugType === 'alphabet') ? 'checked' : '' ?>
                               onchange="toggleCustomSlug()">
                        <label class="form-check-label" for="slug_alphabet">index+アルファベット</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="slug_type" id="slug_word"
                               value="word" <?= ($defaultSlugType === 'word') ? 'checked' : '' ?>
                               onchange="toggleCustomSlug()">
                        <label class="form-check-label" for="slug_word">ランダム単語</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="slug_type" id="slug_custom"
                               value="custom" <?= ($defaultSlugType === 'custom') ? 'checked' : '' ?>
                               onchange="toggleCustomSlug()">
                        <label class="form-check-label" for="slug_custom">カスタム入力</label>
                    </div>
                </div>
                <div id="custom_slug_wrapper" class="mt-2" style="<?= ($defaultSlugType !== 'custom') ? 'display:none;' : '' ?>">
                    <input type="text" class="form-control" id="custom_slug" name="custom_slug"
                           placeholder="my-short-url" value="<?= h($_POST['custom_slug'] ?? '') ?>"
                           pattern="[a-zA-Z0-9_-]+">
                    <div class="form-text">英数字、ハイフン、アンダースコアが使用可能</div>
                </div>
            </div>

            <div class="row">
                <!-- カテゴリ -->
                <div class="col-md-4 mb-3">
                    <label for="category_id" class="form-label">カテゴリ</label>
                    <select class="form-select" id="category_id" name="category_id">
                        <option value="">（指定しない）</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= (($_POST['category_id'] ?? '') == $cat['id']) ? 'selected' : '' ?>>
                                <?= h($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- グループ -->
                <div class="col-md-4 mb-3">
                    <label for="group_id" class="form-label">グループ</label>
                    <select class="form-select" id="group_id" name="group_id">
                        <option value="">（指定しない）</option>
                        <?php foreach ($groups as $grp): ?>
                            <option value="<?= $grp['id'] ?>" <?= (($_POST['group_id'] ?? '') == $grp['id']) ? 'selected' : '' ?>>
                                <?= h($grp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 転送方法 -->
                <div class="col-md-4 mb-3">
                    <label class="form-label">転送方法</label>
                    <div class="d-flex gap-3 mt-1">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="redirect_type" id="redirect_jump"
                                   value="jump" <?= ($defaultRedirectType === 'jump') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="redirect_jump">ジャンプ</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="redirect_type" id="redirect_preserve"
                                   value="preserve" <?= ($defaultRedirectType === 'preserve') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="redirect_preserve">URL保持</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- タイトルバー文字（URL保持時のみ） -->
            <div class="mb-3">
                <label for="title_bar_text" class="form-label">タイトルバー文字（URL保持の場合のみ）</label>
                <input type="text" class="form-control" id="title_bar_text" name="title_bar_text"
                       value="<?= h($_POST['title_bar_text'] ?? '') ?>">
            </div>

            <!-- メモ -->
            <div class="mb-4">
                <label for="memo" class="form-label">メモ</label>
                <textarea class="form-control" id="memo" name="memo" rows="2"><?= h($_POST['memo'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-plus-circle me-1"></i>短縮URLを作成
            </button>
        </form>
    </div>
</div>

<script>
function toggleCustomSlug() {
    var wrapper = document.getElementById('custom_slug_wrapper');
    var radio = document.getElementById('slug_custom');
    wrapper.style.display = radio.checked ? '' : 'none';
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

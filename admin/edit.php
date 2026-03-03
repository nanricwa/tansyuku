<?php
/**
 * URL編集画面
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = 'URL編集';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . BASE_PATH . '/admin/manage.php');
    exit;
}

// URL取得
$stmt = $db->prepare('SELECT * FROM urls WHERE id = ?');
$stmt->execute([$id]);
$url = $stmt->fetch();

if (!$url) {
    setFlash('danger', 'URLが見つかりません。');
    header('Location: ' . BASE_PATH . '/admin/manage.php');
    exit;
}

// 権限チェック（adminか作成者のみ）
if (!Auth::isAdmin() && $url['user_id'] != Auth::userId()) {
    http_response_code(403);
    echo '権限がありません。';
    exit;
}

$categories = $db->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
$groups = $db->query('SELECT * FROM `groups` ORDER BY name')->fetchAll();

// 更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/edit.php?id=' . $id);
        exit;
    }

    $destinationUrl = trim($_POST['destination_url'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $redirectType = $_POST['redirect_type'] ?? 'jump';
    $titleBarText = trim($_POST['title_bar_text'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
    $memo = trim($_POST['memo'] ?? '');
    $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if (empty($destinationUrl) || !filter_var($destinationUrl, FILTER_VALIDATE_URL)) {
        $errors[] = '有効な転送先URLを入力してください。';
    }
    if (empty($slug) || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        $errors[] = 'スラッグは英数字、ハイフン、アンダースコアのみ使用できます。';
    } elseif (!isSlugAvailable($slug, $id)) {
        $errors[] = 'このスラッグは既に使用されています。';
    }

    if (empty($errors)) {
        if (empty($name)) {
            $name = $slug;
        }

        $stmt = $db->prepare(
            'UPDATE urls SET slug = ?, name = ?, destination_url = ?, redirect_type = ?, title_bar_text = ?,
             category_id = ?, group_id = ?, memo = ?, expires_at = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$slug, $name, $destinationUrl, $redirectType, $titleBarText,
            $categoryId, $groupId, $memo, $expiresAt, $isActive, $id]);

        AuditLog::log('update', 'url', $id, json_encode(['slug' => $slug]));
        setFlash('success', 'URLを更新しました。');
        header('Location: ' . BASE_PATH . '/admin/edit.php?id=' . $id);
        exit;
    } else {
        setFlash('danger', implode('<br>', $errors));
    }
}

// クリック統計
$stmt = $db->prepare('SELECT COUNT(*) FROM clicks WHERE url_id = ?');
$stmt->execute([$id]);
$clicksTotal = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM clicks WHERE url_id = ? AND is_unique = 1');
$stmt->execute([$id]);
$clicksUnique = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM conversions WHERE url_id = ?');
$stmt->execute([$id]);
$conversionsTotal = (int)$stmt->fetchColumn();

include __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2"></i>URL編集</h4>
    <a href="<?= BASE_PATH ?>/admin/manage.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>一覧に戻る
    </a>
</div>

<!-- 統計カード -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-primary"><i class="bi bi-cursor"></i></div>
            <div class="fs-4 fw-bold"><?= number_format($clicksTotal) ?></div>
            <div class="text-muted small">トータルクリック</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-success"><i class="bi bi-person-check"></i></div>
            <div class="fs-4 fw-bold"><?= number_format($clicksUnique) ?></div>
            <div class="text-muted small">ユニーククリック</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-warning"><i class="bi bi-trophy"></i></div>
            <div class="fs-4 fw-bold"><?= number_format($conversionsTotal) ?></div>
            <div class="text-muted small">成約数</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card text-center p-3">
            <div class="stat-icon text-info"><i class="bi bi-percent"></i></div>
            <div class="fs-4 fw-bold"><?= $clicksUnique > 0 ? round($conversionsTotal / $clicksUnique * 100, 1) : 0 ?>%</div>
            <div class="text-muted small">成約率</div>
        </div>
    </div>
</div>

<!-- 短縮URLリスト -->
<div class="card mb-4">
    <div class="card-header bg-light"><i class="bi bi-link-45deg me-1"></i>短縮URL</div>
    <div class="card-body">
        <?php
        $formats = ['default' => '', 'htm' => 'htm', 'html' => 'html', 'dir' => 'dir'];
        foreach ($formats as $label => $fmt):
            $shortUrl = buildShortUrl($url['slug'], $fmt);
        ?>
        <div class="input-group input-group-sm mb-1">
            <span class="input-group-text" style="width: 80px;"><?= h($label) ?></span>
            <input type="text" class="form-control" value="<?= h($shortUrl) ?>" readonly>
            <button class="btn btn-outline-secondary btn-copy" type="button" data-copy="<?= h($shortUrl) ?>">
                <i class="bi bi-clipboard"></i>
            </button>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 編集フォーム -->
<div class="card">
    <div class="card-body">
        <form method="post" action="">
            <?= Csrf::tokenField() ?>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="slug" class="form-label fw-bold">スラッグ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="slug" name="slug"
                           value="<?= h($url['slug']) ?>" required pattern="[a-zA-Z0-9_-]+">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="name" class="form-label fw-bold">名前</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= h($url['name']) ?>">
                </div>
            </div>

            <div class="mb-3">
                <label for="destination_url" class="form-label fw-bold">転送先URL <span class="text-danger">*</span></label>
                <input type="url" class="form-control" id="destination_url" name="destination_url"
                       value="<?= h($url['destination_url']) ?>" required>
            </div>

            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">転送方法</label>
                    <div class="d-flex gap-3 mt-1">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="redirect_type" value="jump"
                                   <?= $url['redirect_type'] === 'jump' ? 'checked' : '' ?>>
                            <label class="form-check-label">ジャンプ</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="redirect_type" value="preserve"
                                   <?= $url['redirect_type'] === 'preserve' ? 'checked' : '' ?>>
                            <label class="form-check-label">URL保持</label>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="category_id" class="form-label">カテゴリ</label>
                    <select class="form-select" name="category_id">
                        <option value="">（指定しない）</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $url['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="group_id" class="form-label">グループ</label>
                    <select class="form-select" name="group_id">
                        <option value="">（指定しない）</option>
                        <?php foreach ($groups as $grp): ?>
                            <option value="<?= $grp['id'] ?>" <?= $url['group_id'] == $grp['id'] ? 'selected' : '' ?>><?= h($grp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="expires_at" class="form-label">有効期限</label>
                    <input type="datetime-local" class="form-control" name="expires_at"
                           value="<?= $url['expires_at'] ? date('Y-m-d\TH:i', strtotime($url['expires_at'])) : '' ?>">
                </div>
            </div>

            <div class="mb-3">
                <label for="title_bar_text" class="form-label">タイトルバー文字（URL保持時）</label>
                <input type="text" class="form-control" name="title_bar_text" value="<?= h($url['title_bar_text']) ?>">
            </div>

            <div class="mb-3">
                <label for="memo" class="form-label">メモ</label>
                <textarea class="form-control" name="memo" rows="2"><?= h($url['memo']) ?></textarea>
            </div>

            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1"
                           <?= $url['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label">有効</label>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>更新
                </button>
                <a href="<?= BASE_PATH ?>/admin/analytics.php?url_id=<?= $id ?>" class="btn btn-outline-info">
                    <i class="bi bi-graph-up me-1"></i>解析を見る
                </a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>

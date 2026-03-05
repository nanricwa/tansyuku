<?php
/**
 * 紹介キャンペーン管理
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
$pageTitle = '紹介キャンペーン';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/referral_campaigns.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $destinationUrl = trim($_POST['destination_url'] ?? '');
        $passParams = isset($_POST['pass_params']) ? 1 : 0;
        $notifyOnCv = isset($_POST['notify_on_cv']) ? 1 : 0;
        $startsAt = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
        $endsAt = !empty($_POST['ends_at']) ? $_POST['ends_at'] : null;
        $memo = trim($_POST['memo'] ?? '');

        $errors = [];
        if (empty($name)) $errors[] = 'キャンペーン名を入力してください。';
        if (empty($slug) || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            $errors[] = 'スラッグは英数字・ハイフン・アンダースコアのみです。';
        }
        if (empty($destinationUrl) || !filter_var($destinationUrl, FILTER_VALIDATE_URL)) {
            $errors[] = '有効な転送先URLを入力してください。';
        }

        // スラッグ重複チェック
        if (empty($errors)) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM ref_campaigns WHERE slug = ?');
            $stmt->execute([$slug]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'このスラッグは既に使用されています。';
            }
        }

        if (empty($errors)) {
            $stmt = $db->prepare(
                'INSERT INTO ref_campaigns (name, slug, destination_url, pass_params, notify_on_cv, starts_at, ends_at, memo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $slug, $destinationUrl, $passParams, $notifyOnCv, $startsAt, $endsAt, $memo]);
            AuditLog::log('create', 'ref_campaign', (int)$db->lastInsertId());
            setFlash('success', 'キャンペーンを追加しました。');
        } else {
            setFlash('danger', implode('<br>', $errors));
        }

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $destinationUrl = trim($_POST['destination_url'] ?? '');
        $passParams = isset($_POST['pass_params']) ? 1 : 0;
        $notifyOnCv = isset($_POST['notify_on_cv']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $startsAt = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
        $endsAt = !empty($_POST['ends_at']) ? $_POST['ends_at'] : null;
        $memo = trim($_POST['memo'] ?? '');

        if ($id && !empty($name) && !empty($slug)) {
            // スラッグ重複チェック（自分以外）
            $stmt = $db->prepare('SELECT COUNT(*) FROM ref_campaigns WHERE slug = ? AND id != ?');
            $stmt->execute([$slug, $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('danger', 'このスラッグは既に使用されています。');
            } else {
                $stmt = $db->prepare(
                    'UPDATE ref_campaigns SET name=?, slug=?, destination_url=?, pass_params=?, notify_on_cv=?,
                     is_active=?, starts_at=?, ends_at=?, memo=? WHERE id=?'
                );
                $stmt->execute([$name, $slug, $destinationUrl, $passParams, $notifyOnCv, $isActive, $startsAt, $endsAt, $memo, $id]);
                AuditLog::log('update', 'ref_campaign', $id);
                setFlash('success', 'キャンペーンを更新しました。');
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM ref_campaigns WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'ref_campaign', $id);
            setFlash('success', 'キャンペーンを削除しました。');
        }
    }

    header('Location: ' . BASE_PATH . '/admin/referral_campaigns.php');
    exit;
}

// 一覧取得
$campaigns = $db->query('SELECT * FROM ref_campaigns ORDER BY created_at DESC')->fetchAll();

// 統計情報
$campaignStats = [];
foreach ($campaigns as $c) {
    $campaignStats[$c['id']] = Referral::getCampaignStats((int)$c['id']);
}

include __DIR__ . '/../templates/header.php';
$baseUrl = Database::getSetting('base_url', 'https://example.com/intro');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-megaphone me-2"></i>紹介キャンペーン</h4>
</div>

<!-- 追加フォーム -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <a data-bs-toggle="collapse" href="#addCampaignForm" class="text-decoration-none">
            <i class="bi bi-plus-circle me-1"></i>キャンペーンを追加
        </a>
    </div>
    <div class="collapse" id="addCampaignForm">
        <div class="card-body">
            <form method="post">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="add">

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">キャンペーン名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="2026年前期セミナー">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">スラッグ <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">/r/</span>
                            <input type="text" class="form-control" name="slug" required placeholder="fjbA1"
                                   pattern="[a-zA-Z0-9_-]+">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">転送先URL <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" name="destination_url" required
                               placeholder="https://online-build.com/2026/sem1/fjbA1/">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">開始日時</label>
                        <input type="datetime-local" class="form-control" name="starts_at">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">終了日時</label>
                        <input type="datetime-local" class="form-control" name="ends_at">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="pass_params" value="1" checked>
                            <label class="form-check-label small">パラメータ渡し</label>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_on_cv" value="1">
                            <label class="form-check-label small">成約通知メール</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">メモ</label>
                        <input type="text" class="form-control" name="memo">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-plus me-1"></i>追加</button>
            </form>
        </div>
    </div>
</div>

<!-- キャンペーン一覧 -->
<?php if (empty($campaigns)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-megaphone fs-1 d-block mb-2"></i>
        キャンペーンがありません。上の「キャンペーンを追加」から始めましょう。
    </div>
<?php endif; ?>

<?php foreach ($campaigns as $c):
    $stats = $campaignStats[$c['id']];
    $isExpired = $c['ends_at'] && strtotime($c['ends_at']) < time();
    $refUrl = $baseUrl . '/r/' . $c['slug'];
?>
<div class="card mb-3 <?= !$c['is_active'] ? 'border-secondary opacity-75' : ($isExpired ? 'border-warning' : '') ?>">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <strong><?= h($c['name']) ?></strong>
            <?php if (!$c['is_active']): ?>
                <span class="badge bg-secondary ms-1">無効</span>
            <?php elseif ($isExpired): ?>
                <span class="badge bg-warning text-dark ms-1">終了</span>
            <?php else: ?>
                <span class="badge bg-success ms-1">有効</span>
            <?php endif; ?>
        </span>
        <div>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse"
                    data-bs-target="#editCampaign<?= $c['id'] ?>">
                <i class="bi bi-pencil"></i>
            </button>
            <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？関連する訪問データも削除されます。');">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-2">
            <div class="col-md-6">
                <small class="text-muted">紹介URL（ベース）:</small>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control font-monospace" value="<?= h($refUrl) ?>?intro=CODE" readonly>
                    <button class="btn btn-outline-secondary btn-copy" data-copy="<?= h($refUrl) ?>?intro=">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-6">
                <small class="text-muted">転送先:</small>
                <div class="small text-truncate">
                    <a href="<?= h($c['destination_url']) ?>" target="_blank"><?= h($c['destination_url']) ?></a>
                </div>
                <?php if ($c['starts_at'] || $c['ends_at']): ?>
                <small class="text-muted">
                    期間: <?= $c['starts_at'] ? date('Y/m/d', strtotime($c['starts_at'])) : '—' ?>
                    ～ <?= $c['ends_at'] ? date('Y/m/d', strtotime($c['ends_at'])) : '—' ?>
                </small>
                <?php endif; ?>
            </div>
        </div>

        <!-- 統計 -->
        <div class="row g-2 mt-2">
            <div class="col-3">
                <div class="border rounded text-center p-2">
                    <div class="fs-5 fw-bold"><?= number_format($stats['total_visits']) ?></div>
                    <div class="text-muted small">総アクセス</div>
                </div>
            </div>
            <div class="col-3">
                <div class="border rounded text-center p-2">
                    <div class="fs-5 fw-bold"><?= number_format($stats['unique_visits']) ?></div>
                    <div class="text-muted small">ユニーク</div>
                </div>
            </div>
            <div class="col-3">
                <div class="border rounded text-center p-2">
                    <div class="fs-5 fw-bold"><?= number_format($stats['conversions']) ?></div>
                    <div class="text-muted small">成約</div>
                </div>
            </div>
            <div class="col-3">
                <div class="border rounded text-center p-2">
                    <div class="fs-5 fw-bold"><?= $stats['cv_rate'] ?>%</div>
                    <div class="text-muted small">CV率</div>
                </div>
            </div>
        </div>

        <!-- CVタグ -->
        <div class="mt-2">
            <small class="text-muted">CVタグ（成約ページに設置）:</small>
            <?php $cvTag = '<img src="' . $baseUrl . '/ref_cv.php?campaign=' . h($c['slug']) . '" width="1" height="1" style="display:none" alt="">'; ?>
            <div class="input-group input-group-sm">
                <input type="text" class="form-control font-monospace small" value="<?= h($cvTag) ?>" readonly>
                <button class="btn btn-outline-warning btn-copy" data-copy="<?= h($cvTag) ?>">
                    <i class="bi bi-clipboard"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- 編集フォーム -->
    <div class="collapse" id="editCampaign<?= $c['id'] ?>">
        <div class="card-body border-top">
            <form method="post">
                <?= Csrf::tokenField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">キャンペーン名</label>
                        <input type="text" class="form-control" name="name" value="<?= h($c['name']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">スラッグ</label>
                        <input type="text" class="form-control" name="slug" value="<?= h($c['slug']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">転送先URL</label>
                        <input type="url" class="form-control" name="destination_url"
                               value="<?= h($c['destination_url']) ?>" required>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">開始日時</label>
                        <input type="datetime-local" class="form-control" name="starts_at"
                               value="<?= $c['starts_at'] ? date('Y-m-d\TH:i', strtotime($c['starts_at'])) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">終了日時</label>
                        <input type="datetime-local" class="form-control" name="ends_at"
                               value="<?= $c['ends_at'] ? date('Y-m-d\TH:i', strtotime($c['ends_at'])) : '' ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="pass_params" value="1"
                                   <?= $c['pass_params'] ? 'checked' : '' ?>>
                            <label class="form-check-label small">パラメータ渡し</label>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_on_cv" value="1"
                                   <?= ($c['notify_on_cv'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label small">成約通知メール</label>
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   <?= $c['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label small">有効</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">メモ</label>
                        <input type="text" class="form-control" name="memo" value="<?= h($c['memo']) ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check me-1"></i>更新</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

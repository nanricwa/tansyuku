<?php
/**
 * 紹介リンク生成ツール
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/referral.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = 'リンク生成';

$campaigns = $db->query('SELECT * FROM ref_campaigns WHERE is_active = 1 ORDER BY name')->fetchAll();
$members = $db->query('SELECT * FROM ref_members WHERE is_active = 1 ORDER BY name')->fetchAll();

$generatedLinks = [];
$matchCode = '';
$selectedCampaignId = '';
$selectedMemberIds = [];
$bulkMode = false;

// リンク生成処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/referral_links.php');
        exit;
    }

    $selectedCampaignId = (int)($_POST['campaign_id'] ?? 0);
    $matchCode = trim($_POST['match_code'] ?? '');
    $selectedMemberIds = $_POST['member_ids'] ?? [];
    $bulkMode = !empty($_POST['bulk_all']);

    // キャンペーン取得
    $stmt = $db->prepare('SELECT * FROM ref_campaigns WHERE id = ?');
    $stmt->execute([$selectedCampaignId]);
    $campaign = $stmt->fetch();

    if ($campaign) {
        $targetMembers = [];
        if ($bulkMode) {
            // 全員分
            $targetMembers = $db->query('SELECT * FROM ref_members WHERE is_active = 1 ORDER BY name')->fetchAll();
        } else {
            // 選択されたメンバー
            if (!empty($selectedMemberIds)) {
                $placeholders = str_repeat('?,', count($selectedMemberIds) - 1) . '?';
                $stmt = $db->prepare("SELECT * FROM ref_members WHERE id IN ({$placeholders}) ORDER BY name");
                $stmt->execute($selectedMemberIds);
                $targetMembers = $stmt->fetchAll();
            }
        }

        foreach ($targetMembers as $member) {
            $url = Referral::buildReferralUrl($campaign['slug'], $member['code'], $matchCode);
            $generatedLinks[] = [
                'member_name' => $member['name'],
                'member_code' => $member['code'],
                'member_email' => $member['email'],
                'url' => $url,
            ];
        }
    }
}

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-link-45deg me-2"></i>紹介リンク生成</h4>

<!-- 生成フォーム -->
<div class="card mb-4">
    <div class="card-body">
        <form method="post">
            <?= Csrf::tokenField() ?>

            <div class="row mb-3">
                <div class="col-md-5">
                    <label class="form-label fw-bold">キャンペーン <span class="text-danger">*</span></label>
                    <select class="form-select" name="campaign_id" required>
                        <option value="">選択してください</option>
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $selectedCampaignId == $c['id'] ? 'selected' : '' ?>>
                                <?= h($c['name']) ?>（/r/<?= h($c['slug']) ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">matchコード</label>
                    <input type="text" class="form-control" name="match_code" value="<?= h($matchCode) ?>"
                           placeholder="例: 202603">
                    <div class="form-text">キャンペーンの回次や期間の識別子</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">対象紹介者</label>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="bulk_all" value="1" id="bulkAll"
                               <?= $bulkMode ? 'checked' : '' ?>
                               onchange="document.getElementById('memberSelect').style.display = this.checked ? 'none' : 'block';">
                        <label class="form-check-label" for="bulkAll">全員分を生成</label>
                    </div>
                    <select class="form-select form-select-sm" name="member_ids[]" multiple size="4"
                            id="memberSelect" style="<?= $bulkMode ? 'display:none' : '' ?>">
                        <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= in_array($m['id'], $selectedMemberIds) ? 'selected' : '' ?>>
                                <?= h($m['name']) ?>（<?= h($m['code']) ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Ctrl/Cmdクリックで複数選択</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-lightning me-1"></i>リンク生成
            </button>
        </form>
    </div>
</div>

<!-- 生成結果 -->
<?php if (!empty($generatedLinks)): ?>
<div class="card mb-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-check-circle me-1"></i><?= count($generatedLinks) ?>件のリンクを生成しました</span>
        <button class="btn btn-sm btn-light" onclick="copyAllLinks()">
            <i class="bi bi-clipboard me-1"></i>全リンクをコピー
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>紹介者</th>
                        <th>コード</th>
                        <th>メール</th>
                        <th>紹介リンク</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($generatedLinks as $link): ?>
                    <tr>
                        <td><?= h($link['member_name']) ?></td>
                        <td><code><?= h($link['member_code']) ?></code></td>
                        <td class="small"><?= safeEmail($link['member_email']) ?></td>
                        <td>
                            <input type="text" class="form-control form-control-sm font-monospace generated-link"
                                   value="<?= h($link['url']) ?>" readonly>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary btn-copy" data-copy="<?= h($link['url']) ?>">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- CSV出力用 -->
<div class="card">
    <div class="card-header bg-light">
        <i class="bi bi-filetype-csv me-1"></i>CSV形式（コピーしてExcel等に貼り付け）
    </div>
    <div class="card-body">
        <textarea class="form-control font-monospace small" rows="6" readonly id="csvOutput"><?php
foreach ($generatedLinks as $link) {
    echo h($link['member_name']) . "\t" . h($link['member_code']) . "\t" . safeEmail($link['member_email']) . "\t" . h($link['url']) . "\n";
}
?></textarea>
        <button class="btn btn-sm btn-outline-secondary mt-2" onclick="navigator.clipboard.writeText(document.getElementById('csvOutput').value)">
            <i class="bi bi-clipboard me-1"></i>CSVをコピー
        </button>
    </div>
</div>

<script>
function copyAllLinks() {
    var links = document.querySelectorAll('.generated-link');
    var text = Array.from(links).map(function(el) { return el.value; }).join('\n');
    navigator.clipboard.writeText(text).then(function() {
        alert(links.length + '件のリンクをクリップボードにコピーしました。');
    });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>

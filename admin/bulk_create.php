<?php
/**
 * 一括URL作成
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

Auth::requireLogin();

$db = Database::getConnection();
$pageTitle = '一括URL作成';

$categories = $db->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll();
$groups = $db->query('SELECT * FROM `groups` ORDER BY name')->fetchAll();

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validateToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', '不正なリクエストです。');
        header('Location: ' . BASE_PATH . '/admin/bulk_create.php');
        exit;
    }

    $inputMode = $_POST['input_mode'] ?? 'text';
    $redirectType = $_POST['redirect_type'] ?? 'jump';
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;

    $lines = [];

    if ($inputMode === 'csv' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        // CSVファイルから読み込み
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($handle) {
            // ヘッダー行スキップ
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $lines[] = [
                    'url' => trim($row[0] ?? ''),
                    'name' => trim($row[1] ?? ''),
                    'slug' => trim($row[2] ?? ''),
                ];
            }
            fclose($handle);
        }
    } else {
        // テキスト入力（1行1URL、タブ区切りで名前・スラッグ指定可能）
        $text = $_POST['urls_text'] ?? '';
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = preg_split('/[\t,]/', $line);
            $lines[] = [
                'url' => trim($parts[0] ?? ''),
                'name' => trim($parts[1] ?? ''),
                'slug' => trim($parts[2] ?? ''),
            ];
        }
    }

    foreach ($lines as $entry) {
        $url = $entry['url'];
        $name = $entry['name'];
        $slug = $entry['slug'];

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $results[] = ['url' => $url, 'status' => 'error', 'message' => '無効なURL'];
            continue;
        }

        // スラッグ生成
        if (empty($slug)) {
            $slug = generateRandomSlug(8);
        } elseif (!isSlugAvailable($slug)) {
            $results[] = ['url' => $url, 'status' => 'error', 'message' => 'スラッグ「' . $slug . '」は使用済み'];
            continue;
        }

        if (empty($name)) {
            $name = $slug;
        }

        $stmt = $db->prepare(
            'INSERT INTO urls (user_id, slug, name, destination_url, redirect_type, category_id, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([Auth::userId(), $slug, $name, $url, $redirectType, $categoryId, $groupId]);
        $newId = (int)$db->lastInsertId();
        AuditLog::log('create', 'url', $newId);

        $results[] = [
            'url' => $url,
            'slug' => $slug,
            'short_url' => buildShortUrl($slug, 'html'),
            'status' => 'success',
            'message' => '作成完了',
        ];
    }

    if (!empty($results)) {
        $successCount = count(array_filter($results, fn($r) => $r['status'] === 'success'));
        setFlash('success', "{$successCount}件のURLを作成しました。");
    }
}

include __DIR__ . '/../templates/header.php';
?>

<h4 class="mb-4"><i class="bi bi-collection me-2"></i>一括URL作成</h4>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= Csrf::tokenField() ?>

            <!-- 入力モード -->
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-bs-toggle="tab" href="#textInput">テキスト入力</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#csvInput">CSVアップロード</a>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="textInput">
                    <input type="hidden" name="input_mode" value="text" id="inputMode">
                    <div class="mb-3">
                        <label class="form-label">URL一覧（1行1URL、カンマ/タブ区切りで名前・スラッグ指定可）</label>
                        <textarea class="form-control" name="urls_text" rows="10"
                                  placeholder="https://example.com/page1&#10;https://example.com/page2,名前,my-slug&#10;https://example.com/page3"></textarea>
                        <div class="form-text">形式: URL, 名前(任意), スラッグ(任意)</div>
                    </div>
                </div>
                <div class="tab-pane fade" id="csvInput">
                    <div class="mb-3">
                        <label class="form-label">CSVファイル</label>
                        <input type="file" class="form-control" name="csv_file" accept=".csv">
                        <div class="form-text">ヘッダー行: URL, 名前, スラッグ</div>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-4 mb-3">
                    <label class="form-label">転送方法</label>
                    <select class="form-select" name="redirect_type">
                        <option value="jump">ジャンプ</option>
                        <option value="preserve">URL保持</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">カテゴリ</label>
                    <select class="form-select" name="category_id">
                        <option value="">（指定しない）</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">グループ</label>
                    <select class="form-select" name="group_id">
                        <option value="">（指定しない）</option>
                        <?php foreach ($groups as $grp): ?>
                            <option value="<?= $grp['id'] ?>"><?= h($grp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-collection me-1"></i>一括作成
            </button>
        </form>
    </div>
</div>

<?php if (!empty($results)): ?>
<div class="card">
    <div class="card-header bg-light">作成結果</div>
    <div class="card-body">
        <table class="table table-sm">
            <thead>
                <tr><th>ステータス</th><th>転送先URL</th><th>短縮URL</th><th>メッセージ</th></tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td>
                        <?php if ($r['status'] === 'success'): ?>
                            <span class="badge bg-success">OK</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Error</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($r['url']) ?></td>
                    <td>
                        <?php if (isset($r['short_url'])): ?>
                            <code><?= h($r['short_url']) ?></code>
                            <button class="btn btn-copy btn-sm" data-copy="<?= h($r['short_url']) ?>"><i class="bi bi-clipboard"></i></button>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($r['message']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(tab) {
    tab.addEventListener('shown.bs.tab', function(e) {
        document.getElementById('inputMode').value = e.target.getAttribute('href') === '#csvInput' ? 'csv' : 'text';
    });
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

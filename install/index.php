<?php
/**
 * インストーラー
 * 初回セットアップ: DB作成、管理者アカウント設定
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Tokyo');

$step = $_GET['step'] ?? '1';
$error = '';
$success = '';

// Step 2: DB設定確認 → テーブル作成 → 管理者作成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $baseUrl = trim($_POST['base_url'] ?? '');
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';

    // バリデーション
    if (empty($dbName) || empty($dbUser) || empty($baseUrl) || empty($adminUser) || empty($adminPass)) {
        $error = '全ての必須項目を入力してください。';
    } elseif (strlen($adminPass) < 8) {
        $error = '管理者パスワードは8文字以上にしてください。';
    } else {
        try {
            // DB接続（Xserver等では事前にDB作成済みのため、直接接続）
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // テーブル作成（1行ずつ読み、コメント除去してSQL文を組み立て）
            $lines = file(__DIR__ . '/schema.sql', FILE_IGNORE_NEW_LINES);
            $sql = '';
            foreach ($lines as $line) {
                $trimmed = trim($line);
                // 空行・コメント行をスキップ
                if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                    continue;
                }
                $sql .= $line . "\n";
                // セミコロンで終わったら実行
                if (substr($trimmed, -1) === ';') {
                    $sql = trim($sql);
                    // CREATE DATABASE / USE / INSERT INTO users は除外
                    if (!preg_match('/^(CREATE DATABASE|USE )/i', $sql)) {
                        $pdo->exec($sql);
                    }
                    $sql = '';
                }
            }

            // 管理者アカウント作成
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([$adminUser, $adminEmail, $hash, 'admin']);

            // ベースURL設定
            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'base_url'");
            $stmt->execute([$baseUrl]);

            // config.php を更新
            $configContent = "<?php
/**
 * 短縮URLツール 設定ファイル（自動生成）
 */

define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

define('DB_HOST', " . var_export($dbHost, true) . ");
define('DB_NAME', " . var_export($dbName, true) . ");
define('DB_USER', " . var_export($dbUser, true) . ");
define('DB_PASS', " . var_export($dbPass, true) . ");
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'URL Shortener');
define('APP_VERSION', '1.0.0');
define('BASE_PATH', " . var_export(parse_url($baseUrl, PHP_URL_PATH) ?: '/url', true) . ");

define('SESSION_LIFETIME', 3600);
define('SESSION_NAME', 'urlshortener_session');

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');
";
            file_put_contents(__DIR__ . '/../config.php', $configContent);

            $success = 'インストールが完了しました！';
            $step = '3';
        } catch (PDOException $e) {
            $error = 'データベースエラー: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = 'エラー: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>インストール - URL Shortener</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .install-container { max-width: 640px; margin: 40px auto; }
        .install-card { border: none; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="install-container">
    <div class="card install-card">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-link-45deg fs-1 text-primary"></i>
                <h3>URL Shortener</h3>
                <p class="text-muted">初回セットアップ</p>
            </div>

            <!-- ステップ表示 -->
            <div class="d-flex justify-content-center mb-4">
                <span class="badge bg-<?= $step >= '1' ? 'primary' : 'secondary' ?> me-2 px-3 py-2">1. 設定入力</span>
                <span class="badge bg-<?= $step >= '2' ? 'primary' : 'secondary' ?> me-2 px-3 py-2">2. インストール</span>
                <span class="badge bg-<?= $step >= '3' ? 'success' : 'secondary' ?> px-3 py-2">3. 完了</span>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($step === '1' || ($step === '2' && $error)): ?>
            <form method="post" action="?step=2">
                <h5 class="mb-3"><i class="bi bi-database me-1"></i>データベース設定</h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">ホスト</label>
                        <input type="text" class="form-control" name="db_host"
                               value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">データベース名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="db_name"
                               value="<?= htmlspecialchars($_POST['db_name'] ?? 'ctwasia2_tansyuku') ?>" required>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">ユーザー名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="db_user"
                               value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">パスワード</label>
                        <input type="password" class="form-control" name="db_pass"
                               value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                    </div>
                </div>

                <hr class="my-4">

                <h5 class="mb-3"><i class="bi bi-globe me-1"></i>サイト設定</h5>
                <div class="mb-3">
                    <label class="form-label">ベースURL <span class="text-danger">*</span></label>
                    <input type="url" class="form-control" name="base_url"
                           value="<?= htmlspecialchars($_POST['base_url'] ?? 'https://ycscampaign.com/intro') ?>"
                           placeholder="https://example.com/url" required>
                    <div class="form-text">短縮URLのベースとなるURL（末尾スラッシュなし）</div>
                </div>

                <hr class="my-4">

                <h5 class="mb-3"><i class="bi bi-person-circle me-1"></i>管理者アカウント</h5>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">ユーザー名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="admin_user"
                               value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">メールアドレス</label>
                        <input type="email" class="form-control" name="admin_email"
                               value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">パスワード <span class="text-danger">*</span>（8文字以上）</label>
                    <input type="password" class="form-control" name="admin_pass" required minlength="8">
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 mt-3">
                    <i class="bi bi-download me-1"></i>インストール実行
                </button>
            </form>
            <?php endif; ?>

            <?php if ($step === '3'): ?>
            <div class="text-center">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
                <p>セキュリティのため、<code>install/</code> ディレクトリを削除またはアクセス制限してください。</p>
                <a href="../admin/index.php" class="btn btn-primary btn-lg">
                    <i class="bi bi-box-arrow-in-right me-1"></i>管理画面へ
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

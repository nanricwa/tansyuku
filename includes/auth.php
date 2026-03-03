<?php
/**
 * 認証・セッション管理
 */

require_once __DIR__ . '/db.php';

class Auth
{
    /**
     * セッション開始
     */
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
        // セッションタイムアウトチェック
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
            self::logout();
        }
        $_SESSION['last_activity'] = time();
    }

    /**
     * ログイン処理
     * @return array ['success' => bool, 'message' => string, 'user' => ?array]
     */
    public static function login(string $username, string $password): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'ユーザー名またはパスワードが違います。', 'user' => null];
        }

        // アカウントロックチェック
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'message' => "アカウントがロックされています。{$remaining}分後にお試しください。", 'user' => null];
        }

        if (!password_verify($password, $user['password_hash'])) {
            // ログイン失敗回数を増加
            $attempts = $user['login_attempts'] + 1;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
                $stmt = $db->prepare('UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?');
                $stmt->execute([$attempts, $lockUntil, $user['id']]);
                return ['success' => false, 'message' => 'ログイン試行回数が上限を超えました。しばらくお待ちください。', 'user' => null];
            }
            $stmt = $db->prepare('UPDATE users SET login_attempts = ? WHERE id = ?');
            $stmt->execute([$attempts, $user['id']]);
            return ['success' => false, 'message' => 'ユーザー名またはパスワードが違います。', 'user' => null];
        }

        // ログイン成功: カウンタリセット、セッション設定
        $stmt = $db->prepare('UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?');
        $stmt->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();

        return ['success' => true, 'message' => '', 'user' => $user];
    }

    /**
     * ログアウト
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * ログインチェック（未ログインならリダイレクト）
     */
    public static function requireLogin(): void
    {
        self::init();
        if (!self::isLoggedIn()) {
            header('Location: ' . BASE_PATH . '/admin/index.php');
            exit;
        }
    }

    /**
     * 管理者権限チェック
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if ($_SESSION['role'] !== 'admin') {
            http_response_code(403);
            echo '権限がありません。';
            exit;
        }
    }

    /**
     * ログイン中か
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * 管理者か
     */
    public static function isAdmin(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    /**
     * 現在のユーザーID
     */
    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * 現在のユーザー名
     */
    public static function username(): string
    {
        return $_SESSION['username'] ?? '';
    }
}

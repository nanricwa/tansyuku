<?php
/**
 * CSRFトークン管理
 */

class Csrf
{
    /**
     * トークンを生成してセッションに保存
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * トークンを検証
     */
    public static function validateToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        $valid = hash_equals($_SESSION['csrf_token'], $token);
        // 使い捨て: 検証後に再生成
        unset($_SESSION['csrf_token']);
        return $valid;
    }

    /**
     * hidden inputタグを出力
     */
    public static function tokenField(): string
    {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

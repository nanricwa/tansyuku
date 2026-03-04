<?php
/**
 * CSRFトークン管理
 *
 * セッションごとに1つのトークンを維持する方式。
 * 1ページに複数フォームがあっても同じトークンを共有するため、
 * トークンの上書き問題が発生しない。
 */

class Csrf
{
    /**
     * トークンを取得（なければ生成）
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * トークンを再生成（ログイン後やセキュリティ上の理由で強制再生成する場合）
     */
    public static function regenerateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    /**
     * トークンを検証
     * ※ トークンは使い捨てにせず、セッション中は再利用する
     */
    public static function validateToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * hidden inputタグを出力
     */
    public static function tokenField(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * 後方互換: generateToken() は getToken() のエイリアス
     */
    public static function generateToken(): string
    {
        return self::getToken();
    }
}

<?php
/**
 * 操作ログ記録
 */

require_once __DIR__ . '/db.php';

class AuditLog
{
    /**
     * ログを記録
     */
    public static function log(string $action, string $targetType, ?int $targetId = null, ?string $details = null): void
    {
        $db = Database::getConnection();
        $userId = $_SESSION['user_id'] ?? null;

        $stmt = $db->prepare(
            'INSERT INTO audit_logs (user_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $action, $targetType, $targetId, $details]);
    }
}

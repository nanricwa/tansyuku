<?php
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<div class="bg-dark text-white" id="sidebar-wrapper" style="min-width: 220px; max-width: 220px; min-height: 100vh;">
    <div class="sidebar-heading p-3 fs-5 fw-bold border-bottom border-secondary">
        <i class="bi bi-link-45deg"></i> <?= h(APP_NAME) ?>
    </div>
    <div class="list-group list-group-flush">
        <a href="<?= BASE_PATH ?>/admin/dashboard.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2 me-2"></i>ダッシュボード
        </a>

        <!-- 短縮URL -->
        <div class="px-3 pt-3 pb-1 text-secondary small text-uppercase fw-bold">短縮URL</div>
        <a href="<?= BASE_PATH ?>/admin/create.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'create' ? 'active' : '' ?>">
            <i class="bi bi-plus-circle me-2"></i>URL作成
        </a>
        <a href="<?= BASE_PATH ?>/admin/manage.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'manage' ? 'active' : '' ?>">
            <i class="bi bi-table me-2"></i>管理
        </a>
        <a href="<?= BASE_PATH ?>/admin/bulk_create.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'bulk_create' ? 'active' : '' ?>">
            <i class="bi bi-collection me-2"></i>一括作成
        </a>
        <a href="<?= BASE_PATH ?>/admin/analytics.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'analytics' ? 'active' : '' ?>">
            <i class="bi bi-graph-up me-2"></i>解析
        </a>
        <a href="<?= BASE_PATH ?>/admin/groups.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'groups' ? 'active' : '' ?>">
            <i class="bi bi-diagram-3 me-2"></i>グループ
        </a>
        <a href="<?= BASE_PATH ?>/admin/categories.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'categories' ? 'active' : '' ?>">
            <i class="bi bi-tags me-2"></i>カテゴリ
        </a>

        <!-- 紹介 -->
        <div class="px-3 pt-3 pb-1 text-secondary small text-uppercase fw-bold">紹介</div>
        <a href="<?= BASE_PATH ?>/admin/referral_campaigns.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'referral_campaigns' ? 'active' : '' ?>">
            <i class="bi bi-megaphone me-2"></i>キャンペーン
        </a>
        <a href="<?= BASE_PATH ?>/admin/referral_members.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'referral_members' ? 'active' : '' ?>">
            <i class="bi bi-people me-2"></i>紹介者
        </a>
        <a href="<?= BASE_PATH ?>/admin/referral_links.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'referral_links' ? 'active' : '' ?>">
            <i class="bi bi-link-45deg me-2"></i>リンク生成
        </a>
        <a href="<?= BASE_PATH ?>/admin/referral_analytics.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'referral_analytics' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart me-2"></i>紹介分析
        </a>

        <!-- システム -->
        <div class="px-3 pt-3 pb-1 text-secondary small text-uppercase fw-bold">システム</div>
        <?php if (Auth::isAdmin()): ?>
        <a href="<?= BASE_PATH ?>/admin/users.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'users' ? 'active' : '' ?>">
            <i class="bi bi-person-gear me-2"></i>ユーザー管理
        </a>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/admin/export.php?type=urls"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary">
            <i class="bi bi-download me-2"></i>CSVエクスポート
        </a>
        <a href="<?= BASE_PATH ?>/admin/settings.php"
           class="list-group-item list-group-item-action bg-dark text-white border-secondary <?= $currentPage === 'settings' ? 'active' : '' ?>">
            <i class="bi bi-gear me-2"></i>設定
        </a>
    </div>
</div>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'URL Shortener') ?> - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="d-flex" id="wrapper">
    <?php include __DIR__ . '/nav.php'; ?>
    <div id="page-content-wrapper" class="w-100">
        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3">
            <button class="btn btn-sm btn-outline-secondary" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            <div class="ms-auto d-flex align-items-center">
                <span class="text-muted me-3">
                    <i class="bi bi-person-circle"></i> <?= h(Auth::username()) ?>
                    <span class="badge bg-<?= Auth::isAdmin() ? 'danger' : 'secondary' ?> ms-1"><?= Auth::isAdmin() ? 'Admin' : 'User' ?></span>
                </span>
                <a href="<?= BASE_PATH ?>/admin/index.php?action=logout" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> ログアウト
                </a>
            </div>
        </nav>
        <div class="container-fluid p-4">
            <?php
            $flash = getFlash();
            if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= h($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

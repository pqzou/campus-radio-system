<?php
$pageTitle = $pageTitle ?? '广播室音乐点播系统';
$activeNav = $activeNav ?? '';
$bodyClass = $bodyClass ?? '';
$flashes   = pull_flashes();
$navItems  = [
    'home'     => ['index.php', '首页'],
    'upload'   => ['upload.php', '班级上传'],
    'player'   => ['player.php', '大屏播放与审核'],
];
if (admin_is_logged_in()) {
    $navItems['activities'] = ['activities.php', '活动管理'];
    $navItems['articles'] = ['articles.php', '文章管理'];
    $navItems['logs'] = ['audit-logs.php', '审计日志'];
}
$navItems['settings'] = ['settings.php', '系统设置'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <title><?= h($pageTitle) ?> - <?= h(site_text('header_title')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=34">
    <script src="assets/js/fingerprint.js"></script>
</head>
<body class="<?= h($bodyClass) ?>">
<header class="site-header">
    <div class="container nav-wrap">
        <a class="brand" href="index.php">
            <img class="school-logo" src="assets/img/school-logo.png" alt="学校校徽"
                 onerror="this.onerror=null;this.src='assets/img/school-logo-placeholder.svg';">
            <span class="brand-text">
                <strong><?= h(site_text('header_title')) ?></strong>
                <small><?= h(site_text('header_subtitle')) ?></small>
            </span>
        </a>
        <nav class="main-nav" aria-label="主导航">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?= h($item[0]) ?>"
                   class="<?= $activeNav === $key ? 'is-active' : '' ?>">
                    <?= h($item[1]) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>

<main class="page">
    <div class="container">
        <?php if ($flashes): ?>
            <div class="flash-list">
                <?php foreach ($flashes as $item): ?>
                    <div class="flash flash-<?= h($item['type'] ?? 'info') ?>" role="status">
                        <span><?= h($item['message'] ?? '') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

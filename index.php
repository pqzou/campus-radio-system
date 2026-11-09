<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db = db();

$articles = $db->query(
    "SELECT `id`, `title`, `summary`, `cover_url`, `content`, `published_at`, `created_at`
     FROM `articles`
     WHERE `is_published` = 1
     ORDER BY `published_at` DESC, `id` DESC"
)->fetchAll();

$pageTitle = '首页';
$activeNav = 'home';
require __DIR__ . '/includes/header.php';
?>

<section class="home-hero">
    <div class="home-hero-copy">
        <h1><?= h(site_text('header_title')) ?></h1>
        <p><?= h(site_text('header_subtitle')) ?></p>
    </div>
    <div class="home-hero-actions">
        <a class="btn btn-primary" href="upload.php">班级点歌</a>
        <a class="btn btn-secondary" href="special.php">特殊个人点歌</a>
    </div>
</section>

<section class="panel article-panel">
    <div class="panel-head panel-head-row">
        <h2>广播站文章</h2>
        <?php if (admin_is_logged_in()): ?>
            <a class="btn btn-secondary btn-sm" href="articles.php?edit=new">写文章</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <?php if (!$articles): ?>
            <div class="empty-state">
                <h3>暂无文章</h3>
            </div>
        <?php else: ?>
            <div class="article-feed">
                <?php foreach ($articles as $article): ?>
                    <?php
                    $summary = trim((string) $article['summary']);
                    if ($summary === '') {
                        $summary = text_excerpt((string) $article['content']);
                    }
                    $cover = trim((string) $article['cover_url']);
                    $date  = substr((string) ($article['published_at'] ?? $article['created_at']), 0, 10);
                    ?>
                    <a class="article-card" href="article.php?id=<?= (int) $article['id'] ?>">
                        <div class="article-card-body">
                            <h3><?= h($article['title']) ?></h3>
                            <?php if ($summary !== ''): ?>
                                <p><?= h($summary) ?></p>
                            <?php endif; ?>
                            <time datetime="<?= h($date) ?>"><?= h($date) ?></time>
                        </div>
                        <?php if ($cover !== ''): ?>
                            <img class="article-thumb" src="<?= h($cover) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="article-thumb is-empty" aria-hidden="true"></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

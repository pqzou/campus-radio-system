<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare(
    'SELECT * FROM `articles` WHERE `id` = ? AND `is_published` = 1 LIMIT 1'
);
$stmt->execute([$id]);
$article = $stmt->fetch();

if ($article === false) {
    flash('error', '文章不存在或尚未发布。');
    redirect('index.php');
}

$cover = trim((string) $article['cover_url']);
$date  = substr((string) ($article['published_at'] ?? $article['created_at']), 0, 10);
$format = (string) ($article['content_format'] ?? 'text');

$pageTitle = (string) $article['title'];
$activeNav = 'home';
require __DIR__ . '/includes/header.php';
?>

<a class="back-link" href="index.php">← 返回首页</a>

<article class="panel article-detail">
    <div class="article-detail-head">
        <h1><?= h($article['title']) ?></h1>
        <div class="article-detail-meta">
            <time datetime="<?= h($date) ?>"><?= h($date) ?></time>
            <?php if (admin_is_logged_in()): ?>
                <a href="articles.php?edit=<?= (int) $article['id'] ?>">编辑</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($cover !== ''): ?>
        <img class="article-cover-full" src="<?= h($cover) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <div class="article-content <?= $format === 'html' ? 'is-rich' : '' ?>"><?= render_article_content($db, (string) $article['content'], (int) $article['id'], $format) ?></div>
</article>

<?php require __DIR__ . '/includes/footer.php'; ?>

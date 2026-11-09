<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (!admin_is_logged_in()) {
    flash('error', '请先登录管理员账号。');
    redirect('settings.php');
}

$db = db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM `activity_submissions` WHERE `id` = ? LIMIT 1');
$stmt->execute([$id]);
$entry = $stmt->fetch();

if ($entry === false) {
    flash('error', '未找到该报名文件。');
    redirect('activities.php');
}

$activityId = (int) $entry['activity_id'];
$fileName   = (string) $entry['original_name'];
$extension  = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
$mimeType   = trim((string) $entry['mime_type']);
$fullPath   = __DIR__ . '/' . (string) $entry['file_path'];

$videoExtensions = ['mp4', 'm4v', 'mov', 'webm', 'ogv'];
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

$isVideo = strpos($mimeType, 'video/') === 0 || in_array($extension, $videoExtensions, true);
$isImage = strpos($mimeType, 'image/') === 0 || in_array($extension, $imageExtensions, true);
$isPdf   = $mimeType === 'application/pdf' || $extension === 'pdf';
$isText  = strpos($mimeType, 'text/') === 0 || in_array($extension, ['txt', 'csv', 'log'], true);

$previewUrl  = 'activity-file.php?id=' . $id . '&mode=preview';
$downloadUrl = 'activity-file.php?id=' . $id . '&mode=download';

$pageTitle = '文件预览';
$activeNav = 'activities';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">报名文件</p>
        <h1 class="page-title"><?= h($fileName) ?></h1>
        <p class="page-desc">
            <?= h(format_file_size((int) $entry['file_size'])) ?>
            · 提交人：<?= h($entry['name']) ?>（<?= h($entry['student_no']) ?>）
            · <?= h(substr((string) $entry['created_at'], 0, 16)) ?>
        </p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-primary" href="<?= h($downloadUrl) ?>">下载文件</a>
        <a class="btn btn-secondary" href="activity-entries.php?id=<?= $activityId ?>">返回报名信息</a>
    </div>
</div>

<section class="panel">
    <div class="panel-body">
        <?php if (!is_file($fullPath)): ?>
            <div class="empty-state">
                <h3>文件不存在或已被删除</h3>
            </div>
        <?php elseif ($isVideo): ?>
            <video class="preview-media" controls preload="metadata" src="<?= h($previewUrl) ?>"></video>
            <p class="preview-hint">若无法播放，请点击右上角「下载文件」后本地查看。</p>
        <?php elseif ($isImage): ?>
            <img class="preview-image" src="<?= h($previewUrl) ?>" alt="<?= h($fileName) ?>">
        <?php elseif ($isPdf || $isText): ?>
            <iframe class="preview-frame" src="<?= h($previewUrl) ?>" title="文件预览"></iframe>
        <?php else: ?>
            <div class="empty-state">
                <h3>该文件类型暂不支持在线预览</h3>
                <p>请下载后查看。</p>
                <a class="btn btn-primary" href="<?= h($downloadUrl) ?>">下载文件</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

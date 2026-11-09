<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db = db();

$activities = $db->query(
    "SELECT `a`.*,
            (SELECT COUNT(*) FROM `activity_submissions` `s` WHERE `s`.`activity_id` = `a`.`id`) AS `entry_count`
     FROM `activities` `a`
     ORDER BY `a`.`is_open` DESC, `a`.`created_at` DESC, `a`.`id` DESC"
)->fetchAll();

$pageTitle = '活动报名';
$activeNav = 'events';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">校园广播站</p>
        <h1 class="page-title">活动报名</h1>
    </div>
</div>

<?php if (!$activities): ?>
    <section class="panel">
        <div class="panel-body">
            <div class="empty-state">
                <h3>暂无活动</h3>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-body">
            <div class="activity-list">
                <?php foreach ($activities as $activity): ?>
                    <?php
                    $entryCount = (int) $activity['entry_count'];
                    $status     = activity_status($activity, $entryCount);
                    $deadline   = !empty($activity['deadline'])
                        ? substr((string) $activity['deadline'], 0, 16)
                        : '不限';
                    ?>
                    <a class="activity-card" href="activity.php?id=<?= (int) $activity['id'] ?>">
                        <div class="activity-card-main">
                            <h3><?= h($activity['title']) ?></h3>
                            <?php if (trim((string) $activity['subtitle']) !== ''): ?>
                                <p><?= h($activity['subtitle']) ?></p>
                            <?php endif; ?>
                            <span class="activity-card-meta">
                                报名截止：<?= h($deadline) ?> · 已报名 <?= h((string) $entryCount) ?> 人
                            </span>
                        </div>
                        <span class="badge <?= $status['open'] ? 'badge-approved' : 'badge-pending' ?>">
                            <?= h($status['label']) ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>

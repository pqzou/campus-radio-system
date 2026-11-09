<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (!admin_is_logged_in()) {
    flash('error', '请先登录管理员账号。');
    redirect('settings.php');
}

$db = db();
$activityId = (int) ($_GET['id'] ?? 0);
$activity   = get_activity($db, $activityId);

if ($activity === null) {
    flash('error', '未找到该活动。');
    redirect('activities.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ((string) ($_POST['action'] ?? '') === 'delete_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);

        $stmt = $db->prepare(
            'SELECT `file_path`, `name`, `student_no` FROM `activity_submissions`
             WHERE `id` = ? AND `activity_id` = ? LIMIT 1'
        );
        $stmt->execute([$entryId, $activityId]);
        $entryRow = $stmt->fetch();

        if ($entryRow !== false) {
            $full = __DIR__ . '/' . (string) $entryRow['file_path'];
            if (is_file($full)) {
                @unlink($full);
            }

            $stmt = $db->prepare('DELETE FROM `activity_submissions` WHERE `id` = ?');
            $stmt->execute([$entryId]);

            audit_log(
                'delete',
                'activity',
                '报名记录',
                $entryId,
                '删除活动「' . (string) $activity['title'] . '」的报名：'
                    . (string) $entryRow['name'] . '（' . (string) $entryRow['student_no'] . '）'
            );

            flash('success', '报名记录已删除。');
        } else {
            flash('error', '未找到该报名记录。');
        }

        redirect('activity-entries.php?id=' . $activityId);
    }

    flash('error', '未知操作。');
    redirect('activity-entries.php?id=' . $activityId);
}

$stmt = $db->prepare('SELECT * FROM `activity_submissions` WHERE `activity_id` = ? ORDER BY `id` DESC');
$stmt->execute([$activityId]);
$entries = $stmt->fetchAll();
$entryCount = count($entries);
$status = activity_status($activity, $entryCount);
$deadline = !empty($activity['deadline']) ? substr((string) $activity['deadline'], 0, 16) : '不限';

$pageTitle = '报名信息';
$activeNav = 'activities';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">后台管理</p>
        <h1 class="page-title">报名信息</h1>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-secondary" href="activities.php">返回活动管理</a>
        <a class="btn btn-secondary" href="activity.php?id=<?= $activityId ?>" target="_blank" rel="noopener">查看活动页</a>
    </div>
</div>

<section class="panel">
    <div class="panel-head panel-head-row">
        <div>
            <h2><?= h($activity['title']) ?></h2>
            <p>报名截止：<?= h($deadline) ?> · 共 <?= h((string) $entryCount) ?> 人报名</p>
            <p class="sub-text">上传目录：uploads/activities/<?= $activityId ?>/</p>
        </div>
        <span class="badge <?= $status['open'] ? 'badge-approved' : 'badge-pending' ?>">
            <?= h($status['label']) ?>
        </span>
    </div>

    <div class="panel-body">
        <?php if (!$entries): ?>
            <div class="empty-state">
                <h3>暂无报名</h3>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table entry-table">
                    <thead>
                        <tr>
                            <th>姓名</th>
                            <th>班级</th>
                            <th>学号</th>
                            <th>自定义信息</th>
                            <th>上传文件</th>
                            <th>提交时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $customData = [];
                            if (!empty($entry['form_data'])) {
                                $decoded = json_decode((string) $entry['form_data'], true);
                                if (is_array($decoded)) {
                                    $customData = $decoded;
                                }
                            }
                            ?>
                            <tr>
                                <td class="nowrap"><strong><?= h($entry['name']) ?></strong></td>
                                <td class="nowrap"><?= h($entry['class_name']) ?></td>
                                <td class="nowrap"><?= h($entry['student_no']) ?></td>
                                <td class="entry-custom">
                                    <?php if ($customData): ?>
                                        <?php foreach ($customData as $item): ?>
                                            <?php
                                            $itemValue = $item['value'] ?? '';
                                            if (is_array($itemValue)) {
                                                $itemValue = implode('、', $itemValue);
                                            }
                                            ?>
                                            <span class="entry-custom-line">
                                                <em><?= h((string) ($item['label'] ?? '')) ?>：</em><?= h((string) $itemValue) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="sub-text">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="entry-file-name"><?= h($entry['original_name']) ?></span>
                                    <span class="sub-text"><?= h(format_file_size((int) $entry['file_size'])) ?></span>
                                    <div class="entry-file-actions">
                                        <a class="btn btn-secondary btn-sm"
                                           href="activity-preview.php?id=<?= (int) $entry['id'] ?>"
                                           target="_blank" rel="noopener">在线预览</a>
                                        <a class="btn btn-secondary btn-sm"
                                           href="activity-file.php?id=<?= (int) $entry['id'] ?>&amp;mode=download">下载</a>
                                    </div>
                                </td>
                                <td class="nowrap"><?= h(substr((string) $entry['created_at'], 0, 16)) ?></td>
                                <td class="nowrap">
                                    <form class="inline-form" method="post"
                                          action="activity-entries.php?id=<?= $activityId ?>"
                                          onsubmit="return confirm('确定删除这条报名及上传文件吗？');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_entry">
                                        <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

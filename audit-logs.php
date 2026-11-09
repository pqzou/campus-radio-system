<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (!admin_is_logged_in()) {
    flash('error', '请先登录管理员账号。');
    redirect('settings.php');
}

$db = db();

$categories = [
    'auth'     => '登录认证',
    'settings' => '系统设置',
    'class'    => '专业班级',
    'uploader' => '验证博主',
    'song'     => '曲目',
    'special'  => '特殊播音',
    'activity' => '活动报名',
    'article'  => '文章',
    'system'   => '系统',
];

$actions = [
    'add'          => '添加',
    'update'       => '修改',
    'delete'       => '删除',
    'approve'      => '审核通过',
    'reject'       => '拒绝',
    'login'        => '登录',
    'logout'       => '退出',
    'login_failed' => '登录失败',
    'setup'        => '初始化',
    'complete'     => '播放完毕',
    'uncomplete'   => '取消完成',
    'move'         => '调整顺序',
];

$actorTypes = [
    'admin'    => '管理员',
    'student'  => '学生',
    'reviewer' => '审核员',
    'visitor'  => '访客',
    'system'   => '系统',
];

$category = (string) ($_GET['category'] ?? '');
if (!isset($categories[$category])) {
    $category = '';
}

$actionFilter = (string) ($_GET['action'] ?? '');
if (!isset($actions[$actionFilter])) {
    $actionFilter = '';
}

$keyword = trim((string) ($_GET['keyword'] ?? ''));
$from    = trim((string) ($_GET['from'] ?? ''));
$to      = trim((string) ($_GET['to'] ?? ''));

$fromDate = $from !== '' ? DateTime::createFromFormat('Y-m-d', $from) : null;
if ($fromDate === false || ($fromDate !== null && $fromDate->format('Y-m-d') !== $from)) {
    $from = '';
}
$toDate = $to !== '' ? DateTime::createFromFormat('Y-m-d', $to) : null;
if ($toDate === false || ($toDate !== null && $toDate->format('Y-m-d') !== $to)) {
    $to = '';
}

$where  = [];
$params = [];

if ($category !== '') {
    $where[]  = '`category` = ?';
    $params[] = $category;
}
if ($actionFilter !== '') {
    $where[]  = '`action` = ?';
    $params[] = $actionFilter;
}
if ($keyword !== '') {
    $where[]  = '(`actor` LIKE ? OR `detail` LIKE ? OR `target_type` LIKE ?)';
    $like     = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($from !== '') {
    $where[]  = '`created_at` >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[]  = '`created_at` <= ?';
    $params[] = $to . ' 23:59:59';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare('SELECT COUNT(*) FROM `audit_logs`' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$perPage = 50;
$pages   = max(1, (int) ceil($total / $perPage));
$page    = max(1, (int) ($_GET['page'] ?? 1));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$stmt = $db->prepare(
    'SELECT * FROM `audit_logs`' . $whereSql . ' ORDER BY `id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$queryBase = [
    'category' => $category,
    'action'   => $actionFilter,
    'keyword'  => $keyword,
    'from'     => $from,
    'to'       => $to,
];
$queryBase = array_filter($queryBase, static function ($value): bool {
    return $value !== '';
});

$songTotal    = (int) $db->query('SELECT COUNT(*) FROM `songs`')->fetchColumn();
$songPerPage  = 50;
$songPages    = max(1, (int) ceil($songTotal / $songPerPage));
$songPage     = min(max(1, (int) ($_GET['spage'] ?? 1)), $songPages);
$songOffset   = ($songPage - 1) * $songPerPage;
$songHistory  = $db->query(
    'SELECT * FROM `songs` ORDER BY `created_at` DESC, `id` DESC LIMIT ' . $songPerPage . ' OFFSET ' . $songOffset
)->fetchAll();

$pageTitle = '审计日志';
$activeNav = 'logs';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">后台管理</p>
        <h1 class="page-title">审计日志</h1>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-secondary" href="index.php">返回首页</a>
    </div>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>操作记录</h2>
            <p>共 <?= h((string) $total) ?> 条记录，当前第 <?= h((string) $page) ?> / <?= h((string) $pages) ?> 页。</p>
        </div>
    </div>

    <div class="panel-body">
        <form class="audit-filters" method="get" action="audit-logs.php">
            <select class="input" name="category" aria-label="业务分类">
                <option value="">全部分类</option>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select class="input" name="action" aria-label="操作类型">
                <option value="">全部操作</option>
                <?php foreach ($actions as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $actionFilter === $key ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input class="input" type="date" name="from" value="<?= h($from) ?>" aria-label="开始日期">
            <input class="input" type="date" name="to" value="<?= h($to) ?>" aria-label="结束日期">

            <input class="input" type="text" name="keyword" value="<?= h($keyword) ?>"
                   placeholder="搜索操作人 / 详情" maxlength="80">

            <button class="btn btn-primary" type="submit">筛选</button>
            <a class="btn btn-secondary" href="audit-logs.php">重置</a>
        </form>

        <?php if (!$logs): ?>
            <div class="empty-state">
                <h3>暂无日志</h3>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table audit-table">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>操作人</th>
                            <th>分类</th>
                            <th>操作</th>
                            <th>对象</th>
                            <th>详情</th>
                            <th>IP</th>
                            <th>浏览器指纹</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $logAction = (string) $log['action'];
                            $badgeClass = 'badge-pending';
                            if (in_array($logAction, ['approve', 'login', 'setup'], true)) {
                                $badgeClass = 'badge-approved';
                            } elseif (in_array($logAction, ['reject', 'delete', 'login_failed'], true)) {
                                $badgeClass = 'badge-rejected';
                            }
                            ?>
                            <tr>
                                <td class="nowrap"><?= h(substr((string) $log['created_at'], 0, 16)) ?></td>
                                <td class="nowrap">
                                    <strong><?= h($log['actor']) ?></strong>
                                    <span class="audit-actor-type">
                                        <?= h($actorTypes[(string) $log['actor_type']] ?? (string) $log['actor_type']) ?>
                                    </span>
                                </td>
                                <td class="nowrap"><?= h($categories[(string) $log['category']] ?? (string) $log['category']) ?></td>
                                <td class="nowrap">
                                    <span class="badge <?= h($badgeClass) ?>">
                                        <?= h($actions[$logAction] ?? $logAction) ?>
                                    </span>
                                </td>
                                <td class="nowrap">
                                    <?= h((string) $log['target_type']) ?>
                                    <?php if ((string) $log['target_id'] !== ''): ?>
                                        <span class="sub-text">#<?= h((string) $log['target_id']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="audit-detail"><?= h((string) $log['detail']) ?></td>
                                <td class="nowrap"><?= h((string) $log['ip']) ?></td>
                                <td class="nowrap">
                                    <?php $fingerprint = (string) ($log['fingerprint'] ?? ''); ?>
                                    <?php if ($fingerprint !== ''): ?>
                                        <span class="audit-fingerprint" title="<?= h($fingerprint) ?>"><?= h(substr($fingerprint, 0, 12)) ?></span>
                                    <?php else: ?>
                                        <span class="sub-text">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="audit-pagination" aria-label="日志分页">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-secondary btn-sm"
                           href="audit-logs.php?<?= h(http_build_query($queryBase + ['page' => $page - 1])) ?>">上一页</a>
                    <?php endif; ?>
                    <span>第 <?= h((string) $page) ?> / <?= h((string) $pages) ?> 页</span>
                    <?php if ($page < $pages): ?>
                        <a class="btn btn-secondary btn-sm"
                           href="audit-logs.php?<?= h(http_build_query($queryBase + ['page' => $page + 1])) ?>">下一页</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="panel" id="song-history">
    <div class="panel-head">
        <div>
            <h2>歌曲上传与审核记录</h2>
            <p>共 <?= h((string) $songTotal) ?> 条记录，当前第 <?= h((string) $songPage) ?> / <?= h((string) $songPages) ?> 页。</p>
        </div>
    </div>

    <div class="panel-body">
        <?php if (!$songHistory): ?>
            <div class="empty-state">
                <h3>暂无上传记录</h3>
                <p>班级提交节目后，记录会显示在这里。</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>班级</th>
                            <th>歌曲</th>
                            <th>上传人</th>
                            <th>广播备注</th>
                            <th>状态</th>
                            <th>申请上传时间</th>
                            <th>审核信息</th>
                            <th>完成时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($songHistory as $song): ?>
                            <tr>
                                <td class="nowrap"><?= h($song['class_name']) ?></td>
                                <td>
                                    <strong>《<?= h($song['title']) ?>》</strong>
                                    <span class="sub-text">作者：<?= h($song['artist'] !== '' ? $song['artist'] : '未填写') ?></span>
                                    <?php if (!empty($song['bvid'])): ?>
                                        <a class="bvid-link" href="https://www.bilibili.com/video/<?= h($song['bvid']) ?>"
                                           target="_blank" rel="noopener"><?= h($song['bvid']) ?></a>
                                    <?php else: ?>
                                        <span class="sub-text">待审核补充播放链接</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?= h($song['uploader_name'] !== '' ? $song['uploader_name'] : '未填写') ?>
                                    <span class="sub-text"><?= h($song['uploader_no'] !== '' ? $song['uploader_no'] : '') ?></span>
                                </td>
                                <td class="remark-cell" title="<?= h($song['remark']) ?>">
                                    <?= h($song['remark'] !== '' ? $song['remark'] : '—') ?>
                                </td>
                                <td class="nowrap">
                                    <span class="badge <?= h(status_class((string) $song['status'])) ?>">
                                        <?= h(status_label((string) $song['status'])) ?>
                                    </span>
                                    <?php if ($song['completed_at'] !== null): ?>
                                        <span class="tag-done">已完成</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap"><?= h(substr((string) $song['created_at'], 0, 16)) ?></td>
                                <td class="nowrap">
                                    <?php if ($song['reviewer_name']): ?>
                                        <?= h($song['reviewer_name']) ?>（<?= h($song['reviewer_no']) ?>）
                                        <span class="sub-text"><?= h(substr((string) $song['reviewed_at'], 0, 16)) ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                    <?php if ($song['status'] === STATUS_REJECTED && !empty($song['reject_reason'])): ?>
                                        <span class="reject-reason-text">拒绝原因：<?= h($song['reject_reason']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <?= $song['completed_at'] !== null ? h(substr((string) $song['completed_at'], 0, 16)) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($songPages > 1): ?>
                <nav class="audit-pagination" aria-label="上传记录分页">
                    <?php if ($songPage > 1): ?>
                        <a class="btn btn-secondary btn-sm"
                           href="audit-logs.php?spage=<?= h((string) ($songPage - 1)) ?>#song-history">上一页</a>
                    <?php endif; ?>
                    <span>第 <?= h((string) $songPage) ?> / <?= h((string) $songPages) ?> 页</span>
                    <?php if ($songPage < $songPages): ?>
                        <a class="btn btn-secondary btn-sm"
                           href="audit-logs.php?spage=<?= h((string) ($songPage + 1)) ?>#song-history">下一页</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (!admin_is_logged_in()) {
    flash('error', '请先登录管理员账号。');
    redirect('settings.php');
}

$db = db();

$specialReviewRedirect = static function () use ($db): void {
    $remaining = (int) $db->query(
        "SELECT COUNT(*) FROM `special_broadcasts` WHERE `status` = 'pending'"
    )->fetchColumn();

    redirect($remaining > 0 ? 'player.php?review=1' : 'player.php');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    $stmt = $db->prepare('SELECT `title` FROM `special_broadcasts` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$id]);
    $reviewItem = $stmt->fetch();
    $itemTitle  = $reviewItem !== false ? (string) $reviewItem['title'] : '未知';

    if ($action === 'special_delete') {
        $stmt = $db->prepare('DELETE FROM `special_broadcasts` WHERE `id` = ?');
        $stmt->execute([$id]);
        audit_log('delete', 'special', '特殊播音', $id, "删除特殊播音「{$itemTitle}」");
        flash('success', '特殊播音记录已删除。');
        $specialReviewRedirect();
    }

    if ($action === 'special_approve' || $action === 'special_reject') {
        if ($action === 'special_approve') {
            $stmt = $db->prepare(
                "UPDATE `special_broadcasts`
                 SET `status` = 'approved', `reject_reason` = '', `reviewer_name` = '广播室',
                     `reviewed_at` = NOW(), `updated_at` = NOW()
                 WHERE `id` = ?"
            );
            $stmt->execute([$id]);
            audit_log('approve', 'special', '特殊播音', $id, "通过特殊播音「{$itemTitle}」");
            flash('success', '已通过该特殊播音申请。');
        } else {
            $reason = trim((string) ($_POST['reject_reason'] ?? ''));

            if ($reason === '') {
                flash('error', '请填写拒绝原因。');
            } elseif (text_length($reason) > 200) {
                flash('error', '拒绝原因不能超过 200 个字符。');
            } else {
                $stmt = $db->prepare(
                    "UPDATE `special_broadcasts`
                     SET `status` = 'rejected', `reject_reason` = ?, `reviewer_name` = '广播室',
                         `reviewed_at` = NOW(), `updated_at` = NOW()
                     WHERE `id` = ?"
                );
                $stmt->execute([$reason, $id]);
                audit_log('reject', 'special', '特殊播音', $id, "拒绝特殊播音「{$itemTitle}」：{$reason}");
                flash('success', '已拒绝该特殊播音申请。');
            }
        }

        redirect('player.php?review=1');
    }

    flash('error', '未知操作。');
    redirect('player.php');
}

$approvedSongs = $db->query(
    "SELECT `id`, `class_name`, `title`, `bvid`, `remark`, `duration`, `progress_seconds`, `completed_at`
     FROM `songs`
     WHERE `status` = 'approved' AND `bvid` IS NOT NULL AND `bvid` <> ''
     ORDER BY `class_name` ASC, `sort_order` ASC, `id` ASC"
)->fetchAll();

$pendingSongs = $db->query(
    "SELECT `id`, `class_name`, `title`, `bvid`, `remark`, `duration`, `uploader_name`, `uploader_no`
     FROM `songs`
     WHERE `status` IN ('pending', 're_review')
     ORDER BY `class_name` ASC, `sort_order` ASC, `id` ASC"
)->fetchAll();

$playlist = [];
foreach ($approvedSongs as $song) {
    $duration  = $song['duration'] !== null ? (int) $song['duration'] : 0;
    $progress  = (int) $song['progress_seconds'];
    $completed = $song['completed_at'] !== null;

    if ($completed || ($duration > 0 && $progress >= $duration - 3)) {
        $progress = 0;
    }

    $playlist[] = [
        'id'          => (int) $song['id'],
        'class'       => (string) $song['class_name'],
        'title'       => (string) $song['title'],
        'bvid'        => (string) $song['bvid'],
        'remark'      => (string) $song['remark'],
        'duration'    => $duration,
        'progress'    => $progress,
        'completed'   => $completed,
        'completedAt' => $completed ? substr((string) $song['completed_at'], 0, 16) : '',
    ];
}

$pendingList = [];
foreach ($pendingSongs as $song) {
    $pendingList[] = [
        'id'           => (int) $song['id'],
        'class'        => (string) $song['class_name'],
        'title'        => (string) $song['title'],
        'bvid'         => !empty($song['bvid']) ? (string) $song['bvid'] : '',
        'remark'       => (string) $song['remark'],
        'duration'     => $song['duration'] !== null ? (int) $song['duration'] : 0,
        'uploaderName' => (string) $song['uploader_name'],
        'uploaderNo'   => (string) $song['uploader_no'],
    ];
}

$specialFilter = (string) ($_GET['special_status'] ?? '');
if (!in_array($specialFilter, ['', 'pending', 'approved', 'rejected'], true)) {
    $specialFilter = '';
}

if ($specialFilter !== '') {
    $specialStmt = $db->prepare(
        "SELECT * FROM `special_broadcasts` WHERE `status` = ? ORDER BY (`status` = 'pending') DESC, `id` DESC"
    );
    $specialStmt->execute([$specialFilter]);
} else {
    $specialStmt = $db->query(
        "SELECT * FROM `special_broadcasts` ORDER BY (`status` = 'pending') DESC, `id` DESC"
    );
}
$specialItems = $specialStmt->fetchAll();

$specialCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
foreach ($db->query('SELECT `status`, COUNT(*) AS `c` FROM `special_broadcasts` GROUP BY `status`')->fetchAll() as $row) {
    $key = (string) $row['status'];
    if (isset($specialCounts[$key])) {
        $specialCounts[$key] = (int) $row['c'];
    }
    $specialCounts['all'] += (int) $row['c'];
}

$reviewOpen = isset($_GET['review']) || isset($_GET['special']) || $specialFilter !== '';

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$playlistJson  = json_encode($playlist, $jsonFlags);
$pendingJson   = json_encode($pendingList, $jsonFlags);
$csrfTokenJson = json_encode(csrf_token(), $jsonFlags);

if ($playlistJson === false) {
    $playlistJson = '[]';
}
if ($pendingJson === false) {
    $pendingJson = '[]';
}
if ($csrfTokenJson === false) {
    $csrfTokenJson = '""';
}

$approvedCount = count($playlist);
$pendingCount  = count($pendingList);
$activeCount   = 0;
foreach ($playlist as $item) {
    if (!$item['completed']) {
        $activeCount++;
    }
}
$pageTitle = '大屏播放与审核';
$activeNav = 'player';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head player-page-head">
    <div>
        <p class="eyebrow">广播室</p>
        <h1 class="page-title">大屏播放与审核</h1>
    </div>
    <div class="page-head-actions player-head-actions">
        <span class="player-stat">已通过 <b><?= h((string) $approvedCount) ?></b> 首</span>
        <span class="player-stat is-warn">待审核 <b id="pendingCountBadge"><?= h((string) $pendingCount) ?></b> 首</span>
        <button class="btn btn-primary btn-sm" id="openReviewBtn" type="button"
            <?= ($pendingCount === 0 && $specialCounts['pending'] === 0) ? 'hidden' : '' ?>>审核待处理</button>
        <button class="btn btn-secondary btn-sm" id="refreshBtn" type="button">刷新</button>
        <span class="player-clock" id="clock">--:--:--</span>
    </div>
</div>

<div class="player-layout">
        <section class="player-stage" aria-label="播放区域">
            <div class="screen-wrap" id="screenWrap">
                <iframe
                    id="biliFrame"
                    class="bili-frame"
                    src="about:blank"
                    title="Bilibili 播放器"
                    scrolling="no"
                    frameborder="0"
                    allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
                    allowfullscreen
                    webkitallowfullscreen
                    mozallowfullscreen
                ></iframe>

                <div class="start-overlay" id="startOverlay">
                    <?php if ($activeCount > 0): ?>
                        <div class="start-card">
                            <h1>校园音乐点播</h1>
                            <p class="start-sub">待播放 <?= h((string) $activeCount) ?> 首</p>
                            <button class="start-btn" id="startBtn" type="button">开始播放</button>
                        </div>
                    <?php elseif ($approvedCount > 0): ?>
                        <div class="start-card is-empty">
                            <h1>已审核曲目均已播放完毕</h1>
                        </div>
                    <?php else: ?>
                        <div class="start-card is-empty">
                            <h1>暂无可播放曲目</h1>
                            <p class="start-sub">请先提交节目并经审核通过；审核通过后会自动出现在播放列表中。</p>
                            <a class="start-link" href="upload.php">前往班级上传</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="now-bar">
                <div class="now-copy">
                    <p class="now-class" id="nowClass">等待开始</p>
                    <h2 class="now-title" id="nowTitle">校园音乐点播</h2>
                </div>
                <div class="now-actions">
                    <button class="screen-btn" id="prevBtn" type="button"
                        <?= $activeCount === 0 ? 'disabled' : '' ?>>上一首</button>
                    <button class="screen-btn" id="replayBtn" type="button"
                        <?= $activeCount === 0 ? 'disabled' : '' ?>>重播</button>
                    <button class="screen-btn is-primary" id="nextBtn" type="button"
                        <?= $activeCount === 0 ? 'disabled' : '' ?>>下一首</button>
                    <button class="screen-btn" id="completeBtn" type="button"
                        <?= $activeCount === 0 ? 'disabled' : '' ?>>标记完成</button>
                    <button class="screen-btn" id="fullBtn" type="button">全屏</button>
                </div>
            </div>

            <div class="notice-board" id="noticeBoard" hidden>
                <div class="notice-row" id="currentRemarkRow" hidden>
                    <span class="notice-label">本曲备注</span>
                    <p id="currentRemarkText"></p>
                </div>
                <div class="notice-row is-next" id="nextRemarkRow" hidden>
                    <span class="notice-label">下一首预备提醒</span>
                    <p id="nextRemarkText"></p>
                </div>
            </div>

            <div class="screen-toolbar">
                <label class="screen-switch">
                    <input type="checkbox" id="autoNext" checked>
                    <span>自动连播</span>
                </label>
                <span class="screen-state" id="playState">未开始</span>
                <span class="screen-counter" id="songCounter">0 / <?= h((string) $approvedCount) ?></span>
            </div>
        </section>

        <aside class="playlist-panel" aria-label="播放列表">
            <div class="playlist-head">
                <div>
                    <h2>播放顺序</h2>
                </div>
                <span class="playlist-count" id="playlistCount"><?= h((string) $activeCount) ?></span>
            </div>
            <div class="playlist-filter" id="playlistFilter" role="group" aria-label="按班级筛选播放顺序"></div>
            <ol class="playlist" id="playlist"></ol>

            <details class="completed-panel" id="completedPanel" hidden open>
                <summary class="completed-head">
                    <h3>已播放完毕<span class="completed-chevron" aria-hidden="true">▾</span></h3>
                    <span id="completedCount">0</span>
                </summary>
                <ol class="completed-list" id="completedList"></ol>
            </details>
        </aside>
</div>


<div class="review-backdrop" id="reviewModal" hidden>
    <div class="review-dialog" role="dialog" aria-modal="true" aria-labelledby="reviewDialogTitle">
        <div class="review-dialog-head">
            <div>
                <h2 id="reviewDialogTitle">节目审核</h2>
                <p>特殊播音待审核 <strong><?= h((string) $specialCounts['pending']) ?></strong> 条 · 歌曲待审核
                    <strong id="reviewPendingCount"><?= h((string) $pendingCount) ?></strong> 首</p>
            </div>
            <button class="review-close" id="reviewCloseBtn" type="button">关闭</button>
        </div>

        <div class="review-dialog-body">
            <section id="specialStage" hidden>
                <div class="review-stage-head">
                    <h3>一、特殊播音审核</h3>
                    <p>共 <?= h((string) $specialCounts['all']) ?> 条，待审核 <?= h((string) $specialCounts['pending']) ?> 条。处理完后可继续歌曲审核。</p>
                </div>

                <nav class="song-tabs" aria-label="特殊播音状态筛选">
                    <?php
                    $specialFilters = [
                        ''         => ['label' => '全部', 'count' => $specialCounts['all']],
                        'pending'  => ['label' => '待审核', 'count' => $specialCounts['pending']],
                        'approved' => ['label' => '已通过', 'count' => $specialCounts['approved']],
                        'rejected' => ['label' => '已拒绝', 'count' => $specialCounts['rejected']],
                    ];
                    ?>
                    <?php foreach ($specialFilters as $key => $item): ?>
                        <a class="song-tab <?= $specialFilter === $key ? 'is-active' : '' ?>"
                           href="player.php?review=1<?= $key !== '' ? '&amp;special_status=' . h($key) : '' ?>">
                            <?= h($item['label']) ?><span class="song-tab-count"><?= h((string) $item['count']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php if (!$specialItems): ?>
                    <div class="empty-state">
                        <h3>暂无特殊播音申请</h3>
                    </div>
                <?php else: ?>
                    <div class="special-review-list">
                        <?php foreach ($specialItems as $item): ?>
                            <?php
                            $status = (string) $item['status'];
                            $date   = !empty($item['broadcast_date']) ? substr((string) $item['broadcast_date'], 0, 10) : '不限';
                            ?>
                            <article class="special-review-item">
                                <div class="special-review-head">
                                    <div>
                                        <strong><?= h($item['title']) ?></strong>
                                        <span class="badge <?= h(special_status_class($status)) ?>"><?= h(special_status_label($status)) ?></span>
                                    </div>
                                    <span class="sub-text"><?= h(substr((string) $item['created_at'], 0, 16)) ?></span>
                                </div>

                                <p class="special-review-meta">
                                    <span>期望播出：<?= h($date) ?></span>
                                    <span>提交人：<?= h($item['uploader_name']) ?>（<?= h($item['uploader_no']) ?>）</span>
                                    <?php if (!empty($item['reviewed_at'])): ?>
                                        <span>审核时间：<?= h(substr((string) $item['reviewed_at'], 0, 16)) ?></span>
                                    <?php endif; ?>
                                </p>

                                <details class="special-review-content">
                                    <summary>查看播稿内容</summary>
                                    <p><?= nl2br(h((string) $item['content'])) ?></p>
                                </details>

                                <?php if ($status === 'rejected' && trim((string) $item['reject_reason']) !== ''): ?>
                                    <p class="form-error">拒绝原因：<?= h($item['reject_reason']) ?></p>
                                <?php endif; ?>

                                <div class="special-review-actions">
                                    <?php if ($status !== 'approved'): ?>
                                        <form class="inline-form" method="post" action="player.php">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="special_approve">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <button class="btn btn-primary btn-sm" type="submit">通过</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($status !== 'rejected'): ?>
                                        <form class="inline-form special-reject-form" method="post" action="player.php">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="special_reject">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <input class="input input-sm" type="text" name="reject_reason"
                                                   placeholder="拒绝原因" maxlength="200">
                                            <button class="btn btn-secondary btn-sm" type="submit">拒绝</button>
                                        </form>
                                    <?php endif; ?>

                                    <form class="inline-form" method="post" action="player.php"
                                          onsubmit="return confirm('确定删除这条特殊播音记录吗？');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="special_delete">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">删除</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="review-actions">
                    <button class="btn btn-primary" id="specialContinueBtn" type="button">继续歌曲审核</button>
                </div>
            </section>

            <section id="reviewLogin">
                <p class="review-intro">有新的歌曲需要审核</p>
                <div class="form-row">
                    <label class="field" for="reviewerName">
                        <span>审核人姓名</span>
                        <input class="input" id="reviewerName" type="text" maxlength="50" placeholder="请输入姓名">
                    </label>
                    <label class="field" for="reviewerNo">
                        <span>学号 / 工号</span>
                        <input class="input" id="reviewerNo" type="text" maxlength="50" placeholder="请输入学号或工号">
                    </label>
                </div>
                <p class="form-error" id="reviewLoginError" hidden></p>
                <div class="review-login-actions">
                    <button class="btn btn-primary" id="reviewStartBtn" type="button">开始审核</button>
                    <button class="btn btn-secondary" id="reviewSkipBtn" type="button">暂不审核，仅播放已通过曲目</button>
                </div>
            </section>

            <section id="reviewWorkspace" hidden>
                <div class="review-video">
                    <iframe
                        id="reviewFrame"
                        src="about:blank"
                        title="审核用 Bilibili 播放器"
                        scrolling="no"
                        frameborder="0"
                        allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
                        allowfullscreen
                    ></iframe>
                    <div class="review-video-empty" id="reviewVideoEmpty" hidden>
                        暂无播放链接<br>请在下方补充后点击“加载视频”
                    </div>
                </div>
                <div class="review-info">
                    <p class="review-position" id="reviewPosition"></p>
                    <h3 id="reviewTitle"></h3>
                    <p class="review-meta" id="reviewMeta"></p>
                    <a class="review-link" id="reviewLink" href="#" target="_blank" rel="noopener">打开 B 站原视频 ↗</a>
                </div>
                <div class="review-remark-editor">
                    <label for="reviewRemarkInput">广播备注（审核员可修改或补充）</label>
                    <textarea class="input textarea textarea-sm" id="reviewRemarkInput" maxlength="200"
                              placeholder="留空则大屏不显示备注"></textarea>
                    <p class="review-link-hint">保存后会同步到上传记录和大屏备注。</p>
                </div>
                <div class="review-link-editor">
                    <label for="reviewBvidInput">播放链接 / BV 号</label>
                    <div class="review-link-row">
                        <input class="input" id="reviewBvidInput" type="text" maxlength="500"
                               placeholder="未匹配到视频时，请粘贴 B 站链接或 BV 号">
                        <button class="btn btn-secondary" id="reviewLoadVideoBtn" type="button">加载视频</button>
                    </div>
                    <p class="review-link-hint" id="reviewBvidHint"></p>
                </div>
                <div class="review-reject-editor">
                    <label for="reviewRejectReason">拒绝原因（点击“拒绝”时保存，可留空）</label>
                    <textarea class="input textarea textarea-sm" id="reviewRejectReason" maxlength="200"
                              placeholder="例如：歌曲类型不符合校园广播要求"></textarea>
                </div>
                <p class="review-hint">请完整试听后再作出审核结论。若视频未自动播放，请点击画面中的播放按钮。</p>
                <p class="form-error" id="reviewError" hidden></p>
                <div class="review-actions">
                    <button class="btn btn-secondary" id="reviewSkipSongBtn" type="button">跳过这首</button>
                    <button class="btn btn-danger" id="reviewRejectBtn" type="button">拒绝</button>
                    <button class="btn btn-success" id="reviewApproveBtn" type="button">通过</button>
                </div>
            </section>

            <section id="reviewSummary" hidden>
                <h3>本次审核完成</h3>
                <p class="review-summary-text" id="reviewSummaryText"></p>
                <div class="rejected-box">
                    <h4>未通过名单</h4>
                    <textarea id="rejectedList" readonly rows="10"></textarea>
                    <button class="btn btn-secondary" id="copyRejectedBtn" type="button">复制名单</button>
                </div>
                <div class="review-actions">
                    <button class="btn btn-primary" id="reviewFinishBtn" type="button">完成并返回播放页</button>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
window.PLAYLIST = <?= $playlistJson ?>;
window.PENDING_LIST = <?= $pendingJson ?>;
window.CSRF_TOKEN = <?= $csrfTokenJson ?>;
window.SPECIAL_PENDING = <?= (int) $specialCounts['pending'] ?>;
window.REVIEW_OPEN = <?= $reviewOpen ? 'true' : 'false' ?>;
</script>
<script src="assets/js/player.js?v=9"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>

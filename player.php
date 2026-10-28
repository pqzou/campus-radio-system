<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db = db();

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
$completedCount = $approvedCount - $activeCount;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>音乐播放与审核 - <?= h(site_text('header_title')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=12">
</head>
<body class="player-body">
<div class="player-shell">
    <header class="player-header">
        <a class="player-brand" href="upload.php">
            <img class="school-logo" src="assets/img/school-logo.png" alt="学校校徽"
                 onerror="this.onerror=null;this.src='assets/img/school-logo-placeholder.svg';">
            <span class="player-brand-text">
                <strong><?= h(site_text('header_title')) ?></strong>
            </span>
        </a>
        <div class="player-head-right">
            <span class="player-stat">已通过 <b><?= h((string) $approvedCount) ?></b> 首</span>
            <span class="player-stat is-warn">待审核 <b id="pendingCountBadge"><?= h((string) $pendingCount) ?></b> 首</span>
            <button class="player-link-btn" id="openReviewBtn" type="button"
                <?= $pendingCount === 0 ? 'hidden' : '' ?>>审核待处理</button>
            <span class="player-clock" id="clock">--:--:--</span>
            <a class="player-link" href="settings.php">系统设置</a>
            <a class="player-link" href="upload.php">班级上传</a>
            <button class="player-link-btn" id="refreshBtn" type="button">刷新</button>
        </div>
    </header>

    <main class="player-layout">
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
                            <p class="start-eyebrow">校园广播站</p>
                            <h1>校园音乐点播</h1>
                            <p class="start-sub">待播放 <?= h((string) $activeCount) ?> 首 · 已完成 <?= h((string) $completedCount) ?> 首</p>
                            <button class="start-btn" id="startBtn" type="button">开始播放</button>
                            <p class="start-hint">若未自动播放，请点击视频画面中的播放按钮。播放进度会自动记录。</p>
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

            <div class="completed-panel" id="completedPanel" hidden>
                <div class="completed-head">
                    <h3>已播放完毕</h3>
                    <span id="completedCount">0</span>
                </div>
                <ol class="completed-list" id="completedList"></ol>
            </div>
        </aside>
    </main>
</div>

<div class="review-backdrop" id="reviewModal" hidden>
    <div class="review-dialog" role="dialog" aria-modal="true" aria-labelledby="reviewDialogTitle">
        <div class="review-dialog-head">
            <div>
                <h2 id="reviewDialogTitle">节目审核</h2>
                <p>待审核 <strong id="reviewPendingCount"><?= h((string) $pendingCount) ?></strong> 首 · 审核结果立即保存</p>
            </div>
            <button class="review-close" id="reviewCloseBtn" type="button">关闭</button>
        </div>

        <div class="review-dialog-body">
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
</script>
<script src="assets/js/player.js?v=8"></script>
</body>
</html>

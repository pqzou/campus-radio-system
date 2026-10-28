<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db          = db();
$uploadOpen  = setting('upload_open', '1') === '1';
$maxPerClass = max(1, (int) setting('max_per_class', '3'));

function normalize_class_name(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $normalized = preg_replace('/\s+/u', ' ', $value);

    return $normalized === null ? $value : $normalized;
}

function upload_redirect(string $className): void
{
    redirect('upload.php' . ($className !== '' ? '?class=' . rawurlencode($className) : ''));
}

/**
 * 读取并校验上传人信息（批量提交时只填写一次）。
 *
 * @return array{0: array{name: string, no: string}, 1: array<int, string>}
 */
function parse_uploader_fields(): array
{
    $uploader = [
        'name' => trim((string) ($_POST['uploader_name'] ?? '')),
        'no'   => trim((string) ($_POST['uploader_no'] ?? '')),
    ];

    $errors = [];

    if ($uploader['name'] === '') {
        $errors[] = '请填写上传人姓名。';
    } elseif (text_length($uploader['name']) > 5) {
        $errors[] = '上传人姓名不能超过 5 个字符。';
    }

    if ($uploader['no'] === '') {
        $errors[] = '请填写上传人学号。';
    } elseif (text_length($uploader['no']) > 6) {
        $errors[] = '上传人学号不能超过 6 个字符。';
    }

    return [$uploader, $errors];
}

/**
 * 读取并校验批量提交的多首歌曲。
 *
 * @return array{0: array<int, array{title: string, artist: string, remark: string}>, 1: array<int, string>}
 */
function parse_song_rows(): array
{
    $titleList  = $_POST['song_title'] ?? [];
    $artistList = $_POST['song_artist'] ?? [];
    $remarkList = $_POST['song_remark'] ?? [];

    if (!is_array($titleList)) {
        $titleList = [$titleList];
    }
    if (!is_array($artistList)) {
        $artistList = [$artistList];
    }
    if (!is_array($remarkList)) {
        $remarkList = [$remarkList];
    }

    $titleList  = array_values($titleList);
    $artistList = array_values($artistList);
    $remarkList = array_values($remarkList);

    $total = max(count($titleList), count($artistList), count($remarkList));
    $rows  = [];
    $errors = [];

    for ($i = 0; $i < $total; $i++) {
        $title  = trim((string) ($titleList[$i] ?? ''));
        $artist = trim((string) ($artistList[$i] ?? ''));
        $remark = trim((string) ($remarkList[$i] ?? ''));

        if ($title === '' && $artist === '' && $remark === '') {
            continue;
        }

        $rowNo = $i + 1;

        if ($title === '') {
            $errors[] = "第 {$rowNo} 首请填写歌曲名称。";
        } elseif (text_length($title) > 60) {
            $errors[] = "第 {$rowNo} 首的歌曲名称不能超过 60 个字符。";
        }

        if ($artist === '') {
            $errors[] = "第 {$rowNo} 首请填写歌曲作者。";
        } elseif (text_length($artist) > 100) {
            $errors[] = "第 {$rowNo} 首的歌曲作者不能超过 100 个字符。";
        }

        if (text_length($remark) > 200) {
            $errors[] = "第 {$rowNo} 首的广播备注不能超过 200 个字符。";
        }

        $rows[] = [
            'title'  => $title,
            'artist' => $artist,
            'remark' => $remark,
        ];
    }

    return [$rows, $errors];
}

/**
 * 读取并校验歌曲公共字段。
 *
 * @return array{0: array<string, string>, 1: array<int, string>}
 */
function parse_song_fields(): array
{
    $fields = [
        'uploader_name' => trim((string) ($_POST['uploader_name'] ?? '')),
        'uploader_no'   => trim((string) ($_POST['uploader_no'] ?? '')),
        'title'         => trim((string) ($_POST['title'] ?? '')),
        'artist'        => trim((string) ($_POST['artist'] ?? '')),
        'remark'        => trim((string) ($_POST['remark'] ?? '')),
    ];

    $errors = [];

    if ($fields['uploader_name'] === '') {
        $errors[] = '请填写上传人姓名。';
    } elseif (text_length($fields['uploader_name']) > 50) {
        $errors[] = '上传人姓名不能超过 50 个字符。';
    }

    if ($fields['uploader_no'] === '') {
        $errors[] = '请填写上传人学号。';
    } elseif (text_length($fields['uploader_no']) > 50) {
        $errors[] = '上传人学号不能超过 50 个字符。';
    }

    if ($fields['title'] === '') {
        $errors[] = '请填写歌曲名称。';
    } elseif (text_length($fields['title']) > 60) {
        $errors[] = '歌曲名称不能超过 60 个字符。';
    }

    if ($fields['artist'] === '') {
        $errors[] = '请填写歌曲作者。';
    } elseif (text_length($fields['artist']) > 100) {
        $errors[] = '歌曲作者不能超过 100 个字符。';
    }

    if (text_length($fields['remark']) > 200) {
        $errors[] = '广播备注不能超过 200 个字符。';
    }

    return [$fields, $errors];
}

/**
 * 从已验证博主中匹配视频。
 *
 * @return array{bvid: ?string, source_input: string, duration: ?int, match: ?array}
 */
function match_song_video(PDO $db, string $title, string $artist): array
{
    try {
        $match = find_verified_video($db, $title, $artist);
    } catch (Throwable $e) {
        error_log('video match failed: ' . $e->getMessage());
        $match = null;
    }

    $bvid  = $match['bvid'] ?? null;
    $duration = $bvid !== null ? fetch_bilibili_duration($bvid) : null;

    return [
        'bvid'         => $bvid,
        'source_input' => $bvid !== null ? 'https://www.bilibili.com/video/' . $bvid . '/' : '',
        'duration'     => $duration,
        'match'        => $match,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    @set_time_limit(180);

    $action      = (string) ($_POST['action'] ?? '');
    $postedClass = normalize_class_name((string) ($_POST['class_name'] ?? ''));

    if (!$uploadOpen) {
        flash('error', '当前点播通道已关闭，暂时无法提交或修改节目。');
        upload_redirect($postedClass);
    }

    try {
        if ($action === 'batch_add') {
            $className = $postedClass;

            [$uploader, $errors] = parse_uploader_fields();
            [$rows, $rowErrors]  = parse_song_rows();
            $errors = array_merge($errors, $rowErrors);

            if ($className === '') {
                $errors[] = '请填写班级名称。';
            } elseif (text_length($className) > 50) {
                $errors[] = '班级名称不能超过 50 个字符。';
            }

            if (!$rows && !$rowErrors) {
                $errors[] = '请至少填写一首歌曲。';
            }

            $quotaCount = $className !== '' ? count_quota_class_songs($db, $className) : 0;
            if ($className !== '' && $rows && $quotaCount + count($rows) > $maxPerClass) {
                $remaining = max(0, $maxPerClass - $quotaCount);
                $errors[] = "本班当前还可提交 {$remaining} 首，本次填写了 "
                    . count($rows) . ' 首，请减少歌曲数量，或先删除/完成已有曲目。';
            }

            if ($errors) {
                foreach ($errors as $error) {
                    flash('error', $error);
                }
                upload_redirect($className);
            }

            // 先完成视频匹配，避免在数据库事务中执行网络请求。
            $matches = [];
            foreach ($rows as $row) {
                $matches[] = match_song_video($db, $row['title'], $row['artist']);
            }

            $orderStmt = $db->prepare(
                'SELECT COALESCE(MAX(`sort_order`), 0) FROM `songs` WHERE `class_name` = ?'
            );
            $orderStmt->execute([$className]);
            $nextOrder = (int) $orderStmt->fetchColumn() + 1;

            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    'INSERT INTO `songs`
                        (`class_name`, `uploader_name`, `uploader_no`, `title`, `artist`, `bvid`,
                         `source_input`, `remark`, `sort_order`, `status`, `duration`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($rows as $index => $row) {
                    $matched = $matches[$index];
                    $stmt->execute([
                        $className,
                        $uploader['name'],
                        $uploader['no'],
                        $row['title'],
                        $row['artist'],
                        $matched['bvid'],
                        $matched['source_input'],
                        $row['remark'],
                        $nextOrder + $index,
                        STATUS_PENDING,
                        $matched['duration'],
                    ]);
                }

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            $submitted = count($rows);
            flash(
                'success',
                $submitted === 1
                    ? "《{$rows[0]['title']}》已提交，等待审核。"
                    : "已提交 {$submitted} 首曲目，等待审核。"
            );
            upload_redirect($className);
        }

        if ($action === 'update') {
            $songId = (int) ($_POST['id'] ?? 0);
            $song   = find_song($db, $songId);

            if ($song === null) {
                flash('error', '未找到该曲目，可能已被删除。');
                upload_redirect($postedClass);
            }

            $className = (string) $song['class_name'];

            if ($song['completed_at'] !== null) {
                flash('error', '该曲目已归档到已完成历史，不能在这里修改。');
                upload_redirect($className);
            }

            [$fields, $errors] = parse_song_fields();

            if ($errors) {
                foreach ($errors as $error) {
                    flash('error', $error);
                }
                redirect('upload.php?class=' . rawurlencode($className) . '&edit=' . $songId . '#edit-song');
            }

            $total       = count_active_class_songs($db, $className);
            $currentPos  = active_song_position($db, $className, $songId);
            $positionRaw = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_INT);
            $position    = $positionRaw === false ? $currentPos : (int) $positionRaw;
            $position    = max(1, min($position, max(1, $total)));

            $identityChanged = $fields['title'] !== (string) $song['title']
                || $fields['artist'] !== (string) $song['artist'];

            $contentChanged = $identityChanged
                || $fields['remark'] !== (string) $song['remark']
                || $position !== $currentPos;

            $uploaderChanged = $fields['uploader_name'] !== (string) $song['uploader_name']
                || $fields['uploader_no'] !== (string) $song['uploader_no'];

            if (!$contentChanged && !$uploaderChanged) {
                flash('info', '未检测到内容变化，曲目状态保持不变。');
                upload_redirect($className);
            }

            $bvid         = $song['bvid'] !== null ? (string) $song['bvid'] : null;
            $sourceInput  = (string) $song['source_input'];
            $duration     = $song['duration'] !== null ? (int) $song['duration'] : null;

            if ($identityChanged) {
                $matched     = match_song_video($db, $fields['title'], $fields['artist']);
                $bvid        = $matched['bvid'];
                $sourceInput = $matched['source_input'];
                $duration    = $matched['duration'];
            } elseif ($bvid !== null && $duration === null) {
                $duration = fetch_bilibili_duration($bvid);
            }

            if ($contentChanged) {
                $wasApproved = in_array((string) $song['status'], [STATUS_APPROVED, STATUS_RE_REVIEW], true);
                $newStatus   = $wasApproved ? STATUS_RE_REVIEW : STATUS_PENDING;

                $stmt = $db->prepare(
                    'UPDATE `songs`
                     SET `uploader_name` = ?, `uploader_no` = ?, `title` = ?, `artist` = ?, `bvid` = ?,
                         `source_input` = ?, `remark` = ?, `duration` = ?, `status` = ?,
                         `reviewer_name` = NULL, `reviewer_no` = NULL, `reject_reason` = NULL,
                         `reviewed_at` = NULL, `completed_at` = NULL, `progress_seconds` = 0,
                         `updated_at` = NOW()
                     WHERE `id` = ?'
                );
                $stmt->execute([
                    $fields['uploader_name'],
                    $fields['uploader_no'],
                    $fields['title'],
                    $fields['artist'],
                    $bvid,
                    $sourceInput,
                    $fields['remark'],
                    $duration,
                    $newStatus,
                    $songId,
                ]);
            } else {
                $stmt = $db->prepare(
                    'UPDATE `songs`
                     SET `uploader_name` = ?, `uploader_no` = ?, `updated_at` = NOW()
                     WHERE `id` = ?'
                );
                $stmt->execute([
                    $fields['uploader_name'],
                    $fields['uploader_no'],
                    $songId,
                ]);
            }

            if ($contentChanged && $position !== $currentPos) {
                reorder_active_song($db, $className, $songId, $position);
            }

            if ($contentChanged) {
                if (in_array((string) $song['status'], [STATUS_APPROVED, STATUS_RE_REVIEW], true)) {
                    flash('success', '修改已保存，该曲目已打回“待重新审核”。');
                } elseif ((string) $song['status'] === STATUS_REJECTED) {
                    flash('success', '修改已保存，曲目已重新提交审核。');
                } else {
                    flash('success', '修改已保存，曲目仍在等待审核。');
                }
            } else {
                flash('success', '上传人信息已更新，曲目审核状态保持不变。');
            }
            upload_redirect($className);
        }

        if ($action === 'delete') {
            $songId = (int) ($_POST['id'] ?? 0);
            $song   = find_song($db, $songId);

            if ($song === null) {
                flash('error', '未找到该曲目，可能已被删除。');
                upload_redirect($postedClass);
            }

            $className = (string) $song['class_name'];

            if ($song['completed_at'] !== null) {
                flash('error', '已完成曲目已归档到历史记录，不能从历史中删除。');
                upload_redirect($className);
            }

            $stmt = $db->prepare('DELETE FROM `songs` WHERE `id` = ?');
            $stmt->execute([$songId]);
            normalize_active_class_orders($db, $className);

            flash('success', "《{$song['title']}》已从节目单中删除。");
            upload_redirect($className);
        }

        if ($action === 'move') {
            $songId    = (int) ($_POST['id'] ?? 0);
            $direction = (string) ($_POST['direction'] ?? '');
            $song      = find_song($db, $songId);

            if ($song === null) {
                flash('error', '未找到该曲目，可能已被删除。');
                upload_redirect($postedClass);
            }

            $className = (string) $song['class_name'];

            if ($song['completed_at'] !== null) {
                flash('error', '已完成曲目已归档到历史记录，不能调整播放顺序。');
                upload_redirect($className);
            }

            $ids       = active_class_song_ids($db, $className);
            $index     = array_search($songId, $ids, true);

            if ($index === false || !in_array($direction, ['up', 'down'], true)) {
                flash('error', '无法调整该曲目的顺序。');
                upload_redirect($className);
            }

            $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if ($targetIndex < 0 || $targetIndex >= count($ids)) {
                flash('info', '已经到达播放顺序的边界。');
                upload_redirect($className);
            }

            reorder_active_song($db, $className, $songId, $targetIndex + 1);

            $needsReview = in_array((string) $song['status'], [STATUS_APPROVED, STATUS_RE_REVIEW], true);
            if ($needsReview) {
                $reset = $db->prepare(
                    'UPDATE `songs`
                     SET `status` = ?, `reviewer_name` = NULL, `reviewer_no` = NULL, `reviewed_at` = NULL,
                         `completed_at` = NULL, `progress_seconds` = 0, `updated_at` = NOW()
                     WHERE `id` = ?'
                );
                $reset->execute([STATUS_RE_REVIEW, $songId]);
            }

            flash(
                'success',
                $needsReview
                    ? '播放顺序已调整。该曲目已打回“待重新审核”。'
                    : '播放顺序已调整。'
            );
            upload_redirect($className);
        }

        flash('error', '未知操作。');
        upload_redirect($postedClass);
    } catch (Throwable $e) {
        error_log('upload.php error: ' . $e->getMessage());
        flash('error', '操作失败，请稍后重试。');
        upload_redirect($postedClass);
    }
}

$className = normalize_class_name((string) ($_GET['class'] ?? ''));
if (text_length($className) > 50) {
    $className = '';
}

$classes = $db->query(
    "SELECT `class_name`,
            SUM(CASE WHEN `completed_at` IS NULL AND `status` = 'approved' THEN 1 ELSE 0 END) AS `song_count`,
            SUM(CASE WHEN `completed_at` IS NOT NULL THEN 1 ELSE 0 END) AS `completed_count`
     FROM `songs`
     GROUP BY `class_name`
     ORDER BY `class_name` ASC"
)->fetchAll();

$activeSongs    = [];
$completedSongs = [];
$editSong       = null;

if ($className !== '') {
    $stmt = $db->prepare(
        'SELECT * FROM `songs`
         WHERE `class_name` = ? AND `completed_at` IS NULL
         ORDER BY `sort_order` ASC, `id` ASC'
    );
    $stmt->execute([$className]);
    $activeSongs = $stmt->fetchAll();

    $stmt = $db->prepare(
        'SELECT * FROM `songs`
         WHERE `class_name` = ? AND `completed_at` IS NOT NULL
         ORDER BY `completed_at` DESC, `id` DESC'
    );
    $stmt->execute([$className]);
    $completedSongs = $stmt->fetchAll();

    if (isset($_GET['edit'])) {
        $editId = (int) $_GET['edit'];
        $editFound = false;
        foreach ($activeSongs as $song) {
            if ((int) $song['id'] === $editId) {
                $editSong = $song;
                $editFound = true;
                break;
            }
        }
        if (!$editFound) {
            foreach ($completedSongs as $song) {
                if ((int) $song['id'] === $editId) {
                    flash('error', '该曲目已完成并归入历史，不能修改。如需修改请先在大屏“已播放完毕”中取消完成。');
                    break;
                }
            }
        }
    }
}

$songCount     = count($activeSongs);
$completedCount = count($completedSongs);

$playableCount = 0;
$quotaCount    = 0;
foreach ($activeSongs as $activeSong) {
    $activeStatus = (string) $activeSong['status'];
    if ($activeStatus === STATUS_APPROVED) {
        $playableCount++;
    }
    if ($activeStatus !== STATUS_REJECTED) {
        $quotaCount++;
    }
}

$canAdd        = $uploadOpen && $quotaCount < $maxPerClass;
$remaining     = max(0, $maxPerClass - $quotaCount);
$reviewCount   = $quotaCount - $playableCount;
$rejectedCount = $songCount - $quotaCount;
$editingPos    = $editSong !== null ? active_song_position($db, $className, (int) $editSong['id']) : 0;
$verifiedCount = (int) $db->query('SELECT COUNT(*) FROM `verified_uploaders`')->fetchColumn();

$pageTitle = '班级上传';
$activeNav = 'upload';
$bodyClass = 'modal-open';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">节目单上传与修改</h1>
    </div>
</div>

<?php if (!$uploadOpen): ?>
    <div class="notice notice-warn">
        <strong>当前点播通道已关闭</strong>
        <span>管理员已暂停上传与修改，已提交曲目的审核和播放不受影响。</span>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <h2>选择班级</h2>
    </div>
    <div class="panel-body">
        <form class="class-selector" method="get" action="upload.php">
            <input class="input" type="text" name="class" list="class-options"
                   placeholder="请输入班级名称" maxlength="50" autocomplete="off" required>
            <datalist id="class-options">
                <?php foreach ($classes as $item): ?>
                    <option value="<?= h($item['class_name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <button type="submit" class="btn btn-primary">进入节目单</button>
        </form>

        <?php if ($classes): ?>
            <div class="class-chips" aria-label="已有班级">
                <?php foreach ($classes as $item): ?>
                    <a class="chip <?= $className === $item['class_name'] ? 'is-active' : '' ?>"
                       href="upload.php?class=<?= h(rawurlencode($item['class_name'])) ?>">
                        <?= h($item['class_name']) ?>
                        <span><?= h((string) $item['song_count']) ?> 首待播</span>
                        <?php if ((int) $item['completed_count'] > 0): ?>
                            <span class="chip-history">历史 <?= h((string) $item['completed_count']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($className !== ''): ?>
    <div class="upload-layout">
        <section class="panel programme-panel">
            <div class="panel-head panel-head-row">
                <div>
                    <h2><?= h($className) ?> · 当前节目单</h2>
                    <p>待播放 <?= h((string) $playableCount) ?> 首<?php if ($reviewCount > 0): ?>，待审核 <?= h((string) $reviewCount) ?> 首<?php endif; ?><?php if ($rejectedCount > 0): ?>，未通过 <?= h((string) $rejectedCount) ?> 首<?php endif; ?>，单班上限 <?= h((string) $maxPerClass) ?> 首。</p>
                </div>
                <span class="badge <?= $quotaCount >= $maxPerClass ? 'badge-pending' : 'badge-approved' ?>">
                    <?= h((string) $playableCount) ?> 首待播
                </span>
            </div>

            <div class="panel-body">
                <?php if (!$activeSongs): ?>
                    <div class="empty-state">
                        <h3>本班暂无待播放曲目</h3>
                        <?php if ($completedSongs): ?>
                            <p>已完成的曲目已归入下方历史记录，可在提交表单中继续添加新曲目。</p>
                        <?php else: ?>
                            <p>请在提交表单中填写上传人信息并添加歌曲。</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="song-list">
                        <?php foreach ($activeSongs as $index => $song): ?>
                            <?php
                            $songId     = (int) $song['id'];
                            $status     = (string) $song['status'];
                            $isApproved = $status === STATUS_APPROVED;
                            $isReReview = $status === STATUS_RE_REVIEW;
                            $isRejected = $status === STATUS_REJECTED;
                            $bvid       = !empty($song['bvid']) ? (string) $song['bvid'] : '';
                            ?>
                            <article class="song-item" id="song-<?= $songId ?>">
                                <div class="song-index"><?= h(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></div>
                                <div class="song-main">
                                    <div class="song-title-line">
                                        <h3>《<?= h($song['title']) ?>》</h3>
                                        <span class="badge <?= h(status_class($status)) ?>"><?= h(status_label($status)) ?></span>
                                    </div>
                                    <p class="song-meta">
                                        <span>第 <?= h((string) ($index + 1)) ?> 首</span>
                                        <span>作者：<?= h($song['artist'] !== '' ? $song['artist'] : '未填写') ?></span>
                                        <span>上传人：<?= h($song['uploader_name'] !== '' ? $song['uploader_name'] : '未填写') ?>
                                            （<?= h($song['uploader_no'] !== '' ? $song['uploader_no'] : '未填写') ?>）</span>
                                        <?php if ($bvid !== ''): ?>
                                            <a href="https://www.bilibili.com/video/<?= h($bvid) ?>"
                                               target="_blank" rel="noopener"><?= h($bvid) ?></a>
                                        <?php else: ?>
                                            <span class="text-warn">待审核补充播放链接</span>
                                        <?php endif; ?>
                                        <span>提交于 <?= h(substr((string) $song['created_at'], 0, 16)) ?></span>
                                    </p>
                                    <?php if ($song['remark'] !== ''): ?>
                                        <p class="song-remark"><span>广播备注</span><?= h($song['remark']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($isApproved && $song['reviewer_name']): ?>
                                        <p class="song-reviewer">
                                            审核人：<?= h($song['reviewer_name']) ?>
                                            （<?= h($song['reviewer_no']) ?>）
                                            · <?= h(substr((string) $song['reviewed_at'], 0, 16)) ?>
                                        </p>
                                    <?php elseif ($isReReview): ?>
                                        <p class="song-reviewer is-warn">内容已修改，原审核信息已清空，等待广播室重新审核。</p>
                                    <?php elseif ($isRejected): ?>
                                        <p class="song-reviewer is-danger">
                                            <?php if (!empty($song['reject_reason'])): ?>
                                                未通过审核：<?= h($song['reject_reason']) ?>。请修改内容后重新提交。
                                            <?php else: ?>
                                                该曲目未通过审核，请修改内容后重新提交。
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="song-side">
                                    <?php if ($uploadOpen): ?>
                                        <div class="order-tools" aria-label="调整顺序">
                                            <form method="post" action="upload.php">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="move">
                                                <input type="hidden" name="id" value="<?= $songId ?>">
                                                <input type="hidden" name="class_name" value="<?= h($className) ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <button class="icon-btn" type="submit" title="上移"
                                                        <?= $index === 0 ? 'disabled' : '' ?>>↑</button>
                                            </form>
                                            <form method="post" action="upload.php">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="move">
                                                <input type="hidden" name="id" value="<?= $songId ?>">
                                                <input type="hidden" name="class_name" value="<?= h($className) ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <button class="icon-btn" type="submit" title="下移"
                                                        <?= $index === $songCount - 1 ? 'disabled' : '' ?>>↓</button>
                                            </form>
                                        </div>
                                        <div class="song-actions">
                                            <a class="btn btn-secondary btn-sm"
                                               href="upload.php?class=<?= h(rawurlencode($className)) ?>&amp;edit=<?= $songId ?>#edit-song">修改</a>
                                            <form method="post" action="upload.php"
                                                  onsubmit="return confirm('确定要从节目单中删除这首曲目吗？');">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $songId ?>">
                                                <input type="hidden" name="class_name" value="<?= h($className) ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">删除</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted">通道关闭，暂不可调整</span>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <aside class="panel form-panel" id="edit-song">
            <?php if (!$uploadOpen): ?>
                <div class="panel-head">
                    <h2>提交已暂停</h2>
                    <p>管理员关闭点播通道后，不可新增或修改曲目。</p>
                </div>
            <?php elseif ($editSong !== null): ?>
                <div class="panel-head">
                    <h2>修改曲目</h2>
                    <p>
                        当前状态：
                        <span class="badge <?= h(status_class((string) $editSong['status'])) ?>">
                            <?= h(status_label((string) $editSong['status'])) ?>
                        </span>
                        修改歌名、作者、备注或顺序后会重新提交审核；上传人信息已自动填入，可按需修改。
                    </p>
                </div>
                <form class="panel-body" method="post" action="upload.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $editSong['id'] ?>">
                    <input type="hidden" name="class_name" value="<?= h($className) ?>">

                    <?php if ((string) $editSong['status'] === STATUS_REJECTED && !empty($editSong['reject_reason'])): ?>
                        <p class="form-error">上次拒绝原因：<?= h($editSong['reject_reason']) ?></p>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="field">
                            <label for="edit-uploader-name">上传人姓名</label>
                            <input class="input" id="edit-uploader-name" type="text" name="uploader_name"
                                   value="<?= h($editSong['uploader_name']) ?>" placeholder="请输入上传人姓名"
                                   maxlength="50" required>
                        </div>
                        <div class="field">
                            <label for="edit-uploader-no">上传人学号</label>
                            <input class="input" id="edit-uploader-no" type="text" name="uploader_no"
                                   value="<?= h($editSong['uploader_no']) ?>" placeholder="请输入上传人学号"
                                   maxlength="50" required>
                        </div>
                    </div>
                    <p class="field-hint">默认使用原上传人信息；如需更正可直接修改，修改后仍会记录新的上传人。</p>

                    <div class="field">
                        <label for="edit-title">歌曲名称</label>
                        <input class="input" id="edit-title" type="text" name="title"
                               value="<?= h($editSong['title']) ?>" maxlength="60" required>
                    </div>

                    <div class="field">
                        <label for="edit-artist">歌曲作者</label>
                        <input class="input" id="edit-artist" type="text" name="artist"
                               value="<?= h($editSong['artist']) ?>" maxlength="100" required>
                    </div>

                    <div class="field">
                        <label for="edit-remark">广播备注</label>
                        <textarea class="input textarea" id="edit-remark" name="remark" maxlength="200"
                                  placeholder="可填写希望广播员播读的内容，留空则不显示"><?= h($editSong['remark']) ?></textarea>
                        <span class="field-hint">会显示在大屏上；若下一首有备注，会在前一首播放时提前提醒。</span>
                    </div>

                    <div class="form-note">
                        当前播放链接：
                        <?php if (!empty($editSong['bvid'])): ?>
                            <a href="https://www.bilibili.com/video/<?= h($editSong['bvid']) ?>"
                               target="_blank" rel="noopener"><?= h($editSong['bvid']) ?></a>
                        <?php else: ?>
                            暂无，审核时由广播室补充。
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="edit-position">播放顺序</label>
                        <select class="input" id="edit-position" name="position">
                            <?php for ($i = 1; $i <= $songCount; $i++): ?>
                                <option value="<?= $i ?>" <?= $i === $editingPos ? 'selected' : '' ?>>第 <?= $i ?> 首</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">保存修改</button>
                        <a class="btn btn-secondary" href="upload.php?class=<?= h(rawurlencode($className)) ?>">取消</a>
                    </div>
                </form>
            <?php elseif (!$canAdd): ?>
                <div class="panel-head">
                    <h2>当前节目单已满</h2>
                    <p>本班节目单占用名额已达上限（<?= h((string) $maxPerClass) ?> 首）。可先删除当前曲目，或等待已有曲目完成后归入历史。</p>
                </div>
            <?php else: ?>
                <div class="panel-head">
                    <h2>提交节目</h2>
                    <p>本次还可以提交 <?= h((string) $remaining) ?> 首。</p>
                </div>
                <form class="panel-body" method="post" action="upload.php" id="batchSongForm">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="batch_add">
                    <input type="hidden" name="class_name" value="<?= h($className) ?>">

                    <?php if ($verifiedCount === 0): ?>
                        <div class="notice notice-warn">
                            <strong>提交后需等待广播室成员审核</strong>
                            <span>如暂无可用播放视频，审核同学会在审核时补充。</span>
                        </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="field">
                            <label for="add-uploader-name">上传人姓名</label>
                            <input class="input" id="add-uploader-name" type="text" name="uploader_name"
                                   placeholder="请输入真实姓名" maxlength="50" required>
                        </div>
                        <div class="field">
                            <label for="add-uploader-no">上传人学号</label>
                            <input class="input" id="add-uploader-no" type="text" name="uploader_no"
                                   placeholder="请输入学号" maxlength="50" required>
                        </div>
                    </div>
                    <p class="field-hint">---</p>

                    <div class="song-entry-list" id="songEntryList" data-max-rows="<?= h((string) $remaining) ?>">
                        <div class="song-entry">
                            <div class="song-entry-head">
                                <span class="song-entry-title">第 1 首</span>
                                <button type="button" class="song-entry-remove" disabled>移除</button>
                            </div>
                            <label class="field">
                                <span>歌曲名称</span>
                                <input class="input" type="text" name="song_title[]"
                                       placeholder="例如：海阔天空" maxlength="60" required>
                            </label>
                            <label class="field">
                                <span>歌曲作者</span>
                                <input class="input" type="text" name="song_artist[]"
                                       placeholder="例如：Beyond" maxlength="100" required>
                            </label>
                            <label class="field">
                                <span>广播备注</span>
                                <textarea class="input textarea" name="song_remark[]" maxlength="200"
                                          placeholder="选填"></textarea>
                            </label>
                        </div>
                    </div>

                    <template id="songEntryTemplate">
                        <div class="song-entry">
                            <div class="song-entry-head">
                                <span class="song-entry-title"></span>
                                <button type="button" class="song-entry-remove">移除</button>
                            </div>
                            <label class="field">
                                <span>歌曲名称</span>
                                <input class="input" type="text" name="song_title[]"
                                       placeholder="例如：海阔天空" maxlength="60" required>
                            </label>
                            <label class="field">
                                <span>歌曲作者</span>
                                <input class="input" type="text" name="song_artist[]"
                                       placeholder="例如：Beyond" maxlength="100" required>
                            </label>
                            <label class="field">
                                <span>广播备注</span>
                                <textarea class="input textarea" name="song_remark[]" maxlength="200"
                                          placeholder="选填"></textarea>
                            </label>
                        </div>
                    </template>

                    <button type="button" class="btn btn-secondary btn-block song-entry-add" id="addSongEntry">
                        + 添加一首歌曲
                    </button>

                    <div class="form-actions">
                        <button class="btn btn-primary btn-block" type="submit" id="batchSubmitBtn">提交全部歌曲</button>
                    </div>
                    <p class="form-note">
                        提交后由广播室统一审核；请在填写完成后通知广播室成员。
                    </p>
                </form>
            <?php endif; ?>
        </aside>
    </div>

    <?php if ($completedSongs): ?>
        <section class="panel class-history-panel">
            <div class="panel-head panel-head-row">
                <div>
                    <h2><?= h($className) ?> · 已完成历史</h2>
                    <p>已标记完成的曲目会归档到这里。</p>
                </div>
                <span class="badge badge-approved"><?= h((string) $completedCount) ?> 首</span>
            </div>
            <div class="panel-body">
                <div class="song-list">
                    <?php foreach ($completedSongs as $index => $song): ?>
                        <?php $historyBvid = !empty($song['bvid']) ? (string) $song['bvid'] : ''; ?>
                        <article class="song-item is-history">
                            <div class="song-index"><?= h(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></div>
                            <div class="song-main">
                                <div class="song-title-line">
                                    <h3>《<?= h($song['title']) ?>》</h3>
                                </div>
                                <p class="song-meta">
                                    <span>作者：<?= h($song['artist'] !== '' ? $song['artist'] : '未填写') ?></span>
                                    <span>上传人：<?= h($song['uploader_name'] !== '' ? $song['uploader_name'] : '未填写') ?>
                                        （<?= h($song['uploader_no'] !== '' ? $song['uploader_no'] : '未填写') ?>）</span>
                                    <?php if ($historyBvid !== ''): ?>
                                        <a href="https://www.bilibili.com/video/<?= h($historyBvid) ?>"
                                           target="_blank" rel="noopener"><?= h($historyBvid) ?></a>
                                    <?php else: ?>
                                        <span class="text-warn">待审核补充播放链接</span>
                                    <?php endif; ?>
                                    <span>完成于 <?= h(substr((string) $song['completed_at'], 0, 16)) ?></span>
                                </p>
                                <?php if ($song['remark'] !== ''): ?>
                                    <p class="song-remark"><span>广播备注</span><?= h($song['remark']) ?></p>
                                <?php endif; ?>
                                <?php if ($song['reviewer_name']): ?>
                                    <p class="song-reviewer">
                                        审核人：<?= h($song['reviewer_name']) ?>
                                        （<?= h($song['reviewer_no']) ?>）
                                        · <?= h(substr((string) $song['reviewed_at'], 0, 16)) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="song-side">
                                <span class="tag-done">已完成</span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php else: ?>
    <section class="panel">
        <div class="panel-body">
            <div class="empty-state">
                <h3>请先选择或输入班级</h3>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php
$disclaimerTitle   = site_text('disclaimer_title');
$disclaimerIntro   = site_text('disclaimer_intro');
$disclaimerWarning = site_text('disclaimer_warning');
$disclaimerRules   = site_config()['disclaimer_rules'] ?? [];
if (!is_array($disclaimerRules)) {
    $disclaimerRules = [];
}
?>
<div class="modal-backdrop" id="disclaimerModal" role="dialog" aria-modal="true" aria-labelledby="disclaimerTitle">
    <div class="modal">
        <div class="modal-head">
            <h2 id="disclaimerTitle"><?= h($disclaimerTitle) ?></h2>
        </div>
        <div class="modal-body">
            <p><?= h($disclaimerIntro) ?></p>
            <ul class="rule-list">
                <?php foreach ($disclaimerRules as $rule): ?>
                    <?php if (!is_string($rule) || trim($rule) === '') { continue; } ?>
                    <li>
                        <?php if (preg_match('/^([^：]+)：(.*)$/u', $rule, $ruleMatches)): ?>
                            <strong><?= h($ruleMatches[1]) ?>：</strong><?= h($ruleMatches[2]) ?>
                        <?php else: ?>
                            <?= h($rule) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="modal-warning"><?= h($disclaimerWarning) ?></p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-primary" id="disclaimerAgree">我已阅读并同意</button>
        </div>
    </div>
</div>

<script>
document.getElementById('disclaimerAgree').addEventListener('click', function () {
    document.getElementById('disclaimerModal').hidden = true;
    document.body.classList.remove('modal-open');
});

(function () {
    var list = document.getElementById('songEntryList');
    var addBtn = document.getElementById('addSongEntry');
    var submitBtn = document.getElementById('batchSubmitBtn');
    var template = document.getElementById('songEntryTemplate');
    var maxRows = parseInt(list ? (list.getAttribute('data-max-rows') || '0') : '0', 10);

    if (!list || !addBtn || !template) {
        return;
    }

    function isEntryEmpty(entry) {
        var title = entry.querySelector('input[name="song_title[]"]');
        var artist = entry.querySelector('input[name="song_artist[]"]');
        var remark = entry.querySelector('textarea[name="song_remark[]"]');

        return (!title || title.value.trim() === '')
            && (!artist || artist.value.trim() === '')
            && (!remark || remark.value.trim() === '');
    }

    function refreshRows() {
        var entries = list.querySelectorAll('.song-entry');
        for (var i = 0; i < entries.length; i += 1) {
            var title = entries[i].querySelector('.song-entry-title');
            if (title) {
                title.textContent = '第 ' + (i + 1) + ' 首';
            }
            var remove = entries[i].querySelector('.song-entry-remove');
            if (remove) {
                remove.disabled = entries.length <= 1;
            }
        }
        if (addBtn) {
            addBtn.disabled = maxRows > 0 && entries.length >= maxRows;
        }
    }

    addBtn.addEventListener('click', function () {
        if (maxRows > 0 && list.querySelectorAll('.song-entry').length >= maxRows) {
            return;
        }
        var node = template.content.cloneNode(true);
        list.appendChild(node);
        refreshRows();

        var entries = list.querySelectorAll('.song-entry');
        var last = entries[entries.length - 1];
        var input = last ? last.querySelector('input[name="song_title[]"]') : null;
        if (input) {
            input.focus();
        }
    });

    list.addEventListener('click', function (event) {
        var button = event.target.closest('.song-entry-remove');
        if (!button) {
            return;
        }
        var entries = list.querySelectorAll('.song-entry');
        if (entries.length <= 1) {
            return;
        }
        var entry = button.closest('.song-entry');
        if (entry) {
            entry.remove();
        }
        refreshRows();
    });

    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            var entries = Array.prototype.slice.call(
                list.querySelectorAll('.song-entry')
            );
            var hasContent = entries.some(function (entry) {
                return !isEntryEmpty(entry);
            });

            if (hasContent) {
                entries.forEach(function (entry) {
                    if (isEntryEmpty(entry)) {
                        entry.remove();
                    }
                });
            }
            refreshRows();
        });
    }

    var form = document.getElementById('batchSongForm');
    if (form) {
        form.addEventListener('submit', function () {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = '正在提交，请稍候…';
            }
        });
    }

    refreshRows();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

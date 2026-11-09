<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db = db();

function activity_redirect(int $activityId, int $returnArticle = 0): void
{
    if ($returnArticle > 0) {
        redirect('article.php?id=' . $returnArticle . '#activity-' . $activityId);
    }

    redirect('activity.php?id=' . $activityId . '#activity-' . $activityId);
}

function activity_content_html(string $content): string
{
    $lines = preg_split('/\r\n|\r|\n/', $content);
    if (!is_array($lines)) {
        return '<p class="activity-paragraph">' . h($content) . '</p>';
    }

    $html    = '';
    $section = 0;

    foreach ($lines as $line) {
        $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
        if ($line === '') {
            continue;
        }

        if (preg_match('/^[一二三四五六七八九十]+、(.+)$/u', $line, $matches)) {
            $section++;
            $html .= '<h2 class="activity-section"><span>'
                . str_pad((string) $section, 2, '0', STR_PAD_LEFT) . '</span>'
                . h($matches[1]) . '</h2>';
        } elseif (preg_match('/^（[一二三四五六七八九十]+）(.+)$/u', $line, $matches)) {
            $html .= '<h3 class="activity-subsection">' . h($matches[1]) . '</h3>';
        } elseif (preg_match('/^\d+[\.．、]/u', $line)) {
            $html .= '<p class="activity-list-item">' . h($line) . '</p>';
        } else {
            $html .= '<p class="activity-paragraph">' . h($line) . '</p>';
        }
    }

    return $html;
}

function activity_is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function activity_fail(string $message, bool $ajax, int $activityId, int $returnArticle): void
{
    if ($ajax) {
        json_response(['ok' => false, 'message' => $message], 422);
    }

    flash('error', $message);
    activity_redirect($activityId, $returnArticle);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = activity_is_ajax();

    if ($isAjax) {
        verify_csrf_json();
    } else {
        verify_csrf();
    }
    @set_time_limit(300);

    $action         = (string) ($_POST['action'] ?? '');
    $activityId     = (int) ($_POST['activity_id'] ?? 0);
    $returnArticle  = (int) ($_POST['return_article'] ?? 0);
    $activity       = get_activity($db, $activityId);

    if ($action !== 'signup' || $activity === null) {
        if ($isAjax) {
            json_response(['ok' => false, 'message' => '活动不存在。'], 404);
        }
        flash('error', '活动不存在。');
        redirect('index.php');
    }

    $entryCount = activity_entry_count($db, $activityId);
    $status     = activity_status($activity, $entryCount);

    if (!$status['open']) {
        activity_fail($status['message'], $isAjax, $activityId, $returnArticle);
    }

    $name       = trim((string) ($_POST['name'] ?? ''));
    $className  = trim((string) ($_POST['class_name'] ?? ''));
    $studentNo  = trim((string) ($_POST['student_no'] ?? ''));
    $errors     = [];

    if ($name === '' || text_length($name) > 50) {
        $errors[] = '请填写姓名（不超过 50 个字符）。';
    }
    if ($className === '' || text_length($className) > 50) {
        $errors[] = '请填写班级（不超过 50 个字符）。';
    }
    if ($studentNo === '' || text_length($studentNo) > 50) {
        $errors[] = '请填写学号（不超过 50 个字符）。';
    }

    $customValues = collect_activity_custom_values(
        $db,
        $activityId,
        (array) ($_POST['custom'] ?? []),
        $errors
    );

    $file = $_FILES['file'] ?? null;
    $uploadError = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

    if ($uploadError !== UPLOAD_ERR_OK) {
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = '文件超过服务器允许的上传大小。';
        } else {
            $errors[] = '请上传文件。';
        }
    }

    if ($errors) {
        if ($isAjax) {
            json_response(['ok' => false, 'message' => implode(' ', $errors)], 422);
        }
        foreach ($errors as $error) {
            flash('error', $error);
        }
        activity_redirect($activityId, $returnArticle);
    }

    $originalName = basename((string) ($file['name'] ?? ''));
    $size         = (int) ($file['size'] ?? 0);
    $extension    = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed      = ['mp4', 'mov', 'm4v', 'webm', 'pdf', 'doc', 'docx', 'zip', 'rar', 'jpg', 'jpeg', 'png'];

    if (!in_array($extension, $allowed, true)) {
        activity_fail('不支持的文件类型。', $isAjax, $activityId, $returnArticle);
    }

    if ($size <= 0 || $size > 200 * 1024 * 1024) {
        activity_fail('文件大小需在 200MB 以内。', $isAjax, $activityId, $returnArticle);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        activity_fail('文件上传失败，请重试。', $isAjax, $activityId, $returnArticle);
    }

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $tmpName);
            $mimeType = is_string($detected) ? $detected : '';
            finfo_close($finfo);
        }
    }

    $directory = __DIR__ . '/uploads/activities/' . $activityId;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        activity_fail('无法创建上传目录。', $isAjax, $activityId, $returnArticle);
    }

    $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $relative   = 'uploads/activities/' . $activityId . '/' . $storedName;

    if (!move_uploaded_file($tmpName, __DIR__ . '/' . $relative)) {
        activity_fail('文件保存失败，请重试。', $isAjax, $activityId, $returnArticle);
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO `activity_submissions`
                (`activity_id`, `name`, `class_name`, `student_no`, `file_path`,
                 `original_name`, `file_size`, `mime_type`, `form_data`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $activityId,
            $name,
            $className,
            $studentNo,
            $relative,
            $originalName !== '' ? $originalName : $storedName,
            $size,
            $mimeType,
            $customValues ? json_encode($customValues, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        @unlink(__DIR__ . '/' . $relative);
        error_log('activity signup failed: ' . $e->getMessage());
        activity_fail('报名提交失败，请重试。', $isAjax, $activityId, $returnArticle);
    }

    audit_log(
        'add',
        'activity',
        '报名记录',
        $db->lastInsertId(),
        "报名活动「{$activity['title']}」：{$name}（{$className} / {$studentNo}）",
        $name . '（' . $studentNo . '）',
        'student'
    );

    if ($isAjax) {
        json_response([
            'ok'          => true,
            'message'     => '报名已提交。',
            'entry_count' => $entryCount + 1,
        ]);
    }

    flash('success', '报名已提交。');
    activity_redirect($activityId, $returnArticle);
}

$id = (int) ($_GET['id'] ?? 0);
$activity = get_activity($db, $id);

if ($activity === null) {
    flash('error', '活动不存在。');
    redirect('index.php');
}

$entryCount = activity_entry_count($db, $id);
$status     = activity_status($activity, $entryCount);
$cover      = trim((string) $activity['cover_url']);
$deadline   = !empty($activity['deadline']) ? substr((string) $activity['deadline'], 0, 16) : '不限';
$pageTitle  = (string) $activity['title'];
$activeNav  = '';

require __DIR__ . '/includes/header.php';
?>

<a class="back-link" href="index.php">← 返回首页</a>

<article class="activity-article">
    <header class="activity-article-head">
        <p class="activity-kicker"><?= h(site_text('header_title')) ?></p>
        <h1><?= h($activity['title']) ?></h1>
        <?php if (trim((string) $activity['subtitle']) !== ''): ?>
            <p class="activity-slogan"><?= h($activity['subtitle']) ?></p>
        <?php endif; ?>
        <div class="activity-meta">
            <span class="badge <?= $status['open'] ? 'badge-approved' : 'badge-pending' ?>">
                <?= h($status['label']) ?>
            </span>
            <span>报名截止：<?= h($deadline) ?></span>
            <span>已报名 <?= h((string) $entryCount) ?> 人</span>
        </div>
    </header>

    <?php if ($cover !== ''): ?>
        <img class="activity-cover" src="<?= h($cover) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <?php if (trim((string) $activity['content']) !== ''): ?>
        <div class="activity-body">
            <?= activity_content_html((string) $activity['content']) ?>
        </div>
    <?php endif; ?>

    <?= render_activity_form($activity) ?>
</article>

<script>
(function () {
    var form = document.querySelector('.activity-signup-form');
    if (!form || !window.FormData || !window.XMLHttpRequest) {
        return;
    }

    var progress = form.querySelector('.upload-progress');
    var bar = form.querySelector('.upload-progress-bar');
    var progressText = form.querySelector('.upload-progress-text');
    var status = form.querySelector('.upload-status');
    var submit = form.querySelector('button[type="submit"]');
    var submitText = submit ? submit.textContent : '提交报名';

    function resetButton() {
        form.removeAttribute('data-uploading');
        if (submit) {
            submit.disabled = false;
            submit.textContent = submitText;
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (form.getAttribute('data-uploading') === '1') {
            return;
        }

        form.setAttribute('data-uploading', '1');
        if (progress) {
            progress.hidden = false;
        }
        if (bar) {
            bar.style.width = '0%';
        }
        if (progressText) {
            progressText.textContent = '0%';
        }
        if (status) {
            status.textContent = '正在上传…';
            status.className = 'upload-status';
        }
        if (submit) {
            submit.disabled = true;
            submit.textContent = '上传中…';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action') || window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', function (evt) {
            if (!evt.lengthComputable) {
                return;
            }
            var percent = Math.min(100, Math.round(evt.loaded / evt.total * 100));
            if (bar) {
                bar.style.width = percent + '%';
            }
            if (progressText) {
                progressText.textContent = percent + '%';
            }
            if (status && percent >= 100) {
                status.textContent = '上传完成，正在处理…';
            }
        });

        xhr.addEventListener('load', function () {
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (err) {
                data = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                if (bar) {
                    bar.style.width = '100%';
                }
                if (progressText) {
                    progressText.textContent = '100%';
                }
                if (status) {
                    status.textContent = data.message || '报名已提交。';
                    status.className = 'upload-status is-success';
                }
                form.reset();
                if (data.entry_count !== undefined) {
                    var count = document.querySelector('.activity-entry-count');
                    if (count) {
                        count.textContent = data.entry_count;
                    }
                }
                if (progress) {
                    window.setTimeout(function () {
                        progress.hidden = true;
                    }, 1500);
                }
            } else {
                if (status) {
                    if (xhr.status === 413) {
                        status.textContent = '文件超过服务器允许的大小，请压缩后重试。';
                    } else {
                        status.textContent = (data && data.message) ? data.message : '提交失败，请重试。';
                    }
                    status.className = 'upload-status is-error';
                }
                if (progress) {
                    progress.hidden = true;
                }
            }

            resetButton();
        });

        xhr.addEventListener('error', function () {
            if (status) {
                status.textContent = '上传连接被中断，文件可能超过服务器限制，请压缩后重试。';
                status.className = 'upload-status is-error';
            }
            if (progress) {
                progress.hidden = true;
            }
            resetButton();
        });

        xhr.send(new FormData(form));
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

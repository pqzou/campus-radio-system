<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (!admin_is_logged_in()) {
    flash('error', '请先登录管理员账号。');
    redirect('settings.php');
}

$db = db();

function field_builder_row(string $indexToken, array $field = []): string
{
    $types       = activity_field_types();
    $currentType = (string) ($field['field_type'] ?? 'text');
    $required    = (int) ($field['required'] ?? 0) === 1;

    $html  = '<div class="field-builder-row">';
    $html .= '<div class="field-builder-main">';
    $html .= '<input class="input" type="text" name="field_label[' . $indexToken . ']"'
        . ' value="' . h($field['label'] ?? '') . '" placeholder="字段名称，例如：出生日期" maxlength="80">';
    $html .= '<select class="input" name="field_type[' . $indexToken . ']">';
    foreach ($types as $typeKey => $typeLabel) {
        $html .= '<option value="' . h($typeKey) . '"'
            . ($currentType === $typeKey ? ' selected' : '') . '>' . h($typeLabel) . '</option>';
    }
    $html .= '</select>';
    $html .= '</div>';

    $html .= '<div class="field-builder-extra">';
    $html .= '<input class="input" type="text" name="field_placeholder[' . $indexToken . ']"'
        . ' value="' . h($field['placeholder'] ?? '') . '" placeholder="输入框提示（选填）" maxlength="120">';
    $html .= '<input class="input" type="text" name="field_help[' . $indexToken . ']"'
        . ' value="' . h($field['help_text'] ?? '') . '" placeholder="字段说明（选填）" maxlength="255">';
    $html .= '</div>';

    $html .= '<textarea class="input textarea field-builder-options" name="field_options[' . $indexToken . ']"'
        . ' placeholder="选项：每行一个（下拉 / 单选 / 多选必填）">' . h($field['options'] ?? '') . '</textarea>';

    $html .= '<div class="field-builder-foot">';
    $html .= '<label class="field-builder-required"><input type="checkbox" name="field_required[' . $indexToken . ']"'
        . ' value="1"' . ($required ? ' checked' : '') . '> 必填</label>';
    $html .= '<button type="button" class="btn btn-danger btn-sm field-builder-remove">删除</button>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $deletedActivity = get_activity($db, $id);

        $stmt = $db->prepare('SELECT `file_path` FROM `activity_submissions` WHERE `activity_id` = ?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $full = __DIR__ . '/' . (string) $path;
            if (is_file($full)) {
                @unlink($full);
            }
        }

        $stmt = $db->prepare('DELETE FROM `activity_submissions` WHERE `activity_id` = ?');
        $stmt->execute([$id]);
        $stmt = $db->prepare('DELETE FROM `activity_fields` WHERE `activity_id` = ?');
        $stmt->execute([$id]);
        $stmt = $db->prepare('DELETE FROM `activities` WHERE `id` = ?');
        $stmt->execute([$id]);

        $directory = __DIR__ . '/uploads/activities/' . $id;
        if (is_dir($directory)) {
            foreach ((array) glob($directory . '/*') as $file) {
                if (is_file((string) $file)) {
                    @unlink((string) $file);
                }
            }
            @rmdir($directory);
        }

        audit_log(
            'delete',
            'activity',
            '活动',
            $id,
            '删除活动「' . ($deletedActivity !== null ? (string) $deletedActivity['title'] : '未知') . '」'
        );

        flash('success', '活动已删除。');
        redirect('activities.php');
    }

    if ($action === 'save') {
        $id          = (int) ($_POST['id'] ?? 0);
        $title       = trim((string) ($_POST['title'] ?? ''));
        $subtitle    = trim((string) ($_POST['subtitle'] ?? ''));
        $cover       = trim((string) ($_POST['cover_url'] ?? ''));
        $content     = trim((string) ($_POST['content'] ?? ''));
        $isOpen      = isset($_POST['is_open']) ? 1 : 0;
        $deadlineRaw = trim((string) ($_POST['deadline'] ?? ''));
        $maxRaw      = trim((string) ($_POST['max_entries'] ?? ''));
        $errors      = [];

        if ($title === '') {
            $errors[] = '请填写活动标题。';
        } elseif (text_length($title) > 150) {
            $errors[] = '活动标题不能超过 150 个字符。';
        }

        if (text_length($subtitle) > 255) {
            $errors[] = '活动副标题不能超过 255 个字符。';
        }

        if ($cover !== '') {
            $scheme = strtolower((string) parse_url($cover, PHP_URL_SCHEME));
            if (filter_var($cover, FILTER_VALIDATE_URL) === false
                || !in_array($scheme, ['http', 'https'], true)) {
                $errors[] = '封面图片地址需以 http:// 或 https:// 开头。';
            } elseif (text_length($cover) > 500) {
                $errors[] = '封面图片地址不能超过 500 个字符。';
            }
        }

        if ($content === '') {
            $errors[] = '请填写活动内容。';
        } elseif (text_length($content) > 200000) {
            $errors[] = '活动内容过长。';
        }

        $deadline = null;
        if ($deadlineRaw !== '') {
            $timestamp = strtotime($deadlineRaw);
            if ($timestamp === false) {
                $errors[] = '报名截止时间格式不正确。';
            } else {
                $deadline = date('Y-m-d H:i:s', $timestamp);
            }
        }

        $maxEntries = 0;
        if ($maxRaw !== '') {
            $parsed = filter_var($maxRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($parsed === false) {
                $errors[] = '报名名额需填写不小于 0 的整数。';
            } else {
                $maxEntries = (int) $parsed;
            }
        }

        $allowedTypes   = array_keys(activity_field_types());
        $fieldLabels    = (array) ($_POST['field_label'] ?? []);
        $fieldTypes     = (array) ($_POST['field_type'] ?? []);
        $fieldOptions   = (array) ($_POST['field_options'] ?? []);
        $fieldHints     = (array) ($_POST['field_placeholder'] ?? []);
        $fieldHelps     = (array) ($_POST['field_help'] ?? []);
        $fieldRequired  = (array) ($_POST['field_required'] ?? []);
        $customFields   = [];

        foreach ($fieldLabels as $index => $rawLabel) {
            $label = trim((string) $rawLabel);
            if ($label === '') {
                continue;
            }

            if (text_length($label) > 80) {
                $errors[] = '自定义字段名称不能超过 80 个字符。';
                continue;
            }

            $type = (string) ($fieldTypes[$index] ?? 'text');
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $optionText = '';
            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $lines = preg_split('/\r\n|\r|\n/', (string) ($fieldOptions[$index] ?? ''));
                $clean = [];
                foreach ((array) $lines as $line) {
                    $line = trim((string) $line);
                    if ($line !== '') {
                        $clean[] = $line;
                    }
                }
                $clean = array_values(array_unique($clean));

                if (!$clean) {
                    $errors[] = "自定义字段「{$label}」需要至少一个选项。";
                    continue;
                }
                if (count($clean) > 100) {
                    $errors[] = "自定义字段「{$label}」的选项不能超过 100 个。";
                    continue;
                }

                $optionText = implode("\n", $clean);
            }

            $placeholder = trim((string) ($fieldHints[$index] ?? ''));
            $helpText    = trim((string) ($fieldHelps[$index] ?? ''));

            if (text_length($placeholder) > 120) {
                $errors[] = "自定义字段「{$label}」的提示文字过长。";
            }
            if (text_length($helpText) > 255) {
                $errors[] = "自定义字段「{$label}」的帮助说明过长。";
            }

            $customFields[] = [
                'label'       => $label,
                'field_type'  => $type,
                'required'    => isset($fieldRequired[$index]) ? 1 : 0,
                'options'     => $optionText,
                'placeholder' => $placeholder,
                'help_text'   => $helpText,
            ];
        }

        if (count($customFields) > 30) {
            $errors[] = '自定义字段不能超过 30 个。';
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            redirect('activities.php?edit=' . ($id > 0 ? (string) $id : 'new'));
        }

        if ($id > 0) {
            $stmt = $db->prepare('SELECT `id` FROM `activities` WHERE `id` = ? LIMIT 1');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === false) {
                flash('error', '未找到该活动。');
                redirect('activities.php');
            }

            $stmt = $db->prepare(
                'UPDATE `activities`
                 SET `title` = ?, `subtitle` = ?, `cover_url` = ?, `content` = ?,
                     `is_open` = ?, `deadline` = ?, `max_entries` = ?, `updated_at` = NOW()
                 WHERE `id` = ?'
            );
            $stmt->execute([$title, $subtitle, $cover, $content, $isOpen, $deadline, $maxEntries, $id]);
            $savedId = $id;
            $directory = __DIR__ . '/uploads/activities/' . $id;
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            flash('success', '活动已保存。');
        } else {
            $stmt = $db->prepare(
                'INSERT INTO `activities`
                    (`title`, `subtitle`, `cover_url`, `content`, `is_open`, `deadline`, `max_entries`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$title, $subtitle, $cover, $content, $isOpen, $deadline, $maxEntries]);
            $newId = (int) $db->lastInsertId();
            $savedId = $newId;
            $directory = __DIR__ . '/uploads/activities/' . $newId;
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            flash('success', '活动已创建。');
        }

        $stmt = $db->prepare('DELETE FROM `activity_fields` WHERE `activity_id` = ?');
        $stmt->execute([$savedId]);

        if ($customFields) {
            $insertField = $db->prepare(
                'INSERT INTO `activity_fields`
                    (`activity_id`, `label`, `field_type`, `required`, `options`,
                     `placeholder`, `help_text`, `sort_order`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($customFields as $index => $field) {
                $insertField->execute([
                    $savedId,
                    $field['label'],
                    $field['field_type'],
                    $field['required'],
                    $field['options'],
                    $field['placeholder'],
                    $field['help_text'],
                    $index + 1,
                ]);
            }
        }

        audit_log(
            $id > 0 ? 'update' : 'add',
            'activity',
            '活动',
            $savedId,
            ($id > 0 ? '编辑活动' : '新建活动') . "「{$title}」"
        );

        redirect('activities.php');
    }

    flash('error', '未知操作。');
    redirect('activities.php');
}

$editing   = null;
$editParam = (string) ($_GET['edit'] ?? '');

if ($editParam === 'new') {
    $editing = [
        'id'          => 0,
        'title'       => '',
        'subtitle'    => '',
        'cover_url'   => '',
        'content'     => '',
        'is_open'     => 1,
        'deadline'    => null,
        'max_entries' => 0,
    ];
} elseif ($editParam !== '' && ctype_digit($editParam)) {
    $editing = get_activity($db, (int) $editParam);
    if ($editing === null) {
        flash('error', '未找到该活动。');
        redirect('activities.php');
    }
}

$activityFields = ($editing !== null && (int) $editing['id'] > 0)
    ? get_activity_fields($db, (int) $editing['id'])
    : [];

$activities = $db->query(
    'SELECT `a`.*,
            (SELECT COUNT(*) FROM `activity_submissions` `s` WHERE `s`.`activity_id` = `a`.`id`) AS `entry_count`
     FROM `activities` `a`
     ORDER BY `a`.`id` DESC'
)->fetchAll();

$pageTitle = '活动管理';
$activeNav = 'activities';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">后台管理</p>
        <h1 class="page-title">活动管理</h1>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-secondary" href="index.php">查看首页</a>
    </div>
</div>

<?php if ($editing !== null): ?>
    <?php
    $deadlineValue = !empty($editing['deadline'])
        ? date('Y-m-d\TH:i', strtotime((string) $editing['deadline']))
        : '';
    ?>
    <section class="panel">
        <div class="panel-head">
            <h2><?= (int) $editing['id'] > 0 ? '编辑活动' : '新建活动' ?></h2>
        </div>
        <form class="panel-body article-form" method="post" action="activities.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

            <div class="field">
                <label for="activityTitle">活动标题</label>
                <input class="input" id="activityTitle" type="text" name="title"
                       value="<?= h($editing['title']) ?>" maxlength="150" required>
            </div>

            <div class="field">
                <label for="activitySubtitle">副标题 / 标语</label>
                <input class="input" id="activitySubtitle" type="text" name="subtitle"
                       value="<?= h($editing['subtitle']) ?>" maxlength="255">
            </div>

            <div class="field">
                <label for="activityCover">封面图片地址</label>
                <input class="input" id="activityCover" type="url" name="cover_url"
                       value="<?= h($editing['cover_url']) ?>" maxlength="500"
                       placeholder="https://...，留空则不显示封面">
            </div>

            <div class="field">
                <label for="activityContent">活动内容</label>
                <textarea class="input textarea article-content-input" id="activityContent"
                          name="content" required><?= h($editing['content']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="activityDeadline">报名截止时间</label>
                    <input class="input" id="activityDeadline" type="datetime-local" name="deadline"
                           value="<?= h($deadlineValue) ?>">
                    <span class="field-hint">留空表示不限制截止时间。</span>
                </div>
                <div class="field">
                    <label for="activityMax">报名名额</label>
                    <input class="input" id="activityMax" type="number" name="max_entries"
                           min="0" step="1" value="<?= h((string) $editing['max_entries']) ?>">
                    <span class="field-hint">0 表示不限制人数。</span>
                </div>
            </div>

            <div class="field">
                <label>自定义报名字段</label>
                <p class="field-hint">报名者在姓名、班级、学号、文件之外，还需填写以下字段。下拉、单选、多选请把选项每行写一个。</p>
                <div class="field-builder" id="fieldBuilder">
                    <div class="field-builder-rows" id="fieldBuilderRows">
                        <?php foreach ($activityFields as $index => $field): ?>
                            <?= field_builder_row((string) $index, $field) ?>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="addFieldRow">+ 添加字段</button>
                </div>
                <template id="fieldRowTemplate"><?= field_builder_row('__INDEX__') ?></template>
            </div>

            <label class="article-publish-row">
                <input type="checkbox" name="is_open" value="1"
                    <?= (int) $editing['is_open'] === 1 ? 'checked' : '' ?>>
                开放报名
            </label>

            <?php if ((int) $editing['id'] > 0): ?>
                <p class="field-hint">文章插入代码：[activity:<?= (int) $editing['id'] ?>]（插入活动入口按钮）</p>
                <p class="field-hint">上传目录：uploads/activities/<?= (int) $editing['id'] ?>/</p>
            <?php endif; ?>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存活动</button>
                <a class="btn btn-secondary" href="activities.php">取消</a>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-head panel-head-row">
            <div>
                <h2>活动列表</h2>
                <p>共 <?= h((string) count($activities)) ?> 个活动。</p>
            </div>
            <a class="btn btn-primary btn-sm" href="activities.php?edit=new">新建活动</a>
        </div>

        <div class="panel-body">
            <?php if (!$activities): ?>
                <div class="empty-state">
                    <h3>暂无活动</h3>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>活动</th>
                                <th>状态</th>
                                <th>报名截止</th>
                                <th>报名人数</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $activity): ?>
                                <?php
                                $entryCount = (int) $activity['entry_count'];
                                $status     = activity_status($activity, $entryCount);
                                $deadline   = !empty($activity['deadline'])
                                    ? substr((string) $activity['deadline'], 0, 16)
                                    : '不限';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= h($activity['title']) ?></strong>
                                        <?php if (trim((string) $activity['subtitle']) !== ''): ?>
                                            <span class="sub-text"><?= h($activity['subtitle']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="nowrap">
                                        <span class="badge <?= $status['open'] ? 'badge-approved' : 'badge-pending' ?>">
                                            <?= h($status['label']) ?>
                                        </span>
                                    </td>
                                    <td class="nowrap"><?= h($deadline) ?></td>
                                    <td class="nowrap"><?= h((string) $entryCount) ?> 人</td>
                                    <td class="nowrap">
                                        <div class="table-actions">
                                            <a class="btn btn-secondary btn-sm"
                                               href="activity-entries.php?id=<?= (int) $activity['id'] ?>">报名信息</a>
                                            <a class="btn btn-secondary btn-sm"
                                               href="activities.php?edit=<?= (int) $activity['id'] ?>">编辑</a>
                                            <form class="inline-form" method="post" action="activities.php"
                                                  onsubmit="return confirm('确定删除这个活动及全部报名文件吗？');">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $activity['id'] ?>">
                                                <button class="btn btn-danger btn-sm" type="submit">删除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<script>
(function () {
    var rows = document.getElementById('fieldBuilderRows');
    var addButton = document.getElementById('addFieldRow');
    var template = document.getElementById('fieldRowTemplate');

    if (!rows || !addButton || !template) {
        return;
    }

    var counter = Date.now();

    addButton.addEventListener('click', function () {
        var html = template.innerHTML.replace(/__INDEX__/g, String(counter));
        counter += 1;
        rows.insertAdjacentHTML('beforeend', html);
    });

    rows.addEventListener('click', function (event) {
        var button = event.target.closest('.field-builder-remove');
        if (!button) {
            return;
        }
        var row = button.closest('.field-builder-row');
        if (row) {
            row.parentNode.removeChild(row);
        }
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ((string) ($_POST['action'] ?? '') === 'special_submit') {
        $specialTitle = trim((string) ($_POST['special_title'] ?? ''));
        $specialBody  = trim((string) ($_POST['special_content'] ?? ''));
        $dateRaw      = trim((string) ($_POST['special_date'] ?? ''));
        $specialName  = trim((string) ($_POST['special_name'] ?? ''));
        $specialNo    = trim((string) ($_POST['special_no'] ?? ''));
        $errors       = [];

        $presetId = (int) ($_POST['special_preset'] ?? 0);
        if ($presetId > 0) {
            $presetStmt = $db->prepare('SELECT `name`, `content` FROM `special_presets` WHERE `id` = ? LIMIT 1');
            $presetStmt->execute([$presetId]);
            $preset = $presetStmt->fetch();

            if ($preset !== false) {
                if ($specialTitle === '') {
                    $specialTitle = (string) $preset['name'];
                }
                if ($specialBody === '') {
                    $specialBody = trim((string) $preset['content']);
                }
            }
        }

        if ($specialTitle === '' || text_length($specialTitle) > 120) {
            $errors[] = '请填写点歌标题（不超过 120 个字符）。';
        }
        if ($specialBody === '' || text_length($specialBody) > 5000) {
            $errors[] = '请填写播稿内容（不超过 5000 个字符）。';
        }
        if ($specialName === '' || text_length($specialName) > 50) {
            $errors[] = '请填写提交人姓名（不超过 50 个字符）。';
        }
        if ($specialNo === '' || text_length($specialNo) > 50) {
            $errors[] = '请填写提交人学号（不超过 50 个字符）。';
        }

        $broadcastDate = null;
        if ($dateRaw !== '') {
            $parsedDate = DateTime::createFromFormat('Y-m-d', $dateRaw);
            if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $dateRaw) {
                $errors[] = '期望播出日期格式不正确。';
            } else {
                $broadcastDate = $dateRaw;
            }
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            redirect('special.php');
        }

        $stmt = $db->prepare(
            "INSERT INTO `special_broadcasts`
                (`class_name`, `title`, `content`, `broadcast_date`, `uploader_name`, `uploader_no`, `status`)
             VALUES ('', ?, ?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([$specialTitle, $specialBody, $broadcastDate, $specialName, $specialNo]);

        audit_log(
            'add',
            'special',
            '特殊播音',
            $db->lastInsertId(),
            "提交特殊播音「{$specialTitle}」",
            $specialName . '（' . $specialNo . '）',
            'student'
        );

        flash('success', '特殊个人点歌申请已提交，等待广播室审核。');
        redirect('special.php');
    }

    flash('error', '未知操作。');
    redirect('special.php');
}

$specialPresets = $db->query(
    'SELECT * FROM `special_presets` ORDER BY `sort_order` ASC, `id` ASC'
)->fetchAll();

$pageTitle = '特殊个人点歌';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">校园广播站</p>
        <h1 class="page-title">特殊个人点歌</h1>
        <p class="page-desc">生日祝福、节日专题、通知公告等非歌曲类播音，可在这里提交，广播室审核后安排播出。</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-secondary" href="index.php">返回首页</a>
    </div>
</div>

<section class="panel special-panel">
    <div class="panel-head">
        <h2>提交点歌申请</h2>
        <p>提交后需广播室审核通过才会安排播出。</p>
    </div>
    <div class="panel-body">
        <form class="special-form special-request-form" method="post" action="special.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="special_submit">

            <?php if ($specialPresets): ?>
                <div class="field">
                    <label for="specialPreset">预制播音</label>
                    <select class="input" id="specialPreset" name="special_preset">
                        <option value="">请选择预制播音（也可手动填写）</option>
                        <?php foreach ($specialPresets as $preset): ?>
                            <option value="<?= (int) $preset['id'] ?>"
                                    data-title="<?= h($preset['name']) ?>"
                                    data-content="<?= h($preset['content']) ?>">
                                <?= h($preset['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint">选择后会自动填入下方标题和播稿内容，可再修改。</span>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="specialTitle">点歌标题</label>
                <input class="input" id="specialTitle" type="text" name="special_title"
                       placeholder="例如：生日快乐歌" maxlength="120" required>
            </div>

            <div class="form-row">
                <div class="field">
                    <label for="specialDate">期望播出日期</label>
                    <input class="input" id="specialDate" type="date" name="special_date">
                </div>
                <div class="field">
                    <label for="specialName">提交人姓名</label>
                    <input class="input" id="specialName" type="text" name="special_name"
                           placeholder="请输入真实姓名" maxlength="50" required>
                </div>
            </div>

            <div class="field">
                <label for="specialNo">提交人学号</label>
                <input class="input" id="specialNo" type="text" name="special_no"
                       placeholder="请输入学号" maxlength="50" required>
            </div>

            <div class="field">
                <label for="specialContent">播稿内容</label>
                <textarea class="input textarea" id="specialContent" name="special_content"
                          placeholder="请填写完整播稿或说明" maxlength="5000" required></textarea>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">提交点歌申请</button>
                <a class="btn btn-secondary" href="index.php">取消</a>
            </div>
        </form>
    </div>
</section>

<script>
(function () {
    var select = document.getElementById('specialPreset');
    var title = document.getElementById('specialTitle');
    var content = document.getElementById('specialContent');

    if (!select || !title || !content) {
        return;
    }

    select.addEventListener('change', function () {
        var option = select.options[select.selectedIndex];
        if (!option || select.value === '') {
            return;
        }

        var presetTitle = option.getAttribute('data-title') || '';
        var presetContent = option.getAttribute('data-content') || '';

        if (presetTitle !== '') {
            title.value = presetTitle;
        }
        if (presetContent !== '') {
            content.value = presetContent;
        }
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

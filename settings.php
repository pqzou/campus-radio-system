<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$db = db();

/* ---------------- 登录与退出 ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'setup_admin') {
        verify_csrf();

        if (admin_credentials_configured()) {
            flash('error', '管理员账号已设置，请直接登录。');
            redirect('settings.php');
        }

        $account  = trim((string) ($_POST['account'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if ($account === '') {
            $errors[] = '请填写管理员用户名。';
        } elseif (text_length($account) < 2 || text_length($account) > 50) {
            $errors[] = '管理员用户名长度需为 2—50 个字符。';
        }
        if ($password === '') {
            $errors[] = '请填写管理员密码。';
        } elseif (text_length($password) < 6 || text_length($password) > 100) {
            $errors[] = '管理员密码长度需为 6—100 个字符。';
        } elseif ($password !== $confirm) {
            $errors[] = '两次输入的密码不一致。';
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            redirect('settings.php');
        }

        admin_set_credentials($account, $password);
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        flash('success', '管理员账号已设置，已自动登录。');
        redirect('settings.php');
    }

    if ($action === 'login') {
        verify_csrf();

        $account  = trim((string) ($_POST['account'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (admin_login($account, $password)) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            flash('success', '登录成功。');
            redirect('settings.php');
        }

        usleep(300000);
        flash('error', '账户或密码错误。');
        redirect('settings.php');
    }

    if ($action === 'logout') {
        verify_csrf();
        unset($_SESSION['admin_authenticated']);
        session_regenerate_id(true);
        flash('success', '已退出登录。');
        redirect('settings.php');
    }
}

if (!admin_is_logged_in()) {
    $needsSetup = !admin_credentials_configured();
    $pageTitle  = $needsSetup ? '首次设置管理员' : '管理员登录';
    $activeNav  = 'settings';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <p class="eyebrow">后台管理</p>
            <h1 class="page-title"><?= $needsSetup ? '首次设置管理员' : '管理员登录' ?></h1>
        </div>
    </div>

    <section class="panel login-panel">
        <div class="panel-head">
            <h2><?= $needsSetup ? '创建管理员账号' : '系统管理登录' ?></h2>
        </div>
        <form class="panel-body" method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $needsSetup ? 'setup_admin' : 'login' ?>">

            <div class="field">
                <label for="adminAccount">管理员用户名</label>
                <input class="input" id="adminAccount" type="text" name="account"
                       maxlength="50" autocomplete="username" required>
            </div>

            <div class="field">
                <label for="adminPassword">管理员密码</label>
                <input class="input" id="adminPassword" type="password" name="password"
                       maxlength="100" autocomplete="<?= $needsSetup ? 'new-password' : 'current-password' ?>" required>
            </div>

            <?php if ($needsSetup): ?>
                <div class="field">
                    <label for="adminPasswordConfirm">确认密码</label>
                    <input class="input" id="adminPasswordConfirm" type="password" name="password_confirm"
                           maxlength="100" autocomplete="new-password" required>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $needsSetup ? '保存并登录' : '登录' ?></button>
            </div>
        </form>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------------- 已登录后的操作 ---------------- */

$previousOpen = setting('upload_open', '1') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_admin') {
        $account  = trim((string) ($_POST['admin_account'] ?? ''));
        $password = (string) ($_POST['admin_password'] ?? '');
        $confirm  = (string) ($_POST['admin_password_confirm'] ?? '');

        $errors = [];
        if ($account === '' && $password === '') {
            $errors[] = '请填写新的用户名或新密码。';
        }
        if ($account !== '' && (text_length($account) < 2 || text_length($account) > 50)) {
            $errors[] = '管理员用户名长度需为 2—50 个字符。';
        }
        if ($password !== '') {
            if (text_length($password) < 6 || text_length($password) > 100) {
                $errors[] = '管理员密码长度需为 6—100 个字符。';
            } elseif ($password !== $confirm) {
                $errors[] = '两次输入的新密码不一致。';
            }
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            redirect('settings.php');
        }

        if ($account !== '') {
            update_setting('admin_username_hash', (string) password_hash($account, PASSWORD_DEFAULT));
        }
        if ($password !== '') {
            update_setting('admin_password_hash', (string) password_hash($password, PASSWORD_DEFAULT));
        }

        session_regenerate_id(true);
        flash('success', '管理员账号密码已更新。');
        redirect('settings.php');
    }

    if ($action === 'save') {
        $open   = isset($_POST['upload_open']) ? '1' : '0';
        $rawMax = trim((string) ($_POST['max_per_class'] ?? ''));
        $max    = filter_var($rawMax, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($max === false) {
            flash('error', '单班级歌曲数量上限需填写不小于 1 的整数。');
        } else {
            update_setting('upload_open', $open);
            update_setting('max_per_class', (string) $max);

            if (!$previousOpen && $open === '1') {
                update_setting('upload_opened_at', date('Y-m-d H:i:s'));
            }

            flash('success', '设置已保存，新的规则已立即生效。');
        }

        redirect('settings.php');
    }

    if ($action === 'add_uids') {
        $raw = trim((string) ($_POST['uids'] ?? ''));
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $added = 0;
        $skipped = 0;

        $insert = $db->prepare(
            'INSERT IGNORE INTO `verified_uploaders` (`uid`, `name`) VALUES (?, ?)'
        );

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[,，]/u', $line, 2);
            $uid   = trim((string) ($parts[0] ?? ''));
            $name  = trim((string) ($parts[1] ?? ''));

            if (!preg_match('/^\d{1,20}$/', $uid) || text_length($name) > 50) {
                $skipped++;
                continue;
            }

            $insert->execute([$uid, $name]);
            if ($insert->rowCount() > 0) {
                $added++;
            } else {
                $skipped++;
            }
        }

        if ($added > 0) {
            flash('success', "已添加 {$added} 个已验证博主 UID。");
        }
        if ($skipped > 0) {
            flash('info', "有 {$skipped} 行未添加（格式不正确或 UID 已存在）。");
        }
        if ($added === 0 && $skipped === 0) {
            flash('error', '请至少填写一个 UID。');
        }

        redirect('settings.php');
    }

    if ($action === 'delete_uid') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM `verified_uploaders` WHERE `id` = ?');
        $stmt->execute([$id]);
        flash('success', '已验证博主 UID 已删除。');
        redirect('settings.php');
    }

    flash('error', '未知操作。');
    redirect('settings.php');
}

/* ---------------- 页面数据 ---------------- */

$uploadOpen  = setting('upload_open', '1') === '1';
$maxPerClass = max(1, (int) setting('max_per_class', '3'));
$openedAt    = setting('upload_opened_at', '');

$statusRows = $db->query('SELECT `status`, COUNT(*) AS total FROM `songs` GROUP BY `status`')->fetchAll();
$counts = [
    STATUS_PENDING   => 0,
    STATUS_RE_REVIEW => 0,
    STATUS_APPROVED  => 0,
    STATUS_REJECTED  => 0,
];
foreach ($statusRows as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int) $row['total'];
    }
}

$totalSongs     = array_sum($counts);
$pendingTotal   = $counts[STATUS_PENDING] + $counts[STATUS_RE_REVIEW];
$classCount     = (int) $db->query('SELECT COUNT(DISTINCT `class_name`) FROM `songs`')->fetchColumn();
$completedCount = (int) $db->query(
    "SELECT COUNT(*) FROM `songs` WHERE `status` = 'approved' AND `completed_at` IS NOT NULL"
)->fetchColumn();

$verifiedUploaders = get_verified_uploaders($db);
$history = $db->query(
    'SELECT * FROM `songs` ORDER BY `created_at` DESC, `id` DESC'
)->fetchAll();

$pageTitle = '系统设置';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">后台管理</p>
        <h1 class="page-title">系统设置与上传记录</h1>
    </div>
    <div class="page-head-actions">
        <form class="inline-form" method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-secondary" type="submit">退出登录</button>
        </form>
    </div>
</div>

<div class="settings-layout">
    <section class="panel">
        <div class="panel-head">
            <h2>投递规则</h2>
        </div>

        <form method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">

            <div class="panel-body">
                <div class="setting-row">
                    <div class="setting-copy">
                        <h3>开启上传通道</h3>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="upload_open" value="1" aria-label="开启上传通道"
                            <?= $uploadOpen ? 'checked' : '' ?>>
                        <span class="switch-track" aria-hidden="true"><span class="switch-thumb"></span></span>
                    </label>
                </div>

                <div class="setting-divider"></div>

                <div class="setting-row">
                    <div class="setting-copy">
                        <h3>单班级歌曲数量上限</h3>
                    </div>
                    <div class="inline-input">
                        <input class="input input-number" type="number" name="max_per_class"
                               min="1" step="1" value="<?= h((string) $maxPerClass) ?>" required>
                        <span>首 / 班</span>
                    </div>
                </div>
            </div>

            <div class="panel-foot">
                <button type="submit" class="btn btn-primary">保存设置</button>
            </div>
        </form>
    </section>

    <aside class="panel status-panel">
        <div class="panel-head">
            <h2>当前状态</h2>
        </div>
        <div class="panel-body">
            <div class="status-line">
                <span>点播通道</span>
                <span class="badge <?= $uploadOpen ? 'badge-approved' : 'badge-pending' ?>">
                    <?= $uploadOpen ? '开放中' : '已关闭' ?>
                </span>
            </div>
            <div class="status-line">
                <span>单班上限</span>
                <strong><?= h((string) $maxPerClass) ?> 首</strong>
            </div>
            <div class="status-line">
                <span>最近开放申请上传</span>
                <strong><?= h($openedAt !== '' ? substr($openedAt, 0, 16) : '未记录') ?></strong>
            </div>
            <div class="status-line">
                <span>参与班级</span>
                <strong><?= h((string) $classCount) ?> 个</strong>
            </div>
            <div class="status-line">
                <span>已验证博主</span>
                <strong><?= h((string) count($verifiedUploaders)) ?> 个</strong>
            </div>
        </div>
    </aside>
</div>

<section class="panel admin-account-panel">
    <div class="panel-head">
        <h2>管理员账号与密码</h2>
    </div>
    <form method="post" action="settings.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_admin">

        <div class="panel-body">
            <div class="form-row">
                <div class="field">
                    <label for="adminNewAccount">新用户名</label>
                    <input class="input" id="adminNewAccount" type="text" name="admin_account"
                           maxlength="50" autocomplete="username" placeholder="留空则不修改">
                </div>
                <div class="field">
                    <label for="adminNewPassword">新密码</label>
                    <input class="input" id="adminNewPassword" type="password" name="admin_password"
                           maxlength="100" autocomplete="new-password" placeholder="留空则不修改">
                </div>
            </div>
            <div class="field">
                <label for="adminNewPasswordConfirm">确认新密码</label>
                <input class="input" id="adminNewPasswordConfirm" type="password" name="admin_password_confirm"
                       maxlength="100" autocomplete="new-password" placeholder="修改密码时再次输入">
            </div>
        </div>

        <div class="panel-foot">
            <button class="btn btn-primary" type="submit">保存账号密码</button>
        </div>
    </form>
</section>

<section class="panel uid-panel">
    <div class="panel-head">
        <h2>已验证 B 站博主</h2>
    </div>
    <div class="panel-body">
        <form class="uid-form" method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_uids">

            <div class="field">
                <label for="verifiedUids">添加 UID</label>
                <textarea class="input textarea" id="verifiedUids" name="uids" maxlength="2000"
                          placeholder="每行一个 UID，例如：&#10;3493093607213343&#10;12345678,博主名称"></textarea>
                <span class="field-hint">支持一次添加多个 UID，每行一个；可以在 UID 后加英文逗号或中文逗号填写博主名称。</span>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">添加 UID</button>
            </div>
        </form>

        <div class="uid-list-wrap">
            <?php if (!$verifiedUploaders): ?>
                <div class="notice notice-warn">
                    <strong>尚未添加已验证博主</strong>
                    <span>当前班级提交后无法自动匹配视频，需要审核同学在大屏审核时手动补充播放链接。</span>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>UID</th>
                                <th>博主名称</th>
                                <th>添加时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verifiedUploaders as $uploader): ?>
                                <tr>
                                    <td>
                                        <a href="https://space.bilibili.com/<?= h($uploader['uid']) ?>"
                                           target="_blank" rel="noopener"><?= h($uploader['uid']) ?></a>
                                    </td>
                                    <td><?= h($uploader['name'] !== '' ? $uploader['name'] : '—') ?></td>
                                    <td class="nowrap"><?= h(substr((string) $uploader['created_at'], 0, 16)) ?></td>
                                    <td class="nowrap">
                                        <form class="inline-form" method="post" action="settings.php"
                                              onsubmit="return confirm('确定删除这个已验证博主 UID 吗？');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_uid">
                                            <input type="hidden" name="id" value="<?= (int) $uploader['id'] ?>">
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
    </div>
</section>

<section class="summary-grid" aria-label="数据概览">
    <div class="summary-item">
        <span>曲目总数</span>
        <strong><?= h((string) $totalSongs) ?></strong>
    </div>
    <div class="summary-item">
        <span>待审核 / 待重审</span>
        <strong><?= h((string) $pendingTotal) ?></strong>
    </div>
    <div class="summary-item">
        <span>已审核可播放</span>
        <strong><?= h((string) $counts[STATUS_APPROVED]) ?></strong>
    </div>
    <div class="summary-item">
        <span>未通过</span>
        <strong><?= h((string) $counts[STATUS_REJECTED]) ?></strong>
    </div>
    <div class="summary-item">
        <span>已标记完成</span>
        <strong><?= h((string) $completedCount) ?></strong>
    </div>
</section>

<section class="panel history-panel">
    <div class="panel-head">
        <h2>上传审核日志</h2>
        <p>共 <?= h((string) $totalSongs) ?> 条记录。</p>
    </div>

    <div class="panel-body">
        <?php if (!$history): ?>
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
                        <?php foreach ($history as $song): ?>
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
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

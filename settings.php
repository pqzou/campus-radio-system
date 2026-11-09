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
        $_SESSION['admin_display'] = $account;
        audit_log('setup', 'auth', '管理员账号', '', "首次设置管理员账号：{$account}", $account, 'admin');
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
            $_SESSION['admin_display'] = $account;
            audit_log('login', 'auth', '管理员账号', '', '登录成功', $account, 'admin');
            flash('success', '登录成功。');
            redirect('settings.php');
        }

        usleep(300000);
        audit_log('login_failed', 'auth', '管理员账号', '', '账户或密码错误', $account, 'visitor');
        flash('error', '账户或密码错误。');
        redirect('settings.php');
    }

    if ($action === 'logout') {
        verify_csrf();
        audit_log('logout', 'auth', '管理员账号', '', '退出登录');
        unset($_SESSION['admin_authenticated']);
        unset($_SESSION['admin_display']);
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

        $changedParts = [];
        if ($account !== '') {
            $changedParts[] = '用户名';
            $_SESSION['admin_display'] = $account;
        }
        if ($password !== '') {
            $changedParts[] = '密码';
        }

        audit_log('update', 'auth', '管理员账号', '', '更新了' . implode('和', $changedParts));

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

            audit_log(
                'update',
                'settings',
                '点播设置',
                '',
                '点播通道：' . ($open === '1' ? '开启' : '关闭') . "；单班上限：{$max} 首"
            );

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
            audit_log('add', 'uploader', '已验证博主', '', "新增 {$added} 个已验证博主 UID");
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

        $stmt = $db->prepare('SELECT `uid` FROM `verified_uploaders` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);
        $uid = $stmt->fetchColumn();

        $stmt = $db->prepare('DELETE FROM `verified_uploaders` WHERE `id` = ?');
        $stmt->execute([$id]);

        audit_log('delete', 'uploader', '已验证博主', $id, '删除 UID：' . ($uid !== false ? (string) $uid : '未知'));

        flash('success', '已验证博主 UID 已删除。');
        redirect('settings.php');
    }

    if ($action === 'add_major') {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            flash('error', '请填写专业名称。');
        } elseif (text_length($name) > 50) {
            flash('error', '专业名称不能超过 50 个字符。');
        } else {
            $stmt = $db->prepare('SELECT `id` FROM `majors` WHERE `name` = ? LIMIT 1');
            $stmt->execute([$name]);

            if ($stmt->fetchColumn() !== false) {
                flash('error', '该专业已存在。');
            } else {
                $order = (int) $db->query('SELECT COALESCE(MAX(`sort_order`), 0) FROM `majors`')->fetchColumn() + 1;
                $stmt  = $db->prepare('INSERT INTO `majors` (`name`, `sort_order`) VALUES (?, ?)');
                $stmt->execute([$name, $order]);
                audit_log('add', 'class', '专业', $db->lastInsertId(), "新增专业「{$name}」");
                flash('success', "专业「{$name}」已添加。");
            }
        }

        redirect('settings.php#class-manage');
    }

    if ($action === 'rename_major') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            flash('error', '请填写专业名称。');
        } elseif (text_length($name) > 50) {
            flash('error', '专业名称不能超过 50 个字符。');
        } else {
            $stmt = $db->prepare('SELECT `id` FROM `majors` WHERE `id` = ? LIMIT 1');
            $stmt->execute([$id]);

            if ($stmt->fetchColumn() === false) {
                flash('error', '未找到该专业。');
            } else {
                $stmt = $db->prepare('SELECT `id` FROM `majors` WHERE `name` = ? AND `id` <> ? LIMIT 1');
                $stmt->execute([$name, $id]);

                if ($stmt->fetchColumn() !== false) {
                    flash('error', '该专业名称已存在。');
                } else {
                    $stmt = $db->prepare('UPDATE `majors` SET `name` = ? WHERE `id` = ?');
                    $stmt->execute([$name, $id]);
                    audit_log('update', 'class', '专业', $id, "专业名称改为「{$name}」");
                    flash('success', '专业名称已更新。');
                }
            }
        }

        redirect('settings.php#class-manage');
    }

    if ($action === 'delete_major') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $db->prepare('SELECT COUNT(*) FROM `classes` WHERE `major_id` = ?');
        $stmt->execute([$id]);

        if ((int) $stmt->fetchColumn() > 0) {
            flash('error', '该专业下还有班级，请先删除或转移班级后再删除专业。');
        } else {
            $stmt = $db->prepare('DELETE FROM `majors` WHERE `id` = ?');
            $stmt->execute([$id]);
            audit_log('delete', 'class', '专业', $id, '删除专业（ID ' . $id . '）');
            flash('success', '专业已删除。');
        }

        redirect('settings.php#class-manage');
    }

    if ($action === 'add_class') {
        $majorId = (int) ($_POST['major_id'] ?? 0);
        $name    = trim((string) ($_POST['name'] ?? ''));

        $stmt = $db->prepare('SELECT `id` FROM `majors` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$majorId]);
        $majorExists = $stmt->fetchColumn() !== false;

        if (!$majorExists) {
            flash('error', '请选择有效的专业。');
        } elseif ($name === '') {
            flash('error', '请填写班级名称。');
        } elseif (text_length($name) > 50) {
            flash('error', '班级名称不能超过 50 个字符。');
        } else {
            $stmt = $db->prepare('SELECT `id` FROM `classes` WHERE `name` = ? LIMIT 1');
            $stmt->execute([$name]);

            if ($stmt->fetchColumn() !== false) {
                flash('error', '该班级名称已存在。');
            } else {
                $stmt = $db->prepare(
                    'SELECT COALESCE(MAX(`sort_order`), 0) FROM `classes` WHERE `major_id` = ?'
                );
                $stmt->execute([$majorId]);
                $order = (int) $stmt->fetchColumn() + 1;

                $stmt = $db->prepare(
                    'INSERT INTO `classes` (`major_id`, `name`, `sort_order`) VALUES (?, ?, ?)'
                );
                $stmt->execute([$majorId, $name, $order]);
                audit_log('add', 'class', '班级', $db->lastInsertId(), "新增班级「{$name}」");
                flash('success', "班级「{$name}」已添加。");
            }
        }

        redirect('settings.php#class-manage');
    }

    if ($action === 'update_class') {
        $id      = (int) ($_POST['id'] ?? 0);
        $majorId = (int) ($_POST['major_id'] ?? 0);
        $name    = trim((string) ($_POST['name'] ?? ''));

        $stmt = $db->prepare('SELECT `id`, `name` FROM `classes` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);
        $class = $stmt->fetch();

        $stmt = $db->prepare('SELECT `id` FROM `majors` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$majorId]);
        $majorExists = $stmt->fetchColumn() !== false;

        if ($class === false) {
            flash('error', '未找到该班级。');
        } elseif (!$majorExists) {
            flash('error', '请选择有效的专业。');
        } elseif ($name === '') {
            flash('error', '请填写班级名称。');
        } elseif (text_length($name) > 50) {
            flash('error', '班级名称不能超过 50 个字符。');
        } else {
            $stmt = $db->prepare('SELECT `id` FROM `classes` WHERE `name` = ? AND `id` <> ? LIMIT 1');
            $stmt->execute([$name, $id]);

            if ($stmt->fetchColumn() !== false) {
                flash('error', '该班级名称已存在。');
            } else {
                $oldName = (string) $class['name'];

                $db->beginTransaction();
                try {
                    if ($name !== $oldName) {
                        $stmt = $db->prepare('UPDATE `songs` SET `class_name` = ? WHERE `class_name` = ?');
                        $stmt->execute([$name, $oldName]);
                    }

                    $stmt = $db->prepare(
                        'UPDATE `classes` SET `name` = ?, `major_id` = ? WHERE `id` = ?'
                    );
                    $stmt->execute([$name, $majorId, $id]);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }

                audit_log('update', 'class', '班级', $id, "班级「{$oldName}」更新为「{$name}」");
                flash('success', '班级信息已更新。');
            }
        }

        redirect('settings.php#class-manage');
    }

    if ($action === 'delete_class') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $db->prepare('SELECT `name` FROM `classes` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            flash('error', '未找到该班级。');
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM `songs` WHERE `class_name` = ?');
            $stmt->execute([$name]);

            if ((int) $stmt->fetchColumn() > 0) {
                flash('error', '该班级已有上传记录，不能删除；可修改班级名称或调整所属专业。');
            } else {
                $stmt = $db->prepare('DELETE FROM `classes` WHERE `id` = ?');
                $stmt->execute([$id]);
                audit_log('delete', 'class', '班级', $id, "删除班级「{$name}」");
                flash('success', '班级已删除。');
            }
        }

        redirect('settings.php#class-manage');
    }

    if ($action === 'add_special_preset') {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));

        if ($name === '') {
            flash('error', '请填写预制播音名称。');
        } elseif (text_length($name) > 80) {
            flash('error', '预制播音名称不能超过 80 个字符。');
        } elseif (text_length($content) > 5000) {
            flash('error', '预置播稿内容不能超过 5000 个字符。');
        } else {
            $stmt = $db->prepare('SELECT `id` FROM `special_presets` WHERE `name` = ? LIMIT 1');
            $stmt->execute([$name]);

            if ($stmt->fetchColumn() !== false) {
                flash('error', '该预制播音已存在。');
            } else {
                $order = (int) $db->query('SELECT COALESCE(MAX(`sort_order`), 0) FROM `special_presets`')->fetchColumn() + 1;
                $stmt  = $db->prepare(
                    'INSERT INTO `special_presets` (`name`, `content`, `sort_order`) VALUES (?, ?, ?)'
                );
                $stmt->execute([$name, $content, $order]);
                audit_log('add', 'special', '预制播音', $db->lastInsertId(), "新增预制播音「{$name}」");
                flash('success', "预制播音「{$name}」已添加。");
            }
        }

        redirect('settings.php#special-presets');
    }

    if ($action === 'update_special_preset') {
        $id      = (int) ($_POST['id'] ?? 0);
        $name    = trim((string) ($_POST['name'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));

        $stmt = $db->prepare('SELECT `id` FROM `special_presets` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);

        if ($stmt->fetchColumn() === false) {
            flash('error', '未找到该预制播音。');
        } elseif ($name === '') {
            flash('error', '请填写预制播音名称。');
        } elseif (text_length($name) > 80) {
            flash('error', '预制播音名称不能超过 80 个字符。');
        } elseif (text_length($content) > 5000) {
            flash('error', '预置播稿内容不能超过 5000 个字符。');
        } else {
            $stmt = $db->prepare('SELECT `id` FROM `special_presets` WHERE `name` = ? AND `id` <> ? LIMIT 1');
            $stmt->execute([$name, $id]);

            if ($stmt->fetchColumn() !== false) {
                flash('error', '该预制播音名称已存在。');
            } else {
                $stmt = $db->prepare(
                    'UPDATE `special_presets` SET `name` = ?, `content` = ?, `updated_at` = NOW() WHERE `id` = ?'
                );
                $stmt->execute([$name, $content, $id]);
                audit_log('update', 'special', '预制播音', $id, "更新预制播音「{$name}」");
                flash('success', '预制播音已更新。');
            }
        }

        redirect('settings.php#special-presets');
    }

    if ($action === 'delete_special_preset') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $db->prepare('SELECT `name` FROM `special_presets` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);
        $presetName = $stmt->fetchColumn();

        $stmt = $db->prepare('DELETE FROM `special_presets` WHERE `id` = ?');
        $stmt->execute([$id]);

        audit_log(
            'delete',
            'special',
            '预制播音',
            $id,
            '删除预制播音「' . ($presetName !== false ? (string) $presetName : '未知') . '」'
        );

        flash('success', '预制播音已删除。');

        redirect('settings.php#special-presets');
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
$majors         = get_majors_with_classes($db);
$classCount     = (int) $db->query('SELECT COUNT(*) FROM `classes`')->fetchColumn();
$specialPresets = $db->query(
    'SELECT * FROM `special_presets` ORDER BY `sort_order` ASC, `id` ASC'
)->fetchAll();
$completedCount = (int) $db->query(
    "SELECT COUNT(*) FROM `songs` WHERE `status` = 'approved' AND `completed_at` IS NOT NULL"
)->fetchColumn();

$verifiedUploaders = get_verified_uploaders($db);

$pageTitle = '系统设置';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">后台管理</p>
        <h1 class="page-title">系统设置</h1>
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

<section class="panel class-manage-panel" id="class-manage">
    <div class="panel-head">
        <h2>专业与班级管理</h2>
        <p>学生上传时需先选择专业，再选择班级；这里维护可选的专业和班级列表。</p>
    </div>
    <div class="panel-body">
        <form class="manage-add-form" method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_major">

            <div class="field">
                <label for="newMajorName">新增专业</label>
                <div class="manage-inline">
                    <input class="input input-compact" id="newMajorName" type="text" name="name"
                           maxlength="50" placeholder="例如：护理专业" required>
                    <button class="btn btn-primary" type="submit">添加专业</button>
                </div>
            </div>
        </form>

        <?php if (!$majors): ?>
            <div class="notice notice-warn">
                <strong>尚未设置专业</strong>
                <span>请先添加专业，再在各专业下添加班级；未设置前学生无法选择班级。</span>
            </div>
        <?php else: ?>
            <?php foreach ($majors as $major): ?>
                <div class="major-card">
                    <div class="major-card-head">
                        <form class="major-rename-form" method="post" action="settings.php">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="rename_major">
                            <input type="hidden" name="id" value="<?= (int) $major['id'] ?>">
                            <input class="input input-compact" type="text" name="name"
                                   value="<?= h($major['name']) ?>" maxlength="50"
                                   aria-label="专业名称" required>
                            <button class="btn btn-secondary btn-sm" type="submit">保存名称</button>
                        </form>
                        <form class="inline-form" method="post" action="settings.php"
                              onsubmit="return confirm('确定删除这个专业吗？');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_major">
                            <input type="hidden" name="id" value="<?= (int) $major['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">删除专业</button>
                        </form>
                    </div>

                    <?php if ($major['classes']): ?>
                        <div class="table-scroll">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>班级名称 / 所属专业</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($major['classes'] as $item): ?>
                                        <tr>
                                            <td>
                                                <form class="class-row-form" method="post" action="settings.php">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="update_class">
                                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                    <input class="input input-compact" type="text" name="name"
                                                           value="<?= h($item['name']) ?>" maxlength="50"
                                                           aria-label="班级名称" required>
                                                    <select class="input input-compact" name="major_id"
                                                            aria-label="所属专业">
                                                        <?php foreach ($majors as $optionMajor): ?>
                                                            <option value="<?= (int) $optionMajor['id'] ?>"
                                                                <?= (int) $optionMajor['id'] === (int) $major['id'] ? 'selected' : '' ?>>
                                                                <?= h($optionMajor['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button class="btn btn-secondary btn-sm" type="submit">保存</button>
                                                </form>
                                            </td>
                                            <td class="nowrap">
                                                <form class="inline-form" method="post" action="settings.php"
                                                      onsubmit="return confirm('确定删除这个班级吗？');">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="delete_class">
                                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                    <button class="btn btn-danger btn-sm" type="submit">删除</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="field-hint">该专业下还没有班级。</p>
                    <?php endif; ?>

                    <form class="add-class-form" method="post" action="settings.php">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_class">
                        <input type="hidden" name="major_id" value="<?= (int) $major['id'] ?>">
                        <input class="input input-compact" type="text" name="name"
                               maxlength="50" placeholder="新增班级名称，例如：护理 1 班" required>
                        <button class="btn btn-secondary btn-sm" type="submit">添加班级</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="panel special-preset-panel" id="special-presets">
    <div class="panel-head">
        <h2>特殊播音预设</h2>
        <p>学生在上传页选择预制播音后，会自动带出名称和播稿内容，例如“生日快乐歌”“生日祝福”。</p>
    </div>
    <div class="panel-body">
        <form class="manage-add-form" method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_special_preset">

            <div class="field">
                <label for="newPresetName">新增预制播音</label>
                <div class="manage-inline">
                    <input class="input input-compact" id="newPresetName" type="text" name="name"
                           maxlength="80" placeholder="例如：生日快乐" required>
                    <button class="btn btn-primary" type="submit">添加预制播音</button>
                </div>
            </div>

            <div class="field">
                <label for="newPresetContent">预置播稿内容（选填）</label>
                <textarea class="input textarea textarea-sm preset-content" id="newPresetContent"
                          name="content" maxlength="5000"
                          placeholder="例如：今天是 XX 同学的生日，广播站为你送上生日快乐歌，祝你生日快乐！"></textarea>
            </div>
        </form>

        <?php if (!$specialPresets): ?>
            <div class="notice notice-warn">
                <strong>还没有预制播音</strong>
                <span>添加后，学生在上传页提交特殊播音时可以直接下拉选择。</span>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>预制播音</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($specialPresets as $preset): ?>
                            <tr>
                                <td>
                                    <form class="preset-row-form" method="post" action="settings.php">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update_special_preset">
                                        <input type="hidden" name="id" value="<?= (int) $preset['id'] ?>">
                                        <input class="input input-compact" type="text" name="name"
                                               value="<?= h($preset['name']) ?>" maxlength="80"
                                               aria-label="预制播音名称" required>
                                        <textarea class="input textarea textarea-sm preset-content"
                                                  name="content" maxlength="5000"
                                                  placeholder="预置播稿内容（选填）"
                                                  aria-label="预置播稿内容"><?= h($preset['content']) ?></textarea>
                                        <button class="btn btn-secondary btn-sm" type="submit">保存</button>
                                    </form>
                                </td>
                                <td class="nowrap">
                                    <form class="inline-form" method="post" action="settings.php"
                                          onsubmit="return confirm('确定删除这个预制播音吗？');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_special_preset">
                                        <input type="hidden" name="id" value="<?= (int) $preset['id'] ?>">
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
        <span>拒绝</span>
        <strong><?= h((string) $counts[STATUS_REJECTED]) ?></strong>
    </div>
    <div class="summary-item">
        <span>已标记完成</span>
        <strong><?= h((string) $completedCount) ?></strong>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

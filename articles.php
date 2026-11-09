<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (!admin_is_logged_in()) {
    flash('error', '请先登录管理员账号。');
    redirect('settings.php');
}

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $db->prepare('SELECT `title` FROM `articles` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$id]);
        $deletedTitle = $stmt->fetchColumn();

        $stmt = $db->prepare('DELETE FROM `articles` WHERE `id` = ?');
        $stmt->execute([$id]);

        audit_log(
            'delete',
            'article',
            '文章',
            $id,
            '删除文章「' . ($deletedTitle !== false ? (string) $deletedTitle : '未知') . '」'
        );

        flash('success', '文章已删除。');
        redirect('articles.php');
    }

    if ($action === 'save') {
        $id        = (int) ($_POST['id'] ?? 0);
        $title     = trim((string) ($_POST['title'] ?? ''));
        $summary   = trim((string) ($_POST['summary'] ?? ''));
        $cover     = trim((string) ($_POST['cover_url'] ?? ''));
        $content   = sanitize_article_html((string) ($_POST['content'] ?? ''));
        $published = isset($_POST['is_published']) ? 1 : 0;

        $errors = [];

        if ($title === '') {
            $errors[] = '请填写文章标题。';
        } elseif (text_length($title) > 120) {
            $errors[] = '文章标题不能超过 120 个字符。';
        }

        if (text_length($summary) > 255) {
            $errors[] = '文章摘要不能超过 255 个字符。';
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

        if (article_content_is_empty($content)) {
            $errors[] = '请填写文章正文。';
        } elseif (text_length($content) > 200000) {
            $errors[] = '文章正文过长。';
        }

        if ($errors) {
            foreach ($errors as $error) {
                flash('error', $error);
            }
            redirect('articles.php?edit=' . ($id > 0 ? (string) $id : 'new'));
        }

        if ($id > 0) {
            $stmt = $db->prepare('SELECT `published_at` FROM `articles` WHERE `id` = ? LIMIT 1');
            $stmt->execute([$id]);
            $oldPublishedAt = $stmt->fetchColumn();

            if ($oldPublishedAt === false) {
                flash('error', '未找到该文章。');
                redirect('articles.php');
            }

            $publishedAt = $published
                ? (($oldPublishedAt !== null && $oldPublishedAt !== '') ? (string) $oldPublishedAt : date('Y-m-d H:i:s'))
                : null;

            $stmt = $db->prepare(
                'UPDATE `articles`
                 SET `title` = ?, `summary` = ?, `cover_url` = ?, `content` = ?,
                     `content_format` = \'html\',
                     `is_published` = ?, `published_at` = ?, `updated_at` = NOW()
                 WHERE `id` = ?'
            );
            $stmt->execute([$title, $summary, $cover, $content, $published, $publishedAt, $id]);
            $savedArticleId = $id;
            flash('success', '文章已保存。');
        } else {
            $publishedAt = $published ? date('Y-m-d H:i:s') : null;

            $stmt = $db->prepare(
                'INSERT INTO `articles`
                    (`title`, `summary`, `cover_url`, `content`, `content_format`, `is_published`, `published_at`)
                 VALUES (?, ?, ?, ?, \'html\', ?, ?)'
            );
            $stmt->execute([$title, $summary, $cover, $content, $published, $publishedAt]);
            $savedArticleId = (int) $db->lastInsertId();
            flash('success', '文章已保存。');
        }

        audit_log(
            $id > 0 ? 'update' : 'add',
            'article',
            '文章',
            $savedArticleId,
            ($id > 0 ? '编辑文章' : '新建文章') . "「{$title}」"
        );

        redirect('articles.php');
    }

    flash('error', '未知操作。');
    redirect('articles.php');
}

$editing   = null;
$editParam = (string) ($_GET['edit'] ?? '');

if ($editParam === 'new') {
    $editing = [
        'id'           => 0,
        'title'        => '',
        'summary'      => '',
        'cover_url'    => '',
        'content'      => '',
        'content_format' => 'text',
        'is_published' => 1,
    ];
} elseif ($editParam !== '' && ctype_digit($editParam)) {
    $stmt = $db->prepare('SELECT * FROM `articles` WHERE `id` = ? LIMIT 1');
    $stmt->execute([(int) $editParam]);
    $editing = $stmt->fetch();

    if ($editing === false) {
        flash('error', '未找到该文章。');
        redirect('articles.php');
    }
}

$articles = $db->query(
    'SELECT `id`, `title`, `is_published`, `published_at`, `created_at`
     FROM `articles`
     ORDER BY `is_published` DESC, COALESCE(`published_at`, `created_at`) DESC, `id` DESC'
)->fetchAll();

$activities   = $db->query('SELECT `id`, `title` FROM `activities` ORDER BY `id` DESC')->fetchAll();
$editorContent = $editing !== null
    ? article_content_to_editor_html(
        (string) $editing['content'],
        (string) ($editing['content_format'] ?? 'text')
    )
    : '';

$pageTitle = '文章管理';
$activeNav = 'articles';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <p class="eyebrow">后台管理</p>
        <h1 class="page-title">文章管理</h1>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-secondary" href="index.php">查看首页</a>
    </div>
</div>

<?php if ($editing !== null): ?>
    <section class="panel">
        <div class="panel-head">
            <h2><?= (int) $editing['id'] > 0 ? '编辑文章' : '写文章' ?></h2>
        </div>
        <form class="panel-body article-form" method="post" action="articles.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

            <div class="field">
                <label for="articleTitle">标题</label>
                <input class="input" id="articleTitle" type="text" name="title"
                       value="<?= h($editing['title']) ?>" maxlength="120" required>
            </div>

            <div class="field">
                <label for="articleSummary">摘要</label>
                <input class="input" id="articleSummary" type="text" name="summary"
                       value="<?= h($editing['summary']) ?>" maxlength="255"
                       placeholder="留空则自动截取正文">
            </div>

            <div class="field">
                <label for="articleCover">封面图片地址</label>
                <input class="input" id="articleCover" type="url" name="cover_url"
                       value="<?= h($editing['cover_url']) ?>" maxlength="500"
                       placeholder="https://...，留空则不显示封面">
            </div>

            <div class="field">
                <label>正文</label>
                <div class="article-editor">
                    <div class="article-editor-toolbar" id="articleEditorToolbar">
                        <button type="button" data-command="bold"><strong>B</strong></button>
                        <button type="button" data-command="italic"><em>I</em></button>
                        <button type="button" data-command="underline"><u>U</u></button>
                        <input class="editor-color" id="articleColor" type="color" value="#b42318" title="文字颜色">
                        <button type="button" data-command="formatBlock" data-value="h2">大标题</button>
                        <button type="button" data-command="formatBlock" data-value="h3">小标题</button>
                        <button type="button" data-command="formatBlock" data-value="p">正文</button>
                        <button type="button" data-command="insertUnorderedList">无序列表</button>
                        <button type="button" data-command="insertOrderedList">有序列表</button>
                        <button type="button" data-command="formatBlock" data-value="blockquote">引用</button>
                        <button type="button" data-command="justifyLeft">左对齐</button>
                        <button type="button" data-command="justifyCenter">居中</button>
                        <button type="button" data-command="createLink">链接</button>
                        <button type="button" data-command="insertImage">图片</button>
                        <button type="button" data-command="insertHorizontalRule">分隔线</button>
                        <button type="button" data-command="removeFormat">清除格式</button>
                        <button type="button" data-command="undo">撤销</button>
                        <button type="button" data-command="redo">重做</button>
                        <button type="button" id="articleSourceToggle">HTML 源码</button>
                        <?php if ($activities): ?>
                            <select class="editor-select" id="activityPicker">
                                <option value="">插入活动入口</option>
                                <?php foreach ($activities as $activity): ?>
                                    <option value="<?= (int) $activity['id'] ?>"><?= h($activity['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="article-editor-paper" id="articleEditor" contenteditable="true"><?= $editorContent ?></div>
                    <textarea class="article-editor-source" id="articleSource" spellcheck="false" hidden><?= h($editorContent) ?></textarea>
                    <textarea id="articleContent" name="content" hidden><?= h($editorContent) ?></textarea>
                </div>
                <span class="field-hint">支持加粗、标题、颜色、列表、引用、图片和活动入口按钮；点击“HTML 源码”可直接编写 HTML。</span>
            </div>

            <label class="article-publish-row">
                <input type="checkbox" name="is_published" value="1"
                    <?= (int) $editing['is_published'] === 1 ? 'checked' : '' ?>>
                发布到首页
            </label>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">保存文章</button>
                <a class="btn btn-secondary" href="articles.php">取消</a>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-head panel-head-row">
            <div>
                <h2>文章列表</h2>
                <p>共 <?= h((string) count($articles)) ?> 篇。</p>
            </div>
            <a class="btn btn-primary btn-sm" href="articles.php?edit=new">写文章</a>
        </div>

        <div class="panel-body">
            <?php if (!$articles): ?>
                <div class="empty-state">
                    <h3>暂无文章</h3>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>标题</th>
                                <th>状态</th>
                                <th>发布时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($articles as $article): ?>
                                <?php
                                $isPublished = (int) $article['is_published'] === 1;
                                $showDate    = substr(
                                    (string) ($article['published_at'] ?? $article['created_at']),
                                    0,
                                    16
                                );
                                ?>
                                <tr>
                                    <td><strong><?= h($article['title']) ?></strong></td>
                                    <td class="nowrap">
                                        <span class="badge <?= $isPublished ? 'badge-approved' : 'badge-pending' ?>">
                                            <?= $isPublished ? '已发布' : '草稿' ?>
                                        </span>
                                    </td>
                                    <td class="nowrap"><?= h($showDate) ?></td>
                                    <td class="nowrap">
                                        <div class="table-actions">
                                            <?php if ($isPublished): ?>
                                                <a class="btn btn-secondary btn-sm"
                                                   href="article.php?id=<?= (int) $article['id'] ?>"
                                                   target="_blank" rel="noopener">查看</a>
                                            <?php endif; ?>
                                            <a class="btn btn-secondary btn-sm"
                                               href="articles.php?edit=<?= (int) $article['id'] ?>">编辑</a>
                                            <form class="inline-form" method="post" action="articles.php"
                                                  onsubmit="return confirm('确定删除这篇文章吗？');">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $article['id'] ?>">
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
    var editor = document.getElementById('articleEditor');
    var source = document.getElementById('articleSource');
    var textarea = document.getElementById('articleContent');
    var toolbar = document.getElementById('articleEditorToolbar');
    var toggle = document.getElementById('articleSourceToggle');
    var colorInput = document.getElementById('articleColor');

    if (!editor || !source || !textarea || !toolbar) {
        return;
    }

    var wrapper = editor.closest('.article-editor');
    var mode = 'visual';
    var savedRange = null;

    document.execCommand('styleWithCSS', false, false);

    document.addEventListener('selectionchange', function () {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return;
        }
        var range = selection.getRangeAt(0);
        if (editor.contains(range.commonAncestorContainer)) {
            savedRange = range.cloneRange();
        }
    });

    function restoreSelection() {
        if (!savedRange) {
            return;
        }
        var selection = window.getSelection();
        if (!selection) {
            return;
        }
        selection.removeAllRanges();
        selection.addRange(savedRange);
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (mode === 'visual') {
                source.value = editor.innerHTML;
                editor.hidden = true;
                source.hidden = false;
                mode = 'source';
                toggle.textContent = '可视化编辑';
            } else {
                editor.innerHTML = source.value;
                source.hidden = true;
                editor.hidden = false;
                mode = 'visual';
                toggle.textContent = 'HTML 源码';
            }
            if (wrapper) {
                wrapper.classList.toggle('is-source', mode === 'source');
            }
        });
    }

    if (colorInput) {
        colorInput.addEventListener('change', function () {
            if (mode === 'source') {
                return;
            }
            editor.focus();
            restoreSelection();
            document.execCommand('styleWithCSS', false, true);
            document.execCommand('foreColor', false, colorInput.value);
            document.execCommand('styleWithCSS', false, false);
        });
    }

    toolbar.addEventListener('mousedown', function (event) {
        if (event.target.closest('button')) {
            event.preventDefault();
        }
    });

    toolbar.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || mode === 'source') {
            return;
        }

        var command = button.getAttribute('data-command');
        var value = button.getAttribute('data-value') || '';
        editor.focus();

        if (command === 'createLink') {
            var url = window.prompt('请输入链接地址', 'https://');
            if (!url) {
                return;
            }
            if (!/^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url)) {
                url = 'https://' + url;
            }
            document.execCommand('createLink', false, url);
            var links = editor.querySelectorAll('a');
            for (var i = 0; i < links.length; i += 1) {
                if (links[i].getAttribute('href') === url) {
                    links[i].setAttribute('target', '_blank');
                    links[i].setAttribute('rel', 'noopener');
                }
            }
            return;
        }

        if (command === 'insertImage') {
            var src = window.prompt('请输入图片地址', 'https://');
            if (!src) {
                return;
            }
            document.execCommand('insertHTML', false, '<img src="' + src.replace(/"/g, '&quot;') + '" alt="">');
            return;
        }

        if (command === 'formatBlock') {
            document.execCommand('formatBlock', false, '<' + value + '>');
            return;
        }

        document.execCommand(command, false, value || null);
    });

    var picker = document.getElementById('activityPicker');
    if (picker) {
        picker.addEventListener('change', function () {
            if (!picker.value) {
                return;
            }
            if (mode === 'source') {
                source.value += '\n<p>[activity:' + picker.value + ']</p>';
            } else {
                editor.insertAdjacentHTML('beforeend', '<p>[activity:' + picker.value + ']</p>');
            }
            picker.value = '';
        });
    }

    var form = editor.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            textarea.value = mode === 'source' ? source.value : editor.innerHTML;
        });
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

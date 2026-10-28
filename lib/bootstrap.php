<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.gc_maxlifetime', '28800');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const STATUS_PENDING   = 'pending';
const STATUS_RE_REVIEW = 're_review';
const STATUS_APPROVED  = 'approved';
const STATUS_REJECTED  = 'rejected';

/**
 * HTML 转义
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * 站点文案配置
 *
 * 由项目根目录的 site-config.php 提供；该文件不存在时使用默认文案，
 * 便于直接删除自定义配置后再上传或分享项目。
 *
 * @return array<string, mixed>
 */
function site_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $config = [
        'header_title'    => '校园广播站',
        'header_subtitle' => '广播室音乐点播系统',
        'footer_text'     => '校园广播站 · 广播室音乐点播系统',
        'footer_support'  => '',
        'disclaimer_title'   => '校园广播点播上传须知',
        'disclaimer_intro'   => '为保障校园广播内容安全、健康、有序，请在提交前认真阅读以下要求：',
        'disclaimer_rules'   => [
            '内容要求：不得含有违反公序良俗、低俗色情、暴力恐怖、宗教敏感、政治敏感等内容。',
            '版权要求：请确认所选内容适合校园广播；因版权或内容问题产生的责任由上传人承担。',
            '审核要求：所有曲目须经广播室审核通过后方可播放；广播室有权拒绝或下架不合规曲目。',
            '信息要求：上传人须填写真实姓名和学号，便于内容追溯与联系。',
        ],
        'disclaimer_warning' => '提交即表示你已阅读并同意以上要求，并对所上传内容负责。',
    ];

    $path = dirname(__DIR__) . '/site-config.php';
    $custom = is_file($path) ? require $path : [];
    if (!is_array($custom)) {
        $custom = [];
    }

    foreach ($config as $key => $default) {
        if (array_key_exists($key, $custom)) {
            $config[$key] = $custom[$key];
        }
    }

    return $config;
}

/**
 * 读取站点文案
 */
function site_text(string $key): string
{
    $value = site_config()[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * 字符长度（兼容未安装 mbstring 的环境）
 */
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * 输出致命错误页面并退出
 */
function render_fatal_error(string $message, string $detail = ''): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $message = h($message);
    $detail  = h($detail);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>系统连接失败</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#f3f6fb;font-family:"Microsoft YaHei","PingFang SC",sans-serif;color:#172033}'
        . '.box{max-width:620px;padding:36px;background:#fff;border-radius:16px;'
        . 'box-shadow:0 18px 50px rgba(23,32,51,.12);line-height:1.8}'
        . 'h1{margin:0 0 12px;font-size:22px;color:#b42318}'
        . 'code{background:#f4f6fa;padding:2px 6px;border-radius:5px;color:#0f3d5e}'
        . 'pre{white-space:pre-wrap;background:#f8fafc;padding:12px;border-radius:8px;font-size:13px;color:#475467}'
        . '</style></head><body><div class="box"><h1>数据库连接失败</h1>'
        . '<p>' . $message . '</p>'
        . '<p>请检查 <code>config.php</code> 中的数据库地址、用户名和密码，并确认 MySQL 服务已启动。'
        . '若数据库账号没有自动建库权限，可先手动导入项目根目录的 <code>install.sql</code>。</p>';
    if ($detail !== '') {
        echo '<pre>' . $detail . '</pre>';
    }
    echo '</div></body></html>';
    exit;
}

/**
 * 获取 PDO 连接（首次调用时自动创建数据库与数据表）
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require dirname(__DIR__) . '/config.php';
    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $config['database']);
    if ($dbName === '') {
        render_fatal_error('config.php 中的数据库名称不合法。');
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $dsnBase = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']);
    $dsnDb   = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $dbName
    );

    try {
        try {
            $pdo = new PDO($dsnDb, $config['username'], $config['password'], $options);
        } catch (PDOException $e) {
            // 1049 = 数据库不存在；此时才尝试自动建库，避免已有库要求额外建库权限。
            if ((int) ($e->errorInfo[1] ?? 0) !== 1049) {
                throw $e;
            }

            $server = new PDO($dsnBase, $config['username'], $config['password'], $options);
            $server->exec(
                "CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            $server = null;

            $pdo = new PDO($dsnDb, $config['username'], $config['password'], $options);
        }

        init_schema($pdo);
    } catch (Throwable $e) {
        render_fatal_error('无法连接到 MySQL 数据库或初始化数据表。', $e->getMessage());
    }

    return $pdo;
}

/**
 * 首次运行自动建表，并兼容旧版本数据表升级
 */
function init_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key`   VARCHAR(64)  NOT NULL,
            `setting_value` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `songs` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `class_name`       VARCHAR(50)  NOT NULL COMMENT '班级名称',
            `uploader_name`    VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '上传人姓名',
            `uploader_no`      VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '上传人学号',
            `title`            VARCHAR(100) NOT NULL COMMENT '歌曲名称',
            `artist`           VARCHAR(100) NOT NULL DEFAULT '' COMMENT '歌曲作者/演唱者',
            `bvid`             CHAR(12)     NULL COMMENT 'B站 BV 号，未匹配时为空',
            `source_input`     VARCHAR(500) NOT NULL DEFAULT '' COMMENT '播放链接来源',
            `remark`           VARCHAR(255) NOT NULL DEFAULT '' COMMENT '广播备注',
            `sort_order`       INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '班内播放顺序',
            `status`           ENUM('pending', 're_review', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            `duration`         INT UNSIGNED NULL COMMENT '视频时长（秒）',
            `progress_seconds` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上次播放位置（秒）',
            `reviewer_name`    VARCHAR(50)  NULL COMMENT '审核人姓名',
            `reviewer_no`      VARCHAR(50)  NULL COMMENT '审核人工号/学号',
            `reject_reason`    VARCHAR(255) NULL COMMENT '拒绝原因',
            `reviewed_at`      DATETIME     NULL COMMENT '审核时间',
            `completed_at`     DATETIME     NULL COMMENT '播放完成标记时间',
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_class_order` (`class_name`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `verified_uploaders` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uid`        VARCHAR(20)  NOT NULL COMMENT 'B站用户 UID',
            `name`       VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '博主名称备注',
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_uid` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
            ('upload_open', '1'),
            ('max_per_class', '3')"
    );
    $pdo->exec(
        "INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
            ('upload_opened_at', NOW())"
    );

    // 管理员账号由首次使用时自行设置，不再写入默认账号。
    migrate_song_columns($pdo);
}

/**
 * 为旧版本数据库补充新增字段
 */
function migrate_song_columns(PDO $pdo): void
{
    $columns    = $pdo->query('SHOW COLUMNS FROM `songs`')->fetchAll();
    $existing   = [];
    $statusType = '';
    $bvidNullable = true;

    foreach ($columns as $column) {
        $existing[$column['Field']] = true;
        if ($column['Field'] === 'status') {
            $statusType = (string) $column['Type'];
        }
        if ($column['Field'] === 'bvid') {
            $bvidNullable = strtoupper((string) $column['Null']) === 'YES';
        }
    }

    $additions = [
        'uploader_name'    => "ADD COLUMN `uploader_name` VARCHAR(50) NOT NULL DEFAULT '' AFTER `class_name`",
        'uploader_no'      => "ADD COLUMN `uploader_no` VARCHAR(50) NOT NULL DEFAULT '' AFTER `uploader_name`",
        'artist'           => "ADD COLUMN `artist` VARCHAR(100) NOT NULL DEFAULT '' AFTER `title`",
        'remark'           => "ADD COLUMN `remark` VARCHAR(255) NOT NULL DEFAULT '' AFTER `source_input`",
        'progress_seconds' => "ADD COLUMN `progress_seconds` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `duration`",
        'completed_at'     => "ADD COLUMN `completed_at` DATETIME NULL AFTER `reviewed_at`",
        'reject_reason'    => "ADD COLUMN `reject_reason` VARCHAR(255) NULL AFTER `reviewer_no`",
    ];

    foreach ($additions as $name => $sql) {
        if (!isset($existing[$name])) {
            $pdo->exec('ALTER TABLE `songs` ' . $sql);
        }
    }

    if (!$bvidNullable) {
        $pdo->exec('ALTER TABLE `songs` MODIFY COLUMN `bvid` CHAR(12) NULL');
    }

    if ($statusType !== '' && stripos($statusType, "'rejected'") === false) {
        $pdo->exec(
            "ALTER TABLE `songs`
             MODIFY COLUMN `status` ENUM('pending', 're_review', 'approved', 'rejected') NOT NULL DEFAULT 'pending'"
        );
    }
}

/**
 * 读取系统设置
 */
function setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT `setting_value` FROM `settings` WHERE `setting_key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

/**
 * 保存系统设置
 */
function update_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `setting_value` = ?'
    );
    $stmt->execute([$key, $value, $value]);
}

/**
 * 写入一次性提示消息
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message];
}

/**
 * 取出并清空提示消息
 */
function pull_flashes(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);

    return is_array($messages) ? $messages : [];
}

/**
 * 跳转
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * CSRF Token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        exit('页面已过期，请返回刷新后重试。');
    }
}

/**
 * 输出 JSON 并结束请求
 */
function json_response(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * API 接口的 CSRF 校验（失败时返回 JSON）
 */
function verify_csrf_json(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        json_response(['ok' => false, 'message' => '页面已过期，请刷新后重试。'], 419);
    }
}

/**
 * 状态名称与样式
 */
function status_label(string $status): string
{
    if ($status === STATUS_APPROVED) {
        return '已审核 / 可播放';
    }
    if ($status === STATUS_RE_REVIEW) {
        return '待重新审核';
    }
    if ($status === STATUS_REJECTED) {
        return '未通过';
    }

    return '待审核';
}

function status_class(string $status): string
{
    if ($status === STATUS_APPROVED) {
        return 'badge-approved';
    }
    if ($status === STATUS_RE_REVIEW) {
        return 'badge-re-review';
    }
    if ($status === STATUS_REJECTED) {
        return 'badge-rejected';
    }

    return 'badge-pending';
}

/**
 * 管理员是否已登录
 */
function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_authenticated']) && admin_credentials_configured();
}

/**
 * 管理员账号是否已完成首次设置
 */
function admin_credentials_configured(): bool
{
    $accountHash  = setting('admin_username_hash', '');
    $passwordHash = setting('admin_password_hash', '');

    if ($accountHash === '' || $passwordHash === '') {
        return false;
    }

    return $accountHash !== ADMIN_LEGACY_USERNAME_HASH
        && $passwordHash !== ADMIN_LEGACY_PASSWORD_HASH;
}

/**
 * 保存管理员账号与密码（仅保存哈希）
 */
function admin_set_credentials(string $account, string $password): void
{
    update_setting('admin_username_hash', (string) password_hash($account, PASSWORD_DEFAULT));
    update_setting('admin_password_hash', (string) password_hash($password, PASSWORD_DEFAULT));
}

/**
 * 校验管理员账号与密码（数据库只保存哈希）
 */
function admin_login(string $account, string $password): bool
{
    if (!admin_credentials_configured()) {
        return false;
    }

    $accountHash  = setting('admin_username_hash', '');
    $passwordHash = setting('admin_password_hash', '');

    return password_verify($account, $accountHash)
        && password_verify($password, $passwordHash);
}

/**
 * 统一文本用于歌曲标题匹配
 */
function normalize_match_text(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    $normalized = preg_replace('/[\s\p{P}\p{S}]+/u', '', $value);

    return $normalized === null ? '' : $normalized;
}

/**
 * 已验证博主列表
 */
function get_verified_uploaders(PDO $db): array
{
    return $db->query(
        'SELECT `id`, `uid`, `name`, `created_at` FROM `verified_uploaders` ORDER BY `id` ASC'
    )->fetchAll();
}

/**
 * 通过 B 站搜索页获取视频卡片（服务端渲染 HTML，无需登录）
 *
 * @return array<int, array{bvid: string, title: string, author: string, mid: string}>
 */
function search_bilibili_videos(string $keyword): array
{
    $keyword = trim($keyword);
    if ($keyword === '' || !class_exists('DOMDocument')) {
        return [];
    }

    $url = 'https://search.bilibili.com/all?keyword=' . rawurlencode($keyword);
    $html = fetch_url($url, [
        'Referer: https://www.bilibili.com/',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9',
    ], 10);

    if ($html === null) {
        return [];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $cards = $xpath->query('//div[contains(@class,"bili-video-card__wrap")]');
    if ($cards === false) {
        return [];
    }

    $videos = [];
    $seen   = [];

    foreach ($cards as $card) {
        $link = $xpath->query('.//a[contains(@href,"/video/BV")]', $card)->item(0);
        $titleNode = $xpath->query('.//h3[contains(@class,"bili-video-card__info--tit")]', $card)->item(0);
        $ownerNode = $xpath->query('.//a[contains(@class,"bili-video-card__info--owner")]', $card)->item(0);
        $authorNode = $xpath->query('.//span[contains(@class,"bili-video-card__info--author")]', $card)->item(0);

        if (!$link || !$titleNode || !$ownerNode) {
            continue;
        }

        if (!preg_match('/BV[0-9A-Za-z]{10}/', $link->getAttribute('href'), $bvidMatch)) {
            continue;
        }
        if (!preg_match('/space\.bilibili\.com\/(\d+)/', $ownerNode->getAttribute('href'), $midMatch)) {
            continue;
        }

        $bvid = $bvidMatch[0];
        if (isset($seen[$bvid])) {
            continue;
        }
        $seen[$bvid] = true;

        $title = $titleNode->getAttribute('title');
        if ($title === '') {
            $title = $titleNode->textContent;
        }

        $videos[] = [
            'bvid'   => $bvid,
            'title'  => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'author' => $authorNode ? trim($authorNode->textContent) : '',
            'mid'    => $midMatch[1],
        ];
    }

    return $videos;
}

/**
 * 在所有已验证博主中查找歌曲
 */
function find_verified_video(PDO $db, string $title, string $artist): ?array
{
    $uploaders = get_verified_uploaders($db);
    if (!$uploaders) {
        return null;
    }

    $titleNorm = normalize_match_text($title);
    if ($titleNorm === '') {
        return null;
    }
    $artistNorm = normalize_match_text($artist);

    $verifiedMap = [];
    foreach ($uploaders as $uploader) {
        $verifiedMap[(string) $uploader['uid']] = (string) $uploader['name'];
    }

    $videos = search_bilibili_videos($title);
    if (!$videos) {
        return null;
    }

    $best      = null;
    $bestScore = -1;

    foreach ($videos as $video) {
        $mid = (string) $video['mid'];
        if (!isset($verifiedMap[$mid])) {
            continue;
        }

        $videoTitleNorm = normalize_match_text($video['title']);
        if ($videoTitleNorm === '') {
            continue;
        }

        $score = -1;
        if ($videoTitleNorm === $titleNorm) {
            $score = 100;
        } elseif (strpos($videoTitleNorm, $titleNorm) !== false) {
            $score = 80;
        } elseif (text_length($videoTitleNorm) >= 2 && strpos($titleNorm, $videoTitleNorm) !== false) {
            $score = 50;
        }

        if ($score < 0) {
            continue;
        }

        $authorNorm = normalize_match_text($video['author']);
        if ($artistNorm !== '' && $authorNorm !== '' && strpos($authorNorm, $artistNorm) !== false) {
            $score += 20;
        }
        if ($artistNorm !== '' && strpos($videoTitleNorm, $artistNorm) !== false) {
            $score += 10;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'bvid'        => $video['bvid'],
                'title'       => $video['title'],
                'author'      => $video['author'],
                'uid'         => $mid,
                'uploader'    => $verifiedMap[$mid],
            ];
        }
    }

    return $best;
}

/**
 * 从分享文本 / 链接 / BV 号中提取 BV 号
 */
function extract_bvid(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (preg_match('/BV[0-9A-Za-z]{10}/', $input, $matches)) {
        return $matches[0];
    }

    if (!preg_match('#https?://[^\s<>"\']+#i', $input, $matches)) {
        return null;
    }

    $url = rtrim($matches[0], '，。；、！？）》】」\'"，.');
    if (preg_match('#(?:b23\.tv|bili2233\.cn)#i', $url)) {
        return resolve_short_link_bvid($url);
    }

    return null;
}

/**
 * 执行 curl 请求；若本机缺少 CA 证书导致 SSL 校验失败，则降级重试。
 * 仅用于读取 B 站公开元数据，不涉及账号或隐私数据。
 */
function curl_exec_with_ssl_fallback($ch)
{
    $body = curl_exec($ch);
    if ($body === false && in_array(curl_errno($ch), [35, 60], true)) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $body = curl_exec($ch);
    }

    return $body;
}

/**
 * 解析 b23.tv 等短链接，返回其中的 BV 号
 */
function resolve_short_link_bvid(string $url): ?string
{
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => $userAgent,
        ]);
        $body = curl_exec_with_ssl_fallback($ch);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (preg_match('/BV[0-9A-Za-z]{10}/', $finalUrl, $matches)) {
            return $matches[0];
        }
        if (is_string($body) && preg_match('/BV[0-9A-Za-z]{10}/', $body, $matches)) {
            return $matches[0];
        }

        return null;
    }

    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 6,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'header'          => "User-Agent: {$userAgent}\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);

    if (is_string($body) && preg_match('/BV[0-9A-Za-z]{10}/', $body, $matches)) {
        return $matches[0];
    }

    return null;
}

/**
 * 简单 HTTP GET
 */
function fetch_url(string $url, array $headers = [], int $timeout = 6): ?string
{
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: */*'], $headers),
        ]);
        $body = curl_exec_with_ssl_fallback($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($body !== false && $status > 0 && $status < 400) ? (string) $body : null;
    }

    $headerLines = array_merge(["User-Agent: {$userAgent}", 'Accept: */*'], $headers);
    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 3,
            'header'          => implode("\r\n", $headerLines),
        ],
    ]);
    $body = @file_get_contents($url, false, $context);

    return $body === false ? null : $body;
}

/**
 * 通过 B 站公开接口获取视频时长（秒），失败返回 null
 */
function fetch_bilibili_duration(string $bvid): ?int
{
    $url = 'https://api.bilibili.com/x/web-interface/view?bvid=' . rawurlencode($bvid);
    $json = fetch_url($url, [
        'Referer: https://www.bilibili.com/',
        'Origin: https://www.bilibili.com',
        'Accept: application/json, text/plain, */*',
    ]);

    if ($json === null) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || (int) ($data['code'] ?? -1) !== 0) {
        return null;
    }

    // 播放页固定播放 page=1，多 P 视频优先取第一 P 的时长。
    $pages = $data['data']['pages'] ?? null;
    if (is_array($pages) && isset($pages[0]['duration'])) {
        $duration = (int) $pages[0]['duration'];
    } else {
        $duration = (int) ($data['data']['duration'] ?? 0);
    }

    return $duration > 0 ? $duration : null;
}

/**
 * 班内待播放（未标记完成）的歌曲数量
 */
function count_active_class_songs(PDO $db, string $className): int
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM `songs` WHERE `class_name` = ? AND `completed_at` IS NULL'
    );
    $stmt->execute([$className]);

    return (int) $stmt->fetchColumn();
}

/**
 * 班内占用提交名额的歌曲数量（未完成且未拒绝）
 */
function count_quota_class_songs(PDO $db, string $className): int
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM `songs`
         WHERE `class_name` = ? AND `completed_at` IS NULL AND `status` <> ?'
    );
    $stmt->execute([$className, STATUS_REJECTED]);

    return (int) $stmt->fetchColumn();
}

/**
 * 班内待播放歌曲 ID（按播放顺序）
 */
function active_class_song_ids(PDO $db, string $className): array
{
    $stmt = $db->prepare(
        'SELECT `id` FROM `songs`
         WHERE `class_name` = ? AND `completed_at` IS NULL
         ORDER BY `sort_order` ASC, `id` ASC'
    );
    $stmt->execute([$className]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 班内已完成歌曲 ID（历史记录，按原顺序）
 */
function completed_class_song_ids(PDO $db, string $className): array
{
    $stmt = $db->prepare(
        'SELECT `id` FROM `songs`
         WHERE `class_name` = ? AND `completed_at` IS NOT NULL
         ORDER BY `sort_order` ASC, `id` ASC'
    );
    $stmt->execute([$className]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 查询单条歌曲
 */
function find_song(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM `songs` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$id]);
    $song = $stmt->fetch();

    return $song === false ? null : $song;
}

/**
 * 将待播放歌曲移动到班内指定位置（已完成曲目统一排在后面）
 */
function reorder_active_song(PDO $db, string $className, int $songId, int $position): void
{
    $activeIds = active_class_song_ids($db, $className);
    $activeIds = array_values(array_filter(
        $activeIds,
        static fn (int $id): bool => $id !== $songId
    ));

    $position = max(1, min($position, count($activeIds) + 1));
    array_splice($activeIds, $position - 1, 0, [$songId]);

    $orderedIds = array_merge($activeIds, completed_class_song_ids($db, $className));
    $update = $db->prepare('UPDATE `songs` SET `sort_order` = ? WHERE `id` = ?');
    foreach ($orderedIds as $index => $id) {
        $update->execute([$index + 1, $id]);
    }
}

/**
 * 重新整理待播放顺序（删除后使用），已完成曲目保持在后
 */
function normalize_active_class_orders(PDO $db, string $className): void
{
    $orderedIds = array_merge(
        active_class_song_ids($db, $className),
        completed_class_song_ids($db, $className)
    );

    $update = $db->prepare('UPDATE `songs` SET `sort_order` = ? WHERE `id` = ?');
    foreach ($orderedIds as $index => $id) {
        $update->execute([$index + 1, $id]);
    }
}

/**
 * 某首待播放歌曲当前在班内的顺序号（1 开始）
 */
function active_song_position(PDO $db, string $className, int $songId): int
{
    $position = array_search($songId, active_class_song_ids($db, $className), true);

    return $position === false ? 0 : $position + 1;
}

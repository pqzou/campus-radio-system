<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (!admin_is_logged_in()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('无权限访问该文件。');
}

$db   = db();
$id   = (int) ($_GET['id'] ?? 0);
$mode = (string) ($_GET['mode'] ?? 'preview');
if ($mode !== 'download') {
    $mode = 'preview';
}

$stmt = $db->prepare(
    'SELECT `file_path`, `original_name`, `mime_type`, `name`, `class_name`
     FROM `activity_submissions` WHERE `id` = ? LIMIT 1'
);
$stmt->execute([$id]);
$entry = $stmt->fetch();

if ($entry === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('未找到该文件。');
}

$baseDir = realpath(__DIR__ . '/uploads/activities');
$fullPath = realpath(__DIR__ . '/' . (string) $entry['file_path']);

if (
    $baseDir === false
    || $fullPath === false
    || strpos($fullPath, $baseDir . DIRECTORY_SEPARATOR) !== 0
    || !is_file($fullPath)
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('文件不存在或已被删除。');
}

$fileName = (string) $entry['original_name'];
if ($fileName === '') {
    $fileName = basename($fullPath);
}

$downloadName = $fileName;
if ($mode === 'download') {
    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    $baseName  = trim((string) $entry['name']);
    $className = trim((string) $entry['class_name']);

    if ($baseName !== '' && $className !== '') {
        $baseName .= '-' . $className;
    } elseif ($baseName === '') {
        $baseName = $className;
    }

    $baseName = preg_replace('/[\\\\\\/:*?"<>|]+/u', '-', $baseName);
    $baseName = trim((string) $baseName, " .-");

    if ($baseName !== '') {
        $downloadName = $baseName . ($extension !== '' ? '.' . $extension : '');
    }
}

$extension = strtolower((string) pathinfo((string) $entry['original_name'], PATHINFO_EXTENSION));
$extensionMimeMap = [
    'mp4'  => 'video/mp4',
    'm4v'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'webm' => 'video/webm',
    'ogv'  => 'video/ogg',
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain; charset=utf-8',
];

$mimeType = trim((string) $entry['mime_type']);
$isGenericMime = $mimeType === ''
    || $mimeType === 'application/octet-stream'
    || $mimeType === 'binary/octet-stream'
    || preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType) !== 1;

if ($isGenericMime && isset($extensionMimeMap[$extension])) {
    $mimeType = $extensionMimeMap[$extension];
}

if ($isGenericMime && !isset($extensionMimeMap[$extension])) {
    $detected = function_exists('mime_content_type') ? @mime_content_type($fullPath) : false;
    $mimeType = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
}

$size  = (int) filesize($fullPath);
$start = 0;
$end   = $size > 0 ? $size - 1 : 0;
$isRange = false;

if ($size > 0 && !empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', (string) $_SERVER['HTTP_RANGE'], $matches) === 1) {
    $rangeStart = $matches[1];
    $rangeEnd   = $matches[2];

    if ($rangeStart === '' && $rangeEnd !== '') {
        $start = max(0, $size - (int) $rangeEnd);
        $end   = $size > 0 ? $size - 1 : 0;
    } else {
        $start = (int) $rangeStart;
        if ($rangeEnd !== '') {
            $end = min((int) $rangeEnd, $size - 1);
        }
    }

    if ($size === 0 || $start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    $isRange = true;
}

$dispositionName = rawurlencode($downloadName);

while (ob_get_level() > 0) {
    ob_end_clean();
}
session_write_close();

http_response_code($isRange ? 206 : 200);
header('Content-Type: ' . $mimeType);
if ($mode === 'download') {
    header(
        'Content-Disposition: attachment; filename="' . $dispositionName
        . '"; filename*=UTF-8\'\'' . $dispositionName
    );
}
header('Content-Length: ' . ($size > 0 ? $end - $start + 1 : 0));
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

if ($isRange) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

if ($size === 0) {
    exit;
}

$handle = fopen($fullPath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, (int) min(65536, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);

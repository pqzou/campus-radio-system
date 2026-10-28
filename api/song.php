<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => '请求方式不正确。'], 405);
}

verify_csrf_json();

$db     = db();
$songId = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

try {
    $song = find_song($db, $songId);
    if ($song === null) {
        json_response(['ok' => false, 'message' => '未找到该曲目。'], 404);
    }
    if ((string) $song['status'] !== STATUS_APPROVED) {
        json_response(['ok' => false, 'message' => '该曲目不在可播放状态。'], 409);
    }

    if ($action === 'progress') {
        $seconds = (int) ($_POST['seconds'] ?? 0);
        $seconds = max(0, $seconds);

        $duration = $song['duration'] !== null ? (int) $song['duration'] : 0;
        if ($duration > 0) {
            $seconds = min($seconds, $duration);
            // 临近结尾时视为已播完，下次从头开始。
            if ($seconds >= $duration - 3) {
                $seconds = 0;
            }
        }

        $stmt = $db->prepare(
            'UPDATE `songs` SET `progress_seconds` = ? WHERE `id` = ? AND `status` = ?'
        );
        $stmt->execute([$seconds, $songId, STATUS_APPROVED]);

        json_response(['ok' => true, 'progress' => $seconds]);
    }

    if ($action === 'complete') {
        $stmt = $db->prepare(
            'UPDATE `songs`
             SET `completed_at` = IFNULL(`completed_at`, NOW()), `progress_seconds` = 0
             WHERE `id` = ? AND `status` = ?'
        );
        $stmt->execute([$songId, STATUS_APPROVED]);

        $stmt = $db->prepare('SELECT `completed_at` FROM `songs` WHERE `id` = ?');
        $stmt->execute([$songId]);
        $completedAt = (string) $stmt->fetchColumn();

        json_response([
            'ok'           => true,
            'completed_at' => $completedAt,
            'display'      => substr($completedAt, 0, 16),
        ]);
    }

    if ($action === 'uncomplete') {
        $stmt = $db->prepare(
            'UPDATE `songs`
             SET `completed_at` = NULL
             WHERE `id` = ? AND `status` = ?'
        );
        $stmt->execute([$songId, STATUS_APPROVED]);

        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'message' => '未知操作。'], 422);
} catch (Throwable $e) {
    error_log('api/song.php: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => '操作失败，请稍后重试。'], 500);
}

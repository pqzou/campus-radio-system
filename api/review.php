<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => '请求方式不正确。'], 405);
}

verify_csrf_json();

$db           = db();
$songId       = (int) ($_POST['id'] ?? 0);
$decision     = (string) ($_POST['decision'] ?? '');
$reviewerName = trim((string) ($_POST['reviewer_name'] ?? ''));
$reviewerNo   = trim((string) ($_POST['reviewer_no'] ?? ''));
$rejectReason = trim((string) ($_POST['reject_reason'] ?? ''));
$remarkProvided = array_key_exists('remark', $_POST);
$remark         = trim((string) ($_POST['remark'] ?? ''));

try {
    if (!in_array($decision, ['approve', 'reject'], true)) {
        json_response(['ok' => false, 'message' => '审核结论不正确。'], 422);
    }
    if ($reviewerName === '' || text_length($reviewerName) > 50) {
        json_response(['ok' => false, 'message' => '请填写审核人姓名（不超过 50 个字符）。'], 422);
    }
    if ($reviewerNo === '' || text_length($reviewerNo) > 50) {
        json_response(['ok' => false, 'message' => '请填写审核人学号 / 工号（不超过 50 个字符）。'], 422);
    }
    if (text_length($rejectReason) > 200) {
        json_response(['ok' => false, 'message' => '拒绝原因不能超过 200 个字符。'], 422);
    }
    if ($remarkProvided && text_length($remark) > 200) {
        json_response(['ok' => false, 'message' => '广播备注不能超过 200 个字符。'], 422);
    }

    $song = find_song($db, $songId);
    if ($song === null) {
        json_response(['ok' => false, 'message' => '未找到该曲目。'], 404);
    }

    if (!in_array((string) $song['status'], [STATUS_PENDING, STATUS_RE_REVIEW], true)) {
        json_response(['ok' => false, 'message' => '该曲目已被处理，请刷新页面。'], 409);
    }

    $bvidInput   = trim((string) ($_POST['bvid_input'] ?? ''));
    $bvid        = !empty($song['bvid']) ? (string) $song['bvid'] : '';
    $sourceInput = (string) $song['source_input'];

    if ($bvidInput !== '') {
        $parsedBvid = extract_bvid($bvidInput);
        if ($parsedBvid === null) {
            json_response(['ok' => false, 'message' => '未识别到 BV 号，请补充完整的 B 站链接或 BV 号。'], 422);
        }
        $bvid        = $parsedBvid;
        $sourceInput = $bvidInput;
    }

    if ($decision === 'approve' && $bvid === '') {
        json_response(['ok' => false, 'message' => '该曲目尚未关联播放视频，请先补充 B 站链接或 BV 号。'], 422);
    }

    $duration = $song['duration'] !== null ? (int) $song['duration'] : null;
    if ($bvid !== '' && ($bvid !== (string) ($song['bvid'] ?? '') || $duration === null)) {
        $duration = fetch_bilibili_duration($bvid);
    }

    $newStatus = $decision === 'approve' ? STATUS_APPROVED : STATUS_REJECTED;
    $rejectReasonValue = $decision === 'reject' && $rejectReason !== '' ? $rejectReason : null;
    $remarkValue = $remarkProvided ? $remark : (string) $song['remark'];

    $stmt = $db->prepare(
        'UPDATE `songs`
         SET `bvid` = ?, `source_input` = ?, `remark` = ?, `duration` = ?, `status` = ?,
             `reviewer_name` = ?, `reviewer_no` = ?, `reject_reason` = ?, `reviewed_at` = NOW(),
             `completed_at` = NULL, `progress_seconds` = 0
         WHERE `id` = ? AND `status` IN (?, ?)'
    );
    $stmt->execute([
        $bvid !== '' ? $bvid : null,
        $sourceInput,
        $remarkValue,
        $duration,
        $newStatus,
        $reviewerName,
        $reviewerNo,
        $rejectReasonValue,
        $songId,
        STATUS_PENDING,
        STATUS_RE_REVIEW,
    ]);

    if ($stmt->rowCount() < 1) {
        json_response(['ok' => false, 'message' => '该曲目已被其他审核人处理，请刷新页面。'], 409);
    }

    json_response([
        'ok'           => true,
        'id'           => $songId,
        'status'       => $newStatus,
        'status_label' => status_label($newStatus),
        'bvid'         => $bvid,
        'remark'       => $remarkValue,
        'reject_reason' => $rejectReasonValue,
    ]);
} catch (Throwable $e) {
    error_log('api/review.php: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => '审核失败，请稍后重试。'], 500);
}

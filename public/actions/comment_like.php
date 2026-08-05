<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/ws_push.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');
$userId = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
require_csrf();

$db = get_db();
$commentId = filter_input(INPUT_POST, 'comment_id', FILTER_VALIDATE_INT);
if (!$commentId) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid comment.']);
    exit;
}

$stmt = $db->prepare('SELECT 1 FROM news_comment_likes WHERE comment_id = :cid AND user_id = :uid');
$stmt->execute([':cid' => $commentId, ':uid' => $userId]);
$liked = (bool) $stmt->fetch();

if ($liked) {
    $db->prepare('DELETE FROM news_comment_likes WHERE comment_id = :cid AND user_id = :uid')
       ->execute([':cid' => $commentId, ':uid' => $userId]);
} else {
    $db->prepare('INSERT OR IGNORE INTO news_comment_likes (comment_id, user_id) VALUES (:cid, :uid)')
       ->execute([':cid' => $commentId, ':uid' => $userId]);
}

$count = $db->prepare('SELECT COUNT(*) FROM news_comment_likes WHERE comment_id = :cid');
$count->execute([':cid' => $commentId]);
$total = (int) $count->fetchColumn();

$stmt = $db->prepare(
    'SELECT np.public_id FROM news_comments nc JOIN news_posts np ON np.id = nc.news_id WHERE nc.id = :cid'
);
$stmt->execute([':cid' => $commentId]);
$postPid = $stmt->fetchColumn();

push_to_websocket([
    'type' => 'broadcast',
    'payload' => ['type' => 'news_comment_like', 'comment_id' => $commentId, 'count' => $total, 'post_id' => $postPid],
]);

echo json_encode(['success' => true, 'liked' => !$liked, 'count' => $total]);

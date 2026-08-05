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
$postPid = trim((string) ($_POST['post_id'] ?? ''));
$comment = trim((string) ($_POST['comment'] ?? ''));

$stmt = $db->prepare('SELECT id FROM news_posts WHERE public_id = :pid');
$stmt->execute([':pid' => $postPid]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found.']);
    exit;
}
$postId = (int) $post['id'];

// Echo is a one-time action per user per post, not a toggle - check first
// rather than silently upserting every click (which used to let someone
// re-echo indefinitely with no visible effect other than a client-side
// count that drifted from the real server total).
$stmt = $db->prepare('SELECT 1 FROM news_echoes WHERE news_id = :nid AND user_id = :uid');
$stmt->execute([':nid' => $postId, ':uid' => $userId]);
$alreadyEchoed = (bool) $stmt->fetch();

if (!$alreadyEchoed) {
    $stmt = $db->prepare('INSERT INTO news_echoes (news_id, user_id, comment) VALUES (:nid, :uid, :comment)');
    $stmt->execute([':nid' => $postId, ':uid' => $userId, ':comment' => $comment]);
}

$countStmt = $db->prepare('SELECT COUNT(*) FROM news_echoes WHERE news_id = :nid');
$countStmt->execute([':nid' => $postId]);
$echoCount = (int) $countStmt->fetchColumn();

push_to_websocket([
    'type' => 'broadcast',
    'payload' => ['type' => 'news_echo', 'post_id' => $postPid, 'echoes' => $echoCount],
]);

echo json_encode(['success' => true, 'echoes' => $echoCount, 'already_echoed' => $alreadyEchoed]);

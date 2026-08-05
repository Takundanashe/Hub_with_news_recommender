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
$reaction = $_POST['reaction'] ?? '';

if (!in_array($reaction, ['like', 'dislike'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid reaction.']);
    exit;
}

$stmt = $db->prepare('SELECT id FROM news_posts WHERE public_id = :pid');
$stmt->execute([':pid' => $postPid]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found.']);
    exit;
}
$postId = (int) $post['id'];

$stmt = $db->prepare('SELECT reaction FROM news_reactions WHERE news_id = :nid AND user_id = :uid');
$stmt->execute([':nid' => $postId, ':uid' => $userId]);
$existing = $stmt->fetch();

if ($existing && $existing['reaction'] === $reaction) {
    // Toggling the same reaction again removes it.
    $stmt = $db->prepare('DELETE FROM news_reactions WHERE news_id = :nid AND user_id = :uid');
    $stmt->execute([':nid' => $postId, ':uid' => $userId]);
} else {
    $stmt = $db->prepare(
        'INSERT INTO news_reactions (news_id, user_id, reaction) VALUES (:nid, :uid, :reaction)
         ON CONFLICT(news_id, user_id) DO UPDATE SET reaction = :reaction'
    );
    $stmt->execute([':nid' => $postId, ':uid' => $userId, ':reaction' => $reaction]);
}

$counts = $db->prepare(
    "SELECT
        SUM(CASE WHEN reaction = 'like' THEN 1 ELSE 0 END) AS likes,
        SUM(CASE WHEN reaction = 'dislike' THEN 1 ELSE 0 END) AS dislikes
     FROM news_reactions WHERE news_id = :nid"
);
$counts->execute([':nid' => $postId]);
$row = $counts->fetch();
$likes = (int) $row['likes'];
$dislikes = (int) $row['dislikes'];

push_to_websocket([
    'type' => 'broadcast',
    'payload' => ['type' => 'news_reaction', 'post_id' => $postPid, 'likes' => $likes, 'dislikes' => $dislikes],
]);

echo json_encode(['success' => true, 'likes' => $likes, 'dislikes' => $dislikes]);

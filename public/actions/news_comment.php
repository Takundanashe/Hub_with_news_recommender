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
$body = trim((string) ($_POST['body'] ?? ''));
$parentCommentId = filter_input(INPUT_POST, 'parent_comment_id', FILTER_VALIDATE_INT) ?: null;

if ($body === '' || mb_strlen($body) > 1000) {
    http_response_code(422);
    echo json_encode(['error' => 'Comment is required (max 1000 characters).']);
    exit;
}

$stmt = $db->prepare('SELECT id, comments_enabled FROM news_posts WHERE public_id = :pid');
$stmt->execute([':pid' => $postPid]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    echo json_encode(['error' => 'Post not found.']);
    exit;
}
// Server-side enforcement of the owner's comment toggle - not just hidden in the UI.
if (!$post['comments_enabled']) {
    http_response_code(403);
    echo json_encode(['error' => 'Comments are turned off for this post.']);
    exit;
}

// If a parent was given, it must actually belong to this same post - otherwise
// a crafted request could nest a reply under an unrelated comment on a
// different post entirely.
if ($parentCommentId !== null) {
    $stmt = $db->prepare('SELECT 1 FROM news_comments WHERE id = :id AND news_id = :nid');
    $stmt->execute([':id' => $parentCommentId, ':nid' => (int) $post['id']]);
    if (!$stmt->fetch()) {
        http_response_code(422);
        echo json_encode(['error' => 'The comment you are replying to no longer exists.']);
        exit;
    }
}

$stmt = $db->prepare('INSERT INTO news_comments (news_id, user_id, parent_comment_id, body) VALUES (:nid, :uid, :parent, :body)');
$stmt->execute([':nid' => (int) $post['id'], ':uid' => $userId, ':parent' => $parentCommentId, ':body' => $body]);
$commentId = (int) $db->lastInsertId();

$stmt = $db->prepare("SELECT public_id, fname, avatar FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$author = $stmt->fetch();

$stmt = $db->prepare('SELECT created_at FROM news_comments WHERE id = :id');
$stmt->execute([':id' => $commentId]);
$createdAt = $stmt->fetchColumn();

// Total includes replies at every depth - the feed's badge and this page's
// header both show "how many comments total", not just top-level ones.
$commentCountStmt = $db->prepare('SELECT COUNT(*) FROM news_comments WHERE news_id = :nid');
$commentCountStmt->execute([':nid' => (int) $post['id']]);
$commentCount = (int) $commentCountStmt->fetchColumn();

$commentPayload = [
    'id' => $commentId,
    'post_id' => $postPid,
    'parent_comment_id' => $parentCommentId,
    'body' => $body,
    'created_at' => $createdAt,
    'like_count' => 0,
    'user' => [
        'public_id' => $author['public_id'],
        'fname' => $author['fname'],
        'avatar' => $author['avatar'],
    ],
    'comment_count' => $commentCount,
];

// Broadcast to every connected client (this is public feed content, not
// scoped to one recipient) so comments and their counts update live for
// everyone looking at the feed or this comments page, not just after their
// next reload.
push_to_websocket(['type' => 'broadcast', 'payload' => array_merge(['type' => 'news_comment'], $commentPayload)]);

echo json_encode(array_merge(['success' => true], $commentPayload));

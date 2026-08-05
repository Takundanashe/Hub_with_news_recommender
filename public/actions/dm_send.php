<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/ws_push.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');

$senderId = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
require_csrf();

$db = get_db();

$recipientPublicId = trim((string) ($_POST['recipient_id'] ?? ''));
$body = trim((string) ($_POST['body'] ?? ''));

if ($recipientPublicId === '' || $body === '') {
    http_response_code(422);
    echo json_encode(['error' => 'A recipient and message body are required.']);
    exit;
}
if (mb_strlen($body) > 4000) {
    http_response_code(422);
    echo json_encode(['error' => 'Message is too long.']);
    exit;
}

$stmt = $db->prepare('SELECT id, dm_permission FROM users WHERE public_id = :pid LIMIT 1');
$stmt->execute([':pid' => $recipientPublicId]);
$recipient = $stmt->fetch();

if (!$recipient) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}
$recipientId = (int) $recipient['id'];

if ($recipientId === $senderId) {
    http_response_code(422);
    echo json_encode(['error' => "You can't message yourself."]);
    exit;
}

// --- Enforce the recipient's DM privacy setting server-side. ---
// This check happens on every send, not just in the UI, so it can't be
// bypassed by calling this endpoint directly.
$permission = $recipient['dm_permission'];
$allowed = false;

if ($permission === 'everyone') {
    $allowed = true;
} elseif ($permission === 'followers') {
    // "followers" = recipient follows the sender back (i.e. sender is one
    // of the people the recipient chooses to hear from).
    $stmt = $db->prepare('SELECT 1 FROM follows WHERE follower_id = :recipient AND followed_id = :sender');
    $stmt->execute([':recipient' => $recipientId, ':sender' => $senderId]);
    $allowed = (bool) $stmt->fetch();
}
// 'no_one' -> $allowed stays false

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'This user is not accepting messages from you right now.']);
    exit;
}

$publicId = generate_public_id('dm');
$stmt = $db->prepare(
    'INSERT INTO direct_messages (public_id, sender_id, recipient_id, body) VALUES (:pid, :sender, :recipient, :body)'
);
$stmt->execute([
    ':pid' => $publicId,
    ':sender' => $senderId,
    ':recipient' => $recipientId,
    ':body' => $body, // stored as-is; escaped with e() at render time, never at storage time
]);
$seq = (int) $db->lastInsertId();

// Fetch sender's public_id/name for the push payload.
$stmt = $db->prepare('SELECT public_id, fname, lname, avatar FROM users WHERE id = :id');
$stmt->execute([':id' => $senderId]);
$sender = $stmt->fetch();

push_to_websocket([
    'type' => 'dm',
    'recipient_id' => $recipientId,
    'payload' => [
        'type' => 'dm',
        'id' => $publicId,
        'seq' => $seq, // lets the recipient's poll cursor skip ahead instead of re-fetching this same message
        'body' => $body,
        'from' => [
            'public_id' => $sender['public_id'],
            'name' => $sender['fname'] . ' ' . $sender['lname'],
            'avatar' => $sender['avatar'],
        ],
        'created_at' => gmdate('c'), // explicit UTC, matches SQLite's datetime('now')
    ],
]);

echo json_encode(['success' => true, 'message_id' => $publicId, 'seq' => $seq]);

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
$groupPublicId = trim((string) ($_POST['group_id'] ?? ''));
$body = trim((string) ($_POST['body'] ?? ''));

if ($body === '' || mb_strlen($body) > 4000) {
    http_response_code(422);
    echo json_encode(['error' => 'Message body is required (max 4000 characters).']);
    exit;
}

$stmt = $db->prepare('SELECT id FROM groups_table WHERE public_id = :pid');
$stmt->execute([':pid' => $groupPublicId]);
$group = $stmt->fetch();
if (!$group) {
    http_response_code(404);
    echo json_encode(['error' => 'Group not found.']);
    exit;
}
$groupId = (int) $group['id'];

// Must be a member to post - enforced server-side, not just hidden in the UI.
$stmt = $db->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid');
$stmt->execute([':gid' => $groupId, ':uid' => $userId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'You need to join this group to post.']);
    exit;
}

$stmt = $db->prepare('INSERT INTO group_messages (group_id, sender_id, body) VALUES (:gid, :uid, :body)');
$stmt->execute([':gid' => $groupId, ':uid' => $userId, ':body' => $body]);
$seq = (int) $db->lastInsertId();

$stmt = $db->prepare('SELECT fname, lname, avatar FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$sender = $stmt->fetch();

$stmt = $db->prepare('SELECT user_id FROM group_members WHERE group_id = :gid AND user_id != :me');
$stmt->execute([':gid' => $groupId, ':me' => $userId]);
$memberIds = array_map(static fn ($r) => (int) $r['user_id'], $stmt->fetchAll());

push_to_websocket([
    'type' => 'group',
    'member_ids' => $memberIds,
    'payload' => [
        'type' => 'group',
        'group_id' => $groupPublicId,
        'seq' => $seq, // lets recipients' poll cursor skip ahead instead of re-fetching this same message
        'body' => $body,
        'from' => $sender['fname'] . ' ' . $sender['lname'],
        'created_at' => gmdate('c'), // explicit UTC, matches SQLite's datetime('now')
    ],
]);

echo json_encode(['success' => true, 'seq' => $seq]);

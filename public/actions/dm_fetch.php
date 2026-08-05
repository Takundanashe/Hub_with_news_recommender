<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/ws_push.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');
$userId = require_login();

$db = get_db();
$withPublicId = trim((string) ($_GET['with'] ?? ''));

$stmt = $db->prepare('SELECT id, fname, lname, username, avatar, status FROM users WHERE public_id = :pid');
$stmt->execute([':pid' => $withPublicId]);
$other = $stmt->fetch();
if (!$other) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}
$otherId = (int) $other['id'];
$afterSeq = (int) ($_GET['after'] ?? 0);

$where = "is_deleted = 0 AND ((sender_id = :me AND recipient_id = :them) OR (sender_id = :them AND recipient_id = :me))";
if ($afterSeq > 0) {
    $where .= ' AND id > :after';
}

$stmt = $db->prepare(
    "SELECT id, public_id, sender_id, body, created_at, read_at
     FROM direct_messages
     WHERE {$where}
     ORDER BY id ASC
     LIMIT 200"
);
$params = [':me' => $userId, ':them' => $otherId];
if ($afterSeq > 0) {
    $params[':after'] = $afterSeq;
}
$stmt->execute($params);
$rows = $stmt->fetchAll();

$messages = array_map(static function (array $row) use ($userId) {
    return [
        'seq' => (int) $row['id'], // opaque cursor for '?after=' - not a user-facing id, just ordering
        'id' => $row['public_id'],
        'body' => $row['body'],
        'mine' => (int) $row['sender_id'] === $userId,
        'created_at' => $row['created_at'],
        'seen' => $row['read_at'] !== null,
    ];
}, $rows);

// Mark incoming messages as read.
$mark = $db->prepare(
    "UPDATE direct_messages SET read_at = datetime('now')
     WHERE sender_id = :them AND recipient_id = :me AND read_at IS NULL"
);
$mark->execute([':them' => $otherId, ':me' => $userId]);

// If any of the sender's messages just got marked read, tell their open
// chat tab right now - without this, they'd only see the tick flip to
// "seen" after leaving and re-opening the conversation (a fresh dm_fetch
// call re-reads the now-updated database), not live while it happens.
if ($mark->rowCount() > 0) {
    $meStmt = $db->prepare('SELECT public_id FROM users WHERE id = :id');
    $meStmt->execute([':id' => $userId]);
    $myPublicId = $meStmt->fetchColumn();

    push_to_websocket([
        'type' => 'dm',
        'recipient_id' => $otherId,
        'payload' => ['type' => 'dm_read', 'reader_public_id' => $myPublicId],
    ]);
}

echo json_encode([
    'other' => [
        'public_id' => $withPublicId,
        'name' => $other['fname'] . ' ' . $other['lname'],
        'avatar' => $other['avatar'],
        'status' => $other['status'],
    ],
    'messages' => $messages,
]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');
$userId = require_login();
$db = get_db();

$groupPublicId = trim((string) ($_GET['id'] ?? ''));
$afterSeq = (int) ($_GET['after'] ?? 0);

$stmt = $db->prepare('SELECT id FROM groups_table WHERE public_id = :pid');
$stmt->execute([':pid' => $groupPublicId]);
$group = $stmt->fetch();
if (!$group) {
    http_response_code(404);
    echo json_encode(['error' => 'Group not found.']);
    exit;
}
$groupId = (int) $group['id'];

// Anyone who can load the group page can poll it - membership is only
// required to post (enforced in group_message_send.php), same read/write
// split used everywhere else in the app.
$stmt = $db->prepare(
    'SELECT gm.id, gm.body, gm.created_at, gm.sender_id, u.fname, u.lname
     FROM group_messages gm JOIN users u ON u.id = gm.sender_id
     WHERE gm.group_id = :gid AND gm.is_deleted = 0 AND gm.id > :after
     ORDER BY gm.id ASC LIMIT 200'
);
$stmt->execute([':gid' => $groupId, ':after' => $afterSeq]);
$rows = $stmt->fetchAll();

$messages = array_map(static function (array $m) use ($userId) {
    return [
        'seq' => (int) $m['id'],
        'body' => $m['body'],
        'name' => $m['fname'] . ' ' . $m['lname'],
        'mine' => (int) $m['sender_id'] === $userId,
        'created_at' => $m['created_at'],
    ];
}, $rows);

echo json_encode(['success' => true, 'messages' => $messages]);

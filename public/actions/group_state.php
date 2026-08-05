<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');
$userId = require_login();

$db = get_db();
$groupPid = trim((string) ($_GET['group'] ?? ''));

$stmt = $db->prepare('SELECT id, public_id, name, avatar, privacy FROM groups_table WHERE public_id = :pid');
$stmt->execute([':pid' => $groupPid]);
$group = $stmt->fetch();
if (!$group) {
    http_response_code(404);
    echo json_encode(['error' => 'Group not found.']);
    exit;
}

$stmt = $db->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid');
$stmt->execute([':gid' => $group['id'], ':uid' => $userId]);
$isMember = (bool) $stmt->fetch();

echo json_encode([
    'public_id' => $group['public_id'],
    'name' => $group['name'],
    'avatar' => $group['avatar'],
    'privacy' => $group['privacy'],
    'is_member' => $isMember,
]);

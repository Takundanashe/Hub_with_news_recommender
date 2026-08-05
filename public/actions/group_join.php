<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
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

$stmt = $db->prepare('SELECT id, privacy FROM groups_table WHERE public_id = :pid');
$stmt->execute([':pid' => $groupPublicId]);
$group = $stmt->fetch();
if (!$group) {
    http_response_code(404);
    echo json_encode(['error' => 'Group not found.']);
    exit;
}
if ($group['privacy'] !== 'public') {
    http_response_code(403);
    echo json_encode(['error' => 'This group is invite-only.']);
    exit;
}

$stmt = $db->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id, role, last_read_at) VALUES (:gid, :uid, 'member', datetime('now'))");
$stmt->execute([':gid' => (int) $group['id'], ':uid' => $userId]);

echo json_encode(['success' => true]);

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

$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$privacy = $_POST['privacy'] ?? 'public';

if ($name === '' || mb_strlen($name) > 80) {
    http_response_code(422);
    echo json_encode(['error' => 'Group name is required (max 80 characters).']);
    exit;
}
if (!in_array($privacy, ['public', 'private'], true)) {
    $privacy = 'public';
}

$db = get_db();
$publicId = generate_public_id('grp');

$db->beginTransaction();
$stmt = $db->prepare(
    'INSERT INTO groups_table (public_id, name, description, privacy, creator_id) VALUES (:pid, :name, :desc, :privacy, :creator)'
);
$stmt->execute([':pid' => $publicId, ':name' => $name, ':desc' => $description, ':privacy' => $privacy, ':creator' => $userId]);
$groupId = (int) $db->lastInsertId();

$stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, role, last_read_at) VALUES (:gid, :uid, 'owner', datetime('now'))");
$stmt->execute([':gid' => $groupId, ':uid' => $userId]);
$db->commit();

echo json_encode(['success' => true, 'group_id' => $publicId]);

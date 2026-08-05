<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');
$userId = require_login();

$db = get_db();
$targetPid = trim((string) ($_GET['user'] ?? ''));

$stmt = $db->prepare('SELECT id, public_id, fname, lname, avatar, dm_permission FROM users WHERE public_id = :pid');
$stmt->execute([':pid' => $targetPid]);
$target = $stmt->fetch();
if (!$target) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}
$targetId = (int) $target['id'];

if ($targetId === $userId) {
    echo json_encode([
        'public_id' => $target['public_id'],
        'name' => $target['fname'] . ' ' . $target['lname'],
        'avatar' => $target['avatar'],
        'is_self' => true,
    ]);
    exit;
}

$stmt = $db->prepare('SELECT 1 FROM follows WHERE follower_id = :me AND followed_id = :them');
$stmt->execute([':me' => $userId, ':them' => $targetId]);
$isFollowing = (bool) $stmt->fetch();

$canMessage = false;
if ($target['dm_permission'] === 'everyone') {
    $canMessage = true;
} elseif ($target['dm_permission'] === 'followers') {
    $stmt = $db->prepare('SELECT 1 FROM follows WHERE follower_id = :them AND followed_id = :me');
    $stmt->execute([':them' => $targetId, ':me' => $userId]);
    $canMessage = (bool) $stmt->fetch();
}

echo json_encode([
    'public_id' => $target['public_id'],
    'name' => $target['fname'] . ' ' . $target['lname'],
    'avatar' => $target['avatar'],
    'is_self' => false,
    'is_following' => $isFollowing,
    'can_message' => $canMessage,
]);

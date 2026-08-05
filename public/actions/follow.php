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
$targetPublicId = trim((string) ($_POST['user_id'] ?? ''));
$action = $_POST['action'] ?? '';

$stmt = $db->prepare('SELECT id FROM users WHERE public_id = :pid');
$stmt->execute([':pid' => $targetPublicId]);
$target = $stmt->fetch();
if (!$target) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}
$targetId = (int) $target['id'];
if ($targetId === $userId) {
    http_response_code(422);
    echo json_encode(['error' => "You can't follow yourself."]);
    exit;
}

if ($action === 'follow') {
    $stmt = $db->prepare('INSERT OR IGNORE INTO follows (follower_id, followed_id) VALUES (:me, :them)');
    $stmt->execute([':me' => $userId, ':them' => $targetId]);
} elseif ($action === 'unfollow') {
    $stmt = $db->prepare('DELETE FROM follows WHERE follower_id = :me AND followed_id = :them');
    $stmt->execute([':me' => $userId, ':them' => $targetId]);
} else {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

echo json_encode(['success' => true]);

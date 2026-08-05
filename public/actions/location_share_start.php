<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/social.php';
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
$viewerPublicId = trim((string) ($_POST['viewer_id'] ?? ''));
$duration = $_POST['duration'] ?? '1_hour'; // '1_hour' | 'until_off'

$stmt = $db->prepare('SELECT id FROM users WHERE public_id = :pid');
$stmt->execute([':pid' => $viewerPublicId]);
$viewer = $stmt->fetch();
if (!$viewer) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}
$viewerId = (int) $viewer['id'];

// Location sharing is mutual-friends-only, on top of an explicit toggle -
// following someone never by itself grants location access.
if (!is_mutual_follow($db, $userId, $viewerId)) {
    http_response_code(403);
    echo json_encode(['error' => 'You can only share your location with mutual friends.']);
    exit;
}

$expiresAt = $duration === 'until_off' ? null : date('Y-m-d H:i:s', strtotime('+1 hour'));

$stmt = $db->prepare(
    'INSERT INTO location_shares (sharer_id, viewer_id, is_active, expires_at)
     VALUES (:sharer, :viewer, 1, :expires)
     ON CONFLICT(sharer_id, viewer_id) DO UPDATE SET is_active = 1, expires_at = :expires'
);
$stmt->execute([':sharer' => $userId, ':viewer' => $viewerId, ':expires' => $expiresAt]);

echo json_encode(['success' => true]);

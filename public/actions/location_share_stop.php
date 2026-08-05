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
$viewerPublicId = trim((string) ($_POST['viewer_id'] ?? ''));

$stmt = $db->prepare('SELECT id FROM users WHERE public_id = :pid');
$stmt->execute([':pid' => $viewerPublicId]);
$viewer = $stmt->fetch();
if (!$viewer) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}

$stmt = $db->prepare('UPDATE location_shares SET is_active = 0 WHERE sharer_id = :me AND viewer_id = :them');
$stmt->execute([':me' => $userId, ':them' => (int) $viewer['id']]);

echo json_encode(['success' => true]);

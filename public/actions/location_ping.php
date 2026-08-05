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

$lat = filter_input(INPUT_POST, 'lat', FILTER_VALIDATE_FLOAT);
$lng = filter_input(INPUT_POST, 'lng', FILTER_VALIDATE_FLOAT);
if ($lat === null || $lat === false || $lng === null || $lng === false) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid coordinates.']);
    exit;
}

$db = get_db();

// Only write into shares that are active AND not expired - an expired
// share silently stops recording rather than needing manual cleanup.
$stmt = $db->prepare(
    "SELECT id, viewer_id FROM location_shares
     WHERE sharer_id = :me AND is_active = 1
       AND (expires_at IS NULL OR expires_at > datetime('now'))"
);
$stmt->execute([':me' => $userId]);
$shares = $stmt->fetchAll();

$sharerRow = $db->prepare('SELECT public_id FROM users WHERE id = :id');
$sharerRow->execute([':id' => $userId]);
$sharerPublicId = $sharerRow->fetchColumn();

$insert = $db->prepare('INSERT INTO location_pings (share_id, latitude, longitude) VALUES (:sid, :lat, :lng)');
foreach ($shares as $share) {
    $insert->execute([':sid' => $share['id'], ':lat' => $lat, ':lng' => $lng]);

    push_to_websocket([
        'type' => 'location',
        'recipient_id' => (int) $share['viewer_id'],
        'payload' => ['type' => 'location', 'sharer_public_id' => $sharerPublicId, 'lat' => $lat, 'lng' => $lng],
    ]);
}

echo json_encode(['success' => true, 'active_shares' => count($shares)]);

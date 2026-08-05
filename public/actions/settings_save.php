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

$dmPermission = $_POST['dm_permission'] ?? 'everyone';
$phoneVisibility = $_POST['phone_visibility'] ?? 'private';
$emailVisibility = $_POST['email_visibility'] ?? 'private';
$phone = trim((string) ($_POST['phone'] ?? ''));

if (!in_array($dmPermission, ['everyone', 'followers', 'no_one'], true)
    || !in_array($phoneVisibility, ['public', 'private'], true)
    || !in_array($emailVisibility, ['public', 'private'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid settings value.']);
    exit;
}

$db = get_db();
$stmt = $db->prepare(
    "UPDATE users SET dm_permission = :dm, phone_visibility = :pv, email_visibility = :ev,
     phone = :phone, updated_at = datetime('now') WHERE id = :id"
);
$stmt->execute([
    ':dm' => $dmPermission,
    ':pv' => $phoneVisibility,
    ':ev' => $emailVisibility,
    ':phone' => $phone !== '' ? $phone : null,
    ':id' => $userId,
]);

echo json_encode(['success' => true]);

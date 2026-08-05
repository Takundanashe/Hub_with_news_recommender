<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/uploads.php';
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

$body = trim((string) ($_POST['body'] ?? ''));
$commentsEnabled = isset($_POST['comments_enabled']) ? 1 : 0;

if ($body === '' || mb_strlen($body) > 3000) {
    http_response_code(422);
    echo json_encode(['error' => 'Post text is required (max 3000 characters).']);
    exit;
}

$db = get_db();
$image = null;
if (!empty($_FILES['image']['name'])) {
    try {
        $image = handle_image_upload($_FILES['image'], __DIR__ . '/../uploads');
    } catch (RuntimeException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

$publicId = generate_public_id('news');
$stmt = $db->prepare(
    'INSERT INTO news_posts (public_id, author_id, body, image, comments_enabled) VALUES (:pid, :uid, :body, :img, :ce)'
);
$stmt->execute([':pid' => $publicId, ':uid' => $userId, ':body' => $body, ':img' => $image, ':ce' => $commentsEnabled]);

echo json_encode(['success' => true, 'post_id' => $publicId]);

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
$sellerPid = trim((string) ($_POST['seller_id'] ?? ''));
$listingPid = trim((string) ($_POST['listing_id'] ?? ''));
$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
$body = trim((string) ($_POST['body'] ?? ''));

if ($rating === null || $rating === false || $rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['error' => 'Rating must be between 1 and 5.']);
    exit;
}

$stmt = $db->prepare('SELECT id FROM users WHERE public_id = :pid');
$stmt->execute([':pid' => $sellerPid]);
$seller = $stmt->fetch();
if (!$seller) {
    http_response_code(404);
    echo json_encode(['error' => 'Seller not found.']);
    exit;
}
if ((int) $seller['id'] === $userId) {
    http_response_code(422);
    echo json_encode(['error' => "You can't review yourself."]);
    exit;
}

$listingId = null;
if ($listingPid !== '') {
    $stmt = $db->prepare('SELECT id FROM listings WHERE public_id = :pid');
    $stmt->execute([':pid' => $listingPid]);
    $listing = $stmt->fetch();
    $listingId = $listing ? (int) $listing['id'] : null;
}

$stmt = $db->prepare(
    'INSERT INTO listing_reviews (seller_id, reviewer_id, listing_id, rating, body) VALUES (:seller, :reviewer, :listing, :rating, :body)'
);
$stmt->execute([
    ':seller' => (int) $seller['id'], ':reviewer' => $userId, ':listing' => $listingId, ':rating' => $rating, ':body' => $body,
]);

echo json_encode(['success' => true]);

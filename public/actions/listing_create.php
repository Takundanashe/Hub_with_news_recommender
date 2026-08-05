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

$type = $_POST['type'] ?? '';
$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$price = $_POST['price'] ?? null;
$location = trim((string) ($_POST['location'] ?? ''));

if (!in_array($type, ['goods', 'job', 'lost_found', 'housing'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid listing type.']);
    exit;
}
if ($title === '' || mb_strlen($title) > 140) {
    http_response_code(422);
    echo json_encode(['error' => 'Title is required (max 140 characters).']);
    exit;
}

$price = ($price !== null && $price !== '') ? filter_var($price, FILTER_VALIDATE_FLOAT) : null;
if ($price === false) {
    http_response_code(422);
    echo json_encode(['error' => 'Price must be a number.']);
    exit;
}

$db = get_db();
$publicId = generate_public_id('lst');

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO listings (public_id, owner_id, type, title, description, price, location)
         VALUES (:pid, :owner, :type, :title, :desc, :price, :loc)'
    );
    $stmt->execute([
        ':pid' => $publicId, ':owner' => $userId, ':type' => $type,
        ':title' => $title, ':desc' => $description, ':price' => $price, ':loc' => $location,
    ]);
    $listingId = (int) $db->lastInsertId();

    if ($type === 'job') {
        $stmt = $db->prepare(
            'INSERT INTO listing_details_job (listing_id, employment_type, salary_min, salary_max, company_name)
             VALUES (:id, :emp, :smin, :smax, :company)'
        );
        $stmt->execute([
            ':id' => $listingId,
            ':emp' => $_POST['employment_type'] ?? null,
            ':smin' => is_numeric($_POST['salary_min'] ?? null) ? (float) $_POST['salary_min'] : null,
            ':smax' => is_numeric($_POST['salary_max'] ?? null) ? (float) $_POST['salary_max'] : null,
            ':company' => trim((string) ($_POST['company_name'] ?? '')),
        ]);
    } elseif ($type === 'housing') {
        $stmt = $db->prepare(
            'INSERT INTO listing_details_housing (listing_id, listing_purpose, bedrooms, bathrooms, lease_term)
             VALUES (:id, :purpose, :bed, :bath, :lease)'
        );
        $stmt->execute([
            ':id' => $listingId,
            ':purpose' => in_array($_POST['listing_purpose'] ?? '', ['rent', 'sale'], true) ? $_POST['listing_purpose'] : 'rent',
            ':bed' => is_numeric($_POST['bedrooms'] ?? null) ? (int) $_POST['bedrooms'] : null,
            ':bath' => is_numeric($_POST['bathrooms'] ?? null) ? (int) $_POST['bathrooms'] : null,
            ':lease' => trim((string) ($_POST['lease_term'] ?? '')),
        ]);
    } elseif ($type === 'lost_found') {
        $stmt = $db->prepare(
            'INSERT INTO listing_details_lost_found (listing_id, report_type, last_seen_at, last_seen_location)
             VALUES (:id, :rtype, :when, :where_)'
        );
        $stmt->execute([
            ':id' => $listingId,
            ':rtype' => in_array($_POST['report_type'] ?? '', ['lost', 'found'], true) ? $_POST['report_type'] : 'lost',
            ':when' => trim((string) ($_POST['last_seen_at'] ?? '')) ?: null,
            ':where_' => trim((string) ($_POST['last_seen_location'] ?? '')),
        ]);
    }

    // Images: up to 6, each re-encoded + EXIF-stripped by handle_image_upload().
    if (!empty($_FILES['images']['name'][0])) {
        $count = min(count($_FILES['images']['name']), 6);
        $order = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $file = [
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error' => $_FILES['images']['error'][$i],
                'size' => $_FILES['images']['size'][$i],
            ];
            $filename = handle_image_upload($file, __DIR__ . '/../uploads');
            $stmt = $db->prepare('INSERT INTO listing_images (listing_id, filename, sort_order) VALUES (:lid, :fn, :ord)');
            $stmt->execute([':lid' => $listingId, ':fn' => $filename, ':ord' => $order++]);
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage() ?: 'Could not create listing.']);
    exit;
}

echo json_encode(['success' => true, 'listing_id' => $publicId]);

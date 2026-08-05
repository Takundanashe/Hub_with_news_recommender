<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');
$userId = require_login();

$db = get_db();
$q = trim((string) ($_GET['q'] ?? ''));
$category = $_GET['category'] ?? 'all';
if (!in_array($category, ['all', 'friends', 'goods', 'jobs', 'lost_found', 'houses', 'groups'], true)) {
    $category = 'all';
}
if ($q === '') {
    echo json_encode(['results' => []]);
    exit;
}
$like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

$results = [];

if ($category === 'all' || $category === 'friends') {
    $stmt = $db->prepare(
        "SELECT public_id, fname, lname, username, avatar FROM users
         WHERE id != :me AND (fname LIKE :q ESCAPE '\\' OR lname LIKE :q ESCAPE '\\' OR username LIKE :q ESCAPE '\\')
         LIMIT 20"
    );
    $stmt->execute([':me' => $userId, ':q' => $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type' => 'user',
            'id' => $r['public_id'],
            'title' => $r['fname'] . ' ' . $r['lname'],
            'subtitle' => '@' . $r['username'],
            'image' => $r['avatar'],
        ];
    }
}

if ($category === 'all' || $category === 'goods') {
    $stmt = $db->prepare(
        "SELECT l.public_id, l.title, l.price, l.currency,
                (SELECT filename FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS thumb
         FROM listings l WHERE l.type = 'goods' AND l.status = 'active' AND l.title LIKE :q ESCAPE '\\' LIMIT 20"
    );
    $stmt->execute([':q' => $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type' => 'goods',
            'id' => $r['public_id'],
            'title' => $r['title'],
            'subtitle' => $r['price'] !== null ? $r['currency'] . ' ' . number_format((float) $r['price'], 0) : '',
            'image' => $r['thumb'] ?? 'default_listing.png',
        ];
    }
}

if ($category === 'all' || $category === 'houses') {
    $stmt = $db->prepare(
        "SELECT l.public_id, l.title, l.price, l.currency,
                (SELECT filename FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS thumb
         FROM listings l WHERE l.type = 'housing' AND l.status = 'active' AND l.title LIKE :q ESCAPE '\\' LIMIT 20"
    );
    $stmt->execute([':q' => $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type' => 'goods', // housing detail lives at the same /market/detail.php route
            'id' => $r['public_id'],
            'title' => $r['title'],
            'subtitle' => $r['price'] !== null ? $r['currency'] . ' ' . number_format((float) $r['price'], 0) : '',
            'image' => $r['thumb'] ?? 'default_listing.png',
        ];
    }
}

if ($category === 'all' || $category === 'jobs') {
    $stmt = $db->prepare(
        "SELECT l.public_id, l.title, l.price, l.currency,
                (SELECT filename FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS thumb,
                jd.company_name
         FROM listings l LEFT JOIN listing_details_job jd ON jd.listing_id = l.id
         WHERE l.type = 'job' AND l.status = 'active'
           AND (l.title LIKE :q ESCAPE '\\' OR jd.company_name LIKE :q ESCAPE '\\')
         LIMIT 20"
    );
    $stmt->execute([':q' => $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type' => 'goods', // job detail also lives at /market/detail.php
            'id' => $r['public_id'],
            'title' => $r['title'],
            'subtitle' => $r['company_name'] ?: ($r['price'] !== null ? $r['currency'] . ' ' . number_format((float) $r['price'], 0) : ''),
            'image' => $r['thumb'] ?? 'default_listing.png',
        ];
    }
}

if ($category === 'all' || $category === 'lost_found') {
    $stmt = $db->prepare(
        "SELECT l.public_id, l.title,
                (SELECT filename FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS thumb,
                lf.report_type
         FROM listings l LEFT JOIN listing_details_lost_found lf ON lf.listing_id = l.id
         WHERE l.type = 'lost_found' AND l.status = 'active' AND l.title LIKE :q ESCAPE '\\'
         LIMIT 20"
    );
    $stmt->execute([':q' => $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type' => 'goods',
            'id' => $r['public_id'],
            'title' => $r['title'],
            'subtitle' => $r['report_type'] ? ucfirst($r['report_type']) : '',
            'image' => $r['thumb'] ?? 'default_listing.png',
        ];
    }
}

if ($category === 'all' || $category === 'groups') {
    $stmt = $db->prepare(
        "SELECT public_id, name, avatar, privacy FROM groups_table WHERE name LIKE :q ESCAPE '\\' LIMIT 20"
    );
    $stmt->execute([':q' => $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type' => 'group',
            'id' => $r['public_id'],
            'title' => $r['name'],
            'subtitle' => ucfirst($r['privacy']) . ' group',
            'image' => $r['avatar'] ?: 'default_group.png',
        ];
    }
}

echo json_encode(['results' => $results]);

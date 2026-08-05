<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

require_csrf();

$db = get_db();

$fname    = trim((string) ($_POST['fname'] ?? ''));
$lname    = trim((string) ($_POST['lname'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$email    = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

// --- Validation (all server-side; never trust the client) ---
$errors = [];
if ($fname === '' || $lname === '') {
    $errors[] = 'First and last name are required.';
}
if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    $errors[] = 'Username must be 3-30 characters: letters, numbers, underscores only.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email address.';
}
if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

// Uniqueness checks - parameterized, no string concatenation into SQL.
$stmt = $db->prepare('SELECT id FROM users WHERE email = :email OR username = :username LIMIT 1');
$stmt->execute([':email' => $email, ':username' => $username]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'That email or username is already taken.']);
    exit;
}

// Optional avatar upload
$avatarFilename = 'default.png';
if (!empty($_FILES['avatar']['name'])) {
    try {
        $avatarFilename = handle_image_upload($_FILES['avatar'], __DIR__ . '/../uploads');
    } catch (RuntimeException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

try {
    $publicId = generate_public_id('usr');
    $passwordHash = password_hash($password, PASSWORD_DEFAULT); // bcrypt/argon2 depending on PHP build, salted automatically

    $stmt = $db->prepare(
        'INSERT INTO users (public_id, username, fname, lname, email, password_hash, avatar, status)
         VALUES (:public_id, :username, :fname, :lname, :email, :password_hash, :avatar, :status)'
    );
    $stmt->execute([
        ':public_id'      => $publicId,
        ':username'       => $username,
        ':fname'          => $fname,
        ':lname'          => $lname,
        ':email'          => $email,
        ':password_hash'  => $passwordHash,
        ':avatar'         => $avatarFilename,
        ':status'         => 'Active now',
    ]);

    $userId = (int) $db->lastInsertId();

    // Set up their wallet (in-app credits) at the same time.
    $moneyId = 'MID-' . strtoupper(bin2hex(random_bytes(4)));
    $stmt = $db->prepare('INSERT INTO wallets (user_id, money_id, balance_cents) VALUES (:user_id, :money_id, 0)');
    $stmt->execute([':user_id' => $userId, ':money_id' => $moneyId]);

    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user_id'] = $userId;
    $_SESSION['ws_token'] = issue_ws_session($db, $userId);

    echo json_encode(['success' => true, 'redirect' => '/index.php']);
} catch (\Throwable $e) {
    // Same class of bug as logout.php/login.php: an uncaught DB error here
    // becomes an empty response body, which the frontend's generic fetch
    // error-handler shows as "Network error" / "No Internet" instead of
    // the real cause.
    error_log('signup.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong on our end. Please try again in a moment.']);
}

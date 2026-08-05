<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
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

$email    = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($email === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Email and password are required.']);
    exit;
}

// Rate limit on both the email being targeted and the source IP -
// mirrors the nginx-level limiter as defense in depth.
if (too_many_login_attempts($db, $email) || too_many_login_attempts($db, $ip)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many attempts. Please wait a few minutes and try again.']);
    exit;
}

try {
    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Always run password_verify even on a missing user (against a dummy hash)
    // so response timing doesn't reveal whether the email exists.
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuuQnYQXJz1hWQXJz1hWQXJz1hWQXJz1h';
    $hashToCheck = $user['password_hash'] ?? $dummyHash;
    $valid = password_verify($password, $hashToCheck) && $user !== false;

    record_login_attempt($db, $email, $valid);
    record_login_attempt($db, $ip, $valid);

    if (!$valid) {
        http_response_code(401);
        echo json_encode(['error' => 'Email or password is incorrect.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['ws_token'] = issue_ws_session($db, (int) $user['id']);

    // "Remember me": extend the session cookie's lifetime to 30 days instead
    // of the default session-only (dies when the browser closes). Session
    // data itself is unaffected either way - this only changes how long the
    // browser keeps sending the cookie back.
    if (!empty($_POST['remember'])) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + 30 * 24 * 3600,
            'path' => $params['path'],
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
        ]);
    }

    $upd = $db->prepare('UPDATE users SET status = :status, updated_at = datetime(\'now\') WHERE id = :id');
    $upd->execute([':status' => 'Active now', ':id' => $user['id']]);

    echo json_encode(['success' => true, 'redirect' => '/index.php']);
} catch (\Throwable $e) {
    // Without this, a DB write failure here (locked file, permissions drift,
    // disk full) becomes an uncaught fatal with an empty response body -
    // which the frontend's generic fetch error-handler shows as "Network
    // error" / "No Internet", masking what's actually a server-side problem.
    error_log('login.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong on our end. Please try again in a moment.']);
}

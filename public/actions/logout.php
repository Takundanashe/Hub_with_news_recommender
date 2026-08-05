<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();

if (!empty($_SESSION['user_id'])) {
    try {
        $db = get_db();
        $stmt = $db->prepare('UPDATE users SET status = :status WHERE id = :id');
        $stmt->execute([':status' => 'Offline', ':id' => $_SESSION['user_id']]);

        if (!empty($_SESSION['ws_token'])) {
            $del = $db->prepare('DELETE FROM sessions WHERE id = :id');
            $del->execute([':id' => $_SESSION['ws_token']]);
        }
    } catch (\Throwable $e) {
        // Best-effort cleanup only - a locked/unreachable DB should never stop
        // someone from being able to log out. Without this try/catch, a
        // transient PDOException here becomes an uncaught fatal error with
        // no response body, which some mobile browsers surface as a generic
        // connection failure ("No internet") instead of a normal error page.
        error_log('logout.php: cleanup failed - ' . $e->getMessage());
    }
}

$_SESSION = [];
session_destroy();
header('Location: /login.php');
exit;

<?php
declare(strict_types=1);

/**
 * Generates a random opaque public identifier, safe to expose in URLs/JSON.
 * Never expose the autoincrement `id` column directly - it leaks row counts
 * and lets people enumerate other users'/listings' records sequentially.
 */
function generate_public_id(string $prefix = ''): string
{
    return ($prefix ? $prefix . '_' : '') . bin2hex(random_bytes(12));
}

/** Escapes a value for safe HTML output. Use on every piece of user content rendered into a page. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Starts (or resumes) a hardened session and applies baseline security headers. */
function start_secure_session(): void
{
    send_security_headers();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,   // JS can't read the session cookie
        'samesite' => 'Lax',  // baseline CSRF mitigation at the cookie level
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/** Returns a per-request CSP nonce, generating it once. Use in <script nonce="..."> for the few inline bootstrap scripts that pass server data to external JS files. */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * Defense-in-depth headers applied on every request, on top of the copies
 * set at the nginx level (server/nginx.conf) - having both means the app
 * is still protected even if it's ever served through a different proxy.
 */
function send_security_headers(): void
{
    static $sent = false;
    if ($sent || headers_sent()) {
        return;
    }
    $sent = true;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');                 // blocks this app being framed (clickjacking)
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    // CSP: only our own origin, the one CDN script the wallet page needs for
    // QR rendering, and a per-request nonce for the handful of tiny inline
    // scripts that hand data to external JS files. No 'unsafe-inline' for
    // scripts - that's what actually stops an injected <script> tag from
    // executing even if an escaping bug slipped through somewhere.
    $nonce = csp_nonce();
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net; " .
        "style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data:; " .
        "connect-src 'self' ws: wss:; " .
        "frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    );
}

/** Redirects to login and halts execution unless the user is authenticated. */
/**
 * Appends a cache-busting version (the file's mtime) to a static asset URL.
 * server/nginx.conf caches everything under /assets/ for 7 days with no
 * revalidation - without this, a browser that loaded a page before a JS/CSS
 * deploy keeps executing the OLD file against the NEW HTML for up to a week.
 */
function asset_url(string $path): string
{
    $fsPath = __DIR__ . '/../public' . $path;
    $version = is_file($fsPath) ? filemtime($fsPath) : time();
    return $path . '?v=' . $version;
}

function require_login(): int
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    return (int) $_SESSION['user_id'];
}

/** Generates (or reuses) a per-session CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verifies a submitted CSRF token using a timing-safe comparison. */
function verify_csrf(?string $submitted): bool
{
    return is_string($submitted)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Call at the top of any state-changing POST endpoint.
 * Sends a 403 and halts if the CSRF token is missing/invalid.
 */
function require_csrf(): void
{
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

/**
 * Basic login-attempt rate limiting: blocks after too many recent failures
 * for the same identifier (email or IP). Mirrors the nginx-level limit as
 * defense in depth.
 */
function too_many_login_attempts(PDO $db, string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS c FROM login_attempts
         WHERE identifier = :id AND success = 0
           AND attempted_at > datetime('now', :window)"
    );
    $stmt->execute([':id' => $identifier, ':window' => "-{$windowSeconds} seconds"]);
    return (int) $stmt->fetch()['c'] >= $maxAttempts;
}

function record_login_attempt(PDO $db, string $identifier, bool $success): void
{
    $stmt = $db->prepare('INSERT INTO login_attempts (identifier, success) VALUES (:id, :success)');
    $stmt->execute([':id' => $identifier, ':success' => $success ? 1 : 0]);
}

/**
 * Issues a row in the `sessions` table and returns its token. This is
 * SEPARATE from PHP's own session cookie - it exists so the standalone
 * WebSocket process (server/websocket/) can authenticate a connection by
 * looking the token up directly in SQLite, without needing access to
 * PHP's file-based session store. The token is embedded server-side into
 * the chat page for the JS client to connect with (see chat.php).
 */
function issue_ws_session(PDO $db, int $userId, int $days = 7): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare(
        "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at)
         VALUES (:id, :user_id, :ip, :ua, datetime('now', :window))"
    );
    $stmt->execute([
        ':id' => $token,
        ':user_id' => $userId,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ':window' => "+{$days} days",
    ]);
    return $token;
}

/**
 * Returns a valid WebSocket auth token for this user, self-healing if the
 * one already in the PHP session no longer exists in the `sessions` table
 * (e.g. the database was recreated/reset after login) or has expired.
 * Without this, a stale token means the socket connects, silently fails
 * server-side auth, and closes - real-time push then never works again
 * until a fresh login, with no visible error to explain why.
 */
function ensure_ws_session(PDO $db, int $userId): string
{
    $token = $_SESSION['ws_token'] ?? null;
    if ($token) {
        $stmt = $db->prepare(
            "SELECT 1 FROM sessions WHERE id = :id AND user_id = :uid AND expires_at > datetime('now')"
        );
        $stmt->execute([':id' => $token, ':uid' => $userId]);
        if ($stmt->fetch()) {
            return $token;
        }
    }
    $token = issue_ws_session($db, $userId);
    $_SESSION['ws_token'] = $token;
    return $token;
}

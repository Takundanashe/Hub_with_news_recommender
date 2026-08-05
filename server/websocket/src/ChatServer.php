<?php

declare(strict_types=1);

namespace App;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use PDO;

/**
 * Replaces the setInterval() polling in chat.js/chats.js.
 * One long-lived process, holding one open connection per logged-in user.
 *
 * Auth model: the browser connects to wss://host/ws?token=<session_id>.
 * We look that session id up directly in the `sessions` table (same
 * SQLite file PHP-FPM writes to) rather than trusting a client-supplied
 * user id, so a connection can't claim to be someone it isn't.
 *
 * At small/medium scale this process talks to SQLite directly. Once
 * running multiple instances behind a load balancer, swap the in-memory
 * $clients map + direct DB writes for a Redis pub/sub layer so instances
 * can broadcast to each other's connections - that's the only structural
 * change needed to scale beyond one process.
 */
final class ChatServer implements MessageComponentInterface
{
    /** @var array<int, ConnectionInterface> user_id => connection */
    private array $clients = [];

    private PDO $db;

    public function __construct(string $sqlitePath)
    {
        $this->db = new PDO('sqlite:' . $sqlitePath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode = WAL;');
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $query = [];
        parse_str((string) parse_url((string) $conn->httpRequest->getUri(), PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;

        $userId = $token ? $this->resolveUserIdFromSession((string) $token) : null;

        if ($userId === null) {
            $conn->close(); // reject unauthenticated connections outright
            return;
        }

        $conn->userId = $userId;
        $this->clients[$userId] = $conn;

        $this->setStatus($userId, 'Active now');
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($from->userId)) {
            return;
        }

        // The socket layer only ever pushes already-persisted events; it does
        // NOT accept arbitrary writes from clients. Sending a message still
        // goes through /actions/dm_send.php (or group equivalent) over HTTPS,
        // which enforces CSRF + the dm_permission privacy check. That HTTP
        // endpoint then calls notifyUser()/broadcastToGroup() below (e.g. via
        // a small internal queue table or a direct call if co-located) to
        // push the already-validated message out in real time.
        //
        // Keeping validation on the HTTP side and delivery on the socket
        // side avoids duplicating the privacy/permission logic in two places.
        if (($data['type'] ?? '') === 'ping') {
            $from->send(json_encode(['type' => 'pong']));
            return;
        }

        // Typing indicator: purely ephemeral, never touches the DB or goes
        // through an HTTP action - it's just "hey, tell the other socket".
        if (($data['type'] ?? '') === 'typing' && isset($data['recipient_public_id'])) {
            $stmt = $this->db->prepare('SELECT id FROM users WHERE public_id = :pid');
            $stmt->execute([':pid' => $data['recipient_public_id']]);
            $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($recipient) {
                $stmt = $this->db->prepare('SELECT public_id FROM users WHERE id = :id');
                $stmt->execute([':id' => $from->userId]);
                $me = $stmt->fetch(PDO::FETCH_ASSOC);
                $this->notifyUser((int) $recipient['id'], [
                    'type' => 'typing',
                    'from_public_id' => $me['public_id'] ?? null,
                ]);
            }
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (isset($conn->userId)) {
            unset($this->clients[$conn->userId]);
            $this->setStatus($conn->userId, 'Offline');
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    /** Pushes an already-persisted event to one user if they're connected. */
    public function notifyUser(int $userId, array $payload): void
    {
        if (isset($this->clients[$userId])) {
            $this->clients[$userId]->send(json_encode($payload));
        }
        // If not connected, the client picks it up on next load from the DB -
        // the socket is a real-time convenience layer, not the source of truth.
    }

    public function broadcastToGroup(array $memberUserIds, array $payload): void
    {
        foreach ($memberUserIds as $userId) {
            $this->notifyUser($userId, $payload);
        }
    }

    /**
     * Pushes to every currently-connected client, regardless of who they
     * are - used for public/shared content (the News feed) where there's
     * no fixed recipient list the way a DM or group has. Safe to include
     * the sender themselves: reaction/echo counts are idempotent (setting
     * the same server-computed number twice is harmless), and new-comment
     * payloads carry the comment's real id so the client can skip a row
     * it already rendered optimistically instead of duplicating it.
     */
    public function broadcastAll(array $payload): void
    {
        foreach ($this->clients as $conn) {
            $conn->send(json_encode($payload));
        }
    }

    private function resolveUserIdFromSession(string $sessionToken): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT user_id FROM sessions WHERE id = :id AND expires_at > datetime('now') LIMIT 1"
        );
        $stmt->execute([':id' => $sessionToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['user_id'] : null;
    }

    private function setStatus(int $userId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE users SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $userId]);
    }
}

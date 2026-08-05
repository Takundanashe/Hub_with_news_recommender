<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use App\ChatServer;

$sqlitePath = __DIR__ . '/../../data/app.sqlite';
$loop = Loop::get();
$chatServer = new ChatServer($sqlitePath);

// Public-facing WebSocket endpoint - nginx proxies wss://host/ws here (port 8080).
$wsSocket = new SocketServer('127.0.0.1:8080', [], $loop);
new IoServer(new HttpServer(new WsServer($chatServer)), $wsSocket, $loop);

// Internal-only push channel (127.0.0.1 ONLY - never route this through nginx
// or expose it externally). PHP-FPM action scripts (dm_send.php,
// group message send, etc.) connect briefly and send one line of JSON after
// they've already validated + persisted an event to SQLite, so this process
// can relay it to any connected client in real time. This process is not the
// source of truth for permissions - it only ever repeats what the HTTP layer
// already approved and saved.
$internalSocket = new SocketServer('127.0.0.1:8081', [], $loop);
$internalSocket->on('connection', function (ConnectionInterface $conn) use ($chatServer): void {
    $buffer = '';
    $conn->on('data', function (string $chunk) use (&$buffer, $conn, $chatServer): void {
        $buffer .= $chunk;
        if (!str_contains($buffer, "\n")) {
            return;
        }
        $data = json_decode(trim($buffer), true);
        $conn->close();
        if (!is_array($data)) {
            return;
        }
        if (($data['type'] ?? '') === 'dm' && isset($data['recipient_id'], $data['payload'])) {
            $chatServer->notifyUser((int) $data['recipient_id'], $data['payload']);
        } elseif (($data['type'] ?? '') === 'group' && isset($data['member_ids'], $data['payload'])) {
            $chatServer->broadcastToGroup($data['member_ids'], $data['payload']);
        } elseif (($data['type'] ?? '') === 'location' && isset($data['recipient_id'], $data['payload'])) {
            $chatServer->notifyUser((int) $data['recipient_id'], $data['payload']);
        } elseif (($data['type'] ?? '') === 'broadcast' && isset($data['payload'])) {
            $chatServer->broadcastAll($data['payload']);
        }
    });
});

echo "WebSocket server on 127.0.0.1:8080, internal push channel on 127.0.0.1:8081\n";
$loop->run();

// Run as its own long-lived process, e.g. via systemd:
//   php /var/www/app/server/websocket/server.php
// Do NOT run this through PHP-FPM - FPM workers are built for short
// request/response cycles, not holding thousands of open connections.

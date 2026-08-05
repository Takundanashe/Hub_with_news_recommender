<?php
declare(strict_types=1);

/**
 * Best-effort relay to the WebSocket process's internal push channel
 * (127.0.0.1:8081 - see server/websocket/server.php). Called AFTER an
 * event is already validated and persisted to SQLite, never before.
 *
 * If the WebSocket process is down, this fails silently and the recipient
 * just sees the update next time they load the page - the socket is a
 * real-time convenience layer, not the source of truth.
 */
function push_to_websocket(array $data): void
{
    $fp = @stream_socket_client('tcp://127.0.0.1:8081', $errno, $errstr, 0.3);
    if ($fp === false) {
        return;
    }
    stream_set_timeout($fp, 0, 300000); // 0.3s, split into int seconds + int microseconds
    @fwrite($fp, json_encode($data) . "\n");
    @fclose($fp);
}

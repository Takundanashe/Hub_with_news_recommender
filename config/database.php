<?php
/**
 * Central database connection.
 * SQLite file lives OUTSIDE the public web root (see /data) so it can
 * never be downloaded directly, and is writable only by the PHP-FPM user.
 */

declare(strict_types=1);

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbPath = __DIR__ . '/../data/app.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // WAL mode: lets reads proceed while a write is in progress, which
    // matters a lot for SQLite once chat/DMs/market/news are all writing.
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;'); // wait up to 5s on a lock instead of failing instantly

    return $pdo;
}

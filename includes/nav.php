<?php
/**
 * Central nav definition. Both the sidebar (all items, always on desktop,
 * slide-out drawer on mobile) and the bottom bar (5 shortcuts on mobile
 * only) read from this list, so there's exactly one place that defines
 * "what pages exist" - no more links duplicated between a page's own body
 * and the nav.
 */
function nav_items(): array
{
    return [
        ['href' => '/index.php',                     'icon' => '🛍️', 'label' => 'Market',        'match' => '/index.php'],
        ['href' => '/market/index.php?type=job',      'icon' => '💼', 'label' => 'Jobs',          'match' => 'type=job'],
        ['href' => '/market/index.php?type=lost_found','icon' => '🔎', 'label' => 'Lost & Found', 'match' => 'type=lost_found'],
        ['href' => '/market/index.php?type=housing',  'icon' => '🏠', 'label' => 'Housing',       'match' => 'type=housing'],
        ['href' => '/news/index.php',                 'icon' => '📰', 'label' => 'News',          'match' => '/news/'],
        ['href' => '/groups.php',                     'icon' => '👥', 'label' => 'Groups',        'match' => '/groups.php'],
        ['href' => '/messages.php',                   'icon' => '💬', 'label' => 'Messages',      'match' => '/messages.php'],
        ['href' => '/wallet.php',                      'icon' => '💳', 'label' => 'Wallet',        'match' => '/wallet.php'],
        ['href' => '/search.php',                      'icon' => '🔍', 'label' => 'Search',        'match' => '/search.php'],
        ['href' => '/settings.php',                    'icon' => '⚙️', 'label' => 'Settings',      'match' => '/settings.php'],
    ];
}

/** Bottom bar only shows the 5 most-used destinations; "More" opens the full sidebar drawer. */
function bottom_nav_items(): array
{
    return ['/index.php', '/news/index.php', '/messages.php', '/wallet.php'];
}

function nav_is_active(string $matchToken): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ($matchToken === '/index.php') {
        return $uri === '/' || str_starts_with($uri, '/index.php');
    }
    return str_contains($uri, $matchToken);
}

/** Total unread DMs across every conversation - same source of truth as the per-conversation badges in Messages. */
function get_unread_dm_count(?PDO $db, ?int $userId): int
{
    if (!$db || !$userId) {
        return 0; // defensive: a page that forgot to set these up gets a blank badge, not a fatal error
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM direct_messages WHERE recipient_id = :me AND read_at IS NULL');
    $stmt->execute([':me' => $userId]);
    return (int) $stmt->fetchColumn();
}

/** Total unread group messages across every group the user belongs to. */
function get_unread_group_count(?PDO $db, ?int $userId): int
{
    if (!$db || !$userId) {
        return 0;
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM group_messages gm
         JOIN group_members mem ON mem.group_id = gm.group_id AND mem.user_id = :me
         WHERE gm.sender_id != :me2 AND gm.created_at > COALESCE(mem.last_read_at, '1970-01-01')"
    );
    $stmt->execute([':me' => $userId, ':me2' => $userId]);
    return (int) $stmt->fetchColumn();
}

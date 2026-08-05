<?php
declare(strict_types=1);

/** "Friends" = each user follows the other back. Used to gate location sharing. */
function is_mutual_follow(PDO $db, int $userA, int $userB): bool
{
    $stmt = $db->prepare(
        'SELECT
            (SELECT COUNT(*) FROM follows WHERE follower_id = :a AND followed_id = :b) AS a_follows_b,
            (SELECT COUNT(*) FROM follows WHERE follower_id = :b AND followed_id = :a) AS b_follows_a'
    );
    $stmt->execute([':a' => $userA, ':b' => $userB]);
    $row = $stmt->fetch();
    return $row && (int) $row['a_follows_b'] > 0 && (int) $row['b_follows_a'] > 0;
}

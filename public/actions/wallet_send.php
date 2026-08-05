<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
header('Content-Type: application/json');
$userId = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
require_csrf();

$db = get_db();

$recipientMoneyId = trim((string) ($_POST['recipient_money_id'] ?? ''));
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$memo = trim((string) ($_POST['memo'] ?? ''));

if ($amount === null || $amount === false || $amount <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Enter a valid amount greater than zero.']);
    exit;
}
$amountCents = (int) round($amount * 100);

$stmt = $db->prepare('SELECT user_id FROM wallets WHERE money_id = :mid');
$stmt->execute([':mid' => $recipientMoneyId]);
$recipientWallet = $stmt->fetch();
if (!$recipientWallet) {
    http_response_code(404);
    echo json_encode(['error' => 'MoneyID not found.']);
    exit;
}
$recipientId = (int) $recipientWallet['user_id'];
if ($recipientId === $userId) {
    http_response_code(422);
    echo json_encode(['error' => "You can't send credits to yourself."]);
    exit;
}

// BEGIN IMMEDIATE takes the write lock right away rather than on first write,
// so a second concurrent transfer from the same sender blocks (up to the
// busy_timeout) instead of racing past the balance check below.
$db->exec('BEGIN IMMEDIATE');
try {
    $stmt = $db->prepare('SELECT balance_cents FROM wallets WHERE user_id = :id');
    $stmt->execute([':id' => $userId]);
    $senderBalance = (int) $stmt->fetchColumn();

    if ($senderBalance < $amountCents) {
        $db->exec('ROLLBACK');
        http_response_code(422);
        echo json_encode(['error' => 'Insufficient balance.']);
        exit;
    }

    $db->prepare('UPDATE wallets SET balance_cents = balance_cents - :amt WHERE user_id = :id')
       ->execute([':amt' => $amountCents, ':id' => $userId]);
    $db->prepare('UPDATE wallets SET balance_cents = balance_cents + :amt WHERE user_id = :id')
       ->execute([':amt' => $amountCents, ':id' => $recipientId]);

    $publicId = generate_public_id('tx');
    $db->prepare(
        'INSERT INTO wallet_transactions (public_id, sender_id, recipient_id, amount_cents, memo, status)
         VALUES (:pid, :sender, :recipient, :amt, :memo, :status)'
    )->execute([
        ':pid' => $publicId, ':sender' => $userId, ':recipient' => $recipientId,
        ':amt' => $amountCents, ':memo' => $memo,
        // Real currency later: a licensed provider's transfer call goes here,
        // and `status` starts 'pending' until its webhook confirms - this
        // in-app version completes synchronously since it's just a ledger entry.
        ':status' => 'completed',
    ]);

    $db->exec('COMMIT');
} catch (Throwable $e) {
    $db->exec('ROLLBACK');
    http_response_code(500);
    echo json_encode(['error' => 'Transfer failed. Please try again.']);
    exit;
}

echo json_encode(['success' => true, 'transaction_id' => $publicId]);

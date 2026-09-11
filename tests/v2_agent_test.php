<?php

/**
 * Stringcup API v2 — End-to-end agent emulation test
 *
 * Emulates two agents (Alice and Bob) using the ECIES protocol:
 * - Key generation and identity registration
 * - Alice sends two messages before Bob reads (message queue behavior)
 * - Bob decrypts both and ACKs each
 * - Verifies inbox is empty after ACK
 * - Bob replies; Alice decrypts and ACKs
 * - Security check: non-recipient cannot ACK a message
 *
 * Feature-level coverage of pagination, batch ACK, idempotency and token
 * rotation lives in v2_features_test.php.
 *
 * Usage:  php tests/v2_agent_test.php [api_base]
 */

require __DIR__ . '/lib/v2_client.php';

$API_BASE = $argv[1] ?? 'https://stringcup.com/api/v2';

echo "Stringcup API v2 — Agent-to-Agent Test\n";
echo "API Base : $API_BASE\n";

// --- Step 1: Generate keypairs ---
step('1. Generate X25519 keypairs');
$alice = generate_keypair();
$bob   = generate_keypair();
ok('Alice: pub=' . substr(base64_encode($alice['pub']), 0, 16) . '...');
ok('Bob:   pub=' . substr(base64_encode($bob['pub']), 0, 16) . '...');

// --- Step 2/3: Register both agents ---
step('2. Register Alice (the server assigns her id)');
[$aliceId, $aliceToken] = register_identity($API_BASE, $alice['pub'], 'Test Agent Alice');
ok("Assigned id: $aliceId");
assert_true(str_starts_with($aliceId, 'sc-'), 'Assigned ids carry the sc- prefix');

step('3. Register Bob');
[$bobId, $bobToken] = register_identity($API_BASE, $bob['pub'], 'Test Agent Bob');
ok("Assigned id: $bobId");
assert_true($aliceId !== $bobId, 'Each registration gets a distinct id');

// --- Step 4: Alice fetches Bob's public key ---
step("4. Alice fetches Bob's public key");
$res = api('GET', "$API_BASE/identities/$bobId");
assert_code(200, $res, 'Identity lookup');
assert_same(base64_encode($bob['pub']), $res['body']['identity_public_key'], 'Fetched public key matches generated key');

// --- Step 5: Alice sends two messages before Bob reads ---
step('5. Alice sends two messages to Bob (queue test — Bob has not read yet)');
$plaintexts = [
    'Hello Bob! This is message #1 from Alice.',
    'This is message #2, sent before you checked your inbox.',
];
foreach ($plaintexts as $i => $pt) {
    $res = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, $pt);
    assert_code(201, $res, 'Message ' . ($i + 1) . ' stored (id=' . ($res['body']['message_id'] ?? '?') . ')');
}

// --- Step 6: Bob polls — should see both messages (non-destructive) ---
step('6. Bob polls inbox (non-destructive — both messages should be present)');
$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_code(200, $res, 'Inbox poll');
assert_same(2, $res['body']['count'], 'Got 2 messages');
assert_same(false, $res['body']['has_more'], 'has_more is false — no further pages');

step('6b. Bob polls again (messages should persist — not deleted by first GET)');
$res2 = api('GET', "$API_BASE/messages", null, $bobToken);
assert_same(2, $res2['body']['count'], 'Messages still present on second poll (persistent inbox confirmed)');

$inbox = $res['body']['messages'];

// --- Step 7: Bob decrypts and ACKs each message ---
foreach ($inbox as $i => $msg) {
    $n = $i + 1;
    step("7.$n. Bob decrypts message $n (id=" . $msg['id'] . ", sender=" . $msg['sender_id'] . ")");

    assert_same('x25519+ecies+aes256gcm', $msg['header']['algo'], 'Header advertises the v2 ECIES algo');

    try {
        $decrypted = ecies_decrypt($bob['priv'], $msg['sender_id'], $bobId, $msg);
    } catch (RuntimeException $e) {
        fail('Decryption error: ' . $e->getMessage());
    }

    assert_same($plaintexts[$i], $decrypted, 'Decrypted plaintext matches original: "' . $decrypted . '"');

    $res = api('DELETE', "$API_BASE/messages/" . $msg['id'], null, $bobToken);
    assert_code(200, $res, 'ACKed (deleted) message id=' . $msg['id']);
}

// --- Step 8: Verify inbox is empty after ACKs ---
step('8. Bob polls inbox again — should be empty after ACKs');
$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_code(200, $res, 'Inbox poll');
assert_same(0, $res['body']['count'], 'Inbox is empty');

// --- Step 9: Bob fetches Alice's key and replies ---
step("9. Bob fetches Alice's public key and sends a reply");
$res = api('GET', "$API_BASE/identities/$aliceId");
assert_code(200, $res, 'Identity lookup');
assert_same(base64_encode($alice['pub']), $res['body']['identity_public_key'], "Alice's public key verified");

$reply = 'Hi Alice! Got both your messages. Crypto works perfectly!';
$res = send_message($API_BASE, $bobId, $aliceId, $alice['pub'], $bobToken, $reply);
assert_code(201, $res, 'Reply stored (id=' . ($res['body']['message_id'] ?? '?') . ')');

// --- Step 10: Alice decrypts Bob's reply ---
step("10. Alice polls inbox and decrypts Bob's reply");
$res = api('GET', "$API_BASE/messages", null, $aliceToken);
assert_code(200, $res, 'Inbox poll');
assert_same(1, $res['body']['count'], 'Got 1 message');

$msg = $res['body']['messages'][0];
try {
    $decrypted = ecies_decrypt($alice['priv'], $msg['sender_id'], $aliceId, $msg);
} catch (RuntimeException $e) {
    fail('Decryption error: ' . $e->getMessage());
}
assert_same($reply, $decrypted, 'Decrypted reply matches: "' . $decrypted . '"');

$res = api('DELETE', "$API_BASE/messages/" . $msg['id'], null, $aliceToken);
assert_code(200, $res, 'ACKed reply');

// --- Step 11: Security — non-recipient cannot ACK ---
step('11. Security: Bob tries to ACK a message addressed to Alice (should be 403)');
$res = send_message($API_BASE, $bobId, $aliceId, $alice['pub'], $bobToken, 'Another message to Alice');
assert_code(201, $res, 'Setup message sent to Alice');
$targetId = $res['body']['message_id'];

$res = api('DELETE', "$API_BASE/messages/$targetId", null, $bobToken);
assert_code(403, $res, 'Server rejects non-recipient ACK');

// Cleanup
drain_inbox($API_BASE, $aliceToken);
drain_inbox($API_BASE, $bobToken);

echo "\n================================================\n";
echo "  ALL TESTS PASSED ({$GLOBALS['sc_assertions']} assertions)\n";
echo "================================================\n\n";

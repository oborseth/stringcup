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
 */

$API_BASE = 'https://stringcup.com/api/v2';

$suffix  = bin2hex(random_bytes(4));
$aliceId = 'test-alice-' . $suffix;
$bobId   = 'test-bob-'   . $suffix;

// ============================================================
// Output helpers
// ============================================================

function step(string $msg): void  { echo "\n[STEP] $msg\n"; }
function ok(string $msg): void    { echo "  ✓  $msg\n"; }
function fail(string $msg): never {
    echo "  ✗  $msg\n";
    exit(1);
}

// ============================================================
// HTTP helper (curl)
// ============================================================

function api(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            'Accept: application/json',
            $token ? "Authorization: Bearer $token" : null,
        ]),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($raw, true)];
}

// ============================================================
// Crypto helpers
// ============================================================

function generate_keypair(): array {
    $kp = sodium_crypto_box_keypair();
    return [
        'pub'  => sodium_crypto_box_publickey($kp),
        'priv' => sodium_crypto_box_secretkey($kp),
    ];
}

function hkdf(string $ikm, string $salt, string $info, int $len = 32): string {
    return hash_hkdf('sha256', $ikm, $len, $info, $salt);
}

function aes_gcm_encrypt(string $key, string $iv, string $plaintext): string {
    $tag = '';
    $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return $ct . $tag; // tag appended
}

function aes_gcm_decrypt(string $key, string $iv, string $ciphertext): string {
    $tag = substr($ciphertext, -16);
    $ct  = substr($ciphertext, 0, -16);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) {
        throw new RuntimeException('AES-256-GCM authentication failed');
    }
    return $pt;
}

/**
 * Encrypt a plaintext string for a recipient (ECIES, v2 protocol).
 * Returns the header array and base64 ciphertext ready to POST.
 */
function ecies_encrypt(string $sender_id, string $recipient_id, string $recipient_pub, string $plaintext): array {
    $eph_kp   = sodium_crypto_box_keypair();
    $eph_pub  = sodium_crypto_box_publickey($eph_kp);
    $eph_priv = sodium_crypto_box_secretkey($eph_kp);

    $shared = sodium_crypto_scalarmult($eph_priv, $recipient_pub);
    $msg_key = hkdf($shared, 'stringcup-v2-msg', "$sender_id->$recipient_id");

    $iv         = random_bytes(12);
    $ciphertext = aes_gcm_encrypt($msg_key, $iv, $plaintext);

    // ephemeral private key is no longer needed — not stored
    sodium_memzero($eph_priv);

    return [
        'header' => [
            'version'       => 2,
            'algo'          => 'x25519+ecies+aes256gcm',
            'ephemeral_pub' => base64_encode($eph_pub),
            'iv'            => base64_encode($iv),
        ],
        'ciphertext' => base64_encode($ciphertext),
    ];
}

/**
 * Decrypt an inbox message (ECIES, v2 protocol).
 * Only needs the recipient's static private key.
 */
function ecies_decrypt(string $my_priv, string $sender_id, string $my_id, array $msg): string {
    $eph_pub    = base64_decode($msg['header']['ephemeral_pub']);
    $iv         = base64_decode($msg['header']['iv']);
    $ciphertext = base64_decode($msg['ciphertext']);

    $shared  = sodium_crypto_scalarmult($my_priv, $eph_pub);
    $msg_key = hkdf($shared, 'stringcup-v2-msg', "$sender_id->$my_id");

    return aes_gcm_decrypt($msg_key, $iv, $ciphertext);
}

// ============================================================
// TEST SUITE
// ============================================================

echo "Stringcup API v2 — Agent-to-Agent Test\n";
echo "Alice ID : $aliceId\n";
echo "Bob ID   : $bobId\n";
echo "API Base : $API_BASE\n";

// --- Step 1: Generate keypairs ---
step('1. Generate X25519 keypairs');
$alice = generate_keypair();
$bob   = generate_keypair();
ok('Alice: pub=' . substr(base64_encode($alice['pub']), 0, 16) . '...');
ok('Bob:   pub=' . substr(base64_encode($bob['pub']), 0, 16) . '...');

// --- Step 2: Register Alice ---
step("2. Register Alice ($aliceId)");
$res = api('POST', "$API_BASE/identities", [
    'external_id'         => $aliceId,
    'identity_public_key' => base64_encode($alice['pub']),
    'algo'                => 'x25519',
    'display_name'        => 'Test Agent Alice',
]);
if ($res['code'] !== 201 || empty($res['body']['api_token'])) {
    fail('Registration failed: ' . json_encode($res));
}
$aliceToken = $res['body']['api_token'];
ok('Registered. Token: ' . substr($aliceToken, 0, 10) . '...');

// --- Step 3: Register Bob ---
step("3. Register Bob ($bobId)");
$res = api('POST', "$API_BASE/identities", [
    'external_id'         => $bobId,
    'identity_public_key' => base64_encode($bob['pub']),
    'algo'                => 'x25519',
    'display_name'        => 'Test Agent Bob',
]);
if ($res['code'] !== 201 || empty($res['body']['api_token'])) {
    fail('Registration failed: ' . json_encode($res));
}
$bobToken = $res['body']['api_token'];
ok('Registered. Token: ' . substr($bobToken, 0, 10) . '...');

// --- Step 4: Alice fetches Bob's public key ---
step("4. Alice fetches Bob's public key");
$res = api('GET', "$API_BASE/identities/$bobId");
if ($res['code'] !== 200) {
    fail('Identity lookup failed: ' . json_encode($res));
}
$bobPubFetched = base64_decode($res['body']['identity_public_key']);
if ($bobPubFetched !== $bob['pub']) {
    fail('Fetched public key does not match generated key');
}
ok('Public key verified (matches generated key)');

// --- Step 5: Alice sends two messages before Bob reads ---
step('5. Alice sends two messages to Bob (queue test — Bob has not read yet)');
$plaintexts = [
    'Hello Bob! This is message #1 from Alice.',
    'This is message #2, sent before you checked your inbox.',
];
$sentIds = [];
foreach ($plaintexts as $i => $pt) {
    $enc = ecies_encrypt($aliceId, $bobId, $bob['pub'], $pt);
    $res = api('POST', "$API_BASE/messages",
        array_merge(['recipient_id' => $bobId, 'sender_id' => $aliceId], $enc),
        $aliceToken
    );
    if ($res['code'] !== 201) {
        fail("Send message " . ($i+1) . " failed: " . json_encode($res));
    }
    $sentIds[] = $res['body']['message_id'];
    ok("Message " . ($i+1) . " stored (id=" . $res['body']['message_id'] . ")");
}

// --- Step 6: Bob polls — should see both messages (non-destructive) ---
step('6. Bob polls inbox (non-destructive — both messages should be present)');
$res = api('GET', "$API_BASE/messages", null, $bobToken);
if ($res['code'] !== 200) {
    fail('Inbox poll failed: ' . json_encode($res));
}
if (count($res['body']) !== 2) {
    fail('Expected 2 messages, got ' . count($res['body']));
}
ok('Got ' . count($res['body']) . ' messages');

// Poll again — messages should still be there (persistent inbox)
step('6b. Bob polls again (messages should persist — not deleted by first GET)');
$res2 = api('GET', "$API_BASE/messages", null, $bobToken);
if (count($res2['body']) !== 2) {
    fail('Expected 2 messages on second poll — inbox was unexpectedly cleared');
}
ok('Messages still present on second poll (persistent inbox confirmed)');

$inbox = $res['body'];

// --- Step 7: Bob decrypts and ACKs each message ---
foreach ($inbox as $i => $msg) {
    $n = $i + 1;
    step("7.$n. Bob decrypts message $n (id=" . $msg['id'] . ", sender=" . $msg['sender_id'] . ")");

    if ($msg['header']['algo'] !== 'x25519+ecies+aes256gcm') {
        fail('Unexpected algo: ' . $msg['header']['algo']);
    }

    try {
        $decrypted = ecies_decrypt($bob['priv'], $msg['sender_id'], $bobId, $msg);
    } catch (RuntimeException $e) {
        fail('Decryption error: ' . $e->getMessage());
    }

    ok('Decrypted: "' . $decrypted . '"');

    if ($decrypted !== $plaintexts[$i]) {
        fail('Plaintext mismatch! Expected: "' . $plaintexts[$i] . '"');
    }
    ok('Plaintext matches original');

    // ACK
    $res = api('DELETE', "$API_BASE/messages/" . $msg['id'], null, $bobToken);
    if ($res['code'] !== 200) {
        fail('ACK failed: ' . json_encode($res));
    }
    ok('ACKed (deleted) message id=' . $msg['id']);
}

// --- Step 8: Verify inbox is empty after ACKs ---
step('8. Bob polls inbox again — should be empty after ACKs');
$res = api('GET', "$API_BASE/messages", null, $bobToken);
if ($res['code'] !== 200) {
    fail('Inbox poll failed: ' . json_encode($res));
}
if (count($res['body']) !== 0) {
    fail('Expected empty inbox, got ' . count($res['body']) . ' messages');
}
ok('Inbox is empty');

// --- Step 9: Bob fetches Alice's key and replies ---
step("9. Bob fetches Alice's public key and sends a reply");
$res = api('GET', "$API_BASE/identities/$aliceId");
if ($res['code'] !== 200) {
    fail('Identity lookup failed: ' . json_encode($res));
}
$alicePubFetched = base64_decode($res['body']['identity_public_key']);
if ($alicePubFetched !== $alice['pub']) {
    fail("Alice's fetched public key does not match");
}
ok("Alice's public key verified");

$reply = 'Hi Alice! Got both your messages. Crypto works perfectly!';
$enc = ecies_encrypt($bobId, $aliceId, $alice['pub'], $reply);
$res = api('POST', "$API_BASE/messages",
    array_merge(['recipient_id' => $aliceId, 'sender_id' => $bobId], $enc),
    $bobToken
);
if ($res['code'] !== 201) {
    fail('Reply send failed: ' . json_encode($res));
}
$replyId = $res['body']['message_id'];
ok("Reply stored (id=$replyId)");

// --- Step 10: Alice decrypts Bob's reply ---
step("10. Alice polls inbox and decrypts Bob's reply");
$res = api('GET', "$API_BASE/messages", null, $aliceToken);
if ($res['code'] !== 200 || count($res['body']) !== 1) {
    fail('Expected 1 message in Alice inbox, got: ' . json_encode($res));
}
$msg = $res['body'][0];
ok('Got 1 message from ' . $msg['sender_id']);

try {
    $decrypted = ecies_decrypt($alice['priv'], $msg['sender_id'], $aliceId, $msg);
} catch (RuntimeException $e) {
    fail('Decryption error: ' . $e->getMessage());
}
ok('Decrypted: "' . $decrypted . '"');
if ($decrypted !== $reply) {
    fail('Plaintext mismatch!');
}
ok('Plaintext matches');

$res = api('DELETE', "$API_BASE/messages/" . $msg['id'], null, $aliceToken);
if ($res['code'] !== 200) {
    fail('ACK failed: ' . json_encode($res));
}
ok('ACKed reply');

// --- Step 11: Security — non-recipient cannot ACK ---
step('11. Security: Bob tries to ACK a message addressed to Alice (should be 403)');
$enc = ecies_encrypt($bobId, $aliceId, $alice['pub'], 'Another message to Alice');
$res = api('POST', "$API_BASE/messages",
    array_merge(['recipient_id' => $aliceId], $enc),
    $bobToken
);
if ($res['code'] !== 201) {
    fail('Setup send failed: ' . json_encode($res));
}
$targetId = $res['body']['message_id'];

$res = api('DELETE', "$API_BASE/messages/$targetId", null, $bobToken);
if ($res['code'] !== 403) {
    fail("Expected 403 Forbidden, got {$res['code']}: " . json_encode($res['body']));
}
ok('Got 403 Forbidden — server correctly rejects non-recipient ACK');

// Cleanup
$res = api('GET', "$API_BASE/messages", null, $aliceToken);
foreach (($res['body'] ?? []) as $m) {
    api('DELETE', "$API_BASE/messages/" . $m['id'], null, $aliceToken);
}

// ============================================================
echo "\n================================================\n";
echo "  ALL TESTS PASSED\n";
echo "================================================\n\n";

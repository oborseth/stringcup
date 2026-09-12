<?php

/**
 * Stringcup API v2 — idempotency concurrency test.
 *
 * A retrying agent can easily have two sends in flight at once (a client
 * timeout that fires while the original request is still being served). The
 * send path guards this by reserving the Idempotency-Key against a unique
 * index before storing anything, so exactly one racer can win.
 *
 * This fires N genuinely parallel requests carrying the same key and asserts
 * that exactly one message is stored regardless of interleaving.
 *
 * Usage:  php tests/v2_idempotency_race_test.php [api_base] [concurrency]
 */

require __DIR__ . '/lib/v2_client.php';

$API_BASE    = $argv[1] ?? 'https://stringcup.com/api/v2';
$CONCURRENCY = (int) ($argv[2] ?? 8);

$suffix = bin2hex(random_bytes(4));

echo "Stringcup API v2 — Idempotency Race Test\n";
echo "API Base    : $API_BASE\n";
echo "Concurrency : $CONCURRENCY\n";

step('1. Register sender and recipient');
$alice = generate_keypair();
$bob   = generate_keypair();
[$aliceId, $aliceToken] = register_identity($API_BASE, $alice['pub'], 'Race Alice');
[$bobId,   $bobToken]   = register_identity($API_BASE, $bob['pub'],   'Race Bob');
ok('Registered');

drain_inbox($API_BASE, $bobToken);

step("2. Fire $CONCURRENCY concurrent sends sharing one Idempotency-Key");

$idemKey = 'race-' . bin2hex(random_bytes(8));

// Build one payload and reuse it, so the requests are byte-identical the way
// a real client retry would be.
$payload = json_encode(array_merge(
    ['recipient_id' => $bobId, 'sender_id' => $aliceId],
    ecies_encrypt($aliceId, $bobId, $bob['pub'], 'concurrent send')
));

$multi   = curl_multi_init();
$handles = [];

for ($i = 0; $i < $CONCURRENCY; $i++) {
    $ch = curl_init("$API_BASE/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer $aliceToken",
            "Idempotency-Key: $idemKey",
        ],
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);

$byCode = [];
$messageIds = [];

foreach ($handles as $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = json_decode(curl_multi_getcontent($ch), true);

    $byCode[$code] = ($byCode[$code] ?? 0) + 1;
    if (isset($body['sent_seq'])) {
        $messageIds[] = $body['sent_seq'];
    }

    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
}
curl_multi_close($multi);

ok('Response codes: ' . json_encode($byCode));

assert_same(1, $byCode[201] ?? 0, 'Exactly one request stored the message (201)');
assert_true(
    ($byCode[200] ?? 0) + ($byCode[409] ?? 0) === $CONCURRENCY - 1,
    'Every other request got a replay (200) or in-flight conflict (409)'
);
assert_true(
    array_diff(array_keys($byCode), [200, 201, 409]) === [],
    'No unexpected status codes'
);

$distinct = array_values(array_unique($messageIds));
assert_true(count($distinct) <= 1, 'All returned sent_seq values agree: ' . json_encode($distinct));

step('3. Confirm the recipient received exactly one message');
$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_code(200, $res, 'Inbox poll');
assert_same(1, $res['body']['count'], 'Exactly one message stored despite the race');

$decrypted = ecies_decrypt($bob['priv'], $aliceId, $bobId, $res['body']['messages'][0]);
assert_same('concurrent send', $decrypted, 'Stored message decrypts correctly');

step('4. A later replay of the same key still resolves to that message');
$replay = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'concurrent send', $idemKey);
assert_code(200, $replay, 'Post-race replay is recognised');
// The sender's sequence and the recipient's are separate spaces now, so the
// guarantee to check is that the replay echoes the same sent_seq the winning
// request did — not that it matches the recipient's inbox number.
assert_same(
    $distinct[0] ?? null,
    $replay['body']['sent_seq'],
    'Replay echoes the winning send\'s sent_seq'
);

$after = api('GET', "$API_BASE/messages", null, $bobToken);
assert_same(1, $after['body']['count'], 'Still exactly one message');

drain_inbox($API_BASE, $bobToken);

echo "\n================================================\n";
echo "  ALL TESTS PASSED ({$GLOBALS['sc_assertions']} assertions)\n";
echo "================================================\n\n";

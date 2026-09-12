<?php

/**
 * Stringcup API v2 — feature test for the agent-oriented additions.
 *
 * Covers, against a live server:
 *   - cursor pagination on the inbox (limit / since_id / has_more)
 *   - batch ACK, including partial-success reporting
 *   - idempotent send via Idempotency-Key, and its per-sender scoping
 *   - token introspection and rotation
 *   - X-RateLimit-* budget headers
 *   - input validation on all of the above
 *
 * Usage:  php tests/v2_features_test.php [api_base]
 *
 * Registration is limited to 5/hr per IP; if re-running trips that, clear
 * writable/cache/ratelimit/ first.
 */

require __DIR__ . '/lib/v2_client.php';

$API_BASE = $argv[1] ?? 'https://stringcup.com/api/v2';

$suffix = bin2hex(random_bytes(4));

echo "Stringcup API v2 — Agent Feature Test\n";
echo "API Base : $API_BASE\n";

// ============================================================
step('1. Register two agents');
// ============================================================
$alice = generate_keypair();
$bob   = generate_keypair();

[$aliceId, $aliceToken] = register_identity($API_BASE, $alice['pub'], 'Feature Test Alice');
[$bobId,   $bobToken]   = register_identity($API_BASE, $bob['pub'],   'Feature Test Bob');
ok('Both identities registered');

// ============================================================
step('2. Rate limit budget headers');
// ============================================================
$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_code(200, $res, 'Inbox reachable');

foreach (['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset'] as $h) {
    assert_true(isset($res['headers'][$h]), "Header $h present" . (isset($res['headers'][$h]) ? " = {$res['headers'][$h]}" : ''));
}
assert_same('300', $res['headers']['x-ratelimit-limit'], 'Inbox limit advertised as 300/hr');

$remainingFirst = (int) $res['headers']['x-ratelimit-remaining'];
$res2 = api('GET', "$API_BASE/messages", null, $bobToken);
$remainingSecond = (int) $res2['headers']['x-ratelimit-remaining'];
assert_true(
    $remainingSecond === $remainingFirst - 1,
    "Remaining decrements per request ($remainingFirst -> $remainingSecond)"
);
assert_true((int) $res['headers']['x-ratelimit-reset'] > time(), 'Reset is a future unix timestamp');

// Two different tokens must not share a bucket, even from one IP.
$aliceRes = api('GET', "$API_BASE/messages", null, $aliceToken);
assert_same(
    299,
    (int) $aliceRes['headers']['x-ratelimit-remaining'],
    "Alice has her own budget (per-token, not per-IP)"
);

// ============================================================
step('3. Token introspection — GET /tokens/current');
// ============================================================
$res = api('GET', "$API_BASE/tokens/current", null, $bobToken);
assert_code(200, $res, 'Token introspection works');
assert_same($bobId, $res['body']['identity_id'], 'Reports the owning identity');
assert_true(!empty($res['body']['expires_at']), 'expires_at present: ' . ($res['body']['expires_at'] ?? 'null'));
assert_same(30, $res['body']['inactivity_ttl_days'], 'TTL reported as 30 days');

$expiresIn = (int) $res['body']['expires_in_seconds'];
assert_true(
    $expiresIn > 29 * 86400 && $expiresIn <= 30 * 86400,
    "expires_in_seconds is ~30 days ($expiresIn)"
);

$res = api('GET', "$API_BASE/tokens/current", null, 'not-a-real-token');
assert_code(401, $res, 'Bogus token rejected');

// ============================================================
step('4. Idempotent send');
// ============================================================
drain_inbox($API_BASE, $bobToken);

$idemKey = 'idem-' . bin2hex(random_bytes(8));
$first = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'Idempotency probe', $idemKey);
assert_code(201, $first, 'First send stored');
$firstMessageId = $first['body']['sent_seq'];

$replay = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'Idempotency probe', $idemKey);
assert_code(200, $replay, 'Replay returns 200 rather than 201');
assert_same($firstMessageId, $replay['body']['sent_seq'], 'Replay returns the original sent_seq');
assert_same(true, $replay['body']['idempotent_replay'], 'Replay is flagged as such');

$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_same(1, $res['body']['count'], 'Only one message was actually stored');

// Same key string from a different sender must not collide.
$bobSend = send_message($API_BASE, $bobId, $aliceId, $alice['pub'], $bobToken, 'Different sender same key', $idemKey);
assert_code(201, $bobSend, 'Idempotency keys are scoped per sender');

// A key is only consumed by a successful send.
$badRecipient = api('POST', "$API_BASE/messages", array_merge(
    ['recipient_id' => 'no-such-identity-' . $suffix],
    ecies_encrypt($aliceId, 'nobody', $bob['pub'], 'x')
), $aliceToken, ['Idempotency-Key' => 'reusable-' . $suffix]);
assert_code(404, $badRecipient, 'Send to unknown recipient fails');

$retry = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'Corrected retry', 'reusable-' . $suffix);
assert_code(201, $retry, 'Key freed after a failed send, so a corrected retry works');

$res = api('POST', "$API_BASE/messages", array_merge(
    ['recipient_id' => $bobId],
    ecies_encrypt($aliceId, $bobId, $bob['pub'], 'bad key')
), $aliceToken, ['Idempotency-Key' => "has space"]);
assert_code(400, $res, 'Malformed Idempotency-Key rejected');

drain_inbox($API_BASE, $bobToken);
drain_inbox($API_BASE, $aliceToken);

// ============================================================
step('5. Inbox pagination');
// ============================================================
$total = 12;
$sentPlaintexts = [];
for ($i = 1; $i <= $total; $i++) {
    $text = "Paginated message #$i";
    $sentPlaintexts[] = $text;
    $r = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, $text);
    if ($r['code'] !== 201) {
        fail("Send $i failed: " . json_encode($r));
    }
}
ok("Alice sent $total messages to Bob");

$res = api('GET', "$API_BASE/messages?limit=5", null, $bobToken);
assert_code(200, $res, 'First page fetched');
assert_same(5, $res['body']['count'], 'Page honours limit=5');
assert_same(true, $res['body']['has_more'], 'has_more true while a backlog remains');
assert_true($res['body']['next_since_id'] !== null, 'next_since_id supplied: ' . $res['body']['next_since_id']);

// Walk the cursor to the end.
$collected = [];
$sinceId   = 0;
$pages     = 0;

while (true) {
    $url = "$API_BASE/messages?limit=5" . ($sinceId > 0 ? "&since_id=$sinceId" : '');
    $page = api('GET', $url, null, $bobToken);
    if ($page['code'] !== 200) {
        fail('Pagination poll failed: ' . json_encode($page));
    }
    $pages++;

    foreach ($page['body']['messages'] as $m) {
        $collected[] = $m;
    }

    if (!$page['body']['has_more']) {
        break;
    }
    $sinceId = $page['body']['next_since_id'];

    if ($pages > 10) {
        fail('Cursor did not terminate');
    }
}

assert_same(3, $pages, 'Walked 12 messages in 3 pages of 5');
assert_same($total, count($collected), "Cursor returned all $total messages");

$ids = array_column($collected, 'id');
assert_same(count($ids), count(array_unique($ids)), 'No duplicate messages across pages');

$sorted = $ids;
sort($sorted, SORT_NUMERIC);
assert_same($sorted, $ids, 'Messages arrive in ascending id order');

// Content survives the round trip.
$decrypted = [];
foreach ($collected as $m) {
    $decrypted[] = ecies_decrypt($bob['priv'], $m['sender_id'], $bobId, $m);
}
assert_same($sentPlaintexts, $decrypted, 'All plaintexts decrypt correctly and in order');

// Cursor past the end.
$lastId = end($ids);
$res = api('GET', "$API_BASE/messages?since_id=$lastId", null, $bobToken);
assert_same(0, $res['body']['count'], 'Cursor past the newest message returns nothing');
assert_same(false, $res['body']['has_more'], 'has_more false at the end of the stream');
assert_same($lastId, $res['body']['next_since_id'], 'Empty page holds the cursor steady');

// Default page size.
$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_same($total, $res['body']['count'], 'Default limit (50) returns the whole backlog here');

// ============================================================
step('6. Pagination input validation');
// ============================================================
foreach ([
    'limit=0'        => 'limit below range',
    'limit=201'      => 'limit above MAX_LIMIT',
    'limit=abc'      => 'non-numeric limit',
    'limit=-1'       => 'negative limit',
    'since_id=abc'   => 'non-numeric since_id',
    'since_id=-5'    => 'negative since_id',
] as $qs => $label) {
    $res = api('GET', "$API_BASE/messages?$qs", null, $bobToken);
    assert_code(400, $res, "Rejects $label ($qs)");
}

$res = api('GET', "$API_BASE/messages?limit=200", null, $bobToken);
assert_code(200, $res, 'Accepts limit at the maximum (200)');

// ============================================================
step('7. Batch ACK');
// ============================================================
$res = api('GET', "$API_BASE/messages?limit=5", null, $bobToken);
$batch = array_column($res['body']['messages'], 'id');

$ack = api('POST', "$API_BASE/messages/ack", ['ids' => $batch], $bobToken);
assert_code(200, $ack, 'Batch ACK accepted');
assert_same(5, $ack['body']['count'], 'Acknowledged all 5 in one call');
assert_same($batch, $ack['body']['acknowledged'], 'Reports exactly the acknowledged ids');
assert_same([], $ack['body']['not_found'], 'Nothing reported missing');
assert_true(!array_key_exists('forbidden', $ack['body']), 'No forbidden bucket in the response');

$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_same($total - 5, $res['body']['count'], 'Inbox shrank by exactly 5');

// Re-ACKing is safe and reported, not an error.
$reAck = api('POST', "$API_BASE/messages/ack", ['ids' => $batch], $bobToken);
assert_code(200, $reAck, 'Re-ACK of already-deleted ids is not an error');
assert_same(0, $reAck['body']['count'], 'Nothing acknowledged the second time');
assert_same($batch, $reAck['body']['not_found'], 'Already-gone ids reported as not_found');

// Sequence numbers are scoped to one inbox, so Bob cannot name a message of
// Alice's at all. This used to be enforced with a 'forbidden' bucket, which
// doubled as an oracle confirming that another identity's message existed.
// Now the number simply means something different in each inbox.
$aliceMsg = send_message($API_BASE, $bobId, $aliceId, $alice['pub'], $bobToken, 'For Alice only');
assert_code(201, $aliceMsg, 'Message to Alice stored');

$aliceInbox = api('GET', "$API_BASE/messages", null, $aliceToken);
$aliceSeqs  = array_column($aliceInbox['body']['messages'], 'id');
assert_true($aliceSeqs !== [], "Alice's inbox has the message");
$aliceSeq = $aliceSeqs[0];

$res = api('GET', "$API_BASE/messages?limit=2", null, $bobToken);
$bobIds = array_column($res['body']['messages'], 'id');

// Bob acknowledges Alice's sequence number alongside his own.
$mixed = api('POST', "$API_BASE/messages/ack", ['ids' => array_merge($bobIds, [$aliceSeq])], $bobToken);
assert_code(200, $mixed, 'Mixed batch processed');
assert_true(
    array_diff($bobIds, $mixed['body']['acknowledged']) === [],
    'Own messages acknowledged'
);
assert_true(!array_key_exists('forbidden', $mixed['body']),
    'No forbidden bucket — naming another inbox is not a reachable outcome');

$check = api('GET', "$API_BASE/messages", null, $aliceToken);
assert_true(
    in_array($aliceSeq, array_column($check['body']['messages'], 'id'), true),
    "Alice's message survived Bob's attempt to ACK it"
);

// Dedupe.
$res = api('GET', "$API_BASE/messages?limit=1", null, $bobToken);
$oneId = $res['body']['messages'][0]['id'];
$dupe = api('POST', "$API_BASE/messages/ack", ['ids' => [$oneId, $oneId, $oneId]], $bobToken);
assert_same([$oneId], $dupe['body']['acknowledged'], 'Duplicate ids in one batch are collapsed');

// ============================================================
step('8. Batch ACK input validation');
// ============================================================
$cases = [
    [['ids' => []],                       'empty ids array'],
    [['ids' => 'nope'],                   'ids not an array'],
    [['nope' => [1]],                     'missing ids key'],
    [['ids' => [1, 'abc']],               'non-integer id'],
    [['ids' => [0]],                      'zero id'],
    [['ids' => [-3]],                     'negative id'],
    [['ids' => range(1, 201)],            'more than 200 ids'],
];
foreach ($cases as [$body, $label]) {
    $res = api('POST', "$API_BASE/messages/ack", $body, $bobToken);
    assert_code(400, $res, "Rejects $label");
}

$res = api('POST', "$API_BASE/messages/ack", ['ids' => [999999999]], $bobToken);
assert_code(200, $res, 'Unknown id is a reported miss, not a 400');
assert_same([999999999], $res['body']['not_found'], 'Unknown id reported in not_found');

$res = api('POST', "$API_BASE/messages/ack", ['ids' => [1]], null);
assert_code(401, $res, 'Batch ACK requires auth');

// ============================================================
step('9. Token rotation');
// ============================================================
drain_inbox($API_BASE, $bobToken);
drain_inbox($API_BASE, $aliceToken);

// Leave one message so we can prove the new token reaches the same inbox.
send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'Survives rotation');

$rot = api('POST', "$API_BASE/tokens/rotate", null, $bobToken);
assert_code(201, $rot, 'Rotation issued a new token');
assert_true(!empty($rot['body']['api_token']), 'New token returned');
assert_same($bobId, $rot['body']['identity_id'], 'Bound to the same identity');
assert_same('revoked', $rot['body']['previous_token'], 'Previous token reported revoked');

$newBobToken = $rot['body']['api_token'];
assert_true($newBobToken !== $bobToken, 'New token differs from the old one');

$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_code(401, $res, 'Old token no longer authenticates');

$res = api('GET', "$API_BASE/messages", null, $newBobToken);
assert_code(200, $res, 'New token authenticates');
assert_same(1, $res['body']['count'], 'Same inbox, same pending message');

$decrypted = ecies_decrypt($bob['priv'], $aliceId, $bobId, $res['body']['messages'][0]);
assert_same('Survives rotation', $decrypted, 'Message still decrypts with the unchanged identity key');

$res = api('POST', "$API_BASE/tokens/rotate", null, $bobToken);
assert_code(401, $res, 'Revoked token cannot rotate again');

// ============================================================
step('10. Message size cap and advertised inbox limits');
// ============================================================

// `ciphertext` is a LONGBLOB, so without an explicit cap the only ceiling is
// nginx's client_max_body_size — a default, not a decision.
$big = str_repeat('A', 300 * 1024);
$res = api('POST', "$API_BASE/messages", [
    'recipient_id' => $bobId,
    'sender_id'    => $aliceId,
    'header'       => [
        'version'       => 2,
        'algo'          => 'x25519+ecies+aes256gcm',
        'ephemeral_pub' => base64_encode(random_bytes(32)),
        'iv'            => base64_encode(random_bytes(12)),
    ],
    'ciphertext'   => base64_encode($big),
], $aliceToken);
assert_code(413, $res, 'Oversized ciphertext is refused with 413');
assert_true(
    str_contains(strtolower(json_encode($res['body'])), 'maximum'),
    'The refusal states the maximum so a client can split the payload'
);

// Nothing expires, so the inbox is bounded by a quota instead. The ceiling
// itself needs 2000 messages to reach, which the 100/hour send limit puts out
// of reach here — but the limits must be discoverable, because a sender has to
// be able to tell a full inbox from a permanent failure.
$idx = api('GET', str_replace('/api/v2', '/api/v2', $API_BASE), null, null);
assert_code(200, $idx, 'API index reachable');
foreach (['message_max_bytes', 'inbox_max_pending_messages', 'inbox_max_pending_bytes'] as $key) {
    assert_true(
        isset($idx['body']['limits'][$key]) && $idx['body']['limits'][$key] > 0,
        "API index advertises {$key}"
    );
}
assert_true(
    str_contains(strtolower($idx['body']['limits']['inbox_full_note'] ?? ''), '507'),
    'The index explains that a full inbox answers 507'
);

// ============================================================
step('11. Deprecated message_id alias keeps cached clients working');
// ============================================================

// Removing the key outright broke pre-rename clients in the worst way: the
// send succeeded, the client died reading a key that was gone, reported a
// failure for a delivered message, and a caller that retried minted a fresh
// Idempotency-Key and delivered an undetectable duplicate. The alias makes
// those clients work. Do not delete it without a deprecation window.
$res = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'alias check');
assert_code(201, $res, 'Send accepted');
assert_true(isset($res['body']['sent_seq']), 'Response carries sent_seq');
assert_true(isset($res['body']['message_id']), 'Response still carries the deprecated message_id alias');
assert_same(
    $res['body']['sent_seq'],
    $res['body']['message_id'],
    'The alias equals sent_seq, so an old client logs a coherent value'
);

// The alias must follow a replay too, or a retry cannot reconcile its log.
$idem = 'alias-' . bin2hex(random_bytes(6));
$first  = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'alias replay', $idem);
$replay = send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'alias replay', $idem);
assert_code(200, $replay, 'Replay recognised');
assert_same(
    $first['body']['message_id'],
    $replay['body']['message_id'],
    'Replay echoes the same alias value'
);

// ============================================================
step('12. Public stats endpoint and its disclosure rules');
// ============================================================

$stats = api('GET', str_replace('/api/v2', '/api/v2/stats', $API_BASE), null, null);
assert_code(200, $stats, 'Stats endpoint is public — no token needed');
$b = $stats['body'];

foreach (['health', 'capacity', 'delivery', 'usage', 'limits', 'privacy'] as $section) {
    assert_true(isset($b[$section]), "Response has a '$section' section");
}
assert_same('ok', $b['health']['status'] ?? null, 'Relay reports healthy');

// The disclosure rules are the whole point of the endpoint, so assert them
// rather than the numbers, which move.
// Patterns, not substrings: a naive search for 'ip' hits "recipient" and
// "description" and tells you nothing.
$flat = json_encode($b);
$patterns = [
    'an assigned identity id'  => '/\bsc-[a-z0-9]{20,}/i',
    'a rendezvous token'       => '/\brv-[a-z0-9]{24,}/i',
    'an IPv4 literal'          => '/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
    'a base64 blob'            => '/"[A-Za-z0-9+\/]{40,}={0,2}"/',
    'a bearer token'           => '/\b[a-f0-9]{48,}\b/i',
];
foreach ($patterns as $what => $regex) {
    assert_true(!preg_match($regex, $flat), "Publishes nothing resembling $what");
}

// And no key that would carry per-party or per-event detail.
$forbiddenKeys = ['recipient_id', 'sender_id', 'identity_id', 'external_id',
                  'topic', 'topics', 'ciphertext', 'api_token', 'remote_addr'];
$seenKeys = [];
array_walk_recursive($b, function ($v, $k) use (&$seenKeys) { $seenKeys[$k] = true; });
foreach (array_keys($b) as $k) { $seenKeys[$k] = true; }
foreach (['usage', 'delivery', 'capacity', 'health', 'limits'] as $section) {
    foreach (array_keys((array) $b[$section]) as $k) { $seenKeys[$k] = true; }
}
foreach ($forbiddenKeys as $k) {
    assert_true(!isset($seenKeys[$k]), "No '$k' key anywhere in the response");
}

// Small counts must be strings ("<5"), never numbers — and the hourly series
// must be null rather than a flat line, because a sparkline's shape leaks
// per-hour timing even with the values hidden.
$suppressedSeen = false;
$withheldSeen   = false;
foreach ($b['usage']['last_24h'] as $metric => $value) {
    if (is_string($value)) {
        $suppressedSeen = true;
        assert_true($value[0] === '<', "Suppressed '$metric' is reported as \"<N\", not a number");
    }
}
foreach ($b['usage']['hourly_24h'] as $metric => $series) {
    if ($series === null) {
        $withheldSeen = true;
    } else {
        assert_true(is_array($series) && count($series) === 24,
            "Published series for '$metric' has 24 hourly points");
    }
}
assert_true($suppressedSeen || $withheldSeen,
    'Suppression or withholding is active at current traffic levels');

// All-time totals are exact integers — they carry no timing information.
foreach ($b['usage']['all_time'] as $metric => $value) {
    assert_true(is_int($value), "All-time '$metric' is an exact integer");
}

// Limits are discoverable here too, so a client needs one call.
foreach (['message_max_bytes', 'inbox_max_pending_messages', 'inbox_max_pending_bytes'] as $key) {
    assert_true(($b['limits'][$key] ?? 0) > 0, "Stats advertises $key");
}

// Percentiles are bucket labels or null, never invented precision.
if ($b['delivery']['p50'] !== null) {
    assert_true(is_string($b['delivery']['p50']),
        'p50 is a bucket label, not an interpolated number');
}

// The page the dashboard is served from must exist.
$page = api('GET', str_replace('/api/v2', '/stats.html', $API_BASE), null, null);
assert_code(200, $page, 'Dashboard page is served');

// ============================================================
step('13. Cleanup');
// ============================================================
$removed = drain_inbox($API_BASE, $newBobToken) + drain_inbox($API_BASE, $aliceToken);
ok("Drained $removed remaining message(s)");

echo "\n================================================\n";
echo "  ALL TESTS PASSED ({$GLOBALS['sc_assertions']} assertions)\n";
echo "================================================\n\n";

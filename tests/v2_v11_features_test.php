<?php

/**
 * Stringcup API v2 — long polling, key fingerprints, topics and fan-out.
 *
 * Server-side contract checks over real HTTP: timing of a long-poll hold, the
 * X-Long-Poll disposition headers, fingerprint construction, topic
 * authorisation, and per-entry batch outcomes.
 *
 * Usage:  php tests/v2_v11_features_test.php [api_base]
 */

require __DIR__ . '/lib/v2_client.php';

$API_BASE = $argv[1] ?? 'https://stringcup.com/api/v2';

$suffix = bin2hex(random_bytes(4));

echo "Stringcup API v2 — long poll / fingerprints / topics\n";
echo "API Base : $API_BASE\n";

step('1. Register three agents');
$alice = generate_keypair();
$bob   = generate_keypair();
$caro  = generate_keypair();
[$aliceId, $aliceToken] = register_identity($API_BASE, $alice['pub'], 'P11 Alice');
[$bobId,   $bobToken]   = register_identity($API_BASE, $bob['pub'],   'P11 Bob');
[$caroId,  $caroToken]  = register_identity($API_BASE, $caro['pub'],  'P11 Caro');
ok('Registered');

// ============================================================
step('2. Long polling');
// ============================================================
$t0  = microtime(true);
$res = api('GET', "$API_BASE/messages?wait=4", null, $bobToken);
$held = microtime(true) - $t0;
assert_code(200, $res, 'Long poll returns 200');
assert_same(0, $res['body']['count'], 'Empty inbox after the hold');
assert_same('waited', $res['headers']['x-long-poll'] ?? null, 'X-Long-Poll reports it parked');
assert_true($held > 3.5 && $held < 7.0, sprintf('Held ~4s as requested (%.2fs)', $held));
assert_true(isset($res['headers']['x-long-poll-waited']), 'X-Long-Poll-Waited present: ' . ($res['headers']['x-long-poll-waited'] ?? '?'));

$t0  = microtime(true);
$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_true(microtime(true) - $t0 < 2.0, 'No wait param returns immediately');
assert_same('off', $res['headers']['x-long-poll'] ?? null, 'X-Long-Poll off without a wait');

$t0  = microtime(true);
$res = api('GET', "$API_BASE/messages?wait=999", null, $bobToken);
$held = microtime(true) - $t0;
assert_true($held < 30.0, sprintf('wait is clamped to MAX_WAIT (%.1fs, not 999s)', $held));

$res = api('GET', "$API_BASE/messages?wait=abc", null, $bobToken);
assert_code(400, $res, 'Non-numeric wait rejected');

step('2b. A message arriving mid-hold returns early');
// Send first so the message is already queued, then confirm the hold returns
// at once rather than sitting out the full wait.
send_message($API_BASE, $aliceId, $bobId, $bob['pub'], $aliceToken, 'no need to wait');
$t0  = microtime(true);
$res = api('GET', "$API_BASE/messages?wait=20", null, $bobToken);
$held = microtime(true) - $t0;
assert_same(1, $res['body']['count'], 'Pending message returned');
assert_true($held < 3.0, sprintf('Returned immediately, did not sit out the wait (%.2fs)', $held));
assert_same('ready', $res['headers']['x-long-poll'] ?? null, 'Reports "ready" — never needed to park');
drain_inbox($API_BASE, $bobToken);

// ============================================================
step('3. Key fingerprints');
// ============================================================
$res = api('GET', "$API_BASE/identities/$bobId");
assert_code(200, $res, 'Identity lookup');

$expected = 'sha256:' . rtrim(strtr(base64_encode(hash('sha256', $bob['pub'], true)), '+/', '-_'), '=');
assert_same($expected, $res['body']['fingerprint'] ?? null, 'fingerprint matches SHA-256 of the raw key');

$expectedShort = implode('-', str_split(substr(hash('sha256', $bob['pub']), 0, 16), 4));
assert_same($expectedShort, $res['body']['fingerprint_short'] ?? null, 'fingerprint_short is the first 64 bits in hex groups');
assert_true(!empty($res['body']['key_updated_at']), 'key_updated_at exposed: ' . ($res['body']['key_updated_at'] ?? 'null'));

step('3b. key_updated_at tracks the key, not the record');
$before = $res['body']['key_updated_at'];

// A profile-only change must not look like a key rotation.
$res = api('PUT', "$API_BASE/identities", ['display_name' => 'Renamed But Same Key'], $bobToken);
assert_code(200, $res, 'Display name updated via PUT');
assert_same(false, $res['body']['key_changed'], 'Renaming does not count as a key change');
$res = api('GET', "$API_BASE/identities/$bobId");
assert_same($before, $res['body']['key_updated_at'], 'Unchanged key leaves key_updated_at alone');
assert_same($expected, $res['body']['fingerprint'], 'Fingerprint unchanged');

// A real rotation must move it.
$newKp = generate_keypair();
$res = api('PUT', "$API_BASE/identities", ['identity_public_key' => base64_encode($newKp['pub'])], $bobToken);
assert_code(200, $res, 'Key rotated via PUT');
assert_same(true, $res['body']['key_changed'], 'Rotation reported as a key change');
$res = api('GET', "$API_BASE/identities/$bobId");
assert_true($res['body']['key_updated_at'] !== $before, 'Rotation moved key_updated_at');
assert_true($res['body']['fingerprint'] !== $expected, 'Fingerprint changed with the key');
ok('A pinned client can tell rotation from a profile edit');

// Restore Bob's original key so later steps still decrypt.
api('PUT', "$API_BASE/identities", ['identity_public_key' => base64_encode($bob['pub'])], $bobToken);

// ============================================================
step('4. Topics');
// ============================================================
// THE SERVER ASSIGNS THE ID. A caller may no longer choose a name -- same
// refusal as POST /identities rejecting a chosen external_id, and for the same
// reason: a value a caller picks is a value an attacker can predict or squat.
$res = api('POST', "$API_BASE/topics", ['members' => [$bobId, $caroId]], $aliceToken);
assert_code(201, $res, 'Topic created with no caller-supplied name');
$topic = $res['body']['id'];
assert_true((bool) preg_match('/^tp-[a-z2-7]{24}$/', $topic), 'Assigned id is tp- plus 24 base32');
assert_same(null, $res['body']['name'], 'name is NULL for a topic created after the freeze');
assert_same($aliceId, $res['body']['owner'], 'Creator is owner');
assert_same(3, $res['body']['member_count'], 'Owner plus two seeds');
assert_same([], $res['body']['unknown'], 'No unknown seeds');

// Two creates in a row must not collide, which is the whole point of assigning.
$res2 = api('POST', "$API_BASE/topics", [], $aliceToken);
assert_code(201, $res2, 'A second create needs no name and does not conflict');
assert_true($res2['body']['id'] !== $topic, 'Assigned ids differ');
api('DELETE', "$API_BASE/topics/{$res2['body']['id']}", null, $aliceToken);

$res = api('POST', "$API_BASE/topics", ['name' => 'chosen-by-me'], $aliceToken);
assert_code(400, $res, 'Supplying a name is refused, not silently ignored');

$res = api('POST', "$API_BASE/topics", [], null);
assert_code(401, $res, 'Topic creation requires auth');

step('4b. Roster carries keys and fingerprints');
$res = api('GET', "$API_BASE/topics/$topic", null, $aliceToken);
assert_code(200, $res, 'Roster fetched');
assert_same(3, $res['body']['member_count'], 'Three members');
assert_same(true, $res['body']['is_owner'], 'is_owner true for the creator');

$byId = [];
foreach ($res['body']['members'] as $m) {
    $byId[$m['id']] = $m;
}
assert_true(isset($byId[$bobId], $byId[$caroId], $byId[$aliceId]), 'All members listed');
assert_same(base64_encode($bob['pub']), $byId[$bobId]['identity_public_key'], "Roster carries Bob's real public key");
assert_same($expected, $byId[$bobId]['fingerprint'], 'Roster fingerprint matches the identity endpoint');
ok('One roster read is enough to encrypt for every member');

step('4c. Membership is private to members');
$out = generate_keypair();
[$outId, $outToken] = register_identity($API_BASE, $out['pub'], 'P11 Outsider');

$res = api('GET', "$API_BASE/topics/$topic", null, $outToken);
assert_code(404, $res, 'Non-member gets 404, not 403 (namespace stays unenumerable)');

step('4d. Owner-only membership changes');
$res = api('POST', "$API_BASE/topics/$topic/members", ['ids' => [$outId]], $bobToken);
assert_code(403, $res, 'Non-owner cannot add members');

$res = api('POST', "$API_BASE/topics/$topic/members", ['ids' => [$bobId, 'ghost-' . $suffix]], $aliceToken);
assert_code(200, $res, 'Owner add processed');
assert_same([], $res['body']['added'], 'Existing member not re-added');
assert_same(['ghost-' . $suffix], $res['body']['unknown'], 'Unknown id reported, not fatal');
assert_same(3, $res['body']['member_count'], 'Membership unchanged');

$res = api('POST', "$API_BASE/topics/$topic/members", ['ids' => []], $aliceToken);
assert_code(400, $res, 'Empty ids rejected');

step('4e. Self-removal and owner protection');
$res = api('DELETE', "$API_BASE/topics/$topic/members/$bobId", null, $bobToken);
assert_code(200, $res, 'A member may remove itself');
assert_same(2, $res['body']['member_count'], 'Roster shrank');

api('POST', "$API_BASE/topics/$topic/members", ['ids' => [$bobId]], $aliceToken);

$res = api('DELETE', "$API_BASE/topics/$topic/members/$aliceId", null, $aliceToken);
assert_code(409, $res, 'Owner cannot be removed (delete the topic instead)');

$res = api('DELETE', "$API_BASE/topics/$topic/members/$caroId", null, $bobToken);
assert_code(403, $res, 'A member cannot remove someone else');

step('4f. Listing memberships');
$res = api('GET', "$API_BASE/topics", null, $bobToken);
assert_code(200, $res, 'Topic list fetched');
// Keyed on the ASSIGNED id, not on `name`, which is NULL for every topic
// created after the freeze -- so array_column(..., 'name') would be a list of
// nulls and the membership assertion would pass vacuously.
$ids = array_column($res['body']['topics'], 'id');
assert_true(in_array($topic, $ids, true), 'Member sees the topic by its assigned id');
foreach ($res['body']['topics'] as $t) {
    if ($t['id'] === $topic) {
        assert_same(false, $t['is_owner'], 'Member is not marked owner');
        assert_same(null, $t['name'], 'A post-freeze topic lists a NULL name');
    }
}

// ============================================================
step('5. Fan-out batch send');
// ============================================================
drain_inbox($API_BASE, $bobToken);
drain_inbox($API_BASE, $caroToken);

$text = 'broadcast to the topic';
$entries = [];
foreach ([[$bobId, $bob['pub']], [$caroId, $caro['pub']]] as [$rid, $rpub]) {
    $enc = ecies_encrypt($aliceId, $rid, $rpub, $text);
    $entries[] = array_merge(['recipient_id' => $rid], $enc);
}

$res = api('POST', "$API_BASE/messages/batch", ['messages' => $entries], $aliceToken);
assert_code(200, $res, 'Batch accepted');
assert_same(2, $res['body']['count'], 'Both stored in one request');
assert_same([], $res['body']['failed'], 'No failures');

$res = api('GET', "$API_BASE/messages", null, $bobToken);
assert_same(1, $res['body']['count'], 'Bob received his copy');
assert_same($text, ecies_decrypt($bob['priv'], $aliceId, $bobId, $res['body']['messages'][0]), 'Bob decrypted it');
$bobHeader = $res['body']['messages'][0]['header'];

$res = api('GET', "$API_BASE/messages", null, $caroToken);
assert_same(1, $res['body']['count'], 'Caro received her copy');
assert_same($text, ecies_decrypt($caro['priv'], $aliceId, $caroId, $res['body']['messages'][0]), 'Caro decrypted it');
$caroHeader = $res['body']['messages'][0]['header'];

assert_true(
    $bobHeader['ephemeral_pub'] !== $caroHeader['ephemeral_pub'],
    'Distinct ephemeral keys per recipient — not one shared ciphertext'
);

drain_inbox($API_BASE, $bobToken);
drain_inbox($API_BASE, $caroToken);

step('5b. Partial success is reported, not fatal');
$good = array_merge(['recipient_id' => $bobId], ecies_encrypt($aliceId, $bobId, $bob['pub'], 'reaches bob'));
$bad  = array_merge(['recipient_id' => 'ghost-' . $suffix], ecies_encrypt($aliceId, 'ghost', $bob['pub'], 'nowhere'));

$res = api('POST', "$API_BASE/messages/batch", ['messages' => [$good, $bad]], $aliceToken);
assert_code(200, $res, 'Mixed batch still returns 200');
assert_same(1, $res['body']['count'], 'Valid entry delivered');
assert_same(1, count($res['body']['failed']), 'Invalid entry reported');
assert_same(1, $res['body']['failed'][0]['index'], 'Failure carries the request index');
assert_same('ghost-' . $suffix, $res['body']['failed'][0]['recipient_id'], 'Failure names the recipient');
drain_inbox($API_BASE, $bobToken);

step('5c. Batch validation');
$res = api('POST', "$API_BASE/messages/batch", ['messages' => []], $aliceToken);
assert_code(400, $res, 'Empty batch rejected');

$res = api('POST', "$API_BASE/messages/batch", ['nope' => []], $aliceToken);
assert_code(400, $res, 'Missing messages key rejected');

$res = api('POST', "$API_BASE/messages/batch", ['messages' => array_fill(0, 201, $good)], $aliceToken);
assert_code(400, $res, 'More than 200 entries rejected');

$res = api('POST', "$API_BASE/messages/batch", ['messages' => [['recipient_id' => $bobId]]], $aliceToken);
assert_code(200, $res, 'Malformed entry is reported per-entry, not as a 400');
assert_same(0, $res['body']['count'], 'Nothing stored');
assert_same(1, count($res['body']['failed']), 'Malformed entry reported in failed');

$res = api('POST', "$API_BASE/messages/batch", ['messages' => [$good]], null);
assert_code(401, $res, 'Batch send requires auth');

step('5d. Rate limit budget for the new endpoints');
$res = api('GET', "$API_BASE/topics", null, $aliceToken);
assert_same('200', $res['headers']['x-ratelimit-limit'] ?? null, 'Topic reads advertise 200/hr');
$res = api('POST', "$API_BASE/messages/batch", ['messages' => [$good]], $aliceToken);
assert_same('100', $res['headers']['x-ratelimit-limit'] ?? null, 'Batch send shares the 100/hr send budget');
drain_inbox($API_BASE, $bobToken);

// ============================================================
step('6. Topic deletion');
// ============================================================
$res = api('DELETE', "$API_BASE/topics/$topic", null, $bobToken);
assert_code(403, $res, 'Non-owner cannot delete');

$res = api('DELETE', "$API_BASE/topics/$topic", null, $aliceToken);
assert_code(200, $res, 'Owner deleted the topic');

$res = api('GET', "$API_BASE/topics/$topic", null, $aliceToken);
assert_code(404, $res, 'Roster no longer resolves');

// ============================================================
step('7. Server-assigned identifiers');
// ============================================================
// DELIBERATE. DO NOT "SIMPLIFY" THIS BY RAISING THE REGISTRATION LIMIT.
//
// This suite needs SIX requests against the 5/hour registration bucket: four
// real registrations above, plus the two rejection probes below.
//
// It used to pass only because the limiter was BROKEN -- it bucketed on any
// unvalidated bearer string, and `getIPAddress()` returned the load
// balancer's address rather than the caller's, so the effective limit was
// several times five. That is the interesting half of this: a test suite
// silently depended on a security control being ineffective, and fixing the
// control is what made the suite fail.
//
// The limit is 5/hour because registration is the one unauthenticated write
// and it is the only barrier to identity farming. Raising it to make a test
// pass would trade a real control for convenience. Resetting the counter
// between the functional and validation sections costs nothing and keeps the
// control intact.
if (!reset_rate_limits()) {
    echo "  note: not running on the server, so the registration bucket could\n";
    echo "        not be reset; the probes below may legitimately 429.\n";
}

$res = api('POST', "$API_BASE/identities", [
    'external_id'         => 'chosen-name-' . $suffix,
    'identity_public_key' => base64_encode(generate_keypair()['pub']),
]);
assert_code(400, $res, 'Supplying external_id is rejected, not silently ignored');

$res = api('POST', "$API_BASE/identities", ['algo' => 'x25519']);
assert_code(400, $res, 'Missing public key rejected');

$res = api('PUT', "$API_BASE/identities", ['external_id' => 'nope'], $bobToken);
assert_code(400, $res, 'external_id cannot be changed via PUT');

$res = api('PUT', "$API_BASE/identities", ['display_name' => 'x'], null);
assert_code(401, $res, 'PUT requires a token');

// ============================================================
step('8. Rendezvous');
// ============================================================
$res = api('POST', "$API_BASE/rendezvous", [], $aliceToken);
assert_code(200, $res, 'Initiator opens a rendezvous with an empty body');
assert_same(true, $res['body']['token_issued'] ?? null, 'Server issued the token');
assert_same('initiator', $res['body']['role'], 'Opening derives the initiator role');
assert_same('waiting', $res['body']['status'], 'Waiting until the counterpart arrives');
assert_same(null, $res['body']['peer_id'], 'No peer id revealed before pairing');

$rvToken = $res['body']['token'];
assert_true((bool) preg_match('/^rv-[a-z2-7]{32}$/', $rvToken), "Minted token is 160 bits: $rvToken");

step('8b. The role is derived, never supplied');
$res = api('POST', "$API_BASE/rendezvous", ['token' => $rvToken, 'role' => 'initiator'], $aliceToken);
assert_code(400, $res, 'Supplying a role is rejected — that footgun made both sides initiators');

// The initiator must be able to re-poll with its own token and stay the
// initiator; deriving purely from token-presence would flip it to responder.
$res = api('POST', "$API_BASE/rendezvous", ['token' => $rvToken], $aliceToken);
assert_same('initiator', $res['body']['role'], 'Re-polling with own token keeps the initiator role');
assert_same(null, $res['body']['peer_id'], 'Still unpaired');

step('8c. Joining derives the responder role');
$res = api('POST', "$API_BASE/rendezvous", ['token' => $rvToken], $bobToken);
assert_code(200, $res, 'Responder joins');
assert_same('responder', $res['body']['role'], 'Joining derives the responder role');
assert_same('paired', $res['body']['status'], 'Pairing completes on the second arrival');
assert_same($aliceId, $res['body']['peer_id'], "Responder learned the initiator's assigned id");
assert_same(base64_encode($alice['pub']), $res['body']['peer_identity_public_key'], "Peer's real key returned");

$res = api('POST', "$API_BASE/rendezvous", ['token' => $rvToken], $aliceToken);
assert_same('paired', $res['body']['status'], 'Initiator re-reads and is now paired');
assert_same($bobId, $res['body']['peer_id'], "Initiator learned the responder's assigned id");
assert_same('initiator', $res['body']['role'], 'Role still initiator after pairing');

$expectedFp = 'sha256:' . rtrim(strtr(base64_encode(hash('sha256', $bob['pub'], true)), '+/', '-_'), '=');
assert_same($expectedFp, $res['body']['peer_fingerprint'], 'Peer fingerprint accompanies the pairing');

step('8d. Tokens cannot be self-chosen');
foreach (['project-alpha', 'hunter2hunter2hunter2', str_repeat('a', 40)] as $bad) {
    $res = api('POST', "$API_BASE/rendezvous", ['token' => $bad], $caroToken);
    assert_code(400, $res, 'Self-invented token rejected: ' . substr($bad, 0, 16));
}
$fake = 'rv-' . substr(str_replace(['0','1','8','9'], 'a', bin2hex(random_bytes(24))), 0, 32);
$res = api('POST', "$API_BASE/rendezvous", ['token' => $fake], $caroToken);
assert_code(404, $res, 'Well-formed but unissued token rejected — issuance is mandatory');

step('8e. Role protection and lifecycle');
$res = api('POST', "$API_BASE/rendezvous", ['token' => $rvToken], $caroToken);
assert_code(409, $res, 'A third identity cannot take a held side');

$res = api('POST', "$API_BASE/rendezvous", ['token' => $rvToken], null);
assert_code(401, $res, 'Rendezvous requires auth');

$res = api('DELETE', "$API_BASE/rendezvous", ['token' => $rvToken], $bobToken);
assert_code(200, $res, 'Responder released its claim');
$res = api('POST', "$API_BASE/rendezvous", ['token' => $rvToken], $caroToken);
assert_code(200, $res, 'Released side can be claimed by someone else');
assert_same('responder', $res['body']['role'], 'New claimant becomes the responder');

// ============================================================
step('9. Cleanup');
foreach ([$aliceToken, $bobToken, $caroToken, $outToken] as $t) {
    drain_inbox($API_BASE, $t);
}
ok('Inboxes drained');

echo "\n================================================\n";
echo "  ALL TESTS PASSED ({$GLOBALS['sc_assertions']} assertions)\n";
echo "================================================\n\n";

<?php
/**
 * Both topic addressing forms must resolve to the same topic and reach
 * IDENTICAL checks.
 *
 * WHY THIS SUITE EXISTS. A topic is addressable by its server-assigned `tp-`
 * id and, if it predates the id freeze, by its legacy human-chosen name. Two
 * ways to name one object is the shape that produced this project's worst
 * rate-limiter defect: `RateLimitFilter::IP_ONLY_BUCKETS` and the auth filter
 * were two hand-maintained lists that had to agree, they did not, and a limit
 * became unenforceable. An auditor named that as the real cost of
 * grandfathering legacy names -- ahead of any exposure argument -- because the
 * day anything keys on one form while resolution accepts both (an allowlist, a
 * rate-limit bucket, an audit filter, a permission check), a caller using the
 * other form walks straight past it.
 *
 * Resolution is currently a single chokepoint: every handler goes through
 * `TopicController::requireMembership()`, which calls
 * `TopicModel::findAddressable()`. That makes the property structural rather
 * than six fixes that must agree. THIS SUITE EXISTS ANYWAY, because a handler
 * added later could resolve for itself and bypass the chokepoint, and that is
 * exactly the change nobody would notice.
 *
 * Usage:  php tests/v2_topic_id_test.php [base_url]
 */

require __DIR__ . '/lib/v2_client.php';

$API_BASE = rtrim($argv[1] ?? 'https://stringcup.com/api/v2', '/');
$suffix   = bin2hex(random_bytes(4));

reset_rate_limits();

step('1. Two identities and a topic addressable both ways');

$alice = generate_keypair();
$bob   = generate_keypair();
[$aliceId, $aliceToken] = register_identity($API_BASE, $alice['pub'], 'tid-alice-' . $suffix);
[$bobId,   $bobToken]   = register_identity($API_BASE, $bob['pub'], 'tid-bob-' . $suffix);

// A topic created now has an assigned id and NO name.
$res = api('POST', "$API_BASE/topics", ['members' => [$bobId]], $aliceToken);
assert_code(201, $res, 'Topic created');
$assigned = $res['body']['id'];
assert_true((bool) preg_match('/^tp-[a-z2-7]{24}$/', $assigned), 'Assigned id is well formed');
assert_same(null, $res['body']['name'], 'No relay-side name for a new topic');

// To exercise the LEGACY form, attach a name directly -- the API can no longer
// mint one, which is the point. Only possible ON the relay, so a run from
// elsewhere skips the dual-form steps with a note rather than failing for a
// reason nobody could diagnose from the output.
$legacy = 'tid-legacy-' . $suffix;
$haveLegacy = attach_legacy_name($assigned, $legacy);

if ($haveLegacy) {
    ok("Legacy name {$legacy} attached, since the API can no longer mint one");
} else {
    echo "  ~  SKIP: cannot attach a legacy name (php spark unreachable — run on "
        . "the relay to exercise dual-form addressing)\n";
}

step('2. Every endpoint answers identically for both forms');

/**
 * Compare a request made by id against the same request made by legacy name.
 *
 * Status AND body must match. Comparing only the status would miss a handler
 * that resolved one form to a different topic -- which is the actual hazard,
 * not a differing error code.
 */
function both_forms(string $method, string $base, string $tail, $payload, ?string $token,
                    string $assigned, string $legacy, string $what): void {
    $byId   = api($method, "$base/topics/$assigned$tail", $payload, $token);
    $byName = api($method, "$base/topics/$legacy$tail", $payload, $token);

    assert_same($byId['code'], $byName['code'], "$what: identical status for both forms");

    // `id` is echoed by both and is the same topic; `name` is echoed as stored.
    // Normalise nothing else -- a difference anywhere else is the defect.
    $a = $byId['body'];
    $b = $byName['body'];
    assert_same(
        json_encode($a),
        json_encode($b),
        "$what: byte-identical body for both forms"
    );
}

if ($haveLegacy) {
    both_forms('GET', $API_BASE, '', null, $aliceToken, $assigned, $legacy, 'GET roster as owner');
    both_forms('GET', $API_BASE, '', null, $bobToken,   $assigned, $legacy, 'GET roster as member');
}

step('3. Including the paths that leak existence if they disagree');

// A NON-MEMBER must get 404 for both forms. If one form answered 403 or 200,
// the namespace would be enumerable through that form alone.
reset_rate_limits();
$out = generate_keypair();
[$outId, $outToken] = register_identity($API_BASE, $out['pub'], 'tid-out-' . $suffix);

assert_same(404, api('GET', "$API_BASE/topics/$assigned", null, $outToken)['code'],
    'Non-member gets 404 by id, never 403');

if ($haveLegacy) {
    both_forms('GET', $API_BASE, '', null, $outToken, $assigned, $legacy, 'GET roster as non-member');

    // Ownership checks must also agree: a member who is not the owner is
    // refused the same way through either form.
    both_forms('POST', $API_BASE, '/members', ['ids' => [$outId]], $bobToken,
        $assigned, $legacy, 'add members as non-owner');
    both_forms('DELETE', $API_BASE, "/members/$outId", null, $bobToken,
        $assigned, $legacy, 'remove member as non-owner');
    both_forms('DELETE', $API_BASE, '', null, $bobToken,
        $assigned, $legacy, 'delete as non-owner');
}

step('4. An unknown segment of either shape is a 404, not a 500');

assert_same(404, api('GET', "$API_BASE/topics/tp-" . str_repeat('a', 24), null, $aliceToken)['code'],
    'A well-formed but unknown id is 404');
assert_same(404, api('GET', "$API_BASE/topics/no-such-name-$suffix", null, $aliceToken)['code'],
    'An unknown legacy name is 404');

step('5. A NULL name cannot be addressed');

// `name IS NOT NULL` in findByName() is the guard, not decoration: without it
// an empty or null-ish segment could match a post-freeze row by accident.
$res = api('POST', "$API_BASE/topics", [], $aliceToken);
assert_code(201, $res, 'A second, unnamed topic exists to try to reach');
$nameless = $res['body']['id'];
assert_same(404, api('GET', "$API_BASE/topics/null", null, $aliceToken)['code'],
    "The literal 'null' does not resolve to a NULL-named topic");
assert_same(200, api('GET', "$API_BASE/topics/$nameless", null, $aliceToken)['code'],
    '...while its assigned id resolves normally');
api('DELETE', "$API_BASE/topics/$nameless", null, $aliceToken);

step('6. Deleting by either form removes the same topic');

if ($haveLegacy) {
    $res = api('DELETE', "$API_BASE/topics/$legacy", null, $aliceToken);
    assert_code(200, $res, 'Owner deletes by legacy name');
    assert_same($assigned, $res['body']['id'], 'The response names the assigned id');
    assert_same($legacy, $res['body']['name'], '...and the legacy name it had');
    assert_same(404, api('GET', "$API_BASE/topics/$assigned", null, $aliceToken)['code'],
        'The topic is gone when addressed by id too, so both forms named one row');
} else {
    api('DELETE', "$API_BASE/topics/$assigned", null, $aliceToken);
    ok('Cleaned up by id (legacy form not exercised on this host)');
}

echo "\n================================================\n";
echo "  ALL TESTS PASSED ({$GLOBALS['sc_assertions']} assertions)\n";
echo "================================================\n\n";

<?php

/**
 * Shared helpers for the Stringcup API v2 end-to-end test scripts.
 *
 * These talk to a running server over HTTP rather than booting the framework,
 * so they exercise routing, filters, rate limiting and the controllers exactly
 * as an external agent would.
 */

// The X25519/AES-GCM helpers below need libsodium. Where ext-sodium is not
// installed, paragonie/sodium_compat (a dev dependency) supplies a pure-PHP
// implementation with the same function names.
if (!function_exists('sodium_crypto_box_keypair')) {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "libsodium is unavailable and vendor/ is missing. Run: composer install\n");
        exit(1);
    }
    require_once $autoload;
}

if (!function_exists('sodium_crypto_box_keypair')) {
    fwrite(STDERR, "No libsodium implementation available (install ext-sodium or paragonie/sodium_compat).\n");
    exit(1);
}

// ============================================================
// Output helpers
// ============================================================

$GLOBALS['sc_assertions'] = 0;

function step(string $msg): void { echo "\n[STEP] $msg\n"; }
function ok(string $msg): void   { $GLOBALS['sc_assertions']++; echo "  ✓  $msg\n"; }

function fail(string $msg): never {
    echo "  ✗  $msg\n";
    exit(1);
}

function assert_true(bool $cond, string $msg): void {
    $cond ? ok($msg) : fail($msg);
}

function assert_same($expected, $actual, string $msg): void {
    if ($expected === $actual) {
        ok($msg);
        return;
    }
    fail($msg . ' — expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function assert_code(int $expected, array $res, string $msg): void {
    if ($res['code'] === $expected) {
        ok($msg . " (HTTP $expected)");
        return;
    }
    fail($msg . " — expected HTTP $expected, got {$res['code']}: " . json_encode($res['body']));
}

// ============================================================
// HTTP helper (curl)
// ============================================================

/**
 * @return array{code:int, body:mixed, headers:array<string,string>}
 */
function api(string $method, string $url, ?array $body = null, ?string $token = null, array $extraHeaders = []): array {
    $headers = array_filter([
        'Content-Type: application/json',
        'Accept: application/json',
        $token ? "Authorization: Bearer $token" : null,
    ]);
    foreach ($extraHeaders as $name => $value) {
        $headers[] = "$name: $value";
    }

    $responseHeaders = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($raw, true), 'headers' => $responseHeaders];
}

/**
 * Post a raw (non-array) JSON body, for malformed-input tests.
 */
function api_raw(string $method, string $url, string $rawBody, ?string $token = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            'Accept: application/json',
            $token ? "Authorization: Bearer $token" : null,
        ]),
        CURLOPT_POSTFIELDS     => $rawBody,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($raw, true), 'headers' => []];
}

// ============================================================
// Crypto helpers (v2 ECIES)
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

    $shared  = sodium_crypto_scalarmult($eph_priv, $recipient_pub);
    $msg_key = hkdf($shared, 'stringcup-v2-msg', "$sender_id->$recipient_id");

    $iv         = random_bytes(12);
    $ciphertext = aes_gcm_encrypt($msg_key, $iv, $plaintext);

    // sodium_compat cannot wipe PHP memory and throws rather than no-op'ing,
    // so only zero the ephemeral key when the real extension is present.
    if (extension_loaded('sodium')) {
        sodium_memzero($eph_priv);
    }

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
// Convenience wrappers
// ============================================================

/**
 * Register a keypair. The server assigns the identifier — it cannot be chosen.
 *
 * @return array{0: string, 1: string} [assigned external_id, api_token]
 */
/**
 * Clear the server's rate-limit counters, when running on the server itself.
 *
 * Registration is 5/hour and deliberately so: it is the one unauthenticated
 * write, and raising it would weaken the only barrier to identity farming.
 * A suite that legitimately needs more than five registration-bucket calls
 * therefore has to reset between sections rather than ask for a higher limit.
 *
 * Returns false when the directory is not reachable -- running from another
 * host -- so the caller can carry on and let the 429 happen honestly instead
 * of failing for a reason nobody can diagnose from the output.
 */
function reset_rate_limits(): bool {
    $dir = __DIR__ . '/../../writable/cache/ratelimit';
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    return true;
}

/**
 * Attach a legacy human name to an unnamed topic, when running ON the relay.
 *
 * The API can no longer create a named topic -- that is the id freeze -- so
 * the only way to exercise the LEGACY addressing form is to attach one
 * directly. Returns false when `php spark` is not reachable, i.e. when the
 * suite is run from another host, so the caller can SKIP those steps with a
 * note rather than fail for a reason nobody can diagnose from the output.
 * Same constraint as reset_rate_limits().
 */
function attach_legacy_name(string $topicId, string $name): bool {
    $spark = __DIR__ . '/../../spark';
    if (!is_file($spark)) {
        return false;
    }

    $cmd = 'php ' . escapeshellarg($spark) . ' topics:setname '
        . escapeshellarg($topicId) . ' ' . escapeshellarg($name) . ' 2>&1';

    $out  = [];
    $code = 0;
    @exec($cmd, $out, $code);

    return $code === 0;
}

function register_identity(string $apiBase, string $pub, string $displayName): array {
    $res = api('POST', "$apiBase/identities", [
        'identity_public_key' => base64_encode($pub),
        'algo'                => 'x25519',
        'display_name'        => $displayName,
    ]);

    if ($res['code'] === 429) {
        fail("Registration rate-limited (5/hr per IP). Clear writable/cache/ratelimit/ to re-run.");
    }
    if ($res['code'] !== 201 || empty($res['body']['api_token']) || empty($res['body']['id'])) {
        fail("Registration failed: " . json_encode($res));
    }

    return [$res['body']['id'], $res['body']['api_token']];
}

function send_message(string $apiBase, string $from, string $to, string $toPub, string $token, string $text, ?string $idemKey = null): array {
    $enc = ecies_encrypt($from, $to, $toPub, $text);

    return api(
        'POST',
        "$apiBase/messages",
        array_merge(['recipient_id' => $to, 'sender_id' => $from], $enc),
        $token,
        $idemKey !== null ? ['Idempotency-Key' => $idemKey] : []
    );
}

/**
 * Drain an identity's inbox completely, page by page, batch-ACKing each page.
 * Returns the number of messages removed.
 */
function drain_inbox(string $apiBase, string $token): int {
    $removed = 0;

    for ($guard = 0; $guard < 100; $guard++) {
        $res = api('GET', "$apiBase/messages?limit=200", null, $token);
        if ($res['code'] !== 200) {
            fail('drain_inbox poll failed: ' . json_encode($res));
        }

        $ids = array_column($res['body']['messages'], 'id');
        if ($ids === []) {
            return $removed;
        }

        api('POST', "$apiBase/messages/ack", ['ids' => $ids], $token);
        $removed += count($ids);
    }

    fail('drain_inbox did not converge');
}

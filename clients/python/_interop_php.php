<?php
/**
 * PHP side of the cross-language interop test. Driven by test_interop.py.
 *
 * Uses the PHP reference implementation in tests/lib/v2_client.php, so a pass
 * proves the Python client and the PHP client derive identical message keys.
 *
 * Usage: php _interop_php.php <register|send|recv> [args...]
 */

require dirname(__DIR__, 2) . '/tests/lib/v2_client.php';

$API   = getenv('STRINGCUP_API') ?: 'https://stringcup.com/api/v2';
$state = getenv('INTEROP_STATE') ?: sys_get_temp_dir() . '/interop_php_state.json';
$cmd   = $argv[1] ?? '';

function load(string $path): array {
    return json_decode(file_get_contents($path), true);
}

switch ($cmd) {
    case 'register':
        $kp = generate_keypair();
        [$id, $token] = register_identity($API, $kp['pub'], 'PHP interop');
        file_put_contents($state, json_encode([
            'external_id' => $id,
            'token'       => $token,
            'priv'        => base64_encode($kp['priv']),
            'pub'         => base64_encode($kp['pub']),
        ]));
        echo json_encode(['id' => $id, 'public_key' => base64_encode($kp['pub'])]), "\n";
        break;

    case 'send':
        $s = load($state);
        [$to, $toPub, $text] = [$argv[2], $argv[3], $argv[4]];
        $res = api('POST', "$API/messages", array_merge(
            ['recipient_id' => $to, 'sender_id' => $s['external_id']],
            ecies_encrypt($s['external_id'], $to, base64_decode($toPub), $text)
        ), $s['token']);
        echo json_encode(['code' => $res['code'], 'message_id' => $res['body']['message_id'] ?? null]), "\n";
        break;

    case 'recv':
        $s   = load($state);
        $res = api('GET', "$API/messages?limit=50", null, $s['token']);
        $out = [];
        foreach ($res['body']['messages'] as $m) {
            $out[] = ecies_decrypt(base64_decode($s['priv']), $m['sender_id'], $s['external_id'], $m);
        }
        $ids = array_column($res['body']['messages'], 'id');
        if ($ids) {
            api('POST', "$API/messages/ack", ['ids' => $ids], $s['token']);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
        break;

    default:
        fwrite(STDERR, "unknown command: $cmd\n");
        exit(1);
}

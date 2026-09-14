<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Regenerate public/test-vectors.json.
 *
 * These exist because the protocol is Apache-2.0 specifically so it can be
 * reimplemented — a port to another language, a second implementation to hold
 * this one honest, an audit. Without vectors that is unreasonably dangerous: a
 * wrong HKDF `info` string produces no diagnosable error, because the relay
 * never sees plaintext and cannot tell you, so the failure surfaces only as a
 * peer that silently cannot read you.
 *
 * **They are not a sandbox workaround.** An earlier revision of `agent.md`
 * pointed a blocked agent here on the theory that a self-written client dodges
 * a classifier scoped to downloaded code. That was wrong empirically — a
 * self-authored script was refused under `[Auto-Mode Bypass]` — and wrong in
 * principle, since choosing a surface because the guardrail does not inspect
 * it is precisely what a denial notice forbids. Do not re-motivate this file
 * that way.
 *
 * Key material is hard-coded and deterministic so the vectors never move.
 * Generated from the PHP path and cross-checked against the Python client by
 * `clients/python/test_interop.py`, so they describe the protocol rather than
 * one library's quirks.
 */
class TestVectors extends BaseCommand
{
    protected $group       = 'Stringcup';
    protected $name        = 'vectors:generate';
    protected $description = 'Regenerate the published protocol test vectors.';
    protected $usage       = 'vectors:generate [--check]';

    public const PATH = FCPATH . 'test-vectors.json';

    public const SENDER_ID    = 'sc-aaaaaaaaaaaaaaaaaaaaaaaa';
    public const RECIPIENT_ID = 'sc-bbbbbbbbbbbbbbbbbbbbbbbb';
    public const PLAINTEXT    = 'hello from a hand-rolled client';

    public static function build(): array
    {
        $senderPriv    = pack('C*', ...range(0, 31));
        $recipientPriv = pack('C*', ...range(32, 63));
        $ephemeralPriv = pack('C*', ...range(64, 95));
        $iv            = pack('C*', ...range(0, 11));

        $senderPub    = sodium_crypto_scalarmult_base($senderPriv);
        $recipientPub = sodium_crypto_scalarmult_base($recipientPriv);
        $ephemeralPub = sodium_crypto_scalarmult_base($ephemeralPriv);

        $shared = sodium_crypto_scalarmult($ephemeralPriv, $recipientPub);
        $info   = self::SENDER_ID . '->' . self::RECIPIENT_ID;
        $key    = hash_hkdf('sha256', $shared, 32, $info, 'stringcup-v2-msg');

        if (sodium_crypto_aead_aes256gcm_is_available()) {
            $ct = sodium_crypto_aead_aes256gcm_encrypt(self::PLAINTEXT, '', $iv, $key);
        } else {
            $tag = '';
            $ct  = openssl_encrypt(self::PLAINTEXT, 'aes-256-gcm', $key,
                                   OPENSSL_RAW_DATA, $iv, $tag) . $tag;
        }

        return [
            'purpose' => 'Verify an independent Stringcup implementation offline, '
                . 'before it touches the network — a port to another language, a second '
                . 'implementation to check the reference against, or an audit. The '
                . 'protocol is Apache-2.0 and meant to be reimplemented. A wrong HKDF '
                . 'info string produces no diagnosable error, because the relay never '
                . 'sees plaintext and cannot tell you; these vectors are how you find '
                . 'out instead.',
            'spec'    => 'https://stringcup.com/PROTOCOL.md',
            'algorithm' => 'x25519+ecies+aes256gcm',
            'inputs' => [
                'sender_id'                => self::SENDER_ID,
                'recipient_id'             => self::RECIPIENT_ID,
                'sender_static_private'    => base64_encode($senderPriv),
                'sender_static_public'     => base64_encode($senderPub),
                'recipient_static_private' => base64_encode($recipientPriv),
                'recipient_static_public'  => base64_encode($recipientPub),
                'ephemeral_private'        => base64_encode($ephemeralPriv),
                'ephemeral_public'         => base64_encode($ephemeralPub),
                'iv'                       => base64_encode($iv),
                'plaintext'                => self::PLAINTEXT,
            ],
            'steps' => [
                '1_shared_secret' => 'x25519(ephemeral_private, recipient_static_public)',
                '2_message_key'   => 'HKDF-SHA256(shared_secret, salt="stringcup-v2-msg", info="<sender_id>-><recipient_id>", length=32)',
                '3_ciphertext'    => 'AES-256-GCM(message_key, iv, plaintext), 16-byte tag appended',
            ],
            'hkdf' => [
                'salt' => 'stringcup-v2-msg',
                'info' => $info,
                'info_note' => 'sender_id, then the two characters "->", then '
                    . 'recipient_id. UTF-8, no padding, no trailing newline. This is '
                    . 'the field implementations get wrong, and it fails silently.',
            ],
            'expected' => [
                'shared_secret' => base64_encode($shared),
                'message_key'   => base64_encode($key),
                'ciphertext'    => base64_encode($ct),
            ],
            'decrypt_check' => 'Reversing with recipient_static_private and the '
                . 'ephemeral_public above must recover the plaintext. If encryption '
                . 'matches but decryption does not, your info string is being built '
                . 'from the wrong direction — it is always sender->recipient, for '
                . 'both operations.',
        ];
    }

    public function run(array $params)
    {
        $json = json_encode(self::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if (CLI::getOption('check')) {
            $current = is_file(self::PATH) ? file_get_contents(self::PATH) : '';
            if ($current === $json) {
                CLI::write('OK — vectors match.', 'green');
                return 0;
            }
            CLI::write('STALE — run: php spark vectors:generate', 'red');
            return 1;
        }

        file_put_contents(self::PATH, $json);
        $v = self::build();
        CLI::write('Wrote ' . self::PATH, 'green');
        CLI::write('  message_key ' . substr($v['expected']['message_key'], 0, 24) . '…');
        return 0;
    }
}

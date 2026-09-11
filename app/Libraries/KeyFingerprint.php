<?php

namespace App\Libraries;

/**
 * Public-key fingerprints, so peers can verify a key out of band.
 *
 * Key distribution runs through this server, and the ciphertext does not bind
 * the sender's identity key. A relay that wanted to could therefore hand out a
 * substituted public key and sit in the middle undetected. Publishing a
 * fingerprint does not fix that on its own — but it gives two parties
 * something short enough to compare over a channel the relay does not control,
 * which is what turns "trust the server" into "trust the server, verified once".
 */
class KeyFingerprint
{
    /**
     * SSH-style fingerprint of a raw public key: "sha256:" + unpadded base64.
     *
     * Same construction as OpenSSH so it looks familiar and is safe to paste.
     *
     * @param string $rawPublicKey 32 raw bytes (not base64)
     */
    public static function of(string $rawPublicKey): string
    {
        $digest = hash('sha256', $rawPublicKey, true);

        return 'sha256:' . rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    /**
     * Short form for humans reading a fingerprint aloud or eyeballing it in a
     * terminal: the first 16 hex characters in groups of four.
     *
     * 64 bits of the digest — enough that an attacker cannot cheaply grind a
     * colliding key for a casual visual check, while staying readable. Compare
     * the full fingerprint when it matters.
     */
    public static function short(string $rawPublicKey): string
    {
        $hex = substr(hash('sha256', $rawPublicKey), 0, 16);

        return implode('-', str_split($hex, 4));
    }

    /**
     * Both forms, for embedding in an API response.
     *
     * @return array{fingerprint: string, fingerprint_short: string}
     */
    public static function describe(string $rawPublicKey): array
    {
        return [
            'fingerprint'       => self::of($rawPublicKey),
            'fingerprint_short' => self::short($rawPublicKey),
        ];
    }
}

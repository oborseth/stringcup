<?php

if (! function_exists('base32_encode_compat')) {
    /**
     * RFC 4648 base32 without padding characters in the alphabet.
     *
     * PHP ships no base32 encoder. Used for server-assigned identifiers:
     * base32 keeps them case-insensitive and free of characters that would
     * need escaping in a URL path, unlike base64.
     */
    function base32_encode_compat(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out      = '';
        $buffer   = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $out .= $alphabet[($buffer >> ($bitsLeft - 5)) & 31];
                $bitsLeft -= 5;
            }
        }

        if ($bitsLeft > 0) {
            $out .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
        }

        return $out;
    }
}

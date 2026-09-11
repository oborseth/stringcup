<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiTokenModel extends Model
{
    protected $table      = 'api_tokens';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'identity_id',
        'token_hash',
        'created_at',
        'last_used_at',
        'is_active',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;

    /**
     * A token dies this many days after its last use (not after issuance).
     * AuthFilter refreshes last_used_at on every authenticated request, so an
     * agent that polls regularly never expires.
     */
    public const INACTIVITY_TTL_DAYS = 30;

    /**
     * Hash a plaintext token the way it is stored (raw binary SHA-256).
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken, true);
    }

    /**
     * Mint a new URL-safe token. Returns [plaintext, hash].
     *
     * @return array{0: string, 1: string}
     */
    public static function mintToken(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$plain, self::hashToken($plain)];
    }

    /**
     * Find the active token row for a plaintext token, or null.
     */
    public function findActiveByPlaintext(string $plainToken): ?array
    {
        return $this->where('token_hash', self::hashToken($plainToken))
            ->where('is_active', 1)
            ->first();
    }

    /**
     * Absolute expiry instant for a token row, as a unix timestamp.
     */
    public static function expiresAtTimestamp(array $tokenRow): int
    {
        $basis = $tokenRow['last_used_at'] ?? $tokenRow['created_at'];

        return strtotime($basis . ' +' . self::INACTIVITY_TTL_DAYS . ' days');
    }

    /**
     * Absolute expiry as a MySQL DATETIME string.
     */
    public static function expiresAt(array $tokenRow): string
    {
        return date('Y-m-d H:i:s', self::expiresAtTimestamp($tokenRow));
    }
}

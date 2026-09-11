<?php

namespace App\Models;

use CodeIgniter\Model;

class RendezvousModel extends Model
{
    protected $table      = 'rendezvous';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'token_hash',
        'role',
        'identity_id',
        'created_at',
        'expires_at',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;

    /** Roles a party may claim. Exactly two, so the pairing is unambiguous. */
    public const ROLES = ['initiator', 'responder'];

    /**
     * How long an unpaired claim survives.
     *
     * Long enough for a launcher to start both sides, short enough that a
     * leaked token stops being useful quickly.
     */
    public const TTL_MINUTES = 15;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token, true);
    }

    public static function otherRole(string $role): string
    {
        return $role === 'initiator' ? 'responder' : 'initiator';
    }

    /**
     * Live claim for a token+role, or null. Expired rows are ignored so a
     * stale claim never blocks a fresh pairing.
     */
    public function findClaim(string $tokenHash, string $role): ?array
    {
        return $this->where('token_hash', $tokenHash)
            ->where('role', $role)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->first();
    }

    /**
     * A live claim this identity already holds under the token, or null.
     *
     * Role cannot be derived from token-presence alone: the initiator has to
     * re-poll *with* its own token while remaining the initiator. An existing
     * claim is therefore what decides the role on any call that carries a
     * token, and only a caller with no claim becomes the responder.
     */
    public function findClaimByIdentity(string $tokenHash, int $identityId): ?array
    {
        return $this->where('token_hash', $tokenHash)
            ->where('identity_id', $identityId)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->first();
    }

    /**
     * Drop expired claims. Called on the claim path so the table stays bounded
     * without a scheduled job.
     */
    public function pruneExpired(): void
    {
        $this->where('expires_at <', date('Y-m-d H:i:s'))->delete();
    }
}

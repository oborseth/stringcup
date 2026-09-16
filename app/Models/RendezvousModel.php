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
     * 30 minutes, raised from 15 on 2026-09-16. The old value was sized for
     * the wrong scenario: "long enough for a launcher to start both sides"
     * describes a programmatic deployment, and the flow this project actually
     * documents is a HUMAN carrying one paste to a second agent -- which may
     * need the package installed and, mechanically, a SESSION RESTART before
     * it can join. Finding a host's config file and restarting can exceed 15
     * minutes on its own, and then the token is dead through no fault of
     * either agent.
     *
     * What the window buys is defence in depth, not the main protection. A
     * claim is single-use per role, so a second identity claiming a held side
     * gets a 409 and the theft is detectable; and when the handoff carries a
     * pairing secret, an interceptor that claims a side fails verification
     * because the secret never reaches the relay. So the window matters
     * mainly in the no-secret case, and doubling it doubles that exposure
     * only.
     *
     * A SLIDING EXPIRY, refreshed on each initiator poll, was considered and
     * rejected -- it sounds strictly better (a live pairing never dies under
     * the operator, an abandoned token still expires) but it does not fix
     * this case: the reference client's `await_peer` defaults to
     * `timeout=300`, five minutes, so the initiator has usually stopped
     * polling well before minute 15. A refresh keyed on polling would extend
     * exactly the pairings that did not need it.
     */
    public const TTL_MINUTES = 30;

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

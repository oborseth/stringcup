<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\IdentityModel;
use App\Models\RendezvousModel;
use App\Libraries\KeyFingerprint;
use App\Libraries\LongPollGuard;

helper('base32');

/**
 * V2 RendezvousController — introduce two agents that share a token.
 *
 * Identifiers are assigned by the server and unguessable, so neither agent can
 * derive the other's. Something has to carry that first introduction. A
 * rendezvous token does it with a single shared value, without reintroducing
 * the squatting race that client-chosen ids had:
 *
 *   - the token names a **meeting, not an identity**. Claiming it grants
 *     nothing addressable and expires in minutes, so there is no durable prize
 *     for guessing one and nothing to squat in advance;
 *   - each role may be claimed **once**. A second identity claiming a role
 *     already held is refused, so an agent whose token leaked is told rather
 *     than quietly displaced;
 *   - tokens are stored **hashed**, since the server never needs the plaintext.
 *
 * A leaked token is still a real compromise — whoever holds it can win the
 * race for a role. It is detectable, time-boxed, and confined to one pairing,
 * which is the most a shared secret can offer. Verify fingerprints afterwards
 * if the token's confidentiality is in doubt.
 */
class RendezvousController extends BaseController
{
    use ResponseTrait;

    /** Longest a caller may park waiting for its counterpart. */
    public const MAX_WAIT = 25;

    /** Re-check cadence while parked, in microseconds. */
    private const POLL_SLEEP_US = 500000;

    /** Shape of a minted token; the real gate is that it must already exist. */
    private const TOKEN_PATTERN = '/^rv-[a-z2-7]{32}$/';

    /** Prefix on minted tokens, so they are recognisable in logs and configs. */
    private const TOKEN_PREFIX = 'rv-';

    /**
     * Entropy behind a minted token: 20 bytes -> 32 base32 chars, 160 bits.
     *
     * A rendezvous token is a bearer secret — whoever holds it can claim a
     * role — so its strength is the whole defence. Leaving that to the caller
     * meant a plausible-looking "project-alpha" was accepted; minting removes
     * the choice, the same way identifiers are assigned rather than chosen.
     */
    private const TOKEN_ENTROPY_BYTES = 20;

    /**
     * POST /api/v2/rendezvous
     *
     * Body: { "token": "<issued token>"?, "wait": 0-25 }
     *
     * **The role is derived, not supplied.** Opening a rendezvous (no token)
     * makes you the initiator; joining one (with a token) makes you the
     * responder. Letting the caller name its own role produced a silent
     * deadlock: a config mistake that told both agents "initiator" had them
     * open two separate rendezvous and wait forever, and the failure was
     * indistinguishable from a peer that never started.
     *
     * Idempotent for the same identity: a retry re-reads the same claim
     * rather than conflicting with itself, so a restart that reuses its
     * identity file resumes instead of erroring.
     */
    public function pair()
    {
        try {
            $identity = $this->request->identity ?? null;
            if (!is_array($identity)) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            $req = $this->request->getJSON(true) ?? [];
            if (!is_array($req)) {
                return $this->failValidationErrors('Body must be a JSON object');
            }

            if (array_key_exists('role', $req)) {
                return $this->failValidationErrors(
                    'role is derived, not supplied: opening a rendezvous (no token) '
                    . 'makes you the initiator, joining one (with a token) makes you '
                    . 'the responder. Omit it.'
                );
            }

            // Omitting the token opens a new rendezvous; supplying one joins
            // an existing rendezvous. Mirrors identity registration, where
            // omitting external_id means "assign me one".
            $minting = !isset($req['token']) || $req['token'] === '' || $req['token'] === null;
            $token   = $minting ? null : (string) $req['token'];



            if (!$minting && !preg_match(self::TOKEN_PATTERN, $token)) {
                return $this->failValidationErrors(
                    'Malformed rendezvous token. Tokens are issued by this endpoint — '
                    . 'POST without a token to open a rendezvous, then share the returned '
                    . 'token with your peer. Self-chosen tokens are not accepted.'
                );
            }

            $wait = 0;
            if (isset($req['wait']) && $req['wait'] !== '') {
                if (!is_int($req['wait']) && !ctype_digit((string) $req['wait'])) {
                    return $this->failValidationErrors('wait must be a non-negative integer number of seconds');
                }
                $wait = min((int) $req['wait'], self::MAX_WAIT);
            }

            $model      = new RendezvousModel();
            $identityId = (int) $identity['id'];

            $model->pruneExpired();

            if ($minting) {
                $token = self::TOKEN_PREFIX . strtolower(
                    base32_encode_compat(random_bytes(self::TOKEN_ENTROPY_BYTES))
                );
            }

            $tokenHash = RendezvousModel::hashToken($token);

            // Role resolution. Opening makes you the initiator. Carrying a
            // token makes you the responder *unless* you already hold a claim
            // under it — the initiator must be able to re-poll with its own
            // token without being reclassified, and a restart that reused its
            // identity file must resume its original side.
            if ($minting) {
                $role = 'initiator';
            } else {
                $held = $model->findClaimByIdentity($tokenHash, $identityId);
                $role = $held !== null ? $held['role'] : 'responder';
            }

            if (!$minting) {
                // A token that names no live rendezvous is either expired,
                // already finished, or invented. Refusing unknown tokens is
                // what makes minting mandatory rather than merely advised —
                // a caller cannot opt back into a weak secret.
                $existing = $model->where('token_hash', $tokenHash)
                    ->where('expires_at >', date('Y-m-d H:i:s'))
                    ->countAllResults();

                if ($existing === 0) {
                    return $this->failNotFound(
                        'No open rendezvous for that token. It may have expired '
                        . '(' . RendezvousModel::TTL_MINUTES . ' minutes), or never existed. '
                        . 'POST without a token to open a new one.'
                    );
                }
            }

            // ---- claim our own role ------------------------------------
            $mine = $model->findClaim($tokenHash, $role);

            if ($mine !== null && (int) $mine['identity_id'] !== $identityId) {
                $this->logWithContext('warning', 'Rendezvous role already claimed', [
                    'role'      => $role,
                    'requester' => $identity['external_id'],
                ]);
                // Deliberately does NOT fire when the same identity re-claims:
                // a restart that kept its identity file must be able to resume.
                return $this->fail(
                    sprintf(
                        'The %s side of this rendezvous is already held by a different '
                        . 'identity. Either a third party has the token, or you '
                        . 're-registered and are no longer the identity that claimed it. '
                        . 'Do not retry — open a new rendezvous.',
                        $role
                    ),
                    409
                );
            }

            $now = date('Y-m-d H:i:s');

            if ($mine === null) {
                $inserted = $this->claimRole($model, $tokenHash, $role, $identityId, $now);

                if (!$inserted) {
                    // Lost a concurrent race for this role.
                    $winner = $model->findClaim($tokenHash, $role);
                    if ($winner !== null && (int) $winner['identity_id'] !== $identityId) {
                        return $this->fail(
                            'That role was just claimed by another identity under this token.',
                            409
                        );
                    }
                }
            }

            // ---- look for the counterpart ------------------------------
            $otherRole = RendezvousModel::otherRole($role);
            $peer      = $this->resolvePeer($model, $tokenHash, $otherRole);

            if ($peer === null && $wait > 0) {
                $guard = new LongPollGuard();

                if ($guard->acquire($wait)) {
                    try {
                        @set_time_limit($wait + 10);
                        $deadline = microtime(true) + $wait;

                        while (microtime(true) < $deadline) {
                            usleep(self::POLL_SLEEP_US);

                            $peer = $this->resolvePeer($model, $tokenHash, $otherRole);
                            if ($peer !== null) {
                                break;
                            }

                            if (connection_aborted() !== 0) {
                                break;
                            }
                        }
                    } finally {
                        $guard->release();
                    }
                }
            }

            $expiresAt = date('Y-m-d H:i:s', strtotime($now . ' +' . RendezvousModel::TTL_MINUTES . ' minutes'));

            if ($peer === null) {
                return $this->respond(array_merge([
                    'status'     => 'waiting',
                    'role'       => $role,
                    'my_id'      => $identity['external_id'],
                    'peer_id'    => null,
                    'expires_at' => $expiresAt,
                    'hint'       => 'Share the token with your peer, then poll again with "wait" set.',
                ], $minting ? ['token' => $token, 'token_issued' => true] : []));
            }

            $this->logWithContext('info', 'Rendezvous paired', [
                'role' => $role,
                'me'   => $identity['external_id'],
                'peer' => $peer['external_id'],
            ]);

            return $this->respond(array_merge($minting ? ['token' => $token, 'token_issued' => true] : [], [
                'status'                   => 'paired',
                'role'                     => $role,
                'my_id'                    => $identity['external_id'],
                'peer_id'                  => $peer['external_id'],
                'peer_display_name'        => $peer['display_name'],
                'peer_identity_public_key' => base64_encode($peer['identity_pubkey']),
                'peer_key_updated_at'      => $peer['key_updated_at'] ?? null,
                'expires_at'               => $expiresAt,
            ], $this->prefixKeys(KeyFingerprint::describe($peer['identity_pubkey']), 'peer_')));
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Rendezvous failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred during rendezvous.');
        }
    }

    /**
     * DELETE /api/v2/rendezvous
     *
     * Releases this identity's claim, so a token can be reused after a
     * mistake without waiting out the TTL.
     */
    public function release()
    {
        try {
            $identity = $this->request->identity ?? null;
            if (!is_array($identity)) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            $req = $this->request->getJSON(true);
            if (!is_array($req) || empty($req['token'])) {
                return $this->failValidationErrors('token is required');
            }

            $model = new RendezvousModel();

            // Scoped to our own identity: releasing is not a way to evict a
            // counterpart from a pairing.
            $model->where('token_hash', RendezvousModel::hashToken((string) $req['token']))
                ->where('identity_id', (int) $identity['id'])
                ->delete();

            return $this->respond(['status' => 'released']);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Rendezvous release failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while releasing the claim.');
        }
    }

    /**
     * Insert the claim, returning false if the unique key rejected it.
     */
    private function claimRole(RendezvousModel $model, string $tokenHash, string $role, int $identityId, string $now): bool
    {
        try {
            return (bool) $model->insert([
                'token_hash'  => $tokenHash,
                'role'        => $role,
                'identity_id' => $identityId,
                'created_at'  => $now,
                'expires_at'  => date('Y-m-d H:i:s', strtotime($now . ' +' . RendezvousModel::TTL_MINUTES . ' minutes')),
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The identity holding the other role, if it has arrived.
     */
    private function resolvePeer(RendezvousModel $model, string $tokenHash, string $otherRole): ?array
    {
        $claim = $model->findClaim($tokenHash, $otherRole);
        if ($claim === null) {
            return null;
        }

        return (new IdentityModel())->find($claim['identity_id']) ?: null;
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,string>
     */
    private function prefixKeys(array $fields, string $prefix): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            $out[$prefix . $key] = $value;
        }

        return $out;
    }
}

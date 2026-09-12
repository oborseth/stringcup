<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\IdentityModel;
use App\Models\ApiTokenModel;
use App\Libraries\KeyFingerprint;
use App\Libraries\Stats;

helper('base32');

/**
 * V2 IdentityController.
 *
 * **Identifiers are assigned by the server and cannot be chosen.** Client-
 * chosen names made `external_id` a first-come namespace: any party could
 * register a name another party was about to use, or was already being
 * addressed by, and silently receive its mail. Assigning an unguessable
 * identifier removes the race rather than documenting it.
 *
 * The cost is that an assigned id cannot be derived, so two agents that have
 * never met need somewhere to exchange ids — see RendezvousController.
 */
class IdentityController extends BaseController
{
    use ResponseTrait;

    /** Marks an identifier as server-assigned at a glance. */
    private const ASSIGNED_PREFIX = 'sc';

    /**
     * Entropy behind an assigned identifier: 15 bytes -> 24 base32 characters,
     * 120 bits. Unpredictability is the entire security property here, so this
     * is deliberately far beyond what a collision check alone would require.
     */
    private const ASSIGNED_ENTROPY_BYTES = 15;

    /**
     * POST /api/v2/identities
     *
     * Registers a public key and returns the assigned identifier plus an API
     * token. The token is issued exactly once and cannot be retrieved again.
     *
     * `external_id` is **not** accepted. Requests that supply one are rejected
     * rather than silently ignored, so a client written against the old
     * contract fails loudly instead of discovering later that its chosen name
     * was never in force.
     */
    public function register()
    {
        try {
            $request = $this->request->getJSON(true);

            if (!is_array($request) || empty($request['identity_public_key'])) {
                $this->logWithContext('warning', 'Identity registration failed: missing public key');
                return $this->failValidationErrors('identity_public_key is required');
            }

            if (array_key_exists('external_id', $request)) {
                return $this->failValidationErrors(
                    'external_id is assigned by the server and cannot be chosen. '
                    . 'Omit it; the assigned id is returned as "id". '
                    . 'To pair with a peer, use POST /api/v2/rendezvous.'
                );
            }

            if (isset($request['display_name']) && strlen($request['display_name']) > 255) {
                return $this->failValidationErrors('display_name must be 255 characters or less');
            }

            $algo = $request['algo'] ?? 'x25519';
            if (!in_array($algo, ['ed25519', 'x25519'], true)) {
                return $this->failValidationErrors('algo must be either ed25519 or x25519');
            }

            $pubkeyDecoded = base64_decode($request['identity_public_key'], true);
            if ($pubkeyDecoded === false || $pubkeyDecoded === '') {
                return $this->failValidationErrors('identity_public_key must be valid base64-encoded data');
            }

            if (strlen($pubkeyDecoded) !== 32) {
                return $this->failValidationErrors('identity_public_key must be 32 bytes when decoded');
            }

            $identityModel = new IdentityModel();

            $externalId = $this->assignExternalId($identityModel);
            if ($externalId === null) {
                $this->logWithContext('error', 'Could not assign a unique external_id');
                return $this->failServerError('Could not assign an identifier. Please retry.');
            }

            $now = date('Y-m-d H:i:s');

            $identityId = $identityModel->insert([
                'external_id'     => $externalId,
                'display_name'    => $request['display_name'] ?? null,
                'identity_pubkey' => $pubkeyDecoded,
                'algo'            => $algo,
                'key_updated_at'  => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ], true);

            if (!$identityId) {
                $this->logWithContext('error', 'Identity creation failed', [
                    'errors' => $identityModel->errors(),
                ]);
                return $this->failServerError('Could not create the identity.');
            }

            [$plainToken, $tokenHash] = ApiTokenModel::mintToken();

            (new ApiTokenModel())->insert([
                'identity_id' => $identityId,
                'token_hash'  => $tokenHash,
                'created_at'  => $now,
                'is_active'   => 1,
            ]);

            Stats::bump(Stats::IDENTITIES_CREATED);

            $this->logWithContext('info', 'Identity created', ['external_id' => $externalId]);

            return $this->respondCreated(array_merge([
                'id'                  => $externalId,
                'id_assigned'         => true,
                'display_name'        => $request['display_name'] ?? null,
                'identity_public_key' => base64_encode($pubkeyDecoded),
                'algo'                => $algo,
                'api_token'           => $plainToken,
                'key_updated_at'      => $now,
                'created_at'          => $now,
            ], KeyFingerprint::describe($pubkeyDecoded)));
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Identity registration failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while processing your request.');
        }
    }

    /**
     * PUT /api/v2/identities
     *
     * Updates the authenticated identity's own public key or display name.
     *
     * Registration used to double as the update path, keyed by the chosen
     * `external_id`. With ids assigned, the caller's identity comes from the
     * bearer token instead, which is both simpler and removes the ambiguity
     * of a POST that sometimes created and sometimes mutated.
     */
    public function update()
    {
        try {
            // Resolved locally rather than by AuthFilter: this route shares a
            // path with POST (registration), which must stay unauthenticated,
            // and filters match by path rather than method.
            $identity = $this->identityFromToken();
            if ($identity === null) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            $request = $this->request->getJSON(true);
            if (!is_array($request) || $request === []) {
                return $this->failValidationErrors('Provide identity_public_key and/or display_name');
            }

            if (array_key_exists('external_id', $request)) {
                return $this->failValidationErrors('external_id cannot be changed');
            }

            $now  = date('Y-m-d H:i:s');
            $data = ['updated_at' => $now];

            if (array_key_exists('display_name', $request)) {
                if ($request['display_name'] !== null && strlen($request['display_name']) > 255) {
                    return $this->failValidationErrors('display_name must be 255 characters or less');
                }
                $data['display_name'] = $request['display_name'];
            }

            $keyChanged = false;

            if (!empty($request['identity_public_key'])) {
                $pubkeyDecoded = base64_decode($request['identity_public_key'], true);
                if ($pubkeyDecoded === false || strlen($pubkeyDecoded) !== 32) {
                    return $this->failValidationErrors('identity_public_key must be base64 decoding to 32 bytes');
                }

                // Only a real change stamps key_updated_at, so a peer that
                // pinned a fingerprint can tell rotation from a rename.
                $keyChanged = !hash_equals((string) $identity['identity_pubkey'], $pubkeyDecoded);

                if ($keyChanged) {
                    $data['identity_pubkey'] = $pubkeyDecoded;
                    $data['key_updated_at']  = $now;
                }
            }

            $identityModel = new IdentityModel();
            $identityModel->update($identity['id'], $data);

            if ($keyChanged) {
                $this->logWithContext('warning', 'Identity public key rotated', [
                    'external_id' => $identity['external_id'],
                ]);
            }

            $fresh = $identityModel->find($identity['id']);

            return $this->respond(array_merge([
                'id'                  => $fresh['external_id'],
                'display_name'        => $fresh['display_name'],
                'identity_public_key' => base64_encode($fresh['identity_pubkey']),
                'algo'                => $fresh['algo'],
                'key_updated_at'      => $fresh['key_updated_at'],
                'key_changed'         => $keyChanged,
            ], KeyFingerprint::describe($fresh['identity_pubkey'])));
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Identity update failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while updating the identity.');
        }
    }

    /**
     * GET /api/v2/identities/{id}
     *
     * Public lookup of a peer's key material. Exact match only — there is no
     * list or search, so this cannot be used to enumerate identities, and with
     * assigned ids an unknown identifier is not guessable either.
     */
    public function show($externalId)
    {
        try {
            $identity = (new IdentityModel())->where('external_id', $externalId)->first();

            if (!$identity) {
                $this->logWithContext('info', 'Identity lookup: not found', ['external_id' => $externalId]);
                return $this->failNotFound('Identity not found');
            }

            return $this->respond(array_merge([
                'id'                  => $identity['external_id'],
                'display_name'        => $identity['display_name'],
                'identity_public_key' => base64_encode($identity['identity_pubkey']),
                'algo'                => $identity['algo'],
                // Compare out of band before trusting a key on first sight;
                // see PROTOCOL.md B.7.
                'key_updated_at'      => $identity['key_updated_at'] ?? null,
            ], KeyFingerprint::describe($identity['identity_pubkey'])));
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Identity lookup failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while retrieving the identity.');
        }
    }

    /**
     * Resolve the bearer token to an identity, honouring the same inactivity
     * expiry AuthFilter applies.
     */
    private function identityFromToken(): ?array
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $plain = trim(substr($header, 7));
        if ($plain === '') {
            return null;
        }

        $tokenModel = new ApiTokenModel();
        $tokenRow   = $tokenModel->findActiveByPlaintext($plain);
        if (!$tokenRow) {
            return null;
        }

        if (time() > ApiTokenModel::expiresAtTimestamp($tokenRow)) {
            $tokenModel->update($tokenRow['id'], ['is_active' => 0]);
            return null;
        }

        $tokenModel->update($tokenRow['id'], ['last_used_at' => date('Y-m-d H:i:s')]);

        return (new IdentityModel())->find($tokenRow['identity_id']) ?: null;
    }

    /**
     * Mint an unused identifier.
     *
     * Collisions at 120 bits are not a practical concern; the retry loop
     * guards against a broken RNG rather than against birthday chance.
     */
    private function assignExternalId(IdentityModel $identityModel): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $random = strtolower(base32_encode_compat(random_bytes(self::ASSIGNED_ENTROPY_BYTES)));

            $candidate = self::ASSIGNED_PREFIX . '-' . $random;

            if (!$identityModel->where('external_id', $candidate)->first()) {
                return $candidate;
            }
        }

        return null;
    }
}

<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\ApiTokenModel;
use App\Models\IdentityModel;

/**
 * V2 TokenController
 *
 * Registration issues a token exactly once and never returns it again. Without
 * rotation, a long-running agent has no way to replace a leaked credential
 * short of abandoning its identity, and no way to see how close it is to the
 * inactivity expiry. Both are covered here.
 */
class TokenController extends BaseController
{
    use ResponseTrait;

    /**
     * The plaintext token for this request, or null.
     */
    private function plainToken(): ?string
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            return null;
        }

        $plain = trim(substr($authHeader, 7));

        return $plain === '' ? null : $plain;
    }

    /**
     * GET /api/v2/tokens/current
     *
     * Reports the authenticated token's lifecycle so an agent can rotate on a
     * schedule rather than discovering expiry as a 401 mid-conversation.
     */
    public function current()
    {
        try {
            $plain = $this->plainToken();
            if ($plain === null) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            $tokenModel = new ApiTokenModel();
            $tokenRow   = $tokenModel->findActiveByPlaintext($plain);

            if (!$tokenRow) {
                return $this->failUnauthorized('Invalid or inactive token');
            }

            $identity = (new IdentityModel())->find($tokenRow['identity_id']);
            if (!$identity) {
                return $this->failUnauthorized('Identity not found');
            }

            $expiresTs = ApiTokenModel::expiresAtTimestamp($tokenRow);

            return $this->respond([
                'identity_id'        => $identity['external_id'],
                'created_at'         => $tokenRow['created_at'],
                'last_used_at'       => $tokenRow['last_used_at'],
                'expires_at'         => date('Y-m-d H:i:s', $expiresTs),
                'expires_in_seconds' => max(0, $expiresTs - time()),
                'inactivity_ttl_days' => ApiTokenModel::INACTIVITY_TTL_DAYS,
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Token introspection failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while reading the token.');
        }
    }

    /**
     * POST /api/v2/tokens/rotate
     *
     * Issues a replacement token for the caller's identity and deactivates the
     * one used to authenticate. The new token is returned exactly once.
     *
     * The old token is revoked immediately, so a client that loses the
     * response has to re-register; that is the safer failure direction for a
     * rotation endpoint, and the reason the response is the only copy.
     */
    public function rotate()
    {
        try {
            $plain = $this->plainToken();
            if ($plain === null) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            $tokenModel = new ApiTokenModel();
            $tokenRow   = $tokenModel->findActiveByPlaintext($plain);

            if (!$tokenRow) {
                return $this->failUnauthorized('Invalid or inactive token');
            }

            $identity = (new IdentityModel())->find($tokenRow['identity_id']);
            if (!$identity) {
                return $this->failUnauthorized('Identity not found');
            }

            [$newPlain, $newHash] = ApiTokenModel::mintToken();
            $now = date('Y-m-d H:i:s');

            $newId = $tokenModel->insert([
                'identity_id'  => $tokenRow['identity_id'],
                'token_hash'   => $newHash,
                'created_at'   => $now,
                'last_used_at' => $now,
                'is_active'    => 1,
            ], true);

            if (!$newId) {
                $this->logWithContext('error', 'Token rotation failed: could not store new token', [
                    'identity_id' => $identity['external_id'],
                ]);
                return $this->failServerError('Could not issue a new token.');
            }

            // Only revoke the old token once the replacement is safely stored.
            $tokenModel->update($tokenRow['id'], ['is_active' => 0]);

            $this->logWithContext('info', 'Token rotated', [
                'identity_id' => $identity['external_id'],
            ]);

            $newRow = $tokenModel->find($newId);

            return $this->respondCreated([
                'identity_id'         => $identity['external_id'],
                'api_token'           => $newPlain,
                'created_at'          => $now,
                'expires_at'          => ApiTokenModel::expiresAt($newRow),
                'previous_token'      => 'revoked',
                'inactivity_ttl_days' => ApiTokenModel::INACTIVITY_TTL_DAYS,
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Token rotation failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while rotating the token.');
        }
    }
}

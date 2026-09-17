<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\ApiTokenModel;
use App\Models\IdentityModel;
use Config\Services;

class AuthFilter implements FilterInterface
{
    /**
     * Check for valid Bearer token authentication.
     * Validates token, checks expiration (30 days), and updates last_used_at.
     *
     * @param RequestInterface $request
     * @param mixed|null       $arguments
     * @return RequestInterface|ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader) {
            return $this->unauthorizedResponse('Missing Authorization header');
        }

        // Expect: Authorization: Bearer <token>
        if (stripos($authHeader, 'Bearer ') !== 0) {
            return $this->unauthorizedResponse('Invalid Authorization format. Expected: Bearer <token>');
        }

        $plainToken = trim(substr($authHeader, 7));
        if ($plainToken === '') {
            return $this->unauthorizedResponse('Empty bearer token');
        }

        $tokenHash = hash('sha256', $plainToken, true);

        $tokenModel    = new ApiTokenModel();
        $identityModel = new IdentityModel();

        $tokenRow = $tokenModel
            ->where('token_hash', $tokenHash)
            ->where('is_active', 1)
            ->first();

        if (!$tokenRow) {
            // TELL A REVOKED CALLER THAT IT WAS REVOKED.
            //
            // "Invalid or inactive token" is indistinguishable from a typo, so
            // an agent cut off by the operator reports a config problem and
            // sends someone hunting a bug that does not exist. It also cannot
            // tell its operator the one thing that would end the search.
            //
            // This discloses nothing: the caller already holds the token, so
            // confirming that it once existed tells them what they knew. A
            // guesser learns that a high-entropy string they did not have is
            // not a former token, which is not a useful oracle.
            $known = $tokenModel->where('token_hash', $tokenHash)->first();

            if ($known) {
                $expired = time() > ApiTokenModel::expiresAtTimestamp($known);

                return $this->unauthorizedResponse($expired
                    ? 'Token has expired through inactivity. Register a new identity; '
                      . 'the old id is no longer reachable by your peers.'
                    : 'This token was REVOKED by the relay operator. It did not expire '
                      . 'and nothing is misconfigured on your side. Ask the operator '
                      . 'why; only they can restore it.');
            }

            return $this->unauthorizedResponse('Invalid or inactive token');
        }

        // Check token expiration (inactivity window from last use or creation)
        $expiresAt = ApiTokenModel::expiresAtTimestamp($tokenRow);

        if (time() > $expiresAt) {
            // Mark token as inactive
            $tokenModel->update($tokenRow['id'], ['is_active' => 0]);
            return $this->unauthorizedResponse('Token has expired');
        }

        // Update last_used_at to extend expiration
        $tokenModel->update($tokenRow['id'], [
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);

        // Load identity
        $identity = $identityModel->find($tokenRow['identity_id']);

        if (!$identity) {
            return $this->unauthorizedResponse('Identity not found');
        }

        // Store identity in request for controllers to access
        $request->identity = $identity;
        $request->apiToken = $tokenRow;

        return $request;
    }

    /**
     * No action needed after controller execution.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param mixed|null        $arguments
     * @return ResponseInterface|void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No action needed
    }

    /**
     * Return a standardized 401 JSON response.
     *
     * @param string $message
     * @return ResponseInterface
     */
    protected function unauthorizedResponse(string $message): ResponseInterface
    {
        $response = Services::response();
        $response->setStatusCode(401);
        $response->setJSON([
            'error' => $message,
            'messages' => [
                'error' => $message
            ]
        ]);
        return $response;
    }
}

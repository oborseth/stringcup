<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\IdentityModel;
use App\Models\PrekeyBundleModel;
use App\Models\PrekeyModel;
use Ramsey\Uuid\Uuid; // if you use ramsey/uuid
use App\Models\ApiTokenModel;

class IdentityController extends BaseController
{
    use ResponseTrait;

    protected function getIdentityForToken(): ?array
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (!$authHeader) {
            return null;
        }

        // Expect: Authorization: Bearer <token>
        if (stripos($authHeader, 'Bearer ') !== 0) {
            return null;
        }

        $plainToken = trim(substr($authHeader, 7));
        if ($plainToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $plainToken, true);

        $tokenModel   = new ApiTokenModel();
        $identityModel= new IdentityModel();

        $tokenRow = $tokenModel
            ->where('token_hash', $tokenHash)
            ->where('is_active', 1)
            ->first();

        if (!$tokenRow) {
            return null;
        }

        // Update last_used_at
        $tokenModel->update($tokenRow['id'], [
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);

        $identity = $identityModel->find($tokenRow['identity_id']);
        return $identity ?: null;
    }

    public function register()
    {
        try {
            $request = $this->request->getJSON(true);

            if (!$request || empty($request['external_id']) || empty($request['identity_public_key'])) {
                $this->logWithContext('warning', 'Identity registration failed: missing required fields');
                return $this->failValidationErrors('Missing external_id or identity_public_key');
            }

            // Validate external_id format
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $request['external_id'])) {
                $this->logWithContext('warning', 'Identity registration failed: invalid external_id format', [
                    'external_id' => $request['external_id']
                ]);
                return $this->failValidationErrors('external_id must be 1-64 characters and contain only letters, numbers, hyphens, and underscores');
            }

            // Validate display_name length if provided
            if (isset($request['display_name']) && strlen($request['display_name']) > 255) {
                $this->logWithContext('warning', 'Identity registration failed: display_name too long');
                return $this->failValidationErrors('display_name must be 255 characters or less');
            }

            // Validate algo if provided
            $algo = $request['algo'] ?? 'x25519';
            if (!in_array($algo, ['ed25519', 'x25519'], true)) {
                $this->logWithContext('warning', 'Identity registration failed: invalid algo', ['algo' => $algo]);
                return $this->failValidationErrors('algo must be either ed25519 or x25519');
            }

            // Validate and decode identity_public_key
            $pubkeyDecoded = base64_decode($request['identity_public_key'], true);
            if ($pubkeyDecoded === false || $pubkeyDecoded === '') {
                $this->logWithContext('warning', 'Identity registration failed: invalid base64 public key');
                return $this->failValidationErrors('identity_public_key must be valid base64-encoded data');
            }

            // Validate key length (X25519 keys are 32 bytes)
            if (strlen($pubkeyDecoded) !== 32) {
                $this->logWithContext('warning', 'Identity registration failed: invalid key length', [
                    'key_length' => strlen($pubkeyDecoded)
                ]);
                return $this->failValidationErrors('identity_public_key must be 32 bytes when decoded');
            }

            $identityModel = new IdentityModel();
        $now           = date('Y-m-d H:i:s');

        // Check if identity exists
        $existing = $identityModel
            ->where('external_id', $request['external_id'])
            ->first();

            // If identity exists, require valid token that matches it
            $currentByToken = $this->getIdentityForToken();

            if ($existing && (!$currentByToken || $currentByToken['id'] !== $existing['id'])) {
                $this->logWithContext('warning', 'Identity update failed: token mismatch', [
                    'external_id' => $request['external_id']
                ]);
                return $this->failUnauthorized('Token does not match this identity');
            }

            $data = [
                'external_id'     => $request['external_id'],
                'display_name'    => $request['display_name'] ?? null,
                'identity_pubkey' => $pubkeyDecoded,
                'algo'            => $algo,
                'updated_at'      => $now,
            ];

            $apiTokenModel = new ApiTokenModel();
            $issuedToken   = null;
            $identityId    = null;

            if ($existing) {
                // Update existing identity
                $identityModel->update($existing['id'], $data);
                $identityId = $existing['id'];

                $this->logWithContext('info', 'Identity updated successfully', [
                    'external_id' => $request['external_id']
                ]);
            } else {
                // Create new identity
                $data['created_at'] = $now;
                $identityId = $identityModel->insert($data, true);

                if (!$identityId) {
                    $this->logWithContext('error', 'Identity creation failed: database insert error', [
                        'external_id' => $request['external_id'],
                        'errors' => $identityModel->errors()
                    ]);
                    return $this->fail('Insert failed: ' . print_r($identityModel->errors(), true));
                }

                // Generate per-identity API token (returned ONCE)
                $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $tokenHash  = hash('sha256', $plainToken, true);

                $apiTokenModel->insert([
                    'identity_id' => $identityId,
                    'token_hash'  => $tokenHash,
                    'created_at'  => $now,
                    'is_active'   => 1,
                ]);

                $issuedToken = $plainToken;

                $this->logWithContext('info', 'Identity created successfully', [
                    'external_id' => $request['external_id'],
                    'identity_id' => $identityId
                ]);
            }

            // Handle prekey_bundle as before (omitted here for brevity)

            return $this->respondCreated([
                'id'                  => $request['external_id'],
                'identity_public_key' => $request['identity_public_key'],
                'algo'                => $data['algo'],
                'api_token'           => $issuedToken,   // null if updated, string if new
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Identity registration failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->failServerError('An error occurred while processing your request. Please try again later.');
        }
    }

    public function show($externalId)
    {
        try {
            $identityModel = new IdentityModel();
            $identity      = $identityModel->where('external_id', $externalId)->first();

            if (!$identity) {
                $this->logWithContext('info', 'Identity lookup: not found', [
                    'external_id' => $externalId
                ]);
                return $this->failNotFound('Identity not found');
            }

        // Get latest prekey bundle (if any)
        $prekeyBundleModel = new PrekeyBundleModel();
        $bundle = $prekeyBundleModel
            ->where('identity_id', $identity['id'])
            ->orderBy('id', 'DESC')
            ->first();

        $prekeyBundle = null;

        if ($bundle) {
            $prekeyModel = new PrekeyModel();
            $prekeys = $prekeyModel
                ->where('bundle_id', $bundle['id'])
                ->where('is_used', 0)
                ->limit(10) // you can pick how many to expose
                ->find();

            $prekeyBundle = [
                'bundle_id'       => $bundle['bundle_uuid'],
                'signed_prekey'   => $bundle['signed_prekey'] ? base64_encode($bundle['signed_prekey']) : null,
                'one_time_prekeys'=> array_map(static function($row) {
                    return base64_encode($row['public_key']);
                }, $prekeys),
            ];
        }

            $this->logWithContext('info', 'Identity lookup successful', [
                'external_id' => $externalId
            ]);

            return $this->respond([
                'id'                  => $identity['external_id'],
                'display_name'        => $identity['display_name'],
                'identity_public_key' => base64_encode($identity['identity_pubkey']),
                'algo'                => $identity['algo'],
                'prekey_bundle'       => $prekeyBundle,
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Identity lookup failed: {message}', [
                'message' => $e->getMessage(),
                'external_id' => $externalId,
                'exception' => get_class($e)
            ]);
            return $this->failServerError('An error occurred while retrieving the identity.');
        }
    }
}


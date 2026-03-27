<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\MessageModel;
use App\Models\IdentityModel;
use App\Models\ApiTokenModel;

class MessageController extends BaseController
{
    use ResponseTrait;

    /**
     * Resolve the calling identity from the Bearer token.
     *
     * Expects: Authorization: Bearer <plain_token>
     *
     * @return array|null   Identity row or null if invalid
     */
    protected function getIdentityForToken(): ?array
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (!$authHeader) {
            return null;
        }

        if (stripos($authHeader, 'Bearer ') !== 0) {
            return null;
        }

        $plainToken = trim(substr($authHeader, 7));
        if ($plainToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $plainToken, true);

        $tokenModel    = new ApiTokenModel();
        $identityModel = new IdentityModel();

        $tokenRow = $tokenModel
            ->where('token_hash', $tokenHash)
            ->where('is_active', 1)
            ->first();

        if (!$tokenRow) {
            return null;
        }

        // Touch last_used_at
        $tokenModel->update($tokenRow['id'], [
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);

        $identity = $identityModel->find($tokenRow['identity_id']);
        return $identity ?: null;
    }

    /**
     * Backwards-compat wrapper for routes pointing to ::send
     * POST /api/v1/messages
     */
    public function send()
    {
        return $this->store();
    }

    /**
     * POST /api/v1/messages
     *
     * Body:
     * {
     *   "recipient_id": "bob",
     *   "header": { ... },
     *   "ciphertext": "<base64>",
     *   "sender_id": "alice"   // optional, must match token if present
     * }
     *
     * Sender is taken from the Bearer token (identity.external_id).
     */
    public function store()
    {
        try {
            $currentIdentity = $this->getIdentityForToken();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'Message send failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $req = $this->request->getJSON(true);
            if (!$req) {
                $this->logWithContext('warning', 'Message send failed: invalid JSON');
                return $this->failValidationErrors('Invalid JSON body');
            }

            if (empty($req['recipient_id']) || empty($req['ciphertext']) || empty($req['header'])) {
                $this->logWithContext('warning', 'Message send failed: missing required fields');
                return $this->failValidationErrors('recipient_id, header, and ciphertext are required');
            }

            // Validate recipient_id format
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $req['recipient_id'])) {
                $this->logWithContext('warning', 'Message send failed: invalid recipient_id format');
                return $this->failValidationErrors('recipient_id must be 1-64 characters and contain only letters, numbers, hyphens, and underscores');
            }

            // Validate header is an array/object
            if (!is_array($req['header'])) {
                $this->logWithContext('warning', 'Message send failed: header not a JSON object');
                return $this->failValidationErrors('header must be a JSON object');
            }

            // Validate required header fields (accept both 'v' and 'version' for flexibility)
            if ((empty($req['header']['v']) && empty($req['header']['version'])) ||
                empty($req['header']['algo']) ||
                !isset($req['header']['msg_seq']) ||
                empty($req['header']['iv'])) {
                $this->logWithContext('warning', 'Message send failed: missing required header fields', [
                    'header' => $req['header']
                ]);
                return $this->failValidationErrors('header must contain version (or v), algo, msg_seq, and iv fields');
            }

            // Validate and decode ciphertext
            $ciphertextDecoded = base64_decode($req['ciphertext'], true);
            if ($ciphertextDecoded === false || $ciphertextDecoded === '') {
                $this->logWithContext('warning', 'Message send failed: invalid base64 ciphertext');
                return $this->failValidationErrors('ciphertext must be valid base64-encoded data');
            }

            $senderExternalId = $currentIdentity['external_id'];

            // Optional: if client sends sender_id, enforce it matches token identity
            if (!empty($req['sender_id'])) {
                if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $req['sender_id'])) {
                    $this->logWithContext('warning', 'Message send failed: invalid sender_id format');
                    return $this->failValidationErrors('sender_id format is invalid');
                }
                if ($req['sender_id'] !== $senderExternalId) {
                    $this->logWithContext('warning', 'Message send failed: sender_id mismatch');
                    return $this->failUnauthorized('sender_id does not match token identity');
                }
            }

            $recipientId   = $req['recipient_id'];
            $identityModel = new IdentityModel();
            $recipient     = $identityModel->where('external_id', $recipientId)->first();

            if (!$recipient) {
                $this->logWithContext('warning', 'Message send failed: recipient not found', [
                    'recipient_id' => $recipientId
                ]);
                return $this->failNotFound('Recipient identity not found');
            }

            $messageModel = new MessageModel();
            $now          = date('Y-m-d H:i:s');

            $messageId = $messageModel->insert([
                'sender_id'    => $senderExternalId,
                'recipient_id' => $recipientId,
                'header_json'  => json_encode($req['header']),
                'ciphertext'   => $ciphertextDecoded,
                'created_at'   => $now,
            ], true);

            $this->logWithContext('info', 'Message sent successfully', [
                'message_id' => $messageId,
                'sender_id' => $senderExternalId,
                'recipient_id' => $recipientId
            ]);

            return $this->respondCreated([
                'message_id' => $messageId,
                'status'     => 'stored',
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Message send failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            return $this->failServerError('An error occurred while sending the message.');
        }
    }

    /**
     * GET /api/v1/messages
     *
     * Uses the token to determine which inbox to read (recipient_id),
     * returns all pending messages, then deletes them.
     */
    public function inbox()
    {
        try {
            $currentIdentity = $this->getIdentityForToken();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'Inbox retrieval failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $recipientExternalId = $currentIdentity['external_id'];

            $msgModel = new MessageModel();
            $messages = $msgModel
                ->where('recipient_id', $recipientExternalId)
                ->where('api_version', 1)
                ->orderBy('id', 'ASC')
                ->findAll();

            $out = [];
            foreach ($messages as $m) {
                $out[] = [
                    'id'           => $m['id'],
                    'sender_id'    => $m['sender_id'],
                    'recipient_id' => $m['recipient_id'],
                    'header'       => json_decode($m['header_json'], true),
                    'ciphertext'   => base64_encode($m['ciphertext']),
                    'created_at'   => $m['created_at'],
                ];
            }

            $this->logWithContext('info', 'Inbox retrieved successfully', [
                'recipient_id' => $recipientExternalId,
                'message_count' => count($messages)
            ]);

            // Respond first
            $response = $this->respond($out);

            // Then delete messages for that recipient (fire-and-forget model)
            if (!empty($messages)) {
                $ids = array_column($messages, 'id');
                $msgModel->whereIn('id', $ids)->delete();

                $this->logWithContext('info', 'Messages deleted after retrieval', [
                    'recipient_id' => $recipientExternalId,
                    'deleted_count' => count($ids)
                ]);
            }

            return $response;
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Inbox retrieval failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            return $this->failServerError('An error occurred while retrieving messages.');
        }
    }
}


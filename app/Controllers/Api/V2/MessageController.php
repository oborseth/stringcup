<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\MessageModel;
use App\Models\IdentityModel;
use App\Models\ApiTokenModel;

/**
 * V2 MessageController
 *
 * Key differences from V1:
 * - Stateless ECIES crypto: header contains ephemeral_pub instead of msg_seq
 * - Persistent inbox: GET does NOT delete messages
 * - Explicit ACK: DELETE /api/v2/messages/{id} removes a specific message
 */
class MessageController extends BaseController
{
    use ResponseTrait;

    protected function getIdentityForToken(): ?array
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            return null;
        }

        $plainToken = trim(substr($authHeader, 7));
        if ($plainToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $plainToken, true);

        $tokenModel = new ApiTokenModel();
        $tokenRow   = $tokenModel
            ->where('token_hash', $tokenHash)
            ->where('is_active', 1)
            ->first();

        if (!$tokenRow) {
            return null;
        }

        $tokenModel->update($tokenRow['id'], ['last_used_at' => date('Y-m-d H:i:s')]);

        $identity = (new IdentityModel())->find($tokenRow['identity_id']);
        return $identity ?: null;
    }

    /**
     * POST /api/v2/messages
     *
     * Accepts ECIES-encrypted messages. Required header fields differ from v1:
     * - ephemeral_pub instead of msg_seq (no ratchet state)
     * - algo must be "x25519+ecies+aes256gcm"
     *
     * Stored with api_version=2; NOT auto-deleted on inbox retrieval.
     */
    public function send()
    {
        try {
            $currentIdentity = $this->getIdentityForToken();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'V2 message send failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $req = $this->request->getJSON(true);
            if (!$req) {
                $this->logWithContext('warning', 'V2 message send failed: invalid JSON');
                return $this->failValidationErrors('Invalid JSON body');
            }

            if (empty($req['recipient_id']) || empty($req['ciphertext']) || empty($req['header'])) {
                $this->logWithContext('warning', 'V2 message send failed: missing required fields');
                return $this->failValidationErrors('recipient_id, header, and ciphertext are required');
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $req['recipient_id'])) {
                $this->logWithContext('warning', 'V2 message send failed: invalid recipient_id format');
                return $this->failValidationErrors('recipient_id must be 1-64 characters: letters, digits, hyphens, underscores');
            }

            if (!is_array($req['header'])) {
                $this->logWithContext('warning', 'V2 message send failed: header not a JSON object');
                return $this->failValidationErrors('header must be a JSON object');
            }

            // V2 requires ephemeral_pub and iv; algo must be ECIES variant
            $header = $req['header'];
            if (empty($header['algo']) || $header['algo'] !== 'x25519+ecies+aes256gcm') {
                $this->logWithContext('warning', 'V2 message send failed: invalid or missing algo', ['algo' => $header['algo'] ?? null]);
                return $this->failValidationErrors('header.algo must be "x25519+ecies+aes256gcm"');
            }

            if (empty($header['ephemeral_pub'])) {
                $this->logWithContext('warning', 'V2 message send failed: missing ephemeral_pub');
                return $this->failValidationErrors('header.ephemeral_pub is required');
            }

            if (empty($header['iv'])) {
                $this->logWithContext('warning', 'V2 message send failed: missing iv');
                return $this->failValidationErrors('header.iv is required');
            }

            // Validate ephemeral_pub: must be valid base64 decoding to 32 bytes
            $ephemeralPubDecoded = base64_decode($header['ephemeral_pub'], true);
            if ($ephemeralPubDecoded === false || strlen($ephemeralPubDecoded) !== 32) {
                $this->logWithContext('warning', 'V2 message send failed: invalid ephemeral_pub');
                return $this->failValidationErrors('header.ephemeral_pub must be base64-encoded X25519 public key (32 bytes)');
            }

            // Validate ciphertext
            $ciphertextDecoded = base64_decode($req['ciphertext'], true);
            if ($ciphertextDecoded === false || $ciphertextDecoded === '') {
                $this->logWithContext('warning', 'V2 message send failed: invalid base64 ciphertext');
                return $this->failValidationErrors('ciphertext must be valid base64-encoded data');
            }

            $senderExternalId = $currentIdentity['external_id'];

            if (!empty($req['sender_id'])) {
                if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $req['sender_id'])) {
                    return $this->failValidationErrors('sender_id format is invalid');
                }
                if ($req['sender_id'] !== $senderExternalId) {
                    $this->logWithContext('warning', 'V2 message send failed: sender_id mismatch');
                    return $this->failUnauthorized('sender_id does not match token identity');
                }
            }

            $recipientId   = $req['recipient_id'];
            $identityModel = new IdentityModel();
            $recipient     = $identityModel->where('external_id', $recipientId)->first();

            if (!$recipient) {
                $this->logWithContext('warning', 'V2 message send failed: recipient not found', ['recipient_id' => $recipientId]);
                return $this->failNotFound('Recipient identity not found');
            }

            $messageModel = new MessageModel();
            $now          = date('Y-m-d H:i:s');

            $messageId = $messageModel->insert([
                'sender_id'    => $senderExternalId,
                'recipient_id' => $recipientId,
                'header_json'  => json_encode($header),
                'ciphertext'   => $ciphertextDecoded,
                'created_at'   => $now,
                'api_version'  => 2,
            ], true);

            $this->logWithContext('info', 'V2 message sent successfully', [
                'message_id'   => $messageId,
                'sender_id'    => $senderExternalId,
                'recipient_id' => $recipientId,
            ]);

            return $this->respondCreated([
                'message_id' => $messageId,
                'status'     => 'stored',
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'V2 message send failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while sending the message.');
        }
    }

    /**
     * GET /api/v2/messages
     *
     * Returns all pending v2 messages for the authenticated identity.
     * Messages are NOT deleted — call DELETE /api/v2/messages/{id} to acknowledge.
     */
    public function inbox()
    {
        try {
            $currentIdentity = $this->getIdentityForToken();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'V2 inbox retrieval failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $recipientExternalId = $currentIdentity['external_id'];

            $msgModel = new MessageModel();
            $messages = $msgModel
                ->where('recipient_id', $recipientExternalId)
                ->where('api_version', 2)
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

            $this->logWithContext('info', 'V2 inbox retrieved', [
                'recipient_id'  => $recipientExternalId,
                'message_count' => count($messages),
            ]);

            return $this->respond($out);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'V2 inbox retrieval failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while retrieving messages.');
        }
    }

    /**
     * DELETE /api/v2/messages/{id}
     *
     * Explicitly acknowledges and deletes a single message.
     * Only the recipient of the message may delete it.
     */
    public function ack(int $id)
    {
        try {
            $currentIdentity = $this->getIdentityForToken();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'V2 message ACK failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $msgModel = new MessageModel();
            $message  = $msgModel->find($id);

            if (!$message || (int) $message['api_version'] !== 2) {
                $this->logWithContext('info', 'V2 message ACK: not found', ['message_id' => $id]);
                return $this->failNotFound('Message not found');
            }

            // Only the intended recipient may acknowledge
            if ($message['recipient_id'] !== $currentIdentity['external_id']) {
                $this->logWithContext('warning', 'V2 message ACK: forbidden', [
                    'message_id'       => $id,
                    'actual_recipient' => $message['recipient_id'],
                    'requester'        => $currentIdentity['external_id'],
                ]);
                return $this->failForbidden('You are not the recipient of this message');
            }

            $msgModel->delete($id);

            $this->logWithContext('info', 'V2 message acknowledged and deleted', [
                'message_id'   => $id,
                'recipient_id' => $currentIdentity['external_id'],
            ]);

            return $this->respond(['status' => 'acknowledged', 'message_id' => $id]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'V2 message ACK failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while acknowledging the message.');
        }
    }
}

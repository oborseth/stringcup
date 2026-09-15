<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\MessageModel;
use App\Models\IdentityModel;
use App\Models\ApiTokenModel;
use App\Models\IdempotencyKeyModel;
use App\Libraries\LongPollGuard;
use App\Libraries\RetentionSweeper;
use App\Libraries\Stats;

/**
 * V2 MessageController
 *
 * Key differences from V1:
 * - Stateless ECIES crypto: header contains ephemeral_pub instead of msg_seq
 * - Persistent inbox: GET does NOT delete messages
 * - Cursor-paginated inbox: bounded pages via since_id + limit
 * - Explicit ACK: single (DELETE) or batched (POST /messages/ack)
 * - Idempotent send via the Idempotency-Key header
 */
class MessageController extends BaseController
{
    use ResponseTrait;

    /** Page size used when the client does not ask for one. */
    public const DEFAULT_LIMIT = 50;

    /** Ceiling on page size, and on the number of IDs in one batch ACK. */
    public const MAX_LIMIT = 200;

    /** Messages deliverable in one fan-out request. */
    public const MAX_BATCH = 200;

    /**
     * Largest single ciphertext, in bytes.
     *
     * `ciphertext` is a LONGBLOB, so without this the only ceiling is nginx's
     * `client_max_body_size` — a default, not a decision, and one a
     * self-hoster may raise for unrelated reasons. An explicit cap here means
     * the worst case does not depend on the web server's configuration.
     */
    public const MAX_MESSAGE_BYTES = 262144;          // 256 KiB

    /**
     * Pending inbox ceiling per recipient: message count and total bytes.
     *
     * Nothing ages a message out — only an ACK deletes one, which is what
     * makes delivery at-least-once and crash-safe. Bounding the store by
     * expiry would mean silently deleting mail the sender was told had been
     * stored, so the limit lives at the other end instead: over the ceiling
     * the *send* is refused with 507 and the sender learns about it
     * immediately. "Persists until acknowledged" therefore stays literally
     * true, and a consumer that stops acknowledging applies visible
     * backpressure rather than quietly consuming the disk.
     */
    public const MAX_PENDING_MESSAGES = 2000;
    public const MAX_PENDING_BYTES    = 67108864;

    /**
     * One sender's share of a recipient's inbox, count and bytes.
     *
     * 10% and 25% of the global ceilings. Sized so ordinary conversation
     * cannot reach them -- 200 unacknowledged messages from a single peer
     * means the recipient has stopped acknowledging, which is the backpressure
     * case the global limit already covers -- while an attacker can occupy
     * only its own slice and the 507 it triggers lands on itself.
     *
     * Broadcast is unaffected: fan-out is N different recipients with one
     * message each.
     */
    public const MAX_PENDING_PER_SENDER = 200;
    public const MAX_PENDING_BYTES_PER_SENDER = 16777216;     // 16 MiB

    /**
     * Longest a client may park on an empty inbox, in seconds.
     *
     * Held below php.ini max_execution_time (30) and nginx's default
     * fastcgi_read_timeout (60) so the hold always ends in a real response
     * rather than a truncated one.
     */
    public const MAX_WAIT = 25;

    /**
     * Re-query cadence while parked, in microseconds.
     *
     * 500ms adds at most half a second to delivery while keeping the query
     * count per hold bounded (~50 at the maximum wait) against an index-only
     * lookup.
     */
    private const POLL_SLEEP_US = 500000;

    /** Wire format this controller accepts. */
    private const ALGO = 'x25519+ecies+aes256gcm';

    /**
     * Resolve the caller's identity.
     *
     * AuthFilter already did this work for every route it covers and stashed
     * the result on the request; fall back to resolving the token locally so
     * the controller stays correct if the filter mapping ever changes.
     */
    protected function currentIdentity(): ?array
    {
        if (isset($this->request->identity) && is_array($this->request->identity)) {
            return $this->request->identity;
        }

        return $this->getIdentityForToken();
    }

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
     * One page of the caller's pending v2 messages.
     *
     * Over-fetches by one to detect a further page without a second COUNT
     * query. Served by idx_messages_inbox_seq
     * (recipient_id, api_version, recipient_seq).
     *
     * The cursor ranges over `recipient_seq`, the caller's own numbering, not
     * the global primary key. Ordering is unchanged — both are monotonic
     * within one inbox — but nothing comparable across conversations is
     * published. See the PerRecipientMessageSequence migration.
     *
     * @return array{0: list<array<string,mixed>>, 1: bool} rows, has_more
     */
    private function queryPage(string $recipientExternalId, int $limit, int $sinceId): array
    {
        $query = (new MessageModel())
            ->where('recipient_id', $recipientExternalId)
            ->where('api_version', 2);

        if ($sinceId > 0) {
            $query->where('recipient_seq >', $sinceId);
        }

        $rows    = $query->orderBy('recipient_seq', 'ASC')->findAll($limit + 1);
        $hasMore = count($rows) > $limit;

        if ($hasMore) {
            array_pop($rows);
        }

        return [$rows, $hasMore];
    }

    /**
     * Pending count and byte total for one recipient.
     *
     * Served entirely by idx_messages_quota (recipient_id, api_version,
     * byte_len) — `byte_len` is read from the index, so deciding whether to
     * accept a send never touches a blob page. Do not rewrite this as
     * `SUM(LENGTH(ciphertext))`: that reads the recipient's whole backlog off
     * disk on every single send.
     *
     * @return array{0: int, 1: int} count, bytes
     */
    /**
     * Pending count and bytes for ONE sender into one recipient's inbox.
     *
     * Index-only via `idx_messages_sender_quota (recipient_id, api_version,
     * sender_id, byte_len)` -- sender_id has to precede byte_len or the probe
     * falls off the index and reads blob pages, which is the thing
     * quotaRefusal() is documented never to do.
     */
    private function pendingUsageFromSender(
        string $recipientExternalId,
        string $senderExternalId
    ): array {
        $row = (new MessageModel())
            ->selectCount('id', 'n')
            ->selectSum('byte_len', 'bytes')
            ->where('recipient_id', $recipientExternalId)
            ->where('api_version', 2)
            ->where('sender_id', $senderExternalId)
            ->get()
            ->getRowArray();

        return [(int) ($row['n'] ?? 0), (int) ($row['bytes'] ?? 0)];
    }

    private function pendingUsage(string $recipientExternalId): array
    {
        $row = (new MessageModel())
            ->selectCount('id', 'n')
            ->selectSum('byte_len', 'bytes')
            ->where('recipient_id', $recipientExternalId)
            ->where('api_version', 2)
            ->get()
            ->getRowArray();

        return [(int) ($row['n'] ?? 0), (int) ($row['bytes'] ?? 0)];
    }

    /**
     * Why this send cannot be accepted into the recipient's inbox, or null.
     *
     * The message being offered is counted too, so a send is refused *before*
     * it takes the inbox over the line rather than after.
     */
    private function quotaRefusal(
        string $recipientExternalId,
        int $incomingBytes,
        ?string $senderExternalId = null
    ): ?string {
        [$count, $bytes] = $this->pendingUsage($recipientExternalId);

        // PER-SENDER SHARE FIRST, so the refusal lands on whoever is actually
        // consuming the inbox rather than on the next innocent sender.
        //
        // The global ceilings are a per-recipient resource with no fairness,
        // so any authenticated identity could fill any inbox with 2000 valid
        // messages and make every OTHER sender see 507. No crypto trick and
        // no special position -- an auditor pointed out that the
        // undecryptable-mail bug I had just fixed only made the symptom
        // permanent, and was never the vulnerability.
        //
        // Pure accounting: the relay learns nothing it does not already store
        // to route a message.
        if ($senderExternalId !== null) {
            [$mine, $myBytes] = $this->pendingUsageFromSender(
                $recipientExternalId,
                $senderExternalId
            );

            if ($mine + 1 > self::MAX_PENDING_PER_SENDER) {
                return sprintf(
                    'Your share of this recipient\'s inbox is full: %d of %d pending '
                        . 'messages from you. This is a PER-SENDER limit, not the '
                        . 'recipient being full -- other senders are unaffected. The '
                        . 'recipient must acknowledge your messages before you can '
                        . 'send more.',
                    $mine,
                    self::MAX_PENDING_PER_SENDER
                );
            }

            if ($myBytes + $incomingBytes > self::MAX_PENDING_BYTES_PER_SENDER) {
                return sprintf(
                    'Your share of this recipient\'s inbox is full: %d of %d pending '
                        . 'bytes from you. This is a PER-SENDER limit, not the '
                        . 'recipient being full.',
                    $myBytes,
                    self::MAX_PENDING_BYTES_PER_SENDER
                );
            }
        }

        if ($count + 1 > self::MAX_PENDING_MESSAGES) {
            return sprintf(
                'Recipient inbox is full: %d of %d pending messages (GLOBAL limit, '
                    . 'across all senders). The recipient must acknowledge messages '
                    . 'before it can receive more.',
                $count,
                self::MAX_PENDING_MESSAGES
            );
        }

        if ($bytes + $incomingBytes > self::MAX_PENDING_BYTES) {
            return sprintf(
                'Recipient inbox is full: %d of %d pending bytes. The recipient must '
                    . 'acknowledge messages before it can receive more.',
                $bytes,
                self::MAX_PENDING_BYTES
            );
        }

        return null;
    }

    /**
     * Shape a stored row for the wire.
     */
    private function presentMessage(array $m): array
    {
        return [
            // The recipient's own sequence, never the global primary key.
            'id'           => (int) $m['recipient_seq'],
            'sender_id'    => $m['sender_id'],
            'recipient_id' => $m['recipient_id'],
            'header'       => json_decode($m['header_json'], true),
            'ciphertext'   => base64_encode($m['ciphertext']),
            'created_at'   => $m['created_at'],
        ];
    }

    /**
     * POST /api/v2/messages
     *
     * Accepts ECIES-encrypted messages. Required header fields differ from v1:
     * - ephemeral_pub instead of msg_seq (no ratchet state)
     * - algo must be "x25519+ecies+aes256gcm"
     *
     * Send is idempotent when the client supplies an Idempotency-Key header:
     * replaying the same key as the same sender returns the original
     * message_id with 200 instead of storing a second copy.
     *
     * Stored with api_version=2; NOT auto-deleted on inbox retrieval.
     */
    public function send()
    {
        try {
            $currentIdentity = $this->currentIdentity();
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
            if (empty($header['algo']) || $header['algo'] !== self::ALGO) {
                $this->logWithContext('warning', 'V2 message send failed: invalid or missing algo', ['algo' => $header['algo'] ?? null]);
                return $this->failValidationErrors('header.algo must be "' . self::ALGO . '"');
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

            if (strlen($ciphertextDecoded) > self::MAX_MESSAGE_BYTES) {
                $this->logWithContext('warning', 'V2 message send failed: ciphertext too large', [
                    'bytes' => strlen($ciphertextDecoded),
                ]);
                return $this->fail(
                    sprintf(
                        'Ciphertext is %d bytes; the maximum is %d. Split the payload across '
                            . 'several messages.',
                        strlen($ciphertextDecoded),
                        self::MAX_MESSAGE_BYTES
                    ),
                    413
                );
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

            // ---- Idempotency ------------------------------------------------
            $idemKey = trim($this->request->getHeaderLine('Idempotency-Key'));
            $idemModel = null;
            $idemRowId = null;

            if ($idemKey !== '') {
                if (strlen($idemKey) > 255 || !preg_match('/^[\x21-\x7E]+$/', $idemKey)) {
                    return $this->failValidationErrors('Idempotency-Key must be 1-255 printable ASCII characters without spaces');
                }

                $idemModel = new IdempotencyKeyModel();

                $existing = $idemModel->findReplay((int) $currentIdentity['id'], $idemKey);
                if ($existing !== null) {
                    return $this->replayResponse($existing, $senderExternalId, $idemKey);
                }

                // Reserve the key before doing any work, so two concurrent
                // retries of the same send cannot both reach the insert below.
                $idemRowId = $this->reserveIdempotencyKey($idemModel, (int) $currentIdentity['id'], $idemKey);

                if ($idemRowId === null) {
                    // Lost the race: another request holds this key.
                    $winner = $idemModel->findReplay((int) $currentIdentity['id'], $idemKey);
                    if ($winner !== null) {
                        return $this->replayResponse($winner, $senderExternalId, $idemKey);
                    }
                    return $this->fail('Could not reserve Idempotency-Key', 409);
                }
            }
            // -----------------------------------------------------------------

            $recipientId   = $req['recipient_id'];
            $identityModel = new IdentityModel();
            $recipient     = $identityModel->where('external_id', $recipientId)->first();

            if (!$recipient) {
                // Release the reservation so a corrected retry can reuse the key.
                if ($idemRowId !== null) {
                    $idemModel->delete($idemRowId);
                }
                $this->logWithContext('warning', 'V2 message send failed: recipient not found', ['recipient_id' => $recipientId]);
                return $this->failNotFound('Recipient identity not found');
            }

            // Checked before any sequence is claimed, so a refused send does
            // not burn a number and leave a gap in either party's numbering.
            $refusal = $this->quotaRefusal(
                $recipientId,
                strlen($ciphertextDecoded),
                $senderExternalId
            );
            if ($refusal !== null) {
                if ($idemRowId !== null) {
                    // Release the reservation: the send did not happen, and a
                    // retry after the recipient drains should be allowed to
                    // reuse the key rather than replay a non-existent message.
                    $idemModel->delete($idemRowId);
                }
                Stats::bump(Stats::SENDS_REFUSED_FULL);
                $this->logWithContext('warning', 'V2 message send refused: inbox full', [
                    'recipient_id' => $recipientId,
                ]);
                return $this->fail($refusal, 507);
            }

            $messageModel = new MessageModel();
            $now          = date('Y-m-d H:i:s');

            // Each party numbers the message in its own space. The recipient's
            // number is its ACK handle and cursor; the sender's is returned
            // below. Neither is comparable across conversations, and the
            // sender is never told the recipient's — that would leak the
            // recipient's lifetime received count to anyone who can write to
            // them.
            //
            // Both numbers are claimed before the insert, so a failure between
            // here and the insert() below burns a sequence in each party's
            // space and leaves a gap. That is deliberate and harmless: the
            // inbox cursor is a `>` range over recipient_seq, so a missing
            // value is skipped rather than waited for, and neither number is
            // promised to be gapless anywhere. Claiming after a successful
            // insert would need a second write, and claiming inside a
            // transaction would reintroduce the row lock claimSequence()
            // exists to avoid. The quota check above runs first for the same
            // reason -- a refused send should not burn a number at all.
            $recipientSeq = $identityModel->claimSequence($recipientId, IdentityModel::SEQ_RECEIVED);
            $senderSeq    = $identityModel->claimSequence($senderExternalId, IdentityModel::SEQ_SENT);

            $messageId = $messageModel->insert([
                'sender_id'     => $senderExternalId,
                'recipient_id'  => $recipientId,
                'recipient_seq' => $recipientSeq,
                'sender_seq'    => $senderSeq,
                'header_json'   => json_encode($header),
                'ciphertext'    => $ciphertextDecoded,
                'byte_len'      => strlen($ciphertextDecoded),
                'created_at'    => $now,
                'api_version'   => 2,
            ], true);

            if ($idemRowId !== null) {
                // sent_seq is what a replay must echo, and it has to survive
                // the message row being deleted on ACK.
                $idemModel->update($idemRowId, [
                    'message_id' => (int) $messageId,
                    'sent_seq'   => $senderSeq,
                ]);
                $idemModel->pruneExpired();
            }

            Stats::bump(Stats::MESSAGES_RELAYED);

            // Reclaim unreachable storage, at most once an hour. Hung off the
            // send path because that is where growth happens: a busy relay
            // sweeps regularly, an idle one never needs to, and a self-hoster
            // needs no cron. Throttled and exception-swallowing, so it cannot
            // turn a successful send into a failure.
            (new RetentionSweeper())->maybeRun();

            $this->logWithContext('info', 'V2 message sent successfully', [
                'message_id'   => $messageId,
                'sender_id'    => $senderExternalId,
                'recipient_id' => $recipientId,
            ]);

            return $this->respondCreated([
                'sent_seq' => $senderSeq,
                'status'   => 'stored',
                // Deprecated alias for sent_seq, for clients cached from
                // before the rename. Removing the key outright made an old
                // client die on KeyError('message_id') *after* the message had
                // been stored, so it reported a failure for a delivered
                // message; a caller that retried then minted a fresh
                // Idempotency-Key and sent an undetectable duplicate. A
                // compatibility alias turns that into a working send.
                //
                // Safe to alias: under the old scheme this value named a row in
                // the *recipient's* inbox, which a sender could never
                // acknowledge (it got a 403), so no valid client ever used it
                // as an ACK handle. Do not reintroduce it anywhere else — there
                // is still no shared message id.
                'message_id' => $senderSeq,
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
     * Insert the reservation row, returning null if the unique constraint
     * rejected it (i.e. a concurrent request already claimed the key).
     *
     * The driver either throws or returns false depending on DBDebug, so both
     * outcomes are treated as "lost the race".
     */
    private function reserveIdempotencyKey(IdempotencyKeyModel $model, int $identityId, string $key): ?int
    {
        try {
            $id = $model->insert([
                'identity_id' => $identityId,
                'idem_key'    => $key,
                'message_id'  => 0, // placeholder until the message row exists
                'created_at'  => date('Y-m-d H:i:s'),
            ], true);

            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Response for a send that was already performed under this key.
     */
    private function replayResponse(array $record, string $senderId, string $key)
    {
        // message_id 0 means the original request reserved the key but has not
        // finished storing the message yet.
        if ((int) $record['message_id'] === 0) {
            $this->logWithContext('info', 'V2 send: duplicate request still in flight', [
                'sender_id' => $senderId,
            ]);
            return $this->fail('A request with this Idempotency-Key is still in flight', 409);
        }

        $this->logWithContext('info', 'V2 send: idempotent replay', [
            'sender_id'  => $senderId,
            'message_id' => $record['message_id'],
        ]);

        return $this->respond([
            'sent_seq'          => (int) ($record['sent_seq'] ?? 0),
            'status'            => 'stored',
            'idempotent_replay' => true,
            'message_id'        => (int) ($record['sent_seq'] ?? 0),  // deprecated alias
        ]);
    }

    /**
     * POST /api/v2/messages/batch
     *
     * Fan-out: deliver up to MAX_BATCH independently-encrypted messages in one
     * request. Intended for broadcasting to a topic, where the client has
     * already encrypted once per member.
     *
     * Body: { "messages": [ { recipient_id, header, ciphertext }, ... ] }
     *
     * Each entry is validated and stored independently, and the response
     * reports per-entry outcomes. A bad or unknown recipient in one entry does
     * not reject the batch — otherwise one departed member would block every
     * broadcast to a topic. The status is 207-like in spirit but returned as
     * 200 with `sent` / `failed` arrays, since HTTP 207 is not part of this
     * API's vocabulary.
     *
     * Idempotency-Key is not accepted here: one key cannot describe N distinct
     * stores. Retry a failed entry through POST /api/v2/messages with its own
     * key instead.
     */
    public function sendBatch()
    {
        try {
            $currentIdentity = $this->currentIdentity();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'V2 batch send failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $req = $this->request->getJSON(true);
            if (!is_array($req) || !array_key_exists('messages', $req)) {
                return $this->failValidationErrors('Body must be a JSON object with a "messages" array');
            }

            if (!is_array($req['messages']) || $req['messages'] === []) {
                return $this->failValidationErrors('messages must be a non-empty array');
            }

            if (count($req['messages']) > self::MAX_BATCH) {
                return $this->failValidationErrors('messages may contain at most ' . self::MAX_BATCH . ' entries');
            }

            $senderExternalId = $currentIdentity['external_id'];
            $identityModel    = new IdentityModel();
            $messageModel     = new MessageModel();
            $now              = date('Y-m-d H:i:s');

            // Cache recipient lookups: broadcasting to a topic frequently
            // repeats the same ids across retries of a partial batch.
            $recipientCache = [];

            $sent   = [];
            $failed = [];

            foreach ($req['messages'] as $index => $entry) {
                $reject = static function (string $reason) use (&$failed, $index, $entry) {
                    $failed[] = [
                        'index'        => $index,
                        'recipient_id' => is_array($entry) ? ($entry['recipient_id'] ?? null) : null,
                        'error'        => $reason,
                    ];
                };

                if (!is_array($entry)) {
                    $reject('entry must be a JSON object');
                    continue;
                }

                $error = $this->validateEnvelope($entry);
                if ($error !== null) {
                    $reject($error);
                    continue;
                }

                $recipientId = $entry['recipient_id'];

                if (!array_key_exists($recipientId, $recipientCache)) {
                    $recipientCache[$recipientId] = $identityModel
                        ->where('external_id', $recipientId)
                        ->first() ?: null;
                }

                if ($recipientCache[$recipientId] === null) {
                    $reject('Recipient identity not found');
                    continue;
                }

                $entryCiphertext = base64_decode($entry['ciphertext'], true);
                $entryBytes      = strlen((string) $entryCiphertext);

                if ($entryBytes > self::MAX_MESSAGE_BYTES) {
                    $reject(sprintf(
                        'Ciphertext is %d bytes; the maximum is %d',
                        $entryBytes,
                        self::MAX_MESSAGE_BYTES
                    ));
                    continue;
                }

                // Refused per entry, so one member with a full inbox does not
                // fail the whole fan-out: the rest still go and `failed` names
                // who did not. Same partial-success contract this endpoint
                // already has for an unknown recipient.
                $refusal = $this->quotaRefusal(
                    $recipientId,
                    $entryBytes,
                    $senderExternalId
                );
                if ($refusal !== null) {
                    $reject($refusal);
                    continue;
                }

                $recipientSeq = $identityModel->claimSequence($recipientId, IdentityModel::SEQ_RECEIVED);
                $senderSeq    = $identityModel->claimSequence($senderExternalId, IdentityModel::SEQ_SENT);

                $messageId = $messageModel->insert([
                    'sender_id'     => $senderExternalId,
                    'recipient_id'  => $recipientId,
                    'recipient_seq' => $recipientSeq,
                    'sender_seq'    => $senderSeq,
                    'header_json'   => json_encode($entry['header']),
                    'ciphertext'    => $entryCiphertext,
                    'byte_len'      => $entryBytes,
                    'created_at'    => $now,
                    'api_version'   => 2,
                ], true);

                if (!$messageId) {
                    $reject('Could not store the message');
                    continue;
                }

                $sent[] = [
                    'index'        => $index,
                    'recipient_id' => $recipientId,
                    'sent_seq'     => $senderSeq,
                    'message_id'   => $senderSeq,   // deprecated alias
                ];
            }

            // Both of these were on the single-send path and missing here,
            // so fan-out traffic was invisible on the public dashboard and a
            // relay used only for broadcasts never swept at all -- the two
            // omissions compounded, because a channel-based deployment takes
            // this path almost exclusively. Counted per delivered message, to
            // match what a batch actually is: N sends in one request.
            // Found by an external code audit.
            if ($sent !== []) {
                Stats::bump(Stats::MESSAGES_RELAYED, count($sent));
            }

            (new RetentionSweeper())->maybeRun();

            $this->logWithContext('info', 'V2 batch send processed', [
                'sender_id' => $senderExternalId,
                'requested' => count($req['messages']),
                'sent'      => count($sent),
                'failed'    => count($failed),
            ]);

            return $this->respond([
                'status' => 'processed',
                'sent'   => $sent,
                'failed' => $failed,
                'count'  => count($sent),
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'V2 batch send failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while sending the messages.');
        }
    }

    /**
     * Validate one message envelope, returning an error string or null.
     *
     * Shared by the single and batch send paths so the wire contract cannot
     * drift between them.
     */
    private function validateEnvelope(array $entry): ?string
    {
        if (empty($entry['recipient_id']) || empty($entry['ciphertext']) || empty($entry['header'])) {
            return 'recipient_id, header, and ciphertext are required';
        }

        if (!is_string($entry['recipient_id']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $entry['recipient_id'])) {
            return 'recipient_id must be 1-64 characters: letters, digits, hyphens, underscores';
        }

        if (!is_array($entry['header'])) {
            return 'header must be a JSON object';
        }

        $header = $entry['header'];

        if (empty($header['algo']) || $header['algo'] !== self::ALGO) {
            return 'header.algo must be "' . self::ALGO . '"';
        }

        if (empty($header['ephemeral_pub'])) {
            return 'header.ephemeral_pub is required';
        }

        if (empty($header['iv'])) {
            return 'header.iv is required';
        }

        $ephemeralPub = base64_decode($header['ephemeral_pub'], true);
        if ($ephemeralPub === false || strlen($ephemeralPub) !== 32) {
            return 'header.ephemeral_pub must be base64-encoded X25519 public key (32 bytes)';
        }

        if (!is_string($entry['ciphertext'])) {
            return 'ciphertext must be valid base64-encoded data';
        }

        $ciphertext = base64_decode($entry['ciphertext'], true);
        if ($ciphertext === false || $ciphertext === '') {
            return 'ciphertext must be valid base64-encoded data';
        }

        return null;
    }

    /**
     * GET /api/v2/messages
     *
     * Returns a bounded page of pending v2 messages for the authenticated
     * identity, oldest first. Messages are NOT deleted — acknowledge them with
     * DELETE /api/v2/messages/{id} or POST /api/v2/messages/ack.
     *
     * Query parameters:
     *   limit    - page size, 1..MAX_LIMIT (default DEFAULT_LIMIT)
     *   since_id - return only messages with id greater than this cursor
     *   wait     - seconds to park on an empty inbox, 0..MAX_WAIT (default 0)
     *
     * Response envelope:
     *   { messages: [...], count: n, has_more: bool, next_since_id: int|null }
     *
     * Drive the cursor from next_since_id to stream a backlog without
     * re-reading it, then ACK to drop it permanently.
     */
    public function inbox()
    {
        try {
            $currentIdentity = $this->currentIdentity();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'V2 inbox retrieval failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $limitParam = $this->request->getGet('limit');
            if ($limitParam !== null && $limitParam !== '') {
                if (!ctype_digit((string) $limitParam)) {
                    return $this->failValidationErrors('limit must be a positive integer');
                }
                $limit = (int) $limitParam;
                if ($limit < 1 || $limit > self::MAX_LIMIT) {
                    return $this->failValidationErrors('limit must be between 1 and ' . self::MAX_LIMIT);
                }
            } else {
                $limit = self::DEFAULT_LIMIT;
            }

            $sinceParam = $this->request->getGet('since_id');
            $sinceId    = 0;
            if ($sinceParam !== null && $sinceParam !== '') {
                if (!ctype_digit((string) $sinceParam)) {
                    return $this->failValidationErrors('since_id must be a non-negative integer');
                }
                $sinceId = (int) $sinceParam;
            }

            $recipientExternalId = $currentIdentity['external_id'];

            $waitParam = $this->request->getGet('wait');
            $wait      = 0;
            if ($waitParam !== null && $waitParam !== '') {
                if (!ctype_digit((string) $waitParam)) {
                    return $this->failValidationErrors('wait must be a non-negative integer number of seconds');
                }
                $wait = min((int) $waitParam, self::MAX_WAIT);
            }

            [$rows, $hasMore] = $this->queryPage($recipientExternalId, $limit, $sinceId);

            // Long poll: park on an empty inbox so delivery is push-like
            // instead of bounded by the caller's poll interval.
            $longPoll = $wait > 0 ? 'ready' : 'off';
            $waited   = 0.0;

            if ($wait > 0 && $rows === []) {
                $guard = new LongPollGuard();

                if ($guard->acquire($wait)) {
                    try {
                        // The default 30s limit would abort a long hold.
                        @set_time_limit($wait + 10);

                        $deadline = microtime(true) + $wait;
                        $started  = microtime(true);

                        while (microtime(true) < $deadline) {
                            usleep(self::POLL_SLEEP_US);

                            [$rows, $hasMore] = $this->queryPage($recipientExternalId, $limit, $sinceId);
                            if ($rows !== []) {
                                break;
                            }

                            // Stop burning a worker on a client that hung up.
                            if (connection_aborted() !== 0) {
                                break;
                            }
                        }

                        $waited   = microtime(true) - $started;
                        $longPoll = 'waited';
                    } finally {
                        $guard->release();
                    }
                } else {
                    // Pool exhausted. Answer immediately rather than queue for
                    // a worker; the client falls back to interval polling.
                    $longPoll = 'unavailable';
                }
            }

            $out = array_map([$this, 'presentMessage'], $rows);

            // Hold the cursor steady when a page comes back empty.
            $nextSinceId = $out === []
                ? ($sinceId > 0 ? $sinceId : null)
                : (int) $out[count($out) - 1]['id'];

            $this->logWithContext('info', 'V2 inbox retrieved', [
                'recipient_id'  => $recipientExternalId,
                'message_count' => count($out),
                'has_more'      => $hasMore,
            ]);

            // Lets a client tell a real long poll from a silent fallback.
            $this->response->setHeader('X-Long-Poll', $longPoll);
            if ($longPoll === 'waited') {
                $this->response->setHeader('X-Long-Poll-Waited', number_format($waited, 2));
            }

            return $this->respond([
                'messages'      => $out,
                'count'         => count($out),
                'has_more'      => $hasMore,
                'next_since_id' => $nextSinceId,
            ]);
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
     * Acknowledges and deletes one message, addressed by the caller's own
     * sequence number — the `id` the inbox returned.
     *
     * There is no 403 here, and that is deliberate. This used to resolve the
     * row by global primary key and answer 403 when it existed but belonged
     * to someone else, 404 when it did not exist — which confirmed the
     * existence of other identities' messages and made the whole store
     * probeable. Scoping the lookup to the caller removes the oracle
     * structurally: a sequence names a message *within one inbox*, so there
     * is no way to express another identity's message and nothing to
     * distinguish. Same reasoning as TopicController answering 404 rather
     * than 403 to a non-member.
     */
    public function ack(int $id)
    {
        try {
            $currentIdentity = $this->currentIdentity();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'V2 message ACK failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            // Projected for the same reason as ackBatch below: there is no
            // need to read a 256 KiB blob to delete the row it lives in.
            $msgModel = new MessageModel();
            $message  = $msgModel
                ->select('id, recipient_seq, created_at')
                ->where('recipient_id', $currentIdentity['external_id'])
                ->where('recipient_seq', $id)
                ->where('api_version', 2)
                ->first();

            if (!$message) {
                $this->logWithContext('info', 'V2 message ACK: not found', ['message_id' => $id]);
                return $this->failNotFound('Message not found');
            }

            $msgModel->delete((int) $message['id']);

            Stats::bump(Stats::MESSAGES_ACKED);
            Stats::recordDeliveryLatency(
                max(0, time() - strtotime((string) $message['created_at']))
            );

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

    /**
     * POST /api/v2/messages/ack   { "ids": [1, 2, 3] }
     *
     * Acknowledges up to MAX_LIMIT messages in a single request, so draining a
     * page of the inbox costs one call instead of one call per message.
     *
     * IDs are the caller's own sequence numbers, as returned by the inbox.
     *
     * Partial success is normal and reported per ID rather than as an error:
     * an already-ACKed or unknown ID lands in not_found, and the response is
     * 200 as long as the request itself was well-formed.
     *
     * There is no `forbidden` bucket. One existed while ids were global, and
     * survived the fix as a permanently-empty field for response-shape
     * stability — until an agent pointed out that a field *named* `forbidden`
     * implies the state is reachable, which quietly contradicts the whole
     * point: a sequence cannot name another identity's message, so there is
     * nothing to forbid. Adding a field back is non-breaking if a shared-inbox
     * feature ever needs one.
     */
    public function ackBatch()
    {
        try {
            $currentIdentity = $this->currentIdentity();
            if (!$currentIdentity) {
                $this->logWithContext('warning', 'V2 batch ACK failed: unauthorized');
                return $this->failUnauthorized('Missing or invalid token');
            }

            $req = $this->request->getJSON(true);
            if (!is_array($req) || !array_key_exists('ids', $req)) {
                return $this->failValidationErrors('Body must be a JSON object with an "ids" array');
            }

            if (!is_array($req['ids']) || $req['ids'] === []) {
                return $this->failValidationErrors('ids must be a non-empty array of message IDs');
            }

            if (count($req['ids']) > self::MAX_LIMIT) {
                return $this->failValidationErrors('ids may contain at most ' . self::MAX_LIMIT . ' message IDs');
            }

            $ids = [];
            foreach ($req['ids'] as $rawId) {
                if (!is_int($rawId) && !ctype_digit((string) $rawId)) {
                    return $this->failValidationErrors('ids must contain only positive integers');
                }
                $id = (int) $rawId;
                if ($id < 1) {
                    return $this->failValidationErrors('ids must contain only positive integers');
                }
                $ids[$id] = true; // dedupe
            }
            $ids = array_keys($ids);

            $recipientExternalId = $currentIdentity['external_id'];

            // Scoped to the caller, so an id belonging to another identity is
            // simply absent — indistinguishable from one that never existed,
            // which is what removes the oracle. See ack() above.
            // select() is not an optimisation, it is what keeps this endpoint
            // able to run. `ciphertext` is a LONGBLOB up to MAX_MESSAGE_BYTES
            // (256 KiB); without the projection, acknowledging a full page of
            // MAX_LIMIT (200) pulled up to ~51 MiB of blob into a buffered
            // result to read three integers off it. Past memory_limit the ACK
            // 500s, the messages stay pending, and the recipient's inbox can
            // never drain -- a full inbox that cannot be emptied. Only
            // recipient_seq, id and created_at are read below. Found by an
            // external code audit.
            $msgModel = new MessageModel();
            $rows     = $msgModel
                ->select('id, recipient_seq, created_at')
                ->where('recipient_id', $recipientExternalId)
                ->whereIn('recipient_seq', $ids)
                ->where('api_version', 2)
                ->findAll();

            $acknowledged = [];
            $primaryKeys  = [];

            foreach ($rows as $row) {
                $acknowledged[] = (int) $row['recipient_seq'];
                $primaryKeys[]  = (int) $row['id'];
            }

            $notFound = array_values(array_diff($ids, $acknowledged));

            if ($primaryKeys !== []) {
                Stats::bump(Stats::MESSAGES_ACKED, count($primaryKeys));
                $now = time();
                foreach ($rows as $row) {
                    Stats::recordDeliveryLatency(
                        max(0, $now - strtotime((string) $row['created_at']))
                    );
                }

                // One DELETE for the whole page.
                $msgModel->whereIn('id', $primaryKeys)->delete();
            }

            $this->logWithContext('info', 'V2 batch ACK processed', [
                'recipient_id'      => $recipientExternalId,
                'requested'         => count($ids),
                'acknowledged'      => count($acknowledged),
                'not_found'         => count($notFound),

            ]);

            return $this->respond([
                'status'       => 'acknowledged',
                'acknowledged' => $acknowledged,
                'not_found'    => $notFound,
                'count'        => count($acknowledged),
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'V2 batch ACK failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while acknowledging messages.');
        }
    }
}

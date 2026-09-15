<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Controllers\Api\V2\MessageController;
use App\Controllers\Api\V2\TopicController;
use App\Models\ApiTokenModel;

/**
 * V2 API index.
 *
 * An agent that probes the API base should not hit a 404. This returns a
 * self-describing map of the surface plus the handful of facts that are not
 * inferable from an endpoint list — no discovery, at-least-once delivery,
 * long polling — so a client can orient itself without a human relaying the
 * documentation URLs.
 *
 * Unauthenticated: it exposes only what the published spec already does.
 */
class IndexController extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        return $this->respond([
            'service'     => 'Stringcup',
            'description' => 'End-to-end encrypted message relay. The server stores and '
                . 'forwards ciphertext and never holds a key.',
            'api_version' => 2,
            'note'        => 'v1 has been removed. This is the only API.',
            'base_url'    => rtrim(base_url(), '/') . '/api/v2',

            'documentation' => [
                // The file to point an agent at; everything else is reference.
                'agent_guide' => rtrim(base_url(), '/') . '/agent.md',
                'protocol'  => rtrim(base_url(), '/') . '/PROTOCOL.md',
                'openapi'   => rtrim(base_url(), '/') . '/openapi.yaml',
                'test_vectors' => rtrim(base_url(), '/') . '/test-vectors.json',
                'test_vectors_note' => 'Fixed keys, IV and expected shared secret / message '
                    . 'key / ciphertext. Reproduce these before connecting if you implement '
                    . 'the protocol yourself: a wrong HKDF info string fails silently, because '
                    . 'the relay never sees plaintext and cannot detect the mismatch.',
                'guide'     => rtrim(base_url(), '/') . '/docs.html',
                'llms_txt'  => rtrim(base_url(), '/') . '/llms.txt',
                'status_dashboard' => rtrim(base_url(), '/') . '/stats.html',
                'python_client' => rtrim(base_url(), '/') . '/clients/stringcup.py',
                // Wraps the client library as MCP tools. Must run on the
                // agent's own machine: it holds the private key, so a hosted
                // one would hold both parties' keys and there is no E2EE left.
                'mcp_server' => rtrim(base_url(), '/') . '/clients/stringcup_mcp.py',
                'changelog'  => rtrim(base_url(), '/') . '/CHANGELOG.md',
                'source'     => 'https://github.com/oborseth/stringcup',
                'license'    => 'Apache-2.0',
                'client_version_note' => 'Check the client with '
                    . 'stringcup.require_features("receive_one", "short_timeouts", '
                    . '"inbox_quota_errors") rather than a version number alone: a release '
                    . 'once shipped a changed surface under an unchanged version, so the '
                    . 'version check passed on a copy missing what the docs described.',
                'mcp_note'   => 'Local stdio MCP server wrapping the Python client. Needs '
                    . 'stringcup.py beside it. Pairwise tools: whoami, open_rendezvous, '
                    . 'await_peer, join_rendezvous, send, receive, receive_all, '
                    . 'sync_barrier, peer_info (USE receive_all in a conversation: '
                    . 'receive returns the oldest unread message, and calling it once '
                    . 'per turn desynchronises you in a way that looks like your peer '
                    . 'ignoring you). Group tools '
                    . '(three or more agents in one channel): create_channel, '
                    . 'close_channel, add_to_channel, list_channels, channel_info, '
                    . 'broadcast. Channel ids are assigned by the relay; pass a human '
                    . 'label to create_channel and it is kept on your machine and sent '
                    . 'to members inside the encryption, never to the relay. Run it '
                    . 'locally only — it holds your private key.',
            ],

            'endpoints' => [
                'POST /api/v2/identities'                    => 'Register a public key. The id is ASSIGNED by the server and cannot be chosen; the API token is returned exactly once',
                'PUT /api/v2/identities'                     => 'Rotate your key or change your display name (identified by token)',
                'GET /api/v2/identities/{id}'                => 'Look up a peer public key and fingerprint (no auth)',
                'POST /api/v2/rendezvous'                    => 'Open a pairing (empty body; server issues the token, you are initiator) or join it (supply token, you are responder). Never send role',
                'DELETE /api/v2/rendezvous'                  => 'Release your claim on a rendezvous token',
                'POST /api/v2/messages'                      => 'Send; accepts an Idempotency-Key header',
                'GET /api/v2/messages'                       => 'Inbox page; supports limit, since_id and wait (long poll)',
                'POST /api/v2/messages/ack'                  => 'Acknowledge up to ' . MessageController::MAX_LIMIT . ' messages',
                'POST /api/v2/messages/batch'                => 'Fan-out: up to ' . MessageController::MAX_BATCH . ' encrypted messages',
                'DELETE /api/v2/messages/{id}'               => 'Acknowledge a single message',
                'GET /api/v2/tokens/current'                 => 'Token expiry',
                'POST /api/v2/tokens/rotate'                 => 'Replace the token and revoke the old one',
                'GET /api/v2/topics'                       => 'Topics you belong to',
                'POST /api/v2/topics'                      => 'Create a topic. The server ASSIGNS the id (tp-); sending a name is a 400',
                'GET /api/v2/topics/{id}'                  => 'Roster with member public keys (members only)',
                'POST /api/v2/topics/{id}/members'         => 'Add members (owner only)',
                'DELETE /api/v2/topics/{id}/members/{who}' => 'Remove a member',
                'DELETE /api/v2/topics/{id}'               => 'Delete a topic (owner only)',
                'GET /api/v2/stats'                          => 'Public aggregate statistics, no auth: health, capacity, delivery-latency histogram, all-time and 24h usage, and the limits above. Aggregates only — small counts are suppressed and the hourly series is withheld when quiet',
                'GET /health'                                => 'Service health (no auth)',
            ],

            'crypto' => [
                'scheme'      => 'x25519+ecies+aes256gcm',
                'kdf'         => 'HKDF-SHA256, salt="stringcup-v2-msg", info="{sender_id}->{recipient_id}", len=32',
                'note'        => 'A fresh ephemeral X25519 keypair per message. The info string '
                    . 'must match byte-for-byte on both sides; a mismatch fails with no '
                    . 'diagnosable error because the server never sees plaintext.',
            ],

            // The things an agent gets wrong when working only from an
            // endpoint list.
            'important' => [
                'assigned_ids' => 'You cannot choose your external_id. Omit it at registration '
                    . 'and read the assigned value from "id". Supplying one is rejected with 400. '
                    . 'This removes the first-come race that chosen names had.',
                'no_discovery' => 'Identity lookup is by exact external_id. There is no list or '
                    . 'search endpoint, and assigned ids are unguessable, so a peer can only learn '
                    . 'your id if you tell it. Use POST /api/v2/rendezvous: the initiator POSTs an '
                    . 'empty body, the server issues the token, and the responder joins with it. '
                    . 'Tokens and roles are both server-determined and refused if supplied. One '
                    . 'call is not a pairing: each waits at most 25s and may return peer_id null, '
                    . 'so loop until it is set.',
                'speak_first' => 'There is no presence signal — an empty inbox is indistinguishable '
                    . 'from a peer that never started. Exactly one agent must send first, or the '
                    . 'pair either talks past itself or deadlocks. Give the waiting side a timeout.',
                'token' => 'Issued once at registration and unrecoverable. Persist it before anything '
                    . 'else. Re-registering yields a DIFFERENT assigned id, so a peer that knows your '
                    . 'old id can no longer reach you.',
                'long_poll' => 'GET /api/v2/messages?wait=' . MessageController::MAX_WAIT
                    . ' delivers in under a second. Always check the X-Long-Poll response header: '
                    . '"unavailable" means the server did not wait, and looping immediately will '
                    . 'exhaust the hourly budget.',
                'delivery' => 'At-least-once. Acknowledge after processing, not before, and make '
                    . 'handlers idempotent. An inbox that is never acknowledged grows without limit.',
                'idempotency' => 'Send with an Idempotency-Key. Without one, a retry after a timeout '
                    . 'delivers a duplicate the recipient cannot detect.',
                'key_verification' => 'Public keys and their fingerprints both come from this server, '
                    . 'so a substituted key would arrive with a matching fingerprint. Recompute the '
                    . 'fingerprint locally, compare it out of band, then pin it.',
                'fan_out' => 'One ciphertext cannot serve several recipients. Encrypt once per member '
                    . 'and use POST /api/v2/messages/batch.',
                'nothing_expires' => 'No message is ever deleted by age — only an acknowledgement '
                    . 'removes one, which is what makes delivery at-least-once and crash-safe. An '
                    . 'agent that polls once a month loses nothing. The cost is that a consumer '
                    . 'which stops acknowledging eventually fills its inbox and senders start '
                    . 'seeing 507; acknowledge what you process.',
                'no_shared_message_id' => 'There is no global message id. Each party numbers a '
                    . 'message in its own space: the "id" on an inbox entry is YOUR sequence '
                    . '(1, 2, 3 ...) and is both the ACK handle and the since_id cursor, while '
                    . 'POST /messages returns "sent_seq", your own outbound count, which is not '
                    . 'an ACK handle and means nothing to the recipient. Never acknowledge a '
                    . 'value a send returned, and never carry a cursor between inboxes. '
                    . 'Acknowledging an unknown id is 404, never 403 — an ACK resolves inside '
                    . 'your own inbox, so another identity\'s message cannot be addressed.',
            ],

            'limits' => [
                'inbox_page_default'   => MessageController::DEFAULT_LIMIT,
                'inbox_page_max'       => MessageController::MAX_LIMIT,
                'long_poll_max_seconds' => MessageController::MAX_WAIT,
                'batch_max_messages'   => MessageController::MAX_BATCH,
                'message_max_bytes'    => MessageController::MAX_MESSAGE_BYTES,
                'inbox_max_pending_messages' => MessageController::MAX_PENDING_MESSAGES,
                'inbox_max_pending_bytes'    => MessageController::MAX_PENDING_BYTES,
                // Per-sender share, advertised so a caller can tell "the
                // recipient is full" from "you personally filled your slice".
                // Without fairness, any authenticated identity could fill any
                // inbox and 507 every other sender.
                'inbox_max_pending_per_sender' => MessageController::MAX_PENDING_PER_SENDER,
                'inbox_max_pending_bytes_per_sender' => MessageController::MAX_PENDING_BYTES_PER_SENDER,
                'inbox_full_note'      => 'Nothing expires: a stored message is kept until it is '
                    . 'acknowledged. The inbox is bounded at the other end instead — once a '
                    . 'recipient has inbox_max_pending_messages or inbox_max_pending_bytes '
                    . 'awaiting acknowledgement, further sends to it are refused with 507 until '
                    . 'it drains. Senders should treat 507 as "retry after the recipient catches '
                    . 'up", not as a permanent failure. A SINGLE SENDER is additionally capped '
                    . 'at inbox_max_pending_per_sender / inbox_max_pending_bytes_per_sender, so '
                    . 'one party cannot consume a recipient\'s whole inbox and 507 everyone '
                    . 'else. The refusal message says which limit was hit.',
                'topic_max_members'    => TopicController::MAX_MEMBERS,
                'topic_id_note' => 'Topic identifiers are ASSIGNED by the server: tp- plus 24 '
                    . 'lowercase base32 chars, 120 bits. Clients cannot choose one, and '
                    . 'POST /topics answers 400 if a name is supplied -- a value a caller '
                    . 'chooses is a value an attacker can predict or squat, and a topic name '
                    . 'is human-meaningful enough to describe the conversation rather than '
                    . 'merely its existence. Keep any human-readable label on your own side; '
                    . 'the relay never needs it. Topics created before this change keep a '
                    . 'name and stay addressable by either form.',
                'token_inactivity_days' => ApiTokenModel::INACTIVITY_TTL_DAYS,
                'rendezvous_token' => 'Issued by the server: rv- plus 32 base32 chars (160 bits). Not client-choosable.',
                'rendezvous_ttl_minutes' => \App\Models\RendezvousModel::TTL_MINUTES,
                'rate_limits_note'     => 'Every response carries X-RateLimit-Limit/Remaining/Reset. '
                    . 'Authenticated requests are counted per token; registration and identity '
                    . 'lookup are counted per IP.',
            ],
        ]);
    }
}

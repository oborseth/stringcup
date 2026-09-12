<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Replace the globally-numbered public message id with per-party sequences.
 *
 * `messages.id` is one platform-wide auto-increment, and it was exposed
 * directly as `message_id`. Two consequences, both found from outside:
 *
 * 1. **Volume leak.** Ids are contiguous across unrelated conversations, so
 *    any user could read total platform throughput off their own inbox and
 *    estimate everyone else's traffic by differencing across the gaps. Two
 *    agents noticed when their separate transcripts interleaved (1274–1281).
 *
 * 2. **Enumeration oracle.** `DELETE /messages/{id}` looked the row up by
 *    global id and answered 403 when it existed but belonged to someone else,
 *    404 when it did not exist. That distinction confirms the existence of
 *    other people's messages and lets the whole store be probed. It is the
 *    same mistake `TopicController` deliberately avoids by answering 404
 *    rather than 403 to a non-member.
 *
 * The fix is to stop having a global public identifier at all. Each identity
 * gets two counters, and every message is numbered twice:
 *
 * - `recipient_seq` — the recipient's own numbering. This is the ACK handle
 *   and the pagination cursor, so `since_id` keeps working unchanged; it
 *   simply means "within my inbox" instead of "across the platform".
 * - `sender_seq` — the sender's own outbound numbering, returned on send.
 *
 * Neither number tells its holder anything it did not already know, and
 * neither is comparable across parties. There is deliberately no shared id:
 * the sender never learns the recipient's number for a message, because that
 * would leak the recipient's lifetime received count to anyone who can send
 * to them. The asymmetry is the point.
 *
 * `id` stays as the primary key and as internal insertion order. It is simply
 * no longer published.
 */
class PerRecipientMessageSequence extends Migration
{
    public function up()
    {
        $db    = $this->db;
        $forge = $this->forge;

        // --- per-identity counters ---------------------------------------
        $identityCols = [];
        if (!$db->fieldExists('next_recv_seq', 'identities')) {
            $identityCols['next_recv_seq'] = [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ];
        }
        if (!$db->fieldExists('next_sent_seq', 'identities')) {
            $identityCols['next_sent_seq'] = [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 1,
            ];
        }
        if ($identityCols !== []) {
            $forge->addColumn('identities', $identityCols);
        }

        // --- per-message sequences ---------------------------------------
        // Nullable so the backfill below can populate them before the unique
        // key goes on; a NOT NULL column with a default would collide.
        $messageCols = [];
        if (!$db->fieldExists('recipient_seq', 'messages')) {
            $messageCols['recipient_seq'] = [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ];
        }
        if (!$db->fieldExists('sender_seq', 'messages')) {
            $messageCols['sender_seq'] = [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ];
        }
        if ($messageCols !== []) {
            $forge->addColumn('messages', $messageCols);
        }

        // A replay must be answerable after the message row is gone, so the
        // sender's sequence is recorded alongside the idempotency key rather
        // than looked up from the message.
        if (!$db->fieldExists('sent_seq', 'idempotency_keys')) {
            $forge->addColumn('idempotency_keys', [
                'sent_seq' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => true,
                ],
            ]);
        }

        // --- backfill -----------------------------------------------------
        // Number the surviving rows per party in insertion order, so existing
        // inboxes keep a sane cursor instead of starting from null.
        $this->backfill('recipient_id', 'recipient_seq');
        $this->backfill('sender_id', 'sender_seq');

        // Advance each identity's counters past anything already numbered.
        $db->query(
            'UPDATE identities i SET i.next_recv_seq = GREATEST(
                 i.next_recv_seq,
                 COALESCE((SELECT MAX(m.recipient_seq) + 1 FROM messages m
                           WHERE m.recipient_id = i.external_id), 1))'
        );
        $db->query(
            'UPDATE identities i SET i.next_sent_seq = GREATEST(
                 i.next_sent_seq,
                 COALESCE((SELECT MAX(m.sender_seq) + 1 FROM messages m
                           WHERE m.sender_id = i.external_id), 1))'
        );

        // --- keys ---------------------------------------------------------
        // One number per inbox. This is what makes an ACK addressable by
        // sequence alone, scoped to the caller, with no way to name another
        // identity's message.
        if (!$this->indexExists('messages', 'uniq_messages_recipient_seq')) {
            $db->query('ALTER TABLE messages
                        ADD UNIQUE KEY uniq_messages_recipient_seq (recipient_id, recipient_seq)');
        }

        // The inbox cursor now ranges over recipient_seq, so the old
        // (recipient_id, api_version, id) index can no longer serve it and
        // every poll would degrade to a filesort over the whole backlog.
        if (!$this->indexExists('messages', 'idx_messages_inbox_seq')) {
            $db->query('ALTER TABLE messages
                        ADD KEY idx_messages_inbox_seq (recipient_id, api_version, recipient_seq)');
        }
    }

    /**
     * Number existing rows 1..n per party, in insertion order.
     */
    private function backfill(string $partyColumn, string $seqColumn): void
    {
        $db = $this->db;

        $parties = $db->query(
            "SELECT DISTINCT {$partyColumn} AS party FROM messages WHERE {$seqColumn} IS NULL"
        )->getResultArray();

        foreach ($parties as $row) {
            $party = $row['party'];
            if ($party === null || $party === '') {
                continue;
            }

            $rows = $db->query(
                "SELECT id FROM messages WHERE {$partyColumn} = ? ORDER BY id ASC",
                [$party]
            )->getResultArray();

            $seq = 1;
            foreach ($rows as $message) {
                $db->query(
                    "UPDATE messages SET {$seqColumn} = ? WHERE id = ?",
                    [$seq, (int) $message['id']]
                );
                $seq++;
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach ($this->db->getIndexData($table) as $existing) {
            if ($existing->name === $index) {
                return true;
            }
        }
        return false;
    }

    public function down()
    {
        $db = $this->db;

        if ($this->indexExists('messages', 'uniq_messages_recipient_seq')) {
            $db->query('ALTER TABLE messages DROP INDEX uniq_messages_recipient_seq');
        }
        if ($this->indexExists('messages', 'idx_messages_inbox_seq')) {
            $db->query('ALTER TABLE messages DROP INDEX idx_messages_inbox_seq');
        }

        foreach (['recipient_seq', 'sender_seq'] as $column) {
            if ($db->fieldExists($column, 'messages')) {
                $this->forge->dropColumn('messages', $column);
            }
        }
        if ($db->fieldExists('sent_seq', 'idempotency_keys')) {
            $this->forge->dropColumn('idempotency_keys', 'sent_seq');
        }
        foreach (['next_recv_seq', 'next_sent_seq'] as $column) {
            if ($db->fieldExists($column, 'identities')) {
                $this->forge->dropColumn('identities', $column);
            }
        }
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Covering index for the PER-SENDER share of a recipient's inbox quota.
 *
 * `MAX_PENDING_MESSAGES` is a per-recipient resource with no per-sender
 * fairness, so **any authenticated identity could fill any recipient's inbox
 * and make every other sender see 507.** No crypto trick, no special
 * position, 2000 perfectly valid messages.
 *
 * An auditor dissolved the framing that had blocked this. The problem looked
 * like "the relay cannot detect undecryptable mail" -- which is true, and it
 * must not. But undecryptability only made the symptom permanent; it was never
 * the vulnerability. The mitigation needs no plaintext at all, because it is
 * pure accounting: cap one sender's share.
 *
 * `idx_messages_quota` cannot serve it -- sender_id is not in it, so the probe
 * would fall off the index and read blob pages on every send, which is exactly
 * what `quotaRefusal()` is documented never to do. Hence this index, with
 * sender_id before byte_len so both the count and the sum come from the index.
 *
 * Broadcast is unaffected: fan-out is N *different* recipients with one
 * message each, so no per-sender slice is approached.
 */
class AddPerSenderQuotaIndex extends Migration
{
    private const INDEX_NAME = 'idx_messages_sender_quota';
    private const COLUMNS    = 'recipient_id, api_version, sender_id, byte_len';

    public function up()
    {
        if (! $this->indexExists('messages', self::INDEX_NAME)) {
            $this->db->query(
                'ALTER TABLE messages ADD KEY ' . self::INDEX_NAME
                . ' (' . self::COLUMNS . ')'
            );
        }
    }

    public function down()
    {
        if ($this->indexExists('messages', self::INDEX_NAME)) {
            $this->db->query('ALTER TABLE messages DROP INDEX ' . self::INDEX_NAME);
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
}

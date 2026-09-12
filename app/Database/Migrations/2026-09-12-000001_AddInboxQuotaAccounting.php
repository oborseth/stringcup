<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Record each message's size so a pending-inbox quota can be enforced cheaply.
 *
 * Nothing ages messages out — only an ACK deletes one, and that is a
 * load-bearing guarantee rather than an oversight: it is what makes delivery
 * at-least-once and crash-safe. So the inbox has to be bounded some other way,
 * and the options were an expiry or a quota. An expiry silently deletes mail
 * the sender was told had been stored, so the quota won it: over the limit the
 * *send* is refused, loudly, at the moment someone can still do something
 * about it. "Persists until acknowledged" stays literally true.
 *
 * The quota needs the pending byte total for one recipient on every send.
 * `SUM(LENGTH(ciphertext))` cannot provide it at that rate: `ciphertext` is a
 * LONGBLOB, InnoDB stores anything over ~768 bytes off-page, and LENGTH() has
 * to fetch those pages — so the check would read the recipient's entire
 * backlog to decide whether to accept one message. `byte_len` is a plain
 * integer in the index instead, which makes the check covering.
 *
 * It is derived from the row rather than kept as a running counter on
 * `identities` deliberately: a counter drifts the moment anything deletes a
 * message outside the ACK path (`db:prune`, a manual fix, a future sweep), and
 * a quota that has silently drifted either rejects valid sends or stops
 * bounding anything.
 */
class AddInboxQuotaAccounting extends Migration
{
    public function up()
    {
        $db    = $this->db;
        $forge = $this->forge;

        if (!$db->fieldExists('byte_len', 'messages')) {
            $forge->addColumn('messages', [
                'byte_len' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
            ]);
        }

        // Backfill. Reads blobs once, here, rather than on every send forever.
        $db->query('UPDATE messages SET byte_len = LENGTH(ciphertext) WHERE byte_len IS NULL');

        // Covering index for the quota probe: the recipient and version narrow
        // the rows, and byte_len is read straight from the index, so deciding
        // on a send never touches a blob page.
        if (!$this->indexExists('messages', 'idx_messages_quota')) {
            $db->query('ALTER TABLE messages
                        ADD KEY idx_messages_quota (recipient_id, api_version, byte_len)');
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
        if ($this->indexExists('messages', 'idx_messages_quota')) {
            $this->db->query('ALTER TABLE messages DROP INDEX idx_messages_quota');
        }
        if ($this->db->fieldExists('byte_len', 'messages')) {
            $this->forge->dropColumn('messages', 'byte_len');
        }
    }
}

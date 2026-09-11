<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Composite index supporting the v2 paginated inbox query:
 *
 *   WHERE recipient_id = ? AND api_version = 2 AND id > ?  ORDER BY id ASC  LIMIT ?
 *
 * The pre-existing (recipient_id, created_at) index cannot serve the id-range
 * scan, so without this the inbox degrades to a filesort over the recipient's
 * whole backlog on every poll.
 */
class AddInboxIndexToMessages extends Migration
{
    private const INDEX_NAME = 'idx_messages_inbox';

    public function up()
    {
        if ($this->indexExists()) {
            return;
        }

        $this->db->query(sprintf(
            'CREATE INDEX %s ON %s (recipient_id, api_version, id)',
            self::INDEX_NAME,
            $this->db->protectIdentifiers('messages', true, false, false)
        ));
    }

    public function down()
    {
        if (! $this->indexExists()) {
            return;
        }

        // DROP INDEX syntax diverges: MySQL needs the table, SQLite forbids it.
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('DROP INDEX ' . self::INDEX_NAME);
        } else {
            $this->db->query('DROP INDEX ' . self::INDEX_NAME . ' ON messages');
        }
    }

    private function indexExists(): bool
    {
        foreach ($this->db->getIndexData('messages') as $index) {
            if ($index->name === self::INDEX_NAME) {
                return true;
            }
        }

        return false;
    }
}

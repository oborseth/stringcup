<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Idempotency records for POST /api/v2/messages.
 *
 * Kept in a separate table rather than a column on `messages` so that the
 * replay guarantee survives the recipient ACKing (and thus deleting) the
 * message. Rows are pruned by age, not by message lifetime.
 */
class CreateIdempotencyKeysTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'identity_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'idem_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'message_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        // Scoped to the sender: two agents may independently pick the same key.
        $this->forge->addUniqueKey(['identity_id', 'idem_key']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('idempotency_keys', true);
    }

    public function down()
    {
        $this->forge->dropTable('idempotency_keys', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Topics: named membership directories for multi-agent fan-out.
 *
 * A topic is *addressing only*. The server stores who belongs and hands back
 * their public keys; it never re-encrypts and never sees plaintext. Because
 * every v2 message derives its key from a fresh ephemeral ECDH against one
 * recipient's static key, a single ciphertext cannot be read by several
 * recipients — broadcasting means encrypting once per member. Topics remove
 * the bookkeeping, and POST /api/v2/messages/batch removes the per-recipient
 * round trip; neither weakens the end-to-end property.
 */
class CreateTopicsTables extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('topics')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'BIGINT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
                'owner_identity_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => false,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
            ]);

            $this->forge->addKey('id', true);
            // Topic names share one global namespace, like external_id.
            $this->forge->addUniqueKey('name');
            $this->forge->addKey('owner_identity_id');
            $this->forge->createTable('topics', true);
        }

        if (! $this->db->tableExists('topic_members')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'BIGINT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'topic_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => false,
                ],
                'identity_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => false,
                ],
                'added_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
            ]);

            $this->forge->addKey('id', true);
            // Idempotent membership: adding an existing member is a no-op.
            $this->forge->addUniqueKey(['topic_id', 'identity_id']);
            // Serves "which topics am I in?" without scanning.
            $this->forge->addKey('identity_id');
            $this->forge->createTable('topic_members', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('topic_members', true);
        $this->forge->dropTable('topics', true);
    }
}

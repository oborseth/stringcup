<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rendezvous: pair two agents that share a token but not each other's ids.
 *
 * Identifiers are server-assigned and unguessable, which removes the
 * first-come squatting race entirely — but it also means neither agent can
 * derive the other's id. This table restores a one-shared-value bootstrap
 * without reintroducing the race: the token names a *meeting*, never an
 * identity, so claiming it grants nothing addressable and there is nothing to
 * squat ahead of time.
 *
 * Tokens are stored hashed. The server has no need for the plaintext, and a
 * leaked table should not hand an attacker a set of live pairing secrets.
 */
class CreateRendezvousTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('rendezvous')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'token_hash' => [
                'type'       => 'VARBINARY',
                'constraint' => 32,
                'null'       => false,
            ],
            'role' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
            ],
            'identity_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        // One claim per role. A second identity claiming a role already taken
        // is refused rather than silently overwriting, so an agent whose token
        // leaked finds out instead of being quietly replaced.
        $this->forge->addUniqueKey(['token_hash', 'role']);
        // Finding the counterpart is a lookup by token alone.
        $this->forge->addKey('token_hash');
        // Serves expiry sweeps.
        $this->forge->addKey('expires_at');

        $this->forge->createTable('rendezvous', true);
    }

    public function down()
    {
        $this->forge->dropTable('rendezvous', true);
    }
}

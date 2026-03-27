<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateApiTokensTable extends Migration
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
            'token_hash' => [
                'type'       => 'BINARY',
                'constraint' => 32,
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'last_used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'null'       => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['token_hash', 'is_active']);
        $this->forge->addKey(['expires_at', 'is_active']);
        $this->forge->addForeignKey('identity_id', 'identities', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('api_tokens', true);
    }

    public function down()
    {
        $this->forge->dropTable('api_tokens', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateIdentitiesTable extends Migration
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
            'external_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'display_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'identity_pubkey' => [
                'type' => 'VARBINARY',
                'constraint' => 255,
                'null' => false,
            ],
            'algo' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('external_id');
        $this->forge->createTable('identities', true);
    }

    public function down()
    {
        $this->forge->dropTable('identities', true);
    }
}

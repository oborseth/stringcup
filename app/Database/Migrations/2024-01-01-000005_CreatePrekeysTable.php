<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePrekeysTable extends Migration
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
            'bundle_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'public_key' => [
                'type' => 'BLOB',
                'null' => false,
            ],
            'is_used' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['bundle_id', 'is_used']);
        $this->forge->addForeignKey('bundle_id', 'prekey_bundles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('prekeys', true);
    }

    public function down()
    {
        $this->forge->dropTable('prekeys', true);
    }
}

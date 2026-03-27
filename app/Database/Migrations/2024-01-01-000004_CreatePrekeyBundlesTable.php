<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePrekeyBundlesTable extends Migration
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
            'bundle_uuid' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'null'       => false,
            ],
            'signed_prekey' => [
                'type' => 'BLOB',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('bundle_uuid');
        $this->forge->addForeignKey('identity_id', 'identities', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('prekey_bundles', true);
    }

    public function down()
    {
        $this->forge->dropTable('prekey_bundles', true);
    }
}

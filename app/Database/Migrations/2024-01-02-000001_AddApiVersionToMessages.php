<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddApiVersionToMessages extends Migration
{
    public function up()
    {
        $this->forge->addColumn('messages', [
            'api_version' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 1,
                'after'      => 'created_at',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('messages', 'api_version');
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Track when an identity's public key last changed.
 *
 * `updated_at` moves for any edit (a display_name tweak, for instance), so it
 * cannot tell a peer whether the key it pinned is still the key in force.
 * A dedicated column makes key rotation detectable: a client that cached a
 * fingerprint can see that the key changed and refuse to send until the new
 * one is re-verified out of band.
 *
 * Backfilled from `updated_at` — the best available approximation for rows
 * that predate the column.
 */
class AddKeyUpdatedAtToIdentities extends Migration
{
    public function up()
    {
        if ($this->columnExists()) {
            return;
        }

        $this->forge->addColumn('identities', [
            'key_updated_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'algo',
            ],
        ]);

        $this->db->query(
            'UPDATE identities SET key_updated_at = updated_at WHERE key_updated_at IS NULL'
        );
    }

    public function down()
    {
        if ($this->columnExists()) {
            $this->forge->dropColumn('identities', 'key_updated_at');
        }
    }

    private function columnExists(): bool
    {
        return in_array('key_updated_at', $this->db->getFieldNames('identities'), true);
    }
}

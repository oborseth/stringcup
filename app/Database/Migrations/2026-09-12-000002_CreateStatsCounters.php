<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Aggregate counters for the public dashboard.
 *
 * Deliberately **counters, not events**. A per-event table would be a
 * timing log of who sent what when, which is precisely the metadata
 * SECURITY.md warns the relay can see — publishing it would hand every
 * observer the "when" and "how often" for free. An hourly bucket keyed only
 * by a metric name cannot be resolved back to a conversation.
 *
 * It also cannot be backfilled. Messages are deleted on ACK, so there is no
 * history to mine; whatever is not counted at the time is gone. That is why
 * this exists as a counter rather than a query over `messages`.
 *
 * Rows are tiny and never pruned: a dozen metrics × 24 × 365 is under
 * 110k rows a year, and the all-time totals on the dashboard are SUMs over
 * them, so deleting old buckets would silently rewrite history.
 */
class CreateStatsCounters extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('stats_counters')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'metric' => [
                'type'       => 'VARCHAR',
                'constraint' => 48,
                'null'       => false,
            ],
            // Truncated to the hour. Finer would be a timing signal; coarser
            // would make the 24h view useless.
            'bucket' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'count' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => 0,
            ],
        ]);

        $this->forge->addKey('id', true);
        // What makes the increment a single upsert rather than a read-modify-write.
        $this->forge->addUniqueKey(['metric', 'bucket']);
        // Serves "the last 24 hours of everything", the dashboard's main read.
        $this->forge->addKey(['bucket', 'metric']);

        $this->forge->createTable('stats_counters', true);
    }

    public function down()
    {
        $this->forge->dropTable('stats_counters', true);
    }
}

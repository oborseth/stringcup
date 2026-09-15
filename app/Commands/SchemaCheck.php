<?php

namespace App\Commands;

use App\Database\Migrations\ReconcileProductionSchema;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Detect drift between the live database and the schema the migrations define.
 *
 * The live schema had silently diverged from the migrations once already —
 * hand-run ALTERs that no migration recorded, which meant a fresh deployment
 * would have come up with a narrower schema than production. This makes that
 * class of drift visible instead of latent.
 *
 *   php spark schema:check
 */
class SchemaCheck extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'schema:check';
    protected $description = 'Compare the live schema against the expected column definitions.';

    /**
     * Expected types beyond those the reconcile migration enforces.
     * Keyed table => column => COLUMN_TYPE.
     */
    private const ALSO_EXPECTED = [
        'messages' => [
            'header_json' => 'text',
            'api_version' => 'tinyint(1) unsigned',
            // Per-party message numbering. Losing these drops the public
            // identifier back onto the global primary key, which reopens both
            // the volume leak and the ACK enumeration oracle.
            'recipient_seq' => 'bigint(20) unsigned',
            'sender_seq'    => 'bigint(20) unsigned',
            // Feeds the pending-inbox quota. Without it the check falls back
            // to SUM(LENGTH(ciphertext)), which reads every blob page in the
            // recipient's backlog on every send.
            'byte_len'      => 'int(10) unsigned',
        ],
        'identities' => [
            'external_id'  => 'varchar(64)',
            'display_name' => 'varchar(255)',
            // The counters those sequences are drawn from.
            'next_recv_seq' => 'bigint(20) unsigned',
            'next_sent_seq' => 'bigint(20) unsigned',
        ],
        'api_tokens' => [
            'is_active' => 'tinyint(1)',
        ],
        'stats_counters' => [
            // Backs the public dashboard. Aggregate only — hourly buckets keyed
            // by metric name, never per-event rows.
            'metric' => 'varchar(48)',
            'count'  => 'bigint(20) unsigned',
        ],
        'idempotency_keys' => [
            'idem_key' => 'varchar(255)',
            // Echoed by an idempotent replay, so it must outlive the message.
            'sent_seq' => 'bigint(20) unsigned',
        ],
    ];

    /** Indexes that must exist, keyed table => index => columns. */
    private const EXPECTED_INDEXES = [
        'messages' => [
            'idx_messages_inbox' => 'recipient_id,api_version,id',
            // Serves the inbox cursor, which ranges over recipient_seq. The
            // older id-based index cannot satisfy it, so losing this one
            // silently degrades every poll to a filesort over the whole
            // backlog.
            'idx_messages_inbox_seq' => 'recipient_id,api_version,recipient_seq',
            // One number per inbox. This is what makes an ACK addressable by
            // sequence alone and keeps a claim from colliding.
            'uniq_messages_recipient_seq' => 'recipient_id,recipient_seq',
            // Makes the quota probe covering — byte_len is read from the
            // index, so a send never touches a blob page.
            'idx_messages_quota' => 'recipient_id,api_version,byte_len',
            // Makes the PER-SENDER quota probe covering too. sender_id must
            // precede byte_len or the probe falls off the index and reads
            // blob pages on every send.
            'idx_messages_sender_quota' => 'recipient_id,api_version,sender_id,byte_len',
        ],
        'idempotency_keys' => [
            'identity_id_idem_key' => 'identity_id,idem_key',
        ],
        'stats_counters' => [
            // What makes an increment a single upsert instead of a
            // read-modify-write, and therefore safe under concurrency.
            'metric_bucket' => 'metric,bucket',
        ],
    ];

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        if (! in_array($db->DBDriver, ['MySQLi', 'mysqli', 'MySQL'], true)) {
            CLI::write('schema:check only supports MySQL; current driver is ' . $db->DBDriver, 'yellow');
            return 0;
        }

        $expected = $this->expectedColumns();
        $problems = [];

        foreach ($expected as $table => $columns) {
            if (! $db->tableExists($table)) {
                $problems[] = "missing table: {$table}";
                continue;
            }

            $live = [];
            foreach (
                $db->query(
                    'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$table]
                )->getResultArray() as $row
            ) {
                $live[$row['COLUMN_NAME']] = $row['COLUMN_TYPE'];
            }

            foreach ($columns as $column => $type) {
                if (! isset($live[$column])) {
                    $problems[] = "{$table}.{$column}: missing (expected {$type})";
                    continue;
                }

                if ($live[$column] !== $type) {
                    $problems[] = "{$table}.{$column}: is {$live[$column]}, expected {$type}";
                }
            }
        }

        foreach (self::EXPECTED_INDEXES as $table => $indexes) {
            if (! $db->tableExists($table)) {
                continue;
            }

            $liveIndexes = [];
            foreach ($db->getIndexData($table) as $index) {
                $liveIndexes[$index->name] = implode(',', $index->fields);
            }

            foreach ($indexes as $name => $fields) {
                if (! isset($liveIndexes[$name])) {
                    $problems[] = "{$table}: missing index {$name} ({$fields})";
                } elseif ($liveIndexes[$name] !== $fields) {
                    $problems[] = "{$table}.{$name}: covers {$liveIndexes[$name]}, expected {$fields}";
                }
            }
        }

        $checked = array_sum(array_map('count', $expected));

        if ($problems === []) {
            CLI::write(sprintf('OK — %d columns and %d index groups match.',
                $checked, count(self::EXPECTED_INDEXES)), 'green');
            return 0;
        }

        CLI::write(sprintf('DRIFT — %d problem(s):', count($problems)), 'red');
        foreach ($problems as $problem) {
            CLI::write('  - ' . $problem, 'yellow');
        }
        CLI::newLine();
        CLI::write('Run `php spark migrate` to apply the reconcile migration,', 'white');
        CLI::write('or update the expected definitions if the change is intentional.', 'white');

        return 1;
    }

    /**
     * Merge the reconcile migration's definitions with the extras above, so
     * there is a single source of truth for the types it enforces.
     */
    private function expectedColumns(): array
    {
        // Migration filenames are timestamp-prefixed, so the class is not
        // PSR-4 autoloadable; load it by path before reflecting on it.
        if (! class_exists(ReconcileProductionSchema::class, false)) {
            $matches = glob(APPPATH . 'Database/Migrations/*_ReconcileProductionSchema.php');
            if ($matches === false || $matches === []) {
                throw new \RuntimeException('Could not locate the ReconcileProductionSchema migration.');
            }
            require_once $matches[0];
        }

        $reflection = new \ReflectionClass(ReconcileProductionSchema::class);
        $columns    = $reflection->getConstant('COLUMNS');

        $expected = [];
        foreach ($columns as $table => $cols) {
            foreach ($cols as $column => [$type, $_ddl]) {
                $expected[$table][$column] = $type;
            }
        }

        foreach (self::ALSO_EXPECTED as $table => $cols) {
            foreach ($cols as $column => $type) {
                $expected[$table][$column] = $type;
            }
        }

        return $expected;
    }
}

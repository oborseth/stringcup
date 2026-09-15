<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Report the legacy (human-named) topic set and its size.
 *
 * Exists because "the frozen set is small" is a claim that must be a NUMBER,
 * not an argument. An auditor's point: grandfathering legacy names is
 * acceptable in proportion to how many there are, so the size has to be
 * queryable rather than asserted. After the freeze this is exactly
 * `WHERE name IS NOT NULL`, and it is monotonically non-increasing.
 */
class TopicAudit extends BaseCommand
{
    protected $group       = 'Stringcup';
    protected $name        = 'topics:audit';
    protected $description = 'Count and list human-named (legacy-addressable) topics.';

    public function run(array $params)
    {
        $db = Database::connect();
        $total = (int) $db->query('SELECT COUNT(*) AS t FROM topics')->getRow()->t;

        $rows = $db->query('SELECT name FROM topics ORDER BY id')->getResultArray();
        $test = 0;
        $other = [];
        foreach ($rows as $r) {
            $n = (string) ($r['name'] ?? '');
            if ($n === '') { continue; }
            if (preg_match('/^(live-mcp-|p11-topic-|wire-|topic-|test)/', $n)) { $test++; }
            else { $other[] = $n; }
        }

        CLI::write("total topics: {$total}");
        CLI::write('recognisable test artefacts: ' . $test);
        CLI::write('other names (' . count($other) . '):');
        foreach ($other as $n) { CLI::write('  ' . $n); }

        return EXIT_SUCCESS;
    }
}

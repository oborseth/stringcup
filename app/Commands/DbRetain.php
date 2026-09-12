<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\RetentionSweeper;

/**
 * Reclaim storage that can no longer be reached by anyone.
 *
 * Distinct from `db:prune`, and the distinction matters: `db:prune` is a
 * one-off development cleanup whose `--all` mode deletes every identity, so it
 * must never be scheduled. This command is safe to run on a live service on a
 * timer, because every rule it applies removes only data that is already
 * unreachable.
 *
 *   php spark db:retain            # dry run
 *   php spark db:retain --force    # delete
 *
 * Nothing here expires a deliverable message. An inbox is bounded at the other
 * end instead, by the pending-inbox quota in MessageController, so
 * "persists until acknowledged" stays literally true. See RetentionSweeper.
 */
class DbRetain extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:retain';
    protected $description = 'Reclaim unreachable data. Safe to schedule. Dry run unless --force.';
    protected $usage       = 'db:retain [--force]';

    public function run(array $params)
    {
        $force   = (bool) CLI::getOption('force');
        $sweeper = new RetentionSweeper();

        CLI::write(
            $force ? 'Reclaiming:' : 'DRY RUN — nothing will be deleted. Re-run with --force.',
            $force ? 'yellow' : 'green'
        );
        CLI::newLine();

        $total = 0;
        foreach ($sweeper->run($force) as $label => $count) {
            CLI::write(sprintf('  %-56s %6d %s', $label, $count, $force ? 'deleted' : 'would go'));
            $total += $count;
        }

        CLI::newLine();
        CLI::write(
            $force ? "Reclaimed {$total} rows." : "Would reclaim {$total} rows.",
            'green'
        );

        if (!$force) {
            CLI::newLine();
            CLI::write('This runs automatically at most once an hour on a request path,', 'dark_gray');
            CLI::write('so scheduling it is optional. See RetentionSweeper.', 'dark_gray');
        }

        return 0;
    }
}

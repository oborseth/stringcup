<?php

namespace App\Commands;

use App\Controllers\Api\V2\MessageController;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use ReflectionClass;

/**
 * Assert that every limit the server ENFORCES is also PUBLISHED.
 *
 * `GET /api/v2` and `GET /api/v2/stats` both exist so a sender can tell a full
 * inbox from a permanent failure and can size its own behaviour. That only
 * works if the published figures are the ones actually enforced.
 *
 * THEY WERE NOT. The per-sender quota (`MAX_PENDING_PER_SENDER` = 200,
 * `MAX_PENDING_BYTES_PER_SENDER` = 16 MiB) shipped without touching
 * `StatsController::limits()`, so the dashboard endpoint published 2000 and
 * 64 MiB -- 10x and 4x higher than anything a sender could reach, with no
 * per-sender field at all. A client reading it for capacity planning, which
 * is that endpoint's documented purpose, was planning against numbers it
 * could never hit. Found on the live relay by an auditor looking specifically
 * for drift rather than for bugs.
 *
 * This is the mechanised form of a rule this project has now written after
 * the fact four times -- "the artefact and its description drifted". A
 * discipline that has to be remembered is not the reliable part; enumerate
 * the constants and fail on any that is not published.
 */
class LimitsCheck extends BaseCommand
{
    protected $group       = 'Stringcup';
    protected $name        = 'limits:check';
    protected $description = 'Assert every enforced MAX_* limit is published by /api/v2 and /stats.';

    /**
     * The published field each enforced constant must appear under.
     *
     * `GET /api/v2` is the client-facing contract, so EVERY enforced limit
     * must be here -- a limit a client cannot discover is one it plans around
     * wrongly.
     */
    private const PUBLISHED_AS = [
        'MAX_MESSAGE_BYTES'            => 'message_max_bytes',
        'MAX_PENDING_MESSAGES'         => 'inbox_max_pending_messages',
        'MAX_PENDING_BYTES'            => 'inbox_max_pending_bytes',
        'MAX_PENDING_PER_SENDER'       => 'inbox_max_pending_per_sender',
        'MAX_PENDING_BYTES_PER_SENDER' => 'inbox_max_pending_bytes_per_sender',
        'MAX_WAIT'                     => 'long_poll_max_seconds',
        'MAX_LIMIT'                    => 'inbox_page_max',
        'MAX_BATCH'                    => 'batch_max_messages',
    ];

    /**
     * The subset `GET /api/v2/stats` must ALSO publish, because the dashboard
     * documents itself as being for capacity planning.
     *
     * THE FOUR PENDING-MAIL LIMITS TRAVEL TOGETHER. That is the whole point:
     * stats published the two whole-inbox ceilings and omitted the two
     * per-sender ones, so it advertised 2000 and 64 MiB while a sender was
     * refused at 200 and 16 MiB. Publishing half of a group is worse than
     * publishing none of it -- the reader has no way to know a lower ceiling
     * exists, so the figures look authoritative and are unreachable.
     *
     * `MAX_BATCH` is deliberately absent: fan-out size is a request-shape
     * limit rather than a capacity one, and it is on the index where a client
     * building a batch looks.
     */
    private const REQUIRED_IN_STATS = [
        'MAX_MESSAGE_BYTES',
        'MAX_PENDING_MESSAGES',
        'MAX_PENDING_BYTES',
        'MAX_PENDING_PER_SENDER',
        'MAX_PENDING_BYTES_PER_SENDER',
        'MAX_WAIT',
        'MAX_LIMIT',
    ];

    public function run(array $params)
    {
        $reflection = new ReflectionClass(MessageController::class);
        $constants  = $reflection->getConstants();

        $enforced = [];
        foreach ($constants as $name => $value) {
            if (strpos($name, 'MAX_') === 0) {
                $enforced[$name] = $value;
            }
        }

        if ($enforced === []) {
            CLI::error('No MAX_* constants found on MessageController -- has it been renamed?');

            return EXIT_ERROR;
        }

        // Read the two publishing surfaces as SOURCE, not by calling them:
        // StatsController::limits() is private, and hitting the live endpoint
        // would make this command depend on a running server.
        $indexPath = APPPATH . 'Controllers/Api/V2/IndexController.php';
        $statsPath = APPPATH . 'Controllers/Api/V2/StatsController.php';

        $failures = 0;
        $checks   = 0;

        foreach ($enforced as $name => $value) {
            if (! isset(self::PUBLISHED_AS[$name])) {
                CLI::error(sprintf(
                    'MessageController::%s (%s) is enforced but this command does not '
                        . 'know what it should be published as. Add it to PUBLISHED_AS -- '
                        . 'adding an enforced limit is meant to force that decision.',
                    $name,
                    $value
                ));
                $failures++;
                continue;
            }

            $field = self::PUBLISHED_AS[$name];

            $sources = ['IndexController' => $indexPath];
            if (in_array($name, self::REQUIRED_IN_STATS, true)) {
                $sources['StatsController'] = $statsPath;
            }

            foreach ($sources as $label => $path) {
                $checks++;
                $source = @file_get_contents($path);
                if ($source === false) {
                    CLI::error("Cannot read {$path}");
                    $failures++;
                    continue;
                }

                // Both the published KEY and the constant feeding it must be
                // present: a hardcoded literal would satisfy the key alone and
                // is exactly the drift being guarded against.
                $hasField    = strpos($source, "'" . $field . "'") !== false;
                $hasConstant = strpos($source, '::' . $name) !== false;

                if ($hasField && $hasConstant) {
                    CLI::write(sprintf('  OK   %-30s -> %s (%s)', $name, $field, $label), 'green');
                    continue;
                }

                if (! $hasField) {
                    CLI::error(sprintf(
                        '  FAIL %s does not publish %s (for MessageController::%s = %s)',
                        $label,
                        $field,
                        $name,
                        $value
                    ));
                } else {
                    CLI::error(sprintf(
                        '  FAIL %s publishes %s but not from MessageController::%s -- '
                            . 'a hardcoded value drifts the moment the constant moves',
                        $label,
                        $field,
                        $name
                    ));
                }
                $failures++;
            }
        }

        CLI::newLine();
        CLI::write(sprintf(
            'checked %d enforced limit(s), %d limit/surface pair(s)',
            count($enforced),
            $checks
        ));

        if ($failures > 0) {
            CLI::error(sprintf('%d problem(s). An enforced limit nobody publishes is a limit '
                . 'clients plan around wrongly.', $failures));

            return EXIT_ERROR;
        }

        CLI::write('OK -- every enforced limit is published where it must be.', 'green');

        return EXIT_SUCCESS;
    }
}

<?php

namespace App\Commands;

use App\Filters\RateLimitFilter;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Filters as FiltersConfig;

/**
 * Assert that the rate limiter's IP-only list agrees with the auth filter.
 *
 * `RateLimitFilter::IP_ONLY_BUCKETS` exists because an endpoint AuthFilter
 * does not protect has nothing downstream to reject a forged credential, so a
 * caller-supplied bearer string must never be allowed to name its bucket --
 * otherwise a fresh random token mints a fresh counter and the limit does not
 * exist.
 *
 * That list and `Config\Filters::$filters['auth']` are two hand-maintained
 * lists, in different files, that MUST agree. They disagreed:
 * `api/v2/identities_put` was missing, so `PUT /api/v2/identities` -- which
 * AuthFilter deliberately does not cover, because the path is shared with
 * unauthenticated registration -- had an unenforceable 30/hour limit.
 * Measured live: three requests with fresh junk tokens all reported 29
 * remaining.
 *
 * An auditor found it, and made the more useful point: the previous fix had
 * been applied to the instances that were named rather than to the class that
 * was described. This command is the class. It would have failed on that
 * commit, and it fails the next time an unauthenticated endpoint is added.
 */
class FiltersCheck extends BaseCommand
{
    protected $group       = 'Housekeeping';
    protected $name        = 'filters:check';
    protected $description = 'Verify every unauthenticated rate-limit bucket keys on IP.';

    public function run(array $params)
    {
        $limiter  = new RateLimitFilter();
        $reflect  = new \ReflectionClass($limiter);

        $limits = $reflect->getProperty('limits');
        $limits->setAccessible(true);
        $buckets = array_keys($limits->getValue($limiter));

        $ipOnly = $reflect->getConstant('IP_ONLY_BUCKETS');

        $authPaths = (new FiltersConfig())->filters['auth']['before'] ?? [];

        $problems = [];

        foreach ($buckets as $bucket) {
            if ($bucket === 'default') {
                continue;
            }

            // Buckets are "<path>_<method>"; recover the path.
            $path = preg_replace('/_[a-z]+$/', '', $bucket);

            $covered = false;
            foreach ($authPaths as $pattern) {
                $regex = '#^' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '$#';
                if (preg_match($regex, $path)) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered && !isset($ipOnly[$bucket])) {
                $problems[] = $bucket;
            }
        }

        CLI::write(sprintf(
            'checked %d buckets against %d auth patterns',
            count($buckets),
            count($authPaths)
        ));

        if ($problems !== []) {
            CLI::error('Buckets NOT covered by AuthFilter and NOT in IP_ONLY_BUCKETS:');
            foreach ($problems as $bucket) {
                CLI::error('  ' . $bucket);
            }
            CLI::write(
                'A caller-supplied bearer string can name its own bucket on these, '
                . 'so the limit is unenforceable. Add them to IP_ONLY_BUCKETS.',
                'yellow'
            );
            exit(1);
        }

        CLI::write('OK — every unauthenticated bucket keys on IP.', 'green');
    }
}

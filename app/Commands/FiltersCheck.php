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

    /** Methods a bucket key may name. A key ending in anything else is a typo. */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

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

            // Split on the LAST underscore: a bucket key is "<path>_<method>",
            // and the path itself may contain underscores.
            //
            // The first version of this matched the whole bucket KEY against
            // the auth PATH globs, which are different namespaces --
            // "api/v2/messages_post" is not a path. It lined up only because
            // every key happens to be <path>_<method> and every auth pattern
            // happens to end in "*", so prefix matching coincided. An auditor
            // called it "correct by coincidence", and named the failure that
            // matters: an auth pattern WITHOUT a trailing "*" leaves its
            // buckets unmatched, the check demands they be added to
            // IP_ONLY_BUCKETS, and a human complies -- moving an
            // AUTH-COVERED endpoint onto the IP-only list. A test that pushes
            // someone toward the unsafe edit is worse than no test.
            $cut = strrpos($bucket, '_');
            if ($cut === false) {
                $problems[] = $bucket . ' (malformed bucket key: no method suffix)';
                continue;
            }

            $path   = substr($bucket, 0, $cut);
            $method = strtoupper(substr($bucket, $cut + 1));

            if (!in_array($method, self::METHODS, true)) {
                $problems[] = $bucket . ' (unrecognised method "' . $method . '")';
                continue;
            }

            $covered = false;
            foreach ($authPaths as $pattern) {
                // preg_quote FIRST, then re-expand the glob. The previous
                // translation replaced only "*" and "/", so an unescaped "."
                // in a pattern became a wildcard. Nothing contains one today.
                $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $path)) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered && !isset($ipOnly[$bucket])) {
                $problems[] = $bucket . ' (path "' . $path . '", method ' . $method . ')';
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

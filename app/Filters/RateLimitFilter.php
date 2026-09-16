<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class RateLimitFilter implements FilterInterface
{
    /**
     * Rate limits per endpoint pattern.
     * Format: [requests, window_seconds]
     */
    protected array $limits = [
        // 30, raised from 5 on 2026-09-16. WHAT THIS CAP ACTUALLY DEFENDS is
        // rate-limit budget, not storage: every registration mints a token,
        // and `getIdentifier()` buckets by token hash, so each identity brings
        // its own 100 sends/hour and 300 inbox reads/hour. Identities
        // themselves "are not what grows" -- one public key each, never
        // deleted.
        //
        // And it was never a bound on the TOTAL, only on the rate: 5/hour is
        // 120/day, unbounded over time. So the choice is which rate is
        // acceptable, and 5 was set against the stated product goal --
        // frictionless onboarding -- because a NAT'd fleet shares one bucket
        // and could not register 20 agents in under four hours.
        //
        // 30 clears that fleet inside an hour with headroom, matches the
        // sibling identities_put budget, and multiplies the anonymous
        // budget-minting rate by 6 rather than removing it. The per-identity
        // budgets and the long-poll slot cap are the limits that actually
        // bound consumption, and neither moved.
        'api/v2/identities_post'  => [30, 3600],
        'api/v2/identities_put'   => [30, 3600],   // key rotation / rename
        // 200, not 120: the reference client's own await_peer/join_rendezvous
        // loop polls every MAX_WAIT (25s), which is 144 calls/hour if a peer is
        // slow to arrive. A limit below the rate our own documented client
        // polls at meant a long pairing failed with a 429 that surfaced as
        // RateLimited rather than PairingTimeout — a confusing error for a
        // situation the client is designed to handle. Keep this above 144.
        'api/v2/rendezvous_post'  => [200, 3600],
        'api/v2/rendezvous_delete' => [60, 3600],
        'api/v2/identities_get'   => [100, 3600],
        'api/v2/messages_post'    => [100, 3600],
        'api/v2/messages_get'     => [300, 3600],
        'api/v2/messages_delete'  => [300, 3600],   // 300 per hour for single ACKs
        'api/v2/messages_ack_post' => [300, 3600],  // batch ACK: one call drains a page
        'api/v2/tokens_get'       => [60, 3600],    // token introspection
        'api/v2/tokens_post'      => [10, 3600],    // rotation should be rare
        // One batch replaces N single sends, so it draws on the same
        // per-message budget as POST /messages rather than a cheaper one.
        'api/v2/messages_batch_post' => [100, 3600],
        'api/v2/topics_get'       => [200, 3600],   // roster reads before each broadcast
        'api/v2/topics_post'      => [60, 3600],    // create + membership changes
        'api/v2/topics_delete'    => [60, 3600],
        // The dashboard polls this and it is unauthenticated, so the bucket is
        // per-IP. Generous, because the response is cached for 30s server-side
        // and a shared NAT would otherwise make the page look broken.
        'api/v2/stats_get'        => [600, 3600],
        'default'                 => [60, 60],      // 60 per minute default
    ];

    protected string $cacheDir = WRITEPATH . 'cache/ratelimit/';

    /**
     * Budget state captured during before(), replayed as headers in after().
     *
     * CodeIgniter reuses one filter instance for both passes of a request
     * (Filters::createFilter caches by class name), so instance state is safe
     * here and avoids re-reading the counter file.
     *
     * @var array{limit:int, remaining:int, reset:int}|null
     */
    protected ?array $budget = null;

    /**
     * Check rate limits before processing request.
     *
     * @param RequestInterface $request
     * @param mixed|null       $arguments
     * @return RequestInterface|ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $uri = $request->getUri();
        $path = $this->normalizePath($uri->getPath());
        $method = $request->getMethod();

        // Determine rate limit key based on path and method
        $limitKey = $this->getLimitKey($path, $method);
        list($maxRequests, $windowSeconds) = $this->limits[$limitKey] ?? $this->limits['default'];

        // Get identifier (authenticated token or IP address)
        $identifier = $this->getIdentifier($request, $limitKey);

        // Read, decide and record under ONE lock. Splitting them let N
        // concurrent requests each read the same window, each conclude it was
        // under the limit, and each overwrite the others -- so a caller
        // issuing requests in parallel rather than serially exceeded any
        // limit by roughly its concurrency. The lock used to cover only the
        // write, which protected the file's integrity and none of the
        // arithmetic that mattered. Found by an external code audit.
        $claim = $this->claimWindow($identifier, $limitKey, $windowSeconds, $maxRequests);

        $this->budget = [
            'limit'     => $maxRequests,
            'remaining' => $claim['remaining'],
            'reset'     => $claim['reset'],
        ];

        if (!$claim['allowed']) {
            return $this->rateLimitedResponse($maxRequests, $windowSeconds, $claim['reset']);
        }

        return $request;
    }

    /**
     * Attach the caller's remaining budget so clients can pace themselves
     * instead of discovering the limit by tripping it.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param mixed|null        $arguments
     * @return ResponseInterface|void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if ($this->budget === null) {
            return;
        }

        $response->setHeader('X-RateLimit-Limit', (string) $this->budget['limit']);
        $response->setHeader('X-RateLimit-Remaining', (string) $this->budget['remaining']);
        $response->setHeader('X-RateLimit-Reset', (string) $this->budget['reset']);

        return $response;
    }

    /**
     * Normalize the request path to the bare route.
     *
     * Front-controller deployments (the nginx config in front of this app is
     * one) surface the path as 'index.php/api/v2/messages'. Leaving that
     * prefix in place made every endpoint pattern below miss, so every caller
     * silently landed in the permissive 'default' bucket instead of the
     * intended per-endpoint limit.
     *
     * @param string $path
     * @return string
     */
    protected function normalizePath(string $path): string
    {
        $path = trim($path, '/');

        return preg_replace('#^index\.php/#', '', $path) ?? $path;
    }

    /**
     * Get the limit key for the current request.
     *
     * @param string $path
     * @param string $method
     * @return string
     */
    protected function getLimitKey(string $path, string $method): string
    {
        $method = strtolower($method);

        // Match API endpoints
        if (preg_match('#^api/v2/identities$#', $path) && $method === 'post') {
            return 'api/v2/identities_post';
        }

        if (preg_match('#^api/v2/identities$#', $path) && $method === 'put') {
            return 'api/v2/identities_put';
        }

        if (preg_match('#^api/v2/rendezvous$#', $path)) {
            if ($method === 'post') {
                return 'api/v2/rendezvous_post';
            }
            if ($method === 'delete') {
                return 'api/v2/rendezvous_delete';
            }
        }

        if (preg_match('#^api/v2/identities/.+#', $path) && $method === 'get') {
            return 'api/v2/identities_get';
        }

        // Checked before the bare /messages rules so they are not shadowed.
        if (preg_match('#^api/v2/messages/ack$#', $path) && $method === 'post') {
            return 'api/v2/messages_ack_post';
        }

        if (preg_match('#^api/v2/messages/batch$#', $path) && $method === 'post') {
            return 'api/v2/messages_batch_post';
        }

        if (preg_match('#^api/v2/messages$#', $path) && $method === 'post') {
            return 'api/v2/messages_post';
        }

        if (preg_match('#^api/v2/messages$#', $path) && $method === 'get') {
            return 'api/v2/messages_get';
        }

        if (preg_match('#^api/v2/messages/\d+$#', $path) && $method === 'delete') {
            return 'api/v2/messages_delete';
        }

        if (preg_match('#^api/v2/tokens/.+#', $path) && $method === 'get') {
            return 'api/v2/tokens_get';
        }

        if (preg_match('#^api/v2/tokens/.+#', $path) && $method === 'post') {
            return 'api/v2/tokens_post';
        }

        if (preg_match('#^api/v2/topics(/.*)?$#', $path)) {
            if ($method === 'get') {
                return 'api/v2/topics_get';
            }
            if ($method === 'delete') {
                return 'api/v2/topics_delete';
            }
            if ($method === 'post') {
                return 'api/v2/topics_post';
            }
        }

        return 'default';
    }

    /**
     * Get unique identifier for rate limiting.
     *
     * This filter runs ahead of AuthFilter, so $request->identity is not yet
     * populated. Deriving the identifier from the bearer token directly keeps
     * each agent on its own budget; without it every agent behind a shared
     * egress IP would compete for one bucket.
     *
     * @param RequestInterface $request
     * @return string
     */
    /**
     * Which limit buckets must key on IP, never on a caller-supplied token.
     *
     * These are the endpoints AuthFilter does not protect, so nothing
     * downstream will ever reject a bogus credential on them. `default` is
     * included because an unrecognised path is more likely to be
     * unauthenticated than not, and guessing wrong in that direction is the
     * safe one.
     */
    protected const IP_ONLY_BUCKETS = [
        'api/v2/identities_post' => true,   // registration: cannot have a token
        // PUT shares the `api/v2/identities` path with registration, and
        // filters match by PATH not method, so AuthFilter cannot cover it --
        // `IdentityController::update()` resolves the token itself. That makes
        // it exactly the class this list is for, and it was MISSING: three
        // requests with fresh junk tokens each reported 29 remaining, so the
        // 30/hour limit did not exist. Found by an auditor; `filters:check`
        // now asserts the class rather than this row.
        'api/v2/identities_put'  => true,
        'api/v2/identities_get'  => true,   // public lookup
        'api/v2/stats_get'       => true,   // public dashboard
        'default'                => true,
    ];

    /**
     * The bucket this caller counts against.
     *
     * On an endpoint AuthFilter does not protect, this is the IP and only the
     * IP. It used to be any bearer string the caller supplied, unvalidated,
     * so `Authorization: Bearer <random>` minted a fresh counter per request
     * and the 5/hour registration cap became unlimited identity creation.
     *
     * The first fix resolved the token against the database. That worked but
     * put a query in front of the limit decision, so a request the limiter
     * was about to refuse still cost a lookup -- the limiter could no longer
     * shed load it had already decided to reject, and an attacker got that
     * query for free by attaching a header it did not need. Pointed out by a
     * re-audit.
     *
     * Keying on the endpoint class instead costs nothing and closes the same
     * hole: the three unauthenticated endpoints are exactly where a forged
     * token bought a free budget. On a protected endpoint an unresolvable
     * token still gets its own bucket, and that is harmless -- AuthFilter
     * answers 401, so the caller can do nothing with it, and the leftover
     * counter file is reclaimed by RetentionSweeper.
     */
    protected function getIdentifier(RequestInterface $request, string $limitKey = 'default'): string
    {
        // Set by AuthFilter on the rare paths where it has already run.
        if (isset($request->identity) && !empty($request->identity['external_id'])) {
            return 'user:' . $request->identity['external_id'];
        }

        if (!isset(self::IP_ONLY_BUCKETS[$limitKey])) {
            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader && stripos($authHeader, 'Bearer ') === 0) {
                $plainToken = trim(substr($authHeader, 7));
                if ($plainToken !== '') {
                    // Hashed so no credential material reaches the filename.
                    return 'token:' . substr(hash('sha256', $plainToken), 0, 32);
                }
            }
        }

        return 'ip:' . $request->getIPAddress();
    }

    /**
     * Read the request timestamps still inside the window.
     *
     * @return list<int>
     */
    /**
     * Claim one request against the window, atomically.
     *
     * Holds a single exclusive lock across the read, the decision and the
     * write, which is the only way the count can be trusted: the previous
     * split read/write meant concurrent callers each saw a stale window.
     * `LongPollGuard` already used this shape.
     *
     * A rejected request is deliberately not recorded, so being throttled
     * cannot extend the throttle.
     *
     * Fails OPEN. A limiter that cannot open its counter file must not take
     * the API down with it; the alternative is a full disk turning into a
     * total outage.
     *
     * @return array{allowed:bool, remaining:int, reset:int}
     */
    protected function claimWindow(
        string $identifier,
        string $limitKey,
        int $windowSeconds,
        int $maxRequests
    ): array {
        $cacheFile = $this->getCacheFile($identifier, $limitKey);

        // 'c+' creates without truncating, so the handle is usable for both
        // reading the existing window and rewriting it.
        $handle = @fopen($cacheFile, 'c+');
        if ($handle === false) {
            return ['allowed' => true, 'remaining' => $maxRequests - 1, 'reset' => time() + $windowSeconds];
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return ['allowed' => true, 'remaining' => $maxRequests - 1, 'reset' => time() + $windowSeconds];
            }

            $raw  = (string) stream_get_contents($handle);
            $data = json_decode($raw, true);

            $cutoff     = time() - $windowSeconds;
            $timestamps = [];
            if (is_array($data) && isset($data['requests']) && is_array($data['requests'])) {
                $timestamps = array_values(array_filter(
                    $data['requests'],
                    static fn ($timestamp) => is_int($timestamp) && $timestamp > $cutoff
                ));
            }

            // Reset is when the oldest in-window request ages out.
            $reset = $timestamps === []
                ? time() + $windowSeconds
                : min($timestamps) + $windowSeconds;

            if (count($timestamps) >= $maxRequests) {
                return ['allowed' => false, 'remaining' => 0, 'reset' => $reset];
            }

            $timestamps[] = time();

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(['requests' => $timestamps]));
            fflush($handle);

            return [
                'allowed'   => true,
                'remaining' => max(0, $maxRequests - count($timestamps)),
                'reset'     => min($timestamps) + $windowSeconds,
            ];
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Get cache file path for identifier and limit key.
     *
     * @param string $identifier
     * @param string $limitKey
     * @return string
     */
    protected function getCacheFile(string $identifier, string $limitKey): string
    {
        $hash = md5($identifier . ':' . $limitKey);
        return $this->cacheDir . $hash . '.json';
    }

    /**
     * Return a standardized 429 JSON response.
     *
     * @param int $maxRequests
     * @param int $windowSeconds
     * @param int $reset
     * @return ResponseInterface
     */
    protected function rateLimitedResponse(int $maxRequests, int $windowSeconds, int $reset): ResponseInterface
    {
        $retryAfter = max(1, $reset - time());

        $response = Services::response();
        $response->setStatusCode(429);
        // Seconds until a slot frees up, not the whole window: retrying at the
        // window length would idle far longer than necessary.
        $response->setHeader('Retry-After', (string) $retryAfter);
        $response->setHeader('X-RateLimit-Limit', (string) $maxRequests);
        $response->setHeader('X-RateLimit-Remaining', '0');
        $response->setHeader('X-RateLimit-Reset', (string) $reset);
        $response->setJSON([
            'error' => 'Rate limit exceeded',
            'messages' => [
                'error' => sprintf(
                    'Too many requests. Limit is %d requests per %d seconds. Please try again later.',
                    $maxRequests,
                    $windowSeconds
                )
            ],
            'limit' => $maxRequests,
            'window' => $windowSeconds,
            'retry_after' => $retryAfter,
        ]);
        return $response;
    }
}

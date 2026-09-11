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
        'api/v2/identities_post'  => [5, 3600],
        'api/v2/identities_put'   => [30, 3600],   // key rotation / rename
        'api/v2/rendezvous_post'  => [120, 3600],  // pairing, often long-polled
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
        $identifier = $this->getIdentifier($request);

        $timestamps = $this->readWindow($identifier, $limitKey, $windowSeconds);

        // Reset is when the oldest in-window request ages out.
        $reset = $timestamps === []
            ? time() + $windowSeconds
            : min($timestamps) + $windowSeconds;

        if (count($timestamps) >= $maxRequests) {
            $this->budget = [
                'limit'     => $maxRequests,
                'remaining' => 0,
                'reset'     => $reset,
            ];

            return $this->rateLimitedResponse($maxRequests, $windowSeconds, $reset);
        }

        // Record this request
        $timestamps[] = time();
        $this->writeWindow($identifier, $limitKey, $timestamps);

        $this->budget = [
            'limit'     => $maxRequests,
            'remaining' => max(0, $maxRequests - count($timestamps)),
            'reset'     => min($timestamps) + $windowSeconds,
        ];
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
    protected function getIdentifier(RequestInterface $request): string
    {
        // Set by AuthFilter on the rare paths where it has already run.
        if (isset($request->identity) && !empty($request->identity['external_id'])) {
            return 'user:' . $request->identity['external_id'];
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader && stripos($authHeader, 'Bearer ') === 0) {
            $plainToken = trim(substr($authHeader, 7));
            if ($plainToken !== '') {
                // Hashed so no credential material reaches the cache filename.
                return 'token:' . substr(hash('sha256', $plainToken), 0, 32);
            }
        }

        // Unauthenticated endpoints (registration, lookup) fall back to IP.
        return 'ip:' . $request->getIPAddress();
    }

    /**
     * Read the request timestamps still inside the window.
     *
     * @return list<int>
     */
    protected function readWindow(string $identifier, string $limitKey, int $windowSeconds): array
    {
        $cacheFile = $this->getCacheFile($identifier, $limitKey);

        if (!file_exists($cacheFile)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($cacheFile), true);
        if (!$data || !isset($data['requests']) || !is_array($data['requests'])) {
            return [];
        }

        $cutoff = time() - $windowSeconds;

        return array_values(array_filter(
            $data['requests'],
            static fn ($timestamp) => is_int($timestamp) && $timestamp > $cutoff
        ));
    }

    /**
     * Persist the pruned window.
     *
     * @param list<int> $timestamps
     */
    protected function writeWindow(string $identifier, string $limitKey, array $timestamps): void
    {
        file_put_contents(
            $this->getCacheFile($identifier, $limitKey),
            json_encode(['requests' => array_values($timestamps)]),
            LOCK_EX
        );
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

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
        'api/v1/identities_post'  => [5, 3600],     // 5 per hour for registration
        'api/v1/identities_get'   => [100, 3600],   // 100 per hour for lookups
        'api/v1/messages_post'    => [100, 3600],   // 100 per hour for sending
        'api/v1/messages_get'     => [300, 3600],   // 300 per hour for retrieval
        'api/v2/identities_post'  => [5, 3600],
        'api/v2/identities_get'   => [100, 3600],
        'api/v2/messages_post'    => [100, 3600],
        'api/v2/messages_get'     => [300, 3600],
        'api/v2/messages_delete'  => [300, 3600],   // 300 per hour for ACKs
        'default'                 => [60, 60],      // 60 per minute default
    ];

    protected string $cacheDir = WRITEPATH . 'cache/ratelimit/';

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
        $path = trim($uri->getPath(), '/');
        $method = $request->getMethod();

        // Determine rate limit key based on path and method
        $limitKey = $this->getLimitKey($path, $method);
        list($maxRequests, $windowSeconds) = $this->limits[$limitKey] ?? $this->limits['default'];

        // Get identifier (IP address or authenticated user)
        $identifier = $this->getIdentifier($request);

        // Check if rate limit exceeded
        if ($this->isRateLimited($identifier, $limitKey, $maxRequests, $windowSeconds)) {
            return $this->rateLimitedResponse($maxRequests, $windowSeconds);
        }

        // Record this request
        $this->recordRequest($identifier, $limitKey);

        return $request;
    }

    /**
     * No action needed after controller execution.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param mixed|null        $arguments
     * @return ResponseInterface|void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No action needed
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
        if (preg_match('#^api/v1/identities$#', $path) && $method === 'post') {
            return 'api/v1/identities_post';
        }

        if (preg_match('#^api/v1/identities/.+#', $path) && $method === 'get') {
            return 'api/v1/identities_get';
        }

        if (preg_match('#^api/v1/messages$#', $path) && $method === 'post') {
            return 'api/v1/messages_post';
        }

        if (preg_match('#^api/v1/messages$#', $path) && $method === 'get') {
            return 'api/v1/messages_get';
        }

        if (preg_match('#^api/v2/identities$#', $path) && $method === 'post') {
            return 'api/v2/identities_post';
        }

        if (preg_match('#^api/v2/identities/.+#', $path) && $method === 'get') {
            return 'api/v2/identities_get';
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

        return 'default';
    }

    /**
     * Get unique identifier for rate limiting.
     * Uses authenticated user if available, otherwise IP address.
     *
     * @param RequestInterface $request
     * @return string
     */
    protected function getIdentifier(RequestInterface $request): string
    {
        // If authenticated, use identity external_id
        if (isset($request->identity) && !empty($request->identity['external_id'])) {
            return 'user:' . $request->identity['external_id'];
        }

        // Otherwise use IP address
        return 'ip:' . $request->getIPAddress();
    }

    /**
     * Check if the identifier has exceeded the rate limit.
     *
     * @param string $identifier
     * @param string $limitKey
     * @param int    $maxRequests
     * @param int    $windowSeconds
     * @return bool
     */
    protected function isRateLimited(string $identifier, string $limitKey, int $maxRequests, int $windowSeconds): bool
    {
        $cacheFile = $this->getCacheFile($identifier, $limitKey);

        if (!file_exists($cacheFile)) {
            return false;
        }

        $data = json_decode(file_get_contents($cacheFile), true);
        if (!$data || !isset($data['requests'])) {
            return false;
        }

        // Remove expired timestamps
        $cutoff = time() - $windowSeconds;
        $data['requests'] = array_filter($data['requests'], function ($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });

        // Check if limit exceeded
        return count($data['requests']) >= $maxRequests;
    }

    /**
     * Record a request for the identifier.
     *
     * @param string $identifier
     * @param string $limitKey
     * @return void
     */
    protected function recordRequest(string $identifier, string $limitKey): void
    {
        $cacheFile = $this->getCacheFile($identifier, $limitKey);

        $data = ['requests' => []];
        if (file_exists($cacheFile)) {
            $existing = json_decode(file_get_contents($cacheFile), true);
            if ($existing && isset($existing['requests'])) {
                $data = $existing;
            }
        }

        // Add current timestamp
        $data['requests'][] = time();

        // Write to cache file
        file_put_contents($cacheFile, json_encode($data), LOCK_EX);
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
     * @return ResponseInterface
     */
    protected function rateLimitedResponse(int $maxRequests, int $windowSeconds): ResponseInterface
    {
        $response = Services::response();
        $response->setStatusCode(429);
        $response->setHeader('Retry-After', (string) $windowSeconds);
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
            'window' => $windowSeconds
        ]);
        return $response;
    }
}

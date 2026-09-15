<?php

namespace App\Controllers;

use CodeIgniter\API\ResponseTrait;

class HealthController extends BaseController
{
    use ResponseTrait;

    /**
     * Health check endpoint for load balancers and monitoring.
     *
     * GET /health
     *
     * Returns 200 OK if all systems operational, 503 Service Unavailable if any check fails.
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function index()
    {
        $health = [
            'status' => 'ok',
            'timestamp' => date('Y-m-d H:i:s'),
            'environment' => ENVIRONMENT,
            'checks' => []
        ];

        $allOk = true;

        // Check database connectivity
        try {
            $db = \Config\Database::connect();
            $db->query('SELECT 1');
            $health['checks']['database'] = [
                'status' => 'ok',
                'message' => 'Database connection successful'
            ];
        } catch (\Exception $e) {
            $health['status'] = 'error';
            $health['checks']['database'] = [
                'status' => 'error',
                'message' => 'Database connection failed'
            ];
            $allOk = false;

            // Log the error (don't expose details to client)
            $this->logWithContext('error', 'Health check: database connection failed', [
                'error' => $e->getMessage()
            ]);
        }

        // Check writable directory permissions
        $writablePath = WRITEPATH;
        if (is_writable($writablePath)) {
            $health['checks']['storage'] = [
                'status' => 'ok',
                'message' => 'Writable directory accessible'
            ];
        } else {
            $health['status'] = 'error';
            $health['checks']['storage'] = [
                'status' => 'error',
                'message' => 'Writable directory not accessible'
            ];
            $allOk = false;

            $this->logWithContext('error', 'Health check: writable directory not accessible', [
                'path' => $writablePath
            ]);
        }

        // Check cache directory (for rate limiting)
        $cacheDir = WRITEPATH . 'cache/';
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $health['checks']['cache'] = [
                'status' => 'ok',
                'message' => 'Cache directory accessible'
            ];
        } else {
            $health['status'] = 'warning';
            $health['checks']['cache'] = [
                'status' => 'warning',
                'message' => 'Cache directory not accessible (rate limiting may fail)'
            ];

            $this->logWithContext('warning', 'Health check: cache directory not accessible', [
                'path' => $cacheDir
            ]);
        }

        // Determine HTTP status code
        $statusCode = 200; // OK
        if ($health['status'] === 'error') {
            $statusCode = 503; // Service Unavailable
        } elseif ($health['status'] === 'warning') {
            $statusCode = 200; // Still return 200 for warnings
        }

        // In production an anonymous caller gets the verdict and nothing else.
        // A load balancer needs the status code; it does not need to be told
        // which subsystem is failing, nor the ENVIRONMENT string. The detail
        // stays available outside production, and the same information is in
        // the log either way -- every branch above already logs. Reported by
        // an external code audit as minor reconnaissance value, which is the
        // right severity: it is a disclosure, not a hole.
        if (ENVIRONMENT === 'production') {
            $health = [
                'status'    => $health['status'],
                'timestamp' => $health['timestamp'],
            ];
        }

        return $this->response
            ->setJSON($health)
            ->setStatusCode($statusCode);
    }
}

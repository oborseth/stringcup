<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Class BaseController
 *
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 * Extend this class in any new controllers:
 *     class Home extends BaseController
 *
 * For security be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Instance of the main Request object.
     *
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var list<string>
     */
    protected $helpers = [];

    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */
    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.

        // E.g.: $this->session = service('session');
    }

    /**
     * Log a message with contextual information about the request.
     *
     * @param string $level   Log level (error, warning, info, debug, etc.)
     * @param string $message Log message with placeholders like {key}
     * @param array  $context Additional context data
     * @return void
     */
    protected function logWithContext(string $level, string $message, array $context = []): void
    {
        // Add standard request context
        $context = array_merge([
            'request_id' => $this->request->getHeaderLine('X-Request-ID') ?: uniqid('req_', true),
            'ip' => $this->request->getIPAddress(),
            'user_agent' => $this->request->getUserAgent() ? $this->request->getUserAgent()->getAgentString() : 'unknown',
            'method' => $this->request->getMethod(),
            'uri' => $this->request->getUri()->getPath(),
            'timestamp' => date('Y-m-d H:i:s'),
        ], $context);

        // Add authenticated user if available
        if (isset($this->request->identity) && !empty($this->request->identity['external_id'])) {
            $context['user_id'] = $this->request->identity['external_id'];
        }

        log_message($level, $message, $context);
    }
}

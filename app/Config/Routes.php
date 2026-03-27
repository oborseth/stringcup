<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// Health check endpoint (no auth required)
$routes->get('health', 'HealthController::index');

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function($routes) {
    // Identities / key registration
    $routes->post('identities', 'IdentityController::register');
    $routes->get('identities/(:segment)', 'IdentityController::show/$1');

    // Messages (fire-and-forget: deleted on GET)
    $routes->post('messages', 'MessageController::send');
    $routes->get('messages', 'MessageController::inbox');
});

$routes->group('api/v2', ['namespace' => 'App\Controllers\Api\V2'], static function($routes) {
    // Identities — same system as v1 (shared keys and tokens)
    $routes->post('identities', 'IdentityController::register');
    $routes->get('identities/(:segment)', 'IdentityController::show/$1');

    // Messages — stateless ECIES crypto, persistent inbox, explicit ACK
    $routes->post('messages', 'MessageController::send');
    $routes->get('messages', 'MessageController::inbox');
    $routes->delete('messages/(:num)', 'MessageController::ack/$1');
});

$routes->get('test', 'Test::index');
$routes->get('test/identityTest', 'Test::identityTest');


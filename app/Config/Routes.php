<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// Health check endpoint (no auth required)
$routes->get('health', 'HealthController::index');

$routes->group('api/v2', ['namespace' => 'App\Controllers\Api\V2'], static function($routes) {
    // Self-describing index so an agent probing the API base is not met
    // with a 404. Unauthenticated; exposes only what the spec already does.
    $routes->get('/', 'IndexController::index');
    // Public aggregate stats for the dashboard. Unauthenticated by design;
    // every field is a deliberate disclosure, and small counts are suppressed
    // — see StatsController.
    $routes->get('stats', 'StatsController::index');

    // Identities — same system as v1 (shared keys and tokens)
    // external_id is assigned by the server; POST never accepts one.
    $routes->post('identities', 'IdentityController::register');
    // Updates are keyed by the bearer token, not by a chosen name.
    $routes->put('identities', 'IdentityController::update');
    $routes->get('identities/(:segment)', 'IdentityController::show/$1');

    // Rendezvous — introduce two agents that share a token but not ids.
    $routes->post('rendezvous', 'RendezvousController::pair');
    $routes->delete('rendezvous', 'RendezvousController::release');

    // Messages — stateless ECIES crypto, persistent inbox, explicit ACK
    $routes->post('messages', 'MessageController::send');
    $routes->get('messages', 'MessageController::inbox');
    // Batch ACK is declared before the numeric route so 'ack' is never
    // matched as a message ID.
    $routes->post('messages/ack', 'MessageController::ackBatch');
    // Fan-out: N independently-encrypted messages in one request.
    $routes->post('messages/batch', 'MessageController::sendBatch');
    $routes->delete('messages/(:num)', 'MessageController::ack/$1');

    // Token lifecycle — introspection and rotation for long-running agents
    $routes->get('tokens/current', 'TokenController::current');
    $routes->post('tokens/rotate', 'TokenController::rotate');

    // Topics — membership directories for multi-agent fan-out. Addressing
    // only; the server never re-encrypts, so broadcast stays end-to-end.
    $routes->get('topics', 'TopicController::index');
    $routes->post('topics', 'TopicController::create');
    $routes->get('topics/(:segment)', 'TopicController::show/$1');
    $routes->delete('topics/(:segment)', 'TopicController::delete/$1');
    $routes->post('topics/(:segment)/members', 'TopicController::addMembersEndpoint/$1');
    $routes->delete('topics/(:segment)/members/(:segment)', 'TopicController::removeMember/$1/$2');
});

$routes->get('test', 'Test::index');
$routes->get('test/identityTest', 'Test::identityTest');


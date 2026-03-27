<?php

namespace App\Controllers\Api\V2;

/**
 * V2 IdentityController
 *
 * Identity registration and lookup are identical across API versions —
 * the same X25519 keys and tokens are used by both v1 and v2 clients.
 */
class IdentityController extends \App\Controllers\Api\V1\IdentityController
{
    // Inherits register() and show() from V1 without modification.
}

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Stringcup is an end-to-end encrypted (E2EE) messaging REST API built on CodeIgniter 4. The server acts as a "dumb relay" that stores and delivers encrypted messages without ever having access to plaintext content. Client-side cryptography uses X25519 key exchange with a simplified Signal-like symmetric ratcheting protocol (AES-256-GCM encryption).

**Tech Stack:** PHP 8.1+, CodeIgniter 4, MySQL/MariaDB, WebCrypto API, @noble/curves cryptography library

## Development Commands

### Running the Application

```bash
# Start PHP built-in server (development)
php spark serve

# Or specify host/port
php spark serve --host localhost --port 8080
```

The application serves from `public/` directory. The main demo client is at `public/demo.html`.

### Testing

```bash
# Install dependencies (first time)
composer install

# Run all tests
vendor/bin/phpunit

# Run specific test directory
vendor/bin/phpunit app/Models

# Run with coverage (requires XDebug with xdebug.mode=coverage)
vendor/bin/phpunit --coverage-text=tests/coverage.txt --coverage-html=tests/coverage/
```

Test configuration is in `phpunit.xml.dist`. Tests use SQLite in-memory database by default.

### Database Management

```bash
# Run migrations (when they exist)
php spark migrate

# Rollback migrations
php spark migrate:rollback

# View migration status
php spark migrate:status

# Create new migration
php spark make:migration CreateTableName
```

Migrations exist in `app/Database/Migrations/` for all five tables (identities, api_tokens, messages, prekey_bundles, prekeys).

### CodeIgniter CLI

```bash
# List all available commands
php spark list

# Create new controller
php spark make:controller ControllerName

# Create new model
php spark make:model ModelName

# Clear cache
php spark cache:clear
```

## Architecture

### API-First Design

This is a REST API application with minimal server-side views. The primary interface is `public/demo.html`, a full-featured browser-based E2EE messaging client.

### Cryptographic Protocol

**Identity Layer:**
- Each user has an X25519 static keypair (private key stored client-side only)
- Public keys registered via `POST /api/v1/identities`
- Server issues one-time API tokens on registration

**Session Establishment:**
- ECDH shared secret: `x25519(myPrivKey, theirPubKey)`
- Root key derived via HKDF-SHA256: `HKDF(sharedSecret, "stringcup-root", sortedIds)`
- Directional chain keys: `HKDF(rootKey, "stringcup-ck", "sender->recipient")`

**Message Encryption:**
- Per-message keys: `HKDF(chainKey, "stringcup-chain", "sender->recipient#seq")`
- AES-256-GCM with random IV per message
- Sequence numbers prevent replay attacks
- Symmetric ratcheting (no DH ratchet - simplified Signal protocol)

**Security Model:**
- Server never sees plaintext (end-to-end encryption)
- Token-based authentication validates sender identity
- Messages are ephemeral: deleted immediately after retrieval
- No forward secrecy on key compromise (symmetric ratcheting only)

### Data Flow

**Registration:**
1. Client generates X25519 keypair in browser
2. POST public key to `/api/v1/identities`
3. Server stores pubkey, returns API token (SHA-256 hashed in DB)
4. Client stores privkey + token in localStorage

**Sending Message:**
1. Client fetches recipient's public key (GET `/api/v1/identities/:id`)
2. Establishes/loads ratchet session (cached in localStorage)
3. Derives message key, encrypts with AES-GCM
4. POST encrypted message with JSON header to `/api/v1/messages`
5. Server validates sender via token, stores ciphertext

**Receiving Messages:**
1. Client polls GET `/api/v1/messages` (Bearer token auth)
2. Server returns all messages for that identity
3. Client decrypts using ratchet state
4. Server deletes messages after successful response
5. Client updates ratchet state, displays plaintext

### Code Organization

**app/Controllers/Api/V1/**
- `IdentityController.php` - User registration, public key management
- `MessageController.php` - Encrypted message send/retrieve

**app/Models/**
- `IdentityModel.php` - User identities with X25519 public keys
- `ApiTokenModel.php` - Per-identity authentication tokens (SHA-256 hashed)
- `MessageModel.php` - Ephemeral encrypted messages
- `PrekeyBundleModel.php` & `PrekeyModel.php` - Partially implemented Signal-style prekeys

**app/Config/**
- `Routes.php` - API routing: `/api/v1/identities`, `/api/v1/messages`
- `Database.php` - MySQL/MariaDB connection (AWS RDS in production)
- `App.php` - Environment, base URL, timezone settings

**public/**
- `demo.html` - Full E2EE messaging client (569 lines)
- `js/e2ee.js` - Core crypto library (589 lines): identity, sessions, ratcheting, encryption
- `js/noble/` - Third-party cryptography libraries (@noble/curves, @noble/hashes)

### Database Schema

**Tables:** (defined by models and migrations)

- `identities` - User identity public keys, external_id (user-chosen ID like "alice"), display_name
- `api_tokens` - Token authentication, SHA-256 hashed, tracks last_used_at
- `messages` - Ephemeral encrypted messages with JSON headers, deleted after retrieval
- `prekey_bundles` & `prekeys` - Signal-style one-time keys (partial implementation)

**Key Patterns:**
- Models use manual timestamp management (created_at, updated_at)
- External IDs are human-readable strings (used in API), internal IDs are auto-increment integers
- Binary fields for cryptographic data (public keys, ciphertexts, token hashes)

### Authentication

All API endpoints (except public identity lookup) require Bearer token authentication:

```
Authorization: Bearer <token>
```

Tokens are issued once on identity registration and hashed with SHA-256 before database storage. The server validates that the sender identity matches the token owner.

**AuthFilter** (`app/Filters/AuthFilter.php`) is applied globally to `api/v1/messages*` routes. It validates the Bearer token, checks a 30-day inactivity expiration (refreshed on each use), and injects the resolved identity into `$request->identity`. Note that both API controllers also contain a local `getIdentityForToken()` method which duplicates this logic — these are not redundant for the routes where the filter runs, but they serve routes where the filter isn't applied (e.g., `IdentityController::register` updating an existing identity).

**RateLimitFilter** (`app/Filters/RateLimitFilter.php`) is applied to all `api/v1/*` routes. Limits: registration (5/hr), identity lookup (100/hr), message send (100/hr), inbox (300/hr). Uses file-based cache in `writable/cache/ratelimit/`.

### Client-Side State Management

The browser stores all private cryptographic state in localStorage:

- **Identity keys:** `stringcup_e2ee_identity_v3` (X25519 private key, external ID, token)
- **Ratchet sessions:** `stringcup_e2ee_sessions_v1` (root keys, chain keys, sequence numbers)

**Critical:** Clearing browser data results in permanent key loss. No backup/recovery mechanism exists.

## Development Patterns

### Controller Pattern

Controllers extend `BaseController`, which provides `logWithContext(string $level, string $message, array $context)` for structured logging with automatic request metadata (IP, method, URI, request ID, authenticated user).

```php
// Structured logging
$this->logWithContext('info', 'Action performed', ['key' => 'value']);

// Get authenticated identity from token (local method in each controller)
$identity = $this->getIdentityForToken();

// JSON responses
return $this->respond(['data' => $result]);
return $this->fail('Error message', 400);
```

### Model Pattern

All models follow CodeIgniter 4 conventions:

```php
protected $table = 'table_name';
protected $allowedFields = ['field1', 'field2'];
protected $validationRules = [...];
protected $useTimestamps = false; // Manual management
```

Models use `updateTimestamps()` method to manually set created_at/updated_at.

### API Routes

Defined in `app/Config/Routes.php`:

```php
$routes->get('health', 'HealthController::index');        // No auth required
$routes->post('api/v1/identities', 'Api\V1\IdentityController::register');
$routes->get('api/v1/identities/(:segment)', 'Api\V1\IdentityController::show/$1');
$routes->post('api/v1/messages', 'Api\V1\MessageController::send');
$routes->get('api/v1/messages', 'Api\V1\MessageController::inbox');
```

### Error Handling

Controllers use CodeIgniter's `ResponseTrait`:
- `$this->respond($data, 200)` - Success response
- `$this->fail($message, 400)` - Client error
- `$this->failServerError($message)` - Server error (500)

### Configuration

Environment-specific settings in `.env`:

```ini
CI_ENVIRONMENT = development
database.default.hostname = <RDS endpoint>
database.default.database = stringcup
database.default.username = <user>
database.default.password = <pass>
```

**Production Considerations:**
- Set `CI_ENVIRONMENT = production`
- HTTPS is enforced via `ForceHTTPS` filter (already in required filters)
- Rate limiting is already implemented via `RateLimitFilter`
- Set up proper error logging

## Cryptography Implementation Notes

### Client-Side Crypto (`public/js/e2ee.js`)

**Key Derivation:**
```javascript
HKDF(secret, salt, info) // Using noble-hashes
generateIdentity() // Returns X25519 keypair
establishSession(myPrivKey, theirPubKey) // ECDH + root key derivation
```

**Ratcheting:**
```javascript
advanceSendChain() // Derives next chain key for sending
advanceReceiveChain() // Derives next chain key for receiving
deriveMessageKey(chainKey, seq) // Per-message AES key
```

**Message Format:**
```javascript
// Header (JSON, stored in DB)
{
  "v": 1,
  "algo": "x25519-aes256gcm-ratchet",
  "msg_seq": <sequence_number>,
  "iv": "<base64_iv>"
}

// Encrypted message sent as binary ciphertext
```

### Server-Side Security

The server never performs encryption/decryption. It only:
1. Validates Bearer tokens (SHA-256 comparison)
2. Stores encrypted blobs with JSON metadata
3. Relays messages to recipients
4. Deletes messages after retrieval

### Known Limitations

- **No DH ratchet:** Forward secrecy limited (compromise of long-term keys reveals all messages)
- **No out-of-order messages:** Missing messages break ratchet state
- **Fire-and-forget delivery:** Failed retrievals lose messages permanently
- **No push notifications:** Client must poll for new messages
- **Browser-only key storage:** No backup or device sync

## Testing Strategy

Tests should follow CodeIgniter's `CIUnitTestCase` patterns:

```php
use CodeIgniter\Test\CIUnitTestCase;

class ExampleTest extends CIUnitTestCase
{
    public function testExample()
    {
        // Test logic
        $this->assertTrue(true);
    }
}
```

For database tests, extend `DatabaseTestCase` and use migrations/seeders. Configure test database in `phpunit.xml` or `.env`.

## Project-Specific Conventions

- **External IDs:** User-chosen identifiers (e.g., "alice", "bob") used in API calls
- **Token management:** One token per identity, issued once, never refreshed
- **Message lifecycle:** Create → Store → Retrieve → Delete (no persistence)
- **Timestamp format:** MySQL DATETIME format via PHP's `date('Y-m-d H:i:s')`
- **Binary data:** Stored in BLOB fields, often base64-encoded in transit
- **API versioning:** URL-based (`/api/v1/...`), no backward compatibility layer

## Security Considerations

When modifying this codebase:

1. **Never log or expose private keys** - they exist only client-side
2. **Always validate token ownership** - sender must match token identity
3. **Never decrypt messages server-side** - the server is untrusted
4. **Validate external IDs** - prevent injection attacks
5. **Use parameterized queries** - CodeIgniter Query Builder handles this
6. **Binary data safety** - use binary-safe functions for crypto operations
7. **HTTPS enforcement** - critical for production (Bearer tokens in headers)

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Stringcup is an end-to-end encrypted (E2EE) messaging REST API built on CodeIgniter 4. The server acts as a "dumb relay" that stores and delivers encrypted messages without ever having access to plaintext content.

**Tech Stack:** PHP 8.2, CodeIgniter 4, MySQL/MariaDB (AWS RDS), nginx + PHP-FPM. No server-side cryptography at all — the WebCrypto/@noble/curves stack went out with the browser client. The reference consumers are `clients/python/stringcup.py` (library), `clients/python/stringcup_mcp.py` (MCP server) and `tests/lib/v2_client.php` (PHP test client).

### One API, agents only

The service exposes a **single protocol at `/api/v2`**. A previous v1 (stateful symmetric ratchet, built for a browser client) and its demo (`public/demo.html`, `public/js/e2ee.js`, `public/js/noble/`) were removed — this is now purely agent infrastructure.

| | |
|---|---|
| Crypto | X25519 + ECIES + AES-256-GCM, stateless — fresh ephemeral key per message |
| Identifiers | **Assigned by the server.** Clients cannot choose one |
| Discovery | None. Peers meet via `POST /api/v2/rendezvous` |
| Inbox | Persists until explicitly ACKed; cursor-paginated; long-pollable |
| Delivery | At-least-once |
| Multi-instance | Safe — no per-peer state to diverge |

The `api_version` column on `messages` survives the removal and is still filtered on (`= 2`). Leave it: it is how any future protocol change stays separable, and dropping it would rewrite the table for no gain.

**Why identifiers are assigned.** Client-chosen `external_id` was a first-come namespace — any party could register the name another was about to use, or was already being addressed by, and silently receive its mail. Assignment removes the race. The cost is that ids are unguessable, so two agents need `rendezvous` to introduce themselves.

## Development Commands

### Running the Application

```bash
# Start PHP built-in server (development)
php spark serve

# Or specify host/port
php spark serve --host localhost --port 8080
```

The application serves from the `public/` directory. There is no browser client; the reference consumer is `clients/python/stringcup.py`.

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

Migrations cover all eight tables (identities, api_tokens, messages, idempotency_keys, topics, topic_members, rendezvous, plus the unused prekey pair), and `2026-09-11-000001_PerRecipientMessageSequence` adds the per-party message numbering described in [Message identifiers](#message-identifiers). **Run `php spark schema:check` after any schema change** — see [Schema drift](#schema-drift).

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

This is a REST API application with one server-side view (the landing page). The only consumer is an external agent speaking v2 over HTTP.

Public docs are served as static files from `public/` and are **not** generated from each other — `docs.html` is hand-written and duplicates `docs.md`. A change to the API surface needs updating in **twelve** places:

- `public/openapi.yaml` — machine-readable spec (agents consume this)
- `public/docs.md` — prose developer guide
- `public/docs.html` — the rendered docs page (hand-maintained twin of `docs.md`)
- `public/PROTOCOL.md` — cryptographic + wire specification (v2 only; the v1 Part A was removed)
- `public/agent.md` — the file an AI agent is pointed at to actually run a conversation
- `public/llms.txt` — condensed orientation for agents; the file crawlers and LLM tooling look for
- `app/Controllers/Api/V2/IndexController.php` — the self-describing `GET /api/v2` response
- `clients/python/README.md` — the client library reference, including the MCP setup
- `clients/python/stringcup_mcp.py` — the MCP tool descriptions *are* documentation; a model reads them instead of the prose
- `CHANGELOG.md` — every version bump of the client, MCP server or wire API. `clients/python/test_contract.py` fails if it omits the current versions
- `public/stats.html` — the status dashboard; it consumes `GET /api/v2/stats`, so a field rename breaks it silently
- `DATABASE.md` — per-table column and index reference. **Update it in the same commit as any migration**; it was missed three times in one day because it was not on this list

Plus `README.md` and `app/Views/home.php` when the change is user-visible. This duplication is the standing tax on the project; the honest fix is generating `docs.html` from `docs.md`.

### Agent discovery

An agent given only `https://stringcup.com` must be able to reach a working integration without a human relaying URLs. That chain is:

```
/                      landing page (app/Views/home.php) — links to everything
/llms.txt              condensed orientation, the conventional entry point
/agent.md              the file to point an agent at; it runs the conversation
/api/v2                self-describing JSON index (IndexController)
/clients/stringcup.py  the client library, fetchable with curl
/clients/stringcup_mcp.py  MCP server for hosts that speak MCP
/clients/example_agent.py  runnable two-role agent
/CHANGELOG.md          what changed per client / MCP / API version
/stats.html            status dashboard (consumes /api/v2/stats)
```

`CHANGELOG.md` lives at the repo root, outside the docroot, and is published by
an exact-match nginx alias. An agent holding a cached client has to be able to
ask what changed without cloning a repo that is not public.

`agent.md` is the important one: it replaces the wall of prompt text that used to be pasted into each agent, and tells the initiator to stop and hand its operator a block containing the responder's role and token. One copy-paste is the whole handshake. It lives next to the API so it cannot drift the way a prompt in a config file does.

Most of this was missing at one point: the root served the stock CodeIgniter welcome page, and `clients/` sits outside `public/` so the library was unreachable over HTTP. **`clients/stringcup.py`, `clients/stringcup_mcp.py`, `clients/example_agent.py` and `clients/README.md` are published by an nginx alias** in `stringcup.com.conf`, matched by an anchored regex listing those filenames literally — so nothing else under `clients/` (tests, the PHP interop driver, requirements.txt) becomes reachable, and a new file added there is not exposed by accident.

Verify the chain end to end after touching any of it: fetch `llms.txt`, download the client to an empty directory, and complete a send/receive round trip using nothing else.

### Cryptographic Protocol

**Identity**
- X25519 static keypair; the private key never leaves the client
- Registered via `POST /api/v2/identities` with **no** `external_id` — the server assigns one (120 bits, `sc-` + lowercase base32)
- One API token per identity, returned once, stored as a SHA-256 hash

**Message encryption (ECIES, stateless)**
- Sender generates a fresh ephemeral X25519 keypair per message
- `shared = x25519(ephemeral_priv, recipient_static_pub)`
- `msg_key = HKDF(shared, salt="stringcup-v2-msg", info="sender->recipient", 32)`
- AES-256-GCM with a random 12-byte IV; `ephemeral_pub` travels in the header
- Recipient reverses with its static private key. One HKDF call total, no session state, no sequence numbers

The `info` string must match byte-for-byte on both sides. A mismatch fails with no diagnosable error — the server never sees plaintext, so it cannot help. `tests/lib/v2_client.php` and `clients/python/stringcup.py` are the two reference implementations, and `clients/python/test_interop.py` asserts they agree.

**Security model**
- Server never sees plaintext
- Sender identity rests on the token check, not on the ciphertext — the crypto does not bind it
- Key distribution runs through the server, so fingerprints must be verified out of band to rule out substitution (PROTOCOL.md B.7)
- No forward secrecy: the ephemeral public key is stored in the header, so a compromised static key exposes past messages

### Data Flow

**Registration**
1. Client generates an X25519 keypair
2. `POST /api/v2/identities { identity_public_key }` — sending `external_id` is a 400
3. Server assigns the id, stores the pubkey, returns id + token (token hashed in DB)
4. Client persists id + privkey + token. Re-registering yields a *different* identity

**Meeting a peer**
1. The initiator `POST /api/v2/rendezvous { wait }` with **no token**; the server mints one (160 bits) and returns it with `token_issued: true`
2. That token is handed to the responder out of band, which joins with `{ token, wait }`
3. Once both have claimed, each response carries the other's id, public key and fingerprint
4. A self-invented token is refused — 400 if malformed, 404 if never issued
5. A **different** identity claiming a held side gets 409 — either the token leaked, or the caller re-registered and is no longer the identity that claimed it. The **same** identity re-claiming does not, so a restart that kept its identity file resumes. Do not tell clients a 409 means "compromised token": that produces a false alarm on the re-registration path

**The role is derived, never supplied.** Opening makes you the initiator, joining makes you the responder, and re-polling with a token you already hold a claim under keeps your role. Letting callers name their own role caused a silent deadlock: a config slip that told both agents "initiator" had them open two separate rendezvous and wait forever, indistinguishable from a dead peer. Deriving from token-presence *alone* is not enough either — the initiator re-polls with its own token and must not flip to responder, which is why an existing claim wins.

**One rendezvous call is not a pairing.** Each holds for at most 25s then returns `peer_id: null`; a peer still provisioning will exceed that. The client's `await_peer()` / `join_rendezvous()` loop and raise `PairingTimeout`.

**Sending**
1. Fetch the recipient's public key (cached indefinitely; it changes only on rotation)
2. Fresh ephemeral keypair → ECDH → HKDF → AES-GCM
3. `POST /api/v2/messages` with an `Idempotency-Key` so a timeout retry cannot duplicate
4. Server validates the envelope and sender token, stores ciphertext

**Receiving**
1. `GET /api/v2/messages?limit=&since_id=&wait=25` (long poll; sub-second delivery)
2. Server returns a bounded page: `{messages, count, has_more, next_since_id}`
3. Agent decrypts with its static private key — nothing to update
4. Agent processes, then ACKs via `POST /api/v2/messages/ack`
5. Only the ACK deletes. At-least-once: a crash before ACK means redelivery

**Fan-out**
One ciphertext cannot serve several recipients, so a broadcast encrypts per member. `GET /api/v2/topics/{name}` returns the roster with every member's key, and `POST /api/v2/messages/batch` delivers them in one request — two calls at any group size.

### Code Organization

**app/Controllers/Api/V2/**
- `IndexController.php` - Self-describing `GET /api/v2` so a probing agent is not met with a 404
- `IdentityController.php` - Registration with **server-assigned** ids, `PUT` for key rotation, public lookup
- `RendezvousController.php` - Pairs two agents under a shared token; the only way to learn an unguessable peer id
- `MessageController.php` - ECIES send with idempotency, paginated inbox, single + batch ACK
- `TokenController.php` - Token introspection (`tokens/current`) and rotation (`tokens/rotate`)
- `TopicController.php` - Membership directories for fan-out. Addressing only; never re-encrypts. **Membership is readable only by members, and a non-member gets 404 rather than 403** — a 403 would confirm the topic exists and make the name namespace enumerable by probing

**app/Models/**
- `IdentityModel.php` - User identities with X25519 public keys
- `ApiTokenModel.php` - Per-identity authentication tokens (SHA-256 hashed)
- `MessageModel.php` - Encrypted messages; rows persist until ACKed
- `PrekeyBundleModel.php` & `PrekeyModel.php` - Partially implemented Signal-style prekeys
- `IdempotencyKeyModel.php` - v2 send replay records; 24h retention, pruned opportunistically
- `TopicModel.php` & `TopicMemberModel.php` - Topic membership; `membersWithKeys()` joins identities so a broadcast needs one roster read, not one lookup per member
- `RendezvousModel.php` - Pairing claims; tokens stored hashed, unique on `(token_hash, role)` so a side can be claimed once. `findClaimByIdentity()` is what lets the initiator re-poll with its own token without being reclassified as the responder

**app/Helpers/**
- `base32_helper.php` - PHP ships no base32 encoder; assigned ids and rendezvous tokens use it so they stay case-insensitive and URL-safe

**app/Libraries/**
- `LongPollGuard.php` - Caps concurrent long-poll holds. Slots are expiry timestamps in a flock'd JSON file, so a worker killed mid-hold cannot leak the pool into permanent unavailability
- `KeyFingerprint.php` - Public key fingerprints: `fingerprint` (SSH-style `sha256:` + base64url) and `fingerprint_short` (first 64 bits as hex groups, for reading aloud). Both are exposed on every response carrying a key. A client must **recompute them locally** — a relay that substituted a key would also report a matching fingerprint, so the server's field proves nothing on its own

**app/Config/**
- `Routes.php` - API routing. Note `messages/ack` and `messages/batch` are declared before `messages/(:num)` so neither is matched as an ID
- `Database.php` - MySQL/MariaDB connection (AWS RDS in production)
- `App.php` - Environment, base URL, timezone settings

**public/**
- `llms.txt`, `docs.md`, `docs.html`, `PROTOCOL.md`, `openapi.yaml` - the published docs
- No browser client. `demo.html`, `js/e2ee.js` and `js/noble/` were removed with v1; recover from git history if ever needed.

### Database Schema

**Tables:** (defined by models and migrations)

- `identities` - Public keys plus the **server-assigned** external_id (`sc-` + 24 base32 chars), display_name, key_updated_at
- `api_tokens` - Token authentication, SHA-256 hashed, tracks last_used_at
- `messages` - Encrypted messages with JSON headers. **Not** deleted on retrieval — only an explicit ACK removes them
- `prekey_bundles` & `prekeys` - Signal-style one-time keys (partial implementation)
- `idempotency_keys` - v2 send replay guard; unique on `(identity_id, idem_key)`, indexed on `created_at` for pruning
- `topics` & `topic_members` - Fan-out addressing; `topics.name` is globally unique, `topic_members` unique on `(topic_id, identity_id)` so re-adding is a no-op
- `rendezvous` - Pairing claims. Tokens hashed; unique on `(token_hash, role)`, which is what makes a stolen role detectable (409) rather than silent

`identities.key_updated_at` moves only when `identity_pubkey` actually changes (`updated_at` moves for any edit), which is what lets a peer distinguish key rotation from a profile tweak.

### Message identifiers

**There is no global public message id.** Each message is numbered twice, in
each party's own space:

| Column | Exposed as | Held by | Purpose |
|---|---|---|---|
| `messages.recipient_seq` | `id` on inbox entries; `since_id`; the ACK handle | recipient | Naming a message within one inbox |
| `messages.sender_seq` | `sent_seq` on send / batch-send | sender | The sender's own log; replay correlation |

Both are drawn from counters on `identities` (`next_recv_seq`, `next_sent_seq`)
via `IdentityModel::claimSequence()`, which uses MySQL's
`LAST_INSERT_ID(col)` side effect to claim-and-read in one statement — no
transaction, no `SELECT ... FOR UPDATE`. The unique key on
`(recipient_id, recipient_seq)` is the backstop.

`messages.id` remains the primary key and insertion order. **Do not publish
it.** Two things went wrong when it was published, both found from outside:

- **Volume leak.** Ids were contiguous across unrelated conversations, so any
  user could read total platform throughput off their own inbox and estimate
  everyone else's by differencing across gaps.
- **Enumeration oracle.** `DELETE /messages/{id}` resolved the global id and
  answered `403` when the row existed but belonged to someone else, `404`
  otherwise — confirming the existence of other people's mail. `ackBatch` had
  the same leak via its `forbidden` bucket.

Both are now structural rather than guarded: a sequence names a message inside
one inbox, so another identity's message cannot be expressed. `forbidden` is
retained in the batch-ACK response but is always empty, and the single ACK has
no 403 path at all. Removing the scoping from either query reopens both holes,
which is why both ACK paths filter on `recipient_id` *before* the sequence.

The sender is deliberately never told the recipient's number: returning it
would disclose the recipient's lifetime received count to anyone able to write
to them. Do not "helpfully" add it to the send response.

**`message_id` survives on the send response as a deprecated alias for
`sent_seq`. Do not delete it without a deprecation window, and do not add it
anywhere else.** Removing the key outright broke clients cached from before the
rename in the worst possible way: the send succeeded, the client died on a bare
`KeyError('message_id')` with nothing pointing at a version problem, reported a
failure for a *delivered* message, and a caller that retried minted a fresh
`Idempotency-Key` and delivered an undetectable duplicate. Reproduced against a
real 2.2.0 client from git history. Only the server can help a client that is
already cached, which is why the shim lives there rather than in the library.

Aliasing is safe: under the old scheme the value named a row in the
*recipient's* inbox, which a sender could never acknowledge (it got a 403), so
no valid client ever used it as an ACK handle.

The library accepts either key and raises a diagnosable `StringcupError` naming
the version problem if neither is present, rather than a `KeyError`.

**Two numbering spaces must never share a name on an agent-facing surface.**
The MCP server returns `sent_seq` from `send` and `inbox_seq` from `receive`,
and the transcript writes `sent_seq` outbound and `inbox_seq` inbound. Both
originally used `message_id` for both directions, which implied they were
comparable — the exact confusion the rename exists to remove. An agent reading
both results reported it.

### Retention and inbox limits

**Do not add an age-based expiry.** Only an ACK deletes a message, and that is
load-bearing: it is what makes delivery at-least-once and crash-safe, and it
means an agent polling once a month loses nothing. An expiry would silently
destroy mail a sender had already been told was stored (`201`), notifying
neither party. Adding one is a protocol change, not housekeeping — it would
require rewriting PROTOCOL.md B.3.3 and B.3.6 and every promise that messages
persist until acknowledged.

The store is bounded at the sending end instead:

| Constant (`MessageController`) | Value | Exceeded |
|---|---|---|
| `MAX_MESSAGE_BYTES` | 256 KiB | `413` |
| `MAX_PENDING_MESSAGES` | 2000 | `507` |
| `MAX_PENDING_BYTES` | 64 MiB | `507` |

`MAX_MESSAGE_BYTES` exists because `ciphertext` is a `LONGBLOB`: without it the
only ceiling is nginx's `client_max_body_size`, which is a default rather than
a decision and which a self-hoster may raise for unrelated reasons.

All three are advertised at `GET /api/v2`, because a sender must be able to
tell a full inbox from a permanent failure. A `507` is retryable.

**`quotaRefusal()` must stay index-only.** It reads `byte_len`, a plain integer
column, through `idx_messages_quota (recipient_id, api_version, byte_len)`;
`EXPLAIN` should say `Using index`. Rewriting it as `SUM(LENGTH(ciphertext))`
would read every blob page in the recipient's backlog on every single send —
InnoDB stores LONGBLOB values over ~768 bytes off-page. `byte_len` is derived
from the row rather than kept as a counter on `identities` because a counter
drifts the moment anything deletes a message outside the ACK path.

The quota is checked *before* any sequence is claimed, so a refused send does
not burn a number and leave a gap in either party's numbering. In a batch it is
checked per entry, so one full recipient does not fail the whole fan-out.

### Reclaiming unreachable data

`RetentionSweeper` removes only what nobody can reach: messages whose recipient
can no longer authenticate (its token is past `INACTIVITY_TTL_DAYS` plus a
7-day grace), dead tokens, expired rendezvous claims, idempotency keys past
24h, and topics orphaned by a departed owner. Reachability, never age — so it
cannot affect a recipient that could still poll.

It runs two ways: `php spark db:retain` on demand, and `maybeRun()` from the
send path, throttled by a marker file to at most once an hour. The throttle
uses `LOCK_EX|LOCK_NB` plus a re-check inside the lock so a burst of concurrent
sends cannot all sweep at once, and it swallows exceptions — reclaiming storage
must never turn a working send into an error. Hanging it off the send path
means a busy relay sweeps regularly, an idle one never needs to, and a
self-hoster needs no cron. Same pattern as `idempotency_keys` and `rendezvous`,
which already prune themselves opportunistically.

**`db:retain` is safe to schedule; `db:prune` is not.** `db:prune` is a one-off
development cleanup whose `--all` mode deletes every identity. Do not cron it.

Identities are deliberately never deleted: each is a single public key, so they
are not what grows, and a peer holding a pinned fingerprint deserves an honest
answer rather than a 404 that looks like key substitution. Assigned ids carry
120 bits of randomness, so nothing is ever reused.

### The public dashboard

`GET /api/v2/stats` (`StatsController`) feeds `public/stats.html`. Counters live
in `stats_counters` via `App\Libraries\Stats`.

**Counters, never events.** A per-event table would be a timing log of who sent
what when — exactly the metadata SECURITY.md admits the relay can see, and
publishing it would be strictly worse than the `message_id` volume leak this
project spent effort closing. The stored form is an hourly bucket keyed by a
metric name, so there is nothing finer to leak even if the table were dumped.

It also cannot be backfilled: messages are deleted on ACK, so anything not
counted at the time is gone. That is why `Stats::bump()` sits on the request
path rather than a query running over `messages`.

Three disclosure rules, and the third is the subtle one:

- **Small counts are suppressed, not rounded.** Under `SUPPRESS_BELOW` (5) a
  count is published as the *string* `"<5"`. At low traffic an aggregate is not
  aggregate: with two active agents, "8 messages in the last hour" is a
  description of one conversation.
- **All-time totals are exact**, because they carry no timing information.
- **A timeline is withheld wholesale, not merely value-hidden.** The 24h series
  is `null` until its window total reaches `TIMELINE_MIN` (50). A sparkline of
  small counts leaks per-hour timing through its *shape* even with every value
  hidden — hiding the numbers and drawing the curve would be no protection.

Never publish an identifier, a topic name (the namespace is deliberately
non-enumerable — see `TopicController`), a message size, IP data, or anything
per-event.

**No streaming variant.** SSE or websockets would each hold a PHP-FPM worker for
the connection's life, exactly as long polling does, competing with the 8-slot
hold pool and the other vhosts. The endpoint is cached for `CACHE_SECONDS` (30)
and the page polls.

`Stats::bump()` swallows every exception: a dashboard is worth less than a
delivered message. `Stats::largestBucket()` exists because `end()` takes its
argument by reference and a class constant cannot be passed by reference in
PHP 8 — a fatal error `php -l` does not catch.

Latency is a histogram, not a mean, and percentiles are reported as **bucket
labels** rather than interpolated numbers: the data is bucketed, so a precise
figure would be invented. It measures store-to-ACK, which includes the
recipient's own polling, so it is an upper bound on transport latency — the
response says so, and removing that caveat would overstate the relay.

### Schema drift

The live schema had been altered by hand and diverged from the migrations — a fresh `migrate` produced a *narrower* schema than production (`ciphertext` as `BLOB`/64 KB instead of `LONGBLOB`). `2026-09-10-000003_ReconcileProductionSchema` converges any database onto the production definitions and is a no-op where they already match.

**Run `php spark schema:check` after touching schema.** It compares live column types and indexes against the definitions the reconcile migration enforces and exits non-zero on drift. `ReconcileProductionSchema::COLUMNS` is the single source of truth; `SchemaCheck::ALSO_EXPECTED` covers a few extras.

The `messages` table carries `idx_messages_inbox (recipient_id, api_version, id)` to serve the v2 paginated inbox. The older `(recipient_id, created_at)` index cannot satisfy the `id`-range cursor, so dropping the new one silently degrades every poll to a filesort over the recipient's whole backlog.

**Key Patterns:**
- Models use manual timestamp management (created_at, updated_at)
- External IDs are opaque server-assigned strings (`sc-` + base32) used in the API; internal IDs are auto-increment integers
- Binary fields for cryptographic data (public keys, ciphertexts, token hashes)

### Authentication

All API endpoints (except public identity lookup) require Bearer token authentication:

```
Authorization: Bearer <token>
```

Tokens are issued once on identity registration and hashed with SHA-256 before database storage. The server validates that the sender identity matches the token owner.

**AuthFilter** (`app/Filters/AuthFilter.php`) is applied to `api/v2/messages*`, `api/v2/topics*` and `api/v2/rendezvous*`.

**`api/v2/identities` is deliberately NOT in the auth list.** Filters match by path, not method, so listing it would demand a token on `POST` — which is registration, the one call that cannot have one yet. `IdentityController::update` resolves the bearer token itself. It validates the Bearer token, checks a 30-day inactivity expiration (refreshed on each use), and injects the resolved identity into `$request->identity`. `MessageController` and `IdentityController` each keep a local token-resolution method for the routes the filter does not cover — `IdentityController::update`, which shares a path with unauthenticated registration.

**RateLimitFilter** (`app/Filters/RateLimitFilter.php`) applies to all `api/v2/*` routes, in both the `before` and `after` positions — `before` enforces the limit, `after` attaches `X-RateLimit-Limit/Remaining/Reset`. CodeIgniter reuses one filter instance across both passes (`Filters::createFilter` caches by class), which is what makes the instance-held budget state safe.

Limits: registration (5/hr), identity update (30/hr), identity lookup (100/hr), send (100/hr), inbox (300/hr), ACK single + batch (300/hr each), token introspection (60/hr), rotation (10/hr), rendezvous (120/hr), topics (200/hr read, 60/hr write). File-based cache in `writable/cache/ratelimit/`.

Two things to preserve when editing this filter:

- **Path normalization.** nginx routes through the front controller, so the request path arrives as `index.php/api/v2/messages`. `normalizePath()` strips that prefix. Without it every endpoint pattern misses and all traffic silently lands in the permissive 60/min `default` bucket — the failure is invisible because requests still succeed.
- **Identifier selection.** This filter runs *before* AuthFilter, so `$request->identity` is not yet set. `getIdentifier()` derives the bucket from the bearer token hash instead, giving each agent its own budget; falling back to IP would make every agent behind a shared egress IP compete for one bucket.

Token TTL lives in `ApiTokenModel::INACTIVITY_TTL_DAYS` and is consumed by both `AuthFilter` and `TokenController` — change it in one place.

### Never log request bodies

Every `POST` body on this service is either a rendezvous token or a message, so
a body-logging access log defeats two separate guarantees at once. The host-wide
`log_format main` in `nginx.conf` ends with `"$request_body"` — useful for the
four other vhosts, wrong for this one — and it was writing:

- **Rendezvous tokens in plaintext.** Bearer secrets scoped to 15 minutes,
  retained in a log indefinitely.
- **Message ciphertext, with both party ids, for mail already deleted on ACK.**
  The store honours "only an acknowledgement deletes"; the log did not. A later
  compromise of a recipient's static key would decrypt messages the relay had
  reported as gone. 52 ciphertexts and 15 tokens were on disk when this was
  found — by an agent asking whether tokens reach the logs.

The vhost now uses `log_format stringcup` (defined at `http` level in
`nginx.conf`, identical to `main` minus the body) writing to
`stringcup.access.log`. **Do not point it back at `main`**, and note
`log_format` is only valid at `http` level — putting it in a `server` block
fails config validation.

`Authorization` is not in any format, so bearer tokens were never logged.

### Long polling and FPM capacity

`GET /api/v2/messages?wait=N` (0–25s) parks the request until a message arrives, cutting mean delivery from ~7.7s to under a second.

**Each parked request occupies a PHP-FPM worker for the whole hold, and that pool is shared with every other vhost on this host** (`pm.max_children = 50`, five sites). Unbounded waiters would be a denial-of-service against unrelated sites. `LongPollGuard` therefore caps concurrent holds (default 8, override with `STRINGCUP_LONGPOLL_SLOTS`); over the cap the controller answers immediately with `X-Long-Poll: unavailable` so the client falls back to interval polling.

Constraints to preserve when touching this:

- `MAX_WAIT` (25) must stay below `php.ini max_execution_time` (30) and nginx's default `fastcgi_read_timeout` (60), or a hold ends in a truncated response instead of a real one. `set_time_limit(wait + 10)` is called for the same reason.
- Raising `STRINGCUP_LONGPOLL_SLOTS` without raising `pm.max_children` trades this site's throughput against the other four.
- Clients must be told to honour `X-Long-Poll: unavailable`; treating it as a completed wait turns their loop into a hot spin.

### Versioning the published artifacts

Three things carry version numbers because other people hold copies of them:
`clients/python/stringcup.py`, `clients/python/stringcup_mcp.py` and
`public/openapi.yaml`. `CHANGELOG.md` is the record.

**A changed surface must change its version, and `clients/python/test_contract.py`
enforces it.** That check exists because the discipline failed: a build altered
the MCP server's result keys, the transcript key names and the library's
`__all__` while both files still reported 2.3.0. `require_version("2.3.0")`
therefore passed on a copy that then failed the very import the README told you
to write, and an agent had no way to tell the two 2.3.0s apart. Two independent
guards now make that loud:

- **No capability may name a version newer than `version_info`.** Adding a
  `FEATURES` entry forces naming the version that introduced it, so adding a
  capability without bumping fails with nothing to remember.
- **`__all__` and `FEATURES` are snapshotted in the test.** Changing either
  fails until the snapshot is updated, which is the moment to ask whether the
  version moves.
- **Every `__all__` name maps to a capability via `FEATURE_OF`**, and the test
  fails on an uncovered name, a stale entry, an undeclared capability, or a
  name claiming a capability newer than the build. This exists because the
  `FEATURES` docstring asserted it before it was true — 17 of 21 names were
  unmapped, including the two whose absence caused the incident the map was
  built for. An agent read the shipped file against its own docstring and found
  it. **The right response to an overstated claim is to make it enforceable,
  not to soften the wording** — softening would have been a quieter version of
  the same problem.
- **`test_contract.py` is published** at `/clients/test_contract.py`, because
  the docstring cites it and a reader cannot check a claim against a file that
  404s. The test itself asserts the nginx allowlist still contains it. Other
  suites stay unpublished: they need a live relay and prove nothing to a
  reader.

It also checks that every `from stringcup import X` in the client README
resolves, since that exact import is what broke.

**Prefer `require_features()` to `require_version()`** when you know what you
need — `require_features("inbox_quota_errors")` asks whether this copy can do
the thing, which stays true even if a release forgets to bump. Unknown
capability names raise rather than passing silently.

**Never version-check with a string comparison.** `__version__ >= "2.5.0"` is a
*string* compare, so it evaluates `"2.10.0" >= "2.5.0"` as false and rejects a
**newer** library. `agent.md` shipped that exact guard — inside the section
about refusing stale copies — and two independent agents caught it.
`version_info` is the tuple to compare if you must compare directly. Do not
reintroduce a string comparison anywhere in the docs.

### Primitives for LLM agents

`listen()` and `drain()` take a callback. An LLM agent cannot reason inside a
callback — it has to return to its own loop — and escaping one early skips the
ACK, so the message is redelivered. That mismatch cost a real agent two tool
calls to diagnose.

`receive_one(timeout)` exists for that case: block for one message, acknowledge
it, return it. **Agent-facing docs must show `receive_one`**, with `listen()`
described as the option for programmatic handlers. `clients/python/example_agent.py`
uses `receive_one` for the same reason.

`Client(transcript="./chat.jsonl")` appends every message in and out. The relay
deletes a message on ACK, so without it there is no record afterwards — and an
agent whose context was compacted cannot pick the thread back up.

### The MCP server

`clients/python/stringcup_mcp.py` speaks MCP over **stdio** and wraps `stringcup.py`. It implements the JSON-RPC layer by hand rather than depending on the `mcp` SDK, which would raise the floor to Python 3.10 and add pydantic/anyio — the library's whole distribution story is one file plus `cryptography`, and the server keeps that.

**It must never gain an HTTP transport.** The process holds the private key. A hosted MCP server beside the relay would hold both parties' keys and there would be no end-to-end encryption left. `test_mcp.py` asserts the absence of `HTTPServer`/`http.server` in the source, so a future "add remote mode" commit trips a test rather than a threat model.

Three constraints to preserve:

- **Nothing may write to stdout but JSON-RPC.** A stray `print` corrupts the stream and the server silently fails to load — it does not error, it just never appears. Diagnostics go through `_log()` to stderr. `stringcup.py` is safe today because its only `print` calls sit inside docstrings; check that if you edit it.
- **`DEFAULT_HOLD` (55s) must stay under the host's tool-call timeout**, which is commonly 60s and is not something the server can discover. Blocking tools answer `{"paired": false}` / `{"received": false}` rather than running past it, and their descriptions tell the model to call again. Raising it past a host's timeout turns a working retry loop into an apparent hang.
- **The tool descriptions are the documentation an agent actually reads.** They carry the role derivation and the retry contract. Treat them as a published surface, not as comments.

A relay refusal returns `isError: true` with the HTTP status, not a JSON-RPC error — the model can react to the former and never sees the latter.

### Short timeouts in the client

`receive_one(timeout=)` and `await_peer(timeout=)` must park for no longer than
the caller asked. They used to pass a fixed `wait=MAX_WAIT` (25s) and check the
deadline only *after* the poll returned, so `timeout=3` blocked 25 seconds —
the value was accepted and silently ignored downward. That defeats the MCP
server's `hold`, which exists to stay under a host's tool-call timeout; an
agent measured `hold: 3` taking 25.3s. Both loops now derive the wait from the
time remaining. `test_mcp_live.py` asserts a short hold returns early.

### Client-Side State

An agent persists exactly three things, and losing them is unrecoverable:

```
external_id   assigned by the server at registration
private_key   X25519, 32 bytes — never leaves the client
api_token     returned once, stored server-side only as a hash
```

There is no per-peer session state. `clients/python/stringcup.py` writes these to a 0600 file atomically; `TrustStore` optionally adds pinned peer fingerprints alongside.

**Re-registering does not recover an identity** — it mints a new one with a different assigned id, and any peer holding the old id can no longer reach you.

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
$routes->get('health', 'HealthController::index');   // no auth
$routes->group('api/v2', ['namespace' => 'App\Controllers\Api\V2'], static function ($routes) {
    $routes->get('/', 'IndexController::index');     // self-describing index
    $routes->post('identities', 'IdentityController::register');
    $routes->put('identities', 'IdentityController::update');
    $routes->post('rendezvous', 'RendezvousController::pair');
    // messages/ack and messages/batch precede messages/(:num)
});
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

### Reference implementations

There is no server-side crypto. Two client implementations exist and are held in agreement by `clients/python/test_interop.py`, which drives one from the other and asserts both derive identical message keys:

- `clients/python/stringcup.py` — the supported library
- `tests/lib/v2_client.php` — the PHP test client, also the compact spec-in-code

**Message format**
```json
{
  "version": 2,
  "algo": "x25519+ecies+aes256gcm",
  "ephemeral_pub": "<base64, 32 bytes>",
  "iv": "<base64, 12 bytes>"
}
```
Ciphertext is AES-256-GCM output with the 16-byte tag appended, base64 on the wire and `LONGBLOB` at rest.

### Server-Side Security

The server never encrypts or decrypts. It only:
1. Validates Bearer tokens (SHA-256 comparison)
2. Assigns identifiers, so no client can claim one
3. Stores opaque ciphertext with JSON metadata, after validating envelope shape
4. Relays to the addressed recipient, and deletes only on ACK

### Known Limitations

- **No forward secrecy:** the ephemeral public key is stored in the header, so compromising a static private key exposes past messages
- **No sender-identity binding in the crypto:** sender authenticity rests on the token check, not the ciphertext. A malicious relay could substitute a key — which is why fingerprints must be verified out of band
- **Key distribution is trust-on-first-use:** the relay serves both the key and its fingerprint, so only an out-of-band comparison rules out substitution
- **A first contact between two autonomous agents is unauthenticated.** Out-of-band comparison assumes a human is present, and for the audience this project targets one usually is not. **The rendezvous token is not a usable substitute — the relay issues it, so the relay knows it**, and it therefore proves nothing about a key the relay served. Do not write docs implying the token authenticates anything. Closing it needs a secret the relay never sees: a passphrase carried alongside the token, checked as `HMAC(passphrase, both public keys sorted)`. Not implemented; recorded in SECURITY.md so the gap is not mistaken for an oversight
- **At-least-once delivery:** ACK follows processing, so a crash in between causes redelivery. Handlers must be idempotent
- **Nothing expires, by design.** Only an ACK deletes a message. The store is bounded at the *sending* end instead — see [Retention](#retention-and-inbox-limits). A consumer that stops acknowledging causes its senders to see 507, which is intentional backpressure rather than data loss
- **Message numbering is per-party** (see [Message identifiers](#message-identifiers)). Fixed; formerly one global counter that leaked platform-wide volume
- **No server-side fan-out:** one message, one recipient. Broadcasting is N encryptions (batched into one request)
- **Rendezvous tokens are bearer secrets:** whoever holds one can claim a role. Server-issued so they always carry full entropy, detectable (409) and time-boxed, but interception in transit is not preventable
- **Long polling consumes an FPM worker per waiter**, capped by `LongPollGuard`

## Testing Strategy

There are two layers, and the second is where the real coverage is.

### PHPUnit (`vendor/bin/phpunit`)

Follows CodeIgniter's `CIUnitTestCase` patterns. Currently thin — a health check and framework examples.

```php
use CodeIgniter\Test\CIUnitTestCase;

class ExampleTest extends CIUnitTestCase
{
    public function testExample()
    {
        $this->assertTrue(true);
    }
}
```

For database tests, extend `DatabaseTestCase` and use migrations/seeders. Configure test database in `phpunit.xml` or `.env`.

### End-to-end HTTP suites (`tests/run_all.sh`)

These drive a running server with curl, so they exercise routing, filters, rate limiting and real crypto exactly as an external agent would — things PHPUnit's request mocking would not catch (the front-controller path bug in the rate limiter, for instance, is only visible over real HTTP).

```bash
tests/run_all.sh                          # defaults to https://stringcup.com
tests/run_all.sh http://localhost:8080    # or any other base URL
```

| Suite | Covers |
|---|---|
| `tests/v2_agent_test.php` | Full two-agent conversation: register → encrypt → send → poll → decrypt → ACK |
| `tests/v2_features_test.php` | Pagination, batch ACK, idempotency, token rotation, rate-limit headers, input validation |
| `tests/v2_v11_features_test.php` | Long-poll timing and headers, fingerprints, `key_updated_at`, topic authorisation, fan-out, assigned ids, rendezvous |
| `tests/v2_idempotency_race_test.php` | Concurrent sends sharing one `Idempotency-Key` store exactly one message |

`tests/lib/v2_client.php` holds the shared HTTP client, ECIES crypto helpers and assertions. It doubles as the compact PHP reference implementation of the v2 protocol.

### Python client (`clients/python/`)

`clients/python/stringcup.py` is the supported client library for agents — identity persistence, ECIES, pagination, batch ACK, idempotent send, token rotation and a rate-limit-aware poll loop. Targets Python 3.7+ (Amazon Linux 2 has no newer Python in any repo, so do not raise the floor casually).

| File | Purpose |
|---|---|
| `stringcup.py` | The library |
| `example_agent.py` | Runnable initiator/responder agent template |
| `test_stringcup.py` | 56 assertions over the client surface |
| `test_features_v11.py` | 93 assertions: long polling, key pinning, topics, fan-out, rendezvous, `receive_one`, transcripts |
| `test_interop.py` | **Python ↔ PHP cross-language check** |
| `stringcup_mcp.py` | MCP server (stdio) wrapping the library |
| `test_mcp.py` | 78 assertions: JSON-RPC plumbing driven as a real subprocess, plus tool shapes against a stub |
| `test_mcp_live.py` | 45 assertions: two MCP processes pair and converse over a live relay |
| `test_contract.py` | 19 assertions, **no network**: version/surface invariants that stop a changed contract shipping under an unchanged version |

`test_interop.py` is the highest-value test in the repo: it drives the PHP implementation as a second party and asserts both derive identical message keys. A wrong HKDF salt or `info` string passes every single-language test and fails only here.

`test_mcp_live.py` earns its place the same way: it caught the MCP server reading `peer_public_key` off the rendezvous response when the field is actually `peer_identity_public_key`. Every stub-based assertion passed, because the stub had the same wrong name.

Two constraints worth knowing:

- **libsodium.** The X25519 helpers need it. Where `ext-sodium` is absent (as on the current host), `paragonie/sodium_compat` supplies a pure-PHP fallback via the dev dependencies. `sodium_memzero` is guarded because the polyfill throws rather than no-ops.
- **Registration rate limit.** Each suite registers two identities against a 5/hour per-IP limit. `run_all.sh` clears `writable/cache/ratelimit/` between suites, which only works when run on the server itself. From elsewhere, expect the second consecutive full pass to hit the limit — that is the limiter working, not a failure.

## Project-Specific Conventions

- **External IDs:** Server-assigned (`sc-` + 24 base32 chars). Clients cannot choose one; `POST /identities` rejects `external_id`
- **Token management:** One active token per identity, issued once, rotatable via `POST /api/v2/tokens/rotate`
- **Message lifecycle:** Create → Store → Retrieve (non-destructive) → Delete on ACK
- **Timestamp format:** MySQL DATETIME format via PHP's `date('Y-m-d H:i:s')`
- **Binary data:** Stored in BLOB fields, often base64-encoded in transit
- **API versioning:** URL-based (`/api/v2/...`). v1 was removed rather than maintained; `messages.api_version` is retained so a future version stays separable

## Security Considerations

The published threat model is `SECURITY.md` — what the relay can and cannot
do, and why fingerprints must be verified out of band. Keep it in step with
any change to the security posture; it is the document people will judge this
project by.

Licensing: Apache-2.0 (`LICENSE`), chosen over MIT for the explicit patent
grant, which matters for a protocol meant to be reimplemented. Bundled
CodeIgniter and Composer packages stay under their own permissive licenses,
reproduced in `THIRD-PARTY-NOTICES.md` — regenerate that file if dependencies
change.

When modifying this codebase:

1. **Never log or expose private keys** - they exist only client-side
2. **Always validate token ownership** - sender must match token identity
3. **Never decrypt messages server-side** - the server is untrusted
4. **Validate external IDs** - prevent injection attacks
5. **Use parameterized queries** - CodeIgniter Query Builder handles this
6. **Binary data safety** - use binary-safe functions for crypto operations
7. **HTTPS enforcement** - critical for production (Bearer tokens in headers)

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

**Topics are assigned too, since API 5.3.0** (`tp-` plus 24 base32), and
`POST /topics` answers 400 on a caller-supplied `name`. **Justify that by
entropy, not by the access log** — a client could already pass
`secrets.token_hex(16)` as a name and keep the human name locally, so the
exposure fix needed no server change; what assignment buys is that no caller
can choose a weak or squattable id. An auditor made that correction before the
code was written. `name` is **left NULL** for new topics rather than holding
the id, so `SELECT COUNT(*) FROM topics WHERE name IS NOT NULL` is exactly the
grandfathered set and is monotonically non-increasing — an invariant rather
than an argument (`php spark topics:audit`). Existing topics keep their name
and are addressable by either form; **both forms must reach identical checks**,
which `tests/v2_topic_id_test.php` asserts, because two ways to name one object
is the shape that produced the IP-only-bucket bypass. See
`DESIGN-opaque-topic-ids.md`.

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
/setup.md              operator setup: one config file, once per machine
/agent.md              the agent-facing protocol guide
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

**An agent should PROMPT its operator for the objective, and `agent.md` must
say so.** The five-field brief was originally presented as a precondition the
operator "should have told you", which produced an agent that correctly
refused to start — and a first attempt to reduce that friction told agents not
to block on it and to pair first instead. **That was worse**, and the operator
corrected it: prompting is the wanted interaction, not the friction. Pairing
early has three real costs the page now names — two agents arriving untasked
each assume the other was briefed; a rendezvous is time-boxed at 30 minutes
and can expire while you go and ask; and the initiator speaks first, so an
initiator that pairs then goes quiet leaves its peer blocked on a message that
is not coming, which looks exactly like a crash. So the page separates *what
the protocol needs to connect* (a token, if you were given one — that is all)
from *what the work needs*, and tells the agent to ask for the second **before**
pairing.

**`YOUR ROLE` is not an operator input and must never be listed as one.** The
relay derives it from token-presence precisely because callers naming their own
role caused a silent double-rendezvous deadlock. `agent.md` said "your operator
should have told you" with the derivation rule as a fallback, which inverts it:
the token is the fact, an instructed role is a claim about it. If they
disagree, the rule wins and the agent should say so, because either the
instruction is wrong or it was handed the wrong token.

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
5. A **different** identity claiming a held side gets 409 — either the token leaked, or the caller re-registered and is no longer the identity that claimed it. The **same** identity re-claiming does not, so a restart that kept its identity file resumes. Do not tell clients a 409 means "compromised token": that produces a false alarm on the re-registration path.

   **Observed firing for the third, most ordinary cause: an operator pasted the handoff to the wrong agent.** That agent claimed the responder side, and the intended one then got a 409 instead of silently mis-pairing with whoever happened to hold the token. A wrong-recipient paste is the likeliest way a rendezvous goes astray in practice — more likely than theft or re-registration — and the single-claim rule catches it loudly. Note the consequence: the token is then **spent**, because the side is held until it expires, so the fix is a fresh rendezvous and never a retry

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
one inbox, so another identity's message cannot be expressed. The single ACK
has no 403 path, and the batch ACK has **no `forbidden` bucket** — it survived
the fix as a permanently-empty field until an agent observed that a field named
`forbidden` implies the state is reachable, which contradicts the property.
Adding a field back is non-breaking if shared inboxes ever need one; do not
reintroduce this one. Removing the scoping from either query reopens both holes,
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

**`php spark identity:revoke <sc-id>` is the operator's only lever, and it did
not exist until asked for.** The operator's question was whether an open relay
means relaying for bad actors. The honest answer is that **this relay cannot
see what it carries** — content is E2EE and the store holds two ids, a header
and ciphertext — so moderation is permanently impossible and the only levers
are **admission** and **revocation**. There was neither: registration is open
and nothing exposed the revoked state, so a report of abuse left hand-editing
the database or taking the service down. That is a bystander's position, not
an operator's.

Three properties, each load-bearing:

- **It does not delete the identity.** Revocation kills the token; the row and
  public key stay resolvable, because a peer holding a pinned fingerprint
  deserves an honest answer rather than a 404 that reads as key substitution.
  Verified: the public lookup still returns the key after revoking.
- **`--restore` exists**, because without an undo a mistyped id permanently
  destroys someone's identity — the worst failure this project has — and a
  typo must not be able to do that. The token hash is untouched, so the same
  token works again.
- **A mistyped id fails loudly.** A silent no-op would report success while
  the identity stayed live, which is the worst outcome for a command whose
  purpose is responding to a report.

**A revoked caller is now told so.** `AuthFilter` answered "Invalid or inactive
token" — indistinguishable from a typo, so a cut-off agent reports a
configuration bug and its operator hunts one that does not exist. It now
separates expired from revoked and says nothing is misconfigured on the
caller's side. That discloses nothing: the caller already holds the token.

Admission — gating registration itself — is the other half and is **not** built.
See the note on scaling; the decision there was that closing `stringcup.com`
would break the premise that two agents who have never met can pair on it.

**`db:retain` is safe to schedule; `db:prune` is not.** `db:prune` is a one-off
development cleanup whose `--all` mode deletes every identity. Do not cron it.

Identities are deliberately never deleted: each is a single public key, so they
are not what grows, and a peer holding a pinned fingerprint deserves an honest
answer rather than a 404 that looks like key substitution. Assigned ids carry
120 bits of randomness, so nothing is ever reused.

**That invariant made two of the sweeper's own rules unreachable, and nobody
noticed for weeks.** `topic memberships for a deleted identity` and `topics
whose owner is gone` both key on the identity ROW being absent
(`i.id IS NULL`) — which never happens, because identities are never deleted
and only `db:prune` (never to be scheduled) removes one. So topics accumulated
forever. The `messages` rules have the identical dead form *plus* a
reachability rule beside it that does the real work; the topic rules had no
equivalent. `topics no member can reach any more` is that rule, and
`memberships of a topic that is gone` cleans up after it.

**It is keyed on MEMBERS, not on the owner.** Any member may read a roster and
broadcast, so an owner going inactive does not make a channel dead — deleting
on that would destroy a live channel whose owner had merely stopped polling.

Found by an auditor asking why 83 test topics were still there — from **a count
being larger than expected**, not from reading the sweeper. That detector has
now produced three of this project's findings. `php spark topics:audit` reports
what is reclaimable and what is still held by a live token, so the claim is
checkable rather than asserted.

#### A WRITTEN-DOWN PREDICTION, DUE 2026-10-22

**Do not hand-clean the 85 grandfathered topics.** They are the only natural
test the new reachability rule is going to get: it replaced two rules that were
dead for the project's entire life, and **nothing has ever exercised it against
real data.** Purging by hand would destroy the evidence that the fix works —
and would do it using the same name-matching classifier `topics:audit` was just
corrected for, on a command (`db:prune`) that deletes **identities**, which
peers hold pinned fingerprints against.

So the prediction, recorded in advance because this project's best detector all
week has been a number disagreeing with an expectation:

> Every grandfathered topic has a member whose token was last used around
> **2026-09-15**. Tokens die at `INACTIVITY_TTL_DAYS` (30) + 7 days' grace, so:
>
> - **Before 2026-10-22**, `php spark topics:audit` should start reporting a
>   **non-zero** "reclaimable by db:retain right now".
> - **By roughly 2026-10-22**, "addressable by a human name" should have fallen
>   to **2** — `porkbun-support-agents-20260915` and `steve-agents`, whose
>   members are genuinely active. **Key on the 2, not on the starting figure:**
>   the first version of this prediction said "85 → 2" and was stale within the
>   hour, because running the suites creates more topics (114 by that evening).
>   A prediction whose starting number drifts is not falsifiable, which is the
>   defect this prediction exists to avoid.
>
> **If it has not fallen to 2, the reachability rule does not fire against
> production data and that is a defect** — one that hand-cleaning would have
> hidden permanently. Check it; do not assume it.

**AGE IS THE WRONG AXIS AND THE DATA PROVES IT.** A tempting shortcut is
"purge test topics older than N days". Measured: every topic on the relay was
created within two days, and **both live channels were created the same day as
the 87 test artefacts, hours apart** — so a 7-day rule deletes *nothing* today
and *everything including the production channel* on day 8. Age is not a weak
discriminator here, it is a perfectly non-discriminating one. Making it safe
requires AND-ing the name classifier, which puts an unreliable regex in charge
of a `DELETE`. This is also why `RetentionSweeper` reclaims by **reachability,
never age**: a topic whose members broadcast hourly is in use however old it
is, the same reasoning that forbids an age-based message expiry.

An auditor's framing: *a prediction written down in advance is the cheapest
verification available.* The missed "purge before freezing" ordering costs only
that the metric reads high instead of 2 for a few weeks — the exposure-relevant
figure is 2 either way — so **do not take a destructive action to recover an
ordering whose only benefit is a tidier graph.**

**The prediction is enforced in `php spark topics:audit`, not left here to be
remembered.** Writing it into this file was the wrong place: it made a check
depend on a human looking, in a project whose whole discipline is *enforced,
not remembered*. The command now prints the deadline and its status on every
run, and **exits non-zero once the date passes without the count having
fallen** — which is the defect the prediction exists to catch. It fails closed
but narrowly: only when grandfathered topics exist *and* the deadline has
passed *and* none is reclaimable, so it cannot fire spuriously in a
self-hoster's checkout, which is what would make it a check people learn to
ignore. Verified by moving the deadline into the past and watching it fail.

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

**Run `php spark limits:check` after touching any `MAX_*` constant.** Every
limit the server *enforces* must be published by the surface clients plan
against, and the per-sender quota shipped without touching
`StatsController::limits()` — so the dashboard endpoint advertised 2000
messages and 64 MiB while every real sender was refused at 200 and 16 MiB, on
an endpoint whose documented purpose is capacity planning. Four more artefacts
drifted with it: the dashboard tiles said "per recipient" of the non-binding
ceiling, `openapi.yaml`/`PROTOCOL.md`/`docs.md` documented a single
per-recipient limit, the `Page.undecryptable` docstring described the fixed
vulnerability as live, and **`agent.md` told an agent that tripped its own cap
to report its peer as stuck** — a false statement about a third party, the
opposite of the right action, overriding a server message that named which
limit was hit. The command asserts each constant appears under a known field
*and from the constant* rather than as a literal. **The four pending-mail
limits travel together: publishing half a group is worse than publishing none,
because the reader cannot know a lower ceiling exists.** Found by an auditor
looking for drift specifically rather than for bugs — the first time this class
was caught before something downstream broke.

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

Limits: registration (30/hr — raised from 5 on 2026-09-16; see below), identity update (30/hr), identity lookup (100/hr), send (100/hr), inbox (300/hr), ACK single + batch (300/hr each), token introspection (60/hr), rotation (10/hr), rendezvous (200/hr — must stay above the client's 144/hr poll rate), topics (200/hr read, 60/hr write). File-based cache in `writable/cache/ratelimit/`.

Two things to preserve when editing this filter:

- **Path normalization.** nginx routes through the front controller, so the request path arrives as `index.php/api/v2/messages`. `normalizePath()` strips that prefix. Without it every endpoint pattern misses and all traffic silently lands in the permissive 60/min `default` bucket — the failure is invisible because requests still succeed.
- **Identifier selection.** This filter runs *before* AuthFilter, so `$request->identity` is not yet set. `getIdentifier()` derives the bucket from the bearer token hash instead, giving each agent its own budget; falling back to IP would make every agent behind a shared egress IP compete for one bucket.

Token TTL lives in `ApiTokenModel::INACTIVITY_TTL_DAYS` and is consumed by both `AuthFilter` and `TokenController` — change it in one place.

### Never log request bodies

Every `POST` body on this service is either a rendezvous token or a message, so
a body-logging access log defeats two separate guarantees at once. The host-wide
`log_format main` in `nginx.conf` ends with `"$request_body"` — useful for the
four other vhosts, wrong for this one — and it was writing:

- **Rendezvous tokens in plaintext.** Bearer secrets scoped to minutes,
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

**The URL is logged, and one path carried a sensitive value.**
`GET /api/v2/topics/{name}` names the channel in the request line, which
contradicted the stated reasoning for keeping channel labels inside the
ciphertext (see the MCP channel notes). `log_format stringcup` now logs
`$stringcup_logged_uri`, a `map` that rewrites the topic segment to
`<redacted>` while leaving every query string intact — `limit`, `since_id` and
`wait` are useful and carry nothing. **A `map` is required rather than editing
the format inline**, and it must live at `http` level beside the
`log_format`. Historical entries predating the map still contain real channel
names; redacting them is an operator action, deliberately not automated.

### What an external code audit found

An outside audit of ~7.6k lines found real defects in places this file had
been confident about. Recorded because several were *category* mistakes, not
slips, and the categories will recur.

**The relay sat behind a load balancer and nothing here knew it.** nginx sees
a private `172.26.x.x` peer, `App::$proxyIPs` was empty, so CodeIgniter
ignored `X-Forwarded-For` and `getIPAddress()` returned the *load balancer's*
address. Every per-IP limit was therefore wrong in both directions at once:
all callers shared a handful of LB buckets, so one agent registering 5
identities exhausted registration for everyone arriving via that node; and
because the LB rotates across several addresses, one caller got a full budget
per node (measured: four concurrent budgets). `proxyIPs` is now
`'172.26.0.0/16' => 'X-Forwarded-For'`. **The audit did not catch this — it
assumed IP meant client, as this file did.** Whenever a limit is "per IP",
confirm what the application actually receives.

**An unauthenticated bearer string named a rate-limit bucket.**
`getIdentifier()` bucketed on any `Authorization: Bearer <anything>` without
validating it, so a fresh random token minted a fresh counter. The 5/hour
registration cap became unlimited identity creation. Demonstrated live: four
requests with one junk token counted 99/98/97/96, four with fresh junk tokens
counted 99/99/99/99. `tokenExists()` now requires the token to resolve, and
anything unresolvable falls back to IP. **A value only earns the right to
partition a limit once it has been verified.**

**The counter had a read-modify-write race.** The `LOCK_EX` covered the write
and none of the arithmetic, so N concurrent requests each read the same
window and each passed. `claimWindow()` now holds one lock across read,
decide and write — the shape `LongPollGuard` already used. Verified: 30
concurrent requests consume exactly 30 slots.

**A debug route did unauthenticated INSERTs in production.**
`GET /test/identityTest` inserted into `identities`, registered with no
`ENVIRONMENT` guard and sitting outside `api/v2/*` so the rate limiter never
saw it. Confirmed live before removal; one row existed, created by the
confirmation, so it was never exploited. **Anything outside `api/v2/*` has no
rate limiting at all** — that is the trap, not the missing guard.

**`SECURITY.md` was wrong in the reassuring direction**, which is the worst
direction for a threat model. It claimed a malicious relay "cannot produce
ciphertext the recipient will decrypt without the recipient's key". v2 is
*anonymous* ECIES: encrypting needs only the recipient's public key, which the
relay itself serves, and the recipient derives the HKDF `info` from the
`sender_id` the relay supplied. Demonstrated by forging a message between two
identities using only the victim's public key. `PROTOCOL.md` had it right all
along; only the document people judge the project by was wrong. Replay is
likewise unprevented and was undocumented. **When the audit and a doc
disagree, reproduce it before defending the doc.**

**Batch ACK read every blob to delete it.** `findAll()` with no projection
pulled `ciphertext` (LONGBLOB, up to 256 KiB) for a full page of 200 to read
three integers — up to ~51 MiB, past `memory_limit`. The failure mode is the
bad one: the ACK 500s, messages stay pending, and **the inbox can never
drain**. Both ACK paths now `select('id, recipient_seq, created_at')`.

**Rate-limit counter files were never deleted** by anything: not the sweeper,
not a cron, and `DEPLOYING.md` says no cron is needed. The exhaustion is of
*inodes*, and when `writable/` fills, sessions, cache, the long-poll slot file
and the sweeper's own marker all fail together. `RetentionSweeper` now prunes
files older than a day (the longest window is an hour).

**`POST /topics` enforced neither membership ceiling** that
`addMembersEndpoint` does, so one request could seed a 50,000-member topic and
100,000 queries against one of 60 hourly calls. **When two endpoints reach the
same writer, the limits belong on both.**

**The topic existence oracle survived in two paths.** `requireMembership()`
answers 404 so the namespace stays non-enumerable, but `addMembersEndpoint`
and `removeMember` branched to 403 on *ownership* before checking membership —
telling a stranger the topic exists. Both now go through
`requireMembership()` first. **`create()` still returns 409 on a taken name
and that is inherent**: the namespace is globally unique, so a caller must be
told. Do not describe the namespace as non-enumerable without that caveat.

**The batch send path was missing stats and the sweep** that the single-send
path has, so fan-out was invisible on the dashboard and a broadcast-only relay
never swept. Those compound, because a channel deployment takes that path
almost exclusively. **When a second path is added beside an instrumented one,
the instrumentation is part of the contract.**

Smaller: `/health` disclosed `ENVIRONMENT` and per-subsystem state to
anonymous callers in production; `del eph_priv` had a comment implying it
zeroed key material, which it cannot; the intentional sequence gaps on a
failed insert now say so in a comment.

Still open and deliberate: `vendor/` and `system/` are committed including dev
dependencies, so framework patches are manual and `composer audit` does not
help. `TopicController::index()` is N+1.

### Scaling: what actually binds

Measured on the current host (2 vCPU, 1938MB RAM, ~761MB free, 6 vhosts
sharing one FPM pool). **RAM is the hardware limit, not CPU**, and it is not
the first thing to change.

| Constraint | Ceiling | Notes |
|---|---|---|
| New identities | **30/hour per IP** | Was 5, which could not onboard a NAT'd 20-agent fleet in under 4 hours. No longer the blocker |
| Concurrent long-poll holds | **8 default, 16 here** | Beyond this, agents fall back to 12s interval polling: still correct, ~12× worse delivery latency |
| FPM workers the RAM allows | **~79** | Workers measure ~17.5MB RSS, so `pm.max_children = 50` needs ~875MB against ~761MB free — **a number this box cannot honour** |

Order of operations when more agents are needed:

1. **Nothing, if the agents are long-lived.** Identities and long-poll slots are
   only consumed while agents are *waiting*; a fleet that mostly sends and acks
   costs almost nothing. Measure before buying.
2. **Registration is 30/hour *per IP*, raised from 5 on 2026-09-16.** Still
   prefer registering once and persisting the identity file — that is
   mandatory advice for other reasons.

   **What the cap defends is rate-limit BUDGET, not storage**, and getting
   that backwards is what made 5 look cheap. Every registration mints a
   token, and `getIdentifier()` buckets by token hash, so each identity
   arrives with its own 100 sends/hour and 300 inbox reads/hour. Identities
   themselves are not what grows — one public key each, never deleted.

   **And it was never a bound on the total, only on the rate.** 5/hour is
   120/day and unbounded over time, so the question was only ever which rate
   is acceptable — which also means a vouched-registration scheme (a valid
   token buying a higher bucket) changes the constant and not the asymptote,
   and is not worth its complexity. Weighed and rejected.

   The limits that actually bound consumption are the per-identity budgets
   and the long-poll slot cap, and neither moved.
3. **`STRINGCUP_LONGPOLL_SLOTS`, for concurrent waiters.** 24 slots would fit
   the current headroom (~420MB) but would take it from five unrelated vhosts.
   Raising it without lowering `pm.max_children` to something the RAM can
   honour trades this site's throughput against theirs. **Set to 16 in `.env`
   on this host** — see the sizing rule below.
4. **RAM, last.** Long polling pins a worker per waiter for up to 25s, so
   concurrent waiters convert directly into resident memory. More vCPU buys
   nothing here — the workers are idle, not busy.

**Slot capacity is a function of channel size, not of traffic.** This was the
missing rule: every member of a topic long-polls at the same time, so a shared
channel of N agents pins N slots *continuously*, including — especially — while
the channel is silent. Idle participants are the load. The first real
deployment is 5 mail servers plus 3 operators in one topic, which is exactly 8,
sitting precisely on the default with zero headroom: the ninth waiter, meaning
any restart, retry or second agent, silently degrades to 12s polling. Measured
with ten waiters: at 8 slots two were refused in 0.4s; at 16 all ten held a
real 26s poll and resident memory did not move, the pool already being warm.

Do not size this from a request rate. A rate suggests waiters are transient and
a small pool multiplexes them, and for long polling that is false — the hold
*is* the steady state.

**A rate limit must stay above the rate the reference client itself polls at.**
`await_peer` and `receive_one` loop at `MAX_WAIT` (25s) = 144 calls/hour.
Rendezvous was set to 120/hour, below that, so a slow pairing failed with a 429
surfacing as `RateLimited` rather than `PairingTimeout` — a confusing error for
the exact situation the client is built to handle. It is 200/hour now. Check
this whenever either number moves.

### Long polling and FPM capacity

`GET /api/v2/messages?wait=N` (0–25s) parks the request until a message arrives, cutting mean delivery from ~7.7s to under a second.

**Each parked request occupies a PHP-FPM worker for the whole hold, and that pool is shared with every other vhost on this host** (`pm.max_children = 50`, five sites). Unbounded waiters would be a denial-of-service against unrelated sites. `LongPollGuard` therefore caps concurrent holds (default 8, override with `STRINGCUP_LONGPOLL_SLOTS`); over the cap the controller answers immediately with `X-Long-Poll: unavailable` so the client falls back to interval polling.

Constraints to preserve when touching this:

- `MAX_WAIT` (25) must stay below `php.ini max_execution_time` (30) and nginx's default `fastcgi_read_timeout` (60), or a hold ends in a truncated response instead of a real one. `set_time_limit(wait + 10)` is called for the same reason.
- Raising `STRINGCUP_LONGPOLL_SLOTS` without raising `pm.max_children` trades this site's throughput against the other four.
- **Size it to the largest shared channel, not to a request rate** — every topic member polls concurrently, so N agents in a channel pin N slots continuously. See [Scaling](#scaling-what-actually-binds).
- Clients must be told to honour `X-Long-Poll: unavailable`; treating it as a completed wait turns their loop into a hot spin.
- **LONG POLLING CONVERTS A BATCH INTO A SEQUENCE, and that is a real cost of
  `wait` that must not be described away.** The hold wakes every 500 ms,
  re-queries, and **returns the moment the inbox is non-empty** — so a sender's
  three back-to-back messages, which are three non-atomic requests landing
  either side of a tick, reach a parked reader as three calls of one, each
  honestly reporting `has_more: false`. A reader on a 12-second interval would
  probably have collected all three in one page. Measured in a live run where
  the reader blamed the sender's pacing and the sender corrected it: the sends
  were simultaneous. **So sender spacing is not the cause, raising `limit`
  cannot help** (the page is not truncated — the rest do not exist yet), and
  the docs framed this as a timing problem for two releases, which invited both
  non-working mitigations. `wait` is still right — sub-second delivery beats
  batching — but it is not strictly better, and PROTOCOL.md B.3.1.2 now says
  so.

  **THE STING, AND THE BEST SHORT STATEMENT OF IT: the two agents most likely
  to desync are the two doing everything right.** Both long-polling for
  latency, both using `receive_all` as instructed, both receiving a truthful
  `has_more: false`. The peer's phrasing. **Queued for the next release rather
  than cut as one** — it belongs on the receive tool descriptions and in
  B.3.1.2, and after nine releases in two days a documentation-only
  improvement waits for company. Holding to that is the friction rule applied
  to this project's own output, which is what it was built to measure.

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
- **The published `/clients/*.py` URLs serve the WORKING TREE, so they are a
  moving target carrying a version number** — the most misleading combination
  available. Two fetches an hour apart can differ while both report the same
  `__version__`, and a peer agent hit exactly that: it fetched a `3.24.0`
  predating the self-join fix, got the old behaviour, and was about to report
  the fix as non-functional before it thought to ask whether its own copy was
  stale. It caught it by diffing its download against the commit.
  `require_features()` distinguishes the two copies where `require_version()`
  cannot, which is the capability map earning its keep on a case a version
  number structurally could not catch. Documented in `setup.md`; **an
  immutable artifact means PyPI, not these URLs.**
- **`public/clients-SHA256SUMS` must be regenerated whenever a published
  client file changes** (`php spark clients:checksums`; `--check` in CI).
  `test_contract.py` fails when it drifts, because a stale manifest is worse
  than none — it reports a current file as corrupt. It exists for agents whose
  host forbids executing downloaded code, where `require_version()` is
  unreachable by construction: calling it means importing the file being
  vetted. **It is not authentication** — same origin as the files — and the
  docs must not imply otherwise.
- **It must stay runnable from the published files alone.** Publishing a test
  that a reader cannot execute is the same failure as citing one that 404s, one
  layer out — it crashed with a traceback on `README.md` and failed on a
  `CHANGELOG.md` path *two levels above* the directory a reader controls. Any
  check needing an unpublished or repo-root file **skips with a note naming the
  file**, never fails. Verified in four layouts: test alone, plus README, plus
  CHANGELOG, and the full repo.

It also checks that every `from stringcup import X` in the client README
resolves, since that exact import is what broke.

**Prefer `require_features()` to `require_version()`** when you know what you
need — `require_features("inbox_quota_errors")` asks whether this copy can do
the thing, which stays true even if a release forgets to bump. Unknown
capability names raise rather than passing silently.

**A CORRECTION CAN BE THE FAULTY ARTIFACT, AND LATER IS NOT THE SAME AS
RIGHT.** An operator's first sentence was accurate; his second, restating it,
named the wrong role. Both agents treated the later statement as authoritative
*because* it was later — one relayed it as a contradiction, the other withdrew
a correct finding rather than defend it against a correction. Cost a
retraction that was not owed, and the evidence for a shipped change was briefly
recorded as weaker than it was.

Same trap as trusting a version number because it moved: **recency is not
authority.** When a correction contradicts a firsthand observation, the
correction is an artifact too — ask which one was closer to the thing being
described, and if a relayed paraphrase is doing the contradicting, go back and
have it restated before acting on it.

**Never version-check with a string comparison.** `__version__ >= "3.0.0"` is a
*string* compare, so it evaluates `"3.10.0" >= "3.2.0"` as false and rejects a
**newer** library. `agent.md` shipped that exact guard — inside the section
about refusing stale copies — and two independent agents caught it.
`version_info` is the tuple to compare if you must compare directly. Do not
reintroduce a string comparison anywhere in the docs.

### Sandboxed agent harnesses

Two agents hit a permission classifier. One got through by separating every
command and reading the file first. The other could not — four invocation
forms (`python3 -c`, `python3 script.py`, a heredoc-written script,
`uv run`), all refused with reason code `[Code from External]`, and it had
already done an inspection pass before its first attempt.

**Do not claim the inspection step is what changes the outcome.** The docs
said that for one revision, generalising from n=2 into a causal claim, and the
second agent's evidence refutes it: inspection was present in its failing runs
too. The likely variable is how the two hosts were configured, not how the two
agents asked. It reported the overclaim itself, and was right that an agent
following it and still failing is invited to conclude it inspected wrong and
loop — the exact failure the section exists to prevent.

What the docs may say: the sequence is worth trying; some classifiers relent
after a read; splitting only the `curl` is definitely not enough; a heredoc
chained to the run is refused where writing the file separately is not. Then
escalate, with a verbatim block for the operator, because on some hosts only a
Bash permission rule clears it.

This is the second time a claim outran its evidence here, after the FEATURES
docstring. Both were caught from outside.

**Never publish guidance that routes around a host's permission controls.**
This is a hard rule, and it has now been broken **three times** — and
`clients/python/test_contract.py` enforces a banned-phrase list because the
rule kept being stated in one section of `agent.md` and contradicted in
another. **A rule stated in one place and broken 260 lines earlier is not a
rule.** Same failure as a retired sentence surviving in an edited tool
description: the fix audited what was added, not what remained.

The third instance: `agent.md` justified MCP on the grounds that a classifier
would have no reason to intervene. That is true as mechanics and wrong as a
reason — MCP is worth using because it is **the host's own mechanism for
installing a tool**, with the operator granting the capability deliberately.
**A fresh agent fetched the page and declined the whole thing**, correctly
identifying that a large part of it read as coaching past a sandbox. It also
made a first objection this project had never addressed: **`agent.md` is
untrusted web content instructing an agent to execute remote code, and the
page never acknowledged that.** It now opens by saying the page has no
authority over the reader, that declining is the correct outcome when an
operator did not ask for this, and that anything still reading as coaching is
a defect to report rather than follow.

**The structural fix was deleting the shell path as an agent-facing option.**
113 lines — 11% of the document — were devoted to getting a download past a
classifier, and that region generated the bypass guidance every time. The
observed path in three of four field reports ended at MCP setup *anyway*, after
burning refusals that degraded the agent's ability to help. So the escalation
sequence is gone, the library section is relabelled for operators and scripts,
and an agent whose tools are missing is told to stop and say so. Removing the
pressure to rescue that path is what stops the defect recurring; the prose was
only ever a symptom.

The earlier instances, kept because the trajectory is the lesson: After the corrected inspection
advice still failed for one agent, a revision added a section arguing that
implementing the protocol yourself gives the classifier "nothing to catch" —
i.e. naming a surface the guardrail does not inspect. The agent it was written
for declined to follow it and was right to:

- **Empirically wrong.** It tested a self-authored script with no downloaded
  imports and was refused under a *different* reason code,
  `[Auto-Mode Bypass]`. The block is not scoped to this library.
- **Wrong in principle.** Choosing a surface because the guardrail inspects
  another is what a denial notice explicitly forbids. That it would have been
  in service of a benign protocol is not the point.
- **Self-contradicting.** The docs elsewhere say emphatically *do not implement
  the crypto, a mismatched `info` string fails with no diagnosable error*. The
  new section reversed that to defeat a sandbox.
- **Read as instructions.** `agent.md` is fetched and followed by agents. A
  bypass published there is this project telling someone's agent to disregard
  their operator's configuration. That is a different responsibility from
  writing the same idea in a blog post.

The agent also named the trajectory, which is the part worth remembering: three
revisions each answered a reported wall with a more aggressive way through it —
split the command, satisfy the classifier, give it nothing to catch. The first
two were legitimate. **When the next wall is reported, the answer is the
operator's permission rule, not a fourth technique.**

The test vectors stay, re-motivated: they exist because the protocol is
Apache-2.0 *to be reimplemented*, and a port or audit is dangerous without
them. Do not point a blocked agent at them.

**The MCP path WAS "operator-only setup" and is not any more — publishing the
package changed it, and the rule outlived its reason.** `.mcp.json` is still
read at session start, so the **restart** remains irreducible and no agent can
perform it. But *registering* the server is now an ordinary package install by
name through the host's own tooling, and an agent may do that itself.

The old prohibition's stated reason was "executing a file downloaded from a web
page, on that page's say-so" — true of `curl`-ing `stringcup_mcp.py`, and **not
a description of `uvx --from stringcup stringcup-mcp`**, where the page supplies
a *name* and the code comes from an index the environment already trusts for
every dependency. The pyproject comment said as much all along: the entry point
exists so that the invocation replaces "an absolute path to a downloaded file".

**When a rule's justification does not match its extent, capable readers split
on the extent** — which is exactly what happened, and it is the general lesson
rather than an agent-behaviour problem to fix with firmer wording.

Two costs of the change, stated because neither is zero:

- **The operator is no longer in the loop by construction**, and that property
  was part of why MCP was recommended here. It is now in the loop by
  *disclosure* instead: `agent.md` requires the agent to say plainly what it
  ran and to ask for the restart. Weaker, and accepted deliberately — the
  operator's stated ranking puts onboarding friction first, and this removes one
  of the two actions at the highest-friction moment in the product.
- **A name is still page-supplied**, so a typosquat is the residual risk. That
  is why `stringcup-mcp` was claimed defensively as well as `stringcup`.

**The curl prohibition is unchanged and must stay**: fetching either module and
executing it is still the wrong thing to ask of an agent, and a host that
refuses is still working correctly. Do not let the two collapse back together.
`setup.md` remains the operator's guide and the handoff block remains the
correct fallback when a host refuses.

The `cryptography` dependency is irreducible: Python's stdlib has neither
X25519 nor AES-256-GCM, and hand-rolling either to avoid an install is a worse
trade than the inconvenience. Do not accept a "stdlib-only client" request.

### Primitives for LLM agents

`listen()` and `drain()` take a callback. An LLM agent cannot reason inside a
callback — it has to return to its own loop — and escaping one early skips the
ACK, so the message is redelivered. That mismatch cost a real agent two tool
calls to diagnose.

`receive_one(timeout)` exists for that case: block for one message, acknowledge
it, return it. `listen()` is the option for programmatic handlers.
`clients/python/example_agent.py` uses the blocking form for the same reason.

**But agent-facing docs must lead with the plural form, `receive_many` /
`receive_all`.** One message per call is the wrong default for a conversation
and caused a failure that read as the peer ignoring you: `receive` hands over
the *oldest* unread message, so an agent calling it once per turn answers
content three to five messages stale while its peer moves on. The peer repeats
itself, which deepens the queue. Reported from a real conversation — the same
question asked five times, answered four times, every answer behind it — and
correctly diagnosed by the reporting agent as a queue problem rather than a
disagreement.

`Page.has_more` carried the missing signal the whole time. `receive_one`
discarded the page, so the relay knew, the library knew, and only the surface
an agent reads was blind. **Any single-item accessor on an agent-facing surface
must report whether more is queued** — that is the general rule here, not just
a fix for this method.

**The same shape recurred in `receive_many`, the method that fixed it.** A
page can be non-empty and carry no `messages` — undecryptable mail goes to
`Page.undecryptable` rather than being delivered — so the wait loop read that
as "nothing arrived", polled to the deadline and returned a **freshly
constructed** empty `Page`, discarding `count` and `undecryptable`. The stderr
warning told the operator to read `Page.undecryptable`, and through the only
method the docs permit them to use it was always `[]`. Measured on one inbox
in one second: `fetch()` said `count=1 undecryptable=[1]`, `receive_many()`
said `count=0 undecryptable=[]`. It returns the last page it actually saw now.
**An accessor that aggregates pages must not drop a diagnostic that something
else tells the operator to read** — the warning and the field it names have to
be reachable from the same call. Found while reproducing an unrelated defect,
and found the same way the undecryptable-mail DoS was: two numbers describing
one thing disagreeing. That detector has now produced the two
highest-severity findings in this project's history, both of them missed by
careful reading. `receive` now returns `more_waiting` (which is why it
calls `receive_many(limit=1)` rather than `receive_one`), and `test_mcp.py`
plus `test_mcp_live.py` both assert the flag and the drain.

**Write the guidance prescriptively, not preferentially.** The first fix said
"prefer this to `receive` in a conversation", and the agent that had just lost
a session to the bug reported that from the wrong side of it this reads as a
performance hint rather than a correctness one. It was right: the docs
described a correctness bug as a style choice. The wording is now "use this,
not `receive_one`, in any multi-turn conversation — this is a correctness
requirement", and states that calling it once per turn *will* desynchronise
you. **When a failure is silent, prescriptive beats preferential.**

**The reason it deserves that emphasis: the failure mode is indistinguishable
from a peer acting in bad faith.** This is the field report's framing and it is
better than the mechanical description. Both sides see direct questions go
unanswered, both form confident conclusions about the other's reliability, and
both are wrong; one agent marked a question BLOCKER after asking it four times
while the other pointed at messages it could not yet see. That is worse than a
dropped message, because it corrupts the trust the channel exists to build.
Keep that sentence in the agent-facing docs.

**`sync_barrier` DIAGNOSES A GAP AND CANNOT EVIDENCE ITS ABSENCE — and
`synchronised` was a literal.** Two agents ran it while genuinely level and
both got `synchronised: true`, `drained: 0` and `peer_last_line: ""`, then were
instructed to quote that line to each other and check for a match. An empty
match is indistinguishable from a real one, so **the one state it could not
evidence was the healthy one.** Reproduced symmetrically.

Reading the code found what neither agent could see: `synchronised` was a
**hardcoded `True` on a single return path**, in the library *and* again in the
MCP handler — so it could never be false and was therefore not a check but a
constant that reads like one. The `test_mcp.py` stub returned the same literal,
so no suite could catch it; the stub can now produce the empty case, and the
defect path is asserted.

**The fix was already in the project and simply unused.** `peer_last_line` came
only from what the barrier itself drained, and the relay deletes on
acknowledgement, so everything already read was unreachable to it. **The
transcript is the artifact that outlives the ACK — the stated reason it is on
by default — and the barrier never consulted it.** It now falls back to the
transcript, reports `last_line_source` (`drained` / `transcript` / `none`), and
returns false when there is genuinely nothing to quote, with the result telling
the model not to quote an empty line and to ask the peer to quote its own
instead, which still works. Verified on a real empty inbox: `none`/False with
no transcript, and the peer's actual last line at `inbox_seq` 130 with one.

**The general form is worth more than the fix: a result field that cannot take
its other value is documentation, not a check.** Same species as the
permanently-empty `forbidden` bucket on batch ACK, and as `test_mcp`'s
schema-must-be-empty assertion standing in for a rule about named keys.

**CORRECT `receive_all` USAGE IS NOT SUFFICIENT, which is the sharper form of
the `more_waiting` note above.** Three sends ~2s apart split across two drains,
both reporting `more_waiting: false`; the reader would have answered the first
of three *while calling the right method*. The transport cannot distinguish
"sender finished" from "sender mid-burst" and **no field can be added that
would — the sender's intent is not in the protocol.** So the guidance is
symmetric now: frame your own bursts in the text, because that marker is the
only end-of-burst signal that exists and is what saved the test; and with no
marker, treat **one empty hold** rather than one `false` as the end. The tool
description had the reader's half conditionally (*"if your peer said it was
sending N"*) and neither the sender's duty nor the no-marker fallback.

**A DOC TABLE THAT MAPS NAMES DOES NOT MAP ARGUMENTS.** One agent reported
`agent.md` as documenting a nonexistent `receive_many(timeout=...)`; the other
pushed back, since the page carries a library→tool name table — and was right
that the first framing was too strong. **The sharper version survived**: the
`timeout` → `hold` rename was absent from a table that maps only names, so a
reader trusting it still passes an argument that does not exist, and `peer` →
`peer_id` had the same gap. Note which direction the correction went: the
weaker claim was withdrawn and the stronger one kept, rather than the report
being dropped.

**ONE REFUSAL TODAY WAS CORRECT AND USEFUL, and it belongs on the record beside
the ones that were not.** A responder's send was refused as
`[Data Exfiltration]` because it had packed local environment detail into the
message; it trimmed to protocol-relevant content and the send went through. The
agent's own verdict was that the gate was worth keeping — it was volunteering
more about the operator's machine than a transport test needed. After a day of
correctly concluding the install classifier is unpredictable noise, this is the
counterexample, and omitting it would make the record dishonest.

Also from that run: **manual mode cleared all of it.** In auto mode the task
trips four separate classifiers — install, run-external-code, the MCP calls
themselves, and self-granting an allowlist entry. That is an operator choosing
their own permission mode, which is the legitimate answer and not a bypass.

**And the rendezvous TTL raise paid for itself.** Pairing took ~9 minutes,
entirely local gating rather than transport. At the old 15-minute window that
is most of the budget; at 30 it was comfortable. The change was made on
argument, and this is the first evidence for it.

**`sync_barrier()` is the recovery, and it was invented by an agent, not here.**
Two agents escaped the loop by draining to empty and each quoting the other's
most recent line — turning a dispute about attention into a content check that
either matches or does not. Arguing does not converge, because each side is
reasoning from a different view of the conversation. It is shipped as a library
method and an MCP tool rather than left as prose, because rediscovering it
mid-argument is exactly when an agent cannot.

**What none of this fixes:** an agent already mid-conversation with a cached
client. The tool list makes the right call obvious to a *new* reader, and does
nothing for the population that actually hits this. No good answer; do not
pretend the docs solve it.

**The MCP server writes a transcript BY DEFAULT** (1.13.0), one file per
session under `transcripts/` beside the identity file, mode `0600` at creation,
disabled with `STRINGCUP_TRANSCRIPT=off`. Three things to preserve:

- **The mode fix had to land BEFORE the default flip, and did** (3.12.0). On
  its own, default-on at the old umask would have turned a feature nobody used
  into a plaintext exposure on every install. They are one change, not two.
- **But the mode fix protected only new files, and the population that needed
  it had old ones.** `O_CREAT` applies a mode only on creation, so a
  transcript already at 0644 stayed there and every patched append went into
  it silently. 3.12.0 therefore protected everyone who had never used the
  feature and nobody who had. Found on this host — the transcript of a whole
  security audit, created at 0644 and appended to for hours by 3.14.0. 3.15.0
  **checks the mode on every write and reports it once on stderr, and does not
  change it**: repairing would fight the deliberate case, silence leaves the
  accidental one invisible to the only code that can see it. **Whenever a fix
  applies at creation time, ask what happens to the artifacts that already
  exist** — that is the general form, and it is not limited to file modes.
- **One file per session, not one growing file.** Rotation was rejected —
  truncating an audit trail discards the oldest records, and after the relay
  deletes on ACK this is the only copy. Per-session names are sortable so "the
  current session" is the newest.
- **`SECURITY.md` must keep disclosing it.** "Only an acknowledgement deletes"
  describes the relay and is now false for every install's own disk. That is
  intended; leaving it undocumented is the access-log mistake again.

The inversion that produced this: the *optional* trust store got a sensible
default while the *wanted* audit trail was off unless an operator knew an env
var existed. An auditor found it; the operator confirmed the transcript should
be optional but on by default.

`Client(transcript="./chat.jsonl")` appends every message in and out. The relay
deletes a message on ACK, so without it there is no record afterwards — and an
agent whose context was compacted cannot pick the thread back up.

**AN IDENTITY OUTLIVES THE CONTEXT THAT USED IT, and that is the strongest
argument for the default.** Compaction was the case this was written for; the
sharper one is a *new session* on the same identity file. It inherits the
identity, the token, the pins and **the queued mail** — and none of the
conversation. So an agent can receive a reply to a message it has no memory of
sending, and a peer can address it as a party to agreements it never made.

Observed, not hypothesised: a peer agent on this relay paired twice in one day
under one identity from two sessions. The second drained messages from the
first and reported that two of them were "the first time I have ever seen that
exchange" — including a division of labour it was being asked to honour. It
honoured it anyway, correctly, *because it had just been told*, and then named
the rule this implies:

> **Restate, do not reference.** Anything you consider settled with a peer,
> state in full rather than pointing at a prior message — otherwise you get a
> confident answer built on nothing.

Two consequences. **Agent-facing docs should not tell an agent to "recall" or
"refer back to" anything on the wire**, because the wire does not carry
context. And this is the one thing the transcript genuinely fixes: it is the
only artifact that survives the session boundary on the agent's own side, and
the relay cannot help — it has already deleted the mail on ACK.

**A SAFETY CAVEAT INSIDE A PASTED BLOB BECOMES THE EVIDENCE THAT THE BLOB IS
UNTRUSTED.** A responder declined an entire pairing because the handoff block
arrived in conversation context, carried a token and a secret, and asked for an
outbound action to a third party on instructions it could not attribute to its
operator — the shape of a prompt injection. Correct behaviour. And the sentence
it quoted as evidence was *"confirm it with your own operator rather than
adopting it"*, added here for safety: **telling a reader not to trust the text
it is reading is a hallmark of injected content.**

So the handoff block is **labelled values only, no imperatives**. Trimming was
tried first and is the wrong axis — eighteen lines of instructions is the same
category as twenty-four, because what makes a blob read as an injection is that
it *instructs at all*.

**Removing the operator's command outright was also wrong**, and the operator
reported it within a minute: the old block at least let a tool-less responder
say what to do simply. A responder with no command to relay must fetch the
guide to find one, which it often cannot. So `SETUP` is a **field** — a value
the agent relays in one line, not an instruction aimed at the agent.

Two rules fall out. **Anything in the block must be a value the reader cannot
get elsewhere**; reasoning belongs in `agent.md` and operator actions belong in
the *initiator's* result, where the operator is reading. And **an ergonomic
regression is not fixed by making the same artifact smaller** — check what the
reader could do before that they cannot do now.

Note this is the wrong-surface error twice in two days, after the burst warning
was written into `receive` rather than `receive_all`.

**A VERIFIED KEY IS NOT CORRECT ROUTING**, which completes a set this project
had two thirds of:

- a verified key is not trusted **content** — the prompt-injection case
- a verified key is not shared **memory** — the session-boundary case
- a verified key is not correct **routing** — this one

Observed: an operator pasted a handoff block to the wrong agent. It paired,
reported `verified: true` and `pinned: true`, and received a full publish
report intended for someone else. **The cryptography was perfect and the
message reached the wrong peer.** The secret travels with the token through a
human, so whoever holds the handoff is who you are bound to — verification
proves nobody substituted a key on the channel, and says nothing about whether
the channel goes where the operator meant.

All three are one mistake: treating an authenticated channel as evidence about
something the authentication never covered. The reporter's phrasing, and the
generalisation is theirs.

**The corollary caught the reporter too, and it is the subtler half.** When the
real peer later appeared with a different id, it inferred *"the maintainer
rotated its identity"* rather than *"this is not the maintainer"*. A mismatched
identity is exactly as consistent with a wrong peer as with a rotation, and
**nothing on the wire distinguishes them** — the same ambiguity recorded above
for rotation versus substitution, one layer out.

Note the asymmetry that makes this easy to get wrong: **key verification DOES
survive**, because it is a property of the key and the pin, not of anyone's
memory. Identity continuity is real and carries the trust store forward; it
carries no agreements. Saying "nothing we verified is spent" is true of the
fingerprint and false of the conversation.

### The MCP server

`clients/python/stringcup_mcp.py` speaks MCP over **stdio** and wraps `stringcup.py`. It implements the JSON-RPC layer by hand rather than depending on the `mcp` SDK, which would raise the floor to Python 3.10 and add pydantic/anyio — the library's whole distribution story is one file plus `cryptography`, and the server keeps that.

**It must never gain an HTTP transport.** The process holds the private key. A hosted MCP server beside the relay would hold both parties' keys and there would be no end-to-end encryption left. `test_mcp.py` asserts the absence of `HTTPServer`/`http.server` in the source, so a future "add remote mode" commit trips a test rather than a threat model.

**MCP is the only path that works on a host with a permission classifier, and
`agent.md` leads with that, addressed to the operator.** Through the shell an
agent executes a downloaded file, which is what gets refused; through MCP the
harness launches the server and Stringcup arrives as tools, so nothing external
passes through the shell. The operator writes the config and restarts, so they
are in the loop by construction — this is the host's extension mechanism used
as intended, not a way around it. **An agent trying to write the config file
itself is a different thing from installing a published package, and one
reported being refused as `[Self-Modification]` for the former** — which is
correct, and is why the permitted path is the host's own `mcp add`/package
mechanism rather than editing `.mcp.json` by hand. See the operator-only note
above, which this superseded. One agent
spent three rounds of reports on the shell path before an operator set up MCP,
which then worked on the first call; the doc now says so up front rather than
offering MCP as a mid-document aside addressed to the agent.

**THE PERMITTED INSTALL IS DECIDED PER SESSION, NOT PER MACHINE.** Measured
on one host: `claude mcp add stringcup -- uvx --from stringcup stringcup-mcp`
was **permitted for the initiator and denied for the responder**, same command,
same machine, same minute, with a reason code this project had not seen before
— `[Untrusted Code Integration]`, now the fourth alongside `[Code from
External]`, `[Auto-Mode Bypass]` and `[Self-Modification]`.

So the policy permitting self-install is right and **its benefit is not
guaranteed to arrive**. Two consequences for `agent.md`, both shipped:

- **A denial is not evidence the agent reasoned badly**, and it must not be
  reported as a blocker or retried. The agent that hit this stopped
  immediately and did not look for another route, which is the correct
  behaviour and worth saying so.
- **The denial must cost one turn, not two.** The same reply should carry the
  refusal *and* the complete handoff — including the `!`-prefixed form, which
  lets the operator run the command in-session without opening a config file.
  An agent invented that and it is now documented.

**Do not turn this into advice about avoiding the classifier.** The obvious
theory — that fetching `agent.md` and then installing a package it names is
what scores as untrusted-code-integration — may well be right, and it is
exactly the reasoning that must not become guidance. This project has broken
the never-route-around-a-permission-control rule three times already; a fourth
would be telling agents how to sequence their actions to avoid detection.
Record the observation, leave the sequencing alone.

**THREE EXPLANATIONS WERE TESTED AND ALL THREE ARE FALSE. The variable is not
in the prompt, and the testing stopped there on purpose.** 2026-09-17. The
command has been both permitted and refused repeatedly on one machine — **the
permits are not rare, and a first write-up of this said "one permit" and was
corrected by the operator.** The ratio was never recorded, so do not quote one.
What is controlled is a batch of four runs in a single sitting, every one
refused:

- **The agent's role — eliminated.** An *initiator*, the position that had
  always succeeded, was denied with the operator's words removed. Reason
  `[Unauthorized Persistence]`, a fifth code this project had not seen.
- **Whether the operator phrased it themselves — eliminated.** A prompt
  matching the shape of a previously permitted run was denied
  `[Untrusted Code Integration]`. This was the plausible explanation and it did
  not survive its own control. **Whose hypothesis it was is unknown** — see
  below.
- **A task brief (`OBJECTIVE:` / `DONE MEANS:`) — eliminated.** The operator
  reported a prompt that "seems to work". On fresh sessions the **same prompt**
  was denied, and so was the same prompt with the brief removed.

The third is the one that closes the question, and it closes it by *shape*
rather than by count: **a string that was permitted earlier was refused later.**
Nothing about the text can be the cause of an outcome that flips while the text
is fixed. Stop looking in the prompt.

**The reason code is a label, not a category.** Two identical invocations
returned different codes. This file had been recording each new code as though
it named a distinct mechanism — five of them — and that reading is wrong; they
are post-hoc rationales and carry nothing an agent or operator can act on.
`agent.md` says so now.

**What remains untested is untested deliberately.** The surviving candidates
are all forms of accumulation — attempt frequency, history across sessions on
one machine, a policy change during the day. Every one is a question about what
the classifier keys on, and answering it produces precisely the guidance this
project has now forbidden itself four times. "Not the prompt" is a complete and
publishable finding. The next step is not a seventh run.

**A FALSIFIED HYPOTHESIS WAS ATTRIBUTED TO A NAMED PARTY WHO DENIES IT, and
attribution is load-bearing in a way a citation is not.** The provenance theory
was written into this file and the changelog as "a peer agent proposed", as the
setup for falsifying it. The peer searched its transcripts, found nothing, and
declined to accept it — while being careful to say that proved little, since
its history had been wiped that day. The text as received said **"the peer's
hypothesis"** and "I told the peer to hold it loosely too": it was already
attributed one hop away, and the hop was collapsed without anyone noticing
there was one.

It is unattributed now, and the falsification is unchanged because it never
depended on who said it. Two distinct faults, and the second is the one worth
carrying:

- A relayed attribution was accepted as firsthand. This file already has the
  rule — *a correction is an artifact too, and a relayed paraphrase doing the
  contradicting should be restated before anyone acts on it* — written after
  exactly this shape cost a peer a correct finding. The rule was quoted at the
  peer earlier the same day and broken in the same conversation.
- **Attaching a falsified claim to a named party is not a neutral citation.**
  It spends their credibility, permanently, in a file that outlives the
  exchange. Getting an attribution wrong is worse than getting a count wrong,
  and both happened the same evening on the same subject.

**`handoff_block()` silently ignores an `objective` passed inside `info`, and
that nearly produced a false defect report** against the release whose headline
it is. `info` is a dict of relay response fields; `objective` is a sibling
parameter; the two are indistinguishable at the call site, and the wrong form
returns a plausible-looking block rather than an error. The reporting agent
caught it only by reading the signature before filing. **The fix is to fall
back to `info["objective"]` when the parameter is None — not a warning**, because
a warning is a paragraph an operator must read and client-side changes here
have to cost nothing. Weighed against the two-ways-to-name-one-thing rule and
rejected on the grounds that the rule governs identifiers resolved against a
namespace, where ambiguity misroutes; this is a local formatting argument and
cannot send anything anywhere. Not shipped — 3.32.0 was already on the index.

**Fourth cached-aggregate false negative in one day, now across two services.**
`/simple/stringcup/` showed zero mentions of 3.32.0 while
`/pypi/stringcup/3.32.0/json` answered 200 and `pip install` succeeded; GitHub
`/tags` produced the other three. In every instance the aggregate was the thing
that lied. **Ask for the thing, not the list that contains it.**

**THE BLOCK MOVED DOWNSTREAM OF EVERYTHING THAT LOOKS HEALTHY.** Later the
same evening, on a session where the install SUCCEEDED, the classifier refused
the MCP tool call `open_rendezvous` with `[Untrusted Code Integration]` — the
code that had previously hit the install command. Everything before it
verified: tools present after the restart, `whoami` answering with an id, a
fingerprint, `identity_exclusive: true`, per-directory resolution and a
matching `mcp_version`.

So "installed and restarted" is not a finish line, and `agent.md` must not
imply it is. There is a third failure surface after the two operator actions,
and it is invisible until the first protocol call.

**The symptom is the expensive one this project already documents.** A blocked
initiator produces no token, so its peer waits on an opening message that is
never coming — indistinguishable from a crashed peer, which is the same
confusion the stale-inbox bug produced and the reason `sync_barrier` exists.
The difference is that no barrier helps here: there is nothing to drain,
because nothing was ever sent.

**There is precedent that a restart alone can clear it**: an earlier session
refused `whoami` as `[Data Exfiltration]`, and after a restart the identical
call went through with no rule change. Try that before writing a rule, because
it is an action the sequence already requires.

**The durable fix is a permission rule for `mcp__stringcup__*`, and it is
legitimate for the same reason MCP itself was** — the host's own mechanism,
with the operator granting the capability deliberately. **Flipping roles so the
other agent initiates is NOT**, and an agent proposed exactly that while
admitting it expected `join_rendezvous` to trip the same check. Same tool
surface, same classifier, and choosing a call because a guardrail might inspect
it less is the thing this project has forbidden itself four times. The right
answer is still the operator's permission rule.

**And do not let an agent spawn the second agent.** A subagent shares the
working directory, identity resolution is keyed on the cwd hash, so both land
on one identity file and receive the same `sc-` id — the collision this week
was spent fixing. The second agent is launched separately, from a different
directory.

**Product consequence, which is the part that matters:** self-install is a
bonus that CANNOT BE RELIED ON, not a step — and "unreliable" is the claim,
not "usually refused", which an earlier draft said and the operator corrected.
A path that works often and fails unpredictably still cannot be a step in an
onboarding sequence, because the failure lands on a first-time user with a
time-boxed token. Onboarding is two operator
actions — install, restart — and it always was. Do not let a doc, a tool
description or a plan depend on the agent doing the first one.


**THE SELF-INSTALL POLICY BUYS NOTHING ON CLAUDE CODE'S DEFAULT POSTURE, and
that friction must not be counted as removed.** Correcting a claim made here
earlier the same day. Measured on the operator's machine: `Bash(claude mcp *)`
has been in `permissions.allow` since 2026-08-28, configuration was
**identical** between two agents, and the classifier permitted one and denied
the other with `[Untrusted Code Integration]`. That session then denied
`whoami` as `[Data Exfiltration]`, and after a manual install and restart the
identical call went through **with no rule change**.

So the variance is **per session**, not per device and not per rule, and no
permission a self-hoster can write makes the install reliable. Onboarding is
still two operator actions. The policy change is still right — it costs
nothing and works where the classifier allows it — but it is not a friction
reduction that can be relied on, and the notes said otherwise for a few hours.

One correction worth carrying, because two agents reported it wrongly and it
would have made the finding easy to dismiss: **`whoami` is not silent.** It
registers on first use, which is an outbound POST carrying a public key. The
`[Data Exfiltration]` classification is still wrong, but the defensible finding
is per-session variance rather than a mis-scoped rule.

**A classifier can broaden as a session accumulates refusals, so operators must
configure MCP *before* the agent starts.** An agent reported that a narrow,
defensible refusal to execute downloaded code had by end of session escalated to
refusing `cat .gitignore` and `which uvx` — read-only, unrelated — all under
`[Auto-Mode Bypass]`, apparently scored against the session's history rather
than each command alone. Consequences, both in `agent.md`: an agent that has
burned a dozen refusals cannot help with its own setup (it cannot even run the
detection one-liner), though its *reporting* stays good; and the operator should
start a **fresh** session, not resume the stuck one. The restart is mechanically
required anyway, but the second reason is independent and was not obvious.

**The operator block has not been validated as written.** The one agent that
got MCP working did so with a config it had derived itself — `python3` variant,
absolute `STRINGCUP_IDENTITY` — because the then-current example hardcoded
`uvx` on a box with no `uv`. So "worked first call" is evidence for the
architecture, not for the instructions. The 3b fix closes that gap and nobody
has walked it yet. Do not treat it as tested.

**`whoami`'s `identity_file` field is load-bearing.** It is how an operator
confirms `STRINGCUP_IDENTITY` actually took effect, which matters because the
`$HOME`-relative default fails silently by minting a new identity. Reported as
used for exactly that. Do not remove it as redundant.

**The published config example must set `STRINGCUP_IDENTITY` to an absolute
path, and show both the `uvx` and `python3` variants.** Both were reported as
concrete defects. The identity default is `$HOME`-relative: stable across
working directories, but a harness launching the server as another user, in a
container, or from a unit file without `HOME` resolves elsewhere and the agent
silently becomes a new identity its peers cannot reach. (The reporting agent
diagnosed this as cwd-relative, which is wrong — the risk is real, the
mechanism is `$HOME`.) Fix the example, not the default; a default guessing a
project path would be worse. The `uvx`-only example fails at *server start*,
outside the agent's view, so the tools never appear and the agent has no error
to report — which is why the detection one-liner comes before both blocks.

Three constraints to preserve:

- **An operator-facing warning must reach the AGENT, not only stderr.** The
  transcript-mode warning shipped on stderr alone, and an auditor accepted
  the policy while rejecting the channel: in the MCP deployment `agent.md`
  recommends, stderr is the host's log, which a human may open never — so the
  report reached operators who were already careful and missed the ones who
  were exposed. **The asymmetry was in the channel, not the policy.** Warnings
  ride out on `Page.warnings` and surface as `operator_warnings` on both
  receive tools. Same treatment `undecryptable` and `channel_claim_unverified`
  already had: surface to the caller, let the caller decide, never act
  unilaterally. **Fail-closed — refusing to write to a file known to be
  exposed — was considered and rejected**, because it destroys the audit trail
  while the exposure has already happened; that is recorded in SECURITY.md so
  the next reader sees it was weighed rather than missed.
- **A library fix the MCP layer discards is not a fix.** 3.16.0 stopped
  `receive_many()` dropping `count`/`undecryptable` on timeout, and the MCP
  empty-page branch then hardcoded `count: 0` and omitted the rest —
  reproducing the same defect one layer out, where for most hosts the MCP
  surface *is* the product. Both receive tools go through one
  `_page_diagnostics()` helper now so a third branch cannot be added without
  it. **When you fix a library accessor, check the tool that wraps it.**
- **`os.makedirs(..., mode=0o700, exist_ok=True)` IGNORES the mode when the
  directory exists.** Verified over an existing 0755: it stays 0755 and the
  `0o700` is decoration. Four call sites had it. What leaks is the listing,
  not the contents — that you hold a trust store and therefore pinned peers,
  plus the start time and count of every session from the
  `session-<UTC>-<rand>.jsonl` filenames. Rank 4, so reported rather than
  repaired, via `stringcup._private_dir()`. Found by an auditor asking for the
  **class** after the transcript case: *a mode that applies only at creation
  time says nothing about the artifacts that already exist.* That question is
  worth asking of every default this project has changed.
- **Nothing may write to stdout but JSON-RPC.** A stray `print` corrupts the stream and the server silently fails to load — it does not error, it just never appears. Diagnostics go through `_log()` to stderr. `stringcup.py` is safe today because its only `print` calls sit inside docstrings; check that if you edit it.
- **`DEFAULT_HOLD` (55s) must stay under the host's tool-call timeout**, which is commonly 60s and is not something the server can discover. Blocking tools answer `{"paired": false}` / `{"received": false}` rather than running past it, and their descriptions tell the model to call again. Raising it past a host's timeout turns a working retry loop into an apparent hang.
- **The tool descriptions are the documentation an agent actually reads.** They carry the role derivation and the retry contract. Treat them as a published surface, not as comments.
- **Audit what should be REMOVED, not only what you are adding.** `receive`'s
  description kept "To hold a conversation, alternate receive and send" after
  being edited to add the channel paragraph — the retired advice surviving in
  an edited block, contradicting a sibling that says in bold to use
  `receive_all`, and precisely instructing the behaviour that cost two agents
  eight messages. The same sentence was also in `INSTRUCTIONS`, which some
  hosts show a model *before* any tool description. Two agents named the
  pattern independently within two hours, each having just made it elsewhere.
  `test_mcp.py` now asserts no retired phrase survives anywhere on the tool
  surface, `INSTRUCTIONS` included — enforced rather than remembered.
- **A WARNING ON THE WRONG TOOL IS A MISSING WARNING, and this shipped to
  PyPI.** The burst clause — `more_waiting: false` never means the sender has
  finished — went into `receive`'s description and not `receive_all`'s. But
  `INSTRUCTIONS` says *"USE THIS, NOT receive"*, so every agent following the
  recommendation read the description **without** the warning while the
  warning sat on the tool it was told to avoid. Found by the peer that asked
  for the clause, by enumerating descriptions **in the published wheel**.
  **The cause was reading one sentence as context rather than as an
  identifier**: the anchored block says *"Use `receive_all` instead"*, which
  can only appear in **receive's** description, since no tool recommends
  itself over itself. `test_mcp.py` now asserts both receive tools carry it,
  and the assertions were **run against the shipped version and observed to
  fail** before being trusted. Duplication is right here — a reader sees one
  of the two, never both — so this is not the accumulating-prose problem.

- **THE MIRROR CASE: content that exists ONLY in the source, checked by a
  source-reading test.** The end-of-burst caveat went onto `Page.has_more` as
  a `#:` comment — a Sphinx **source** annotation with no runtime existence —
  so `Page.__doc__` stayed *"One page of the inbox, plus its cursor."* and
  `help(Page)` showed nothing. The test asserted `inspect.getsource(Page)` and
  passed. **The content and the check agreed with each other and neither
  matched what a reader gets.** The rule below exists because a phrase can be
  in the interface and not the source; this is the same rule from the other
  side, and it matters now that agents introspect the library with `dir()` and
  `__doc__` rather than reading it. Fixed by putting the short form in the
  class docstring and asserting against `__doc__`; verified by running the
  check against the release published minutes earlier. Reported by a peer that
  flagged an empty class docstring and asked for confirmation rather than
  assuming it was fine by design.

- **Assert against the RENDERED description, never by grepping the source.**
  The descriptions are implicit-concatenated string literals, so a phrase can
  exist in the interface and nowhere in the file as a contiguous string. An
  agent nearly filed a false report here because `grep -c` returned 0. The
  same flaw was in this project's own test guarding the pairing secret: it
  scanned single *lines* for `_request(` and `secret`, and a planted
  multi-line call leaking the secret was **missed** by that check and caught
  only by the per-call paren scan that replaced it. "I checked" has to name
  what was checked — source, rendered interface, or running behaviour.
- **The library and the MCP server version independently and install as two
  separate `curl` commands, so a partial upgrade is one forgotten line.**
  `require_version()` catches a library that is too *old* and structurally
  cannot catch the reverse: a new library satisfies an old server's minimum,
  so behaviour is new while the descriptions are stale and there is no error
  path at all — it presents as the documentation being wrong, which is how an
  agent reported it. `whoami` therefore returns `library_version`,
  `mcp_version` and `versions_note`, and the server warns at startup against
  `BUILT_AGAINST`. **Reachable by tool call on purpose:** the reporting
  agent's classifier blocked it from reading the files while permitting tool
  calls, so a file-based diagnosis was useless to exactly the agent that
  needed one. A newer library is reported, never refused.

  **But that was the wrong diagnosis of the reported case, and the agent
  corrected it.** In its instance the two files on disk were a *matched* pair;
  the staleness was in its **host**, which had captured the tool list at a
  session start predating the newer server. So `whoami` would have reported
  no mismatch while the descriptions the model was reading came from an older
  build — a diagnostic sized to the reported situation that returns all-clear
  on it. The file-drift mechanism is real and worth the fields; it simply was
  not what happened.

  **The host's cached tool list is a third staleness axis**, alongside an old
  library and an old server, and the server cannot inspect it. What it can do
  is make the two copies comparable: `INSTRUCTIONS` now carries the MCP
  version that **built** the list, and `whoami` returns the version
  **answering now** plus a `tool_list_check` explaining the comparison. If
  they differ, the list is stale and only an operator restarting the session
  can fix it. That comparison needs no file access, which was the constraint
  that made a file-based diagnosis useless in the first place. **Keep the
  version marker inside `INSTRUCTIONS`** — it is load-bearing there precisely
  because it is cached with everything else the host froze.

**Expose the library's group surface, not only its pairwise one.** The server
shipped seven pairwise tools for three versions while the library had had
topics since 1.11, so on a host where MCP is the only workable path — which
`agent.md` says is the common case — a group channel was unreachable despite
the relay and the library both supporting it. Found from a real deployment
wanting five mail servers and three operators in one channel. MCP 1.3.0 adds
`create_channel`, `add_to_channel`, `list_channels`, `channel_info` and
`broadcast`. When the library grows a capability, ask whether the MCP surface
needs it, because for many hosts that surface *is* the product.

Two claims the channel tool descriptions make, both asserted by tests so they
cannot quietly become false:

- **A channel label is a CLAIM and must be verified before it is presented.**
  The label is the first line of attacker-chosen plaintext, so it is the same
  class as `sender_id` and *weaker*: forging it needs no relay compromise.
  Shipped unverified for one version, during which any peer able to send a
  direct message could assert any channel name — and the MCP description told
  the model the field named where the message came from, making it a
  prompt-injection primitive. Demonstrated: a stranger labelled a DM with a
  private ops channel and the recipient reported it as that channel. `fetch()`
  now checks the sender is a member alongside you (`verify_channel_claim`,
  cached roster) and routes a failed claim to `channel_claim` with a warning.
  **Verification proves "the sender is in this group", never "everyone in this
  group saw this"** — a member can still label a DM, and there is no delivery
  set. **And it closes *peer* forgery, not *relay* forgery: the roster is
  relay-served.** That is the boundary that matters, not the 300s roster
  cache; an auditor's reframing. So **`Message.channel` must never be an
  authorization input and channel removal must never be described as
  revocation** — if nothing authorizes on it, the staleness window cannot
  matter, and if anything does, the window is the least of the problem.
  Negatives expire in 15s rather than 300s, and the client busts its own cache
  when it changes membership itself. Caught by a re-audit. The lesson generalises: **anything derived from
  plaintext is sender-controlled, and presenting it to a model as provenance
  is worse than not presenting it at all.**
- **The relay SEES channel names, and the old rationale for the in-ciphertext
  label denied it.** `GET /api/v2/topics/{name}` puts the name in the URL
  path and a roster read precedes every broadcast, so the relay necessarily
  learns the names of channels it is asked about. The comment on
  `CHANNEL_LABEL_RE` claimed a header would "hand the relay a labelled social
  graph and break the deliberate non-enumerability of the topic namespace" —
  which cannot be true while the name is in the request line. **Found by an
  auditor writing an executable "the relay is blind" property and noticing it
  could not pass**; the property could not be written honestly against the
  existing API, which is a better outcome than a green test. Measured: 403
  roster reads on this host, 32 naming a real deployment's channel.

  Worse, **a URL path is logged by every access log format that exists —
  including the deliberately body-free `log_format stringcup` adopted after
  the body-logging incident** — and that log rotates on its own schedule and
  outlives the ACK. That is the same exposure class the access-log
  remediation was about. nginx now redacts the topic segment
  (`map $request_uri $stringcup_logged_uri`), which removes the *retention*
  and not the relay's knowledge.

  **If this is ever closed with opaque topic ids, the label must carry the
  ID, not the human name.** `verify_channel_claim()` resolves a claim to a
  roster and asks whether both parties are in it, which is sound *only
  because topic names are a global namespace* — both ends resolve the same
  string to the same channel. Client-side local names destroy that invariant
  and the check **fails open**: if B has its own channel named "ops" and A
  labels a broadcast "ops", B resolves it to *its* channel and asks whether A
  is a member — for agents that work together, frequently yes. Verification
  passes and the message is attributed to the wrong channel, which is the
  channel-label forgery already fixed once, resurrected, and worse because the
  check now returns `True`. Deliberately reachable: A picks a local name it
  knows B uses. The fix is free — the label is inside the ciphertext and the
  id is already relay-visible. See `DESIGN-opaque-topic-ids.md`, which records
  that critique and five others against a design **before** it was written.

  Keeping the label out of the header is **still right, for a narrower
  reason**: it avoids writing a human-meaningful name into `header_json` once
  per message, in rows deleted only by an ACK, and avoids the relay holding
  (sender, recipient, channel) tuples at rest. It does **not** buy secrecy of
  the name from the relay. Do not restore the old wording. Hiding the name
  properly needs **opaque topic ids with the human name kept client-side** —
  the same move as server-assigned `external_id`s, and a v3 change. **Where a
  doc explains a design choice, check that the rest of the API does not
  contradict the rationale**; this is the same species as SECURITY.md naming
  token hashes and the 507 tile saying "per recipient".
- **A broadcast is labelled, and the label lives inside the ciphertext.**
  This replaced the earlier "no channel label" property, and the two were
  changed together as that note required. An agent asked for a `channel` field
  "even advisory" because it could not tell a broadcast from a direct message.
  **Do not put it in the header.** The header is plaintext to the relay and
  stored beside the ciphertext, and a channel name is human-meaningful — the
  channel that prompted this was named after the company that created it, the
  function of its agents, and the date. A header field would hand the relay a
  labelled social graph and break the topic namespace's deliberate
  non-enumerability, permanently, in a stored column, for a convenience.
  `broadcast()` therefore prefixes the *plaintext* (`CHANNEL_LABEL_RE`) and the
  receiving client strips it into `Message.channel`. Two consequences the docs
  must keep stating: `channel is None` means "direct message **or** a
  pre-3.4.0 sender", never "certainly direct"; and a pre-3.4.0 *reader* sees
  the label as text, which is the manual convention it replaces, so it degrades
  to the previous best practice. `test_mcp.py` asserts `encrypt()` puts no
  channel field in the header, and the live suite asserts the name is nowhere
  the relay can read.
- **An unrecognised member id lands in `unknown` rather than failing the
  call.** Members are typed by hand, so a typo must not discard the other six.

A relay refusal returns `isError: true` with the HTTP status, not a JSON-RPC error — the model can react to the former and never sees the latter.

### Auto-throttle must be per bucket and audible

`_maybe_throttle()` caused a pairing failure that looked like a protocol bug:
two agents, one joining and one awaiting, never seeing each other until one was
stopped and retried.

It slept up to 30s whenever `remaining <= 10`. That is an **absolute** threshold
across buckets from 10/hour (rotation) to 300/hour (inbox), so registration —
which was 5/hour at the time —
which can never report more than 5 — always tripped it. A fresh registration at
4 of 5 slept the full 30 seconds. Measured: 30s and 68s of pure sleep for two
agents registering; 0.1s and 8.3s after the fix.

Stop-and-retry "fixed" it because the retry reused the saved identity and never
registered, which is why this looked like timing rather than the client.

Four constraints now:

- **Threshold is a fraction of the bucket's own limit** (`THROTTLE_AT_FRACTION`,
  10%). Any absolute number is either always or never tripped depending on the
  endpoint.
- **Budgets are per bucket** (`_budgets`, keyed by `_bucket(method, path)`,
  mirroring the server's own per-endpoint-and-method limits). One shared figure
  throttles the wrong calls. `rate_limit` stays as the last-response view for
  display; do not throttle from it.
- **A single pause is capped at `MAX_THROTTLE_SLEEP` (5s).** Inside a caller's
  pairing timeout, a long stall is indistinguishable from a dead peer.
- **It writes to stderr when it pauses.** A silent sleep is what made this take
  a user report to find. Never stdout — the MCP server speaks JSON-RPC there,
  and `stringcup.py` is imported by it.

`sys` is imported for that warning. Writing it without the import made the
throttle raise `NameError` *only* when a budget was nearly spent — latent until
an agent was under rate pressure, which is exactly when it must work. Caught by
testing the firing path, not the passing one.

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

**Protection must track the PAYLOAD, not the label.** Three files this
project writes were protected in inverse proportion to what is in them, and an
auditor named the inversion:

| File | Treatment it got | What is actually in it |
|---|---|---|
| `TrustStore._save()` | `os.open(..., 0o600)`, atomic | **Public** key fingerprints |
| `Identity.save()` | `0600`, atomic | One X25519 private key |
| `_log_transcript()` | plain `open(..., "a")` — **default umask, 0644** | **Every message plaintext**, both party ids, timestamps |
| `db:backup` | documented as "API token hashes" | Every pending **ciphertext** + the membership graph |

The reasoning tracked how sensitive each felt when it was written. The
transcript is the artifact that defeats the entire product — the relay never
sees plaintext, and this is plaintext on disk that deliberately **outlives the
ACK** — and it had the weakest treatment, in the same module that already
contained the correct primitive twice. Both are fixed (`O_CREAT` with a mode,
which applies only on creation and so does not fight a deliberately loosened
file). **The check is mechanical: for every file the system creates, name its
worst field and set the mode and the documentation from that.**

**A MODE ARGUMENT IS NOT A MODE, and this module was audited three times for
file permissions without anyone asking the question.** Every pass looked at
the `0o600` in the `os.open()` call; none asked what happens when the open
does not *create* the file. `Identity.save()` and `TrustStore._save()` wrote a
predictable `<path>.tmp` with `O_CREAT|O_TRUNC` and **no `O_EXCL`, no
`O_NOFOLLOW`** — so with write access to the state directory, pre-placing a
**symlink** made `O_CREAT` follow it and write the X25519 private key wherever
it pointed, and pre-placing a **file at 0666** made the mode argument a no-op
so `os.replace` installed a world-readable identity. Both reproduced. The
second is worse: **the atomic-write pattern that makes the mode correct
everywhere else is exactly what carries the wrong mode in**, because
`os.replace` preserves the temp file's mode. Fixed with `_open_new_private()`
(`O_EXCL|O_NOFOLLOW`), which unlinks a stale temp first or one crash would
make the identity permanently unsaveable. **Any `os.open` with a mode needs
`O_EXCL` if the file is meant to be new**, and `test_properties.py` property 3
asserts it for both files against both attacks.

**Do not silence a warning with a heuristic.** 3.18.0's `_looks_owned()`
suppressed the directory-mode warning by *basename* — `tmp`, `var`, `etc` — so
it silenced directories the caller owned and could fix (`~/.stringcup/tmp`),
while being redundant for the shared ancestors it was written for, since those
are root-owned and the uid check already covered them — *except when running
as root*, which is why the list existed. It papered over a different problem.
The fix is to **bound the ascent rather than filter it**: `_private_dir()`
takes a `boundary` and never walks above the configured state root, so there
are no shared ancestors to suppress. Flagging it to a reviewer as the one
place a warning had been made quieter is what surfaced the rank-1 defect
underneath it.

**Agent-facing docs must tell the reader to `.gitignore` the identity file and
transcript.** 0600 protects against other local users; it does nothing against
`git add -A`. An agent reported keeping both in a project directory, untracked
but not ignored — one commit away from publishing its own private key and every
message it had exchanged. This project committed a live encryption key once
already; do not let a reader repeat it.

**A PROBE MUST NEVER USE THE ACKNOWLEDGING READ PATH.** Destroyed a real
message on this host doing exactly that:

```python
p = me.receive_many(limit=10, timeout=5)      # default ack=True
print("inbox:", p.count, "message(s) pending")
```

One line, in a throwaway verification. It printed `1`. The **count** was
printed and the **text** discarded, the default ACK deleted the row, and the
client was a bare `Client()` — whose constructor does **not** default a
transcript, unlike `load_or_register`. Fetched, acknowledged, deleted,
discarded, unrecoverable.

**The guarantee this project sells is that only an acknowledgement deletes, and
an acknowledgement is exactly what a careless probe issues.** The transcript
exists *because* the relay deletes on ACK, and the one client built without one
was the one used to ask a yes/no question.

`ack=False` exists and is the right tool: a read that answers "is there mail"
must not consume it. Verified before relying on it — a message survived two
non-acking reads and was still there for a real one.

**What was lost makes it worse rather than better.** It was not a peer's reply:
that peer checked its own transcript and had sent nothing in the window. It was
a **production broadcast on another team's channel**, stored before a
membership removal took effect — removal is not revocation, so mail already
accepted still lands. The intended members held their own ciphertexts, so
nothing was taken from them, but the copy addressed to this identity is gone
and **its sender has no way to know.** Recorded in those terms because "a
message, possibly the auditor's" was the comfortable version.

`sync_barrier()` cannot recover from this, which is a limit of that tool rather
than a fault: on an empty inbox it reports `drained 0, synchronised true` and
an **empty** `last_line`. It answers *"are we level now"*, never *"what did I
lose"*. For a loss, only the sender can help.

**ONE IDENTITY, ONE READER.** At-least-once is a promise to the *recipient*,
not to each reader, so two processes on one identity file do not get a copy
each — and **the failure has two modes depending on timing, which the first
version of this note got wrong.**

- **Staggered polling — starvation.** The poller that is ahead decrypts, ACKs,
  and the relay deletes; the other never learns the message existed and
  reports a peer that has gone quiet. Found live here: a leftover
  `receive_many` loop and a fresh MCP server sharing
  `~/.stringcup/claude-code.json`, where the loop won every 25s poll and the
  opening message of a real conversation had to be recovered from the loop's
  stdout log.
- **Concurrent polling — DUPLICATION, which this note originally denied.**
  Measured: three messages, two readers on one identity started together, and
  **both readers received all three.** A fetch is not an ACK, so two reads that
  overlap both return the full page and both then acknowledge it. For agents
  that is arguably worse than starvation — both act on the same instruction,
  and nothing in either one's view says the other did too.

So "one wins and the other starves" describes the staggered case only. The
general statement is that **the inbox is not a queue with two consumers; it is
one mailbox two processes are both reading**, and what each sees depends on
which of them ACKs first. Found by testing the mechanism after describing it
from the API surface — the description was wrong in the direction that sounds
more benign.

**The mechanism is one config line, not carelessness.** `STRINGCUP_IDENTITY` is
an env var, so two MCP hosts pointed at one file is the ordinary way to arrive
here. And the symptom is the expensive one this project already documents: a
peer that looks silent or selectively unresponsive, indistinguishable from bad
faith. Documented in `setup.md` and on the PyPI page; the framing is the
publishing agent's, which is better than "do not leave a stray loop running".

**Re-registering does not recover an identity** — it mints a new one with a different assigned id, and any peer holding the old id can no longer reach you.

**The operative property is not "do not lose the file", it is "a peer who
verified you out of band has paid for that, and changing your key spends their
work".** Two agents converged on this reframing and it is better than what the
docs said. The cost to the peer is identical whether the change was
accidental, deliberate, or merely careless — and it is invisible from your
side, because from the peer's side a rotation and a **key substitution are the
same observation**: an identifier it verified is replaced by a different
identifier with a different key, and no explanation arrives on the wire. An
agent hit exactly that, spent a message reasoning about whether it was being
impersonated, and could only resolve it by having two humans talk.

The docs named only the accidental cause. There are three:

- **Accidental** — the identity file is lost. What the warning described.
- **Deliberate** — the identity is retired or rotated on purpose.
- **Careless** — a *new* identity is minted when an existing one would have
  served. This is what actually happened here, and it is the worst of the
  three because nothing signals that a choice was made at all.

**Keep test identities separate from the identity you hand to external
agents.** The confusion above arose because a scratch identity created as a
test *responder* while walking through `agent.md` ended up in another party's
handoff and roster, was reasonably taken for a durable identity, and was then
not reused. An identity that appears in someone else's roster has had real cost
spent verifying it; treat it as durable on this project's own reasoning.
Announcing a rotation helps and is cheap, but it is a claim anyone could make,
so it only tells the peer what to go and check. Not rotating the
externally-verified identity removes the need to check at all.

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

- **No forward secrecy:** the ephemeral public key is stored in the header, so compromising a static private key exposes past messages. **Rotation is the coarse substitute, and its first implementation was a defect that shipped** — it destroyed the old key immediately, which permanently destroyed mail in flight *and* mail from every peer holding a cached key, silently, while the sender was told `201 stored`. Forward secrecy is the deliberate destruction of a decryption key, so **anything in flight when you destroy it dies**; a coarser granularity does not avoid that, it only widens and hides the window. A rotated key is now retained for decryption only for `RETIRED_KEY_GRACE_SECONDS` (30 days) and **its destruction, not the rotation, is what delivers the secrecy**. Do not reintroduce immediate destruction, and do not restore the pending-inbox refusal that stood in for it — that was a TOCTOU and did nothing about the cached-key case. The 30 days matches `INACTIVITY_TTL_DAYS` and is an argument rather than a proof: nothing invalidates a peer's cached key, so `key_updated_at`-driven invalidation is the missing half
- **No sender-identity binding in the crypto:** sender authenticity rests on the token check, not the ciphertext. A malicious relay could substitute a key — which is why fingerprints must be verified out of band
- **Key distribution is trust-on-first-use:** the relay serves both the key and its fingerprint, so only an out-of-band comparison rules out substitution
- **First contact is authenticated when a secret rides the handoff** (library
  3.7.0). `open_rendezvous()` mints 128 bits the client generates and **never
  sends to the relay**; it travels in the handoff block the operator was
  already pasting, and both sides compare `HMAC(secret, both public keys
  sorted)` over the ordinary message path. Each side hashes its **own real**
  key with the key it was **served**, so the tags match only if neither was
  substituted. Verified against a simulated malicious relay: one substituted
  key made both sides raise `VerificationFailed`; the same substitution
  without a secret paired silently with `verified: false`.

  **The v1 tag was REFLECTABLE and shipped broken for one version.** It was
  `HMAC(secret, sorted(both keys))` — fully symmetric, so both sides computed
  the identical value and each compared the received tag against its *own*. A
  value both parties compute identically, exchanged over a channel the
  adversary controls, proves nothing: **the relay never needed to forge a tag,
  only to reflect one.** It decrypts a side's tag (substitution bought that),
  mints a message with `sender_id` set to the peer — forgeable, as this file
  already records — carrying that side's own tag encrypted to its real key,
  and the comparison succeeds. Both sides reported `verified: true` under a
  full MITM. Reproduced end to end before the fix.

  Three things to carry forward, and the third is the general one:

  - **A tag names the role of whoever computed it** (`PAIRING_TAG_CONTEXT`,
    `other_pairing_role`). Each side sends its own role's tag and compares the
    peer's against the *other* role's — never its own. Receiving one's own tag
    back is detected explicitly, because nothing legitimate produces it. Both
    ids, both keys and the rendezvous token are bound, length-prefixed.
  - **Machine generation is structural.** A caller-supplied secret is refused
    like a client-chosen `external_id`. An auditor noted this is the *third*
    time this project has learned that lesson, so it is a rule now, not a
    warning.
  - **The original argument asked whether the adversary could COMPUTE a
    matching value, and never asked whether it needed to.** The test modelled
    a passive substituter that *forwarded* tags; the adversary this feature
    exists to stop is active on the message path, because it **is** the
    message path. Any future proof-of-possession here must be tested against
    an adversary that echoes, not merely one that tampers.

  **A verified pairing PINS, and a failed one leaves nothing behind.** Two
  wrinkles found by self-audit after the reflection fix, both in the feature
  as shipped:

  - Verification was **per-process**. The verified key sat only in the
    in-memory `_peer_keys` cache, so after a restart `send()` re-fetched it
    from the relay with nothing to compare against — an operator who carried
    a secret by hand bought one process's worth of assurance. A successful
    verification now pins the locally computed fingerprint, which is the
    natural composition: the secret gives what an out-of-band comparison
    would, and that is what a pin records. With no trust store it warns once
    on stderr and reports `pinned: false` rather than implying durability.
  - A **failed** pairing left a **poisoned pin**. `rendezvous()` pins on first
    sight, before verification decides, so a substituted key got pinned and
    the next attempt against the *genuine* key raised `KeyPinMismatch` —
    reading as an attack when it was poison. **The first attempt at this fix
    was inert**: it asked "was this pinned before?" inside `_verify_pairing`,
    where the answer is always yes because `rendezvous()` has already pinned.
    `rendezvous()` now records whether *it* created the pin
    (`_pin_created_for`), which is the only place that can know. A pin that
    pre-dated the pairing is never touched.

  Four further properties to preserve. **The secret must never reach the relay** — a
  value the relay knows proves nothing about a key it served, and
  `test_mcp.py` asserts against the source that no `_request()` passes it.
  **A mismatch must stay terminal, not retryable** — retrying cannot fix
  substitution, and an agent reading "call again" would loop into an
  unauthenticated conversation. **Absence of a secret must be reported**, not
  defaulted away: `verified: false` plus a statement that substitution would
  be undetectable. And **it costs one message in each direction**, so it
  spends a sequence number, a send-rate slot and a dashboard count — which is
  why a test asserting the first conversation message is sequence 1 broke, and
  was rewritten to assert the increment instead.

  It does **not** fix two *fully autonomous* agents with no human in the loop;
  nothing does without a pre-shared trust root. What changed is that
  authentication is free exactly when a human is already carrying the handoff.
- **A first contact between two autonomous agents with no human present is unauthenticated.** Out-of-band comparison assumes a human is present, and for the audience this project targets one usually is not. **The rendezvous token is not a usable substitute — the relay issues it, so the relay knows it**, and it therefore proves nothing about a key the relay served. Do not write docs implying the token authenticates anything. Closing it needs a secret the relay never sees. **The scheme recorded here previously was weak and must not be implemented as written:** `HMAC(passphrase, both public keys sorted)` gives the relay an *offline verifier* — it holds both public keys and sees the tag, so a human-chosen passphrase falls to a dictionary attack (demonstrated: recovered in 29 guesses, sub-millisecond). Two sound forms: a **high-entropy client-generated secret carried in the existing handoff block**, which the relay never sees and which HMAC binds safely because there is nothing to guess; or a **PAKE** (SPAKE2/CPace) if the secret must be human-memorable. Not implemented; recorded in SECURITY.md so the gap is not mistaken for an oversight
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
| `php spark limits:check` | Every enforced `MAX_*` limit is published by `/api/v2` and, for the capacity subset, `/api/v2/stats` |

`tests/lib/v2_client.php` holds the shared HTTP client, ECIES crypto helpers and assertions. It doubles as the compact PHP reference implementation of the v2 protocol.

### Python client (`clients/python/`)

`clients/python/stringcup.py` is the supported client library for agents — identity persistence, ECIES, pagination, batch ACK, idempotent send, token rotation and a rate-limit-aware poll loop. Targets Python 3.7+ (Amazon Linux 2 has no newer Python in any repo, so do not raise the floor casually).

| File | Purpose |
|---|---|
| `stringcup.py` | The library |
| `example_agent.py` | Runnable initiator/responder agent template |
| `test_stringcup.py` | 70 assertions over the client surface |
| `test_features_v11.py` | 95 assertions: long polling, key pinning, topics, fan-out, rendezvous, `receive_one`, transcripts |
| `test_interop.py` | **Python ↔ PHP cross-language check** |
| `stringcup_mcp.py` | MCP server (stdio) wrapping the library |
| `test_mcp.py` | 226 assertions: JSON-RPC plumbing driven as a real subprocess, plus tool shapes against a stub |
| `test_mcp_live.py` | 86 assertions: three MCP processes pair, converse and share a labelled channel over a live relay |
| `test_contract.py` | 27 assertions, **no network**: version/surface invariants that stop a changed contract shipping under an unchanged version |
| `test_properties.py` | 37 assertions: the promises in PROTOCOL.md B.6, asserted by observing a real run |

**A CHECK IS AN ARTIFACT TOO, AND THE "I CHECKED" RULE NEEDED ONE MORE WORD.**
The rule said *name what was checked — source, rendered interface, or running
behaviour.* A peer agent proposed the missing half after a night in which
**five** of its own checks returned confident wrong answers about this
project's files, none of them failing loudly:

- a **case-sensitive** `grep` for a clause written in capitals — twice
- a **3000-character window** after `def sync_barrier` on a 5064-character
  body, with the addition at line 80 of it
- `objective` passed as a **dict key** where it was a sibling parameter
- testing for **its own four string markers** when the claim was about three
  *properties*, which the text conveys in different words

Each would have produced a false defect report; each was caught only by
re-checking before sending. And on this side, the same move: the burst clause
was verified to **exist** when the request had been about **where it was** —
presence standing in for position.

So the rule is: **"I checked" must name what was checked *and against what* —
the claim itself, or a proxy for it.** Markers-for-properties and
presence-for-position are one error: verifying something adjacent to the claim
and then reporting on the claim. Its framing, and better than the version
here: *each check was a reasonable thing to type; the failure was applying the
scrutiny to the artifact under test while exempting the instrument.*

**And a new test must be observed to FAIL against the broken artifact before
it is trusted.** The placement assertions were run against the version already
on PyPI and all three failed, which is the only thing that distinguishes a
check from a sentence — a placement test that passes on the defect is worse
than none, and that is now demonstrated rather than argued. Same reason
`test_properties.py` exists and the same reason the `NameError` in
`_maybe_throttle` was caught by exercising the firing path.

**The wire-level property retires nothing, and the source scan stays.** An
auditor suggested property 4 subsumes `test_mcp.py`'s per-call paren scan for
the pairing secret. It does not, and this file's own rule says why: *"I
checked" has to name what was checked — source, rendered interface, or running
behaviour.* The property observes the requests a lifecycle actually makes, so
it is blind to a leak on a path that lifecycle does not exercise; the paren
scan reads every `_request()` call in the file regardless of reachability.
They fail on different things. Keep both. **The auditor conceded this** —
they had recommended retiring the scan while quoting the rule that says keep
the three modes distinct — and asked that it stay recorded as a disagreement
with the concession attached rather than be tidied into agreement. Do that.

**`test_properties.py` asserts the SPEC, not the code, and it is the only
suite here that can contradict the implementation.** Every other suite is
written from the diff — it asserts what the code does, so it can only confirm
it. That is how the rotation defect passed a review, a CHANGELOG entry *and* a
test suite: the suite asserted "the old key is gone", which was exactly the
behaviour causing the bug. An auditor's diagnosis, and the most useful thing
to come out of the whole review: **PROTOCOL.md B.6 already states the
promises, in prose, and nothing executed them.** Findings get reproduced,
fixes get reviewed, and the spec gets read once then quoted selectively — yet
the spec is the only artefact that stated the correct answer before the bug
existed. Rotation contradicted a sentence already published in it: *"the
alternative, deleting old mail, would lose messages a sender was told had been
stored."*

Two properties, and both are worded to be assertable rather than
aspirational:

- **Accepted mail is retrievable through the interface the docs mandate, OR
  the caller is told it exists and why it cannot be read.** The "or told"
  disjunct carries the weight: mail sealed to a destroyed key *should* be
  unreadable, so the correct behaviour is disclosure. And it binds to the
  **outermost** surface — "readable" alone is satisfied by the MCP re-drop
  defect, where the mail was there and only the surface the user has reported
  nothing.
- **No plaintext this system writes is readable by anyone but its owner**,
  asserted by `os.walk` + `stat` over everything a run creates, with **no
  allowlist** — the whole class of defect here is a file nobody remembered
  writing.

It found a defect on its first run, which is the argument for it: `makedirs`
applies `mode` **only to the leaf**, so `~/.stringcup` — holding the private
key, the trust store and every transcript — was created by the library itself
at 0755 on a fresh install. No amount of reading that function shows it.
**When you add a promise to PROTOCOL.md, ask what would execute it.**

`test_interop.py` is the highest-value test in the repo: it drives the PHP implementation as a second party and asserts both derive identical message keys. A wrong HKDF salt or `info` string passes every single-language test and fails only here.

`test_mcp_live.py` earns its place the same way: it caught the MCP server reading `peer_public_key` off the rendezvous response when the field is actually `peer_identity_public_key`. Every stub-based assertion passed, because the stub had the same wrong name.

**Every suite must be in `tests/run_all.sh`.** `test_features_v11.py` was not, and rotted silently: it asserted a transcript key renamed in 2.4.0 (`message_id`, when the keys became `sent_seq`/`inbox_seq`) and registered **six** identities against the 5/hour registration bucket, so it could only ever have passed while the rate limiter was broken. Both defects had been sitting there for weeks with nothing reporting anything — the same shape as the PHP suite that needed six registrations, which grew a `reset_rate_limits()` helper for exactly this reason. The Python suite now has the same helper and the same reasoning in its docstring: **a suite resets between sections rather than asking for a higher limit**, because registration is the one unauthenticated write. (The cap is 30/hour since 2026-09-16, so six registrations now fit — but keep the reset: a suite run twice in an hour still needs it, and a suite that depends on the cap being generous is a suite that breaks when it is tightened.) A suite nobody runs is not coverage.

Two constraints worth knowing:

- **libsodium.** The X25519 helpers need it. Where `ext-sodium` is absent (as on the current host), `paragonie/sodium_compat` supplies a pure-PHP fallback via the dev dependencies. `sodium_memzero` is guarded because the polyfill throws rather than no-ops.
- **Registration rate limit.** Each suite registers two identities against a 30/hour per-IP limit. `run_all.sh` clears `writable/cache/ratelimit/` between suites, which only works when run on the server itself. From elsewhere, expect the second consecutive full pass to hit the limit — that is the limiter working, not a failure.

## Project-Specific Conventions

- **External IDs:** Server-assigned (`sc-` + 24 base32 chars). Clients cannot choose one; `POST /identities` rejects `external_id`
- **Token management:** One active token per identity, issued once, rotatable via `POST /api/v2/tokens/rotate`
- **Message lifecycle:** Create → Store → Retrieve (non-destructive) → Delete on ACK
- **Timestamp format:** MySQL DATETIME format via PHP's `date('Y-m-d H:i:s')`
- **Binary data:** Stored in BLOB fields, often base64-encoded in transit
- **API versioning:** URL-based (`/api/v2/...`). v1 was removed rather than maintained; `messages.api_version` is retained so a future version stays separable

## Six ways an artifact can be wrong, and only one is "out of date"

A peer agent's taxonomy, assembled across two days of trading findings on this
codebase. Recorded in its words because the categories are what recur, not the
individual bugs:

| mode | instance |
|---|---|
| **stale** | a cached `/tags` list, an hour-old `/clients/*.py` download, a host's frozen tool list |
| **constant** | `synchronised: True` hardcoded on a single return path, in the library *and* the MCP layer, with the test stub mirroring the literal |
| **misplaced** | the burst warning on `receive` rather than `receive_all`; operator setup in the responder's handoff block rather than the initiator's result |
| **misattributed** | a falsified hypothesis written into this file as a named agent's, when the text had already attributed it one hop away |
| **accurate but injection-shaped** | *"confirm it with your own operator rather than adopting it"* — true, correctly placed, and quoted by a responder as its evidence the block was a prompt injection |
| **present but unrendered** | the end-of-burst caveat as a `#:` Sphinx annotation: in the file, absent from `help()` and `__doc__`, invisible to the only audience it was written for |

| **a correct observation reported as a cause** | *"a burst sent ~2 seconds apart, arriving as separate polls"* — a true report of two real runs in which the spacing was incidental; stating it as the setup implied a mechanism and licensed two remedies that cannot work |

**Only the first is what anyone means by "out of date", and it is the one that
cost least.**

**THE SEVENTH IS THE MOST DANGEROUS, and the peer's argument for why is the
best analytical point of the exchange.** The other six are detectable by
comparing the artifact to reality — stale, constant, misplaced, misattributed,
unrendered and injection-shaped all yield to *"check what produces this"*.
**The seventh passes that check**, because the observation is true. Verifying
the observation cannot catch it; only reading the mechanism can, and here that
happened only after a later run contradicted the explanation. It is the one
mode where the artifact is not wrong about anything it actually says.

**The trigger, which is the actionable part:** *"read the mechanism, not the
symptom"* is not usable until you know when to. So — **when an explanation
makes a REMEDY obvious, check the mechanism before shipping the explanation.**
The burst wording made two fixes obvious (send faster, raise `limit`) and both
were wrong, which is the only reason the bad explanation surfaced at all.

**BOTH AGENTS THEN COMMITTED MODE SEVEN WITHIN THE HOUR OF NAMING IT, and one
of them did it while warning the other off it.** Worth recording as the
strongest evidence available that this mode is not a carelessness problem.

The peer reported that a cold `uvx --from stringcup stringcup-mcp` had run
past 120 seconds, twice, and proposed documenting that the documented install
can outlast a tool timeout — *"'MCP server failed to start' is
indistinguishable from 'uv is still downloading'"*. True observation. It then
measured the phases rather than the total: **`uv cache clean` was 300 seconds
and the install itself was 0.** Every second belonged to its own test
scaffolding, which no operator runs, and the first run's own stderr had
already said *"Installed 4 packages in 8ms"*.

**And the alternative cause offered from this side was also invented.** Told
that the claim was probably wrong, this project's reply named *bandwidth,
datacenter versus laptop* as "the likeliest actual variable" — hedged, but
still a mechanism asserted without measuring the machine it was about, in the
same message that told the peer not to do exactly that. A measurement here
(0.9s cold, cache verified empty first) licensed a guess about a different
host.

So the rule earns a second clause: **a correction is not exempt.** Supplying a
replacement cause is the same act as supplying the original one and needs the
same evidence, and "likeliest" is not evidence. Neither agent's proposed cause
survived; the real one was scaffolding in the measurement. The last two were each caught once, by someone other than the
author, and neither was reachable by more careful reading.

**The `#:` case is the one to keep, because the rule already existed in the
other direction.** This file says *assert the rendered interface, never grep
the source* — written because a phrase can be in the interface and not the
source. The mirror went unguarded: content that lives only in the source,
checked by a source-reading test, so the content and the check agreed with
each other and neither matched what a reader gets. **A rule stated in one
direction does not guard its inverse.**

## When an instrument disagrees with expectation, report the disagreement — do not resolve it

The peer ran **six** checks in two days that returned confident wrong answers
about this codebase: a case-sensitive `grep` for a clause in capitals (twice),
a 3000-character window on a 5064-character function body, `objective` passed
as a dict key where it was a sibling parameter, and its own phrase markers
standing in for a property (twice). **None failed loudly.** Every one would
have produced a false defect report.

It got one right, and its own account of why is the lesson: it did **not** catch
the `Page.__doc__` defect by being careful. Its check had just produced four
false negatives, and it flagged the finding anyway as *"confirm rather than
assume I am right to wave it through."* **Both shortcuts were wrong** —
trusting the `False` meant reporting four fixed surfaces as broken; trusting
its instinct to dismiss it meant burying a real defect. The only move that
worked was declining to decide and saying so.

So: *"when an instrument disagrees with expectation, the report should carry
the disagreement rather than a resolution of it."* Its framing. Note this is
the counterpart to the rule that a check must name **what** was checked and
**against what** — that one is about building the instrument, this one is about
what to do when you cannot trust it.

## What the review actually taught, in one line

Four findings this week were worth more than the rest put together: a
symmetric proof-of-possession tag proves nothing because the adversary can
**echo** it rather than forge it; a fix that applies at **creation time** says
nothing about the artifacts that already exist; a property nobody **executes**
is a sentence; and a check can depend on an **invariant nobody wrote down**.

**Every one of them is a question about a dependency nobody wrote down.** A
symmetric tag depends on the adversary being unable to copy. A creation-time
fix depends on nothing existing yet. A published property depends on someone
executing it. A membership check depends on a namespace being global. In each
case **the code was correct with respect to everything that was written down,
and wrong with respect to something that was not** — which is why no amount of
more careful reading found any of them, and why three were found by an outside
party asking a question that was not on any list.

That does not resolve into a checklist, and it should be distrusted if it ever
seems to. The nearest operational form, arrived at independently on both
sides: **when you remove or change a property, go and find every check that
depended on it** — and accept that "every check" is not enumerable, which is
why it stays a question rather than becoming a test. The rotation defect was
that shape (removed "the old private key exists", left every path assuming
it), and so was the opaque-topic-id design (removed "topic names are global",
left `verify_channel_claim` depending on it). The second was caught only
because the design was attacked **before** it was written.

**Attacking a design before writing it is the cheapest review available.**
Every other finding arrived after the thing had been written, tested,
documented and often shipped. Send a plan to a reviewer before implementing a
change of any size.

**A WORKAROUND CAN PROMOTE AN ARTIFACT TO A LOAD-BEARING SURFACE, and nothing
tells you it happened.** Agents began driving `stringcup.py` directly — through
`uvx --from stringcup python -c ...`, a name from a package index, which is
permitted by the same reasoning that permits `claude mcp add` — in order to
avoid the MCP session restart. That is legitimate and it worked. **The
side-effect is that library docstrings became agent-facing documentation**, and
nothing had updated them: the burst warning existed on both MCP tool
descriptions and on **none** of `Page.has_more`, `receive_many()`, `fetch()` or
`receive_one()`. Two agents in a row read the library docs as the product's
documentation. Measured against 3.35.0: MCP surfaces carried the caveat, every
library surface did not.

**This is the third instance in two days of fixing the surface where a bug was
REPORTED rather than every surface carrying the claim** — after the warning
landing on `receive` instead of `receive_all`, and operator setup landing in
the responder's handoff block instead of the initiator's result. The
generalisation is not "check more carefully": it is **enumerate the surfaces
that state the claim, then assert none of them lacks it.**
`test_contract.py` step 10 does that for `has_more` across the library source,
both MCP descriptions and the published prose, and was verified by watching
four surfaces fail against the shipped release. `PROTOCOL.md B.3.1.2` is the
canonical statement so the other surfaces can point rather than duplicate —
which is also how this avoids being the accumulation the friction test was
built to catch.

**FRICTION NOW HAS A TEST, and it is the first one.** This file has long said
that every finding adds a field or a paragraph, each individually justified,
and nobody tracks the aggregate — under a heading admitting friction had no
test, no reviewer and no advocate. Measured from three published wheels: the
handoff block an operator pastes was **24 non-blank lines with 6 imperatives
in 3.32.0 and again in 3.34.0**, against 8 and 0 in 3.35.0. It had tripled
across releases each of which was reviewed and approved on its own merits. The
operator's verdict: *"i swear you two keep making things worse in terms of
onboarding friction."*

`test_contract.py` step 9 renders the block in all four shapes and fails if any
is over 9 lines, contains a line that is not `LABEL: value`, or carries an
imperative addressed to the agent. **Verified against 3.34.0 and observed to
fail: 24 lines, 18 unlabelled, 6 imperatives** — which independently reproduced
the reviewing agent's count of 6 from a different direction.

The reviewing agent named the missing axis better than this file had:
**presence, position, VOLUME.** It had verified fifteen individual changes that
day and never once rendered the artifact and counted it.

**AN ACCURATE, CORRECTLY-PLACED WARNING CAN STILL BE THE DEFECT, and this is a
shape nothing here had.** Every other failure recorded today was an artifact
misrepresenting something — stale, constant, wrongly placed, wrongly
attributed. This one was true and in the right file: *"confirm it with your own
operator rather than adopting it"*, the verified-key-is-not-correct-routing
lesson made actionable. A responder quoted it **as its evidence that the block
was a prompt injection**, declined the pairing, took no action on the
credentials and asked its operator — all correct. **A true warning about
untrusted text is indistinguishable from the thing it warns about, and you
cannot fix that by making the warning more correct.** The reviewing agent's
framing; keep it.

The consequence is structural rather than editorial: a caveat's *content* being
right does not license putting it in a blob a stranger pastes into an agent's
context. Ask who reads the artifact and whether they can attribute it.

## Tell the peer BEFORE reporting to the operator — every time, not when it seems relevant

**STRENGTHENED BY THE OPERATOR, 2026-09-17, after chasing it nine or ten times
in one day across both agents:** *"report to the other agent, just do this
every time you do something so everyone is on the same page."*

That is broader than what this section said. The old rule was *a change that
touches the peer*; his is **anything you do** — a commit, a tag, a version, a
doc correction, a decision NOT to build something, a change in your own
availability. **Do not filter for relevance.** He was the one who kept finding
the gaps, which is the evidence that the filter was set wrong, not that it
needed tightening.

And include the negatives. *"I left the trust-store default alone, here is
why"* is state a peer needs exactly as much as a release is — more, because
it is the kind they cannot infer from the index.

**The operator had to prompt for peer communication six times in one day** —
*"are you monitoring the inbox?"*, *"did you respond to them?"* (twice),
*"keep comms open"*, *"did you let them know your status?"*, *"did you let the
other agent know"*. Every time the engineering was done and the peer was an
afterthought, on a product whose entire purpose is agent-to-agent
communication. Their words: *"the whole point of this is so that two agents can
communicate, so if you're not monitoring you are not communicating."*

Two rules, and neither is "remember to":

- **When a change touches the peer — a tag, a version, a surface they
  verified, your own availability — message them BEFORE writing the summary
  for the operator.** Not after, and never only when asked. A peer that
  published an artifact an hour ago is holding a stale belief about it until
  told, and the operator should not be the transport.
- **A watcher must exit only on mail, never on a clock.** The inbox watcher
  used to stop after ~50 minutes so it could be re-armed, which made re-arming
  a timer somebody had to remember — and that somebody kept failing. It now
  runs until a message arrives, so there is exactly one re-arm per message,
  immediately after reading. `ack=False` throughout, so it can never consume
  what it reports.

**And say what your own failure modes are, unprompted.** A peer volunteered
that its window was one session and that silence would mean a wipe; the reply
was version numbers and commit hashes, called "state", and sent nothing about
compaction, watcher gaps or session end. **Operational state is not
continuity.** A peer cannot tell a slow reader from a dead one — the failure
this project documents more than any other — so the mechanism has to be given,
not guessed.

## Friction is a property, and nothing was measuring it

**The operator's verdict after a week of audit work: "while good, maybe made it
harder to use."** It was fair. Every finding added a field, a caveat or a
paragraph; each was individually justified; nobody was tracking the aggregate.
The stated product goal is *agents communicating with little to no friction*,
and that goal had no test, no reviewer and no advocate while nine security
findings did.

Two measured regressions, both self-inflicted:

- **`broadcast("ops-mail", …)` stopped working** when channel ids were
  assigned, replaced by `tp-wuteffkb25lwlhyfgbvseyxh`. The fix was
  `_resolve_channel()`: **a label works wherever an id does**, resolved
  client-side, never sent. This gives up nothing — the label was already
  stored locally — which means the regression was never a necessary cost of
  the security property. It was just not reconciled.
- **Five prose note fields accumulated on MCP results**, in the same server
  whose history records that a 491-character paragraph on every message
  *defeats itself* because identical text every turn stops being read. The
  lesson was learned, written down, and then broken five more times. **A
  result field carries a value; a tool description carries the explanation.**

The general rule: **when a security change alters an interface, state the
ergonomic cost explicitly and look for the version that has none.** Usually
there is one. And **ask whether the threat is the operator's** — relay-visible
channel names are not a threat to someone who runs the relay, which was said
out loud before the work started and then not weighed when the design landed
on opaque ids everywhere.

## Security Considerations

**RESTATED BY THE OPERATOR, 2026-09-16, AND IT REORDERS THIS LIST.** In their
own words, after two rounds of correcting a reading of it that was too loose:

> **as secure as possible but don't get in the way of frictionless agent
> onboarding.**

The **relay** must be secure and private. The client side should be as secure
as it can be *made without anyone noticing*.

**The cap is on COST, not on SECURITY, and that distinction is the whole
point.** "Client-side hardening is optional" would be the wrong reading and
was the first one written here: it invites leaving a client insecure, which is
not what was asked. The right reading is that client-side protection should be
**free and invisible** — on by default, requiring no decision, no extra step,
no prompt and no paragraph an operator must read. A 0600 file mode is perfect
by this standard: correct, and nobody ever knows it happened. A warning an
operator must interpret before their agent works is not, however sound the
reasoning behind it.

So the test for a client-side proposal is not *"is this more secure?"* — it
usually is — but **"will a new agent notice?"** If yes, it needs to pay for
itself. If no, ship it.

That reorders the previous ranking, which had plaintext-on-the-client's-own-
disk as the worst class and called the 0644 transcript this project's most
serious defect — an artifact on the *operator's own machine*, under their own
control.

Ranking now:

1. **Onboarding friction** — the product goal, and the thing that has
   repeatedly lost to individually-justified security work. A change that
   makes a new agent slower to reach its first message needs to pay for
   itself, and "it is more secure" is not automatically payment.
2. **Relay security and privacy** — what the relay itself holds, serves,
   logs, retains and leaks. Ciphertext retained past the ACK, request bodies
   in access logs, one identity reading another's mail: still top-rank
   defects. This is the part that is *not* optional, because a relay operator
   asks users to trust it.
3. **Integrity and availability** — real, and they do not expose content.
4. **Client-side hardening** — **wanted, but it must be free.** File modes,
   transcript permissions, atomic writes: keep them, keep them correct, add
   more where they cost nothing. What is capped is the *friction budget*, not
   the security — so a protection that is silent and on by default is always
   welcome, and one that adds a step, a decision or a caveat on an
   agent-facing surface has to justify itself against rank 1.
5. **Metadata minimisation** — keep what exists, do not add complexity for it.

**Two consequences worth stating, because the old ranking is quoted all over
this file.** The transcript and file-mode findings were still real defects and
the fixes stay — they cost no friction, which is exactly why they were never
controversial and exactly why they remain right under the new ranking. What
changes is *prospective*: a proposal that buys client-side secrecy at the cost
of an extra step, an extra field, or an extra warning an operator must read
now loses by default — and the first thing to try is the version of it that
costs nothing, which usually exists. It also means **do not
re-rank the 0644 transcript as the worst defect in this project's history**
when summarising; it was a defect, and it was on the user's own disk.

**The first thing this ranking indicted was registration, and it has been
raised: 5/hour per IP → 30.** It was the one limit this file already called
"the fleet-onboarding blocker", which is exactly the newly top-ranked
concern.

The two sentences that looked contradictory — "identities are not what grows"
versus "raising it weakens the only barrier to identity-farming" — are both
true about *different resources*, and naming which one settled it:
**registration mints rate-limit budget.** A token per identity, bucketed by
token hash, each with its own 100 sends/hour and 300 inbox reads/hour. That
is the real cost, and it is a rate, not a total — 5/hour was already 120/day.
See [Scaling](#scaling-what-actually-binds) for the full reasoning and for why
a vouched-registration scheme was weighed and rejected.

**Do not spend design budget reducing metadata exposure**, and do not accept a
complexity increase justified only by it. But note the qualification: a
*channel name* can describe the conversation's subject rather than its
existence — one was named for a company, a function and a date — so keeping
the label inside the ciphertext remains right. The rule is about not
*investing* in metadata hardening, not about leaking subject matter for free.

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

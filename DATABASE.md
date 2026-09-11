# Database Documentation

## Overview

Stringcup uses a MySQL/MariaDB database hosted on AWS RDS. The schema consists of 5 tables that manage user identities, authentication tokens, encrypted messages, and prekey bundles for the Signal-style E2EE protocol.

## Running Migrations

### Initial Setup

```bash
# Run all migrations to create tables
php spark migrate

# Check migration status
php spark migrate:status

# Rollback last migration
php spark migrate:rollback

# Rollback all migrations
php spark migrate:rollback --all
```

### Creating New Migrations

```bash
# Generate a new migration file
php spark make:migration MigrationName

# Example: add a new column
php spark make:migration AddLastLoginToIdentities
```

## Database Schema

> Column types below match the **live production schema**. They previously
> did not: the database had been altered by hand and drifted from the
> migrations, so this file documented types that no deployment actually used.
> Verify with `php spark schema:check`, which fails on drift.

### Table: `identities`

Identity records and public keys for E2EE. Shared by both API versions.

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `external_id` (VARCHAR(64), UNIQUE, NOT NULL) - **Server-assigned** identifier, `sc-` + 24 base32 chars (120 bits)
- `display_name` (VARCHAR(255), NULL) - Optional display name
- `identity_pubkey` (VARBINARY(64), NOT NULL) - X25519 public key, 32 raw bytes
- `algo` (VARCHAR(32), NOT NULL, DEFAULT 'ed25519') - 'ed25519' or 'x25519'
- `key_updated_at` (DATETIME, NULL) - When `identity_pubkey` last changed
- `created_at` (DATETIME, NOT NULL)
- `updated_at` (DATETIME, NOT NULL)

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE KEY on `external_id`

**Notes:**
- `external_id` is assigned at registration and cannot be chosen. Client-chosen
  names made this a first-come namespace where anyone could claim the id another
  party was about to use; assignment removes the race
- `identity_pubkey` is raw binary, not base64
- **`key_updated_at` moves only when the key itself changes.** `updated_at`
  moves for any edit, so only this column lets a peer that pinned a
  fingerprint tell a genuine key rotation from a display-name change
- The `algo` default is a historical artefact; v2 clients always send x25519

---

### Table: `api_tokens`

Bearer tokens, stored only as hashes.

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `identity_id` (BIGINT UNSIGNED, NOT NULL) - FK to `identities.id`, ON DELETE CASCADE
- `token_hash` (VARBINARY(32), UNIQUE, NOT NULL) - Raw SHA-256 of the plaintext token
- `created_at` (DATETIME, NOT NULL)
- `last_used_at` (DATETIME, NULL) - Refreshed on every authenticated request
- `is_active` (TINYINT(1), NOT NULL, DEFAULT 1) - Cleared on expiry or rotation

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE KEY `uniq_token_hash` on `token_hash`
- KEY `fk_api_tokens_identity` on `identity_id` (FOREIGN KEY to `identities`)

**Notes:**
- There is **no `expires_at` column.** Expiry is computed from `last_used_at`
  plus `ApiTokenModel::INACTIVITY_TTL_DAYS` (30). An earlier migration created
  such a column; production never had it, and the reconcile migration drops it
- Rotation (`POST /api/v2/tokens/rotate`) inserts the replacement first, then
  clears `is_active` on the old row — never the reverse

---

### Table: `messages`

Encrypted messages. Filtered on `api_version = 2`; v1 was removed but the
column is retained so a future protocol change stays separable.

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT) - Also the inbox cursor
- `sender_id` (VARCHAR(191), NOT NULL) - external_id of sender
- `recipient_id` (VARCHAR(191), NOT NULL) - external_id of recipient
- `header_json` (TEXT, NOT NULL) - Message metadata (version, algo, ephemeral_pub/msg_seq, IV)
- `ciphertext` (LONGBLOB, NOT NULL) - AES-256-GCM output including the 16-byte tag
- `created_at` (DATETIME, NOT NULL)
- `api_version` (TINYINT(1) UNSIGNED, NOT NULL, DEFAULT 1) - always 2 for new rows; retained so a future protocol stays separable

**Indexes:**
- PRIMARY KEY on `id`
- KEY `idx_recipient_id` on `recipient_id`
- KEY `idx_created_at` on `created_at`
- KEY `idx_messages_inbox` on (`recipient_id`, `api_version`, `id`)

**Message lifecycle:** created → stored → retrieved (non-destructive) →
**deleted only on explicit ACK**. Delivery is at-least-once; a consumer that
never ACKs accumulates an unbounded backlog.

**Notes:**
- `ciphertext` is LONGBLOB, not BLOB. A 64 KB BLOB would silently cap message
  size; the practical limit is now MySQL's `max_allowed_packet` (16 MB here).
  256 KB payloads are verified working
- `idx_messages_inbox` is what serves the v2 paginated inbox
  (`WHERE recipient_id = ? AND api_version = 2 AND id > ? ORDER BY id`). The
  older `(recipient_id, created_at)` index cannot satisfy the id-range cursor,
  so dropping this one degrades every poll to a filesort over the recipient's
  whole backlog
- The server never decrypts. It stores opaque blobs and validates envelopes

---

### Table: `idempotency_keys`

Replay guard for `POST /api/v2/messages`.

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `identity_id` (BIGINT UNSIGNED, NOT NULL) - The sending identity
- `idem_key` (VARCHAR(255), NOT NULL) - Client-supplied `Idempotency-Key`
- `message_id` (BIGINT UNSIGNED, NOT NULL) - 0 while the send is in flight
- `created_at` (DATETIME, NOT NULL)

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE KEY on (`identity_id`, `idem_key`) - Scoped per sender
- KEY on `created_at` - Serves pruning

**Notes:**
- **The unique constraint is the concurrency control.** A send reserves the key
  (inserting with `message_id = 0`) *before* writing the message, so two
  concurrent retries cannot both insert. The loser gets 409
- Separate from `messages` on purpose: the replay guarantee must outlive the
  recipient ACKing and deleting the message
- Rows older than 24 hours are pruned opportunistically on the send path, so
  the table stays bounded without a cron dependency
- A failed send releases its key, letting a corrected retry reuse it

---

### Table: `topics`

Named membership directories for multi-agent fan-out.

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `name` (VARCHAR(64), UNIQUE, NOT NULL) - Global namespace, like `external_id`
- `owner_identity_id` (BIGINT UNSIGNED, NOT NULL) - Only the owner may change membership
- `created_at` (DATETIME, NOT NULL)

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE KEY on `name`
- KEY on `owner_identity_id`

---

### Table: `rendezvous`

Pairing claims that let two agents exchange server-assigned identifiers.

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `token_hash` (VARBINARY(32), NOT NULL) - Raw SHA-256 of the shared token
- `role` (VARCHAR(16), NOT NULL) - `initiator` or `responder`
- `identity_id` (BIGINT UNSIGNED, NOT NULL) - The claiming identity
- `created_at` (DATETIME, NOT NULL)
- `expires_at` (DATETIME, NOT NULL) - 15 minutes after the claim

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE KEY on (`token_hash`, `role`)
- KEY on `token_hash` - finding the counterpart
- KEY on `expires_at` - pruning

**Notes:**
- **The unique key is the security property.** A role can be claimed once, so a
  second identity claiming a held role is refused with `409` — an agent whose
  token leaked is told, rather than silently displaced
- Tokens are stored **hashed**; the server never needs the plaintext, and a
  leaked table should not yield live pairing secrets
- Rows are pruned on the claim path, so the table stays bounded without a cron
- A rendezvous token names a *meeting*, not an identity. It confers nothing
  addressable and expires in minutes, which is what distinguishes it from the
  client-chosen `external_id` it replaced

---

### Table: `topic_members`

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `topic_id` (BIGINT UNSIGNED, NOT NULL)
- `identity_id` (BIGINT UNSIGNED, NOT NULL)
- `added_at` (DATETIME, NOT NULL)

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE KEY on (`topic_id`, `identity_id`) - Re-adding a member is a no-op
- KEY on `identity_id` - Serves "which topics am I in?"

**Notes:**
- Topics carry **no messages**. They answer "who is in this group and what are
  their public keys?" so a sender can encrypt once per member and fan out via
  `POST /api/v2/messages/batch`. The server never re-encrypts, which is what
  keeps broadcast end-to-end
- Membership is readable only by members; a non-member gets 404, not 403, so
  the namespace cannot be enumerated by probing
- **These two tables are the one place the server learns the social graph.**
  Content stays private, but who is grouped with whom is visible to the operator
- The owner is always a member and cannot be removed — delete the topic instead

---

### Table: `prekey_bundles`

Signal-style prekeys. **Partially implemented and unused by v1 or v2.**

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `identity_id` (BIGINT UNSIGNED, NOT NULL) - FK to `identities.id`, ON DELETE CASCADE
- `bundle_uuid` (CHAR(36), UNIQUE, NOT NULL)
- `signed_prekey` (VARBINARY(64), NULL)
- `created_at` (DATETIME, NOT NULL)

---

### Table: `prekeys`

**Columns:**
- `id` (BIGINT UNSIGNED, PRIMARY KEY, AUTO_INCREMENT)
- `bundle_id` (BIGINT UNSIGNED, NOT NULL) - FK to `prekey_bundles.id`, ON DELETE CASCADE
- `public_key` (VARBINARY(64), NOT NULL)
- `is_used` (TINYINT(1), NOT NULL, DEFAULT 0)
- `created_at` (DATETIME, NOT NULL)
- `used_at` (DATETIME, NULL)

**Notes:**
- Surfaced in `GET /api/v?/identities/{id}` as `prekey_bundle`, but no client
  populates or consumes it. Neither protocol depends on these tables

---

## Schema drift

The live schema was altered by hand and diverged from the migrations. A fresh
`php spark migrate` produced a **narrower** schema than production — most
seriously `messages.ciphertext` as BLOB (64 KB) rather than LONGBLOB, which
would have silently capped message size for any new deployment, plus `INT`
rather than `BIGINT` ids across five tables and a phantom
`api_tokens.expires_at`.

`2026-09-10-000003_ReconcileProductionSchema` converges any database onto the
production definitions and is a no-op where they already match, so:

- fresh install: earlier migrations create the old types, this corrects them
- existing dev database: same, converges
- production: every column already matches, every step skipped

It is deliberately irreversible — rolling back would narrow LONGBLOB to BLOB
and truncate data, and the pre-drift schema was never correct.

```bash
php spark schema:check   # exits non-zero on drift
```

`ReconcileProductionSchema::COLUMNS` is the single source of truth for the
types it enforces; `SchemaCheck::ALSO_EXPECTED` covers a few extras. Run it
after any schema change, and add new expectations there rather than letting
drift accumulate again.

## Indexing Strategy

Verified against the live schema. Run `php spark schema:check` to confirm the
two indexes the application actually depends on are still present.

### What each index is for

1. **Token authentication** — `uniq_token_hash` UNIQUE on `api_tokens.token_hash`
   - Every authenticated request is one lookup by raw SHA-256
   - `is_active` is *not* part of the index; it is filtered after the seek.
     The hash alone is already unique, so a compound index would add nothing

2. **v2 paginated inbox** — `idx_messages_inbox` on
   (`recipient_id`, `api_version`, `id`)
   - Serves `WHERE recipient_id = ? AND api_version = 2 AND id > ?
     ORDER BY id ASC LIMIT ?` as an index-only range scan
   - **Load bearing.** The older `(recipient_id, created_at)` shape cannot
     satisfy an `id`-range cursor, so without this every poll degrades to a
     filesort over the recipient's entire backlog — and since a v2 inbox
     persists until ACKed, that backlog is unbounded
   - Also re-scanned every 500 ms by each parked long poll, so its cost is
     paid far more often than the request rate suggests

3. **Legacy inbox** — `idx_recipient_id` and `idx_created_at` on `messages`
   - Predate v2. `idx_recipient_id` still serves the v1 inbox
   - `idx_created_at` is not used by any current query path; it is a candidate
     for removal if write throughput ever matters

4. **Idempotency** — UNIQUE on `idempotency_keys` (`identity_id`, `idem_key`)
   - Not just a lookup: the uniqueness constraint *is* the concurrency control
     that stops two racing retries from both storing a message
   - `created_at` index serves the 24-hour prune

5. **Topic membership** — UNIQUE on `topic_members` (`topic_id`, `identity_id`),
   plus a plain index on `identity_id`
   - The unique key makes re-adding a member a no-op
   - The `identity_id` index answers "which topics am I in?" without a scan

6. **Foreign keys** — `api_tokens.identity_id`, `prekey_bundles.identity_id`,
   `prekeys.bundle_id`, `topics.owner_identity_id`
   - Required by InnoDB for the FK constraints, and used by cascade deletes

### Indexes this document previously claimed, which do not exist

Listed so nobody re-adds them believing they were lost:

- `(token_hash, is_active)` compound on `api_tokens` — redundant; the hash is unique
- `(recipient_id, created_at)` compound on `messages` — these are two separate
  single-column indexes
- `sender_id` on `messages` — never created; no query path needs it
- `(bundle_id, is_used)` compound on `prekeys` — only `bundle_id` exists

### Index maintenance

```sql
SHOW INDEX FROM messages;
SHOW INDEX FROM api_tokens;
SHOW INDEX FROM topic_members;

ANALYZE TABLE messages;
ANALYZE TABLE api_tokens;
```

Confirm the inbox index is actually being chosen:

```sql
EXPLAIN SELECT * FROM messages
 WHERE recipient_id = 'agent-bob' AND api_version = 2 AND id > 100
 ORDER BY id ASC LIMIT 51;
-- expect key: idx_messages_inbox, and no "Using filesort"
```

---

## Backup Recommendations

### AWS RDS Automated Backups

The production database runs on AWS RDS, which provides:
- Automated daily snapshots
- Point-in-time recovery (5-minute granularity)
- Retention period: 7-35 days (configurable)

**Configure via AWS Console:**
1. RDS → Databases → stringcup
2. Modify → Backup
3. Set retention period (recommend 7 days minimum)
4. Enable automatic backups

### Manual Backups

```bash
# Create manual snapshot via AWS CLI
aws rds create-db-snapshot \
  --db-instance-identifier stringcup \
  --db-snapshot-identifier stringcup-manual-$(date +%Y%m%d-%H%M%S)

# Export using mysqldump (for local backups)
mysqldump -h <rds-endpoint> -u stringcup -p stringcup > backup-$(date +%Y%m%d).sql

# Restore from mysqldump
mysql -h <rds-endpoint> -u stringcup -p stringcup < backup-20240101.sql
```

### Backup Strategy

**Recommended approach:**
- RDS automated backups: 7-day retention
- Weekly manual snapshots kept for 30 days
- Monthly archives for long-term retention
- Test restore procedure quarterly

**What to backup:**
- `identities` - Critical (user accounts)
- `api_tokens` - Important (access control)
- `prekey_bundles`, `prekeys` - Nice to have
- `messages` - **Do not backup** (ephemeral by design)

---

## Database Maintenance

### Cleanup Tasks

```sql
-- Remove expired tokens (30+ days inactive)
DELETE FROM api_tokens
WHERE is_active = 1
  AND last_used_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Clean up old messages (shouldn't exist if system working correctly)
DELETE FROM messages
WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Remove unused prekey bundles older than 90 days
DELETE FROM prekey_bundles
WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

**Cron Job Recommendation:**
```bash
# Add to crontab: Run cleanup daily at 3 AM
0 3 * * * /usr/bin/php /usr/share/nginx/html/stringcup.com/spark db:cleanup
```

### Monitoring Queries

```sql
-- Count active identities
SELECT COUNT(*) FROM identities;

-- Count active tokens
SELECT COUNT(*) FROM api_tokens WHERE is_active = 1;

-- Count pending messages (should be low)
SELECT COUNT(*) FROM messages;

-- Check messages older than 1 hour (potential delivery issue)
SELECT COUNT(*) FROM messages WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Check database size
SELECT
  table_name,
  ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = 'stringcup'
ORDER BY (data_length + index_length) DESC;
```

---

## Connection Configuration

**Environment Variables** (`.env`):
```ini
database.default.hostname = <rds-endpoint>.rds.amazonaws.com
database.default.database = stringcup
database.default.username = stringcup
database.default.password = <secure-password>
database.default.DBDriver = MySQLi
database.default.port = 3306
database.default.charset = utf8mb4
database.default.DBCollat = utf8mb4_general_ci
```

**Security Notes:**
- Never commit `.env` to version control
- Use AWS Secrets Manager for RDS credentials in production
- Restrict RDS security group to application server IPs only
- Enable SSL/TLS for database connections in production

---

## Troubleshooting

### Common Issues

**Migration fails with "Table already exists":**
```bash
# Check current migration status
php spark migrate:status

# Reset migrations (CAUTION: drops all tables)
php spark migrate:rollback --all
php spark migrate
```

**Foreign key constraint errors:**
- Ensure parent table exists before child table
- Migration order is critical (identities → api_tokens → messages)
- Check that foreign key data types match exactly

**Character encoding issues:**
- Ensure `utf8mb4` charset for emoji support
- Check that `utf8mb4_general_ci` collation is set
- Binary fields (VARBINARY, BINARY, BLOB) should not have charset

**Connection timeout:**
- Verify RDS security group allows inbound from application server
- Check that RDS instance is in "Available" state
- Test connection: `mysql -h <rds-endpoint> -u stringcup -p`

---

## Performance Tips

1. **Index all foreign keys** - Already done in migrations
2. **Use EXPLAIN** - Analyze slow queries
   ```sql
   EXPLAIN SELECT * FROM messages WHERE recipient_id = 'alice';
   ```
3. **Monitor slow query log** - Enable in RDS parameter group
4. **Connection pooling** - CodeIgniter uses persistent connections by default
5. **Batch operations** - Use `insertBatch()` for multiple inserts
6. **Limit result sets** - Add `->limit(100)` to queries

---

## Schema Versioning

Current schema version: **v3.0** (2026-09-10)

**Migration history:**

| Version | Migration |
|---|---|
| `2024-01-01-000001` | Create identities table |
| `2024-01-01-000002` | Create api_tokens table (creates a vestigial `expires_at`, dropped later) |
| `2024-01-01-000003` | Create messages table |
| `2024-01-01-000004` | Create prekey_bundles table |
| `2024-01-01-000005` | Create prekeys table |
| `2024-01-02-000001` | Add `api_version` to messages (v1/v2 partitioning) |
| `2026-09-10-000001` | Create idempotency_keys table |
| `2026-09-10-000002` | Add `idx_messages_inbox` for the v2 paginated inbox |
| `2026-09-10-000003` | **Reconcile production schema** — see [Schema drift](#schema-drift) |
| `2026-09-10-000004` | Add `key_updated_at` to identities (key-rotation detection) |
| `2026-09-10-000005` | Create topics and topic_members |
| `2026-09-10-000006` | Create rendezvous (agent pairing) |

The `2026-09-10-000003` reconcile migration is the reason the earlier ones can
be read literally without misleading you: it corrects what they produce to
match production. Do not "fix" the earlier migrations in place — they have
already run everywhere, and the reconcile step is what converges them.

**Deliberately not implemented:**

- *Read receipts* (`read_at` on messages) — the server cannot distinguish
  delivered from read without a client claim, and v2 already has an explicit
  ACK, which is the honest version of the same signal
- *Group messaging via `group_id` on messages* — this would mean one ciphertext
  for many recipients, which the v2 crypto cannot express and which would
  require the server to hold a key. Fan-out is client-side by design; see
  `topics` / `topic_members` and `POST /api/v2/messages/batch`
- *Multi-device* (`device_info` on api_tokens) — v2 is already multi-instance
  safe, since any holder of the identity key can decrypt independently

**Plausible future work:**

- Retention/TTL on `messages` — nothing currently ages out an unACKed inbox
- Prekey support, if forward secrecy is ever added (the tables exist but no
  protocol path uses them)

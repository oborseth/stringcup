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

### Table: `identities`

Stores user identity information and public keys for E2EE.

**Columns:**
- `id` (INT, PRIMARY KEY, AUTO_INCREMENT)
- `external_id` (VARCHAR(64), UNIQUE, NOT NULL) - User-chosen identifier (e.g., "alice", "bob")
- `display_name` (VARCHAR(255), NULL) - Optional display name
- `identity_pubkey` (VARBINARY(255), NOT NULL) - X25519 public key (32 bytes)
- `algo` (VARCHAR(20), NOT NULL) - Key algorithm ('ed25519' or 'x25519')
- `created_at` (DATETIME, NOT NULL)
- `updated_at` (DATETIME, NOT NULL)

**Indexes:**
- PRIMARY KEY on `id`
- UNIQUE KEY on `external_id`

**Notes:**
- `external_id` is the primary user-facing identifier
- `identity_pubkey` is stored as binary data (not base64)
- Keys should be exactly 32 bytes for X25519

---

### Table: `api_tokens`

Manages API authentication tokens with 30-day expiration.

**Columns:**
- `id` (INT, PRIMARY KEY, AUTO_INCREMENT)
- `identity_id` (INT, NOT NULL, FOREIGN KEY → identities.id)
- `token_hash` (BINARY(32), NOT NULL) - SHA-256 hash of bearer token
- `created_at` (DATETIME, NOT NULL)
- `last_used_at` (DATETIME, NULL) - Updated on each use, extends expiration
- `expires_at` (DATETIME, NULL) - Future use for explicit expiration
- `is_active` (TINYINT(1), NOT NULL, DEFAULT 1) - 0 = revoked/expired

**Indexes:**
- PRIMARY KEY on `id`
- KEY on (`token_hash`, `is_active`) - Fast auth lookup
- KEY on (`expires_at`, `is_active`) - Cleanup expired tokens
- FOREIGN KEY `identity_id` → `identities.id` (CASCADE DELETE)

**Token Lifecycle:**
1. Issued once during identity registration
2. Never sent again (one-time display)
3. Hashed with SHA-256 before storage
4. Expires 30 days after last use
5. Can be revoked by setting `is_active = 0`

---

### Table: `messages`

Ephemeral storage for encrypted messages (deleted after retrieval).

**Columns:**
- `id` (INT, PRIMARY KEY, AUTO_INCREMENT)
- `sender_id` (VARCHAR(64), NOT NULL) - external_id of sender
- `recipient_id` (VARCHAR(64), NOT NULL) - external_id of recipient
- `header_json` (TEXT, NOT NULL) - Message metadata (version, algo, seq, IV)
- `ciphertext` (BLOB, NOT NULL) - AES-256-GCM encrypted message
- `created_at` (DATETIME, NOT NULL)

**Indexes:**
- PRIMARY KEY on `id`
- KEY on (`recipient_id`, `created_at`) - Fast inbox queries
- KEY on `sender_id`

**Message Lifecycle:**
1. Created via POST /api/v1/messages
2. Stored temporarily in encrypted form
3. Retrieved via GET /api/v1/messages
4. **Deleted immediately after successful retrieval**

**Notes:**
- Messages are NOT persisted long-term
- No message history on server
- Fire-and-forget delivery model
- Server never decrypts messages

---

### Table: `prekey_bundles`

Signal-style prekey bundles for asynchronous messaging (partially implemented).

**Columns:**
- `id` (INT, PRIMARY KEY, AUTO_INCREMENT)
- `identity_id` (INT, NOT NULL, FOREIGN KEY → identities.id)
- `bundle_uuid` (VARCHAR(36), NOT NULL) - UUID for bundle identification
- `signed_prekey` (BLOB, NULL) - Signed prekey for key agreement
- `created_at` (DATETIME, NOT NULL)

**Indexes:**
- PRIMARY KEY on `id`
- KEY on `bundle_uuid`
- FOREIGN KEY `identity_id` → `identities.id` (CASCADE DELETE)

**Notes:**
- Part of Signal Double Ratchet protocol
- Currently not fully utilized in simplified implementation

---

### Table: `prekeys`

One-time prekeys for perfect forward secrecy (partially implemented).

**Columns:**
- `id` (INT, PRIMARY KEY, AUTO_INCREMENT)
- `bundle_id` (INT, NOT NULL, FOREIGN KEY → prekey_bundles.id)
- `public_key` (BLOB, NOT NULL) - One-time use public key
- `is_used` (TINYINT(1), NOT NULL, DEFAULT 0) - 1 = consumed
- `created_at` (DATETIME, NOT NULL)
- `used_at` (DATETIME, NULL) - Timestamp when key was used

**Indexes:**
- PRIMARY KEY on `id`
- KEY on (`bundle_id`, `is_used`) - Find unused prekeys
- FOREIGN KEY `bundle_id` → `prekey_bundles.id` (CASCADE DELETE)

**Notes:**
- One-time keys for session establishment
- Marked as used after consumption
- Provides forward secrecy in full Signal protocol

---

## Indexing Strategy

### Performance Optimizations

1. **Authentication** - `(token_hash, is_active)` compound index on `api_tokens`
   - Enables fast O(1) token lookups
   - Filters inactive tokens immediately

2. **Inbox Retrieval** - `(recipient_id, created_at)` compound index on `messages`
   - Retrieves user's messages in chronological order
   - Critical for `GET /api/v1/messages` performance

3. **Sender Queries** - `sender_id` index on `messages`
   - Optional for analytics or user message history views

4. **Prekey Lookup** - `(bundle_id, is_used)` compound index on `prekeys`
   - Finds available (unused) prekeys efficiently

### Index Maintenance

```sql
-- Check index usage
SHOW INDEX FROM identities;
SHOW INDEX FROM api_tokens;
SHOW INDEX FROM messages;

-- Analyze table for query optimization
ANALYZE TABLE messages;
ANALYZE TABLE api_tokens;
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

Current schema version: **v1.0** (2024-01-01)

**Migration History:**
- `2024-01-01-000001` - Create identities table
- `2024-01-01-000002` - Create api_tokens table (with expires_at)
- `2024-01-01-000003` - Create messages table
- `2024-01-01-000004` - Create prekey_bundles table
- `2024-01-01-000005` - Create prekeys table

**Future Enhancements:**
- Add `last_login_at` to identities
- Add `device_info` to api_tokens (multi-device support)
- Add `read_at` to messages (read receipts)
- Add `group_id` to messages (group messaging)

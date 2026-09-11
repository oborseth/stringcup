<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Reconcile the migration-defined schema with what production actually runs.
 *
 * The live database had been altered by hand and drifted from the migrations.
 * A fresh `php spark migrate` produced a *different, more restrictive* schema
 * than production — notably `messages.ciphertext` as BLOB (64 KB) instead of
 * LONGBLOB, silently capping message size for any new deployment.
 *
 * Rather than rewrite migrations that have already run, this one converges any
 * database onto the production definitions:
 *
 *   - fresh install: earlier migrations create the old types, this fixes them
 *   - existing dev DB: same, converges
 *   - production: every column already matches, so every step is skipped
 *
 * Drift is detectable going forward with `php spark schema:check`.
 *
 * Only the MySQL family is handled. SQLite (used by the PHPUnit suite) cannot
 * ALTER column types and does not enforce the width/type distinctions this
 * migration is about, so it is skipped there.
 */
class ReconcileProductionSchema extends Migration
{
    /**
     * Target column definitions, matching production exactly.
     *
     * Keyed table => column => [COLUMN_TYPE as information_schema reports it,
     * full DDL fragment for MODIFY].
     */
    private const COLUMNS = [
        'identities' => [
            'id'              => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'],
            'identity_pubkey' => ['varbinary(64)', 'VARBINARY(64) NOT NULL'],
            'algo'            => ['varchar(32)', "VARCHAR(32) NOT NULL DEFAULT 'ed25519'"],
        ],
        'api_tokens' => [
            'id'          => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'],
            'identity_id' => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL'],
            'token_hash'  => ['varbinary(32)', 'VARBINARY(32) NOT NULL'],
        ],
        'messages' => [
            'id'           => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'],
            'sender_id'    => ['varchar(191)', 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'],
            'recipient_id' => ['varchar(191)', 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'],
            'ciphertext'   => ['longblob', 'LONGBLOB NOT NULL'],
        ],
        // These two are ours; widen the FK-ish columns so they cannot overflow
        // before the bigint tables they point at do.
        'idempotency_keys' => [
            'id'          => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'],
            'identity_id' => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL'],
            'message_id'  => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL'],
        ],
        'prekey_bundles' => [
            'id'          => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'],
            'identity_id' => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL'],
        ],
        'prekeys' => [
            'id'        => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'],
            'bundle_id' => ['bigint(20) unsigned', 'BIGINT UNSIGNED NOT NULL'],
        ],
    ];

    /**
     * Columns the migrations create but production never had.
     *
     * `api_tokens.expires_at` is vestigial: expiry is computed from
     * last_used_at (see ApiTokenModel::expiresAtTimestamp), never stored.
     */
    private const DROP_COLUMNS = [
        'api_tokens' => ['expires_at'],
    ];

    public function up()
    {
        if (! $this->isMySQL()) {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            foreach ($columns as $column => [$targetType, $ddl]) {
                if ($this->columnType($table, $column) === $targetType) {
                    continue; // already correct — production path
                }

                // AUTO_INCREMENT columns must keep their key; MODIFY preserves it.
                $this->db->query(sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` %s',
                    $table,
                    $column,
                    $ddl
                ));
            }
        }

        foreach (self::DROP_COLUMNS as $table => $columns) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if ($this->columnType($table, $column) !== null) {
                    $this->forge->dropColumn($table, $column);
                }
            }
        }
    }

    /**
     * Intentionally irreversible.
     *
     * Rolling back would mean narrowing LONGBLOB to BLOB and bigint to int,
     * which truncates stored data. The pre-drift schema was never correct, so
     * there is nothing worth restoring.
     */
    public function down()
    {
        // no-op
    }

    private function isMySQL(): bool
    {
        return in_array($this->db->DBDriver, ['MySQLi', 'mysqli', 'MySQL'], true);
    }

    /**
     * COLUMN_TYPE as information_schema reports it, or null if absent.
     */
    private function columnType(string $table, string $column): ?string
    {
        $row = $this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->getRowArray();

        return $row['COLUMN_TYPE'] ?? null;
    }
}

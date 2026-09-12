<?php

namespace App\Models;

use CodeIgniter\Model;

class IdempotencyKeyModel extends Model
{
    protected $table      = 'idempotency_keys';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'identity_id',
        'idem_key',
        'message_id',
        // The sender's own sequence, echoed by a replay. Recorded here because
        // it must outlive the message row, which an ACK deletes.
        'sent_seq',
        'created_at',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;

    /**
     * Idempotency records are only useful for as long as a client might
     * plausibly retry a timed-out send.
     */
    public const RETENTION_HOURS = 24;

    /**
     * Look up a prior send by this identity with the same key.
     */
    public function findReplay(int $identityId, string $key): ?array
    {
        return $this->where('identity_id', $identityId)
            ->where('idem_key', $key)
            ->first();
    }

    /**
     * Opportunistically drop records past the retention window.
     *
     * Called on the send path rather than from cron so the table stays bounded
     * without adding an operational dependency; the delete is indexed on
     * created_at and touches only expired rows.
     */
    public function pruneExpired(): void
    {
        $this->where('created_at <', date('Y-m-d H:i:s', time() - self::RETENTION_HOURS * 3600))
            ->delete();
    }
}

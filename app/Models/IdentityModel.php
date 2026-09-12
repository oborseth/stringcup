<?php

namespace App\Models;

use CodeIgniter\Model;

class IdentityModel extends Model
{
    protected $table      = 'identities';
    protected $primaryKey = 'id';

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    // We’ll set created_at / updated_at ourselves in the controller
    protected $useTimestamps = false;

    protected $allowedFields = [
        'external_id',
        'display_name',
        'identity_pubkey',
        'algo',
        'created_at',
        'updated_at',
        // Set only when identity_pubkey actually changes, so peers can detect
        // key rotation independently of unrelated profile edits.
        'key_updated_at',
    ];

    protected $validationRules = [
        'external_id'     => 'required|min_length[1]|max_length[64]',
        'algo'            => 'required|in_list[ed25519,x25519]',
    ];

    protected $validationMessages = [
        'external_id' => [
            'required'   => 'external_id is required.',
            'max_length' => 'external_id cannot exceed 64 characters.',
        ],
        'identity_pubkey' => [
            'required' => 'identity_public_key is required.',
        ],
        'algo' => [
            'required' => 'algo is required.',
            'in_list'  => 'algo must be one of: ed25519, x25519.',
        ],
    ];

    protected $skipValidation = false;

    /** Counter columns that may be claimed. Guards the interpolation below. */
    public const SEQ_RECEIVED = 'next_recv_seq';
    public const SEQ_SENT     = 'next_sent_seq';

    /**
     * Atomically take the next sequence number for one identity.
     *
     * Messages carry no global public id — each party numbers them in its own
     * space, so nothing comparable across conversations is ever published.
     * See the PerRecipientMessageSequence migration for why.
     *
     * `LAST_INSERT_ID(col)` returns the *old* value and sets the session's
     * last-insert-id to it as a side effect, so the claim and the read are one
     * statement plus one cheap session read — no transaction, no
     * `SELECT ... FOR UPDATE`, and no window in which two concurrent sends
     * could take the same number. The unique key on
     * (recipient_id, recipient_seq) is the backstop if that ever stops being
     * true.
     *
     * @param string $column One of the SEQ_* constants.
     */
    public function claimSequence(string $externalId, string $column): int
    {
        if (!in_array($column, [self::SEQ_RECEIVED, self::SEQ_SENT], true)) {
            throw new \InvalidArgumentException("Not a sequence column: {$column}");
        }

        $db = $this->db;

        $db->query(
            "UPDATE identities SET {$column} = LAST_INSERT_ID({$column}) + 1 WHERE external_id = ?",
            [$externalId]
        );

        if ($db->affectedRows() === 0) {
            throw new \RuntimeException("No such identity: {$externalId}");
        }

        return (int) $db->query('SELECT LAST_INSERT_ID() AS seq')->getRow()->seq;
    }
}


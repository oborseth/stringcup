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
}


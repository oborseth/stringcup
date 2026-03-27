<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiTokenModel extends Model
{
    protected $table      = 'api_tokens';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'identity_id',
        'token_hash',
        'created_at',
        'last_used_at',
        'is_active',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;
}


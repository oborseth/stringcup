<?php

namespace App\Models;

use CodeIgniter\Model;

class PrekeyBundleModel extends Model
{
    protected $table      = 'prekey_bundles';
    protected $primaryKey = 'id';

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'identity_id',
        'bundle_uuid',
        'signed_prekey',
        'created_at',
    ];

    protected $validationRules = [
        'identity_id' => 'required|is_natural_no_zero',
        'bundle_uuid' => 'required|min_length[36]|max_length[36]',
    ];
}


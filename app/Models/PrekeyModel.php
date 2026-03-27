<?php

namespace App\Models;

use CodeIgniter\Model;

class PrekeyModel extends Model
{
    protected $table      = 'prekeys';
    protected $primaryKey = 'id';

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'bundle_id',
        'public_key',
        'is_used',
        'created_at',
        'used_at',
    ];

    protected $validationRules = [
        'bundle_id'  => 'required|is_natural_no_zero',
        'public_key' => 'required',
    ];
}


<?php

namespace App\Models;

use CodeIgniter\Model;

class TopicModel extends Model
{
    protected $table      = 'topics';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'name',
        'owner_identity_id',
        'created_at',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;

    public function findByName(string $name): ?array
    {
        return $this->where('name', $name)->first();
    }
}

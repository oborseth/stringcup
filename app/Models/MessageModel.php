<?php

namespace App\Models;

use CodeIgniter\Model;

class MessageModel extends Model
{
    protected $table      = 'messages';
    protected $primaryKey = 'id';

    // If you want stricter protection, you can set this to true,
    // but then make sure all used fields are listed in $allowedFields.
    protected $protectFields = false;

    protected $allowedFields = [
        'sender_id',
        'recipient_id',
        // Per-party numbering. The public identifier of a message is the
        // recipient's sequence; `id` is internal and never published.
        'recipient_seq',
        'sender_seq',
        'header_json',
        'ciphertext',
        'created_at',
        'api_version',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;
}


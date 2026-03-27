<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\IdentityModel;

class Test extends BaseController
{
    public function index()
    {
        return $this->response->setJSON([
            'message' => 'Test controller is working!'
        ]);
    }

public function identityTest()
{
    $model = new IdentityModel();

    $now = date('Y-m-d H:i:s');

    // Insert a dummy row with binary public key
    $insertId = $model->insert([
        'external_id'     => 'test_user_' . bin2hex(random_bytes(3)),
        'display_name'    => 'Test User',
        'identity_pubkey' => random_bytes(32), // raw binary in DB is fine
        'algo'            => 'ed25519',
        'created_at'      => $now,
        'updated_at'      => $now,
    ], true);

    $row = $model->find($insertId);

    // 🔑 Convert binary to base64 for JSON output
    if (isset($row['identity_pubkey'])) {
        $row['identity_pubkey'] = base64_encode($row['identity_pubkey']);
    }

    return $this->response->setJSON([
        'status'   => 'ok',
        'insertId' => $insertId,
        'row'      => $row,
    ]);
}

}


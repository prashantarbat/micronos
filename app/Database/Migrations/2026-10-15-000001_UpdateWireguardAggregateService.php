<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateWireguardAggregateService extends Migration
{
    public function up()
    {
        $this->db->table('system_services')->where('id', 'wireguard')->update([
            'name'        => 'WireGuard tunnels',
            'description' => 'Aggregate lifecycle control for configured WireGuard tunnel interfaces',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        $this->db->table('system_services')->where('id', 'wireguard')->update([
            'name'        => 'wireguard',
            'description' => 'WireGuard Fast Kernel Cryptographic Tunnel Module (wg0)',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
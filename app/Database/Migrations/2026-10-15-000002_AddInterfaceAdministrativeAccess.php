<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddInterfaceAdministrativeAccess extends Migration
{
    public function up()
    {
        $this->forge->addColumn('interfaces', [
            'admin_access' => [
                'type'    => 'TEXT',
                'null'    => true,
                'default' => null,
            ],
        ]);

        foreach ($this->db->table('interfaces')->get()->getResultArray() as $interface) {
            $id = strtolower((string) ($interface['id'] ?? ''));
            $name = strtolower((string) ($interface['name'] ?? ''));
            if (str_starts_with($id, 'lan') || str_starts_with($name, 'lan')) {
                $access = ['http', 'https', 'ping', 'ssh'];
            } elseif (str_starts_with($id, 'opt') || str_starts_with($name, 'opt')) {
                $access = ['http', 'https', 'ping'];
            } elseif (str_starts_with($id, 'wan') || str_starts_with($name, 'wan')) {
                $access = ['ping'];
            } else {
                $access = [];
            }
            $this->db->table('interfaces')->where('id', $interface['id'])->update([
                'admin_access' => json_encode($access, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down()
    {
        $this->forge->dropColumn('interfaces', 'admin_access');
    }
}
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDhcpServerSettingsTable extends Migration
{
    public function up()
    {
        // 1. Create dhcp_server_settings for per-interface DHCP configuration
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'deny_unknown' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'range_from' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'range_to' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'gateway' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'domain_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'domain_search' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'dns_1' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'dns_2' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'dns_3' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'dns_4' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'default_lease_time' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 7200,
            ],
            'max_lease_time' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 86400,
            ],
            'ntp_1' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'ntp_2' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'tftp_server' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'next_server' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'bootfile_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'wins_1' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'wins_2' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'custom_options' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('interface_id');
        $this->forge->createTable('dhcp_server_settings', true);

        // 2. Add interface_id to dhcp_reservations if not exists
        if (!$this->db->fieldExists('interface_id', 'dhcp_reservations')) {
            $this->forge->addColumn('dhcp_reservations', [
                'interface_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'lan',
                    'after'      => 'id',
                ],
            ]);
        }
    }

    public function down()
    {
        $this->forge->dropTable('dhcp_server_settings', true);
    }
}

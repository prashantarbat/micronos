<?php

namespace App\Database\Migrations;

use App\Libraries\InternetService;
use CodeIgniter\Database\Migration;

/**
 * Internet Service Database
 * -------------------------
 * A catalogue of well-known SaaS / cloud / threat platforms, each backed by a
 * set of published IP prefixes. An admin references a service from a firewall
 * address object (type `internet-service`) instead of hand-maintaining CIDRs.
 *
 * `prefixes` holds an optional inline snapshot (JSON list of CIDR strings) so a
 * set is usable before micrond refreshes it from `source_url`.
 */
class CreateInternetServicesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'General',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'source_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
            ],
            'prefixes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'last_synced_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('internet_services', true);

        // Seed the catalogue. Idempotent so a re-run never duplicates rows.
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        foreach (InternetService::SERVICES as $key => $svc) {
            if ($db->table('internet_services')->where('id', $key)->countAllResults() > 0) {
                continue;
            }

            $db->table('internet_services')->insert([
                'id'          => $key,
                'name'        => $svc['label'],
                'category'    => $svc['category'],
                'description' => $svc['description'],
                'source_url'  => null,
                'prefixes'    => null,
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('internet_services', true);
    }
}

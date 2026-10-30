<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How access to the Clientless Gateway port is decided.
 *
 *  - open     : the gateway port is accepted from every interface (the login
 *               page and MFA are the only gate) — the original behaviour
 *  - firewall : the port is checked against firewall policies whose Outgoing
 *               Interface is "This Firewall"; unmatched sources are dropped
 *               and logged (NftablesCompiler::compileZtnaGatewayChain)
 */
class AddAccessModeToZtnaSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ztna_settings', [
            'access_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'open',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ztna_settings', ['access_mode']);
    }
}

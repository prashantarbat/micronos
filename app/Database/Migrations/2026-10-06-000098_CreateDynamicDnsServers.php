<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Dynamically assigned DNS servers (Architecture §21.2).
 *
 * The DNS Server Override checkbox on `/dns?tab=settings` says that resolvers
 * handed to the appliance by a DHCP/PPP server on WAN or by a remote OpenVPN
 * peer (Pull DNS) may override the manually configured list. That checkbox is
 * only half the feature: something has to *record* which servers the dynamic
 * links actually assigned so the compiler can merge them in (or drop them when
 * the override is off).
 *
 * This table is that record. The interface path — `Interfaces::apply()`,
 * `Pppoe::apply()` and `VpnOpenvpn::restart()` — queries the data plane for the
 * servers its dynamic links currently hold and reconciles this table, then
 * recompiles `/etc/resolv.conf` through `SystemResolverCompiler`. It is never
 * consulted for Unbound: the resolver that serves LAN clients is unaffected by
 * the override, and these addresses are never handed to DHCP clients.
 *
 * One row per (source, interface, address). Rows are replaced wholesale on each
 * reconcile, so a link that goes down (or an override that is cleared) leaves
 * nothing behind.
 */
class CreateDynamicDnsServers extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('dynamic_dns_servers')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'comment'    => 'dhcp | ppp | openvpn — which dynamic link assigned it',
            ],
            'interface_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'interfaces.id when the link maps to a configured interface',
            ],
            'interface_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'Human/daemon-visible link name, e.g. WAN or ovpn-client1',
            ],
            'address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'comment'    => 'The assigned resolver, a literal IPv4 or IPv6 address',
            ],
            'assigned_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('dynamic_dns_servers', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('dynamic_dns_servers', true);
    }
}

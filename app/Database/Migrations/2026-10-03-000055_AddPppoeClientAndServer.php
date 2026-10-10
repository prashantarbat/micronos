<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * PPPoE client & server.
 * ----------------------
 * `interfaces.ip_assignment_type` has always accepted a `pppoe` value, but the
 * mode stored nothing and configured nothing — selecting it silently produced a
 * static interface. This adds the missing half:
 *
 *  - the client settings an interface needs to dial a PPPoE access concentrator
 *    (credentials, service name, AC name, MTU/MRU, idle timeout, and the
 *    optional "use this link as the default route" switch);
 *  - a `pppoe_servers` table for the appliance's own PPPoE access concentrator,
 *    one row per server bound to a local interface;
 *  - a `pppoe_server_users` table holding the subscriber credentials that server
 *    authenticates.
 *
 * Passwords are stored encrypted (CI4 Encrypter), so the columns are TEXT.
 * The access concentrator name (`pppoe_ac_name`) is distinct from the local
 * server name: one identifies the remote provider, the other identifies us.
 */
class AddPppoeClientAndServer extends Migration
{
    public function up(): void
    {
        // ---- PPPoE client settings on the interface ----
        $clientColumns = [
            'pppoe_username'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
            'pppoe_password'    => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'pppoe_service'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
            'pppoe_ac_name'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
            'pppoe_mtu'         => ['type' => 'INTEGER', 'null' => true, 'default' => null],
            'pppoe_mru'         => ['type' => 'INTEGER', 'null' => true, 'default' => null],
            'pppoe_idle_timeout'=> ['type' => 'INTEGER', 'null' => false, 'default' => 0],
            'pppoe_default_route' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
        ];

        foreach ($clientColumns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'interfaces')) {
                $this->forge->addColumn('interfaces', [$name => $definition]);
            }
        }

        // ---- PPPoE access concentrator ----
        if (! $this->db->tableExists('pppoe_servers')) {
            $this->forge->addField([
                'id'                => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'name'              => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'interface'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
                'service_name'      => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false, 'default' => 'MicronOS'],
                'ac_name'           => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
                'local_ip'          => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => false],
                'remote_start'      => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => false],
                'remote_end'        => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => false],
                'dns1'              => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
                'dns2'              => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
                'auth_required'     => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
                'idle_timeout'      => ['type' => 'INTEGER', 'null' => false, 'default' => 0],
                'max_sessions'      => ['type' => 'INTEGER', 'null' => false, 'default' => 0],
                'description'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
                'enabled'           => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
                'created_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
                'updated_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('name');
            $this->forge->createTable('pppoe_servers', true);
        }

        // ---- PPPoE subscribers ----
        if (! $this->db->tableExists('pppoe_server_users')) {
            $this->forge->addField([
                'id'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'server_id'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'username'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'password'       => ['type' => 'TEXT', 'null' => false],
                'static_ip'      => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
                'dns1'           => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
                'dns2'           => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'default' => null],
                'rate_limit'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'default' => null],
                'description'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
                'enabled'        => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
                'created_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['server_id', 'username']);
            $this->forge->createTable('pppoe_server_users', true);
        }

        // ---- Permissions ----
        $this->seedPermissions();
    }

    /**
     * Adds the PPPoE permission keys, granting them to the roles that already
     * manage interfaces and routing. Insert-only and idempotent: re-running the
     * migration (or the seeder) leaves existing grants untouched.
     */
    private function seedPermissions(): void
    {
        if (! $this->db->tableExists('permissions')) {
            return;
        }

        $catalog = [
            ['key' => 'pppoe.client.manage', 'module' => 'Interfaces', 'label' => 'Manage PPPoE Client Settings'],
            ['key' => 'pppoe.server.view',   'module' => 'PPPoE',      'label' => 'View PPPoE Server'],
            ['key' => 'pppoe.server.manage', 'module' => 'PPPoE',      'label' => 'Manage PPPoE Server & Subscribers'],
            ['key' => 'pppoe.server.apply',  'module' => 'PPPoE',      'label' => 'Apply PPPoE Server Changes'],
        ];

        $now     = date('Y-m-d H:i:s');
        $permIds = [];

        foreach ($catalog as $perm) {
            $existing = $this->db->table('permissions')->where('key', $perm['key'])->get()->getRowArray();
            if ($existing) {
                $permIds[$perm['key']] = $existing['id'];

                continue;
            }

            $id = $this->uuid();
            $this->db->table('permissions')->insert([
                'id'          => $id,
                'key'         => $perm['key'],
                'label'       => $perm['label'],
                'module'      => $perm['module'],
                'description' => null,
                'created_at'  => $now,
            ]);
            $permIds[$perm['key']] = $id;
        }

        // Grant to roles that already own the matching domain. SuperAdmin holds
        // everything already, so it is left alone.
        $grants = [
            'NetworkAdmin' => ['pppoe.client.manage', 'pppoe.server.view', 'pppoe.server.manage', 'pppoe.server.apply'],
            'Operator'     => ['pppoe.server.view'],
        ];

        foreach ($grants as $roleName => $keys) {
            $role = $this->db->table('roles')->where('name', $roleName)->get()->getRowArray();
            if (! $role) {
                continue;
            }

            foreach ($keys as $key) {
                if (! isset($permIds[$key])) {
                    continue;
                }

                $exists = $this->db->table('role_permissions')
                    ->where('role_id', $role['id'])
                    ->where('permission_id', $permIds[$key])
                    ->countAllResults();

                if (! $exists) {
                    $this->db->table('role_permissions')->insert([
                        'role_id'       => $role['id'],
                        'permission_id' => $permIds[$key],
                        'granted'       => 1,
                        'created_at'    => $now,
                    ]);
                }
            }
        }
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function down(): void
    {
        $this->forge->dropTable('pppoe_server_users', true);
        $this->forge->dropTable('pppoe_servers', true);

        // CI4's SQLite table rebuild cannot drop an unindexed column through the
        // forge, so the raw statement is used where the driver needs it.
        $columns = [
            'pppoe_username', 'pppoe_password', 'pppoe_service', 'pppoe_ac_name',
            'pppoe_mtu', 'pppoe_mru', 'pppoe_idle_timeout', 'pppoe_default_route',
        ];

        foreach ($columns as $column) {
            if (! $this->db->fieldExists($column, 'interfaces')) {
                continue;
            }

            if ($this->db->DBDriver === 'SQLite3') {
                $table = $this->db->prefixTable('interfaces');
                $this->db->query("ALTER TABLE {$table} DROP COLUMN {$column}");

                continue;
            }

            $this->forge->dropColumn('interfaces', $column);
        }
    }
}

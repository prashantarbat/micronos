<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 124: Creates tables and permissions for CVEs & Security Advisories
 *
 * Provides authenticated local vulnerability auditing for MicronOS and Debian packages,
 * NIST NVD and Debian Security Tracker integration, and scan history.
 */
class CreateCveAdvisoriesTables extends Migration
{
    private const PERMISSIONS = [
        [
            'id'          => 'perm_security_cve_view',
            'key'         => 'security.cve.view',
            'module'      => 'Security',
            'label'       => 'View CVEs & Security Advisories',
            'description' => 'View system package vulnerability audits and NIST NVD security advisories',
        ],
        [
            'id'          => 'perm_security_cve_scan',
            'key'         => 'security.cve.scan',
            'module'      => 'Security',
            'label'       => 'Run Authenticated CVE Scans',
            'description' => 'Trigger authenticated root vulnerability scans and refresh online CVE feeds',
        ],
    ];

    private const GRANTS = [
        'SecurityAdmin' => ['security.cve.view', 'security.cve.scan'],
        'NetworkAdmin'  => ['security.cve.view'],
        'AuditAdmin'    => ['security.cve.view'],
    ];

    public function up(): void
    {
        // 1. Table: cve_advisories
        if (! $this->forge->getConnection()->tableExists('cve_advisories')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                ],
                'package_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'null'       => false,
                ],
                'installed_version' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                ],
                'fixed_version' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => true,
                    'default'    => null,
                ],
                'severity' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => false,
                    'default'    => 'MEDIUM', // CRITICAL, HIGH, MEDIUM, LOW
                ],
                'cvss_score' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '3,1',
                    'null'       => false,
                    'default'    => 5.0,
                ],
                'title' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => false,
                ],
                'summary' => [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'default' => null,
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                    'default'    => 'vulnerable', // vulnerable, patched, not_affected
                ],
                'attack_vector' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                    'default'    => 'NETWORK', // NETWORK, LOCAL, ADJACENT, PHYSICAL
                ],
                'source' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                    'default'    => 'NIST NVD',
                ],
                'nvd_url' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => false,
                ],
                'debian_url' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'default'    => null,
                ],
                'remediation' => [
                    'type'    => 'TEXT',
                    'null'    => true,
                    'default' => null,
                ],
                'published_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
                'scanned_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
                'updated_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('package_name');
            $this->forge->addKey('severity');
            $this->forge->addKey('status');
            $this->forge->createTable('cve_advisories', true);
        }

        // 2. Table: cve_scans
        if (! $this->forge->getConnection()->tableExists('cve_scans')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                ],
                'scan_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                    'default'    => 'authenticated_root',
                ],
                'total_packages' => [
                    'type'    => 'INT',
                    'null'    => false,
                    'default' => 0,
                ],
                'vulnerabilities_found' => [
                    'type'    => 'INT',
                    'null'    => false,
                    'default' => 0,
                ],
                'critical_count' => [
                    'type'    => 'INT',
                    'null'    => false,
                    'default' => 0,
                ],
                'high_count' => [
                    'type'    => 'INT',
                    'null'    => false,
                    'default' => 0,
                ],
                'medium_count' => [
                    'type'    => 'INT',
                    'null'    => false,
                    'default' => 0,
                ],
                'low_count' => [
                    'type'    => 'INT',
                    'null'    => false,
                    'default' => 0,
                ],
                'duration_ms' => [
                    'type'    => 'INT',
                    'null'    => false,
                    'default' => 0,
                ],
                'scanned_by' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => false,
                    'default'    => 'root',
                ],
                'status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                    'default'    => 'completed', // completed, running, failed
                ],
                'kernel_version' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'null'       => true,
                    'default'    => null,
                ],
                'os_release' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'null'       => true,
                    'default'    => null,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('cve_scans', true);
        }

        // 3. Table: cve_settings
        if (! $this->forge->getConnection()->tableExists('cve_settings')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                ],
                'auto_scan_enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 1,
                ],
                'auto_scan_cron' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => false,
                    'default'    => '0 3 * * *',
                ],
                'min_alert_severity' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'null'       => false,
                    'default'    => 'HIGH',
                ],
                'nvd_api_key' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'null'       => true,
                    'default'    => null,
                ],
                'offline_mode' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 0,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
                'updated_at' => [
                    'type'    => 'DATETIME',
                    'null'    => true,
                    'default' => null,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('cve_settings', true);

            // Default settings row
            $now = date('Y-m-d H:i:s');
            $this->db->table('cve_settings')->insert([
                'id'                 => 'default',
                'auto_scan_enabled'  => 1,
                'auto_scan_cron'     => '0 3 * * *',
                'min_alert_severity' => 'HIGH',
                'offline_mode'       => 0,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        // 4. Permissions & Role Grants
        if ($this->db->tableExists('permissions')) {
            $now = date('Y-m-d H:i:s');
            $ids = [];
            $fields = $this->db->getFieldNames('permissions');
            foreach (self::PERMISSIONS as $p) {
                $row = $this->db->table('permissions')->where('key', $p['key'])->get()->getRowArray();
                if ($row) {
                    $ids[$p['key']] = $row['id'];
                    continue;
                }
                $this->db->table('permissions')->insert(array_intersect_key($p + ['created_at' => $now], array_flip($fields)));
                $ids[$p['key']] = $p['id'];
            }

            if ($this->db->tableExists('roles') && $this->db->tableExists('role_permissions')) {
                foreach (self::GRANTS as $roleName => $keys) {
                    $role = $this->db->table('roles')->where('name', $roleName)->get()->getRowArray();
                    if (! $role) {
                        continue;
                    }
                    foreach ($keys as $key) {
                        if (isset($ids[$key]) && $this->db->table('role_permissions')->where('role_id', $role['id'])->where('permission_id', $ids[$key])->countAllResults() === 0) {
                            $this->db->table('role_permissions')->insert([
                                'role_id'       => $role['id'],
                                'permission_id' => $ids[$key],
                                'granted'       => 1,
                                'created_at'    => $now,
                            ]);
                        }
                    }
                }
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('cve_settings', true);
        $this->forge->dropTable('cve_scans', true);
        $this->forge->dropTable('cve_advisories', true);

        if ($this->db->tableExists('permissions')) {
            $this->db->table('permissions')->whereIn('key', ['security.cve.view', 'security.cve.scan'])->delete();
        }
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration: Consolidate mechanism-style NAT into object-style NAT
 *
 * Port Forwarding (nat_rules) and 1:1 NAT (nat_one_to_one) are both destination
 * NAT and are converted into Virtual IPs — 1:1 rows carry the new
 * `bidirectional` flag. The manual outbound SNAT list is superseded by the
 * policy + IP Pool model and is retired as well.
 *
 * Retained: nat_settings (reflection + outbound mode) and nat_nptv6.
 */
class ConsolidateNatObjects extends Migration
{
    public function up(): void
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // Historical consolidation. If the NAT object table has already been
        // renamed to nat_dnat (2026-10-03-000046) the work below is finished,
        // and re-running it would touch tables that no longer exist.
        if (! $db->tableExists('nat_vips')) {
            return;
        }

        // 1. Virtual IPs gain a bi-directional (1:1 NAT) flag
        $vipCols = array_map(fn ($c) => $c->name, $db->getFieldData('nat_vips'));
        if (! in_array('bidirectional', $vipCols, true)) {
            $this->forge->addColumn('nat_vips', [
                'bidirectional' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
            ]);
        }

        // Interface id -> configured IPv4 address (a port-forward's "external"
        // address is really the address of the interface it listens on).
        $ifaceIps = [];
        foreach ($db->table('interfaces')->get()->getResultArray() as $iface) {
            $ifaceIps[strtolower((string) $iface['id'])] = trim((string) ($iface['ipv4_address'] ?? ''));
        }

        // 2. Port-forward rules -> Virtual IPs
        if ($db->tableExists('nat_rules')) {
            foreach ($db->table('nat_rules')->where('rule_type', 'dnat')->get()->getResultArray() as $r) {
                $externalIp = $ifaceIps[strtolower((string) ($r['interface_id'] ?? ''))] ?? '';
                if ($externalIp === '') {
                    $externalIp = '0.0.0.0';
                }
                $db->table('nat_vips')->insert([
                    'id'             => 'vip_' . bin2hex(random_bytes(6)),
                    'name'           => $this->uniqueVipName($db, trim((string) ($r['description'] ?? '')), (string) ($r['id'] ?? '')),
                    'interface_id'   => $r['interface_id'] ?: 'wan',
                    'external_ip'    => $externalIp,
                    'external_port'  => ! empty($r['dest_port']) ? (int) $r['dest_port'] : null,
                    'protocol'       => $r['protocol'] ?: 'tcp',
                    'mapped_ip'      => (string) ($r['target_ip'] ?? ''),
                    'mapped_port'    => ! empty($r['target_port']) ? (int) $r['target_port'] : null,
                    'nat_reflection' => 1,
                    'bidirectional'  => 0,
                    'comment'        => 'Migrated from port-forward rule',
                    'enabled'        => (int) ($r['enabled'] ?? 1),
                    'created_at'     => $r['created_at'] ?? $now,
                    'updated_at'     => $now,
                ]);
            }
        }

        // 3. 1:1 NAT mappings -> bi-directional Virtual IPs
        if ($db->tableExists('nat_one_to_one')) {
            foreach ($db->table('nat_one_to_one')->get()->getResultArray() as $r) {
                $db->table('nat_vips')->insert([
                    'id'             => 'vip_' . bin2hex(random_bytes(6)),
                    'name'           => $this->uniqueVipName($db, trim((string) ($r['description'] ?? '')), (string) ($r['id'] ?? '')),
                    'interface_id'   => $r['interface_id'] ?: 'wan',
                    'external_ip'    => (string) ($r['external_ip'] ?? ''),
                    'external_port'  => null,
                    'protocol'       => 'any',
                    'mapped_ip'      => (string) ($r['internal_ip'] ?? ''),
                    'mapped_port'    => null,
                    'nat_reflection' => (int) ($r['nat_reflection'] ?? 1),
                    'bidirectional'  => 1,
                    'comment'        => 'Migrated from 1:1 NAT',
                    'enabled'        => (int) ($r['enabled'] ?? 1),
                    'created_at'     => $r['created_at'] ?? $now,
                    'updated_at'     => $now,
                ]);
            }
        }

        // 4. Normalise the legacy outbound mode name ('hybrid' -> 'automatic').
        // Manual/disabled modes are preserved.
        if ($db->tableExists('nat_settings')) {
            $db->table('nat_settings')->where('outbound_mode', 'hybrid')->update([
                'outbound_mode' => 'automatic',
                'updated_at'    => $now,
            ]);
        }

        // 5. Retire the mechanism tables now that their rows are objects
        $this->forge->dropTable('nat_outbound_rules', true);
        $this->forge->dropTable('nat_one_to_one', true);
        $this->forge->dropTable('nat_rules', true);
    }

    /**
     * Produces a human-friendly, unique Virtual IP name.
     */
    private function uniqueVipName($db, string $preferred, string $sourceId): string
    {
        $name = $preferred !== '' ? $preferred : ('NAT Object ' . substr($sourceId, 0, 8));
        $name = substr($name, 0, 64);
        $base = $name;
        $n = 1;
        while ($db->table('nat_vips')->where('name', $name)->countAllResults() > 0) {
            $suffix = ' (' . (++$n) . ')';
            $name = substr($base, 0, 64 - strlen($suffix)) . $suffix;
        }
        return $name;
    }

    public function down(): void
    {
        // The retired tables are owned by earlier migrations; this consolidation
        // is not reversible (see 000003 / 000027 for their original definitions).
    }
}

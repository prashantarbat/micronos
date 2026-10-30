<?php

namespace App\Libraries;

/**
 * GatewayAccessPolicy — the firewall objects behind "Gateway access:
 * controlled by firewall rules".
 *
 * Switching that mode on must not lock anybody out, so it seeds one policy that
 * allows every source and logs it. The operator then adds narrower allow
 * policies above it and finally turns this one into a deny, tightening access
 * step by step from Firewall → Rules.
 */
final class GatewayAccessPolicy
{
    /** Service object matching the gateway port. */
    public const SERVICE_NAME = 'ZTNA-Gateway';

    /** Name of the seeded allow-all policy. */
    public const STARTER_NAME = 'ZTNA Gateway - allow all (tighten me)';

    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Make sure the service object exists for $port and that at least one
     * This-Firewall policy covers the gateway; create the starter policy when
     * none does.
     *
     * @return array{service_created: bool, policy_created: bool}
     */
    public function ensure(int $port): array
    {
        $now = date('Y-m-d H:i:s');

        $svc = $this->db->table('firewall_services')->where('name', self::SERVICE_NAME)->get()->getRowArray();
        $serviceCreated = false;
        if ($svc === null) {
            $this->db->table('firewall_services')->insert([
                'id'         => 'svc_' . bin2hex(random_bytes(6)),
                'name'       => self::SERVICE_NAME,
                'protocol'   => 'TCP',
                'tcp_port'   => (string) $port,
                'udp_port'   => '',
                'category'   => 'Remote Access',
                'is_system'  => 0,
                'comment'    => 'Clientless Gateway (ZTNA) HTTPS port',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $serviceCreated = true;
        } elseif ((string) ($svc['tcp_port'] ?? '') !== (string) $port) {
            // Follows the gateway's External Port setting.
            $this->db->table('firewall_services')->where('id', $svc['id'])->update(['tcp_port' => (string) $port, 'updated_at' => $now]);
        }

        $interfaceId = $this->wanInterfaceId();
        if ($this->hasGatewayPolicy() || $interfaceId === null) {
            return ['service_created' => $serviceCreated, 'policy_created' => false];
        }

        $maxSeq = (int) ($this->db->table('firewall_rules')->selectMax('sequence')->get()->getRow()->sequence ?? 0);

        $this->db->table('firewall_rules')->insert([
            'id'            => 'pol_' . bin2hex(random_bytes(6)),
            'name'          => self::STARTER_NAME,
            'sequence'      => $maxSeq + 1,
            'interface_id'  => $interfaceId,
            'src_interface' => 'any',
            'dst_interface' => 'local',
            'sources'       => json_encode(['all']),
            'destinations'  => json_encode(['all']),
            'services'      => json_encode([self::SERVICE_NAME]),
            'action'        => 'accept',
            'direction'     => 'in',
            'protocol'      => 'any',
            'source_type'   => 'any',
            'source_value'  => 'any',
            'dest_type'     => 'any',
            'dest_value'    => 'any',
            'nat_enabled'   => 0,
            'schedule'      => 'always',
            'log_traffic'   => 'all',
            'enabled'       => 1,
            'description'   => 'Seeded when Clientless Gateway access was put under firewall control. Add narrower allow policies above this one, then change it to Deny.',
            'hit_count'     => 0,
            'bytes'         => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return ['service_created' => $serviceCreated, 'policy_created' => true];
    }

    /** Whether an enabled This-Firewall policy uses the gateway service. */
    public function hasGatewayPolicy(): bool
    {
        $rows = $this->db->table('firewall_rules')
            ->select('services')
            ->where('enabled', 1)
            ->whereIn('dst_interface', ['local', 'self'])
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $services = json_decode((string) ($row['services'] ?? ''), true);
            if (is_array($services) && in_array(self::SERVICE_NAME, $services, true)) {
                return true;
            }
        }

        return false;
    }

    /** The policy table's legacy interface_id must name a real interface. */
    private function wanInterfaceId(): ?string
    {
        try {
            $row = $this->db->table('interfaces')->where('LOWER(id)', 'wan')->get()->getRowArray()
                ?: $this->db->table('interfaces')->orderBy('id', 'ASC')->get()->getRowArray();
        } catch (\Throwable) {
            $row = null;
        }

        return isset($row['id']) ? (string) $row['id'] : null;
    }
}

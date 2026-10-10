<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets a WireGuard tunnel interface carry more than one address.
 *
 * A comma-separated "Address" list on a WireGuard tunnel is accepted, so
 * tunnel (typically an IPv4 and an IPv6 tunnel address, sometimes several of
 * each), and `wg-quick` accepts it too:
 *
 *     Address = 10.9.0.1/24, fd00::1/64
 *
 * Until now the table only had a single ipv4_address + ipv4_subnet pair, so
 * dual-stack or multi-subnet tunnels could not be expressed.
 *
 * `addresses` is the authoritative, comma-separated CIDR list going forward.
 * `ipv4_address` / `ipv4_subnet` are kept in sync with its first IPv4 entry so
 * existing code, the peer profile generator and the DNS fallback keep working.
 */
class AddAddressListToWireguardTunnels extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('wireguard_tunnels', [
            'addresses' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'ipv4_subnet',
            ],
        ]);

        // Backfill from the existing single address so nothing needs re-entering
        try {
            $rows = $this->db->table('wireguard_tunnels')->get()->getResultArray();
            foreach ($rows as $row) {
                $address = trim((string) ($row['ipv4_address'] ?? ''));
                if ($address === '') {
                    continue;
                }
                if (! str_contains($address, '/') && ! empty($row['ipv4_subnet'])) {
                    $address .= '/' . (int) $row['ipv4_subnet'];
                }
                $this->db->table('wireguard_tunnels')->where('id', $row['id'])->update(['addresses' => $address]);
            }
        } catch (\Throwable $e) {
            // Backfill is best-effort; the compiler falls back to the legacy pair.
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('wireguard_tunnels', 'addresses');
    }
}

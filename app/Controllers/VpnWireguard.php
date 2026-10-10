<?php

namespace App\Controllers;

use App\Libraries\Cidr;
use App\Libraries\IpcClient;
use App\Libraries\WireguardCompiler;

/**
 * VpnWireguard Controller — WireGuard tunnel (interface) and peer management
 *
 * Layout: a "Tunnels" tab owns the interface, and each tunnel's peers are
 * (its IP address, listen port, keys, MTU) and every peer belongs to exactly one
 * tunnel. The peer's own tunnel address is stored in `peer_address` and compiled
 * as the first AllowedIPs entry; `allowed_ips` holds the networks routed
 * through that peer.
 */
class VpnWireguard extends BaseController
{
    private IpcClient $ipc;
    private WireguardCompiler $compiler;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc      = \Config\Services::ipc();
        $this->compiler = new WireguardCompiler();
    }

    /* ----------------------------------------------------------------
     * GET /vpn/wireguard
     * ---------------------------------------------------------------- */
    public function index(): string
    {
        $db      = \Config\Database::connect();
        $tunnels = $db->table('wireguard_tunnels')->orderBy('created_at', 'ASC')->get()->getResultArray();

        $peers = $db->table('wireguard_peers')->orderBy('created_at', 'ASC')->get()->getResultArray();

        // Resolve each peer's tunnel label + per-tunnel peer count
        $tunnelNames = [];
        $peerCounts  = [];
        foreach ($tunnels as $t) {
            $tunnelNames[$t['id']] = $t['name'] ?: ($t['interface_name'] ?: 'wg');
        }
        foreach ($peers as $p) {
            $peerCounts[$p['tunnel_id']] = ($peerCounts[$p['tunnel_id']] ?? 0) + 1;
        }

        $liveData = $this->ipc->getWireguardPeers();

        return view('vpn/wireguard', [
            'title'       => 'WireGuard VPN Tunnels — MicronOS',
            'breadcrumb'  => 'WireGuard',
            'tunnels'     => $tunnels,
            'peers'       => $peers,
            'tunnelNames' => $tunnelNames,
            'peerCounts'  => $peerCounts,
            'liveData'    => $this->indexLivePeers($liveData),
            'liveStatus'  => $liveData,
            'isMock'      => $this->ipc->isMock(),
        ]);
    }

    public function peers(): string
    {
        return $this->index();
    }

    /* ----------------------------------------------------------------
     * Tunnels (interfaces)
     * ---------------------------------------------------------------- */

    /** POST /vpn/wireguard/tunnels/store */
    public function storeTunnel(): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->request->getPost();

        if (! $this->validateTunnel($post)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $addresses = $this->normaliseAddresses((string) ($post['addresses'] ?? ''));

        $id = 'wg_tun_' . bin2hex(random_bytes(6));
        $db->table('wireguard_tunnels')->insert(array_merge([
            'id'             => $id,
            'name'           => trim((string) $post['name']),
            'description'    => trim((string) ($post['description'] ?? '')) ?: null,
            'interface_name' => trim((string) $post['interface_name']),
            'listen_port'    => (int) ($post['listen_port'] ?: 51820),
            'private_key'    => trim((string) ($post['private_key'] ?? '')),
            'public_key'     => trim((string) ($post['public_key'] ?? '')),
            'preshared_key'  => trim((string) ($post['preshared_key'] ?? '')) ?: null,
            'mtu'            => (int) ($post['mtu'] ?: 1420),
            'dns_servers'    => $this->normaliseList((string) ($post['dns_servers'] ?? '')),
            'enabled'        => isset($post['enabled']) ? 1 : 0,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $addresses['columns']));

        // Adopt peers that predate tunnel records: the old controller wrote a
        // placeholder tunnel_id ('wg0') that matches no row, so such peers would
        // be orphaned and never compiled into any interface.
        $knownIds = array_column($db->table('wireguard_tunnels')->select('id')->get()->getResultArray(), 'id');
        $orphans  = $db->table('wireguard_peers')->select('id')->whereNotIn('tunnel_id', $knownIds)->get()->getResultArray();
        $adopted  = 0;
        foreach ($orphans as $orphan) {
            $db->table('wireguard_peers')->where('id', $orphan['id'])->update(['tunnel_id' => $id, 'updated_at' => $now]);
            $adopted++;
        }

        // New peers inherit the tunnel's default pre-shared key (per-peer keys win).
        $this->inheritPresharedKey($id, trim((string) ($post['preshared_key'] ?? '')) ?: null);

        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'Tunnel saved and WireGuard reloaded.'
            . ($adopted > 0 ? " {$adopted} pre-existing peer(s) attached to it." : ''));
    }

    /** POST /vpn/wireguard/tunnels/(:segment)/update */
    public function updateTunnel(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $tunnel = $db->table('wireguard_tunnels')->where('id', $id)->get()->getRowArray();
        if (! $tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $post = $this->request->getPost();
        if (! $this->validateTunnel($post, $tunnel)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $rawAddresses = (string) ($post['addresses'] ?? '');
        if (trim($rawAddresses) === '') {
            // Field not submitted at all → keep what is stored
            $rawAddresses = (string) ($tunnel['addresses'] ?? '');
            if ($rawAddresses === '' && ! empty($tunnel['ipv4_address'])) {
                $rawAddresses = $tunnel['ipv4_address'] . (! str_contains((string) $tunnel['ipv4_address'], '/') ? '/' . (int) $tunnel['ipv4_subnet'] : '');
            }
        }
        $addresses = $this->normaliseAddresses($rawAddresses);

        $pick = static function (string $key, $fallback) use ($post) {
            $value = trim((string) ($post[$key] ?? ''));

            return $value !== '' ? $value : $fallback;
        };

        $db->table('wireguard_tunnels')->where('id', $id)->update(array_merge([
            'name'           => trim((string) ($post['name'] ?? $tunnel['name'])),
            'description'    => trim((string) ($post['description'] ?? '')) ?: null,
            'interface_name' => $pick('interface_name', $tunnel['interface_name']),
            'listen_port'    => (int) ($post['listen_port'] ?: $tunnel['listen_port']),
            'private_key'    => $pick('private_key', $tunnel['private_key']),
            'public_key'     => $pick('public_key', $tunnel['public_key']),
            'preshared_key'  => trim((string) ($post['preshared_key'] ?? '')) ?: null,
            'mtu'            => (int) ($post['mtu'] ?: $tunnel['mtu']),
            'dns_servers'    => $this->normaliseList((string) ($post['dns_servers'] ?? '')),
            'enabled'        => isset($post['enabled']) ? 1 : 0,
            'updated_at'     => date('Y-m-d H:i:s'),
        ], $addresses['columns']));

        // Peers that never set their own key pick up the tunnel default.
        $this->inheritPresharedKey($id, trim((string) ($post['preshared_key'] ?? '')) ?: null);

        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'Tunnel updated — interface address and peers pushed to the daemon.');
    }

    /** POST /vpn/wireguard/tunnels/(:segment)/toggle */
    public function toggleTunnel(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $tunnel = $db->table('wireguard_tunnels')->where('id', $id)->get()->getRowArray();
        if (! $tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $enabled = ((int) $tunnel['enabled'] === 1) ? 0 : 1;
        $db->table('wireguard_tunnels')->where('id', $id)
            ->update(['enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))
            ->with('success', 'Tunnel ' . ($enabled ? 'enabled' : 'disabled') . '. Its interface is ' . ($enabled ? 'brought up' : 'torn down') . ' accordingly.');
    }

    /** POST /vpn/wireguard/tunnels/(:segment)/delete */
    public function deleteTunnel(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('wireguard_peers')->where('tunnel_id', $id)->delete();
        $db->table('wireguard_tunnels')->where('id', $id)->delete();
        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'Tunnel and its peers removed.');
    }

    /** GET /vpn/wireguard/tunnels/(:segment)/config */
    public function downloadTunnel(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $tunnel = $this->findTunnel($id);
        if (! $tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $iface = $this->compiler->interfaceName($tunnel);

        return $this->download($this->compiler->compileServerConfig($id), $iface . '.conf', 'text/plain');
    }

    /** GET /vpn/wireguard/tunnels/(:segment)/apply-script */
    public function applyScript(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $tunnel = $this->findTunnel($id);
        if (! $tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        return $this->download(
            $this->compiler->compileApplyScript($id),
            'bring-up-' . $this->compiler->interfaceName($tunnel) . '.sh',
            'text/x-shellscript'
        );
    }

    /** POST /vpn/wireguard/tunnels/(:segment)/genkey */
    public function generateKeys(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $tunnel = $this->findTunnel($id);
        if (! $tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $result = $this->ipc->call('vpn.wireguard.genkey', ['interface' => $this->compiler->interfaceName($tunnel)])['result'] ?? [];
        if (empty($result['private_key']) || empty($result['public_key'])) {
            return redirect()->to(base_url('vpn/wireguard'))
                ->with('error', 'The daemon did not return a keypair — generate one with `wg genkey | tee private | wg pubkey` and paste it.');
        }

        \Config\Database::connect()->table('wireguard_tunnels')->where('id', $id)->update([
            'private_key' => $result['private_key'],
            'public_key'  => $result['public_key'],
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'New interface keypair generated'
            . ($this->ipc->isMock() ? ' (mock daemon — replace with a real wg keypair on the appliance).' : '.'));
    }

    /**
     * POST /vpn/wireguard/keygen — JSON helper for the add/edit modals.
     *
     * The "Add Tunnel" modal can be open before the tunnel exists, so the
     * saved-tunnel genkey route cannot serve it. This action mints material
     * without needing an id:
     *
     *   kind=keypair → Curve25519 interface keypair (private_key / public_key)
     *   kind=psk     → 32-byte pre-shared key (preshared_key)
     *
     * The rotating CSRF token is echoed back so the calling form can keep
     * submitting without a reload.
     */
    public function keygen(): \CodeIgniter\HTTP\ResponseInterface
    {
        $kind = strtolower(trim((string) ($this->request->getPost('kind') ?? 'keypair'))) ?: 'keypair';
        $csrf = service('security')->getHash();

        if ($kind === 'psk') {
            $psk = $this->ipc->call('vpn.wireguard.pskgen')['result']['preshared_key'] ?? null;
            if (! is_string($psk) || $psk === '') {
                // A PSK is just 32 random bytes — safe to mint locally when the
                // daemon does not answer (mock mode, or an older micrond).
                $psk = base64_encode(random_bytes(32));
            }

            return $this->response->setJSON([
                'ok'           => true,
                'mock'         => $this->ipc->isMock(),
                'csrf'         => $csrf,
                'preshared_key'=> $psk,
            ]);
        }

        $result = $this->ipc->call('vpn.wireguard.genkey')['result'] ?? [];
        if (empty($result['private_key']) || empty($result['public_key'])) {
            return $this->response->setStatusCode(502)->setJSON([
                'ok'    => false,
                'csrf'  => $csrf,
                'error' => 'The daemon did not return a keypair — generate one with `wg genkey | tee private | wg pubkey` and paste it.',
            ]);
        }

        return $this->response->setJSON([
            'ok'          => true,
            'mock'        => $this->ipc->isMock(),
            'csrf'        => $csrf,
            'private_key' => $result['private_key'],
            'public_key'  => $result['public_key'],
        ]);
    }

    /* ----------------------------------------------------------------
     * Peers
     * ---------------------------------------------------------------- */

    /** POST /vpn/wireguard/peers/store */
    public function storePeer(): \CodeIgniter\HTTP\RedirectResponse
    {
        $post     = $this->request->getPost();
        $tunnelId = (string) ($post['tunnel_id'] ?? '');

        $rules = [
            'name'       => 'required|min_length[2]|max_length[64]',
            'public_key' => 'required|max_length[128]',
            'tunnel_id'  => 'required',
        ];
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }
        $tunnel = $this->findTunnel($tunnelId);
        if (! $tunnel) {
            return redirect()->back()->withInput()
                ->with('error', 'Pick a tunnel first — each peer belongs to one WireGuard interface.');
        }
        if (! $this->validatePeerAddresses($post)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        // A peer with no key of its own inherits the tunnel's default.
        $presharedKey = trim((string) ($post['preshared_key'] ?? '')) ?: trim((string) ($tunnel['preshared_key'] ?? ''));

        $db->table('wireguard_peers')->insert([
            'id'                   => 'wg_peer_' . bin2hex(random_bytes(6)),
            'tunnel_id'            => $tunnelId,
            'name'                 => trim($post['name']),
            'public_key'           => trim($post['public_key']),
            'preshared_key'        => $presharedKey ?: null,
            'endpoint'             => trim((string) ($post['endpoint'] ?? '')) ?: null,
            'peer_address'         => Cidr::normalise((string) ($post['peer_address'] ?? '')) ?: null,
            // allowed_ips is NOT NULL in the schema; '' means "no extra networks".
            'allowed_ips'          => Cidr::normalise((string) ($post['allowed_ips'] ?? '')),
            'persistent_keepalive' => (int) ($post['keepalive_secs'] ?? 25),
            'enabled'              => isset($post['enabled']) ? 1 : 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'Peer added and the tunnel interface reloaded with its AllowedIPs.');
    }

    /** POST /vpn/wireguard/peers/(:segment)/update */
    public function updatePeer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $peer = $db->table('wireguard_peers')->where('id', $id)->get()->getRowArray();
        if (! $peer) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Peer not found.');
        }

        $post = $this->request->getPost();
        if (! $this->validate([
            'name'       => 'required|min_length[2]|max_length[64]',
            'public_key' => 'required|max_length[128]',
        ])) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }
        if (! $this->validatePeerAddresses($post)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }
        $pick = static function (string $key, $fallback) use ($post) {
            $value = trim((string) ($post[$key] ?? ''));

            return $value !== '' ? $value : $fallback;
        };

        // Keep the peer's own key; otherwise fall back to its tunnel's default.
        $presharedKey = trim((string) ($post['preshared_key'] ?? '')) ?: (string) ($peer['preshared_key'] ?? '');
        if ($presharedKey === '') {
            $targetTunnel = $this->findTunnel((string) ($post['tunnel_id'] ?? $peer['tunnel_id']));
            $presharedKey = trim((string) ($targetTunnel['preshared_key'] ?? ''));
        }

        $db->table('wireguard_peers')->where('id', $id)->update([
            'tunnel_id'            => $pick('tunnel_id', $peer['tunnel_id']),
            'name'                 => $pick('name', $peer['name']),
            'public_key'           => $pick('public_key', $peer['public_key']),
            'preshared_key'        => $presharedKey ?: null,
            'endpoint'             => trim((string) ($post['endpoint'] ?? '')) ?: null,
            'peer_address'         => Cidr::normalise((string) ($post['peer_address'] ?? '')) ?: null,
            // allowed_ips is NOT NULL in the schema; '' means "no extra networks".
            'allowed_ips'          => Cidr::normalise((string) ($post['allowed_ips'] ?? '')),
            'persistent_keepalive' => (int) ($post['keepalive_secs'] ?? $peer['persistent_keepalive'] ?? 25),
            'enabled'              => isset($post['enabled']) ? 1 : 0,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'Peer updated and tunnel synchronized.');
    }

    /** POST /vpn/wireguard/peers/(:segment)/delete */
    public function deletePeer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('wireguard_peers')->where('id', $id)->delete();
        $this->sync();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'Peer deleted.');
    }

    /** GET /vpn/wireguard/peers/(:segment)/download */
    public function downloadPeer(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db   = \Config\Database::connect();
        $peer = $db->table('wireguard_peers')->where('id', $id)->get()->getRowArray();
        if (! $peer) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Peer not found.');
        }

        $tunnel = $this->findTunnel((string) $peer['tunnel_id']) ?? [];

        // Split tunnel by default: the client routes the tunnel network (so it can
        // reach the gateway and sibling peers). Widen AllowedIPs to 0.0.0.0/0 in
        // the profile for a full-tunnel client.
        $network = $this->compiler->tunnelNetwork($tunnel);
        $routes  = $network !== '' ? [$network] : ['0.0.0.0/0'];

        $endpointHost = $this->request->getServer('SERVER_NAME') ?: (gethostname() ?: '192.168.1.1');

        $conf = $this->compiler->generateClientProfile($peer, $tunnel, $endpointHost, $routes);

        $filename = 'wg-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower((string) $peer['name'])) . '.conf';

        return $this->download($conf, $filename, 'text/plain');
    }

    /* ----------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    private function sync(): void
    {
        $this->ipc->call('vpn.wireguard.sync');
    }

    /**
     * Seeds peers of a tunnel that have no pre-shared key of their own with the
     * tunnel's default. A peer's own key is authoritative and never overwritten.
     */
    private function inheritPresharedKey(string $tunnelId, ?string $psk): void
    {
        $psk = trim((string) $psk);
        if ($psk === '') {
            return;
        }

        \Config\Database::connect()->table('wireguard_peers')
            ->where('tunnel_id', $tunnelId)
            ->groupStart()
                ->where('preshared_key', null)
                ->orWhere('preshared_key', '')
            ->groupEnd()
            ->update(['preshared_key' => $psk, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * A peer may declare several tunnel addresses (dual stack) and several
     * networks. Both lists are validated entry by entry.
     *
     * @param array<string, mixed> $post
     */
    private function validatePeerAddresses(array $post): bool
    {
        // service('validation') rather than $this->validator: update paths may not
        // have populated the controller property yet.
        $validation = service('validation');

        foreach (['peer_address' => 'Peer tunnel address', 'allowed_ips' => 'Network'] as $field => $label) {
            $raw = trim((string) ($post[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (! Cidr::isValidList($raw)) {
                $bad = array_values(array_filter(Cidr::split($raw), static fn ($entry) => ! Cidr::isValid($entry)));
                $validation->setError($field, $label . ' list contains an invalid entry: ' . implode(', ', $bad)
                    . ' — use CIDR notation and separate multiple entries with commas.');

                return false;
            }
        }

        return true;
    }

    private function findTunnel(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        return \Config\Database::connect()->table('wireguard_tunnels')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function validateTunnel(array $post, ?array $existing = null): bool
    {
        $rules = [
            'name'           => 'required|min_length[2]|max_length[64]|regex_match[/^[A-Za-z0-9 ._-]+$/]',
            'interface_name' => 'required|regex_match[/^[A-Za-z0-9_]{1,15}$/]',
            'listen_port'    => 'required|integer|greater_than[0]|less_than[65536]',
            'mtu'            => 'permit_empty|integer|greater_than_equal_to[576]|less_than_equal_to[9000]',
            'dns_servers'    => 'permit_empty',
        ];
        if (! $this->validate($rules)) {
            return false;
        }

        // Address list: any number of IPv4/IPv6 addresses or CIDRs
        $validation = service('validation');

        // Address list: any number of IPv4/IPv6 addresses or CIDRs
        $raw = trim((string) ($post['addresses'] ?? ''));
        if ($raw !== '' && ! Cidr::isValidList($raw)) {
            $bad = array_values(array_filter(Cidr::split($raw), static fn ($entry) => ! Cidr::isValid($entry)));
            $validation->setError('addresses', 'Not a valid address: ' . implode(', ', $bad)
                . ' — use CIDR notation such as 10.9.0.1/24 or fd00::1/64.');

            return false;
        }

        $dns = trim((string) ($post['dns_servers'] ?? ''));
        if ($dns !== '' && ! Cidr::isValidList($dns)) {
            $validation->setError('dns_servers', 'DNS server list contains an invalid address.');

            return false;
        }

        return true;
    }

    /**
     * Normalises the interface address list and keeps the legacy
     * ipv4_address / ipv4_subnet pair in sync with its first IPv4 entry.
     *
     * Entries typed without a prefix default to /24 (IPv4) or /64 (IPv6).
     *
     * @return array{columns: array<string, mixed>, list: list<string>}
     */
    private function normaliseAddresses(string $raw): array
    {
        $list = [];
        foreach (Cidr::split($raw) as $entry) {
            if (! Cidr::isValid($entry)) {
                continue;
            }
            if (! str_contains($entry, '/')) {
                $entry = Cidr::withPrefix($entry, Cidr::family($entry) === 'ipv6' ? 64 : 24);
            }
            $list[] = $entry;
        }
        $list = array_values(array_unique($list));

        $columns = ['addresses' => $list === [] ? null : implode(', ', $list)];

        foreach ($list as $entry) {
            if (Cidr::family($entry) === 'ipv4') {
                $columns['ipv4_address'] = Cidr::addressOf($entry);
                $columns['ipv4_subnet']  = Cidr::prefixOf($entry);
                break;
            }
        }

        return ['columns' => $columns, 'list' => $list];
    }

    private function normaliseList(string $list): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', $list)), static fn ($v) => $v !== '');

        return $parts === [] ? null : implode(',', $parts);
    }

    /**
     * Indexes daemon peer status by public key so the table can show live state.
     *
     * @return array<string, array>
     */
    private function indexLivePeers(array $liveData): array
    {
        $indexed = [];
        foreach ($liveData['peers'] ?? [] as $entry) {
            $key = $entry['peer'] ?? $entry['public_key'] ?? null;
            if ($key !== null) {
                $indexed[$key] = $entry;
            }
        }

        return $indexed;
    }

    private function download(string $body, string $filename, string $contentType): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response
            ->setHeader('Content-Type', $contentType)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($body);
    }
}

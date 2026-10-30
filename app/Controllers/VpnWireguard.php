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

    /** The daemon's answer to the last sync, so messages state what really happened. */
    private array $lastSync = [];

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
        $this->compiler = new WireguardCompiler();
    }

    /* ----------------------------------------------------------------
     * GET /vpn/wireguard
     * ---------------------------------------------------------------- */
    public function index(): string
    {
        $db = \Config\Database::connect();
        $tunnels = $db->table('wireguard_tunnels')->orderBy('created_at', 'ASC')->get()->getResultArray();

        $peers = $db->table('wireguard_peers')->orderBy('created_at', 'ASC')->get()->getResultArray();

        // Resolve each peer's tunnel label + per-tunnel peer count
        $tunnelNames = [];
        $tunnelInterfaces = [];
        $peerCounts = [];
        foreach ($tunnels as $t) {
            $tunnelNames[$t['id']] = $t['name'] ?: ($t['interface_name'] ?: 'wg');
            $tunnelInterfaces[$t['id']] = $t['interface_name'];
        }
        foreach ($peers as $p) {
            $peerCounts[$p['tunnel_id']] = ($peerCounts[$p['tunnel_id']] ?? 0) + 1;
        }

        $liveData = $this->ipc->getWireguardPeers();

        $settingsRows = $db->table('plugin_settings')->where('plugin_id', 'wireguard')->get()->getResultArray();
        $settings = [
            'enable_wireguard' => '1',
            'keep_configuration' => '1',
            'resolve_interval' => '300',
            'track_system_resolve' => '0',
            'interface_group_membership' => 'all',
            'hide_secrets' => '1',
            'hide_peers' => '1',
        ];
        foreach ($settingsRows as $sr) {
            $settings[$sr['setting_key']] = $sr['value'];
        }

        $hideSecrets = ($settings['hide_secrets'] ?? '1') === '1';

        // When "Hide Secrets" is enabled, secrets are stripped from the dataset sent
        // to the browser. When disabled, the stored keys are passed so the UI can display them.
        if ($hideSecrets) {
            $tunnels = array_map(static function (array $tunnel): array {
                unset($tunnel['private_key'], $tunnel['preshared_key']);

                return $tunnel;
            }, $tunnels);

            $peers = array_map(static function (array $peer): array {
                unset($peer['preshared_key']);

                return $peer;
            }, $peers);
        }

        $tab = $this->request->getGet('tab') ?: 'tunnels';
        if (!in_array($tab, ['tunnels', 'peers', 'settings', 'status'], true)) {
            $tab = 'tunnels';
        }

        return view('vpn/wireguard', [
            'title' => 'WireGuard VPN — Micron OS',
            'breadcrumb' => 'WireGuard',
            'activeTab' => $tab,
            'settings' => $settings,
            'tunnels' => $tunnels,
            'peers' => $peers,
            'tunnelNames' => $tunnelNames,
            'tunnelInterfaces' => $tunnelInterfaces,
            'peerCounts' => $peerCounts,
            'liveData' => $this->indexLivePeers($liveData),
            'liveStatus' => $liveData,
            'isMock' => $this->ipc->isMock(),
        ]);
    }

    public function peers(): string
    {
        return $this->index();
    }

    /**
     * POST /vpn/wireguard/settings
     */
    public function saveSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $post = $this->request->getPost();

        $keys = [
            'enable_wireguard' => !empty($post['enable_wireguard']) ? '1' : '0',
            'keep_configuration' => !empty($post['keep_configuration']) ? '1' : '0',
            'resolve_interval' => (string) max(0, (int) ($post['resolve_interval'] ?? 300)),
            'track_system_resolve' => !empty($post['track_system_resolve']) ? '1' : '0',
            'interface_group_membership' => $post['interface_group_membership'] ?? 'all',
            'hide_secrets' => !empty($post['hide_secrets']) ? '1' : '0',
            'hide_peers' => !empty($post['hide_peers']) ? '1' : '0',
        ];

        foreach ($keys as $key => $val) {
            $exists = $db->table('plugin_settings')
                ->where('plugin_id', 'wireguard')
                ->where('setting_key', $key)
                ->countAllResults();

            if ($exists > 0) {
                $db->table('plugin_settings')
                    ->where('plugin_id', 'wireguard')
                    ->where('setting_key', $key)
                    ->update(['value' => $val]);
            } else {
                $db->table('plugin_settings')->insert([
                    'plugin_id' => 'wireguard',
                    'setting_key' => $key,
                    'value' => $val,
                    'is_secret' => 0,
                ]);
            }
        }

        return redirect()->to(base_url('vpn/wireguard?tab=settings'))->with('success', 'WireGuard general settings saved successfully.');
    }

    /* ----------------------------------------------------------------
     * Tunnels (interfaces)
     * ---------------------------------------------------------------- */

    /** POST /vpn/wireguard/tunnels/store */
    public function storeTunnel(): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->request->getPost();

        if (!$this->validateTunnel($post)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $addresses = $this->normaliseAddresses((string) ($post['addresses'] ?? ''));

        $id = 'wg_tun_' . bin2hex(random_bytes(6));
        $db->table('wireguard_tunnels')->insert(array_merge([
            'id' => $id,
            'name' => trim((string) $post['name']),
            'description' => trim((string) ($post['description'] ?? '')) ?: null,
            'interface_name' => trim((string) $post['interface_name']),
            'listen_port' => (int) ($post['listen_port'] ?: 51820),
            'private_key' => trim((string) ($post['private_key'] ?? '')),
            'public_key' => trim((string) ($post['public_key'] ?? '')),
            'preshared_key' => trim((string) ($post['preshared_key'] ?? '')) ?: null,
            'mtu' => (int) ($post['mtu'] ?: 1420),
            'dns_servers' => $this->normaliseList((string) ($post['dns_servers'] ?? '')),
            'enabled' => isset($post['enabled']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $addresses['columns']));

        // Adopt peers that predate tunnel records: the old controller wrote a
        // placeholder tunnel_id ('wg0') that matches no row, so such peers would
        // be orphaned and never compiled into any interface.
        $knownIds = array_column($db->table('wireguard_tunnels')->select('id')->get()->getResultArray(), 'id');
        $orphans = $db->table('wireguard_peers')->select('id')->whereNotIn('tunnel_id', $knownIds)->get()->getResultArray();
        $adopted = 0;
        foreach ($orphans as $orphan) {
            $db->table('wireguard_peers')->where('id', $orphan['id'])->update(['tunnel_id' => $id, 'updated_at' => $now]);
            $adopted++;
        }

        // New peers inherit the tunnel's default pre-shared key (per-peer keys win).
        $this->inheritPresharedKey($id, trim((string) ($post['preshared_key'] ?? '')) ?: null);

        $this->sync((string) $post['interface_name']);

        return redirect()->to(base_url('vpn/wireguard'))->with(...$this->wgFlash('Tunnel saved and WireGuard reloaded.'
            . ($adopted > 0 ? " {$adopted} pre-existing peer(s) attached to it." : '')));
    }

    /** POST /vpn/wireguard/tunnels/(:segment)/update */
    public function updateTunnel(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $tunnel = $db->table('wireguard_tunnels')->where('id', $id)->get()->getRowArray();
        if (!$tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $post = $this->request->getPost();
        if (!$this->validateTunnel($post, $tunnel)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $rawAddresses = (string) ($post['addresses'] ?? '');
        if (trim($rawAddresses) === '') {
            // Field not submitted at all → keep what is stored
            $rawAddresses = (string) ($tunnel['addresses'] ?? '');
            if ($rawAddresses === '' && !empty($tunnel['ipv4_address'])) {
                $rawAddresses = $tunnel['ipv4_address'] . (!str_contains((string) $tunnel['ipv4_address'], '/') ? '/' . (int) $tunnel['ipv4_subnet'] : '');
            }
        }
        $addresses = $this->normaliseAddresses($rawAddresses);

        $pick = static function (string $key, $fallback) use ($post) {
            $value = trim((string) ($post[$key] ?? ''));

            return $value !== '' ? $value : $fallback;
        };

        $settingsRow = $db->table('plugin_settings')->where('plugin_id', 'wireguard')->where('setting_key', 'hide_secrets')->get()->getRowArray();
        $hideSecrets = ($settingsRow['value'] ?? '1') === '1';

        $presharedKey = trim((string) ($post['preshared_key'] ?? ''));
        if ($hideSecrets && $presharedKey === '') {
            $presharedKey = (string) ($tunnel['preshared_key'] ?? '');
        }

        $db->table('wireguard_tunnels')->where('id', $id)->update(array_merge([
            'name' => trim((string) ($post['name'] ?? $tunnel['name'])),
            'description' => trim((string) ($post['description'] ?? '')) ?: null,
            'interface_name' => $pick('interface_name', $tunnel['interface_name']),
            'listen_port' => (int) ($post['listen_port'] ?: $tunnel['listen_port']),
            'private_key' => $pick('private_key', $tunnel['private_key']),
            'public_key' => $pick('public_key', $tunnel['public_key']),
            'preshared_key' => $presharedKey !== '' ? $presharedKey : null,
            'mtu' => (int) ($post['mtu'] ?: $tunnel['mtu']),
            'dns_servers' => $this->normaliseList((string) ($post['dns_servers'] ?? '')),
            'enabled' => isset($post['enabled']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $addresses['columns']));

        // Peers that never set their own key pick up the tunnel default.
        $this->inheritPresharedKey($id, $presharedKey !== '' ? $presharedKey : null);

        // A renamed interface: retire the old device, then bring up the new one.
        $newIface = $pick('interface_name', $tunnel['interface_name']);
        if ($newIface !== $this->compiler->interfaceName($tunnel)) {
            $this->ipc->call('vpn.wireguard.remove', ['interface' => $this->compiler->interfaceName($tunnel)]);
        }
        $this->sync($newIface);

        return redirect()->to(base_url('vpn/wireguard'))->with(...$this->wgFlash('Tunnel updated — interface address and peers pushed to the daemon.'));
    }

    /** POST /vpn/wireguard/tunnels/(:segment)/toggle */
    public function toggleTunnel(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $tunnel = $db->table('wireguard_tunnels')->where('id', $id)->get()->getRowArray();
        if (!$tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $enabled = ((int) $tunnel['enabled'] === 1) ? 0 : 1;
        $db->table('wireguard_tunnels')->where('id', $id)
            ->update(['enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->sync($this->compiler->interfaceName($tunnel));

        return redirect()->to(base_url('vpn/wireguard'))
            ->with(...$this->wgFlash('Tunnel ' . ($enabled ? 'enabled' : 'disabled') . '.'));
    }

    /** POST /vpn/wireguard/tunnels/(:segment)/delete */
    public function deleteTunnel(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $tunnel = $db->table('wireguard_tunnels')->where('id', $id)->get()->getRowArray();

        // Tear the live interface down first, so a deleted tunnel cannot keep
        // accepting handshakes. Its own interface name is used — never a default.
        if ($tunnel && trim((string) ($tunnel['interface_name'] ?? '')) !== '') {
            $outcome = $this->ipcOutcome($this->ipc->call('vpn.wireguard.remove', ['interface' => $tunnel['interface_name']]));
            if (!$outcome['ok']) {
                return redirect()->to(base_url('vpn/wireguard'))
                    ->with('error', 'The tunnel was not removed: its interface could not be torn down (' . $outcome['reason'] . ').');
            }
        }

        $db->table('wireguard_peers')->where('tunnel_id', $id)->delete();
        $db->table('wireguard_tunnels')->where('id', $id)->delete();

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'Tunnel and its peers removed, and its interface torn down.');
    }

    /** GET /vpn/wireguard/tunnels/(:segment)/config */
    public function downloadTunnel(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $tunnel = $this->findTunnel($id);
        if (!$tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $iface = $this->compiler->interfaceName($tunnel);

        return $this->download($this->compiler->compileServerConfig($id), $iface . '.conf', 'text/plain');
    }

    /** GET /vpn/wireguard/tunnels/(:segment)/apply-script */
    public function applyScript(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $tunnel = $this->findTunnel($id);
        if (!$tunnel) {
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
        if (!$tunnel) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Tunnel not found.');
        }

        $result = [];
        try {
            $result = $this->ipc->call('vpn.wireguard.genkey', ['interface' => $this->compiler->interfaceName($tunnel)])['result'] ?? [];
        } catch (\Throwable $e) {
            // Daemon communication failed — fall back to local cryptographic generation
        }

        if (empty($result['private_key']) || empty($result['public_key'])) {
            $result = WireguardCompiler::generateKeypair();
        }

        \Config\Database::connect()->table('wireguard_tunnels')->where('id', $id)->update([
            'private_key' => $result['private_key'],
            'public_key' => $result['public_key'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->sync($this->compiler->interfaceName($tunnel));

        return redirect()->to(base_url('vpn/wireguard'))->with('success', 'New interface keypair generated'
            . ($this->ipc->isMock() ? ' (simulated environment).' : '.'));
    }

    /**
     * POST /vpn/wireguard/keygen — JSON helper for the add/edit modals.
     *
     * The "Add Tunnel" modal can be open before the tunnel exists, so the
     * saved-tunnel genkey route cannot serve it. This action mints material
     * without needing an id:
     *
     *   kind=keypair → Curve25519 interface keypair (private_key / public_key)
     *   kind=pubkey  → Curve25519 public key derived from given private_key
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
            $psk = null;
            try {
                $psk = $this->ipc->call('vpn.wireguard.pskgen')['result']['preshared_key'] ?? null;
            } catch (\Throwable $e) {
                // fall through
            }

            if (!is_string($psk) || $psk === '') {
                // A PSK is just 32 random bytes — safe to mint locally when the
                // daemon does not answer (mock mode, or an older micrond).
                $psk = WireguardCompiler::generatePresharedKey();
            }

            return $this->response->setJSON([
                'ok' => true,
                'mock' => $this->ipc->isMock(),
                'csrf' => $csrf,
                'preshared_key' => $psk,
            ]);
        }

        if ($kind === 'pubkey') {
            $privateKey = trim((string) ($this->request->getPost('private_key') ?? ''));
            $publicKey = '';
            if ($privateKey !== '') {
                try {
                    $publicKey = $this->ipc->call('vpn.wireguard.pubkey', ['private_key' => $privateKey])['result']['public_key'] ?? '';
                } catch (\Throwable $e) {
                }
                if ($publicKey === '') {
                    $publicKey = WireguardCompiler::publicKeyFromPrivateKey($privateKey);
                }
            }

            return $this->response->setJSON([
                'ok' => true,
                'mock' => $this->ipc->isMock(),
                'csrf' => $csrf,
                'public_key' => $publicKey,
            ]);
        }

        $result = [];
        try {
            $result = $this->ipc->call('vpn.wireguard.genkey')['result'] ?? [];
        } catch (\Throwable $e) {
            // fall through
        }

        if (empty($result['private_key']) || empty($result['public_key'])) {
            $result = WireguardCompiler::generateKeypair();
        }

        return $this->response->setJSON([
            'ok' => true,
            'mock' => $this->ipc->isMock(),
            'csrf' => $csrf,
            'private_key' => $result['private_key'],
            'public_key' => $result['public_key'],
        ]);
    }

    /* ----------------------------------------------------------------
     * Peers
     * ---------------------------------------------------------------- */

    /** POST /vpn/wireguard/peers/store */
    public function storePeer(): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->request->getPost();
        $tunnelId = (string) ($post['tunnel_id'] ?? '');

        $rules = [
            'name' => 'required|min_length[2]|max_length[64]',
            'public_key' => 'required|max_length[128]',
            'tunnel_id' => 'required',
        ];
        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }
        $tunnel = $this->findTunnel($tunnelId);
        if (!$tunnel) {
            return redirect()->back()->withInput()
                ->with('error', 'Pick a tunnel first — each peer belongs to one WireGuard interface.');
        }
        if (!$this->validatePeer($post, $tunnel, null)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $addresses = $this->splitAllowedIps((string) ($post['allowed_ips'] ?? ''));

        // A peer with no key of its own inherits the tunnel's default.
        $presharedKey = trim((string) ($post['preshared_key'] ?? '')) ?: trim((string) ($tunnel['preshared_key'] ?? ''));

        $db->table('wireguard_peers')->insert([
            'id' => 'wg_peer_' . bin2hex(random_bytes(6)),
            'tunnel_id' => $tunnelId,
            'name' => trim($post['name']),
            'public_key' => trim($post['public_key']),
            'preshared_key' => $presharedKey ?: null,
            'endpoint' => $this->buildEndpoint($post),
            'peer_address' => $addresses['peer_address'],
            // allowed_ips is NOT NULL in the schema; '' means "no extra networks".
            'allowed_ips' => $addresses['allowed_ips'],
            'persistent_keepalive' => $this->keepaliveSeconds($post),
            'enabled' => isset($post['enabled']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->sync($this->compiler->interfaceName($tunnel));

        return redirect()->to(base_url('vpn/wireguard'))->with(...$this->wgFlash('Peer added and the tunnel interface reloaded with its AllowedIPs.'));
    }

    /** POST /vpn/wireguard/peers/(:segment)/update */
    public function updatePeer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $peer = $db->table('wireguard_peers')->where('id', $id)->get()->getRowArray();
        if (!$peer) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Peer not found.');
        }

        $post = $this->request->getPost();
        if (
            !$this->validate([
                'name' => 'required|min_length[2]|max_length[64]',
                'public_key' => 'required|max_length[128]',
            ])
        ) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $tunnel = $this->findTunnel((string) ($post['tunnel_id'] ?? $peer['tunnel_id']))
            ?? $this->findTunnel((string) $peer['tunnel_id']);

        if (!$this->validatePeer($post, $tunnel ?? [], $id)) {
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
            $presharedKey = trim((string) ($tunnel['preshared_key'] ?? ''));
        }

        $addresses = $this->splitAllowedIps((string) ($post['allowed_ips'] ?? ''));

        $db->table('wireguard_peers')->where('id', $id)->update([
            'tunnel_id' => $pick('tunnel_id', $peer['tunnel_id']),
            'name' => $pick('name', $peer['name']),
            'public_key' => $pick('public_key', $peer['public_key']),
            'preshared_key' => $presharedKey ?: null,
            'endpoint' => $this->buildEndpoint($post),
            'peer_address' => $addresses['peer_address'],
            // allowed_ips is NOT NULL in the schema; '' means "no extra networks".
            'allowed_ips' => $addresses['allowed_ips'],
            'persistent_keepalive' => $this->keepaliveSeconds($post, (int) ($peer['persistent_keepalive'] ?? 2)),
            'enabled' => isset($post['enabled']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->sync($tunnel ? $this->compiler->interfaceName($tunnel) : '');

        return redirect()->to(base_url('vpn/wireguard'))->with(...$this->wgFlash('Peer updated and tunnel synchronized.'));
    }

    /** POST /vpn/wireguard/peers/(:segment)/toggle */
    public function togglePeer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $peer = $db->table('wireguard_peers')->where('id', $id)->get()->getRowArray();
        if (!$peer) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Peer not found.');
        }

        $enabled = ((int) $peer['enabled'] === 1) ? 0 : 1;
        $db->table('wireguard_peers')->where('id', $id)
            ->update(['enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);

        $tunnel = $this->findTunnel((string) $peer['tunnel_id']);
        $this->sync($tunnel ? $this->compiler->interfaceName($tunnel) : '');

        return redirect()->to(base_url('vpn/wireguard'))->with(...$this->wgFlash(
            'Peer ' . ($enabled ? 'enabled' : 'disabled') . '. '
            . ($enabled
                ? 'Its AllowedIPs are back in the interface configuration.'
                : 'Its AllowedIPs were withdrawn — the peer keeps its settings but no traffic is routed to it.')
        ));
    }

    /** POST /vpn/wireguard/peers/(:segment)/delete */
    public function deletePeer(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $peer = $db->table('wireguard_peers')->where('id', $id)->get()->getRowArray();

        $db->table('wireguard_peers')->where('id', $id)->delete();

        $tunnel = $peer ? $this->findTunnel((string) $peer['tunnel_id']) : null;
        $this->sync($tunnel ? $this->compiler->interfaceName($tunnel) : '');

        return redirect()->to(base_url('vpn/wireguard'))->with(...$this->wgFlash('Peer deleted.'));
    }

    /** GET /vpn/wireguard/peers/(:segment)/download */
    public function downloadPeer(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $peer = $db->table('wireguard_peers')->where('id', $id)->get()->getRowArray();
        if (!$peer) {
            return redirect()->to(base_url('vpn/wireguard'))->with('error', 'Peer not found.');
        }

        $tunnel = $this->findTunnel((string) $peer['tunnel_id']) ?? [];

        // Split tunnel by default: the client routes the tunnel network (so it can
        // reach the gateway and sibling peers). Widen AllowedIPs to 0.0.0.0/0 in
        // the profile for a full-tunnel client.
        $network = $this->compiler->tunnelNetwork($tunnel);
        $routes = $network !== '' ? [$network] : ['0.0.0.0/0'];

        $endpointHost = $this->request->getServer('SERVER_NAME') ?: (gethostname() ?: '192.168.1.1');

        $conf = $this->compiler->generateClientProfile($peer, $tunnel, $endpointHost, $routes);

        $filename = 'wg-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower((string) $peer['name'])) . '.conf';

        return $this->download($conf, $filename, 'text/plain');
    }

    /* ----------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /**
     * Push one tunnel's configuration to the daemon.
     *
     * The interface name is always sent: the daemon otherwise defaults to wg0,
     * so a change on wg2 would reload the wrong interface's peers.
     */
    private function sync(string $interface): void
    {
        $this->lastSync = [];
        if (trim($interface) === '') {
            return;
        }

        // Resolve the tunnel that owns this interface; without one there is
        // nothing to compile (and defaulting to wg0 would touch another tunnel).
        $tunnel = \Config\Database::connect()->table('wireguard_tunnels')
            ->where('interface_name', $interface)->get()->getRowArray();
        if (!$tunnel) {
            return;
        }

        $this->lastSync = $this->ipc->call('vpn.wireguard.sync', [
            'interface' => $interface,
            'enabled' => (int) ($tunnel['enabled'] ?? 1) === 1,
            // Exactly what "Download config" offers: enabled peers only.
            'config' => $this->compiler->compileServerConfig((string) $tunnel['id']),
        ]);
    }

    /**
     * Flash pair for a change that was saved and pushed to the interface.
     *
     * @return array{0: string, 1: string}
     */
    private function wgFlash(string $saved): array
    {
        if ($this->lastSync === []) {
            return ['success', $saved];
        }
        $outcome = $this->ipcOutcome($this->lastSync);
        if (!$outcome['ok']) {
            return ['error', rtrim($saved, '.') . ' — saved, but the interface was not updated: ' . $outcome['reason']];
        }
        $note = trim((string) ($this->lastSync['result']['message'] ?? ''));

        return ['success', $saved . ($note !== '' ? " ({$note}" . ($this->ipc->isMock() ? ', simulated' : '') . ')' : '')];
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
     * Validates a peer submission: the AllowedIPs list, the endpoint split into
     * its own boxes, and the two address conflicts that silently break routing —
     * reusing this appliance's own tunnel address, or reusing an address that
     * another peer on the same tunnel already holds.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $tunnel the peer's tunnel (empty when unknown)
     */
    private function validatePeer(array $post, array $tunnel, ?string $peerId): bool
    {
        // service('validation') rather than $this->validator: update paths may not
        // have populated the controller property yet.
        $validation = service('validation');

        $raw = trim((string) ($post['allowed_ips'] ?? ''));
        if ($raw !== '' && !Cidr::isValidList($raw)) {
            $bad = array_values(array_filter(Cidr::split($raw), static fn($entry) => !Cidr::isValid($entry)));
            $validation->setError('allowed_ips', 'Allowed IPs contains an invalid entry: ' . implode(', ', $bad)
                . ' — use CIDR notation and separate multiple entries with commas.');

            return false;
        }

        if (!$this->validateEndpoint($post, $validation)) {
            return false;
        }

        $addresses = $this->splitAllowedIps($raw);
        $peerIp = $addresses['peer_address']; // e.g. 10.9.0.2/32

        if ($peerIp === null || $peerIp === '') {
            return true; // a peer with no tunnel IP of its own is legitimate
        }

        // 1. This appliance's own tunnel address can never belong to a peer:
        //    the cryptokey route would send the interface's own traffic to it.
        $own = $this->compiler->tunnelCidrs($tunnel);
        foreach ($own as $cidr) {
            if (Cidr::addressOf($cidr) === Cidr::addressOf($peerIp)) {
                $validation->setError('allowed_ips', Cidr::addressOf($peerIp) . ' is this appliance\'s own tunnel address — '
                    . 'give the peer a different host address inside the tunnel subnet (e.g. ' . $this->suggestPeerAddress($tunnel, $peerId) . ').');

                return false;
            }
        }

        // 2. Two peers cannot hold the same tunnel address: the last AllowedIPs
        //    entry wins, so one of them would silently receive no traffic.
        $tunnelId = (string) ($tunnel['id'] ?? ($post['tunnel_id'] ?? ''));
        if ($tunnelId !== '') {
            $builder = \Config\Database::connect()->table('wireguard_peers')
                ->select('name, peer_address')
                ->where('tunnel_id', $tunnelId);
            if ($peerId !== null) {
                $builder->where('id !=', $peerId);
            }

            foreach ($builder->get()->getResultArray() as $other) {
                foreach (Cidr::split((string) ($other['peer_address'] ?? '')) as $existing) {
                    if (Cidr::addressOf($existing) === Cidr::addressOf($peerIp)) {
                        $validation->setError('allowed_ips', Cidr::addressOf($peerIp) . ' is already used by peer \''
                            . $other['name'] . '\' on this tunnel — every peer needs its own tunnel address.');

                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Validates the Endpoint boxes. A dynamic endpoint carries no address, so
     * whatever is typed there is ignored.
     *
     * @param array<string, mixed> $post
     */
    private function validateEndpoint(array $post, $validation): bool
    {
        if (!empty($post['dynamic_endpoint'])) {
            return true;
        }

        $host = trim((string) ($post['endpoint_host'] ?? ''));
        $port = trim((string) ($post['endpoint_port'] ?? ''));

        if ($host === '' && $port === '') {
            return true;
        }

        if ($host === '') {
            $validation->setError('endpoint_host', 'An endpoint port was given without an address — enter the peer\'s hostname or IP, or tick Dynamic Endpoint.');

            return false;
        }

        $isAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isHostname = preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/', $host) === 1;

        if (!$isAddress && !$isHostname) {
            $validation->setError('endpoint_host', 'Endpoint \'' . $host . '\' is not a hostname, IPv4 or IPv6 address — enter only the address, not host:port or a URL.');

            return false;
        }

        if ($port !== '' && (preg_match('/^\d+$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535)) {
            $validation->setError('endpoint_port', 'Endpoint port must be a number between 1 and 65535 (leave it blank for the WireGuard default, 51820).');

            return false;
        }

        return true;
    }

    /**
     * Joins the Endpoint boxes into the single value WireGuard stores.
     *
     * An empty result means "dynamic" — the peer is expected to talk first and
     * the kernel learns its address from the incoming handshake.
     *
     * @param array<string, mixed> $post
     */
    private function buildEndpoint(array $post): ?string
    {
        if (!empty($post['dynamic_endpoint'])) {
            return null;
        }

        $host = trim((string) ($post['endpoint_host'] ?? ''));
        $port = trim((string) ($post['endpoint_port'] ?? ''));

        if ($host === '') {
            return null;
        }

        if ($port === '') {
            return $host;
        }

        // An IPv6 literal needs brackets once a port is appended.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }

        return $host . ':' . $port;
    }

    /**
     * Splits the peer's Allowed IPs into the two columns the compiler consumes:
     * its own tunnel address (the first host entry) and the networks routed
     * through it.
     *
     * WireGuard has no peer-address keyword: the leading host entry of
     * AllowedIPs IS the peer's tunnel address, which is why the form asks for a
     * single list instead of a separate field.
     *
     * Networks are stored at their subnet start boundary (`192.168.20.77/24` →
     * `192.168.20.0/24`), the same normalisation pfSense applies before saving.
     * Host entries are left exactly as typed, because `/32` (or `/128`) is what
     * makes them the peer's address rather than a routed network.
     *
     * @return array{peer_address: ?string, allowed_ips: string}
     */
    private function splitAllowedIps(string $raw): array
    {
        $peerAddress = null;
        $networks = [];

        foreach (Cidr::split($raw) as $entry) {
            if (!Cidr::isValid($entry)) {
                continue;
            }

            if ($peerAddress === null && !Cidr::isNetwork($entry)) {
                $peerAddress = Cidr::withHostPrefix($entry);
                continue;
            }

            $networks[] = Cidr::networkOf($entry) ?: $entry;
        }

        return [
            'peer_address' => $peerAddress,
            'allowed_ips' => implode(', ', array_values(array_unique($networks))),
        ];
    }

    /**
     * The next free host address in the tunnel subnet, offered in validation
     * messages so the operator does not have to work it out.
     */
    private function suggestPeerAddress(array $tunnel, ?string $peerId): string
    {
        $cidr = $this->compiler->tunnelCidr($tunnel);
        if ($cidr === '') {
            return '10.9.0.2/32';
        }

        $address = Cidr::addressOf($cidr);
        $prefix = Cidr::prefixOf($cidr);

        if (Cidr::family($cidr) === 'ipv6') {
            $packed = @inet_pton($address);
            if ($packed === false) {
                return 'fd00::2/128';
            }
            $packed[15] = chr(ord($packed[15]) + 1);

            return @inet_ntop($packed) . '/128';
        }

        $network = Cidr::networkOf($cidr);
        $base = ip2long(Cidr::addressOf($network === '' ? $cidr : $network));
        if ($base === false) {
            return '10.9.0.2/32';
        }

        $own = ip2long($address);
        $builder = \Config\Database::connect()->table('wireguard_peers')->select('peer_address')
            ->where('tunnel_id', (string) ($tunnel['id'] ?? ''));
        if ($peerId !== null) {
            $builder->where('id !=', $peerId);
        }

        $taken = [(string) ($own === false ? 0 : $own)];
        foreach ($builder->get()->getResultArray() as $row) {
            foreach (Cidr::split((string) ($row['peer_address'] ?? '')) as $existing) {
                $long = ip2long(Cidr::addressOf($existing));
                if ($long !== false) {
                    $taken[] = (string) $long;
                }
            }
        }

        // Host 0 of the subnet is the network; the appliance owns the first
        // usable host, so peers start one beyond it.
        $usable = $prefix >= 31 ? 1 : min(254, (2 ** (32 - $prefix)) - 2);

        for ($host = 1; $host <= $usable; $host++) {
            $candidate = $base + $host;
            if (!in_array((string) $candidate, $taken, true)) {
                return long2ip($candidate) . '/32';
            }
        }

        return long2ip($base + 1) . '/32';
    }

    /**
     * Persistent keepalive, clamped to the range the form offers.
     *
     * @param array<string, mixed> $post
     */
    private function keepaliveSeconds(array $post, int $fallback = 2): int
    {
        $value = trim((string) ($post['keepalive_secs'] ?? ''));
        if ($value === '') {
            return $fallback;
        }

        return max(0, min(3600, (int) $value));
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
            'name' => 'required|min_length[2]|max_length[64]|regex_match[/^[A-Za-z0-9 ._-]+$/]',
            'interface_name' => 'required|regex_match[/^[A-Za-z0-9_]{1,15}$/]',
            'listen_port' => 'required|integer|greater_than[0]|less_than[65536]',
            'mtu' => 'permit_empty|integer|greater_than_equal_to[576]|less_than_equal_to[9000]',
            'dns_servers' => 'permit_empty',
        ];
        if (!$this->validate($rules)) {
            return false;
        }

        // Address list: any number of IPv4/IPv6 addresses or CIDRs
        $validation = service('validation');

        // Address list: any number of IPv4/IPv6 addresses or CIDRs
        $raw = trim((string) ($post['addresses'] ?? ''));
        if ($raw !== '' && !Cidr::isValidList($raw)) {
            $bad = array_values(array_filter(Cidr::split($raw), static fn($entry) => !Cidr::isValid($entry)));
            $validation->setError('addresses', 'Not a valid address: ' . implode(', ', $bad)
                . ' — use CIDR notation such as 10.9.0.1/24 or fd00::1/64.');

            return false;
        }

        $dns = trim((string) ($post['dns_servers'] ?? ''));
        if ($dns !== '' && !Cidr::isValidList($dns)) {
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
            if (!Cidr::isValid($entry)) {
                continue;
            }
            if (!str_contains($entry, '/')) {
                $entry = Cidr::withPrefix($entry, Cidr::family($entry) === 'ipv6' ? 64 : 24);
            }
            $list[] = $entry;
        }
        $list = array_values(array_unique($list));

        $columns = ['addresses' => $list === [] ? null : implode(', ', $list)];

        foreach ($list as $entry) {
            if (Cidr::family($entry) === 'ipv4') {
                $columns['ipv4_address'] = Cidr::addressOf($entry);
                $columns['ipv4_subnet'] = Cidr::prefixOf($entry);
                break;
            }
        }

        return ['columns' => $columns, 'list' => $list];
    }

    private function normaliseList(string $list): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', $list)), static fn($v) => $v !== '');

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

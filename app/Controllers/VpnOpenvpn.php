<?php

namespace App\Controllers;

use App\Libraries\Cidr;
use App\Libraries\IpcClient;
use App\Libraries\OpenvpnCompiler;

/**
 * VpnOpenvpn Controller
 *
 * Manages both OpenVPN roles stored in `openvpn_servers`:
 *   - mode = 'server' → road-warrior / head-end daemon (+ .ovpn client bundles)
 *   - mode = 'client' → site-to-site tunnel dialling a remote OpenVPN server
 */
class VpnOpenvpn extends BaseController
{
    /** Crypto values the UI offers (keeps a rogue POST from writing anything else). */
    private const CIPHERS      = ['AES-256-GCM', 'AES-128-GCM', 'CHACHA20-POLY1305', 'AES-256-CBC'];
    private const AUTH_DIGESTS = ['SHA256', 'SHA512', 'SHA1'];
    private const COMPRESSION  = ['', 'lzo', 'lz4'];

    private IpcClient $ipc;
    private OpenvpnCompiler $compiler;

    /** The daemon's answer to the last `vpn.openvpn.sync`, for truthful flashes. */
    private array $lastSync = [];

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc      = \Config\Services::ipc();
        $this->compiler = new OpenvpnCompiler();
    }

    /* ----------------------------------------------------------------
     * GET /vpn/openvpn
     * ---------------------------------------------------------------- */
    public function index(): string
    {
        $db = \Config\Database::connect();
        $rows = $db->table('openvpn_servers')->orderBy('created_at', 'ASC')->get()->getResultArray();

        // Partition the shared table by role (legacy rows default to 'server')
        $servers = [];
        $clients = [];
        foreach ($rows as $row) {
            if (($row['mode'] ?? 'server') === 'client') {
                $clients[] = $row;
            } else {
                $servers[] = $row;
            }
        }

        return view('vpn/openvpn', [
            'title'      => 'OpenVPN Server & Site-to-Site — MicronOS',
            'breadcrumb' => 'OpenVPN',
            'servers'    => $servers,
            'clients'    => $clients,
            'liveStatus' => $this->ipc->call('vpn.openvpn.status')['result'] ?? [],
            'isMock'     => $this->ipc->isMock(),
        ]);
    }

    /* ----------------------------------------------------------------
     * POST /vpn/openvpn/store
     * ---------------------------------------------------------------- */
    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $post = $this->request->getPost();
        $mode = ($post['mode'] ?? 'server') === 'client' ? 'client' : 'server';

        $rules = [
            'name'     => 'required|min_length[2]|max_length[64]',
            'protocol' => 'permit_empty|in_list[udp,tcp]',
            'mode'     => 'permit_empty|in_list[server,client]',
        ];
        if ($mode === 'client') {
            $rules['remote_host'] = 'required|max_length[190]';
            $rules['remote_port'] = 'permit_empty|integer|greater_than[0]|less_than[65536]';
        }
        if (! $this->validate($rules) || ! $this->validateLists($post, $mode)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $data = $this->collectInput($post, $mode);
        $data['id']         = ($mode === 'client' ? 'ovpn_cli_' : 'ovpn_srv_') . bin2hex(random_bytes(6));
        $data['mode']       = $mode;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $db->table('openvpn_servers')->insert($data);
        $this->reloadDaemon();

        $label = $mode === 'client' ? 'Site-to-site client' : 'OpenVPN server';

        return redirect()->to(base_url('vpn/openvpn'))
            ->with(...$this->syncFlash($label . ' saved and OpenVPN reloaded.'));
    }

    /* ----------------------------------------------------------------
     * POST /vpn/openvpn/(:segment)/update
     * ---------------------------------------------------------------- */
    public function update(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $record = $db->table('openvpn_servers')->where('id', $id)->get()->getRowArray();
        if (! $record) {
            return redirect()->to(base_url('vpn/openvpn'))->with('error', 'OpenVPN instance not found.');
        }

        $post = $this->request->getPost();
        $mode = ($post['mode'] ?? $record['mode'] ?? 'server') === 'client' ? 'client' : 'server';

        if (! $this->validate([
            'name'        => 'required|min_length[2]|max_length[64]',
            'protocol'    => 'permit_empty|in_list[udp,tcp]',
            'remote_host' => $mode === 'client' ? 'required|max_length[190]' : 'permit_empty|max_length[190]',
            'remote_port' => 'permit_empty|integer|greater_than[0]|less_than[65536]',
        ]) || ! $this->validateLists($post, $mode)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $data               = $this->collectInput($post, $mode, $record);
        $data['mode']       = $mode;
        $data['updated_at'] = date('Y-m-d H:i:s');

        $db->table('openvpn_servers')->where('id', $id)->update($data);
        $this->reloadDaemon();

        return redirect()->to(base_url('vpn/openvpn'))
            ->with(...$this->syncFlash('OpenVPN instance updated and reloaded.'));
    }

    /* ----------------------------------------------------------------
     * POST /vpn/openvpn/(:segment)/toggle
     * ---------------------------------------------------------------- */
    public function toggle(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $record = $db->table('openvpn_servers')->where('id', $id)->get()->getRowArray();
        if (! $record) {
            return redirect()->to(base_url('vpn/openvpn'))->with('error', 'OpenVPN instance not found.');
        }

        $enabled = ((int) $record['enabled'] === 1) ? 0 : 1;
        $db->table('openvpn_servers')->where('id', $id)
            ->update(['enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->reloadDaemon();

        return redirect()->to(base_url('vpn/openvpn'))
            ->with(...$this->syncFlash('OpenVPN instance ' . ($enabled ? 'enabled' : 'disabled') . '.'));
    }

    /* ----------------------------------------------------------------
     * POST /vpn/openvpn/(:segment)/delete
     * ---------------------------------------------------------------- */
    public function delete(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('openvpn_servers')->where('id', $id)->delete();
        $this->reloadDaemon();

        return redirect()->to(base_url('vpn/openvpn'))->with(...$this->syncFlash('OpenVPN instance removed.'));
    }

    /* ----------------------------------------------------------------
     * POST /vpn/openvpn/restart
     * ---------------------------------------------------------------- */
    public function restart(): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->reloadDaemon();

        // A remote OpenVPN peer with Pull DNS may have pushed resolvers; the
        // DNS Server Override decides whether the appliance adopts them.
        $note = $this->reconcileApplianceResolver($this->ipc);

        return redirect()->to(base_url('vpn/openvpn'))
            ->with('success', 'OpenVPN service reloaded from the stored configuration.' . $note);
    }

    /* ----------------------------------------------------------------
     * GET /vpn/openvpn/client-config/(:segment)
     * Compiles the site-to-site client tunnel config for download.
     * ---------------------------------------------------------------- */
    public function clientConfig(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $record = $this->find($id) ?? [
            'id'              => $id,
            'name'            => 'site-to-site',
            'mode'            => 'client',
            'protocol'        => 'udp',
            'remote_host'     => 'vpn.example.com',
            'remote_port'     => 1194,
            'tunnel_local_ip' => '10.8.0.2',
            'tunnel_remote_ip'=> '10.8.0.1',
        ];

        $certs = $this->certs(OpenvpnCompiler::CLIENT_CERT_DIR);

        $conf = $this->compiler->compileClientConfig($record, $this->request->getGet('inline') ? $certs : []);

        $filename = 'openvpn-client-' . preg_replace('/[^a-z0-9_-]/i', '_', $record['name'] ?? $id) . '.conf';

        return $this->response
            ->setHeader('Content-Type', 'text/plain')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($conf);
    }

    /* ----------------------------------------------------------------
     * GET /vpn/openvpn/ccd/(:segment)
     * Server-side client-config-dir entry for the matching site-to-site peer.
     * ---------------------------------------------------------------- */
    public function ccd(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $record = $this->find($id);
        if (! $record) {
            return redirect()->to(base_url('vpn/openvpn'))->with('error', 'OpenVPN instance not found.');
        }

        $body = $this->compiler->compileCcd($record);

        return $this->response
            ->setHeader('Content-Type', 'text/plain')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $this->compiler->ccdName($record) . '"')
            ->setBody($body);
    }

    /* ----------------------------------------------------------------
     * GET /vpn/openvpn/export/(:segment)
     * All-in-one .ovpn bundle for a road-warrior user of this server.
     * ---------------------------------------------------------------- */
    public function export(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $server = $this->find($id) ?? [
            'id'              => $id,
            'name'            => 'RoadWarrior SSL Server',
            'mode'            => 'server',
            'protocol'        => 'udp',
            'listen_port'     => 1194,
            'tunnel_subnet'   => '10.8.0.0/24',
            'public_endpoint' => '192.168.1.1',
        ];

        $certs = $this->certs(OpenvpnCompiler::SERVER_CERT_DIR);

        $clientName = $this->request->getGet('client') ?: session()->get('username') ?: 'micronos-client';
        $bundle     = $this->compiler->generateClientBundle(
            $server,
            $clientName,
            $certs['ca'],
            $certs['cert'],
            $certs['key'],
            $certs['tls_auth']
        );

        $filename = 'micronos-' . preg_replace('/[^a-z0-9_-]/i', '_', $clientName) . '.ovpn';

        return $this->response
            ->setHeader('Content-Type', 'application/x-openvpn-profile')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($bundle);
    }

    /* ----------------------------------------------------------------
     * GET /vpn/openvpn/server-config/(:segment)
     * ---------------------------------------------------------------- */
    public function serverConfig(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $server = $this->find($id) ?? [
            'id' => $id, 'name' => 'openvpn-server', 'mode' => 'server',
            'protocol' => 'udp', 'listen_port' => 1194, 'tunnel_subnet' => '10.8.0.0/24',
        ];

        return $this->response
            ->setHeader('Content-Type', 'text/plain')
            ->setHeader('Content-Disposition', 'attachment; filename="openvpn-server-' . $id . '.conf"')
            ->setBody($this->compiler->compileServerConfig($server));
    }

    /* ----------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    private function find(string $id): ?array
    {
        return \Config\Database::connect()->table('openvpn_servers')->where('id', $id)->get()->getRowArray() ?: null;
    }

    private function reloadDaemon(): void
    {
        $this->lastSync = $this->applier()->openvpn();
    }

    /**
     * Flash pair for an action that was saved and then pushed to the daemon:
     * success only when the daemon applied it, otherwise an error naming why.
     *
     * @return array{0: string, 1: string}
     */
    private function syncFlash(string $saved): array
    {
        return $this->ipcFlash($this->lastSync, $saved, rtrim($saved, '.') . ' — but the OpenVPN apply');
    }

    /**
     * Maps POSTed form fields onto table columns.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $existing  current row (blank secrets/values are kept)
     * @return array<string, mixed>
     */
    private function collectInput(array $post, string $mode, array $existing = []): array
    {
        // A field that is present in the POST is authoritative — even when empty,
        // so clearing a network list or a name actually clears it. Fields the form
        // did not submit at all keep their stored value.
        $value = static function (string $key, string $default = '') use ($post, $existing): string {
            if (array_key_exists($key, $post)) {
                return trim((string) $post[$key]);
            }

            return trim((string) ($existing[$key] ?? $default));
        };

        $cipher = $value('cipher', 'AES-256-GCM');
        if (! in_array($cipher, self::CIPHERS, true)) {
            $cipher = 'AES-256-GCM';
        }
        $auth = $value('auth_digest', 'SHA256');
        if (! in_array($auth, self::AUTH_DIGESTS, true)) {
            $auth = 'SHA256';
        }
        $compression = trim((string) ($post['compression'] ?? ($existing['compression'] ?? '')));
        if (! in_array($compression, self::COMPRESSION, true)) {
            $compression = '';
        }

        $data = [
            'name'             => $value('name', 'openvpn-' . $mode),
            'protocol'         => in_array(strtolower($value('protocol', 'udp')), ['udp', 'tcp'], true)
                                    ? strtolower($value('protocol', 'udp')) : 'udp',
            'interface_name'   => $value('interface_name', 'tun0'),
            'cipher'           => $cipher,
            'auth_digest'      => $auth,
            'compression'      => $compression,
            'tls_auth'         => isset($post['tls_auth']) ? (int) ((bool) $post['tls_auth']) : (int) ($existing['tls_auth'] ?? 1),
            'redirect_gateway' => isset($post['redirect_gateway']) ? 1 : 0,
            'enabled'          => isset($post['enabled']) ? 1 : (int) ($existing['enabled'] ?? 1),
        ];

        if ($mode === 'client') {
            $data['remote_host']      = $value('remote_host');
            $data['remote_port']      = (int) ($post['remote_port'] ?? $existing['remote_port'] ?? 1194);
            $data['tunnel_local_ip']  = $value('tunnel_local_ip');
            $data['tunnel_remote_ip'] = $value('tunnel_remote_ip');
            $data['tunnel_subnet']    = $value('tunnel_subnet', '10.8.0.0/24');
            $data['remote_subnets']   = $this->normaliseList($value('remote_subnets'));
            // Networks behind THIS appliance — the remote server needs them as iroutes
            $data['local_subnets']    = $this->normaliseList($value('local_subnets'));
            $data['verify_x509_name'] = $value('verify_x509_name');
            $data['dns_servers']      = $this->normaliseList($value('dns_servers'));
            // Mirrored so the daemon knows which port to bind if it acts as a fallback server
            $data['listen_port']      = (int) ($post['remote_port'] ?? $existing['remote_port'] ?? 1194);
        } else {
            $data['listen_port']    = (int) ($post['listen_port'] ?? $existing['listen_port'] ?? 1194);
            $data['tunnel_subnet']  = $value('tunnel_subnet', '10.8.0.0/24');
            $data['public_endpoint']= $value('public_endpoint');
            $data['dns_servers']    = $this->normaliseList($value('dns_servers'));
            // Server side: "IPv4 Local Network(s)" shipped to clients via push "route"
            $data['routes_pushed']  = $this->normaliseList(
                array_key_exists('local_subnets', $post) ? $value('local_subnets') : $value('routes_pushed')
            );
            $data['auth_mode']      = $value('auth_mode', 'cert_totp');
        }

        return $data;
    }

    /** Collapses "a, b ,,c" into "a, b, c" and drops duplicates. */
    private function normaliseList(string $list): string
    {
        return Cidr::normalise($list);
    }

    /**
     * Validates every address/network list on the form.
     *
     * A tunnel routinely carries several networks, so a typo in one entry must
     * be caught here rather than compiled into an OpenVPN config that silently
     * fails to load.
     *
     * @param array<string, mixed> $post
     */
    private function validateLists(array $post, string $mode): bool
    {
        // service('validation') rather than $this->validator: update paths may not
        // have populated the controller property yet.
        $validation = service('validation');

        // One or many networks are allowed here — that is the point of the list
        $lists = [
            'remote_subnets' => 'IPv4 Remote Network(s)',
            'local_subnets'  => 'IPv4 Local Network(s)',
            'routes_pushed'  => 'Local Network(s)',
            'dns_servers'    => 'DNS server',
        ];

        foreach ($lists as $field => $label) {
            $raw = trim((string) ($post[$field] ?? ''));
            if ($raw === '' || Cidr::isValidList($raw)) {
                continue;
            }

            $bad = array_values(array_filter(Cidr::split($raw), static fn ($entry) => ! Cidr::isValid($entry)));
            $validation->setError($field, $label . ' contains an invalid entry: ' . implode(', ', $bad)
                . ' (use CIDR notation, e.g. 192.168.20.0/24 — separate multiple entries with commas).');

            return false;
        }

        // The tunnel pool itself must be a network, not a single address
        $tunnelNetwork = trim((string) ($post['tunnel_subnet'] ?? ''));
        if ($tunnelNetwork !== '' && (! Cidr::isValid($tunnelNetwork) || ! Cidr::isNetwork($tunnelNetwork))) {
            $validation->setError('tunnel_subnet', 'Tunnel network must be written as a CIDR network, e.g. 10.8.0.0/24.');

            return false;
        }

        // Point-to-point tunnel addresses are single host addresses, not subnets
        foreach (['tunnel_local_ip', 'tunnel_remote_ip'] as $field) {
            $raw = trim((string) ($post[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (! Cidr::isValid($raw)) {
                $validation->setError($field, 'Enter a single tunnel address, e.g. 10.8.0.2.');

                return false;
            }
            if ($mode === 'client' && Cidr::isNetwork($raw)) {
                $validation->setError($field, 'The tunnel address is a point-to-point pair — enter the address alone, without a /prefix.');

                return false;
            }
        }

        return true;
    }

    /**
     * Loads CA/cert/key material for a role directory (demo bodies on dev hosts).
     *
     * @return array{ca: string, cert: string, key: string, tls_auth: string}
     */
    private function certs(string $dir): array
    {
        return [
            'ca'       => $this->compiler->loadCert($dir . '/ca.crt', "-----BEGIN CERTIFICATE-----\nMIIDXTCCAkWgAwIBAgIUDEMO_CA...\n-----END CERTIFICATE-----"),
            'cert'     => $this->compiler->loadCert($dir . '/client.crt', "-----BEGIN CERTIFICATE-----\nMIIDXTCCAkWgAwIBAgIUCLIENT...\n-----END CERTIFICATE-----"),
            'key'      => $this->compiler->loadCert($dir . '/client.key', "-----BEGIN PRIVATE KEY-----\nMIIEvwIBADANBgkqhkiG9w...\n-----END PRIVATE KEY-----"),
            'tls_auth' => $this->compiler->loadCert($dir . '/ta.key', "-----BEGIN OpenVPN Static key V1-----\n# 2048-bit OpenVPN static key demo\n-----END OpenVPN Static key V1-----"),
        ];
    }
}

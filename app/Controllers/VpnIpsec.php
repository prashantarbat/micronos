<?php

namespace App\Controllers;

use App\Libraries\Cidr;
use App\Libraries\IpcClient;
use App\Libraries\StrongswanCompiler;

/**
 * VpnIpsec Controller — strongSwan IKEv1/IKEv2 Site-to-Site and Mobile Client VPN
 *
 * Supports both IPsec shapes: policy-based (traffic selectors only, no tunnel
 * interface) and route-based with a VTI, where the tunnel owns an interface with
 * its own local/remote address pair.
 */
class VpnIpsec extends BaseController
{
    private IpcClient $ipc;
    private StrongswanCompiler $compiler;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc      = \Config\Services::ipc();
        $this->compiler = new StrongswanCompiler();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();
        $tunnels = $db->table('ipsec_tunnels')->orderBy('created_at', 'ASC')->get()->getResultArray();

        // Get live IKE SAs and Child SAs from strongSwan daemon via micrond
        $liveStatus = $this->ipc->getIpsecStatus();

        return view('vpn/ipsec', [
            'title'      => 'IPsec & IKEv2 VPN — MicronOS',
            'breadcrumb' => 'IPsec / IKEv2',
            'tunnels'    => $tunnels,
            'liveStatus' => $liveStatus,
            'isMock'     => $this->ipc->isMock(),
        ]);
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $rules = [
            'name'        => 'required|min_length[3]|max_length[64]',
            'ike_version' => 'required|in_list[ikev2,ikev1]',
            'mode'        => 'required|in_list[site2site,roadwarrior]',
        ];

        if (! $this->validate($rules) || ! $this->validateSubnets()) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $id  = 'ipsec_' . bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');

        $db->table('ipsec_tunnels')->insert(array_merge([
            'id'             => $id,
            'name'           => trim($this->request->getPost('name')),
            'ike_version'    => $this->request->getPost('ike_version'),
            'mode'           => $this->request->getPost('mode'),
            'remote_gateway' => trim($this->request->getPost('remote_gateway') ?: null),
            'auth_type'      => $this->request->getPost('auth_type') ?: 'psk',
            'psk_secret'     => trim($this->request->getPost('psk_secret') ?: null),
            'local_subnet'   => trim($this->request->getPost('local_subnet') ?: '192.168.1.0/24'),
            'remote_subnet'  => trim($this->request->getPost('remote_subnet') ?: null),
            'ike_cipher'     => $this->request->getPost('ike_cipher') ?: 'aes256gcm16-prfsha384-ecp384',
            'esp_cipher'     => $this->request->getPost('esp_cipher') ?: 'aes256gcm16-ecp384',
            'enabled'        => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $this->vtiFields($this->request->getPost(), null)));

        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('vpn.ipsec.sync'),
            'IPsec tunnel configured and loaded into strongSwan.',
            'Saved, but the IPsec sync'
        );

        return redirect()->to(base_url('vpn/ipsec'))->with($key, $message);
    }

    public function delete(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('ipsec_tunnels')->where('id', $id)->delete();
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('vpn.ipsec.sync'),
            'IPsec tunnel removed.',
            'Removed, but the IPsec sync'
        );

        return redirect()->to(base_url('vpn/ipsec'))->with($key, $message);
    }

    public function update(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $tunnel = $db->table('ipsec_tunnels')->where('id', $id)->get()->getRowArray();
        if (! $tunnel) {
            return redirect()->to(base_url('vpn/ipsec'))->with('error', 'Tunnel not found.');
        }
        if (! $this->validate([
            'name'        => 'required|min_length[3]|max_length[64]',
            'ike_version' => 'required|in_list[ikev2,ikev1]',
            'mode'        => 'required|in_list[site2site,roadwarrior]',
        ])) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }
        if (! $this->validateSubnets()) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $data = [
            'name'           => trim($this->request->getPost('name') ?: $tunnel['name']),
            'ike_version'    => $this->request->getPost('ike_version') ?: $tunnel['ike_version'],
            'mode'           => $this->request->getPost('mode') ?: $tunnel['mode'],
            'remote_gateway' => trim($this->request->getPost('remote_gateway') ?: '') ?: null,
            'auth_type'      => $this->request->getPost('auth_type') ?: 'psk',
            'psk_secret'     => trim($this->request->getPost('psk_secret') ?: '') ?: $tunnel['psk_secret'],
            'local_subnet'   => trim($this->request->getPost('local_subnet') ?: '192.168.1.0/24'),
            'remote_subnet'  => trim($this->request->getPost('remote_subnet') ?: '') ?: null,
            'ike_cipher'     => $this->request->getPost('ike_cipher') ?: $tunnel['ike_cipher'],
            'esp_cipher'     => $this->request->getPost('esp_cipher') ?: $tunnel['esp_cipher'],
            'enabled'        => $this->request->getPost('enabled') !== null ? (int)$this->request->getPost('enabled') : 1,
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($data, $this->vtiFields($this->request->getPost(), $tunnel));

        $db->table('ipsec_tunnels')->where('id', $id)->update($data);
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('vpn.ipsec.sync'),
            'IPsec tunnel updated and strongSwan synchronized.',
            'Updated, but the IPsec sync'
        );

        return redirect()->to(base_url('vpn/ipsec'))->with($key, $message);
    }

    /**
     * GET /vpn/ipsec/(:segment)/vti-script
     *
     * The `ip tunnel add` / `ip addr add` commands that create the route-based
     * tunnel interface and give it the configured VTI address pair.
     */
    public function vtiScript(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $tunnel = \Config\Database::connect()->table('ipsec_tunnels')->where('id', $id)->get()->getRowArray();
        if (! $tunnel) {
            return redirect()->to(base_url('vpn/ipsec'))->with('error', 'Tunnel not found.');
        }

        $iface = $this->compiler->normalize($tunnel)['vti_interface'];

        return $this->response
            ->setHeader('Content-Type', 'text/x-shellscript')
            ->setHeader('Content-Disposition', 'attachment; filename="vti-' . $iface . '.sh"')
            ->setBody($this->compiler->compileVtiScript($tunnel));
    }

    public function toggle(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $tunnel = $db->table('ipsec_tunnels')->where('id', $id)->get()->getRowArray();
        if ($tunnel) {
            $newStatus = ((int)$tunnel['enabled'] === 1) ? 0 : 1;
            $db->table('ipsec_tunnels')->where('id', $id)->update(['enabled' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')]);
            $statusText = $newStatus ? 'enabled' : 'disabled';

            [$key, $message] = $this->ipcFlash(
                $this->ipc->call('vpn.ipsec.sync'),
                "IPsec tunnel {$statusText}.",
                'State saved, but the IPsec sync'
            );

            return redirect()->to(base_url('vpn/ipsec'))->with($key, $message);
        }
        return redirect()->to(base_url('vpn/ipsec'))->with('error', 'Tunnel not found.');
    }

    /**
     * GET /vpn/ipsec/config
     *
     * The compiled /etc/swanctl/swanctl.conf for every enabled tunnel.
     */
    public function compiledConfig(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response
            ->setHeader('Content-Type', 'text/plain')
            ->setHeader('Content-Disposition', 'attachment; filename="swanctl.conf"')
            ->setBody($this->compiler->compile());
    }

    public function restart(): \CodeIgniter\HTTP\RedirectResponse
    {
        [$key, $message] = $this->ipcFlash(
            $this->ipc->call('service.restart', ['service' => 'strongswan']),
            'strongSwan IPsec daemon reloaded.',
            'strongSwan restart'
        );

        return redirect()->to(base_url('vpn/ipsec'))->with($key, $message);
    }

    /**
     * Validates the local/remote traffic selectors.
     *
     * strongSwan accepts a comma-separated list in local_ts/remote_ts, which is
     * how one tunnel protects several subnets at once, so each entry is checked
     * individually instead of the field being treated as a single CIDR.
     */
    private function validateSubnets(): bool
    {
        // service('validation') rather than $this->validator: this helper can run
        // on update paths where Controller::validate() has not populated it yet.
        $validation = service('validation');

        foreach (['local_subnet' => 'Local network', 'remote_subnet' => 'Remote network'] as $field => $label) {
            $raw = trim((string) ($this->request->getPost($field) ?? ''));
            if ($raw === '' || Cidr::isValidList($raw)) {
                continue;
            }

            $bad = array_values(array_filter(Cidr::split($raw), static fn ($entry) => ! Cidr::isValid($entry)));
            $validation->setError($field, $label . ' list contains an invalid entry: ' . implode(', ', $bad)
                . ' (use CIDR notation, e.g. 10.50.0.0/16 — separate multiple subnets with commas).');

            return false;
        }

        return true;
    }

    /**
     * Collects the addressing + IKE identity fields shared by store() and update().
     *
     * @param array<string, mixed>      $post
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function vtiFields(array $post, ?array $existing): array
    {
        $pick = static function (string $key, $fallback) use ($post) {
            $value = trim((string) ($post[$key] ?? ''));

            return $value !== '' ? $value : $fallback;
        };

        $routeBased = isset($post['vti_enabled']);

        return [
            'local_gateway' => trim((string) ($post['local_gateway'] ?? '')) ?: ($existing['local_gateway'] ?? null),
            'local_id'      => trim((string) ($post['local_id'] ?? '')) ?: ($existing['local_id'] ?? null),
            'remote_id'     => trim((string) ($post['remote_id'] ?? '')) ?: ($existing['remote_id'] ?? null),
            'vti_enabled'   => $routeBased ? 1 : 0,
            'vti_interface' => $routeBased
                ? ($pick('vti_interface', $existing['vti_interface'] ?? 'vti0'))
                : ($existing['vti_interface'] ?? null),
            'vti_local_ip'  => $routeBased
                ? ($pick('vti_local_ip', $existing['vti_local_ip'] ?? null))
                : ($existing['vti_local_ip'] ?? null),
            'vti_remote_ip' => $routeBased
                ? ($pick('vti_remote_ip', $existing['vti_remote_ip'] ?? null))
                : ($existing['vti_remote_ip'] ?? null),
            'vti_mark'      => $routeBased
                ? (int) ($post['vti_mark'] ?: ($existing['vti_mark'] ?? StrongswanCompiler::DEFAULT_VTI_MARK))
                : ($existing['vti_mark'] ?? StrongswanCompiler::DEFAULT_VTI_MARK),
        ];
    }
}

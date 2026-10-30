<?php

namespace App\Controllers;

use App\Libraries\IpcClient;

/**
 * Nat Controller — object-first Network Address Translation
 *
 * One page, four tabs:
 *   1. Destination NAT — inbound DNAT rules (a plain port-forward carries
 *      ports; a bi-directional rule is a 1:1 mapping).
 *   2. SNAT Pools      — source-NAT address ranges, referenced from policies.
 *   3. NPTv6           — stateless IPv6 prefix translation (RFC 6296).
 *   4. Settings        — global NAT reflection (hairpin) and outbound mode.
 *
 * Outbound NAT itself lives on the firewall policy (interface masquerade or an
 * SNAT pool), which is where the traffic decision is actually made.
 */
class Nat extends BaseController
{
    private IpcClient $ipc;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();

        $settings = $db->table('nat_settings')->where('id', 'global')->get()->getRowArray();
        if (!$settings) {
            $settings = [
                'id'                 => 'global',
                'outbound_mode'      => 'automatic',
                'nat_reflection'     => 1,
                'reflection_timeout' => 30,
            ];
        }

        $tab = $this->request->getGet('tab') ?: 'dnat';
        if (! in_array($tab, ['dnat', 'snat', 'npt', 'settings'], true)) {
            $tab = 'dnat';
        }

        return view('nat/index', [
            'title'      => 'NAT — MicronOS',
            'breadcrumb' => 'Firewall: NAT',
            'activeTab'  => $tab,
            'dnatRules'  => $db->table('nat_dnat')->orderBy('name', 'ASC')->get()->getResultArray(),
            'snatPools'  => $db->table('nat_snat_pools')->orderBy('name', 'ASC')->get()->getResultArray(),
            'nptRules'   => $db->table('nat_nptv6')->orderBy('created_at', 'ASC')->get()->getResultArray(),
            'settings'   => $settings,
            'interfaces'         => \App\Libraries\VpnInterfaceProvider::getUnifiedInterfaces($db),
            'physicalInterfaces' => $db->table('interfaces')->orderBy('name', 'ASC')->get()->getResultArray(),
            'vpnInterfaces'      => \App\Libraries\VpnInterfaceProvider::getVpnInterfaces($db),
            'schedules'  => $db->table('firewall_schedules')->orderBy('name', 'ASC')->get()->getResultArray(),
            'isMock'     => $this->ipc->isMock(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // TAB 1: Destination NAT rules (DNAT)
    // ─────────────────────────────────────────────────────────────────

    public function storeDnat(): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->saveDnat(null);
    }

    public function updateDnat(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->saveDnat($id);
    }

    private function saveDnat(?string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $back = redirect()->to(base_url('nat?tab=dnat'));

        $name       = trim($this->request->getPost('name') ?: '');
        $externalIp = trim($this->request->getPost('external_ip') ?: '');
        $mappedIp   = trim($this->request->getPost('mapped_ip') ?: '');

        if ($name === '' || ! filter_var($externalIp, FILTER_VALIDATE_IP) || ! filter_var($mappedIp, FILTER_VALIDATE_IP)) {
            return $back->with('error', 'A name and valid external / mapped IP addresses are required.');
        }

        $dup = $db->table('nat_dnat')->where('name', $name);
        if ($id !== null) {
            $dup->where('id !=', $id);
        }
        if ($dup->countAllResults() > 0) {
            return $back->with('error', "A Destination NAT named '{$name}' already exists.");
        }

        $data = [
            'name'           => $name,
            'interface_id'   => $this->request->getPost('interface_id') ?: 'wan',
            'external_ip'    => $externalIp,
            'external_port'  => $this->request->getPost('external_port') !== null && $this->request->getPost('external_port') !== '' ? (int) $this->request->getPost('external_port') : null,
            'protocol'       => $this->request->getPost('protocol') ?: 'tcp',
            'mapped_ip'      => $mappedIp,
            'mapped_port'    => $this->request->getPost('mapped_port') !== null && $this->request->getPost('mapped_port') !== '' ? (int) $this->request->getPost('mapped_port') : null,
            'nat_reflection' => $this->request->getPost('nat_reflection') ? 1 : 0,
            'bidirectional'  => $this->request->getPost('bidirectional') ? 1 : 0,
            'schedule'       => $this->request->getPost('schedule') ?: 'always',
            'comment'        => trim($this->request->getPost('comment') ?: ''),
            'enabled'        => $this->request->getPost('enabled') !== null ? (int) $this->request->getPost('enabled') : 1,
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        if ($id === null) {
            $data['id']         = 'dnat_' . bin2hex(random_bytes(6));
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->table('nat_dnat')->insert($data);
        } else {
            if (! $db->table('nat_dnat')->where('id', $id)->countAllResults()) {
                return $back->with('error', 'Destination NAT not found.');
            }
            $db->table('nat_dnat')->where('id', $id)->update($data);
        }

        return $back->with('success', "Destination NAT '{$name}' saved. Reference it from a firewall policy, then Apply.");
    }

    public function toggleDnat(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $row = $db->table('nat_dnat')->where('id', $id)->get()->getRowArray();
        if ($row) {
            $db->table('nat_dnat')->where('id', $id)->update(['enabled' => ((int) $row['enabled'] === 1) ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')]);
            return redirect()->to(base_url('nat?tab=dnat'))->with('success', 'Destination NAT status toggled.');
        }
        return redirect()->to(base_url('nat?tab=dnat'))->with('error', 'Destination NAT not found.');
    }

    public function deleteDnat(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('nat_dnat')->where('id', $id)->delete();
        return redirect()->to(base_url('nat?tab=dnat'))->with('success', 'Destination NAT removed.');
    }

    // ─────────────────────────────────────────────────────────────────
    // TAB 2: SNAT Pools (SNAT source ranges)
    // ─────────────────────────────────────────────────────────────────

    public function storeSnatPool(): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->saveSnatPool(null);
    }

    public function updateSnatPool(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->saveSnatPool($id);
    }

    private function saveSnatPool(?string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $back = redirect()->to(base_url('nat?tab=snat'));

        $name    = trim($this->request->getPost('name') ?: '');
        $startIp = trim($this->request->getPost('start_ip') ?: '');
        $endIp   = trim($this->request->getPost('end_ip') ?: '');

        if ($name === '' || ! filter_var($startIp, FILTER_VALIDATE_IP) || ($endIp !== '' && ! filter_var($endIp, FILTER_VALIDATE_IP))) {
            return $back->with('error', 'A name and a valid start (and optional end) IP are required.');
        }

        $dup = $db->table('nat_snat_pools')->where('name', $name);
        if ($id !== null) {
            $dup->where('id !=', $id);
        }
        if ($dup->countAllResults() > 0) {
            return $back->with('error', "An SNAT Pool named '{$name}' already exists.");
        }

        $data = [
            'name'         => $name,
            'interface_id' => $this->request->getPost('interface_id') ?: 'wan',
            'start_ip'     => $startIp,
            'end_ip'       => $endIp !== '' ? $endIp : null,
            'comment'      => trim($this->request->getPost('comment') ?: ''),
            'enabled'      => $this->request->getPost('enabled') !== null ? (int) $this->request->getPost('enabled') : 1,
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        if ($id === null) {
            $data['id']         = 'snat_' . bin2hex(random_bytes(6));
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->table('nat_snat_pools')->insert($data);
        } else {
            if (! $db->table('nat_snat_pools')->where('id', $id)->countAllResults()) {
                return $back->with('error', 'SNAT Pool not found.');
            }
            $db->table('nat_snat_pools')->where('id', $id)->update($data);
        }

        return $back->with('success', "SNAT Pool '{$name}' saved. Select it as a policy's SNAT address, then Apply.");
    }

    public function toggleSnatPool(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $row = $db->table('nat_snat_pools')->where('id', $id)->get()->getRowArray();
        if ($row) {
            $db->table('nat_snat_pools')->where('id', $id)->update(['enabled' => ((int) $row['enabled'] === 1) ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')]);
            return redirect()->to(base_url('nat?tab=snat'))->with('success', 'SNAT Pool status toggled.');
        }
        return redirect()->to(base_url('nat?tab=snat'))->with('error', 'SNAT Pool not found.');
    }

    public function deleteSnatPool(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('nat_snat_pools')->where('id', $id)->delete();
        return redirect()->to(base_url('nat?tab=snat'))->with('success', 'SNAT Pool removed.');
    }

    // ─────────────────────────────────────────────────────────────────
    // TAB 3: IPv6 Prefix Translation (NPTv6 — RFC 6296)
    // ─────────────────────────────────────────────────────────────────

    public function storeNpt(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $back = redirect()->to(base_url('nat?tab=npt'));

        $intPrefix = trim($this->request->getPost('internal_prefix') ?? '');
        $extPrefix = trim($this->request->getPost('external_prefix') ?? '');

        if (empty($intPrefix) || empty($extPrefix)) {
            return $back->with('error', 'Internal and External IPv6 prefixes are required.');
        }

        $db->table('nat_nptv6')->insert([
            'id'              => 'npt_' . bin2hex(random_bytes(6)),
            'interface_id'    => $this->request->getPost('interface_id') ?: 'wan',
            'internal_prefix' => $intPrefix,
            'external_prefix' => $extPrefix,
            'description'     => trim($this->request->getPost('description') ?? ''),
            'enabled'         => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return $back->with('success', 'NPTv6 mapping created.');
    }

    public function toggleNpt(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $row = $db->table('nat_nptv6')->where('id', $id)->get()->getRowArray();
        if ($row) {
            $db->table('nat_nptv6')->where('id', $id)->update(['enabled' => ((int) $row['enabled'] === 1) ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')]);
            return redirect()->to(base_url('nat?tab=npt'))->with('success', 'NPTv6 mapping status toggled.');
        }
        return redirect()->to(base_url('nat?tab=npt'))->with('error', 'NPTv6 mapping not found.');
    }

    public function deleteNpt(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('nat_nptv6')->where('id', $id)->delete();
        return redirect()->to(base_url('nat?tab=npt'))->with('success', 'NPTv6 mapping removed.');
    }

    // ─────────────────────────────────────────────────────────────────
    // TAB 4: Global NAT Settings
    // ─────────────────────────────────────────────────────────────────

    public function saveSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $back = redirect()->to(base_url('nat?tab=settings'));

        $mode = strtolower($this->request->getPost('outbound_mode') ?: 'automatic');
        if (! in_array($mode, ['automatic', 'manual', 'disabled'], true)) {
            $mode = 'automatic';
        }

        $existing = $db->table('nat_settings')->where('id', 'global')->countAllResults();
        $data = [
            'outbound_mode'      => $mode,
            'nat_reflection'     => $this->request->getPost('nat_reflection') ? 1 : 0,
            'reflection_timeout' => max(10, (int) ($this->request->getPost('reflection_timeout') ?? 30)),
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $db->table('nat_settings')->where('id', 'global')->update($data);
        } else {
            $data['id'] = 'global';
            $db->table('nat_settings')->insert($data);
        }

        return $back->with('success', 'Global NAT settings saved.');
    }

    public function apply(): \CodeIgniter\HTTP\RedirectResponse
    {
        $tab = $this->request->getPost('tab') ?: 'dnat';
        try {
            $resp = $this->ipc->call('firewall.apply', ['table' => 'nat']);
            $msg = $resp['message'] ?? 'NAT objects (Destination NAT rules, SNAT Pools, NPTv6) compiled and loaded into Linux Netfilter.';
            return redirect()->to(base_url('nat?tab=' . urlencode($tab)))->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('nat?tab=' . urlencode($tab)))->with('error', 'Failed to apply NAT: ' . $e->getMessage());
        }
    }
}

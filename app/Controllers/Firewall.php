<?php

namespace App\Controllers;

use App\Libraries\Fqdn;
use App\Libraries\GeoIp;
use App\Libraries\InternetService;
use App\Libraries\IpcClient;
use App\Libraries\NftablesCompiler;
use App\Libraries\ObjectSearch;

/**
 * Firewall Controller — Firewall Policies & Objects Management
 * Supports Addresses (Subnet, Range, FQDN), Address Groups, Services/Known Ports,
 * Service Groups, Interface-Pair grouping, and atomic nftables compilation.
 */
class Firewall extends BaseController
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

        // 1. Interfaces
        $interfaces = $db->table('interfaces')->orderBy('name', 'ASC')->get()->getResultArray();
        $selectedIface = $this->request->getGet('if') ?: 'all';
        $viewMode = $this->request->getGet('view') ?: 'pair'; // 'pair' (interface-pair view) or 'seq'

        // 2. Objects: Addresses & Groups
        $addresses = $db->table('firewall_addresses')->orderBy('name', 'ASC')->get()->getResultArray();
        $addressGroups = $db->table('firewall_address_groups')->orderBy('name', 'ASC')->get()->getResultArray();

        // 3. Objects: Services & Groups
        $services = $db->table('firewall_services')->orderBy('category', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
        $serviceGroups = $db->table('firewall_service_groups')->orderBy('name', 'ASC')->get()->getResultArray();

        // 3b. NAT objects: Destination NAT rules & SNAT pools — referenced by policies
        $dnatRules = $db->table('nat_dnat')->orderBy('name', 'ASC')->get()->getResultArray();
        $snatPools = $db->table('nat_snat_pools')->orderBy('name', 'ASC')->get()->getResultArray();

        // 4. Policies (firewall_rules)
        $ruleBuilder = $db->table('firewall_rules');
        if ($selectedIface !== 'all') {
            $ruleBuilder->groupStart()
                ->where('src_interface', $selectedIface)
                ->orWhere('dst_interface', $selectedIface)
                ->orWhere('interface_id', $selectedIface)
                ->groupEnd();
        }
        $rules = $ruleBuilder->orderBy('sequence', 'ASC')->get()->getResultArray();

        // 5. Group rules by interface pair
        $policiesByPair = [];
        foreach ($rules as $r) {
            $src = !empty($r['src_interface']) ? $r['src_interface'] : ($r['interface_id'] ?? 'any');
            $dst = !empty($r['dst_interface']) ? $r['dst_interface'] : 'any';
            $pairKey = strtoupper($src) . ' ➔ ' . strtoupper($dst);
            if (!isset($policiesByPair[$pairKey])) {
                $policiesByPair[$pairKey] = [
                    'src'   => $src,
                    'dst'   => $dst,
                    'title' => $pairKey,
                    'rules' => [],
                ];
            }
            $policiesByPair[$pairKey]['rules'][] = $r;
        }

        // Live stats & IPC status
        $liveRules = $this->ipc->getFirewallRules();

        return view('firewall/index', [
            'title'          => 'Firewall Policy & Objects — MicronOS',
            'breadcrumb'     => 'Firewall Policy',
            'interfaces'     => $interfaces,
            'selectedIface'  => $selectedIface,
            'viewMode'       => $viewMode,
            'rules'          => $rules,
            'policiesByPair' => $policiesByPair,
            'addresses'      => $addresses,
            'addressGroups'  => $addressGroups,
            'services'       => $services,
            'serviceGroups'  => $serviceGroups,
            'dnatRules'      => $dnatRules,
            'snatPools'      => $snatPools,
            'schedules'      => $db->table('firewall_schedules')->orderBy('name', 'ASC')->get()->getResultArray(),
            'liveRules'      => $liveRules,
            'isMock'         => $this->ipc->isMock(),
        ]);
    }

    // ========================================================
    // Policy management
    // ========================================================

    public function storePolicy(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $name = trim($this->request->getPost('name') ?: '');
        $srcIface = $this->request->getPost('src_interface') ?: 'lan';
        $dstIface = $this->request->getPost('dst_interface') ?: 'wan';
        $action = strtolower($this->request->getPost('action') ?: 'accept');
        
        $sources = (array) ($this->request->getPost('sources') ?? ['all']);
        $destinations = (array) ($this->request->getPost('destinations') ?? ['all']);
        $services = (array) ($this->request->getPost('services') ?? ['ALL']);

        $natEnabled = $this->request->getPost('nat_enabled') ? 1 : 0;
        $schedule = $this->request->getPost('schedule') ?: 'always';
        $logTraffic = $this->request->getPost('log_traffic') ?: 'all';
        $enabled = $this->request->getPost('enabled') !== null ? (int)$this->request->getPost('enabled') : 1;
        $description = trim($this->request->getPost('description') ?: '');

        $secProfiles = [];
        if ($this->request->getPost('profile_ips')) {
            $secProfiles[] = 'ips';
        }
        if ($this->request->getPost('profile_av')) {
            $secProfiles[] = 'av';
        }
        if ($this->request->getPost('profile_webfilter')) {
            $secProfiles[] = 'webfilter';
        }

        $maxSeq = $db->table('firewall_rules')->selectMax('sequence')->get()->getRow()->sequence ?? 0;
        $policyId = 'pol_' . bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');

        $data = [
            'id'                => $policyId,
            'name'              => !empty($name) ? $name : ("Policy " . ($maxSeq + 1)),
            'sequence'          => $maxSeq + 1,
            'interface_id'      => $srcIface,
            'src_interface'     => $srcIface,
            'dst_interface'     => $dstIface,
            'sources'           => json_encode(array_values(array_filter($sources))),
            'destinations'      => json_encode(array_values(array_filter($destinations))),
            'services'          => json_encode(array_values(array_filter($services))),
            'action'            => $action,
            'direction'         => 'in',
            'protocol'          => 'any',
            'source_type'       => 'any',
            'source_value'      => 'any',
            'dest_type'         => 'any',
            'dest_value'        => 'any',
            'nat_enabled'       => $natEnabled,
            'dnat_name'          => ($vipName = trim($this->request->getPost('dnat_name') ?: '')) !== '' ? $vipName : null,
            'snat_pool_name'         => ($snatPoolName = trim($this->request->getPost('snat_pool_name') ?: '')) !== '' ? $snatPoolName : null,
            'schedule'          => $schedule,
            'log_traffic'       => $logTraffic,
            'security_profiles' => !empty($secProfiles) ? json_encode($secProfiles) : null,
            'enabled'           => $enabled,
            'description'       => $description,
            'hit_count'         => 0,
            'bytes'             => 0,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $db->table('firewall_rules')->insert($data);

        // Record automatic Git revision commit and bump patch version
        $author = (string) (session('username') ?? 'ministrator');
        \Config\Services::configRevisionEngine()->commit($author, "Create firewall policy: {$data['name']}", 'firewall', true);

        return redirect()->to(base_url('firewall/rules'))
            ->with('success', 'Firewall Policy "' . esc($data['name']) . '" created successfully.');
    }

    public function updatePolicy(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $policy = $db->table('firewall_rules')->where('id', $id)->get()->getRowArray();
        if (!$policy) {
            return redirect()->to(base_url('firewall/rules'))->with('error', 'Policy not found.');
        }

        $name = trim($this->request->getPost('name') ?: $policy['name']);
        $srcIface = $this->request->getPost('src_interface') ?: ($policy['src_interface'] ?? 'lan');
        $dstIface = $this->request->getPost('dst_interface') ?: ($policy['dst_interface'] ?? 'wan');
        $action = strtolower($this->request->getPost('action') ?: $policy['action']);

        $sources = $this->request->getPost('sources');
        $destinations = $this->request->getPost('destinations');
        $services = $this->request->getPost('services');

        $sourcesJson = $sources !== null ? json_encode((array)$sources) : $policy['sources'];
        $destinationsJson = $destinations !== null ? json_encode((array)$destinations) : $policy['destinations'];
        $servicesJson = $services !== null ? json_encode((array)$services) : $policy['services'];

        $secProfiles = [];
        if ($this->request->getPost('profile_ips')) {
            $secProfiles[] = 'ips';
        }
        if ($this->request->getPost('profile_av')) {
            $secProfiles[] = 'av';
        }
        if ($this->request->getPost('profile_webfilter')) {
            $secProfiles[] = 'webfilter';
        }

        $data = [
            'name'              => $name,
            'interface_id'      => $srcIface,
            'src_interface'     => $srcIface,
            'dst_interface'     => $dstIface,
            'sources'           => $sourcesJson,
            'destinations'      => $destinationsJson,
            'services'          => $servicesJson,
            'action'            => $action,
            'nat_enabled'       => $this->request->getPost('nat_enabled') !== null ? (int)$this->request->getPost('nat_enabled') : (int)$policy['nat_enabled'],
            'dnat_name'          => trim($this->request->getPost('dnat_name') ?: '') !== '' ? trim($this->request->getPost('dnat_name')) : null,
            'snat_pool_name'         => trim($this->request->getPost('snat_pool_name') ?: '') !== '' ? trim($this->request->getPost('snat_pool_name')) : null,
            'schedule'          => $this->request->getPost('schedule') ?: ($policy['schedule'] ?? 'always'),
            'log_traffic'       => $this->request->getPost('log_traffic') ?: ($policy['log_traffic'] ?? 'all'),
            'security_profiles' => !empty($secProfiles) ? json_encode($secProfiles) : null,
            'description'       => trim($this->request->getPost('description') ?: ($policy['description'] ?? '')),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        $db->table('firewall_rules')->where('id', $id)->update($data);

        // Record automatic Git revision commit and bump patch version
        $author = (string) (session('username') ?? 'ministrator');
        \Config\Services::configRevisionEngine()->commit($author, "Update firewall policy: {$name}", 'firewall', true);

        return redirect()->to(base_url('firewall/rules'))
            ->with('success', 'Firewall Policy "' . esc($name) . '" updated successfully.');
    }

    public function togglePolicy(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->toggle($id);
    }

    public function deletePolicy(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->delete($id);
    }

    /**
     * Clone a policy, optionally "reversed".
     *
     * A plain clone keeps every field and lands directly after the original.
     * A reversed clone swaps source/destination interfaces and address sets (so
     * an outbound "LAN → WAN allow web" becomes an inbound "WAN → LAN" policy)
     * and disables NAT, which almost never makes sense on the return path.
     */
    public function clonePolicy(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $policy = $db->table('firewall_rules')->where('id', $id)->get()->getRowArray();

        if (! $policy) {
            return redirect()->to(base_url('firewall/rules'))->with('error', 'Policy not found.');
        }

        $reverse = (bool) $this->request->getPost('reverse');

        // Addressing lives in two places depending on policy age: the newer
        // `sources`/`destinations` JSON lists, and the legacy single-value
        // `source_type`/`source_value` … `dest_port` columns. A reverse clone has
        // to swap both, or old-style policies come back with identical endpoints.
        $sources      = $this->decodeList($policy['sources'] ?? null);
        $destinations = $this->decodeList($policy['destinations'] ?? null);

        $srcIface  = $policy['src_interface'] ?: ($policy['interface_id'] ?: 'any');
        $dstIface  = $policy['dst_interface'] ?: 'any';
        $sourceLeg = [
            'type' => $policy['source_type'] ?? 'any',
            'value' => $policy['source_value'] ?? 'any',
            'port' => $policy['source_port'] ?? null,
        ];
        $destLeg = [
            'type' => $policy['dest_type'] ?? 'any',
            'value' => $policy['dest_value'] ?? 'any',
            'port' => $policy['dest_port'] ?? null,
        ];

        if ($reverse) {
            [$srcIface, $dstIface]     = [$dstIface, $srcIface];
            [$sources, $destinations]  = [$destinations, $sources];
            [$sourceLeg, $destLeg]     = [$destLeg, $sourceLeg];
        }

        // `interface_id` carries an FK to `interfaces`, so it can never be 'any'.
        // Prefer whichever side of the (possibly swapped) pair is a real zone,
        // and fall back to the original binding when both sides are 'any'.
        $ifaceId = $policy['interface_id'] ?? null;
        foreach ([$srcIface, $dstIface] as $candidate) {
            if ($candidate && strtolower($candidate) !== 'any') {
                $ifaceId = $candidate;
                break;
            }
        }

        $baseName = $policy['name'] ?: ('Policy ' . $policy['id']);
        $name     = $this->uniquePolicyName($db, $baseName . ($reverse ? ' (reverse copy)' : ' (copy)'));

        // Slot the clone directly after the original. The shift and the insert
        // must succeed or fail together, otherwise a rejected insert leaves the
        // whole policy list with a hole in its sequence numbering.
        $sequence = (int) ($policy['sequence'] ?? 0);
        $now      = date('Y-m-d H:i:s');

        $db->transBegin();

        $db->table('firewall_rules')->where('sequence >', $sequence)
           ->set('sequence', 'sequence + 1', false)->update();

        $db->table('firewall_rules')->insert([
            'id'                => 'pol_' . bin2hex(random_bytes(6)),
            'name'              => $name,
            'sequence'          => $sequence + 1,
            'interface_id'      => $ifaceId,
            'src_interface'     => $srcIface,
            'dst_interface'     => $dstIface,
            'sources'           => json_encode($sources),
            'destinations'      => json_encode($destinations),
            'services'          => json_encode($this->decodeList($policy['services'] ?? null)),
            'action'            => $policy['action'] ?? 'accept',
            'direction'         => $reverse
                ? (($policy['direction'] ?? 'in') === 'in' ? 'out' : 'in')
                : ($policy['direction'] ?? 'in'),
            'protocol'          => $policy['protocol'] ?? 'any',
            'source_type'       => $sourceLeg['type'],
            'source_value'      => $sourceLeg['value'],
            'source_port'       => $sourceLeg['port'],
            'dest_type'         => $destLeg['type'],
            'dest_value'        => $destLeg['value'],
            'dest_port'         => $destLeg['port'],
            // Reverse direction: source NAT on the return path is almost always wrong
            'nat_enabled'       => $reverse ? 0 : (int) ($policy['nat_enabled'] ?? 0),
            'schedule'          => $policy['schedule'] ?? 'always',
            'log_traffic'       => $policy['log_traffic'] ?? 'all',
            'security_profiles' => $policy['security_profiles'] ?? null,
            'enabled'           => (int) ($policy['enabled'] ?? 1),
            'description'       => $policy['description'] ?? '',
            'hit_count'         => 0,
            'bytes'             => 0,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        if ($db->transStatus() === false) {
            $db->transRollback();
            log_message('error', 'Policy clone failed: ' . ($db->error()['message'] ?? 'unknown error'));

            return redirect()->to(base_url('firewall/rules'))
                ->with('error', 'Could not clone that policy. Please check its interfaces and address objects.');
        }

        $db->transCommit();

        $label = $reverse ? 'Reverse clone' : 'Clone';

        return redirect()->to(base_url('firewall/rules'))
            ->with('success', "{$label} created as '{$name}'.");
    }

    /**
     * Decode a policy multi-value column (JSON array, or a legacy string) to a list.
     *
     * @return list<string>
     */
    private function decodeList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
    }

    /** Append a numeric suffix until the policy name is free. */
    private function uniquePolicyName($db, string $name): string
    {
        $candidate = $name;
        $suffix    = 2;

        while ($db->table('firewall_rules')->where('name', $candidate)->countAllResults() > 0) {
            $candidate = $name . ' ' . $suffix++;
        }

        return $candidate;
    }

    /**
     * Renumber every policy 1..N in current sequence order.
     *
     * Sequence is what the nftables compiler orders by and what the UI shows as
     * "#N", so deletions should not leave permanent holes behind.
     */
    private function compactSequences($db): void
    {
        $ids = $db->table('firewall_rules')
                  ->select('id')
                  ->orderBy('sequence', 'ASC')
                  ->get()
                  ->getResultArray();

        $i = 1;
        foreach ($ids as $row) {
            $db->table('firewall_rules')->where('id', $row['id'])->update(['sequence' => $i++]);
        }
    }

    // ========================================================
    // Address & address-group management
    // ========================================================

    public function storeAddress(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $name = trim($this->request->getPost('name') ?: '');
        $type = $this->request->getPost('type') ?: 'ipmask';
        $value = trim($this->request->getPost('value') ?: '');
        $interface = $this->request->getPost('interface') ?: 'any';
        $color = $this->request->getPost('color') ?: '#3b82f6';
        $comment = trim($this->request->getPost('comment') ?: '');

        if (empty($name) || empty($value)) {
            return redirect()->back()->with('error', 'Address name and IP/FQDN value are required.');
        }

        if ($type === 'geoip') {
            if ($geoError = $this->validateGeography($value)) {
                return redirect()->back()->with('error', $geoError);
            }
            $value = GeoIp::normalise($value);
        } elseif ($type === 'fqdn') {
            if (! Fqdn::isValidList($value)) {
                return redirect()->back()->with('error', 'Enter one or more valid domains (wildcards such as *.example.com are allowed).');
            }
            $value = implode(', ', Fqdn::split($value));
        } elseif ($type === 'internet-service') {
            if (InternetService::keys($value) === []) {
                return redirect()->back()->with('error', 'Select at least one Internet Service Database entry.');
            }
            $value = InternetService::normalise($value);
        }

        // Check uniqueness
        $exists = $db->table('firewall_addresses')->where('name', $name)->countAllResults();
        if ($exists > 0) {
            return redirect()->back()->with('error', "An address named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('firewall_addresses')->insert([
            'id'         => 'addr_' . bin2hex(random_bytes(6)),
            'name'       => $name,
            'type'       => $type,
            'value'      => $value,
            'interface'  => $interface,
            'color'      => $color,
            'comment'    => $comment,
            'is_system'  => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->back()
            ->with('success', "Address object '{$name}' created.");
    }

    public function deleteAddress(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $addr = $db->table('firewall_addresses')->where('id', $id)->get()->getRowArray();
        if (!$addr) {
            return redirect()->back()->with('error', 'Address not found.');
        }
        if (!empty($addr['is_system'])) {
            return redirect()->back()->with('error', 'System address cannot be deleted.');
        }

        $db->table('firewall_addresses')->where('id', $id)->delete();
        return redirect()->back()
            ->with('success', "Address object '{$addr['name']}' deleted.");
    }

    public function storeAddressGroup(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $name = trim($this->request->getPost('name') ?: '');
        $members = (array) ($this->request->getPost('members') ?? []);
        $color = $this->request->getPost('color') ?: '#6366f1';
        $comment = trim($this->request->getPost('comment') ?: '');

        if (empty($name) || empty($members)) {
            return redirect()->back()->with('error', 'Group name and at least one member address are required.');
        }

        $exists = $db->table('firewall_address_groups')->where('name', $name)->countAllResults();
        if ($exists > 0) {
            return redirect()->back()->with('error', "An address group named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('firewall_address_groups')->insert([
            'id'         => 'ag_' . bin2hex(random_bytes(6)),
            'name'       => $name,
            'members'    => json_encode(array_values($members)),
            'color'      => $color,
            'comment'    => $comment,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->back()
            ->with('success', "Address group '{$name}' created.");
    }

    public function deleteAddressGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $group = $db->table('firewall_address_groups')->where('id', $id)->get()->getRowArray();
        if ($group) {
            $db->table('firewall_address_groups')->where('id', $id)->delete();
            return redirect()->back()
                ->with('success', "Address group '{$group['name']}' deleted.");
        }
        return redirect()->back()->with('error', 'Address group not found.');
    }

    // ========================================================
    // Service & service-group management
    // ========================================================

    public function storeService(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $name = strtoupper(trim($this->request->getPost('name') ?: ''));
        $protocol = $this->request->getPost('protocol') ?: 'TCP';
        $tcpPort = trim($this->request->getPost('tcp_port') ?: '') ?: null;
        $udpPort = trim($this->request->getPost('udp_port') ?: '') ?: null;
        $category = trim($this->request->getPost('category') ?: 'General');
        $comment = trim($this->request->getPost('comment') ?: '');

        if (empty($name)) {
            return redirect()->back()->with('error', 'Service name is required.');
        }

        $exists = $db->table('firewall_services')->where('name', $name)->countAllResults();
        if ($exists > 0) {
            return redirect()->back()->with('error', "Service '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('firewall_services')->insert([
            'id'         => 'svc_' . bin2hex(random_bytes(6)),
            'name'       => $name,
            'protocol'   => $protocol,
            'tcp_port'   => $tcpPort,
            'udp_port'   => $udpPort,
            'category'   => $category,
            'is_system'  => 0,
            'comment'    => $comment,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->back()
            ->with('success', "Service '{$name}' created.");
    }

    public function deleteService(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $svc = $db->table('firewall_services')->where('id', $id)->get()->getRowArray();
        if (!$svc) {
            return redirect()->back()->with('error', 'Service not found.');
        }
        if (!empty($svc['is_system'])) {
            return redirect()->back()->with('error', 'System service cannot be deleted.');
        }

        $db->table('firewall_services')->where('id', $id)->delete();
        return redirect()->back()
            ->with('success', "Service '{$svc['name']}' deleted.");
    }

    public function storeServiceGroup(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $name = trim($this->request->getPost('name') ?: '');
        $members = (array) ($this->request->getPost('members') ?? []);
        $comment = trim($this->request->getPost('comment') ?: '');

        if (empty($name) || empty($members)) {
            return redirect()->back()->with('error', 'Group name and at least one member service are required.');
        }

        $exists = $db->table('firewall_service_groups')->where('name', $name)->countAllResults();
        if ($exists > 0) {
            return redirect()->back()->with('error', "Service group '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('firewall_service_groups')->insert([
            'id'         => 'sg_' . bin2hex(random_bytes(6)),
            'name'       => $name,
            'members'    => json_encode(array_values($members)),
            'comment'    => $comment,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->back()
            ->with('success', "Service group '{$name}' created.");
    }

    public function deleteServiceGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $group = $db->table('firewall_service_groups')->where('id', $id)->get()->getRowArray();
        if ($group) {
            $db->table('firewall_service_groups')->where('id', $id)->delete();
            return redirect()->back()
                ->with('success', "Service group '{$group['name']}' deleted.");
        }
        return redirect()->back()->with('error', 'Service group not found.');
    }

    // ========================================================
    // Legacy Rule Fallbacks & Atomic Operations
    // ========================================================

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        // If the caller passed policy parameters, route to storePolicy
        if ($this->request->getPost('src_interface') || $this->request->getPost('sources')) {
            return $this->storePolicy();
        }

        $db = \Config\Database::connect();

        $rules = [
            'interface_id' => 'required',
            'action'       => 'required',
            'direction'    => 'required',
            'protocol'     => 'required',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()
                ->with('error', implode(' ', $this->validator->getErrors()));
        }

        $ifaceId = $this->request->getPost('interface_id');
        $maxSeq = $db->table('firewall_rules')
                     ->selectMax('sequence')
                     ->get()
                     ->getRow()
                     ->sequence ?? 0;

        $ruleId = 'rule_' . bin2hex(random_bytes(8));
        $now    = date('Y-m-d H:i:s');

        $db->table('firewall_rules')->insert([
            'id'           => $ruleId,
            'name'         => $this->request->getPost('description') ?: ('Rule #' . ($maxSeq + 1)),
            'sequence'     => $maxSeq + 1,
            'interface_id' => $ifaceId,
            'src_interface'=> $ifaceId,
            'dst_interface'=> 'wan',
            'action'       => $this->request->getPost('action'),
            'direction'    => $this->request->getPost('direction'),
            'protocol'     => $this->request->getPost('protocol'),
            'source_type'  => $this->request->getPost('source_type') ?: 'any',
            'source_value' => trim($this->request->getPost('source_value') ?: 'any'),
            'source_port'  => trim($this->request->getPost('source_port') ?: null),
            'dest_type'    => $this->request->getPost('dest_type') ?: 'any',
            'dest_value'   => trim($this->request->getPost('dest_value') ?: 'any'),
            'dest_port'    => trim($this->request->getPost('dest_port') ?: null),
            'sources'      => json_encode(['all']),
            'destinations' => json_encode(['all']),
            'services'     => json_encode(['ALL']),
            'log_traffic'  => $this->request->getPost('log_enabled') ? 'all' : 'none',
            'enabled'      => $this->request->getPost('enabled') ? 1 : 0,
            'description'  => trim($this->request->getPost('description') ?: ''),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return redirect()->to(base_url('firewall/rules?if=' . urlencode($ifaceId)))
            ->with('success', 'Firewall rule created. Click Apply Changes to reload packet filter.');
    }

    public function delete(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $rule = $db->table('firewall_rules')->where('id', $id)->get()->getRowArray();

        if ($rule) {
            $db->table('firewall_rules')->where('id', $id)->delete();
            $this->compactSequences($db);
            return redirect()->to(base_url('firewall/rules'))
                ->with('success', 'Firewall rule / policy deleted.');
        }

        return redirect()->to(base_url('firewall/rules'))->with('error', 'Rule not found.');
    }

    public function update(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        if ($this->request->getPost('src_interface') || $this->request->getPost('sources')) {
            return $this->updatePolicy($id);
        }

        $db = \Config\Database::connect();
        $rule = $db->table('firewall_rules')->where('id', $id)->get()->getRowArray();
        if (! $rule) {
            return redirect()->to(base_url('firewall/rules'))->with('error', 'Rule not found.');
        }

        $data = [
            'action'       => $this->request->getPost('action') ?: $rule['action'],
            'direction'    => $this->request->getPost('direction') ?: $rule['direction'],
            'protocol'     => $this->request->getPost('protocol') ?: $rule['protocol'],
            'source_type'  => $this->request->getPost('source_type') ?: 'any',
            'source_value' => trim($this->request->getPost('source_value') ?: 'any'),
            'source_port'  => trim($this->request->getPost('source_port') ?: '') ?: null,
            'dest_type'    => $this->request->getPost('dest_type') ?: 'any',
            'dest_value'   => trim($this->request->getPost('dest_value') ?: 'any'),
            'dest_port'    => trim($this->request->getPost('dest_port') ?: '') ?: null,
            'enabled'      => $this->request->getPost('enabled') !== null ? (int)$this->request->getPost('enabled') : 1,
            'description'  => trim($this->request->getPost('description') ?: ''),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $db->table('firewall_rules')->where('id', $id)->update($data);

        return redirect()->to(base_url('firewall/rules'))
            ->with('success', 'Firewall rule updated.');
    }

    public function toggle(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $rule = $db->table('firewall_rules')->where('id', $id)->get()->getRowArray();
        if ($rule) {
            $newStatus = ((int)$rule['enabled'] === 1) ? 0 : 1;
            $db->table('firewall_rules')->where('id', $id)->update(['enabled' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')]);
            $statusText = $newStatus ? 'enabled' : 'disabled';
            return redirect()->to(base_url('firewall/rules'))
                ->with('success', "Policy '{$rule['name']}' {$statusText}. Remember to Apply Changes.");
        }
        return redirect()->to(base_url('firewall/rules'))->with('error', 'Rule not found.');
    }

    public function apply(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            // Compile the live configuration and hand the ruleset to the daemon,
            // which syntax-checks it and loads it atomically. Calling the daemon
            // without a ruleset would apply nothing.
            $ruleset = (new NftablesCompiler())->compile();

            $resp = $this->ipc->call('firewall.apply', [
                'table'     => 'filter',
                'ruleset'   => $ruleset,
                'safe_mode' => true,
            ]);

            if (! empty($resp['error'])) {
                return redirect()->to(base_url('firewall/rules'))
                    ->with('error', 'Failed to apply ruleset: ' . ($resp['error']['message'] ?? 'daemon error'));
            }

            $result = $resp['result'] ?? [];
            $status = $result['status'] ?? 'applied';

            if (in_array($status, ['syntax_error', 'apply_failed', 'error'], true)) {
                return redirect()->to(base_url('firewall/rules'))
                    ->with('error', 'Ruleset rejected by the dataplane: ' . ($result['error'] ?? $result['message'] ?? $status));
            }

            $msg = $result['message'] ?? 'nftables ruleset compiled and active.';
            return redirect()->to(base_url('firewall/rules'))
                ->with('success', $msg . ' (' . strlen($ruleset) . ' bytes' . ($this->ipc->isMock() ? ', simulated' : '') . ')');
        } catch (\Throwable $e) {
            return redirect()->to(base_url('firewall/rules'))
                ->with('error', 'Failed to apply ruleset: ' . $e->getMessage());
        }
    }

    /**
     * Persist a new policy evaluation order after a drag & drop.
     *
     * The client sends the visible policies in their new order. Rather than
     * renumbering 1..N blindly (which would clash with policies hidden by an
     * interface filter), the supplied policies are dropped into the exact set
     * of sequence slots they already occupied, and only then is the whole list
     * renumbered. Policies outside the supplied set keep their relative order.
     */
    public function reorder(): \CodeIgniter\HTTP\ResponseInterface
    {
        $order = array_values(array_filter(array_map('strval', (array) ($this->request->getPost('order') ?? [])), 'strlen'));
        $db = \Config\Database::connect();

        if ($order === []) {
            return $this->response->setJSON(['success' => false, 'error' => 'No policy order was supplied.']);
        }

        $rows = $db->table('firewall_rules')->select('id, sequence')
                   ->orderBy('sequence', 'ASC')->orderBy('id', 'ASC')
                   ->get()->getResultArray();
        $ids = array_column($rows, 'id');

        if ($ids === []) {
            return $this->response->setJSON(['success' => false, 'error' => 'There are no policies to reorder.']);
        }

        foreach ($order as $id) {
            if (! in_array($id, $ids, true)) {
                return $this->response->setJSON(['success' => false, 'error' => 'Unknown policy: ' . $id]);
            }
        }

        // De-duplicate while preserving the dragged order
        $order = array_values(array_unique($order));

        // Slots (global positions) the supplied policies currently occupy
        $slots = [];
        foreach ($ids as $index => $id) {
            if (in_array($id, $order, true)) {
                $slots[] = $index;
            }
        }

        if (count($slots) !== count($order)) {
            return $this->response->setJSON(['success' => false, 'error' => 'The reorder list is incomplete.']);
        }

        // Drop the policies back into those slots, in the new order
        $reordered = $ids;
        foreach ($slots as $i => $slot) {
            $reordered[$slot] = $order[$i];
        }

        $db->transStart();
        $sequence = [];
        foreach ($reordered as $i => $id) {
            $sequence[$id] = $i + 1;
            $db->table('firewall_rules')->where('id', $id)->update([
                'sequence'   => $i + 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $db->transComplete();

        return $this->response->setJSON([
            'success'  => true,
            'count'    => count($reordered),
            'sequence' => $sequence,
        ]);
    }

    /**
     * One page of address objects / groups for the object pickers.
     *
     * The policy dialog and the policy-routing dialog both search the same
     * objects, and both used to render every one of them into the form and
     * filter in the DOM — fine for a dozen objects, thousands of `<option>`
     * nodes on a real install. This returns a single page instead, so the
     * dialog renders a bounded list however many objects exist.
     *
     * Reached through two routes (`firewall/objects/search` and
     * `routing/objects/search`) so each screen is covered by its own permission:
     * a routing operator does not need firewall-rule rights to pick the object a
     * PBR rule matches on.
     */
    public function searchObjects(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setJSON(ObjectSearch::search([
            'kinds'   => $this->request->getGet('kinds'),
            'q'       => (string) $this->request->getGet('q'),
            'page'    => $this->request->getGet('page'),
            'per'     => $this->request->getGet('per'),
            'include' => $this->request->getGet('include'),
        ]));
    }

    public function nftablesPreview(): \CodeIgniter\HTTP\ResponseInterface
    {
        $compiler = new NftablesCompiler();
        $ruleset  = $compiler->compile();

        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="nftables.conf"')
            ->setBody($ruleset);
    }

    // ========================================================
    // Dedicated Views for Sidebar
    // ========================================================

    public function addresses(): string
    {
        $db = \Config\Database::connect();
        $addresses = $db->table('firewall_addresses')->orderBy('name', 'ASC')->get()->getResultArray();
        $interfaces = $db->table('interfaces')->orderBy('name', 'ASC')->get()->getResultArray();

        $resolver = new \App\Libraries\ResolveObjects($db);

        return view('firewall/addresses', [
            'title'      => 'Firewall Addresses & FQDNs — MicronOS',
            'breadcrumb' => 'Firewall Addresses',
            'addresses'  => $addresses,
            'interfaces' => $interfaces,
            'freshness'  => $resolver->freshnessMap($addresses),
        ]);
    }

    /**
     * Resolves every non-literal address object into an offline CIDR feed —
     * the same engine `spark micron:resolve-objects` uses.
     */
    public function resolveAddresses(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $summary = (new \App\Libraries\ResolveObjects($db))->run();

        if ($summary['processed'] === 0) {
            return redirect()->back()->with('info', 'No FQDN, internet-service or geography objects to resolve.');
        }

        $message = sprintf(
            'Address feed refresh complete: %d resolved, %d unresolvable%s.',
            $summary['stored'],
            $summary['unresolvable'],
            $summary['failed'] > 0 ? ", {$summary['failed']} failed" : '',
        );

        return redirect()->back()->with($summary['failed'] > 0 ? 'error' : 'success', $message);
    }

    public function addressGroups(): string
    {
        $db = \Config\Database::connect();
        $addressGroups = $db->table('firewall_address_groups')->orderBy('name', 'ASC')->get()->getResultArray();
        $addresses = $db->table('firewall_addresses')->orderBy('name', 'ASC')->get()->getResultArray();

        return view('firewall/address_groups', [
            'title'         => 'Firewall Address Groups — MicronOS',
            'breadcrumb'    => 'Address Groups',
            'addressGroups' => $addressGroups,
            'addresses'     => $addresses,
        ]);
    }

    public function services(): string
    {
        $db = \Config\Database::connect();
        $services = $db->table('firewall_services')->orderBy('category', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();

        return view('firewall/services', [
            'title'      => 'Firewall Services & Ports — MicronOS',
            'breadcrumb' => 'Services & Ports',
            'services'   => $services,
        ]);
    }

    public function serviceGroups(): string
    {
        $db = \Config\Database::connect();
        $serviceGroups = $db->table('firewall_service_groups')->orderBy('name', 'ASC')->get()->getResultArray();
        $services = $db->table('firewall_services')->orderBy('name', 'ASC')->get()->getResultArray();

        return view('firewall/service_groups', [
            'title'         => 'Firewall Service Groups — MicronOS',
            'breadcrumb'    => 'Service Groups',
            'serviceGroups' => $serviceGroups,
            'services'      => $services,
        ]);
    }

    // ========================================================
    // CSV Helpers
    // ========================================================

    private function streamCsv(string $filename, array $headers, array $rows): \CodeIgniter\HTTP\ResponseInterface
    {
        $fp = fopen('php://temp', 'r+');
        fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        $csvData = stream_get_contents($fp);
        fclose($fp);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Expires', '0')
            ->setBody($csvData);
    }

    private function parseUploadedCsv(): ?array
    {
        $file = $this->request->getFile('csv_file');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return null;
        }

        $path = $file->getTempName();
        $handle = fopen($path, 'r');
        if (!$handle) {
            return null;
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return null;
        }

        $cleanHeaders = array_map(fn($h) => strtolower(trim((string)$h, " \t\n\r\0\x0B\"'")), $header);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (empty($data) || (count($data) === 1 && $data[0] === null)) {
                continue;
            }
            $row = [];
            foreach ($cleanHeaders as $idx => $key) {
                $row[$key] = isset($data[$idx]) ? trim((string)$data[$idx]) : '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    // ========================================================
    // Rules CSV Export & Import
    // ========================================================

    public function exportRulesCsv(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $rules = $db->table('firewall_rules')->orderBy('sequence', 'ASC')->get()->getResultArray();

        $headers = [
            'name', 'src_interface', 'dst_interface', 'sources', 'destinations', 
            'services', 'action', 'nat_enabled', 'schedule', 'log_traffic', 
            'security_profiles', 'enabled', 'description'
        ];

        $rows = [];
        foreach ($rules as $r) {
            $sources = $r['sources'];
            if ($decoded = json_decode((string)$sources, true)) {
                $sources = implode(', ', $decoded);
            }
            $destinations = $r['destinations'];
            if ($decoded = json_decode((string)$destinations, true)) {
                $destinations = implode(', ', $decoded);
            }
            $services = $r['services'];
            if ($decoded = json_decode((string)$services, true)) {
                $services = implode(', ', $decoded);
            }
            $secProfiles = $r['security_profiles'];
            if ($decoded = json_decode((string)$secProfiles, true)) {
                $secProfiles = implode(', ', $decoded);
            }

            $rows[] = [
                $r['name'] ?? ('Policy #' . $r['id']),
                $r['src_interface'] ?? ($r['interface_id'] ?? 'lan'),
                $r['dst_interface'] ?? 'wan',
                $sources ?: 'all',
                $destinations ?: 'all',
                $services ?: 'ALL',
                strtolower($r['action'] ?? 'accept'),
                (int)($r['nat_enabled'] ?? 1),
                $r['schedule'] ?? 'always',
                $r['log_traffic'] ?? 'all',
                $secProfiles ?: '',
                (int)($r['enabled'] ?? 1),
                $r['description'] ?? '',
            ];
        }

        return $this->streamCsv('firewall_rules_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    public function importRulesCsv(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rows = $this->parseUploadedCsv();
        if ($rows === null) {
            return redirect()->back()->with('error', 'Please upload a valid CSV file.');
        }

        $db = \Config\Database::connect();
        $mode = $this->request->getPost('mode') ?: 'update';
        $maxSeq = $db->table('firewall_rules')->selectMax('sequence')->get()->getRow()->sequence ?? 0;
        $now = date('Y-m-d H:i:s');
        $imported = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            if (empty($name)) {
                continue;
            }

            $srcIface = !empty($row['src_interface']) ? $row['src_interface'] : 'lan';
            $dstIface = !empty($row['dst_interface']) ? $row['dst_interface'] : 'wan';

            // Parse sources
            $srcVal = trim($row['sources'] ?? 'all');
            $sources = array_filter(array_map('trim', explode(',', str_replace(';', ',', $srcVal))));
            if (empty($sources)) $sources = ['all'];

            // Parse destinations
            $dstVal = trim($row['destinations'] ?? 'all');
            $destinations = array_filter(array_map('trim', explode(',', str_replace(';', ',', $dstVal))));
            if (empty($destinations)) $destinations = ['all'];

            // Parse services
            $svcVal = trim($row['services'] ?? 'ALL');
            $services = array_filter(array_map('trim', explode(',', str_replace(';', ',', $svcVal))));
            if (empty($services)) $services = ['ALL'];

            // Action
            $action = in_array(strtolower($row['action'] ?? 'accept'), ['deny', 'reject', 'block']) ? 'deny' : 'accept';

            // NAT
            $natRaw = strtolower(trim($row['nat_enabled'] ?? '1'));
            $natEnabled = in_array($natRaw, ['1', 'true', 'yes', 'on']) ? 1 : 0;

            // Profiles
            $secRaw = trim($row['security_profiles'] ?? '');
            $secProfiles = array_filter(array_map('trim', explode(',', str_replace(';', ',', $secRaw))));

            // Enabled
            $enRaw = strtolower(trim($row['enabled'] ?? '1'));
            $enabled = in_array($enRaw, ['0', 'false', 'no', 'off']) ? 0 : 1;

            $record = [
                'name'              => $name,
                'interface_id'      => $srcIface,
                'src_interface'     => $srcIface,
                'dst_interface'     => $dstIface,
                'sources'           => json_encode(array_values($sources)),
                'destinations'      => json_encode(array_values($destinations)),
                'services'          => json_encode(array_values($services)),
                'action'            => $action,
                'direction'         => 'in',
                'protocol'          => 'any',
                'source_type'       => 'any',
                'source_value'      => 'any',
                'dest_type'         => 'any',
                'dest_value'        => 'any',
                'nat_enabled'       => $natEnabled,
                'schedule'          => trim($row['schedule'] ?? '') ?: 'always',
                'log_traffic'       => trim($row['log_traffic'] ?? '') ?: 'all',
                'security_profiles' => !empty($secProfiles) ? json_encode(array_values($secProfiles)) : null,
                'enabled'           => $enabled,
                'description'       => trim($row['description'] ?? ''),
                'updated_at'        => $now,
            ];

            $existing = $db->table('firewall_rules')->where('name', $name)->get()->getRowArray();
            if ($existing) {
                if ($mode === 'update') {
                    $db->table('firewall_rules')->where('id', $existing['id'])->update($record);
                    $updated++;
                }
            } else {
                $maxSeq++;
                $record['id'] = 'pol_' . bin2hex(random_bytes(6));
                $record['sequence'] = $maxSeq;
                $record['hit_count'] = 0;
                $record['bytes'] = 0;
                $record['created_at'] = $now;
                $db->table('firewall_rules')->insert($record);
                $imported++;
            }
        }

        return redirect()->to(base_url('firewall/rules'))
            ->with('success', "Import complete: {$imported} policies created, {$updated} policies updated.");
    }

    // ========================================================
    // Addresses Update, Export & Import
    // ========================================================

    /**
     * Validate a Geography (country) address value. Returns an error message,
     * or null when the value is acceptable.
     */
    private function validateGeography(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Select at least one country for a Geography address object.';
        }

        $bad = GeoIp::invalidCodes($value);
        if ($bad !== []) {
            return 'Unknown country code(s): ' . implode(', ', $bad) . '. Use ISO 3166-1 alpha-2 codes such as US, DE, SG.';
        }

        if (GeoIp::codes($value) === []) {
            return 'Select at least one country for a Geography address object.';
        }

        return null;
    }

    public function updateAddress(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $addr = $db->table('firewall_addresses')->where('id', $id)->get()->getRowArray();
        if (!$addr) {
            return redirect()->back()->with('error', 'Address not found.');
        }

        $name = trim($this->request->getPost('name') ?: $addr['name']);
        $type = $this->request->getPost('type') ?: $addr['type'];
        $value = trim($this->request->getPost('value') ?: $addr['value']);
        $interface = $this->request->getPost('interface') ?: $addr['interface'];
        $color = $this->request->getPost('color') ?: ($addr['color'] ?? '#3b82f6');
        $comment = trim($this->request->getPost('comment') ?? ($addr['comment'] ?? ''));

        if ($name !== $addr['name']) {
            $exists = $db->table('firewall_addresses')->where('name', $name)->countAllResults();
            if ($exists > 0) {
                return redirect()->back()->with('error', "An address named '{$name}' already exists.");
            }
        }

        if ($type === 'geoip') {
            if ($geoError = $this->validateGeography($value)) {
                return redirect()->back()->with('error', $geoError);
            }
            $value = GeoIp::normalise($value);
        } elseif ($type === 'fqdn') {
            if (! Fqdn::isValidList($value)) {
                return redirect()->back()->with('error', 'Enter one or more valid domains (wildcards such as *.example.com are allowed).');
            }
            $value = implode(', ', Fqdn::split($value));
        } elseif ($type === 'internet-service') {
            if (InternetService::keys($value) === []) {
                return redirect()->back()->with('error', 'Select at least one Internet Service Database entry.');
            }
            $value = InternetService::normalise($value);
        }

        $db->table('firewall_addresses')->where('id', $id)->update([
            'name'       => $name,
            'type'       => $type,
            'value'      => $value,
            'interface'  => $interface,
            'color'      => $color,
            'comment'    => $comment,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()
            ->with('success', "Address object '{$name}' updated.");
    }

    public function exportAddressesCsv(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $addresses = $db->table('firewall_addresses')->orderBy('name', 'ASC')->get()->getResultArray();

        $headers = ['name', 'type', 'value', 'interface', 'color', 'comment'];
        $rows = [];
        foreach ($addresses as $a) {
            $rows[] = [
                $a['name'],
                $a['type'],
                $a['value'],
                $a['interface'] ?? 'any',
                $a['color'] ?? '#3b82f6',
                $a['comment'] ?? '',
            ];
        }

        return $this->streamCsv('firewall_addresses_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    public function importAddressesCsv(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rows = $this->parseUploadedCsv();
        if ($rows === null) {
            return redirect()->back()->with('error', 'Please upload a valid CSV file.');
        }

        $db = \Config\Database::connect();
        $mode = $this->request->getPost('mode') ?: 'update';
        $now = date('Y-m-d H:i:s');
        $imported = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            $val = trim($row['value'] ?? '');
            if (empty($name) || empty($val)) {
                continue;
            }

            $type = in_array($row['type'] ?? '', ['ipmask', 'iprange', 'fqdn', 'internet-service', 'geoip']) ? $row['type'] : 'ipmask';
            $interface = trim($row['interface'] ?? '') ?: 'any';
            $color = trim($row['color'] ?? '') ?: '#3b82f6';
            $comment = trim($row['comment'] ?? '');

            $existing = $db->table('firewall_addresses')->where('name', $name)->get()->getRowArray();
            if ($existing) {
                if ($mode === 'update' && empty($existing['is_system'])) {
                    $db->table('firewall_addresses')->where('id', $existing['id'])->update([
                        'type'       => $type,
                        'value'      => $val,
                        'interface'  => $interface,
                        'color'      => $color,
                        'comment'    => $comment,
                        'updated_at' => $now,
                    ]);
                    $updated++;
                }
            } else {
                $db->table('firewall_addresses')->insert([
                    'id'         => 'addr_' . bin2hex(random_bytes(6)),
                    'name'       => $name,
                    'type'       => $type,
                    'value'      => $val,
                    'interface'  => $interface,
                    'color'      => $color,
                    'comment'    => $comment,
                    'is_system'  => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $imported++;
            }
        }

        return redirect()->back()
            ->with('success', "Import complete: {$imported} addresses created, {$updated} addresses updated.");
    }

    // ========================================================
    // Address Groups Update, Export & Import
    // ========================================================

    public function updateAddressGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $group = $db->table('firewall_address_groups')->where('id', $id)->get()->getRowArray();
        if (!$group) {
            return redirect()->back()->with('error', 'Address group not found.');
        }

        $name = trim($this->request->getPost('name') ?: $group['name']);
        $members = (array) ($this->request->getPost('members') ?? []);
        $color = $this->request->getPost('color') ?: ($group['color'] ?? '#6366f1');
        $comment = trim($this->request->getPost('comment') ?? ($group['comment'] ?? ''));

        if (empty($name) || empty($members)) {
            return redirect()->back()->with('error', 'Group name and at least one member address are required.');
        }

        if ($name !== $group['name']) {
            $exists = $db->table('firewall_address_groups')->where('name', $name)->countAllResults();
            if ($exists > 0) {
                return redirect()->back()->with('error', "An address group named '{$name}' already exists.");
            }
        }

        $db->table('firewall_address_groups')->where('id', $id)->update([
            'name'       => $name,
            'members'    => json_encode(array_values($members)),
            'color'      => $color,
            'comment'    => $comment,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()
            ->with('success', "Address group '{$name}' updated.");
    }

    public function exportAddressGroupsCsv(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $groups = $db->table('firewall_address_groups')->orderBy('name', 'ASC')->get()->getResultArray();

        $headers = ['name', 'members', 'color', 'comment'];
        $rows = [];
        foreach ($groups as $g) {
            $members = $g['members'];
            if ($decoded = json_decode((string)$members, true)) {
                $members = implode(', ', $decoded);
            }
            $rows[] = [
                $g['name'],
                $members,
                $g['color'] ?? '#6366f1',
                $g['comment'] ?? '',
            ];
        }

        return $this->streamCsv('firewall_address_groups_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    public function importAddressGroupsCsv(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rows = $this->parseUploadedCsv();
        if ($rows === null) {
            return redirect()->back()->with('error', 'Please upload a valid CSV file.');
        }

        $db = \Config\Database::connect();
        $mode = $this->request->getPost('mode') ?: 'update';
        $now = date('Y-m-d H:i:s');
        $imported = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            $membersRaw = trim($row['members'] ?? '');
            if (empty($name) || empty($membersRaw)) {
                continue;
            }

            $members = array_filter(array_map('trim', explode(',', str_replace(';', ',', $membersRaw))));
            if (empty($members)) {
                continue;
            }

            $color = trim($row['color'] ?? '') ?: '#6366f1';
            $comment = trim($row['comment'] ?? '');

            $existing = $db->table('firewall_address_groups')->where('name', $name)->get()->getRowArray();
            if ($existing) {
                if ($mode === 'update') {
                    $db->table('firewall_address_groups')->where('id', $existing['id'])->update([
                        'members'    => json_encode(array_values($members)),
                        'color'      => $color,
                        'comment'    => $comment,
                        'updated_at' => $now,
                    ]);
                    $updated++;
                }
            } else {
                $db->table('firewall_address_groups')->insert([
                    'id'         => 'ag_' . bin2hex(random_bytes(6)),
                    'name'       => $name,
                    'members'    => json_encode(array_values($members)),
                    'color'      => $color,
                    'comment'    => $comment,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $imported++;
            }
        }

        return redirect()->back()
            ->with('success', "Import complete: {$imported} address groups created, {$updated} address groups updated.");
    }

    // ========================================================
    // Services Update, Export & Import
    // ========================================================

    public function updateService(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $svc = $db->table('firewall_services')->where('id', $id)->get()->getRowArray();
        if (!$svc) {
            return redirect()->back()->with('error', 'Service not found.');
        }

        $name = strtoupper(trim($this->request->getPost('name') ?: $svc['name']));
        $protocol = $this->request->getPost('protocol') ?: $svc['protocol'];
        $tcpPort = trim($this->request->getPost('tcp_port') ?: '') ?: null;
        $udpPort = trim($this->request->getPost('udp_port') ?: '') ?: null;
        $category = trim($this->request->getPost('category') ?: ($svc['category'] ?? 'General'));
        $comment = trim($this->request->getPost('comment') ?? ($svc['comment'] ?? ''));

        if (empty($name)) {
            return redirect()->back()->with('error', 'Service name is required.');
        }

        if ($name !== $svc['name']) {
            $exists = $db->table('firewall_services')->where('name', $name)->countAllResults();
            if ($exists > 0) {
                return redirect()->back()->with('error', "Service '{$name}' already exists.");
            }
        }

        $db->table('firewall_services')->where('id', $id)->update([
            'name'       => $name,
            'protocol'   => $protocol,
            'tcp_port'   => $tcpPort,
            'udp_port'   => $udpPort,
            'category'   => $category,
            'comment'    => $comment,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()
            ->with('success', "Service '{$name}' updated.");
    }

    public function exportServicesCsv(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $services = $db->table('firewall_services')->orderBy('category', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();

        $headers = ['name', 'protocol', 'tcp_port', 'udp_port', 'category', 'comment'];
        $rows = [];
        foreach ($services as $s) {
            $rows[] = [
                $s['name'],
                $s['protocol'],
                $s['tcp_port'] ?? '',
                $s['udp_port'] ?? '',
                $s['category'] ?? 'General',
                $s['comment'] ?? '',
            ];
        }

        return $this->streamCsv('firewall_services_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    public function importServicesCsv(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rows = $this->parseUploadedCsv();
        if ($rows === null) {
            return redirect()->back()->with('error', 'Please upload a valid CSV file.');
        }

        $db = \Config\Database::connect();
        $mode = $this->request->getPost('mode') ?: 'update';
        $now = date('Y-m-d H:i:s');
        $imported = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $name = strtoupper(trim($row['name'] ?? ''));
            if (empty($name)) {
                continue;
            }

            $protocol = strtoupper(trim($row['protocol'] ?? 'TCP')) ?: 'TCP';
            $tcpPort = trim($row['tcp_port'] ?? '') ?: null;
            $udpPort = trim($row['udp_port'] ?? '') ?: null;
            $category = trim($row['category'] ?? '') ?: 'General';
            $comment = trim($row['comment'] ?? '');

            $existing = $db->table('firewall_services')->where('name', $name)->get()->getRowArray();
            if ($existing) {
                if ($mode === 'update' && empty($existing['is_system'])) {
                    $db->table('firewall_services')->where('id', $existing['id'])->update([
                        'protocol'   => $protocol,
                        'tcp_port'   => $tcpPort,
                        'udp_port'   => $udpPort,
                        'category'   => $category,
                        'comment'    => $comment,
                        'updated_at' => $now,
                    ]);
                    $updated++;
                }
            } else {
                $db->table('firewall_services')->insert([
                    'id'         => 'svc_' . bin2hex(random_bytes(6)),
                    'name'       => $name,
                    'protocol'   => $protocol,
                    'tcp_port'   => $tcpPort,
                    'udp_port'   => $udpPort,
                    'category'   => $category,
                    'is_system'  => 0,
                    'comment'    => $comment,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $imported++;
            }
        }

        return redirect()->back()
            ->with('success', "Import complete: {$imported} services created, {$updated} services updated.");
    }

    // ========================================================
    // Service Groups Update, Export & Import
    // ========================================================

    public function updateServiceGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $group = $db->table('firewall_service_groups')->where('id', $id)->get()->getRowArray();
        if (!$group) {
            return redirect()->back()->with('error', 'Service group not found.');
        }

        $name = trim($this->request->getPost('name') ?: $group['name']);
        $members = (array) ($this->request->getPost('members') ?? []);
        $comment = trim($this->request->getPost('comment') ?? ($group['comment'] ?? ''));

        if (empty($name) || empty($members)) {
            return redirect()->back()->with('error', 'Group name and at least one member service are required.');
        }

        if ($name !== $group['name']) {
            $exists = $db->table('firewall_service_groups')->where('name', $name)->countAllResults();
            if ($exists > 0) {
                return redirect()->back()->with('error', "Service group '{$name}' already exists.");
            }
        }

        $db->table('firewall_service_groups')->where('id', $id)->update([
            'name'       => $name,
            'members'    => json_encode(array_values($members)),
            'comment'    => $comment,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()
            ->with('success', "Service group '{$name}' updated.");
    }

    public function exportServiceGroupsCsv(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $groups = $db->table('firewall_service_groups')->orderBy('name', 'ASC')->get()->getResultArray();

        $headers = ['name', 'members', 'comment'];
        $rows = [];
        foreach ($groups as $g) {
            $members = $g['members'];
            if ($decoded = json_decode((string)$members, true)) {
                $members = implode(', ', $decoded);
            }
            $rows[] = [
                $g['name'],
                $members,
                $g['comment'] ?? '',
            ];
        }

        return $this->streamCsv('firewall_service_groups_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    public function importServiceGroupsCsv(): \CodeIgniter\HTTP\RedirectResponse
    {
        $rows = $this->parseUploadedCsv();
        if ($rows === null) {
            return redirect()->back()->with('error', 'Please upload a valid CSV file.');
        }

        $db = \Config\Database::connect();
        $mode = $this->request->getPost('mode') ?: 'update';
        $now = date('Y-m-d H:i:s');
        $imported = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            $membersRaw = trim($row['members'] ?? '');
            if (empty($name) || empty($membersRaw)) {
                continue;
            }

            $members = array_filter(array_map('trim', explode(',', str_replace(';', ',', $membersRaw))));
            if (empty($members)) {
                continue;
            }

            $comment = trim($row['comment'] ?? '');

            $existing = $db->table('firewall_service_groups')->where('name', $name)->get()->getRowArray();
            if ($existing) {
                if ($mode === 'update') {
                    $db->table('firewall_service_groups')->where('id', $existing['id'])->update([
                        'members'    => json_encode(array_values($members)),
                        'comment'    => $comment,
                        'updated_at' => $now,
                    ]);
                    $updated++;
                }
            } else {
                $db->table('firewall_service_groups')->insert([
                    'id'         => 'sg_' . bin2hex(random_bytes(6)),
                    'name'       => $name,
                    'members'    => json_encode(array_values($members)),
                    'comment'    => $comment,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $imported++;
            }
        }

        return redirect()->back()
            ->with('success', "Import complete: {$imported} service groups created, {$updated} service groups updated.");
    }
}

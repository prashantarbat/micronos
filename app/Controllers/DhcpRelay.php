<?php

namespace App\Controllers;

use App\Libraries\DhcpRelayCompiler;
use App\Libraries\IpcClient;
use App\Models\InterfaceModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * DhcpRelay — Services → DHCP Relay.
 *
 * A relay forwards DHCP/BOOTP broadcasts from an interface to a DHCP server on
 * another segment, for networks the server cannot hear directly. Destinations
 * are reusable named server lists; each relayed interface points at one.
 *
 * The page is a thin front end over {@see DhcpRelayCompiler} and the daemon: the
 * install plan and the UDP/67 conflict check are computed by the compiler, and
 * nothing is applied until `apply` dispatches `dhcp.relay_apply`.
 */
class DhcpRelay extends BaseController
{
    private IpcClient $ipc;

    private InterfaceModel $interfaceModel;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc            = \Config\Services::ipc();
        $this->interfaceModel = new InterfaceModel();
    }

    public function index(): string
    {
        $db = \Config\Database::connect();

        $destinations = $db->table('dhcp_relay_destinations')->orderBy('name', 'ASC')->get()->getResultArray();
        $relays       = $db->table('dhcp_relay_interfaces')->orderBy('interface_id', 'ASC')->get()->getResultArray();

        $destById = [];
        foreach ($destinations as $d) {
            $destById[$d['id']] = $d;
        }
        foreach ($relays as &$relay) {
            $relay['destination_name'] = $destById[$relay['destination_id']]['name'] ?? null;
        }
        unset($relay);

        $interfaces = $this->interfaceModel->findAll();
        if ($interfaces === []) {
            $interfaces = [
                ['id' => 'lan', 'name' => 'LAN', 'physical_device' => 'eth1'],
                ['id' => 'dmz', 'name' => 'DMZ', 'physical_device' => 'eth2'],
            ];
        }

        $plan = (new DhcpRelayCompiler())->compile();

        return view('dhcp/relay', [
            'title'        => 'DHCP Relay — MicronOS',
            'breadcrumb'   => 'DHCP Relay',
            'destinations' => $destinations,
            'relays'       => $relays,
            'interfaces'   => $interfaces,
            'plan'         => $plan,
            'relayStates'  => $this->relayStates(),
            'isMock'       => $this->ipc->isMock(),
        ]);
    }

    public function storeDestination(): RedirectResponse
    {
        $name    = trim($this->request->getPost('name') ?? '');
        $servers = $this->normaliseServers((string) ($this->request->getPost('servers') ?? ''));

        if ($name === '' || $servers === []) {
            return redirect()->back()->with('error', 'A destination needs a name and at least one valid server IP address.');
        }

        $now = date('Y-m-d H:i:s');
        \Config\Database::connect()->table('dhcp_relay_destinations')->insert([
            'id'         => 'relaydest_' . bin2hex(random_bytes(5)),
            'name'       => $name,
            'servers'    => implode(',', $servers),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->back()->with('success', "Relay destination '{$name}' created.");
    }

    public function deleteDestination(string $id): RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('dhcp_relay_destinations')->where('id', $id)->delete();
        // Relays pointing at it become destination-less and are skipped with a warning.
        $db->table('dhcp_relay_interfaces')->where('destination_id', $id)->update(['destination_id' => null]);

        return redirect()->back()->with('success', 'Relay destination removed.');
    }

    public function storeRelay(): RedirectResponse
    {
        $ifaceId = trim($this->request->getPost('interface_id') ?? '');
        $destId  = trim($this->request->getPost('destination_id') ?? '') ?: null;

        if ($ifaceId === '') {
            return redirect()->back()->with('error', 'Select an interface to relay.');
        }

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('dhcp_relay_interfaces')->where('interface_id', $ifaceId)->get()->getRowArray();
        $data = [
            'interface_id'   => $ifaceId,
            'destination_id' => $destId,
            'enabled'        => 1,
            'updated_at'     => $now,
        ];

        if ($existing) {
            $db->table('dhcp_relay_interfaces')->where('id', $existing['id'])->update($data);
        } else {
            $db->table('dhcp_relay_interfaces')->insert($data + [
                'id'         => 'relayif_' . bin2hex(random_bytes(5)),
                'created_at' => $now,
            ]);
        }

        return redirect()->back()->with('success', "Relay enabled on '{$ifaceId}'.");
    }

    public function toggleRelay(string $id): RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('dhcp_relay_interfaces')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Relay not found.');
        }

        $db->table('dhcp_relay_interfaces')->where('id', $id)->update([
            'enabled'    => ((int) $row['enabled'] === 1) ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return redirect()->back()->with('success', 'Relay status toggled.');
    }

    public function deleteRelay(string $id): RedirectResponse
    {
        \Config\Database::connect()->table('dhcp_relay_interfaces')->where('id', $id)->delete();

        return redirect()->back()->with('success', 'Relay removed.');
    }

    /**
     * Compile and dispatch the relay configuration to the daemon.
     */
    public function apply(): RedirectResponse
    {
        $plan = (new DhcpRelayCompiler())->compile();

        if ($plan['conflicts'] !== []) {
            return redirect()->back()->with('error', 'DHCP relay not applied: ' . implode(' ', $plan['conflicts']));
        }

        $instances = array_map(static fn ($i) => [
            'name'       => $i['slug'],
            'interfaces' => $i['interfaces'],
            'servers'    => $i['servers'],
        ], $plan['instances']);

        $result = $this->ipc->call('dhcp.relay_apply', ['instances' => $instances]);

        [$key, $message] = $this->ipcFlash(
            $result,
            $instances === []
                ? 'DHCP relay disabled; no relays are enabled.'
                : sprintf('DHCP relay applied: %d helper instance(s).', count($instances)),
            'DHCP relay apply'
        );

        return redirect()->back()->with($key, $message);
    }

    /**
     * Bulk run-state lookup for the relay widget's live status LED.
     *
     *   GET dhcp/relay/instances/status?ids=branch,warehouse
     *
     * Returns the same `{ok, services: {id: status}}` shape as the shared
     * service widget, driven by each `dhcp-helper@<slug>.service` unit's real
     * systemd state (via `dhcp.relay_status`). Unknown slugs report "unknown".
     */
    public function status(): ResponseInterface
    {
        $raw = (string) ($this->request->getGet('ids') ?? '');
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== ''));
        $ids = array_slice(array_unique($ids), 0, 25);

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = 'unknown';
        }

        if ($ids !== []) {
            $result = $this->ipc->call('dhcp.relay_status', []);
            foreach ((array) ($result['result']['instances'] ?? []) as $row) {
                $slug = (string) ($row['name'] ?? '');
                if ($slug !== '' && array_key_exists($slug, $map)) {
                    $map[$slug] = (string) ($row['state'] ?? 'unknown');
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'services' => $map]);
    }

    /**
     * JSON start / stop / restart a single compiled relay helper instance.
     *
     * The instance is addressed by its slug — the same value the daemon embeds
     * in the systemd unit (`dhcp-helper@<slug>.service`). The slug is checked
     * against the current compiled plan, so the control can only ever act on a
     * relay this page manages. The response mirrors the shared widget's
     * `/control` contract so the buttons update in place.
     */
    public function control(string $slug): ResponseInterface
    {
        $action = (string) ($this->request->getPost('action') ?? '');
        if ($action === '') {
            $json   = $this->request->getJSON(true) ?: [];
            $action = (string) ($json['action'] ?? '');
        }
        $action = strtolower(trim($action));

        if (! in_array($action, ['start', 'restart', 'stop'], true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'ok'      => false,
                'message' => 'Invalid action.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $known = $this->compiledInstances();
        if (! isset($known[$slug])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => "Relay instance '{$slug}' not found.",
                'csrf'    => csrf_hash(),
            ]);
        }

        $result  = $this->ipc->call('dhcp.relay_service', ['slug' => $slug, 'action' => $action]);
        $outcome = $this->ipcOutcome($result);

        $status = strtolower((string) ($result['result']['state'] ?? ''));
        if (! in_array($status, ['running', 'stopped'], true)) {
            $status = $action === 'stop' ? 'stopped' : 'running';
        }

        $past = ['start' => 'Started', 'stop' => 'Stopped', 'restart' => 'Restarted'][$action];

        return $this->response->setJSON([
            'ok'      => $outcome['ok'],
            'service' => $slug,
            'name'    => $known[$slug],
            // On failure the LED keeps its last known state (no status field).
            'status'  => $outcome['ok'] ? $status : null,
            'message' => $outcome['ok']
                ? "{$past} relay instance '{$slug}'"
                : sprintf('Relay %s failed: %s', $action, $outcome['reason'] ?? 'unknown error'),
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Recent journal lines for one relay helper unit, for the widget's LED
     * click-through (`GET dhcp/relay/instances/{slug}/logs`).
     */
    public function logs(string $slug): ResponseInterface
    {
        $known = $this->compiledInstances();
        if (! isset($known[$slug])) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Relay instance not found']);
        }

        $result = $this->ipc->call('dhcp.relay_logs', ['slug' => $slug, 'lines' => 30]);
        $logs   = trim((string) ($result['result']['logs'] ?? ''));
        if ($logs === '') {
            $logs = sprintf('No journal entries found for dhcp-helper@%s.service.', $slug);
        }

        return $this->response->setJSON([
            'service' => $known[$slug],
            'logs'    => $logs,
        ]);
    }

    /**
     * slug => friendly name for every relay in the current compiled plan.
     *
     * @return array<string, string>
     */
    private function compiledInstances(): array
    {
        $known = [];
        foreach ((new DhcpRelayCompiler())->compile()['instances'] as $instance) {
            $slug = (string) ($instance['slug'] ?? '');
            if ($slug !== '') {
                $known[$slug] = (string) ($instance['name'] ?? $slug);
            }
        }

        return $known;
    }

    /**
     * First-paint run-state for the compiled relay instances, keyed by slug.
     *
     * @return array<string, string>
     */
    private function relayStates(): array
    {
        $states = [];
        $result = $this->ipc->call('dhcp.relay_status', []);
        foreach ((array) ($result['result']['instances'] ?? []) as $row) {
            $slug = (string) ($row['name'] ?? '');
            if ($slug !== '') {
                $states[$slug] = (string) ($row['state'] ?? 'unknown');
            }
        }

        return $states;
    }

    /**
     * @return list<string>
     */
    private function normaliseServers(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $out[] = $candidate;
            }
        }

        return array_values(array_unique($out));
    }
}

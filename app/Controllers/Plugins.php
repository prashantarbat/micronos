<?php

namespace App\Controllers;

use App\Libraries\PackageManager;
use App\Plugins\PluginManifest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Plugins — the extension surface (Plugin Spec §9).
 *
 * Renders the shipped catalogue, and drives install/remove/upgrade through
 * {@see PackageManager}. The page is deliberately a *thin* front end: the
 * install plan and every protected-core refusal are computed by the library and
 * the daemon, not by the browser. The capability-consent dialog only chooses
 * which of the manifest's own capabilities the operator approves.
 *
 * `plan()` is daemon-free, so the catalogue renders even before any install
 * transaction runs.
 */
class Plugins extends BaseController
{
    private const SERVICE_ACTIONS = ['start', 'stop', 'restart'];

    public function index(): string
    {
        $manager   = \Config\Services::packageManager();
        $installed = $this->installedRows();

        $plugins = [];
        $plans   = [];
        foreach ($manager->catalogue() as $entry) {
            $plan      = $manager->plan($entry['id']);
            $row       = $installed[$entry['id']] ?? null;
            $plans[$entry['id']] = $plan;

            $plugins[] = $entry + [
                'description' => (string) ($manager->registry()->get($entry['id'])?->toArray()['description'] ?? ''),
                'plan_ok'     => $plan['ok'],
                'blocked'     => $plan['blocked'],
                // A blocked Class C plugin says which preset repository resolves
                // it, so the page offers a next step instead of a dead end.
                'preset_hint' => $plan['preset_hint'] ?? null,
                'repos'       => $plan['repos'] ?? [],
                'installed'   => $row !== null,
                'enabled'     => (int) ($row['enabled'] ?? 0),
                'state'       => $row['state'] ?? ($plan['ok'] ? 'available' : 'blocked'),
                'installed_at' => $row['installed_at'] ?? null,
            ];
        }

        return view('plugins/index', [
            'title'       => 'Plugins & Packages — MicronOS',
            'breadcrumb'  => 'Plugins & Packages',
            'plugins'     => $plugins,
            'plans'       => $plans,
            'installedCount' => count($installed),
            'pluginStates'   => $this->daemonStates($plugins, $manager),
        ]);
    }

    /**
     * Install a plugin after the operator has approved a set of capabilities.
     *
     * @param string $id plugin id from the route
     */
    public function install(string $id): RedirectResponse
    {
        $manager  = \Config\Services::packageManager();
        $manifest = $manager->registry()->get($id);
        if ($manifest === null) {
            return redirect()->back()->with('error', "Unknown plugin: {$id}.");
        }

        // Class C is the one class whose install needs a second, recorded
        // consent beyond approving capabilities (Plugin Spec §11): the packages
        // come from a third-party repository, the peer registers with a
        // controller outside the appliance, and the overlay changes the routing
        // of live traffic. The checkbox is enforced here, not only in the
        // dialog, so a hand-posted form cannot skip it.
        if ($manifest->class() === 'C' && ! $this->request->getPost('class_c_ack')) {
            return redirect()->back()->with('error', sprintf(
                'Installing %s requires acknowledging the Class C notice: third-party repository, external identity, and changes to live routing.',
                $manifest->name(),
            ));
        }

        $grants = $this->request->getPost('grants');
        $grants = is_array($grants) ? array_values(array_filter($grants, 'is_string')) : [];

        $result = $manager->install($id, $grants);

        if (($result['success'] ?? false) !== true) {
            return redirect()->back()->with('error', $this->failureMessage($id, $result));
        }

        $this->persistInstall($manifest, $grants);

        // darkstat's package ships switched off (START_DARKSTAT=no), so an
        // install alone leaves nothing on its web port. Start it with working
        // defaults — the LAN port, web UI on 666, opened on the LAN only — so
        // the console answers straight away; the settings page can change it.
        if ($id === 'darkstat') {
            $note = $this->darkstatFirstStart($manager);
            if ($note !== null) {
                return redirect()->back()->with('warning', "Plugin '{$manifest->name()}' installed, but " . $note);
            }
        }

        return redirect()->back()->with('success', "Plugin '{$manifest->name()}' installed.");
    }

    public function remove(string $id): RedirectResponse
    {
        $manager = \Config\Services::packageManager();
        $manifest = $manager->registry()->get($id);
        if ($manifest === null) {
            return redirect()->back()->with('error', "Unknown plugin: {$id}.");
        }

        $purge  = (bool) $this->request->getPost('purge');
        $result = $manager->remove($id, $purge);

        if (($result['success'] ?? false) !== true) {
            return redirect()->back()->with('error', $this->failureMessage($id, $result));
        }

        $db = \Config\Database::connect();
        $db->table('plugin_grants')->where('plugin_id', $id)->delete();
        $hadWebPort = $db->tableExists('plugin_settings')
            && $db->table('plugin_settings')->where('plugin_id', $id)->where('setting_key', 'web_port')->countAllResults() > 0;
        $db->table('plugins')->where('id', $id)->delete();
        if ($purge) {
            $db->table('plugin_settings')->where('plugin_id', $id)->delete();
        }
        // A removed plugin's web port is closed: the compiler only opens it
        // while the plugin row exists and is enabled.
        if ($hadWebPort) {
            $this->reapplyFirewallForPlugins();
        }

        return redirect()->back()->with('success', "Plugin '{$manifest->name()}' removed" . ($purge ? ' and its data purged.' : ' (data retained).'));
    }

    public function upgrade(string $id): RedirectResponse
    {
        $manager  = \Config\Services::packageManager();
        $manifest = $manager->registry()->get($id);
        if ($manifest === null) {
            return redirect()->back()->with('error', "Unknown plugin: {$id}.");
        }

        $result = $manager->upgrade($id);
        if (($result['success'] ?? false) !== true) {
            return redirect()->back()->with('error', $this->failureMessage($id, $result));
        }

        // Record the version the operator upgraded *to*: the signed catalogue's
        // when it names a newer one, otherwise the shipped manifest's.
        $this->syncVersionRow($id, $this->targetVersion($id, $manifest->version()));

        return redirect()->back()->with('success', "Plugin '{$manifest->name()}' upgraded.");
    }

    /**
     * A single installed plugin's own page — the target of the nav entries a
     * plugin declares in `provides.menu` (`/plugins/<id>`). Shows the daemon's
     * run-state and controls, its packages/units, the granted capabilities, and
     * the update/remove actions.
     */
    public function show(string $id): string
    {
        $manager  = \Config\Services::packageManager();
        $manifest = $manager->registry()->get($id);
        $row      = $this->installedRows()[$id] ?? null;

        if ($manifest === null || $row === null) {
            throw new \CodeIgniter\Exceptions\PageNotFoundException("Plugin not installed: {$id}");
        }

        $states = [];
        if ($manifest->units() !== []) {
            $result = $manager->status($id);
            if (($result['success'] ?? false) === true && isset($result['state'])) {
                $states[$id] = (string) $result['state'];
            }
        }

        $grants = [];
        $db = \Config\Database::connect();
        if ($db->tableExists('plugin_grants')) {
            $grants = $db->table('plugin_grants')->where('plugin_id', $id)->get()->getResultArray();
        }

        $data = [
            'title'      => $manifest->name() . ' — Plugins — MicronOS',
            'breadcrumb' => 'Plugins: ' . $manifest->name(),
            'plugin'     => [
                'id'           => $manifest->id(),
                'name'         => $manifest->name(),
                'version'      => $manifest->version(),
                'class'        => $manifest->class(),
                'vendor'       => $manifest->vendor(),
                'description'  => $manifest->description(),
                'packages'     => $manifest->packages(),
                'units'        => $manifest->units(),
                'capabilities' => $manifest->capabilities(),
                'menu'         => $manifest->menu(),
            ],
            'installedVersion' => (string) $row['version'],
            'installedAt'      => $row['installed_at'] ?? null,
            'enabled'          => (int) ($row['enabled'] ?? 0),
            'states'           => $states,
            'grants'           => $grants,
        ];

        // A plugin may contribute its own UI surface: when a view exists at
        // Views/plugins/apps/<id>.php it replaces the generic metadata page, so
        // an installed plugin's menu entry lands on something usable (the iperf3
        // speed-test runner, for instance) without special-casing here.
        // Capture/analyser plugins pick from the ports this machine really has.
        if ($id === 'darkstat') {
            $data['nics'] = (new \App\Libraries\NetworkHardware(\Config\Services::ipc()))->names();
        }

        if (preg_match('#^[a-z0-9][a-z0-9-]*$#', $id) === 1
            && is_file(APPPATH . 'Views/plugins/apps/' . $id . '.php')) {
            return view('plugins/apps/' . $id, $data);
        }

        return view('plugins/show', $data);
    }

    /**
     * Run an iperf3 throughput test — the plugin's own UI surface
     * (`POST plugins/iperf3/run`).
     *
     * The daemon fixes the plugin id and validates every parameter, and the
     * response mirrors the JSON shape a small runner script renders in place.
     * A plugin that is not installed has no runner to reach.
     */
    public function iperf3Run(): ResponseInterface
    {
        $manager  = \Config\Services::packageManager();
        $manifest = $manager->registry()->get('iperf3');
        if ($manifest === null || ! isset($this->installedRows()['iperf3'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The iperf3 plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        // The runner posts JSON; a plain form post is accepted too so the
        // endpoint is easy to exercise by hand and from feature tests.
        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $params = [
            'mode'      => (string) ($body['mode'] ?? 'client'),
            'target'    => (string) ($body['target'] ?? ''),
            'port'      => (int) ($body['port'] ?? 5201),
            'duration'  => (int) ($body['duration'] ?? 10),
            'protocol'  => (string) ($body['protocol'] ?? 'tcp'),
            'direction' => (string) ($body['direction'] ?? 'upload'),
            'streams'   => (int) ($body['streams'] ?? 1),
        ];

        $result = $manager->iperf3Run($params);
        $ok     = ($result['success'] ?? false) === true;

        $warnings = $result['warnings'] ?? [];
        $message  = $ok
            ? 'Throughput test completed.'
            : (string) ($result['message'] ?? (is_array($warnings) && $warnings !== [] ? $warnings[0] : 'the daemon rejected the test'));

        return $this->response->setJSON([
            'ok'      => $ok,
            'message' => $message,
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * NetBird overlay status for the plugin's own page (`GET plugins/netbird/status`).
     *
     * Read-only, so the page can poll it without an operator action. A plugin
     * that is not installed has no agent to ask, and says so.
     */
    public function netbirdStatus(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('netbird') === null || ! isset($this->installedRows()['netbird'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The NetBird plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->netbirdStatus();

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Drive the NetBird overlay (`POST plugins/netbird/control`).
     *
     * `up` connects an enrolled peer, `down` disconnects it, and `enroll`
     * registers the peer with a setup key. The key is never stored by the
     * management plane and never echoed back: it is passed to the daemon, which
     * stages it as a 0600 file the agent reads and then deletes.
     */
    public function netbirdControl(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('netbird') === null || ! isset($this->installedRows()['netbird'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The NetBird plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $action     = (string) ($body['action'] ?? 'status');
        $management = (string) ($body['management_url'] ?? '');

        $result = match ($action) {
            'up'     => $manager->netbirdUp($management),
            'down'   => $manager->netbirdDown(),
            'enroll' => $manager->netbirdEnroll((string) ($body['setup_key'] ?? ''), $management),
            default  => ['success' => false, 'status' => 'error', 'message' => "unsupported action: {$action}"],
        };

        $ok       = ($result['success'] ?? false) === true;
        $warnings = $result['warnings'] ?? [];

        return $this->response->setJSON([
            'ok'      => $ok,
            'message' => $ok
                ? (string) ($result['message'] ?? 'Done.')
                : (string) ($result['message'] ?? (is_array($warnings) && $warnings !== [] ? $warnings[0] : 'the daemon rejected the request')),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * ZeroTier overlay status for the plugin's own page (`GET plugins/zerotier/status`).
     *
     * Read-only, so the page can poll it without an operator action. A plugin
     * that is not installed has no agent to ask, and says so.
     */
    public function zerotierStatus(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('zerotier') === null || ! isset($this->installedRows()['zerotier'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The ZeroTier plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->zerotierStatus();

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Join or leave one ZeroTier network (`POST plugins/zerotier/control`).
     *
     * The page never builds a command: it names a network id, and the daemon
     * validates it, resolves the plugin id against its own fixed catalogue, and
     * runs `zerotier-cli join|leave <id>` with a fixed argv.
     */
    public function zerotierControl(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('zerotier') === null || ! isset($this->installedRows()['zerotier'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The ZeroTier plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $action = (string) ($body['action'] ?? 'status');
        $nwid   = (string) ($body['nwid'] ?? '');

        $result = match ($action) {
            'join'  => $manager->zerotierJoin($nwid),
            'leave' => $manager->zerotierLeave($nwid),
            default => ['success' => false, 'status' => 'error', 'message' => "unsupported action: {$action}"],
        };

        $ok       = ($result['success'] ?? false) === true;
        $warnings = $result['warnings'] ?? [];

        return $this->response->setJSON([
            'ok'      => $ok,
            'message' => $ok
                ? (string) ($result['message'] ?? 'Done.')
                : (string) ($result['message'] ?? (is_array($warnings) && $warnings !== [] ? $warnings[0] : 'the daemon rejected the request')),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * NAT64/DNS64 status for the plugin's own page (`GET plugins/nat64/status`).
     *
     * Read-only, so the page can poll it without an operator action. A plugin
     * that is not installed has no translator to ask about.
     */
    public function nat64Status(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('nat64') === null || ! isset($this->installedRows()['nat64'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The NAT64 / DNS64 plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->nat64Status();

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Apply the NAT64/DNS64 configuration (`POST plugins/nat64/apply`).
     *
     * The form is passed through unchanged: the daemon validates every CIDR
     * before it writes a byte or runs a command, and gates the DNS64 half
     * through `unbound-checkconf` with rollback — the protected resolver unit
     * itself is never controlled from here.
     */
    public function nat64Apply(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('nat64') === null || ! isset($this->installedRows()['nat64'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The NAT64 / DNS64 plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $result = $manager->nat64Apply([
            'enabled' => (bool) ($body['enabled'] ?? true),
            'prefix'  => (string) ($body['prefix'] ?? '64:ff9b::/96'),
            'pool'    => (string) ($body['pool'] ?? ''),
            'dns64'   => (bool) ($body['dns64'] ?? true),
        ]);

        return $this->pluginApplyResponse(
            $result,
            ($result['success'] ?? false) === true ? $this->reapplyFirewallForPlugins() : null,
        );
    }

    /**
     * 6in4 tunnel status for the plugin's own page
     * (`GET plugins/ipv6-tunnel/status`). Read-only; the stored update key is
     * never part of the payload.
     */
    public function tunnelStatus(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('ipv6-tunnel') === null || ! isset($this->installedRows()['ipv6-tunnel'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The IPv6 tunnel plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->tunnelStatus();

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Apply (or tear down) the 6in4 tunnel (`POST plugins/ipv6-tunnel/apply`).
     *
     * The update key is forwarded once and never echoed: the daemon stages it
     * as a 0600 file, and neither this response nor any later status carries it
     * back. Leaving the field blank keeps the stored key.
     */
    public function tunnelApply(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('ipv6-tunnel') === null || ! isset($this->installedRows()['ipv6-tunnel'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The IPv6 tunnel plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $params = [
            'enabled'       => (bool) ($body['enabled'] ?? true),
            'broker'        => (string) ($body['broker'] ?? 'other'),
            'endpoint_v4'   => (string) ($body['endpoint_v4'] ?? ''),
            'local_v4'      => (string) ($body['local_v4'] ?? ''),
            'client_v6'     => (string) ($body['client_v6'] ?? ''),
            'server_v6'     => (string) ($body['server_v6'] ?? ''),
            'routed_prefix' => (string) ($body['routed_prefix'] ?? ''),
            'mtu'           => (int) ($body['mtu'] ?? 1480),
            'update_user'   => (string) ($body['update_user'] ?? ''),
            'update_tunnel' => (string) ($body['update_tunnel'] ?? ''),
        ];
        // The key travels only when it was actually typed: an empty string
        // means "clear it", an absent field means "leave it as it is".
        if (array_key_exists('update_key', $body)) {
            $params['update_key'] = (string) $body['update_key'];
        }

        $result = $manager->tunnelApply($params);

        return $this->pluginApplyResponse(
            $result,
            ($result['success'] ?? false) === true ? $this->reapplyFirewallForPlugins() : null,
        );
    }

    /**
     * Tell the broker this appliance's current IPv4 endpoint
     * (`POST plugins/ipv6-tunnel/update`).
     *
     * The key never leaves the daemon; the response is the broker's
     * dyndns2-style answer and nothing else.
     */
    public function tunnelUpdate(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('ipv6-tunnel') === null || ! isset($this->installedRows()['ipv6-tunnel'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The IPv6 tunnel plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->tunnelUpdate();

        return $this->pluginApplyResponse($result);
    }

    /**
     * darkstat status for the plugin's own page (`GET plugins/darkstat/status`).
     *
     * Read-only, so the page can poll it without an operator action. A plugin
     * that is not installed has no analyser to ask about.
     */
    public function darkstatStatus(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('darkstat') === null || ! isset($this->installedRows()['darkstat'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The darkstat plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->darkstatStatus();

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Apply the darkstat analyser's settings (`POST plugins/darkstat/apply`).
     *
     * The form is passed through unchanged: the daemon validates every value
     * (interface names, port range, local networks, the BPF filter, the hosts
     * caps) before it writes the generated systemd drop-in or runs a command.
     */
    public function darkstatApply(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('darkstat') === null || ! isset($this->installedRows()['darkstat'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The darkstat plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $result = $manager->darkstatApply([
            'enabled'       => (bool) ($body['enabled'] ?? true),
            'interface'     => (string) ($body['interface'] ?? ''),
            'port'          => (int) ($body['port'] ?? 666),
            'local_nets'    => (string) ($body['local_nets'] ?? ''),
            'filter'        => (string) ($body['filter'] ?? ''),
            'resolve_dns'   => (bool) ($body['resolve_dns'] ?? true),
            'local_only'    => (bool) ($body['local_only'] ?? false),
            'chroot_dir'    => (string) ($body['chroot_dir'] ?? ''),
            'database_file' => (string) ($body['database_file'] ?? ''),
            'daylog'        => (string) ($body['daylog'] ?? ''),
            'hosts_max'     => (int) ($body['hosts_max'] ?? 1000),
            'hosts_keep'    => (int) ($body['hosts_keep'] ?? 30),
        ]);

        // darkstat serves its own web UI on the configured port; the input
        // chain drops everything it does not list, so the port is recorded and
        // the ruleset reloaded — otherwise the analyser runs but its page
        // never loads from the LAN.
        if (($result['success'] ?? false) === true) {
            $enabled = (bool) ($body['enabled'] ?? true);
            $port    = (int) ($body['port'] ?? 666);
            if ($this->recordPluginWebPort('darkstat', $enabled ? $port : null)) {
                $warning = $this->reapplyFirewallForPlugins();
                if ($warning !== null) {
                    $result['warnings']   = $result['warnings'] ?? [];
                    $result['warnings'][] = $warning;
                } elseif ($enabled) {
                    $result['steps']   = $result['steps'] ?? [];
                    $result['steps'][] = "firewall: opened TCP {$port} on the LAN for the darkstat web UI";
                }
            }
        }

        return $this->pluginApplyResponse($result);
    }

    /**
     * lightsquid status for the plugin's own page (`GET plugins/lightsquid/status`).
     *
     * Read-only, so the page can poll it without an operator action. A plugin
     * that is not installed has no reporter to ask about.
     */
    public function lightsquidStatus(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('lightsquid') === null || ! isset($this->installedRows()['lightsquid'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The lightsquid plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->lightsquidStatus();

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Apply the lightsquid reporter's settings (`POST plugins/lightsquid/apply`).
     *
     * The form is passed through unchanged: the daemon validates every value
     * (the language/template/colour/name sets, the schedule, the directories and
     * the skip list) before it writes the config file or the cron drop-in.
     */
    public function lightsquidApply(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('lightsquid') === null || ! isset($this->installedRows()['lightsquid'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The lightsquid plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $result = $manager->lightsquidApply([
            'enabled'    => (bool) ($body['enabled'] ?? true),
            'lang'       => (string) ($body['lang'] ?? 'eng'),
            'template'   => (string) ($body['template'] ?? 'base'),
            'barcolor'   => (string) ($body['barcolor'] ?? 'orange'),
            'ip2name'    => (string) ($body['ip2name'] ?? 'dns'),
            'skipurl'    => (string) ($body['skipurl'] ?? ''),
            'log_dir'    => (string) ($body['log_dir'] ?? ''),
            'report_dir' => (string) ($body['report_dir'] ?? ''),
            'schedule'   => (string) ($body['schedule'] ?? '60m'),
        ]);

        return $this->pluginApplyResponse($result);
    }

    /**
     * Run the lightsquid parser now (`POST plugins/lightsquid/refresh`).
     *
     * pfSense's "Refresh now" (today's log) and "Refresh full" (all rotated
     * logs). The daemon fixes the parser and validates the mode.
     */
    public function lightsquidRefresh(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('lightsquid') === null || ! isset($this->installedRows()['lightsquid'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The lightsquid plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $result = $manager->lightsquidRefresh([
            'mode' => (string) ($body['mode'] ?? 'today'),
        ]);

        return $this->pluginApplyResponse($result);
    }

    /**
     * LightSquid generated report viewer / console (`GET lightsquid` or `GET lightsquid/(:any)`).
     *
     * Serves generated static HTML/assets from the configured report directory,
     * or renders the interactive LightSquid Reports Console when no static report exists yet.
     */
    public function lightsquidReport(string $subpath = ''): ResponseInterface|string
    {
        $manager = \Config\Services::packageManager();
        $isInstalled = isset($this->installedRows()['lightsquid']);

        // Determine the configured report directory
        $reportDir = '/var/lightsquid/report';
        if ($isInstalled) {
            $status = $manager->lightsquidStatus();
            if (!empty($status['data']['report_dir'])) {
                $reportDir = (string) $status['data']['report_dir'];
            }
        }

        // Also check writable/lightsquid/report for local/dev environments
        $devDir = WRITEPATH . 'lightsquid/report';
        $activeDir = is_dir($reportDir) ? $reportDir : (is_dir($devDir) ? $devDir : $reportDir);

        $cleanSubpath = ltrim(str_replace(['..', "\0"], '', $subpath), '/\\');
        $targetFile = $cleanSubpath !== '' ? rtrim($activeDir, '/\\') . DIRECTORY_SEPARATOR . $cleanSubpath : '';

        // If a specific file was requested and exists, serve it
        if ($targetFile !== '' && is_file($targetFile)) {
            $ext = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'html', 'htm' => 'text/html; charset=UTF-8',
                'css'         => 'text/css; charset=UTF-8',
                'js'          => 'application/javascript; charset=UTF-8',
                'png'         => 'image/png',
                'gif'         => 'image/gif',
                'jpg', 'jpeg' => 'image/jpeg',
                'svg'         => 'image/svg+xml',
                default       => 'application/octet-stream',
            };

            return $this->response
                ->setHeader('Content-Type', $mime)
                ->setBody((string) file_get_contents($targetFile));
        }

        // If top-level was requested and index.html exists, serve it
        if ($cleanSubpath === '' && is_file(rtrim($activeDir, '/\\') . DIRECTORY_SEPARATOR . 'index.html')) {
            return $this->response
                ->setHeader('Content-Type', 'text/html; charset=UTF-8')
                ->setBody((string) file_get_contents(rtrim($activeDir, '/\\') . DIRECTORY_SEPARATOR . 'index.html'));
        }

        // Otherwise render the interactive LightSquid Console view
        $manifest = $manager->registry()->get('lightsquid');
        $pluginData = $manifest ? $manifest->toArray() : ['id' => 'lightsquid', 'name' => 'Lightsquid Report', 'version' => '1.0.0', 'class' => 'B'];

        return view('plugins/apps/lightsquid_console', [
            'plugin'      => $pluginData,
            'installed'   => $isInstalled,
            'reportDir'   => $reportDir,
            'hasReports'  => is_dir($activeDir) && count(glob(rtrim($activeDir, '/\\') . '/*') ?: []) > 0,
            'refreshUrl'  => base_url('plugins/lightsquid/refresh'),
            'statusUrl'   => base_url('plugins/lightsquid/status'),
            'settingsUrl' => base_url('plugins/lightsquid'),
        ]);
    }

    /**
     * lldpd status for the plugin's own page (`GET plugins/lldpd/status`).
     *
     * Read-only, so the page can poll it without an operator action. A plugin
     * that is not installed has no daemon to ask about.
     */
    public function lldpdStatus(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('lldpd') === null || ! isset($this->installedRows()['lldpd'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The lldpd plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->lldpdStatus();

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * The LLDP neighbour table (`GET plugins/lldpd/neighbours`).
     *
     * `?format=json` asks the daemon for one structured row per neighbour (the
     * local port, the remote chassis and port, the management address and the
     * TTL) instead of the raw `lldpcli` listing; anything else is the text form.
     * The value is forwarded unchanged and validated by the daemon, which
     * refuses an unknown format before it runs `lldpcli`.
     *
     * Read-only: it is the plugin page's LLDP Status surface, mirroring
     * pfSense's LLDP Status tab. The listing is the daemon's own output.
     */
    public function lldpdNeighbours(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('lldpd') === null || ! isset($this->installedRows()['lldpd'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The lldpd plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $params = [];
        $format = trim((string) $this->request->getGet('format'));
        if ($format !== '') {
            $params['format'] = $format;
        }

        $result = $manager->lldpdNeighbours($params);

        return $this->response->setJSON([
            'ok'      => ($result['success'] ?? false) === true,
            'message' => (string) ($result['message'] ?? ''),
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Apply the LLDP daemon's settings (`POST plugins/lldpd/apply`).
     *
     * The form is passed through unchanged: the daemon validates every value
     * (the interface pattern, the management-address pattern, the description
     * and platform strings, the hostname and the transmit timing) before it
     * writes the generated systemd drop-in or `/etc/lldpd.conf`.
     */
    public function lldpdApply(): ResponseInterface
    {
        $manager = \Config\Services::packageManager();
        if ($manager->registry()->get('lldpd') === null || ! isset($this->installedRows()['lldpd'])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => 'The lldpd plugin is not installed.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $body = $this->request->getJSON(true) ?: [];
        if ($body === []) {
            $body = $this->request->getPost() ?: [];
        }

        $result = $manager->lldpdApply([
            'enabled'                 => (bool) ($body['enabled'] ?? true),
            'interfaces'              => (string) ($body['interfaces'] ?? ''),
            'receive_only'            => (bool) ($body['receive_only'] ?? false),
            'cdp'                     => (bool) ($body['cdp'] ?? false),
            'edp'                     => (bool) ($body['edp'] ?? false),
            'fdp'                     => (bool) ($body['fdp'] ?? false),
            'sonmp'                   => (bool) ($body['sonmp'] ?? false),
            'snmp_subagent'           => (bool) ($body['snmp_subagent'] ?? false),
            'suppress_kernel_version' => (bool) ($body['suppress_kernel_version'] ?? false),
            'system_description'      => (string) ($body['system_description'] ?? ''),
            'platform'                => (string) ($body['platform'] ?? ''),
            'management_addresses'    => (string) ($body['management_addresses'] ?? ''),
            'system_name'             => (string) ($body['system_name'] ?? ''),
            'tx_interval'             => (int) ($body['tx_interval'] ?? 30),
            'tx_hold'                 => (int) ($body['tx_hold'] ?? 4),
        ]);

        return $this->pluginApplyResponse($result);
    }

    /**
     * The shared JSON envelope for the two plugins' apply/control endpoints:
     * `ok`, a human message (the daemon's warnings as fallback), the full
     * result for the page's step list, and a fresh CSRF token.
     *
     * @param array<string, mixed> $result
     */
    private function pluginApplyResponse(array $result, ?string $warning = null): ResponseInterface
    {
        $ok       = ($result['success'] ?? false) === true;
        $warnings = $result['warnings'] ?? [];

        $message = $ok
            ? (string) ($result['message'] ?? 'Done.')
            : (string) ($result['message'] ?? (is_array($warnings) && $warnings !== [] ? $warnings[0] : 'the daemon rejected the request'));

        if ($warning !== null && $warning !== '') {
            $message = trim($message . ' ' . $warning);
        }

        return $this->response->setJSON([
            'ok'      => $ok,
            'message' => $message,
            'result'  => $result,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Load a freshly compiled filter ruleset after a plugin changed which
     * virtual interfaces exist.
     *
     * The NAT64 and tunnel accepts in the compiled ruleset are gated on the
     * owning plugin's own enabled row (see NftablesCompiler::pluginForwardDevices),
     * so a translator or tunnel that just came up — or just went away — is only
     * reachable once the new ruleset is loaded. The operator already confirmed
     * this by clicking Apply, so it runs without safe mode: the rollback timer
     * would silently revert the accepts a moment later.
     *
     * @return string|null a warning to surface, or null when the ruleset is live
     */
    private function reapplyFirewallForPlugins(): ?string
    {
        try {
            $ruleset = (new \App\Libraries\NftablesCompiler())->compile();
            $resp    = \Config\Services::ipc()->call('firewall.apply', [
                'table'     => 'filter',
                'ruleset'   => $ruleset,
                'safe_mode' => false,
            ]);
        } catch (\Throwable $e) {
            return 'The firewall ruleset could not be recompiled: ' . $e->getMessage();
        }

        if (! empty($resp['error'])) {
            return 'The firewall ruleset could not be recompiled: ' . ($resp['error']['message'] ?? 'daemon error');
        }

        $status = $resp['result']['status'] ?? 'applied';
        if (in_array($status, ['syntax_error', 'apply_failed', 'error'], true)) {
            return 'The firewall ruleset was rejected by the dataplane: ' . ($resp['result']['error'] ?? $status);
        }

        return null;
    }

    /**
     * Update / upgrade screen: installed plugins with their current and shipped
     * versions, the repository Refresh action, and per-plugin / bulk upgrade.
     */
    public function updates(): string
    {
        $manager   = \Config\Services::packageManager();
        $installed = $this->installedRows();

        $catalogue    = \Config\Services::remoteCatalogue();
        $remoteVersions = $catalogue->versions();

        $plugins = [];
        foreach ($installed as $id => $row) {
            $manifest = $manager->registry()->get((string) $id);
            if ($manifest === null) {
                continue;
            }

            $installedVersion = (string) ($row['version'] ?? '');
            $shipped          = $manifest->version();
            $remote           = (string) ($remoteVersions[(string) $id] ?? '');

            // The catalogue wins when it names a newer release than the shipped
            // manifest; otherwise the appliance's own manifest is the offer.
            $fromRemote = $remote !== '' && version_compare($remote, $shipped, '>');
            $available  = $fromRemote ? $remote : $shipped;

            $plugins[] = [
                'id'                => $manifest->id(),
                'name'              => $manifest->name(),
                'installed_version' => $installedVersion,
                'available_version' => $available,
                'shipped_version'   => $shipped,
                'remote_version'    => $remote,
                'source'            => $fromRemote ? 'remote' : 'shipped',
                'update_available'  => version_compare($available, $installedVersion, '>'),
                'state'             => (string) ($row['state'] ?? 'installed'),
                'enabled'           => (int) ($row['enabled'] ?? 0),
                'packages'          => $manifest->packages(),
            ];
        }

        return view('plugins/updates', [
            'title'      => 'Plugin Updates & Upgrades — MicronOS',
            'breadcrumb' => 'Plugins: Updates',
            'plugins'    => $plugins,
            'catalogue'  => $catalogue->status(),
        ]);
    }

    /**
     * Refresh the signed remote catalogue (`POST plugins/catalogue/sync`).
     *
     * Fetches through the shared egress guard, verifies the signature against a
     * key shipped in the image, and caches the verified document. Nothing is
     * cached unless the signature checks out.
     */
    public function syncCatalogue(): RedirectResponse
    {
        $result = \Config\Services::remoteCatalogue()->sync();

        if (($result['ok'] ?? false) !== true) {
            return redirect()->to(base_url('plugins/updates'))
                ->with('error', 'Signed catalogue sync failed: ' . (string) ($result['message'] ?? 'unknown error'));
        }

        $catalogue = is_array($result['catalogue'] ?? null) ? $result['catalogue'] : [];
        $count     = is_array($catalogue['plugins'] ?? null) ? count($catalogue['plugins']) : 0;
        $keyId     = (string) ($result['meta']['key_id'] ?? '—');

        return redirect()->to(base_url('plugins/updates'))
            ->with('success', sprintf('Signed catalogue verified: %d plugin(s) in the feed, signed by %s.', $count, $keyId));
    }

    /**
     * Upgrade every installed plugin's packages in one pass; per-plugin upgrade
     * records are re-synced to the shipped manifest version.
     */
    public function upgradeAll(): RedirectResponse
    {
        $manager   = \Config\Services::packageManager();
        $installed = $this->installedRows();

        if ($installed === []) {
            return redirect()->back()->with('error', 'No plugins are installed to upgrade.');
        }

        $upgraded = 0;
        $failed   = [];
        foreach ($installed as $id => $row) {
            $manifest = $manager->registry()->get((string) $id);
            if ($manifest === null) {
                continue;
            }

            $result = $manager->upgrade((string) $id);
            if (($result['success'] ?? false) === true) {
                $upgraded++;
                $this->syncVersionRow((string) $id, $this->targetVersion((string) $id, $manifest->version()));
            } else {
                $failed[] = (string) $id;
            }
        }

        if ($failed !== []) {
            return redirect()->back()->with('error', 'Upgrade failed for: ' . implode(', ', $failed) . '.');
        }

        return redirect()->back()->with('success', sprintf('Upgrade complete: %d plugin(s) up to date.', $upgraded));
    }

    /**
     * Bulk run-state poll for the installed-plugin daemon widget.
     *
     *   GET plugins/daemons/status?ids=lldpd,darkstat
     *
     * Returns the shared service widget's `{ok, services: {id: state}}` shape,
     * resolved per plugin through `plugin.status` (the daemon reads the plugin's
     * own systemd unit). Ids with no daemon — unknown, or a plugin that declares
     * no units — report "unknown".
     */
    public function daemonStatus(): ResponseInterface
    {
        $raw = (string) ($this->request->getGet('ids') ?? '');
        $ids = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== ''));
        $ids = array_slice(array_unique($ids), 0, 25);

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = 'unknown';
        }

        if ($ids !== []) {
            $manager   = \Config\Services::packageManager();
            $installed = $this->installedRows();
            $withUnits = [];
            foreach ($manager->catalogue() as $entry) {
                if (! empty($entry['units'])) {
                    $withUnits[(string) $entry['id']] = true;
                }
            }

            foreach ($ids as $id) {
                // Only an installed plugin that owns a daemon has a live unit.
                if (! isset($withUnits[$id]) || ! isset($installed[$id])) {
                    continue;
                }
                $result = $manager->status($id);
                if (($result['success'] ?? false) === true && isset($result['state'])) {
                    $map[$id] = (string) $result['state'];
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'services' => $map]);
    }

    /**
     * JSON start / stop / restart of an installed plugin's own daemon.
     *
     * The plugin is addressed by id — the daemon resolves that id to the plugin's
     * unit from its fixed catalogue and refuses anything outside it, exactly as
     * the DHCP relay instance controls address a compiled slug. The response
     * mirrors the shared widget's `/control` contract so the buttons update in
     * place without a page reload.
     */
    public function daemonControl(string $id): ResponseInterface
    {
        $action = (string) ($this->request->getPost('action') ?? '');
        if ($action === '') {
            $json   = $this->request->getJSON(true) ?: [];
            $action = (string) ($json['action'] ?? '');
        }
        $action = strtolower(trim($action));

        if (! in_array($action, self::SERVICE_ACTIONS, true)) {
            return $this->response->setStatusCode(422)->setJSON([
                'ok'      => false,
                'message' => 'Invalid action.',
                'csrf'    => csrf_hash(),
            ]);
        }

        $manager  = \Config\Services::packageManager();
        $manifest = $manager->registry()->get($id);
        if ($manifest === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => "Unknown plugin: {$id}.",
                'csrf'    => csrf_hash(),
            ]);
        }
        if (! isset($this->installedRows()[$id])) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok'      => false,
                'message' => "Plugin '{$id}' is not installed.",
                'csrf'    => csrf_hash(),
            ]);
        }
        if ($manifest->units() === []) {
            return $this->response->setStatusCode(422)->setJSON([
                'ok'      => false,
                'message' => "Plugin '{$id}' has no daemon to control.",
                'csrf'    => csrf_hash(),
            ]);
        }

        $result = $manager->service($id, $action);
        $ok     = ($result['success'] ?? false) === true;

        $status = strtolower((string) ($result['state'] ?? ''));
        if (! in_array($status, ['running', 'stopped'], true)) {
            $status = $action === 'stop' ? 'stopped' : 'running';
        }

        $past = ['start' => 'Started', 'stop' => 'Stopped', 'restart' => 'Restarted'][$action];

        return $this->response->setJSON([
            'ok'      => $ok,
            'service' => $id,
            'name'    => $manifest->name(),
            // On failure the LED keeps its last known state (no status field).
            'status'  => $ok ? $status : null,
            'message' => $ok
                ? "{$past} {$manifest->name()} daemon"
                : sprintf('Plugin %s failed: %s', $action, (string) ($result['message'] ?? 'unknown error')),
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Recent journal lines for a plugin's own unit, for the widget's LED
     * click-through (`GET plugins/daemons/{id}/logs`).
     */
    public function daemonLogs(string $id): ResponseInterface
    {
        $manager  = \Config\Services::packageManager();
        $manifest = $manager->registry()->get($id);
        if ($manifest === null || $manifest->units() === [] || ! isset($this->installedRows()[$id])) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Plugin daemon not found']);
        }

        $result = $manager->logs($id, 30);
        $logs   = trim((string) ($result['logs'] ?? ''));
        if ($logs === '') {
            $logs = sprintf('No journal entries found for the %s daemon.', $manifest->name());
        }

        return $this->response->setJSON([
            'service' => $manifest->name(),
            'logs'    => $logs,
        ]);
    }

    public function refresh(): RedirectResponse
    {
        $result = \Config\Services::packageManager()->refresh();

        if (($result['success'] ?? false) !== true) {
            return redirect()->back()->with('error', 'Repository refresh failed: ' . $this->reason($result));
        }

        return redirect()->back()->with('success', 'Plugin repository metadata refreshed.');
    }

    /**
     * Lightweight JSON status for the page's poll.
     */
    public function status(): \CodeIgniter\HTTP\ResponseInterface
    {
        $manager   = \Config\Services::packageManager();
        $installed = $this->installedRows();

        $out = [];
        foreach ($manager->catalogue() as $entry) {
            $row = $installed[$entry['id']] ?? null;
            $out[$entry['id']] = [
                'installed' => $row !== null,
                'enabled'   => (int) ($row['enabled'] ?? 0),
                'state'     => $row['state'] ?? 'available',
            ];
        }

        return $this->response->setJSON(['plugins' => $out]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * First-paint run-state for every installed plugin that owns a daemon.
     *
     * @param list<array<string, mixed>> $plugins catalogue entries as rendered
     *
     * @return array<string, string> plugin id => running|stopped
     */
    private function daemonStates(array $plugins, PackageManager $manager): array
    {
        $states = [];
        foreach ($plugins as $plugin) {
            if (empty($plugin['installed']) || empty($plugin['units'])) {
                continue;
            }
            $result = $manager->status((string) $plugin['id']);
            if (($result['success'] ?? false) === true && isset($result['state'])) {
                $states[(string) $plugin['id']] = (string) $result['state'];
            }
        }

        return $states;
    }

    /**
     * The version an upgrade should record for a plugin: the signed catalogue's
     * when it names a newer release, otherwise the shipped manifest's.
     */
    private function targetVersion(string $id, string $shipped): string
    {
        $remote = (string) (\Config\Services::remoteCatalogue()->versions()[$id] ?? '');

        return $remote !== '' && version_compare($remote, $shipped, '>') ? $remote : $shipped;
    }

    /**
     * Keep the installed row's version/state in step with the shipped manifest
     * after a successful upgrade.
     */
    private function syncVersionRow(string $id, string $version): void
    {
        $db = \Config\Database::connect();
        if ($db->table('plugins')->where('id', $id)->countAllResults() > 0) {
            $db->table('plugins')->where('id', $id)->update([
                'version' => $version,
                'state'   => 'enabled',
                'enabled' => 1,
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>> installed plugins keyed by id
     */
    /**
     * First start of darkstat after install, on the LAN port with port 666.
     * Returns null on success, or what went wrong (the plugin stays installed).
     */
    private function darkstatFirstStart(\App\Libraries\PackageManager $manager): ?string
    {
        $db  = \Config\Database::connect();
        $lan = '';
        try {
            $lan = (string) ($db->table('interfaces')->where('id', 'lan')->get()->getRowArray()['physical_device'] ?? '');
        } catch (\Throwable) {
        }
        $names = (new \App\Libraries\NetworkHardware(\Config\Services::ipc()))->names();
        if ($lan === '' || ($names !== [] && ! in_array($lan, $names, true) && ! str_starts_with($lan, 'br'))) {
            $lan = $names[1] ?? $names[0] ?? '';
        }
        if ($lan === '') {
            return 'no capture interface could be chosen; set one on the darkstat page.';
        }

        $result = $manager->darkstatApply(['enabled' => true, 'interface' => $lan, 'port' => 666]);
        if (($result['success'] ?? false) !== true) {
            return 'darkstat could not be started: ' . (string) ($result['message'] ?? 'daemon error');
        }
        if ($this->recordPluginWebPort('darkstat', 666)) {
            return $this->reapplyFirewallForPlugins();
        }

        return null;
    }

    /**
     * Record (or clear, with null) the web port a plugin listens on, which the
     * firewall compiler opens on the LAN. Returns true when the stored value
     * changed and the ruleset therefore needs reloading.
     */
    private function recordPluginWebPort(string $id, ?int $port): bool
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('plugin_settings')) {
            return false;
        }

        $table   = $db->table('plugin_settings');
        $current = $table->where('plugin_id', $id)->where('setting_key', 'web_port')->get()->getRowArray();
        $value   = $port === null ? null : (string) $port;

        if ($current === null && $value === null) {
            return false;
        }
        if ($current !== null && (string) $current['value'] === (string) $value) {
            return false;
        }

        if ($value === null) {
            $db->table('plugin_settings')->where('plugin_id', $id)->where('setting_key', 'web_port')->delete();
        } elseif ($current === null) {
            $db->table('plugin_settings')->insert(['plugin_id' => $id, 'setting_key' => 'web_port', 'value' => $value, 'is_secret' => 0]);
        } else {
            $db->table('plugin_settings')->where('id', $current['id'])->update(['value' => $value]);
        }

        return true;
    }

    private function installedRows(): array
    {
        $db = \Config\Database::connect();
        if (! $db->tableExists('plugins')) {
            return [];
        }

        $rows = $db->table('plugins')->get()->getResultArray();
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['id']] = $row;
        }

        return $out;
    }

    /**
     * Persist the plugin row and its capability grants after a successful install.
     *
     * @param list<string> $grants
     */
    private function persistInstall(PluginManifest $manifest, array $grants): void
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $id  = $manifest->id();

        $exists = $db->table('plugins')->where('id', $id)->countAllResults() > 0;

        $row = [
            'id'            => $id,
            'name'          => $manifest->name(),
            'version'       => $manifest->version(),
            'class'         => $manifest->class(),
            'state'         => 'enabled',
            'enabled'       => 1,
            'data_policy'   => 'retain',
            'manifest_json' => json_encode($manifest->toArray(), JSON_UNESCAPED_SLASHES),
        ];

        if ($exists) {
            $db->table('plugins')->where('id', $id)->update($row);
        } else {
            $row['installed_at'] = $now;
            $db->table('plugins')->insert($row);
        }

        $db->table('plugin_grants')->where('plugin_id', $id)->delete();
        foreach ($manifest->capabilities() as $capability) {
            $db->table('plugin_grants')->insert([
                'plugin_id'  => $id,
                'capability' => $capability,
                'granted'    => in_array($capability, $grants, true) ? 1 : 0,
                'granted_by' => (string) (session('username') ?? ''),
                'granted_at' => $now,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function failureMessage(string $id, array $result): string
    {
        $plan = \Config\Services::packageManager()->plan($id);
        if (! $plan['ok'] && $plan['blocked'] !== []) {
            return "Plugin '{$id}' cannot be installed on this appliance: " . implode('; ', $plan['blocked']) . '.';
        }

        return "Plugin '{$id}' operation failed: " . $this->reason($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function reason(array $result): string
    {
        return (string) ($result['message'] ?? 'the daemon rejected the request');
    }
}

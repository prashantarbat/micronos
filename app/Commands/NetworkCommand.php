<?php

namespace App\Commands;

use App\Libraries\Network\NetworkPlan;
use App\Libraries\Network\NetworkTransaction;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Console access to the same network transaction the Web UI uses.
 *
 *   php spark network plan                       print the plan built from the database (JSON)
 *   php spark network status                     committed plan vs kernel, pending change, last result
 *   php spark network apply [--confirm]          apply everything that is configured
 *   php spark network assign <wan-device> <lan-device|none> [optN=device ...] [--factory-reset]
 *   php spark network set-lan <device> <dhcp|a.b.c.d/nn>
 *   php spark network set-mode <interface-id> <dhcp|none>
 *   php spark network set-address <id> <cidr> [gateway] [--keep-references]
 *   php spark network ipv6 <on|off|status>
 *   php spark network confirm <tx>  |  rollback  |  reconcile
 *   php spark network boot-files <dir>           write plan.json, nftables.conf and kea-dhcp4.conf
 *                                                from the database (boot: micrond is not up yet)
 *   php spark network set-address ... --offline  update the database and every dependent setting
 *                                                without applying (boot-time conflict repair)
 *
 * Exit status: 0 success, 1 refused/rolled back, 2 usage error.
 */
class NetworkCommand extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'network';
    protected $description = 'Plan, apply and inspect the network configuration (one transaction for Web UI, CLI and boot).';
    protected $usage       = 'network <plan|status|apply|assign|set-device|swap|set-wan|set-lan|set-mode|set-address|ipv6|confirm|rollback|reconcile|boot-files> [args]';

    public function run(array $params)
    {
        $action = (string) ($params[0] ?? 'status');
        $ipc    = \Config\Services::ipc();
        $tx     = new NetworkTransaction($ipc);
        $actor  = 'console:' . (get_current_user() ?: 'root');

        switch ($action) {
            case 'plan':
                CLI::print(json_encode((new NetworkPlan())->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

                return 0;

            case 'status':
                $st = $ipc->call('network.status')['result'] ?? null;
                if (! is_array($st)) {
                    CLI::error('The appliance daemon did not answer.');

                    return 1;
                }
                $plan = (new NetworkPlan())->build();
                $ipv6Effective = $st['ipv6_effective'] ?? null;
                CLI::write('IPv6: configured ' . ($plan['ipv6_enabled'] ? 'ON' : 'OFF') . ', kernel '
                    . ($ipv6Effective === null ? 'n/a' : ($ipv6Effective ? 'ON' : 'OFF')));
                foreach ($plan['interfaces'] as $i) {
                    $eff = $st['effective'][$i['device']]['ipv4'] ?? [];
                    CLI::write(sprintf('  %-6s %-10s %-7s want %-18s have %s', strtoupper($i['id']), $i['device'], $i['mode'],
                        $i['ipv4'] ?? '-', implode(', ', array_column((array) $eff, 'cidr')) ?: '-'));

                    $interfaceId = strtolower((string) $i['id']);
                    if (! empty($i['enabled']) && ($interfaceId === 'lan' || preg_match('/^opt[1-9][0-9]*$/', $interfaceId))) {
                        foreach ((array) $eff as $address) {
                            $cidr = (string) ($address['cidr'] ?? '');
                            $ip   = explode('/', $cidr, 2)[0];
                            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                CLI::write("    Web UI ({$interfaceId}): http://{$ip}/ (Port 80)", 'cyan');
                            }
                        }
                    }
                }
                if (! empty($st['pending'])) {
                    CLI::write('Pending change ' . $st['pending']['tx'] . ' — ' . $st['pending']['seconds_left'] . 's to confirm', 'yellow');
                }
                if (! empty($st['last'])) {
                    CLI::write('Last result: ' . ($st['last']['status'] ?? '') . ' — ' . ($st['last']['message'] ?? ''));
                }

                return 0;

            case 'apply':
                return $this->report($tx->apply(null, ['confirm' => CLI::getOption('confirm') !== null, 'actor' => $actor, 'reason' => 'console apply']));

            case 'assign':
                $wan = trim((string) ($params[1] ?? ''));
                $lan = trim((string) ($params[2] ?? ''));
                $factoryReset = CLI::getOption('factory-reset') !== null;
                if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $wan)
                    || ($lan !== '' && strtolower($lan) !== 'none' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $lan))) {
                    CLI::error('usage: php spark network assign <wan-device> <lan-device|none> [optN=device ...]');

                    return 2;
                }
                $lan = strtolower($lan) === 'none' ? '' : $lan;
                if ($lan !== '' && $lan === $wan) {
                    CLI::error('WAN and LAN must use different physical interfaces.');

                    return 2;
                }
                $optional = [];
                $usedDevices = array_filter([$wan, $lan]);
                foreach (array_slice($params, 3) as $assignment) {
                    if ((string) $assignment === '--factory-reset') {
                        continue;
                    }
                    $parts = explode('=', (string) $assignment, 2);
                    $id = strtolower(trim($parts[0] ?? ''));
                    $device = trim($parts[1] ?? '');
                    if (! preg_match('/^opt[1-9][0-9]*$/', $id)
                        || ($device !== '' && strtolower($device) !== 'none'
                            && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $device))) {
                        CLI::error('optional interfaces must be specified as optN=<device|none>');

                        return 2;
                    }
                    $device = strtolower($device) === 'none' ? '' : $device;
                    if (isset($optional[$id]) || ($device !== '' && in_array($device, $usedDevices, true))) {
                        CLI::error('each interface role and physical device may be assigned only once');

                        return 2;
                    }
                    $optional[$id] = $device;
                    if ($device !== '') {
                        $usedDevices[] = $device;
                    }
                }

                $bridgeRows = [];
                $db = \Config\Database::connect();
                if ($factoryReset && $db->tableExists('interface_bridges')) {
                    $bridgeRows = $db->table('interface_bridges')->get()->getResultArray();
                    foreach ($bridgeRows as $bridge) {
                        $removed = $ipc->bridgeDelete((string) $bridge['id']);
                        if (($removed['success'] ?? false) !== true) {
                            CLI::error("Could not detach legacy bridge {$bridge['id']}; network roles were not changed.");

                            return 1;
                        }
                    }
                }

                $result = $tx->apply(static function ($db) use ($wan, $lan, $optional, $factoryReset, $bridgeRows) {
                    $wanRow = $db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
                    $lanBefore = $db->table('interfaces')->where('id', 'lan')->get()->getRowArray();
                    if (! $wanRow || ! $lanBefore) {
                        throw new \RuntimeException('WAN and LAN interfaces must exist before assignment');
                    }
                    $now = date('Y-m-d H:i:s');
                    if ($factoryReset) {
                        foreach ($bridgeRows as $bridge) {
                            $db->table('interfaces')->where('id', $bridge['id'])->update([
                                'physical_device' => '',
                                'enabled' => 0,
                                'ip_assignment_type' => 'none',
                                'ipv4_address' => null,
                                'ipv4_netmask' => null,
                                'ipv4_gateway' => null,
                                'updated_at' => $now,
                            ]);
                        }
                    }

                    // Robust conflict resolution: clear claimed physical devices from any other interfaces
                    $allClaimed = array_filter(array_merge([$wan, $lan], array_values($optional)));
                    $otherConflicting = $db->table('interfaces')
                        ->whereNotIn('id', array_merge(['wan', 'lan'], array_keys($optional)))
                        ->whereIn('physical_device', $allClaimed)
                        ->get()->getResultArray();
                    foreach ($otherConflicting as $conf) {
                        $db->table('interfaces')->where('id', $conf['id'])->update([
                            'physical_device' => '',
                            'enabled' => 0,
                            'updated_at' => $now,
                        ]);
                    }

                    // Update WAN: preserve existing addressing settings unless factory-reset is requested
                    $wanUpdate = [
                        'physical_device' => $wan,
                        'enabled' => 1,
                        'updated_at' => $now,
                    ];
                    if ($factoryReset) {
                        $wanUpdate['ip_assignment_type'] = 'dhcp';
                        $wanUpdate['ipv4_address'] = null;
                        $wanUpdate['ipv4_gateway'] = null;
                    } elseif (empty($wanRow['ip_assignment_type']) || $wanRow['ip_assignment_type'] === 'none') {
                        $wanUpdate['ip_assignment_type'] = 'dhcp';
                    }
                    $db->table('interfaces')->where('id', 'wan')->update($wanUpdate);

                    // Update LAN: preserve existing addressing settings unless factory-reset is requested
                    if ($lan === '') {
                        $db->table('interfaces')->where('id', 'lan')->update([
                            'physical_device' => null,
                            'enabled' => 0,
                            'ip_assignment_type' => 'none',
                            'ipv4_address' => null,
                            'ipv4_gateway' => null,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $lanUpdate = [
                            'physical_device' => $lan,
                            'enabled' => 1,
                            'updated_at' => $now,
                        ];
                        if ($factoryReset || empty($lanBefore['ip_assignment_type']) || $lanBefore['ip_assignment_type'] === 'none') {
                            $lanUpdate['ip_assignment_type'] = 'static';
                            $lanUpdate['ipv4_address'] = '10.10.10.10';
                            $lanUpdate['ipv4_netmask'] = 24;
                            $lanUpdate['ipv4_gateway'] = null;
                        }
                        $db->table('interfaces')->where('id', 'lan')->update($lanUpdate);
                    }

                    $existingOptional = $db->table('interfaces')->like('id', 'opt', 'after')->get()->getResultArray();
                    foreach ($existingOptional as $row) {
                        $id = strtolower((string) $row['id']);
                        if (! array_key_exists($id, $optional)) {
                            $db->table('interfaces')->where('id', $id)->update([
                                'physical_device' => '',
                                'enabled' => 0,
                                'ip_assignment_type' => 'none',
                                'ipv4_address' => null,
                                'ipv4_netmask' => null,
                                'ipv4_gateway' => null,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                    foreach ($optional as $id => $device) {
                        $row = $db->table('interfaces')->where('id', $id)->get()->getRowArray();
                        $values = [
                            'name' => strtoupper($id),
                            'physical_device' => $device,
                            'enabled' => $device === '' ? 0 : 1,
                            'ip_assignment_type' => 'none',
                            'ipv4_address' => null,
                            'ipv4_netmask' => null,
                            'ipv4_gateway' => null,
                            'updated_at' => $now,
                        ];
                        if ($row) {
                            $db->table('interfaces')->where('id', $id)->update($values);
                        } else {
                            $db->table('interfaces')->insert($values + [
                                'id' => $id,
                                'mtu' => 1500,
                                'created_at' => $now,
                            ]);
                        }
                    }

                    return ['before' => $lanBefore, 'after' => $db->table('interfaces')->where('id', 'lan')->get()->getRowArray()];
                }, ['actor' => $actor, 'reason' => "console assign WAN {$wan} LAN " . ($lan !== '' ? $lan : 'none')]);

                $exit = $this->report($result);
                if (! empty($result['ok'])) {
                    self::syncNetworkInitJson($wan, $lan);
                }

                if (! $factoryReset) {
                    return $exit;
                }

                if (empty($result['ok'])) {
                    foreach ($bridgeRows as $bridge) {
                        $ipc->bridgeApply([
                            'id' => (string) $bridge['id'],
                            'slaves' => json_decode((string) $bridge['slaves'], true) ?: [],
                            'stp' => (int) $bridge['stp'],
                            'forward_delay' => (int) $bridge['forward_delay'],
                            'mtu' => (int) $bridge['mtu'],
                        ]);
                    }

                    return $exit;
                }
                if ($bridgeRows !== [] && $db->tableExists('interface_bridges')) {
                    $db->table('interface_bridges')->emptyTable();
                }
                $ifupdown = '/etc/network/interfaces';
                if (is_file($ifupdown)) {
                    $tmp = $ifupdown . '.micron-reset';
                    if (file_put_contents($tmp, "auto lo\niface lo inet loopback\n") !== false) {
                        @chmod($tmp, 0644);
                        @rename($tmp, $ifupdown);
                    }
                }

                return $exit;

            case 'set-device':
                $id = strtolower(trim((string) ($params[1] ?? '')));
                $device = trim((string) ($params[2] ?? ''));
                $swap = CLI::getOption('swap') !== null;
                if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id)
                    || ($device !== '' && strtolower($device) !== 'none'
                        && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $device))) {
                    CLI::error('usage: php spark network set-device <interface-id> <device|none> [--swap]');

                    return 2;
                }
                $device = strtolower($device) === 'none' ? '' : $device;

                $result = $tx->apply(static function ($db) use ($id, $device, $swap) {
                    $target = $db->table('interfaces')->where('id', $id)->get()->getRowArray();
                    if (! $target) {
                        throw new \RuntimeException("interface '{$id}' does not exist");
                    }
                    $oldDev = (string) ($target['physical_device'] ?? '');
                    $now = date('Y-m-d H:i:s');

                    if ($device !== '') {
                        $conflict = $db->table('interfaces')->where('physical_device', $device)->where('id !=', $id)->get()->getRowArray();
                        if ($conflict) {
                            if ($swap && $oldDev !== '') {
                                $db->table('interfaces')->where('id', $conflict['id'])->update([
                                    'physical_device' => $oldDev,
                                    'updated_at' => $now,
                                ]);
                            } else {
                                $db->table('interfaces')->where('id', $conflict['id'])->update([
                                    'physical_device' => '',
                                    'enabled' => 0,
                                    'updated_at' => $now,
                                ]);
                            }
                        }
                    }

                    $db->table('interfaces')->where('id', $id)->update([
                        'physical_device' => $device !== '' ? $device : null,
                        'enabled' => $device !== '' ? 1 : 0,
                        'updated_at' => $now,
                    ]);

                    return ['before' => $target, 'after' => $db->table('interfaces')->where('id', $id)->get()->getRowArray()];
                }, ['actor' => $actor, 'reason' => "console set-device {$id} {$device}"]);

                $exit = $this->report($result);
                if (! empty($result['ok'])) {
                    self::syncNetworkInitJson();
                }

                return $exit;

            case 'swap':
                $id1 = strtolower(trim((string) ($params[1] ?? '')));
                $id2 = strtolower(trim((string) ($params[2] ?? '')));
                if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id1) || ! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id2) || $id1 === $id2) {
                    CLI::error('usage: php spark network swap <interface-id-1> <interface-id-2>');

                    return 2;
                }

                $result = $tx->apply(static function ($db) use ($id1, $id2) {
                    $row1 = $db->table('interfaces')->where('id', $id1)->get()->getRowArray();
                    $row2 = $db->table('interfaces')->where('id', $id2)->get()->getRowArray();
                    if (! $row1 || ! $row2) {
                        throw new \RuntimeException("interfaces '{$id1}' and '{$id2}' must both exist to swap");
                    }
                    $dev1 = (string) ($row1['physical_device'] ?? '');
                    $dev2 = (string) ($row2['physical_device'] ?? '');
                    if ($dev1 === '' && $dev2 === '') {
                        throw new \RuntimeException("at least one interface must have an assigned physical device to swap");
                    }
                    $now = date('Y-m-d H:i:s');
                    $db->table('interfaces')->where('id', $id1)->update([
                        'physical_device' => '__swap_temp__',
                        'updated_at' => $now,
                    ]);
                    $db->table('interfaces')->where('id', $id2)->update([
                        'physical_device' => $dev1 !== '' ? $dev1 : null,
                        'updated_at' => $now,
                    ]);
                    $db->table('interfaces')->where('id', $id1)->update([
                        'physical_device' => $dev2 !== '' ? $dev2 : null,
                        'updated_at' => $now,
                    ]);

                    return ['before' => $row1, 'after' => $db->table('interfaces')->where('id', $id1)->get()->getRowArray()];
                }, ['actor' => $actor, 'reason' => "console swap {$id1} <-> {$id2}"]);

                $exit = $this->report($result);
                if (! empty($result['ok'])) {
                    self::syncNetworkInitJson();
                }

                return $exit;

            case 'set-wan':
                $device = trim((string) ($params[1] ?? ''));
                $mode = strtolower(trim((string) ($params[2] ?? 'dhcp')));
                if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $device)
                    || ! in_array($mode, ['dhcp', 'none', 'keep', 'static', 'pppoe'], true)) {
                    CLI::error('usage: php spark network set-wan <device> [dhcp|none|keep]');

                    return 2;
                }

                $result = $tx->apply(static function ($db) use ($device, $mode) {
                    $before = $db->table('interfaces')->where('id', 'wan')->get()->getRowArray();
                    if (! $before) {
                        throw new \RuntimeException('WAN interface does not exist');
                    }
                    $oldDev = (string) ($before['physical_device'] ?? '');
                    $now = date('Y-m-d H:i:s');

                    $conflict = $db->table('interfaces')->where('physical_device', $device)->where('id !=', 'wan')->get()->getRowArray();
                    if ($conflict) {
                        $db->table('interfaces')->where('id', $conflict['id'])->update([
                            'physical_device' => $oldDev !== '' ? $oldDev : '',
                            'updated_at' => $now,
                        ]);
                    }

                    $changes = [
                        'physical_device' => $device,
                        'enabled' => 1,
                        'updated_at' => $now,
                    ];
                    if ($mode !== 'keep') {
                        $changes['ip_assignment_type'] = $mode;
                        if ($mode === 'dhcp' || $mode === 'none') {
                            $changes['ipv4_address'] = null;
                            $changes['ipv4_gateway'] = null;
                        }
                    }
                    $db->table('interfaces')->where('id', 'wan')->update($changes);

                    return ['before' => $before, 'after' => $db->table('interfaces')->where('id', 'wan')->get()->getRowArray()];
                }, ['actor' => $actor, 'reason' => "console set-wan {$device} {$mode}"]);

                $exit = $this->report($result);
                if (! empty($result['ok'])) {
                    self::syncNetworkInitJson();
                }

                return $exit;

            case 'set-mode':
                $id = strtolower(trim((string) ($params[1] ?? '')));
                $mode = strtolower(trim((string) ($params[2] ?? '')));
                if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) || ! in_array($mode, ['dhcp', 'none'], true)) {
                    CLI::error('usage: php spark network set-mode <interface-id> <dhcp|none>');

                    return 2;
                }

                return $this->report($tx->apply(static function ($db) use ($id, $mode) {
                    $before = $db->table('interfaces')->where('id', $id)->get()->getRowArray();
                    if (! $before || trim((string) ($before['physical_device'] ?? '')) === '') {
                        throw new \RuntimeException("interface '{$id}' has no assigned physical device");
                    }
                    $now = date('Y-m-d H:i:s');
                    $db->table('interfaces')->where('id', $id)->update([
                        'enabled' => 1,
                        'ip_assignment_type' => $mode,
                        'ipv4_address' => null,
                        'ipv4_netmask' => null,
                        'ipv4_gateway' => null,
                        'updated_at' => $now,
                    ]);
                    if ($id === 'lan' && $mode === 'dhcp' && $db->tableExists('dhcp_server_settings')) {
                        $db->table('dhcp_server_settings')->where('interface_id', 'lan')->update([
                            'enabled' => 0,
                            'updated_at' => $now,
                        ]);
                    }

                    return ['before' => $before, 'after' => $db->table('interfaces')->where('id', $id)->get()->getRowArray()];
                }, ['actor' => $actor, 'reason' => "console set-mode {$id} {$mode}"]));

            case 'set-lan':
                $device = trim((string) ($params[1] ?? ''));
                $value = trim((string) ($params[2] ?? ''));
                $dhcp = strtolower($value) === 'dhcp';
                $cidr = $dhcp ? null : NetworkPlan::cidr($value);
                if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/', $device) || (! $dhcp && $cidr === null)) {
                    CLI::error('usage: php spark network set-lan <device> <dhcp|a.b.c.d/nn>');

                    return 2;
                }

                $result = $tx->apply(static function ($db) use ($device, $dhcp, $cidr) {
                    $before = $db->table('interfaces')->where('id', 'lan')->get()->getRowArray();
                    if (! $before) {
                        throw new \RuntimeException('LAN interface does not exist');
                    }
                    $oldDev = (string) ($before['physical_device'] ?? '');
                    $now = date('Y-m-d H:i:s');

                    // Conflict resolution: check if another interface holds this device
                    $conflict = $db->table('interfaces')->where('physical_device', $device)->where('id !=', 'lan')->get()->getRowArray();
                    if ($conflict) {
                        $db->table('interfaces')->where('id', $conflict['id'])->update([
                            'physical_device' => $oldDev !== '' ? $oldDev : '',
                            'updated_at' => $now,
                        ]);
                    }

                    $changes = [
                        'physical_device' => $device,
                        'enabled' => 1,
                        'ip_assignment_type' => $dhcp ? 'dhcp' : 'static',
                        'ipv4_address' => $dhcp ? null : $cidr[2],
                        'ipv4_netmask' => $dhcp ? null : $cidr[1],
                        'ipv4_gateway' => null,
                        'updated_at' => $now,
                    ];
                    $db->table('interfaces')->where('id', 'lan')->update($changes);
                    if ($dhcp && $db->tableExists('dhcp_server_settings')) {
                        $db->table('dhcp_server_settings')->where('interface_id', 'lan')->update([
                            'enabled' => 0,
                            'updated_at' => $now,
                        ]);
                    }

                    return ['before' => $before, 'after' => $db->table('interfaces')->where('id', 'lan')->get()->getRowArray()];
                }, ['actor' => $actor, 'reason' => 'console set-lan ' . $device . ' ' . $value]);

                $exit = $this->report($result);
                if (! empty($result['ok'])) {
                    self::syncNetworkInitJson();
                }

                return $exit;

            case 'boot-files':
                return $this->bootFiles((string) ($params[1] ?? ''));

            case 'set-address':
                $id   = strtolower((string) ($params[1] ?? ''));
                $cidr = (string) ($params[2] ?? '');
                $gw   = (string) ($params[3] ?? '');
                $c    = NetworkPlan::cidr($cidr);
                if ($id === '' || $c === null) {
                    CLI::error('usage: php spark network set-address <interface-id> <a.b.c.d/nn> [gateway] [--offline] [--keep-references]');

                    return 2;
                }
                $mutate = static function ($db) use ($id, $c, $gw) {
                    $before = $db->table('interfaces')->where('id', $id)->get()->getRowArray();
                    if (! $before) {
                        throw new \RuntimeException("no interface with id '{$id}'");
                    }
                    $db->table('interfaces')->where('id', $id)->update([
                        'ip_assignment_type' => 'static',
                        'ipv4_address'       => $c[2],
                        'ipv4_netmask'       => $c[1],
                        'ipv4_gateway'       => $gw !== '' ? $gw : ($before['ip_assignment_type'] === 'static' ? $before['ipv4_gateway'] : null),
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ]);

                    return ['before' => $before, 'after' => $db->table('interfaces')->where('id', $id)->get()->getRowArray()];
                };
                if (CLI::getOption('offline') !== null) {
                    return $this->offline($mutate, CLI::getOption('keep-references') === null);
                }

                return $this->report($tx->apply($mutate, ['update_references' => CLI::getOption('keep-references') === null, 'actor' => $actor,
                    'reason' => "console set-address {$id} {$cidr}"]));

            case 'ipv6':
                $want = strtolower((string) ($params[1] ?? 'status'));
                if ($want === 'status') {
                    $plan = (new NetworkPlan())->build();
                    $st   = $ipc->call('network.status')['result'] ?? [];
                    CLI::write('IPv6 configured: ' . ($plan['ipv6_enabled'] ? 'on' : 'off') . ' | kernel: '
                        . (($st['ipv6_effective'] ?? null) === null ? 'unknown' : ($st['ipv6_effective'] ? 'on' : 'off')));

                    return 0;
                }
                if (! in_array($want, ['on', 'off'], true)) {
                    CLI::error('usage: php spark network ipv6 <on|off|status>');

                    return 2;
                }

                return $this->report($tx->apply(static function ($db) use ($want) {
                    $db->table('system_settings')->where('id', 1)->update(['ipv6_enabled' => $want === 'on' ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);

                    return null;
                }, ['actor' => $actor, 'reason' => "console ipv6 {$want}"]));

            case 'confirm':
                $r = $tx->confirm((string) ($params[1] ?? ''));
                CLI::write($r['message'], $r['ok'] ? 'green' : 'red');

                return $r['ok'] ? 0 : 1;

            case 'rollback':
                $r = $tx->rollback();
                CLI::write($r['message'], $r['ok'] ? 'green' : 'red');

                return $r['ok'] ? 0 : 1;

            case 'reconcile':
                $msg = $tx->reconcile();
                CLI::write($msg ?? 'Nothing to reconcile.');

                return 0;
        }

        CLI::error('Unknown action. ' . $this->usage);

        return 2;
    }

    /**
     * Database-only change with the same dependency sync, for boot when the
     * daemon is not running yet: micron-network applies the result right after.
     */
    private function offline(callable $mutate, bool $updateReferences): int
    {
        $db = \Config\Database::connect();
        $db->transBegin();
        try {
            $diff = $mutate($db);
            $sync = is_array($diff) ? (new \App\Libraries\Network\AddressChangeSync($db))->sync($diff['before'], $diff['after'], $updateReferences)
                : ['updated' => [], 'review' => []];
            $errors = NetworkPlan::validate((new NetworkPlan($db))->build());
            if ($errors !== []) {
                $db->transRollback();
                foreach ($errors as $e) {
                    CLI::write('  error: ' . $e, 'red');
                }

                return 1;
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            CLI::error($e->getMessage());

            return 1;
        }
        CLI::write('Database updated (not applied).', 'green');
        foreach ($sync['updated'] as $u) {
            CLI::write("  updated {$u['what']}: {$u['from']} → {$u['to']}");
        }
        foreach ($sync['review'] as $u) {
            CLI::write("  review  {$u['what']} ({$u['value']}): {$u['reason']}", 'yellow');
        }

        return 0;
    }

    /**
     * The three generated artefacts boot needs, from the database alone.
     * Exit 0 when all were written; the caller validates each before use.
     */
    private function bootFiles(string $dir): int
    {
        if ($dir === '' || ! is_dir($dir) || ! is_writable($dir)) {
            CLI::error('usage: php spark network boot-files <writable-directory>');

            return 2;
        }
        $plan = (new NetworkPlan())->build();
        file_put_contents($dir . '/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/nftables.conf', (new \App\Libraries\NftablesCompiler())->compile());
        $kea = new \App\Libraries\KeaDhcpCompiler();
        file_put_contents($dir . '/kea-dhcp4.conf', $kea->compile());
        $dhcpOn = false;
        $cfg    = json_decode((string) file_get_contents($dir . '/kea-dhcp4.conf'), true);
        if (is_array($cfg) && ! empty($cfg['Dhcp4']['subnet4'])) {
            $dhcpOn = true;
        }
        file_put_contents($dir . '/dhcp4.enabled', $dhcpOn ? "1\n" : "0\n");
        file_put_contents($dir . '/ipv6.enabled', $plan['ipv6_enabled'] ? "1\n" : "0\n");
        CLI::write("wrote {$dir}/plan.json, nftables.conf, kea-dhcp4.conf (DHCPv4 " . ($dhcpOn ? 'on' : 'off') . ', IPv6 ' . ($plan['ipv6_enabled'] ? 'on' : 'off') . ')');

        return 0;
    }

    public static function syncNetworkInitJson(?string $wanDev = null, ?string $lanDev = null): void
    {
        try {
            $db = \Config\Database::connect();
            $wan = $wanDev ?? ($db->table('interfaces')->where('id', 'wan')->get()->getRowArray()['physical_device'] ?? '');
            $lan = $lanDev ?? ($db->table('interfaces')->where('id', 'lan')->get()->getRowArray()['physical_device'] ?? '');
            $path = '/etc/micron/network_init.json';
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $payload = [
                'wan_interface' => (string) $wan,
                'lan_interface' => (string) $lan,
                'configured'    => ($wan !== '' || $lan !== ''),
            ];
            @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        } catch (\Throwable) {
        }
    }

    private function report(array $r): int
    {
        CLI::write($r['message'], $r['ok'] ? 'green' : 'red');
        foreach ($r['errors'] as $e) {
            CLI::write('  error: ' . $e, 'red');
        }
        foreach ($r['updated'] as $u) {
            CLI::write("  updated {$u['what']}: {$u['from']} → {$u['to']}");
        }
        foreach ($r['review'] as $u) {
            CLI::write("  review  {$u['what']} ({$u['value']}): {$u['reason']}", 'yellow');
        }
        foreach ($r['warnings'] as $w) {
            CLI::write('  warning: ' . $w, 'yellow');
        }
        if (! empty($r['tx'])) {
            CLI::write("  confirm with: php spark network confirm {$r['tx']}", 'yellow');
        }

        return $r['ok'] ? 0 : 1;
    }
}

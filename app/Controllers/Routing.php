<?php

namespace App\Controllers;

use App\Libraries\IpcClient;

/**
 * Routing Controller
 *
 * Handles: Static routes, FIB inspection, BGP (FRRouting), OSPF,
 *          BFD, VRF, PBR, and Multi-WAN gateway / group management.
 */
class Routing extends BaseController
{
    private IpcClient $ipc;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request,
                                   \CodeIgniter\HTTP\ResponseInterface $response,
                                   \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->ipc = \Config\Services::ipc();
    }

    // ================================================================
    // INDEX — Static Routes + FIB + all dynamic routing data (tabbed)
    // ================================================================

    public function index(): string
    {
        $db = \Config\Database::connect();

        // ── Kernel FIB (mock until IPC live) ──
        $kernelRoutes = [
            ['destination' => 'default',         'gateway' => '192.168.1.254', 'interface' => 'eth0', 'flags' => 'UG',  'metric' => 100, 'proto' => 'static'],
            ['destination' => '192.168.1.0/24',  'gateway' => '0.0.0.0',       'interface' => 'eth1', 'flags' => 'U',   'metric' => 0,   'proto' => 'kernel'],
            ['destination' => '10.8.0.0/24',     'gateway' => '0.0.0.0',       'interface' => 'tun0', 'flags' => 'U',   'metric' => 0,   'proto' => 'static'],
            ['destination' => '10.9.0.0/24',     'gateway' => '0.0.0.0',       'interface' => 'wg0',  'flags' => 'U',   'metric' => 0,   'proto' => 'static'],
            ['destination' => '10.0.0.0/8',      'gateway' => '10.0.0.1',      'interface' => 'eth0', 'flags' => 'UG',  'metric' => 20,  'proto' => 'bgp'],
            ['destination' => '172.16.0.0/12',   'gateway' => '192.168.1.1',   'interface' => 'eth0', 'flags' => 'UG',  'metric' => 110, 'proto' => 'ospf'],
        ];

        // ── Seed static route if empty ──
        if ($db->table('static_routes')->countAllResults() === 0) {
            $db->table('static_routes')->insert([
                'id'          => 'rt_' . bin2hex(random_bytes(6)),
                'destination' => '10.200.0.0/16',
                'gateway'     => '192.168.1.254',
                'interface'   => 'eth0',
                'metric'      => 10,
                'enabled'     => 1,
                'description' => 'Branch Office Datacenter VPN Subnet',
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        // ── Seed OSPF default settings if missing ──
        if ($db->table('frr_ospf_settings')->countAllResults() === 0) {
            $db->table('frr_ospf_settings')->insert([
                'id'                     => 'default',
                'enabled'                => 1,
                'router_id'              => '192.168.1.1',
                'log_adj_changes'        => 1,
                'spf_hold_time'          => 1000,
                'spf_delay'              => 200,
                'default_area'           => '0.0.0.0',
                'reference_bandwidth'    => 1000,
                'max_paths'              => 4,
                'passive_default'        => 0,
                'redistribute_connected' => 1,
                'redistribute_static'    => 1,
                'redistribute_metric_type'=> 2,
                'updated_at'             => date('Y-m-d H:i:s'),
            ]);
        } else {
            $currOspf = $db->table('frr_ospf_settings')->where('id', 'default')->get()->getRowArray();
            if ($currOspf && empty($currOspf['router_id'])) {
                $db->table('frr_ospf_settings')->where('id', 'default')->update([
                    'router_id'              => '192.168.1.1',
                    'log_adj_changes'        => 1,
                    'spf_hold_time'          => 1000,
                    'spf_delay'              => 200,
                    'default_area'           => '0.0.0.0',
                    'reference_bandwidth'    => 1000,
                    'max_paths'              => 4,
                    'redistribute_connected' => 1,
                    'redistribute_static'    => 1,
                    'redistribute_metric_type'=> 2,
                ]);
            }
        }

        // ── Seed OSPF Areas if empty ──
        if ($db->table('frr_ospf_areas')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_ospf_areas')->insertBatch([
                [
                    'id'             => 'oa_backbone_000',
                    'area_id'        => '0.0.0.0',
                    'description'    => 'Backbone Area (Area 0)',
                    'area_type'      => 'normal',
                    'default_cost'   => null,
                    'abr_shortcut'   => 'default',
                    'auth_type'      => 'none',
                    'no_summary'     => 0,
                    'summary_ranges' => json_encode([
                        ['prefix' => '10.0.0.0/8', 'not_advertise' => false, 'cost' => null, 'substitute' => null],
                        ['prefix' => '192.168.0.0/16', 'not_advertise' => false, 'cost' => null, 'substitute' => null],
                    ]),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
                [
                    'id'             => 'oa_branch_001',
                    'area_id'        => '0.0.0.1',
                    'description'    => 'Branch Offices & Remote Aggregation',
                    'area_type'      => 'stub',
                    'default_cost'   => 10,
                    'abr_shortcut'   => 'default',
                    'auth_type'      => 'none',
                    'no_summary'     => 0,
                    'summary_ranges' => json_encode([
                        ['prefix' => '172.16.0.0/16', 'not_advertise' => false, 'cost' => null, 'substitute' => null],
                    ]),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
            ]);
        }

        // ── Seed OSPF Interfaces if empty ──
        if ($db->table('frr_ospf_ifaces')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_ospf_ifaces')->insertBatch([
                [
                    'id'                  => 'oif_lan_001',
                    'interface'           => 'eth1',
                    'description'         => 'Corporate LAN Core (LAN)',
                    'network_type'        => 'broadcast',
                    'area'                => '0.0.0.0',
                    'metric'              => 10,
                    'passive'             => 0,
                    'ignore_mtu'          => 0,
                    'accept_filter'       => 0,
                    'auth_type'           => 'none',
                    'auth_password'       => null,
                    'router_priority'     => 100,
                    'retransmit_interval' => 5,
                    'hello_interval'      => 10,
                    'dead_interval'       => 40,
                    'minimal_hello'       => null,
                    'transmit_delay'      => 1,
                    'bfd_enabled'         => 1,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ],
                [
                    'id'                  => 'oif_wan_001',
                    'interface'           => 'eth0',
                    'description'         => 'Inter-Router Enterprise WAN Link',
                    'network_type'        => 'point-to-point',
                    'area'                => '0.0.0.0',
                    'metric'              => 20,
                    'passive'             => 0,
                    'ignore_mtu'          => 0,
                    'accept_filter'       => 0,
                    'auth_type'           => 'none',
                    'auth_password'       => null,
                    'router_priority'     => 1,
                    'retransmit_interval' => 5,
                    'hello_interval'      => 10,
                    'dead_interval'       => 40,
                    'minimal_hello'       => null,
                    'transmit_delay'      => 1,
                    'bfd_enabled'         => 1,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ],
            ]);
        }

        // ── Seed OSPF Networks if empty ──
        if ($db->table('frr_ospf_networks')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_ospf_networks')->insertBatch([
                [
                    'id'          => 'ospf_net_lan',
                    'network'     => '192.168.1.0/24',
                    'area'        => '0.0.0.0',
                    'area_type'   => 'normal',
                    'cost'        => 10,
                    'description' => 'Corporate Office LAN Subnet',
                    'enabled'     => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'ospf_net_bb',
                    'network'     => '10.100.0.0/24',
                    'area'        => '0.0.0.0',
                    'area_type'   => 'normal',
                    'cost'        => 20,
                    'description' => 'Core Switch & Firewall Transit Link',
                    'enabled'     => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'ospf_net_branch',
                    'network'     => '172.16.10.0/24',
                    'area'        => '0.0.0.1',
                    'area_type'   => 'stub',
                    'cost'        => 50,
                    'description' => 'Branch Office Edge Transit Subnet',
                    'enabled'     => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
            ]);
        }

        // ── Seed OSPF Neighbors if empty ──
        if ($db->table('frr_ospf_neighbors')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_ospf_neighbors')->insertBatch([
                [
                    'id'           => 'on_core_01',
                    'neighbor_ip'  => '10.100.0.2',
                    'area'         => '0.0.0.0',
                    'priority'     => 1,
                    'poll_interval'=> 120,
                    'description'  => 'Core L3 Backbone Switch',
                    'enabled'      => 1,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
                [
                    'id'           => 'on_branch_01',
                    'neighbor_ip'  => '172.16.10.254',
                    'area'         => '0.0.0.1',
                    'priority'     => 0,
                    'poll_interval'=> 120,
                    'description'  => 'Branch Edge Gateway',
                    'enabled'      => 1,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
            ]);
        }

        // ── Seed OSPF policy (prefix-lists & route-maps) if empty ──
        if ($db->table('frr_prefix_lists')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_prefix_lists')->insertBatch([
                [
                    'id'          => 'pl_banking_0001',
                    'name'        => 'Banking_Applications',
                    'description' => 'Banking application host prefixes redistributed into OSPF',
                    'rules'       => json_encode([
                        ['seq' => 10,  'action' => 'permit', 'prefix' => '10.11.83.0/24',   'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 20,  'action' => 'permit', 'prefix' => '10.29.1.171/32',  'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 30,  'action' => 'permit', 'prefix' => '10.29.3.51/32',   'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 40,  'action' => 'permit', 'prefix' => '10.28.3.51/32',   'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 50,  'action' => 'permit', 'prefix' => '10.28.1.171/32',  'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 60,  'action' => 'permit', 'prefix' => '10.100.22.82/32', 'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 70,  'action' => 'permit', 'prefix' => '10.100.21.65/32', 'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 80,  'action' => 'permit', 'prefix' => '10.120.22.100/32','ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 90,  'action' => 'permit', 'prefix' => '10.120.21.68/32', 'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 100, 'action' => 'permit', 'prefix' => '10.100.22.229/32','ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 110, 'action' => 'permit', 'prefix' => '10.120.22.221/32','ge' => null, 'le' => null, 'description' => ''],
                    ]),
                    'enabled'    => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'          => 'pl_banking_dr_0001',
                    'name'        => 'Banking_DR_Applications',
                    'description' => 'Disaster-recovery subnet',
                    'rules'       => json_encode([
                        ['seq' => 100, 'action' => 'permit', 'prefix' => '10.9.99.96/27', 'ge' => null, 'le' => null, 'description' => ''],
                    ]),
                    'enabled'    => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'          => 'pl_iftas_ekuber_0001',
                    'name'        => 'IFTAS_EKUBER',
                    'description' => 'IFTAS / EKUBER application hosts',
                    'rules'       => json_encode([
                        ['seq' => 10, 'action' => 'permit', 'prefix' => '10.29.1.197/32', 'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 20, 'action' => 'permit', 'prefix' => '10.29.1.143/32', 'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 30, 'action' => 'permit', 'prefix' => '10.28.1.170/32', 'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 40, 'action' => 'permit', 'prefix' => '10.28.1.143/32', 'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 50, 'action' => 'permit', 'prefix' => '10.35.1.28/32',  'ge' => null, 'le' => null, 'description' => ''],
                        ['seq' => 60, 'action' => 'permit', 'prefix' => '10.35.2.143/32', 'ge' => null, 'le' => null, 'description' => ''],
                    ]),
                    'enabled'    => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        if ($db->table('frr_route_maps')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_route_maps')->insert([
                'id'          => 'rm_banking_0001',
                'name'        => 'Banking_Applications',
                'description' => 'Permit the Banking_Applications prefix-list into OSPF',
                'rules'       => json_encode([
                    ['seq' => 10, 'action' => 'permit', 'match_prefix_list' => 'Banking_Applications', 'set_metric' => null, 'set_metric_type' => null, 'set_tag' => null, 'description' => ''],
                ]),
                'enabled'    => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── Seed OSPF route filters if empty ──
        if ($db->table('frr_ospf_distribute_lists')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_ospf_distribute_lists')->insert([
                'id'          => 'odl_banking_0001',
                'prefix_list' => 'Banking_Applications',
                'direction'   => 'in',
                'interface'   => null,
                'description' => 'Accept only banking prefixes from OSPF neighbors',
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        if ($db->table('frr_ospf_area_filters')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_ospf_area_filters')->insert([
                'id'          => 'oaf_branch_0001',
                'area_id'     => '0.0.0.1',
                'prefix_list' => 'IFTAS_EKUBER',
                'direction'   => 'in',
                'description' => 'Limit inter-area LSAs advertised into the branch area',
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // ── Seed RIP settings if missing ──
        if ($db->table('frr_rip_settings')->countAllResults() === 0) {
            $db->table('frr_rip_settings')->insert([
                'id'                     => 'default',
                'enabled'                => 1,
                'version'                => 2,
                'redistribute_connected' => 1,
                'redistribute_static'    => 1,
                'redistribute_bgp'       => 0,
                'redistribute_ospf'      => 0,
                'default_information'    => 0,
                'distance'               => null,
                'maximum_paths'          => 4,
                'timers_update'          => 30,
                'timers_timeout'         => 180,
                'timers_garbage'         => 120,
                'passive_default'        => 0,
                'auth_mode'              => 'none',
                'auth_password'          => null,
                'updated_at'             => date('Y-m-d H:i:s'),
            ]);
        }

        // ── Seed RIP networks if empty ──
        if ($db->table('frr_rip_networks')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_rip_networks')->insertBatch([
                [
                    'id'          => 'rn_lan_0001',
                    'network'     => '192.168.1.0/24',
                    'description' => 'Corporate Office LAN Subnet',
                    'enabled'     => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'rn_bb_0001',
                    'network'     => '10.100.0.0/24',
                    'description' => 'Core Switch & Firewall Transit Link',
                    'enabled'     => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
            ]);
        }

        // ── Seed RIP neighbors if empty ──
        if ($db->table('frr_rip_neighbors')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_rip_neighbors')->insert([
                'id'          => 'rb_core_0001',
                'neighbor_ip' => '10.100.0.2',
                'description' => 'Core L3 Backbone Switch (unicast RIPv2)',
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // ── Seed RIP passive interfaces if empty ──
        if ($db->table('frr_rip_passive_interfaces')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_rip_passive_interfaces')->insert([
                'id'          => 'rp_lan_0001',
                'interface'   => 'eth1',
                'description' => 'Suppress RIP updates toward the LAN (listen only)',
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // ── Seed IS-IS settings if missing ──
        if ($db->table('frr_isis_settings')->countAllResults() === 0) {
            $db->table('frr_isis_settings')->insert([
                'id'                     => 'default',
                'enabled'                => 1,
                'area_tag'               => 'DEFAULT',
                'net'                    => '49.0001.1921.6800.1001.00',
                'is_type'                => 'level-1-2',
                'metric_style'           => 'wide',
                'log_adjacency'          => 1,
                'lsp_gen_interval'       => 33,
                'spf_interval_l1'        => 50,
                'spf_interval_l2'        => 50,
                'max_lsp_lifetime'       => 1200,
                'redistribute_connected' => 1,
                'redistribute_static'    => 1,
                'redistribute_bgp'       => 0,
                'redistribute_ospf'      => 0,
                'auth_mode'              => 'none',
                'auth_password'          => null,
                'passive_default'        => 0,
                'updated_at'             => date('Y-m-d H:i:s'),
            ]);
        }

        // ── Seed IS-IS interfaces if empty ──
        if ($db->table('frr_isis_interfaces')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_isis_interfaces')->insertBatch([
                [
                    'id'               => 'ii_lan_0001',
                    'interface'        => 'eth1',
                    'description'      => 'Corporate LAN Core (broadcast)',
                    'circuit_type'     => 'level-1-2',
                    'network_type'     => 'broadcast',
                    'metric_l1'        => 10,
                    'metric_l2'        => 10,
                    'hello_interval'   => 10,
                    'hello_multiplier' => 3,
                    'csnp_interval'    => null,
                    'psnp_interval'    => null,
                    'password'         => null,
                    'bfd_enabled'      => 1,
                    'enabled'          => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ],
                [
                    'id'               => 'ii_wan_0001',
                    'interface'        => 'eth0',
                    'description'      => 'Inter-Router Enterprise WAN Link (p2p)',
                    'circuit_type'     => 'level-2-only',
                    'network_type'     => 'point-to-point',
                    'metric_l1'        => null,
                    'metric_l2'        => 20,
                    'hello_interval'   => 3,
                    'hello_multiplier' => 3,
                    'csnp_interval'    => null,
                    'psnp_interval'    => null,
                    'password'         => null,
                    'bfd_enabled'      => 1,
                    'enabled'          => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ],
            ]);
        }

        // ── Seed BGP default settings if missing ──
        if ($db->table('frr_bgp_settings')->countAllResults() === 0) {
            $db->table('frr_bgp_settings')->insert([
                'id'         => 'default',
                'enabled'    => 1,
                'local_as'   => 65001,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // ── Seed BFD Peers if empty ──
        if ($db->table('frr_bfd_peers')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('frr_bfd_peers')->insertBatch([
                [
                    'id'               => 'bfd_peer_core',
                    'peer_ip'          => '10.100.0.2',
                    'local_ip'         => '10.100.0.1',
                    'interface'        => 'eth0',
                    'receive_interval' => 300,
                    'transmit_interval'=> 300,
                    'detect_multiplier'=> 3,
                    'echo_mode'        => 1,
                    'description'      => 'Core Switch OSPF/BGP Fast-Detection Session',
                    'enabled'          => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ],
                [
                    'id'               => 'bfd_peer_gw',
                    'peer_ip'          => '192.168.1.254',
                    'local_ip'         => '192.168.1.1',
                    'interface'        => 'eth1',
                    'receive_interval' => 200,
                    'transmit_interval'=> 200,
                    'detect_multiplier'=> 3,
                    'echo_mode'        => 0,
                    'description'      => 'Upstream Gateway Health Probe',
                    'enabled'          => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ],
            ]);
        }

        $staticRoutes   = $db->table('static_routes')->orderBy('destination', 'ASC')->get()->getResultArray();
        $interfaces     = $db->table('interfaces')->orderBy('name', 'ASC')->get()->getResultArray();
        $bgpNeighbors   = $db->table('frr_bgp_neighbors')->orderBy('neighbor_ip', 'ASC')->get()->getResultArray();
        $bgpSettings    = $db->table('frr_bgp_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $ospfSettings   = $db->table('frr_ospf_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $ospfAreas      = $db->table('frr_ospf_areas')->orderBy('area_id', 'ASC')->get()->getResultArray();
        $ospfIfaces     = $db->table('frr_ospf_ifaces')->orderBy('interface', 'ASC')->get()->getResultArray();
        $ospfNetworks   = $db->table('frr_ospf_networks')->orderBy('area', 'ASC')->orderBy('network', 'ASC')->get()->getResultArray();
        $ospfNeighbors  = $db->table('frr_ospf_neighbors')->orderBy('neighbor_ip', 'ASC')->get()->getResultArray();
        $prefixLists    = $db->table('frr_prefix_lists')->orderBy('name', 'ASC')->get()->getResultArray();
        $routeMaps      = $db->table('frr_route_maps')->orderBy('name', 'ASC')->get()->getResultArray();

        // Where each prefix-list / route-map is referenced, so the Policy tab can
        // show usage and refuse to delete something still in use.
        $policyRefs     = $this->buildPolicyReferences();
        $prefixListRefs = $policyRefs['prefixLists'];
        $routeMapRefs   = $policyRefs['routeMaps'];
        $ospfDistributeLists = $db->table('frr_ospf_distribute_lists')->orderBy('prefix_list', 'ASC')->get()->getResultArray();
        $ospfAreaFilters     = $db->table('frr_ospf_area_filters')->orderBy('area_id', 'ASC')->get()->getResultArray();
        $ospfPolicyPreview   = self::compileOspfPolicy($prefixLists, $routeMaps, $ospfDistributeLists, $ospfAreaFilters);
        $ospfStaleRefs       = self::staleOspfReferences($prefixLists, $routeMaps, $ospfDistributeLists, $ospfAreaFilters, $ospfSettings);
        $ripSettings         = $db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $ripNetworks         = $db->table('frr_rip_networks')->orderBy('network', 'ASC')->get()->getResultArray();
        $ripNeighbors        = $db->table('frr_rip_neighbors')->orderBy('neighbor_ip', 'ASC')->get()->getResultArray();
        $ripPassiveIfaces    = $db->table('frr_rip_passive_interfaces')->orderBy('interface', 'ASC')->get()->getResultArray();
        $isisSettings        = $db->table('frr_isis_settings')->where('id', 'default')->get()->getRowArray() ?? [];
        $isisInterfaces      = $db->table('frr_isis_interfaces')->orderBy('interface', 'ASC')->get()->getResultArray();
        $bfdPeers       = $db->table('frr_bfd_peers')->orderBy('peer_ip', 'ASC')->get()->getResultArray();
        $vrfs           = $db->table('routing_vrfs')->orderBy('name', 'ASC')->get()->getResultArray();
        $pbrRules       = $db->table('routing_pbr_rules')->orderBy('priority', 'ASC')->get()->getResultArray();

        // The address objects a PBR rule can match on are no longer loaded here:
        // the pickers ask `routing/objects/search` for one page at a time, so
        // this screen does not pull every object into the response.

        // ── Seed Multi-WAN Gateways if empty ──
        if ($db->table('wan_gateways')->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('wan_gateways')->insertBatch([
                [
                    'id'           => 'gw_wan1_fiber',
                    'name'         => 'WAN1_Fiber_Primary',
                    'interface'    => 'eth0',
                    'gateway_ip'   => '192.168.1.254',
                    'monitor_ip'   => '1.1.1.1',
                    'weight'       => 1,
                    'priority'     => 1,
                    'latency_warn' => 150,
                    'latency_down' => 500,
                    'loss_warn'    => 5,
                    'loss_down'    => 20,
                    'probe_iface'  => 'eth0',
                    'description'  => 'Gigabit Fiber ISP Upstream Gateway (Primary)',
                    'enabled'      => 1,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
                [
                    'id'           => 'gw_wan2_cable',
                    'name'         => 'WAN2_Broadband_Backup',
                    'interface'    => 'eth1',
                    'gateway_ip'   => '10.0.0.1',
                    'monitor_ip'   => '8.8.8.8',
                    'weight'       => 2,
                    'priority'     => 2,
                    'latency_warn' => 200,
                    'latency_down' => 600,
                    'loss_warn'    => 10,
                    'loss_down'    => 25,
                    'probe_iface'  => 'eth1',
                    'description'  => 'Secondary Coaxial / LTE Backup Gateway',
                    'enabled'      => 1,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
            ]);

            $db->table('wan_gateway_groups')->insert([
                'id'          => 'gwg_failover_highavail',
                'name'        => 'Failover_Tier1_Tier2',
                'strategy'    => 'failover',
                'members'     => json_encode([
                    ['id' => 'gw_wan1_fiber', 'tier' => 1, 'weight' => 1],
                    ['id' => 'gw_wan2_cable', 'tier' => 2, 'weight' => 1],
                ]),
                'trigger'     => 'packetloss',
                'description' => 'Automated failover: routes out WAN1 until loss > 5% then switches to WAN2',
            ]);
        }

        $wanGateways    = $db->table('wan_gateways')->orderBy('priority', 'ASC')->get()->getResultArray();
        $wanGroups      = $db->table('wan_gateway_groups')->orderBy('name', 'ASC')->get()->getResultArray();

        // Attach gateway latency telemetry
        foreach ($wanGateways as &$gw) {
            $isPri = str_contains($gw['name'], 'Fiber') || $gw['priority'] === 1;
            $gw['telemetry'] = [
                'rtt'     => $isPri ? 11.4 : 28.6,
                'stddev'  => $isPri ? 1.2 : 3.4,
                'loss'    => 0.0,
                'status'  => 'online',
            ];
        }
        unset($gw);

        // OSPF live status mock (would come from IPC in production)
        $ospfLiveNeighbors = [
            ['neighbor_id' => '10.10.10.10', 'priority' => 1, 'state' => 'Full/DR',     'dead_time' => '34.90s', 'address' => '172.16.100.1', 'interface' => 're0:172.16.100.4', 'rxmtl' => 0, 'rqstl' => 0, 'dbsml' => 0],
            ['neighbor_id' => '9.9.9.9',     'priority' => 1, 'state' => 'Full/Backup', 'dead_time' => '32.76s', 'address' => '172.16.100.3', 'interface' => 're0:172.16.100.4', 'rxmtl' => 0, 'rqstl' => 0, 'dbsml' => 0],
        ];

        $frrStatus = [
            'running'   => true,
            'version'   => 'FRRouting 9.1',
            'uptime'    => '3d 14h 22m',
            'bgp_as'    => $bgpSettings['local_as'] ?? 65001,
            'router_id' => $bgpSettings['router_id'] ?? ($ospfSettings['router_id'] ?? '192.168.1.1'),
        ];

        return view('routing/index', [
            'title'             => 'Dynamic Routing & Multi-WAN — MicronOS',
            'breadcrumb'        => 'Routing',
            'kernelRoutes'      => $kernelRoutes,
            'staticRoutes'      => $staticRoutes,
            'interfaces'        => $interfaces,
            'bgpNeighbors'      => $bgpNeighbors,
            'bgpSettings'       => $bgpSettings,
            'ospfSettings'      => $ospfSettings,
            'ospfAreas'         => $ospfAreas,
            'ospfIfaces'        => $ospfIfaces,
            'ospfNetworks'      => $ospfNetworks,
            'ospfNeighbors'     => $ospfNeighbors,
            'ospfLiveNeighbors' => $ospfLiveNeighbors,
            'prefixLists'       => $prefixLists,
            'routeMaps'         => $routeMaps,
            'prefixListRefs'    => $prefixListRefs,
            'routeMapRefs'      => $routeMapRefs,
            'ospfDistributeLists' => $ospfDistributeLists,
            'ospfAreaFilters'     => $ospfAreaFilters,
            'ospfPolicyPreview'   => $ospfPolicyPreview,
            'ospfStaleRefs'       => $ospfStaleRefs,
            'ripSettings'       => $ripSettings,
            'ripNetworks'       => $ripNetworks,
            'ripNeighbors'      => $ripNeighbors,
            'ripPassiveIfaces'  => $ripPassiveIfaces,
            'isisSettings'      => $isisSettings,
            'isisInterfaces'    => $isisInterfaces,
            'bfdPeers'          => $bfdPeers,
            'vrfs'              => $vrfs,
            'pbrRules'          => $pbrRules,
            'wanGateways'       => $wanGateways,
            'wanGroups'         => $wanGroups,
            'frrStatus'         => $frrStatus,
            'isMock'            => $this->ipc->isMock(),
        ]);
    }

    // ================================================================
    // STATIC ROUTES (existing)
    // ================================================================

    public function storeStatic(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $dest = trim($this->request->getPost('destination') ?? '');
        $gw   = trim($this->request->getPost('gateway') ?? '');
        $iface  = trim($this->request->getPost('interface') ?? 'eth0');
        $metric = (int)($this->request->getPost('metric') ?: 10);
        $desc   = trim($this->request->getPost('description') ?? '');

        if (empty($dest) || empty($gw)) {
            return redirect()->to(base_url('routing'))->with('error', 'Destination network and gateway IP are required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('static_routes')->insert([
            'id'          => 'rt_' . bin2hex(random_bytes(6)),
            'destination' => $dest,
            'gateway'     => $gw,
            'interface'   => $iface,
            'metric'      => $metric,
            'enabled'     => 1,
            'description' => $desc,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('network.route.add', ['destination' => $dest, 'gateway' => $gw]);
        return redirect()->to(base_url('routing'))->with('success', "Static route to {$dest} via {$gw} added.");
    }

    public function updateStatic(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $route = $db->table('static_routes')->where('id', $id)->get()->getRowArray();
        if (! $route) {
            return redirect()->to(base_url('routing'))->with('error', 'Route not found.');
        }

        $db->table('static_routes')->where('id', $id)->update([
            'destination' => trim($this->request->getPost('destination') ?: $route['destination']),
            'gateway'     => trim($this->request->getPost('gateway') ?: $route['gateway']),
            'interface'   => trim($this->request->getPost('interface') ?: $route['interface']),
            'metric'      => (int)($this->request->getPost('metric') ?: 10),
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => $this->request->getPost('enabled') !== null ? (int)$this->request->getPost('enabled') : 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return redirect()->to(base_url('routing'))->with('success', 'Static route updated.');
    }

    public function toggleStatic(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $route = $db->table('static_routes')->where('id', $id)->get()->getRowArray();
        if ($route) {
            $new = ((int)$route['enabled'] === 1) ? 0 : 1;
            $db->table('static_routes')->where('id', $id)->update(['enabled' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
            return redirect()->to(base_url('routing'))->with('success', 'Static route ' . ($new ? 'enabled' : 'disabled') . '.');
        }
        return redirect()->to(base_url('routing'))->with('error', 'Route not found.');
    }

    public function deleteStatic(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('static_routes')->where('id', $id)->delete();
        return redirect()->to(base_url('routing'))->with('success', 'Static route removed.');
    }

    // ================================================================
    // BGP Neighbors
    // ================================================================

    public function storeBgp(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $ip   = trim($this->request->getPost('neighbor_ip') ?? '');
        $as   = (int)($this->request->getPost('remote_as') ?? 0);

        if (empty($ip) || $as === 0) {
            return redirect()->back()->with('error', 'Neighbor IP and Remote AS are required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_bgp_neighbors')->insert([
            'id'               => 'bgp_' . bin2hex(random_bytes(6)),
            'neighbor_ip'      => $ip,
            'remote_as'        => $as,
            'local_as'         => ($this->request->getPost('local_as') ?: null),
            'description'      => trim($this->request->getPost('description') ?? ''),
            'password'         => trim($this->request->getPost('password') ?? '') ?: null,
            'update_source'    => trim($this->request->getPost('update_source') ?? '') ?: null,
            'next_hop_self'    => (int)(bool)$this->request->getPost('next_hop_self'),
            'soft_reconfigure' => 1,
            'route_map_in'     => trim($this->request->getPost('route_map_in') ?? '') ?: null,
            'route_map_out'    => trim($this->request->getPost('route_map_out') ?? '') ?: null,
            'bfd_enabled'      => (int)(bool)$this->request->getPost('bfd_enabled'),
            'enabled'          => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->ipc->call('frr.bgp.reload');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=neighbors')->with('success', "BGP neighbor {$ip} (AS{$as}) added.");
    }

    public function updateBgp(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_bgp_neighbors')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'BGP neighbor not found.');
        }

        $db->table('frr_bgp_neighbors')->where('id', $id)->update([
            'neighbor_ip'   => trim($this->request->getPost('neighbor_ip') ?: $row['neighbor_ip']),
            'remote_as'     => (int)($this->request->getPost('remote_as') ?: $row['remote_as']),
            'description'   => trim($this->request->getPost('description') ?? ''),
            'password'      => trim($this->request->getPost('password') ?? '') ?: null,
            'update_source' => trim($this->request->getPost('update_source') ?? '') ?: null,
            'next_hop_self' => (int)(bool)$this->request->getPost('next_hop_self'),
            'route_map_in'  => trim($this->request->getPost('route_map_in') ?? '') ?: null,
            'route_map_out' => trim($this->request->getPost('route_map_out') ?? '') ?: null,
            'bfd_enabled'   => (int)(bool)$this->request->getPost('bfd_enabled'),
            'enabled'       => (int)(bool)$this->request->getPost('enabled'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.bgp.reload');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=neighbors')->with('success', 'BGP neighbor updated.');
    }

    public function deleteBgp(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_bgp_neighbors')->where('id', $id)->delete();
        $this->ipc->call('frr.bgp.reload');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=neighbors')->with('success', 'BGP neighbor removed.');
    }

    // ================================================================
    // OSPF Networks
    // ================================================================

    public function storeOspf(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $net = trim($this->request->getPost('network') ?? '');
        if (empty($net)) {
            return redirect()->back()->with('error', 'Network CIDR is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_networks')->insert([
            'id'          => 'ospf_' . bin2hex(random_bytes(6)),
            'network'     => $net,
            'area'        => trim($this->request->getPost('area') ?: '0.0.0.0'),
            'area_type'   => $this->request->getPost('area_type') ?: 'normal',
            'cost'        => ($this->request->getPost('cost') ?: null),
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf')->with('success', "OSPF network {$net} added.");
    }

    public function deleteOspf(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_networks')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=networks')->with('success', 'OSPF network removed.');
    }

    // ================================================================
    // OSPF Global Settings
    // ================================================================

    public function saveOspfSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $data = [
            'enabled'                 => (int)(bool)$this->request->getPost('enabled'),
            'router_id'               => trim($this->request->getPost('router_id') ?? '') ?: null,
            'log_adj_changes'         => (int)(bool)$this->request->getPost('log_adj_changes'),
            'spf_hold_time'           => (int)($this->request->getPost('spf_hold_time') ?: 1000),
            'spf_delay'               => (int)($this->request->getPost('spf_delay') ?: 200),
            'spf_min_hold'            => ($this->request->getPost('spf_min_hold') ?: null),
            'spf_max_hold'            => ($this->request->getPost('spf_max_hold') ?: null),
            'snmp_agentx'             => (int)(bool)$this->request->getPost('snmp_agentx'),
            'default_area'            => trim($this->request->getPost('default_area') ?: '0.0.0.0'),
            'passive_default'         => (int)(bool)$this->request->getPost('passive_default'),
            'redistribute_connected'  => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_static'     => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_bgp'        => (int)(bool)$this->request->getPost('redistribute_bgp'),
            'redistribute_rip'        => (int)(bool)$this->request->getPost('redistribute_rip'),
            'redistribute_connected_rm'=> $this->knownRouteMap($this->request->getPost('redistribute_connected_rm')),
            'redistribute_static_rm'  => $this->knownRouteMap($this->request->getPost('redistribute_static_rm')),
            'redistribute_bgp_rm'     => $this->knownRouteMap($this->request->getPost('redistribute_bgp_rm')),
            'redistribute_rip_rm'     => $this->knownRouteMap($this->request->getPost('redistribute_rip_rm')),
            'redistribute_metric'     => ($this->request->getPost('redistribute_metric') ?: null),
            'redistribute_metric_type'=> (int)($this->request->getPost('redistribute_metric_type') ?: 2),
            'reference_bandwidth'     => (int)($this->request->getPost('reference_bandwidth') ?: 100),
            'distance_external'       => ($this->request->getPost('distance_external') ?: null),
            'distance_inter'          => ($this->request->getPost('distance_inter') ?: null),
            'distance_intra'          => ($this->request->getPost('distance_intra') ?: null),
            'max_paths'               => (int)($this->request->getPost('max_paths') ?: 4),
            'updated_at'              => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_ospf_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_ospf_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_ospf_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=general')->with('success', 'OSPF general settings saved.');
    }

    // ================================================================
    // OSPF Areas
    // ================================================================

    public function storeOspfArea(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $areaId = trim($this->request->getPost('area_id') ?? '');
        if (empty($areaId)) {
            return redirect()->back()->with('error', 'Area ID is required.');
        }

        $ranges = $this->buildSummaryRanges();
        $now    = date('Y-m-d H:i:s');
        $db->table('frr_ospf_areas')->insert([
            'id'           => 'oa_' . bin2hex(random_bytes(6)),
            'area_id'      => $areaId,
            'description'  => trim($this->request->getPost('description') ?? ''),
            'area_type'    => $this->request->getPost('area_type') ?: 'normal',
            'default_cost' => ($this->request->getPost('default_cost') ?: null),
            'abr_shortcut' => $this->request->getPost('abr_shortcut') ?: 'default',
            'auth_type'    => $this->request->getPost('auth_type') ?: 'none',
            'no_summary'   => (int)(bool)$this->request->getPost('no_summary'),
            'summary_ranges'=> json_encode($ranges),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=areas')->with('success', "OSPF area {$areaId} added.");
    }

    public function updateOspfArea(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_areas')->where('id', $id)->get()->getRowArray();
        if (!$row) {
            return redirect()->back()->with('error', 'Area not found.');
        }

        $db->table('frr_ospf_areas')->where('id', $id)->update([
            'area_id'       => trim($this->request->getPost('area_id') ?: $row['area_id']),
            'description'   => trim($this->request->getPost('description') ?? ''),
            'area_type'     => $this->request->getPost('area_type') ?: 'normal',
            'default_cost'  => ($this->request->getPost('default_cost') ?: null),
            'abr_shortcut'  => $this->request->getPost('abr_shortcut') ?: 'default',
            'auth_type'     => $this->request->getPost('auth_type') ?: 'none',
            'no_summary'    => (int)(bool)$this->request->getPost('no_summary'),
            'summary_ranges'=> json_encode($this->buildSummaryRanges()),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=areas')->with('success', 'OSPF area updated.');
    }

    public function deleteOspfArea(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_areas')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=areas')->with('success', 'OSPF area removed.');
    }

    /** Build summary ranges array from POST sr_prefix[], sr_no_adv[], sr_cost[], sr_substitute[] */
    private function buildSummaryRanges(): array
    {
        $prefixes    = (array)($this->request->getPost('sr_prefix') ?? []);
        $noAdv       = (array)($this->request->getPost('sr_no_adv') ?? []);
        $costs       = (array)($this->request->getPost('sr_cost') ?? []);
        $substitutes = (array)($this->request->getPost('sr_substitute') ?? []);
        $ranges = [];
        foreach ($prefixes as $i => $prefix) {
            if (!empty(trim($prefix))) {
                $ranges[] = [
                    'prefix'      => trim($prefix),
                    'not_advertise'=> !empty($noAdv[$i]),
                    'cost'        => $costs[$i] ?: null,
                    'substitute'  => trim($substitutes[$i] ?? '') ?: null,
                ];
            }
        }
        return $ranges;
    }

    // ================================================================
    // OSPF Interface Configuration
    // ================================================================

    public function storeOspfIface(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $iface = trim($this->request->getPost('interface') ?? '');
        if (empty($iface)) {
            return redirect()->back()->with('error', 'Interface is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_ifaces')->insert([
            'id'                  => 'oif_' . bin2hex(random_bytes(6)),
            'interface'           => $iface,
            'description'         => trim($this->request->getPost('description') ?? ''),
            'network_type'        => $this->request->getPost('network_type') ?: 'broadcast',
            'area'                => trim($this->request->getPost('area') ?? '') ?: null,
            'metric'              => ($this->request->getPost('metric') ?: null),
            'passive'             => (int)(bool)$this->request->getPost('passive'),
            'ignore_mtu'          => (int)(bool)$this->request->getPost('ignore_mtu'),
            'accept_filter'       => (int)(bool)$this->request->getPost('accept_filter'),
            'auth_type'           => $this->request->getPost('auth_type') ?: 'none',
            'auth_password'       => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'router_priority'     => (int)($this->request->getPost('router_priority') ?: 1),
            'retransmit_interval' => (int)($this->request->getPost('retransmit_interval') ?: 5),
            'hello_interval'      => (int)($this->request->getPost('hello_interval') ?: 10),
            'dead_interval'       => (int)($this->request->getPost('dead_interval') ?: 40),
            'minimal_hello'       => ($this->request->getPost('minimal_hello') ?: null),
            'transmit_delay'      => (int)($this->request->getPost('transmit_delay') ?: 1),
            'bfd_enabled'         => (int)(bool)$this->request->getPost('bfd_enabled'),
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=interfaces')->with('success', "OSPF interface {$iface} configured.");
    }

    public function updateOspfIface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_ifaces')->where('id', $id)->get()->getRowArray();
        if (!$row) {
            return redirect()->back()->with('error', 'Interface not found.');
        }

        $db->table('frr_ospf_ifaces')->where('id', $id)->update([
            'interface'           => trim($this->request->getPost('interface') ?: $row['interface']),
            'description'         => trim($this->request->getPost('description') ?? ''),
            'network_type'        => $this->request->getPost('network_type') ?: 'broadcast',
            'area'                => trim($this->request->getPost('area') ?? '') ?: null,
            'metric'              => ($this->request->getPost('metric') ?: null),
            'passive'             => (int)(bool)$this->request->getPost('passive'),
            'ignore_mtu'          => (int)(bool)$this->request->getPost('ignore_mtu'),
            'accept_filter'       => (int)(bool)$this->request->getPost('accept_filter'),
            'auth_type'           => $this->request->getPost('auth_type') ?: 'none',
            'auth_password'       => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'router_priority'     => (int)($this->request->getPost('router_priority') ?: 1),
            'retransmit_interval' => (int)($this->request->getPost('retransmit_interval') ?: 5),
            'hello_interval'      => (int)($this->request->getPost('hello_interval') ?: 10),
            'dead_interval'       => (int)($this->request->getPost('dead_interval') ?: 40),
            'minimal_hello'       => ($this->request->getPost('minimal_hello') ?: null),
            'transmit_delay'      => (int)($this->request->getPost('transmit_delay') ?: 1),
            'bfd_enabled'         => (int)(bool)$this->request->getPost('bfd_enabled'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=interfaces')->with('success', 'OSPF interface updated.');
    }

    public function deleteOspfIface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_ifaces')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=interfaces')->with('success', 'OSPF interface config removed.');
    }

    // ================================================================
    // OSPF Manual Neighbors
    // ================================================================

    public function storeOspfNeighbor(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $ip = trim($this->request->getPost('neighbor_ip') ?? '');
        if (empty($ip)) {
            return redirect()->back()->with('error', 'Neighbor IP is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_neighbors')->insert([
            'id'           => 'on_' . bin2hex(random_bytes(6)),
            'neighbor_ip'  => $ip,
            'area'         => trim($this->request->getPost('area') ?? '') ?: null,
            'priority'     => ($this->request->getPost('priority') ?: null),
            'poll_interval'=> ($this->request->getPost('poll_interval') ?: null),
            'description'  => trim($this->request->getPost('description') ?? ''),
            'enabled'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=neighbors')->with('success', "OSPF neighbor {$ip} added.");
    }

    public function deleteOspfNeighbor(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_neighbors')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=neighbors')->with('success', 'OSPF neighbor removed.');
    }

    // ================================================================
    // OSPF Policy — Prefix Lists
    // ================================================================

    /**
     * Redirects back to the OSPF Policy sub-tab with an error flash.
     *
     * Policy forms are opened from the Policy sub-tab; redirect()->back() would
     * follow the referer and could land the user on a different OSPF sub-tab,
     * hiding the message.
     */
    private function policyError(string $message): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('error', $message);
    }

    public function storePrefixList(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Prefix-list name is required and may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildPrefixListRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        if ($db->table('frr_prefix_lists')->where('name', $name)->countAllResults()) {
            return $this->policyError("A prefix-list named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_prefix_lists')->insert([
            'id'          => 'pl_' . bin2hex(random_bytes(6)),
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', "Prefix-list '{$name}' created.");
    }

    public function updatePrefixList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_prefix_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Prefix-list not found.');
        }

        $name = trim($this->request->getPost('name') ?: $row['name']);
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Prefix-list name may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildPrefixListRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        $dupe = $db->table('frr_prefix_lists')->where('name', $name)->where('id !=', $id)->countAllResults();
        if ($dupe) {
            return $this->policyError("A prefix-list named '{$name}' already exists.");
        }

        $db->table('frr_prefix_lists')->where('id', $id)->update([
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'Prefix-list updated.');
    }

    public function deletePrefixList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_prefix_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Prefix-list not found.');
        }

        $uses = $this->policyUsage($row['name'], 'prefix');
        if ($uses !== []) {
            return $this->policyError(
                "Prefix-list '{$row['name']}' is still in use and cannot be deleted: " . implode('; ', $uses) . '. Remove those references first.'
            );
        }

        $db->table('frr_prefix_lists')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'Prefix-list removed.');
    }

    /**
     * Builds the ordered rule array for a prefix-list from the modal arrays.
     *
     * @return array<int,array<string,mixed>>|string The rules, or an error message.
     */
    private function buildPrefixListRules()
    {
        $seqs    = (array)($this->request->getPost('pl_seq') ?? []);
        $actions = (array)($this->request->getPost('pl_action') ?? []);
        $prefixes= (array)($this->request->getPost('pl_prefix') ?? []);
        $ges     = (array)($this->request->getPost('pl_ge') ?? []);
        $les     = (array)($this->request->getPost('pl_le') ?? []);
        $descs   = (array)($this->request->getPost('pl_desc') ?? []);

        $rules = [];
        foreach ($prefixes as $i => $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix === '') {
                continue;
            }
            if (! \App\Libraries\Cidr::isValid($prefix)) {
                return "'{$prefix}' is not a valid prefix (use address/prefix-length, e.g. 10.11.83.0/24).";
            }
            $ge = (string) ($ges[$i] ?? '');
            $le = (string) ($les[$i] ?? '');
            $rules[] = [
                'seq'         => (int) ($seqs[$i] ?? 0) ?: ($i + 1) * 10,
                'action'      => (($actions[$i] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                'prefix'      => $prefix,
                'ge'          => ($ge !== '' && ctype_digit($ge)) ? (int) $ge : null,
                'le'          => ($le !== '' && ctype_digit($le)) ? (int) $le : null,
                'description' => trim((string) ($descs[$i] ?? '')),
            ];
        }

        if ($rules === []) {
            return 'Add at least one rule with a prefix.';
        }

        usort($rules, static fn ($a, $b) => $a['seq'] <=> $b['seq']);

        return $rules;
    }

    // ================================================================
    // OSPF Policy — Route Maps
    // ================================================================

    public function storeRouteMap(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Route-map name is required and may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildRouteMapRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        if ($db->table('frr_route_maps')->where('name', $name)->countAllResults()) {
            return $this->policyError("A route-map named '{$name}' already exists.");
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_route_maps')->insert([
            'id'          => 'rm_' . bin2hex(random_bytes(6)),
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', "Route-map '{$name}' created.");
    }

    public function updateRouteMap(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_route_maps')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Route-map not found.');
        }

        $name = trim($this->request->getPost('name') ?: $row['name']);
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            return $this->policyError('Route-map name may only contain letters, digits, dot, dash and underscore.');
        }

        $rules = $this->buildRouteMapRules();
        if (is_string($rules)) {
            return $this->policyError($rules);
        }

        $dupe = $db->table('frr_route_maps')->where('name', $name)->where('id !=', $id)->countAllResults();
        if ($dupe) {
            return $this->policyError("A route-map named '{$name}' already exists.");
        }

        $db->table('frr_route_maps')->where('id', $id)->update([
            'name'        => $name,
            'description' => trim($this->request->getPost('description') ?? ''),
            'rules'       => json_encode($rules),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'Route-map updated.');
    }

    public function deleteRouteMap(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_route_maps')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Route-map not found.');
        }

        $uses = $this->policyUsage($row['name'], 'map');
        if ($uses !== []) {
            return $this->policyError(
                "Route-map '{$row['name']}' is still in use and cannot be deleted: " . implode('; ', $uses) . '. Remove those references first.'
            );
        }

        $db->table('frr_route_maps')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'Route-map removed.');
    }

    /**
     * Builds the ordered rule array for a route-map from the modal arrays.
     *
     * @return array<int,array<string,mixed>>|string The rules, or an error message.
     */
    private function buildRouteMapRules()
    {
        $seqs    = (array)($this->request->getPost('rm_seq') ?? []);
        $actions = (array)($this->request->getPost('rm_action') ?? []);
        $matches = (array)($this->request->getPost('rm_match_pl') ?? []);
        $metrics = (array)($this->request->getPost('rm_set_metric') ?? []);
        $types   = (array)($this->request->getPost('rm_set_metric_type') ?? []);
        $tags    = (array)($this->request->getPost('rm_set_tag') ?? []);
        $descs   = (array)($this->request->getPost('rm_desc') ?? []);

        $rules = [];
        foreach ($seqs as $i => $seq) {
            $match = trim((string) ($matches[$i] ?? ''));
            $metric = (string) ($metrics[$i] ?? '');
            $type   = (string) ($types[$i] ?? '');
            $tag    = (string) ($tags[$i] ?? '');

            // A rule that neither matches nor sets anything is a no-op; skip it.
            if ($match === '' && $metric === '' && $type === '' && $tag === '') {
                continue;
            }

            $rules[] = [
                'seq'              => (int) $seq ?: ($i + 1) * 10,
                'action'           => (($actions[$i] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                'match_prefix_list'=> $match,
                'set_metric'       => ($metric !== '' && ctype_digit($metric)) ? (int) $metric : null,
                'set_metric_type'  => in_array($type, ['type-1', 'type-2'], true) ? $type : null,
                'set_tag'          => ($tag !== '' && ctype_digit($tag)) ? (int) $tag : null,
                'description'      => trim((string) ($descs[$i] ?? '')),
            ];
        }

        if ($rules === []) {
            return 'Add at least one route-map rule with a match or set action.';
        }

        usort($rules, static fn ($a, $b) => $a['seq'] <=> $b['seq']);

        return $rules;
    }

    /**
     * Returns a route-map name only when it exists, otherwise null.
     *
     * Prevents a redistribution from referencing a route-map that was deleted
     * (or mistyped), which FRR would reject at load time.
     */
    private function knownRouteMap(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $exists = \Config\Database::connect()->table('frr_route_maps')
            ->where('name', $name)->countAllResults();

        return $exists ? $name : null;
    }

    // ================================================================
    // OSPF Policy — Distribute Lists (process-level)
    // ================================================================

    public function storeDistributeList(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $fields = $this->distributeListFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_distribute_lists')->insert($fields + [
            'id'         => 'odl_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'OSPF distribute-list added.');
    }

    public function updateDistributeList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_distribute_lists')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Distribute-list entry not found.');
        }

        $fields = $this->distributeListFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $db->table('frr_ospf_distribute_lists')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'OSPF distribute-list updated.');
    }

    public function deleteDistributeList(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_distribute_lists')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'OSPF distribute-list removed.');
    }

    /**
     * Validates and normalises a distribute-list form submission.
     *
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function distributeListFields()
    {
        $prefixList = trim($this->request->getPost('prefix_list') ?? '');
        if (! $this->knownPrefixList($prefixList)) {
            return 'Select an existing prefix-list.';
        }

        $direction = strtolower(trim($this->request->getPost('direction') ?? 'in'));
        if (! in_array($direction, ['in', 'out'], true)) {
            return 'Direction must be in or out.';
        }

        // FRR only permits an interface on the inbound form; the outbound form
        // has no interface argument, so drop any stray value rather than store
        // a configuration the daemon cannot render.
        $interface = trim($this->request->getPost('interface') ?? '') ?: null;
        if ($direction === 'out') {
            $interface = null;
        }

        return [
            'prefix_list' => $prefixList,
            'direction'   => $direction,
            'interface'   => $interface,
            'description' => trim($this->request->getPost('description') ?? ''),
        ];
    }

    // ================================================================
    // OSPF Policy — Area Filter Lists (ABR inter-area filtering)
    // ================================================================

    public function storeAreaFilter(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db     = \Config\Database::connect();
        $fields = $this->areaFilterFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_ospf_area_filters')->insert($fields + [
            'id'         => 'oaf_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'OSPF area filter-list added.');
    }

    public function updateAreaFilter(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_ospf_area_filters')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->policyError('Area filter-list entry not found.');
        }

        $fields = $this->areaFilterFields();
        if (is_string($fields)) {
            return $this->policyError($fields);
        }

        $db->table('frr_ospf_area_filters')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'OSPF area filter-list updated.');
    }

    public function deleteAreaFilter(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_ospf_area_filters')->where('id', $id)->delete();
        $this->ipc->call('frr.ospf.reload');
        return redirect()->to(base_url('routing') . '?tab=ospf&sub=policy')->with('success', 'OSPF area filter-list removed.');
    }

    /**
     * Validates and normalises an area filter-list form submission.
     *
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function areaFilterFields()
    {
        $area = trim($this->request->getPost('area_id') ?? '');
        if ($area === '' || ! preg_match('/^\d+(\.\d+){0,3}$/', $area)) {
            return 'Enter a valid area ID (dotted-decimal like 0.0.0.1, or an integer).';
        }

        $prefixList = trim($this->request->getPost('prefix_list') ?? '');
        if (! $this->knownPrefixList($prefixList)) {
            return 'Select an existing prefix-list.';
        }

        $direction = strtolower(trim($this->request->getPost('direction') ?? 'in'));
        if (! in_array($direction, ['in', 'out'], true)) {
            return 'Direction must be in or out.';
        }

        return [
            'area_id'     => $area,
            'prefix_list' => $prefixList,
            'direction'   => $direction,
            'description' => trim($this->request->getPost('description') ?? ''),
        ];
    }

    /**
     * True when a prefix-list with the given name exists.
     *
     * Distribute-lists and area filter-lists reference a prefix-list by name;
     * storing one that does not exist would make ospfd reject the config.
     */
    private function knownPrefixList(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        return (bool) \Config\Database::connect()->table('frr_prefix_lists')
            ->where('name', $name)->countAllResults();
    }

    /**
     * Indexes every place a prefix-list or route-map name is referenced.
     *
     * Used by the OSPF Policy tab to display usage and by the delete actions to
     * refuse removing something still in use (FRR would otherwise reject, or
     * silently drop policy, on the next config render).
     *
     * @return array{prefixLists: array<string,list<string>>, routeMaps: array<string,list<string>>}
     */
    private function buildPolicyReferences(): array
    {
        $db       = \Config\Database::connect();
        $prefixes = [];
        $maps     = [];

        $add = static function (array &$bucket, ?string $name, string $label): void {
            $name = trim((string) $name);
            if ($name !== '') {
                $bucket[$name][] = $label;
            }
        };

        // Prefix-lists matched inside route-map rules.
        foreach ($db->table('frr_route_maps')->get()->getResultArray() as $rm) {
            foreach (json_decode($rm['rules'] ?? '[]', true) ?: [] as $rule) {
                $add($prefixes, $rule['match_prefix_list'] ?? '', 'Route-map ' . $rm['name']);
            }
        }

        // Prefix-lists referenced by OSPF distribute-lists and area filter-lists.
        foreach ($db->table('frr_ospf_distribute_lists')->get()->getResultArray() as $dl) {
            $scope = $dl['direction'] === 'out' ? 'outbound' : 'inbound';
            if (! empty($dl['interface'])) {
                $scope .= ' on ' . $dl['interface'];
            }
            $add($prefixes, $dl['prefix_list'] ?? '', 'OSPF distribute-list ' . $scope);
        }
        foreach ($db->table('frr_ospf_area_filters')->get()->getResultArray() as $af) {
            $add($prefixes, $af['prefix_list'] ?? '', 'OSPF area ' . $af['area_id'] . ' filter-list ' . ($af['direction'] === 'out' ? 'out' : 'in'));
        }

        // Route-maps applied to BGP neighbors.
        foreach ($db->table('frr_bgp_neighbors')->get()->getResultArray() as $nb) {
            $add($maps, $nb['route_map_in'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (route-map in)');
            $add($maps, $nb['route_map_out'] ?? '', 'BGP neighbor ' . $nb['neighbor_ip'] . ' (route-map out)');
        }

        // Route-maps attached to redistribution, per protocol.
        $redistColumns = [
            'frr_ospf_settings' => [
                'redistribute_connected_rm' => 'OSPF redistribute connected',
                'redistribute_static_rm'    => 'OSPF redistribute static',
                'redistribute_bgp_rm'       => 'OSPF redistribute bgp',
                'redistribute_rip_rm'       => 'OSPF redistribute rip',
            ],
            'frr_rip_settings' => [
                'redistribute_connected_rm' => 'RIP redistribute connected',
                'redistribute_static_rm'    => 'RIP redistribute static',
                'redistribute_bgp_rm'       => 'RIP redistribute bgp',
                'redistribute_ospf_rm'      => 'RIP redistribute ospf',
            ],
            'frr_isis_settings' => [
                'redistribute_connected_rm' => 'IS-IS redistribute connected',
                'redistribute_static_rm'    => 'IS-IS redistribute static',
                'redistribute_bgp_rm'       => 'IS-IS redistribute bgp',
                'redistribute_ospf_rm'      => 'IS-IS redistribute ospf',
            ],
        ];
        foreach ($redistColumns as $table => $columns) {
            $row = $db->table($table)->where('id', 'default')->get()->getRowArray();
            if (! $row) {
                continue;
            }
            foreach ($columns as $column => $label) {
                $add($maps, $row[$column] ?? '', $label);
            }
        }

        // RIP default-information originates a default route via a route-map.
        $rip = $db->table('frr_rip_settings')->where('id', 'default')->get()->getRowArray();
        if ($rip) {
            $add($maps, $rip['default_information_rm'] ?? '', 'RIP default-information');
        }

        return ['prefixLists' => $prefixes, 'routeMaps' => $maps];
    }

    /**
     * Human-readable list of the places a prefix-list / route-map is referenced.
     *
     * @return list<string>
     */
    private function policyUsage(string $name, string $kind): array
    {
        $refs = $this->buildPolicyReferences();
        $key  = $kind === 'map' ? 'routeMaps' : 'prefixLists';

        return array_values(array_unique($refs[$key][$name] ?? []));
    }

    /**
     * Compiles the OSPF policy objects into the FRR configuration block they
     * would produce, for read-only review before the daemon applies it.
     *
     * Prefix-lists and route-maps are global objects; distribute-lists and area
     * filter-lists render inside the `router ospf` block. Disabled objects are
     * omitted and summarised in a trailing comment.
     *
     * @param array<int,array<string,mixed>> $prefixLists
     * @param array<int,array<string,mixed>> $routeMaps
     * @param array<int,array<string,mixed>> $distributeLists
     * @param array<int,array<string,mixed>> $areaFilters
     */
    public static function compileOspfPolicy(array $prefixLists, array $routeMaps, array $distributeLists, array $areaFilters): string
    {
        // Address family of each prefix-list drives the route-map match keyword.
        $family = [];
        foreach ($prefixLists as $pl) {
            $rules = json_decode($pl['rules'] ?? '[]', true) ?: [];
            $allV6 = $rules !== [];
            foreach ($rules as $rule) {
                if (\App\Libraries\Cidr::family((string) ($rule['prefix'] ?? '')) !== 'ipv6') {
                    $allV6 = false;
                    break;
                }
            }
            $family[$pl['name']] = $allV6 ? 'ipv6' : 'ip';
        }

        // Names of prefix-lists that actually exist, so a reference to a
        // missing one is annotated rather than silently emitted.
        $plExists = array_flip(array_column($prefixLists, 'name'));

        $lines   = [];
        $skipped = [];

        // ── Prefix lists ──────────────────────────────────────────────
        $lines[] = '! ── Prefix lists ──────────────────────────────';
        if ($prefixLists === []) {
            $lines[] = '! (none)';
        }
        foreach ($prefixLists as $pl) {
            if (empty($pl['enabled'])) {
                $skipped[] = 'prefix-list ' . $pl['name'];
                continue;
            }
            if (! empty($pl['description'])) {
                $lines[] = '! ' . $pl['name'] . ' — ' . $pl['description'];
            }
            $rules = json_decode($pl['rules'] ?? '[]', true) ?: [];
            if ($rules === []) {
                $lines[] = '! ' . $pl['name'] . ' (no rules)';
                continue;
            }
            foreach ($rules as $rule) {
                $prefix = (string) ($rule['prefix'] ?? '');
                if ($prefix === '') {
                    continue;
                }
                $keyword = \App\Libraries\Cidr::family($prefix) === 'ipv6' ? 'ipv6' : 'ip';
                $line    = sprintf(
                    '%s prefix-list %s seq %d %s %s',
                    $keyword,
                    $pl['name'],
                    (int) ($rule['seq'] ?? 0),
                    (($rule['action'] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                    $prefix
                );
                if (! empty($rule['ge'])) {
                    $line .= ' ge ' . (int) $rule['ge'];
                }
                if (! empty($rule['le'])) {
                    $line .= ' le ' . (int) $rule['le'];
                }
                $lines[] = $line;
            }
        }

        // ── Route maps ────────────────────────────────────────────────
        $lines[] = '!';
        $lines[] = '! ── Route maps ────────────────────────────────';
        if ($routeMaps === []) {
            $lines[] = '! (none)';
        }
        foreach ($routeMaps as $rm) {
            if (empty($rm['enabled'])) {
                $skipped[] = 'route-map ' . $rm['name'];
                continue;
            }
            if (! empty($rm['description'])) {
                $lines[] = '! ' . $rm['name'] . ' — ' . $rm['description'];
            }
            $rules = json_decode($rm['rules'] ?? '[]', true) ?: [];
            if ($rules === []) {
                $lines[] = '! ' . $rm['name'] . ' (no rules)';
                continue;
            }
            foreach ($rules as $rule) {
                $lines[] = sprintf(
                    'route-map %s %s %d',
                    $rm['name'],
                    (($rule['action'] ?? 'permit') === 'deny') ? 'deny' : 'permit',
                    (int) ($rule['seq'] ?? 0)
                );
                if (! empty($rule['match_prefix_list'])) {
                    $plName  = (string) $rule['match_prefix_list'];
                    $lines[] = sprintf(' match %s address prefix-list %s', $family[$plName] ?? 'ip', $plName);
                    if (! isset($plExists[$plName])) {
                        $lines[] = '! WARNING: prefix-list ' . $plName . ' does not exist';
                    }
                }
                if (isset($rule['set_metric']) && $rule['set_metric'] !== null) {
                    $lines[] = ' set metric ' . (int) $rule['set_metric'];
                }
                if (! empty($rule['set_metric_type'])) {
                    $lines[] = ' set metric-type ' . $rule['set_metric_type'];
                }
                if (isset($rule['set_tag']) && $rule['set_tag'] !== null) {
                    $lines[] = ' set tag ' . (int) $rule['set_tag'];
                }
            }
        }

        // ── router ospf: distribute-lists & area filter-lists ─────────
        $lines[]     = '!';
        $lines[]     = '! ── router ospf ───────────────────────────────';
        $activeDist  = array_values(array_filter($distributeLists, static fn ($dl) => ! empty($dl['enabled'])));
        $activeArea  = array_values(array_filter($areaFilters, static fn ($af) => ! empty($af['enabled'])));
        foreach ($distributeLists as $dl) {
            if (empty($dl['enabled'])) {
                $skipped[] = 'distribute-list ' . $dl['prefix_list'];
            }
        }
        foreach ($areaFilters as $af) {
            if (empty($af['enabled'])) {
                $skipped[] = 'area ' . $af['area_id'] . ' filter-list';
            }
        }
        if ($activeDist === [] && $activeArea === []) {
            $lines[] = '! (no distribute-lists or area filter-lists)';
        } else {
            $lines[] = 'router ospf';
            foreach ($activeDist as $dl) {
                $dir  = ($dl['direction'] === 'out') ? 'out' : 'in';
                $line = ' distribute-list ' . $dl['prefix_list'] . ' ' . $dir;
                if ($dir === 'in' && ! empty($dl['interface'])) {
                    $line .= ' ' . $dl['interface'];
                }
                $lines[] = $line;
                if (! isset($plExists[$dl['prefix_list']])) {
                    $lines[] = '! WARNING: prefix-list ' . $dl['prefix_list'] . ' does not exist';
                }
            }
            foreach ($activeArea as $af) {
                $dir     = ($af['direction'] === 'out') ? 'out' : 'in';
                $lines[] = sprintf(' area %s filter-list prefix %s %s', $af['area_id'], $af['prefix_list'], $dir);
                if (! isset($plExists[$af['prefix_list']])) {
                    $lines[] = '! WARNING: prefix-list ' . $af['prefix_list'] . ' does not exist';
                }
            }
        }

        if ($skipped !== []) {
            $lines[] = '!';
            $lines[] = '! Disabled (not compiled): ' . implode(', ', $skipped);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Finds OSPF policy references pointing at a route-map or prefix-list that
     * no longer exists.
     *
     * 
     * A rename or a hand-edited row can leave an OSPF distribute-list, area
     * filter-list, route-map match or redistribution route-map pointing at a
     * deleted object. FRR would then reject the config or silently match
     * nothing, so these are surfaced instead of being ignored.
     *
     * @param array<int,array<string,mixed>> $prefixLists
     * @param array<int,array<string,mixed>> $routeMaps
     * @param array<int,array<string,mixed>> $distributeLists
     * @param array<int,array<string,mixed>> $areaFilters
     * @param array<string,mixed>            $ospfSettings
     *
     * @return list<array{type:string,name:string,referenced_by:string}>
     */
    public static function staleOspfReferences(
        array $prefixLists,
        array $routeMaps,
        array $distributeLists,
        array $areaFilters,
        array $ospfSettings = []
    ): array {
        $plExists = array_flip(array_column($prefixLists, 'name'));
        $rmExists = array_flip(array_column($routeMaps, 'name'));

        $stale = [];
        $check = static function (string $type, ?string $name, string $referencedBy) use (&$stale, $plExists, $rmExists): void {
            $name = trim((string) $name);
            if ($name === '') {
                return;
            }
            $exists = $type === 'route-map' ? isset($rmExists[$name]) : isset($plExists[$name]);
            if (! $exists) {
                $stale[] = ['type' => $type, 'name' => $name, 'referenced_by' => $referencedBy];
            }
        };

        // Route-map rules matching a prefix-list.
        foreach ($routeMaps as $rm) {
            foreach (json_decode($rm['rules'] ?? '[]', true) ?: [] as $rule) {
                if (! empty($rule['match_prefix_list'])) {
                    $check('prefix-list', (string) $rule['match_prefix_list'], 'Route-map ' . $rm['name']);
                }
            }
        }

        // Distribute-lists and area filter-lists referencing a prefix-list.
        foreach ($distributeLists as $dl) {
            $dir = ($dl['direction'] ?? 'in') === 'out' ? 'outbound' : 'inbound';
            $check('prefix-list', $dl['prefix_list'] ?? '', "OSPF distribute-list {$dir}");
        }
        foreach ($areaFilters as $af) {
            $dir = ($af['direction'] ?? 'in') === 'out' ? 'out' : 'in';
            $check('prefix-list', $af['prefix_list'] ?? '', 'OSPF area ' . ($af['area_id'] ?? '?') . " filter-list {$dir}");
        }

        // Redistribution route-maps.
        $redistribute = [
            'redistribute_connected_rm' => 'OSPF redistribute connected',
            'redistribute_static_rm'    => 'OSPF redistribute static',
            'redistribute_bgp_rm'       => 'OSPF redistribute bgp',
            'redistribute_rip_rm'       => 'OSPF redistribute rip',
        ];
        foreach ($redistribute as $column => $label) {
            $check('route-map', $ospfSettings[$column] ?? '', $label);
        }

        return $stale;
    }

    // ================================================================
    // RIP (ripd)
    // ================================================================

    /** Redirect back to a RIP sub-tab with an error flash. */
    private function ripError(string $message, string $sub = 'general'): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(base_url('routing') . '?tab=rip&sub=' . $sub)->with('error', $message);
    }

    public function saveRipSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $version = (int) $this->request->getPost('version');
        if (! in_array($version, [1, 2], true)) {
            $version = 2;
        }

        $authMode = strtolower(trim($this->request->getPost('auth_mode') ?? 'none'));
        if (! in_array($authMode, ['none', 'text', 'md5'], true)) {
            return $this->ripError('Authentication mode must be none, text or md5.');
        }

        $data = [
            'enabled'                => (int)(bool)$this->request->getPost('enabled'),
            'version'                => $version,
            'redistribute_connected' => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_static'    => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_bgp'       => (int)(bool)$this->request->getPost('redistribute_bgp'),
            'redistribute_ospf'      => (int)(bool)$this->request->getPost('redistribute_ospf'),
            'redistribute_connected_rm' => $this->knownRouteMap($this->request->getPost('redistribute_connected_rm')),
            'redistribute_static_rm'    => $this->knownRouteMap($this->request->getPost('redistribute_static_rm')),
            'redistribute_bgp_rm'       => $this->knownRouteMap($this->request->getPost('redistribute_bgp_rm')),
            'redistribute_ospf_rm'      => $this->knownRouteMap($this->request->getPost('redistribute_ospf_rm')),
            'redistribute_metric'    => ($this->request->getPost('redistribute_metric') !== null && $this->request->getPost('redistribute_metric') !== '' ? (int)$this->request->getPost('redistribute_metric') : null),
            'default_information'    => (int)(bool)$this->request->getPost('default_information'),
            'default_information_rm' => $this->knownRouteMap($this->request->getPost('default_information_rm')),
            'distance'               => ($this->request->getPost('distance') !== null && $this->request->getPost('distance') !== '' ? (int)$this->request->getPost('distance') : null),
            'maximum_paths'          => max(1, (int)($this->request->getPost('maximum_paths') ?: 4)),
            'timers_update'          => (int)($this->request->getPost('timers_update') ?: 30),
            'timers_timeout'         => (int)($this->request->getPost('timers_timeout') ?: 180),
            'timers_garbage'         => (int)($this->request->getPost('timers_garbage') ?: 120),
            'passive_default'        => (int)(bool)$this->request->getPost('passive_default'),
            'auth_mode'              => $authMode,
            'auth_password'          => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_rip_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_rip_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_rip_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        $this->ipc->call('frr.rip.reload');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=general')->with('success', 'RIP general settings saved.');
    }

    public function storeRipNetwork(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db      = \Config\Database::connect();
        $network = trim($this->request->getPost('network') ?? '');
        if (\App\Libraries\Cidr::isValid($network) === false) {
            return $this->ripError('Enter a valid IPv4/IPv6 network in CIDR notation (e.g. 192.168.1.0/24).', 'networks');
        }

        if ($db->table('frr_rip_networks')->where('network', $network)->countAllResults()) {
            return $this->ripError("RIP network '{$network}' already exists.", 'networks');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_rip_networks')->insert([
            'id'          => 'rn_' . bin2hex(random_bytes(6)),
            'network'     => $network,
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('frr.rip.reload');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=networks')->with('success', "RIP network {$network} added.");
    }

    public function deleteRipNetwork(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_rip_networks')->where('id', $id)->delete();
        $this->ipc->call('frr.rip.reload');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=networks')->with('success', 'RIP network removed.');
    }

    public function storeRipNeighbor(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $ip = trim($this->request->getPost('neighbor_ip') ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->ripError('Enter a valid neighbor IP address.', 'neighbors');
        }

        if ($db->table('frr_rip_neighbors')->where('neighbor_ip', $ip)->countAllResults()) {
            return $this->ripError("RIP neighbor '{$ip}' already exists.", 'neighbors');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_rip_neighbors')->insert([
            'id'          => 'rb_' . bin2hex(random_bytes(6)),
            'neighbor_ip' => $ip,
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('frr.rip.reload');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=neighbors')->with('success', "RIP neighbor {$ip} added.");
    }

    public function deleteRipNeighbor(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_rip_neighbors')->where('id', $id)->delete();
        $this->ipc->call('frr.rip.reload');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=neighbors')->with('success', 'RIP neighbor removed.');
    }

    public function storeRipPassiveInterface(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db    = \Config\Database::connect();
        $iface = trim($this->request->getPost('interface') ?? '');
        if ($iface === '') {
            return $this->ripError('Interface is required.', 'passive');
        }

        if ($db->table('frr_rip_passive_interfaces')->where('interface', $iface)->countAllResults()) {
            return $this->ripError("Interface '{$iface}' is already marked passive.", 'passive');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_rip_passive_interfaces')->insert([
            'id'          => 'rp_' . bin2hex(random_bytes(6)),
            'interface'   => $iface,
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('frr.rip.reload');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=passive')->with('success', "Interface {$iface} marked passive for RIP.");
    }

    public function deleteRipPassiveInterface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_rip_passive_interfaces')->where('id', $id)->delete();
        $this->ipc->call('frr.rip.reload');
        return redirect()->to(base_url('routing') . '?tab=rip&sub=passive')->with('success', 'RIP passive interface removed.');
    }

    // ================================================================
    // IS-IS (isisd)
    // ================================================================

    /** Redirect back to an IS-IS sub-tab with an error flash. */
    private function isisError(string $message, string $sub = 'general'): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to(base_url('routing') . '?tab=isis&sub=' . $sub)->with('error', $message);
    }

    public function saveIsisSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();

        $areaTag = trim($this->request->getPost('area_tag') ?? '') ?: 'DEFAULT';
        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $areaTag)) {
            return $this->isisError('Area tag may only contain letters, digits, dot, dash and underscore.');
        }

        $isType = strtolower(trim($this->request->getPost('is_type') ?? 'level-1-2'));
        if (! in_array($isType, ['level-1', 'level-1-2', 'level-2-only'], true)) {
            return $this->isisError('IS type must be level-1, level-1-2 or level-2-only.');
        }

        $metricStyle = strtolower(trim($this->request->getPost('metric_style') ?? 'wide'));
        if (! in_array($metricStyle, ['narrow', 'transition', 'wide'], true)) {
            return $this->isisError('Metric style must be narrow, transition or wide.');
        }

        $authMode = strtolower(trim($this->request->getPost('auth_mode') ?? 'none'));
        if (! in_array($authMode, ['none', 'text', 'md5'], true)) {
            return $this->isisError('Authentication mode must be none, text or md5.');
        }

        // NET is optional while disabled, but must be well-formed when present.
        $net = trim($this->request->getPost('net') ?? '');
        if ($net !== '' && ! preg_match('/^[0-9A-Fa-f]{2}(\.[0-9A-Fa-f]{4})+\.[0-9A-Fa-f]{2}$/', $net)) {
            return $this->isisError('NET must be a valid network entity title, e.g. 49.0001.1921.6800.1001.00.');
        }

        $data = [
            'enabled'                => (int)(bool)$this->request->getPost('enabled'),
            'area_tag'               => $areaTag,
            'net'                    => $net ?: null,
            'is_type'                => $isType,
            'metric_style'           => $metricStyle,
            'log_adjacency'          => (int)(bool)$this->request->getPost('log_adjacency'),
            'lsp_gen_interval'       => ($this->request->getPost('lsp_gen_interval') !== null && $this->request->getPost('lsp_gen_interval') !== '' ? (int)$this->request->getPost('lsp_gen_interval') : null),
            'spf_interval_l1'        => ($this->request->getPost('spf_interval_l1') !== null && $this->request->getPost('spf_interval_l1') !== '' ? (int)$this->request->getPost('spf_interval_l1') : null),
            'spf_interval_l2'        => ($this->request->getPost('spf_interval_l2') !== null && $this->request->getPost('spf_interval_l2') !== '' ? (int)$this->request->getPost('spf_interval_l2') : null),
            'max_lsp_lifetime'       => ($this->request->getPost('max_lsp_lifetime') !== null && $this->request->getPost('max_lsp_lifetime') !== '' ? (int)$this->request->getPost('max_lsp_lifetime') : null),
            'redistribute_connected' => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_static'    => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_bgp'       => (int)(bool)$this->request->getPost('redistribute_bgp'),
            'redistribute_ospf'      => (int)(bool)$this->request->getPost('redistribute_ospf'),
            'redistribute_connected_rm' => $this->knownRouteMap($this->request->getPost('redistribute_connected_rm')),
            'redistribute_static_rm'    => $this->knownRouteMap($this->request->getPost('redistribute_static_rm')),
            'redistribute_bgp_rm'       => $this->knownRouteMap($this->request->getPost('redistribute_bgp_rm')),
            'redistribute_ospf_rm'      => $this->knownRouteMap($this->request->getPost('redistribute_ospf_rm')),
            'redistribute_metric'    => ($this->request->getPost('redistribute_metric') !== null && $this->request->getPost('redistribute_metric') !== '' ? (int)$this->request->getPost('redistribute_metric') : null),
            'auth_mode'              => $authMode,
            'auth_password'          => trim($this->request->getPost('auth_password') ?? '') ?: null,
            'passive_default'        => (int)(bool)$this->request->getPost('passive_default'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_isis_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_isis_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_isis_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        $this->ipc->call('frr.isis.reload');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=general')->with('success', 'IS-IS general settings saved.');
    }

    public function storeIsisInterface(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $fields = $this->isisInterfaceFields();
        if (is_string($fields)) {
            return $this->isisError($fields, 'interfaces');
        }

        if ($db->table('frr_isis_interfaces')->where('interface', $fields['interface'])->countAllResults()) {
            return $this->isisError("Interface '{$fields['interface']}' is already configured for IS-IS.", 'interfaces');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_isis_interfaces')->insert($fields + [
            'id'         => 'ii_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->ipc->call('frr.isis.reload');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=interfaces')->with('success', "IS-IS interface {$fields['interface']} configured.");
    }

    public function updateIsisInterface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('frr_isis_interfaces')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return $this->isisError('IS-IS interface not found.', 'interfaces');
        }

        $fields = $this->isisInterfaceFields();
        if (is_string($fields)) {
            return $this->isisError($fields, 'interfaces');
        }

        if ($db->table('frr_isis_interfaces')->where('interface', $fields['interface'])->where('id !=', $id)->countAllResults()) {
            return $this->isisError("Interface '{$fields['interface']}' is already configured for IS-IS.", 'interfaces');
        }

        $db->table('frr_isis_interfaces')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('frr.isis.reload');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=interfaces')->with('success', 'IS-IS interface updated.');
    }

    public function deleteIsisInterface(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        \Config\Database::connect()->table('frr_isis_interfaces')->where('id', $id)->delete();
        $this->ipc->call('frr.isis.reload');
        return redirect()->to(base_url('routing') . '?tab=isis&sub=interfaces')->with('success', 'IS-IS interface removed.');
    }

    /**
     * Validates and normalises an IS-IS interface form submission.
     *
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function isisInterfaceFields()
    {
        $iface = trim($this->request->getPost('interface') ?? '');
        if ($iface === '') {
            return 'Interface is required.';
        }

        $circuitType = strtolower(trim($this->request->getPost('circuit_type') ?? 'level-1-2'));
        if (! in_array($circuitType, ['level-1', 'level-1-2', 'level-2-only'], true)) {
            return 'Circuit type must be level-1, level-1-2 or level-2-only.';
        }

        $networkType = strtolower(trim($this->request->getPost('network_type') ?? 'broadcast'));
        if (! in_array($networkType, ['broadcast', 'point-to-point'], true)) {
            return 'Network type must be broadcast or point-to-point.';
        }

        $intOrNull = static function ($value): ?int {
            return ($value !== null && $value !== '') ? (int) $value : null;
        };

        return [
            'interface'        => $iface,
            'description'      => trim($this->request->getPost('description') ?? ''),
            'circuit_type'     => $circuitType,
            'network_type'     => $networkType,
            'metric_l1'        => $intOrNull($this->request->getPost('metric_l1')),
            'metric_l2'        => $intOrNull($this->request->getPost('metric_l2')),
            'hello_interval'   => max(1, (int)($this->request->getPost('hello_interval') ?: 10)),
            'hello_multiplier' => max(1, (int)($this->request->getPost('hello_multiplier') ?: 3)),
            'csnp_interval'    => $intOrNull($this->request->getPost('csnp_interval')),
            'psnp_interval'    => $intOrNull($this->request->getPost('psnp_interval')),
            'password'         => trim($this->request->getPost('password') ?? '') ?: null,
            'bfd_enabled'      => (int)(bool)$this->request->getPost('bfd_enabled'),
        ];
    }

    // ================================================================
    // BGP Global Settings
    // ================================================================

    public function saveBgpSettings(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $networks = array_values(array_filter(
            array_map('trim', explode("\n", $this->request->getPost('networks') ?? ''))
        ));

        $data = [
            'enabled'                  => (int)(bool)$this->request->getPost('enabled'),
            'local_as'                 => (int)($this->request->getPost('local_as') ?: 65001),
            'router_id'                => trim($this->request->getPost('router_id') ?? '') ?: null,
            'keepalive_interval'       => (int)($this->request->getPost('keepalive_interval') ?: 60),
            'hold_time'                => (int)($this->request->getPost('hold_time') ?: 180),
            'connect_retry'            => (int)($this->request->getPost('connect_retry') ?: 120),
            'log_neighbor_changes'     => (int)(bool)$this->request->getPost('log_neighbor_changes'),
            'graceful_restart'         => (int)(bool)$this->request->getPost('graceful_restart'),
            'deterministic_med'        => (int)(bool)$this->request->getPost('deterministic_med'),
            'always_compare_med'       => (int)(bool)$this->request->getPost('always_compare_med'),
            'bestpath_as_path_ignore'  => (int)(bool)$this->request->getPost('bestpath_as_path_ignore'),
            'redistribute_connected'   => (int)(bool)$this->request->getPost('redistribute_connected'),
            'redistribute_static'      => (int)(bool)$this->request->getPost('redistribute_static'),
            'redistribute_ospf'        => (int)(bool)$this->request->getPost('redistribute_ospf'),
            'networks'                 => json_encode($networks),
            'max_paths_ebgp'           => (int)($this->request->getPost('max_paths_ebgp') ?: 1),
            'max_paths_ibgp'           => (int)($this->request->getPost('max_paths_ibgp') ?: 1),
            'updated_at'               => date('Y-m-d H:i:s'),
        ];

        if ($db->table('frr_bgp_settings')->where('id', 'default')->countAllResults()) {
            $db->table('frr_bgp_settings')->where('id', 'default')->update($data);
        } else {
            $db->table('frr_bgp_settings')->insert(array_merge(['id' => 'default'], $data));
        }

        $this->ipc->call('frr.bgp.reload');
        return redirect()->to(base_url('routing') . '?tab=bgp&sub=general')->with('success', 'BGP global settings saved.');
    }


    // ================================================================

    public function storeBfd(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $ip = trim($this->request->getPost('peer_ip') ?? '');
        if (empty($ip)) {
            return redirect()->back()->with('error', 'Peer IP is required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('frr_bfd_peers')->insert([
            'id'                => 'bfd_' . bin2hex(random_bytes(6)),
            'peer_ip'           => $ip,
            'local_ip'          => trim($this->request->getPost('local_ip') ?? '') ?: null,
            'interface'         => trim($this->request->getPost('interface') ?? '') ?: null,
            'detect_multiplier' => (int)($this->request->getPost('detect_multiplier') ?: 3),
            'receive_interval'  => (int)($this->request->getPost('receive_interval') ?: 300),
            'transmit_interval' => (int)($this->request->getPost('transmit_interval') ?: 300),
            'echo_mode'         => (int)(bool)$this->request->getPost('echo_mode'),
            'description'       => trim($this->request->getPost('description') ?? ''),
            'enabled'           => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $this->ipc->call('frr.bfd.reload');
        return redirect()->to(base_url('routing') . '?tab=bfd')->with('success', "BFD peer {$ip} added.");
    }

    public function deleteBfd(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('frr_bfd_peers')->where('id', $id)->delete();
        $this->ipc->call('frr.bfd.reload');
        return redirect()->to(base_url('routing') . '?tab=bfd')->with('success', 'BFD peer removed.');
    }

    // ================================================================
    // VRF
    // ================================================================

    public function storeVrf(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if (empty($name)) {
            return redirect()->back()->with('error', 'VRF name is required.');
        }

        $ifaces = (array)($this->request->getPost('interfaces') ?? []);
        $now = date('Y-m-d H:i:s');
        $db->table('routing_vrfs')->insert([
            'id'          => 'vrf_' . bin2hex(random_bytes(6)),
            'name'        => $name,
            'table_id'    => ($this->request->getPost('table_id') ?: null),
            'interfaces'  => json_encode(array_values($ifaces)),
            'description' => trim($this->request->getPost('description') ?? ''),
            'enabled'     => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->ipc->call('network.vrf.sync');
        return redirect()->to(base_url('routing') . '?tab=vrf')->with('success', "VRF '{$name}' created.");
    }

    public function deleteVrf(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('routing_vrfs')->where('id', $id)->delete();
        $this->ipc->call('network.vrf.sync');
        return redirect()->to(base_url('routing') . '?tab=vrf')->with('success', 'VRF removed.');
    }

    // ================================================================
    // PBR Rules
    // ================================================================

    public function storePbr(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $fields = $this->pbrFields();
        if (is_string($fields)) {
            return redirect()->to(base_url('routing') . '?tab=pbr')->with('error', $fields);
        }

        $db->table('routing_pbr_rules')->insert($fields + [
            'id'         => 'pbr_' . bin2hex(random_bytes(6)),
            'enabled'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->ipc->call('network.pbr.sync');
        return redirect()->to(base_url('routing') . '?tab=pbr')->with('success', 'PBR rule added.');
    }

    public function updatePbr(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('routing_pbr_rules')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'PBR rule not found.');
        }

        $fields = $this->pbrFields($row);
        if (is_string($fields)) {
            return redirect()->to(base_url('routing') . '?tab=pbr')->with('error', $fields);
        }

        // The edit form carries an explicit enabled flag; when it is absent
        // (e.g. a partial update) the existing value is preserved rather than
        // silently cleared.
        $fields['enabled'] = $this->request->getPost('enabled') !== null
            ? (int) (bool) $this->request->getPost('enabled')
            : (int) $row['enabled'];

        $db->table('routing_pbr_rules')->where('id', $id)->update($fields + [
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('network.pbr.sync');
        return redirect()->to(base_url('routing') . '?tab=pbr')->with('success', 'PBR rule updated.');
    }

    /**
     * Validates and normalises the PBR form fields.
     *
     * @param  array<string,mixed>|null $existing
     * @return array<string,mixed>|string The fields, or an error message.
     */
    private function pbrFields(?array $existing = null)
    {
        $src = trim($this->request->getPost('match_src') ?? '');
        $dst = trim($this->request->getPost('match_dst') ?? '');

        foreach (['source' => $src, 'destination' => $dst] as $label => $value) {
            if ($value !== '' && $value !== 'any' && ! \App\Libraries\Cidr::isValid($value)) {
                return "The {$label} match must be an IP address or CIDR (e.g. 10.0.0.0/24).";
            }
        }

        $nexthop = trim($this->request->getPost('set_nexthop') ?? '');
        if ($nexthop !== '' && ! filter_var($nexthop, FILTER_VALIDATE_IP)) {
            return 'The next-hop must be a valid IP address.';
        }

        // A selected object must exist, otherwise the rule silently matches
        // nothing. Catch the typo at save time instead.
        $unknown = $this->unknownPbrObjects();
        if ($unknown !== []) {
            return 'Unknown address object(s): ' . implode(', ', $unknown) . '.';
        }

        $table = $this->request->getPost('set_table');
        $table = ($table === null || $table === '') ? null : (int) $table;
        if ($table !== null && ($table < 1 || $table > 252)) {
            return 'The routing table must be between 1 and 252.';
        }

        $action = strtolower(trim((string) ($this->request->getPost('action') ?? 'permit')));

        return [
            'priority'          => (int) ($this->request->getPost('priority') ?: ($existing['priority'] ?? 100)),
            'description'       => trim($this->request->getPost('description') ?? ''),
            'match_src'         => $src ?: null,
            'match_dst'         => $dst ?: null,
            'match_src_objects' => $this->pbrObjectList('match_src_objects'),
            'match_dst_objects' => $this->pbrObjectList('match_dst_objects'),
            'match_protocol'    => trim($this->request->getPost('match_protocol') ?? '') ?: null,
            'match_dscp'        => trim($this->request->getPost('match_dscp') ?? '') ?: null,
            'match_iif'         => trim($this->request->getPost('match_iif') ?? '') ?: null,
            'set_nexthop'       => $nexthop ?: null,
            'set_table'         => $table,
            'set_vrf'           => trim($this->request->getPost('set_vrf') ?? '') ?: null,
            'action'            => $action === 'deny' ? 'deny' : 'permit',
        ];
    }

    /**
     * Object names in the submitted selection that are neither an address
     * object nor an address group.
     *
     * @return list<string>
     */
    private function unknownPbrObjects(): array
    {
        $names = [];
        foreach (['match_src_objects', 'match_dst_objects'] as $field) {
            $raw = $this->request->getPost($field);
            $items = is_array($raw) ? $raw : preg_split('/[,\n]+/', (string) $raw);
            foreach ($items ?: [] as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $names[$item] = true;
                }
            }
        }

        if ($names === []) {
            return [];
        }

        $db = \Config\Database::connect();
        $known = array_merge(
            array_column($db->table('firewall_addresses')->select('name')->get()->getResultArray(), 'name'),
            array_column($db->table('firewall_address_groups')->select('name')->get()->getResultArray(), 'name'),
        );
        $known = array_flip($known);

        return array_values(array_filter(array_keys($names), static fn ($name) => ! isset($known[$name])));
    }

    /**
     * Reads a PBR object-match selection (a list of names) into a JSON list.
     *
     * The value is stored as JSON so a name containing a comma survives; an
     * empty selection becomes null rather than an empty array.
     */
    private function pbrObjectList(string $field): ?string
    {
        $raw = $this->request->getPost($field);
        if ($raw === null) {
            return null;
        }

        $items = is_array($raw) ? $raw : preg_split('/[,\n]+/', (string) $raw);
        $items = array_values(array_unique(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            $items ?: [],
        ), 'strlen')));

        return $items === [] ? null : json_encode($items);
    }

    public function deletePbr(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('routing_pbr_rules')->where('id', $id)->delete();
        $this->ipc->call('network.pbr.sync');
        return redirect()->to(base_url('routing') . '?tab=pbr')->with('success', 'PBR rule removed.');
    }

    public function togglePbr(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('routing_pbr_rules')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'PBR rule not found.');
        }

        $newVal = empty($row['enabled']) ? 1 : 0;
        $db->table('routing_pbr_rules')->where('id', $id)->update([
            'enabled'    => $newVal,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('network.pbr.sync');

        return redirect()->to(base_url('routing') . '?tab=pbr')
            ->with('success', "PBR rule '{$row['description']}' is now " . ($newVal ? 'enabled' : 'disabled') . '.');
    }

    public function reorderPbr(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db    = \Config\Database::connect();
        $order = array_values(array_filter(array_map('strval', (array) ($this->request->getPost('order') ?? [])), 'strlen'));

        if ($order === []) {
            return $this->response->setJSON(['ok' => false, 'error' => 'No rule order was supplied.']);
        }

        foreach ($order as $index => $id) {
            $db->table('routing_pbr_rules')->where('id', $id)->update(['priority' => $index + 1]);
        }

        $this->ipc->call('network.pbr.sync');
        return $this->response->setJSON(['ok' => true]);
    }

    /**
     * Compiles the PBR rules and pushes the mark/route policy to the dataplane.
     *
     * The compiled text is returned for inspection (the ruleset plus its
     * companion `ip rule` / `ip route` section) and applied through the daemon.
     */
    public function applyPbr(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $compiler = new \App\Libraries\NftablesCompiler();

            // The PBR marks live in the nftables ruleset; the `ip rule` /
            // `ip route` policy is separate kernel state. Both are sent so the
            // daemon can load the ruleset and (re)build the routing policy
            // atomically in one call.
            $ruleset = $compiler->compile();
            $policy  = $compiler->compileRoutingPolicy();

            $resp = $this->ipc->call('routing.apply', [
                'ruleset'   => $ruleset,
                'rules'     => $policy['rules'],
                'routes'    => $policy['routes'],
                'safe_mode' => true,
            ]);

            if (! empty($resp['error'])) {
                return redirect()->to(base_url('routing') . '?tab=pbr')
                    ->with('error', 'Failed to apply policy routing: ' . ($resp['error']['message'] ?? 'daemon error'));
            }

            $result = $resp['result'] ?? [];
            $status = $result['status'] ?? 'applied';

            if (in_array($status, ['syntax_error', 'apply_failed', 'error'], true)) {
                return redirect()->to(base_url('routing') . '?tab=pbr')
                    ->with('error', 'Policy routing rejected: ' . ($result['error'] ?? $result['message'] ?? $status));
            }

            $count = count($policy['rules']);
            $msg   = $result['message'] ?? sprintf(
                'Policy routing applied: %d rule(s) and %d table route(s)%s.',
                $count,
                count($policy['routes']),
                $this->ipc->isMock() ? ' (simulated)' : '',
            );

            return redirect()->to(base_url('routing') . '?tab=pbr')->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('routing') . '?tab=pbr')
                ->with('error', 'Failed to apply policy routing: ' . $e->getMessage());
        }
    }

    // ================================================================
    // Multi-WAN — Gateways
    // ================================================================

    public function storeGateway(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        $gw   = trim($this->request->getPost('gateway_ip') ?? '');
        if (empty($name) || empty($gw)) {
            return redirect()->back()->with('error', 'Gateway name and IP are required.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('wan_gateways')->insert([
            'id'           => 'gw_' . bin2hex(random_bytes(6)),
            'name'         => $name,
            'interface'    => trim($this->request->getPost('interface') ?: 'eth0'),
            'gateway_ip'   => $gw,
            'monitor_ip'   => trim($this->request->getPost('monitor_ip') ?? '') ?: null,
            'weight'       => (int)($this->request->getPost('weight') ?: 1),
            'priority'     => (int)($this->request->getPost('priority') ?: 1),
            'latency_warn' => (int)($this->request->getPost('latency_warn') ?: 200),
            'latency_down' => (int)($this->request->getPost('latency_down') ?: 500),
            'loss_warn'    => (int)($this->request->getPost('loss_warn') ?: 10),
            'loss_down'    => (int)($this->request->getPost('loss_down') ?: 20),
            'description'  => trim($this->request->getPost('description') ?? ''),
            'enabled'      => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->ipc->call('network.multiwan.sync');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', "Gateway '{$name}' added.");
    }

    public function updateGateway(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('wan_gateways')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Gateway not found.');
        }

        $db->table('wan_gateways')->where('id', $id)->update([
            'name'         => trim($this->request->getPost('name') ?: $row['name']),
            'interface'    => trim($this->request->getPost('interface') ?: $row['interface']),
            'gateway_ip'   => trim($this->request->getPost('gateway_ip') ?: $row['gateway_ip']),
            'monitor_ip'   => trim($this->request->getPost('monitor_ip') ?? '') ?: null,
            'weight'       => (int)($this->request->getPost('weight') ?: 1),
            'priority'     => (int)($this->request->getPost('priority') ?: 1),
            'latency_warn' => (int)($this->request->getPost('latency_warn') ?: 200),
            'latency_down' => (int)($this->request->getPost('latency_down') ?: 500),
            'loss_warn'    => (int)($this->request->getPost('loss_warn') ?: 10),
            'loss_down'    => (int)($this->request->getPost('loss_down') ?: 20),
            'description'  => trim($this->request->getPost('description') ?? ''),
            'enabled'      => (int)(bool)$this->request->getPost('enabled'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('network.multiwan.sync');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', 'Gateway updated.');
    }

    public function deleteGateway(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('wan_gateways')->where('id', $id)->delete();
        $this->ipc->call('network.multiwan.sync');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', 'Gateway removed.');
    }

    // ================================================================
    // Multi-WAN — Gateway Groups
    // ================================================================

    public function storeGatewayGroup(): \CodeIgniter\HTTP\RedirectResponse
    {
        $db   = \Config\Database::connect();
        $name = trim($this->request->getPost('name') ?? '');
        if (empty($name)) {
            return redirect()->back()->with('error', 'Group name is required.');
        }

        // Build members array [{id, tier, weight}]
        $memberIds   = (array)($this->request->getPost('member_ids') ?? []);
        $memberTiers = (array)($this->request->getPost('member_tiers') ?? []);
        $members = [];
        foreach ($memberIds as $i => $mid) {
            if (!empty($mid)) {
                $members[] = ['id' => $mid, 'tier' => (int)($memberTiers[$i] ?? 1)];
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->table('wan_gateway_groups')->insert([
            'id'             => 'gwg_' . bin2hex(random_bytes(6)),
            'name'           => $name,
            'strategy'       => $this->request->getPost('strategy') ?: 'failover',
            'sticky_enabled' => (int)(bool)$this->request->getPost('sticky_enabled'),
            'sticky_timeout' => max(0, (int)($this->request->getPost('sticky_timeout') ?? 0)),
            'members'        => json_encode($members),
            'trigger'        => $this->request->getPost('trigger') ?: 'down',
            'description'    => trim($this->request->getPost('description') ?? ''),
            'enabled'        => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $this->ipc->call('network.multiwan.sync');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', "Gateway group '{$name}' created.");
    }

    public function updateGatewayGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db  = \Config\Database::connect();
        $row = $db->table('wan_gateway_groups')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return redirect()->back()->with('error', 'Gateway group not found.');
        }

        $memberIds   = (array)($this->request->getPost('member_ids') ?? []);
        $memberTiers = (array)($this->request->getPost('member_tiers') ?? []);
        $members = [];
        foreach ($memberIds as $i => $mid) {
            if (!empty($mid)) {
                $members[] = ['id' => $mid, 'tier' => (int)($memberTiers[$i] ?? 1)];
            }
        }

        $db->table('wan_gateway_groups')->where('id', $id)->update([
            'name'           => trim($this->request->getPost('name') ?: $row['name']),
            'strategy'       => $this->request->getPost('strategy') ?: $row['strategy'],
            'sticky_enabled' => (int)(bool)$this->request->getPost('sticky_enabled'),
            'sticky_timeout' => max(0, (int)($this->request->getPost('sticky_timeout') ?? 0)),
            'members'        => json_encode($members),
            'trigger'        => $this->request->getPost('trigger') ?: $row['trigger'],
            'description'    => trim($this->request->getPost('description') ?? ''),
            'enabled'        => (int)(bool)$this->request->getPost('enabled'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $this->ipc->call('network.multiwan.sync');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', 'Gateway group updated.');
    }

    public function deleteGatewayGroup(string $id): \CodeIgniter\HTTP\RedirectResponse
    {
        $db = \Config\Database::connect();
        $db->table('wan_gateway_groups')->where('id', $id)->delete();
        $this->ipc->call('network.multiwan.sync');
        return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', 'Gateway group removed.');
    }

    /**
     * Applies Multi-WAN load balancing: the nftables ruleset that tags each
     * flow with its gateway, plus the routing policy that turns those tags into
     * per-gateway default routes.
     */
    public function applyMultiWan(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $compiler = new \App\Libraries\NftablesCompiler();
            $multiWan = $compiler->compileMultiWan();

            if ($multiWan['balancers'] === []) {
                return redirect()->to(base_url('routing') . '?tab=multiwan')
                    ->with('error', 'No enabled load-balanced gateway group with at least two members.');
            }

            // The routing half is shared with Policy-Based Routing, so both are
            // sent in one call and the daemon rebuilds the whole policy.
            $policy = $compiler->compileRoutingPolicy();

            $resp = $this->ipc->call('routing.apply', [
                'ruleset'   => $compiler->compile(),
                'rules'     => $policy['rules'],
                'routes'    => $policy['routes'],
                'safe_mode' => true,
            ]);

            if (! empty($resp['error'])) {
                return redirect()->to(base_url('routing') . '?tab=multiwan')
                    ->with('error', 'Failed to apply Multi-WAN: ' . ($resp['error']['message'] ?? 'daemon error'));
            }

            $result = $resp['result'] ?? [];
            if (in_array($result['status'] ?? 'applied', ['syntax_error', 'apply_failed', 'error'], true)) {
                return redirect()->to(base_url('routing') . '?tab=multiwan')
                    ->with('error', 'Multi-WAN rejected: ' . ($result['error'] ?? $result['message'] ?? 'apply failed'));
            }

            $sticky = 0;
            foreach ($multiWan['balancers'] as $balancer) {
                if ($balancer['sticky']) {
                    $sticky++;
                }
            }

            $msg = sprintf(
                'Multi-WAN applied: %d balanced group(s), %d with sticky connections%s.',
                count($multiWan['balancers']),
                $sticky,
                $this->ipc->isMock() ? ' (simulated)' : '',
            );

            return redirect()->to(base_url('routing') . '?tab=multiwan')->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('routing') . '?tab=multiwan')
                ->with('error', 'Failed to apply Multi-WAN: ' . $e->getMessage());
        }
    }
}
